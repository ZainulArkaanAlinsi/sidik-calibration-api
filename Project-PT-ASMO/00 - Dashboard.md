---
aliases: [Dashboard]
---

# Dashboard — Project PT ASMO

Aplikasi kalibrasi alat ukur & sertifikat digital. Tim 2 orang, mulai 13 Juli 2026, target 2-3 bulan.

👉 [[01 - Ringkasan Project|Baca Ringkasan Project dulu kalau baru buka vault ini]]

## Progress Cepat
*(update manual tiap minggu — centang kalau minggu itu udah kelar)*

- [ ] [[Minggu 01 - Setup & Fondasi]] — 13-17 Jul
- [ ] [[Minggu 02 - Auth Lengkap, Role & Master Data]] — 20-24 Jul
- [ ] [[Minggu 03 - Data Alat & Kategori Kalibrasi]] — 27-31 Jul
- [ ] [[Minggu 04 - Input Kalibrasi Manual (Pipeline Dasar)]] — 3-7 Agu
- [ ] [[Minggu 05 - Kamera - OCR — Pilot (Prioritas Utama)]] — 10-14 Agu
- [ ] [[Minggu 06 - Perluasan OCR & Stabilisasi]] — 17-21 Agu
- [ ] [[Minggu 07 - Perhitungan Otomatis (GUM + ILAC-G8)]] — 24-28 Agu
- [ ] [[Minggu 08 - Approval & Generate Sertifikat]] — 31 Agu-4 Sep
- [ ] [[Minggu 09 - Laporan & Notifikasi Dasar]] — 7-11 Sep
- [ ] [[Minggu 10 - UI-UX Polish (Karena Target Akhirnya Mau Dijual)]] — 14-18 Sep
- [ ] [[Minggu 11 - Testing Menyeluruh]] — 21-25 Sep
- [ ] [[Minggu 12 - Build APK & Siap Demo]] — 28 Sep-2 Okt

## Hari Ini
Buka catatan harian sesuai tanggal di folder `03 - Catatan Harian/` — nama filenya format `YYYY-MM-DD`, jadi kalau pakai plugin Daily Notes bawaan Obsidian, arahin ke folder itu biar bisa langsung `Ctrl/Cmd+O` buka catatan hari ini.

## Referensi Teknis
- [[Data Kemampuan Kalibrasi]] — batas rentang ukur & ketidakpastian per kategori alat, dipakai buat validasi input
- [[Aturan Bisnis Inti]] — aturan penomoran sertifikat, GUM, ILAC-G8, role & akses

## Struktur Vault
```
Project-PT-ASMO/
├── 00 - Dashboard.md              ← kamu di sini
├── 01 - Ringkasan Project.md
├── 02 - Rencana Mingguan/         (12 file, 1 per minggu)
├── 03 - Catatan Harian/           (60 file, 1 per hari kerja)
└── 04 - Referensi Teknis/
```

## Cara Pakai
1. Tiap pagi, buka catatan hari itu di `03 - Catatan Harian/`
2. Kerjain task Backend & Mobile yang tertera, centang checkbox-nya
3. Sebelum pulang, isi bagian **Progress Hari Ini** — ini yang jadi bukti kemajuan harian ke atasan
4. Tiap Jumat, isi **Rekap Minggu** di file minggu terkait, lalu centang progress di Dashboard ini
5. Kalau ada yang meleset dari rencana (fitur ternyata beda, waktu meleset, dst), edit langsung catatan harian/mingguan terkait — vault ini hidup, bukan dokumen statis
