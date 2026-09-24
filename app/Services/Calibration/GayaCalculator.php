<?php

namespace App\Services\Calibration;

use App\Support\GayaMentah;
use InvalidArgumentException;

/**
 * Fungsi inti olah data GAYA — dipakai bertiga: UTM, Load Cell, Proving Ring.
 *
 * ## Kenapa gaya beda dari alat lain di repo ini
 *
 * Gaya tidak punya "anak timbangan gaya" — dia interaksi, bukan benda. Jadi
 * kalibrasinya tak langsung: mesin uji (UUT) menekan load cell STANDAR yang
 * tertelusur, keduanya menerima gaya fisik yang sama, dan selisih bacaannya
 * adalah kesalahan UUT.
 *
 * Konsekuensinya buat kode: **selalu dua angka per titik** — nilai UUT dan
 * nilai standar. Model yang cuma menyimpan satu angka per titik tidak cukup.
 *
 * ## Rantai hitungnya, dan satu urutan yang gampang dibalik
 *
 * ```
 * B  = nominal UUT dikonversi ke kN
 * R  = rata-rata 12 bacaan (4 posisi x 3 replikat), dalam kN
 * S  = simpangan baku 12 bacaan
 * T  = RSD  = S/R x 100         <- masuk BUDGET
 * AB = RRPE = (MAX-MIN)/B x 100 <- yang DICETAK di sertifikat
 * W  = koreksi standar, nearest-match dari tabel
 * Y  = R + W
 * Z  = Y x (1 + 0,00027 x (T_sertifikat - T_aktual))
 * AA = Y - B                    <- Y, BUKAN Z
 * ```
 *
 * `T` dan `AB` gampang tertukar: dua-duanya "sebaran", tapi yang satu memakai
 * simpangan baku dan yang satu rentang penuh. Yang tercetak di sertifikat RRPE;
 * yang masuk budget RSD. Tertukar tidak menghasilkan error, cuma angka yang
 * salah di kolom yang benar.
 *
 * `AA` memakai `Y` sementara sertifikat mencetak `Z` di kolom Standard Value.
 * Itu ANOMALI MASTER yang direplikasi apa adanya: pembaca sertifikat yang
 * menghitung `Standard Value - UUT` tidak akan mendapat angka di kolom
 * Correction (selisih 0,00066 kN di sesi contoh). Sudah diangkat jadi
 * pertanyaan lab; jangan "dibetulkan" sendiri karena itu menggeser angka yang
 * sudah tercetak di sertifikat pelanggan.
 *
 * ## Titik nol: tiga kasus, bukan satu
 *
 * Master menghindari pembagian nol dengan MENGETIK angka mati di baris titik
 * nol (`RSD = 0`, `RRPE = "-"`), bukan dengan rumus. Meniru itu dengan
 * `$rata ?: 0` kelihatan aman tapi salah: dia menyamakan "titik nol memang
 * tidak punya RSD" dengan "alat baca nol padahal dibebani 500 kgf". Yang kedua
 * TEMUAN, dan menyembunyikannya berarti sesi rusak lolos ke sertifikat.
 */
class GayaCalculator
{
    /** Titik nominal nol: tidak punya RSD maupun RRPE, dan itu normal. */
    private const NOL = 0.0;

    /**
     * Konversi nilai gaya ke kN.
     *
     * Satuan yang tidak dikenal DILEMPAR, bukan dianggap 1. Master menjawab
     * string `"PILIH SATUAN"` waktu satuannya belum dipilih, dan string itu
     * bisa ikut mengalir ke hasil — jadi angka yang tercetak lahir dari satuan
     * yang tidak pernah ditentukan.
     */
    public static function keKn(float $nilai, string $satuan): float
    {
        $faktor = TabelStandarGaya::faktorSatuan($satuan);

        if ($faktor === null) {
            $dikenal = implode(', ', TabelStandarGaya::satuanYangDikenal());

            throw new InvalidArgumentException(
                "Satuan gaya `{$satuan}` nggak dikenal. Yang ada: {$dikenal}."
            );
        }

        return $nilai * $faktor;
    }

    /**
     * Koreksi termal load cell standar.
     *
     * Strain gauge berubah hambatannya karena suhu, bukan cuma karena regangan.
     * Sertifikat standar dibuat pada satu suhu; dipakai di suhu lain, bacaannya
     * bergeser. Kecil — 0,034% di sesi contoh — tapi pada 3000 kN itu 1 kN.
     */
    public static function koreksiTermal(float $nilai, float $suhuSertifikat, float $suhuAktual): float
    {
        return $nilai * (1 + TabelStandarGaya::koefisienSuhu() * ($suhuSertifikat - $suhuAktual));
    }

    /**
     * Rata-rata aritmetik.
     *
     * @param  array<int, float>  $nilai
     */
    public static function rata(array $nilai): float
    {
        return $nilai === [] ? 0.0 : array_sum($nilai) / count($nilai);
    }

    /**
     * Simpangan baku SAMPEL (pembagi n-1), sama dengan `STDEV` Excel.
     *
     * @param  array<int, float>  $nilai
     */
    public static function stdev(array $nilai): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        $rata = self::rata($nilai);
        $jumlah = 0.0;

        foreach ($nilai as $x) {
            $jumlah += ($x - $rata) ** 2;
        }

        return sqrt($jumlah / ($n - 1));
    }

    /**
     * RSD (%) — tiga kasus yang sengaja dibedakan.
     *
     * - nominal nol -> 0, karena titik nol memang tidak punya RSD;
     * - rata-rata nol di titik NON-nol -> `null`, dan itu temuan: alatnya baca
     *   nol padahal dibebani. Yang memutuskan manusia, bukan `?: 0`;
     * - selebihnya -> S/R x 100.
     */
    public static function rsd(float $stdev, float $rataKn, float $nominalKn): ?float
    {
        if ($nominalKn === self::NOL) {
            return 0.0;
        }

        if ($rataKn === self::NOL) {
            return null;
        }

        return $stdev / $rataKn * 100;
    }

    /**
     * RRPE (%) — `(MAX-MIN)/nominal x 100`, yang DICETAK di sertifikat.
     *
     * `null` di titik nol; sertifikat mencetaknya "-", persis master.
     *
     * @param  array<int, float>  $bacaanKn
     */
    public static function rrpe(array $bacaanKn, float $nominalKn): ?float
    {
        if ($nominalKn === self::NOL || $bacaanKn === []) {
            return null;
        }

        return (max($bacaanKn) - min($bacaanKn)) / $nominalKn * 100;
    }

    /**
     * Satu titik beban, dari 12 bacaan mentah sampai angka sertifikat.
     *
     * @param  array<int, float>  $bacaan  bacaan UUT dalam satuan aslinya (belum kN)
     * @return array{
     *     B: float, R: float, S: float, T: float|null, W: float|null,
     *     Y: float|null, Z: float|null, AA: float|null, AB: float|null,
     *     set_point_standar_kn: float|null, di_luar_rentang_tabel: bool,
     *     bacaan_kn: array<int, float>, temuan: array<int, string>
     * }
     */
    public static function hitungTitik(
        float $nominal,
        array $bacaan,
        string $satuan,
        string $kunciStandar,
        string $arah,
        float $suhuSertifikatStandar,
        float $suhuStandarAktual,
    ): array {
        $B = self::keKn($nominal, $satuan);
        $bacaanKn = array_map(static fn (float $x): float => self::keKn($x, $satuan), $bacaan);

        $R = self::rata($bacaanKn);
        $S = self::stdev($bacaanKn);
        $T = self::rsd($S, $R, $B);
        $AB = self::rrpe($bacaanKn, $B);

        $temuan = [];

        if ($T === null) {
            $temuan[] = "Pembacaan nol pada beban {$nominal} {$satuan} — alat tidak membaca beban.";
        }

        $std = TabelStandarGaya::koreksi($B, $kunciStandar, $arah);

        if ($std === null) {
            // Kombinasi standar x arah tanpa tabel BUKAN koreksi nol. Dibiarkan
            // null supaya pemanggil berhenti, bukan menerbitkan angka yang tidak
            // pernah ditelusuri ke sertifikat standar mana pun.
            return [
                'B' => $B, 'R' => $R, 'S' => $S, 'T' => $T,
                'W' => null, 'Y' => null, 'Z' => null, 'AA' => null, 'AB' => $AB,
                'set_point_standar_kn' => null,
                'di_luar_rentang_tabel' => false,
                'bacaan_kn' => $bacaanKn,
                'temuan' => [...$temuan, "Standar `{$kunciStandar}` nggak punya tabel arah `{$arah}`."],
            ];
        }

        $W = $std['koreksi_kn'];
        $Y = $R + $W;
        $Z = self::koreksiTermal($Y, $suhuSertifikatStandar, $suhuStandarAktual);
        $AA = $Y - $B;   // Y, BUKAN Z — lihat docblock kelas.

        if ($std['di_luar_rentang']) {
            $temuan[] = sprintf(
                'Beban %s %s di luar rentang tabel standar — koreksi diambil dari titik terdekat (%s kN), bukan interpolasi tervalidasi.',
                $nominal,
                $satuan,
                $std['set_point_kn'],
            );
        }

        if (count($bacaan) > 1 && count(array_unique($bacaan, SORT_REGULAR)) === 1) {
            $temuan[] = sprintf(
                '%d pembacaan pada titik %s %s identik semua — mesin uji nyata biasanya bervariasi di digit terakhir.',
                count($bacaan),
                $nominal,
                $satuan,
            );
        }

        return [
            'B' => $B, 'R' => $R, 'S' => $S, 'T' => $T,
            'W' => $W, 'Y' => $Y, 'Z' => $Z, 'AA' => $AA, 'AB' => $AB,
            'set_point_standar_kn' => $std['set_point_kn'],
            'di_luar_rentang_tabel' => $std['di_luar_rentang'],
            'bacaan_kn' => $bacaanKn,
            'temuan' => $temuan,
        ];
    }

    /**
     * Delapan komponen budget — dihitung SEKALI per sesi, bukan per titik.
     *
     * Itu bentuk masternya, dan bukan penyederhanaan: `RSD MAX` diambil dari
     * seluruh titik, zero error dari preload, misalignment dari empat
     * pengukuran tingkat-sesi. Tidak satu pun bisa dijawab dari satu titik saja.
     *
     * Komponen 5 (drift) SENGAJA tidak dibagi divisornya — replikasi anomali
     * master, lihat `GayaBudgetTest`. Nilainya dipilih per WORKBOOK karena
     * ketiganya tidak sepakat untuk standar yang sama.
     *
     * @param  array<string, mixed>  $blok  keluaran `GayaMentah::blokSesi()`
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>
     */
    public static function komponenBudget(
        array $blok,
        float $rsdMaks,
        float $rentangKn,
        float $u95Standar,
        ?float $drift,
    ): array {
        $akar3 = sqrt(3);
        $mis = $blok['misalignment'] ?? [];

        $resolusiUutKn = self::keKn((float) ($blok['resolusi_uut'] ?? 0), (string) $blok['satuan']);
        $resolusiStdKn = (float) ($blok['resolusi_standar'] ?? 0);
        $kapasitasStdKn = (float) ($blok['kapasitas_standar'] ?? 0);

        $zeroKn = self::keKn(
            GayaMentah::zeroErrorMaks($blok['preload_zero'] ?? []),
            (string) $blok['satuan'],
        );

        $uMisalignment = 0.0;
        if ($mis !== [] && self::rata($mis) !== 0.0) {
            $uMisalignment = self::stdev($mis) / self::rata($mis) * 100;
        }

        $bagi = static fn (float $atas, float $bawah): float => $bawah === 0.0 ? 0.0 : $atas / $bawah;

        return [
            [
                'sumber' => 'sertifikat_kalibrator',
                'keterangan' => 'Sertifikat load cell standar (U95% % reading, k=2)',
                'distribusi' => 'normal',
                'u' => $u95Standar / 2,
                'ci' => 1.0,
                'vi' => 200.0,
            ],
            [
                'sumber' => 'daya_baca_uut',
                'keterangan' => 'Resolusi alat yang dikalibrasi',
                'distribusi' => 'rectangular',
                'u' => ($bagi($resolusiUutKn, $rentangKn) * 100 / 2) / $akar3,
                'ci' => 1.0,
                'vi' => 1e6,
            ],
            [
                'sumber' => 'daya_baca_standar',
                'keterangan' => 'Resolusi load cell standar',
                'distribusi' => 'rectangular',
                'u' => ($bagi($resolusiStdKn, $kapasitasStdKn) * 100 / 2) / $akar3,
                'ci' => 1.0,
                'vi' => 1e6,
            ],
            [
                'sumber' => 'temperature',
                'keterangan' => 'Pengaruh suhu, konstanta metode 0,027%',
                'distribusi' => 'rectangular',
                'u' => 0.027 / $akar3,
                'ci' => 1.0,
                'vi' => 50.0,
            ],
            [
                // Divisor akar-3 TIDAK dipakai — master begitu, di ketiga
                // workbook. Membaginya menggeser U95% yang sudah tercetak.
                'sumber' => 'drift_standar',
                'keterangan' => 'Drift standar (master tidak membagi divisornya — pertanyaan lab)',
                'distribusi' => 'rectangular',
                'u' => (float) ($drift ?? 0.0),
                'ci' => 1.0,
                'vi' => 50.0,
            ],
            [
                'sumber' => 'pengulangan',
                'keterangan' => 'RSD terbesar antar titik dibagi akar jumlah pembacaan',
                'distribusi' => 'normal',
                'u' => $rsdMaks / sqrt(GayaMentah::REPLIKAT * count(GayaMentah::PERAN_POSISI)),
                'ci' => 1.0,
                'vi' => 11.0,
            ],
            [
                'sumber' => 'zero_error',
                'keterangan' => 'Zero error terbesar sesudah preload',
                'distribusi' => 'rectangular',
                'u' => ($bagi($zeroKn, $rentangKn) * 100) / $akar3,
                'ci' => 1.0,
                'vi' => 1e6,
            ],
            [
                'sumber' => 'misalignment',
                'keterangan' => 'Sebaran pengukuran misalignment piringan',
                'distribusi' => 'rectangular',
                'u' => $uMisalignment / $akar3,
                'ci' => 1.0,
                'vi' => 50.0,
            ],
        ];
    }
}
