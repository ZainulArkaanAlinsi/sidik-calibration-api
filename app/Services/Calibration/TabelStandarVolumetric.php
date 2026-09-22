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
     * `resolusi_g` selalu `null` di keluarga `graduated` — workbook-nya memang
     * tidak punya kolom Res, dan budget Graduated memakai U95/2, bukan
     * resolusi/√3 (pertanyaan lab no. 9).
     *
     * @return array{nama: string, merk_type: string, serial: string|null, u95_g: float|null, resolusi_g: float|null, stdev_g: float|null}|null
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

    /**
     * Suhu air terkoreksi = bacaan + koreksi kalibrator + koreksi sensor PRT.
     *
     * Master (`PERHITUNGAN`) memilih titik tabel kalibrator yang TERDEKAT ke
     * bacaan lewat `INDEX/MATCH(MIN(ABS(...)))`, lalu memakai titik itu untuk
     * KEDUA koreksi — sensor PRT tidak diinterpolasi ke bacaannya sendiri.
     * Ditiru persis: bacaan 27,0 °C memakai koreksi titik 25 °C.
     *
     * Koreksi sensor diambil dari sheet lokal `FC_Prt_Pt100`, bukan dari
     * `SENSOR_PT100` yang membacanya lewat tautan luar `[4]` — lihat generator.
     *
     * `null` kalau titik terpilih tidak punya koreksi sensor: menambahkan nol
     * diam-diam menggeser suhu, dan suhu menggeser seluruh V20.
     *
     * @return array{titik_c: float, koreksi_kalibrator_c: float, koreksi_sensor_c: float, terkoreksi_c: float}|null
     */
    public function koreksiSuhu(float $bacaan): ?array
    {
        $tabel = self::muat()['koreksi_suhu'];

        $terpilih = null;
        $jarakTerkecil = INF;
        foreach ($tabel['kalibrator'] as $baris) {
            $jarak = abs((float) $baris['titik_c'] - $bacaan);
            if ($jarak < $jarakTerkecil) {
                $jarakTerkecil = $jarak;
                $terpilih = $baris;
            }
        }

        if ($terpilih === null) {
            return null;
        }

        $titik = (float) $terpilih['titik_c'];
        $sensor = null;
        foreach ($tabel['sensor_prt'] as $baris) {
            if (abs((float) $baris['titik_c'] - $titik) < 1e-9) {
                $sensor = (float) $baris['koreksi_c'];
                break;
            }
        }

        if ($sensor === null) {
            return null;
        }

        $kalibrator = (float) $terpilih['koreksi_c'];

        return [
            'titik_c' => $titik,
            'koreksi_kalibrator_c' => $kalibrator,
            'koreksi_sensor_c' => $sensor,
            'terkoreksi_c' => $bacaan + $kalibrator + $sensor,
        ];
    }

    /**
     * U95 termometer (Yokogawa) dan sensor PRT, °C.
     *
     * SATU angka untuk seluruh rentang — master membaca `DATABASE` kolom U95%,
     * bukan tabel U95 per titik di `STANDARD_KALIBRATOR`.
     *
     * @return array{termometer_c: float, sensor_c: float}
     */
    public function u95Suhu(): array
    {
        $u = self::muat()['koreksi_suhu']['u95'];

        return ['termometer_c' => (float) $u['termometer_c'], 'sensor_c' => (float) $u['sensor_c']];
    }

    /**
     * Seluruh neraca SATU keluarga — pilihan dropdown "Balance Used".
     *
     * @return list<array{nama: string, merk_type: string, serial: string|null, u95_g: float|null, resolusi_g: float|null, stdev_g: float|null}>
     */
    public function semuaNeraca(string $keluarga): array
    {
        return array_values(self::muat()['neraca'][$keluarga] ?? []);
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
            || ! isset($isi['neraca']['fixed'], $isi['neraca']['graduated'])
            || ! isset($isi['koreksi_suhu']['kalibrator'], $isi['koreksi_suhu']['sensor_prt'])
            || ! isset($isi['koreksi_suhu']['u95']['termometer_c'], $isi['koreksi_suhu']['u95']['sensor_c'])) {
            throw new RuntimeException("Tabel standar volumetric rusak: {$berkas}");
        }

        return self::$data = $isi;
    }
}
