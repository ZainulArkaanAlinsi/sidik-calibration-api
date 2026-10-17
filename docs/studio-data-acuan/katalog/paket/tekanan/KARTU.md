# Tekanan — pressure, vacuum, differential — paket `tekanan`

| | |
|---|---|
| Kelompok | Tekanan |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-tekanan.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `TekananProfile`, `PressureGaugeProfile`, `VacuumGaugeProfile`, `DifferentialPressureProfile` |
| Kalkulator | `TekananCalculator` |
| Kelas tabel | `TabelStandarTekanan` |
| Formulir resmi | SIDIK-FM-CAL-0507_Rev.5 - LEMBAR KERJA PRESSURE & VACUUM.pdf |
| Folder master | — |
| Generator | `docs/skrip/gen-tabel-standar-tekanan.py` |
| Fixture master | `database/data/sesi-master-tekanan.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-tekanan.md` |
| Serah-terima frontend | — |

**Catatan:** Master tekanan (4 workbook) TIDAK ada di folder alat-alat (CSV-nya di-gitignore karena memuat data pelanggan). Sha256 workbook di database/data/manifest-workbook-tekanan-piston.json; riwayat metode di log-metode-tekanan-piston.json.

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarTekanan` ← `CalibrationRequest`, `DifferentialPressureProfile`, `HitungMentahKalibrasi`, `PressureGaugeProfile`, `TekananCalculator`, `TekananProfile`, `VacuumGaugeProfile`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarTekanan.php`
- `app/Services/Calibration/TekananCalculator.php`
- `app/Services/Calibration/Profiles/TekananProfile.php`
- `app/Services/Calibration/Profiles/PressureGaugeProfile.php`
- `app/Services/Calibration/Profiles/VacuumGaugeProfile.php`
- `app/Services/Calibration/Profiles/DifferentialPressureProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/KoreksiTekananMustahilTest.php`
- `tests/Feature/PenahananKeputusanTmTest.php`
- `tests/Feature/SemuaProfilLembarKerjaTest.php`
- `tests/Feature/TekananCmcTest.php`
- `tests/Feature/TekananIkutPulangDiDetailSesiTest.php`
- `tests/Feature/TekananSesiTest.php`
- `tests/Feature/VersiRumusTekananPistonTest.php`
- `tests/Unit/LogMetodeTekananPistonTest.php`
- `tests/Unit/RoutingProfilSepakatTest.php`
- `tests/Unit/TekananMasterTest.php`

## Peta Excel → Studio

Tidak ada CSV master di `alat-alat-Pt-Sidik/` untuk paket ini.


## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-tekanan.json`: 463/463 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `gravitasi_lokal` | nilai_tunggal | 1 | 1 | lebar | nilai·desimal |
| `gravitasi_standar` | nilai_tunggal | 1 | 1 | lebar | nilai·desimal |
| `beda_level` | peta | 4 | 4 | lebar | u·desimal; pembagi·desimal; vi·desimal; sumber·teks |
| `pembagi_resolusi` | peta | 4 | 4 | lebar | 1/2·desimal; 1/5·desimal; 1/10·desimal; digital·desimal |
| `varian/druck07g` | peta | 1 | 1 | lebar | satuan_kerja·teks |
| `varian/druck07g/konversi` | tabel | 12 | 24 | lebar | satuan·teks; faktor·desimal |
| `varian/druck07g/titik_standar` | tabel | 19 | 83 | lebar | set_point·desimal; koreksi_up·desimal; koreksi_down·desimal; u95·desimal; u95_dari_sel_gabungan·teks |
| `varian/druck07g/standar` | peta | 8 | 8 | lebar | nama·teks; merk_tipe·teks; nomor_seri·desimal_teks; tertelusur·teks; tanggal_kalibrasi·tanggal; interval_tahun·desimal; jatuh_tempo·tanggal; resolusi·desimal |
| `varian/druck07g/media` | tabel | 3 | 9 | lebar | nomor·desimal; media·teks; massa_jenis·desimal |
| `varian/druck07g/drift` | peta | 5 | 5 | lebar | nilai·desimal; sel_master·teks; rumus_master·teks; metode·teks; catatan·teks |
| `varian/druck07g/cmc_master` | tabel_berkunci | 2 | 4 | lebar | nilai·desimal; rumus_master·teks |
| `varian/druck13g` | peta | 1 | 1 | lebar | satuan_kerja·teks |
| `varian/druck13g/konversi` | tabel | 12 | 24 | lebar | satuan·teks; faktor·desimal |
| `varian/druck13g/titik_standar` | tabel | 11 | 44 | lebar | set_point·desimal; koreksi_up·desimal; koreksi_down·desimal; u95·desimal |
| `varian/druck13g/standar` | peta | 8 | 8 | lebar | nama·teks; merk_tipe·teks; nomor_seri·desimal_teks; tertelusur·teks; tanggal_kalibrasi·tanggal; interval_tahun·desimal; jatuh_tempo·tanggal; resolusi·desimal |
| `varian/druck13g/media` | tabel | 3 | 9 | lebar | nomor·desimal; media·teks; massa_jenis·desimal |
| `varian/druck13g/drift` | peta | 5 | 5 | lebar | nilai·desimal; sel_master·teks; rumus_master·teks; metode·teks; catatan·teks |
| `varian/druck13g/cmc_master` | tabel_berkunci | 2 | 4 | lebar | nilai·desimal; rumus_master·teks |
| `varian/spmk` | peta | 1 | 1 | lebar | satuan_kerja·teks |
| `varian/spmk/konversi` | tabel | 7 | 14 | lebar | satuan·teks; faktor·desimal |
| `varian/spmk/titik_standar` | tabel | 13 | 52 | lebar | set_point·desimal; koreksi_up·desimal; koreksi_down·desimal; u95·desimal |
| `varian/spmk/standar` | peta | 8 | 8 | lebar | nama·teks; merk_tipe·teks; nomor_seri·desimal_teks; tertelusur·teks; tanggal_kalibrasi·tanggal; interval_tahun·desimal; jatuh_tempo·tanggal; resolusi·desimal |
| `varian/spmk/media` | tabel | 3 | 9 | lebar | nomor·desimal; media·teks; massa_jenis·desimal |
| `varian/spmk/drift` | peta | 5 | 5 | lebar | nilai·desimal; sel_master·teks; rumus_master·teks; metode·teks; catatan·teks |
| `varian/spmk/cmc_master` | tabel_berkunci | 1 | 2 | lebar | nilai·desimal; rumus_master·desimal |
| `varian/differential` | peta | 1 | 1 | lebar | satuan_kerja·teks |
| `varian/differential/konversi` | tabel | 10 | 20 | lebar | satuan·teks; faktor·desimal |
| `varian/differential/titik_standar` | tabel | 22 | 88 | lebar | set_point·desimal; koreksi_up·desimal; koreksi_down·desimal; u95·desimal |
| `varian/differential/standar` | peta | 8 | 8 | lebar | nama·teks; merk_tipe·teks; nomor_seri·teks; tertelusur·teks; tanggal_kalibrasi·tanggal; interval_tahun·desimal; jatuh_tempo·tanggal; resolusi·desimal |
| `varian/differential/media` | tabel | 3 | 9 | lebar | nomor·desimal; media·teks; massa_jenis·desimal |
| `varian/differential/drift` | peta | 5 | 5 | lebar | nilai·desimal; sel_master·teks; rumus_master·teks; metode·teks; catatan·teks |
| `varian/differential/cmc_master` | tabel_berkunci | 1 | 2 | lebar | nilai·desimal; rumus_master·desimal |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!S5`, `STANDAR_ADDITEL!J6`, `STANDAR_DRUCK!K4`, `STANDAR_DRUCK!K5`, `STANDAR_SPMK!K5`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-tekanan.json:_sumber**: {"dibuat_oleh": "docs/skrip/gen-tabel-standar-tekanan.py", "tanggal": "2026-09-28", "workbook": {"druck07g": "Master Olda Pressure DRUCK07G -0.8~2bar new.xlsm", "druck13g": "Master Olda Pressure DRUCK13G 0~20bar.xlsm", "spmk": "Master Olda Pressure SPMK.xlsm", "differential": "Master Olda Pressure Differential.xlsm"}, "workbook_sha256": {"druck07g": "a7dde40cdfe61bc7df8aee7c4fea2bd3f80dc7999021c3c

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
