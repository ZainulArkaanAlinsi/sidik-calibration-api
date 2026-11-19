# Perintah Frontend — Selesaikan & Buka Lagi Penugasan (QA ronde 2-B, R2-2)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke sesi kerja
aplikasi Lab (mobile) — tidak perlu membuka dokumen lain. Aplikasi BELUM memakai ini (backend
mendahului); tidak ada perubahan merusak: semua field lama tetap, dua field/rute baru bersifat
additive.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `fix/qa-ronde2-b` (belum di-push) |
| Asal | QA ronde 2, R2-2: penugasan tidak pernah bisa `selesai` (teknisi melapor 1/1, `siap_ditutup` true, status tetap `aktif`; satu-satunya jalan keluar = batalkan pekerjaan yang tuntas) |
| Status backend | Selesai di SQLite, test terarah hijau; gerbang MySQL belum |

---

Bertindak sebagai Frontend Engineer untuk aplikasi Lab PT Sidik.

## 1. Dua aksi baru

| Aksi | Rute | Siapa | Syarat |
|---|---|---|---|
| Selesaikan | `POST /api/penugasan/{penugasan}/selesaikan` | admin (Master Data) dan super admin | status `aktif` DAN `persen_tuntas` = 100 |
| Buka lagi | `POST /api/penugasan/{penugasan}/buka-lagi` | **super admin saja** | status `selesai`; body `{ "alasan": "..." }` (wajib, 5–400 karakter) |

Throttle `penugasan-tulis` (60/menit per akun). Kunci izin di `GET /api/me/permissions`:
`penugasan.selesaikan` (admin & super admin) dan `penugasan.buka-lagi` (super admin). Sembunyikan
tombolnya bila kunci tidak ada, **tetapi tetap tangani `403`**: menyembunyikan tombol bukan
kontrol akses.

Menutup sengaja keputusan ORANG: `PATCH /penugasan/item/{i}` tidak menutup otomatis walau
semua baris penuh. Respons itu sudah membawa `siap_ditutup: true`; `persen_tuntas` = 100 di
detail penugasan berarti hal yang sama — pakai itu untuk menyalakan tombol "Selesaikan".

## 2. `POST /api/penugasan/{p}/selesaikan`

Tanpa body. `200`:

```json
{ "message": "Penugasan diselesaikan.",
  "data": { "id": 14, "status": "selesai", "persen_tuntas": 100,
            "diselesaikan_pada": "2026-11-19T10:15:00+07:00", "...": "bentuk penugasan lengkap" } }
```

Anggota penugasan menerima notifikasi kategori `penugasan_selesai` (lonceng, push, halaman
notifikasi; `tautan: {"tipe":"penugasan","id":14}`). Siaran realtime `data.berubah`:
`jenis: "penugasan"`, `aksi: "diubah"` — segarkan daftar & detail.

Galat (`kode` stabil):

| HTTP | `kode` | Kapan | Yang dilakukan aplikasi |
|---|---|---|---|
| 422 | `penugasan_belum_tuntas` | `persen_tuntas` < 100 (`persen_tuntas` ikut di badan) | Tampilkan "baru N% tuntas"; arahkan melaporkan progres |
| 422 | `penugasan_sudah_selesai` | sudah `selesai` | Muat ulang; anggap sudah berhasil |
| 422 | `penugasan_tidak_aktif` | `dibatalkan` (`status` di badan) | Muat ulang |
| 403 | — | teknisi/viewer | Tombol seharusnya tidak tampil |
| 404 | — | penugasan lab lain atau tidak ada | Muat ulang daftar |

Server memutuskan; yang dikirim aplikasi hanya niat "tutup". Tidak ada "tutup paksa" untuk
penugasan yang belum tuntas — kalau memang tidak akan dituntaskan, jalurnya `batalkan` dengan
alasan.

## 3. `POST /api/penugasan/{p}/buka-lagi`

Body `{ "alasan": "Ada alat yang ternyata belum diperiksa." }` (wajib, 5–400; hitungan karakter).
Status `selesai` → `aktif`, `diselesaikan_pada` kembali `null`; progres teknisi (`jumlah_selesai`,
catatan baris) tidak tersentuh. Alasan, pelaku, dan waktunya masuk jejak audit (aksi
`dibuka_lagi`, nilai lama & baru, terbaca lewat `GET /audit-logs?action=dibuka_lagi`).
Sesudah dibuka, teknisi bisa melapor lagi dan admin bisa menutup lagi.

Semua anggota penugasan menerima notifikasi kategori `penugasan_dibuka_lagi` (lonceng, push, halaman
notifikasi; judul "Penugasan dibuka lagi", isi memuat judul penugasan, super admin yang membuka, dan
alasannya; `tautan: {"tipe":"penugasan","id":14}`) — pola `penugasan_dibatalkan`. Siaran realtime
`data.berubah` `jenis: "penugasan"`, `aksi: "diubah"`. Penolakan (422/403/404) tidak mengabari siapa pun.

`200`: `{ "message": "Penugasan dibuka lagi.", "data": { "status": "aktif", "diselesaikan_pada": null, "...": "..." } }`

| HTTP | `kode` | Kapan |
|---|---|---|
| 422 | `penugasan_tidak_selesai` | status bukan `selesai` (`status` di badan: `aktif` atau `dibatalkan`). Penugasan `dibatalkan` TIDAK dibuka lagi — buat penugasan baru |
| 422 | (validasi) `errors.alasan` | alasan kosong / < 5 / > 400 |
| 403 | — | admin, teknisi, viewer (hanya super admin) |
| 404 | — | penugasan lab lain atau tidak ada |

Admin biasa mendapat `403` untuk ID MANA PUN (ada, milik lab lain, atau tidak ada): peran
diperiksa sebelum ID dicari.

## 4. Field additive

`diselesaikan_pada` (ISO-8601 atau `null`) di semua bentuk penugasan (`GET /penugasan`,
`GET /penugasan/{p}`, dan respons aksi di atas). `status: "selesai"` sudah ada sebagai nilai
filter `GET /penugasan?status=selesai` — daftar "Selesai" kini berisi sesuatu.

`PATCH /penugasan/item/{i}` pada penugasan `selesai` tetap `422` dengan pesan "Penugasan ini
sudah selesai. Minta super admin membukanya lagi ..." — kini ada aksinya. `POST
/penugasan/{p}/batalkan` pada penugasan `selesai` tetap `422 penugasan_sudah_selesai`
(buka lagi dulu, baru batalkan).

## 5. Yang SENGAJA tidak ada

- Tidak ada penutupan otomatis saat semua baris penuh.
- Tidak ada pembukaan kembali penugasan `dibatalkan`.
