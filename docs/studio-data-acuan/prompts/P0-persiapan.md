# P0 — Persiapan: pasang paket & catat keputusan

Repo: `sidik-calibration-api`. Tidak ada kode aplikasi yang diubah di prompt ini.

## Tugas

1. `git pull origin main`, buat branch `feat/studio-data-acuan`.
2. Pastikan `docs/studio-data-acuan/` berisi dokumen 00–08 + Lampiran A (sudah ada di branch
   `docs/studio-data-acuan` bila belum di-merge — ambil dari sana, jangan tulis ulang).
3. Salin dari ZIP: `katalog/` → `docs/studio-data-acuan/katalog/`, `alat-bantu/` →
   `docs/studio-data-acuan/alat-bantu/`, `prompts/` → `docs/studio-data-acuan/prompts/`.
4. Jalankan `python3 -I docs/studio-data-acuan/alat-bantu/validasi_katalog.py .` — harus
   melaporkan **cakupan 100%** untuk 21 paket ber-JSON dan **nol drift** antara katalog dan
   `database/data/`. Kalau ada drift (JSON berubah sesudah 8 Okt 2026), bangun ulang katalog
   dengan `bangun_katalog.py` + `tulis_kartu_prompt.py`, lalu laporkan paket mana yang berubah.
5. Tambahkan §48 di `docs/permintaan-user-7.md`: ringkasan CR-48 (rujuk
   `docs/studio-data-acuan/00-CR-Impact-Analysis.md`) + tabel keputusan K-48-01…07 beserta
   **jawaban pemilik proyek**. Jawaban yang belum ada → tulis "default dipakai" sesuai README.
6. Manifest workbook pilot: minta pemilik proyek menaruh 4 berkas `.xlsm` Micrometer di luar repo
   (mis. `C:\master-sidik\micrometer\`). Hitung sha256 tiap berkas dan tulis
   `database/data/manifest-workbook-micrometer.json` mengikuti pola
   `manifest-workbook-tekanan-piston.json`. **Sandi workbook tidak pernah ditulis ke repo, log,
   atau komentar.** Berkas `.xlsm` tidak di-commit.

## Jangan

- Mengubah kode di `app/`, migrasi, atau test.
- Menyalin isi sheet `DATABASE` atau baris pelanggan dari workbook.

## Selesai bila

Validator 100% & nol drift; §48 ada; manifest pilot ada (atau dicatat "menunggu berkas").
