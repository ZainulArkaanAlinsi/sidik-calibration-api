# Pertanyaan untuk Lab — Volumetric Glassware (Fixed & Graduated)

**Untuk:** Manajer Teknis
**Metode:** `SIDIK-IK-CAL-0510`
**Sumber:** workbook master `Fixed_Volumetric_Glassware_2026` dan `Graduated_Volumetric_Glassware_2026`
**Disusun:** 21 September 2026

Semua angka di dokumen ini **diadu langsung ke cache Excel** kedua workbook,
bukan dikutip dari dokumen analisis yang menyertainya. Setiap temuan menyebut
sel sumbernya supaya bisa diperiksa ulang tanpa membuka kode.

---

## Yang sudah terbukti cocok — tidak perlu ditanyakan

Rantai perhitungan inti sudah diimplementasikan ulang dan diadu ke master:

| Besaran | Selisih terhadap Excel |
|---|---|
| V20 Fixed (nominal 1 mL, `PERHITUNGAN!H60`) | **0** |
| V20 Graduated (titik 10 mL, ulangan 1) | **0** |
| Delapan koefisien sensitivitas Fixed | **0** semuanya |
| `uc` Fixed dan Graduated | **0** |
| Faktor cakupan `k` Graduated | 9·10⁻¹⁶ |

Catatan untuk `k`: angka ini hanya cocok kalau `veff` **dipotong ke bilangan
bulat** sebelum `TINV` (51,349 → 51). Excel memang begitu. Sistem mengikutinya.

---

## Pertanyaan

### 1. `Veff` di workbook Fixed membagi dengan SATU baris, bukan jumlahnya

`PERHITUNGAN_U95%` Fixed:

```
Veff = (I45^4) / K43      ← K43 = baris "Repeated measurements" saja
```

Workbook **Graduated** menulis rumus yang sama dengan benar:

```
Veff = (I45^4) / K44      ← K44 = SUM seluruh (ui·ci)⁴/vi
```

Rumus Welch–Satterthwaite menuntut pembaginya **jumlah** seluruh komponen.
Karena workbook saudaranya menulisnya benar, ini tampak seperti rujukan sel yang
meleset satu baris, bukan keputusan metode.

Dampaknya pada contoh di workbook (Pipet Volume 1 mL):

| | Rumus master | Welch–S. benar |
|---|---|---|
| Veff | 11.846,5 | 53,1 |
| k | 1,9602 | 2,0056 |
| U | 0,000997 mL | 0,001020 mL (**+2,32%**) |

Pada contoh ini `U95% sertifikat = MAX(U, CMC) = 0,003 mL` — CMC yang menang,
jadi angka cetaknya tidak berubah. **Untuk alat yang U-hitungnya melewati CMC,
selisih ini akan tercetak.**

**Pertanyaan:** Apakah `K43` memang kekeliruan salin-tempel? Kalau ya, apakah
ada sertifikat Fixed yang sudah terbit dengan `k` yang terlalu kecil?

**Sikap sistem saat ini:** menghitung dengan rumus yang benar (arah hasilnya
lebih besar/konservatif), dan menyimpan angka master sebagai pembanding di
catatan audit sesi.

---

### 2. Komponen keterulangan Graduated menghitung lima angka nol yang bukan data

`PERHITUNGAN!H55` Graduated, berlabel **"Rata - Rata STDEV"**:

```
H55 = STDEV(IFERROR(H54:Q54, ""))   =  0,03537981705907074
```

Rentang `H54:Q54` berisi 10 kolom. Nilai yang benar-benar ada hanya di H, J, L
(simpangan baku V20 tiap titik). Kolom N dan P berisi `#VALUE!` karena titiknya
kosong — `IFERROR` mengubahnya jadi teks dan `STDEV` mengabaikannya, **benar**.

Tetapi lima kolom sela (I, K, M, O, Q) adalah **sel kosong**, dan
`IFERROR(sel_kosong, "")` memulangkan **0**, bukan teks. Lima nol itu ikut
dihitung. Angka di sel tersebut cocok **persis** hanya dengan tafsiran ini (dari
16 tafsiran yang diuji):

```
STDEV( 0,0534763 ; 0 ; 0,0948427 ; 0 ; 0,0287416 ; 0 ; 0 ; 0 )    n = 8
```

Lima nol ini ada di **setiap** sertifikat Graduated, berapa pun titik yang terisi.

Dampak pada contoh di workbook (Gelas Ukur 100 mL):

| | Master (dengan 5 nol) | Tanpa 5 nol |
|---|---|---|
| H55 | 0,03537982 | 0,03339744 |
| U | 0,33725 mL | 0,33700 mL |
| Dibulatkan 2 desimal | **0,34** | **0,34** |

Pada contoh ini angka cetaknya tidak berubah. Itu kebetulan data, bukan jaminan.

**Pertanyaan:**
a. Apakah sel ini seharusnya rata-rata (labelnya) atau simpangan baku (rumusnya)?
b. Apakah lima nol itu disengaja?
c. Apakah ada sertifikat Graduated lama yang perlu ditinjau?

**Sikap sistem saat ini** (keputusan pemilik proyek, 21 Sep 2026): menghitung
**tanpa** lima nol, dan menyimpan angka master sebagai pembanding di catatan
audit sesi — supaya selisihnya selalu bisa diadu ke sertifikat lama.

---

### 3. Revisi metode tidak sama antara lampiran akreditasi dan workbook

| Sumber | Revisi |
|---|---|
| Lampiran akreditasi (data di sistem) | **Rev.6** |
| Master metode lab (`DATABASE.csv`) | **Rev.7** |
| Kedua workbook Volumetric | **Rev.7** |

**Pertanyaan:** Apakah data lampiran di sistem yang belum diperbarui, atau
Rev.7 belum masuk lingkup akreditasi? Jawabannya menentukan revisi mana yang
dicetak di sertifikat.

**Masih terbuka (8 Okt 2026):** ketujuh workbook lab Labu Ukur & Pipet Volume
yang dipakai acuan (`INPUT DATA!Y14`) menulis `SIDIK-IK-CAL-0510_Rev.7`,
sementara seeder CMC sistem masih mencatat `Rev.6`.

---

### 4. `#REF!` di baris tekanan udara sertifikat Graduated

`SERTIFIKAT` Graduated, baris "Tekanan Udara", dibandingkan dengan baris yang
sama di workbook Fixed (yang utuh):

| Kolom | Fixed | Graduated |
|---|---|---|
| nilai | `PERHITUNGAN!G19` → 933,15 | `PERHITUNGAN!G19` → 933,15 |
| satuan | `PERHITUNGAN!J19` → hPa | **`#REF!`** |
| simbol ± | `PERHITUNGAN!O19` → ± | **`#REF!`** |
| U95 | `PERHITUNGAN!P19` → 2,0025 | `PERHITUNGAN!P19` → 2,0025 |
| satuan | `PERHITUNGAN!Q19` → hPa | **`#REF!`** |

**Angkanya tetap benar** — yang rusak hanya satuan dan simbol ±. Sertifikat
Graduated yang dicetak dari Excel akan menampilkan `933,15 #REF! #REF! 2,0025
#REF!`.

**Pertanyaan:** Apakah rujukan yang benar memang `J19`/`O19`/`Q19` seperti di
workbook Fixed?

**Sikap sistem saat ini:** tidak mereplikasi `#REF!`, dan tidak menganggap
perbaikannya final sebelum dikonfirmasi.

---

### 5. Tanda koefisien sensitivitas muai termal berlawanan antar workbook

Komponen "Expansion Coefficient of Material":

- Fixed: `(J3·(J6−J4)) / (J6·(J5−J4))` — **positif**
- Graduated: `−(J3·(J6−J4)) / (J6·(J5−J4))` — **negatif**

Karena dikuadratkan dalam `uc`, **angka cetak tidak terpengaruh**. Tetapi
koefisien sensitivitas yang tercatat berbeda tanda untuk besaran yang sama.

**Pertanyaan:** Tanda mana yang benar?

---

### 6. Picnometer masuk keluarga Fixed atau Graduated?

Lampiran akreditasi mencantumkan enam alat untuk metode ini. Workbook membaginya
menjadi dua keluarga, tetapi **tidak menyebut Picnometer secara eksplisit** di
keduanya.

| Fixed (satu nominal) | Graduated (banyak titik) |
|---|---|
| Labu Ukur | Gelas Ukur |
| Pipet Volume | Buret |
| **Picnometer?** | Pipet Ukur |

Sistem saat ini menempatkan Picnometer di Fixed karena wadahnya bervolume
tunggal. **Itu dugaan pengembang, bukan dari master.**

**Pertanyaan:** Apakah penempatan itu benar?

---

### 7. "Class A/B" — kelas akurasi atau jenis bahan?

Kedua workbook memetakan:

- Class A → γ = 9,9·10⁻⁶ /°C (borosilicate 3.3)
- Class B → γ = 15·10⁻⁶ /°C (borosilicate 5.0)

Workbook Graduated juga menyertakan tabel **14 material** (soda-lime, aneka
plastik, aluminium, stainless steel, dst.), tetapi rumusnya hanya memakai dua
baris pertama.

**Pertanyaan:**
a. Apakah "Class A/B" di sini kelas akurasi ISO 4787, atau sebenarnya
   penanda bahan gelas?
b. Apakah tabel 14 material akan diaktifkan — misalnya untuk kalibrasi gelas
   ukur plastik?

**Sikap sistem saat ini:** hanya menerima A dan B. Nilai lain ditolak, bukan
dipetakan diam-diam ke nilai bawaan.

---

### 8. Stdev neraca Fujitsu dirujuk dari baris neraca Excellent (Fixed)

`PERHITUNGAN_U95%` Fixed, sel "stdev timb":

```
IF(C5="Electronic Balance Excellent", DATABASE!AE20,
IF(C5="Electronic Balance Fujitsu",   DATABASE!AE20, ...     <- seharusnya AE21
```

Fujitsu ada di baris 21, tetapi rumusnya membaca baris 20 (Excellent). Pola
salin-tempel yang sama dengan `Veff` di pertanyaan 1.

**Dampak saat ini nol**: kolom stdev ketiga neraca di workbook bernilai 0, jadi
baris mana pun yang dirujuk hasilnya sama. Tetapi begitu stdev Fujitsu diisi,
workbook akan memakai stdev Excellent tanpa pemberitahuan.

**Pertanyaan:** Apakah rujukan yang benar `AE21`?

**Sikap sistem:** membaca stdev dari baris neraca yang benar-benar dipilih.

**Masih sama di workbook Rev.7 (8 Okt 2026):** `PERHITUNGAN U95%!C17` untuk
Fujitsu tetap membaca `DATABASE!AE20`. Dampaknya tetap nol (`AE20 = AE21 = 0`).

---

### 9. Ketidakpastian timbang dihitung dengan cara berbeda antar workbook

Label selnya sama persis di kedua workbook ("LOP atau read."), tetapi isinya:

| | Fixed | Graduated |
|---|---|---|
| Diambil dari | kolom `AD` (resolusi neraca) | kolom `AB` (U95 sertifikat neraca) |
| Dibagi | √3 (sebaran rektangular) | 2 (faktor cakupan sertifikat) |
| Contoh | 0,0001 / √3 = 0,0000577 g | 0,0019 / 2 = 0,00095 g |

Keduanya cara yang sah, tetapi untuk komponen yang sama.

**Pertanyaan:** Mana yang dimaksud metode `SIDIK-IK-CAL-0510`?

**Sikap sistem:** masing-masing keluarga memakai cara workbook-nya sendiri.

---

### 10. Ketidakpastian densitas air suling beda seribu kali

| | Fixed | Graduated |
|---|---|---|
| Komponen "Density of Destillate Water" | `=0,05/1000` = **5·10⁻⁵** g/mL | angka mati **5·10⁻⁸** g/mL |

Pada contoh workbook dampaknya pada `uc` sangat kecil, tetapi kedua angka
tidak mungkin sama-sama benar untuk besaran yang sama.

**Pertanyaan:** Nilai mana yang benar?

**Sikap sistem:** masing-masing keluarga memakai angka workbook-nya sendiri.

---

### 11. Label "Correction" berlawanan dengan rumus yang tercetak

- Sheet budget (`PERHITUNGAN_U95%`): "Correction = Equipment Nominal − V20"
- Sertifikat kedua workbook, kolom "Correction": `= N21 − E21` = **V20 − Nominal**

Contoh: Pipet Volume 1 mL, V20 = 1,00426 → tercetak **+0,00426**. Dengan label
budget, angkanya seharusnya −0,00426.

Menurut konvensi umum, *correction* adalah nilai yang ditambahkan ke pembacaan
untuk mendapat nilai benar (= Nominal − Actual), sedangkan Actual − Nominal
adalah *error*/*deviation*.

**Pertanyaan:** Apakah kolom sertifikat seharusnya berjudul "Deviation"/"Error",
atau angkanya seharusnya bertanda sebaliknya?

**Sikap sistem:** mencetak persis seperti sertifikat master (V20 − Nominal).

### 12. Alat berskala yang dikalibrasi di SATU titik saja

Keterulangan budget Graduated adalah `STDEV` dari simpangan baku V20 **per
titik** (`PERHITUNGAN!H55`). Dengan satu titik, STDEV satu angka tidak
terdefinisi; master menutupinya karena lima sel kosong ikut terbaca nol
(pertanyaan no. 2), sehingga tetap keluar angka.

**Pertanyaan:** Apakah buret / gelas ukur / pipet ukur boleh dikalibrasi di
satu titik saja? Kalau boleh, komponen keterulangannya diambil dari mana —
simpangan baku tiga ulangan titik itu sendiri?

**Sikap sistem:** sesi Graduated dengan kurang dari dua titik **ditahan**
dengan alasan yang terbaca, tidak diterbitkan.

### 13. Kapasitas yang tidak ada di lampiran akreditasi

Lantai CMC master diambil dari kapasitas alat lewat nominal **terdekat**
(`INDEX/MATCH(MIN(ABS(...)))`). Buret di lampiran cuma 25 mL dan 50 mL, jadi
buret 10 mL memakai CMC 25 mL (0,019 mL), dan gelas ukur 150 mL memakai
baris 100 mL.

**Pertanyaan:** Apakah alat yang kapasitasnya tidak tercantum di lampiran
tetap boleh membawa klaim akreditasi dengan CMC baris terdekat, atau
sertifikatnya harus terbit tanpa klaim (seperti Hydrometer di luar pita)?

**Sikap sistem:** meniru master — baris terdekat, klaim akreditasi tetap.
Jejak sesi mencatat baris CMC mana yang terpakai (`jejak_titik`).

---

### 14. ρ air ulangan 1 dan 2 dihitung pada suhu tetap 25,5 °C (workbook Rev.7)

`PERHITUNGAN!H40` dan `J40` ("Average std Corrected" ulangan 1 dan 2) berisi
**angka ketik 25,5**, bukan rumus. Hanya `L40` (ulangan 3) yang berumus
`AVERAGE(L39:M39)`. Akibatnya ρ air ulangan 1 dan 2 (`H45`, `J45`) selalu
dihitung pada 25,5 °C, berapa pun suhu air yang dicatat teknisi.

Dampak pada tujuh workbook (suhu bacaan 25,2/25,3/25,2 °C): V20 bergeser
2,6·10⁻³ mL (LU-200) sampai 6,6·10⁻³ mL (LU-500), dan 6,5·10⁻⁶ mL (PV 0,5)
sampai 5,2·10⁻⁵ mL (PV 4). Pada LU-500 angka cetaknya ikut berubah:
500,24 mL (workbook) lawan 500,25 mL (hitungan dari suhu terukur).

**Pertanyaan:** Apakah 25,5 di `H40`/`J40` sisa uji yang lupa dikembalikan ke
rumus `AVERAGE(H39:I39)`/`AVERAGE(J39:K39)`?

**Sikap sistem:** keputusan pemilik 8 Okt 2026 (terakhir, menggantikan `ukur`):
**angka ikut workbook** — sakelar `VolumetricGlasswareCalculator::SUHU_DENSITAS_AIR_REV7`
= `master` (25,5 °C untuk ulangan 1 & 2) — **dan sistem tetap berjaga**: cabang
suhu terukur (`ukur`) tetap dihitung dan V20 + U-nya tercatat di jejak sesi
(`volumetric_rev7_suhu_densitas_air`). Kalau angka CETAK V20 atau Correction
(desimal sertifikat titik itu) berbeda antara kedua cabang, validator memunculkan
PERINGATAN `volumetric_suhu_25_5_menggeser_cetak` berisi kedua angka cetak. Di
ketujuh workbook acuan peringatan itu hanya muncul di LU-500 (500,24 lawan
500,25 mL); suhu air yang jauh dari 25,5 °C (mis. 22 °C) selalu memunculkannya.

### 15. Rentang suhu air tidak menyapu sel terakhir

`PERHITUNGAN!O35 = MAX(H39:L39)` berhenti di kolom L, sementara
`P35 = MIN(H39:M39)` sampai kolom M. Bacaan akhir ulangan ke-3 (`M39`) tidak
ikut dicari maksimumnya. Rentang ini masuk `PERHITUNGAN U95%!H21`
(u suhu air). Dampak di tujuh workbook nol, karena `M39 = L39`.

**Pertanyaan:** Apakah rujukan yang benar `MAX(H39:M39)`?

**Sikap sistem:** maksimum dan minimum dari ketiga bacaan suhu yang disimpan.

### 16. Suhu air dicatat enam kali di workbook, tiga kali di sistem

Workbook Rev.7 mencatat suhu awal dan akhir tiap ulangan (`INPUT DATA!H39:M39`,
enam sel). Lembar kerja sistem menyimpan satu suhu per ulangan (`vol_suhu`,
tiga nilai). Keduanya setara selama suhu awal = akhir, seperti di ketujuh
workbook acuan.

**Pertanyaan:** Apakah teknisi memang membaca suhu dua kali per ulangan? Kalau
ya, lembar kerja di HP perlu enam kotak suhu, dan suhu rata-rata (`N35`) serta
rentangnya ikut berubah.

**Sikap sistem:** tiga suhu per ulangan sampai dijawab.

### 17. Status neraca selalu VALID

`DATABASE!AA20` dan `AA21` (Excellent, Fujitsu) berumus `=Z20-$Z$11` dan
`=Z21-$Z$11`. Sel `Z11` kosong, jadi hasilnya nomor seri tanggal (46406),
bukan sisa hari. `AA19` (Analytical Balance) berumus `=Z19+365`. Ketiganya
selalu jauh di atas 31, jadi `INPUT DATA!T24` selalu "VALID" walaupun neraca
lewat jatuh tempo. Baris termometer dan sensor (`AA22`, `AA23`) memakai
`$Z$17` (`NOW()`) dan benar.

**Pertanyaan:** Apakah rujukan yang benar `$Z$17`?

**Sikap sistem:** status standar diperiksa dari data standar di sistem, bukan
dari workbook. Sejak 8 Okt 2026 `CalibrationValidator` membandingkan
`berlaku_sampai` neraca, termometer & sensor standar, dan thermohygro sesi
Labu Ukur/Pipet Volume dengan **tanggal kalibrasi** sesi (selain dengan hari
ini) — kedaluwarsa = `standar_kadaluarsa` tingkat ERROR, sertifikat tidak bisa
terbit.

### 18. Termometer standar Yokogawa lewat jatuh tempo saat dipakai

`DATABASE!Z22` (Yokogawa CA 150, dari `STANDAR-YOKOGAWA!J3`) jatuh tempo
**12 Agustus 2026**. Ketujuh workbook acuan bertanggal kalibrasi
**6 Oktober 2026** (`INPUT DATA!Q16`), dan `INPUT DATA!K3` sendiri menulis
"ONE OR MORE STANDARD EXPIRED".

**Pertanyaan:** Apakah Yokogawa sudah dikalibrasi ulang dan workbook belum
diperbarui? Kalau belum, sertifikat yang terbit dari sesi itu memakai standar
kedaluwarsa.

**Sikap sistem (8 Okt 2026):** termometer standar ("Termometer & Sensor Std.",
23P1005) dan sensor PRT Pt-100 (SH1/20) diturunkan dari master standar lab untuk
SETIAP sesi Labu Ukur/Pipet Volume — tabel koreksi & U95-nya selalu dipakai
rumus. Kalau `berlaku_sampai` di master lebih awal dari tanggal kalibrasi, sesi
ditahan (`standar_kadaluarsa`, ERROR). Begitu sertifikat Yokogawa yang baru
dicatat di master standar, sesinya bisa terbit.

### 19. Berkas bernama "Pipet Ukur" berisi Pipet Volume

Empat berkas `Pipet Ukur PV 0,5/2/3/4-1-26.xlsm` memilih tipe alat 5
(`INPUT DATA!E6 = 5`, `Y15 = "Pipet Volume"`), seri PV, dan CMC dari
`CMC_pipetvolume`. Sistem memperlakukannya sebagai Pipet Volume.

**Pertanyaan:** Mohon konfirmasi alatnya Pipet Volume (bertanda satu). Kalau
ternyata Pipet Ukur, CMC dan keluarganya (Graduated) berbeda.

### 20. Keterulangan tidak masuk budget ketidakpastian (workbook Rev.7)

Budget Rev.7 hanya tujuh komponen (`PERHITUNGAN U95%!I36:I42`): massa, ρ udara,
ρ air, ρ anak timbangan, suhu air, koefisien muai, meniskus. Tidak ada komponen
keterulangan (Type A). Workbook Fixed lama punya baris kedelapan "Repeated
measurements" (stdev V20 ÷ √3, ν = 2).

**Pertanyaan:** Apakah penghapusan komponen keterulangan disengaja? GUM
menuntut komponen Type A masuk budget bila pengukuran diulang.

**Sikap sistem:** meniru workbook (keputusan pemilik 8 Okt 2026). U dengan
keterulangan tetap dihitung dan tercatat di jejak sesi
(`volumetric_rev7_keterulangan_tidak_masuk_budget`).

### 21. Format desimal cetak Pipet Volume tidak seragam

`SERTIFIKAT!E21/N21/S21` (Nominal, Actual, Correction):

| Workbook | Format |
|---|---|
| PV 0,5 | `0.000` |
| PV 2 | `0.00` |
| PV 3 | `0.000` |
| PV 4 | `0.0000` |

U95 (`Q22`) `0.0000` di keempatnya. Labu Ukur seragam: `0.00` dan U95 `0.000`.

**Pertanyaan:** Berapa desimal yang baku untuk Pipet Volume?

**Sikap sistem:** keputusan pemilik 8 Okt 2026 — ikut workbook PER NOMINAL:
0,5 mL → 3, 2 mL → 2, 3 mL → 3, 4 mL → 4, nominal lain → 3; U95 empat desimal.
Layar HP dan sertifikat membaca hook yang sama
(`PipetVolumeProfile::desimalSertifikatTitik()`).

### 22. Tekanan udara dicetak tanpa koreksi thermobarometer

`SERTIFIKAT!U11 = PERHITUNGAN!G16 + M16` (suhu terkoreksi) dan
`U12 = G17 + M17` (RH terkoreksi), tetapi `U13 = PERHITUNGAN!G19` — rata-rata
BACAAN tekanan tanpa koreksi Lutron (`M19` = +1 hPa). Di ketujuh workbook
tercetak 1001 hPa; dengan koreksi 1002 hPa. Densitas udara (`H57`) memang
memakai bacaan mentah ketiganya.

**Pertanyaan:** Apakah tekanan di sertifikat sengaja tanpa koreksi, atau
seharusnya `G19 + M19` seperti suhu dan RH?

**Sikap sistem:** meniru workbook — sertifikat Labu Ukur & Pipet Volume
mencetak rata-rata bacaan tekanan (format `0`) dengan ketidakpastian dari
sertifikat thermobarometer + pergeseran awal–akhir (format `0.0`).

---

## Koreksi atas dokumen analisis yang menyertai master

Untuk catatan, supaya tidak dipakai sebagai acuan:

- Tabel koefisien muai berisi **14** material, bukan 13.
- `ρ` anak timbangan Volumetric **7,95** g/mL, **tidak sama** dengan Hydrometer
  (8,0 g/mL).
- Rumus densitas air Volumetric dan Hydrometer **tidak setara** secara numerik
  (selisih hingga 5·10⁻⁶), meski keduanya mendekati besaran fisis yang sama.
- `#REF!` di sertifikat Graduated **tidak** menggantikan angka — hanya satuan
  dan simbol ±.

---

## Keputusan pemilik 8 Okt 2026 — Labu Ukur & Pipet Volume ikut workbook Rev.7

Workbook lab adalah acuan, sama seperti Anak Timbangan. Server disesuaikan
supaya olah data Labu Ukur dan Pipet Volume sama dengan tujuh workbook lab
template Fixed `SIDIK-IK-CAL-0510_Rev.7` (LU-200/250/500-1, PV 0,5/2/3/4-1-26).
Profil Volumetric lain (Picnometer, Buret, Gelas Ukur, Pipet Ukur) tidak
berubah.

| | Sebelumnya | Sekarang | Sel workbook Rev.7 |
|---|---|---|---|
| A. Komponen budget | 8, termasuk keterulangan | **7** | `PERHITUNGAN U95%!I36:I42` (pertanyaan 20) |
| B. ρ anak timbangan | 7,95 g/mL (u 0,795) | **8** (u 0,8) | `PERHITUNGAN!H58`, `PERHITUNGAN U95%!D39` |
| C. γ kelas A | 9,9·10⁻⁶ /°C | **10·10⁻⁶** | `PERHITUNGAN!H59` |
| D. ρ air ulangan 1 & 2 | suhu terukur | **`master`** (25,5 °C, ikut workbook) + peringatan bila suhu terukur menggeser angka cetak | `PERHITUNGAN!H40`/`J40` = 25,5 (pertanyaan 14) |
| E. Desimal cetak Labu Ukur | 4 / U95 4 | **2 / U95 3** | `SERTIFIKAT!N21/S21` `0.00`, `Q22` `0.000` |
| E. Desimal cetak Pipet Volume | 4 / U95 4 | **per nominal: 0,5→3, 2→2, 3→3, 4→4, lain 3 / U95 4** | `SERTIFIKAT!N21/S21` keempat workbook, `Q22` `0.0000` (pertanyaan 21) |

k tetap dicetak bulat (`SERTIFIKAT!V23` `0`). Pembanding K4 (pertanyaan 1)
tidak berlaku untuk Rev.7: `I45 = I44^4/K43` dan `K43` di Rev.7 adalah baris
SUM.

Dengan sakelar D = `master`, V20, deviasi, ketujuh u·c, u_c, ν_eff, k, U, dan U
cetak sama dengan cache Excel ketujuh workbook (selisih terbesar 5,5·10⁻¹³
relatif, di k; V20 dan deviasi identik). Dijaga
`tests/Unit/VolumetrikRev7WorkbookTest.php` dan
`tests/Feature/VolumetricGlasswareSesiTest.php`.
