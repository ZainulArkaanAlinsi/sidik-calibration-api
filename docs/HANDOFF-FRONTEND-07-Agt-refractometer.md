# Handoff Frontend — Refractometer (alat ke-4)

Tanggal: 7 Agt 2026 · Backend: **sudah jalan & hijau** (714 test, SQLite + MySQL)

Refractometer nyusul pH Meter, Turbidimeter, dan Chlorine Meter. **Sebagian besar
layar nggak perlu diapa-apain** — bentuk lembar kerjanya datang dari endpoint yang
sama dan strukturnya sama. Yang di bawah ini yang BEDA, dan tiga di antaranya
kalau kelewat bikin angka di layar salah tanpa ada error apa pun.

---

## 0. Ringkas — yang wajib dikerjakan

| # | Hal | Dampak kalau kelewat |
|---|---|---|
| 1 | Kolom **suhu per pembacaan** wajib bisa diisi | Koreksi di sertifikat meleset, diam-diam |
| 2 | Lembar perhitungan nampilin **`average_dikoreksi_suhu`**, bukan `average` | Admin lihat angka yang beda dari sertifikat |
| 3 | Pilihan **satuan n20D / °Brix** ditanya di depan | Semua angka hilirnya salah satuan |
| 4 | Jangan mad angka ke 4 desimal di tabel hasil | `1,33935` kepotong jadi `1,3394` |
| 5 | Kamera: kirim `satuan` + `titik_nominal` | Akurasi baca foto turun jauh |

---

## 1. Ambil bentuk lembar kerja

```
GET /api/calibrations/lembar-kerja?profil=refractometer
GET /api/calibrations/lembar-kerja?profil=refractometer&pengulangan=3
```

Sama persis kayak tiga alat lain (`?profil=` menerima `ph_meter`, `turbidimeter`,
`chlorine_meter`, `refractometer`). Yang beda isinya:

```jsonc
{
  "kode_dokumen": "SIDIK-FM-CAL-0523_Rev.2",
  "judul": "Calibration Worksheet - Refractometer",
  "jumlah_pengulangan": 5,
  "larutan_standar": [1.33659, 1.39986],   // DUA titik, bukan tiga
  "satuan": "n20D",
  "satuan_suhu": "°C",
  "pilihan_satuan": [                       // ← BARU, cuma ada di alat ini
    { "nilai": "n20D",  "label": "n20D (indeks bias)" },
    { "nilai": "°Brix", "label": "°Brix (kadar sukrosa)" }
  ],
  "semua_kolom_opsional": true,
  "bagian": [ /* identitas_alat, pemilik, usage_check, data_kalibrasi, hasil, penutup */ ]
}
```

Aturan lama tetap berlaku: **nggak ada satu pun field `wajib: true`**. Lembar
setengah jadi boleh dikirim dari lapangan; yang nahan penerbitan sertifikat itu
admin, bukan tombol kirim.

---

## 2. ⚠️ Kolom suhu itu BUKAN pelengkap

Di lembar Chlorine, kolom °C cuma dicatat buat jejak. **Di refractometer, kolom
itu yang dipakai ngitung.**

Tabel hasil (`bagian[kode=hasil].tabel[]`), dua tahap `sebelum_adjustment` &
`sesudah_adjustment`, tiap sel punya dua kolom:

```jsonc
"kolom": [
  { "kode": "pembacaan", "label": "n20D", "tipe": "angka", "satuan": "n20D" },
  { "kode": "suhu",      "label": "°C",   "tipe": "angka", "satuan": "°C" }
]
```

Alasannya: indeks bias berubah ikut suhu. Pembacaan dinormalisasi ke **20 °C**
(huruf "20" di `n20D` itu ini) sebelum dibandingin ke larutan standar:

```
Corrected = Observed + 0,00045 × (T − 20)        // n20D
Corrected = Observed + 0,07    × (T − 20)        // °Brix
```

Contoh nyata dari master lab: dibaca `1,3362` pada rata-rata `27 °C` →
`1,3362 + 0,00045 × 7 = 1,33935`. Itu yang kecetak di sertifikat sebagai
**Unit Under Test**, bukan `1,3362`.

**Kalau teknisi ngosongin kolom suhu**, backend pakai pembacaan apa adanya —
nggak nebak. Hasilnya sah tapi kurang teliti, jadi tolong kasih hint halus di
layar ("isi suhu biar pembacaan dinormalisasi ke 20 °C"), **bukan** validasi
yang ngeblokir.

---

## 3. ⚠️ Lembar perhitungan: dua kolom rata-rata

```
GET /api/calibrations/{id}/perhitungan
```

Tiap titik sekarang bawa **dua** angka rata-rata:

| field | isi | dipakai buat |
|---|---|---|
| `average` | Observed Value — rata-rata pembacaan MENTAH | ditampilkan biar teknisi bisa cek salah ketik |
| `average_dikoreksi_suhu` | **Corrected Value** — sudah dinormalisasi ke 20 °C | **ini yang harus dipakai** |
| `average_suhu` | rata-rata suhu larutan titik itu | kolom "T (°C)" |
| `correction` | `average_dikoreksi_suhu − standard` | kolom Correction |

Buat pH/Turbidimeter/Chlorine **dua-duanya isinya sama persis** (profilnya
balikin angka apa adanya), jadi aman dipakai satu komponen buat semua alat —
cukup selalu baca `average_dikoreksi_suhu`.

Tata letak yang niru sheet lab (blok "Temperature Correction"):

```
Standar Value │   T    │ Observed Value │ Corrected Value │ Correction
   1,33659    │  27 °C │     1,3362     │     1,33935     │  -0,00276
   1,39986    │  25 °C │     1,3986     │     1,40085     │  -0,00099
```

`stdev` tetap dihitung dari pembacaan **mentah** — itu memang benar, jangan
"diperbaiki" biar konsisten sama kolom Corrected.

---

## 4. Pilihan satuan n20D / °Brix

Satu refractometer bisa nampilin dua satuan, dan pilihannya **ngubah semua angka
hilirnya**: koefisien suhu (0,00045 vs 0,07), titik standar, dan CMC. Jadi:

- Tanya di depan, di bagian `identitas_alat` (field `equipment.satuan`), sebelum
  teknisi mulai ngisi tabel.
- Pilihan diambil dari `pilihan_satuan` di akar respons — jangan di-hardcode.
- Kirim balik lewat field alat yang biasa.

**Status hari ini: yang jalan penuh baru `n20D`.** Titik °Brix (2,5 & 40) sudah
ada CMC-nya dan tetap bisa dikalibrasi + terbit sertifikat, tapi ketidakpastiannya
dilaporkan pakai CMC laboratorium apa adanya, bukan budget 5 komponen — blok
°Brix di master Excel lab isinya `#REF!`, jadi rumusnya belum bisa diturunkan.
Nggak ada bedanya di API; cuma kalau ada yang nanya kenapa U95 °Brix selalu sama
persis, itu jawabannya.

---

## 5. ⚠️ Pembulatan — jangan mad ke resolusi alat

Baris tabel hasil refractometer **sengaja nggak ngirim `desimal` & `resolusi`
per baris** (beda dari Turbidimeter yang ngirim `[2,1,0]`):

```jsonc
"baris": [
  { "titik_ukur": 1.33659, "label": "1,33659" },
  { "titik_ukur": 1.39986, "label": "1,39986" }
]
```

Di sisi mobile, "nggak ada" = **resolusinya seragam, jangan mad per baris** —
aturan yang sama sudah dipakai Chlorine. Penting di sini karena resolusi alatnya
`0,0001` (4 desimal) tapi nilai terkoreksinya bisa **5 desimal** (`1,33935`).
Mad ke 4 desimal bikin `1,33935` kecetak `1,3394` — beda dari sertifikat.

Sertifikat sendiri kecetak di **4 desimal** (`desimal: 4` di snapshot):

```
Standard Value │ Unit Under Test │ Correction │ U95%, k=2
    1,3366     │     1,3394      │  -0,0028   │   0,0005
    1,3999     │     1,4009      │  -0,0010   │   0,0005
```

---

## 6. Kamera / AI Vision

```
POST /api/raw-measurements/extract-from-photo
```

**Nggak ada endpoint baru dan nggak ada perubahan bentuk.** Jalur kamera sudah
instrument-agnostic sejak awal — satu foto = satu tabel, tiap sel sepasang angka
(pembacaan + °C), sama buat keempat alat. Kunci JSON balikannya masih bernama
`ph` (dipertahankan biar parser lama nggak pecah); isinya pembacaan dalam satuan
alatnya, bukan pH.

Yang **wajib** dikirim biar akurasinya nggak jatuh:

```jsonc
{
  "satuan": "n20D",
  "titik_nominal": [1.33659, 1.39986],   // dari `larutan_standar`
  "jumlah_titik": 2,
  "jumlah_pengulangan": 5
}
```

`titik_nominal` itu yang bikin model nggak salah baca `1,3362` jadi `13362` atau
`1,362`. Angka refractometer punya 4 desimal dan mepet satu sama lain — tanpa
petunjuk nominal, tingkat salah bacanya jauh lebih tinggi daripada pH.

Alur konfirmasinya tetap: hasil AI **nggak** langsung disimpen, teknisi
konfirmasi/koreksi dulu (sel `low` ditandai lewat `meta.perlu_dicek`), baru
submit lewat `POST /calibrations`.

---

## 7. Data contoh buat nyoba

Sesi `2211.11.R` sudah diseed lengkap (`php artisan migrate:fresh --seed`):
Hanna HI 96811 · S/N C12345 · TH-5 · suhu ruang 20,9→21,2 °C · RH 62→60.
Dua titik, dua tahap, angkanya persis master Excel lab.

Sertifikat yang keluar dari sesi ini dijaga `SertifikatCocokMasterTest` —
kalau layar nampilin angka lain dari tabel di §5, yang salah layarnya.

---

## 8. Yang TIDAK berubah

- Endpoint, autentikasi, bentuk `POST /calibrations`, alur approve & terbit.
- Layar pH/Turbidimeter/Chlorine — nol perubahan perilaku (dijaga suite penuh).
- Aturan "semua kolom opsional" & draft setengah jadi.

---

## 9. Catatan buat backend (bukan tugas frontend)

Dua hal yang sudah ketahuan dan sengaja dibiarkan, ditulis di sini biar nggak
ditemukan ulang sebagai "bug frontend":

1. **U95 refractometer disimpan di `decimal(20,8)`** → cuma 4 angka penting
   (`0,00052715` dari `0,0005271534327…`). Nggak ngubah angka cetak (4 desimal)
   maupun PASS/FAIL, tapi kalau lab minta U95 lebih presisi, kolomnya yang mesti
   dilebarin. Detailnya di docblock `SertifikatCocokMasterTest::TOLERANSI_SIMPAN`.
2. **`u_temperature` refractometer beda dari alat lain** (0,0394 vs 0,3612)
   padahal termometer standarnya unit fisik yang sama — ikut master Excel sesuai
   keputusan 6 Agt 2026, masih nunggu konfirmasi lab. Detailnya di docblock
   `RefractometerCapabilitySeeder`.
