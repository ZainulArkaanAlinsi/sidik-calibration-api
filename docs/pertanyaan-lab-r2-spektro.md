# Pertanyaan ke lab — kolom R² blok %T (Spectrophotometer)

> **Status 14 Agt 2026 — kolomnya sudah dicetak.** Pertanyaan di bawah masih
> berlaku dan masih perlu dijawab, tapi sudah **tidak menahan** apa pun.
> Sebabnya di [Kenapa akhirnya dicetak](#kenapa-akhirnya-dicetak): sel R² di
> master diformat nol desimal, jadi kedua kandidat angka yang belum bisa
> dibedakan (0,9359 dan 0,999922) sama-sama **tercetak `1`**. Apa pun jawaban
> lab nanti, yang muncul di sertifikat tidak berubah.

Satu kolom di sertifikat Spectrophotometer sempat tidak dihasilkan sistem, dan
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

## Kenapa sistem sempat tidak mencetaknya

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

## Kenapa akhirnya dicetak

Keberatan awalnya: R² adalah pernyataan seberapa linier respons alat pelanggan,
dan mengisinya dengan angka yang kami hitung sendiri (0,99992) berarti
sertifikat terakreditasi memuat klaim yang **berbeda dari dokumen lab yang
sudah beredar**. Selama itu kolomnya tidak dicetak sama sekali.

Yang membuka jalan bukan rumusnya, melainkan **format selnya**. Sel R² di
master diformat nol desimal — itu yang sudah tercatat di bagian atas dokumen
ini, tapi konsekuensinya baru ditarik 14 Agt 2026 waktu tampilan workbook-nya
diadu langsung:

| Kandidat | Nilai | Tercetak (0 desimal) |
|---|---|---|
| Isi sel master | 0,9359 | **1** |
| `RSQ(Standard %T; UUT %T)` | 0,999922 | **1** |

Dua kandidat yang belum bisa dibedakan **mencetak angka yang sama**. Jadi
menyalakan kolomnya tidak bisa membuat sertifikat mengklaim linieritas yang
berbeda dari dokumen lab — apa pun jawaban lab nanti. Yang tersisa cuma
pertanyaan asal-usul angkanya, dan itu tidak perlu menahan satu kolom yang
hasil cetaknya sudah pasti.

Kolomnya sekarang dicetak **nol desimal**, di PDF maupun Excel. Bukti bentuknya
dikunci `R2SpektroTest`; kalau jawaban lab ternyata rumus yang berbeda dan
hasilnya **tidak** membulat ke `1`, tes itu yang merah duluan.

Sisa sertifikatnya tidak terpengaruh: 24 titik lain (Standard/UUT/Correction)
dan ketiga U95 sudah cocok dengan master sampai batas presisi penyimpanan.

## Yang sudah terpasang di sistem

Kolomnya dibangun penuh — PDF, Excel, dan halaman verifikasi QR — dan sekarang
**menyala secara bawaan**.

Sakelarnya satu nilai config, dan tetap ada supaya definisinya bisa diganti
tanpa menambal kode:

```php
// config/kalibrasi.php
'r2_spektro' => env('SPEKTRO_R2', 'rsq_standar_uut'),
```

| Nilai | Perilaku |
|---|---|
| `rsq_standar_uut` (bawaan) | Kolom R² = `RSQ(Standard %T; UUT %T)` atas seluruh titik blok %T, dicetak 0 desimal |
| `off` | Kolom R² tidak ada sama sekali di PDF maupun Excel |

Nilai lain (termasuk salah ketik di `.env`) diperlakukan sebagai `off`, supaya
isi sertifikat terakreditasi tidak bisa berubah karena typo yang tidak direview.

Kalau nanti jawaban lab ternyata rumus yang berbeda, yang diganti cuma isi
`SpectrophotometerProfile::koefisienDeterminasi()` — sisanya (pembekuan ke
snapshot, tata letak kolom, pembulatan 0 desimal) sudah terpasang dan bertes.

Catatan yang ikut menentukan bentuk kolomnya:

- **Cuma blok %T.** Dua blok panjang gelombang (Holmium & Didynium) tidak punya
  kolom R² di master, jadi tabelnya tidak berubah sama sekali.
- **Satu kotak setinggi tabelnya.** Selnya di-`rowspan`, meniru sel merge
  master (`SERTIFIKAT!R47:R51`) — bukan lima angka yang kebetulan sama.
- **Nol desimal.** `1`, bukan `0,9999` — mengikuti format sel masternya.
- **Minimal 3 titik.** Dua titik selalu menghasilkan R² = 1 apa pun datanya,
  jadi angka seperti itu bukan bukti linieritas dan tidak dicetak.
- **Sertifikat lama aman.** Snapshot yang terbit sebelum kolom ini ada tetap
  bisa dirender dan tidak berubah bentuk.
