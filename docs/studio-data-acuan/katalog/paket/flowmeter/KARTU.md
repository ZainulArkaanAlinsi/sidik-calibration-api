# Flowmeter — pembanding UFM — paket `flowmeter`

| | |
|---|---|
| Kelompok | Aliran |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-flowmeter.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `FlowmeterProfile`, `FlowmeterFlowrateProfile`, `FlowmeterTotalizerProfile` |
| Kalkulator | `FlowmeterCalculator` |
| Kelas tabel | `TabelStandarFlowmeter` |
| Formulir resmi | SIDIK-FM-CAL-0538-Rev.0 LEMBAR KERJA FLOWMETER (Perbandingan Langsung dengan UFM).pdf, SIDIK-FM-CAL-0538.A-Rev.3 LEMBAR KERJA FLOWMETER-FLOWRATE.pdf, SIDIK-FM-CAL-0538.B-Rev.3 LEMBAR KERJA FLOWMETER-TOTALIZER.pdf |
| Folder master | `Aliran_2/1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026`, `Aliran_2/Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026` |
| Generator | `docs/skrip/gen-tabel-standar-flowmeter.py` |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-flowmeter.md` |
| Serah-terima frontend | `docs/perintah-frontend-flowmeter.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarFlowmeter` ← `AuditFlowmeterCmc`, `CalibrationRequest`, `FlowmeterCalculator`, `FlowmeterFlowrateProfile`, `FlowmeterGravimetriCalculator`, `FlowmeterProfile`, `FlowmeterTotalizerProfile`, `TabelStandarFlowmeterGravimetri`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarFlowmeter.php`
- `app/Services/Calibration/FlowmeterCalculator.php`
- `app/Services/Calibration/Profiles/FlowmeterProfile.php`
- `app/Services/Calibration/Profiles/FlowmeterFlowrateProfile.php`
- `app/Services/Calibration/Profiles/FlowmeterTotalizerProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/AlurPenuhFlowmeterTest.php`
- `tests/Feature/AuditFlowmeterCmcTest.php`
- `tests/Feature/FlowmeterLantaiCmcTest.php`
- `tests/Feature/FlowmeterSatuanSertifikatTest.php`
- `tests/Feature/FlowmeterSertifikatTest.php`
- `tests/Feature/FlowmeterSesiTest.php`
- `tests/Feature/FlowmeterVarianTest.php`
- `tests/Unit/FlowmeterGravimetriGerbangTest.php`
- `tests/Unit/FlowmeterGravimetriMasterTest.php`
- `tests/Unit/FlowmeterMasterTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 114 |
| `1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026` | Drift Yokogawa-TC Type K | tab data acuan (bisa disunting lewat versi) | 35 |
| `1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026` | FORM VALIDASI | sumber nomor versi workbook (F1) | 140 |
| `1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 67 |
| `1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 80 |
| `1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 126 |
| `1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 55 |
| `1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026` | STANDAR KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 86 |
| `Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 110 |
| `Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026` | Drift Timer Software Flowmeter | tab data acuan (bisa disunting lewat versi) | 30 |
| `Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026` | Drift Yokogawa-TC Type K | tab data acuan (bisa disunting lewat versi) | 35 |
| `Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026` | FORM VALIDASI | sumber nomor versi workbook (F1) | 141 |
| `Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 66 |
| `Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 81 |
| `Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 103 |
| `Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 61 |
| `Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026` | STANDAR KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 96 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026 › Drift Yokogawa-TC Type K**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Temperature Calibrator
Merk | : | Yokogawa
Type | : | CA150 Handy Cal (measure type K)
No. Seri | : | 23P1005
Range | : | 1200 | oC
```
**1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026 › STANDAR KALIBRATOR**

```
STANDAR KALIBRATOR
Nama Alat | : | Ultrasonic Flowmeter Totalizer | Tanggal Kalibrasi | : | 2025-08-08T00:00:00 | Nama Alat | : | Ultrasonic Flowmeter Flowrate | Tanggal Kalibrasi | : | 2025-08-08T00:00:00
Merek | : | KROHNE | Due Date Kalibrasi | : | 2026-08-08T00:00:00 | Merek | : | KROHNE | Due Date Kalibrasi | : | 2026-08-08T00:00:00
Type | : | UFC300 | Tertelusur | : | LK-285-IDN | Type | : | UFC300 | Tertelusur | : | LK-285-IDN
S/N | : | A18P045140 | Resolusi | : | 0.001 | L | S/N | : | A18P045140 | Resolusi | : | 0.001 | Lpm
Standard | Unit Under Test | Koreksi | Ketidakpastian | Ketidakpastian | Standard | Unit Under Test | Koreksi | Ketidakpastian | Ketidakpastian
```
**Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026 › Drift Timer Software Flowmeter**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Timer
Merk | : | Weight Scale
Type | : | -
No. Seri | : | Timer.FM.1
Range | : | 60 | s
```
**Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026 › Drift Yokogawa-TC Type K**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Temperature Calibrator
Merk | : | Yokogawa
Type | : | CA150 Handy Cal (measure type K)
No. Seri | : | 23P1005
Range | : | 1200 | oC
```
**Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026 › STANDAR KALIBRATOR**

```
STANDAR KALIBRATOR
Nama Alat | : | Ultrasonic Flowmeter Totalizer | Tanggal Kalibrasi | : | 2025-08-08T00:00:00 | Nama Alat | : | Ultrasonic Flowmeter Flowrate | Tanggal Kalibrasi | : | 2025-08-08T00:00:00
Merek | : | KROHNE | Due Date Kalibrasi | : | 2026-08-08T00:00:00 | Merek | : | KROHNE | Due Date Kalibrasi | : | 2026-08-08T00:00:00
Type | : | UFC300 | Tertelusur | : | LK-285-IDN | Type | : | UFC300 | Tertelusur | : | LK-285-IDN
S/N | : | A18P045140 | Resolusi | : | 0.001 | L | S/N | : | A18P045140 | Resolusi | : | 0.001 | Lpm
Standard | Unit Under Test | Koreksi | Ketidakpastian | Ketidakpastian | Standard | Unit Under Test | Koreksi | Ketidakpastian | Ketidakpastian
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-flowmeter.json`: 136/136 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `std_totalizer` | tabel | 4 | 20 | lebar | standard·desimal; uut·desimal; koreksi·desimal; persen_of_reading·desimal; u·desimal |
| `std_flowrate` | tabel | 3 | 15 | lebar | standard·desimal; uut·desimal; koreksi·desimal; persen_of_reading·desimal; u·desimal |
| `cmc/totalizer` | tabel | 2 | 10 | lebar | label·teks; min·desimal; maks·desimal; satuan·teks; cmc_persen_of_reading·desimal |
| `cmc/flowrate` | tabel | 2 | 10 | lebar | label·teks; min·desimal; maks·desimal; satuan·teks; cmc_persen_of_reading·desimal |
| `standar` | tabel_berkunci | 6 | 48 | lebar | nama·teks; merk·teks; tipe·teks; seri·teks; tertelusur·teks; tanggal_kalibrasi·tanggal; tanggal_jatuh_tempo·tanggal; resolusi·desimal; u95_c·desimal·°C; u95_mm·desimal·mm; … +1 |
| `konstanta` | peta | 22 | 22 | lebar | pi·desimal; pembagi_rect·desimal; pembagi_normal·desimal; vi_type_b·desimal; vi_standar_ufm·desimal; vi_suhu_totalizer·desimal; vi_suhu_flowrate·desimal; pembagi_cross_section_totalizer·desimal; pembagi_cross_section_flowrate·desimal; velocity_profile_persen·desimal·%; geometry_factor_persen·desimal·%; drift_ufm_persen·desimal·%; … +10 |
| `satuan` | tabel_berkunci | 2 | 11 | lebar | L·desimal; m3·desimal; usg·desimal; ml·desimal; kg·kosong; LPM·desimal; m3/h·desimal; usg/min·desimal; m3/min·desimal; kg/h·kosong; … +1 |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!R5:S6`, `DATABASE!S22`, `DATABASE!S25`, `DATABASE!S42`, `DATABASE!T17`, `DATABASE!U27`, `DATABASE!V15`, `PERHITUNGAN FC!Q28`, `PERHITUNGAN U95%!D41`, `PERHITUNGAN U95%!F13`, `PERHITUNGAN U95%!I24`, `SERTIFIKAT!E26`, `STANDAR KALIBRATOR!D39`, `STANDAR KALIBRATOR!E32`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-flowmeter.json:_sumber**: 1.3 Master Olah Data Flowmeter_Ultrasonic Totalizer (1000-1900L) 2026.xlsm & Master olda Ultrasonic Flowrate (100-300 lpm) 2026.xlsm (ber-password). Digenerate docs/skrip/gen-tabel-standar-flowmeter.py — jangan diketik tangan.
- **tabel-standar-flowmeter.json:_catatan_baris_kosong**: std_totalizer menyapu 12 baris di master (4 terisi), std_flowrate 9 baris (3 terisi). Baris kosong SENGAJA tidak disalin: di master dia dibaca 0 oleh MIN(ABS(...)) dan menerbitkan #N/A ke sertifikat.
- **tabel-standar-flowmeter.json:_catatan_validasi**: FORM VALIDASI kolom VALIDATION KOSONG di kedua master (J7/K8). Angka di sini berasal dari master yang belum lolos validasi internalnya sendiri — lihat docs/pertanyaan-lab-flowmeter.md §1.

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
