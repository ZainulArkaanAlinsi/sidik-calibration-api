# Kalender perawatan (isi tanggal, jangan menunggu rusak)

Kolom "tanggal" kosong = pemilik mengisi. Tanggal yang sudah diketahui dari repo dan penyedia ditulis.

## Berulang
| Frekuensi | Pekerjaan | Cara | Bukti |
| --- | --- | --- | --- |
| Harian | Pantau surel kegagalan workflow `Jaga server bangun` dan peringatan uptime (bila sudah dipasang) | surel | tidak ada alert terbuka |
| Mingguan | Tinjau PR pembaruan dependensi (Composer, pub, GitHub Actions) bila Dependabot dipasang; jalankan CI | PR lewat CI hijau | PR digabung atau ditolak dengan alasan |
| Bulanan | **Uji pulihkan cadangan**: jalankan skrip cadangan, pulihkan ke database uji LOKAL, hitung baris tabel kunci (sertifikat, sesi, pengguna), catat waktu (RTO) dan umur data (RPO) | skrip cadangan pemilik + `mysql` lokal | catatan di `docs/operasional/insiden/` atau `uji-pemulihan-YYYY-MM.md` |
| Bulanan | Tinjau performa: endpoint paling lambat, error 500 paling sering, ukuran database dan penyimpanan arsip | log Render, `/api/health`, penyedia DB | 3 angka tercatat |
| Bulanan | Bersihkan berkas lama (citra OCR sementara dibersihkan otomatis `ocr:bersihkan-citra` jam 02:30); periksa penyimpanan arsip PDF | `/api/health` blok arsip | ukuran |
| Kuartalan | Perbarui rilis keamanan mayor (PHP, Laravel, Filament, Flutter) dengan PR terpisah dan tes penuh | CI | PR |
| Kuartalan | Latihan rollback: deploy lalu rollback satu versi di jam sepi | runbook | catatan |
| Tahunan | Tinjau seluruh daftar akses dan rotasi kunci | `akses-dan-env.md` | tanggal rotasi |

## Tanggal yang diketahui
| Tanggal | Hal | Tindakan |
| --- | --- | --- |
| 28 Nov 2026 | Batas keputusan database pengganti (Aiven gratis berakhir) | pilih pengganti; uji beban ulang |
| 5 Des 2026 | Migrasi database selesai (sasaran) | cadangan, migrasi, verifikasi paritas |
| 21 Des 2026 | Layanan MySQL gratis Aiven dihentikan | HARUS sudah pindah |
| _isi_ | Sertifikat SSL: Render memperbarui otomatis; domain khusus bila dipakai | catat tanggal bila ada domain sendiri |
| _isi_ | Kunci penandatangan APK (keystore): tersimpan di luar repo; catat lokasi cadangan dan siapa yang tahu kata sandinya | pemilik |
| _isi_ | Token GitHub, kunci layanan Firebase, kunci push, Deploy Hook Render: catat tanggal dibuat dan tanggal tinjau | pemilik |
| _isi_ | Retensi penyimpanan Firebase Hosting (rilis desktop/web gagal 429) | Console: Release storage settings |
