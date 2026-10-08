# Piston Volume (fixed & graduated, buret digital, dispensett, piston pipette) — paket `piston_volume`

| | |
|---|---|
| Kelompok | Volume |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-piston-volume.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `PistonVolumeProfile`, `BuretDigitalProfile`, `DispensettProfile`, `PistonPipetteProfile` |
| Kalkulator | `PistonVolumeCalculator` |
| Kelas tabel | `TabelStandarPistonVolume` |
| Formulir resmi | SIDIK-FM-CAL-0528_Rev.3 - LEMBAR KERJA ONE MARK PISTON VOLUME.pdf, SIDIK-FM-CAL-0529_Rev.3 - LEMBAR KERJA GRADUATED PISTON VOLUME.pdf |
| Folder master | — |
| Generator | `docs/skrip/gen-tabel-standar-piston-volume.py` |
| Fixture master | `database/data/sesi-master-piston-volume.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-piston-volume.md` |
| Serah-terima frontend | — |

**Catatan:** Master piston (2 workbook) TIDAK ada di folder alat-alat; sha256 di manifest-workbook-tekanan-piston.json.

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarPistonVolume` ← `BuretDigitalProfile`, `DispensettProfile`, `HitungMentahKalibrasi`, `PistonPipetteProfile`, `PistonVolumeCalculator`, `PistonVolumeProfile`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarPistonVolume.php`
- `app/Services/Calibration/PistonVolumeCalculator.php`
- `app/Services/Calibration/Profiles/PistonVolumeProfile.php`
- `app/Services/Calibration/Profiles/BuretDigitalProfile.php`
- `app/Services/Calibration/Profiles/DispensettProfile.php`
- `app/Services/Calibration/Profiles/PistonPipetteProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/PenahananKeputusanTmTest.php`
- `tests/Unit/LogMetodeTekananPistonTest.php`
- `tests/Unit/PistonVolumeMasterTest.php`
- `tests/Unit/RoutingProfilSepakatTest.php`

## Peta Excel → Studio

Tidak ada CSV master di `alat-alat-Pt-Sidik/` untuk paket ini.


## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-piston-volume.json`: 199/199 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `konstanta` | peta | 3 | 3 | lebar | densitas_anak_timbangan·desimal; koefisien_muai·desimal; u_operator·desimal |
| `konstanta/tanaka` | peta | 5 | 5 | lebar | a1·desimal; a2·desimal; a3·desimal; a4·desimal; a5·desimal |
| `timbangan` | tabel | 3 | 27 | lebar | nomor·desimal; nama·teks; merk_tipe·teks; nomor_seri·desimal_teks; tertelusur·teks; jatuh_tempo·tanggal; u95·desimal; resolusi·desimal; stdev·desimal |
| `termometer` | peta | 6 | 6 | lebar | nama·teks; merk_tipe·teks; nomor_seri·teks; tertelusur·teks; jatuh_tempo·tanggal; u95·desimal |
| `sensor` | peta | 3 | 3 | lebar | nama·teks; jatuh_tempo·tanggal; u95·desimal |
| `koreksi_meter_suhu` | tabel | 12 | 24 | lebar | indeks·desimal; koreksi·desimal |
| `koreksi_sensor_suhu` | tabel | 7 | 14 | lebar | indeks·desimal; koreksi·desimal |
| `cmc/buret_digital` | tabel | 2 | 4 | lebar | maks_ml·desimal·mL; nilai_ml·desimal·mL |
| `cmc/piston_pipette` | tabel | 3 | 6 | lebar | maks_ml·desimal·mL; nilai_ml·desimal·mL |
| `cmc/dispensett` | tabel | 3 | 6 | lebar | maks_ml·desimal·mL; nilai_ml·desimal·mL |
| `mpe/piston_pipette` | tabel | 13 | 26 | lebar | nominal_ml·desimal·mL; mpe_ul·desimal |
| `mpe/buret_digital` | tabel | 8 | 24 | lebar | nominal_ml·desimal·mL; hand_driven_ul·desimal; motor_driven_ul·desimal |
| `mpe/dispensett` | tabel | 17 | 51 | lebar | nominal_ml·desimal·mL; single_stroke_ul·kosong; multi_stroke_ul·desimal |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`PERHITUNGAN U95%!J48`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-piston-volume.json:_sumber**: {"dibuat_oleh": "docs/skrip/gen-tabel-standar-piston-volume.py", "tanggal": "2026-09-28", "workbook": {"fixed": "Master Olah Data_Fixed Piston Volume.xlsm", "graduated": "Master Olah Data_Graduated Piston Volume.xlsm"}, "workbook_sha256": {"fixed": "228991d120343995d2a6c70a79739230bf1a7a203845a30970ae864f86e90491", "graduated": "c09a6efdb172a730eb47108812a463c8dbd19f65ed40b092e5de22035238b036"}, "

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
