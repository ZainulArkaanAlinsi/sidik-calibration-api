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

> ⚠️ **Angka "1.488 + 968" di bawah JANGAN dibaca sebagai penjumlahan.** Lihat
> §"Dua master, dua tabel kalibrator" — dua cache itu tidak boleh digabung.

| Sheet | Status |
|---|---|
| `STANDAR KALIBRATOR` | 1.488 dan 968 sel di DUA master **terpisah** — lihat peringatan di bawah |
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

---

## Dua master, dua tabel kalibrator — JANGAN digabung

Dua master TIDS 2022 diadu sel per sel. Dari 1.202 sel yang ada di dua-duanya,
**155 berbeda.** Setelah ditelusuri, bedanya BUKAN data rusak dan BUKAN salah ketik —
tapi memang dua tabel koreksi kalibrator yang berlainan.

Di sheet `STANDAR KALIBRATOR`, susunannya sama (kolom B = titik suhu, nilainya identik:
−100, −20, 0, 25, 50, 100, …), tapi:

| Blok kolom | Keadaan |
|---|---|
| **M–P** | **IDENTIK** di dua master — satu kalibrator yang sama, tidak berubah |
| **C–J** | **BEDA sistematis** — kalibrator yang lain, nilai koreksinya memang lain |

Contoh baris 0 °C:

| Kolom | Master `TIDS 2022` | Master `TIDS 2022 -20-150` |
|---|---|---|
| C | −0,16 | −0,23 |
| D | 0,09 | 0,12 |
| E | 0,15 | 0,19 |
| G | 0,10 | 0,13 |
| H | 0,20 | 0,35 |
| I | 0,30 | 0,16 |
| **N** | **0,02** | **0,02** |
| **O** | **0,1575** | **0,1575** |
| **P** | **0,0850** | **0,0850** |

Selisih di blok C–J sekitar 0,03–0,15 °C. Terhadap CMC 1,5 °C itu bukan angka yang bikin
sertifikat langsung salah vonis — tapi **tetap angka yang salah tercetak di dokumen
terakreditasi**, dan salahnya diam.

`STANDAR KALIBRATOR` cuma cocok penuh di **19 dari 66 baris**. `DATABASE` cocok penuh di
34 dari 63 baris (bedanya di baris 22–74, wilayah daftar metode/pelaksana/thermohygro yang
susunannya memang berlainan antar-master).

### Aturan pakai

1. **Pilih SATU master, dan catat yang mana.** Jangan menggabungkan per alamat sel — dua
   tabel berbeda di alamat yang sama akan tergabung tanpa ada yang gagal.
2. **Nama kalibrator tiap blok TIDAK ada di cache.** Baris 1–9 (kepala tabel) nol sel —
   yang ditarik workbook pemanggil cuma baris datanya. Jadi mana blok C–J milik Constant,
   mana milik Victor, **belum bisa dipastikan dari sini.** Harus dikonfirmasi ke lab atau
   dari workbook aslinya.
3. **Blok M–P aman dipakai** — identik di dua master, jadi tidak ada yang perlu dipilih.
4. Master A menulis **sel kosong** di tempat master B menulis **`0`**. Kosong dan nol itu
   dua hal berbeda: kosong = tidak ada datanya, nol = koreksinya memang nol. Jangan
   diperlakukan sama waktu membaca JSON-nya.

### Yang TIDAK bermasalah

- Blok jenis sensor + CMC (`DATABASE!Q3:S11`) **identik di dua master**, termasuk Type S = 1,6.
  Temuan itu berdiri kokoh.
- Tidak ada satu pun baris yang berulang di dalam `STANDAR KALIBRATOR` (diperiksa dengan
  membandingkan tanda tangan tiap baris) — jadi tidak ada duplikat di dalam satu tabel.
- Lampiran akreditasi `kemampuan-kalibrasi.json` juga bersih: 48 alat, nol baris identik,
  nol nama yang muncul di dua kelompok, nol nama yang cuma beda huruf besar/kecil.
