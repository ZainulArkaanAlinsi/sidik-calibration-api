# Pertanyaan ke Lab — Viscometer

Status: menunggu jawaban · Dibuat 18 Agustus 2026 · Diperbarui 19 Agustus 2026 · Sumber:
`Project-PT-Sidik/Master_Olah_Data_Viscometer_CSV/`

Backend Viscometer sudah jalan penuh dan angkanya sudah diadu ke workbook
master. Enam hal di bawah **tidak** bisa diputuskan dari berkas yang ada, dan
semuanya sudah diberi keputusan sementara supaya pekerjaan tidak berhenti.
Setiap butir menyebut apa yang dipakai sekarang dan apa yang berubah kalau lab
menjawab lain.

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

## 3. Blok larutan standar 30000 cP

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

## 5. Berapa desimal yang dicetak sertifikat Viscometer

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

## Tambahan: kertas SIDIK-FM-CAL-0524_Rev.3 ketinggalan

Bukan pertanyaan, tapi perlu diketahui sebelum formulir dicetak ulang:

| Yang tercetak di Rev.3 | Yang berlaku menurut master |
|---|---|
| "Larutan Std Visco 100 cP" **dua kali**, lalu 30000 & 60000 cP | 100 / 1000 / 60000 cP |
| Daftar spindle global untuk dilingkari, satu kali | Spindle **berbeda per titik** (sesi master: HA1, HA2, HA7) |
| Tidak ada kotak RPM | RPM berbeda per titik (63, 62, 62) dan masuk rumus MPE |

Lembar siap pindai yang dihasilkan `php artisan ocr:cetak-lembar viscometer`
sudah menambahkan kotak Spindle, RPM, dan Resolusi UUT per titik. Cetakan resmi
Rev.3 tidak diubah dari sini — kalau formulirnya mau direvisi, tiga baris di
atas yang perlu masuk.
