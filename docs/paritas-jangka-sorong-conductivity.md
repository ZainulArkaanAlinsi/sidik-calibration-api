# Paritas aplikasi lawan Excel: Jangka Sorong dan Conductivity Meter

Dibuat 25 Nov 2026 (cabang `feat/paritas-jangka-sorong-conductivity`, dari `origin/main` `e350ff53`). Pola sama dengan `docs/paritas-micrometer-ph.md` (PR #299). Tidak ada kode aplikasi (`app/`) yang diubah. Dokumen ini hanya melaporkan; selisih = KONFLIK untuk Manajer Teknis (TM), tidak diperbaiki dan tidak dipilih agar tes lulus. Nomor konflik batch ini: P-10 sampai P-19.

Label: **SAMA** = aplikasi = Excel dalam toleransi bertulis. **SELISIH** = beda yang terukur (konflik P-n). **MENUNGGU** = butuh keputusan orang atau bukti yang belum ada. Status "TERBUKTI" per alat BUKAN diputuskan di sini; ini bukti yang tersedia untuk koordinator/TM.

## 1. Ringkasan

| Alat | Hasil | Catatan |
|---|---|---|
| Jangka Sorong (SIDIK-FM-CAL-0527 Rev.2; workbook `Master Olah Data Caliper 2026`) | titik Outside dan Inside (10 + 10 titik, 5 tahap), semua komponen budget kecuali drift, Depth (bila bacaan sama), konstanta, tabel standar, kesejajaran, kerataan, U95 cetak: **SAMA** | **SELISIH** P-10 (bacaan Depth dari berkas luar), P-11 (drift: umur/365 lawan /12; U aplikasi 0,24 % lebih kecil), P-12 (repeatability Depth), P-13 (lantai CMC), **P-14 BARU: blok Inside dan Depth sheet SERTIFIKAT master salah rujuk kolom** |
| Conductivity Meter (SIDIK-FM-CAL-0510 Rev.5; `Master Olah Data_Conductivity.xlsm`) | ketiga titik (25 µS/cm, 1412 µS/cm, 111 mS/cm) **SAMA** di semua tahap hitung: nilai standar, rata-rata, STDEV, koreksi, 4 komponen, uc, veff, k, U, lantai CMC, angka cetak | **SELISIH** hanya di luar jalur hitung sesi contoh: P-15 (polinomial varian 1,412 mS/cm), P-16 (nomor style sertifikat; Standard Value `25.0` lawan `25`), P-17 (tanggal terbit tiga rumus dua nilai), P-18 (sel koreksi buntu), P-19 (Type A terkunci n = 5) |

Untuk Jangka Sorong, satu-satunya selisih yang mengubah angka CETAK adalah P-14 (Inside dan Depth tercetak salah di Excel, benar di aplikasi). P-11 menggeser U sebesar 0,24 % tetapi teks cetak `0.033` sama.

## 2. Cara membaca, menjalankan, dan menghasilkan ulang

- Tes: `tests/Feature/Paritas/ParitasJangkaSorongTest.php` (20 kasus, 1152 assertion), `tests/Feature/Paritas/ParitasConductivityTest.php` (10 kasus, 274 assertion). Jalankan: `php artisan test --filter='ParitasJangkaSorongTest|ParitasConductivityTest'` (SQLite). Gate MySQL penuh BELUM dijalankan di sini; CI yang menjalankan.
- Fixture: `tests/Fixtures/paritas/jangka-sorong.json` (139 KB), `tests/Fixtures/paritas/conductivity-meter.json` (30 KB). Tiap nilai Excel membawa alamat sel (`SHEET!SEL`); pesan galat tes menyebut sel itu. Dibangun `docs/skrip/gen-fixture-paritas-jangka-sorong-conductivity.py` (generator BARU; pembaca sel diimpor dari generator PR #299 yang tidak diubah). Sandi hanya dari `SIDIK_MASTER_SANDI`, di memori; tanpa nama/alamat pelanggan, nomor sertifikat, nomor order, atau teknisi (dipindai: 0 kemunculan).
- Menghasilkan ulang: `py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-jangka-sorong-conductivity.py "<folder Downloads>"`. Skrip berhenti bila rekonstruksi menyimpang > 1e-11 dari cache. Hasil: Jangka Sorong 4,9e-12 (rantai ketiga budget) dan 1,4e-14 (drift K10); Conductivity 1,1e-12.
- Titik panggil (tanpa HTTP): Jangka Sorong `JangkaSorongCalculator::hitungSesi()` (yang dipanggil `JangkaSorongProfile::hitungPerGrup()` untuk preview, simpan, hitung ulang); Conductivity `GumCalculator::hitungTitik()` dengan masukan seperti `CalibrationController` (suhu larutan = rata-rata lima suhu). Konstanta Conductivity dibaca dari sumber produksi (`ConductivityProfile::KOEF_SUHU`, `ConductivitySeeder::STANDAR` lewat refleksi, `ConductivityCapabilitySeeder`); konstanta Jangka Sorong dari `TabelStandarJangkaSorong` (JSON produksi).
- **Jebakan `NOW()` (Jangka Sorong)**: `DATABASE!X11 =NOW()` memengaruhi komponen drift (`PERHITUNGAN U95%!K10`, disalin ke K30). Acuan sah: nilai-cache pada X11 TERSIMPAN (2026-06-04 11:20:55,06) dengan umur 146,4729 hari dari `DATABASE!W14` (2026-01-09); aplikasi diberi tanggal sesi = X11 dipotong ke detik. Rekonstruksi Python rumus K10 pada X11 itu = cache dalam 1,4e-14. Conductivity: `X11` hanya memengaruhi sel status standar (tidak masuk budget). Berkas `.xlsm1` Conductivity (hash 86f3a881...) berbeda dari `.xlsm` (hash 6b19de07...) HANYA di sel yang bergantung `NOW()` (K3, T29, Y29-Y35 di INPUT DATA; X11, Z13-Z18 di DATABASE): seluruh sel hitung identik (dibandingkan dengan diff dump rumus + nilai).
- Uji mutasi (tidak di-commit, `git diff -- app` kosong sesudahnya): geometri Jangka Sorong dibagi 2 bukan akar 3 -> 2 tes merah; `PEMBAGI_UMUR_HARI` 366 bukan 365 -> 3 merah; Conductivity `u_temperature / 3` bukan `/ 2` -> 3 merah; ci `/101` bukan `/100` -> 2 merah. Pesan galat menyebut sel workbook yang menyimpang.
- Simulasi MySQL (tidak di-commit): tes Conductivity dijalankan dengan `u_temperature` diganti 0,36124784 (hasil pembulatan `decimal(20,8)`): 10 kasus tetap hijau.

### Toleransi dan asalnya (bukan dipilih agar lulus)

| Besaran | Toleransi | Asal |
|---|---|---|
| Tahap aritmatika (ui, ci, vi, uc, veff, nilai titik) | relatif 1e-11, lantai mutlak 1e-12 | sama dengan `docs/paritas-micrometer-ph.md` bagian 2: rekonstruksi mandiri mereproduksi cache <= 1,2e-12 (pH), 1,5e-13 (Micrometer), 4,9e-12 (Jangka Sorong), 1,1e-12 (Conductivity); 1e-11 = di atas batas teramati, empat orde di atas epsilon double |
| `k` (TINV) | mutlak 1e-9 | `StudentTDistribution::EPS = 3e-12` pada CDF; galat kuantil <= ~5e-11, margin 20x |
| U = k * uc | relatif 1e-9 | mewarisi galat `k` |
| Rasio drift aplikasi/Excel = 12/365 (Jangka Sorong) | relatif 1e-8 | `getTimestamp()` memotong 0,06 detik pada umur 1,2655e7 detik = 4,74e-9; batas 2x |
| Conductivity, komponen/tahap yang bergantung `u_temperature` | ui, ci 1e-8 relatif; uici 2e-8; uc 1e-9; veff 4e-9; U 4e-9 | kolom `calibration_capabilities.u_temperature` `decimal(20,8)`: MySQL membulatkan 0,36124783736 ke 0,36124784 (galat mutlak 2,6e-9, relatif 7,3e-9). Efek terburuk titik 1412 (bagian temperature 5 % dari jumlah kuadrat): ui, ci 7,3e-9; uici 1,5e-8; uc 3,7e-10; veff 1,5e-9. Komponen lain tetap ketat 1e-11 |
| Angka cetak | teks SAMA PERSIS | pelanggan hanya memegang yang tercetak; teks Excel = emulasi (lihat bagian 6, P-5) |

## 3. Jangka Sorong: status per butir

Sesi contoh workbook: kapasitas 600 mm, resolusi 0,05 mm; Outside 10 titik (0, 50, 100, 150, 200, 300, 400, 500, 550, 600), Inside 10 titik (sama), Depth 5 titik (10, 20, 30, 40, 50 dari tumpukan balok); Evaluation 10 pembacaan Outside dan Inside; suhu 20,25 °C; X11 2026-06-04. Hasil acuan = nilai-cache sel; sheet: `INPUT DATA` (I), `PERHITUNGAN` (P), `PERHITUNGAN U95%` (U), `SERTIFIKAT` (S), `DATABASE` (DB). Selisih maksimum dihitung terhadap nilai-cache (angka dari `JangkaSorongCalculator`).

| # | Butir | Sel sumber | Excel | Aplikasi | Selisih | Status |
|---|---|---|---|---|---|---|
| J-01 | Konstanta budget: alpha 1,2e-6; Uα Caliper Checker 2e-6, balok 2e-5; pembagi akar 6; drift 0,02 + 0,00025·L; wringing 0,05; geometri, efek mekanik 0,0005; meja granit 0,0062; vi 60 / 1e9 / 9; Depth ci muai 50; Depth ci suhu 0,001999998 | P!Y37, Z37, Z24, Z28; U!N9, K10, K11, K12, K13, K53, Q5, Q6, Q7, V48, V46 | (sel) | `tabel-standar-jangka-sorong.json` | 0 (Depth ci suhu 5,6e-16) | SAMA |
| J-02 | Tabel Caliper Checker Outside: 10 nilai terkoreksi | `Std_CaliperCek!C10:F19` | 10 nilai | `TabelStandarHeightGauge` (JSON) | 0 | SAMA |
| J-03 | Tabel Caliper Checker Inside: 10 nilai terkoreksi | `Std_CaliperCek!C23:F32` | 10 nilai | idem | 0 | SAMA |
| J-04 | U95 Caliper Checker 4,1 µm (semua 20 baris) | `Std_CaliperCek!J10:J32` | 4,1 | 4,1 | 0 | SAMA |
| J-05 | Tabel balok ukur Depth: 32 keping (nilai terkoreksi dan U95) | `Standar_GB!Q10:S132` | (32 baris) | `tabel-standar-jangka-sorong.json` | 0 | SAMA |
| J-06 | Tanggal kalibrasi Caliper Checker (2026-01-09) dan balok ukur (2024-01-24) | DB!W14, W13 | idem | idem | 0 | SAMA |
| J-07 | Tumpukan balok Depth, 5 titik | I!C111:C123 | 7,5+2,5; 11+9; 21+9; 40; 50 | `tumpukanDepth()` | 0 | SAMA |
| J-08 | CMC pita 0-300 mm = 15 µm (nilai) | DB!T5 | 15 | 0,015 mm | 0 | SAMA |
| J-09 | Faktor satuan mm / inch | DB!T23, T24 | 1 / 25,4 | `FAKTOR_KE_MM` | 0 | SAMA |
| J-10 | Pembagi umur drift | U!K10 (rumus) | 12 | 365 (`PEMBAGI_UMUR_HARI`); JSON mencatat 12 | - | **SELISIH P-11** |
| J-11 | STDEV Evaluation Outside, Inside (10 pembacaan) | P!N25, N31 | 0,0263523138347 | 0,0263523138347 | 0 | SAMA |
| J-12 | Outside, 10 titik: total nominal | P!H37:H64 | (10) | (10) | 0 | SAMA |
| J-13 | Outside: rata-rata pembacaan | P!U37:U64 | (10) | (10) | 0 | SAMA |
| J-14 | Outside: standar terkoreksi | P!AH37:AH64 | (10) | (10) | 0 | SAMA |
| J-15 | Outside: STDEV titik (10 pembacaan) | P!AI37:AI64 | (10) | (10) | 0 | SAMA |
| J-16 | Outside: koreksi (standar - rata-rata) | P!AJ37:AJ64 | (10) | (10) | 0 | SAMA |
| J-17 | Inside, 10 titik: total nominal | P!H72:H99 | (10) | (10) | 0 | SAMA |
| J-18 | Inside: rata-rata | P!U72:U99 | (10) | (10) | 0 | SAMA |
| J-19 | Inside: standar terkoreksi | P!AH72:AH99 | (10) | (10) | 0 | SAMA |
| J-20 | Inside: STDEV (lima pembacaan pertama, `STDEV(I:M)`) | P!AI72:AI99 | (10) | (10) | 0 | SAMA |
| J-21 | Inside: koreksi | P!AJ72:AJ99 | (10) | (10) | 0 | SAMA |
| J-22 | Depth, 5 titik: total nominal | P!H106:H118 | (5) | (5) | 0 | SAMA |
| J-23 | Depth: standar terkoreksi | P!AH106:AH118 | (5) | (5) | 0 | SAMA |
| J-24 | Depth, bacaan = cache Excel (berkas luar): rata-rata, STDEV (lima pertama), koreksi | P!U, AI, AJ rows 106-118 | (5 x 3) | (5 x 3) | 0 | SAMA |
| J-25 | Depth, bacaan = INPUT DATA (yang diketik teknisi): rata-rata di titik 2-5 | P!U109, U112, U115, U118 | 20,002 / 30,003 / 39,999 / 50,004 | 20 / 30 / 40 / 50 | 0,002-0,004 mm | **SELISIH P-10** |
| J-26 | Depth, bacaan = INPUT DATA: koreksi dan STDEV titik 3 dan 5 | P!AJ, AI rows 109-118 | koreksi -0,00195 / -0,00322 / +0,00114 / -0,0041; STDEV 0,005477 di titik 3, 5 | dihitung dari bacaan lokal (STDEV 0) | sampai 4,1e-3 mm | **SELISIH P-10** |
| J-27 | Outside, 9 komponen selain drift: ui, ci, vi (pengulangan, resolusi, standar, suhu, muai, wringing, geometri, efek mekanik, selisih suhu) | U!K5:V14 | (9 x 3) | (9 x 3) | maks 0 | SAMA |
| J-28 | Inside, 9 komponen selain drift (urutan baris Inside: selisih suhu di posisi 5) | U!K24:V33 | (9 x 3) | (9 x 3) | maks 0 | SAMA |
| J-29 | Outside dan Inside: komponen drift, ci dan vi | U!V10, Q10, V30, Q30 | 1 / 60 | 1 / 60 | 0 | SAMA |
| J-30 | Outside dan Inside: komponen drift, ui | U!T10, T30 | 1,1980e-3 mm | 3,9387e-5 mm (= rumus sel dengan umur/365) | rasio 12/365 = 0,0329 (1e-8) | **SELISIH P-11** |
| J-31 | Depth: 11 komponen ui, ci, vi (dengan bacaan INPUT DATA) | U!K43:V53 | (11 x 3) | (11 x 3) | maks 5,6e-16 | SAMA |
| J-32 | Depth: repeatability bila ada sebaran bacaan (STDEV terbesar 0,005477 mm) | U!K43 = `PERHITUNGAN!AD121` (sel kosong) | 0 | 0,005477/akar 10 = 1,73e-3 | 1,73e-3 mm | **SELISIH P-12** |
| J-33 | Outside: uc, veff, k, U terhadap rumus sel DENGAN drift aplikasi | U!AA16:AA19 (rumus) | 0,016798640692 / 148,533808 / 1,9761224936 / 0,033196171734 | sama | uc 0; veff 8,5e-14; k 9,6e-12; U 1,6e-13 | SAMA |
| J-34 | Inside: uc, veff, k, U terhadap rumus sel dengan drift aplikasi | U!AA35:AA38 (rumus) | sama dengan Outside | sama | sama | SAMA |
| J-35 | Outside dan Inside: uc, veff, k, U terhadap CACHE Excel | U!AA16:AA19, AA35:AA38 | 0,016841259769 / 150,037300 / 1,9759053309 / 0,033276734957 | 0,016798640692 / 148,533808 / 1,9761224936 / 0,033196171734 | uc -0,25 %; veff -1,0 %; k +2,2e-4; U -0,24 % | **SELISIH P-11** |
| J-36 | Depth: uc, veff, k, U | U!AA55:AA58 | 0,014777606152 / 30965,556327 / 1,9600405989 / 0,028964708012 | sama | 0 | SAMA |
| J-37 | U sertifikat Outside, Inside, Depth (kapasitas 600 mm: tanpa lantai CMC) | U!AA21, AA40, AA60 | = U hitung | = U hitung (Outside, Inside: drift aplikasi) | 0 | SAMA |
| J-38 | Lantai CMC 0,015 mm: master tidak menyambungkan (`AA20`, `AA39`, `AA59` kosong); aplikasi memasangnya untuk kapasitas <= 300 mm; pada sampel U > CMC jadi tidak mengikat | U!AA20, AA39, AA59 | kosong | `pitaCmc()` 0,015 | tidak teramati | **SELISIH P-13** |
| J-39 | Kesejajaran muka ukur: nominal, pembacaan, koreksi (3 posisi) | S!F18:M20 | 11 / 11 / 0 | 11 / 11 / 0 | 0 | SAMA |
| J-40 | Kerataan muka ukur: teks tercetak `Baik` | S!F65 (= `INPUT DATA!Z22`) | Baik | Baik | - | SAMA |
| J-41 | Angka cetak Outside: Standard Reading, UUT, Correction (10 baris x 3, format `0.00`) | S!D25:L34 | teks | teks | 0 | SAMA |
| J-42 | Angka cetak U95 Outside, Inside, Depth (`0.033`, `0.033`, `0.029`) | S!J35, J51, J62 | teks | teks | 0 | SAMA |
| J-43 | Angka cetak Inside dan Depth: Standard Reading, UUT, Correction | S!D41:L50, D57:L61 | Standar 0.00 dan Correction 0.00 di SEMUA baris; UUT = satu bacaan X3' | nilai benar (standar terkoreksi, rata-rata, koreksi) | beda di 10 + 5 baris | **SELISIH P-14** |
| J-44 | Tanggal terbit: sertifikat mencetak tanggal kalibrasi (+0); aplikasi +0 | S!B75 = N9; I!D135 `=O16+4` tidak dirujuk | +0 | +0 | 0 | SAMA (K-15: lihat bagian 6) |
| J-45 | `k` tercetak `2` (format `General`, kolom sempit) | S!O36, O52, O63 | nilai 1,97590533 / 1,97590533 / 1,96004060 | nilai SAMA (k Outside/Inside: lihat J-35); teks `2` hanya klaim repo | - | MENUNGGU render |
| J-46 | Daftar nominal Outside/Inside: kertas 9 baris KOSONG, master 10 baris default, aplikasi 11 baris pra-cetak (0, 25, 50, 100, ...) | I!C42:C69; kertas 0527 Rev.2 | - | `barisPraCetak()` | - | MENUNGGU K-14 |
| J-47 | Sesi job 200 mm, resolusi 0,01 mm (cetakan `Jangka Sorong_A23032081.pdf`: halaman input saja, tanpa hasil Excel) | - | tidak ada nilai | - | - | MENUNGGU workbook hasil |

## 4. Conductivity Meter: status per butir

Sesi contoh workbook (job master, tanggal 13 Mei 2024): Std 25 µS/cm, Std 1412 µS/cm, Std 111 mS/cm; 5 pengulangan SESUDAH adjustment (`INPUT DATA!F49:M53`); resolusi 0,1 µS/cm, 1 µS/cm, 0,01 mS/cm. Sheet: `PERHITUNGAN` (P), `PERHITUNGAN U95%` (U), `SERTIFIKAT STYLE 2` (S2), `DATABASE` (DB). Hanya perilaku BAWAAN; parameter keputusan metode (PR #298) sudah merge (`3ab0a8f3`) dengan bawaan identik; tes ditulis sebelum #298 masuk dan dijalankan ulang sesudah cabang ini memuat #298: semua assertion angka tetap hijau (satu-satunya penyesuaian: daftar baris jejak audit di `type_b_components` bertambah `parameter_metode`); polinomial acuan tidak disentuh.

| # | Butir | Sel sumber | Excel | Aplikasi | Selisih | Status |
|---|---|---|---|---|---|---|
| C-01 | Polinomial nilai standar 111 mS/cm (a = 0,0042; b = 1,732; c = 64,88); 25 dan 1412 µS/cm konstan | P!L26 (rumus), F26, H26 | (a, b, c) / 25 / 1412 | `KOEF_SUHU` | 0 | SAMA |
| C-02 | U95 sertifikat larutan 0,5 / 8 µS/cm, 1,6 mS/cm (111 diketik 1600 µS lalu /1000) | DB!V13:V15; U!M37 | 0,5 / 8 / 1600 -> 1,6 | `ConductivitySeeder::STANDAR` | 0 | SAMA |
| C-03 | CMC 0,41 / 5,1 µS/cm / 1,7 mS/cm (+ varian mS = S6/1000) | DB!S5:S8 | 0,41 / 5,1 / 0,0051 / 1,7 | `ConductivityCapabilitySeeder` | 0 | SAMA |
| C-04 | UTemperature = akar((0,72/2)^2 + (0,06/2)^2) = 0,36124783736 (NILAI) | U!Z3:Z5 | 0,36124783736376886 | seeder; MySQL 0,36124784 | <= 2,6e-9 (`decimal(20,8)`) | SAMA (nilai); sumber MENUNGGU (rujuk P-6) |
| C-05 | Resolusi dan satuan per titik; jumlah pengulangan 5 | I!E17:J17 | 0,1 / 1 / 0,01 | `ConductivityProfile::TITIK` | 0 | SAMA |
| C-06 | Suhu larutan rata-rata (sesudah adjustment) | P!G46, I46, M46 | 25,22 / 24,92 / 25,2 | sama | 0 | SAMA |
| C-07 | Nilai standar pada suhu larutan | P!F39, H39, L39 | 25 / 1412 / 111,193568 | sama | 0 | SAMA |
| C-08 | Rata-rata UUT | P!F46, H46, L46 | 25,04 / 1413 / 110,674 | sama | 0 | SAMA |
| C-09 | STDEV sampel | P!F48, H48, L48 | 0,054772255751 / 0 / 0,008944271910 | sama | 0 | SAMA |
| C-10 | Koreksi (Standar - UUT), di lembar PERHITUNGAN dan SERTIFIKAT | P!F47, H47, L47; S2!P35, P36, P41 | -0,04 / -1 / 0,519568 | sama | 0 | SAMA |
| C-11 | Komponen sertifikat larutan: ui, ci, vi, uici | U!M9:Y9, M23:Y23, M37:Y37 | 0,25 / 4 / 0,8; ci 1; vi 200 | sama | 0 | SAMA |
| C-12 | Komponen resolusi alat | U!M10:Y10, M24:Y24, M38:Y38 | 0,02887 / 0,28868 / 0,0028868; vi 1e6 | sama | 0 | SAMA |
| C-13 | Komponen temperature (ci = (UTemp/100)·titik NOMINAL; ui = UTemp/2) | U!W11, W25, W39; T11 | ci 0,0903120 / 5,1008195 / 0,4009851; ui 0,1806239 | sama | maks 1,6e-14 | SAMA |
| C-14 | Komponen pengulangan (Type A, n = 5, vi 4) | U!M12:Y12, M26:Y26, M40:Y40 | 0,0244949 / 0 / 0,004 | sama | 0 | SAMA |
| C-15 | uc | U!AB14, AB28, AB42 | 0,253376066331 / 4,114873303303 / 0,803287044642 | sama | maks 1,8e-15 | SAMA |
| C-16 | v_eff | U!AB15, AB29, AB43 | 210,052490 / 223,354774 / 203,293695 | sama | maks 3,4e-13 | SAMA |
| C-17 | k (TINV, derajat bebas dipotong) | U!AB16, AB30, AB44 | 1,9713247932 / 1,9706589608 / 1,9717188485 | sama | maks 1,3e-12 | SAMA |
| C-18 | U sebelum lantai (uc·k) | U!AB17, AB31, AB45 | 0,499486521574 / 8,109011947858 / 1,583856206647 | sama | maks 5,4e-12 | SAMA |
| C-19 | U sertifikat = MAX(U; CMC); lantai mengikat hanya di 111 mS/cm (1,584 < 1,7) | U!AB19, AB33, AB47 | 0,499487 / 8,109012 / 1,7 | sama | 0 | SAMA |
| C-20 | Tanpa vonis PASS/FAIL (master tidak punya sel pembanding batas) | pencarian `pass|fail|toleran` di dump: 0 hasil | - | `keputusan` null | - | SAMA |
| C-21 | Angka cetak Standard Value titik 1412 dan 111 (`1412`, `111.19`) | S2!C36, C41 | teks | teks | 0 | SAMA |
| C-22 | Angka cetak UUT, Correction, U95 (3 titik); `k` per blok (`2`) | S2!J35:R41, U37, U42 | teks | teks | 0 | SAMA (koreksi titik 25: lihat C-24) |
| C-23 | Standard Value titik 25: Excel `25.0` (format `0.0`), aplikasi `25` (`nolBelakangStandarDibuang()` bawaan true) | S2!C35 | `25.0` | `25` | 1 karakter | **SELISIH P-16** |
| C-24 | Koreksi titik 25 = -0,04 dengan format `0.0`: emulasi Excel `-0.0`; aplikasi sengaja `0.0` (`tandaNolDicetak()` false; klaim repo: PDF master tanpa minus) | S2!P35 | emulasi `-0.0` | `0.0` | 1 tanda | MENUNGGU bukti render (pola P-5) |
| C-25 | Titik tengah varian 1,412 mS/cm: polinomial suhu di master (J26), konstan di aplikasi (E-5, arahan lab 11 Agt 2026) | P!J26, K33 | (2e-13·T² + 27·T + 738)/1000; cache 0,738 (K33 kosong) | 1412 konstan; polinomial disimpan, tidak dipakai | pada 24,92 °C: 1410,84 lawan 1412 µS/cm | **SELISIH P-15** |
| C-26 | Nomor style sertifikat kebalikan dari nama sheet | S1!C40 = J39 (mS), S2!C36 = $H$39 (µS); I!K57 | STYLE 2 = titik tengah µS | µS-µS-mS = style 1 | tukar nomor | **SELISIH P-16** |
| C-27 | Tanggal terbit: tiga rumus, dua nilai | I!D57 (+2), S1!B55 (+4), S2!B55 (+2) | +2 / +4 / +2 | `tambah_hari = null` (tanggal terbit sistem) | - | **SELISIH P-17** |
| C-28 | Sel koreksi varian mS di lembar PERHITUNGAN: `=AVERAGE(...)` bukan selisih (buntu, cache 0) | P!J34, J47 | `=AVERAGE(J29:J33)`, `=AVERAGE(J42:J46)` | Standar - UUT | - | **SELISIH P-18** |
| C-29 | Type A terkunci: pembagi akar(5) dan vi 4 diketik; aplikasi memakai n sebenarnya | U!P12, R12 (idem 26, 40) | 5 / 4 | n = 5: sama; n = 4: vi 3 | n lain | **SELISIH P-19** |
| C-30 | Kondisi lingkungan (suhu/RH rata-rata 25,45 °C / 54,5 %RH; koreksi TH-6; U95 1,7029 °C / 4,9031 %RH) | P!G16:Q17 | tercatat di fixture | tidak diadu | - | MENUNGGU |
| C-31 | Sesi varian mS/cm titik tengah end-to-end (kolom J INPUT DATA kosong di master) | I!J39:K53 | kosong | - | - | MENUNGGU sesi nyata |
| C-32 | Sumber UTemperature (satu konstanta aplikasi lawan termometer standar sesi) | DB!V17, V18 | 0,72 / 0,06 | satu konstanta | - | MENUNGGU (rujuk P-6) |

## 5. Konflik bernomor untuk Manajer Teknis (tidak diperbaiki, tidak dipilih)

| # | Butir | Fakta terukur | Dampak pada angka cetak | Status |
|---|---|---|---|---|
| P-10 | Jangka Sorong: bacaan Depth | `PERHITUNGAN!I106:S118` master membaca berkas LUAR (`'[3]INPUT DATA'`); bacaan lokal `INPUT DATA!F111:P123` berbeda di 4 dari 5 titik (rata-rata Excel 20,002 / 30,003 / 39,999 / 50,004 lawan 20 / 30 / 40 / 50). Aplikasi memakai lembar sesinya sendiri. Total nominal dan standar terkoreksi SAMA di kelima titik | tidak ada di Excel (blok Depth tercetak salah rujuk, lihat P-14); di aplikasi koreksi Depth mengikuti bacaan lokal | **SELISIH sengaja** (penyimpangan no. 3 `JangkaSorongCalculator`, `docs/pertanyaan-lab-jangka-sorong.md` §4); konfirmasi TM |
| P-11 | Jangka Sorong: drift standar Outside dan Inside | Excel `(0,02 + 0,00025·L)/1000 · ((NOW() - W14)/12)` = 2,075e-3 mm (umur 146,47 hari dari sertifikat Caliper Checker, dibagi 12); aplikasi `(0,02 + 0,00025·L)/1000 · (umur/365)` dengan umur dari tanggal sesi = 6,82e-5 mm. ui drift 1,1980e-3 lawan 3,9387e-5 (rasio 12/365). Efek: uc 0,016841 -> 0,016799 (-0,25 %), veff 150,04 -> 148,53 (derajat bebas dipotong 150 -> 148, jadi k 1,975905 -> 1,976122), U 0,033277 -> 0,033196 mm (-0,24 %) | teks cetak U95 `0.033` dan k `2` sama | **SELISIH sengaja** (paket keputusan 16 Sep 2026; U lebih KECIL dari master); konfirmasi TM bahwa arah ini disetujui untuk kapasitas > 300 mm (tanpa lantai CMC) dan untuk sesi baru |
| P-12 | Jangka Sorong: repeatability budget Depth | `U!K43 = PERHITUNGAN!AD121` menunjuk sel KOSONG -> 0 selalu; maksudnya `AI121` (STDEV terbesar titik Depth = 0,005477 mm pada cache Excel). Aplikasi memakai STDEV terbesar; diumpani bacaan cache Excel, ui repeatability 1,73e-3 mm (Excel 0) dan U Depth naik | tidak mengubah teks cetak pada sampel (bacaan lokal identik -> 0 di keduanya) | **SELISIH sengaja** (penyimpangan no. 2; `pertanyaan-lab-jangka-sorong.md` §3) |
| P-13 | Jangka Sorong: lantai CMC dan lingkup akreditasi | `DATABASE!T5` = 15 µm tersedia tetapi `AA20`, `AA39`, `AA59` kosong dan `AA21 = MAX(AA19:AA20)/T23`: master tidak punya lantai. Aplikasi memasang 0,015 mm untuk kapasitas <= 300 mm; kapasitas 600 mm terbit tanpa klaim akreditasi (`dalamLingkupAkreditasiSesi` false). Pada sampel U (0,029 sampai 0,033 mm) > CMC, lantai tidak mengikat | tidak ada pada sampel; mengikat untuk caliper digital resolusi 0,01 dengan sebaran kecil (U ~0,0077 mm di master) | **SELISIH sengaja** (penyimpangan no. 1; §2); belum teramati di workbook |
| P-14 | **BARU** Jangka Sorong: sheet SERTIFIKAT master salah rujuk kolom di blok Inside dan Depth | Outside `D25 = AH37/T23`, `I25 = U37/T23`, `L26 = AJ40/T23` (standar terkoreksi, rata-rata, koreksi). Inside (`D41:L50`) dan Depth (`D57:L61`) memakai `AC` (delta-suhu = 0), `N` (SATU bacaan mentah X3'), `AE` (lw = 0). Nilai-cache tercetak: Standard Reading 0,00 dan Correction 0,00 di SEMUA baris Inside dan Depth; UUT = satu bacaan (mis. Inside 400 mm tercetak 400,50 padahal rata-rata 400,15). Identik di `SERTIFIKAT (2)`. Juga `SERTIFIKAT!L28` cabang `inch` merujuk `V46` (ci Depth) bukan `AJ46`. Tidak tercatat di matriks maupun `docs/pertanyaan-lab-jangka-sorong.md` | **ya**: sertifikat Excel mencetak Inside/Depth yang salah; aplikasi mencetak yang benar (Standard Reading 50,00 dst., UUT rata-rata, Correction 0,00 / -0,05 dst.) | **SELISIH** (cacat master, bukan aplikasi); TM mohon konfirmasi bahwa nilai benar yang dicetak aplikasi; Excel perlu diperbaiki di lab |
| P-15 | Conductivity: titik tengah 1412 µS/cm | master mengkoreksi varian 1,412 mS/cm dengan polinomial suhu (`PERHITUNGAN!J26`), varian 1412 µS/cm konstan (`H26` diketik). Aplikasi mengunci keduanya konstan (E-5, arahan lab 11 Agt 2026); polinomial `KOEF_SUHU_1412_TERDOKUMENTASI` disimpan tanpa dipakai. Pada 24,92 °C: 1410,84 lawan 1412 µS/cm. Sesi contoh memakai varian µS/cm, jadi angka SAMA | tidak ada pada sesi contoh | **SELISIH sengaja** (keputusan lab); butuh rumus final dari lab (`pertanyaan-lab-conductivity.md`) |
| P-16 | Conductivity: (a) nomor style sertifikat; (b) Standard Value titik 25 | (a) Sheet `SERTIFIKAT STYLE 2` mencetak titik tengah dalam µS (`C36 = $H$39`), `STYLE 1` dalam mS (`C40 = J39`); catatan master `INPUT DATA!K57` memakai nomor yang sama; aplikasi: µS-µS-mS = style 1, µS-mS-mS = style 2 (lab menyebut label file salah). (b) `S2!C35` format `0.0` mencetak `25.0`; aplikasi `25` (nol belakang dibuang) pada tingkat pemformat (`Angka::nilaiStandar`) | (b) ya: `25.0` lawan `25` pada baris Standard Value titik 25; (a) hanya nomor | **SELISIH** (a) sengaja menurut arahan lisan; (b) bawaan profil, tidak tertulis alasannya; konfirmasi TM |
| P-17 | Conductivity: tanggal terbit | `INPUT DATA!D57 = Q16+2`, `STYLE 1!B55 = S12+4`, `STYLE 2!B55 = S12+2`: tiga rumus, dua nilai. Aplikasi `tambah_hari = null` (tanggal terbit sistem) | tanggal terbit | **SELISIH** (master tidak konsisten); butuh keputusan TM (sama kelas dengan K4 lembar centang dan K-15) |
| P-18 | Conductivity: sel koreksi buntu varian mS | `PERHITUNGAN!J34 = AVERAGE(J29:J33)`, `J47 = AVERAGE(J42:J46)` bukan selisih, tidak dirujuk sertifikat (cache 0). Aplikasi: Standar - UUT untuk keempat kolom (E-2). Tampilan admin API memakai Rata-rata - Standar (`PerhitunganBuilder`), kebalikan `P!F47` | tidak ada pada sertifikat | **SELISIH sengaja** (audit E-2); informasi |
| P-19 | Conductivity: Type A terkunci | `U!P12/P26/P40 = 5` (pembagi akar) dan `R12/R26/R40 = 4` (vi) diketik, tidak peduli jumlah pengulangan. Aplikasi: n sebenarnya (pembagi akar n, vi = n - 1). Sama di n = 5 (sesi contoh), beda di n lain (n = 4: vi 3 lawan 4) | tidak ada pada sesi contoh | **SELISIH** (tidak teramati di workbook); butuh keputusan TM tentang n selain 5 |

Rujukan konflik lama yang tidak diulang di sini: K-6, K-10, K-14, K-15 (`konflik-excel-kertas.md`), P-5 dan P-6 (`docs/paritas-micrometer-ph.md`).

## 6. Yang BELUM diuji / belum terbukti

- Excel tidak dijalankan ulang: acuan = nilai-cache workbook (dibaca dengan sandi) dan hitung ulang Python rumus sel (ui/ci/vi -> uc -> veff -> k -> U; TINV = t(0,975; floor(veff)); drift pada X11 tersimpan). Rumus sel dibaca dari berkas; makro tidak ada (kedua workbook tanpa VBA).
- Angka cetak diuji pada pemformat (`TataLetakSertifikat::formatAngka`, `Angka::hasil`, `Angka::nilaiStandar`), bukan render HTML/PDF penuh dan bukan render Excel asli (teks Excel = emulasi pembulatan 15 angka penting lalu desimal format, setengah menjauhi nol). Tanda minus pada koreksi yang membulat ke nol (Conductivity titik 25: `-0.0`) = MENUNGGU bukti render (pola P-5). Jangka Sorong: nol baris bertanda minus pada sampel.
- `k` Jangka Sorong berformat `General` di kolom sempit (`S!O36`, `O52`, `O63`): nilainya diadu; tampilan `2` hanya klaim repo (B3a/R9).
- Jangka Sorong, tidak teramati di sesi contoh: satuan inch; Lmaks gabungan Outside-Inside lawan `C67` master (Inside tidak lebih panjang dari Outside); umur drift negatif (tanggal sesi < 2026-01-09: aplikasi umur 0 + peringatan, Excel `NOW()` positif); lantai CMC mengikat (U < 0,015 mm); kerataan `Buruk` (master hanya membaca checkbox pertama `Y22`); evaluation identik; repeatability Depth dengan pembacaan lokal bersebaran (hanya lewat bacaan cache Excel); sesi job 200 mm (cetakan PDF hanya halaman input).
- Conductivity, tidak diuji: kondisi lingkungan (suhu/RH, koreksi TH-6, U95 TH) dan status standar (termometer standar jatuh tempo 12 Agu 2026, `DATABASE!Y17`); varian mS/cm titik tengah end-to-end (belum ada sesi nyata); slot ke-4 `Conduct 80000` (TRUE tanpa data, sengaja bukan titik: E-4); kertas FM-0510 Rev.5 masih menulis nominal lama 84 / 1413 / 5000 / 80000; TH-6 berada di grup Insitu padahal sampel master Inlab.
- Kedua alat: jalur HTTP simpan/preview dan `HitungUlangSesi` dari baris `raw_measurements` tidak dipanggil (yang dipanggil fungsi hitung yang sama); gate MySQL penuh wajib di CI. Efek `decimal(20,8)` pada `u_temperature` Conductivity disimulasikan (bagian 2), bukan dijalankan di MySQL.
- Paritas Conductivity = perilaku BAWAAN. Parameter keputusan metode (PR #298, termasuk conductivity#18) kini di main (`3ab0a8f3`); bawaannya identik (vonis peninjau independen PR #298) dan tes ini hijau di atas #298. PILIHAN non-bawaan (penetapan TM) TIDAK diadu di sini: tiap pilihan lain dicocokkan ke sel workbook oleh tes PR #298 sendiri.
- Workbook yang tidak punya padanan: tidak ada workbook Jangka Sorong lain (Caliper 2026 satu-satunya; hash sama di folder master dan Downloads). Conductivity: dua berkas (`.xlsm`, `.xlsm1`), sel hitung identik.

## 7. Hitungan butir

Butir = baris bertanda status di bagian 3 dan 4 (dihitung dengan skrip dari kolom Status dokumen ini, baris `J-` dan `C-`):

| Alat | SAMA | SELISIH | MENUNGGU | Total |
|---|---|---|---|---|
| Jangka Sorong | 36 | 8 (J-10, J-25, J-26, J-30, J-32, J-35, J-38, J-43; konflik P-10 sampai P-14) | 3 (J-45, J-46, J-47) | 47 |
| Conductivity Meter | 22 | 6 (C-23, C-25, C-26, C-27, C-28, C-29; konflik P-15 sampai P-19) | 4 (C-24, C-30, C-31, C-32) | 32 |
| Jumlah | 58 | 14 | 7 | 79 |

## 8. Menghasilkan ulang fixture

```
py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-jangka-sorong-conductivity.py "<folder Downloads>"   # butuh SIDIK_MASTER_SANDI di lingkungan
```

Skrip berhenti bila rekonstruksi rantai menyimpang > 1e-11 dari cache. Hasil terakhir: Jangka Sorong 4,9e-12 (budget) / 1,4e-14 (drift K10); Conductivity 1,1e-12.
