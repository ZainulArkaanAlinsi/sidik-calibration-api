# Pertanyaan lab — Anak Timbangan (OIML R111), alat ke-29

Dari pembongkaran `1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx`
(21 sheet), sesi contoh sertifikat `001-CAL-126`, 20 keping, kelas UUT F1
lawan standar E2, ditimbang di Analytical Balance.

**Angka di dokumen ini hasil hitung ulang, bukan salinan.** Reimplementasi
mandiri di Python diadu ke master sel demi sel: **1033 nilai, nol beda pada
5·10⁻⁶** — 20 titik × (ms, de, b, mT) plus 20 blok budget × 6 komponen ×
(U, divisor, vi, ui, ci, uici, uici²) plus uc, veff, k, U95 tiap blok. Yang
dilaporkan di bawah karena itu bukan tebakan.

> **Batas yang tidak dilanggar dokumen ini:** menarik atau menerbitkan ulang
> sertifikat adalah tindakan formal manajer teknis di bawah klausa Pekerjaan
> Tidak Sesuai ISO/IEC 17025. Yang disiapkan di sini rekomendasi dan angkanya.

Urutannya menurut besar dampak yang **sudah terukur**, bukan menurut kesan.

---

## §0 — Dua permintaan sebelum yang lain dinilai

**(a) Master `.xlsm` aslinya.** Berkas yang ada `.xlsx` berakhiran **`imp`** —
hasil impor. Yang saya punya karena itu **nilai sel, tanpa rumus**. Semua
temuan di bawah dibuktikan dari NILAI (dan itu cukup untuk membuktikan
angkanya salah), tapi **letak sel mana yang keliru cuma bisa dipastikan dari
rumusnya**. Tanpa `.xlsm`, perbaikan di master harus dicari manual.

**(b) `FORM VALIDASI` menyebut perubahan yang belum saya lihat berkasnya.**
Revisi 3 (29 Mei 2026, disusun NS, dicek NR, divalidasi Manajer Teknis)
mencatat:

> *Change Formula "Ketidakpastian Resolusi Timbangan and Sensitivity" in
> Perhitungan U95%* · *Change Formula "Deviasi Standar Timbangan"*

Dua-duanya persis yang jadi §1 dan §18 di bawah. Jadi §1 **bukan kecelakaan
salin-tempel** — dia perubahan metode yang sengaja, baru, dan sudah lewat
validasi. Yang saya minta bukan koreksi, melainkan **dasar pertimbangannya**,
supaya server meniru yang benar dan bukan menebak.

---

## §1 — Repeatability: sel berlabel "Rata-rata" berisi SIMPANGAN BAKU · dampak terbesar

Sheet `Deviasi Standard Timbangan` mengukur keterulangan tiap neraca: **6 hari
verifikasi × 10 ulangan ABBA**. Tiap hari dihitung simpangan bakunya sendiri.
Untuk Analytical Balance, keenamnya (mg):

```
0,0537   0,0497   0,1155   0,1212   0,1117   0,1732
```

Baris di bawahnya berlabel **"Rata-rata STDev"** dan berisi **0,046363 mg**.

Itu bukan rata-rata. Rata-rata keenamnya 0,104174 mg. **0,046363 mg adalah
SIMPANGAN BAKU dari keenam simpangan baku itu** — sudah saya buktikan sampai
epsilon mesin (beda relatif 1,5·10⁻¹⁶) dan **cocok untuk kelima neraca**:

| Neraca | stdev(6 STDev harian) | Sel "Rata-rata STDev" | beda relatif |
|---|---|---|---|
| Semi Micro Balance | 1,92310372033431438·10⁻⁶ | 1,92310372033431395·10⁻⁶ | 2·10⁻¹⁶ |
| Analytical Balance | 4,63629784187065153·10⁻⁵ | 4,63629784187065221·10⁻⁵ | 1·10⁻¹⁶ |
| Electronic Balance Fujitsu | 1,94077401347152607·10⁻⁴ | 1,94077401347152607·10⁻⁴ | 0 |
| Electronic Balance Excellent | 1,65377144660895474·10⁻³ | 1,65377144660895474·10⁻³ | 0 |
| Electronic Balance Mettler | 1,39485728621643423·10⁻³ | 1,39485728621643445·10⁻³ | 2·10⁻¹⁶ |

Nilai itu **langsung jadi komponen Repeatability di kedua puluh budget**, dan
Repeatability adalah komponen terbesar (0,0464 mg dari uc 0,0639 mg).

**Kenapa ini yang paling berdampak:** 0,046363 mg lebih kecil daripada
keterulangan hari **manapun** — bahkan lebih kecil dari hari terbaik (0,0497 mg).
Sebaran antar-hari mengukur *seberapa berbeda keterulangan dari hari ke hari*,
bukan *seberapa berulang satu penimbangan*. Kalau yang dipakai gabungan
kuadrat harian (0,112443 mg), U95 seluruh sertifikat **hampir dua kali lipat**:

| Titik | U95 terbit | U95 kalau pakai gabungan harian | naik |
|---|---|---|---|
| 100 g | 0,1263 mg | 0,2407 mg | 1,91× |
| 200 g | 0,1291 mg | 0,2422 mg | 1,88× |
| 0,1 g | 0,1223 mg | 0,2387 mg | 1,95× |
| 1 g | 0,1224 mg | 0,2388 mg | 1,95× |
| 50 g | 0,1232 mg | 0,2392 mg | 1,94× |

**Pertanyaan:** apakah "Deviasi Standar Timbangan" memang dimaksudkan sebagai
sebaran antar-hari, dan apa dasarnya? Kalau yang dimaksud keterulangan
penimbangan, penggantinya gabungan kuadrat harian (0,112443 mg untuk Analytical
Balance) — dan **U95 seluruh sertifikat anak timbangan yang terbit sejak
29 Mei 2026 tercetak sekitar setengah dari yang seharusnya**.

Catatan pendukung: derajat kebebasan yang dipakai budget **vi = 54**
( = 6 hari × (10 − 1) ), yaitu derajat kebebasan gabungan **60 pembacaan** —
konsisten dengan tafsir "keterulangan penimbangan", tidak dengan tafsir
"sebaran 6 angka" (yang derajat kebebasannya 5).

---

## §2 — Koreksi apung 12 keping memakai massa keping PERTAMA

Rumusnya `b = (ρ_udara − 1,2) · (1/ρ_UUT − 1/ρ_std) · ms`, dan `ms` seharusnya
massa keping itu sendiri. Di kolom `b` sheet `PERHITUNGAN FC`, sejak titik 9
sampai 20 yang dipakai **massa keping titik 1 = 100,000144 g**.

Terbukti untuk **setiap** titik yang punya densitas — hitungan saya memulangkan
angka master persis begitu `ms` diganti 100,000144:

| Titik | Nominal | `b` master (mg) | `b` benar (mg) | Meleset (mg) | MPE F1 | Rasio |
|---|---|---|---|---|---|---|
| 9 | 0,1 g | −1,1563 | −0,0012 | −1,1552 | 0,05 | **23,1×** |
| 10 | 0,2 g | +1,1563 | +0,0023 | +1,1540 | 0,06 | **19,2×** |
| 11 | 0,2 g | +1,1563 | +0,0023 | +1,1540 | 0,06 | **19,2×** |
| 12 | 0,5 g | −2,4779 | −0,0124 | −2,4655 | 0,08 | **30,8×** |
| 13 | 1 g | −2,6105 | −0,0261 | −2,5844 | 0,10 | **25,8×** |
| 14 | 2 g | −1,5143 | −0,0303 | −1,4840 | 0,12 | **12,4×** |
| 15 | 2 g | −1,5143 | −0,0303 | −1,4840 | 0,12 | **12,4×** |
| 16 | 5 g | +0,2978 | +0,0149 | +0,2829 | 0,16 | 1,8× |
| 17 | 10 g | +0,1379 | +0,0138 | +0,1241 | 0,20 | 0,6× |
| 18 | 20 g | +0,0512 | +0,0102 | +0,0410 | 0,25 | 0,2× |
| 19 | 20 g | +0,0512 | +0,0102 | +0,0410 | 0,25 | 0,2× |
| 20 | 50 g | +0,0118 | +0,0059 | +0,0059 | 0,30 | 0,0× |

Titik 1 kebetulan benar (keping itu memang keping 1). Titik 2 & 3 lihat §5.

Sertifikat mencetak massa konvensional keping 0,1 g sebagai **0,09884965 g** —
meleset 1,16 mg pada keping yang toleransinya 0,05 mg, dan **9,4× ketidakpastian
yang dicetak di baris yang sama** (0,1223 mg).

**Yang perlu ditegaskan:** ini kerusakan di kolom `b` saja. Koefisien sensitivitas
`ci` di blok budget **sudah benar** — tiap blok memakai `ms` keping itu sendiri
(dibuktikan: titik 2 memakai 200,000149, titik 1 memakai 100,000144). Jadi
`U95`-nya tidak ikut tergeser oleh cacat ini; yang tergeser cuma nilai yang
dilaporkan.

**Pertanyaan:** sertifikat `001-CAL-126` ditarik, direvisi, atau berlaku maju?

---

## §3 — Keping 10 g terbit sebagai 5,500163 g

Titik 17, pembacaan `T1 = 0,9998` — seharusnya `9,9998`. Satu digit hilang.
S1 = 9,9999 · T2 = 10,0001 · S2 = 10,0001.

```
de = (0,9998 − 9,9999 − 10,0001 + 10,0001)/2 = −4,50005 g
mT = 10,000075 + (−4,50005) + 0,000137933      =  5,500163 g
```

Sertifikat mencetak **5,500163 g untuk anak timbangan nominal 10 g** — meleset
4,5 gram, atau **45 %**. Ketidakpastiannya tetap terbit 0,1226 mg seolah
pengukurannya baik, dan nol sel memprotes.

Penjaga "pembacaan di luar rentang alat" **tidak menangkap ini**: 0,9998 g
memang di dalam rentang 0–200 g. Yang membedakannya cuma jaraknya dari nominal.

**Pertanyaan:** kami usulkan gerbang **`|de| > 10 × MPE` memblokir titik**.
Untuk 10 g itu berarti menolak `|de| > 2 mg`, sementara `de` yang wajar di sesi
ini semuanya di bawah 0,35 mg. Ambang itu disetujui, atau lab punya angka lain?

---

## §4 — Lima keping mencetak `#VALUE!` di sertifikat

Titik 4–8 (nominal 0,005 / 0,01 / 0,02 / 0,02 / 0,05 g) memulangkan `#VALUE!`
untuk **massa konvensional DAN ketidakpastian**, dan `#VALUE!` itu benar-benar
tercetak di sertifikat pelanggan — **seperempat dari 20 baris**.

Sumbernya tabel densitas (§6): kolom kelas F1 kosong untuk 0,01 g dan 0,05 g,
dan barisnya tidak ada sama sekali untuk 0,005 g dan 0,02 g. Sel yang kosong
dibaca sebagai teks `"tdk ada di tabel"`, teks masuk `1/ρ`, jadi `#VALUE!`,
lalu merambat ke `mT`, ke budget, dan ke sertifikat.

**Pertanyaan:** densitas kelas F1 untuk 5 mg–50 mg memang belum pernah
ditabelkan, atau tabelnya yang terpotong? Server akan **memblokir** titik yang
densitasnya tidak ada, dengan alasan yang kebaca — bukan menerbitkan `#VALUE!`.

---

## §5 — Titik 2 dan 3 kehilangan koreksi apung diam-diam

Kolom `b` titik 2 dan 3 (dua keping 200 g) bernilai **0**, padahal densitasnya
ada (F1 8060, E2 8010) dan koreksi yang benar **+0,0169 mg**.

Besarnya kecil — di bawah 14 % U95, tidak akan pernah terlihat. Yang jadi
persoalan bukan besarnya melainkan **caranya**: berbeda dari §2 yang memakai
nilai salah, ini **menghapus komponennya seluruhnya tanpa satu pun tanda**.
Titik 4 kebetulan meledak jadi `#VALUE!` karena densitasnya juga kosong; kalau
densitasnya ada, dia akan diam persis seperti titik 2 dan 3.

---

## §6 — Tabel densitas memuat nilai yang mustahil secara fisika

`STD AT` blok *Kelas Anak Timbang OIML (kg/m³)*, apa adanya:

| Nominal | E2 | F1 | F2 | M1 | M2 |
|---|---|---|---|---|---|
| 100 g | 8010 | 8060 | 8550 | 4400 | 2300 |
| 50 g | 8010 | 8080 | 9000 | 4000 | |
| 20 g | 8035 | 8350 | **14400** | 2600 | |
| 10 g | 8080 | 9000 | 4000 | 2000 | |
| 5 g | 8250 | **10650** | 3000 | 4000 | |
| 2 g | 9000 | 4000 | 2000 | 3000 | |
| 1 g | **10650** | 3000 | 4000 | 2000 | |
| 0,5 g | 4400 | 2200 | 3000 | | |
| 0,2 g | 3000 | 4400 | 2200 | | |
| 0,1 g | 4400 | 3000 | | | |
| 0,05 g | 3400 | | | | |
| 0,01 g | 2300 | | | | |

**Yang menandainya rusak, bukan sekadar aneh:**

1. **10650 kg/m³ itu densitas timbal**, dan 14400 kg/m³ tidak dimiliki logam
   mana pun yang dipakai anak timbangan. Anak timbangan E2/F1 dibuat dari baja
   nirkarat, ±7900–8000 kg/m³.
2. **Kolom F1 turun rapi lalu patah.** 8060 → 8080 → 8350 → 9000 → 10650 untuk
   100 → 50 → 20 → 10 → 5 g adalah deret yang masuk akal: itu **titik tengah
   rentang densitas OIML R111 Tabel B.7** untuk kelas F1, yang memang melebar
   makin kecil kepingnya. Lalu di 2 g dia terjun ke 4000 dan naik-turun tak
   beraturan (3000 → 2200 → 4400 → 3000).
3. **Kolom-kolomnya saling meminjam angka.** E2 pada 10 g (8080) = F1 pada 50 g.
   E2 pada 1 g (10650) = F1 pada 5 g. E2 pada 2 g (9000) = F1 pada 10 g. Pola
   geser dua baris yang **putus di sebagian baris** — tanda tabel yang pernah
   diseret salah arah lalu ditambal sebagian.
4. Kolom E2 dan F1 **saling tertukar** antara 0,1 g dan 0,2 g, dan justru itu
   yang membuat koreksi apung titik 9 dan 10 **berlawanan tanda** (−0,0012 mg
   lawan +0,0023 mg).

**Yang jujur harus disebut: dampaknya kecil.** Koreksi apung yang BENAR untuk
seluruh 20 keping berkisar **0,0012–0,0303 mg** — di bawah seperempat U95 di
tiap titik. Jadi tabel ini bukan penyebab angka sertifikat meleset (itu §2);
dia penyebab **lima titik gagal terbit** (§4) dan penyebab tanda koreksi yang
tidak masuk akal.

**Pertanyaan:** tabel ini diganti dengan OIML R111-1 Tabel B.7 lengkap
(termasuk baris 5 mg–50 mg yang sekarang hilang)? Kami **tidak** akan menyalin
tabel yang sekarang ke server tanpa jawaban — menyalinnya berarti mengabadikan
nilai yang mustahil.

---

## §7 — Ketidakpastian tekanan di sertifikat memakai angka kelembaban

Sertifikat mencetak `Tekanan Udara 933,15 hPa ± 3,0016662039607276 hPa`.

Angka itu = `√(3² + 0,1²)`. Tapi ketidakpastian Thermobarometer untuk tekanan
di `DATABASE` adalah **2 hPa** — yang **3** itu kolom **kelembaban**. Sheet
`PERHITUNGAN FC` memakai yang benar dan memulangkan `√(2² + 0,1²) =
2,0024984394500795 hPa`.

Jadi satu workbook, satu sesi, **dua angka berbeda untuk hal yang sama** — dan
yang salah justru yang dicetak untuk pelanggan.

Rumusnya sendiri sudah diverifikasi cocok di ketiga besaran:
`U95_sertifikat = √(U95_standar² + Δ²)` dengan `Δ = |awal − akhir|`
(suhu 1,2041594578792296 °C, RH 3,1622776601683795 %RH).

---

## §8 — Keping kembar ber-bintang mustahil terpilih

`OVERALL STANDAR ANAK TIMBANGAN` memuat pasangan kembar dengan penanda bintang:

| Nominal | ms (g) | Kembarannya | ms (g) | Selisih |
|---|---|---|---|---|
| `0.002` | 0,00200127 | `0.002*` | 0,00200133 | 0,06 µg |
| `0.02` | 0,0200027 | `0.02*` | 0,0200009 | 1,8 µg |
| `0.2` | 0,2000033 | `0.2*` | 0,2000078 | 4,5 µg |
| `2` | 2,0000192 | `2*` | 2,0000185 | 0,7 µg |
| `20` | 20,0000948 | `20*` | 20,0000812 | 13,6 µg |
| `200` | 200,000149 | `200*` | 200,000104 | 45 µg |

Nominal yang diketik teknisi selalu angka, dan pencarian exact selalu mendarat
di baris pertama. **Baris ber-bintang tidak akan pernah terpilih.**

Terbukti di sesi contoh: titik 2 dan 3 dua-duanya 200 g, dua-duanya memungut
`ms = 200,000149`; titik 10 & 11, 14 & 15, 18 & 19 sama pola. Padahal keping
standar keduanya **benda fisik yang lain**, dan sertifikatnya sendiri mencetak
`200*` di kolom Nominal baris ketiga — jadi masternya tahu ada dua, tapi tetap
memungut massa yang sama.

Selisihnya di bawah U95, jadi tidak pernah terlihat.

**Pertanyaan:** keping standar dipilih lewat **penanda keping**, bukan nominal —
disetujui? (Itu sekaligus menyelesaikan §11.)

---

## §9 — Rata-rata lingkungan yang dipajang ke teknisi salah kolom

| | Awal | Akhir | Dipajang `INPUT DATA` sbg "Average" | Dipakai hitungan |
|---|---|---|---|---|
| Suhu | 23,1 | 23,0 | **23,0** | 23,05 |
| RH | 55 | 56 | **56** | 55,5 |
| Tekanan | 933,2 | 933,1 | **933,1** | 933,15 |

Yang dipajang persis sama dengan kolom **Akhir** — rata-ratanya membaca sepasang
sel yang bergeser satu kolom. Yang masuk hitungan **benar** (sudah diverifikasi:
`ρ_udara` hanya reproduksi dengan 23,05 / 55,5 / 933,15), jadi angkanya tidak
bergeser. Tapi teknisi yang mengecek pekerjaannya sendiri melihat angka yang salah.

---

## §10 — Tabel MPE lengkap ada, sertifikat tidak memberi vonis

Sheet `MPE AT` memuat OIML R111 lengkap: 23 nominal dari 1 mg sampai 20 kg,
tujuh kelas. Sudah saya adu ke R111 dan cocok.

Sertifikat mencetak Nominal, Conventional Mass, dan Uncertainty — dan
**tidak ada kolom lulus/tidak lulus**. Padahal itu yang paling dicari pelanggan
dari kalibrasi anak timbangan, dan tabelnya sudah tersedia di workbook yang sama.

**Pertanyaan:** vonis kesesuaian sengaja tidak dicetak, atau tabelnya disiapkan
lalu tidak jadi disambungkan? Kalau dicetak, keputusannya memakai *guarded
acceptance* seperti alat lain di sistem ini (lulus kalau `|error| + U ≤ MPE`)?
Perlu diketahui bahwa dengan aturan itu, dan U95 sekarang, **keping di bawah
1 g tidak akan pernah bisa dinyatakan lulus** — lihat §12.

---

## §11 — Kolom "ID Number" kosong dua puluh kali

Sertifikat mencetak `-` di kolom identitas untuk kedua puluh keping.

Untuk anak timbangan itu bukan kosmetik: set ini berisi keping **kembar** — dua
200 g, dua 20 g, dua 2 g, dua 0,2 g, dua 0,02 g. Sertifikatnya mencetak dua
baris "200 g" dengan massa konvensional yang sama dan **tanpa apa pun yang
memberi tahu pelanggan keping mana yang mana**. Untuk 20 g bahkan massanya
berbeda (19,99989598 g dan 20,00049598 g) sementara identitasnya sama-sama `-`.

**Pertanyaan:** bagaimana pelanggan memetakan sertifikat ke keping fisiknya
sekarang? Server akan **mewajibkan** `no_identitas` untuk keping bernominal
kembar. Kolomnya sudah ada di lembar kerja master (`No Identitas :`), tinggal
diisi.

---

## §12 — Di bawah 1 g, U95 melebihi MPE kepingnya sendiri

Budget didominasi neraca, jadi U95 nyaris rata **0,1223–0,1291 mg** dari ujung
ke ujung — tidak peduli kepingnya 0,1 g atau 200 g:

| Nominal | MPE F1 | U95 terbit | Rasio |
|---|---|---|---|
| 0,1 g | 0,05 mg | 0,1223 mg | **2,4×** |
| 0,2 g | 0,06 mg | 0,1223 mg | **2,0×** |
| 0,5 g | 0,08 mg | 0,1223 mg | **1,5×** |
| 1 g | 0,10 mg | 0,1224 mg | **1,2×** |
| 2 g | 0,12 mg | 0,1224 mg | 1,02× |
| 5 g | 0,16 mg | 0,1224 mg | 0,77× |
| 50 g | 0,30 mg | 0,1232 mg | 0,41× |

Untuk keping 1 g ke bawah, **ketidakpastian pengukurannya lebih besar dari
toleransi kepingnya** — kesesuaian tidak bisa dinyatakan ke arah mana pun.
Dan itu memakai U95 sekarang; kalau §1 dijawab "pakai gabungan harian",
batasnya bergeser ke sekitar 5 g.

Neraca yang tepat untuk keping sekecil itu ada di lab dan **tidak dipilih**:
**Semi Micro Balance**, keterulangan 0,0019 mg — **24× lebih baik** daripada
Analytical Balance — dengan kapasitas 80 g, cukup untuk seluruh keping sampai
50 g.

**Pertanyaan:** Semi Micro Balance diwajibkan di bawah ambang tertentu? Berapa
ambangnya? (Kalau ambangnya 80 g, 18 dari 20 titik sesi ini pindah neraca.)

---

## §13 — Koreksi lingkungan dihitung, lalu tidak dipakai

Rantai `nilai → titik indeks → koreksi` lengkap dan benar untuk ketiga besaran.
Sudah saya verifikasi aturan pemilihannya: **titik indeks TERDEKAT, seri
memilih yang lebih rendah** — suhu 23,05 → 20,1 (jaraknya 2,95, seri persis
dengan 26); RH 55,5 → 59,2; tekanan 933,15 → 931.

Lalu `ρ_udara` membaca **rata-rata MENTAH**, bukan yang sudah dikoreksi.
Terbukti: `ρ_udara = 1,0909731524586854 kg/m³` hanya reproduksi dengan
T = 23,05 · RH = 55,5 · P = 933,15. Dengan nilai terkoreksi angkanya lain.

Kelas yang sama dengan koreksi timer Flowmeter yang dihitung lalu dibuang.

Catatan: seri di suhu itu bukan kebetulan yang aman. 23,05 berjarak persis sama
dari 20,1 dan 26; kalau pemilihnya memilih yang lebih tinggi, koreksinya jadi
+1,0 °C alih-alih +0,1 °C. Selama koreksi tidak dipakai, dampaknya nol — tapi
aturan pemutus seri itu perlu ditulis sebelum ada yang menyambungkannya.

---

## §14 — Rumus densitas udara: pendekatan, bukan CIPM-2007

```
ρ_udara = ((0,34848·P) − (0,009·RH)·EXP(0,061·T)) / (T + 273,15)
```

Ini rumus sederhana **OIML R111 Lampiran E**, bukan CIPM-2007 penuh. Sudah
diverifikasi cocok dengan master sampai digit terakhir.

**Pertanyaan:** pendekatan itu memang yang disepakati untuk lingkup ini?
(Untuk kelas F1 dan U95 sebesar sekarang, selisih keduanya tidak akan terlihat —
pertanyaannya soal apa yang ditulis di prosedur, bukan soal angka.)

---

## §15 — Status akreditasi: kertasnya sudah menjawab, mohon konfirmasi

Kelompok **Massa** di lampiran LK-285-IDN memuat satu baris: no. 12,
*"Timbangan (Elektronik, mekanik)"*. Kalibrasi **anak timbangan** tidak ada.

Tapi `INPUT DATA` mencetak `LK-285-IDN` di kepala lembar, dan tabel CMC yang
dipakai workbook ini (`DATABASE`: pita A 0,1–100 g = 0,00016 g, B 100–210 g =
0,0004 g, … I 200–2000 kg = 450 g) berlabel **"Jenis Timbangan"** — itu pita
CMC untuk **timbangan**, alat yang lain.

**Kertas lembar kerjanya sendiri sudah menjawab:** formulirnya bernama
`SIDIK-FM-CAL-0541_Rev.0 - LEMBAR KERJA ANAK TIMBANGAN **(Non KAN)**`.

**Pertanyaan (konfirmasi):** sertifikat anak timbangan **tidak** membawa klaim
akreditasi — betul? Server akan menyetel `dalamLingkupAkreditasi() === false`
dan **tidak** memungut CMC timbangan sebagai lantai.

---

## §16 — Tidak ada lantai CMC, dan tidak ada yang menampung komponen hilang

Sel berlabel `CMC PT. SIDIK` di kedua puluh blok budget **kosong** — sudah saya
periksa satu per satu, dan `U95% Sertifikat` selalu sama persis dengan
`U = k·Uc` tanpa perbandingan apa pun.

Di alat ini itu **konsisten** dengan §15: memang di luar lampiran, jadi tidak
ada pita yang berlaku. Tapi konsekuensinya tetap: kalau suatu saat kolom
keterulangan kosong atau drift keping terbaca nol, `U95` langsung terbit terlalu
kecil dan **tidak ada apa pun yang terlihat ganjil**. Gerbang penerbitannya
dipatok eksplisit di server, bukan disandarkan ke lantai yang tidak ada.

---

## §17 — Tanggal neraca yang perlu dipastikan

`DATABASE` mencantumkan satu kolom tanggal per neraca berlabel
**"Due Date Calibration"**:

| Neraca | Tanggal di kolom itu | Tanggal kalibrasi sesi |
|---|---|---|
| Semi Micro Balance | 2026-01-26 | 2026-01-27 |
| Analytical Balance | 2026-01-19 | 2026-01-27 |
| Fujitsu / Excellent / Mettler | 2026-01-19 | 2026-01-27 |

Kalau kolom itu benar-benar *due date*, seluruh sesi dikerjakan dengan neraca
yang sudah lewat masa berlakunya. Kalau yang dimaksud sebenarnya *tanggal
kalibrasi* (mislabel), tidak ada masalah sama sekali.

Berbeda dengan keping standar, yang punya kolom lengkap (Calibration Date +
Interval + Due Date) dan semuanya masih berlaku.

**Pertanyaan:** kolom itu tanggal kalibrasi atau tanggal jatuh tempo? Server
akan memblokir sesi yang memakai standar kedaluwarsa, jadi jawabannya menentukan
apakah gerbang itu menyala untuk data historis.

---

## §18 — Butir kecil yang tetap dicatat

- **Kelipatan `1,414` pada komponen resolusi.** `ui = (U/1,73) × 1,414` —
  resolusi dihitung masuk dua kali (baca standar + baca UUT). Sah, tapi
  angkanya **1,414 literal**, bukan `√2` (1,41421356…): selisih 0,015 %.
  Termasuk yang diubah di revisi 3 (§0b).
- **Pembagi `1,73`** alih-alih `√3` (1,7320508…), di tiga komponen. Selisih
  0,12 %, arahnya menaikkan `ui` — jadi konservatif.
- **`U` komponen Bouyancy = 0,12 kg/m³**, tetap di kedua puluh blok. Itu sekitar
  11 % dari `ρ_udara` yang dihitung (1,0910 kg/m³). Dari mana angkanya?
- **Ketidakpastian sensitivitas `usens` negatif** (−0,00026461 mg untuk
  Analytical Balance). Rantainya sudah saya reproduksi:
  `usens = de_sens × √((s/Δm)² + (U_Msens/Msens)²)` dengan `de_sens = −0,15 mg`.
  Tandanya ikut `de_sens`, jadi negatif — dan karena masuk budget sebagai
  kuadrat, tandanya tidak berpengaruh. Tapi ketidakpastian bertanda negatif
  tetap perlu keterangan di prosedur.
- **Keterulangan neraca ada dua versi di satu workbook.** `DATABASE` mencatat
  `Stdev` Analytical Balance = 0,085 mg; `Deviasi Standard Timbangan` memulangkan
  0,046363 mg. Yang dipakai budget yang kedua. Yang pertama dipakai siapa?
- **Baris 10 kg di tabel standar**: kolom drift = **2,3094010767038933**
  ( = 4/√3) sementara seluruh baris lain drift = `U/2`. Satu baris memutus pola.
  Baris itu juga satu-satunya yang tertelusur ke LK-022-IDN, bukan LK-045-IDN,
  dan `U` = 15 mg-nya lebih besar daripada baris 20 kg (8,1 mg).
- **`k` dicetak sekali di sertifikat** (1,9765750658304413, milik titik 1),
  padahal tiap titik punya `k` sendiri (1,9765751 … 1,9780988 … 1,9786708).
  Selisihnya kecil, tapi yang tercetak berlaku untuk satu baris saja.
- **Kolom Nominal sertifikat memuat teks `200*`** di baris ketiga, di tengah
  kolom yang isinya angka.
- **Kapasitas di sertifikat tertulis `100 g - 200 g`** padahal `INPUT DATA`
  menyebut kapasitas alat 200 g dan titik terkecil yang diuji 0,005 g.
- **`Ketelitian Baca Alat` = 0** di kepala budget. Anak timbangan memang tidak
  punya daya baca, jadi nol itu benar — dan memang tidak dipakai komponen mana
  pun.

---

## §19 — Meter `TH-7` tidak rekonsiliasi dengan dirinya sendiri

`DATABASE` memuat dua meter lingkungan. **Thermobarometer** — yang dipakai sesi
ini — rekonsiliasi sempurna: kolom Correction sama persis dengan `Standart
Indication − Instrument Indication` di kelima baris suhu, kelima baris
kelembaban, dan kesembilan baris tekanan.

**TH-7 tidak:**

| Besaran | Standar | Instrumen | Selisih | Tertulis | Beda |
|---|---|---|---|---|---|
| Suhu | 15,32 | 15 | 0,32 | **0,36** | +0,04 |
| Suhu | 20,36 | 20 | 0,36 | **0,40** | +0,04 |
| Suhu | 30,27 | 30 | 0,27 | **0,31** | +0,04 |
| Suhu | 40,78 | 40 | 0,78 | 0,78 | 0 (cocok) |
| Suhu | 50,49 | 50 | 0,49 | **0,51** | +0,02 |
| RH | 48,72 | 50 | −1,28 | **−0,88** | +0,40 |
| RH | 69,06 | 70 | −0,94 | **−0,54** | +0,40 |
| RH | 77,54 | 80 | −2,46 | **−2,06** | +0,40 |
| RH | 86,4 | 89 | −2,60 | **−2,20** | +0,40 |

Offsetnya **tetap** (0,04 untuk suhu, 0,40 untuk kelembaban) dan meleset di
empat dari lima baris — pola yang tidak bisa dijelaskan dari nilainya saja.

Tabel TH-7 karena itu **tidak disalin ke server**. Generatornya menolak menulis
sampai butir ini dijawab, dan penolakan itu memang menggigit waktu pertama kali
dijalankan. Menyalin tabel yang tidak rekonsiliasi berarti menaruh angka yang
belum terverifikasi di server dengan tampang berwenang.

**Pertanyaan:** kolom Correction TH-7 diturunkan dari mana? Sertifikat
kalibrasinya sendiri, atau selisih kedua kolom di sebelahnya?

---

## §20 — Dimensi koefisien sensitivitas apung tidak konsisten

Komponen Bouyancy: `u` bersatuan **kg/m³**, `ci` = `(1/ρ_UUT − 1/ρ_std) · ms`
dengan `ms` dalam **gram**, dan hasil `u·ci` dijumlahkan bersama lima komponen
lain yang semuanya **miligram**.

Dibaca konsisten, `ci` seharusnya seribu kali lebih besar. Dampaknya sudah saya
hitung:

| | U95 sekarang | U95 kalau `ci` dibaca konsisten | naik |
|---|---|---|---|
| 100 g | 0,1263 mg | 0,1268 mg | 0,35 % |

Kecil, tapi bukan nol. Yang membuat saya menduga ini slip satuan: **workbook
yang sama mislabel satuan di tempat lain** — sel rantai sensitivitas berisi
`20075` berlabel mg padahal itu µg (20,075 mg).

Server **meniru master apa adanya**, karena "kemungkinan besar slip" bukan dasar
untuk menggeser U95 tiap sertifikat yang sudah terbit. Perlakuannya sama dengan §1.

**Pertanyaan:** `ci` apung dibaca per gram (seperti sekarang) atau per miligram?

---

## §21 — Kertas Rev.0 tidak punya kolom tekanan udara

Lembar `SIDIK-FM-CAL-0541_Rev.0` memuat kolom Awal/Akhir untuk **Suhu Ruangan**
dan **Kelembaban** — dan tidak ada baris tekanan udara sama sekali.

Tapi tekanan masuk langsung ke densitas udara, dan densitas udara masuk ke
koreksi apung **setiap keping**. Tanpa tekanan, tidak ada satu pun keping yang
bisa dihitung.

Workbook masternya sendiri punya kolomnya (`INPUT DATA` baris Tekanan Udara,
933,2 / 933,1 hPa), jadi datanya memang dikumpulkan — cuma tidak lewat lembar
ini. Lembar kerja server **tetap meminta tekanan**, dengan catatan pengisian
yang menjelaskan kenapa.

**Pertanyaan:** teknisi mencatat tekanan di mana sekarang? Dan Rev. berikutnya
menambahkan kolomnya?

---

## §22 — Kertas dan sertifikat menyebut standar yang berbeda

| | Kertas Rev.0 | Sertifikat master |
|---|---|---|
| Neraca tercetak | **2** (Timbangan Analitik, Timbangan Elektronik) | **5** |
| Set anak timbangan tercetak | tidak ada | **7** |
| Meter lingkungan | `TH-3` | `Thermobarometer` |
| Merk Analytical Balance | `Mettler Toledo/**X5204**` | `Mettler Toledo/**XS204**` |

Yang terakhir beda satu karakter — angka `5` lawan huruf `S`. Salah satunya
salah ketik, dan yang tercetak di kertas kerja teknisi bisa jadi yang salah.

Lembar kerja server memakai **daftar sertifikat** (kedua belas standar), karena
itu yang harus muncul di dokumen pelanggan.

**Pertanyaan:** `XS204` atau `X5204`? Dan kertas Rev. berikutnya mencetak
kelima neraca, atau cukup dua yang lazim dipakai?

---

## §23 — Kertas minta tiga pembacaan per baris, workbook memakai satu

Tiap blok keping di kertas berbentuk:

```
n. (   )   Nominal AT      X1    X2    X3
           Standard        __    __    __
           UUT             __    __    __
           UUT             __    __    __
           Standard        __    __    __
```

**Dua belas angka per keping.** Workbook masternya menyimpan **empat** — satu
sel per baris. Ke mana delapan sisanya, tidak tertulis di mana pun.

Server **merata-ratakan** berapa pun pembacaan yang ada per peran. Alasannya:
itu satu-satunya perlakuan yang **runtuh jadi perilaku master** waktu deretnya
cuma berisi satu angka. Memilih yang pertama, yang terakhir, atau yang tengah
sama-sama membuang data yang sengaja dikumpulkan teknisi.

**Pertanyaan:** ketiga pembacaan itu dirata-ratakan teknisi sebelum ditulis ke
workbook, atau dipilih salah satu? Kalau dirata-ratakan, perlakuan server sudah
benar dan tinggal dikonfirmasi.

Catatan sekalian: kertas menyediakan **sepuluh** blok keping, sesi contoh
masternya berisi **dua puluh**. Lembar kerja server memakai sepuluh (mengikuti
kertas) dan barisnya bisa ditambah kalau setnya lebih panjang.

---

## Yang TIDAK bisa saya nilai dari berkas ini

Berkas `_imp` cuma menyimpan nilai. Hal-hal berikut **tidak** saya klaim, dan
baru bisa dipastikan dari `.xlsm` asli (§0a):

- letak sel persisnya yang salah rujukan di §2, §5, §7, dan §9;
- ada/tidaknya *defined name* yang putus (`#REF!`) dan tautan ke workbook lain;
- apakah tabel CMC di `DATABASE` ditarik lewat tautan luar atau diketik di sini.

Nilai-nilainya sendiri sudah cukup membuktikan **hasilnya** salah; yang belum
pasti cuma **di mana** memperbaikinya di master.

---

## Formulir keputusan

| § | Butir | Keputusan | Tanda tangan | Tanggal |
|---|---|---|---|---|
| 0a | Master `.xlsm` asli diserahkan | | | |
| 0b | Dasar perubahan revisi 3 (29 Mei 2026) | | | |
| 1 | Repeatability: sebaran antar-hari atau gabungan harian | | | |
| 2 | Sertifikat `001-CAL-126` — tarik / revisi / berlaku maju | | | |
| 3 | Ambang penolakan `\|de\|` — 10 × MPE atau lain | | | |
| 4 | Densitas F1 5 mg–50 mg — ada atau belum ditabelkan | | | |
| 6 | Tabel densitas → OIML R111 B.7 lengkap | | | |
| 8 | Keping standar dipilih lewat penanda, bukan nominal | | | |
| 10 | Vonis kesesuaian dicetak — ya / tidak, aturannya apa | | | |
| 11 | `no_identitas` wajib untuk keping kembar | | | |
| 12 | Semi Micro Balance wajib di bawah ambang — berapa | | | |
| 15 | Sertifikat tanpa klaim akreditasi (Non KAN) — konfirmasi | | | |
| 17 | Kolom tanggal neraca: kalibrasi atau jatuh tempo | | | |
| 19 | Asal kolom Correction TH-7 (tabelnya belum dipakai server) | | | |
| 20 | `ci` apung dibaca per gram atau per miligram | | | |
| 21 | Teknisi mencatat tekanan udara di mana; Rev. berikutnya | | | |
| 22 | `XS204` atau `X5204`; berapa neraca yang dicetak di kertas | | | |
| 23 | Tiga pembacaan `X1 X2 X3` — dirata-ratakan atau dipilih | | | |

Manajer Teknis: ______________________  Tanggal: ____________
