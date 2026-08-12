<?php

namespace Database\Seeders;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use App\Services\KondisiLingkungan;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data Visible Spectrophotometer dari `Master Olah Data_Spectrofotometer.xlsm`
 * — tiga filter standar PG.Instrument, alat Perkin Elmer Lambda 25 milik PT LDC
 * Indonesia, dan satu sesi kalibrasi end-to-end yang U95%-nya BENERAN dihitung
 * `SpectrophotometerProfile::hitungPerGrup()`, bukan angka jadi ditempel.
 *
 * Pembacaannya dari `INPUT DATA` baris 35-44 (Holmium), 49-57 (Didynium), dan
 * 66-75 (%T pada λ 560 nm).
 *
 * Angka yang harus keluar, dicek `SpectrophotometerBudgetTest` lawan sel master:
 *
 *   kelompok    STDEV maks            Uc                    k          U95%                  sertifikat
 *   Holmium     0,15588457268125408   0,13591967799557511   3,182447   0,43255707705236945   0,43255707705236945
 *   Didynium    0,15011106998929746   0,15828510160732645   2,364624   0,3742847899265122    0,4  ← lantai CMC
 *   %T          0,00409878030638399   0,02891741113354837   2,008559   0,058082329630652574  0,5  ← lantai CMC
 *
 * ## Nomor sesinya SENGAJA bukan nomor job
 *
 * `INPUT DATA!M5` (Certificate Number) dan `M6` (Order Number) di master
 * **kosong** — workbook-nya lembar kerja trial, bukan job yang pernah terbit.
 * Ngarang nomor job bikin orang ngira ada pekerjaan lab yang nggak pernah ada,
 * jadi dipakai penanda demo yang jelas. Angka kalibrasinya sendiri asli.
 *
 * WAJIB jalan SETELAH `SpectrophotometerCapabilitySeeder` (butuh CMC buat
 * lantai U95 sertifikat) dan `ThermohygroSeeder` (TH-2).
 */
class SpectrophotometerSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Tiga filter standar (sheet `DATABASE` R13:Z15 + `STANDAR_KALIBRATOR` M5 /
     * M18 / M29). Semua satu sertifikat, kalibrasi 15 Apr 2025, interval 2
     * tahun, jatuh tempo 15 Apr 2027, tertelusur BSN-SNSU.
     *
     * `ketidakpastian` = U95% sertifikat filternya, k=2. Itu yang dipakai DUA
     * komponen budget sekaligus: "Ketidakpastian gelas filter" (U/k) dan "Drift
     * standar" (U·0,1/√3).
     *
     * @var list<array{nama: string, merk: string, serial: string, ketidakpastian: float, satuan: string}>
     */
    private const STANDAR = [
        [
            'nama' => 'Filter Standard 1',
            'merk' => 'PG.Instrument/Holmium Oxide',
            'serial' => 'SPG080982.A',
            'ketidakpastian' => 0.1,
            'satuan' => 'nm',
        ],
        [
            'nama' => 'Filter Standard 2',
            'merk' => 'PG.Instrument/Didynium',
            'serial' => 'SPG080982.B',
            'ketidakpastian' => 0.2,
            'satuan' => 'nm',
        ],
        [
            'nama' => 'Filter Standard 3',
            'merk' => 'PG.Instrument/Filter1,2,3',
            'serial' => 'SPG080982.C',
            'ketidakpastian' => 0.5,
            'satuan' => '%T',
        ],
    ];

    /**
     * `standards` nggak punya kolom tanggal kalibrasi — cuma `berlaku_sampai`.
     * Tanggal kalibrasi standarnya (15 Apr 2025, `STANDAR_KALIBRATOR!J2`)
     * dicatat di sini biar interval 2 tahunnya kebaca dari mana asalnya.
     */
    private const BERLAKU_SAMPAI_STANDAR = '2027-04-15';

    /**
     * Titik ukur sesi contoh, urut persis kayak lembarnya: 10 Holmium, 9
     * Didynium, 5 %T. `titik_ke` = urutan di daftar ini.
     *
     * Titik %T punya ENAM pembacaan — master nyetak dua baris X1..X3 per nilai
     * standar dan ngerata-ratain keenamnya (`PERHITUNGAN!F47 = SQRT(6)`).
     * Nilai barisnya emang kembar; itu apa adanya dari workbook, bukan salah
     * salin.
     *
     * Kolom suhu larutan sengaja NGGAK ada: filter kaca dibaca nominal, master
     * spektro nggak punya satu pun sel koreksi suhu buat nilai standarnya.
     *
     * @var list<array{titik: float, standar: int, satuan: string, pembacaan: list<float>}>
     */
    private const TITIK = [
        // A. Filter Holmium — INPUT DATA G35:J44
        ['titik' => 279.6, 'standar' => 0, 'satuan' => 'nm', 'pembacaan' => [280, 280, 280]],
        ['titik' => 287.7, 'standar' => 0, 'satuan' => 'nm', 'pembacaan' => [288, 288, 288]],
        ['titik' => 334.0, 'standar' => 0, 'satuan' => 'nm', 'pembacaan' => [333.74, 333.74, 333.74]],
        ['titik' => 360.9, 'standar' => 0, 'satuan' => 'nm', 'pembacaan' => [360.35, 360.59, 360.6]],
        ['titik' => 418.6, 'standar' => 0, 'satuan' => 'nm', 'pembacaan' => [418.6, 418.34, 418.34]],
        ['titik' => 445.8, 'standar' => 0, 'satuan' => 'nm', 'pembacaan' => [445.44, 445.69, 445.69]],
        ['titik' => 453.6, 'standar' => 0, 'satuan' => 'nm', 'pembacaan' => [453.55, 453.29, 453.29]],
        ['titik' => 460.0, 'standar' => 0, 'satuan' => 'nm', 'pembacaan' => [459.13, 459.37, 459.37]],
        ['titik' => 536.3, 'standar' => 0, 'satuan' => 'nm', 'pembacaan' => [536.63, 536.37, 536.37]],
        ['titik' => 637.9, 'standar' => 0, 'satuan' => 'nm', 'pembacaan' => [637.45, 637.18, 637.18]],

        // B. Filter Didynium — INPUT DATA G49:J57
        ['titik' => 475.2, 'standar' => 1, 'satuan' => 'nm', 'pembacaan' => [477.86, 477.93, 477.93]],
        ['titik' => 513.7, 'standar' => 1, 'satuan' => 'nm', 'pembacaan' => [513.32, 513.58, 513.58]],
        ['titik' => 529.7, 'standar' => 1, 'satuan' => 'nm', 'pembacaan' => [529.53, 529.28, 529.28]],
        ['titik' => 572.7, 'standar' => 1, 'satuan' => 'nm', 'pembacaan' => [572.6, 572.34, 572.34]],
        ['titik' => 585.7, 'standar' => 1, 'satuan' => 'nm', 'pembacaan' => [585.28, 585.51, 585.51]],
        ['titik' => 684.9, 'standar' => 1, 'satuan' => 'nm', 'pembacaan' => [684.55, 684.3, 684.3]],
        ['titik' => 738.5, 'standar' => 1, 'satuan' => 'nm', 'pembacaan' => [738.63, 738.52, 738.52]],
        ['titik' => 748.0, 'standar' => 1, 'satuan' => 'nm', 'pembacaan' => [747.87, 747.79, 747.78]],
        ['titik' => 806.1, 'standar' => 1, 'satuan' => 'nm', 'pembacaan' => [806.49, 806.35, 806.35]],

        // C. Accuracy %T @ λ 560 nm — INPUT DATA G66:J75
        ['titik' => 0.0, 'standar' => 2, 'satuan' => '%T', 'pembacaan' => [0.001, 0.001, 0, 0.001, 0.001, 0]],
        ['titik' => 9.9, 'standar' => 2, 'satuan' => '%T', 'pembacaan' => [9.668, 9.661, 9.666, 9.668, 9.661, 9.666]],
        ['titik' => 20.0, 'standar' => 2, 'satuan' => '%T', 'pembacaan' => [19.531, 19.525, 19.534, 19.531, 19.525, 19.534]],
        ['titik' => 30.1, 'standar' => 2, 'satuan' => '%T', 'pembacaan' => [29.25, 29.249, 29.249, 29.25, 29.249, 29.249]],
        ['titik' => 100.0, 'standar' => 2, 'satuan' => '%T', 'pembacaan' => [100.004, 100.003, 100.001, 100.004, 100.003, 100.001]],
    ];

    public function run(): void
    {
        $customer = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PT LDC INDONESIA'],
            [
                'organization_id' => 1,
                'alamat' => 'Jl. Soekarno Hatta KM 10, LK II, RT 023, Way Lunik, Panjang, '
                    .'Kota Bandar Lampung',
            ],
        );

        $standar = [];
        foreach (self::STANDAR as $i => $s) {
            $standar[$i] = Standard::updateOrCreate(
                ['organization_id' => 1, 'nama' => $s['nama']],
                [
                    'organization_id' => 1,
                    'merk' => $s['merk'],
                    'serial_number' => $s['serial'],
                    'no_sertifikat' => $s['serial'],
                    'tertelusur_ke' => 'BSN-SNSU',
                    'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], self::BERLAKU_SAMPAI_STANDAR),
                    'ketidakpastian' => $s['ketidakpastian'],
                    'satuan_ketidakpastian' => $s['satuan'],
                    'faktor_cakupan' => 2,
                    // Filter kaca dibaca nominal — nggak ada kurva suhu.
                    'koefisien_suhu' => null,
                ],
            );
        }

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Instrumen Analitik'))
            ->firstOrFail();

        $equipment = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => '501S13102801'],
            [
                'organization_id' => 1,
                'customer_id' => $customer->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Visible Spectrofotometer',
                // Kunci yang bikin registry milih SpectrophotometerProfile.
                'nama_alat_kemampuan' => 'Spectrophotometer',
                'merk' => 'Perkin Elmer',
                'model' => 'Lambda 25',
                // INPUT DATA E14/G14: alat ini punya DUA rentang — 0-100 %T dan
                // 200-700 nm. Kolom range_min/max cuma muat satu, jadi diisi
                // rentang panjang gelombang (yang nutup 19 dari 24 titik);
                // rentang %T-nya kebaca dari `resolusi_rentang` di bawah.
                'range_min' => 200,
                'range_max' => 700,
                'satuan' => 'nm',
                // Fallback kalau `resolusi_rentang` kosong.
                'resolusi' => 0.01,
                // INPUT DATA E16 (0,001 %T) & G16 (0,01 nm) — dua kotak resolusi
                // dengan satuannya masing-masing. Kuncinya `titik`, bukan `maks`:
                // titik %T (0-100) secara angka ada DI DALAM rentang nm
                // (200-700), jadi ambang numerik nggak bisa misahin keduanya.
                'resolusi_rentang' => array_map(
                    static fn (array $t): array => [
                        'titik' => $t['titik'],
                        'resolusi' => $t['satuan'] === '%T' ? 0.001 : 0.01,
                        'satuan' => $t['satuan'],
                    ],
                    self::TITIK,
                ),
                // SENGAJA null. Master nggak punya satu pun sel yang mbandingin
                // hasil sama batas keberterimaan, dan sertifikatnya nggak nyetak
                // vonis PASS/FAIL. Lihat SpectrophotometerProfile::punyaToleransi().
                'toleransi' => null,
                'lokasi' => 'Insitu (PT. LDC)',
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $teknisi = User::where('organization_id', 1)
            ->where('role', User::ROLE_TEKNISI)
            ->where('status', User::STATUS_AKTIF)
            ->first();

        if ($teknisi === null) {
            return; // user demo belum diseed — cukup master data-nya aja
        }

        $th2 = Standard::where('organization_id', 1)->where('nama', 'TH-2')->first();

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => 'DEMO-SPECTRO-LDC'],
            [
                'equipment_id' => $equipment->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar[0]->id,
                'thermohygro_standard_id' => $th2?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2023-07-21',
                'lokasi' => 'onsite',
                // Pembacaan thermohygro awal & akhir apa adanya (INPUT DATA
                // E21/F21 & E22/F22). suhu_ruang & U95-nya JANGAN ditulis di
                // sini — `KondisiLingkungan` yang ngitung dari awal/akhir +
                // koreksi sertifikat TH-2, dan hasilnya harus 21,61 °C / 56,5
                // %RH persis kayak PERHITUNGAN!G14+N14 & G15+N15.
                'suhu_awal' => 22.0,
                'suhu_akhir' => 22.0,
                'kelembaban_awal' => 57.0,
                'kelembaban_akhir' => 58.0,
                'submitted_at' => now(),
            ],
        );

        app(KondisiLingkungan::class)->terapkan($sesi);

        $this->isiTitikUkur($sesi, $equipment, $standar);
    }

    /**
     * @param  array<int, Standard>  $standar
     */
    private function isiTitikUkur(CalibrationSession $sesi, Equipment $equipment, array $standar): void
    {
        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $profil = new SpectrophotometerProfile;
        $versiRumus = $this->versiRumusUntuk($sesi);

        $siapHitung = [];

        foreach (self::TITIK as $index => $titik) {
            $std = $standar[$titik['standar']];
            $titikKe = $index + 1;

            foreach ($titik['pembacaan'] as $i => $nilai) {
                RawMeasurement::create([
                    'calibration_session_id' => $sesi->id,
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => $i + 1,
                    'tahap' => 'sesudah_adjustment',
                    'titik_ukur' => $titik['titik'],
                    'standard_id' => $std->id,
                    'pembacaan' => $nilai,
                    'satuan' => $titik['satuan'],
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }

            $siapHitung[] = [
                'titik_ke' => $titikKe,
                'titik_ukur' => $titik['titik'],
                'pembacaan' => $titik['pembacaan'],
                'standard' => $std,
            ];
        }

        // SEKALI buat semua titik, bukan per titik — U95 spektro lahir per
        // kelompok dari STDEV terbesar kelompok itu. Ini jalur yang sama persis
        // yang dipakai `CalibrationController::susunPengukuran()`.
        $hasil = $profil->hitungPerGrup($siapHitung, $equipment);

        foreach ($hasil['hitungan'] as $baris) {
            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                'formula_version_id' => $versiRumus,
                ...$baris,
            ]);
        }

        foreach ($hasil['belum_dihitung'] as $lewat) {
            $this->command?->warn(sprintf(
                '  [spectro] titik ke-%d nggak kehitung: %s',
                $lewat['titik_ke'],
                $lewat['alasan'],
            ));
        }
    }
}
