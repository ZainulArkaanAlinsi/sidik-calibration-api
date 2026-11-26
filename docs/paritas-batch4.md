# Paritas aplikasi lawan Excel: DO Meter, Tachometer, Centrifuge, Thermohygrometer, Tekanan (batch alat ke-4)

Dibuat 26 Nov 2026 (cabang `feat/paritas-batch4`, dari `origin/main` 6361f281). Tidak ada kode aplikasi (`app/`) yang diubah. Dokumen ini hanya melaporkan; selisih tidak diperbaiki dan tidak dipilih agar tes lulus. Pola, istilah, dan toleransi sama dengan `docs/paritas-micrometer-ph.md` dan `docs/paritas-analitik-batch2.md`.

Label: **SAMA** = aplikasi = Excel dalam toleransi bertulis, dibuktikan tes yang dijalankan. **SELISIH** = beda yang terukur (tes menegaskan perilaku sekarang dengan penanda `SELISIH`). **MENUNGGU** = butuh keputusan orang atau bukti yang belum ada, atau belum diuji di sini (alasannya ditulis). Status "TERBUKTI" per alat bukan diputuskan di sini.

Nomor temuan baru: `B4-n`. Selisih yang sudah punya keputusan lama dirujuk ke `docs/pertanyaan-lab-waktu-frekuensi.md` (§2 blok rusak, §3 drift K sebagian, §10 tanda koreksi Tachometer), keputusan A-1 Thermohygro (`docs/pertanyaan-lab-suhu-3alat.md`), dan log metode Tekanan T-1/T-2/T-11 (pertanyaan P-1/P-2/P-11; `docs/pertanyaan-lab-tekanan.md`), dan tidak diberi nomor baru.

## 1. Ringkasan

| Alat | Hasil | SAMA | SELISIH | MENUNGGU |
|---|---|---|---|---|
| DO Meter (mg/L, %O2) | rantai penuh mg/L SAMA (konstanta, standar, 5 komponen, uc, v_eff, k, U, lantai CMC, angka cetak, tanggal terbit, kondisi lingkungan TH-2); 2 SELISIH (B4-1, P-25) | 7 | 2 | 2 |
| Tachometer inframerah (18 titik, 6 blok) | semua kolom titik SAMA; 5 dari 6 blok SAMA dengan tabel versi-master; blok 5 rusak di workbook | 6 | 6 | 2 |
| Centrifuge (15 titik, 5 blok) | semua kolom titik SAMA; 4 dari 5 blok SAMA dengan tabel versi-master; blok 1 pengulangan satu sel | 7 | 4 | 2 |
| Thermohygrometer (10 titik: 5 suhu, 3 %RH Biobase, 2 %RH GEA) | titik, konstanta, tabel koreksi, komponen non-akar-ganda, agregasi dengan masukan Excel, U95/k tercetak, CMC lampiran SAMA; 8 baris budget akar ganda (A-1) dan turunannya, k pecahan, urutan tabel Humidity, `M63 #REF!` SELISIH | 8 | 4 | 4 |
| Tekanan (DRUCK07G, DRUCK13G, SPMK, Differential: lembar SERTIFIKAT) | mode MASTER SAMA di semua sel tabel hasil, U95, k, nilai dan teks; SPMK mode BENAR SAMA; tiga varian lain berselisih tepat di yang dibetulkan T-1/T-2/T-11 dan ditahan sampai TM menjawab | 4 | 3 | 3 |
| Jumlah (64 butir) | | 32 | 19 | 13 |

Angka cetak sampel yang berbeda dari Excel: U95 di 4 dari 6 blok Tachometer dan di 5 dari 5 blok Centrifuge (drift §3, bagian 4); Thermohygrometer tidak berbeda di satu pun angka cetak (A-1 menggeser nilai antara, bukan cetak); Tekanan tidak berbeda di mode MASTER, dan berbeda di mode BENAR pada U95/k DRUCK07G, U95 Differential, dan kolom naik DRUCK13G (sesi itu tidak terbit sampai TM menjawab). Tidak ada angka cetak lain. Selisih terbesar yang mengubah nilai antara adalah Tachometer blok 5 (U 1,69 lawan 4,45 rpm sebelum lantai CMC), tetapi U cetaknya SAMA (5,0) karena lantai CMC.

## 2. Cara membaca, menjalankan, dan menghasilkan ulang

- Tes: `tests/Feature/Paritas/ParitasDoMeterTest.php` (10 kasus), `ParitasPutaranTest.php` (22 kasus; sebagian ber-`DataProvider` untuk dua alat), `ParitasThermohygroTest.php` (35 kasus), `ParitasTekananTest.php` (17 kasus, empat varian). Jalankan satu berkas: `php vendor/phpunit/phpunit/phpunit --configuration=phpunit.xml tests/Feature/Paritas/ParitasPutaranTest.php`. Gate MySQL penuh BELUM dijalankan di sini; CI yang menjalankan.
- Fixture: `tests/Fixtures/paritas/do-meter.json`, `tachometer.json`, `centrifuge.json`, `thermohygro.json`, `tekanan.json`. Tiap nilai Excel membawa alamat sel (`SHEET!SEL`); pesan galat tes menyebut sel itu. Dibangun `docs/skrip/gen-fixture-paritas-batch4.py` dari nilai-cache workbook. Sandi hanya dari `SIDIK_MASTER_SANDI`, di memori. Fixture tidak memuat nama atau alamat pelanggan, nomor sertifikat, nomor order, atau teknisi.
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

## 5. Thermohygrometer, rincian per butir

Workbook: `Master Olah Data Suhu & Kelembapan.xlsm`; satu sesi sampel (5 titik suhu 15-50 C, 3 titik %RH chamber Biobase, 2 titik %RH chamber GEA). Titik panggil: `ThermohygroCalculator::hitungSesi()` dengan masukan dari sel `INPUT DATA`; agregasi diuji lewat `GumCalculator::agregasiBudget` dan `StudentTDistribution::quantile`.

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| H-1 | Rata-rata, simpangan baku, koreksi standar, nilai terkoreksi, rata-rata dan simpangan baku UUT, 10 titik | `PERHITUNGAN FC`, `SERTIFIKAT` kolom Standard/UUT/Correction | SAMA | `test_tahap_titik_sama_dengan_perhitungan_fc_dan_sertifikat`, `test_semua_titik_terhitung_dan_masuk_grup_yang_sama_dengan_blok_workbook` |
| H-2 | Angka cetak 10 titik | `SERTIFIKAT` | SAMA | `test_angka_cetak_tiap_titik_sama_dengan_sertifikat` |
| H-3 | Konstanta dan tabel koreksi kalibrator | `DATABASE`, tabel koreksi | SAMA | `test_konstanta_dan_tabel_koreksi_aplikasi_sama_dengan_workbook` |
| H-4 | Urutan dan jumlah komponen budget; nilai `ui` untuk komponen yang tidak berakar ganda | `PERHITUNGAN U95%` | SAMA | `test_urutan_dan_jumlah_komponen_budget_sama_dengan_baris_workbook`, `test_u_komponen_sama_dengan_excel_kecuali_delapan_baris_akar_ganda` |
| H-5 | Agregasi (uc, v_eff, k, U) bila diberi masukan komponen milik Excel | `AC27`..`AC30` dan blok serupa tiap grup | SAMA | `test_agregasi_dengan_masukan_excel_sama_dengan_blok_agregat_workbook`. Membuktikan agregator sama; selisih A-1 ada di komponen, bukan di agregator |
| H-6 | U95 dan k tercetak | `SERTIFIKAT!U22`, `U35`, `U49`, `T30`, `T64` | SAMA | `test_u95_dan_k_tercetak_sama_dengan_sertifikat`: suhu "2.0", %RH tertahan lantai CMC "4.8", k "2". Sel k `T64` (Biobase) dipakai bersama dua chamber %RH di master |
| H-7 | CMC lampiran akreditasi "Thermohygrometer" (suhu 1,7; %RH 4,8) | `DATABASE` | SAMA | `test_cmc_lampiran_akreditasi_sama_dengan_database_workbook` |
| H-8 | Chamber (Biobase atau GEA) dari set point sama dengan blok isian workbook | blok isian | SAMA | `test_chamber_dari_set_point_sama_dengan_blok_isian_workbook`; sampel memuat 49 dan 50 %RH, satu di tiap sisi ambang |
| A-1 | Delapan baris budget memakai `U = N/SQRT(Q)` padahal `Q` sudah berisi pembaginya; aplikasi memakai `N/Q` | `PERHITUNGAN U95%` | SELISIH (keputusan A-1) | `test_agregat_aplikasi_sama_dengan_rantai_pembagi_benar_dan_berselisih_dari_excel` (aplikasi = rantai pembagi benar, berbeda dari Excel lebih dari 5e-4 relatif), `test_catatan_audit_akar_ganda_mengutip_angka_master_yang_sama_dengan_excel` (jejak audit memuat angka master = U Excel). U95 hitung: suhu +0,2 %, %RH Biobase -8,6 %, %RH GEA -2,9 %; U95 tercetak tidak bergeser |
| B4-4 | `k` aplikasi memakai `v_eff` pecahan (`FLOOR_V_EFF = false`), Excel `TINV` memotong derajat bebas | `AC29` (`TINV`) | SELISIH | `test_k_aplikasi_dan_excel_beda_hanya_karena_v_eff_tidak_dipotong`. Selisih U kurang dari 0,005, jauh di bawah presisi cetak (k dicetak `0`). Docblock `FLOOR_V_EFF` di kalkulator menyebut polinomial, bukan `TINV`: menyesatkan, tidak diubah |
| B4-5 | Urutan tabel Humidity: master mencetak GEA lebih dulu, kalkulator Biobase lebih dulu | `SERTIFIKAT!E35:E36`, `E49:E51` | SELISIH (urutan, bukan angka) | `test_urutan_tabel_humidity_master_gea_dulu_sedangkan_kalkulator_biobase_dulu`. Docblock kalkulator menulis "seperti sheet", keliru. Apakah urutan sampai ke kertas bergantung pada `titik_ke` yang diisi teknisi dan butuh keputusan lab |
| M63 | `SERTIFIKAT!M63 = #REF!` (baris U95 %RH di bawah tabel Humidity rusak di master); aplikasi mencetak U95 %RH yang benar | `SERTIFIKAT!M63` | SELISIH (deviasi sengaja, catatan audit `thermohygro_u95_rh_ref_rusak`) | `test_sel_u95_kelembapan_di_bawah_tabel_rusak_dan_aplikasi_mencetak_nilai_yang_benar` |
| H-9 | Indeks koreksi standar: Excel memakai indeks yang DIKETIK teknisi, aplikasi memakai titik tabel terdekat | `PERHITUNGAN FC` | MENUNGGU | `test_indeks_koreksi_excel_diketik_dan_sama_dengan_titik_terdekat_aplikasi`: sama di 10 titik sampel; sesi dengan indeks sengaja berbeda tidak punya acuan Excel |
| H-10 | Paraf Manajer Teknis atas A-1 | - | MENUNGGU | keputusan 16 Sep 2026 disetujui pemilik, paraf MT menyusul |
| H-11 | Perilaku persis tepat di ambang chamber 50 %RH | - | MENUNGGU | di workbook teknisi memilih blok sendiri; tidak ada sel yang bisa dibandingkan |
| H-12 | Jalur `HitungUlangSesi`/validator, kondisi lingkungan, tanggal terbit | - | MENUNGGU | tidak diuji di batch ini (tanpa HTTP/DB) |

## 6. Tekanan (Pressure Gauge, Vacuum Gauge, Differential Pressure), rincian per butir

Workbook: `Master Olda Pressure DRUCK07G -0.8~2bar new.xlsm`, `Master Olda Pressure DRUCK13G 0~20bar.xlsm`, `Master Olda Pressure SPMK.xlsm`, `Master Olda Pressure Differential.xlsm`. Yang SUDAH dibuktikan lama dan tidak diulang: rantai `PERHITUNGAN FC` dan `PERHITUNGAN U95%` (962 sel, 1e-12) oleh `tests/Unit/TekananMasterTest.php`, dan CMC lampiran lawan sel master oleh `tests/Feature/TekananCmcTest.php`. Celah yang ditutup di sini: lembar `SERTIFIKAT` langsung, yaitu nilai TAMPIL (satuan pilihan teknisi, tiga kolom histeresis) dan teks yang dicetak, yang di tes lama hanya diperiksa saat fixture dibangun.

Layout `SERTIFIKAT`: baris titik mulai 22, E setelan, I/L standar naik/turun, N/P koreksi naik/turun, R/T/V histeresis 1-3, `P35` U95, `U36` k. Jumlah titik: DRUCK07G 6, DRUCK13G 5, SPMK 3, Differential 6. Masukan hitung dari `database/data/sesi-master-tekanan.json`; kesamaan berkas dibuktikan sha256 penuh.

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| PR-1 | Fixture berasal dari workbook yang BYTE-nya sama dengan sumber masukan hitung; jumlah titik, satuan, setelan sama | `SERTIFIKAT!E22:E..`, `E20`, `S35` | SAMA | `test_lembar_sertifikat_berasal_dari_workbook_yang_sama_dengan_masukan_hitung` (4 varian) |
| PR-2 | Mode MASTER: standar naik/turun, koreksi naik/turun, histeresis 1-3 di tiap titik; nilai, format sel, dan teks tercetak | `SERTIFIKAT!I,L,N,P,R,T,V` | SAMA | `test_tabel_hasil_mode_master_sama_dengan_sertifikat_nilai_dan_teks` (4 varian). Format sel master = format profil (`FormatSertifikatAcuan`); teks lewat `TataLetakSertifikat::formatAngka`, termasuk tanda minus |
| PR-3 | Mode MASTER: U95 = MAX(U, CMC)/faktor dan k = ROUND(k; 1), nilai dan teks | `SERTIFIKAT!P35`, `U36` | SAMA | `test_u95_dan_k_mode_master_sama_dengan_sertifikat_nilai_dan_teks` (4 varian; k Differential diformat `0`) |
| PR-4 | SPMK tidak memicu cacat master mana pun: mode BENAR = Excel di semua yang tercetak | seluruh `SERTIFIKAT` | SAMA | `test_mode_benar_hanya_menggeser_yang_dibetulkan_dan_yang_tercetak_berbeda_ditahan` (kasus SPMK, daftar beda kosong) |
| PR-5 | DRUCK07G mode BENAR (T-1, T-2): tabel hasil sama, tetapi U naik sekitar sebelas kali; U95 `P35` 0,052 lawan 0,565 dan k `U36` 2 lawan 4,3 | `P35`, `U36` | SELISIH (ditahan, P-1/P-2) | kasus DRUCK07G pada tes yang sama; angka aplikasi dari hitung ulang, bukan dari workbook |
| PR-6 | DRUCK13G jenis vakum mode BENAR (T-2, T-11): U95 dan k tercetak sama, tetapi kolom naik (I dan N) di 5 titik tercetak lain, karena Excel membaca kolom U95 sebagai koreksi (`AD24`) | `SERTIFIKAT!I22:I26`, `N22:N26` | SELISIH (ditahan, P-11) | kasus DRUCK13G; mis. `I22` Excel 0,077 lawan aplikasi -0,0613 |
| PR-7 | Differential mode BENAR (T-2): tabel dan k (`2`) sama, U95 `0.06` menjadi `0.07` | `P35` | SELISIH (ditahan, P-2) | kasus Differential |
| PR-8 | Jawaban Technical Manager P-1, P-2, P-11 | - | MENUNGGU | sesi yang memicu T-1/T-2/T-11 tidak terbit sampai log metode versi baru mencatat jawaban; `test_pemicu_penahanan_sama_dengan_varian_yang_tercetak_berbeda` mengunci pemicu (DRUCK07G: T-1, T-2; DRUCK13G vakum: T-2, T-11; SPMK: kosong; Differential: T-2) |
| PR-9 | Tabel sertifikat ujung ke ujung (`tabelSertifikatTekanan`, butuh DB), jalur `HitungUlangSesi`/validator | - | MENUNGGU | sudah ada di `TekananSesiTest`; tidak dijalankan di batch ini (CI/gate MySQL) |
| PR-10 | Satu sampel per varian dan satu satuan per varian; satuan lain (faktor lain) belum terbukti lawan sel Excel | - | MENUNGGU | perlu sesi workbook kedua dengan satuan berbeda |

## 7. Yang tidak dikerjakan di batch ini

- DialIndicator, HeightGauge, TimerStopwatch, Hydrometer, Sieve, Viscometer: milik batch 5 (agen lain).
- Tidak ada perubahan `app/`, migrasi, konfigurasi test, atau CI.
- Gate MySQL penuh (suite 3 jam) belum dijalankan; wajib sebelum modul disebut selesai.
