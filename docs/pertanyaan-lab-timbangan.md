# Pertanyaan lab — alat baru **Timbangan** (Massa, lampiran no. 12)

**Sumber:** tiga workbook master ber-password yang dikirim pemilik proyek 31 Agt 2026.

| Berkas | Sesi contoh | Basis | Metode |
|---|---|---|---|
| `New_Master_Olda_Timbangan_kg.xlsm` | `011-CAL-525` — Timbangan Bestar, 100 kg / 0,02 kg | kg | pembebanan langsung |
| `New_Master_Olda_Timbangan_gram.xlsm` | `019-CAL-425` — Moisture Analyzer Mettler Toledo HB53, 54 g / 0,0001 g | g | pembebanan langsung |
| `TERBARU_Master_Olda_Timbangan_Subtitusi_291025.xlsm` | `0136-CAL-123` — Timbangan Elektronik Dini Argeo DFWLB-3, 2000 kg / 0,1 kg | kg | beban substitusi |

Acuan metodenya ditulis sendiri di sheet `Sekilas Info` ketiganya: **NMI Monograph 4
(CSIRO 2010)**, yang memisahkan *Uncertainty of Correction* dari *Uncertainty of Weighing*.
Dua-duanya dicetak di sertifikat (bagian 3 dan bagian 7).

**Statusnya sekarang:** backend alat ke-21 sudah jalan dan angkanya **cocok sampai digit
terakhir dengan ketiga master** — 1.099 angka diadu oleh `TimbanganMasterTest`
(tiap `ui × ci`, tiap `vi`, `uc`, `veff`, `k`, `U`, `U95`, plus tiap kolom turunan blok
akurasi). Yang di bawah ini **bukan blokir**; ini hal-hal yang cuma manajer teknis lab yang
boleh memutuskan, dan sampai diputuskan kode meniru masternya apa adanya.

---

## T1 — Tiga workbook memuat SERTIFIKAT ANAK TIMBANGAN yang berbeda. Mana yang berlaku?

Ini yang paling penting, dan paling gampang kelewat karena ketiganya kelihatan "tabel yang sama".

Keping fisik yang sama, tiga angka:

| Keping | master kg | master gram | master substitusi |
|---|---|---|---|
| E2 100 g — massa konvensional | 100,0004 g | **100,000033 g** | 99,999984 g |
| E2 100 g — ketidakpastian | 0,035 mg | 0,023 mg | 0,032 mg |

Selisih kg vs gram = **0,37 mg**, yaitu **16×** ketidakpastian keping itu sendiri. Dan blok E2
master substitusi **seluruhnya** memakai angka lain (37 sel berbeda dari master kg) — kelihatannya
kalibrasi ulang, berkasnya bertanggal 29 Okt 2025.

Di blok F1 ada pola ketiga: **kolom ketidakpastian keping 0,1 g – 500 g di master kg seribu kali
lebih kecil** daripada baris yang sama di master gram, sementara baris 1 kg ke atas di berkas yang
sama cocok persis. Satu kolom, dua satuan.

> **Yang dilakukan kode sekarang:** menyimpan ketiganya dan memilih per sesi, supaya tiap sesi
> bisa dihitung ulang jadi angka yang sama dengan kertas yang menerbitkannya.
>
> **Yang ditanyakan:** sertifikat anak timbangan mana yang berlaku hari ini? Begitu dijawab, dua
> tabel sisanya jadi arsip dan sesi baru pakai satu tabel saja.

## T2 — `ui` "Uncertainty of Correction" di budget Weighing: tiga perlakuan, satu komponen

Ketiganya memasukkan ketidakpastian **diperluas** (`k · uc`) sebagai komponen **baku** di budget
Weighing, lalu memperlakukannya beda-beda:

| Master | rumus `ui` | akibatnya |
|---|---|---|
| kg | `ui = U` (dipakai mentah) | paling besar; secara GUM kelebihan ±2× |
| gram | `ui = U / k` | mengembalikan ke baku — **ini yang benar menurut GUM** |
| substitusi | `ui = U / √3` | tidak mengembalikan apa pun; √3 itu pembagi distribusi rectangular |

Di sesi kg titik 1 bedanya nyata: `ui` 0,01967 (mentah) vs 0,01003 (dibagi k). U95 Weighing
0,04251 kg vs kira-kira 0,0248 kg — **hampir dua kali**.

> **Ditanyakan:** perlakuan mana yang dipakai lab? Kalau `U/k` (gram) yang benar, dua master lain
> menerbitkan U95 Weighing yang terlalu besar — arah aman, tapi bukan angka yang seharusnya.

## T3 — Enam baris drift: kolom "Divider" tertulis `Ö3`, rumusnya tidak membagi

Di ketiga master, baris `Mass Instability (Drift)-Mass 1..6` menulis pembagi `Ö3` di kolomnya,
tapi rumus `ui`-nya `=IF(J13="","",J13)` — **tanpa pembagian**.

Nilai di `Tabel_F1drift` memang sudah berupa ketidakpastian baku, jadi membaginya lagi justru
salah. Yang keliru **labelnya**, bukan angkanya.

> **Ditanyakan:** boleh label kolomnya dibetulkan jadi `1` di master? Angkanya tidak berubah
> sama sekali; yang berubah cuma jejak audit berhenti membaca dua hal yang bertentangan.

## T4 — `ci = 10` di baris drift Mref (khusus master substitusi)

Master substitusi memberi koefisien sensitivitas **10** ke baris drift pertama (Mref 200 kg);
lima baris drift lain, dan seluruh baris drift di dua master lain, ber-`ci = 1`. Tidak ada
keterangan di mana pun di berkas itu.

Dugaan yang masuk akal: 2000 kg ÷ 200 kg = 10, yaitu berapa kali keping Mref dipakai ulang
sepanjang rangkaian substitusi — kalau begitu `ci = 10` memang benar secara fisika, dan
seharusnya **dihitung** dari kapasitas ÷ nominal Mref, bukan ditulis tetap.

> **Ditanyakan:** benar 10 itu jumlah pemakaian ulang Mref? Kalau ya, sesi 1000 kg dengan Mref
> 200 kg seharusnya ber-`ci = 5`, dan angka tetap 10 di master bakal terlalu besar.

## T5 — Dua sel silang-kabel di master substitusi

Di `PERHITUNGAN U95%-Weighing` master substitusi:

- **`K6` (Sres MID)** dihitung dari `FC!H116` — kolom STDEV kapasitas **Maximum**, bukan `E116`
  yang Middle.
- **`K7` (Sres MAX)** dihitung dari `FC!M116`, kolom **ketiga** yang di workbook ini tidak ada
  (sisa salinan master kg yang punya tiga kolom keterulangan). Sel kosong dibaca nol, jadi
  cabangnya selalu jatuh ke lantai `0,82 × a`.
- **`H8` (Sr MID)** menunjuk `FC!V70`, blok akurasi ke-**6**, sementara kapasitas tengah sesi itu
  ada di blok ke-**5** (`V66`).

Akibat yang pertama: komponen `Repeatability MID-range` naik dari ~0 jadi 0,0707 kg, dan U95
koreksi titik 1 dari ~0,237 jadi **0,280 kg — 18%**.

> **Yang dilakukan kode sekarang:** meniru dua yang pertama (angkanya yang terbit di sertifikat),
> dan meniru **niat** yang ketiga (Sr blok akurasi terdekat kapasitas tengah) karena nomor
> barisnya tidak punya arti buat sesi dengan jumlah titik lain. Ketiganya nol di sesi contoh, jadi
> pilihan itu tidak menggeser angka apa pun.
>
> **Ditanyakan:** ketiganya kerusakan salin-tempel, atau ada alasannya?

## T6 — Master gram titik 9: rujukan massa meleset tiga baris

Di `PERHITUNGAN U95% - Correction` master gram, blok POINT 9 membaca Mass 2 & Mass 3 dari
`FC!B80` & `FC!B81`. Blok titik 9 ada di baris **76–78**; baris 80–81 milik titik 10 dan kosong.
Akibatnya keping 20 g & 5 g titik itu menyumbang **drift nol**.

Sembilan titik tetangga di berkas yang sama melakukannya dengan benar.

> **Yang dilakukan kode sekarang:** menghitungnya BENAR (bukan meniru), karena perilaku yang
> benar tidak ambigu. Selisihnya: U95 titik 9 dari 0,00057460 jadi **0,00057477 g** (+0,03%),
> arah aman. Ditegakkan `TimbanganMasterTest::SEL_MASTER_RUSAK`.
>
> **Ditanyakan:** konfirmasi ini memang salah drag, bukan disengaja.

## T7 — Master substitusi titik 9: komponen dibaca dari WORKBOOK LAIN

Komponen `Weight Standard` blok POINT 9 berbunyi:

```
=SUM('[3]PERHITUNGAN FC'!F74:G76)/1000
```

`[3]` itu tautan luar ke
`\\192.168.100.70\sidcal\…\(NEW) Master Olda Timbangan kg.xlsm` — **workbook yang berbeda**, dan
nilainya tinggal cache. Hasilnya 2,4055e-5 alih-alih 0,01348 dari berkasnya sendiri.

Ini kelas jebakan yang sudah pernah menggigit repo ini (§11: tabel Yokogawa Thermocouple dari
cache tautan luar yang berlubang).

> **Yang dilakukan kode sekarang:** membaca dari berkasnya sendiri. Selisih U95 titik 9:
> 0,27978 → **0,28014 kg**; di titik itu lantai CMC 0,52 kg menang, jadi **sertifikatnya tidak
> bergerak sama sekali**.
>
> **Ditanyakan:** tautan luar `[3]` ini boleh diputus di master? Selama masih ada, tiap kali
> berkas kg dipindah/diubah, angka di berkas substitusi ikut berubah tanpa ada yang menyentuhnya.

## T8 — `Rounding of Final Result`: resolusi alat vs 0,5 tetap

| Master | `U` | pembagi | `ui` di sesi contoh |
|---|---|---|---|
| kg | resolusi alat (0,02 kg) | 3,4641 (= 2√3) | 0,005774 kg |
| gram | **`0,5/1000` tetap** | 1,73 | 0,000289 g |
| substitusi | **`0,5/1000` tetap** | 1,73 | 0,000289 kg |

Dua master menuliskan angka tetap yang tidak diturunkan dari alatnya. Untuk sesi gram (resolusi
0,0001 g) itu **sepuluh kali lebih besar** daripada kalau resolusi yang dipakai; untuk sesi
substitusi (resolusi 0,1 kg) justru jauh lebih kecil.

Pembaginya juga beda: `2√3` (kg) memperlakukan `U` sebagai lebar penuh, `√3` (dua lainnya)
memperlakukannya sebagai setengah-lebar.

> **Ditanyakan:** mana yang benar — resolusi alat atau 0,5 tetap? Dan setengah-lebar atau
> lebar penuh?

## T9 — Keterulangan: tiga sumber `Sr` yang berbeda

| Master | `Sr` diambil dari |
|---|---|
| kg | STDEV blok keterulangan, dipilih pita kapasitas titiknya (Middle / Maximum) |
| gram | `Sr` blok **akurasi** titik itu sendiri — `STDEV(m, m')`, n = 2 |
| substitusi | dua komponen (MID & MAX), masing-masing `N × √5` |

Ketiganya menyebut hal yang berbeda dengan nama yang sama. Master kg juga **membulatkan ke 4
desimal** dulu sebelum membandingkan `Sr` dengan `Sres`; dua lainnya tidak.

Master gram punya cabang eksplisit `IF(N8=0, N9, "cek")` — dia **menolak menebak** kalau
`0 < Sr < Sres`. Itu perilaku yang paling hati-hati dari ketiganya.

> **Ditanyakan:** mana yang jadi acuan? Dan `√5` di master substitusi itu apa — jumlah posisi
> eksentrisitas?

## T10 — `Ud` (drift massa standar) dihitung tapi tidak pernah dipakai

`INPUT DATA!E83` menghitung `d = 0,1 × ketidakpastian maksimum seluruh anak timbangan`, lalu
`PERHITUNGAN FC` menghitung `Ud = d/√3`. Angka itu **tidak masuk budget mana pun** — komponen
drift di budget datang dari `Tabel_F1drift` kolom 5, bukan dari sini.

> **Ditanyakan:** sel ini sisa revisi lama, atau memang ada tempat yang seharusnya memakainya?

## T11 — U95 tercetak satu desimal lebih pendek daripada masternya

Format angka kolom sertifikat, dibaca dari sheet `SERTIFIKAT` ketiga master:

| Master | resolusi | Nominal & Correction | **Uncertainty** |
|---|---|---|---|
| kg | 0,02 | `0.00` (2 desimal) | `0.000` (**3**) |
| gram | 0,0001 | `0.0000` (4) | `0.00000` (**5**) |
| substitusi | 0,1 | `0.0` (1) | `0.0` (**1**) |

Aturan bawaan sistem menurunkan desimal dari resolusi alat (`Angka::desimalDariResolusi`), dan
memakai angka yang sama untuk kolom pembacaan DAN kolom U95. Kolom pembacaannya cocok di ketiga
varian; **kolom U95-nya yang meleset satu desimal** di dua varian:

- kg: `0,033 kg` bakal tercetak **`0,03 kg`** — mengecilkan ketidakpastian terakreditasi, dan itu
  arah yang salah;
- gram: `0,00057 g` bakal tercetak `0,0006 g`.

Dua master yang pertama sebenarnya konsisten dengan aturan metrologi yang biasa dipakai:
**U dilaporkan 2 angka penting** (0,033 · 0,00057). Yang tidak ikut justru master substitusi —
`0,52 kg` tercetak `0,5 kg`, satu angka penting.

> **Belum diperbaiki, dan sengaja.** Hook profil yang tersedia
> (`desimalU95()` / `desimalU95Titik($titikUkur)`) tidak menerima alat maupun nilai U95-nya, jadi
> "2 angka penting" tidak bisa dinyatakan dari situ tanpa menebak. Menaruh angka tetap juga salah:
> apa pun yang dipilih bakal bertentangan dengan salah satu dari tiga master.
>
> **Ditanyakan:** U95 sertifikat Timbangan dicetak 2 angka penting (ikut master kg & gram), atau
> ikut desimal resolusi alat (ikut master substitusi)? Begitu dijawab, perbaikannya satu tempat —
> dan mungkin perlu hook yang menerima nilainya, bukan cuma titik ukurnya.

---

## Yang TIDAK ditanyakan karena sudah jelas

- **Sel kosong dibaca nol.** Tiap `VLOOKUP` master dibungkus `IFERROR(…,"")`, jadi nominal anak
  timbangan yang tidak ada di tabel pulang kosong dan kosong ikut dijumlah sebagai nol —
  sertifikat terbit dengan massa standar yang hilang, tanpa error. **Tidak ditiru:** titik
  seperti itu diblokir dengan alasan yang kebaca. Pola yang sama sudah dipakai ketiga alat suhu.
- **`STDEV` sepuluh angka identik.** Excel memulangkan 1,198e-13, bukan nol — noise floating
  point. Sel itu cuma masuk `MAX(...)` yang dimenangkan angka lain, jadi tidak pernah sampai ke
  hasil. Kode memulangkan nol yang benar.
- **Deviasi keterulangan & kolom sumber STDEV.** Master kg tidak mengurangi nominal kapasitas dan
  ber-STDEV atas kolom deviasi; gram & substitusi mengurangi nominal dan ber-STDEV atas kolom
  pembacaan mentah. Selama kolom nol-nya konstan hasilnya identik, dan di ketiga sesi contoh
  memang konstan. Ditiru per varian supaya sesi yang nol-nya BERGERAK tidak diam-diam dihitung
  beda dari kertasnya.
- **Pita CMC.** Ketujuh belas pita A..Q (0 g s/d 2000 kg) semuanya ADA di lampiran akreditasi
  no. 12 — dicocokkan baris demi baris oleh `TimbanganCmcCocokAkreditasiTest`. Sempat diduga
  hanya delapan yang pertama yang terakreditasi; dugaan itu salah dan sudah dibuang.

---

## T12 — Nomor formulir lembar kerja untuk metode kg & gram

Kertas metode **substitusi** sudah ada: `SIDIK-FM-CAL-0508.A`, Revise 4 (diterima 31 Agt 2026).
Akhiran `.A` menyiratkan ada saudaranya untuk dua metode lain, tapi menyiratkan bukan mengetahui.

Nomornya **tidak ditebak**: lembar kg & gram terbit dengan `kode_dokumen` null sampai kertasnya
turun. Nomor formulir karangan di kop lembar lab terakreditasi itu temuan audit.

**Yang ditanyakan:** berapa nomor & revisi formulir lembar kerja Timbangan untuk metode langsung
(satuan kg) dan satuan gram?

---

## T13 — Selisih eksentrisitas: diukur dari BEBAN atau dari pembacaan CENTER?

Master menghitung penyimpangan tiap posisi sebagai `beban − pembacaan posisi`
(`FC!D133 = $F$128 − C133`). Mesin hitung kami memakai `pembacaan center − pembacaan posisi`,
karena kolom `beban` **kosong di ketiga workbook master** — jadi tidak ada angka lain yang bisa
dipakai.

Di ketiga sesi contoh keduanya menghasilkan angka yang sama persis, karena pembacaan center-nya
kebetulan sama dengan beban yang dipakai. Begitu ada sesi yang center-nya menyimpang, keduanya
berpisah dan yang tercetak di sertifikat (§4) ikut berbeda.

**Yang ditanyakan:** apakah kolom `Weight mass` (beban uji eksentrisitas) wajib diisi teknisi mulai
sekarang? Kalau ya, rumusnya dipindah ke `beban − pembacaan` mengikuti master. Kalau tidak,
pembacaan center tetap jadi acuan dan itu perlu dinyatakan sebagai keputusan, bukan kebetulan.
