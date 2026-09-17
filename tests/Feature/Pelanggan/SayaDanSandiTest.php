<?php

namespace Tests\Feature\Pelanggan;

use App\Mail\Pelanggan\KodeOtpEmail;
use App\Models\CustomerMember;
use App\Models\OtpPelanggan;
use App\Models\PengajuanAkunPelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/** `GET/PATCH /saya`, ganti sandi, lupa sandi, dan gerbang REQ-AUTH-03. */
class SayaDanSandiTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
        Mail::fake();
    }

    private function kodeTerkirim(string $email): string
    {
        $kode = null;

        Mail::assertSent(KodeOtpEmail::class, function (KodeOtpEmail $mail) use ($email, &$kode) {
            if (! $mail->hasTo($email)) {
                return false;
            }

            $kode = $mail->kode;

            return true;
        });

        return (string) $kode;
    }

    public function test_saya_menampilkan_profil_dan_keanggotaan(): void
    {
        $user = $this->anggota();

        $this->withHeaders($this->bearer($user))
            ->getJson('/api/pelanggan/v1/saya')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.butuh_verifikasi', false)
            ->assertJsonPath('data.keanggotaan.0.peran', CustomerMember::PERAN_PIC_UTAMA)
            ->assertJsonStructure(['data' => ['id', 'nama', 'email', 'telepon', 'jabatan', 'status', 'keanggotaan']]);
    }

    /** Kosakata internal tidak ikut keluar ke aplikasi pelanggan. */
    public function test_saya_tidak_membocorkan_field_internal(): void
    {
        $user = $this->anggota();

        $balasan = $this->withHeaders($this->bearer($user))
            ->getJson('/api/pelanggan/v1/saya')
            ->assertOk();

        foreach (['role', 'employee_id', 'department', 'kode_teknisi', 'password'] as $bocor) {
            $this->assertArrayNotHasKey($bocor, $balasan->json('data'), "Field internal `{$bocor}` ikut keluar.");
        }
    }

    /** Layar S06 — akun menunggu verifikasi HARUS bisa membaca statusnya sendiri. */
    public function test_akun_menunggu_verifikasi_tetap_bisa_membaca_saya(): void
    {
        $user = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI);
        $this->pengajuan($user);

        $this->withHeaders($this->bearer($user))
            ->getJson('/api/pelanggan/v1/saya')
            ->assertOk()
            ->assertJsonPath('data.butuh_verifikasi', true)
            ->assertJsonPath('data.pengajuan.status', PengajuanAkunPelanggan::STATUS_MENUNGGU)
            ->assertJsonPath('data.pengajuan.nama_perusahaan', 'PT Klaim Pendaftar');
    }

    /** REQ-AUTH-05 — alasan penolakan sampai ke pemohonnya. */
    public function test_alasan_penolakan_terbaca_di_saya(): void
    {
        $user = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI);
        $this->pengajuan($user, PengajuanAkunPelanggan::STATUS_DITOLAK)
            ->forceFill(['alasan_tolak' => 'Nama perusahaan tidak cocok dengan data kami.'])->save();

        $this->withHeaders($this->bearer($user))
            ->getJson('/api/pelanggan/v1/saya')
            ->assertOk()
            ->assertJsonPath('data.pengajuan.alasan_tolak', 'Nama perusahaan tidak cocok dengan data kami.');
    }

    /**
     * REQ-AUTH-03 — endpoint DATA tertutup buat akun yang belum diverifikasi.
     *
     * Rutenya didaftarkan di dalam test karena Fase 4 belum punya endpoint data
     * satu pun; yang diuji middleware-nya. Menumpang rute nyata nanti bikin
     * test ini ikut merah tiap kali rute itu berubah karena alasan lain.
     */
    public function test_REQ_AUTH_03_gerbang_pelanggan_aktif_menutup_akun_menunggu(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'aplikasi:pelanggan', 'pelanggan.aktif'])
            ->get('/uji/data-pelanggan', fn () => response()->json(['data' => ['ok' => true]]));

        $menunggu = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI);
        $aktif = $this->anggota();

        $this->withHeaders($this->bearer($menunggu))
            ->getJson('/uji/data-pelanggan')
            ->assertStatus(403)
            ->assertJsonPath('kode', 'akun_belum_diverifikasi');

        $this->lupakanSesiGuard();

        $this->withHeaders($this->bearer($aktif))
            ->getJson('/uji/data-pelanggan')
            ->assertOk();
    }

    /** Token internal tidak bisa dipakai di jalur pelanggan (M1-03). */
    public function test_token_internal_ditolak_di_rute_pelanggan(): void
    {
        $teknisi = User::factory()->create([
            'organization_id' => $this->organisasi()->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$teknisi->createToken('internal', ['internal'])->plainTextToken])
            ->getJson('/api/pelanggan/v1/saya')
            ->assertStatus(403)
            ->assertJsonPath('kode', 'bukan_aplikasi_ini');
    }

    public function test_patch_saya_mengubah_nama_telepon_jabatan(): void
    {
        $user = $this->anggota();

        $this->withHeaders($this->bearer($user))
            ->patchJson('/api/pelanggan/v1/saya', [
                'nama' => 'Budi Sudah Ganti',
                'telepon' => '0857 1111 2222',
                'jabatan' => 'Manager QC',
            ])
            ->assertOk()
            ->assertJsonPath('data.nama', 'Budi Sudah Ganti')
            ->assertJsonPath('data.telepon', '+6285711112222')
            ->assertJsonPath('data.jabatan', 'Manager QC');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Budi Sudah Ganti',
            'telepon' => '+6285711112222',
        ]);
    }

    /** Field yang tidak dikirim tidak boleh tertimpa null. */
    public function test_patch_sebagian_tidak_menghapus_field_lain(): void
    {
        $user = $this->anggota();

        $this->withHeaders($this->bearer($user))
            ->patchJson('/api/pelanggan/v1/saya', ['nama' => 'Cuma Nama'])
            ->assertOk()
            ->assertJsonPath('data.telepon', $user->telepon)
            ->assertJsonPath('data.jabatan', $user->jabatan);
    }

    /** Email TIDAK bisa diganti sendiri — lihat docblock `PerbaruiProfilRequest`. */
    public function test_patch_saya_tidak_bisa_mengganti_email_atau_status(): void
    {
        $user = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI);

        $this->withHeaders($this->bearer($user))
            ->patchJson('/api/pelanggan/v1/saya', [
                'email' => 'dibajak@contoh.test',
                'status' => User::STATUS_AKTIF,
                'role' => User::ROLE_ADMIN,
                'nama' => 'Tetap Boleh',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.status', User::STATUS_PENDING_VERIFIKASI);

        $user->refresh();
        $this->assertSame(User::ROLE_PELANGGAN, $user->role);
    }

    public function test_ganti_sandi_butuh_sandi_lama_yang_benar(): void
    {
        $user = $this->anggota();

        $this->withHeaders($this->bearer($user))
            ->postJson('/api/pelanggan/v1/saya/ganti-sandi', [
                'sandi_lama' => 'bukan-sandi-saya',
                'sandi' => 'SandiBaru#2026Sidik',
            ])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'sandi_lama_salah');

        $this->assertTrue(Hash::check($this->sandiBenar, (string) $user->fresh()->password));
    }

    /** REQ-AUTH-09 — sesi LAIN dicabut, sesi yang dipakai bertahan. */
    public function test_REQ_AUTH_09_ganti_sandi_mencabut_sesi_lain_saja(): void
    {
        $user = $this->anggota();
        $lain = $this->bearer($user);
        $ini = $this->bearer($user);

        $this->withHeaders($ini)
            ->postJson('/api/pelanggan/v1/saya/ganti-sandi', [
                'sandi_lama' => $this->sandiBenar,
                'sandi' => 'SandiBaru#2026Sidik',
            ])
            ->assertOk()
            ->assertJsonPath('data.sesi_dicabut', 1);

        $this->lupakanSesiGuard();
        $this->withHeaders($lain)->getJson('/api/pelanggan/v1/saya')->assertUnauthorized();

        $this->lupakanSesiGuard();
        $this->withHeaders($ini)->getJson('/api/pelanggan/v1/saya')->assertOk();

        $this->assertTrue(Hash::check('SandiBaru#2026Sidik', (string) $user->fresh()->password));
    }

    public function test_sandi_baru_ikut_aturan_kekuatan(): void
    {
        $user = $this->anggota();

        $this->withHeaders($this->bearer($user))
            ->postJson('/api/pelanggan/v1/saya/ganti-sandi', [
                'sandi_lama' => $this->sandiBenar,
                'sandi' => 'pendek12',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sandi');
    }

    /** Lupa sandi selalu 200 — email asing tidak boleh bisa dibedakan. */
    public function test_lupa_sandi_selalu_200_dan_tidak_mengirim_ke_email_asing(): void
    {
        $user = $this->anggota();

        $ada = $this->postJson('/api/pelanggan/v1/auth/lupa-sandi', ['email' => $user->email])->assertOk();
        $asing = $this->postJson('/api/pelanggan/v1/auth/lupa-sandi', ['email' => 'asing@contoh.test'])->assertOk();

        $this->assertSame($ada->json('message'), $asing->json('message'));
        Mail::assertSent(KodeOtpEmail::class, 1);
    }

    /** Akun lab tidak boleh dapat OTP dari pintu pelanggan. */
    public function test_lupa_sandi_tidak_mengirim_otp_ke_akun_internal(): void
    {
        $admin = User::factory()->create([
            'organization_id' => $this->organisasi()->id,
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->postJson('/api/pelanggan/v1/auth/lupa-sandi', ['email' => $admin->email])->assertOk();

        Mail::assertNothingSent();
    }

    /** REQ-AUTH-09 — atur ulang sandi mencabut SEMUA sesi, tanpa kecuali. */
    public function test_atur_ulang_sandi_mengganti_sandi_dan_mencabut_semua_sesi(): void
    {
        $user = $this->anggota();
        $lama = $this->bearer($user);

        $this->postJson('/api/pelanggan/v1/auth/lupa-sandi', ['email' => $user->email])->assertOk();
        $kode = $this->kodeTerkirim((string) $user->email);

        $this->postJson('/api/pelanggan/v1/auth/atur-ulang-sandi', [
            'email' => $user->email,
            'otp' => $kode,
            'sandi' => 'SandiHasilReset#2026',
        ])->assertOk();

        $this->assertTrue(Hash::check('SandiHasilReset#2026', (string) $user->fresh()->password));

        $this->lupakanSesiGuard();
        $this->withHeaders($lama)->getJson('/api/pelanggan/v1/saya')->assertUnauthorized();

        $this->postJson('/api/pelanggan/v1/auth/masuk', [
            'email' => $user->email,
            'sandi' => 'SandiHasilReset#2026',
        ])->assertOk();
    }

    /** OTP atur ulang sandi juga sekali pakai & bisa kedaluwarsa. */
    public function test_otp_atur_ulang_sandi_sekali_pakai_dan_kedaluwarsa(): void
    {
        $user = $this->anggota();

        $this->postJson('/api/pelanggan/v1/auth/lupa-sandi', ['email' => $user->email])->assertOk();
        $kode = $this->kodeTerkirim((string) $user->email);

        $this->travel(OtpPelanggan::BERLAKU_MENIT + 1)->minutes();

        $this->postJson('/api/pelanggan/v1/auth/atur-ulang-sandi', [
            'email' => $user->email,
            'otp' => $kode,
            'sandi' => 'SandiHasilReset#2026',
        ])->assertStatus(422)->assertJsonPath('kode', 'otp_salah');

        $this->assertTrue(Hash::check($this->sandiBenar, (string) $user->fresh()->password));
    }

    /**
     * OTP verifikasi email TIDAK bisa dipakai buat mengatur ulang sandi.
     *
     * Dua tujuan disimpan di tabel yang sama; tanpa saringan `tujuan`, kode
     * yang dikirim buat memverifikasi email jadi kunci pengganti sandi.
     */
    public function test_otp_beda_tujuan_tidak_bisa_saling_dipakai(): void
    {
        $user = $this->pelanggan(User::STATUS_PENDING_EMAIL);

        $this->postJson('/api/pelanggan/v1/auth/kirim-ulang-otp', ['email' => $user->email])->assertOk();
        $kodeEmail = $this->kodeTerkirim((string) $user->email);

        $this->postJson('/api/pelanggan/v1/auth/atur-ulang-sandi', [
            'email' => $user->email,
            'otp' => $kodeEmail,
            'sandi' => 'SandiHasilReset#2026',
        ])->assertStatus(422)->assertJsonPath('kode', 'otp_salah');
    }
}
