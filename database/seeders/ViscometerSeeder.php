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
 * Data Viscometer dari `5. Viscometer 86068360 terbaru .xlsm` — LIMA larutan
 * standar Paragon Scientific, alat Brookfield DV Plus S/N 86068360, dan satu
 * sesi kalibrasi end-to-end yang U95%-nya BENERAN dihitung `GumCalculator`,
 * bukan angka jadi yang ditempel.
 *
 * Sumbernya sesi asli **0817-CAL-726** (order 2607.59.W, PT Lamurindo
 * Cikarang, 31 Juli 2026), menggantikan lembar trial tanpa nomor job yang
 * dipakai sampai 19 Agustus 2026. Pembacaannya dari `INPUT DATA` blok "After
 * Adjustment Reading" (G48:L52).
 *
 * Sesi ini mengukur TIGA titik (100, 1000, 3000 cP); blok 60000 & 100000 cP
 * ada di lembarnya tapi tidak diisi. Kelima larutan tetap diseed — teknisi
 * membawanya, dan sertifikat mencetak seluruh baris "Standard used".
 *
 * ## TIGA tempat angkanya SENGAJA beda dari master, semuanya terlacak
 *
 * **1. Nilai acuan titik 3000 cP: 2891,02 cP, master 2495,68 cP.** Blok
 * interpolasi 3000 cP di sheet `PERHITUNGAN` (`T33`/`T34`) berisi ANGKA
 * KETIKAN 3437 & 1398 yang menimpa formulanya. Empat blok lain — termasuk
 * baris pertama blok yang sama (`T32`) — semuanya formula ke
 * `Tabel Pengaruh Temperature`, yang untuk larutan ini berbunyi 3987 @25 °C
 * dan 1613 @37,78 °C. Yang dipakai TABEL SERTIFIKATNYA: itu satu-satunya
 * sumber yang punya identitas (Paragon Scientific/N1400, S/N 2241502068), dan
 * sheet `INPUT DATA` maupun budget U95 titik itu juga membacanya (`K34` =
 * 3987). Sertifikat yang memakai dua nilai berbeda untuk satu larutan tidak
 * bisa dipertahankan di depan asesor. Akibatnya koreksi titik itu berbalik
 * tanda: +181,22 cP di sini, −214,12 cP di master.
 *
 * **2. Resolusi 0,1 cP, bukan 1.** `INPUT DATA!E16` menulis 1 dan blok 1000 cP
 * membacanya, tapi empat blok U95 lain mematok 0,1 — dan pembacaan di lembar
 * itu sendiri (79,7 / 779,5) cuma mungkin dari alat beresolusi 0,1. Akibatnya
 * `uc` titik 1000 cP keluar 1,1867 di sini, 1,2209 di master.
 *
 * **3. Faktor cakupan k=2 untuk SEMUA titik.** Master memakai dua aturan
 * sekaligus: `2` di blok 100 & 1000 cP, pendekatan t-student di tiga blok
 * baru. Sertifikatnya mencetak `Coverage Factor ( k ) = 2` dan memberi judul
 * kolom `U95%, k=2`, jadi yang diikuti dokumennya. Akibatnya U95 titik 3000 cP
 * keluar 10,088 cP di sini, 9,949 cP di master — 1,4 % lebih besar, arah yang
 * aman.
 *
 * Ketiganya dicatat di `docs/pertanyaan-lab-viscometer.md` dan diassert
 * EKSPLISIT di `ViscometerBudgetTest` — biar kalau lab menjawab, yang berubah
 * ketahuan langsung, bukan lewat test yang toleransinya kelewat longgar.
 *
 * ## Yang cocok PERSIS sama master
 *
 *   titik      Standard Value      uc                     v_eff
 *   100 cP     79,895696400626     0,07733775101928006    198,2005653515736
 *   3000 cP    (lihat catatan 1)   5,044064680830383      209,3999674977603
 *
 * WAJIB jalan SETELAH `ViscometerCapabilitySeeder` (butuh CMC + `u_temperature`)
 * dan `ThermohygroSeeder` (TH-2).
 */
class ViscometerSeeder extends Seeder
{
    use MemanjangkanMasaBerlaku;
    use MenstempelVersiRumus;

    /**
     * Tiga larutan standar (`DATABASE` R13:Z15) + tabel sertifikat suhunya
     * (`Tabel Pengaruh Temperature`).
     *
     * `ketidakpastian` = U95% pada 25 °C dalam cP, k=2 — hasil `u_persen` baris
     * 25 °C dikali nilainya (0,17 % × 99,65 = 0,169405). Disimpen absolut
     * supaya jalur hitung nggak perlu tau soal persen; `u_persen` tetap ikut
     * di tabel sebagai sumber angkanya.
     *
     * Kolom densitas di sertifikat larutan SENGAJA nggak ikut: nggak ada satu
     * sel pun di jalur hitung master yang makai, dan nyimpen angka yang nggak
     * dipakai bikin pembaca berikutnya nyari-nyari di mana dia kepake.
     *
     * @var list<array{nama: string, merk: string, serial: string, berlaku: string, ketidakpastian: float, tabel: list<array{suhu: float, nilai: float, u_persen: float}>}>
     */
    private const STANDAR = [
        [
            'nama' => 'Viscosity Standard Solution 100 cP',
            'merk' => 'Paragon Scientific/S60',
            'serial' => '1241202088',
            // Kalibrasi 19 Agt 2025, interval 3 tahun.
            'berlaku' => '2028-08-19',
            // 0,17 % x 99,65 cP. Lihat catatan panjang di `tabel` di bawah soal
            // kenapa BUKAN 0,13 % seperti yang tertulis di master terbaru.
            'ketidakpastian' => 0.169405,
            // Kolom `u_persen` IKUT MASTER LAMA (CSV), bukan master terbaru.
            //
            // ## Kenapa, padahal biasanya berkas terbaru yang menang
            //
            // Master 20 Agu 2026 menulis kolom ini terbalik arah dari master
            // sebelumnya — 0,13 % di 20 degC naik ke 0,17 % di 100 degC,
            // sementara master lama 0,17 % turun ke 0,08 %. Lima bukti bahwa
            // yang menyimpang berkas barunya, bukan berkas lamanya:
            //
            //  1. **Botolnya sama.** `DATABASE!T13` di KEDUA master menulis
            //     S/N 1241202088. Ini bukan lot yang diganti.
            //  2. **Nilai viskositas & densitasnya sama persis** di kedua
            //     berkas (134 / 99,65 / 51,1 ... dan 0,8585 / 0,8554 / ...).
            //     Sertifikat larutan yang direvisi tidak mungkin mengubah satu
            //     kolom saja dan membiarkan dua kolom lain identik.
            //  3. **Arahnya melawan empat larutan lain di workbook yang sama.**
            //     1000 cP (0,23 -> 0,13), 3000 cP (0,25 -> 0,17), dan 60000 cP
            //     (0,23 -> 0,17) semuanya MENURUN dengan suhu. Larutan 100 cP
            //     satu-satunya yang menaik.
            //  4. **Header tabelnya sendiri mundur.** Sheet
            //     `Tabel Pengaruh Temperature` di master baru menulis S/N
            //     1220905085 — lot yang LEBIH TUA dari yang ada di master lama
            //     maupun di `DATABASE`-nya sendiri.
            //  5. **Sertifikat yang sudah terbit mereproduksi angka lama.**
            //     `CAL/2026/08/0047` (sesi KAL/2026/08/0052) mencetak U95 titik
            //     100 cP = 0,49299154 cP, dan angka itu cuma bisa lahir dari
            //     0,17 %. Dengan 0,13 % hasilnya 0,48075411 — dokumen yang
            //     sudah dipegang pelanggan tidak bisa dihitung ulang.
            //
            // Sempat dipakai 0,13 % (20 Agu 2026, commit 1810a99) dengan
            // anggapan lab mengganti lot. Anggapan itu keliru: serialnya sama.
            // Ditanyakan ke lab di `docs/pertanyaan-lab-viscometer.md` butir 10.
            'tabel' => [
                ['suhu' => 20.0, 'nilai' => 134.0, 'u_persen' => 0.17],
                ['suhu' => 25.0, 'nilai' => 99.65, 'u_persen' => 0.17],
                ['suhu' => 37.78, 'nilai' => 51.1, 'u_persen' => 0.15],
                ['suhu' => 40.0, 'nilai' => 45.97, 'u_persen' => 0.15],
                ['suhu' => 50.0, 'nilai' => 29.75, 'u_persen' => 0.13],
                ['suhu' => 60.0, 'nilai' => 20.32, 'u_persen' => 0.13],
                ['suhu' => 80.0, 'nilai' => 10.75, 'u_persen' => 0.13],
                ['suhu' => 98.89, 'nilai' => 6.638, 'u_persen' => 0.08],
                ['suhu' => 100.0, 'nilai' => 6.47, 'u_persen' => 0.08],
            ],
        ],
        [
            'nama' => 'Viscosity Standard Solution 1000 cP',
            'merk' => 'Paragon Scientific/D1000',
            'serial' => '1252905118',
            // Kalibrasi 25 Nov 2025, interval 3 tahun.
            'berlaku' => '2028-11-25',
            'ketidakpastian' => 2.3414,
            'tabel' => [
                ['suhu' => 20.0, 'nilai' => 1504.0, 'u_persen' => 0.23],
                ['suhu' => 25.0, 'nilai' => 1018.0, 'u_persen' => 0.23],
                ['suhu' => 37.78, 'nilai' => 419.5, 'u_persen' => 0.19],
                ['suhu' => 40.0, 'nilai' => 364.6, 'u_persen' => 0.19],
                ['suhu' => 50.0, 'nilai' => 203.2, 'u_persen' => 0.19],
                ['suhu' => 60.0, 'nilai' => 121.3, 'u_persen' => 0.17],
                ['suhu' => 80.0, 'nilai' => 51.27, 'u_persen' => 0.15],
                ['suhu' => 98.89, 'nilai' => 26.8, 'u_persen' => 0.13],
                ['suhu' => 100.0, 'nilai' => 25.9, 'u_persen' => 0.13],
            ],
        ],
        [
            'nama' => 'Viscosity Standard Solution 3000 cP',
            'merk' => 'Paragon Scientific/N1400',
            'serial' => '2241502068',
            // Kalibrasi 19 Jun 2025, interval 3 tahun.
            'berlaku' => '2028-06-19',
            // 0,25 % x 3987 (`Tabel Pengaruh Temperature!D91`).
            'ketidakpastian' => 9.9675,
            // Tabel INI yang dipakai, bukan angka ketikan 3437/1398 di sel
            // `PERHITUNGAN!T33`/`T34` — lihat catatan 1 di docblock kelas.
            'tabel' => [
                ['suhu' => 20.0, 'nilai' => 5082.0, 'u_persen' => 0.25],
                ['suhu' => 25.0, 'nilai' => 3987.0, 'u_persen' => 0.25],
                ['suhu' => 37.78, 'nilai' => 1613.0, 'u_persen' => 0.23],
                ['suhu' => 40.0, 'nilai' => 1419.0, 'u_persen' => 0.23],
                ['suhu' => 50.0, 'nilai' => 781.9, 'u_persen' => 0.21],
                ['suhu' => 60.0, 'nilai' => 458.9, 'u_persen' => 0.19],
                ['suhu' => 80.0, 'nilai' => 185.7, 'u_persen' => 0.19],
                ['suhu' => 98.89, 'nilai' => 92.55, 'u_persen' => 0.17],
                ['suhu' => 100.0, 'nilai' => 89.18, 'u_persen' => 0.17],
            ],
        ],
        [
            'nama' => 'Viscosity Standard Solution 60000 cP',
            'merk' => 'Paragon Scientific/N18000',
            'serial' => '4230901097',
            // Kalibrasi 24 Sep 2025, interval 2 tahun.
            'berlaku' => '2027-09-24',
            'ketidakpastian' => 135.7069,
            'tabel' => [
                ['suhu' => 20.0, 'nilai' => 95192.0, 'u_persen' => 0.23],
                ['suhu' => 25.0, 'nilai' => 59003.0, 'u_persen' => 0.23],
                ['suhu' => 37.78, 'nilai' => 19259.0, 'u_persen' => 0.23],
                ['suhu' => 40.0, 'nilai' => 16096.0, 'u_persen' => 0.23],
                ['suhu' => 50.0, 'nilai' => 7489.0, 'u_persen' => 0.22],
                ['suhu' => 60.0, 'nilai' => 3732.0, 'u_persen' => 0.22],
                ['suhu' => 80.0, 'nilai' => 1117.0, 'u_persen' => 0.2],
                ['suhu' => 98.89, 'nilai' => 432.7, 'u_persen' => 0.17],
                ['suhu' => 100.0, 'nilai' => 411.3, 'u_persen' => 0.17],
            ],
        ],
        [
            'nama' => 'Viscosity Standard Solution 100000 cP',
            'merk' => 'Paragon Scientific/RT100000',
            'serial' => '1251704078',
            // Kalibrasi 9 Jul 2025, interval 3 tahun.
            'berlaku' => '2028-07-09',
            // 0,46 % x 99613 (`Tabel Pengaruh Temperature!D106`).
            'ketidakpastian' => 458.2198,
            // TIGA baris, bukan sembilan — sertifikat larutan ini memang cuma
            // memuat 20 & 25 degC, dan baris 37,78 degC pun diturunkan di
            // workbook (`B107 = B106 - E107*12,78`), bukan diukur. Diseed apa
            // adanya: menambah baris yang tidak ada di sertifikatnya berarti
            // mengarang titik tertelusur. Kolom ketidakpastian baris 37,78
            // dikosongkan master; diisi 0,46 di sini mengikuti dua baris di
            // atasnya, dan angka itu tidak pernah dipakai menghitung karena
            // yang dibaca budget cuma baris 25 degC.
            'tabel' => [
                ['suhu' => 20.0, 'nilai' => 110487.0, 'u_persen' => 0.46],
                ['suhu' => 25.0, 'nilai' => 99613.0, 'u_persen' => 0.46],
                ['suhu' => 37.78, 'nilai' => 71819.056, 'u_persen' => 0.46],
            ],
        ],
    ];

    /**
     * Titik sesi contoh, urut kayak lembarnya. `pembacaan` & `suhu` sejajar
     * per-index. Semuanya dari blok "After Adjustment Reading" (`INPUT DATA`
     * G48:L52).
     *
     * Titik ketiga (3000 cP) `spindle` & `rpm`-nya NULL — lembar masternya
     * memang tidak diisi (`INPUT DATA!L42` & `K43` kosong). Tanpa dua itu
     * Fullscale tidak punya arti, jadi MPE-nya null dan titik itu terbit tanpa
     * vonis PASS/FAIL. Dilaporkan sebagai kosong, bukan ditebak.
     *
     * @var list<array{standar: int, spindle: string|null, rpm: float|null, pembacaan: list<float>, suhu: list<float>}>
     */
    private const TITIK = [
        [
            'standar' => 0,
            // `INPUT DATA!H42` = 1 dengan model layar RV -> spindle RV1.
            'spindle' => 'RV1',
            'rpm' => 60.0,
            'pembacaan' => [79.7, 79.6, 79.7, 79.6, 79.6],
            'suhu' => [30.2, 30.2, 30.2, 30.2, 30.2],
        ],
        [
            'standar' => 1,
            // `INPUT DATA!J42` = 3 -> RV3.
            'spindle' => 'RV3',
            'rpm' => 61.0,
            'pembacaan' => [779.5, 779.5, 779.5, 779.5, 779.5],
            'suhu' => [30.6, 30.6, 30.6, 30.6, 30.6],
        ],
        [
            'standar' => 2,
            'spindle' => null,
            'rpm' => null,
            'pembacaan' => [2709.0, 2710.0, 2710.0, 2710.0, 2710.0],
            'suhu' => [30.9, 30.9, 30.9, 30.9, 30.9],
        ],
    ];

    /**
     * Model badan alat (`INPUT DATA!AA57`, turunan pilihan `J12` = 6) → TK 1.
     * Layarnya menampilkan `RV`, dan itu yang menentukan kelompok spindle.
     */
    private const MODEL_VISCO = 'DV2TRV';

    public function run(): void
    {
        $customer = Customer::updateOrCreate(
            ['organization_id' => 1, 'nama' => 'PT LAMURINDO CIKARANG'],
            [
                'organization_id' => 1,
                'alamat' => 'Jl. Ramin Raya, Sukamahi, Kec. Cikarang Pusat, '
                    .'Kabupaten Bekasi, Jawa Barat 17530',
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
                    'tertelusur_ke' => 'Paragon Scientific Limited',
                    'berlaku_sampai' => $this->berlakuSampaiDemo($s['nama'], $s['berlaku']),
                    'ketidakpastian' => $s['ketidakpastian'],
                    'satuan_ketidakpastian' => 'cP',
                    'faktor_cakupan' => 2,
                    // Bentuk TABEL, bukan polinom {a,b,c} kayak buffer pH.
                    // Lihat `Standard::nilaiPadaSuhu()`.
                    'koefisien_suhu' => ['tabel' => $s['tabel']],
                ],
            );
        }

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('kode', Str::slug('Instrumen Analitik'))
            ->firstOrFail();

        $equipment = Equipment::updateOrCreate(
            ['organization_id' => 1, 'serial_number' => '86068360'],
            [
                'organization_id' => 1,
                'customer_id' => $customer->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Viscometer',
                // Kunci yang bikin registry milih ViscometerProfile.
                'nama_alat_kemampuan' => 'Viscometer',
                'merk' => 'Brookfield',
                'model' => 'DV Plus',
                // INPUT DATA "Rentang Ukur : 100-3000".
                'range_min' => 100,
                'range_max' => 3000,
                'satuan' => 'cP',
                // 0,1 walau `INPUT DATA!E16` menulis 1 — lihat
                // `ViscometerProfile::resolusiTitik()`. Pembacaan lembarnya
                // sendiri (79,7 / 779,5) cuma mungkin dari alat 0,1 cP.
                'resolusi' => 0.1,
                // SENGAJA null, dan ini BEDA artinya dari Spectrophotometer.
                // Viscometer punya batas keberterimaan (MPE), tapi batasnya
                // dihitung per titik dari spindle & RPM — bukan satu angka per
                // alat. Lihat ViscometerProfile::toleransiTitik().
                'toleransi' => null,
                'lokasi' => 'Insitu',
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
            ['organization_id' => 1, 'nomor_sesi' => '2607.59.W'],
            [
                'equipment_id' => $equipment->id,
                'nomor_order' => '2607.59.W',
                'teknisi_id' => $teknisi->id,
                'standard_id' => $standar[0]->id,
                'thermohygro_standard_id' => $th2?->id,
                'input_method' => 'manual',
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'tanggal_terima' => '2026-07-31',
                'tanggal_kalibrasi' => '2026-07-31',
                'lokasi' => 'onsite',
                // Pembacaan thermohygro apa adanya (INPUT DATA E22:F23).
                // suhu_ruang & U95-nya JANGAN ditulis di sini —
                // `KondisiLingkungan` yang ngitung.
                'suhu_awal' => 25.2,
                'suhu_akhir' => 25.3,
                'kelembaban_awal' => 57.0,
                'kelembaban_akhir' => 58.0,
                // Nentuin TK lewat Tabel D-2. Dibaca `toleransiTitik()` waktu
                // ngitung MPE, dan dibaca ulang `CalibrationValidator` waktu
                // hitung ulang.
                'spesifikasi_alat' => ['model_visco' => self::MODEL_VISCO],
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

        $versiRumus = $this->versiRumusUntuk($sesi);
        $gum = app(GumCalculator::class);

        // Suhu ruang MENTAH (awal+akhir)/2 — sama persis kayak yang dipakai
        // `CalibrationController::susunPengukuran()`.
        $suhuRuang = ((float) $sesi->suhu_awal + (float) $sesi->suhu_akhir) / 2.0;

        foreach (self::TITIK as $index => $titik) {
            $std = $standar[$titik['standar']];
            $titikKe = $index + 1;
            $nominal = self::STANDAR[$titik['standar']]['tabel'][1]['nilai'];

            foreach ($titik['pembacaan'] as $i => $nilai) {
                RawMeasurement::create([
                    'calibration_session_id' => $sesi->id,
                    'titik_ke' => $titikKe,
                    'pembacaan_ke' => $i + 1,
                    'tahap' => 'sesudah_adjustment',
                    // Nilai NOMINAL botol — yang tertulis di lembar kerja.
                    // Yang kecetak di sertifikat nanti hasil interpolasi pada
                    // suhu terukur, dan itu dihitung `hitungTitik()` di bawah.
                    'titik_ukur' => $nominal,
                    'standard_id' => $std->id,
                    'pembacaan' => $nilai,
                    'suhu' => $titik['suhu'][$i],
                    'satuan' => 'cP',
                    'spindle' => $titik['spindle'],
                    'rpm' => $titik['rpm'],
                    'input_source' => 'manual',
                    'is_verified' => true,
                ]);
            }

            $suhuLarutan = array_sum($titik['suhu']) / count($titik['suhu']);

            $hasil = $gum->hitungTitik(
                $titikKe,
                (float) $nominal,
                $titik['pembacaan'],
                $equipment,
                $std,
                $suhuLarutan,
                $suhuRuang,
                [
                    'spindle' => $titik['spindle'],
                    'rpm' => $titik['rpm'],
                    'tk' => self::MODEL_VISCO,
                ],
            );

            UncertaintyCalculation::create([
                'calibration_session_id' => $sesi->id,
                'formula_version_id' => $versiRumus,
                ...$hasil,
            ]);
        }
    }
}
