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

# Database dicek DULUAN, dan dikasih kesempatan beberapa kali.
#
# ## Kenapa perlu
#
# 23 Agt 2026: node MySQL Aiven masuk status "Rebuilding" dan catatan DNS-nya
# ikut dicabut selama proses itu. Tiga deploy berturut-turut mati, dan yang
# kelihatan di Render cuma satu kalimat: "Exited with status 1 while running
# your code". Sebab aslinya kependam di 40 baris stack trace `migrate`, dan
# butuh sejam buat sampai ke satu baris yang menentukan:
#
#     getaddrinfo for mysql-...aivencloud.com failed: Name or service not known
#
# Penjaga APP_KEY & DB_HOST di atas ada persis buat alasan yang sama, tapi
# dua-duanya cuma mastiin variabelnya TERISI — bukan databasenya bisa
# dihubungi. Celah itu yang ditambal di sini.
#
# ## Kenapa diulang, bukan sekali cek lalu mati
#
# Berkas ini jalan tiap container nyala, termasuk tiap Render ngebangunin
# service yang ketiduran — bukan cuma waktu deploy. Gangguan database sepuluh
# detik (pemeliharaan, failover, DNS yang belum nyebar) jadi cukup buat matiin
# service yang sebetulnya sehat. Nunggu sebentar jauh lebih murah daripada
# mati lalu nunggu orang nyadar.
#
# Yang SENGAJA nggak dilakukan: nunggu selamanya. Database yang beneran hilang
# harus muncul sebagai deploy gagal dengan alasan yang kebaca, bukan container
# yang menggantung diam-diam sampai Render nyerah sendiri dan bilang "timeout"
# — itu cuma nukar satu pesan membingungkan sama pesan membingungkan lain.
#
# `db:show` dipakai karena dia nyambung lewat konfigurasi Laravel sendiri,
# jadi MYSQL_ATTR_SSL_CA di atas ikut kepakai. Cek pakai PDO mentah bakal
# nempuh jalur lain dari yang dipakai aplikasi — dan cek yang jalurnya beda
# dari yang dijaga itu cek yang bisa bohong.
TUJUAN_DB="${DB_HOST:-database (lewat DB_URL)}"
[ -n "${DB_HOST}" ] && TUJUAN_DB="${DB_HOST}:${DB_PORT:-3306}"

PERCOBAAN=1
until php artisan db:show >/tmp/cek-db.log 2>&1; do
    if [ "${PERCOBAAN}" -ge 6 ]; then
        echo "!! Database ${TUJUAN_DB} nggak bisa dihubungi sesudah ${PERCOBAAN} percobaan (~1 menit)." >&2
        grep -i -m1 'SQLSTATE\|getaddrinfo\|Connection refused\|Access denied' /tmp/cek-db.log >&2 || true
        echo "   Cek service databasenya di console.aiven.io — statusnya harus Running," >&2
        echo "   bukan Rebuilding atau Powered off. Paket gratis cuma punya 1 node," >&2
        echo "   jadi selama node itu dibangun ulang nggak ada yang menggantikan." >&2
        echo "   Kalau host-nya berubah, perbarui DB_* di Render → Environment." >&2
        exit 1
    fi

    echo "   database belum nyaut (percobaan ${PERCOBAAN}/6), nunggu 10 detik..."
    PERCOBAAN=$((PERCOBAAN + 1))
    sleep 10
done

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
