# Perintah Frontend — Pengelolaan Pengguna & Pelanggan di Aplikasi Lab (menggantikan panel web `/admin`)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke sesi kerja frontend
aplikasi lab (`sidik-calibration-mobile`) — tidak perlu membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `feat/super-admin-pengelolaan` (worktree `C:\Users\USER\sidik-api-sa`) |
| Mandat | 18 Nov 2026: panel web `/admin` (Filament) tidak dipakai lagi; kelola **Pengguna** dan **Pelanggan** pindah ke aplikasi lab |
| Status | **KONTRAK DITULIS DULU** — endpoint bertanda **BARU** sedang diimplementasi di branch yang sama; bentuk di bawah mengikat. Bagian "Sudah ada" memakai bentuk nyata dari kode yang sudah di `main`. Berkas ini diperbarui di akhir kerja (status + hash commit + jumlah test) |
| Kontrak panjang | `docs/kontrak-api.md` (bagian ringkas ditambahkan di akhir kerja) |
| Gerbang | MySQL penuh **belum** dijalankan — jangan menyebut backend "selesai" ke pemilik sebelum itu |

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

Bentuk visual setiap layar **bukan urusan berkas ini** — tanyakan ke pemilik per layar (aturan UI proyek).

## 6. `GET /api/me/permissions` — kunci izin baru

`MatriksIzin` membaca penjagaan rute asli, jadi jawabannya tidak bisa berbeda dari server:

| Kunci | Rute | `admin` | `super_admin` |
|---|---|---|---|
| `anggota-pelanggan.lihat` | `GET /customers/{customer}/anggota` | ya | ya |
| `anggota-pelanggan.cabut` | `POST /customers/{customer}/anggota/{anggota}/nonaktifkan` | ya | ya |
| `pengguna.buat-admin` | `POST /users` | tidak | ya |
| `pengguna.ubah-status` | `POST /users/{user}/nonaktifkan` (+ `aktifkan`, izin sama) | tidak | ya |

Kunci yang sudah ada dan relevan, tidak berubah: `pengguna.kelola` (`GET /users`), `pengguna.setujui`,
`pelanggan.kelola`, `anggota-pelanggan.undang`, `anggota-pelanggan.pic-admin`, `pengajuan-akun.lihat`,
`pengajuan-akun.putuskan`, `teknisi.kelola`. Teknisi/viewer: tidak satu pun kunci di §6.

## 7. Yang SENGAJA tidak ada (jangan diminta, jangan dibuat di klien)

- Hapus akun pengguna / hapus anggota pelanggan.
- Membuat `super_admin`, `viewer`, atau akun `pelanggan` dari API (akun pelanggan lahir dari undangan).
- Super admin melihat/mengubah lab lain (masih terkurung; lintas lab di API ditunda — AGENTS.md).
- Mengubah sandi lewat `PUT /users/{id}` (`password` ditolak; pakai `reset-password`).
- Mengubah `role` kolom di database atau menamai ulang peran — label tampilan `admin` = "Master Data" hanya di UI.
- Admin biasa menambah admin lain atau menonaktifkan lewat endpoint §4.4 — hanya super admin (kemampuan lama
  `PUT /users/{id}` tidak diperluas maupun dicabut oleh paket ini).
