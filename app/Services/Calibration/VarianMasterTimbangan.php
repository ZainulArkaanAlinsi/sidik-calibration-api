<?php

namespace App\Services\Calibration;

use InvalidArgumentException;

/**
 * Tiga REVISI workbook master Timbangan, dan apa saja yang beda di antaranya.
 *
 * Lab mengirim tiga workbook pada 31 Agt 2026. Ketiganya berkop
 * `KALIBRASI MASSA/TIMBANGAN`, bernomor lingkup `LK-285-IDN`, dan
 * menerbitkan sertifikat dengan delapan bagian yang sama persis. Tapi
 * **rumusnya tidak identik** — dan bedanya bukan cuma satuan:
 *
 *   `New_Master_Olda_Timbangan_kg.xlsm`                  → [KG]
 *   `New_Master_Olda_Timbangan_gram.xlsm`                → [GRAM]
 *   `TERBARU_Master_Olda_Timbangan_Subtitusi_291025.xlsm`→ [SUBSTITUSI]
 *
 * Dua yang pertama tinggal di folder yang namanya sendiri berbunyi
 * *"Temuan No. 34 - (blm rampung total)"* (kebaca dari tautan luar yang
 * tertanam di workbook ketiga). Yang ketiga bernama "TERBARU" dan bertanggal
 * 29 Okt 2025. Jadi ketiganya BUKAN tiga alat, dan bukan cuma dua satuan:
 * ketiganya tiga potret dari satu berkas yang masih dirapikan lab.
 *
 * ## Kenapa dimodelkan, bukan diseragamkan
 *
 * Menyeragamkan berarti memilih satu perlakuan dan diam-diam mengubah angka
 * yang sudah pernah terbit di sertifikat pelanggan. Sembilan sesi contoh di
 * repo ini terbit dari ketiga revisi itu apa adanya; kalau kode memaksakan
 * satu rumus, sesi lama tidak bisa dihitung ulang jadi angka yang sama, dan
 * `HitungUlangSemuaSesiTest` yang menegakkan "angka tersimpan masih bisa
 * direproduksi" jadi merah — dengan benar.
 *
 * Yang dilakukan malah sebaliknya: tiap penyimpangan ditulis terang di sini,
 * ditiru persis, DAN dilaporkan tiap sesi lewat
 * `TimbanganProfile::peringatanSesi()` beserta angka pembandingnya. Yang
 * memutuskan revisi mana yang menang itu manajer teknis lab — sembilan
 * pertanyaannya ada di `docs/pertanyaan-lab-timbangan.md`.
 *
 * ## Beda yang NYATA (dibuktikan sel demi sel, bukan dugaan)
 *
 * |                              | KG                  | GRAM                | SUBSTITUSI          |
 * |------------------------------|---------------------|---------------------|---------------------|
 * | metode pembebanan            | langsung            | langsung            | beban substitusi    |
 * | baris per titik akurasi      | 3                   | 3                   | 4                   |
 * | koreksi                      | ΣCN − (m̄ − z̄)      | ΣCN − (m̄ − z̄)      | kumulatif ΔI        |
 * | u standar (massa)            | Σu seluruh blok     | Σu seluruh blok     | u baris Mref saja   |
 * | ci drift massa pertama       | 1                   | 1                   | 10                  |
 * | komponen keterulangan        | 1                   | 1                   | 2 (MID + MAX)       |
 * | U pembulatan                 | resolusi alat       | 0,0005 tetap        | 0,0005 tetap        |
 * | pembagi pembulatan           | 3,4641 (=2√3)       | 1,73                | 1,73                |
 * | U eksentrisitas (weighing)   | rentang/2           | rentang (tanpa /2)  | rentang/2           |
 * | ui "U of Correction"         | U apa adanya        | U/k                 | U/√3                |
 * | deviasi keterulangan         | m − z               | m − z − nominal     | m − z − nominal     |
 * | STDEV keterulangan diambil   | kolom deviasi       | kolom pembacaan     | kolom pembacaan     |
 *
 * Tiga baris terakhir tidak menggeser angka di sembilan sesi contoh (zero-nya
 * konstan, jadi STDEV-nya sama; deviasinya cuma tampilan) — tetap ditiru
 * supaya sesi yang zero-nya BERGERAK tidak diam-diam dihitung beda dari
 * kertasnya.
 *
 * ## Silang-kabel di master SUBSTITUSI — ditiru, dan dilaporkan
 *
 * Dua sel di `PERHITUNGAN U95%-Weighing` master substitusi menunjuk kolom yang
 * keliru, dan dua-duanya menggeser U95 yang tercetak:
 *
 *  - `K6` (**Sres MID**) dihitung dari `FC!H116` — kolom STDEV kapasitas
 *    **Maximum**, bukan `E116` yang Middle. Di sesi contoh itu menaikkan
 *    komponen "Repeatability MID-range" dari ~0 jadi 0,0707 kg, dan U95
 *    koreksi titik 1 dari ~0,237 jadi 0,280 kg — 18%.
 *  - `K7` (**Sres MAX**) dihitung dari `FC!M116`, kolom KETIGA yang di
 *    workbook ini memang tidak ada (sisa salinan master kg yang punya tiga
 *    kolom keterulangan). Sel kosong dibaca nol, jadi cabangnya selalu jatuh
 *    ke lantai `0,82 × a`.
 *
 * Ditambah satu geser baris: `H8` (Sr MID) menunjuk `FC!V70` — blok akurasi
 * ke-6, sementara kapasitas tengah sesi itu ada di blok ke-5 (`V66`).
 * Dua-duanya nol di sesi contoh, jadi angkanya tidak bergerak; yang ditiru di
 * sini NIATNYA (Sr blok akurasi terdekat kapasitas tengah), bukan nomor
 * barisnya — meniru nomor baris berarti sesi 12 titik memakai blok yang
 * artinya lain lagi. Dilaporkan sebagai T5.
 *
 * Yang paling berbahaya baris `ui "U of Correction"`: ketiganya memasukkan
 * ketidakpastian DIPERLUAS (k·uc) sebagai komponen baku di budget Weighing.
 * KG memakainya mentah — secara GUM itu terlalu besar sekitar dua kali lipat;
 * GRAM membaginya balik dengan k — itu yang benar; SUBSTITUSI membaginya √3 —
 * itu tidak mengembalikan apa pun. Selisihnya sampai 2× di U95 yang tercetak.
 */
final class VarianMasterTimbangan
{
    public const KG = 'kg';

    public const GRAM = 'gram';

    public const SUBSTITUSI = 'substitusi';

    /** @return list<string> */
    public static function semua(): array
    {
        return [self::KG, self::GRAM, self::SUBSTITUSI];
    }

    private function __construct(
        public readonly string $kode,
        public readonly string $label,
        /** Pembebanan pakai beban substitusi (kapasitas jauh di atas anak timbangan lab). */
        public readonly bool $substitusi,
        /** Baris pembacaan per titik akurasi: z, m, m', (+ z' untuk substitusi). */
        public readonly int $barisPerTitik,
        /** U pembulatan TETAP (satuan alat), atau null = pakai resolusi alat. */
        public readonly ?float $uPembulatanTetap,
        /** Pembagi komponen "Rounding of Final Result". */
        public readonly float $pembagiPembulatan,
        /** Rentang eksentrisitas dibagi dua sebelum jadi U. */
        public readonly bool $eksentrisitasDibagiDua,
        /** Cara "U of Correction" diturunkan jadi ui di budget Weighing. */
        public readonly string $turunanUKoreksi,
        /** ci komponen drift massa PERTAMA (Mref). */
        public readonly float $ciDriftPertama,
        /** Keterulangan dipecah jadi dua komponen (MID & MAX). */
        public readonly bool $keterulanganDuaKomponen,
        /** Dari mana `Sr` budget koreksi diambil — lihat [SR_*]. */
        public readonly string $sumberSr,
        /** u standar cuma dari baris Mref, bukan seluruh blok. */
        public readonly bool $uStandarMrefSaja,
        /** Deviasi keterulangan mengurangi nominal kapasitas. */
        public readonly bool $deviasiKurangiNominal,
        /** STDEV keterulangan dihitung atas kolom pembacaan mentah, bukan deviasi. */
        public readonly bool $stdevAtasPembacaan,
    ) {}

    /** Cara ui komponen "Uncertainty of Correction" diturunkan di budget Weighing. */
    public const U_KOREKSI_MENTAH = 'mentah';   // ui = U   (KG)

    public const U_KOREKSI_BAGI_K = 'bagi_k';   // ui = U/k (GRAM)

    public const U_KOREKSI_BAGI_AKAR3 = 'bagi_akar3'; // ui = U/√3 (SUBSTITUSI)

    /**
     * Dari mana `Sr` (simpangan baku yang diadu ke lantai `Sres`) diambil.
     * Ketiga master menjawabnya BEDA, dan bedanya menggeser U yang tercetak.
     */
    // KG — STDEV blok keterulangan, dipilih dari pita kapasitas titiknya:
    // titik <= nominal tengah pakai kolom Middle, di atasnya kolom Maximum.
    // Master: `IF(C3<=FC!$C$94, FC!$E$107, IF(AND(...), FC!$H$107, "Kondisi Lain"))`.
    public const SR_PITA_KETERULANGAN = 'pita_keterulangan';

    // GRAM — Sr blok AKURASI titik itu sendiri (`FC!U52`, `U55`, …), yaitu
    // STDEV(m, m') satu siklus. Sama sekali bukan tabel keterulangan.
    public const SR_TITIK_AKURASI = 'sr_titik_akurasi';

    // SUBSTITUSI — dua komponen (MID & MAX), masing-masing dari pasangan
    // (Sr akurasi, lantai Sres) sendiri. Lihat catatan silang-kabel di bawah.
    public const SR_DUA_PITA = 'dua_pita';

    public static function dari(string $kode): self
    {
        return match ($kode) {
            self::KG => new self(
                kode: self::KG,
                label: 'Master kg (pembebanan langsung)',
                substitusi: false,
                barisPerTitik: 3,
                uPembulatanTetap: null,
                pembagiPembulatan: 3.4641,
                eksentrisitasDibagiDua: true,
                turunanUKoreksi: self::U_KOREKSI_MENTAH,
                ciDriftPertama: 1.0,
                keterulanganDuaKomponen: false,
                sumberSr: self::SR_PITA_KETERULANGAN,
                uStandarMrefSaja: false,
                deviasiKurangiNominal: false,
                stdevAtasPembacaan: false,
            ),
            self::GRAM => new self(
                kode: self::GRAM,
                label: 'Master gram (pembebanan langsung)',
                substitusi: false,
                barisPerTitik: 3,
                // 0.5/1000 dituliskan tetap di masternya — TIDAK diturunkan dari
                // resolusi alat. Buat sesi contoh (resolusi 0,0001 g) angkanya
                // sepuluh kali lebih besar daripada kalau resolusi yang dipakai.
                uPembulatanTetap: 0.0005,
                pembagiPembulatan: 1.73,
                eksentrisitasDibagiDua: false,
                turunanUKoreksi: self::U_KOREKSI_BAGI_K,
                ciDriftPertama: 1.0,
                keterulanganDuaKomponen: false,
                sumberSr: self::SR_TITIK_AKURASI,
                uStandarMrefSaja: false,
                deviasiKurangiNominal: true,
                stdevAtasPembacaan: true,
            ),
            self::SUBSTITUSI => new self(
                kode: self::SUBSTITUSI,
                label: 'Master substitusi (beban pengganti, kapasitas besar)',
                substitusi: true,
                barisPerTitik: 4,
                uPembulatanTetap: 0.0005,
                pembagiPembulatan: 1.73,
                eksentrisitasDibagiDua: true,
                turunanUKoreksi: self::U_KOREKSI_BAGI_AKAR3,
                // ci = 10 cuma di baris drift Mref. Tidak ada keterangan di
                // masternya; dilaporkan sebagai T4 di docs/pertanyaan-lab-timbangan.md.
                ciDriftPertama: 10.0,
                keterulanganDuaKomponen: true,
                sumberSr: self::SR_DUA_PITA,
                uStandarMrefSaja: true,
                deviasiKurangiNominal: true,
                stdevAtasPembacaan: true,
            ),
            default => throw new InvalidArgumentException(
                "Varian master Timbangan '{$kode}' nggak dikenal. Yang ada: "
                .implode(', ', self::semua()).'.',
            ),
        };
    }

    /**
     * Varian yang PANTAS dipakai buat sebuah kapasitas & satuan, dipakai waktu
     * sesi belum menyebut variannya sendiri (draf lama, atau alat baru yang
     * teknisinya belum memilih).
     *
     * Aturannya dari lampiran akreditasi no. 12: *"untuk rentang di atas 200 kg
     * menggunakan Metode beban substitusi"*. Di bawah itu, satuan yang
     * menentukan berkas mana yang dipakai lab.
     */
    public static function bawaanUntuk(float $kapasitasKg, ?string $satuan): self
    {
        if ($kapasitasKg > 200.0) {
            return self::dari(self::SUBSTITUSI);
        }

        return self::dari(strtolower(trim((string) $satuan)) === 'g' ? self::GRAM : self::KG);
    }
}
