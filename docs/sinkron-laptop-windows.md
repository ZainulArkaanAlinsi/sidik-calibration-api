# Menyamakan laptop Windows dengan Mac — sisi backend

**Kenapa berkas ini ada.** Kode di repo ini otomatis sama di mesin mana pun begitu
`git pull` selesai — itu memang tugas git. Yang bikin dua mesin tetap berbeda bukan
kodenya, tapi tiga hal yang git memang **tidak** bawa:

1. berkas yang sengaja di-`.gitignore` (`.env`, master data lab, kunci),
2. hasil pemasangan dependency (`vendor/`, `node_modules/`),
3. isi database.

Tanpa daftar yang eksplisit, ketiganya baru ketahuan hilang saat dibutuhkan — dan
"kodenya sudah sama kok" jadi menyesatkan, karena aplikasinya tetap tidak jalan.

Sasaran berkas ini: laptop Windows bisa menjalankan `php artisan serve` dengan data
selengkap Mac, dan ada **satu perintah** untuk membuktikan hasilnya identik.

Sisi aplikasi mobile ada di berkas terpisah dengan nama yang sama, di repo
[`sidik-calibration-mobile`](https://github.com/ZainulArkaanAlinsi/sidik-calibration-mobile).

---

## 1. Yang git bawa vs yang tidak

| Yang dibawa git (otomatis sama) | Yang TIDAK dibawa git (harus diurus sendiri) |
|---|---|
| Seluruh `app/`, `routes/`, `config/`, `database/migrations/`, `tests/` | `.env` — dibuat dari `.env.example`, isinya beda tiap mesin |
| Seluruh `docs/` termasuk `docs/permintaan-user-7.md` | `vendor/` — hasil `composer install` |
| `database/data/*.json` — angka master kalibrator suhu, pH, thermohygro | `node_modules/` — hasil `npm install` |
| `database/ocr-templates/` — 20 template lembar kerja | Isi database MySQL — dibangun ulang lewat `migrate` + `db:seed` |
| `CATATAN/ini-yang-dari-karywan-manual/DATABASE.csv` — dibaca `MetodeKalibrasiSeeder` saat seeding | `storage/app/few_shot/` — contoh gambar buat OCR (opsional) |
| 30 berkas referensi di `Project-PT-Sidik/` | Sisa `Project-PT-Sidik/*_CSV/` dan `CATATAN/` — master data lab berisi nama pelanggan asli |

**Kabar baiknya:** semua berkas yang dibaca saat *runtime* sudah ikut git. Sudah
diperiksa satu per satu — `MetodeKalibrasiSeeder`, `CalibrationCapabilitySeeder`,
`PhMeterSeeder`, `ThermohygroSeeder`, `TabelKalibratorSuhu`, `TabelKalibratorSuhu3Alat`,
`TabelKalibratorEnclosure` — tidak ada satu pun yang menunjuk ke berkas yang di-ignore.
Artinya **clone bersih di Windows bisa seeding penuh tanpa menyalin apa pun dari Mac.**

Master data CSV yang di-ignore (`Project-PT-Sidik/Master Data TurbidiMeter_CSV/` dkk.)
memang tidak ikut, tapi angkanya sudah masuk ke seeder. Itu bahan rujukan saat
mengembangkan, bukan syarat aplikasi jalan. Salin dari Mac hanya kalau mau menelusuri
ulang asal sebuah angka.

---

## 2. Pasang toolchain di Windows

Yang dipatok repo ini (`composer.json`, `package.json`):

| Alat | Versi | Catatan |
|---|---|---|
| PHP | **8.3** atau lebih baru | `"php": "^8.3"` |
| Composer | 2.x | |
| MySQL | **8.0** | Bukan MariaDB — beberapa migrasi memakai tipe & mode MySQL 8 |
| Node.js | 20 LTS atau lebih baru | Untuk `vite build` (aset panel Filament) |
| Git | terbaru | Sekalian dapat **Git Bash**, dipakai menjalankan skrip `.sh` di repo ini |

Cara paling ringkas di Windows — lewat `winget` di PowerShell:

```powershell
winget install Git.Git
winget install OpenJS.NodeJS.LTS
winget install Oracle.MySQL
```

PHP di Windows tidak ada di winget dengan versi yang rapi. Dua pilihan:

- **Laragon** (disarankan) — sekali pasang langsung dapat PHP 8.3, MySQL 8, dan
  Composer, plus tombol start/stop. Unduh dari laragon.org, pilih paket "Full".
- **PHP manual** — unduh "VS17 x64 Thread Safe" dari windows.php.net, ekstrak ke
  `C:\php`, tambahkan ke PATH, lalu salin `php.ini-development` jadi `php.ini`.

Kalau pasang PHP manual, buka `php.ini` dan hilangkan tanda `;` di depan baris ini:

```ini
extension=pdo_mysql
extension=mbstring
extension=openssl
extension=fileinfo
extension=curl
extension=gd
extension=zip
extension=intl
```

`pdo_mysql` untuk database, `gd` untuk dompdf (render sertifikat PDF), `zip` dipakai
Composer sendiri. Kalau ada yang masih kurang, `composer install` akan menyebut nama
ekstensinya — tidak perlu menebak.

Pastikan semua kebaca sebelum lanjut:

```bash
php -v          # harus 8.3.x atau lebih
composer -V
mysql --version # harus 8.0.x
node -v
```

---

## 3. Clone dan setup

Semua perintah di bawah dijalankan di **Git Bash**, bukan CMD — supaya sama persis
dengan yang dijalankan di Mac.

```bash
git clone https://github.com/ZainulArkaanAlinsi/sidik-calibration-api.git
cd sidik-calibration-api

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Buat databasenya:

```bash
mysql -u root -p -e "CREATE DATABASE sidik_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Lalu buka `.env` dan sesuaikan **hanya** baris ini:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sidik_db
DB_USERNAME=root
DB_PASSWORD=<password MySQL di laptop ini>
```

Sisanya biarkan seperti `.env.example`. `SEED_ADMIN_PASSWORD` **sengaja dikosongkan** —
di `APP_ENV=local` seeder selalu memakai `rahasia123` dan baris itu diabaikan.

Isi datanya, lalu jalankan:

```bash
php artisan migrate --seed
php artisan serve
```

API ada di `http://localhost:8000/api`. Health check: `GET /up`.

### Kunci API yang tidak wajib

`GEMINI_API_KEY`, `ANTHROPIC_API_KEY`, dan blok `AWS_*` (Cloudflare R2) boleh dibiarkan
kosong. Yang mati kalau kosong cuma ekstraksi lembar kerja lewat AI cloud dan unggahan
berkas ke R2 — sisa aplikasinya jalan normal. Kalau memang mau dipakai di laptop ini,
salin nilainya dari `.env` di Mac; **jangan** diambil dari repo, karena repo ini publik
dan kunci-kunci itu memang tidak pernah ikut di-commit.

---

## 4. Membuktikan sudah sama — satu perintah

```bash
./scripts/cek-sinkron.sh
```

Skrip itu memeriksa hal-hal yang benar-benar menentukan "sama atau tidak":

- working tree bersih (tidak ada perubahan yang belum di-commit),
- `HEAD` sama persis dengan `origin/main`,
- tidak ada commit lokal yang belum ke-push,
- berkas yang dibaca saat runtime lengkap,
- `.env` ada dan `APP_KEY` terisi,
- `vendor/` dan `node_modules/` terpasang,
- versi PHP/Composer/MySQL/Node memenuhi patokan.

Jalankan skrip yang **sama** di Mac. Kalau dua-duanya melaporkan hash commit yang
sama dan nol temuan, dua mesin itu identik sejauh yang bisa dijamin — sisanya cuma
password database, yang memang sengaja berbeda.

---

## 5. Rutinitas harian supaya tetap sama

Yang bikin dua mesin melenceng bukan setup awal, tapi kebiasaan harian. Tiga aturan:

1. **Setiap mulai kerja, di mesin mana pun:** `git pull origin main` — ini juga sudah
   jadi aturan di `CLAUDE.md`.
2. **Setiap selesai kerja:** commit dan push. Kerjaan yang menginap di satu mesin
   sebagai perubahan yang belum di-commit adalah satu-satunya cara kode dua mesin
   bisa berbeda tanpa disadari. `./scripts/cek-sinkron.sh` akan menangkapnya.
3. **Setelah `git pull` yang menyentuh `composer.json` / `package.json` / migrasi:**

   ```bash
   composer install
   npm install
   php artisan migrate
   ```

   Kode boleh sama, tapi `vendor/` dan skema database tidak ikut `git pull`.

⚠️ **Database di dua mesin tidak otomatis sama, dan memang tidak perlu sama.**
Skemanya disamakan oleh `php artisan migrate`; isinya disamakan oleh `db:seed`. Yang
tidak ikut adalah data yang kamu masukkan sendiri lewat aplikasi. Kalau memang mau
dipindahkan, ekspor dari Mac dan impor di sini:

```bash
# di Mac
mysqldump -u root -p sidik_db > sidik_db.sql

# di laptop Windows, setelah berkasnya disalin
mysql -u root -p sidik_db < sidik_db.sql
```

Jangan jalankan `migrate:fresh`, `migrate:refresh`, atau `db:wipe` kalau databasenya
sedang dipakai bersama lewat LAN — perintah itu menghapus semua tabel, dan yang hilang
bukan cuma punyamu. Aturan lengkapnya ada di `README.md` bagian "Kerja Berdua".

---

## 6. Kalau mau benar-benar semuanya, termasuk yang di-ignore

Untuk aplikasi berjalan, isi bab ini **tidak diperlukan**. Ini hanya kalau kamu mau
laptop ini punya seluruh bahan rujukan yang ada di Mac.

Salin manual (flashdisk / Google Drive / AirDrop — bukan lewat git, karena isinya
nama & alamat pelanggan asli sementara repo ini publik):

| Dari Mac | Ke laptop | Isinya |
|---|---|---|
| `Project-PT-Sidik/Master Data TurbidiMeter_CSV/` | posisi yang sama | Master data turbidimeter |
| `Project-PT-Sidik/Chlorine_Meter_CSV/` | posisi yang sama | Master data chlorine |
| `Project-PT-Sidik/Master_Olah_Data_Spectrofotometer_CSV/` | posisi yang sama | Master data spektrofotometer |
| `Project-PT-Sidik/Master Olah Data_pH for trial*` | posisi yang sama | Master data pH (CSV + .xlsm) |
| `Project-PT-Sidik/Master Data Conductivity/` | posisi yang sama | Master data conductivity |
| `Project-PT-Sidik/Refractometer_CSV/` | posisi yang sama | Master data refractometer |
| `Project-PT-Sidik/Master_Olah_Data_Viscometer_CSV/` | posisi yang sama | Master data viscometer |
| `CATATAN/` (selain `DATABASE.csv` yang sudah ikut git) | posisi yang sama | Sertifikat terbit, kop surat |
| `storage/app/few_shot/` | posisi yang sama | Contoh gambar buat prompt OCR |
| `.env` (bagian `GEMINI_API_KEY`, `ANTHROPIC_API_KEY`, `AWS_*`) | disalin per baris | Kunci API |

Setelah selesai, `./scripts/cek-sinkron.sh` akan menandai berkas rujukan ini sebagai
ada — sebelumnya ia hanya memberi catatan, bukan kegagalan, karena memang opsional.
