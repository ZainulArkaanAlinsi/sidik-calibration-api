<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Services\CertificateExcelExporter;
use App\Services\CertificateSnapshotBuilder;
use App\Services\DataTampilanSertifikat;
use App\Services\SertifikatSatuHalaman;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Tests\TestCase;

/**
 * Sertifikat Enclosure mencetak DUA bagian master, bukan tabel empat kolom.
 *
 * ## Bug yang dijaga
 *
 * Sampai 15 Okt 2026 snapshot enclosure lewat tabel bawaan `Standard | UUT |
 * Correction | U95`, satu baris per set point. Kolom `koreksi` baris
 * enclosure menyimpan KESERAGAMAN (wakil "koreksi terbesar", lihat
 * `EnclosureProfileBase::barisHitungan`), jadi sertifikat Inkubator simulasi
 * olah data terbit `Standard 25,5 | UUT 25,5 | Correction 0,8 | U95 1,40` —
 * dibaca pelanggan "tambahkan 0,8 °C", padahal 0,8 itu selisih antar-posisi.
 * Sebaran per sensor dan Kinerja tidak tercetak sama sekali.
 *
 * ## Acuannya
 *
 * Sheet `SERTIFIKAT` kedua master enclosure (CSV di
 * `Project-PT-Sidik/alat-alat-Pt-Sidik/suhu_&_kelembapan/Master_Olah_Data_Suhu_Enclosure_*`),
 * diadu ke sesi contoh seeder yang sama dengan `EnclosureSesiTest`:
 *
 *   - `2405.03.AV` Inkubator, Yokogawa + Type N, 4 set point (15/35/75/100)
 *   - `2406.25.AI` Oven, Recorder GL840 + Type K, 3 set point @ 67
 *
 * Angka master disalin dari nilai sel (`F25:S35`, `B25`, `J42:O47`), presisi
 * penuh — bukan dari tampilan yang sudah dibulatkan.
 */
class EnclosureSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private const TOL = 1e-9;

    /**
     * `SERTIFIKAT!F25:S35` (Terukur, posisi 1..9) & `J42:O45` (KS/SS/VK)
     * master Constant/Yokogawa, sesi 0123-CAL-524.
     *
     * @var array<int, array{set_point: float, indikator: float, terukur: list<float>, ks: float, ss: float, vk: float}>
     */
    private const MASTER_YOKOGAWA = [
        ['set_point' => 15.0, 'indikator' => 15.0, 'terukur' => [14.346249999999952, 14.506249999999955, 14.386249999999956, 14.426249999999953, 14.186249999999955, 14.526249999999957, 14.366249999999955, 14.446249999999955, 14.186249999999955], 'ks' => 0.20000000000000107, 'ss' => 0.09999999999999964, 'vk' => 0.40000000000000036],
        ['set_point' => 35.0, 'indikator' => 35.0, 'terukur' => [34.166249999999955, 34.166249999999955, 34.28624999999995, 34.02624999999996, 33.82624999999995, 34.22624999999996, 34.02624999999996, 34.24624999999996, 34.42624999999996], 'ks' => 0.3999999999999986, 'ss' => 0.05000000000000071, 'vk' => 0.7000000000000028],
        ['set_point' => 75.0, 'indikator' => 75.0, 'terukur' => [74.7475, 74.7875, 75.105, 74.86749999999999, 74.76750000000001, 75.165, 74.62749999999998, 75.0075, 75.00749999999998], 'ks' => 0.5575000000000188, 'ss' => 0.20000000000000284, 'vk' => 0.6575000000000131],
        ['set_point' => 100.0, 'indikator' => 100.0, 'terukur' => [99.805, 99.76500000000001, 100.22100000000002, 100.15900000000002, 99.90500000000002, 99.64500000000001, 99.78500000000001, 100.08300000000001, 100.09900000000002], 'ks' => 0.539999999999992, 'ss' => 0.10000000000000142, 'vk' => 0.7399999999999949],
    ];

    /**
     * Master Recorder sesi 0304-CAL-624, set point 1 (`F25:S25`, `J41:O41`).
     * SP2 identik. SP3 master SENGAJA tidak dipakai: blok selnya rusak
     * (`docs/pertanyaan-lab-enclosure.md` #5b) dan kalkulator menghitungnya
     * sama dengan SP1.
     */
    private const MASTER_RECORDER_SP1 = [
        'set_point' => 67.0,
        'indikator' => 67.36,
        'terukur' => [67.032, 67.542, 68.762, 67.758, 66.142, 67.454, 68.032, 66.634, 67.032],
        'ks' => 1.75,
        'ss' => 0.01999999999999602,
        'vk' => 2.6399999999999864,
    ];

    /**
     * Angka SISTEM sejak 15 Okt 2026 (peta kolom FC master tidak ditiru).
     *
     * Sumber, dihitung terpisah dari kalkulator: sel master SERTIFIKAT (Terukur
     * di atas) + selisih AVERAGE kelima pembacaan `INPUT DATA` (fixture
     * `tests/Fixtures/enclosure-golden.json`) terhadap AVERAGE versi peta
     * master `[1,2,3,3,4]`; koreksi per termokopel = Terukur master − AVERAGE
     * versi peta. Keseragaman/Kestabilan/Variasi dari pembacaan terkoreksi
     * kelimanya (R42/R41/R43).
     *
     * Satu pengecualian, SP3 posisi 9 (TC no. 11): rata-rata kelima pembacaan
     * 75,02 masuk indeks tabel 100 (versi peta master tepat 75,00 masuk 50),
     * jadi koreksinya = koreksi TC11 di indeks 100 dari master sendiri
     * (SERTIFIKAT!S31 − AVERAGE versi peta SP4 = 0,065) → 75,02 + 0,065.
     *
     * @var array<int, array{set_point: float, indikator: float, terukur: list<float>, ks: float, ss: float, vk: float}>
     */
    private const SISTEM_YOKOGAWA = [
        ['set_point' => 15.0, 'indikator' => 15.0, 'terukur' => [14.346249999999952, 14.506249999999955, 14.406249999999957, 14.446249999999951, 14.186249999999955, 14.546249999999958, 14.386249999999956, 14.466249999999953, 14.206249999999958], 'ks' => 0.3000000000000078, 'ss' => 0.10000000000000053, 'vk' => 0.5],
        ['set_point' => 35.0, 'indikator' => 35.0, 'terukur' => [34.166249999999955, 34.166249999999955, 34.28624999999995, 34.02624999999996, 33.82624999999995, 34.24624999999996, 34.04624999999996, 34.22624999999996, 34.40624999999996], 'ks' => 0.3999999999999986, 'ss' => 0.10000000000000142, 'vk' => 0.7000000000000028],
        ['set_point' => 75.0, 'indikator' => 75.0, 'terukur' => [74.7475, 74.7875, 75.10500000000002, 74.86749999999999, 74.76750000000001, 75.185, 74.6475, 75.0075, 75.08500000000002], 'ks' => 0.5575000000000188, 'ss' => 0.20000000000000284, 'vk' => 0.7575000000000216],
        ['set_point' => 100.0, 'indikator' => 100.0, 'terukur' => [99.805, 99.78500000000003, 100.24500000000002, 100.16300000000001, 99.925, 99.66499999999999, 99.82500000000003, 100.08500000000001, 100.10300000000002], 'ks' => 0.5400000000000205, 'ss' => 0.10000000000000142, 'vk' => 0.7400000000000233],
    ];

    /** Sumber sama dengan [SISTEM_YOKOGAWA], dari Recorder SP1 (SP2 identik). */
    private const SISTEM_RECORDER_SP1 = [
        'set_point' => 67.0,
        'indikator' => 67.36,
        'terukur' => [67.028, 67.544, 68.75599999999999, 67.762, 66.14, 67.44999999999999, 68.032, 66.634, 67.032],
        'ks' => 1.75,
        'ss' => 0.02499999999999858,
        'vk' => 2.6499999999999915,
    ];

    /**
     * Versi master yang dicatat di jejak `peta_kolom_master_rusak`, per titik.
     *
     * @return list<array<string, mixed>|null>
     */
    private function versiMasterDiJejak(CalibrationSession $sesi): array
    {
        return $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get()
            ->map(fn ($u): ?array => collect($u->type_b_components)->firstWhere('sumber', 'peta_kolom_master_rusak')['rincian']['versi_master'] ?? null)
            ->all();
    }

    /** Versi master di jejak = nilai SEL master (SERTIFIKAT F25.., J/L/O). */
    private function cocokkanVersiMaster(array $master, ?array $versi, string $label): void
    {
        $this->assertNotNull($versi, "{$label}: jejak `peta_kolom_master_rusak` tidak ada");

        foreach ($master['terukur'] as $i => $terukur) {
            $this->assertEqualsWithDelta($terukur, $versi['terukur'][$i], self::TOL, "{$label}: versi master Terukur posisi ".($i + 1));
        }

        $this->assertEqualsWithDelta($master['ks'], $versi['keseragaman'], self::TOL, "{$label}: versi master KS");
        $this->assertEqualsWithDelta($master['ss'], $versi['kestabilan'], self::TOL, "{$label}: versi master SS");
        $this->assertEqualsWithDelta($master['vk'], $versi['variasi_keseluruhan'], self::TOL, "{$label}: versi master VK");
    }

    private function sesi(string $nomorSesi): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        return CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();
    }

    /** Sertifikat terbit berisi snapshot dari builder yang sedang diuji. */
    private function terbitkan(CalibrationSession $sesi): Certificate
    {
        $sertifikat = Certificate::factory()->create([
            'organization_id' => $sesi->organization_id,
            'calibration_session_id' => $sesi->id,
        ]);

        $sertifikat->update(['snapshot' => app(CertificateSnapshotBuilder::class)->bangun($sesi, $sertifikat)]);

        return $sertifikat->fresh();
    }

    /** @param  array{set_point: float, indikator: float, terukur: list<float>, ks: float, ss: float, vk: float}  $master */
    private function cocokkanSetPoint(array $master, array $sp, string $label): void
    {
        $this->assertEqualsWithDelta($master['set_point'], $sp['set_point'], self::TOL, "{$label}: set point");
        $this->assertEqualsWithDelta($master['indikator'], $sp['indikator'], 1e-8, "{$label}: indikator (B25)");
        $this->assertSame(1, $sp['posisi_acuan'], "{$label}: Sensor Acuan mestinya posisi 1");
        $this->assertCount(count($master['terukur']), $sp['sensor']);

        foreach ($master['terukur'] as $i => $terukur) {
            $sensor = $sp['sensor'][$i];
            $this->assertSame($i + 1, $sensor['posisi']);
            $this->assertEqualsWithDelta($terukur, $sensor['terukur'], self::TOL, "{$label}: Terukur posisi ".($i + 1));
            // `SERTIFIKAT!F26 = F25 − $B$25`.
            $this->assertEqualsWithDelta($terukur - $master['indikator'], $sensor['koreksi'], 1e-8, "{$label}: Koreksi posisi ".($i + 1));
        }

        $this->assertEqualsWithDelta($master['ks'], $sp['keseragaman'], self::TOL, "{$label}: Keseragaman (R42)");
        $this->assertEqualsWithDelta($master['ss'], $sp['kestabilan'], self::TOL, "{$label}: Kestabilan (R41)");
        $this->assertEqualsWithDelta($master['vk'], $sp['variasi_keseluruhan'], self::TOL, "{$label}: Variasi (R43)");
    }

    public function test_snapshot_inkubator_yokogawa_cocok_sertifikat_master(): void
    {
        $sesi = $this->sesi('2405.03.AV');
        $snapshot = $this->terbitkan($sesi)->snapshot;

        // Tabel empat kolom sengaja kosong — sumber "Correction 0,8".
        $this->assertSame([], $snapshot['hasil']);

        $enc = $snapshot['enclosure'];
        $this->assertIsArray($enc);
        $this->assertSame('°C', $enc['satuan']);

        // Kolom master 1..9 = termokopel Type N no. 3..11 (nomor terkecil = posisi 1).
        $this->assertSame(range(1, 9), array_column($enc['posisi'], 'posisi'));
        $this->assertSame(range(3, 11), array_column($enc['posisi'], 'no'));

        $this->assertCount(4, $enc['set_point']);

        $versiMaster = $this->versiMasterDiJejak($sesi);

        foreach (self::SISTEM_YOKOGAWA as $i => $sistem) {
            $sp = $enc['set_point'][$i];
            $this->cocokkanSetPoint($sistem, $sp, 'Yokogawa SP'.($i + 1));
            $this->cocokkanVersiMaster(self::MASTER_YOKOGAWA[$i], $versiMaster[$i], 'Yokogawa SP'.($i + 1));
            // U95 dilaporkan `U25:U35` = 1,4 (lantai CMC Inkubator).
            $this->assertEqualsWithDelta(1.4, $sp['u95'], self::TOL);
            $this->assertSame(3, $sp['sensor_acuan']);
        }

        // `SERTIFIKAT!P37` = `PERHITUNGAN U95%!AC37` = k set point pertama,
        // master 1,9616120829. Sistem 1,96161517 — selisih ~3e-6 lahir di
        // `v_eff` budget (bukan di snapshot ini; Uc-nya sendiri cocok 1e-7 di
        // `EnclosureSesiTest`). Yang tercetak sama: `1,96`.
        $this->assertEqualsWithDelta(1.9616120828804522, $enc['set_point'][0]['faktor_cakupan_k'], 1e-5);

        // Format sel master Constant/Yokogawa (B25, F25:S36, U25, C42:O45 `0.0`; P37 `0`).
        $this->assertSame('constant_yokogawa', $enc['varian_master']);
        // Urutan kunci tidak dijamin: kolom JSON MySQL mengurutkan ulang kunci objek.
        $this->assertEquals(['indikator' => 1, 'terukur' => 1, 'koreksi' => 1, 'u95' => 1, 'k' => 0, 'setting' => 1, 'indikator_kinerja' => 1, 'kinerja' => 1], $enc['desimal']);
    }

    public function test_snapshot_oven_recorder_cocok_sertifikat_master(): void
    {
        $sesi = $this->sesi('2406.25.AI');
        $enc = $this->terbitkan($sesi)->snapshot['enclosure'];
        $versiMaster = $this->versiMasterDiJejak($sesi);

        $this->assertCount(3, $enc['set_point']);
        $this->assertSame(range(1, 9), array_column($enc['posisi'], 'no'));

        $this->cocokkanSetPoint(self::SISTEM_RECORDER_SP1, $enc['set_point'][0], 'Recorder SP1');
        $this->cocokkanSetPoint(self::SISTEM_RECORDER_SP1, $enc['set_point'][1], 'Recorder SP2');
        $this->cocokkanVersiMaster(self::MASTER_RECORDER_SP1, $versiMaster[0], 'Recorder SP1');
        $this->cocokkanVersiMaster(self::MASTER_RECORDER_SP1, $versiMaster[1], 'Recorder SP2');
        $this->assertSame('recorder', $enc['varian_master']);

        // SP3: data identik dengan SP1, master-nya rusak sel (#5b) — yang
        // tercetak di sistem sama dengan SP1, bukan salinan sel rusak itu.
        $this->assertEquals($enc['set_point'][0]['sensor'], $enc['set_point'][2]['sensor']);

        // Kanal Recorder ikut dibekukan (jejak audit), tidak dicetak.
        $this->assertNotNull($enc['set_point'][0]['sensor'][0]['channel']);
        $this->assertEqualsWithDelta(1.5, $enc['set_point'][0]['u95'], self::TOL);
    }

    public function test_pdf_cetak_sebaran_dan_kinerja_tanpa_baris_correction(): void
    {
        // K-50-15 (pemilik 20 Okt 2026, "ikut master semua"): sertifikat baru
        // bertitik desimal seperti tampilan sel master; desimalnya tidak berubah.
        $sertifikat = $this->terbitkan($this->sesi('2405.03.AV'));
        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();

        foreach ([
            'A) SEBARAN SUHU',
            'B) KINERJA ENKLOSUR',
            'Pembacaan Indikator Enklosur (°C)',
            'Standar dan Koreksi Indikator (°C)',
            'Sensor No. (No. 1 Sensor Acuan)',
            'Terukur (°C)',
            'Koreksi (°C)',
            'Suhu Setting (°C)',
            'Suhu yang diukur Indikator Enklosur (°C)',
            'Keseragaman (°C)',
            'Kestabilan (°C)',
            'Variasi Keseluruhan (°C)',
        ] as $teks) {
            $this->assertStringContainsString($teks, $html, "Bagian `{$teks}` nggak kecetak.");
        }

        // SP1 posisi 1: Terukur 14,34625 → `14,3`, Koreksi −0,65375 → `-0,7`;
        // SP4 posisi 3: 100,221 → `100,2`. Satu desimal = format sel `0.0` master.
        $this->assertStringContainsString('<td>14.3</td>', $html);
        $this->assertStringContainsString('<td>-0.7</td>', $html);
        $this->assertStringContainsString('<td>100.2</td>', $html);
        // U95 `U25` format `0.0` → `1,4` (bukan `1,40`); k `P37` format `0` → `2`.
        $this->assertStringContainsString('<td rowspan="2">1.4</td>', $html);
        $this->assertStringNotContainsString('1.40', $html);
        $this->assertMatchesRegularExpression('/coverage factor \( k \)\s*=\s*2\s*</', $html);
        // SP1 Kinerja sistem (5 pembacaan): KS 0,3 — master (peta rusak) 0,2.
        $this->assertStringContainsString('<td>0.3</td>', $html);

        // Yang DIBUANG: tabel empat kolom & kalimat baku `k = 2`.
        $this->assertStringNotContainsString('Correction', $html);
        $this->assertStringNotContainsString('Uncertainty U', $html);
        $this->assertStringNotContainsString('Coverage Factor (k) = 2', $html);

        $pdf = app(SertifikatSatuHalaman::class)->isi($sertifikat);
        $this->assertSame(1, preg_match_all('~/Type\s*/Page[^s]~', $pdf), 'Sertifikat Inkubator meluap dari satu halaman.');
    }

    public function test_excel_tulis_sebaran_dan_kinerja_bukan_correction(): void
    {
        $sertifikat = $this->terbitkan($this->sesi('2405.03.AV'));
        $berkas = tempnam(sys_get_temp_dir(), 'enc-').'.xlsx';

        try {
            app(CertificateExcelExporter::class)->satu($sertifikat, $berkas);

            $reader = new Reader;
            $reader->open($berkas);
            $baris = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $baris[] = $row->toArray();
                }
            }

            $reader->close();
        } finally {
            @unlink($berkas);
        }

        $sel = collect($baris)->flatten();

        $this->assertTrue($sel->contains('A) Sebaran Suhu'));
        $this->assertTrue($sel->contains('B) Kinerja Enklosur'));
        $this->assertFalse($sel->contains('Correction'));
        $this->assertFalse($sel->contains(fn ($v) => is_string($v) && str_contains($v, 'Coverage Factor (k) = 2')));

        // Baris Terukur SP1: indikator 15, lalu posisi 1..9, lalu U95.
        $terukur = collect($baris)->first(fn (array $r): bool => ($r[1] ?? null) === 'Terukur (°C)');
        $this->assertEqualsWithDelta(15.0, $terukur[0], self::TOL);
        $this->assertEqualsWithDelta(14.3, $terukur[2], self::TOL);
        $this->assertEqualsWithDelta(1.4, $terukur[11], self::TOL);

        // Kinerja SP1: setting 15 · indikator 15 · KS 0,2 · SS 0,1 · VK 0,4.
        $judulKinerja = collect($baris)->search(fn (array $r): bool => ($r[0] ?? null) === 'Suhu Setting (°C)');
        $this->assertNotFalse($judulKinerja, 'Tabel Kinerja Enklosur nggak ada.');

        // SISTEM_YOKOGAWA SP1: KS 0,3 · SS 0,1 · VK 0,5 (format `0.0`).
        foreach ([15.0, 15.0, 0.3, 0.1, 0.5] as $i => $harap) {
            $this->assertEqualsWithDelta($harap, (float) $baris[$judulKinerja + 1][$i], self::TOL, "Kinerja SP1 kolom {$i}");
        }
    }

    /**
     * K-50-15, temuan F-1 paritas format (22 Okt 2026): master
     * Constant/Yokogawa memformat Pembacaan Indikator set point ke-5 & ke-6
     * (`SERTIFIKAT!B33`, `B35`) `0.00` sementara `B25:B31` `0.0`, dan Suhu
     * Setting ke-5 & ke-6 (`C46:C47`) General sementara `C42:C45` `0.0`.
     * Format per sel dibekukan di `tests/Fixtures/peta-sel-xlsm/format-sertifikat.json`.
     * Sesi contoh hanya 4 set point, jadi baris SP4 diduplikasi jadi SP5/SP6.
     */
    public function test_set_point_5_dan_6_ikut_format_baris_master(): void
    {
        $sesi = $this->sesi('2405.03.AV');
        $sp4 = $sesi->uncertaintyCalculations()->orderByDesc('titik_ke')->firstOrFail();

        foreach ([5 => [120.0, 120.126], 6 => [140.0, 140.124]] as $ke => [$setting, $indikator]) {
            $baris = $sp4->replicate();
            $baris->fill(['titik_ke' => $ke, 'titik_ukur' => $setting, 'rata_rata' => $indikator])->save();
        }

        $sertifikat = $this->terbitkan($sesi->fresh());
        $sp = $sertifikat->snapshot['enclosure']['set_point'];
        $this->assertCount(6, $sp);

        foreach ([0, 1, 2, 3] as $i) {
            $this->assertArrayNotHasKey('desimal_indikator', $sp[$i], 'SP'.($i + 1).' ikut format kolom (B25 `0.0`)');
            $this->assertArrayNotHasKey('desimal_setting', $sp[$i]);
        }

        foreach ([4, 5] as $i) {
            $this->assertSame(2, $sp[$i]['desimal_indikator'], 'SP'.($i + 1).' B33/B35 `0.00`');
            $this->assertNull($sp[$i]['desimal_setting'], 'SP'.($i + 1).' C46/C47 General');
        }

        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();
        // Sebaran: indikator SP1 `0.0` → `15.0`; SP5/SP6 `0.00` → `120.13` / `140.12`.
        $this->assertStringContainsString('<td rowspan="2">15.0</td>', $html);
        $this->assertStringContainsString('<td rowspan="2">120.13</td>', $html);
        $this->assertStringContainsString('<td rowspan="2">140.12</td>', $html);
        // Kinerja: setting General `120`, indikator kinerja tetap `E46/E47` `0.0` → `120.1`.
        $this->assertStringContainsString('<td>120</td>', $html);
        $this->assertStringContainsString('<td>120.1</td>', $html);
        $this->assertStringNotContainsString('<td>120.13</td>', $html);

        $berkas = tempnam(sys_get_temp_dir(), 'enc-').'.xlsx';

        try {
            app(CertificateExcelExporter::class)->satu($sertifikat, $berkas);
            $reader = new Reader;
            $reader->open($berkas);
            $baris = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $baris[] = $row->toArray();
                }
            }

            $reader->close();
        } finally {
            @unlink($berkas);
        }

        $terukur = collect($baris)->filter(fn (array $r): bool => ($r[1] ?? null) === 'Terukur (°C)')->values();
        $this->assertEqualsWithDelta(15.0, $terukur[0][0], self::TOL);
        $this->assertEqualsWithDelta(120.13, $terukur[4][0], self::TOL);
        $this->assertEqualsWithDelta(140.12, $terukur[5][0], self::TOL);
    }

    /**
     * Termokopel bernomor terkecil kosong di SATU set point: kolomnya kosong
     * di baris itu, angka di kanannya tidak bergeser ke posisi yang salah, dan
     * kepala kolom berhenti mengaku "No. 1 Sensor Acuan".
     */
    public function test_termokopel_kosong_di_satu_set_point_tidak_menggeser_kolom(): void
    {
        $sesi = $this->sesi('2405.03.AV');
        $sesi->load('uncertaintyCalculations');
        $sp2 = $sesi->uncertaintyCalculations->sortBy('titik_ke')->values()[1];

        $sp2->type_b_components = array_map(function (array $k): array {
            if (($k['sumber'] ?? null) === 'sebaran_sensor') {
                $k['sensor'] = array_values(array_filter($k['sensor'], fn (array $s): bool => (int) $s['no'] !== 3));
                $k['sensor_acuan'] = 4;
            }

            return $k;
        }, $sp2->type_b_components);

        $sertifikat = Certificate::factory()->create([
            'organization_id' => $sesi->organization_id,
            'calibration_session_id' => $sesi->id,
        ]);
        $snapshot = app(CertificateSnapshotBuilder::class)->bangun($sesi, $sertifikat);
        $enc = $snapshot['enclosure'];

        $this->assertSame(range(3, 11), array_column($enc['posisi'], 'no'), 'Posisi diambil dari gabungan seluruh sesi.');
        $this->assertNull($enc['set_point'][1]['sensor'][0]);
        $this->assertSame(4, $enc['set_point'][1]['sensor'][1]['no']);
        $this->assertEqualsWithDelta(self::MASTER_YOKOGAWA[1]['terukur'][1], $enc['set_point'][1]['sensor'][1]['terukur'], self::TOL);
        $this->assertSame(2, $enc['set_point'][1]['posisi_acuan']);
        $this->assertSame(1, $enc['set_point'][0]['posisi_acuan']);

        $sertifikat->update(['snapshot' => $snapshot]);
        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat->fresh()))->render();

        $this->assertStringContainsString('Sensor Acuan per set point: No. 1 / 2 / 1 / 1', $html);
        $this->assertStringNotContainsString('No. 1 Sensor Acuan', $html);
    }

    /** Recorder: format sel master `0.00` untuk Terukur/Koreksi/Kinerja, `General` untuk Setting. */
    public function test_pdf_recorder_ikut_format_sel_master(): void
    {
        // K-50-15 (pemilik 20 Okt 2026, "ikut master semua"): sertifikat baru
        // bertitik desimal seperti tampilan sel master; desimalnya tidak berubah.
        $sertifikat = $this->terbitkan($this->sesi('2406.25.AI'));
        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();

        // SISTEM_RECORDER_SP1: Terukur pos 1 67,028 → `67,03`; Koreksi −0,332 → `-0,33`;
        // Indikator A (B25 `0.0`) `67,4`; Indikator B (E41 `0.00`) `67,36`;
        // Setting (C41 General) `67`; KS `1,75`; SS 0,0249999… → `0,02`; VK 2,6499999… → `2,65`; U95 `1,5`.
        foreach (['<td>67.03</td>', '<td>-0.33</td>', '<td rowspan="2">67.4</td>', '<td>67.36</td>', '<td>67</td>', '<td>1.75</td>', '<td>0.02</td>', '<td>2.65</td>', '<td rowspan="2">1.5</td>'] as $sel) {
            $this->assertStringContainsString($sel, $html, "Sel {$sel} nggak kecetak.");
        }
    }

    /** Penyimpangan master yang dibetulkan tercatat di jejak sesi (AGENTS.md §Olah data butir 4). */
    public function test_penyimpangan_master_tercatat_di_jejak(): void
    {
        $baris = $this->sesi('2405.03.AV')->uncertaintyCalculations()->orderBy('titik_ke')->get();
        $jejak = fn (int $i, string $sumber): ?array => collect($baris[$i]->type_b_components)->firstWhere('sumber', $sumber);

        // SP3 saja: PERHITUNGAN U95%!O75 merujuk Tabel_Drift (0,035), sistem U kalibrator 0,24.
        $sp3 = $jejak(2, 'master_sp3_sel_kalibrator');
        $this->assertNotNull($sp3);
        $this->assertStringContainsString('PERHITUNGAN U95%!O75', $sp3['keterangan']);
        $this->assertEqualsWithDelta(0.035, $sp3['rincian']['u95_kalibrator_master'], self::TOL);
        $this->assertEqualsWithDelta(0.24, $sp3['rincian']['u95_kalibrator_sistem'], self::TOL);
        $this->assertNull($jejak(0, 'master_sp3_sel_kalibrator'));

        // k: kuantil t eksak, bukan klaim "mengikuti master".
        $this->assertStringContainsString('EKSAK', $jejak(0, 'v_eff_tidak_dipotong')['keterangan']);
        $this->assertNull($jejak(0, 'peta_kolom_pembacaan'));
    }

    /** Jejak sebaran hilang → sertifikat GAGAL terbit, bukan terbit separuh kosong. */
    public function test_jejak_sebaran_hilang_menolak_terbit(): void
    {
        $sesi = $this->sesi('2405.03.AV');
        $sesi->load('uncertaintyCalculations');
        $sp1 = $sesi->uncertaintyCalculations->sortBy('titik_ke')->first();
        $sp1->type_b_components = array_values(array_filter(
            $sp1->type_b_components,
            fn (array $k): bool => ($k['sumber'] ?? null) !== 'sebaran_sensor',
        ));

        $sertifikat = Certificate::factory()->create([
            'organization_id' => $sesi->organization_id,
            'calibration_session_id' => $sesi->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sebaran_sensor');

        app(CertificateSnapshotBuilder::class)->bangun($sesi, $sertifikat);
    }

    /** Alat lain tetap lewat tabel bawaan — blok enclosure `null`. */
    public function test_alat_lain_tidak_kena(): void
    {
        $snapshot = $this->terbitkan($this->sesi('2405.13.A'))->snapshot;

        $this->assertNull($snapshot['enclosure']);
        $this->assertNotEmpty($snapshot['hasil']);
        $this->assertArrayHasKey('correction', $snapshot['hasil'][0]);
    }
}
