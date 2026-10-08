# Gaya — UTM, Load Cell, Proving Ring — paket `gaya`

| | |
|---|---|
| Kelompok | Gaya |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-gaya.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `GayaProfile`, `UtmProfile`, `LoadCellProfile`, `ProvingRingProfile` |
| Kalkulator | `GayaCalculator` |
| Kelas tabel | `TabelStandarGaya` |
| Formulir resmi | SIDIK-FM-CAL-0519_Rev.3 - LEMBAR KERJA COMPRESSION (UTM).pdf, SIDIK-FM-CAL-0520_Rev.3 - LEMBAR KERJA LOAD CELL.pdf, SIDIK-FM-CAL-0521_Rev.3 - LEMBAR KERJA PROFING RING.pdf |
| Folder master | `Alat_Gaya/Gaya_UTM`, `Alat_Gaya/Gaya_Load_Cell`, `Alat_Gaya/Gaya_Proving_Ring` |
| Generator | `docs/skrip/gen-tabel-standar-gaya.py` |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-gaya.md` |
| Serah-terima frontend | `docs/perintah-frontend-gaya.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarGaya` ← `GayaCalculator`, `GayaProfile`, `ProvingRingProfile`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarGaya.php`
- `app/Services/Calibration/GayaCalculator.php`
- `app/Services/Calibration/Profiles/GayaProfile.php`
- `app/Services/Calibration/Profiles/UtmProfile.php`
- `app/Services/Calibration/Profiles/LoadCellProfile.php`
- `app/Services/Calibration/Profiles/ProvingRingProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/GayaDariHpTest.php`
- `tests/Feature/GayaSesiContohCocokMasterTest.php`
- `tests/Feature/GayaSesiSimpanTest.php`
- `tests/Feature/GayaValidasiSesiTest.php`
- `tests/Unit/GayaBudgetTest.php`
- `tests/Unit/GayaCalculatorTest.php`
- `tests/Unit/GayaValidasiTitikTest.php`
- `tests/Unit/ProvingRingMasterTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Gaya_UTM` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 102 |
| `Gaya_UTM` | FORM_VALIDASI | sumber nomor versi workbook (F1) | 64 |
| `Gaya_UTM` | INPUT_DATA | tab Bentuk Lembar (lapis 3) | 38 |
| `Gaya_UTM` | Misalignment | tab Rumus — baca saja (lapis 2) | 6 |
| `Gaya_UTM` | PERHITUNGAN_FC | tab Rumus — baca saja (lapis 2) | 44 |
| `Gaya_UTM` | PERHITUNGAN_U95pct | tab Rumus — baca saja (lapis 2) | 22 |
| `Gaya_UTM` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 43 |
| `Gaya_UTM` | STANDAR_LOADCELL | tab data acuan (bisa disunting lewat versi) | 72 |
| `Gaya_Load_Cell` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 102 |
| `Gaya_Load_Cell` | FORM_VALIDASI | sumber nomor versi workbook (F1) | 58 |
| `Gaya_Load_Cell` | INPUT_DATA | tab Bentuk Lembar (lapis 3) | 42 |
| `Gaya_Load_Cell` | Misalignment | tab Rumus — baca saja (lapis 2) | 6 |
| `Gaya_Load_Cell` | PERHITUNGAN_FC | tab Rumus — baca saja (lapis 2) | 44 |
| `Gaya_Load_Cell` | PERHITUNGAN_U95pct | tab Rumus — baca saja (lapis 2) | 22 |
| `Gaya_Load_Cell` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 44 |
| `Gaya_Load_Cell` | STANDAR_LOADCELL | tab data acuan (bisa disunting lewat versi) | 72 |
| `Gaya_Proving_Ring` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 102 |
| `Gaya_Proving_Ring` | FORM_VALIDASI | sumber nomor versi workbook (F1) | 52 |
| `Gaya_Proving_Ring` | INPUT_DATA | tab Bentuk Lembar (lapis 3) | 42 |
| `Gaya_Proving_Ring` | Misalignment | tab Rumus — baca saja (lapis 2) | 6 |
| `Gaya_Proving_Ring` | PERHITUNGAN_FC | tab Rumus — baca saja (lapis 2) | 47 |
| `Gaya_Proving_Ring` | PERHITUNGAN_U95pct | tab Rumus — baca saja (lapis 2) | 22 |
| `Gaya_Proving_Ring` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 47 |
| `Gaya_Proving_Ring` | STANDAR_LOADCELL | tab data acuan (bisa disunting lewat versi) | 72 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Gaya_UTM › STANDAR_LOADCELL**

```
LATEST RECORD DRIFT
Nama Alat | : | Load Cell 100 kN (10000 kg) | Tanggal Kalibrasi | : | 2024-12-17 00:00:00
Merek | : | Uscell | Due Date Kalibrasi | : | 2026-12-17 00:00:00 [=DATE(YEAR(L2)+2,MONTH(L2),DAY(L2))] | No | STD | Udrift (%) f.s
Type | : | ST2-C3-10+ | Tertelusur | : | LK-057-IDN | Tekan | Tarik
S/N | : | J10CC13283 | 1 | Load Cell 5 kN | 0.0692820323027551 | 0
2 | Load Cell 100 kN | 0.031834452342817454 | 0.034272153479393656
```
**Gaya_Load_Cell › STANDAR_LOADCELL**

```
LATEST RECORD DRIFT
Nama Alat | : | Load Cell 100 kN (10000 kg) | Tanggal Kalibrasi | : | 2024-12-17 00:00:00
Merek | : | Uscell | Due Date Kalibrasi | : | 2026-12-17 00:00:00 [=DATE(YEAR(L2)+2,MONTH(L2),DAY(L2))] | No | STD | Udrift (%) f.s
Type | : | ST2-C3-10+ | Tertelusur | : | LK-057-IDN | Tekan | Tarik
S/N | : | J10CC13283 | 1 | Load Cell 5 kN | 0.0692820323027551 | 0.04000000000000001 [=10%*H75]
2 | Load Cell 100 kN | 0.031834452342817454 | 0.034272153479393656
```
**Gaya_Proving_Ring › STANDAR_LOADCELL**

```
LATEST RECORD DRIFT
Nama Alat | : | Load Cell 100 kN (10000 kg) | Tanggal Kalibrasi | : | 2024-12-17 00:00:00
Merek | : | Uscell | Due Date Kalibrasi | : | 2026-12-17 00:00:00 [=DATE(YEAR(L2)+2,MONTH(L2),DAY(L2))] | No | STD | Udrift (%) f.s
Type | : | ST2-C3-10+ | Tertelusur | : | LK-057-IDN | Tekan | Tarik
S/N | : | J10CC13283 | 1 | Load Cell 5 kN | 0.0692820323027551 | 0.04000000000000001 [=10%*H75]
2 | Load Cell 100 kN | 0.031834452342817454 | 0.034272153479393656
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-gaya.json`: 218/218 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `faktor_satuan` | peta | 5 | 5 | lebar | kN·desimal; N·desimal; lbf·desimal; kgf·desimal; tnf·desimal |
| `faktor_set_point_kg_ke_kn` | nilai_tunggal | 1 | 1 | lebar | nilai·desimal·kN |
| `koefisien_suhu_per_c` | nilai_tunggal | 1 | 1 | lebar | nilai·desimal·/°C |
| `standar` | tabel_berkunci | 3 | 27 | lebar | nama·teks; merek·teks; tipe·teks; serial·teks; tertelusur·teks; tanggal_kalibrasi·tanggal; berlaku_sampai·tanggal; suhu_sertifikat·desimal; u95_persen·desimal·% |
| `standar/*/tabel` | tabel_anak | 3 | 0 | lebar |  |
| `standar/*/tabel/*/Push` | tabel_anak | 29 | 94 | lebar | set_point_kn·desimal·kN; koreksi_kn·desimal·kN; set_point_kg·desimal·kg; koreksi_kg·desimal·kg |
| `standar/*/tabel/*/Pull` | tabel_anak | 18 | 72 | lebar | set_point_kn·desimal·kN; koreksi_kn·desimal·kN; set_point_kg·desimal·kg; koreksi_kg·desimal·kg |
| `drift` | tabel_berkunci | 3 | 18 | lebar | Push.utm·desimal; Push.load_cell·desimal; Push.proving_ring·desimal; Pull.utm·desimal; Pull.load_cell·desimal; Pull.proving_ring·desimal |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-gaya.json:_sumber**: Project-PT-Sidik/alat-alat-Pt-Sidik/Alat_Gaya/*/STANDAR_LOADCELL.csv + DATABASE.csv
- **tabel-standar-gaya.json:_generator**: docs/skrip/gen-tabel-standar-gaya.py - JANGAN disunting tangan
- **tabel-standar-gaya.json:_catatan**: {"cmc": "TIDAK di sini - sumbernya database/data/kemampuan-kalibrasi.json (LK-285-IDN).", "drift": "Per arah per WORKBOOK, karena ketiganya tidak sepakat untuk standar yang sama.", "faktor_set_point_kg_ke_kn": "Eksak 0,00980665, beda dari faktor pembacaan kgf 0,00981."}

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
