# Timer & Stopwatch — paket `waktu`

| | |
|---|---|
| Kelompok | Waktu |
| Sumber data acuan hari ini | json — `database/data/tabel-standar-waktu.json` |
| Status di Studio | Gelombang 3 — pindah ke SumberAcuan (prompt P7) |
| Profil | `TimerStopwatchProfile` |
| Kalkulator | `WaktuCalculator` |
| Kelas tabel | `TabelStandarWaktu` |
| Formulir resmi | SIDIK-FM-CAL-0512_Rev.4 - LEMBAR KERJA STOPWATCH & TIMER.pdf |
| Folder master | `Waktu_/Master_Olda_Timer_dan_Stopwatch` |
| Generator | `docs/skrip/gen-tabel-standar-waktu-frekuensi.py` |
| Fixture master | `database/data/sesi-master-waktu-frekuensi.json` |
| Pertanyaan lab | `docs/pertanyaan-lab-waktu-frekuensi.md` |
| Serah-terima frontend | `docs/perintah-frontend-waktu-frekuensi.md` |

**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):

- `TabelStandarWaktu` ← `TabelStandarPutaran`, `TimerStopwatchProfile`, `WaktuCalculator`

## Berkas kode yang terlibat

- `app/Services/Calibration/TabelStandarWaktu.php`
- `app/Services/Calibration/WaktuCalculator.php`
- `app/Services/Calibration/Profiles/TimerStopwatchProfile.php`

## Test yang wajib tetap hijau tanpa diubah

- `tests/Feature/BudgetTerlihatDanGerbangCmcTest.php`
- `tests/Feature/NominalDiLuarSertifikatDitolakTest.php`
- `tests/Unit/WaktuFrekuensiMasterTest.php`

## Peta Excel → Studio

| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |
|---|---|---|---|
| `Master_Olda_Timer_dan_Stopwatch` | DATABASE | hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH | 110 |
| `Master_Olda_Timer_dan_Stopwatch` | Drift Stopwatch | tab data acuan (bisa disunting lewat versi) | 29 |
| `Master_Olda_Timer_dan_Stopwatch` | FORM VALIDASI | sumber nomor versi workbook (F1) | 179 |
| `Master_Olda_Timer_dan_Stopwatch` | Human Reaction | tab data acuan (bisa disunting lewat versi) | 23 |
| `Master_Olda_Timer_dan_Stopwatch` | INPUT DATA | tab Bentuk Lembar (lapis 3) | 300 |
| `Master_Olda_Timer_dan_Stopwatch` | Konsep | bukan data — tidak masuk Studio | 57 |
| `Master_Olda_Timer_dan_Stopwatch` | PERHITUNGAN U95% | tab Rumus — baca saja (lapis 2) | 113 |
| `Master_Olda_Timer_dan_Stopwatch` | PERHITUNGAN | tab Rumus — baca saja (lapis 2) | 274 |
| `Master_Olda_Timer_dan_Stopwatch` | SERTIFIKAT KALIBRATOR | pratinjau sertifikat di layar Simulasi | 26 |
| `Master_Olda_Timer_dan_Stopwatch` | SERTIFIKAT | pratinjau sertifikat di layar Simulasi | 57 |

### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)

Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**

**Master_Olda_Timer_dan_Stopwatch › Drift Stopwatch**

```
Nama Alat: | Stopwatch
Merk       : | CASIO
Type       : | Digital
SN          : | SW-1
Res        : | 0.001 | s
Jumlah hari | 360 | hari
```
**Master_Olda_Timer_dan_Stopwatch › Human Reaction**

```
Perhitungan human reaction dapat digunakan untuk pengukuran metoda perbandingan langsung poin 2,3 dan metode totalize
Pengambilan data human rection disesuaikan dengan metode yang digunakan
Nominal | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10
(detik) | s | s | s | s | s | s | s | s | s | s
Std | 10 | NR | 10.257 | 10.274 | 10.209 | 10.042 | 10.328 | 10.286 | 10.27 | 10.258 | 10.291 | 10.294
UUT | 10.166 | 10.26 | 10.19 | 10.06 | 10.31 | 10.28 | 10.26 | 10.24 | 10.28 | 10.29
```

## Lembar data acuan (dari JSON — 100% sel tercakup)

- `tabel-standar-waktu.json`: 173/173 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `standar` | peta | 5 | 5 | lebar | nama·teks; merk·teks; resolusi·desimal; seri·teks; tanggal_kalibrasi·tanggal |
| `sertifikat` | tabel | 13 | 39 | lebar | nominal_detik·desimal; koreksi_ms·desimal; u95_detik·desimal |
| `drift` | peta | 3 | 3 | lebar | hari_master·desimal; rentang_maks_master_ms·desimal; rentang_maks_lengkap_ms·desimal |
| `drift/snapshot` | tabel | 4 | 12 | lebar | kolom·teks; tanggal·tanggal; lab·teks |
| `drift/titik` | tabel | 13 | 91 | lebar | nominal_detik·desimal; koreksi_ms.F·desimal; koreksi_ms.G·desimal; koreksi_ms.H·desimal; koreksi_ms.I·desimal; rentang_ms·desimal; dihitung_master·boolean |
| `human_reaction` | peta | 3 | 3 | lebar | rata_maks_master·desimal; rata_maks_lengkap·desimal; stdev_maks·desimal |
| `human_reaction/operator` | tabel | 4 | 20 | lebar | inisial·teks; nominal_detik·desimal; beda·daftar_angka; rata_rata·desimal; stdev·desimal |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Catatan yang sudah tertulis di JSON acuan

- **tabel-standar-waktu.json:_sumber**: Master Olda Timer dan Stopwatch.xlsm (sheet SERTIFIKAT KALIBRATOR, Drift Stopwatch, Human Reaction).
- **tabel-standar-waktu.json:_digenerate_oleh**: docs/skrip/gen-tabel-standar-waktu-frekuensi.py

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).
