# Audit: koreksi otomatis nilai OCR di kode yang sudah ada

Tanggal: 23 Nov 2026. Cara: pencarian kata kunci + pembacaan terarah di API dan mobile. **Tidak ada yang diubah**
(permintaan pemilik: laporkan dulu). Bukan audit menyeluruh; bagian yang belum diperiksa ada di bawah.

Ukuran dari pemilik: hasil OCR tidak boleh diubah agar cocok dengan templat, rentang, atau pola angka; validasi hanya
boleh menolak atau menandai; rentang/format hanya untuk menolak dan asalnya dari spesifikasi alat yang disetujui.

## Tempat yang MENGUBAH atau MEMILIH nilai (melanggar ukuran di atas, atau berisiko)

| # | Tempat | Yang terjadi | Catatan |
|---|---|---|---|
| 1 | `app/Services/Ocr/NormalisasiAngka.php:34-44` (`MIRIP_DIGIT`) | Huruf/simbol diganti digit: `O o Q D`→0; `I l i \| ! /`→1; `Z z`→2; `A`→4; `S s`→5; `G b`→6; `T`→7; `B`→8; `g q`→9 | Tiap penggantian dicatat (`substitusi`), menurunkan skor, dan memberi alasan lunak `ada_koreksi_karakter` (`ValidasiSel::periksaLunak`). Kodenya sendiri menyebutnya "tebakan, bukan bacaan". **Risiko khusus: `\|` dan `/` (goresan garis tabel) menjadi angka 1.** |
| 2 | `NormalisasiAngka.php` `kandidatTafsiran()` (sekitar baris 227-280) | Titik/koma ambigu ("1.413": desimal atau ribuan) diputuskan dengan **menyaring pakai bukti templat (jumlah desimal dan rentang titik)**; lolos satu = dipakai | Ini rentang/format dipakai untuk MEMILIH tafsiran, bukan hanya menolak. |
| 3 | `NormalisasiAngka.php` `buangSpasi()` (baris 160) | Spasi dibuang: "12 .5" menjadi "12.5" | Menyambung dua token jadi satu angka. |
| 4 | `app/Services/Ocr/NormalisasiJam.php` (baris 53-61) | Pemisah diseragamkan jadi `:` dan digit dikelompokkan | Belum dibaca penuh; kemungkinan menyisipkan `:` pada deret digit. Perlu diperiksa. |
| 5 | `lib/services/peta_tabel_foto.dart` `_angka()` (baris 1998-2006) | Hanya menerima `^-?[0-9][0-9.,]*$`, lalu `,`→`.` untuk parsing | Normalisasi koma desimal; tidak menyisipkan/memindahkan koma. Teks asli perlu dipastikan tetap disimpan (belum dicek). |
| 6 | `lib/services/ocr_angka/dekoder_ctc.dart` + alfabet model (`0123456789.,-`) | Model dipaksa memilih hanya digit/tanda baca; huruf tidak pernah keluar | Pilihan paksa oleh whitelist: huruf mirip digit dibaca sebagai digit tanpa catatan. |

## Tempat yang hanya MENOLAK atau MENANDAI (sesuai ukuran)

- `ValidasiSel::periksaKeras` (di luar rentang, rasio ke nominal, teks meluber dari sel) dan `periksaLunak` (desimal
  kebanyakan, bukan kelipatan resolusi). Komentar kodenya menegaskan nilai tidak dibulatkan diam-diam. **Yang belum
  dibuktikan: asal `min`/`maks`/`nominal`/`resolusi` (dari `TemplateLembarKerja::aturan*`, profil/CMC) adalah
  spesifikasi alat yang disetujui.**
- `lib/services/ocr_angka/aturan_format_sel.dart` dan `keputusan_sel.dart` (gerbang format, ambang `jalur` 0,99,
  pagar tinta): hanya menurunkan sel ke "perlu dicek".
- `pembaca_sel.dart:173`: hanya merapikan spasi ganda.
- `peta_tabel_foto.dart` pencocokan jangkar baris dengan toleransi titik: memilih sel tujuan dari nilai standar yang
  terbaca, tidak mengubah nilai sel bacaan.

## Belum diperiksa

`WorksheetVisionExtractor` dan `EkstraktorDokumenGenerik` (jalur LLM; tidak boleh diubah), `ValidasiSel::periksa`/`periksaJam`
secara penuh, `PemrosesScanLembarKerja::prosesAsli`, `FormulirAsli` (aturan angka per sel), dan widget layar review mobile
yang memformat nilai.

## Catatan terkait versi lama

`template_versi` **wajib** di `POST /worksheet-scans` (`WorksheetScanRequest` baris 81 dan 117): aplikasi lama yang tidak
mengirimnya ditolak `422` dengan pesan validasi Laravel bawaan ("The template versi field is required"), bukan diterima
diam-diam. Versi yang dikirim tetapi berbeda ditolak `PemrosesScanLembarKerja::periksaTemplate` (baris 160-170) dengan pesan
Indonesia. `ValidasiSel.php:187` adalah hal lain: `kotak_teks_di_dalam_sel` yang `null` dari HP lama diturunkan menjadi
alasan lunak (tidak lulus diam-diam, tidak gagal).
