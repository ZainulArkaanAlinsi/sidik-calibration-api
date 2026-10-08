# Spektrofotometer — paket `spectrophotometer`

| | |
|---|---|
| Kelompok | Analitik |
| Sumber data acuan hari ini | konstanta-php — — |
| Status di Studio | Gelombang 4 — ekstrak konstanta dari kode dulu (prompt P8) |
| Profil | `SpectrophotometerProfile` |
| Kalkulator | — |
| Kelas tabel | — |
| Formulir resmi | SIDIK-FM-CAL-0511_Rev.5 - LEMBAR KERJA SPECTROFOTOMETER.pdf |
| Folder master | `instrument-analiitk/Master_Olah_Data_Spectrofotometer` |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-r2-spektro.md` |
| Serah-terima frontend | — |

## Berkas kode yang terlibat

- `app/Services/Calibration/Profiles/SpectrophotometerProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/BentukPindaiFotoCocokTabelTest.php`
- `tests/Feature/LembarKerjaSpektroCocokCetakanTest.php`
- `tests/Feature/R2SpektroTest.php`
- `tests/Feature/SpectrophotometerApiTest.php`
- `tests/Feature/SpectrophotometerBudgetTest.php`
- `tests/Unit/AngkaTandaNolTest.php`
- `tests/Unit/SpektroDataLainTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Spectrofotometer` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 109 |
| `Master_Olah_Data_Spectrofotometer` | FORM VALIDASI | sumber nomor versi workbook (F1) | 170 |
| `Master_Olah_Data_Spectrofotometer` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 83 |
| `Master_Olah_Data_Spectrofotometer` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 101 |
| `Master_Olah_Data_Spectrofotometer` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 248 |
| `Master_Olah_Data_Spectrofotometer` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 85 |
| `Master_Olah_Data_Spectrofotometer` | STANDAR_KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 36 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olah_Data_Spectrofotometer › STANDAR_KALIBRATOR**

```
STANDAR FILTER | STANDAR FILTER RECALIBRATION
Calibration date : | 2020-10-02T00:00:00 | (EXPIRED) | Calibration date : | 2025-04-15T00:00:00 | (VALID-USED)
Panjang Gelombang (Holmium Oxide) | Panjang Gelombang (Holmium Oxide)
No. | Nilai Standard | Nilai Aktual | Koreksi | U95% | No. | Nilai Standard | Nilai Aktual | Koreksi | U95%
1 | 334.1 | - | 1 | 279.6 | - | 0.1
1 | 334.1 | - | 0.2 | 2 | 287.7 | -
```

## Kandidat nilai acuan yang masih ditulis di kode

Dikumpulkan otomatis (konstanta kelas + literal `ci`/`vi`/`u`/`titik`/… di method). **Belum digolongkan.** Pakai aturan AGENTS.md §Olah data: nilai standar, koreksi, CMC, MPE, tabel koefisien, konstanta fisika metode → lapis 1 (pindah ke paket); ekspresi & struktur budget → lapis 2 (tetap kode); kode dokumen, label, satuan tampil → lapis 3.

| Berkas:baris | Nama | Nilai awal | Jenis |
|---|---|---|---|
| `SpectrophotometerProfile.php:100` | `KODE_DOKUMEN` | `'SIDIK-FM-CAL-0511_Rev.5';` | const |
| `SpectrophotometerProfile.php:112` | `KODE_METODE` | `'SIDIK-IK-CAL-0508_Rev.4';` | const |
| `SpectrophotometerProfile.php:118` | `JUMLAH_PENGULANGAN` | `3;` | const |
| `SpectrophotometerProfile.php:136` | `PENGULANGAN_TRANSMITAN` | `6;` | const |
| `SpectrophotometerProfile.php:138` | `SATUAN_PANJANG_GELOMBANG` | `'nm';` | const |
| `SpectrophotometerProfile.php:140` | `SATUAN_TRANSMITAN` | `'%T';` | const |
| `SpectrophotometerProfile.php:150` | `R2_RSQ_STANDAR_UUT` | `'rsq_standar_uut';` | const |
| `SpectrophotometerProfile.php:164` | `TOLERANSI_TITIK` | `0.05;` | const |
| `SpectrophotometerProfile.php:192` | `TITIK` | `[` | const |
| `SpectrophotometerProfile.php:253` | `STANDARD_TERCETAK` | `[` | const |
| `SpectrophotometerProfile.php:279` | `THERMOHYGRO_TERCETAK` | `[` | const |

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
