# Referensi Umum Lab (bukan spesifik sertifikat 012-CAL-524)

Sumber: `DATABASE.csv`, `FORM VALIDASI.csv`

Ini data master lab yang dipakai lintas-sertifikat — dicatat di sini sebagai
referensi, TIDAK ikut di-seed oleh `PhMeterSeeder` (di luar ruang lingkup
satu record kalibrasi), tapi berguna kalau nanti mau diperluas.

## Pelaksana Kalibrasi (inisial teknisi)

NR, DT, JO, CA, DR, NS, AJ

## Dihitung Oleh

AM, NR, DR

## Penandatangan Sertifikat

| Inisial/Nama | Jabatan |
|---|---|
| IPD Wiska Susetio | Laboratory Director |
| Alex Misramto | Technical Manager |

## Validasi

| Inisial | Jabatan |
|---|---|
| AM | Technical Manager |
| NR | Supervisor |
| DR | Data Analyst |
| NS | Data Analyst |

## Thermohygro TH-1 s/d TH-7 (alat pemantau suhu/kelembaban ruangan lab)

Semua kalibrasi 2025-09-11, due 2027-09-12, traceable ke LK-285-IDN, kecuali TH-5.

| No | Kode | Lokasi | Tanggal Kalibrasi | Due Date |
|---|---|---|---|---|
| 1 | TH-1 | Inlab (Lab. Dimensi) | 2025-09-11 | 2027-09-12 |
| 2 | TH-2 | Insitu (Outsite) | 2025-09-11 | 2027-09-12 |
| 3 | TH-3 | Inlab (Lab. Pressure) | 2025-09-11 | 2027-09-12 |
| 4 | TH-4 | Inlab (Lab. Suhu) | 2025-09-11 | 2027-09-12 |
| 5 | TH-5 | Inlab (Flowmeter Instalation) | 2024-06-17 | 2024-06-18 |
| 6 | TH-6 | Insitu (Outsite) | 2025-09-11 | 2027-09-12 |
| 7 | TH-7 | Inlab (Lab. Gaya) | 2025-09-11 | 2027-09-12 |

TH-3 adalah unit yang dipakai untuk sertifikat 012-CAL-524 (lihat
[01-identitas-alat-dan-customer.md](01-identitas-alat-dan-customer.md)).
Uncertainty standar: ±1.7 °C (temperature), ±4.8–4.9 %RH (kelembaban).

## Daftar 34 Jenis Pengukuran & Nomor Metode (IK)

Daftar lengkap ini sudah tersimpan sebagai data master di
`database/data/kemampuan-kalibrasi.json` (dipakai `CalibrationCapabilitySeeder`) —
nomor IK per jenis alat konsisten dengan yang tercantum di sini, contoh yang relevan:

| No | Jenis Pengukuran | Metode Kalibrasi (IK) |
|---|---|---|
| 6 | pH Meter | SIDIK-IK-CAL-0506_Rev.6 |
| 7 | Conductivity Meter | SIDIK-IK-CAL-0507_Rev.6 |
| 23 | Turbidity Meter | SIDIK-IK-CAL-0523_Rev.1 |
| 24 | Chlorine Meter | SIDIK-IK-CAL-0524_Rev.1 |
| 30 | DO Meter | SIDIK-IK-CAL-0530_Rev.2 |

(daftar penuh 34 baris ada di `DATABASE.csv` baris 68-103, dan sudah tercakup di
`kemampuan-kalibrasi.json`).

## Reminder Recalibration (aturan interval default)

| Jenis Alat | Interval | Hari |
|---|---|---|
| Analog | 2 tahun | 730 |
| Digital | 1 tahun | 365 |

pH Meter Mettler Toledo Five Easy (S/N B628755900) tergolong **Digital** → interval
1 tahun.
