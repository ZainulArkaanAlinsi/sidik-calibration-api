<?php

namespace Tests\Feature\Pelanggan;

use App\Mail\Pelanggan\KodeOtpEmail;
use App\Models\OtpPelanggan;
use App\Models\PengajuanAkunPelanggan;
use App\Models\PersetujuanDokumen;
use App\Models\User;
use App\Notifications\Pelanggan\PengajuanAkunMenunggu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * REQ-AUTH-01/02/10 — daftar, OTP, dan penguncian sesudah 5 kali salah.
 *
 * Kode OTP-nya dibaca dari email yang dipalsukan, BUKAN dari kolom database:
 * yang disimpan cuma hash-nya, dan test yang mengintip hash lalu mencocokkannya
 * sendiri berarti tidak pernah membuktikan kode yang sampai ke orangnya benar.
 */
class DaftarDanVerifikasiTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
        Mail::fake();
    }

    /** @return array<string, mixed> */
    private function formulir(array $ubah = []): array
    {
        return [
            'nama' => 'Budi Pendaftar',
            'email' => 'budi@contoh.test',
            'sandi' => $this->sandiBenar,
            'telepon' => '0812-3456-7890',
            'jabatan' => 'QA Supervisor',
            'nama_perusahaan' => 'PT Contoh Industri',
            'alamat_perusahaan' => 'Jl. Contoh No. 1, Bandung',
            'setuju_syarat' => true,
            ...$ubah,
        ];
    }

    private function kodeDariEmail(string $email): string
    {
        $kode = null;

        Mail::assertSent(KodeOtpEmail::class, function (KodeOtpEmail $mail) use ($email, &$kode) {
            if (! $mail->hasTo($email)) {
                return false;
            }

            $kode = $mail->kode;

            return true;
        });

        $this->assertNotNull($kode, "Tidak ada email OTP yang terkirim ke {$email}.");

        return (string) $kode;
    }

    public function test_REQ_AUTH_01_daftar_bikin_akun_pending_email_beserta_pengajuannya(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir())
            ->assertCreated()
            ->assertJsonPath('data.status', User::STATUS_PENDING_EMAIL)
            ->assertJsonPath('data.email', 'budi@contoh.test')
            // Tidak ada token di balasan pendaftaran, dan itu inti REQ-AUTH-01:
            // "belum ada akses data apa pun".
            ->assertJsonMissingPath('data.token');

        $user = User::query()->where('email', 'budi@contoh.test')->firstOrFail();

        $this->assertSame(User::ROLE_PELANGGAN, $user->role);
        $this->assertSame(User::STATUS_PENDING_EMAIL, $user->status);
        $this->assertSame('+6281234567890', $user->telepon, 'Nomor HP tidak dinormalisasi ke +62.');
        $this->assertNull($user->employee_id, 'Akun pelanggan tidak boleh kebagian ID pegawai.');

        $this->assertDatabaseHas('pengajuan_akun_pelanggan', [
            'user_id' => $user->id,
            'nama_perusahaan' => 'PT Contoh Industri',
            'status' => PengajuanAkunPelanggan::STATUS_MENUNGGU,
            'customer_id' => null,
        ]);

        // Klaim pendaftar TIDAK boleh langsung jadi baris `customers` (R-D02).
        $this->assertDatabaseMissing('customers', ['nama' => 'PT Contoh Industri']);

        Mail::assertSent(KodeOtpEmail::class, 1);
    }

    /** Persetujuan privasi & syarat tercatat, versinya dari config (S05 langkah 1). */
    public function test_persetujuan_dokumen_tercatat_dengan_versi_server(): void
    {
        config(['pelanggan.versi_dokumen.kebijakan_privasi' => '2.3']);

        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir())->assertCreated();

        $user = User::query()->where('email', 'budi@contoh.test')->firstOrFail();

        $this->assertDatabaseHas('persetujuan_dokumen', [
            'user_id' => $user->id,
            'jenis' => PersetujuanDokumen::JENIS_KEBIJAKAN_PRIVASI,
            'versi' => '2.3',
        ]);
        $this->assertDatabaseHas('persetujuan_dokumen', [
            'user_id' => $user->id,
            'jenis' => PersetujuanDokumen::JENIS_SYARAT_KETENTUAN,
        ]);
    }

    /** Tanpa centang persetujuan, pendaftarannya ditolak — bukan dicatat diam-diam. */
    public function test_daftar_tanpa_persetujuan_ditolak(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir(['setuju_syarat' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('setuju_syarat');

        $this->assertDatabaseCount('persetujuan_dokumen', 0);
    }

    /**
     * Email yang sudah terpakai ditolak TANPA memberi tahu bahwa dia terpakai.
     *
     * Pesannya diadu isinya, bukan cuma "ada error 422": pesan bawaan Laravel
     * berbunyi "email sudah digunakan", dan itu persis kalimat yang bikin
     * endpoint ini bisa dipakai menyisir pelanggan PT Sidik dari luar.
     */
    public function test_email_terpakai_ditolak_tanpa_membocorkan_bahwa_dia_terpakai(): void
    {
        $this->pelanggan(tambahan: ['email' => 'budi@contoh.test']);

        $balasan = $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $pesan = (string) $balasan->json('errors.email.0');

        $this->assertStringNotContainsStringIgnoringCase('sudah digunakan', $pesan);
        $this->assertStringNotContainsStringIgnoringCase('sudah terdaftar', $pesan);
        $this->assertStringContainsStringIgnoringCase('lupa sandi', $pesan);
    }

    /** Email disimpan huruf kecil — beda SQLite vs MySQL tidak boleh menentukan. */
    public function test_email_disimpan_huruf_kecil(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir(['email' => 'Budi.Besar@Contoh.Test']))
            ->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'budi.besar@contoh.test']);
    }

    public function test_sandi_pendek_dan_sandi_bocor_sama_sama_ditolak(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir(['sandi' => 'pendek12']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sandi');

        $this->sandiSelaluDianggapBocor();

        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir(['sandi' => 'SandiPanjangTapiBocor123']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sandi');

        $this->assertDatabaseCount('pengajuan_akun_pelanggan', 0);
    }

    public function test_nomor_hp_ngawur_ditolak(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir(['telepon' => '12345']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('telepon');
    }

    public function test_REQ_AUTH_02_otp_benar_bikin_status_pending_verifikasi_dan_token_menunggu(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'organization_id' => $this->organisasi()->id,
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir())->assertCreated();
        $kode = $this->kodeDariEmail('budi@contoh.test');

        $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', [
            'email' => 'budi@contoh.test',
            'otp' => $kode,
        ])
            ->assertOk()
            ->assertJsonPath('data.kemampuan', 'pelanggan:menunggu')
            ->assertJsonPath('data.user.status', User::STATUS_PENDING_VERIFIKASI)
            ->assertJsonPath('data.user.butuh_verifikasi', true)
            ->assertJsonPath('data.user.pengajuan.status', PengajuanAkunPelanggan::STATUS_MENUNGGU)
            ->assertJsonStructure(['data' => ['token', 'kedaluwarsa_pada', 'kemampuan', 'user']]);

        $user = User::query()->where('email', 'budi@contoh.test')->firstOrFail();
        $this->assertSame(User::STATUS_PENDING_VERIFIKASI, $user->status);
        $this->assertNotNull($user->email_verified_at);

        Notification::assertSentTo($admin, PengajuanAkunMenunggu::class);
    }

    /**
     * Admin tidak dikabari SEBELUM emailnya terverifikasi.
     *
     * Ini separuh REQ-AUTH-02 yang paling gampang hilang: barisnya dibuat waktu
     * daftar (lihat docblock `AuthPelangganController::daftar()`), jadi tanpa
     * test ini "admin tidak diganggu" cuma niat baik di komentar.
     */
    public function test_admin_tidak_dikabari_dan_antrean_kosong_sebelum_email_terverifikasi(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'organization_id' => $this->organisasi()->id,
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir())->assertCreated();

        Notification::assertNothingSentTo($admin);
        $this->assertSame(0, PengajuanAkunPelanggan::query()->siapDitinjau()->count());

        $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', [
            'email' => 'budi@contoh.test',
            'otp' => $this->kodeDariEmail('budi@contoh.test'),
        ])->assertOk();

        $this->assertSame(1, PengajuanAkunPelanggan::query()->siapDitinjau()->count());
    }

    public function test_otp_salah_dijawab_422_tanpa_membocorkan_akunnya_ada(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir())->assertCreated();

        $adaAkun = $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', [
            'email' => 'budi@contoh.test',
            'otp' => '000000',
        ])->assertStatus(422)->assertJsonPath('kode', 'otp_salah');

        $tanpaAkun = $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', [
            'email' => 'tidak-ada@contoh.test',
            'otp' => '000000',
        ])->assertStatus(422)->assertJsonPath('kode', 'otp_salah');

        $this->assertSame($adaAkun->json('message'), $tanpaAkun->json('message'));
    }

    /** REQ-AUTH-02 — salah 5 kali mengunci 15 menit. */
    public function test_REQ_AUTH_02_salah_lima_kali_mengunci_lima_belas_menit(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir())->assertCreated();
        $kode = $this->kodeDariEmail('budi@contoh.test');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', [
                'email' => 'budi@contoh.test',
                'otp' => '000000',
            ])->assertStatus(422)->assertJsonPath('kode', 'otp_salah');
        }

        $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', [
            'email' => 'budi@contoh.test',
            'otp' => '000000',
        ])->assertStatus(429)->assertJsonPath('kode', 'otp_terkunci');

        // Kode yang BENAR pun ditolak selama masih terkunci — kalau tidak,
        // penguncian cuma memperlambat penebakan, bukan menghentikannya.
        $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', [
            'email' => 'budi@contoh.test',
            'otp' => $kode,
        ])->assertStatus(429)->assertJsonPath('kode', 'otp_terkunci');

        $this->travel(OtpPelanggan::KUNCI_MENIT + 1)->minutes();

        // Kunci (15 menit) SELALU lebih panjang dari masa berlaku kode (10
        // menit), jadi orang yang terkunci tidak pernah bisa memakai kode
        // lamanya lagi — dia wajib minta kode baru. Diadu sebagai angka, bukan
        // cuma diceritakan: kalau salah satunya diubah, testnya yang bicara
        // sebelum orangnya kejebak di layar OTP tanpa jalan keluar.
        $this->assertGreaterThan(
            OtpPelanggan::BERLAKU_MENIT,
            OtpPelanggan::KUNCI_MENIT,
            'Kunci lebih pendek dari masa berlaku kode: orang yang terkunci bisa memakai kode lamanya.',
        );

        $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', [
            'email' => 'budi@contoh.test',
            'otp' => $kode,
        ])->assertStatus(422)->assertJsonPath('kode', 'otp_salah');

        // Yang membuktikan kuncinya BENERAN lepas: kode BARU diterima. Tanpa
        // langkah ini, test di atas sama saja dengan "kodenya kedaluwarsa" dan
        // tidak menyentuh penguncian sama sekali.
        Mail::fake();
        $this->postJson('/api/pelanggan/v1/auth/kirim-ulang-otp', ['email' => 'budi@contoh.test'])->assertOk();

        $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', [
            'email' => 'budi@contoh.test',
            'otp' => $this->kodeDariEmail('budi@contoh.test'),
        ])->assertOk();
    }

    /** Kunci tidak bisa dilewati dengan minta kode baru. */
    public function test_kirim_ulang_ditolak_selama_akunnya_masih_terkunci(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir())->assertCreated();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', [
                'email' => 'budi@contoh.test',
                'otp' => '000000',
            ]);
        }

        $this->postJson('/api/pelanggan/v1/auth/kirim-ulang-otp', ['email' => 'budi@contoh.test'])
            ->assertStatus(429)
            ->assertJsonPath('kode', 'otp_terkunci');

        Mail::assertSent(KodeOtpEmail::class, 1);
    }

    public function test_otp_kedaluwarsa_ditolak(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir())->assertCreated();
        $kode = $this->kodeDariEmail('budi@contoh.test');

        $this->travel(OtpPelanggan::BERLAKU_MENIT + 1)->minutes();

        $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', [
            'email' => 'budi@contoh.test',
            'otp' => $kode,
        ])->assertStatus(422)->assertJsonPath('kode', 'otp_salah');
    }

    public function test_otp_cuma_sekali_pakai(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir())->assertCreated();
        $kode = $this->kodeDariEmail('budi@contoh.test');

        $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', ['email' => 'budi@contoh.test', 'otp' => $kode])
            ->assertOk();

        $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', ['email' => 'budi@contoh.test', 'otp' => $kode])
            ->assertStatus(422)->assertJsonPath('kode', 'otp_salah');
    }

    /** Kirim ulang mematikan kode lama — kode yang terlanjur terkirim tidak boleh hidup dua. */
    public function test_kirim_ulang_mematikan_kode_sebelumnya(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir())->assertCreated();
        $kodeLama = $this->kodeDariEmail('budi@contoh.test');

        Mail::fake();
        $this->postJson('/api/pelanggan/v1/auth/kirim-ulang-otp', ['email' => 'budi@contoh.test'])->assertOk();
        $kodeBaru = $this->kodeDariEmail('budi@contoh.test');

        $this->assertNotSame($kodeLama, $kodeBaru);

        $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', ['email' => 'budi@contoh.test', 'otp' => $kodeLama])
            ->assertStatus(422);

        $this->postJson('/api/pelanggan/v1/auth/verifikasi-email', ['email' => 'budi@contoh.test', 'otp' => $kodeBaru])
            ->assertOk();
    }

    /** Email tak dikenal tetap dijawab 200, dan tidak ada email yang terkirim. */
    public function test_kirim_ulang_untuk_email_asing_tetap_200_tanpa_mengirim_apa_pun(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/kirim-ulang-otp', ['email' => 'asing@contoh.test'])
            ->assertOk()
            ->assertJsonMissingPath('kode');

        Mail::assertNothingSent();
    }

    /** OTP tidak pernah tersimpan polos di database (REQ-PRV-02). */
    public function test_otp_disimpan_dalam_bentuk_hash(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', $this->formulir())->assertCreated();
        $kode = $this->kodeDariEmail('budi@contoh.test');

        $baris = OtpPelanggan::query()->firstOrFail();

        $this->assertNotSame($kode, $baris->kode_hash);
        $this->assertStringNotContainsString($kode, (string) $baris->kode_hash);
        $this->assertTrue(password_verify($kode, (string) $baris->kode_hash) || strlen((string) $baris->kode_hash) > 20);
    }
}
