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

# Penanda waktu tiap tahap boot.
#
# Render ngasih JENDELA 15 MENIT dari "Deploying..." sampai health check harus
# lolos, dan semua yang di bawah ini jalan SEBELUM server nerima request
# pertama. Deploy 1 Sep 2026 timeout dengan 6 menit 39 detik habis di tahap-
# tahap ini — dan lognya SUNYI selama itu, jadi nggak ada yang bisa nunjuk
# tahap mana yang lambat.
#
# `date` doang, nggak ngubah perilaku apa pun. Yang dibeli: deploy gagal
# berikutnya nyebutin sendiri tahap yang makan jendelanya.
tahap() {
    echo "[$(date -u '+%H:%M:%S')] → $1"
}

# Seeder di boot itu jebakan yang mahal: DemoDataSeeder nulis ulang SELURUH
# sesi contoh tiap container nyala, dan di MySQL gratis itu bisa makan menit —
# menit yang diambil dari jendela health check yang sama. Dokumennya bilang
# "nyalain sekali pas deploy pertama, terus matiin"; kalau nggak pernah
# dimatiin, tiap deploy bayar ongkosnya lagi tanpa ada yang tahu.
if [ "${SEED_ON_BOOT}" = "true" ]; then
    echo "!! SEED_ON_BOOT=true — seeder jalan tiap container nyala." >&2
    echo "   Ini nambah menit ke tiap boot dan diambil dari jendela health check" >&2
    echo "   Render yang cuma 15 menit. Matiin di Render → Environment sesudah" >&2
    echo "   deploy pertama berhasil." >&2
fi

tahap "bersihin cache build"
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

tahap "migrasi database"
php artisan migrate --force

# Sengaja pakai saklar, bukan otomatis: seeder di project ini idempotent
# (firstOrCreate/updateOrCreate), tapi DemoDataSeeder nulis ulang sesi demo —
# kalau jalan tiap container bangun, data yang lagi diuji teknisi bisa
# ketimpa balik ke bawaan. Nyalain sekali pas deploy pertama, terus matiin.
if [ "${SEED_ON_BOOT}" = "true" ]; then
    tahap "seeding data awal"
    php artisan db:seed --force
fi

# Akun admin dari environment — dibikin kalau AKUN_ADMIN_EMAIL keisi dan
# emailnya belum kedaftar. Diam kalau nggak disetel.
#
# Kenapa lewat boot: menambah orang normalnya lewat /admin, dan itu tetap jalan
# yang benar buat sehari-hari. Yang nggak bisa lewat situ cuma satu keadaan —
# waktu yang megang panelnya lagi nggak bisa membukanya. Paket gratis Render
# nggak punya shell sama sekali, jadi `tinker` juga bukan jalan keluar.
#
# SENGAJA TANPA `|| true`, dan itu keputusan — bukan kelalaian.
#
# Environment akun yang salah (email salah ketik, organisasi belum di-seed, ID
# pegawai kembar) emang nggak boleh matiin API, dan itu udah diurus DI DALAM
# perintahnya: ketiganya nulis alasannya lalu pulang sukses. Jadi nggak ada
# lagi yang perlu ditelan di sini.
#
# Yang tersisa cuma kegagalan tak terduga, dan yang itu HARUS mematikan boot.
# `User` pakai trait Diaudit, dan aturannya udah tertulis di sana: "Kalau
# nyatet audit gagal, perubahannya ikut gagal … perubahan yang nggak kecatat
# lebih berbahaya daripada perubahan yang gagal." `User::create()` nulis
# barisnya DULU, baru event `created` nulis `audit_logs` — jadi kalau yang
# kedua gagal sementara galatnya ditelan, akun admin udah terlanjur ada tanpa
# jejak audit dan nggak ada satu pun yang tahu. Buat lab terakreditasi itu
# temuan, bukan ketidaknyamanan.
#
# Temuan review CodeRabbit di PR #175.
tahap "akun admin dari environment"
php artisan akun:admin

# Bangun ulang snapshot & PDF sertifikat yang SUDAH terbit.
#
# ## Kenapa lewat boot, bukan dijalankan sekali lewat shell
#
# Alasannya sama persis dengan impor direktori di bawah: paket gratis Render
# TIDAK menyediakan shell sama sekali ("Shell is not supported for free compute
# plans" — dialog upgrade-nya muncul begitu tab Shell dibuka). Tanpa jalur ini,
# `sertifikat:bangun-ulang` sama sekali tidak bisa dijalankan di produksi, dan
# perbaikan yang menyentuh SNAPSHOT tidak akan pernah sampai ke sertifikat yang
# terlanjur terbit.
#
# Tombol "Cetak ulang PDF" di panel admin bukan penggantinya, dan itu disengaja:
# dia sengaja TIDAK menyentuh snapshot ([CetakUlangSertifikat]) — yang
# dirender ulang cuma lembarnya, dari snapshot yang sama persis. Perbaikan
# seperti U95 per titik atau urutan tabel ketertelusuran hidup DI DALAM
# snapshot, jadi tombol itu tidak memunculkannya.
#
# ## Kenapa saklar, dan kenapa harus dimatikan lagi
#
# Perintahnya aman diulang — hasilnya cuma bergantung data sesi + kode yang
# lagi jalan — tapi dia menulis ulang setiap berkas PDF tiap kali jalan. Di
# paket gratis itu menit yang diambil dari jendela health check Render yang
# cuma 15 menit, dan ongkosnya tumbuh seiring jumlah sertifikat. Nyalakan
# sesudah deploy yang mengubah bentuk snapshot, baca hasilnya di deploy log,
# lalu matikan lagi.
#
# ## `|| true` di sini BUKAN kelalaian
#
# Sama alasannya dengan impor direktori: sertifikat yang gagal dibangun ulang
# tetap punya snapshot & PDF lamanya — dokumennya masih utuh, cuma belum ikut
# betul. Menjatuhkan boot karena itu berarti menukar satu berkas yang
# ketinggalan dengan SELURUH server yang dipakai teknisi di lokasi. Gagalnya
# tetap kelihatan di log, dan perintahnya sendiri sudah memisahkan "dilewati"
# dari "gagal" di kode keluarnya.
if [ "${BANGUN_ULANG_ON_BOOT}" = "true" ]; then
    tahap "bangun ulang snapshot & PDF sertifikat"
    echo "!! BANGUN_ULANG_ON_BOOT=true — jalan tiap container nyala." >&2
    echo "   Matikan lagi di Render → Environment sesudah hasilnya kebaca di" >&2
    echo "   log ini, biar tiap boot berikutnya nggak nulis ulang semua PDF." >&2
    php artisan sertifikat:bangun-ulang --render-ulang-pdf || true
fi

# Direktori perusahaan rujukan (10.320 PT) dimuat di sini, bukan lewat shell.
#
# ## Kenapa di boot, bukan sekali manual
#
# Paket gratis Render TIDAK menyediakan shell sama sekali ("Shell is not
# supported for free compute plans"), jadi tidak ada tempat lain buat
# menjalankannya. Tanpa baris ini, tabelnya selamanya kosong di produksi dan
# pencarian PT jatuh ke OpenStreetMap saja — yang cakupannya tipis buat pabrik
# di kawasan industri, persis masalah yang mau ditutup.
#
# ## Kenapa ini aman ditaruh di jendela health check
#
# `--lewati-kalau-terisi` memeriksa isi tabel SEBELUM membaca berkas. Boot
# pertama sesudah deploy membayar penuh (baca 1,3 MB CSV + 22 paket upsert,
# hitungan detik); boot kedua dan seterusnya — termasuk tiap Render
# membangunkan service yang ketiduran — cuma membayar satu query COUNT.
#
# Yang diperiksa ISI tabelnya, bukan penanda "sudah pernah jalan": database
# yang direset bikin penanda berbohong, dan tanpa shell tidak ada yang bisa
# membetulkannya. Hitungan baris selalu jujur, jadi jalur ini memulihkan
# dirinya sendiri.
#
# ## `|| true` di sini BUKAN kelalaian
#
# Direktori ini fitur kenyamanan: tanpa dia, pendaftaran pelanggan tetap jalan
# lewat ketik tangan dan OSM. Membiarkan impor yang gagal menjatuhkan boot
# berarti menukar fitur kenyamanan dengan SELURUH server yang dipakai teknisi
# di lokasi — dan itu pertukaran yang salah arah. Gagalnya tetap kelihatan di
# log, dan `GET /api/health` melaporkan `direktori_perusahaan.lokal.baris`
# supaya keadaannya bisa diperiksa dari luar tanpa masuk ke mana pun.
tahap "muat direktori perusahaan (dilewati kalau sudah terisi)"
php artisan direktori:impor-lokal database/direktori/jababeka.csv \
    --sumber=jababeka --lewati-kalau-terisi || true
php artisan direktori:impor-lokal database/direktori/indonetwork.csv \
    --sumber=indonetwork --lewati-kalau-terisi || true

php artisan storage:link >/dev/null 2>&1 || true

tahap "config:cache"
php artisan config:cache
tahap "view:cache"
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

# Scheduler nebeng di container yang sama, sama alasannya kayak worker di atas:
# paket gratis Render cuma ngasih satu service, jadi nggak ada tempat buat cron
# terpisah.
#
# ## Kenapa perlu
#
# Tanpa baris ini, `routes/console.php` cuma daftar niat. Tiga perintah
# terjadwal di situ NGGAK pernah jalan di produksi:
#
#   alat:cek-jatuh-tempo      07:00  notifikasi alat mendekati jatuh tempo
#   standar:cek-kadaluarsa    07:05  notifikasi sertifikat standar acuan
#   ocr:bersihkan-citra       02:30  retensi citra lembar kerja
#
# Yang ketiga yang paling penting, dan alasannya terbalik dari dugaan: selama
# disknya masih ephemeral, citra lembar kerja pelanggan kehapus sendiri tiap
# deploy — retensinya ketegakkan tanpa sengaja. Begitu berkas pindah ke
# penyimpanan persisten (R2), nggak ada lagi yang menghapus, dan retensi 90 hari
# di config/ocr.php diam-diam berubah jadi SELAMANYA. Itu keputusan soal data
# pelanggan, bukan cuma soal tagihan penyimpanan.
#
# `schedule:work` ngecek tiap menit di proses yang hidup terus — cocok buat
# container, beda dari `schedule:run` yang mengandaikan ada cron di luar.
#
# CATATAN: di paket gratis, container yang ketiduran ikut ngehentikan scheduler,
# jadi jadwal yang jatuh pas service lagi tidur kelewat. Ping dari luar (lihat
# docs/deploy-gratis-render.md langkah 7) yang bikin dia melek — jadi ping itu
# bukan cuma soal responsif, tapi juga syarat jadwal ini kepakai.
(
    while true; do
        php artisan schedule:work || true
        sleep 2
    done
) &

tahap "server jalan di port ${PORT} — jendela health check mulai kepakai"
exec frankenphp run --config /etc/caddy/Caddyfile
