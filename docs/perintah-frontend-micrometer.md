# Perintah frontend — lembar **Micrometer** (alat ke-25, kelompok Dimensi)

Dokumen berdiri sendiri. Tempel ke sesi kerja `sidik-calibration-mobile`; tidak perlu membaca
percakapan backend.

**Status backend:** BERES 4 Sep 2026. Profil, lembar kerja, mesin hitung, jalur simpan, jalur
hitung ulang, satu sesi contoh ter-seed, template OCR, dan `MicrometerMasterTest` (306 asersi,
diadu ke empat workbook master) hijau.

**Status HP:** BELUM DIKERJAKAN.

---

## 1. Yang berubah buat HP

| | |
|---|---|
| Kategori baru | `dimensi` — sebelumnya tidak pernah dipakai lembar mana pun |
| Kode profil | `micrometer` |
| Nama kemampuan | `Micrometer` (ejaan lampiran akreditasi no. 34) |
| Alias yang dikenal | `Mikrometer`, `Outside Micrometer`, `Micrometer Outside`, `Mikrometer Luar` |
| Endpoint bentuk | `GET /api/worksheet-schema?equipment_id=…` — sama seperti 24 alat lain |
| Nomor formulir | **null** — kertas lembar kerjanya belum pernah dikirim lab |
| Satuan simpan | **selalu `mm`**, apa pun skala alatnya. Lihat §4 — ini yang paling gampang salah |

Alat contoh sudah ter-seed, jadi bisa langsung dicoba:

| Sesi | Alat | Kapasitas / resolusi | Pita CMC |
|---|---|---|---|
| `0106-CAL-1023` | Micrometer Digital Mitutoyo IP65 (`ZQ-100`) | 50 mm / 0,001 mm | B (0,87 µm) |

## 2. Bentuk lembarnya

Tujuh bagian. Yang jadi baris titik di sertifikat cuma `hasil`:

| Bagian (`kode`) | Judul | Bentuk |
|---|---|---|
| `identitas_alat` | Identitas Alat dan Data Customer | field — termasuk dropdown **Satuan Alat**, lihat §4 |
| `pemilik` | Data Customer | field |
| `usage_check` | Standard Used | 1 baris centang (Gauge Block Standard) |
| `pra_evaluasi` | Pre-Evaluation (Outside Measurement) | 2 field suhu + **2 `tabel`**, lihat §3 |
| `hasil` | Data Hasil Kalibrasi | **2 `tabel`** bersisian, lihat §5 |
| `pemeriksaan_muka` | Pemeriksaan Muka Ukur | 2 dropdown Baik/Buruk |
| `penutup` | Catatan & Tanda Tangan | field |

## 3. Blok pra-evaluasi WAJIB diisi — dari situ ketidakpastiannya lahir

Ini beda paling penting dari 24 lembar sebelumnya. **Pengulangan (Type A) TIDAK datang dari
lima pembacaan tiap titik.** Dia datang dari blok pra-evaluasi: sepuluh pembacaan berulang di
SATU titik, plus tumpukan balok ukur yang dipakai untuk pembacaan itu.

Dua tabel di bagian `pra_evaluasi`:

| `grup` | Isi | Simpan ke |
|---|---|---|
| `pra_balok` | sampai 6 keping nominal balok ukur (mm) | `spesifikasi_alat.micrometer.balok_pra_evaluasi` |
| `pra_pembacaan` | 10 pembacaan berulang (mm) | `spesifikasi_alat.micrometer.pra_evaluasi` |

Plus dua field suhu di bagian yang sama:

- `spesifikasi_alat.micrometer.suhu_balok_c` — suhu balok ukur, °C
- `spesifikasi_alat.micrometer.suhu_uut_c` — suhu mikrometer, °C

**Kalau blok ini kosong ATAU pembacaan berulangnya kurang dari dua, seluruh titik pulang
sebagai `belum_dihitung`** dengan alasan yang kebaca — bukan dihitung dengan pengulangan nol.
Jadi UI-nya jangan memperlakukan bagian ini sebagai opsional walau `semua_kolom_opsional`
bernilai `true` untuk kolom tabel.

Yang satu pembacaan itu bentuk paling licin: simpangan bakunya jatuh ke nol, komponen
pengulangan hilang dari budget, U95 mendarat di lantai CMC — dan hasilnya kelihatan **wajar**.
Makanya server menolak menerbitkannya sama sekali, bukan menerbitkan angka yang lebih kecil.

Perhatikan: keduanya masuk `spesifikasi_alat`, **bukan** `measurements`. Blok tingkat-sesi
yang dipaksa jadi titik ukur lahir sebagai titik hantu yang selalu gagal hitung ulang.

## 4. Satuan: satu dropdown yang mengubah arti tiga field

`spesifikasi_alat.micrometer.satuan` — `mm` (×1), `inch` (×25,4), atau `µm` (×0,001).

**Isi ini LEBIH DULU.** Server mengonversi pembacaan alat ke mm memakai faktor ini, sekali, di
ujung masuk. Nominal balok ukur **tidak** dikonversi — sertifikat balok ukur selalu mm apa pun
skala mikrometernya.

Kenapa ini ditonjolkan: master lab sendiri pernah salah di sini. Sesi contoh 0-25 mm-nya
tersetel `inch` sementara angkanya diketik dalam milimeter, dan akibatnya berantai — kapasitas
25 dikali 25,4 jadi 635 mm, jatuh di luar semua pita CMC, dan koreksi yang tercetak
**−61 mm pada balok ukur 2,5 mm**. Tidak ada satu pun sel yang memprotes. Rinciannya di
`docs/pertanyaan-lab-micrometer.md` §1 dan §3.

Saran UI: taruh dropdown satuan di ATAS field kapasitas/resolusi, dan tampilkan nilai mm
hasil konversinya sebagai teks bantu di bawah kotaknya.

## 5. Satu titik = tumpukan balok ukur + deret pembacaan

Dua tabel bersisian di bagian `hasil`, dibedakan `grup` (**bukan** `peran` — `peran` di HP
berarti lembar pasangan standar/UUT dan membelokkan seluruh jalur kirim):

| `grup` | Judul | Kolom | Pengulangan |
|---|---|---|---|
| `mikro_balok` | Nominal Balok Ukur (tumpukan) | `nominal` | 1..3 keping |
| `mikro_pembacaan` | Pembacaan Mikrometer | `nilai` | 1..5 ulangan |

11 baris titik. Titik pertama biasanya **nol** (rahang tertutup, tanpa balok ukur) — itu sah,
dan tumpukan kosong di titik itu bukan error.

Bentuk payload `measurements` per titik:

```jsonc
{
  "titik_ukur": 25.0,               // total nominal CETAK tumpukan
  "mikro_balok":     [6.0, 19.0],   // nominal tiap keping, mm — TIDAK dikonversi
  "mikro_pembacaan": [25.001, 25.0, 25.001, 25.0, 25.001]  // satuan ALAT, dikonversi server
}
```

Titik dianggap terpakai kalau **salah satu** sisinya ada isinya, jadi titik yang teknisinya
baru sempat mengisi tumpukan baloknya tidak kebuang.

## 6. Ketidakpastian terbit SEKALI per sesi, bukan per titik

Sertifikat master mencetak satu baris `Uncertainty U95% = ±` di bawah sebelas titik. Server
memulangkan angka yang sama di `ketidakpastian_diperluas` **tiap** baris hitungan — jadi UI
boleh menampilkannya satu kali di bawah tabel, bukan satu kolom per baris.

Sembilan komponen budget (semuanya µm): repeatability, resolusi, standard balok ukur,
perubahan suhu terhadap 20 °C, koefisien muai thermal, drift standard, lapisan wringing,
kesalahan geometri, selisih suhu mikrometer–balok ukur.

## 7. Sesi bisa DIBLOKIR, dan itu disengaja

Ada **dua** sebabnya, dan server memperlakukan keduanya sama:

1. Kapasitas alat jatuh di luar keempat pita CMC terakreditasi (0-25, 25-50, 50-75, 75-100 mm)
   — tidak ada lantai ketidakpastian yang bisa dipertanggungjawabkan.
2. Pra-evaluasi berisi kurang dari dua pembacaan (§3) — tidak ada simpangan baku, jadi
   komponen pengulangan tidak punya dasar.

Yang **TIDAK** memblokir, walau ikut muncul di `belum_dihitung`: tanggal kalibrasi sesi lebih
awal dari sertifikat balok ukur standar yang tersimpan. Itu sesi historis — driftnya dianggap
nol, alasannya dicatat, dan sesinya tetap terbit di atas lantai CMC. Bedanya prinsipil:
pengulangan mengukur alat pelanggan itu sendiri, drift itu sifat standarnya.

Dalam dua sebab yang memblokir, server:

- memulangkan **`hitungan` KOSONG** — nol baris, bukan baris ber-U95 nol,
- memindahkan **semua** titik ke `belum_dihitung`, masing-masing dengan alasannya.

Khusus sebab no. 1, ada tambahan: temuan peringatan sesi berkode `micrometer_di_luar_cmc`
yang menyebut dugaan penyebabnya (satuan salah pilih). Sebab no. 2 tidak punya peringatan
sesi tersendiri — alasannya cuma ada di `belum_dihitung`, jadi UI **harus** menampilkan
daftar itu, bukan cuma mengandalkan panel peringatan.

**Kenapa nol baris, bukan U95 = 0.** Baris ber-`ketidakpastian_diperluas` nol tetap tercetak di
sertifikat sebagai `± 0,000` — klaim pengukuran **sempurna**, yang lebih buruk daripada angka
0,735 µm yang sedang diperbaiki. Dan peringatannya sendiri tidak menahan apa pun: server
membungkusnya jadi temuan tingkat PERINGATAN yang boleh dilewati admin. Jadi yang menahan
ketiadaan barisnya.

UI harus menampilkan `belum_dihitung` itu menonjol dan **tidak** menampilkan tabel hasil kosong
seolah-olah sesinya cuma belum diisi.

## 8. Yang perlu dibikin di HP

1. Kategori `dimensi` di daftar pilih alat berjenjang (permintaan 1).
2. Layar lembar Micrometer: tujuh bagian di atas, dua tabel di `hasil`, dua di `pra_evaluasi`.
3. Dropdown satuan yang mengubah teks bantu kapasitas/resolusi (§4).
4. Penanganan peringatan `blokir` (§7).
5. Mock lembar buat test — salin apa adanya dari respons
   `GET /api/worksheet-schema?equipment_id=…` alat `ZQ-100`, taruh di
   `lib/services/contoh_lembar_kerja_dimensi.dart`.
6. Test widget: lembar kegambar, payload dua-grup terkirim benar, peringatan blokir tampil.

## 9. Yang JANGAN dilakukan

- **Jangan** meratakan dua tabel `hasil` jadi satu deret `pembacaan`. Nominal balok ukur yang
  berbaur dengan penunjukan alat menggeser rata-rata tanpa satu pun error.
- **Jangan** memakai kunci `peran` untuk kedua tabel itu — pakai `grup`. `peran` di HP berarti
  lembar pasangan standar/UUT.
- **Jangan** mengonversi nominal balok ukur dengan faktor satuan. Cuma pembacaan alat.
- **Jangan** mengirim blok pra-evaluasi sebagai `measurements` ber-`titik_ke`. Dia
  `spesifikasi_alat`.
