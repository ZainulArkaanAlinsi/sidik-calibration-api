# Paritas aplikasi lawan Excel: batch alat ke-8 (Mesin UTM, Load Cell, Sieve Mesh)

Dibuat 27 Nov 2026 (cabang `feat/paritas-batch8`, dari `origin/main` 9d6eb3e5). Tidak ada kode aplikasi (`app/`) yang diubah dan tidak ada rumus produksi yang disentuh. Dokumen ini hanya melaporkan; selisih = KONFLIK untuk Manajer Teknis (TM), tidak diperbaiki dan tidak dipilih agar tes lulus. Pola dan istilah sama dengan `docs/paritas-batch7.md` dan `docs/paritas-batch6.md`. Nomor selisih batch ini `B8-n` (batch 7 berakhir di `B7-11`); butir yang menunggu keputusan diberi `M-n` per alat (nomor `M-n` berlaku di dalam satu alat, bukan lintas alat).

Label: **SAMA** = aplikasi = Excel dalam toleransi bertulis, dibuktikan tes yang dijalankan. **SELISIH** = beda yang TERUKUR dari sel Excel (konflik bernomor `B8-n`; tes mengunci perilaku sekarang dan berada di grup `menunggu-keputusan-tm`). **MENUNGGU** = butuh keputusan orang atau bukti yang belum ada (alasannya ditulis); tes MENUNGGU mencatat apa yang teramati, bukan memutuskannya. Status "TERBUKTI" per alat BUKAN diputuskan di sini; ini bukti yang tersedia untuk koordinator dan TM.

Hitungan di tabel adalah jumlah KASUS TES per label (satu tes = satu `test_*`), bukan jumlah sel; jumlah sel yang diadu ada di kolom "Pernyataan". Tes yang tidak berlabel SELISIH atau MENUNGGU dihitung SAMA, termasuk beberapa penjaga struktur (mis. "fixture tidak memuat sel berbasis NOW()").

## 1. Ringkasan

| Alat | Hasil | Kasus tes / pernyataan | SAMA | SELISIH | MENUNGGU |
|---|---|---|---|---|---|
| Mesin UTM (6 titik 0-500 kgf, tarik, standar Load Cell 5 kN, 12 bacaan per titik) | rantai per titik, 7 dari 8 komponen budget, agregat bila drift master dipasang, kolom cetak E/L/Q, pita CMC, teks U dan k SAMA; 1 konflik (B8-1 drift standar) | 19 / 662 | 14 | 2 tes (1 butir: B8-1) | 3 (M-1, M-2, M-3) |
| Load Cell (10 titik, tarik, standar 100 kN, kN, 12 bacaan per titik) | idem UTM, kolom cetak Y; 1 konflik (B8-2 drift standar); U cetak sama teks walau nilai antara beda | 20 / 1060 | 12 | 3 tes (1 butir: B8-2) | 5 tes (M-1, M-2 dua tes, M-4, M-5) |
| Sieve Mesh (19 mm, tipe Inspection, standar Digital Caliper, 15 opening, warp / weft / kawat) | rata-rata, STDEV, pengulangan, 18 komponen budget (6 x 3 parameter), uc, veff, k, U, CMC, U cetak, sel SERTIFIKAT, vonis master SAMA; 2 konflik (B8-3 koreksi standar, B8-4 minimum opening Calibration) | 15 / 469 | 11 | 2 (B8-3, B8-4) | 2 (M-1, M-2) |

Angka CETAK:

- **UTM dan Load Cell:** angka cetak U dan k sama dengan Excel walau nilai antara drift berbeda. UTM: U 0,3965 % (aplikasi) lawan 0,4190 % (master), tetapi U cetak dikunci lantai CMC 0,020601 kN di kedua sisi dan k tercetak `2` di kedua sisi. Load Cell: U 0,297342 kN lawan 0,297610 kN, cetak `0.00` -> 0,30 di kedua sisi, k `0` -> 2. Ini kebetulan pembulatan dan lantai, bukan bukti bahwa drift benar: jarak ke batas pembulatan tidak diukur untuk sesi lain.
- **Sieve:** angka cetak U95 (`SERTIFIKAT!N20:N22`) dan vonis PASS sama. Yang BERBEDA di sertifikat adalah nilai tercetak dan koreksi: master mencetak warp 19,1387 dan weft 19,1827 (koreksi standar 0), aplikasi menambah +0,01 mm (19,1487 dan 19,1927); kawat tidak bergeser (koreksi 0,0 di kedua sisi). Itu B8-3, dan menyentuh angka CETAK `SERTIFIKAT!H20:H21` dan `K20:K21`.

Batas: satu sesi contoh per alat. Tiap bagian rincian di bawah menyebut apa yang TIDAK dibuktikan oleh satu sesi itu.

## 2. Cara membaca, menjalankan, dan menghasilkan ulang

- Tes: `tests/Feature/Paritas/ParitasUtmTest.php`, `ParitasLoadCellTest.php`, `ParitasSieveTest.php`. UTM dan Load Cell berbagi trait `tests/Concerns/MenegakkanParitasGaya.php` (13 tes yang sama persis di kedua alat; kelas alat memegang yang khas). Perangkat dasar tetap `tests/Concerns/MenegakkanParitas.php` (tidak diubah).
- Jalankan satu berkas: `php vendor/phpunit/phpunit/phpunit --configuration=phpunit.xml tests/Feature/Paritas/ParitasSieveTest.php` (SQLite; `php artisan test` menggantung di mesin kerja). Gate MySQL penuh BELUM dijalankan di sini; CI yang menjalankan, dan gate MySQL penuh tetap wajib sebelum modul disebut selesai.
- Fixture: `tests/Fixtures/paritas/utm.json`, `load-cell.json`, `sieve.json`. Tiap nilai Excel membawa alamat sel (`SHEET!SEL`); pesan galat tes menyebut sel itu (contoh: `Paritas warp rata-rata: Excel PERHITUNGAN!G80 = ..., aplikasi = ..., selisih ... (batas ...)`). Fixture disapu sebelum `git add`: tanpa nama/alamat pelanggan, nomor sertifikat, atau nomor order; `INPUT DATA!Q10:Q12` (identitas pelanggan Sieve) sengaja tidak dibaca generator.
- Workbook (`Downloads\EXEL CALIBRATION SIDIK\`, dibuka dengan sandi dari pemilik, hanya di memori lewat env `SIDIK_MASTER_SANDI`; sandi tidak ada di berkas mana pun): `Master Olah Data_Gaya_UTM.xlsm` (sha256 12 awal `9285b867bc36`), `Master Olah Data_Gaya_Load Cell.xlsm` (`2324bc8982b8`), `Master Olah Data_Sieve Mesh.xlsm` (`d86639468a5a`). Hash penuh di `workbook.sumber` tiap fixture.
- Generator menghitung ulang rantai dari sel hulu sebelum menulis dan berhenti bila selisihnya melebihi 1e-11: selisih relatif maksimum terhadap cache UTM 4,6e-13, Load Cell 1,4e-14, Sieve 1,3e-16.
- Titik panggil (tanpa HTTP): UTM dan Load Cell lewat `UtmProfile::hitungPerGrup()` / `LoadCellProfile::hitungPerGrup()` (fungsi yang SAMA dengan pratinjau, simpan, dan `HitungUlangSesi`) plus `GayaCalculator::hitungTitik()`; Sieve lewat `SieveCalculator::hitungSesi()` (mesin di belakang `SieveProfile::hitungPerGrup()`). Kesetaraan jalur hitung ulang (`HitungUlangSesi`) disimpulkan dari satu fungsi hitung bersama, bukan dari tes `raw_measurements` baru; jalur sesi penuh dijaga `GayaSesiContohCocokMasterTest` dan `SieveMasterTest`.
- Angka cetak diadu lewat format kolom profil dan pemformat produksi, bukan render PDF penuh dan bukan render Excel asli (teks Excel di fixture = nilai sel dengan format sel).

### Regenerasi fixture

```
py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-batch8.py "C:\Users\<user>\Downloads" [utm] [load_cell] [sieve]
```

Tanpa nama alat, ketiganya dibangun. Sandi dibaca dari `SIDIK_MASTER_SANDI`; skrip berhenti bila env kosong. Jangan menyunting fixture dengan tangan.

### Toleransi dan asalnya (bukan dipilih agar lulus)

Semua dari `tests/Concerns/MenegakkanParitas.php` (alasan tiap angka ditulis di kepala berkas itu, tidak diubah di batch ini): batas = `max(1e-12 mutlak, 1e-11 x |acuan|)`; `k` hasil kuantil mutlak 1e-9 (Excel `TINV` lawan `StudentTDistribution::quantile` adalah dua algoritma); U = k x uc relatif 1e-9; teks angka CETAK harus sama persis. Sieve memakai `k = 2` yang DIKETIK di master (`PERHITUNGAN U95%!AC20/37/55`, bukan TINV), jadi tesnya mengadu `k` dengan `assertSamaExcel` baku. Tidak ada toleransi yang dilebarkan untuk butir SELISIH; butir itu dikunci pada selisihnya.

Catatan batas: uc Sieve (sekitar 0,013 mm) jatuh ke lantai mutlak 1e-12, karena 1e-11 relatif hanya 1,3e-13 mutlak. Selisih yang lebih kecil dari batas itu tidak terdeteksi; lulus berarti "sama sampai sekitar 12 desimal", bukan "sama bit demi bit".

Pemeriksaan kepekaan (dilakukan sekali untuk Sieve, 27 Nov): menggeser `PERHITUNGAN!G80` dari 19,138666666666666 ke 19,1386667 dan `PERHITUNGAN U95%!AC18` dari 0,01307889448267283 ke 0,01307889448 membuat 3 tes merah dengan pesan yang menyebut selnya; fixture dikembalikan dan tes hijau lagi. Pergeseran 5e-14 relatif TIDAK terdeteksi (di bawah batas), sesuai toleransi di atas.

## 3. Mesin UTM, rincian per butir

Sesi contoh: 6 titik 0, 100 ... 500 kgf, arah Tarik, standar Load Cell 5 kN, 12 bacaan per titik, suhu ruang 24,5 / 24,3 °C.

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| U-1 | Konversi kgf -> kN, rata-rata, STDEV, RSD | `PERHITUNGAN FC` (sel di fixture) | SAMA | `test_konversi_bacaan_ke_kn_dan_nominal_sama_dengan_perhitungan_fc`, `test_rantai_per_titik_sama_dengan_perhitungan_fc` |
| U-2 | Suhu aktual standar | `K7` | SAMA | `test_suhu_aktual_standar_aplikasi_sama_dengan_k7_workbook` |
| U-3 | Koreksi standar (W), Y, koreksi termal (Z), AA, RRPE | `PERHITUNGAN FC` kolom W..AB | SAMA | `test_rantai_per_titik_sama_dengan_perhitungan_fc` |
| U-4 | Tujuh dari delapan komponen budget (ui, ci, vi) | `PERHITUNGAN U95%` baris komponen | SAMA | `test_tujuh_komponen_budget_sama_dengan_master_kecuali_drift` |
| U-5 | RSD maksimum dan masukan budget | `PERHITUNGAN U95%` | SAMA | `test_rsd_maks_dan_masukan_budget_sama_dengan_master` |
| U-6 | Agregat (jumlah (ui ci)^2, uc, veff, k, U %) bila ui drift master dipasang | `PERHITUNGAN U95%` | SAMA | `test_penutup_drift_master_dipasang_menghasilkan_budget_dan_u_master` |
| U-7 | Kolom sertifikat E / L / Q (satuan alat) dan RRPE tercetak | `SERTIFIKAT` | SAMA | `test_kolom_sertifikat_e_l_q_dalam_satuan_alat_sama_dengan_master`, `test_angka_cetak_e_l_q_dan_rrpe_sama_persis_dengan_master` |
| U-8 | Pita CMC lampiran | `DATABASE!S5:S8` | SAMA | `test_pita_cmc_lampiran_sama_dengan_database_s5_s8_master`, `test_cmc_sesi_ini_sama_dengan_ac22_dan_mengunci_u_di_kedua_sisi` |
| U-9 | Teks U cetak dan teks k | `SERTIFIKAT!P35`, `T36` | SAMA | `test_teks_u_dan_k_tercetak_sama_dengan_p35_dan_t36`, `test_u_tercetak_sama_dengan_p35_dan_format_kolom_sama_dengan_sel_master` |
| U-10 | Titik nol tidak dicetak (baris tersembunyi master) | `SERTIFIKAT` | SAMA | `test_titik_nol_tidak_dicetak_seperti_baris_tersembunyi_master` |
| **B8-1** | Drift standar: master `N13 = VLOOKUP(Z3; Tabel_Drift; 2; 0)` membaca kolom TEKAN `STANDAR_LOADCELL!Q5:R7` walau bebannya Tarik; aplikasi memakai kolom Tarik | `PERHITUNGAN U95%!N13` | SELISIH | master 0,0692820323027551 (sama dengan kolom Tekan tabel aplikasi), aplikasi 0,0. Efek: uc 0,20109 lawan 0,21269, veff 204,4 lawan 241,8, k 1,9717 lawan 1,9699, U 0,3965 % lawan 0,4190 %. Angka CETAK tidak bergeser. Pertanyaan lab PK5-1 / G2 (`docs/pertanyaan-lab-gaya.md` G2, `docs/pertanyaan-lab-adu-cetak-b.md`). `test_b8_1_*` (2 tes) |
| M-1 | Sumber zero error: master memakai selisih berpasangan 12 bacaan di beban nol dibagi beban uji terbesar; aplikasi memakai nilai mutlak terbesar `preload_zero` dibagi kapasitas | `PERHITUNGAN FC!C44:P46` | MENUNGGU | data contoh membuat keduanya 0, jadi data tidak membedakan. G8 `docs/pertanyaan-lab-gaya.md` |
| M-2 | Kolom set point V ANGKA KETIKAN di semua titik | `PERHITUNGAN FC` kolom V | MENUNGGU | aplikasi memilih set point terdekat dari tabel; hasilnya sama dengan ketikan di sesi ini, tetapi itu bukan bukti rumus |
| M-3 | Sel status berbasis `NOW()` | `INPUT DATA!Z23:Z27`, `DATABASE!Z13:Z15`, `FORM VALIDASI` | MENUNGGU | nilai cache bergeser tiap workbook dihitung ulang; tidak dipakai sebagai acuan, `test_m_3_fixture_tidak_memuat_sel_berbasis_now` memastikan fixture tidak memuatnya |

Yang tidak dibuktikan: arah Tekan, standar 100 kN dan 3000 kN, satuan lain, dan jalur `raw_measurements` penuh.

## 4. Load Cell, rincian per butir

Sesi contoh: 10 titik (0, 100, lalu 2 ... 9 kN), Tarik, standar Load Cell 100 kN, satuan kN, 12 bacaan per titik, suhu ruang 27,7 °C. Butir U-1 sampai U-7, pita CMC lampiran (U-8), dan teks U / k (U-9) diadu lewat trait bersama (13 tes yang sama dengan UTM); pita CMC sesi, U cetak P35 dan titik nol hanya di kelas UTM, dan untuk Load Cell U cetak masuk B8-2 dan M-4 di bawah. Perbedaan kolom cetak: Load Cell mencetak Y, bukan Z (G5/G12 `docs/pertanyaan-lab-gaya.md`; diamati, belum diputuskan).

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| L-1 | Rantai per titik, tujuh dari delapan komponen, agregat dengan drift master, kolom E/L/Q, pita CMC lampiran, teks U dan k | idem UTM | SAMA | 11 tes SAMA di trait bersama: 10 tes untuk butir U-1 sampai U-9 di bagian 3 (tanpa CMC sesi dan U cetak P35) ditambah `test_tabel_standar_aplikasi_tidak_memuat_resolusi_standar_jadi_dipasok_sesi` (resolusi standar dipasok sesi, bukan dari tabel) |
| L-2 | Kolom cetak Load Cell: Standard Value = Y dan Correction = AA (bukan Z) | `PERHITUNGAN FC` Y, AA; `SERTIFIKAT` E | SAMA | `test_load_cell_mencetak_y_dan_koreksi_sama_dengan_aa` |
| **B8-2** | Drift standar (sama dengan B8-1). Load Cell 100 kN: master 0,031834..., aplikasi 0,034272... | `PERHITUNGAN U95%!N13` | SELISIH | Efek: uc 0,14254 lawan 0,14311, veff 20,95 lawan 21,28, k 2,0860 lawan 2,0796, U 0,297342 kN lawan 0,297610 kN. Angka CETAK sama (`0.00` -> 0,30; k `0` -> 2). `test_b8_2_*` (3 tes). Pertanyaan lab PK5-1 / G2 |
| M-1 | Sumber zero error | idem UTM | MENUNGGU | G8; data contoh tidak membedakan |
| M-2 | Kolom V set point ketikan; tabel standar 100 kN tidak punya set point di 0-9 kN dan di atas 88,26 kN | `PERHITUNGAN FC` kolom V | MENUNGGU | master mengetik 9,80665 di titik kecil; aplikasi memilih set point terdekat (9,80665 untuk titik kecil, 88,25985 untuk 100 kN) dengan tanda `di_luar_rentang_tabel`, sama dengan ketikan teknisi. G10. Dua tes: `test_sel_ketikan_master_diamati_bukan_bukti_rumus` (trait) dan `test_m_2_titik_di_luar_tabel_standar_ditandai_...` |
| M-4 | CMC `cek range`: rumus master `PERHITUNGAN U95%!AC22` di Load Cell hanya punya cabang Pull <= 5 kN, jadi pada rentang 100 kN `AC22` = "cek range", `AH22` dan `AC23` = `#VALUE!`, dan U cetak (`P35` membaca `AH23`, `MAX` yang mengabaikan teks) jatuh ke U hitung TANPA lantai CMC | `PERHITUNGAN U95%!AC22`, `AH22`, `AC23`, `AH23` | MENUNGGU | aplikasi memilih satu-satunya pita Tarik lampiran (0-500 kgf = 0,020601 kN), yang tidak mengikat, jadi U cetak tanpa lantai di kedua sisi. `docs/pertanyaan-lab-gaya.md` G9 bertanda TERJAWAB: lampiran LK-285-IDN tidak memuat Tarik 10-88 kN untuk Load Cell, jadi lab memang tidak terakreditasi untuk rentang itu. Tes mengunci perilaku kedua sisi apa adanya; tidak ada keputusan baru dibuat di sini. Catatan: kepala `ParitasLoadCellTest` semula masih menyebut G9 sebagai pertanyaan terbuka; sudah disesuaikan |
| M-5 | Urutan beban 0, 100, 2 ... 9 kN tidak naik | data sesi contoh | MENUNGGU | aplikasi menghitung apa adanya dan memperingatkan di `temuan_sesi`. G13; soal data contoh, bukan rumus |

Catatan resolusi: resolusi standar sesi contoh seeder (0,001) beda dari `K6` master (0,000981); tes memakai nilai master (efeknya pada U kecil sekali: komponen daya baca standar). Yang tidak dibuktikan: standar 5 kN dan 3000 kN, arah Tekan, satuan lain.

## 5. Sieve Mesh, rincian per butir

Sesi contoh: ASTM E11 19 mm, tipe Inspection, standar Digital Caliper, 15 opening per parameter (warp, weft, kawat), suhu ruang 20,1 / 20,3 °C, tanggal kalibrasi 2026-05-11. Master menghitung SATU budget per parameter (6 komponen), jadi komponen dibandingkan per parameter, bukan per titik.

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| S-1 | Masukan: suhu rata ruang | `PERHITUNGAN!G17` | SAMA | `test_masukan_fixture_lengkap_dan_suhu_rata_sama_master` |
| S-2 | Nominal sieve, Ø kawat preferred, baris Tabel MPE (Y, stdev maksimum, kawat preferred / maks / min) | `PERHITUNGAN!E7:E8`, `STANDAR_KALIBRATOR!J60:N60` | SAMA | `test_nominal_dan_baris_tabel_mpe_sama_master` |
| S-3 | Minimum opening Inspection (15, sel cache) dan Compliance (150, kolom 5 tabel) | `INPUT DATA!F16`, `STANDAR_KALIBRATOR!F60` | SAMA | `test_minimum_opening_inspection_dan_compliance_sama_tabel_master`. Compliance bukan sel cache F16: diadu ke sel tabel yang dipakai rumus F16 |
| S-4 | Rata-rata, STDEV, enam salinan pengulangan (opening 1..6), STDEV pengulangan, ketiga parameter | `PERHITUNGAN!G80`, `J80`, `L80` (rata-rata); `G83`, `J83`, `L83` (STDEV); baris 89 sampai 91 (enam salinan per parameter); `H92`, `K92`, `M92` (STDEV pengulangan) | SAMA | `test_rata_rata_stdev_dan_pengulangan_sama_master` |
| S-5 | Enam komponen budget (nilai asal, pembagi, vi, ui, ci, ui*ci) x tiga parameter = 108 nilai | `PERHITUNGAN U95%` baris 11-16, 28-33, 46-51 | SAMA | `test_enam_komponen_budget_ketiga_parameter_sama_master`; urutan komponen aplikasi = urutan baris master |
| S-6 | uc, veff, k = 2 (diketik di master, bukan rumus), U = k uc, U cetak, U95 di `SERTIFIKAT!N20:N22`, k cetak `O23` | `PERHITUNGAN U95%!AC18:AC24` (warp), `AC35:AC41` (weft), `AC53:AC59` (kawat) | SAMA | `test_uc_veff_k_u_dan_u_cetak_sama_master`; tes juga menegaskan `rumus = false` untuk sel k |
| S-7 | Lantai CMC | `DATABASE!T6` (0,02 mm), `AC22` | SAMA | `test_lantai_cmc_sama_master`. `DATABASE!T5` (0,00433, pita mikroskop) bukan bagian sesi ini dan tidak diadu |
| S-8 | Kawat: nilai tercetak dan koreksi tercetak (koreksi standar 0,0 di titik indeks kawat), STDEV tercetak | `SERTIFIKAT!H22`, `K22`, `G28` | SAMA | `test_kawat_terkoreksi_dan_koreksi_tercetak_sama_master` |
| S-9 | Titik koreksi standar yang dipilih (indeks 21 mm warp dan weft) = indeks master | `PERHITUNGAN!G81`, `J81`, `L81` | SAMA | `test_titik_koreksi_dipilih_sama_dengan_indeks_master`; indeks di master DIKETIK (`rumus = false`), bukan hasil pencarian |
| S-10 | Vonis master (versi tanpa U) dan vonis simpangan baku, batas Y dan batas kawat min/maks | `SERTIFIKAT!AE20:AE22` (vonis), `AE24:AE25` (vonis simpangan baku warp dan weft), `AD20:AD25` (batas), `AB24:AB25` | SAMA | `test_vonis_master_dan_batas_sama_master` (warp, weft, kawat semua PASS; vonis simpangan baku hanya warp dan weft) |
| **B8-3** | Koreksi standar: master mencari koreksi lewat `VLOOKUP(indeks; Koreksi_Caliper; 3; 0)` (`PERHITUNGAN!G82`, `J82`), dan kolom ke-3 adalah kolom M yang KOSONG (`STANDAR_KALIBRATOR!M17`), jadi terkoreksi = rata-rata (koreksi 0); aplikasi membaca kolom ke-4 (`N17`, berumus `=K17-L17`) = +0,01 mm | `PERHITUNGAN!G82`, `J82`; `STANDAR_KALIBRATOR!M17`, `N17` | SELISIH | warp dan weft: terkoreksi aplikasi = master + 0,01; nilai tercetak dan koreksi tercetak (`SERTIFIKAT!H20:H21`, `K20:K21`) bergeser 0,01 mm (warp 19,1387 lawan 19,1487; weft 19,1827 lawan 19,1927); budget tidak tersentuh (uc sama). Kawat tidak bergeser (koreksi 0,0 di kedua sisi, `M13`/`N13`). Kerusakan salin-tempel (kolom salah): dihitung benar, selisih ditulis. `docs/pertanyaan-lab-sieve.md` §4. `test_b8_3_*` |
| **B8-4** | Minimum opening tipe Calibration: rumus `INPUT DATA!F16` memakai kolom 6 (Inspection) untuk Inspection DAN Calibration; aplikasi memakai kolom 7 | `INPUT DATA!F16`, `STANDAR_KALIBRATOR!G60`, `H60` | SELISIH | master menuntut 15, aplikasi 30. Sel F16 hanya ter-cache untuk Inspection; nilai master untuk Calibration DITURUNKAN dari teks rumus + sel tabel, BUKAN dibaca dari sel cache. `docs/pertanyaan-lab-sieve.md` §5. `test_b8_4_*` |
| M-1 | Status standar berbasis `NOW()`: `INPUT DATA!Z19` (sisa hari, berumus) membaca `DATABASE!Z13` yang berumus `=Y13-$X$11` (sel waktu berjalan); sisa hari pecahan (-111,40...), `U25` = "EXPIRED" di cache | `INPUT DATA!Z19`, `U25`, `DATABASE!Z13` | MENUNGGU | cache menunjukkan kedaluwarsa karena workbook terakhir dihitung ulang pada hari lain. Aplikasi menilai standar pada TANGGAL KALIBRASI sesi (2026-05-11). Tidak dipakai sebagai acuan. `docs/pertanyaan-lab-sieve.md` §12 |
| M-2 | Aturan vonis: master memvonis tanpa U dan dari rata-rata tanpa koreksi standar; aplikasi memakai guarded acceptance (nilai mutlak deviasi + U <= Y) | `SERTIFIKAT!AE20:AE22` | MENUNGGU | pada sesi ini keduanya PASS di ketiga parameter, jadi selisih ATURAN nyata tetapi tidak teramati; perilaku di ambang dijaga `SieveMasterTest`, bukan berkas ini. `docs/pertanyaan-lab-sieve.md` §10 |

Yang tidak dibuktikan: cabang Digital Microscope, tipe Compliance dan Calibration pada sesi penuh (hanya minimum opening yang diadu), satuan inch dan µm, opening di atas 50 per parameter (fixture berisi 15), `DATABASE!T5`, dan vonis simpangan baku kawat (tidak diadu; hanya warp dan weft). Angka tercetak diadu lewat sel `SERTIFIKAT`, bukan lewat PDF. Toleransi vonis di ambang (nilai tepat di batas) tidak diuji di sini.

Keanehan master yang tetap ditiru dan sudah ada sebagai pertanyaan lab bernomor di `docs/pertanyaan-lab-sieve.md` (tidak ada pertanyaan baru ditambahkan di batch ini): pengulangan dibagi 6 (§1), k dipatok 2 (§2), blok pengulangan salinan (§3), minimum opening tidak ditegakkan (§7), pita CMC master berbeda dari lampiran (§8), vonis tanpa U (§10).

## 6. Konflik untuk Manajer Teknis

| No | Alat | Isi | Mengubah angka CETAK sertifikat? | Perilaku aplikasi sekarang |
|---|---|---|---|---|
| B8-1 | Mesin UTM | drift standar dibaca dari kolom Tekan di master untuk beban Tarik | tidak pada sesi contoh (lantai CMC dan k menang); nilai U hitung berbeda | memakai kolom Tarik |
| B8-2 | Load Cell | idem B8-1 | tidak pada sesi contoh (cetak `0.00` dan k `0` sama) | memakai kolom Tarik |
| B8-3 | Sieve | master membaca kolom koreksi standar yang kosong (koreksi 0) | YA: nilai dan koreksi tercetak warp dan weft bergeser 0,01 mm | memakai kolom Koreksi |
| B8-4 | Sieve | master memakai kolom minimum opening Inspection untuk Calibration | YA untuk tipe Calibration (baris "Minimum Number of Apertures Examined" dan keputusan jumlah opening) | memakai kolom Calibration (30) |

Dokumen ini tidak memutuskan mana yang benar. Keadaan di `docs/pertanyaan-lab-sieve.md`: B8-3 (§4) sistem sudah memakai kolom Koreksi, tetapi konfirmasi arah tanda dan peninjauan sertifikat lama masih pertanyaan terbuka. B8-4 (§5) sudah ada keputusan riset lab (`F16` untuk Calibration adalah kerusakan salin-tempel; sertifikat mencetak kolom yang benar untuk tipenya, selisih dicatat di jejak sesi, dikunci `SertifikatTahapB2Test`); tes batch ini hanya mengukur selisihnya terhadap sel master dan tidak memeriksa paraf TM atas keputusan itu. B8-1 dan B8-2 (G2, bertanda merah) masih terbuka.

## 7. Alat yang tidak dikerjakan di batch ini dan alasannya

| Alat | Alasan |
|---|---|
| Timbangan, Timer / Stopwatch | ditunda atas perintah penugasan: menunggu Manajer Teknis |
| Proving Ring | ditahan di `config/sertifikat.php` `profil_ditahan` (TM-4, pita CMC mana yang benar = G14 belum diputuskan); bukan di daftar kandidat penugasan |
| Picnometer | ditahan di `profil_ditahan` (TM-7: empat pertanyaan PK-3 belum dijawab, angka cetak belum pernah diadu master); bukan di daftar kandidat penugasan |
| Differential Pressure, Vacuum Gauge | cakupan paritas sudah ada (keluarga tekanan, `ParitasTekananTest`); Vacuum tidak punya workbook sendiri (dilewati di batch 6) |
| Viscometer | BELUM dikerjakan karena batas anggaran konteks sesi ini, bukan karena penghalang teknis: profil `ViscometerProfile` ada dan workbook ada (`Master Olah Data_Viscometer.xlsm`, `5. Viscometer 86068360 terbaru .xlsm`). Kandidat pertama batch berikutnya. Judul `docs/paritas-batch5.md` menyebut Sieve dan Viscometer tetapi sebelum batch ini tidak ada `ParitasSieveTest` maupun `ParitasViscometerTest` di `tests/Feature/Paritas/`; Sieve baru ada sekarang, Viscometer belum |
| Keluarga Oven / Furnace / Inkubator / Bath, Refrigerator | tidak ada profil API dengan nama itu di `app/Services/Calibration/Profiles/`. Profil ruang suhu yang ada bernama `TidsProfile`, `TitsProfile`, `ProfilSuhuPasangan` (dan folder `Enclosure/`); Bath ditahan (`profil_ditahan` TM-8). Tidak dievaluasi alat mana yang dilayani profil-profil itu dan tidak dijangkau di batch ini |
| Keluarga Gelas / Labu / Pipet / Buret | profil ada: `BuretProfile`, `BuretDigitalProfile`, `FixedVolumetricGlasswareProfile` (+ `Rev7`), `GraduatedVolumetricGlasswareProfile`, `GelasUkurProfile`, `LabuUkurProfile`, `PipetUkurProfile`, `PipetVolumeProfile`, `VolumetricGlasswareProfile`; workbook Volumetric ada. Tidak dijangkau di batch ini (batas anggaran konteks). Satu pertanyaan lab terbuka sudah menunggu: `docs/pertanyaan-lab-volumetric.md` no. 1 (veff dibagi satu baris) |

Batas yang dipakai tugas ini: berhenti setelah 5 alat atau 3 kegagalan pada langkah yang sama. Keduanya TIDAK tercapai: 3 alat selesai, 0 kegagalan berulang. Penyebab berhenti di 3 adalah anggaran konteks sesi, dan hanya itu.

## 8. Risiko dan hal yang perlu dicek peninjau

- Fixture dan tes sama-sama memakai nilai cache workbook; workbook yang dihitung ulang lain hari bisa menggeser sel `NOW()` (M-3 UTM, M-1 Sieve). Sel itu tidak dipakai sebagai acuan angka.
- Nilai master untuk Sieve tipe Calibration (B8-4) diturunkan dari teks rumus + sel tabel, bukan dari sel cache. Peninjau perlu menilai apakah penurunan itu cukup sebagai bukti.
- Trait `MenegakkanParitasGaya` dipakai bersama oleh UTM dan Load Cell; perubahan padanya menggeser dua kelas tes sekaligus.
- Tidak ada perubahan `app/`. Gate MySQL penuh (3 jam) belum dijalankan; CI yang menjalankan shard SQLite dan MySQL.
