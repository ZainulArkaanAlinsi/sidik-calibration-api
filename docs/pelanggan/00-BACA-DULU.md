# SIDIK Pelanggan — Paket Dokumen Perencanaan

> Versi 0.1 (draf) · 16 Sep 2026 · Disusun dari pembacaan kode `sidik-calibration-api` (commit `3972a42`) dan `sidik-calibration-mobile`.
> Status: **Usulan**. Semua keputusan bertanda "PT Sidik" belum boleh dianggap final sebelum disetujui.

Paket ini buat role ketiga CertiCal: **aplikasi pelanggan** yang dipasang perusahaan-perusahaan se-Indonesia lewat Google Play. Pelanggan mencatat alat ukur miliknya, mengajukan kalibrasi (onsite atau kirim ke lab), memantau prosesnya, mengunduh sertifikat, dan menerima pengingat jadwal kalibrasi ulang.

## Urutan baca

| # | Berkas | Isinya | Wajib dibaca oleh |
|---|---|---|---|
| 00 | `00-BACA-DULU.md` | Ringkasan keputusan, hal mendesak, keputusan yang harus diambil PT Sidik | Semua |
| 01 | `01-PRD.md` | Tujuan, non-tujuan, persona, fitur MVP, alur besar | Semua |
| 02 | `02-SRS.md` | Requirement Given/When/Then, aturan bisnis, NFR | Dev, QA, Admin lab |
| 03 | `03-SDD.md` | Arsitektur, ERD, state machine, spesifikasi API, notifikasi, keamanan | Dev |
| 04 | `04-UI-UX-Flow.md` | Alur layar, daftar layar + state, copy status | Dev mobile, desain (Stitch) |
| 05 | `05-Task-Breakdown.md` | Milestone M0–M8, tiap task punya DoD/Estimasi/Dependency/Owner/Link | Dev, supervisor |
| 06 | `06-Risk-Register.md` | Risiko dari yang kecil sampai kritis + pencegahan & respon | Semua |
| 07 | `07-Rilis-Operasional-Runbook.md` | Lingkungan gratis→berbayar, jalur Play Store, checklist rilis, runbook insiden, serah terima | Dev, pemegang akun |
| ADR | `ADR-001-Strategi-Repo.md` | Kenapa repo aplikasi pelanggan dipisah | Dev |
| 08 | `08-Prompt-Claude-Code-M0-M1.md` | Prompt siap tempel untuk mulai M0–M1 di repo backend | Dev |

Kalau ada perubahan, rambatkan sesuai protokol: PRD → SRS → SDD/UI → Tasks.

## Keputusan inti (ringkas)

1. **Aplikasi pelanggan = repo Flutter terpisah** (`sidik-pelanggan-mobile`). **Backend tetap satu** (`sidik-calibration-api`), dengan modul pelanggan yang diisolasi: file rute, namespace controller, resource, dan folder test sendiri. Alasannya di ADR-001.
2. **Pelanggan = role `pelanggan`** di tabel `users`, ditambah tabel keanggotaan `customer_members`. Satu perusahaan bisa punya banyak akun PIC, dan PIC utama bisa menonaktifkan akun stafnya sendiri.
3. **Semua endpoint pelanggan ada di `/api/pelanggan/v1/*`.** Perusahaan selalu diambil dari token, tidak pernah dari body atau query. Rute internal ditutup untuk pelanggan secara *deny by default*.
4. **Inbox admin bersama + "ambil alih" + PIC admin default per pelanggan.** Pelanggan melihat "Tim PT Sidik", bukan admin perorangan.
5. **Tanggal jatuh tempo ditentukan admin saat approve** (`berlaku_sampai`, sudah ada). Nilainya disinkronkan ke alat. Pengingat dijalankan server lewat FCM di H-30, H-7, H-1, H-0, dan setelah lewat.
6. **Uji coba memakai stack gratis yang terpisah dari server yang dipakai teknisi.** Produksi memakai VPS sesuai `docs/infrastruktur-vps-produksi.md` dan akun Google Play atas nama **organisasi PT Sidik**.

## ⚠️ Mendesak — cek minggu ini

| # | Temuan | Kenapa bahaya | Tindakan |
|---|---|---|---|
| U1 | **Verifikasi developer Android berlaku di Indonesia mulai 30 Sep 2026.** Aplikasi dari developer yang belum terverifikasi tidak bisa dipasang atau diupdate lewat jalur normal di perangkat Android bersertifikat. | App internal `com.ptsidik.kalibrasi` saat ini disebar sebagai APK (Firebase App Distribution / halaman unduh). Kalau belum didaftarkan, teknisi yang ganti HP atau perlu update bisa mentok di lokasi, dua minggu dari sekarang. | Daftarkan identitas developer (sebaiknya atas nama PT Sidik) dan daftarkan package `com.ptsidik.kalibrasi` di Android Developer Console / Play Console sebelum 30 Sep. Task M0-02. |
| U2 | **Kedua repo bisa di-clone tanpa login** per 16 Sep 2026, padahal `AGENTS.md` mencatat repo API sudah privat sejak 10 Sep. | Isi `Project-PT-Sidik/` (lampiran akreditasi, lembar kerja, workbook) ikut terbuka. Kalau dibuka sengaja supaya bisa dibaca untuk dokumen ini, balikkan lagi. | Set privat, jalankan pemindai secret di seluruh histori git. Task M0-01. |
| U3 | **Tanggal jatuh tempo alat tidak ikut ter-update saat sertifikat terbit.** `approve` menyimpan `berlaku_sampai` di `certificates`, tapi `PengingatJatuhTempo` membaca `equipments.tanggal_jatuh_tempo`. | Pengingat admin yang sekarang pun bisa salah. Aplikasi pelanggan nanti akan menampilkan tanggal basi. | Task M0-05. |
| U4 | **Semua rute GET internal hanya disaring `organization_id`.** Channel realtime `organisasi.{id}` juga begitu. | Begitu akun pelanggan dibuat di organisasi PT Sidik, PT A bisa melihat alat dan sertifikat PT B. Ini melanggar kerahasiaan pelanggan (ISO/IEC 17025 klausul 4.2). | Task M0-06, harus selesai **sebelum** role `pelanggan` ada. |
| U5 | Produksi internal di Render `plan: free`. Scheduler `dailyAt('07:00')` berjalan dengan `APP_TIMEZONE=UTC` (jadi 14:00 WIB). `MAIL_MAILER=log`. Token Sanctum `expiration: null`. | Pengingat bisa tidak jalan saat service tidur. Email reset sandi tidak pernah terkirim. Token pelanggan hidup selamanya. | Dibahas di 03-SDD §10 dan 07-Runbook. |

## Keputusan yang harus diambil PT Sidik (bukan developer)

| # | Keputusan | Kenapa penting | Siapa memutuskan (usulan) | Paling lambat |
|---|---|---|---|---|
| K1 | Akun Google Play Console & Android Developer atas nama **organisasi PT Sidik**, bukan akun pribadi developer. Butuh nomor D-U-N-S perusahaan. | Aplikasi yang dirilis dari akun pribadi magang akan "tersandera" saat magang selesai. Akun organisasi juga tidak terkena syarat 12 penguji × 14 hari yang berlaku untuk akun pribadi baru. | Direksi / manajemen | Sebelum M7, idealnya sekarang karena D-U-N-S butuh waktu |
| K2 | Siapa pemilik GitHub Organization, Firebase project, domain, email pengirim, VPS, dan bucket R2. | Aset milik perusahaan harus tetap bisa diakses setelah tim magang selesai. | Manajemen + IT | M0 |
| K3 | Kebijakan interval kalibrasi ulang. ISO/IEC 17025:2017 klausul 7.8.4.3 melarang sertifikat/label memuat rekomendasi interval kecuali disepakati dengan pelanggan. Sistem sekarang menampilkan "Berlaku sampai". | Menentukan istilah di aplikasi, apakah tanggal dicetak di sertifikat, dan bagaimana kesepakatan dengan pelanggan dicatat. | Manajer mutu / Pak Rohman | Sebelum M3 |
| K4 | Pemisahan wewenang: siapa yang boleh **mengesahkan sertifikat** vs admin yang hanya melayani pelanggan. | Saat ini `approve` hanya dijaga `role:admin`. | Manajer teknis | Sebelum M4 |
| K5 | Daftar admin yang menangani inbox pelanggan, jam layanan, dan target respon (misal konfirmasi ≤ 1 hari kerja). | Janji layanan yang tampil di aplikasi. | Manajemen operasional | Sebelum M4 |
| K6 | Kebijakan privasi, syarat & ketentuan, masa simpan data, dan pendaftaran PSE Lingkup Privat ke Komdigi (PM Kominfo 5/2020). | Wajib untuk listing Google Play dan UU PDP. Komdigi sedang menegakkan pendaftaran PSE di 2026. | Manajemen + legal | Sebelum M7 |
| K7 | 3–5 perusahaan pilot untuk uji tertutup (dan 12 penguji kalau K1 terpaksa akun pribadi). | Uji nyata sebelum publik. | Marketing / admin | Sebelum M6 |
| K8 | iOS ikut di rilis pertama atau tidak. | PIC perusahaan besar banyak yang memakai iPhone. | Manajemen | Sebelum M6 |

## Istilah yang dipakai di semua dokumen

- **Pelanggan / Perusahaan** — baris di `customers` (PT-nya).
- **Anggota / PIC** — orang yang login, baris di `users` + `customer_members`. `pic_utama` bisa mengelola staf, `staf` tidak.
- **Permintaan kalibrasi** — pengajuan dari pelanggan (tabel baru). Setelah diterima admin, permintaan menjadi **Order** internal (`orders`, sudah ada).
- **Jadwal kalibrasi ulang / jatuh tempo** — tanggal yang ditentukan admin. Di aplikasi pelanggan **tidak** disebut "kadaluarsa" (lihat K3).
- **Staging** — lingkungan uji coba gratis, data dummy saja. **Produksi** — lingkungan asli.

## Di mana berkas ini disimpan

- Seluruh paket masuk ke `docs/` di repo baru `sidik-pelanggan-mobile`, dan ke vault Obsidian.
- 03-SDD §7 (API) disalin ke repo backend sebagai `docs/kontrak-api-pelanggan.md`. Mulai saat itu, **salinan di repo backend yang jadi sumber kebenaran**, sama seperti `kontrak-api.md` untuk app internal.
