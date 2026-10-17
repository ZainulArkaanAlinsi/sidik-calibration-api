# Turbidimeter — paket `turbidimeter`

| | |
|---|---|
| Kelompok | Analitik |
| Sumber data acuan hari ini | konstanta-php — — |
| Status di Studio | Gelombang 4 — ekstrak konstanta dari kode dulu (prompt P8) |
| Profil | `TurbidimeterProfile` |
| Kalkulator | — |
| Kelas tabel | — |
| Formulir resmi | SIDIK-FM-CAL-0530_Rev.2 - LEMBAR KERJA TURBIDIMETER.pdf |
| Folder master | `instrument-analiitk/Master_Olah_Data_Turbidimeter` |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | — |
| Serah-terima frontend | — |

## Berkas kode yang terlibat

- `app/Services/Calibration/Profiles/TurbidimeterProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/ConductivityBudgetTest.php`
- `tests/Feature/LembarKerjaTest.php`
- `tests/Unit/AngkaTandaNolTest.php`
- `tests/Unit/PengulanganBebasTest.php`
- `tests/Unit/TurbidimeterBudgetTest.php`
- `tests/Unit/ViscometerBudgetTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Turbidimeter` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_Turbidimeter` | FORM VALIDASI | sumber nomor versi workbook (F1) | 173 |
| `Master_Olah_Data_Turbidimeter` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 248 |
| `Master_Olah_Data_Turbidimeter` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 66 |
| `Master_Olah_Data_Turbidimeter` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 242 |
| `Master_Olah_Data_Turbidimeter` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 55 |


## Kandidat nilai acuan yang masih ditulis di kode

Dikumpulkan otomatis (konstanta kelas + literal `ci`/`vi`/`u`/`titik`/… di method). **Belum digolongkan.** Pakai aturan AGENTS.md §Olah data: nilai standar, koreksi, CMC, MPE, tabel koefisien, konstanta fisika metode → lapis 1 (pindah ke paket); ekspresi & struktur budget → lapis 2 (tetap kode); kode dokumen, label, satuan tampil → lapis 3.

| Berkas:baris | Nama | Nilai awal | Jenis |
|---|---|---|---|
| `TurbidimeterProfile.php:49` | `KODE_METODE` | `'SIDIK-IK-CAL-0523_Rev.1';` | const |
| `TurbidimeterProfile.php:51` | `KODE_DOKUMEN` | `'SIDIK-FM-CAL-0530_Rev.2';` | const |
| `TurbidimeterProfile.php:53` | `JUMLAH_PENGULANGAN` | `5;` | const |
| `TurbidimeterProfile.php:63` | `TITIK` | `[` | const |
| `TurbidimeterProfile.php:69` | `SATUAN` | `'NTU';` | const |
| `TurbidimeterProfile.php:78` | `STANDARD_TERCETAK` | `[` | const |
| `TurbidimeterProfile.php:104` | `THERMOHYGRO_TERCETAK` | `[` | const |
| `TurbidimeterProfile.php:123` | `titik` | `1.0` | literal_dalam_method |
| `TurbidimeterProfile.php:124` | `titik` | `100.0` | literal_dalam_method |
| `TurbidimeterProfile.php:125` | `titik` | `1000.0` | literal_dalam_method |
| `TurbidimeterProfile.php:222` | `ci` | `1.0` | literal_dalam_method |
| `TurbidimeterProfile.php:223` | `vi` | `200` | literal_dalam_method |
| `TurbidimeterProfile.php:230` | `ci` | `1.0` | literal_dalam_method |
| `TurbidimeterProfile.php:231` | `vi` | `1_000_000` | literal_dalam_method |
| `TurbidimeterProfile.php:242` | `vi` | `200` | literal_dalam_method |
| `TurbidimeterProfile.php:249` | `ci` | `1.0` | literal_dalam_method |

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
