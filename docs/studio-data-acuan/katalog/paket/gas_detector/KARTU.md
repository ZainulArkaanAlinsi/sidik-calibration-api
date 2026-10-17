# Gas Detector — paket `gas_detector`

| | |
|---|---|
| Kelompok | Analitik |
| Sumber data acuan hari ini | konstanta-php — — |
| Status di Studio | Gelombang 4 — ekstrak konstanta dari kode dulu (prompt P8) |
| Profil | `GasDetectorProfile` |
| Kalkulator | — |
| Kelas tabel | — |
| Formulir resmi | — (tidak ada di worksheet_alat_calibration/) |
| Folder master | `instrument-analiitk/Gas_Detector_Uli_Skin__std_Rigaz_` |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-gas-detector.md` |
| Serah-terima frontend | `docs/perintah-frontend-gas-detector.md` |

## Berkas kode yang terlibat

- `app/Services/Calibration/Profiles/GasDetectorProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/GasDetectorSesiTest.php`
- `tests/Unit/GasDetectorBudgetTest.php`
- `tests/Unit/PeringatanProfilBentukTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Gas_Detector_Uli_Skin__std_Rigaz_` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 86 |
| `Gas_Detector_Uli_Skin__std_Rigaz_` | FORM VALIDASI | sumber nomor versi workbook (F1) | 188 |
| `Gas_Detector_Uli_Skin__std_Rigaz_` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 245 |
| `Gas_Detector_Uli_Skin__std_Rigaz_` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 102 |
| `Gas_Detector_Uli_Skin__std_Rigaz_` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 243 |
| `Gas_Detector_Uli_Skin__std_Rigaz_` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 59 |


## Kandidat nilai acuan yang masih ditulis di kode

Dikumpulkan otomatis (konstanta kelas + literal `ci`/`vi`/`u`/`titik`/… di method). **Belum digolongkan.** Pakai aturan AGENTS.md §Olah data: nilai standar, koreksi, CMC, MPE, tabel koefisien, konstanta fisika metode → lapis 1 (pindah ke paket); ekspresi & struktur budget → lapis 2 (tetap kode); kode dokumen, label, satuan tampil → lapis 3.

| Berkas:baris | Nama | Nilai awal | Jenis |
|---|---|---|---|
| `GasDetectorProfile.php:121` | `KODE_DOKUMEN` | `null;` | const |
| `GasDetectorProfile.php:127` | `KODE_METODE` | `'SIDIK-IK-CAL-0536_Rev.0';` | const |
| `GasDetectorProfile.php:141` | `JUMLAH_PENGULANGAN` | `3;` | const |
| `GasDetectorProfile.php:147` | `CI_PERSEN_SUHU` | `0.0034;` | const |
| `GasDetectorProfile.php:150` | `CI_PERSEN_TEKANAN` | `0.0098;` | const |
| `GasDetectorProfile.php:166` | `PEMBAGI_TEKANAN` | `5.0;` | const |
| `GasDetectorProfile.php:177` | `VI_RESOLUSI` | `60;` | const |
| `GasDetectorProfile.php:180` | `VI_STANDAR` | `200;` | const |
| `GasDetectorProfile.php:193` | `PERSEN_U95_STANDAR` | `0.05;` | const |
| `GasDetectorProfile.php:207` | `TOLERANSI_TITIK` | `3.0;` | const |
| `GasDetectorProfile.php:242` | `GAS` | `[` | const |
| `GasDetectorProfile.php:307` | `STANDARD_TERCETAK` | `[` | const |
| `GasDetectorProfile.php:329` | `PARAMETER_KONDISI_WAJIB` | `'tekanan';` | const |
| `GasDetectorProfile.php:675` | `ci` | `1.0` | literal_dalam_method |
| `GasDetectorProfile.php:683` | `ci` | `1.0` | literal_dalam_method |
| `GasDetectorProfile.php:697` | `ci` | `1.0` | literal_dalam_method |

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
