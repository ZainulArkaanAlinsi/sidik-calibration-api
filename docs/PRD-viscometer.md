# PRD — Kalibrasi Viscometer (alat ke-7)

| | |
|---|---|
| **Status** | Terbangun & terverifikasi di MySQL — 18 Agustus 2026 |
| **Tanggal** | 18 Agustus 2026 |
| **Jenis alat** | Viscometer rotasi (Brookfield), satuan cP |
| **Formulir lembar kerja** | `SIDIK-FM-CAL-0524_Rev.3` |
| **Metode kalibrasi (IK)** | `SIDIK-IK-CAL-0517_Rev.3` |
| **Formulir sertifikat** | `SIDIK-FM-CAL-2403_Rev.0` |
| **Ruang lingkup akreditasi** | LK-285-IDN no. 44 (Instrumen Analitik) |

---

## 1. Kenapa ini dibangun

Enam jenis alat sudah jalan penuh di backend: pH Meter, Turbidimeter, Chlorine
Meter, Refractometer, Conductivity Meter, dan Spectrophotometer. Viscometer
adalah alat ketujuh dan **belum ada satu baris kode pun** — yang ada baru satu
baris CMC di `database/data/kemampuan-kalibrasi.json` dan satu baris rekap di
`docs/Rekap-Data-Kemampuan-Kalibrasi.md`.

Sampai ini selesai, kalibrasi Viscometer masih dikerjakan manual di workbook
Excel: teknisi mengisi kertas, lalu seseorang mengetik ulang angkanya ke
`Master Olah Data_Viscometer.xlsm`. Dua kali pengetikan, dua kesempatan salah,
dan tidak ada jejak audit siapa mengubah apa.

Yang ingin dicapai:

1. Teknisi mengisi lembar kerja Viscometer langsung di HP, atau memotret lembar
   cetak dan angkanya terbaca otomatis.
2. Sistem menghitung sendiri nilai acuan terkoreksi suhu, budget ketidakpastian
   U95%, dan MPE — tanpa ada yang mengetik ulang.
3. Sertifikat terbit dengan angka yang bisa diadu baris per baris ke master lab.

## 2. Sumber data

Semua angka di dokumen ini berasal dari
`Project-PT-Sidik/Master_Olah_Data_Viscometer_CSV/`:

| Berkas | Isi yang dipakai |
|---|---|
| `INPUT DATA.csv` | Sesi contoh: identitas alat, 3 titik × 5 pengulangan (cP + °C), spindle, RPM, model |
| `PERHITUNGAN.csv` | Kondisi lingkungan terkoreksi, rata-rata, STDEV, interpolasi standar |
| `PERHITUNGAN U95%.csv` | Budget ketidakpastian 4 komponen per titik |
| `SERTIFIKAT.csv` | Bentuk sertifikat + blok pemeriksaan MPE |
| `MPE Visco.csv` | Tabel D-2 (TK, 12 model) & Tabel D-1 (SMC, 63 spindle) |
| `Tabel Pengaruh Temperature.csv` | Tabel sertifikat 3 larutan standar (suhu → cP → %U) |
| `DATABASE.csv` | Master standar kalibrator, thermohygro TH-1..TH-7, CMC, daftar IK |
| `FORM VALIDASI.csv` | Riwayat 18 revisi master — dipakai menentukan mana yang terbaru |
| `SIDIK-FM-CAL-0524_Rev.3 … .pdf` | Bentuk kertas yang diisi teknisi |

Berkas `.xlsm` aslinya **tidak ada** di folder. Akibatnya format sel — yang di
alat-alat sebelumnya menentukan berapa desimal yang tercetak — tidak bisa
dibaca. Lihat bagian 9.4.

## 3. Ruang lingkup

**Termasuk**

- Tiga titik kalibrasi: larutan standar 100 cP, 1000 cP, dan 60000 cP.
- Dua tahap pembacaan: *Before Adjustment* dan *After Adjustment*.
- Lima kolom pengulangan per titik, masing-masing mencatat pembacaan (cP) **dan**
  suhu larutan (°C).
- Koreksi kondisi lingkungan lewat thermohygro TH-1..TH-7.
- Budget ketidakpastian GUM penuh + lantai CMC.
- Perhitungan MPE dan vonis PASS/FAIL.
- Lembar kerja siap pindai + template OCR.

**Tidak termasuk**

- Blok larutan standar **30000 cP**. Blok budgetnya di `PERHITUNGAN U95%.csv`
  berisi `#DIV/0!` di seluruh baris — sumber angkanya sudah hilang dari workbook
  itu sendiri. Ditandai `sumber_belum_ada` di lembar kerja, tidak menerima input,
  dan tidak pernah masuk hitungan. Perlakuan yang sama persis dengan blok SRE di
  Spectrophotometer.
- Kolom densitas larutan. Ada di tabel sertifikat standar, tapi tidak dipakai
  satu sel pun di jalur hitung master.
- Konversi satuan dPas / P ke cP di layar. Master menyediakan tabelnya
  (`DATABASE.csv`: dPas ×100, cP ×1, P ×100) tapi sesi contohnya cP; konversi
  disiapkan di data, tidak di UI, sampai ada permintaan nyata.

---

## 4. Spesifikasi hitung

Seluruh rumus di bawah **sudah dihitung ulang dan cocok dengan sel master sampai
belasan desimal**. Ini spesifikasi, bukan perkiraan.

### 4.1 Nilai acuan standar — interpolasi linier terhadap suhu

Viskositas larutan standar berubah tajam mengikuti suhu (larutan 60000 cP: 95192
cP pada 20 °C, 19259 cP pada 37,78 °C — turun 80 % dalam 18 °C). Karena itu nilai
acuan yang dipakai **bukan angka nominal di botol**, melainkan nilai larutan pada
suhu saat titik itu diukur.

```
nilai_acuan = interpolasi_linier(tabel_sertifikat_larutan, rata_rata_suhu_titik)
```

Interpolasi dilakukan antara dua baris tabel yang mengapit suhu terukur.

| Larutan | Baris pengapit | Suhu rata-rata | Nilai acuan | Sel master |
|---|---|---|---|---|
| 100 cP | 25 °C→99,65 · 37,78 °C→51,1 | 26,52 °C | 93,87566510172147 | cocok |
| 1000 cP | 25 °C→1018 · 37,78 °C→419,5 | 27,3 °C | 910,2887323943662 | cocok |
| 60000 cP | 20 °C→95192 · 25 °C→59003 | 24,6 °C | 61898,119999999995 | cocok |

> **Jangan pakai persamaan kubiknya.** `Tabel Pengaruh Temperature.csv` mencetak
> trendline `y = -0,0007x³ + 0,1522x² - 11,693x + 313,28` di bawah tabel larutan
> 100 cP. Itu hiasan grafik, bukan rumus yang dipakai: pada 26,52 °C hasilnya
> 97,16 cP, sementara master memakai 93,88 cP. Dua angka ini beda 3,5 % — cukup
> untuk membalik vonis MPE.

### 4.2 Rata-rata, koreksi, dan STDEV

- Rata-rata pembacaan dan rata-rata suhu dihitung dari kolom pengulangan yang
  **terisi**.
- `Correction` yang dicetak di sertifikat = `nilai_acuan − rata_rata_UUT`
  (bertanda; ketiga titik sesi contoh negatif).
- STDEV = standar deviasi sampel (pembagi `n−1`), dari pembacaan mentah.

### 4.3 Budget ketidakpastian — empat komponen per titik

| # | Komponen | Distribusi | `U` | Pembagi | `vi` | `ci` |
|---|---|---|---|---|---|---|
| 1 | Ketidakpastian baku sertifikat kalibrator | normal | `%U × nilai@25 °C` | 2 | 200 | 1 |
| 2 | Daya baca alat yang dikalibrasi | rect. | `resolusi / 2` | √3 | 10⁶ | 1 |
| 3 | Pengaruh temperature | rect. | `U_temp` | √3 | 50 | `nilai@25 °C × U_temp / 400` |
| 4 | Pengulangan pembacaan | t-student | `STDEV` | √n | `n − 1` | 1 |

dengan

```
U_temp = √( (U95_termometer / k)² + (U95_sensor / k)² )
       = √( (0,72/2)² + (0,06/2)² )
       = 0,36124783736376886  °C
```

Termometer Yokogawa CA 150 Handy Cal S/N 23P1005 + sensor PT100/SH1
(`DATABASE.csv` baris 16–17). Angka ini **sama persis** dengan yang dipakai
Turbidimeter dan Chlorine, jadi konsisten lintas alat.

`%U` dibaca dari kolom `Uncertainty %` baris **25 °C** tabel larutan:

| Larutan | `%U` @25 °C | nilai @25 °C | `U95` kalibrator |
|---|---|---|---|
| 100 cP | 0,17 % | 99,65 cP | 0,169405 cP |
| 1000 cP | 0,23 % | 1018 cP | 2,3414 cP |
| 60000 cP | 0,23 % | 59003 cP | 135,7069 cP |

> **Komponen 1 dan 3 memakai nilai NOMINAL @25 °C, bukan nilai hasil
> interpolasi.** Ini sudah diuji dua-duanya; hanya nominal yang mereproduksi
> angka master. `INPUT DATA.csv` pun menuliskannya eksplisit sebagai
> "Index nilai 100cP : 25" dan "Value visco on 100cP : 99,65".

Agregasi memakai mesin GUM bersama yang sudah ada
(`GumCalculator::agregasiBudget()`) — tidak ada yang baru:

```
uc   = √Σ(u·ci)²
veff = uc⁴ / Σ((u·ci)⁴ / vi)          (Welch–Satterthwaite)
k    = t-student(97,5 %, floor(veff))
U    = k · uc
U95  = max(U, CMC)                    (lantai CMC)
```

Hasilnya, diadu ke master:

| Titik | `uc` | `veff` | master `k` | master `U` | CMC |
|---|---|---|---|---|---|
| 93,88 cP | 0,24649576970552553 | 5,376144439333908 | 2 | 0,49299153941105106 | 0,2 cP |
| 910,29 cP | 1,356001576327294 | 60,620109476367105 | 2 | 2,712003152654588 | 2,1 cP |
| 61898,12 cP | 72,05656247619179 | 168,2349490679152 | 1,9754000802500271 | 142,34053929801036 | 140 cP |

`uc` dan `veff` cocok persis di ketiga titik. `k` titik pertama tidak — lihat 9.2.

### 4.4 MPE dan vonis PASS/FAIL

Viscometer punya batas keberterimaan, dan batas itu **dihitung per titik**, bukan
diambil dari satu kolom `toleransi` di master alat:

```
Fullscale = TK × SMC × 10000 / RPM
MPE       = 1 % × Fullscale  +  1 % × rata_rata_UUT
```

- `TK` (*Torque Constant*) — dari model alat, Tabel D-2. Satu nilai per sesi.
  Contoh: model badan `DV2THA`, layar `HA` → TK = 2.
- `SMC` (*Spindle Multiplier Constant*) — dari spindle yang dipakai, Tabel D-1.
  **Berbeda per titik**: HA1 → 1, HA2 → 4, HA7 → 400.
- `RPM` — kecepatan putar, **berbeda per titik** (63 / 62 / 62 di sesi contoh).

| Titik | Spindle | RPM | Fullscale (cP) | MPE (cP) | \|Correction\| | Vonis |
|---|---|---|---|---|---|---|
| 100 cP | HA1 (SMC 1) | 63 | 317,46031746031747 | 4,141803174603175 | 2,844 | PASS |
| 1000 cP | HA2 (SMC 4) | 62 | 1290,3225806451612 | 22,079825806451613 | 7,371 | PASS |
| 60000 cP | HA7 (SMC 400) | 62 | 129032,25806451612 | 1921,8410806451611 | 1253,730 | PASS |

Ketiganya cocok persis dengan blok "MPE Check for Viscometer" di
`SERTIFIKAT.csv`.

Vonis memakai *guarded acceptance* (ILAC-G8) yang sudah berlaku untuk enam alat
lain: `|error| + U95 ≤ MPE`. Untuk sesi contoh ketiga titik PASS baik dengan
aturan ini maupun dengan `|error| ≤ MPE` saja, jadi tidak ada konflik dengan
master.

### 4.5 Kondisi lingkungan

Tidak ada yang baru. `KondisiLingkungan` yang sudah ada mereproduksi master
persis:

| | Awal | Akhir | Rata-rata | Koreksi TH-2 | Terkoreksi | U95% |
|---|---|---|---|---|---|---|
| Suhu | 25,2 | 25,3 | 25,25 | −0,23 | **25,02 °C** | **1,70293863659264 °C** |
| Kelembaban | 57 | 58 | 57,5 | −1 | **56,5 %RH** | **4,903060268852505 %RH** |

Titik koreksi dipilih dari baris sertifikat thermohygro yang *Instrument
Indication*-nya paling dekat ke rata-rata — logika yang sudah ada di
`Standard::parameterKondisi()`.

---

## 5. Lembar kerja teknisi

Bentuknya meniru cetakan `SIDIK-FM-CAL-0524_Rev.3` (A4 tegak, satu halaman),
supaya teknisi yang mengisi sambil memegang kertas melihat urutan yang sama di
layar.

### 5.1 Kepala lembar

Kolom kiri: Customer · Address · Calibration Date · Location of Calibration ·
T awal (°C) · T akhir (°C) · RH awal (%RH) · RH akhir (%RH) · Thermohygro used.

Kolom kanan: Equipment Name · Manufacturer · Type · SN · Range · Resolution ·
Methode (terisi `SIDIK-IK-CAL-0517`, tidak diisi teknisi).

Pilihan thermohygro persis seperti tercetak — Insitu: TH-2, TH-6, TH-7;
Inlab: TH-4. Unit lain (TH-1, TH-3, TH-5) tetap boleh dipilih tapi ditandai
`di_kertas: false`; mempersempit daftar pernah membuat tiga sertifikat meleset
karena teknisi terpaksa memilih unit yang bukan dibawanya.

### 5.2 Tabel hasil

Enam blok: **3 larutan standar × 2 tahap** (Before / After Adjustment). Bentuk
tiap blok, mengikuti kertas:

```
Standard ( ......... )      UUT Reading ( ......... )
                            ┌──────┬──────┬──────┬──────┬──────┐
                            │  1   │  2   │  3   │  4   │  5   │
                     cP     ├──────┼──────┼──────┼──────┼──────┤
                            │      │      │      │      │      │
                     °C     ├──────┼──────┼──────┼──────┼──────┤
                            │      │      │      │      │      │
                            └──────┴──────┴──────┴──────┴──────┘
Spindle: ( ....... )   RPM: ( ....... )   Resolusi UUT: ( ....... )
```

Catatan bentuk:

- **Resolusi ditulis per titik**, bukan sekali di kepala lembar. Kertasnya
  eksplisit: *"\* Resolusi tuliskan pada masing-masing titik kalibrasi"*.
- Kotak **Spindle** dan **RPM** per titik adalah **tambahan kita** — lihat 9.5.
  Ketiganya (spindle, RPM, resolusi) ikut tercetak sebagai garis isian di blok
  kepala, tapi bukan sel pindai: kode spindle bentuknya `HA7` /
  `CPE-51 or CPA-51Z`, sementara pembaca angka murni digit.
- Setiap sel angka punya nilai nominal barisnya sendiri (99,65 / 1018 / 59003),
  dipakai penjaga OCR di bagian 6.

### 5.5 Lembar siap pindai berorientasi LANSKAP

Satu-satunya dari tujuh alat, dan alasannya satu angka. Baris 60000 cP menulis
tujuh karakter (`63181.3`). Di grid potret standar rumah (124 px @200 dpi) itu
15,7 mm — **2,2 mm per digit tulisan tangan**, dan itu sumber salah baca kamera
terbesar di lembar ini.

| | Potret (standar 6 alat lain) | Lanskap (Viscometer) |
|---|---|---|
| Ukuran referensi | 1654 × 2339 | **2339 × 1654** |
| Kotak pembacaan | 124 px = 15,7 mm | **230 px = 29,2 mm** |
| Kotak suhu | 124 px = 15,7 mm | 138 px = 17,5 mm |
| Per digit (7 karakter) | 2,2 mm | **4,2 mm** |

Kotak suhu sengaja lebih sempit — isinya cuma empat karakter (`24.6`), dan lebar
yang dihemat dari situ yang dipakai kotak pembacaan. Lebar per Repeat tetap
368 px, jadi kotak jangkar `X1..X5` tidak bergeser.

Perubahan ini membongkar satu bug lama di `TataLetakLembar`: konstanta
**tipografi** (lebar label, jarak antar baris, tinggi sel maksimum) ikut
diskalakan mengikuti ukuran kertas, padahal semua lembar dicetak di 200 dpi
dengan font 8 pt yang sama. Di lanskap kotak label melar 41 % sementara jarak
barisnya menyusut 29 %, dan blok isian identitas meluber menimpa judul tabel.
Sekarang konstanta halaman dan konstanta tipografi dipisah; A4 potret faktor
skalanya persis 1,0, jadi enam geometri yang sudah terverifikasi **byte-identik**
setelah diregenerasi.

### 5.3 Pilihan model dan spindle

Kertas menyediakan dua daftar untuk dicentang/dilingkari: 12 model (Tabel D-2,
menentukan TK) dan 63 spindle (Tabel D-1, menentukan SMC). Di layar keduanya
jadi **daftar pilihan, bukan isian bebas** — TK dan SMC masuk langsung ke rumus
MPE, jadi satu salah ketik menggeser Fullscale sampai ratusan kali lipat
(SMC berkisar 0,327 sampai 1280).

### 5.4 Prinsip pengisian

- Semua kolom boleh dikosongkan; lembar tetap bisa dikirim. Titik yang kosong
  tidak dihitung, dan alasannya dilaporkan — bukan hilang diam-diam.
- Jumlah kolom pengulangan bawaan 5, bisa diturunkan sampai 2 lewat mekanisme
  `setelKolomPengulangan()` yang sudah ada. Rumus selalu mengikuti berapa kotak
  yang benar-benar terisi.

---

## 6. Akurasi pembacaan foto (OCR)

Ini permintaan utama, dan Viscometer memang lembar paling rawan dari ketujuh
alat: **satu lembar memuat angka yang bedanya tiga orde magnitudo** (96 cP,
918 cP, 63181 cP). Aturan berikut yang harus dipenuhi.

### 6.1 Nominal per baris, bukan per lembar

Penjaga `magnitudo_meleset` (`ValidasiSel`) menolak angka yang rasionya ke
nominal di luar pita yang berlaku. Untuk Viscometer nominal itu **wajib diambil
dari baris**, bukan dari lembar: kalau dipukul rata, pembacaan 63181 cP di baris
60000 akan dinilai terhadap nominal 99,65 dan seluruh baris ketiga ditolak.

### 6.1b Pitanya dari jangkauan larutan, BUKAN nominal ±10 %

Aturan umum `TemplateLembarKerja::aturanPembacaan()` memberi pita `nominal
±10 %`. Itu benar untuk enam alat pertama karena nilai acuannya diam — buffer
pH 7 selalu ~7. Viscometer tidak, dan pita bawaan itu **menolak angka yang
benar**:

| Baris | Pita bawaan (nominal ±10 %) | Pembacaan master terkecil |
|---|---|---|
| 1000 cP | 916,2 – 1119,8 | **916,3** |

Selisihnya 0,1 cP. Sesi master lolos karena kebetulan diukur pada 27,3 °C; sesi
yang sama pada 30 °C akan ditolak seluruh barisnya, dengan pesan yang terbaca
seperti kamera gagal padahal angkanya benar. Pada 37,78 °C larutan itu 419,5 cP
— jauh di luar pita.

`ViscometerProfile::pitaPembacaan()` menggantinya dengan jangkauan tabel
sertifikat larutan pada suhu kerja 20–37,78 °C, dilonggarkan 20 % untuk alat
yang memang meleset:

| Baris | Nominal | Pita |
|---|---|---|
| 100 cP | 99,65 | 42,58 – 160,80 |
| 1000 cP | 1018 | 349,58 – 1804,80 |
| 60000 cP | 59003 | 16049,17 – 114230,40 |

Penjaga rasio ikut dilonggarkan ke 0,25–2,0×, karena batas bawah baris ketiga
(16049 cP) itu 0,27× nominalnya. Geseran titik desimal tetap tertangkap:
631813 itu 10,7× dan 6318 itu 0,107×.

### 6.2 Titik desimal ganda harus DITOLAK, bukan ditebak

Master lab sendiri memuat contohnya: sel pengulangan ke-5 titik 60000 cP berisi
teks `631.74.2` — dua titik desimal. Excel diam-diam melewatkannya, dan itulah
sebabnya rata-rata di master dihitung dari 4 angka, bukan 5.

Perilaku yang diwajibkan: `631.74.2` → **MERAH** (teknisi mengetik ulang). Bukan
`631,74`, bukan `63174,2`. Menebak salah satu berarti memasukkan angka karangan
ke dokumen terakreditasi. Dijamin test.

### 6.3 Ribuan vs desimal harus jatuh ke ambigu

`63.181` bisa dibaca `63181` atau `63,181`. Kalau bukti dari resolusi dan pita
nominal tidak bisa menyingkirkan salah satunya, hasilnya `desimal_ambigu` →
MERAH. Tidak ada tebakan diam-diam.

### 6.4 Kolom suhu

24–27 °C, masuk rentang wajar bawaan (5–45 °C) apa adanya. Resolusi 0,1 °C.

### 6.5 Bentuk kertas harus dinyatakan benar

`bentukPindaiFoto()` menentukan prompt dan skema JSON yang dikirim ke model
pembaca foto. Salah menyatakan bentuk berarti model diminta membaca kolom yang
tidak ada di kertasnya — dan gagalnya tidak kelihatan sebagai error, hanya
sebagai "gagal baca, isi manual". Untuk Viscometer: **ada kolom suhu** di setiap
sel, dan standarnya berdiri sebagai judul blok.

### 6.6 Batas yang tidak digeser

Ambang hijau/kuning, penalti substitusi karakter, cek kemiringan/blur/glare, dan
uji sebaran antar-pengulangan memakai setelan bersama di `config/ocr.php`. Tidak
ada satu pun yang dilonggarkan untuk Viscometer. Selama template belum diadu ke
foto kertas asli, berkasnya tetap `terverifikasi: false` dan seluruh hasil
pindai wajib lewat layar review.

---

## 7. Sertifikat

Kolom tabel `CALIBRATION REPORT`, mengikuti `SERTIFIKAT.csv`:

| Standard Value (cP) | Unit Under Test (cP) | Correction (cP) | U95%, k=2 (cP) |
|---|---|---|---|

Di luar tabel, sertifikat mencetak: `Spindel No.` (mis. `1,2,7`), `Speed (rpm)`
(mis. `63,62,62`), `Env. Condition` (T dan RH terkoreksi ± U95), daftar standar
yang dipakai lengkap dengan merk/tipe, serial, dan ketertelusuran, serta
`Calibration Method : SIDIK-IK-CAL-0517_Rev.3`.

Blok MPE di master berada di area bertanda "DON'T ERASE !!!" — itu blok bantu
hitung, bukan bagian badan sertifikat. MPE tetap dihitung dan disimpan (dipakai
vonis PASS/FAIL dan jejak audit), tapi tidak menambah kolom baru di sertifikat.

---

## 8. Data dan skema

| Tempat | Yang disimpan |
|---|---|
| `standards.koefisien_suhu` | Tabel sertifikat larutan (9 baris: suhu → cP → %U) |
| `standards.ketidakpastian` | `U95` kalibrator @25 °C, satuan cP, k=2 |
| `raw_measurements.pembacaan` / `.suhu` | Pembacaan cP dan suhu °C per pengulangan (sudah ada) |
| `raw_measurements.spindle` / `.rpm` | **Kolom baru** — SMC dan RPM milik titik, dipakai MPE |
| `calibration_sessions.spesifikasi_alat` | Model badan & layar (menentukan TK), rentang, resolusi per titik |
| `calibration_capabilities` | 3 baris CMC Viscometer + `u_temperature` |
| `uncertainty_calculations` | Seluruh hasil hitung, termasuk `toleransi` (= MPE) dan `keputusan` |

CMC diseed sebagai **rentang kontinyu**, bukan titik tunggal, dan barisnya
**empat**, bukan tiga:

| Parameter | Rentang (cP) | CMC |
|---|---|---|
| Viskositas — Std 100 cP | 51,1 – 102 | 0,2 cP |
| Viskositas — Std 1000 cP | 419,5 – 1028 | 2,1 cP |
| Viskositas — Std 60000 cP | 19259 – 58021 | 140 cP |
| Viskositas — Std 60000 cP (di luar lingkup KAN) | 58021 – 95192 | **0** |

Kenapa rentang, bukan titik tunggal: nilai acuan bergeser mengikuti suhu, jadi
titik ukurnya tidak pernah berupa satu angka tetap. Kalau diseed sebagai titik
tunggal 102 / 1028 / 58021 cP, pencocokan CMC **tidak akan pernah ketemu** —
ambang gesernya `max(0,1 ; 0,5 % × titik)`, sementara jarak 93,88 ke 102 adalah
8,12. Akibatnya seluruh titik jatuh ke jalur generik dan lantai CMC tidak pernah
dipakai.

Kenapa batas ATASNYA ikut lampiran KAN (102 / 1028 / 58021 cP) dan bukan
jangkauan tabel larutan (134 / 1504 / 95192 cP): lab tidak boleh mengklaim CMC
di luar ruang lingkup yang diakreditasi. Batas bawahnya tetap dari tabel larutan
supaya dua titik pertama tetap dapat lantainya.

Konsekuensinya nyata dan diterima: titik ketiga sesi master jatuh di 61898,12 cP
— **di atas** 58021 cP — jadi tidak dapat lantai CMC, dan `U95`-nya dilaporkan
apa adanya (144,16 cP, bukan 140).

Baris keempat ada karena memotong rentang saja **tidak cukup**, dan ini lubang
yang baru kelihatan waktu dijalankan: `GumCalculator::hitungTitik()` hanya
memanggil `komponenBudget()` kalau ada baris kemampuan yang cocok. Titik yang
tidak menemukan baris apa pun jatuh ke `hitungDariStandarDanResolusi()` — jalur
cadangan dua komponen yang **membuang pengaruh suhu**. `uc`-nya mengecil dari
72,858 ke 72,005 dan `veff`-nya melonjak ke 239; sertifikatnya mengklaim
ketidakpastian lebih baik dari yang bisa dibuktikan, tanpa satu pun error.
Baris keempat dengan `ketidakpastian_terbaik = 0` (kolomnya `NOT NULL`, jadi nol
yang jadi penanda "tidak ada klaim") membuat budget tetap empat komponen
sementara lantainya tetap tidak berlaku.

---

## 9. Selisih antara master dan sistem

Tujuh hal di bawah dicatat, tidak satu pun dibetulkan sepihak. Yang masih butuh
jawaban lab (9.2, 9.3, 9.4) dikumpulkan di `docs/pertanyaan-lab-viscometer.md`;
sisanya sudah punya jawaban dan ditulis di sini supaya tidak ditanyakan dua kali.

### 9.1 `ci` pengaruh suhu = `nilai@25 × U_temp / 400` — BUKAN kejanggalan

Waktu pertama dibaca, pembagi 400 ini kelihatan mencurigakan: koefisien
sensitivitas seharusnya tidak bergantung pada ketidakpastian termometernya
sendiri, dan 400 kebetulan sama dengan SMC spindle HA7.

Ternyata bukan. Rumus yang sama sudah dipakai dua alat lain dan sudah diadu ke
master masing-masing:

| Alat | Kode | `ci` |
|---|---|---|
| Turbidimeter | `TurbidimeterProfile.php:194` | `(U_temp / 400) × titik` |
| Chlorine Meter | `ChlorineProfile.php:302` | `(U_temp / 400) × titik` |
| Viscometer | (baru) | `(U_temp / 400) × nilai@25 °C` |

Jadi ini **konvensi rumah lab**, bukan sel rusak. Yang berbeda cuma "titik"-nya:
standar turbidity dan chlorine tidak punya kurva suhu, jadi titik ukurnya sama
dengan nominalnya. Standar viskositas punya, jadi harus dinyatakan eksplisit
bahwa yang dipakai **nominal @25 °C**, bukan nilai hasil interpolasi — dan itu
yang cocok dengan master.

Satu-satunya yang berbeda dari dua alat lain: derajat kebebasan komponen suhu.
Master Viscometer memakai `vi = 50`, Turbidimeter dan Chlorine `vi = 200`.
Angka 50 yang dipakai, karena itu yang mereproduksi `veff` master persis di
ketiga titik.

### 9.2 `k` titik 100 cP: master 2, GUM 2,5706

`veff` titik pertama 5,376. Tabel t-student pada 95 % dua sisi memberi
`t(0,975 ; 5) = 2,5706`, sementara master mencetak `k = 2`.

Master pH lab **sendiri** memakai t-student (`k = 2,77645` untuk `veff = 4,92`),
jadi sel `k` viscometer inilah yang menyimpang, bukan mesin kita. Workbook
viscometer ini juga lembar trial: `Certificate Number` dan `Order Number`-nya
kosong, jadi tidak ada sertifikat terbit yang perlu direproduksi.

**Keputusan: ikut mesin GUM.** Konsekuensinya `U95` titik 100 cP menjadi
**0,6336 cP**, bukan 0,49299 cP. Dua titik lain tidak berubah berarti
(2,7124 vs 2,7120; 142,25 vs 142,34).

### 9.3 Pembacaan ke-5 titik 60000 cP rusak

Sel berisi teks `631.74.2` — muncul di `INPUT DATA` maupun `PERHITUNGAN`, di
tahap *before* maupun *after*. Excel melewatkannya saat `AVERAGE`/`STDEV`
(rata-ratanya 63151,85 = rata-rata **4** angka) tapi pembaginya tetap dipatok
`√5` dengan `vi = 4`. Dua hal itu tidak bisa benar sekaligus.

**Keputusan: seed 4 pembacaan (n = 4), sel rusak dibuang.** Konsisten, dan
`U95` menjadi 144,16 cP (master 142,34). Ditanyakan ke lab: angka sebenarnya
berapa.

### 9.4 Desimal cetak sertifikat

Berkas `.xlsm` tidak tersedia, jadi format sel tidak terbaca.

**Keputusan: dua desimal**, ikut aturan umum enam alat lain untuk alat
beresolusi 0,1 cP → `93,88` / `910,29` / `61898,12` cP. Yang dibulatkan hanya
bentuk cetaknya; seluruh rantai hitung dan seluruh isi
`uncertainty_calculations` tetap presisi penuh. Satu konstanta,
`ViscometerProfile::DESIMAL_SERTIFIKAT`.

Draf pertama dokumen ini mengusulkan cetak presisi penuh (`93,87566510172147`)
dengan alasan "tidak mengarang bentuk yang kelihatan resmi". Itu salah arah:
angka empat belas desimal di dokumen terakreditasi mengklaim ketelitian yang
alatnya sendiri tidak punya — resolusinya 0,1 cP. Yang jujur bukan mencetak
semua digit, tapi mencetak sebanyak yang bisa dipertanggungjawabkan.

Begitu lab mengirim `.xlsm` atau satu sertifikat viscometer yang sudah tercetak,
yang diganti cukup satu angka konstanta — rumusnya tidak tersentuh. Diminta di
`docs/pertanyaan-lab-viscometer.md` §5.

### 9.5 Spindle dan RPM tidak ada kotaknya di Rev.3

Cetakan Rev.3 hanya punya daftar spindle global untuk dilingkari dan checklist
model. Itu tidak cukup: MPE butuh SMC **dan** RPM per titik, dan sesi contoh
memang memakai tiga spindle berbeda dengan dua RPM berbeda.

**Keputusan: lembar siap pindai yang kita hasilkan menambah tiga garis isian
per titik** — Spindle, RPM, dan Resolusi UUT — di blok kepala lembar. Cetakan
resmi Rev.3 tidak diubah; bedanya dicatat eksplisit di profil dan di
`_catatan_formulir` template OCR.

Ketiganya **bukan sel pindai**. Kode spindle bentuknya `HA7` /
`CPE-51 or CPA-51Z`; pembaca angka di jalur pindai murni digit, jadi sel semacam
itu akan selalu merah dan tiap lembar ditolak sebelum satu angka pun dipetakan.
Diisi di layar dari daftar pilihan tertutup (63 spindle, 12 model).

Konsekuensi yang harus ikut dibereskan: alat ini `equipments.toleransi`-nya
`NULL` karena batasnya lahir per titik — dan penjaga
`CalibrationController::alasanBelumBisaDihitung()` menolak setiap alat yang
kolom itu kosong. Tanpa perbaikan, **tidak ada satu pun sesi Viscometer yang
bisa dihitung lewat API**: sesinya tersimpan, pengukurannya tersimpan, nol titik
dihitung, tanpa error. Penjaga itu sekarang bertanya ke profil dulu
(`CalibrationProfile::toleransiDariKolomAlat()`).

### 9.6 Larutan standar mana yang berlaku

Cetakan Rev.3 menulis "Larutan Std Visco 100 cP" **dua kali**, lalu 30000 cP dan
60000 cP. Master, `DATABASE.csv`, dan lampiran KAN semuanya menyebut
**100 / 1000 / 60000 cP**, dan `FORM VALIDASI.csv` revisi 18 (18 Mei 2026)
menyatakan eksplisit "Update Standard Viscometer 1000 cP".

**Keputusan: ikut master.** Kertas Rev.3 ketinggalan satu revisi standar.

### 9.7 Satuan CMC titik ketiga

`kemampuan-kalibrasi.json` menulis `1.4` satuan `P` (Poise); `DATABASE.csv`
menulis `140` satuan cP. `1 P = 100 cP`, jadi angkanya sama. Yang dipakai
**140 cP** supaya sebanding dengan kolom lain di baris yang sama.

---

## 10. Kriteria selesai

| # | Kriteria | Status |
|---|---|---|
| 1 | Migrasi & seeder jalan bersih **di MySQL** (`asmo_db`), bukan hanya SQLite | ✅ |
| 2 | Sesi contoh mereproduksi ketiga titik dan diadu ke `SERTIFIKAT.csv` + `PERHITUNGAN U95%.csv`; tiga selisih di-assert **eksplisit sebagai selisih** | ✅ |
| 3 | Lembar kerja terbaca lewat API, bentuknya sesuai bagian 5 | ✅ `ViscometerApiTest` |
| 4 | `ocr:cetak-lembar viscometer --versi=1` menghasilkan PDF satu halaman yang setiap kunci selnya dikenali server | ✅ `CetakLembarKerjaOcrTest` (62 test, 7 alat) |
| 5 | `631.74.2` ditolak, `63.181` dibaca lewat bukti, angka nyasar baris ditolak | ✅ `PindaiViscometerTest` (15 test) |
| 6 | Suite penuh hijau — terutama jalur pH, karena `Standard::nilaiPadaSuhu()` dipakai bersama | ✅ 1058 test |
| 7 | Tiga dokumen serah terima terisi, contoh JSON dari `tinker` di DB nyata | ✅ |

Selisih dari master yang disengaja, ketiganya di-assert sebagai selisih:

1. `k` titik 100 cP — t-student 2,5706, master menulis 2 (§9.2)
2. `n = 4` di titik 60000 cP — sel ke-5 master rusak (§9.3)
3. Titik 3 tanpa lantai CMC — di luar batas ruang lingkup 58021 cP (§8)

`uc` dan `veff` cocok persis dengan master di ketiga titik; MPE cocok persis di
ketiganya.

## 11. Risiko

| Risiko | Dampak | Penanganan |
|---|---|---|
| `vi` komponen suhu 50 (Viscometer) vs 200 (Turbidimeter/Chlorine) | `veff` dan `k` bergeser | Angka 50 dipakai karena mereproduksi master; ditulis sebagai satu konstanta di profil |
| Template OCR belum diadu ke foto kertas asli | Angka mendarat di sel yang salah | `terverifikasi: false`; hasil pindai wajib lewat review manual |
| Titik 61898 cP di luar batas lingkup 58021 cP | Satu titik sertifikat tanpa klaim CMC | Batas atas rentang dipatok ke lampiran; titiknya tetap dihitung penuh dan `U95`-nya dilaporkan apa adanya. Ditanyakan ke lab (§4 dokumen pertanyaan) |
| Desimal cetak belum dipastikan | Sertifikat berbeda bentuk dari dokumen lab | Dua desimal (ikut aturan umum resolusi 0,1 cP), satu konstanta, mudah diganti; `.xlsm` diminta ke lab |
| Lembar lanskap, beda dari enam alat lain | Teknisi & operator cetak bisa keliru orientasi | Ukuran kertas dibaca dari `ukuran_referensi` di template, bukan diasumsikan; alasannya ditulis di `_catatan_formulir` |
| Master hanya punya satu sesi contoh | Kasus tepi (suhu di luar 20–37,78 °C) belum teruji | Suhu di luar jangkauan tabel ditolak dengan alasan terbaca, bukan diekstrapolasi |
