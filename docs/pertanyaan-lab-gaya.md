# Pertanyaan Lab — Alat Gaya (UTM, Load Cell, Proving Ring)

> Ditulis 24 Sep 2026, waktu ketiga workbook master gaya dibongkar dan Tahap 1–2
> olah datanya dibangun. Sembilan butir: enam dari panduan teknis yang ikut
> dikirim bersama master, tiga ditemukan waktu ketiga workbook dibandingkan satu
> sama lain.
>
> **Yang perlu dipahami sebelum membaca:** tidak satu pun dari ini "dibetulkan"
> di kode. Aturan repo (AGENTS.md §Aturan yang Lahir dari Kesalahan Nyata) —
> master lab **ditiru**, kejanggalannya diangkat jadi pertanyaan, dan yang
> memutuskan manajer teknis. Membetulkannya sendiri menggeser angka yang sudah
> tercetak di sertifikat pelanggan, dan pergeserannya tidak akan ketahuan siapa
> pun karena tidak memunculkan error.

---

## G1 🔴 Sertifikat standar `Load Cell 5 kN` sudah kedaluwarsa

`STANDAR_LOADCELL.csv` ketiga workbook menulis:

```
Load Cell 5 kN (500kg)   S/N LC-01-SDK
Tanggal Kalibrasi  : 2024-06-13
Due Date Kalibrasi : 2026-06-13      <- sudah lewat
```

Per hari ini (24 Sep 2026) tanggal itu **lewat tiga bulan**. Standar acuan yang
lewat masa berlaku tidak boleh dipakai kalibrasi, dan sistem memang sudah
menolaknya: validator memberi temuan tingkat **ERROR** `standar_kadaluarsa`, dan
sesi yang memakainya tidak bisa disetujui.

**Yang perlu dijawab:**

1. Apakah load cell 5 kN itu sudah direkalibrasi dan tanggalnya belum masuk
   sistem? Kalau ya, kirim sertifikat barunya — tabel koreksinya ikut berubah
   dan harus digenerate ulang.
2. Kalau belum direkalibrasi: berapa sesi gaya yang sudah dikerjakan sejak
   13 Jun 2026 memakainya? Itu menentukan ada-tidaknya ketidaksesuaian yang
   harus dicatat.

Sesi contoh di seeder sengaja **tidak** memakai tanggal itu, dan alasannya
ditulis di `UtmSeeder`: sesi contoh yang selalu ber-ERROR melatih orang
mengabaikan temuan yang justru paling penting dibaca.

### Diperluas 25 Sep 2026 — BUKAN cuma yang 5 kN

Waktu Proving Ring dikerjakan, standar ketiga ikut diperiksa:

| Standar | `berlaku_sampai` di master | Keadaan 25 Sep 2026 |
|---|---|---|
| Load Cell 5 kN | 13 Jun 2026 | **kedaluwarsa** |
| Load Cell 100 kN | 17 Des 2026 | masih berlaku |
| Load Cell 3000 kN | 10 Sep 2026 | **kedaluwarsa** |

**Dua dari tiga load cell standar gaya sudah lewat masa berlakunya**, dan yang
3000 kN baru lewat lima belas hari — jadi ini bukan temuan lama yang tertinggal,
tapi yang sedang berjalan.

Sesi contoh keduanya memakai tanggal yang dimajukan **di seeder saja**, dengan
alasan yang sama: sesi contoh yang selalu ber-ERROR "standar kadaluarsa" melatih
orang mengabaikan temuan yang justru paling penting dibaca. Angka itu tidak ikut
ke mana pun selain fixture.

**Yang perlu dijawab:** kapan keduanya direkalibrasi, dan sampai itu terjadi —
apakah kalibrasi gaya yang memakainya boleh tetap diterbitkan.


---

## G2 🔴 Tiga workbook tidak sepakat soal drift standar yang SAMA

Standar fisik yang sama (`Load Cell 5 kN`, S/N J10CC13283), kolom
`Udrift (%) f.s` arah **Tarik**:

| Workbook | Nilai |
|---|---|
| `Gaya_UTM` | `0` |
| `Gaya_Load_Cell` | `0.04000000000000001` |
| `Gaya_Proving_Ring` | `0.04000000000000001` |

Arah Tekan ketiganya sepakat (`0.0692820323027551`).

Ini bukan selisih pembulatan — salah satunya salah, dan yang dipakai menentukan
U95% yang terbit. Ketiganya disimpan apa adanya di
`database/data/tabel-standar-gaya.json` (`drift[standar][arah][workbook]`), dan
profil alatnya memilih menurut workbook-nya sendiri.

**Yang perlu dijawab:** berapa drift arah tarik load cell 5 kN yang benar, dan
dari mana angkanya (sertifikat rekalibrasi? catatan riwayat?).

---

## G3 🟠 Komponen Drift tidak dibagi divisornya — di KETIGA workbook

Di sheet `PERHITUNGAN U95%`, kolom `ui` tiap baris = `U / Divisor`. Kecuali
baris **Drift Standard**:

```
r9  : ui = N9/Q9      ✓
r12 : ui = N12/Q12    ✓
r13 : ui = N13        ✗   <- divisor akar-3 tertulis di kolomnya, tapi tidak dipakai
r14 : ui = N14/Q14    ✓
```

Dampaknya terukur: kontribusi drift jadi akar-3 kali lebih besar, dan dia
komponen terbesar **kedua** sesudah sertifikat kalibrator. Kalau divisornya
dipakai, `uc` sesi contoh turun dari `0,212693` jadi `0,205032` — sekitar 3,6%,
dan itu pergeseran yang sampai ke angka tercetak.

Direplikasi apa adanya. Besarnya pergeseran dikunci `GayaBudgetTest` supaya
dampaknya kelihatan begitu keputusan turun.

**Yang perlu dijawab:** apakah nilai di `Tabel_Drift` memang sudah "pre-divided"
(sehingga tidak boleh dibagi lagi), atau rumusnya yang kelupaan dibagi?

Catatan yang mungkin membantu: nilai UTM `0,0692820323027551` = **0,04 × akar 3
persis**. Tapi dua nilai drift lain (`0,031834…` dan `0,074285…`) bukan kelipatan
akar-3 dari angka bulat, jadi pola itu tidak konsisten di seluruh tabel.

---

## G4 🟠 Identitas standar di workbook Proving Ring rusak (`#REF!`)

```
Gaya_Proving_Ring/STANDAR_LOADCELL.csv
  Merek : #REF!      Type : #REF!      S/N : #REF!
```

Dua workbook lain menulisnya utuh (`Usscell/STI-C3-500kg`, `LC-01-SDK`).

Artinya workbook Proving Ring **tidak bisa memberi tahu standar mana yang
dipakai** — rujukannya putus. Untuk lab terakreditasi, "sertifikat ini tertelusur
ke standar yang mana" bukan pertanyaan opsional.

Tidak ditambal dari workbook tetangga: yang menambal diam-diam membuat
sertifikat menyebut ketertelusuran yang tidak pernah dibaca dari sumbernya.

**Yang perlu dijawab:** rujukan mana yang putus, dan apakah sesi Proving Ring
yang sudah terbit memakai standar yang sama dengan dua workbook lain.

---

## G5 🟡 `Correction` memakai nilai SEBELUM koreksi termal, sertifikat mencetak yang SESUDAH

```
AA (Correction)   = Y − B     <- Y, sebelum koreksi termal
Standard Value    = Z         <- sesudah koreksi termal
```

Akibatnya pembaca sertifikat yang menghitung `Standard Value − Unit Under Test`
**tidak akan mendapat angka di kolom Correction**. Di sesi contoh selisihnya
0,00066 kN.

Direplikasi apa adanya; `GayaCalculatorTest` mengunci selisihnya.

**Yang perlu dijawab:** mana yang benar — Correction dihitung dari Y (dan kolom
Standard Value yang seharusnya mencetak Y), atau Correction seharusnya dari Z?

---

## G6 ✅ DIKOREKSI — koreksi suhu Proving Ring GANDA di rumus, TUNGGAL di hasil

Versi pertama butir ini menyebut Proving Ring memakai dua koreksi suhu pada satu
rantai dan menanyakan apakah itu dimaksudkan. Sesudah diperiksa sel per sel,
pertanyaannya hampir seluruhnya gugur.

Yang benar-benar bekerja cuma **satu**: faktor ruangan
`G52 = 1 + 0,00027 × (23 − T_ruangan)`, acuannya 23 °C dipatok di rumus, dipakai
dua kali — pada pembacaan dial (`J`) dan pada nilai standar (`Y`).

Koreksi termal terhadap suhu sertifikat kalibrator — yang menggigit di UTM —
**ada di rumusnya tapi tidak pernah menggigit di sini**:

```
Z = Y × (1 + 0,00027 × ('INPUT DATA'!H6 − 'INPUT DATA'!K7))
```

Lembar Proving Ring tidak punya field *Actual Temperature of Standard* yang
dipunyai lembar UTM, jadi pengurangnya nol dan **`Z` sama persis dengan `Y` di
kesembilan barisnya** — diperiksa satu per satu, bukan disimpulkan dari satu
baris.

Sistem menyamakan perilakunya: kedua argumen suhu diberi nilai yang sama
sehingga faktornya tepat 1. Kalau suatu saat lab menambahkan field itu, yang
berubah satu argumen di `ProvingRingProfile`.

**Yang masih layak ditanyakan, tapi ringan:** apakah acuan 23 °C itu memang
suhu acuan metode Proving Ring, dan kenapa berbeda dari UTM yang mengacu ke suhu
sertifikat kalibratornya.

---

## G7 🟡 Misalignment: milik alat, atau milik mesin uji lab?

Empat pengukuran `Misalignment Axial` (X1..X4) masuk budget lewat
`STDEV / rata-rata × 100`. Yang belum jelas: nilainya sifat **pemasangan alat
yang sedang dikalibrasi**, atau sifat **mesin uji lab** yang dipakai mengukur.

Menentukan bentuk isian: kalau milik alat, diisi tiap sesi (seperti sekarang);
kalau milik mesin lab, tempatnya tabel referensi dan cukup diperbarui waktu
mesinnya diservis.

---

## G8 🟠 Budget Proving Ring cuma menjumlahkan 6 dari 8 komponen — TERBUKTI, bukan dugaan

Versi pertama butir ini menulisnya sebagai dugaan yang "akan dihadapi waktu
Tahap 4 dikerjakan". Sekarang sudah dihitung, dan angkanya tidak menyisakan
ruang tafsir.

`PERHITUNGAN U95%` Proving Ring mendaftarkan delapan komponen, lalu sel `Jumlah`
berisi `0,04703237424735414`. Dijumlahkan kuadrat `uici`-nya:

| Yang dijumlahkan | Hasil | Beda dari `Jumlah` master |
|---|---|---|
| Kedelapan komponen | 0,048801243938405243 | 0,0017688696910511045 |
| **Enam komponen pertama** | **0,04703237424735414** | **0** |

Selisihnya persis suku misalignment (0,04205793² = 0,0017689). Jadi **zero error
dan misalignment berada di luar penjumlahannya.**

Satu catatan kejujuran: zero error di sesi contoh bernilai NOL, jadi dari angka
saja tidak bisa dibedakan "sengaja dikecualikan" dari "kebetulan tidak
menyumbang". Yang benar-benar terbukti dikecualikan cuma misalignment.

**Yang dilakukan sistem:** kedelapan komponen tetap DIHITUNG; yang masuk agregasi
cuma enam. Dua yang di luar disimpan di jejak audit sesi
(`komponen_di_luar_jumlah`) berikut `ci`-nya, supaya kontribusi yang hilang
kebaca tanpa membuka kode. Dijaga
`ProvingRingMasterTest::test_budget_cocok_master_dan_hanya_enam_komponen`, yang
juga membuktikan bahwa menjumlahkan delapan MENAIKKAN `u_c` — jadi "enam" bukan
kebetulan yang kebetulan cocok.

**Yang perlu dijawab:** apakah kedua komponen itu memang sengaja tidak masuk
untuk Proving Ring — dan kalau ya, kenapa tetap dihitung dan ditampilkan di
lembar budget-nya. Kalau ternyata rumusnya terpotong, `u_c` naik dari
0,2168694867 ke 0,2209100358 — sekitar 1,9 % lebih besar, dan U95 yang tercetak
ikut naik sepadan.

---

## G9 ✅ TERJAWAB — cabang CMC "Tarik 10–88 kN" hilang di Load Cell

Panduan menduga ini cacat workbook. **Bukan.** Lampiran akreditasi LK-285-IDN
(`database/data/kemampuan-kalibrasi.json`) memang cuma memuat empat pita untuk
Load Cell:

```
Tekan 0-500 kgf   2,3 kgf
Tekan 10-88 kN    0,27 kN
Tekan 200-2000 kN 7,6 kN
Tarik 0-500 kgf   2,1 kgf
```

Tidak ada `Tarik 10–88 kN` — labnya memang tidak terakreditasi untuk rentang itu
pada Load Cell. Workbook benar; yang perlu dibangun justru pesan galat yang jelas
kalau ada sesi jatuh ke sana, dan itu sudah ada (`TabelStandarGaya::koreksi()`
memulangkan `null`, bukan koreksi nol).

Seluruh nilai CMC ketiga alat sudah diadu ke lampiran dan **cocok persis**,
termasuk konversi kgf→kN (2,1 kgf × 0,00981 = 0,020601).

---

## G10 🟠 Sesi Load Cell master: SEMBILAN dari sepuluh titik di luar tabel standar

Sesi `085-CAL-124` menguji alat 0–100 kN memakai standar `Load Cell 100 kN`.
Tabel kalibrasi standar itu mulai di **9,8 kN** dan berhenti di **88,3 kN**.

| Titik | Posisinya terhadap tabel |
|---|---|
| 0; 2; 3; 4; 5; 6; 7; 8; 9 kN | di BAWAH baris pertama |
| 100 kN | di ATAS baris terakhir |

Akibatnya sembilan titik memakai koreksi standar yang sama persis
(`W = −0,00196133 kN`, koreksi baris 9,8 kN), dan titik 100 kN memakai koreksi
baris 88,3 kN. Tidak ada satu pun titik yang benar-benar tertelusur ke baris
tabel yang mewakilinya.

Angkanya bisa dicek: titik 9 kN punya rata-rata 8,9125 kN, dan sertifikat
mencetak `8,91053867` — yaitu `8,9125 + (−0,00196133)`, cocok sampai digit
terakhir. Jadi ini memang perilaku master, bukan salah baca.

Master **diam** soal ini; tidak ada peringatan apa pun di lembarnya. Sistem
menandai tiap titiknya lewat `di_luar_rentang_tabel`, dan penandanya ikut ke
jejak audit sesi.

**Yang perlu dijawab:** apakah kalibrasi di bawah 9,8 kN memang sah dilakukan
dengan standar 100 kN, atau titik-titik itu seharusnya memakai standar
`Load Cell 5 kN`. Kalau sah, apa dasar menerima koreksi baris terdekat sebagai
koreksi yang berlaku di rentang yang tidak tercakup.

---

## G11 🔴 DIKOREKSI — Proving Ring dikalibrasi dengan standar 600× kapasitasnya

> **Versi pertama butir ini salah arah, dan dicatat di sini supaya tidak
> dibaca ulang sebagai fakta.** Semula ditulis "koreksi standar HILANG di semua
> titik, ditelan `ISERROR`", dengan kesimpulan bahwa sertifikat yang sudah
> terbit kehilangan koreksi standarnya. Sesudah pencariannya dijalankan
> sungguhan lawan tabelnya, kesimpulan itu **terlalu jauh**.

### Yang benar

`PERHITUNGAN FC` kolom `W` memang ditelan `ISERROR`:

```
=IF(ISERROR(IF($X$26="Load Cell 100 kN", VLOOKUP(V34,Standar_100kN_tekan,3,0), …)), "", …)
```

Tapi dengan standar yang disebut workbook — **Load Cell 3000 kN** — pencarian
yang BENAR pun memulangkan nol. Tabel 3000 kN barisnya `0, 300, 600, … 3000 kN`;
seluruh titik Proving Ring ada di 0–4,905 kN, jadi baris terdekatnya **selalu
baris nol**, yang koreksinya memang 0.

Jadi `ISERROR` **menyembunyikan** keadaan itu, bukan menyebabkannya. Angkanya
sama; yang hilang cuma pengetahuan bahwa angkanya nol karena tidak ada titik
tertelusur di sana. Dibuktikan
`ProvingRingMasterTest::test_koreksi_standar_3000kn_memang_nol_bukan_karena_error`.

### Masalah yang sebenarnya

Alat **500 kgf (4,905 kN)** dikalibrasi dengan standar yang titik terkalibrasi
terendahnya **300 kN** — 61× kapasitas alatnya, dan resolusi bacanya 0,1 kN
= 2 % dari skala penuh alat. Tidak ada koreksi tertelusur di rentang alat itu
sama sekali.

Sementara tabel **Load Cell 5 kN** membentang `0 … 4,903325 kN` — pas menutupi
kapasitasnya, dengan sembilan titik yang koreksinya nyata (0 sampai 0,00363 kN).

Bersama G4 (identitas standar di workbook ini `#REF!`), keduanya menunjuk ke satu
sebab: lembar Proving Ring disalin dari lembar alat lain dan field identitasnya
tidak pernah diperbarui.

### Yang dilakukan sistem

Pencariannya dijalankan sungguhan, dan titik yang baris tabelnya **meleset lebih
dari 10 %** dari bebannya diangkat jadi temuan — penjaga baru, karena
`di_luar_rentang` tidak menangkapnya: 0,29 kN memang ada di dalam [0, 3000].
Standarnya dibaca dari blok sesi, jadi begitu lab memutuskan, yang berubah cuma
isian teknisi — bukan kode.

**Yang perlu dijawab:** standar mana yang seharusnya dipakai Proving Ring? Kalau
`Load Cell 5 kN`, koreksi standarnya berhenti nol dan angka sertifikatnya
bergeser — termasuk sertifikat yang sudah terbit.

---

## G12 🟠 Kolom `Standard Value` tidak sepakat — antar workbook DAN di dalam satu workbook

G5 menanyakan `Correction` memakai Y sementara sertifikat mencetak Z. Pembacaan
ketiga `SERTIFIKAT` berdampingan menunjukkan masalahnya lebih dalam dari itu.

**Antar workbook:**

| Workbook | Kolom yang dicetak di `Standard Value` |
|---|---|
| UTM | `Z` (sesudah koreksi termal), keenam cabang satuan |
| Load Cell | `Y` — **tapi cuma di cabang `kN`** |
| Proving Ring | `Z`, **kecuali baris data pertama** yang memakai `Y` |

**Di dalam workbook Load Cell**, rumusnya:

```
=IF('PERHITUNGAN FC'!Z30="","",                       <- penjaga menguji Z
   IF(G15="kN",  'PERHITUNGAN FC'!Y30/DATABASE!$S$20,  <- Y
   IF(G15="N",   'PERHITUNGAN FC'!Z30/DATABASE!$S$21,  <- Z
   IF(G15="lbf", 'PERHITUNGAN FC'!Z30/…              <- Z
```

Satu cabang disunting, lima tertinggal, penjaganya tidak ikut. Itu bentuk
suntingan yang berhenti di tengah, bukan keputusan metode — ditiru apa adanya,
angka yang TERCETAK berubah arti tergantung satuan tampilan yang dipilih
teknisi.

**Yang dilakukan sistem:**

- UTM mencetak `Z`, Load Cell mencetak `Y` — direplikasi, karena itu yang ada di
  sertifikat pelanggan yang sudah terbit.
- **Penyimpangan sadar:** Load Cell memakai `Y` untuk SEMUA satuan, bukan cuma
  kN. Sesi bersatuan kN — satu-satunya yang pernah dijalankan lab untuk alat
  ini, termasuk sesi masternya sendiri — identik dengan master. Satuan lain
  berbeda sebesar koreksi termal. Ditulis di jejak audit sesi
  (`penyimpangan_master.standard_value_hanya_cabang_kn`), bukan cuma di komentar
  kode.
- Baris pertama Proving Ring numerik tidak berbeda (titik nol, `Y = Z = 0`), jadi
  tidak ada percabangan untuknya.

**Yang perlu dijawab:** satu kolom untuk ketiga alat — `Y` atau `Z`? Selama
belum dijawab, dua alat mencetak kolom yang berbeda untuk besaran yang sama.

---

## G13 🟡 Panduan menyuruh blokir urutan titik yang tidak naik — tapi master sendiri melanggarnya

Panduan §8.1 mendaftarkan "Nominal harus naik monoton" sebagai **pemblokir
submit**, lengkap dengan pesannya: *"Titik ke-4 (300) lebih kecil dari titik
ke-3 (400)"*.

Sesi master Load Cell `085-CAL-124` urutan titiknya:

```
0 → 100 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 kN
```

Titik kedua langsung kapasitas penuh, baru turun ke rentang bawah — dan
sertifikatnya mencetak dalam urutan itu juga. Jadi aturan panduan, kalau
ditegakkan sebagai pemblokir, membuat lembar yang benar-benar dipakai lab
tidak bisa dikirim.

**Yang dilakukan sistem:** urutan turun **tidak memblokir**, tapi tetap muncul
sebagai peringatan di jejak sesi. Antara panduan dan master, yang menang master
(AGENTS.md §Aturan yang Lahir dari Kesalahan Nyata). Dijaga
`GayaValidasiSesiTest::test_urutan_titik_tidak_naik_cuma_peringatan`.

**Yang perlu dijawab:** apakah urutan `0 → 100 → 2 → 3 …` itu memang metode
yang disengaja (membebani penuh dulu untuk melihat histeresis, lalu turun), atau
kebiasaan pengisian yang boleh diseragamkan. Kalau yang kedua, aturannya bisa
dinaikkan jadi pemblokir — tapi sesi lama harus diperiksa dulu.

---

## G14 🔴 Pita CMC Proving Ring terbaca dari baris yang SALAH — sekitar 10×

Lampiran akreditasi LK-285-IDN memuat dua pita untuk Proving Ring:

```
0-500 kgf    2,3 kgf      <- yang mencakup alat 500 kgf
10-88 kN     0,22 kN
```

Budget master memakai **`CMC PT. SIDIK = 0,22 kN`** — itu baris kedua, yang
**tidak mencakup** alat berkapasitas 500 kgf. Yang benar 2,3 kgf = **0,022563
kN**, hampir sepuluh kali lebih kecil.

Akibatnya di angka yang tercetak:

| | Master | Sistem ini |
|---|---|---|
| U95 hitung (sebelum lantai CMC) | 0,021099 kN | 0,021099 kN |
| Lantai CMC yang dipakai | 0,22 kN | 0,022563 kN |
| **U95 yang terbit** | **0,22 kN** | **0,022563 kN** |

Lantai CMC repo ini memilih pita berdasarkan **kapasitas alat**, jadi dia
memakai yang benar tanpa perlu disetel — dan itu berarti U95 kita ~10× lebih
kecil dari yang tercetak di sertifikat yang sudah terbit.

Ini kelas kesalahan yang AGENTS.md sebut eksplisit: *"Jangan percaya bacaan
tabel yang terpotong — tabel lampiran akreditasi barisnya menyambung dengan
kolom kosong."* Dan bersama G11 di atas, keduanya menunjuk sebab yang sama:
identitas standar & pita di lembar ini disalin dari alat lain.

**Yang perlu dijawab:** pita mana yang berlaku untuk Proving Ring 500 kgf, dan
apa yang dilakukan terhadap sertifikat yang sudah terbit dengan U95 sepuluh kali
lebih besar. Mengaku ketidakpastian yang LEBIH KECIL dari yang pernah
diterbitkan bukan keputusan yang boleh diambil di kode.

---

## Yang sudah diputuskan sendiri, tidak perlu ditanyakan

- **`v_eff` dipotong ke bawah** sebelum mencari `t`. Bukan pilihan: GUM G.4.1
  memintanya, dan lembar manual lab sendiri mengikutinya. Dengan `v_eff` utuh,
  `k` meleset di desimal kelima dari yang dicatat master.
- **Nearest-match, bukan interpolasi**, dan yang seri diambil yang lebih dulu di
  tabel — meniru `MATCH` Excel.
- **Titik nol tidak punya RSD/RRPE**, dan itu dibedakan dari "alat baca nol
  padahal dibebani" yang justru jadi temuan.
