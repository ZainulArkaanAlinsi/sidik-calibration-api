# Perintah Frontend — Permintaan kalibrasi pelanggan

Dokumen **berdiri sendiri**: tempel utuh ke sesi kerja frontend/mobile, tidak perlu
membaca berkas lain.

> Konteksnya, kalau perlu: §41 di `docs/permintaan-user-7.md` (keputusan pemilik
> proyek 30 Sep 2026). Berkas itu pegangan status & keputusan, BUKAN prasyarat.

Backend **permintaan kalibrasi** selesai. Pelanggan mengajukan kalibrasi dari
aplikasinya; **semua admin aktif** lab menerimanya di aplikasi lab; salah satu admin
**menerima** (lahir Order + OrderItem, dan alat baru didaftarkan) atau **menolak**
dengan alasan yang dibaca pelanggan. **Tidak pernah otomatis.** Satu utas pesan per
permintaan, pelanggan ↔ admin lab.

Dua aplikasi, dua dunia, dua awalan URL:

| Aplikasi | Awalan | Token | Header lain |
|---|---|---|---|
| Pelanggan | `/api/pelanggan/v1` | ability `pelanggan` | `X-Perusahaan-Id` (hanya kalau anggota >1 perusahaan) |
| Lab (admin) | `/api` | ability `internal` | — |

Token dari aplikasi yang salah ditolak (403) sebelum apa pun. Semua jawaban JSON.

---

## 1. Status & aturan

```text
            ┌───────────── pelanggan batal ─────────────▶ dibatalkan
   baru ────┤
            ├───────────── admin terima ────────────────▶ diterima  (Order lahir)
            └───────────── admin tolak (alasan) ────────▶ ditolak
```

| Status | Arti | Yang masih boleh |
|---|---|---|
| `baru` | Menunggu ditinjau lab | pelanggan **batal**; admin **terima/tolak**; pesan dua arah |
| `diterima` | Order sudah lahir, `paket` terisi | pesan dua arah. Pelacakan tahap alat di `/paket/{id}` |
| `ditolak` | Lab menolak, `alasan_penolakan` terisi | hanya baca (percakapan **ditutup**) |
| `dibatalkan` | Pelanggan membatalkan | hanya baca (percakapan **ditutup**) |

- Hanya `baru` yang bisa diputuskan atau dibatalkan. Sisanya dijawab **422** dengan
  pesan di `errors.status`.
- Dua admin menekan Terima bersamaan: yang pertama menang, yang kedua **422**. Tidak
  pernah lahir dua order untuk satu permintaan.
- Nomor permintaan: `PMT/2026/09/0001` — urut per lab per bulan.
- Cara pengantaran: `diantar_sendiri` | `diambil_lab`. Hanya dua.

### Bentuk galat (semua endpoint)

| Status | Kapan | Badan |
|---|---|---|
| 401 | Tanpa/tidak sah token | `{"message": "Unauthenticated."}` |
| 403 | Role/aplikasi salah; akun pelanggan belum diverifikasi; teknisi/viewer di rute lab | `{"message": "..."}` |
| 404 | ID tidak ada **atau milik perusahaan/lab lain** (sengaja sama — jangan dibedakan di UI) | `{"message": "..."}` |
| 422 | Validasi **atau** aturan status | `{"message": "...", "errors": {"field": ["pesan"]}}` |
| 429 | Terlalu sering menulis | `{"message": "Kebanyakan percobaan. Tunggu sebentar, terus coba lagi."}` |

404 vs 403: data milik perusahaan/lab lain **selalu 404**. Jangan memetakan 404 jadi
"tidak punya izin" — cukup "tidak ditemukan".

---

## 2. APLIKASI PELANGGAN — `/api/pelanggan/v1`

Semua butuh akun **terverifikasi** (anggota aktif suatu perusahaan). Staf maupun PIC
utama boleh mengajukan, membatalkan, dan berpesan. `customer_id` **tidak pernah**
dikirim — server mengambilnya dari perusahaan aktif.

### 2.1 `GET /permintaan` — daftar

Query: `saring=aktif|selesai|semua` (bawaan `aktif`), `per_page` (1–50, bawaan 20).

- **aktif** = `baru`, atau `diterima` yang paketnya belum selesai/dibatalkan.
- **selesai** = `ditolak`, `dibatalkan`, dan `diterima` yang paketnya sudah selesai.

```json
{
  "data": [ { /* Permintaan, lihat 2.2 */ } ],
  "meta": {
    "total": 1, "per_page": 20, "current_page": 1, "last_page": 1,
    "jumlah": { "aktif": 3, "selesai": 4 }
  }
}
```

`meta.jumlah` untuk angka di tab "Aktif 3 · Selesai 4" — terlepas dari tab yang dibuka.
Terbaru di atas.

### 2.2 `GET /permintaan/{id}` — detail

```json
{
  "data": {
    "id": 12,
    "nomor": "PMT/2026/09/0012",
    "status": "baru",
    "metode_pengantaran": "diantar_sendiri",
    "tanggal_diinginkan_dari": "2026-10-05",
    "tanggal_diinginkan_sampai": "2026-10-09",
    "catatan": "Tolong diprioritaskan.",
    "diajukan_pada": "2026-09-30T02:11:05Z",
    "diputuskan_pada": null,
    "dibatalkan_pada": null,
    "dapat_dibatalkan": true,
    "percakapan_terbuka": true,
    "jumlah_pesan": 2,
    "jumlah_alat": 2,
    "alat": [
      { "id": 31, "alat_id": 88, "baru": false, "nama": "Timbangan Ohaus PX224",
        "merk": "Ohaus", "model": "PX224", "serial": "C3349" },
      { "id": 32, "alat_id": null, "baru": true, "nama": "pH Meter",
        "merk": "Hanna", "model": "HI2211", "serial": "HI2211-0419" }
    ],
    "paket": null
  }
}
```

- `alasan_penolakan` **hanya ada** kalau `status = ditolak` (kuncinya tidak dikirim
  untuk status lain).
- `paket` terisi `{ "id", "nomor" }` setelah `diterima` — pakai `id`-nya ke
  `GET /paket/{id}` untuk pelacakan tahap. `null` kalau belum diterima.
- `alat[].id` = id baris permintaan (BUKAN id alat). `alat_id` = id alat
  (`/alat/{id}`), `null` selama alat baru belum didaftarkan lab. Sesudah diterima,
  alat baru sudah punya `alat_id`.
- Tidak ada nama admin, email admin, atau id organisasi di jawaban ini — memang
  tidak dikirim.

### 2.3 `POST /permintaan` — ajukan (throttle `pelanggan-permintaan-tulis`, 30/menit)

```json
{
  "metode_pengantaran": "diantar_sendiri",
  "tanggal_diinginkan_dari": "2026-10-05",
  "tanggal_diinginkan_sampai": "2026-10-09",
  "catatan": "Tolong diprioritaskan.",
  "alat_id": [88, 91],
  "alat_baru": [
    {
      "nama_alat": "pH Meter",
      "merk": "Hanna",
      "model": "HI2211",
      "serial_number": "HI2211-0419",
      "no_identifikasi": "QC-07",
      "rentang_min": 0,
      "rentang_maks": 14,
      "satuan": "pH",
      "resolusi": 0.01,
      "lokasi": "Lab QC Lantai 2",
      "catatan": "Elektroda diganti Agustus 2026."
    }
  ]
}
```

| Field | Aturan |
|---|---|
| `metode_pengantaran` | **wajib**, `diantar_sendiri` \| `diambil_lab` |
| `tanggal_diinginkan_dari` | opsional, tanggal, **tidak boleh kemarin** |
| `tanggal_diinginkan_sampai` | opsional, tidak boleh sebelum `dari`. Hanya keinginan — lab yang memutuskan tanggal pastinya |
| `catatan` | opsional, ≤ 2000 |
| `alat_id[]` | id alat **yang sudah terdaftar atas perusahaan ini**, status aktif, tanpa duplikat |
| `alat_baru[]` | alat yang belum terdaftar; field mengikuti formulir PL_Form_Alat |
| `alat_baru[].nama_alat` | **wajib**, ≤ 255 |
| `alat_baru[].satuan` | **wajib kalau** `rentang_min` atau `rentang_maks` diisi |
| `alat_baru[].rentang_maks` | ≥ `rentang_min` |
| `alat_baru[].serial_number` | boleh kosong (alat tanpa pelat nama) — lab yang melengkapinya saat menerima. Kalau sudah ada di daftar alat perusahaan sendiri → 422 di `alat_baru.N.serial_number`: "Pilih dari daftar alat" |
| lainnya | `merk`, `model`, `no_identifikasi`, `lokasi` ≤ 255; `resolusi` ≥ 0; `catatan` ≤ 1000 |

Aturan silang: **minimal satu alat** (`alat_id` atau `alat_baru`), **maksimal 50**
total. Tanpa alat → 422 `errors.alat_id`.

`alat_id` milik perusahaan lain dijawab dengan pesan galat yang **sama persis** dengan
id yang tidak ada (`errors["alat_id.0"]`) — jangan menafsirkannya.

Jawaban **201**:

```json
{ "message": "Permintaan terkirim. Tim lab akan meninjaunya.", "data": { /* Permintaan */ } }
```

Alat baru **belum** menjadi alat di daftar pelanggan sampai lab menerima. Jangan
menampilkannya di daftar `/alat` secara lokal.

Foto pelat nama (PL_Form_Alat) **belum** didukung server — lihat §6.

### 2.4 `POST /permintaan/{id}/batal` — batalkan (throttle sama)

Tanpa badan. Hanya status `baru`. Jawaban **200**:
`{ "message": "Permintaan dibatalkan.", "data": { /* Permintaan, status: dibatalkan */ } }`.
Status lain → **422** `errors.status`: `Permintaan ini tidak bisa dibatalkan: statusnya sudah "diterima".`
Di UI: tampilkan tombol hanya kalau `dapat_dibatalkan = true`.

### 2.5 `GET /permintaan/{id}/pesan` — utas

Query: `per_page` (1–100, bawaan 50). **Terlama di atas** (dibaca seperti percakapan).

```json
{
  "data": [
    { "id": 5, "sisi": "lab", "dari_saya": false, "nama_pengirim": "Tim PT Sistem Dirgantara Inovasi Teknologi",
      "isi": "Alat sudah kami terima semua ya pak.", "dibuat_pada": "2026-09-22T03:00:00Z" },
    { "id": 6, "sisi": "pelanggan", "dari_saya": true, "nama_pengirim": "Budi Santoso",
      "isi": "Baik, terima kasih.", "dibuat_pada": "2026-09-22T03:10:00Z" }
  ],
  "meta": { "total": 2, "per_page": 50, "current_page": 1, "last_page": 1, "percakapan_terbuka": true }
}
```

- Pesan dari lab **tidak membawa nama admin** — selalu `Tim {nama lab}`.
- `dari_saya` true hanya untuk pesan yang dikirim akun ini; pesan rekan sekantor
  `sisi: "pelanggan"` dengan `dari_saya: false`.
- `percakapan_terbuka = false` (ditolak/dibatalkan) → sembunyikan kotak ketik, tetap
  tampilkan riwayat.

### 2.6 `POST /permintaan/{id}/pesan` — kirim

Badan `{ "isi": "..." }` — wajib, ≤ 2000. Throttle sama. Jawaban **201**
`{ "data": { /* pesan, bentuk 2.5 */ } }`. Permintaan ditolak/dibatalkan → **422**
`errors.isi`: `Percakapan ini sudah ditutup karena permintaannya ditolak.`

### 2.7 `GET /preferensi-notifikasi` dan `PUT /preferensi-notifikasi`

Saklar notifikasi **per anggota per perusahaan** (perusahaan dari `X-Perusahaan-Id`;
konsultan di dua pabrik punya dua set). `PUT` throttle `pelanggan-preferensi` (30/menit).

```json
{ "data": {
  "pengingat_jadwal": true,
  "status_permintaan": true,
  "pesan_lab": true,
  "ringkasan_email_mingguan": false
} }
```

`PUT`: kirim **sebagian** — yang tidak dikirim tidak berubah (satu ketukan = satu
kunci). Nilai harus boolean, kalau tidak 422. Kunci asing diabaikan. Jawabannya
seluruh set terkini. Anggota yang belum pernah menyetel mendapat bawaan di atas.

| Saklar | Mengatur |
|---|---|
| `pengingat_jadwal` | alarm jatuh tempo (H-30, H-7, H-1, lalu tiap 7 hari setelah lewat) |
| `status_permintaan` | "permintaan diterima" dan "permintaan ditolak" |
| `pesan_lab` | balasan admin di utas |
| `ringkasan_email_mingguan` | **disimpan saja** — pengirim email mingguan belum ada |

Saklar mati = notifikasinya **tidak dikirim sama sekali** (bukan cuma disenyapkan).
Notifikasi OS yang dimatikan di HP tetap masuk ke kotak Notifikasi di dalam aplikasi.
Sisi lain: alat yang sedang diajukan (`baru`) atau ada di order `baru/diproses` **tidak
lagi diingatkan jatuh tempo**.

### 2.8 Notifikasi yang diterima pelanggan (kotak `GET /notifikasi`)

| `kategori` | Kapan | `tautan` |
|---|---|---|
| `pelanggan.permintaan_diterima` | admin menerima | `{"tipe":"pelanggan_permintaan","id":<id>}` |
| `pelanggan.permintaan_ditolak` | admin menolak (alasan ikut di `isi`) | idem |
| `pelanggan.pesan_lab` | admin membalas (isi pesan **tidak** ikut) | idem |

Penerima: seluruh anggota **aktif** perusahaan itu yang saklarnya menyala. Anggota
yang dinonaktifkan PIC tidak menerima. Push hanya ke token aplikasi pelanggan.
`tautan.tipe = pelanggan_permintaan` → buka detail permintaan `id`.

---

## 3. APLIKASI LAB — `/api`

Semua rute di bawah **admin saja**:

| Role | Baca (GET) | Tulis (POST) |
|---|---|---|
| `admin` | ya | ya |
| `super_admin` | ya (lab sendiri) | **403** — wewenang memutuskan belum dibuka (K4) |
| `teknisi`, `viewer` | **403** | **403** |

Izin untuk menyembunyikan tombol dari `GET /api/me/permissions` → `boleh`:
`permintaan.lihat`, `permintaan.putuskan` (terima/tolak), `permintaan.balas`.
Super admin hanya mendapat `permintaan.lihat`.

Permintaan lab lain **404** — pada rute tulis, 404 datang **sebelum** validasi.

### 3.1 `GET /permintaan-pelanggan` — antrean

Query: `status=baru|diterima|ditolak|dibatalkan`, `customer_id`, `search` (nomor atau
nama perusahaan), `per_page` (1–100, bawaan 20). `status` tak dikenal → 422.

Urutan: `status=baru` → **paling lama menunggu di atas**; selain itu terbaru di atas.

```json
{
  "data": [ { /* 3.2 */ } ],
  "meta": { "total": 4, "per_page": 20, "current_page": 1, "last_page": 1, "jumlah_baru": 2 }
}
```

`meta.jumlah_baru` = badge antrean, tidak terpengaruh filter.

### 3.2 `GET /permintaan-pelanggan/{id}` — detail

```json
{ "data": {
  "id": 12, "nomor": "PMT/2026/09/0012", "status": "baru",
  "customer": { "id": 7, "nama": "PT Tirta Mandiri Laboratorium" },
  "pemohon": { "id": 41, "nama": "Budi", "email": "budi@tirtamandiri.co.id", "telepon": "+62812..." },
  "metode_pengantaran": "diambil_lab",
  "tanggal_diinginkan_dari": "2026-10-05", "tanggal_diinginkan_sampai": "2026-10-09",
  "catatan": "Tolong diprioritaskan.",
  "alasan_penolakan": null,
  "diputuskan_oleh": null, "diputuskan_pada": null, "dibatalkan_pada": null,
  "order": null,
  "jumlah_alat": 2, "jumlah_pesan": 1,
  "alat": [
    { "id": 31, "equipment_id": 88, "baru": false, "nama_alat": "Timbangan Ohaus PX224",
      "merk": "Ohaus", "model": "PX224", "serial_number": "C3349", "no_identifikasi": null,
      "rentang_min": null, "rentang_maks": null, "satuan": null, "resolusi": null,
      "lokasi": null, "catatan": null,
      "perlu_kategori": false, "perlu_nomor_seri": false },
    { "id": 32, "equipment_id": null, "baru": true, "nama_alat": "pH Meter",
      "merk": "Hanna", "model": "HI2211", "serial_number": null, "no_identifikasi": "QC-07",
      "rentang_min": 0, "rentang_maks": 14, "satuan": "pH", "resolusi": 0.01,
      "lokasi": "Lab QC Lantai 2", "catatan": "Elektroda diganti Agustus 2026.",
      "perlu_kategori": true, "perlu_nomor_seri": true }
  ],
  "dibuat_pada": "2026-09-30T02:11:05Z"
} }
```

`perlu_kategori` / `perlu_nomor_seri` = petunjuk layar Terima: alat baru yang belum
jadi alat, dan apa yang **wajib dilengkapi admin**. Sesudah diterima `order` terisi
`{ "id", "nomor", "status" }` dan `equipment_id` semua alat terisi.

### 3.3 `POST /permintaan-pelanggan/{id}/terima` (throttle `permintaan-putus`, 60/menit)

```json
{
  "tanggal_masuk": "2026-10-02",
  "tanggal_janji_selesai": "2026-10-20",
  "catatan": "Prioritas.",
  "alat_baru": [
    { "item_id": 32, "equipment_category_id": 4, "serial_number": "HI2211-0419" }
  ]
}
```

| Field | Aturan |
|---|---|
| `tanggal_masuk` | opsional; bawaan hari ini |
| `tanggal_janji_selesai` | opsional |
| `catatan` | opsional, ≤ 1000; masuk ke catatan order |
| `alat_baru[]` | **satu entri untuk tiap alat baru**, dan **wajib** kalau permintaan punya alat baru |
| `alat_baru[].item_id` | id baris alat (`alat[].id`) dari permintaan **ini** |
| `alat_baru[].equipment_category_id` | **wajib**; kategori milik lab ini (`GET /api/categories`) |
| `alat_baru[].serial_number` | **wajib kalau** pelanggan mengosongkannya (`perlu_nomor_seri`). Kalau diisi, menimpa ketikan pelanggan |

Yang terjadi, **utuh atau tidak sama sekali**:

1. `Equipment` dibuat untuk tiap alat baru (milik perusahaan itu, status aktif,
   catatan memuat nomor permintaan).
2. `Order` lahir: status `baru`, `diterima_oleh` = admin ini, nomor `ORD/…` dari
   penomoran order yang sama dengan meja penerimaan, catatan memuat nomor
   permintaan + cara pengantaran + catatan pelanggan.
3. Satu `OrderItem` per alat.
4. Status permintaan → `diterima`, `order_id` terisi.
5. Anggota perusahaan dikabari (sesuai saklar `status_permintaan`).

Jawaban **200**: `{ "message": "Permintaan diterima. Order dibuat.", "data": { /* 3.2 */ } }`.

Galat 422 yang perlu ditangani di layar:

| `errors` kunci | Penyebab |
|---|---|
| `alat_baru.{item_id}.equipment_category_id` | kategori belum dipilih untuk alat baru |
| `alat_baru.{item_id}.serial_number` | nomor seri kosong, atau **bentrok** dengan alat lain di lab ini (termasuk milik perusahaan lain — pesan tidak menyebut pemiliknya). Solusi: ubah nomor serinya, atau tolak & minta pelanggan memilih alat terdaftar |
| `alat_baru.N.item_id` / `alat_baru.N.equipment_category_id` | id bukan dari permintaan/lab ini |
| `status` | sudah diputuskan (admin lain lebih dulu) atau dibatalkan pelanggan |

### 3.4 `POST /permintaan-pelanggan/{id}/tolak` (throttle `permintaan-putus`)

Badan `{ "alasan": "..." }` — **wajib**, 5–1000 karakter, dibaca **pelanggan apa
adanya** (tulis untuk pelanggan, bukan catatan internal). Jawaban **200**
`{ "message": "...", "data": { /* 3.2, status: ditolak */ } }`. Status bukan `baru` →
422 `errors.status`. Alasan kosong/spasi → 422 `errors.alasan`.

### 3.5 `GET /permintaan-pelanggan/{id}/pesan` dan `POST …/pesan`

`GET`: sama dengan 2.5 tetapi pengirim diberi nama sebenarnya:

```json
{ "data": [ { "id": 5, "sisi": "lab", "pengirim": { "id": 3, "nama": "Sari (admin)" },
              "isi": "...", "dibuat_pada": "..." } ],
  "meta": { "total": 1, "per_page": 50, "current_page": 1, "last_page": 1, "percakapan_terbuka": true } }
```

`POST` (throttle `permintaan-pesan`, 60/menit): `{ "isi": "..." }` wajib ≤ 2000 →
**201** `{ "data": { /* satu pesan */ } }`. Ditolak/dibatalkan → 422 `errors.isi`.
Hanya `admin` (super admin 403).

### 3.6 Notifikasi yang diterima admin (kotak `GET /notifications`)

| `kategori` | Kapan | Penerima | `tautan` |
|---|---|---|---|
| `permintaan_baru` | pelanggan mengajukan | **semua admin aktif** lab itu | `{"tipe":"permintaan_pelanggan","id":<id>}` |
| `permintaan_pesan` | pelanggan menulis di utas | semua admin aktif lab itu | idem |

Bukan untuk teknisi/viewer/super admin, admin nonaktif, atau admin lab lain. Isi
catatan & pesan **tidak** ikut di notifikasi (layar kunci).

### 3.7 Siaran realtime

Channel privat `organisasi.{id}` (sudah dipakai), event `data.berubah`, payload
`{ "jenis": "permintaan", "aksi": "dibuat|diterima|ditolak|dibatalkan|pesan", "id": <permintaan id> }`.
Penerimaan juga menyiarkan `{ "jenis": "paket", "aksi": "dibuat", "id": <order id> }`.
Tarik ulang daftar/detail lewat REST saat menerimanya. Siaran yang gagal tidak
menggagalkan permintaan (data sudah tersimpan).

---

## 4. Penyaring baru di endpoint lab yang sudah ada

Tidak mengubah bawaan: tanpa parameter ini jawabannya **sama persis** dengan sebelumnya.

### `GET /api/equipments`

| Query | Arti |
|---|---|
| `customer_id` | alat milik satu pelanggan (id dari lab lain → daftar kosong) |
| `jatuh_tempo_dalam=N` | alat **aktif** yang jatuh tempo dalam N hari ke depan (0–3650), dari hari ini s.d. hari ini+N. Yang sudah lewat **tidak** ikut |
| `termasuk_lewat=1` | dengan `jatuh_tempo_dalam`: ikutkan yang sudah lewat jatuh tempo (satu daftar untuk layar "Jatuh tempo") |
| `urut=jatuh_tempo` | paling lama lewat di atas, alat tanpa jadwal di dasar. Bawaan tetap terbaru dulu (`urut=terbaru`) |

Nilai ngawur (`jatuh_tempo_dalam=abc`, `urut=acak`, `customer_id=abc`) → **422**,
bukan dibaca nol. Bisa digabung dengan `search`, `status`, `category`, `profil`.
Contoh layar "30 hari ke depan": `?jatuh_tempo_dalam=30&urut=jatuh_tempo`; layar
"Sudah lewat + 30 hari": `?jatuh_tempo_dalam=30&termasuk_lewat=1&urut=jatuh_tempo`.

### `GET /api/certificates`

| Query | Arti |
|---|---|
| `customer_id` | sertifikat milik satu pelanggan (dibaca dari alat sesinya, termasuk alat yang sudah dihapus lunak) — untuk pusat pelanggan |

Aturan lama tetap: teknisi hanya melihat sertifikat sesinya sendiri.

---

## 5. Halaman verifikasi publik (`/verify/{qr_token}`)

Tampilan diselaraskan dengan artboard `Web_Verifikasi` / `Web_Verifikasi_HP`. **Data
yang tampil tidak bertambah** dan tetap `noindex, nofollow` + `viewport` (termasuk
lembar penuh hasil pindai, yang sebelumnya tidak punya dua meta itu). Tidak ada
perubahan API.

---

## 6. Yang BELUM ada (jangan dibangun di sisi klien seolah ada)

- **Foto pelat nama** pada alat baru (PL_Form_Alat, maks 3 foto) — belum ada endpoint
  unggah.
- **`POST /alat/{id}/minta-koreksi`** (PL_Ubah_Alat) — belum dibangun: butuh alur
  penanganan di sisi admin yang belum diputuskan, dan kuncian identitas alat
  (`field_terkunci`) belum ada. Layar tetap dirancang, tombolnya sembunyikan.
- **Teknisi dijadwalkan / resi pengiriman** (PL_Daftar_Permintaan) — di luar
  keputusan pemilik proyek; status permintaan hanya empat di atas.
- **Status sertifikat Digantikan/Dibatalkan & penyamaran nama** di halaman
  verifikasi — belum dibangun.
- **Ringkasan email mingguan** — saklarnya tersimpan, pengirimnya belum ada.
- Tidak ada "tetap simpan" untuk nomor seri kembar di sisi pelanggan: dijawab 422,
  pelanggan diminta memilih alat dari daftar.
