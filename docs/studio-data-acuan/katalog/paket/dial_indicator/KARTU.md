# Dial Indicator — paket `dial_indicator`

| | |
|---|---|
| Kelompok | Panjang |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-dial-indicator.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `DialIndicatorProfile` |
| Kalkulator | `DialIndicatorCalculator` |
| Kelas tabel | `TabelStandarDialIndicator` |
| Formulir resmi | SIDIK-FM-CAL-0526_Rev.3 - LEMBAR KERJA DIAL INDICATOR.pdf |
| Folder master | `Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Dial_Indicator` |
| Generator | `docs/skrip/gen-tabel-standar-dial-indicator.py` |
| Fixture master | `database/data/sesi-master-dial-indicator.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-dial-indicator.md` |
| Serah-terima frontend | `docs/perintah-frontend-dial-indicator.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarDialIndicator` ← `CalibrationProfileRegistry`, `DialIndicatorCalculator`, `DialIndicatorProfile`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarDialIndicator.php`
- `app/Services/Calibration/DialIndicatorCalculator.php`
- `app/Services/Calibration/Profiles/DialIndicatorProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/DialIndicatorSesiTest.php`
- `tests/Unit/DialIndicatorMasterTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olah_Data_Dial_Indicator` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 109 |
| `Master_Olah_Data_Dial_Indicator` | FORM VALIDASI | sumber nomor versi workbook (F1) | 182 |
| `Master_Olah_Data_Dial_Indicator` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 296 |
| `Master_Olah_Data_Dial_Indicator` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 21 |
| `Master_Olah_Data_Dial_Indicator` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 286 |
| `Master_Olah_Data_Dial_Indicator` | Perhitungan koef. Sensitivitas | tab Rumus — baca saja (lapis 2) | 7 |
| `Master_Olah_Data_Dial_Indicator` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 54 |
| `Master_Olah_Data_Dial_Indicator` | Standar_GB | tab data acuan (bisa disunting lewat versi) | 133 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olah_Data_Dial_Indicator › Standar_GB**

```
STANDAR GAUGE BLOCK
Name : | Gauge block standard | Tanggal Kalibrasi | 2024-01-24T00:00:00
No Seri | 160006 | LK-410-IDN
Merk | Metrology
Tipe | GB-9122-0
Rentang Ukur | 0.5-100 mm
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-dial-indicator.json`: 35/35 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `standar` | peta | 6 | 6 | lebar | nama·teks; merk_tipe·teks; seri·desimal_teks; traceability·teks; tanggal_kalibrasi·tanggal; interval_tahun·desimal |
| `cmc` | tabel | 4 | 16 | lebar | kode·teks; label·teks; kapasitas_maks_mm·desimal·mm; u95_mm·desimal·mm |
| `konstanta` | peta | 13 | 13 | lebar | suhu_acuan_c·desimal·°C; delta_alpha_per_c·desimal·/°C; u_alpha_pengali·desimal; pembagi_muai·desimal; alpha_per_c·desimal·/°C; pengulangan_pembagi_n·desimal·N; drift_a_um·desimal·µm; drift_b_um_per_mm·desimal·µm/mm; drift_pembagi_umur·desimal; wringing_um_per_keping·desimal; tegak_lurus_mm·desimal·mm; meja_granit_mm·desimal·mm; … +1 |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!X11`, `INPUT DATA!E15`, `PERHITUNGAN U95%!AA20`, `PERHITUNGAN U95%!K10`, `PERHITUNGAN U95%!K11`, `PERHITUNGAN U95%!K12`, `PERHITUNGAN U95%!K13`, `PERHITUNGAN U95%!N5`, `PERHITUNGAN U95%!N9`, `PERHITUNGAN U95%!Q6:Q14`, `PERHITUNGAN!G14`, `PERHITUNGAN!G8`, `PERHITUNGAN!H23`, `PERHITUNGAN!Q31`, `PERHITUNGAN!S31`, `PERHITUNGAN!V22`, `PERHITUNGAN!W22`, `Standar_GB!Q10:R132`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-dial-indicator.json:_sumber**: Master Olah Data_Dial Indicator.xlsm (sheet DATABASE, Standar_GB, PERHITUNGAN U95%), ber-password. Balok ukur dibaca dari tabel-standar-micrometer.json — set fisik yang sama, sudah diadu skrip ini.
- **tabel-standar-dial-indicator.json:_digenerate_oleh**: docs/skrip/gen-tabel-standar-dial-indicator.py

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
