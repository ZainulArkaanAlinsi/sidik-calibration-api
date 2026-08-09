<?php

namespace Database\Seeders;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\CalibrationValidator;
use App\Services\CertificateSnapshotBuilder;
use App\Services\DataTampilanSertifikat;
use App\Services\GumCalculator;
use App\Services\KondisiLingkungan;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Satu record kalibrasi ASLI, lengkap, end-to-end — bukan angka contoh kayak
 * `DemoDataSeeder`. Sumbernya sertifikat 012-CAL-524 (PT Tirta Gracia Semesta
 * Mandiri, pH Meter Mettler Toledo Five Easy S/N B628755900), dari
 * `Master Olah Data_pH for trial_CSV/` — workbook `.xlsm` aslinya ter-password
 * protect, jadi nggak bisa dibaca langsung. Dirapikan dulu di
 * `Project-PT-Sidik/Master Olah Data_pH for trial_RAPI/`, dikanonikalkan ke
 * `database/data/kalibrasi-ph-meter.json`.
 *
 * Ketidakpastiannya BENERAN dihitung lewat `GumCalculator::hitungTitik()`
 * (jalur sama yang dipakai `CalibrationController::isiUlangPengukuran()`),
 * bukan angka hasil hitung manual ditempel ke kolom — kalau rumusnya berubah,
 * seeder ini bakal ikut kepengaruh (dan itu memang yang diinginkan: angkanya
 * harus selalu konsisten sama logika app, bukan salinan beku).
 *
 * Nomor sertifikatnya sengaja TETAP 012-CAL-524 (nomor asli di arsip), bukan
 * skema auto CAL/YYYY/MM/NNNN punya `GenerateCertificate` — record ini
 * histori dari sebelum app ini ada, jadi PDF-nya dirender manual di sini
 * (bukan lewat job) tapi pakai view Blade yang persis sama:
 * `resources/views/sertifikat/pdf.blade.php`.
 *
 * WAJIB jalan SETELAH `CalibrationCapabilitySeeder` (butuh kategori
 * `instrumen-analitik`) dan `PhMeterCapabilitySeeder` (butuh CMC pH 4/7/10
 * presisi tinggi supaya `GumCalculator` masuk jalur CMC, bukan jalur generik).
 */
class PhMeterSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;

    public function run(): void
    {
        $data = $this->muatData();

        $customer = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => $data['customer']['nama']],
            ['alamat' => $data['customer']['alamat'], 'organization_id' => 1],
        );

        /** @var Collection<string, Standard> $standarPerSerial */
        $standarPerSerial = collect($data['standards'])->mapWithKeys(
            fn (array $s) => [$s['serial_number'] => Standard::updateOrCreate(
                ['organization_id' => 1, 'serial_number' => $s['serial_number']],
                [
                    'nama' => $s['nama'],
                    'merk' => $s['merk'],
                    'model' => $s['model'] ?? null,
                    'no_sertifikat' => $s['serial_number'],
                    'tertelusur_ke' => $s['tertelusur_ke'],
                    'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], $s['berlaku_sampai']),
                    'ketidakpastian' => $s['ketidakpastian'],
                    'satuan_ketidakpastian' => $s['satuan_ketidakpastian'],
                    'faktor_cakupan' => $s['faktor_cakupan'],
                    // Persamaan suhu dari sertifikat Merck — cuma ada di larutan
                    // buffer, nggak di Termometer & Sensor Std. Tanpa ini nilai
                    // Standard di lembar perhitungan jatuh ke nominal (4,00),
                    // bukan 4,0092252 kayak di lembar olah data aslinya.
                    'koefisien_suhu' => $s['koefisien_suhu'] ?? null,
                ],
            )],
        );

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Instrumen Analitik'))
            ->firstOrFail();

        $e = $data['equipment'];
        $equipment = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => $e['serial_number']],
            [
                'organization_id' => 1,
                'customer_id' => $customer->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $e['nama_alat'],
                'nama_alat_kemampuan' => $e['nama_alat_kemampuan'],
                'merk' => $e['merk'],
                'model' => $e['model'],
                'range_min' => $e['range_min'],
                'range_max' => $e['range_max'],
                'satuan' => $e['satuan'],
                'resolusi' => $e['resolusi'],
                'toleransi' => $e['toleransi'],
                'lokasi' => $e['lokasi'],
                'tanggal_kalibrasi_terakhir' => $e['tanggal_kalibrasi_terakhir'],
                'tanggal_jatuh_tempo' => $e['tanggal_jatuh_tempo'],
                'status' => Equipment::STATUS_AKTIF,
            ],
        );

        $teknisi = $this->buatUser($data['teknisi'], User::ROLE_TEKNISI);
        $penandatangan = $this->buatUser($data['penandatangan'], User::ROLE_ADMIN);

        $sesi = $this->buatSesi($data['sesi'], $equipment, $teknisi, $penandatangan, $standarPerSerial[$data['titik_ukur'][1]['standard_serial_number']]);

        $this->isiTitikUkur($sesi, $data['titik_ukur'], $equipment, $standarPerSerial);

        $this->terbitkanSertifikat($sesi->fresh(['equipment.customer', 'organization', 'uncertaintyCalculations']), $penandatangan, $data['sertifikat']);
    }

    /** @return array<string, mixed> */
    private function muatData(): array
    {
        $path = database_path('data/kalibrasi-ph-meter.json');

        if (! is_file($path)) {
            throw new RuntimeException("File data kalibrasi nggak ketemu di: {$path}");
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param  array{employee_id: string, name: string, department: string, email: string}  $orang */
    private function buatUser(array $orang, string $role): User
    {
        return User::updateOrCreate(
            ['email' => $orang['email']],
            [
                'organization_id' => 1,
                'employee_id' => $orang['employee_id'],
                'name' => $orang['name'],
                'department' => $orang['department'],
                'role' => $role,
                'status' => User::STATUS_AKTIF,
                'password' => 'rahasia123',
            ],
        );
    }

    /**
     * @param  array{nomor_sesi: string, tanggal_kalibrasi: string, lokasi: string, thermohygro_serial: string, suhu_awal: float, suhu_akhir: float, kelembaban_awal: float, kelembaban_akhir: float, submitted_at: string, reviewed_at: string}  $s
     */
    private function buatSesi(array $s, Equipment $equipment, User $teknisi, User $penandatangan, Standard $standarDefault): CalibrationSession
    {
        $thermohygro = Standard::where('organization_id', 1)
            ->where('serial_number', $s['thermohygro_serial'])
            ->first();

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => $s['nomor_sesi']],
            [
                'equipment_id' => $equipment->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standarDefault->id,
                'thermohygro_standard_id' => $thermohygro?->id,
                'reviewed_by' => $penandatangan->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_DISETUJUI,
                'tanggal_kalibrasi' => $s['tanggal_kalibrasi'],
                'lokasi' => $s['lokasi'],
                // Pembacaan thermohygro AWAL & AKHIR apa adanya — `suhu_ruang`
                // & U95-nya sengaja NGGAK ditulis di sini, biar dihitung
                // `KondisiLingkungan` dari koreksi sertifikat TH-3. Pola yang
                // sama dipakai seeder Turbidimeter & Chlorine.
                //
                // Dulu yang keseed langsung `suhu_ruang: 21,4` & `kelembaban:
                // 54,5` — itu rata-rata MENTAH, bukan yang terkoreksi. Jadi
                // satu-satunya alat yang masternya punya blok kondisi
                // lingkungan lengkap malah nerbitin sertifikat tanpa koreksi
                // dan tanpa U95 sama sekali (`T: 21,4°C — %RH: 54,50%`),
                // padahal master nulis `20,97 ± 1,7117` & `51,95 ± 5,6604`.
                'suhu_awal' => $s['suhu_awal'],
                'suhu_akhir' => $s['suhu_akhir'],
                'kelembaban_awal' => $s['kelembaban_awal'],
                'kelembaban_akhir' => $s['kelembaban_akhir'],
                'submitted_at' => $s['submitted_at'],
                'reviewed_at' => $s['reviewed_at'],
            ],
        );

        app(KondisiLingkungan::class)->terapkan($sesi);

        return $sesi->fresh();
    }

    /**
     * Simpen pembacaan mentah + hitung GUM per titik — jalur yang sama kayak
     * `CalibrationController::isiUlangPengukuran()`: satu titik FAIL bikin
     * seluruh sesi FAIL.
     *
     * @param  list<array{titik_ke: int, standard_serial_number: string, titik_ukur: float, pembacaan: list<float>}>  $titikUkur
     * @param  Collection<string, Standard>  $standarPerSerial
     */
    private function isiTitikUkur(CalibrationSession $sesi, array $titikUkur, Equipment $equipment, $standarPerSerial): void
    {
        $sesi->rawMeasurements()->delete();
        $sesi->uncertaintyCalculations()->delete();

        $kalkulator = new GumCalculator;
        $keputusanSesi = 'PASS';

        foreach ($titikUkur as $titik) {
            $standar = $standarPerSerial[$titik['standard_serial_number']];

            foreach ($titik['pembacaan'] as $i => $nilai) {
                RawMeasurement::create([
                    'calibration_session_id' => $sesi->id,
                    'titik_ke' => $titik['titik_ke'],
                    'pembacaan_ke' => $i + 1,
                    'titik_ukur' => $titik['titik_ukur'],
                    'pembacaan' => $nilai,
                    'satuan' => 'pH',
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }

            $hasil = $kalkulator->hitungTitik(
                $titik['titik_ke'],
                $titik['titik_ukur'],
                $titik['pembacaan'],
                $equipment,
                $standar,
            );

            if ($hasil['keputusan'] === 'FAIL') {
                $keputusanSesi = 'FAIL';
            }

            UncertaintyCalculation::create(['calibration_session_id' => $sesi->id, ...$hasil]);
        }

        $sesi->update(['keputusan' => $keputusanSesi]);
    }

    /**
     * Terbitin Certificate + render PDF-nya beneran lewat view
     * `sertifikat.pdf` — persis logika `GenerateCertificate::handle()`,
     * kecuali nomornya TETAP nomor asli (bukan digenerate ulang) supaya
     * qr_token nggak berubah tiap seeder di-re-run (idempoten).
     *
     * @param  array{nomor: string, diterbitkan_pada: string}  $s
     */
    private function terbitkanSertifikat(CalibrationSession $sesi, User $penerbit, array $s): void
    {
        $sertifikat = Certificate::firstOrNew(['organization_id' => 1, 'nomor' => $s['nomor']]);

        if (! $sertifikat->exists) {
            $sertifikat->qr_token = Str::lower(Str::random(10));
        }

        $sertifikat->fill([
            'calibration_session_id' => $sesi->id,
            'issued_by' => $penerbit->id,
            'qr_payload' => url("/verify/{$sertifikat->qr_token}"),
            'diterbitkan_pada' => $s['diterbitkan_pada'],
            'berlaku_sampai' => Carbon::parse($s['diterbitkan_pada'])->addYear(),
            'status' => Certificate::STATUS_MENUNGGU_GENERATE,
        ])->save();

        // Snapshot WAJIB diisi sebelum render. View `sertifikat.pdf` baca
        // `certificates.snapshot` bulat-bulat (lihat docblock di atas view-nya),
        // bukan relasi `sesi`/`titik` — jadi sertifikat tanpa snapshot kecetak
        // dengan tabel hasil KOSONG, dan `CertificateExcelExporter` nolak
        // dengan "belum terbit". Dulu di sini masih ngirim `sesi`+`titik` ke
        // `loadView` ngikut kontrak view yang lama, jadi 012-CAL-524 blank.
        $sertifikat->update([
            'validasi' => app(CalibrationValidator::class)->periksa($sesi),
            'snapshot' => app(CertificateSnapshotBuilder::class)->bangun($sesi, $sertifikat),
        ]);

        // Bahannya dirakit `DataTampilanSertifikat`, sama persis kayak
        // `GenerateCertificate::handle()` — biar lembar hasil seeder nggak bisa
        // beda dari lembar yang keluar lewat alur approve beneran.
        $pdf = Pdf::loadView('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat));

        $path = "certificates/{$sertifikat->qr_token}.pdf";
        Storage::disk('local')->put($path, $pdf->output());

        $sertifikat->update([
            'pdf_path' => $path,
            'status' => Certificate::STATUS_TERBIT,
        ]);
    }
}
