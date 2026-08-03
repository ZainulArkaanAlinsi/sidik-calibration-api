# ASMO API

Backend REST API (Laravel) untuk aplikasi kalibrasi alat ukur & sertifikat digital PT ASMO. Melayani aplikasi mobile [`asmo-mobile`](https://github.com/ZainulArkaanAlinsi/asmo-mobile) — tidak ada web admin panel terpisah, semua konsumsi data lewat API ini.

## Tech Stack
- **Framework**: Laravel 13 (PHP 8.3)
- **Auth**: Laravel Sanctum (token-based, dipakai dari mobile)
- **Database**: MySQL 8.0
- **Mobile client**: lihat repo [`asmo-mobile`](https://github.com/ZainulArkaanAlinsi/asmo-mobile)

## Fitur Utama (rencana)
- Autentikasi & otorisasi berbasis role: **admin** (semua akses), **teknisi** (input alat & kalibrasi), **viewer** (read-only)
- Master data: PT/organisasi, alamat, pelanggan, kategori & data alat ukur
- Input hasil kalibrasi — manual maupun hasil OCR dari mobile (keduanya lewat pipeline yang sama)
- Perhitungan otomatis ketidakpastian (**GUM**) & keputusan PASS/FAIL (**ILAC-G8**)
- Approval & generate sertifikat PDF + QR code, dengan endpoint verifikasi publik (tanpa login)
- Laporan (riwayat, rekap, export) & notifikasi jatuh tempo kalibrasi

## Setup Lokal

```bash
git clone https://github.com/ZainulArkaanAlinsi/sidik-calibration-api.git
cd sidik-calibration-api
composer install
cp .env.example .env
php artisan key:generate
```

Buat database, lalu sesuaikan kredensial di `.env`:

```sql
CREATE DATABASE sidik_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```env
DB_CONNECTION=mysql
DB_DATABASE=sidik_db
DB_USERNAME=root
DB_PASSWORD=<password mysql lokal kamu>
```

Jalankan migrasi & server:

```bash
php artisan migrate
php artisan serve
```

API tersedia di `http://localhost:8000/api`. Health check: `GET /up`.

> Kalau mobile dites di HP fisik, `API_BASE_URL` di app harus diarahkan ke IP LAN laptop (mis. `http://192.168.1.10:8000/api`), bukan `localhost` — dan server dijalankan dengan `php artisan serve --host=0.0.0.0`.

## Kerja Berdua — Database Bersama (LAN)

Tim ini pakai **satu database bersama** yang ada di laptop Zainul, biar data yang dilihat berdua persis sama. Zainul connect ke `127.0.0.1`, Raihan connect lewat IP LAN.

**`.env` Raihan** (sisanya sama):

```env
DB_CONNECTION=mysql
DB_HOST=192.168.1.46      # IP laptop Zainul — cek ulang pakai `ipconfig` kalau ganti wifi
DB_PORT=3306
DB_DATABASE=sidik_db
DB_USERNAME=asmo_dev      # user khusus LAN, bukan root
DB_PASSWORD=AsmoDev#2026
```

Masing-masing tetap jalanin `php artisan serve` sendiri di laptopnya — yang dibagi cuma databasenya, bukan servernya.

### Syaratnya: harus SATU jaringan yang sama

| Situasi | Bisa? |
|---|---|
| Berdua di kantor, satu wifi | ✅ Bisa |
| Berdua di rumah salah satu, satu wifi | ✅ Bisa |
| Berdua di kafe, satu wifi | ✅ Bisa |
| Zainul di rumahnya, Raihan di rumahnya (wifi beda) | ❌ **Nggak bisa** |

"Satu wifi" artinya benar-benar **nyambung ke router yang sama**, bukan sekadar sama-sama pakai wifi. Kalau beda rumah, laptop Zainul nggak bisa dihubungi dari luar (kehalang NAT/router). Kalau nanti perlu kerja dari rumah masing-masing, pindahkan DB ke cloud (Railway/Aiven) atau pakai VPN mesh (Tailscale).

User MySQL `asmo_dev` sudah dibolehkan dari semua subnet privat umum (`192.168.%`, `10.%`, `172.16.%`), jadi ganti wifi nggak masalah — **yang wajib diupdate cuma `DB_HOST`**, karena IP laptop Zainul berubah tiap ganti jaringan. Cek dengan `ipconfig` di laptop Zainul, lalu Raihan update `DB_HOST` di `.env`-nya.

### ⚠️ Aturan wajib kalau DB dipakai bareng
- **JANGAN `php artisan migrate:fresh` / `migrate:refresh` / `db:wipe`** — perintah itu menghapus SEMUA tabel, dan karena databasenya bersama, data yang kehapus bukan cuma punyamu tapi punya berdua.
- `php artisan migrate` **cukup dijalankan satu orang** (siapa pun yang bikin migration-nya). Yang lain tinggal `git pull` — skemanya sudah keburu ke-apply di DB bersama.
- Laptop Zainul harus **nyala dan sejaringan** biar Raihan bisa connect. Kalau Zainul pulang duluan / laptopnya mati, Raihan sementara nggak bisa akses DB.
- Error `SQLSTATE[HY000] [2002]` (connection refused/timeout) di sisi Raihan? Urutan ngecek: (1) laptop Zainul nyala & sejaringan? (2) `DB_HOST` masih IP yang benar? cek `ipconfig`.

## Konvensi API
- Semua endpoint di-prefix `/api` (lihat `routes/api.php`)
- Autentikasi pakai Bearer token Sanctum: header `Authorization: Bearer <token>`
- Response selalu JSON — error pun JSON, bukan halaman HTML (sudah diatur di `bootstrap/app.php`)

## Aturan Bisnis Penting
Detail lengkap ada di vault Obsidian (`Project-PT-ASMO/04 - Referensi Teknis/`). Ringkasnya:
- Nomor sertifikat format `CAL/{tahun}/{bulan}/{urutan 4 digit}` — keunikan dijaga lewat **database transaction locking**, bukan cuma unique constraint
- Sertifikat yang sudah terbit **tidak bisa diedit** — revisi lewat entry baru yang terhubung ke sertifikat asal
- Hasil kalibrasi FAIL tetap disimpan dan tetap bisa diterbitkan sertifikatnya — yang beda cuma statusnya
- Validasi rentang ukur & ketidakpastian mengacu ke data CMC (lihat `Data Kemampuan Kalibrasi`)

## Git Workflow
- Branch: `main` (rilis) / `develop` (integrasi) / `feature/nama-fitur`
- Commit pakai [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `refactor:`, `docs:`, …)
- PR ke `develop` wajib direview sebelum merge

## Status Project
Rencana harian & progress lengkap ada di vault Obsidian (`Project-PT-ASMO/`).
