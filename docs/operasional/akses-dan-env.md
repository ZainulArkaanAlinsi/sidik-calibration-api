# Akses dan variabel lingkungan (tanpa nilai rahasia)

Dokumen ini hanya memuat NAMA variabel dan PEMEGANG akses. Nilai rahasia tidak pernah ditulis di sini atau di repo. Sumber daftar nama: `render.yaml`.

## Pemegang akses (isi nama orang dan cadangannya)
| Sistem | Fungsi | Pemegang | Cadangan |
| --- | --- | --- | --- |
| GitHub (tiga repo, Actions, rilis) | kode, CI, rilis APK | _isi_ | _isi_ |
| Render (layanan API) | deploy, log, env | _isi_ | _isi_ |
| Database MySQL (Aiven; pengganti 28 Nov) | data lab | _isi_ | _isi_ |
| Firebase (Hosting, App Distribution, FCM/push, Crashlytics) | halaman unduh, tester, notifikasi | _isi_ | _isi_ |
| Keystore penandatangan APK | tanda tangan rilis Lab dan Pelanggan | _isi_ (lokasi cadangan _isi_) | _isi_ |
| Deploy Hook Render | memicu deploy dari CI | tersimpan sebagai rahasia GitHub | _isi_ |
| Kunci QR/verifikasi publik | token QR sertifikat (kolom `qr_token`) | basis data | _isi_ |
| Layanan uptime/alert (bila dipasang) | pemantauan | _isi_ | _isi_ |

## Variabel lingkungan (nama), per kelompok
- Aplikasi: `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_TIMEZONE`, `APP_LOCALE`, `APP_KEY`, `LOG_CHANNEL`, `LOG_LEVEL`.
- Basis data: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSL_CA_B64`; antrean/sesi/cache: `QUEUE_CONNECTION`, `DB_QUEUE_RETRY_AFTER`, `SESSION_DRIVER`, `CACHE_STORE`; `BROADCAST_CONNECTION`.
- Berkas dan arsip: `FILESYSTEM_DISK`, `ARSIP_DRIVER`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_DEFAULT_REGION`, `AWS_USE_PATH_STYLE_ENDPOINT`, `AWS_URL`.
- OCR/visi (opsional, mati): `VISION_AKTIF`, `VISION_DRIVER`, `VISION_DRIVER_CADANGAN`, `GEMINI_MODEL`, `GEMINI_API_KEY`, `OPENAI_MODEL`, `OPENAI_API_KEY`, `OCR_FORMULIR_ASLI_UJI`.
- Direktori perusahaan: `DIREKTORI_PERUSAHAAN_DRIVER`, `DIREKTORI_PERUSAHAAN_USER_AGENT`, `DIREKTORI_PERUSAHAAN_KEY`, `GITHUB_TOKEN`.
- Data acuan dan sertifikat: `DATA_ACUAN_SUMBER`, `SERTIFIKAT_KOP_PRACETAK`; opsional `SERTIFIKAT_PRA_APPROVE_SATU_HALAMAN` (sengaja belum terdaftar di blueprint).
- Boot: `SEED_ON_BOOT`, `BANGUN_ULANG_ON_BOOT`, `ACUAN_IMPOR_AWAL_ON_BOOT`, `AKUN_ADMIN_EMAIL`, `AKUN_ADMIN_NAMA`, `AKUN_ADMIN_ID_PEGAWAI`, `AKUN_ADMIN_DEPARTEMEN`, `SEED_ADMIN_PASSWORD`.
- Aplikasi Pelanggan/Studio: `FITUR_PELANGGAN`, `PELANGGAN_VERSI_MINIMUM`, `PELANGGAN_VERSI_TERBARU`, `STUDIO_VERSI_MINIMUM`, `STUDIO_AKUN`, `PELANGGAN_MAINTENANCE`, `PELANGGAN_PESAN_MAINTENANCE`, `KABAR_TAHAP_TURUNAN`, `PELANGGAN_CUTOFF_TOKEN_LAMA`, `PELANGGAN_VERSI_KEBIJAKAN_PRIVASI`, `PELANGGAN_VERSI_SYARAT_KETENTUAN`, dan kelompok `PELANGGAN_INFO_*` (nama, alamat, telepon, WhatsApp, surel, jam layanan, tautan kebijakan privasi/syarat/hapus akun, petunjuk pengemasan).

## Aturan
1. Mengubah env di Render hanya dengan izin pemilik untuk kejadian itu; perubahan dashboard bisa tertimpa sinkron blueprint (pernah terjadi pada `ARSIP_DRIVER`).
2. Kunci baru wajib ke `.env.example` dan `render.yaml`.
3. Rahasia tidak masuk repo, log, catatan kerja, atau perintah agen; simpan di manajer sandi pemilik.
