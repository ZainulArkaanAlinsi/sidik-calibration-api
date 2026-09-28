<?php

namespace Tests\Unit;

use App\Services\Calibration\TabelStandarTekanan;
use App\Services\Calibration\TekananCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rekonsiliasi keluarga TEKANAN ke keempat workbook master.
 *
 * Angka harapannya TIDAK diketik: `database/data/sesi-master-tekanan.json`
 * digenerate `docs/skrip/gen-tabel-standar-tekanan.py`, yang membaca cache
 * Excel keempat master dan menolak menulis kalau satu saja dari 962 selnya
 * tidak tereproduksi.
 *
 * Toleransi `1e-12 × max(1, |harapan|)`. Relatif untuk angka besar karena
 * satu-satunya sel yang tidak lolos mutlak 1e-12 (`L43` SPMK ≈ 144) lahir dari
 * `Q38` yang cocok 1e-15 lalu dikali ρ·g = 8805 — mutlak 1e-12 di angka
 * ratusan lebih ketat dari presisi double itu sendiri. Jangan dilonggarkan
 * lebih dari itu untuk membuat test hijau.
 */
class TekananMasterTest extends TestCase
{
    private const TOL = 1e-12;

    /** @return array<string, array{0: string}> */
    public static function varian(): array
    {
        return [
            'DRUCK07G' => [TabelStandarTekanan::DRUCK07G],
            'DRUCK13G' => [TabelStandarTekanan::DRUCK13G],
            'SPMK' => [TabelStandarTekanan::SPMK],
            'Differential' => [TabelStandarTekanan::DIFFERENTIAL],
        ];
    }

    /** @return array<string, mixed> */
    private static function sesi(string $varian): array
    {
        $isi = json_decode((string) file_get_contents(database_path('data/sesi-master-tekanan.json')), true);

        return $isi['sesi'][$varian];
    }

    private function sama(float|int|null $harap, float|int|null $dapat, string $label): void
    {
        if ($harap === null || $dapat === null) {
            $this->assertSame($harap, $dapat, $label);

            return;
        }

        $batas = self::TOL * max(1.0, abs((float) $harap));
        $this->assertLessThanOrEqual(
            $batas,
            abs((float) $harap - (float) $dapat),
            sprintf('%s: harapan %.17g, dapat %.17g', $label, $harap, $dapat),
        );
    }

    /**
     * Mode MASTER mereproduksi cache Excel sampai ujung — termasuk ketiga cacat
     * yang ditiru HANYA di mode ini. Kalau ini merah, rantainya salah baca;
     * kalau ini hijau sementara mode benar merah, yang salah pembetulannya.
     */
    #[DataProvider('varian')]
    public function test_mode_master_mereproduksi_cache_excel_seluruh_rantai(string $varian): void
    {
        $s = self::sesi($varian);
        $this->adu($varian, $s['masukan'], $s['harapan_master_excel'], TekananCalculator::MODE_MASTER);
    }

    /**
     * Mode BENAR (yang dipakai sesi) = keluaran skrip Python dengan T-1, T-2,
     * dan T-11 dihitung benar. Semua nilai yang TIDAK tersentuh pembetulan
     * tetap sama dengan Excel; `test_pembetulan_cuma_menyentuh_yang_dibetulkan`
     * menegakkan itu dari arah sebaliknya.
     */
    #[DataProvider('varian')]
    public function test_mode_benar_sama_dengan_hitungan_independen(string $varian): void
    {
        $s = self::sesi($varian);
        $this->adu($varian, $s['masukan'], $s['harapan_benar'], TekananCalculator::MODE_BENAR);
    }

    /** @param  array<string, mixed>  $m  @param  array<string, mixed>  $h */
    private function adu(string $varian, array $m, array $h, string $mode): void
    {
        $r = (new TekananCalculator)->hitungSesi($varian, $m, $mode);

        $this->sama($h['faktor'], $r['faktor'], 'faktor konversi');
        $this->assertCount(count($h['per_titik']), $r['per_titik']);

        foreach ($h['per_titik'] as $i => $x) {
            $p = $r['per_titik'][$i];
            $t = 't'.($i + 1).' ';
            $this->sama($x['E'], $p['E'], $t.'E');

            foreach ([0, 1, 2] as $j) {
                $this->sama($x['G'][$j], $p['up_kerja'][$j], $t."G{$j}");
                $this->sama($x['J'][$j], $p['down_kerja'][$j], $t."J{$j}");
                $this->sama($x['hys'][$j], $p['histeresis'][$j], $t."hys{$j}");
            }

            $this->sama($x['M'], $p['rata_up'], $t.'AVG up');
            $this->sama($x['N'], $p['rata_down'], $t.'AVG down');
            $this->sama($x['dev_up'], $p['deviasi_up'], $t.'deviasi up');
            $this->sama($x['dev_down'], $p['deviasi_down'], $t.'deviasi down');
            $this->sama($x['sd_up'], $p['stdev_up'], $t.'STDEV up');
            $this->sama($x['sd_down'], $p['stdev_down'], $t.'STDEV down');
            $this->sama($x['hys_avg'], $p['histeresis_rata'], $t.'AVG hys');
            $this->sama($x['indeks'], $p['indeks'], $t.'indeks');
            $this->sama($x['koreksi_up'], $p['koreksi_up'], $t.'koreksi std up');
            $this->sama($x['koreksi_down'], $p['koreksi_down'], $t.'koreksi std down');
            $this->sama($x['koreksi_tinggi'], $p['koreksi_tinggi'], $t.'koreksi tinggi');
            $this->sama($x['terkoreksi_up'], $p['terkoreksi_up'], $t.'std terkoreksi up');
            $this->sama($x['terkoreksi_down'], $p['terkoreksi_down'], $t.'std terkoreksi down');
            $this->sama($x['u95_titik'], $p['u95_titik'], $t.'U95 kalibrator titik');
            $this->sama($x['terkoreksi_up'] / $h['faktor'], $p['standar_up_tampil'], $t.'sertifikat std up');
            $this->sama($x['terkoreksi_down'] / $h['faktor'], $p['standar_down_tampil'], $t.'sertifikat std down');
        }

        foreach (['maks_koreksi', 'maks_stdev_up', 'maks_stdev_down', 'maks_stdev', 'zero_deviasi', 'maks_indeks',
            'u95_kalibrator', 'resolusi_kerja', 'kapasitas_kerja', 'R43', 'koreksi_tinggi', 'L43', 'L44', 'L45'] as $kunci) {
            $this->sama($h['agregat'][$kunci], $r['agregat'][$kunci], "agregat {$kunci}");
        }

        $this->assertCount(count($h['komponen']), $r['komponen'], 'jumlah komponen budget');

        foreach ($h['komponen'] as $i => $k) {
            $d = $r['komponen'][$i];
            $this->assertSame($k['kode'], $d['sumber'], "urutan komponen ke-{$i}");
            $this->sama($k['U'], $d['U'], "{$k['kode']} U");
            $this->sama($k['pembagi'], $d['pembagi'], "{$k['kode']} pembagi");
            $this->sama($k['vi'], $d['vi'], "{$k['kode']} vi");
            $this->sama($k['ci'], $d['ci'], "{$k['kode']} ci");
            $this->sama($k['u'], $d['u'], "{$k['kode']} u");
            $this->sama($k['uici'], $d['uici'], "{$k['kode']} u·ci");
        }

        $this->sama($h['uc'], $r['uc'], 'uc');
        $this->sama($h['veff'], $r['veff'], 'v_eff');
        $this->assertSame($h['df'], $r['df'], 'df bulat yang masuk inverse-t');
        $this->sama($h['k'], $r['k'], 'k');
        $this->sama($h['U'], $r['U'], 'U = k·uc');
        $this->sama($h['u95'], max($r['U'], $h['cmc']), 'U95 = MAX(U, CMC)');
    }

    /**
     * Pembetulan cuma boleh menggeser yang memang dibetulkan. Kalau mode benar
     * mengubah, misalnya, koreksi standar DOWN atau zero error, ada cabang yang
     * ikut kena tanpa sengaja.
     */
    #[DataProvider('varian')]
    public function test_pembetulan_cuma_menyentuh_yang_dibetulkan(string $varian): void
    {
        $s = self::sesi($varian);
        $calc = new TekananCalculator;
        $master = $calc->hitungSesi($varian, $s['masukan'], TekananCalculator::MODE_MASTER);
        $benar = $calc->hitungSesi($varian, $s['masukan'], TekananCalculator::MODE_BENAR);

        foreach ($master['per_titik'] as $i => $p) {
            $q = $benar['per_titik'][$i];

            foreach (['E', 'rata_up', 'rata_down', 'stdev_up', 'stdev_down', 'indeks', 'koreksi_down', 'terkoreksi_down'] as $kunci) {
                $this->assertSame($p[$kunci], $q[$kunci], "{$varian} t".($i + 1)." {$kunci} tidak boleh tersentuh");
            }
        }

        foreach ([1, 2, 3] as $i) {   // daya baca UUT, daya baca standar, drift
            $this->assertSame($master['komponen'][$i]['U'], $benar['komponen'][$i]['U']);
        }
    }

    /** Angka yang tercetak di sertifikat DRUCK07G titik 100 cmHg — Lampiran A.1. */
    public function test_sertifikat_druck07g_titik_100_cmhg(): void
    {
        $r = (new TekananCalculator)->hitungSesi(
            TabelStandarTekanan::DRUCK07G,
            self::sesi(TabelStandarTekanan::DRUCK07G)['masukan'],
        );
        $p = $r['per_titik'][1];

        $this->sama(99.57248916157873, $p['standar_up_tampil'], 'Standard Indication Up');
        $this->sama(99.40582249491209, $p['standar_down_tampil'], 'Standard Indication Down');
        $this->sama(-0.42751083842127, $p['koreksi_up_tampil'], 'Correction Up');
        $this->sama(-0.594177505087913, $p['koreksi_down_tampil'], 'Correction Down');
        $this->assertSame(140.0, $p['indeks']);
    }

    /**
     * T-1 & T-2 dibetulkan → U DRUCK07G contoh naik ~11×. Angka ini yang
     * disebut prompt panduan (0,0692 → 0,7533 kPa); kalau berubah, pembetulan
     * atau budget-nya bergeser.
     */
    public function test_pembetulan_t1_t2_druck07g_menaikkan_u_sebelas_kali(): void
    {
        $m = self::sesi(TabelStandarTekanan::DRUCK07G)['masukan'];
        $calc = new TekananCalculator;
        $master = $calc->hitungSesi(TabelStandarTekanan::DRUCK07G, $m, TekananCalculator::MODE_MASTER);
        $benar = $calc->hitungSesi(TabelStandarTekanan::DRUCK07G, $m, TekananCalculator::MODE_BENAR);

        $this->sama(0.0692283052976264, $master['U'], 'U master');
        $this->sama(0.06, $master['agregat']['u95_kalibrator'], 'T-1 master: selalu set point 0');
        $this->sama(0.09, $benar['agregat']['u95_kalibrator'], 'T-1 benar: MAX di indeks 140/200/0/-80');
        $this->assertSame(100, $master['df']);
        $this->assertSame(2, $benar['df']);
        $this->assertGreaterThan($master['U'] * 10, $benar['U']);
    }

    /** T-11: koreksi UP DRUCK13G Vacum di mode master = kolom U95. */
    public function test_t11_druck13g_vakum_membaca_kolom_u95_hanya_di_mode_master(): void
    {
        $m = self::sesi(TabelStandarTekanan::DRUCK13G)['masukan'];
        $this->assertSame('vakum', $m['jenis_tekanan']);
        $calc = new TekananCalculator;
        $master = $calc->hitungSesi(TabelStandarTekanan::DRUCK13G, $m, TekananCalculator::MODE_MASTER);
        $benar = $calc->hitungSesi(TabelStandarTekanan::DRUCK13G, $m, TekananCalculator::MODE_BENAR);

        $this->sama(0.077, $master['per_titik'][0]['koreksi_up'], 'master: U95 set point 0');
        $this->sama(-0.0613, $benar['per_titik'][0]['koreksi_up'], 'benar: koreksi set point 0');
    }

    /**
     * `MATCH(MIN(ABS(…)),…,0)` mengambil yang PERTAMA kalau jaraknya kembar.
     * 130 kPa berjarak sama dari 120 (koreksi −0,09) dan 140 (−0,17); yang
     * benar 120. Data contoh master tidak punya kasus ini, jadi tanpa test ini
     * pembanding `<` yang diganti `<=` lolos hijau (uji mutasi A.5).
     */
    public function test_indeks_berjarak_sama_ambil_yang_pertama(): void
    {
        $this->assertSame(120.0, TabelStandarTekanan::indeksTerdekat(TabelStandarTekanan::DRUCK07G, 130.0));
        $this->sama(-0.09, TabelStandarTekanan::baris(TabelStandarTekanan::DRUCK07G, 120.0)['koreksi_up'], 'koreksi 120');
    }

    /** Excel TINV memotong v_eff ke bawah — k pecahan WAJIB beda dari k potong. */
    public function test_k_memakai_df_dipotong_bukan_pecahan(): void
    {
        $r = (new TekananCalculator)->hitungSesi(
            TabelStandarTekanan::DRUCK07G,
            self::sesi(TabelStandarTekanan::DRUCK07G)['masukan'],
            TekananCalculator::MODE_MASTER,
        );

        $this->assertSame(100, $r['df']);
        $this->sama(1.9839715185235556, $r['k'], 'k = TINV(0,05; 100)');
        $this->assertGreaterThan(1e-5, abs($r['k'] - 1.9838535342799437), 'k df pecahan 100,488 harus beda');
    }
}
