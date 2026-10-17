# Viscometer — paket `viscometer`

| | |
|---|---|
| Kelompok | Analitik |
| Sumber data acuan hari ini | konstanta-php — — |
| Status di Studio | Gelombang 4 — ekstrak konstanta dari kode dulu (prompt P8) |
| Profil | `ViscometerProfile` |
| Kalkulator | — |
| Kelas tabel | — |
| Formulir resmi | SIDIK-FM-CAL-0524_Rev.3 - LEMBAR KERJA VISCOMETER.pdf |
| Folder master | `instrument-analiitk/Master_Olah_Data_Viscometer`, `instrument-analiitk/5__Viscometer_86068360_terbaru_` |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-viscometer.md` |
| Serah-terima frontend | `docs/perintah-frontend-viscometer.md` |

## Berkas kode yang terlibat

- `app/Services/Calibration/Profiles/ViscometerProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/BentukPindaiFotoCocokTabelTest.php`
- `tests/Feature/PindaiViscometerTest.php`
- `tests/Feature/ViscometerApiTest.php`
- `tests/Feature/ViscometerMasterBaruTest.php`
- `tests/Feature/ViscometerSesiLainTest.php`
- `tests/Unit/ViscometerBudgetTest.php`
- `tests/Unit/ViscometerDataLainTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Viscometer` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_Viscometer` | FORM VALIDASI | sumber nomor versi workbook (F1) | 191 |
| `Master_Olah_Data_Viscometer` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 62 |
| `Master_Olah_Data_Viscometer` | MPE Visco | tab data acuan (bisa disunting lewat versi) | 84 |
| `Master_Olah_Data_Viscometer` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 89 |
| `Master_Olah_Data_Viscometer` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 242 |
| `Master_Olah_Data_Viscometer` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 53 |
| `Master_Olah_Data_Viscometer` | Tabel Pengaruh Temperature | tab data acuan (bisa disunting lewat versi) | 84 |
| `5__Viscometer_86068360_terbaru_` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `5__Viscometer_86068360_terbaru_` | FORM VALIDASI | sumber nomor versi workbook (F1) | 191 |
| `5__Viscometer_86068360_terbaru_` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 64 |
| `5__Viscometer_86068360_terbaru_` | MPE Visco | tab data acuan (bisa disunting lewat versi) | 84 |
| `5__Viscometer_86068360_terbaru_` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 104 |
| `5__Viscometer_86068360_terbaru_` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 242 |
| `5__Viscometer_86068360_terbaru_` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 66 |
| `5__Viscometer_86068360_terbaru_` | Tabel Pengaruh Temperature | tab data acuan (bisa disunting lewat versi) | 115 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olah_Data_Viscometer › MPE Visco**

```
Based on Viscometer Operating Instruction No. M13-167-B0614
Table D-2 for finding the TK (Torque Constant) value
No. | MODEL (on body) | MODEL (on screen display) | TK
1 | DV2TLV | LV | 0.09373
2 | 2.5DV2TLV | L3 | 0.2343
3 | 5DV2TLV | L5 | 0.4686
```
**Master_Olah_Data_Viscometer › Tabel Pengaruh Temperature**

```
Temperature | cP | Temperature | cP | Selisih
0 | 1.7915 | 21 | 0.961512 | -0.02479599999999993
5 | 1.5193 | 22 | 0.9367160000000001 | -0.023851999999999984
10 | 1.307 | 23 | 0.9128640000000001 | -0.022955999999999976
15 | 1.1383 | 24 | 0.8899080000000001 | -0.022108000000000017 | 25 | 0.8678000000000001 | -0.0809120000000001
20 | 1.002 | 25 | 0.8678000000000001 | -0.021307999999999883 | 29 | 0.786888
```
**5__Viscometer_86068360_terbaru_ › MPE Visco**

```
Based on Viscometer Operating Instruction No. M13-167-B0614
Table D-2 for finding the TK (Torque Constant) value
No. | MODEL (on body) | MODEL (on screen display) | TK
1 | DV2TLV | LV | 0.09373
2 | 2.5DV2TLV | L3 | 0.2343
3 | 5DV2TLV | L5 | 0.4686
```
**5__Viscometer_86068360_terbaru_ › Tabel Pengaruh Temperature**

```
Temperature | cP | Temperature | cP | Selisih
0 | 1.7915 | 21 | 0.961512 | -0.02479599999999993
5 | 1.5193 | 22 | 0.9367160000000001 | -0.023851999999999984
10 | 1.307 | 23 | 0.9128640000000001 | -0.022955999999999976
15 | 1.1383 | 24 | 0.8899080000000001 | -0.022108000000000017 | 25 | 0.8678000000000001 | -0.0809120000000001
20 | 1.002 | 25 | 0.8678000000000001 | -0.021307999999999883 | 29 | 0.786888
```

## Kandidat nilai acuan yang masih ditulis di kode

Dikumpulkan otomatis (konstanta kelas + literal `ci`/`vi`/`u`/`titik`/… di method). **Belum digolongkan.** Pakai aturan AGENTS.md §Olah data: nilai standar, koreksi, CMC, MPE, tabel koefisien, konstanta fisika metode → lapis 1 (pindah ke paket); ekspresi & struktur budget → lapis 2 (tetap kode); kode dokumen, label, satuan tampil → lapis 3.

| Berkas:baris | Nama | Nilai awal | Jenis |
|---|---|---|---|
| `ViscometerProfile.php:112` | `KODE_DOKUMEN` | `'SIDIK-FM-CAL-0524_Rev.3';` | const |
| `ViscometerProfile.php:120` | `KODE_METODE` | `'SIDIK-IK-CAL-0517_Rev.3';` | const |
| `ViscometerProfile.php:122` | `JUMLAH_PENGULANGAN` | `5;` | const |
| `ViscometerProfile.php:124` | `SATUAN` | `'cP';` | const |
| `ViscometerProfile.php:138` | `SUHU_ACUAN` | `25.0;` | const |
| `ViscometerProfile.php:149` | `VI_TEMPERATURE` | `50;` | const |
| `ViscometerProfile.php:152` | `VI_KALIBRATOR` | `200;` | const |
| `ViscometerProfile.php:159` | `PEMBAGI_CI_SUHU` | `400.0;` | const |
| `ViscometerProfile.php:167` | `PERSEN_MPE` | `0.01;` | const |
| `ViscometerProfile.php:185` | `DESIMAL_SERTIFIKAT` | `2;` | const |
| `ViscometerProfile.php:203` | `SPINDLE_TIDAK_DIPINDAI` | `true;` | const |
| `ViscometerProfile.php:228` | `TITIK` | `[` | const |
| `ViscometerProfile.php:305` | `TITIK_TANPA_CMC` | `['3000', '100000'];` | const |
| `ViscometerProfile.php:315` | `TABEL_TK` | `[` | const |
| `ViscometerProfile.php:339` | `TABEL_SMC` | `[` | const |
| `ViscometerProfile.php:412` | `STANDARD_TERCETAK` | `[` | const |
| `ViscometerProfile.php:434` | `THERMOHYGRO_TERCETAK` | `[` | const |
| `ViscometerProfile.php:689` | `JANGKAUAN_LARUTAN` | `[` | const |
| `ViscometerProfile.php:709` | `KELONGGARAN_PITA` | `1.2;` | const |
| `ViscometerProfile.php:821` | `ci` | `1.0` | literal_dalam_method |
| `ViscometerProfile.php:829` | `ci` | `1.0` | literal_dalam_method |
| `ViscometerProfile.php:830` | `vi` | `1_000_000` | literal_dalam_method |
| `ViscometerProfile.php:848` | `ci` | `1.0` | literal_dalam_method |

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
