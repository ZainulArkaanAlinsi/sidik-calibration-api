# Rencana Pengerjaan Frontend — Sidik Calibration

Backend-nya sudah selesai & teruji (268 test hijau). Dokumen ini merangkum apa
yang tinggal dikerjakan di sisi tampilan, lengkap dengan endpoint yang dipanggil
dan bentuk datanya.

Acuan isi layar:
- Lembar kerja teknisi → `SIDIK-FM-CAL-0509_Rev.4 - LEMBAR KERJA pH METER.pdf`
- Lembar perhitungan admin → `Master Olah Data_pH for trial_CSV/PERHITUNGAN.csv`
- Sertifikat → `Spesifikasi-Aplikasi-Kalibrasi.md` poin 9

---

## 0. Aturan besar yang harus dipegang

1. **Tampilan teknisi ≠ tampilan admin.** Bukan cuma beda hak akses — beda
   layar, beda kolom. Teknisi lihat lembar kerja apa adanya; admin lihat lembar
   perhitungan + kolom administratif.
2. **Nggak ada kolom wajib di lembar kerja teknisi.** Tombol kirim SELALU aktif.
   Kolom yang belum keisi tetap lolos. Backend nggak akan nolak.
3. **Teknisi banyak, admin satu pintu.** Semua kiriman dari semua akun teknisi
   masuk ke antrean yang sama di panel admin (`GET /api/calibrations?status=menunggu_approval`).
4. **Angka jangan dihitung ulang di frontend.** Semua sudah dihitung backend.
   Kalau frontend ikut menghitung, cepat atau lambat angkanya beda dari
   sertifikat.

---

## 1. Aplikasi Teknisi (mobile)

### 1.1 Layar input — Lembar Kerja pH Meter

Susunannya **persis** lembar kerja kertas. Jangan tambah kolom, jangan kurangi.

Ambil bentuk formulirnya dari backend supaya nggak ada tebak-tebakan:

```
GET /api/calibrations/lembar-kerja
```

Balikannya sudah menyesuaikan role — kalau yang login teknisi, kolom
administratif (Thermohygro used, Calibration Methode, Order Number) **tidak ikut
terkirim** sama sekali. Isinya: daftar `bagian` → `field` (kode, label, tipe,
satuan, sumber) dan `tabel` untuk bagian hasil.

Susunan layarnya:

| Bagian | Isi |
|---|---|
| EQUIPMENT IDENTITY AND CUSTOMER DATA | Received Date, Calibration Date, pilih alat → Name / Range-Resolution / Type-Model / Serial Number / Merk terisi **otomatis** (read-only) |
| OWNER | Name & Address, terisi otomatis dari alat yang dipilih (read-only) |
| STANDARD CALIBRATION DATA | Location (In lab / Insitu) + pilih ruangan, Env. Condition **First** (°C, %RH) dan **End** (°C, %RH) |
| Standard Name / Usage Check | Daftar standar dari `GET /api/standards`, tiap baris ada centang "dipakai" + kolom keterangan |
| CALIBRATION RESULT | **Dua tabel**: Before adjustment & After adjustment. Baris = larutan standar (4,00 / 7,00 / 10,01), kolom = Repeat 1–5, tiap sel isi **dua angka: pH dan °C** |
| Catatan & tanda tangan | Catatan bebas; "Calibrated by" terisi otomatis dari akun yang login; "Checked by" kosong (diisi sistem saat admin menyetujui) |

Kirim dengan:

```
POST /api/calibrations          (baru)
PUT  /api/calibrations/{id}     (lanjut draft / perbaiki yang dikembalikan admin)
```

Bentuk payload:

```jsonc
{
  "equipment_id": 12,
  "standard_id": 3,                  // boleh null
  "room_id": 2,                      // boleh null
  "tanggal_kalibrasi": "2024-05-26", // wajib hanya saat dikirim ke admin
  "tanggal_terima": "2024-05-26",
  "suhu_awal": 21.3,  "suhu_akhir": 21.5,
  "kelembaban_awal": 53, "kelembaban_akhir": 56,
  "catatan_teknisi": "Buffer 7 habis, dilanjut besok.",
  "status": "menunggu_approval",     // atau "draft"
  "standar_dicek": [
    { "standard_id": 3, "dipakai": true },
    { "standard_id": 9, "dipakai": false, "keterangan": "Sensor dibawa tim lain" }
  ],
  "measurements": [
    {
      "titik_ukur": 4.00,
      "standard_id": 3,
      "pembacaan_sebelum": [4.04, 4.04, 4.04, 5.00, 4.04],
      "suhu_sebelum":      [22.2, 22.2, 22.2, 22.2, 22.2],
      "pembacaan":         [4.00, 4.00, 4.00, 4.00, 4.00],
      "suhu":              [22.2, 22.2, 22.1, 22.2, 22.2]
    }
  ]
}
```

**Sel kosong dikirim sebagai `null`**, jangan dibuang — supaya nomor Repeat-nya
nggak geser. Backend yang menyaring.

Yang perlu dipahami frontend:
- Titik yang pembacaannya kurang dari 2 tetap tersimpan, cuma tidak dihitung.
  Itu normal, bukan error.
- `status: "draft"` → tersimpan, belum masuk antrean admin, tanggal boleh kosong.
- Kirim ulang `PUT` **tanpa** kunci `measurements` = hanya memperbarui bagian
  header; data pengukuran yang sudah ada tidak terhapus.
- Sertakan `client_request_id` (UUID, dibuat sekali per submit) supaya submit
  yang di-retry saat sinyal putus tidak membuat sesi ganda.

### 1.2 OCR (spesifikasi poin 2)

Alurnya: foto → `POST /api/calibrations/photos` (multipart, field `photo`) →
dapat `photo_path` → sertakan di `measurements[].ocr[]` sejajar per-index dengan
`pembacaan`.

Yang bikin OCR sering error selama ini di sisi aplikasi, jadi perbaikannya di
frontend: kompres/resize foto sebelum kirim (maks 8 MB), tangani izin kamera yang
ditolak, dan jangan blokir UI selama upload. Angka hasil OCR **wajib**
dikonfirmasi manusia lewat `POST /api/calibrations/{id}/measurements/verify`
sebelum admin bisa menyetujui.

### 1.3 Notifikasi (poin 4 & 6)

Ikon di **atas** layar dengan badge angka, buka **halaman sendiri**.

```
GET    /api/notifications?belum_dibaca=1
GET    /api/notifications/unread-count       → badge
POST   /api/notifications/{id}/read
POST   /api/notifications/read-all
DELETE /api/notifications/{id}
```

Tiap notifikasi punya `kategori` dan `tautan` (`{tipe, id}`) — pakai itu untuk
menentukan layar tujuan saat diketuk. Kategori yang ada: `jatuh_tempo`,
`sesi_menunggu_approval`, `sesi_disetujui`, `sesi_perlu_revisi`,
`sertifikat_terbit`.

### 1.4 Folder Manager (poin 3 & 7)

Menggantikan "Notifikasi" di navbar bawah. Folder terbentuk otomatis
(`PT / tahun`), jadi frontend cukup menampilkan & menelusuri.

```
GET    /api/folders                      → folder akar (daftar PT)
GET    /api/folders?parent_id=5          → isi folder
GET    /api/folders/{id}                 → sub-folder + file di dalamnya
GET    /api/folder-files?folder_id=5
GET    /api/folder-files/{id}/download
```

Field `tipe` = `sistem` → sembunyikan tombol rename/hapus (backend menolaknya).
Tulis (buat/ubah/hapus folder & unggah file) **admin saja**.

Di halaman **Riwayat**, bagian "Folder" dihapus (poin 7).

### 1.5 Navigasi (poin 5 & 8)

- Tombol back di setiap halaman.
- Navbar bawah hanya menu utama: Beranda, Kalibrasi, Riwayat, Folder Manager.
- Notifikasi **tidak** di navbar bawah lagi.

---

## 2. Admin Panel (web/desktop + mobile)

### 2.1 Antrean masuk

Semua kiriman dari semua teknisi:

```
GET /api/calibrations?status=menunggu_approval
```

Admin juga bisa **mengisi/melengkapi sendiri** lewat endpoint yang sama dengan
teknisi (`POST`/`PUT /api/calibrations`) — bedanya form admin memuat kolom
administratif juga (`GET /api/calibrations/lembar-kerja` saat login admin
mengembalikan bagian tambahan "Data Administratif").

### 2.2 Lembar PERHITUNGAN — layar utama admin

```
GET /api/calibrations/{id}/perhitungan
```

Tampilkan persis seperti sheet PERHITUNGAN:

| Blok | Isi |
|---|---|
| IDENTITAS ALAT | Nama Alat, Merk, Type, No. Seri, Rentang Ukur, Kapasitas Max., Resolusi |
| IDENTITAS CUSTOMER | Nama, Alamat, Tanggal Terima, Tanggal Kalibrasi |
| PERHITUNGAN KONDISI LINGKUNGAN | Baris Suhu Ruangan & Kelembaban dengan kolom: Awal, Akhir, Average, Indexed Value, Correction (C), Δ, C, U95% Std TH, U95% Sertifikat. Plus "Thermohygro Used" |
| DATA HASIL KALIBRASI | Dua tabel (Before / After adjustment). Header = nilai Standard per titik, isi = Repeat 1–5 (pH & °C), penutup = baris **Average**, **Correction**, **STDEV**, dan **MAX STDEV** |

Semua angka sudah jadi di responsnya — tinggal ditampilkan. Catatan penting:

- `standard` per titik **bukan** nilai nominal (4,00) tapi nilai buffer pada suhu
  larutan saat itu (4,0092252 di 22,2 °C). Sudah dihitung backend dari persamaan
  di sertifikat buffer.
- `correction` di layar ini = Average − Standard. Di **sertifikat** tandanya
  kebalikan (Standard − Average). Jangan dipakai silang.

### 2.3 Kolom administratif

```
PATCH /api/calibrations/{id}/admin
```

Isi: `nomor_order`, `calibration_method_id`, `thermohygro_standard_id`,
`room_id`, `tanggal_terima`. Begitu thermohygro dipilih, koreksi & U95% kondisi
lingkungan langsung ikut terhitung.

### 2.4 Periksa & setujui

```
GET  /api/calibrations/{id}/validasi     → tombol "Periksa"
POST /api/calibrations/{id}/approve
POST /api/calibrations/{id}/reject       (body: catatan_revisi)
```

Tampilkan temuan berdasarkan `tingkat`:

| Tingkat | Tampilan | Perilaku |
|---|---|---|
| `error` | merah | Approve diblokir, tidak bisa dilewati |
| `peringatan` | kuning | Approve ditolak sekali dengan `butuh_konfirmasi: true` → tampilkan dialog "lanjut?" → kirim ulang dengan `abaikan_peringatan: true` |
| `info` | abu | Sekadar pemberitahuan kolom kosong |

### 2.5 Sertifikat

```
GET /api/certificates
GET /api/certificates/{id}                 → isi lengkap (snapshot) untuk pratinjau
GET /api/certificates/{id}/download        → PDF
GET /api/certificates/{id}/excel           → Excel
GET /api/certificates/{id}/qr              → gambar QR (PNG)
GET /api/certificates/export/excel?bulan=2026-07&customer_id=3   → rekap
POST /api/certificates/{id}/retry          → ulang kalau gagal
```

Layar pratinjau tinggal me-render `snapshot` (header 16 field, tabel hasil 4
kolom, 2 catatan baku, tabel Standard Used, footer). **Jangan tambah field lain.**

### 2.6 Import Excel (poin 12C)

```
GET  /api/imports/format                → daftar kolom yang dikenali
POST /api/imports/excel                 → multipart: file, tipe, uji_coba
```

Alurnya dua langkah: unggah dengan `uji_coba` (default) → tampilkan ringkasan
(dibuat / diperbarui / dilewati + alasan per baris) → kalau cocok, kirim ulang
dengan `uji_coba: false`. Urutan import: **customers → standards → equipments**
(alat butuh PT-nya sudah ada).

### 2.7 Master data

`customers`, `equipments`, `standards`, `rooms`, `technicians`,
`calibration-methods` — semuanya CRUD standar, admin saja.

Untuk `standards` ada dua field baru yang perlu form-nya:
- `koefisien_suhu` `{a, b, c}` — persamaan suhu buffer dari sertifikat Merck
- `parameter_kondisi` `{suhu: {indexed_value, correction, u95}, kelembaban: {...}}`
  — data sertifikat thermohygro

Dua-duanya yang bikin lembar perhitungan bisa jalan otomatis. Panel Filament di
`/admin` sudah menyediakan sisanya (Metode Kalibrasi, Pengaturan Organisasi
termasuk penandatangan & kode dokumen sertifikat).

---

## 3. Urutan pengerjaan yang disarankan

1. Lembar kerja teknisi + kirim (paling dipakai sehari-hari, dan paling menentukan
   apakah datanya masuk sama sekali)
2. Notifikasi di atas + halaman notifikasi, tombol back, rapikan navbar
3. Antrean admin + lembar perhitungan + periksa/setujui
4. Pratinjau sertifikat + unduh PDF/Excel + QR
5. Folder Manager
6. Perbaikan OCR
7. Import Excel & master data lanjutan

---

## 4. Yang sudah pasti disediakan backend

- Semua endpoint di atas sudah jalan & tertutup test.
- Perhitungan lembar olah data sudah dicocokkan angka per angka dengan
  `Master Olah Data_pH for trial.xlsm` milik lab (lihat `tests/Feature/PerhitunganTest.php`).
- Sertifikat dibekukan saat terbit, jadi PDF, Excel, halaman verifikasi QR, dan
  API mustahil beda isi.
