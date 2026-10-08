# Volumetric Glassware (fixed & graduated) — paket `volumetric`

| | |
|---|---|
| Kelompok | Volume |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-volumetric.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `VolumetricGlasswareProfile`, `FixedVolumetricGlasswareProfile`, `GraduatedVolumetricGlasswareProfile`, `LabuUkurProfile`, `PipetVolumeProfile`, `PipetUkurProfile`, `GelasUkurProfile`, `BuretProfile`, `PicnometerProfile` |
| Kalkulator | `VolumetricGlasswareCalculator` |
| Kelas tabel | `TabelStandarVolumetric` |
| Formulir resmi | SIDIK-FM-CAL-0513_Rev.4 - LEMBAR KERJA VOLUMETRIK TUNGGAL.pdf, SIDIK-FM-CAL-0514_Rev.4 - LEMBAR KERJA VOLUMETRIK MAJEMUK.pdf |
| Folder master | `Volumetric_Glassware_2026/Fixed_Volumetric_Glassware_2026`, `Volumetric_Glassware_2026/Graduated_Volumetric_Glassware_2026` |
| Generator | `docs/skrip/gen-tabel-standar-volumetric.py` |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-volumetric.md` |
| Serah-terima frontend | `docs/perintah-frontend-volumetric.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarVolumetric` ← `VolumetricGlasswareCalculator`, `VolumetricGlasswareProfile`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarVolumetric.php`
- `app/Services/Calibration/VolumetricGlasswareCalculator.php`
- `app/Services/Calibration/Profiles/VolumetricGlasswareProfile.php`
- `app/Services/Calibration/Profiles/FixedVolumetricGlasswareProfile.php`
- `app/Services/Calibration/Profiles/GraduatedVolumetricGlasswareProfile.php`
- `app/Services/Calibration/Profiles/LabuUkurProfile.php`
- `app/Services/Calibration/Profiles/PipetVolumeProfile.php`
- `app/Services/Calibration/Profiles/PipetUkurProfile.php`
- `app/Services/Calibration/Profiles/GelasUkurProfile.php`
- `app/Services/Calibration/Profiles/BuretProfile.php`
- `app/Services/Calibration/Profiles/PicnometerProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/KategoriProfilLembarKerjaTest.php`
- `tests/Feature/VolumetricGlasswareSesiTest.php`
- `tests/Unit/ProfilDariNamaAlatTest.php`
- `tests/Unit/TabelStandarVolumetricTest.php`
- `tests/Unit/VolumetricGlasswareBudgetTest.php`
- `tests/Unit/VolumetricGlasswareMasterTest.php`
- `tests/Unit/VolumetricGlasswareMentahTest.php`
- `tests/Unit/VolumetricGlasswareSesiTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Fixed_Volumetric_Glassware_2026` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 80 |
| `Fixed_Volumetric_Glassware_2026` | FC_Prt_Pt100 | tab data acuan (bisa disunting lewat versi) | 64 |
| `Fixed_Volumetric_Glassware_2026` | FORM_VALIDASI | sumber nomor versi workbook (F1) | 74 |
| `Fixed_Volumetric_Glassware_2026` | INPUT_DATA | tab Bentuk Lembar (lapis 3) | 36 |
| `Fixed_Volumetric_Glassware_2026` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 52 |
| `Fixed_Volumetric_Glassware_2026` | PERHITUNGAN_U95pct | tab Rumus — baca saja (lapis 2) | 48 |
| `Fixed_Volumetric_Glassware_2026` | SENSOR_PT100 | tab data acuan (bisa disunting lewat versi) | 12 |
| `Fixed_Volumetric_Glassware_2026` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 30 |
| `Fixed_Volumetric_Glassware_2026` | STANDAR-YOKOGAWA | tab data acuan (bisa disunting lewat versi) | 160 |
| `Fixed_Volumetric_Glassware_2026` | STANDARD_KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 46 |
| `Fixed_Volumetric_Glassware_2026` | Tabel_Maximum_Internal_Diameter | tab data acuan (bisa disunting lewat versi) | 46 |
| `Graduated_Volumetric_Glassware_2026` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 80 |
| `Graduated_Volumetric_Glassware_2026` | FC_Prt_Pt100 | tab data acuan (bisa disunting lewat versi) | 64 |
| `Graduated_Volumetric_Glassware_2026` | FORM_VALIDASI | sumber nomor versi workbook (F1) | 79 |
| `Graduated_Volumetric_Glassware_2026` | INPUT_DATA | tab Bentuk Lembar (lapis 3) | 42 |
| `Graduated_Volumetric_Glassware_2026` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 71 |
| `Graduated_Volumetric_Glassware_2026` | PERHITUNGAN_U95pct | tab Rumus — baca saja (lapis 2) | 44 |
| `Graduated_Volumetric_Glassware_2026` | SENSOR_PT100 | tab data acuan (bisa disunting lewat versi) | 12 |
| `Graduated_Volumetric_Glassware_2026` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 34 |
| `Graduated_Volumetric_Glassware_2026` | STANDAR-YOKOGAWA | tab data acuan (bisa disunting lewat versi) | 160 |
| `Graduated_Volumetric_Glassware_2026` | STANDARD_KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 46 |
| `Graduated_Volumetric_Glassware_2026` | Tabel_Koefisien_Muai_Bahan | tab Rumus — baca saja (lapis 2) | 16 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Fixed_Volumetric_Glassware_2026 › FC_Prt_Pt100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2025-02-14 00:00:00 | Link calculator : https://www.fluke.com/en-us/learn/tools-calculators/pt100-calculator?srsltid=AfmBOoqPe_j-N87lgotXxrFrPdjOLAJ5b_2mxDXIuBjJ08Xi
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2027-02-15 00:00:00 [=J2+[6]DATABASE!AF2+1]
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
For t ≥0 oC : | For t≤0 oC :
0 [=D14-1]
Lama: | Terbaru :
```
**Fixed_Volumetric_Glassware_2026 › SENSOR_PT100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2025-02-14 00:00:00 [='[4]FC Prt Pt100'!J2]
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2027-02-15 00:00:00 [=J2+[6]DATABASE!AF2+1]
S/N | : | SH1/20 [=[6]DATABASE!T22] | Tertelusur | : | LK-070-IDN [='[6]FC Prt Pt100'!J4]
Titik Kalibrasi | Correction | Uncertainty U95%
oC | oC | oC
-20 | 0.003969163676529774 [='[4]FC Prt Pt100'!M59] | 0.08 [='[4]FC Prt Pt100'!$B$65]
```
**Fixed_Volumetric_Glassware_2026 › STANDAR-YOKOGAWA**

```
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2025-08-12 00:00:00
Merek | : | Yokogawa | Due Date Kalibrasi | : | 2026-08-12 00:00:00 [=J2+[6]DATABASE!AF3]
Type | : | CA 150 Handy Cal | Tertelusur | : | LK-202-IDN
S/N | : | 23P1005
Mode | : | Measure (Indikator)
Sensor | : | PRT PT100 [=[6]DATABASE!R22]
```
**Fixed_Volumetric_Glassware_2026 › STANDARD_KALIBRATOR**

```
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2025-08-12 00:00:00 [='STANDAR-YOKOGAWA'!J2]
Merek | : | Yokogawa [='STANDAR-YOKOGAWA'!D3] | Due Date Kalibrasi | : | 2026-08-12 00:00:00 [='STANDAR-YOKOGAWA'!J3]
Type | : | CA 150 Handy Cal [='STANDAR-YOKOGAWA'!D4] | Tertelusur | : | LK-202-IDN
S/N | : | 23P1005 [='STANDAR-YOKOGAWA'!D5]
TABEL KOREKSI TEMPERATURE KALIBRATOR
Titik Kalibrasi (oC) | Correction oC
```
**Fixed_Volumetric_Glassware_2026 › Tabel_Maximum_Internal_Diameter**

```
Limit of Volumetric Error, ±µL | Maximum Internal Diameter of Tube at theGraduation Line, mm
0.0001 [=0.1/1000] | 0.56
0.0002 [=0.2/1000] | 0.78
0.0003 [=0.3/1000] | 0.96
0.0004 [=0.4/1000] | 1.1
0.0005 [=0.5/1000] | 1.2
```
**Graduated_Volumetric_Glassware_2026 › FC_Prt_Pt100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2025-02-14 00:00:00 | Link calculator : https://www.fluke.com/en-us/learn/tools-calculators/pt100-calculator?srsltid=AfmBOoqPe_j-N87lgotXxrFrPdjOLAJ5b_2mxDXIuBjJ08Xi
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2027-02-15 00:00:00 [=J2+[6]DATABASE!AF2+1]
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
For t ≥0 oC : | For t≤0 oC :
0 [=D14-1]
Lama: | Terbaru :
```
**Graduated_Volumetric_Glassware_2026 › SENSOR_PT100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2025-02-14 00:00:00 [='[4]FC Prt Pt100'!J2]
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2027-02-15 00:00:00 [=J2+[6]DATABASE!AF2+1]
S/N | : | SH1/20 [=[6]DATABASE!T22] | Tertelusur | : | LK-070-IDN [='[6]FC Prt Pt100'!J4]
Titik Kalibrasi | Correction | Uncertainty U95%
oC | oC | oC
-20 | 0.003969163676529774 [='[4]FC Prt Pt100'!M59] | 0.08 [='[4]FC Prt Pt100'!$B$65]
```
**Graduated_Volumetric_Glassware_2026 › STANDAR-YOKOGAWA**

```
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2025-08-12 00:00:00
Merek | : | Yokogawa | Due Date Kalibrasi | : | 2026-08-12 00:00:00 [=J2+[6]DATABASE!AF3]
Type | : | CA 150 Handy Cal | Tertelusur | : | LK-202-IDN
S/N | : | 23P1005
Mode | : | Measure (Indikator)
Sensor | : | PRT PT100 [=[6]DATABASE!R22]
```
**Graduated_Volumetric_Glassware_2026 › STANDARD_KALIBRATOR**

```
Nama Alat | : | Temperature Calibrator | Tanggal Kalibrasi | : | 2025-08-12 00:00:00 [='STANDAR-YOKOGAWA'!J2]
Merek | : | Yokogawa [='STANDAR-YOKOGAWA'!D3] | Due Date Kalibrasi | : | 2026-08-12 00:00:00 [='STANDAR-YOKOGAWA'!J3]
Type | : | CA 150 Handy Cal [='STANDAR-YOKOGAWA'!D4] | Tertelusur | : | LK-202-IDN
S/N | : | 23P1005 [='STANDAR-YOKOGAWA'!D5]
TABEL KOREKSI TEMPERATURE KALIBRATOR
Titik Kalibrasi (oC) | Correction oC
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-volumetric.json`: 371/371 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `cmc/Gelas Ukur` | tabel | 8 | 16 | lebar | nominal_ml·desimal·mL; cmc_ml·desimal·mL |
| `cmc/Labu Ukur` | tabel | 11 | 22 | lebar | nominal_ml·desimal·mL; cmc_ml·desimal·mL |
| `cmc/Buret` | tabel | 2 | 4 | lebar | nominal_ml·desimal·mL; cmc_ml·desimal·mL |
| `cmc/Picnometer` | tabel | 3 | 6 | lebar | nominal_ml·desimal·mL; cmc_ml·desimal·mL |
| `cmc/Pipet Volume` | tabel | 10 | 20 | lebar | nominal_ml·desimal·mL; cmc_ml·desimal·mL |
| `cmc/Pipet Ukur` | tabel | 5 | 10 | lebar | nominal_ml·desimal·mL; cmc_ml·desimal·mL |
| `diameter_iso4787` | tabel | 45 | 90 | lebar | batas_galat_ml·desimal·mL; diameter_maks_mm·desimal·mm |
| `koefisien_muai` | tabel | 14 | 28 | lebar | material·teks; gamma_per_c·desimal·/°C |
| `koreksi_suhu/kalibrator` | tabel | 13 | 39 | lebar | titik_c·desimal·°C; koreksi_c·desimal·°C; u95_c·desimal·°C |
| `koreksi_suhu/sensor_prt` | tabel | 49 | 98 | lebar | titik_c·desimal·°C; koreksi_c·desimal·°C |
| `koreksi_suhu/u95` | peta | 2 | 2 | lebar | termometer_c·desimal·°C; sensor_c·desimal·°C |
| `neraca/fixed` | tabel | 3 | 18 | lebar | nama·teks; merk_type·teks; serial·desimal_teks; u95_g·desimal·g; resolusi_g·desimal·g; stdev_g·desimal·g |
| `neraca/graduated` | tabel | 3 | 18 | lebar | nama·teks; merk_type·teks; serial·desimal_teks; u95_g·desimal·g; resolusi_g·kosong·g; stdev_g·desimal·g |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!AB22`, `PERHITUNGAN!G16`, `PERHITUNGAN!H45`, `PERHITUNGAN!H57`, `PERHITUNGAN!H58`, `PERHITUNGAN!H60`, `PERHITUNGAN_U95%!C16`, `PERHITUNGAN_U95%!H25`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-volumetric.json:_sumber**: Project-PT-Sidik/alat-alat-Pt-Sidik/Volumetric_Glassware_2026 - DIGENERATE, jangan diketik
- **tabel-standar-volumetric.json:_generator**: docs/skrip/gen-tabel-standar-volumetric.py

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
