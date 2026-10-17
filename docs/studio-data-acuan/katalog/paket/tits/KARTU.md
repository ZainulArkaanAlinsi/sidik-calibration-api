# TITS & kalibrator suhu (measure/source) — paket `tits`

| | |
|---|---|
| Kelompok | Suhu |
| Sumber data acuan hari ini | json — `database/data/tabel-kalibrator-suhu.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `TitsProfile` |
| Kalkulator | `TitsCalculator` |
| Kelas tabel | `TabelKalibratorSuhu` |
| Formulir resmi | SIDIK-FM-CAL-0505_Rev.3 - LEMBAR KERJA TITS.pdf |
| Folder master | `suhu_&_kelembapan/Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT`, `suhu_&_kelembapan/Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-tits.md` |
| Serah-terima frontend | `docs/perintah-frontend-tits.md` |

**Catatan:** Tabel kalibrator ini juga dibaca TIDS, Enclosure, dan tabel suhu 3 alat — perubahan di sini menggeser alat-alat itu juga. Simulasi wajib menjalankan semua profil pemakainya.

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelKalibratorSuhu` ← `CalibrationRequest`, `TabelKalibratorEnclosure`, `TabelKalibratorSuhu3Alat`, `TabelStandarTids`, `TidsProfile`, `TitsCalculator`, `TitsProfile`, `UjiProfilKalibrasi`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelKalibratorSuhu.php`
- `app/Services/Calibration/TitsCalculator.php`
- `app/Services/Calibration/Profiles/TitsProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/Suhu3AlatLembarKerjaTest.php`
- `tests/Feature/ThermohygroSemuaLembarTest.php`
- `tests/Feature/TidsLembarKerjaTest.php`
- `tests/Feature/TitsSesiTest.php`
- `tests/Unit/TitsBudgetTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 109 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | Drawing Enclosure | bukan data — tidak masuk Studio | 13 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | FC Prt Pt100 | tab data acuan (bisa disunting lewat versi) | 103 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | FORM VALIDASI | sumber nomor versi workbook (F1) | 188 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 57 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | Interpolasi | tab data acuan (bisa disunting lewat versi) | 37 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 42 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 36 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | SENSOR PT100 | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 58 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | STANDAR KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 100 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | STANDAR-CONSTANT | tab data acuan (bisa disunting lewat versi) | 129 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | STANDAR-YOKOGAWA | tab data acuan (bisa disunting lewat versi) | 304 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | TERMOCOUPLE TYPE K | tab data acuan (bisa disunting lewat versi) | 132 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT` | TERMOCOUPLE TYPE N | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 109 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | Drawing Enclosure | bukan data — tidak masuk Studio | 13 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | FC Prt Pt100 | tab data acuan (bisa disunting lewat versi) | 103 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | FORM VALIDASI | sumber nomor versi workbook (F1) | 179 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 55 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | Interpolasi | tab data acuan (bisa disunting lewat versi) | 52 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 40 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 37 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | SENSOR PT100 | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 52 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | STANDAR KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 100 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | STANDAR-CONSTANT | tab data acuan (bisa disunting lewat versi) | 129 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | STANDAR-YOKOGAWA | tab data acuan (bisa disunting lewat versi) | 137 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | TERMOCOUPLE TYPE K | tab data acuan (bisa disunting lewat versi) | 132 |
| `Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT` | TERMOCOUPLE TYPE N | tab data acuan (bisa disunting lewat versi) | 140 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT › FC Prt Pt100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2022-09-19T00:00:00
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2024-09-19T00:00:00
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
For t ≥0 oC : | For t≤0 oC :
0
0.003878 | A | 0.003501
```
**Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT › Interpolasi**

```
Temp Calibrator : Constant
Titik Kalibrasi | PRT PT100 | Titik Kalibrasi | Thermocouple Type K | Titik Kalibrasi | Thermocouple Type N | Titik Kalibrasi | Thermocouple Type B | Titik Kalibrasi | Thermocouple Type T | Titik Kalibrasi | Thermocouple
-20 | 0 | -20 | -0.10000000000000142 | -20 | -0.10000000000000142 | 600 | -0.39999999999997726 | -100 | -0.15000000000000568 | 0 | 0 | 0 | 0
0 | 0.2 | 0 | -1 | 0 | -0.2 | 800 | -0.4199999999999591 | 0 | -0.2 | 50 | -0.20000000000000284 | 50 | -0.20000000000000284
100 | 0.29999999999999716 | 100 | -0.20000000000000284 | 100 | -0.20000000000000284 | 1000 | -0.5399999999999636 | 50 | -0.20000000000000284 | 100 | -0.20000000000000284 | 100 | -0.20000000000000284
200 | 0.30000000000001137 | 200 | -0.09999999999999432 | 200 | -0.30000000000001137 | 1200 | -0.6600000000000819 | 100 | -0.29999999999999716 | 200 | -0.30000000000001137 | 200 | -0.09999999999999432
```
**Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT › SENSOR PT100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2022-09-19T00:00:00
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2024-09-19T00:00:00
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
Titik Kalibrasi | Correction | Uncertainty U95%
oC | oC | oC
-20 | -0.13466592594299698 | 0.04
```
**Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT › STANDAR KALIBRATOR**

```
STANDAR METER/INDIKATOR
TABEL RECORD DRIFT
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2024-08-28T00:00:00 | Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2025-08-12T00:00:00 | Std Mode: | Source
Merek | : | Constant | Due Date Kalibrasi | : | 2025-08-28T00:00:00 | Merek | : | Yokogawa | Due Date Kalibrasi | : | 2026-08-12T00:00:00
Type | : | 40T | Tertelusur | : | LK-202-IDN | Type | : | CA 150 Handy Cal | Tertelusur | : | LK-202-IDN | Constant | Yokogawa
S/N | : | 99875850 | S/N | : | 23P1005 | Termocouple | Udrift | Termocouple | Udrift
```
**Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT › STANDAR-CONSTANT**

```
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2024-08-28T00:00:00
Merek | : | Constant | Due Date Kalibrasi | : | 2025-08-29T00:00:00
Type | : | 40T | Tertelusur | : | LK-202-IDN
S/N | : | 99875850
Mode | : | Source (Simulator)
Sensor | : | PRT PT100
```
**Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT › STANDAR-YOKOGAWA**

```
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2025-08-12T00:00:00
Merek | : | Yokogawa | Due Date Kalibrasi | : | 2026-08-12T00:00:00
Type | : | CA 150 Handy Cal | Tertelusur | : | LK-241-IDN
S/N | : | 23P1005
Mode | : | Source (Simulator)
Sensor | : | PRT PT100
```
**Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT › TERMOCOUPLE TYPE K**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2022-07-07T00:00:00
Type | : | Type K | Due Date Kalibrasi | : | 2024-07-06T00:00:00
S/N | : | TCK-01~16 | Tertelusur | : | LK-202-IDN
Tikal | TCK-01 | Correct | Tikal | TCK-02 | Correct | AVERAGE | Titik Kalibrasi | Correction (oC)
0.223 | 0.26 | -0.037000000000000005 | 0.223 | 0.16 | 0.063 | 0.012999999999999998 | oC | TCK-01 | TCK-02 | TCK-03 | TCK-04 | TCK-05 | TCK-06 | TCK-07 | TCK-08 | TCK-09 | TCK-10 | TCK-11 | TCK-12 | TCK-13 | TCK-14 | TCK-
50.062 | 49.91 | 0.15200000000000102 | 50.079 | 49.97 | 0.10900000000000176 | 0.1305000000000014 | 0 | 0.012999999999999998 | 0.012999999999999998 | 0.012999999999999998 | 0.012999999999999998 | 0.012999999999999998 | 0.
```
**Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT › TERMOCOUPLE TYPE N**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2021-08-24T00:00:00
Type | : | Type N | Due Date Kalibrasi | : | 2023-08-24T00:00:00
S/N | : | TCN-01,02 | Tertelusur | : | LK-064-IDN
Titik Kalibrasi | Correction (oC)
Tikal | TC3 | TC4 | AVERAGE | oC | TC3 | TC4 | TC5 | TC6 | TC7 | TC8 | TC9 | TC10 | TC11 | TC12
-20 | 3.42 | 3.86 | 3.6399999999999997 | -20 | 3.6399999999999997 | 3.6399999999999997 | 3.6399999999999997 | 3.6399999999999997 | 3.6399999999999997 | 3.6399999999999997 | 3.6399999999999997 | 3.6399999999999997 | 3.639
```
**Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT › FC Prt Pt100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2022-09-19T00:00:00
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2024-09-19T00:00:00
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
For t ≥0 oC : | For t≤0 oC :
0
0.003878 | A | 0.003501
```
**Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT › Interpolasi**

```
Temp Calibrator : Constant
Titik Kalibrasi | PRT PT100 | Titik Kalibrasi | Thermocouple Type K | Titik Kalibrasi | Thermocouple Type N | Titik Kalibrasi | Thermocouple Type B | Titik Kalibrasi | Thermocouple Type T | Titik Kalibrasi | Thermocouple
-20 | 0 | -20 | -0.10000000000000142 | -20 | -0.10000000000000142 | 600 | -0.39999999999997726 | -100 | -0.15000000000000568 | 0 | -0.1 | 0 | 0.09
0 | 0.2 | 0 | -1 | 0 | -0.2 | 800 | -0.4199999999999591 | 0 | -0.05 | 50 | -0.14999999999999858 | 50 | 0.240000000000002
100 | 0.29999999999999716 | 100 | -0.20000000000000284 | 100 | -0.20000000000000284 | 1000 | -0.5399999999999636 | 50 | -0.03999999999999915 | 100 | -0.12999999999999545 | 100 | 0.20000000000000284
200 | 0.30000000000001137 | 200 | -0.09999999999999432 | 200 | -0.30000000000001137 | 1200 | -0.6600000000000819 | 100 | -0.01999999999999602 | 200 | -0.12999999999999545 | 200 | 0.3100000000000023
```
**Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT › SENSOR PT100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2022-09-19T00:00:00
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2024-09-19T00:00:00
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
Titik Kalibrasi | Correction | Uncertainty U95%
oC | oC | oC
-20 | -0.13466592594299698 | 0.04
```
**Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT › STANDAR KALIBRATOR**

```
STANDAR METER/INDIKATOR
TABEL RECORD DRIFT
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2024-08-28T00:00:00 | Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2025-08-12T00:00:00 | Std Mode: | Source
Merek | : | Constant | Due Date Kalibrasi | : | 2025-08-28T00:00:00 | Merek | : | Yokogawa | Due Date Kalibrasi | : | 2026-08-12T00:00:00 | TGL Update : | 2024-08-14T00:00:00
Type | : | 40T | Tertelusur | : | LK-202-IDN | Type | : | CA 150 Handy Cal | Tertelusur | : | LK-202-IDN | Constant | Yokogawa
S/N | : | 99875850 | S/N | : | 23P1005 | Termocouple | Udrift | Termocouple | Udrift
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-kalibrator-suhu.json`: 1160/1160 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `mode/measure` | tabel_berkunci | 2 | 13 | lebar | drift.RTD·desimal; drift.Type N·desimal; drift.Type K·desimal; drift.Type T·desimal; drift.Type S·desimal; drift.Type R·desimal; drift.Type J·desimal |
| `mode/measure/*/titik` | tabel_anak | 2 | 0 | lebar |  |
| `mode/measure/*/titik/*/RTD` | tabel_anak | 26 | 78 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/measure/*/titik/*/Type K` | tabel_anak | 31 | 93 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/measure/*/titik/*/Type N` | tabel_anak | 33 | 99 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/measure/*/titik/*/Type B` | tabel_anak | 8 | 24 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/measure/*/titik/*/Type T` | tabel_anak | 18 | 54 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/measure/*/titik/*/Type R` | tabel_anak | 28 | 84 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/measure/*/titik/*/Type S` | tabel_anak | 30 | 90 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/measure/*/titik/*/Type J` | tabel_anak | 15 | 45 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/source` | tabel_berkunci | 2 | 13 | lebar | drift.RTD·desimal; drift.Type N·desimal; drift.Type K·desimal; drift.Type T·desimal; drift.Type S·desimal; drift.Type R·desimal; drift.Type J·desimal |
| `mode/source/*/titik` | tabel_anak | 2 | 0 | lebar |  |
| `mode/source/*/titik/*/RTD` | tabel_anak | 26 | 78 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/source/*/titik/*/Type K` | tabel_anak | 31 | 93 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/source/*/titik/*/Type N` | tabel_anak | 33 | 99 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/source/*/titik/*/Type B` | tabel_anak | 8 | 24 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/source/*/titik/*/Type T` | tabel_anak | 17 | 51 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/source/*/titik/*/Type R` | tabel_anak | 28 | 84 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/source/*/titik/*/Type S` | tabel_anak | 30 | 90 | lebar | titik·desimal; koreksi·desimal; u95·desimal |
| `mode/source/*/titik/*/Type J` | tabel_anak | 16 | 48 | lebar | titik·desimal; koreksi·desimal; u95·desimal |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!Q3:S11`, `KALIBRATOR!X7:AA13`, `PERHITUNGAN FC!P35`, `PERHITUNGAN U95%!N22`, `SERTIFIKAT!L20`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-kalibrator-suhu.json:_sumber**: Master Olah Data_Suhu_TITS fungsi Measure utk UUT.xlsm & fungsi Source utk UUT.xlsm (terkunci sandi), sheet 'STANDAR KALIBRATOR' — blok B8:J27 & B30:J49 (Constant 40T) dan M8:U27 & M30:U49 (Yokogawa CA 150 Handy Cal), tabel drift X5:AA13.
- **tabel-kalibrator-suhu.json:_catatan**: Dua MODE, dua tabel yang BEDA ISI (135 sel berbeda antar workbook) — bukan salinan. 'measure' = UUT berfungsi sebagai pembaca, kalibrator yang men-source sinyal; 'source' = kebalikannya. Koreksi & U95 kalibrator memang beda per fungsi, jadi tabelnya tidak boleh dipakai silang.
- **tabel-kalibrator-suhu.json:_kejanggalan**: ["Sel #REF! (kolom Type B & Type S Yokogawa di titik >=600) dibuang jadi kosong, bukan dibaca 0.", "Kolom U95 'Type K' Yokogawa di workbook SOURCE berisi angka NEGATIF (-0,06 s/d -0,31) — itu nilai KOREKSI yang tersalin ke tabel U95. Dibiarkan apa adanya di sini supaya tidak mengarang, tapi TitsCalculator menolaknya (U95 tidak boleh negatif) dan sesi Type K mode Source jatuh ke lantai CMC. Ditanya

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
