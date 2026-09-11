# Ringkasan Pertanyaan Lab — satu dokumen untuk Manajer Teknis

**Disusun:** 11 September 2026 · **Untuk:** Manajer Teknis Lab PT Sidik
**Dari:** tim backend SIDIK · **Sifat:** dokumen baca + formulir keputusan

Selama membangun 29 profil alat, tiap workbook master dibongkar sel demi sel dan
diadu ke sertifikat yang sudah beredar. Yang cocok diteruskan diam-diam. Yang
**tidak** bisa diputuskan dari kode ditulis sebagai pertanyaan bernomor — sekarang
ada **21 dokumen** berisi **sekitar 213 butir**.

Dokumen ini bukan pengganti keduapuluh satu itu. Ini **peta dan urutan bacanya**,
disusun bukan per alat melainkan **per akibat kalau dijawab lain**.

---

## Cara pakai dokumen ini

Tiap butir di bawah punya empat baris tetap:

| Baris | Artinya |
|---|---|
| **Yang master lakukan** | apa yang tertulis di workbook lab, apa adanya |
| **Yang server lakukan sekarang** | apa yang sudah berjalan di aplikasi hari ini |
| **Yang tertahan** | pekerjaan apa yang berhenti sampai butir ini dijawab |
| **Pertanyaannya** | satu kalimat yang perlu jawaban Bapak/Ibu |

Kalau waktunya terbatas, **Tingkat 1 saja sudah cukup** — tujuh butir, dan
ketujuhnya menyangkut angka yang sudah tercetak di sertifikat pelanggan.

---

## Ringkasan satu layar

| Tingkat | Isi | Butir | Kalau tidak dijawab |
|---|---|---|---|
| **1** | Mengubah **angka** di sertifikat yang sudah beredar | 7 | Arsip sertifikat lama menggantung |
| **2** | Mengubah **pernyataan** akreditasi, satuan, atau metode | 6 | Sertifikat membawa klaim yang belum dipastikan |
| **3** | Kejanggalan metode yang **sudah ditiru** dari master | ±170 | Tidak menahan apa pun — tapi tiruannya belum berestu |
| **4** | **Sudah diputuskan**, disimpan supaya tidak ditanya dua kali | ±30 | — |

> **Yang penting dipahami lebih dulu:** Tingkat 3 yang jumlahnya paling banyak justru
> yang **paling tidak mendesak**. Aturan kerja proyek ini: kejanggalan metode di master
> **ditiru**, tidak dibetulkan diam-diam, supaya angka kami identik dengan sertifikat
> yang sudah Bapak/Ibu terbitkan. Jadi tidak ada yang rusak selama menunggu. Yang
> ditunggu cuma restu bahwa meniru itu memang yang dikehendaki.

---

# TINGKAT 1 — mengubah angka yang sudah tercetak

Tujuh butir ini punya satu ciri sama: **ada sertifikat di tangan pelanggan yang
angkanya akan berbeda kalau jawabannya lain.** Ini yang perlu diputuskan duluan.

---

## 1.1 · Anak Timbangan — sel "Rata-rata STDev" berisi simpangan baku, bukan rata-rata

> Sumber lengkap: [`pertanyaan-lab-anak-timbangan.md` §1](pertanyaan-lab-anak-timbangan.md)
> **Dampak terbesar dari seluruh 213 butir.**

**Yang master lakukan.** Sheet `Deviasi Standard Timbangan` mengukur keterulangan tiap
neraca lewat 6 hari verifikasi × 10 ulangan ABBA. Tiap hari punya simpangan bakunya
sendiri. Untuk Analytical Balance keenamnya, dalam mg:

```
0,0537   0,0497   0,1155   0,1212   0,1117   0,1732
```

Baris di bawahnya berlabel **"Rata-rata STDev"** dan berisi **0,046363 mg**.

Itu bukan rata-rata keenamnya (rata-ratanya 0,104174 mg). **0,046363 mg adalah
simpangan baku DARI keenam simpangan baku itu** — sudah dibuktikan sampai epsilon
mesin untuk **kelima** neraca lab, beda relatif 0 sampai 2·10⁻¹⁶.

**Kenapa ini penting.** Angka itu langsung jadi komponen Repeatability di kedua puluh
budget, dan Repeatability komponen terbesar (0,0464 mg dari u_c 0,0639 mg). Tapi
0,046363 mg **lebih kecil daripada keterulangan hari mana pun** — bahkan lebih kecil
dari hari terbaik (0,0497 mg). Sebaran antar-hari mengukur *seberapa berbeda
keterulangan dari hari ke hari*, bukan *seberapa berulang satu penimbangan*.

Kalau yang dimaksud keterulangan penimbangan, penggantinya gabungan kuadrat harian
(0,112443 mg), dan U95 seluruh sertifikat **hampir dua kali lipat**:

| Titik | U95 terbit | U95 kalau gabungan harian | naik |
|---|---|---|---|
| 100 g | 0,1263 mg | 0,2407 mg | 1,91× |
| 200 g | 0,1291 mg | 0,2422 mg | 1,88× |
| 0,1 g | 0,1223 mg | 0,2387 mg | 1,95× |
| 50 g | 0,1232 mg | 0,2392 mg | 1,94× |

**Satu petunjuk yang mengarah ke tafsir kedua:** derajat kebebasan yang dipakai budget
`vi = 54` ( = 6 × (10−1) ), yaitu derajat kebebasan gabungan **60 pembacaan**. Itu
konsisten dengan "keterulangan penimbangan", **tidak** dengan "sebaran 6 angka" — yang
derajat kebebasannya 5.

**Yang server lakukan sekarang.** Meniru master persis. U95 yang kami terbitkan sama
dengan yang sudah Bapak/Ibu terbitkan.

**Yang tertahan.** Tidak ada pekerjaan yang berhenti. Yang menggantung: **seluruh
sertifikat anak timbangan sejak 29 Mei 2026 mungkin mencetak U95 sekitar separuh dari
yang seharusnya.**

**Pertanyaannya.** Apakah "Deviasi Standar Timbangan" memang dimaksudkan sebagai sebaran
antar-hari, dan apa dasarnya? Kalau yang dimaksud keterulangan penimbangan, arsip
sertifikat anak timbangan perlu ditinjau.

---

## 1.2 · Flowmeter Ultrasonic — satu titik terbit di bawah pita CMC

> Sumber: [`pertanyaan-lab-flowmeter.md` §2](pertanyaan-lab-flowmeter.md)

**Yang master lakukan.** Sel U95 sertifikat berbunyi `K62 = MAX(J60:K61)`, dan `K61`
kosong — `MAX` atas satu angka. Tidak ada lantai CMC sama sekali. Blok titik 3 & 4 punya
rumus lantai, tapi menunjuk sel kosong di area tabel thermohygro.

| Titik | U95 terbit (%OR) | Pita CMC | Status |
|---|---|---|---|
| Totalizer 1 | 1,35105 % | 1,2 % | aman |
| Totalizer 2 | 1,34661 % | 1,2 % | aman |
| Flowrate 1 | 1,89385 % | 0,76 % | aman |
| **Flowrate 2** | **1,04662 %** | **1,2 %** | **di bawah lantai** |

Sertifikat itu mencetak ketidakpastian **0,153 poin persen lebih kecil** dari yang diakui
KAN, dan tidak ada satu pun sel yang memprotes.

**Yang server lakukan sekarang.** Lantai CMC dipasang: `U95 = max(U; CMC% × pembacaan)`.
Flowrate titik 2 naik dari 3,2512388 → 3,7277107 Lpm (tepat 1,200 %OR). Titik yang jatuh
di luar kedua pita **diblokir**, bukan diterbitkan tanpa lantai.

**Alat bantu yang sudah siap pakai** — supaya arsip tidak perlu dibuka satu per satu:

```bash
php artisan flowmeter:audit-cmc                    # seluruh arsip
php artisan flowmeter:audit-cmc --csv=tinjauan.csv # buat ditandatangani
```

Perintahnya **read-only** dan melingkupi **per titik**, bukan per sesi.

**Yang tertahan.** Keputusan atas arsip lama.

**Pertanyaannya.** Sertifikat yang sudah terbit dengan angka di bawah pita — ditarik,
direvisi, atau berlaku maju sejak tanggal keputusan?

---

## 1.3 · Flowmeter Gravimetri — empat titik di bawah pita, di master yang SUDAH divalidasi

> Sumber: [`pertanyaan-lab-flowmeter-gravimetri.md` §1](pertanyaan-lab-flowmeter-gravimetri.md)

**Yang master lakukan.** Sel berlabel `CMC` ada di keempat blok budget dan **semuanya
kosong**, padahal tabel CMC-nya lengkap di `DATABASE!R5:S6` dan cocok persis dengan
lampiran LK-285-IDN.

| Titik | Volume | U95 tercetak | %OR | Pita | Status |
|---|---|---|---|---|---|
| 1 | 140,53 L | 1,06076 L | 0,7576 % | 1,2 % | di bawah lantai |
| 2 | 501,72 L | 1,05570 L | 0,2104 % | 1,2 % | di bawah lantai |
| 3 | 1003,36 L | 1,05965 L | **0,1056 %** | 1,2 % | di bawah lantai |
| 4 | 2498,16 L | 2,11949 L | 0,0848 % | >1991 L | di luar pita |

Titik 3 mengklaim ketidakpastian **sebelas kali lebih baik** dari yang diakui KAN.

**Yang server lakukan sekarang.** Lantai dipasang. Titik 1 naik 1,06076 → 1,68128 L;
titik 2 → 6,00836 L; titik 3 → 12,01516 L.

**Yang tertahan.** Tidak ada di sisi server. Yang menggantung arsip sertifikat lama.

**Pertanyaannya.** Sama dengan 1.2 — ditarik, direvisi, atau berlaku maju?

---

## 1.4 · Micrometer — `MAX()` Excel mengabaikan teks, dan lantainya hilang

> Sumber: [`pertanyaan-lab-micrometer.md` §1](pertanyaan-lab-micrometer.md)

**Yang master lakukan.** Workbook 0–25 mm menerbitkan **U95 = 0,735 µm** padahal pita
terakreditasinya **0,83 µm**. Rantai sebabnya bisa ditelusuri sel demi sel:

1. Satuan alat diisi `inch`, jadi kapasitas 25 dikali 25,4 → **635 mm**
2. Pemilih pita: `IF(G8<=25;"A";…IF(G8<=100;"D";"ISI KAPASITAS"))` → 635 > 100 → `"ISI KAPASITAS"`
3. Pencarian pita gagal, memulangkan teks `"cek range"`
4. Penutupnya `MAX(X17:Z18)` — dan **`MAX()` Excel mengabaikan teks**, jadi yang keluar
   angka telanjang tanpa lantai

Penjaga `IF(X18=0;"";…)` tidak menangkapnya karena `"cek range"` memang bukan `0`.

**Yang server lakukan sekarang.** Sesi yang kapasitasnya di luar keempat pita **tidak
menghasilkan satu pun baris hitungan**. Sempat ditulis "baris tetap terbit tapi U95 nol"
— itu **lebih berbahaya** dan sudah dicabut: baris ber-U95 nol tercetak `± 0,000`, yaitu
klaim pengukuran sempurna.

**Pertanyaannya.**
(a) Sertifikat yang sudah terbit dari workbook 0–25 mm perlu ditinjau?
(b) Apakah ada mikrometer di lab yang kapasitasnya **memang** di atas 100 mm? Kalau ada,
alat itu di luar akreditasi dan sesinya harus ditandai "tidak terakreditasi", bukan
diblokir.

---

## 1.5 · Thermohygrometer — lima baris kelembaban tercetak di bawah kepala kolom °C

> Sumber: [`pertanyaan-lab-thermohygro-satuan.md`](pertanyaan-lab-thermohygro-satuan.md)

**Yang master lakukan.** Sertifikat `0312-CAL-624` punya 10 titik dalam satu tabel dengan
`remark` kosong, jadi kesepuluhnya jatuh ke satu kelompok — dan satuan kelompok diambil
dari baris pertama:

```
Standard (°C) | Unit Under Test (°C) | Correction (°C)
        45,35 |                44,48 |            0,87
        56,15 |                54,52 |            1,63
        48,83 |                49,00 |           -0,17   ← ini %RH
        69,83 |                69,00 |            0,83   ← ini %RH
        88,53 |                89,00 |           -0,47   ← ini %RH
```

Buktinya ada di snapshot: U95 barisan 1–5 = 1,9788, barisan 6–10 = **4,8** — dua angka
yang sudah tercatat sebagai U95 suhu dan U95 RH Thermohygrometer.

**Dua akibatnya.** (1) Pembaca yang memakai kolom sesuai judulnya menyimpulkan 88,53 °C.
(2) Satu tabel cuma mencetak satu U95, yaitu milik baris pertama — jadi **ketidakpastian
kelima baris kelembaban tidak muncul sama sekali di dokumen.**

**Pertanyaannya.** Tabel dipecah dua (°C dan %RH masing-masing dengan U95-nya), atau
tetap satu tabel?

---

## 1.6 · Alat suhu — pasangan `koreksi = 0` DAN `U95 = 0` di titik yang terpakai

> Sumber: [`pertanyaan-lab-suhu.md` A-5](pertanyaan-lab-suhu.md) dan
> [`pertanyaan-lab-tits.md` §9](pertanyaan-lab-tits.md)

Sel kosong yang dibungkus `IFERROR(…;"")` ikut terbaca nol, lalu tercetak sebagai koreksi
nol **berpasangan dengan U95 nol** — yaitu klaim "alat ini tepat sempurna dan kami yakin
sempurna". Aturan proyek untuk kelas kerusakan ini tegas: **tidak pernah ditiru**, titiknya
diblokir dengan alasan yang kebaca.

**Pertanyaannya.** Konfirmasi bahwa memblokir titik seperti ini benar, dan sertifikat lama
yang memuat pasangan nol-nol perlu ditinjau atau tidak.

---

## 1.7 · Height Gauge — pembagi drift `/12` padahal selisihnya dalam HARI

> Sumber: [`pertanyaan-lab-height-gauge.md` §1](pertanyaan-lab-height-gauge.md)

Komponen drift dibagi 12 seolah-olah satuan waktunya bulan, sementara selisih yang
dihitung satuannya hari. Kalau salah, seluruh komponen drift Height Gauge meleset dengan
faktor tetap.

**Pertanyaannya.** `/12` itu konversi hari→bulan yang disengaja, atau salah ketik dari `√12`?

---

# TINGKAT 2 — mengubah pernyataan, bukan angka

Butir-butir ini tidak menggeser satu digit pun, tapi menentukan **apa yang boleh ditulis**
di sertifikat.

| # | Perkara | Di mana |
|---|---|---|
| 2.1 | **Anak Timbangan:** nama formulir berbunyi **(Non KAN)**, tapi kop kertasnya tetap mencetak **LK-285-IDN**. Satu lembar, dua pernyataan yang saling meniadakan | [anak-timbangan §15](pertanyaan-lab-anak-timbangan.md) |
| 2.2 | **Flowmeter Gravimetri:** seluruh sesi Flowrate berjalan di 2,03 dan 9,98 Lpm, sementara pita akreditasi mulai di 75 Lpm — dua puluh sampai tiga puluh tujuh kali di bawah batas. Sertifikatnya tetap membawa LK-285-IDN | [flowmeter-gravimetri §2](pertanyaan-lab-flowmeter-gravimetri.md) |
| 2.3 | **Height Gauge:** status akreditasinya belum dipastikan | [height-gauge §6](pertanyaan-lab-height-gauge.md) |
| 2.4 | **Thermohygro:** footer kertas menulis **Rev.2**, nama berkasnya **Rev.3**. Revisi dokumen terkendali cuma lab yang boleh menyatakan | [suhu-3alat §7](pertanyaan-lab-suhu-3alat.md) |
| 2.5 | **Thermocouple:** sertifikat yang sudah terbit menulis nomor metode milik **TITS** | [suhu-3alat §1](pertanyaan-lab-suhu-3alat.md) |
| 2.6 | **Gas Detector:** baris CMC-nya kosong — belum ada klaim terakreditasi, atau belum diisi? | [gas-detector §3](pertanyaan-lab-gas-detector.md) |

**Yang tertahan di 2.2:** seluruh sesi Flowrate gravimetri. Sampai dijawab, **tidak ada satu
pun titik Flowrate yang bisa disertifikatkan lewat jalur ini.** Ini satu-satunya butir di
seluruh dokumen yang benar-benar memblokir pekerjaan produksi.

---

# TINGKAT 3 — kejanggalan master yang sudah ditiru

Sekitar 170 butir, tersebar di 21 dokumen. **Tidak satu pun menahan pekerjaan.** Semuanya
sudah ditiru persis supaya angka kami identik dengan sertifikat yang sudah beredar, dan
tiap tiruan ditulis terang di kode.

Yang paling sering berulang, dan karena itu paling layak diputuskan sekali untuk semua:

### Pola A — pembagi distribusi yang tidak cocok dengan labelnya
Muncul di **tujuh alat**: label `rect.` tapi selnya membagi √5, √6, atau bahkan `√(√3)`;
pembagi ditulis `1,73` literal alih-alih `√3`; kolom "Divider" tertulis `√3` tapi rumusnya
tidak membagi sama sekali.
> TITS §1, Enclosure §2 & §3, Flowmeter §7, Height Gauge §2, pH §2, Timbangan T3, Gas Detector §2

**Pertanyaan tunggalnya:** apakah pembagi-pembagi ini keputusan metode yang disengaja, atau
warisan salin-tempel yang boleh dirapikan sekali jalan?

### Pola B — `v_eff` tidak dipotong ke bawah sebelum mencari `k`
Excel `TINV(0,05; v_eff)` dipanggil dengan `v_eff` pecahan. GUM meminta pembulatan ke bawah.
Selisihnya kecil tapi sistematis.
> TITS §2, Enclosure §6, Suhu A-2

### Pola C — rujukan ke workbook LAIN lewat cache tautan luar
Angka diambil dari `[n]Sheet!Sel`, yaitu berkas lain yang cache-nya bisa basi. Aturan proyek:
**tidak pernah ditiru** — dihitung dari berkasnya sendiri, selisihnya dicatat.
> Thermocouple Yokogawa (suhu-3alat §8), Timbangan T7, TIDS T1

### Pola D — standar kedaluwarsa saat sesi berjalan
Sertifikat tetap terbit meski standar acuannya sudah lewat masa berlaku.
> Flowmeter §15, Flowmeter Gravimetri §12, Enclosure §8

### Pola E — sel dihitung lalu tidak pernah dipakai
Drift, volume pipa `Vt`, koreksi lingkungan, `Ud` — dihitung rapi, hasilnya nol kali dipakai.
> Flowmeter §11, Gravimetri §5, Anak Timbangan §13, Timbangan T10

Sisanya bersifat per-alat dan sudah dijelaskan di dokumennya masing-masing.

---

# TINGKAT 4 — sudah diputuskan, jangan ditanya ulang

Disimpan supaya tidak berputar. Sekitar 30 butir, yang terbesar:

| Perkara | Keputusan | Tanggal |
|---|---|---|
| PASS/FAIL memakai guarded acceptance ILAC-G8 (\|error\| **+ U** masuk toleransi) | ditetapkan | 14 Jul 2026 |
| Waktu & Frekuensi — 13 butir, 6 diputus "tiru master", 4 "sudah dihitung benar" | ditetapkan | 1–2 Sep 2026 |
| Realtime ditunda, plan Render `free` | ditetapkan | audit 2 Sep |
| Label `direktori` cuma catatan asal-usul | ditetapkan | audit 2 Sep |
| Viscometer — blok 30000 cP dan jumlah desimal sertifikat | terjawab workbook baru | 20 Agu 2026 |
| Flowmeter §1 & §16 (validasi master, metode lampiran) | **gugur** setelah dicek ulang | 10 Sep 2026 |
| Kolom R² blok %T Spectrophotometer | sudah dicetak, pertanyaan tidak lagi menahan | 14 Agu 2026 |

---

# Peta 21 dokumen

Kolom **Butir** dihitung dari judul bernomor di tiap berkas.

| Dokumen | Alat | Butir | Catatan |
|---|---|---|---|
| [anak-timbangan](pertanyaan-lab-anak-timbangan.md) | Anak Timbangan (ke-29) | 24 | §1 dampak terbesar seluruh proyek |
| [suhu](pertanyaan-lab-suhu.md) | TITS + Enclosure | 26 | sengaja menggabungkan ulang dua dokumen lain |
| [flowmeter-gravimetri](pertanyaan-lab-flowmeter-gravimetri.md) | Flowmeter ISO 4185 | 23 | §2 satu-satunya yang memblokir produksi |
| [flowmeter](pertanyaan-lab-flowmeter.md) | Flowmeter Ultrasonic | 17 | 2 butir sudah gugur |
| [timbangan](pertanyaan-lab-timbangan.md) | Timbangan (ke-21) | 14 | tiga workbook, tiga sertifikat standar berbeda |
| [enclosure](pertanyaan-lab-enclosure.md) | Oven/Furnace/Bath/Inkubator | 13 | |
| [waktu-frekuensi](pertanyaan-lab-waktu-frekuensi.md) | Timer, Centrifuge, Tachometer | 13 | **hampir seluruhnya sudah diputus** |
| [micrometer](pertanyaan-lab-micrometer.md) | Micrometer (ke-25) | 11 | 8 masih perlu jawaban |
| [height-gauge](pertanyaan-lab-height-gauge.md) | Height Gauge 600 mm | 10 | |
| [viscometer](pertanyaan-lab-viscometer.md) | Viscometer | 10 | 2 sudah terjawab |
| [tits](pertanyaan-lab-tits.md) | TITS | 9 | tumpang tindih dengan `suhu` |
| [suhu-3alat](pertanyaan-lab-suhu-3alat.md) | Thermocouple, Term. Gelas, Thermohygro | 9 | |
| [tids-workbook](pertanyaan-lab-tids-workbook.md) | TIDS | 9 | |
| [audit-2026-09](pertanyaan-lab-audit-2026-09.md) | lintas alat | 5 | **3 sudah dijawab** |
| [akurasi-kamera](pertanyaan-lab-akurasi-kamera.md) | OCR lembar kerja | 4 | T2 menentukan jalur pindai |
| [data-pelanggan](pertanyaan-lab-data-pelanggan.md) | arsip pelanggan | 4 | P1 sudah dicoret |
| [gas-detector](pertanyaan-lab-gas-detector.md) | Gas Detector | 4 | |
| [conductivity](pertanyaan-lab-conductivity.md) | Conductivity | 3 | kertas lebih tua dari master |
| [ph-dua-master](pertanyaan-lab-ph-dua-master.md) | pH Meter | 3 | dua master, dua-duanya asli |
| [thermohygro-satuan](pertanyaan-lab-thermohygro-satuan.md) | Thermohygrometer | 1 | masuk Tingkat 1 |
| [r2-spektro](pertanyaan-lab-r2-spektro.md) | Spectrophotometer | 1 | sudah tidak menahan |

---

# Formulir keputusan

Cukup dijawab yang Tingkat 1 dan 2. Sisanya bisa menyusul.

| # | Perkara | Jawaban | Paraf |
|---|---|---|---|
| 1.1 | "Rata-rata STDev" Anak Timbangan: sebaran antar-hari (tetap) / keterulangan penimbangan (ganti) | | |
| 1.2 | Arsip Flowmeter Ultrasonic di bawah CMC: tarik / revisi / berlaku maju | | |
| 1.3 | Arsip Flowmeter Gravimetri di bawah CMC: tarik / revisi / berlaku maju | | |
| 1.4a | Arsip Micrometer 0–25 mm: perlu ditinjau? | | |
| 1.4b | Ada mikrometer di atas 100 mm? | | |
| 1.5 | Tabel Thermohygro: pecah dua / tetap satu | | |
| 1.6 | Blokir titik `koreksi = 0` & `U95 = 0`: setuju? | | |
| 1.7 | Pembagi drift Height Gauge `/12`: disengaja / salah ketik | | |
| 2.1 | Anak Timbangan: Non KAN atau LK-285-IDN? | | |
| 2.2 | Flowrate di bawah 75 Lpm: ada pita baru / tidak boleh ber-LK-285-IDN | | |
| 2.3 | Height Gauge terakreditasi atau tidak | | |
| 2.4 | Kertas Thermohygro: Rev.2 atau Rev.3 | | |
| 2.5 | Nomor metode Thermocouple di sertifikat lama | | |
| 2.6 | CMC Gas Detector: belum ada klaim, atau belum diisi | | |
| 3 | Pola A–E Tingkat 3: tiru terus (default) / rapikan sekali jalan | | |

---

*Disusun oleh tim backend SIDIK. Tiap angka di dokumen ini berasal dari pembongkaran
workbook master, bukan dari perkiraan. Detail sel demi sel ada di dokumen yang ditunjuk
tiap butir.*
