# Perintah Frontend — CRUD Lengkap (Kategori Alat, Arsip Draf, Penugasan Edit/Batal)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke sesi kerja
aplikasi (mobile/Lab) — tidak perlu membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `feat/crud-kategori-penugasan-arsip-draf` (belum di-push) |
| Status kontrak | **KONTRAK DIKUNCI 18 Nov 2026 (sebelum implementasi).** Bentuk di bawah tidak berubah; kalau terpaksa berubah, dicatat di §9 "Perubahan sesudah dikunci". Contoh JSON ditulis dari rancangan lalu DIVERIFIKASI terhadap respons test saat backend selesai (lihat §9) |
| Status backend | Lihat §9 (diperbarui di commit terakhir paket ini) |
| Mandat | "Sistem CRUD penting di setiap fitur" — pemilik proyek, 18 Nov 2026. Keputusan bawaan orkestrator di bawah bisa dibatalkan pemilik |

---

Bertindak sebagai Frontend Engineer untuk aplikasi Lab (internal) PT Sidik.

Tiga celah CRUD ditutup di API: (1) kategori alat hanya bisa dibaca, (2) penugasan tidak bisa
diedit/dibatalkan, (3) draf lembar kerja tidak bisa dibuang. Berkas arsip (`PUT/DELETE/POST
/arsip/berkas`), Order (`POST/PUT/DELETE /orders`), dan Teknisi (`PUT/DELETE /technicians`)
**sudah ada di API sebelum paket ini** — aplikasi tinggal memakainya; tidak dibahas di sini.

## 0. Aturan paling penting

1. **Tombol disembunyikan bukan kontrol akses.** Server menolak di sisi API; aplikasi hanya
   memakai `GET /api/me/permissions` (kunci di §1) untuk menyembunyikan tombol, dan tetap harus
   menangani `403`/`404`/`422` di tiap aksi.
2. **Tidak ada penghapusan permanen** di paket ini. "Arsip" = disembunyikan dari daftar biasa dan
   bisa dipulihkan Master Data; nilai yang diisi teknisi tidak pernah hilang (ISO/IEC 17025
   7.5.2). "Batal" penugasan = status `dibatalkan`, riwayat tetap. "Nonaktif" kategori = tidak
   ditawarkan di form baru, alat lama tetap menampilkannya.
3. **Alasan wajib** untuk arsipkan draf dan batalkan penugasan (5–400 karakter setelah di-trim).
   Batas 400 (bukan 500) disengaja: alasan ikut tertulis di `audit_logs.note` bersama awalan.
   Hitungan karakter, bukan byte — huruf beraksen/emoji boleh. Aplikasi sebaiknya membatasi input
   400 karakter; server menolak lebih dengan `422`.
4. **Peran** (kunci peran tidak pernah berubah: `admin` = label "Master Data"). `super_admin`
   ikut boleh di semua rute tulis `admin`/`teknisi` di organisasinya sendiri (aturan K-49-14, tanpa
   pengecualian lintas lab). `viewer` dan `pelanggan` tidak boleh menulis apa pun di sini.
5. **Isolasi lab:** milik lab lain dijawab `404` (bukan `403`). Teknisi yang membuka draf teknisi
   lain juga `404`.
6. **Idempoten:** mengulang permintaan yang sama aman (lihat tiap endpoint). Pakai ini untuk retry
   jaringan.
7. Bentuk galat: `{"message": "...", "kode": "<kode_stabil>", "errors": {...}?}`. Branch di
   **`kode`**, jangan di teks `message` (teks boleh diperbaiki kapan saja). Galat validasi bawaan
   (`422` tanpa `kode`) berbentuk `{"message", "errors": {"field": ["..."]}}`.

## 1. Kunci izin baru di `GET /api/me/permissions` → `data.boleh`

| Kunci | Rute penentu | admin | super_admin | teknisi | viewer |
|---|---|---|---|---|---|
| `kategori.kelola` | `POST /api/categories` (juga PUT/DELETE/nonaktifkan/aktifkan) | ya | ya | tidak | tidak |
| `kalibrasi.arsipkan` | `POST /api/calibrations/{c}/arsipkan` | ya | ya | ya (draf sendiri) | tidak |
| `kalibrasi.pulihkan` | `POST /api/calibrations/{c}/pulihkan` | ya | ya | tidak | tidak |
| `penugasan.ubah` | `PUT /api/penugasan/{p}` | ya | ya | tidak | tidak |
| `penugasan.batalkan` | `POST /api/penugasan/{p}/batalkan` | ya | ya | tidak | tidak |

- Tab/daftar **"Arsip draf"** tampil bila `kalibrasi.pulihkan` ada (tidak ada kunci terpisah untuk
  melihat arsip; rute daftarnya sama dengan `GET /api/calibrations`).
- `viewer`/`pelanggan`/tanpa token: `403`/`401`. Tidak ada kunci baru untuk `pelanggan`; seluruh
  rute di sini internal (`api/...`, bukan `api/pelanggan/v1`).

## 2. Kategori alat

Kategori = kelompok pengukuran (mis. `Tekanan`). `kode` (slug dari nama) adalah kunci yang
dipakai field `kategori` di alat dan di URL; **tidak pernah berubah** sesudah dibuat.

### 2.1 Bentuk kategori (item `data`) — field lama tidak berubah, field baru ADDITIVE

```json
{
  "id": 7,
  "kode": "tekanan",
  "nama": "Tekanan",
  "rentang_ukur": null,
  "ketidakpastian_terbaik": null,
  "satuan": null,
  "jumlah_kemampuan": 0,
  "jumlah_tanpa_cmc": 0,
  "aktif": true,
  "nonaktif_pada": null,
  "jumlah_alat": 0
}
```

- `aktif`: `false` bila dinonaktifkan. `nonaktif_pada`: ISO-8601 atau `null`.
- `jumlah_alat`: alat yang masih hidup (belum dihapus) di kategori ini. Tombol **Hapus** tampil
  bila `jumlah_alat == 0 && jumlah_kemampuan == 0`; server tetap memeriksa penuh (juga alat yang
  sudah dihapus lunak, penugasan, dan metode) dan menjawab `422 kategori_dipakai`.

### 2.2 `GET /api/categories` (sudah ada — perilaku berubah sedikit)

- **Default hanya kategori `aktif`** → dropdown form baru (alat, penugasan) otomatis tidak
  menawarkan yang nonaktif.
- `?termasuk_nonaktif=1`: sertakan yang nonaktif. **Hanya berlaku untuk admin & super_admin**;
  untuk peran lain parameter ini diabaikan (tetap hanya aktif). Pakai di layar kelola kategori.
- `GET /api/categories/{kode}` tetap `200` untuk kategori nonaktif (alat lama masih perlu CMC-nya);
  `data` kini juga memuat `id`, `aktif`, `nonaktif_pada` (additive).
- Alat lama yang kategorinya sudah nonaktif **tetap menampilkan kategorinya** (`GET /equipments`
  `kategori` = kodenya) dan tetap bisa diedit tanpa mengganti kategori.

### 2.3 `POST /api/categories` — buat (admin, super_admin)

Throttle `kategori-tulis` (30/menit/akun).

Request:

```json
{ "nama": "Tekanan Vakum" }
```

- `nama`: wajib, string, 2–100 karakter (setelah trim), harus mengandung huruf/angka.
- `kode` TIDAK diterima dari klien: server membuat slug dari nama (`tekanan-vakum`); bila kode itu
  sudah terpakai (termasuk oleh kategori yang sudah dihapus) server menambah akhiran `-2`, `-3`, …
- **Nama unik per organisasi**, tanpa membedakan huruf besar/kecil dan spasi ganda, terhadap
  semua kategori yang belum dihapus (aktif maupun nonaktif).

`201`:

```json
{
  "message": "Kategori dibuat.",
  "data": { "id": 21, "kode": "tekanan-vakum", "nama": "Tekanan Vakum", "rentang_ukur": null,
            "ketidakpastian_terbaik": null, "satuan": null, "jumlah_kemampuan": 0,
            "jumlah_tanpa_cmc": 0, "aktif": true, "nonaktif_pada": null, "jumlah_alat": 0 }
}
```

Galat: `403` (peran tidak boleh) · `422` validasi `errors.nama` · `422` **`nama_kategori_kembar`**:

```json
{
  "message": "Sudah ada kategori bernama itu.",
  "kode": "nama_kategori_kembar",
  "errors": { "nama": ["Sudah ada kategori bernama itu."] },
  "kategori": { "kode": "tekanan-vakum", "nama": "Tekanan Vakum", "aktif": false }
}
```

(`kategori` di galat memberi tahu apakah yang kembar itu nonaktif → aplikasi bisa menawarkan
"Aktifkan kembali".)

### 2.4 `PUT /api/categories/{kode}` — ubah nama (admin, super_admin)

Throttle `kategori-tulis`. Body `{ "nama": "..." }` (aturan sama dengan buat). `kode` tidak
berubah. Boleh juga untuk kategori nonaktif. Nama yang sama dengan miliknya sendiri → `200` tanpa
perubahan. `200 {"message":"Kategori diubah.","data":{...bentuk 2.1}}`. Galat: `404` (kode tidak
ada / bukan lab ini / sudah dihapus) · `422` seperti buat (`nama_kategori_kembar` membandingkan
dengan kategori LAIN).

### 2.5 `POST /api/categories/{kode}/nonaktifkan` — nonaktifkan (admin, super_admin)

Throttle `kategori-tulis`. Body opsional `{ "alasan": "..." }` (maks 400). Boleh walau kategori
dipakai alat — itulah gunanya. Idempoten: sudah nonaktif → `200` tanpa baris audit baru.

`200`:

```json
{ "message": "Kategori dinonaktifkan.", "data": { "id": 21, "kode": "tekanan-vakum",
  "nama": "Tekanan Vakum", "aktif": false, "nonaktif_pada": "2026-11-18T10:15:00+07:00",
  "jumlah_alat": 3, "...": "field lain sama dengan 2.1" } }
```

Sesudah nonaktif, server menolak kategori ini untuk **alat baru** (`POST /equipments`), untuk
**mengganti** kategori alat ke yang nonaktif (`PUT /equipments/{e}`), untuk baris penugasan baru
(`item.*.equipment_category_id`), dan untuk nama alat baru (`POST /categories/{kode}/kemampuan`):
`422` dengan `errors.kategori` / `errors.item.N.equipment_category_id` /
`kode: "kategori_nonaktif"` (khusus kemampuan). Alat yang SUDAH berkategori itu tidak terganggu.

### 2.6 `POST /api/categories/{kode}/aktifkan` — aktifkan kembali (admin, super_admin)

Kebalikan 2.5; body kosong; idempoten. `200 {"message":"Kategori diaktifkan.","data":{...}}`.

### 2.7 `DELETE /api/categories/{kode}` — hapus yang tidak dipakai (admin, super_admin)

Throttle `kategori-tulis`. Hapus lunak (data tetap di database, tercatat di audit). Hanya bila
kategori tidak dipakai apa pun. `200 {"message":"Kategori dihapus."}`. Sudah dihapus → `404`.

`422` **`kategori_dipakai`** (pakai nonaktifkan sebagai gantinya):

```json
{
  "message": "Kategori ini masih dipakai (3 alat). Nonaktifkan saja supaya tidak ditawarkan lagi.",
  "kode": "kategori_dipakai",
  "pemakai": { "alat": 3, "kemampuan": 0, "penugasan": 0, "metode": 0 }
}
```

`pemakai.alat` menghitung juga alat yang sudah dihapus lunak (sesi lama masih menunjuknya).

## 3. Arsip draf lembar kerja

Hanya sesi berstatus **`draft`** yang bisa diarsipkan. Sesi `menunggu_approval`,
`perlu_revisi`, `disetujui` (dan status lama lain) → `422 bukan_draf`.

**Yang terjadi pada draf yang diarsipkan:** hilang dari `GET /calibrations`, dashboard, laporan,
dan pelacakan; `GET`/`PUT /calibrations/{id}` → `404`; nomor sesi (`nomor_sesi`) tetap miliknya
dan **tidak dipakai ulang**; seluruh pembacaan mentah (`raw_measurements`) dan hasil hitung TIDAK
dihapus; tidak ada sertifikat yang berubah (draf memang belum punya). Master Data bisa memulihkan
(§3.3) — sesi kembali `draft` persis seperti sebelum diarsipkan.

### 3.1 `POST /api/calibrations/{calibration}/arsipkan`

Siapa: **pembuat draf** (teknisi pemilik sesi) atau **admin / super_admin** (draf siapa pun di lab
ini). Teknisi pada draf teknisi lain → `404`. Throttle `sesi-arsip` (60/menit/akun).

Request:

```json
{ "alasan": "Salah pilih alat, dibuat ulang." }
```

`200`:

```json
{
  "message": "Draf diarsipkan.",
  "data": {
    "id": 412,
    "nomor_sesi": "KAL/2026/11/0007",
    "status": "draft",
    "diarsipkan": true,
    "arsip": {
      "alasan": "Salah pilih alat, dibuat ulang.",
      "oleh": { "id": 5, "nama": "Budi" },
      "pada": "2026-11-18T10:20:00+07:00"
    }
  }
}
```

- **Idempoten:** mengarsipkan draf yang sudah diarsipkan → `200` dengan `message: "Draf sudah
  diarsipkan."` dan `arsip` yang LAMA (tidak ada baris audit kedua).
- `422` **`bukan_draf`**: `{"message":"Hanya draf yang bisa diarsipkan; sesi ini sudah menunggu_approval.","kode":"bukan_draf","status":"menunggu_approval"}`
- `409` **`status_berubah`**: sesi berubah status di antara pemeriksaan dan penyimpanan (mis.
  dikirim di perangkat lain bersamaan). Muat ulang lalu ulangi bila masih relevan.
- `422` validasi `errors.alasan` (wajib, 5–400 karakter).
- `404` bukan lab ini / bukan milik teknisi pemanggil / tidak ada. `403` untuk `viewer`.

### 3.2 `GET /api/calibrations?diarsipkan=1` — daftar arsip (admin, super_admin)

Parameter lain sama dengan daftar biasa (`q`, `dari`, `sampai`, `customer_id`, `per_page` maks 50,
`page`); `bentuk` diabaikan (selalu bentuk ringkas). Urutan: yang terakhir diarsipkan di atas.
Selain admin/super_admin → `403` dengan `kode: "hanya_admin"`. Tanpa `diarsipkan=1` (atau `0`)
daftar biasa **tidak pernah** memuat sesi terarsip.

Setiap item = bentuk ringkas daftar sesi (`bentuk=ringan`, kunci sama) **ditambah** `arsip`:

```json
{
  "data": [
    {
      "id": 412, "nomor_sesi": "KAL/2026/11/0007", "status": "draft",
      "tanggal_kalibrasi": "2026-11-18",
      "equipment": { "id": 88, "nama_alat": "Timbangan Analitik", "serial_number": "SN-123" },
      "pelanggan": { "id": 3, "nama": "PT Contoh" },
      "teknisi": { "id": 5, "nama": "Budi" },
      "...": "kunci ringkas lain tetap",
      "arsip": {
        "alasan": "Salah pilih alat, dibuat ulang.",
        "oleh": { "id": 5, "nama": "Budi" },
        "pada": "2026-11-18T10:20:00+07:00"
      }
    }
  ],
  "links": { "...": "paginasi Laravel biasa" },
  "meta": { "current_page": 1, "per_page": 15, "total": 1, "...": "..." }
}
```

### 3.3 `POST /api/calibrations/{calibration}/pulihkan` — pulihkan (admin, super_admin)

Throttle `sesi-arsip`. Body opsional `{ "alasan": "..." }` (maks 400). `200`:

```json
{
  "message": "Draf dipulihkan.",
  "data": { "id": 412, "nomor_sesi": "KAL/2026/11/0007", "status": "draft", "diarsipkan": false }
}
```

- Draf kembali muncul di daftar teknisi pemiliknya dengan isi yang sama.
- Idempoten: sesi yang tidak sedang diarsipkan → `422` **`tidak_diarsipkan`** (bukan galat berat:
  aplikasi cukup menyegarkan daftar). `404` lintas lab. `403` untuk `teknisi`/`viewer`.

## 4. Penugasan — edit & batal

Hanya penugasan berstatus **`aktif`** yang bisa diedit/dibatalkan. Siapa: **admin & super_admin**
(sama seperti membuatnya, `POST /penugasan`). Teknisi tetap hanya melaporkan progres
(`PATCH /penugasan/item/{i}`) — tidak berubah.

Bentuk respons `data` = bentuk penugasan yang sudah ada (id, judul, tipe, status, tanggal_target,
terlambat, persen_tuntas, catatan, dibuat_oleh, teknisi[], item[]) dengan satu field ADDITIVE:

```json
{ "...": "field lama", "batal": null }
```

`batal` = `null` selama belum dibatalkan; sesudah dibatalkan:

```json
{ "batal": { "alasan": "Pelanggan membatalkan pesanan.", "oleh": "Sari", "pada": "2026-11-18T11:00:00+07:00" } }
```

(`batal` juga muncul di `GET /penugasan` & `GET /penugasan/{p}` untuk penugasan dibatalkan.)

### 4.1 `PUT /api/penugasan/{penugasan}` — edit

Throttle `penugasan-tulis`. Body = body `POST /penugasan` (PENGGANTI penuh, bukan patch), plus
`item.*.id` opsional:

```json
{
  "judul": "Kalibrasi minggu ini (revisi)",
  "tanggal_target": "2026-11-30",
  "catatan": null,
  "teknisi": [5, 9],
  "item": [
    { "id": 31, "jenis_alat": "Autoklaf", "jumlah": 12 },
    { "jenis_alat": "Timbangan", "jumlah": 4, "equipment_category_id": 2, "order_id": null }
  ]
}
```

Aturan:

- `judul` wajib maks 150; `catatan` maks 1000; `tanggal_target` boleh dikosongkan; **tanggal
  yang sama dengan yang tersimpan boleh walau sudah lewat**, tanggal BARU harus hari ini atau
  sesudahnya.
- `teknisi`: 1–20 id teknisi aktif lab ini; yang pertama jadi ketua; `tipe` dihitung ulang
  (`personal` bila satu orang, `grup` bila lebih). Anggota yang tetap **mempertahankan
  `dilihat_pada`**; anggota baru menerima notifikasi `penugasan_baru`. Anggota yang dikeluarkan
  tidak diberi notifikasi (daftar tugasnya hanya menyegar).
- `item`: 1–50 baris; `item.*.id` = baris yang sudah ada (harus milik penugasan ini); tanpa `id`
  = baris baru. **`jumlah_selesai` tidak pernah diubah lewat sini** (milik teknisi).
  Baris lama yang tidak disebut dihapus HANYA bila `jumlah_selesai == 0`; kalau sudah ada
  progres → `422 item_sudah_dikerjakan` (ubah jumlahnya saja, jangan buang barisnya).
- Perubahan dicatat di audit (nilai lama → baru) per tabel.

`200`:

```json
{ "message": "Penugasan diubah.", "data": { "id": 14, "judul": "Kalibrasi minggu ini (revisi)", "status": "aktif", "...": "bentuk penugasan, batal: null" } }
```

Galat: `403` (peran) · `404` (bukan lab ini) · `422` **`penugasan_tidak_aktif`**
(`{"kode":"penugasan_tidak_aktif","status":"selesai"}` — status `selesai`/`dibatalkan`) · `422`
**`item_sudah_dikerjakan`** (`errors.item`) · `422` validasi (`errors.teknisi.N`,
`errors.item.N.id` "bukan baris penugasan ini", dst.).

### 4.2 `POST /api/penugasan/{penugasan}/batalkan` — batalkan

Throttle `penugasan-tulis`. Body `{ "alasan": "..." }` (wajib, 5–400). Status → `dibatalkan`;
**riwayat tidak dihapus** (anggota, item, progres tetap terbaca). Anggota menerima notifikasi
`penugasan_dibatalkan` (`tautan: {"tipe":"penugasan","id":14}`). Setelah batal,
`PATCH /penugasan/item/{i}` menjawab `422` seperti untuk penugasan selesai.

`200`:

```json
{ "message": "Penugasan dibatalkan.", "data": { "id": 14, "status": "dibatalkan",
  "batal": { "alasan": "Pelanggan membatalkan pesanan.", "oleh": "Sari", "pada": "2026-11-18T11:00:00+07:00" },
  "...": "bentuk penugasan lengkap" } }
```

- **Idempoten:** sudah `dibatalkan` → `200` tanpa audit/notifikasi kedua, `batal` yang lama.
- `422` **`penugasan_sudah_selesai`** bila statusnya `selesai`. `422` validasi `errors.alasan`.
  `404`/`403` seperti biasa.

## 5. Notifikasi baru

Kategori `penugasan_dibatalkan` (lonceng, push, halaman notifikasi — pola `penugasan_baru`):
judul "Penugasan dibatalkan", isi memuat judul penugasan, pembatal, dan alasan; `tautan`
`{"tipe":"penugasan","id":<id>}`. Tidak ada notifikasi baru untuk kategori atau arsip draf.

## 6. Siaran realtime (`data.berubah`)

`jenis: "kategori"`, `aksi: "dibuat" | "diubah"` (nonaktif/aktif/hapus memakai `diubah`);
`jenis: "kalibrasi"`, `aksi: "diubah"` untuk arsip & pulihkan (daftar harus disegarkan);
`jenis: "penugasan"`, `aksi: "diubah"` untuk edit & batal.

## 7. Kode galat stabil (ringkasan)

| `kode` | HTTP | Arti |
|---|---|---|
| `nama_kategori_kembar` | 422 | nama kategori sudah dipakai di lab ini |
| `kategori_dipakai` | 422 | kategori masih dipakai, tidak bisa dihapus |
| `kategori_nonaktif` | 422 | menambah nama alat ke kategori nonaktif |
| `bukan_draf` | 422 | hanya draf yang bisa diarsipkan |
| `tidak_diarsipkan` | 422 | memulihkan sesi yang tidak diarsipkan |
| `status_berubah` | 409 | status sesi berubah bersamaan |
| `hanya_admin` | 403 | `diarsipkan=1` oleh non-admin |
| `penugasan_tidak_aktif` | 422 | edit/batal pada penugasan selesai/dibatalkan |
| `penugasan_sudah_selesai` | 422 | batal pada penugasan selesai |
| `item_sudah_dikerjakan` | 422 | membuang baris yang sudah ada progresnya |

Throttle (per akun; lewat batas → `429`): `kategori-tulis` 30/menit, `sesi-arsip` 60/menit,
`penugasan-tulis` 60/menit (yang sudah ada).

## 8. Yang SENGAJA tidak ada di paket ini

- Tidak ada API membuat/mengubah **rumus** atau **CMC** (lewat Studio dengan gerbang paritas).
- Tidak ada penghapusan permanen sesi/penugasan; tidak ada arsip untuk sesi yang sudah dikirim
  (`menunggu_approval` dst.) — jalur koreksi mereka tetap pengembalian/revisi.
- Detail satu sesi terarsip (`GET /calibrations/{id}`) tetap `404`; daftar arsip (§3.2) sudah
  memuat ringkasan yang cukup untuk memutuskan memulihkan.
- Kategori: tidak ada ubah `kode`, tidak ada edit `worksheet_schema` lewat API.

## 9. Perubahan sesudah dikunci / status backend

(Diisi di commit terakhir paket ini: hash commit, jumlah test, penyimpangan dari §2–§7 bila ada.)
