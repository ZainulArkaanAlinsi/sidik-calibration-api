<?php

namespace Tests\Unit;

use App\Services\Calibration\Profiles\TimbanganProfile;
use App\Services\Calibration\VarianMasterTimbangan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * K-50-15 / B3a (R9): format cetak Timbangan = format sel lembar `SERTIFIKAT`
 * ketiga master, bukan aturan `d` / `max(d,2)` / `d+1` (T14, 1 Sep 2026,
 * dibatalkan K-50-15 "sertifikat persis master").
 *
 * Tiap kasus di bawah satu sel master (`sertifikat.json` ekstraksi:
 * `number_format` sel `SERTIFIKAT`). `d` = desimal resolusi neraca sampel
 * master = format sel resolusi `M12` (gram `0.0000` → 4, kg `0.00` → 2,
 * substitusi `0.0` → 1). Hanya CETAK: nilai tak berubah.
 */
class TimbanganFormatCetakMasterTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: array<string, string>}>
     */
    public static function masterSampel(): array
    {
        return [
            // New_Master_Olda_Timbangan_gram: I19 · L19 · W19 · L23 · C28 · L28 · U28 · F40 · G42 · L45 · R45 · M46 · C49 · H49
            'gram (M12 0.0000)' => [VarianMasterTimbangan::GRAM, 4, [
                'kapasitas' => '0.000', 'stdev' => '0.0000', 'maks_beda' => '0.0000', 'tare' => '0.0000',
                'nominal' => '0.0000', 'koreksi' => '0.0000', 'u95' => '0.00000', 'beban' => '0.000',
                'selisih' => '0.0000', 'hys_beban' => '0.000', 'hys_batas' => '0.0000', 'lop' => '0.00000',
                'nominal_u' => '0.000', 'u95_penimbangan' => '0.00000',
            ]],
            // New_Master_Olda_Timbangan_kg: I19 General · L19 · W19 · L23 · C28 · L28 · U28 · F40 General · G42 · N45 · U45 · O46 · C49 · H49
            'kg (M12 0.00)' => [VarianMasterTimbangan::KG, 2, [
                'kapasitas' => 'General', 'stdev' => '0.00', 'maks_beda' => '0.00', 'tare' => '0.00',
                'nominal' => '0.00', 'koreksi' => '0.00', 'u95' => '0.000', 'beban' => 'General',
                'selisih' => '0.00', 'hys_beban' => '0.00', 'hys_batas' => '0.00', 'lop' => '0.00',
                'nominal_u' => '0.00', 'u95_penimbangan' => '0.000',
            ]],
            // TERBARU_Master_Olda_Timbangan_Subtitusi_291025: I19 General · L19 0.00 · W19 0.0 · L23 · C28 · L28 · U28 0.0 · F40 General · G42 · N45 · U45 · O46 0 · C49 0.00 · H49 0.0
            'substitusi (M12 0.0)' => [VarianMasterTimbangan::SUBSTITUSI, 1, [
                'kapasitas' => 'General', 'stdev' => '0.00', 'maks_beda' => '0.0', 'tare' => '0.0',
                'nominal' => '0.0', 'koreksi' => '0.0', 'u95' => '0.0', 'beban' => 'General',
                'selisih' => '0.0', 'hys_beban' => '0.0', 'hys_batas' => '0.0', 'lop' => '0',
                'nominal_u' => '0.00', 'u95_penimbangan' => '0.0',
            ]],
        ];
    }

    /**
     * @param  array<string, string>  $harap
     */
    #[DataProvider('masterSampel')]
    public function test_format_sama_dengan_sel_master_di_neraca_sampel(string $varian, int $d, array $harap): void
    {
        $this->assertSame($harap, TimbanganProfile::formatCetakMaster($varian, $d));
    }

    public function test_varian_tak_dikenal_tanpa_format_jatuh_ke_aturan_lama(): void
    {
        $this->assertSame([], TimbanganProfile::formatCetakMaster('lain', 2));
    }

    public function test_desimal_nol_tidak_menghasilkan_kode_negatif(): void
    {
        // d = 0 (neraca 1 kg): `d-1` dijepit ke `0`, bukan `0.` atau kode rusak.
        $f = TimbanganProfile::formatCetakMaster(VarianMasterTimbangan::SUBSTITUSI, 0);

        $this->assertSame('0', $f['lop']);
        $this->assertSame('0', $f['u95']);
        $this->assertSame('0.0', $f['nominal_u']);
    }

    /**
     * Teks angka mengikuti kode: tetap = `Angka::id` dengan desimalnya,
     * `General` = presisi penuh tanpa nol di belakang, tanpa kode = desimal
     * bawaan (snapshot terbit sebelum B3a).
     */
    public function test_teks_angka_cetak(): void
    {
        // Master kg `O46` 0.00 (LOP 0,0795 kg): `0,08`, BUKAN `0,080` (d+1 lama).
        $this->assertSame('0,08', TimbanganProfile::teksAngkaCetak(0.07953615325627225, 3, '0.00'));
        // Master substitusi `O46` 0 (LOP 13,66 kg): `14`.
        $this->assertSame('14', TimbanganProfile::teksAngkaCetak(13.660610503200731, 2, '0'));
        // Master gram `I19` 0.000: `25,000`; kg `I19` General: `50`.
        $this->assertSame('25,000', TimbanganProfile::teksAngkaCetak(25.0, 4, '0.000'));
        $this->assertSame('50', TimbanganProfile::teksAngkaCetak(50.0, 2, 'General'));
        $this->assertSame('1000', TimbanganProfile::teksAngkaCetak(1000.0, 1, 'General'));
        // Snapshot lama (tanpa `format`): perilaku lama persis.
        $this->assertSame('0,080', TimbanganProfile::teksAngkaCetak(0.07953615325627225, 3, null));
    }

    /**
     * Peninjau B3a: format gram `d-1` TIDAK boleh memotong nilai uji yang
     * diketik. Neraca 0,1 g (d = 1) dengan kapasitas 105,5 g: `d-1` = 0
     * desimal akan mencetak `106`. Di neraca sampel master (d = 4, kapasitas
     * bulat) hasilnya tetap `0.000` persis master.
     */
    public function test_gram_tidak_memotong_nilai_uji(): void
    {
        $gram = static fn (int $d, array $uji): array => TimbanganProfile::formatCetakMaster(VarianMasterTimbangan::GRAM, $d, $uji);

        // d = 1: kapasitas 105,5 -> 1 desimal; Half Capacity 105,5 tidak jadi `106`.
        $f = $gram(1, ['kapasitas' => [105.5, 211.0], 'beban' => [20.0], 'hys_beban' => [50.5]]);
        $this->assertSame('0.0', $f['kapasitas']);
        $this->assertSame('105,5', TimbanganProfile::teksAngkaCetak(105.5, 1, $f['kapasitas']));
        $this->assertSame('0', $f['beban'], 'Beban bulat di neraca 0,1 g: tanpa desimal berlebih.');
        $this->assertSame('0.0', $f['hys_beban']);
        $this->assertSame('0.0', $f['nominal_u'], 'nominal §7 d <= 1: sama dengan §3 (d), bukan bilangan bulat.');

        // d = 2: 105,55 butuh 2 desimal (d-1 = 1 akan jadi `105.6`).
        $this->assertSame('0.00', $gram(2, ['kapasitas' => [105.55]])['kapasitas']);

        // Neraca sampel master (d = 4): kapasitas bulat tetap `0.000`.
        $f = $gram(4, ['kapasitas' => [25.0, 50.0], 'beban' => [10.0], 'hys_beban' => [20.0]]);
        $this->assertSame(['0.000', '0.000', '0.000', '0.000'], [$f['kapasitas'], $f['beban'], $f['hys_beban'], $f['nominal_u']]);
        $this->assertSame('25,000', TimbanganProfile::teksAngkaCetak(25.0, 4, $f['kapasitas']));
    }

    /**
     * Peninjau B3a: LOP/U95 tidak boleh tercetak `0`. Format master tetap
     * dipakai selama tidak membulat ke nol — termasuk `0.5` (U95 0,52) dan
     * LOP `14` (13,66) yang dicetak master sendiri.
     */
    public function test_penjaga_ketidakpastian_tidak_pernah_mencetak_nol(): void
    {
        $sub = TimbanganProfile::formatCetakMaster(VarianMasterTimbangan::SUBSTITUSI, 1);
        $cetak = static fn (array $f, string $kunci, float $v): string => TimbanganProfile::teksAngkaCetak($v, 2, $f[$kunci]);

        // LOP 0,4 kg di substitusi: format `0` mencetak `0` -> `0.0` -> `0,4`.
        $f = TimbanganProfile::jagaFormatKetidakpastian($sub, 0.4, [0.52], [0.52]);
        $this->assertSame('0.0', $f['lop']);
        $this->assertSame('0,4', $cetak($f, 'lop', 0.4));
        // LOP 3,4 (bilangan bulat satu digit `3`) -> satu desimal.
        $f = TimbanganProfile::jagaFormatKetidakpastian($sub, 3.4, [0.52], [0.52]);
        $this->assertSame('3,4', $cetak($f, 'lop', 3.4));
        // LOP 13,66 TETAP `14` (master substitusi O46), U95 0,52 TETAP `0,5` (U28:U37).
        $f = TimbanganProfile::jagaFormatKetidakpastian($sub, 13.660610503200731, [0.52, 0.52], [0.52]);
        $this->assertSame($sub, $f);
        $this->assertSame('14', $cetak($f, 'lop', 13.660610503200731));
        $this->assertSame('0,5', $cetak($f, 'u95', 0.52));
        // LOP 9,6 membulat ke `10` (dua digit): tidak dilebarkan.
        $this->assertSame('10', $cetak(TimbanganProfile::jagaFormatKetidakpastian($sub, 9.6, [], []), 'lop', 9.6));

        // U95 0,04 (dan 0,5 di titik lain) -> kolom `0.0` mencetak 0,0 untuk 0,04 -> `0.00`.
        $f = TimbanganProfile::jagaFormatKetidakpastian($sub, 13.66, [0.04, 0.5], [0.04]);
        $this->assertSame('0.00', $f['u95']);
        $this->assertSame('0.00', $f['u95_penimbangan']);
        $this->assertSame('0,04', $cetak($f, 'u95', 0.04));

        // kg: LOP 0,0795 TETAP `0,08` (master O46 `0.00`); LOP 0,004 -> `0,004`.
        $kg = TimbanganProfile::formatCetakMaster(VarianMasterTimbangan::KG, 2);
        $this->assertSame($kg, TimbanganProfile::jagaFormatKetidakpastian($kg, 0.07953615325627225, [0.033], [0.033]));
        $this->assertSame('0.000', TimbanganProfile::jagaFormatKetidakpastian($kg, 0.004, [0.033], [0.033])['lop']);

        // gram: U95 0,00057 (lima desimal) tidak berubah; LOP/nilai nol & null diabaikan.
        $gram = TimbanganProfile::formatCetakMaster(VarianMasterTimbangan::GRAM, 4);
        $this->assertSame($gram, TimbanganProfile::jagaFormatKetidakpastian($gram, 0.00077, [0.00057], [0.0006]));
        $this->assertSame($gram, TimbanganProfile::jagaFormatKetidakpastian($gram, null, [0.0], []));
    }
}
