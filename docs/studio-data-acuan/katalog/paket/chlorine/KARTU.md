# Chlorine Meter — paket `chlorine`

| | |
|---|---|
| Kelompok | Analitik |
| Sumber data acuan hari ini | konstanta-php — — |
| Status di Studio | Gelombang 4 — ekstrak konstanta dari kode dulu (prompt P8) |
| Profil | `ChlorineProfile` |
| Kalkulator | — |
| Kelas tabel | — |
| Formulir resmi | SIDIK-FM-CAL-0531_Rev.2 - LEMBAR KERJA CHLORINE.pdf |
| Folder master | `instrument-analiitk/Master_Olah_Data_Chlorine_Meter` |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | — |
| Serah-terima frontend | — |

## Berkas kode yang terlibat

- `app/Services/Calibration/Profiles/ChlorineProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/CalibrationValidationTest.php`
- `tests/Feature/ConductivityBudgetTest.php`
- `tests/Feature/U95PerTitikInstrumenAnalitikTest.php`
- `tests/Unit/AngkaTandaNolTest.php`
- `tests/Unit/ChlorineBudgetTest.php`
- `tests/Unit/PengulanganBebasTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Chlorine_Meter` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 110 |
| `Master_Olah_Data_Chlorine_Meter` | FORM VALIDASI | sumber nomor versi workbook (F1) | 173 |
| `Master_Olah_Data_Chlorine_Meter` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 245 |
| `Master_Olah_Data_Chlorine_Meter` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 92 |
| `Master_Olah_Data_Chlorine_Meter` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 242 |
| `Master_Olah_Data_Chlorine_Meter` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 51 |


## Kandidat nilai acuan yang masih ditulis di kode

Dikumpulkan otomatis (konstanta kelas + literal `ci`/`vi`/`u`/`titik`/… di method). **Belum digolongkan.** Pakai aturan AGENTS.md §Olah data: nilai standar, koreksi, CMC, MPE, tabel koefisien, konstanta fisika metode → lapis 1 (pindah ke paket); ekspresi & struktur budget → lapis 2 (tetap kode); kode dokumen, label, satuan tampil → lapis 3.

| Berkas:baris | Nama | Nilai awal | Jenis |
|---|---|---|---|
| `ChlorineProfile.php:60` | `KODE_METODE` | `'SIDIK-IK-CAL-0524_Rev.1';` | const |
| `ChlorineProfile.php:62` | `KODE_DOKUMEN` | `'SIDIK-FM-CAL-0531_Rev.2';` | const |
| `ChlorineProfile.php:64` | `JUMLAH_PENGULANGAN` | `5;` | const |
| `ChlorineProfile.php:66` | `SATUAN` | `'mg/L';` | const |
| `ChlorineProfile.php:73` | `RESOLUSI` | `0.01;` | const |
| `ChlorineProfile.php:84` | `TOLERANSI_TITIK` | `0.05;` | const |
| `ChlorineProfile.php:92` | `TITIK` | `[` | const |
| `ChlorineProfile.php:130` | `CI_PENGENCERAN` | `0.00028720439420210824;` | const |
| `ChlorineProfile.php:136` | `U95_MICROPIPETTE_ML` | `0.00089;` | const |
| `ChlorineProfile.php:138` | `U95_LABU_UKUR_ML` | `0.033;` | const |
| `ChlorineProfile.php:146` | `STANDARD_TERCETAK` | `[` | const |
| `ChlorineProfile.php:169` | `titik` | `1.74` | literal_dalam_method |
| `ChlorineProfile.php:170` | `titik` | `1.83` | literal_dalam_method |
| `ChlorineProfile.php:192` | `THERMOHYGRO_TERCETAK` | `[` | const |
| `ChlorineProfile.php:341` | `ci` | `1.0` | literal_dalam_method |
| `ChlorineProfile.php:342` | `vi` | `200` | literal_dalam_method |
| `ChlorineProfile.php:349` | `ci` | `1.0` | literal_dalam_method |
| `ChlorineProfile.php:350` | `vi` | `1_000_000` | literal_dalam_method |
| `ChlorineProfile.php:361` | `vi` | `200` | literal_dalam_method |
| `ChlorineProfile.php:372` | `vi` | `200` | literal_dalam_method |
| `ChlorineProfile.php:379` | `ci` | `1.0` | literal_dalam_method |

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
