# Ringkasan — Master Olah Data pH (Trial)

Folder ini adalah versi rapi dari `Master Olah Data_pH for trial.xlsm`.

**Kenapa nggak langsung baca file `.xlsm`-nya?**
File aslinya (`../Master Olah Data_pH for trial.xlsm`) di-password protect (terenkripsi),
jadi nggak bisa dibuka otomatis. Untungnya semua sheet-nya sudah pernah di-export
manual ke CSV di folder sebelah (`../Master Olah Data_pH for trial_CSV/`), dan isinya
lengkap. Folder ini adalah hasil rapi-in dari 7 CSV tersebut — kolom kosong dan
artefak export Excel dibuang, datanya dikelompokkan per topik biar gampang dibaca.

**Satu contoh kalibrasi ASLI & LENGKAP** yang ada di data ini:
- Alat: pH Meter Mettler Toledo Five Easy, S/N `B628755900`
- Customer: PT Tirta Gracia Semesta Mandiri
- No. Sertifikat: `012-CAL-524` / No. Order: `2405.13.A`
- Tanggal kalibrasi: 26 Mei 2024, terbit 30 Mei 2024

## Isi folder

| File | Isi |
|---|---|
| [01-identitas-alat-dan-customer.md](01-identitas-alat-dan-customer.md) | Data alat yang dikalibrasi + data customer |
| [02-data-hasil-kalibrasi.md](02-data-hasil-kalibrasi.md) | Pembacaan mentah 5x pengulangan di 3 titik (pH 4/7/10), sebelum & sesudah adjustment |
| [03-perhitungan-ketidakpastian.md](03-perhitungan-ketidakpastian.md) | Budget ketidakpastian GUM per titik ukur (Type A, Type B, U95%) |
| [04-koefisien-sensitivitas-suhu.md](04-koefisien-sensitivitas-suhu.md) | Tabel koreksi nilai pH buffer terhadap suhu |
| [05-standar-acuan.md](05-standar-acuan.md) | Buffer pH 4/7/10 + termometer & sensor standar, traceability, masa berlaku |
| [06-sertifikat.md](06-sertifikat.md) | Nilai final yang tercetak di sertifikat + penandatangan |
| [07-referensi-lab.md](07-referensi-lab.md) | Data umum lab (bukan spesifik sertifikat ini): daftar teknisi, thermohygro TH-1..7, daftar 34 metode IK |

## Ke mana data ini dipakai di backend

Data di sini adalah sumber untuk `database/data/kalibrasi-ph-meter.json`, yang
dibaca oleh `database/seeders/PhMeterSeeder.php` buat bikin record
kalibrasi ASLI (bukan dummy) yang jalan penuh lewat sistem yang sama dipakai app:
`Customer → Equipment → CalibrationSession → RawMeasurement → GumCalculator →
UncertaintyCalculation → Certificate (PDF beneran ke-generate)`.
