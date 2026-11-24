# Paritas aplikasi lawan Excel: Refractometer, Turbidimeter, Chlorine Meter (batch alat ke-2)

Dibuat 25 Nov 2026 (cabang `feat/paritas-refraktometer-turbidimeter-klorin`, dari `origin/main` 3ab0a8f3, yang sudah memuat PR #298 parameter keputusan metode). Tidak ada kode aplikasi (`app/`) yang diubah. Dokumen ini hanya melaporkan; selisih = KONFLIK untuk Manajer Teknis (TM), tidak diperbaiki dan tidak dipilih agar tes lulus. Pola dan istilah sama dengan `docs/paritas-micrometer-ph.md` (PR #299).

Label: **SAMA** = aplikasi = Excel dalam toleransi bertulis, dibuktikan tes yang dijalankan. **SELISIH** = beda yang terukur (konflik bernomor P-20..P-29, tes menegaskan perilaku sekarang dengan penanda `SELISIH P-nn`). **MENUNGGU** = butuh keputusan orang atau bukti yang belum ada, atau belum diuji di sini (alasannya ditulis). Status "TERBUKTI" per alat BUKAN diputuskan di sini; ini bukti yang tersedia untuk koordinator dan TM.

Hanya perilaku BAWAAN yang diuji. Parameter keputusan metode PR #298 dibiarkan di bawaannya (`refractometer.sumber_deviasi_suhu` = `suhu_ruang`); pilihan non-bawaan tidak disentuh.

## 1. Ringkasan

| Alat | Hasil | SAMA | SELISIH | MENUNGGU |
|---|---|---|---|---|
| Refractometer (n20D) | rantai penuh SAMA di sesi contoh (standar, koreksi suhu 20 C, 5 komponen, uc, v_eff, k, U, lantai CMC, angka cetak, kondisi lingkungan suhu), 113 baris tabel konversi suhu SAMA; 2 SELISIH (P-20, P-21); P-22 internal workbook | 27 | 2 | 9 |
| Turbidimeter (NTU, 3 titik) | rantai penuh SAMA di ketiga titik (termasuk STDEV tidak nol dan lantai CMC); 2 SELISIH (P-23, P-25); P-24 dan P-26 menunggu TM | 21 | 2 | 9 |
| Chlorine Meter (mg/L, 2 titik) | titik 1,74 SAMA di semua tahap; titik 1,83: komponen Type A beda karena sel tempelan (P-27), angka TERCETAK sama (0,08) | 24 | 2 | 6 |
| Jumlah (102 butir) | | 72 | 6 | 24 |

Di luar 6 SELISIH, tidak ada tahap hitung yang berbeda dari Excel di sampel. Satu-satunya angka CETAK sampel yang berbeda dari Excel: U95 kelembaban Refractometer (P-21, `± 5,2` lawan `± 5,3`). P-20 baru berpengaruh pada suhu ruang di luar 21,0-21,1 C; P-23 bila RH awal berbeda dari akhir (sampel mengetik 2 dan 55; seeder memakai 55/55); P-25 bila resolusi ketikan lebih halus dari master; P-27 tidak mengubah angka cetak (lantai CMC). P-22 dan P-28 adalah ketidakkonsistenan di dalam workbook itu sendiri. Daftar lengkap dan efek ke angka cetak ada di bagian 6.

## 2. Cara membaca, menjalankan, dan menghasilkan ulang

- Tes: `tests/Feature/Paritas/ParitasRefractometerTest.php` (10 kasus), `ParitasTurbidimeterTest.php` (9), `ParitasChlorineMeterTest.php` (10). Jalankan: `php artisan test --filter='Paritas(Refractometer|Turbidimeter|ChlorineMeter)Test'` (SQLite; 29 kasus, 1609 assertion). Gate MySQL penuh BELUM dijalankan di sini; CI yang menjalankan. Perangkat bersama: trait `tests/Concerns/MenegakkanParitas.php` (PR #299, tidak diubah) dan `tests/Concerns/MenyiapkanAlatAnalitik.php` (baru: seeder produksi, alat sintetis, `KondisiLingkungan`).
- Fixture: `tests/Fixtures/paritas/refractometer.json`, `turbidimeter.json`, `chlorine-meter.json`. Tiap nilai Excel membawa alamat sel (`SHEET!SEL`); pesan galat tes menyebut sel itu. Dibangun `docs/skrip/gen-fixture-paritas-analitik-batch2.py` (generator PR #299 tidak diubah). Sandi workbook hanya dari `SIDIK_MASTER_SANDI`, di memori; fixture tanpa nama/alamat pelanggan, nomor sertifikat, nomor order, atau teknisi (dipindai 0 kemunculan; sandi tidak muncul di berkas mana pun).
- Workbook yang dipakai (folder `EXEL CALIBRATION SIDIK`): Refractometer `c634ded9e7e6` (semua salinan identik), Turbidimeter `a8151cf7eb61` (`.xlsm` dan `.xlsm2` identik), Chlorine Meter `116eadaff676` (18 Nov 2026; varian lama `1a4203d90cf2` berbeda hanya pada sel `NOW()` menurut `VERIFIKASI-24NOV.md` T1-2). Angka fixture = nilai-cache sel. Rekonstruksi rantai `PERHITUNGAN U95%` dari sel komponen mereproduksi cache dengan selisih relatif maksimum 1,0e-15 (Refractometer), 1,2e-12 (Turbidimeter), 1,1e-12 (Chlorine); di atas 1e-11 generator berhenti. Selisih 1e-12 itu `k` (TINV Excel lawan scipy).
- Titik panggil (tanpa HTTP): `GumCalculator::hitungTitik()` (fungsi yang sama dengan preview, simpan, dan `HitungUlangSesi`), dengan masukan disusun seperti `CalibrationController` (suhu larutan = rata-rata suhu baris terisi; suhu ruang = rata-rata MENTAH awal+akhir). Kondisi lingkungan: `KondisiLingkungan::hitung()` dengan data thermohygro produksi (`ThermohygroSeeder`). Konstanta aplikasi dibaca dari SEEDER produksi (`*CapabilitySeeder`, `*Seeder`) dan profil, bukan diketik ulang, jadi tes juga membuktikan konstanta seeder = sel workbook.
- Angka cetak diuji pada pemformat produksi (`Angka`) dengan desimal yang ditetapkan profil, bukan render HTML/PDF penuh dan bukan render Excel asli (teks Excel = emulasi pembulatan 15 angka penting lalu desimal format, setengah menjauhi nol).
- Uji mutasi (tidak di-commit, `git diff -- app` kosong setelah tiap putaran): U larutan Refractometer dibagi 2 bukan 1 -> 3 tes merah; koreksi suhu Refractometer dibalik tandanya -> 3 merah; `ci` suhu Turbidimeter `/300` bukan `/400` -> 2 merah; `CI_PENGENCERAN` Chlorine 0,0003 -> 2 merah; komponen suhu Turbidimeter dibagi 2 bukan akar 3 -> 2 merah. Pembulatan MySQL disimulasikan di SQLite (`ROUND(...,8)` pada `u_temperature`, CMC, U larutan): 29 tes tetap hijau.

### Toleransi dan asalnya (bukan dipilih agar lulus)

Sama dengan `docs/paritas-micrometer-ph.md` bagian 2 (`MenegakkanParitas`: relatif 1e-11, lantai mutlak 1e-12; `k` mutlak 1e-9; angka cetak teks SAMA PERSIS), ditambah satu kelompok khusus:

| Besaran | Toleransi | Asal |
|---|---|---|
| ui, uici komponen suhu | relatif 2e-7 | kolom `calibration_capabilities.u_temperature` `decimal(20,8)`: MySQL membulatkan 0,36124783736 ke 0,36124784 (relatif 7,3e-9) dan 0,03935733731 ke 0,03935734 (6,8e-8, Refractometer). Batas = 3x yang terburuk; konstanta salah memberi >= 1e-4 |
| uc, U sebelum lantai (dan U cetak bila tanpa lantai) | relatif 1e-10 | komponen suhu hanya 4e-5..3,2e-4 dari jumlah kuadrat (terburuk 1000 NTU), jadi pergeseran uc <= ~5e-12 |
| v_eff | relatif 1e-9 | v_eff ~ uc^4: 4x pergeseran uc; k aman karena `floor(v_eff)` jauh dari bilangan bulat |

Disimulasikan pada SQLite dengan nilai dibulatkan 8 desimal (lihat atas): hijau. Selisih mutlak ui terbesar yang teramati di SQLite (float penuh): 7,3e-17 (Refractometer), 6,4e-16 (Turbidimeter), 5,6e-16 (Chlorine).

## 3. Refractometer, rincian per butir

Sesi contoh workbook: dua titik n20D (1,33659 dan 1,39986), lima pengulangan, suhu larutan 27 C (titik 1; repeat ke-5 35 C) dan 25 C, suhu ruang 20,9 / 21,2 C (rata-rata 21,05), kelembaban 62 / 60 %RH, thermohygro TH-5. Semua pembacaan titik identik, jadi STDEV = 0.

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| R-1 | U95 larutan standar n20D (0,000026 / 0,000037) | DATABASE!V15, V16 | SAMA | `test_konstanta_aplikasi_sama_dengan_workbook`; seeder `RefractometerSeeder` |
| R-2 | CMC titik (6,2e-05 / 6,7e-05) | DATABASE!S5, S6 | SAMA | idem; `RefractometerCapabilitySeeder` |
| R-3 | UTemperature = sqrt((0,07/2)^2 + (0,036/2)^2) = 0,0393573373 | PERHITUNGAN U95%!V4, AC4, AJ3 | SAMA | idem dan `test_p22_...` (rel 1e-7 untuk decimal(20,8)) |
| R-4 | Resolusi 0,0001 dan pembagi larutan 1 (bukan k=2) | INPUT DATA!E16; PERHITUNGAN U95%!Q9 | SAMA | idem |
| R-5 | Tabel konversi n20D: deviasi, 113 baris (19,0-30,2 C) | Tab Konversi Temperatur!H4:H116 | SAMA | `test_tabel_konversi_suhu_113_baris_sama_dengan_model_linear`, `RefractometerProfile::deviasiSuhu` |
| R-6 | Tabel konversi n20D: nilai terkoreksi, 113 baris | Tab Konversi Temperatur!G4:G116 | SAMA | idem, `rataRataPadaSuhuAcuan` |
| R-7 | Tabel konversi °Brix: deviasi, 113 baris | Tab Konversi Temperatur!E4:E116 | SAMA | idem (koef 0,07/C) |
| R-8 | Tabel konversi °Brix: nilai terkoreksi, 113 baris | Tab Konversi Temperatur!D4:D116 | SAMA | idem |
| R-9 | Nilai standar nominal tiap titik | PERHITUNGAN!G22, I22 | SAMA | `test_nilai_standar_rata_rata_terkoreksi_stdev_dan_koreksi_cocok_excel` |
| R-10 | Rata-rata UUT terkoreksi ke 20 C (1,33935 / 1,40085) | PERHITUNGAN!J46, J47 (= SERTIFIKAT!I18, I19) | SAMA | idem; selisih 0 |
| R-11 | STDEV sampel | PERHITUNGAN!G41, I41 | SAMA | idem; nilainya 0 di sampel, jadi jalur sebaran tidak nol tidak teruji (R-31) |
| R-12 | Koreksi cetak Std - Terkoreksi (-0,00276 / -0,00099) | SERTIFIKAT!O18, O19 | SAMA | idem |
| R-13 | Komponen 1 kemurnian larutan: ui, ci, vi | PERHITUNGAN U95%!U9, X9, S9 (blok 2: U24, X24, S24) | SAMA | `test_lima_komponen_budget_cocok_excel` |
| R-14 | Komponen 2 daya baca: ui, ci, vi (1e6) | U10, X10, S10 (U25, X25, S25) | SAMA | idem |
| R-15 | Komponen 3 perbedaan suhu pada suhu ruang sesi contoh (21,05 C -> baris 21,0, -0,00045) | U11, X11, S11 (U26, X26, S26); N11 | SAMA | idem; BUKAN berlaku umum (lihat R-28) |
| R-16 | Komponen 4 temperature: ui = UTemp/2, ci = resolusi 0,0001, vi 200 | U12, X12, S12 (U27, X27, S27) | SAMA | idem |
| R-17 | Komponen 5 pengulangan: ui, ci, vi 4 | U13, X13, S13 (U28, X28, S28) | SAMA | idem; nilai 0 |
| R-18 | uc (0,000262703646 / 0,000264019329) | AC15, AC30 | SAMA | `test_uc_veff_k_u_dan_lantai_cmc_cocok_excel`; selisih maks 3,5e-17 |
| R-19 | v_eff (52,2656 / 53,3164) | AC16, AC31 | SAMA | idem; selisih 6,5e-13 / 9,2e-13 |
| R-20 | k = TINV(0,05; v_eff dipotong) (2,006647 / 2,005746) | AC17, AC32 | SAMA | idem; selisih 5,4e-13 / 6,2e-13 |
| R-21 | U sebelum lantai (0,000527153 / 0,000529556) | AC18, AC33 | SAMA | idem; selisih 2,1e-16 / 2,3e-16 |
| R-22 | Lantai CMC dan U cetak = MAX(U; CMC) (lantai tidak aktif di sampel) | AC19:AC20, AC34:AC35 | SAMA | idem |
| R-23 | Angka cetak 4 kolom x 2 titik, 5 desimal (1,33659 / 1,33935 / -0,00276 / 0,00053) | SERTIFIKAT!C18:R19 | SAMA | `test_angka_cetak_sama_dengan_sertifikat_excel`; `desimalSertifikat()` = 5 = format `0.00000` |
| R-24 | k tercetak satu angka (2) | SERTIFIKAT!U21 | SAMA | idem |
| R-25 | Tanggal terbit = tanggal kalibrasi + 4 | SERTIFIKAT!B37 (`=S9+4`) | SAMA | `test_tanggal_terbit_tambah_hari_sama_dengan_rumus_sertifikat` |
| R-26 | Kondisi lingkungan suhu (TH-5): rata-rata, indexed value, koreksi, delta, U95 TH, U95 sertifikat, nilai cetak 21,96 | PERHITUNGAN!G14:Q14; SERTIFIKAT!T11, W11 | SAMA | `test_kondisi_lingkungan_th5_suhu_sama_dan_u95_kelembaban_selisih_p21` |
| R-27 | Kondisi lingkungan RH: rata-rata, indexed value, koreksi, delta, nilai cetak 60,41 | PERHITUNGAN!G15:N15; SERTIFIKAT!T12 | SAMA | idem |
| R-28 | Deviasi suhu pada suhu ruang selain 21,0-21,1 C (P-20) | PERHITUNGAN U95%!N11, N26 -> Tab Konversi Temperatur!H24 | SELISIH | `test_selisih_p20_deviasi_suhu_sel_tetap_lawan_suhu_ruang` |
| R-29 | U95 thermohygro kelembaban: per unit TH (Excel 4,9) lawan per titik RH (aplikasi 4,8) (P-21) | PERHITUNGAN!O15, Q15 | SELISIH | idem (R-27); tercetak `± 5,3` lawan `± 5,2` |
| R-30 | Sumber UTemperature: sheet U95% (0,07/0,036) lawan DATABASE (0,72/0,06) di workbook yang sama (P-22) | PERHITUNGAN U95%!V4, AC4 lawan DATABASE!V17, V18 | MENUNGGU | `test_p22_utemperature_mengikuti_sheet_u95_bukan_database`; aplikasi = Excel, tetapi Excel sendiri bertentangan |
| R-31 | Type A tidak nol: sesi dengan sebaran pembacaan | PERHITUNGAN!G41, I41 (= 0 di sampel) | MENUNGGU | tidak ada sesi bersebaran di workbook; butuh workbook/hitungan Excel dengan pembacaan beda |
| R-32 | Jumlah pengulangan selain 5 (Excel mengunci P=5, vi=4) | PERHITUNGAN U95%!Q13, S13 | MENUNGGU | di Excel tidak ada padanan; aplikasi menerima 2-10 |
| R-33 | Budget °Brix | PERHITUNGAN U95%!B37:AC51, B70:AC99 (`#REF!`, `#DIV/0!`) | MENUNGGU | Excel tidak punya budget °Brix yang sah; aplikasi hanya melaporkan CMC |
| R-34 | Baris sertifikat ke-3 (tautan luar rusak) | SERTIFIKAT!C20:R21 | MENUNGGU | `#REF!`; sisa tautan, aplikasi tidak mencetaknya |
| R-35 | Vonis PASS/FAIL dan toleransi (P-29) | tidak ada | MENUNGGU | `punyaToleransi()` true; angka seeder 0,005 hanya demo |
| R-36 | Status masa berlaku standar (VALID/WARNING/EXPIRED) | INPUT DATA!T23:T27, Z25:Z29 (`NOW()`) | MENUNGGU | Excel menilai terhadap hari ini, aplikasi terhadap tanggal kalibrasi; tidak diuji |
| R-37 | Teks Capacity/Graduation dan header/identitas (nama, merk, tanggal, lokasi, IK, teknisi, penandatangan) | SERTIFIKAT!F7:S10, F11:M11 | MENUNGGU | tidak diuji di sini (matriks `matriks-refractometer.md` baris 1-7, 33 menunggu konfirmasi) |
| R-38 | Daftar standar tercetak (3 baris di Excel, 6 di aplikasi) | SERTIFIKAT!B30:U32 | MENUNGGU | tidak diuji di sini |

## 4. Turbidimeter, rincian per butir

Sesi contoh: tiga titik (1, 100, 1000 NTU), lima pengulangan, ketiganya dengan sebaran tidak nol (STDEV 0,0089 / 0,0447 / 0,5477), suhu ruang 23,2 / 23,4 C, kelembaban diketik 2 dan 55 %RH (lihat P-23), thermohygro TH-6. Lantai CMC menang di ketiga titik.

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| T-1 | U95 larutan (0,04 / 3 / 21) dan k = 2 | DATABASE!W13:W15; PERHITUNGAN U95%!P11, P25, P39 | SAMA | `test_konstanta_aplikasi_sama_dengan_workbook` |
| T-2 | CMC (0,041 / 3,1 / 22) | DATABASE!T5:T7 | SAMA | idem |
| T-3 | UTemperature 0,361247837 dan pembagi komponen suhu akar 3 | PERHITUNGAN U95%!AE4, P13 | SAMA | idem (rel 1e-7 untuk decimal(20,8)) |
| T-4 | Resolusi per titik 0,01 / 0,1 / 1 | INPUT DATA!E17:G17 | SAMA | idem, `TurbidimeterProfile::resolusiTitik` |
| T-5 | Nilai standar nominal | PERHITUNGAN!G35, I35, K35 | SAMA | `test_nilai_standar_rata_rata_stdev_dan_koreksi_cocok_excel` |
| T-6 | Rata-rata UUT sesudah adjustment (1,004 / 100,02 / 1000,6) | PERHITUNGAN!G42, I42, K42 | SAMA | idem |
| T-7 | STDEV sampel (tidak nol) | PERHITUNGAN!G44, I44, K44 | SAMA | idem; selisih 0 |
| T-8 | Koreksi cetak Std - UUT dan koreksi lembar UUT - Std | SERTIFIKAT!P22:P24; PERHITUNGAN!G43, I43, K43 | SAMA | idem |
| T-9 | Komponen 1 sertifikat kalibrator: ui = U/2, ci, vi 200 | PERHITUNGAN U95%!T11, W11, R11 (T25.., T39..) | SAMA | `test_empat_komponen_budget_cocok_excel` |
| T-10 | Komponen 2 daya baca per titik | T12, W12, R12 (T26.., T40..) | SAMA | idem |
| T-11 | Komponen 3 temperature: ui = UTemp/akar 3, ci = (UTemp/400) x titik (0,000903 / 0,0903 / 0,903), vi 200 | T13, W13, R13 (T27, W27 ...) | SAMA | idem; selisih <= 2,8e-15 |
| T-12 | Komponen 4 pengulangan Type A = STDEV/akar 5, vi 4 (tidak nol) | T14, W14, R14 (T28.., T42..) | SAMA | idem |
| T-13 | uc (0,0206002 / 1,500529 / 10,508511) | AB16, AB30, AB44 | SAMA | `test_uc_veff_k_u_dan_lantai_cmc_cocok_excel`; selisih 0 |
| T-14 | v_eff (208,44 / 200,28 / 200,65) | AB17, AB31, AB45 | SAMA | idem; selisih <= 5,7e-14 |
| T-15 | k (1,971435 / 1,971896 / 1,971896) | AB18, AB32, AB46 | SAMA | idem; selisih <= 1,1e-12 |
| T-16 | U sebelum lantai (0,040612 / 2,958888 / 20,721694) | AB19, AB33, AB47 | SAMA | idem; selisih <= 1,0e-11 |
| T-17 | Lantai CMC dan U cetak (0,041 / 3,1 / 22) | AB20:AB21, AB34:AB35, AB48:AB49 | SAMA | idem; U hitung < CMC di ketiganya |
| T-18 | Angka cetak per titik: Standard Value `0`; UUT, Correction, U95 `0.00` / `0.0` / `0` | SERTIFIKAT!C22:S24 | SAMA | `test_angka_cetak_sama_dengan_sertifikat_excel`; desimal `TurbidimeterProfile::desimalTitik` 2/1/0 |
| T-19 | k tercetak `2,0` | SERTIFIKAT!U27 | SAMA | idem |
| T-20 | Kondisi lingkungan suhu (TH-6): nilai cetak 23,07, U95 1,7117 | PERHITUNGAN!G14:Q14; SERTIFIKAT!U11, X11 | SAMA | `test_kondisi_lingkungan_th6_suhu_sama_dan_selisih_p23_rh_awal` |
| T-21 | Kondisi lingkungan RH dengan RH yang Excel PAKAI (awal = akhir = 55) | PERHITUNGAN!E15:Q15; SERTIFIKAT!U12, X12 | SAMA | idem (nilai cetak 51,83, U95 4,8) |
| T-22 | RH awal diketik 2: Excel mengabaikannya (P-23) | PERHITUNGAN!E15 (`='INPUT DATA'!$F$23`) | SELISIH | idem |
| T-23 | Tanggal terbit: +1 (SERTIFIKAT) lawan +4 (INPUT DATA) lawan tanggal sistem (aplikasi) (P-24) | SERTIFIKAT!B40; INPUT DATA!D55 | MENUNGGU | `test_p24_tanggal_terbit_ditahan_dan_workbook_bertentangan` |
| T-24 | Resolusi per titik: Excel memakai yang diketik, aplikasi yang lebih besar dengan master (P-25) | PERHITUNGAN U95%!M12, M26, M40 (`=L9*0.5`) | SELISIH | `test_p25_resolusi_aplikasi_lebih_besar_dari_ketikan_lebih_halus` |
| T-25 | Tanda minus pada koreksi yang membulat ke nol (`-0,00`, `-0,0`) (P-26) | SERTIFIKAT!P22, P23 | MENUNGGU | emulasi Excel = aplikasi; PDF master mencetak tanpa minus; jumlah baris dikunci (2) |
| T-26 | Titik ke-4/5 kosong tetap tercetak (0 / `#REF!` / 0,3714) | SERTIFIKAT!C25:S26 | MENUNGGU | sisa kolom; aplikasi hanya tiga titik |
| T-27 | Titik 15 dan 750 NTU (ada di kertas 0530, tidak ada di workbook) | tidak ada | MENUNGGU | butuh standar dan workbook |
| T-28 | Vonis PASS/FAIL dan toleransi (P-29) | tidak ada | MENUNGGU | `test_p29_...`; angka seeder 24 NTU hanya demo |
| T-29 | Status masa berlaku standar (`NOW()`) dan baris standar 'RTD Sensor/SH1', 'Victor 14+' | INPUT DATA!S24:S29; DATABASE!Z13:AA19 | MENUNGGU | tidak diuji di sini |
| T-30 | Jumlah pengulangan selain 5 (terkunci P14/R14) | PERHITUNGAN U95%!P14, R14 | MENUNGGU | tidak ada padanan Excel |
| T-31 | Varian workbook lain (`.xlsm1`, 2024-11-20): tabel koreksi thermohygro berbeda (I15 0,48 lawan -3,17; Q15 4,5 lawan 4,8) | PERHITUNGAN!I15, Q15 | MENUNGGU | tidak diadu; `VERIFIKASI-24NOV.md` T1-2; TM menentukan varian mana yang berlaku |
| T-32 | Header/identitas, Capacity/Graduation bertingkat | SERTIFIKAT!F7:K15 | MENUNGGU | tidak diuji di sini |

## 5. Chlorine Meter, rincian per butir

Sesi contoh: dua titik, 1,74 mg/L (Free Chlorine; pembacaan 1,76 x4 dan 1,75, STDEV 0,00447) dan 1,83 mg/L (Total Chlorine; enam pembacaan seragam 1,86, STDEV 0), suhu larutan 25,7-25,8 C (dicatat, tidak mengoreksi nilai standar), suhu ruang 24,1 / 23,5 C, RH 56 / 55, thermohygro TH-4. Cache workbook versi 18 Nov 2026.

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| C-1 | U95 larutan (0,09 / 0,06), k = 2 | DATABASE!W13, W14; PERHITUNGAN U95%!P22, P37 | SAMA | `test_konstanta_aplikasi_sama_dengan_workbook` |
| C-2 | CMC (0,091 / 0,08) | DATABASE!T5, T6 | SAMA | idem |
| C-3 | UTemperature 0,361247837 | PERHITUNGAN U95%!AE4 | SAMA | idem (rel 1e-7 untuk decimal(20,8)) |
| C-4 | Resolusi 0,01 | INPUT DATA!E16 | SAMA | idem |
| C-5 | Uc pengenceran (L) dan ci pengenceran (titik 1: `=AE11/100*C20`; titik 2: `=W25` salinan) | PERHITUNGAN U95%!M25, W25, W40 | SAMA | idem; satu konstanta aplikasi untuk dua titik |
| C-6 | Nilai standar nominal (suhu larutan tidak mengoreksi) | PERHITUNGAN!G35, I35 | SAMA | `test_nilai_standar_rata_rata_stdev_dan_koreksi_cocok_excel` |
| C-7 | Rata-rata UUT (1,758 / 1,86) | PERHITUNGAN!G42, I42 | SAMA | idem |
| C-8 | STDEV sampel (0,004472 / 0) | PERHITUNGAN!G44, I44 | SAMA | idem |
| C-9 | Koreksi cetak Std - UUT (-0,018 / -0,03) | SERTIFIKAT!P18, P19 | SAMA | idem |
| C-10 | Komponen 1 sertifikat larutan (ui = U/2, vi 200), kedua titik | PERHITUNGAN U95%!T22, T37 | SAMA | `test_komponen_budget_cocok_excel_kecuali_type_a_titik_183` |
| C-11 | Komponen 2 daya baca, kedua titik | T23, T38 | SAMA | idem |
| C-12 | Komponen 3 temperature: ui = UTemp/2 (normal), ci = (UTemp/400) x titik, vi 200 | T24, W24, T39, W39 | SAMA | idem |
| C-13 | Komponen 4 faktor pengenceran: ui = Uc/2, ci, vi 200 | T25, W25, T40, W40 | SAMA | idem |
| C-14 | Komponen 5 pengulangan titik 1,74 (ui 0,002, vi 4) | T26, W26, R26 | SAMA | idem |
| C-15 | uc titik 1,74 (0,0451377) | AB28 | SAMA | `test_uc_veff_k_u_dan_lantai_cmc_cocok_excel_untuk_titik_174` |
| C-16 | v_eff titik 1,74 (202,42) | AB29 | SAMA | idem; selisih 8,5e-14 |
| C-17 | k titik 1,74 (1,971777) | AB30 | SAMA | idem; selisih 9,6e-13 |
| C-18 | U sebelum lantai titik 1,74 (0,0890015) | AB31 | SAMA | idem; selisih 4,4e-14 |
| C-19 | Lantai CMC dan U cetak titik 1,74 (0,091) | AB32:AB33 | SAMA | idem |
| C-20 | ui pengulangan titik 1,83: Excel `T41` = angka tempelan 0,0244949 (bukan rumus), aplikasi 0 (P-27) | PERHITUNGAN U95%!T41 | SELISIH | `test_selisih_p27_type_a_titik_183_tempelan_lawan_pembacaan` |
| C-21 | uc, v_eff, k, U titik 1,83 yang diturunkannya (Excel 0,03884 / 24,19 / 2,0639 / 0,08016; aplikasi 0,03014 / 203,76 / 1,9717 / 0,05943) (P-27) | AB43:AB46, AB48 | SELISIH | idem; tercetak sama (0,08) karena lantai CMC 0,08 |
| C-22 | Angka cetak 4 kolom x 2 titik (`1,74 / 1,76 / -0,02 / 0,09`; `1,83 / 1,86 / -0,03 / 0,08`) dan Remark Free/Total Chlorine | SERTIFIKAT!C18:S19, W18:W19 | SAMA | `test_angka_cetak_dan_remark_sama_dengan_sertifikat_excel` |
| C-23 | k tercetak `2` (teks) | SERTIFIKAT!U21 | SAMA | idem; nilai mentah sel (1,9600) beda dari k titik 1 (1,9718), lihat C-25 |
| C-24 | Tanggal terbit = tanggal kalibrasi + 2 | SERTIFIKAT!B38 (`=T9+2`) | SAMA | `test_tanggal_terbit_tambah_hari_sama_dengan_rumus_sertifikat` |
| C-25 | Baris sertifikat ke-3 (0 mg/L, nilai 0 / 0 / 0 / 0,0057) dan k mentah dari baris kosong (P-28) | SERTIFIKAT!C20:S20, U21 | MENUNGGU | `test_p28_baris_ketiga_kosong_tidak_punya_padanan`; aplikasi hanya dua titik |
| C-26 | Kondisi lingkungan suhu (TH-4): nilai cetak 23,21, U95 1,8028 | PERHITUNGAN!G14:O14; SERTIFIKAT!U11, X11 | SAMA | `test_kondisi_lingkungan_th4_cocok_excel` |
| C-27 | Kondisi lingkungan RH: nilai cetak 53, U95 4,9031 (U95 TH per titik RH, sama dengan aplikasi) | PERHITUNGAN!G15:O15; SERTIFIKAT!U12, X12 | SAMA | idem |
| C-28 | Tanda koreksi di lembar PERHITUNGAN titik 2 terbalik (`I43 = I35-I42`, titik 1 `=G42-G35`) (P-28) | PERHITUNGAN!G43, I43 | MENUNGGU | sisi Excel terbukti (rumus dan nilai); sisi lembar admin aplikasi (`PerhitunganBuilder`) tidak dijalankan di sini; tidak memengaruhi sertifikat |
| C-29 | Standar 1,74 tercetak dua kali di daftar "Standard used" (P-28) | SERTIFIKAT!B30, B31 | MENUNGGU | tidak diuji di sini; aplikasi mencetak tiap standar sekali |
| C-30 | Vonis PASS/FAIL dan toleransi (P-29) | tidak ada | MENUNGGU | `test_p29_...`; angka seeder 0,15 hanya demo |
| C-31 | Jumlah pengulangan selain 5 (terkunci P26/R26) | PERHITUNGAN U95%!P26, R26 | MENUNGGU | tidak ada padanan Excel |
| C-32 | Status masa berlaku standar (`NOW()`); termometer standar 2026-08-12 sudah lewat di cache | DATABASE!Z13:AA19 | MENUNGGU | tidak diuji; cek ke lab apakah sudah dikalibrasi ulang |

## 6. Konflik bernomor untuk Manajer Teknis (tidak diperbaiki, tidak dipilih)

| # | Alat | Butir | Fakta terukur | Dampak pada angka cetak | Status |
|---|---|---|---|---|---|
| P-20 | Refractometer | Deviasi suhu ("Ketidakpastian Pengaruh Perbedaan Temperature") | Excel (`PERHITUNGAN U95%!N11/N26`) menunjuk SEL TETAP `Tab Konversi Temperatur!H24` (baris 21,0 C = -0,00045), suhu ruang sesi tidak dibaca. Aplikasi (bawaan `suhu_ruang`) mencari baris menurut suhu ruang rata-rata, diturunkan ke kelipatan 0,1. Sama hanya bila suhu ruang rata-rata di 21,0-21,1 C (sampel 21,05). Di 23,0 C aplikasi -0,00135 dan Excel tetap -0,00045: uc 0,000780 (aplikasi) lawan 0,000263 (Excel), U cetak 0,001567 lawan 0,000527. Penyebab terbukti tunggal (rumus sel dengan baris 23,0 = angka aplikasi) | U95 cetak di sesi non-21 C: `0,00157` (aplikasi) lawan `0,00053` (Excel), sekitar 3x | **SELISIH**; PR #298 sudah menyediakan pilihan `baris_tetap_21c` (TIDAK dipakai di sini); TM memilih (butir `refractometer#24`) |
| P-21 | Refractometer | U95 thermohygro kelembaban | Excel `PERHITUNGAN!O15` = `VLOOKUP($E$16, Termohygro_Lingkungan, 9, 0)` per UNIT TH (TH-5: 4,9) -> Q15 5,2924. Aplikasi per TITIK RH (titik terdekat: 4,8) -> 5,2, seperti master Turbidimeter dan Chlorine (`VLOOKUP($H$15, ...)`) | Env. Condition `%RH 60 % ± 5,2 %` (aplikasi) lawan `± 5,3` (Excel) | **SELISIH** sudah dikenal (`SertifikatCocokMasterTest`); mengikuti Excel akan menggeser dua alat lain ke 4,9 |
| P-22 | Refractometer | UTemperature: dua nilai untuk satu termometer fisik | `PERHITUNGAN U95%!V4/AC4` = 0,07/0,036 (UTemp 0,03936; dipakai Excel dan aplikasi) lawan `DATABASE!V17/V18` = 0,72/0,06 di workbook yang SAMA (UTemp 0,36125, ~9x lebih besar; dipakai Turbidimeter dan Chlorine). Revisi 11 dan 13 di FORM VALIDASI menyebut pembaruan ke 0,06 yang kelihatannya tidak sampai ke sel U95% | tidak berubah di sampel (komponen ini 1,97e-6 dari uc 2,6e-4); naik bila diganti | **MENUNGGU** (aplikasi = Excel, tetapi Excel bertentangan dengan dirinya sendiri) |
| P-23 | Turbidimeter | RH awal | `PERHITUNGAN!E15 = 'INPUT DATA'!$F$23` dan `F15 = 'INPUT DATA'!$F$23`: RH AKHIR dipakai untuk awal dan akhir. Sampel mengetik RH awal 2 (INPUT DATA!E23, tampak salah ketik) dan 55. Excel: rata-rata 55, delta 0, U95 4,8. Aplikasi memakai kedua isian: rata-rata 28,5, delta 53, U95 sertifikat 53,23 | bila awal ≠ akhir; di sampel tercetak Excel `51,83 ± 4,8`; masukan yang sama di aplikasi memberi `± 53,2` | **SELISIH** (seeder sengaja meniru 55/55); TM memutuskan apakah Excel salah rujuk |
| P-24 | Turbidimeter | Tanggal terbit | Workbook bertentangan dengan dirinya: `SERTIFIKAT!B40 = T9+1` (tercetak) lawan `INPUT DATA!D55 = O16+4`. Aplikasi menahan (`FormatMasterSertifikat` `tambah_hari` null: tanggal terbit sistem) | tanggal terbit | **MENUNGGU** (ditahan koordinator, menunggu pemilik) |
| P-25 | Turbidimeter | Resolusi per titik | Excel memakai angka yang DIKETIK (`INPUT DATA!E17:G17` -> `L9/L23/L37` -> `M12 = L9*0.5`). Aplikasi (`resolusiDipakai`) memakai yang LEBIH BESAR antara master dan ketikan ("aturan emas": perbaikan tidak boleh diam-diam mengecilkan U). Sama untuk sampel; bila teknisi menulis resolusi 10x lebih halus, ui resolusi Excel 10x lebih kecil | U95 cetak hanya bila komponen resolusi berarti; di sampel lantai CMC menang | **SELISIH** sengaja; TM setuju atau ikut Excel (pertanyaan 6 matriks) |
| P-26 | Turbidimeter | Tanda minus koreksi yang membulat ke nol | Koreksi -0,004 (1 NTU) dan -0,02 (100 NTU) membulat ke `-0,00` / `-0,0`. Emulasi tampilan Excel dan aplikasi (`tandaNolDicetak()` true) sama-sama bertanda minus; kertas lama 0189-CAL-624 juga; PDF master mencetak `0.00` / `0.0` tanpa minus. Tidak ada render Excel asli di fixture | teks cetak 2 baris koreksi | **MENUNGGU** bukti render Excel; jumlah baris dikunci (2) |
| P-27 | Chlorine Meter | Type A titik 1,83 | `PERHITUNGAN U95%!T41` adalah angka TEMPELAN 0,02449489742783176 (bukan rumus `=M41/SQRT(P41)`); STDEV titik ini 0 (pembacaan seragam), jadi rumus akan memberi 0. Excel: uc 0,038838, v_eff 24,19, k 2,0639, U 0,0801585 (> CMC 0,08, jadi U sertifikat 0,0801585). Aplikasi (Type A dari pembacaan = 0): uc 0,030140, v_eff 203,76, k 1,9717, U 0,0594277 -> lantai CMC 0,08. Penyebab terbukti tunggal (rumus sel dengan ui pengulangan := 0 = angka aplikasi) | tidak ada: `0,08` di kedua sisi (mentah 0,0801585 lawan 0,08) | **SELISIH** tercatat (`ChlorineSeeder` mencatat "sel ketinggalan"); TM memastikan tempelan itu salah |
| P-28 | Chlorine Meter | Sisa templat Excel | (a) baris sertifikat ke-3 dari `K32` kosong: `SERTIFIKAT!C20:S20` = 0 / 0 / 0 / 0,0057 dan `U21` (k) = 1,9600 diambil dari baris kosong itu; (b) `PERHITUNGAN!I43 = I35-I42` (titik 2) lawan `G43 = G42-G35` (titik 1): tanda koreksi lembar terbalik; (c) standar 1,74 tercetak dua kali (`SERTIFIKAT!B30`, `B31 = DATABASE!S13`); (d) `PERHITUNGAN!H39` salah rujuk (tidak memengaruhi angka) | k tercetak `2` sama; baris ke-3 tidak tercetak di aplikasi | **MENUNGGU** (apakah baris ke-3 masuk area cetak master) |
| P-29 | ketiga alat | Vonis PASS/FAIL dan toleransi | Ketiga workbook tidak punya satu pun sel PASS/FAIL atau toleransi. Aplikasi (`punyaToleransi()` true) menolak menghitung titik bila `equipments.toleransi` kosong; angka seeder (0,005 n20D / 24 NTU / 0,15 mg/L) hanya demo | vonis di sertifikat opsional | **MENUNGGU** TM: divonis atau tidak; bila tidak, `punyaToleransi()` perlu `false` |

## 7. Yang BELUM diuji / belum terbukti

- Excel tidak dijalankan ulang: acuan = nilai-cache workbook (dibaca dengan sandi) dan hitung ulang Python rumus sel `PERHITUNGAN U95%` dengan TINV = t(0,975; floor(v_eff)). Skenario "aplikasi" (P-20, P-27) = rumus sel dengan satu masukan diganti, bukan nilai-cache.
- Sebaran pembacaan (Type A) nol di Refractometer dan titik 1,83 Chlorine; hanya Turbidimeter dan titik 1,74 Chlorine menguji Type A tidak nol.
- Tahap "sebelum adjustment" (as-found; tidak masuk sertifikat), `PerhitunganBuilder` (lembar admin) dan `HitungUlangSesi` dari baris `raw_measurements` tidak dipanggil; jalur HTTP simpan/preview dan render HTML/PDF/QR tidak dijalankan.
- Pilihan non-bawaan parameter keputusan metode PR #298 (mis. `baris_tetap_21c`), °Brix (budget Excel rusak), jumlah pengulangan selain 5, resolusi ketikan yang lebih besar dari master, status standar VALID/WARNING/EXPIRED, header/identitas, dan teks Capacity/Graduation.
- VBA: Refractometer dan Chlorine memuat VBA tetapi kosong (0 baris kode yang dapat dieksekusi) menurut `INVENTARIS-WORKBOOK-24NOV.md`; tidak diperiksa ulang di sini. Turbidimeter tanpa VBA.
- Gate MySQL penuh wajib sebelum modul disebut selesai; di sini hanya SQLite terarah. Pembulatan `decimal(20,8)` pada `u_temperature`, CMC, dan U larutan disimulasikan (lihat bagian 2), bukan dijalankan di MySQL.

## 8. Menghasilkan ulang fixture

```
py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-analitik-batch2.py "<folder Downloads>"   # butuh SIDIK_MASTER_SANDI di lingkungan
```

Skrip berhenti bila rekonstruksi rantai menyimpang > 1e-11 dari cache. Hasil terakhir: Refractometer 1,0e-15, Turbidimeter 1,2e-12, Chlorine Meter 1,1e-12.
