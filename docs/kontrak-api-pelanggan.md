# Kontrak API — SIDIK Pelanggan (`/api/pelanggan/v1`)

> Versi 0.1 · 16 Sep 2026 · Cakupan: **M1-03, M1-04, M1-06** (auth & akun).
> Endpoint alat, sertifikat, permintaan, pesan, dan anggota menyusul di Fase 5–6.

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

### `POST /auth/keluar` · `POST /auth/keluar-semua`

`keluar` mencabut token yang sedang dipakai saja. `keluar-semua` mencabut
semuanya termasuk yang sekarang, dan memulangkan `data.sesi_dicabut`.

---

## 3. Butuh token DAN akun terverifikasi

Belum ada isinya di Fase 4. Grupnya sudah berdiri di server, dan begitu diisi
(Fase 5: `/beranda`, `/alat`, `/sertifikat`, `/permintaan`, `/anggota`) semuanya
otomatis menolak token `pelanggan:menunggu` dengan:

```json
{
  "kode": "akun_belum_diverifikasi",
  "message": "Akun Anda masih menunggu verifikasi PT Sidik. Kami kabari begitu selesai."
}
```

---

## 4. Yang SENGAJA belum ada

Ditulis supaya tidak ditunggu:

| Belum ada | Kapan | Catatan |
|---|---|---|
| `POST /auth/terima-undangan` | Fase 5 (M1-05) | Tabel `undangan_pelanggan` sudah berdiri |
| `DELETE /saya` (hapus akun) | Fase 5 | REQ-AUTH-11; `users.dianonimkan_pada` sudah ada |
| `POST/DELETE /perangkat` (FCM) | Fase 6 | `device_tokens.aplikasi` sudah ada |
| `/beranda`, `/alat`, `/sertifikat`, `/permintaan`, `/pesan` | Fase 5–6 | |
| Endpoint admin `/api/admin/pengajuan-akun` | Fase 5 (M1-05) | Scope `siapDitinjau()` sudah ada di server |
