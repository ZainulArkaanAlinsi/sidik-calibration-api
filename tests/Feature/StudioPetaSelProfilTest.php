<?php

namespace Tests\Feature;

use App\Services\Acuan\SumberAcuan;
use App\Services\Studio\PetaSel\DataBersamaVarian;
use App\Services\Studio\PetaSel\JalurIsi;
use App\Services\Studio\PetaSel\PemeriksaMaster;
use App\Services\Studio\PetaSel\PetaSel;
use App\Services\Studio\Workbook\GagalBacaWorkbook;
use App\Services\Studio\Workbook\PembacaCsvWorkbook;
use App\Support\JsonKanonik;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Paritas peta sel profil SESUDAH Micrometer (rencana studio-kendali-olah-data
 * fase 4, 22 Okt 2026): ekstraksi peta dari ekspor CSV ASLI master di repo,
 * di atas dasar JSON repo, = berkas JSON yang dulu dibangkitkan generator dari
 * master yang sama (sha256 kanonik). Pola `StudioPetaSelMicrometerTest`.
 */
class StudioPetaSelProfilTest extends TestCase
{
    private const AKAR = 'Project-PT-Sidik/alat-alat-Pt-Sidik/';

    private string $temp = '';

    protected function setUp(): void
    {
        parent::setUp();
        SumberAcuan::lupakanCache();
        config(['studio.peta_sel.direktori' => database_path('data/peta-sel')]);
    }

    protected function tearDown(): void
    {
        if ($this->temp !== '' && is_dir($this->temp)) {
            (new Filesystem)->deleteDirectory($this->temp);
        }

        parent::tearDown();
    }

    /**
     * kode paket => [list [folder master, varian harapan, jumlah jalur ditulis]].
     *
     * @return array<string, array{string, list<array{string, ?string, int}>}>
     */
    public static function master(): array
    {
        return [
            // 6 standar + 4 × (label, u95_mm).
            'dial_indicator' => ['dial_indicator', [['Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Dial_Indicator', null, 14]]],
            // 9 standar + 10 × (terkoreksi, koreksi) Outside + 10 × 3 Inside; nominal Outside cuma dicek.
            'height_gauge' => ['height_gauge', [['Panjang_CSV/Master_olda_Height_Gauge_600_mm_2026', null, 59]]],
            // 5 identitas + keping + 2 cmc + 2 konstanta Depth.
            'jangka_sorong' => ['jangka_sorong', [['Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_', null, 10]]],
            // Satu paket, dua master. Fixed: 6 CMC + 2 U95 suhu + neraca + diameter + kalibrator + PRT;
            // Graduated: 6 CMC + 2 U95 suhu + neraca + koefisien muai.
            // Totalizer: 2 tabel std + 2 CMC + identitas/U95 standar pendukung (17); Flowrate: 2 + 2 + timer.
            'flowmeter' => ['flowmeter', [
                ['Aliran_2/1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026', 'totalizer', 21],
                ['Aliran_2/Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026', 'flowrate', 5],
            ]],
            // Tiga master: 44 butir ekstrak dikurangi 3 identitas 5 kN di Proving Ring (#REF!).
            'gaya' => ['gaya', [
                ['Alat_Gaya/Gaya_UTM', 'utm', 44],
                ['Alat_Gaya/Gaya_Load_Cell', 'load_cell', 44],
                ['Alat_Gaya/Gaya_Proving_Ring', 'proving_ring', 41],
            ]],
            // Identitas standar + tabel sertifikat; drift dijaga cek, tidak diekstrak.
            'putaran' => ['putaran', [
                ['Waktu_/Master_Olda_Tachometer', 'tachometer', 6],
                ['Waktu_/Master_Olda_Centrifuge', 'centrifuge', 5],
            ]],
            'timer_stopwatch' => ['timer_stopwatch', [['Waktu_/Master_Olda_Timer_dan_Stopwatch', null, 6]]],
            // Enclosure Constant_Yokogawa: 20 tabel meter + 52 tabel sensor + 9 drift + index_temps.
            // Master Recorder DITAHAN (lihat test_enclosure_recorder_dilewati_peta).
            'enclosure' => ['enclosure', [['suhu_&_kelembapan/Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa', 'constant_yokogawa', 82]]],
            // TITS: per mode 14 tabel titik (7 tipe × 2 kalibrator) + 13 drift.
            'kalibrator_suhu' => ['kalibrator_suhu', [
                ['suhu_&_kelembapan/Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT', 'measure', 27],
                ['suhu_&_kelembapan/Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT', 'source', 27],
            ]],
            'volumetric' => ['volumetric', [
                ['Volumetric_Glassware_2026/Fixed_Volumetric_Glassware_2026', 'fixed', 12],
                ['Volumetric_Glassware_2026/Graduated_Volumetric_Glassware_2026', 'graduated', 10],
            ]],
        ];
    }

    #[DataProvider('master')]
    public function test_ekstraksi_master_asli_identik_dengan_json(string $kode, array $daftar): void
    {
        $peta = PetaSel::untuk($kode) ?? $this->fail("peta {$kode} tidak ada");
        $this->assertSame([], PetaSel::validasi($kode, $peta->isi));
        $json = json_decode((string) file_get_contents(database_path('data/'.$peta->isi['sumber_json'])), true);
        $shaJson = JsonKanonik::sha256(JsonKanonik::serialisasi($json));

        foreach ($daftar as [$folder, $varian, $jumlahJalur]) {
            $lembar = app(PembacaCsvWorkbook::class)->baca(base_path(self::AKAR.$folder))['lembar'];
            $hasil = app(PemeriksaMaster::class)->periksa($peta, basename($folder), $lembar, $json);

            $this->assertSame($varian, $hasil['varian'], $folder);
            $this->assertSame($shaJson, JsonKanonik::sha256(JsonKanonik::serialisasi($hasil['isi'])), "{$folder}: isi ekstraksi beda dari JSON");
            $this->assertCount($jumlahJalur, $hasil['jalur'], $folder);

            // Ekstraksi memang MENULIS: tiap daun yang dipetakan dirusak di
            // dasar, master memulihkannya.
            $rusak = $json;

            foreach ($hasil['jalur'] as $jalur) {
                JalurIsi::tulis($rusak, $jalur, '__rusak__');
            }

            $pulih = app(PemeriksaMaster::class)->periksa($peta, basename($folder), $lembar, $rusak);
            $this->assertSame($shaJson, JsonKanonik::sha256(JsonKanonik::serialisasi($pulih['isi'])), "{$folder}: dasar rusak tidak dipulihkan");
        }

        // (e) Data bersama antar varian diturunkan otomatis dari jalur tanpa {varian}.
        $bersama = DataBersamaVarian::jalurBersama($peta);
        $this->assertSame(isset($peta->isi['varian']) ? $bersama : [], $bersama);
    }

    /**
     * Height Gauge: dua penjagaan generator ditiru lewat `cek` — sepuluh
     * nominal Outside (titik pra-cetak) tidak boleh berubah, dan U95 kedua
     * tabel wajib satu angka (= standar.u95_um).
     */
    public function test_height_gauge_nominal_outside_dan_u95_berbeda_ditolak(): void
    {
        $peta = PetaSel::untuk('height_gauge') ?? $this->fail('peta height_gauge tidak ada');
        $json = json_decode((string) file_get_contents(database_path('data/tabel-standar-height-gauge.json')), true);
        $asal = base_path(self::AKAR.'Panjang_CSV/Master_olda_Height_Gauge_600_mm_2026');

        foreach ([
            [',1,25,,,25.0008,', ',1,30,,,25.0008,', 'Std_CaliperCek!C10 = 30'],
            ['-0.0011,-1.1,4.1', '-0.0011,-1.1,4.2', 'Std_CaliperCek!J32 = 4.2'],
        ] as [$cari, $ganti, $harap]) {
            $this->temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sidik-petasel-'.bin2hex(random_bytes(5));
            (new Filesystem)->copyDirectory($asal, $this->temp);
            $berkas = $this->temp.'/Std_CaliperCek.csv';
            $isi = (string) file_get_contents($berkas);
            $this->assertSame(1, substr_count($isi, $cari), "prasyarat: {$cari}");
            file_put_contents($berkas, str_replace($cari, $ganti, $isi));

            try {
                app(PemeriksaMaster::class)->periksa($peta, basename($asal), app(PembacaCsvWorkbook::class)->baca($this->temp)['lembar'], $json);
                $this->fail("{$harap} seharusnya ditolak");
            } catch (GagalBacaWorkbook $e) {
                $this->assertStringContainsString($harap, $e->getMessage());
            } finally {
                (new Filesystem)->deleteDirectory($this->temp);
            }
        }
    }

    /**
     * Drift Putaran & Waktu tidak diekstrak (rentang/maks = turunan generator)
     * tapi dijaga: master dengan koreksi drift lain DITOLAK, tidak diam-diam
     * menyisakan rentang lama.
     */
    public function test_drift_putaran_dan_waktu_berubah_ditolak(): void
    {
        foreach ([
            ['putaran', 'Waktu_/Master_Olda_Tachometer', 'Drift Std Kalibrator.csv', ',,30000,0,1.4,1.5,-2,-3,0,-2,', ',,30000,0,1.4,1.5,-2,-3,0,-2.5,', 'Drift Std Kalibrator!J32 = -2.5'],
            ['timer_stopwatch', 'Waktu_/Master_Olda_Timer_dan_Stopwatch', 'Drift Stopwatch.csv', ',0,40,0,0,278,245,-10,-20,', ',0,40,0,0,278,245,-10,-25,', 'Drift Stopwatch!I23 = -25'],
        ] as [$kode, $folder, $csv, $cari, $ganti, $harap]) {
            $peta = PetaSel::untuk($kode) ?? $this->fail("peta {$kode}");
            $json = json_decode((string) file_get_contents(database_path('data/'.$peta->isi['sumber_json'])), true);
            $this->temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sidik-petasel-'.bin2hex(random_bytes(5));
            (new Filesystem)->copyDirectory(base_path(self::AKAR.$folder), $this->temp);
            $isi = (string) file_get_contents($this->temp.'/'.$csv);
            $this->assertSame(1, substr_count($isi, $cari), "prasyarat {$kode}");
            file_put_contents($this->temp.'/'.$csv, str_replace($cari, $ganti, $isi));

            try {
                app(PemeriksaMaster::class)->periksa($peta, basename($folder), app(PembacaCsvWorkbook::class)->baca($this->temp)['lembar'], $json);
                $this->fail("{$harap} seharusnya ditolak");
            } catch (GagalBacaWorkbook $e) {
                $this->assertStringContainsString($harap, $e->getMessage());
            } finally {
                (new Filesystem)->deleteDirectory($this->temp);
            }
        }
    }

    /**
     * Master .xlsm memakai alamat lain dari ekspor CSV (baris kosong dibuang
     * ekspor, nama lembar berspasi). Bagian `xlsm` peta menerjemahkannya:
     * lembar CSV yang digeser & diganti nama PERSIS menurut aturan peta —
     * tiruan bentuk .xlsm — diekstrak dengan format xlsm memulangkan JSON yang
     * sama, dan dengan format csv ditolak. (Paritas terhadap .xlsm asli
     * dibuktikan `docs/skrip/verifikasi-peta-xlsm.py`; berkasnya tidak di repo.)
     */
    #[DataProvider('peta_xlsm')]
    public function test_alamat_xlsm_diterjemahkan(string $kode, string $folder, string $varian): void
    {
        $peta = PetaSel::untuk($kode) ?? $this->fail("peta {$kode}");
        $json = json_decode((string) file_get_contents(database_path('data/'.$peta->isi['sumber_json'])), true);
        $lembar = app(PembacaCsvWorkbook::class)->baca(base_path(self::AKAR.$folder))['lembar'];
        $alih = $peta->alihXlsm('xlsm', $varian);
        $this->assertNotSame([], $alih['geser']);

        $tiruan = array_map(static function (array $l) use ($alih): array {
            $geser = $alih['geser'][$l['nama']] ?? [];
            $sel = array_map(static function (array $s) use ($geser): array {
                $g = 0;

                foreach ($geser as [$dari, $n]) {
                    $g = $s['b'] >= $dari ? $n : $g;
                }

                $s['b'] += $g;
                $s['a'] = preg_replace('/\d+$/', (string) $s['b'], $s['a']);

                return $s;
            }, json_decode((string) $l['sel_kanonik'], true));

            return ['nama' => $alih['lembar'][$l['nama']] ?? $l['nama'], 'sel_kanonik' => json_encode($sel)] + $l;
        }, $lembar);

        $nama = basename($folder);
        $hasil = app(PemeriksaMaster::class)->periksa($peta, $nama, $tiruan, $json, PemeriksaMaster::FORMAT_XLSM);
        $this->assertSame($varian, $hasil['varian']);
        $this->assertSame(JsonKanonik::sha256(JsonKanonik::serialisasi($json)), JsonKanonik::sha256(JsonKanonik::serialisasi($hasil['isi'])));

        $this->expectException(GagalBacaWorkbook::class);
        app(PemeriksaMaster::class)->periksa($peta, $nama, $tiruan, $json, PemeriksaMaster::FORMAT_CSV);
    }

    /** @return array<string, array{string, string, string}> */
    public static function peta_xlsm(): array
    {
        return [
            'gaya proving ring' => ['gaya', 'Alat_Gaya/Gaya_Proving_Ring', 'proving_ring'],
            'gaya utm' => ['gaya', 'Alat_Gaya/Gaya_UTM', 'utm'],
            'volumetric fixed' => ['volumetric', 'Volumetric_Glassware_2026/Fixed_Volumetric_Glassware_2026', 'fixed'],
            'volumetric graduated' => ['volumetric', 'Volumetric_Glassware_2026/Graduated_Volumetric_Glassware_2026', 'graduated'],
        ];
    }

    /**
     * Master Enclosure Recorder sengaja BELUM dipetakan: tabel-kalibrator-
     * enclosure.json tidak memuat titik -20 °C (U95 meter recorder 40 kanal,
     * koreksi & U95 sensor Type N 10 kanal) yang ada di ekspor CSV repo dan di
     * .xlsm aslinya. Peta melewatinya (`lainnya: lewati`) sampai pemilik/lab
     * memutuskan; test ini memaku selisih itu supaya tidak hilang diam-diam.
     */
    public function test_enclosure_recorder_dilewati_peta_dan_selisih_minus_20_dipaku(): void
    {
        $peta = PetaSel::untuk('enclosure') ?? $this->fail('peta enclosure');
        $this->assertTrue($peta->tidakBerlakuUntuk('Master Olah Data_Suhu_Enclosure_Recorder.xlsm'));
        $this->assertFalse($peta->tidakBerlakuUntuk('Master Olah Data_Suhu_Enclosure_Constant Yokogawa.xlsm'));

        $json = json_decode((string) file_get_contents(database_path('data/tabel-kalibrator-enclosure.json')), true);
        $this->assertArrayNotHasKey('-20', $json['meter']['recorder']['u95']['Type N']['1']);
        $this->assertArrayNotHasKey('-20', $json['sensor']['recorder']['koreksi']['Type N']['3']);

        $csv = (string) file_get_contents(base_path(self::AKAR.'suhu_&_kelembapan/Master_Olah_Data_Suhu_Enclosure_Recorder/Standar_Kalibrator.csv'));
        $baris = preg_split('/\R/', $csv);
        $this->assertStringStartsWith(',-20,,0.83,', (string) $baris[29], 'U95 meter recorder -20 °C ada di master');
        $this->assertStringStartsWith(',-20,,-0.15,', (string) $baris[52], 'koreksi sensor recorder TCN3 -20 °C ada di master');
    }

    public function test_bilangan_bulat_menolak_pecahan_dan_bagi_hanya_untuk_angka(): void
    {
        $peta = PetaSel::untuk('dial_indicator') ?? $this->fail('peta dial_indicator tidak ada');

        $salah = $peta->isi;
        $salah['ekstrak'][0]['bagi'] = 1000; // teks
        $salah['ekstrak'][7]['bagi'] = 0;
        $masalah = implode(' | ', PetaSel::validasi('dial_indicator', $salah));
        $this->assertStringContainsString('ekstrak[0]: bagi hanya untuk tipe angka/daftar_angka', $masalah);
        $this->assertStringContainsString('ekstrak[7]: bagi harus angka > 0', $masalah);

        // daftar_objek: kunci wajib salah satu kolom, kolom sebaris.
        $js = PetaSel::untuk('jangka_sorong')?->isi ?? $this->fail('peta jangka_sorong tidak ada');
        $js['ekstrak'][5]['kunci'] = 'tidak_ada';
        $js['ekstrak'][5]['kolom']['u95_mm'] = 'Standar_GB!S11:S132';
        $masalah = implode(' | ', PetaSel::validasi('jangka_sorong', $js));
        $this->assertStringContainsString('ekstrak[5]: kunci harus salah satu nama di kolom', $masalah);
        $this->assertStringContainsString('ekstrak[5]: tiap kolom harus satu kolom dengan jumlah baris yang sama', $masalah);

        $this->temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sidik-petasel-'.bin2hex(random_bytes(5));
        (new Filesystem)->copyDirectory(base_path(self::AKAR.'Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Dial_Indicator'), $this->temp);
        $berkas = $this->temp.'/DATABASE.csv';
        $isi = (string) file_get_contents($berkas);
        $baru = str_replace('2024-01-24T00:00:00,3,', '2024-01-24T00:00:00,2.5,', $isi);
        $this->assertNotSame($isi, $baru, 'prasyarat: X13 ditemukan');
        file_put_contents($berkas, $baru);

        $json = json_decode((string) file_get_contents(database_path('data/tabel-standar-dial-indicator.json')), true);
        $lembar = app(PembacaCsvWorkbook::class)->baca($this->temp)['lembar'];

        try {
            app(PemeriksaMaster::class)->periksa($peta, 'Master_Olah_Data_Dial_Indicator', $lembar, $json);
            $this->fail('X13 pecahan seharusnya ditolak');
        } catch (GagalBacaWorkbook $e) {
            $this->assertSame(GagalBacaWorkbook::STRUKTUR_BERUBAH, $e->kode);
            $this->assertStringContainsString('DATABASE!X13 = 2.5, bukan bilangan bulat', $e->getMessage());
        }
    }
}
