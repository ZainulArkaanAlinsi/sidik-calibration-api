# Koefisien Sensitivitas Suhu Buffer pH

Sumber: `Nilai koefisien Sensitifitas.csv`

Tabel pengaruh suhu terhadap nilai pH buffer (dipakai untuk koreksi nilai standar
saat suhu larutan tidak persis 25 °C — sesuai sertifikat buffer Merck/Supelco).

| Temp (°C) | ΔpH 10.01 Buffer | ΔpH 7.00 Buffer | ΔpH 4.01 Buffer | pH 4 aktual | pH 7 aktual | pH 10.01 aktual |
|---|---|---|---|---|---|---|
| 0 | 0.26 | 0.13 | 0.05 | 4.05 | 7.13 | 10.27 |
| 5 | 0.17 | 0.07 | 0.04 | 4.04 | 7.07 | 10.18 |
| 10 | 0.11 | 0.05 | 0.02 | 4.02 | 7.05 | 10.12 |
| 15 | 0.05 | 0.02 | 0.01 | 4.01 | 7.02 | 10.06 |
| 20 | 0 | 0 | 0 | 4.00 | 7.00 | 10.01 |
| 25 | -0.06 | -0.02 | 0.01 | 4.01 | 6.98 | 9.95 |
| 30 | -0.11 | -0.02 | 0.01 | 4.01 | 6.98 | 9.90 |
| 35 | -0.16 | -0.04 | 0.01 | 4.01 | 6.96 | 9.85 |
| 40 | -0.18 | -0.05 | 0.01 | 4.01 | 6.95 | 9.83 |
| 50 | -0.26 | -0.05 | 0 | 4.00 | 6.95 | 9.75 |

Kadaluarsa sertifikat buffer (jadi batas berlaku tabel ini per larutan):

| Buffer | Kadaluarsa |
|---|---|
| pH 10.01 | 2027-07-31 |
| pH 7.00 | 2027-12-17 |
| pH 4.01 | 2026-07-31 |

## Rumus linieritas suhu (dipakai untuk interpolasi presisi, bukan cuma tabel step-5°C)

- Buffer pH 4: y = 3×10⁻⁵x² − 0.0023x + 4.0455
- Buffer pH 7: y = 8×10⁻⁵x² − 0.0076x + 7.1182
- Buffer pH 10: y = 9×10⁻⁵x² − 0.0148x + 10.262

Di mana `x` = suhu larutan standar (°C), `y` = nilai pH buffer pada suhu tersebut.
Nilai standar yang dipakai di data hasil kalibrasi (misal 4.00924457 pada ±22.2 °C)
adalah hasil dari rumus ini, bukan dari tabel step-5°C.
