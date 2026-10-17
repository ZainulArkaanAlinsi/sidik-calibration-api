# pH Meter — paket `ph_meter`

| | |
|---|---|
| Kelompok | Analitik |
| Sumber data acuan hari ini | konstanta-php — — |
| Status di Studio | Gelombang 4 — ekstrak konstanta dari kode dulu (prompt P8) |
| Profil | `PhMeterProfile` |
| Kalkulator | — |
| Kelas tabel | — |
| Formulir resmi | SIDIK-FM-CAL-0509_Rev.4 - LEMBAR KERJA pH METER.pdf |
| Folder master | `instrument-analiitk/pH_meter_IMTE-WQ-129` |
| Generator | — |
| Fixture master | `database/data/kalibrasi-ph-meter.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-ph-dua-master.md` |
| Serah-terima frontend | — |

**Catatan:** Titik buffer (4,00 / 7,00 / 10,01) dan ci/vi komponen budget ditulis di dalam method profil; nilai standar buffer (U, k, drift) sudah di tabel `standards`.

## Berkas kode yang terlibat

- `app/Services/Calibration/Profiles/PhMeterProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/ConductivityBudgetTest.php`
- `tests/Feature/Suhu3AlatLembarKerjaTest.php`
- `tests/Feature/TidsLembarKerjaTest.php`
- `tests/Unit/AngkaTandaNolTest.php`
- `tests/Unit/PengulanganBebasTest.php`
- `tests/Unit/PhMeterMasterTest.php`
- `tests/Unit/RoutingProfilSepakatTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `pH_meter_IMTE-WQ-129` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 110 |
| `pH_meter_IMTE-WQ-129` | FORM VALIDASI | sumber nomor versi workbook (F1) | 176 |
| `pH_meter_IMTE-WQ-129` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 244 |
| `pH_meter_IMTE-WQ-129` | Nilai koefisien Sensitifitas | tab Rumus — baca saja (lapis 2) | 54 |
| `pH_meter_IMTE-WQ-129` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 82 |
| `pH_meter_IMTE-WQ-129` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 242 |
| `pH_meter_IMTE-WQ-129` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 56 |


## Kandidat nilai acuan yang masih ditulis di kode

Dikumpulkan otomatis (konstanta kelas + literal `ci`/`vi`/`u`/`titik`/… di method). **Belum digolongkan.** Pakai aturan AGENTS.md §Olah data: nilai standar, koreksi, CMC, MPE, tabel koefisien, konstanta fisika metode → lapis 1 (pindah ke paket); ekspresi & struktur budget → lapis 2 (tetap kode); kode dokumen, label, satuan tampil → lapis 3.

| Berkas:baris | Nama | Nilai awal | Jenis |
|---|---|---|---|
| `PhMeterProfile.php:35` | `KODE_METODE` | `'SIDIK-IK-CAL-0506_Rev.6';` | const |
| `PhMeterProfile.php:78` | `titik` | `4.00` | literal_dalam_method |
| `PhMeterProfile.php:79` | `titik` | `7.00` | literal_dalam_method |
| `PhMeterProfile.php:80` | `titik` | `10.01` | literal_dalam_method |
| `PhMeterProfile.php:117` | `ci` | `1.0` | literal_dalam_method |
| `PhMeterProfile.php:118` | `vi` | `200` | literal_dalam_method |
| `PhMeterProfile.php:125` | `ci` | `1.0` | literal_dalam_method |
| `PhMeterProfile.php:126` | `vi` | `1_000_000` | literal_dalam_method |
| `PhMeterProfile.php:137` | `vi` | `200` | literal_dalam_method |
| `PhMeterProfile.php:148` | `vi` | `50` | literal_dalam_method |
| `PhMeterProfile.php:155` | `ci` | `1.0` | literal_dalam_method |

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
