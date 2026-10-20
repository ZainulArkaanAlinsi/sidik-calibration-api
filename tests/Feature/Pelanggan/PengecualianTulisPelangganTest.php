<?php

namespace Tests\Feature\Pelanggan;

use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Aplikasi pelanggan hanya MEMBACA — keputusan pemilik proyek 20 Okt 2026
 * (lanjutan K-50-09). Rute yang menulis tinggal urusan akun sendiri: masuk/
 * keluar, sandi, profil `/saya`, perangkat push, tanda baca notifikasi,
 * preferensi notifikasi, dan kelola anggota oleh PIC utama.
 *
 * Daftar rutenya dibaca dari ROUTER, bukan diketik ulang: rute tulis baru di
 * `routes/api_pelanggan.php` (mis. menghidupkan lagi `PATCH /alat/{alat}`)
 * memerahkan test ini sampai ada keputusan yang menambahkannya ke daftar di
 * bawah — tidak bisa lolos diam-diam.
 */
class PengecualianTulisPelangganTest extends TestCase
{
    private const PREFIX = 'api/pelanggan/v1/';

    /** Rute non-GET yang DIIZINKAN — "METHOD uri" tanpa prefix. */
    private const DAFTAR_IZIN = [
        // Masuk, undangan, sandi lupa (tamu).
        'POST auth/masuk',
        'POST auth/terima-undangan',
        'POST auth/lupa-sandi',
        'POST auth/atur-ulang-sandi',
        // Sesi & akun sendiri.
        'POST auth/keluar',
        'POST auth/keluar-semua',
        'PATCH saya',
        'DELETE saya',
        'POST saya/ganti-sandi',
        // Token push perangkat.
        'POST perangkat',
        'DELETE perangkat',
        // Tanda baca notifikasi.
        'POST notifikasi/dibaca-semua',
        'POST notifikasi/{notifikasi}/dibaca',
        // Saklar notifikasi per perusahaan.
        'PUT preferensi-notifikasi',
        // Kelola anggota (PIC utama).
        'POST anggota/undangan',
        'DELETE anggota/undangan/{undangan}',
        'POST anggota/{anggota}/nonaktifkan',
    ];

    /** @return list<string> */
    private function ruteTulisTerdaftar(): array
    {
        $hasil = [];

        foreach (Router::getRoutes() as $rute) {
            if (! str_starts_with($rute->uri(), self::PREFIX)) {
                continue;
            }

            $uri = substr($rute->uri(), strlen(self::PREFIX));

            foreach (array_diff($rute->methods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
                $hasil[] = "{$method} {$uri}";
            }
        }

        sort($hasil);

        return $hasil;
    }

    public function test_rute_tulis_pelanggan_hanya_yang_diizinkan(): void
    {
        $tidakDiizinkan = array_values(array_diff($this->ruteTulisTerdaftar(), self::DAFTAR_IZIN));

        $this->assertSame(
            [],
            $tidakDiizinkan,
            'Rute tulis pelanggan baru tanpa keputusan pemilik proyek. Aplikasi '
                .'pelanggan hanya membaca (20 Okt 2026); tambahkan ke DAFTAR_IZIN '
                .'hanya bila ada keputusan tertulis.',
        );
    }

    /** Daftar izin yang basi (rutenya sudah tiada) juga harus ketahuan. */
    public function test_daftar_izin_tidak_basi(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff(self::DAFTAR_IZIN, $this->ruteTulisTerdaftar())),
        );
    }

    public function test_ubah_alat_dicabut(): void
    {
        $this->assertNotContains('PATCH alat/{alat}', $this->ruteTulisTerdaftar());
        $this->assertNotContains('PUT alat/{alat}', $this->ruteTulisTerdaftar());
    }
}
