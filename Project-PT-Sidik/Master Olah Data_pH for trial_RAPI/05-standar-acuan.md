# Standar Acuan (Alat Standar Milik Lab)

Sumber: `DATABASE.csv`, `SERTIFIKAT.csv`

| No | Nama | Merk/Type | S/N | Traceability | U95% | Tanggal Kalibrasi | Interval | Due Date |
|---|---|---|---|---|---|---|---|---|
| 1 | pH Buffer Solution 4 | Supelco/Merck | HC32513535 | Merck KGaA | 0.02 pH | 2023-07-20 | 3 tahun | 2026-07-31 |
| 2 | pH Buffer Solution 7 | Supelco/Merck | HC46341939 | Merck KGaA | 0.02 pH | 2024-12-17 | 3 tahun | 2027-12-17 |
| 3 | pH Buffer Solution 10 | Supelco/Merck | HC45400338 | Merck KGaA | 0.03 pH | 2024-08-07 | 3 tahun | 2027-07-31 |
| 4 | Termometer & Sensor Std. | Yokogawa/CA 150 Handy Cal | 23P1005 | LK-285-IDN | 0.72 °C | 2025-08-12 | 1 tahun | 2026-08-12 |

Baris 1–3 (buffer pH) sudah ada di `DemoDataSeeder.php`. Baris 4 (termometer &
sensor) BELUM ada di seeder manapun — dipakai [PhMeterCapabilitySeeder] secara
tidak langsung (komponen ketidakpastian suhu di
[03-perhitungan-ketidakpastian.md](03-perhitungan-ketidakpastian.md)), dan sekarang
ditambahkan lewat `PhMeterSeeder`.

Catatan: kolom sumber aslinya bertuliskan "U95% (µS/cm)" — itu template bawaan sheet
untuk conductivity meter yang dipakai ulang, satuan sebenarnya untuk baris pH & suhu
di atas mengikuti isi tabelnya masing-masing (pH untuk baris 1-3, °C untuk baris 4),
bukan µS/cm.
