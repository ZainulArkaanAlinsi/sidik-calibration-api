# Paritas aplikasi lawan Excel: batch alat ke-7 (Hydrometer, Thermometer Glass)

Dibuat 26 Nov 2026 (cabang `feat/paritas-batch7`, dari `origin/main` 6361f281). Tidak ada kode aplikasi (`app/`) yang diubah. Dokumen ini hanya melaporkan; selisih = KONFLIK untuk Manajer Teknis (TM), tidak diperbaiki dan tidak dipilih agar tes lulus. Pola dan istilah sama dengan `docs/paritas-analitik-batch2.md`. Nomor konflik batch ini `B7-n` (batch 2 memakai P-10..P-29). Alat ditambahkan satu per satu: bagian 3 (Hydrometer) dan 4 (Thermometer Glass) memuat rincian per butir; bagian 5 (konflik), 6 (belum diuji), dan 7 (regenerasi) mencakup semua alat.

Label: **SAMA** = aplikasi = Excel dalam toleransi bertulis, dibuktikan tes yang dijalankan. **SELISIH** = beda yang terukur (konflik bernomor B7-n; tes mengunci perilaku sekarang dengan penanda `SELISIH B7-n` dan grup `menunggu-keputusan-tm`). **MENUNGGU** = butuh keputusan orang atau bukti yang belum ada, atau belum diuji di sini (alasannya ditulis). Status "TERBUKTI" per alat BUKAN diputuskan di sini; ini bukti yang tersedia untuk koordinator dan TM.

## 1. Ringkasan

| Alat | Hasil | SAMA | SELISIH | MENUNGGU |
|---|---|---|---|---|
| Hydrometer ringan 0,60-0,65 g/mL dan berat 1,8-2,0 g/mL (3 skala tiap varian) | rantai penuh SAMA di kedua varian (praolah, Cuckow, densitas, simpangan baku, koreksi, 11 komponen budget, uc, v_eff, k, U, angka cetak); 3 konflik (B7-1 CMC, B7-2 penyebut tegangan permukaan, B7-3 rujukan sel stem) | 24 | 4 | 7 |
| Thermometer Glass 30-100 °C (5 titik, kalibrator Yokogawa, probe PRT PT100 no. 17, oilbath dua) | rantai per titik, besaran sesi, 11 komponen budget (9 dari 11 ui), agregator GUM dengan ui master, jalur profil, pita CMC, dan angka cetak standar/koreksi/U95/k SAMA; 4 konflik (B7-5 pembagi keterulangan standar, B7-6 algoritma k, B7-7 digit tabel referensi, B7-8 format cetak UUT) | 17 | 7 | 6 |

Satu-satunya angka CETAK Hydrometer yang berbeda dari Excel: U95 Hydrometer ringan, `0.00051` (aplikasi) lawan `0.00070` (sertifikat master), B7-1. B7-2 dan B7-3 mengubah nilai antara (ci) tetapi tidak mengubah angka cetak sampel: B7-3 tertutup lantai CMC di ringan dan tidak berdampak di berat; B7-2 berdampak nol pada U karena ui tegangan permukaan berat bernilai 0. Rincian di bagian 3.

Thermometer Glass: angka CETAK berbeda dari Excel hanya di kolom UUT tiga titik pertama (`30.0`, `51.0`, `61.0` lawan `30`, `51`, `61`, B7-8). U95 (`1.1`) dan k (`2`) tercetak sama walau nilai antaranya berbeda (U 1,1262 lawan 1,1174 karena B7-5; k karena B7-5 dan B7-6); jarak ke batas pembulatan `1.15` masih 0,024. Rincian di bagian 4.

## 2. Cara membaca, menjalankan, dan menghasilkan ulang

### Hydrometer

- Tes: `tests/Feature/Paritas/ParitasHydrometerTest.php` (21 kasus, 980 assertion; data provider `ringan` dan `berat`). Jalankan: `php vendor/phpunit/phpunit/phpunit --configuration=phpunit.xml tests/Feature/Paritas/ParitasHydrometerTest.php` (SQLite; `php artisan test` menggantung di mesin ini). Gate MySQL penuh BELUM dijalankan di sini; CI yang menjalankan. Perangkat bersama: trait `tests/Concerns/MenegakkanParitas.php` (tidak diubah).
- Fixture: `tests/Fixtures/paritas/hydrometer.json`. Tiap nilai Excel membawa alamat sel (`SHEET!SEL`); pesan galat tes menyebut sel itu. Dibangun `docs/skrip/gen-fixture-paritas-batch7.py` (mandiri; memuat bagian alat berikutnya di batch ini). Sandi workbook hanya dari env `SIDIK_MASTER_SANDI`, dibuka di memori; fixture tanpa nama/alamat pelanggan, nomor sertifikat, nomor order, atau teknisi (dipindai); sandi tidak muncul di berkas mana pun.
- Workbook (folder `Downloads`, dibuka dengan sandi dari pemilik): `Hydrometer 0.600 - 0.650 gmL.xlsx` (ringan, sha256 12 awal `3dac055eb0d6`) dan `Hydrometer 1.800 - 2.000 gmL.xlsx` (berat, `1e9427d310e7`). Angka fixture = nilai-cache sel. Rekonstruksi rantai `NILAI U95%` dari sel komponen mereproduksi cache dengan selisih relatif maksimum 1,1e-16 (ringan) dan 4,1e-16 (berat) untuk uc, v_eff, ui; `k`/U memakai TINV Excel yang iteratif lawan `scipy.stats.t.ppf` (selisih 1,3e-11 ringan, 3,7e-11 berat), jadi rekonstruksi `k` dicatat pada 1e-9 dan batas generator untuk aritmetika 1e-11.
- Titik panggil (tanpa HTTP): `HydrometerCalculator::hitungSesi()` (rantai Cuckow + budget 11 komponen) dan `HydrometerProfile::hitungPerGrup()` (jalur simpan dan hitung ulang, plus lantai CMC dari pita `CalibrationCapability`). Pita CMC dibaca dari `database/data/kemampuan-kalibrasi.json` (sumber seeder produksi), bukan diketik ulang. Pembacaan titik ukur, massa di cairan, dan suhu tiap ulangan diambil dari sel masukan master (`PERHITUNGAN`), bukan dari data contoh seeder.
- Angka cetak diuji lewat jalur render sungguhan: format sel dari peta `format_sertifikat` paket Hydrometer (`FormatSertifikatAcuan::untuk`, yang dibekukan ke snapshot), diterapkan `TataLetakSertifikat::formatAngka` / `teksK`, lalu titik desimal bentuk master. Tes juga menegaskan format peta = format sel workbook (mis. `0.000` nominal, `0.00000` U ringan, `0.0000` U berat, `0` k). Bukan render PDF penuh dan bukan render Excel asli (teks Excel = emulasi pembulatan 15 angka penting lalu desimal format, setengah menjauhi nol).
- Dugaan awal "B7-4" (format cetak U95 berat `0.0000` lawan `desimalU95()` = 5) TERBUKTI KELIRU lewat tes pertama yang jalan: cetak memakai peta `format_sertifikat`, bukan `desimalU95()`, dan petanya sama dengan sel master (`0.0009`). Nomornya tidak dipakai; tes memuat penjaganya (`test_cetak_u95_berat_sama_dengan_sel_sertifikat_lewat_peta_format`).

### Thermometer Glass

- Tes: `tests/Feature/Paritas/ParitasThermometerGlassTest.php` (20 kasus, 450 assertion; 9 di antaranya berlabel `SELISIH B7-n` dan berada di grup `menunggu-keputusan-tm`). Jalankan: `php vendor/phpunit/phpunit/phpunit --configuration=phpunit.xml tests/Feature/Paritas/ParitasThermometerGlassTest.php` (SQLite). Gate MySQL penuh BELUM dijalankan di sini; CI yang menjalankan.
- Fixture: `tests/Fixtures/paritas/thermometer-glass.json`, dibangun `docs/skrip/gen-fixture-paritas-batch7.py` yang sama. Tiap nilai Excel membawa alamat sel (`PERHITUNGAN FC!J23`, `PERHITUNGAN U95%!AC32`, `SERTIFIKAT!L35`, dst.). Blok `skenario` di fixture BUKAN sel Excel: nilai dihitung ulang, tiap entri berlabel `sumber` dan hanya dipakai untuk membuktikan penyebab selisih yang dikunci. Fixture dipindai: tanpa nama/alamat pelanggan, nomor sertifikat, nomor order, atau nama teknisi.
- Workbook (`EXEL CALIBRATION SIDIK\`, dibuka dengan sandi dari pemilik): `Master Olah Data_Suhu_Thermometer Glass.xlsm` (sha256 12 awal `b9614850813b`). Rekonstruksi rantai budget dari sel komponen mereproduksi cache dengan selisih relatif maksimum 2,3e-16; `k` master adalah polinomial `AC34` (aritmetika murni, rekonstruksi selisih 0,0), bukan TINV.
- Sesi contoh: lima titik 30 / 50 / 60 / 80 / 100 °C, kalibrator Yokogawa, sensor standar PRT PT100 (di aplikasi `RTD`), probe no. 17, oilbath `dua`, resolusi UUT 1,0 °C, lima pembacaan per titik, titik es 0,0 / 0,0 / 0,0. Master menghitung SATU budget per sesi (STDEV standar dan UUT maksimum, index tabel maksimum, rentang titik es), jadi komponen budget dibandingkan sekali per sesi, bukan per titik.
- Titik panggil (tanpa HTTP): `ThermometerGlassCalculator::hitungSesi()` (rantai per titik, 11 komponen budget, agregasi) dan `ThermometerGlassProfile::hitungPerGrup()` (jalur simpan dan hitung ulang, plus lantai CMC dari pita `CalibrationCapability` "Termometer Gelas", dibaca dari `database/data/kemampuan-kalibrasi.json`). Agregator diadu juga langsung: `GumCalculator::agregasiBudget()` diberi ui master.
- Angka cetak diuji lewat `TataLetakSertifikat::teksSel` dan `teksK` dengan desimal dari profil. Paket suhu 3 alat TIDAK punya peta `format_sertifikat`, jadi kolom mengikuti `desimalSertifikat()` (1), `desimalU95()` (1), `desimalFaktorCakupan()` (0), `tandaNolDicetak()` (true). Format sel workbook dibaca dari fixture (`0.0` standar, koreksi, dan U95; `0` k; UUT `0` untuk tiga titik pertama dan `0.0` untuk dua titik terakhir). Bukan render PDF penuh dan bukan render Excel asli.

### Toleransi dan asalnya (bukan dipilih agar lulus)

`MenegakkanParitas`: relatif 1e-11, lantai mutlak 1e-12; `k` mutlak 1e-9 dan relatif 1e-9; teks cetak SAMA PERSIS. Hydrometer memakai 1e-9 untuk `k` karena TINV Excel iteratif lawan `scipy`. Thermometer Glass memakai 1e-9 hanya saat aplikasi diadu dengan skenario `scipy` (bukan sel Excel), karena skenario di fixture masih membawa ui drift sensor presisi penuh (B7-7; selisih sisa relatif sekitar 1e-10, di bawah 1e-9 tetapi di atas 1e-11); adu aplikasi lawan rantai master yang sudah memuat dua penyebab selisih memakai batas baku 1e-11. Tidak ada toleransi yang dilebarkan untuk butir SELISIH; butir itu dikunci pada selisihnya (lihat bagian 5).

Catatan batas relatif (Thermometer Glass): `standar_terkoreksi` (`PERHITUNGAN FC!S23:S27`) LULUS di batas baku hanya karena batas relatif 1e-11 dikali besar nilai (30-100 °C, batas sekitar 3e-10 sampai 1e-9 mutlak) menutup selisih koreksi probe B7-7 (sampai 2,3e-10 mutlak). Nilai antara itu secara mutlak tidak persis sama dengan master; dicatat di G-8 dan SG-4, bukan disembunyikan. Sebaliknya `SERTIFIKAT!L21:L25` (koreksi, besar sekitar 0,3-1,1 °C, batas baku sekitar 3e-12 sampai 1e-11) menembus batas baku dan karena itu masuk SG-5.

## 3. Hydrometer, rincian per butir

Sesi contoh workbook: dua master. Ringan: skala 0,610 / 0,625 / 0,650 g/mL dengan beban tambahan (sinker, `PERHITUNGAN!E24` = 54,0052). Berat: skala 1,800 / 1,900 / 2,000 g/mL tanpa beban tambahan. Tiga ulangan per skala. Vonis PASS/FAIL tidak diuji di sini (tidak ada butir vonis di fixture).

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| H-1 | Praolah lingkungan: suhu ruang, rho udara, f_press, f_temp, m_air, rho air titik 1, rho air maks | `PERHITUNGAN` (blok praolah, sel di fixture `praolah`) | SAMA | `test_praolah_sama_dengan_sel_master` (kedua varian) |
| H-2 | Praolah geometri dan tegangan: pid_yx, pid_yl, tegangan permukaan hydrometer, diameter stem rata-rata, st maks, st min | idem | SAMA | idem |
| H-3 | Per skala: suhu rata-rata, tegangan permukaan titik, densitas cairan acuan | `PERHITUNGAN` (sel di fixture `titik[]`) | SAMA | `test_densitas_koreksi_dan_simpangan_baku_sama_dengan_sel_master` |
| H-4 | Massa di cairan terkoreksi (rata-rata ulangan) | `PERHITUNGAN!G44:I44` (rata-rata) | SAMA | idem; per ulangan tidak diekspos aplikasi (M-4) |
| H-5 | Densitas tiap ulangan (3 ulangan, 3 skala, 2 varian) | `PERHITUNGAN` (sel di fixture `densitas_per_ulangan`) | SAMA | idem |
| H-6 | Densitas rata-rata = Actual Value | `PERHITUNGAN` (fixture `densitas_rata`) | SAMA | idem |
| H-7 | Simpangan baku sampel | `PERHITUNGAN` (fixture `simpangan_baku`) | SAMA | idem |
| H-8 | Koreksi = Nominal - Actual | `SERTIFIKAT!O17:O19` | SAMA | idem |
| H-9 | ui tiap 11 komponen budget (66 pasangan komponen x skala) | `NILAI U95%` kolom J (blok skala 1-3) | SAMA | `test_sebelas_komponen_budget_sama_dengan_master_kecuali_selisih_terkunci`; urutan dan nama 11 komponen sama |
| H-10 | vi tiap 11 komponen (66 pasangan) | `NILAI U95%` kolom vi | SAMA | idem |
| H-11 | ci tiap 11 komponen: 62 dari 66 pasangan | `NILAI U95%` kolom ci | SAMA | idem; 4 pasangan dikunci di S-2 dan S-3 |
| H-12 | uc (5 dari 6 skala; ringan skala 3 di S-4) | `NILAI U95%` (fixture `agregat.uc`) | SAMA | `test_uc_veff_k_dan_u_sama_dengan_master_kecuali_selisih_terkunci` |
| H-13 | v_eff (5 dari 6) | idem | SAMA | idem |
| H-14 | k = TINV(0,05; floor v_eff) (5 dari 6) | idem | SAMA | idem; k mutlak 1e-9 (TINV iteratif) |
| H-15 | U hitung = k x uc, tanpa lantai (5 dari 6) | idem | SAMA | idem |
| H-16 | Cetak Nominal Value (`0.610`, `1.800`, ...; nol di belakang dipertahankan) | `SERTIFIKAT!E17:E19` | SAMA | `test_cetak_nominal_aktual_koreksi_dan_k_sama_dengan_teks_sertifikat`; format `0.000` dari peta |
| H-17 | Cetak Actual Value | `SERTIFIKAT!J17:J19` | SAMA | idem |
| H-18 | Cetak Correction | `SERTIFIKAT!O17:O19` | SAMA | idem; `tandaNolDicetak()` |
| H-19 | Cetak k = `2` (nilai 1,98) | `SERTIFIKAT!U23` | SAMA | idem; format `0` |
| H-20 | Cetak U95 berat (`0.0009` di tiga skala; format `0.0000`) | `SERTIFIKAT!S17:S19` | SAMA | `test_cetak_u95_berat_sama_dengan_sel_sertifikat_lewat_peta_format` |
| H-21 | U95 berat lewat jalur profil = U hitung, tanpa lantai (titik 1,8-2,0 di luar pita akreditasi 0,6-1,0 dan 1,1-1,7) | `SERTIFIKAT!S17:S19` | SAMA | `test_u95_berat_sama_dengan_sel_sertifikat_karena_tanpa_lantai_cmc`; master mengetik 0,0007 di bawah U hitung 0,00087-0,00090 |
| H-22 | Jalur profil (`hitungPerGrup`): rata-rata dan koreksi = sel sertifikat; k dan uc = kalkulator persis dan = Excel (kecuali ringan skala 3, S-4) | `NILAI U95%` | SAMA | `test_jalur_profil_menghasilkan_aktual_koreksi_dan_budget_yang_sama_dengan_kalkulator` (simpan dan hitung ulang memakai fungsi yang sama) |
| H-23 | B7-3 berat: diameter stem 0,615 x3, ui stem = 0, jadi uc/v_eff/k/U sama dengan Excel walau ci stem skala 3 berbeda | `NILAI U95%!J139`, `K146` | SAMA | `test_b7_3_berat_ui_stem_nol_sehingga_u_tidak_berubah` |
| H-24 | B7-2 ringan: master ringan memakai penyebut tegangan permukaan skala 1 (`O55`) di semua skala, sama dengan aplikasi | `NILAI U95%!F57`, `F91`, `F125` lawan `O55` | SAMA | `test_b7_2_master_ringan_memakai_penyebut_skala_1_di_semua_skala` |
| S-1 | **B7-1**: lantai CMC ringan. Master mengetik 0,0007 untuk semua skala; aplikasi memakai pita akreditasi 0,60-1,00 g/mL = 0,00051 | `NILAI U95%!L79`, `L113`, `L147` (cetak `L80`, `L114`, `L148`; `SERTIFIKAT!S17:S19`) | SELISIH | `test_b7_1_lantai_cmc_ringan_aplikasi_0_00051_master_0_0007`; cetak `0.00051` lawan `0.00070` |
| S-2 | **B7-2**: ci tegangan permukaan berat skala 2 dan 3. Master memakai penyebut skala sendiri (`O90`, `O124`), aplikasi meniru master ringan (`O55`) di semua skala | `NILAI U95%!F92`, `F126` | SELISIH | `test_b7_2_ci_tegangan_permukaan_berat_skala_2_dan_3_memakai_penyebut_skala_1`; ui tegangan berat = 0, jadi U sama |
| S-3 | **B7-3**: ci stem skala 3. `J139 = C57` (milik skala 1), seharusnya `C126`; aplikasi memakai `C126` | `NILAI U95%!J139` lawan `C126` | SELISIH | `test_b7_3_ci_stem_skala_3_aplikasi_pakai_c126_bukan_j139` (kedua varian); selisih relatif 0,0635 (ringan), 0,0995 (berat) |
| S-4 | **B7-3** dampak hilir ringan skala 3: ui stem tidak nol (diameter 0,708/0,710/0,709), jadi uc, v_eff, k, U beda dari Excel | `NILAI U95%!K146` | SELISIH | `test_b7_3_dampak_hilir_ringan_skala_3_sama_dengan_skenario_c126`; aplikasi = skenario "master memakai C126" (dihitung ulang, berlabel) |
| M-1 | Blok skala 4-5 | `NILAI U95%` (`#VALUE!`) | MENUNGGU | master tidak punya hasil sah; `docs/pertanyaan-lab-hydrometer.md` §15 |
| M-2 | Baris ke-4 dan 5 tabel hasil berat | `SERTIFIKAT!` baris 20-21 (`#VALUE!`) | MENUNGGU | aplikasi tidak mencetaknya |
| M-3 | Resolusi tercetak (teks `0.001`) | `SERTIFIKAT!I10` | MENUNGGU | tidak diuji lewat jalur aplikasi |
| M-4 | Nilai antara yang tidak diekspos: massa terkoreksi per ulangan, penyebut `E44` dan `O55`/`O90` sebagai angka (hanya terlihat lewat ci) | `PERHITUNGAN!G44:I44` per ulangan, `NILAI U95%!O55`, `O90`, `O124` | MENUNGGU | tidak ada titik panggil publik; dibuktikan tidak langsung lewat densitas dan ci |
| M-5 | Header/identitas (nama, merk, tanggal, lokasi, IK, teknisi, penandatangan), daftar standar tercetak, kondisi lingkungan thermohygro, vonis PASS/FAIL | `SERTIFIKAT!` blok identitas | MENUNGGU | tidak diuji di sini |
| M-6 | Pita CMC 1,8-2,0 g/mL tidak ada di lampiran akreditasi | `database/data/kemampuan-kalibrasi.json` | MENUNGGU | aplikasi tanpa lantai; master mengetik 0,0007; sama hanya karena U hitung lebih besar. Bila pita ditambahkan, hasil cetak bisa berbeda |
| M-7 | Keputusan TM atas B7-1, B7-2, B7-3 | lihat bagian 5 | MENUNGGU | tidak ada perubahan app di sini |

Jumlah di tabel ringkasan (24 SAMA, 4 SELISIH, 7 MENUNGGU) menghitung baris H-1..H-24, S-1..S-4, dan M-1..M-7. Satu baris dapat memuat banyak sel (mis. H-9 = 66 pasangan komponen x skala).

## 4. Thermometer Glass, rincian per butir

Sesi contoh workbook: lima titik 30-100 °C, lima pembacaan per titik, SATU budget per sesi (lihat bagian 2). Sisi UUT tidak dikoreksi di master (`UUT Terkoreksi` memang tidak ada), jadi rata-rata UUT = pembacaan UUT. Vonis PASS/FAIL tidak diuji di sini.

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| G-1 | Masukan: sensor standar PRT PT100 = `RTD` aplikasi, merk Yokogawa, oilbath `dua`, lima titik, titik saran aplikasi = nominal master, no. probe 17, lima pembacaan standar dan UUT per titik | `INPUT DATA!B50:B54`, `B33`, `D33:K33` dan seterusnya (alamat di fixture `masukan`) | SAMA | `test_masukan_master_cocok_dengan_konstanta_dan_pembacaan_aplikasi` |
| G-2 | Rata-rata pembacaan standar per titik (5 titik) | `PERHITUNGAN FC!J23:J27` | SAMA | `test_rantai_per_titik_sama_dengan_sel_master` |
| G-3 | STDEV standar per titik | `PERHITUNGAN FC!K23:K27` | SAMA | idem |
| G-4 | Index tabel koreksi standar per titik | `PERHITUNGAN FC!P23:P27` | SAMA | idem |
| G-5 | Koreksi meter kalibrator per titik | `PERHITUNGAN FC!Q23:Q27` | SAMA | idem |
| G-6 | Rata-rata UUT per titik | `PERHITUNGAN FC!J42:J46` | SAMA | idem |
| G-7 | STDEV UUT per titik | `PERHITUNGAN FC!K42:K46` | SAMA | idem |
| G-8 | Standar terkoreksi per titik (`S = J + Q + R`) | `PERHITUNGAN FC!S23:S27` | SAMA | idem; LULUS hanya karena batas relatif 1e-11 x nilai menutup selisih koreksi probe B7-7 (lihat bagian 2 dan SG-4) |
| G-9 | Besaran sesi: STDEV standar maksimum, STDEV UUT maksimum, index maksimum, rentang uji titik es | `PERHITUNGAN FC!M23`, `M42`, `P37`, `Q46` | SAMA | `test_besaran_sesi_sama_dengan_sel_master`; titik es 0,0 di sampel, jadi komponen titik es bernilai nol |
| G-10 | Sebelas komponen budget: vi dan ci seluruhnya (11 dari 11), ui 9 dari 11 komponen | `PERHITUNGAN U95%!S20:S30` (vi), `X20:X30` (ci), `U20:U30` (ui) | SAMA | `test_sebelas_komponen_budget_sama_dengan_master_kecuali_selisih_terkunci`; urutan dan nama sama dengan baris 20-30; dua ui lainnya di SG-1 dan SG-6 |
| G-11 | Agregator GUM aplikasi (`GumCalculator::agregasiBudget`) diberi ui master persis: uc dan v_eff sama dengan master | `PERHITUNGAN U95%!AC32`, `AC33` | SAMA | `test_agregasi_gum_dengan_ui_master_sama_dengan_uc_dan_veff_master`; batas baku 1e-11; membuktikan selisih uc/v_eff hanya dari ui masukan, bukan agregator |
| G-12 | Cetak standar (`30.5`, ...) dan koreksi (`0.5`, ...) lima titik | `SERTIFIKAT!E21:E25`, `L21:L25` | SAMA | `test_cetak_standar_dan_koreksi_sama_dengan_teks_sertifikat`; format `0.0`, teks sama persis |
| G-13 | Cetak UUT titik ke-4 dan ke-5 (`82.0`, `99.0`) | `SERTIFIKAT!J24:J25` | SAMA | `test_cetak_uut_titik_keempat_dan_kelima_sama_dengan_teks_sertifikat`; format `0.0` |
| G-14 | Cetak U95 (`1.1`) dan k (`2`) walau nilai antaranya berbeda dari master | `SERTIFIKAT!L35`, `O36` | SAMA | `test_cetak_u95_dan_k_sama_dengan_teks_sertifikat_walau_nilai_antara_beda`; U aplikasi 1,1262 lawan master 1,1174 (SG-1), keduanya tercetak `1.1` |
| G-15 | Jalur profil (`hitungPerGrup`): standar terkoreksi, rata-rata UUT, STDEV UUT = sel master; koreksi, uc, k, v_eff, U95 = kalkulator persis (simpan, hitung ulang, dan pratinjau memakai fungsi yang sama) | `PERHITUNGAN FC!S23:S27`, `J42:J46`, `K42:K46` | SAMA | `test_jalur_profil_menghasilkan_nilai_yang_sama_dengan_kalkulator_dan_sel_master` |
| G-16 | Pita CMC: titik 100 °C ada di dua pita (0-100 dan 100-200); pita bawah menang, CMC 0,58 = master, lantai tidak aktif (U hitung 1,13 > 0,58) | `PERHITUNGAN U95%!AC36` (CMC), `DATABASE!S5`, `S6` (nilai pita di fixture `masukan.spek`) | SAMA | `test_pita_cmc_bawah_menang_di_100_derajat_dan_lantai_tidak_aktif`; pita dibaca dari `database/data/kemampuan-kalibrasi.json` |
| G-17 | Jejak audit profil memuat sebelas komponen dengan nama dan urutan master; U95 satu per sesi di semua titik; cetak U95 profil = sertifikat | `SERTIFIKAT!L35` | SAMA | `test_jejak_audit_profil_memuat_sebelas_komponen_dan_cetak_u95_sama_dengan_sertifikat` |
| SG-1 | **B7-5**: ui keterulangan STANDAR (`U27 = N27/Q27`, dibagi n = 5) lawan aplikasi dibagi akar n; dampak hilir: uc 0,56854 lawan 0,57303, v_eff 446,62 lawan 442,30, k 1,96529 lawan 1,96534, U 1,11735 lawan 1,12620 (sekitar +0,8 %) | `PERHITUNGAN U95%!U27`, `AC32:AC35` | SELISIH | `test_b7_5_ui_pengulangan_standar_aplikasi_dibagi_akar_n_master_dibagi_n`, `test_b7_5_dampak_hilir_uc_veff_k_dan_u_sama_dengan_skenario_akar_n`; aplikasi = skenario `skenario.pengulangan_standar_dibagi_akar_n` (dihitung ulang, berlabel) |
| SG-2 | **B7-5** jejak: catatan audit `pengulangan_standar_dibagi_n` menyebut sel master (`U27 = N27/Q27`), U95 pembagi master (1,117354) dan U95 yang dipakai (1,126199), dan ikut ke `type_b_components` tiap titik | `PERHITUNGAN U95%!AC37` | SELISIH | `test_b7_5_jejak_audit_menyebut_u95_pembagi_master_dan_u95_dipakai`; selisih tercatat, tidak diam (`AGENTS.md` Olah data butir 4) |
| SG-3 | **B7-6**: k master = polinomial `AC34` dari v_eff; aplikasi = kuantil t(0,975; v_eff) eksak tanpa pemotongan (`FLOOR_V_EFF = false`). Pada v_eff master: 1,9652887 (master) lawan 1,9652898 (eksak), selisih relatif 5,7e-7 | `PERHITUNGAN U95%!AC34` | SELISIH | `test_b7_6_k_master_polinomial_aplikasi_kuantil_eksak`; rumus master dikunci di tes; tidak mengubah angka cetak (`2`) |
| SG-4 | **B7-7**: koreksi probe per titik. Tabel aplikasi 5 angka penting (mis. `2.9007e-05`), master presisi penuh (2,9007059e-05); selisih mutlak sampai 2,3e-10 | `PERHITUNGAN FC!R23:R27` | SELISIH | `test_b7_7_koreksi_probe_aplikasi_dibulatkan_lima_angka_penting`; selisih = galat pembulatan 5 angka penting |
| SG-5 | **B7-7**: koreksi per titik berbeda persis sebesar selisih koreksi probe (titik 1: 5,9e-11) | `SERTIFIKAT!L21:L25` | SELISIH | `test_b7_7_koreksi_per_titik_berbeda_persis_sebesar_selisih_koreksi_probe`; koreksi = sel L + selisih koreksi probe pada batas mutlak 1e-12; tercetak sama (G-12) |
| SG-6 | **B7-7**: ui drift sensor. Aplikasi dari tabel `N24` 9 desimal (0,501701242; `peta-sel/suhu_3alat.json` `bulat: 9`), master presisi penuh; ui aplikasi 0,28965734712 lawan master 0,28965734698 (selisih 1,4e-10, relatif 4,9e-10) | `PERHITUNGAN U95%!U24` (`N24`) | SELISIH | `test_b7_7_ui_drift_sensor_aplikasi_dari_tabel_sembilan_desimal`; B7-5 dan B7-7 BERSAMA menjelaskan seluruh selisih uc, v_eff, k, U pada batas baku 1e-11 (`test_b7_5_dan_b7_7_menjelaskan_seluruh_selisih_uc_veff_k_dan_u`, skenario dihitung di tes dari sel fixture) |
| SG-7 | **B7-8**: cetak UUT tiga titik pertama. Master format `0` (`30`, `51`, `61`); aplikasi satu desimal (`30.0`, `51.0`, `61.0`) | `SERTIFIKAT!J21:J23` | SELISIH | `test_b7_8_cetak_uut_tiga_titik_pertama_aplikasi_satu_desimal_master_nol_desimal`; di master satu kolom punya dua format (J24:J25 `0.0`, G-13) |
| MG-1 | Celah pita CMC master: `AC36 = IF(U18<=100, ..., IF(AND(U18>101, U18<=200), ..., "cek kapsitas UUT"))` tidak punya cabang untuk 100 < U18 <= 101; aplikasi memakai pita (pita bawah menang di 100) | `PERHITUNGAN U95%!AC36` | MENUNGGU | tidak ada sesi contoh dengan titik tertinggi di rentang itu; perilaku aplikasi di sana belum dibandingkan dengan master |
| MG-2 | Header/identitas, standar digunakan, kondisi lingkungan, vonis PASS/FAIL | `SERTIFIKAT!` blok identitas | MENUNGGU | tidak diuji di sini |
| MG-3 | Nilai antara per pembacaan (per ulangan, sebelum rata-rata) | `PERHITUNGAN FC!` kolom pembacaan per ulangan | MENUNGGU | tidak ada titik panggil publik; dibuktikan tidak langsung lewat rata-rata dan STDEV (G-2, G-3, G-6, G-7) |
| MG-4 | Format cetak UUT yang benar bila master sendiri tidak konsisten (J21:J23 `0`, J24:J25 `0.0`) | `SERTIFIKAT!J21:J25` | MENUNGGU | butuh keputusan TM; aplikasi tidak diubah (SG-7). Docblock `ThermometerGlassProfile` menyebut UUT "nol desimal", kodenya satu desimal |
| MG-5 | Keputusan TM atas B7-5, B7-6, B7-7, B7-8 | lihat bagian 5 | MENUNGGU | tidak ada perubahan app di sini. B7-5 sudah diterapkan di aplikasi atas jawaban lab `jawaban.md` §17.3 (16 Sep 2026, `docs/permintaan-user-7.md` §32); yang menunggu TM: menerima selisihnya ke master, yang sertifikat sesi contohnya terbit dengan pembagi 5. B7-6, B7-7, B7-8 belum punya keputusan |
| MG-6 | Render PDF penuh dan render Excel asli | `SERTIFIKAT!` | MENUNGGU | hanya teks sel (emulasi pembulatan), bukan render |

Jumlah di tabel ringkasan (17 SAMA, 7 SELISIH, 6 MENUNGGU) menghitung baris G-1..G-17, SG-1..SG-7, dan MG-1..MG-6. Satu baris dapat memuat banyak sel (mis. G-10 = 11 komponen x vi, ci, ui).

## 5. Konflik untuk Manajer Teknis

Aplikasi tidak diubah. Tes mengunci perilaku sekarang; bila TM memutuskan lain, tes `SELISIH B7-n` yang pertama merah dan menunjuk butirnya.

| # | Alat | Butir | Fakta terukur | Dampak | Status |
|---|---|---|---|---|---|
| B7-1 | Hydrometer ringan | Lantai CMC | Master mengetik 0,0007 di `NILAI U95%!L79/L113/L147` untuk semua skala; aplikasi memakai pita akreditasi yang memuat titik: 0,00051 (0,60-1,00 g/mL). U hitung 0,00048 di bawah keduanya | Cetak U95 ringan: `0.00051` (aplikasi) lawan `0.00070` (master). Hanya ringan; berat di luar pita | Disengaja dan tercatat di jejak audit (`hydrometer_cmc_pita_akreditasi`), `docs/pertanyaan-lab-hydrometer.md` §7. Menunggu keputusan TM bila ingin menyamakan |
| B7-2 | Hydrometer berat | Penyebut koefisien tegangan permukaan | Master berat memakai penyebut skala sendiri (`F92` pakai `O90`, `F126` pakai `O124`); master ringan dan aplikasi memakai `O55` (skala 1). ci tegangan berat skala 2: Excel 0,10616095, aplikasi 0,09533804 (sekitar -10,2 %); skala 3 sekitar -18,9 % | Tidak ada pada U: ui tegangan permukaan berat = 0 di master dan di aplikasi (sel di fixture, dikunci tes), jadi uc, v_eff, k, U, dan cetak sama. Muncul bila sesi punya ui tegangan tidak nol | Aplikasi meniru master ringan (`docs/pertanyaan-lab-hydrometer.md` §8, status DITIRU). Menunggu keputusan TM |
| B7-3 | Hydrometer (kedua varian) | ci diameter stem skala 3 | `NILAI U95%!J139 = C57` (milik skala 1), seharusnya `C126`, di kedua workbook. Aplikasi memakai `C126`. Selisih ci relatif 0,0635 (ringan), 0,0995 (berat) | Ringan: ui stem tidak nol, U aplikasi 0,00049433 lawan Excel 0,00047722 (`K146`), k 1,97993 (v_eff 120,1) lawan 1,97912 (125,31); tertutup lantai CMC jadi cetak tidak berubah. Berat: ui stem 0, tidak berdampak | Disengaja (TIDAK DITIRU), tercatat di jejak audit (`hydrometer_ci_stem_per_skala`), `docs/pertanyaan-lab-hydrometer.md` §14. Menunggu keputusan TM |
| B7-5 | Thermometer Glass | Pembagi keterulangan STANDAR | Master `PERHITUNGAN U95%!U27 = N27/Q27` (Q27 = 5, dibagi n); baris UUT di atasnya `U26 = N26/SQRT(Q26)` (dibagi akar n). Aplikasi membagi akar n di kedua baris (jawaban lab 16 Sep 2026 §17.3). ui standar: master 0,0357771, aplikasi 0,0800000 | uc 0,56854 lawan 0,57303; v_eff 446,62 lawan 442,30; k 1,96529 lawan 1,96534; U 1,11735 lawan 1,12620 (+0,8 %). Cetak U95 `1.1` sama, jarak ke batas pembulatan `1.15` masih 0,024 | Disengaja dan tercatat di jejak audit (`pengulangan_standar_dibagi_n`, memuat kedua U95), dikunci tes. `docs/pertanyaan-lab-suhu-3alat.md` §3 masih menulis "ditiru apa adanya (÷5)", sedangkan kode dan tes memakai ÷√5: dokumen itu basi. Menunggu keputusan TM |
| B7-6 | Thermometer Glass | Algoritma k | Master `AC34` = polinomial dari v_eff (`1,95996 + 2,37356/v + ...`); aplikasi = kuantil t(0,975; v_eff) eksak tanpa pemotongan. Pada v_eff master 446,62: 1,9652887 lawan 1,9652898, selisih relatif 5,7e-7 | Tidak ada pada angka cetak (k tercetak `2`, U95 `1.1`). Muncul sebagai selisih kecil di nilai antara k dan U | Dikunci tes. Catatan audit `v_eff_tidak_dipotong` menyebut v_eff pecahan "mengikuti aproksimasi polinomial master", tetapi tidak menyebut bahwa k aplikasi kuantil eksak, bukan polinomial. Menunggu keputusan TM |
| B7-7 | Thermometer Glass | Digit tabel referensi | Koreksi probe `PERHITUNGAN FC!R23:R27`: aplikasi 5 angka penting (mis. `2.9007e-05`), master presisi penuh (2,9007059e-05); selisih sampai 2,3e-10. ui drift sensor `PERHITUNGAN U95%!U24`: aplikasi dari `N24` 9 desimal (0,501701242), master 0,5017012417552564; ui 0,28965734712 lawan 0,28965734698 (1,4e-10) | Koreksi per titik (`SERTIFIKAT!L21:L25`) berbeda sampai 2,3e-10; uc, v_eff, k, U bergeser bersama B7-5 (B7-5 + B7-7 menjelaskan seluruh selisih pada 1e-11). Tidak ada pada angka cetak | Dikunci tes; bukan catatan audit sesi (galat pembulatan tabel, bukan keputusan metode). Menunggu keputusan TM apakah tabel perlu presisi penuh |
| B7-8 | Thermometer Glass | Format cetak kolom UUT | Master `SERTIFIKAT!J21:J23` format `0` (`30`, `51`, `61`), `J24:J25` format `0.0` (`82.0`, `99.0`): satu kolom, dua format. Aplikasi satu desimal di semua titik (paket suhu 3 alat tanpa peta `format_sertifikat`) | Cetak UUT titik 1-3: `30.0`, `51.0`, `61.0` (aplikasi) lawan `30`, `51`, `61` (master). Titik 4-5 sama. Satu-satunya angka cetak yang berbeda di alat ini | Dikunci tes. Docblock `ThermometerGlassProfile` menyebut UUT "nol desimal", tidak cocok dengan kodenya. Menunggu keputusan TM format mana yang benar |

Catatan penomoran: B7-4 tidak dipakai (dugaan awal format cetak U95 Hydrometer berat terbukti keliru, bagian 2).

## 6. Belum diuji

### Hydrometer

- Blok skala 4-5 dan baris sertifikat 20-21 di master berat (`#VALUE!`, M-1, M-2).
- Resolusi tercetak, header, identitas, standar digunakan, kondisi lingkungan thermohygro, vonis PASS/FAIL (M-3, M-5).
- Nilai antara per ulangan yang tidak diekspos aplikasi (M-4).
- Rentang tegangan permukaan tidak nol (ui tegangan > 0) pada master berat: tidak ada sesi contoh; efek B7-2 pada U belum terukur (di sampel nol).
- Render PDF penuh dan render Excel asli (hanya teks sel).
- Gate MySQL penuh dan CI (wajib sebelum alat disebut selesai).

### Thermometer Glass

- Celah rumus CMC master: `AC36` tidak punya cabang untuk 100 < U18 <= 101 (MG-1). Aplikasi bekerja per pita; tidak ada sesi contoh di rentang itu.
- Header, identitas, standar digunakan, kondisi lingkungan, vonis PASS/FAIL (MG-2).
- Nilai antara per pembacaan, sebelum rata-rata (MG-3).
- Sesi dengan titik es tidak nol: sampel 0,0 / 0,0 / 0,0, jadi komponen titik es (`N29 = Q46/2`) hanya terbukti bernilai nol; rumus dan pengaitannya ke rentang titik es tidak diuji dengan angka tidak nol.
- Sesi dengan UUT di atas 100 °C (pita 100-200) dan sesi yang U hitungnya di bawah CMC (lantai aktif): tidak ada sampel, jalur lantai tidak dibandingkan dengan master.
- Kalibrator selain Yokogawa, sensor selain PRT PT100, oilbath selain `dua`, nomor probe selain 17 (tabel koreksi lain): satu sesi contoh saja.
- Render PDF penuh dan render Excel asli (hanya teks sel).
- Gate MySQL penuh dan CI (wajib sebelum alat disebut selesai).

## 7. Regenerasi fixture

```
py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-batch7.py "C:\Users\<user>\Downloads"
```

Folder yang diberikan memuat subfolder `EXEL CALIBRATION SIDIK\` dengan workbook kedua alat. Skrip menulis `hydrometer.json` dan `thermometer-glass.json` di `tests/Fixtures/paritas/`. Env `SIDIK_MASTER_SANDI` wajib terisi (dibaca di memori, tidak dicetak). Generator berhenti bila rekonstruksi menyimpang dari cache lebih dari 1e-11 (aritmetika) atau 1e-9 (k, U Hydrometer, TINV); untuk Thermometer Glass skrip juga berhenti bila rumus `AC34` atau salah satu rumus ui baris 20-30 berubah dari yang dikenal. Setelah regenerasi, jalankan tes di bagian 2; perubahan di fixture yang bukan angka workbook harus dijelaskan di commit.
