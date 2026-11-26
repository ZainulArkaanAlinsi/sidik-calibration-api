# Paritas aplikasi lawan Excel: DO Meter, Tachometer, Centrifuge (batch alat ke-4)

Dibuat 26 Nov 2026 (cabang `feat/paritas-batch4`, dari `origin/main` 6361f281). Tidak ada kode aplikasi (`app/`) yang diubah. Dokumen ini hanya melaporkan; selisih tidak diperbaiki dan tidak dipilih agar tes lulus. Pola, istilah, dan toleransi sama dengan `docs/paritas-micrometer-ph.md` dan `docs/paritas-analitik-batch2.md`.

Label: **SAMA** = aplikasi = Excel dalam toleransi bertulis, dibuktikan tes yang dijalankan. **SELISIH** = beda yang terukur (tes menegaskan perilaku sekarang dengan penanda `SELISIH`). **MENUNGGU** = butuh keputusan orang atau bukti yang belum ada, atau belum diuji di sini (alasannya ditulis). Status "TERBUKTI" per alat bukan diputuskan di sini.

Nomor temuan baru: `B4-n`. Selisih yang sudah punya keputusan lama dirujuk ke `docs/pertanyaan-lab-waktu-frekuensi.md` (§2 blok rusak, §3 drift K sebagian, §10 tanda koreksi Tachometer) dan tidak diberi nomor baru.

## 1. Ringkasan

| Alat | Hasil | SAMA | SELISIH | MENUNGGU |
|---|---|---|---|---|
| DO Meter (mg/L, %O2) | rantai penuh mg/L SAMA (konstanta, standar, 5 komponen, uc, v_eff, k, U, lantai CMC, angka cetak, tanggal terbit, kondisi lingkungan TH-2); 2 SELISIH (B4-1, P-25) | 7 | 2 | 2 |
| Tachometer inframerah (18 titik, 6 blok) | semua kolom titik SAMA; 5 dari 6 blok SAMA dengan tabel versi-master; blok 5 rusak di workbook | 6 | 6 | 2 |
| Centrifuge (15 titik, 5 blok) | semua kolom titik SAMA; 4 dari 5 blok SAMA dengan tabel versi-master; blok 1 pengulangan satu sel | 7 | 4 | 2 |
| Jumlah (38 butir) | | 20 | 12 | 6 |

Angka cetak sampel yang berbeda dari Excel: U95 di 4 dari 6 blok Tachometer dan di 5 dari 5 blok Centrifuge (drift §3, bagian 4), dan tidak ada angka cetak lain. Selisih terbesar yang mengubah nilai antara adalah Tachometer blok 5 (U 1,69 lawan 4,45 rpm sebelum lantai CMC), tetapi U cetaknya SAMA (5,0) karena lantai CMC.

## 2. Cara membaca, menjalankan, dan menghasilkan ulang

- Tes: `tests/Feature/Paritas/ParitasDoMeterTest.php` (10 kasus), `ParitasPutaranTest.php` (22 kasus; sebagian ber-`DataProvider` untuk dua alat). Jalankan satu berkas: `php vendor/phpunit/phpunit/phpunit --configuration=phpunit.xml tests/Feature/Paritas/ParitasPutaranTest.php`. Gate MySQL penuh BELUM dijalankan di sini; CI yang menjalankan.
- Fixture: `tests/Fixtures/paritas/do-meter.json`, `tachometer.json`, `centrifuge.json`. Tiap nilai Excel membawa alamat sel (`SHEET!SEL`); pesan galat tes menyebut sel itu. Dibangun `docs/skrip/gen-fixture-paritas-batch4.py` dari nilai-cache workbook. Sandi hanya dari `SIDIK_MASTER_SANDI`, di memori. Fixture tidak memuat nama atau alamat pelanggan, nomor sertifikat, nomor order, atau teknisi.
- Rekonstruksi rantai `PERHITUNGAN U95%` dari sel komponen mereproduksi cache dengan selisih relatif maksimum 1,09e-11 (Tachometer) dan 4,3e-12 (Centrifuge). Selisih itu `k`/U: `TINV` Excel mendekati kuantil t, beda ~1e-11 relatif dari kuantil eksak pada derajat bebas ratusan ribu. Karena itu `k` dan U memakai toleransi 1e-9 (`TOL_K`, `TOL_K_REL` di `MenegakkanParitas`), bukan 1e-11.
- Titik panggil tanpa HTTP: `PutaranCalculator::hitungBlok()` (yang dipanggil `ProfilPutaran::hitungPerGrup`), satu blok = tiga titik. Tabel standar kalibrator dibaca dari `database/data/tabel-standar-putaran.json`, tanpa database.
- Dua mode tabel standar, supaya dua hal yang sengaja berbeda dari master tidak bercampur dengan kesetaraan rumus: "versi master" (subkelas `TabelStandarPutaranVersiMasterParitas`, drift dari 5 baris berrumus kolom K seperti `Drift Std Kalibrator!K35`) harus SAMA di semua blok tak rusak; tabel produksi (drift lengkap) dikunci sebagai SELISIH.
- Lantai mutlak untuk koreksi dan nilai terkoreksi: 1,5e-14 x set point (sampai 25002 rpm, ulp 3,6e-12), karena selisih dua bilangan sebesar set point tidak bisa lebih teliti dari itu.
- Angka cetak diuji pada pemformat produksi `TataLetakSertifikat::formatAngka` dengan format kolom profil (`0.0`; Tachometer `0` sejak 10000 rpm; `k` `0`), bukan render PDF penuh. Teks Excel = nilai sel dengan format selnya.

## 3. Tachometer dan Centrifuge, rincian per butir

Workbook: `Master Olda Tachometer.xlsm` dan `Master Olda Centrifuge.xlsm` (sha256 12 hex pertama di fixture). Layout: `PERHITUNGAN` baris ukur per blok, kolom G/I/K; `PERHITUNGAN U95%` komponen di baris r0..r0+4, agregat di kolom AC; `SERTIFIKAT` kolom C/J/P/S.

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| T-1 | Tabel sertifikat kalibrator: nominal, koreksi, U95 pita | `SERTIFIKAT KALIBRATOR!C:F` | SAMA | `test_tabel_sertifikat_kalibrator_sama_dengan_workbook` (13 nominal, 5 pita U95) |
| T-2 | Pita CMC: 60-7000 rpm 1,5 dan 7000-30000 rpm 5 (Tachometer); 60-200 rpm 1,5 dan 200-9000 rpm 1,6 (Centrifuge) | `DATABASE!R5:S6` | SAMA | `test_pita_cmc_data_aplikasi_sama_dengan_database_workbook`; pemilihan pita lewat database dijaga `LantaiCmcPutaranTest` |
| T-3 | Rata-rata, nominal standar, koreksi standar, terkoreksi, koreksi, simpangan baku, SEMUA titik | `PERHITUNGAN!{G,I,K}{r+8..r+13}` | SAMA | `test_kolom_titik_cocok_excel_semua_titik`. Nominal standar diturunkan aplikasi (terdekat, seri ke atas), Excel mengetiknya; cocok di semua titik sampel |
| T-4 | Lima komponen, uc, v_eff, k, U, lantai CMC, di blok tak rusak, tabel versi-master | `PERHITUNGAN U95%` | SAMA | `test_budget_dan_agregat_blok_tidak_rusak_cocok_excel`; jejak audit kosong |
| T-5 | Angka cetak: set point, terkoreksi, koreksi, U95 per blok | `SERTIFIKAT!C,J,P,S` | SAMA | `test_angka_cetak_sama_dengan_sertifikat_excel`; kolom Standard/UUT bertukar posisi di kedua alat, dibandingkan per isi |
| T-6 | `k` tercetak 2 (k blok pertama) | `SERTIFIKAT!U37` (Tachometer), `U34` (Centrifuge) | SAMA | `test_k_cetak_sama_dengan_sertifikat_excel` |
| T-7 | Drift kalibrator: Excel 2,25 rpm, tabel produksi 2,75 rpm | `Drift Std Kalibrator!K35` | SELISIH §3 | `test_selisih_drift_tabel_produksi_lebih_besar_dari_master`. Kolom K berrumus hanya di baris 15, 18, 26, 28, 32; keputusan lab sudah melengkapinya. Efek: ui drift 1,299 (Excel) lawan lebih besar di aplikasi, empat komponen lain SAMA, jejak audit memuat nilai master yang sama dengan sel |
| T-8 | Efek T-7 ke angka CETAK | `SERTIFIKAT!S` | SELISIH §3 | `test_selisih_drift_u95_tercetak_lebih_besar_dari_excel_di_atas_lantai_cmc`: U95 Excel lawan aplikasi (tabel produksi), Tachometer blok 1-4: 2,6/2,6/3,0/3,1 lawan 3,2/3,2/3,5/3,5; Centrifuge blok 1-5: 2,6/2,6/3,0/3,1/4,1 lawan 3,2/3,2/3,5/3,6/4,4. Tachometer blok 5 dan 6 SAMA (lantai 5,0). Angka aplikasi dari hitung ulang, bukan dari workbook |
| T-9 | Tachometer blok 5 (set point 10000/12000/14000 rpm): pita sertifikat `='SERTIFIKAT KALIBRATOR'!F15` (1,6) padahal nominal tertinggi blok (15000) di F18 (3,1); drift `='[1]Drift Std Kalibrator'!K54` (tautan luar, kosong, cache 0); pengulangan `=MAX(PERHITUNGAN!G113:L113)` (baris kosong, 0) | `PERHITUNGAN U95%!N98, N101, N102` | SELISIH §2 | `test_selisih_tachometer_blok5_tiga_kerusakan_salin_tempel`. U Excel 1,69487, aplikasi 4,44807; "semua disamakan dengan master" memulihkan U Excel. U cetak SAMA 5,0 (lantai CMC). Aplikasi menghitung benar dan mencatat ketiganya di jejak audit |
| T-10 | Centrifuge blok 1: pengulangan `=PERHITUNGAN!G34`, satu sel (titik 1), blok lain `MAX()` sebaris | `PERHITUNGAN U95%!N13` | SELISIH §2 | `test_selisih_centrifuge_blok1_pengulangan_satu_sel`. ui pengulangan 0,02 lawan 0,04; U 2,61719 lawan 2,61807; U cetak SAMA (2,6) |
| T-11 | Nol bertanda minus: koreksi yang membulat ke nol dicetak `-0.0` di Excel (Tachometer titik 7, Centrifuge titik 2), `0.0` di aplikasi | `SERTIFIKAT!P` | SELISIH (G0-b P4) | `test_nol_bertanda_minus_excel_dicetak_tanpa_minus_oleh_aplikasi`. Keputusan 10 Nov 2026: PDF master tidak memuat `-0.0` |
| T-12 | Centrifuge: set point sampel di atas batas akreditasi 9000 rpm (blok 4: 10000; blok 5: 15000-25000) | `DATABASE!R6:S6` | SAMA | Excel memakai CMC pita teratas (1,6); aplikasi sama dan menambah peringatan `centrifuge_di_luar_akreditasi` (`LantaiCmcPutaranTest`). Dicatat supaya sampel ini tidak dibaca sebagai bukti kemampuan di atas 9000 rpm |
| T-13 | Kondisi lingkungan, tanggal terbit, identitas standar, jalur `HitungUlangSesi`/validator | `SERTIFIKAT` kepala | MENUNGGU | tidak diuji untuk rpm di batch ini (tanpa HTTP/DB); DO Meter sudah menguji kondisi lingkungan dan tanggal terbit, rpm belum |
| T-14 | Satu sampel per alat; jalur data dengan sebaran berbeda antar blok (termasuk blok 6 Tachometer, lihat B4-3) belum teruji | - | MENUNGGU | perlu sesi workbook kedua dengan pembacaan berbeda |

### Temuan baru

**B4-2 (Tachometer blok 5, daya baca standar).** `PERHITUNGAN U95%!K94` DIKETIK 0,1 di bawah label pita `7000-10000` (K93), padahal blok berisi titik sampai 14000 rpm; blok 6 (label `15000-30000`) mengetik 1. Aplikasi memakai 1 rpm untuk nominal di atas 10000 rpm, jadi ui resolusi standar blok ini 10x lebih besar dari Excel. Ini penyimpangan KEEMPAT di blok yang sudah rusak di tiga tempat (T-9) dan belum dijawab lab; aplikasi sudah mencatatnya di jejak audit (`putaran_resolusi_standar_blok5_ambang`). Tes: `test_b4_2_daya_baca_standar_blok5_tachometer_diketik_0_1`, grup `menunggu-keputusan-tm`. Pertanyaan lab: apakah 0,1 rpm benar untuk titik 10000-14000 rpm, atau label pita yang salah.

**B4-3 (Tachometer blok 6, alamat pengulangan).** Pengulangan blok 6 `=MAX(PERHITUNGAN!G94:L94)` menunjuk baris simpangan baku BLOK 5; baris milik blok 6 adalah 109. Sampel mengisi pembacaan kedua blok dengan pola yang sama (0,2683/0/0), jadi angkanya kebetulan sama dengan aplikasi (yang memakai titik blok sendiri). Begitu data blok 6 berbeda, Excel membaca blok yang salah. Tidak ada selisih angka di sampel. Tes: `test_b4_3_pengulangan_blok6_tachometer_menunjuk_baris_blok5`, grup `menunggu-keputusan-tm`. Satu salin-tempel dengan T-9; aturan repo: hitung benar + tulis selisihnya.

## 4. DO Meter, rincian per butir

Workbook: `Master Olah Data_DO Meter.xlsm`; sampel satu sesi mg/L dengan blok %O2 yang rusak di master.

| # | Butir | Status | Bukti dan catatan |
|---|---|---|---|
| D-1 | Konstanta aplikasi (seeder/profil) = sel workbook | SAMA | `test_konstanta_aplikasi_sama_dengan_workbook` |
| D-2 | Nilai standar, rata-rata, simpangan baku, koreksi | SAMA | `test_nilai_standar_rata_rata_stdev_dan_koreksi_cocok_excel` |
| D-3 | Lima komponen budget | SAMA | `test_lima_komponen_budget_cocok_excel` |
| D-4 | uc, v_eff, k, U, lantai CMC | SAMA | `test_uc_veff_k_u_dan_lantai_cmc_cocok_excel` |
| D-5 | Angka cetak | SAMA | `test_angka_cetak_sama_dengan_sertifikat_excel` |
| D-6 | Tanggal terbit = tanggal kalibrasi + 1 hari | SAMA | `test_tanggal_terbit_tambah_hari_sama_dengan_rumus_sertifikat` (`SERTIFIKAT!B39 = T8+1`). Di dalam workbook `INPUT DATA!D52 = O16+4` berbeda tetapi tidak dicetak |
| D-7 | Kondisi lingkungan thermohygro TH-2 | SAMA | `test_kondisi_lingkungan_th2_cocok_excel`; indeks TH `H14`/`H15` diketik tangan di workbook |
| B4-1 | Blok %O2: Excel mencetak baris 20,9 / 0 / 20,9 dan U95/k `#REF!` (`SERTIFIKAT!Q20`, `W21`; rantai `PERHITUNGAN U95%!AB43:AB48` `#DIV/0!`); aplikasi tidak punya titik itu | SELISIH | `test_b4_1_blok_o2_rusak_di_excel_tidak_ada_padanan_aplikasi`. Pertanyaan lab: apakah titik %O2 harus ada di sertifikat DO Meter |
| P-25 | Resolusi: Excel memakai angka yang DIKETIK (`PERHITUNGAN U95%!M24 = G6*0,5`, `G6 = INPUT DATA!E16`); aplikasi memakai yang lebih besar antara master dan alat | SELISIH | `test_p25_resolusi_aplikasi_lebih_besar_dari_ketikan_lebih_halus`; pola sama dengan P-25 Turbidimeter. Tidak berpengaruh bila ketikan = resolusi master (sampel) |
| D-8 | `INPUT DATA!D52 +4` lawan `SERTIFIKAT!B39 +1`; blok BEFORE sama dengan AFTER di sampel | MENUNGGU | ketidakkonsistenan di dalam workbook, bukan beda aplikasi; perlu jawaban lab |
| D-9 | Jalur `HitungUlangSesi`/validator | MENUNGGU | tidak diuji di batch ini |

## 5. Yang tidak dikerjakan di batch ini

- Tekanan (PressureGauge/Tekanan), DifferentialPressure, VacuumGauge, Thermohygro: lihat bagian 6 untuk statusnya.
- Tidak ada perubahan `app/`, migrasi, konfigurasi test, atau CI.
- Gate MySQL penuh (suite 3 jam) belum dijalankan; wajib sebelum modul disebut selesai.
