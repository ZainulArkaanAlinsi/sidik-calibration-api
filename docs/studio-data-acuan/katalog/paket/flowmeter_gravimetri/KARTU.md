# Flowmeter — gravimetri (ISO 4185) — paket `flowmeter_gravimetri`

| | |
|---|---|
| Kelompok | Aliran |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-flowmeter-gravimetri.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `FlowmeterProfile` |
| Kalkulator | `FlowmeterGravimetriCalculator` |
| Kelas tabel | `TabelStandarFlowmeterGravimetri` |
| Formulir resmi | SIDIK-FM-CAL-0538.A-Rev.3 LEMBAR KERJA FLOWMETER-FLOWRATE.pdf, SIDIK-FM-CAL-0538.B-Rev.3 LEMBAR KERJA FLOWMETER-TOTALIZER.pdf |
| Folder master | `Aliran_2/1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_`, `Aliran_2/2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` |
| Generator | `docs/skrip/gen-tabel-standar-flowmeter-gravimetri.py` |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-flowmeter-gravimetri.md` |
| Serah-terima frontend | `docs/perintah-frontend-flowmeter-gravimetri.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarFlowmeterGravimetri` ← `FlowmeterGravimetriCalculator`, `FlowmeterProfile`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarFlowmeterGravimetri.php`
- `app/Services/Calibration/FlowmeterGravimetriCalculator.php`
- `app/Services/Calibration/Profiles/FlowmeterProfile.php`

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
| `1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 111 |
| `1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | Drift Constant-TC Type K | tab data acuan (bisa disunting lewat versi) | 34 |
| `1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | Drift Timbangan Dini Argeo | tab data acuan (bisa disunting lewat versi) | 43 |
| `1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | Drift Timbangan Mettler | tab data acuan (bisa disunting lewat versi) | 43 |
| `1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | Drift Timbangan Sartorius | tab data acuan (bisa disunting lewat versi) | 41 |
| `1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | Drift Yokogawa-TC Type K | tab data acuan (bisa disunting lewat versi) | 35 |
| `1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | FORM VALIDASI | sumber nomor versi workbook (F1) | 209 |
| `1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 62 |
| `1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 83 |
| `1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 100 |
| `1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 57 |
| `1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_` | STANDAR KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 130 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 109 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | Drift Constant-TC Type K | tab data acuan (bisa disunting lewat versi) | 34 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | Drift Timbangan Dini Argeo | tab data acuan (bisa disunting lewat versi) | 43 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | Drift Timbangan Mettler | tab data acuan (bisa disunting lewat versi) | 41 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | Drift Timbangan Sartorius | tab data acuan (bisa disunting lewat versi) | 41 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | Drift Timer Software Flowmeter | tab data acuan (bisa disunting lewat versi) | 30 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | Drift Yokogawa-TC Type K | tab data acuan (bisa disunting lewat versi) | 35 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | FORM VALIDASI | sumber nomor versi workbook (F1) | 207 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 65 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 92 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 87 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 52 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | STANDAR KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 117 |
| `2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026` | Unit Converter | tab data acuan (bisa disunting lewat versi) | 10 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_ › Drift Constant-TC Type K**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Temperature Calibrator
Merk | : | Constant
Type | : | 40T (measure type K)
No. Seri | : | 992613877
Range | : | 1200 | oC
```
**1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_ › Drift Timbangan Dini Argeo**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Timbangan
Merk | : | Dini Argeo
Type | : | DFWLB-3
No. Seri | : | 0792531584
Range | : | 2000 | kg
```
**1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_ › Drift Timbangan Mettler**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Timbangan
Merk | : | Mettler
Type | : | DJ Series
No. Seri | : | HSEX1403752
Range | : | 3000 | g
```
**1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_ › Drift Timbangan Sartorius**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Timbangan
Merk | : | Sartorius
Type | : | MWP1P1-150GF-L
No. Seri | : | 34166240
Range | : | 150 | kg
```
**1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_ › Drift Yokogawa-TC Type K**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Temperature Calibrator
Merk | : | Yokogawa
Type | : | CA150 Handy Cal (measure type K)
No. Seri | : | 23P1005
Range | : | 1200 | oC
```
**1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_ › STANDAR KALIBRATOR**

```
STANDAR KALIBRATOR
Nama Alat | : | Timbangan Digital | Tanggal Kalibrasi | : | 2025-08-08T00:00:00 | Nama Alat | : | Timbangan Digital | Tanggal Kalibrasi | : | 2025-07-07T00:00:00
Merek | : | Dini Argeo | Due Date Kalibrasi | : | 2026-08-08T00:00:00 | Merek | : | Sartorius | Due Date Kalibrasi | : | 2026-07-07T00:00:00
Type | : | DFWLB-3 | Tertelusur | : | LK-285-IDN | Type | : | 150GF | Tertelusur | : | LK-285-IDN
S/N | : | 0792531584 | Resolusi | : | 0.1 | kg | S/N | : | 34166240 | Resolusi | : | 0.01 | kg
U95 % (kg) | 0.52 | Weight Correction | U95 % (kg) | 0.033 | Weight Correction
```
**2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026 › Drift Constant-TC Type K**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Temperature Calibrator
Merk | : | Constant
Type | : | 40T (measure type K)
No. Seri | : | 992613877
Range | : | 1200 | oC
```
**2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026 › Drift Timbangan Dini Argeo**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Timbangan
Merk | : | Dini Argeo
Type | : | DFWLB-3
No. Seri | : | 0792531584
Range | : | 2000 | kg
```
**2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026 › Drift Timbangan Mettler**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Timbangan
Merk | : | Mettler
Type | :
No. Seri | : | 34166240
Range | : | 30 | kg
```
**2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026 › Drift Timbangan Sartorius**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Timbangan
Merk | : | Sartorius
Type | : | MWP1P1-150GF-L
No. Seri | : | 34166240
Range | : | 150 | kg
```
**2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026 › Drift Timer Software Flowmeter**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Timer
Merk | : | Weight Scale
Type | : | -
No. Seri | : | Timer.FM.1
Range | : | 60 | s
```
**2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026 › Drift Yokogawa-TC Type K**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Temperature Calibrator
Merk | : | Yokogawa
Type | : | CA150 Handy Cal (measure type K)
No. Seri | : | 23P1005
Range | : | 1200 | oC
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-flowmeter-gravimetri.json`: 318/318 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `timbangan/totalizer` | tabel_berkunci | 4 | 56 | lebar | kode·desimal; nama·teks; nama_kertas·kosong; merk·teks; tipe·teks; seri·desimal_teks; tertelusur·teks; tanggal_kalibrasi·tanggal; tanggal_jatuh_tempo·tanggal; u95_kg·desimal·kg; … +4 |
| `timbangan/totalizer/*/koreksi` | tabel_anak | 40 | 80 | lebar | titik·desimal; koreksi_kg·desimal·kg |
| `timbangan/flowrate` | tabel_berkunci | 3 | 42 | lebar | kode·desimal; nama·teks; nama_kertas·kosong; merk·teks; tipe·teks; seri·desimal_teks; tertelusur·teks; tanggal_kalibrasi·tanggal; tanggal_jatuh_tempo·tanggal; u95_kg·desimal·kg; … +4 |
| `timbangan/flowrate/*/koreksi` | tabel_anak | 30 | 60 | lebar | titik·desimal; koreksi_kg·desimal·kg |
| `densitas_air` | peta | 5 | 5 | lebar | volume_piknometer_ml·desimal·mL; tanggal_ukur·tanggal; pic·teks; tertelusur·teks; interpolasi·teks |
| `densitas_air/titik` | tabel | 4 | 12 | lebar | suhu_c·desimal·°C; tertimbang_g·desimal·g; densitas_kg_per_l·desimal·L |
| `kalibrator_suhu` | peta | 8 | 8 | lebar | nama·teks; merk·teks; tipe·teks; seri·teks; tertelusur·teks; tanggal_kalibrasi·tanggal; tanggal_jatuh_tempo·tanggal; drift_c·desimal·°C |
| `kalibrator_suhu/titik` | tabel | 6 | 18 | lebar | pembacaan_c·desimal·°C; koreksi_c·desimal·°C; u95_c·desimal·°C |
| `sensor_suhu` | peta | 7 | 7 | lebar | nama·teks; tipe·teks; seri·teks; tertelusur·teks; tanggal_kalibrasi·tanggal; tanggal_jatuh_tempo·tanggal; u95_c·desimal·°C |
| `timer` | peta | 10 | 10 | lebar | nama·teks; merk·teks; tipe·teks; seri·teks; tertelusur·teks; tanggal_kalibrasi·tanggal; tanggal_jatuh_tempo·tanggal; u95_s·desimal·s; u95_min·desimal; drift_min·desimal |
| `timer/titik` | tabel | 4 | 8 | lebar | titik_min·desimal; koreksi_min·desimal |
| `konstanta` | peta | 12 | 12 | lebar | densitas_udara_kg_per_l·desimal·L; densitas_anak_timbang_kg_per_l·desimal·L; u95_densitas_air_kg_per_l·desimal·L; koefisien_muai_air_per_c·desimal·/°C; persen_bouyancy·desimal; pembagi_rect·desimal; pembagi_normal·desimal; pembagi_drift·desimal; pembagi_ut_water·desimal; vi_type_b·desimal; vi_normal·desimal; volume_pipa_l·desimal·L |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!R5:S6`, `INPUT DATA!D49:D51`, `INPUT DATA!X24`, `PERHITUNGAN FC!B74`, `PERHITUNGAN FC!D44:D46`, `PERHITUNGAN FC!D66`, `STANDAR KALIBRATOR!N61:P65`, `STANDAR KALIBRATOR!P66:P80`, `STANDAR KALIBRATOR!Q51`, `static weighing method. PERHITUNGAN FC!B74`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-flowmeter-gravimetri.json:_sumber**: 1.2 Master olda Flowmeter Totalizer dini (2026) 140-2500L.xlsm & 2.1 Master olda Flowmeter Flowrate 100-980lpm 2026.xlsm (ber-password). Digenerate docs/skrip/gen-tabel-standar-flowmeter-gravimetri.py — jangan diketik tangan.
- **tabel-standar-flowmeter-gravimetri.json:_metode**: ISO 4185 — static weighing method. PERHITUNGAN FC!B74 menulis judulnya sendiri.
- **tabel-standar-flowmeter-gravimetri.json:_penyimpangan**: {"timbangan_3_beda_alat": "Kedua workbook menyimpan timbangan ke-3 dengan tipe, S/N, nomor akreditasi, tanggal kalibrasi, DAN satuan tabel koreksi yang berbeda. Keduanya disimpan; dipilih per mode. Ditambah nama KETIGA: kertas lembar kerja Rev.3 menyebutnya `Excellent`, bukan `Mettler` — disimpan di `nama_kertas`. Pertanyaan lab §15.", "drift_tanpa_akar3": "Judul kolom sheet drift menulis 0,5·ΔC/√

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
