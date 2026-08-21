# Pertanyaan ke Lab — TITS (Temperature Indikator Tanpa Sensor)

Status: menunggu jawaban · Dibuat 21 Agustus 2026 · Sumber:
`Master Olah Data_Suhu_TITS fungsi Measure utk UUT.xlsm` (sesi 01-CAL-625,
PT Sistem Dirgantara Inovasi Teknologi, Graphtech GL840, 8 Mei 2025) dan
`Master Olah Data_Suhu_TITS fungsi Source utk UUT.xlsm` (sesi 0159-CAL-626,
PT GE Nusantara Turbine Services, Siemens Simatic IPC477FE, 10 Juni 2026)

Backend TITS (alat ke-11) sudah jalan penuh. Kedua mode diadu ke workbook
sampai digit terakhir — `Tests\Unit\TitsBudgetTest` &
`Tests\Feature\TitsSesiTest`, hijau di SQLite maupun MySQL. Delapan hal di
bawah **tidak** bisa diputuskan dari berkas yang ada, dan semuanya sudah diberi
keputusan sementara supaya pekerjaan tidak berhenti.

---

## 1. Pembagi AC Pick Up: label `rect.` tapi selnya √(√3)

Komponen "Pengaruh AC Pick Up" ditandai distribusi `rect.` di kolom K — dan
distribusi persegi pembaginya √3. Selnya bilang lain:

```
Q22 = SQRT(3)          ← kolom "Divisor", sampai sini benar
U22 = N22/SQRT(Q22)    ← akar DIAMBIL LAGI dari divisor yang sudah berupa akar
```

Jadi pembagi efektifnya `3^0,25 ≈ 1,3161`, bukan `1,7321`. Baris tepat di atas
dan di bawahnya menulis `U = N/Q` yang benar dengan `Q` yang sama-sama
`SQRT(3)`. Kejanggalan ini muncul **identik di kedua workbook**.

**Yang dipakai sekarang:** angka sel (√(√3)) — itu yang mereproduksi `Uc` kedua
sesi. Tiap sesi mencetak catatan audit yang menyebutkan hasil versi
dibetulkannya.

**Dampak kalau dibetulkan ke √3:** `u` komponen ini turun dari 0,1520 ke
0,1155, dan U95 sesi measure contoh turun **0,8534 → 0,8351 °C**. Masih di atas
CMC 0,83, jadi angka yang tercetak di sertifikat ikut berubah — bukan kosmetik.

**Yang ditanyakan:** yang mana yang dimaksud — pembaginya, atau labelnya? Kalau
seharusnya √3, yang diganti satu konstanta
(`TitsCalculator::PEMBAGI_AC_PICKUP`).

## 2. `v_eff` tidak dipotong ke bawah sebelum dicari `k`-nya

Master menghitung `k` lewat aproksimasi polinomial atas `v_eff` pecahan apa
adanya (`AC27 = 1.95996 + 2.37356/v + …`). Sepuluh alat lain di sistem ini
memotong `v_eff` ke bawah dulu, sesuai **GUM G.4.1**, dan pilihan itu dulu
diverifikasi cocok dengan lembar manual pH lab.

Selisihnya nyata waktu `v_eff` kecil — sesi measure contoh `v_eff` 6,5068:

| cara | `k` | U95 | tercetak |
|---|---|---|---|
| apa adanya (master TITS) | 2,4015 | 0,8533 | **0,85** |
| dipotong ke 6 (GUM G.4.1, sepuluh alat lain) | 2,4469 | 0,8694 | **0,87** |

**Yang dipakai sekarang:** cara master TITS, supaya U95-nya sama dengan
sertifikat 01-CAL-625 yang sudah diserahkan ke pelanggan. Tiap sesi mencetak
catatan audit `v_eff_tidak_dipotong` yang menyebutkan angka versi satunya.

**Yang ditanyakan:** apakah dua master TITS ini memang sengaja beda cara dari
lembar pH, atau ini yang perlu diseragamkan? Kalau diseragamkan, yang diganti
satu konstanta (`TitsCalculator::FLOOR_V_EFF`).

## 3. Budget mode Source punya DUA komponen drift, satu di antaranya sel mati

Sheet `PERHITUNGAN U95%` workbook Source memuat:

- baris 20 — `Ketidakpastian Baku Drift Temp. Kalibrator`, lookup normal ke
  tabel drift menurut merk & tipe sensor (Type S → 0,056);
- baris 22 — `Drift`, isinya `='STANDAR KALIBRATOR'!Y8` dengan `ci = 2`.

`Y8` itu **alamat mutlak** ke drift *Constant Type N* (0,38), padahal sesi itu
memakai *Yokogawa Type S*. Sel itu tidak ikut berubah kalau tipe sensornya
diganti — dia bukan lookup. Workbook Measure tidak punya baris ini sama sekali.

**Yang dipakai sekarang:** ikut disertakan, karena itu yang mereproduksi
`Uc = 0,5761` master. Tiap sesi source mencetak catatan audit
`drift_referensi_mati`.

**Dampak kalau dibuang:** `Uc` turun 0,5761 → 0,3733 dan U95 hitung 1,1377 →
±0,75. Untuk sesi contoh **U95 yang dilaporkan tidak berubah** (1,2 — lantai
CMC menang di kedua versi), tapi untuk sesi lain bisa berubah.

**Yang ditanyakan:** apakah baris 22 memang komponen tersendiri (kalau ya, dari
mana angkanya dan kenapa `ci = 2`), atau sisa salin-tempel yang seharusnya
tidak ada?

## 4. Drift kalibrator dibagi 2 di Measure, tidak di Source

Komponen yang sama, dua perlakuan:

```
Measure  N20 = VLOOKUP(K17, Tabel_Drift_Victor, 2, 0) / 2
Source   N20 = VLOOKUP(K17, Tabel_Drift_Yokogawa, 2, 0)
```

**Yang dipakai sekarang:** ikut masing-masing workbook.

**Yang ditanyakan:** apakah pembagian 2 di mode Measure itu konversi dari
"drift ± setengah rentang" (dan karenanya mode Source yang kurang), atau
sebaliknya?

Catatan tambahan: nama range di workbook Measure `Tabel_Drift_Victor` menunjuk
ke kolom **Yokogawa** (`STANDAR KALIBRATOR!Z7:AA14`). Nama "Victor" itu
peninggalan kalibrator lama; isinya benar. Sementara `Tabel_Drift_Yokogawa` di
workbook Source menunjuk ke **workbook luar** (`[4]STANDAR KALIBRATOR!AK7:AL9`)
yang tidak ikut dikirim — nilai tersimpannya kebetulan sama dengan kolom
lokalnya, jadi yang dipakai kolom lokal.

## 5. `u` kalibrator diambil beda cara di dua mode

```
Measure  O19 = MAX('STANDAR KALIBRATOR'!P32:P49)     ← MAX seluruh rentang
Source   O19 = VLOOKUP(R17, U95_source, …)           ← U95 di titik index TERTINGGI sesi
           R17 = 'PERHITUNGAN FC'!P41 = MAX(P23:P40)
```

Mode Measure konservatif (ambil U95 terburuk di seluruh tabel), mode Source
tidak (ambil U95 di ujung atas sesi). Untuk sesi contoh: Measure memakai 0,36
(dari titik 1200 °C, padahal sesinya berhenti di 1000), Source memakai 0,56
(titik 1200 °C, dan sesinya memang sampai situ).

**Yang dipakai sekarang:** ikut masing-masing workbook.

**Yang ditanyakan:** mana yang jadi aturan lab?

## 6. Titik yang jatuh TEPAT di tengah dua titik tabel

Setpoint 1100 °C di sesi Source berjarak sama (100 °C) ke titik tabel 1000 dan
1200. Rumus masternya `INDEX(…, MATCH(MIN(ABS(…)), …, 0))` semestinya
mengembalikan yang **pertama** ketemu, yaitu 1000 (koreksi −0,15). Hasil yang
tersimpan di selnya **1200** (koreksi −0,20), dan itu yang tercetak di
sertifikat 0159-CAL-626.

**Yang dipakai sekarang:** hasilnya, bukan rumusnya — pada seri, titik yang
lebih tinggi menang. Itu satu-satunya kasus seri di kedua workbook.

**Yang ditanyakan:** (a) mana yang benar untuk kasus seri, dan (b) apakah untuk
setpoint yang jauh dari titik tabel sebaiknya **diinterpolasi** saja? Sekarang
koreksi titik tabel dipakai utuh tanpa interpolasi, dan sistem cuma memunculkan
peringatan kalau jaraknya lebih dari 50 °C.

## 7. Kolom U95 `Type K` Yokogawa di workbook Source berisi angka negatif

`STANDAR KALIBRATOR!N32:N47` (tabel **U95**) berisi −0,06 sampai −0,31 — itu
persis deret **koreksi** dari tabel di atasnya, tersalin ke kolom yang salah.
U95 tidak bisa negatif.

**Yang dipakai sekarang:** ditolak. Sesi Type K mode Source tidak akan menyusun
komponen "sertifikat kalibrator", mencetak catatan audit `komponen_tanpa_data`,
dan jatuh ke lantai CMC.

**Yang diminta:** kolom U95 Type K Yokogawa untuk fungsi Source yang benar.

## 8. Tiga hal kecil

**(a) Nomor formulir lembar kerja belum ada.** Satu-satunya nomor formulir di
seluruh berkas `SIDIK-FM-CAL-2403_Rev. 0` di footer sheet `SERTIFIKAT` — itu
formulir sertifikat, dipakai bersama semua alat. `kode_dokumen` lembar kerja
dikosongkan (null); mohon nomor formulir lembar kerja TITS-nya.

**(b) `k` dicetak nol desimal di sertifikat.** `SERTIFIKAT!O30` berformat `0`,
jadi `k = 2,40` tercetak `2`. Pembaca sertifikat tidak punya cara membedakannya
dari `k = 2` yang sebenarnya. Ditiru apa adanya; mohon konfirmasi apakah
formatnya yang perlu diperbaiki (satu konstanta:
`TitsProfile::desimalFaktorCakupan()`).

**(c) Rentang RLK sensor resistif beda antara dua dokumen.** Lampiran
akreditasi menulis **−20…800 °C**, `DATABASE!T11` master Excel menulis
**−10…800 °C**. Yang dipakai lampiran (dokumen yang mengikat lab). Mohon
konfirmasi mana yang berlaku.

---

## Yang TIDAK ditanyakan karena sudah jelas

- Titik 1400 & 1700 di kolom Type N / Type S berisi `0` di tabel koreksi
  **maupun** U95. Nol di kedua kolom sekaligus itu sel kosong yang diisi nol,
  bukan koreksi nol yang terukur — tapi karena tidak ada sesi yang mencapai
  titik itu, tidak ada dampaknya sekarang. Datanya tetap disimpan apa adanya.
- Sel `#REF!` (kolom Type B & Type S Yokogawa dari titik 600 ke atas) dibuang
  jadi kosong, bukan dibaca 0.
- Lab belum punya klaim CMC untuk **Type B** — lampiran akreditasi cuma memuat
  Type K/J/T/N/R/S dan Resistance sensor. Sesi Type B boleh disimpan tapi tidak
  menghasilkan U95.
