# Sieve Mesh — paket `sieve`

| | |
|---|---|
| Kelompok | Panjang |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-sieve.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `SieveProfile` |
| Kalkulator | `SieveCalculator` |
| Kelas tabel | `TabelStandarSieve` |
| Formulir resmi | SIDIK-FM-CAL-0536_Rev.2 - LEMBAR KERJA SIEVE MESH.pdf |
| Folder master | `Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Sieve_Mesh` |
| Generator | `docs/skrip/gen-tabel-standar-sieve.py` |
| Fixture master | `database/data/sesi-master-sieve.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-sieve.md` |
| Serah-terima frontend | `docs/perintah-frontend-sieve.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarSieve` ← `CalibrationController`, `SieveCalculator`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarSieve.php`
- `app/Services/Calibration/SieveCalculator.php`
- `app/Services/Calibration/Profiles/SieveProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/JangkaSorongSieveSesiTest.php`
- `tests/Unit/SieveMasterTest.php`
- `tests/Unit/SieveMpeAstmTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Sieve_Mesh` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 108 |
| `Master_Olah_Data_Sieve_Mesh` | FORM VALIDASI | sumber nomor versi workbook (F1) | 179 |
| `Master_Olah_Data_Sieve_Mesh` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 319 |
| `Master_Olah_Data_Sieve_Mesh` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 63 |
| `Master_Olah_Data_Sieve_Mesh` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 323 |
| `Master_Olah_Data_Sieve_Mesh` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 56 |
| `Master_Olah_Data_Sieve_Mesh` | STANDAR_KALIBRATOR | tab data acuan (bisa disunting lewat versi) | 148 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olah_Data_Sieve_Mesh › STANDAR_KALIBRATOR**

```
STANDAR KALIBRATOR
Name | Digital Microscope | Tgl Kalibrasi | 2026-02-13T00:00:00 | Name | Digital Caliper | Tgl Kalibrasi | 2025-07-25T00:00:00
No Seri | B1401068 | No Seri | LPI-0368
Merk | Dino-Lite | Merk | Tesa
Tipe | AF3113 | Tipe | Cal-IP67
Rentang Ukur | - | Rentang Ukur | 150 | mm
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-sieve.json`: 1493/1493 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `standar` | tabel_berkunci | 2 | 16 | lebar | nama·teks; merk_tipe·teks; seri·teks; traceability·teks; tanggal_kalibrasi·tanggal; interval_tahun·desimal; berlaku_sampai·tanggal; resolusi_mm·desimal·mm |
| `koreksi/caliper` | tabel | 10 | 30 | lebar | nilai_standar_mm·desimal·mm; penunjukan_mm·desimal·mm; koreksi_mm·desimal·mm |
| `koreksi/mikroskop_x` | tabel | 11 | 33 | lebar | nilai_standar_mm·desimal·mm; penunjukan_mm·desimal·mm; koreksi_mm·desimal·mm |
| `koreksi/mikroskop_y` | tabel | 11 | 33 | lebar | nilai_standar_mm·desimal·mm; penunjukan_mm·desimal·mm; koreksi_mm·desimal·mm |
| `u95_sertifikat_mm` | peta | 3 | 3 | lebar | caliper·desimal; mikroskop_x·desimal; mikroskop_y·desimal |
| `cmc_master` | tabel | 2 | 10 | lebar | label·teks; standar·teks; ukuran_min_mm·desimal·mm; ukuran_maks_mm·desimal·mm; u95_mm·desimal·mm; ukuran_min_mm_eksklusif·desimal |
| `cmc_lampiran` | tabel | 2 | 8 | lebar | label·teks; ukuran_min_mm·desimal·mm; ukuran_maks_mm·desimal·mm; u95_mm·desimal·mm |
| `konstanta` | peta | 12 | 12 | lebar | suhu_acuan_c·desimal·°C; delta_alpha_per_c·desimal·/°C; vi_sertifikat·desimal; daya_baca_pengali·desimal; vi_daya_baca·desimal; geometri_mm·desimal·mm; vi_geometri·desimal; vi_suhu·desimal; pembagi_pengulangan·desimal; vi_pengulangan·desimal; jumlah_opening_pengulangan·desimal; k·desimal |
| `konstanta/u_alpha_pengali` | peta | 2 | 2 | lebar | mikroskop·desimal; caliper·desimal |
| `konstanta/pembagi_sertifikat` | peta | 2 | 2 | lebar | mikroskop·desimal; caliper·desimal |
| `mpe` | tabel | 96 | 1344 | lebar | ukuran_mm·desimal·mm; sieve_no·teks; ukuran_um·desimal·µm; ukuran_inch·desimal; min_opening.compliance·desimal; min_opening.inspection·teks; min_opening.calibration·teks; x_mm·desimal·mm; y_mm·desimal·mm; max_stdev_mm·teks·mm; … +4 |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!R13:Z14`, `DATABASE!S5:T6`, `DATABASE!Z13`, `INPUT DATA!H89:N91`, `INPUT DATA!Z16`, `PERHITUNGAN U95%!AC22`, `PERHITUNGAN U95%!N15`, `PERHITUNGAN U95%!X15`, `PERHITUNGAN!G17`, `PERHITUNGAN!G81`, `PERHITUNGAN!J82`, `STANDAR_KALIBRATOR!B41:N136`, `master INPUT DATA!H89:N91`, `resolusi STANDAR_KALIBRATOR!C7`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-sieve.json:_sumber**: Master Olah Data_Sieve Mesh.xlsm (sheet STANDAR_KALIBRATOR & DATABASE), ber-password. Tabel_MPE disalin apa adanya; kolom yang janggal DITANDAI di `tanda`, tidak dibetulkan.
- **tabel-standar-sieve.json:_digenerate_oleh**: docs/skrip/gen-tabel-standar-sieve.py

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
