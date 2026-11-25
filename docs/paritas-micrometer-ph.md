# Paritas aplikasi lawan Excel: Micrometer (0522.A-D) dan pH Meter

Dibuat 24 Nov 2026 (cabang `test/paritas-micrometer-ph`, dari `origin/main` 34fd7c70). Tidak ada kode aplikasi (`app/`) yang diubah. Dokumen ini hanya melaporkan; selisih = KONFLIK untuk Manajer Teknis (TM), tidak diperbaiki dan tidak dipilih agar tes lulus.

**Diperbarui 25 Nov 2026** (cabang `feat/paritas-micrometer-final`, dari `origin/main` b9525fb9): status akhir tiap butir Micrometer diputuskan memakai keputusan default koordinator (bagian 0). Bagian 1-7 tetap catatan bukti 24 Nov; label MENUNGGU di sana digantikan bagian 0. Tidak ada kode `app/`, rumus, atau nilai acuan Excel yang diubah; yang berubah hanya tes (nama/docblock K-1, satu tes K-10 baru) dan dokumen.

Label 24 Nov: **SAMA** = aplikasi = Excel dalam toleransi bertulis. **SELISIH** = beda yang terukur (dilaporkan sebagai konflik P-n). **MENUNGGU** = butuh keputusan orang atau bukti yang belum ada. Status "TERBUKTI" per alat BUKAN diputuskan di sini; ini bukti yang tersedia untuk koordinator/TM.

## 0. Status akhir per butir Micrometer (25 Nov 2026)

Sumber keputusan: `sidik-dokumentasi/2026-11-25/keputusan/KEPUTUSAN-DEFAULT.md` (default koordinator atas delegasi pemilik; Manajer Teknis TIDAK ditunggu). Itu BUKAN jawaban TM. Keyakinan tiap butir ditulis apa adanya dan TM dapat membalik tiap default; bila pembalikan menyentuh rumus, butirnya ditandai di kolom dasar.

Label akhir: **SAMA** = aplikasi = Excel/keputusan default dalam toleransi bertulis. **SELISIH-DITERIMA-DENGAN-ALASAN** = beda terukur, sengaja, dengan alasan dan sumber tertulis. **PERLU-KODE** = butuh ubah rumus produksi (TIDAK dilakukan di sini). **TERBLOKIR-BUKTI** = bukti yang diperlukan tidak ada; tidak ditebak.

| # | Butir | Label akhir | Dasar / sumber default | Keyakinan |
|---|---|---|---|---|
| 1 | P-1 = K-1 = A1: titik 3 pita 50-75 mm | **SAMA** | Default B: 51,0. Kertas 0522.C Rev.1 = master 50-75 (`PERHITUNGAN!H37` = 51,00025) = aplikasi (`database/data/tabel-standar-micrometer.json`, `TabelStandarMicrometer.php`). 55,6 hanya di cetakan job 0455-CAL-1026. Tes `test_k1_..._keputusan_default_51_0`. Tanpa ubah kode. Sertifikat job 0455-CAL-1026 bila sudah terbit perlu ditinjau (Standard Reading 55,600 lawan 51,000) | sedang |
| 2 | K-9: tumpukan keping dipatok | **SAMA** | Default A (kode sekarang). Tes `test_tumpukan_keping_tabel_varian_sama_dengan_isian_workbook` | sedang |
| 3 | K-6 = A6 dan pertanyaan-lab §10: kotak kerataan/kesejajaran muka ukur | **SAMA** (sisi aplikasi) | Default A: tambah kotak di kertas 0522/0527 ([K] kertas, bukan kode). Aplikasi sudah menampung (`MicrometerProfile.php:1053-1060`, blok Note `catatanSertifikat`); kertas yang belum punya | sedang |
| 4 | K-8: header gauge block 50-75 (2021 / LK-102-IDN) | **SAMA** (kosmetik) | Default A: samakan header ([K] workbook). Hitungan memakai `DATABASE!W13` = 24 Jan 2024 di keempat master; drift ui cocok 0,0e+00 di keempat ukuran (bagian 4) | sedang |
| 5 | K-13a: terbit sesuai resolusi isian sesi; resolusi kosong ditahan | **SAMA** | Default A. Kode: `MicrometerCalculator.php:371-380` (`boleh_terbit` mensyaratkan pita CMC, sebaran pra-evaluasi, `resolusi_mm > 0`) | rendah |
| 6 | Pertanyaan-lab §5, §6, §8: suhu/muai kembar, `vi = 200`, selisih suhu nol menurut konstruksi | **SAMA** | Ditiru persis master; ci/vi sembilan komponen cocok di keempat ukuran (bagian 4). Kebenaran METODE (bukan paritas) tetap perlu konfirmasi ahli | tidak ada butir default |
| 7 | Pertanyaan-lab §9: format cetak tiga desimal koreksi/U95 | **SAMA** | Keputusan pemilik K-50-15 (sertifikat = lembar SERTIFIKAT master persis; `MicrometerProfile.php:774-783`); angka cetak sama persis di keempat ukuran. Catatan: di 25-50 dan 75-100 seluruh baris koreksi tercetak `0.000`/`-0.000`; ini persis yang §9 sebut berisiko, jadi perlu konfirmasi ahli apakah masih dikehendaki | tidak ada butir default |
| 8 | P-2: sesi 0-25 mm tersimpan cacat; aplikasi menahan, Excel menerbitkan 0,7354 um di bawah CMC 0,83 um | **SELISIH-DITERIMA-DENGAN-ALASAN** | Penyimpangan sengaja no. 1. K-7 default B: cetakan tanpa sebaran = placeholder, bukan data uji. K-13 default A. Tes `test_025_...` dan `test_jalur_profil_025_...` tetap | sedang (K-7); rendah (K-13) |
| 9 | P-3: panjang ci termal (0-25 mm) | **SELISIH-DITERIMA-DENGAN-ALASAN** | Penyimpangan sengaja no. 3. Terbukti satu-satunya penyebab (rumus sel dengan F61 := 25,00027 = aplikasi); ci aplikasi lebih BESAR (arah aman); angka cetak tidak berubah. TIDAK ada butir default: konfirmasi ahli tetap diminta | tidak ada butir default |
| 10 | P-4: drift dari tanggal kalibrasi sesi, bukan `NOW()` | **SELISIH-DITERIMA-DENGAN-ALASAN** | Penyimpangan sengaja no. 2; acuan sah = hitung ulang rumus sel pada 2026-06-30 + silang-cek cache X11. TIDAK ada butir default | tidak ada butir default |
| 11 | K-10: kesejajaran tanpa centang | **SELISIH-DITERIMA-DENGAN-ALASAN** | Default A: tidak dicetak. Master mencetak "Buruk" dari kotak `W28` kosong (cache; 25-50, 50-75, 75-100). Aplikasi tidak mencetak pemeriksaan yang tidak dilakukan. Tes baru `test_k10_kerataan_kesejajaran_tanpa_isian_tidak_dicetak` mengunci perilaku aplikasi. Bertentangan dengan prinsip "persis master" | rendah |
| 12 | K-11: `Thermohygro Used : TH-1` statis di kertas 0522 | **SELISIH-DITERIMA-DENGAN-ALASAN** | Default A: hapus TH-1 dari kertas ([K] kertas). Master dan cetakan memakai TH-3; aplikasi dropdown (`MicrometerProfile.php:63-64`). Tidak diuji di sini | sedang |
| 13 | P-2b: sesi 0-25 mm benar-satuan untuk diadu | **TERBLOKIR-BUKTI** | Tidak ada workbook atau foto berlabel sesi 0-25 mm yang valid; K-7: apakah cetakan data nyata, tidak ada bukti | tidak ada bukti |
| 14 | P-4b: umur drift negatif (tanggal sesi < 24 Jan 2024) | **TERBLOKIR-BUKTI** | Excel selalu memakai `NOW()` sehingga tidak ada nilai acuan untuk umur negatif | tidak ada bukti |
| 15 | P-5: tanda minus pada koreksi yang membulat ke nol | **TERBLOKIR-BUKTI** | Fixture memuat emulasi tampilan, bukan render Excel asli; aplikasi tanpa minus (`tandaNolDicetak()=false`). Butuh PDF cetak Excel asli | tidak ada bukti |
| 16 | K-12: tahun cetakan 25-50 mm (kalibrasi 10 Oct 2023 sebelum diterima 10 Oct 2026) | **TERBLOKIR-BUKTI** | Default A: koreksi data job ([K], bukan kode). Tahun benar tidak ada bukti (dugaan 2026) | sedang untuk arah, tidak ada bukti untuk tahun |
| 17 | K-13b: mikrometer analog dibaca 0,01 mm? | **TERBLOKIR-BUKTI** | KEPUTUSAN-DEFAULT bagian 11: tidak ada bukti | tidak ada bukti |
| 18 | Pertanyaan-lab §11: satuan `ci` komponen termal (mm dalam budget um); bila dibetulkan U95 25-50 mm 0,872 -> 0,978 um, di atas CMC 0,87 um | **TERBLOKIR-BUKTI** | Tidak dicakup default. Dua masukan penentu belum ada (ketidakpastian pengukuran suhu Lab Dimensi; `delta_alpha_per_c` = alfa atau delta-alfa). **Menjadi PERLU-KODE** bila lab menjawab: `MicrometerCalculator.php:438-439` (ci) dan `:586`, `:594` (u). Kode saat ini meniru master dan ditegakkan tes | tidak ada bukti |
| 19 | Pertanyaan-lab §1, §3: sertifikat 0-25 mm yang sudah terbit di bawah CMC / koreksi -61 mm | **TERBLOKIR-BUKTI** | Aplikasi sudah menahan sesi baru. Tidak ada bukti sertifikat lama mana yang terbit; keputusan tinjau/terbit ulang milik TM | tidak ada bukti |

Hitungan: **SAMA 7**, **SELISIH-DITERIMA-DENGAN-ALASAN 5**, **PERLU-KODE 0** (1 bersyarat: butir 18), **TERBLOKIR-BUKTI 7**; total 19 butir.

Catatan kode (bukan butir paritas, tidak diubah di sini): komentar `MicrometerProfile.php:707-716` masih menulis "Itu tidak ditiru" tentang format `0.000`, sedangkan `:774-783` (K-50-15) dan tes angka cetak menegakkan format master. Komentar basi, perlu dirapikan oleh pemilik kode.

## 1. Ringkasan

| Alat / ukuran | Hasil | Catatan |
|---|---|---|
| Micrometer 25-50 mm (0522.B) | **SAMA** di semua tahap yang diuji | + P-5 TERBLOKIR-BUKTI (tanda minus pada 2 baris koreksi yang membulat ke nol; bagian 0 butir 15) |
| Micrometer 50-75 mm (0522.C) | **SAMA** untuk sesi master (titik 3 = 51,0) | P-1 (K-1) **SAMA** menurut keputusan default B (25 Nov): 51,0 (kertas = master = aplikasi); 55,6 (cetakan Excel job) tidak diikuti. Belum dikonfirmasi TM; sertifikat job 0455-CAL-1026 bila sudah terbit perlu ditinjau (bagian 0 butir 1) |
| Micrometer 75-100 mm (0522.D) | **SAMA** di semua tahap yang diuji | + P-5 TERBLOKIR-BUKTI (6 baris; bagian 0 butir 15) |
| Micrometer 0-25 mm (0522.A) | rantai **SAMA** pada angka yang sama, dengan 1 **SELISIH** tercatat (P-3) | P-2 **SELISIH-DITERIMA-DENGAN-ALASAN** (penahanan aplikasi benar) + sesi benar-satuan **TERBLOKIR-BUKTI** (bagian 0 butir 8 dan 13): sesi yang tersimpan di workbook CACAT (satuan inch, kapasitas 635, resolusi 1e-5, pra-evaluasi seragam). Tidak ada sesi 0-25 mm yang benar untuk diadu; aplikasi menahan penerbitan, Excel menerbitkan di bawah CMC |
| pH Meter, workbook `for trial` (vektor 1) | **SAMA** di semua tahap (standar, rata-rata, STDEV, koreksi, 5 komponen, uc, veff, k, U, lantai CMC, angka cetak) | konstanta seeder = sel workbook |
| pH Meter, workbook `IMTE-WQ-129` (vektor 2) | **SAMA** untuk standar, rata-rata, STDEV, koreksi, 4 dari 5 komponen, **angka cetak**; **SELISIH P-6** pada komponen `temperature` dan turunannya (uc, veff, k, U sebelum lantai) | konflik J1 yang sudah tercatat; cetak tetap sama karena lantai CMC menang di ketiga titik |

## 2. Cara membaca, menjalankan, dan menghasilkan ulang

- Tes: `tests/Feature/Paritas/ParitasMicrometerTest.php` (33 kasus), `tests/Feature/Paritas/ParitasPhMeterTest.php` (10 kasus). Jalankan: `php artisan test tests/Feature/Paritas` (SQLite). Gate MySQL penuh BELUM dijalankan di sini; CI yang menjalankan.
- Fixture: `tests/Fixtures/paritas/micrometer.json`, `ph-meter.json`. Tiap nilai Excel membawa alamat sel (`SHEET!SEL`); pesan galat tes menyebut sel itu. Dibangun `docs/skrip/gen-fixture-paritas-micrometer-ph.py` dari workbook (sandi hanya dari `SIDIK_MASTER_SANDI`, di memori; tanpa nama/alamat pelanggan, nomor sertifikat, nomor order, atau teknisi; dipindai 0 kemunculan).
- Titik panggil (tanpa HTTP): Micrometer `MicrometerCalculator::hitungSesi()` dan `MicrometerProfile::hitungPerGrup()` (fungsi yang sama dengan preview, simpan, dan `HitungUlangSesi`); pH `GumCalculator::hitungTitik()` dengan masukan yang disusun seperti `CalibrationController` (suhu larutan = rata-rata lima suhu sesudah adjustment). Konstanta pH dibaca dari sumber produksi (`kalibrasi-ph-meter.json`, `PhMeterCapabilitySeeder`), bukan diketik ulang.
- **Jebakan `NOW()`**: komponen drift Excel (`PERHITUNGAN U95%!K10`) memakai `DATABASE!X11 =NOW()`, jadi nilai-cache U95 tumbuh tiap berkas dibuka. Acuan sah: (a) hitung ulang rumus sel dengan TANGGAL TETAP 2026-06-30 (Python), (b) silang-cek ke cache pada X11 tersimpan (batas turunan satu detik umur). Cache tidak dipakai sebagai acuan U95 umum.
- Uji mutasi (tidak di-commit): `koefisien_muai` dibagi 2 bukan akar 3 -> 13 tes merah; drift `/366` bukan `/365` -> 14 merah; pH `u_temperature / 3` bukan `/2` -> 4 merah; berkas dipulihkan dari salinan (`git diff -- app` kosong).

### Toleransi dan asalnya (bukan dipilih agar lulus)

| Besaran | Toleransi | Asal |
|---|---|---|
| Tahap aritmatika (u, ci, vi, uc, veff, nilai titik) | relatif 1e-11, lantai mutlak 1e-12 | rekonstruksi mandiri T1 mereproduksi cache pH <= 1,2e-12 (`MATRIKS-ALAT-PERTAMA.md` bagian I) dan skrip pembangkit mereproduksi cache Micrometer <= 1,5e-13; 1e-11 = 10x batas teramati, 4 orde di atas epsilon double; 1e-12 mutlak = ~70 ulp untuk pengurangan dua nilai ~50-100 mm |
| `k` (TINV) | mutlak 1e-9 | `StudentTDistribution::EPS = 3e-12` pada CDF; kerapatan t ~0,058 di t~1,97 -> galat kuantil <= ~5e-11; margin 20x |
| U = k * uc | relatif 1e-9 | mewarisi galat `k` |
| Silang-cek cache pada X11 tersimpan | relatif 1/(umur dalam detik) ~ 1,7e-8 | aplikasi menghitung umur dari `getTimestamp()` (detik penuh), X11 punya pecahan detik: paling besar 1 detik |
| pH: komponen/tahap yang bergantung `u_temperature` | ui 2,5e-9 mutlak; uc/U 1e-9 relatif; veff 4e-9 relatif | kolom `calibration_capabilities.u_temperature` `decimal(20,8)`: MySQL membulatkan 0,36124783736 ke 0,36124784 (galat <= 5e-9); efek terburuk dihitung di docblock `ParitasPhMeterTest`; disimulasikan pada SQLite dengan nilai dibulatkan: tetap hijau |
| Angka cetak | teks SAMA PERSIS | pelanggan hanya memegang yang tercetak |

## 3. Micrometer, rincian 50-75 mm (sesi master, X11 tersimpan 19 Des 2025; acuan U95 sah = hitung ulang pada 2026-06-30)

| Tahap | Excel (sel) | Excel | Aplikasi | Selisih | Status |
|---|---|---|---|---|---|
| Pra-evaluasi: STDEV (mm) | PERHITUNGAN!N25 | 0.000516397779497 | 0.000516397779497 | 0.0e+00 | SAMA |
| Ustdmax balok pra-evaluasi (um) | PERHITUNGAN!O25 | 0.26 | 0.26 | 0.0e+00 | SAMA |
| Total nominal titik (mm), 11 titik | PERHITUNGAN!H31:H61 | (11 nilai, lihat fixture) | (11 nilai) | maks 0.0e+00 | SAMA |
| Standar terkoreksi (mm) | PERHITUNGAN!AB31:AB61 | (11 nilai, lihat fixture) | (11 nilai) | maks 0.0e+00 | SAMA |
| Rata-rata pembacaan (mm) | PERHITUNGAN!N31:N61 | (11 nilai, lihat fixture) | (11 nilai) | maks 0.0e+00 | SAMA |
| Koreksi (mm) | PERHITUNGAN!AD31:AD61 | (11 nilai, lihat fixture) | (11 nilai) | maks 0.0e+00 | SAMA |
| STDEV titik (mm; tampilan) | PERHITUNGAN!AC31:AC61 | (11 nilai, lihat fixture) | (11 nilai) | maks 0.0e+00 | SAMA |
| ui repeatability (um) | U95%!T5 | 0.163299316186 | 0.163299316186 | 0.0e+00 | SAMA |
| ui resolusi (um) | U95%!T6 | 0.288675134595 | 0.288675134595 | 0.0e+00 | SAMA |
| ui standard balok ukur (um) | U95%!T7 | 0.13 | 0.13 | 0.0e+00 | SAMA |
| ui perubahan suhu (um) | U95%!T8 | 0.317542648054 | 0.317542648054 | 0.0e+00 | SAMA |
| ui koefisien muai (um) | U95%!T9 | 5.7735026919e-06 | 5.7735026919e-06 | 0.0e+00 | SAMA |
| ui drift standar (um) (rumus sel, tanggal tetap) | U95%!T10 | 0.0544291034598 | 0.0544291034598 | 0.0e+00 | SAMA |
| ui lapisan wringing (um) | U95%!T11 | 0.0645497224368 | 0.0645497224368 | 6.1e-15 | SAMA |
| ui kesalahan geometri (um) | U95%!T12 | 0.288675134595 | 0.288675134595 | 0.0e+00 | SAMA |
| ui selisih suhu mikrometer-balok (um) | U95%!T13 | 0 | 0 | 0.0e+00 | SAMA |
| ci / vi sembilan komponen | U95%!V5:V13, Q5:Q13 | sama | sama | 0 | SAMA |
| uc (um), 2026-06-30 | U95%!X15 (rumus) | 0.466221664813 | 0.466221664813 | 8.9e-16 | SAMA |
| v_eff | U95%!AC15 (rumus) | 314.944179818 | 314.944179818 | 2.3e-12 | SAMA |
| k | U95%!X16 (TINV) | 1.96754769809 | 1.96754769808 | 1.2e-12 | SAMA |
| U95 sebelum lantai (um) | U95%!X17 | 0.9173133634 | 0.917313363399 | 5.4e-13 | SAMA |
| CMC pita C (um) | DATABASE!T7 / U95%!X18 | 0.91 | 0.91 | 0 | SAMA |
| U95 sertifikat (mm) = max(U95; CMC)/1000 | U95%!X19 | 0.0009173133634 | 0.000917313363399 | 5.4e-16 | SAMA |
| Silang-cek cache X11 tersimpan: U95 (um) | U95%!X17 | 0.914926948616 | 0.914926948591 | rel 2.7e-11 (batas 1,7e-8) | SAMA |
| Angka cetak: 11 baris Standar/UUT/Koreksi, U95, k | SERTIFIKAT!D18:L28, J29, P30 | teks | teks | 0 | SAMA (U95 '0.001' mm, k '2') |
| Titik 3: nominal cetak / tumpukan / total | INPUT DATA!D43:E45, PERHITUNGAN!H37 | 51,0 / 1,5+6+1,6+40+1,9 / 51,00025 | sama | 0 | SAMA; **MENUNGGU P-1** |

## 4. Micrometer, selisih maksimum per tahap, keempat ukuran

Selisih = |Excel - aplikasi| terbesar. Acuan sama dengan bagian 3 (tahap tidak bergantung tanggal: cache; drift dan turunannya: hitung ulang rumus sel pada 2026-06-30).

| Tahap (sel Excel) | 0-25 | 25-50 | 50-75 | 75-100 |
|---|---|---|---|---|
| Total nominal (H) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| Standar terkoreksi (AB) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| Rata-rata (N) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| Koreksi (AD) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| STDEV (AC) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| ui repeatability (U95%!T5) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| ui resolusi (U95%!T6) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| ui standard balok ukur (U95%!T7) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| ui perubahan suhu (U95%!T8) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| ui koefisien muai (U95%!T9) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| ui drift standar (U95%!T10) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| ui lapisan wringing (U95%!T11) | 6.1e-15 | 6.1e-15 | 6.1e-15 | 6.1e-15 |
| ui kesalahan geometri (U95%!T12) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| ui selisih suhu mikrometer-balok (U95%!T13) | 0.0e+00 | 0.0e+00 | 0.0e+00 | 0.0e+00 |
| ci sembilan komponen (U95%!V5:V13) | 0 (kecuali 3 komponen termal: P-3) | 0 | 0 | 0 |
| vi sembilan komponen (U95%!Q5:Q13) | 0 | 0 | 0 | 0 |
| uc (X15) | 1.0e-15 | 8.9e-16 | 8.9e-16 | 7.8e-16 |
| v_eff (AC15) | 4.4e-12 | 3.9e-12 | 2.3e-12 | 2.1e-12 |
| k (X16) | 1.6e-12 | 1.6e-12 | 1.2e-12 | 1.0e-12 |
| U95 sebelum lantai (X17), um | 6.3e-13 | 7.0e-13 | 5.4e-13 | 4.8e-13 |
| Acuan uc/U95 0-25 | rumus sel dengan F61 := 25,00027 (bukan F61 asli 6,00016) | - | - | - |
| Lantai CMC / penerbitan | Excel: "cek range" tanpa lantai; aplikasi: DITAHAN (P-2) | SAMA | SAMA | SAMA |

Angka aplikasi (2026-06-30), uc um / v_eff / k / U95 um: 0-25: 0.405481939 / 489.8562 / 1.96482708 / 0.796701894; 25-50: 0.445580886 / 481.3966 / 1.96490816 / 0.875525521; 50-75: 0.466221665 / 314.9442 / 1.9675477 / 0.917313363; 75-100: 0.468515174 / 307.343 / 1.96772129 / 0.921907281.

Silang-cek cache pada X11 tersimpan, selisih relatif U95: 0-25: 5.8e-08; 25-50: 8.7e-11; 50-75: 2.7e-11; 75-100: 2.3e-11. Di 0-25 mm selisih 5,8e-8 adalah efek P-3 (bukan galat acak).

## 5. pH Meter

### Vektor 1: `Master Olah Data_pH for trial` (Yokogawa CA 150; resolusi 0,01)

| Tahap | Excel (sel) | pH 4 Excel / app | pH 7 Excel / app | pH 10 Excel / app | Status |
|---|---|---|---|---|---|
| Nilai standar pada suhu larutan | PERHITUNGAN!G35/I35/K35 | 4.009244572 / 4.009244572 | 6.9889072 / 6.9889072 | 9.9788769 / 9.9788769 | SAMA |
| Rata-rata UUT sesudah adjustment | PERHITUNGAN!G42/I42/K42 | 4 / 4 | 7.004 / 7.004 | 10.11 / 10.11 | SAMA |
| STDEV sampel | PERHITUNGAN!G44/I44/K44 | 0 / 0 | 0.00547722557505 / 0.00547722557505 | 0 / 0 | SAMA |
| Koreksi cetak (Standar - UUT) | SERTIFIKAT!P21:P23 | 0.009244572 / 0.009244572 | -0.0150928 / -0.0150928 | -0.1311231 / -0.1311231 | SAMA |
| uc | U95%!AB17/32/47 | 0.0119031932703 / 0.0119031932703 | 0.0107116199684 / 0.0107116199684 | 0.0153886109568 / 0.0153886109568 | SAMA |
| v_eff | U95%!AB18/33/48 | 277.960232 / 277.960232 | 223.132118 / 223.132118 | 221.494585 / 221.494585 | SAMA |
| k | U95%!AB19/34/49 | 1.968565046 / 1.968565046 | 1.970658961 / 1.970658961 | 1.97075627 / 1.97075627 | SAMA |
| U sebelum lantai (uc*k) | U95%!AB20/35/50 | 0.0234322102126 / 0.0234322102126 | 0.0211089498757 / 0.0211089498757 | 0.0303272015372 / 0.0303272015372 | SAMA |
| U cetak = max(U; CMC) | U95%!AB22/37/52 | 0.0234322102126 / 0.0234322102126 | 0.0211089498757 / 0.0211089498757 | 0.031 / 0.031 | SAMA |

Lima komponen budget: semua SAMA (ui, ci, vi, uici; U95%!T/W/R/Y baris 11-15, 26-30, 41-45).
Angka cetak (Standard | UUT | Correction | U95, teks sama persis): pH 4: 4.01 | 4.00 | 0.01 | 0.02 / pH 7: 6.99 | 7.00 | -0.02 | 0.02 / pH 10: 9.98 | 10.11 | -0.13 | 0.03; k: 2 (SERTIFIKAT!U24). **SAMA**.

### Vektor 2: `pH meter_IMTE-WQ-129` (Constant/SH 10; resolusi 0,001)

| Tahap | Excel (sel) | pH 4 Excel / app | pH 7 Excel / app | pH 10 Excel / app | Status |
|---|---|---|---|---|---|
| Nilai standar pada suhu larutan | PERHITUNGAN!G35/I35/K35 | 4.0057607 / 4.0057607 | 6.97642 / 6.97642 | 9.9451681 / 9.9451681 | SAMA |
| Rata-rata UUT sesudah adjustment | PERHITUNGAN!G42/I42/K42 | 4.0096 / 4.0096 | 6.9996 / 6.9996 | 10.0006 / 10.0006 | SAMA |
| STDEV sampel | PERHITUNGAN!G44/I44/K44 | 0.000547722557505 / 0.000547722557505 | 0.000547722557505 / 0.000547722557505 | 0.00167332005307 / 0.00167332005307 | SAMA |
| Koreksi cetak (Standar - UUT) | SERTIFIKAT!P21:P23 | -0.0038393 / -0.0038393 | -0.02318 / -0.02318 | -0.0554319 / -0.0554319 | SAMA |
| uc | U95%!AB17/32/47 | 0.0115536169285 / 0.0115540473441 | 0.0100170331629 / 0.0100274025723 | 0.0150788146882 / 0.0151366887786 | **SELISIH P-6** |
| v_eff | U95%!AB18/33/48 | 246.715022 / 246.751784 | 201.361737 / 202.194289 | 204.16236 / 207.278672 | **SELISIH P-6** |
| k | U95%!AB19/34/49 | 1.969654176 / 1.969654176 | 1.971836507 / 1.971777385 | 1.971660889 / 1.971490392 | **SELISIH P-6** |
| U sebelum lantai (uc*k) | U95%!AB20/35/50 | 0.0227566298333 / 0.022757477603 | 0.0197519516802 / 0.019771805619 | 0.0297303091807 / 0.0298418364916 | **SELISIH P-6** |
| U cetak = max(U; CMC) | U95%!AB22/37/52 | 0.023 / 0.023 | 0.021 / 0.021 | 0.031 / 0.031 | SAMA |

Lima komponen budget: ui, ci, vi SAMA untuk sertifikat buffer, daya baca, perbedaan suhu, pengulangan; `temperature` BEDA (ui Excel 0,125897 vs aplikasi 0,180624; U95%!T13/T28/T43) = P-6.
Angka cetak (Standard | UUT | Correction | U95, teks sama persis): pH 4: 4.006 | 4.010 | -0.004 | 0.023 / pH 7: 6.976 | 7.000 | -0.023 | 0.021 / pH 10: 9.945 | 10.001 | -0.055 | 0.031; k: 2 (SERTIFIKAT!U24). **SAMA**.

Konstanta aplikasi = workbook (dibuktikan tes): koefisien polinomial buffer a/b/c (tertulis di rumus PERHITUNGAN!G35/I35/K35), U95 buffer (DATABASE!V13:V15), CMC (DATABASE!S5:S7), ci suhu, U/ci perbedaan suhu. SAMA di kedua workbook; satu-satunya konstanta yang berbeda antar workbook adalah UTemperature.

## 6. Konflik bernomor untuk Manajer Teknis (tidak diperbaiki, tidak dipilih)

| # | Butir | Fakta terukur | Dampak pada angka cetak | Status |
|---|---|---|---|---|
| P-1 (=K-1) | Micrometer 50-75 mm, titik 3: 51,0 atau 55,6 | kertas 0522.C = workbook master = aplikasi = 51,0 (total terkoreksi 51,00025 mm); cetakan Excel job 0455-CAL-1026 = 55,6 (tumpukan 50,0+1,2+2,5+1,9, total terkoreksi 55,60025). Tabel keping aplikasi = tabel Standar_GB Excel, jadi Excel dan aplikasi menerima tumpukan 55,6 sebagai nilai; tetapi aplikasi mematok nominal titik ke varian kertas dan tidak bisa merepresentasikan 55,6 tanpa mengubah tabel varian | kolom Standard Reading titik 3: 51,000 (aplikasi) lawan 55,600 (cetakan job) | **SAMA** (keputusan default B, 25 Nov; belum dikonfirmasi TM); tes `test_k1_..._keputusan_default_51_0` (grup `keputusan-default`) mengunci |
| P-2 | Micrometer 0-25 mm: sesi tersimpan cacat | INPUT DATA!H14 = 2 (inch) dengan angka mm; F15 = 25 menjadi PERHITUNGAN!G8 = 635 (di luar keempat pita) sehingga X18 = teks 'cek range' dan Excel menerbitkan U95 0,7354 um di bawah CMC 0,83 um; F16 = 1e-5; pra-evaluasi 25 x10 identik (STDEV 0). Aplikasi menahan: tanpa pita CMC dan pra-evaluasi seragam | Excel menerbitkan, aplikasi tidak | **SELISIH-DITERIMA-DENGAN-ALASAN** (penyimpangan no. 1 `MicrometerCalculator`; K-7 default B, K-13 default A) + **TERBLOKIR-BUKTI** (workbook sesi 0-25 mm yang benar-satuan) |
| P-3 | Panjang untuk ci termal | Excel F61 = keping pertama tumpukan titik terakhir (0-25: 6,00016 mm); aplikasi = total nominal terbesar (25,00027 mm). Sama di 25-50, 50-75, 75-100 (keping tunggal). Efek 0-25: uc +5,8e-8 relatif; ci aplikasi lebih BESAR | tidak mengubah angka cetak | **SELISIH-DITERIMA-DENGAN-ALASAN** (penyimpangan no. 3), terbukti satu-satunya penyebab (rumus sel dengan F61 := 25,00027 = aplikasi); tidak ada butir default, konfirmasi ahli tetap diminta |
| P-4 | Drift standar: `NOW()` (Excel) lawan tanggal kalibrasi sesi (aplikasi) | U95 Excel tumbuh tiap berkas dibuka (25-50 mm: 0,8738 um pada 19 Des 2025, 0,8755 um pada 30 Jun 2026). Aplikasi mengulang hasil yang sama dari tanggal sesi. Sesi dengan tanggal kalibrasi SEBELUM 24 Jan 2024 (sertifikat balok ukur yang tersimpan) mendapat drift 0 di aplikasi (Excel memberi drift positif karena NOW()); kasus umur negatif TIDAK diuji di sini | kecil; lantai CMC berlaku | **SELISIH-DITERIMA-DENGAN-ALASAN** (penyimpangan no. 2); umur negatif **TERBLOKIR-BUKTI**; tidak ada butir default |
| P-5 | Tanda minus pada koreksi yang membulat ke nol (`-0.000`) | fixture memuat EMULASI tampilan Excel, bukan render asli; emulasi memberi `-0.000` (25-50: 2 baris; 75-100: 6 baris), aplikasi sengaja `0.000` (`tandaNolDicetak()=false`, klaim repo: PDF master tanpa minus, G0-b P4). Bukti saling bertentangan, tidak ada render Excel asli di fixture | teks cetak koreksi pada baris itu | **TERBLOKIR-BUKTI**: butuh render Excel asli (cetak PDF dari Excel untuk sesi yang koreksinya negatif kecil); tes membandingkan tanpa tanda dan mengunci jumlah barisnya |
| P-6 (=J1, `ph_meter#19`) | pH: UTemperature satu konstanta | aplikasi memakai 0,3612478 (Yokogawa, U95 0,72) untuk semua sesi; workbook `IMTE-WQ-129` memakai 0,2517936 (Constant, U95 0,5). Efek (aplikasi - Excel): uc +4,3e-7 / +1,04e-5 / +5,8e-5; U sebelum lantai +8,5e-7 / +2,0e-5 / +1,1e-4 pH untuk pH 4/7/10. Terbukti satu-satunya penyebab (rumus sel dengan UTemperature aplikasi = aplikasi) dan arahnya aman (aplikasi >= Excel) | tidak ada: U cetak = CMC 0,023 / 0,021 / 0,031 di kedua sisi | **SELISIH tercatat**, menunggu jawaban TM (sumber UTemperature per termometer sesi) |
| P-7 | pH: konstanta tersimpan `decimal(20,8)` | `u_temperature` 0,36124783736 tersimpan MySQL sebagai 0,36124784 (galat relatif 7e-9 pada ui temperature); tidak ada efek tercetak | tidak ada | informasi; sudah ditampung toleransi bertulis |

Catatan alur (bukan konflik tahap hitung, tetapi menyentuh jalur kritis): di tiga dari empat cetakan Excel Micrometer, kesepuluh pembacaan pra-evaluasi identik (K-7). Aplikasi menahan sesi seperti itu (`boleh_terbit = false`; keterulangan nol disamarkan lantai CMC). Workbook 0-25 mm adalah contohnya. Perlakuan (lantai keterulangan berbasis resolusi atau menolak) adalah keputusan TM; default K-7 B (25 Nov): cetakan tanpa sebaran dianggap placeholder, bukan data uji, dan aplikasi tetap menahan.

## 7. Yang BELUM diuji / belum terbukti

- Excel tidak dijalankan ulang: acuan = nilai-cache workbook (dibaca dengan sandi) dan hitung ulang Python rumus sel `PERHITUNGAN U95%` (K5..X19) dengan TINV = t(0,975; floor(veff)); rantai sel `PERHITUNGAN` (titik) dibandingkan ke cache langsung.
- Micrometer: sesi 0-25 mm yang benar-satuan (tidak ada di workbook); satuan inch/um pada sesi valid di ukuran lain; tumpukan keping selain yang ada di master; umur drift negatif (tanggal sesi < 24 Jan 2024); pra-evaluasi seragam (hanya dicatat di P-2/K-7, sudah dijaga `MicrometerMasterTest`); kondisi lingkungan (suhu/RH + U95 Thermohygro) dan K-11 (TH-1 lawan TH-3); status standar VALID/WARNING; tanggal terbit (+4 hari); kerataan/kesejajaran (K-6; K-10 hanya diuji pada perilaku aplikasi oleh `test_k10_...`, bukan pada render master); header/identitas.
- Jalur `HitungUlangSesi` dari baris `raw_measurements` (`MicrometerMentah::dari`) dan jalur HTTP simpan/preview tidak dipanggil di sini; yang dipanggil `hitungPerGrup()` dengan masukan berbentuk konteks controller. Tes sesi (`MicrometerSesiTest`) sudah menjaga jalur HTTP.
- pH: suhu ruang/RH dan U95 Thermohygro (J7, J8), tabel SEBELUM adjustment (tidak masuk sertifikat), jumlah pengulangan selain 5 (Excel mengunci P=5, vi=4; aplikasi bebas, J3), tanggal terbit +4 lawan +3 (J5), status standar kedaluwarsa per 24 Nov 2026 (J9), PASS/FAIL (Excel tidak punya, J6).
- Angka cetak diuji pada pemformat (`TataLetakSertifikat::formatAngka`, `Angka`), bukan render HTML/PDF penuh dan bukan render Excel asli (teks Excel = emulasi pembulatan 15 angka penting lalu desimal format, setengah menjauhi nol; lihat P-5). HTML penuh pH vektor 1 dijaga `SieveAutoclaveTimerPhSesiContohCocokMasterTest`.
- Gate MySQL penuh wajib sebelum modul disebut selesai; di sini hanya SQLite terarah. Efek kolom `decimal(20,8)` pada pH disimulasikan, bukan dijalankan di MySQL.

## 8. Menghasilkan ulang fixture

```
py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-micrometer-ph.py "<folder Downloads>"   # butuh SIDIK_MASTER_SANDI di lingkungan
```

Skrip berhenti bila rekonstruksi rantai menyimpang > 1e-11 dari cache. Hasil terakhir: Micrometer 0-25/25-50/50-75/75-100 = 2,3e-14 / 2,2e-14 / 1,3e-13 / 1,4e-13; pH `for trial` 7,1e-13, `IMTE-WQ-129` 1,1e-12.
