# 07 — Migrasi & Rollout Studio Data Acuan

Aturan yang mengikat: push ke `main` = deploy produksi; satu push, satu deploy, tunggu yang
sebelumnya terverifikasi (`gh run list` hijau **dan** `/api/health` memulangkan `deploy.versi`
yang cocok) — AGENTS.md §Git Workflow. Tulis ke produksi lewat urutan
`docs/aturan-akses-database.md`.

## 1. Sebelum apa pun

1. **Cadangan** database produksi + berkas privat (`storage`), diverifikasi bisa dipulihkan di
   mesin lokal. Catat nama berkas & sha256-nya di catatan rilis.
2. Sensus baca-saja (izin sekali, sebut tabel & perkiraan baris dulu):
   `uncertainty_calculations` (berapa baris, berapa ber-`formula_version_id`),
   `calibration_sessions` per status, `certificates` terbit. Per 2 Okt: 2 sesi nyata, 0 hasil
   hitung — angka ini harus diperbarui pada hari rilis, bukan dikutip.
3. Pastikan ≥ 1 akun `super_admin` yang **bisa login** dan bukan Master Data penyunting
   (ADR-05). Tanpa itu versi tidak akan pernah bisa disahkan.

## 2. Urutan deploy

| Tahap | Isi | Efek ke pengguna | Bisa dibatalkan dengan |
|---|---|---|---|
| 1 | Migrasi additive (T1.1) + model + izin, **tanpa** dipakai siapa pun | nol | `migrate:rollback` langkah itu (tabel baru kosong) |
| 2 | `acuan:impor-awal micrometer` di produksi → versi 1 `aktif`; laporan sha256 dicocokkan dengan JSON di repo | nol (angka identik) | hapus baris paket micrometer (belum ada stempel) |
| 3 | `TabelStandarMicrometer` membaca `SumberAcuan`; stempel `paket_acuan_versi_id` mulai ditulis | nol secara angka; jejak sesi menampilkan "Data acuan v1" | sakelar `DATA_ACUAN_SUMBER=json` (fallback baca JSON, stempel tetap ditulis) |
| 4 | API tulis + Studio desktop (rilis desktop) — **hanya untuk akun pilot** (izin `data-acuan.sunting` diberikan ke 1 Master Data, `sahkan` ke 1 SA) | pilot bisa membuat draf & simulasi; belum ada versi baru yang disahkan | cabut izin |
| 5 | Pengesahan versi nyata pertama (rekalibrasi standar Micrometer, T2.10) di **staging**, lalu di produksi | sesi Micrometer bertanggal ≥ `berlaku_mulai` memakai v2 | `tarik` v2 (FR-19) → v1 berlaku lagi |
| 6 | Alat berikutnya, satu per deploy (T3.7) | per alat | per alat, sakelar per profil |
| 7 | Sesudah 2 bulan stabil: `database/data/tabel-*.json` dijadikan fixture test saja (dipindah ke `tests/fixtures/acuan/`) | nol | — |

### 2a. Menjalankan tahap 2 di produksi tanpa shell

Paket gratis Render tidak punya shell ("Shell is not supported for free compute plans"), jadi
`acuan:impor-awal` dijalankan lewat boot, dengan sakelar `ACUAN_IMPOR_AWAL_ON_BOOT`
(`docker/entrypoint.sh`; bawaan `false` = tidak melakukan apa pun).

**Syarat — semuanya sebelum sakelar disentuh:**

1. §1 selesai: cadangan produksi terverifikasi + sensus baca-saja. Boot tidak bisa memeriksanya;
   yang menyalakan yang bertanggung jawab. Tahap 2 menulis ke produksi (tabel `paket_acuan` dan
   `paket_acuan_versi`), jadi urutannya mengikuti `docs/aturan-akses-database.md`.
2. Tahap 1 (migrasi) sudah live dan `/api/health` memulangkan `deploy.versi` yang cocok.
3. `DATA_ACUAN_SUMBER` masih `json` — tahap 2 tidak mengubah angka siapa pun.

**Langkah:**

1. Satu commit: `render.yaml` → `ACUAN_IMPOR_AWAL_ON_BOOT` `value: "true"` (nilainya dipatok
   blueprint, bukan dashboard — menyalakannya lewat commit yang ditinjau). Satu push, satu
   deploy (AGENTS.md §Git Workflow).
2. Buka deploy log, cari tahap `impor awal data acuan`. Per paket per organisasi tercetak
   `versi 1 dibuat (aktif, berlaku_mulai …), sha256 …` atau `versi 1 sudah ada, sama`. Cocokkan
   `sha256 kanonik` tiap `kode_profil` dengan JSON di repo (`JsonKanonik`).
3. Boot tetap lanjut walau impor gagal (`|| true`, kode keluarnya dicetak). Galat per paket
   (berkas hilang, paket soft delete, versi 1 lama **BEDA** dari berkas) tidak menimpa apa pun
   dan harus diputuskan manusia sebelum tahap 3.
4. **Commit berikutnya mengembalikan nilainya ke `"false"`.** Perintahnya aman diulang, tapi tiap
   boot yang masih `true` cuma memakan jendela health check Render yang 15 menit tanpa memberi
   apa-apa; entrypoint mencetak peringatan keras di tiap boot selama masih `true`.
5. Jalankan sekali lagi hanya bila perlu bukti idempotensi: semua baris harus `sudah ada, sama`.

**Rollback tahap 2** = hapus baris paket yang baru dibuat. Aman hanya selama belum ada hasil
hitung yang distempel `paket_acuan_versi_id` (tahap 3 belum jalan). Itu tulis ke produksi, jadi
lewat urutan `docs/aturan-akses-database.md` butir 4 (rencana tertulis → cadangan → dry-run →
eksekusi atas instruksi terpisah); mengembalikan sakelar ke `false` saja TIDAK menghapus apa pun.

Sakelar `DATA_ACUAN_SUMBER` wajib masuk `.env.example` **dan** `render.yaml` (AGENTS.md §Alur
Kerja 9). Nilainya di `render.yaml` ditulis `value:` eksplisit dengan komentar, supaya tidak
ketimpa diam-diam seperti kejadian `ARSIP_DRIVER` 1 Sep.

## 3. Migrasi data: versi 1

- Isi versi 1 dibangun dari `database/data/tabel-standar-micrometer.json` dengan
  `JsonKanonik` (angka → string persis seperti di JSON).
- Bukti setara: `TabelAcuanSetaraJsonTest` membaca kedua jalur dan membandingkan **setiap**
  float yang diberikan ke calculator, plus seluruh test Micrometer dijalankan dengan sumber DB.
- `berlaku_mulai` versi 1 = tanggal paling awal yang mungkin dipakai sesi (tanggal kalibrasi
  sesi tertua organisasi, atau 2024-01-01 bila tidak ada) supaya sesi lama juga punya versi
  berlaku. Ini **bukan** berlaku mundur dalam arti FR-18 karena isinya identik dengan yang dulu
  dipakai.
- Hasil hitung lama (bila sudah ada saat rilis): distempel v1 hanya bila sha256 JSON pada commit
  yang menghitungnya = sha256 v1. Selain itu dibiarkan `null` dan masuk laporan.

## 4. Rollback

| Masalah | Langkah |
|---|---|
| Angka beda sesudah tahap 3 | `DATA_ACUAN_SUMBER=json`, redeploy; selidiki `TabelAcuanSetaraJsonTest` |
| Versi baru salah sesudah disahkan | `tarik` (bukan hapus). Sesi yang belum terbit ditahan & dihitung ulang dengan versi sebelumnya; sertifikat terbit tidak berubah (BR-11) — bila terbukti salah, jalur revisi sertifikat §38 |
| Migrasi gagal di tengah | migrasi additive per tabel; rollback langkah terakhir; tidak ada tabel lama yang diubah |
| Studio desktop bermasalah | cabut izin `data-acuan.sunting`; server tetap jalan dengan versi aktif |

## 5. Kompatibilitas aplikasi lama

| Klien | Versi minimum yang aman | Catatan |
|---|---|---|
| APK teknisi | semua yang beredar | field tambahan diabaikan; tanpa `If-None-Match` tetap 200 |
| APK teknisi + override bentuk (fase 3) | `min_versi_klien` per kosakata field | Per 8 Okt server **tidak tahu** versi APK yang memanggilnya (tidak ada header versi; `VersiAplikasiController` hanya menyajikan info pembaruan). Jadi fase 3 butuh dulu header tambahan `X-Versi-Klien` dari HP (additive) + pencatatan versi terakhir per akun. Sebelum itu ada, editor bentuk hanya boleh memakai kosakata field yang sudah dikenal APK tertua yang masih dirilis |
| Desktop lama | semua | tidak punya menu Studio; tidak rusak |
| Pelanggan | — | tidak tersentuh |

## 6. Pemantauan sesudah rilis

- `/api/health` → `data_acuan.versi_aktif_tanpa_simulasi` harus 0.
- Log `Siaran perubahan data gagal.` (dari `siarkanAman`) untuk jenis `data_acuan`.
- Kueri harian: hasil hitung baru tanpa `paket_acuan_versi_id` untuk profil yang sudah termigrasi
  = **cacat jalur simpan**, bukan data lama (pola AGENTS.md §Olah data 2).
