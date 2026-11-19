# Merawat sistem setelah live: panduan umum dipetakan ke Sidik (19 Nov 2026)

Sumber ide: panduan "Merawat sistem setelah live" yang ditempel pemilik. Di bawah, tiap butir dibandingkan dengan keadaan Sidik yang sebenarnya (dibaca dari repo dan produksi, bukan dari panduan). Status: ADA, SEBAGIAN, BELUM. Kolom "perlu pemilik" = akun, izin, atau keputusan yang tidak bisa diambil sendiri.

## Gambaran arsitektur yang menentukan
Satu container Render Free menjalankan web (FrankenPHP), pekerja antrean (`queue:work`, antrean `database`) dan penjadwal (`schedule:work`) sekaligus. **Container yang tertidur menghentikan penjadwal dan pekerja** (catatan di `docker/entrypoint.sh`). Jadi tidur = tugas terjadwal berhenti, termasuk `sertifikat:sapu-tertunda` (tiap 10 menit) dan pengingat jatuh tempo. Itu alasan penahan tidur dan peringatan ketersediaan penting, dan keterbatasannya ada di `sasaran-layanan.md`.
Aplikasi Lab: OCR berjalan di HP (bukan layanan luar, tidak ada kuota); draf lokal tersimpan di HP; realtime (Reverb) di produksi nonaktif (`/api/health`: `realtime.nyala=false`); render PDF sinkron di request approve (median sekitar 10 detik lokal).

## 1. Sistem yang memberi tahu kalau sakit
| Butir panduan | Keadaan Sidik | Rencana | Perlu pemilik |
| --- | --- | --- | --- |
| Endpoint health | `/api/health` ADA (versi deploy, arsip, push, data acuan, pelanggan, realtime). SEBAGIAN: belum menyatakan koneksi database, penyimpanan, dan jumlah job gagal | tambah blok `kesehatan` (db, storage, antrean: menunggu dan gagal) tanpa data sensitif | tidak |
| Pantau uptime tiap 1-5 menit + alert WhatsApp/Telegram | BELUM andal (jadwal GitHub terlewat: lihat `sasaran-layanan.md`) | UptimeRobot atau Better Stack gratis, cek `/api/health` tiap 5 menit, alert email/Telegram | ya: akun dan bot |
| Pelacak error (Sentry/Crashlytics) | Pelanggan: `firebase_crashlytics` opsional ADA. Lab dan API: BELUM | Sentry gratis untuk Laravel dan Flutter, dengan penyaringan data (tanpa nama pelanggan, tanpa isi lembar, tanpa token, tanpa citra) | ya: akun, DSN (rahasia), persetujuan data apa yang boleh keluar |
| Alert spesifik (antrean macet, job gagal menumpuk, disk/DB penuh, lonjakan 500) | BELUM | perintah terjadwal ringkas yang menulis status ke `/api/health`; alert dari pemantau uptime membaca isinya (kata kunci) | sebagian: pemantau luar |
| Kuota API OCR | tidak berlaku: OCR di HP; mode visi server (Gemini/OpenAI) mati | diperiksa lagi bila mode visi dinyalakan | tidak |
Aturan: alert sedikit tetapi penting; setiap alert baru wajib punya tindakan di runbook.

## 2. Runbook
ADA: `docs/operasional/runbook-insiden.md` (satu halaman). Ditambah tabel khusus Sidik di bagian "Skenario Sidik" runbook (server tidak merespons, setelah deploy banyak error, database, PDF/antrean, realtime, OCR).

## 3. Gagal dengan halus
| Butir | Keadaan Sidik |
| --- | --- |
| Fitur opsional tidak menjatuhkan inti | OCR: input manual selalu ada (jalur Mulai kalibrasi). Realtime: nonaktif, aplikasi memakai penarikan ulang. Push: opsional. ADA |
| Saklar mematikan fitur tanpa deploy | SEBAGIAN: saklar ada tetapi lewat env Render (butuh izin pemilik per kejadian dan deploy ulang): `FITUR_PELANGGAN`, `PELANGGAN_MAINTENANCE`, `VISION_AKTIF`, `OCR_FORMULIR_ASLI_UJI`, `SERTIFIKAT_PRA_APPROVE_SATU_HALAMAN` (opsional), penahanan penerbitan lewat `config/sertifikat.php` (PR). Rencana: saklar di basis data yang bisa diubah Super Admin dari aplikasi (di Studio), dicatat audit |
| Antrean dan coba ulang | ADA: `queue:work --tries=3`, penyapu `sertifikat:sapu-tertunda` tiap 10 menit (hanya jalan bila container bangun) |
| Draf lokal di aplikasi | ADA (draf lokal Lab); catatan: draf yang diarsipkan admin kini menjawab 404 dengan pesan generik |

## 4. Tidak bereksperimen di produksi
| Butir | Keadaan Sidik |
| --- | --- |
| Staging | tidak ada layanan staging terpisah (Render Free; jatah jam 750 per bulan tidak cukup untuk dua layanan). Penggantinya: lingkungan uji LOKAL (TiDB/MySQL + server artisan, skrip E2E di folder kerja QA), CI 4 SQLite + 6 MySQL, dan tinjauan independen sebelum merge |
| Deploy di jam sepi | belum jadi kebiasaan (deploy 05:13 dan 11:47 WIB). **Aturan kerja baru:** perubahan API dan rilis Lab digabung di luar jam kerja lab (usul: sebelum 07:00 atau setelah 17:00 WIB) kecuali mendesak; jam kerja lab diisi pemilik |
| Rilis bertahap | Lab: ADA saluran tester (Firebase App Distribution) tetapi APK rilis dan tester keluar bersamaan. Rencana: gelombang tester dulu, pengguna lain sesudah beberapa jam tanpa masalah; ada kolom `wajib` pada versi terbaru |
| Tahu cara rollback sebelum deploy | tertulis di runbook; kebiasaan: catat `deploy.versi` sebelum merge |

## 5. Perawatan rutin
Lihat `kalender-perawatan.md` (jadwal bulanan/kuartalan/tanggal kedaluwarsa). Inti: tes pemulihan cadangan tiap bulan (skrip `mysqldump` pemilik; RPO dan RTO dicatat), pembaruan dependensi terjadwal (usul: Dependabot mingguan untuk Composer dan pub, dengan PR yang lewat CI), pembersihan log dan penyimpanan, tinjau performa bulanan (endpoint paling lambat dan error paling sering), tanggal kedaluwarsa (Aiven gratis berakhir 21 Des 2026, kunci layanan, token, domain).

## 6. Setelah masalah selesai
Template `catatan-insiden-TEMPLATE.md` (empat butir: apa terjadi, penyebab, pemulihan, perubahan pencegah) disimpan di folder `docs/operasional/insiden/` dan dirujuk tes regresi atau alert baru. Kebiasaan yang sudah berjalan di proyek ini: bug yang lolos menjadi pelajaran tertulis (AGENTS.md "Aturan yang lahir dari kesalahan nyata").

## 7. Jangan bergantung pada satu orang
`akses-dan-env.md`: daftar NAMA variabel env dan siapa memegang akses (tanpa nilai rahasia), cara deploy dan rollback di `runbook-insiden.md`. Pengetahuan yang hanya ada di kepala satu orang adalah risiko terbesar: lengkapi bagian "pemegang akses" dengan nama dan cadangan.
