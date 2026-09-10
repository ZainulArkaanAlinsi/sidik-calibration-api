# Pertanyaan lab — Flowmeter Gravimetri (ISO 4185)

Dari pembedahan dua workbook master varian metode kedua alat ke-27 & ke-28:

- `1.2 Master olda Flowmeter Totalizer dini (2026) 140-2500L.xlsm`
- `2.1 Master olda Flowmeter Flowrate 100-980lpm 2026.xlsm`

Keduanya password `spirit285`, dan keduanya **sudah divalidasi**: `FORM VALIDASI`
baris terakhir 21 Mei 2026, PIC `NR`, CHECK `AM`, **VALIDATION `AM`**. Itu yang
membedakannya dari master UFM (`docs/pertanyaan-lab-flowmeter.md`) yang kolom
VALIDATION-nya kosong — dan yang membuat butir-butir di bawah lebih mendesak,
bukan kurang.

Urutannya menurut **konsekuensi**, bukan kemudahan. Yang paling atas menyangkut
dokumen yang sudah di tangan pelanggan.

> **Batas yang tidak dilanggar dokumen ini:** menarik atau menerbitkan ulang
> sertifikat adalah tindakan formal manajer teknis di bawah klausa Pekerjaan
> Tidak Sesuai ISO/IEC 17025. Yang disiapkan di sini rekomendasi, angkanya, dan
> alat pelingkup arsipnya — tanda tangannya bukan urusan perangkat lunak.

---

## §1 — Empat titik terbit DI BAWAH pita CMC, di master yang sudah divalidasi

**PRIORITAS SATU.**

Sel berlabel `CMC` ada di keempat blok budget (`PERHITUNGAN U95%!J35`, `J55`,
`J75`, `J95`) dan **semuanya kosong**. Tabel CMC-nya sendiri lengkap di
`DATABASE!R5:S6` dan cocok persis dengan lampiran akreditasi LK-285-IDN.

Yang terbit di sertifikat contoh:

| Titik | Volume | U95 tercetak | %OR | Pita CMC | Status |
|---|---|---|---|---|---|
| 1 | 140,53 L | 1,06076 L | **0,7576 %** | 78–1991 L → 1,2 % | di bawah lantai |
| 2 | 501,72 L | 1,05570 L | **0,2104 %** | 1,2 % | di bawah lantai |
| 3 | 1003,36 L | 1,05965 L | **0,1056 %** | 1,2 % | di bawah lantai |
| 4 | 2498,16 L | 2,11949 L | 0,0848 % | **> 1991 L** | di luar pita |

Titik 3 mengklaim ketidakpastian **sebelas kali lebih baik** dari yang diakui KAN.

**Yang sudah dikerjakan server:** lantai dipasang,
`U95 = MAX(U · ρ_air; CMC% · rata-rata UUT / 100)`. Titik 1 naik dari
**1,06076** ke **1,68128 L**; titik 2 ke **6,00836 L**; titik 3 ke
**12,01516 L**.

**Pertanyaan:** sertifikat yang sudah terbit dari master ini ditarik, direvisi,
atau berlaku maju sejak tanggal keputusan?

**Yang tertahan sampai dijawab:** tidak ada — server sudah memasang lantainya.
Yang tertahan cuma keputusan atas arsip sertifikat lama.

---

## §2 — Seluruh sesi Flowrate berjalan DI LUAR lingkup akreditasi

Pita akreditasi Flowrate mulai di **75 Lpm**. Sesi contoh mengukur **2,03** dan
**9,98 Lpm** — dua puluh sampai tiga puluh tujuh kali di bawah batas bawah.
Sertifikatnya tetap membawa nomor lingkup `LK-285-IDN`.

Ditambah titik 4 Totalizer: **2498 L** di luar pita 78–1991 L.

Nama berkas masternya sendiri berbunyi "100-980lpm", sementara `INPUT DATA!E14`
berbunyi `0.1-0.6 m3/h` (= 1,67–10 Lpm). Jadi ada dua kemungkinan:

1. Rentang di nama berkas itu rancangan instalasi, bukan sesi ini; atau
2. Sesi ini memang berjalan di luar lingkup dan sertifikatnya tetap membawa
   nomor akreditasi.

**Yang sudah dikerjakan server:** titik di luar kedua pita **DIBLOKIR**, bukan
diterbitkan dengan peringatan. Peringatan yang bisa dilewati admin adalah persis
kelas kerusakan yang sudah ditulis di `docs/permintaan-user-7.md` §9.

**Pertanyaan:** ada pita yang belum masuk lampiran, atau sesi seperti ini memang
tidak boleh terbit ber-LK-285-IDN?

**Yang tertahan:** seluruh sesi Flowrate gravimetri. Sampai dijawab, tidak ada
satu pun titik Flowrate yang bisa disertifikatkan lewat jalur ini.

---

## §3 — Koreksi timer dihitung, lalu dibuang — dan satu deviasinya BERGANTI TANDA

`PERHITUNGAN FC` Flowrate menghitung rantai lengkap:

```
D45  t_rata                     = AVERAGE(3 pencatatan)
D46  Index Timer                = titik tabel terdekat
D47  Correction Standard        = +0,0035 menit
D48  Standard Corrected (minute)= 1,0038333
```

Lalu laju alir massa (`D77`) memakai **`D45`** — waktu MENTAH. `D48` tidak dibaca
satu sel pun di seluruh workbook.

Dampaknya diukur, bukan dikira:

| Titik | Deviasi master (waktu mentah) | Waktu terkoreksi saja | Waktu + suhu terkoreksi |
|---|---|---|---|
| 1 | −0,0041534 Lpm | −0,0112284 (2,70×) | **−0,0101469 (2,44×)** |
| 2 | **+0,0299162 Lpm** | −0,0048855 | **−0,0011816** |

Perhatikan titik 2: **tandanya berbalik.** Sertifikat master mengatakan alat
membaca **rendah** 0,03 Lpm; dengan koreksi timer dipakai, alat membaca
**tinggi**. Besarannya kecil, arahnya kebalikan — dan arah itulah yang dipakai
pelanggan untuk menyetel alatnya.

**Yang sudah dikerjakan server:** koreksi timer **DIPAKAI**. Ini kerusakan
salin-tempel yang perilaku benarnya tidak ambigu — koreksi standar yang sudah
dihitung memang untuk dipakai, dan seluruh rantai lain di workbook yang sama
(timbangan, suhu) memakai nilai terkoreksinya.

**Pertanyaan:** dikonfirmasi? Dan kalau ya, sertifikat mana saja yang terbit
dengan tanda deviasi yang terbalik?

---

## §4 — Dua rumus koreksi apung dalam SATU sheet

`PERHITUNGAN FC` Flowrate:

```
D75 (titik 1) = D74 * (1 + E)                   <- bentuk KALI
I75 (titik 2) = I74 / (1 - (ρ_udara/ρ_air))     <- bentuk BAGI
M75 (titik 3) = M74 / (1 - (ρ_udara/ρ_air))     <- bentuk BAGI
```

Keduanya pendekatan yang sah untuk koreksi apung, tapi bukan hal yang sama:
selisihnya **0,0151 % pada massa**. Workbook Totalizer memakai bentuk KALI untuk
keempat titiknya.

Terukur di Flowrate titik 2: `Mt` bergerak dari **9,9410046** ke **9,9394996 kg**.

**Yang sudah dikerjakan server:** dipilih bentuk **KALI** — mayoritas, dan
konsisten dengan Totalizer.

**Pertanyaan:** bentuk mana yang berlaku? ISO 4185 sendiri menulis yang mana?

---

## §5 — Volume pipa `Vt` dihitung di kedua workbook, dipakai NOL kali

```
Totalizer  INPUT DATA!P32 -> PERHITUNGAN FC!P21 = 0,10129012 L
Flowrate   INPUT DATA!Q32 -> PERHITUNGAN FC!S20 = 0,00010129012 m3
```

Nilainya berasal dari cabang `(3,14 · 0,635² · 80)/1000`. Sel `AE32` menyimpan
jejak versi lain sebagai TEKS: `(3.14*(3.81^2)*625)/1000` — jari-jari dan panjang
pipa yang sama sekali berbeda, hasilnya **28,49 L** alih-alih 0,10129 L.

Di ISO 4185, volume pipa dari UUT ke standar memang masuk hitungan
standing-start-stop. Di kedua workbook ini dia berhenti di situ: nol sel
membacanya.

**Yang sudah dikerjakan server:** disimpan sebagai besaran sesi
(`spesifikasi_alat.flowmeter.volume_pipa_l`), **tidak** masuk hitungan.

**Pertanyaan:** metodenya standing-start-stop (dan `Vt` seharusnya masuk), atau
flying-start-stop (dan `Vt` memang tidak relevan)? Yang mana dimensi pipa yang
benar — 0,635/80 atau 3,81/625?

---

## §6 — Sel U95 sertifikat titik 3 & 4 tidak dikonversi ke satuan volume

Totalizer:

```
K36 (titik 1) = J34 * ρ_air         <- konversi kg -> L
K56 (titik 2) = J54 * ρ_air         <- konversi kg -> L
K76 (titik 3) = MAX(J74:K75)        <- TANPA konversi
K96 (titik 4) = MAX(J94:K95)        <- TANPA konversi
```

`PERHITUNGAN U95%!L76` dan `L96` bahkan melabelinya `kg`, sementara kolom
sertifikat `SERTIFIKAT!J21` berjudul `L`. Dua baris sertifikat mencetak kilogram
di kolom berjudul liter.

Selisihnya kecil hari ini (ρ ≈ 0,9964, jadi 0,36 %) dan karena itu tidak pernah
terlihat. Kalau fluidanya suatu saat bukan air, dia meledak.

**Yang sudah dikerjakan server:** satu jalur, konversi selalu.

**Pertanyaan:** konfirmasi saja — ini salin-tempel, bukan kebijakan?

---

## §7 — Kolom "Measured Flowrate During Calibration" separuh diketik tangan

`SERTIFIKAT!K22 = 0,77` dan `K23 = 0,78` — **angka literal**, bukan rumus.
`K24`/`K25` memakai `='PERHITUNGAN FC'!K27` yang menunjuk blok
`Flowrate on Software (kg/s)` yang **kosong di seluruh sesi**, jadi pulang `0`.

Sertifikat mencetak dua angka yang diketik orang dan dua angka nol, di kolom yang
sama, tanpa ada yang membedakannya.

**Pertanyaan:** 0,77 dan 0,78 m3/h itu dari mana? Dan kolom ini memang wajib
tercetak, atau boleh dikosongkan kalau tidak diukur?

---

## §8 — `Unit Converter` berisi data sesi lain

Sheet tersembunyi berjudul `AUTO CONVERT (MASUK KE INPUT DATA)` berisi pembacaan
**39,424 / 40,010 / 39,945 m3/h** dan mengalikannya jadi ~657–667 Lpm.
`INPUT DATA` sesi ini berisi **0,1221 m3/h**. Dua-duanya tidak berhubungan, dan
tidak ada satu sel pun yang menghubungkannya — judulnya berbohong tentang arah
alirannya.

Kolom sumbernya berlabel **`Data Lapangan (Ultrasonic Flowmeter)`** dan
`Data Penimbangan (Timbangan Dini Argeo/Sartorius)`, jadi ini sisa sesi
**varian UFM**, bukan gravimetri.

**Pertanyaan:** sesi mana yang datanya, dan apakah sesi itu sudah terbit?

---

## §9 — Pembagi dan distribusi yang tidak konsisten dengan dirinya sendiri

Tiga hal, satu keluarga:

1. **Pembagi `1,73`** dipakai di komponen `rectangular`, sementara komponen
   pengulangan memakai `SQRT(3)` sungguhan **di sheet yang sama**. Selisihnya
   0,12 %; pembulatan ke bawah membuat `u` sedikit lebih besar — arah yang aman.
2. **Komponen 8 Flowrate** (`Ketidakpastian densitas karena pengaruh perbedaan
   suhu`) berdistribusi `normal`, pembagi `2`, `vi` 60, dan memakai
   `U_temperature` **utuh**. Komponen sebangun di Totalizer berdistribusi
   `rectangular`, pembagi `1,73`, `vi` 50, dan memakai `U_temperature / 2`.
   Komponen yang sama, dua perlakuan, dua workbook.
3. **Drift dibagi 2 lagi.** Sheet drift menulis rumusnya sendiri
   `u(δmD) = 0,5·(Cmax − Cmin)/√3`, tapi kolom yang benar-benar dihitung cuma
   `0,5·ΔC` — pembagi `√3` tidak pernah dipakai. Lalu budget membaginya `2`
   sekali lagi sebelum membaginya `1,73`. Efektifnya `ΔC / 6,92`, bukan
   `ΔC / 3,46`.

**Yang sudah dikerjakan server:** ketiganya **DITIRU apa adanya**.

**Pertanyaan:** ketiganya disengaja? Butir 3 membuat komponen drift terbit
**dua kali lebih kecil** dari rumus yang ditulis sheet-nya sendiri.

---

## §10 — `vi` blok titik 2, 3, 4 tidak sebangun dengan blok titik 1

Komponen yang sama, `vi` yang berbeda-beda lintas blok (Totalizer):

| Komponen | Titik 1 | Titik 2–4 |
|---|---|---|
| Ketidakpastian Standar Timbangan | 60 | **200** |
| Kestabilan Aliran | 50 | **60** |
| Water Density (pembagi) | 2 | **1,73** (titik 3 & 4) |
| Water Density (`vi`) | 60 | **50** |
| Drift Timbangan Standar | 50 | **1.000.000** |
| Drift Suhu & Sensor Standar | 50 | **1.000.000** |

Cuma blok titik 1 yang konsisten dengan dirinya sendiri.

**Yang sudah dikerjakan server:** blok titik 1 diikuti untuk semua titik.

**Pertanyaan:** `vi = 1.000.000` (praktis tak hingga, yang secara metrologi
justru BENAR untuk batas rectangular yang diketahui penuh) atau `vi = 50`?

---

## §11 — Wadah kosong nol di seluruh sesi

Blok `Empty Container Weight` ada di kedua workbook (`INPUT DATA!D41:H43`) dan
bernilai **nol di seluruh sesi**, jadi jalur pengurangannya **nol kali teruji**.
Rumus master `D41 = D38 + D40` kebetulan benar selama wadahnya nol.

**Yang sudah dikerjakan server:** wadah kosong **DIKURANGKAN**, dan dijaga
`FlowmeterGravimetriGerbangTest::test_wadah_kosong_dikurangkan` dengan fixture
sintetis berwadah 12,5 kg. Tanpa itu, sesi pertama yang benar-benar memakai wadah
terbit dengan massa 152,37 kg alih-alih 139,87 kg — **8,9 % kelebihan**, tanpa
satu pun error.

**Pertanyaan:** airnya memang selalu ditara sebelum ditimbang, atau blok itu ada
untuk dipakai?

---

## §12 — Standar kedaluwarsa, sertifikat tetap terbit

`INPUT DATA!K3` kedua workbook memvonis **`ONE OR MORE STANDARD EXPIRED`**, dan
blok status di sebelahnya menandai:

| Standar | Status di master | Tanggal jatuh tempo | Tanggal sesi |
|---|---|---|---|
| Temperature Calibrator (Yokogawa) | WARNING | 2026-08-12 | 2026-01-05 |
| TC Type K | VALID | 2026-09-06 | 2026-01-05 |
| Stopwatch | **EXPIRED** (−81,4 hari) | 2026-04-25 | 2026-01-05 |
| Timbangan (Flowrate, Mettler) | **EXPIRED** | **2025-07-22** | 2026-01-05 |

Timbangan Mettler — yang jadi **standar utama** sesi Flowrate — sertifikatnya
sudah lewat 167 hari pada tanggal kalibrasi. Sertifikatnya tetap terbit.

**Pertanyaan:** status kedaluwarsa menahan penerbitan atau tidak? Dan kalau
menahan, sesi Flowrate ini bagaimana?

---

## §13 — Densitas anak timbang dan densitas udara dipatok nominal

`PERHITUNGAN FC!D63 = 8 kg/L` (densitas anak timbang) dan `D65 = 0,0012 kg/L`
(densitas udara). Keduanya nilai nominal ISO 4185, bukan hasil ukur — dan
keduanya masuk koreksi apung `E = ρ_udara·(1/ρ_air − 1/ρ_anak)` yang mengubah
massa 0,105 %.

**Yang sudah dikerjakan server:** ditiru.

**Pertanyaan:** perlu diukur (barometer + higrometer untuk ρ_udara, sertifikat
anak timbang untuk ρ_anak), atau nominal ISO 4185 memang yang berlaku?

---

## §14 — Varian UFM lawan gravimetri: sesi baru pakai yang mana?

Repo sudah punya dua jalur untuk alat yang sama:

| | UFM | Gravimetri |
|---|---|---|
| Umur master | dibuat Jan 2026 | dibuat Apr 2023, **23 revisi** |
| Kolom VALIDATION | **kosong** | **AM, 21 Mei 2026** |
| Standar | UFM Krohne UFC300 | Timbangan digital |
| Metode di lampiran akreditasi | tidak disebut | **ISO 4185 / NIST SP 250** |

**Yang sudah dikerjakan server:** varian gravimetri jadi **bawaan** untuk sesi
baru; varian UFM tetap bisa dipilih dan sesi lama tetap menghasilkan angka yang
sama persis. Sesi yang memilih UFM melahirkan peringatan yang menyebut status
validasinya.

**Pertanyaan:** dikonfirmasi? Dan workbook UFM yang belum divalidasi masih boleh
dipakai untuk sesi baru?

**Yang tertahan:** tidak ada — tapi butir ini yang paling menentukan bentuk kode
ke depan, dan cuma lab yang bisa menjawabnya.

---

## §15 — "Timbangan ke-3" bukan alat yang sama di kedua workbook

`INPUT DATA!X24` (Totalizer) / `Y24` (Flowrate) memilih satu dari empat
timbangan. Timbangan ke-3 (Mettler) tercatat berbeda di kedua workbook:

| | Totalizer | Flowrate |
|---|---|---|
| Tipe | DJ Series | **DFWLB-3** (tipe milik Dini Argeo) |
| S/N | HSEX1403752 | **0792531584** (S/N milik Dini Argeo) |
| Tertelusur | LK-285-IDN | **LK-305-IDN** |
| Tgl kalibrasi | 2024-07-22 | 2026-01-19 |
| Satuan tabel koreksi | **gram** | **kilogram** |
| Koreksi titik 27 & 30 | **0,1 kg** | 0,001 kg |

Tabel Totalizer bertitik **3–30 gram** tapi koreksinya sampai **0,1 kg** — koreksi
588 kali U95 timbangannya sendiri (0,00017 kg). Pola 0,1 di dua titik terakhir
identik dengan tabel Dini Argeo; salin-tempel.

Ditambah: `DATABASE!T16` menulis S/N `HASEX1403752`, `STANDAR KALIBRATOR!D27`
menulis `HSEX1403752` — beda satu huruf.

**Yang sudah dikerjakan server:** **keduanya disimpan**, dipilih per mode.
Memilih salah satu sebagai "yang benar" akan diam-diam menggeser angka yang sudah
tercetak. Selain itu titik tabel gram dikonversi ke kilogram sebelum dicocokkan,
supaya penimbangan 27 kg tidak memungut koreksi titik 27 g.

**Dan kertasnya menyebut nama KETIGA.** `SIDIK-FM-CAL-0538.B_Rev.3` mendaftar
empat checkbox: `Timbangan Elektronik (Dini Argeo)`, `(Sartorius)`,
**`(Excellent)`**, `(Fujitsu)` — bukan "Mettler". Sejalan dengan `FORM VALIDASI`
26 Jul 2024 yang berbunyi *"Adding balance **excellent**, dan fujitsu (baru)"*.
Jadi slot ke-3 lahir bernama Excellent, dan kedua sheet workbook sekarang
menyebutnya Mettler.

**Pertanyaan:** timbangan ke-3 itu SATU alat atau DUA? Kalau satu, "Excellent"
nama lama dan kertas Rev.3 yang belum menyusul — server memakai label workbook
(Mettler) dan mencatat aliasnya. Kalau dua, kertas dan workbook menunjuk
timbangan yang BERBEDA untuk slot yang sama, dan U95 yang masuk perhitungan ikut
berbeda. Dan tabel koreksi Totalizer-nya bersatuan gram atau kilogram?

---

## §16 — Label kestabilan aliran menulis `%FS`, nilainya kilogram

`STANDAR KALIBRATOR!P56` (Totalizer) melabeli kolomnya `Nilai Kestabilan (%FS)`;
`O35` (Flowrate) melabelinya `(kg/m)`. Nilainya sama-sama `(Max − Min)/2` dari
tabel stabilitas penimbangan, satuannya kilogram. Label Totalizer yang salah.

Ditambah: data uji stabilitas Dini Argeo **berbeda** antar-workbook —
Totalizer `546,2 / 547,0 / 546,2 / 545,4` (kestabilan 0,8 kg), Flowrate
`546,2 / 546,8 / 546,5 / 545,4` (kestabilan 0,7 kg), padahal tanggal ujinya sama
(10 Des 2025) dan tekniknya sama (`DT & NR`).

**Pertanyaan:** uji stabilitas yang mana yang berlaku, 0,8 atau 0,7 kg?

---

## §17 — Drift: rumus yang ditulis tidak sama dengan yang dihitung

Sudah disinggung di §9 butir 3, diulang di sini karena sumbernya sheet terpisah.

Keenam sheet drift (`Drift Timbangan Dini Argeo`, `... Sartorius`,
`... Mettler`, `Drift Constant-TC Type K`, `Drift Yokogawa-TC Type K`,
`Drift Timer Software Flowmeter`) semuanya menulis di kepala kolom:

```
u(δmD) = 0,5 (C max - C min) / sqrt(3)
```

dan semuanya menghitung kolom `0.5 * ΔC` saja. Pembagi `√3` tidak muncul di satu
sel pun.

**Pertanyaan:** `√3`-nya memang sengaja tidak dipakai (karena dibagi lagi di
budget), atau kepala kolomnya yang tertinggal dari versi lama?

---

## §18 — Drift Mettler 8,5e-05 kg tidak bisa diturunkan dari sheet drift-nya

`STANDAR KALIBRATOR!Q51` (Totalizer) dan `Q32` (Flowrate) sama-sama berbunyi
**8,5e-05 kg** untuk drift timbangan Mettler. Sheet `Drift Timbangan Mettler`
seluruh kolom koreksinya **nol**, dan `Max. U drift` di `N33` berbunyi **0**.

Angka 8,5e-05 tidak bersumber di sel mana pun.

**Yang sudah dikerjakan server:** dipakai 8,5e-05 (yang dibaca budget), dan
ketidakcocokannya dicatat di `_penyimpangan` JSON tabel standar.

**Pertanyaan:** 8,5e-05 itu dari mana?

---

## §19 — U95 timbangan beda antara sheet `DATABASE` dan `STANDAR KALIBRATOR`

| Timbangan | `DATABASE!V` | `STANDAR KALIBRATOR` | Yang dipakai budget |
|---|---|---|---|
| Dini Argeo | 0,52 | 0,52 | 0,52 |
| Sartorius | 0,033 | 0,033 | 0,033 |
| **Mettler** | **0,05** | **0,00017** | 0,00017 |
| **Fujitsu** | **0,016** | **1,6e-05** | 1,6e-05 |

Untuk dua timbangan terkecil, kedua sheet berselisih **294 kali** dan **1000
kali**.

**Pertanyaan:** yang mana U95 yang benar? Kalau `DATABASE` yang benar, budget
Flowrate sesi contoh terlalu kecil ratusan kali.

---

## §20 — Densitas air diukur SESUDAH sesi kalibrasi

`STANDAR KALIBRATOR!Q57` (Flowrate) / `Q64` (Totalizer):
`Tanggal Pengukuran : 18 Mei 2026`, PIC `NR`, piknometer 50,3139 ml.

Tanggal sesi kalibrasi: **5 Januari 2026** — empat bulan lebih awal.

Densitas air masuk hasil akhir sebagai **pembagi** (`hasil = Mt / ρ_air`) dan
masuk budget lewat dua komponen.

**Pertanyaan:** boleh memakai pengukuran densitas yang dilakukan sesudah sesinya?
Ada pengukuran densitas yang berlaku pada Januari 2026?

---

## §21 — Suhu terkoreksi Flowrate memakai INDEX, bukan rata-rata

`PERHITUNGAN FC` Flowrate:

```
D63 Average                = 26,5 °C     (suhu air yang benar-benar terukur)
D64 Index suhu             = 25          (titik tabel terdekat)
D65 Correction Standard    = -0,02
D66 Standard Corrected     = 24,98       <- = D64 + D65, bukan D63 + D65
```

Workbook Totalizer melakukannya dengan benar: `D58 = D55 + D57` = rata-rata +
koreksi = 25,48 °C.

Terukur: `ρ_air` bergerak dari **0,9964893** ke **0,9959691 kg/L** (0,052 %), dan
deviasi titik 1 ikut bergeser **25 %**.

**Yang sudah dikerjakan server:** rata-rata dulu, baru dikoreksi — mengikuti
workbook Totalizer.

**Pertanyaan:** konfirmasi saja — ini salin-tempel, bukan metode?

---

## §22 — Tiga besaran yang tidak bersumber

1. **Koefisien `0,00021`** di `ci` komponen suhu (`ci = Mt · 0,00021 / ρ²`).
   Tidak muncul di sel mana pun sebagai besaran bernama; dimensinya tidak jelas.
   Kemungkinan koefisien muai volume air per °C (yang nilainya memang ~2,1·10⁻⁴
   /°C pada 25 °C), tapi tidak tertulis.
2. **`0,0005 %`** di komponen Koreksi Bouyancy. Tidak bersumber.
3. **Penyebut komponen Koreksi Bouyancy berbeda antar-workbook**: Totalizer
   memakai nilai TERKOREKSI (`0,0005 % × Totalizer`), Flowrate memakai RATA-RATA
   UUT (`0,0005 % × UUT_rata × ρ`). Masing-masing ditiru.

**Pertanyaan:** ketiganya dari acuan mana?

---

## §23 — Penyebut `%OR` berbeda-beda, dan lantai CMC ikut menentukan

Master memakai **tiga penyebut berbeda** untuk `% of reading`:

| Sel | Blok | Penyebut | Nilai |
|---|---|---|---|
| `M36` | Totalizer titik 1 | **nilai terkoreksi** (140,53 L) | 0,75761 % |
| `M54` | Totalizer titik 2 | **massa** (499,37 kg) | 0,21218 % |
| `M37` | Flowrate titik 1 | **rata-rata UUT** (2,033 Lpm) | 2,22208 % |
| — | Totalizer titik 3 & 4 | selnya **tidak ada** | — |

Ini sebagian menjelaskan kenapa §1 tidak pernah ketahuan: persentase yang dipakai
membandingkan ke CMC cuma pernah lahir untuk sebagian titik, dan dengan penyebut
yang berpindah-pindah.

**Yang sudah dikerjakan server:** KEDUA varian memakai **rata-rata UUT** untuk
memilih pita CMC dan menghitung lantainya — satu aturan, bukan dua.

Alasannya bukan metrologi: kedua penyebut sama-sama bisa dibela. Alasannya
`flowmeter:audit-cmc` menghitung `%OR` sesi tersimpan dari `rata_rata`, jadi
lantai yang memakai penyebut lain menandai **setiap** sesi gravimetri sebagai
"perlu ditinjau" selamanya — peringatan palsu yang melatih admin menekan
"setujui tetap" tanpa membaca.

Terukur di titik 1 Totalizer: **1,68128 L** dengan rata-rata UUT, **1,68635 L**
dengan nilai terkoreksi. Selisihnya 0,3 %.

**Pertanyaan:** `% of reading` di lampiran akreditasi mengacu ke pembacaan alat
(UUT) atau ke nilai besaran terukur (standar terkoreksi)? Jawaban ini
menyeragamkan kedua varian.

---

## Butir kecil yang tetap dicatat

Tidak menahan apa pun, tapi ditulis supaya tidak ditemukan ulang:

- **`PERHITUNGAN U95%!D4` = `#VALUE!`** di kedua workbook — rentang ukur teks
  (`'140-2500'`, `'0.1-0.6'`) dikalikan angka.
- **`SERTIFIKAT!T27` (Totalizer) / `T25` (Flowrate) = `#REF!`** — rujukan yang
  targetnya sudah tidak ada.
- **Faktor cakupan `k` yang tercetak cuma milik titik 1.** `SERTIFIKAT!L26`
  Totalizer mencetak `1,9893186` untuk keempat baris, padahal titik 2 dan 3
  ber-`k` 1,9832641 dan titik 4 ber-`k` **2,5705818**. Sertifikat menyatakan satu
  faktor cakupan untuk empat baris yang tiga di antaranya dihitung dengan faktor
  lain.
- **Nomor metode berbeda antar-sheet.** `INPUT DATA!X11` berbunyi
  `SIDIK-IK-CAL-0528_Rev.6`; `SERTIFIKAT!O12` mencetak
  **`SIDIK-IK-CAL-0528_Rev.7`**. Yang sampai ke pelanggan Rev.7.
- **Tanggal terbit berbeda antar-sheet (Totalizer).** `INPUT DATA!E59` berbunyi
  2026-01-06; `SERTIFIKAT!B41` mencetak 2026-01-09.
- **Baris `Capacity/Graduation` tercetak dua kali** di sertifikat Totalizer
  (`B14` dan `B15`), dan baris kedua mencetak kelembapan yang **belum
  dikoreksi** (40,5 % lawan 39,67 %).
- **Blok "Standard used" sertifikat Flowrate rusak.** `B30` menulis
  `Timbangan Elektronik / Mettler/ / Timer.FM.1 / 0` — nomor seri yang tercetak
  milik **stopwatch**, dan kolom ketertelusuran memulangkan **`0`**. Sertifikat
  salah menyebut standar utamanya sendiri.
- **`STANDAR KALIBRATOR` kolom `Konversi Satuan Standart` KOSONG** di baris 25 °C
  dan 50 °C pada kedua workbook; VLOOKUP-nya memakai kolom `Pembacaan Alat (UUT)`
  sebagai gantinya. Ditiru.
- **Label tabel drift keliru.** `STANDAR KALIBRATOR!O50` menulis
  `Temp. Cal Constant - TC Type K` dengan nilai **0,06 °C**, tapi 0,06 itu
  `Max. U drift` sheet **Yokogawa**; sheet Constant memulangkan 0,125 °C. Nilai
  yang dipakai benar (sesinya memang memakai Yokogawa), labelnya yang salah.
- **Timer tertelusur `LK-361-IDN`**, bukan `LK-285-IDN` seperti standar lain.

---

## Formulir keputusan

| § | Butir | Keputusan | Tanda tangan | Tanggal |
|---|---|---|---|---|
| 1 | Sertifikat di bawah pita CMC — tarik / revisi / berlaku maju | | | |
| 2 | Sesi Flowrate di luar lingkup — blokir / pita baru | | | |
| 3 | Koreksi timer dipakai — ya / tidak | | | |
| 4 | Bentuk koreksi apung — KALI / BAGI | | | |
| 5 | Volume pipa `Vt` masuk hitungan — ya / tidak | | | |
| 12 | Standar kedaluwarsa menahan penerbitan — ya / tidak | | | |
| 14 | Varian bawaan sesi baru — gravimetri / UFM | | | |
| 15 | Timbangan ke-3 — satu alat / dua alat | | | |
| 19 | U95 Mettler & Fujitsu — DATABASE / STANDAR KALIBRATOR | | | |
| 23 | `% of reading` — UUT / nilai terukur | | | |

Manajer Teknis: ______________________  Tanggal: ____________
