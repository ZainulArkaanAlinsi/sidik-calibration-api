# Infrastruktur Produksi — VPS untuk backend

Status: usulan arsitektur, 25 Juli 2026
Untuk: Raihan (full stack mobile/desktop) · Arkaan (full stack mobile/desktop)
Menggantikan bagian "Desktop membawa servernya sendiri" di
`docs/arsitektur-desktop-database.md` — desktop cukup jadi klien API biasa,
sama seperti mobile.

## Kenapa berubah dari rencana LAN-local

Dokumen sebelumnya sengaja menghindari VPS supaya satu lab bisa pegang
semuanya sendiri tanpa biaya bulanan. Tapi kalau targetnya sistem yang bisa
diakses dari mana saja, cepat, dan siap dipakai banyak organisasi — itu bukan
lagi kasus 1 PC = 1 server. VPS jadi kebutuhan, bukan pilihan.

Kabar baiknya: pindah ke VPS justru **menghapus** sebagian pekerjaan di
rencana lama —

- Tidak perlu bungkus PHP ke dalam aplikasi desktop.
- Tidak perlu pindah MySQL → SQLite.
- Desktop dan mobile jadi klien yang sama persis, cuma beda tampilan.

Yang **tetap** dari dokumen lama dan tidak berubah sama sekali: Kelola Data
(Keputusan 4), Rumus Kalibrasi berversi (Keputusan 5), tiga jalur impor data
lama (Keputusan 6), dan batas peran AI. Itu semua soal lapisan aplikasi, bukan
soal di mana server-nya nyala.

**Penting dibedakan:** "admin bisa ngutak-atik sendiri" itu berlaku di
**lapisan data** — lewat menu Kelola Data & Rumus Kalibrasi di dalam aplikasi.
Bukan di lapisan **server** — VPS, deploy, backup tetap dipegang Raihan/Arkaan.
Dua hal ini jangan tertukar; lab tidak perlu (dan tidak boleh) pegang SSH ke
VPS.

## Opsi VPS

| | Opsi hemat | Opsi rekomendasi | Opsi scale-up |
|---|---|---|---|
| Spek | 1 vCPU / 2GB RAM | 2 vCPU / 4GB RAM | 4 vCPU / 8GB RAM+ |
| Provider | Vultr / DigitalOcean, ~$6/bln | Vultr / DigitalOcean / Biznet Gio, ~$12-18/bln | sama, tinggal resize |
| Cocok untuk | coba-coba, demo | pemakaian nyata 1 lab, headroom buat AI vision + Reverb | banyak lab/klien sekaligus |
| Catatan | gampang kehabisan RAM begitu Reverb + queue worker + PHP-FPM jalan bareng | ini titik mulai yang masuk akal | tinggal upgrade paket, bukan pindah arsitektur |

Provider Indonesia (Biznet Gio, IDCloudHost) worth dipertimbangkan kalau mayoritas
pengguna di Indonesia — latensi lebih rendah, billing IDR, support bahasa
Indonesia. Provider luar (DigitalOcean/Vultr) lebih murah dan dokumentasinya
lebih lengkap. Dua-duanya bisa, pilih salah satu — jangan pusingkan ini
terlalu lama, gampang pindah nanti karena arsitekturnya sama (Ubuntu + Nginx +
PHP standar).

**Rekomendasi: mulai dari Opsi rekomendasi (2 vCPU/4GB).** Cukup buat 1 lab
jalan lancar plus ruang buat proses tambahan di bawah, dan naik ke scale-up
tinggal resize, bukan migrasi.

## Yang jalan di VPS

| Komponen | Fungsi | Kenapa perlu |
|---|---|---|
| Ubuntu LTS + Nginx (atau Caddy) | web server | Caddy lebih simpel — SSL otomatis tanpa setup manual |
| PHP-FPM 8.3 | jalankan Laravel | — |
| MySQL 8 | database utama | tetap MySQL, tidak perlu ganti mesin |
| Laravel Reverb | websocket, sinkron HP ↔ desktop real-time | sudah mulai diintegrasikan (`feat(realtime)` commit terakhir) |
| Redis | cache + antrean (queue) + session | bikin API kerasa cepat, dan dibutuhkan queue di bawah |
| Queue worker (Supervisor) | proses AI Vision & tugas berat di belakang layar | panggilan ke AI Vision butuh beberapa detik — kalau ditunggu langsung, upload foto kerasa lemot. Taruh di antrean, respons ke HP tetap instan |
| Object storage (Cloudflare R2 atau S3-compatible) | simpan foto lembar kerja & PDF sertifikat | disk VPS ikut server — kalau server bermasalah, foto ikut hilang. Storage terpisah lebih aman dan gampang di-backup sendiri. R2 gratis untuk biaya keluar (egress) |

## Domain, SSL, keamanan

- Beli domain (`.id` atau `.com`), arahkan ke VPS.
- SSL otomatis lewat Caddy atau Certbot (Let's Encrypt, gratis) — wajib, bukan
  opsional, karena kamera & upload foto di browser/mobile butuh HTTPS.
- Firewall (`ufw`): cuma buka port 80/443. SSH pakai key, bukan password.
- Rate limit di API (bawaan Laravel `throttle`) — jaga dari penyalahgunaan,
  juga jaga biaya AI Vision supaya tidak jebol kalau ada yang spam upload.
- `.env` (kunci API AI Vision, kredensial database) tidak pernah masuk git.
- Role akses admin/teknisi/viewer sudah ada di desain — tinggal ditegakkan di
  level API, bukan cuma UI.

## Supaya "jangan sampai down"

- **Backup otomatis harian** — dump database + salin isi object storage, taruh
  di tempat lain (bukan di VPS yang sama). Retensi ~30 hari.
- **Auto-restart** — Supervisor jaga proses Reverb & queue worker tetap hidup
  kalau crash.
- **Endpoint `/api/health`** — dipantau dari luar.
- **Uptime monitoring** — UptimeRobot (gratis), cek `/api/health` tiap
  beberapa menit, kirim notifikasi (WhatsApp/email/Telegram) kalau down.
- **Error tracking** — Sentry (free tier cukup besar), tangkap error PHP di
  backend dan crash di aplikasi Flutter, biar tau ada masalah sebelum
  pengguna komplain.
- **Deploy tanpa downtime** — GitHub Actions auto-deploy ke VPS pas push ke
  `main`, pakai `php artisan down` cuma sepersekian detik saat migrasi
  (Laravel bawaan), bukan mati total tiap update.

## Ringkasan: apa yang perlu disiapkan, di luar kode backend/frontend

1. VPS (mulai 2 vCPU/4GB) + domain
2. Object storage terpisah (Cloudflare R2) buat foto & PDF
3. Redis buat cache, queue, session
4. Supervisor buat jaga Reverb + queue worker tetap hidup
5. Backup otomatis ke luar VPS + retensi
6. Uptime monitoring (UptimeRobot) + error tracking (Sentry) — dua-duanya gratis di skala ini
7. SSL otomatis (Caddy/Let's Encrypt) + firewall dasar

Semua ini **satu kali setup**, bukan kerjaan berulang. Setelah jalan, yang
"diutak-atik" pengguna sehari-hari cuma lapisan aplikasi (Kelola Data, Rumus
Kalibrasi) — persis yang diminta, tanpa mereka pernah perlu menyentuh server.
