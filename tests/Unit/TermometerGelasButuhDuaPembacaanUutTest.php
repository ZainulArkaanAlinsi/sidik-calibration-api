<?php

namespace Tests\Unit;

use App\Services\Calibration\ThermocoupleCalculator;
use App\Services\Calibration\ThermohygroCalculator;
use App\Services\Calibration\ThermometerGlassCalculator;
use Tests\TestCase;

/**
 * Termometer Gelas menuntut dua pembacaan UUT, sama dengan tiga saudaranya.
 *
 * ## Kenapa berkas ini ada
 *
 * ```
 * ThermometerGlassCalculator.php:201   count($standar) < 2 || count($uut) < 1   ← ganjil
 * ThermocoupleCalculator.php:227       count($standar) < 2 || count($uut) < 2
 * ThermohygroCalculator.php:221        count($standar) < 2 || count($uut) < 2
 * ```
 *
 * Yang dihasilkan asimetri itu bukan penolakan, tapi **angka**. `stdev()`
 * memulangkan `0.0` untuk n < 2, dan nilai itu masuk komponen budget
 * `pengulangan_uut` yang `'disertakan' => true` tanpa syarat. Jadi satu
 * pembacaan UUT tidak menghasilkan "tidak ada sebaran" — dia menghasilkan
 * "sebarannya nol", dan U95% di sertifikat jadi lebih kecil dari yang bisa
 * dipertanggungjawabkan.
 *
 * Kelas kesalahan ini sudah dijelaskan panjang di `GumCalculator`: *"Satu
 * pembacaan nggak punya sebaran… sertifikatnya bakal ngeklaim ketidakpastian
 * lebih bagus dari yang bisa dibuktiin. Salah yang diam-diam."* Penjaganya ada
 * di sana; yang tidak ada jalannya ke sisi UUT kalkulator ini.
 *
 * Jalurnya terjangkau: `CalibrationRequest` menyaratkan
 * `['sometimes','nullable','array','max:20']` untuk `measurements.*.uut` —
 * **tanpa `min`**, jadi array berisi satu elemen lolos validasi.
 *
 * ## Yang TIDAK berubah, dan itu penting
 *
 * Penjagaan ini **tidak memblokir teknisi di lapangan**. Dia memulangkan
 * `['alasan' => …]`: titiknya ditahan dari perhitungan dengan keterangan yang
 * kebaca, sesinya tetap tersimpan, dan penerbitan sertifikatnya yang ketahan
 * `CalibrationValidator`. Pola yang sama dengan tiga penjagaan lain di method
 * itu — dan bukan pola BUG-019 yang menahan orang di lapangan.
 */
class TermometerGelasButuhDuaPembacaanUutTest extends TestCase
{
    private const SPEK = [
        'merk_kalibrator' => 'yokogawa', 'tipe_sensor' => 'RTD', 'no_probe' => 17,
        'oilbath' => 'dua', 'resolusi' => 1.0, 'resolusi_standar' => 0.1, 'cmc' => 0.58,
        'titik_es' => [0.0, 0.0, 0.0],
    ];

    /**
     * @param  list<float>  $uut
     * @return array<string, mixed>
     */
    private function hitung(array $uut): array
    {
        return (new ThermometerGlassCalculator)->hitungSesi(
            [[
                'titik_ke' => 1,
                'titik_ukur' => 30.0,
                'standar' => [29.9, 30.3, 30.3, 30.3, 30.3],
                'uut' => $uut,
            ]],
            self::SPEK,
        );
    }

    /**
     * INTI bug-nya. Sebelum diperbaiki, titik ini DIHITUNG — lengkap dengan
     * komponen pengulangan UUT bernilai nol.
     */
    public function test_satu_pembacaan_uut_ditahan_bukan_dihitung(): void
    {
        $hasil = $this->hitung([30.0]);

        $this->assertSame(
            [],
            $hasil['titik'],
            'Titik dengan satu pembacaan UUT tetap kehitung — U95-nya ngeklaim sebaran nol.'
        );
        $this->assertNotEmpty($hasil['belum_dihitung']);
    }

    /** Alasannya harus kebaca, bukan cuma "ditahan". */
    public function test_alasannya_nyebut_kedua_sisi(): void
    {
        $alasan = $this->hitung([30.0])['belum_dihitung'][0]['alasan'] ?? '';

        $this->assertStringContainsString('tiap sisi butuh minimal 2', $alasan);
    }

    /**
     * JANGAN kebablasan: dua pembacaan UUT tetap dihitung seperti biasa.
     *
     * Kalau test ini merah, penjagaannya kelewat ketat dan alat yang selama ini
     * benar ikut ditahan.
     */
    public function test_dua_pembacaan_uut_tetap_dihitung(): void
    {
        $hasil = $this->hitung([30.0, 30.0]);

        $this->assertCount(1, $hasil['titik']);
        $this->assertSame([], $hasil['belum_dihitung']);
    }

    /** Lima pembacaan — bentuk normal di master — tentu tetap jalan. */
    public function test_lima_pembacaan_uut_tetap_dihitung(): void
    {
        $hasil = $this->hitung([30, 30, 30, 30, 30]);

        $this->assertCount(1, $hasil['titik']);
        $this->assertSame([], $hasil['belum_dihitung']);
    }

    /**
     * Penjagaan sisi STANDAR tidak ikut berubah — dia sudah `< 2` sejak awal.
     */
    public function test_satu_pembacaan_standar_tetap_ditahan(): void
    {
        $hasil = (new ThermometerGlassCalculator)->hitungSesi(
            [[
                'titik_ke' => 1,
                'titik_ukur' => 30.0,
                'standar' => [29.9],
                'uut' => [30, 30, 30, 30, 30],
            ]],
            self::SPEK,
        );

        $this->assertSame([], $hasil['titik']);
    }

    /**
     * Yang bikin temuan ini ketemu: keempat kalkulator suhu harus bilang hal
     * yang sama.
     *
     * Penjagaan ini menahan kalkulator BERIKUTNYA lahir dengan ambang yang beda
     * lagi — dan ambang yang beda tidak menerbitkan error apa pun, cuma angka
     * ketidakpastian yang lebih kecil di satu jenis alat saja.
     */
    public function test_keempat_kalkulator_suhu_pakai_ambang_yang_sama(): void
    {
        $sumber = [
            ThermometerGlassCalculator::class,
            ThermocoupleCalculator::class,
            ThermohygroCalculator::class,
        ];

        foreach ($sumber as $kelas) {
            $isi = file_get_contents((new \ReflectionClass($kelas))->getFileName());

            $this->assertStringContainsString(
                'count($standar) < 2 || count($uut) < 2',
                $isi,
                basename(str_replace('\\', '/', $kelas)).' pakai ambang pembacaan yang beda.'
            );
        }
    }
}
