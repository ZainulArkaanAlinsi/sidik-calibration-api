# Kontrak API — SIDIK Pelanggan (`/api/pelanggan/v1`)

> Versi 0.4 · 16 Sep 2026 · Cakupan: **M1-03 s/d M1-09** (auth, akun, undangan, anggota, hapus akun).
> Endpoint alat, sertifikat, permintaan, dan pesan menyusul di Fase 6.

Dokumen ini buat @ZainulArkaanAlinsi (Flutter, repo `sidik-pelanggan-mobile`).
Isinya endpoint yang SUDAH jalan di server, bukan rencana.

Berbeda dari `docs/kontrak-api.md` (app internal teknisi): **base URL-nya lain,
tokennya lain, dan akunnya lain.** Satu akun tidak bisa dipakai di dua aplikasi —
itu disengaja, lihat §2.

**Kalau ada yang mau diubah, kabarin dulu.** Contoh JSON di bawah bukan diketik
tangan: dia disalin dari `tests/Fixtures/pelanggan/*.json`, yang diadu ulang tiap
kali suite jalan (`KontrakResponsPelangganTest`). Jadi kalau dokumen ini dan
server berbeda, yang salah dokumennya — dan testnya sudah merah duluan.

---

## 0. Aturan umum

**Base URL**: `/api/pelanggan/v1`. Waktu dev dari emulator Android:
`http://10.0.2.2:8000/api/pelanggan/v1`.

**Header yang selalu dikirim**:

```
Authorization: Bearer <token>      (kecuali endpoint §1)
Accept: application/json
X-App-Versi: 1.0.0+12
X-Request-Id: <uuid v4>
```

**Sukses** selalu dibungkus `data`; list menambah `meta` paginasi Laravel.

**Error 422** bentuknya bawaan Laravel (`message` + `errors` per field).

**Error selain 422 SELALU punya `kode`** — dan `kode` itulah yang dipakai
aplikasi buat bercabang, **bukan `message`**:

```json
{ "kode": "email_belum_diverifikasi", "message": "Email Anda belum diverifikasi. …" }
```

Alasannya: aplikasi yang sudah terpasang di HP orang tidak bisa disuruh ikut
berubah hari itu juga. Kalimat Indonesianya boleh diperbaiki kapan saja; `kode`
tidak (NFR-12).

### Daftar `kode` yang sudah dipakai

| `kode` | HTTP | Kapan | Yang harus dilakukan aplikasi |
|---|---|---|---|
| `belum_tersedia` | 503 | `FITUR_PELANGGAN` mati | Layar "belum tersedia", jangan retry otomatis |
| `versi_aplikasi_usang` | 426 | Di bawah `versi_minimum` | Layar wajib update (S21) |
| `terlalu_sering` | 429 | Rate limit | Tampilkan hitung mundur, jangan retry langsung |
| `kredensial_salah` | 401 | Email/sandi salah | "Email atau sandi salah" — jangan bedakan |
| `email_belum_diverifikasi` | 403 | Akun `pending_email` | Lempar ke layar OTP (S05 langkah 3) |
| `akun_nonaktif` | 403 | Nonaktif / dihapus | Layar kontak PT Sidik |
| `bukan_akun_pelanggan` | 403 | Akun lab masuk di sini | "Pakai aplikasi teknisi" |
| `bukan_aplikasi_ini` | 403 | Token dari aplikasi lain | Paksa keluar, hapus token lokal |
| `sesi_berakhir` | 401 | Token nganggur > 30 hari | Ke layar masuk, **draf lokal jangan dihapus** |
| `token_lama` | 401 | Token lama lewat masa transisi | Sama seperti `sesi_berakhir` |
| `otp_salah` | 422 | Kode salah / kedaluwarsa / sudah dipakai | Kosongkan field, izinkan coba lagi |
| `otp_terkunci` | 429 | Salah 5 kali | Hitung mundur 15 menit |
| `sandi_lama_salah` | 422 | Ganti sandi, sandi lama meleset | Tandai field sandi lama |
| `akun_belum_diverifikasi` | 403 | Token `pelanggan:menunggu` menyentuh data | Kembali ke S06 |
| `undangan_tidak_berlaku` | 422 | Kode undangan salah / kedaluwarsa / sudah dipakai | Satu pesan saja — jangan tebak mana penyebabnya |
| `undangan_akun_internal` | 422 | Email undangan ternyata akun internal PT Sidik | "Email ini akun internal, tidak bisa diundang" |
| `batas_anggota` | 422 | Perusahaan sudah penuh (`data.maks_anggota`) | Tampilkan angkanya, arahkan hubungi PT Sidik |
| `pic_utama_terakhir` | 422 | PIC utama terakhir menonaktifkan dirinya | Minta angkat PIC utama lain dulu |
| `sudah_diputus` | 409 | Admin lain mendahului (sisi lab) | Muat ulang antrean |
| `perusahaan_belum_dipilih` | 400 | Anggota > 1 perusahaan, `X-Perusahaan-Id` kosong | Tampilkan pemilih dari `data.pilihan` |
| `bukan_pic_utama` | 403 | Aksi keanggotaan oleh staf | Sembunyikan tombolnya, jangan tampilkan lalu gagal |
| `sudah_nonaktif` | 422 | Anggota sudah nonaktif | Muat ulang daftar |
| `sandi_salah` | 422 | Sandi salah waktu menghapus akun | Tandai field sandi, akun tidak jadi dihapus |

---

## 1. Tanpa token

### `GET /app/status`

Satu-satunya endpoint yang tetap jalan waktu `FITUR_PELANGGAN` mati. Dipanggil
tiap kali aplikasi dibuka dan tiap kali kembali dari background.

```json
{
  "data": {
    "versi_minimum": "0.0.0",
    "versi_terbaru": "0.0.0",
    "maintenance": false,
    "pesan_maintenance": "Aplikasi sedang dalam perbaikan. Silakan coba lagi sebentar lagi."
  }
}
```

### `POST /auth/daftar` — REQ-AUTH-01

Throttle: **5 per jam per IP** (`kode: terlalu_sering`).

```json
{
  "nama": "Budi Pendaftar",
  "email": "budi@contoh.test",
  "sandi": "Kalibrasi#2026Sidik",
  "telepon": "0812-3456-7890",
  "jabatan": "QA Supervisor",
  "nama_perusahaan": "PT Contoh Industri",
  "alamat_perusahaan": "Jl. Contoh No. 1, Bandung",
  "setuju_syarat": true
}
```

Aturan yang gampang kelewat:

- **`sandi` minimal 10 karakter** dan ditolak kalau ada di daftar sandi bocor
  (HIBP). Pesannya keluar di `errors.sandi`.
- **`telepon` dinormalisasi server** ke `+62…`. Kirim apa adanya (`0812-…`,
  `+62 812 …`, `812…`), yang tersimpan tetap satu bentuk.
- **`setuju_syarat` wajib `true`.** Versi dokumen yang dicatat ditentukan
  server, jangan dikirim dari aplikasi.
- **Email yang sudah terpakai dijawab 422**, dengan pesan yang **tidak** bilang
  "sudah terdaftar" — jangan ditulis ulang jadi kalimat itu di aplikasi.

Balasan **201** (perhatikan: **tidak ada token** di sini):

```json
{
  "message": "Kode verifikasi 6 digit dikirim ke budi@contoh.test.",
  "data": {
    "email": "budi@contoh.test",
    "status": "pending_email",
    "otp_berlaku_menit": 10
  }
}
```

### `POST /auth/verifikasi-email` — REQ-AUTH-02

Throttle: **10 per 15 menit per email** — pagar luar saja. Yang mengikat
penguncian **5 kode salah** yang tersimpan di akunnya (lihat bawah).

```json
{ "email": "budi@contoh.test", "otp": "483920", "nama_perangkat": "Pixel 8a Budi" }
```

Berhasil → **200** dengan token ber-`kemampuan: "pelanggan:menunggu"`. Bentuknya
sama persis dengan `POST /auth/masuk` di bawah.

Salah 5 kali → `otp_terkunci` **15 menit**, dan selama terkunci **kode yang benar
pun ditolak**. `POST /auth/kirim-ulang-otp` juga ditolak selama itu — jadi jangan
pasang tombol "kirim ulang" sebagai jalan keluar dari layar terkunci.

Sesudah kuncinya lepas, **kode lama pasti sudah mati** (kunci 15 menit > masa
berlaku kode 10 menit). Aplikasi harus langsung meminta kode baru, jangan
menawarkan "coba kode yang tadi".

### `POST /auth/kirim-ulang-otp`

Throttle: **3 per 15 menit per email**, dan embernya **TERPISAH** dari
`verifikasi-email`. Jadi salah memasukkan kode tidak menghabiskan jatah "kirim
ulang", dan sebaliknya.

`{ "email": "…" }` → selalu **200**, apa pun emailnya. Kode lama mati begitu
kode baru terbit.

### `POST /auth/terima-undangan` — REQ-AUTH-06

Throttle: **10 per 15 menit per email**.

Jalur KEDUA masuk ke aplikasi, dan sengaja berbeda dari `daftar`: di sini
**tidak ada OTP dan tidak ada antrean verifikasi**. Yang menjamin orangnya
berhak bukan klaim yang dia ketik, melainkan bahwa seseorang yang sudah
berwenang (PIC utama perusahaan itu, atau admin lab) mengirim kode ke alamat
emailnya.

```json
{
  "email": "budi@contoh.test",
  "kode": "K7M2QP4R",
  "nama": "Budi Diundang",
  "sandi": "Kalibrasi#2026Sidik",
  "telepon": "0812-3456-7890",
  "jabatan": "QA Staff",
  "setuju_syarat": true,
  "nama_perangkat": "Pixel 8a Budi"
}
```

- **Kode 8 simbol**, abjadnya **tanpa `O` `0` `I` `1` `L`** — orang mengetiknya
  ulang dari email. Huruf kecil dan spasi **dirapikan server**, jadi kirim apa
  adanya dari field input.
- Berlaku **7 hari**, sekali pakai, dan hanya cocok untuk email yang diundang.
- Undangan **baru membatalkan yang lama** untuk pasangan email+perusahaan yang
  sama.
- Email yang **sudah punya akun pelanggan** tidak dibuatkan akun kedua — dia
  jadi anggota perusahaan tambahan (kasus konsultan). `nama` dan `sandi` yang
  dikirim **diabaikan** untuk akun yang sudah ada.

Balasan **201**, bentuknya sama dengan `masuk`:

```json
{
  "data": {
    "token": "<token>",
    "kedaluwarsa_pada": "<iso8601>",
    "kemampuan": "pelanggan",
    "user": {
      "id": "<int>",
      "nama": "Budi Diundang",
      "email": "budi@contoh.test",
      "telepon": "+6281234567890",
      "jabatan": "QA Staff",
      "status": "aktif",
      "butuh_verifikasi": false,
      "keanggotaan": [
        {
          "customer_id": "<int>",
          "nama_perusahaan": "PT Contoh Pelanggan",
          "peran": "staf"
        }
      ],
      "pengajuan": null
    }
  }
}
```

Semua kegagalan kode dijawab **satu pesan yang sama** (`undangan_tidak_berlaku`)
— jangan tampilkan tebakan "mungkin sudah kedaluwarsa"; server memang tidak
memberi tahu yang mana.

### `POST /auth/masuk` — REQ-AUTH-03/07/08/10

Throttle: **10 per menit per IP**, DAN **5 kegagalan per email → kunci 15 menit**.

```json
{ "email": "budi@contoh.test", "sandi": "Kalibrasi#2026Sidik", "nama_perangkat": "Pixel 8a Budi" }
```

```json
{
  "data": {
    "token": "<token>",
    "kedaluwarsa_pada": "<iso8601>",
    "kemampuan": "pelanggan",
    "user": {
      "id": "<int>",
      "nama": "Budi Anggota",
      "email": "budi@contoh.test",
      "telepon": "+628123456789",
      "jabatan": "QA Manager",
      "status": "aktif",
      "butuh_verifikasi": false,
      "keanggotaan": [
        {
          "customer_id": "<int>",
          "nama_perusahaan": "PT Contoh Pelanggan",
          "peran": "pic_utama"
        }
      ],
      "pengajuan": null
    }
  }
}
```

- `kemampuan` = `pelanggan` (sudah ditautkan admin) atau `pelanggan:menunggu`
  (masih antre). Aplikasi memakai ini buat memilih beranda vs layar S06.
- `kedaluwarsa_pada` = **90 hari** sejak terbit. Token juga mati kalau **nganggur
  30 hari**. Dua-duanya membalas 401 dengan `kode` sendiri — jangan hapus draf
  lokal waktu itu terjadi.
- Gagal → **401 `kredensial_salah`**, dan pesannya sama persis buat "email tidak
  terdaftar" dan "sandi salah". Jangan bikin kalimat yang membedakan.

```json
{
  "kode": "kredensial_salah",
  "message": "Email atau sandi salah."
}
```

### `POST /auth/lupa-sandi` · `POST /auth/atur-ulang-sandi`

Throttle: `lupa-sandi` **3 per 15 menit per email** (ember kirim),
`atur-ulang-sandi` **10 per 15 menit per email** (ember periksa).

```json
{ "email": "budi@contoh.test" }
```

Selalu **200**, bahkan buat email yang tidak ada. Lalu:

```json
{ "email": "budi@contoh.test", "otp": "483920", "sandi": "SandiBaru#2026Sidik" }
```

Berhasil → **200**, dan **SEMUA sesi di semua perangkat dicabut**. Aplikasi wajib
menghapus token lokalnya dan kembali ke layar masuk.

---

## 2. Butuh token — akun yang masih menunggu verifikasi pun boleh

Token `pelanggan:menunggu` **boleh** menyentuh grup ini. Itu yang bikin layar S06
("menunggu verifikasi", tarik untuk refresh) bisa membaca statusnya sendiri.

### `GET /saya`

```json
{
  "data": {
    "id": "<int>",
    "nama": "Budi Menunggu",
    "email": "budi@contoh.test",
    "telepon": "+628123456789",
    "jabatan": "QA Manager",
    "status": "pending_verifikasi",
    "butuh_verifikasi": true,
    "keanggotaan": [],
    "pengajuan": {
      "status": "menunggu",
      "nama_perusahaan": "PT Klaim Pendaftar",
      "diajukan_pada": "<iso8601>",
      "diputus_pada": null,
      "alasan_tolak": null
    }
  }
}
```

Sesudah admin menolak:

```json
{
  "data": {
    "id": "<int>",
    "nama": "Budi Ditolak",
    "email": "budi@contoh.test",
    "telepon": "+628123456789",
    "jabatan": "QA Manager",
    "status": "pending_verifikasi",
    "butuh_verifikasi": true,
    "keanggotaan": [],
    "pengajuan": {
      "status": "ditolak",
      "nama_perusahaan": "PT Klaim Pendaftar",
      "diajukan_pada": "<iso8601>",
      "diputus_pada": null,
      "alasan_tolak": "Nama perusahaan tidak cocok dengan data kami."
    }
  }
}
```

`butuh_verifikasi` adalah jawaban SERVER buat "boleh buka beranda atau belum".
Jangan hitung sendiri dari `status` — daftar statusnya bisa bertambah.

### `PATCH /saya`

Body boleh sebagian: `nama`, `telepon`, `jabatan`. Field yang tidak dikirim
tidak berubah.

**`email` tidak bisa diganti sendiri** — dikirim pun diabaikan. Ganti email
lewat admin (M1-05).

### `POST /saya/ganti-sandi`

```json
{ "sandi_lama": "…", "sandi": "SandiBaru#2026Sidik" }
```

Berhasil → **200**, `data.sesi_dicabut` berisi jumlah sesi LAIN yang dicabut.
Sesi yang sedang dipakai **tetap hidup**, jadi aplikasi tidak perlu masuk ulang.

### `DELETE /saya` — REQ-AUTH-11

Throttle: **5 per menit per orang**.

```json
{ "sandi": "Kalibrasi#2026Sidik", "konfirmasi": true }
```

`konfirmasi` wajib `true` — konfirmasi KEDUA di luar sandi, karena ini tidak bisa
dibatalkan dan satu tap yang salah di layar HP tidak boleh cukup.

```json
{
  "message": "Akun Anda sudah dihapus.",
  "data": {
    "dihapus": [
      "nama",
      "email",
      "nomor HP",
      "jabatan",
      "seluruh sesi & perangkat"
    ],
    "tetap_tersimpan": [
      "data perusahaan",
      "alat",
      "permintaan kalibrasi",
      "sertifikat"
    ]
  }
}
```

**Layar konfirmasi WAJIB menyebut `tetap_tersimpan` sebelum tombolnya ditekan.**
Ambil kalimatnya dari balasan ini, jangan ditulis ulang di aplikasi — kalau
berbeda dari yang benar-benar dilakukan server, yang dijanjikan ke orangnya
bukan yang dia dapatkan.

Kenapa data itu tetap: perusahaan, alat, permintaan, dan sertifikat adalah
**rekaman laboratorium terakreditasi**. Sertifikat yang sudah terbit harus tetap
bisa ditelusuri ke alat dan sesi kalibrasinya selama masa simpan yang diwajibkan
ISO/IEC 17025. Yang hilang identitas orangnya, bukan jejak pengukurannya.

Boleh dipakai akun yang **masih menunggu verifikasi** juga — orang yang ditolak
tidak boleh terjebak dengan data pribadi yang tidak bisa dia cabut.

PIC utama **terakhir** BOLEH menghapus akunnya (beda dari `nonaktifkan` yang
menolak). Admin lab yang dikabari bahwa perusahaan itu jadi tanpa PIC utama.

Sesudah berhasil: seluruh sesi mati seketika. Aplikasi hapus token lokalnya dan
kembali ke layar sambutan.

### Halaman web hapus akun — REQ-PRV-03

`GET /hapus-akun` di domain yang sama (bukan di bawah `/api`). Wajib dicantumkan
di listing Play Store: Google menuntut URL yang bisa dibuka **tanpa memasang
aplikasinya**.

Halaman itu **tidak ikut mati** waktu `FITUR_PELANGGAN=false`.

### `POST /auth/keluar` · `POST /auth/keluar-semua`

`keluar` mencabut token yang sedang dipakai saja. `keluar-semua` mencabut
semuanya termasuk yang sekarang, dan memulangkan `data.sesi_dicabut`.

---

## 3. Butuh token DAN akun terverifikasi

Semua endpoint di bagian ini juga melewati **`KonteksPerusahaan`**, jadi tiga
aturan berlaku buat semuanya:

- **`X-Perusahaan-Id` menentukan perusahaan aktif.** Anggota SATU perusahaan
  boleh menghilangkannya — server memilihkan. Anggota lebih dari satu WAJIB
  mengirimnya, kalau tidak dapat **400 `perusahaan_belum_dipilih`** beserta
  `data.pilihan` yang siap ditampilkan sebagai pemilih.
- **Header yang bukan miliknya dijawab 404, bukan 403** — termasuk keanggotaan
  yang sudah dinonaktifkan. Jangan tampilkan "akses ditolak"; perlakukan seperti
  perusahaan yang tidak ada dan kembalikan ke pemilih.
- **Perusahaan tidak pernah dikirim di body atau di URL.** Server mengambilnya
  dari header saja.

### `GET /anggota` — REQ-ANG-03

Semua peran boleh melihat.

```json
{
  "data": {
    "anggota": [
      {
        "id": "<int>",
        "peran": "pic_utama",
        "status": "aktif",
        "bergabung_pada": "<iso8601>",
        "dinonaktifkan_pada": null,
        "orang": {
          "id": "<int>",
          "nama": "Budi PIC",
          "email": "budi@contoh.test",
          "telepon": "+628123456789",
          "jabatan": "QA Manager"
        },
        "saya": true
      },
      {
        "id": "<int>",
        "peran": "staf",
        "status": "aktif",
        "bergabung_pada": "<iso8601>",
        "dinonaktifkan_pada": null,
        "orang": {
          "id": "<int>",
          "nama": "Sari Staf",
          "email": "sari@contoh.test",
          "telepon": "+628123456789",
          "jabatan": "QA Manager"
        },
        "saya": false
      }
    ],
    "undangan_menunggu": [],
    "maks_anggota": 50,
    "saya": {
      "customer_id": "<int>",
      "member_id": "<int>",
      "peran": "pic_utama"
    }
  }
}
```

`undangan_menunggu` ikut supaya PIC utama tahu dia sudah mengundang seseorang —
tanpa itu dia mengundang lagi, dan undangan kedua **membatalkan** kode yang sudah
terkirim.

`saya` di tiap baris anggota dijawab server, jangan dihitung aplikasi dari id
yang disimpan lokal (nilai yang gampang basi sesudah ganti akun).

### `POST /anggota/undangan` — REQ-ANG-01 · **PIC utama saja**

Throttle: **5 per menit per orang** (lebih ketat dari jalur admin lab: tiap
undangan mengirim email ke alamat yang diketik pemanggil).

```json
{ "email": "rekan@contoh.test", "peran": "staf" }
```

`peran` boleh `staf` atau `pic_utama` — PIC utama memang boleh mengangkat PIC
utama lain, supaya perusahaan yang PIC-nya keluar kerja tidak perlu menelepon
lab.

### `DELETE /anggota/undangan/{id}` · **PIC utama saja**

Membatalkan, bukan menghapus — jejak "siapa mengundang siapa" tetap ada.

### `POST /anggota/{id}/nonaktifkan` — REQ-ANG-02 · **PIC utama saja**

Seluruh sesi orang itu dicabut di request yang sama, **kecuali** dia masih
anggota aktif di perusahaan lain (kasus konsultan) — lalu yang berubah cuma
`X-Perusahaan-Id` yang boleh dia pakai.

PIC utama **terakhir** tidak bisa menonaktifkan dirinya sendiri: `422
pic_utama_terakhir`. Arahkan dia mengangkat PIC utama lain dulu.



Token `pelanggan:menunggu` ditolak di SELURUH bagian ini dengan:

```json
{
  "kode": "akun_belum_diverifikasi",
  "message": "Akun Anda masih menunggu verifikasi PT Sidik. Kami kabari begitu selesai."
}
```

---

## 4. Sisi LAB — `/api/admin/...` (aplikasi internal, bukan aplikasi pelanggan)

Ditulis di dokumen ini, bukan di `docs/kontrak-api.md`, supaya satu modul
terbaca di satu tempat. Pemanggilnya **aplikasi internal** dengan token
ber-ability `internal` dan `role:admin` — base URL `/api`, bukan
`/api/pelanggan/v1`.

Rutenya sengaja **tidak** ikut mati waktu `FITUR_PELANGGAN=false`: antrean yang
telanjur berisi tetap harus bisa diputus.

| Metode | Path | Keterangan |
|---|---|---|
| GET | `/api/admin/pengajuan-akun` | `?status=menunggu` (default) · `disetujui` · `ditolak` |
| POST | `/api/admin/pengajuan-akun/{id}/setujui` | `{customer_id}` **atau** `{pelanggan_baru:{nama,…}}` — wajib salah satu |
| POST | `/api/admin/pengajuan-akun/{id}/tolak` | `{alasan}` minimal 10 karakter |
| POST | `/api/customers/{id}/undangan` | `{email, peran}` |
| DELETE | `/api/customers/{id}/undangan/{undanganId}` | Membatalkan, bukan menghapus |
| PATCH | `/api/customers/{id}/pic-admin` | `{user_id}` (boleh `null`) |

```json
{
  "data": [
    {
      "id": "<int>",
      "status": "menunggu",
      "diajukan_pada": "<iso8601>",
      "klaim": {
        "nama_perusahaan": "PT Klaim Pendaftar",
        "alamat_perusahaan": "Jl. Contoh No. 1, Bandung",
        "jabatan": "QA Manager"
      },
      "pemohon": {
        "id": "<int>",
        "nama": "Budi Menunggu",
        "email": "budi@contoh.test",
        "telepon": "+628123456789",
        "status": "pending_verifikasi"
      },
      "keputusan": null,
      "saran_pelanggan": [
        {
          "id": "<int>",
          "nama": "PT Klaim Pendaftar",
          "alamat": "Jl. Contoh No. 1, Bandung",
          "sama_persis": true
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

Tiga hal yang menentukan bentuk layarnya:

- **`klaim`, bukan `nama_perusahaan`.** Yang ada di situ nama yang DIKETIK
  pendaftar, bukan pelanggan yang sudah terverifikasi. Menyajikannya sebagai
  fakta menuntun admin menyetujui tanpa memeriksa — itu pintu R-D02.
- **`saran_pelanggan` itu saran, bukan pilihan.** Aturannya sama dengan impor
  pelanggan: badan usaha yang berbeda (`PT` vs `CV`) tidak pernah disarankan
  berpasangan, karena itu dua badan hukum dengan NPWP berbeda.
- **Admin WAJIB memilih.** Mengirim keduanya, atau tidak sama sekali, dijawab
  422. Tidak ada jalan "terima klaim apa adanya".

Dua admin menekan "setujui" bersamaan: yang kedua dapat **409 `sudah_diputus`**
beserta nama admin yang mendahului. Muat ulang antrean, jangan ulangi kirim.

## 5. Yang SENGAJA belum ada

Ditulis supaya tidak ditunggu:

| Belum ada | Kapan | Catatan |
|---|---|---|
| `POST/DELETE /perangkat` (FCM) | Fase 6 | `device_tokens.aplikasi` sudah ada |
| `/beranda`, `/alat`, `/sertifikat`, `/permintaan`, `/pesan` | Fase 6 | |
