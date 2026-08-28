# Pertanyaan ke lab — dua workbook master TIDS

**Sumber:** `Master Olah Data Suhu TIDS - Recorder Graptech.xlsm` &
`Master Olah Data Suhu TIDS - Yokogawa K,N.xlsm`, dikirim pemilik proyek 28 Agt 2026
(ber-password). Dua-duanya berkop `KALIBRASI TEMPERATURE INDIKATOR DENGAN SENSOR (TIDS)`,
nomor lingkup `LK-285-IDN`, metode `SIDIK-IK-CAL-0503_Rev.6`.

**Yang sudah dikerjakan:** budget ketidakpastian TIDS akhirnya bisa dihitung — `TidsCalculator`
mereproduksi kedua sesi contoh sampai digit terakhir (`Tests\Unit\TidsMasterTest`). Blokir U95
yang berdiri sejak profil ini lahir sudah dicabut.

**Yang ditulis di berkas ini:** empat tempat di mana kedua master **tidak sepakat dengan
tabelnya sendiri**. Keempatnya DITIRU apa adanya — supaya angka aplikasi cocok dengan sertifikat
yang sudah diserahkan ke pelanggan — dan keempatnya melahirkan catatan audit + peringatan sesi
yang menyebut berapa angkanya kalau dibetulkan. Yang memutuskan manajer teknis lab, bukan kode.

---

## Ringkasan angka sesi contoh

| | Recorder Graptech (`071-CAL-325`) | Yokogawa (Thermometer Bola Basah) |
|---|---|---|
| Standar / sensor | Temperature Recorder GL840 / Thermocouple Type K | Yokogawa CA 150 / PRT PT100 |
| Set point tertinggi | 200 °C | 35 °C |
| Uc (`AC37`) | 0,81598032 | 0,54206467 |
| v_eff (`AC38`) | 82,78188732 | 258,79710209 |
| k (`AC39`) | 1,98904831 | 1,96917374 |
| U = k·Uc (`AC40`) | **1,62302428 °C** | **1,06741951 °C** |
| CMC pita (`AC41`) | 1,4 °C | 0,86 °C |
| U dilaporkan (`AC42`) | 1,62302428 °C | 1,06741951 °C |

Aplikasi memulangkan angka yang sama; selisihnya di orde 1e-5 dan datang dari cara mencari `k`
(master pakai aproksimasi polinomial Excel, repo pakai `StudentTDistribution` yang punya test
sendiri). Jauh di bawah presisi cetak sertifikat.

---

## D1 · `O24` workbook Recorder menunjuk sel TETAP

```
PERHITUNGAN U95%!O24  =Standar_Recorder!T30
```

`T30` itu sel di blok **Type N**, kolom **CH17**, baris **0 °C** — nilainya `0,83`, dan dia
dipakai apa pun tipe sensor & kanal sesinya. Sesi contoh memakai **Type K**, dan tabel U95
recorder di workbook yang sama berbunyi **0,67** untuk seluruh kolom Type K.

- Ditiru: `TidsCalculator::U95_METER_RECORDER_TETAP = 0.83`.
- **Pertanyaan:** `T30` itu memang keputusan lab ("pakai angka terburuk untuk semua tipe"),
  atau rumus yang lupa diganti waktu tabel per-kanal dibuat?

> **D1, D2 & D3 dihitung bersama.** Ketiganya menyentuh sesi yang sama, jadi angka
> "kalau dibetulkan"-nya cuma berarti kalau ketiganya dibaca dari tabel sekaligus:
> U95 sesi contoh jadi **1,6836 °C**, bukan 1,6230. Arah masing-masing berbeda —
> D1 sendirian menurunkan (0,83 → 0,67) sementara D2 (0,14 → 0,44) dan D3
> (0,2 → 0,5) menaikkan — dan yang menang dua yang menaikkan. Jadi **angka yang
> terbit sekarang lebih kecil** daripada kalau workbook membaca tabelnya sendiri.
> Angka itu ikut di catatan audit tiap sesi Recorder, dihitung ulang per sesi,
> bukan disalin dari sini.

## D2 · `O25` workbook Recorder ditulis literal `0,14`

Workbook yang sama punya sheet `TABEL NILAI U95% TERMOKOPEL` yang berbunyi **0,44** (Type K)
dan **0,76** (Type N) di seluruh titik ≤ 400 °C. Angka `0,14` tidak ada di tabel mana pun di
kedua workbook.

- Ditiru: `TidsCalculator::U95_SENSOR_RECORDER_TETAP = 0.14`.
- **Pertanyaan:** `0,14` datang dari sertifikat sensor mana? Kalau dari sertifikat baru yang
  belum masuk tabel, tabelnya yang perlu diperbarui.

## D3 · `N27` workbook Recorder menunjuk sel di tabel KOREKSI

```
PERHITUNGAN U95%!N27  =Standar_Recorder!AM9
```

`AM9` = kolom **CH16 Type K**, baris **−20 °C**, di `TABEL NILAI KOREKSI TEMPERATURE RECORDER`.
Nilainya **−0,2** — setengah-lebar distribusi persegi yang NEGATIF, yang cuma mungkin kalau
sumbernya memang bukan tabel drift.

Workbook itu punya `Tabel_Drift_Recorder` (`AT8:AU9`): **Type N 0,25 · Type K 0,5**. Rentang
bernama itu ada, terdaftar, dan **tidak dipakai satu rumus pun**.

- Ditiru: `TidsCalculator::DRIFT_METER_RECORDER_TETAP = -0.2`.
- **Pertanyaan:** ini salah tunjuk sel, atau `Tabel_Drift_Recorder` yang sudah tidak berlaku?

## D4 · `AC36` workbook Constant/Yokogawa menjumlah 9 dari 12 komponen

```
Recorder            AC36 =SUM(AC24:AD35)     ← dua belas komponen
Constant/Yokogawa   AC36 =SUM(AC24:AD32)     ← berhenti di baris 32
```

Tiga komponen terakhir — **Self Heating (0)**, **Interpolasi (0,19788)**, **Drift UUT**
(dari uji titik es) — lahir, ditampilkan, lalu tidak ikut dijumlah. Dua master, satu alat, dua
jawaban.

- Ditiru per workbook: `TidsCalculator::TIGA_KOMPONEN_TERAKHIR`.
- Kalau ketiganya ikut, U95 sesi contoh Yokogawa **1,1411 °C**, bukan 1,0674.
- **Ini yang paling mendesak dijawab**, karena arahnya membuat U95 lebih KECIL: sertifikat yang
  understate ketidakpastiannya adalah temuan asesor.
- **Pertanyaan:** rentang `SUM` yang lupa dipanjangkan waktu tiga baris terakhir ditambahkan,
  atau ketiganya memang tidak berlaku untuk kalibrator blok?

---

## Yang lain, lebih kecil

### T1 · Komponen `Interpolasi` datang dari workbook luar

```
O34  =[13]Sheet2!$B$7      (Recorder)
O34  =[7]Sheet2!$B$7       (Yokogawa)
```

Workbook yang ditunjuk tidak ikut dikirim. Nilai yang ke-cache di dua-duanya sama persis —
`0,19788162882115856` — jadi diperlakukan sebagai konstanta lab
(`TidsCalculator::KETIDAKPASTIAN_INTERPOLASI`). Divisornya `Q34 = 1`: satu-satunya komponen
berdistribusi persegi di seluruh tabel yang **tidak** dibagi √3.

**Pertanyaan:** kirim workbook sumbernya, atau tetapkan angkanya sebagai konstanta terkendali.

### T2 · In-homogeneity termokopel dihitung dua cara

| workbook | `N26` |
|---|---|
| Recorder | literal `0,6` |
| Constant/Yokogawa | `=0.25% * MAX('INPUT DATA'!L33:L34) / 2` |

Rumus kedua cuma membaca **dua baris set point pertama**, bukan seluruh tabel. Aplikasi memakai
set point tertinggi seluruh sesi — arah yang lebih konservatif, dan selisihnya cuma muncul kalau
baris ke-3 ke bawah lebih panas dari dua baris pertama.

**Pertanyaan:** `MAX(L33:L34)` itu memang cuma dua baris, atau `MAX(L33:L46)` yang dimaksud?

### T3 · Rantai `IF` pita CMC bolong di dua tempat

```
AC41 =IF(U22<=150, S5, IF(AND(U22>151,U22<=400), S6, IF(AND(U22>401,U22<=600), S7, "cek rentang")))
```

Set point **150,5 °C** tidak masuk cabang mana pun (>150 tapi tidak >151) dan sel-nya
memulangkan teks `"cek rentang"`. Sama untuk 400,5 °C. Aplikasi **tidak** meniru lubang ini —
pita di `calibration_capabilities` tidak punya celah, dan batas atas dimenangkan pita bawah
(aturan yang sama dengan lembar Thermocouple).

**Pertanyaan:** konfirmasi bahwa 150 °C memang masuk pita 0,86 (bukan 1,4).

### T4 · PRT PT100 tidak bisa dipakai di recorder

Cabang terakhir kedua rumus koreksi jatuh ke `VLOOKUP(…, 100, 0)` — kolom ke-100 di tabel 42
kolom. Errornya dibungkus `IFNA(…,"")`, jadi hasilnya kosong, dan kosong ikut dijumlah `J+Q+R`
sebagai **nol**. Artinya sesi TIDS dengan PRT PT100 di recorder terbit dengan koreksi meter DAN
koreksi sensor dua-duanya hilang, tanpa satu pun error.

Aplikasi **memblokir** kombinasi itu dengan alasan yang kebaca teknisi.

**Pertanyaan:** PT100 memang tidak pernah dipakai bersama recorder, atau tabel kolomnya yang
belum dibuat?

### T5 · Drift sensor Type K beda antar workbook

| workbook | Type N | Type K | RTD |
|---|---|---|---|
| Recorder (`Drift_sensor`) | 0,55 | **0,55** | — |
| Constant/Yokogawa (`Tabel_Drift_Sensor`) | 0,55 | **0,5** | 0,502 |

Disimpan per keluarga apa adanya. **Pertanyaan:** dua sertifikat sensor yang berbeda, atau satu
angka yang belum diseragamkan?

---

## Yang TERJAWAB oleh workbook ini (tidak perlu ditanyakan lagi)

| Pertanyaan lama | Jawaban |
|---|---|
| **K1** — lembar TIDS itu 5 UUT dalam 1 sesi, atau 5 sesi terpisah? | **Tidak pernah ada lima UUT.** Kolomnya dinamai `PRT1`…`PRT5` di master dan dipakai `AVERAGE`+`STDEV` per baris: lima ULANGAN, satu alat, satu baris = satu set point |
| **K2** — kapan workbook TIDS turun dari lab? | Sudah — dua-duanya, 28 Agt 2026 |
| Aturan uji titik es 0 °C | Komponen budget `Drift UUT`: ½ × \|awal − akhir\|, distribusi persegi, ÷√3, vi 50 |
| Tabel koreksi dryblock A & B | Ada, dan **berbeda** (beda dari workbook Thermocouple yang dua sheet-nya identik — lihat K12): A Isotech stabilitas 0,0005 keseragaman 0,47 · B Techne stabilitas 0,03 keseragaman 0,1 |
| CMC mana yang berlaku | Kedua master menulis **0,86 / 1,4 / 3,1 °C**, sama persis dengan lampiran akreditasi. Angka 1,5 rata di master TIDS 2022 yang ke-cache sudah tidak berlaku |
| U95 per titik atau per sesi | **Per sesi** — satu baris `Uncertainty 95% ±` di bawah tabel sertifikat |
