#!/bin/sh
#
# Dijalanin tiap container nyala — termasuk tiap kali Render nge-bangunin
# service yang ketiduran, bukan cuma waktu deploy. Jadi semua yang di sini
# WAJIB aman diulang-ulang.
#
set -e

# Render nyuntik PORT lewat environment. Caddy dikasih ":PORT" tanpa hostname
# supaya dia nggak nyoba nerbitin sertifikat TLS sendiri — TLS-nya udah
# dipegang Render di depan.
: "${PORT:=10000}"
export SERVER_NAME=":${PORT}"

# URL publiknya baru ada SESUDAH service kebentuk, jadi nggak mungkin ditulis
# di render.yaml sejak awal. Render nyuntik RENDER_EXTERNAL_URL sendiri, jadi
# APP_URL ngikut itu kecuali emang diisi manual (mis. begitu pakai domain
# sendiri). APP_URL yang salah bikin tautan verifikasi QR di sertifikat
# nunjuk ke localhost.
if [ -z "${APP_URL}" ] && [ -n "${RENDER_EXTERNAL_URL}" ]; then
    export APP_URL="${RENDER_EXTERNAL_URL}"
fi

# MySQL Aiven nolak sambungan yang nggak TLS. PDO mau pakai TLS tapi tetap
# minta sertifikat CA-nya buat verifikasi, dan berkas itu nggak bisa ditaruh
# di git — jadi dititipin lewat environment dalam bentuk base64, terus
# dibongkar ke berkas sementara di sini.
if [ -n "${DB_SSL_CA_B64}" ]; then
    echo "${DB_SSL_CA_B64}" | base64 -d > /tmp/db-ca.pem
    export MYSQL_ATTR_SSL_CA=/tmp/db-ca.pem
fi

# SEMENTARA — dicabut begitu deploy pertama berhasil.
#
# Dashboard Render bilang APP_KEY tersimpan; container bilang kosong. Dua-duanya
# nggak bisa dibuktikan dari luar, jadi container-nya sendiri yang ditanya.
# Yang dicetak cuma NAMA variabelnya, nggak pernah nilainya — log Render kebaca
# siapa pun yang punya akses ke dashboard.
echo "→ [diagnosa] variabel yang diterima container:"
env | cut -d= -f1 | sort | sed 's/^/     /'
echo "→ [diagnosa] APP_KEY: $([ -n "${APP_KEY:-}" ] && echo "ADA, ${#APP_KEY} karakter" || echo "TIDAK ADA / KOSONG")"

# Gagal cepat dengan pesan yang jelas. Tanpa penjagaan ini, APP_KEY kosong
# munculnya sebagai "Unsupported cipher or incorrect key length" di tengah
# request — error yang nggak nyebut-nyebut APP_KEY sama sekali.
if [ -z "${APP_KEY}" ]; then
    echo "!! APP_KEY kosong. Isi dulu di Render → Environment." >&2
    echo "   Bikin nilainya di laptop: php artisan key:generate --show" >&2
    exit 1
fi

if [ -z "${DB_HOST}" ] && [ -z "${DB_URL}" ]; then
    echo "!! DB_HOST/DB_URL kosong — database Aiven belum kesambung." >&2
    exit 1
fi

# Cache lama dari tahap build (kalau ada) dibuang dulu, biar config:cache di
# bawah baca environment yang sekarang, bukan yang keburu kebekukan pas build.
php artisan config:clear >/dev/null 2>&1 || true

echo "→ migrasi database"
php artisan migrate --force

# Sengaja pakai saklar, bukan otomatis: seeder di project ini idempotent
# (firstOrCreate/updateOrCreate), tapi DemoDataSeeder nulis ulang sesi demo —
# kalau jalan tiap container bangun, data yang lagi diuji teknisi bisa
# ketimpa balik ke bawaan. Nyalain sekali pas deploy pertama, terus matiin.
if [ "${SEED_ON_BOOT}" = "true" ]; then
    echo "→ seeding data awal"
    php artisan db:seed --force
fi

php artisan storage:link >/dev/null 2>&1 || true

php artisan config:cache
php artisan view:cache

# `route:cache` SENGAJA nggak dipanggil. routes/api.php nutup /health pakai
# closure, dan closure nggak bisa diserialisasi — perintahnya bakal mati dengan
# "Unable to prepare route for serialization". Kalau nanti closure itu diganti
# jadi controller/invokable, baris ini boleh dihidupin:
#     php artisan route:cache

# Queue worker nebeng di container yang sama. Paket gratis Render cuma ngasih
# satu service, jadi nggak ada tempat buat worker terpisah — padahal
# GenerateCertificate itu ShouldQueue, dan tanpa worker sertifikatnya
# ngendon di tabel jobs selamanya tanpa pesan error apa pun.
#
# --max-time=3600 bikin worker mati sendiri tiap jam lalu dihidupin ulang
# sama loop di bawah; ini cara standar ngelawan memory leak di proses PHP yang
# hidup lama, dan penting banget di jatah 512 MB.
(
    while true; do
        php artisan queue:work --sleep=3 --tries=3 --max-time=3600 || true
        sleep 2
    done
) &

echo "→ server jalan di port ${PORT}"
exec frankenphp run --config /etc/caddy/Caddyfile
