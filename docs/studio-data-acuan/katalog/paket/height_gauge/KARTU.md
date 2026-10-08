# Height Gauge 600 mm — paket `height_gauge`

| | |
|---|---|
| Kelompok | Panjang |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-height-gauge.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `HeightGaugeProfile` |
| Kalkulator | `HeightGaugeCalculator` |
| Kelas tabel | `TabelStandarHeightGauge` |
| Formulir resmi | — (tidak ada di worksheet_alat_calibration/) |
| Folder master | `Panjang_CSV/Master_olda_Height_Gauge_600_mm_2026` |
| Generator | `docs/skrip/gen-tabel-standar-height-gauge.py` |
| Fixture master | `database/data/sesi-master-height-gauge.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-height-gauge.md` |
| Serah-terima frontend | `docs/perintah-frontend-height-gauge.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarHeightGauge` ← `CalibrationController`, `HeightGaugeCalculator`, `HeightGaugeProfile`, `TabelStandarJangkaSorong`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarHeightGauge.php`
- `app/Services/Calibration/HeightGaugeCalculator.php`
- `app/Services/Calibration/Profiles/HeightGaugeProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/HeightGaugeSertifikatTest.php`
- `tests/Feature/HeightGaugeSesiTest.php`
- `tests/Unit/HeightGaugeMasterTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_olda_Height_Gauge_600_mm_2026` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 111 |
| `Master_olda_Height_Gauge_600_mm_2026` | FORM VALIDASI | sumber nomor versi workbook (F1) | 140 |
| `Master_olda_Height_Gauge_600_mm_2026` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 300 |
| `Master_olda_Height_Gauge_600_mm_2026` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 21 |
| `Master_olda_Height_Gauge_600_mm_2026` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 288 |
| `Master_olda_Height_Gauge_600_mm_2026` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 59 |
| `Master_olda_Height_Gauge_600_mm_2026` | Std_CaliperCek | tab data acuan (bisa disunting lewat versi) | 32 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_olda_Height_Gauge_600_mm_2026 › Std_CaliperCek**

```
STANDAR GAUGE BLOCK
Nama Alat | : | Caliper Checker | Tanggal Kalibrasi | 2026-01-09T00:00:00
No Seri | : | 800035 | Tertelusur | LK-404-IDN
Merk | : | Metrology
Tipe | : | CMG-9060C
Rentang Ukur | : | 0-600 mm
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-height-gauge.json`: 82/82 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `standar` | peta | 9 | 9 | lebar | nama·teks; merk·teks; tipe·teks; merk_tipe·teks; seri·desimal_teks; tanggal_kalibrasi·tanggal; tertelusur·teks; rentang·teks; u95_um·desimal·µm |
| `outside` | tabel | 10 | 30 | lebar | nominal_mm·desimal·mm; nilai_terkoreksi_mm·desimal·mm; koreksi_um·desimal·µm |
| `inside` | tabel | 10 | 30 | lebar | nominal_mm·desimal·mm; nilai_terkoreksi_mm·desimal·mm; koreksi_um·desimal·µm |
| `konstanta` | peta | 13 | 13 | lebar | suhu_acuan_c·desimal·°C; alpha_per_c·desimal·/°C; delta_alpha_per_c·desimal·/°C; drift_a_mm·desimal·mm; drift_b_mm_per_mm·desimal·mm; drift_pembagi_umur·desimal; geometri_mm·desimal·mm; meja_granit_mm·desimal·mm; batas_paralelisme_mm·desimal·mm; vi_type_b_normal·desimal; vi_type_b_rect·desimal; vi_repeatability·desimal; … +1 |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Rujukan sel master yang ditemukan di generator/kode

Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.

`DATABASE!S5:T5`, `DATABASE!X11`, `PERHITUNGAN!C30:M30`, `PERHITUNGAN!C65`, `PERHITUNGAN!M35`, `PERHITUNGAN!M44`, `SERTIFIKAT!L27`, `Std_CaliperCek!C23:J32`

## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-height-gauge.json:_sumber**: Master_olda_Height_Gauge_600_mm_2026.xlsm (sheet Std_CaliperCek & DATABASE), ber-password. Tabel Inside ikut disalin tapi TIDAK tersambung ke mesin hitung — seluruh VLOOKUP jalur Height Gauge memakai Nom_Outside.
- **tabel-standar-height-gauge.json:_digenerate_oleh**: docs/skrip/gen-tabel-standar-height-gauge.py

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
