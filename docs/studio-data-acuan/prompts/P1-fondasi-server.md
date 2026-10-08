# P1 — Fondasi server (Gelombang 1, tanpa UI)

Repo: `sidik-calibration-api`, branch `feat/studio-data-acuan`. Rujukan utama:
`docs/studio-data-acuan/03-SDD-ADR.md` (§2 model data, §3 kontrak API, §4 alur hitung,
ADR-02/03/04/08), `05-Task-Breakdown.md` T1.1–T1.11, `06-Test-Plan.md`.
Skill repo yang wajib dipakai: `[[sidik-data-layer]]`, `[[sidik-query-organisasi]]`,
`[[sidik-kalkulasi-presisi]]`, `[[sidik-test-verifier]]`.

## Gerbang masuk

P0 selesai. Tulis dulu daftar berkas yang akan dibuat/diubah, lalu tunggu "lanjut" bila
daftar itu menyentuh lebih dari kelas Micrometer + berkas baru.

## Tugas (urut)

1. **Migrasi additive** (T1.1): `paket_acuan`, `paket_acuan_versi`, `paket_acuan_suntingan`,
   `simulasi_acuan`, `simulasi_acuan_sesi`, `unggahan_workbook`; kolom nullable
   `uncertainty_calculations.paket_acuan_versi_id` (`nullOnDelete`) dan
   `calibration_sessions.bentuk_versi_id`. Nama index ≤ 64 karakter, eksplisit.
   `migrate` dan `migrate:rollback` bersih di SQLite & MySQL.
2. **Model** (T1.2): trait `Diaudit`; `PaketAcuanSuntingan` append-only (update/delete dilempar);
   semua tersaring `organization_id`.
3. **`JsonKanonik`** (T1.3): kunci terurut, angka sebagai string persis, sha256 stabil.
   Test: urutan kunci acak → sha256 sama; SQLite vs MySQL → sama.
4. **`CalibrationProfile::skemaAcuan(): ?array`** bawaan `null`; isi untuk `MicrometerProfile`
   dari `docs/studio-data-acuan/katalog/paket/micrometer/skema.json` (T1.4). Satuan dari kode &
   komentar `TabelStandarMicrometer`, bukan `satuan_tebakan`.
5. **`php artisan acuan:impor-awal {profil?}`** (T1.5): versi 1 `aktif` dari JSON; idempoten;
   menolak bila paket sudah punya versi; mencetak sha256. `berlaku_mulai` mengikuti 07 §3.
6. **`SumberAcuan`** (T1.6): memuat isi versi tertentu, cache per (versi_id, sha256).
   `TabelStandarMicrometer` membaca darinya; `private static ?array $data` dihapus.
   Sakelar env `DATA_ACUAN_SUMBER=db|json` (masuk `.env.example` DAN `render.yaml`, `value:` eksplisit).
7. **`PenentuVersiAcuan`** (T1.7) pola `RumusKalibrasi::versiUntukSesi()`: versi berlaku pada
   `tanggal_kalibrasi`. Stempel di SEMUA jalur simpan hasil hitung.
8. **`CalibrationValidator` & `HitungUlangSesi`** (T1.8) memakai versi dari stempel.
9. **Izin** (T1.9) di `App\Services\MatriksIzin::PETA`: `data-acuan.lihat|sunting|impor|ajukan|sahkan`,
   `bentuk-lembar.sunting`; jalankan `php docs/skrip/gen-nama-izin-mobile.php`.
10. **API baca** (T1.10): `GET /api/data-acuan/paket`, `/paket/{kode}`, `/paket/{kode}/berlaku`,
    `/paket/{kode}/versi`, `/versi/{versi}`, `/versi/{a}/banding/{b}`, riwayat sel. Teknisi: hanya
    versi aktif. Organisasi lain → 404.
11. **Kontrak lembar kerja** (T1.11): `GET /calibrations/lembar-kerja` + `versi_acuan` + `ETag`;
    perhitungan/validasi/snapshot + `paket_acuan_versi`. Field lama identik byte (test).
12. Perbarui `docs/kontrak-api.md` (§ baru) dan `docs/BACA-DULU-BACKEND.md` (status).

## Test wajib (nama mengikuti 06 §1)

`TabelAcuanSetaraJsonTest` (kasus micrometer), `CacheAcuanPerVersiTest`, `VersiAcuanBerlakuTest`,
`StempelAcuanTest`, `IzinDataAcuanTest`, `LembarKerjaVersiAcuanTest`, plus regresi 06 §2 —
**tanpa mengubah satu pun test lama**.

## Jangan

Menyentuh rumus/kalkulator; memindahkan alat selain Micrometer; membuat endpoint tulis (itu P2);
push ke `main`.

## Selesai bila

Suite SQLite **dan** MySQL hijau; `acuan:impor-awal micrometer` di DB lokal mencetak sha256 sama
dengan `JsonKanonik(tabel-standar-micrometer.json)`; laporan sesuai format induk.
