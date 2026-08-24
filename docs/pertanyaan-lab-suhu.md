# Pertanyaan ke Lab — Alat Suhu (TITS & Enclosure)

Status: menunggu jawaban · Disusun 24 Agustus 2026

Dokumen ini **menggabungkan** pertanyaan dua alat suhu yang baru selesai
dibangun, karena beberapa di antaranya ternyata pertanyaan yang SAMA muncul di
dua alat — lebih enak dijawab sekali daripada dua kali:

| | |
|---|---|
| **TITS** (Temperature Indikator Tanpa Sensor) | `Master Olah Data_Suhu_TITS fungsi Measure utk UUT.xlsm` (sesi 01-CAL-625) & `… fungsi Source utk UUT.xlsm` (sesi 0159-CAL-626) |
| **Enclosure** (Oven, Furnace, Bath, Inkubator, Refrigerator) | `…Enclosure_Constant_Yokogawa.xlsm` (sesi 0123-CAL-524) & `…Enclosure_Recorder.xlsm` (sesi 0304-CAL-624) |

**Backend dua-duanya sudah jalan dan sudah diadu ke workbook sampai digit
terakhir.** Semua hal di bawah sudah diberi keputusan sementara supaya
pekerjaan tidak berhenti — yang diminta konfirmasi, bukan perbaikan darurat.

Detail teknis lengkap (nomor sel, rumus, angka pembanding) ada di
`docs/pertanyaan-lab-tits.md` dan `docs/pertanyaan-lab-enclosure.md`.

---

## Yang paling perlu dijawab duluan

Sebagian besar kejanggalan di Enclosure tertutup lantai CMC (U95 yang
dilaporkan = MAX(U hitung, CMC), dan di kedua sesi contoh CMC yang menang). **Di
TITS tidak begitu** — CMC-nya 0,83 sementara U95 hitungnya 0,85, jadi yang
tercetak itu hasil hitung, dan tiap perubahan pembagi langsung kelihatan di
sertifikat.

Yang benar-benar mengubah angka tercetak:

| | Soal | Kenapa mendesak |
|---|---|---|
| **A-1** | Pembagi diakarin dua kali | U95 TITS measure `0,85` → `0,84 °C`. Di atas CMC 0,83, jadi **ikut berubah di sertifikat** |
| **A-2** | `v_eff` dibulatkan atau tidak | U95 TITS measure `0,85` → `0,87 °C`. Sama, di atas CMC |
| **A-5** | Koreksi kalibrator `constant` Type N di 1000 °C | Sel kosong yang diisi nol, **persis di batas atas akreditasi Type N**. Sebelum ditolak, U95 sesi Source turun ~17 % tanpa satu pun peringatan bunyi |
| **B-5** | Kolom U95 Type K Yokogawa (Source) isinya minus | Sekarang ditolak, jadi sesi Type K mode Source jatuh ke CMC. Butuh angka yang benar |
| **C-3** | Peta kolom pembacaan Enclosure | Kolom Sebaran Suhu bergeser ~0,02 °C — dan itu tercetak, tidak tertutup CMC |
| **C-8** | Sensor Acuan Enclosure: nomor terkecil atau posisi tetap di chamber? | Kalau posisi tetap, aturan yang saya pakai sekarang bisa salah diam-diam di seluruh kolom Keseragaman |

**A-1 dan A-2 saling menarik ke arah berlawanan**, jadi enaknya dijawab
bareng: betulkan pembagi saja → `0,84`; seragamkan `v_eff` saja → `0,87`;
dua-duanya → sekitar `0,85` lagi (kebetulan mendekati angka sekarang).

---

# A. Muncul di DUA alat — cukup dijawab sekali

## A-1. Pembagi diakarin dua kali (`√(√3)`, bukan `√3`)

Kejadian di **empat file**, komponen yang beda tapi polanya persis sama:

| Alat | Komponen | Sel |
|---|---|---|
| TITS (dua-duanya) | AC Pick Up | `Q22 = SQRT(3)` lalu `U22 = N22/SQRT(Q22)` |
| Enclosure Recorder | Pengulangan Pembacaan Standar | `Q29 = SQRT(3)` lalu `U29 = N29/SQRT(Q29)` |

Jadi pembaginya `3^0,25 ≈ 1,316`, bukan `1,732` — padahal labelnya `rect.`.
Baris atas-bawahnya di workbook yang sama nulisnya benar (`U20 = N20/Q20`).
Master Enclosure Constant/Yokogawa juga benar di komponen yang sama.

**Dampaknya beda antara dua alat:**

- **TITS** — U95 Measure `0,8534` → `0,8351 °C` kalau dibetulkan ke `√3`.
  CMC TITS `0,83`, jadi dua-duanya di ATAS lantai CMC: **angka yang tercetak di
  sertifikat ikut berubah** (`0,85` → `0,84`). Ini bukan kosmetik.
- **Enclosure Recorder** — efeknya di bawah `0,001 °C` di sesi contoh
  (komponennya kecil, ½ × spread = 0,02 °C) dan tertutup lantai CMC, tapi
  membesar di enclosure yang keseragamannya buruk.

**Sekarang:** ditiru apa adanya, dengan catatan audit yang menyebut hasil versi
`√3`-nya.

> **Yang bener pembaginya atau labelnya, Pak?**

## A-2. `v_eff` nggak dibulatkan ke bawah

Dua master (TITS & Enclosure) cari `k` dari `v_eff` apa adanya. Sepuluh alat
lain di sistem membulatkan ke bawah dulu — aturan GUM G.4.1, dan dulu itu yang
cocok sama lembar pH manual.

**Dampaknya beda jauh antara dua alat**, karena `v_eff`-nya beda skala:

- **TITS** `v_eff` kecil (6,5068 di sesi measure contoh):

  | cara | `k` | U95 | tercetak |
  |---|---|---|---|
  | apa adanya (master TITS) | 2,4015 | 0,8533 | **0,85** |
  | dipotong ke 6 (GUM G.4.1, sepuluh alat lain) | 2,4469 | 0,8694 | **0,87** |

  Dua-duanya di atas CMC 0,83, jadi **yang tercetak ikut berubah**.

- **Enclosure** `v_eff` besar (298 di Recorder, 1437 di Yokogawa) → selisihnya
  praktis nol, `k` beda di desimal ke-5.

**Sekarang:** dua-duanya ditiru (tidak dipotong).

> **Ini sengaja beda dari sepuluh alat lain, atau mau diseragamkan?** Kalau
> diseragamkan, tinggal ubah satu konstanta di tiap alat.

## A-3. Nomor formulir lembar kerja belum ada — dua-duanya

Baik TITS maupun Enclosure, satu-satunya nomor formulir di seluruh berkas itu
formulir **SERTIFIKAT** bersama (`SIDIK-FM-CAL-2403_Rev. 0`), bukan lembar
kerjanya.

**Sekarang:** `kode_dokumen` lembar kerja dikosongkan (null).

> **Mohon nomor formulir lembar kerja TITS dan lembar kerja Enclosure.**

## A-4. Berapa desimal `k` dicetak di sertifikat

Dua master beda perlakuan untuk hal yang sama:

- **TITS** `SERTIFIKAT!O30` berformat `0` desimal → `k = 2,40` tercetak `2`.
  Pembaca sertifikat nggak punya cara bedain itu dari `k = 2` yang sebenarnya.
- **Enclosure** `SERTIFIKAT!P37` justru presisi penuh → `1,9616120828…`

**Sekarang:** TITS ditiru apa adanya (0 desimal), Enclosure saya pakai 2 desimal
supaya kebaca.

> **Berapa desimal `k` yang benar untuk sertifikat?** Enaknya satu aturan buat
> semua alat.

## A-5. Sel `koreksi = 0` DAN `U95 = 0` berpasangan — INI YANG BARU, PAK

**Ini belum pernah saya sampaikan** — ketemunya waktu audit ulang kemarin, dan
ini yang paling berdampak dari semua yang di dokumen ini.

Beberapa baris tabel kalibrator berisi **nol di kolom koreksi dan nol di kolom
U95 sekaligus**. Nol berpasangan begitu nggak mungkin hasil pengukuran: U95 nol
artinya standarnya tanpa ketidakpastian sama sekali. Itu sel kosong yang diisi
nol waktu master dibuat.

Di kalibrator `constant`:

| tipe sensor | titik |
|---|---|
| Type N | **1000 °C**, 1200 °C |
| Type K | 1200 °C |
| Type R | 1200 °C |
| Type S | 1400 °C, 1700 °C |
| RTD | −100 °C |

Dulu saya kira ini nggak berdampak karena titiknya ekstrem dan nggak pernah
kepakai. **Itu keliru.** Type N **1000 °C** itu **batas atas rentang akreditasi
Type N** — bukan titik mustahil, justru titik uji paling wajar untuk tipe itu.

Rantainya lengkap dan diam:

1. sesi Type N mode **Source** ambil U95 kalibrator di titik index tertinggi
   sesi (lihat B-3), dan itu mendarat tepat di sel nol;
2. komponen "sertifikat kalibrator" jadi **0** — dan karena komponennya ADA
   (cuma bernilai nol), peringatan `komponen_tanpa_data` **nggak** bunyi;
3. jarak ke titik tabel juga nol, jadi peringatan `titik jauh dari tabel`
   **nggak** bunyi;
4. U95 sesi turun sekitar **17 %**, dan turunnya lolos di bawah lantai CMC.

Nggak ada satu pun angka yang kelihatan janggal di sertifikat yang terbit.

**Sekarang:** baris ber-nol-berpasangan **dibuang** dari tabel — sama sikapnya
seperti U95 negatif (B-5) dan `#REF!`. Pencarian titik terdekat jatuh ke titik
VALID berikutnya (Type N 1000 → pakai baris 900), dan itu tercermin di
keterangan komponennya.

> **Minta koreksi & U95 kalibrator `constant` yang sebenarnya, terutama Type N
> di 1000 °C.** Kalau kalibratornya memang nggak pernah disertifikasi di titik
> itu, mohon konfirmasi bahwa jatuh ke titik valid terdekat itu perlakuan yang
> benar — alternatifnya sesi yang titik tertingginya melewati titik sertifikat
> terakhir kita tolak saja.

---

# B. Khusus TITS

## B-1. Mode Source ada DUA komponen drift, satu di antaranya sel mati

Baris 20 normal (lookup sesuai tipe sensor). Baris 22 isinya
`='STANDAR KALIBRATOR'!Y8` — alamat MUTLAK ke drift **Constant Type N** (0,38),
padahal sesinya pakai **Yokogawa Type S**. Alamat itu nggak ikut berubah waktu
tipe sensor diganti. Di file Measure baris ini nggak ada sama sekali.

**Sekarang:** ikut disertakan, karena itu yang mereproduksi `Uc = 0,5761` master.
Tiap sesi Source mencetak catatan audit `drift_referensi_mati`.

**Kalau dibuang:** `Uc` turun `0,5761` → `0,3733`, U95 hitung `1,1377` → `±0,75`.
Untuk sesi contoh U95 yang dilaporkan nggak berubah (1,2 — lantai CMC menang di
dua-duanya), tapi untuk sesi lain bisa berubah.

> **Itu komponen tersendiri (angkanya dari mana, dan kenapa `ci = 2`), atau sisa
> copy-paste?**

## B-2. Drift dibagi 2 cuma di Measure

Measure: `VLOOKUP(...)/2` — Source: `VLOOKUP(...)`. Komponen yang sama persis,
dua workbook, dua perlakuan.

> **Yang dibagi 2 itu konversi dari apa, atau Source-nya yang kelupaan?**

## B-3. Ketidakpastian kalibrator diambil beda cara di dua mode

- **Measure:** `MAX` seluruh kolom U95 tipe sensor itu (`O19 = MAX(P32:P49)`) —
  konservatif.
- **Source:** U95 di titik index **tertinggi sesi itu**
  (`O19 = VLOOKUP(R17, …)` dengan `R17 = MAX(P23:P40)`) — bisa lebih kecil.

Cara yang kedua ini yang bikin lubang di **A-5** bisa kejadian.

> **Mana yang jadi patokan lab?**

## B-4. Titik yang jatuh TEPAT di tengah dua titik tabel

Titik 1100 pas di tengah 1000 dan 1200. Rumusnya harusnya ambil 1000
(koreksi −0,15), tapi hasil di selnya 1200 (koreksi −0,20) — dan itu yang
kecetak. Saya ikut hasilnya.

Ini satu-satunya kasus seri di kedua workbook TITS. **Dan Enclosure kebalikannya:** set point 75 °C yang pas di tengah index 50 dan
100, master-nya ambil yang **lebih rendah** (50) — selisih koreksinya 0,0575 °C.
Jadi dua alat suhu, dua arah, untuk keadaan yang sama persis. Ini yang paling
enak dijawab sekalian jadi satu aturan.

Untuk titik yang jauh dari tabel, sekarang koreksi titik terdekat dipakai utuh
tanpa interpolasi; sistem cuma memunculkan peringatan kalau jaraknya lebih dari
50 °C.

> **Kalau seri begitu ambil yang mana — yang lebih rendah atau lebih tinggi?
> Dan titik yang jauh dari tabel sebaiknya diinterpolasi nggak, atau tetap
> diambil koreksi titik terdekat apa adanya?**

## B-5. Kolom U95 Type K Yokogawa di file Source isinya MINUS

`STANDAR KALIBRATOR!N32:N47` berisi −0,06 sampai −0,31 — itu persis deret
**koreksi** dari tabel di atasnya, kesalin ke kolom U95. U95 nggak bisa negatif.

**Sekarang:** ditolak. Sesi Type K mode Source nggak menyusun komponen
"sertifikat kalibrator", mencetak catatan audit, dan jatuh ke lantai CMC.

> **Minta angka U95 Type K Yokogawa untuk fungsi Source yang benar, Pak.**

## B-6. Rentang RLK sensor resistif beda antara dua dokumen

Lampiran akreditasi menulis **−20…800 °C**, `DATABASE!T11` master Excel menulis
**−10…800 °C**.

**Sekarang:** saya pakai yang lampiran (dokumen yang mengikat lab).

> **Mohon konfirmasi mana yang berlaku.**

---

# C. Khusus Enclosure

## C-1. Dua master, dua budget yang beda STRUKTUR — bukan cuma angka

Constant/Yokogawa punya **11 komponen**, Recorder **10**:

| Komponen | Constant/Yokogawa | Recorder |
|---|---|---|
| Efek Radiasi | **0,6 °C** | **0,1 °C** |
| Efek Pembebanan | **20 % × deviasi maks** (dihitung dari data) | **0,1 °C** konstan |
| Konduksi Panas | **0,1 °C** | **tidak ada** |

> Tiga pertanyaan:
> - **Radiasi 0,6 vs 0,1** — dua angka untuk pengaruh fisik yang sama. Mana yang
>   berlaku untuk enclosure secara umum?
> - **Pembebanan** — dihitung dari data (0,04…0,11 °C di sesi contoh) atau
>   angka baku 0,1?
> - **Konduksi Panas** — memang nggak perlu untuk mode Recorder, atau barisnya
>   lupa disalin?

## C-2. Pembagi drift ditulis `1,73` literal, bukan `√3`

Di kedua master Enclosure, komponen drift kalibrator & drift sensor pembaginya
`1,73` diketik sebagai konstanta — padahal komponen persegi lain di workbook
yang sama pakai `SQRT(3)` penuh.

Efeknya ke `Uc` cuma ±0,0002 °C. Bukan salah yang kelihatan, tapi tetap dua
konstanta beda untuk maksud yang sama.

> **`1,73` itu pembulatan baku lab untuk √3, atau seharusnya presisi penuh?**

## C-3. Baris termokopel membuang pembacaan ke-5 dan menggandakan ke-3

Di sheet `PERHITUNGAN FC`, tiap baris termokopel menyalin lima kolom pembacaan
jadi **`[1, 2, 3, 3, 4]`** — kolom ke-3 kepakai dua kali, pembacaan ke-5 nggak
pernah dibaca. Baris **Indikator** justru menyalin kelimanya dengan benar.

**Dampaknya tercetak.** Sesi Yokogawa SP4 sensor No.5: mentah
`[100,24; 100,1; 100,1; 100,24; 100,22]`, master pakai
`[100,24; 100,1; 100,1; 100,1; 100,24]` → AVG Terkoreksi bergeser ~0,02 °C, dan
angka itu masuk kolom Sebaran Suhu sertifikat.

**Sekarang:** ditiru, supaya kolom Sebaran sama dengan sertifikat yang terbit.

> **Disengaja, atau salah salin rumus?** Kalau salah, sertifikat lama
> sebaran-nya ikut berubah sedikit.

## C-4. Blok SET POINT 3 di KEDUA master rusak sel-nya

- **Yokogawa SP3 (75 °C):** sel komponen "Temperature Kalibrator" keisi rumus
  DRIFT (0,035) alih-alih U95 kalibrator (0,24). `Uc` master jadi 0,6234,
  seharusnya 0,6346.
- **Recorder SP3 (67 °C):** koreksi sensor jatuh ke `0` (seharusnya −0,08), dan
  `v_eff` keluar 1620 padahal komponennya sama persis dengan SP1 (`v_eff` = 298).

**Sekarang:** kalkulator menghitung SP3 **dengan benar**, nggak menyalin bug
sel-nya. Yang tercetak tetap sama (1,4 dan 1,5, lantai CMC menang di dua-duanya).

> **Konfirmasi bahwa blok SP3 di kedua master memang salah sel**, supaya
> master-nya bisa dibetulkan.

## C-5. `#REF!` PT100 di 300 °C dan 400 °C

`SENSOR PT100!E16`/`E17` berisi referensi rusak, menjalar ke tabel kalibrator.

**Sekarang:** modul v1 mendukung **Type N & Type K** saja.

> **(a) Enclosure perlu dukung PT100/RTD nggak? (b) Kalau ya, berapa koreksi
> PT100 di 300 °C & 400 °C?** Kemungkinan ada di file `FC Prt Pt100.xlsx` yang
> nggak ikut dikirim.

## C-6. Standar Recorder GL840 berstatus EXPIRED di master yang jadi acuan

Graphtech GL840 (S/N `C305B1470`) sudah lewat masa berlaku ~31 hari. Ini bukan
arsip mati — instrumen ini yang jadi kalibrator utama master Recorder.

> **Ada sertifikat GL840 terbaru yang belum diperbarui, atau memang menunggu
> re-kalibrasi?** Kalau menunggu, sesi Enclosure mode Recorder sebaiknya belum
> jadi acuan sampai VALID lagi.

## C-7. Batas kelengkapan grid: berapa pembacaan & termokopel minimum?

Master selalu **9 termokopel × 5 pembacaan**, jadi berkasnya nggak pernah
menjawab apa yang harus terjadi kalau lembar kerjanya terisi sebagian. Ini bukan
kasus teoretis — teknisi menyimpan lembar setengah jadi, dan set point yang
kurang lengkap tetap punya kolom yang TERCETAK (Sebaran Suhu, Keseragaman,
Kestabilan), bukan cuma U95 yang bisa tertutup CMC.

**Sekarang dua ambang, dua-duanya menolak menghitung** (set point-nya masuk
`belum_dihitung` dengan alasan yang menyebut termokopelnya; sisanya di sesi yang
sama tetap dihitung):

**(a) Minimal 4 pembacaan per termokopel.** Karena peta kolom master menyalin
`[1,2,3,3,4]` (lihat C-3), grid 4 pembacaan hasilnya **persis sama** dengan grid
5 pembacaan — jadi 4 itu batas alami, bukan pilihan. Di bawah 4, kolom yang
hilang cuma bisa ditebak. Kode lama menambalnya dengan mengulang pembacaan
terakhir; pada grid 3 pembacaan itu menggeser rata-rata sensor di orde
**0,06 °C** — cukup mengubah kolom yang dicetak satu desimal.

**(b) Minimal 2 termokopel per set point.** Keseragaman & Variasi itu selisih
antar-POSISI. Dengan satu termokopel keduanya keluar **0,0** — dan `0,0` di
kolom Keseragaman dibaca pelanggan sebagai "sudah dibuktikan seragam", padahal
yang benar "belum diukur".

> Tiga pertanyaan:
> 1. **Minimum termokopel yang sah menurut IK berapa?** Batas 2 sengaja longgar
>    supaya chamber kecil nggak terblokir. Kalau `SIDIK-IK-CAL-0501` mewajibkan
>    9 (atau 5, atau tergantung volume chamber), angkanya tinggal diganti.
> 2. **Pembacaan kurang dari 4: tolak (seperti sekarang), atau hitung apa adanya
>    dengan catatan?**
> 3. **Set point yang cuma terisi baris Indikator** sekarang tetap disimpan tapi
>    nggak dihitung — supaya lembar setengah jadi nggak hilang. Betul begitu?

## C-8. Sensor Acuan disimpulkan, bukan dicatat

Keseragaman diukur relatif ke **Sensor Acuan** = baris pertama grid (baris 23
master). Tapi yang tersimpan cuma nomor termokopelnya — nggak ada satu kolom pun
yang menyatakan "ini acuannya". Jadi acuannya harus DISIMPULKAN.

**Sekarang:** acuan = **nomor termokopel TERKECIL** yang terisi. Dipilih karena
cocok dengan kedua master — baris "Sensor Acuan" di dua-duanya memang nomor
terkecil (Type N mulai no. 3, Type K mulai no. 1).

> Dua pertanyaan:
> 1. **Apakah acuan memang selalu nomor terkecil?** Kalau Sensor Acuan itu
>    sebenarnya **posisi tertentu di chamber** (misalnya selalu titik tengah)
>    yang nomor termokopelnya berganti tiap sesi, aturan "nomor terkecil" bisa
>    salah diam-diam — dan yang salah itu seluruh kolom Keseragaman. Kalau
>    begitu, nomornya perlu jadi **field sesi sendiri**, dicatat teknisi.
> 2. **Kalau termokopel acuan nggak terisi**, sekarang nomor berikutnya yang naik
>    jadi acuan. Betul begitu, atau set point-nya harus ditolak?

## C-9. Baris "Suhu Ruang" diminta ke teknisi, tapi nggak ada yang menampungnya

Lembar kerja Enclosure menyuruh teknisi mengisi **tiga jenis baris** per set
point: 9 termokopel, 1 baris Indikator enclosure, dan 1 baris **Suhu Ruang**
(`bentukLembarKerja()` mengirim `baris_suhu_ruang: true`).

Dua yang pertama masuk perhitungan. Yang ketiga tidak — dan ada dua lapis
masalahnya, yang gampang tertukar:

**(i) Baris Suhu Ruang per set point (5 pembacaan) tidak punya tempat sama
sekali.** Validasi request cuma mengenal `sensor_grid` dan `indikator`. Kalau
layar teknisi nanti mengirim baris itu, angkanya **hilang tanpa pesan apa pun**.

**(ii) Suhu ruang tingkat SESI ada, tapi tidak sampai ke hitungan Enclosure.**
Sesi punya field `suhu_ruang` (satu angka, bukan deret) yang diterima dan
tersimpan, dan buat sepuluh alat lain angka itu masuk budget lewat
`komponenBudget(..., $suhuRuang, ...)`. Enclosure tidak lewat jalur itu — dia
pakai `hitungPerGrup()`, yang tidak menerima suhu ruang sama sekali. Jadi meski
angkanya tersimpan, dia tidak pernah dipakai menghitung apa pun untuk Enclosure.

Artinya jawaban "sudah ada field `suhu_ruang` kok" **tidak menyelesaikan
pertanyaan ini** — yang ada itu satu angka per sesi yang tidak terpakai, bukan
deret per set point yang diminta lembar kerja.

Belum ada korbannya sekarang (layar Enclosure-nya belum dibangun), tapi ini
harus diputuskan sebelum frontend jadi — bukan sesudah teknisi mengisi kolom
yang ternyata nggak kesimpan.

> **Baris Suhu Ruang itu buat apa, Pak?** Tiga kemungkinan, dan tindakannya
> beda-beda:
> 1. **Masuk budget ketidakpastian** (mis. sebagai komponen pengaruh lingkungan)
>    — kalau ya, mohon rumusnya, dan mohon dipastikan: yang dipakai deret per
>    set point, atau cukup satu angka per sesi yang sudah ada?
> 2. **Cuma dokumentasi kondisi**, dicetak di sertifikat tapi nggak dihitung —
>    kalau ya, saya simpan sebagai data dan tampilkan, tapi nggak masuk budget.
> 3. **Peninggalan lembar lama yang sudah nggak dipakai** — kalau ya, barisnya
>    saya hapus dari lembar kerja supaya teknisi nggak mengisi kolom sia-sia.

## C-10. Kolom "Koreksi" sertifikat Enclosure saya isi pakai Keseragaman

Sepuluh alat lain punya satu angka **koreksi per titik** (nilai standar −
pembacaan alat). Enclosure nggak punya itu: yang dilaporkan sebaran SPASIAL
(tiap sensor punya koreksinya sendiri terhadap Indikator), plus tiga angka
kinerja (Keseragaman, Kestabilan, Variasi).

Tapi tabel hasil di database punya kolom `koreksi` dan `error` yang dipakai
bersama sebelas alat. Buat Enclosure saya isi dengan **Keseragaman** (dan
`error` = negatifnya), sebagai wakil "koreksi terbesar" set point itu. Angka
sebaran per sensor yang lengkap tetap tersimpan utuh di jejak audit.

Pilihan ini nggak salah secara hitungan — tapi dia menentukan angka apa yang
muncul kalau ada laporan atau template sertifikat yang membaca kolom `koreksi`
secara umum tanpa tahu ini Enclosure.

> **Keseragaman itu wakil yang tepat, atau sebaiknya kolom itu dikosongkan saja
> buat Enclosure** supaya nggak ada yang salah baca sebagai "koreksi alat"?

## C-11. Hal-hal kecil Enclosure

**(a) CMC seragam di seluruh RLK tiap jenis.** Oven 1,5 (amb–300 °C), Furnace
3,0 (300–1000 °C), Bath 1,2 (0–100 °C), Inkubator 1,4 (15–100 °C), Refrigerator
1,5 (−20–10 °C) — satu CMC untuk seluruh rentang. **Memang seragam per lampiran
akreditasi, atau ada pemecahan sub-rentang yang belum tercermin di Excel?**

**(b) U95 sensor & kalibrator tampak DATAR.** Recorder: Type N seragam `0,83`,
Type K seragam `0,67` di semua kolom suhu. Yokogawa `U95_Sensor` Type N: `0,76`
di index 25 maupun 100. **Memang konstan per spesifikasi sertifikat sensor, atau
kolom lookup yang belum diisi bertingkat?**

**(c) Jumlah set point.** Template Constant/Yokogawa menyediakan 6; sesi contoh
mengisi 4, dan baris SP5/SP6 kosong keluar `#DIV/0!` di sertifikat. **Maksimum
set point yang benar-benar didukung — 4, 6, atau tergantung jenis enclosure?**

**(d) Resolusi Standar di-hardcode.** Budget membaca resolusi kalibrator
(0,1 °C) tanpa peduli kalibrator mana yang dipakai. Kebetulan Constant maupun
Recorder dua-duanya beresolusi 0,1 jadi nggak berdampak sekarang — dicatat saja,
supaya nggak kelewat kalau nanti ada kalibrator beresolusi lain.

**(e) Referensi workbook eksternal terputus.** Master memuat link `[3]`–`[7]` ke
file yang nggak ikut dikirim (nilai cached terakhirnya masih terbaca). **Mohon
file-nya, atau konfirmasi nilai cached sudah final.**

---

# D. Di luar dua alat itu — kepakai sebelas alat

## D-1. Kondisi lingkungan yang tercetak di sertifikat

Master TITS mencocokkan suhu ruang ke titik kalibrasi thermohygro yang **paling
dekat ke suhu terukur** (24,3 °C → titik 29,14, koreksi −0,82 → tercetak
23,38 °C). Sistem memakai **satu titik tetap** per thermohygro (19,37, koreksi
−0,59 → 23,61 °C).

Sesi 01-CAL-625: master **23,38 °C**, sistem **23,61 °C**.

**Sekarang:** belum saya ubah — jalur ini (`KondisiLingkungan` +
`database/data/thermohygro-lab.json`) kepakai sebelas alat, jadi mengubahnya
menggeser angka lingkungan di semua sertifikat, bukan cuma TITS.

> **Mana yang benar — titik terdekat ke suhu terukur, atau satu titik tetap?**
> Kalau titik terdekat, saya ubah sekali di jalur bersama itu.

## D-2. Rumus titik kelembaban di master kayaknya salah sel

Rumus yang nyari titik kelembaban di master (`H16`) itu ngitungnya pakai **suhu
akhir**, bukan angka kelembabannya.

> **Kelihatannya keliru sel juga — mohon konfirmasi.**

---

## Ringkas: mana yang mengubah angka tercetak

| | Mengubah angka yang sudah tercetak? |
|---|---|
| **A-1** (pembagi akar-ganda) | **Ya, di TITS** — `0,85` → `0,84 °C`. Di Enclosure tidak (tertutup CMC) |
| **A-2** (`v_eff`) | **Ya, di TITS** — `0,85` → `0,87 °C`. Di Enclosure praktis nol |
| **A-5** (nol berpasangan Type N @1000) | **Ya** — U95 Source turun ~17 % sebelum diperbaiki |
| **B-5** (U95 Type K minus) | **Ya** — sesi Type K Source sekarang jatuh ke CMC |
| **C-3** (peta kolom pembacaan) | **Ya** — kolom Sebaran Suhu bergeser ~0,02 °C |
| **C-7 / C-8** (kelengkapan grid, Sensor Acuan) | **Ya**, kalau jawabannya beda dari yang saya pakai |
| B-1, B-2, B-3, B-4, C-1, C-2, C-4 | Tidak di sesi contoh — tertutup lantai CMC, tapi berpengaruh di sesi lain |
| **C-9** (baris Suhu Ruang) | Belum — layarnya belum ada. Tapi kalau nggak diputuskan sebelum frontend jadi, angka teknisi hilang diam-diam |
| **C-10** (kolom Koreksi diisi Keseragaman) | Menentukan angka yang muncul di laporan umum yang baca kolom `koreksi` |
| A-3, A-4, B-6, C-5, C-6, C-11, D-1, D-2 | Administratif / kosmetik / belum kepakai |

**Nggak buru-buru, Pak — backend dua-duanya sudah jalan dan sudah diuji.** Yang
penting jawabannya masuk sebelum sertifikat produksi TITS/Enclosure terbit ke
pelanggan, terutama tiga yang di tabel "perlu dijawab duluan" di atas.
