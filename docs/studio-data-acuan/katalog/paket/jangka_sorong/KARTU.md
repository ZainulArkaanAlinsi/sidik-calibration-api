# Jangka Sorong (Caliper) — paket `jangka_sorong`

| | |
|---|---|
| Kelompok | Panjang |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-jangka-sorong.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `JangkaSorongProfile` |
| Kalkulator | `JangkaSorongCalculator` |
| Kelas tabel | `TabelStandarJangkaSorong` |
| Formulir resmi | SIDIK-FM-CAL-0527_Rev.2 - LEMBAR KERJA JANGKA SORONG.pdf |
| Folder master | `Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` |
| Generator | `docs/skrip/gen-tabel-standar-jangka-sorong.py` |
| Fixture master | `database/data/sesi-master-jangka-sorong.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-jangka-sorong.md` |
| Serah-terima frontend | `docs/perintah-frontend-jangka-sorong.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarJangkaSorong` ← `JangkaSorongCalculator`, `JangkaSorongProfile`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarJangkaSorong.php`
- `app/Services/Calibration/JangkaSorongCalculator.php`
- `app/Services/Calibration/Profiles/JangkaSorongProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/CalibrationTest.php`
- `tests/Feature/JangkaSorongSieveSesiTest.php`
- `tests/Unit/JangkaSorongMasterTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 109 |
| `Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` | FORM VALIDASI | sumber nomor versi workbook (F1) | 182 |
| `Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 359 |
| `Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 61 |
| `Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 352 |
| `Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` | Perhitungan koef. Sensitivitas | tab Rumus — baca saja (lapis 2) | 7 |
| `Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` | SERTIFIKAT (2) | pratinjau sertifikat di layar Simulasi | 86 |
| `Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 86 |
| `Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` | Standar_GB | tab data acuan (bisa disunting lewat versi) | 133 |
| `Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_` | Std_CaliperCek | tab data acuan (bisa disunting lewat versi) | 32 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_ › Standar_GB**

```
STANDAR GAUGE BLOCK
Name : | Gauge block standard | Tanggal Kalibrasi | 2024-01-24T00:00:00
No Seri | 160006 | LK-410-IDN
Merk | Metrology
Tipe | GB-9122-0
Rentang Ukur | 0.5-100 mm
```
**Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_ › Std_CaliperCek**

```
STANDAR GAUGE BLOCK
Nama Alat | : | Caliper Checker | Tanggal Kalibrasi | 2026-01-09T00:00:00
No Seri | : | 800035 | Tertelusur | LK-404-IDN
Merk | : | Metrology
Tipe | : | CMG-9060C
Rentang Ukur | : | 0-600 mm
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-jangka-sorong.json`: 127/127 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `balok_ukur` | peta | 5 | 5 | lebar | nama·teks; merk_tipe·teks; seri·desimal_teks; traceability·teks; tanggal_kalibrasi·tanggal |
| `balok_ukur/keping` | tabel | 32 | 96 | lebar | nominal_mm·desimal·mm; nilai_terkoreksi_mm·desimal·mm; u95_mm·desimal·mm |
| `cmc` | peta | 3 | 3 | lebar | label·teks; kapasitas_maks_mm·desimal·mm; u95_mm·desimal·mm |
| `tumpukan_depth_mm/0` | daftar | 2 | 1 | lebar | nilai·daftar_angka |
| `tumpukan_depth_mm/1` | daftar | 2 | 1 | lebar | nilai·daftar_angka |
| `tumpukan_depth_mm/2` | daftar | 2 | 1 | lebar | nilai·daftar_angka |
| `tumpukan_depth_mm/3` | daftar | 1 | 1 | lebar | nilai·daftar_angka |
| `tumpukan_depth_mm/4` | daftar | 1 | 1 | lebar | nilai·daftar_angka |
| `konstanta` | peta | 18 | 18 | lebar | suhu_acuan_c·desimal·°C; alpha_per_c·desimal·/°C; u_alpha_caliper_checker_per_c·desimal·/°C; u_alpha_balok_ukur_per_c·desimal·/°C; pembagi_muai·desimal; drift_a_um·desimal·µm; drift_b_um_per_mm·desimal·µm/mm; drift_pembagi_umur·desimal; wringing_um_per_keping·desimal; geometri_mm·desimal·mm; efek_mekanik_mm·desimal·mm; meja_granit_mm·desimal·mm; … +6 |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!T5`, `PERHITUNGAN U95%!K7`, `PERHITUNGAN!AD121`, `PERHITUNGAN!AH37`, `PERHITUNGAN!F72`, `PERHITUNGAN!H126:H128`, `PERHITUNGAN!V37`, `PERHITUNGAN!X37`, `PERHITUNGAN!Y37`, `PERHITUNGAN!Z24`, `PERHITUNGAN!Z28`, `Standar_GB!Q10:S132`, `Std_CaliperCek!J19`, `U95!K10`, `U95!K11`, `U95!K12`, `U95!K13`, `U95!K53`, `U95!N5`, `U95!N9`, `U95!Q6`, `U95!Q7:Q14`, `ci muai DIKETIK 50 (U95!V48`, `dari sesi master (INPUT DATA!C111:C123`, `punya angkanya (DATABASE!T5`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-jangka-sorong.json:_sumber**: Master Olah Data Caliper 2026 (std caliper checker+gb).xlsm (sheet Std_CaliperCek, Standar_GB, DATABASE, Perhitungan koef. Sensitivitas), ber-password. Caliper Checker dibaca dari tabel-standar-height-gauge.json — keping fisik yang sama, sudah diadu skrip ini.
- **tabel-standar-jangka-sorong.json:_digenerate_oleh**: docs/skrip/gen-tabel-standar-jangka-sorong.py

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
