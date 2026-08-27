#!/usr/bin/env bash
#
# Membuktikan laptop ini sama dengan mesin lain — bukan cuma kodenya.
#
#   ./scripts/cek-sinkron.sh
#
# Kenapa ada skrip ini: `git status` bersih cuma bilang tidak ada perubahan yang
# belum di-commit. Itu tidak menjawab pertanyaan yang sebenarnya — apakah mesin
# ini bisa menjalankan aplikasinya sama seperti mesin sebelah. Tiga hal yang git
# tidak bawa (isi .env, hasil composer/npm, koneksi database) justru yang paling
# sering bikin "kodenya sudah sama kok" jadi menyesatkan.
#
# Jalankan skrip yang sama di dua mesin. Kalau dua-duanya melaporkan hash commit
# yang sama dan nol masalah, dua mesin itu identik sejauh yang bisa dijamin.
#
# Jalan di macOS dan di Git Bash (Windows). Sengaja tidak pakai fitur bash 4 —
# bash bawaan macOS masih 3.2.

set -uo pipefail

cd "$(dirname "$0")/.."

MASALAH=0
CATATAN=0

ok()      { printf '  \033[32m✓\033[0m %s\n' "$1"; }
masalah() { printf '  \033[31m✗\033[0m %s\n' "$1"; MASALAH=$((MASALAH + 1)); }
catatan() { printf '  \033[33m·\033[0m %s\n' "$1"; CATATAN=$((CATATAN + 1)); }
bagian()  { printf '\n\033[1m[%s]\033[0m\n' "$1"; }

# Membandingkan "8.3.10" dengan minimal "8.3" tanpa `sort -V`, yang perilakunya
# beda antara GNU coreutils dan BSD/macOS.
versi_cukup() {
  ada_mayor=$(echo "$1" | cut -d. -f1)
  ada_minor=$(echo "$1" | cut -d. -f2)
  min_mayor=$(echo "$2" | cut -d. -f1)
  min_minor=$(echo "$2" | cut -d. -f2)
  [ "${ada_mayor:-0}" -gt "${min_mayor:-0}" ] && return 0
  [ "${ada_mayor:-0}" -lt "${min_mayor:-0}" ] && return 1
  [ "${ada_minor:-0}" -ge "${min_minor:-0}" ]
}

printf '\033[1m=== Cek sinkron — sidik-calibration-api ===\033[0m\n'

# ---------------------------------------------------------------- git
bagian "git"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  masalah "bukan repo git — clone ulang dari GitHub"
  exit 1
fi

# Diambil dulu supaya perbandingan di bawah melawan keadaan GitHub sekarang,
# bukan salinan origin/main yang sudah basi berhari-hari.
if git fetch origin main --quiet 2>/dev/null; then
  ok "berhasil fetch origin/main"
else
  catatan "tidak bisa fetch (offline?) — perbandingan di bawah pakai data lokal"
fi

if [ -z "$(git status --porcelain)" ]; then
  ok "working tree bersih"
else
  masalah "ada perubahan yang belum di-commit:"
  git status --short | sed 's/^/      /'
fi

HEAD_SHA=$(git rev-parse --short HEAD)
MAIN_SHA=$(git rev-parse --short origin/main 2>/dev/null || echo '?')

if [ "$HEAD_SHA" = "$MAIN_SHA" ]; then
  ok "HEAD = origin/main ($HEAD_SHA)"
else
  DIBELAKANG=$(git rev-list --count "HEAD..origin/main" 2>/dev/null || echo '?')
  DIDEPAN=$(git rev-list --count "origin/main..HEAD" 2>/dev/null || echo '?')
  [ "$DIBELAKANG" != "0" ] && masalah "ketinggalan $DIBELAKANG commit dari origin/main → git pull origin main"
  [ "$DIDEPAN" != "0" ] && masalah "$DIDEPAN commit belum di-push → git push origin $(git rev-parse --abbrev-ref HEAD)"
fi

# ------------------------------------------------- berkas dibaca saat runtime
bagian "berkas yang dibaca saat aplikasi jalan"

# Daftar ini bukan tebakan: semuanya hasil telusur `base_path(` dan
# `database_path(` di database/seeders/ dan app/. Kalau salah satu hilang,
# seeding berhenti atau perhitungan jatuh ke jalur generik tanpa error.
for berkas in \
  "CATATAN/ini-yang-dari-karywan-manual/DATABASE.csv" \
  "database/data/kemampuan-kalibrasi.json" \
  "database/data/kalibrasi-ph-meter.json" \
  "database/data/thermohygro-lab.json" \
  "database/data/tabel-kalibrator-suhu.json" \
  "database/data/tabel-master-suhu-3alat.json" \
  "database/data/tabel-kalibrator-enclosure.json"
do
  if [ -f "$berkas" ]; then ok "$berkas"; else masalah "HILANG: $berkas"; fi
done

JML_TEMPLATE=$(ls -1 database/ocr-templates/*.json 2>/dev/null | wc -l | tr -d ' ')
if [ "$JML_TEMPLATE" -ge 20 ]; then
  ok "database/ocr-templates/ — $JML_TEMPLATE template"
else
  masalah "database/ocr-templates/ cuma $JML_TEMPLATE template (harusnya 20+)"
fi

# --------------------------------------------------------------- setup lokal
bagian "setup lokal"

if [ -f .env ]; then
  ok ".env ada"
  if grep -qE '^APP_KEY=base64:' .env; then
    ok "APP_KEY terisi"
  else
    masalah "APP_KEY masih kosong → php artisan key:generate"
  fi
  DB_NAMA=$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d= -f2-)
  ok "DB_DATABASE=${DB_NAMA:-<kosong>}"
else
  masalah ".env belum dibuat → cp .env.example .env && php artisan key:generate"
fi

if [ -f vendor/autoload.php ]; then ok "vendor/ terpasang"; else masalah "vendor/ kosong → composer install"; fi
if [ -d node_modules ]; then ok "node_modules/ terpasang"; else masalah "node_modules/ kosong → npm install"; fi

# Yang benar-benar menentukan aplikasi jalan bukan ada-tidaknya klien mysql di
# PATH (Laragon sering tidak menaruhnya di sana), tapi apakah Laravel-nya sendiri
# bisa menyambung. Jadi yang diuji itu.
if [ -f vendor/autoload.php ] && [ -f .env ]; then
  if php artisan db:show >/dev/null 2>&1; then
    ok "Laravel bisa menyambung ke database"
  else
    masalah "Laravel tidak bisa menyambung ke database — cek DB_PASSWORD di .env, dan MySQL sudah nyala?"
  fi
fi

# ----------------------------------------------------------------- toolchain
bagian "toolchain"

cek_versi() {
  nama="$1"; perintah="$2"; minimal="$3"
  if ! command -v "$perintah" >/dev/null 2>&1; then
    masalah "$nama belum terpasang (minimal $minimal)"
    return
  fi
  case "$perintah" in
    php)      versi=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION.".".PHP_RELEASE_VERSION;') ;;
    composer) versi=$(composer -V 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1) ;;
    node)     versi=$(node -v 2>/dev/null | tr -d 'v') ;;
    *)        versi="?" ;;
  esac
  if [ -n "$minimal" ] && versi_cukup "$versi" "$minimal"; then
    ok "$nama $versi (minimal $minimal)"
  else
    masalah "$nama $versi — di bawah minimal $minimal"
  fi
}

cek_versi "PHP"      php      "8.3"
cek_versi "Composer" composer "2.0"
cek_versi "Node.js"  node     "20.0"

# ------------------------------------------------------- rujukan (opsional)
bagian "bahan rujukan (opsional — tidak bikin aplikasi gagal)"

# Sengaja bukan masalah: isinya nama & alamat pelanggan asli, jadi memang tidak
# pernah ikut git. Angkanya sudah masuk ke seeder. Lihat docs/sinkron-laptop-windows.md §6.
for folder in \
  "Project-PT-Sidik/Master Data TurbidiMeter_CSV" \
  "Project-PT-Sidik/Chlorine_Meter_CSV" \
  "Project-PT-Sidik/Master_Olah_Data_Spectrofotometer_CSV" \
  "Project-PT-Sidik/Master Data Conductivity" \
  "Project-PT-Sidik/Refractometer_CSV" \
  "Project-PT-Sidik/Master_Olah_Data_Viscometer_CSV"
do
  if [ -d "$folder" ]; then ok "$folder"; else catatan "belum disalin: $folder"; fi
done

# -------------------------------------------------------------------- hasil
printf '\n'
if [ "$MASALAH" -eq 0 ]; then
  printf '\033[32m\033[1mSama.\033[0m commit %s · %s catatan opsional\n' "$HEAD_SHA" "$CATATAN"
  printf 'Jalankan skrip ini juga di mesin sebelah — kalau hash commitnya sama, dua mesin itu identik.\n'
  exit 0
else
  printf '\033[31m\033[1mBelum sama — %s masalah.\033[0m Perbaiki yang bertanda ✗ di atas, lalu jalankan lagi.\n' "$MASALAH"
  exit 1
fi
