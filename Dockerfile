# syntax=docker/dockerfile:1
#
# Image produksi buat Render (paket gratis).
#
# Basisnya FrankenPHP, bukan nginx + php-fpm + supervisor. Alasannya satu:
# paket gratis Render cuma ngasih SATU proses web dengan 512 MB RAM. FrankenPHP
# itu satu binary yang udah jadi web server sekaligus runtime PHP, jadi nggak
# ada tiga proses yang harus dijaga hidup-hidupan di dalam satu container.
#
# TLS-nya dipegang Render di depan, bukan di sini — makanya SERVER_NAME diisi
# ":PORT" doang (tanpa hostname), yang bikin Caddy nyerah bikin sertifikat
# sendiri dan cukup dengerin HTTP polos di port itu.

# ─────────────────────────────────────────────────────────────────────
# Tahap 1 — aset frontend (Vite + Tailwind)
#
# Dipisah dari tahap PHP biar Node nggak ikut kebawa ke image akhir; yang
# nyampe cuma hasil build-nya (public/build), bukan node_modules-nya.
# ─────────────────────────────────────────────────────────────────────
# Node 22, bukan 20. Vite 8 minta `^20.19.0 || >=22.12.0`, dan Rolldown (mesin
# barunya) nitip binding native lewat optionalDependencies. Kalau Node-nya nggak
# masuk syarat itu, npm NGELEWATIN binding-nya tanpa bilang gagal — `npm install`
# kelihatan sukses, baru `npm run build` mati dengan "Cannot find module
# '@rolldown/binding-...'". Tag `node:20` nempel di batas persis syarat itu;
# `node:22` nggak ada urusan sama sekali.
FROM node:22-bookworm-slim AS aset

WORKDIR /app

# package-lock.json emang nggak ada di repo, jadi `npm install`, bukan `npm ci`.
COPY package.json ./
RUN npm install --no-audit --no-fund

# `artisan` ikut disalin karena laravel-vite-plugin mendeteksi root project
# lewat berkas itu — tanpa dia, build-nya bingung naruh manifest di mana.
COPY artisan vite.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build


# ─────────────────────────────────────────────────────────────────────
# Tahap 2 — runtime PHP
# ─────────────────────────────────────────────────────────────────────
# PHP 8.4, bukan 8.3, dan itu WAJIB — bukan sekadar "biar baru".
# config/database.php nyebut `Pdo\Mysql::ATTR_SSL_CA` (bawaan skeleton Laravel
# 13), dan kelas `Pdo\Mysql` itu baru ada sejak PHP 8.4. Di PHP 8.3 berkas
# config-nya fatal waktu dimuat — `php artisan package:discover` di bawah mati,
# jadi build-nya gagal sebelum sempat deploy. Laptop dev juga jalan di 8.4.
FROM dunglas/frankenphp:1-php8.4-bookworm

# pdo_mysql : koneksi ke MySQL Aiven
# gd        : dompdf butuh ini buat gambar (logo & QR di sertifikat)
# intl      : Filament + format angka/tanggal lokal
# bcmath    : hitungan kalibrasi yang nggak boleh kena galat float
# zip       : impor/ekspor Excel
# exif      : baca orientasi foto lembar kerja dari kamera HP
# pcntl     : queue worker butuh ini buat berhenti dengan rapi
# opcache   : wajib di produksi, tanpa ini tiap request nge-compile ulang PHP
RUN install-php-extensions \
        pdo_mysql \
        gd \
        intl \
        bcmath \
        zip \
        exif \
        pcntl \
        opcache

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependency disalin duluan, terpisah dari kode. Selama composer.lock nggak
# berubah, layer ini kepakai lagi dari cache dan deploy jadi jauh lebih cepat.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .
COPY --from=aset /app/public/build ./public/build

# `--no-scripts` di atas bikin package:discover nggak jalan otomatis, jadi
# dipanggil manual di sini — sesudah kode lengkap kesalin.
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
 && php artisan package:discover --ansi \
 && php artisan filament:assets \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Render nyuntik PORT sendiri waktu container jalan; 10000 cuma nilai bawaan
# kalau image ini dijalanin di luar Render.
ENV PORT=10000
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
