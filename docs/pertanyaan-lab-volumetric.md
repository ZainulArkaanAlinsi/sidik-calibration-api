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

## Koreksi atas dokumen analisis yang menyertai master

Untuk catatan, supaya tidak dipakai sebagai acuan:

- Tabel koefisien muai berisi **14** material, bukan 13.
- `ρ` anak timbangan Volumetric **7,95** g/mL, **tidak sama** dengan Hydrometer
  (8,0 g/mL).
- Rumus densitas air Volumetric dan Hydrometer **tidak setara** secara numerik
  (selisih hingga 5·10⁻⁶), meski keduanya mendekati besaran fisis yang sama.
- `#REF!` di sertifikat Graduated **tidak** menggantikan angka — hanya satuan
  dan simbol ±.
