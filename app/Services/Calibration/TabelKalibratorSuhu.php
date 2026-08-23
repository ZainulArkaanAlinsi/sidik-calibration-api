<?php

namespace App\Services\Calibration;

/**
 * Tabel koreksi & U95 kalibrator suhu standar lab, dibaca dari
 * `database/data/tabel-kalibrator-suhu.json`.
 *
 * ## Kenapa berkas data, bukan konstanta di kelas profil
 *
 * Isinya 2 mode × 2 merk kalibrator × 8 tipe sensor × sampai 18 titik — sekitar
 * 900 angka, dan semuanya lahir dari SERTIFIKAT kalibrator yang diperbarui tiap
 * kalibrator dikalibrasi ulang (Constant jatuh tempo 28 Agustus 2025, Yokogawa
 * 12 Agustus 2026). Angka yang punya tanggal kedaluwarsa tidak boleh hidup di
 * dalam kelas: yang memperbaruinya nanti admin lab lewat berkas, bukan
 * programmer lewat `git`.
 *
 * ## Dua mode, dua tabel yang isinya BEDA
 *
 * Ini bagian yang paling gampang salah dan paling mahal kalau salah. Master
 * TITS itu DUA workbook, dan sheet `STANDAR KALIBRATOR`-nya **tidak sama** —
 * 135 sel berbeda. Bukan salah simpan: kalibrator punya dua fungsi yang
 * dikalibrasi terpisah, sebagai SUMBER sinyal dan sebagai PEMBACA sinyal, dan
 * tiap fungsi punya koreksi sendiri.
 *
 *  - mode `measure` — UUT-nya yang membaca, kalibrator yang men-source. Yang
 *    dipakai koreksi kalibrator sebagai sumber.
 *  - mode `source` — UUT-nya yang men-source, kalibrator yang membaca. Yang
 *    dipakai koreksi kalibrator sebagai pembaca.
 *
 * Ketuker berarti seluruh kolom `Correction` di sertifikat bergeser sebesar
 * selisih dua tabel (di Type S titik 200: +0,08 vs +0,31 — hampir 0,25 °C)
 * tanpa satu pun angka kelihatan janggal.
 *
 * ## Pencocokan titik: TERDEKAT, bukan interpolasi
 *
 * Master memakai `INDEX(Index_Kalibrator, MATCH(MIN(ABS(Index_Kalibrator −
 * titik)), …))` — titik tabel yang paling dekat, tanpa interpolasi di antaranya.
 * Titik 1100 dengan tabel yang punya 1000 dan 1200 mengambil salah satunya utuh,
 * bukan rata-ratanya.
 *
 * @see docs/pertanyaan-lab-tits.md
 */
class TabelKalibratorSuhu
{
    /** Mode UUT — dipakai sebagai kunci tingkat pertama berkas data. */
    public const MODE_MEASURE = 'measure';

    public const MODE_SOURCE = 'source';

    /**
     * Tipe sensor/enclosure yang dikenal, urut seperti `DATABASE!Q3:S11`
     * (kolom `Jenis Sensor`) di master.
     *
     * `RTD` di master ditulis `PT100` di header tabel STANDAR KALIBRATOR tapi
     * `RTD` di tabel CMC dan di dropdown `Tipe_Enclosure` — dua nama untuk
     * barang yang sama. Yang dipakai di sini `RTD`, dan berkas data sudah
     * diseragamkan waktu diekstrak.
     *
     * @var list<string>
     */
    public const TIPE_SENSOR = ['RTD', 'Type K', 'Type N', 'Type B', 'Type T', 'Type R', 'Type S', 'Type J'];

    /**
     * Merk kalibrator yang punya tabel. Kuncinya huruf kecil; nama tercetaknya
     * ada di [MERK_TERCETAK].
     *
     * @var list<string>
     */
    public const MERK = ['constant', 'yokogawa'];

    /** @var array<string, string> */
    public const MERK_TERCETAK = [
        'constant' => 'Constant',
        'yokogawa' => 'Yokogawa',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * Koreksi kalibrator di titik TERDEKAT, plus titik tabel yang menang.
     *
     * `null` kalau tipe sensor / merk / mode-nya tidak punya tabel sama sekali —
     * dan itu harus menghentikan perhitungan, bukan diperlakukan sebagai koreksi
     * nol. Koreksi nol adalah pernyataan ("kalibratornya tepat di titik ini"),
     * bukan ketiadaan data.
     *
     * @return array{titik: float, nilai: float}|null
     */
    public function koreksi(string $mode, string $merk, string $tipeSensor, float $titikUkur): ?array
    {
        return $this->terdekat($mode, $merk, $tipeSensor, $titikUkur, 'koreksi');
    }

    /**
     * U95 sertifikat kalibrator di titik TERDEKAT.
     *
     * Nilai negatif ditolak (balik `null`), bukan dipakai apa adanya: tabel U95
     * `Type K` Yokogawa di workbook SOURCE berisi angka negatif — nilai koreksi
     * yang tersalin ke kolom yang salah. U95 negatif tidak punya arti, dan
     * dipakai mentah dia akan mengecilkan `uc` lewat kuadrat yang tetap positif
     * sambil menyembunyikan bahwa datanya rusak.
     *
     * @return array{titik: float, nilai: float}|null
     */
    public function u95(string $mode, string $merk, string $tipeSensor, float $titikUkur): ?array
    {
        $baris = $this->terdekat($mode, $merk, $tipeSensor, $titikUkur, 'u95');

        return $baris !== null && $baris['nilai'] >= 0.0 ? $baris : null;
    }

    /**
     * U95 TERBESAR di seluruh rentang tipe sensor ini — yang dipakai budget mode
     * `measure` (`MAX('STANDAR KALIBRATOR'!P32:P49)` di master).
     *
     * Baris bernilai negatif dibuang lebih dulu, alasannya sama seperti [u95].
     */
    public function u95Maksimum(string $mode, string $merk, string $tipeSensor): ?float
    {
        $nilai = array_filter(
            array_map(
                static fn (array $b): ?float => isset($b['u95']) ? (float) $b['u95'] : null,
                $this->baris($mode, $merk, $tipeSensor),
            ),
            static fn (?float $v): bool => $v !== null && $v >= 0.0,
        );

        return $nilai === [] ? null : max($nilai);
    }

    /**
     * Ketidakpastian drift kalibrator untuk tipe sensor ini (`STANDAR
     * KALIBRATOR!X7:AA13`).
     *
     * Nilainya beda per mode DAN per merk: Yokogawa Type K 0,034 di tabel mode
     * `measure` tapi 0,001 di mode `source`.
     */
    public function drift(string $mode, string $merk, string $tipeSensor): ?float
    {
        $nilai = self::muat()['mode'][$mode][$merk]['drift'][$tipeSensor] ?? null;

        return $nilai === null ? null : (float) $nilai;
    }

    /**
     * Tipe sensor yang PUNYA tabel untuk kombinasi mode+merk ini.
     *
     * Tidak semua delapan tipe ada di tiap tabel — kolom `Type B` Yokogawa
     * misalnya isinya `#REF!` dari titik 600 ke atas dan kosong di bawahnya.
     * Dipakai profil buat menyusun pilihan dropdown yang cuma memuat tipe yang
     * benar-benar bisa dihitung.
     *
     * @return list<string>
     */
    public function tipeSensorTersedia(string $mode, string $merk): array
    {
        return array_values(array_keys(self::muat()['mode'][$mode][$merk]['titik'] ?? []));
    }

    /**
     * Titik tabel yang paling dekat ke `$titikUkur` DAN punya isi di kolom
     * `$kolom`.
     *
     * ## Aturan seri: yang menang titik yang LEBIH TINGGI
     *
     * Titik yang jatuh tepat di tengah dua titik tabel (1100 di antara 1000 dan
     * 1200) tidak punya pemenang alami. Master memilih **1200** di satu-satunya
     * kasus seri yang ada di kedua workbook (`PERHITUNGAN FC!P35 = 1200`,
     * FC −0,20 bukan −0,15) — walau rumus `MATCH(…, 0)`-nya sendiri semestinya
     * mengembalikan yang pertama ketemu, yaitu 1000.
     *
     * Yang ditiru HASILNYA, bukan rumusnya: itu angka yang tercetak di
     * sertifikat 0159-CAL-626 dan dipegang pelanggan. Ketidakcocokan rumus vs
     * hasil di master ditanyakan ke lab.
     *
     * @return array{titik: float, nilai: float}|null
     */
    private function terdekat(string $mode, string $merk, string $tipeSensor, float $titikUkur, string $kolom): ?array
    {
        $menang = null;

        foreach ($this->baris($mode, $merk, $tipeSensor) as $baris) {
            if (! isset($baris[$kolom])) {
                continue;
            }

            $titik = (float) $baris['titik'];
            $jarak = abs($titik - $titikUkur);

            if ($menang === null
                || $jarak < $menang['jarak']
                || ($jarak === $menang['jarak'] && $titik > $menang['titik'])) {
                $menang = ['jarak' => $jarak, 'titik' => $titik, 'nilai' => (float) $baris[$kolom]];
            }
        }

        return $menang === null ? null : ['titik' => $menang['titik'], 'nilai' => $menang['nilai']];
    }

    /**
     * @return list<array{titik: float, koreksi?: float|null, u95?: float|null}>
     */
    private function baris(string $mode, string $merk, string $tipeSensor): array
    {
        $baris = self::muat()['mode'][$mode][$merk]['titik'][$tipeSensor] ?? [];

        // Sel kosong & `#REF!` sudah dibuang waktu ekstraksi, tapi kunci-nya
        // tetap ada bernilai null. Disaring di sini biar pemanggil nggak perlu
        // tahu bedanya "titik ini nggak ada di tabel" dan "ada tapi kosong".
        //
        // Baris ber-`koreksi` 0 DAN `u95` 0 sekaligus ikut dibuang: itu sel
        // kosong yang diisi nol waktu master dibuat, bukan pengukuran.
        // U95 nol tidak punya arti fisik — standar selalu punya ketidakpastian —
        // jadi nol berpasangan itu penanda ketiadaan data, bukan data.
        //
        // Ini BUKAN cuma soal titik 1400/1700 Type S yang tidak terjangkau sesi
        // mana pun. Kalibrator `constant` punya pasangan nol yang sama di
        // **Type N @ 1000 °C** — persis batas atas rentang akreditasi Type N
        // (`TitsProfile::CMC_TIPE_SENSOR`), alias titik uji paling wajar untuk
        // tipe itu. Dipakai mentah, mode `source` mengambil U95 di titik index
        // TERTINGGI sesi, mendarat di sel itu, dan komponen "sertifikat
        // kalibrator" jadi nol tanpa memicu `komponen_tanpa_data` (komponennya
        // ada, cuma nol). Efeknya U95 sesi TURUN — dan turunnya lolos dari
        // lantai CMC. Jaraknya ke titik tabel nol, jadi peringatan
        // `tits_titik_jauh_dari_tabel` juga tidak pernah bunyi.
        //
        // Yang benar: menolak menghitung, sama seperti sikap tabel ini pada U95
        // negatif dan `#REF!`.
        return array_values(array_filter(
            $baris,
            static function (array $b): bool {
                $koreksi = $b['koreksi'] ?? null;
                $u95 = $b['u95'] ?? null;

                if ($koreksi === null && $u95 === null) {
                    return false;
                }

                return ! ((float) $koreksi === 0.0 && (float) $u95 === 0.0);
            },
        ));
    }

    /**
     * Isi berkas data, dibaca sekali per proses.
     *
     * Statis karena tabel ini konstanta lab, bukan status: satu request bisa
     * menghitung sembilan titik dan tiap titik memanggil tiga lookup — membaca
     * ulang 20 KB JSON 27 kali itu pemborosan yang tidak beli apa-apa.
     *
     * @return array<string, mixed>
     */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = database_path('data/tabel-kalibrator-suhu.json');

        if (! is_file($path)) {
            throw new \RuntimeException(
                "Tabel kalibrator suhu nggak ketemu di {$path} — tanpa itu koreksi & U95 standar "
                .'nggak bisa dibaca dan sesi TITS nggak boleh dihitung.',
            );
        }

        $isi = json_decode((string) file_get_contents($path), true);

        if (! is_array($isi) || ! isset($isi['mode'])) {
            throw new \RuntimeException("Tabel kalibrator suhu di {$path} rusak — kunci `mode` nggak ada.");
        }

        return self::$data = $isi;
    }
}
