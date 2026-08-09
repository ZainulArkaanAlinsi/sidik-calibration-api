# Temuan: sel pengulangan U95 titik 1,83 mg/L di master Chlorine

**Buat:** tim lab PT Sidik (pemilik workbook `Master Olah Data_Chlorine Meter`)
**Dari:** tim aplikasi kalibrasi
**Tanggal:** 6 Agustus 2026
**Status:** nunggu keputusan lab — **belum** ada yang diubah di file lab

---

## Ringkas

Di sheet `PERHITUNGAN U95%` titik **1,83 mg/L**, baris *Ketidakpastian Baku
Pengulangan Pembacaan* nulis `ui = 0,0244949` padahal kolom `U` di baris yang
sama isinya **0** dan STDEV pembacaannya juga **0**. Kalau STDEV nol, Type A
mestinya nol juga.

Akibatnya U95 titik itu jadi **0,0801585 mg/L** — **di atas CMC 0,08 mg/L** yang
jadi lingkup akreditasi lab sendiri (LK-285-IDN no. 42).

Aplikasi menghitung dari pembacaan apa adanya, jadi keluarnya **0,0596 mg/L**,
lalu dilaporkan **0,08** karena kena batas CMC. Di kertas, dua-duanya kecetak
`0,08` — jadi **sertifikat yang sudah terbit tidak berubah**. Yang perlu
dibereskan itu selnya, bukan sertifikatnya.

## Angkanya

Sumber: `Chlorine_Meter_CSV/PERHITUNGAN.csv` & `PERHITUNGAN_U95%.csv`, sesi
`0189-CAL-624` (Hanna HI97711, Juni 2024).

| | Titik 1,74 mg/L | Titik 1,83 mg/L |
|---|---|---|
| Pembacaan (5×) | 1,76 · 1,76 · 1,76 · 1,76 · 1,75 | 1,86 semua |
| STDEV di sheet | 0,004472 | **0** |
| Kolom `U` baris pengulangan | 0,004472 | **0** |
| Kolom `ui` baris pengulangan | 0,002 | **0,0244949** ← nggak nyambung |
| U95 sheet | 0,089 → dilaporkan 0,091 (CMC) | **0,0801585** |
| U95 aplikasi | 0,089 → 0,091 (CMC) | 0,0596 → 0,08 (CMC) |

Tiga hal yang bikin kami yakin selnya yang ketinggalan, bukan hitungan
aplikasinya:

1. STDEV titik 1,83 di `PERHITUNGAN.csv` baris 31 memang **0** — lima
   pembacaannya identik.
2. Kolom `U` di baris budget itu sendiri juga **0**, jadi `ui` mestinya
   `0 / √5 = 0`.
3. `0,0244949² = 0,0006` persis nutup selisih antara jumlah kuadrat versi kami
   (0,00090842245) dengan sel "Jumlah" di sheet (0,0015084224467774394).

Angka `0,0244949` itu sendiri = `0,06 / √6`, kelihatannya kebawa dari sel lain.

## Kenapa ini perlu diurus walau sertifikatnya sama

Selama CMC 0,08 masih berlaku, batas itu yang menutupi selisihnya. Begitu CMC
titik 1,83 berubah — mis. sesudah re-asesmen atau ganti larutan standar —
selisih 0,0596 vs 0,0802 langsung kelihatan di kolom U95% sertifikat, dan dua
sumber (workbook lab vs aplikasi) bakal ngasih angka beda buat sesi yang sama.

Sertifikat `0189-CAL-624` yang sudah dipegang pelanggan **tidak perlu ditarik
atau direvisi**: yang tercetak `0,08`, dan itu tetap benar.

## Yang kami butuhkan dari lab

1. Konfirmasi apakah sel `ui` titik 1,83 memang salah isi. Kalau iya, mohon
   dibetulkan di workbook master.
2. Kalau ternyata **bukan** salah — misalnya ada komponen pengulangan yang
   sengaja dipatok dari data historis, bukan dari lima pembacaan sesi itu —
   mohon dijelaskan dasarnya, supaya aplikasinya yang kami samakan.

Selama belum ada jawaban, aplikasi tetap menghitung dari pembacaan apa adanya.
Pilihan itu sengaja: menyamakan ke sheet tanpa dasar bakal bikin ketidakpastian
yang dilaporkan **di luar lingkup akreditasi** lab sendiri.

## Di mana ini terkunci di kode

- `database/seeders/ChlorineSeeder.php` — docblock `TITIK`, penjelasan lengkap.
- `tests/Unit/ChlorineBudgetTest.php` — budget titik 1,74 dicocokin sampai digit
  terakhir; titik 1,83 sengaja beda.
- `tests/Feature/SertifikatCocokMasterTest.php` — mastiin yang KECETAK tetap
  `0,08`, jadi kalau batas CMC-nya berubah, tesnya yang teriak duluan.
