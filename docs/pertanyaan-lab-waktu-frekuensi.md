# Pertanyaan Lab — kelompok Waktu dan Frekuensi

Alat ke-22/23/24: **Timer/Stopwatch**, **Centrifuge**, **Infrared Tachometer**
(lampiran akreditasi LK-285-IDN no. 37, 38, 39).

Sumber: tiga workbook master ber-password yang turun 16 Apr 2026 —
`Master Olda Timer dan Stopwatch.xlsm`, `Master Olda Centrifuge.xlsm`,
`Master Olda Tachometer.xlsm`.

Semua pertanyaan di bawah lahir dari **pembuktian sel demi sel**: reimplementasi
Python diadu ke ketiga workbook, 464 nilai dibandingkan pada toleransi 5·10⁻⁶.
**Setiap** selisih yang muncul tercatat di sini — tidak ada satu pun yang
dibiarkan tanpa penjelasan.

Yang butuh keputusan manajer teknis ditandai **[PERLU JAWABAN]**. Yang sudah
diputuskan sepihak oleh kode (karena perilaku benarnya tidak ambigu) ditandai
**[SUDAH DIHITUNG BENAR]** — tetap perlu dibaca, karena angkanya bergerak.

---

## §1 — Ketidakpastian sertifikat kalibrator berbentuk pita  [SUDAH DIHITUNG BENAR]

Kolom `Uncertainty` di sheet `SERTIFIKAT KALIBRATOR` (kedua workbook rpm) cuma
terisi di **lima** dari tiga belas baris:

| Nominal (rpm) | 60 | 100 | 200 | 300 | 500 | 2000 | 5000 | 7000 | 10000 | 15000 | 20000 | 25000 | 30000 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| U95 | 0,21 | — | 0,2 | 1,5 | — | — | 1,6 | — | — | 3,1 | — | — | — |

Kode memperlakukan sel kosong sebagai **pita berlaku** sampai baris ber-nilai
berikutnya, dan memilih pita milik `Indexed Value` **tertinggi** dalam satu blok
tiga titik.

**Buktinya:** aturan itu diadu ke sebelas blok budget di dua workbook —
**sepuluh cocok persis**. Satu-satunya yang meleset adalah blok 5 Tachometer,
yang di master juga rusak di dua tempat lain (§2).

> **Konfirmasi diminta, bukan keputusan:** apakah benar sel kosong = pita
> berlaku, bukan "belum diisi"? Kalau ternyata "belum diisi", sepuluh blok yang
> sekarang cocok justru semuanya salah.

---

## §2 — Blok 5 Tachometer rusak di tiga tempat  [SUDAH DIHITUNG BENAR]

Blok budget `PERHITUNGAN U95%` baris 98–109 (rentang 10000–15000 rpm):

| Komponen | Rujukan master | Nilai master | Yang benar | Kenapa |
|---|---|---|---|---|
| Ketidakpastian sertifikat | `'SERTIFIKAT KALIBRATOR'!F15` | 1,6 | **3,1** (`F18`) | Titik tertinggi blok ini 15000 rpm, bernaung di pita `F18` |
| Drift standar | `'[1]Drift Std Kalibrator'!K54` | **0** | **2,25** (`K35`) | `K54` sel **kosong**, dan `[1]` itu **workbook lain** (Centrifuge) — nilainya tinggal cache |
| Pengulangan | `MAX(PERHITUNGAN!G113:L113)` | **0** | **0,268** (baris 94) | Baris 113 kosong; lima blok lain memakai baris 34/49/64/79/94 |

Akibatnya U95 master di blok ini **1,69 rpm** padahal hitungan yang benar
**4,04 rpm** — dua kali lipat lebih besar. Yang menyelamatkannya dari terbit
cuma lantai CMC 5,0 rpm yang kebetulan lebih besar dari keduanya.

Ketiganya kerusakan salin-tempel yang perilaku benarnya tidak ambigu (lima blok
tetangga di berkas yang sama melakukannya dengan benar), jadi **dihitung benar**
sesuai aturan proyek. Ditegakkan
`WaktuFrekuensiMasterTest::test_blok_rusak_tachometer_dihitung_lebih_besar`.

> **[PERLU JAWABAN]** Sertifikat Tachometer yang sudah terbit memakai blok ini
> — perlu ditarik/diterbitkan ulang, atau cukup dicatat? Karena lantai CMC
> menang, **angka tercetaknya tidak berubah** (5,0 rpm), jadi kemungkinan besar
> tidak ada sertifikat yang perlu disentuh. Mohon dikonfirmasi.

---

## §3 — Drift kalibrator: kolom `K` cuma berrumus sebagian  [SUDAH DIHITUNG BENAR]

**rpm** (`Drift Std Kalibrator`): 15 baris berdata, kolom `K` berrumus di **5**
(baris 15, 18, 26, 28, 32). Melengkapinya:

| | Master | Lengkap |
|---|---|---|
| Rentang koreksi maks | 4,5 rpm (titik 30000) | **5,5 rpm** (titik 25000) |
| Setengah-lebar (`/2`) | 2,25 rpm | **2,75 rpm** |

**waktu** (`Drift Stopwatch`): 13 baris berdata, kolom `K` berrumus di **4**.

| | Master | Lengkap |
|---|---|---|
| Rentang koreksi maks | 298 ms | **322 ms** (titik 30 menit) |

Baris yang tidak berrumus bukan baris kosong — datanya lengkap, cuma rumusnya
tidak ikut disalin. Dilengkapi, dan arahnya (selalu naik) ditegakkan test.

> **[PERLU JAWABAN]** Apakah kelima/keempat baris yang berrumus itu **pilihan
> sengaja** (mis. cuma titik yang dianggap wakil), atau memang rumusnya tidak
> ikut tersalin? Kalau sengaja, dasar pemilihannya apa? Titiknya (100, 500,
> 5000, 10000, 30000 rpm) tidak membentuk pola yang jelas.

---

## §4 — Arah pemutusan seri nominal BERBEDA antar kelompok  [PERLU JAWABAN]

Sel `Indexed Value` tidak punya rumus — teknisi mengetiknya. Aturan yang
terbukti: **nominal sertifikat terdekat**. Yang tidak sepakat cuma apa yang
terjadi kalau jaraknya **seri**:

| Kelompok | Set point | Kandidat | Jarak | Yang diketik master |
|---|---|---|---|---|
| rpm | 80 | 60 / 100 | 20 / 20 | **100** (ke atas) |
| rpm | 150 | 100 / 200 | 50 / 50 | **200** (ke atas) |
| **waktu** | **900 s** (15 menit) | 600 / 1200 | 300 / 300 | **600** (ke **bawah**) |

Kode meniru masing-masing kelompok apa adanya — `TabelStandarPutaran` memutus ke
atas, `TabelStandarWaktu` ke bawah. Menyeragamkannya diam-diam **menggeser
koreksi titik 900 detik sebesar 10 ms** dari yang sudah tercetak di sertifikat
pelanggan.

> **[PERLU JAWABAN]** Mana yang benar? Tiga kemungkinan:
>
> 1. Seri selalu ke atas → titik 900 s master salah ketik.
> 2. Seri selalu ke bawah → dua titik rpm master salah ketik.
> 3. Set point yang tidak persis ada di sertifikat **seharusnya tidak
>    dikalibrasi sama sekali** — dan ketiga titik itu semestinya ditolak.
>
> Perlu diingat kedua workbook rpm **berbagi data contoh yang sama** (Tachometer
> disalin dari Centrifuge), jadi kedua kasus "ke atas" itu sebenarnya **satu**
> pengamatan, bukan dua.

---

## §5 — Master Timer cuma punya SATU blok yang menghitung  [PERLU JAWABAN]

Dari lima blok `Set Point` di `PERHITUNGAN U95%`, **hanya yang pertama utuh.**
Empat sisanya rusak berlapis:

| Blok | Pengulangan | Penjumlahan | `k` |
|---|---|---|---|
| Set Point 1 | ✅ | `SUM(AC8:AC13)` — **6 komponen** | `TINV(0,05; veff)` |
| Set Point 2 | `#REF!` | `SUM(AC28:AC31)` — **4 komponen** | `2` diketik tangan |
| Set Point 3 | `#REF!` | `SUM(AC48:AC51)` — 4 komponen | `TINV` |
| Set Point 4 | `#REF!` | `SUM(AC68:AC71)` — 4 komponen | `2` diketik tangan |
| Set Point 5 | `#REF!` | `SUM(AC86:AC89)` — 4 komponen | `2` diketik tangan |

Penjumlahan empat komponen itu **membuang dua komponen human reaction**
(`uHRTB`, `uHRTSD`) yang justru ada di baris 32–33, tepat di bawah rentang yang
dijumlah.

Kode memakai bentuk **Set Point 1** — enam komponen, `k` dari `TINV` — untuk
semua titik.

> **[PERLU JAWABAN]** Konsekuensinya perlu disadari: **hitungan kita tidak bisa
> dibuktikan cocok di titik ke-2 dan seterusnya**, karena masternya sendiri
> tidak punya angka pembanding di situ. Mohon dikirim **satu workbook Timer yang
> keempat bloknya hidup** supaya bisa diadu — atau konfirmasi bahwa enam
> komponen memang bentuk yang benar.

---

## §6 — `uHRTB` cuma mencakup dua dari empat operator  [SUDAH DIHITUNG BENAR]

Sheet `Human Reaction`, empat operator (NR, HN, DT, AW) masing-masing sepuluh
kali di nominal 10 detik. Dua sel bersebelahan menutup tabel itu:

| Sel | Rumus | Cakupan |
|---|---|---|
| `N23` | `MAX(N21:N22)` | **DT & AW saja** |
| `P23` | `MAX(P19:P22)` | keempatnya |

Ketimpangan dua sel bersebelahan itulah yang menjadikannya kerusakan
salin-tempel, bukan pilihan metode.

| | Master | Lengkap |
|---|---|---|
| Rata-rata reaksi terbesar | 0,0191 s (AW) | **0,0351 s** (HN) |
| `uHRTB` (`/√3`, lalu `/2`) | 0,01103 s | **0,02026 s** |

> **Catatan:** data reaksi operator ini tertanggal dan menyebut inisial orang.
> Kalau susunan teknisi berubah, tabel ini perlu diambil ulang — nilainya
> berlaku untuk **setiap** sesi Timer sampai diganti.

---

## §7 — Centrifuge diukur di luar pita akreditasi  [PERLU JAWABAN]

Blok 5 `Master Olda Centrifuge.xlsm` mengukur **15000, 20000, dan 25000 rpm**.
Lampiran akreditasi LK-285-IDN no. 38 (Centrifuge) berhenti di **9000 rpm**:

| Pita akreditasi Centrifuge | CMC |
|---|---|
| 60 – 200 rpm | 1,5 rpm |
| 200 – 9000 rpm | 1,6 rpm |

Master tetap memakai CMC **1,6 rpm** untuk ketiga titik itu — angka dari pita
yang tidak mencakupnya. Kalau tercetak apa adanya, sertifikatnya menyatakan
ketidakpastian terakreditasi untuk putaran yang lampirannya tidak pernah
mencakup.

Kode **tidak menolak** sesinya (lab boleh mengkalibrasi di luar lingkup), tapi
mengangkat **peringatan sesi** yang harus dilewati admin secara sadar —
`ProfilPutaran::peringatanSesi()`, berlaku untuk Centrifuge **dan** Tachometer.

### Lantai CMC-nya sempat hilang, bukan cuma "di luar lingkup"

Pemilihan pita CMC memakai titik **tertinggi** di blok, dan sebelumnya
memulangkan `null` kalau tidak ada pita yang memuatnya. `null` berarti
`max($u95, 0)` — lantainya lenyap untuk **satu blok penuh**, termasuk dua titik
lain di blok itu yang justru berada di dalam lingkup. Terukur di sistem yang
berjalan, pembacaan rapat di 60 & 100 rpm:

| Blok (Tachometer) | Pita yang terpilih | U95 tercetak |
|---|---|---|
| 60, 100, 12000 rpm | 7000 – 30000 | **5,00 rpm** |
| 60, 100, 40000 rpm | *(tidak ada)* | **4,44 rpm** |

Makin jauh titik ketiganya keluar lingkup, makin **bagus** ketidakpastian yang
terbit. Sekarang blok yang titik tertingginya tidak tercakup **meminjam pita
terdekat** — perilaku master (blok 5 Centrifuge memakai CMC 1,6 rpm untuk 15000–
25000 rpm) dan yang memang sudah dijanjikan teks peringatannya. Pita yang
dipinjam ikut tertulis di jejak audit `type_b_components`, jadi lantai pinjaman
bisa dibedakan dari lantai yang benar-benar menaungi titiknya. Dijaga
`LantaiCmcPutaranTest`.

> **[PERLU JAWABAN]** Dua kemungkinan: (a) blok 5 itu memang milik lembar
> Tachometer yang ikut tersalin ke workbook Centrifuge, atau (b) lab memang
> melayani centrifuge sampai 25000 rpm di luar lingkup akreditasi. Kalau (b),
> bagaimana sertifikatnya harus menandai titik-titik itu?

---

## §8 — Pembagi drift beda antar kelompok  [PERLU JAWABAN]

| Kelompok | Rumus master | Arti |
|---|---|---|
| rpm | `K35 = MAX(K13:K34)/2` | rentang penuh **dibagi 2** = setengah-lebar |
| waktu | `K26 = MAX(K12:K25)` | rentang penuh **apa adanya** |

Keduanya lalu dibagi √3 (sebaran persegi). Untuk sebaran persegi, pembilang yang
benar adalah **setengah-lebar** — jadi kelompok rpm benar, dan kelompok waktu
**dua kali lebih konservatif** dari yang seharusnya.

Ditiru apa adanya: mengubahnya **mengecilkan** ketidakpastian yang sudah terbit,
dan itu bukan keputusan yang boleh diambil kode.

> **[PERLU JAWABAN]** Apakah drift stopwatch memang sengaja dibuat konservatif,
> atau `/2`-nya kelupaan? Kalau kelupaan, U95 Timer akan turun — dan karena
> lantai CMC 0,81 s hampir selalu menang, kemungkinan besar **angka tercetaknya
> tidak berubah**.

---

## §9 — Jumlah hari drift memakai satu interval saja  [PERLU JAWABAN]

| Kelompok | Rumus | Nilai | Rentang koreksi yang dibaginya |
|---|---|---|---|
| rpm | `L8 = G10-F10` | 420 hari | koreksi lintas **7** sertifikat (2019–2025) |
| waktu | `L7 = I9-H9` | 360 hari | koreksi lintas **4** sertifikat (2022–2025) |

Rentang koreksi membentang bertahun-tahun, tapi dibagi jumlah hari **satu**
interval saja — rpm memakai interval pertama, waktu memakai interval terakhir.

Ini tidak berpengaruh ke angka apa pun sekarang: kolom `Drift per hari`/`per
tahun` **tidak dipakai** budget mana pun — yang dipakai rentang mentahnya
(`K`). Diangkat supaya tidak jadi jebakan waktu ada yang memutuskan memakai
kolom itu.

---

## §10 — Sheet SERTIFIKAT Tachometer menukar kedua kolomnya  [SUDAH DIHITUNG BENAR]

Ditemukan waktu mengaudit jalur cetak, sesudah §1–§9 ditulis. Ini yang paling
langsung kelihatan di dokumen yang dipegang pelanggan.

Kedua workbook rpm memakai data contoh yang **sama persis**, tapi sheet
`SERTIFIKAT`-nya menyusun kolom secara **berlawanan**:

| | Kolom `Standard Value` | Kolom `Unit Under Test` | `Correction` |
|---|---|---|---|
| **Centrifuge** `C19`/`J19` | `PERHITUNGAN!G32` — standar terkoreksi **59,78** | `G22` — set point **60** | `C−J` = **−0,22** |
| **Tachometer** `C19`/`J19` | `PERHITUNGAN!G22` — set point **60** | `G32` — standar terkoreksi **59,78** | `C−J` = **+0,22** |

Data identik, **tanda koreksinya berlawanan**.

Yang benar Centrifuge, dan alasannya bukan selera: `Correction` menurut definisi
adalah angka yang **ditambahkan ke penunjukan alat** supaya ketemu nilai benar,
jadi `Correction = nilai benar − penunjukan alat`. Di kolom Centrifuge nilai
benar (standar terkoreksi) ada di kiri dan penunjukan alat (set point) di kanan —
sesuai. Tachometer menaruhnya terbalik, sehingga tandanya ikut terbalik.

Bahwa Tachometer disalin dari Centrifuge sudah dibuktikan terpisah (§2: tautan
luar `[1]` yang masih menunjuk `Master Olda Centrifuge.xlsm`, dan sheet
`PERHITUNGAN` yang identik baris demi baris). Sheet Timer memakai konvensi yang
sama dengan Centrifuge (`SERTIFIKAT!E19:L19` menunjuk baris `STD CORRECTED`,
bukan set point), jadi **dua dari tiga** master sepakat.

Yang dilakukan kode: memakai konvensi Centrifuge/Timer untuk **ketiganya**.
Dijaga `WaktuFrekuensiSertifikatTest`, yang menegakkan dua hal sekaligus —
identitas `Standard ≡ UUT + Correction` di setiap titik, dan angka baris
pertamanya lawan sel masternya.

> **[PERLU JAWABAN]** Sertifikat Tachometer yang **sudah terbit** memakai
> konvensi terbalik itu, jadi kolom dan tanda koreksinya berbeda dari yang akan
> diterbitkan sistem ini. Perlu diterbitkan ulang, atau cukup berlaku maju saja?
> Ini satu-satunya penyimpangan yang benar-benar **mengubah angka tercetak**
> (bukan cuma ketidakpastian) — §2 sampai §6 semuanya tertutup lantai CMC.

---

## §11 — Data 15000 rpm berdesimal satu, budgetnya bilangan bulat  [PERLU JAWABAN]

Blok budget master memilih daya baca tachometer standar dari nominalnya: blok
ber-nominal tertinggi 10000 rpm memakai **0,1 rpm**, yang 15000 rpm memakai
**1 rpm** (`PutaranCalculator::AMBANG_RESOLUSI_STANDAR`). Tapi pembacaan
masternya di 15000 rpm justru **berdesimal satu** — `15000,4`.

Jadi master menyatakan dua hal yang bertentangan tentang alat yang sama: kalau
standarnya benar-benar membaca bilangan bulat di atas 10000 rpm, `15000,4` tidak
mungkin muncul di layarnya; kalau dia membaca 0,1 rpm di sana, komponen resolusi
budgetnya sepuluh kali terlalu besar.

Yang dilakukan kode: **menuruti budget** (0,1 rpm sampai 10000, 1 rpm di atasnya)
karena itu yang menentukan angka U95 tercetak, dan membiarkan pemeriksa
`pembacaan_bukan_kelipatan_resolusi` mengangkat satu peringatan per sesi di titik
itu. Satu peringatan yang isinya benar, bukan lima puluh tiga yang isinya salah —
lihat `PeringatanPalsuWaktuFrekuensiTest`.

> **[PERLU JAWABAN]** Berapa daya baca tachometer standar di atas 10000 rpm —
> 0,1 rpm (dan komponen resolusi blok 5 & 6 terlalu besar), atau 1 rpm (dan
> desimal di data 15000 rpm salah ketik)? Pengaruhnya ke U95 kecil, tapi dua
> jawaban itu tidak bisa dua-duanya benar.

---

## §12 — Set point di luar jangkauan sertifikat kalibrator  [SUDAH DIBLOKIR]

Kedua tabel standar memungut nominal sertifikat **terdekat** untuk satu set
point. Sebelumnya tanpa batas jarak, jadi set point berapa pun mendapat
padanan — betapa pun jauhnya:

| Alat | Set point | Nominal yang dipungut | Yang terbit |
|---|---|---|---|
| Stopwatch | 7200 s | 3600 s | koreksi −20 ms, U95 0,81 s |
| Stopwatch | 86400 s | 3600 s | sama, jaraknya 23× lipat |
| Stopwatch | 1 s | 5 s | koreksi +30 ms = 3% penunjukan |
| Tachometer | 500000 rpm | 30000 rpm | koreksi −2,0 rpm |

Semuanya terbit lengkap dengan U95 dan lantai CMC, tanpa satu pun peringatan.
Dan **tiga penjagaan yang memang ditulis untuk ini adalah kode mati**:
`WaktuCalculator` memeriksa `koreksiMs($nominal) === null` dan
`u95Sertifikat($nominal) === null`, `PutaranCalculator` memeriksa
`koreksi($nominal) === null` — tapi `$nominal`-nya diambil dari daftar itu
sendiri, jadi ketiganya selalu ketemu. Cabang yang tak terjangkau itu bahkan
menyimpan kesalahan fatalnya sendiri (`end()` atas nilai balik fungsi), yang
baru terbit setelah jalannya dibuka.

Sekarang `nominalTerdekat()` memulangkan `null` di luar `[nominal terkecil,
nominal terbesar]` sertifikat — 5–3600 detik dan 60–30000 rpm — sehingga
titiknya masuk `belum_dihitung` dengan alasan yang menyebut jangkauannya.
Seluruh set point master maupun `TITIK_SARAN` ada di dalam jangkauan itu, jadi
tidak ada angka yang bergeser. Dijaga `NominalDiLuarSertifikatDitolakTest`.

> **Catatan, bukan pertanyaan terbuka.** Lab tetap boleh mengkalibrasi di luar
> jangkauan kalibratornya; yang tidak boleh adalah menerbitkan koreksi yang
> ditebak dari baris terdekat. Kalau lab memang melayani titik di luar itu,
> yang dibutuhkan baris sertifikat kalibrator baru — bukan pelonggaran di kode.

---

## Ringkasan status

| § | Pokok | Status | Pengaruh ke angka tercetak |
|---|---|---|---|
| 1 | Pita ketidakpastian sertifikat | Dihitung benar, konfirmasi diminta | — (10/11 blok cocok) |
| 2 | Blok 5 Tachometer rusak 3x | Dihitung benar | Tidak ada (CMC menang) |
| 3 | Kolom `K` drift sebagian | Dilengkapi | U95 naik tipis |
| 4 | Arah seri nominal | **Ditiru per kelompok** | 10 ms di satu titik |
| 5 | Timer cuma 1 blok hidup | Bentuk SP1 dipakai semua | Belum terbukti di titik 2+ |
| 6 | `uHRTB` 2 dari 4 operator | Dihitung benar | U95 naik tipis |
| 7 | Centrifuge di luar lingkup | Peringatan sesi (kedua alat) + pita terdekat dipinjam | U95 di luar lingkup **naik** ke lantai CMC |
| 8 | Pembagi drift beda | Ditiru | — |
| 9 | Hari drift satu interval | Ditiru (tidak terpakai) | — |
| **10** | **Sheet SERTIFIKAT Tachometer menukar kolom** | **Konvensi Centrifuge/Timer dipakai** | **TANDA koreksi berbalik** |
| 11 | Data 15000 rpm berdesimal, budgetnya bulat | Budget dituruti | — (1 peringatan/sesi) |
| 12 | Set point di luar jangkauan sertifikat | **Diblokir** dengan alasan | — (nol set point master kena) |
