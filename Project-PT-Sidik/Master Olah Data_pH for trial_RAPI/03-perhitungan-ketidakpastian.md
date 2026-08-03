# Perhitungan Ketidakpastian (GUM Budget)

Sumber: `PERHITUNGAN U95%.csv`

Metode: JCGM 100:2008 (GUM). Faktor cakupan k dihitung dari derajat kebebasan efektif
(Welch-Satterthwaite) untuk tingkat kepercayaan 95%.

## Titik pH 4 (Point Ukur 3.99 pH)

| Komponen | Distribusi | Nilai (u) |
|---|---|---|
| Ketidakpastian baku sertifikat kalibrator | normal | 0.01 |
| Ketidakpastian baku daya baca alat | rect. | 0.002886751 |
| Ketidakpastian temperature | rect. | 0.000139080 |
| Ketidakpastian pengaruh perbedaan suhu | rect. | 0.005773503 |
| Ketidakpastian baku pengulangan pembacaan | t-student | 0 |
| **Ketidakpastian baku gabungan (Uc)** | | **0.01190319** |
| Derajat kebebasan efektif | | 277.960 |
| Faktor cakupan k (CL 95%) | | 1.96857 |
| **Ketidakpastian bentangan U = k·Uc** | | **0.02343221 pH** |
| **CMC Laboratory (dibulatkan)** | | **0.023 pH** |

## Titik pH 7 (Point Ukur 7 pH)

| Komponen | Distribusi | Nilai (u) |
|---|---|---|
| Ketidakpastian baku sertifikat kalibrator | normal | 0.01 |
| Ketidakpastian baku daya baca alat | rect. | 0.002886751 |
| Ketidakpastian temperature | rect. | 0.000635796 |
| Ketidakpastian pengaruh perbedaan suhu | rect. | 0.000035103 (ci-adjusted) |
| Ketidakpastian baku pengulangan pembacaan | t-student | 0.002449490 |
| **Ketidakpastian baku gabungan (Uc)** | | **0.01071162** |
| Derajat kebebasan efektif | | 223.132 |
| Faktor cakupan k (CL 95%) | | 1.97066 |
| **Ketidakpastian bentangan U = k·Uc** | | **0.02110895 pH** |
| **CMC Laboratory (dibulatkan)** | | **0.021 pH** |

## Titik pH 10 (Point Ukur 10.01 pH)

| Komponen | Distribusi | Nilai (u) |
|---|---|---|
| Ketidakpastian baku sertifikat kalibrator | normal | 0.015 |
| Ketidakpastian baku daya baca alat | rect. | 0.002886751 |
| Ketidakpastian temperature | rect. | 0.001844170 |
| Ketidakpastian pengaruh perbedaan suhu | rect. | 0.000273953 |
| Ketidakpastian baku pengulangan pembacaan | t-student | 0 |
| **Ketidakpastian baku gabungan (Uc)** | | **0.01538861** |
| Derajat kebebasan efektif | | 221.495 |
| Faktor cakupan k (CL 95%) | | 1.97076 |
| **Ketidakpastian bentangan U = k·Uc** | | **0.03032720 pH** |
| **CMC Laboratory (dibulatkan)** | | **0.031 pH** |

## Sumber ketidakpastian temperature bersama (dipakai di ketiga titik)

| Komponen | Nilai | k | Utemp |
|---|---|---|---|
| U95% Thermometer Certificate | 0.72 °C | 2 | 0.36 °C |
| U95% Sensor Certificate | 0.06 °C | 2 | 0.03 °C |
| UTemperature gabungan | | | **0.36124784 °C** |

Angka CMC (0.023 / 0.021 / 0.031 pH) inilah yang sudah dipakai sebagai kemampuan
kalibrasi resmi lab di `database/seeders/PhMeterCapabilitySeeder.php` — jadi baris
ini tinggal dikonfirmasi cocok, bukan dibuat ulang.

## Ringkasan hasil per titik (dihitung dari data di [02](02-data-hasil-kalibrasi.md))

| Titik | Standar (pH) | Rata-rata (pH) | Error | U95% | Toleransi* | Keputusan |
|---|---|---|---|---|---|---|
| pH 4 | 4.00924457 | 4.0000 | -0.00924457 | ±0.023 | ±0.2 | PASS |
| pH 7 | 6.98890720 | 7.0040 | +0.01509280 | ±0.021 | ±0.2 | PASS |
| pH 10 | 9.97887690 | 10.1100 | +0.13112310 | ±0.031 | ±0.2 | PASS |

\* Toleransi ±0.2 pH tidak tercantum eksplisit di workbook sumber (workbook ini cuma
melaporkan koreksi & U95%, tanpa kriteria PASS/FAIL) — ini asumsi kriteria
penerimaan umum untuk pH meter lab yang dipakai di sistem (lihat catatan di
`PhMeterSeeder`). Kalau PT Sidik punya kriteria penerimaan resmi yang
beda, tinggal ganti `toleransi` di seeder.

Keputusan pakai *guarded acceptance* (ILAC-G8): `|error| + U95% <= toleransi`.
