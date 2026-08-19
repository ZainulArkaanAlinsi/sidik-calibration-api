# Pertanyaan ke lab — Lembar Kerja Conductivity

Tiga hal di lembar kerja `SIDIK-FM-CAL-0510_Rev.5` tidak cocok dengan
`Master Olah Data_Conductivity.xlsm`. Sistem sudah mengikuti master, karena
master yang bisa diadu ke sertifikat yang sudah beredar. Yang dibutuhkan
sekarang pernyataan lab tentang mana yang berlaku.

Pesan siap kirim ada di bagian paling bawah.

---

## Ringkas: kertas lebih tua dari master

PDF formulirnya dibuat **15 Desember 2023**. Sesudah itu master berubah:

| Tanggal | Catatan di `01 - FORM VALIDASI` |
|---|---|
| 3 Apr 2024 | *"Update new larutan std conduct (certified MRA/ISO 17034). Change point ukur menjadi 3 (25 uS, 1412 uS, dan 111 mS)"* |
| 13 Agt 2025 | *"Update new larutan std conduct 25 uS dan 1412 uS (certified MRA/ISO 17034)"* |

Sheet `DATABASE` sekarang cuma memuat **tiga** larutan yang punya nomor seri,
ketertelusuran, dan tanggal jatuh tempo:

| Larutan | Merk | S/N | Tertelusur | U95% | Jatuh tempo |
|---|---|---|---|---|---|
| Conductivity Std Solution 25 µS/cm | Supelco/Merck | LRAD7693 | Merck KGaA | 0,5 µS/cm | 1 Feb 2027 |
| Conductivity Std Solution 1412 µS/cm | Supelco/Merck | LRAD9052 | Merck KGaA | 8 µS/cm | 10 Jun 2027 |
| Conductivity Std Solution 111 mS/cm | Supelco/Merck | HC56824055 | Merck KGaA | 1600 µS/cm | 30 Apr 2028 |

---

## Pertanyaan 1 — kolom larutan: empat nominal lama, atau tiga yang sekarang?

Kertas Rev.5 punya **empat** kolom: `84` · `1413 µS / 1.413 mS` ·
`5000 µS / 5 mS` · `80000 µS / 80 mS`.

Sheet `INPUT DATA` master masih memakai nama yang sama untuk centang standar,
tapi isinya menunjuk ke larutan yang sekarang:

```
Conduct 84     : True → Conductivity Std Solution 25 µS/cm
Conduct 1413   : True → Conductivity Std Solution 1412 µS/cm
Conduct 5000   : True → Conductivity Std Solution 111 mS/cm
Conduct 80000  : True → (kosong)
```

Dua bacaan yang mungkin, dan keduanya mengarah ke kesimpulan yang sama —
nama slotnya peninggalan lama, larutannya yang sekarang:

- nama centang di master tidak ikut diganti waktu titik ukurnya jadi tiga, atau
- nama itu memang nominal botol lama yang tidak dipakai lagi

**Yang perlu dijawab:** apakah teknisi masih menuang botol 84 / 1413 / 5000 /
80000 µS, atau sudah 25 µS/cm · 1412 µS/cm · 111 mS/cm? Kalau sudah, formulir
cetaknya perlu revisi — kepala kolomnya menyebut botol yang tidak ada lagi di
`DATABASE`.

Catatan tambahan: slot keempat (`80000`) dicentang `True` di master tapi baris
`DATABASE`-nya kosong — tidak punya nilai acuan, CMC, maupun kurva suhu. Di
aplikasi kolom itu digambar (karena ada di kertas) tapi tidak bisa diisi.

## Pertanyaan 2 — TH-7 itu Insitu atau Inlab?

Lembar Conductivity Rev.5 mencetak `Insitu: TH-2, TH-6, TH-7` dan
`Inlab: TH-4`. Sistem dan empat profil alat lain menaruh **TH-7 di Inlab**.

Ini murni soal di baris mana kotaknya tercetak — unit yang dipilih tetap unit
yang sama. Tapi kalau lembar Conductivity menaruhnya berbeda dari lembar pH,
salah satunya keliru cetak.

## Pertanyaan 3 — "Victor 14+" masih dipakai?

Daftar STANDARD di kertas menyebut `PRT PT100` dan `Victor 14+`. Sheet
`DATABASE` master menyebut:

| Nama | Merk/Type | S/N | Kalibrasi terakhir |
|---|---|---|---|
| Termometer & Sensor Std. | Yokogawa/CA 150 Handy Cal | 23P1005 | 12 Agt 2025 |
| PT100/SH1 | — | 20 | 14 Feb 2025 |

`Victor 14+` tidak muncul di master mana pun. Dugaannya readout lama yang sudah
diganti Yokogawa CA 150 — perlu dipastikan, karena nama alat standar ikut
tercetak di sertifikat.

---

## Kalau Rev.5 yang dinyatakan berlaku

Aplikasi tidak perlu diubah untuk pertanyaan 2 dan 3 — cukup satu baris
setelan. Untuk pertanyaan 1 lain: kalau botol 84 / 1413 / 5000 / 80000 memang
masih dipakai, yang berubah bukan tampilan tapi **data standar** — nilai acuan,
CMC, dan kurva suhu tiap botol harus dimasukkan ke master alat lebih dulu.
Sebelum itu ada, kolomnya tidak bisa dihitung.

---

## Pesan siap kirim

> Pak/Bu, mau konfirmasi tiga hal soal Lembar Kerja Conductivity
> (SIDIK-FM-CAL-0510 Rev.5). Formulirnya dibuat Desember 2023, sementara Master
> Olah Data_Conductivity berubah sesudahnya (3 April 2024: titik ukur jadi 3 —
> 25 µS/cm, 1412 µS/cm, 111 mS/cm), jadi ada beberapa bagian yang tidak lagi
> sama.
>
> 1. Kolom larutan di kertas masih 84 / 1413 / 5000 / 80000 µS, sedangkan sheet
>    DATABASE cuma punya tiga larutan bersertifikat: 25 µS/cm (LRAD7693),
>    1412 µS/cm (LRAD9052), dan 111 mS/cm (HC56824055). Yang dituang teknisi di
>    lapangan sekarang yang mana? Kalau sudah yang tiga, formulirnya perlu
>    direvisi.
> 2. Di lembar ini TH-7 tercetak di baris Insitu, sedangkan di lembar alat lain
>    TH-7 masuk Inlab. Mana yang benar?
> 3. Daftar standar di kertas menyebut "Victor 14+", sedangkan DATABASE menyebut
>    Termometer & Sensor Std. Yokogawa/CA 150 Handy Cal (S/N 23P1005). Victor
>    14+ masih dipakai atau sudah diganti?
>
> Sistemnya sekarang mengikuti master, jadi angka sertifikatnya sudah cocok
> dengan yang selama ini terbit. Ketiga hal di atas yang belum bisa kami putuskan
> sendiri. Terima kasih.
