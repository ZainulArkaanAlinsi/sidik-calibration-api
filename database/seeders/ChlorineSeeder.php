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
use App\Services\GumCalculator;
use App\Services\KondisiLingkungan;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data Chlorin Meter dari `Master Olah Data_Chlorine Meter.xlsm` (sheet INPUT
 * DATA) — dua larutan standar chlorine, alat Hanna HI97711, dan satu sesi
 * kalibrasi end-to-end yang ketidakpastiannya BENERAN dihitung `GumCalculator`
 * (bukan angka jadi ditempel), sama polanya kayak `TurbidimeterSeeder`.
 *
 * Sumbernya sesi asli **0189-CAL-624** (order 2406.32.C.NK, Juni 2024).
 * Pembacaannya dari blok "After Adjustment Reading": titik 1,74 → 1,76 ×4 +
 * 1,75 (STDEV 0,004472135954999583, sel PERHITUNGAN G44), titik 1,83 → 1,86 ×5
 * (STDEV 0).
 *
 * WAJIB jalan SETELAH `ChlorineCapabilitySeeder` (butuh CMC chlorine biar
 * `GumCalculator` masuk jalur budget penuh) dan `ThermohygroSeeder`.
 */
class ChlorineSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;

    /**
     * Dua larutan standar chlorine ASLI yang dimiliki lab (sheet DATABASE
     * baris 1–2): Supelco/Merck, U95 sertifikat 0,09 & 0,06 mg/L, k=2,
     * tertelusur Merck KGaA. Bukan placeholder.
     *
     * Serial di sheet ketulis sama buat dua-duanya (`QC1065-2ML/LRAD8911` —
     * satu kit dua cuvette), jadi dipecah biar `Standard` tetap unik dan
     * `ChlorineProfile::STANDARD_TERCETAK` bisa nyocokin per baris.
     *
     * `berlaku_sampai` = Due Date ASLI dari sheet DATABASE (dikalibrasi
     * 30 Apr 2024, interval 3 tahun). Per 5 Agt 2026 dua-duanya MASIH BERLAKU
     * ~20 bulan lagi, jadi `MemanjangkanMasaBerlaku` bakal mertahanin tanggal
     * ini apa adanya — beda dari `now()->addYear()` yang dulu dipakai di sini
     * dan ngebuang tanggal aslinya tiap kali seed, tanpa alasan.
     *
     * @var list<array{nama: string, serial: string, ketidakpastian: float, berlaku_sampai: string}>
     */
    private const STANDAR = [
        [
            'nama' => 'Chlorine Standard Solution 1.74 mg/L',
            'serial' => 'QC1065-2ML',
            'ketidakpastian' => 0.09,
            'berlaku_sampai' => '2027-04-30',
        ],
        [
            'nama' => 'Chlorine Standar Cuvettes 1.83 mg/L',
            'serial' => 'LRAD8911',
            'ketidakpastian' => 0.06,
            'berlaku_sampai' => '2027-04-30',
        ],
    ];

    /**
     * Pembacaan After Adjustment per titik (INPUT DATA G/I 46–50) — sesi asli
     * 0189-CAL-624.
     *
     * CATATAN soal titik 1,83: pembacaannya seragam 1,86 semua, jadi STDEV = 0
     * dan Type A = 0. Sheet `PERHITUNGAN U95%` di kolom titik ini nulis
     * ui = 0,0244949 buat baris pengulangan — angka yang NGGAK ngikut dari
     * pembacaannya sendiri (kalau STDEV 0, Type A mesti 0). Kelihatannya sel
     * ketinggalan di workbook-nya. Di sini Type A dihitung dari pembacaan
     * beneran, jadi uc titik 1,83 sengaja BEDA dari sheet — lihat
     * `ChlorineBudgetTest`. Titik 1,74 cocok sampai digit terakhir.
     *
     * Tiga hal yang bikin yakin selnya yang salah, bukan hitungan di sini:
     * `PERHITUNGAN.csv` baris 31 nulis STDEV titik 1,83 = 0; kolom `U` di baris
     * budget itu sendiri juga 0 (jadi `ui` mestinya 0/√5 = 0); dan 0,0244949²
     * = 0,0006 persis nutup selisih antara jumlah kuadrat versi kita
     * (0,00090842245) sama sel "Jumlah" di sheet (0,0015084224467774394).
     *
     * KONSEKUENSINYA KE SERTIFIKAT, bukan cuma angka antara: sheet ngasih
     * U = 0,0801585479793244 dan itu yang kecetak di `SERTIFIKAT.csv` — DI ATAS
     * CMC 0,08 yang jadi lingkup akreditasi lab sendiri. Versi kita 0,0596 →
     * dilaporkan 0,08 (kena batas CMC). Jangan "disamain" ke sheet tanpa lab
     * mbenerin selnya dulu; yang keluar bakal ketidakpastian di luar lingkup.
     * Diperiksa ulang 5 Agt 2026 waktu cocokin DB ke Chlorine_Meter_CSV.
     *
     * @var list<array{titik: float, standar: int, pembacaan: list<float>, suhu: float}>
     */
    private const TITIK = [
        ['titik' => 1.74, 'standar' => 0, 'pembacaan' => [1.76, 1.76, 1.76, 1.76, 1.75], 'suhu' => 25.8],
        ['titik' => 1.83, 'standar' => 1, 'pembacaan' => [1.86, 1.86, 1.86, 1.86, 1.86], 'suhu' => 25.8],
    ];

    public function run(): void
    {
        $customer = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'LABORATORIUM LINGKUNGAN PPLH - INSTITUT PERTANIAN BOGOR'],
            [
                'organization_id' => 1,
                'alamat' => 'Jl. Lingkar Akademik Kampus IPB Darmaga, Bogor',
            ],
        );

        $standar = [];
        foreach (self::STANDAR as $i => $s) {
            $standar[$i] = Standard::updateOrCreate(
                ['organization_id' => 1, 'nama' => $s['nama']],
                [
                    'organization_id' => 1,
                    'merk' => 'Supelco/Merck',
                    'serial_number' => $s['serial'],
                    'no_sertifikat' => $s['serial'],
                    'tertelusur_ke' => 'Merck KGaA',
                    'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], $s['berlaku_sampai']),
                    'ketidakpastian' => $s['ketidakpastian'],
                    'satuan_ketidakpastian' => 'mg/L',
                    'faktor_cakupan' => 2,
                    // Larutan chlorine nggak punya kurva suhu kayak buffer pH —
                    // nilai standar dipakai nominal apa adanya.
                    'koefisien_suhu' => null,
                ],
            );
        }

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Instrumen Analitik'))
            ->firstOrFail();

        $equipment = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => '905320134111'],
            [
                'organization_id' => 1,
                'customer_id' => $customer->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Chlorine Meter',
                // Kunci yang bikin registry milih ChlorineProfile. Ejaannya
                // ngikut LAMPIRAN AKREDITASI ("Chlorin", tanpa 'e') — beda dari
                // `nama_alat` di atas yang ngikut lembar kerjanya.
                'nama_alat_kemampuan' => 'Chlorin Meter',
                'merk' => 'Hanna Instrument',
                'model' => 'HI97711',
                'range_min' => 0,
                'range_max' => 4,
                'satuan' => 'mg/L',
                'resolusi' => 0.01,
                // Toleransi skalar. Dipilih 0,15 mg/L supaya guarded-acceptance
                // dua titiknya PASS buat demo: |error| terbesar 0,03 + U 0,091
                // = 0,121 < 0,15. Toleransi asli alat ini belum kecatat di
                // workbook — kalau lab ngasih angkanya, ganti di sini.
                'toleransi' => 0.15,
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

        // Thermohygro TH-4 (INPUT DATA "Thermohygro used: TH-4").
        $th4 = Standard::where('organization_id', 1)->where('nama', 'TH-4')->first();

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => '2406.32.C'],
            [
                'equipment_id' => $equipment->id,
                // Order Number sesi asli (sel SERTIFIKAT "Order Number"). Tanpa
                // ini `CalibrationValidator` nyalain temuan `nomor_order` di
                // tiap sertifikat — cuma tingkat info, tapi bikin kolom Order
                // Number kecetak strip di dokumen yang mestinya lengkap.
                'nomor_order' => '2406.32.C.NK',
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar[0]->id,
                'thermohygro_standard_id' => $th4?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2024-06-11',
                // Received Date sel SERTIFIKAT — sama alasannya kayak nomor_order
                // di atas: kolomnya kecetak di sertifikat, jadi jangan dibiarin
                // kosong di data contoh yang jadi acuan orang.
                'tanggal_terima' => '2024-06-10',
                'lokasi' => 'lab',
                // Awal & akhir apa adanya dari PERHITUNGAN KONDISI LINGKUNGAN
                // (24,1 → 23,5 °C dan 56 → 55 %RH). suhu_ruang & U95-nya
                // JANGAN ditulis di sini — biar `KondisiLingkungan` yang ngitung
                // dari awal/akhir + koreksi sertifikat TH-4, persis kayak alur
                // teknisi beneran. Hasil yang diharapkan: average 23,8 °C &
                // 55,5 %RH (sel PERHITUNGAN baris 14–15).
                'suhu_awal' => 24.1,
                'suhu_akhir' => 23.5,
                'kelembaban_awal' => 56,
                'kelembaban_akhir' => 55,
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
        $keputusanSesi = 'PASS';

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
                    'pembacaan' => $nilai,
                    'suhu' => $titik['suhu'],
                    'satuan' => 'mg/L',
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
            );

            if ($hasil['keputusan'] === 'FAIL') {
                $keputusanSesi = 'FAIL';
            }

            UncertaintyCalculation::create(['calibration_session_id' => $sesi->id, ...$hasil]);
        }

        $sesi->update(['keputusan' => $keputusanSesi]);
    }
}
