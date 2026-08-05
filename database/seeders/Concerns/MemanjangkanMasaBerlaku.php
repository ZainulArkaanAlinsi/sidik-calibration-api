<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Carbon;

/**
 * Masa berlaku sertifikat kalibrator buat data seeder, DIPANJANGIN kalau udah
 * (mau) lewat — dan **selalu diumumin waktu dipanjangin**.
 *
 * ## Kenapa perlu
 *
 * Backend nolak `422` kalau standar acuannya kadaluarsa, dan itu BENER:
 * ketertelusurannya putus. Tapi tanggal di data seeder itu tanggal sertifikat
 * ASLI, dan sertifikat punya masa berlaku. Artinya seeder BASI SENDIRI LEWAT
 * WAKTU — rantai kalibrasi berhenti di dev tanpa ada yang ngubah kode apa pun.
 *
 * Per 5 Agt 2026 yang udah/hampir lewat:
 *   pH Buffer Solution 4          31 Jul 2026  (lewat)
 *   Termometer & Sensor Std.      12 Agt 2026  (6 hari)
 *   Turbidity Standard 1 NTU       4 Des 2025  (lewat)
 *   Turbidity Standard 100 NTU     3 Nov 2025  (lewat)
 *   Turbidity Standard 1000 NTU   19 Des 2025  (lewat)
 *
 * ## Kenapa nggak `now()->addYear()` polos
 *
 * Sebelum ini `TurbidimeterSeeder` & `ChlorineSeeder` nulis `now()->addYear()`
 * apa adanya: tanggal aslinya DIBUANG tiap kali seed, diam-diam, walaupun
 * sertifikatnya masih berlaku lama. Dua akibatnya:
 *
 *  1. Data demo nyamar jadi data valid. Nggak ada yang tahu standar turbidity
 *     lab sebenernya udah kadaluarsa sejak akhir 2025 — di dev semuanya
 *     kelihatan hijau.
 *  2. Sertifikat yang masih lama (Chlorine due 30 Apr 2027) ikut ditimpa,
 *     padahal nggak ada alasannya.
 *
 * Sekarang: tanggal asli DIPERTAHANKAN selama masih > [ambangHari]. Lewat dari
 * itu baru dipanjangin, dan barisnya dicetak ke keluaran seeder.
 *
 * ## Yang TIDAK diubah
 *
 * Cuma masa berlakunya. **U95, faktor cakupan, koefisien suhu, nomor
 * sertifikat, dan tertelusur-ke tetap yang asli** — itu yang masuk perhitungan
 * & kecetak di sertifikat. Berkas data sumbernya juga nggak disentuh; dia tetap
 * catatan tanggal sebenarnya.
 */
trait MemanjangkanMasaBerlaku
{
    /**
     * Ambangnya 60 hari, bukan "udah lewat": kalau pas-pasan, seeder yang hari
     * ini hijau bakal merah minggu depan, dan yang kena orang lain.
     */
    protected int $ambangHari = 60;

    protected function berlakuSampaiDemo(string $nama, Carbon|string|null $asli): Carbon
    {
        // Data lama yang nggak nyimpen tanggal sama sekali: nggak ada yang bisa
        // dipertahankan, jadi langsung dikasih masa berlaku demo.
        if ($asli === null) {
            return now()->addYear();
        }

        $tanggal = $asli instanceof Carbon ? $asli : Carbon::parse($asli);

        if ($tanggal->greaterThan(now()->addDays($this->ambangHari))) {
            return $tanggal;
        }

        $baru = now()->addYear();

        $this->command?->warn(sprintf(
            '  [demo] "%s" masa berlakunya %s (%s) -> dipanjangin ke %s. '
            .'U95 & data sertifikat lainnya TETAP asli.',
            $nama,
            $tanggal->toDateString(),
            $tanggal->isPast() ? 'udah lewat' : 'tinggal '.(int) now()->diffInDays($tanggal).' hari',
            $baru->toDateString(),
        ));

        return $baru;
    }
}
