# Paritas aplikasi lawan Excel: Spectrophotometer (batch alat ke-3)

Dibuat 26 Nov 2026 (cabang `feat/paritas-batch3`, dari `origin/main` ea6f4b07). Tidak ada kode aplikasi (`app/`) yang diubah. Dokumen ini hanya melaporkan; selisih = KONFLIK untuk Manajer Teknis (TM), tidak diperbaiki dan tidak dipilih agar tes lulus. Pola dan istilah sama dengan `docs/paritas-micrometer-ph.md` dan `docs/paritas-analitik-batch2.md`. Nomor konflik batch ini P-30 sampai P-39.

Label: **SAMA** = aplikasi = Excel dalam toleransi bertulis, dibuktikan tes yang dijalankan. **SELISIH** = beda yang terukur (konflik bernomor, tes menegaskan perilaku aplikasi SEKARANG dengan penanda `SELISIH P-nn`). **MENUNGGU** = butuh keputusan orang atau bukti yang belum ada, atau belum diuji di sini (alasannya ditulis). Status "TERBUKTI" per alat BUKAN diputuskan di sini; ini bukti yang tersedia untuk koordinator dan TM.

Hanya perilaku BAWAAN yang diuji. Keputusan bawaan yang dirujuk ada di dokumen keputusan bawaan lab (nomor #). Konfigurasi `kalibrasi.r2_spektro` dipatok ke `rsq_standar_uut` (nilai bawaan produksi) dan parameter keputusan metode dibiarkan di bawaannya.

## 1. Ringkasan

| Alat | Hasil | SAMA | SELISIH | MENUNGGU |
|---|---|---|---|---|
| Spectrophotometer (3 grup: Holmium nm 10 titik, Didymium nm 9 titik, Accuracy %T 5 titik) | rantai penuh SAMA di ketiga grup (statistik titik, 15 komponen budget, uc, v_eff, k, U, lantai CMC, angka cetak); 3 SELISIH (P-30, P-31, P-32); 8 butir MENUNGGU (konflik P-33..P-38 dan dua batas bukti S-29, S-30) | 19 | 3 | 8 |
| Jumlah (30 butir) | | 19 | 3 | 8 |

Dengan data master apa adanya, tidak ada angka CETAK yang berbeda dari Excel kecuali tanda minus pada dua nol (P-33, itupun hasil simulasi, lihat bagian 4). P-30 baru mengubah angka bila STDEV titik pertama Holmium adalah yang terbesar (data master tidak begitu). P-31 tidak menggeser angka cetak (format `0` mencetak `1` untuk 0,9359 dan untuk hasil hitung). P-32 menggeser TANGGAL TERBIT bila tanggal di `INPUT DATA!D80` dipakai (tanggal kalibrasi +4 lawan +1). Daftar dan efek ke angka cetak ada di bagian 5.

## 2. Cara membaca, menjalankan, dan menghasilkan ulang

- Tes: `tests/Feature/Paritas/ParitasSpectrophotometerTest.php` (16 kasus, 721 assertion). Jalankan: `php artisan test --filter=ParitasSpectrophotometerTest` (SQLite in-memory). Gate MySQL penuh BELUM dijalankan di sini; CI yang menjalankan. Perangkat bersama: trait `tests/Concerns/MenegakkanParitas.php` (tidak diubah).
- Fixture: `tests/Fixtures/paritas/spectrophotometer.json`. Tiap nilai Excel membawa alamat sel (`SHEET!SEL`); pesan galat tes menyebut sel itu. Dibangun `docs/skrip/gen-fixture-paritas-spectrophotometer.py`. Sandi workbook hanya dari env `SIDIK_MASTER_SANDI`, di memori; fixture tanpa nama atau alamat pelanggan dan tanpa nomor sertifikat (dipindai sebelum commit; sandi tidak muncul di berkas mana pun).
- Workbook yang dipakai: `Master Olah Data_Spectrofotometer.xlsm` (folder `EXEL CALIBRATION SIDIK`), sha256 12 karakter pertama `1c73f0b89a4e`. Angka fixture = nilai-cache sel. Generator merekonstruksi rantai `PERHITUNGAN U95%` dari sel komponen dan membandingkannya dengan cache pada 135 titik banding; selisih terbesar = 5,5e-4 kali batas relatif 1e-11 (sekitar 5,5e-15); di atas batas generator berhenti.
- Titik panggil (tanpa HTTP): `SpectrophotometerCalculator::hitungGrup()` per grup (statistik titik, budget, agregasi GUM, lantai CMC), `celahSpectralBandwidth()`, serta `SpectrophotometerProfile` (nominal, resolusi, format kolom sertifikat, R2, presisi cetak). Kemampuan CMC dibaca dari SEEDER produksi (`SpectrophotometerCapabilitySeeder`), jadi tes juga membuktikan CMC seeder = sel workbook.
- Angka cetak diuji pada pemformat produksi (`Angka`, `TataLetakSertifikat::formatAngka`) dengan desimal yang ditetapkan profil, bukan render HTML/PDF penuh dan bukan render Excel asli (teks Excel = emulasi pembulatan 15 angka penting lalu desimal format, setengah menjauhi nol).
- Uji mutasi: TIDAK dijalankan untuk batch ini. Tes hanya terbukti hijau terhadap kode sekarang; kemampuannya menangkap kesalahan belum diukur.
- CI: `php .github/ci/shard.php verify --profil=mysql --of=6` OK (605 berkas, tanpa duplikat, tanpa yang hilang) dengan berkas tes baru.

### Toleransi dan asalnya (bukan dipilih agar lulus)

Sama dengan `docs/paritas-micrometer-ph.md` bagian 2, lewat `MenegakkanParitas`:

| Besaran | Toleransi | Asal |
|---|---|---|
| rata-rata, koreksi, STDEV, ui, ci, vi, uici, uc, v_eff, CMC | relatif 1e-11, lantai mutlak 1e-12 (`assertSamaExcel`) | rekonstruksi rantai dari sel komponen mereproduksi cache sampai sekitar 5,5e-15 relatif |
| k = TINV(0,05; floor(v_eff)) | mutlak 1e-9 (`assertKSamaExcel`) | TINV Excel lawan implementasi aplikasi beda di orde 1e-12 |
| U (sebelum lantai) dan U95 cetak | relatif 1e-9 (`assertUSamaExcel`) | U = k x uc, mewarisi selisih k |
| angka cetak (teks) | SAMA PERSIS | teks hasil pemformat produksi lawan teks emulasi Excel |

## 3. Spectrophotometer, rincian per butir

Sesi contoh workbook: Holmium 10 titik (279,6 sampai 637,9 nm, 3 pembacaan per titik), Didymium 9 titik (475,2 sampai 806,1 nm, 3 pembacaan), Accuracy %T 5 titik (0; 9,9; 20,0; 30,1; 100,0 %T, 6 pembacaan per titik, panjang gelombang 560 nm di `INPUT DATA!F66`). U standar 0,1 / 0,2 / 0,5 (`STANDAR_KALIBRATOR!M5, M18, M29`), CMC 0,4 / 0,4 / 0,5 (`DATABASE!S5:S7`), resolusi 0,01 / 0,01 / 0,001 (`INPUT DATA!G16, E16`).

Nilai penting dari fixture (nilai-cache Excel): Holmium uc 0,1359196779955751, v_eff 3,4642618700507275, k 3,182446305283709, U 0,43255707705236945, tercetak `0,43` dan k `3`. Didymium uc 0,15828510160732645, v_eff 7,367683516139811, k 2,364624251592785, U 0,3742847899265122, lantai CMC menang, tercetak `0,40` dan k `2`. Accuracy %T uc 0,028917411133548367, v_eff 50,340915292002045, k 2,008559112100761, U 0,058082329630652574, lantai CMC menang, tercetak `0,50` dan k `2`. Celah spectral bandwidth `PERHITUNGAN U95%!Q30` = 0,07555555555555789.

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| S-1 | Nominal tiap titik (10 + 9 + 5 = 24) lawan `SpectrophotometerProfile::TITIK` | INPUT DATA!G35:G44, G49:G57, G66:G74 | SAMA | `test_nominal_resolusi_dan_grup_titik_profil_sama_dengan_workbook` |
| S-2 | Grup titik dan resolusi per titik (0,01 nm; 0,001 %T) | INPUT DATA!G16, E16 | SAMA | idem; `grupTitik()`, `resolusiTitik()` |
| S-3 | Rata-rata UUT per titik (24) | PERHITUNGAN!K26:K35, K40:K48, K57:K65 | SAMA | `test_statistik_tiap_titik_sama_dengan_workbook` |
| S-4 | Koreksi per titik (24) | PERHITUNGAN!L26:L35, L40:L48, L57:L65 | SAMA | idem |
| S-5 | STDEV sampel per titik (24) dan jumlah pengulangan | PERHITUNGAN!M26:M35, M40:M48, M57:M65 | SAMA | idem |
| S-6 | MAX STDEV per grup (data master) | PERHITUNGAN!M36, M49, M67 | SAMA | `test_standar_deviasi_maks_tiap_grup_sama_dengan_workbook`; Holmium M36 `=MAX(M27:M35)`, lihat P-30 |
| S-7 | 15 komponen budget: ui, ci, vi, uici, ikut atau tidak di jumlah | PERHITUNGAN U95%!G10:J14, G26:J30, G46:J50 | SAMA | `test_komponen_budget_tiap_grup_sama_dengan_workbook`; aplikasi dibandingkan ke `ui` (bukan `u_mentah` dibagi pembagi) |
| S-8 | Pembagi Type A: `=E/SQRT(F)` dengan F = SQRT(3), jadi pembagi efektif 3^0,25 (keanehan master, DITIRU) | PERHITUNGAN U95%!H10, H26 | SAMA | `test_pembagi_type_a_master_adalah_tiga_pangkat_seperempat`; angka sama, keanehan dicatat |
| S-9 | uc per grup | PERHITUNGAN U95%!J17, J33, J53 | SAMA | `test_agregasi_gum_dan_cmc_tiap_grup_sama_dengan_workbook` |
| S-10 | v_eff per grup | PERHITUNGAN U95%!J18, J34, J54 | SAMA | idem |
| S-11 | k per grup | PERHITUNGAN U95%!J19, J35, J55 | SAMA | idem |
| S-12 | U sebelum lantai per grup | PERHITUNGAN U95%!J20, J36, J56 | SAMA | idem |
| S-13 | CMC dan U95 cetak = MAX(U; CMC), sumber (lantai CMC menang di Didymium dan %T) | PERHITUNGAN U95%!J21:J22, J37:J38, J57:J58 | SAMA | idem; kode audit `perbandingan_cmc` |
| S-14 | Blok %T: SUM hanya baris 47-49; ketidakpastian standar (baris 46) dan spectral bandwidth (baris 50) tidak ikut dijumlah (keanehan master, DITIRU, dicatat `komponen_dikecualikan_master`) | PERHITUNGAN U95%!J47:J49, J52 | SAMA | `test_blok_transmitan_meniru_jumlah_master_dan_mencatat_komponen_dikecualikan` |
| S-15 | Celah spectral bandwidth | PERHITUNGAN U95%!Q30 (`=ABS(AVERAGE(P10:P27))`) | SAMA | idem agregasi; `celahSpectralBandwidth()` |
| S-16 | Angka cetak titik: nominal, UUT, koreksi (24 titik, format kolom, teks persis) | SERTIFIKAT (kolom nominal, UUT, koreksi baris titik) | SAMA | `test_angka_cetak_titik_u95_dan_k_sama_persis_dengan_teks_excel`; pengecualian dua nol bertanda minus, lihat P-33 |
| S-17 | Angka cetak U95 dan k per grup (`0,43` / `3`; `0,40` / `2`; `0,50` / `2`) | SERTIFIKAT!M29, Q30, M43, Q44, M53, Q54 | SAMA | idem |
| S-18 | Presisi cetak profil: `desimalU95()` 2, `desimalSertifikat()` 1, `desimalFaktorCakupan()` 0 = format sel | SERTIFIKAT (format `0.00`, `0.0`, `0`) | SAMA | `test_presisi_cetak_profil_sama_dengan_format_workbook` |
| S-19 | CMC kemampuan terseed per grup (0,4 / 0,4 / 0,5) | DATABASE!S5:S7 | SAMA | `test_cmc_kemampuan_terseed_sama_dengan_cmc_workbook`; lihat P-36 untuk 0,38 di lampiran |
| S-20 | MAX STDEV Holmium: workbook membuang titik pertama dari MAX, aplikasi memakai semua titik (P-30) | PERHITUNGAN!M36 (`=MAX(M27:M35)`) lawan M49, M67 | SELISIH | `test_p30_...`; keputusan bawaan #15 |
| S-21 | R2 transmitan: konstanta ketikan 0,9359 lawan RSQ hasil hitung aplikasi (P-31) | SERTIFIKAT!R48 | SELISIH | `test_p31_...`; keputusan bawaan #24 |
| S-22 | Tanggal terbit: aplikasi +1 hari lawan `INPUT DATA` +4 hari (P-32) | SERTIFIKAT!B70 (`=R9+1`) lawan INPUT DATA!D80 (`=O16+4`) | SELISIH | `test_p32_...`; keputusan bawaan #4 |
| S-23 | Nol bertanda minus: Excel `-0.0`, aplikasi `0.0` pada dua koreksi %T (P-33) | SERTIFIKAT!O48, O52 | MENUNGGU | `test_p33_...`; "-0.0" disimulasikan generator, bukan teks tampilan tersimpan |
| S-24 | Titik, nilai standar, dan jumlah ulangan: master lawan kertas Rev.5 (P-34) | INPUT DATA!G35:G74, H35:J74 | MENUNGGU | `test_p34_...` mengunci angka master; keputusan bawaan #12/#16/#20/#21 |
| S-25 | Resolusi (P-35) | INPUT DATA!G16, E16 | MENUNGGU | konstanta workbook sama dengan aplikasi (S-2); kepastian nilainya menunggu keputusan #27 |
| S-26 | CMC Didymium 0,40 di master lawan 0,38 di lampiran LK-285-IDN butir 47 (P-36) | DATABASE!S6 | MENUNGGU | aplikasi = master (S-19); keputusan bawaan #32 |
| S-27 | Titik di luar rentang kemampuan CMC: Holmium 1, Didymium 0, %T 3 titik (P-37) | tidak ada di workbook | MENUNGGU | `test_p37_...`; fakta sisi aplikasi saja, workbook tidak punya rentang |
| S-28 | Tampilan MAX STDEV (P-38) | PERHITUNGAN!M36, M49, M67 | MENUNGGU | hanya dokumen, tidak diuji; keputusan bawaan #43 |
| S-29 | U standar 0,1 / 0,2 / 0,5 | STANDAR_KALIBRATOR!M5, M18, M29 | MENUNGGU | nilai diambil dari fixture dan diberikan ke kalkulator; belum diadu ke sumbernya di aplikasi (data standar di basis data) |
| S-30 | Header dan identitas sertifikat (nama, merk, tanggal, lokasi, IK, teknisi, penandatangan) dan daftar standar tercetak | SERTIFIKAT (area header dan daftar standar) | MENUNGGU | tidak diuji di sini |

Butir S-1..S-19 bernilai SAMA hanya untuk data master apa adanya. Tidak ada sesi lain di workbook, jadi perilaku pada pembacaan dengan sebaran berbeda baru teruji lewat S-20 (data Holmium dilebarkan di tes, perilaku aplikasi saja, bukan nilai Excel).

## 4. Keterbatasan bukti

- **R2 (P-31).** Tidak ada sel RSQ, CORREL, atau LINEST di workbook; `SERTIFIKAT!R48` adalah konstanta ketikan 0,9359. Nilai RSQ 0,9999219 yang dipakai tes adalah HITUNG ULANG Pearson di tes (tanpa sel Excel), diberi label itu di pesan tes.
- **Nol bertanda minus (P-33).** Workbook tidak menyimpan teks tampilan. Generator mensimulasikan tampilan sel (format sel + nilai-cache, pembulatan setengah menjauhi nol), sehingga `-0.0` pada `SERTIFIKAT!O48` dan `O52` adalah simulasi, bukan teks yang diamati di Excel. Karena itu MENUNGGU, bukan SELISIH.
- **Keanehan master yang ditiru** (angka SAMA): pembagi Type A 3^0,25 (S-8) dan jumlah blok %T yang melewati baris 46 dan 50 (S-14). Keduanya perlu pertanyaan ke lab bila TM ingin membetulkannya; tes tidak membetulkan apa pun.
- **Komentar kode.** Docblock `SpectrophotometerProfile::tandaNolDicetak()` menyebut `SERTIFIKAT!Q48 & Q52`, padahal sel koreksinya `O48` dan `O52`. Hanya komentar, tidak diubah di sini (`app/` tidak disentuh).
- Uji mutasi tidak dijalankan (bagian 2). Gate MySQL tidak dijalankan di sini.

## 5. Konflik dan efeknya ke angka cetak

| No. | Uraian | Status | Efek ke angka cetak | Keputusan yang dirujuk |
|---|---|---|---|---|
| P-30 | MAX STDEV Holmium: Excel M36 membuang titik 1, aplikasi memakai semua titik. Saklar `spectrophotometer.max_stdev_holmium = tanpa_titik_pertama` menyamakannya | SELISIH | Nol pada data master (titik 1 bukan STDEV terbesar). Bila STDEV titik 1 terbesar, uc dan U Holmium beda | #15 |
| P-31 | R2 %T: konstanta 0,9359 (R48) lawan RSQ dari data | SELISIH | Nol: format `0` mencetak `1` untuk keduanya | #24 |
| P-32 | Tanggal terbit: aplikasi +1 hari (cocok dengan `SERTIFIKAT!B70`), `INPUT DATA!D80` +4 hari | SELISIH | Tanggal terbit pada sertifikat berbeda 3 hari bila `D80` yang dipakai | #4 |
| P-33 | Nol bertanda minus: `-0.0` (simulasi) lawan `0.0` | MENUNGGU | Dua koreksi %T tercetak tanpa minus di aplikasi | (belum ada) |
| P-34 | Titik, nilai, dan jumlah ulangan: master lawan kertas Rev.5 (`JUMLAH_PENGULANGAN` 3, `PENGULANGAN_TRANSMITAN` 6, `KODE_DOKUMEN` `SIDIK-FM-CAL-0511_Rev.5`) | MENUNGGU | Bergantung formulir yang dipakai teknisi | #12, #16, #20, #21 |
| P-35 | Resolusi 0,01 / 0,001 | MENUNGGU | Nol bila nilai dipertahankan | #27 |
| P-36 | CMC Didymium 0,40 (master dan aplikasi) lawan 0,38 (lampiran butir 47) | MENUNGGU | U95 Didymium tercetak 0,40 karena lantai CMC; 0,38 akan mencetak 0,38 bila lantai itu yang menang | #32 |
| P-37 | Titik di luar rentang kemampuan CMC (rentang terseed Holmium 283-641 nm: titik 279,6 nm di luar; rentang %T 10,0-30,5: titik 0, 9,9, dan 100 di luar; Didymium nol) | MENUNGGU | Tidak ada di workbook; perilaku aplikasi di titik itu belum diputuskan | (belum ada) |
| P-38 | Tampilan MAX STDEV | MENUNGGU | Nol (hanya tampilan) | #43 |

P-39 tidak dipakai di batch ini.
