# Handoff Frontend — Folder Arsip (file manager)

Kontrak buat tim frontend/mobile. Semua di bawah **udah jalan di backend & ada
test**-nya: `tests/Feature/FolderArsipTest.php` (24 test, 113 assertion).
Seluruh suite backend: **261 test lolos**, nol regresi.

Base URL `/api`. Semua butuh header `Authorization: Bearer <token>`.

---

## 1. Ringkasnya

Arsip sekarang **dua lapis** yang jalan bareng di atas data yang sama:

| | Arsip lama (`/arsip/perusahaan`, `/arsip/alat`) | Folder baru (`/arsip/folders`) |
|---|---|---|
| Susunan | Tetap: Perusahaan → Alat → Berkas | Bebas, sedalam apa pun (maks 10) |
| Asalnya | Diturunkan dari relasi | Pohon beneran di tabel `folders` |
| Bisa diubah user | Nggak | Ya (bikin/rename/pindah/hapus) |

**Yang lama nggak diubah sama sekali** — 9 test-nya masih lolos. Frontend boleh
pakai dua-duanya: yang lama buat "cari cepat lewat alat", yang baru buat
"susun map sendiri".

Satu sesi kalibrasi selalu punya `folder_id`, dan tetap kelihatan juga lewat
folder alatnya di arsip lama.

---

## 2. Auto-filing — sesi baru masuk folder sendiri

Waktu `POST /api/calibrations`, backend otomatis naruh sesi (beserta
sertifikatnya nanti) ke **folder akar perusahaan pemilik alat**.

Jalurnya **relasi `equipment.customer_id`**, bukan cocok-cocokan nama
perusahaan. Teknisi milih alat, dan alat itu udah nempel ke pemiliknya — jadi
foldernya nggak mungkin salah.

> **Frontend nggak perlu ngirim `folder_id`** waktu bikin sesi. Kalau dikirim
> pun diabaikan.

Folder akar dibikin otomatis saat pertama dibutuhin, namanya = nama pelanggan.
Perusahaan yang belum pernah dikalibrasi belum punya folder — dan itu normal.

**Dikunci test:** dua sesi perusahaan sama → satu folder yang sama (bukan dua);
dua perusahaan beda → folder masing-masing.

---

## 3. Endpoint

### Baca — semua role yang login

| Method | Path | Guna |
|---|---|---|
| `GET` | `/arsip/perusahaan/{customer}/folder` | Buka folder akar perusahaan (dibikin kalau belum ada). **Ini pintu masuknya.** |
| `GET` | `/arsip/folders/{folder}` | Buka folder mana pun |

Response dua-duanya bentuknya sama:

```jsonc
{
  "folder": {
    "tipe": "folder",
    "id": 12,
    "nama": "Semester 1",
    "is_root": false,
    "parent_id": 9,
    "perusahaan": { "id": 3, "nama": "PT Tirta Gracia" },
    // Rantai akar → folder ini. Frontend nggak perlu manjat parent satu-satu.
    "breadcrumb": [
      { "id": 8,  "nama": "PT Tirta Gracia", "is_root": true  },
      { "id": 9,  "nama": "2026",            "is_root": false },
      { "id": 12, "nama": "Semester 1",      "is_root": false }
    ]
  },

  // Subfolder — TIDAK dipaginasi (jumlahnya kecil; file manager yang
  // nyembunyiin folder di halaman 2 bikin orang ngira foldernya ilang).
  "subfolder": [
    { "tipe": "folder", "id": 15, "nama": "pH Meter HI-2211",
      "parent_id": 12, "is_root": false,
      "jumlah_subfolder": 0, "jumlah_berkas": 4,
      "dibuat_pada": "2026-07-21T10:00:00Z" }
  ],

  // Berkas — DIPAGINASI 15/halaman (`?page=2`), bentuknya sama persis kayak
  // arsip lama (ArsipBerkasResource), jadi komponen daftar berkas bisa dipakai ulang.
  "data": [ { "tipe": "berkas", "id": 3, "keputusan": "PASS", "sertifikat": { ... } } ],
  "links": { ... },
  "meta": { ... }
}
```

> Campur subfolder + berkas di satu daftar UI: gabung `subfolder` lalu `data`,
> dua-duanya udah bawa field `tipe` (`"folder"` / `"berkas"`) buat dibedain.

**Teknisi cuma lihat berkas miliknya sendiri** — sama kayak `/calibrations`,
`/certificates`, dan arsip lama. Subfolder tetap kelihatan semua.

### Nyusun — admin & teknisi (viewer `403`)

| Method | Path | Body |
|---|---|---|
| `POST` | `/arsip/folders` | `{ "parent_id": 9, "nama": "Semester 1" }` |
| `PUT` | `/arsip/folders/{folder}` | `{ "nama": "Semester 2" }` |
| `PUT` | `/arsip/folders/{folder}/pindah` | `{ "parent_id": 9 }` |
| `DELETE` | `/arsip/folders/{folder}` | — |
| `PUT` | `/arsip/berkas/{calibration}/pindah` | `{ "folder_id": 12 }` |

`POST` balikin `201` + `{ "data": { ...FolderResource } }`.
`customer_id` **nggak** dikirim frontend — diturunkan dari induknya, biar folder
nggak bisa "diculik" ke perusahaan lain lewat request yang dikarang.

---

## 4. Aturan yang bikin `422` — siapin pesannya di UI

Semua balik format validasi Laravel standar (`{ "message": ..., "errors": { "<field>": [...] } }`).

| Kejadian | Field error | Pesan backend |
|---|---|---|
| Nama kembar dalam satu induk | `nama` | "Di folder ini udah ada folder dengan nama yang sama." |
| Folder dipindah ke dalam anaknya sendiri / ke dirinya sendiri | `parent_id` | "Folder nggak bisa dipindah ke dalam folder isinya sendiri." |
| Folder/berkas dipindah ke perusahaan lain | `parent_id` / `folder_id` | "...nggak bisa dipindah ke perusahaan lain." |
| Lebih dalam dari 10 tingkat | `parent_id` | "Folder udah kelewat dalam (maksimal 10 tingkat)." |
| Hapus folder yang masih ada isinya | `folder` | "Folder masih ada isinya. Pindahin atau hapus isinya dulu." |
| Rename/pindah/hapus folder akar | `folder` | "Folder perusahaan nggak bisa ..." |

**Folder organisasi lain → `404`**, bukan `403` (biar nggak bocorin bahwa
folder itu ada).

### Yang perlu dilakuin frontend

1. **Sembunyiin tombol Rename / Pindah / Hapus kalau `is_root: true`** — jangan
   nunggu ditolak 422 dulu.
2. **Nama kembar**: validasi optimistis di UI boleh, tapi tetap tangani 422 —
   dua orang bisa bikin folder bareng.
3. **Hapus folder**: kalau `jumlah_subfolder > 0` atau `jumlah_berkas > 0`,
   tombol hapusnya matiin aja + kasih alasan. Backend nggak akan pernah
   hapus-berantai; itu disengaja.

---

## 5. Kenapa dibikin begini (jangan diubah tanpa baca ini)

- **Tiap folder nyimpen `customer_id`, bukan cuma folder akar.** Kepemilikan
  kejawab dalam satu baris, dan cek "pindah lintas perusahaan" jadi
  perbandingan satu kolom. Konsekuensinya folder **nggak bisa** pindah
  perusahaan — itu memang disengaja: sertifikat lab terakreditasi kekunci ke
  pemiliknya.
- **Hapus folder ditolak, bukan cascade.** Di lab terakreditasi, satu klik yang
  ngilangin puluhan sertifikat itu risiko yang nggak sebanding sama kemudahannya.
  Lapis kedua: `calibration_sessions.folder_id` pakai `nullOnDelete`, jadi kalau
  pun folder ilang, rekaman kalibrasinya tetap utuh.
- **Siklus dijaga.** Tanpa itu, mindahin folder ke dalam anaknya sendiri bikin
  cabang itu lepas dari akar — isinya ilang dari arsip tanpa pernah kehapus.
- **Maks 10 tingkat.** Bukan batas teknis; breadcrumb sepuluh tingkat udah nggak
  kebaca di layar HP.

---

## 6. Migrasi

```
2026_07_21_110000_create_folders_table
2026_07_21_110100_add_folder_id_to_calibration_sessions_table
```

Sesi lama `folder_id`-nya `null` (belum kefolderin) — **nggak** di-backfill di
migrasi, biar nggak bikin ribuan folder cuma buat ngisi kolom. Folder akar
dibikin saat dibutuhin. Kalau mau sesi lama ikut kefolderin, bilang — tinggal
nambah command `artisan` sekali jalan.

---

## 7. Yang BELUM digarap

- **Sertifikat belum punya `folder_id` sendiri** — dia ikut folder sesinya
  (relasi `certificate.calibration_session_id`). Cukup buat sekarang; kalau
  nanti sertifikat mau bisa dipindah lepas dari sesinya, itu kolom baru.
- **Upload berkas bebas** (kontrak, PO, foto worksheet mentah) ke dalam folder
  — sekarang isi folder cuma sesi kalibrasi. Butuh tabel `folder_files` +
  storage. Bilang kalau perlu.
- **Pindah banyak berkas sekaligus** (multi-select) — sekarang satu-satu.

*Disusun 21 Juli 2026 · branch `feat/kalibrasi-ph-lengkap-dan-arsip`.*
