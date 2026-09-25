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
     * RRPE di atas ini diangkat sebagai peringatan (%).
     *
     * Panduan §8.2 butir 2 menyebut "biasanya < 0,5%". Angka ini BUKAN batas
     * keberterimaan — alat gaya memang tidak divonis PASS/FAIL di repo ini —
     * melainkan ambang "pantas dilihat manusia". Sesi contoh UTM tertinggi
     * 0,034% dan Load Cell 1,0%, jadi yang Load Cell memang ikut tersorot, dan
     * itu benar: sepuluh titiknya di luar rentang tabel standar.
     */
    public const RRPE_PANTAS_DILIHAT = 0.5;

    /**
     * Ambang z-skor MAD untuk menandai satu bacaan menyimpang.
     *
     * Panduan §8.3. MAD dipakai, bukan STDEV, karena satu salah ketik ikut
     * menggelembungkan STDEV-nya sendiri sehingga menyamarkan dirinya.
     *
     * Yang ditandai TIDAK PERNAH dibuang — ISO/IEC 17025 klausul 7.5.2, dan
     * AGENTS.md §Peran butir 5. Manusia yang memutuskan: salah ketik (koreksi,
     * dengan jejak) atau memang begitu bacaannya.
     */
    public const Z_MAD_MENYIMPANG = 3.5;

    /**
     * Seberapa jauh baris tabel standar boleh meleset dari beban yang diminta,
     * sebagai pecahan beban itu, sebelum diangkat jadi temuan.
     *
     * Sepuluh persen, dan angkanya dipilih supaya yang tersorot cuma yang
     * benar-benar tidak tertelusur. Sesi UTM meleset 0,03% (baris 1,96133 kN
     * untuk beban 1,962 kN) — jauh di bawah ambang. Proving Ring yang
     * dikalibrasi dengan standar 3000 kN meleset 100% di tiap titik, karena
     * baris terdekatnya nol dan baris berikutnya 300 kN.
     */
    public const JARAK_SET_POINT_PANTAS_DILIHAT = 0.10;

    /** Faktor baku yang membuat MAD sebanding dengan simpangan baku normal. */
    private const MAD_KE_SIGMA = 0.6745;

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
     * Indeks bacaan yang menyimpang jauh dari tetangganya, lewat z-skor MAD.
     *
     * Kenapa MAD dan bukan simpangan baku: satu ketikan `250,1` yang mestinya
     * `200,1` ikut menggelembungkan STDEV-nya sendiri, sehingga z-skor
     * berbasis STDEV justru mengecil dan nilai itu lolos. Median dan MAD tidak
     * ikut tergeser oleh satu nilai.
     *
     * MAD nol (mayoritas bacaan identik) memulangkan daftar KOSONG, bukan
     * menandai semua yang berbeda: pembagi nol di sana melahirkan tak-hingga,
     * dan lembar yang seluruh bacaannya identik sudah punya peringatannya
     * sendiri.
     *
     * @param  array<int, float>  $nilai
     * @return list<int> indeks 0-basis
     */
    public static function menyimpangMad(array $nilai): array
    {
        if (count($nilai) < 3) {
            return [];
        }

        $median = self::median($nilai);
        $simpangan = array_map(static fn (float $x): float => abs($x - $median), $nilai);
        $mad = self::median($simpangan);

        // MAD NOL adalah keadaan NORMAL di sini, bukan kasus pinggiran.
        //
        // Bentuk data gaya yang paling lazim: satu posisi membaca beda, sebelas
        // bacaan lain identik. Sesi contoh Load Cell titik 2 kN begitu —
        // sembilan kali 2,16 dan tiga kali 2,14 — dan median simpangannya nol
        // karena mayoritasnya berimpit dengan median.
        //
        // Berhenti di sini berarti detektornya mati justru pada bentuk data
        // yang paling sering muncul. Jadi skalanya diambil dari simpangan yang
        // BUKAN nol: itu ukuran "seberapa jauh bacaan yang memang berbeda
        // biasanya berbeda", dan si salah ketik tetap menonjol jauh di atasnya.
        //
        // Yang TIDAK tertangkap cara ini, dan ditulis di sini supaya tidak
        // ditemukan ulang sebagai kejutan: satu bacaan nyasar di antara SEBELAS
        // yang identik. Simpangan bukan-nol-nya cuma ada satu, jadi dia jadi
        // skalanya sendiri dan z-nya selalu 0,67. Keadaan itu tertangkap RRPE
        // di tingkat titik — yang hilang cuma penyebutan bacaan keberapa.
        if ($mad <= 0.0) {
            $bukanNol = array_values(array_filter($simpangan, static fn (float $d): bool => $d > 0.0));

            if ($bukanNol === []) {
                return [];
            }

            $mad = self::median($bukanNol);
        }

        $menyimpang = [];

        foreach (array_values($nilai) as $i => $x) {
            if (abs($x - $median) / $mad * self::MAD_KE_SIGMA > self::Z_MAD_MENYIMPANG) {
                $menyimpang[] = $i;
            }
        }

        return $menyimpang;
    }

    /** @param  array<int, float>  $nilai */
    public static function median(array $nilai): float
    {
        $urut = array_values($nilai);
        sort($urut);
        $n = count($urut);

        if ($n === 0) {
            return 0.0;
        }

        $tengah = intdiv($n, 2);

        return $n % 2 === 1
            ? $urut[$tengah]
            : ($urut[$tengah - 1] + $urut[$tengah]) / 2;
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

        $jauh = self::temuanJarakSetPoint($std, $B, "{$nominal} {$satuan}");

        if ($jauh !== null) {
            $temuan[] = $jauh;
        }

        if (count($bacaan) > 1 && count(array_unique($bacaan, SORT_REGULAR)) === 1) {
            $temuan[] = sprintf(
                '%d pembacaan pada titik %s %s identik semua — mesin uji nyata biasanya bervariasi di digit terakhir.',
                count($bacaan),
                $nominal,
                $satuan,
            );
        }

        // Panduan §8.2 butir 3 — yang paling sering menyelamatkan.
        //
        // Salah ketik satu digit (250,1 alih-alih 200,1) lolos SEMUA
        // pemeriksaan rentang: nilainya masuk akal untuk alat 500 kgf. Yang
        // membongkarnya cuma membandingkannya ke sebelas tetangganya.
        foreach (self::menyimpangMad($bacaan) as $i) {
            $temuan[] = sprintf(
                'Bacaan ke-%d pada titik %s %s (%s) menyimpang jauh dari yang lain (median %s) — '
                .'kemungkinan salah ketik. Nilainya TIDAK diubah; Master Data yang memutuskan.',
                $i + 1,
                $nominal,
                $satuan,
                $bacaan[$i],
                self::median($bacaan),
            );
        }

        // Panduan §8.2 butir 6. Dipisah dari "baca nol" di atas: nol berarti
        // alat tidak merespons, negatif berarti arahnya terbalik — dua sebab
        // berbeda yang perlu dua kalimat berbeda buat orang di lapangan.
        $negatif = array_values(array_filter(
            array_keys($bacaan),
            static fn (int $i): bool => $bacaan[$i] < 0.0,
        ));

        if ($B > 0.0 && $negatif !== []) {
            $temuan[] = sprintf(
                'Bacaan negatif pada titik %s %s (ke-%s) padahal bebannya positif — periksa arah beban atau pemasangan.',
                $nominal,
                $satuan,
                implode(', ', array_map(static fn (int $i): int => $i + 1, $negatif)),
            );
        }

        // Panduan §8.2 butir 2. Bukan vonis: alat gaya tidak punya toleransi di
        // repo ini, jadi yang bisa dilakukan cuma menyorot.
        if ($AB !== null && $AB > self::RRPE_PANTAS_DILIHAT) {
            $temuan[] = sprintf(
                'RRPE %s%% pada titik %s %s (biasanya di bawah %s%%) — periksa kondisi mesin.',
                round($AB, 3),
                $nominal,
                $satuan,
                self::RRPE_PANTAS_DILIHAT,
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
     * Temuan "baris tabel yang terpilih terlalu jauh dari bebannya".
     *
     * Dipisah jadi helper karena DUA rantai memakainya, dan pesannya harus
     * sama persis: yang membaca jejak sesi tidak boleh menebak apakah dua
     * kalimat berbeda berarti dua keadaan berbeda.
     *
     * Diam kalau titiknya sudah ditandai `di_luar_rentang` — dua peringatan
     * untuk satu sebab melatih orang berhenti membaca keduanya.
     *
     * @param  array<string, mixed>  $std
     */
    private static function temuanJarakSetPoint(array $std, float $B, string $bebanTertulis): ?string
    {
        if ($std['di_luar_rentang'] || $B <= 0.0) {
            return null;
        }

        $jarak = (float) ($std['jarak_ke_set_point_kn'] ?? 0.0);

        if ($jarak / $B <= self::JARAK_SET_POINT_PANTAS_DILIHAT) {
            return null;
        }

        return sprintf(
            'Baris tabel standar yang terpilih (%s kN) meleset %s%% dari beban %s. Koreksinya tidak '
            .'mewakili beban ini — periksa apakah standarnya sesuai kapasitas alat.',
            $std['set_point_kn'],
            round($jarak / $B * 100, 1),
            $bebanTertulis,
        );
    }

    /**
     * Suhu acuan koreksi ruangan Proving Ring (°C).
     *
     * Master: `G52 = 1 + 0,00027 x (23 - suhu ruangan)`. Acuannya 23 °C dan
     * dipatok di rumusnya, bukan diambil dari sertifikat standar — beda dari
     * koreksi termal yang sudah ada, yang acuannya suhu sertifikat kalibrator.
     *
     * Proving Ring memakai KEDUANYA pada rantai yang sama. Itu ganjil dan sudah
     * diangkat sebagai pertanyaan lab G6; ditiru apa adanya.
     */
    public const SUHU_ACUAN_RUANGAN = 23.0;

    /**
     * Faktor koreksi ruangan Proving Ring.
     *
     * Dipakai DUA kali di rantainya, dan itu bukan salah ketik: sekali pada
     * pembacaan alat (`J`), sekali pada nilai standar (`Y`). Master melakukan
     * hal yang sama — `AVERAGE(C:I)*$G$52` dan `(B+W)*$G$52`.
     */
    public static function faktorRuangan(float $suhuRuangRata): float
    {
        return 1 + TabelStandarGaya::koefisienSuhu() * (self::SUHU_ACUAN_RUANGAN - $suhuRuangRata);
    }

    /**
     * Satu titik PROVING RING — rantai yang BERBEDA dari UTM & Load Cell.
     *
     * ## Tiga hal yang membuatnya tidak bisa menumpang `hitungTitik()`
     *
     * 1. **Pembacaannya bukan gaya.** Yang dibaca teknisi jumlah DIVISI pada
     *    dial (`237`, `726`, …), bukan kgf atau kN. Jadi tidak ada konversi
     *    satuan sama sekali di sisi UUT, dan rata-ratanya tetap bersatuan divisi.
     *
     * 2. **Keluarannya FAKTOR, bukan koreksi.** Yang dicetak sertifikat
     *    `Calibration Factor = Z / J` — berapa kN per satu divisi. Selisih
     *    `standar − UUT` tidak punya arti di sini: dua besaran yang berbeda.
     *
     * 3. **Enam bacaan, bukan dua belas.** UP 3x lalu DOWN 3x, karena cincin
     *    bajanya punya histeresis. Tidak ada empat posisi.
     *
     * Dan sebaran yang dilaporkan RSD (`L`), bukan RRPE — lagi-lagi karena
     * pembaginya rata-rata pembacaan, bukan beban nominal.
     *
     * @param  array<int, float>  $bacaanDiv  pembacaan dial, dalam DIVISI
     * @return array{
     *     B: float, J: float, K: float, L: float|null, W: float|null,
     *     Y: float|null, Z: float|null, CF: float|null,
     *     set_point_standar_kn: float|null, di_luar_rentang_tabel: bool,
     *     bacaan_div: array<int, float>, temuan: array<int, string>
     * }
     */
    public static function hitungTitikProvingRing(
        float $setPointKn,
        array $bacaanDiv,
        string $kunciStandar,
        string $arah,
        float $suhuRuangRata,
        float $suhuSertifikatStandar,
        float $suhuStandarAktual,
    ): array {
        $g52 = self::faktorRuangan($suhuRuangRata);

        $B = $setPointKn;
        $J = self::rata($bacaanDiv) * $g52;
        $K = self::stdev($bacaanDiv);

        // RSD di sini dibagi rata-rata PEMBACAAN, bukan beban nominal — beda
        // dari `rsd()` yang dipakai UTM & Load Cell. Titik nol tetap tidak
        // punya sebaran, dan itu normal.
        $L = $J == 0.0 ? null : $K / $J * 100;

        $temuan = [];

        if ($B > 0.0 && $J == 0.0) {
            $temuan[] = "Pembacaan nol pada beban {$setPointKn} kN — dial tidak bergerak.";
        }

        foreach (self::menyimpangMad($bacaanDiv) as $i) {
            $temuan[] = sprintf(
                'Bacaan ke-%d pada titik %s kN (%s divisi) menyimpang jauh dari yang lain '
                .'(median %s) — kemungkinan salah ketik. Nilainya TIDAK diubah.',
                $i + 1,
                $setPointKn,
                $bacaanDiv[$i],
                self::median($bacaanDiv),
            );
        }

        $std = TabelStandarGaya::koreksi($B, $kunciStandar, $arah);

        if ($std === null) {
            return [
                'B' => $B, 'J' => $J, 'K' => $K, 'L' => $L,
                'W' => null, 'Y' => null, 'Z' => null, 'CF' => null,
                'set_point_standar_kn' => null,
                'di_luar_rentang_tabel' => false,
                'bacaan_div' => $bacaanDiv,
                'temuan' => [...$temuan, "Standar `{$kunciStandar}` nggak punya tabel arah `{$arah}`."],
            ];
        }

        $W = $std['koreksi_kn'];
        $Y = ($B + $W) * $g52;
        $Z = self::koreksiTermal($Y, $suhuSertifikatStandar, $suhuStandarAktual);
        $CF = $J == 0.0 ? null : $Z / $J;

        if ($std['di_luar_rentang']) {
            $temuan[] = sprintf(
                'Beban %s kN di luar rentang tabel standar — koreksi diambil dari titik terdekat (%s kN), '
                .'bukan interpolasi tervalidasi.',
                $setPointKn,
                $std['set_point_kn'],
            );
        }

        $jauh = self::temuanJarakSetPoint($std, $B, "{$setPointKn} kN");

        if ($jauh !== null) {
            $temuan[] = $jauh;
        }

        if (count($bacaanDiv) > 1 && count(array_unique($bacaanDiv, SORT_REGULAR)) === 1) {
            $temuan[] = sprintf(
                '%d pembacaan pada titik %s kN identik semua — dial nyata biasanya bervariasi satu divisi.',
                count($bacaanDiv),
                $setPointKn,
            );
        }

        return [
            'B' => $B, 'J' => $J, 'K' => $K, 'L' => $L,
            'W' => $W, 'Y' => $Y, 'Z' => $Z, 'CF' => $CF,
            'set_point_standar_kn' => $std['set_point_kn'],
            'di_luar_rentang_tabel' => $std['di_luar_rentang'],
            'bacaan_div' => $bacaanDiv,
            'temuan' => $temuan,
        ];
    }

    /**
     * Budget PROVING RING — delapan komponen dihitung, ENAM dijumlahkan.
     *
     * ## Yang beda dari UTM & Load Cell, dan semuanya diadu ke master
     *
     * | Komponen | UTM / Load Cell | Proving Ring |
     * |---|---|---|
     * | Daya baca alat | resolusi gaya / rentang | resolusi DIAL (mm) / kapasitas dial (mm) |
     * | Pengulangan | RSD / akar 12 | RSD / akar 6 |
     * | Misalignment | dibagi akar 3, ci = 1 | TIDAK dibagi, ci = 2 |
     * | Zero error | ci = 1 | ci = 2 |
     * | Yang dijumlahkan | delapan | **enam** |
     *
     * ## Enam dari delapan — dan itu bukan tafsir
     *
     * `Jumlah` di master = 0,04703237424735414. Menjumlahkan kuadrat kedelapan
     * `uici` memberi 0,048801243938405243; menjumlahkan yang ENAM pertama
     * memberi 0,04703237424735414 — **beda nol**. Selisihnya persis suku
     * misalignment (0,04205793²). Jadi zero error dan misalignment memang
     * berada di luar penjumlahannya.
     *
     * Zero error kebetulan bernilai nol di sesi contoh, jadi dari angka saja
     * tidak bisa dibedakan "sengaja dikecualikan" dari "kebetulan tidak
     * menyumbang". Yang pasti terbukti cuma misalignment. Keduanya tetap
     * DIHITUNG dan disimpan di jejak audit supaya kontribusinya yang hilang
     * bisa dibaca tanpa membuka kode — itu G8, dan lab yang memutuskan.
     *
     * @param  array<string, mixed>  $blok
     * @return array{dijumlahkan: list<array<string, mixed>>, di_luar_jumlah: list<array<string, mixed>>}
     */
    public static function komponenBudgetProvingRing(
        array $blok,
        float $rsdMaks,
        float $rentangKn,
        float $u95Standar,
        ?float $drift,
    ): array {
        $akar3 = sqrt(3);
        $mis = $blok['misalignment'] ?? [];

        $resolusiDialMm = (float) ($blok['resolusi_dial_mm'] ?? 0);
        $kapasitasDialMm = (float) ($blok['kapasitas_dial_mm'] ?? 0);
        $resolusiStdKn = (float) ($blok['resolusi_standar'] ?? 0);
        $kapasitasStdKn = (float) ($blok['kapasitas_standar'] ?? 0);

        $zeroKn = self::keKn(
            GayaMentah::zeroErrorMaks($blok['preload_zero'] ?? []),
            (string) $blok['satuan'],
        );

        $bagi = static fn (float $atas, float $bawah): float => $bawah === 0.0 ? 0.0 : $atas / $bawah;

        $uMisalignment = 0.0;
        if ($mis !== [] && self::rata($mis) !== 0.0) {
            // TANPA divisor — master Proving Ring menulis Divisor = 1 di baris
            // ini, sementara UTM & Load Cell membaginya akar 3. Ditiru apa
            // adanya; toh komponen ini tidak ikut dijumlahkan.
            $uMisalignment = self::stdev($mis) / self::rata($mis) * 100;
        }

        return [
            'dijumlahkan' => [
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
                    'keterangan' => 'Resolusi DIAL proving ring terhadap kapasitas dial (mm)',
                    'distribusi' => 'rectangular',
                    'u' => ($bagi($resolusiDialMm, $kapasitasDialMm) * 100 / 2) / $akar3,
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
                    'sumber' => 'drift_standar',
                    'keterangan' => 'Drift standar (master tidak membagi divisornya — pertanyaan lab G3)',
                    'distribusi' => 'rectangular',
                    'u' => (float) ($drift ?? 0.0),
                    'ci' => 1.0,
                    'vi' => 50.0,
                ],
                [
                    'sumber' => 'pengulangan',
                    'keterangan' => 'RSD terbesar antar titik dibagi akar 6 (UP 3x + DOWN 3x)',
                    'distribusi' => 'normal',
                    'u' => $rsdMaks / sqrt(GayaMentah::REPLIKAT * 2),
                    'ci' => 1.0,
                    'vi' => 5.0,
                ],
            ],
            'di_luar_jumlah' => [
                [
                    'sumber' => 'zero_error',
                    'keterangan' => 'Zero error sesudah preload — DIHITUNG tapi tidak ikut dijumlahkan master (G8)',
                    'distribusi' => 'rectangular',
                    'u' => $bagi($zeroKn, $rentangKn) * 100,
                    'ci' => 2.0,
                    'vi' => 5.0,
                ],
                [
                    'sumber' => 'misalignment',
                    'keterangan' => 'Sebaran misalignment — DIHITUNG tapi tidak ikut dijumlahkan master (G8)',
                    'distribusi' => 'rectangular',
                    'u' => $uMisalignment,
                    'ci' => 2.0,
                    'vi' => 5.0,
                ],
            ],
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
