# Checklist Deploy ke VPS

Turunan praktis dari [`infrastruktur-vps-produksi.md`](infrastruktur-vps-produksi.md)
(yang itu keputusan arsitektur; yang ini urutan kerjanya). Disusun 6 Agt 2026.

Semua kebutuhan di bawah dibaca langsung dari repo — `composer.json`,
`routes/console.php`, `DatabaseSeeder`, `AdminPanelProvider`, dan seterusnya —
bukan template deploy Laravel umum. Yang **tidak** dibutuhkan juga ditulis, di
bagian akhir, biar nggak ada waktu kebuang.

**Pemicu dokumen ini:** `php artisan sertifikat:perbaiki-qr` nolak jalan selama
`APP_URL` masih `localhost`. QR di sertifikat nggak akan pernah bisa dibuka
pelanggan sampai backend punya alamat publik yang tetap. Itu batas
infrastruktur, bukan batas kode.

---

## 0. Yang harus sudah ada sebelum mulai

- [ ] VPS aktif (rekomendasi 2 vCPU / 4GB — lihat tabel opsi di dokumen induk)
- [ ] Domain/subdomain, mis. `api.pt-sidik.com`
- [ ] DNS `A record` sudah nunjuk ke IP VPS **dan sudah propagasi**
      (`dig +short api.pt-sidik.com` keluar IP-nya)

Tanpa domain, jangan lanjut. IP telanjang bisa jalan, tapi begitu IP-nya ganti
seluruh QR yang sudah tercetak mati — persis masalah yang lagi dibenerin.

---

## 1. Paket di server

- [ ] **PHP 8.3+** (`composer.json`: `"php": "^8.3"`) + ekstensi: `mbstring`,
      `xml`, `curl`, `zip`, `gd`, `bcmath`, `mysql`, `intl`
      - `gd` buat QR & logo; `dompdf` butuh `gd` + `mbstring`
- [ ] **MySQL 8** (prod pakai MySQL — test lokal jalan di SQLite, jadi jangan
      anggap "hijau di lokal" = aman di server)
- [ ] **Composer 2**
- [ ] **Node 20+ & npm** — dibutuhkan sekali saja buat build aset panel admin
- [ ] **Nginx** + **Certbot** (Let's Encrypt)
- [ ] **Git**

---

## 2. Ambil kode & dependensi

```bash
git clone <repo> /var/www/sidik-api && cd /var/www/sidik-api
composer install --no-dev --optimize-autoloader
npm ci && npm run build        # aset Filament; tanpa ini /admin tampil polos
```

- [ ] `composer install` sukses
- [ ] `npm run build` sukses, folder `public/build` kebentuk

---

## 3. `.env` produksi

```bash
cp .env.example .env
php artisan key:generate
```

Yang **wajib** beda dari `.env.example`:

| Variabel | Isi | Kenapa |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | **kritis** — `true` bocorin isi `.env` di halaman error |
| `APP_URL` | `https://api.pt-sidik.com` | **inti masalahnya.** Ini yang nempel permanen di QR sertifikat |
| `APP_KEY` | hasil `key:generate` | |
| `DB_*` | kredensial MySQL server | jangan pakai `root` |
| `SESSION_DOMAIN` | domainmu | panel `/admin` pakai session |
| `MAIL_*` | SMTP asli | sertifikat dikirim via email ke pelanggan |
| `VISION_DRIVER` | `gemini` | |
| `GEMINI_API_KEY` | key produksi | **bikin key BARU** — jangan pakai key dev |
| `GEMINI_MODEL` | `gemini-3.6-flash` | cek dulu masih hidup; nama model Gemini mati tanpa aba-aba |

- [ ] `APP_DEBUG=false` — cek ulang, ini yang paling sering kelewat
- [ ] `APP_URL` pakai **https** dan **tanpa** garis miring di belakang
- [ ] `.env` permission `600`, pemilik user web

---

## 4. Migrasi & seeder — ⚠️ JANGAN `db:seed` polos

```bash
php artisan migrate --force
```

**`php artisan db:seed` di produksi = bencana senyap.** `DatabaseSeeder` ikut
manggil seeder demo, yang bikin:

- 4 akun demo (`admin@sidik.test`, `teknisi@sidik.test`, …) berpassword
  **`rahasia123`** — akun admin aktif dengan password yang tertulis di repo publik
- sesi kalibrasi & sertifikat palsu (pH, turbidimeter, chlorine) yang bercampur
  dengan data pelanggan asli, lengkap dengan pelanggan fiktif

Yang dijalankan di produksi cuma seeder **master data** — urutannya wajib
seperti ini:

```bash
php artisan db:seed --class=OrganizationSeeder --force
php artisan db:seed --class=MetodeKalibrasiSeeder --force
php artisan db:seed --class=CalibrationCapabilitySeeder --force
php artisan db:seed --class=PhMeterCapabilitySeeder --force
php artisan db:seed --class=TurbidimeterCapabilitySeeder --force
php artisan db:seed --class=ChlorineCapabilitySeeder --force
php artisan db:seed --class=ThermohygroSeeder --force
php artisan db:seed --class=PengaturanSertifikatSeeder --force
```

- [ ] Tiga `*CapabilitySeeder` jalan **sesudah** `CalibrationCapabilitySeeder` —
      yang belakangan menghapus dulu isi kategori `instrumen-analitik` sebelum
      nulis ulang. Kebalik = baris presisi penuh pH/turbidimeter/chlorine hilang
      diam-diam.
- [ ] `PengaturanSertifikatSeeder` **jangan dilewat.** Docblock-nya nyebut
      sendiri: ini dua data yang "gampang ketinggalan waktu deploy ke server
      baru", dan kalau kosong **sertifikat tetap terbit tapi isinya salah** —
      penanda tangan jatuh ke admin yang kebetulan menyetujui, padahal di lab
      terakreditasi itu jabatan tetap.

---

## 5. Akun admin pertama

Karena seeder demo tidak dijalankan, belum ada satu pun user. Panel `/admin`
cuma menerima `role = admin` **dan** `status = aktif` (`User::canAccessPanel()`),
jadi `make:filament-user` saja tidak cukup — dia tidak mengisi dua kolom itu.

```bash
php artisan tinker
```
```php
\App\Models\User::create([
    'organization_id' => 1,
    'employee_id'     => 'SDK-0001',
    'name'            => 'Nama Admin',
    'email'           => 'admin@pt-sidik.com',
    'password'        => 'password-kuat-yang-kamu-pilih',  // otomatis di-hash
    'department'      => 'Quality Control',
    'role'            => \App\Models\User::ROLE_ADMIN,
    'status'          => \App\Models\User::STATUS_AKTIF,
]);
```

- [ ] Bisa login ke `https://<domain>/admin`
- [ ] Password **tidak** sama dengan yang mana pun di repo

---

## 6. Storage & izin

```bash
php artisan storage:link          # logo organisasi (disk public)
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

- [ ] `storage:link` jalan
- [ ] **PDF sertifikat TIDAK boleh bisa diakses langsung dari web.** Dia disimpan
      di disk `local` (`storage/app`), bukan `public`, dan hanya keluar lewat
      endpoint ber-auth. Jangan "dibantu" dipindah ke `public/` biar gampang
      diunduh — itu bikin dokumen terkendali bocor ke siapa pun yang menebak URL.

---

## 7. Queue worker — tanpa ini sertifikat nyangkut

`QUEUE_CONNECTION=database` dan penerbitan sertifikat (`GenerateCertificate`)
berjalan di antrean. **Tanpa worker, admin klik approve → status berhenti di
`menunggu_generate` selamanya, tanpa pesan error.**

`/etc/systemd/system/sidik-worker.service`:

```ini
[Unit]
Description=Sidik queue worker
After=network.target mysql.service

[Service]
User=www-data
Restart=always
RestartSec=5
ExecStart=/usr/bin/php /var/www/sidik-api/artisan queue:work --tries=3 --timeout=120 --sleep=3

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now sidik-worker
```

- [ ] `systemctl status sidik-worker` → `active (running)`
- [ ] **Restart worker tiap habis deploy** (`systemctl restart sidik-worker`) —
      `queue:work` memuat kode ke memori; tanpa restart dia masih menjalankan
      versi lama

---

## 8. Cron scheduler

Dua tugas harian di `routes/console.php`: `alat:cek-jatuh-tempo` (07:00) dan
`standar:cek-kadaluarsa` (07:05). Yang kedua yang memperingatkan kalau
sertifikat standar acuan mau kadaluarsa — kalau mati, lab bisa mengalibrasi
pakai standar kedaluwarsa tanpa ada yang tahu.

```cron
* * * * * cd /var/www/sidik-api && php artisan schedule:run >> /dev/null 2>&1
```

- [ ] `php artisan schedule:list` menampilkan dua tugas itu
- [ ] `APP_TIMEZONE=Asia/Jakarta` **ada di `.env`**. Yang menentukan jam 07:00
      itu `config('app.timezone')`, bukan zona waktu OS — dan defaultnya di
      `config/app.php` adalah **UTC**. Kalau baris ini hilang saat menyalin
      `.env`, notifikasi jatuh tempo terkirim jam 14:00 WIB. `.env.example`
      sudah memuatnya; tinggal jangan dihapus.

---

## 9. Nginx + HTTPS

- [ ] `root` mengarah ke `/var/www/sidik-api/**public**` (bukan folder project)
- [ ] `client_max_body_size` ≥ **10M** — foto lembar kerja divalidasi `max:8192`
      (8 MB) di `WorksheetExtractionController`
- [ ] **`php.ini` juga dinaikkan**: `upload_max_filesize = 10M` dan
      `post_max_size = 12M`. Default PHP cuma **2M** — nginx boleh lolos, tapi
      PHP tetap menolak, dan gejalanya bikin bingung: request sampai ke server
      tapi `$request->file('foto')` kosong tanpa pesan yang jelas
- [ ] `certbot --nginx -d api.pt-sidik.com` → HTTPS hidup, auto-renew aktif
- [ ] HTTP di-redirect ke HTTPS

HTTPS bukan opsional di sini: panel `/admin` mengirim password lewat form
biasa, dan token Sanctum mobile lewat header.

---

## 10. Cache produksi

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

- [ ] Jalankan **sesudah** `.env` final. Kalau `.env` diubah setelah ini,
      `config:cache` harus diulang — kalau tidak, perubahannya tidak terbaca dan
      kamu akan mengejar hantu.

---

## 11. Perbaiki QR sertifikat lama ← alasan dokumen ini ada

Baru **sesudah** `APP_URL` benar dan HTTPS hidup:

```bash
php artisan sertifikat:perbaiki-qr --dry-run   # lihat dulu
php artisan sertifikat:perbaiki-qr             # eksekusi
```

- [ ] Dry-run menampilkan alamat baru yang benar
- [ ] Dijalankan tanpa `--dry-run`

Yang perlu disadari: perintah ini memperbaiki kolom `qr_payload`, **bukan PDF
yang sudah tersimpan.** Gambar QR sudah ter-render di dalam file PDF. Sertifikat
yang terlanjur dicetak/dikirim dengan alamat lokal harus **diterbitkan ulang**,
bukan sekadar di-update kolomnya. Untuk 10 sertifikat yang ada sekarang ini
tidak masalah — semuanya data demo, belum ada yang keluar ke pelanggan.

---

## 12. Verifikasi pasca-deploy

- [ ] `curl https://<domain>/api/health` → 200
- [ ] Login panel `/admin` berhasil, aset tampil rapi (bukan HTML polos)
- [ ] Login API dari mobile berhasil
- [ ] **Uji rantai penuh:** buat sesi → approve → PDF terbit (bukti worker jalan)
- [ ] **Scan QR pakai HP dengan Wi-Fi DIMATIKAN** (kuota seluler) — ini satu-satunya
      cara membuktikan alamatnya benar-benar publik, bukan cuma kelihatan jalan
      karena kamu masih di jaringan yang sama
- [ ] Kirim satu sertifikat ke email sungguhan, pastikan masuk (bukan spam)

---

## 13. Backup

- [ ] Dump MySQL harian, simpan **di luar VPS**
- [ ] `storage/app/certificates/` ikut dibackup — itu dokumen terkendali;
      hilang = tidak bisa dibuat ulang persis
- [ ] Sekali waktu, **coba restore** ke server kosong. Backup yang belum pernah
      dites bukan backup

---

## 14. Yang TIDAK perlu dikerjakan

Supaya tidak ada waktu terbuang mengejar yang belum ada:

- **Reverb / WebSocket.** `.env.example` memang punya `REVERB_*` dan docs
  menyinggung realtime, tapi `laravel/reverb` **belum ada di `composer.json`**
  dan `BROADCAST_CONNECTION` default `log`. Jadi tidak ada server WebSocket
  untuk dinyalakan. Lewati sampai paketnya benar-benar dipasang.
- **Redis.** `CACHE_STORE`, `SESSION_DRIVER`, dan `QUEUE_CONNECTION` semuanya
  `database`. Untuk beban 1 lab ini cukup; Redis baru masuk kalau nanti terbukti
  perlu.
- **Supervisor.** Cukup systemd (bagian 7).
- **SSH untuk lab.** Sesuai dokumen induk: VPS/deploy/backup dipegang
  Raihan/Arkaan. "Admin bisa utak-atik sendiri" berlaku di lapisan data lewat
  menu Kelola Data, bukan di lapisan server.

---

## Ringkasan urutan

```
DNS → paket → clone → composer/npm → .env → migrate
  → seeder MASTER saja → admin pertama → storage:link
  → worker → cron → nginx+HTTPS → config:cache
  → sertifikat:perbaiki-qr → verifikasi (scan QR pakai kuota)
```

Tiga yang paling gampang kelewat dan paling mahal akibatnya:

1. **`db:seed` polos** → akun admin berpassword `rahasia123` di server produksi.
2. **Worker tidak jalan** → sertifikat nyangkut tanpa error, ketahuan waktu
   pelanggan menagih.
3. **`PengaturanSertifikatSeeder` terlewat** → sertifikat terbit dengan penanda
   tangan yang salah. Tidak ada error, tidak ada yang curiga.

## PDF sertifikat & disk yang kehapus tiap deploy

Produksi Render jalan dengan `ARSIP_DRIVER=local`, dan disk container Render itu **sementara** —
kehapus tiap deploy dan tiap container restart (`docs/deploy-gratis-render.md` §227). Artinya tiap
deploy menghapus SELURUH PDF sertifikat yang pernah terbit, sementara barisnya di database tetap
`terbit` dengan `pdf_path` terisi.

Gejalanya khas dan sempat membingungkan: **halaman QR dan unduhan Excel tetap jalan** (dua-duanya
dirakit dari `certificates.snapshot` di database), **cuma unduhan PDF yang 404** — jadi kelihatan
seperti "PDF-nya rusak", padahal berkasnya yang tidak ada.

**Jaring pengamannya sudah terpasang:** `App\Services\BerkasPdfSertifikat` membangun ulang PDF dari
snapshot beku begitu berkasnya tidak ditemukan, dan mencatatnya sebagai `warning` di log. Dipakai
keempat jalur unduh (QR, API, folder berkas, tombol Filament) plus pemeriksaan lampiran email.
Dijaga `tests/Feature/PdfSertifikatSelamatDariDeployTest.php`.

**Itu jaring, bukan obat.** Obatnya memindahkan disk arsip ke penyimpanan yang awet:

1. Buat bucket Cloudflare R2 (gratis 10 GB) — `dash.cloudflare.com` → R2 → Create bucket.
2. Isi `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT` di Render.
3. Salin berkas lama — **jangan manual**, pakai perintahnya:

   ```
   php artisan arsip:pindah              # coba kering, cuma melaporkan
   php artisan arsip:pindah --jalankan   # salin beneran
   ```

   Kunci disalin apa adanya dan ukurannya diverifikasi sesudah mendarat. Ini langkah yang paling
   gampang salah: kolom di database menyimpan KUNCI, bukan URL, jadi kunci yang bergeser sedikit
   saja bikin SELURUH berkas lama tidak ketemu — dan gejalanya identik dengan disk yang kehapus.

   Kalau `ARSIP_PREFIX` diisi (bucket dipakai bareng hal lain), prefiksnya **ikut sendiri** —
   perintahnya membaca nilai yang sama dengan yang dipakai disk `arsip` sesudah saklarnya digeser,
   jadi yang menulis dan yang membaca tidak bisa berbeda pendapat. Baris `Prefix bucket:` di awal
   keluaran yang menunjukkannya.

   Perintahnya aman diulang, dan yang sudah sama persis dilewat. Kalau dia melaporkan **BENTROK**,
   itu berkas yang sudah ada di tujuan tapi ukurannya beda dari sumber — biasanya sisa pindah yang
   mati di tengah. Berkasnya tidak disentuh dan perintahnya keluar gagal, supaya tidak ada yang
   menggeser `ARSIP_DRIVER` di atas berkas kepotong. Periksa dulu mana yang benar; kalau yang di
   sumber, jalankan ulang dengan `--timpa`.
4. Baru setel `ARSIP_DRIVER=s3` — dan cuma kalau langkah 3 keluar **sukses**. Selama masih ada
   yang gagal atau bentrok, **jangan digeser**.

Sebelum langkah 3 selesai, jangan geser `ARSIP_DRIVER` — kunci yang tidak cocok bikin berkas lama
tidak ketemu, dan bangun ulang cuma menolong PDF (tanda tangan & kop tidak punya sumber beku).

> **Kalau `arsip.awet` tiba-tiba balik `false` sesudah pernah `true`, periksa `render.yaml` duluan.**
>
> 1 Sep 2026 ini benar-benar terjadi. Saklarnya digeser ke `s3` di dashboard dan berhasil, lalu
> deploy berikutnya menyinkronkan blueprint — dan `ARSIP_DRIVER` yang waktu itu ditulis
> `value: local` **menimpa balik** setelan dashboard-nya. Produksi diam-diam kembali menulis ke
> disk container yang kehapus tiap deploy, tanpa satu pun error.
>
> Kuncinya beda antara `value:` dan `sync: false`. Yang `value:` **dikelola blueprint** dan
> ditimpa tiap sync; yang `sync: false` dikelola manual dan selamat. Itu sebabnya keempat `AWS_*`
> bertahan sementara `ARSIP_DRIVER` tidak. Sekarang `ARSIP_DRIVER` sudah `sync: false`, tapi
> aturan umumnya berlaku buat setelan lain: **apa pun yang diputuskan operator, bukan kode,
> jangan dipatok `value:` di blueprint.**

## Deploy Render timeout — apa yang dibaca duluan

Render memberi **jendela 15 menit** dari `==> Deploying...` sampai health check `/up` harus
lolos. Seluruh isi `docker/entrypoint.sh` jalan **sebelum** server menerima request pertama, jadi
tiap menit yang dipakai di situ diambil dari jendela yang sama.

Kejadian 1 Sep 2026: `Deploying...` 01:15:55 → server bind `:10000` **01:22:34** (6 menit 39
detik terpakai) → `Timed Out` 01:30:57. Aplikasinya sendiri sehat — `/up` menjawab 200 dalam
208 ms dengan `config:cache` + `view:cache` seperti produksi.

Urutan periksanya:

1. **`SEED_ON_BOOT` masih `true`?** Ini tersangka pertama. Seeder menulis ulang seluruh sesi
   contoh **tiap container nyala**, dan di MySQL gratis itu bisa makan menit. Dokumennya sendiri
   bilang "nyalain sekali pas deploy pertama, terus matiin" — kalau tidak pernah dimatikan, tiap
   deploy membayar ongkosnya lagi. Matikan di Render → Environment.
2. **Baca penanda tahap di log.** `entrypoint.sh` sekarang mencetak `[HH:MM:SS] → <tahap>` di
   tiap langkah, jadi tahap yang memakan jendela menyebut dirinya sendiri. Sebelum ini lognya
   sunyi selama enam menit dan tidak ada yang bisa ditunjuk.
3. **Ulangi deploy-nya.** Migrasi yang sudah mendarat tidak diulang, jadi percobaan kedua
   biasanya naik dalam hitungan detik. Dua cara: Render → **Manual Deploy → Deploy latest
   commit**, atau GitHub → Actions → **Tes** → **Run workflow** (jalur `workflow_dispatch`, tetap
   lewat gerbang phpunit dulu).
4. **Kalau tetap timeout sesudah `→ server jalan di port`,** masalahnya bukan lambatnya boot
   melainkan health check-nya sendiri. Yang dibutuhkan isi tab **Events** Render untuk deploy itu
   — di situ tertulis alasan probe-nya gagal.

Kalau tahap yang lambat ternyata migrasi dan bukan seeder, pindahkan `php artisan migrate --force`
ke `preDeployCommand` di `render.yaml`: langkah itu jalan **sebelum** instance baru dinyalakan,
jadi keluar dari jendela health check sepenuhnya.


## Tiga pertanyaan operasional yang bisa dijawab tanpa dashboard

`GET /api/health` (tanpa login) sekarang melaporkan:

```json
"deploy": {
  "versi": "3e81086…",           // commit yang BENERAN jalan di container ini
  "arsip": { "awet": false },     // false = berkas kehapus tiap deploy
  "seed_saat_boot": false         // true = seeder jalan tiap container nyala
}
```

Batasnya sama dengan `direktori_perusahaan`: yang dilaporkan **status, bukan nilai**, nol request
ke penyedia, nol rahasia. Repo ini publik, jadi SHA commit bukan rahasia.

### "Direktorinya sedang menagih atau nggak?" (ditambah 2 Sep 2026)

Blok `direktori_perusahaan` sekarang tiga field, bukan satu:

```json
"direktori_perusahaan": {
  "disetel": true,          // jalur direktorinya kepakai
  "driver": "osm",          // yang BENERAN jalan, bukan isi .env apa adanya
  "bisa_ditagih": false     // true = jalur ini menyentuh Google Places
}
```

**Kenapa `disetel` sendirian nggak cukup.** Dia `true` buat `osm` MAUPUN `auto` — yang pertama
gratis, yang kedua menembak Google duluan dan ditagih begitu kuota bulanannya lewat. Dua keadaan
yang bedanya uang, dilaporkan dengan angka yang sama persis.

Itu bukan kasus tepi. Waktu bawaan direktori dipindah ke OSM (1 Sep 2026), keputusannya sudah
tercatat sejak 31 Agt tapi nilainya tertinggal di `auto` — dan **tidak ada satu pun cara
memeriksanya dari luar**. Yang akhirnya menemukan tagihan Google Cloud, bukan endpoint ini.

**`driver` melaporkan yang EFEKTIF, bukan isi `.env`.** `DIREKTORI_PERUSAHAAN_DRIVER=osmm` yang
salah ketik jatuh ke `osm`, dan yang dilaporkan `osm` — karena yang perlu diketahui "yang jalan
yang mana", bukan "yang saya ketik apa".

**Pakainya begini.** Sesudah mengubah setelan direktori:

```
curl -s https://<domain>/api/health | jq .direktori_perusahaan
```

`"bisa_ditagih": false` = aman. `true` = jalur berbayar hidup, disengaja atau tidak.

> **Arah penimpaannya KEBALIKAN dari instingnya — dan ini sudah menggigit sekali.**
>
> `DIREKTORI_PERUSAHAAN_DRIVER` ditulis `value: osm` di `render.yaml`, jadi dia **dikelola
> blueprint**: tiap sync, nilai itu menimpa apa pun yang diketik di dashboard. Bukan sebaliknya.
> Lihat kotak `ARSIP_DRIVER` di atas — persis mekanisme yang bikin arsip produksi diam-diam balik
> ke disk sementara pada 1 Sep 2026.
>
> Buat driver direktori, arah itu justru **yang diinginkan**: bawaan gratis ditegakkan ulang tiap
> deploy, jadi `auto` yang tertinggal di dashboard tidak bisa diam-diam menyalakan jalur berbayar.
> Konsekuensinya harus disadari: **memindahkannya ke `google`/`auto` lewat dashboard saja tidak
> bertahan** — yang harus diubah `render.yaml`, atau kuncinya dipindah ke `sync: false` dulu
> mengikuti aturan umum di kotak `ARSIP_DRIVER`.
>
> Field `driver` di health yang memberi tahu mana yang sebenarnya menang, tanpa perlu menebak.

Nama driver itu **status, bukan rahasia**: nilainya sama saja apakah API key-nya terisi, kosong,
atau salah. Key-nya sendiri tetap tidak pernah ikut.

Kegunaannya:

- **"Deploy-nya udah naik belum?"** → cocokkan `versi` dengan commit terakhir di `main`. Sebelum
  ini jawabannya cuma ada di dashboard Render.
- **"Kenapa deploy timeout?"** → `seed_saat_boot: true` itu tersangka pertama; seeder menulis ulang
  seluruh sesi contoh tiap container nyala, dan menitnya diambil dari jendela health check yang
  cuma 15 menit.
- **"Tanda tangan hilang lagi?"** → `arsip.awet: false` menjelaskannya, dan obatnya bagian di atas.
