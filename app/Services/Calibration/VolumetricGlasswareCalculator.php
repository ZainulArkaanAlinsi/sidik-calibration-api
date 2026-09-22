<?php

namespace App\Services\Calibration;

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
}
