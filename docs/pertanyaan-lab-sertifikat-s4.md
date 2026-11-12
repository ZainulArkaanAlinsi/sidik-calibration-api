# Pertanyaan ke Lab — Sertifikat persis master, tahap S-4

Status: MENUNGGU jawaban (Q1 sudah dijawab pemilik 12 Nov 2026) · Dibuat 12 Nov 2026 · Sumber: banding ulang
`pressure_gauge`, `vacuum_gauge`, `differential_pressure`, `tids`, `bath`,
`furnace`, `refrigerator` terhadap lembar `SERTIFIKAT` master masing-masing
(Druck 07G / 13G / SPMK / Differential, TIDS Recorder Graptech + Yokogawa K N,
Enclosure Constant Yokogawa + Recorder). Laporan lengkap:
`_agents_workspace/audit-sertifikat-20261109/07-banding-ulang-s4.md`.

Aturan sama dengan B1: yang datanya tidak ada di input app TIDAK ditebak.

---

## 1. Bath, Furnace, Refrigerator — benar memakai master Enclosure?

**DIJAWAB pemilik 12 Nov 2026: Furnace/Bath/Refrigerator satu paket dengan Oven dan
Inkubator dalam master Enclosure.** Susunan master Enclosure (9 termokopel + diagram
kubus) dipakai untuk kelima jenis; tidak ada master terpisah yang ditunggu. (Pertanyaan
asli di bawah dibiarkan sebagai riwayat.)

Ketiganya tidak punya workbook sendiri; app memakai lembar `SERTIFIKAT` master
Enclosure Constant Yokogawa / Recorder (kubus posisi termokopel 1-9, Sebaran
Suhu, Kinerja Enklosur) lewat `EnclosureProfileBase`, sama dengan Oven dan
Inkubator. Untuk Furnace (300–1000 °C) dan Refrigerator (−20…10 °C) kubus 1-9 dan
kalibrator Yokogawa/Recorder bisa jadi bukan susunan yang dipakai di lapangan.

**Yang ditanyakan:** apakah susunan master Enclosure (9 termokopel + diagram
kubus) memang baku untuk ketiga jenis itu, atau ada master khusus yang belum
dikirim?

## 2. Baris kosong tabel hasil tekanan (13 baris tetap)

Keempat master tekanan mencetak tabel 13 baris bergaris tetap (baris tanpa data
tampil kosong; Druck 07G bahkan menampilkan `#VALUE!` di baris kosong — kerusakan
rumus yang TIDAK ditiru). App hanya mencetak baris berdata, seperti semua profil
lain.

**Yang ditanyakan:** apakah baris kosong sampai 13 baris perlu dicetak (kotak
tabel selalu setinggi 13 baris), atau cukup baris berdata?

## 3. Capacity/Graduation tekanan: teks bebas di master

Master mengetik Capacity sebagai teks bebas (`0-2000` di 07G dan 13G, `0-10` di
Differential, `20` di SPMK). App membangun `min–max satuan / resolusi satuan`
dari rentang alat terdaftar (tanda hubung panjang `–`).

**Yang ditanyakan:** apakah bentuk app cukup, atau Capacity perlu menjadi isian
teknisi (satu teks, dicetak apa adanya seperti master)?

## 4. Nomor seri Additel (Differential)

Master `Differential` mencetak `21H18280008` (tabel standar & `SERTIFIKAT`);
formulir kertas FM-CAL-0507 dan seeder app memakai `211H18280008`.

**Yang ditanyakan:** nomor seri yang benar yang tertulis di sertifikat kalibrasi
Additel?

## 5. Nama standar di tabel Standard used

Master mengetik nama generik (`Pressure Calibrator`, `Pressure Vacuum
Calibrator`, `Digital Pressure Gauge`, `Temperature Calibrator`, `Thermocouple
Type K`) dan kolom Merk/Type `-/Type K`; app mencetak isi master standar lab
(`Druck DPI611-13G`, …).

**Yang ditanyakan:** apakah kolom nama/merk di master standar lab perlu
diseragamkan ke ejaan master Excel (jadi cetakannya identik)?

## 6. "Convidence Level" pada kalimat faktor cakupan

Master menulis "Convidence" (salah ketik) di keempat master tekanan, kedua TIDS,
dan kedua Enclosure. App tetap menulis "Confidence" (kebijakan G4).

**Yang ditanyakan:** tetap dikoreksi, atau ditiru persis "Convidence"?

## 7. Issuance Date: letak dan hari tambahan

(a) Master mencetak label `Issuance Date` di satu baris dan tanggalnya di baris
berikutnya; app mencetak sebaris. (b) Hari tambahan yang terbukti dari rumus
master (`P16+N`, `P16` = Calibration Date): Enclosure Constant Yokogawa +1,
Enclosure Recorder +4 (`INPUT DATA!E168`, `E100`); TIDS Recorder +4, TIDS
Yokogawa K N +2 (`INPUT DATA!E67`). Dipakai sejak S-4, dibatasi hari penerbitan.

**Yang ditanyakan:** (a) letak tanggal perlu diikuti? (b) apakah N itu aturan lab
atau kebiasaan per workbook (kalau berubah, siapa yang menetapkan)?

## 8. Vacuum Gauge — kode metode

Master Druck07G (jalur vakum) mencetak `SIDIK-IK-CAL-0504_Rev.6`; app memakai
`SIDIK-IK-CAL-0534_Rev.1` sesuai lampiran akreditasi no. 25 (sudah tercatat
sebagai P-13).

**Yang ditanyakan:** kode mana yang tercetak untuk alat vakum?

## 9. TIDS Recorder — baris pertama Standard used bergeser di master

`SERTIFIKAT!I41` mencetak "Temperature Recorder" (nama tipe) di kolom Merk/Type dan
`L41` mencetak "Graptech/GL840" di kolom Serial Number (workbook Yokogawa K N
mencetak merk di `I41` dan serial di `L41`). App mencetak Merk/Type
`Graptech/GL840`, Serial `C305B1470`; selisihnya tercatat di jejak sesi
(`tids_recorder_standard_used_bergeser`).

**Yang ditanyakan:** benar cetakan yang bergeser itu kesalahan master, bukan
format yang disengaja?

## 10. Kapasitas TIDS dan Enclosure belum diketik

Master mencetak Capacity yang diketik (`INPUT DATA!E15`, mis. `250 °C`, `100 °C`,
`300 °C`). App memakai isian "Kapasitas Alat" lembar kerja; bila kosong,
rentang atas alat terdaftar (`equipments.range_max`).

**Yang ditanyakan:** isian "Kapasitas Alat" perlu dijadikan wajib sebelum sesi
dikirim?

## 11. Pembulatan kasus seri persis (tie): 0,76 di app vs 0,75 di PDF master

Contoh: TIDS Recorder titik 94.24, kolom Correction. Standard Reading `94.235`,
dikurangi `93.48` = tepat `0.755` dalam hitungan tangan. Di pelampung (float)
hasilnya `0.7549999999999955` (master `INPUT DATA`/`SERTIFIKAT`: `E22` =
`94.23499999999999`, `L22` = `0.7549999999999955`).

- Kalkulator app memberi `0.7549999999999955`. Dicetak lewat
  `Angka::tampilanExcel(..., 2)` (15 digit seperti Excel) nilai itu menjadi `0,75`,
  SAMA dengan PDF master.
- Nilai disimpan di kolom `decimal(20,8)` (`uncertainty_calculations.koreksi`; di
  MySQL dibaca balik sebagai string). Penyimpanan membulatkannya ke 8 desimal:
  `0.75500000`. Dari nilai tersimpan itu `tampilanExcel(..., 2)` menghasilkan
  `0,76`. Itulah angka yang tercetak di sertifikat app.

Jadi penyebabnya BUKAN data seeder, bukan perbedaan LibreOffice vs Excel, dan bukan
rumus: selisih satu digit muncul dari pembulatan penyimpanan pada kasus seri
persis. Perilaku kode TIDAK diubah di tahap ini (nilai antara tidak dibulatkan di
tengah jalur, dan angka tersimpan tidak pernah dihitung ulang saat dibaca). Mencetak
`0,75` hanya mungkin dari nilai tak terbulat `0.7549999999999955`, yang tidak muat
di `decimal(20,8)`.

**Yang ditanyakan:** untuk selisih satu digit pada kasus seri persis seperti ini,
acuan mana yang berlaku: `0,75` (angka tampilan workbook master/PDF master) atau
`0,76` (nilai yang dibulatkan ke 8 desimal saat disimpan)? Bila acuannya `0,75`,
nilai tak terbulat harus disimpan di kolom yang lebih presisi atau bentuk lain;
itu perubahan data dan butuh keputusan pemilik, bukan sekadar perbaikan cetak.
