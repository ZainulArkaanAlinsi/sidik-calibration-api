<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Pembaca tabel referensi **Volumetric Glassware**.
 *
 * Isinya `database/data/tabel-standar-volumetric.json` — DIGENERATE oleh
 * `docs/skrip/gen-tabel-standar-volumetric.py` dari kedua workbook master,
 * jangan disunting tangan.
 *
 * ## Dua cara cari, dan keduanya sengaja berbeda
 *
 * - **CMC → nominal TERDEKAT.** Master memilih baris CMC lewat
 *   `INDEX(Index_x, MATCH(MIN(ABS(Index_x − nominal)), ABS(Index_x − nominal), 0))`,
 *   lalu `VLOOKUP` persis pada nominal terpilih itu. Jadi pipet 1,5 mL memakai
 *   CMC rentang terdekatnya, bukan ditolak. Kalau dua rentang sama dekat,
 *   `MATCH` mengambil yang **pertama** di tabel — ditiru persis, termasuk itu.
 * - **Diameter tabung → PERSIS.** Master memakai `VLOOKUP(toleransi,
 *   Tabel_Diameter, 2, 0)` — argumen `0` artinya cocok persis. Toleransi yang
 *   tidak ada di tabel ISO 4787 menghasilkan `#N/A` di master; di sini `null`,
 *   supaya pemanggil memblokir titiknya dengan alasan yang kebaca, bukan
 *   diam-diam memakai diameter tetangga.
 *
 * Menyeragamkan keduanya jadi satu cara ("biar konsisten") mengubah angka yang
 * sudah tercetak di sertifikat lab, tanpa satu pun error.
 */
class TabelStandarVolumetric
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * CMC (mL) untuk jenis alat dan nominalnya, lewat nominal TERDEKAT.
     *
     * `null` kalau jenis alatnya tidak dikenal atau tabelnya kosong.
     */
    public function cmc(string $jenisAlat, float $nominal): ?float
    {
        $baris = $this->barisTerdekat($jenisAlat, $nominal);

        return $baris === null ? null : (float) $baris['cmc_ml'];
    }

    /**
     * Nominal tabel yang terpilih untuk CMC — master menyebutnya "Indexed Value".
     *
     * Disediakan terpisah karena ini yang harus terbaca di jejak sesi: orang
     * yang menyetujui perlu tahu CMC rentang mana yang dipakai, terutama kalau
     * nominal alatnya tidak persis ada di lampiran.
     */
    public function nominalTerindeks(string $jenisAlat, float $nominal): ?float
    {
        $baris = $this->barisTerdekat($jenisAlat, $nominal);

        return $baris === null ? null : (float) $baris['nominal_ml'];
    }

    /**
     * Diameter maksimum tabung (mm) untuk batas galat volumetrik, cocok PERSIS.
     *
     * Dicocokkan dengan toleransi 1e-9, bukan `===`: nilai datang lewat JSON dan
     * pembulatan biner float bikin `0.008` hasil `json_decode` tidak selalu
     * identik bit-per-bit dengan `0.008` di tabel.
     */
    public function diameterMaksimum(float $batasGalatMl): ?float
    {
        foreach (self::muat()['diameter_iso4787'] as $baris) {
            if (abs((float) $baris['batas_galat_ml'] - $batasGalatMl) < 1e-9) {
                return (float) $baris['diameter_maks_mm'];
            }
        }

        return null;
    }

    /**
     * Neraca standar milik SATU keluarga workbook.
     *
     * `$keluarga` = `fixed` atau `graduated`. Sengaja tidak ada versi "semua
     * neraca": neraca ketiga beda fisik — Fujitsu di Fixed, Precisa di
     * Graduated — dan daftar gabungan membuat salah satunya bisa terpilih untuk
     * keluarga yang salah.
     *
     * @return array{nama: string, merk_type: string, lop_g: float|null, stdev_g: float|null}|null
     */
    public function neraca(string $keluarga, string $nama): ?array
    {
        foreach (self::muat()['neraca'][$keluarga] ?? [] as $baris) {
            if ($baris['nama'] === $nama) {
                return $baris;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function jenisAlat(): array
    {
        return array_keys(self::muat()['cmc']);
    }

    /**
     * Baris CMC dengan nominal terdekat; seri → yang pertama di tabel.
     *
     * `<` (bukan `<=`) yang membuat baris pertama menang saat seri — perilaku
     * `MATCH(..., 0)` Excel.
     *
     * @return array{nominal_ml: float, cmc_ml: float}|null
     */
    private function barisTerdekat(string $jenisAlat, float $nominal): ?array
    {
        $terpilih = null;
        $jarakTerkecil = INF;

        foreach (self::muat()['cmc'][$jenisAlat] ?? [] as $baris) {
            $jarak = abs((float) $baris['nominal_ml'] - $nominal);

            if ($jarak < $jarakTerkecil) {
                $jarakTerkecil = $jarak;
                $terpilih = $baris;
            }
        }

        return $terpilih;
    }

    /** @return array<string, mixed> */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = database_path('data/tabel-standar-volumetric.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar volumetric nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        // Diperiksa SEMUA bagian, bukan cuma yang dipakai pemanggil pertama.
        // Berkas yang kehilangan satu blok akan meledak jauh dari sini — di
        // tengah perhitungan satu sesi, bukan waktu tabelnya dimuat.
        if (! is_array($isi)
            || ! isset($isi['cmc'], $isi['diameter_iso4787'], $isi['koefisien_muai'])
            || ! isset($isi['neraca']['fixed'], $isi['neraca']['graduated'])) {
            throw new RuntimeException("Tabel standar volumetric rusak: {$berkas}");
        }

        return self::$data = $isi;
    }
}
