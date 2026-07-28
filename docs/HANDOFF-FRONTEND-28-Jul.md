# Handoff Frontend — status API per 28 Juli 2026

Dokumen ini buat tim frontend/mobile yang mau ngeksekusi desain. Isinya tiga hal:
**apa yang udah siap dipakai**, **apa yang belum ada (jangan dibangun dulu)**, dan
**apa yang berubah dan bakal ngerusak layar lama**.

Semua yang ditulis di sini dicek langsung ke `routes/api.php` di branch `main`
(115 rute terdaftar), bukan dari dokumen lama. Kontrak detail per endpoint ada di
[`kontrak-api.md`](kontrak-api.md) — dokumen ini peta, bukan pengganti.

---

## ⚠️ 1. Baca ini duluan — dua hal yang ngerusak layar lama

### 1a. Semua field tanggal sekarang TANGGAL POLOS, bukan ISO ber-zona waktu

**Ini breaking change.** Dulu:

```json
"diterbitkan_pada": "2024-05-29T17:00:00Z"
```

Sekarang:

```json
"diterbitkan_pada": "2024-05-30"
```

Kenapa diubah: kolomnya bertipe `date` (nggak ada jamnya). Diserialisasi ke UTC dari
zona Jakarta bikin **30 Mei keluar jadi 29 Mei jam 17:00** — mundur sehari. Parser
yang ngambil 10 karakter pertama dapat tanggal salah, dan di sertifikat itu cacat
dokumen terkendali, bukan bug tampilan.

**Yang perlu dicek di sisi frontend:** semua `DateTime.parse()` yang dipasang ke field
tanggal. Sekarang formatnya `yyyy-MM-dd` polos — kalau ada kode yang ngarepin `Z` di
belakang atau motong string di indeks tertentu, itu perlu disesuaikan.

Field yang kena: `diterbitkan_pada`, `berlaku_sampai`, `akreditasi_mulai`,
`akreditasi_berakhir`, `tanggal_kalibrasi`, `tanggal_terima`.

### 1b. Masa berlaku sertifikat nggak lagi dipaku 1 tahun

Dulu hard-coded 12 bulan. Sekarang admin yang nentuin — default per organisasi, dan
bisa ditimpa per sertifikat waktu approve. **Jangan hitung `berlaku_sampai` sendiri di
frontend** dengan nambah 1 tahun ke tanggal kalibrasi; pakai nilai dari API.

---

## ✅ 2. Siap dipakai — boleh langsung didesain & dibangun

### 2a. Matriks peran — `GET /api/me/permissions`

Satu daftar resmi endpoint × role × boleh/nggak. **Ini yang dipakai buat nyembunyiin
tombol**, jangan hard-code aturan role di frontend lagi.

Respons bawa dua hal yang beda gunanya:

| Field | Jawab pertanyaan |
|---|---|
| `boleh` | "layarnya kebuka apa nggak" |
| `batasan` | "isinya sebanyak apa" (mis. teknisi cuma lihat sesi sendiri) |

Tanpa `batasan`, frontend nggak bisa bedain tombol yang disembunyiin dari layar yang
kebuka tapi datanya lebih sedikit.

Daftarnya **dihitung dari middleware rute yang beneran terdaftar**, bukan ditulis
tangan. Jadi kalau backend ganti aturan, jawabannya ikut berubah di request
berikutnya — nggak ada lagi mobile basi diam-diam.

**Tiga jawaban yang udah pasti:**
1. Viewer boleh baca arsip & sertifikat, nulis nggak sama sekali.
2. Teknisi **nggak boleh** lihat sesi teknisi lain (`404` kalau nebak ID).
3. **Nggak ada role keempat.** Penanda tangan itu atribut, bukan role.

### 2b. Tanda tangan sertifikat — 4 endpoint (BARU, 27 Jul)

| Endpoint | Guna |
|---|---|
| `POST /api/organization/tanda-tangan` | unggah PNG |
| `GET /api/organization/tanda-tangan` | pratinjau gambarnya |
| `DELETE /api/organization/tanda-tangan` | hapus |
| `PATCH /api/organization/tanda-tangan/posisi` | setel posisi & ukuran cetak |

**Admin doang** — teknisi & viewer kena `403` di keempatnya, termasuk pratinjau.

Tiga hal yang bikin salah kalau nggak diperhatiin waktu bikin layarnya:

**1. Nggak ada `ttd_url`, dan nggak akan pernah ada.** Gambarnya di disk privat —
URL tanda tangan yang bisa diakses siapa pun berarti siapa pun bisa nempelin ke
dokumen palsu. Yang dikirim cuma penanda:

```json
"punya_tanda_tangan": true,
"tanda_tangan": { "geser_x_mm": -8.5, "geser_y_mm": 4, "lebar_mm": 42 }
```

Buat nampilin gambarnya di layar: `GET /api/organization/tanda-tangan` nge-stream
PNG-nya. Di Flutter itu **`Image.memory` dari respons `http`, BUKAN
`Image.network`** — `Image.network` nggak bawa header `Authorization`, jadi gagal.

**2. `geser_y_mm` POSITIF = NAIK.** Kebalikan koordinat layar. Kalau bikin UI drag
dan hasilnya kebalik (geser ke atas, TTD-nya turun), yang salah tanda di sisi UI —
backend udah dites buat arah ini.

**3. PNG doang, JPG ditolak.** JPG nggak punya latar transparan, jadi kecetak sebagai
kotak putih yang nutupin garis tanda tangan & nama. Pesan `422`-nya udah nyebut
alasan lengkap — **tampilkan apa adanya**, jangan diganti "format tidak didukung".

**Batas nilai buat slider/input:**

| Field | Rentang | Bawaan |
|---|---|---|
| `geser_x_mm` | −40 … 40 (negatif = kiri) | 0 |
| `geser_y_mm` | −40 … 40 (positif = naik) | 0 |
| `lebar_mm` | 10 … 80 | 35 |

Posisinya disimpen **sekali di tingkat template**, berlaku buat semua sertifikat.
Bukan per sertifikat — sertifikat terbit itu dokumen terkendali dan nggak boleh bisa
diedit. Jadi di UI: satu layar pengaturan, bukan editor per sertifikat.

### 2c. `penanda_tangan` di objek sertifikat (BARU, 28 Jul)

Buat layar pencocokan sertifikat:

```json
"penanda_tangan": { "nama": "Alex Misramto", "jabatan": "Technical Manager" }
```

Ada di `GET /calibrations/{id}` (objek `sertifikat` yang di-embed) dan di respons
sertifikat sendiri.

**Nilainya beku dari snapshot** — ganti penandatangan di pengaturan nggak ngubah
sertifikat lama. Jadi **jangan disamain** sama
`organization.settings.penandatangan_nama` buat sertifikat lama; dua-duanya bener,
cuma beda waktu. `null` kalau belum terbit.

### 2d. Kirim sertifikat ke email pelanggan

`POST /api/certificates/{id}/kirim-email` + `GET /api/certificates/{id}/riwayat-email`.
Admin doang.

Catatan buat desain layarnya:

- **Responsnya sinkron** — admin nunggu sampai email beneran keluar, jadi butuh
  loading state yang jelas. Ini disengaja: kiriman yang di-queue lalu gagal diam-diam
  berarti pelanggan nggak pernah nerima dan nggak ada yang sadar.
- **`502` = gagal kirim, dan percobaannya TETAP tercatat.** Jangan diperlakukan
  sebagai "nggak terjadi apa-apa" — riwayatnya nambah satu baris dengan `error`.
- Riwayat nampilin **semua percobaan, termasuk yang gagal**. Itu justru informasi
  yang dicari waktu pelanggan ngaku nggak nerima.
- `ke` maks 10 alamat, `cc` maks 10. Throttle 20/menit.

> **Status operasional:** kodenya kelar & teruji, tapi `MAIL_*` di `.env` produksi
> belum diisi — sekarang email masuk log, bukan ke internet. Fungsional di layar
> tetap bisa didesain & dites sekarang.

### 2e. Arsip / Folder Manager — KELAR SEMUA

Browse, rename, hapus, tap PT, pindah folder, pindah berkas — semuanya ada.

```
GET    /api/arsip/perusahaan
GET    /api/arsip/perusahaan/{customer}/folder
GET    /api/arsip/folders/{id}
PUT    /api/arsip/folders/{id}
PUT    /api/arsip/folders/{id}/pindah
DELETE /api/arsip/folders/{id}
PUT    /api/arsip/berkas/{sesiId}/pindah
```

**Dua batasan yang WAJIB dicegah di UI, bukan cuma ditangkap sebagai error:**

1. **Folder bertipe `sistem` nggak bisa dipindah** → `422`. Field `tipe` udah ikut di
   respons `/folders`, jadi **jangan bikin folder itu bisa di-drag sama sekali**.
2. **Folder nggak bisa dipindah ke dalam keturunannya sendiri** → `422`. Kalau lolos,
   folder-nya lepas dari pohon dan ilang dari semua layar tanpa error.

Hapus folder dikunci ke admin.

### 2f. Laporan kalibrasi + ekspor

`GET /api/laporan/kalibrasi` + `GET /api/laporan/kalibrasi/export` (PDF & Excel).
Plus `GET /api/certificates/export/excel` buat rekap sertifikat.

### 2g. Preview hitung tanpa nyimpen

`POST /api/calibrations/preview` — hitung sambil ngetik, nggak bikin baris di DB.
Ini yang bikin layar input bisa nampilin hasil real-time.

### 2h. Baca lembar kerja dari foto (AI Vision)

`POST /api/raw-measurements/extract-from-photo` — **ini ganti OCR yang lama**.
Plus `POST /api/calibrations/photos` buat unggah foto.

### 2i. Logo organisasi

`POST/DELETE /api/organization/logo`, dan `logo_url` di objek organisasi.
Beda dari tanda tangan: logo **ADA URL-nya** dan bisa dipakai langsung di
`Image.network`, karena logo itu identitas yang memang dipajang.

### 2j. Lain-lain yang udah live

| Endpoint | Guna |
|---|---|
| `GET /api/customers/lookup` | dropdown pelanggan, **kebuka buat semua role** — ini yang dulu bikin form Tambah Alat mentok di akun teknisi |
| `GET /api/audit-logs` + `/export` | riwayat perubahan data |
| `GET /api/formulas` + `/versions` | rumus berversi |
| `GET /api/calibrations/lembar-kerja` | lembar kerja teknisi |
| `GET /api/calibrations/{id}/perhitungan` | lembar perhitungan admin |
| `POST /api/imports/excel` + `GET /api/imports/format` | import Excel |
| `POST /api/reminders/jatuh-tempo` | pemicu manual pengingat |
| `GET /api/verify/{qr_token}` | halaman verifikasi QR (publik) |

Objek sesi di `GET /calibrations/{id}` juga udah digemukin — `sertifikat`,
`standar_acuan`, `teknisi`, dan `reviewer` sekarang lengkap, jadi layar pencocokan
sertifikat **nggak perlu 3 request tambahan** lagi.

---

## 🚫 3. BELUM ADA — jangan dibangun frontend-nya dulu

Diverifikasi absen dari 115 rute yang terdaftar di `main`:

| Yang diminta | Status | Dampak |
|---|---|---|
| `GET /dashboard/tren` | **PR #21 belum di-merge** | Grafik Dashboard **aman** (pakai `grafik_pekerjaan` yang udah ada). Yang belum bisa cuma grafik rentang tanggal bebas |
| Entitas `/orders` | **belum ada sama sekali** | Nggak ada layar Order tersendiri. Tapi `nomor_order` & `tanggal_terima` **udah ada di objek sesi** — kalau yang dibutuhin cuma nampilin itu, bisa jalan sekarang |
| `calculated_by` / `signed_by` di worksheet | **belum ada** | Blok approval Halaman 2 worksheet. **Beda dari penanda tangan sertifikat** (§2c): yang itu satu orang tingkat lab, yang ini per sesi |
| Evaluator ekspresi rumus | **belum ada** | Ganti versi rumus belum ngubah cara hitung. Pencatatan versinya udah ada, mesin eksekusinya belum |

### Soal notifikasi — setengah tersedia

**Endpoint-nya UDAH ADA** dan bisa dipakai sekarang:

```
GET    /api/notifications
GET    /api/notifications/unread-count
POST   /api/notifications/{id}/read
POST   /api/notifications/read-all
DELETE /api/notifications/{id}
```

**Tapi** 3 kejadian yang bikin notifikasi itu keisi masih di **PR #20 yang belum
di-merge**. Artinya: layar notifikasi boleh dibangun sekarang (bentuk datanya udah
final), cuma jangan kaget kalau isinya masih sepi sampai #20 masuk.

---

## 4. Ringkasan buat perencanaan sprint

**Bisa dikerjain sekarang, nggak nunggu siapa-siapa:**

1. Layar pengaturan tanda tangan (unggah + drag posisi) — §2b
2. Layar kirim email sertifikat + riwayat — §2d
3. Folder Manager lengkap termasuk drag-drop — §2e
4. Layar laporan + tombol ekspor — §2f
5. Perbaikan parsing tanggal di seluruh app — §1a ⚠️ **paling mendesak, ini
   ngerusak yang udah jalan**
6. Ganti aturan role hard-coded pakai `/me/permissions` — §2a
7. Layar notifikasi (shell-nya) — §3

**Tunggu dulu:** grafik tren rentang bebas, layar Order, blok approval worksheet.

---

*Disusun 28 Juli 2026 · dicek langsung ke `routes/api.php` (115 rute) dan test suite
(556 hijau) di branch `main` + `feat/penanda-tangan-objek-sertifikat`.*
