# Indeks Dokumen

Semua dokumen tetap di `docs/` (rata, tanpa subfolder) supaya tautan lama dari
kode dan chat nggak putus. Halaman ini cuma peta biar gampang nyari.

## Mulai dari sini

| Dokumen | Isi |
|---|---|
| [BACA-DULU-BACKEND.md](BACA-DULU-BACKEND.md) | Status backend dicek langsung ke kode, per 25 Juli 2026 |
| [Spesifikasi-Aplikasi-Kalibrasi.md](Spesifikasi-Aplikasi-Kalibrasi.md) | Spesifikasi produk secara keseluruhan |
| [kontrak-api.md](kontrak-api.md) | Kontrak API lengkap — acuan utama Mobile |

## Spesifikasi teknis

| Dokumen | Isi |
|---|---|
| [SPEC-ocr-template-lokal.md](SPEC-ocr-template-lokal.md) | OCR template lokal, pindai lembar kerja tanpa AI berbayar |
| [SPEC-turbidimeter-profile.md](SPEC-turbidimeter-profile.md) | Profil kalibrasi turbidimeter (alat ke-2) |
| [SPEC-vision-ai-worksheet-extraction.md](SPEC-vision-ai-worksheet-extraction.md) | Ekstraksi lembar kerja pakai AI vision |
| [SPEC-vision-prompt.md](SPEC-vision-prompt.md) | Prompt AI vision worksheet, siap tempel |
| [arsitektur-desktop-database.md](arsitektur-desktop-database.md) | Arsitektur desktop & database supaya lab pegang sendiri |
| [realtime-sync.md](realtime-sync.md) | Realtime sync Mobile ↔ Desktop |

## Handoff & perintah frontend

Urut dari yang paling baru.

| Dokumen | Alat / topik |
|---|---|
| [perintah-frontend-spectrophotometer.md](perintah-frontend-spectrophotometer.md) | Spectrophotometer |
| [handoff-backend-spectrophotometer.md](handoff-backend-spectrophotometer.md) | Spectrophotometer (sisi backend) |
| [perintah-frontend-ocr-lembar-kerja.md](perintah-frontend-ocr-lembar-kerja.md) | Pindai lembar kerja (OCR lokal) |
| [perintah-frontend-conductivity.md](perintah-frontend-conductivity.md) | Conductivity meter |
| [handoff-frontend-conductivity.md](handoff-frontend-conductivity.md) | Conductivity meter |
| [HANDOFF-FRONTEND-07-Agt-refractometer.md](HANDOFF-FRONTEND-07-Agt-refractometer.md) | Refractometer (alat ke-4) |
| [HANDOFF-FRONTEND-05-Agt.md](HANDOFF-FRONTEND-05-Agt.md) | Chlorine meter (alat ke-3) |
| [HANDOFF-FRONTEND-31-Jul.md](HANDOFF-FRONTEND-31-Jul.md) | Peringatan koreksi suhu & tata letak sertifikat |
| [HANDOFF-FRONTEND-30-Jul.md](HANDOFF-FRONTEND-30-Jul.md) | Koreksi suhu buffer pH |
| [HANDOFF-FRONTEND-29-Jul.md](HANDOFF-FRONTEND-29-Jul.md) | Perubahan API 29 Juli |
| [HANDOFF-BACKEND-29-Jul-adendum.md](HANDOFF-BACKEND-29-Jul-adendum.md) | Adendum handoff backend 29 Juli |
| [HANDOFF-FRONTEND-28-Jul.md](HANDOFF-FRONTEND-28-Jul.md) | Status API per 28 Juli |

## Permintaan dari Mobile ke Backend

| Dokumen | Isi |
|---|---|
| [permintaan-endpoint.md](permintaan-endpoint.md) | Permintaan endpoint baru |
| [permintaan-endpoint-fase-2.md](permintaan-endpoint-fase-2.md) | Fase 2 — peran, arsip, sertifikat lengkap, laporan |
| [permintaan-backend-2026-07-24.md](permintaan-backend-2026-07-24.md) | Permintaan dengan deadline 24 Juli 2026 |
| [permintaan-worksheet-ph.md](permintaan-worksheet-ph.md) | Worksheet pH biar persis Excel |

## Data lab & audit master

| Dokumen | Isi |
|---|---|
| [Rekap-Data-Kemampuan-Kalibrasi.md](Rekap-Data-Kemampuan-Kalibrasi.md) | Rekap CMC (kemampuan kalibrasi & pengukuran) |
| [audit-sumber-conductivity-refractometer.md](audit-sumber-conductivity-refractometer.md) | Audit sumber angka conductivity & refractometer |
| [temuan-sel-U95-chlorine-1.83.md](temuan-sel-U95-chlorine-1.83.md) | Temuan sel pengulangan U95 titik 1,83 mg/L |

## Pertanyaan yang masih nunggu jawaban lab

| Dokumen | Isi |
|---|---|
| [pertanyaan-lab-r2-spektro.md](pertanyaan-lab-r2-spektro.md) | Kolom R² blok %T (spectrophotometer) |
| [pertanyaan-lab-conductivity.md](pertanyaan-lab-conductivity.md) | Lembar kerja conductivity |

## Deploy & infrastruktur

| Dokumen | Isi |
|---|---|
| [deploy-gratis-render.md](deploy-gratis-render.md) | Deploy gratis lewat Render + Aiven |
| [CHECKLIST-DEPLOY-VPS.md](CHECKLIST-DEPLOY-VPS.md) | Checklist deploy ke VPS |
| [infrastruktur-vps-produksi.md](infrastruktur-vps-produksi.md) | Infrastruktur VPS produksi |

## Lain-lain

- [skrip/e2e-ph.py](skrip/e2e-ph.py) — skrip uji end-to-end pH. Bukan bagian
  aplikasi Laravel; `__pycache__` yang kebentuk waktu dijalanin sudah diabaikan
  di `.gitignore`.
