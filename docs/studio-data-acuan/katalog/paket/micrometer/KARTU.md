# Micrometer 0–100 mm (4 varian) — paket `micrometer`

> **Alat pilot.** Dikerjakan pertama di Gelombang 2 (prompt P2–P4), sebelum alat lain.

| | |
|---|---|
| Kelompok | Panjang |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-micrometer.json` |
| Status di Studio | Pilot (Gelombang 2) |
| Profil | `MicrometerProfile` |
| Kalkulator | `MicrometerCalculator` |
| Kelas tabel | `TabelStandarMicrometer` |
| Formulir resmi | SIDIK-FM-CAL-0522.A_Rev.1 - LEMBAR KERJA MICROMETER (0-25).pdf, SIDIK-FM-CAL-0522.B_Rev.1 - LEMBAR KERJA MICROMETER (25-50).pdf, SIDIK-FM-CAL-0522.C_Rev.1 - LEMBAR KERJA MICROMETER (50-75).pdf, SIDIK-FM-CAL-0522.D_Rev.1 - LEMBAR KERJA MICROMETER (75-100).pdf |
| Folder master | `Panjang_CSV/Master_Olah_Data_Micrometer_0-25mm`, `Panjang_CSV/Master_Olah_Data_Micrometer_25-50mm`, `Panjang_CSV/Master_Olah_Data_Micrometer_50-75mm`, `Panjang_CSV/Master_Olah_Data_Micrometer_75-100mm` |
| Generator | `docs/skrip/gen-tabel-standar-micrometer.py` |
| Fixture master | `database/data/sesi-master-micrometer.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-micrometer.md` |
| Serah-terima frontend | `docs/perintah-frontend-micrometer.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarMicrometer` ← `AuditMicrometerCmc`, `CalibrationController`, `CalibrationProfileRegistry`, `FlowmeterCalculator`, `MicrometerCalculator`, `MicrometerProfile`, `TabelStandarDialIndicator`, `TabelStandarFlowmeter`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarMicrometer.php`
- `app/Services/Calibration/MicrometerCalculator.php`
- `app/Services/Calibration/Profiles/MicrometerProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/AuditMicrometerCmcTest.php`
- `tests/Feature/FlowmeterLantaiCmcTest.php`
- `tests/Feature/FlowmeterSesiTest.php`
- `tests/Feature/HeightGaugeSertifikatTest.php`
- `tests/Feature/MicrometerSertifikatTest.php`
- `tests/Feature/MicrometerSesiTest.php`
- `tests/Unit/HeightGaugeMasterTest.php`
- `tests/Unit/MicrometerMasterTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Micrometer_0-25mm` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_Micrometer_0-25mm` | FORM VALIDASI | sumber nomor versi workbook (F1) | 176 |
| `Master_Olah_Data_Micrometer_0-25mm` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 299 |
| `Master_Olah_Data_Micrometer_0-25mm` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 21 |
| `Master_Olah_Data_Micrometer_0-25mm` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 286 |
| `Master_Olah_Data_Micrometer_0-25mm` | Perhitungan koef. Sensitivitas | tab Rumus — baca saja (lapis 2) | 7 |
| `Master_Olah_Data_Micrometer_0-25mm` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 57 |
| `Master_Olah_Data_Micrometer_0-25mm` | Standar_GB | tab data acuan (bisa disunting lewat versi) | 133 |
| `Master_Olah_Data_Micrometer_25-50mm` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_Micrometer_25-50mm` | FORM VALIDASI | sumber nomor versi workbook (F1) | 176 |
| `Master_Olah_Data_Micrometer_25-50mm` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 299 |
| `Master_Olah_Data_Micrometer_25-50mm` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 21 |
| `Master_Olah_Data_Micrometer_25-50mm` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 286 |
| `Master_Olah_Data_Micrometer_25-50mm` | Perhitungan koef. Sensitivitas | tab Rumus — baca saja (lapis 2) | 7 |
| `Master_Olah_Data_Micrometer_25-50mm` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 57 |
| `Master_Olah_Data_Micrometer_25-50mm` | Standar_GB | tab data acuan (bisa disunting lewat versi) | 133 |
| `Master_Olah_Data_Micrometer_50-75mm` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_Micrometer_50-75mm` | FORM VALIDASI | sumber nomor versi workbook (F1) | 176 |
| `Master_Olah_Data_Micrometer_50-75mm` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 299 |
| `Master_Olah_Data_Micrometer_50-75mm` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 21 |
| `Master_Olah_Data_Micrometer_50-75mm` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 286 |
| `Master_Olah_Data_Micrometer_50-75mm` | Perhitungan koef. Sensitivitas | tab Rumus — baca saja (lapis 2) | 7 |
| `Master_Olah_Data_Micrometer_50-75mm` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 57 |
| `Master_Olah_Data_Micrometer_50-75mm` | Standar_GB | tab data acuan (bisa disunting lewat versi) | 133 |
| `Master_Olah_Data_Micrometer_75-100mm` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_Micrometer_75-100mm` | FORM VALIDASI | sumber nomor versi workbook (F1) | 176 |
| `Master_Olah_Data_Micrometer_75-100mm` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 299 |
| `Master_Olah_Data_Micrometer_75-100mm` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 21 |
| `Master_Olah_Data_Micrometer_75-100mm` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 286 |
| `Master_Olah_Data_Micrometer_75-100mm` | Perhitungan koef. Sensitivitas | tab Rumus — baca saja (lapis 2) | 7 |
| `Master_Olah_Data_Micrometer_75-100mm` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 57 |
| `Master_Olah_Data_Micrometer_75-100mm` | Standar_GB | tab data acuan (bisa disunting lewat versi) | 133 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olah_Data_Micrometer_0-25mm › Standar_GB**

```
STANDAR GAUGE BLOCK
Name : | Gauge block standard | Tanggal Kalibrasi | 2024-01-24T00:00:00
No Seri | 160006 | LK-410-IDN
Merk | Metrology
Tipe | GB-9122-0
Rentang Ukur | 0.5-100 mm
```
**Master_Olah_Data_Micrometer_25-50mm › Standar_GB**

```
STANDAR GAUGE BLOCK
Name : | Gauge block standard | Tanggal Kalibrasi | 2024-01-24T00:00:00
No Seri | 160006 | LK-410-IDN
Merk | Metrology
Tipe | GB-9122-0
Rentang Ukur | 0.5-100 mm
```
**Master_Olah_Data_Micrometer_50-75mm › Standar_GB**

```
STANDAR GAUGE BLOCK
Name : | Gauge block standard | Tanggal Kalibrasi | 2021-01-26T00:00:00
No Seri | 160006 | LK-102-IDN
Merk | Metrology
Tipe | GB-9122-0
Rentang Ukur | 0.5-100 mm
```
**Master_Olah_Data_Micrometer_75-100mm › Standar_GB**

```
STANDAR GAUGE BLOCK
Name : | Gauge block standard | Tanggal Kalibrasi | 2024-01-24T00:00:00
No Seri | 160006 | LK-410-IDN
Merk | Metrology
Tipe | GB-9122-0
Rentang Ukur | 0.5-100 mm
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-micrometer.json`: 175/175 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `standar` | peta | 5 | 5 | lebar | nama·teks; merk_tipe·teks; seri·desimal_teks; traceability·teks; tanggal_kalibrasi·tanggal |
| `balok_ukur` | peta | 32 | 32 | lebar | 1.0·desimal; 1.1·desimal; 1.2·desimal; 1.3·desimal; 1.5·desimal; 1.6·desimal; 1.7·desimal; 1.8·desimal; 1.9·desimal; 2.5·desimal; 5.0·desimal; 6.0·desimal; … +20 |
| `ketidakpastian_balok_um` | peta | 1 | 1 | lebar | lainnya·desimal |
| `ketidakpastian_balok_um/aturan` | tabel | 4 | 8 | lebar | maks·desimal; u·desimal |
| `ketidakpastian_balok_um/persis` | peta | 2 | 2 | lebar | 101.6·desimal; 200·desimal |
| `cmc` | tabel | 4 | 32 | lebar | kode·teks; label·teks; kode_dokumen·teks; judul_rentang·teks; kapasitas_min_mm·desimal·mm; kapasitas_maks_mm·desimal·mm; u95_um·desimal·µm; balok_pra_evaluasi_mm·daftar_angka·mm |
| `cmc/*/titik` | tabel_anak | 44 | 88 | lebar | nominal_cetak_mm·desimal·mm; tumpukan_mm·daftar_angka·mm |
| `konstanta` | peta | 7 | 7 | lebar | delta_alpha_per_c·desimal·/°C; wringing_um·desimal·µm; geometri_um·desimal·µm; drift_a_um·desimal·µm; drift_b_um_per_mm·desimal·µm/mm; suhu_acuan_c·desimal·°C; vi_type_b·desimal |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!X11`, `INPUT DATA!F5`, `PERHITUNGAN!F61`, `PERHITUNGAN!H23`, `PERHITUNGAN!O25`, `Standar_GB!Q10:R132`, `pita CMC (DATABASE!R5:T8`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-micrometer.json:_sumber**: Master_Olah_Data_Micrometer_{025,2550,5075,75100}mm.xlsm (sheet Standar_GB & DATABASE) — keempat workbook memuat tabel balok ukur yang IDENTIK, sudah diadu oleh skrip generator ini.
- **tabel-standar-micrometer.json:_digenerate_oleh**: docs/skrip/gen-tabel-standar-micrometer.py

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
