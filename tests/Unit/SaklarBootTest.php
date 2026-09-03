<?php

namespace Tests\Unit;

use App\Support\SaklarBoot;
use PHPUnit\Framework\TestCase;

/**
 * Saklar boot dibaca persis seperti `docker/entrypoint.sh` membacanya.
 *
 * ## Kejadian yang melahirkan berkas ini
 *
 * Temuan review CodeRabbit di PR yang menambahkan `bangun_ulang_saat_boot` ke
 * `GET /api/health`. Versi pertamanya memakai `FILTER_VALIDATE_BOOL`, meniru
 * `seed_saat_boot` yang sudah ada — dan menyalin bug-nya sekalian.
 *
 * Gerbang di entrypoint bentuknya shell, dan cuma mengenal satu nilai:
 *
 * ```sh
 * if [ "${BANGUN_ULANG_ON_BOOT}" = "true" ]; then
 * ```
 *
 * `FILTER_VALIDATE_BOOL` menerima `1`, `yes`, `on`; `env()` Laravel meng-cast
 * `TRUE` jadi boolean lebih dulu. Empat nilai yang bikin health melapor `true`
 * sementara entrypoint tidak menjalankan apa pun.
 *
 * Yang dipertaruhkan bukan kerapian: endpoint itu ADA supaya tidak ada yang
 * perlu menebak keadaan container. Laporan `true` yang salah mengirim orangnya
 * mencari ke tempat yang salah — lebih buruk daripada tidak melaporkan sama
 * sekali.
 *
 * ## Kenapa `TestCase` polos, bukan `Tests\TestCase`
 *
 * Yang diuji pembacaan environment mentah, sebelum Laravel menyentuhnya. Boot
 * aplikasi penuh justru memuat `.env` dan mengaburkan yang mau dibuktikan.
 */
class SaklarBootTest extends TestCase
{
    private const KUNCI = 'SAKLAR_BOOT_UJI';

    protected function tearDown(): void
    {
        unset($_ENV[self::KUNCI], $_SERVER[self::KUNCI]);
        putenv(self::KUNCI);

        parent::tearDown();
    }

    /** Cuma `true` huruf kecil yang menyalakan — sama seperti shell. */
    public function test_hanya_string_true_huruf_kecil_yang_nyala(): void
    {
        $_ENV[self::KUNCI] = 'true';

        $this->assertTrue(SaklarBoot::nyala(self::KUNCI));
    }

    /**
     * Empat nilai yang DULU bohong. Masing-masing diadu terpisah supaya kalau
     * ada yang lolos, pesannya menyebut nilainya.
     *
     * `TRUE` yang paling halus: `FILTER_VALIDATE_BOOL` saja tidak menerimanya,
     * tapi `env()` Laravel meng-cast-nya jadi boolean `true` lebih dulu — jadi
     * perbaikan yang cuma mengganti filter tanpa membaca nilai mentah tetap
     * bohong di nilai ini.
     */
    public function test_nilai_yang_mirip_benar_tida_k_nyala(): void
    {
        foreach (['1', 'yes', 'on', 'TRUE', 'True', 'YES', ' true'] as $nilai) {
            $_ENV[self::KUNCI] = $nilai;

            $this->assertFalse(
                SaklarBoot::nyala(self::KUNCI),
                "`{$nilai}` dilaporkan nyala, padahal `entrypoint.sh` cuma "
                .'menjalankan kerjanya kalau nilainya persis `true`. Health yang '
                .'melapor nyala tanpa ada yang jalan itu peringatan palsu.',
            );
        }
    }

    /** Yang jelas mati tetap mati. */
    public function test_nilai_mati_dan_kosong(): void
    {
        foreach (['false', 'FALSE', '0', 'no', 'off', ''] as $nilai) {
            $_ENV[self::KUNCI] = $nilai;

            $this->assertFalse(SaklarBoot::nyala(self::KUNCI), "`{$nilai}` mestinya mati.");
        }
    }

    /** Tidak disetel sama sekali = mati, bukan error. */
    public function test_belum_disetel_dianggap_mati(): void
    {
        $this->assertFalse(SaklarBoot::nyala('SAKLAR_YANG_TIDAK_PERNAH_ADA'));
    }

    /**
     * Variabel proses betulan ikut kebaca — itu bentuk yang dipakai Render.
     *
     * `$_ENV` sengaja dikosongkan di sini: kalau `mentah()` cuma melihat
     * `$_ENV`, test ini yang merah, bukan produksi yang diam-diam salah.
     */
    public function test_variabel_proses_betulan_ikut_kebaca(): void
    {
        unset($_ENV[self::KUNCI], $_SERVER[self::KUNCI]);
        putenv(self::KUNCI.'=true');

        $this->assertTrue(SaklarBoot::nyala(self::KUNCI));
    }
}
