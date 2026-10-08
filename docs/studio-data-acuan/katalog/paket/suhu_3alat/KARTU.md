# Thermocouple · Termometer gelas · Thermohygrometer — paket `suhu_3alat`

| | |
|---|---|
| Kelompok | Suhu |
| Sumber data acuan hari ini | json — `database/data/tabel-master-suhu-3alat.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `ThermocoupleProfile`, `ThermometerGlassProfile`, `ThermohygroProfile` |
| Kalkulator | `ThermocoupleCalculator`, `ThermometerGlassCalculator`, `ThermohygroCalculator` |
| Kelas tabel | `TabelKalibratorSuhu3Alat` |
| Formulir resmi | SIDIK-FM-CAL-0525_Rev.3 - LEMBAR KERJA THERMOHYGRO.pdf, SIDIK-FM-CAL-0535_Rev.2 - LEMBAR KERJA THERMOCOUPLE.pdf, SIDIK-FM-CAL-0537_Rev.2 - LEMBAR KERJA THERMOMETER GLASS.pdf |
| Folder master | `suhu_&_kelembapan/Master_Olah_Data_Suhu_Thermocouple`, `suhu_&_kelembapan/Master_Olah_Data_Suhu_Thermometer_Glass`, `suhu_&_kelembapan/Master_Olah_Data_Suhu___Kelembapan` |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-suhu-3alat.md`, `docs/pertanyaan-lab-thermohygro-satuan.md` |
| Serah-terima frontend | — |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelKalibratorSuhu3Alat` ← `TabelStandarTids`, `ThermocoupleCalculator`, `ThermocoupleProfile`, `ThermohygroCalculator`, `ThermohygroProfile`, `ThermometerGlassCalculator`, `ThermometerGlassProfile`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelKalibratorSuhu3Alat.php`
- `app/Services/Calibration/ThermocoupleCalculator.php`
- `app/Services/Calibration/ThermometerGlassCalculator.php`
- `app/Services/Calibration/ThermohygroCalculator.php`
- `app/Services/Calibration/Profiles/ThermocoupleProfile.php`
- `app/Services/Calibration/Profiles/ThermometerGlassProfile.php`
- `app/Services/Calibration/Profiles/ThermohygroProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/Suhu3AlatLembarKerjaTest.php`
- `tests/Feature/ThermohygroSemuaLembarTest.php`
- `tests/Feature/TipeThermocoupleUutTest.php`
- `tests/Unit/Suhu3AlatMasterTest.php`
- `tests/Unit/TermometerGelasButuhDuaPembacaanUutTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Suhu_Thermocouple` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 109 |
| `Master_Olah_Data_Suhu_Thermocouple` | Drawing Enclosure | bukan data — tidak masuk Studio | 13 |
| `Master_Olah_Data_Suhu_Thermocouple` | FC Prt Pt100 | tab data acuan (bisa disunting lewat versi) | 68 |
| `Master_Olah_Data_Suhu_Thermocouple` | FORM VALIDASI | sumber nomor versi workbook (F1) | 185 |
| `Master_Olah_Data_Suhu_Thermocouple` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 71 |
| `Master_Olah_Data_Suhu_Thermocouple` | Interpolasi | tab data acuan (bisa disunting lewat versi) | 76 |
| `Master_Olah_Data_Suhu_Thermocouple` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 57 |
| `Master_Olah_Data_Suhu_Thermocouple` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 41 |
| `Master_Olah_Data_Suhu_Thermocouple` | SENSOR PT100 | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_Thermocouple` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 63 |
| `Master_Olah_Data_Suhu_Thermocouple` | STANDAR KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 100 |
| `Master_Olah_Data_Suhu_Thermocouple` | STANDAR-CONSTANT | tab data acuan (bisa disunting lewat versi) | 130 |
| `Master_Olah_Data_Suhu_Thermocouple` | STANDAR-VICTOR | tab data acuan (bisa disunting lewat versi) | 146 |
| `Master_Olah_Data_Suhu_Thermocouple` | STANDAR-YOKOGAWA | tab data acuan (bisa disunting lewat versi) | 145 |
| `Master_Olah_Data_Suhu_Thermocouple` | TERMOCOUPLE TYPE K | tab data acuan (bisa disunting lewat versi) | 132 |
| `Master_Olah_Data_Suhu_Thermocouple` | TERMOCOUPLE TYPE N | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_Thermocouple` | Variasi axial Dryblok A | tab data acuan (bisa disunting lewat versi) | 50 |
| `Master_Olah_Data_Suhu_Thermocouple` | Variasi axial Dryblok B | tab data acuan (bisa disunting lewat versi) | 50 |
| `Master_Olah_Data_Suhu_Thermocouple` | stdev drywell | tab data acuan (bisa disunting lewat versi) | 21 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 109 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | FC Prt Pt100 | tab data acuan (bisa disunting lewat versi) | 68 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | FORM VALIDASI | sumber nomor versi workbook (F1) | 179 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 70 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | Interpolasi | tab data acuan (bisa disunting lewat versi) | 60 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 56 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 43 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | SENSOR PT100 | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 64 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | STANDAR KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 100 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | STANDAR-CONSTANT | tab data acuan (bisa disunting lewat versi) | 131 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | Stndar Yokogawa | tab data acuan (bisa disunting lewat versi) | 146 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | TERMOCOUPLE TYPE K | tab data acuan (bisa disunting lewat versi) | 132 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | TERMOCOUPLE TYPE N | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_Thermometer_Glass` | Variasi Spasial & stab Oilbath | tab data acuan (bisa disunting lewat versi) | 58 |
| `Master_Olah_Data_Suhu___Kelembapan` | ClimaticChamberStability(unuse) | bukan data — tidak masuk Studio | 41 |
| `Master_Olah_Data_Suhu___Kelembapan` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 109 |
| `Master_Olah_Data_Suhu___Kelembapan` | FORM VALIDASI | sumber nomor versi workbook (F1) | 185 |
| `Master_Olah_Data_Suhu___Kelembapan` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 110 |
| `Master_Olah_Data_Suhu___Kelembapan` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 100 |
| `Master_Olah_Data_Suhu___Kelembapan` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 74 |
| `Master_Olah_Data_Suhu___Kelembapan` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 87 |
| `Master_Olah_Data_Suhu___Kelembapan` | STANDAR_KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 27 |
| `Master_Olah_Data_Suhu___Kelembapan` | stabilitas dan homogenitas Cham | tab data acuan (bisa disunting lewat versi) | 34 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olah_Data_Suhu_Thermocouple › FC Prt Pt100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2025-02-14T00:00:00
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2027-02-15T00:00:00
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
For t ≥0 oC : | For t≤0 oC :
0
0.003878 | A | 0.00390954
```
**Master_Olah_Data_Suhu_Thermocouple › Interpolasi**

```
Temp Calibrator : Constant
Titik Kalibrasi | PRT PT100 | Titik Kalibrasi | Thermocouple Type K | Titik Kalibrasi | Thermocouple Type N | Titik Kalibrasi | Thermocouple Type B | Titik Kalibrasi | Thermocouple Type T | Titik Kalibrasi | Thermocouple
-20 | 0.10000000000000142 | -20 | 0.1999999999999993 | -20 | 0.10000000000000142 | 600 | 0.10000000000002274 | -100 | 0.12999999999999545 | 0 | 0.05 | 0 | 0.23
0 | 0.1 | 0 | 0.1 | 0 | 0.1 | 800 | 0.16999999999995907 | 0 | 0.06 | 50 | -0.17999999999999972 | 50 | 0.21000000000000085
200 | 0.09999999999999432 | 100 | -0.09999999999999432 | 100 | 0.09999999999999432 | 1000 | 0.2999999999999545 | 50 | 0.07000000000000028 | 100 | -0.15000000000000568 | 100 | 0.18999999999999773
400 | 0.10000000000002274 | 200 | -0.09999999999999432 | 200 | -0.09999999999999432 | 1200 | 0.31999999999993634 | 100 | 0.10999999999999943 | 200 | -0.12999999999999545 | 200 | 0.30000000000001137
```
**Master_Olah_Data_Suhu_Thermocouple › SENSOR PT100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2025-02-14T00:00:00
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2027-02-15T00:00:00
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
Titik Kalibrasi | Correction | Uncertainty U95%
oC | oC | oC
-20 | 0.0039691640657189 | 0.08
```
**Master_Olah_Data_Suhu_Thermocouple › STANDAR KALIBRATOR**

```
STANDAR METER/INDIKATOR | TABEL RECORD DRIFT
Std Mode: | Measure
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2024-08-28T00:00:00 | Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2025-08-12T00:00:00 | Std Meter | Update drift juni 2024
Merek | : | Constant | Due Date Kalibrasi | : | 2025-08-28T00:00:00 | Merek | : | Yokogawa | Due Date Kalibrasi | : | 2026-08-12T00:00:00 | Constant | Yokogawa
Type | : | 40T | Tertelusur | : | LK-202-IDN | Type | : | CA 150 Handy Cal | Tertelusur | : | LK-202-IDN | Termocouple | Udrift | Termocouple | Udrift
S/N | : | 99875850 | S/N | : | 23P1005 | PRT PT100 | 0.23 | PRT PT100 | 0.072
```
**Master_Olah_Data_Suhu_Thermocouple › STANDAR-CONSTANT**

```
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2024-08-28T00:00:00
Merek | : | Constant | Due Date Kalibrasi | : | 2025-08-28T00:00:00
Type | : | 40T | Tertelusur | : | LK-202-IDN
S/N | : | 99875850
Mode | : | Measure (Indikator)
Sensor | : | PRT PT100
```
**Master_Olah_Data_Suhu_Thermocouple › STANDAR-VICTOR**

```
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2020-09-25T00:00:00
Merek | : | Victor | Due Date Kalibrasi | : | 2021-09-25T00:00:00
Type | : | Victor 14+ | Tertelusur | : | LK-202-IDN
S/N | : | 992613877
Mode | : | Measure (Indikator)
Sensor | : | PRT PT100
```
**Master_Olah_Data_Suhu_Thermocouple › STANDAR-YOKOGAWA**

```
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2025-08-12T00:00:00 | 0
Merek | : | Yokogawa | Due Date Kalibrasi | : | 2026-08-12T00:00:00
Type | : | CA 150 Handy Cal | Tertelusur | : | LK-202-IDN
S/N | : | 23P1005
Mode | : | Measure (Indikator)
Sensor | : | PRT PT100
```
**Master_Olah_Data_Suhu_Thermocouple › TERMOCOUPLE TYPE K**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2024-09-06T00:00:00
Type | : | Type K | Due Date Kalibrasi | : | 2026-09-06T00:00:00
S/N | : | TC-01,02 | Tertelusur | : | LK-064-IDN
Tikal | TC-01 Correct | Tikal | TC-02 Corret | AVERAGE | Titik Kalibrasi | Correction (oC)
-20 | -0.21 | -20 | -0.33 | -0.27 | oC | TCK-01 | TCK-02 | TCK-03 | TCK-04 | TCK-05 | TCK-06 | TCK-07 | TCK-08 | TCK-09 | TCK-10 | TCK-11 | TCK-12 | TCK-13 | TCK-14 | TCK-15 | TCK-16
0 | -0.06 | 0 | -0.1 | -0.08 | -20 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27
```
**Master_Olah_Data_Suhu_Thermocouple › TERMOCOUPLE TYPE N**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2024-09-06T00:00:00
Type | : | Type N | Due Date Kalibrasi | : | 2026-09-06T00:00:00
S/N | : | TCN-06,11 | Tertelusur | : | LK-064-IDN
Titik Kalibrasi | Correction (oC)
Tikal | TCN-06 | TCN-11 | AVERAGE | oC | TC6 | TC7 | TC8 | TC9 | TC10 | TC11 | TC12 | TC13 | TC14 | TC15
-20 | -0.24 | -0.06 | -0.15 | -20 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15
```
**Master_Olah_Data_Suhu_Thermocouple › Variasi axial Dryblok A**

```
Variasi Axial
Owner : | PT. Sistem Dirgantara Inovasi Teknologi | Type : | TeCal 700xs
TGL Tes : | 2024-07-17T00:00:00 | SN : | DB-B-2
Kapasitas: | 0~600 oC
Standard: | Dryblok
Merk: | Techne
```
**Master_Olah_Data_Suhu_Thermocouple › Variasi axial Dryblok B**

```
Variasi Axial
Owner : | PT. Sistem Dirgantara Inovasi Teknologi | Type : | TeCal 700xs
TGL Tes : | 2024-07-17T00:00:00 | SN : | DB-B-2
Kapasitas: | 0~600 oC
Standard: | Dryblok
Merk: | Techne
```
**Master_Olah_Data_Suhu_Thermocouple › stdev drywell**

```
KESTABILAN | KESERAGAMAN
Posisi | Lubang | Stdev | Average | Stdev
100 | 200 | 300 | 400 | 100 | 200 | 300 | 400 | 100 | 200 | 300 | 400
Tengah | 1 | 0.0547722557505135 | 0.0547722557505135 | 0.05477225575049793 | 0.05477225575049793 | 98.43999999999998 | 198.44 | 298.24 | 398.0400000000001 | 1.1879393923934147 | 1.1879393923934047 | 1.3293607486307062 | 
Bawah | 0.0836660026534096 | 0.0836660026534028 | 0.08366600265339941 | 0.08366600265339941 | 100.12 | 200.12 | 300.12 | 400.12
Tengah | 2 | 0.0547722557505135 | 0.0547722557505135 | 0.05477225575049793 | 0.05477225575049793 | 98.55999999999999 | 198.56 | 298.26 | 397.9599999999999 | 1.315218613006978 | 1.2727922061357735 | 1.4142135623730951 | 1
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-master-suhu-3alat.json`: 1992/1992 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `thermocouple` | peta | 5 | 5 | lebar | _sumber·teks; _yokogawa_dari_tautan_luar·teks; index_kalibrator·daftar_angka; stabilitas_cold_junction·desimal; ac_pick_up·desimal |
| `thermocouple/koreksi_kalibrator/constant` | tabel | 18 | 112 | lebar | titik·desimal; RTD·desimal; Type K·desimal; Type N·desimal; Type T·desimal; Type R·desimal; Type S·desimal; Type B·desimal |
| `thermocouple/koreksi_kalibrator/yokogawa` | tabel | 18 | 61 | lebar | titik·desimal; RTD·desimal; Type K·desimal; Type N·desimal |
| `thermocouple/u95_kalibrator/constant` | tabel | 18 | 110 | lebar | titik·desimal; RTD·desimal; Type K·desimal; Type N·desimal; Type T·desimal; Type R·desimal; Type S·desimal; Type B·desimal |
| `thermocouple/u95_kalibrator/yokogawa` | tabel | 18 | 61 | lebar | titik·desimal; RTD·desimal; Type K·desimal; Type N·desimal |
| `thermocouple/koreksi_sensor` | tabel | 15 | 300 | panjang | titik·desimal; RTD·desimal; TCN3·desimal; TCN4·desimal; TCN5·desimal; TCN6·desimal; TCN7·desimal; TCN8·desimal; TCN9·desimal; TCN10·desimal; … +18 |
| `thermocouple/u95_sensor` | tabel | 15 | 302 | panjang | titik·desimal; RTD·desimal; TCN3·desimal; TCN4·desimal; TCN5·desimal; TCN6·desimal; TCN7·desimal; TCN8·desimal; TCN9·desimal; TCN10·desimal; … +18 |
| `thermocouple/drift_kalibrator` | tabel_berkunci | 2 | 6 | lebar | RTD·desimal; Type N·desimal; Type K·desimal |
| `thermocouple/drift_sensor` | peta | 3 | 3 | lebar | RTD·desimal; Type N·desimal; Type K·desimal |
| `thermocouple/dryblock` | tabel_berkunci | 2 | 4 | lebar | axial_u·desimal; radial_max·desimal |
| `thermocouple/cmc` | tabel | 3 | 6 | lebar | rentang·teks; u·desimal |
| `thermocouple/probe_per_tipe` | tabel_berkunci | 3 | 27 | lebar | 1·teks; 2·teks; 3·teks; 4·teks; 5·teks; 6·teks; 7·teks; 8·teks; 9·teks; 10·teks; … +7 |
| `thermometer_glass` | peta | 2 | 2 | lebar | _sumber·teks; index_kalibrator·daftar_angka |
| `thermometer_glass/koreksi_kalibrator/constant` | tabel | 18 | 80 | lebar | titik·desimal; RTD·desimal; Type K·desimal; Type N·desimal; Type T·desimal; Type R·desimal; Type S·desimal; Type B·desimal |
| `thermometer_glass/koreksi_kalibrator/yokogawa` | tabel | 18 | 61 | lebar | titik·desimal; RTD·desimal; Type K·desimal; Type N·desimal |
| `thermometer_glass/u95_kalibrator/constant` | tabel | 18 | 110 | lebar | titik·desimal; RTD·desimal; Type K·desimal; Type N·desimal; Type T·desimal; Type R·desimal; Type S·desimal; Type B·desimal |
| `thermometer_glass/u95_kalibrator/yokogawa` | tabel | 18 | 61 | lebar | titik·desimal; RTD·desimal; Type K·desimal; Type N·desimal |
| `thermometer_glass/koreksi_sensor` | tabel | 15 | 284 | panjang | titik·desimal; RTD·desimal; TCN3·desimal; TCN4·desimal; TCN5·desimal; TCN6·desimal; TCN7·desimal; TCN8·desimal; TCN9·desimal; TCN10·desimal; … +18 |
| `thermometer_glass/u95_sensor` | tabel | 15 | 300 | panjang | titik·desimal; RTD·desimal; TCN3·desimal; TCN4·desimal; TCN5·desimal; TCN6·desimal; TCN7·desimal; TCN8·desimal; TCN9·desimal; TCN10·desimal; … +18 |
| `thermometer_glass/drift_kalibrator` | tabel_berkunci | 2 | 6 | lebar | RTD·desimal; Type N·desimal; Type K·desimal |
| `thermometer_glass/drift_sensor` | peta | 3 | 3 | lebar | RTD·desimal; Type N·desimal; Type K·desimal |
| `thermometer_glass/oilbath` | tabel_berkunci | 2 | 4 | lebar | variasi_spasial·desimal; stabilitas·desimal |
| `thermometer_glass/tipe_thermometer` | tabel | 3 | 6 | lebar | nilai·desimal; label·teks |
| `thermometer_glass/cmc` | tabel | 2 | 4 | lebar | rentang·teks; u·desimal |
| `thermometer_glass/probe_per_tipe` | tabel_berkunci | 3 | 27 | lebar | 1·teks; 2·teks; 3·teks; 4·teks; 5·teks; 6·teks; 7·teks; 8·teks; 9·teks; 10·teks; … +7 |
| `thermohygro` | peta | 1 | 1 | lebar | _sumber·teks |
| `thermohygro/koreksi_suhu` | tabel | 7 | 14 | lebar | titik·desimal; koreksi·desimal |
| `thermohygro/koreksi_rh` | tabel | 9 | 18 | lebar | titik·desimal; koreksi·desimal |
| `thermohygro/u95_kalibrator` | peta | 2 | 2 | lebar | suhu·desimal; rh·desimal |
| `thermohygro/drift_kalibrator` | peta | 2 | 2 | lebar | suhu·desimal; rh·desimal |
| `thermohygro/chamber` | tabel_berkunci | 3 | 6 | lebar | homogenitas·desimal; stabilitas·desimal |
| `thermohygro/cmc` | tabel | 2 | 4 | lebar | parameter·teks; u·desimal |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!Q20:V23`, `DATABASE!R5:S7`, `PERHITUNGAN FC!M23`, `PERHITUNGAN FC!P23`, `PERHITUNGAN FC!R23`, `PERHITUNGAN U95%!B20:B28`, `PERHITUNGAN U95%!B20:B30`, `PERHITUNGAN U95%!N22`, `SERTIFIKAT!L20`

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
