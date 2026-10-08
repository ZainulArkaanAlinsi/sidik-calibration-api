# Refractometer — paket `refractometer`

| | |
|---|---|
| Kelompok | Analitik |
| Sumber data acuan hari ini | konstanta-php — — |
| Status di Studio | Gelombang 4 — ekstrak konstanta dari kode dulu (prompt P8) |
| Profil | `RefractometerProfile` |
| Kalkulator | — |
| Kelas tabel | — |
| Formulir resmi | SIDIK-FM-CAL-0523_Rev.2 - LEMBAR KERJA REFRACTOMETER.pdf |
| Folder master | `instrument-analiitk/Master_Olah_Data_Refractometer` |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | — |
| Serah-terima frontend | — |

## Berkas kode yang terlibat

- `app/Services/Calibration/Profiles/RefractometerProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/ConductivityBudgetTest.php`
- `tests/Feature/LembarKerjaTest.php`
- `tests/Feature/SertifikatCocokMasterTest.php`
- `tests/Unit/AngkaTandaNolTest.php`
- `tests/Unit/RefractometerBudgetTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Refractometer` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_Refractometer` | FORM VALIDASI | sumber nomor versi workbook (F1) | 179 |
| `Master_Olah_Data_Refractometer` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 178 |
| `Master_Olah_Data_Refractometer` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 151 |
| `Master_Olah_Data_Refractometer` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 172 |
| `Master_Olah_Data_Refractometer` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 50 |
| `Master_Olah_Data_Refractometer` | Tab Konversi Temperatur | tab data acuan (bisa disunting lewat versi) | 117 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olah_Data_Refractometer › Tab Konversi Temperatur**

```
T | Observed Value | Corrected Value | Deviation | Observed Value | Corrected Value | Deviation
oC | oBrix | oBrix | oBrix | n20D | n20D | n20D
19 | 20 | 19.93 | 0.07000000000000028 | 1.33299 | 1.3325399999999998 | 0.00045000000000006146
19.1 | 20 | 19.937 | 0.06299999999999883 | 1.33299 | 1.332585 | 0.0004049999999999887
19.2 | 20 | 19.944 | 0.05600000000000094 | 1.33299 | 1.33263 | 0.00035999999999991594
19.3 | 20 | 19.951 | 0.04899999999999949 | 1.33299 | 1.3326749999999998 | 0.00031500000000006523
```

## Kandidat nilai acuan yang masih ditulis di kode

Dikumpulkan otomatis (konstanta kelas + literal `ci`/`vi`/`u`/`titik`/… di method). **Belum digolongkan.** Pakai aturan AGENTS.md §Olah data: nilai standar, koreksi, CMC, MPE, tabel koefisien, konstanta fisika metode → lapis 1 (pindah ke paket); ekspresi & struktur budget → lapis 2 (tetap kode); kode dokumen, label, satuan tampil → lapis 3.

| Berkas:baris | Nama | Nilai awal | Jenis |
|---|---|---|---|
| `RefractometerProfile.php:106` | `KODE_METODE` | `'SIDIK-IK-CAL-0516_Rev.4';` | const |
| `RefractometerProfile.php:108` | `KODE_DOKUMEN` | `'SIDIK-FM-CAL-0523_Rev.2';` | const |
| `RefractometerProfile.php:110` | `JUMLAH_PENGULANGAN` | `5;` | const |
| `RefractometerProfile.php:112` | `SATUAN_N20D` | `'n20D';` | const |
| `RefractometerProfile.php:114` | `SATUAN_BRIX` | `'°Brix';` | const |
| `RefractometerProfile.php:120` | `SUHU_ACUAN` | `20.0;` | const |
| `RefractometerProfile.php:126` | `KOEF_SUHU` | `[` | const |
| `RefractometerProfile.php:132` | `SUHU_TABEL_MIN` | `19.0;` | const |
| `RefractometerProfile.php:134` | `SUHU_TABEL_MAKS` | `30.2;` | const |
| `RefractometerProfile.php:140` | `RESOLUSI` | `0.0001;` | const |
| `RefractometerProfile.php:150` | `TITIK` | `[` | const |
| `RefractometerProfile.php:171` | `TITIK_BRIX` | `[` | const |
| `RefractometerProfile.php:177` | `TITIK_PER_SATUAN` | `[` | const |
| `RefractometerProfile.php:196` | `titik` | `1.33659` | literal_dalam_method |
| `RefractometerProfile.php:197` | `titik` | `1.39986` | literal_dalam_method |
| `RefractometerProfile.php:198` | `titik` | `2.5` | literal_dalam_method |
| `RefractometerProfile.php:199` | `titik` | `40.0` | literal_dalam_method |
| `RefractometerProfile.php:214` | `STANDARD_TERCETAK` | `[` | const |
| `RefractometerProfile.php:243` | `THERMOHYGRO_TERCETAK` | `[` | const |
| `RefractometerProfile.php:392` | `ci` | `1.0` | literal_dalam_method |
| `RefractometerProfile.php:393` | `vi` | `200` | literal_dalam_method |
| `RefractometerProfile.php:400` | `ci` | `1.0` | literal_dalam_method |
| `RefractometerProfile.php:401` | `vi` | `1_000_000` | literal_dalam_method |
| `RefractometerProfile.php:412` | `ci` | `1.0` | literal_dalam_method |
| `RefractometerProfile.php:413` | `vi` | `50` | literal_dalam_method |
| `RefractometerProfile.php:427` | `vi` | `200` | literal_dalam_method |
| `RefractometerProfile.php:434` | `ci` | `1.0` | literal_dalam_method |

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
