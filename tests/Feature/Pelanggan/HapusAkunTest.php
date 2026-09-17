<?php

namespace Tests\Feature\Pelanggan;

use App\Mail\Pelanggan\PermintaanHapusAkunWeb;
use App\Models\CustomerMember;
use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\Pelanggan\PerusahaanTanpaPicUtama;
use App\Services\Pelanggan\PenganonimAkun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/** REQ-AUTH-11 & REQ-PRV-03 — hapus akun, dari aplikasi dan dari web. */
class HapusAkunTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
        Notification::fake();
        Mail::fake();
    }

    /** @return array<string, mixed> */
    private function badan(array $ubah = []): array
    {
        return ['sandi' => $this->sandiBenar, 'konfirmasi' => true, ...$ubah];
    }

    // ------------------------------------------------------- dari aplikasi

    /**
     * INTI REQ-AUTH-11 — data pribadi hilang, rekaman lab tetap.
     *
     * Isi baris user diadu sebagai HIMPUNAN, bukan cuma `dianonimkan_pada`
     * terisi: kolom pribadi yang lupa dibersihkan tidak memunculkan error apa
     * pun, jadi yang harus menangkapnya perbandingan yang menyebut tiap kolom.
     */
    public function test_REQ_AUTH_11_data_pribadi_dianonimkan_rekaman_lab_tetap(): void
    {
        $user = $this->anggota();
        $perusahaan = $user->keanggotaan()->first()->customer;
        $idLama = $user->id;

        DeviceToken::create([
            'user_id' => $user->id,
            'token' => 'fcm-contoh',
            'platform' => 'android',
            'aplikasi' => DeviceToken::APLIKASI_PELANGGAN,
        ]);

        $this->withHeaders($this->bearer($user))
            ->deleteJson('/api/pelanggan/v1/saya', $this->badan())
            ->assertOk()
            ->assertJsonStructure(['message', 'data' => ['dihapus', 'tetap_tersimpan']]);

        $sesudah = User::query()->findOrFail($idLama);

        $this->assertSame('Akun Dihapus', $sesudah->name);
        $this->assertSame("dihapus-{$idLama}@anonim.invalid", $sesudah->email);
        $this->assertNull($sesudah->telepon);
        $this->assertNull($sesudah->jabatan);
        $this->assertNotNull($sesudah->dianonimkan_pada);
        $this->assertSame(User::STATUS_NONAKTIF, $sesudah->status);

        // Sandi diacak — kalau dibiarkan, satu-satunya yang menahan masuk
        // tinggal pemeriksaan status.
        $this->assertFalse(Hash::check($this->sandiBenar, (string) $sesudah->password));

        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $idLama)->count());
        $this->assertDatabaseMissing('device_tokens', ['user_id' => $idLama]);
        $this->assertDatabaseHas('customer_members', [
            'user_id' => $idLama,
            'status' => CustomerMember::STATUS_NONAKTIF,
        ]);

        // BR-07 — rekaman lab TIDAK ikut hilang.
        $this->assertDatabaseHas('customers', ['id' => $perusahaan->id, 'nama' => $perusahaan->nama]);
    }

    /**
     * Tiap kolom pribadi yang disebut REQ-AUTH-11 memang ikut dibersihkan.
     *
     * Diadu lewat daftar yang dipulangkan servicenya, bukan lewat pemeriksaan
     * satu-satu di atas: begitu kolom pribadi BARU ditambahkan ke `users`
     * (misal foto profil, yang disebut REQ-AUTH-11 tapi kolomnya belum ada),
     * yang harus memerah test ini — bukan menunggu ada yang ingat.
     */
    public function test_daftar_kolom_pribadi_mencakup_yang_disebut_requirement(): void
    {
        $user = $this->anggota();
        $kolom = array_keys(app(PenganonimAkun::class)->kolomPribadi($user));

        foreach (['name', 'email', 'telepon', 'jabatan', 'password', 'dianonimkan_pada'] as $wajib) {
            $this->assertContains($wajib, $kolom, "Kolom pribadi `{$wajib}` nggak ikut dianonimkan.");
        }

        // Penjaga buat masa depan: kalau `users` suatu hari punya kolom foto
        // profil, dia WAJIB masuk daftar itu (REQ-AUTH-11 menyebutnya).
        foreach (['foto', 'foto_profil', 'avatar'] as $mungkinNanti) {
            if (Schema::hasColumn('users', $mungkinNanti)) {
                $this->assertContains(
                    $mungkinNanti,
                    $kolom,
                    "Kolom `{$mungkinNanti}` sudah ada di `users` tapi nggak ikut dianonimkan (REQ-AUTH-11).",
                );
            }
        }
    }

    /** Sandi salah → tidak ada yang tersentuh sama sekali. */
    public function test_sandi_salah_membatalkan_seluruhnya(): void
    {
        $user = $this->anggota();

        $this->withHeaders($this->bearer($user))
            ->deleteJson('/api/pelanggan/v1/saya', $this->badan(['sandi' => 'bukan-sandi-saya']))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'sandi_salah');

        $sesudah = $user->fresh();
        $this->assertNull($sesudah->dianonimkan_pada);
        $this->assertSame($user->email, $sesudah->email);
        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $user->id)->count());
    }

    /** Konfirmasi wajib — satu tap yang salah tidak boleh cukup. */
    public function test_tanpa_konfirmasi_ditolak(): void
    {
        $user = $this->anggota();

        $this->withHeaders($this->bearer($user))
            ->deleteJson('/api/pelanggan/v1/saya', $this->badan(['konfirmasi' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('konfirmasi');

        $this->assertNull($user->fresh()->dianonimkan_pada);
    }

    /** Akun yang sudah dihapus tidak bisa dipakai masuk lagi. */
    public function test_akun_terhapus_tidak_bisa_masuk_lagi(): void
    {
        $user = $this->anggota();
        $emailLama = (string) $user->email;

        $this->withHeaders($this->bearer($user))
            ->deleteJson('/api/pelanggan/v1/saya', $this->badan())
            ->assertOk();

        $this->permintaanBaru();

        $this->postJson('/api/pelanggan/v1/auth/masuk', [
            'email' => $emailLama,
            'sandi' => $this->sandiBenar,
        ])->assertStatus(401)->assertJsonPath('kode', 'kredensial_salah');
    }

    /** Sesi yang sedang dipakai ikut mati begitu akunnya dihapus. */
    public function test_sesi_yang_dipakai_ikut_dicabut(): void
    {
        $user = $this->anggota();
        $sesi = $this->bearer($user);

        $this->withHeaders($sesi)->deleteJson('/api/pelanggan/v1/saya', $this->badan())->assertOk();

        $this->permintaanBaru();
        $this->withHeaders($sesi)->getJson('/api/pelanggan/v1/saya')->assertUnauthorized();
    }

    /**
     * REQ-AUTH-11 — PIC utama TERAKHIR tetap boleh menghapus akunnya, dan admin
     * lab dikabari perusahaannya jadi tanpa PIC utama.
     *
     * Beda dari `nonaktifkan` yang menolak (REQ-ANG-02): menahan orang keluar
     * dari layanan demi masalah organisasi yang bukan miliknya itu menyandera.
     */
    public function test_REQ_AUTH_11_pic_utama_terakhir_boleh_hapus_dan_admin_dikabari(): void
    {
        $admin = $this->adminLab();
        $pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $perusahaan = $pic->keanggotaan()->first()->customer;

        // Buktikan dulu bahwa jalur NONAKTIFKAN memang menolak — kalau tidak,
        // test ini cuma menunjukkan dua jalur yang kebetulan sama-sama longgar.
        $this->withHeaders($this->bearer($pic))
            ->postJson("/api/pelanggan/v1/anggota/{$pic->keanggotaan()->first()->id}/nonaktifkan")
            ->assertStatus(422)
            ->assertJsonPath('kode', 'pic_utama_terakhir');

        $this->permintaanBaru();

        $this->withHeaders($this->bearer($pic))
            ->deleteJson('/api/pelanggan/v1/saya', $this->badan())
            ->assertOk();

        $this->assertNotNull($pic->fresh()->dianonimkan_pada);
        Notification::assertSentTo($admin, PerusahaanTanpaPicUtama::class);

        $this->assertSame(0, CustomerMember::query()
            ->where('customer_id', $perusahaan->id)
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->count());
    }

    /** Perusahaan yang MASIH punya PIC utama lain tidak memicu notifikasi. */
    public function test_admin_tidak_dikabari_kalau_masih_ada_pic_utama_lain(): void
    {
        $admin = $this->adminLab();
        $pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $perusahaan = $pic->keanggotaan()->first()->customer;

        $picKedua = $this->pelanggan();
        CustomerMember::create([
            'organization_id' => $perusahaan->organization_id,
            'customer_id' => $perusahaan->id,
            'user_id' => $picKedua->id,
            'peran' => CustomerMember::PERAN_PIC_UTAMA,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $this->withHeaders($this->bearer($pic))
            ->deleteJson('/api/pelanggan/v1/saya', $this->badan())
            ->assertOk();

        Notification::assertNotSentTo($admin, PerusahaanTanpaPicUtama::class);
    }

    /** Konsultan: SEMUA keanggotaannya dilepas, bukan cuma yang sedang aktif. */
    public function test_semua_keanggotaan_dilepas_bukan_cuma_satu(): void
    {
        $konsultan = $this->anggota(CustomerMember::PERAN_STAF);
        $kedua = $this->perusahaan();
        CustomerMember::create([
            'organization_id' => $kedua->organization_id,
            'customer_id' => $kedua->id,
            'user_id' => $konsultan->id,
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $this->withHeaders($this->bearer($konsultan))
            ->deleteJson('/api/pelanggan/v1/saya', $this->badan())
            ->assertOk();

        $this->assertSame(0, CustomerMember::query()
            ->where('user_id', $konsultan->id)
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->count());
    }

    /** Akun yang masih menunggu verifikasi juga berhak menghapus akunnya. */
    public function test_akun_menunggu_verifikasi_boleh_hapus_akun(): void
    {
        $user = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI);
        $this->pengajuan($user);

        $this->withHeaders($this->bearer($user))
            ->deleteJson('/api/pelanggan/v1/saya', $this->badan())
            ->assertOk();

        $this->assertNotNull($user->fresh()->dianonimkan_pada);
    }

    /** Email hasil anonimisasi unik — dua akun terhapus tidak bentrok. */
    public function test_dua_akun_terhapus_tidak_bentrok_emailnya(): void
    {
        foreach ([$this->anggota(), $this->anggota()] as $user) {
            $this->permintaanBaru();

            $this->withHeaders($this->bearer($user))
                ->deleteJson('/api/pelanggan/v1/saya', $this->badan())
                ->assertOk();
        }

        $this->assertSame(
            2,
            User::query()->where('email', 'like', 'dihapus-%@anonim.invalid')->count(),
        );
    }

    // ------------------------------------------------------------ dari web

    /** REQ-PRV-03 — halaman publik, kebuka tanpa akun & tanpa aplikasi. */
    public function test_REQ_PRV_03_halaman_hapus_akun_publik(): void
    {
        $this->get('/hapus-akun')
            ->assertOk()
            ->assertSee('Hapus Akun', false)
            // Syarat Google Play: apa yang dihapus DAN apa yang disimpan.
            ->assertSee('Yang dihapus', false)
            ->assertSee('Yang TETAP tersimpan', false);
    }

    /** Halaman itu tetap hidup walau modul pelanggannya dimatikan. */
    public function test_halaman_web_tidak_ikut_mati_waktu_flag_dimatikan(): void
    {
        config(['pelanggan.fitur' => false]);

        $this->get('/hapus-akun')->assertOk();
    }

    /** Permintaan web diteruskan ke admin lab, beserta ID penggunanya. */
    public function test_permintaan_web_diteruskan_ke_admin(): void
    {
        $admin = $this->adminLab();
        $user = $this->anggota();

        $this->post('/hapus-akun', ['email' => $user->email, 'alasan' => 'Saya pindah kerja.'])
            ->assertRedirect();

        Mail::assertSent(PermintaanHapusAkunWeb::class, fn (PermintaanHapusAkunWeb $mail) => $mail->hasTo($admin->email)
            && $mail->userId === $user->id
            && $mail->alasan === 'Saya pindah kerja.');
    }

    /**
     * Email asing dan email terdaftar dibalas SAMA PERSIS.
     *
     * Kalau dibedakan, halaman publik ini jadi alat menyisir email pelanggan
     * PT Sidik dari luar — tanpa akun, tanpa aplikasi.
     */
    public function test_balasan_web_sama_buat_email_terdaftar_dan_asing(): void
    {
        // Admin lab HARUS ada: tanpa tujuan, `teruskanKeAdmin()` mencatat
        // peringatan lalu diam — dan test ini jadi hijau karena nol email
        // terkirim buat KEDUA email, bukan karena balasannya memang sama.
        $this->adminLab();
        $user = $this->anggota();

        $terdaftar = $this->post('/hapus-akun', ['email' => $user->email]);
        $asing = $this->post('/hapus-akun', ['email' => 'asing@contoh.test']);

        $this->assertSame($terdaftar->status(), $asing->status());
        $this->assertSame(
            $terdaftar->headers->get('Location'),
            $asing->headers->get('Location'),
        );

        // Yang berbeda cuma pekerjaan admin, bukan informasi yang keluar.
        Mail::assertSent(PermintaanHapusAkunWeb::class, 1);
    }

    /** Akun lab tidak bisa diminta hapus lewat jalur pelanggan. */
    public function test_email_akun_internal_tidak_diteruskan(): void
    {
        $teknisi = $this->adminLab(User::ROLE_TEKNISI);

        $this->post('/hapus-akun', ['email' => $teknisi->email])->assertRedirect();

        Mail::assertNothingSent();
    }

    /** Akun yang SUDAH dihapus tidak memicu email kedua. */
    public function test_akun_yang_sudah_dianonimkan_tidak_diteruskan_lagi(): void
    {
        $user = $this->anggota();
        $emailLama = (string) $user->email;

        app(PenganonimAkun::class)->untuk($user);
        Mail::fake();

        $this->post('/hapus-akun', ['email' => $emailLama])->assertRedirect();

        Mail::assertNothingSent();
    }

    public function test_email_ngawur_ditolak_validasi(): void
    {
        $this->post('/hapus-akun', ['email' => 'bukan-email'])
            ->assertSessionHasErrors('email');
    }
}
