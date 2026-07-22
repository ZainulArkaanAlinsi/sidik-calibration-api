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
| Semua role (login) | 31 | admin, teknisi, viewer |
| Tulis kalibrasi & alat | 12 | admin, teknisi |
| Admin saja | 33 | admin |

---

## 1. Publik — tanpa token

```
GET    /health
GET    /verify/{qr_token}
POST   /forgot-password
POST   /login
POST   /register
POST   /reset-password
```

## 2. Semua role yang login (termasuk `viewer`)

```
GET    /arsip/alat/{equipment}
GET    /arsip/folders/{folder}
GET    /arsip/perusahaan
GET    /arsip/perusahaan/{customer}
GET    /arsip/perusahaan/{customer}/folder
GET    /calibrations
GET    /calibrations/{calibration}
GET    /categories
GET    /categories/{kode}
GET    /certificates
GET    /certificates/{certificate}/download
GET    /dashboard
GET    /dashboard/tren
GET    /equipments
GET    /equipments/{equipment}
GET    /laporan/kalibrasi
GET    /laporan/kalibrasi/export
GET    /me
GET    /me/permissions
GET    /notifications
GET    /orders
GET    /orders/{order}
GET    /rooms
GET    /rooms/{room}
GET    /standards
GET    /standards/{standard}
POST   /logout
POST   /logout-all
POST   /me/ttd
POST   /notifications/baca-semua
POST   /notifications/{id}/baca
```

## 3. `admin` + `teknisi`

```
DELETE /equipments/{equipment}
POST   /arsip/folders
POST   /calibrations
POST   /calibrations/photos
POST   /calibrations/preview
POST   /calibrations/{calibration}/measurements/verify
POST   /equipments
PUT    /arsip/berkas/{calibration}/pindah
PUT    /arsip/folders/{folder}
PUT    /arsip/folders/{folder}/pindah
PUT    /calibrations/{calibration}
PUT    /equipments/{equipment}
```

## 4. `admin` saja

```
DELETE /arsip/folders/{folder}
DELETE /customers/{customer}
DELETE /orders/{order}
DELETE /rooms/{room}
DELETE /standards/{standard}
DELETE /technicians/{technician}
GET    /customers
GET    /customers/{customer}
GET    /organization
GET    /technicians
GET    /technicians/{technician}
GET    /users
POST   /calibrations/{calibration}/approve
POST   /calibrations/{calibration}/reject
POST   /certificates/{certificate}/kirim-email
POST   /certificates/{certificate}/retry
POST   /customers
POST   /orders
POST   /orders/{order}/penugasan
POST   /organization/logo
POST   /rooms
POST   /standards
POST   /technicians
POST   /users/{user}/approve
POST   /users/{user}/reject
POST   /users/{user}/reset-password
PUT    /customers/{customer}
PUT    /orders/{order}
PUT    /organization
PUT    /rooms/{room}
PUT    /standards/{standard}
PUT    /technicians/{technician}
PUT    /users/{user}
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
| 1 | Siapa boleh hapus folder? | ✅ **admin doang** — sesuai saran kalian. Teknisi boleh bikin/rename/pindah, nggak boleh hapus |
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
