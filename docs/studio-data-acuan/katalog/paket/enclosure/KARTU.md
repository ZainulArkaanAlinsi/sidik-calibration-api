# Enclosure — oven, inkubator, bath, furnace, refrigerator — paket `enclosure`

| | |
|---|---|
| Kelompok | Suhu |
| Sumber data acuan hari ini | json — `database/data/tabel-kalibrator-enclosure.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `EnclosureProfileBase`, `OvenProfile`, `InkubatorProfile`, `BathProfile`, `FurnaceProfile`, `RefrigeratorProfile` |
| Kalkulator | `EnclosureCalculator` |
| Kelas tabel | `TabelKalibratorEnclosure` |
| Formulir resmi | SIDIK-FM-CAL-0504_Rev.3 - LEMBAR KERJA ENCLOSURE.pdf |
| Folder master | `suhu_&_kelembapan/Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa`, `suhu_&_kelembapan/Master_Olah_Data_Suhu_Enclosure_Recorder` |
| Generator | `docs/skrip/gen-tabel-kalibrator-enclosure.py` |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-enclosure.md` |
| Serah-terima frontend | `docs/perintah-frontend-enclosure.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelKalibratorEnclosure` ← `EnclosureCalculator`, `EnclosureProfileBase`, `TabelStandarTids`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelKalibratorEnclosure.php`
- `app/Services/Calibration/EnclosureCalculator.php`
- `app/Services/Calibration/Profiles/Enclosure/EnclosureProfileBase.php`
- `app/Services/Calibration/Profiles/Enclosure/OvenProfile.php`
- `app/Services/Calibration/Profiles/Enclosure/InkubatorProfile.php`
- `app/Services/Calibration/Profiles/Enclosure/BathProfile.php`
- `app/Services/Calibration/Profiles/Enclosure/FurnaceProfile.php`
- `app/Services/Calibration/Profiles/Enclosure/RefrigeratorProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/EnclosureGridParsialTest.php`
- `tests/Feature/EnclosureKepalaLembarTest.php`
- `tests/Feature/EnclosureRegresiTest.php`
- `tests/Feature/EnclosureSesiTest.php`
- `tests/Feature/EnclosureStandarTertautTest.php`
- `tests/Feature/ResolusiAlatAutoklafNolDitolakTest.php`
- `tests/Feature/StandarTercetakTerdaftarTest.php`
- `tests/Unit/EnclosureBudgetTest.php`
- `tests/Unit/EnclosureProfilTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | Drawing Enclosure | bukan data — tidak masuk Studio | 13 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | FC Prt Pt100 | tab data acuan (bisa disunting lewat versi) | 68 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | FORM VALIDASI | sumber nomor versi workbook (F1) | 199 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 171 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | Interpolasi | tab data acuan (bisa disunting lewat versi) | 74 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 190 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 171 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | SENSOR PT100 | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 76 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | STANDAR KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 100 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | STANDAR-CONSTANT | tab data acuan (bisa disunting lewat versi) | 130 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | STANDAR-YOKOGAWA | tab data acuan (bisa disunting lewat versi) | 146 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | TERMOCOUPLE TYPE K | tab data acuan (bisa disunting lewat versi) | 132 |
| `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa` | TERMOCOUPLE TYPE N | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | Drawing Enclosure | bukan data — tidak masuk Studio | 13 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | Drift Rec TC Type K | tab data acuan (bisa disunting lewat versi) | 36 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | Drift Rec TC Type N | tab data acuan (bisa disunting lewat versi) | 42 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | FC Prt Pt100 | tab data acuan (bisa disunting lewat versi) | 103 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | FORM VALIDASI | sumber nomor versi workbook (F1) | 187 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 103 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | Interpolasi | tab data acuan (bisa disunting lewat versi) | 103 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | Old_Std Kalibrator | tab data acuan (bisa disunting lewat versi) | 166 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 105 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 98 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | SENSOR PT100 | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 76 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | Standar_Kalibrator | tab data acuan (bisa disunting lewat versi) | 167 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | TERMOCOUPLE TYPE K | tab data acuan (bisa disunting lewat versi) | 132 |
| `Master_Olah_Data_Suhu_Enclosure_Recorder` | TERMOCOUPLE TYPE N | tab data acuan (bisa disunting lewat versi) | 140 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa › FC Prt Pt100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2025-02-14T00:00:00
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2027-02-15T00:00:00
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
For t ≥0 oC : | For t≤0 oC :
0
0.003878 | A | 0.00390954
```
**Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa › Interpolasi**

```
Temp Calibrator : Constant
Titik Kalibrasi | PRT PT100 | Titik Kalibrasi | Thermocouple Type K | Titik Kalibrasi | Thermocouple Type N | Titik Kalibrasi | Thermocouple Type B | Titik Kalibrasi | Thermocouple Type T | Titik Kalibrasi | Thermocouple
-20 | 0.10000000000000142 | -20 | 0.1999999999999993 | -20 | 0.10000000000000142 | 600 | 0.10000000000002274 | -100 | 0.12999999999999545 | 0 | 0.05 | 0 | 0.23
0 | 0.1 | 0 | 0.1 | 0 | 0.1 | 800 | 0.16999999999995907 | 0 | 0.06 | 50 | -0.17999999999999972 | 50 | 0.21000000000000085
200 | 0.09999999999999432 | 100 | -0.09999999999999432 | 100 | 0.09999999999999432 | 1000 | 0.2999999999999545 | 50 | 0.07000000000000028 | 100 | -0.15000000000000568 | 100 | 0.18999999999999773
400 | 0.10000000000002274 | 200 | -0.09999999999999432 | 200 | -0.09999999999999432 | 1200 | 0.31999999999993634 | 100 | 0.10999999999999943 | 200 | -0.12999999999999545 | 200 | 0.30000000000001137
```
**Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa › SENSOR PT100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2025-02-14T00:00:00
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2027-02-15T00:00:00
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
Titik Kalibrasi | Correction | Uncertainty U95%
oC | oC | oC
-20 | 0.003969164084907106 | 0.08
```
**Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa › STANDAR KALIBRATOR**

```
STANDAR METER/INDIKATOR | TABEL RECORD DRIFT
Std Mode: | Measure
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2024-08-28T00:00:00 | Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2025-08-12T00:00:00 | Std Meter
Merek | : | Constant | Due Date Kalibrasi | : | 2025-08-28T00:00:00 | Merek | : | Yokogawa | Due Date Kalibrasi | : | 2026-08-12T00:00:00 | Constant | Yokogawa
Type | : | 40T | Tertelusur | : | LK-202-IDN | Type | : | CA 150 Handy Cal | Tertelusur | : | LK-202-IDN | Termocouple | Udrift | Termocouple | Udrift
S/N | : | 99875850 | S/N | : | 23P1005 | PRT PT100 | 0.23 | PRT PT100 | 0.072
```
**Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa › STANDAR-CONSTANT**

```
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2024-08-28T00:00:00
Merek | : | Constant | Due Date Kalibrasi | : | 2025-08-29T00:00:00
Type | : | 40T | Tertelusur | : | LK-285-IDN
S/N | : | 99875850
Mode | : | Measure (Indikator)
Sensor | : | PRT PT100
```
**Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa › STANDAR-YOKOGAWA**

```
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2025-08-12T00:00:00
Merek | : | Yokogawa | Due Date Kalibrasi | : | 2026-08-12T00:00:00
Type | : | CA 150 Handy Cal | Tertelusur | : | LK-202-IDN
S/N | : | 23P1005
Mode | : | Measure (Indikator)
Sensor | : | PRT PT100
```
**Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa › TERMOCOUPLE TYPE K**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2024-09-06T00:00:00
Type | : | Type K | Due Date Kalibrasi | : | 2026-09-06T00:00:00
S/N | : | TC-01,02 | Tertelusur | : | LK-064-IDN
Tikal | TC-01 Correct | Tikal | TC-02 Corret | AVERAGE | Titik Kalibrasi | Correction (oC)
-20 | -0.21 | -20 | -0.33 | -0.27 | oC | TC-01 | TC-02 | TC-03 | TC-04 | TC-05 | TC-06 | TC-07 | TC-08 | TC-09 | TC-10 | TC-11 | TC-12 | TC-13 | TC-14 | TC-15 | TC-16
0 | -0.06 | 0 | -0.1 | -0.08 | -20 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27
```
**Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa › TERMOCOUPLE TYPE N**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2024-09-06T00:00:00
Type | : | Type N | Due Date Kalibrasi | : | 2026-09-06T00:00:00
S/N | : | TCN-06,11 | Tertelusur | : | LK-064-IDN
Titik Kalibrasi | Correction (oC)
Tikal | TCN-06 | TCN-11 | AVERAGE | oC | TC6 | TC7 | TC8 | TC9 | TC10 | TC11 | TC12 | TC13 | TC14 | TC15
-20 | -0.24 | -0.06 | -0.15 | -20 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15
```
**Master_Olah_Data_Suhu_Enclosure_Recorder › Drift Rec TC Type K**

```
Nama Alat | : | Temperature Recorder
Merk | : | Graptech
Type | : | GL840
No. Seri | : | C305B1470
Range | : | 2000 | oC
Interval Kalibrasi | : | 1 | tahun
```
**Master_Olah_Data_Suhu_Enclosure_Recorder › Drift Rec TC Type N**

```
Nama Alat | : | Temperature Recorder
Merk | : | Graptech
Type | : | GL840
No. Seri | : | C305B1470
Range | : | 2000 | oC
Interval Kalibrasi | : | 1 | tahun
```
**Master_Olah_Data_Suhu_Enclosure_Recorder › FC Prt Pt100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2022-09-19T00:00:00
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2024-09-19T00:00:00
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
For t ≥0 oC : | For t≤0 oC :
0
0.003878 | A | 0.003501
```
**Master_Olah_Data_Suhu_Enclosure_Recorder › Interpolasi**

```
Titik Kalibrasi | Recorder+Type N
CH1 | CH2 | CH3 | CH4 | CH5 | CH6 | CH7 | CH8 | CH9 | CH10 | CH11 | CH12 | CH13 | CH14 | CH15 | CH16 | CH17 | CH18 | CH19 | CH20
-20 | 0.1 | -0.3 | 0 | -0.1 | -0.1 | 0 | 0 | -0.3 | -0.2 | -0.3 | -0.3 | -0.2 | -0.2 | -0.2 | 0 | 0 | -0.1 | 0 | -0.1 | -0.2
0 | -0.4 | -0.3 | -0.3 | -0.3 | -0.3 | -0.3 | -0.2 | -0.4 | -0.4 | 0 | -0.4 | -0.3 | -0.4 | -0.2 | -0.2 | -0.2 | -0.4 | -0.2 | -0.2 | -0.4
100 | -0.3 | -0.1 | -0.1 | -0.3 | -0.2 | -0.1 | 0.1 | 0 | 0 | 0 | -0.4 | -0.2 | -0.2 | -0.1 | -0.2 | -0.2 | -0.2 | -0.2 | -0.2 | -0.3
200 | -0.5 | -0.1 | -0.2 | -0.3 | -0.3 | -0.2 | -0.3 | -0.2 | -0.2 | -0.3 | -0.3 | -0.5 | -0.5 | -0.4 | -0.4 | -0.4 | -0.4 | -0.4 | -0.3 | -0.4
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-kalibrator-enclosure.json`: 2639/2639 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `meter/constant` | tabel_berkunci | 3 | 189 | panjang | PT100.-100·desimal; PT100.-20·desimal; PT100.0·desimal; PT100.25·desimal; PT100.50·desimal; PT100.100·desimal; PT100.200·desimal; PT100.300·desimal; PT100.400·desimal; PT100.500·desimal; … +87 |
| `meter/yokogawa` | tabel_berkunci | 3 | 93 | panjang | PT100.-100·desimal; PT100.-20·desimal; PT100.0·desimal; PT100.25·desimal; PT100.50·desimal; PT100.100·desimal; PT100.200·desimal; PT100.300·desimal; PT100.400·desimal; PT100.500·desimal; … +38 |
| `meter/recorder/koreksi` | tabel_berkunci | 2 | 640 | panjang | 1.-20·desimal; 1.0·desimal; 1.10·desimal; 1.25·desimal; 1.50·desimal; 1.100·desimal; 1.150·desimal; 1.200·desimal; 1.300·desimal; 1.400·desimal; … +310 |
| `meter/recorder/u95` | tabel_berkunci | 2 | 600 | panjang | 1.0·desimal; 1.25·desimal; 1.50·desimal; 1.100·desimal; 1.150·desimal; 1.200·desimal; 1.300·desimal; 1.400·desimal; 1.500·desimal; 1.600·desimal; … +290 |
| `meter/recorder/drift` | peta | 2 | 2 | lebar | Type N·desimal; Type K·desimal |
| `sensor/yoko/koreksi` | tabel_berkunci | 2 | 294 | panjang | 1.0·desimal; 1.25·desimal; 1.50·desimal; 1.100·desimal; 1.150·desimal; 1.200·desimal; 1.300·desimal; 1.400·desimal; 1.500·desimal; 2.0·desimal; … +194 |
| `sensor/yoko/u95` | tabel_berkunci | 2 | 278 | panjang | 1.0·desimal; 1.25·desimal; 1.50·desimal; 1.100·desimal; 1.150·desimal; 1.200·desimal; 1.300·desimal; 1.400·desimal; 2.0·desimal; 2.25·desimal; … +188 |
| `sensor/yoko/drift` | peta | 3 | 3 | lebar | PT100·desimal; Type N·desimal; Type K·desimal |
| `sensor/recorder/koreksi` | tabel_berkunci | 2 | 268 | panjang | 3.0·desimal; 3.25·desimal; 3.50·desimal; 3.100·desimal; 3.150·desimal; 3.200·desimal; 3.300·desimal; 3.400·desimal; 3.500·desimal; 3.600·desimal; … +178 |
| `sensor/recorder/u95` | tabel_berkunci | 2 | 268 | panjang | 3.0·desimal; 3.25·desimal; 3.50·desimal; 3.100·desimal; 3.150·desimal; 3.200·desimal; 3.300·desimal; 3.400·desimal; 3.500·desimal; 3.600·desimal; … +178 |
| `sensor/recorder/drift` | peta | 2 | 2 | lebar | Type N·desimal; Type K·desimal |
| `index_temps` | peta | 2 | 2 | lebar | yoko·daftar; recorder·daftar |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!V13`, `SERTIFIKAT!F26`

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
