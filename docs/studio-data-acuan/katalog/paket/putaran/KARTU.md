# Centrifuge & Tachometer — paket `putaran`

| | |
|---|---|
| Kelompok | Waktu |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-putaran.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `ProfilPutaran`, `CentrifugeProfile`, `TachometerProfile` |
| Kalkulator | `PutaranCalculator` |
| Kelas tabel | `TabelStandarPutaran` |
| Formulir resmi | SIDIK-FM-CAL-0515_Rev.4 - LEMBAR KERJA CENTRIFUGE & TACHOMETER.pdf |
| Folder master | `Waktu_/Master_Olda_Centrifuge`, `Waktu_/Master_Olda_Tachometer` |
| Generator | `docs/skrip/gen-tabel-standar-waktu-frekuensi.py` |
| Fixture master | `database/data/sesi-master-waktu-frekuensi.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-waktu-frekuensi.md` |
| Serah-terima frontend | `docs/perintah-frontend-waktu-frekuensi.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarPutaran` ← `ProfilPutaran`, `PutaranCalculator`, `TabelStandarWaktu`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarPutaran.php`
- `app/Services/Calibration/PutaranCalculator.php`
- `app/Services/Calibration/Profiles/ProfilPutaran.php`
- `app/Services/Calibration/Profiles/CentrifugeProfile.php`
- `app/Services/Calibration/Profiles/TachometerProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/BudgetTerlihatDanGerbangCmcTest.php`
- `tests/Feature/LantaiCmcPutaranTest.php`
- `tests/Feature/NominalDiLuarSertifikatDitolakTest.php`
- `tests/Feature/PayloadHpWaktuFrekuensiTest.php`
- `tests/Feature/SemuaProfilLembarKerjaTest.php`
- `tests/Unit/WaktuFrekuensiMasterTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olda_Centrifuge` | Certificate (2) | pratinjau sertifikat di layar Simulasi | 50 |
| `Master_Olda_Centrifuge` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 110 |
| `Master_Olda_Centrifuge` | Drift Std Kalibrator | tab data acuan (bisa disunting lewat versi) | 35 |
| `Master_Olda_Centrifuge` | FORM VALIDASI | sumber nomor versi workbook (F1) | 179 |
| `Master_Olda_Centrifuge` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 266 |
| `Master_Olda_Centrifuge` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 109 |
| `Master_Olda_Centrifuge` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 289 |
| `Master_Olda_Centrifuge` | SERTIFIKAT KALIBRATOR | pratinjau sertifikat di layar Simulasi | 26 |
| `Master_Olda_Centrifuge` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 56 |
| `Master_Olda_Centrifuge` | konsep | bukan data — tidak masuk Studio | 50 |
| `Master_Olda_Tachometer` | Certificate (2) | pratinjau sertifikat di layar Simulasi | 50 |
| `Master_Olda_Tachometer` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 110 |
| `Master_Olda_Tachometer` | Drift Std Kalibrator | tab data acuan (bisa disunting lewat versi) | 35 |
| `Master_Olda_Tachometer` | FORM VALIDASI | sumber nomor versi workbook (F1) | 179 |
| `Master_Olda_Tachometer` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 276 |
| `Master_Olda_Tachometer` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 128 |
| `Master_Olda_Tachometer` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 304 |
| `Master_Olda_Tachometer` | SERTIFIKAT KALIBRATOR | pratinjau sertifikat di layar Simulasi | 26 |
| `Master_Olda_Tachometer` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 61 |
| `Master_Olda_Tachometer` | konsep | bukan data — tidak masuk Studio | 50 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olda_Centrifuge › Drift Std Kalibrator**

```
Nama Alat: | Tachometer
Merk       : | -
Type       : | -
SN          : | 190119000006
Res        : | 0.1 & 1 | Rpm
Jumlah Hari: | 420 | hari
```
**Master_Olda_Tachometer › Drift Std Kalibrator**

```
Nama Alat: | Tachometer
Merk       : | -
Type       : | -
SN          : | 190119000006
Res        : | 0.1 & 1 | Rpm
Jumlah Hari: | 420 | hari
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-putaran.json`: 214/214 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `standar` | peta | 6 | 6 | lebar | nama·teks; merk·teks; resolusi·teks; seri·teks; tanggal_kalibrasi·tanggal; traceability·teks |
| `sertifikat` | tabel | 13 | 52 | lebar | nominal·desimal; uut·desimal; koreksi·desimal; u95·desimal |
| `drift` | peta | 3 | 3 | lebar | hari_master·desimal; rentang_maks_master·desimal; rentang_maks_lengkap·desimal |
| `drift/snapshot` | tabel | 7 | 21 | lebar | kolom·teks; tanggal·tanggal; lab·teks |
| `drift/titik` | tabel | 15 | 132 | lebar | nominal·desimal; koreksi.D·desimal; koreksi.F·desimal; koreksi.G·desimal; rentang·desimal; dihitung_master·boolean; koreksi.H·desimal; koreksi.I·desimal; koreksi.J·desimal; koreksi.E·desimal |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`PERHITUNGAN!G34`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-putaran.json:_sumber**: Master Olda Tachometer.xlsm / Master Olda Centrifuge.xlsm (sheet SERTIFIKAT KALIBRATOR & Drift Std Kalibrator) — kedua workbook memuat tabel yang IDENTIK untuk keping standar yang sama.
- **tabel-standar-putaran.json:_digenerate_oleh**: docs/skrip/gen-tabel-standar-waktu-frekuensi.py

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
