<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Pembaca `database/data/tabel-standar-gaya.json` — tabel kalibrasi load cell
 * standar yang dipakai UTM, Load Cell, dan Proving Ring.
 *
 * Berkasnya DIGENERATE `docs/skrip/gen-tabel-standar-gaya.py` dari workbook
 * master; jangan disunting tangan. Tiga standar x dua arah = ~60 pasang angka,
 * dan satu digit yang meleset menggeser koreksi satu titik beban tanpa satu pun
 * error muncul.
 *
 * ## Nearest-match, BUKAN interpolasi
 *
 * Master mencari koreksi standar dengan `MATCH(MIN(ABS(...)))` — set point
 * terdekat, apa adanya. Mengganti ini dengan interpolasi linier menghasilkan
 * angka yang berbeda di hampir tiap titik, dan test rekonsiliasi ke master
 * bakal merah tanpa memberi tahu sebabnya.
 *
 * Aturan serinya ikut ditiru: kalau dua set point sama jauhnya, yang diambil
 * **yang lebih dulu di tabel** (perbandingan `<`, bukan `<=`), persis perilaku
 * `MATCH` Excel. Beban 2500 kg di antara set point 2000 dan 3000 mengambil
 * 2000 — dan bedanya cuma ketahuan di sesi yang kebetulan seri.
 *
 * ## Dua faktor kg->kN yang hidup berdampingan
 *
 * Set point tabel standar dihitung dengan `0,00980665` (nilai fisik eksak),
 * sementara pembacaan teknisi dikonversi `0,00981` dari `Tabel_Satuan`.
 * Keduanya memang beda di master dan TIDAK diseragamkan di sini: menyamakannya
 * menggeser hasil nearest-match di titik yang dekat batas antar set point.
 */
class TabelStandarGaya
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** Arah beban seperti yang dipakai master: Tekan / Tarik. */
    public const ARAH_PUSH = 'Push';

    public const ARAH_PULL = 'Pull';

    /** Faktor konversi satuan gaya ke kN, dari `Tabel_Satuan` master. */
    public static function faktorSatuan(string $satuan): ?float
    {
        $faktor = self::muat()['faktor_satuan'] ?? [];

        return isset($faktor[$satuan]) ? (float) $faktor[$satuan] : null;
    }

    /** @return array<int, string> */
    public static function satuanYangDikenal(): array
    {
        return array_keys(self::muat()['faktor_satuan'] ?? []);
    }

    /** Koefisien suhu load cell, 0,00027 per °C. */
    public static function koefisienSuhu(): float
    {
        return (float) (self::muat()['koefisien_suhu_per_c'] ?? 0.00027);
    }

    /** @return array<string, mixed>|null */
    public static function standar(string $kunci): ?array
    {
        return self::muat()['standar'][$kunci] ?? null;
    }

    /** @return array<int, string> */
    public static function daftarStandar(): array
    {
        return array_keys(self::muat()['standar'] ?? []);
    }

    /**
     * Koreksi standar buat satu nilai beban (kN).
     *
     * Memulangkan `null` kalau kombinasi standar x arah memang tidak punya
     * tabel — dan itu keadaan NYATA, bukan kelalaian data: `Load Cell 3000 kN`
     * cuma dikalibrasi arah tekan. Pemanggil wajib memutuskan sendiri; yang
     * berbahaya kalau ketiadaan tabel diam-diam dibaca sebagai koreksi nol,
     * karena sesi tetap terbit dengan angka yang tidak pernah ditelusuri.
     *
     * @return array{koreksi_kn: float, set_point_kn: float, di_luar_rentang: bool}|null
     */
    public static function koreksi(float $nilaiKn, string $kunciStandar, string $arah): ?array
    {
        $tabel = self::muat()['standar'][$kunciStandar]['tabel'][$arah] ?? null;

        if (! is_array($tabel) || $tabel === []) {
            return null;
        }

        $tabel = array_values($tabel);
        $terbaik = 0;

        foreach ($tabel as $i => $baris) {
            // `<` dan bukan `<=`: yang seri diambil yang lebih dulu, meniru
            // `MATCH` Excel. Lihat docblock kelas.
            if (abs((float) $baris['set_point_kn'] - $nilaiKn)
                < abs((float) $tabel[$terbaik]['set_point_kn'] - $nilaiKn)) {
                $terbaik = $i;
            }
        }

        $semua = array_map(static fn (array $b): float => (float) $b['set_point_kn'], $tabel);

        return [
            'koreksi_kn' => (float) $tabel[$terbaik]['koreksi_kn'],
            'set_point_kn' => (float) $tabel[$terbaik]['set_point_kn'],
            // Master diam waktu beban di luar rentang tabel dan tetap memulangkan
            // titik terdekat — itu ekstrapolasi tanpa ada yang tahu. Di sini
            // keadaannya dilaporkan; yang memutuskan boleh-tidaknya Master Data.
            'di_luar_rentang' => $nilaiKn < min($semua) || $nilaiKn > max($semua),
            // Seberapa JAUH baris yang terpilih dari beban yang diminta.
            //
            // `di_luar_rentang` cuma menangkap yang melewati ujung tabel, dan
            // itu tidak cukup. Proving Ring 500 kgf yang dikalibrasi dengan
            // standar 3000 kN membuktikannya: titik 0,2943 kN ada di DALAM
            // [0, 3000], jadi tidak ditandai apa pun — padahal baris terdekat
            // yang terpilih 0 kN sementara baris berikutnya 300 kN, seribu kali
            // lipat bebannya. Koreksinya jadi nol bukan karena alatnya bagus,
            // tapi karena tidak ada titik tertelusur di rentang itu.
            //
            // Angkanya dipulangkan mentah, bukan jadi vonis: yang menentukan
            // berapa jauh itu "terlalu jauh" beda per alat, dan itu urusan
            // kalkulatornya.
            'jarak_ke_set_point_kn' => abs($nilaiKn - (float) $tabel[$terbaik]['set_point_kn']),
        ];
    }

    /**
     * Drift standar (% f.s) menurut WORKBOOK yang dipilih.
     *
     * Ada tiga sumber karena ketiga workbook tidak sepakat untuk standar yang
     * sama: `Load Cell 5 kN` arah Tarik ditulis `0` di workbook UTM tapi `0,04`
     * di Load Cell dan Proving Ring. Memilih satu sebagai "yang benar" berarti
     * menggeser U95% yang terbit, jadi ketiganya disimpan dan yang memilih
     * adalah profil alatnya — sambil menunggu jawaban lab.
     */
    public static function drift(string $kunciStandar, string $arah, string $sumber): ?float
    {
        $nilai = self::muat()['drift'][$kunciStandar][$arah][$sumber] ?? null;

        return $nilai === null ? null : (float) $nilai;
    }

    /** @return array<string, float|null> drift standar itu menurut ketiga workbook */
    public static function driftSemuaSumber(string $kunciStandar, string $arah): array
    {
        return self::muat()['drift'][$kunciStandar][$arah] ?? [];
    }

    /** @return array<string, mixed> */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = database_path('data/tabel-standar-gaya.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar gaya nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi)
            || ! isset($isi['faktor_satuan'], $isi['standar'], $isi['drift'])
            || $isi['standar'] === []) {
            throw new RuntimeException("Tabel standar gaya rusak: {$berkas}");
        }

        return self::$data = $isi;
    }

    /** Dipakai test yang menukar berkas tabelnya. */
    public static function lupakan(): void
    {
        self::$data = null;
    }
}
