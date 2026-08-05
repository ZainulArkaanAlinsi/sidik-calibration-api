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
use Barryvdh\DomPDF\Facade\Pdf;
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
                    'berlaku_sampai' => $this->berlakuSampai($s),
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
    /**
     * Masa berlaku sertifikat kalibrator, DIPANJANGIN kalau udah (mau) lewat.
     *
     * ## Kenapa perlu
     *
     * Backend nolak `422` kalau standar acuannya kadaluarsa — dan itu BENER,
     * ketertelusurannya putus. Tapi tanggal di `kalibrasi-ph-meter.json` itu
     * tanggal sertifikat ASLI, dan sertifikat punya masa berlaku: per 5 Agt
     * 2026 pH Buffer 4 udah lewat (31 Jul 2026) dan Termometer & Sensor Std.
     * tinggal seminggu (12 Agt 2026). Jadi seeder ini BASI SENDIRI LEWAT WAKTU:
     * rantai pH 3 titik berhenti di dev tanpa ada yang ngubah kode apa pun.
     *
     * `TurbidimeterSeeder` & `ChlorineSeeder` udah lama nangani ini dengan
     * `now()->addYear()`. Yang pH kelewat, makanya dia yang mati duluan.
     *
     * ## Yang TIDAK diubah
     *
     * Cuma masa berlakunya. **Angka U95, faktor cakupan, koefisien suhu, nomor
     * sertifikat, dan tertelusur-ke tetap yang asli** — itu yang masuk ke
     * perhitungan & kecetak di sertifikat. JSON-nya juga sengaja nggak disentuh:
     * dia tetap catatan tanggal sebenarnya.
     *
     * Perpanjangannya diumumin ke keluaran seeder, bukan diam-diam — data demo
     * yang nyamar jadi data valid itu persis cara orang salah percaya.
     *
     * Ambangnya 60 hari, bukan "udah lewat": kalau pas-pasan, seeder yang hari
     * ini hijau bakal merah minggu depan, dan yang kena orang lain.
     */
    private function berlakuSampai(array $s): Carbon
    {
        $asli = Carbon::parse($s['berlaku_sampai']);

        if ($asli->greaterThan(now()->addDays(60))) {
            return $asli;
        }

        $baru = now()->addYear();
        $this->command?->warn(sprintf(
            '  [demo] "%s" masa berlakunya %s (%s) -> dipanjangin ke %s. '
            .'U95 & data sertifikat lainnya TETAP asli.',
            $s['nama'],
            $asli->toDateString(),
            $asli->isPast() ? 'udah lewat' : 'tinggal '.(int) now()->diffInDays($asli).' hari',
            $baru->toDateString(),
        ));

        return $baru;
    }

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

    /** @param  array{nomor_sesi: string, tanggal_kalibrasi: string, lokasi: string, suhu_ruang: float, kelembaban: float, submitted_at: string, reviewed_at: string}  $s */
    private function buatSesi(array $s, Equipment $equipment, User $teknisi, User $penandatangan, Standard $standarDefault): CalibrationSession
    {
        return CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => $s['nomor_sesi']],
            [
                'equipment_id' => $equipment->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standarDefault->id,
                'reviewed_by' => $penandatangan->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_DISETUJUI,
                'tanggal_kalibrasi' => $s['tanggal_kalibrasi'],
                'lokasi' => $s['lokasi'],
                'suhu_ruang' => $s['suhu_ruang'],
                'kelembaban' => $s['kelembaban'],
                'submitted_at' => $s['submitted_at'],
                'reviewed_at' => $s['reviewed_at'],
            ],
        );
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
