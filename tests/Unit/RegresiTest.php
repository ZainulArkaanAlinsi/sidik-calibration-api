<?php

namespace Tests\Unit;

use App\Support\Regresi;
use PHPUnit\Framework\TestCase;

/**
 * R² harus keluar sama persis kayak `RSQ()` Excel — semua angka pembanding yang
 * dipegang lab lahir dari sana, jadi rumus yang "mirip tapi beda dikit" bikin
 * angka sertifikat nggak bisa diadu ke masternya.
 *
 * Yang dijaga paling ketat di sini bukan angka benernya, tapi KAPAN NULL.
 * Pembagi nol di rumus R² balikin `NAN`, dan `NAN` yang lolos ke snapshot
 * kecetak `0,0000` di PDF — angka yang kelihatan sah padahal nggak pernah
 * kehitung. Di sertifikat terakreditasi itu kesalahan yang paling sulit
 * ketahuan.
 */
class RegresiTest extends TestCase
{
    /**
     * Data blok %T master Spectrophotometer (`SERTIFIKAT.csv` baris 47–51).
     * `RSQ()` di Excel atas pasangan yang sama ngasih 0,9999219438582861.
     */
    public function test_cocok_sama_rsq_excel_di_data_master(): void
    {
        $standar = [0.0, 9.9, 20.0, 30.1, 100.0];
        $uut = [0.0006666666666666666, 9.665, 19.529999999999998, 29.24933333333333, 100.00266666666668];

        $this->assertEqualsWithDelta(
            0.9999219438582861,
            Regresi::koefisienDeterminasi($standar, $uut),
            1e-12,
        );
    }

    public function test_garis_lurus_sempurna_ngasih_satu(): void
    {
        $this->assertEqualsWithDelta(
            1.0,
            Regresi::koefisienDeterminasi([1.0, 2.0, 3.0, 4.0], [3.0, 5.0, 7.0, 9.0]),
            1e-12,
        );
    }

    /**
     * Garis turun tetap R² = 1. R² nggak ngukur arah, cuma serapat apa titiknya
     * jatuh di satu garis — kalau ini kebalik jadi 0, kolom sertifikat bakal
     * nuduh alat yang linier sempurna sebagai alat yang ngawur.
     */
    public function test_kemiringan_negatif_tetap_satu(): void
    {
        $this->assertEqualsWithDelta(
            1.0,
            Regresi::koefisienDeterminasi([1.0, 2.0, 3.0], [10.0, 8.0, 6.0]),
            1e-12,
        );
    }

    public function test_dua_titik_ditolak_karena_selalu_ngasih_satu(): void
    {
        // Garis lewat dua titik SELALU ada, seberapa pun ngawurnya alatnya.
        // `1` yang lahir begitu bukan bukti linieritas.
        $this->assertNull(Regresi::koefisienDeterminasi([1.0, 2.0], [40.0, 3.0]));
    }

    public function test_deret_beda_panjang_ditolak(): void
    {
        $this->assertNull(Regresi::koefisienDeterminasi([1.0, 2.0, 3.0], [1.0, 2.0]));
    }

    public function test_deret_tanpa_sebaran_ngasih_null_bukan_nan(): void
    {
        // Semua x sama → pembaginya nol. Excel pun `#DIV/0!` di sini.
        $hasil = Regresi::koefisienDeterminasi([5.0, 5.0, 5.0], [1.0, 2.0, 3.0]);

        $this->assertNull($hasil);

        // Sisi y-nya juga, dan dua-duanya sekaligus.
        $this->assertNull(Regresi::koefisienDeterminasi([1.0, 2.0, 3.0], [7.0, 7.0, 7.0]));
        $this->assertNull(Regresi::koefisienDeterminasi([2.0, 2.0, 2.0], [7.0, 7.0, 7.0]));
    }

    public function test_kosong_ngasih_null(): void
    {
        $this->assertNull(Regresi::koefisienDeterminasi([], []));
    }

    /**
     * Sebaran yang beneran acak mesti jatuh jauh dari 1 — kalau nggak, rumusnya
     * lagi ngukur sesuatu yang lain.
     */
    public function test_data_tidak_linier_jauh_dari_satu(): void
    {
        $hasil = Regresi::koefisienDeterminasi([1.0, 2.0, 3.0, 4.0], [5.0, 1.0, 6.0, 2.0]);

        $this->assertNotNull($hasil);
        $this->assertLessThan(0.5, $hasil);
    }
}
