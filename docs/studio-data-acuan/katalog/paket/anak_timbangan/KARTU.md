# Anak Timbangan (OIML R111) — paket `anak_timbangan`

| | |
|---|---|
| Kelompok | Massa |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-anak-timbangan.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `AnakTimbanganProfile` |
| Kalkulator | `AnakTimbanganCalculator` |
| Kelas tabel | `TabelStandarAnakTimbangan` |
| Formulir resmi | SIDIK-FM-CAL-0541_Rev.0 - LEMBAR KERJA ANAK TIMBANGAN (Non KAN).pdf |
| Folder master | `Massa_Timbangan/1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` |
| Generator | `docs/skrip/gen-tabel-standar-anak-timbangan.py` |
| Fixture master | `database/data/sesi-master-anak-timbangan.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-anak-timbangan.md` |
| Serah-terima frontend | `docs/perintah-frontend-anak-timbangan.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarAnakTimbangan` ← `AnakTimbanganCalculator`, `AnakTimbanganProfile`, `CalibrationRequest`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarAnakTimbangan.php`
- `app/Services/Calibration/AnakTimbanganCalculator.php`
- `app/Services/Calibration/Profiles/AnakTimbanganProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/AlurPenuhAnakTimbanganTest.php`
- `tests/Feature/AnakTimbanganSertifikatSatuHalamanTest.php`
- `tests/Unit/AnakTimbanganGerbangTest.php`
- `tests/Unit/AnakTimbanganMasterTest.php`
- `tests/Unit/AnakTimbanganRohmanTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 93 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Data Sens Analytical Balance | tab data acuan (bisa disunting lewat versi) | 36 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Data Sens Excellent | tab data acuan (bisa disunting lewat versi) | 36 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Data Sens Fujitsu | tab data acuan (bisa disunting lewat versi) | 36 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Data Sens Mettler Toledo | tab data acuan (bisa disunting lewat versi) | 36 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Data Sens Semi Micro | tab data acuan (bisa disunting lewat versi) | 175 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Deviasi Standard Timbangan | tab data acuan (bisa disunting lewat versi) | 99 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Drift AT E2(0.1-200g) | tab data acuan (bisa disunting lewat versi) | 95 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Drift AT E2(1kg) | tab data acuan (bisa disunting lewat versi) | 38 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Drift AT E2(500g) | tab data acuan (bisa disunting lewat versi) | 38 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Drift AT F1(10kg) | tab data acuan (bisa disunting lewat versi) | 38 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Drift AT F1(20kg) | tab data acuan (bisa disunting lewat versi) | 38 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Drift AT F1(2kg) | tab data acuan (bisa disunting lewat versi) | 38 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | Drift AT F1(5kg) | tab data acuan (bisa disunting lewat versi) | 38 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | FORM VALIDASI | sumber nomor versi workbook (F1) | 15 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 77 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | MPE AT | tab data acuan (bisa disunting lewat versi) | 26 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 74 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 345 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 68 |
| `1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp` | STD AT | tab data acuan (bisa disunting lewat versi) | 89 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp › Data Sens Analytical Balance**

```
E2 | AT yang dipakai untuk Msens
MPE | 0.16 | mg
Data Sensitivitas Timbangan Analytical Balance | AT Msens | 20 | mg
Middle Capacity : | 100 | g
Repeatability | S (E2) | T (E2) | T+Msens | S+Msens | Nama Standard | Kapasitas
1 | 99.9999 | 99.9997 | 100.0198 | 100.0199 | Semi Micro Balance | 80
```
**1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp › Data Sens Excellent**

```
F2 | AT yang dipakai untuk Msens
MPE | 30 | mg
Data Sensitivitas Timbangan | AT Msens | 900 | mg
Middle Capacity : | 2000 | g | AT Msens | 1000
Repeatability | S (F1) | T (F2) | T+Msens | S+Msens | Nama Standard | Kapasitas
1 | 2000 | 1999.97 | 2000.97 | 2001 | Semi Micro Balance | 80
```
**1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp › Data Sens Fujitsu**

```
F1 | AT yang dipakai untuk Msens
MPE | 2.5 | mg
Data Sensitivitas Timbangan | AT Msens | 100 | mg
Middle Capacity : | 500 | g
Repeatability | S (E2) | T (F1) | T+Msens | S+Msens | Nama Standard | Kapasitas
1 | 500 | 499.996 | 500.096 | 500.099 | Semi Micro Balance | 80
```
**1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp › Data Sens Mettler Toledo**

```
F2 | AT yang dipakai untuk Msens
MPE | 160 | mg
Data Sensitivitas Timbangan | AT Msens | 4800 | mg
Middle Capacity : | 10000 | g | AT Msens | 5000
Repeatability | S (F1) | T (F2) | T+Msens | S+Msens | Nama Standard | Kapasitas
1 | 10000 | 9999.99 | 10004.99 | 10005 | Semi Micro Balance | 80
```
**1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp › Data Sens Semi Micro**

```
E2 | AT yang dipakai untuk Msens
MPE | 0.1 | mg
Data Sensitivitas Timbangan Semi Micro Balance | AT Msens | 20 | mg
Middle Capacity : | 50 | g
Repeatability | S (E2) | T (E2) | T+Msens | S+Msens | Nama Standard | Kapasitas
1 | 49.99999 | 49.99998 | 50.00199 | 50.00199 | Semi Micro Balance | 80
```
**1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp › Deviasi Standard Timbangan**

```
Nama Standard | : | Semi Micro Balance
Nominal AT | : | 50 | g
Tanggal Verifikasi | 2026-05-04T00:00:00 | 2026-05-05T00:00:00 | 2026-05-06T00:00:00 | 2026-05-07T00:00:00 | 2026-05-08T00:00:00 | 2026-05-11T00:00:00
Repeat | Pembacaan (g)
S | T | T | S | ∆m | S | T | T | S | ∆m | S | T | T | S | ∆m | S | T | T | S | ∆m | S | T | T | S | ∆m | S | T | T | S | ∆m
n | 1 | 2 | 3 | 4 | 5 | 6
```
**1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp › Drift AT E2(0.1-200g)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | Accurate
Type | : | Stainless
No. Seri | : | 75870
Kelas | : | E2
```
**1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp › Drift AT E2(1kg)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | -
Type | : | -
No. Seri | :
Kelas | : | E2
```
**1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp › Drift AT E2(500g)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | Accurate
Type | : | Stainless
No. Seri | : | 100636
Kelas | : | E2
```
**1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp › Drift AT F1(10kg)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | -
Type | : | -
No. Seri | : | 4321
Kelas | : | F1
```
**1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp › Drift AT F1(20kg)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | -
Type | : | -
No. Seri | : | 130016
Kelas | : | F1
```
**1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp › Drift AT F1(2kg)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | -
Type | : | -
No. Seri | :
Kelas | : | F1
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-anak-timbangan.json`: 783/783 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `sumber` | peta | 8 | 8 | lebar | workbook·teks; lembar_kerja·teks; metode·teks; acuan·teks; revisi_master·teks; dalam_lingkup_akreditasi·boolean; catatan·teks; digenerate_oleh·teks |
| `konstanta` | peta | 7 | 7 | lebar | rho_udara_referensi_kg_m3·desimal; u_bouyancy_kg_m3·desimal; pembagi_rectangular·desimal; pembagi_normal·desimal; faktor_resolusi_ganda·desimal; catatan_pembagi·teks; rumus_densitas_udara·teks |
| `konstanta/vi` | peta | 6 | 6 | lebar | repeatability·desimal; sertifikat_calibrator_at·desimal; resolusi_timbangan_standard·desimal; instability·desimal; bouyancy·desimal; sensitivity·desimal |
| `standar_at/set` | tabel | 7 | 70 | lebar | nama·teks; kapasitas·teks; kelas·teks; merk_tipe·teks; no_seri·desimal_teks; tertelusur·teks; no_sertifikat·teks; tanggal_kalibrasi·tanggal; due_date·tanggal; interval_tahun·desimal |
| `standar_at/keping` | tabel | 29 | 261 | lebar | kelas·teks; nominal_teks·desimal_teks; nominal_g·desimal·g; bintang·boolean; konvensional_g·desimal·g; koreksi_g·desimal·g; u_mg·desimal·mg; drift_mg·desimal·mg; pola_drift·teks |
| `timbangan` | tabel | 5 | 95 | lebar | nama·teks; merk_tipe·teks; no_seri·teks; tertelusur·teks; tanggal_database·tanggal; kapasitas_g·desimal·g; resolusi_g·desimal·g; u95_g·desimal·g; nominal_at_uji_g·desimal·g; stdev_harian_mg·daftar_angka·mg; … +9 |
| `densitas` | peta | 6 | 6 | lebar | status·teks; satuan·teks; pertanyaan_lab·teks; catatan·teks; kelas·daftar; aturan_diatas_100g·teks |
| `densitas/baris` | tabel | 12 | 72 | lebar | nominal_g·desimal·g; E2·desimal; F1·desimal; F2·desimal; M1·desimal; M2·desimal |
| `mpe` | peta | 3 | 3 | lebar | satuan·teks; acuan·teks; kelas·daftar |
| `mpe/baris` | tabel | 23 | 207 | lebar | label·teks; nominal_g·desimal·g; F1·desimal; F2·desimal; M1·desimal; M1-2·kosong; M2·desimal; M2-3·kosong; M3·desimal |
| `meter_lingkungan` | tabel | 1 | 5 | lebar | nama·teks; lokasi·teks; tanggal_kalibrasi·tanggal; due_date·tanggal; tertelusur·teks |
| `meter_lingkungan/*/suhu` | tabel_anak | 1 | 1 | lebar | u95·desimal |
| `meter_lingkungan/*/suhu/0/titik/0` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/suhu/0/titik/1` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/suhu/0/titik/2` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/suhu/0/titik/3` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/suhu/0/titik/4` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/kelembaban` | tabel_anak | 1 | 1 | lebar | u95·desimal |
| `meter_lingkungan/*/kelembaban/0/titik/0` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/kelembaban/0/titik/1` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/kelembaban/0/titik/2` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/kelembaban/0/titik/3` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/kelembaban/0/titik/4` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/tekanan` | tabel_anak | 1 | 1 | lebar | u95·desimal |
| `meter_lingkungan/*/tekanan/0/titik/0` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/tekanan/0/titik/1` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/tekanan/0/titik/2` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/tekanan/0/titik/3` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/tekanan/0/titik/4` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/tekanan/0/titik/5` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/tekanan/0/titik/6` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/tekanan/0/titik/7` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `meter_lingkungan/*/tekanan/0/titik/8` | daftar | 3 | 1 | lebar | nilai·daftar_angka |
| `drift_penimbangan` | tabel | 7 | 21 | lebar | maks_index_standar_g·desimal·g; kelompok·teks; u_drift_g·desimal·g |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
