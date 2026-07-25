# Permintaan Backend — Fase 2 (Peran, Arsip, Sertifikat Lengkap, Laporan)

> ⚠️ **Baca [`BACA-DULU-BACKEND.md`](BACA-DULU-BACKEND.md) dulu.** Sebagian isi
> dokumen ini udah dikerjain backend duluan; halaman itu yang nandain mana yang
> masih berlaku.

Lanjutan dari [`permintaan-endpoint.md`](permintaan-endpoint.md). Dokumen itu
nutup celah Fase 1 (Dashboard, Master Data, Order, Input Pengukuran); nomor 1–4
di sana **udah kelar**, nomor 5 masih terbuka.

Dokumen ini nurunin sisa spec — bagian yang sampai sekarang belum bisa digarap
mobile karena datanya/endpointnya belum ada.

Formatnya sama: cuma yang **bener-bener ngeblok**, diurutin dari yang paling
murah, dan tiap permintaan dikasih alasan kenapa mobile nggak bisa nambal
sendiri.

*Disusun 22 Juli 2026 · dicek langsung ke kode mobile di branch
`feat/dashboard-redesign`, bukan dari dokumen lama.*

---

## 0. Koreksi dulu: peta fitur yang beredar udah basi

Ada "Peta Fitur: Spec vs Kode" yang dibaca dari branch `develop`. **Lima
barisnya udah nggak akurat** — barang-barang ini sekarang ADA di mobile, jangan
dikerjain ulang dan jangan dianggap blocker:

| Baris di peta lama | Status sebenarnya |
|---|---|
| Grafik pekerjaan — *BELUM, belum ada charting sama sekali* | ✅ **ADA** — `lib/widgets/work_chart.dart`, batang masuk vs selesai 6 bulan, digambar manual tanpa paket charting |
| Data Teknisi — *BELUM, tidak ada layar maupun service* | ✅ **ADA** — `technician_list_screen.dart` + `user_service.dart` (approve/tolak/reset password) |
| Kalibrasi selesai — *SEBAGIAN* | ✅ **ADA** — `kalibrasi_selesai` udah dikirim & dipajang |
| Rentang Ukur — *BELUM, tidak ketemu di model Equipment* | ✅ **ADA** — `range_min`/`range_max`/`satuan`/`resolusi` di `Equipment` |
| Order Kalibrasi — *belum ada layar Order tersendiri* | 🔸 **SEBAGIAN** — `my_tasks_screen.dart` + `order_service.dart` udah jalan buat antrean teknisi |

Yang **masih beneran kosong** dari peta itu: Data Ruangan (layar mobile),
Laporan (seluruhnya), dan pelengkap sertifikat (logo, QR, TTD, print, email).

> **Data Ruangan bukan permintaan backend.** `GET/POST/PUT/DELETE /api/rooms`
> udah ada (`kontrak-api.md` §9). Yang belum itu layarnya di mobile — itu
> pekerjaan kami, bukan backend.

---

## 1. Matriks peran — **perlu dikonfirmasi, bukan ditebak**

Mobile sekarang cuma kenal tiga role (`UserRole`: `admin`, `teknisi`,
`viewer`) dan dua turunan: `isAdmin` dan `bisaInput` (admin+teknisi).

Masalahnya, aturan siapa boleh apa sekarang **ditebak dari respons 403 yang
kejadian di lapangan**, bukan dari daftar resmi. Itu yang bikin bug kemarin:
form Tambah Alat mulus waktu dites pakai admin, lalu mentok di akun teknisi
karena dropdown pelanggannya narik `GET /customers` yang ternyata admin-only.

**Yang diminta:** satu daftar resmi — endpoint × role × boleh/nggak. Nggak
harus endpoint baru; file markdown di repo backend pun cukup, asal jadi satu
sumber kebenaran.

Kalau mau lebih rapi dan sekali kerja buat seterusnya, lebih bagus lagi:

```
GET /api/me/permissions
```

```json
{
  "data": {
    "role": "teknisi",
    "boleh": [
      "alat.lihat", "alat.tambah", "alat.ubah",
      "kalibrasi.buat", "kalibrasi.ubah-miliknya",
      "arsip.lihat", "sertifikat.lihat"
    ]
  }
}
```

Gunanya: mobile bisa **nyembunyiin tombol yang bakal ditolak**, bukan nampilin
tombol lalu user kena error 403. Sekarang aturannya di-hardcode di mobile
(`role.bisaInput`), dan tiap backend ganti aturan, mobile ikut basi diam-diam.

**Pertanyaan yang perlu dijawab backend:**
1. Apakah `viewer` boleh lihat arsip & sertifikat pelanggan? (mobile sekarang
   nganggep boleh baca semua, nggak boleh nulis)
2. Apakah teknisi boleh lihat sesi kalibrasi milik teknisi LAIN? (mobile
   sekarang nganggep tidak)
3. Apakah ada rencana role keempat (mis. Manajer Teknis yang tanda tangan
   sertifikat)? Ini nyangkut permintaan §3 di bawah.

---

## 2. Semua yang butuh keputusan admin harus **nyampe** ke admin

Permintaan Zainul: *"kalo ada sesuatu dan yang dibutuhkan sama admin maka
semuanya dikirim ke bagian admin."*

Sekarang mobile udah punya `notification_service.dart`, tapi yang masuk ke situ
belum mencakup kejadian yang butuh tindakan admin. Akibatnya admin harus
buka-buka layar sendiri buat tahu ada yang nunggu.

**Yang diminta — notifikasi (atau minimal penghitung) buat kejadian ini:**

| Kejadian | Yang perlu tahu | Kenapa penting |
|---|---|---|
| Sesi masuk antrean approval | admin | Ini inti alurnya. Sekarang admin nggak dikabarin sama sekali |
| Akun baru daftar & nunggu disetujui | admin | User kejebak di layar "belum disetujui" tanpa batas waktu |
| Sertifikat gagal digenerate | admin | Sekarang cuma ketahuan kalau ada yang iseng buka layar sertifikatnya |
| Alat lewat jatuh tempo | admin + teknisi pemegang | Temuan audit kalau kelewat |
| Sesi ditolak / minta revisi | teknisi pembuat | Teknisi nggak tahu kerjaannya dibalikin |
| Standar acuan mau kadaluarsa (H-30) | admin | Sertifikat standar kadaluarsa = ketertelusuran putus, sesi ditolak |

Bentuk minimal yang mobile butuh:

```
GET /api/notifications?belum_dibaca=1
POST /api/notifications/{id}/baca
```

```json
{ "data": [ {
  "id": 12,
  "tipe": "kalibrasi.menunggu_approval",
  "judul": "Sesi KAL/2026/07/0012 menunggu persetujuan",
  "isi": "pH Meter Mettler Toledo · teknisi Dwi Rahayu",
  "tautan": { "jenis": "kalibrasi", "id": 12 },
  "dibaca_pada": null,
  "created_at": "2026-07-22T09:10:00Z"
} ] }
```

`tautan` yang bikin notifikasi berguna: mobile bisa langsung buka layar yang
dimaksud, bukan cuma nampilin teks lalu user nyari sendiri.

> Kalau push notification (FCM) belum mau digarap, **polling aja dulu cukup** —
> mobile bisa refresh waktu app dibuka. Yang penting datanya ada.

---

## 3. Pelengkap sertifikat — logo, QR, tanda tangan

Spec bagian 07 minta Logo Laboratorium, QR Code, Tanda Tangan, dan nama Manajer
Teknis di sertifikat. Semuanya belum ada.

Catatan penting: **PDF sertifikat digenerate backend**, jadi yang nempel di
kertas itu urusan backend. Mobile cuma butuh datanya buat layar pencocokan
sebelum approve.

### 3a. Logo lab di Pengaturan Organisasi — ✅ **UDAH JADI (25 Jul)**

`GET/PUT /api/organization` belum punya field logo.

```json
"logo_url": "https://.../storage/logo-pt-sidik.png"
```

Plus cara ngunggahnya (`POST /api/organization/logo`, multipart). Mobile udah
punya alur unggah gambar (dipakai foto profil), jadi tinggal diarahkan.

> ### ✅ Jadi — plus satu batasan yang perlu dipegang
>
> `logo_url` ada di `GET/PUT /api/organization`; unggahnya `POST /api/organization/logo`
> (field `logo`, admin doang), hapusnya `DELETE`. Kontrak: [`kontrak-api.md` §8](kontrak-api.md).
>
> - **PNG atau JPG doang.** WEBP ditolak `422` — dompdf nggak bisa render WEBP,
>   dan mime di PDF ditebak dari ekstensi, jadi WEBP bakal dilabeli JPEG dan kop
>   sertifikatnya rusak tanpa error.
> - Ternyata sisi PDF-nya **udah jalan dari sebelumnya**: `GenerateCertificate`
>   udah baca `logo_path` dari disk publik. Yang kurang cuma pintu unggahnya.

### 3b. QR verifikasi

`kontrak-api.md` §5 udah nyebut `GET /verify/{qr_token}` (halaman web) dan
`GET /api/verify/{qr_token}` (JSON) — tapi **`qr_token`-nya sendiri nggak ikut
di respons sesi/sertifikat**, jadi mobile nggak bisa nampilin QR-nya.

Minta ditambah di objek `sertifikat`:

```json
"qr_token": "a1b2c3d4...",
"qr_url": "https://sidik.example/verify/a1b2c3d4"
```

Mobile yang render QR-nya di layar (nggak perlu backend kirim gambar).

### 3c. Tanda tangan & Manajer Teknis

Sertifikat contoh ditandatangani **Alex Misramto, Technical Manager**. Sekarang
nggak ada tempat buat nyimpen itu.

Ini nyambung ke pertanyaan role di §1: apakah "Manajer Teknis" itu role
keempat, atau atribut di data teknisi? Mobile ngikut, tapi perlu diputuskan
dulu sebelum layarnya dibikin.

Minimal yang dibutuhin:

```json
"penanda_tangan": { "nama": "Alex Misramto", "jabatan": "Technical Manager",
                    "ttd_url": "https://.../ttd-alex.png" }
```

### 3d. Print & Kirim Email

- **Print** — nggak perlu endpoint. Mobile bisa nge-print PDF yang udah ada
  lewat dialog cetak Android. Ini pekerjaan kami.
- **Kirim email ke pelanggan** — ini butuh backend:

```
POST /api/certificates/{id}/kirim-email
{ "ke": ["pic@pelanggan.co.id"], "cc": [] }
```

Alasan ditaruh di backend, bukan mobile: alamat pengirim harus domain lab
(bukan Gmail teknisi), dan pengirimannya perlu tercatat buat audit — siapa
ngirim sertifikat ke siapa, kapan.

---

## 4. Arsip: bikin & hapus folder/file — 🔸 **SEBAGIAN**, bukan selesai

> ## ⛔ KOREKSI 25 Juli 2026 — bagian ini sempat salah, jangan dipercaya versi lamanya
>
> Bagian ini dulu ditulis **"✅ UDAH DIBIKIN, JANGAN DIULANG"** dan daftarin 7
> endpoint. Dicek ke `routes/api.php` repo ini: **3 dari 7 nggak ada.** Daftarnya
> kelihatannya nyalin dari repo `asmo-api`, bukan dari sini.
>
> Efeknya bahaya justru karena nadanya "jangan dikerjain": yang hilang itu
> termasuk **`GET /arsip/perusahaan/{customerId}/folder`**, yang paling ngeblok —
> tanpa itu tap PT di Folder Manager gagal. Jadi kalimat "jangan diulang" bikin
> orang nge-skip kerjaan yang justru wajib.
>
> Yang bener: **`permintaan-backend-2026-07-24.md` §2b**, dan status
> terverifikasinya di **`BACA-DULU-BACKEND.md` §3**.
>
> `tests/Feature/FolderArsipTest.php` yang disebut juga nggak ada. Yang ada
> `FolderManagerTest.php` + `FolderManagerArsipAliasTest.php`.

Status per endpoint, dicek ke `routes/api.php` 25 Juli 2026:

```
✅ GET    /api/arsip/perusahaan            (index; daftar folder akar per-PT)
✅ GET    /api/arsip/folders/{folder}
✅ PUT    /api/arsip/folders/{folder}      (rename doang — parent_id dipertahankan)
✅ DELETE /api/arsip/folders/{folder}
✅ POST   /api/folders                     (bukan /arsip/folders)

✅ GET    /api/arsip/perusahaan/{customer}/folder   ← find-or-create folder akar (jadi 25 Jul)
❌ PUT    /api/arsip/folders/{folder}/pindah        ← sekunder
❌ PUT    /api/arsip/berkas/{calibration}/pindah    ← sekunder
```

Lima pertanyaan yang dulu ditulis di sini dijawab lewat
`asmo-api/HANDOFF-FOLDER-ARSIP.md` — **file itu nggak ada di repo ini**, dan dua
jawabannya nggak cocok sama kode di sini. Versi terverifikasi (25 Juli 2026):

| Pertanyaan | Jawaban di repo INI | Sumber |
|---|---|---|
| Siapa boleh nyusun/hapus | ⛔ **Admin doang.** Teknisi & viewer `403` — bukan "admin & teknisi" | `routes/api.php:219-227` ada di dalam blok `role:admin` |
| Folder berisi boleh dihapus | **Nggak** — `422` | `FolderController.php:148-156` |
| "Nggak ada cascade sama sekali" | ⛔ **Salah.** `folder_files.folder_id` itu `cascadeOnDelete`. Praktiknya kejaga karena hapus folder berisi ditolak 422 dulu, tapi klaimnya nggak akurat | `2026_07_23_110500_create_folder_files_table.php:22` |
| Folder akar perusahaan | Nggak bisa di-rename/pindah/hapus | `FolderController.php` |
| `calibration_sessions.folder_id` `nullOnDelete` | ⛔ **Kolomnya nggak ada** di repo ini | `Schema::hasColumn` → false |

> **Yang paling ngefek buat frontend: tombol bikin/rename/hapus folder harus
> disembunyiin dari teknisi**, bukan cuma dari viewer. Kalau ngikutin tabel lama,
> teknisi lihat tombolnya lalu kena `403` — persis bug "jalan di admin, mentok di
> teknisi" yang §1 dokumen ini keluhin.

**Yang tersisa buat backend di bagian ini: 2 operasi**, dua-duanya sekunder
(`/pindah` folder & berkas). Yang core — tap PT buka folder akarnya — **udah jadi
25 Jul**, jadi browse / rename / hapus / tap PT semuanya bisa disambungin sekarang.

---

## 5. Laporan — belum ada apa-apa

Spec bagian 08 minta laporan dengan filter Pelanggan / Tanggal / Teknisi /
Jenis Alat, output PDF & Excel. Di mobile belum ada layar, service, maupun
model — dan nggak bisa dimulai karena endpointnya belum ada.

```
GET /api/laporan/kalibrasi?dari=&sampai=&pelanggan_id=&teknisi_id=&kategori=&page=
```

Balikan JSON berpaginasi (buat ditampilin di layar), plus:

```
GET /api/laporan/kalibrasi/export?format=pdf|xlsx&<filter sama>
```

yang nge-stream file — sama polanya kayak
`GET /certificates/{id}/download` yang udah jalan, jadi mobile bisa pakai
`pdf_downloader.dart` yang sudah ada.

**Catatan:** rekap Excel-nya **jangan** dirakit di HP. Bukan soal susah, tapi
soal angka: kalau HP yang ngerangkum, hasilnya bisa beda tipis dengan yang
keluar dari server (pembulatan, zona waktu) — dan buat lab terakreditasi, dua
laporan dengan angka beda itu temuan.

---

## 6. Ringkasan buat rapat

| # | Permintaan | Ukuran | Ngeblok | Catatan |
|---|---|---|---|---|
| 1 | Matriks peran (dokumen) / `GET /me/permissions` | Kecil–Sedang | Tombol yang muncul tapi ditolak 403 | Minimal dokumen dulu |
| 2 | Notifikasi kejadian yang butuh admin | Sedang | "Semua ke admin" | Polling dulu nggak apa-apa |
| ~~3a~~ | ~~`logo_url` di organisasi~~ | — | — | ✅ **jadi 25 Jul** |
| 3b | `qr_token` di objek sertifikat | **Sangat kecil** | QR verifikasi | Endpoint verify-nya udah ada |
| 3c | Penanda tangan / Manajer Teknis | Sedang | Blok TTD | Perlu keputusan role dulu |
| 3d | `POST /certificates/{id}/kirim-email` | Sedang | Kirim sertifikat ke pelanggan | |
| 4 | CRUD folder & file arsip | Kecil–Sedang | Tap PT di Folder Manager | 🔸 **SEBAGIAN** — 3 operasi masih kurang, lihat §4 (dulu salah ditandain "udah ada") |
| 5 | `GET /laporan/kalibrasi` + export | Besar | Seluruh bagian Laporan | |

**Paling murah & bisa dirilis besok:** 3b (`qr_token`) dan §5 di dokumen
sebelumnya (`nomor_order` + `tanggal_terima`).

**Paling ngeblok mobile:** §1 (matriks peran). Selama itu belum ada, tiap layar
baru berisiko ngulang bug "jalan di admin, mentok di teknisi".

**Jangan dikerjain:** Data Ruangan (backend udah ada, tinggal layar mobile),
Import Excel, Backup Database, User Management penuh — alasannya di
`permintaan-endpoint.md` §6.

---

*Semua permintaan di sini sifatnya nambah, nggak ngubah/ngilangin field lama.
Parser mobile nganggep field yang belum ada sebagai `null` dan barisnya nggak
dirender, jadi backend bebas ngerilis satu-satu tanpa nunggu mobile.*
