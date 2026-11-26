# Paritas aplikasi lawan Excel: Hydrometer (batch alat ke-7)

Dibuat 26 Nov 2026 (cabang `feat/paritas-batch7`, dari `origin/main` 6361f281). Tidak ada kode aplikasi (`app/`) yang diubah. Dokumen ini hanya melaporkan; selisih = KONFLIK untuk Manajer Teknis (TM), tidak diperbaiki dan tidak dipilih agar tes lulus. Pola dan istilah sama dengan `docs/paritas-analitik-batch2.md`. Nomor konflik batch ini `B7-n` (batch 2 memakai P-10..P-29).

Label: **SAMA** = aplikasi = Excel dalam toleransi bertulis, dibuktikan tes yang dijalankan. **SELISIH** = beda yang terukur (konflik bernomor B7-n; tes mengunci perilaku sekarang dengan penanda `SELISIH B7-n` dan grup `menunggu-keputusan-tm`). **MENUNGGU** = butuh keputusan orang atau bukti yang belum ada, atau belum diuji di sini (alasannya ditulis). Status "TERBUKTI" per alat BUKAN diputuskan di sini; ini bukti yang tersedia untuk koordinator dan TM.

## 1. Ringkasan

| Alat | Hasil | SAMA | SELISIH | MENUNGGU |
|---|---|---|---|---|
| Hydrometer ringan 0,60-0,65 g/mL dan berat 1,8-2,0 g/mL (3 skala tiap varian) | rantai penuh SAMA di kedua varian (praolah, Cuckow, densitas, simpangan baku, koreksi, 11 komponen budget, uc, v_eff, k, U, angka cetak); 3 konflik (B7-1 CMC, B7-2 penyebut tegangan permukaan, B7-3 rujukan sel stem) | 24 | 4 | 7 |

Satu-satunya angka CETAK sampel yang berbeda dari Excel: U95 Hydrometer ringan, `0.00051` (aplikasi) lawan `0.00070` (sertifikat master), B7-1. B7-2 dan B7-3 mengubah nilai antara (ci) tetapi tidak mengubah angka cetak sampel: B7-3 tertutup lantai CMC di ringan dan tidak berdampak di berat; B7-2 berdampak nol pada U karena ui tegangan permukaan berat bernilai 0. Rincian di bagian 3.

## 2. Cara membaca, menjalankan, dan menghasilkan ulang

- Tes: `tests/Feature/Paritas/ParitasHydrometerTest.php` (21 kasus, 980 assertion; data provider `ringan` dan `berat`). Jalankan: `php vendor/phpunit/phpunit/phpunit --configuration=phpunit.xml tests/Feature/Paritas/ParitasHydrometerTest.php` (SQLite; `php artisan test` menggantung di mesin ini). Gate MySQL penuh BELUM dijalankan di sini; CI yang menjalankan. Perangkat bersama: trait `tests/Concerns/MenegakkanParitas.php` (tidak diubah).
- Fixture: `tests/Fixtures/paritas/hydrometer.json`. Tiap nilai Excel membawa alamat sel (`SHEET!SEL`); pesan galat tes menyebut sel itu. Dibangun `docs/skrip/gen-fixture-paritas-batch7.py` (mandiri; memuat bagian alat berikutnya di batch ini). Sandi workbook hanya dari env `SIDIK_MASTER_SANDI`, dibuka di memori; fixture tanpa nama/alamat pelanggan, nomor sertifikat, nomor order, atau teknisi (dipindai); sandi tidak muncul di berkas mana pun.
- Workbook (folder `Downloads`, dibuka dengan sandi dari pemilik): `Hydrometer 0.600 - 0.650 gmL.xlsx` (ringan, sha256 12 awal `3dac055eb0d6`) dan `Hydrometer 1.800 - 2.000 gmL.xlsx` (berat, `1e9427d310e7`). Angka fixture = nilai-cache sel. Rekonstruksi rantai `NILAI U95%` dari sel komponen mereproduksi cache dengan selisih relatif maksimum 1,1e-16 (ringan) dan 4,1e-16 (berat) untuk uc, v_eff, ui; `k`/U memakai TINV Excel yang iteratif lawan `scipy.stats.t.ppf` (selisih 1,3e-11 ringan, 3,7e-11 berat), jadi rekonstruksi `k` dicatat pada 1e-9 dan batas generator untuk aritmetika 1e-11.
- Titik panggil (tanpa HTTP): `HydrometerCalculator::hitungSesi()` (rantai Cuckow + budget 11 komponen) dan `HydrometerProfile::hitungPerGrup()` (jalur simpan dan hitung ulang, plus lantai CMC dari pita `CalibrationCapability`). Pita CMC dibaca dari `database/data/kemampuan-kalibrasi.json` (sumber seeder produksi), bukan diketik ulang. Pembacaan titik ukur, massa di cairan, dan suhu tiap ulangan diambil dari sel masukan master (`PERHITUNGAN`), bukan dari data contoh seeder.
- Angka cetak diuji lewat jalur render sungguhan: format sel dari peta `format_sertifikat` paket Hydrometer (`FormatSertifikatAcuan::untuk`, yang dibekukan ke snapshot), diterapkan `TataLetakSertifikat::formatAngka` / `teksK`, lalu titik desimal bentuk master. Tes juga menegaskan format peta = format sel workbook (mis. `0.000` nominal, `0.00000` U ringan, `0.0000` U berat, `0` k). Bukan render PDF penuh dan bukan render Excel asli (teks Excel = emulasi pembulatan 15 angka penting lalu desimal format, setengah menjauhi nol).
- Dugaan awal "B7-4" (format cetak U95 berat `0.0000` lawan `desimalU95()` = 5) TERBUKTI KELIRU lewat tes pertama yang jalan: cetak memakai peta `format_sertifikat`, bukan `desimalU95()`, dan petanya sama dengan sel master (`0.0009`). Nomornya tidak dipakai; tes memuat penjaganya (`test_cetak_u95_berat_sama_dengan_sel_sertifikat_lewat_peta_format`).

### Toleransi dan asalnya (bukan dipilih agar lulus)

`MenegakkanParitas`: relatif 1e-11, lantai mutlak 1e-12; `k` mutlak 1e-9 dan relatif 1e-9 (alasan TINV di atas); teks cetak SAMA PERSIS. Tidak ada toleransi yang dilebarkan untuk butir SELISIH; butir itu dikunci pada selisihnya (lihat bagian 4).

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
| M-7 | Keputusan TM atas B7-1, B7-2, B7-3 | lihat bagian 4 | MENUNGGU | tidak ada perubahan app di sini |

Jumlah di tabel ringkasan (24 SAMA, 4 SELISIH, 7 MENUNGGU) menghitung baris H-1..H-24, S-1..S-4, dan M-1..M-7. Satu baris dapat memuat banyak sel (mis. H-9 = 66 pasangan komponen x skala).

## 4. Konflik untuk Manajer Teknis

Aplikasi tidak diubah. Tes mengunci perilaku sekarang; bila TM memutuskan lain, tes `SELISIH B7-n` yang pertama merah dan menunjuk butirnya.

| # | Alat | Butir | Fakta terukur | Dampak | Status |
|---|---|---|---|---|---|
| B7-1 | Hydrometer ringan | Lantai CMC | Master mengetik 0,0007 di `NILAI U95%!L79/L113/L147` untuk semua skala; aplikasi memakai pita akreditasi yang memuat titik: 0,00051 (0,60-1,00 g/mL). U hitung 0,00048 di bawah keduanya | Cetak U95 ringan: `0.00051` (aplikasi) lawan `0.00070` (master). Hanya ringan; berat di luar pita | Disengaja dan tercatat di jejak audit (`hydrometer_cmc_pita_akreditasi`), `docs/pertanyaan-lab-hydrometer.md` §7. Menunggu keputusan TM bila ingin menyamakan |
| B7-2 | Hydrometer berat | Penyebut koefisien tegangan permukaan | Master berat memakai penyebut skala sendiri (`F92` pakai `O90`, `F126` pakai `O124`); master ringan dan aplikasi memakai `O55` (skala 1). ci tegangan berat skala 2: Excel 0,10616095, aplikasi 0,09533804 (sekitar -10,2 %); skala 3 sekitar -18,9 % | Tidak ada pada U: ui tegangan permukaan berat = 0 di master dan di aplikasi (sel di fixture, dikunci tes), jadi uc, v_eff, k, U, dan cetak sama. Muncul bila sesi punya ui tegangan tidak nol | Aplikasi meniru master ringan (`docs/pertanyaan-lab-hydrometer.md` §8, status DITIRU). Menunggu keputusan TM |
| B7-3 | Hydrometer (kedua varian) | ci diameter stem skala 3 | `NILAI U95%!J139 = C57` (milik skala 1), seharusnya `C126`, di kedua workbook. Aplikasi memakai `C126`. Selisih ci relatif 0,0635 (ringan), 0,0995 (berat) | Ringan: ui stem tidak nol, U aplikasi 0,00049433 lawan Excel 0,00047722 (`K146`), k 1,97993 (v_eff 120,1) lawan 1,97912 (125,31); tertutup lantai CMC jadi cetak tidak berubah. Berat: ui stem 0, tidak berdampak | Disengaja (TIDAK DITIRU), tercatat di jejak audit (`hydrometer_ci_stem_per_skala`), `docs/pertanyaan-lab-hydrometer.md` §14. Menunggu keputusan TM |

## 5. Belum diuji

- Blok skala 4-5 dan baris sertifikat 20-21 di master berat (`#VALUE!`, M-1, M-2).
- Resolusi tercetak, header, identitas, standar digunakan, kondisi lingkungan thermohygro, vonis PASS/FAIL (M-3, M-5).
- Nilai antara per ulangan yang tidak diekspos aplikasi (M-4).
- Rentang tegangan permukaan tidak nol (ui tegangan > 0) pada master berat: tidak ada sesi contoh; efek B7-2 pada U belum terukur (di sampel nol).
- Render PDF penuh dan render Excel asli (hanya teks sel).
- Gate MySQL penuh dan CI (wajib sebelum alat disebut selesai).

## 6. Regenerasi fixture

```
py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-batch7.py "C:\Users\<user>\Downloads"
```

Env `SIDIK_MASTER_SANDI` wajib terisi (dibaca di memori, tidak dicetak). Generator berhenti bila rekonstruksi menyimpang dari cache lebih dari 1e-11 (aritmetika) atau 1e-9 (k, U). Setelah regenerasi, jalankan tes di bagian 2; perubahan di fixture yang bukan angka workbook harus dijelaskan di commit.
