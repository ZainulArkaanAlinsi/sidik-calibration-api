# Pertanyaan ke lab — Thermocouple, Termometer Gelas, Thermohygrometer

Tiga workbook master turun 26 Agustus 2026 dan ketiganya sudah direproduksi
sampai digit terakhir (`tests/Unit/Suhu3AlatMasterTest.php`). Yang ada di sini
**bukan** hal yang menahan pekerjaan — semuanya sudah jalan. Yang ada di sini
hal-hal yang **hanya lab yang boleh memutuskan**, dan yang kalau diputuskan
sendiri oleh kode akan menghasilkan angka yang kelihatan sah.

Tiap butir menyebut: apa yang ditemukan, apa yang dipakai sekarang, dan berapa
selisihnya kalau diputuskan sebaliknya.

---

## 1. Nomor metode Thermocouple: sertifikat yang sudah terbit menulis metode TITS

**Temuan.** Sertifikat `0513-CAL-1124` (`SERTIFIKAT!O13`) mencetak
`SIDIK-IK-CAL-0502_Rev.3` — itu metode **TITS** (Temperature Indicator tanpa
Sensor), bukan metode termokopel.

Sebabnya kelihatan dari workbook-nya sendiri: dropdown `Metode_Kalibrasi` di
`DATABASE!A69:C93` berhenti di nomor 24 dan **tidak memuat baris Thermocouple
sama sekali**. Teknisinya memilih yang paling dekat. Workbook Termometer Gelas —
yang daftarnya lebih panjang (sampai nomor 34) — memuatnya di nomor 29:
`Thermocouple → SIDIK-IK-CAL-0529_Rev.2`, dan itu cocok dengan lampiran
akreditasi LK-285-IDN no. 5 (`SIDIK-IK-CAL-0529_Rev.2; ASTM E220-13`).

**Yang dipakai sekarang:** `SIDIK-IK-CAL-0529_Rev.2`. Lampiran akreditasi
dokumen yang mengikat lab, dan nomor metode ikut tercetak di sertifikat yang
diaudit.

**Yang perlu diputuskan lab:**
- Betul bahwa metode termokopel `SIDIK-IK-CAL-0529_Rev.2`?
- Sertifikat `0513-CAL-1124` yang sudah terbit dengan nomor metode TITS — perlu
  diterbitkan ulang, atau cukup dicatat?
- Dropdown `Metode_Kalibrasi` di workbook Thermocouple perlu disamakan dengan
  yang di workbook Gelas (24 baris → 34 baris).

---

## 2. Nomor metode Termometer Gelas: master Rev.1, lampiran Rev.0

**Temuan.** `DATABASE!A95:C95` menulis `SIDIK-IK-CAL-0527_Rev.1`; lampiran
akreditasi menulis `SIDIK-IK-CAL-0527_Rev.0`.

**Yang dipakai sekarang:** `Rev.1`. Nomor IK-nya sama, yang beda cuma nomor
revisi, dan revisi yang lebih baru berarti dokumen labnya sudah diperbarui
sesudah lampiran akreditasi dicetak. Arahnya beda dari butir 1 — di sana yang
berselisih nomor IK-nya sendiri, yaitu metode alat LAIN.

**Yang perlu diputuskan lab:** benar Rev.1 yang berlaku, atau lampiran yang
benar dan workbook-nya yang perlu dibetulkan?

---

## 3. Termometer Gelas: dua baris keterulangan bersebelahan, dua pembagi berbeda

**Temuan.** `PERHITUNGAN U95%`:

```
U26 = N26/SQRT(Q26)    Pengulangan Pembacaan UUT      → ÷√5 = 2,2360
U27 = N27/Q27          Pengulangan Pembacaan STANDAR  → ÷5
```

`Q26` dan `Q27` dua-duanya berisi 5, `S26`/`S27` dua-duanya 4. Jadi ini bukan
pembagi yang sengaja beda arti — satu `SQRT` yang hilang di baris kedua.

**Yang dipakai sekarang:** ditiru apa adanya (÷5), karena sertifikat
`0135-CAL-125` sudah terbit dengan angka itu. Tiap sesi melahirkan catatan audit
`pengulangan_standar_dibagi_n`.

**Selisihnya:** dibetulkan ke ÷√5, U95 sesi contoh naik dari **1,1174 → 1,1268 °C**.
Bukan pembulatan — angka sertifikatnya ikut berubah.

---

## 4. Thermohygrometer: delapan baris memakai `U = N/SQRT(Q)`

**Temuan.** Kolom `Q` (Divisor) sudah berisi pembaginya (`SQRT(3)`), lalu kolom
`U` mengambil akarnya LAGI — pembagi efektifnya 1,3161, bukan 1,7321. Kena di
baris stabilitas & homogenitas chamber ketiga budget, plus baris drift dua
budget.

Baris tetangganya menulis yang benar dengan `Q` yang sama persis (`U21 = N21/Q21`,
`U42 = N42/Q42`), jadi ini bukan konvensi tabel. Baris `Drift Kalibrator` malah
punya perlakuan KETIGA: `Q23 = 0.5*SQRT(3)` lalu `U23 = N23/SQRT(Q23)` — pembagi
efektif 0,9306, jadi komponennya DIPERBESAR. Di budget GEA baris drift yang sama
justru ditulis benar (`U61 = N61/Q61`).

Kelas kesalahan yang sama sudah terdokumentasi di master TITS
(`TitsCalculator::PEMBAGI_AC_PICKUP`).

**Yang dipakai sekarang:** ditiru apa adanya. Tiap grup melahirkan catatan audit
`pembagi_akar_ganda` yang menyebut angka versi dibetulkannya.

**Yang perlu diputuskan lab:** tiga perlakuan untuk satu komponen dalam satu
sheet — mana yang benar?

---

## 5. Thermocouple: budget tanpa komponen keterulangan

**Temuan.** `PERHITUNGAN FC!M23` menghitung `MAX(K23:L36)` — STDEV terbesar
seluruh sesi — dan memajangnya. `AC29 = SUM(AC20:AD28)` hanya menjumlah sembilan
komponen, dan tidak satu pun di antaranya STDEV itu. Angkanya lahir,
ditampilkan, lalu tidak dipakai siapa pun.

Bandingkan: Termometer Gelas punya DUA komponen keterulangan, Thermohygro satu,
TITS satu.

**Yang dipakai sekarang:** ditiru — `standar_deviasi_maks` tetap dihitung dan
dilaporkan, tapi tidak masuk budget. Tiap sesi melahirkan catatan audit
`type_a_tidak_masuk_budget` yang menghitung berapa U95-nya kalau disertakan.

**Catatan:** di sesi contoh seluruh STDEV **nol** (lima pembacaan identik tiap
titik), jadi selisihnya nol dan tidak terlihat. Begitu ada sesi yang
pembacaannya beneran bervariasi, selisihnya muncul.

---

## 6. Thermocouple: pita CMC master punya LUBANG

**Temuan.** `PERHITUNGAN U95%!AC34`:

```
IF(AND(U18>=-20,U18<=150), S5,
IF(AND(U18>151,U18<=400), S6,
IF(AND(U18>401,U18<=600), S7, "cek kapasitas")))
```

Set point 150,5 °C tidak masuk pita mana pun dan `AC34` memulangkan TEKS
`"cek kapasitas"` — yang lalu ikut `MAX(AC33:AI34)`. Sama untuk 400,5 °C.

**Yang dipakai sekarang:** pita dari `calibration_capabilities` (lampiran
akreditasi), yang bersambung tanpa lubang: −20…150, 150…400, 400…600. Di batas
yang bertumpuk (tepat 150 °C) yang menang **pita bawah**, mengikuti rantai `IF`
master — dan itu yang bikin sesi contoh terbit dengan CMC 0,84, bukan 1,5.

**Yang perlu diputuskan lab:** benar batas 150 °C masuk pita bawah?

---

## 7. Thermocouple: U95 probe standar selalu diambil dari probe PERTAMA tipe itu

**Temuan.** `PERHITUNGAN U95%!O21` memilih kolom U95 probe dari TIPE sensornya
saja — kolom 3 untuk Type K (= `TCK-01`), 19 untuk Type N (= `TCN3`), 2 untuk
RTD. Nomor probe yang benar-benar dipakai titik mana pun tidak ikut menentukan.

**Yang dipakai sekarang:** ditiru. Aman di data hari ini, karena kolom
`TCK-01`…`TCK-13` (dan `TCN3`…`TCN12`) berisi angka yang sama persis.

**Yang jadi masalah nanti:** `TCK-14`, `TCK-15`, `TCK-16` bernilai **0** di tabel
U95 — belum pernah diisi. Begitu lab mengisi ketiganya dengan angka yang berbeda
dari `TCK-01`, sesi yang memakai probe itu akan memakai U95 probe lain tanpa satu
pun error.

---

## 8. Tabel Yokogawa Thermocouple datang dari CACHE TAUTAN LUAR

**Temuan.** `Koreksi_Yokogawa` & `U95_Yokogawa` di workbook Thermocouple
menunjuk `'[4]STANDAR KALIBRATOR'` — berkas lain (`Master Olda Enclosure -
Yokogawa.xlsm`) yang tidak ikut dikirim. Nilainya terselamatkan dari cache
tautan luar workbook itu sendiri, dan cache hanya menyimpan sel yang
benar-benar PERNAH DITARIK.

Titik yang dipakai sesi contoh (50, 100, 200 °C) lengkap dan cocok sampai digit
terakhir. Titik lain bisa berlubang.

**Yang dipakai sekarang:** sel yang tidak ada **memblokir titiknya** dengan
alasan yang kebaca — bukan dikoreksi nol seperti master (`IFNA(…,"")`).

**Yang dibutuhkan dari lab:** workbook `Master Olda Enclosure - Yokogawa.xlsm`
aslinya, atau tabel koreksi & U95 Yokogawa dalam bentuk apa pun, supaya tabelnya
utuh.

---

## 9. Sesi contoh Thermohygro: set point 50 °C dibaca ~54,95 °C

**Temuan.** `INPUT DATA!B40 = 50` dengan pembacaan standar 54,91…54,99. Set point
dan pembacaannya selisih ~5 °C — jauh di luar pola empat titik lainnya (yang
selisihnya < 0,2 °C). Rentang akreditasinya sendiri berhenti di 50 °C.

Kemungkinannya dua: set point-nya memang 55 °C dan kolomnya salah ketik, atau
chamber-nya meleset 5 °C.

**Yang dipakai sekarang:** apa adanya. Perhitungan tidak terpengaruh — koreksi
standar dicocokkan ke RATA-RATA pembacaan, bukan ke set point, jadi 54,952
mendarat di baris tabel 50 dan hasilnya sama persis dengan master (56,152 /
54,52 / 1,632).

**Yang perlu diputuskan lab:** angka `50` di kolom set point perlu dibetulkan
jadi `55`? Kalau iya, titik itu di luar rentang akreditasi 15…50 °C dan
sertifikatnya perlu ditandai.

---

## Yang TIDAK ditanyakan, dan kenapa

- **CMC ketiga workbook.** Sudah dicocokkan ke lampiran akreditasi dan
  ketiga-tiganya **sama persis** (Thermocouple 0,84/1,5/3,3 · Gelas 0,58/1,0 ·
  Thermohygro 1,7/4,8). Dijaga `Suhu3AlatMasterTest::test_cmc_workbook_sama_dengan_lampiran_akreditasi`.
- **Label `"Temperature indikator dengan sensor"` di budget Thermocouple.**
  Sudah terbukti label basi lewat tabel CMC-nya sendiri — lihat docblock
  `ThermocoupleProfile`. Bukan pertanyaan, sudah terjawab.
- **Nomor formulir lembar kerja.** Ketiganya `null` dengan alasan yang sama
  seperti TITS dulu: workbook cuma memuat `SIDIK-FM-CAL-2403_Rev. 0`, formulir
  SERTIFIKAT yang dipakai bersama semua alat. Yang dibutuhkan formulir cetaknya,
  bukan keputusan — begitu kertasnya ada, nomornya kebaca dari footer-nya
  sendiri.
