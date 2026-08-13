# Pertanyaan ke lab — kolom R² blok %T (Spectrophotometer)

Satu kolom di sertifikat Spectrophotometer belum bisa dihasilkan sistem, dan
sebabnya bukan teknis. Dokumen ini menuliskan apa yang sudah diperiksa supaya
yang perlu dijawab lab tinggal satu hal.

## Yang ada di master

`SERTIFIKAT.csv` baris 47–48, blok `Accuracy %T and Linierity at λ = 560nm`:

```
… Correction (%T) , , , R2
…                 , , , 0.9359
```

Nilainya **0,9359**. Tapi di sertifikat cetak yang beredar, kolom itu tampil
**`1`** — konsisten dengan sel yang diformat 0 desimal (`ROUND(0,9359; 0)` = 1).

Jadi ada dua angka untuk satu kolom, dan keduanya tidak sama:

| | Nilai |
|---|---|
| Tersimpan di sel | 0,9359 |
| Tercetak di sertifikat | 1 |

## Kenapa sistem belum mencetaknya

Rumusnya tidak bisa ditelusuri. Workbook aslinya terenkripsi, jadi isi selnya
tidak bisa dibaca — yang ada cuma nilainya.

Kami menghitung ulang semua kemungkinan yang masuk akal dari data blok itu
sendiri (5 titik standar & pembacaannya):

| Kandidat | Hasil |
|---|---|
| RSQ(%T standar, %T UUT) — 5 titik | 0,999922 |
| RSQ(%T standar, %T UUT) — tanpa titik 0 | 0,999946 |
| RSQ(absorbansi standar, absorbansi UUT) | 0,999939 |
| RSQ(%T standar, koreksi) | 0,048865 |

Lalu disisir menyeluruh: 7 transformasi (T, absorbansi, 1/T, √T, T², log T,
−ln T) × 7 transformasi × semua subset titik ≥ 3. Yang paling dekat ke 0,9359:

- `R² = 0,934540` — x = %T², y = %T, titik [0; 20; 30,1]
- `R² = 0,937741` — x = %T², y = √%T, titik [9,9; 20; 30,1; 100]

Dua-duanya tidak punya arti fisika untuk linieritas fotometrik: %T dikuadratkan
bukan besaran yang diukur alat ini, dan subset titiknya ganjil (membuang titik
tengah, atau membuang dua ujung).

Kesimpulan sementara: **0,9359 kemungkinan besar bukan hasil hitung dari data
blok ini** — bisa nilai sisa dari template lama, atau diketik manual, atau
diambil dari sheet lain yang tidak ikut diekspor ke CSV.

## Yang perlu diputuskan lab

1. **Angka 0,9359 itu dari mana?** Sel/data mana yang menghasilkannya, dan
   apakah dia memang dihitung atau diketik?
2. **Kolom R² maunya menampilkan apa?** `1` (seperti sertifikat yang beredar),
   `0,94`, atau angka lain?
3. Kalau R² memang harus dihitung: **dari besaran apa** — %T, absorbansi, atau
   yang lain — dan **titik mana** yang ikut?

## Kenapa tidak diisi dulu saja

R² adalah pernyataan seberapa linier respons alat pelanggan. Mengisinya dengan
angka yang kami hitung sendiri (0,99992) berarti sertifikat terakreditasi
memuat klaim yang **berbeda dari dokumen lab yang sudah beredar**, dan tidak
ada yang bisa menjelaskan bedanya waktu diaudit.

Selama pertanyaan di atas belum terjawab, kolom R² tidak dicetak sama sekali.
Kolom yang tidak ada lebih jujur daripada kolom berisi angka yang tidak bisa
dipertanggungjawabkan.

Sisa sertifikatnya tidak terpengaruh: 24 titik lain (Standard/UUT/Correction)
dan ketiga U95 sudah cocok dengan master sampai batas presisi penyimpanan.

## Yang sudah terpasang di sistem

Kolomnya sudah dibangun penuh — PDF, Excel, dan halaman verifikasi QR — lalu
**dimatikan**. Yang menunggu jawaban lab cuma keputusannya, bukan pekerjaannya.

Sakelarnya satu nilai config:

```php
// config/kalibrasi.php
'r2_spektro' => env('SPEKTRO_R2', 'off'),
```

| Nilai | Perilaku |
|---|---|
| `off` (bawaan) | Kolom R² tidak ada sama sekali di PDF maupun Excel |
| `rsq_standar_uut` | Kolom R² = `RSQ(Standard %T; UUT %T)` atas seluruh titik blok %T |

Nilai lain (termasuk salah ketik di `.env`) diperlakukan sebagai `off`, supaya
isi sertifikat terakreditasi tidak bisa berubah karena typo yang tidak direview.

Kalau nanti jawaban lab ternyata rumus yang berbeda, yang diganti cuma isi
`SpectrophotometerProfile::koefisienDeterminasi()` — sisanya (pembekuan ke
snapshot, tata letak kolom, pembulatan 4 desimal) sudah terpasang dan bertes.

Catatan yang ikut menentukan bentuk kolomnya:

- **Cuma blok %T.** Dua blok panjang gelombang (Holmium & Didynium) tidak punya
  kolom R² di master, jadi tabelnya tidak berubah sama sekali.
- **Sekali per kelompok.** Nilainya dicetak di baris pertama saja, persis
  seperti master (`SERTIFIKAT!R47:R51` — empat baris sisanya kosong).
- **Minimal 3 titik.** Dua titik selalu menghasilkan R² = 1 apa pun datanya,
  jadi angka seperti itu bukan bukti linieritas dan tidak dicetak.
- **Sertifikat lama aman.** Snapshot yang terbit sebelum kolom ini ada tetap
  bisa dirender dan tidak berubah bentuk.
