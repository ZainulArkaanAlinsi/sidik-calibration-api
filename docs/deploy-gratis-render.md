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
   **`main`**.

   > Dokumen ini awalnya nyebut branch `chore/deploy-gratis-render`. Branch itu
   > sudah masuk `main` (dicek: `git merge-base --is-ancestor`), dan `main`
   > sudah jalan jauh di depannya. Pilih `main` — kalau nunjuk ke branch lama,
   > yang kedeploy backend enam alat yang lalu.
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
6. **Matiin Auto-Deploy di dashboard Render** (Settings → Auto-Deploy → No).

   `render.yaml` di repo ini sudah nyetel `autoDeploy: false`, tapi berkas itu
   dibaca waktu blueprint DIIMPOR — service yang sudah terlanjur kebentuk nggak
   ikut berubah cuma karena berkasnya diedit. Jadi saklarnya harus digeser
   manual sekali.

   Kalau nggak: tiap push memicu dua build sekaligus, dan yang lewat
   Auto-Deploy naik **tanpa nunggu test** — bikin urutan "test dulu, baru
   deploy" di workflow jadi sia-sia, karena commit yang merah tetap nyampe
   server lewat jalur satunya.
7. **Daftarin ping biar service-nya nggak ketiduran.** Buka
   [uptimerobot.com](https://uptimerobot.com) (gratis, nggak perlu kartu),
   New Monitor → tipe **HTTP(s)** → URL
   `https://<url>.onrender.com/api/health` → interval **5 menit**.

   Tanpa ini, service nganggur 15 menit bakal ditidurin Render, dan permintaan
   berikutnya makan ~50 detik buat ngebangunin. Di HP kelihatannya persis kayak
   aplikasi nge-hang.
8. **Pindahin penyimpanan berkas ke Cloudflare R2** sebelum teknisi mulai
   masukin data beneran.

   Tiap kali kamu push kode, berkas yang sudah diunggah ikut kehapus — bukan
   risiko yang nunggu kejadian, tapi akibat rutin dari kerja normal.

   **Saklarnya `ARSIP_DRIVER`, BUKAN `FILESYSTEM_DISK`.** Ini pernah salah
   ditulis di sini, dan salahnya mahal: `FILESYSTEM_DISK` cuma dibaca baris
   `'default'` di `config/filesystems.php`, sementara nggak ada satu pun
   pemakaian `Storage::` di `app/` yang memakai disk default — semuanya
   nyebut disknya langsung. Diisi `s3`, dia nggak memindahkan apa pun, tapi
   MEMATIKAN Import Excel (`FileUpload` Filament ikut variabel itu, sementara
   pembacaannya dari disk lain).

   Berkas awet ada di disk bernama **`arsip`** — PDF sertifikat, tanda tangan,
   dokumen Folder Manager, foto titik ukur. Langkahnya:

   1. [dash.cloudflare.com](https://dash.cloudflare.com) → **R2** → Create
      bucket (gratis 10 GB).
   2. **Manage R2 API Tokens** → **Create Account API token** (bukan User —
      yang User mati kalau kamu keluar dari organisasi). Permission **Object
      Read & Write**, dibatasi ke bucket itu saja. Salin Access Key ID &
      Secret.
   3. Di Render → Environment, isi `AWS_ACCESS_KEY_ID`,
      `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, dan `AWS_ENDPOINT`
      (`https://<account-id>.r2.cloudflarestorage.com`, tanpa nama bucket di
      belakangnya). `AWS_DEFAULT_REGION` biarin `auto` — R2 nggak punya region,
      tapi SDK-nya tetap minta kolom itu terisi.
   4. Salin berkas yang sudah ada dari `storage/app/private` ke bucket, pakai
      kunci yang sama persis dengan isi kolom `pdf_path`, `tanda_tangan_path`,
      dan `path`. Dilewati, rujukan di database nunjuk ke berkas yang nggak
      ada di bucket.
   5. Baru sesudah semuanya beres, ganti **`ARSIP_DRIVER`** jadi `s3`.
      Dibalik urutannya, unggahan langsung error.
   6. Uji: unggah tanda tangan, terbitkan satu sertifikat, unduh PDF-nya, lalu
      deploy ulang dan unduh lagi. Kalau masih kebuka sesudah deploy, berarti
      sudah kena.

   Kalau unggahan ditolak dengan keluhan soal nama bucket, balik
   `AWS_USE_PATH_STYLE_ENDPOINT` ke `true`.

   **Yang BELUM ikut pindah:** logo & kop organisasi (masih di disk `public`)
   dan citra pindai lembar kerja (disk sendiri, `OCR_DISK`). Dua-duanya sengaja
   ditunda — logo bawa pertanyaan URL publik, dan citra OCR nggak boleh pindah
   ke penyimpanan awet sebelum `schedule:work` terbukti jalan, karena tanpa
   pembersih terjadwal retensi 90 hari berubah jadi selamanya.

## 5. APK, Windows, macOS buat orang lain

Nggak ada build manual lagi. Di repo mobile, tempel URL yang barusan dikasih
Render sebagai repository variable:

```bash
gh variable set API_BASE_URL --body "https://<yang-asli>.onrender.com"
```

Tanpa `/api` di belakang — workflow-nya yang nambahin sendiri.

Sesudah itu tiap push ke `main` membangun ketiga platform sendiri:

- **Android** — workflow "APK rilis (nyambung server)" ngirim APK-nya langsung
  ke grup tester `teknisi` lewat Firebase App Distribution. Emailnya harus
  didaftarkan dulu di Console, kalau nggak ya terkirim ke grup kosong.
- **Windows & macOS** — workflow "Rilis desktop (nyambung server)" naruh kedua
  zip di halaman unduh Firebase Hosting.

Dua-duanya butuh secret `FIREBASE_SERVICE_ACCOUNT` yang sama. Detailnya di
[`docs/deploy-firebase.md`](https://github.com/ZainulArkaanAlinsi/sidik-calibration-mobile/blob/main/docs/deploy-firebase.md)
di repo mobile.

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

Ini yang paling gampang diremehkan dari empat hal di halaman ini, karena
kerusakannya nggak muncul sebagai error: nggak ada yang merah, nggak ada yang
gagal — berkasnya cuma nggak ada lagi. Yang nyadar duluan biasanya teknisi yang
balik ke sesi lamanya dan nemu lembar kerjanya kosong.

Adapternya sudah terpasang di repo, jadi pindah ke R2 nggak butuh ubah kode
sama sekali. Langkah lengkapnya ada di
[langkah 8 di atas](#4-sesudah-deploy-pertama).

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
| `npm install` sukses tapi `npm run build` mati: `Cannot find module '@rolldown/binding-...'` | Node di tahap aset keturunin ke bawah syarat Vite 8 (`^20.19.0 \|\| >=22.12.0`). npm ngelewatin binding native-nya diam-diam, tanpa bilang gagal — makanya tahap aset dipatok `node:22` |
| Log nyebut `APP_KEY kosong` | langkah 2 kelewat |
| `SQLSTATE[HY000] [2002]` waktu migrasi | DB_HOST/DB_PORT Aiven salah ketik |
| `SSL connection error` | `DB_SSL_CA_B64` kosong atau base64-nya kepotong (harus SATU baris, tanpa newline) |
| Panel `/admin` tampil tanpa CSS | `trustProxies` kehapus dari `bootstrap/app.php` |
| Sertifikat nggak jadi-jadi, nggak ada error | queue worker mati — cek log, harusnya nggak ada `queue:work` yang exit berulang |
| Permintaan pertama lama banget lalu normal | bukan error, itu service-nya lagi bangun (poin 1 di atas) |
