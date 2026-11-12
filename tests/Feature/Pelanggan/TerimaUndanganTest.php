<?php

namespace Tests\Feature\Pelanggan;

use App\Mail\Pelanggan\UndanganEmail;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Organization;
use App\Models\UndanganPelanggan;
use App\Models\User;
use App\Services\Pelanggan\TokenPelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/** REQ-AUTH-06 & REQ-ANG-01 — undangan berkode, dari dibuat sampai ditukar. */
class TerimaUndanganTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
        Mail::fake();
    }

    private function kodeDariEmail(string $email): string
    {
        $kode = null;

        Mail::assertSent(UndanganEmail::class, function (UndanganEmail $mail) use ($email, &$kode) {
            if (! $mail->hasTo($email)) {
                return false;
            }

            $kode = $mail->kode;

            return true;
        });

        $this->assertNotNull($kode, "Tidak ada email undangan ke {$email}.");

        return (string) $kode;
    }

    /** @return array{customer: Customer, kode: string} */
    private function undang(string $email, string $peran = CustomerMember::PERAN_STAF, ?Customer $perusahaan = null): array
    {
        // Nama dibiarkan bernomor urut dari helper: `undang()` dipanggil
        // berulang di satu test, dan nama tetap menabrak
        // UNIQUE(organization_id, nama).
        $perusahaan ??= $this->perusahaan();

        $respons = $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", ['email' => $email, 'peran' => $peran])
            ->assertCreated()
            ->assertJsonPath('data.email', $email)
            ->assertJsonStructure(['data' => ['id', 'email', 'peran', 'kedaluwarsa_pada', 'berlaku_sampai', 'kode', 'email_terkirim']]);

        $kode = $this->kodeDariEmail($email);

        // Kode di balasan HARUS kode yang sama dengan yang masuk email — bukan
        // sekadar "8 karakter apa saja".
        $this->assertSame($kode, $respons->json('data.kode'));
        $this->lupakanSesiGuard();

        return ['customer' => $perusahaan, 'kode' => $kode];
    }

    /** @return array<string, mixed> */
    private function formulir(string $email, string $kode, array $ubah = []): array
    {
        return [
            'email' => $email,
            'kode' => $kode,
            'nama' => 'Budi Diundang',
            'sandi' => $this->sandiBenar,
            'telepon' => '0812-3456-7890',
            'jabatan' => 'QA Staff',
            'setuju_syarat' => true,
            ...$ubah,
        ];
    }

    // ------------------------------------------------------------ membuat

    public function test_undangan_disimpan_dalam_bentuk_hash(): void
    {
        ['kode' => $kode] = $this->undang('budi@contoh.test');

        $baris = UndanganPelanggan::query()->firstOrFail();

        $this->assertNotSame($kode, $baris->kode_hash);
        $this->assertStringNotContainsString($kode, (string) $baris->kode_hash);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $kode, 'Abjad kodenya memuat karakter yang gampang salah baca.');
    }

    /** Kodenya tidak memuat 0/O/1/I — orang mengetiknya ulang dari email. */
    public function test_abjad_kode_tidak_memuat_karakter_rancu(): void
    {
        for ($i = 0; $i < 12; $i++) {
            Mail::fake();
            ['kode' => $kode] = $this->undang("budi{$i}@contoh.test");

            $this->assertSame(
                0,
                preg_match('/[O0I1L]/', $kode),
                "Kode `{$kode}` memuat karakter rancu.",
            );
        }
    }

    /** Undangan baru MEMBATALKAN yang lama buat email + perusahaan yang sama. */
    public function test_undangan_baru_membatalkan_yang_lama(): void
    {
        $perusahaan = $this->perusahaan('PT Tujuan Undangan');
        ['kode' => $kodeLama] = $this->undang('budi@contoh.test', perusahaan: $perusahaan);

        Mail::fake();
        ['kode' => $kodeBaru] = $this->undang('budi@contoh.test', perusahaan: $perusahaan);

        $this->assertNotSame($kodeLama, $kodeBaru);

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', $kodeLama))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'undangan_tidak_berlaku');

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', $kodeBaru))
            ->assertCreated();
    }

    public function test_anggota_aktif_tidak_bisa_diundang_lagi(): void
    {
        $anggota = $this->anggota();
        $perusahaan = $anggota->keanggotaan()->first()->customer;

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", [
                'email' => $anggota->email,
                'peran' => CustomerMember::PERAN_STAF,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /** REQ-ANG-01 — batas anggota memberi tahu ADMIN, bukan orang yang diundang. */
    public function test_REQ_ANG_01_batas_anggota_menahan_pembuatan_undangan(): void
    {
        $anggota = $this->anggota();
        $perusahaan = $anggota->keanggotaan()->first()->customer;
        $perusahaan->forceFill(['maks_anggota' => 1])->save();

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", [
                'email' => 'budi@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'batas_anggota');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('undangan_pelanggan', 0);
    }

    public function test_undangan_perusahaan_lab_lain_dijawab_404(): void
    {
        $lain = Customer::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'nama' => 'PT Lab Sebelah',
        ]);

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/customers/{$lain->id}/undangan", [
                'email' => 'budi@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ])
            ->assertNotFound();
    }

    // ------------------------------------------------------------ kode di balasan (Fase 1a)

    /**
     * Kode di balasan `POST /customers/{c}/undangan` adalah kode yang SUNGGUHAN
     * bisa ditukar — diuji ujung ke ujung, tanpa menengok email sama sekali.
     * (Tes ini sengaja tidak memakai helper `undang()`.)
     */
    public function test_kode_di_balasan_bisa_ditukar_ujung_ke_ujung(): void
    {
        $perusahaan = $this->perusahaan();

        $respons = $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", [
                'email' => 'budi@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Undangan dibuat untuk budi@contoh.test. Kirim kodenya langsung bila email tidak sampai.');

        $kode = $respons->json('data.kode');
        $this->assertIsString($kode);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $kode);
        $this->assertSame($respons->json('data.kedaluwarsa_pada'), $respons->json('data.berlaku_sampai'));
        $this->assertTrue(
            now()->addDays(UndanganPelanggan::BERLAKU_HARI)->diffInSeconds($respons->json('data.berlaku_sampai'), true) < 60,
        );
        $this->lupakanSesiGuard();

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', $kode))
            ->assertCreated()
            ->assertJsonPath('data.user.status', User::STATUS_AKTIF);

        $this->assertDatabaseHas('customer_members', [
            'customer_id' => $perusahaan->id,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);
    }

    /** Undangan kedua: kode di balasan pertama mati, kode di balasan kedua hidup. */
    public function test_kode_dari_balasan_lama_mati_setelah_undangan_baru(): void
    {
        $perusahaan = $this->perusahaan();
        $kodeDari = function () use ($perusahaan): string {
            $kode = $this->withHeaders($this->bearerInternal($this->adminLab()))
                ->postJson("/api/customers/{$perusahaan->id}/undangan", [
                    'email' => 'budi@contoh.test',
                    'peran' => CustomerMember::PERAN_STAF,
                ])
                ->assertCreated()
                ->json('data.kode');
            $this->lupakanSesiGuard();

            return $kode;
        };

        $lama = $kodeDari();
        $baru = $kodeDari();

        $this->assertNotSame($lama, $baru);

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', $lama))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'undangan_tidak_berlaku');

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', $baru))
            ->assertCreated();
    }

    /** Anggota aktif: 422 dan TIDAK ada kode, undangan, maupun email. */
    public function test_anggota_aktif_422_tanpa_kode_di_balasan(): void
    {
        $anggota = $this->anggota();
        $perusahaan = $anggota->keanggotaan()->first()->customer;

        $respons = $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", [
                'email' => $anggota->email,
                'peran' => CustomerMember::PERAN_STAF,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertNull($respons->json('data.kode'));
        $this->assertArrayNotHasKey('data', $respons->json() ?? [], 'Penolakan tidak boleh membawa data.kode.');
        $this->assertDatabaseCount('undangan_pelanggan', 0);
        Mail::assertNothingSent();
    }

    /** Kode polos tidak boleh nongol di DB (hash saja), audit_logs, maupun log. */
    public function test_kode_polos_tidak_tersimpan_di_db_audit_atau_log(): void
    {
        $dicatat = [];
        Log::listen(function ($pesan) use (&$dicatat) {
            $dicatat[] = $pesan->message.json_encode($pesan->context);
        });

        ['kode' => $kode] = $this->undang('budi@contoh.test');

        foreach (DB::table('undangan_pelanggan')->get() as $baris) {
            $this->assertStringNotContainsString($kode, json_encode($baris));
        }
        foreach (DB::table('audit_logs')->get() as $baris) {
            $this->assertStringNotContainsString($kode, json_encode($baris), 'Kode polos bocor ke audit_logs.');
        }
        $this->assertStringNotContainsString($kode, implode("\n", $dicatat), 'Kode polos bocor ke log.');
    }

    /** Galat kirim email tidak menggagalkan undangan, dan balasan jujur: email_terkirim=false. */
    public function test_email_terkirim_false_bila_mailer_gagal_tapi_kode_tetap_dibalas(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP mati'));
        $perusahaan = $this->perusahaan();

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", [
                'email' => 'budi@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ])
            ->assertCreated()
            ->assertJsonPath('data.email_terkirim', false)
            ->assertJsonPath('data.kode', fn ($kode) => is_string($kode) && strlen($kode) === 8);
    }

    /** Mailer `log`/`array` tidak mengirim ke mana pun, jadi tidak boleh mengaku terkirim. */
    public function test_email_terkirim_hanya_true_untuk_mailer_sungguhan(): void
    {
        $perusahaan = $this->perusahaan();
        $panggil = fn (string $email) => $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", ['email' => $email, 'peran' => CustomerMember::PERAN_STAF])
            ->assertCreated();

        config(['mail.default' => 'log']);
        $panggil('a@contoh.test')->assertJsonPath('data.email_terkirim', false);
        $this->lupakanSesiGuard();

        config(['mail.default' => 'smtp']);
        $panggil('b@contoh.test')->assertJsonPath('data.email_terkirim', true);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function peranPemanggil(): array
    {
        return [
            'admin' => [User::ROLE_ADMIN, 201],
            'super_admin' => [User::ROLE_SUPER_ADMIN, 201],
            'teknisi' => [User::ROLE_TEKNISI, 403],
            'viewer' => [User::ROLE_VIEWER, 403],
        ];
    }

    #[DataProvider('peranPemanggil')]
    public function test_siapa_boleh_mengundang(string $role, int $diharapkan): void
    {
        $perusahaan = $this->perusahaan();

        $respons = $this->withHeaders($this->bearerInternal($this->adminLab($role)))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", [
                'email' => 'budi@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ])
            ->assertStatus($diharapkan);

        if ($diharapkan === 201) {
            $this->assertSame(8, strlen($respons->json('data.kode')));
        } else {
            // Ditolak: tidak ada kode di balasan, undangan, ataupun email.
            $this->assertArrayNotHasKey('data', $respons->json() ?? [], 'Penolakan tidak boleh membawa data.kode.');
            $this->assertDatabaseCount('undangan_pelanggan', 0);
            Mail::assertNothingSent();
        }
    }

    public function test_pelanggan_tidak_bisa_memakai_pintu_undangan_lab(): void
    {
        $anggota = $this->anggota();
        $perusahaan = $anggota->keanggotaan()->first()->customer;

        $respons = $this->withHeaders($this->bearer($anggota))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", [
                'email' => 'budi@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ]);

        $this->assertContains($respons->getStatusCode(), [403, 404]);
        $this->assertArrayNotHasKey('data', $respons->json() ?? [], 'Penolakan tidak boleh membawa data.kode.');
        $this->assertDatabaseCount('undangan_pelanggan', 0);
    }

    public function test_admin_organisasi_lain_dijawab_404_tanpa_kode(): void
    {
        $perusahaan = $this->perusahaan();
        $adminLain = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);

        $respons = $this->withHeaders($this->bearerInternal($adminLain))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", [
                'email' => 'budi@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ])
            ->assertNotFound();

        $this->assertArrayNotHasKey('data', $respons->json() ?? [], 'Penolakan tidak boleh membawa data.kode.');
        $this->assertDatabaseCount('undangan_pelanggan', 0);
    }

    public function test_rute_undangan_lab_punya_throttle_dan_menahan_banjir(): void
    {
        $rute = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/customers/{customer}/undangan' && in_array('POST', $r->methods(), true));

        $this->assertNotNull($rute);
        $this->assertContains('throttle:pelanggan-undang', $rute->gatherMiddleware());

        // Perilakunya, bukan cuma daftarnya: jatah 20/menit per admin.
        $perusahaan = $this->perusahaan();
        $header = $this->bearerInternal($this->adminLab());
        for ($i = 1; $i <= 20; $i++) {
            $this->withHeaders($header)
                ->postJson("/api/customers/{$perusahaan->id}/undangan", ['email' => "u{$i}@contoh.test", 'peran' => CustomerMember::PERAN_STAF])
                ->assertCreated();
        }

        $this->withHeaders($header)
            ->postJson("/api/customers/{$perusahaan->id}/undangan", ['email' => 'u21@contoh.test', 'peran' => CustomerMember::PERAN_STAF])
            ->assertStatus(429);
    }

    // ------------------------------------------------------------ menukar

    public function test_REQ_AUTH_06_kode_benar_bikin_akun_langsung_aktif(): void
    {
        ['customer' => $perusahaan, 'kode' => $kode] = $this->undang('budi@contoh.test', CustomerMember::PERAN_PIC_UTAMA);

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', $kode))
            ->assertCreated()
            ->assertJsonPath('data.kemampuan', TokenPelanggan::ABILITY_PENUH)
            ->assertJsonPath('data.user.status', User::STATUS_AKTIF)
            ->assertJsonPath('data.user.butuh_verifikasi', false)
            ->assertJsonPath('data.user.keanggotaan.0.peran', CustomerMember::PERAN_PIC_UTAMA)
            ->assertJsonStructure(['data' => ['token', 'kedaluwarsa_pada', 'kemampuan', 'user', 'akun_sudah_ada']]);

        $user = User::query()->where('email', 'budi@contoh.test')->firstOrFail();

        $this->assertSame(User::ROLE_PELANGGAN, $user->role);
        $this->assertNotNull($user->email_verified_at, 'Kodenya sendiri sudah membuktikan dia membaca email itu.');
        $this->assertSame('+6281234567890', $user->telepon);

        // Tidak ada antrean pengajuan sama sekali — itu inti REQ-AUTH-06.
        $this->assertDatabaseCount('pengajuan_akun_pelanggan', 0);
        $this->assertDatabaseHas('undangan_pelanggan', ['dipakai_oleh' => $user->id]);
        $this->assertDatabaseHas('customer_members', [
            'customer_id' => $perusahaan->id,
            'user_id' => $user->id,
            'peran' => CustomerMember::PERAN_PIC_UTAMA,
        ]);
    }

    /** Sekali pakai. */
    public function test_kode_tidak_bisa_dipakai_dua_kali(): void
    {
        ['kode' => $kode] = $this->undang('budi@contoh.test');

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', $kode))
            ->assertCreated();

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('lain@contoh.test', $kode))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'undangan_tidak_berlaku');
    }

    /** Kode hanya cocok untuk email yang diundang. */
    public function test_kode_tidak_cocok_buat_email_lain(): void
    {
        ['kode' => $kode] = $this->undang('budi@contoh.test');

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('orang-lain@contoh.test', $kode))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'undangan_tidak_berlaku');

        $this->assertDatabaseMissing('users', ['email' => 'orang-lain@contoh.test']);
    }

    public function test_kode_kedaluwarsa_ditolak(): void
    {
        ['kode' => $kode] = $this->undang('budi@contoh.test');

        $this->travel(UndanganPelanggan::BERLAKU_HARI + 1)->days();

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', $kode))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'undangan_tidak_berlaku');
    }

    public function test_undangan_yang_dibatalkan_tidak_bisa_ditukar(): void
    {
        ['customer' => $perusahaan, 'kode' => $kode] = $this->undang('budi@contoh.test');
        $undangan = UndanganPelanggan::query()->firstOrFail();

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->deleteJson("/api/customers/{$perusahaan->id}/undangan/{$undangan->id}")
            ->assertOk();

        // Barisnya TETAP ada — jejak "siapa mengundang siapa" tidak dihapus.
        $this->assertDatabaseHas('undangan_pelanggan', ['id' => $undangan->id]);
        $this->lupakanSesiGuard();

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', $kode))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'undangan_tidak_berlaku');
    }

    /**
     * Semua kegagalan kode dijawab dengan pesan yang SAMA PERSIS.
     *
     * Kalau dibedakan, endpoint ini jadi alat memeriksa undangan mana yang
     * pernah ada buat sebuah email.
     */
    public function test_semua_kegagalan_kode_dijawab_sama(): void
    {
        ['kode' => $kode] = $this->undang('budi@contoh.test');

        $salah = $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', 'ZZZZZZZZ'))
            ->assertStatus(422);

        $emailAsing = $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('asing@contoh.test', $kode))
            ->assertStatus(422);

        $this->assertSame($salah->json('kode'), $emailAsing->json('kode'));
        $this->assertSame($salah->json('message'), $emailAsing->json('message'));
    }

    /**
     * Konsultan yang sudah punya akun pelanggan TIDAK dibuatkan akun kedua —
     * dia jadi anggota perusahaan kedua.
     */
    public function test_akun_pelanggan_yang_sudah_ada_jadi_anggota_perusahaan_kedua(): void
    {
        $konsultan = $this->anggota();
        $pertama = $konsultan->keanggotaan()->first()->customer;

        ['customer' => $kedua, 'kode' => $kode] = $this->undang((string) $konsultan->email);

        $badan = $this->postJson('/api/pelanggan/v1/auth/terima-undangan',
            $this->formulir((string) $konsultan->email, $kode, ['nama' => 'Nama Baru Diabaikan']))
            ->assertCreated()
            ->json();

        // `true` yang SUNGGUHAN (bool), bukan "1"/"true" — klien bercabang pada tipenya.
        $this->assertSame(true, $badan['data']['akun_sudah_ada']);

        $this->assertSame(1, User::query()->where('email', $konsultan->email)->count());
        $this->assertSame(2, CustomerMember::query()->where('user_id', $konsultan->id)->count());

        foreach ([$pertama->id, $kedua->id] as $customerId) {
            $this->assertDatabaseHas('customer_members', [
                'customer_id' => $customerId,
                'user_id' => $konsultan->id,
                'status' => CustomerMember::STATUS_AKTIF,
            ]);
        }
    }

    // ------------------------------------------------ data.akun_sudah_ada (12 Nov 2026)

    /** Email yang belum punya akun: akun dibuat panggilan ini, jadi `false` (bool). */
    public function test_akun_sudah_ada_false_untuk_akun_baru(): void
    {
        ['kode' => $kode] = $this->undang('budi@contoh.test');

        $badan = $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', $kode))
            ->assertCreated()
            ->json();

        $this->assertArrayHasKey('akun_sudah_ada', $badan['data']);
        $this->assertSame(false, $badan['data']['akun_sudah_ada']);
        $this->assertSame(1, User::query()->where('email', 'budi@contoh.test')->count());
    }

    /**
     * Akun pelanggan yang SUDAH ada sebelum undangan ditukar: `true`, dan sandi
     * lamanya tidak ditimpa oleh `sandi` di formulir (yang diabaikan).
     */
    public function test_akun_sudah_ada_true_untuk_akun_pelanggan_lama_dan_sandi_tidak_ditimpa(): void
    {
        $konsultan = $this->anggota();
        $hashLama = (string) $konsultan->fresh()->password;
        ['kode' => $kode] = $this->undang((string) $konsultan->email);

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan',
            $this->formulir((string) $konsultan->email, $kode, ['sandi' => 'SandiLain#2026Baru']))
            ->assertCreated()
            ->assertJsonPath('data.akun_sudah_ada', true);

        $this->assertSame($hashLama, (string) $konsultan->fresh()->password);
    }

    /** Hanya balasan 201 yang membawa field itu: `masuk` tidak berubah bentuknya. */
    public function test_masuk_tidak_memuat_akun_sudah_ada(): void
    {
        $user = $this->anggota();

        $badan = $this->postJson('/api/pelanggan/v1/auth/masuk', ['email' => $user->email, 'sandi' => $this->sandiBenar])
            ->assertOk()
            ->json();

        $this->assertArrayNotHasKey('akun_sudah_ada', $badan['data']);
    }

    /**
     * Kode salah / sudah dipakai / email asing dijawab SATU bentuk yang sama,
     * dan jawaban itu TIDAK bergantung pada apakah email-nya sudah punya akun:
     * penebak kode tidak boleh memperoleh `akun_sudah_ada`, atau pembeda lain.
     */
    public function test_kegagalan_kode_tidak_membocorkan_apakah_email_sudah_punya_akun(): void
    {
        $punyaAkun = $this->anggota();
        ['kode' => $kodeSah] = $this->undang('budi@contoh.test');

        $bandingkan = function (string $emailAda, string $emailTidakAda, string $kode, string $skenario): void {
            $this->permintaanBaru();
            $ada = $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir($emailAda, $kode))->assertStatus(422);
            $this->permintaanBaru();
            $tidak = $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir($emailTidakAda, $kode))->assertStatus(422);

            $this->assertSame($tidak->json(), $ada->json(), "Jawaban berbeda untuk skenario: {$skenario}");
            $this->assertSame('undangan_tidak_berlaku', $ada->json('kode'));
            $this->assertArrayNotHasKey('data', $ada->json());
            $this->assertStringNotContainsString('akun_sudah_ada', (string) $ada->getContent());
        };

        // 1. kode ngawur
        $bandingkan((string) $punyaAkun->email, 'tidak-ada@contoh.test', 'ZZZZZZZZ', 'kode salah');

        // 2. kode sah milik email LAIN dipakai di email yang sudah punya akun
        $bandingkan((string) $punyaAkun->email, 'tidak-ada@contoh.test', $kodeSah, 'kode milik email lain');

        // 3. kode sah yang sudah terpakai
        $this->permintaanBaru();
        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', $kodeSah))->assertCreated();
        $bandingkan((string) $punyaAkun->email, 'tidak-ada@contoh.test', $kodeSah, 'kode sudah dipakai');
    }

    /** Akun LAB tidak bisa ditarik jadi anggota perusahaan lewat undangan. */
    public function test_akun_internal_tidak_bisa_menerima_undangan(): void
    {
        $teknisi = $this->adminLab(User::ROLE_TEKNISI);
        ['kode' => $kode] = $this->undang((string) $teknisi->email);

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir((string) $teknisi->email, $kode))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'undangan_akun_internal');

        $this->assertSame(User::ROLE_TEKNISI, $teknisi->fresh()->role);
        $this->assertDatabaseCount('customer_members', 0);
    }

    /** Sandi undangan ikut aturan kekuatan yang sama dengan daftar mandiri. */
    public function test_sandi_ikut_aturan_kekuatan(): void
    {
        ['kode' => $kode] = $this->undang('budi@contoh.test');

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan',
            $this->formulir('budi@contoh.test', $kode, ['sandi' => 'pendek12']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sandi');
    }

    /** Kode diketik ulang orang: huruf kecil & spasi dirapikan server. */
    public function test_kode_huruf_kecil_dan_berspasi_tetap_diterima(): void
    {
        ['kode' => $kode] = $this->undang('budi@contoh.test');
        $diketikOrang = strtolower(substr($kode, 0, 4).' '.substr($kode, 4));

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan',
            $this->formulir('budi@contoh.test', $diketikOrang))
            ->assertCreated();
    }

    /** Flag modul mati → jalur tukar undangan ikut tertutup. */
    public function test_flag_mati_menutup_tukar_undangan(): void
    {
        ['kode' => $kode] = $this->undang('budi@contoh.test');
        config(['pelanggan.fitur' => false]);

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir('budi@contoh.test', $kode))
            ->assertStatus(503)
            ->assertJsonPath('kode', 'belum_tersedia');
    }
}
