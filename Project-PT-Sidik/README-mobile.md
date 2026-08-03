# ASMO Mobile

Aplikasi mobile (Flutter, satu APK) untuk kalibrasi alat ukur & sertifikat digital — teknisi dan admin pakai app yang sama, dibedakan lewat role. Dikembangkan selama program magang, dikerjakan berdua ([nama kamu] & [nama teman]).

## Tech Stack
- **Framework**: Flutter
- **State management**: Riverpod
- **OCR**: Google ML Kit Text Recognition (input kalibrasi via kamera)
- **Backend**: lihat repo [`asmo-api`](https://github.com/ZainulArkaanAlinsi/asmo-api)

## Fitur Utama
- Login & navigasi berbasis role (admin / teknisi / viewer) — satu app, tanpa web admin panel terpisah
- Dashboard, daftar alat, form tambah/edit alat per kategori
- Input kalibrasi manual **dan** via kamera/OCR (kamera mempercepat input, bukan menggantikan verifikasi — selalu ada layar review sebelum data tersimpan)
- Preview & unduh sertifikat PDF, verifikasi QR
- Riwayat kalibrasi & notifikasi jatuh tempo

## Setup Lokal

```bash
git clone https://github.com/ZainulArkaanAlinsi/asmo-mobile.git
cd asmo-mobile
flutter pub get
# isi API_BASE_URL sesuai flavor (dev/staging/prod)
flutter run --flavor dev
```

Pastikan `flutter doctor` sudah bersih (tanpa tanda silang merah) sebelum run pertama kali.

## Struktur Folder Penting

```
lib/
├── core/theme/       # design system (warna, tipografi, komponen dasar)
├── models/
├── services/
│   ├── api_service.dart
│   ├── auth_service.dart
│   └── ocr_service.dart
├── providers/        # Riverpod providers
├── screens/
│   ├── auth/
│   ├── dashboard/
│   ├── equipment/
│   ├── calibration/  # termasuk flow scan kamera & review hasil scan
│   ├── certificate/
│   └── profile/      # termasuk sub-menu admin
└── widgets/           # komponen reusable (button, badge, empty state)
```

## Prinsip Desain
- Bottom nav sama buat semua role — yang beda cuma isi tab **Profil** (admin dapat menu tambahan: Manajemen Pengguna, dst — disembunyikan total dari non-admin, bukan disabled)
- Field hasil scan kamera **wajib direview & dikonfirmasi** sebelum tersimpan — field kosong/gagal terbaca harus dilengkapi manual atau retake foto, tombol lanjut disabled sampai lengkap
- UI harus konsisten pakai design system dari awal — target akhirnya aplikasi ini mungkin ditawarkan ke perusahaan lain, jadi kualitas visual penting

## Git Workflow
Sama seperti [`asmo-api`](https://github.com/ZainulArkaanAlinsi/asmo-api) — `main` / `develop` / `feature/nama-fitur`, Conventional Commits, PR wajib direview.

## Status Project
Lihat rencana harian & progress lengkap di vault Obsidian (`Project-PT-ASMO/`).
