# SPEC — Profil Kalibrasi Turbidimeter (alat ke-2 dari 48)

Serah-terima buat Rehan. Sumber kebenaran: `Master Olah Data_Turbidimeter.xlsm`
(pw `spirit285`) — sheet INPUT DATA, PERHITUNGAN, PERHITUNGAN U95%, DATABASE.
Metode `SIDIK-IK-CAL-0523`, form `SIDIK-FM-CAL-0530_Rev.2` (DATABASE row).

Status: **jalan end-to-end dengan ANGKA ASLI** (worksheet → hitung GUM → seeder
→ sesi demo). 3 titik: **1 / 100 / 1000 NTU**.

## Kenapa 3 titik, bukan 5 (form nyetak 5)

Form PDF `SIDIK-FM-CAL-0530_Rev.2` nyetak 5 kolom (0,04/15/100/750/2000 NTU),
TAPI master data lab (sheet DATABASE workbook) cuma punya **3 standar turbidity
yang beneran dimiliki**: 1/100/1000 NTU (Supelco/Merck LRAD7304/7305/7089),
dengan U95 sertifikat **0,04 / 3 / 21** dan CMC **0,041 / 3,1 / 22**. Sertifikat
trial 0189-CAL-624 juga pakai 3 titik itu. Standar **15 & 750 NTU nggak ada**
di master data + nggak ada sertifikatnya — jadi angkanya nggak bisa diisi tanpa
ngarang. Semua angka turbidimeter sekarang **ASLI, bukan placeholder**.

### Kalau lab NAMBAH standar 15 & 750 NTU (biar 5 titik penuh)
Butuh dari lab: U95 sertifikat tiap larutan baru + CMC-nya (lampiran LK-285-IDN).
Lalu tambah baris di `TurbidimeterProfile::TITIK` & `STANDARD_TERCETAK`,
`TurbidimeterCapabilitySeeder`, `TurbidimeterSeeder`. Engine-nya udah generik —
nggak perlu diubah.

## Arsitektur "Profil Kalibrasi"

Rumus & bentuk formulir per jenis alat sekarang hidup di **satu file profil**,
bukan di-hardcode di kelas bersama.

```
app/Services/Calibration/
  Profiles/
    CalibrationProfile.php        # kontrak (abstract)
    PhMeterProfile.php            # pH — delegasi worksheet ke LembarKerjaTemplate lama
    TurbidimeterProfile.php       # NTU — worksheet + budget 4 komponen sendiri
  CalibrationProfileRegistry.php  # resolve profil dari equipments.nama_alat_kemampuan
```

Nambah alat ke-3: bikin `XxxProfile extends CalibrationProfile`, daftarin di
`CalibrationProfileRegistry::daftarProfil()`, tambah `KODE_GUM_XXX` di
`Formula`, tambah `XxxCapabilitySeeder`. **Kelas bersama nggak disentuh.**

Yang generik & dipakai ulang (JANGAN disalin per alat):
- `GumCalculator::agregasiBudget()` — Uc = √Σ(u·ci)², v_eff Welch–Satterthwaite,
  k dari t-student, U = k·Uc.
- Lantai CMC: `U dilaporkan = max(U_hitung, CMC)` (ILAC — nggak boleh ngeklaim
  lebih baik dari akreditasi).
- `PerhitunganBuilder`, `CalibrationValidator`, keputusan PASS/FAIL guarded.

## Dispatch

- `GET /api/calibrations/lembar-kerja?profil=turbidimeter` (atau
  `?instrumen=Turbidimeter`) → `CalibrationController::lembarKerja()` milih profil
  via registry. Tanpa param = pH (mobile lama nggak berubah).
- Hitung: `GumCalculator::hitungTitik()` resolve profil dari
  `equipment.nama_alat_kemampuan`, minta `komponenBudget()`-nya. Kalau non-null →
  jalur budget penuh; kalau null → jatuh ke CMC/generik lama.
- Stempel rumus: `RumusKalibrasi::versiUntukSesi()` pakai `kodeFormula()` profil
  (`gum-turbidi` vs `gum-ph`).

## Rumus Turbidimeter (beda dari pH)

| Aspek | pH | Turbidimeter |
|---|---|---|
| Titik | 4 / 7 / 10 | **1 / 100 / 1000 NTU** |
| Resolusi | tunggal (`equipments.resolusi`) | **per-titik** 0,01 / 0,1 / 1 → di `TurbidimeterProfile::TITIK` |
| Nilai standar | dikoreksi suhu (`koefisien_suhu`) | **nominal** (nggak ada kurva suhu) |
| Komponen budget | 5 | **4** (nggak ada "pengaruh perbedaan suhu") |
| Komponen suhu | u = U_temp/2 (normal) | u = UTemp/√3 (rect); **ci = (UTemp/400)·titik** |

Budget 4 komponen per titik (semua masuk `agregasiBudget`):

1. **Sertifikat kalibrator** — u = `standard.ketidakpastian`/2, ci 1, vi 200 (normal)
2. **Daya baca alat** — u = (resolusi_titik/2)/√3, ci 1, vi 1e6 (rect)
3. **Temperature** — u = UTemperature/√3, ci = (UTemperature/400)·titik, vi 200 (rect)
4. **Pengulangan (Type A)** — u = STDEV/√n, ci 1, vi n−1 (t-student)

`UTemperature = √((U_thermometer/2)² + (U_sensor/2)²)` dari sertifikat termometer
(U95 0,72 °C) & sensor TC (U95 0,06 °C), k=2 → **0.36124783736376886**. Disimpen
di `calibration_capabilities.u_temperature`. `ci_suhu` **NULL** (profil ngitung
sendiri, turunan titik — beda dari pH yang nyimpen konstanta).

## Nilai acuan (dicek lawan workbook, sel PERHITUNGAN U95%)

| Titik | U95 std | res | uc (AB16/30/44) | k | U hitung (AB19/33/47) | CMC | **Dilaporkan** |
|---|---|---|---|---|---|---|---|
| 1 | 0,04 | 0,01 | 0.0206002139 | 1.9714346585 | 0.0406119757 | 0,041 | **0,041** |
| 100 | 3 | 0,1 | 1.5005292834 | 1.9718962236 | 2.9588880273 | 3,1 | **3,1** |
| 1000 | 21 | 1 | 10.5085114560 | 1.9718962236 | 20.7216940561 | 22 | **22** |

Test: `tests/Unit/TurbidimeterBudgetTest.php` (reproduksi sampai 1e-6/1e-8) &
`tests/Feature/LembarKerjaTest.php` (endpoint NTU/3 titik/resolusi per-titik).

## Seeder

- `TurbidimeterCapabilitySeeder` — CMC 3 titik (0,041/3,1/22) + `u_temperature`.
  WAJIB abis `CalibrationCapabilitySeeder`.
- `TurbidimeterSeeder` — standar turbidity 1/100/1000 NTU (U95 0,04/3/21, k=2),
  alat HACH 2100Q, sesi demo `2406.32.A` (hitung lewat GumCalculator, hasil PASS).

## Follow-up (belum dikerjain)

1. **Toleransi per-titik.** `equipments.toleransi` skalar; turbidimeter aslinya
   ±% pembacaan. Demo pakai 24 (biar titik 1000 PASS). Perlu toleransi per-titik
   (mungkin di `calibration_capabilities`) buat keputusan PASS/FAIL yang bener.
2. **5 titik penuh** — kalau lab nambah standar 15 & 750 NTU (lihat bagian atas).

## Udah diverifikasi
- Sertifikat PDF turbidimeter render 1 halaman + kop full-bleed (dicek lewat
  `CertificateSnapshotBuilder` → `sertifikat.pdf` blade, satuan NTU kebawa).
- Sesi demo `2406.32.A`: U95 keluar 0,041 / 3,1 / 22 (cocok workbook), PASS.
