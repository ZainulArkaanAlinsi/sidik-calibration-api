# Pertanyaan lab — Flowmeter Ultrasonic (Totalizer & Flowrate)

**Sumber:** `1.3 Master Olah Data Flowmeter_Ultrasonic Totalizer (1000-1900L) 2026.xlsm`
dan `Master olda Ultrasonic Flowrate (100-300 lpm) 2026.xlsm` — satu order yang sama,
dikalibrasi 2 dan 3 Februari 2026.
**Untuk:** Manajer Teknis Lab.
**Dari:** tim backend SIDIK, 8 September 2026.

Tujuh belas butir di bawah **tidak menahan** alat ke-27 & ke-28 masuk sistem. Seluruh
angka sesi contohnya sudah direproduksi sampai 5·10⁻⁶ terhadap kedua workbook
(`FlowmeterMasterTest`). Yang diminta di sini keputusan **metode**, yang bukan hak kami
untuk mengambil.

Tiap butir menyertakan angkanya, jadi bisa diputuskan **tanpa membuka Excel**.

Urutannya bukan urutan penting. Yang paling mendesak **§1**, **§2**, dan **§16**.

> Identitas pelanggan sengaja tidak ditulis di dokumen ini — repositori API bersifat
> publik. Sesi yang dimaksud bisa ditelusuri dari nomor order internal lab.

---

## §1 — ~~Kedua master BELUM DIVALIDASI~~ · **GUGUR 10 Sep 2026**

> **GUGUR.** Butir ini benar untuk kedua master **UFM**, dan tetap benar. Yang
> membatalkannya sebagai pertanyaan: dua workbook master **gravimetri** yang
> turun 10 Sep 2026 mengukur alat yang sama, besaran yang sama, dan pita CMC yang
> sama — dan `FORM VALIDASI`-nya punya **23 baris revisi (Totalizer)** serta
> **21 baris (Flowrate)** sejak April 2023, dengan baris terakhir 21 Mei 2026
> terisi lengkap: PIC `NR`, CHECK `AM`, **VALIDATION `AM`**.
>
> Jadi jawabannya bukan "tunggu validasi", melainkan "**jalur produksinya yang
> lain**". Varian gravimetri sekarang jadi bawaan; varian UFM ini tetap bisa
> dihitung ulang supaya sertifikat yang sudah terbit tidak bergeser, tapi sesi
> yang memilihnya melahirkan peringatan yang menyebut status validasinya.
> Lihat `docs/pertanyaan-lab-flowmeter-gravimetri.md` §14.

<details><summary>Isi butir aslinya</summary>


`FORM VALIDASI` kolom `VALIDATION` (`J7`) dan `Position` (`K8`) **kosong di
kedua workbook**. Yang terisi cuma `PIC` (`DR`) dan `CHECK` (`NR`).

Bandingkan master Height Gauge, yang `J7 = 'AM'` (Technical Manager).

| Workbook | Modified | Detail | PIC | CHECK | VALIDATION |
|---|---|---|---|---|---|
| Totalizer | 26 Jan 2026 | Creating All Sheets | DR | NR | *(kosong)* |
| Flowrate | 28 Jan 2026 | Creating All Sheets | DR | NR | *(kosong)* |
| Flowrate | 20 Mei 2026 | Merubah all budget ketidakpastian; menambahkan stdev untuk UUT; menambahkan keterangan spek pipa | NR | NR | *(kosong)* |

**Yang kami lakukan:** diimplementasikan apa adanya, dan `peringatanSesi()` kedua
profil **selalu** memunculkan catatan bahwa angkanya berasal dari master yang belum
lolos validasi internalnya sendiri. Admin harus melewatinya secara sadar.

**Pertanyaan:** apakah kedua master ini sudah boleh dipakai menerbitkan sertifikat, dan
kalau sudah — siapa yang menandatangani kolom VALIDATION-nya?

---

</details>

---

## §2 — U95 terbit DI BAWAH pita CMC terakreditasi · **prioritas satu**

Sel U95 sertifikat blok titik yang benar-benar dipakai berbunyi:

```
K62 = MAX(J60:K61)      ← K61 KOSONG
```

`MAX` atas satu angka. Tidak ada lantai CMC sama sekali. Blok titik 3 & 4 punya rumus
lantai, tapi menunjuk `DATABASE!S42`/`S43` — sel di area tabel thermohygro, kosong —
dan rumus itu bahkan membaca `D63` (Standar Terkoreksi **Titik 1**) dari dalam blok
Titik 3.

Akibatnya, terukur di sesi contoh:

| Titik | U95 terbit (%OR) | Pita CMC | Status |
|---|---|---|---|
| Totalizer 1 | 1,35105 % | 1,2 % (78–1991 L) | di atas lantai — aman |
| Totalizer 2 | 1,34661 % | 1,2 % | aman |
| Flowrate 1 | 1,89385 % | 0,76 % (75–191 Lpm) | aman |
| **Flowrate 2** | **1,04662 %** | **1,2 % (190,6–519,4 Lpm)** | **DI BAWAH LANTAI** |

Sertifikat yang terbit dari workbook Flowrate mencetak ketidakpastian **0,153 poin
persen lebih kecil** dari yang diakui KAN, dan tidak ada satu pun sel yang memprotes.

**Yang kami lakukan:** lantai CMC DIPASANG — `U95 = max(U; CMC% × pembacaan)`. Flowrate
titik 2 naik dari **3,2512388 → 3,7277107 Lpm** (tepat 1,200 %OR). Tiga titik lain tidak
bergerak. Titik yang jatuh di luar KEDUA pita **diblokir**, bukan diterbitkan tanpa
lantai. Arahnya ditegakkan `FlowmeterLantaiCmcTest`.

**Daftarnya sudah bisa diambil sendiri.** Supaya butir ini tidak perlu menunggu
seseorang membuka arsip satu per satu:

```
php artisan flowmeter:audit-cmc                       # seluruh arsip
php artisan flowmeter:audit-cmc --org=1               # satu lab saja
php artisan flowmeter:audit-cmc --csv=tinjauan.csv    # buat ditandatangani
```

Perintahnya **read-only** dan melingkupi **per TITIK**, bukan per sesi — satu sesi bisa
punya tiga titik yang aman dan satu yang di bawah pita, persis yang terjadi di sesi
contoh. Tiga temuan yang dibedakan:

| Temuan | Artinya |
|---|---|
| `di_bawah_cmc` | sertifikatnya mengklaim ketidakpastian **lebih baik** dari yang diakui KAN |
| `di_luar_pita` | sertifikatnya membawa nomor lingkup LK-285-IDN untuk pengukuran yang **tidak** diakreditasi |
| `jarak_tabel_NNpct` | koreksi titik tabel yang jauh dipakai utuh (lihat §4) |

Keluarannya **bahan tinjauan, bukan daftar penarikan**: tiap baris masih perlu dinilai —
sertifikatnya sudah di tangan pelanggan atau belum, dan dengan U yang berlantai CMC
apakah pernyataan kesesuaiannya bisa berbalik.

Presedennya `micrometer:audit-cmc`, yang lahir dari persoalan yang sama persis di alat
ke-25.

**Pertanyaan:** sertifikat yang sudah terbit dengan angka di bawah pita — perlu ditarik
atau direvisi?

---

## §3 — Totalizer belum ikut revisi 20 Mei 2026

Flowrate punya **9** komponen budget, Totalizer **8**. Bedanya komponen *"Pengulangan
Pembacaan UUT"* (`PERHITUNGAN FC!Q28`, `MAX` simpangan baku tiap ulangan atas ketiga
durasinya), yang lahir dari revisi 20 Mei 2026 dan belum masuk workbook Totalizer.

**Yang kami lakukan:** ditiru masing-masing apa adanya — 8 dan 9. Menambahkan komponen
ke-9 ke Totalizer berarti mengubah U95 yang sudah tercetak di sertifikat pelanggan.

**Pertanyaan:** apakah Totalizer memang menunggu revisi yang setara, atau memang tidak
perlu komponen itu?

---

## §4 — Pencocokan tabel standar TERDEKAT, bukan interpolasi

`INDEX(MATCH(MIN(ABS(tabel − nilai))))` memungut baris **terdekat**, lalu koreksinya
dipakai utuh. Di Totalizer tabelnya rapat dan pergeserannya kecil. Di Flowrate tidak:

| | nilai |
|---|---|
| Rata-rata pembacaan standar | **309,739 Lpm** |
| Titik tabel yang dipungut | **236,147 Lpm** (jarak **23,8 %**) |
| Koreksi yang dipakai | −2,911 Lpm |
| Interpolasi linear (236,147; −2,911)–(506,822; −6,037) | −3,761 Lpm |
| Selisihnya | 0,85 Lpm — **22 % dari deviasi yang dilaporkan** |
| Deviasi terbit | −3,815 Lpm (interpolasi: −4,665 Lpm) |

**Yang kami lakukan:** pencocokan terdekat **ditiru**, tapi ditambah **peringatan sesi**
kalau jarak ke titik tabel terdekat melebihi **10 %** dari bacaan — ambang itu usulan
kami, bukan keputusan lab. Sesi contoh Flowrate memicu peringatan ini.

**Pertanyaan:** ambang berapa yang benar? Dan apakah interpolasi seharusnya dipakai?

---

## §5 — Tabel standar Flowrate cuma TIGA titik

`std_flowrate` (`STANDAR KALIBRATOR!Q10:U18`) menyapu 9 baris, **3 terisi**:
100,185 / 236,147 / 506,822 Lpm — untuk pita terakreditasi 75–519,4 Lpm.

Jarak antar-titik 136 Lpm dan 271 Lpm. Itu yang membuat §4 menggigit.

`std_totalizer` lebih rapat: 100,168 / 506,648 / 1013,852 / 1919,692 L.

**Pertanyaan:** apakah ada sertifikat UFM Krohne dengan titik yang lebih rapat?

---

## §6 — Tetapan yang BEDA antar-varian untuk komponen yang SAMA

| Komponen | Totalizer | Flowrate |
|---|---|---|
| `vi` ketidakpastian pengukuran temperature fluida | **2** | **50** |
| Pembagi ketidakpastian cross sectional area | **2** | **1,73** |

Keduanya komponen yang sama, dari besaran yang sama, dengan distribusi tertulis yang
sama (`rectangular` untuk cross-sectional).

**Yang kami lakukan:** ditiru masing-masing.

**Pertanyaan:** mana yang benar?

---

## §7 — Pembagi `1,73` alih-alih `√3`

Komponen resolusi, velocity profile, geometry factor, dan drift memakai pembagi
**`1,73`** yang diketik telanjang, sementara komponen pengulangan di sheet yang **sama**
memakai `=SQRT(3)` sungguhan (1,7320508…).

Selisihnya 0,12 %. Pembulatan ke bawah membuat `u` sedikit **lebih besar** — arah yang
aman.

**Yang kami lakukan:** ditiru (`1,73`), disimpan sebagai konstanta bernama di
`tabel-standar-flowmeter.json`.

---

## §8 — `π` ditulis `3,14`

`PERHITUNGAN U95%!D41` (Totalizer) / `D40` (Flowrate): `π : 3,14`, bukan `PI()`.

| | A (mm²) |
|---|---|
| `3,14` (master) | 1674,0850340 |
| `PI()` | 1674,9250… |

Selisih 0,05 %. `A` cuma masuk lewat `ci` komponen cross-sectional yang sumbangannya
kecil, jadi U95 nyaris tidak bergerak.

**Yang kami lakukan:** ditiru.

---

## §9 — `ci` komponen suhu: dari mana `0,00021`?

```
ci_suhu = STD_terkoreksi × 0,00021 / ρ²
```

Angka `0,00021` tidak bersumber di sel mana pun — dia diketik langsung di dalam rumus,
di kedua workbook. Dimensinya juga tidak jelas: kalau dia koefisien muai volumetrik air
(satuan 1/°C, dan 2,1·10⁻⁴ memang besaran yang masuk akal untuk air ~25 °C), maka
pembagian dengan `ρ²` tidak punya penjelasan dimensional.

**Yang kami lakukan:** ditiru apa adanya, disimpan sebagai
`konstanta.koefisien_muai_air_per_c`.

**Pertanyaan:** apa acuan angka itu, dan kenapa dibagi `ρ²`?

---

## §10 — `0,11 %` dan `0,3 %` tidak bersumber

Komponen **velocity profile** (`0,11 % × STD_terkoreksi`) dan **geometry factor**
(`0,3 % × STD_terkoreksi`) diketik langsung di rumus, tanpa rujukan di workbook mana
pun. Keduanya sumbangan yang besar:

| Titik | u(velocity) | u(geometry) | uc |
|---|---|---|---|
| Totalizer 1 | 1,0983 L | 2,9955 L | 6,8068 L |
| Flowrate 2 | 0,3375 Lpm | 0,9205 Lpm | 1,6331 Lpm |

Di Totalizer titik 1, geometry factor menyumbang **44 %** dari `uc²`.

**Pertanyaan:** dari standar/publikasi mana kedua angka itu? (Untuk clamp-on UFM,
angka sebesar ini biasanya bergantung path configuration — lihat §13.)

---

## §11 — Sheet drift tersembunyi dihitung, tapi TIDAK dipakai

Sheet tersembunyi:

- Totalizer: `Drift Yokogawa-TC Type K`
- Flowrate: `Drift Timer Software Flowmeter` **dan** `Drift Yokogawa-TC Type K`

Isinya rekaman rekalibrasi standar dengan `u(δmD) = 0,5·(Cmax − Cmin)/√3`, dan hasilnya
tercatat rapi (`STANDAR KALIBRATOR!V33 = 0,06 °C`).

**Tidak satu pun dipakai budget yang terbit.** Komponen "Drift UFM Standar" justru
memakai `1 % × U_standar`.

**Pertanyaan:** mana yang berlaku? Kalau yang tersembunyi, komponen drift-nya berubah;
kalau `1 %`, sheet itu sebaiknya dihapus supaya tidak dikira sumber.

---

## §12 — `Ut-water` menunjuk sel KOSONG (temuan baru)

```
Totalizer  I24 = 'PERHITUNGAN FC'!Q52 - 'PERHITUNGAN FC'!Q54
Flowrate   I24 = 'PERHITUNGAN FC'!P53 - 'PERHITUNGAN FC'!P55
```

Blok suhu air Totalizer berhenti di kolom `P`; kolom `Q` **di luarnya**. Blok suhu
Flowrate memakai kolom `D/F`, `I/J`, `M/O`; kolom `P` **di luarnya**. Jadi di kedua
workbook suku itu **selalu nol**, padahal labelnya sendiri berbunyi `(Tmax−Tmin)Water`.

| | master | dihitung benar |
|---|---|---|
| `Ut-water` | 0 | 0,0288675 °C |
| `U_temperature` | 0,2780288 °C | **0,2795234 °C** |

**Yang kami lakukan:** **dibetulkan** — dihitung dari suhu air sesi yang sebenarnya.
Arahnya aman (U membesar). Efeknya di sesi contoh ~1·10⁻⁷ relatif pada U95.

**Pertanyaan:** konfirmasi bahwa maksudnya memang `(Tmax − Tmin)` seluruh suhu air sesi.

---

## §13 — Material pipa, jenis fluida, path configuration, liner

Keempatnya **dipungut kertas** `SIDIK-FM-CAL-0538`, dan:

- tidak ada di `INPUT DATA` workbook mana pun;
- sertifikat Flowrate punya **labelnya** (`B19` Material of pipe, `B20` Outside
  Diameter, `B21` Thickness of Pipe, `B22` Methode UFM Clamp On — hasil revisi 20 Mei)
  tapi **sel isinya kosong, tanpa rumus**;
- sertifikat Totalizer tidak punya labelnya sama sekali.

**Yang kami lakukan:** keempatnya dipasang di lembar kerja, disimpan di
`spesifikasi_alat.flowmeter`, dan **dicetak di sertifikat**. Path configuration jadi
satu PILIHAN (Z / V / W), bukan tiga centang — tiga boolean yang saling meniadakan
tidak bisa divalidasi. **Tidak** masuk budget.

**Pertanyaan:** apakah salah satunya seharusnya mempengaruhi ketidakpastian? Z vs V vs W
path mengubah panjang lintasan akustik, dan itu biasanya masuk lewat velocity profile
(§10).

---

## §14 — `k` yang tercetak cuma milik Titik 1

Kedua sertifikat mencetak SATU baris:

```
The Uncertainty is taken at a Confidence Level 95 % and Coverage Factor ( k ) =
    Q29 = 'PERHITUNGAN U95%'!J59        ← k TITIK 1
```

Padahal tiap titik punya `veff` dan `k` sendiri:

| Titik | veff | k |
|---|---|---|
| Flowrate 1 | 18,934 | **2,1009** |
| Flowrate 2 | 78,678 | **1,9908** |

Beda **5,5 %**, dan yang tercetak 2,1009 untuk keduanya.

**Yang kami lakukan:** `k` disimpan dan dicetak **per titik**. Sertifikat mencetak
`Coverage Factor ( k ) ≈` (bukan `=`) waktu nilainya berbeda antar-titik — mekanisme
yang sudah ada di repo untuk alat lain.

---

## §15 — Standar kedaluwarsa saat sesi berjalan

`INPUT DATA!K3` kedua workbook memvonis **`ONE OR MORE STANDARD EXPIRED`**, dan
sertifikatnya tetap terbit.

Pada snapshot yang kami terima:

| Standar | Sisa hari |
|---|---|
| Ultrasonic Flowmeter (Krohne) | +20,5 |
| Temperature Calibrator (Yokogawa) | +24,5 |
| Thermocouple Type K | +49,5 |
| Digital Caliper | +6,5 |
| **Timer (Flowrate)** | **−84,5 — EXPIRED** |

Perhitungannya juga dari `DATABASE!X12 = NOW()`, jadi vonisnya berubah tiap kali
berkasnya dibuka.

**Yang kami lakukan:** dihitung dari **tanggal kalibrasi sesi** (bisa diulang), dan
standar kedaluwarsa jadi **peringatan sesi** yang harus dilewati admin secara sadar —
bukan pemblokir, karena menjadikannya pemblokir mengubah kebijakan lab.

**Pertanyaan:** apakah standar kedaluwarsa seharusnya menahan penerbitan?

---

## §16 — ~~Metode di lampiran vs metode di kertas~~ · **GUGUR 10 Sep 2026**

> **GUGUR — dan jawabannya ditemukan, bukan diputuskan.** Butir ini bertanya
> kenapa lampiran akreditasi menyebut *static weighing method* (ISO 4185 / NIST
> SP 250) sementara yang dikerjakan perbandingan langsung dengan UFM.
>
> Jawabannya: **static weighing method-nya ada, di workbook lain.** Master
> gravimetri yang turun 10 Sep 2026 menulis judulnya sendiri di
> `PERHITUNGAN FC!B74`: `ISO 4185`, `Laju alir masa`. Bukan dua metode untuk satu
> alat yang saling bertentangan — dua metode yang memang dipakai lab, dan yang
> disebut lampiran akreditasi adalah yang gravimetri.
>
> Yang TERSISA dari butir ini dan pindah ke dokumen baru: nomor Instruksi Kerja
> masih berselisih antar-sheet (`INPUT DATA` menulis Rev.6, `SERTIFIKAT` mencetak
> **Rev.7**, lampiran menyebut Rev.4). Lihat
> `docs/pertanyaan-lab-flowmeter-gravimetri.md` §Butir kecil.

<details><summary>Isi butir aslinya</summary>


| Sumber | Metode |
|---|---|
| Lampiran akreditasi no. 30 & 31 | `SIDIK-IK-CAL-0528_Rev.4; ISO 4185:1980; NIST SP 250 (Static weighing method)` |
| `INPUT DATA!X11` kedua master | `SIDIK-IK-CAL-0528_Rev.6` |
| Judul kertas `SIDIK-FM-CAL-0538` | *"Perbandingan Langsung dengan UFM"* |

Lampirannya menyebut **static weighing method**; yang dikerjakan **perbandingan langsung
dengan flowmeter ultrasonik**. Itu metode yang berbeda.

Sisa metode penimbangan masih terlihat di master Totalizer: blok **Empty Container
Weight** (`INPUT DATA!B35:P37`) dan label *"Readings of Weighing Result Standard"*
(`B50`) — dan `G50` di sebelahnya sendiri bertuliskan **`'TIDAK DIPAKAI'`**, karena
angka yang diketik di situ sebenarnya pembacaan totalizer UFM.

**Yang kami lakukan:** `kodeMetode()` memakai nomor lampiran (`Rev.4`), sementara nomor
yang benar-benar tercetak di baris `metode` tiap titik diambil dari baris kemampuan
(`calibration_capabilities.metode`). Blok penimbangan tidak dipungut sama sekali.

**Pertanyaan:** apakah perbandingan langsung dengan UFM tercakup akreditasi yang
sekarang? **Butir ini yang paling mahal kalau salah, dan tidak boleh diputuskan di
kode.**

---

</details>

---

## §17 — S/N Digital Caliper beda antar-sheet

| Sel | Isi |
|---|---|
| `DATABASE!T17` | `LPI-0368` |
| `STANDAR KALIBRATOR!D39` | `LPI-0368` |
| `PERHITUNGAN U95%!F13` | **`CLP-130990`** |

Dua sheet menyebut satu S/N, satu sheet menyebut yang lain. Yang dipakai jalur hitung
`DATABASE!T17`, jadi angkanya tidak terpengaruh — tapi blok "Standard used" sertifikat
bisa mencetak S/N yang salah tergantung sel mana yang dirujuk.

**Yang kami lakukan:** dipungut yang dipakai jalur hitung (`LPI-0368`).

**Pertanyaan:** mana S/N caliper yang benar?

---

## Lampiran — yang TIDAK jadi pertanyaan

Diperiksa dan terbukti tidak bermasalah, dicatat supaya tidak diperiksa ulang:

- **Makro.** Kedua `.xlsm` punya `xl/vbaProject.bin` sungguhan, tapi isinya kosong:
  `Sub DropDown7_Change()` dan `Private Sub OptionButton1_Click()` tanpa badan. Tidak
  ada logika tersembunyi.
- **Tautan luar.** Totalizer 2, Flowrate 3. Semuanya menunjuk workbook flowmeter lain
  dan satu workbook Oven; tidak satu pun menyuplai angka ke jalur hitung.
- **Pita CMC.** `DATABASE!R5:S6` kedua workbook **cocok persis** dengan lampiran
  akreditasi no. 30 & 31 di `database/data/kemampuan-kalibrasi.json`.
- **Tabel standar.** `std_totalizer` dan `std_flowrate` identik di kedua workbook, baris
  demi baris; generatornya menolak menulis kalau berbeda.
- **Konversi satuan yang rusak.** `DATABASE!S26` Flowrate (kg/min) = `#REF!`, `S25`
  (kg/h) = `=S23/1000` (0,0166667 — itu m³/h dibagi seribu, salah dimensi), dan `S25`
  Totalizer (kg) berisi teks `'perlu dibagi densitas'`. Tidak ditiru: satuan berbasis
  massa dikonversi lewat densitas UUT, dan tanpa densitas titiknya **diblokir**.
- **`DATABASE!V14`** berisi teks `"Unit Under Test"` (menunjuk `STANDAR KALIBRATOR!E8`)
  di kolom yang seharusnya `U95%` standar UFM. Tidak dibaca jalur hitung mana pun.
- **`PERHITUNGAN FC!G8` Totalizer = `#VALUE!`** karena rentang ukurnya teks
  (`'140-2500'`) dikalikan angka. Tidak dibaca jalur hitung.
- **Blok titik 3 & 4** kedua workbook rusak salin-tempel dan tidak dipakai sebagai acuan
  bentuk apa pun. Inventarisnya di docblock `FlowmeterCalculator`.
