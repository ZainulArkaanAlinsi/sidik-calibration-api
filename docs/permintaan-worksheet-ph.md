# Permintaan Backend — Worksheet pH biar Persis Excel

Dari Mobile ke Backend. Acuannya screenshot workbook asli `KALIBRASI pH METER`
(PT. SIDIK, LK-285-IDN, sertifikat `012-CAL-524`), dua halaman.

Tiap poin di bawah udah dicek langsung ke kode `sidik-calibration-api`
(model, migrasi, Resource) — bukan dari dokumentasi. Yang udah ada ditandain
supaya nggak dibangun dua kali.

---

## 0. BLOKER — `titik_ukur` nominal vs terkoreksi suhu

**Ini harus dijawab duluan. Salah di sini bikin angka sertifikat meleset tanpa
error.**

Di worksheet **Halaman 2**, kolom `Standard` yang dilihat & diisi teknisi itu
**nilai nominal buffer**:

```
Standard      3,99 pH        7,00 pH       10,01 pH
Repeat 1      4,04 / 22,2    7,02 / 22,3    9,61 / 22,2
```

Tapi handoff backend ("Kalibrasi pH 100%") minta:

```jsonc
"titik_ukur": 4.009244572   // "nilai standar (buffer) terkoreksi suhu"
```

**3,99 ≠ 4,009244572.** Berarti ada konversi nominal → terkoreksi-suhu (kurva
suhu buffer Merck) yang belum jelas siapa yang ngerjain.

Mobile **nggak boleh** ngitung ini — `Aturan Bisnis Inti.md`: "mobile cuma
menampilkan hasil, tidak menghitung ulang apa pun". Kalau HP ikut ngitung,
angkanya berisiko beda dari sertifikat resmi.

**Yang kami minta diputuskan:**

| Opsi | Yang dikirim mobile | Konsekuensi |
|---|---|---|
| **A (disarankan)** | `titik_ukur` = **3.99** (nominal, apa adanya dari worksheet) + `suhu[]` per baris | Backend yang nurunin nilai terkoreksi dari kurva buffer. Teknisi ngetik persis kayak di Excel. |
| B | `titik_ukur` = 4.009244572 (terkoreksi) | Teknisi harus ngetik angka 10 digit yang di worksheet-nya sendiri nggak kelihatan. Rawan salah ketik. |

> Catatan: commit mobile `4a884b9` **dulu** pakai opsi A (kirim `suhu_larutan`,
> backend nurunin `titik_ukur`). Terus diubah ke opsi B ngikutin handoff. Setelah
> lihat worksheet aslinya, opsi A yang cocok sama kenyataan di lapangan.
> Tolong konfirmasi mana yang bener — mobile ngikut.

**Sampai ini dijawab, form pH belum bisa dipastikan ngirim angka yang benar.**

---

## 1. Yang SUDAH ADA — jangan dibangun ulang

Dicek ke kode, semua ini udah jalan:

| Kebutuhan worksheet | Sumbernya |
|---|---|
| Nama Alat, Merk, Type, No. Seri | `EquipmentResource`: `nama_alat`, `merk`, `model`, `serial_number` |
| Rentang Ukur (`0–14 pH`) | `EquipmentResource.rentang_ukur` (udah diformat siap tempel) |
| Kapasitas Max. | `range_max` |
| Resolusi Alat | `resolusi` |
| Nama Customer | `EquipmentResource.pelanggan.nama` |
| Kondisi lingkungan Awal/Akhir/Average + U95% | `calibration_sessions.suhu_ruang_awal/akhir/...`, dihitung backend |
| Thermohygro Used | `calibration_sessions.thermohygro` |
| Before / After Adjustment 5×(pH+°C) | `measurements[].pembacaan` + `suhu` + `pembacaan_sebelum` + `suhu_sebelum` |
| Standar per titik (buffer 4/7/10) | `measurements[].standard_id` |
| Nomor Order | `calibration_sessions.nomor_order` |

---

## 2. Yang KURANG — per bagian worksheet

### 2.1 Header — `Calibrator Standard Status Monitoring`

Worksheet nampilin banner merah **"ONE OR MORE STANDARD EXPIRED"**, plus badge
per standar di bagian PENGERJAAN:

```
pH Buffer Solution 4      WARNING
pH Buffer Solution 7      VALID
pH Buffer Solution 10     VALID
Termometer & Sensor Std.  WARNING
```

`Standard` udah punya `berlaku_sampai`, tapi **ambang WARNING belum ada di
mana pun**. Mobile nggak mau nentuin sendiri — kalau HP bilang VALID padahal
backend nolak waktu approve, teknisi kerja sia-sia.

**Minta di `StandardResource`:**

```jsonc
"status_kalibrasi": "valid" | "warning" | "expired",
"hari_menuju_kadaluarsa": 23
```

Ambangnya diputuskan backend (mis. `warning` = ≤30 hari), yang penting
**satu sumber**. Mobile cuma nampilin.

**Dan di response sesi**, ringkasan buat banner header:

```jsonc
"status_standar": {
  "ringkasan": "expired",           // terburuk dari semua standar sesi ini
  "pesan": "ONE OR MORE STANDARD EXPIRED"
}
```

### 2.2 PENGERJAAN — `Lokasi Kalibrasi` bukan enum

Worksheet: **`Lab. Uji A`** — itu nama ruangan, bukan `lab`/`onsite`.

Sekarang `calibration_sessions.lokasi` = `enum('lab','onsite')`.
Tabel `rooms` **udah ada** (`Room` model, punya `kode` + `nama`), tapi sesi
nggak nyimpen ruangan.

**Minta:** kolom `room_id` (nullable) di `calibration_sessions`, ikut di
request `POST/PUT /calibrations` dan di response. `lokasi` yang lama tetap
dipertahankan (lab/onsite itu info beda: di lab vs di tempat pelanggan).

### 2.3 PENGERJAAN — `Technician ID`, `Calculated by`, `Signed by`

Worksheet punya **tiga orang berbeda**:

| Field | Contoh | Sekarang |
|---|---|---|
| Technician ID | `DR` | ada (`teknisi_id`), tapi ditampilkan sebagai inisial/kode |
| Calculated by | `NR` | **belum ada** |
| Signed by | `Alex Misr…` | **belum ada** |

**Minta:** `calculated_by` & `signed_by` (FK ke `users`, nullable) di
`calibration_sessions` atau `certificates` — backend yang mutusin paling pas di
mana. Plus field pendek buat inisial di `UserResource` (mis. `inisial: "DR"`),
karena worksheet nampilin kode, bukan nama panjang.

### 2.4 `Certificate Number` muncul di Halaman 1

Worksheet nampilin `012-CAL-524` **waktu input**, padahal sertifikat baru
dibikin setelah approve (`GenerateCertificate::nomorBerikutnya()`).

**Perlu diputuskan:** nomor sertifikat di-reserve sejak sesi dibuat, atau kolom
itu memang kosong sampai terbit? Mobile ngikut — cuma jangan sampai nampilin
nomor yang belum tentu jadi.

### 2.5 `Issuance Date`

Tanggal terbit sertifikat (30 May 2024) beda dari `tanggal_kalibrasi`
(26 May 2024). Belum ada field-nya. **Minta:** `tanggal_terbit` di
`certificates`.

### 2.6 Alamat Customer

`EquipmentResource.pelanggan` cuma bawa `id` + `nama`. Worksheet nampilin
alamat lengkap.

**Minta:** tambahin `alamat` di objek `pelanggan` (datanya udah ada di
`customers.alamat`, cuma belum ikut dikirim).

---

## 3. Kamera / OCR — cara pakainya beda dari yang diasumsikan

Alur yang diminta: **foto → semua kolom keisi otomatis → kalau ada yang kurang,
foto lagi, TANPA menimpa yang udah bener.**

Jadi OCR itu **menambal**, bukan mengganti. Foto kedua cuma ngisi kolom yang
masih kosong / yang teknisi tandain buat diulang.

Ini kerjaan device (OCR on-device, backend nggak ngejalanin OCR — sesuai
handoff §7), tapi ada dua hal yang perlu backend:

1. **Satu sesi boleh punya banyak foto.** Sekarang `photo_path` nempel per
   pembacaan. Kalau satu worksheet difoto 3 kali, ketiganya harus kesimpen
   sebagai jejak audit — bukan cuma yang terakhir.
   **Minta:** endpoint `POST /calibrations/{id}/photos` yang balikin daftar
   foto sesi, atau konfirmasi kalau `measurements[].ocr[].photo_path` yang
   sekarang emang udah cukup.

2. **`input_method` per sesi jadi kurang akurat** kalau satu sesi campur manual
   + OCR. **Minta:** `input_method` per baris pembacaan (`raw_measurements`)
   dipertahankan/ditampilkan, biar auditor tahu angka mana yang dari foto.

---

## 4. Perhitungan tampil saat input

Worksheet ngitung `Average`, `Correction`, `STDEV`, `MAX STDEV` langsung sambil
diisi. Sekarang: mobile kirim → backend hitung → hasil baru kelihatan setelah
submit.

**Minta:** `POST /calibrations/preview` — body sama persis kayak
`POST /calibrations`, tapi **nggak nyimpen apa-apa**, cuma balikin hasil
hitungan (`titik[]`, `titik_sebelum[]`, `kondisi_lingkungan`).

Dengan ini angkanya tetap 100% dari backend (nggak ada risiko beda sama
sertifikat), tapi teknisi lihat hasilnya sambil ngetik.

---

## 5. Prioritas

| # | Item | Ngeblok | Ukuran |
|---|---|---|---|
| 0 | **Keputusan `titik_ukur` nominal vs terkoreksi** | Seluruh angka sertifikat | Cuma keputusan |
| 1 | `status_kalibrasi` di StandardResource + ringkasan sesi | Banner + badge Halaman 1 | Kecil |
| 2 | `alamat` di `pelanggan` | Identitas Customer | Sangat kecil |
| 3 | `POST /calibrations/preview` | Perhitungan sambil ngetik | Sedang |
| 4 | `room_id` di sesi | Lokasi "Lab. Uji A" | Kecil |
| 5 | `calculated_by` / `signed_by` / `inisial` | Blok Approval Halaman 2 | Sedang |
| 6 | `tanggal_terbit` di certificates | Issuance Date | Kecil |
| 7 | Nomor sertifikat saat input | Header Halaman 1 | Perlu keputusan |
| 8 | Banyak foto per sesi | Alur OCR bertahap | Sedang |

Nomor 1 & 2 bisa dirilis sendirian kapan aja — mobile aman nerima response
tanpa field itu (parser-nya nganggep field hilang sebagai null/0).

---

*Disusun 22 Juli 2026 · dicek langsung ke `app/Models`, `app/Http/Resources`,
dan `database/migrations` di branch `feat/kalibrasi-ph-lengkap-dan-arsip`.*
