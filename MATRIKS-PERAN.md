# Matriks Peran — Endpoint × Role

Jawaban buat *Permintaan Backend Fase 2 §1*. **Ini sumber kebenaran.**

Daftar di bawah **diturunkan langsung dari middleware di `routes/api.php`**, bukan ditulis tangan — jadi nggak ada celah "dokumen bilang A, kode-nya B". Kalau route diubah, dokumen ini harus di-generate ulang.

Role yang ada saat ini cuma **3**: `admin`, `teknisi`, `viewer`.

> Cara paling gampang buat mobile: panggil **`GET /api/me/permissions`** (baru) — balikin daftar ability pemanggil, jadi tombol bisa disembunyiin tanpa hardcode aturan.

---

## Ringkasan

| Kelompok | Jumlah | Siapa |
|---|---|---|
| Publik (tanpa login) | 6 | siapa saja |
| Semua role (login) | 25 | admin, teknisi, viewer |
| Tulis kalibrasi & alat | 12 | admin, teknisi |
| Admin saja | 30 | admin |

---

## 1. Publik — tanpa token

```
GET  /health
GET  /verify/{qr_token}
POST /login
POST /register
POST /forgot-password
POST /reset-password
```

## 2. Semua role yang login (termasuk `viewer`)

```
GET  /me
GET  /me/permissions
GET  /notifications
POST /notifications/{id}/baca
POST /notifications/baca-semua
GET  /dashboard
GET  /dashboard/tren
GET  /categories
GET  /categories/{kode}
GET  /equipments
GET  /equipments/{equipment}
GET  /standards
GET  /standards/{standard}
GET  /rooms
GET  /rooms/{room}
GET  /orders
GET  /orders/{order}
GET  /calibrations
GET  /calibrations/{calibration}
GET  /certificates
GET  /certificates/{certificate}/download
GET  /arsip/perusahaan
GET  /arsip/perusahaan/{customer}
GET  /arsip/perusahaan/{customer}/folder
GET  /arsip/alat/{equipment}
GET  /arsip/folders/{folder}
POST /logout
POST /logout-all
```

## 3. `admin` + `teknisi`

```
POST   /equipments
PUT    /equipments/{equipment}
DELETE /equipments/{equipment}
POST   /calibrations
PUT    /calibrations/{calibration}
POST   /calibrations/photos
POST   /calibrations/{calibration}/measurements/verify
POST   /arsip/folders
PUT    /arsip/folders/{folder}
PUT    /arsip/folders/{folder}/pindah
DELETE /arsip/folders/{folder}
PUT    /arsip/berkas/{calibration}/pindah
```

## 4. `admin` saja

```
POST   /calibrations/{calibration}/approve
POST   /calibrations/{calibration}/reject
POST   /certificates/{certificate}/retry
GET    /organization
PUT    /organization
GET    /customers
GET    /customers/{customer}
POST   /customers
PUT    /customers/{customer}
DELETE /customers/{customer}
POST   /orders
PUT    /orders/{order}
DELETE /orders/{order}
POST   /orders/{order}/penugasan
POST   /rooms
PUT    /rooms/{room}
DELETE /rooms/{room}
POST   /standards
PUT    /standards/{standard}
DELETE /standards/{standard}
GET    /technicians
GET    /technicians/{technician}
POST   /technicians
PUT    /technicians/{technician}
DELETE /technicians/{technician}
GET    /users
PUT    /users/{user}
POST   /users/{user}/approve
POST   /users/{user}/reject
POST   /users/{user}/reset-password
```

---

## ⚠️ Izin memanggil ≠ boleh lihat semua data

Beberapa endpoint kebuka buat semua role, **tapi isinya disaring per-role di controller**. Mobile nggak perlu ikut nyaring — cukup tahu bahwa jumlahnya bisa beda antar akun:

| Endpoint | Penyaringan |
|---|---|
| `GET /calibrations`, `GET /calibrations/{id}` | **teknisi cuma sesi miliknya sendiri**; admin & viewer lihat semua di lab. Tebak-ID nggak tembus (dibalas 404) |
| `GET /certificates`, `.../download` | sama — teknisi cuma dari sesinya sendiri |
| `GET /arsip/alat/{equipment}` | level berkas disaring per-teknisi |
| Semua endpoint | selalu dibatasi ke organisasi pemanggil (multi-tenant). Data PT lain → 404 |
| `GET /dashboard` | angka **sesi** per-teknisi; angka **alat & sertifikat** se-lab |

---

## Jawaban 3 pertanyaan di §1

**1. Apakah `viewer` boleh lihat arsip & sertifikat pelanggan?**
**Ya.** `viewer` bisa baca semua (arsip, sertifikat, unduh PDF), nggak bisa nulis apa pun. Tebakan mobile sudah benar.

**2. Apakah teknisi boleh lihat sesi kalibrasi milik teknisi LAIN?**
**Tidak.** Disaring di `CalibrationController` — dan bukan cuma di daftar: buka per-ID punya orang lain dibalas **404**, jadi nggak bisa ditebak. Tebakan mobile sudah benar.

**3. Apakah ada rencana role keempat (Manajer Teknis)?**
**Belum ada.** Saat ini `User` cuma kenal 3 role. Penanda tangan sertifikat sekarang diambil dari **admin yang meng-approve** (`reviewed_by` → dicetak di PDF sebagai penanda tangan, dengan `department` sebagai jabatan — mis. "Technical Manager").

Artinya: **"Manajer Teknis" sekarang = atribut `department` di akun admin, bukan role terpisah.** Kalau mau jadi role keempat sungguhan (mis. admin boleh approve tapi cuma Manajer Teknis yang boleh tanda tangan), itu perubahan alur approval — **butuh keputusan pemilik produk dulu**, jangan diasumsikan.

---

## Catatan buat §4 (CRUD arsip) — sebagian besar SUDAH ADA

Dokumen Fase 2 nulis *"arsip read-only"* — itu sudah **tidak berlaku**. Folder CRUD sudah rilis (lihat kelompok 3 di atas). Jawaban 5 pertanyaan kalian, berdasarkan yang sudah jalan:

| # | Pertanyaan | Kondisi sekarang |
|---|---|---|
| 1 | Siapa boleh hapus folder? | **admin & teknisi** — beda dari saran kalian (admin doang). **Perlu keputusan**: mau diketatkan? |
| 2 | Hapus beneran hilang? | **Soft delete** ✅ sesuai saran |
| 3 | Folder berisi boleh dihapus? | **Ditolak 422** ✅ sesuai saran |
| 4 | Sertifikat boleh dihapus dari arsip? | **Tidak bisa** ✅ — yang dipindah cuma penempatan folder; sesi & sertifikat nggak pernah terhapus (`folder_id` nullable, `nullOnDelete`) |
| 5 | Batas jenis & ukuran file | **Belum relevan** — arsip sekarang menata *sesi kalibrasi* ke dalam folder, belum ada unggah file bebas. Kalau memang butuh unggah file lepas (mis. scan worksheet), itu permintaan baru |

Endpoint yang ada (bukan `/arsip/folder` seperti di dokumen kalian, tapi **`/arsip/folders`** — jamak):

```
GET    /arsip/perusahaan/{customer}/folder    daftar folder akar perusahaan
GET    /arsip/folders/{folder}                isi folder + breadcrumb
POST   /arsip/folders                         { nama, induk_id }
PUT    /arsip/folders/{folder}                { nama }
PUT    /arsip/folders/{folder}/pindah         { induk_id }
DELETE /arsip/folders/{folder}
PUT    /arsip/berkas/{calibration}/pindah     { folder_id }
```

Pengaman yang sudah jalan: folder akar dikunci sistem (nggak bisa dihapus/rename/pindah), folder nggak bisa dipindah ke dalam keturunannya sendiri, dan nggak bisa pindah lintas perusahaan.
