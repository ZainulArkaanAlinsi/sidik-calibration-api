# Conductivity Meter — paket `conductivity`

| | |
|---|---|
| Kelompok | Analitik |
| Sumber data acuan hari ini | konstanta-php — — |
| Status di Studio | Gelombang 4 — ekstrak konstanta dari kode dulu (prompt P8) |
| Profil | `ConductivityProfile` |
| Kalkulator | — |
| Kelas tabel | — |
| Formulir resmi | SIDIK-FM-CAL-0510_Rev.5 - LEMBAR KERJA CONDUCTIVITY.pdf |
| Folder master | `instrument-analiitk/Master_Olah_Data_Conductivity` |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-conductivity.md` |
| Serah-terima frontend | `docs/perintah-frontend-conductivity.md` |

## Berkas kode yang terlibat

- `app/Services/Calibration/Profiles/ConductivityProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/ConductivityBudgetTest.php`
- `tests/Feature/ConductivitySesiVarianMiliTest.php`
- `tests/Feature/ConductivityUjungKeUjungTest.php`
- `tests/Feature/ThermohygroSemuaLembarTest.php`
- `tests/Feature/TitikKosongTidakMenggeserTest.php`
- `tests/Unit/AngkaTandaNolTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Conductivity` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_Conductivity` | FORM VALIDASI | sumber nomor versi workbook (F1) | 185 |
| `Master_Olah_Data_Conductivity` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 593 |
| `Master_Olah_Data_Conductivity` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 157 |
| `Master_Olah_Data_Conductivity` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 587 |
| `Master_Olah_Data_Conductivity` | SERTIFIKAT STYLE 1 | pratinjau sertifikat di layar Simulasi | 69 |
| `Master_Olah_Data_Conductivity` | SERTIFIKAT STYLE 2 | pratinjau sertifikat di layar Simulasi | 69 |
| `Master_Olah_Data_Conductivity` | nilai koefisien sensitifitas | tab Rumus — baca saja (lapis 2) | 86 |


## Kandidat nilai acuan yang masih ditulis di kode

Dikumpulkan otomatis (konstanta kelas + literal `ci`/`vi`/`u`/`titik`/… di method). **Belum digolongkan.** Pakai aturan AGENTS.md §Olah data: nilai standar, koreksi, CMC, MPE, tabel koefisien, konstanta fisika metode → lapis 1 (pindah ke paket); ekspresi & struktur budget → lapis 2 (tetap kode); kode dokumen, label, satuan tampil → lapis 3.

| Berkas:baris | Nama | Nilai awal | Jenis |
|---|---|---|---|
| `ConductivityProfile.php:80` | `KODE_DOKUMEN` | `'SIDIK-FM-CAL-0510_Rev.5';` | const |
| `ConductivityProfile.php:88` | `KODE_METODE` | `'SIDIK-IK-CAL-0507_Rev.6';` | const |
| `ConductivityProfile.php:90` | `JUMLAH_PENGULANGAN` | `5;` | const |
| `ConductivityProfile.php:92` | `SATUAN_MIKRO` | `'µS/cm';` | const |
| `ConductivityProfile.php:94` | `SATUAN_MILI` | `'mS/cm';` | const |
| `ConductivityProfile.php:123` | `SLOT_CETAK` | `[` | const |
| `ConductivityProfile.php:124` | `titik` | `25.0` | literal_dalam_method |
| `ConductivityProfile.php:125` | `titik` | `1412.0` | literal_dalam_method |
| `ConductivityProfile.php:126` | `titik` | `111.0` | literal_dalam_method |
| `ConductivityProfile.php:141` | `TITIK` | `[` | const |
| `ConductivityProfile.php:214` | `KOEF_SUHU` | `[` | const |
| `ConductivityProfile.php:228` | `KOEF_SUHU_1412_TERDOKUMENTASI` | `['a' => 2.0e-13, 'b' => 27.0, 'c' => 738.0];` | const |
| `ConductivityProfile.php:246` | `STANDARD_TERCETAK` | `[` | const |
| `ConductivityProfile.php:260` | `THERMOHYGRO_TERCETAK` | `[` | const |
| `ConductivityProfile.php:328` | `titik` | `25.0` | literal_dalam_method |
| `ConductivityProfile.php:329` | `titik` | `1412.0` | literal_dalam_method |
| `ConductivityProfile.php:330` | `titik` | `1.412` | literal_dalam_method |
| `ConductivityProfile.php:331` | `titik` | `111.0` | literal_dalam_method |
| `ConductivityProfile.php:475` | `ci` | `1.0` | literal_dalam_method |
| `ConductivityProfile.php:476` | `vi` | `200` | literal_dalam_method |
| `ConductivityProfile.php:483` | `ci` | `1.0` | literal_dalam_method |
| `ConductivityProfile.php:484` | `vi` | `1_000_000` | literal_dalam_method |
| `ConductivityProfile.php:497` | `vi` | `200` | literal_dalam_method |
| `ConductivityProfile.php:504` | `ci` | `1.0` | literal_dalam_method |

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
