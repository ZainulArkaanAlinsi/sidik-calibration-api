# Autoclave — paket `autoclave`

| | |
|---|---|
| Kelompok | Suhu & tekanan |
| Sumber data acuan hari ini | konstanta-php — — |
| Status di Studio | Gelombang 4 — ekstrak konstanta dari kode dulu (prompt P8) |
| Profil | `AutoclaveProfile` |
| Kalkulator | — |
| Kelas tabel | — |
| Formulir resmi | SIDIK-FM-CAL-0539_Rev.4 - LEMBAR KERJA AUTOCLAVE.pdf |
| Folder master | `instrument-analiitk/Master_Olah_Data_Autoclave` |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | — |
| Serah-terima frontend | — |

## Berkas kode yang terlibat

- `app/Services/Calibration/Profiles/AutoclaveProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/AutoclaveApiTest.php`
- `tests/Feature/AutoclaveCertificateTest.php`
- `tests/Feature/AutoclaveSeederTest.php`
- `tests/Feature/AutoclaveStoreTest.php`
- `tests/Feature/KoreksiTekananMustahilTest.php`
- `tests/Feature/PindaiAutoclaveTest.php`
- `tests/Feature/TebakanMesinAutoclaveTest.php`
- `tests/Unit/AutoclaveCalculatorTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Autoclave` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_Autoclave` | Drawing Autoclave | bukan data — tidak masuk Studio | 13 |
| `Master_Olah_Data_Autoclave` | FORM VALIDASI | sumber nomor versi workbook (F1) | 197 |
| `Master_Olah_Data_Autoclave` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 57 |
| `Master_Olah_Data_Autoclave` | PERHITUNGAN FC | tab Rumus — baca saja (lapis 2) | 128 |
| `Master_Olah_Data_Autoclave` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 135 |
| `Master_Olah_Data_Autoclave` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 71 |
| `Master_Olah_Data_Autoclave` | STANDAR KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 63 |
| `Master_Olah_Data_Autoclave` | Tabel Suhu - Tekanan | tab data acuan (bisa disunting lewat versi) | 19 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olah_Data_Autoclave › STANDAR KALIBRATOR**

```
Nama Alat | : | Pressure Disk | Tanggal Kalibrasi | : | 2025-06-16T00:00:00
Merek | : | Tecnosoft | Due Date Kalibrasi | : | 2026-06-17T00:00:00
Type | : | PressureDisk 05 | Tertelusur | : | LK-285-IDN
S/N | : | 3501009550 | Resolusi | : | 0.001 | Bar
Set Point (bar) | Correction (bar) | U95% (bar)
UP | DOWN
```
**Master_Olah_Data_Autoclave › Tabel Suhu - Tekanan**

```
Reference:  https://blueskypharmacy.wordpress.com/2022/12/07/sterilisasi-media-kultur-difco/
1 pound | 6.89475728 | kPa | Grafik Hubungan Pressure dan Temp
Pounds | Pressure (kPa) | Pressure (MPa) | Temp (oC)
5 | 34.4737864 | 0.034473786400000005 | 109
10 | 68.9475728 | 0.06894757280000001 | 115
15 | 103.42135920000001 | 0.10342135920000001 | 121
```

## Kandidat nilai acuan yang masih ditulis di kode

Dikumpulkan otomatis (konstanta kelas + literal `ci`/`vi`/`u`/`titik`/… di method). **Belum digolongkan.** Pakai aturan AGENTS.md §Olah data: nilai standar, koreksi, CMC, MPE, tabel koefisien, konstanta fisika metode → lapis 1 (pindah ke paket); ekspresi & struktur budget → lapis 2 (tetap kode); kode dokumen, label, satuan tampil → lapis 3.

| Berkas:baris | Nama | Nilai awal | Jenis |
|---|---|---|---|
| `AutoclaveProfile.php:50` | `KODE_METODE` | `'SIDIK-IK-CAL-0531_Rev.4';` | const |
| `AutoclaveProfile.php:52` | `KODE_DOKUMEN` | `'SIDIK-FM-CAL-0539_Rev.4';` | const |
| `AutoclaveProfile.php:55` | `JUMLAH_TITIK_WAKTU` | `5;` | const |
| `AutoclaveProfile.php:58` | `JUMLAH_DISK` | `3;` | const |
| `AutoclaveProfile.php:61` | `JUMLAH_PEMBACAAN_TEKANAN` | `5;` | const |
| `AutoclaveProfile.php:63` | `SATUAN_SUHU` | `'°C';` | const |
| `AutoclaveProfile.php:71` | `PITA_SUHU_KALIBRATOR` | `['min' => 21.0, 'maks' => 140.0];` | const |
| `AutoclaveProfile.php:74` | `PITA_SUHU_RUANG` | `['min' => 5.0, 'maks' => 45.0];` | const |
| `AutoclaveProfile.php:83` | `SATUAN_TEKANAN` | `[` | const |
| `AutoclaveProfile.php:95` | `DISPLAY_TEKANAN` | `[` | const |
| `AutoclaveProfile.php:103` | `KODE_LEMBAR` | `'LK-285-IDN';` | const |
| `AutoclaveProfile.php:117` | `STANDARD_TERCETAK` | `[` | const |
| `AutoclaveProfile.php:147` | `THERMOHYGRO_TERCETAK` | `[` | const |

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
