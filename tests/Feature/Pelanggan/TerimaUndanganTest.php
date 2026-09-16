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
use Illuminate\Support\Facades\Mail;
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

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", ['email' => $email, 'peran' => $peran])
            ->assertCreated()
            ->assertJsonPath('data.email', $email)
            ->assertJsonStructure(['data' => ['id', 'email', 'peran', 'kedaluwarsa_pada']]);

        $kode = $this->kodeDariEmail($email);
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
            ->assertJsonStructure(['data' => ['token', 'kedaluwarsa_pada', 'kemampuan', 'user']]);

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

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan',
            $this->formulir((string) $konsultan->email, $kode, ['nama' => 'Nama Baru Diabaikan']))
            ->assertCreated();

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

    /** Akun LAB tidak bisa ditarik jadi anggota perusahaan lewat undangan. */
    public function test_akun_internal_tidak_bisa_menerima_undangan(): void
    {
        $teknisi = $this->adminLab(User::ROLE_TEKNISI);
        ['kode' => $kode] = $this->undang((string) $teknisi->email);

        $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir((string) $teknisi->email, $kode))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'bukan_akun_pelanggan');

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
