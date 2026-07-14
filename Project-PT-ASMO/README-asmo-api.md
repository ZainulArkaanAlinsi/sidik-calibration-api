# ASMO API

Backend untuk aplikasi kalibrasi alat ukur & penerbitan sertifikat digital — dikembangkan selama program magang, dikerjakan berdua ([nama kamu] & [nama teman]).

## Tech Stack
- **Framework**: Laravel
- **Database**: MySQL
- **Auth**: Laravel Sanctum (token Bearer). Bukan JWT — token Sanctum nggak kadaluarsa, jadi nggak ada endpoint `/refresh`
- **Queue**: buat proses berat (generate PDF sertifikat, notifikasi) — jangan jalan sinkron di request cycle

## Fitur Utama
- Login & role-based access (admin / teknisi / viewer)
- Master data (perusahaan, pelanggan) & data alat per kategori
- Input hasil kalibrasi (manual & via kamera/OCR)
- Perhitungan otomatis ketidakpastian pengukuran (metodologi GUM) & keputusan PASS/FAIL (ILAC-G8)
- Approval & generate sertifikat PDF otomatis + QR code terenkripsi
- Laporan riwayat & rekap kalibrasi

## Setup Lokal

```bash
git clone https://github.com/USERNAME/asmo-api.git
cd asmo-api
composer install
cp .env.example .env
php artisan key:generate
# isi .env: DB_*, QR_ENCRYPTION_KEY (unik, jangan disamain sama staging/production)
php artisan migrate --seed   # bikin 1 organisasi + akun admin awal
php artisan serve
```

Cek `http://127.0.0.1:8000` — kalau muncul halaman default Laravel, backend siap jalan.

## Struktur Folder Penting

```
app/
├── Http/Controllers/Api/
├── Http/Requests/        # validasi input
├── Http/Resources/       # format response API
├── Models/
├── Services/             # business logic (jangan numpuk di controller)
│   ├── CertificateGeneratorService.php
│   ├── UncertaintyCalculatorService.php
│   └── ValidationService.php
└── Jobs/                 # proses async (generate PDF, notifikasi)
```

## Aturan Bisnis Kunci
- Nomor sertifikat: `CAL/{tahun}/{bulan}/{4 digit}`, unik lewat **database transaction locking** (bukan cuma unique constraint)
- Sertifikat yang sudah terbit tidak boleh diedit langsung — revisi = entry baru (`revision_of`)
- Ketidakpastian: Type A + Type B → combined → expanded (`U = k × u_c`)
- Sertifikat FAIL tetap sah diterbitkan — statusnya aja beda

Detail lengkap ada di vault Obsidian project (`04 - Referensi Teknis/Aturan Bisnis Inti.md`).

## Git Workflow
- `main` — production only, nerima merge dari `develop`
- `develop` — staging, tempat semua fitur digabung
- `feature/nama-fitur` — kerja harian, satu branch per task
- Commit convention: `feat:`, `fix:`, `docs:`, `test:` (Conventional Commits)
- PR wajib direview 1 orang sebelum merge ke `develop`

## Testing
```bash
php artisan test
```
Unit test wajib buat logic di `Services/`, terutama kalkulasi ketidakpastian & validasi PASS/FAIL — PR nggak boleh di-merge kalau test gagal.

## Status Project
Lihat rencana harian & progress lengkap di vault Obsidian (`Project-PT-ASMO/`).
