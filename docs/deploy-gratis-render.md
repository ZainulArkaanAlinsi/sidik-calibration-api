# Deploy gratis — Render + Aiven

Status: siap dijalankan · 10 Agustus 2026

Tujuannya: backend berhenti nebeng laptop. Begitu ini jalan, HP nembak server
lewat internet, jadi wifi lokasi mati / laptop ketutup / pindah kota pun
aplikasinya tetap hidup.

Ini **bukan** pengganti [`infrastruktur-vps-produksi.md`](infrastruktur-vps-produksi.md).
Ini versi gratis buat uji coba. Bedanya dijelasin di bagian
[Yang harus diterima](#yang-harus-diterima-kalau-mau-gratis) — baca itu dulu
sebelum janjiin apa-apa ke lab.

Ini juga bukan pengganti [`tunnel-cloudflare.md`](tunnel-cloudflare.md); ini
lebih jauh. Tunnel cuma bikin URL-nya tetap, servernya tetap di laptop. Yang
ini servernya pindah beneran, laptop nggak perlu nyala.

## Bagi tugas

| | |
|---|---|
| Udah beres di repo (`chore/deploy-gratis-render`) | `Dockerfile`, `docker/entrypoint.sh`, `render.yaml`, `.dockerignore`, `trustProxies` di `bootstrap/app.php` |
| Cuma bisa kamu yang ngerjain | bikin akun Aiven & Render, tempel rahasia (APP_KEY, password DB, GEMINI_API_KEY) |

Rahasianya jangan dikasih ke siapa-siapa termasuk ke sesi Claude — semuanya
diketik langsung di dashboard.

## Kenapa dua layanan, bukan satu

Render nggak punya MySQL gratis (yang gratis cuma PostgreSQL, dan itu pun ada
masa kedaluwarsanya). Project ini pakai MySQL di 59 migrasi + query mentah di
beberapa service, jadi pindah ke PostgreSQL itu kerjaan sendiri yang nggak ada
hubungannya sama urusan wifi. Aiven ngasih MySQL 1 GB gratis selamanya tanpa
kartu kredit. Jadi: aplikasinya di Render, databasenya di Aiven.

---

## 1. Database — Aiven MySQL

1. Daftar di [aiven.io](https://aiven.io/free-mysql-database) (nggak perlu
   kartu kredit).
2. **Create service** → **MySQL** → paket **Free** → region **Asia (Singapore)**
   kalau ada; kalau nggak ada, ambil yang paling deket.
3. Tunggu statusnya jadi **Running** (~2 menit).
4. Di tab **Overview**, catat: Host, Port, User, Password, Database name.
5. Di bawah kredensial itu ada **CA Certificate** — unduh (`ca.pem`).
   MySQL Aiven nolak sambungan yang nggak TLS, dan PHP minta berkas ini buat
   verifikasi.
6. Ubah jadi satu baris base64, hasilnya nanti ditempel ke Render:

   ```bash
   base64 -i ~/Downloads/ca.pem | tr -d '\n' | pbcopy
   ```

## 2. Bikin APP_KEY

Di laptop, di repo ini:

```bash
php artisan key:generate --show
```

Salin hasilnya (bentuknya `base64:....`). **Jangan** pakai APP_KEY yang di
`.env` lokal — server produksi punya kuncinya sendiri.

## 3. Aplikasi — Render

1. Daftar di [render.com](https://render.com) pakai akun GitHub (gratis, nggak
   perlu kartu kredit).
2. **New** → **Blueprint** → pilih repo `sidik-calibration-api` → branch
   **`chore/deploy-gratis-render`**.
3. Render baca `render.yaml` dan nanyain semua nilai rahasianya sekaligus:

   | Isian | Diambil dari |
   |---|---|
   | `APP_KEY` | langkah 2 |
   | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Aiven, langkah 1.4 |
   | `DB_SSL_CA_B64` | base64 `ca.pem`, langkah 1.6 |
   | `GEMINI_API_KEY` | kunci Gemini yang sekarang dipakai di `.env` lokal |

4. **Sebelum klik Apply**: ubah `SEED_ON_BOOT` jadi `true`. Ini yang ngisi
   master data (organisasi, kemampuan kalibrasi, standar, akun demo) ke
   database kosong.
5. **Apply**. Build pertama makan ~5–10 menit (nyusun image PHP dari nol).

## 4. Sesudah deploy pertama

1. Buka log di Render, pastiin ada `→ migrasi database`, `→ seeding data awal`,
   terus `→ server jalan di port ...` tanpa error merah.
2. Cek dari laptop — ganti `<url>` dengan URL yang dikasih Render:

   ```bash
   curl https://<url>.onrender.com/api/health
   ```

   Harusnya bales `{"status":"ok",...}`.
3. **Balikin `SEED_ON_BOOT` ke `false`** (Environment → Save). Kalau ketinggalan
   `true`, tiap kali service bangun dari tidur, data uji yang baru dimasukin
   teknisi bisa ketimpa balik ke bawaan seeder.
4. Ganti password akun demo. Seeder nulis `admin@sidik.test` / `rahasia123`, dan
   URL Render itu kebuka buat siapa pun yang tahu alamatnya. Login ke
   `https://<url>.onrender.com/admin`, ganti password semua akun — atau hapus
   yang nggak kepakai.
5. Betulin QR sertifikat yang udah terbit. `qr_payload` ketulis sekali waktu
   sertifikatnya jadi, ngikut `APP_URL` yang berlaku saat itu — jadi sertifikat
   yang lahir di laptop QR-nya nunjuk ke `localhost` dan discan siapa pun bakal
   mentok. Jalanin sekali abis URL-nya tetap:

   ```
   php artisan sertifikat:perbaiki-qr
   ```

   Paket gratis Render nggak ngasih akses shell, jadi jalanin ini dari laptop
   dengan `.env` yang DB-nya diarahin ke Aiven — bukan ke MySQL lokal.

## 5. APK buat HP

Di repo mobile (branch `chore/apk-rilis-cloud`):

```bash
./tool/build-apk.sh https://<url>.onrender.com
```

Keluar dua APK di `build/apk-rilis/` — `sidik-cloud.apk` (lewat internet) dan
`sidik-lokal.apk` (cadangan lewat kabel USB kalau lokasinya ternyata nggak ada
internet sama sekali). Penjelasannya ada di kepala skripnya.

---

## Yang harus diterima kalau mau gratis

Empat hal ini konsekuensi paket gratis, bukan bug. Dua yang pertama bisa kena
pas uji coba, jadi baca beneran.

**1. Service ketiduran, dan bangunnya lama.**
Nganggur ~15 menit → Render nidurin container-nya. Permintaan berikutnya jadi
pemicu bangun, dan itu makan **~50 detik sampai semenit**. Di HP kelihatannya
persis kayak aplikasi nge-hang.

Penangkalnya: daftarin `https://<url>.onrender.com/api/health` di
[UptimeRobot](https://uptimerobot.com) (gratis), interval 5 menit. Dia nge-ping
terus, jadi servernya nggak pernah sempat tidur. Dan tetap: **buka aplikasinya
sekali ~2 menit sebelum demo mulai**, jangan pas orangnya udah ngeliatin.

**2. Berkas yang diunggah bisa hilang.**
Disk container Render itu sementara — kehapus tiap deploy dan tiap container
bangun ulang. Yang kena: foto lembar kerja, logo organisasi, tanda tangan, PDF
sertifikat. Datanya sendiri (sesi, pembacaan, hasil hitungan) aman karena itu
di MySQL Aiven, bukan di disk.

PDF sertifikat bisa dibangun ulang dari data:
`php artisan sertifikat:bangun-ulang`. Tanda tangan & logo harus diunggah lagi.
Buat uji coba masih kepakai. Sebelum dipakai beneran, pindahin ke Cloudflare R2
(gratis 10 GB) — tinggal ganti `FILESYSTEM_DISK` ke `s3`, nggak perlu ubah kode.

**3. Jatah kecil.** 512 MB RAM, 0,1 CPU, MySQL 1 GB. Cukup buat beberapa
teknisi kerja barengan; bukan buat dipakai satu lab penuh sehari-hari.

**4. Kunci Gemini kepakai beneran.** Sekarang server ini kebuka di internet.
Rate limit bawaan Laravel udah nyala, tapi awasi tagihan Gemini-nya.

Kalau uji cobanya lancar dan lab mau lanjut, pindah ke rencana di
[`infrastruktur-vps-produksi.md`](infrastruktur-vps-produksi.md) — VPS ~$12/bln
ngilangin keempat poin di atas sekaligus.

## Kalau gagal

| Gejala | Sebabnya biasanya |
|---|---|
| Build gagal di `composer install` | `composer.lock` nggak cocok sama PHP di image — cek pesan versinya di log |
| Build mati di `package:discover` dengan `Class "Pdo\Mysql" not found` | image-nya keturunin ke PHP 8.3; `config/database.php` butuh 8.4 |
| Log nyebut `APP_KEY kosong` | langkah 2 kelewat |
| `SQLSTATE[HY000] [2002]` waktu migrasi | DB_HOST/DB_PORT Aiven salah ketik |
| `SSL connection error` | `DB_SSL_CA_B64` kosong atau base64-nya kepotong (harus SATU baris, tanpa newline) |
| Panel `/admin` tampil tanpa CSS | `trustProxies` kehapus dari `bootstrap/app.php` |
| Sertifikat nggak jadi-jadi, nggak ada error | queue worker mati — cek log, harusnya nggak ada `queue:work` yang exit berulang |
| Permintaan pertama lama banget lalu normal | bukan error, itu service-nya lagi bangun (poin 1 di atas) |
