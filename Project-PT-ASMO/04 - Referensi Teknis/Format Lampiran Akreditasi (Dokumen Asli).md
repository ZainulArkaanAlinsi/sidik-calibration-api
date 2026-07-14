---
aliases: [Format Lampiran Akreditasi, Format Sertifikat]
---

# Format Lampiran Akreditasi — Dokumen Asli

🏠 [[Dashboard]] · Sumber: `Project-PT-ASMO/LK 285 IDN_PT Sidik_draft.pdf` (9 halaman)

Ini **dokumen resmi PT Sidik**, bukan contoh karangan. Dua fungsinya:

1. **Sumber data** [[Data Kemampuan Kalibrasi]] — 48 alat, 151 rentang kemampuan, udah diimpor ke tabel `equipment_categories` + `calibration_capabilities` lewat seeder.
2. **Acuan bentuk** buat sertifikat yang nanti digenerate aplikasi (Minggu 08). Layout & kalimat formalnya ngikutin ini, jangan bikin gaya sendiri.

## Kop — muncul di TIAP halaman

| Bagian | Isi di dokumen asli | Sumber datanya di DB |
|---|---|---|
| Judul | LAMPIRAN SERTIFIKAT AKREDITASI LABORATORIUM NO. **LK-285-IDN** — SNI ISO/IEC 17025:2017 | `organizations.no_akreditasi`, `standar_akreditasi` |
| Subjudul | KEMAMPUAN KALIBRASI DAN PENGUKURAN (CMC) LABORATORIUM KALIBRASI | statis |
| Nama LPK | PT Sistem Dirgantara Inovasi Teknologi | `organizations.nama` |
| Alamat | Kawasan Niaga MTC/ MIM Blok J No 25, Buahbatu, Kota Bandung, Jawa Barat | `organizations.alamat` |
| Kontak | Telp. (022)7537623 · Email: sidikkalibrasi@pt-sidik.com | `organizations.telepon`, `email` |
| Masa berlaku | **28 Oktober 2024 s/d 27 Oktober 2029** | `organizations.akreditasi_mulai`, `akreditasi_berakhir` |
| Nomor halaman | "1 dari 9" | dihitung waktu render |

## Kolom tabel

`No.` · `Kelompok pengukuran` · `Alat/bahan/standar yang dikalibrasi` · `Parameter` · `Rentang ukur` · `Ketidakpastian yang diperluas *)` · `Metode kalibrasi / dokumen standar / teknik` · `Keterangan`

Semua kolom itu udah ada padanannya di tabel `calibration_capabilities` (lihat [[ERD Awal]]).

## Catatan kaki — WAJIB ikut kecetak

Dua kalimat ini disalin **persis** (jangan diparafrase — ini pernyataan formal ke KAN). Disimpan di `organizations.settings`:

> **\*)** Ketidakpastian yang diperluas dinyatakan pada tingkat kepercayaan 95% dengan faktor cakupan k = 2 yang merupakan ketidakpastian terbaik yang dapat dicapai dalam layanan kalibrasi rutin dengan sumberdaya yang dimiliki laboratorium

> **2)** Lampiran sertifikat akreditasi ini tidak boleh digandakan kecuali seluruhnya, tanpa persetujuan tertulis dari pihak KAN

## Yang perlu diingat waktu bikin generator sertifikat (Minggu 08)

- **Masa berlaku akreditasi itu data, bukan hiasan.** Lab yang akreditasinya udah lewat nggak boleh nerbitin sertifikat terakreditasi. Cek pakai `Organization::akreditasiMasihBerlaku()` sebelum generate. Sekarang berlaku sampai **27 Okt 2029**, jadi nggak keliatan mendesak — tapi jangan sampai lupa ditanam.
- **`k = 2` bukan konstanta hardcode.** Dia ada di `organizations.settings.faktor_cakupan_default` dan per-kemampuan di `calibration_capabilities.faktor_cakupan`. Nilai yang dipakai waktu ngitung **disimpan** di `uncertainty_calculations.faktor_cakupan_k` — biar sertifikat lama tetap nunjukin angka yang sama walau default-nya nanti berubah.
- **Penulisan angka ikut dokumen asli**: `0.50 °C` (bukan `0.5`), `0.000062 n20D` (bukan notasi ilmiah `6.2e-05`). Ini soal keterbacaan buat auditor — nanti diformat waktu render, datanya sendiri tetap disimpan sebagai `decimal`.
- Satuan di dokumen aslinya pakai simbol derajat yang beda-beda (`˚C`, `oC`) karena hasil ketikan Excel. Di DB udah dinormalisasi jadi `°C`.
