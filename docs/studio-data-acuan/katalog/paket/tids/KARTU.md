# TIDS (indikator suhu digital) — paket `tids`

| | |
|---|---|
| Kelompok | Suhu |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-tids.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `TidsProfile` |
| Kalkulator | `TidsCalculator` |
| Kelas tabel | `TabelStandarTids` |
| Formulir resmi | SIDIK-FM-CAL-0506_Rev.4 - LEMBAR KERJA TIDS.pdf |
| Folder master | `suhu_&_kelembapan/Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_`, `suhu_&_kelembapan/Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` |
| Generator | — |
| Fixture master | `database/data/tids-cache-master.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-tids-workbook.md` |
| Serah-terima frontend | `docs/perintah-frontend-tids.md` |

**Juga membaca paket:** `tits` — simulasi wajib menjalankan pemakai paket itu.

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarTids` ← `TidsCalculator`, `TidsProfile`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarTids.php`
- `app/Services/Calibration/TidsCalculator.php`
- `app/Services/Calibration/Profiles/TidsProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/Suhu3AlatLembarKerjaTest.php`
- `tests/Feature/ThermohygroSemuaLembarTest.php`
- `tests/Feature/TidsLembarKerjaTest.php`
- `tests/Feature/TidsRoutingTidakBisaDitembusTest.php`
- `tests/Feature/TidsU95TidakBocorTest.php`
- `tests/Feature/WorksheetExtractionTest.php`
- `tests/Unit/ProfilDariNamaAlatTest.php`
- `tests/Unit/RoutingProfilSepakatTest.php`
- `tests/Unit/TidsMasterTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 109 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | Drift Rec TC Type K | tab data acuan (bisa disunting lewat versi) | 35 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | Drift Rec TC Type N | tab data acuan (bisa disunting lewat versi) | 42 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | FC Prt Pt100 | tab data acuan (bisa disunting lewat versi) | 67 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | FORM VALIDASI | sumber nomor versi workbook (F1) | 191 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 70 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | Interpolasi | tab data acuan (bisa disunting lewat versi) | 103 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 56 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 48 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | SENSOR PT100 | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 64 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | Standar_Recorder | tab data acuan (bisa disunting lewat versi) | 166 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | TERMOCOUPLE TYPE K | tab data acuan (bisa disunting lewat versi) | 132 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | TERMOCOUPLE TYPE N | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | Variasi axial Dryblok A | tab data acuan (bisa disunting lewat versi) | 43 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | Variasi axial Dryblok B | tab data acuan (bisa disunting lewat versi) | 50 |
| `Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_` | stdev drywell | tab data acuan (bisa disunting lewat versi) | 21 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 109 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | FC Prt Pt100 | tab data acuan (bisa disunting lewat versi) | 68 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | FORM VALIDASI | sumber nomor versi workbook (F1) | 203 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 70 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | Interpolasi | tab data acuan (bisa disunting lewat versi) | 60 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 56 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 48 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | SENSOR PT100 | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 63 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | STANDAR KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 100 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | STANDAR-CONSTANT | tab data acuan (bisa disunting lewat versi) | 130 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | STANDAR-YOKOGAWA | tab data acuan (bisa disunting lewat versi) | 170 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | TERMOCOUPLE TYPE K | tab data acuan (bisa disunting lewat versi) | 132 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | TERMOCOUPLE TYPE N | tab data acuan (bisa disunting lewat versi) | 140 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | Variasi axial Dryblok A | tab data acuan (bisa disunting lewat versi) | 43 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | Variasi axial Dryblok B | tab data acuan (bisa disunting lewat versi) | 50 |
| `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N` | stdev drywell | tab data acuan (bisa disunting lewat versi) | 21 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_ › Drift Rec TC Type K**

```
Nama Alat | : | Temperature Recorder
Merk | : | Graptech
Type | : | GL840
No. Seri | : | C305B1470
Range | : | 2000 | oC
Interval Kalibrasi | : | 1 | tahun
```
**Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_ › Drift Rec TC Type N**

```
Nama Alat | : | Temperature Recorder
Merk | : | Graptech
Type | : | GL840
No. Seri | : | C305B1470
Range | : | 2000 | oC
Interval Kalibrasi | : | 1 | tahun
```
**Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_ › FC Prt Pt100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2025-02-14T00:00:00
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2027-02-15T00:00:00
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
For t ≥0 oC : | For t≤0 oC :
0
Terbaru :
```
**Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_ › Interpolasi**

```
Titik Kalibrasi | Recorder+Type N
CH1 | CH2 | CH3 | CH4 | CH5 | CH6 | CH7 | CH8 | CH9 | CH10 | CH11 | CH12 | CH13 | CH14 | CH15 | CH16 | CH17 | CH18 | CH19 | CH20
-20 | 0.1 | -0.2 | 0 | 0 | 0 | 0.1 | 0 | -0.2 | -0.1 | -0.2 | -0.2 | -0.2 | -0.1 | -0.1 | -0.1 | 0.1 | 0 | 0 | 0 | -0.1
0 | -0.3 | -0.2 | -0.2 | -0.2 | -0.2 | -0.2 | -0.1 | -0.3 | -0.3 | 0.1 | -0.3 | -0.2 | -0.3 | -0.1 | -0.2 | -0.1 | -0.3 | -0.1 | -0.1 | -0.3
100 | -0.2 | 0.1 | 0 | -0.2 | 0 | 0 | 0.2 | 0.1 | 0.1 | 0.2 | -0.2 | -0.1 | -0.1 | 0.1 | 0 | 0 | -0.1 | -0.1 | 0 | -0.2
200 | -0.2 | 0.2 | 0 | 0 | 0 | 0 | -0.1 | 0.1 | 0.1 | 0 | 0 | -0.2 | -0.2 | -0.1 | -0.1 | -0.1 | -0.2 | -0.1 | 0 | -0.1
```
**Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_ › SENSOR PT100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2025-02-14T00:00:00
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2027-02-15T00:00:00
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
Titik Kalibrasi | Correction | Uncertainty U95%
oC | oC | oC
-20 | 0.00342684530068027 | 0.08
```
**Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_ › Standar_Recorder**

```
Nama Alat | : | Temperature Recorder | Tanggal Kalibrasi | : | 2025-05-08T00:00:00
Type | : | GL840 | Due Date Kalibrasi | : | 2026-05-08T00:00:00 | TABEL RECORD DRIFT
S/N | : | C305B1470 | Tertelusur | : | LK-285-IDN | Std Mode: | Measure
Std Meter
TABEL NILAI KOREKSI TEMPERATURE RECORDER | Recorder
Titik Kalibrasi | Correction (oC) Type N | Correction (oC) Type K | Termocouple | Udrift
```
**Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_ › TERMOCOUPLE TYPE K**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2024-09-06T00:00:00
Type | : | Type K | Due Date Kalibrasi | : | 2026-09-06T00:00:00
S/N | : | TCK-01~16 | Tertelusur | : | LK-064-IDN
Tikal | TC-01 Correct | Tikal | TC-02 Corret | AVERAGE | Titik Kalibrasi | Correction (oC)
-20 | -0.21 | -20 | -0.33 | -0.27 | oC | TCK-01 | TCK-02 | TCK-03 | TCK-04 | TCK-05 | TCK-06 | TCK-07 | TCK-08 | TCK-09 | TCK-10 | TCK-11 | TCK-12 | TCK-13 | TCK-14 | TCK-15 | TCK-16
0 | -0.06 | 0 | -0.1 | -0.08 | 0 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27 | -0.27
```
**Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_ › TERMOCOUPLE TYPE N**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2024-09-06T00:00:00
Type | : | Type N | Due Date Kalibrasi | : | 2026-09-06T00:00:00
S/N | : | TCN-06,11 | Tertelusur | : | LK-064-IDN
Titik Kalibrasi | Correction (oC)
Tikal | TCN-06 | TCN-11 | AVERAGE | oC | TC3 | TC4 | TC5 | TC6 | TC7 | TC8 | TC9 | TC10 | TC11 | TC12
-20 | -0.24 | -0.06 | -0.15 | -20 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15 | -0.15
```
**Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_ › Variasi axial Dryblok A**

```
Variasi Axial
Owner : | PT. Sistem Dirgantara Inovasi Teknologi | Type : | Fast Cal-Low
TGL Tes : | 2024-07-17T00:00:00 | SN : | 321486-1
Kapasitas: | -20~150 oC | Resolusi | 0.01
Standard: | Dryblok
Merk: | Isotech
```
**Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_ › Variasi axial Dryblok B**

```
Variasi Axial
Owner : | PT. Sistem Dirgantara Inovasi Teknologi | Type : | TeCal 700xs
TGL Tes : | 2024-07-17T00:00:00 | SN : | DB-B-2
Kapasitas: | 0~600 oC
Standard: | Dryblok
Merk: | Techne
```
**Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_ › stdev drywell**

```
KESTABILAN | KESERAGAMAN
Posisi | Lubang | Stdev | Average | Stdev
100 | 200 | 300 | 400 | 100 | 200 | 300 | 400 | 100 | 200 | 300 | 400
Tengah | 1 | 0.0547722557505135 | 0.0547722557505135 | 0.05477225575049793 | 0.05477225575049793 | 98.43999999999998 | 198.44 | 298.24 | 398.0400000000001 | 1.1879393923934147 | 1.1879393923934047 | 1.3293607486307062 | 
Bawah | 0.0836660026534096 | 0.0836660026534028 | 0.08366600265339941 | 0.08366600265339941 | 100.12 | 200.12 | 300.12 | 400.12
Tengah | 2 | 0.0547722557505135 | 0.0547722557505135 | 0.05477225575049793 | 0.05477225575049793 | 98.55999999999999 | 198.56 | 298.26 | 397.9599999999999 | 1.315218613006978 | 1.2727922061357735 | 1.4142135623730951 | 1
```
**Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N › FC Prt Pt100**

```
Nama Alat | : | Thermocouple | Tanggal Kalibrasi | : | 2025-02-14T00:00:00 | Link calculator : https://www.fluke.com/en-us/learn/tools-calculators/pt100-calculator?srsltid=AfmBOoqPe_j-N87lgotXxrFrPdjOLAJ5b_2mxDXIuBjJ08Xi
Type | : | PRT PT100 | Due Date Kalibrasi | : | 2027-02-15T00:00:00
S/N | : | SH1/20 | Tertelusur | : | LK-070-IDN
For t ≥0 oC : | For t≤0 oC :
0
Lama: | Terbaru :
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-tids.json`: 2763/2763 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `index_titik` | peta | 2 | 2 | lebar | recorder·daftar; kalibrator·daftar |
| `meter/recorder/koreksi` | tabel_berkunci | 2 | 640 | panjang | 1.-20·desimal; 1.0·desimal; 1.10·desimal; 1.25·desimal; 1.50·desimal; 1.100·desimal; 1.150·desimal; 1.200·desimal; 1.300·desimal; 1.400·desimal; … +310 |
| `meter/recorder/u95` | tabel_berkunci | 2 | 640 | panjang | 1.-20·desimal; 1.0·desimal; 1.10·desimal; 1.25·desimal; 1.50·desimal; 1.100·desimal; 1.150·desimal; 1.200·desimal; 1.300·desimal; 1.400·desimal; … +310 |
| `meter/recorder/drift` | peta | 2 | 2 | lebar | Type N·desimal; Type K·desimal |
| `meter/constant` | tabel_berkunci | 3 | 189 | panjang | RTD.-100·desimal; RTD.-20·desimal; RTD.0·desimal; RTD.25·desimal; RTD.50·desimal; RTD.100·desimal; RTD.200·desimal; RTD.300·desimal; RTD.400·desimal; RTD.500·desimal; … +87 |
| `meter/yokogawa` | tabel_berkunci | 3 | 124 | panjang | RTD.-100·desimal; RTD.-20·desimal; RTD.0·desimal; RTD.25·desimal; RTD.50·desimal; RTD.100·desimal; RTD.200·desimal; RTD.300·desimal; RTD.400·desimal; RTD.500·desimal; … +54 |
| `sensor/recorder/koreksi` | tabel_berkunci | 2 | 278 | panjang | 3.-20·desimal; 3.0·desimal; 3.25·desimal; 3.50·desimal; 3.100·desimal; 3.150·desimal; 3.200·desimal; 3.300·desimal; 3.400·desimal; 3.500·desimal; … +188 |
| `sensor/recorder/u95` | tabel_berkunci | 2 | 278 | panjang | 3.-20·desimal; 3.0·desimal; 3.25·desimal; 3.50·desimal; 3.100·desimal; 3.150·desimal; 3.200·desimal; 3.300·desimal; 3.400·desimal; 3.500·desimal; … +188 |
| `sensor/recorder/drift` | peta | 2 | 2 | lebar | Type N·desimal; Type K·desimal |
| `sensor/kalibrator/koreksi` | tabel_berkunci | 3 | 301 | panjang | 17.-20·desimal; 17.0·desimal; 17.25·desimal; 17.50·desimal; 17.100·desimal; 17.150·desimal; 17.200·desimal; 1.0·desimal; 1.25·desimal; 1.50·desimal; … +201 |
| `sensor/kalibrator/u95` | tabel_berkunci | 3 | 285 | panjang | 17.-20·desimal; 17.0·desimal; 17.25·desimal; 17.50·desimal; 17.100·desimal; 17.150·desimal; 17.200·desimal; 1.0·desimal; 1.25·desimal; 1.50·desimal; … +195 |
| `sensor/kalibrator/drift` | peta | 3 | 3 | lebar | RTD·desimal; Type N·desimal; Type K·desimal |
| `dryblock` | tabel_berkunci | 2 | 10 | lebar | nama·teks; serial·desimal_teks; rentang·teks; stabilitas·desimal; keseragaman·desimal |
| `cmc` | tabel | 3 | 9 | lebar | min·desimal; maks·desimal; u95·desimal |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!Q13:U16`, `DATABASE!Q19:V25`, `DATABASE!R5:S7`, `PERHITUNGAN FC!P23`, `PERHITUNGAN FC!Q23`, `PERHITUNGAN U95%!B24:B35`, `SERTIFIKAT!J20`, `SERTIFIKAT!L20`, `Sheet2!B7`, `Standar_Recorder!AM9`, `Standar_Recorder!T30`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-tids.json:_sumber**: Master_Olah_Data_Suhu_TIDS_-_Recorder_Graptech.xlsm (sesi 071-CAL-325) & Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N.xlsm — dua workbook TIDS ber-password dari lab, 28 Agt 2026. Sheet Standar_Recorder / STANDAR KALIBRATOR.
- **tabel-standar-tids.json:_ejaan**: Master menulis 'PRT PT100'; berkas ini memakai 'RTD' — kosakata yang sama dengan TabelKalibratorSuhu.

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
