# Mau nanya soal olah data TITS, Pak Rohman

Halo Pak Rohman, ini soal master TITS yang dua itu — `fungsi Measure utk UUT`
sama `fungsi Source utk UUT`. Backend-nya sudah saya buatkan dan angkanya sudah
saya cocokkan ke dua sesi yang ada di file (01-CAL-625 dan 0159-CAL-626).
Alhamdulillah cocok semua sampai digit terakhir.

Cuma ada beberapa hal di file-nya yang saya nggak berani putuskan sendiri, jadi
saya ikut apa adanya dulu supaya hasilnya sama persis dengan sertifikat yang
sudah keluar. Tapi kalau ternyata salah satu di antaranya memang keliru, tolong
dikasih tahu ya Pak — saya tinggal ganti satu angka di kode, nggak sampai
bongkar apa-apa.

Saya urutkan dari yang paling ngaruh ke angka sertifikat.

---

## 1. Pembagi AC Pick Up — ini agak aneh, Pak

Di sheet `PERHITUNGAN U95%`, baris "Pengaruh AC Pick Up" itu ditandai
distribusinya `rect.` di kolom K. Kalau `rect.` kan pembaginya √3.

Nah, di selnya begini:

```
Q22 = SQRT(3)          ← ini kolom Divisor, sampai sini sudah bener
U22 = N22/SQRT(Q22)    ← tapi di sini diakarin LAGI
```

Jadi pembaginya jatuh ke 1,316, bukan 1,732. Yang bikin saya ragu: baris di
atas dan di bawahnya nulisnya `U = N/Q` yang bener, padahal `Q`-nya sama-sama
`SQRT(3)`. Cuma baris ini yang beda. Dan kejadiannya di **dua-duanya**, file
Measure maupun Source.

**Sekarang saya ikutin file-nya**, karena kalau saya betulin, angkanya jadi
beda dari sertifikat yang sudah dikirim ke pelanggan.

Kalau dibetulin ke √3: U95 sesi Measure turun dari **0,85 jadi 0,84 °C**. Masih
di atas CMC 0,83, jadi angka di sertifikat ikut berubah — bukan cuma di
belakang koma.

**Pertanyaannya:** yang bener pembaginya, atau labelnya `rect.` itu yang perlu
diganti?

## 2. Derajat kebebasan (v_eff) nggak dibulatkan ke bawah

Di master TITS, `k` dicari dari `v_eff` apa adanya (yang rumus panjang
`1.95996 + 2.37356/v + ...` itu). Sementara sepuluh alat lain di sistem
membulatkan `v_eff` ke bawah dulu baru cari `k`-nya — ikut aturan GUM G.4.1,
dan dulu itu sudah dicocokkan sama lembar manual pH dan cocok.

Bedanya kelihatan pas `v_eff`-nya kecil. Sesi Measure kemarin `v_eff` 6,5:

| cara | k | U95 | kecetak jadi |
|---|---|---|---|
| apa adanya (master TITS) | 2,40 | 0,8533 | **0,85** |
| dibulatkan ke bawah jadi 6 (alat lain) | 2,45 | 0,8694 | **0,87** |

**Sekarang saya ikutin master TITS**, biar sama dengan sertifikat 01-CAL-625.

**Pertanyaannya:** ini memang sengaja beda dari lembar pH, atau sebaiknya
diseragamkan aja Pak?

## 3. Di mode Source ada DUA komponen drift

Di file Source, budget-nya punya:

- baris 20 — "Ketidakpastian Baku Drift Temp. Kalibrator", ini normal, dia
  lookup ke tabel drift sesuai merk & tipe sensor (Type S → 0,056);
- baris 22 — namanya cuma "Drift", isinya `='STANDAR KALIBRATOR'!Y8` dengan
  `ci = 2`.

Yang baris 22 ini yang bikin saya bingung. `Y8` itu alamat mati ke **drift
Constant Type N** (0,38), padahal sesinya pakai **Yokogawa Type S**. Jadi kalau
tipe sensornya diganti, sel itu nggak ikut berubah — dia bukan lookup, cuma
nunjuk satu sel.

Dan di file Measure, baris ini nggak ada sama sekali.

**Sekarang saya ikutin**, karena kalau dibuang `Uc`-nya berubah dari 0,576 ke
0,373. Untungnya untuk sesi kemarin U95 yang dilaporkan tetap 1,2 (kena lantai
CMC dua-duanya), tapi untuk sesi lain bisa beda.

**Pertanyaannya:** baris 22 itu memang komponen sendiri (kalau iya, angkanya
dari mana Pak, dan kenapa `ci`-nya 2), atau sisa copy-paste?

## 4. Drift dibagi 2 di Measure, tapi nggak di Source

Komponen yang sama persis, perlakuannya beda:

```
Measure  N20 = VLOOKUP(...) / 2
Source   N20 = VLOOKUP(...)
```

**Pertanyaannya:** yang dibagi 2 di Measure itu konversi dari apa ya Pak? Atau
justru yang Source yang kelupaan dibagi?

## 5. Ketidakpastian kalibrator diambilnya beda cara

```
Measure  ambil nilai TERBESAR dari seluruh kolom U95 tipe sensor itu
Source   ambil U95 di titik paling tinggi yang dipakai sesi itu
```

Jadi Measure ambil yang paling jelek se-tabel (aman), Source ambil yang di
ujung atas sesi aja. Untuk sesi kemarin: Measure pakai 0,36 (padahal itu
angka titik 1200, sesinya cuma sampai 1000), Source pakai 0,56.

**Pertanyaannya:** yang mana yang jadi patokan lab Pak?

## 6. Titik 1100 itu pas di tengah-tengah

Di sesi Source ada titik 1100 °C. Di tabel koreksi kalibrator adanya cuma 1000
dan 1200 — jaraknya sama persis, 100 ke kiri 100 ke kanan.

Rumus di master (`MATCH(...,0)`) itu harusnya ngambil yang ketemu duluan, yaitu
1000 (koreksi −0,15). Tapi hasil yang tersimpan di selnya **1200** (koreksi
−0,20), dan itu yang kecetak di sertifikat.

**Sekarang saya ikutin hasilnya**, bukan rumusnya — jadi kalau seri, yang
menang titik yang lebih tinggi.

**Pertanyaannya:** (a) kalau seri gitu harusnya ambil yang mana Pak? dan (b)
untuk titik yang jauh dari titik tabel, sebaiknya **diinterpolasi** aja nggak?
Sekarang koreksinya diambil utuh dari titik terdekat, nggak diinterpolasi.

## 7. Kolom U95 Type K Yokogawa di file Source isinya minus

Ini kayaknya jelas kekeliruan, tapi saya laporkan aja. Di file **Source**,
tabel **U95** kolom Type K Yokogawa isinya −0,06 sampai −0,31. Kalau
dibandingkan, itu persis deret **koreksi** dari tabel di atasnya — kayaknya
kesalin ke kolom sebelah.

U95 nggak mungkin minus, jadi di sistem saya tolak: kalau ada sesi Type K mode
Source, komponen sertifikat kalibratornya nggak ikut dihitung dan U95-nya jatuh
ke CMC.

**Yang saya minta:** angka U95 Type K Yokogawa untuk fungsi Source yang bener,
Pak.

## 8. Tiga hal kecil

**(a) Nomor formulir lembar kerjanya belum ada.** Di seluruh file cuma ada
`SIDIK-FM-CAL-2403_Rev. 0` di footer sheet SERTIFIKAT — itu kan formulir
sertifikat, dipakai bareng semua alat. Nomor formulir lembar kerja TITS-nya
berapa ya Pak? Sementara saya kosongkan, nggak berani ngarang.

**(b) `k` di sertifikat kecetak nol desimal.** Sel `O30` formatnya `0`, jadi
`k = 2,40` kecetaknya jadi `2`. Yang baca sertifikat kan nggak bisa bedain sama
`k = 2` beneran. Saya ikutin dulu; kalau memang mau diperbaiki formatnya, tinggal
bilang.

**(c) Rentang RLK sensor resistif beda antara dua dokumen.** Di lampiran
akreditasi tertulis **−20…800 °C**, tapi di sheet DATABASE master tertulis
**−10…800 °C**. Saya pakai yang lampiran (karena itu yang ngiket lab). Mana yang
bener Pak?

---

## Yang nggak saya tanyakan karena kayaknya udah jelas

- Titik 1400 & 1700 di kolom Type N/Type S isinya `0` di tabel koreksi **dan**
  tabel U95. Nol di dua-duanya itu kayaknya sel kosong yang keisi nol, bukan
  koreksi nol beneran. Tapi karena nggak ada sesi yang sampai titik segitu,
  sementara nggak ngaruh apa-apa. Datanya saya simpan apa adanya.
- Sel `#REF!` (kolom Type B & Type S Yokogawa dari titik 600 ke atas) saya
  anggap kosong, bukan nol.
- Type B belum ada CMC-nya di lampiran akreditasi, jadi kalau ada sesi Type B
  datanya tetap bisa disimpan tapi U95-nya belum bisa terbit.

---

## Satu lagi, di luar TITS

Ini bukan soal TITS langsung, tapi ketahuan pas saya ngerjain ini.

Soal kondisi lingkungan yang kecetak di sertifikat. Master ngambil titik
kalibrasi thermohygro yang **paling dekat ke suhu yang terukur**, terus pakai
koreksi titik itu. Sistem kita pakai **satu titik tetap** per thermohygro (yang
dekat kondisi lab sehari-hari, sekitar 20 °C).

Sesi 01-CAL-625 kemarin, TH-4, suhu 24,1 → 24,3 °C:

- master ambil titik 29,14 (koreksi −0,82) → kecetak **23,38 °C**
- sistem ambil titik 19,37 (koreksi −0,59) → kecetak **23,61 °C**

Bedanya 0,23 °C. Saya **belum ubah** karena jalur ini dipakai bareng sebelas
alat — kalau saya ganti, angka kondisi lingkungan di semua sertifikat ikut
geser. Jadi saya tunggu keputusannya dulu.

Oh iya, satu lagi: rumus yang nyari titik **kelembaban** di master
(`H16`) itu ngitungnya pakai **suhu akhir** (`F15`), bukan pakai angka
kelembabannya. Kayaknya itu kekeliruan sel juga Pak, tapi saya laporkan aja
biar tahu.

---

Segitu dulu Pak Rohman. Kalau ada yang kurang jelas atau perlu saya tunjukkan
selnya langsung, bilang aja. Nggak buru-buru — backend-nya sudah jalan, tinggal
nunggu konfirmasi angkanya aja.

Rincian teknisnya lengkap ada di `docs/pertanyaan-lab-tits.md` kalau mau dicek
lebih detail.
