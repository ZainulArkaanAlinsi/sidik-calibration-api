# Pertanyaan ke Lab — Viscometer

Status: sebagian TERJAWAB oleh `5. Viscometer 86068360 terbaru .xlsm` (20 Agu
2026) · Dibuat 18 Agustus 2026 · Diperbarui 20 Agustus 2026 · Sumber lama:
`Project-PT-Sidik/Master_Olah_Data_Viscometer_CSV/`

**20 Agustus 2026 — berkas `.xlsm` yang diminta sudah dikirim** (sesi
0817-CAL-726, PT Lamurindo, Brookfield DV Plus S/N 86068360). Butir 3 dan 5
terjawab; butir 1, 2, 4, 6 tetap sebagaimana adanya (sesi lama tidak ada di
berkas baru). EMPAT pertanyaan BARU muncul dari berkas itu — nomor 7-10 di
bawah. Nomor 10 yang paling mendesak: satu kolom di berkas baru berselisih
dengan berkas lama untuk botol yang serialnya sama, dan selisih itu membuat
sertifikat yang sudah terbit tidak bisa dihitung ulang.

---

## 1. Pembacaan ke-5 titik 60000 cP isinya rusak

Sel pengulangan ke-5 titik 60000 cP berisi teks `631.74.2` — dua titik desimal.
Muncul di `INPUT DATA` maupun `PERHITUNGAN`, tahap *before* maupun *after*.

Excel melewatkannya waktu `AVERAGE`/`STDEV`: rata-rata masternya 63151,85 yang
memang rata-rata **empat** angka. Tapi di sheet `PERHITUNGAN U95%` pembaginya
tetap dipatok `√5` dengan `vi = 4`. Dua hal itu tidak bisa benar sekaligus.

**Yang dipakai sekarang:** empat pembacaan, `n = 4`, sel rusak dibuang. `U95`
titik itu jadi 145,72 cP (master mencetak 142,34 cP).

Selisih 3,4 cP itu lahir dari **dua** hal yang berdiri sendiri, dan dua-duanya
sudah ditelusuri sampai angkanya:

1. **Pembagi Type A.** `STDEV`-nya sama persis di dua tempat —
   48,19436343252727 cP, dihitung dari empat pembacaan yang terpakai. Tapi
   master membaginya `√5` sementara di sini `√4`. Sel pembagi dan `vi` di
   workbook masih dipatok untuk lima pembacaan padahal `AVERAGE`/`STDEV`-nya
   sendiri sudah melewatkan sel rusak itu.
2. **Faktor cakupan**, lihat butir 2 di bawah.

**Yang ditanyakan:** angka sebenarnya berapa? Kalau memang `63174,2`, kirimkan —
satu angka itu langsung memperbaiki `n`, `STDEV`, dan `U95` sekaligus.

> Sel ini juga dipakai sebagai kasus uji: lembar pindai foto **menolak**
> `631.74.2` dan menandainya merah, tidak menebaknya jadi `631,74` atau
> `63174,2`. Menebak berarti memasukkan angka karangan ke dokumen
> terakreditasi.

## 2. Faktor cakupan titik 100 cP: master menulis 2, t-student memberi 2,5706

Derajat kebebasan efektif titik pertama 5,376. Tabel t-student 95 % dua sisi
memberi `t(0,975 ; 5) = 2,5706`, sementara sel `k` di master berisi 2.

Master pH lab sendiri memakai t-student (`k = 2,77645` untuk `veff = 4,92`),
jadi yang menyimpang sel viscometer ini, bukan mesin hitungnya. Workbook
viscometer juga lembar percobaan: `Certificate Number` dan `Order Number`-nya
kosong, jadi tidak ada sertifikat terbit yang perlu direproduksi.

**Yang dipakai sekarang (berubah 19 Agustus 2026):** `k = 2`, dikunci khusus
Viscometer lewat `ViscometerProfile::faktorCakupanTetap()`. Sebelumnya t-student,
dan itu membuat `U95` titik 100 cP tercetak 0,6336 cP — 28,5 % di atas lembar
lab.

Alasan dibalik: kalimat sertifikat masternya sendiri berbunyi
`Coverage Factor ( k ) = 2`, dan keempat baris CMC Viscometer di
`calibration_capabilities` juga `faktor_cakupan = 2`. Sertifikat yang mencetak
k=2 tapi menghitung dengan 2,5706 membantah dirinya sendiri.

Sesudah dikunci, titik 100 cP dan 1000 cP **cocok persis** dengan workbook
master di keempat kolom. Lima alat lain tidak tersentuh — workbook pH tetap
memakai t-student.

**Yang ditanyakan:** apakah `k = 2` di sel itu memang disengaja untuk seluruh
Viscometer? Kalau ya, sel `k` titik 60000 cP yang berisi 1,9754 justru yang
perlu diseragamkan — workbook sekarang memakai dua aturan sekaligus.

## 3. Blok larutan standar 30000 cP — **TERJAWAB 20 Agu 2026**

**Jawaban dari berkas baru: larutan 30000 cP tidak pernah ada — yang benar
3000 cP** (Paragon Scientific/N1400, S/N 2241502068), plus SATU larutan lagi
yang belum pernah muncul: **100000 cP** (Paragon/RT100000, S/N 1251704078).
Blok yang dulu `#DIV/0!` sekarang hidup penuh dengan tabel sertifikat suhunya
sendiri. Kelima larutan sudah masuk `ViscometerSeeder` & `ViscometerProfile`;
kertas Rev.3 yang menulis "30000" ketinggalan dua revisi standar.

Konteks lamanya (untuk jejak):

Seluruh baris budgetnya di `PERHITUNGAN U95%` berisi `#DIV/0!`. Sumber angkanya
sudah hilang dari workbook itu sendiri.

**Yang dipakai sekarang:** blok 30000 cP tidak dibangun sama sekali — tidak ada
barisnya di lembar kerja, tidak ada kotaknya di lembar cetak, tidak pernah masuk
hitungan. Perlakuan yang sama persis dengan blok SRE di Spectrophotometer.

**Yang ditanyakan:** larutan 30000 cP masih dipakai atau sudah pensiun? Kertas
Rev.3 masih mencetak namanya, tapi `FORM VALIDASI` revisi 18 (18 Mei 2026)
menyebut eksplisit *"Update Standard Viscometer 1000 cP"*, dan `DATABASE`
maupun lampiran KAN cuma menyebut 100 / 1000 / 60000 cP.

## 4. Titik 61898 cP di luar batas ruang lingkup 58021 cP

Lampiran KAN LK-285-IDN no. 44 menyatakan CMC 1,4 P (= 140 cP) **sampai
58021 cP**. Sesi master mengukur titik ketiga di 61898,12 cP — di atas batas
itu. Nilai acuannya memang bergeser mengikuti suhu larutan: 59003 cP pada
25 °C, tapi 95192 cP pada 20 °C.

**Yang dipakai sekarang:** lantai CMC berlaku sampai 58021 cP saja. Titik 61898
cP dihitung dengan budget lengkap (empat komponen, termasuk pengaruh suhu) tapi
**tanpa** lantai CMC — `U95`-nya dilaporkan apa adanya, 145,72 cP. Lab tidak
mengklaim kemampuan di luar yang diakreditasi.

**Yang ditanyakan:** apakah ruang lingkup akan diperluas sampai 95192 cP
(jangkauan tabel sertifikat larutan pada 20 °C)? Kalau ya, satu angka di
`ViscometerCapabilitySeeder` yang diubah. Kalau tidak, sertifikat viscometer
yang diukur di bawah 25 °C akan selalu punya satu titik tanpa klaim CMC — dan
itu perlu diketahui sebelum dicetak, bukan sesudah.

## 5. Berapa desimal yang dicetak sertifikat Viscometer — **TERJAWAB 20 Agu 2026**

**Jawaban dari berkas baru: BUKAN angka seragam.** Format sel `SERTIFIKAT`
C23:R27 berbunyi `0.00` · `0.0` · `0` · `0.0` · `0.0` — dua desimal di baris
100 cP, satu di 1000/60000/100000, nol di 3000. Diimplementasikan per baris di
`ViscometerProfile::desimalSertifikatTitik()`; konstanta dua-desimal lama
tinggal jadi cadangan buat titik tak dikenal.

Konteks lamanya (untuk jejak):

Di enam alat lain jumlah desimal dibaca dari **format sel** workbook masternya.
Berkas `.xlsm` Viscometer tidak ada di folder — yang ada cuma export CSV, dan
CSV tidak menyimpan format sel.

**Yang dipakai sekarang:** dua desimal, ikut aturan umum untuk alat beresolusi
0,1 cP → `93,88` / `910,29` / `61898,12` cP. Yang dibulatkan hanya bentuk
cetaknya; seluruh rantai hitung tetap presisi penuh.

**Yang diminta:** berkas `.xlsm`-nya, atau satu sertifikat viscometer yang sudah
tercetak. Yang diganti nanti cukup satu konstanta
(`ViscometerProfile::DESIMAL_SERTIFIKAT`); rumusnya tidak tersentuh.

## 6. Workbook lab mengeluarkan 37,87 cP di titik 100 cP

19 Agustus 2026 lab menjalankan ulang workbook-nya dan kolom `U95%` titik
100 cP keluar **37,87 cP**. Baris 1000 cP dan 60000 cP di lembar yang sama
tetap cocok (2,7 dan 142).

**Ini bukan selisih pendapat — 37,87 tidak bisa lahir dari budget itu.** Tiap
sel sudah disapu; supaya hasilnya 37,87, salah satu ini harus benar:

| Sel | Harus bernilai | Nilai di master |
|---|---|---|
| U95% Standar | 37,867 | 0,169405 |
| Resolusi | 65,587 cP | 0,1 |
| UTemperature | 11,47 °C | 0,361 |
| STDEV pembacaan | 42,34 cP | 0,512 |

Tidak satu pun bisa datang dari sesi ini — STDEV 42 cP pada rata-rata 96,72 cP
berarti pembacaannya meloncat dari 40 ke 150, sementara lembar kerjanya
95,9–97,3 cP. Angka `37,87` juga tidak muncul di satu pun dari sembilan berkas
master.

Pemeriksa yang paling mudah dilihat: **MPE titik ini 4,1418 cP**. `U95` 37,87
berarti ketidakpastiannya sembilan kali lebih lebar dari batas keberterimaannya
sendiri — titik itu tidak akan pernah bisa lulus, dan sertifikat seperti itu
tidak pernah terbit. `SERTIFIKAT.csv` untuk sesi yang sama menulis
0,49299153941105106.

**Yang dipakai sekarang:** 0,49299154 cP, dan budgetnya sudah dikunci komponen
per komponen di `ViscometerBudgetTest::test_budget_titik_100_cocok_komponen_per_komponen`
— keempat komponen, `uc`, `v_eff`, `k`, plus penjaga `U95 < MPE`.

**Yang diminta:** buka sheet `PERHITUNGAN U95%` blok *Point 100cP*, baris
`Ketidakpastian Baku Gabungan, Uc`. Nilainya seharusnya **0,24649577**. Kalau di
sana terbaca ~18,93, salah satu dari empat baris komponen di atasnya yang rusak
— kirimkan blok itu dan selnya bisa ditunjuk persis.

---

## 7. Titik 3000 cP: dua sel interpolasi ditimpa angka ketikan (BARU, 20 Agu 2026)

Sel `PERHITUNGAN!T33` & `T34` (nilai larutan 3000 cP pada 25 & 37,78 °C untuk
interpolasi) berisi **angka mati 3437 & 1398** yang menimpa formulanya. Baris
pertama blok yang sama (`T32`) masih formula, dan keempat blok larutan lain
seluruhnya formula ke `Tabel Pengaruh Temperature` — yang untuk larutan ini
berbunyi **3987** @25 °C dan **1613** @37,78 °C. Sheet `INPUT DATA` (`K34`)
dan seluruh budget U95 titik itu membaca tabel (0,25 % × 3987 = 9,9675), jadi
workbook-nya sendiri memakai dua nilai berbeda untuk satu larutan.

**Yang dipakai sekarang: tabel sertifikatnya (3987/1613).** Akibatnya nilai
acuan titik itu 2891,02 cP pada suhu sesi 30,9 °C — bukan 2495,68 seperti sel
master — dan koreksinya **berbalik tanda** (+181,22 di sini, −214,12 di
master). Sertifikat yang memakai dua nilai untuk satu larutan tidak bisa
dipertahankan di depan asesor, jadi tabel yang menang.

**Yang ditanyakan:** dari mana 3437 & 1398? Kalau itu sertifikat lot lain yang
lebih baru, kirimkan sertifikatnya — tabel di `Tabel Pengaruh Temperature`
baris 88-98 yang akan diganti, bukan kodenya.

## 8. Resolusi alat: `INPUT DATA` menulis 1, empat blok budget memakai 0,1 (BARU, 20 Agu 2026)

`INPUT DATA!E16` (Resolusi Alat) = **1** dan blok U95 titik 1000 cP membacanya
(`K31 = E16`). Tapi empat blok lain mematok **0,1** (`K5`, lalu berantai
`K50 = K5` dst), dan pembacaan di lembar itu sendiri — 79,7 / 779,5 — hanya
mungkin dari alat berdaya baca 0,1 cP.

**Yang dipakai sekarang: 0,1 untuk semua titik** (empat lawan satu, plus bukti
pembacaan). Akibatnya `uc` titik 1000 cP keluar 1,1867 di sini vs 1,2209 di
master — satu-satunya titik yang bergeser karena ini.

**Yang ditanyakan:** apakah DV Plus ini daya bacanya berubah menurut rentang
(0,1 cP di bawah ~1000 cP, 1 cP di atasnya)? Kalau ya, yang perlu diisi
resolusi PER TITIK — kotaknya sudah ada di lembar kerja cetak kita, tinggal
dipakai; kalau tidak, sel `K31` di master yang perlu dibetulkan ke 0,1.

## 9. Faktor cakupan: dua aturan dalam satu workbook, lagi (BARU, 20 Agu 2026)

Butir 2 dulu menanyakan sel `k` 100 cP yang berisi 2. Berkas baru menjawab
sebagian — dan menambah bentuk barunya: blok 100 & 1000 cP tetap `k = 2`
(angka mati), sementara tiga blok baru (3000/60000/100000) memakai pendekatan
`k = (2,35746 × 1,099 + veff × 1,9599999) / veff` ≈ 1,972-1,973. Sertifikatnya
sendiri mencetak `Coverage Factor ( k ) = 2` (membaca sel blok 100 cP) DAN
menjudulkan kolomnya `U95%, k=2`.

**Yang dipakai sekarang: k = 2 untuk semua titik** — dokumen yang terbit
menulis 2 dua kali. Akibatnya `U95` titik 3000 cP keluar 10,088 cP di sini vs
9,949 cP di sel master (~1,4 % lebih besar; arah yang aman).

**Yang ditanyakan:** satu aturan yang mana yang dimaksud berlaku? Kalau
pendekatan t-student yang benar, kalimat sertifikat & judul kolomnya ikut
diubah — dan `ViscometerProfile::faktorCakupanTetap()` tinggal dikembalikan ke
null.

## 10. Kolom ketidakpastian larutan 100 cP berselisih antar berkas, botolnya sama (BARU, 20 Agu 2026)

Kolom `Uncertainty %` untuk larutan 100 cP di sheet `Tabel Pengaruh
Temperature` berbeda antara dua berkas master:

| Suhu (°C) | 20 | 25 | 37,78 | 40 | 50 | 60 | 80 | 98,89 | 100 |
|---|---|---|---|---|---|---|---|---|---|
| Berkas lama | 0,17 | 0,17 | 0,15 | 0,15 | 0,13 | 0,13 | 0,13 | 0,08 | 0,08 |
| Berkas baru | 0,13 | 0,13 | 0,15 | 0,15 | 0,15 | 0,15 | 0,17 | 0,17 | 0,17 |

Yang membuat ini bukan sekadar "berkas baru menang":

1. **Botolnya sama.** `DATABASE!T13` di KEDUA berkas menulis S/N
   **1241202088**. Ini bukan lot yang diganti.
2. **Nilai viskositas & densitasnya identik** di kedua berkas (134 / 99,65 /
   51,1 … dan 0,8585 / 0,8554 / …). Sertifikat larutan yang direvisi tidak
   mengubah satu kolom dan membiarkan dua kolom lain sama persis.
3. **Arahnya melawan keempat larutan lain di berkas baru itu sendiri.**
   1000 cP (0,23 → 0,13), 3000 cP (0,25 → 0,17), dan 60000 cP (0,23 → 0,17)
   semuanya menurun dengan suhu. Larutan 100 cP satu-satunya yang menaik.
4. **Header tabelnya justru mundur.** Sheet `Tabel Pengaruh Temperature` di
   berkas baru menulis S/N **1220905085** — lebih tua dari yang ada di
   `DATABASE`-nya sendiri maupun di berkas lama.
5. **Sertifikat yang sudah terbit hanya cocok dengan 0,17 %.**
   `CAL/2026/08/0047` (sesi `KAL/2026/08/0052`, 19 Agu 2026) mencetak U95 titik
   100 cP = **0,49299154 cP**. Dengan 0,13 % hasilnya 0,48075411 — dokumen yang
   sudah dipegang pelanggan tidak bisa dihitung ulang.

**Yang dipakai sekarang: 0,17 %,** ikut berkas lama. Sempat diikuti 0,13 %
(20 Agu 2026) dengan anggapan lab mengganti lot; anggapan itu keliru karena
serialnya sama, dan sudah dikembalikan.

Akibatnya `uc` titik 100 cP di sesi 0817-CAL-726 keluar 0,0947 di sini
sementara sel `PERHITUNGAN U95%!AC21` berbunyi 0,0773. **Angka yang
DILAPORKAN tidak bergeser sama sekali** — lantai CMC 0,2 cP menang di titik itu
pada kedua angka, jadi sel `AC26` tetap direproduksi.

**Yang ditanyakan:** kirimkan sertifikat larutan 100 cP S/N 1241202088 yang
berlaku. Kalau kolom di berkas baru yang benar, satu hal yang perlu diketahui
lebih dulu: sertifikat `CAL/2026/08/0047` yang sudah terbit dihitung dengan
0,17 %, jadi perlu diputuskan apakah dokumen itu diterbitkan ulang atau
dibiarkan sebagaimana adanya.

## Tambahan: kertas SIDIK-FM-CAL-0524_Rev.3 ketinggalan

Bukan pertanyaan, tapi perlu diketahui sebelum formulir dicetak ulang:

| Yang tercetak di Rev.3 | Yang berlaku menurut master |
|---|---|
| "Larutan Std Visco 100 cP" **dua kali**, lalu 30000 & 60000 cP | 100 / 1000 / **3000** / 60000 / **100000** cP (20 Agu 2026) |
| Daftar spindle global untuk dilingkari, satu kali | Spindle **berbeda per titik** (sesi master: HA1, HA2, HA7) |
| Tidak ada kotak RPM | RPM berbeda per titik (63, 62, 62) dan masuk rumus MPE |

Lembar siap pindai yang dihasilkan `php artisan ocr:cetak-lembar viscometer`
sudah menambahkan kotak Spindle, RPM, dan Resolusi UUT per titik. Cetakan resmi
Rev.3 tidak diubah dari sini — kalau formulirnya mau direvisi, tiga baris di
atas yang perlu masuk.
