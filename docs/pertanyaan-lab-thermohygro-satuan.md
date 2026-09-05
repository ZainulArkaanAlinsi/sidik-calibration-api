# Pertanyaan Lab — Thermohygrometer: baris kelembaban tercetak di bawah kepala °C

Status: menunggu jawaban · Ditemukan 3 September 2026

Ditemukan **tidak sengaja**, waktu mengukur apakah U95 per titik perlu dinyalakan
di luar kelompok instrumen analitik (permintaan Pak Rohman, 3 Sep 2026). Bukan
bagian dari permintaan itu, dan sengaja **tidak** ikut diperbaiki di PR yang sama —
lihat §Kenapa ditahan.

---

## Apa yang tercetak sekarang

Sertifikat Thermohygrometer (`0312-CAL-624`) punya **10 titik dalam satu tabel**,
dan `remark` kesepuluhnya `NULL`. Blade mengelompokkan tabel hasil dengan
`groupBy(remark)` (`resources/views/sertifikat/pdf.blade.php`), jadi sepuluh
barisnya jatuh ke **satu kelompok**.

Satu kelompok = satu satuan, dan satuannya diambil dari **baris pertama**:

```text
Standard (°C) | Unit Under Test (°C) | Correction (°C)
        15,77 |               14,36 |            1,41
        25,60 |               24,40 |            1,20
        35,64 |               34,54 |            1,10
        45,35 |               44,48 |            0,87
        56,15 |               54,52 |            1,63
        48,83 |               49,00 |           -0,17   ← ini %RH
        69,83 |               69,00 |            0,83   ← ini %RH
        88,53 |               89,00 |           -0,47   ← ini %RH
        29,93 |               29,00 |            0,93   ← ini %RH
        47,83 |               48,00 |           -0,17   ← ini %RH
```

Lima baris terakhir itu pembacaan **kelembaban**, tapi tercetak di bawah kepala
kolom **°C**.

## Bukti bahwa lima baris itu memang kelembaban

Di snapshot, U95 kesepuluh barisnya cuma punya dua nilai — dan pemisahnya persis
di titik ke-6:

| titik | `satuan` di snapshot | U95 |
|---|---|---|
| 1–5 | `°C` | 1,9788 |
| 6–10 | `°C` | **4,8** |

`1,9788 °C` dan `4,8 %RH` itu dua angka yang sudah tercatat sebagai U95 suhu dan
U95 RH Thermohygrometer di `docs/permintaan-user-7.md` §G7. Jadi barisnya memang
dua besaran, tapi `satuan`-nya ditulis `°C` untuk sepuluh-sepuluhnya.

## Akibatnya, dan kenapa ini bukan sekadar tata letak

1. **Angka kelembaban dibaca sebagai suhu.** Pembaca yang memakai kolom sesuai
   judulnya sampai ke kesimpulan yang salah — 88,53 dibaca 88,53 °C.
2. **U95 satu tabel = satu angka.** Yang tercetak U95 baris pertama (1,9788),
   jadi ketidakpastian kelima baris kelembaban (4,8) **tidak muncul sama sekali**
   di dokumen.

## Yang perlu diputuskan lab

1. **T-TH-1.** Di master lab, apakah suhu dan kelembaban Thermohygrometer memang
   dua tabel terpisah dengan kepala kolom & U95 sendiri-sendiri? Kalau ya,
   bentuk cetaknya ikut master.
2. **T-TH-2.** Kalau dua tabel, judul kelompoknya apa persisnya — mengikuti
   `remark` seperti Spectrophotometer (`Wave Length ( λ ) - Filter Holmium`),
   atau ada penamaan sendiri di formulir?
3. **T-TH-3.** Sertifikat Thermohygrometer yang **sudah terbit** ikut dibangun
   ulang, atau berlaku maju saja? Ini menyangkut dokumen di bawah lingkup
   terakreditasi yang mungkin sudah dipegang pelanggan — preseden yang ada
   §10 Waktu-Frekuensi: **berlaku maju**, dan pelaporan ketidaksesuaiannya jalur
   sistem mutu, bukan diputuskan dari repositori ini.

## Kenapa ditahan, tidak langsung diperbaiki

Menebak jawabannya berarti mengarang bentuk dokumen terkendali. Yang paling
mungkin benar — pisah jadi dua kelompok bersatuan masing-masing — mengubah
tata letak sertifikat yang sudah terbit, dan itu keputusan pemilik lab.

Yang **sudah** dikerjakan supaya masalahnya tidak makin dalam: `satuan` per baris
memang sudah ada di snapshot, jadi begitu keputusannya turun, perbaikannya tinggal
mengubah kunci pengelompokan — nol perubahan di mesin hitung.
