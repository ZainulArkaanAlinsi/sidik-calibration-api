# Perintah Frontend — Pengelolaan Pengguna & Pelanggan di Aplikasi Lab (menggantikan panel web `/admin`)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke sesi kerja frontend
aplikasi lab (`sidik-calibration-mobile`) — tidak perlu membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `feat/super-admin-pengelolaan` (worktree `C:\Users\USER\sidik-api-sa`) |
| Mandat | 18 Nov 2026: panel web `/admin` (Filament) tidak dipakai lagi; kelola **Pengguna** dan **Pelanggan** pindah ke aplikasi lab |
| Status | **Backend selesai di branch ini** (commit kode terakhir `cf4c82e5`; kontrak dikunci sejak `8874f211`): semua endpoint bertanda **BARU** hidup dan bentuknya persis seperti di bawah. 77 tes baru hijau di SQLite (`AnggotaPelangganSisiLabTest`, `KelolaAkunInternalSuperAdminTest`, `AlatDanRiwayatSertifikatLabTest`, `PelangganHitunganDanPindahAlatTest`, `IsiNamaNormalPelangganTest`) + sapuan izin/isolasi yang sudah ada tetap hijau. Contoh JSON di bawah dikunci oleh asersi tes-tes itu, bukan ditulis dari ingatan |
| Kontrak panjang | `docs/kontrak-api.md` §20 (ringkasan tabel) |
| Gerbang | **MySQL penuh BELUM dijalankan** dan belum ada PR/CI — jangan menyebut backend "selesai" ke pemilik sebelum itu. Branch belum di-push; belum terdeploy, jadi endpoint baru belum ada di server produksi |

---

Bertindak sebagai Frontend Engineer untuk aplikasi lab PT Sidik (Flutter).

## 0. Aturan paling penting

1. **Izin dari server, bukan tebakan peran.** Tampilkan/sembunyikan tombol dari `GET /api/me/permissions`
   (kunci izin baru ada di §6). Menyembunyikan tombol bukan kontrol akses — server tetap menolak dengan
   `403`. Jangan menulis `if (role == 'super_admin')` untuk menyalakan layar ini.
2. **Tidak ada `organization_id` / `customer_id` di body mana pun.** Organisasi selalu dari token.
   `{customer}`, `{anggota}`, `{user}` di path hanya menunjuk baris; yang bukan milik lab pemanggil dijawab
   **`404`** (bukan `403`) — perlakukan `404` sebagai "data tidak ada / sudah dihapus", jangan sebagai bug.
3. **Server yang memutuskan aturan.** Jangan menghitung sendiri "PIC terakhir", "bisa dicabut", "boleh
   diaktifkan". Pakai flag dari server (`bisa_dicabut`, `pic_utama_terakhir`) hanya untuk memperjelas UI;
   server tetap menolak walau tombol sempat tampil.
4. **Alasan wajib untuk aksi yang memutus akses.** `alasan` (min 3, maks 500 karakter) dikirim apa adanya
   ke jejak audit. Jangan mengisinya otomatis dengan teks bawaan — jejaknya harus ucapan orang yang menekan.
   Panjang dihitung **per karakter**, bukan byte (huruf bertanda dan emoji = 1 karakter); batas 500 TIDAK
   berubah (catatan 18 Nov sore: server sempat 500 di MySQL untuk alasan sangat panjang — sudah diperbaiki di
   sisi server, alasan lengkap tetap tersimpan utuh; klien tidak perlu berubah dan tidak boleh memperpendek batas).
5. **Tidak ada hapus.** Akun dan keanggotaan **dinonaktifkan**, tidak pernah dihapus (riwayat sertifikat &
   audit menempel di sana). Jangan menampilkan tombol "Hapus" untuk akun pengguna maupun anggota.
6. **Sandi tidak pernah dikembalikan server.** Sandi awal akun baru hanya diketahui pembuatnya; tampilkan
   pengingat "sampaikan langsung ke orangnya, minta diganti lewat Ganti Sandi".
7. **Setelah aksi sukses, ambil ulang daftar** (`GET`), jangan menambal state lokal. Kunci JSON `data` di
   balasan aksi hanya untuk menampilkan baris yang baru berubah.

## 1. Siapa boleh apa (ringkas)

| Aksi | Master Data (`admin`) | Super Admin (`super_admin`) | Teknisi / Viewer / Pelanggan |
|---|---|---|---|
| Daftar & detail pelanggan, undang, batalkan undangan, PIC admin | ya | ya | 403 |
| **BARU** Daftar anggota + undangan menunggu per perusahaan | ya | ya | 403 |
| **BARU** Cabut (nonaktifkan) anggota pelanggan | ya | ya | 403 |
| **BARU** Daftar alat ringkas satu pelanggan (kursor) | ya | ya | 403 |
| **BARU** Hitungan & penanda `tanpa_akun` di daftar/detail pelanggan | ya | ya | 403 |
| **BARU** Pindahkan alat (+ sertifikatnya) ke PT lain, dengan pratinjau | ya | ya | 403 |
| **BARU** Riwayat sertifikat satu alat (kursor) | ya | ya | teknisi & viewer **boleh** (teknisi: hanya sesinya sendiri); pelanggan 403 |
| Daftar pengguna lab (`GET /users`) | ya | ya | 403 |
| Ubah data akun, setujui/tolak pendaftar, setel ulang sandi orang lain | ya (sudah ada, tidak berubah) | ya | 403 |
| **BARU** Tambah akun **admin** | **403** | ya | 403 |
| **BARU** Nonaktifkan / aktifkan kembali akun internal | **403** (admin tetap bisa lewat `PUT /users/{id}` `status` yang sudah ada) | ya | 403 |
| Buat akun `super_admin` / `viewer` / `pelanggan` dari API | **tidak pernah** | **tidak pernah** | — |

Super admin **terkurung di organisasinya sendiri** di API (K-49-14). Akun `super_admin` lain tidak pernah
muncul di `GET /users` dan tidak bisa disentuh (`404`).

## 2. Konvensi respons (berlaku untuk semua endpoint di berkas ini)

- Header: `Authorization: Bearer <token>`, `Accept: application/json`. Token ber-ability `internal`.
- Sukses: `200` / `201`, badan `{ "message": "...", "data": ... }` (daftar `GET /users` tetap bentuk
  paginasi Laravel `{ data, links, meta }`).
- Galat validasi: `422` `{ "message": "...", "errors": { "<field>": ["..."] } }` — tampilkan per kolom.
- Galat aturan bisnis: `422` (atau `4xx` lain) `{ "kode": "<kode_stabil>", "message": "..." }`. **Percabangan UI
  memakai `kode`, bukan teks `message`** (teks boleh berubah). Daftar `kode` ada di tiap endpoint.
- `401` `{ "kode": "akun_nonaktif", "message": "..." }` — akun pemanggil dinonaktifkan; keluar ke layar masuk.
- `403` `{ "message": "Kamu nggak punya akses ke sini." }` — peran tidak cukup (tanpa `kode`).
- `404` — baris tidak ada **atau milik lab/perusahaan lain** (sengaja tidak dibedakan).
- `429` `{ "message": "Kebanyakan percobaan. Tunggu sebentar, terus coba lagi." }` — throttle per akun.

## 3. Pelanggan (perusahaan) — apa yang SUDAH ada dan apa yang BARU

### 3.1 Sudah ada — tidak berubah (jangan menunggu backend untuk ini)

Semua di bawah dipagari `admin` + `super_admin`. Bentuk di bawah ditarik dari kode (`CustomerResource`,
`PelangganKeanggotaanController`), bukan karangan.

| Kebutuhan layar | Endpoint | Catatan nyata |
|---|---|---|
| Daftar pelanggan | `GET /api/customers?search=&page=` | paginasi 15; `{data:[{id,nama,alamat,contact_person,telepon,email,jumlah_alat}],links,meta}` |
| Detail pelanggan | `GET /api/customers/{customer}` | `{data:{id,nama,alamat,contact_person,telepon,email,jumlah_alat}}`. **Tidak memuat anggota, undangan, atau PIC admin** — itu alasan §3.2 ada |
| Tambah / ubah | `POST /api/customers`, `PUT /api/customers/{customer}` | body `nama` (wajib saat tambah, unik per lab), `alamat`, `contact_person`, `telepon`, `email` (semua nullable). `nama.unique` → 422 |
| Hapus | `DELETE /api/customers/{customer}` | `422` `{message}` bila masih punya alat. Tombol ini boleh ada (data pelanggan, bukan akun) |
| Undang anggota | `POST /api/customers/{customer}/undangan` | body `{email, peran: "pic_utama"\|"staf"}` → `201` `{message, data:{id,email,peran,kedaluwarsa_pada,berlaku_sampai,email_terkirim,kode}}`. **`kode` polos hanya muncul di balasan ini, sekali** — tampilkan "Salin kode" supaya admin bisa menyampaikannya bila email gagal. `422` bila email sudah anggota aktif / batas anggota tercapai (`kode: batas_anggota`). Throttle `pelanggan-undang` 20/menit |
| Batalkan undangan | `DELETE /api/customers/{customer}/undangan/{undangan}` | `200` `{message:"Undangan dibatalkan."}`; undangan lain perusahaan → 404 |
| Tunjuk PIC admin lab | `PATCH /api/customers/{customer}/pic-admin` | body `{user_id: <id admin aktif lab ini> \| null}` → `{message, data:{customer_id,pic_admin_id}}`; `422 errors.user_id` bila bukan admin aktif lab ini |
| Pengajuan akun (antrean lama) | `GET /api/admin/pengajuan-akun`, `POST /api/admin/pengajuan-akun/{id}/setujui`, `/tolak` | antrean tidak terisi lagi (pendaftaran mandiri ditutup); layar boleh disediakan untuk baris lama. Throttle `pelanggan-putus-pengajuan` 30/menit |

### 3.2 BARU — daftar anggota + undangan menunggu per perusahaan

`GET /api/customers/{customer}/anggota` — `admin`, `super_admin`. Tanpa query. Tanpa paginasi (batas anggota
per perusahaan ≤ `maks_anggota`, bawaan kecil).

Respons `200`:

```json
{
  "data": {
    "customer": { "id": 17, "nama": "PT Contoh Makmur" },
    "maks_anggota": 50,
    "jumlah_aktif": 2,
    "pic_admin": { "id": 4, "nama": "Rina Master Data", "email": "rina@lab.test" },
    "anggota": [
      {
        "id": 41,
        "peran": "pic_utama",
        "status": "aktif",
        "bergabung_pada": "2026-11-02T03:10:00+00:00",
        "dinonaktifkan_pada": null,
        "dinonaktifkan_oleh": null,
        "pic_utama_terakhir": true,
        "bisa_dicabut": false,
        "orang": { "id": 88, "nama": "Budi Santoso", "email": "budi@contoh.test", "telepon": "0812000000", "jabatan": "QC Manager" }
      },
      {
        "id": 42,
        "peran": "staf",
        "status": "aktif",
        "bergabung_pada": "2026-11-03T07:00:00+00:00",
        "dinonaktifkan_pada": null,
        "dinonaktifkan_oleh": null,
        "pic_utama_terakhir": false,
        "bisa_dicabut": true,
        "orang": { "id": 91, "nama": "Sari Dewi", "email": "sari@contoh.test", "telepon": null, "jabatan": null }
      },
      {
        "id": 40,
        "peran": "staf",
        "status": "nonaktif",
        "bergabung_pada": "2026-10-20T01:00:00+00:00",
        "dinonaktifkan_pada": "2026-11-10T09:30:00+00:00",
        "dinonaktifkan_oleh": { "id": 4, "nama": "Rina Master Data" },
        "pic_utama_terakhir": false,
        "bisa_dicabut": false,
        "orang": { "id": 75, "nama": "Andi Lama", "email": "andi@contoh.test", "telepon": null, "jabatan": null }
      }
    ],
    "undangan_menunggu": [
      {
        "id": 12,
        "email": "baru@contoh.test",
        "peran": "staf",
        "kedaluwarsa_pada": "2026-11-25T08:00:00+00:00",
        "dibuat_pada": "2026-11-18T08:00:00+00:00",
        "diundang_oleh": { "id": 4, "nama": "Rina Master Data" }
      }
    ]
  }
}
```

Aturan isi:
- `anggota` diurut: aktif dulu, lalu nonaktif; di dalam kelompok menurut `id`. Anggota yang pernah dicabut
  tetap tampil (status `nonaktif`) sebagai jejak.
- `undangan_menunggu` = undangan yang belum dipakai, belum dibatalkan, belum kedaluwarsa. **Kode undangan
  tidak pernah ada di sini** (server hanya menyimpan hash). Untuk undangan yang kodenya hilang: batalkan lalu
  undang ulang.
- `pic_utama_terakhir` = anggota ini satu-satunya `pic_utama` aktif. `bisa_dicabut` = `status == "aktif"` dan
  bukan PIC utama terakhir.
- `dinonaktifkan_oleh` bisa orang lab, PIC utama perusahaan itu, atau orangnya sendiri (hapus akun) → `{id,nama}`;
  `null` bila tidak ada.
- `orang` bisa `null` bila akun orangnya sudah tidak ada.
- Galat: `404` bila `{customer}` bukan milik lab pemanggil.

### 3.3 BARU — cabut (nonaktifkan) anggota pelanggan oleh Master Data / Super Admin

`POST /api/customers/{customer}/anggota/{anggota}/nonaktifkan` — `admin`, `super_admin`.
Throttle: `pelanggan-cabut-anggota` 30/menit per akun.

Body:

```json
{ "alasan": "Karyawan sudah pindah perusahaan; diminta PIC lewat telepon 18 Nov." }
```

| Field | Aturan |
|---|---|
| `alasan` | wajib, string, 3–500 karakter (spasi di ujung dipangkas) |

Respons `200` — bentuk satu elemen `anggota[]` §3.2 (status sudah `nonaktif`):

```json
{
  "message": "Anggota dinonaktifkan. Seluruh sesinya dicabut.",
  "data": {
    "id": 42, "peran": "staf", "status": "nonaktif",
    "bergabung_pada": "2026-11-03T07:00:00+00:00",
    "dinonaktifkan_pada": "2026-11-18T10:15:00+00:00",
    "dinonaktifkan_oleh": { "id": 4, "nama": "Rina Master Data" },
    "pic_utama_terakhir": false, "bisa_dicabut": false,
    "orang": { "id": 91, "nama": "Sari Dewi", "email": "sari@contoh.test", "telepon": null, "jabatan": null }
  }
}
```

Efek di server (satu transaksi, lewat satu pintu yang sama dengan jalur PIC utama):
- baris keanggotaan → `nonaktif` + siapa & kapan; token aplikasi pelanggan orang itu dicabut dan token perangkat
  push-nya dihapus **kecuali** dia masih anggota aktif perusahaan lain (konsultan) — dalam kasus itu sesinya
  tetap hidup dan hanya perusahaan ini yang hilang dari akses;
- baris `audit_logs`: pelaku (akun yang login, **tidak pernah menyamar**), peran pelaku, waktu, status
  sebelum/sesudah, `alasan`.
- Mengundang ulang orang yang sama kemudian menghidupkan kembali keanggotaannya (lewat §3.1 undangan).

Galat:

| HTTP | `kode` | Arti | UI |
|---|---|---|---|
| 422 | — (`errors.alasan`) | alasan kosong / < 3 / > 500 | tampilkan di kolom |
| 422 | `pic_utama_terakhir` | dia satu-satunya PIC utama aktif; tidak boleh dicabut | jelaskan: undang/angkat PIC utama lain dulu |
| 422 | `sudah_nonaktif` | anggota sudah nonaktif (mis. dicabut orang lain semenit lalu) | muat ulang daftar |
| 404 | — | perusahaan bukan milik lab ini, anggota bukan milik perusahaan ini, atau tidak ada | muat ulang / kembali |
| 403 | — | peran bukan admin/super admin | — |
| 429 | — | throttle | — |

### 3.4 BARU — melacak alat milik satu pelanggan (daftar ringkas, kursor)

Untuk tab "Alat" di detail Pelanggan. Yang **sudah ada tapi tidak cukup**: `GET /api/equipments?customer_id=`
(paginasi `page`, bentuk penuh tanpa jumlah/riwayat sertifikat) dan `GET /api/certificates?customer_id=`
(paginasi `page`, satu daftar campur untuk semua alat). Dua-duanya tetap ada dan tidak berubah; endpoint ini
menambah bentuk ringkas + paginasi **kursor** supaya daftar panjang tidak dimuat sekaligus.

`GET /api/customers/{customer}/alat?cursor=&limit=` — `admin`, `super_admin`. Throttle `alat-pelanggan-lab`
60/menit per akun.

| Query | Aturan |
|---|---|
| `limit` | opsional, bilangan bulat **1–50**, bawaan 20. Di luar itu → `422 errors.limit` (tidak dipotong diam-diam) |
| `cursor` | opsional; **nilai opak** dari `meta.next_cursor` halaman sebelumnya — jangan dibuat, dibaca, atau disimpan lintas sesi. Rusak/bukan dari server → `422 errors.cursor` |

Urutan tetap: alat **terbaru didaftarkan dulu** (id menurun) — stabil walau ada alat baru masuk saat menggulir.
Alat yang sudah dihapus tidak ikut. Respons `200`:

```json
{
  "data": [
    {
      "id": 118,
      "nama_alat": "Termometer Digital",
      "merk": "Fluke",
      "model": "1524",
      "serial_number": "SN-2231",
      "no_identifikasi": "TH-014",
      "status": "aktif",
      "status_alat": "mendekati_jadwal",
      "tanggal_kalibrasi_terakhir": "2025-12-03",
      "tanggal_jatuh_tempo": "2026-12-02",
      "hari_ke_jatuh_tempo": 14,
      "jumlah_sertifikat": 3,
      "sertifikat_terbaru": {
        "id": 31,
        "nomor": "CAL/2025/12/0007",
        "diterbitkan_pada": "2025-12-03",
        "status": "terbit",
        "status_dokumen": "berlaku"
      }
    }
  ],
  "meta": { "limit": 20, "next_cursor": "azE6MTE4", "ada_lagi": true }
}
```

- `status` = kosakata lab yang sama dengan `GET /equipments` (`aktif` \| `overdue` \| `nonaktif`).
- `status_alat` = **kode yang sama persis** dengan yang dilihat pelanggan di aplikasinya (`terkalibrasi` \|
  `mendekati_jadwal` \| `lewat_jadwal` \| `belum_pernah` \| `nonaktif`; dihitung oleh fungsi yang sama dengan
  aplikasi pelanggan, jendela "mendekati" = 30 hari). Jangan menghitung ulang di klien.
- `tanggal_jatuh_tempo` = jadwal kalibrasi ulang alat itu; `hari_ke_jatuh_tempo` negatif = sudah lewat; `null`
  bila belum ada jadwal.
- `jumlah_sertifikat` = semua baris sertifikat alat itu, **status apa pun** (terbit, digantikan, ditarik,
  dibatalkan, gagal, menunggu). Aplikasi pelanggan hanya melihat yang terbit/dibatalkan — angkanya boleh lebih
  kecil di sana; itu bukan beda data.
- `sertifikat_terbaru` = baris sertifikat **terbaru dibuat** (id terbesar, status apa pun) atau `null`. Arti
  `status` / `status_dokumen`: lihat tabel §3.5.
- `meta.next_cursor` = `null` dan `ada_lagi: false` di halaman terakhir. Kirim `next_cursor` apa adanya ke
  panggilan berikutnya sampai `ada_lagi` false. Halaman kosong = perusahaan belum punya alat.

Galat: `404` (perusahaan bukan milik lab ini / tidak ada — **dijawab sebelum** validasi `limit`/`cursor`, jadi
query rusak ke perusahaan lab lain tetap `404`), `422` (`limit`/`cursor`), `403`, `429`.

### 3.5 BARU — riwayat sertifikat SATU alat (tahun ke tahun, kursor)

Untuk layar "Riwayat sertifikat" dari satu alat (nomor seri yang sama dikalibrasi ulang tiap tahun).
Sertifikat lama **tidak pernah tertimpa**: revisi/terbit ulang melahirkan baris BARU (`…-R1`), dan baris lama
tetap tampil dengan `status_dokumen: "digantikan"` + penunjuk ke penggantinya.

`GET /api/equipments/{equipment}/sertifikat?cursor=&limit=` — **semua peran internal** (`admin`, `super_admin`,
`teknisi`, `viewer`), sama dengan `GET /api/equipments/{id}`. Aturan peran yang sudah berlaku di daftar
sertifikat: **teknisi hanya melihat sertifikat dari sesi kalibrasi miliknya sendiri** (alat yang sama bisa
tampak lebih sedikit baris baginya); admin, super admin, viewer melihat semua. Throttle `riwayat-sertifikat-alat`
60/menit per akun. `limit`/`cursor` seperti §3.4.

Urutan tetap: **terbaru dibuat dulu** (id menurun). Untuk sertifikat normal ini sama dengan terbaru terbit
dulu; revisi selalu lebih baru dari aslinya. Respons `200`:

```json
{
  "data": [
    {
      "id": 40,
      "nomor": "CAL/2025/12/0007-R1",
      "revisi_ke": 1,
      "status": "terbit",
      "status_dokumen": "berlaku",
      "keputusan": "PASS",
      "diterbitkan_pada": "2026-01-15",
      "berlaku_sampai": "2026-12-02",
      "tahun": 2026,
      "bulan": 1,
      "revisi_dari": { "id": 31, "nomor": "CAL/2025/12/0007" },
      "digantikan_oleh": null,
      "ditarik_pada": null,
      "dibatalkan_pada": null
    },
    {
      "id": 31,
      "nomor": "CAL/2025/12/0007",
      "revisi_ke": 0,
      "status": "terbit",
      "status_dokumen": "digantikan",
      "keputusan": null,
      "diterbitkan_pada": "2025-12-03",
      "berlaku_sampai": "2026-12-02",
      "tahun": 2025,
      "bulan": 12,
      "revisi_dari": null,
      "digantikan_oleh": { "id": 40, "nomor": "CAL/2025/12/0007-R1", "diterbitkan_pada": "2026-01-15" },
      "ditarik_pada": null,
      "dibatalkan_pada": null
    }
  ],
  "meta": { "limit": 20, "next_cursor": null, "ada_lagi": false }
}
```

| Field | Arti |
|---|---|
| `status` | status baris di server, apa adanya: `terbit` \| `ditarik` \| `dibatalkan` \| `gagal` \| `menunggu_generate`. `gagal` = PDF gagal dirender (admin bisa mencoba lagi lewat `POST /certificates/{id}/retry`); `ditarik` = ditarik kembali ke lembar kerja |
| `status_dokumen` | label yang sama dengan yang dilihat pelanggan: `berlaku` \| `digantikan` \| `dibatalkan` \| `ditarik` \| `belum_terbit` (untuk `gagal`/`menunggu_generate`). `digantikan` = ada revisi yang terbit atau dibatalkan (pembatalan revisi tidak menghidupkan pendahulunya) |
| `revisi_ke` | 0 = asli; `n` = revisi ke-n (`…-R<n>`) |
| `keputusan` | **BARU 19 Nov 2026 (aditif):** `PASS` \| `FAIL` \| `null`. Keputusan sesi yang TERSIMPAN (sama dengan detail sertifikat & halaman verifikasi), bukan dihitung ulang; `null` = jenis alat tidak divonis → **jangan tampilkan lencana**. Kuncinya selalu ada |
| `digantikan_oleh` | **hanya bila penggantinya TERBIT** (id, nomor, tanggal terbit); `null` bila tidak ada pengganti sah — termasuk bila revisinya dibatalkan |
| `revisi_dari` | sertifikat asal bila baris ini revisi; selain itu `null` |
| `tahun`, `bulan` | dari `diterbitkan_pada` (1–12) untuk pengelompokan di UI; `null` bila belum terbit |
| `diterbitkan_pada`, `berlaku_sampai`, `ditarik_pada`, `dibatalkan_pada` | tanggal (`YYYY-MM-DD`) / waktu ISO-8601, `null` bila tidak berlaku |

Yang **tidak** ada di baris ini (sengaja ringkas): isi `snapshot`/angka, alasan revisi/penarikan, path berkas.
Buka satu sertifikat lewat `GET /api/certificates/{id}`; PDF lewat tautan unduh yang sudah ada
(`GET /api/certificates/{id}/download`, berurutan sama).

Galat: `404` (alat bukan milik lab ini / tidak ada / sudah dihapus — dijawab sebelum validasi query), `422`
(`limit`/`cursor`), `403` (hanya akun non-internal, mis. token aplikasi pelanggan), `401`, `429`.

### 3.6 BARU (aditif) — hitungan & penanda di daftar dan detail pelanggan

`GET /api/customers` (tiap elemen `data[]`) dan `GET /api/customers/{customer}` (`data`) mendapat enam kunci
BARU. **Semua kunci lama tidak berubah** (termasuk `jumlah_alat`; `alat_count` bernilai sama, nama baru untuk
keseragaman). Dihitung di SERVER dengan agregat/subquery berindeks — satu query per halaman, bukan satu per
baris. Klien tidak menghitung ulang.

```json
{
  "id": 17, "nama": "PT Contoh Makmur", "alamat": "Jl. Contoh 1", "contact_person": null,
  "telepon": null, "email": null, "jumlah_alat": 3,
  "alat_count": 3,
  "sertifikat_count": 5,
  "anggota_aktif_count": 0,
  "undangan_menunggu_count": 1,
  "tanpa_akun": true,
  "nama_normal_kosong": false
}
```

| Kunci | Arti |
|---|---|
| `alat_count` | alat PT ini yang belum dihapus |
| `sertifikat_count` | sertifikat berstatus `terbit` + `dibatalkan` yang menempel lewat alat PT (alat yang sudah dihapus tetap dihitung) — **sama dengan yang dilihat pelanggan** di daftar sertifikatnya; `ditarik`/`gagal`/`menunggu_generate` tidak dihitung |
| `anggota_aktif_count` | anggota berstatus `aktif` |
| `undangan_menunggu_count` | undangan belum dipakai, belum dibatalkan, belum kedaluwarsa |
| `tanpa_akun` | `true` bila PT **punya alat atau sertifikat** tetapi `anggota_aktif_count` = 0 — artinya tidak ada satu orang pun di PT itu yang bisa melihat datanya. Tombol "Undang anggota" layak ditonjolkan |
| `nama_normal_kosong` | `true` bila kolom normalisasi nama PT kosong: deteksi PT kembar (`POST /customers/cepat`, pencarian longgar) **tidak bekerja untuk baris ini**. Menyimpan ulang PT itu lewat `PUT /customers/{id}` memperbaikinya otomatis; untuk massal lihat §3.8 |

`POST`/`PUT /customers` ikut membawa `nama_normal_kosong` (selalu `false` setelah disimpan) tetapi **tidak**
membawa hitungan (tidak dihitung di sana). Filter daftar (`search`) dan urutan tidak berubah.

### 3.7 BARU — pindahkan alat (beserta sertifikatnya) ke PT lain, dengan pratinjau

Temuan data nyata: sertifikat bisa menempel di PT yang tidak punya akun, sementara akun ada di PT lain
(PT kembar/salah ketik). Memperbaikinya = memindahkan ALAT ke PT yang benar; sertifikat ikut karena
sertifikat menempel lewat alat (sama dengan cara aplikasi pelanggan membacanya).

`POST /api/customers/{customer}/pindahkan-alat` — `admin`, `super_admin`. `{customer}` = **PT asal**.
Throttle `pindah-alat-pelanggan` 10/menit per akun. Satu panggilan, dua mode:

**Pratinjau (tidak menulis apa pun)**

```json
{ "alat_ids": [118, 119], "ke_customer_id": 23, "dry_run": true }
```

**Eksekusi** (wajib `konfirmasi` dan `alasan`; sebaiknya didahului pratinjau)

```json
{ "alat_ids": [118, 119], "ke_customer_id": 23, "alasan": "PT salah ketik, pemilik sebenarnya PT B", "konfirmasi": true }
```

| Field | Aturan |
|---|---|
| `alat_ids` | wajib, larik 1–100 bilangan bulat unik; semua harus alat **PT asal** |
| `ke_customer_id` | wajib, bilangan bulat; PT di laboratorium yang sama, bukan PT asal |
| `dry_run` | opsional boolean, bawaan `false`. `true` = pratinjau; `konfirmasi`/`alasan` diabaikan |
| `konfirmasi` | wajib `true` bila bukan `dry_run`; selain itu `422 konfirmasi_wajib` |
| `alasan` | wajib 3–500 karakter bila bukan `dry_run` |

Respons `200` pratinjau (`dry_run: true`):

```json
{
  "data": {
    "dry_run": true,
    "dari": { "id": 17, "nama": "PT Contoh Makmur", "anggota_aktif_count": 0 },
    "ke":   { "id": 23, "nama": "PT Contoh Makmur Tbk", "anggota_aktif_count": 2 },
    "jumlah_alat": 2,
    "alat": [
      { "id": 118, "nama_alat": "Termometer Digital", "serial_number": "SN-2231", "jumlah_sertifikat": 3 },
      { "id": 119, "nama_alat": "Timbangan", "serial_number": "SN-9001", "jumlah_sertifikat": 2 }
    ],
    "sertifikat_terdampak": 5,
    "sertifikat_terlihat_pelanggan": 4,
    "anggota_mulai_melihat": [ { "id": 41, "nama": "Budi Santoso", "email": "budi@contoh.test" } ],
    "anggota_berhenti_melihat": [],
    "paket_terkait_count": 1,
    "peringatan": [
      { "kode": "paket_tidak_dipindah", "pesan": "Baris paket/order yang menunjuk alat ini TIDAK ikut pindah." },
      { "kode": "arsip_tidak_dipindah", "pesan": "Berkas arsip lama tetap di folder PT asal sampai pemilik menjalankan perapian arsip." },
      { "kode": "isi_sertifikat_tetap", "pesan": "Nomor dan isi sertifikat (termasuk nama pemilik yang tercetak) tidak diubah." }
    ]
  }
}
```

- `sertifikat_terdampak` = semua baris sertifikat dari alat-alat itu, status apa pun;
  `sertifikat_terlihat_pelanggan` = yang `terbit` + `dibatalkan` (yang tampil di aplikasi pelanggan).
- `anggota_mulai_melihat` = anggota aktif PT tujuan yang **bukan** anggota aktif PT asal (mulai melihat alat &
  sertifikat itu); `anggota_berhenti_melihat` = kebalikannya. Konsultan yang aktif di kedua PT tidak muncul di
  keduanya. Tiap daftar paling banyak 50 orang.
- `paket_terkait_count` = baris paket/order (`order_items`) yang menunjuk alat itu. Tidak dipindahkan;
  `peringatan` selalu memuat tiga kode di atas dengan `kode` yang stabil (`pesan` boleh berubah).
- Pratinjau tidak menulis `audit_logs` dan tidak mengubah data apa pun.

Respons `200` eksekusi:

```json
{
  "message": "2 alat dipindahkan ke PT Contoh Makmur Tbk.",
  "data": {
    "dry_run": false,
    "dari": { "id": 17, "nama": "PT Contoh Makmur", "anggota_aktif_count": 0 },
    "ke":   { "id": 23, "nama": "PT Contoh Makmur Tbk", "anggota_aktif_count": 2 },
    "jumlah_alat": 2,
    "alat_ids": [118, 119],
    "sertifikat_terdampak": 5,
    "sertifikat_terlihat_pelanggan": 4
  }
}
```

Efek di server (satu transaksi, baris alat dikunci): hanya `equipments.customer_id` berubah. **Nomor, isi,
snapshot, PDF, dan QR sertifikat tidak disentuh** (sertifikat lama tidak pernah tertimpa; nama pemilik yang
tercetak tetap yang dulu). Jejak: satu baris `audit_logs` `alat_dipindah_pt` (pelaku, peran, PT asal → PT
tujuan, id alat, jumlah sertifikat, `alasan`) di samping baris `diubah` otomatis per alat (`customer_id` lama →
baru). Tidak ada notifikasi push ke anggota mana pun.

Galat:

| HTTP | `kode` | Arti |
|---|---|---|
| 404 | — | PT asal / PT tujuan bukan milik lab ini atau tidak ada, atau ada id di `alat_ids` yang bukan alat lab ini (sengaja tidak dibedakan). Dijawab SEBELUM validasi `alasan`/`konfirmasi`/aturan PT; hanya id yang salah tipe (mis. `"abc"`) yang `422` lebih dulu |
| 422 | — (`errors.alat_ids`, `errors.ke_customer_id`, `errors.alasan`) | isian rusak / alasan kosong |
| 422 | `pt_tujuan_sama` | `ke_customer_id` = PT asal |
| 422 | `alat_bukan_milik_pt_asal` | sebagian id adalah alat lab ini tetapi milik PT lain; `data.alat_ids` = id yang salah. Muat ulang daftar alat PT asal |
| 422 | `konfirmasi_wajib` | eksekusi tanpa `konfirmasi: true` |
| 409 | `alat_sudah_pindah` | alat berpindah PT di antara pratinjau dan eksekusi (admin lain); ulangi pratinjau |
| 403 / 429 | — | peran / throttle |

### 3.8 Pengisian `nama_normal` massal — perintah artisan (BUKAN endpoint)

Tidak ada `POST /customers/{id}/normalisasi-nama`. Pengisian massal untuk baris `nama_normal` kosong dijalankan
**pemilik** lewat server: `php artisan pelanggan:isi-nama-normal` (dry-run bawaan, hanya mencetak hitungan;
`--jalankan` terpisah menulis). Aplikasi cukup membaca `nama_normal_kosong` (§3.6): setelah perintah jalan,
penanda itu hilang pada muat ulang. Menyimpan satu PT lewat `PUT /customers/{id}` juga memperbaiki baris itu.

## 4. Pengguna (akun internal lab)

### 4.1 Sudah ada — tidak berubah

| Kebutuhan layar | Endpoint | Catatan nyata |
|---|---|---|
| Daftar pengguna | `GET /api/users?status=&page=` | `admin` + `super_admin`; **sudah** terkurung ke lab pemanggil dan hanya role `admin`/`teknisi`/`viewer` (pelanggan & super admin tidak pernah tampil). Item = `UserResource`: `{id,nama,email,employee_id,kode_teknisi,role,status,department,organization_id}`. Paginasi 15, urut `id` turun. Filter `role`/`search` = BARU, §4.2 |
| Ubah data akun | `PUT /api/users/{user}` | body opsional: `nama`, `email`, `employee_id`, `department`, `role` (`admin`/`teknisi`; menjadi `viewer` ditolak), `status` (`aktif`/`pending`/`nonaktif`). `status:"nonaktif"` mencabut token. Email/ID pegawai kembar → `422`. Tidak bisa mengubah akun `super_admin` / pelanggan (`404`). **Tidak bisa mengubah sandi** |
| Setel ulang sandi orang lain | `POST /api/users/{user}/reset-password` | body `{password}` (min 8) → `200 {message}`; semua token orang itu dicabut |
| Setujui / tolak pendaftar `pending` | `POST /api/users/{user}/approve` (body `{role:"admin"\|"teknisi"}`), `POST /api/users/{user}/reject` | untuk baris lama; pendaftaran mandiri sudah ditutup |
| Daftar / tambah / ubah **teknisi** | `GET/POST /api/technicians`, `GET/PUT /api/technicians/{id}` | `POST` body `{nama,employee_id,email,department?,password(min 8),status?}` → `201 {data}`; role dipaksa `teknisi`. `DELETE` ditolak `422` bila teknisi punya riwayat — **pakai nonaktifkan, bukan hapus** |
| Ganti sandi SENDIRI | `POST /api/me/ganti-sandi` | body `{sandi_lama, sandi, sandi_confirmation}` → `200 {message, data:{sesi_dicabut}}`; `422` `kode: sandi_lama_salah`; throttle `ganti-sandi-lab` 5/menit. Semua peran internal termasuk super admin |
| Izin pemanggil | `GET /api/me/permissions` | `{data:{role, boleh:[...], batasan:{...}}}` — kunci baru di §6 |

### 4.2 BARU — `GET /api/users`: filter tambahan (aditif)

Tambahan query (bentuk respons **tidak berubah**; klien lama tidak terpengaruh):

| Query | Nilai | Perilaku |
|---|---|---|
| `role` | `admin` \| `teknisi` \| `viewer` | menyaring per peran. Nilai lain (termasuk `super_admin`, `pelanggan`) → `422 errors.role` |
| `search` | teks ≤ 100 | cocokkan sebagian pada `nama`, `email`, atau `employee_id` |
| `status` | (sudah ada) `aktif` \| `pending` \| `nonaktif` | tetap seperti sekarang |

Boleh digabung. Semua tetap dalam lab pemanggil. `page` seperti biasa.

### 4.3 BARU — tambah akun **admin (Master Data)**: hanya Super Admin

`POST /api/users` — **hanya `super_admin`** (`admin` → `403`). Throttle `akun-lab-buat` 10/menit per akun.

Body:

```json
{
  "role": "admin",
  "nama": "Rina Master Data",
  "email": "rina@lab.test",
  "employee_id": "SDK-0200",
  "department": "Master Data",
  "password": "sandiawal-min-8"
}
```

| Field | Aturan |
|---|---|
| `role` | wajib, **hanya `admin`**. Nilai lain → `422 errors.role` ("Akun teknisi dibuat lewat POST /api/technicians; peran lain tidak dibuat dari sini."). `super_admin`, `viewer`, `pelanggan` tidak pernah bisa |
| `nama` | wajib, string ≤ 255 |
| `email` | wajib, email valid, ≤ 255, unik di seluruh tabel akun (`422 errors.email` "Email ini sudah terdaftar.") |
| `employee_id` | wajib, string ≤ 50, unik (`422 errors.employee_id` "ID pegawai ini sudah terdaftar.") |
| `department` | opsional, string ≤ 255 |
| `password` | wajib, min 8 |

`organization_id` **tidak** dikirim: server memakai lab pemanggil. Akun lahir `status: "aktif"`.

Respons `201`:

```json
{
  "message": "Akun admin dibuat.",
  "data": {
    "id": 57, "nama": "Rina Master Data", "email": "rina@lab.test",
    "employee_id": "SDK-0200", "kode_teknisi": "RM",
    "role": "admin", "status": "aktif", "department": "Master Data", "organization_id": 1
  }
}
```

Sandi tidak ada di respons. Jejak: baris `audit_logs` "dibikin" (pelaku = super admin yang menekan; sandi disensor).

### 4.4 BARU — nonaktifkan / aktifkan kembali akun internal: hanya Super Admin

`POST /api/users/{user}/nonaktifkan` dan `POST /api/users/{user}/aktifkan` — **hanya `super_admin`**.
Throttle `akun-lab-status` 30/menit per akun. Target harus akun `admin`/`teknisi`/`viewer` di lab yang sama;
akun `super_admin`, pelanggan, atau lab lain → `404`.

Body `nonaktifkan`: `{ "alasan": "Resign per 15 Nov 2026" }` — `alasan` wajib, 3–500 karakter.
Body `aktifkan`: `{ "alasan": "Kembali bertugas" }` — `alasan` opsional (≤ 500).

Respons `200` (kedua endpoint):

```json
{
  "message": "Akun dinonaktifkan. Seluruh sesinya dicabut.",
  "data": {
    "id": 21, "nama": "Dedi Teknisi", "email": "dedi@lab.test", "employee_id": "SDK-0021",
    "kode_teknisi": "DT", "role": "teknisi", "status": "nonaktif",
    "department": null, "organization_id": 1
  }
}
```

(`aktifkan`: `message: "Akun diaktifkan kembali."`, `status: "aktif"`.)

Efek di server:
- `nonaktifkan`: `status → nonaktif` dan **semua token akun itu dicabut** di request yang sama (perangkatnya
  keluar pada panggilan API berikutnya, `401 akun_nonaktif`). Pekerjaan/riwayat/sertifikat atas namanya tidak
  disentuh. Tidak ada hapus.
- `aktifkan`: `status → aktif`; **sandi dan role tidak berubah**. Orangnya masuk lagi dengan sandi lamanya.
  Bila perlu sandi baru, pakai `reset-password` (§4.1).
- Dua-duanya menulis `audit_logs`: pelaku, peran pelaku, waktu, status sebelum/sesudah, `alasan`.

Galat:

| HTTP | `kode` | Arti |
|---|---|---|
| 422 | — (`errors.alasan`) | alasan kosong/terlalu pendek/terlalu panjang (nonaktifkan) |
| 422 | `sudah_nonaktif` | `nonaktifkan` untuk akun yang sudah `nonaktif` |
| 422 | `sudah_aktif` | `aktifkan` untuk akun yang sudah `aktif` |
| 422 | `perlu_persetujuan` | `aktifkan` untuk akun `pending` — pendaftar harus lewat `POST /users/{id}/approve` (yang menetapkan role) |
| 404 | — | akun tidak ada / lab lain / `super_admin` / pelanggan |
| 403 | — | bukan super admin (termasuk Master Data) |
| 429 | — | throttle |

## 5. Layar yang disarankan (pemetaan ke endpoint — urutan & tampilan keputusan sesi UI)

| Layar | Endpoint | Peran yang melihat |
|---|---|---|
| Pengguna (daftar + filter peran/status/cari) | `GET /users` | admin, super admin |
| Detail pengguna → Ubah data / Setel ulang sandi | `PUT /users/{id}`, `POST /users/{id}/reset-password` | admin, super admin |
| Detail pengguna → Nonaktifkan / Aktifkan | `POST /users/{id}/nonaktifkan\|aktifkan` | super admin |
| Tambah admin | `POST /users` | super admin |
| Tambah teknisi | `POST /technicians` | admin, super admin |
| Pelanggan (daftar / detail / tambah / ubah) | `/customers*` | admin, super admin |
| Pelanggan → Anggota & Undangan | `GET /customers/{id}/anggota` + undang/batalkan/cabut | admin, super admin |
| Pelanggan → PIC admin | `PATCH /customers/{id}/pic-admin` | admin, super admin |
| Pelanggan → Alat (daftar ringkas) | `GET /customers/{id}/alat` | admin, super admin |
| Pelanggan → Pindahkan alat | `POST /customers/{id}/pindahkan-alat` (pratinjau lalu eksekusi) | admin, super admin |
| Alat → Riwayat sertifikat | `GET /equipments/{id}/sertifikat` | semua peran internal |

Bentuk visual setiap layar **bukan urusan berkas ini** — tanyakan ke pemilik per layar (aturan UI proyek).

## 6. `GET /api/me/permissions` — kunci izin baru

`MatriksIzin` membaca penjagaan rute asli, jadi jawabannya tidak bisa berbeda dari server:

| Kunci | Rute | `admin` | `super_admin` |
|---|---|---|---|
| `anggota-pelanggan.lihat` | `GET /customers/{customer}/anggota` | ya | ya |
| `anggota-pelanggan.cabut` | `POST /customers/{customer}/anggota/{anggota}/nonaktifkan` | ya | ya |
| `pelanggan.alat-lihat` | `GET /customers/{customer}/alat` | ya | ya |
| `pelanggan.pindah-alat` | `POST /customers/{customer}/pindahkan-alat` | ya | ya |
| `alat.riwayat-sertifikat` | `GET /equipments/{equipment}/sertifikat` | ya | ya (teknisi & viewer juga ya) |
| `pengguna.buat-admin` | `POST /users` | tidak | ya |
| `pengguna.ubah-status` | `POST /users/{user}/nonaktifkan` (+ `aktifkan`, izin sama) | tidak | ya |

Kunci yang sudah ada dan relevan, tidak berubah: `pengguna.kelola` (`GET /users`), `pengguna.setujui`,
`pelanggan.kelola`, `anggota-pelanggan.undang`, `anggota-pelanggan.pic-admin`, `pengajuan-akun.lihat`,
`pengajuan-akun.putuskan`, `teknisi.kelola`. Teknisi/viewer hanya mendapat `alat.riwayat-sertifikat` dari tabel di atas
(teknisi: sertifikat sesinya sendiri); kunci lain di §6 tidak.

## 7. Yang SENGAJA tidak ada (jangan diminta, jangan dibuat di klien)

- Hapus akun pengguna / hapus anggota pelanggan.
- Membuat `super_admin`, `viewer`, atau akun `pelanggan` dari API (akun pelanggan lahir dari undangan).
- Super admin melihat/mengubah lab lain (masih terkurung; lintas lab di API ditunda — AGENTS.md).
- Mengubah sandi lewat `PUT /users/{id}` (`password` ditolak; pakai `reset-password`).
- Mengubah `role` kolom di database atau menamai ulang peran — label tampilan `admin` = "Master Data" hanya di UI.
- Admin biasa menambah admin lain atau menonaktifkan lewat endpoint §4.4 — hanya super admin (kemampuan lama
  `PUT /users/{id}` tidak diperluas maupun dicabut oleh paket ini).
