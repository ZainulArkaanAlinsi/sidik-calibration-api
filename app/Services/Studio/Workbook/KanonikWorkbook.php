<?php

namespace App\Services\Studio\Workbook;

use App\Support\JsonKanonik;

/**
 * Bentuk kanonik hasil ekstraksi workbook: satu isi, satu deretan byte, satu
 * sha256. Penyerialnya `JsonKanonik` (kunci objek urut byte, list dipertahankan,
 * float round-trip terpendek) — aturan yang sama dengan `paket_acuan_versi.isi`.
 *
 * ## Format sel (dipakai API apa adanya)
 *
 *     {"a":"C5","b":5,"k":3,"t":"n","n":60.0,"s":"60","r":null,"f":null,"g":null}
 *
 *  - `a` alamat A1, `b` baris (1-basis), `k` kolom (1-basis, A = 1).
 *  - `t` tipe: `n` angka, `s` teks, `b` boolean, `e` galat (`#REF!` …),
 *    `f` rumus (nilai cache-nya di `n`/`s`), `kosong` sel tanpa nilai yang
 *    tetap disimpan karena bergaya (isi/garis) — hanya dari `.xlsm`.
 *  - `n` angka (float) bila nilainya numerik, selain itu null. Tidak pernah
 *    dibulatkan.
 *  - `s` teks tampil. CSV: teks sel apa adanya. `.xlsm`: nilai cache diformat
 *    dengan number format sel (`NumberFormat::toFormattedString`), TANPA
 *    menghitung ulang rumus.
 *  - `r` rumus DENGAN `=` di depan, seperti bilah rumus Excel. Rumus larik
 *    ditulis `{=…}` (konvensi tampilan Excel untuk larik CSE). Null = konstan.
 *  - `f` number format (null = General / tidak diketahui; CSV selalu null).
 *  - `g` gaya ringkas, hanya kunci yang tidak bawaan (kunci = kontrak mobile
 *    `GayaSel.fromJson`): `tebal` true, `miring` true, `isi` warna isi
 *    `#RRGGBB`, `teks` warna teks `#RRGGBB`, `rata` perataan horizontal Excel
 *    apa adanya (`center`, `right`, …; `general` tidak dikirim), `vertikal`
 *    perataan vertikal Excel apa adanya (`top`, `center`, `justify`,
 *    `distributed`; `bottom` = bawaan Excel, tidak dikirim), `bungkus` true
 *    (wrap text), `ukuran` ukuran font dalam poin (float) bila beda dari font
 *    bawaan workbook, `font` nama font bila beda dari font bawaan workbook,
 *    `border` peta per sisi `{atas,kanan,bawah,kiri}` → gaya garis Excel
 *    (`thin`, `medium`, …), hanya sisi yang bergaris. Null = tanpa gaya (CSV
 *    selalu null).
 *
 * Sel diurutkan baris lalu kolom. Sel kosong tidak disimpan, kecuali `kosong`
 * yang ber-`isi` atau ber-`border` — Excel menggambar warna isi dan garis sel
 * walau selnya kosong.
 *
 * ## sha256 workbook
 *
 * Bukan hash satu JSON raksasa: tiap lembar dikanonikkan dengan `sel_sha256`
 * (sha256 deretan byte sel kanoniknya) menggantikan isi sel, lalu daftar lembar
 * itu dikanonikkan sekali lagi. Hasilnya sama-sama deterministik, tapi isi sel
 * cukup diserial sekali — byte yang sama itulah yang disimpan di kolom `sel`.
 */
final class KanonikWorkbook
{
    /**
     * Naikkan bila bentuk sel/lembar kanonik berubah — hash lama tidak lagi sebanding.
     *
     * 2 (13 Okt 2026, tinjau S1b #1-#2): `tata_letak` ikut kontrak mobile
     * (`lebar_kolom`/`tinggi_baris` peta, `freeze`), kunci gaya `g` ikut
     * `GayaSel.fromJson`.
     *
     * 3 (13 Okt 2026, tahap A): K-49-12 — isi DATABASE/SERTIFIKAT/konsep ikut
     * diekstrak; `tata_letak` + `garis_kisi`, `zoom`, `lebar_perkiraan`
     * (CSV: lebar kolom perkiraan dari panjang teks); `lebar_kolom_bawaan` /
     * `tinggi_baris_bawaan` xlsm selalu terisi; `g` + `bungkus`, `vertikal`,
     * `ukuran`, `font`; sel kosong disimpan hanya bila ber-isi/ber-border.
     *
     * 4 (14 Okt 2026, tahap C): `tata_letak` + `gambar` (drawing per lembar),
     * `cf` (aturan conditional formatting) + `cf_gaya_dasar`; `g` sel di
     * rentang CF sudah memuat hasil CF dari nilai cache.
     *
     * 5 (15 Okt 2026, K-49-15): `tata_letak` + `dilindungi` (proteksi sheet
     * Excel) dan `sel_tidak_terkunci` (rentang sel yang TIDAK terkunci, hanya
     * bila dilindungi — `ProteksiLembar`). Baris format ≤ 4 tanpa kunci ini =
     * tidak dilindungi (`tataLetakKabel` mengisi bawaan). Sel kanonik tidak
     * berubah: status kunci tidak disimpan per sel.
     */
    public const FORMAT = 5;

    private function __construct() {}

    /**
     * Byte kanonik daftar sel satu lembar (diurutkan baris, lalu kolom).
     *
     * @param  list<array<string, mixed>>  $sel
     */
    public static function sel(array $sel): string
    {
        usort($sel, static fn (array $x, array $y): int => [$x['b'], $x['k']] <=> [$y['b'], $y['k']]);

        return JsonKanonik::serialisasi(array_values($sel));
    }

    /**
     * Byte kanonik seluruh workbook. `$lembar` = keluaran pembaca, tiap lembar
     * sudah membawa `sel_kanonik` (keluaran `sel()`).
     *
     * @param  list<array<string, mixed>>  $lembar
     */
    public static function workbook(array $lembar): string
    {
        $daftar = [];

        foreach ($lembar as $l) {
            $daftar[] = [
                'urutan' => $l['urutan'],
                'nama' => $l['nama'],
                'tersembunyi' => $l['tersembunyi'],
                'dikecualikan' => $l['dikecualikan'],
                'alasan_dikecualikan' => $l['alasan_dikecualikan'],
                'tautan_tabel' => $l['tautan_tabel'],
                'jumlah_baris' => $l['jumlah_baris'],
                'jumlah_kolom' => $l['jumlah_kolom'],
                'jumlah_sel' => $l['jumlah_sel'],
                'jumlah_rumus' => $l['jumlah_rumus'],
                // Bentuk kabel: peta kosong = `{}`, jadi hash membedakannya dari list.
                'tata_letak' => self::tataLetakKabel($l['tata_letak']),
                'sel_sha256' => hash('sha256', $l['sel_kanonik']),
            ];
        }

        return JsonKanonik::serialisasi(['format' => self::FORMAT, 'lembar' => $daftar]);
    }

    public static function sha256(string $kanonik): string
    {
        return JsonKanonik::sha256($kanonik);
    }

    /**
     * Tata letak kosong — bentuknya SAMA dengan keluaran `.xlsm`, dan kuncinya
     * kontrak mobile (`TataLetakLembar.fromJson`):
     *
     *  - `lebar_kolom` peta huruf kolom → lebar Excel (`{"B": 20.0}`);
     *  - `tinggi_baris` peta nomor baris → tinggi poin (`{"3": 30.0}`);
     *  - `merge` list `"A1:C1"`; `kolom_tersembunyi` list huruf;
     *    `baris_tersembunyi` list int;
     *  - `lebar_kolom_bawaan` lebar kolom yang tidak tercantum di
     *    `lebar_kolom` (satuan sama), `tinggi_baris_bawaan` tinggi baris yang
     *    tidak tercantum (poin). xlsm: dari `defaultColWidth` /
     *    `defaultRowHeight` sheet, bila tidak ada → tabel lebar/tinggi bawaan
     *    PhpSpreadsheet untuk font bawaan workbook (Calibri 11 → 9,140625 /
     *    15). CSV: null (tidak diketahui);
     *  - `lebar_perkiraan` true = `lebar_kolom` BUKAN dari Excel, melainkan
     *    perkiraan dari panjang teks (CSV); false = dari berkas;
     *  - `freeze` sel kiri-atas area yang TIDAK beku (`"B4"`) atau null;
     *  - `garis_kisi` bool — garis kisi sheet tampil (`showGridLines`);
     *  - `zoom` persen zoom sheet bila bukan 100, selain itu null;
     *  - `jumlah_komentar` (tambahan, diabaikan klien yang tidak memakainya);
     *  - `gambar` (format 4) list `{id, dari: {kolom, baris, dx, dy}, lebar,
     *    tinggi, jenis: png|jpeg|lain[, ekstensi]}` — px; byte lewat
     *    `GET /studio/workbook/{workbook}/gambar/{id}`;
     *  - `cf` (format 4) aturan conditional formatting (`FormatBersyarat`),
     *    `cf_gaya_dasar` peta alamat → `g` sebelum CF (untuk `/hitung`).
     *    Klien tidak perlu membaca keduanya: `g` sel sudah memuat hasil CF.
     *  - `dilindungi` (format 5) bool — sheet dilindungi di Excel;
     *    `sel_tidak_terkunci` list rentang A1 sel yang TIDAK terkunci (kosong
     *    bila tidak dilindungi). Sel terkunci ⇔ `dilindungi` dan tidak ada
     *    rentang yang memuatnya (`ProteksiLembar`). CSV: false / [].
     *
     * Di PHP peta ditulis sebagai array berkunci; peta kosong = `[]` di sini
     * dan di kolom DB. Ke kabel dan ke hash selalu lewat `tataLetakKabel()`,
     * yang menjadikannya objek JSON (`{}` bila kosong).
     *
     * @return array<string, mixed>
     */
    public static function tataLetakKosong(): array
    {
        return [
            'merge' => [],
            'lebar_kolom' => [],
            'tinggi_baris' => [],
            'kolom_tersembunyi' => [],
            'baris_tersembunyi' => [],
            'lebar_kolom_bawaan' => null,
            'tinggi_baris_bawaan' => null,
            'lebar_perkiraan' => false,
            'freeze' => null,
            'garis_kisi' => true,
            'zoom' => null,
            'jumlah_komentar' => 0,
            'gambar' => [],
            'cf' => [],
            'cf_gaya_dasar' => [],
            'dilindungi' => false,
            'sel_tidak_terkunci' => [],
        ];
    }

    /**
     * Tata letak untuk kabel (respons API) dan hash: dua peta dijadikan objek
     * supaya `json_encode` menulis `{}` untuk peta kosong, bukan `[]` (array
     * PHP kosong tidak bisa membedakan keduanya). Kunci baris `3` tertulis
     * `"3"` — JSON tidak punya kunci angka.
     *
     * Kunci yang tidak ada (baris tersimpan dengan format lama) diisi nilai
     * `tataLetakKosong()`, supaya bentuk di kabel selalu lengkap. Keluaran
     * pembaca selalu sudah lengkap, jadi hash tidak terpengaruh.
     *
     * @param  array<string, mixed>  $tata
     * @return array<string, mixed>
     */
    public static function tataLetakKabel(array $tata): array
    {
        // Urutan kunci kanonik: kolom JSON MySQL mengurutkan ulang kunci objek
        // (panjang dulu, lalu byte), SQLite tidak — tanpa ini kabelnya beda per DB.
        $tata = array_replace(self::tataLetakKosong(), $tata);

        foreach (['lebar_kolom', 'tinggi_baris', 'cf_gaya_dasar'] as $peta) {
            $tata[$peta] = (object) (is_array($tata[$peta]) ? $tata[$peta] : []);
        }

        return $tata;
    }
}
