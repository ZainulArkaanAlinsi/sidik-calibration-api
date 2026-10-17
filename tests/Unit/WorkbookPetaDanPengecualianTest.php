<?php

namespace Tests\Unit;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Studio\Workbook\PengecualianLembar;
use App\Services\Studio\Workbook\PetaProfilWorkbook;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Peta nama workbook → kode profil (01-riset-peta-workbook.md) dan daftar
 * sheet bertautan tabel (`tautan_tabel`; isinya tidak lagi dikecualikan,
 * K-49-12 mencabut K-49-08).
 */
class WorkbookPetaDanPengecualianTest extends TestCase
{
    /** @return array<string, array{string, ?string, ?string}> */
    public static function petaFolder(): array
    {
        return [
            'micrometer' => ['Master_Olah_Data_Micrometer_25-50mm', 'micrometer', null],
            'micrometer xlsm' => ['Master Olah Data Micrometer 0-25mm.xlsm', 'micrometer', null],
            'fixed volumetric' => ['Fixed_Volumetric_Glassware_2026', null, 'volumetric_fixed'],
            'graduated volumetric' => ['Graduated_Volumetric_Glassware_2026', null, 'volumetric_graduated'],
            'enclosure' => ['Master_Olah_Data_Suhu_Enclosure_Recorder', null, 'enclosure'],
            'piston' => ['Master_Olah_Data_Fixed_Piston_Volume', null, 'piston_volume'],
            'druck' => ['Master_Olda_Pressure_DRUCK07G_-0.82bar_new', null, 'tekanan_druck'],
            'spmk' => ['Master_Olda_Pressure_SPMK', 'pressure_gauge', null],
            'differential' => ['Master_Olda_Pressure_Differential', 'differential_pressure', null],
            'anak timbangan' => ['1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp', 'anak_timbangan', null],
            'timbangan subtitusi' => ['_TERBARU__Master_Olda_Timbangan_Subtitusi_291025', 'timbangan', null],
            'kelembapan' => ['Master_Olah_Data_Suhu___Kelembapan', 'thermohygro', null],
            'tids' => ['Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N', 'tids', null],
            'tits' => ['Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT', 'tits', null],
            'proving ring' => ['Gaya_Proving_Ring', 'proving_ring', null],
            'utm' => ['Gaya_UTM', 'utm', null],
            'caliper' => ['Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_', 'jangka_sorong', null],
            'do meter' => ['Master_Olah_Data_DO_Meter', 'do_meter', null],
            'spectro' => ['Master_Olah_Data_Spectrofotometer', 'spectrophotometer', null],
            'ph salinan' => ['pH_meter_IMTE-WQ-129', 'ph_meter', null],
            'timer' => ['Master_Olda_Timer_dan_Stopwatch', 'timer_stopwatch', null],
            'totalizer' => ['1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026', 'flowmeter_totalizer', null],
            'flowrate' => ['Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026', 'flowmeter_flowrate', null],
            'pipet ukur PV' => ['Pipet Ukur PV 0.5-1-26.xlsm', null, 'volumetric'],
            'asing' => ['Workbook_Tak_Dikenal', null, null],
        ];
    }

    #[DataProvider('petaFolder')]
    public function test_peta_nama_ke_profil(string $nama, ?string $kode, ?string $keluarga): void
    {
        $hasil = (new PetaProfilWorkbook)->cocokkan($nama);

        $this->assertSame($kode, $hasil['kode_profil'], $nama);
        $this->assertSame($keluarga, $hasil['keluarga'], $nama);

        if ($kode === null) {
            $this->assertNotEmpty($hasil['catatan'], 'kode null wajib bercatatan');
        }
    }

    public function test_semua_kode_peta_ada_di_registry(): void
    {
        $kodeRegistry = array_map(static fn ($p): string => $p->kode(), app(CalibrationProfileRegistry::class)->semua());
        $dipakai = PetaProfilWorkbook::kodeDipakai();

        $this->assertGreaterThanOrEqual(30, count($dipakai));
        $this->assertSame([], array_values(array_diff($dipakai, $kodeRegistry)), 'kode di peta yang tidak ada di registry');
    }

    /**
     * Semua folder CSV di repo terpetakan; yang null hanya keluarga yang memang
     * melayani banyak profil (4 folder: Fixed/Graduated Volumetric, 2 Enclosure).
     */
    public function test_folder_csv_repo_terpetakan(): void
    {
        $akar = base_path('Project-PT-Sidik/alat-alat-Pt-Sidik');
        $folder = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($akar, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (strtolower($f->getExtension()) === 'csv') {
                $folder[basename($f->getPath())] = true;
            }
        }

        $this->assertGreaterThanOrEqual(46, count($folder));
        $peta = new PetaProfilWorkbook;
        $tanpaKode = [];

        foreach (array_keys($folder) as $nama) {
            $h = $peta->cocokkan($nama);

            if ($h['kode_profil'] === null) {
                $this->assertNotNull($h['keluarga'], "{$nama} tidak terpetakan sama sekali");
                $tanpaKode[] = $nama;
            }
        }

        sort($tanpaKode);
        $this->assertSame([
            'Fixed_Volumetric_Glassware_2026',
            'Graduated_Volumetric_Glassware_2026',
            'Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa',
            'Master_Olah_Data_Suhu_Enclosure_Recorder',
        ], $tanpaKode);
    }

    /** @return array<string, array{string, ?string}> */
    public static function namaSheet(): array
    {
        return [
            'DATABASE' => ['DATABASE', 'customers'],
            'database spasi' => [' database ', 'customers'],
            'SERTIFIKAT' => ['SERTIFIKAT', 'certificates'],
            'SERTIFIKAT (2)' => ['SERTIFIKAT (2)', 'certificates'],
            'SERTIFIKAT_(2) csv' => ['SERTIFIKAT_(2)', 'certificates'],
            'STYLE 1' => ['SERTIFIKAT STYLE 1', 'certificates'],
            'Certificate (2)' => ['Certificate (2)', 'certificates'],
            'konsep' => ['konsep', 'certificates'],
            'sertifikat kalibrator TIDAK' => ['SERTIFIKAT KALIBRATOR', null],
            'input data TIDAK' => ['INPUT DATA', null],
            'database lain TIDAK' => ['DATABASE STANDAR', null],
        ];
    }

    /** K-49-12: tinggal penanda tautan — isinya tetap dibaca. */
    #[DataProvider('namaSheet')]
    public function test_tautan_lembar(string $nama, ?string $tautan): void
    {
        $this->assertSame($tautan, (new PengecualianLembar)->tautan($nama), $nama);
    }
}
