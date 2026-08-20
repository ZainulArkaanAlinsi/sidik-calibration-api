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
use Database\Seeders\Concerns\MenstempelVersiRumus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data DO Meter dari `Master Olah Data_DO Meter.xlsm` (sheet INPUT DATA) — satu
 * larutan oksigen standar 8,77 mg/L, alat Mettler Toledo Five Go, dan satu sesi
 * kalibrasi end-to-end yang ketidakpastiannya BENERAN dihitung `GumCalculator`
 * (bukan angka jadi ditempel), sama polanya kayak `ChlorineSeeder`.
 *
 * Sumbernya sesi asli **0566-CAL-624** (order 2406.50.S.NK, LPKL PDAM
 * Tirtawening, Juni 2024). Pembacaan dari blok "After Adjustment Reading":
 * 8,82 ×3 + 8,83 ×2 (STDEV 0,005477225575051544, sel PERHITUNGAN G44), suhu
 * larutan 23,7 °C.
 *
 * WAJIB jalan SETELAH `DoMeterCapabilitySeeder` (butuh CMC + u_temperature biar
 * `GumCalculator` masuk jalur budget penuh) dan `ThermohygroSeeder`.
 */
class DoMeterSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Dua larutan oksigen standar dari sheet DATABASE (baris 1 & 7): Supelco/
     * Merck, U95 sertifikat 0,15 & 0,08 mg/L, k=2, tertelusur Merck KGaA.
     * Dua-duanya kecetak di "Standard used" sertifikat. Yang jadi TITIK cuma
     * 8,77 (larutan 5,51 dibawa tapi nggak dipakai ngukur di sesi ini).
     *
     * `berlaku_sampai` = Due Date ASLI dari DATABASE (`Y13`/`Y19`).
     *
     * @var list<array{nama: string, serial: string, ketidakpastian: float, berlaku_sampai: string}>
     */
    private const STANDAR = [
        [
            'nama' => 'Oxygen Standard Solution 8.77 mg/L',
            'serial' => 'LRAD6897',
            'ketidakpastian' => 0.15,
            'berlaku_sampai' => '2025-09-30',
        ],
        [
            'nama' => 'Oxygen Standard Solution 5.51 mg/L',
            'serial' => 'LRAD9311',
            'ketidakpastian' => 0.08,
            'berlaku_sampai' => '2026-06-28',
        ],
    ];

    /**
     * Titik 8,77 — pembacaan After Adjustment (INPUT DATA G43–G47).
     *
     * @var list<array{titik: float, standar: int, pembacaan: list<float>, suhu: float}>
     */
    private const TITIK = [
        ['titik' => 8.77, 'standar' => 0, 'pembacaan' => [8.82, 8.82, 8.82, 8.83, 8.83], 'suhu' => 23.7],
    ];

    public function run(): void
    {
        $customer = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'LPKL PDAM TIRTAWENING'],
            [
                'organization_id' => 1,
                'alamat' => 'Jl. Atlas No. 6 Antapani Kota Bandung',
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
                    // Larutan oksigen dibaca nominal apa adanya — nggak ada
                    // kurva suhu (lihat DoMeterProfile::standarBerkurvaSuhu).
                    'koefisien_suhu' => null,
                ],
            );
        }

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Instrumen Analitik'))
            ->firstOrFail();

        $equipment = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => 'B542569141'],
            [
                'organization_id' => 1,
                'customer_id' => $customer->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'DO Meter',
                'nama_alat_kemampuan' => 'DO Meter',
                'merk' => 'Mettler Toledo',
                'model' => 'Five Go',
                'range_min' => 0,
                'range_max' => 20,
                'satuan' => 'mg/L',
                'resolusi' => 0.01,
                // NULL, bukan 0: master DO Meter nggak punya batas keberterimaan
                // (MPE) sama sekali, dan sertifikatnya nggak nyetak vonis
                // PASS/FAIL — DoMeterProfile::punyaToleransi() = false. 0 di
                // kolom ini kebaca sebagai "batasnya nol mg/L", pernyataan yang
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

        // Thermohygro TH-2 (INPUT DATA "Thermohygro used: TH-2").
        $th2 = Standard::where('organization_id', 1)->where('nama', 'TH-2')->first();

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => '2406.50.S'],
            [
                'equipment_id' => $equipment->id,
                'nomor_order' => '2406.50.S.NK',
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar[0]->id,
                'thermohygro_standard_id' => $th2?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2024-06-17',
                'tanggal_terima' => '2024-06-17',
                'lokasi' => 'lab',
                // Awal & akhir apa adanya dari INPUT DATA Suhu Ruangan (23,6 →
                // 23,8 °C) & Kelembaban (52 → 55 %RH). suhu_ruang & U95-nya
                // JANGAN ditulis — biar `KondisiLingkungan` yang ngitung dari
                // awal/akhir + koreksi sertifikat TH-2, persis kayak alur
                // teknisi beneran.
                'suhu_awal' => 23.6,
                'suhu_akhir' => 23.8,
                'kelembaban_awal' => 52,
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
        $versiRumus = $this->versiRumusUntuk($sesi);

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

            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                'formula_version_id' => $versiRumus,
                ...$hasil,
            ]);
        }

        // DO Meter nggak divonis (punyaToleransi=false) — keputusan sesi null.
        $sesi->update(['keputusan' => null]);
    }
}
