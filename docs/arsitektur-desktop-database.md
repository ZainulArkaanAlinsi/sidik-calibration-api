# Arsitektur Desktop & Database — supaya lab bisa pegang sendiri

Status: usulan arsitektur, 24 Juli 2026
Untuk: Raihan (full stack mobile/desktop) · Arkaan (full stack mobile/desktop)

> ## ⚠️ SEBAGIAN DOKUMEN INI SUDAH DIGANTI — baca ini dulu
>
> [`infrastruktur-vps-produksi.md`](infrastruktur-vps-produksi.md) (25 Juli 2026)
> **menggantikan Keputusan 2 & 3 di bawah.** Arahnya sekarang VPS: **tetap MySQL**
> (nggak pindah ke SQLite), dan desktop cukup jadi klien API biasa (nggak bungkus
> PHP ke dalam aplikasi).
>
> Jadi dua item di "Yang perlu dikerjakan backend" di bawah **jangan dikerjain**:
>
> - ~~nomor 1: pindah `DB_CONNECTION` ke `sqlite` + `PRAGMA journal_mode=WAL`~~
> - ~~nomor 4 bagian "cadangkan/pulihkan berkas SQLite"~~ (backup jadi urusan VPS)
>
> ✅ **27 Jul: `audit_logs` (fondasi Keputusan 4) udah jadi.** Jejak perubahan
> kecatat otomatis dari model event, jadi semua jalur ikut — API, panel Filament,
> queue, command. `GET /audit-logs` + ekspor CSV, admin doang & baca-saja; baris
> audit nggak bisa diubah/dihapus lewat model. Kontrak: [`kontrak-api.md` §11](kontrak-api.md).
>
> Yang **tetap berlaku** dan belum dikerjain: sisa Keputusan 4 (layar "Kelola Data"
> di desktop, perkakas perawatan, ekspor CSV per tabel — fondasi audit-nya udah
> ada), Keputusan 5 (rumus berversi), Keputusan 6 (tiga jalur impor data lama), dan
> batas peran AI. Status terverifikasi tiap item ada di
> [`BACA-DULU-BACKEND.md`](BACA-DULU-BACKEND.md).

## Yang harus dicapai

1. Jalan di **Windows & macOS** sebagai aplikasi desktop untuk admin.
2. **Database ada di dalam aplikasi** — tanpa phpMyAdmin, tanpa XAMPP, tanpa
   setup MySQL.
3. Admin bisa **membetulkan datanya sendiri** dari desktop.
4. Data lama dari perangkat mereka sekarang bisa **dipindah masuk**, satu per
   satu, tanpa merusak yang sudah ada.
5. **Rumus kalibrasi bisa diubah** sendiri kalau metodenya berubah.
6. Isi & datanya **sama dengan aplikasi mobile** (tampilan boleh beda).
7. Cepat.
8. Setelah proyek selesai, **mereka yang pegang semuanya**.

---

## Keputusan 1 — Satu database, bukan dua

Godaan pertama: bikin desktop punya database sendiri supaya cepat dan bisa
offline. **Jangan.**

Kalau desktop punya database sendiri dan mobile punya server sendiri, ada dua
sumber kebenaran. Teknisi menyetujui sesi di HP, admin melihat angka lama di
desktop — dan tidak ada cara memutuskan mana yang benar. Di lab terakreditasi
itu bukan sekadar merepotkan; itu temuan audit.

**Satu database. Desktop dan mobile membaca dan menulis ke tempat yang sama.**
Yang bikin desktop terasa cepat bukan database terpisah, tapi karena
databasenya ada di mesin yang sama (lihat Keputusan 3).

## Keputusan 2 — MySQL + phpMyAdmin diganti SQLite satu file

| | Sekarang | Usulan |
|---|---|---|
| Database | MySQL server | SQLite, satu berkas `sidik.sqlite` |
| Kelola | phpMyAdmin di browser | Menu **Kelola Data** di dalam aplikasi |
| Backup | export SQL | salin satu berkas |
| Yang harus dinyalakan | Apache + MySQL | tidak ada |
| Port | 3306 + 80 | tidak ada yang dibuka ke luar |

Laravel mendukung SQLite secara bawaan — migration, Eloquent, relasi, transaksi
semuanya jalan. Yang berubah cuma `DB_CONNECTION=sqlite` dan path berkasnya.

Nyalakan **mode WAL** (`PRAGMA journal_mode=WAL`) supaya baca dan tulis bisa
barengan. Untuk lab dengan belasan sampai puluhan pengguna aktif ini lebih dari
cukup — SQLite baru jadi masalah di beban tulis konkuren tinggi, dan lab
kalibrasi bukan itu.

**Yang harus dicek backend sebelum pindah** (Arkaan):

- Query mentah yang MySQL-only: `JSON_EXTRACT`, `GROUP_CONCAT` dengan
  `SEPARATOR`, `DATE_FORMAT`, `IF()`, `ON DUPLICATE KEY`.
- `groupBy` yang mengandalkan longgarnya `ONLY_FULL_GROUP_BY` MySQL.
- Kolom `enum` — di SQLite jadi teks; tambahkan validasi di aplikasi.
- Migration yang pakai `->after()` / `dropColumn` beruntun (SQLite lebih rewel;
  Laravel 11+ sudah jauh lebih baik, tapi tetap dijalankan sekali dari nol).
- Full-text search kalau ada → ganti `LIKE` atau FTS5.

Kalau ternyata ada yang tidak bisa dipindah, alternatifnya MariaDB portabel
yang ikut dibungkus. Tapi coba SQLite dulu — hasilnya jauh lebih sederhana
untuk mereka pegang.

## Keputusan 3 — Desktop membawa servernya sendiri

Aplikasi desktop bukan cuma tampilan. Waktu dibuka, dia:

1. menyalakan backend Laravel yang ikut dibungkus, di `127.0.0.1:8787`,
   sebagai proses anak;
2. menunggu sampai `/api/health` menjawab;
3. baru menampilkan jendela.

Waktu ditutup, prosesnya dimatikan.

Buat lab, itu **satu ikon aplikasi**. Tidak ada XAMPP, tidak ada "nyalakan
Apache dulu". Dan karena backend ada di mesin yang sama, panggilan API-nya
lewat loopback — tidak ada lompatan jaringan, jadi terasa instan.

HP teknisi tetap konek seperti sekarang: ke alamat IP PC itu di Wi-Fi yang
sama. Jadi PC admin sekaligus jadi servernya.

**Pembungkusan:**

| | Cara |
|---|---|
| macOS | PHP statis (FrankenPHP atau `static-php-cli`) di dalam `.app` → `Contents/Resources/server/` |
| Windows | PHP portabel (`php.exe`, ±30 MB, tanpa instalasi) di folder aplikasi |
| Menyalakan | `Process.start` dari Flutter, dimatikan di `exit` |
| Letak database | folder data pengguna, bukan folder aplikasi — supaya tidak hilang waktu aplikasi diperbarui |

**Bertahap, supaya tidak menghambat deadline:**

- **Tahap 1** — desktop konek ke server yang dinyalakan manual (persis seperti
  sekarang). Semua tampilan dan fiturnya sudah bisa dikerjakan dan dipakai.
- **Tahap 2** — bungkus servernya ke dalam aplikasi. Tidak ada satu pun layar
  yang perlu diubah; cuma cara menyalakannya.

## Keputusan 4 — "Kelola Data" menggantikan phpMyAdmin, tapi lewat pintu yang tercatat

Ini bagian yang perlu dibahas jujur. Permintaannya adalah admin bisa
membetulkan database sendiri. Itu benar dan memang membantu. Tapi kalau
caranya persis seperti phpMyAdmin — edit baris langsung, tanpa jejak — justru
berbahaya untuk lab terakreditasi: ISO/IEC 17025 mensyaratkan rekaman bisa
ditelusuri (siapa mengubah apa, kapan, dari nilai berapa ke berapa).
phpMyAdmin tidak mencatat itu sama sekali.

Jadi **Kelola Data** memberi keleluasaan yang sama, tapi setiap perubahan
tercatat:

- Jelajahi semua tabel: Alat, Pelanggan, Standar, Teknisi, Sesi Kalibrasi,
  Sertifikat, Folder, Arsip, Organisasi.
- Cari, saring, urutkan, ubah di tempat, hapus, ubah massal.
- Validasi yang dipakai **sama** dengan yang dipakai API — tidak ada pintu
  belakang yang bisa memasukkan data tak masuk akal.
- **Riwayat perubahan wajib**: pengguna, waktu, nilai lama → nilai baru,
  alasan. Bisa dilihat dan diekspor.
- **Sertifikat yang sudah terbit tidak bisa diedit.** Dia snapshot. Kalau
  salah: batalkan, terbitkan revisi dengan nomor baru. Ini justru yang diminta
  auditor.
- Perkakas perawatan: cek integritas, `VACUUM`, jalankan migrasi, pulihkan dari
  cadangan.
- Cadangan otomatis harian + otomatis sebelum operasi besar (impor, ubah
  massal, migrasi). Cadangan = salinan satu berkas, bisa mereka copy sendiri ke
  flashdisk.
- Ekspor CSV per tabel.

Hasilnya lebih berguna daripada phpMyAdmin, bukan lebih terbatas: mereka bisa
membetulkan apa saja, dan bisa membuktikan apa yang mereka betulkan.

## Keputusan 5 — Rumus kalibrasi bisa diubah, dan diberi versi

Rumus disimpan di database, bukan ditanam di kode. Admin bisa mengubahnya
sendiri lewat menu **Rumus Kalibrasi**.

Yang penting: **rumus itu berversi, dan tiap sesi kalibrasi mencatat versi
rumus yang dipakainya.** Kalau rumus diubah hari ini, sertifikat yang terbit
tahun lalu tidak boleh ikut berubah angkanya. Sertifikat sudah berupa snapshot
(`certificate_snapshot`), jadi fondasinya sudah ada.

Isi editornya:

- Daftar besaran: pH, massa, suhu, kelembaban, tekanan, dimensi.
- Variabel + satuannya (pembacaan standar, pembacaan alat, jumlah pengulangan,
  ketidakpastian baku standar, faktor cakupan…).
- Ekspresi: koreksi, simpangan baku, `u` tiap komponen, `U95`, faktor `k`,
  pembulatan, dan aturan lulus/tidak lulus.
- **Uji coba sebelum disimpan**: masukkan data contoh, lihat hasil sebelum dan
  sesudah berdampingan. Kalau berubah, kelihatan berubahnya di mana.
- Simpan = versi baru. Versi lama diarsipkan dan bisa dikembalikan.

Batasnya tegas: rumus dihitung mesin deterministik. **AI tidak pernah
menghitung angka yang masuk sertifikat.**

## Keputusan 6 — Tiga jalur memasukkan data lama

| Bentuk data lama | Jalur |
|---|---|
| Excel / CSV dari perangkat lama | **Seret & lepas** ke jendela desktop |
| Lembar kerja kertas / hasil pindai / foto | **AI kamera** (`/raw-measurements/extract-from-photo`) |
| Berkas jadi (PDF sertifikat lama, dokumen) | Salin ke **Arsip**, ditempelkan ke alat/pelanggan |

**Seret & lepas** memakai alur impor dua langkah yang sudah ada
(`POST /imports/excel`, `uji_coba: true` lalu `false`) — jadi ini menambah cara
memberi berkasnya, bukan mesin baru:

1. Lepas berkas di mana saja di jendela. Boleh banyak sekaligus; diantre satu
   per satu.
2. Kolom dipetakan otomatis. Kalau ada judul kolom yang tidak dikenal — dan
   berkas lama mereka pasti begitu — **AI mengusulkan pemetaannya**. Usul saja;
   admin yang menyetujui.
3. **Selalu uji coba dulu.** Tampilkan per baris: akan dibuat / diperbarui /
   dilewati, beserta alasannya. Plus kolom mana yang terpakai dan mana yang
   diabaikan.
4. Baru tombol **Terapkan**.
5. Satu impor = satu batch tercatat, jadi bisa **dibatalkan** kalau ternyata
   salah berkas.

Nomor 5 itu yang bikin mereka berani mencoba. Memindahkan data bertahun-tahun
itu menakutkan justru karena takut merusak; kalau bisa dibatalkan, ketakutannya
hilang.

## Batas peran AI

| Boleh | Tidak boleh |
|---|---|
| Membaca foto lembar kerja jadi angka, dengan tingkat keyakinan per sel | Menghitung nilai yang masuk sertifikat |
| Mengusulkan pemetaan kolom Excel yang judulnya tidak baku | Menyetujui sesi kalibrasi |
| Menandai kejanggalan ("koreksi titik ini jauh dari pola") | Menulis ke database langsung |
| Menyusun draf catatan/narasi | Mengubah rumus sendiri |

Angka yang dibaca AI selalu masuk sebagai **usulan yang bisa diedit**, dengan
sel berkeyakinan rendah ditandai — mekanismenya sudah ada
(`selRendahKeyakinan`, `TingkatKeyakinan`).

## Isi panel desktop = isi aplikasi mobile

Tampilan boleh beda; entitas, field, dan statusnya tidak boleh beda.

| Layar desktop | Sumber data | Status yang sah |
|---|---|---|
| Ringkasan | `DashboardSummary`: `totalAlat`, `alatOverdue`, `kalibrasiDraft`, `kalibrasiSelesai`, `menungguApproval`, `sertifikatBulanIni`, `totalSertifikat`, `grafikPekerjaan[]` | — |
| Antrean approval | `CalibrationHistoryItem` | `draft`, `menunggu_approval`, `disetujui`, `perlu_revisi` |
| Alat | `Equipment` | `aktif`, `overdue`, `nonaktif` |
| Sesi & perhitungan | `CalibrationDetail`, `Perhitungan`, `Validasi` | keputusan `pass` / `fail` |
| Sertifikat | `Certificate`, `CertificateSnapshot` | — |
| Folder & Arsip | `Folder`, `FolderFile`, `ArsipPerusahaan` | — |
| Pelanggan / Standar / Teknisi / Organisasi | `Customer`, `Standard`, `User`, `Organization` | peran: admin / teknisi / viewer |
| Impor | `HasilImport`, `BarisImport` | tindakan: buat / perbarui / lewati |

Semuanya lewat API yang sama dengan mobile. Tidak ada endpoint khusus desktop
kecuali yang memang cuma ada di desktop (Kelola Data, Rumus).

## Yang perlu dikerjakan backend

1. Pindah `DB_CONNECTION` ke `sqlite` + `PRAGMA journal_mode=WAL`; jalankan
   migrasi dari nol; perbaiki query MySQL-only (daftar di Keputusan 2).
2. Endpoint `GET /api/health` yang murah — dipakai desktop untuk tahu server
   sudah siap.
3. Tabel + endpoint **riwayat perubahan** (`audit_logs`): siapa, kapan, tabel,
   id, sebelum, sesudah, alasan.
4. Endpoint **Kelola Data**: daftar tabel, baca/ubah/hapus baris dengan
   validasi + audit, ekspor CSV, cek integritas, cadangkan, pulihkan.
5. Tabel **rumus berversi** + kolom `formula_version_id` di sesi kalibrasi;
   endpoint uji coba rumus (hitung ulang data contoh tanpa menyimpan).
6. Impor: kembalikan `batch_id`, tambahkan `DELETE /imports/{batch}` untuk
   membatalkan satu batch.
7. Impor: terima **banyak berkas** dan judul kolom tidak baku; sediakan
   endpoint usulan pemetaan kolom berbantuan AI (usul saja, tidak menyimpan).
8. Tiga endpoint `/arsip/*` yang masih kurang (lihat
   `docs/permintaan-backend-2026-07-24.md` §2b).

## Yang perlu dikerjakan mobile/desktop

1. Layar desktop: Ringkasan, Antrean, Alat, Sertifikat, Folder & Arsip, Master
   Data, **Impor (seret & lepas)**, **Rumus**, **Kelola Data**.
2. Tata letak adaptif — `NavigationRail` + panel ganda di layar lebar, navbar
   bawah di HP. Satu basis kode.
3. Paket `desktop_drop` untuk seret & lepas; `file_picker` sudah ada sebagai
   cadangan.
4. Penyala server bundled (`Process.start` + tunggu `/api/health`) — Tahap 2.
5. Halaman Kelola Data: penjelajah tabel + riwayat perubahan + cadangan.
