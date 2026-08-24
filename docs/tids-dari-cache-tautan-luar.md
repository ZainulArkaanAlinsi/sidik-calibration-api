# TIDS: data master yang diselamatkan dari cache tautan luar

**Berkas data:** `database/data/tids-cache-master.json` — 3.452 sel, 5 workbook TIDS.

---

## Dari mana datangnya

Master olah data TIDS **tidak pernah dikirim ke repo ini**. Yang dikirim empat workbook
TITS & Enclosure. Tapi keempatnya pernah dibuka berdampingan dengan master TIDS di komputer
lab, dan Excel menyimpan **snapshot beku** dari setiap berkas yang dirujuk — di
`xl/externalLinks/externalLink*.xml`.

Snapshot itulah yang diambil di sini. **Ini angka lab sendiri, bukan turunan, bukan analogi,
bukan karangan.** Tiap sel bisa ditelusuri balik ke berkas asalnya.

Lima master TIDS yang ke-cache:

| Workbook TIDS | Ke-cache di | Sel |
|---|---|---|
| `1. Master Olah Data_Suhu_TIDS 2022 -20-150.xlsm` | Enclosure Recorder, Enclosure Yokogawa | 1.851 |
| `Master Olah Data_Suhu_TIDS 2022.xlsm` | TITS Measure, TITS Source | 1.461 |
| `Melting Point_TIDS_Limas.xlsx` | Enclosure Recorder, Enclosure Yokogawa | 118 |
| `Master Olah Data_Suhu_TIDS_Constant Yokogawa.xlsm` | Enclosure Yokogawa | 12 |
| `Master Olah Data_Suhu_TIDS - Yokogawa.xlsm` | Enclosure Yokogawa | 10 |

---

## Batasnya — baca ini sebelum memakai

Cache tautan luar **cuma menyimpan sel yang benar-benar ditarik** berkas pemanggilnya, bukan
seluruh workbook. Jadi yang ada di sini tidak lengkap, dan yang tidak ada BUKAN berarti tidak
penting — cuma berarti workbook TITS/Enclosure kebetulan tidak merujuknya.

| Sheet | Status |
|---|---|
| `STANDAR KALIBRATOR` | **1.488 + 968 sel** — tabel koreksi kalibrator, ini yang paling utuh |
| `DATABASE` | **487 + 362 sel** — jenis sensor + CMC, Inlab/Insitu, daftar pelaksana |
| `FC` (Melting Point) | **117 sel** — uji titik leleh |
| `Interpolasi` | 22 sel |
| `Inhomogen Stabilitas DryBlock` | 7 sel — nyaris kosong |
| **`PERHITUNGAN U95%`** | **NOL sel** |
| **`Variasi axial Dryblok A` / `B`** | **NOL sel** |
| **`stdev drywell`** | **NOL sel** |
| `PERHITUNGAN FC`, `SERTIFIKAT`, `INPUT DATA` | NOL sel |

### Artinya buat pekerjaan TIDS

**Boleh dipakai** — tabel koreksi kalibrator, daftar jenis sensor + CMC, daftar pelaksana,
pilihan Inlab/Insitu, dan struktur workbook-nya (18 nama sheet, termasuk nama dua dryblock).

**TIDAK boleh dikarang** — budget ketidakpastian. `PERHITUNGAN U95%` nol sel, dan dua sheet
variasi aksial dryblock juga nol. TITS dan Enclosure dua-duanya dikerjakan dengan
mereproduksi workbook sampai digit terakhir; itu yang bikin angkanya bisa dipertanggungjawabkan
ke auditor. Menyusun budget TIDS dari analogi TITS akan menghasilkan angka yang **terlihat
sah** dan tidak ketahuan salah sampai ada yang mengaudit. Untuk lab terakreditasi itu bukan
"kurang teliti" — itu temuan.

➜ **Yang masih harus diminta ke lab: workbook TIDS aslinya**, khusus sheet `PERHITUNGAN U95%`,
`Variasi axial Dryblok A`, `Variasi axial Dryblok B`, dan `stdev drywell`.

---

## Isi yang sudah terbaca

### Jenis sensor + CMC (`DATABASE!Q5:S11`)

| # | Jenis Sensor | CMC (°C) |
|---|---|---|
| 1 | Type K | 1,5 |
| 2 | Type J | 1,5 |
| 3 | Type T | 1,5 |
| 4 | Type N | 1,5 |
| 5 | Type R | 1,5 |
| 6 | **Type S** | **1,6** |
| 7 | RTD | 1,5 |

> Type S sendirian di 1,6 sementara enam lainnya 1,5. Persis jenis detail yang mustahil ditebak
> dan salahnya diam kalau diseragamkan.

### Kalibrator & standar sensor (`DATABASE!R13:S21`)

- Temperature Calibrator: `Constant/40T`, `Victor/Victor 14+`
- Standar Sensor: `Thermocouple Type N` (TC Limited), `Thermocouple Type K`

> Victor masih ada di master 2022 ini. Di master TITS yang sekarang, Victor **sudah dihapus**
> (FORM VALIDASI rev. 11, 24 Mei 2024: *"Remove std. Victor / Add std kalibrator yokogawa"*).
> Jadi cache ini potret 2022 — jangan dipakai buat memutuskan standar yang berlaku hari ini.

### Lokasi kalibrasi (`DATABASE!B4:B5`)

`Insitu` dan `Inlab` — sama dengan yang dipakai lembar kerja lain.

---

## Kejanggalan yang harus ditanyakan ke lab

**CMC TIDS ada DUA angka yang berbeda, dari dua sumber.**

| Sumber | Angka |
|---|---|
| Master TIDS 2022 (cache ini) | 1,5 °C rata untuk Type K/J/T/N/R/RTD; 1,6 untuk Type S |
| Lampiran akreditasi LK-285-IDN (sudah ter-seed di sistem) | 0,86 / 1,4 / 3,1 °C |

Yang tercetak di sertifikat harus mengikuti yang mana? Ini bukan soal pembulatan — dua-duanya
angka resmi dari sumber berbeda, dan yang menentukan lantai U95 cuma boleh satu.
