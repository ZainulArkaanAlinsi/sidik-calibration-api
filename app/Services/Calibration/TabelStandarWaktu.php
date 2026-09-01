<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Tabel standar kelompok **Waktu** (Timer/Stopwatch), dibaca dari
 * `database/data/tabel-standar-waktu.json`.
 *
 * Standarnya stopwatch Casio s/n SW-1. Bedanya dari [TabelStandarPutaran]: di
 * sini koreksi sertifikat bersatuan **milidetik** sedangkan nominalnya
 * **detik**, persis seperti masternya — dan campuran satuan itu dipertahankan,
 * bukan diseragamkan, supaya angka yang tersimpan bisa diadu langsung ke sel
 * workbook waktu ada sengketa.
 *
 * ## Human Reaction hidup di sini, bukan di kalkulator
 *
 * Dua komponen budget Timer (`uHRTB`, `uHRTSD`) lahir dari sheet `Human
 * Reaction`: empat operator lab masing-masing sepuluh kali menekan standar dan
 * UUT berbarengan di nominal 10 detik. Itu sifat LABORATORIUMNYA, bukan sifat
 * alat pelanggan — nilainya sama untuk setiap sesi sampai lab mengambil data
 * reaksi baru. Jadi tempatnya di tabel standar, sederet dengan drift.
 */
class TabelStandarWaktu
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * Koreksi standar (ms) di satu nominal sertifikat (detik), atau `null`
     * kalau nominal itu tidak ada — meniru `VLOOKUP(...; 0)` master.
     */
    public function koreksiMs(float $nominalDetik): ?float
    {
        foreach (self::muat()['sertifikat'] as $baris) {
            if (abs((float) $baris['nominal_detik'] - $nominalDetik) < 1e-9) {
                return (float) $baris['koreksi_ms'];
            }
        }

        return null;
    }

    /** U95 sertifikat kalibrator (detik) yang berlaku di `$nominalDetik`. */
    public function u95Sertifikat(float $nominalDetik): ?float
    {
        $pilih = null;

        foreach (self::muat()['sertifikat'] as $baris) {
            if ($baris['u95_detik'] !== null && (float) $baris['nominal_detik'] <= $nominalDetik + 1e-9) {
                $pilih = (float) $baris['u95_detik'];
            }
        }

        return $pilih;
    }

    /**
     * Nominal sertifikat (detik) yang dipakai untuk satu set point: yang
     * **terdekat**, seri diputus ke BAWAH.
     *
     * ## Kenapa arah serinya beda dari kelompok rpm
     *
     * Master Timer punya empat titik terpakai. Tiga di antaranya cocok persis
     * dengan nominal sertifikat (60, 300, 600 detik) sehingga tidak menguji
     * aturan apa pun. Yang keempat, set point 15 menit = 900 detik, jatuh
     * TEPAT di tengah 600 dan 1200 — dan teknisi mengetik **600**.
     *
     * Jadi satu-satunya bukti arah seri di kelompok waktu menunjuk ke bawah,
     * sementara satu-satunya bukti di kelompok rpm menunjuk ke atas. Keduanya
     * ditiru apa adanya, masing-masing di tabelnya sendiri, dan
     * perbedaannya diangkat sebagai pertanyaan lab §4 — menyeragamkannya
     * diam-diam menggeser koreksi titik 900 detik sebesar 10 ms dari yang sudah
     * tercetak di sertifikat pelanggan.
     */
    public function nominalTerdekat(float $setPointDetik): ?float
    {
        $pilih = null;
        $jarak = null;

        foreach (self::muat()['sertifikat'] as $baris) {
            $nominal = (float) $baris['nominal_detik'];
            $d = abs($nominal - $setPointDetik);

            // `<` saja: daftarnya urut menaik, jadi yang seri dimenangkan
            // nominal yang lebih dulu ditemui — yang lebih KECIL.
            if ($jarak === null || $d < $jarak) {
                $pilih = $nominal;
                $jarak = $d;
            }
        }

        return $pilih;
    }

    /**
     * Drift standar (ms) — pembilang komponen `u_drift`.
     *
     * > **Tidak dibagi dua**, tidak seperti [TabelStandarPutaran::driftSetengahLebar].
     * > Master waktu memakai `K26 = MAX(K12:K25)` apa adanya sebagai `u` lalu
     * > membaginya √3, sedangkan master rpm memakai `MAX(...)/2`. Untuk sebaran
     * > persegi yang benar adalah setengah-lebar, jadi master waktu dua kali
     * > lebih konservatif. Ditiru apa adanya — mengubahnya MENGECILKAN
     * > ketidakpastian yang sudah terbit. Pertanyaan lab §3.
     */
    public function driftMs(bool $lengkap = true): float
    {
        $rentang = [];

        foreach (self::muat()['drift']['titik'] as $baris) {
            if ($lengkap || $baris['dihitung_master'] === true) {
                $rentang[] = (float) $baris['rentang_ms'];
            }
        }

        if ($rentang === []) {
            throw new RuntimeException('Tabel drift stopwatch kosong.');
        }

        return max($rentang);
    }

    /**
     * `uHRTB` (detik) — rata-rata selisih reaksi operator TERBESAR, dibagi √3.
     *
     * `$lengkap = true` memakai keempat operator; `false` meniru master yang
     * `MAX(N21:N22)`-nya cuma mencakup dua operator terakhir.
     */
    public function uHumanReactionRata(bool $lengkap = true): float
    {
        $operator = self::muat()['human_reaction']['operator'];
        $dipakai = $lengkap ? $operator : array_slice($operator, 2);

        return max(array_map(static fn (array $o): float => (float) $o['rata_rata'], $dipakai)) / sqrt(3);
    }

    /**
     * `uHRTSD` (detik) — simpangan baku reaksi operator TERBESAR.
     *
     * Tidak punya saklar `$lengkap`: di master pun rentangnya (`MAX(P19:P22)`)
     * sudah mencakup keempat operator. Justru ketimpangan itulah — `N23` dua
     * operator, `P23` empat operator, di dua sel bersebelahan — yang menjadikan
     * `N23` kerusakan salin-tempel, bukan pilihan metode.
     */
    public function uHumanReactionStdev(): float
    {
        return max(array_map(
            static fn (array $o): float => (float) $o['stdev'],
            self::muat()['human_reaction']['operator'],
        ));
    }

    public function standar(): array
    {
        return self::muat()['standar'];
    }

    /** Berapa sertifikat kalibrator yang jadi bahan hitungan drift. */
    public function jumlahSnapshotDrift(): int
    {
        return count(self::muat()['drift']['snapshot']);
    }

    /** @return list<array{nominal_detik: float, koreksi_ms: float, u95_detik: float|null}> */
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

        $berkas = database_path('data/tabel-standar-waktu.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar waktu nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi) || ! isset($isi['sertifikat'], $isi['drift'], $isi['human_reaction'])) {
            throw new RuntimeException("Tabel standar waktu rusak: {$berkas}");
        }

        return self::$data = $isi;
    }
}
