# DO Meter — paket `do_meter`

| | |
|---|---|
| Kelompok | Analitik |
| Sumber data acuan hari ini | konstanta-php — — |
| Status di Studio | Gelombang 4 — ekstrak konstanta dari kode dulu (prompt P8) |
| Profil | `DoMeterProfile` |
| Kalkulator | — |
| Kelas tabel | — |
| Formulir resmi | SIDIK-FM-CAL-0532_Rev.2 - LEMBAR KERJA DO METER.pdf |
| Folder master | `instrument-analiitk/Master_Olah_Data_DO_Meter` |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | — |
| Serah-terima frontend | — |

## Berkas kode yang terlibat

- `app/Services/Calibration/Profiles/DoMeterProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Unit/DoMeterBudgetTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_DO_Meter` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_DO_Meter` | FORM VALIDASI | sumber nomor versi workbook (F1) | 173 |
| `Master_Olah_Data_DO_Meter` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 245 |
| `Master_Olah_Data_DO_Meter` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 65 |
| `Master_Olah_Data_DO_Meter` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 242 |
| `Master_Olah_Data_DO_Meter` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 52 |


## Kandidat nilai acuan yang masih ditulis di kode

Dikumpulkan otomatis (konstanta kelas + literal `ci`/`vi`/`u`/`titik`/… di method). **Belum digolongkan.** Pakai aturan AGENTS.md §Olah data: nilai standar, koreksi, CMC, MPE, tabel koefisien, konstanta fisika metode → lapis 1 (pindah ke paket); ekspresi & struktur budget → lapis 2 (tetap kode); kode dokumen, label, satuan tampil → lapis 3.

| Berkas:baris | Nama | Nilai awal | Jenis |
|---|---|---|---|
| `DoMeterProfile.php:68` | `KODE_METODE` | `'SIDIK-IK-CAL-0530_Rev.2';` | const |
| `DoMeterProfile.php:70` | `KODE_DOKUMEN` | `'SIDIK-FM-CAL-0532_Rev.2';` | const |
| `DoMeterProfile.php:72` | `JUMLAH_PENGULANGAN` | `5;` | const |
| `DoMeterProfile.php:74` | `SATUAN` | `'mg/L';` | const |
| `DoMeterProfile.php:77` | `RESOLUSI` | `0.01;` | const |
| `DoMeterProfile.php:84` | `TOLERANSI_TITIK` | `0.05;` | const |
| `DoMeterProfile.php:95` | `U95_MICROPIPETTE` | `0.89;` | const |
| `DoMeterProfile.php:97` | `U95_LABU_UKUR` | `0.033;` | const |
| `DoMeterProfile.php:108` | `TITIK` | `[` | const |
| `DoMeterProfile.php:119` | `STANDARD_TERCETAK` | `[` | const |
| `DoMeterProfile.php:138` | `THERMOHYGRO_TERCETAK` | `[` | const |
| `DoMeterProfile.php:263` | `titik` | `8.77` | literal_dalam_method |
| `DoMeterProfile.php:380` | `ci` | `1.0` | literal_dalam_method |
| `DoMeterProfile.php:381` | `vi` | `200` | literal_dalam_method |
| `DoMeterProfile.php:388` | `ci` | `1.0` | literal_dalam_method |
| `DoMeterProfile.php:389` | `vi` | `1_000_000` | literal_dalam_method |
| `DoMeterProfile.php:400` | `vi` | `200` | literal_dalam_method |
| `DoMeterProfile.php:411` | `vi` | `200` | literal_dalam_method |
| `DoMeterProfile.php:418` | `ci` | `1.0` | literal_dalam_method |

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
