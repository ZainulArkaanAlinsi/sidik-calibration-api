<?php

namespace Tests\Unit;

use App\Support\KemiripanNama;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tiga penjagaan di `KemiripanNama`, masing-masing diadu sendiri.
 *
 * Aturannya sebelumnya hidup di `ImporPelanggan\Pemilah` dan cuma diuji lewat
 * jalur impor — jadi yang terbukti "hasil akhir impornya benar", bukan
 * "aturannya benar". Sekarang dua jalur memakainya (impor + saran pelanggan
 * mirip waktu menyetujui pengajuan), jadi aturannya diuji langsung: penjagaan
 * yang hilang di sini merusak dua fitur sekaligus, dan tidak satu pun
 * memunculkan error.
 */
class KemiripanNamaTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function pasanganMirip(): array
    {
        return [
            'sama persis' => ['pt maju jaya', 'pt maju jaya'],
            'beda satu huruf' => ['pt maju jaya', 'pt maju java'],
            'beda dua huruf' => ['pt maju jaya', 'pt maja java'],
            'tanpa badan usaha' => ['maju jaya', 'maju java'],
            'badan usaha sama-sama ada' => ['cv sumber rejeki', 'cv sumber rejeka'],
        ];
    }

    #[DataProvider('pasanganMirip')]
    public function test_pasangan_mirip_dikenali(string $a, string $b): void
    {
        $this->assertTrue(KemiripanNama::mirip($a, $b));
        $this->assertTrue(KemiripanNama::mirip($b, $a), 'Aturannya nggak simetris.');
    }

    /**
     * PENJAGAAN 1 — badan usaha berbeda = badan hukum berbeda.
     *
     * Jaraknya cuma 2, jadi tanpa penjagaan ini pasangan ini lolos dan muncul
     * berpasangan di layar tinjauan — dan pasangan yang nyaris sama persis itu
     * yang paling gampang di-"gabung saja". NPWP-nya berbeda; sertifikatnya
     * tidak boleh tertukar.
     */
    public function test_badan_usaha_berbeda_tidak_pernah_mirip(): void
    {
        $this->assertSame(2, levenshtein('pt maju', 'cv maju'), 'Premis testnya berubah: jaraknya bukan 2 lagi.');
        $this->assertFalse(KemiripanNama::mirip('pt maju', 'cv maju'));
        $this->assertFalse(KemiripanNama::mirip('ud sentosa', 'pd sentosa'));

        // Yang dibatalkan cuma pasangan yang bentuknya BERBEDA. Satu pihak
        // telanjang tetap boleh dibandingkan — kalau tidak, `maju jaya` yang
        // baru diketik tidak pernah disarankan ke `pt maju jaya` yang sudah ada.
        $this->assertTrue(KemiripanNama::mirip('maju jaya', 'maju jayo'));
    }

    /**
     * PENJAGAAN 2 — batas panjang, dan alasannya BUKAN lagi yang tertulis dulu.
     *
     * Komentar aslinya menyebut `levenshtein()` memulangkan -1 di atas 255 byte
     * sehingga `-1 <= 2` bikin tiap nama panjang "mirip". Benar sampai PHP 7.4;
     * **PHP 8.0 mencabut batas itu**, dan repo ini jalan di 8.4.
     *
     * Test ini mengadu DUA hal sekaligus, dan itu disengaja:
     *
     * 1. kenyataan PHP hari ini (jaraknya dihitung beneran, bukan -1) — kalau
     *    suatu hari berubah lagi, yang merah di sini, bukan di jalur impor;
     * 2. penjaganya TETAP memotong — karena memindahkan aturan ini adalah
     *    refactor, dan refactor tidak mengubah perilaku.
     */
    public function test_batas_panjang_tetap_memotong_walau_alasan_lamanya_sudah_lewat(): void
    {
        $panjangA = str_repeat('a', 300);
        $panjangB = str_repeat('b', 300);

        $this->assertSame(
            300,
            levenshtein($panjangA, $panjangB),
            'PHP berubah lagi: batas 255 byte kembali, atau jaraknya dihitung lain.',
        );

        $this->assertFalse(
            KemiripanNama::mirip($panjangA, $panjangB),
            'Penjaga panjang dicabut. Itu perubahan perilaku, dan tempatnya bukan di refactor.',
        );

        // Yang dikorbankan penjaga itu: pasangan panjang yang SEBENARNYA mirip
        // ikut dijawab "tidak mirip". Ditulis terang supaya siapa pun yang mau
        // mencabutnya tahu persis apa yang dia dapatkan.
        $miripTapiPanjang = str_repeat('a', 300);
        $this->assertSame(1, levenshtein($miripTapiPanjang, str_repeat('a', 299).'b'));
        $this->assertFalse(KemiripanNama::mirip($miripTapiPanjang, str_repeat('a', 299).'b'));
    }

    /** PENJAGAAN 3 — beda panjang di atas ambang langsung ditolak. */
    public function test_beda_panjang_jauh_tidak_mirip(): void
    {
        $this->assertFalse(KemiripanNama::mirip('pt maju', 'pt maju jaya sentosa abadi'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function pasanganBeda(): array
    {
        return [
            'perusahaan lain' => ['pt maju jaya', 'pt sumber rejeki'],
            'beda tiga huruf' => ['pt maju jaya', 'pt maju jayoxx'],
            'satu kosong' => ['', 'pt maju jaya'],
        ];
    }

    #[DataProvider('pasanganBeda')]
    public function test_pasangan_beda_tidak_dikenali_mirip(string $a, string $b): void
    {
        $this->assertFalse(KemiripanNama::mirip($a, $b));
    }

    /** Dua nama kosong sama persis — itu urusan pemanggil, bukan aturan ini. */
    public function test_dua_kosong_dianggap_sama(): void
    {
        $this->assertTrue(KemiripanNama::mirip('', ''));
    }
}
