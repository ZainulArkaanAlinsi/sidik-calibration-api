---
name: sidik-kalkulasi-presisi
description: Audit konsistensi angka & presisi pada perhitungan GUM/ketidakpastian kalibrasi — preview vs data tersimpan, versi rumus, pembulatan, kondisi lingkungan. Pakai saat mengubah logika perhitungan kalibrasi, menambah alat baru, atau mengubah kolom presisi/rumus.
---

# Sidik Kalkulasi & Presisi Numerik

Ini setara "concurrency audit" di proyek Go — tapi jebakan licik di proyek ini
BUKAN goroutine, melainkan **angka yang diam-diam beda** antara dua jalur yang
seharusnya identik. Untuk lab terakreditasi, dua angka berbeda untuk satu
pengukuran yang sama itu temuan audit, bukan bug kosmetik.

## Jebakan Utama

### 1. Preview vs Store harus identik bit-per-bit
`POST /calibrations/preview` (hitung tanpa simpan) dan `POST /calibrations`
(simpan) WAJIB memakai fungsi penyusun angka yang SAMA PERSIS
(`susunPengukuran()`), bukan disalin jadi dua implementasi. Kalau menambah
logika perhitungan baru, pastikan dia masuk ke fungsi bersama itu, bukan
ditambal terpisah di salah satu endpoint saja.

### 2. Pembulatan terjadi SEBELUM masuk DB, di satu tempat
Konstanta desimal (`DESIMAL_PEMBACAAN`, `DESIMAL_SUHU`, `DESIMAL_K`, dst di
`CalibrationController`) harus persis mencerminkan presisi kolom migrasi
(`decimal(20,8)`, dll). Kalau kolom migrasi berubah, konstanta ini WAJIB ikut
berubah — cek `[[sidik-data-layer]]`. Jangan bulatkan di lapisan lain
(Resource, frontend) — itu memberi peluang dua tempat membulatkan beda cara.

### 3. Versi rumus (Formula Version) di-stempel sekali per sesi
`RumusKalibrasi::versiUntukSesi()` dipanggil SEKALI di luar loop titik ukur,
lalu dipakai untuk semua titik sesi itu — bukan dipanggil per titik. Ini
menjaga sertifikat lama tetap bisa dijelaskan setelah rumus berubah di
kemudian hari (audit trail perhitungan). Jangan hilangkan stempel ini saat
menambah alat baru.

### 4. Bentuk cetak ≠ nilai — cocokkan ke workbook master
Jumlah desimal yang TERCETAK di sertifikat dibaca dari format sel workbook
Excel master lab, bukan dari resolusi alat. Nilai yang secara matematis benar
tapi jumlah desimalnya beda dari master tetap dianggap salah oleh lab
(`[[format-cetak-dari-sel-master]]`). Saat menambah alat/profil baru, minta
atau cek contoh workbook sebelum menetapkan desimal tampilan.

### 5. Suhu ruang mentah vs terkoreksi — jangan tertukar
Beberapa perhitungan (mis. Refractometer) sengaja memakai rata-rata suhu
ruang MENTAH (sebelum koreksi sertifikat thermohygro), bukan `suhu_ruang`
yang sudah terkoreksi — karena begitu cara workbook master menghitungnya.
Kalau menambah alat baru yang sensitif suhu, cek dulu source mana yang benar
untuk alat itu, jangan asumsikan selalu pakai yang sudah terkoreksi.

### 6. Faktor cakupan k bukan konstanta 2
`k` dihitung dari distribusi t-Student pada derajat kebebasan efektif
(`StudentTDistribution`), lalu dibulatkan sebelum disimpan. Siapa pun yang
mengalikan ulang `k × u_c` dari kolom yang sudah dibulatkan bisa dapat angka
beda tipis dari `U` yang sebenarnya dilaporkan — jangan hitung ulang `k×u_c`
di lapisan lain, pakai `U` yang sudah tersimpan.

## Checklist Saat Menambah Alat/Profil Baru
- [ ] Profil baru (`app/Services/Calibration/Profiles/`) implement
      `CalibrationProfile`, didaftarkan di `CalibrationProfileRegistry`.
- [ ] Presisi tampilan dicocokkan ke workbook master, bukan ditebak.
- [ ] Kalau ada varian satuan per titik (seperti Conductivity/Refractometer),
      pastikan mekanisme `eksklusif_dengan` / resolusi variannya jelas — lihat
      `adaVarianBelumDitentukan()` di `CalibrationController` sebagai contoh
      kelas masalah yang harus dicegah untuk alat baru.
- [ ] Test bandingkan angka `preview` vs angka tersimpan untuk skenario yang
      sama (lihat `[[sidik-test-verifier]]`), dan verifikasi juga di MySQL.

## Guidelines
- Setiap perubahan di sekitar file `GumCalculator`, `PerhitunganBuilder`,
  `KondisiLingkungan`, `RumusKalibrasi`, atau `app/Services/Calibration/**`
  WAJIB memicu skill ini sebelum dianggap selesai.
- Kalau ragu kenapa suatu angka dihitung dengan cara tertentu, cari komentar
  WHY di sekitarnya dulu (kode ini padat dengan alasan historis/insiden lab)
  sebelum mengubahnya.
