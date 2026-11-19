# Kesehatan, Sentry, dan saklar fitur (paket OPS-KODE, 19 Nov 2026)

Tiga perubahan kode dari keputusan pemilik 19 Nov 2026 (peringatan lewat Telegram dan surel, Sentry dengan
privasi keras, saklar fitur tanpa deploy). Dasar: `perawatan-setelah-live.md` (PR #285) dan `sasaran-layanan.md`.
Kontrak API ringkas ada di `docs/kontrak-api.md` (bagian "Operasional"), kontrak frontend saklar di
`docs/perintah-frontend-saklar-fitur.md`.

## 1. Kesehatan di `/api/health`

`GET /api/health` (tanpa auth) mendapat blok `kesehatan`. Pemantau uptime luar (tiap 5 menit) membaca **kode
HTTP** dan **kata kunci `"status":"ok"`**.

| Kode alasan | Artinya | HTTP | Kondisi (ambang di `config/kesehatan.php`) |
|---|---|---|---|
| `db_gagal` | `select 1` gagal | **503** | koneksi database putus |
| `penyimpanan_gagal` | disk arsip tidak bisa ditulis-dihapus | **503** | tulis berkas kecil ke disk `arsip` gagal |
| `antrean_macet` | worker antrean tidak bekerja | 200 | job tertua yang belum jalan > 1800 detik, atau job gagal >= 10 dalam 24 jam, atau tabel antrean tidak terbaca |
| `sertifikat_menggantung` | PDF tidak kunjung jadi | 200 | `menunggu_generate`, tanpa PDF, sesi masih disetujui, diam > 60 menit |

Sehat: `status` = `ok`, `kesehatan.alasan` = `[]`. Bila ada alasan: `status` = `degraded`.

Hal yang sengaja begitu:

- **Kata kunci hanya muncul sekali.** Blok bersarang memakai `ok`/`gagal` tanpa kunci `status`, jadi `degraded`
  tidak pernah memuat `"status":"ok"`.
- **Tidak ada rincian.** Nama tabel, jalur berkas, dan pesan galat tidak ikut; galat hanya ke log internal
  (`Health: pemeriksaan gagal.`, nama kelas dan pesan dipotong).
- **Murah.** Satu `select 1`, satu agregat `jobs`, satu hitung `failed_jobs`, satu hitung `certificates`, satu
  tulis-hapus berkas kecil di disk lokal. Tidak ada baris yang ditarik ke PHP; jumlah query tidak tumbuh dengan
  isi tabel (dites). Di MySQL tiap agregat membawa petunjuk `MAX_EXECUTION_TIME(2000)`.
- **`failed_jobs` dihitung dalam jendela 24 jam.** Tabel itu tidak dibersihkan apa pun dan Render Free tidak
  punya shell untuk `queue:flush`; tanpa jendela, sepuluh kegagalan lama membuat `degraded` menetap.
- **Disk arsip di `s3`/R2 dilaporkan `dilewati`**, tidak ditulisi: endpoint ini publik, dan satu tulis per
  ketukan ke bucket berbayar adalah tuas kuota (aturan lama blok `health`: nol request ke penyedia). Kegagalan
  R2 tampak lewat jalur lain (`/api/health` -> `deploy.arsip.awet` dan galat unggah).
- **Sertifikat menggantung memakai definisi `sertifikat:sapu-tertunda`** (sesi harus `disetujui`). Sertifikat
  yang sesinya sengaja ditarik tidak akan pernah selesai sendiri dan tidak dihitung, supaya status tidak menetap.
- **Aplikasi mobile**: `degraded` dengan HTTP 200 tidak mengubah apa pun bagi klien; `503` hanya saat database
  atau penyimpanan benar-benar rusak.

Menyiapkan pemantau: URL `/api/health`, interval 5 menit, kata kunci `"status":"ok"` (jangan
`status":"ok"` saja), alert Telegram dan surel. Tiap alert wajib punya langkah di `runbook-insiden.md`:
`db_gagal` -> bagian database; `penyimpanan_gagal` -> cek `ARSIP_DRIVER` dan disk; `antrean_macet` -> container
tertidur atau worker mati (lihat entrypoint), lihat log `queue:work`; `sertifikat_menggantung` -> tunggu penyapu
10 menit, lalu cek log.

## 2. Sentry (API)

Status: **terpasang, nonaktif** sampai DSN diisi. Paket `sentry/sentry-laravel` 4.30.0 (MIT) dengan
`sentry/sentry` 4.37.1 (MIT); tambahan transitif `jean85/pretty-package-versions`, `nyholm/psr7`,
`symfony/options-resolver`, `symfony/psr-http-message-bridge` (semuanya MIT). Tidak ada paket lama yang berubah
versi, dan tidak ada advisori untuk paket baru (`composer audit`).

**Menyalakan** (pemilik, per kejadian): setel env Render `SENTRY_LARAVEL_DSN` (rahasia, `sync: false`, jangan
ditulis ke repo). Kosong = tidak ada pengiriman dan tidak ada sambungan jaringan. Env lain milik paket
(`SENTRY_SEND_DEFAULT_PII`, `SENTRY_TRACES_SAMPLE_RATE`, ...) **tidak dibaca**: pengaturan privasi tidak bisa
digeser dari dashboard.

Privasi keras, tiga lapis:

1. `config/sentry.php`: `send_default_pii=false`, `max_request_body_size='none'`, tanpa cookie/header
   otorisasi, tanpa pelacakan/profil/log/metrik, tanpa kode sumber (`context_lines=null`), remah hanya SQL
   tanpa binding, batas waktu kirim 2 detik, `server_name` tetap, rilis = commit (`deploy.versi`) dan
   lingkungan = `APP_ENV`.
2. `App\Support\PenyaringSentry` (`before_send`, `before_breadcrumb`): daftar izin, bukan larangan. Membuang
   pengguna, seluruh `request` kecuali metode, seluruh `extra`, konteks selain `trace`/`runtime`/`os` (di sinilah
   `Context` Laravel dan `exception->context()` mendarat), tag di luar daftar izin, variabel dan baris kode
   frame, dan remah selain SQL. Menyamarkan di pesan: surel, token Bearer, token Sanctum, nomor sertifikat,
   string buram panjang (token QR, hash), IP, dan seluruh `(Connection: ..., SQL: ...)` pada `QueryException`.
   **Pesan driver DB dipotong sesudah kode galatnya**: `Duplicate entry '1-PT Maju Jaya' for key ...` dan
   `Incorrect decimal value: '12,345' ...` memantulkan NILAI kolom (nama pelanggan, nomor seri, bacaan lembar),
   jadi dari teks apa pun yang memuat `SQLSTATE[` hanya `SQLSTATE[23000]: Integrity constraint violation: 1062`
   (atau `SQLSTATE[HY000] [2002]`) yang tinggal, sisanya `[rincian disamarkan]`. Berlaku juga untuk `previous`
   bersarang, pesan peristiwa, dan `logentry`.
3. Tes (`SentryPrivasiTest`, `PenyaringSentryTest`, `SentryKonfigurasiTest`) membaca muatan yang BENAR-BENAR
   sampai ke transport palsu, dengan data sensitif di semua tempat yang diketahui.

Galat yang tidak dilaporkan: `ValidationException`, `AuthenticationException`, `AuthorizationException`,
`ModelNotFoundException`, `NotFoundHttpException`, `ThrottleRequestsException`, dan setiap HTTP 4xx.

**Batas yang tidak bisa dijamin pola:** teks bebas yang ditulis kode kita sendiri ke pesan `throw` (mis. nama
pelanggan) tidak punya bentuk yang dikenali. Aturan penulis kode: jangan menyisipkan data pelanggan/lembar ke
pesan pengecualian. Tes lingkungan: `APP_ENV=testing` memaksa DSN `null`, jadi suite tidak pernah mengirim
walau `.env` pengembang berisi DSN.

## 3. Saklar fitur

Super Admin mematikan fitur opsional lab tanpa deploy: `PUT /api/organization/fitur`. Disimpan di
`organizations.settings.fitur_nonaktif` (tanpa migrasi), per organisasi. Daftar awal: `pindai_lembar` dan
`kirim_email_sertifikat`; **pengisian, hitung, persetujuan, dan penerbitan tidak bisa dimatikan** (validasi
menolak, dan sapuan rute di `SaklarFiturTest` merah bila rute inti diberi `fitur:`). `arsip_unduh_massal` dari
rencana awal tidak dibuat: rute unduh massal belum ada.

- Rute fitur yang mati menjawab `503 fitur_dimatikan` (sesudah cek peran, sebelum route model binding).
- Jejak: baris `audit_logs` `fitur_dimatikan`/`fitur_dinyalakan` per fitur dengan daftar lama/baru, pelaku,
  alasan (5-400 karakter), di samping baris `diubah` otomatis untuk kolom `settings`.
- Admin (Master Data) tidak punya jalan ke saklar ini; `PUT /organization` menolak kuncinya dan tidak
  menghapusnya.
- Menambah kode fitur baru = PR yang kelihatan (lihat docblock `App\Support\FiturSaklar`).
- Aplikasi menyembunyikan tombol lewat `GET /me/permissions` -> `fitur_nonaktif` (UI saklar di aplikasi:
  paket terpisah).

## 4. Yang belum dan yang perlu pemilik

- `SENTRY_LARAVEL_DSN` belum terdaftar di `render.yaml` dan `.env.example` (lihat laporan paket: harus
  ditambahkan bersamaan; `BlueprintDanEnvExampleSejalanTest` menjaga keduanya). Menyalakan Sentry tetap bisa
  lewat dashboard Render, tetapi daftarkan di keduanya supaya tidak tertimpa sinkron blueprint.
- Pemantau uptime luar dan bot Telegram: akun pemilik.
- Sentry Flutter dan UI saklar di aplikasi: paket Lab terpisah.
- Gerbang suite MySQL penuh tetap wajib sebelum modul disebut selesai.
