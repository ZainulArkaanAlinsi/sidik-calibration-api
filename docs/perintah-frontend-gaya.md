# Perintah Frontend — Alat Gaya (UTM, Load Cell, Proving Ring)

> Buat pengembang `sidik-calibration-mobile`. Berdiri sendiri: tidak perlu
> membuka kode server untuk mengerjakan sisi HP-nya.
>
> Status per 24 Sep 2026: **UTM** (`utm`) dan **Load Cell** (`load_cell`) sudah
> ada di server. Proving Ring menyusul. Dokumen ini sudah memuat bentuk
> ketiganya supaya layarnya tidak perlu dibongkar dua kali.
>
> UTM dan Load Cell memakai **layar yang sama persis** — bentuk lembarnya lahir
> dari kelas induk yang sama. Yang berbeda cuma nomor formulir, jumlah desimal
> yang tercetak di sertifikat, dan satuan bawaannya. Jangan bikin dua layar.

---

## 1. Kenapa alat gaya beda dari semua alat sebelumnya

Dua hal yang tidak ada di 39 profil lain:

**a. Satu titik = DUA BELAS pembacaan, bukan tiga.** Empat posisi (0°, 90°,
180°, 270°) × tiga replikat. Ini bukan pengulangan biasa: mesin uji punya
piringan, dan kalau load cell standar tidak tepat di sumbunya, gaya tidak jatuh
lurus. Kesalahan itu **cuma kelihatan** kalau alat dihadapkan ke arah berbeda.

**b. Ada blok tingkat-SESI yang bukan titik ukur:** preload (zero & kapasitas
maks, 3 replikat) dan empat pengukuran misalignment. Keduanya masuk budget
ketidakpastian, jadi bukan catatan tambahan yang boleh dilewat.

Proving Ring lain lagi: tidak diputar empat posisi, tapi diuji **UP 3× dan
DOWN 3×** karena cincin bajanya punya histeresis. Keluarannya juga beda —
bukan "meleset berapa" melainkan **faktor kalibrasi kN/Div**.

---

## 2. Ambil bentuk lembarnya

```
GET /api/calibrations/lembar-kerja?profil=utm
```

Balasannya sama bentuknya dengan profil lain. Yang perlu diperhatikan:

| Bagian (`kode`) | Isi |
|---|---|
| `identitas_alat` | pilih alat, merk, no. seri, **kapasitas**, **satuan gaya**, **resolusi**, **tipe beban** |
| `pemilik` | pelanggan, kondisi lingkungan, lokasi, thermohygro |
| `usage_check` | pilih load cell standar + **tabel Preload** (2 baris × 3 replikat) |
| `misalignment` | empat kotak angka (X1..X4, mm) |
| `hasil` | **empat tabel** posisi, barisnya sinkron |
| `penutup` | catatan & tanda tangan |

| Profil | `kode_dokumen` | Satuan sesi contoh | Desimal sertifikat |
|---|---|---|---|
| `utm` | `SIDIK-FM-CAL-0519_Rev.3` | kgf | 1 |
| `load_cell` | `SIDIK-FM-CAL-0520_Rev.3` | kN | 2 |

Desimalnya datang dari **resolusi alat**, bukan dari selera: UTM contohnya
beresolusi 0,1 kgf, Load Cell 0,01 kN. HP tidak perlu menghitungnya sendiri —
angka yang tercetak sudah dibulatkan server, dan `GET` sertifikat memulangkannya
apa adanya.

---

## 3. Empat tabel yang barisnya SINKRON

Bagian `hasil` memuat empat tabel dengan `grup` berbeda:

```
gaya_pos_0    offset_kunci 1000   "a. Posisi 0°"
gaya_pos_90   offset_kunci 2000   "b. Posisi 90°"
gaya_pos_180  offset_kunci 3000   "c. Posisi 180°"
gaya_pos_270  offset_kunci 4000   "d. Posisi 270°"
```

**Baris ke-n keempat tabel itu titik beban yang SAMA.** Nominalnya diketik
sekali (baris `titik_ukur` kosong = kotak terbuka), dan keempat tabel mengacu
titik yang sama.

`offset_kunci` beda supaya kotak isian di HP tidak saling menimpa — pola yang
sama dengan Hydrometer dan Volumetric Glassware. Jangan disamakan.

### Kirimnya

```jsonc
{
  "measurements": [
    {
      "titik_ukur": 200,
      "satuan": "kgf",
      "gaya_pos_0":   [200.538, 200.600, 200.538],
      "gaya_pos_90":  [200.538, 200.538, 200.538],
      "gaya_pos_180": [200.538, 200.538, 200.538],
      "gaya_pos_270": [200.538, 200.538, 200.538]
    }
  ]
}
```

Tiap deret **tepat 3 nilai**. Server memakai keduabelasnya sekaligus untuk
rata-rata, STDEV, dan RRPE — tapi menyimpannya terpisah per posisi supaya
Master Data bisa melihat sebaran antar posisi.

---

## 4. Blok tingkat-sesi (WAJIB)

Dikirim di `spesifikasi_alat.gaya`:

```jsonc
{
  "spesifikasi_alat": {
    "gaya": {
      "satuan": "kgf",            // kN | N | lbf | kgf | tnf — WAJIB
      "tipe_beban": "Pull",       // Push | Pull — WAJIB
      "standar": "5kN",           // 5kN | 100kN | 3000kN — WAJIB
      "suhu_sertifikat_standar": 23.15,
      "kapasitas": 500,
      "resolusi_uut": 0.1,
      "resolusi_standar": 0.0000981,   // kN
      "kapasitas_standar": 5.0,        // kN
      "preload_zero": [0, 0, 0],
      "preload_max": [999.1, 998.9, 999.1],
      "misalignment": [8.237, 8.234, 8.237, 8.238]
    }
  }
}
```

**Kalau blok ini tidak lengkap, sesinya tidak dihitung** — server memulangkan
alasannya per titik, bukan angka kosong. Tiga yang paling sering terlupa:
`satuan`, `standar`, dan `tipe_beban`.

> **Kenapa satuan wajib:** master Excel menjawab string `"PILIH SATUAN"` kalau
> belum dipilih, dan string itu bisa ikut mengalir ke hasil. Di server ini
> satuan yang tidak dikenal **dilempar**, bukan dianggap 1.

---

## 5. Kombinasi standar × arah yang TIDAK ADA

Tidak semua kombinasi punya tabel kalibrasi:

| Standar | Push | Pull |
|---|---|---|
| 5 kN | ada | ada |
| 100 kN | ada | ada |
| 3000 kN | ada | **tidak ada** |

Kalau teknisi memilih `3000kN` + `Pull`, server **menolak menghitung** dan
menyebut alasannya. Itu bukan bug: load cell 3000 kN memang cuma dikalibrasi
arah tekan, dan menganggap koreksinya nol berarti menerbitkan angka yang tidak
pernah ditelusuri ke sertifikat mana pun.

Sebaiknya HP menyaring dropdown-nya begitu tipe beban dipilih.

---

## 6. Yang dicetak di sertifikat

```
Standard Value │ Unit Under Test │ Correction │ RRPE
    (kgf)      │      (kgf)      │   (kgf)    │  (%)
───────────────┼─────────────────┼────────────┼───────
     0,0       │       0,0       │    0,0     │   −
   100,4       │     100,0       │    0,4     │  0,00
   200,8       │     200,0       │    0,9     │  0,03

Uncertainty U95% = ± 2,1 kgf,  k = 2
```

Tiga hal yang perlu diketahui sisi HP:

1. **Tidak ada PASS/FAIL.** Sertifikat gaya tidak memvonis lulus/tidak — lab
   melaporkan seberapa meleset, pelanggan yang menilai. Jangan menampilkan
   lencana "laik pakai" di layar mana pun.
2. **RRPE titik nol dicetak `−`**, bukan `0,00`. Titik nol memang tidak punya
   RRPE (pembaginya nol).
3. **`k` dicetak 2** walau nilai hitungnya 1,9698…

---

## 7. Peringatan yang sebaiknya ditampilkan di HP

Server mengirimkannya sebagai temuan; menampilkannya lebih awal menghemat satu
putaran bolak-balik ke Master Data:

- **12 pembacaan identik pada satu titik** — mesin uji nyata hampir selalu
  berbeda di digit terakhir. Bukan blokir, tapi minta konfirmasi.
- **Beban di luar rentang tabel standar** — koreksinya diambil dari titik
  terdekat, bukan interpolasi tervalidasi.
- **Pembacaan nol pada beban non-nol** — ini temuan, bukan angka nol yang wajar.

---

## 8. Desimal koma

Pembacaan gaya ditulis sampai empat desimal (`300,3006`). Keyboard HP Indonesia
default koma. **Normalisasi di Flutter sebelum kirim**; test wajib:
`"300,3006"` → `300.3006`, bukan `3003006` atau `300`.
