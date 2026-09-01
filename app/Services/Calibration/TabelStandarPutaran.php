<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Tabel standar kelompok **Putaran** (Infrared Tachometer & Centrifuge), dibaca
 * dari `database/data/tabel-standar-putaran.json`.
 *
 * Kedua alat memakai keping standar yang SAMA — Infrared Tachometer NKTECH
 * NK-300 s/n 1186.01.23-1, tertelusur LK-305-IDN — dan sheet `SERTIFIKAT
 * KALIBRATOR` serta `Drift Std Kalibrator` di kedua workbook master isinya
 * identik baris demi baris. Jadi satu tabel, dipakai dua profil; menyalinnya
 * jadi dua berkas berarti dua tempat yang harus ingat diperbarui waktu
 * sertifikat kalibratornya turun tahun depan.
 *
 * ## Nominal sertifikat itu KUNCI PASTI, bukan pita
 *
 * Master mencari koreksi standar lewat `VLOOKUP(indexed; Standar_Correction; 3;
 * 0)` — argumen terakhir `0` = **cocok persis**. Nominal yang tidak ada di tabel
 * memulangkan `#N/A`, artinya titik itu memang tidak bisa dihitung. [koreksi]
 * meniru itu: balik `null`, bukan menebak tetangga terdekat.
 *
 * Yang MENENTUKAN nominal mana yang dipakai untuk satu set point adalah
 * [nominalTerdekat] — dan itu hal yang berbeda, lihat docblock-nya.
 *
 * ## Ketidakpastian sertifikat berbentuk PITA, dan itu terbukti
 *
 * Kolom `Uncertainty` di sertifikat kalibrator cuma terisi di lima baris (60,
 * 200, 300, 5000, 15000 rpm); delapan baris lain kosong. Itu bukan data hilang
 * — nilainya berlaku sebagai pita sampai baris ber-nilai berikutnya. Dibuktikan
 * dengan mengadu aturan "pita yang memuat Indexed Value TERTINGGI dalam satu
 * blok" ke sebelas blok budget di dua workbook: **sepuluh cocok persis**, dan
 * satu-satunya yang meleset (blok 5 Tachometer) adalah blok yang di master juga
 * rusak di dua tempat lain (lihat [PutaranCalculator]). Lihat
 * `docs/pertanyaan-lab-waktu-frekuensi.md` §1.
 */
class TabelStandarPutaran
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * Koreksi standar (rpm) di satu nominal sertifikat, atau `null` kalau
     * nominal itu tidak ada di tabel — meniru `VLOOKUP(...; 0)` master.
     */
    public function koreksi(float $nominal): ?float
    {
        foreach (self::muat()['sertifikat'] as $baris) {
            if (abs((float) $baris['nominal'] - $nominal) < 1e-9) {
                return (float) $baris['koreksi'];
            }
        }

        return null;
    }

    /**
     * U95 sertifikat kalibrator (rpm) yang berlaku di `$nominal` — nilai pita
     * ber-nominal TERBESAR yang masih <= `$nominal`.
     *
     * Balik `null` kalau `$nominal` ada di bawah pita pertama (60 rpm): di bawah
     * itu kalibratornya memang tidak punya angka, dan menebaknya berarti
     * menerbitkan ketidakpastian yang tidak bersumber.
     */
    public function u95Sertifikat(float $nominal): ?float
    {
        $pilih = null;

        foreach (self::muat()['sertifikat'] as $baris) {
            if ($baris['u95'] !== null && (float) $baris['nominal'] <= $nominal + 1e-9) {
                $pilih = (float) $baris['u95'];
            }
        }

        return $pilih;
    }

    /**
     * Nominal sertifikat yang dipakai untuk satu set point: yang **terdekat**,
     * dan kalau jaraknya seri, yang LEBIH BESAR.
     *
     * ## Kenapa diturunkan, padahal master mengetiknya tangan
     *
     * Sel `Indexed Value` di master tidak punya rumus — teknisi mengetiknya.
     * Aturan di atas diadu ke SELURUH 33 titik di kedua workbook rpm dan cocok
     * 33 dari 33, termasuk ketiga kasus seri (80 -> 100, 150 -> 200) dan yang
     * kelihatan janggal tapi benar (set point 1000 -> nominal 500, karena 500
     * memang lebih dekat daripada 2000).
     *
     * Diturunkan, bukan disimpan sebagai masukan, supaya salah ketik tidak bisa
     * masuk: nominal yang meleset satu baris menggeser koreksi standar tanpa
     * menerbitkan satu pun error, dan itu sudah terjadi di master (lihat
     * [PutaranCalculator]).
     *
     * > Seri diputus KE ATAS hanya terbukti di kelompok rpm. Master Timer
     * > memutus serinya ke BAWAH di satu titik — lihat
     * > [TabelStandarWaktu::nominalTerdekat] dan pertanyaan lab §4.
     */
    public function nominalTerdekat(float $setPoint): ?float
    {
        // SERI dimenangkan nominal yang LEBIH BESAR: set point 80 rpm memilih
        // 100 (bukan 60), 150 memilih 200 (bukan 100).
        //
        // Ditulis di sini, bukan cuma di dalam loop, karena saudaranya
        // [TabelStandarWaktu::nominalTerdekat] memutuskan KEBALIKANNYA — seri di
        // sana dimenangkan yang lebih kecil. Dua berkas kembar dengan dua
        // jawaban yang berbeda: pembaca yang menyamakannya karena "bentuknya
        // sama" akan salah di salah satunya, dan selisihnya cuma muncul di set
        // point yang diketik teknisi sendiri.

        $semua = array_map(
            static fn (array $baris): float => (float) $baris['nominal'],
            self::muat()['sertifikat'],
        );

        // DI LUAR jangkauan sertifikat berarti TIDAK ADA padanan — bukan "ambil
        // yang paling ujung". Alasan lengkapnya di
        // [TabelStandarWaktu::nominalTerdekat]; bentuknya sama, dan begitu pula
        // cacatnya: set point 500000 rpm memungut koreksi baris 30000 rpm, dan
        // penjagaan `koreksi($nominal) === null` di `PutaranCalculator` tidak
        // pernah punya jalan masuk karena `$nominal`-nya diambil dari daftar itu
        // sendiri.
        if ($setPoint < min($semua) - 1e-9 || $setPoint > max($semua) + 1e-9) {
            return null;
        }

        $pilih = null;
        $jarak = null;

        foreach ($semua as $nominal) {
            $d = abs($nominal - $setPoint);

            // `>=` bukan `>`: seri dimenangkan nominal yang lebih besar, dan
            // daftarnya sudah urut menaik.
            if ($jarak === null || $d < $jarak || ($d === $jarak && $nominal > (float) $pilih)) {
                $pilih = $nominal;
                $jarak = $d;
            }
        }

        return $pilih;
    }

    /**
     * Setengah-lebar drift kalibrator (rpm) — pembilang komponen `u_drift`.
     *
     * `$lengkap = true` (bawaan) memakai SEMUA titik drift yang berdata; `false`
     * meniru master yang cuma mengisi kolom `K` di 5 dari 15 baris.
     *
     * Dibagi dua karena rentang koreksi lintas sertifikat itu lebar PENUH,
     * sedangkan sebaran persegi butuh setengah-lebarnya (`a`) sebelum dibagi
     * √3 — `K35 = MAX(...)/2` di master, dan itu praktik GUM yang benar.
     */
    public function driftSetengahLebar(bool $lengkap = true): float
    {
        $rentang = [];

        foreach (self::muat()['drift']['titik'] as $baris) {
            if ($lengkap || $baris['dihitung_master'] === true) {
                $rentang[] = (float) $baris['rentang'];
            }
        }

        if ($rentang === []) {
            throw new RuntimeException('Tabel drift standar putaran kosong.');
        }

        return max($rentang) / 2.0;
    }

    /** Identitas keping standar buat dicetak di baris `Standard Used`. */
    public function standar(): array
    {
        return self::muat()['standar'];
    }

    /** Berapa sertifikat kalibrator yang jadi bahan hitungan drift. */
    public function jumlahSnapshotDrift(): int
    {
        return count(self::muat()['drift']['snapshot']);
    }

    /** @return list<array{nominal: float, uut: float|null, koreksi: float, u95: float|null}> */
    public function sertifikat(): array
    {
        return self::muat()['sertifikat'];
    }

    /** @return array<string, mixed> */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = database_path('data/tabel-standar-putaran.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar putaran nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi) || ! isset($isi['sertifikat'], $isi['drift'])) {
            throw new RuntimeException("Tabel standar putaran rusak: {$berkas}");
        }

        return self::$data = $isi;
    }
}
