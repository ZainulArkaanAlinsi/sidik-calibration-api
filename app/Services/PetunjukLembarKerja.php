<?php

namespace App\Services;

/**
 * Petunjuk BENTUK lembar buat satu kali baca foto.
 *
 * Dulu petunjuknya dilempar sebagai lima argumen berderet ke lima method di
 * `WorksheetVisionExtractor`, dan tiap penambahan bikin dua jalur penyedia
 * (Anthropic & Gemini) harus diubah sejajar. Yang dibungkus di sini bukan cuma
 * kerapian: dua kolom terakhir — `kolomSuhu` & `standarDiBaris` — yang bikin
 * lembar Spectrophotometer bisa dibaca sama sekali.
 *
 * ## Kenapa `kolomSuhu` & `standarDiBaris` ada
 *
 * Empat alat pertama (pH, Turbidimeter, Chlorine, Refractometer) dan
 * Conductivity punya lembar yang bentuknya SAMA: larutan standar jadi KOLOM,
 * Repeat jadi BARIS, dan tiap sel isinya sepasang angka (pembacaan + suhu °C).
 * Prompt, skema JSON, dan few-shot-nya dibangun di atas anggapan itu.
 *
 * Lembar Spectrophotometer melanggar dua-duanya sekaligus:
 *
 *  - **Satu angka per sel.** Panjang gelombang dibaca dalam nm; nggak ada kolom
 *    °C sama sekali. Suhu ruangnya dicatat sekali di blok `Env. Condition`, di
 *    kepala lembar, bukan per pembacaan.
 *  - **Standarnya turun ke bawah.** Yang berdiri di kiri tiap baris justru
 *    nilai standarnya (279,6 nm … 637,9 nm), dan yang berjajar di atas justru
 *    nomor Repeat (X1..X3) — kebalikan lembar pH.
 *
 * Tanpa dua petunjuk ini, skema yang dikirim ke model MEWAJIBKAN `suhu` per sel
 * buat lembar yang nggak punya kolom suhu, dan prompt-nya bilang "tiap sel
 * isinya dua angka" ke foto yang tiap selnya cuma berisi satu. Hasilnya bukan
 * error yang kelihatan: model mengarang kolom suhu atau memampatkan tiga Repeat
 * jadi satu baris, dan yang nyampe teknisi cuma "gagal baca, isi manual".
 */
final class PetunjukLembarKerja
{
    /**
     * @param  int|null  $jumlahTitik  jumlah larutan/filter standar
     * @param  int|null  $jumlahPengulangan  jumlah Repeat yang diharapkan
     * @param  string|null  $satuan  satuan pembacaan (`pH`, `NTU`, `nm`, `%T`)
     * @param  list<float>|null  $nominal  nilai nominal tiap standar, urut seperti di kertas
     * @param  list<int>|null  $desimal  jumlah desimal tipikal tiap standar (sejajar $nominal)
     * @param  bool  $kolomSuhu  tiap sel berisi pembacaan + suhu °C
     * @param  bool  $standarDiBaris  standar turun ke bawah, Repeat berjajar ke kanan
     */
    public function __construct(
        public readonly ?int $jumlahTitik = null,
        public readonly ?int $jumlahPengulangan = null,
        public readonly ?string $satuan = null,
        public readonly ?array $nominal = null,
        public readonly ?array $desimal = null,
        public readonly bool $kolomSuhu = true,
        public readonly bool $standarDiBaris = false,
    ) {}

    /**
     * Bentuk lembar pH — yang few-shot-nya ada.
     *
     * Contoh foto + JSON yang dilampirkan ke prompt itu lembar pH asli. Buat
     * lembar yang bentuknya lain, contoh itu MENYESATKAN, bukan membantu: dia
     * memperagakan tepat dua hal yang nggak berlaku di sana (kolom suhu, dan
     * standar sebagai kolom).
     */
    public function bentukPh(): bool
    {
        return $this->kolomSuhu && ! $this->standarDiBaris;
    }
}
