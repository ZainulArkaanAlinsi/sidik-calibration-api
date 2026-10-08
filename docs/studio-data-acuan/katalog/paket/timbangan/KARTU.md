# Timbangan (gram / kg / substitusi) — paket `timbangan`

| | |
|---|---|
| Kelompok | Massa |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-timbangan.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `TimbanganProfile` |
| Kalkulator | `TimbanganCalculator` |
| Kelas tabel | `TabelStandarTimbangan` |
| Formulir resmi | SIDIK-FM-CAL-0508.A_Rev.4 - LEMBAR KERJA TIMBANGAN (Metode Subtitusi).pdf, SIDIK-FM-CAL-0508_Rev.6 - LEMBAR KERJA TIMBANGAN.pdf |
| Folder master | `Massa_Timbangan/_New__Master_Olda_Timbangan_gram`, `Massa_Timbangan/_New__Master_Olda_Timbangan_kg`, `Massa_Timbangan/_TERBARU__Master_Olda_Timbangan_Subtitusi_291025` |
| Generator | — |
| Fixture master | `database/data/sesi-master-timbangan.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-timbangan.md` |
| Serah-terima frontend | `docs/perintah-frontend-timbangan.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarTimbangan` ← `TimbanganCalculator`, `TimbanganProfile`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarTimbangan.php`
- `app/Services/Calibration/TimbanganCalculator.php`
- `app/Services/Calibration/Profiles/TimbanganProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/AlurPenuhAnakTimbanganTest.php`
- `tests/Feature/AnakTimbanganSertifikatSatuHalamanTest.php`
- `tests/Feature/RantaiTimbanganTeknisiSampaiSertifikatTest.php`
- `tests/Feature/TebakanMesinTimbanganTest.php`
- `tests/Feature/TimbanganSertifikatTest.php`
- `tests/Feature/TimbanganSesiTest.php`
- `tests/Unit/AnakTimbanganGerbangTest.php`
- `tests/Unit/AnakTimbanganMasterTest.php`
- `tests/Unit/AnakTimbanganRohmanTest.php`
- `tests/Unit/ProfilDariNamaAlatTest.php`
- `tests/Unit/RoutingProfilSepakatTest.php`
- `tests/Unit/TimbanganCmcCocokAkreditasiTest.php`
- `tests/Unit/TimbanganMasterTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `_New__Master_Olda_Timbangan_gram` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 116 |
| `_New__Master_Olda_Timbangan_gram` | Drift AT E2(0.1-200g) | tab data acuan (bisa disunting lewat versi) | 75 |
| `_New__Master_Olda_Timbangan_gram` | Drift AT F1(0.1-500g) | tab data acuan (bisa disunting lewat versi) | 67 |
| `_New__Master_Olda_Timbangan_gram` | Drift AT F1(10kg) | tab data acuan (bisa disunting lewat versi) | 38 |
| `_New__Master_Olda_Timbangan_gram` | Drift AT F2(1-5kg) | tab data acuan (bisa disunting lewat versi) | 44 |
| `_New__Master_Olda_Timbangan_gram` | Drift AT M2(20kg) | tab data acuan (bisa disunting lewat versi) | 65 |
| `_New__Master_Olda_Timbangan_gram` | FORM VALIDASI | sumber nomor versi workbook (F1) | 197 |
| `_New__Master_Olda_Timbangan_gram` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 88 |
| `_New__Master_Olda_Timbangan_gram` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 175 |
| `_New__Master_Olda_Timbangan_gram` | PERHITUNGAN U95% - Correction | tab Rumus — baca saja (lapis 2) | 261 |
| `_New__Master_Olda_Timbangan_gram` | PERHITUNGAN U95%-Weighing | tab Rumus — baca saja (lapis 2) | 477 |
| `_New__Master_Olda_Timbangan_gram` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 81 |
| `_New__Master_Olda_Timbangan_gram` | STANDAR_AT | tab data acuan (bisa disunting lewat versi) | 105 |
| `_New__Master_Olda_Timbangan_gram` | Sekilas Info | bukan data — tidak masuk Studio | 28 |
| `_New__Master_Olda_Timbangan_kg` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 116 |
| `_New__Master_Olda_Timbangan_kg` | Drift AT E2(0.1-200g) | tab data acuan (bisa disunting lewat versi) | 75 |
| `_New__Master_Olda_Timbangan_kg` | Drift AT F1(0.1-500g) | tab data acuan (bisa disunting lewat versi) | 67 |
| `_New__Master_Olda_Timbangan_kg` | Drift AT F1(10kg) | tab data acuan (bisa disunting lewat versi) | 38 |
| `_New__Master_Olda_Timbangan_kg` | Drift AT F2(1-5kg) | tab data acuan (bisa disunting lewat versi) | 44 |
| `_New__Master_Olda_Timbangan_kg` | Drift AT M2(20kg) | tab data acuan (bisa disunting lewat versi) | 65 |
| `_New__Master_Olda_Timbangan_kg` | FORM VALIDASI | sumber nomor versi workbook (F1) | 200 |
| `_New__Master_Olda_Timbangan_kg` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 88 |
| `_New__Master_Olda_Timbangan_kg` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 172 |
| `_New__Master_Olda_Timbangan_kg` | PERHITUNGAN U95% - Correction | tab Rumus — baca saja (lapis 2) | 261 |
| `_New__Master_Olda_Timbangan_kg` | PERHITUNGAN U95%-Weighing | tab Rumus — baca saja (lapis 2) | 461 |
| `_New__Master_Olda_Timbangan_kg` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 81 |
| `_New__Master_Olda_Timbangan_kg` | STANDAR_AT | tab data acuan (bisa disunting lewat versi) | 106 |
| `_New__Master_Olda_Timbangan_kg` | Sekilas Info | bukan data — tidak masuk Studio | 28 |
| `_TERBARU__Master_Olda_Timbangan_Subtitusi_291025` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 116 |
| `_TERBARU__Master_Olda_Timbangan_Subtitusi_291025` | FORM VALIDASI | sumber nomor versi workbook (F1) | 191 |
| `_TERBARU__Master_Olda_Timbangan_Subtitusi_291025` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 92 |
| `_TERBARU__Master_Olda_Timbangan_Subtitusi_291025` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 181 |
| `_TERBARU__Master_Olda_Timbangan_Subtitusi_291025` | PERHITUNGAN U95% - Correction | tab Rumus — baca saja (lapis 2) | 271 |
| `_TERBARU__Master_Olda_Timbangan_Subtitusi_291025` | PERHITUNGAN U95%-Weighing | tab Rumus — baca saja (lapis 2) | 369 |
| `_TERBARU__Master_Olda_Timbangan_Subtitusi_291025` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 81 |
| `_TERBARU__Master_Olda_Timbangan_Subtitusi_291025` | STANDAR_AT | tab data acuan (bisa disunting lewat versi) | 106 |
| `_TERBARU__Master_Olda_Timbangan_Subtitusi_291025` | Sekilas Info | bukan data — tidak masuk Studio | 28 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**_New__Master_Olda_Timbangan_gram › Drift AT E2(0.1-200g)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | -
Type | : | -
No. Seri | : | 2434
Kelas | : | E2
```
**_New__Master_Olda_Timbangan_gram › Drift AT F1(0.1-500g)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | Excellent
Type | : | -
No. Seri | : | 113106
Kelas | : | F1
```
**_New__Master_Olda_Timbangan_gram › Drift AT F1(10kg)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | -
Type | : | -
No. Seri | : | 4231
Kelas | : | F1
```
**_New__Master_Olda_Timbangan_gram › Drift AT F2(1-5kg)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | Sonic
Type | : | -
No. Seri | : | A3659; A3660; A3661
Kelas | : | F2
```
**_New__Master_Olda_Timbangan_gram › Drift AT M2(20kg)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | RC
Type | : | -
No. Seri | : | -
Kelas | : | M2
```
**_New__Master_Olda_Timbangan_gram › STANDAR_AT**

```
Nama Alat | : | Anak Timbangan | Tanggal Kalibrasi | : | 2025-08-21T00:00:00 | OVERALL STANDAR ANAK TIMBANGAN
Kapasitas | : | 100 mg - 200 g | Due Date Kalibrasi | : | 2027-08-21T00:00:00
Type | : | E2 | Tertelusur | : | LK-023-IDN | Kelas | Massa Nominal | Massa Konvensional | Koreksi AT | Ketidakpastian | Drift of Correction/Instability of mass
S/N | : | 2434 | No. Sertif | : | 2373/PKTN.4.7/08/2021 | g | g | g | mg | g
E2 | 0.01 | 0.010015000000000001 | 1.5000000000001124e-05 | 0.002 | 8.660254037893109e-08
Massa Nominal | Massa Konvensional | Koreksi AT | Ketidakpastian | E2 | 0.2 | 0.200035 | 3.499999999997949e-05 | 0.005 | 4.8e-06
```
**_New__Master_Olda_Timbangan_kg › Drift AT E2(0.1-200g)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | -
Type | : | -
No. Seri | : | 2434
Kelas | : | E2
```
**_New__Master_Olda_Timbangan_kg › Drift AT F1(0.1-500g)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | Excellent
Type | : | -
No. Seri | : | 113106
Kelas | : | F1
```
**_New__Master_Olda_Timbangan_kg › Drift AT F1(10kg)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | -
Type | : | -
No. Seri | : | 4231
Kelas | : | F1
```
**_New__Master_Olda_Timbangan_kg › Drift AT F2(1-5kg)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | Sonic
Type | : | -
No. Seri | : | A3659; A3660; A3661
Kelas | : | F2
```
**_New__Master_Olda_Timbangan_kg › Drift AT M2(20kg)**

```
RECORD HASIL REKALIBRASI STANDAR KALIBRATOR
Nama Alat | : | Anak Timbangan
Merk | : | RC
Type | : | -
No. Seri | : | -
Kelas | : | M2
```
**_New__Master_Olda_Timbangan_kg › STANDAR_AT**

```
Nama Alat | : | Anak Timbangan | Tanggal Kalibrasi | : | 2025-08-21T00:00:00 | OVERALL STANDAR ANAK TIMBANGAN
Kapasitas | : | 100 mg - 200 gr | Due Date Kalibrasi | : | 2027-08-21T00:00:00
Type | : | E2 | Tertelusur | : | LK-023-IDN | Kelas | Massa Nominal | Massa Konvensional | Koreksi AT | Ketidakpastian | Drift of Correction/Instability of mass
S/N | : | 2434 | No. Sertif | : | 2373/PKTN.4.7/08/2021 | kg | kg | kg | g | kg
E2 | 1e-05 | 1.0015000000000002e-05 | 1.5000000000001124e-08 | 2e-06 | 8.660254037893109e-11
Massa Nominal | Massa Konvensional | Koreksi AT | Ketidakpastian | E2 | 0.0002 | 0.000200035 | 3.4999999999979495e-08 | 5e-06 | 4.8e-09
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-timbangan.json`: 824/824 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `varian/kg` | peta | 1 | 1 | lebar | basis·teks |
| `varian/kg/e2` | tabel | 15 | 90 | lebar | nominal·desimal; duplikat·boolean; konvensional·desimal; koreksi·desimal; u·desimal; u_drift·desimal |
| `varian/kg/f1` | tabel | 26 | 156 | lebar | nominal·desimal; duplikat·boolean; konvensional·desimal; koreksi·desimal; u·desimal; u_drift·desimal |
| `varian/gram` | peta | 1 | 1 | lebar | basis·teks |
| `varian/gram/e2` | tabel | 15 | 90 | lebar | nominal·desimal; duplikat·boolean; konvensional·desimal; koreksi·desimal; u·desimal; u_drift·desimal |
| `varian/gram/f1` | tabel | 26 | 156 | lebar | nominal·desimal; duplikat·boolean; konvensional·desimal; koreksi·desimal; u·desimal; u_drift·desimal |
| `varian/substitusi` | peta | 1 | 1 | lebar | basis·teks |
| `varian/substitusi/e2` | tabel | 15 | 90 | lebar | nominal·desimal; duplikat·boolean; konvensional·desimal; koreksi·desimal; u·desimal; u_drift·desimal |
| `varian/substitusi/f1` | tabel | 26 | 156 | lebar | nominal·desimal; duplikat·boolean; konvensional·desimal; koreksi·desimal; u·desimal; u_drift·desimal |
| `cmc` | tabel | 17 | 68 | lebar | kode·teks; rentang·teks; cmc_gram·desimal·g; maks_kg·desimal·kg |
| `drift_keluarga` | tabel | 5 | 15 | lebar | maks_kg·desimal·kg; standar·teks; u_drift·desimal |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`Correction!B6`, `DATABASE!R5:T21`, `FC!B50`, `FC!B51`, `FC!B52`, `FC!C50`, `FC!H116`, `FC!V70`, `FC!V86`, `INPUT DATA!AC17`, `INPUT DATA!E4`, `STANDAR_AT!N51:P55`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-timbangan.json:_sumber**: Diekstrak dari TIGA workbook master Timbangan ber-password yang turun dari lab 31 Agt 2026 (sheet STANDAR_AT & DATABASE). JANGAN diedit tangan.
- **tabel-standar-timbangan.json:_kenapa_tiga**: Ketiga workbook memuat SNAPSHOT SERTIFIKAT ANAK TIMBANGAN YANG BERBEDA untuk keping fisik yang sama — bukan cuma beda satuan. Contoh paling terang: keping E2 100 g bermassa konvensional 100,0004 g di master kg tapi 100,000033 g di master gram, dan SELURUH blok E2 master substitusi memakai angka lain lagi (kalibrasi ulang Okt 2025). Disimpan bertiga supaya tiap sesi bisa dihitung ulang jadi angka yang sama dengan kertas yang menerbitkannya. Mana yang berlaku sekarang = pertanyaan T1 di docs/pertanyaan-lab-timbangan.md, dan itu keputusan manajer teknis lab.
- **tabel-standar-timbangan.json:_kolom**: {"nominal": "satuan basis varian", "konvensional": "satuan basis", "koreksi": "satuan basis", "u": "seperseribu satuan basis (master membaginya 1000 di tiap sel)", "u_drift": "satuan basis"}
- **tabel-standar-timbangan.json:_pemilihan**: Tipe Timbangan 'Analytical' -> e2; 'Non-Analytical' -> f1. Master gram memilih lewat INPUT DATA!AC17; master kg & substitusi selalu memakai f1.
- **tabel-standar-timbangan.json:_catatan_cmc**: Ketujuh belas pita A..Q (0 g s/d 2000 kg) SEMUANYA ada di lampiran akreditasi LK-285-IDN no. 12 (Massa / Timbangan Elektronik, mekanik) — dicocokkan baris demi baris ke database/data/kemampuan-kalibrasi.json oleh TimbanganCmcCocokAkreditasiTest. Yang TIDAK punya lantai CMC cuma kapasitas di atas 2000 kg; sesi seperti itu ditandai peringatan sesi.

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
