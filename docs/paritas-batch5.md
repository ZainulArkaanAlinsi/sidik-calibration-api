# Paritas aplikasi lawan Excel: batch alat ke-5 (Dial Indicator, Height Gauge, Timer dan Stopwatch, Hydrometer, Sieve, Viscometer)

Dibuat 26 Nov 2026 (cabang `feat/paritas-batch5`, dari `origin/main` 6361f281). Tidak ada kode aplikasi (`app/`) yang diubah. Dokumen ini hanya melaporkan; selisih tidak diperbaiki dan tidak dipilih agar tes lulus. Pola, istilah, dan toleransi sama dengan `docs/paritas-micrometer-ph.md`, `docs/paritas-analitik-batch2.md`, dan `docs/paritas-batch4.md`.

Label: **SAMA** = aplikasi = Excel dalam toleransi bertulis, dibuktikan tes yang dijalankan. **SELISIH** = beda yang terukur (tes menegaskan perilaku sekarang dengan penanda `SELISIH P-nn` dan `#[Group('menunggu-keputusan-tm')]`). **MENUNGGU** = butuh keputusan orang atau bukti yang belum ada, atau belum diuji di sini (alasannya ditulis). Status "TERBUKTI" per alat bukan diputuskan di sini.

Penomoran selisih batch ini mulai dari `P-30` (batch 4 berakhir di `P-25`).

Dokumen ini ditambah per alat, satu alat satu commit. Alat yang belum punya bagian di bawah belum dikerjakan.

## 1. Ringkasan

| Alat | Hasil | SAMA | SELISIH | MENUNGGU |
|---|---|---|---|---|
| Dial Indicator (mm, 10 titik) | semua tahap sampai titik, 10 komponen, agregasi, jalur profil, dan angka cetak titik SAMA; U95 akhir BEDA dari Excel dan selisihnya habis dijelaskan P-30 sampai P-32 | 9 | 5 | 3 |

## 2. Cara membaca, menjalankan, dan menghasilkan ulang

- Tes per alat di `tests/Feature/Paritas/Paritas<Alat>Test.php`. Jalankan satu berkas: `php vendor/phpunit/phpunit/phpunit --configuration=phpunit.xml tests/Feature/Paritas/ParitasDialIndicatorTest.php`. Gate MySQL penuh BELUM dijalankan di sini; CI yang menjalankan.
- Fixture: `tests/Fixtures/paritas/<alat>.json`. Tiap nilai Excel membawa alamat sel (`SHEET!SEL`); pesan galat tes menyebut sel itu. Dibangun `docs/skrip/gen-fixture-paritas-batch5.py` dari nilai-cache workbook (`py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-batch5.py <folder-Downloads> <alat>`). Workbook master terenkripsi dan dibuka dengan sandi dari pemilik, hanya di memori lewat variabel lingkungan `SIDIK_MASTER_SANDI`; tidak ada salinan tanpa sandi di repo, dan sandi tidak muncul di fixture, log, atau dokumen. Fixture tidak memuat nama atau alamat pelanggan, nomor sertifikat, nomor order, atau teknisi. Berkas workbook dan hash SHA-256-nya dicatat di bagian `workbook.sumber` tiap fixture.
- Skrip pembangkit menghitung ulang rantai `PERHITUNGAN U95%` dari sel komponen sebelum menulis, dan berhenti bila selisihnya di atas 1e-11 (1e-9 untuk `k`/U). Selisih terbesar tersimpan di fixture (`rekonstruksi`).
- Toleransi sama dengan `tests/Concerns/MenegakkanParitas.php`: relatif 1e-11, lantai mutlak 1e-12, `k` mutlak 1e-9, U relatif 1e-9.
- Angka cetak diuji pada pemformat produksi `TataLetakSertifikat::formatAngka` dengan format kolom dari profil, bukan render PDF penuh. Teks Excel dalam fixture adalah emulasi (nilai sel dengan format selnya), bukan teks yang dibaca dari render Excel.

## 3. Dial Indicator

Workbook: `Master Olah Data_Dial Indicator.xlsm` (SHA-256 `dadd9ab0a54cdd983cd30925d0bb62fd147a49b6897f061906aa61adb2ab48a2`). Satu sesi, pita 0-25 mm, resolusi 0,01 mm, sepuluh titik, lima pembacaan per titik. Tes: `ParitasDialIndicatorTest.php` (14 kasus, 707 pernyataan, lulus). Rekonstruksi rantai dari sel komponen mereproduksi cache dengan selisih relatif maksimum 1,44e-14.

Titik panggil tanpa HTTP: `DialIndicatorCalculator::hitungSesi()` dan `budget()`, serta `DialIndicatorProfile::hitungPerGrup()` (fungsi yang sama dengan preview, simpan, dan hitung ulang). Tes memakai `RefreshDatabase` karena `CalibrationProfile::kemampuanSesi` membaca tabel kemampuan.

### SAMA

1. Suhu ruang rata-rata (`INPUT DATA!H21`) dan STDEV pra-evaluasi (`PERHITUNGAN U95%!N25`, lihat batas bukti).
2. Ketidakpastian balok standar Evaluation: tiap balok (`H23`, `I23`), `O25` (µm), `P25` (mm).
3. Konstanta tabel (`V22`, `W22`, `S31`, `U31`), tanggal kalibrasi balok standar (`DATABASE!W13`), dan lantai CMC keempat pita (`DATABASE!T5:T8`).
4. Tiap titik, sepuluh titik: nilai terkoreksi tiap keping (`PERHITUNGAN!F`), total nominal (`H`), standar terkoreksi (`AB`), rata-rata (`N`), koreksi (`AD`), STDEV (`AC`), pembacaan.
5. Sepuluh komponen budget pada dasar yang sama dengan Excel (panjang `C61`, keping terbanyak `B61`, `Q31`, `X31`): ci, vi (kecuali pengulangan), ui, ui x ci. Drift hanya dibanding ci dan vi.
6. Umur drift aplikasi pada `DATABASE!X11` (cache `NOW()`) lawan hitung ulang `X11 - W13`.
7. Agregasi dari ui, ci, vi milik Excel: uc (`AA16`), jumlah kuadrat (`AA15`), v_eff (`AA17`), k (`AA18`), U (`AA19`), CMC (`AA20`), U sertifikat (`AA21`).
8. Jalur profil `hitungPerGrup()`: titik ukur, rata-rata, koreksi, error, STDEV, jumlah pengulangan, tanpa toleransi dan tanpa vonis; kolom ketidakpastian sama dengan `hitungSesi()` pada tanggal yang sama.
9. Angka cetak sepuluh titik (standar, UUT, koreksi, format `0.00`) dan `k` (`0`, teks `2`).

### SELISIH

- **P-30, derajat bebas pengulangan.** Aplikasi `vi` = 9 (sepuluh bacaan Evaluation dikurangi satu); Excel `PERHITUNGAN U95%!Q5` = 4. Pada data ini komponen keterulangan nol di kedua sisi, jadi v_eff dan k tidak berubah (dikunci di tes). Pengaruhnya pada sesi bersebaran nyata belum terbukti.
- **P-31, pembagi umur drift.** Aplikasi membagi umur (hari) dengan 365; master `K10` dengan 12. Komponen drift aplikasi = `K10` x 12/365 / akar 3 (rasio Excel/aplikasi 365/12). Sudah tertulis di `DialIndicatorCalculator` sebagai keputusan 16 Sep 2026, paraf Manajer Teknis menyusul. Bila hanya ini yang berbeda, U95 tercetak 0,0086 lawan 0,0088 di Excel (arah tidak aman).
- **P-32, panjang untuk koefisien sensitivitas termal.** Aplikasi memakai total nominal terbesar sesi (24,50011 mm); Excel `PERHITUNGAN!C61` = 21 (keping pertama titik terakhir). ci suhu ruang, koefisien muai, dan selisih suhu aplikasi lebih besar tepat sebanding rasio panjang (arah aman).
- **P-33, komponen ke-11 histeresis.** Ada di aplikasi, tidak ada di Excel. Bernilai 0 pada data ini (lima bacaan per titik, ganjil, tidak berpasangan), jadi uc dan k tidak berubah.
- **P-34, rantai penuh.** `hitungSesi()` pada `X11`: U95 aplikasi lebih kecil dari Excel `AA19`, di atas lantai CMC 0,0065 (lantai tidak aktif), `boleh_terbit` benar, dan seluruh bedanya sama dengan agregasi dari komponen Excel yang hanya diganti pada P-30, P-31, P-32. Tes ini merah bila ada selisih keempat yang belum bernomor. Angka cetak U95: aplikasi 0,0086, Excel 0,0088. Ini satu-satunya angka cetak yang berbeda pada alat ini.

### MENUNGGU

- **Suhu yang tercetak lawan suhu hitungan.** `SERTIFIKAT!O12` = 20,59, sedangkan suhu yang dipakai hitungan `INPUT DATA!H21` = 20,75. Asal bedanya belum ditelusuri; tidak ada tes.
- **Nol bertanda minus.** Excel mencetak `-0.00` pada kolom koreksi titik 1 dan 4 (nilai negatif yang membulat ke nol); aplikasi sengaja tidak mencetak tanda minus (`DialIndicatorProfile::tandaNolDicetak()`). Tes membandingkan tanpa tanda dan mengunci jumlah barisnya (titik 1 dan 4). Perlu keputusan apakah cetakan master dipertahankan.
- **Batas bukti, jangan dibaca lebih dari ini.**
  - Sepuluh bacaan blok Evaluation di workbook ini identik (25,01 sepuluh kali), jadi STDEV dan komponen keterulangan nol di kedua sisi. Rumus keterulangan dan cabang sebaran TIDAK terbukti oleh data ini.
  - Lima bacaan tiap titik identik, jadi STDEV titik nol di kedua sisi.
  - Satu sesi (pita 0-25 mm); pita 50, 100, dan 300 mm hanya diadu pada nilai CMC-nya.
  - Umur drift diadu pada `X11` yang tersimpan, bukan pada tanggal kalibrasi sesi; U95 cache Excel tumbuh tiap workbook dihitung ulang.

## 4. Yang tidak dikerjakan di batch ini

- Suite MySQL penuh (3 jam) dan render PDF sertifikat penuh.
- Perubahan kode di `app/`. Semua selisih di atas hanya dicatat.
