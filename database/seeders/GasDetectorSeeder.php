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
use App\Services\Calibration\Profiles\GasDetectorProfile;
use App\Services\GumCalculator;
use App\Services\KondisiLingkungan;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data Multi Gas Detector dari `Gas Detector Uli Skin (std Rigaz).xlsm` —
 * empat botol gas standar Rigas, alat Honeywell Microclip XL, dan satu sesi
 * kalibrasi end-to-end yang ketidakpastiannya BENERAN dihitung `GumCalculator`
 * (bukan angka jadi ditempel), sama polanya kayak `DoMeterSeeder`.
 *
 * Sumbernya sesi asli **001-CAL-226** (order 2602.03.A.NK, PT Unilever
 * Indonesia Skin Care Factory, 2 Februari 2026). Pembacaan dari blok "After
 * Adjustment Reading" (`INPUT DATA` G45:M47) — yang sama persis dengan blok
 * Before di sesi ini, karena alatnya nggak perlu di-adjust.
 *
 * WAJIB jalan SETELAH `GasDetectorCapabilitySeeder` (butuh baris kemampuan biar
 * `GumCalculator` masuk jalur budget penuh) dan `ThermohygroSeeder` (butuh
 * Thermobarometer Lutron — satu-satunya alat kondisi lingkungan lab yang punya
 * kalibrasi TEKANAN, dan tanpa tekanan dua dari lima komponen budget nggak bisa
 * disusun).
 */
class GasDetectorSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Empat botol campuran gas Rigas dari sheet `DATABASE` (Q13:Y16). S/N-nya
     * sama semua (`WO0125576`) — satu order pengisian, empat campuran.
     *
     * `ketidakpastian` = 5 % dari konsentrasi nominal (`V11 = 5%`, dipakai
     * sebagai pengali di V13:V16), k=2. Disimpan sebagai ANGKA MUTLAK di satuan
     * masing-masing, bukan persen — itu bentuk yang dibaca
     * `GasDetectorProfile::komponenBudget()` lewat `standards.ketidakpastian`,
     * dan bentuk yang sama dipakai delapan alat lain.
     *
     * `berlaku_sampai` = Due Date asli dari `DATABASE!Y13:Y16`.
     *
     * @var list<array{nama: string, komposisi: string, satuan: string, nominal: float, ketidakpastian: float}>
     */
    private const STANDAR = [
        [
            'nama' => 'Standar Gas Mixture (CO)',
            'komposisi' => 'Carbon Monoxide (CO) 101 Ppm',
            'satuan' => 'ppm',
            'nominal' => 101.0,
            'ketidakpastian' => 5.05,
        ],
        [
            'nama' => 'Standar Gas Mixture (H₂S)',
            'komposisi' => 'Hydrogen Sulfide (H₂S) 25 Ppm',
            'satuan' => 'ppm',
            'nominal' => 25.0,
            'ketidakpastian' => 1.25,
        ],
        [
            'nama' => 'Standar Gas Mixture (CH4)',
            'komposisi' => 'Methane (CH4) 2.5%(50%LEL)',
            'satuan' => '%LEL',
            'nominal' => 50.0,
            'ketidakpastian' => 2.5,
        ],
        [
            'nama' => 'Standar Gas Mixture (O2)',
            'komposisi' => 'Oxygen (O2) 17.9%',
            'satuan' => '%',
            'nominal' => 17.9,
            'ketidakpastian' => 0.895,
        ],
    ];

    /**
     * Empat titik = empat gas, TIGA pengulangan masing-masing.
     *
     * Nggak ada kolom suhu per pembacaan — master gas detector memang nggak
     * punya, dan wajar: yang masuk budget suhu RUANGAN, bukan suhu media yang
     * diukur seperti larutan buffer pH.
     *
     * @var list<array{titik: float, standar: int, satuan: string, pembacaan: list<float>}>
     */
    private const TITIK = [
        ['titik' => 101.0, 'standar' => 0, 'satuan' => 'ppm', 'pembacaan' => [100.0, 100.0, 101.0]],
        ['titik' => 25.0, 'standar' => 1, 'satuan' => 'ppm', 'pembacaan' => [24.0, 24.0, 23.0]],
        ['titik' => 50.0, 'standar' => 2, 'satuan' => '%LEL', 'pembacaan' => [50.0, 49.0, 50.0]],
        ['titik' => 17.9, 'standar' => 3, 'satuan' => '%', 'pembacaan' => [16.7, 16.8, 16.7]],
    ];

    /** Kondisi lingkungan sesi, `INPUT DATA` E26:F28. */
    private const SUHU_AWAL = 22.8;

    private const SUHU_AKHIR = 22.9;

    private const KELEMBABAN_AWAL = 53.0;

    private const KELEMBABAN_AKHIR = 56.0;

    private const TEKANAN_AWAL = 923.5;

    private const TEKANAN_AKHIR = 922.8;

    public function run(): void
    {
        $customer = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PT UNILEVER INDONESIA (SKIN CARE FACTORY)'],
            [
                'organization_id' => 1,
                'alamat' => 'Jl. Jababeka V blok U no.14-16, Cikarang, Bekasi',
            ],
        );

        $standar = [];
        foreach (self::STANDAR as $i => $s) {
            $standar[$i] = Standard::updateOrCreate(
                ['organization_id' => 1, 'nama' => $s['nama']],
                [
                    'organization_id' => 1,
                    'merk' => 'Rigas',
                    // Kolom "Merk/Type" di master diisi KOMPOSISI gasnya — itu
                    // yang tercetak di kolom "Gas Composition" sertifikat
                    // (`SERTIFIKAT!I37`), jadi disimpen apa adanya.
                    'model' => $s['komposisi'],
                    'serial_number' => 'WO0125576',
                    'no_sertifikat' => 'WO0125576',
                    'tertelusur_ke' => 'Rigas',
                    'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], '2028-10-16'),
                    'ketidakpastian' => $s['ketidakpastian'],
                    'satuan_ketidakpastian' => $s['satuan'],
                    'faktor_cakupan' => 2,
                    // Campuran gas dibaca nominal apa adanya — nggak ada kurva
                    // suhu (lihat GasDetectorProfile::standarBerkurvaSuhu).
                    'koefisien_suhu' => null,
                ],
            );
        }

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Instrumen Analitik'))
            ->firstOrFail();

        $equipment = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => 'KA419-1086675'],
            [
                'organization_id' => 1,
                'customer_id' => $customer->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Multi Gas Detector',
                'nama_alat_kemampuan' => 'Gas Detector',
                'merk' => 'Honeywell',
                'model' => 'Microclip XL',
                // Rentang & resolusi di kolom ini yang PALING BESAR (CO), karena
                // kolomnya cuma muat satu. Rentang & resolusi per gas yang benar
                // ada di `GasDetectorProfile::GAS` dan dipakai lewat
                // `resolusiTitik()`/`satuanTitik()`.
                'range_min' => 0,
                'range_max' => 1999,
                'satuan' => 'ppm',
                'resolusi' => 1,
                // NULL, bukan 0: master Gas Detector nggak punya batas
                // keberterimaan sama sekali dan sertifikatnya berhenti di kolom
                // U95% — `GasDetectorProfile::punyaToleransi()` = false. 0 di
                // kolom ini kebaca sebagai "batasnya nol ppm", pernyataan yang
                // salah di jejak audit.
                'toleransi' => null,
                'lokasi' => 'Lab PT. Sidik',
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

        $barometer = Standard::where('organization_id', 1)
            ->where('nama', 'Thermobarometer Lutron')
            ->first();

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => '2602.03.A'],
            [
                'equipment_id' => $equipment->id,
                'nomor_order' => '2602.03.A.NK',
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar[0]->id,
                'thermohygro_standard_id' => $barometer?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2026-02-02',
                'tanggal_terima' => '2026-02-02',
                'lokasi' => 'lab',
                // Awal & akhir apa adanya dari INPUT DATA. Nilai terkoreksi &
                // U95-nya JANGAN ditulis — biar `KondisiLingkungan` yang ngitung
                // dari awal/akhir + koreksi sertifikat barometer, persis kayak
                // alur teknisi beneran.
                'suhu_awal' => self::SUHU_AWAL,
                'suhu_akhir' => self::SUHU_AKHIR,
                'kelembaban_awal' => self::KELEMBABAN_AWAL,
                'kelembaban_akhir' => self::KELEMBABAN_AKHIR,
                'tekanan_awal' => self::TEKANAN_AWAL,
                'tekanan_akhir' => self::TEKANAN_AKHIR,
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

        $kalkulator = new GumCalculator;
        $versiRumus = $this->versiRumusUntuk($sesi);

        // Δ kondisi lingkungan — inilah yang jadi `u` komponen suhu & tekanan
        // di budget Gas Detector. Dihitung dengan rumus yang sama persis kayak
        // `CalibrationController::deltaKondisi()`, dari angka yang sama.
        $konteks = [
            'delta_suhu' => abs(self::SUHU_AKHIR - self::SUHU_AWAL),
            'delta_tekanan' => abs(self::TEKANAN_AKHIR - self::TEKANAN_AWAL),
        ];

        $suhuRuang = (self::SUHU_AWAL + self::SUHU_AKHIR) / 2;

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
                    // Satuan per BARIS — empat gas, empat satuan. Kolom ini
                    // sudah ada dari alat pertama, cuma baru sekarang beneran
                    // beda-beda dalam satu sesi.
                    'satuan' => $titik['satuan'],
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }

            $hasil = $kalkulator->hitungTitik(
                $titikKe,
                $titik['titik'],
                $titik['pembacaan'],
                $equipment,
                $std,
                null,
                $suhuRuang,
                $konteks,
            );

            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                'formula_version_id' => $versiRumus,
                ...$hasil,
            ]);
        }

        // Gas Detector nggak divonis (punyaToleransi=false) — keputusan sesi
        // null. Lihat GasDetectorProfile.
        $sesi->update(['keputusan' => null]);
    }

    /**
     * Dipakai test buat mastiin daftar gas di profil & botol yang diseed
     * nggak pernah geser sendiri-sendiri.
     *
     * @return list<array{nama: string, komposisi: string, satuan: string, nominal: float, ketidakpastian: float}>
     */
    public static function standar(): array
    {
        return self::STANDAR;
    }

    /**
     * Nilai nominal botol di sini WAJIB sama dengan `GasDetectorProfile::GAS`
     * — profil mencocokkan titik ke gas lewat angka itu, jadi kalau keduanya
     * geser sendiri-sendiri satuan sertifikat ikut geser tanpa ada yang error.
     *
     * @return list<float>
     */
    public static function nominalProfil(): array
    {
        return array_map(fn (array $g): float => $g['nilai'], GasDetectorProfile::GAS);
    }
}
