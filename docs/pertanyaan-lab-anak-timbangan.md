# Pertanyaan lab — Anak Timbangan (OIML R111), alat ke-29

Dari pembongkaran `1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx`
(21 sheet, **tanpa password**), sesi contoh sertifikat `001-CAL-126`.

**Status pekerjaan: rumusnya sudah dibuktikan, kodenya BELUM ditulis.**
Reimplementasi mandiri di Python sudah diadu ke master — 21 pengaduan, nol beda
pada 5·10⁻⁶: densitas udara, rantai ABBA, koreksi apung, keenam komponen budget,
`uc`, `veff`, `k`, dan `U95`. Angka di dokumen ini **hasil hitung**, bukan
salinan.

Urutannya menurut konsekuensi. Yang paling atas menyangkut sertifikat yang sudah
di tangan pelanggan.

> **Batas yang tidak dilanggar dokumen ini:** menarik atau menerbitkan ulang
> sertifikat adalah tindakan formal manajer teknis di bawah klausa Pekerjaan
> Tidak Sesuai ISO/IEC 17025. Yang disiapkan di sini rekomendasi dan angkanya.

---

## §0 — Permintaan DATA, bukan pertanyaan: master `.xlsm` aslinya

Berkas yang ada berekstensi `.xlsx` dan namanya berakhiran **`imp`** — hasil
impor/konversi. Konversi itulah yang kemungkinan besar memutus **sembilan
defined name** jadi `#REF!` (§8) dan menyisakan **tujuh nama lewat tautan luar**
(§9).

**Sebagian temuan di bawah mungkin lahir dari konversi, bukan dari lab.** Itu
mengubah mana yang perlu dibawa ke rapat dan mana yang cukup dibetulkan
diam-diam. Mohon master `.xlsm` aslinya sebelum butir §8 dan §9 dinilai.

---

## §1 — Tabel densitas kelas tergeser diagonal · **PRIORITAS SATU**

`PERHITUNGAN FC!Z32:AG52` memuat densitas anak timbangan per nominal, dua kolom:
UUT (kelas F1) dan standar (kelas E2). Dibaca ke bawah:

| Nominal | F1 (UUT) | E2 (standar) |
|---|---|---|
| 0,1 g | 3000 | 4400 |
| 0,2 g | 4400 | 3000 |
| 0,5 g | 2200 | 4400 |
| 1 g | 3000 | **10650** |
| 2 g | 4000 | 9000 |
| 5 g | **10650** | 8250 |
| 10 g | 9000 | 8080 |
| 20 g | 8350 | 8035 |
| 50 g | 8080 | 8010 |
| 100 g | 8060 | 8010 |

Dua angka menyingkapnya: **10650 kg/m³ itu densitas TIMBAL**, dan anak timbangan
kelas F1/E2 dibuat dari baja nirkarat (~7900–8000 kg/m³). Nilai 2200 dan 3000
juga mustahil — itu kaca dan aluminium.

Polanya: kedua kolom **hampir sama, bergeser satu baris**. Tanda tangan tabel
yang dibuat dengan menyeret fill-handle secara diagonal.

**Akibatnya bukan kosmetik.** Densitas masuk koreksi apung lewat
`(1/ρ_UUT − 1/ρ_std)`, dan di titik 10 (0,2 g) kedua kolomnya **tertukar**
dibanding titik 9 (0,1 g) — sehingga koreksi apungnya **berbalik tanda**:
`−0,0011563` di titik 9, `+0,0011563` di titik 10.

**Pertanyaan:** tabel ini diganti dengan **OIML R111-1 Tabel B.7** (rentang
densitas yang diizinkan per kelas per nominal)? Dan yang membuat butir ini
prioritas satu: tabel yang sama juga dipakai `TabelStandarTimbangan` milik
**alat ke-21**, jadi jawabannya menyentuh dua alat sekaligus.

---

## §2 — Koreksi apung 18 dari 20 keping memakai massa keping PERTAMA

Rumusnya `b = (ρ_udara − 1,2) · (1/ρ_UUT − 1/ρ_std) · ms`. Faktor `ms`
seharusnya massa keping itu sendiri. Di master, sejak titik 5 sampai 20, yang
dipakai **`ms` titik 1 = 100,000144 g** — sel `AG29`, yang tidak ikut bergeser
waktu rumusnya di-drag ke bawah.

Sudah dibuktikan mandiri di tiga titik:

| Titik | Nominal | `b` master | `b` benar | Meleset | MPE F1 | Rasio |
|---|---|---|---|---|---|---|
| 9 | 0,1 g | −1,1563 mg | −0,0012 mg | −1,1552 mg | 0,05 mg | **23× MPE** |
| 10 | 0,2 g | +1,1563 mg | +0,0023 mg | +1,1540 mg | 0,06 mg | **19× MPE** |
| 12 | 0,5 g | −2,4779 mg | −0,0124 mg | −2,4655 mg | 0,08 mg | **31× MPE** |

Sertifikat mencetak massa konvensional keping 0,1 g sebagai **0,09884965 g** —
meleset 1,15 mg pada keping yang toleransinya 0,05 mg.

Butir yang sama berlaku di budget: `V14` (`ci` komponen Bouyancy) berbunyi
`((1/$AA$33)-(1/$AG$33))*$D$27` — **ketiganya absolut, ketiganya titik 1**, di
kedua puluh blok.

**Pertanyaan:** sertifikat `001-CAL-126` ditarik, direvisi, atau berlaku maju?

---

## §3 — Keping 10 g terbit sebagai 5,500163 g

Titik 17, pembacaan `T1 = 0,9998` — seharusnya `9,9998`. Satu digit hilang.

```
de = (0,9998 − 9,9999 − 10,0001 + 10,0001)/2 = −4,50005 g
mT = 10,000075 + (−4,50005) + 0,000138       =  5,500163 g
```

Sertifikat mencetak **5,500163 g untuk anak timbangan nominal 10 g** — meleset
4,5 gram, atau **45 %**. Ketidakpastiannya tetap terbit 0,1226 mg seolah
pengukurannya baik, dan nol sel memprotes.

Penjaga "pembacaan di luar rentang alat" **tidak menangkap ini** — 0,9998 g
memang di dalam rentang 0–200 g. Yang membedakannya cuma jaraknya dari nominal.

**Pertanyaan:** kami usulkan gerbang **`|de| > 10 × MPE` memblokir titik**.
Ambang itu disetujui, atau lab punya angka lain?

---

## §4 — Lima keping mencetak `#VALUE!` di sertifikat

Titik 4–8 (nominal 0,005 / 0,01 / 0,02 / 0,02 / 0,05 g) memulangkan `#VALUE!`
untuk **massa konvensional DAN ketidakpastian**.

Rantainya: kolom densitas berbunyi teks `"tdk ada di tabel"` → teks masuk `1/ρ`
→ `#VALUE!` → merambat ke `mT`, lalu `PERHITUNGAN U95%`, lalu `SERTIFIKAT!I20:L24`.

Jadi **seperempat sertifikat pelanggan berisi `#VALUE!`**.

**Pertanyaan:** densitas kelas F1 untuk 5 mg–50 mg memang belum pernah
ditabelkan, atau tabelnya yang terpotong? (Server akan **memblokir** titik yang
densitasnya tidak ada, dengan alasan yang kebaca — bukan menerbitkan `#VALUE!`.)

---

## §5 — Titik 2 dan 3 kehilangan koreksi apung diam-diam

`J29` dan `P29` bernilai **0**, karena rumusnya menunjuk `AM26`/`AM29`/`AS26`/
`AS29` yang **tidak ada isinya**.

Berbeda dari §2 yang memakai nilai salah, ini **menghapus komponennya
seluruhnya**, tanpa satu pun tanda. Titik 4 kebetulan meledak jadi `#VALUE!`
karena densitasnya juga kosong — kalau densitasnya ada, dia akan diam seperti
titik 2 dan 3.

---

## §6 — Baris berbintang di tabel standar mustahil terpilih

`Tabel_E2` memuat pasangan kembar OIML dengan penanda bintang:

| Nominal | ms (g) | Kembarannya | ms (g) | Selisih |
|---|---|---|---|---|
| `0.02` | 0,0200027 | `0.02*` | 0,0200009 | 1,8 µg |
| `0.2` | 0,2000033 | `0.2*` | 0,2000078 | 4,5 µg |
| `20` | 20,0000948 | `20*` | 20,0000812 | 13,6 µg |
| `200` | 200,000149 | `200*` | 200,000104 | 45 µg |

Lookup-nya `VLOOKUP(nominal_angka; Tabel_E2; 2; FALSE)`, dan nominal yang diketik
teknisi selalu angka. **Baris berbintang tidak akan pernah terpilih.**

Terbukti di sesi contoh: titik 2 dan 3 dua-duanya 200 g, dua-duanya memungut
`ms = 200,000149`. Padahal keping standar kedua massanya 200,000104.

Selisihnya masih di bawah U95 (0,129 mg), jadi tidak pernah terlihat — tapi dua
keping fisik berbeda diperlakukan sebagai satu.

**Pertanyaan:** keping standar dipilih lewat **penanda keping**, bukan nominal —
disetujui? (Itu sekaligus menyelesaikan §11.)

---

## §7 — Rata-rata lingkungan yang dipajang ke teknisi salah kolom

`INPUT DATA!I21/I22/I24` berbunyi `AVERAGE(F21:G21)` sementara nilainya ada di
`E21:F21`. Jadi yang dipajang sebagai "rata-rata" sebenarnya cuma nilai **Akhir**:

| | Awal | Akhir | Dipajang `INPUT DATA` | Dipakai hitungan |
|---|---|---|---|---|
| Suhu | 23,1 | 23,0 | **23,0** | 23,05 |
| RH | 55 | 56 | **56** | 55,5 |
| Tekanan | 933,2 | 933,1 | **933,1** | 933,15 |

Yang masuk hitungan **benar**, jadi angkanya tidak bergeser. Tapi ada dua angka
berbeda untuk hal yang sama di satu workbook, dan yang salah justru yang dilihat
teknisi.

---

## §8 — Sembilan defined name `#REF!`

`DensitasE1`, `DensitasE2`, `DensitasF1`, `DensitasF2`, `DensitasF2_251020`,
`DensitasM1`, `DensitasM12`, `DensitasM2`, `DensitasM23`.

Nama-nama itu menyiratkan pernah ada **tabel densitas terpisah per kelas** — dan
tabel yang sekarang (§1) penggantinya yang cacat. Lihat §0.

---

## §9 — Tujuh nama lewat tautan luar, nilainya tinggal cache

`CMC_AT`, `Tabel_CMCTimb`, `Metode_Kalibrasi`, `Penandatangan`, `Dihitung_OIeh`,
`Barometer_Tekanan`, `Jenis_Timbangan`.

Yang paling menentukan **`Barometer_Tekanan`**: dia memasok koreksi tekanan
udara, yang masuk `ρ_udara`, yang masuk koreksi apung **setiap keping**. Nilai
yang terbaca sekarang tinggal cache dari workbook lain.

---

## §10 — Tabel MPE lengkap, dan nol sel membacanya

Sheet `MPE AT` (tersembunyi) memuat OIML R111 lengkap: 23 nominal dari 1 mg
sampai 20 kg, tujuh kelas. Defined name `Tabel_MPE` ada. **Tidak satu pun rumus
memakainya.**

Akibatnya sertifikat mencetak Nominal, Conventional Mass, dan Uncertainty —
**tanpa vonis lulus/tidak lulus**. Padahal itu yang paling dicari pelanggan dari
kalibrasi anak timbangan.

**Pertanyaan:** vonis kesesuaian sengaja tidak dicetak, atau tabelnya disiapkan
lalu lupa disambungkan?

---

## §11 — Kolom "No Identitas" kosong dua puluh kali

Sertifikat mencetak `-` di kolom identitas untuk kedua puluh keping.

Untuk anak timbangan itu bukan kosmetik: satu set berisi keping **kembar** (dua
200 g, dua 20 g, dua 2 g, dua 0,2 g, dua 0,02 g). Tanpa penanda fisik, pelanggan
menerima dua baris "200 g" dengan massa berbeda dan **tidak tahu yang mana yang
mana**.

**Pertanyaan:** bagaimana pelanggan memetakan sertifikat ke keping fisiknya
sekarang? Server akan **mewajibkan** `no_identitas` untuk keping bernominal
kembar.

---

## §12 — Untuk keping di bawah 1 g, U95 melebihi MPE-nya sendiri

Budget didominasi neraca, jadi `U95` nyaris rata **0,1223–0,1291 mg** dari 5 mg
sampai 200 g:

| Nominal | MPE F1 | U95 | Rasio |
|---|---|---|---|
| 5 mg | 0,02 mg | 0,1223 mg | **6,1×** |
| 10 mg | 0,025 mg | 0,1223 mg | 4,9× |
| 0,1 g | 0,05 mg | 0,1223 mg | 2,4× |
| 1 g | 0,10 mg | 0,1224 mg | 1,2× |
| 50 g | 0,30 mg | 0,1232 mg | 0,41× |

Untuk keping di bawah ~1 gram, **ketidakpastian pengukurannya lebih besar dari
toleransi kepingnya** — kesesuaian tidak bisa dinyatakan.

Neraca yang benar untuk keping sekecil itu adalah **Semi Micro Balance**
(simpangan baku 0,0019 mg, **24× lebih baik**), yang tersedia di daftar tapi
tidak dipilih.

**Pertanyaan:** Semi Micro Balance diwajibkan untuk keping di bawah ambang
tertentu? Berapa ambangnya?

---

## §13 — Koreksi lingkungan dihitung, lalu tidak dipakai

Rantai `index → koreksi → nilai terkoreksi` lengkap untuk suhu, RH, dan tekanan.
Tapi `ρ_udara` (`AG25`) membaca `G14`/`G15`/`G17` — nilai **rata-rata MENTAH**.

Terbukti: `ρ_udara = 1,0909731524586854` direproduksi persis dengan T=23,05
RH=55,5 P=933,15 (mentah). Dengan nilai terkoreksi (T=23,15 RH=54,7 P=934,15)
angkanya berbeda.

Kelas yang sama dengan koreksi timer Flowmeter yang dibuang.

---

## §14 — Rumus densitas udara: pendekatan, bukan CIPM-2007

```
ρ_udara = ((0,34848·P) − (0,009·RH)·EXP(0,061·T)) / (T + 273,15)
```

Itu formula sederhana yang lazim di **OIML R111 Lampiran E**, bukan CIPM-2007
penuh. Sudah diverifikasi cocok dengan master.

**Pertanyaan:** pendekatan itu memang yang disepakati untuk lingkup ini?

---

## §15 — Status akreditasi: sudah terjawab kertasnya, mohon konfirmasi

Kelompok **Massa** di lampiran LK-285-IDN cuma memuat **satu** baris: no. 12,
*"Timbangan (Elektronik, mekanik)"*. Kalibrasi **anak timbangan** tidak ada.

Tapi `INPUT DATA!U1` mencetak `LK-285-IDN`, dan defined name
`CMC_AT = [2]DATABASE!$R$5:$T$21` menunjuk — lewat tautan luar — ke tabel CMC
**timbangan** milik alat lain.

**Kertasnya sendiri sudah menjawab:** formulirnya bernama
`SIDIK-FM-CAL-0541_Rev.0 - LEMBAR KERJA ANAK TIMBANGAN **(Non KAN)**`.

**Pertanyaan (konfirmasi):** sertifikat anak timbangan **tidak** membawa klaim
akreditasi — betul? Server akan menyetel `dalamLingkupAkreditasi() === false`,
dan **tidak** memungut CMC timbangan sebagai lantai (itu pita untuk alat lain,
lewat tautan luar yang nilainya tinggal cache).

---

## §16 — Tidak ada lantai CMC, dan tidak ada yang menampung komponen hilang

`AA22 = MAX(AA20:AD21)` dengan `AD21` **kosong**. Sel `V21` berlabel
`'CMC PT. SIDIK'` tidak pernah diisi.

Di alat ini itu **konsisten** dengan §15 — memang di luar lampiran, jadi tidak
ada pita yang berlaku. Tapi konsekuensinya sama dengan Height Gauge: kalau
resolusi neraca kosong atau simpangan bakunya nol, `U95` langsung terbit terlalu
kecil dan **tidak ada yang terlihat ganjil**. Gerbang penerbitannya dipatok
eksplisit di server.

---

## §17 — Butir kecil yang tetap dicatat

- **Kelipatan `1,414` pada komponen resolusi.** `ui = (u/1,73) × 1,414` — resolusi
  masuk dua kali (baca standar + baca UUT). Sah, tapi angkanya **1,414 literal**,
  bukan `√2` (1,41421356…). Sudah diverifikasi.
- **Pembagi `1,73`** alih-alih `√3`, di tiga komponen. Selisih 0,12 %, arah aman.
- **`Tabel_E2` baris 10 kg**: kolom `R` = **2,3094** (= 4/√3) sementara seluruh
  baris lain `R = U/2`. Pola putus di satu baris.
- **`Tabel_F1` bukan tabel kelas F1** — dia ekor `Tabel_E2` (2 kg–20 kg).
  Penamaannya berbohong; rumus rutingnya sebenarnya "keping kecil vs besar".
- **`SERTIFIKAT!P46 = DATABASE!Z33`** — ketertelusuran Fujitsu membaca baris
  Excellent. Harusnya `Z32`.
- **`SERTIFIKAT!F19 = '200*'`** — teks harfiah di tengah kolom berumus.
- **`PERHITUNGAN FC!F17 = 'INPUT DATA'!F24:H24`** — rentang di rumus skalar.
- **`PERHITUNGAN U95%!E5`** (Ketelitian Baca Alat) = **0**. Anak timbangan memang
  tidak punya daya baca, jadi nol itu benar.

---

## Formulir keputusan

| § | Butir | Keputusan | Tanda tangan | Tanggal |
|---|---|---|---|---|
| 0 | Master `.xlsm` asli diserahkan | | | |
| 1 | Tabel densitas → OIML R111 B.7 (menyentuh alat ke-21 juga) | | | |
| 2 | Sertifikat `001-CAL-126` — tarik / revisi / berlaku maju | | | |
| 3 | Ambang penolakan `\|de\|` — 10 × MPE atau lain | | | |
| 4 | Densitas F1 untuk 5 mg–50 mg — ada atau belum ditabelkan | | | |
| 6 | Keping standar dipilih lewat penanda, bukan nominal | | | |
| 10 | Vonis kesesuaian dicetak di sertifikat — ya / tidak | | | |
| 11 | `no_identitas` wajib untuk keping kembar | | | |
| 12 | Semi Micro Balance wajib di bawah ambang — berapa | | | |
| 15 | Sertifikat tanpa klaim akreditasi (Non KAN) — konfirmasi | | | |

Manajer Teknis: ______________________  Tanggal: ____________
