<?php

namespace Tests\Feature\Paritas;

use App\Services\Acuan\FormatSertifikatAcuan;
use App\Services\Calibration\Profiles\VacuumGaugeProfile;
use App\Services\Calibration\TabelStandarTekanan as Tabel;
use App\Services\Calibration\TekananCalculator;
use App\Support\TataLetakSertifikat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\MenegakkanParitas;
use Tests\TestCase;

/**
 * PARITAS keluarga Tekanan (Pressure Gauge, Vacuum Gauge, Differential Pressure) pada LEMBAR SERTIFIKAT keempat master
 * (DRUCK07G, DRUCK13G, SPMK, Differential): isi tabel hasil `E22:V..`, U95 `P35`, dan k `U36`, nilai maupun teks tercetak.
 *
 * Yang SUDAH dibuktikan di tempat lain dan tidak diulang di sini: rantai hitungan lembar `PERHITUNGAN FC` dan
 * `PERHITUNGAN U95%` (962 sel, 1e-12) di `tests/Unit/TekananMasterTest.php`, serta CMC lampiran lawan sel master di
 * `tests/Feature/TekananCmcTest.php`. Celah yang ditutup di sini: nilai TAMPIL (satuan pilihan teknisi, termasuk tiga kolom
 * histeresis) dan teks yang dicetak, yang di tes lama hanya diperiksa saat fixture dibangun.
 *
 * Fixture `tests/Fixtures/paritas/tekanan.json` dibangun `docs/skrip/gen-fixture-paritas-batch4.py`. Masukan hitung
 * (setelan, pembacaan naik/turun, spesifikasi) diambil dari `database/data/sesi-master-tekanan.json`, yang dibangun dari
 * workbook yang sama; kesamaan berkasnya dibuktikan lewat sha256 di tes pertama.
 *
 * Dua mode hitung (`TekananCalculator`): MASTER meniru Excel apa adanya; BENAR membetulkan cacat salin-tempel T-1, T-2,
 * T-11 (pertanyaan lab P-1, P-2, P-11, menunggu Technical Manager). Sesi yang memicunya TIDAK terbit
 * (`TekananProfile::penahanTerbit`), jadi selisih BENAR-lawan-Excel di bawah adalah selisih yang ditahan, bukan angka
 * di sertifikat.
 */
#[Group('paritas')]
class ParitasTekananTest extends TestCase
{
    use MenegakkanParitas;

    /** Kolom fixture -> kunci `histeresis_tampil` / `*_tampil` kalkulator dan kolom format sel master. */
    private const KOLOM_FORMAT = [
        'standar_up' => 'standar',
        'standar_down' => 'standar',
        'koreksi_up' => 'koreksi',
        'koreksi_down' => 'koreksi',
        'histeresis_1' => 'sebaran',
        'histeresis_2' => 'sebaran',
        'histeresis_3' => 'sebaran',
    ];

    /** @return array<string, array{0: string}> */
    public static function varian(): array
    {
        return [
            'DRUCK07G' => [Tabel::DRUCK07G],
            'DRUCK13G' => [Tabel::DRUCK13G],
            'SPMK' => [Tabel::SPMK],
            'Differential' => [Tabel::DIFFERENTIAL],
        ];
    }

    /** @return array<string, mixed> */
    private static function lembar(string $varian): array
    {
        return self::fixtureParitas('tekanan')['workbook']['varian'][$varian];
    }

    /** @return array<string, mixed> */
    private static function berkasSesi(): array
    {
        return json_decode((string) file_get_contents(database_path('data/sesi-master-tekanan.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function masukan(string $varian): array
    {
        return self::berkasSesi()['sesi'][$varian]['masukan'];
    }

    /** @return array<string, mixed> */
    private static function hitung(string $varian, string $mode): array
    {
        return (new TekananCalculator)->hitungSesi($varian, self::masukan($varian), $mode);
    }

    /**
     * Nilai satu kolom tabel hasil seperti yang dicetak profil (`tabelSertifikatTekanan`): kolom `*_tampil` kalkulator.
     *
     * @param  array<string, mixed>  $titik  satu elemen `per_titik`
     */
    private static function nilaiTampil(array $titik, string $kolom): float
    {
        return match ($kolom) {
            'standar_up' => $titik['standar_up_tampil'],
            'standar_down' => $titik['standar_down_tampil'],
            'koreksi_up' => $titik['koreksi_up_tampil'],
            'koreksi_down' => $titik['koreksi_down_tampil'],
            'histeresis_1' => $titik['histeresis_tampil'][0],
            'histeresis_2' => $titik['histeresis_tampil'][1],
            'histeresis_3' => $titik['histeresis_tampil'][2],
        };
    }

    /** U95 tampil = MAX(U, CMC) dibagi faktor satuan (`SERTIFIKAT!P35`); CMC dari sel master, dibuktikan di TekananCmcTest. */
    private static function u95Tampil(string $varian, array $hasil): float
    {
        $cmc = self::berkasSesi()['sesi'][$varian]['harapan_master_excel']['cmc'];

        return max($hasil['U'], $cmc) / $hasil['faktor'];
    }

    // ------------------------------------------------------------------ pengikat fixture

    /**
     * Fixture dan tabel standar aplikasi berasal dari workbook yang BYTE-nya sama, dan lembar SERTIFIKAT yang dibaca
     * memuat titik yang sama dengan masukan hitung. Tanpa ini, tes lain mengadu angka yang bisa berasal dari dua
     * revisi workbook yang berbeda.
     */
    #[DataProvider('varian')]
    public function test_lembar_sertifikat_berasal_dari_workbook_yang_sama_dengan_masukan_hitung(string $varian): void
    {
        $berkas = self::berkasSesi();
        $lembar = self::lembar($varian);
        $m = self::masukan($varian);

        $this->assertSame($berkas['_sumber']['workbook_sha256'][$varian], $lembar['sha256'], "sha256 workbook {$varian}");
        $this->assertCount(count($m['titik']), $lembar['baris'], "jumlah baris tabel hasil {$varian}");
        $this->assertSame($m['satuan'], $lembar['satuan_kolom']['nilai'], 'satuan di judul kolom (E20)');
        $this->assertSame($m['satuan'], $lembar['satuan_u95']['nilai'], 'satuan di baris U95 (S35)');

        foreach ($lembar['baris'] as $i => $b) {
            $this->assertSamaExcel($b['setelan'], (float) $m['titik'][$i]['setelan'], "{$varian} baris {$b['baris']}: setelan (E)");
        }
    }

    // ------------------------------------------------------------------ mode MASTER = Excel

    /**
     * Tabel hasil: standar naik/turun (I, L), koreksi naik/turun (N, P), dan tiga kolom histeresis (R, T, V) di setiap
     * titik, nilai lawan sel; lalu TEKS: format sel master sama dengan format yang dipakai profil, dan angka yang tercetak
     * (pembulatan tampilan Excel) sama persis, termasuk tanda minus pada nilai yang membulat ke nol.
     */
    #[DataProvider('varian')]
    public function test_tabel_hasil_mode_master_sama_dengan_sertifikat_nilai_dan_teks(string $varian): void
    {
        $lembar = self::lembar($varian);
        $hasil = self::hitung($varian, TekananCalculator::MODE_MASTER);
        $format = FormatSertifikatAcuan::untuk('tekanan', $varian, 0.0);

        $this->assertCount(count($lembar['baris']), $hasil['per_titik']);

        foreach ($lembar['baris'] as $i => $b) {
            $titik = $hasil['per_titik'][$i];

            $this->assertSame($format['uut'], $b['setelan']['format'], "{$b['setelan']['sel']}: format kolom UUT");
            $this->assertSame($b['setelan']['teks'], self::keTitik(TataLetakSertifikat::formatAngka((float) $b['setelan']['nilai'], $format['uut'])), "{$b['setelan']['sel']}: teks setelan");

            foreach (self::KOLOM_FORMAT as $kolom => $jenis) {
                $sel = $b[$kolom];
                $app = self::nilaiTampil($titik, $kolom);

                $this->assertSamaExcel($sel, $app, "{$varian} {$sel['sel']} ({$kolom})");
                $this->assertSame($format[$jenis], $sel['format'], "{$sel['sel']}: format sel master = format profil ({$jenis})");
                $this->assertSame($sel['teks'], self::keTitik(TataLetakSertifikat::formatAngka($app, $format[$jenis])), "{$sel['sel']} ({$kolom}): teks tercetak");
            }
        }
    }

    /**
     * U95 (`P35`) = MAX(U, CMC) / faktor, tercetak dengan format `P35`; k (`U36`) = ROUND(k; 1), tercetak dengan format
     * `U36` (Differential `0`, tiga master lain General).
     */
    #[DataProvider('varian')]
    public function test_u95_dan_k_mode_master_sama_dengan_sertifikat_nilai_dan_teks(string $varian): void
    {
        $lembar = self::lembar($varian);
        $hasil = self::hitung($varian, TekananCalculator::MODE_MASTER);
        $format = FormatSertifikatAcuan::untuk('tekanan', $varian, 0.0);
        $u95 = self::u95Tampil($varian, $hasil);
        $k = round($hasil['k'], 1);

        $this->assertSamaExcel($lembar['u95'], $u95, "{$varian}: U95 tampil");
        $this->assertSame($format['u95'], $lembar['u95']['format'], 'format U95 (P35)');
        $this->assertSame($lembar['u95']['teks'], self::keTitik(TataLetakSertifikat::formatAngka($u95, $format['u95'])), "{$varian}: teks U95");

        $this->assertSamaExcel($lembar['k'], $k, "{$varian}: k = ROUND(k; 1)");
        $this->assertSame($format['k'], $lembar['k']['format'], 'format k (U36)');
        $this->assertSame($lembar['k']['teks'], self::keTitik(TataLetakSertifikat::formatAngka($k, $format['k'])), "{$varian}: teks k");
    }

    // ------------------------------------------------------------------ mode BENAR = selisih yang ditahan

    /**
     * SPMK tidak memicu cacat master mana pun: mode BENAR dan Excel sama di SEMUA yang tercetak, jadi sesinya terbit.
     * Tiga varian lain berselisih hanya pada yang dibetulkan T-1/T-2/T-11. Daftar kolom di bawah diukur sekali dari
     * keluaran kalkulator lawan sel SERTIFIKAT, lalu dijelaskan penyebabnya di log metode; tes ini mengunci supaya
     * selisih tidak bergeser atau bertambah diam-diam (selisihnya nyata, bukan kesalahan tes: tetap MENUNGGU TM).
     *
     *  - DRUCK07G (T-1, T-2): tabel hasil sama, tetapi U naik sekitar sebelas kali, jadi U95 `P35` dan k `U36`
     *    (`2` menjadi `4.3`) tercetak lain.
     *  - DRUCK13G vakum (T-2, T-11): U95 dan k tercetak sama, tetapi kolom naik (I dan N) tiap titik tercetak lain,
     *    karena Excel membaca kolom U95 sebagai koreksi (`AD24`, dicatat di `TekananCalculator`).
     *  - Differential (T-2): tabel dan k (`2`) sama, U95 `0.06` menjadi `0.07`.
     *
     * @return array<string, array{0: string, 1: list<string>}> varian dan kolom/besaran yang tercetak berbeda
     */
    public static function selisihTertahan(): array
    {
        return [
            'DRUCK07G: U95 dan k (T-1, T-2)' => [Tabel::DRUCK07G, ['u95', 'k']],
            'DRUCK13G vakum: kolom naik (T-11)' => [Tabel::DRUCK13G, ['standar_up', 'koreksi_up']],
            'SPMK: tidak ada' => [Tabel::SPMK, []],
            'Differential: U95 (T-2)' => [Tabel::DIFFERENTIAL, ['u95']],
        ];
    }

    /** @param  list<string>  $diharapkan */
    #[DataProvider('selisihTertahan')]
    public function test_mode_benar_hanya_menggeser_yang_dibetulkan_dan_yang_tercetak_berbeda_ditahan(string $varian, array $diharapkan): void
    {
        $lembar = self::lembar($varian);
        $hasil = self::hitung($varian, TekananCalculator::MODE_BENAR);
        $format = FormatSertifikatAcuan::untuk('tekanan', $varian, 0.0);
        $beda = [];

        foreach ($lembar['baris'] as $i => $b) {
            foreach (self::KOLOM_FORMAT as $kolom => $jenis) {
                $teks = self::keTitik(TataLetakSertifikat::formatAngka(self::nilaiTampil($hasil['per_titik'][$i], $kolom), $format[$jenis]));

                if ($teks !== $b[$kolom]['teks']) {
                    $beda[$kolom] = true;
                }
            }
        }

        if (self::keTitik(TataLetakSertifikat::formatAngka(self::u95Tampil($varian, $hasil), $format['u95'])) !== $lembar['u95']['teks']) {
            $beda['u95'] = true;
        }

        if (self::keTitik(TataLetakSertifikat::formatAngka(round($hasil['k'], 1), $format['k'])) !== $lembar['k']['teks']) {
            $beda['k'] = true;
        }

        $this->assertEqualsCanonicalizing($diharapkan, array_keys($beda), "{$varian}: yang tercetak berbeda dari Excel di mode BENAR");
    }

    /**
     * Tiga selisih di atas butuh jawaban Technical Manager (P-1, P-2, P-11), dan sesi yang memicunya ditahan. Yang
     * dikunci: pemicu (varian + jenis tekanan) yang ditentukan profil memang tepat varian yang tercetak berbeda, dan
     * SPMK tidak punya pemicu. Kalau pemicu bergeser tanpa tes ini ikut berubah, sesi yang tercetak berbeda dari
     * master bisa terbit tanpa jawaban TM.
     */
    public function test_pemicu_penahanan_sama_dengan_varian_yang_tercetak_berbeda(): void
    {
        $profil = new class extends VacuumGaugeProfile
        {
            /** @return list<string> */
            public function pemicu(string $varian): array
            {
                return $this->penyimpanganTerpicu($varian);
            }
        };

        // T-11 hanya menyala untuk DRUCK13G berjenis vakum; masukan sesi master memang vakum.
        $this->assertSame('vakum', self::masukan(Tabel::DRUCK13G)['jenis_tekanan']);
        $this->assertSame(['T-1', 'T-2'], $profil->pemicu(Tabel::DRUCK07G));
        $this->assertSame(['T-2', 'T-11'], $profil->pemicu(Tabel::DRUCK13G));
        $this->assertSame([], $profil->pemicu(Tabel::SPMK));
        $this->assertSame(['T-2'], $profil->pemicu(Tabel::DIFFERENTIAL));
    }
}
