<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;

/**
 * Rantai inti **Volumetric Glassware** — kalibrasi gravimetri gelas ukur
 * volumetrik, metode `SIDIK-IK-CAL-0510`, Lab. Volumetrik.
 *
 * Dipakai bersama oleh DUA keluarga profil yang strategi budget-nya berbeda:
 *
 *  - **Fixed** (satu nominal): Labu Ukur, Pipet Volume, Picnometer
 *  - **Graduated** (sampai 5 titik skala): Gelas Ukur, Buret, Pipet Ukur
 *
 * Keenamnya alat terpisah di lampiran akreditasi LK-285-IDN (no. 13, 17, 18,
 * 19, 20, 21) dengan tabel CMC masing-masing — "Fixed"/"Graduated" itu sumbu
 * workbook Excel, bukan sumbu akreditasi, dan tidak pernah muncul sebagai nama
 * alat di mana pun.
 *
 * ## Dibuktikan sebelum ditulis
 *
 * Seluruh rantai di bawah diadu lebih dulu ke cache Excel kedua workbook
 * master, dan baru sesudah cocok kelas ini ditulis. Hasilnya **selisih nol**
 * untuk V20 kedua alat, kedelapan koefisien sensitivitas Fixed, dan `uc` kedua
 * alat. Test yang mengulang adu itu:
 * `tests/Unit/VolumetricGlasswareMasterTest.php`.
 *
 * ## Yang TIDAK boleh dipakai ulang dari Hydrometer
 *
 * Keduanya sekeluarga metode (gravimetri, densitas udara psikrometrik), dan
 * itu membuat pakai-ulang terasa aman padahal tidak. Yang sudah diperiksa
 * angka per angka:
 *
 * | | Hydrometer | Volumetric | Boleh dipakai ulang? |
 * |---|---|---|---|
 * | Densitas udara | `((0,34848·P) − (0,009·RH)·e^(0,061·T)) / (T+273,15) / 1000` | sama persis | **YA** |
 * | Densitas air | polinom orde-5 | bentuk Tanaka | **TIDAK** — lihat bawah |
 * | ρ anak timbangan | **8,0** g/mL | **7,95** g/mL | **TIDAK** |
 *
 * Densitas airnya **tidak setara**: selisih keduanya mencapai `5,0·10⁻⁶` pada
 * rentang 15–35 °C — persis di ambang toleransi rekonsiliasi repo. Di titik uji
 * master selisihnya `3,76·10⁻⁶` dan `4,03·10⁻⁶`, jadi sebagian suhu lolos dan
 * sebagian tidak. Memakai fungsi Hydrometer membuat vektor uji V20 yang
 * sekarang selisihnya NOL jadi meleset — dan melesetnya bergantung suhu sesi,
 * jadi bisa hijau hari ini dan merah bulan depan.
 *
 * `ρ_AT` pun beda: 8,0 di Hydrometer, 7,95 di sini. Memakai yang salah
 * menggeser SELURUH V20 tanpa satu pun error.
 *
 * Dokumen analisis yang menyertai master menulis "ρ_AT … sama seperti
 * Hydrometer" — itu keliru, dan sudah diperiksa ke `TabelStandarHydrometer`.
 */
class VolumetricGlasswareCalculator
{
    /**
     * Densitas konvensional anak timbangan standar, g/mL.
     *
     * `PERHITUNGAN!H58` kedua workbook. **7,95, BUKAN 8,0** — lihat tabel di
     * docblock kelas.
     */
    public const DENSITAS_ANAK_TIMBANGAN = 7.95;

    /** Koefisien muai kubik borosilicate 3.3 (/°C) — dipetakan dari Class A. */
    public const GAMMA_KELAS_A = 9.9e-6;

    /** Koefisien muai kubik borosilicate 5.0 (/°C) — dipetakan dari Class B. */
    public const GAMMA_KELAS_B = 15e-6;

    /** Suhu acuan volume, °C. */
    public const SUHU_ACUAN = 20.0;

    public const KELUARGA_FIXED = 'fixed';

    public const KELUARGA_GRADUATED = 'graduated';

    /** Ulangan per deret per titik — tiga, di kedua workbook. */
    public const PENGULANGAN = 3;

    /** Titik per sesi: Fixed satu nominal, Graduated sampai lima titik skala. */
    public const TITIK_MAKS = [self::KELUARGA_FIXED => 1, self::KELUARGA_GRADUATED => 5];

    /**
     * Ketidakpastian densitas air (kolom U budget), g/mL — BEDA per keluarga.
     *
     * Fixed menulis `=0,05/1000` (5·10⁻⁵), Graduated angka mati 5·10⁻⁸.
     * Pertanyaan lab no. 10; ditiru masing-masing sampai dijawab.
     */
    public const U_DENSITAS_AIR = [self::KELUARGA_FIXED => 5e-05, self::KELUARGA_GRADUATED => 5e-08];

    /** Tanda ci muai termal — pertanyaan lab no. 5. */
    public const TANDA_CI_MUAI = [self::KELUARGA_FIXED => 1, self::KELUARGA_GRADUATED => -1];

    /** `n` pembagi stdev neraca (`PERHITUNGAN_U95%!C16` kedua workbook). */
    public const N_STDEV_NERACA = 10;

    /**
     * Jumlah sel kosong yang ikut dibaca NOL oleh keterulangan Graduated master.
     *
     * `H55 = STDEV(IFERROR(H54:Q54, ""))` menyapu sepuluh sel berpasangan
     * (H..Q, tiap titik dua kolom tergabung). Sel pasangan yang kosong lolos
     * `IFERROR` sebagai 0 — lima nol yang bukan pengukuran. Dipakai HANYA
     * untuk angka pembanding di jejak audit (keputusan pemilik proyek 21 Sep,
     * pertanyaan lab no. 2), tidak pernah untuk U yang terbit.
     */
    public const NOL_HANTU_MASTER = 5;

    /**
     * Densitas udara psikrometrik, g/mL.
     *
     * `PERHITUNGAN!H57`. Pembagian `/1000` DI UJUNG, bukan konstanta yang sudah
     * dibagi — urutan operasi float ikut menentukan digit terakhir yang diadu
     * test rekonsiliasi. Alasan yang sama persis sudah ditulis di
     * `HydrometerCalculator`, dan rumusnya memang identik.
     */
    public static function densitasUdara(float $suhuRuang, float $kelembaban, float $tekanan): float
    {
        return ((0.34848 * $tekanan) - (0.009 * $kelembaban) * exp(0.061 * $suhuRuang))
            / ($suhuRuang + 273.15) / 1000;
    }

    /**
     * Densitas air suling, g/mL — bentuk Tanaka (1975).
     *
     * `PERHITUNGAN!H45`, lewat sel antara `B43`/`B44`/`B45`. Ditulis dalam
     * bentuk Tanaka aslinya, BUKAN polinom orde-5 milik Hydrometer. Keduanya
     * mendekati besaran fisis yang sama tapi **tidak setara secara numerik** —
     * lihat docblock kelas.
     */
    public static function densitasAirSuling(float $t): float
    {
        $a1 = -3.983035;
        $a2 = 301.797;
        $a3 = 522528.9;
        $a4 = 69.34881;

        return 0.99997495 * (1 - ((($t + $a1) ** 2) * ($t + $a2)) / ($a3 * ($t + $a4)));
    }

    /**
     * Volume terkoreksi ke 20 °C, mL.
     *
     * `PERHITUNGAN!H60`:
     *
     * ```
     * V20 = massa · ( 1/(ρ_air − ρ_udara) · (1 − (ρ_udara/ρ_AT) · (1 − γ·(t−20))) )
     * ```
     *
     * **Struktur operatornya disalin persis, jangan diturunkan ulang dari
     * ingatan rumus ISO 4787.** Suku muai termal `γ·(t−20)` BERSARANG di dalam
     * suku daya apung — dikalikan `ρ_udara/ρ_AT` — bukan faktor pengali
     * terpisah di luar. Menulisnya sebagai faktor terpisah menggeser hasil
     * ~0,0001 mL; pada CMC pipet volume yang sekecil 0,002 mL itu signifikan,
     * dan tidak memunculkan error apa pun.
     */
    public static function v20(
        float $massa,
        float $densitasAir,
        float $densitasUdara,
        float $gamma,
        float $suhuAir,
        float $densitasAnakTimbangan = self::DENSITAS_ANAK_TIMBANGAN,
    ): float {
        return $massa * (
            1 / ($densitasAir - $densitasUdara)
            * (1 - ($densitasUdara / $densitasAnakTimbangan)
                * (1 - ($gamma * ($suhuAir - self::SUHU_ACUAN))))
        );
    }

    /**
     * Delapan komponen budget ketidakpastian — `PERHITUNGAN_U95%` baris 36–43.
     *
     * Memulangkan bahan mentah untuk `GumCalculator::agregasiBudget()`, BUKAN
     * hasil agregasinya. Agregasinya sengaja diserahkan ke sana: mesin itu
     * sudah terbukti cocok dengan `TINV` Excel termasuk pemotongan `veff` ke
     * bawah, dan dia membagi dengan **jumlah** `(ui·ci)⁴/vi` — yang otomatis
     * membetulkan `Veff` workbook Fixed yang membagi dengan baris terakhir saja
     * (`K43`, bukan `K44`; lihat `docs/pertanyaan-lab-volumetric.md` no. 1).
     *
     * Masukan `u_*` adalah ketidakpastian yang tertulis di kolom **U** master
     * (sebelum dibagi pembaginya), supaya tiap angkanya bisa diadu langsung ke
     * sel yang sama di workbook.
     *
     * ## Yang beda antar keluarga — ditiru masing-masing, bukan diseragamkan
     *
     * | Masukan | Fixed | Graduated |
     * |---|---|---|
     * | `u_massa` | resolusi neraca ÷ √3 | U95 sertifikat neraca ÷ 2 |
     * | `u_rho_air` | 5·10⁻⁵ (`=0,05/1000`) | 5·10⁻⁸ (angka mati) |
     * | `u_meniskus` | tabel diameter ISO 4787 | resolusi ÷ (2√3) |
     * | `tanda_ci_muai` | +1 | −1 |
     *
     * Keempatnya pertanyaan lab (no. 5, 9, 10). Sampai dijawab, masing-masing
     * keluarga memakai caranya sendiri — memilih salah satu sebagai "yang benar"
     * berarti diam-diam menggeser angka yang sudah tercetak.
     *
     * @param  array{
     *     massa: float, rho_udara: float, rho_air: float, suhu_air: float, gamma: float,
     *     u_massa: float, u_suhu: float, u_meniskus: float, u_rho_air: float,
     *     u_keterulangan: float, tanda_ci_muai: int, rho_anak_timbangan?: float
     * }  $m
     * @return list<array{nama: string, u_diperluas: float, pembagi: float, u: float, ci: float, vi: float}>
     */
    public static function komponenBudget(array $m): array
    {
        $r3 = $m['massa'];
        $r4 = $m['rho_udara'];
        $r5 = $m['rho_air'];
        $r6 = $m['rho_anak_timbangan'] ?? self::DENSITAS_ANAK_TIMBANGAN;
        $r7 = $m['suhu_air'];
        $r8 = $m['gamma'];

        // Nama variabel mengikuti sel catatan master (J3..J8) supaya rumus di
        // bawah bisa diadu baris per baris ke `PERHITUNGAN_U95%` kolom H.
        $faktorMuai = 1 - ($r8 * ($r7 - self::SUHU_ACUAN));
        $ciRho = $r3 * (($r6 - $r5) / ($r6 * (($r5 - $r4) ** 2))) * $faktorMuai;

        $baris = [
            ['Weight of Destillate Water', $m['u_massa'], 2.0, 18.0,
                (($r6 - $r4) / ($r6 * ($r5 - $r4))) * $faktorMuai],
            ['Density of Air', 0.1 * $r4, sqrt(3), 50.0, $ciRho],
            ['Density of Destillate Water', $m['u_rho_air'], 2.0, 60.0, -$ciRho],
            ['Density of Weight Standard', 0.1 * $r6, sqrt(3), 50.0,
                $r3 * $r4 / ($r6 ** 2 * ($r5 - $r4)) * $faktorMuai],
            ['Temperature of Destillate Water', $m['u_suhu'], 2.0, 60.0,
                (-$r3 * $r8 * ($r6 - $r4) * ($r7 - self::SUHU_ACUAN) / ($r6 * ($r5 - $r4)))],
            ['Expansion Coefficient of Material', 0.1 * $r8, sqrt(3), 40.0,
                $m['tanda_ci_muai'] * ($r3 * ($r6 - $r4)) / ($r6 * ($r5 - $r4))],
            ['Meniscus', $m['u_meniskus'], sqrt(3), 50.0, 1.0],
            ['Repeated measurements', $m['u_keterulangan'], 1.0, 2.0, 1.0],
        ];

        return array_map(static fn (array $b): array => [
            'nama' => $b[0],
            'u_diperluas' => $b[1],
            'pembagi' => $b[2],
            'u' => $b[1] / $b[2],
            'ci' => $b[4],
            'vi' => $b[3],
        ], $baris);
    }

    /**
     * Ketidakpastian meniskus keluarga FIXED (mL) — `PERHITUNGAN_U95%` B27–B32.
     *
     * Tebal garis 0,1 mm → Up = 0,05 mm; luas penampang `E = 3,14·ø²/4`;
     * `U = Up·E` (mm³) → ÷1000 ke mL. Diameter `ø` dari tabel ISO 4787 lewat
     * toleransi alat (`TabelStandarVolumetric::diameterMaksimum()`).
     *
     * **3,14, bukan `M_PI`** — master menulis angka itu, dan selisihnya
     * (~0,05%) cukup untuk menggeser digit yang diadu test rekonsiliasi.
     */
    public static function meniskusFixed(float $diameterMm): float
    {
        $up = 0.1 / 2;
        $luas = (3.14 * ($diameterMm ** 2)) / 4;

        return ($up * $luas) / 1000;
    }

    /**
     * Ketidakpastian meniskus keluarga GRADUATED (mL) — `PERHITUNGAN_U95%` B28.
     *
     * `resolusi / (2√3)` — dari resolusi alat, BUKAN tabel diameter seperti
     * Fixed. Budget lalu membaginya dengan √3 LAGI sebagai pembagi komponen;
     * pembagian ganda itu milik master dan ditiru.
     */
    public static function meniskusGraduated(float $resolusiMl): float
    {
        return $resolusiMl / (2 * sqrt(3));
    }

    /**
     * Koefisien muai bahan dari kelas alat.
     *
     * Master cuma memetakan DUA pilihan, walau workbook Graduated menyertakan
     * tabel 14 material (borosilicate, soda-lime, aneka plastik, aluminium,
     * stainless steel, dst). Tabel itu kapasitas yang **belum diaktifkan**:
     * rumusnya sendiri tetap `IF(Class="A", B4, IF(Class="B", B5, "cek kelas"))`
     * — dan `B4`/`B5` persis dua nilai yang di-hardcode di workbook Fixed.
     *
     * Mengaktifkan 14 material butuh keputusan lab lebih dulu: apakah "Class
     * A/B" itu kelas akurasi ISO 4787 atau representasi bahan gelas. Sampai itu
     * dijawab, kelas di luar A/B **ditolak** — tidak dipetakan diam-diam ke
     * nilai bawaan, karena γ yang meleset menggeser seluruh V20 tanpa error.
     */
    public static function gammaDariKelas(?string $kelas): ?float
    {
        return match (strtoupper(trim((string) $kelas))) {
            'A' => self::GAMMA_KELAS_A,
            'B' => self::GAMMA_KELAS_B,
            default => null,
        };
    }

    /**
     * Ketidakpastian suhu air (kolom U budget), °C — `PERHITUNGAN_U95%!H25`.
     *
     *   √[(U95 termometer / 2)² + (U95 sensor / 2)² + ((Tmax − Tmin) / 2√3)²]
     *
     * Rumus yang sama di kedua workbook; cuma rentang suhunya yang disapu
     * berbeda (Fixed: tiga ulangan satu titik; Graduated: seluruh titik).
     */
    public static function uSuhu(float $u95Termometer, float $u95Sensor, float $rentang): float
    {
        return sqrt(($u95Termometer / 2) ** 2 + ($u95Sensor / 2) ** 2 + ($rentang / (2 * sqrt(3))) ** 2);
    }

    /**
     * Hitung SATU sesi Volumetric — kedua keluarga lewat pintu yang sama.
     *
     * `$titik`: list `{titik_ke, nominal, kosong, isi, suhu}` (massa gram,
     * suhu °C BACAAN — koreksi kalibrator + sensor dipasang di sini).
     *
     * `$blok`: `{kelas, toleransi_ml, resolusi_ml, neraca, suhu_awal, suhu_akhir,
     * kelembaban_awal, kelembaban_akhir, tekanan_awal, tekanan_akhir}`.
     *
     * ## Budget: Fixed per titik, Graduated SATU untuk semua titik
     *
     * Graduated master menyusun satu budget dari agregat seluruh titik (MAX
     * massa rata-rata `R38`, MAX ρ air rata-rata `R85`, rata-rata semua suhu
     * `Z49`), dan satu U itu dipakai tiap baris sertifikat. Ditiru. Akibatnya
     * satu titik yang rusak menahan SELURUH sesi Graduated: menghitung budget
     * dari titik yang tersisa berarti U yang tercetak bergantung pada titik
     * mana yang kebetulan diketik benar.
     *
     * ## Yang ditolak, bukan ditebak
     *
     * Kelas di luar A/B, neraca yang bukan milik keluarganya, kondisi
     * lingkungan tak lengkap (ρ udara lahir dari situ), toleransi yang tidak
     * ada di tabel ISO 4787 (Fixed), resolusi kosong (Graduated), deret yang
     * bukan tepat tiga angka, massa air ≤ 0, dan Graduated dengan kurang dari
     * dua titik — keterulangannya STDEV dari simpangan baku per titik, dan
     * STDEV satu angka tidak terdefinisi (master menutupinya dengan nol hantu;
     * pertanyaan lab no. 12).
     *
     * @param  list<array{titik_ke: int, nominal: float, kosong: list<float>, isi: list<float>, suhu: list<float>}>  $titik
     * @param  array<string, mixed>  $blok
     * @return array{boleh_terbit: bool, ditolak: list<array{titik_ke: int, alasan: string}>, praolah: array<string, mixed>, titik: list<array<string, mixed>>}
     */
    public function hitungSesi(string $keluarga, array $titik, array $blok, ?TabelStandarVolumetric $tabel = null): array
    {
        $tabel ??= new TabelStandarVolumetric;
        $tolakSemua = static fn (string $alasan): array => [
            'boleh_terbit' => false,
            'ditolak' => array_map(static fn (array $t): array => [
                'titik_ke' => (int) $t['titik_ke'],
                'alasan' => $alasan,
            ], $titik),
            'praolah' => [],
            'titik' => [],
        ];

        if ($titik === []) {
            return ['boleh_terbit' => false, 'ditolak' => [], 'praolah' => [], 'titik' => []];
        }

        $gamma = self::gammaDariKelas($blok['kelas'] ?? null);
        if ($gamma === null) {
            return $tolakSemua(sprintf(
                'Kelas alat "%s" bukan A atau B. Koefisien muai cuma dipetakan untuk dua kelas itu, '
                .'dan γ yang salah menggeser seluruh V20 tanpa error — pilih kelasnya dulu.',
                (string) ($blok['kelas'] ?? ''),
            ));
        }

        $neraca = is_string($blok['neraca'] ?? null) ? $tabel->neraca($keluarga, $blok['neraca']) : null;
        if ($neraca === null) {
            return $tolakSemua(sprintf(
                'Neraca "%s" bukan neraca workbook %s. Neraca ketiga beda fisik antar keluarga '
                .'(Fujitsu di Fixed, Precisa di Graduated), jadi tidak dicocokkan lintas keluarga.',
                (string) ($blok['neraca'] ?? ''),
                $keluarga === self::KELUARGA_FIXED ? 'Fixed' : 'Graduated',
            ));
        }

        $lingkungan = [];
        foreach (['suhu', 'kelembaban', 'tekanan'] as $besaran) {
            $awal = $blok["{$besaran}_awal"] ?? null;
            $akhir = $blok["{$besaran}_akhir"] ?? null;
            if (! is_numeric($awal) || ! is_numeric($akhir)) {
                return $tolakSemua(
                    'Kondisi lingkungan belum lengkap (suhu, kelembaban, dan tekanan udara — awal & akhir). '
                    .'Densitas udara dihitung dari ketiganya; tanpa itu tidak ada V20 yang bisa diterbitkan.'
                );
            }
            // Rata-rata BACAAN mentah, bukan yang terkoreksi thermohygro —
            // `PERHITUNGAN!G16/G17/G19` memakai kolom G (AVERAGE(E, F)).
            $lingkungan[$besaran] = ((float) $awal + (float) $akhir) / 2;
        }

        $rhoUdara = self::densitasUdara($lingkungan['suhu'], $lingkungan['kelembaban'], $lingkungan['tekanan']);

        if ($keluarga === self::KELUARGA_FIXED) {
            $diameter = is_numeric($blok['toleransi_ml'] ?? null)
                ? $tabel->diameterMaksimum((float) $blok['toleransi_ml'])
                : null;
            if ($diameter === null) {
                return $tolakSemua(sprintf(
                    'Toleransi %s mL tidak ada di tabel diameter ISO 4787. Master memakai pencocokan '
                    .'PERSIS (`VLOOKUP(..., 0)` → #N/A); ketidakpastian meniskus tidak bisa dihitung '
                    .'dari diameter tetangga.',
                    (string) ($blok['toleransi_ml'] ?? 'kosong'),
                ));
            }
            $uMeniskus = self::meniskusFixed($diameter);
            $uTimbang = (float) $neraca['resolusi_g'] / sqrt(3);
        } else {
            $resolusi = is_numeric($blok['resolusi_ml'] ?? null) ? (float) $blok['resolusi_ml'] : null;
            if ($resolusi === null || $resolusi <= 0) {
                return $tolakSemua(
                    'Resolusi alat (mL) belum diisi. Ketidakpastian meniskus Graduated lahir dari '
                    .'resolusi, dan komponen itu yang mendominasi budget-nya.'
                );
            }
            $diameter = null;
            $uMeniskus = self::meniskusGraduated($resolusi);
            $uTimbang = (float) $neraca['u95_g'] / 2;
        }

        $uMassa = sqrt($uTimbang ** 2 + ((float) $neraca['stdev_g'] / sqrt(self::N_STDEV_NERACA)) ** 2);

        $ditolak = [];
        $olah = [];
        $batas = self::TITIK_MAKS[$keluarga];

        foreach ($titik as $t) {
            $ke = (int) $t['titik_ke'];

            if ($ke > $batas) {
                $ditolak[] = ['titik_ke' => $ke, 'alasan' => sprintf(
                    'Titik ke-%d melebihi batas %d titik untuk alat %s.', $ke, $batas,
                    $keluarga === self::KELUARGA_FIXED ? 'bernominal tunggal' : 'berskala',
                )];

                continue;
            }

            foreach (['kosong', 'isi', 'suhu'] as $nama) {
                if (count($t[$nama]) !== self::PENGULANGAN) {
                    $ditolak[] = ['titik_ke' => $ke, 'alasan' => sprintf(
                        'Titik ke-%d: deret %s berisi %d angka, harus tepat %d.',
                        $ke, $nama, count($t[$nama]), self::PENGULANGAN,
                    )];

                    continue 2;
                }
            }

            $massa = [];
            $suhu = [];
            $rhoAir = [];
            $koreksi = [];

            for ($i = 0; $i < self::PENGULANGAN; $i++) {
                $m = (float) $t['isi'][$i] - (float) $t['kosong'][$i];
                if ($m <= 0) {
                    $ditolak[] = ['titik_ke' => $ke, 'alasan' => sprintf(
                        'Titik ke-%d ulangan %d: berat berisi air (%s g) tidak lebih besar dari berat '
                        .'kosong (%s g) — kemungkinan kedua deret tertukar.',
                        $ke, $i + 1, $t['isi'][$i], $t['kosong'][$i],
                    )];

                    continue 2;
                }

                $k = $tabel->koreksiSuhu((float) $t['suhu'][$i]);
                if ($k === null) {
                    $ditolak[] = ['titik_ke' => $ke, 'alasan' => sprintf(
                        'Titik ke-%d ulangan %d: suhu %s °C tidak punya koreksi sensor di tabel standar.',
                        $ke, $i + 1, $t['suhu'][$i],
                    )];

                    continue 2;
                }

                $massa[] = $m;
                $suhu[] = $k['terkoreksi_c'];
                $koreksi[] = $k;
                $rhoAir[] = self::densitasAirSuling($k['terkoreksi_c']);
            }

            $v20 = [];
            foreach ($massa as $i => $m) {
                $v20[] = self::v20($m, $rhoAir[$i], $rhoUdara, $gamma, $suhu[$i]);
            }

            $massaRata = self::rata($massa);
            $suhuRata = self::rata($suhu);
            $rhoAirRata = self::rata($rhoAir);

            // Fixed mencetak V20 dari RATA-RATA (`PERHITUNGAN!H60`: massa N29,
            // suhu N35, ρ air N45); Graduated mencetak rata-rata V20 per
            // ulangan (`H53`). Bedanya di digit ke-16 untuk contoh master,
            // tapi itu dua rumus yang berbeda dan masing-masing ditiru.
            $v20Terbit = $keluarga === self::KELUARGA_FIXED
                ? self::v20($massaRata, $rhoAirRata, $rhoUdara, $gamma, $suhuRata)
                : self::rata($v20);

            $olah[] = [
                'titik_ke' => $ke,
                'nominal' => (float) $t['nominal'],
                'massa_per_ulangan' => $massa,
                'suhu_terkoreksi_per_ulangan' => $suhu,
                'koreksi_suhu' => $koreksi,
                'rho_air_per_ulangan' => $rhoAir,
                'v20_per_ulangan' => $v20,
                'massa_rata_rata' => $massaRata,
                'suhu_rata_rata' => $suhuRata,
                'rho_air_rata_rata' => $rhoAirRata,
                'v20' => $v20Terbit,
                'deviasi' => $v20Terbit - (float) $t['nominal'],
                'stdev_v20' => self::stdev($v20),
            ];
        }

        if ($keluarga === self::KELUARGA_GRADUATED) {
            if ($ditolak !== []) {
                // Satu budget untuk semua titik — lihat docblock. Titik yang
                // sehat ikut ditahan, dengan alasan yang menyebut sebabnya.
                foreach ($olah as $o) {
                    $ditolak[] = ['titik_ke' => $o['titik_ke'], 'alasan' => sprintf(
                        'Titik ke-%d ditahan: budget alat berskala satu untuk semua titik, dan titik lain '
                        .'di sesi ini ditolak.', $o['titik_ke'],
                    )];
                }
                $olah = [];
            } elseif (count($olah) < 2) {
                foreach ($olah as $o) {
                    $ditolak[] = ['titik_ke' => $o['titik_ke'], 'alasan' => 'Alat berskala butuh minimal dua '
                        .'titik. Keterulangan budget-nya adalah STDEV dari simpangan baku per titik, dan '
                        .'STDEV satu angka tidak terdefinisi (pertanyaan lab no. 12).'];
                }
                $olah = [];
            }
        }

        $u95Suhu = $tabel->u95Suhu();
        $praolah = [
            'keluarga' => $keluarga,
            'rho_udara' => $rhoUdara,
            'suhu_ruang' => $lingkungan['suhu'],
            'kelembaban' => $lingkungan['kelembaban'],
            'tekanan' => $lingkungan['tekanan'],
            'gamma' => $gamma,
            'kelas' => strtoupper(trim((string) $blok['kelas'])),
            'neraca' => $neraca,
            'u_timbang' => $uTimbang,
            'u_massa' => $uMassa,
            'u_meniskus' => $uMeniskus,
            'diameter_mm' => $diameter,
            'u95_termometer' => $u95Suhu['termometer_c'],
            'u95_sensor' => $u95Suhu['sensor_c'],
        ];

        usort($ditolak, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        if ($olah === []) {
            return ['boleh_terbit' => false, 'ditolak' => $ditolak, 'praolah' => $praolah, 'titik' => []];
        }

        $gum = app(GumCalculator::class);
        $hasil = [];

        if ($keluarga === self::KELUARGA_FIXED) {
            foreach ($olah as $o) {
                $rentang = max($o['suhu_terkoreksi_per_ulangan']) - min($o['suhu_terkoreksi_per_ulangan']);
                $masukan = [
                    'massa' => $o['massa_rata_rata'],
                    'rho_udara' => $rhoUdara,
                    'rho_air' => $o['rho_air_rata_rata'],
                    'suhu_air' => $o['suhu_rata_rata'],
                    'gamma' => $gamma,
                    'u_massa' => $uMassa,
                    'u_suhu' => self::uSuhu($u95Suhu['termometer_c'], $u95Suhu['sensor_c'], $rentang),
                    'u_meniskus' => $uMeniskus,
                    'u_rho_air' => self::U_DENSITAS_AIR[$keluarga],
                    'u_keterulangan' => $o['stdev_v20'] / sqrt(self::PENGULANGAN),
                    'tanda_ci_muai' => self::TANDA_CI_MUAI[$keluarga],
                ];
                $komponen = self::komponenBudget($masukan);
                $agregat = $gum->agregasiBudget($komponen);

                // Pembanding K4: master membagi Veff dengan baris TERAKHIR
                // (`K43`), bukan jumlahnya (`K44`).
                $akhir = $komponen[array_key_last($komponen)];
                $sukuAkhir = (($akhir['u'] * $akhir['ci']) ** 4) / $akhir['vi'];

                $hasil[] = $o + [
                    'rentang_suhu' => $rentang,
                    'masukan_budget' => $masukan,
                    'komponen_budget' => $komponen,
                    'agregat' => $agregat,
                    'pembanding_master' => [
                        'veff_dibagi_baris_akhir' => $sukuAkhir > 0
                            ? ($agregat['ketidakpastian_gabungan'] ** 4) / $sukuAkhir
                            : null,
                    ],
                ];
            }
        } else {
            $semuaSuhu = array_merge(...array_column($olah, 'suhu_terkoreksi_per_ulangan'));
            $rentang = max($semuaSuhu) - min($semuaSuhu);
            $stdevPerTitik = array_column($olah, 'stdev_v20');

            $masukan = [
                'massa' => max(array_column($olah, 'massa_rata_rata')),
                'rho_udara' => $rhoUdara,
                'rho_air' => max(array_column($olah, 'rho_air_rata_rata')),
                'suhu_air' => self::rata($semuaSuhu),
                'gamma' => $gamma,
                'u_massa' => $uMassa,
                'u_suhu' => self::uSuhu($u95Suhu['termometer_c'], $u95Suhu['sensor_c'], $rentang),
                'u_meniskus' => $uMeniskus,
                'u_rho_air' => self::U_DENSITAS_AIR[$keluarga],
                'u_keterulangan' => self::stdev($stdevPerTitik) / sqrt(self::PENGULANGAN),
                'tanda_ci_muai' => self::TANDA_CI_MUAI[$keluarga],
            ];
            $komponen = self::komponenBudget($masukan);
            $agregat = $gum->agregasiBudget($komponen);

            // Pembanding K3: keterulangan master dengan lima nol hantu.
            $h55Master = self::stdev(array_merge($stdevPerTitik, array_fill(0, self::NOL_HANTU_MASTER, 0.0)));
            $agregatMaster = $gum->agregasiBudget(self::komponenBudget(
                ['u_keterulangan' => $h55Master / sqrt(self::PENGULANGAN)] + $masukan,
            ));

            foreach ($olah as $o) {
                $hasil[] = $o + [
                    'rentang_suhu' => $rentang,
                    'masukan_budget' => $masukan,
                    'komponen_budget' => $komponen,
                    'agregat' => $agregat,
                    'pembanding_master' => [
                        'stdev_keterulangan_nol_hantu' => $h55Master,
                        'stdev_keterulangan_benar' => self::stdev($stdevPerTitik),
                        'u95_nol_hantu' => $agregatMaster['ketidakpastian_diperluas'],
                    ],
                ];
            }
        }

        return ['boleh_terbit' => true, 'ditolak' => $ditolak, 'praolah' => $praolah, 'titik' => $hasil];
    }

    /** @param  list<float>  $x */
    private static function rata(array $x): float
    {
        return array_sum($x) / count($x);
    }

    /**
     * Simpangan baku sampel (`STDEV` Excel, pembagi n−1). `0.0` untuk n < 2 —
     * pemanggil yang membutuhkan n ≥ 2 wajib menolak lebih dulu.
     *
     * @param  list<float>  $x
     */
    private static function stdev(array $x): float
    {
        $n = count($x);
        if ($n < 2) {
            return 0.0;
        }
        $rata = array_sum($x) / $n;

        return sqrt(array_sum(array_map(static fn (float $v): float => ($v - $rata) ** 2, $x)) / ($n - 1));
    }
}
