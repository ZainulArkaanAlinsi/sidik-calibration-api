# Pita CMC lampiran akreditasi LK-285-IDN (semua alat) — paket `cmc_lampiran`

| | |
|---|---|
| Kelompok | Lintas alat |
| Sumber data acuan hari ini | seed-db — `database/data/kemampuan-kalibrasi.json` |
| Status di Studio | Lintas alat — versikan `calibration_capabilities` (prompt P7, mode seed) |
| Profil | `(semua profil)` |
| Kalkulator | — |
| Kelas tabel | `TabelStandarHydrometer`, `TabelStandarTimbangan` |
| Formulir resmi | — (tidak ada di worksheet_alat_calibration/) |
| Folder master | — |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | — |
| Serah-terima frontend | — |

**Catatan:** Diseed ke `calibration_capabilities` (`php artisan kemampuan:pastikan`), sudah ada resource Filament. Studio menjadikannya ber-versi; jangan membuat tabel CMC kedua.

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarHydrometer` ← `CalibrationController`, `HydrometerCalculator`, `HydrometerMentah`, `HydrometerProfile`, `VolumetricGlasswareCalculator`
- `TabelStandarTimbangan` ← `TimbanganCalculator`, `TimbanganProfile`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarHydrometer.php`
- `app/Services/Calibration/TabelStandarTimbangan.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Unit/HydrometerMasterTest.php`
- `tests/Unit/TimbanganCmcCocokAkreditasiTest.php`
- `tests/Unit/TimbanganMasterTest.php`
- `tests/Unit/VolumetricGlasswareMasterTest.php`

## Peta Excel → Studio

Tidak ada CSV master di `alat-alat-Pt-Sidik/` untuk paket ini.


## Lembar data acuan (dari JSON — 100% sel tercakup)

- `kemampuan-kalibrasi.json`: 1117/1117 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `sumber_dokumen` | peta | 9 | 9 | lebar | nama_lpk·teks; no_akreditasi·teks; standar_akreditasi·teks; alamat·teks; telp·teks; email·teks; masa_berlaku·teks; catatan_ketidakpastian·teks; catatan_hak_cipta·teks |
| `kelompok_pengukuran` | tabel | 10 | 10 | lebar | kelompok·teks |
| `kelompok_pengukuran/*/alat` | tabel_anak | 48 | 192 | lebar | no·desimal; nama_alat·teks; metode·teks; keterangan·kosong |
| `kelompok_pengukuran/*/alat/*/rentang` | tabel_anak | 151 | 906 | lebar | parameter·teks; min·desimal; max·desimal; satuan·teks; ketidakpastian·desimal; satuan_u·teks |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!F31`, `DATABASE!R5:T21`, `DATABASE!V20:W21`, `DATABASE!V29:W31`, `INPUT DATA!AC17`, `INPUT DATA!E4`, `NILAI U95%!E39`, `NILAI U95%!L79`, `PERHITUNGAN!AD29`, `PERHITUNGAN!AD39`, `PERHITUNGAN!AE39`, `PERHITUNGAN!AF84`, `PERHITUNGAN!AF85`, `PERHITUNGAN!G26`, `PERHITUNGAN!G56`, `PERHITUNGAN!J83`, `PERHITUNGAN!J86`, `PERHITUNGAN!J87`, `PERHITUNGAN!J89`, `PERHITUNGAN!J90`, `PERHITUNGAN!J91`, `PERHITUNGAN!P15`, `SERTIFIKAT!E17`, `STANDAR_AT!N51:P55`

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
