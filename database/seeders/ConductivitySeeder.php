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
use App\Services\Calibration\Profiles\ConductivityProfile;
use App\Services\GumCalculator;
use App\Services\KondisiLingkungan;
use Database\Seeders\Concerns\MemanjangkanMasaBerlaku;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data Conductivity Meter dari `Master Olah Data_Conductivity.xlsm` — tiga
 * larutan standar Supelco/Merck, alat Lovibond Sensodirect ISO, dan satu sesi
 * kalibrasi end-to-end (job `0189-CAL-524`) yang ketidakpastiannya BENERAN
 * dihitung `GumCalculator`, bukan angka jadi ditempel.
 *
 * Pembacaannya dari blok "After Adjustment Reading" (INPUT DATA baris 49–53) —
 * blok itu yang dipakai sertifikat & budget, bukan Before Adjustment.
 *
 * Angka yang harus keluar, dicek `ConductivityBudgetTest` lawan sel master:
 *
 *   STDEV     0,05477225575051739 | 0 | 0,008944271909997378  (PERHITUNGAN 48)
 *   Uc        0,25337606633141846 | 4,114873303302707 | 0,8032870446419409
 *   U95%      0,49948652157359164 | 8,109011947857544 | 1,5838562066470179
 *   Sertifikat 0,49948652157359164 | 8,109011947857544 | 1,7  ← titik 3 kena lantai CMC
 *
 * WAJIB jalan SETELAH `ConductivityCapabilitySeeder` (butuh CMC biar
 * `GumCalculator` masuk jalur budget penuh) dan `ThermohygroSeeder` (TH-6).
 */
class ConductivitySeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;

    /**
     * Tiga larutan standar ASLI (sheet DATABASE R13/R14/R15 + V13/V14/V15 +
     * W13/W14/W15). U95% sertifikat 0,5 µS/cm / 8 µS/cm / 1,6 mS/cm, k=2,
     * tertelusur Merck KGaA.
     *
     * `koefisien_suhu` diambil dari `ConductivityProfile::KOEF_SUHU` — SATU
     * sumber, biar profil dan seeder nggak bisa lari sendiri-sendiri.
     *
     * @var list<array{nama: string, serial: string, ketidakpastian: float, satuan: string, koef: string, berlaku_sampai: string}>
     */
    private const STANDAR = [
        [
            'nama' => 'Conductivity Std Solution 25 µS/cm',
            'serial' => 'LRAD7693',
            'ketidakpastian' => 0.5,
            'satuan' => 'µS/cm',
            'koef' => '25',
            'berlaku_sampai' => '2027-02-01',
        ],
        [
            'nama' => 'Conductivity Std Solution 1412 µS/cm',
            'serial' => 'LRAD9052',
            'ketidakpastian' => 8.0,
            'satuan' => 'µS/cm',
            'koef' => '1412',
            'berlaku_sampai' => '2027-06-10',
        ],
        [
            'nama' => 'Conductivity Std Solution 111 mS/cm',
            'serial' => 'HC56824055',
            'ketidakpastian' => 1.6,
            'satuan' => 'mS/cm',
            'koef' => '111',
            'berlaku_sampai' => '2028-04-30',
        ],
    ];

    /**
     * Titik ukur sesi contoh — After Adjustment (INPUT DATA F/H/L 49–53),
     * lengkap sama suhu larutan per pengulangan (kolom G/I/M).
     *
     * Kolom J (varian mS/cm titik tengah) KOSONG di master, jadi nggak ikut
     * diseed — lihat E-5 & `ConductivityProfile::peringatanVarianMili()`.
     *
     * @var list<array{titik: float, standar: int, satuan: string, pembacaan: list<float>, suhu: list<float>}>
     */
    private const TITIK = [
        [
            'titik' => 25.0,
            'standar' => 0,
            'satuan' => 'µS/cm',
            'pembacaan' => [25, 25, 25, 25.1, 25.1],
            'suhu' => [25.2, 25.2, 25.2, 25.3, 25.2],
        ],
        [
            'titik' => 1412.0,
            'standar' => 1,
            'satuan' => 'µS/cm',
            'pembacaan' => [1413, 1413, 1413, 1413, 1413],
            'suhu' => [25, 24.9, 24.9, 24.9, 24.9],
        ],
        [
            'titik' => 111.0,
            'standar' => 2,
            'satuan' => 'mS/cm',
            'pembacaan' => [110.69, 110.67, 110.67, 110.67, 110.67],
            'suhu' => [25.2, 25.2, 25.2, 25.2, 25.2],
        ],
    ];

    public function run(): void
    {
        $customer = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'Pusat Kesehatan Angkatan Darat Lembaga Biologi Vaksin'],
            [
                'organization_id' => 1,
                'alamat' => 'Jl. Gudang Selatan No.26, Merdeka, Kec. Sumur Bandung, Kota Bandung, Jawa Barat 40113',
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
                    'satuan_ketidakpastian' => $s['satuan'],
                    'faktor_cakupan' => 2,
                    // Nilai acuan larutan conductivity BERGESER ikut suhu —
                    // beda dari turbidity/chlorine yang dibaca nominal.
                    'koefisien_suhu' => ConductivityProfile::KOEF_SUHU[$s['koef']],
                ],
            );
        }

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Instrumen Analitik'))
            ->firstOrFail();

        $equipment = Equipment::updateOrCreate(
            // ⚠ SERIAL SEED, BUKAN SERIAL ALAT SEBENARNYA.
            //
            // `INPUT DATA!C13` di master Conductivity nulis `C12345` — dan
            // master Refractometer nulis serial yang SAMA PERSIS. Dua workbook
            // trial lab memakai nomor contoh yang sama.
            //
            // `equipments` punya unique `(organization_id, serial_number)`,
            // jadi dua alat nggak bisa berbagi serial. Waktu seeder ini pakai
            // `C12345`, `updateOrCreate` nggak bikin baris baru — dia MENIMPA
            // baris alat Refractometer: sesi `2211.11.R` berubah jadi
            // "Conductivity Meter", ke-match `ConductivityProfile`, dan hasil
            // hitung ulangnya jadi ngawur (UUT balik ke pembacaan mentah
            // 1,3362, U95 0,005774, satuan kebaca mS/cm). Lima tes
            // Refractometer mendadak 422; ketahuan 11 Agt 2026.
            //
            // Sufiks di bawah dipakai supaya alat kelima berhenti ngerusak alat
            // keempat. Ini keputusan DATA SEED, bukan angka kalibrasi — begitu
            // lab ngasih serial Conductivity yang sebenarnya, ganti di sini.
            ['organization_id' => 1, 'serial_number' => 'C12345-COND'],
            [
                'organization_id' => 1,
                'customer_id' => $customer->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Conductivity Meter',
                // Kunci yang bikin registry milih ConductivityProfile. Ejaannya
                // ngikut lampiran akreditasi (`Conductivitymeter`, satu kata),
                // BUKAN nama alat di sertifikat — pencocokan CMC pakai yang ini.
                'nama_alat_kemampuan' => 'Conductivitymeter',
                'merk' => 'Lovibond',
                'model' => 'Sensodirect ISO',
                'range_min' => 0,
                'range_max' => 100,
                'satuan' => 'mS/cm',
                // Fallback kalau `resolusi_rentang` di bawah kosong.
                'resolusi' => 0.01,
                // Baris "Resolusi Alat" di lembar input — INPUT DATA
                // E16/E17 · G16/G17 · I16/I17, tiga kotak dengan SATUANNYA
                // masing-masing.
                //
                // Satuan di sini yang nentuin satuan sertifikat, bukan format
                // yang kita patok (arahan lab 11 Agt 2026). Alat contoh ini
                // baca 25 & 1412 dalam µS/cm lalu pindah ke mS/cm di standar
                // ketiga — pola paling umum. Alat pelanggan lain yang pindah
                // ke mS/cm lebih awal tinggal diisi beda di sini; nggak ada
                // kode yang perlu disentuh.
                //
                // Kuncinya `titik` (nilai nominal larutan), BUKAN `maks` kayak
                // Turbidimeter — lihat `ConductivityProfile::barisAlat()`.
                // Singkatnya: 111 mS/cm secara angka lebih kecil dari 1412
                // µS/cm, jadi ambang numerik nggak bisa misahin keduanya.
                'resolusi_rentang' => [
                    ['titik' => 25, 'resolusi' => 0.1, 'satuan' => 'µS/cm'],
                    ['titik' => 1412, 'resolusi' => 1.0, 'satuan' => 'µS/cm'],
                    ['titik' => 111, 'resolusi' => 0.01, 'satuan' => 'mS/cm'],
                ],
                // SENGAJA null — Q3. Master nggak punya satu pun sel yang
                // mbandingin hasil sama batas keberterimaan, dan sertifikatnya
                // nggak nyetak vonis. `GumCalculator::keputusan()` balikin null
                // buat toleransi kosong, jadi sesi ini nggak divonis PASS/FAIL.
                // Ngisi angka di sini = ngarang kriteria kelulusan.
                'toleransi' => null,
                'lokasi' => 'Lab. PT. Sidik',
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

        $th6 = Standard::where('organization_id', 1)->where('nama', 'TH-6')->first();

        $sesi = CalibrationSession::updateOrCreate(
            ['organization_id' => 1, 'nomor_sesi' => '2405.32.A.NK'],
            [
                'equipment_id' => $equipment->id,
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar[0]->id,
                'thermohygro_standard_id' => $th6?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_kalibrasi' => '2024-05-13',
                'lokasi' => 'lab',
                // Pembacaan thermohygro awal & akhir apa adanya (INPUT DATA
                // E23/F23 & E24/F24). suhu_ruang & U95-nya JANGAN ditulis di
                // sini — `KondisiLingkungan` yang ngitung dari awal/akhir +
                // koreksi sertifikat TH-6, persis kayak alur teknisi beneran.
                'suhu_awal' => 25.4,
                'suhu_akhir' => 25.5,
                'kelembaban_awal' => 54.0,
                'kelembaban_akhir' => 55.0,
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

        $kalkulator = new GumCalculator;

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
                    'suhu' => $titik['suhu'][$i],
                    'satuan' => $titik['satuan'],
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }

            // Suhu larutan yang dipakai ngoreksi nilai acuan itu RATA-RATA
            // suhu titik ini — master ngerata-ratain dulu (PERHITUNGAN baris
            // 46), baru masuk polinomial di baris 39.
            $suhuRata = array_sum($titik['suhu']) / count($titik['suhu']);

            $hasil = $kalkulator->hitungTitik(
                $titikKe,
                $titik['titik'],
                $titik['pembacaan'],
                $equipment,
                $std,
                $suhuRata,
            );

            UncertaintyCalculation::create(['calibration_session_id' => $sesi->id, ...$hasil]);
        }

        // Sengaja NGGAK nyetel `keputusan` — conductivity nggak divonis
        // PASS/FAIL (Q3). Lihat catatan `toleransi` di atas.
    }
}
