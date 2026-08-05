# Handoff Frontend — Chlorine Meter (alat ke-3) & `valid` yang berhenti merah palsu, 5 Agustus 2026

Lanjutan dari [`HANDOFF-FRONTEND-31-Jul.md`](HANDOFF-FRONTEND-31-Jul.md). Bagian 2 di
dokumen ini **mencabut sebagian** yang ditulis di bagian 1 dokumen itu — baca bagian 2
duluan kalau kamu yang kemarin ngerjain layar approval admin.

Isinya tiga hal: lembar kerja alat baru (Chlorine Meter), satu perubahan perilaku di
`GET /calibrations/{id}/validasi`, dan data master baru buat pengujian.

Semua bentuk request/respons di bawah diambil dari respons asli, bukan dari ingatan.

---

## 1. Chlorine Meter — lembar kerjanya udah bisa diambil

Alat ke-3 sesudah pH Meter & Turbidimeter. Endpoint-nya **yang lama, nggak ada yang baru**:

```http
GET /api/calibrations/lembar-kerja?profil=chlorine_meter
```

Bisa juga `?instrumen=Chlorin Meter`. Perhatiin ejaannya: **"Chlorin", tanpa `e`** —
itu nama di lampiran akreditasi dan itu yang dicocokin backend. Yang pakai `e`
("Chlorine Meter") itu `nama_alat` buat ditampilin ke user. Kalau kamu kirim
`?instrumen=Chlorine Meter`, yang balik profil **pH Meter** (default), bukan error —
jadi salah ejaannya nggak keliatan sampai lembar kerjanya kerender salah. Amannya
pakai `?profil=chlorine_meter` aja.

### Bedanya dari dua alat sebelumnya

| | pH Meter | Turbidimeter | **Chlorine Meter** |
|---|---|---|---|
| Titik | 3 (4 / 7 / 10,01) | 3 (1/100/1000) | **2 — 1,74 & 1,83 mg/L** |
| Pengulangan | 5 | 5 | 5 |
| Satuan | pH | NTU | **mg/L** |
| Resolusi | seragam | **beda per titik** | seragam 0,01 |
| `resolusi`/`desimal` per baris | tidak dikirim | **dikirim** | **tidak dikirim** |
| Halaman | 1 | 1 | 1 |
| `kode_dokumen` | SIDIK-FM-CAL-0509_Rev.4 | SIDIK-FM-CAL-0530_Rev.2 | **SIDIK-FM-CAL-0531_Rev.2** |

**Soal `resolusi`/`desimal`:** di turbidimeter kamu wajib baca keduanya per baris
(1 NTU pakai 2 desimal, 1000 NTU pakai 0). Di chlorine dua-duanya **nggak dikirim** —
artinya seragam, ambil dari `equipment.resolusi` (0,01 → 2 desimal). Aturan ini sama
dengan pH, jadi kode yang udah ada mestinya jalan tanpa cabang baru. Yang penting:
**jangan nebak dari jumlah titik** — patokannya ada/tidaknya field itu.

### ⚠️ Titiknya 1,74 & 1,83 — bukan 0,40 & 4,00

Form cetak Rev.2 yang dipegang teknisi nulis `Solution Standard 0.40` & `4.00`. **Itu
ketinggalan zaman.** Yang mengikat lampiran akreditasi LK-285-IDN no. 42 (berlaku
28 Okt 2024–27 Okt 2029): titik 1,74 & 1,83 mg/L. Kalibrasi di titik luar lampiran
nggak bisa jadi sertifikat berakreditasi.

Jadi: **jangan hardcode titik dari foto lembar kerja**. Ambil dari `larutan_standar`
di respons:

```jsonc
{
  "kode_dokumen": "SIDIK-FM-CAL-0531_Rev.2",
  "judul": "Calibration Worksheet - Chlorine Meter",
  "jumlah_pengulangan": 5,
  "larutan_standar": [1.74, 1.83],   // ← sumber kebenaran titik
  "satuan": "mg/L",
  "satuan_suhu": "°C",
  "semua_kolom_opsional": true,
  "bagian": [ /* ... */ ]
}
```

### Struktur `bagian` (semua di halaman 1)

| `kode` | Judul | Isi |
|---|---|---|
| `identitas_alat` | EQUIPMENT IDENTITY AND CUSTOMER DATA | 9 field, termasuk `thermohygro_standard_id` |
| `pemilik` | OWNER | `pemilik_nama`, `pemilik_alamat` |
| `usage_check` | STANDARD | tabel `baris` + field `standar_dicek.*` |
| `data_kalibrasi` | CALIBRATION DATA | `lokasi`, `room_id` |
| `hasil` | CALIBRATION RESULT | suhu/kelembaban awal-akhir + 2 tabel pembacaan |
| `penutup` | Catatan & Tanda Tangan | `catatan_teknisi`, nama teknisi & reviewer |

Polanya identik dengan dua profil sebelumnya, jadi komponen yang udah ada mestinya
kepakai ulang. Dua hal yang beda:

**a. Baris STANDARD ada yang `terdaftar: false`.**

```jsonc
"baris": [
  { "label": "Chlorine Standard Solution 1.74 mg/L", "standard_id": 16,   "terdaftar": true  },
  { "label": "Chlorine Standar Cuvettes 1.83 mg/L",  "standard_id": 17,   "terdaftar": true  },
  { "label": "RTD Sensor/SH1/20",                    "standard_id": 12,   "terdaftar": true  },
  { "label": "Victor 14+/992613877",                 "standard_id": null, "terdaftar": false }
]
```

Baris terakhir **tetap harus dirender** (ada di form cetak, teknisi mencentangnya),
tapi `standard_id`-nya `null` — jangan dikirim balik sebagai id. Ini bukan bug data:
alatnya ada di lembar kerja tapi belum masuk master `standards`.

**b. Pilihan thermohygro udah disaring & dikelompokkan** — `TH-2`, `TH-6`, `TH-7`
(grup `Insitu`) dan `TH-4` (grup `Inlab`). Render pakai grup itu, jangan diurutkan
ulang sendiri.

### Tabel pembacaan

Dua tabel, `tahap: "sebelum_adjustment"` dan `"sesudah_adjustment"`, masing-masing
2 baris × 5 pengulangan, kolom `pembacaan` (mg/L) + `suhu` (°C).

**Kolom suhu wajib ada di UI walau standarnya nggak punya kurva suhu.** Suhu di sini
bukan buat ngoreksi nilai acuan (larutan chlorine dibaca nominal) — dia masuk budget
ketidakpastian lewat komponen `ketidakpastian_temperature`. Kalau kolomnya kamu
sembunyiin karena "toh nggak dipakai", angka ketidakpastiannya berubah.

---

## 2. ⚠️ PERUBAHAN PERILAKU — `valid` di layar approval

**Ini mencabut sebagian bagian 1 di [`HANDOFF-FRONTEND-31-Jul.md`](HANDOFF-FRONTEND-31-Jul.md).**

Temuan `standar_tanpa_kurva_suhu` **berhenti muncul** buat alat yang standarnya
memang dibaca nominal — Chlorine Meter & Turbidimeter. Bentuk responsnya nggak
berubah sama sekali, cuma temuannya nggak keluar lagi.

### Yang berubah di layar kamu

Sebelum ini, **tiap** sesi chlorine & turbidimeter selalu `"valid": false`. Bukan
karena datanya bermasalah — tapi karena `koefisien_suhu` NULL di standar mereka
dianggap "data master belum diisi", padahal NULL di situ jawaban yang benar.

Di DB dev, empat sesi berubah begitu perbaikannya jalan:

```
sesi #7  Chlorine Meter   valid: false → true
sesi #11 Turbidimeter     valid: false → true
sesi #12 Turbidimeter     valid: false → true
sesi #13 Turbidimeter     valid: false → true
```

Jadi kalau kamu:

- **nampilin badge merah / blocker berdasarkan `valid`** → sesi chlorine &
  turbidimeter yang tadinya selalu merah sekarang hijau. Nggak ada yang perlu
  diubah, tapi jangan kaget pas regression test.
- **sempat bikin workaround** semacam "buang temuan `standar_tanpa_kurva_suhu`
  kalau alatnya turbidimeter" → **cabut sekarang**, udah ditangani backend.

### Yang TIDAK berubah

`suhu_larutan_tidak_dicatat` (kasus pH) **tetap jalan seperti sebelumnya** — itu
temuan yang beneran nunjuk data bolong. Jangan ikut dihapus.

`standar_tanpa_kurva_suhu` juga **masih ada** buat alat lain. Yang berubah cuma:
sekarang backend nanya profil alatnya dulu sebelum nyalain peringatan itu.

### Sertifikat yang terlanjur terbit

Sertifikat lama nyimpen snapshot temuan **saat diterbitkan**, jadi `validasi` di
sertifikat #3 & #6–8 masih berisi temuan versi lama. Itu disengaja — snapshot
dokumen resmi nggak ditimpa surut. Kalau layar detail sertifikat nampilin temuan
dari snapshot, sertifikat lama bakal keliatan beda dari hasil validasi ulang sesi
yang sama. Bukan bug.

---

## 3. Data master baru (buat pengujian)

| Data | Nilai | Catatan |
|---|---|---|
| `Chlorine Standard Solution 1.74 mg/L` | U95 0,09 mg/L, k=2 | id 16, berlaku s/d **2027-04-30** |
| `Chlorine Standar Cuvettes 1.83 mg/L` | U95 0,06 mg/L, k=2 | id 17, berlaku s/d **2027-04-30** |
| Alat contoh | Hanna HI97711, S/N 905320134111 | 0–4 mg/L, resolusi 0,01 |
| Sesi contoh | `2406.32.C` | order `2406.32.C.NK`, PASS, 2 titik |
| `TH-5` | due **2026-06-18** (dulu 2024-06-18) | **tetap kadaluarsa** — jangan muncul sebagai pilihan valid |

Sesi contoh `2406.32.C` sekarang lolos validasi **tanpa satu pun temuan**
(`"temuan": []`) — pakai ini kalau butuh kasus "bersih sempurna" buat nge-tes
tampilan layar approval.

Tanggal berlaku dua larutan chlorine sempat kebaca `2027-08-05` di DB dev sampai
5 Agt siang. Itu data basi, bukan kontrak — sekarang `2027-04-30`. Kalau kamu
terlanjur nyalin angka lama ke fixture, perbarui.

---

## 4. Satu hal yang jangan bikin kaget: U95 titik 1,83

Kalau ada yang ngadu **"angka di aplikasi beda sama Excel lab"** buat chlorine titik
1,83 — itu udah diperiksa, dan yang salah Excel-nya.

- Aplikasi: **0,08 mg/L**
- Excel lab (`SERTIFIKAT.csv`): **0,0801585479793244 mg/L**

Sel repeatability di sheet `PERHITUNGAN U95%` nyimpen `ui = 0,0244949` padahal
STDEV-nya sendiri 0 (lima pembacaannya 1,86 semua) — sisa data lama yang nggak
ke-refresh. Yang bikin serius: 0,0802 itu **di atas CMC 0,08**, alias di luar lingkup
akreditasi lab sendiri.

**Jangan "samain sistem ke Excel"** kalau ada yang minta. Keputusannya ada di lab,
dan sampai mereka mbenerin selnya, angka yang benar 0,08. Detailnya di docblock
`database/seeders/ChlorineSeeder.php`.

---

## Ringkasan buat yang buru-buru

1. **Chlorine Meter jalan** — `GET /calibrations/lembar-kerja?profil=chlorine_meter`.
   2 titik (1,74 & 1,83 mg/L), 5 pengulangan, resolusi seragam. Struktur `bagian`
   sama persis kayak dua alat sebelumnya.
2. **Jangan hardcode titik 0,40 & 4,00** dari form cetak — baca `larutan_standar`.
3. **Ejaan `Chlorin Meter`** (tanpa `e`) kalau pakai `?instrumen=`; salah ejaan
   diam-diam balik ke profil pH. Pakai `?profil=chlorine_meter` aja.
4. **`valid` buat chlorine & turbidimeter berhenti merah palsu.** Cabut workaround
   `standar_tanpa_kurva_suhu` kalau kamu sempat bikin. `suhu_larutan_tidak_dicatat`
   (pH) nggak berubah.
5. **Kolom suhu tetap dirender** di tabel pembacaan chlorine — dia masuk hitungan
   ketidakpastian.
6. **Baris STANDARD "Victor 14+" `standard_id: null`** — dirender, tapi jangan
   dikirim balik sebagai id.

Backend: 667 test hijau. Ada yang nggak nyambung, bilang aja.
