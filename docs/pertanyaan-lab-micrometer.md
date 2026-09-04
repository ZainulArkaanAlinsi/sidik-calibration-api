# Pertanyaan Lab — Micrometer

Alat ke-25: **Micrometer** (lampiran akreditasi LK-285-IDN no. 34, kelompok
**Panjang** — bareng Sieve, Vernier Caliper, dan Dial Indicator).

> Revisi awal dokumen ini menulis "kelompok Dimensi". Salah: `Dimensi` bukan
> salah satu dari sepuluh kelompok lampiran, dan kategori itu sempat lahir jadi
> kartu HANTU di layar pilih kategori — kosong waktu dibuka, sementara alat
> contohnya justru duduk di dalamnya. Sekarang dijaga
> `KategoriAlatIkutLampiranTest`.

Sumber: empat workbook master ber-password yang turun 4 Sep 2026 —
`Master_Olah_Data_Micrometer_025mm.xlsm`, `_2550mm`, `_5075mm`, `_75100mm`.

Semua pertanyaan di bawah lahir dari **pembuktian sel demi sel**: reimplementasi
Python diadu ke keempat workbook, **53 nilai** dibandingkan pada toleransi
5·10⁻⁶ (9 komponen budget + `uc` + `veff` + `k` + `U95`, kali empat workbook,
plus rumus drift dari tanggalnya sendiri). Semuanya cocok. **Setiap** selisih
yang muncul sesudah itu tercatat di sini — tidak ada satu pun yang dibiarkan
tanpa penjelasan.

> **Ada usulan jawabannya.** `docs/analisis-pertanyaan-lab-micrometer.md`
> memuat analisis + rekomendasi untuk §1, §9, dan §11 (yang terakhir temuan
> baru). Isinya USULAN, bukan keputusan — tapi tiga dari sebelas pertanyaan di
> sini sudah punya bahan untuk diputuskan, bukan cuma pertanyaannya.

Yang butuh keputusan manajer teknis ditandai **[PERLU JAWABAN]**. Yang sudah
diputuskan sepihak oleh kode (karena perilaku benarnya tidak ambigu) ditandai
**[SUDAH DIHITUNG BENAR]** — tetap perlu dibaca, karena angkanya bergerak.

**Tambahan 4 Sep 2026:** kertas lembar kerja resmi turun sesudah keempat
workbook dibedah — `SIDIK-FM-CAL-0522.{A,B,C,D}_Rev.1`, satu per rentang.
Kertas itu **menjawab sebagian** yang tadinya mau ditanyakan (§8) dan
**mempersempit** §3, jadi dibaca bareng dokumen ini. Bentuk lembarnya sekarang
mengikuti kertas, bukan sheet `INPUT DATA`; rinciannya di
`docs/perintah-frontend-micrometer.md`.

---

## Ringkasan

| § | Temuan | Kelas | Angka tercetak berubah? |
|---|---|---|---|
| §1 | Sesi 0-25 mm terbit **di bawah lantai CMC-nya sendiri** | Kerusakan | **Ya** — 0,735 → ditolak |
| §2 | Umur drift dari `NOW()`, bukan tanggal kalibrasi | Kerusakan | **Ya** — kecil, tapi tiap kali beda |
| §3 | Satuan sesi 0-25 mm `inch` sementara angkanya milimeter, **dan** pra-evaluasinya berisi kapasitas sepuluh kali | Data | **Ya** — koreksi −61 mm; keterulangan nol |
| §4 | `ci` memakai keping pertama tumpukan, bukan total nominal | Kerusakan | Tidak — ~5·10⁻¹⁰ dari `uc²` |
| §5 | Komponen suhu & muai kembar menurut konstruksi | Metode | Tidak — ditiru |
| §6 | `vi = 200` untuk semua komponen Type B | Metode | Tidak — ditiru |
| §7 | Sheet `Perhitungan koef. Sensitivitas` mati & salah | Tidak dipakai | Tidak |
| §8 | Komponen ke-9 nol menurut konstruksi — kertas tidak memungut suhunya | Metode | Tidak — ditiru |
| §9 | Sertifikat master berformat `0.000` — koreksi tercetak nol di SEBELAS titik | Kerusakan | **Ya** — 0,000 → 0,00027 |
| §10 | Kertas Rev.1 membuang Kerataan/Kesejajaran Muka Ukur, sertifikat masih mencetaknya | Dokumen | Belum — tidak dicetak |
| §11 | Komponen termal menyumbang **0,000%** karena `ci` bersatuan mm di budget µm | Kerusakan | Belum — **kalau dibetulkan, U95 naik DI ATAS CMC** |

---

## §1 — U95 terbit di bawah lantai CMC **[PERLU JAWABAN]**

Workbook 0-25 mm menerbitkan **U95 = 0,735 µm** padahal pita terakreditasinya
**0,83 µm**. Yang diterbitkan lebih kecil daripada yang diakui KAN, dan tidak
ada satu pun sel yang memprotes.

**Rantai sebabnya, sel demi sel:**

1. `INPUT DATA!H15` (satuan alat) = `inch`, jadi `PERHITUNGAN!G8` mengalikan
   kapasitas 25 dengan 25,4 → **635 mm**.
2. `INPUT DATA!F5` memilih pita lewat
   `IF(G8<=25;"A";IF(G8<=50;"B";IF(G8<=75;"C";IF(G8<=100;"D";"ISI KAPASITAS"))))`.
   635 > 100 → `"ISI KAPASITAS"`.
3. `PERHITUNGAN U95%!X18` mencari pita itu, tidak ketemu, memulangkan teks
   `"cek range"`.
4. `X19` menutup dengan `MAX(X17:Z18)` — dan **`MAX()` Excel mengabaikan teks**.
   Jadi yang keluar `X17` telanjang: 0,735 µm, tanpa lantai.

Penjaga `IF(X18=0;"";…)` di depan `X19` tidak menangkapnya, karena `"cek range"`
memang bukan `0`.

**Yang dilakukan kode:** sesi yang kapasitasnya di luar keempat pita tidak
menghasilkan **satu pun baris hitungan** — semua titiknya masuk
`belum_dihitung`, dan peringatan sesi menyebut penyebabnya berikut dugaan
satuannya.

Sempat ditulis sebagai "baris tetap terbit, tapi U95-nya nol" supaya admin bisa
melihat komponennya. Itu **salah dan lebih berbahaya**, ditangkap waktu audit
kode sendiri: baris ber-U95 nol tercetak di sertifikat sebagai `± 0,000` —
klaim pengukuran SEMPURNA, lebih buruk daripada 0,735 µm yang sedang
diperbaiki. Peringatan sesi tidak menahannya, karena `CalibrationValidator`
membungkus peringatan profil jadi temuan tingkat PERINGATAN yang boleh dilewati
admin lewat `abaikan_peringatan`. Yang menahan harus ketiadaan barisnya.

**Yang perlu diputuskan lab:**

- (a) Apakah sertifikat yang sudah terbit dari workbook 0-25 mm perlu ditinjau?
  Kalau sesi contoh `095-CAL-324` (PT Unilever, 14 Mar 2024) pernah dikirim ke
  pelanggan, angka U95-nya lebih kecil daripada yang diakreditasi.
- (b) Apakah ada mikrometer di lab yang kapasitasnya **memang** di atas 100 mm?
  Kalau ada, alat itu di luar akreditasi dan sesinya harus ditandai
  "tidak terakreditasi" — bukan diblokir.

---

## §2 — Umur drift dihitung dari `NOW()` **[SUDAH DIHITUNG BENAR]**

Komponen `Drift standard` memakai
`(0,02 + 0,00025 × L) × ((DATABASE!X11 − DATABASE!W13) / 365)`, dan
**`X11` berisi `=NOW()`**.

Artinya U95 satu sesi **tumbuh tiap kali berkasnya dibuka**, dan sesi yang sama
tidak pernah menghasilkan angka yang sama dua kali. Ini bukan dugaan — keempat
workbook disimpan selang dua menit dan umur driftnya sudah berbeda:

| Workbook | `X11` (saat terakhir dihitung) | Umur (hari) |
|---|---|---|
| 0-25 mm | 2025-12-19 10:06:35 | 695,4212 |
| 50-75 mm | 2025-12-19 10:07:34 | 695,4219 |
| 75-100 mm | 2025-12-19 10:08:01 | 695,4222 |
| 25-50 mm | 2025-12-19 10:08:23 | 695,4225 |

**Yang dilakukan kode:** umur dihitung dari **tanggal kalibrasi sesi** dikurangi
tanggal kalibrasi balok ukur standar (2024-01-24). Maksudnya jelas — "umur
sertifikat standar saat kerja dilakukan" — dan hasilnya bisa diulang tahun
depan.

**Konsekuensi yang ditanggung sadar:** untuk sesi lama, angka drift kita
berbeda dari yang tercetak di master, karena master memakai "kapan file
dibuka" dan kita memakai "kapan alat dikalibrasi".

**Dan ternyata itu menyentuh TIGA dari empat sesi master.** Tabel standar cuma
menyimpan sertifikat balok ukur yang terakhir (24 Jan 2024), sementara:

| Sesi | Tanggal kalibrasi | Umur drift |
|---|---|---|
| `095-CAL-324` (0-25 mm) | 14 Mar 2024 | +50 hari |
| `0106-CAL-1023` (25-50 mm) | 10 Okt 2023 | **negatif** |
| `003-UB.P-11-20` (75-100 mm) | 5 Apr 2023 | **negatif** |
| `002-UB.P-11-20` (50-75 mm) | 5 Des 2020 | **negatif** |

Rancangan pertama kode ini **memblokir** sesi berumur negatif, dan itu keliru —
ketahuan justru waktu diadu ke data lab: tiga dari empat sesi master berhenti
bisa dihitung ulang, dan di produksi itu berarti setiap sesi historis ikut
berhenti. Yang hilang cuma catatan sertifikat lama, bukan pengukurannya.

**Yang dilakukan kode sekarang:** umur negatif → komponen drift **nol**,
alasannya dicatat di `belum_dihitung`, dan sesinya **tetap terbit** — lantai
CMC yang jadi penjaganya. Aman karena drift itu sifat STANDAR (bukan alat yang
dikalibrasi), sumbangannya kecil (0,06 µm dari `uc` 0,44 di sesi 25-50 mm), dan
tanpa drift `uc` justru turun sedikit sehingga yang terbit lantai
terakreditasinya sendiri.

**Yang perlu diketahui lab:** apakah balok ukur `160006` punya sertifikat lain
yang berlaku pada 2020–2023? Kalau ada, tanggalnya perlu masuk supaya sesi
selama itu punya drift yang bersumber, bukan nol.

---

## §3 — Satuan `inch` dengan angka milimeter **[PERLU JAWABAN]**

Sesi contoh 0-25 mm mengisi dropdown satuan dengan `inch`, tapi seluruh
angkanya milimeter. Yang tercetak di sertifikatnya:

| Titik | Standard (D) | Pembacaan (I) | Correction (L) |
|---|---|---|---|
| 2 | 0,09843 inch | 2,5 | **−2,4016** |
| 11 | 0,98426 inch | 25,001 | **−24,0167** |

Koreksi **−24 inch** (≈ −610 mm) pada mikrometer 0-25 mm secara fisik mustahil,
dan tetap terbit rapi.

Penyebabnya kolom standar dan kolom pembacaan tidak diperlakukan sama: nilai
balok ukur datang dari sertifikatnya (selalu mm), sementara pembacaan dikalikan
25,4 karena dropdown-nya `inch`.

**Yang dilakukan kode:** satuan jadi dropdown yang diisi lebih dulu, dan yang
TERSIMPAN di `raw_measurements` angka mentah yang diketik teknisi berikut
satuannya — konversi ke mm terjadi di tempat pakai (`MicrometerMentah::keMm()`),
bukan di ujung masuk. Bedanya bukan gaya: konversi di ujung masuk **tidak
idempoten**, dan simpan-draft dua kali mengalikan 25,4 tiap kali. Nominal balok
ukur tidak pernah dikonversi, karena sertifikat balok ukur memang selalu mm apa
pun skala mikrometernya.

**Yang perlu diputuskan lab:** apakah mikrometer `IMTE-FQS-015` (Mitutoyo
Analog, PT Unilever) benar berskala **inch** dengan resolusi 0,00001", atau
berskala **mm** 0,001 dan dropdown-nya salah pilih? Dua kemungkinan itu
menghasilkan sertifikat yang berbeda, dan datanya sendiri tidak bisa
membedakan.

### Kerusakan kedua di sesi yang sama: pra-evaluasi berisi kapasitas

Blok pra-evaluasi (sepuluh pembacaan berulang yang jadi dasar komponen
keterulangan) berisi **635,0 sepuluh kali** — dan 635 itu 25 × 25,4, yaitu
kapasitas alat yang ikut terkonversi. Bukan sepuluh pembacaan; satu nilai
disalin sepuluh kali.

Akibatnya simpangan bakunya **nol**, jadi komponen keterulangan hilang dari
budget dan U95 jatuh sampai ditutupi lantai CMC — bentuk kegagalan yang sama
dengan resolusi kosong (§ audit no. 3): hasilnya tampak wajar dan tidak ada
error di mana pun.

**Yang dilakukan kode:** sesi ini **tidak ditanam** sebagai sesi contoh.
Menanamnya berarti menerbitkan sesi dengan keterulangan nol; memperbaikinya
berarti mengarang data keterulangan, dan keterulangan itu dasar seluruh budget.
Datanya tetap disimpan utuh di `database/data/sesi-master-micrometer.json`
supaya bisa diadu ulang begitu lab menjawab. Lihat `docs/permintaan-user-7.md`
§21.

**Yang perlu diputuskan lab (tambahan):** apakah masih ada lembar kerja asli
sesi ini, sehingga sepuluh pembacaan pra-evaluasinya bisa dimasukkan kembali —
dan kalau tidak ada, apakah sertifikat `095-CAL-324` yang sudah terbit ke PT
Unilever perlu ditinjau, mengingat komponen keterulangannya nol.

---

## §4 — `ci` memakai keping pertama tumpukan **[SUDAH DIHITUNG BENAR]**

Koefisien sensitivitas suku termal memakai `PERHITUNGAN!F61` — **nilai balok
ukur pertama** di tumpukan titik terakhir, bukan total nominalnya.

Bentuk yang benar terbukti dari master itu sendiri: di tiga dari empat workbook
titik terakhirnya cuma satu keping, dan di ketiganya `F61` **sama persis**
dengan total nominal.

| Workbook | `F61` master | Total nominal titik terakhir | Sama? |
|---|---|---|---|
| 25-50 mm | 49,9999 | 49,9999 | ya |
| 50-75 mm | 74,9999 | 74,9999 | ya |
| 75-100 mm | 100,00012 | 100,00012 | ya |
| 0-25 mm | **6,00016** | **25,00027** (6 + 19) | **tidak** |

**Yang dilakukan kode:** memakai total nominal terbesar di sesi itu.

**Dampaknya nol pada angka yang tercetak hari ini** — komponen suhu dan muai
menyumbang ~5·10⁻¹⁰ dari `uc²` 0,14 — tapi jadi nyata begitu ruangan menyimpang
jauh dari 20 °C.

---

## §5 — Komponen suhu & muai kembar menurut konstruksi **[PERLU JAWABAN]**

Dua komponen budget:

| # | Komponen | `u` | `ci` |
|---|---|---|---|
| 4 | Perubahan suhu terhadap 20 °C | `Δϴ / √3` | `Δα × L` |
| 5 | Koefisien muai thermal | `Δα / √3` | `L × Δϴ` |

Sumbangan keduanya `Δϴ · Δα · L / √3` — **identik secara aljabar**, dan di
keempat workbook memang tercetak angka yang sama persis
(2,2517·10⁻⁵ di 0-25 mm).

Penyebabnya master memakai nilai besaran itu sendiri sebagai
ketidakpastiannya: `u(Δϴ) = Δϴ` dan `u(α) = Δα`. Itu asumsi ketidakpastian
relatif 100 %, yang lazim sebagai penyederhanaan konservatif tapi berarti
sumbangan termal dihitung **dua kali**.

**Yang dilakukan kode:** ditiru apa adanya.

**Yang perlu diputuskan lab:** apakah ini disengaja (dua komponen berbeda:
ketidakpastian suhu dan ketidakpastian koefisien muai) atau tidak sengaja
kembar? Kalau disengaja, `u(Δϴ)` dan `u(α)` seharusnya punya angka sendiri —
bukan menyalin `Δϴ` dan `Δα`.

---

## §6 — `vi = 200` untuk semua komponen Type B **[PERLU JAWABAN]**

Kesembilan komponen Type B diberi derajat kebebasan 200, bukan tak hingga.
Efeknya `veff` menjadi berhingga (303–478 di keempat workbook) dan `k` sedikit
lebih besar dari 1,96 — arah yang **aman**.

**Yang dilakukan kode:** ditiru.

**Yang perlu dikonfirmasi lab:** apakah 200 punya dasar (mis. jumlah pengamatan
historis), atau angka konvensi? Kalau konvensi, tidak ada yang perlu diubah —
cuma perlu tercatat supaya asesor tidak menanyakannya sebagai temuan.

---

## §7 — Sheet `Perhitungan koef. Sensitivitas` mati dan salah **[SUDAH DIHITUNG BENAR]**

Sheet kelima keempat workbook menghitung koefisien sensitivitas sendiri:

```
A3 Spesimen     B3 "Balok Ukur 10mm"      G1 L20    H1 9.99997 mm
D1 CTE          E1 2e-05
E4 = '[2]FC GB'!M71          -> 99.9999      <- tautan luar
E5 = E4 + E1*(D5-D4)*E4      -> 100.0099
F7 = (E5-E4)/(D5-D4)         -> 0.002 mm/°C
```

Dua hal salah sekaligus:

1. **Tautan luar.** `[2]` menunjuk
   `\\192.168.100.70\...\DIMENSI\Mikrometer\4. OLDA micrometer 75-100 mm.xlsx`
   — workbook LAIN, lewat cache yang bisa basi. Keempat workbook memuat
   rujukan yang sama, termasuk workbook 75-100 mm yang merujuk dirinya sendiri.
2. **Spesimennya tidak cocok.** Sheet menyatakan "Balok Ukur 10mm" dan
   `H1 = 9,99997 mm` ada di sel sebelahnya, tapi `E4` menarik 99,9999 mm.
   Koefisiennya jadi 0,002 mm/°C — **sepuluh kali** dari 0,0002 yang sesuai
   dengan balok 10 mm.

**Yang menyelamatkan:** sheet itu **tidak dibaca siapa pun**. Budget
menghitung `ci`-nya sendiri dari `F61`; satu-satunya penyebut nama
"Sensitivitas" di seluruh workbook cuma label teks di `FORM VALIDASI!L10`
("Revisi perhitungan koef. Sensitifitas").

**Yang dilakukan kode:** sheet ini tidak diimplementasikan sama sekali.

**Yang perlu diketahui lab:** sheet mati yang berisi angka salah adalah temuan
audit menunggu terjadi. Sebaiknya dihapus dari master, atau ditandai jelas
sebagai coretan.

---

## §8 — Komponen "selisih suhu mikrometer–balok ukur" nol menurut konstruksi **[PERLU JAWABAN]**

Komponen ke-9 budget adalah `|suhu_uut − suhu_balok|`. Di keempat workbook
master angkanya **nol**, dan bukan kebetulan: kedua suhu itu diisi dengan
bilangan yang sama, dan bilangan itu rata-rata suhu ruangan.

| Workbook | `suhu_awal` | `suhu_akhir` | rata-rata | `suhu_balok` (O31) | `suhu_uut` (P31) |
|---|---|---|---|---|---|
| 0-25 mm | 20,6 | 20,7 | **20,65** | 20,65 | 20,65 |
| 25-50 mm | 20,5 | 20,6 | **20,55** | 20,55 | 20,55 |
| 50-75 mm | 20,5 | 20,6 | **20,55** | 20,55 | 20,55 |
| 75-100 mm | 20,6 | 20,2 | **20,40** | 20,40 | 20,40 |

Empat dari empat. Dan kertas lembar kerja **tidak punya kotak** untuk kedua
suhu itu — yang dipungut cuma suhu ruangan awal & akhir. Jadi keduanya memang
diturunkan, bukan diukur terpisah.

**Yang dilakukan kode:** identitas itu diberlakukan — `suhu_balok = suhu_uut =
(suhu_awal + suhu_akhir) / 2` — dan komponen ke-9 karenanya selalu nol. Dia
tetap ditulis ke budget supaya susunannya cocok baris demi baris dengan master,
bukan dibuang. Ditegakkan oleh
`MicrometerMasterTest::test_suhu_balok_dan_uut_sama_dengan_rata_rata_suhu_ruangan`.

Kenapa tidak dijadikan kotak isian saja "untuk jaga-jaga": dua kotak kembar yang
bisa diisi beda melahirkan komponen ketidakpastian yang tidak bersumber dari
pengukuran apa pun. Teknisi yang mengetik 20,6 dan 20,4 karena merasa itu lebih
teliti akan menaikkan U95 tanpa dasar, dan tidak ada satu pun sel yang memprotes.

**Yang perlu diputuskan lab:** kalau memang mikrometer dan balok ukur pernah
diukur suhunya terpisah — mis. UUT baru datang dari lapangan dan belum
menyesuaikan diri — komponen ini punya arti dan kertasnya perlu tambah dua
kotak. Kalau praktiknya selalu direndam dulu sampai kedua benda satu suhu
ruangan (yang tersirat dari keempat master), komponen ke-9 sebaiknya **dihapus
dari budget**, bukan dibiarkan nol: komponen yang selalu nol melatih pembacanya
melewati kolom itu.

---

## §9 — Sertifikat master mencetak koreksi `0,000` di kesebelas titik **[PERLU JAWABAN]**

Sel `SERTIFIKAT!D18:L28` keempat workbook berformat **`0.000`** — tiga desimal.
Koreksi mikrometer ini besarnya ~0,0003 mm. Jadi sertifikat CETAK master
menampilkan:

| Standard (mm) | Unit Under Test (mm) | Correction (mm) |
|---|---|---|
| 25.000 | 25.000 | 0.000 |
| 27.500 | 27.500 | 0.000 |
| 31.000 | 31.000 | -0.000 |
| … | … | 0.000 |

**Kesebelas koreksinya nol.** Baris `Uncertainty U95%` (`J29`, format `0.000`
juga) menampilkan **0.001 mm** untuk nilai sebenarnya 0,00087 mm.

Nilai di dalam selnya benar — 0,00027000000000043656 dan
0,0008737653585539594. Yang hilang cuma di lapisan tampilan. Tapi yang
diterima pelanggan lembar cetaknya, bukan selnya.

**Yang dilakukan kode:** TIDAK ditiru. Kolom hasil lima desimal (`0,00027`),
U95 lima desimal (`0,00087`). Alasannya sama persis dengan larangan meniru
`IFERROR(…,"")`: kolom koreksi yang seluruhnya nol memberi tahu pelanggan
alatnya sempurna di tiap titik — klaim yang lebih berbahaya daripada angka
yang sedang diperbaiki. Ditegakkan
`MicrometerSertifikatTest::test_desimal_cukup_untuk_koreksi_mikrometer`.

**Yang perlu diputuskan lab:** sertifikat yang SUDAH TERBIT dari master ini
memuat kolom koreksi bernilai 0,000 semua. Perlu ditinjau/diterbitkan ulang,
atau dibiarkan? Dan apakah format selnya di master mau dibetulkan supaya
workbook dan sistem tidak lagi mencetak angka yang berbeda.

---

## §10 — Kertas Rev.1 membuang pemeriksaan Muka Ukur, sertifikat masih mencetaknya **[PERLU JAWABAN]**

Sheet `SERTIFIKAT` master mencetak dua baris di bawah `Note :` —

    Kerataan Muka Ukur dalam Kondisi    : Baik
    Kesejajaran Muka Ukur dalam Kondisi : Buruk

— dan sheet `INPUT DATA` memungutnya lewat dua dropdown.

**Kertas lembar kerja `SIDIK-FM-CAL-0522.{A,B,C,D}_Rev.1` tidak punya kotak
itu.** Yang ada cuma kotak **Catatan** bebas di kaki lembar, bareng
Dikalibrasi Oleh & Diperiksa Oleh. Sudah diperiksa ulang di keempat PDF-nya.

Jadi ada dua dokumen lab yang tidak sejalan: kertas kerja Rev.1 (September
2026) membuang pemeriksaannya, sementara formulir sertifikat
`SIDIK-FM-CAL-2403_Rev. 0` masih menyediakan tempatnya.

**Yang dilakukan kode:** mengikuti KERTAS. Lembar kerja tidak memungutnya,
tidak ada jalur datanya, dan sertifikat tidak mencetaknya. Tidak ada kotak
yang dikarang di lembar yang ikut diaudit, dan tidak ada baris sertifikat yang
diisi dari data yang tidak pernah dipungut siapa pun.

**Yang perlu diputuskan lab, dan ini menentukan:**

1. Pemeriksaan muka ukur memang **dihapus** dari metode → sertifikat perlu
   berhenti menyediakan barisnya juga. Tidak ada perubahan kode.
2. Pemeriksaan itu **masih dilakukan**, cuma kotaknya lupa ikut ke kertas
   Rev.1 → kertasnya perlu Rev.2, dan baru sesudah itu lembar & sertifikat
   kita menambahkannya. Menambahkannya SEKARANG berarti mengarang kotak yang
   tidak ada di formulir terakreditasi.
3. Pemeriksaan dicatat di kotak **Catatan** bebas → perlu diputuskan apakah
   isi Catatan teknisi ikut tercetak di sertifikat. Saat ini TIDAK, dan itu
   berlaku untuk kedua puluh lima alat, bukan cuma Micrometer.

Sampai dijawab, sertifikat Micrometer terbit tanpa dua baris itu.

---

## §11 — Komponen termal lenyap karena satuan `ci` **[PERLU JAWABAN]**

Budget bekerja dalam **µm**, tapi `ci` kedua komponen termal dihitung dengan L
dalam **milimeter**:

```
suhu_ruang        u=0,3175      ci=0,00025      u·ci = 7,94e-5 µm    0,000% dari uc²
koefisien_muai    u=5,77e-6     ci=13,75        u·ci = 7,94e-5 µm    0,000% dari uc²
```

Seluruh `uc` berdiri di atas enam komponen lain. Dengan rumus yang PERSIS SAMA
tapi `ci` dalam µm, sumbangan masing-masing jadi 0,1588 µm dan U95 sesi 25-50 mm
naik **0,872 → 0,978 µm** — yaitu **di atas** pita CMC 0,87 µm.

Ini membalik arah §1: bukan cuma "pernahkah kita menerbitkan di bawah CMC?",
tapi "**apakah CMC-nya sendiri tercapai** dengan metode sebagaimana tertulis?"

**Yang dilakukan kode:** ditiru apa adanya, dan ketiadaan sumbangannya
DITEGAKKAN test (`test_komponen_termal_menyumbang_nol_karena_satuan_ci`) supaya
tidak ada yang membetulkannya diam-diam tanpa membaca akibatnya.

**Belum diubah** karena `u` kedua komponen juga belum benar — master memakai
besaran itu sendiri sebagai ketidakpastiannya. Membetulkan satuan tanpa
membetulkan `u` menukar satu kesalahan dengan kesalahan yang lebih besar.

**Yang perlu dijawab lab, dan keduanya menentukan angkanya:**

1. Berapa ketidakpastian PENGUKURAN suhu di Lab Dimensi (ketelitian thermohygro
   + gradien ruang + beda suhu balok/UUT)? Master memakai Δϴ, simpangan dari
   20 °C, sebagai ketidakpastiannya sendiri.
2. `delta_alpha_per_c = 1e-5` itu α baja (≈11,5e-6/°C) atau δα, beda koefisien
   balok ukur vs rangka mikrometer (lazimnya ~1e-6/°C)? Besarnya sekarang mirip
   α, bukan mirip selisih dua benda baja.

Analisis lengkap + tiga skenario angkanya: `docs/analisis-pertanyaan-lab-micrometer.md` §11.

---

## Lampiran — yang sudah dicocokkan dan COCOK

Supaya jelas apa yang **tidak** dipertanyakan:

- **Pita CMC vs lampiran akreditasi.** `DATABASE!S5:T8` keempat workbook
  (0,83 / 0,87 / 0,91 / 0,91 µm pada 0-25 / 25-50 / 50-75 / 75-100 mm) cocok
  **persis** dengan `database/data/kemampuan-kalibrasi.json` baris no. 34.
- **Tabel balok ukur.** 32 keping, identik di keempat workbook (sudah diadu
  otomatis oleh `docs/skrip/gen-tabel-standar-micrometer.py`, yang menolak
  menulis kalau ada yang menyimpang).
- **Pemotongan `veff`.** `TINV(0,05; veff)` Excel memotong derajat kebebasan
  ke bawah, dan `GumCalculator::agregasiBudget()` sudah melakukannya. Tanpa
  pemotongan, `k` meleset 1,8·10⁻⁶; dengan pemotongan, cocok sampai ~10⁻¹⁴.
- **Rumus drift.** Direproduksi dari tanggalnya sendiri di keempat workbook,
  termasuk pecahan harinya.
- **Tiga gerbang `boleh_terbit`.** Sesi TIDAK menerbitkan satu pun baris
  hitungan kalau: kapasitasnya di luar keempat pita CMC, baris Evaluasi berisi
  kurang dari dua pembacaan, ATAU resolusi alat belum diisi. Ketiganya bentuk
  yang sama — komponen budget yang hilang lalu ditutupi lantai CMC sehingga
  angkanya tampak wajar.
- **Sebelas baris tabel sertifikat.** `SERTIFIKAT!D18:L28` (Standar Reading /
  Unit Under Test / Correction) diadu ke sertifikat terbitan sistem pada
  toleransi 5·10⁻⁶ — nol selisih di ketiga kolom, kesebelas baris. Dijaga
  `MicrometerSertifikatTest`.
- **U95 sertifikat.** Master 0,00087377 mm, sistem 0,00087097 mm. Selisihnya
  PERSIS komponen drift yang di-nol-kan karena sesi contoh mendahului
  sertifikat balok ukurnya (0,06192/√3 = 0,03575 µm dalam kuadratur) — lihat
  §2. Di lima desimal keduanya tercetak `0,00087`.
- **44 nominal pra-cetak kertas vs total tumpukan master.** Sebelas titik kali
  empat rentang, diadu ke total nominal tumpukan balok ukur masing-masing pada
  toleransi 0,06 mm — nol selisih. Generator
  `docs/skrip/gen-tabel-standar-micrometer.py` menolak menulis kalau ada satu
  pun yang meleset.

  Termasuk **titik 3 varian 25-50 mm (31 mm) dan 50-75 mm (51 mm)**, yang
  keluar dari pola +2,6 mm dan sempat disangka salah ketik kertas. Bukan:
  totalnya 30,99997 mm dan 51,00025 mm di master. Yang menentukan nominal
  adalah tumpukan keping yang tersedia di set balok ukur, bukan deret
  aritmetika.
