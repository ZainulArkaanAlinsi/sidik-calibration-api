# Runbook insiden (satu halaman)

Berlaku untuk API di Render dan database MySQL. Aturan tetap: **tulis ke data produksi tidak pernah dilakukan sebagai bagian penanganan tanpa instruksi eksplisit pemilik**; env Render hanya diubah dengan izin pemilik untuk kejadian itu; satu push satu deploy.

## 1. Tentukan gejalanya (2 menit)

| Gejala | Kemungkinan |
| --- | --- |
| Semua aplikasi gagal terhubung, `/api/health` tidak menjawab | instance tidur/mati, deploy gagal, database mati |
| Request pertama sangat lambat lalu normal | bangun dari tidur (Render Free); bukan insiden |
| Approve lama atau timeout, yang lain normal | render PDF memakan CPU; sesi tak muat satu halaman; antrean |
| 5xx setelah deploy baru | regresi versi baru |
| 500 hanya pada satu fitur | bug; ambil `request ID`/waktu dari laporan, cari di log |
| Data tampak salah | JANGAN menulis ulang data; kumpulkan bukti dulu (lihat §4) |

## 2. Periksa (urut)

1. `GET /api/health`: `status`, `deploy.versi`, `deploy.lingkungan`, `append_only_db`, status push dan data acuan.
2. Cocokkan `deploy.versi` dengan commit terakhir di `main` dan hasil CI (`gh run list --branch main`). Versi lama yang masih hidup: deploy belum selesai atau balapan; tunggu dulu (sampai 25 menit).
3. Log Render (dashboard atau akses baca): error terbaru, memori, restart.
4. Database: koneksi dari log; status layanan penyedia. Layanan MySQL gratis berakhir 21 Des 2026: jadwalkan pengganti jauh sebelumnya.
5. Cek waktu gangguan terhadap deploy, lonjakan pengguna, dan job berat (render sertifikat, impor).

## 3. Tindakan

- **Regresi versi baru dan perubahan data kompatibel:** kembalikan ke deploy sebelumnya di Render (Rollback/redeploy deploy terdahulu), atau buka PR revert; hanya satu deploy dalam satu waktu. Pastikan `deploy.versi` kembali dan alur utama jalan.
- **Instance tidur:** satu ketukan `/api/health` membangunkan (butuh sampai sekitar satu menit). Workflow terjadwal GitHub mencoba menahannya tetap bangun tetapi tidak andal (sering tertunda atau terlewat); pengecekan layanan uptime luar lebih dapat diandalkan.
- **Render PDF menahan:** tahan menyetujui lembar yang besar sampai pulih; jangan menyalakan gerbang pra-approve di tengah insiden (perlu pengukuran, lihat AGENTS.md).
- **Database mati atau penuh:** tunggu pemulihan penyedia; jangan menjalankan migrasi atau penghapusan; bila perlu pindah, ikuti rencana pengganti database dan pulihkan dari cadangan terbaru.
- **Penerbitan salah dicurigai:** jangan menerbitkan lagi; gunakan daftar `profil_ditahan` (`config/sertifikat.php`) untuk menahan jenis alat yang dicurigai lewat PR kecil; sertifikat terbit ditangani lewat revisi dan penarikan dengan jejak, bukan penghapusan.

## 4. Data salah atau hilang

Kumpulkan: waktu, akun, nomor sesi/sertifikat, versi saat itu, log. Jangan memperbaiki data produksi sendiri. Cadangan dibuat pemilik dengan skrip `mysqldump` (kata sandi tidak masuk repo). Pemulihan diuji berkala: catat waktu pemulihan (RTO) dan umur data yang hilang (RPO) di `docs/operasional/uji-beban.md` atau catatan uji pemulihan.

## 5. Setelah pulih

1. Konfirmasi dengan satu alur terkendali (login, simpan lembar contoh, baca daftar) dan periksa data periode gangguan.
2. Tulis catatan singkat: kronologi, penyebab, perubahan pencegah, bukti uji ulang.
3. Bila penyebabnya jenis uji yang belum ada, tambahkan uji (regresi), bukan hanya perbaikan.

## Kontak dan akses

Pemilik proyek memegang akses Render, database, dan cadangan. Akses produksi untuk penanganan hanya baca kecuali diinstruksikan.

## Skenario Sidik: kejadian dan langkah pertama

| Kejadian | Langkah pertama |
| --- | --- |
| Server tidak merespons | Ketuk `/api/health`; cek status Render dan log; bila tidur, tunggu sampai satu menit; bila mati, restart layanan di Render |
| Habis deploy lalu banyak error | Catat `deploy.versi`; rollback ke deploy sebelumnya di Render (atau PR revert), baru selidiki |
| Database bermasalah | Cek koneksi dari log dan status penyedia; JANGAN menjalankan migrasi atau menghapus; bila data rusak, pulihkan dari cadangan terbaru ke database uji dulu, lalu putuskan |
| Sertifikat tidak terbit atau menggantung | Periksa antrean (`jobs` dan `failed_jobs`) dan penyapu `sertifikat:sapu-tertunda`; ingat penjadwal dan pekerja mati bila container tidur; coba ketuk `/api/health` agar bangun; teknisi tetap bisa mengisi dan menghitung |
| Approve ditolak `profil_ditahan` | Bukan kerusakan: penerbitan jenis alat itu ditahan menunggu Technical Manager; lembar tetap bisa diisi; pelepasan = hapus satu entri `config/sertifikat.php` lewat PR |
| OCR/foto gagal | OCR berjalan di HP; teknisi pakai jalur manual (Mulai kalibrasi); kirim laporan dengan jenis lembar dan jenis HP, tanpa foto pelanggan |
| Realtime (Reverb) mati | Di produksi realtime memang nonaktif; aplikasi menarik ulang data; tidak perlu tindakan |
| Rilis aplikasi bermasalah | Hentikan penawaran pembaruan (turunkan `PELANGGAN_VERSI_TERBARU` atau tandai rilis sebelumnya) dengan izin pemilik; rilis ulang versi sebelumnya lewat jalur biasa |
| Data tampak salah | Jangan menulis ulang data; kumpulkan bukti (§4) dan eskalasi ke pemilik dan Technical Manager |
