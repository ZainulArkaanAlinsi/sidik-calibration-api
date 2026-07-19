# Data Hasil Kalibrasi (Pembacaan Mentah)

Sumber: `PERHITUNGAN.csv` (sheet "DATA HASIL KALIBRASI")

Ada dua putaran pengukuran: **sebelum adjustment** (kondisi awal alat, sebelum
disetel teknisi) dan **sesudah adjustment** (kondisi final, ini yang dipakai
untuk sertifikat). 5 kali pengulangan per titik, 3 titik ukur (buffer pH 4, 7, 10).

## Sebelum Adjustment

Nilai standar (buffer) yang dipakai: pH 4 = 4.00922520, pH 7 = 6.98850320, pH 10 = 9.97779560

| Repeat | pH 4 (pH / °C) | pH 7 (pH / °C) | pH 10 (pH / °C) |
|---|---|---|---|
| 1 | 4.04 / 22.2 | 7.02 / 22.3 | 9.61 / 22.2 |
| 2 | 4.04 / 22.2 | 7.04 / 22.3 | 9.94 / 22.2 |
| 3 | 4.04 / 22.2 | 7.05 / 22.3 | 9.66 / 22.2 |
| 4 | 5.00 / 22.2 | 7.02 / 22.3 | 9.61 / 22.2 |
| 5 | 4.04 / 22.2 | 7.02 / 22.3 | 9.61 / 22.2 |
| **Rata-rata** | 4.2320 | 7.0300 | 9.6860 |
| **Std Dev** | 0.42933 | 0.01414 | 0.14363 |

Titik ke-4 (repeat 4, pH 4 = 5.00) kelihatan outlier — kemungkinan salah baca/ketik
di data asli. Dibiarkan apa adanya karena memang begitu tercatat di workbook sumber.

## Sesudah Adjustment (dipakai untuk sertifikat)

Nilai standar (buffer) yang dipakai: pH 4 = 4.00924457, pH 7 = 6.98890720, pH 10 = 9.97887690

| Repeat | pH 4 (pH / °C) | pH 7 (pH / °C) | pH 10 (pH / °C) |
|---|---|---|---|
| 1 | 4.00 / 22.2 | 7.01 / 22.2 | 10.11 / 22.1 |
| 2 | 4.00 / 22.2 | 7.01 / 22.2 | 10.11 / 22.1 |
| 3 | 4.00 / 22.1 | 7.00 / 22.2 | 10.11 / 22.1 |
| 4 | 4.00 / 22.2 | 7.00 / 22.2 | 10.11 / 22.1 |
| 5 | 4.00 / 22.2 | 7.00 / 22.2 | 10.11 / 22.1 |
| **Rata-rata** | 4.0000 | 7.0040 | 10.1100 |
| **Std Dev** | 0 | 0.00548 | 0 |
| **Koreksi** (rata-rata − standar) | -0.00924457 | 0.01509280 | 0.13112310 |

Titik pH 10 punya deviasi paling besar (+0.131 pH dari nilai standar) — ini titik
yang paling ketat marginnya terhadap toleransi. Lihat
[03-perhitungan-ketidakpastian.md](03-perhitungan-ketidakpastian.md) untuk keputusan
PASS/FAIL-nya.
