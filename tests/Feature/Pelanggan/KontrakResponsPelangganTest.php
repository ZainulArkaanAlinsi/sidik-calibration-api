<?php

namespace Tests\Feature\Pelanggan;

use App\Mail\Pelanggan\KodeOtpEmail;
use App\Mail\Pelanggan\UndanganEmail;
use App\Models\CustomerMember;
use App\Models\PengajuanAkunPelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Bentuk respons `/api/pelanggan/v1` dibekukan ke berkas fixture.
 *
 * ## Kenapa fixture, padahal sudah ada `assertJsonPath` di test lain
 *
 * `assertJsonPath` membuktikan kunci yang DISEBUT ada. Dia diam total soal
 * kunci yang HILANG dan kunci yang DITAMBAH — dan buat modul ini dua-duanya
 * perubahan merusak (NFR-12): aplikasi yang sudah terpasang di HP ribuan orang
 * tidak bisa disuruh ikut berubah hari itu juga.
 *
 * Perbandingannya sengaja SELURUH badan respons, bukan sebagian. Jadi kunci
 * baru yang ditambahkan tanpa sadar bikin test ini merah, dan yang menambahkan
 * harus memutuskan dengan sadar: kunci tambahan boleh (aplikasi lama
 * mengabaikannya), kunci hilang atau berganti nama TIDAK.
 *
 * ## Nilai yang berubah tiap jalan
 *
 * `id`, token, dan waktu tidak mungkin sama antar-jalan. Yang dibekukan
 * BENTUKNYA: nilai-nilai itu diganti penanda seperti `<int>` dan `<iso8601>`
 * lewat [bekukan()]. Yang tersisa di berkas fixture jadi bisa dibaca manusia
 * dan dipakai apa adanya di `docs/kontrak-api-pelanggan.md`.
 *
 * ## Memperbarui fixture
 *
 * `TULIS_FIXTURE=1 php artisan test --filter=KontrakResponsPelangganTest`
 *
 * Disengaja harus diketik: fixture yang menulis ulang dirinya sendiri tiap kali
 * merah bukan penjaga apa pun — dia cuma mencatat apa pun yang kebetulan
 * dikeluarkan kode hari itu.
 */
class KontrakResponsPelangganTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
        Mail::fake();
    }

    private function berkas(string $nama): string
    {
        return base_path('tests/Fixtures/pelanggan/'.$nama.'.json');
    }

    /**
     * Ganti nilai yang berubah tiap jalan dengan penanda tipenya.
     *
     * @param  mixed  $nilai
     * @return mixed
     */
    private function bekukan($nilai, string $kunci = '')
    {
        if (is_array($nilai)) {
            $hasil = [];

            foreach ($nilai as $k => $v) {
                $hasil[$k] = $this->bekukan($v, (string) $k);
            }

            return $hasil;
        }

        if (in_array($kunci, ['id', 'customer_id', 'member_id', 'user_id'], true) && is_int($nilai)) {
            return '<int>';
        }

        if ($kunci === 'token') {
            return '<token>';
        }

        if (is_string($nilai) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/', $nilai)) {
            return '<iso8601>';
        }

        return $nilai;
    }

    private function aduKeFixture(string $nama, array $badan): void
    {
        $beku = $this->bekukan($badan);
        $berkas = $this->berkas($nama);

        if (env('TULIS_FIXTURE') === '1') {
            file_put_contents($berkas, json_encode($beku, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }

        $this->assertFileExists($berkas, "Fixture {$nama}.json belum ada. Bikin dengan TULIS_FIXTURE=1.");

        $this->assertSame(
            json_decode((string) file_get_contents($berkas), true),
            $beku,
            "Bentuk respons `{$nama}` berubah. Kunci HILANG atau BERGANTI NAMA itu perubahan merusak buat\n".
            "aplikasi yang sudah terpasang (NFR-12) — kalau memang disengaja, buat v2.\n".
            'Kunci TAMBAHAN aman; perbarui fixture dengan TULIS_FIXTURE=1.',
        );
    }

    public function test_bentuk_respons_daftar(): void
    {
        $badan = $this->postJson('/api/pelanggan/v1/auth/daftar', [
            'nama' => 'Budi Pendaftar',
            'email' => 'budi@contoh.test',
            'sandi' => $this->sandiBenar,
            'telepon' => '0812-3456-7890',
            'jabatan' => 'QA Supervisor',
            'nama_perusahaan' => 'PT Contoh Industri',
            'alamat_perusahaan' => 'Jl. Contoh No. 1, Bandung',
            'setuju_syarat' => true,
        ])->assertCreated()->json();

        $this->aduKeFixture('daftar', $badan);
    }

    public function test_bentuk_respons_masuk_anggota_aktif(): void
    {
        $user = $this->anggota();
        $user->forceFill(['name' => 'Budi Anggota', 'email' => 'budi@contoh.test'])->save();

        $badan = $this->postJson('/api/pelanggan/v1/auth/masuk', [
            'email' => 'budi@contoh.test',
            'sandi' => $this->sandiBenar,
            'nama_perangkat' => 'Pixel 8a Budi',
        ])->assertOk()->json();

        // Nama perusahaannya bernomor urut supaya tidak menabrak UNIQUE;
        // dipatok di sini supaya fixture-nya tidak berubah gara-gara urutan test.
        $badan['data']['user']['keanggotaan'][0]['nama_perusahaan'] = 'PT Contoh Pelanggan';

        $this->aduKeFixture('masuk', $badan);
    }

    public function test_bentuk_respons_saya_menunggu_verifikasi(): void
    {
        $user = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI, [
            'name' => 'Budi Menunggu',
            'email' => 'budi@contoh.test',
        ]);
        $this->pengajuan($user);

        $badan = $this->withHeaders($this->bearer($user))
            ->getJson('/api/pelanggan/v1/saya')
            ->assertOk()
            ->json();

        $this->aduKeFixture('saya-menunggu-verifikasi', $badan);
    }

    public function test_bentuk_respons_saya_ditolak(): void
    {
        $user = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI, [
            'name' => 'Budi Ditolak',
            'email' => 'budi@contoh.test',
        ]);
        $this->pengajuan($user, PengajuanAkunPelanggan::STATUS_DITOLAK)
            ->forceFill(['alasan_tolak' => 'Nama perusahaan tidak cocok dengan data kami.'])->save();

        $badan = $this->withHeaders($this->bearer($user))
            ->getJson('/api/pelanggan/v1/saya')
            ->assertOk()
            ->json();

        $this->aduKeFixture('saya-ditolak', $badan);
    }

    public function test_bentuk_respons_terima_undangan(): void
    {
        Mail::fake();

        $perusahaan = $this->perusahaan('PT Contoh Pelanggan');

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", [
                'email' => 'budi@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ])->assertCreated();

        $kode = null;
        Mail::assertSent(UndanganEmail::class, function ($mail) use (&$kode) {
            $kode = $mail->kode;

            return true;
        });

        $this->lupakanSesiGuard();

        $badan = $this->postJson('/api/pelanggan/v1/auth/terima-undangan', [
            'email' => 'budi@contoh.test',
            'kode' => $kode,
            'nama' => 'Budi Diundang',
            'sandi' => $this->sandiBenar,
            'telepon' => '0812-3456-7890',
            'jabatan' => 'QA Staff',
            'setuju_syarat' => true,
            'nama_perangkat' => 'Pixel 8a Budi',
        ])->assertCreated()->json();

        $this->aduKeFixture('terima-undangan', $badan);
    }

    public function test_bentuk_respons_antrean_pengajuan_sisi_lab(): void
    {
        $pemohon = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI, [
            'name' => 'Budi Menunggu',
            'email' => 'budi@contoh.test',
        ]);
        $this->pengajuan($pemohon);

        // Alamatnya DIPATOK, tidak dibiarkan dari factory: `CustomerFactory`
        // memakai faker, jadi nilainya berubah tiap kali suite jalan dan
        // fixture ini jadi merah bergantian tanpa ada yang berubah di kode.
        // Ketahuan waktu ditulis — jalan pertama hijau (karena fixture-nya baru
        // ditulis di jalan yang sama), jalan kedua merah.
        $this->perusahaan('PT Klaim Pendaftar')
            ->forceFill(['alamat' => 'Jl. Contoh No. 1, Bandung'])
            ->save();

        $badan = $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->getJson('/api/admin/pengajuan-akun')
            ->assertOk()
            ->json();

        $this->aduKeFixture('admin-antrean-pengajuan', $badan);
    }

    public function test_bentuk_respons_daftar_anggota(): void
    {
        $pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $pic->forceFill(['name' => 'Budi PIC', 'email' => 'budi@contoh.test'])->save();

        $perusahaan = $pic->keanggotaan()->first()->customer;
        $perusahaan->forceFill(['nama' => 'PT Contoh Pelanggan', 'maks_anggota' => 50])->save();

        $staf = $this->pelanggan(tambahan: ['name' => 'Sari Staf', 'email' => 'sari@contoh.test']);
        CustomerMember::create([
            'organization_id' => $perusahaan->organization_id,
            'customer_id' => $perusahaan->id,
            'user_id' => $staf->id,
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $badan = $this->withHeaders($this->bearer($pic))
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertOk()
            ->json();

        $this->aduKeFixture('anggota', $badan);
    }

    /** Bentuk ERROR ikut dibekukan — aplikasi bercabang pada `kode`, bukan pada `message`. */
    public function test_bentuk_respons_error_kredensial_salah(): void
    {
        $badan = $this->postJson('/api/pelanggan/v1/auth/masuk', [
            'email' => 'asing@contoh.test',
            'sandi' => 'salah-sekali',
        ])->assertStatus(401)->json();

        $this->aduKeFixture('error-kredensial-salah', $badan);
    }

    public function test_bentuk_respons_error_akun_belum_diverifikasi(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'aplikasi:pelanggan', 'pelanggan.aktif'])
            ->get('/uji/kontrak-data', fn () => response()->json(['data' => []]));

        $user = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI);

        $badan = $this->withHeaders($this->bearer($user))
            ->getJson('/uji/kontrak-data')
            ->assertStatus(403)
            ->json();

        $this->aduKeFixture('error-akun-belum-diverifikasi', $badan);
    }

    public function test_bentuk_respons_app_status(): void
    {
        $badan = $this->getJson('/api/pelanggan/v1/app/status')->assertOk()->json();

        $this->aduKeFixture('app-status', $badan);
    }

    /** Email OTP memang terkirim — fixture di atas tidak boleh hijau tanpa jalur emailnya jalan. */
    public function test_email_otp_terkirim_saat_daftar(): void
    {
        $this->postJson('/api/pelanggan/v1/auth/daftar', [
            'nama' => 'Budi Pendaftar',
            'email' => 'budi@contoh.test',
            'sandi' => $this->sandiBenar,
            'telepon' => '0812-3456-7890',
            'jabatan' => 'QA Supervisor',
            'nama_perusahaan' => 'PT Contoh Industri',
            'setuju_syarat' => true,
        ])->assertCreated();

        Mail::assertSent(KodeOtpEmail::class, fn (KodeOtpEmail $mail) => $mail->hasTo('budi@contoh.test')
            && preg_match('/^\d{6}$/', $mail->kode) === 1);
    }
}
