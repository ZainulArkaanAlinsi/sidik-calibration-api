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
git clone https://github.com/ZainulArkaanAlinsi/asmo-api.git
cd asmo-api
composer install
cp .env.example .env
php artisan key:generate
```

Buat database, lalu sesuaikan kredensial di `.env`:

```sql
CREATE DATABASE asmo_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```env
DB_CONNECTION=mysql
DB_DATABASE=asmo_db
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
