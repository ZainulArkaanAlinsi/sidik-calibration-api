# Pertanyaan Lab — Micrometer

Alat ke-25: **Micrometer** (lampiran akreditasi LK-285-IDN no. 34, kelompok
Dimensi — dan yang pertama di kelompok itu).

Sumber: empat workbook master ber-password yang turun 4 Sep 2026 —
`Master_Olah_Data_Micrometer_025mm.xlsm`, `_2550mm`, `_5075mm`, `_75100mm`.

Semua pertanyaan di bawah lahir dari **pembuktian sel demi sel**: reimplementasi
Python diadu ke keempat workbook, **53 nilai** dibandingkan pada toleransi
5·10⁻⁶ (9 komponen budget + `uc` + `veff` + `k` + `U95`, kali empat workbook,
plus rumus drift dari tanggalnya sendiri). Semuanya cocok. **Setiap** selisih
yang muncul sesudah itu tercatat di sini — tidak ada satu pun yang dibiarkan
tanpa penjelasan.

Yang butuh keputusan manajer teknis ditandai **[PERLU JAWABAN]**. Yang sudah
diputuskan sepihak oleh kode (karena perilaku benarnya tidak ambigu) ditandai
**[SUDAH DIHITUNG BENAR]** — tetap perlu dibaca, karena angkanya bergerak.

---

## Ringkasan

| § | Temuan | Kelas | Angka tercetak berubah? |
|---|---|---|---|
| §1 | Sesi 0-25 mm terbit **di bawah lantai CMC-nya sendiri** | Kerusakan | **Ya** — 0,735 → ditolak |
| §2 | Umur drift dari `NOW()`, bukan tanggal kalibrasi | Kerusakan | **Ya** — kecil, tapi tiap kali beda |
| §3 | Satuan sesi 0-25 mm `inch` sementara angkanya milimeter | Data | **Ya** — koreksi −61 mm |
| §4 | `ci` memakai keping pertama tumpukan, bukan total nominal | Kerusakan | Tidak — ~5·10⁻¹⁰ dari `uc²` |
| §5 | Komponen suhu & muai kembar menurut konstruksi | Metode | Tidak — ditiru |
| §6 | `vi = 200` untuk semua komponen Type B | Metode | Tidak — ditiru |
| §7 | Sheet `Perhitungan koef. Sensitivitas` mati & salah | Tidak dipakai | Tidak |

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

**Yang dilakukan kode:** satuan jadi dropdown yang diisi lebih dulu dan
konversinya terjadi **sekali**, di ujung masuk — yang tersimpan di
`raw_measurements` selalu mm. Nominal balok ukur tidak pernah dikonversi,
karena sertifikat balok ukur memang selalu mm apa pun skala mikrometernya.

**Yang perlu diputuskan lab:** apakah mikrometer `IMTE-FQS-015` (Mitutoyo
Analog, PT Unilever) benar berskala **inch** dengan resolusi 0,00001", atau
berskala **mm** 0,001 dan dropdown-nya salah pilih? Dua kemungkinan itu
menghasilkan sertifikat yang berbeda, dan datanya sendiri tidak bisa
membedakan.

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
