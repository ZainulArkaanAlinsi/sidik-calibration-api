# 06 — Risk Register: SIDIK Pelanggan

> Versi 0.1 · 16 Sep 2026 · Ditinjau ulang di akhir tiap milestone.

**Level:** 🟢 Kecil (mengganggu, gampang dibetulkan) · 🟡 Sedang (merugikan sebagian pengguna atau makan waktu) · 🟠 Tinggi (merusak kepercayaan pelanggan atau pekerjaan lab) · 🔴 Kritis (kebocoran data, masalah hukum/akreditasi, atau aplikasi tidak bisa diselamatkan)

Kolom **Tanda** = bagaimana kita tahu risikonya sedang terjadi. Kolom **Kalau terjadi** = langkah pertama, detail di 07-Runbook §9.

---

## A. Selama pembuatan

| ID | Risiko | Level | Pencegahan | Tanda | Kalau terjadi | Owner |
|---|---|---|---|---|---|---|
| R-A01 | Nama field API berubah diam-diam, app pelanggan rusak | 🟡 | Fixture JSON dipakai test di dua repo, `kontrak-api-pelanggan.md` satu sumber, PR backend yang mengubah fixture wajib menyebut PR app | Test fixture merah | Kembalikan nama lama atau rilis `/v2` | Raihan |
| R-A02 | Perubahan untuk pelanggan merusak alur teknisi di produksi | 🟠 | Migrasi additive saja, feature flag, smoke test internal di checklist deploy, deploy di luar jam kerja lapangan | Laporan teknisi, error rate naik setelah deploy | Rollback deploy (07 §8) | Raihan |
| R-A03 | Resource internal dipakai ulang, field internal bocor ke pelanggan | 🟠 | Aturan keras no. 1 di SDD §2, test daftar key respons pelanggan | Review PR, test key respons | Hotfix resource, cek log akses | Raihan |
| R-A04 | Migrasi enum lolos di SQLite tapi gagal di MySQL | 🟡 | Suite MySQL wajib hijau (aturan repo) | CI MySQL merah | Perbaiki sebelum merge | Raihan |
| R-A05 | Konflik kerja dua developer di file yang sama (`routes/api.php`, `pubspec`) | 🟢 | Repo app terpisah, rute pelanggan di file sendiri, PR kecil, CODEOWNERS | Konflik merge berulang | Pair sebentar, bagi ulang task | Keduanya |
| R-A06 | MVP internal (akhir Sep) tertunda karena kerja pelanggan | 🟠 | Hanya M0 yang dikerjakan sebelum MVP internal aman | Task MVP internal molor | Tunda M1+ | Supervisor |
| R-A07 | `applicationId` salah/berubah pikiran setelah upload pertama | 🟠 | Dikonfirmasi PT Sidik di M2-01 sebelum build pertama | — | Tidak bisa diubah: harus membuat listing baru | Arkaan |
| R-A08 | Kode dari app internal ikut tersalin berlebihan ke app pelanggan (OCR, layar admin) | 🟢 | Salin sekali hanya token desain + pola client (M2-03) | Dependency ML Kit muncul di `pubspec` app pelanggan | Hapus, cek ukuran AAB | Arkaan |
| R-A09 | Prompt/konteks AI coding memakai data pelanggan asli | 🟠 | Data dummy di staging & fixture. Folder `Project-PT-Sidik` tidak dijadikan konteks untuk repo pelanggan | Nama PT asli di fixture/PR | Hapus dari histori, laporkan ke supervisor | Keduanya |
| R-A10 | Estimasi meleset karena keputusan PT Sidik (K1–K8) lambat | 🟡 | Daftar keputusan dikirim minggu ini dengan tanggal batas | Task terblokir > 5 hari | Eskalasi ke supervisor, kerjakan task tak terblokir | Arkaan |

## B. Saat uji coba dengan layanan gratis

| ID | Risiko | Level | Pencegahan | Tanda | Kalau terjadi | Owner |
|---|---|---|---|---|---|---|
| R-B01 | Staging memakai server/DB yang sama dengan teknisi | 🔴 | M0-04: service, DB, bucket, Firebase terpisah. `APP_ENV` dicek di health | Data dummy muncul di app teknisi | Hentikan staging, bersihkan data, audit | Raihan |
| R-B02 | Data pelanggan asli masuk staging (dump produksi untuk "tes realistis") | 🔴 | Larangan tertulis di 07 §1, seeder dummy tersedia | Nama PT asli di staging | Hapus DB staging & backup-nya, catat insiden PDP | Raihan |
| R-B03 | Render free tidur → request pertama timeout, penguji mengira app rusak | 🟢 | Timeout app 20 dtk + pesan "server bersiap" di build staging | Keluhan "loading lama pagi hari" | Normal di staging, jelaskan ke penguji | Arkaan |
| R-B04 | Scheduler/queue tidak jalan saat service tidur → pengingat & email tidak terkirim | 🟡 | Pinger cron eksternal (M5-05), algoritma "titik tercapai" | Health: run terakhir > 26 jam | Picu manual, cek pinger | Raihan |
| R-B05 | Kuota gratis habis (email harian, storage, DB) di tengah UAT | 🟡 | Catat kuota tiap layanan di 07 §2, pantau mingguan | Email gagal/422 kuota | Pindah UAT ke produksi-pilot (M6-06) | Raihan |
| R-B06 | Layanan gratis mengubah kebijakan / menghapus paket | 🟡 | Staging bisa dibangun ulang dari `render.yaml` + seeder dalam < 1 hari | Email pemberitahuan penyedia | Pindah penyedia | Raihan |
| R-B07 | "Staging gratis" diam-diam dipakai pelanggan sungguhan | 🔴 | Build staging hanya di track internal testing, ikon & nama bertanda "STAGING", banner di app | Akun non-dev mendaftar di staging | Tutup pendaftaran staging (hanya undangan) | Arkaan |
| R-B08 | Email OTP staging masuk spam / tidak terkirim | 🟢 | Domain pengirim terverifikasi (SPF/DKIM) meski staging | Penguji tidak menerima OTP | Endpoint admin staging untuk melihat OTP (**hanya** `APP_ENV=staging`) | Raihan |

## C. Rilis Google Play

| ID | Risiko | Level | Pencegahan | Tanda | Kalau terjadi | Owner |
|---|---|---|---|---|---|---|
| R-C01 | App dirilis dari akun pribadi developer magang | 🔴 | K1: akun organisasi PT Sidik (D-U-N-S) | Nama developer di listing = nama pribadi | Transfer app ke akun organisasi (proses resmi Play, butuh waktu) | PT Sidik |
| R-C02 | Akun pribadi baru terkena syarat 12 penguji × 14 hari | 🟡 | Pakai akun organisasi. Kalau terpaksa pribadi, rekrut penguji dari staf PT Sidik + pilot sejak awal | Tombol produksi terkunci | Jalankan closed test sesuai syarat | Arkaan |
| R-C03 | Verifikasi developer Android (Indonesia, 30 Sep 2026) belum beres untuk app internal | 🔴 | M0-02 | Teknisi gagal pasang/update APK | Selesaikan verifikasi, sementara pakai track internal testing Play untuk teknisi | Arkaan + PT Sidik |
| R-C04 | Ditolak review: tidak ada akun demo untuk reviewer (app wajib login) | 🟡 | M6-04 akun demo di "App access" | Email penolakan | Isi kredensial, kirim ulang | Arkaan |
| R-C05 | Ditolak review: tidak ada hapus akun di app & web | 🟡 | M1-09 + S20 | Email penolakan | — | Arkaan |
| R-C06 | Data safety tidak sesuai data yang dikumpulkan (misal lupa Crashlytics, foto) | 🟠 | Inventaris data di M6-04 dicocokkan dengan dependency | Peringatan kebijakan | Perbaiki formulir, rilis ulang kalau perlu | Arkaan |
| R-C07 | Target API tidak memenuhi syarat saat update (API 36 sejak 31 Agu 2026, naik tiap tahun) | 🟡 | Kalender tahunan "cek target API" tiap Juni | Peringatan Play Console | Upgrade Flutter/target, minta perpanjangan kalau perlu | Arkaan |
| R-C08 | Upload key hilang / bocor | 🟠 | Play App Signing (Google memegang app signing key), upload key di password manager milik PT Sidik + GitHub Secrets | Build tidak bisa ditandatangani | Reset upload key lewat Play Console | Pemegang akun |
| R-C09 | Versi buruk terlanjur tersebar ke semua pengguna | 🟠 | Rollout bertahap 10/50/100 + kriteria henti 07 §8 | Crash-free < 99%, lonjakan keluhan | Hentikan rollout, rilis perbaikan versionCode lebih tinggi, naikkan versi minimum | Arkaan |

## D. Keamanan & data (setelah dipakai)

| ID | Risiko | Level | Pencegahan | Tanda | Kalau terjadi | Owner |
|---|---|---|---|---|---|---|
| R-D01 | **Pelanggan A melihat data pelanggan B** (IDOR / rute internal terbuka) | 🔴 | M0-06, BR-01/02, test isolasi otomatis (M1-08), review M6-01 | Alarm 404 lintas perusahaan, laporan pelanggan | Runbook insiden kebocoran (07 §9.1), matikan flag | Raihan |
| R-D02 | Orang mengaku sebagai PT X lalu melihat sertifikat PT X | 🔴 | Pendaftaran selalu diverifikasi admin (REQ-AUTH-04), undangan terikat email | PIC utama melaporkan anggota tak dikenal | Nonaktifkan, cabut token, audit akses, kabari PIC utama | Admin lab |
| R-D03 | Karyawan pelanggan resign tapi akunnya masih aktif | 🟠 | PIC utama bisa menonaktifkan, token idle 30 hari mati, pengingat ke PIC utama tiap 6 bulan untuk meninjau daftar anggota | Anggota tidak aktif lama | PIC utama/admin nonaktifkan | PIC utama |
| R-D04 | HP dipakai bergantian (shift pabrik): notifikasi perusahaan A muncul di HP yang sekarang dipakai orang lain | 🟡 | Token FCM dihapus saat keluar, `DeviceToken::catat` memindahkan pemilik token, isi push minim (REQ-NTF-10) | Laporan push "nyasar" | Hapus token perangkat itu | Raihan |
| R-D05 | Tautan PDF sertifikat tersebar dan bisa dibuka siapa pun | 🟡 | URL bertanda tangan 5 menit, bucket privat | URL permanen di log/pesan | Rotasi kredensial bucket kalau bucket ternyata publik | Raihan |
| R-D06 | Foto dari HP pelanggan membawa lokasi GPS pabrik | 🟡 | `PembersihFoto` buang EXIF (M3-04) | Test EXIF | Proses ulang file tersimpan | Raihan |
| R-D07 | Brute force login / spam pendaftaran | 🟡 | Throttle NFR-02, pesan login netral, persetujuan admin | Lonjakan pendaftaran/login gagal | Perketat throttle, blok IP, CAPTCHA sebagai langkah berikut | Raihan |
| R-D08 | Secret bocor lewat repo, APK, atau log | 🟠 | M0-01, gitleaks di CI, tidak ada secret di APK, REQ-PRV-02 | Temuan gitleaks, tagihan aneh | Rotasi key, audit histori & tagihan | Raihan |
| R-D09 | PIC pakai iPhone sehingga tidak bisa memakai app | 🟡 | K8. Sementara: undang PIC lain yang pakai Android, sertifikat tetap dikirim email | Permintaan dari pelanggan | Prioritaskan iOS/portal web di fase berikut | PT Sidik |
| R-D10 | Kebocoran data pribadi tanpa pemberitahuan sesuai UU PDP | 🔴 | Runbook 07 §9.1 memuat kewajiban pemberitahuan (UU 27/2022, notifikasi tertulis maks. 3×24 jam, dikonfirmasi legal) | — | Jalankan runbook, libatkan manajemen & legal | PT Sidik |

## E. Operasional setelah dipakai

| ID | Risiko | Level | Pencegahan | Tanda | Kalau terjadi | Owner |
|---|---|---|---|---|---|---|
| R-E01 | Pengingat tidak terkirim sehingga alat pelanggan lewat jadwal | 🟠 | Scheduler di VPS (bukan free tier), alarm run > 26 jam, email cadangan H-30/H-0 | Alarm, `pengingat_pelanggan_terkirim` kosong | Picu manual, kirim susulan, cek penyebab | Raihan |
| R-E02 | Pengingat terkirim berulang tiap hari (spam) | 🟡 | Tabel dedup + simulasi 60 hari (M5-04) | Keluhan pelanggan, jumlah push/anggota > 1/hari | Matikan scheduler pelanggan, perbaiki, hapus duplikat | Raihan |
| R-E03 | Push tertahan di HP Xiaomi/Oppo/Vivo karena hemat baterai | 🟡 | Kotak notifikasi di app (REQ-NTF-06), email cadangan, panduan setelan baterai di halaman bantuan | Pengguna merek tertentu "tidak pernah dapat notif" | Arahkan ke panduan | Arkaan |
| R-E04 | Tanggal jatuh tempo alat tidak sinkron dengan sertifikat | 🟠 | M0-05, sinkron dipanggil di terbit & revisi | Selisih tanggal alat vs sertifikat (query rekonsiliasi mingguan) | Jalankan `alat:sinkron-jadwal --dry-run`, tinjau, jalankan | Raihan |
| R-E05 | Salah hari karena zona waktu (UTC vs WIB, pelanggan WITA/WIT) | 🟡 | BR-08, scheduler `->timezone('Asia/Jakarta')`, tanggal disimpan date-only | Pengingat H-0 terkirim H-1 | Perbaiki timezone, kirim koreksi kalau perlu | Raihan |
| R-E06 | Inbox menumpuk, pelanggan menunggu lama | 🟠 | K5 SLA, notifikasi ulang 4 jam (REQ-ADM-03), undangan massal bertahap (M7-03) | Permintaan belum diambil > 1 hari kerja | Eskalasi ke manajer operasional | Admin lab |
| R-E07 | Dua admin membalas/menangani permintaan yang sama | 🟢 | Ambil alih atomik (REQ-PMT-04) | Riwayat ganda | Alihkan ke satu admin | Admin lab |
| R-E08 | Admin layanan mengesahkan sertifikat tanpa kewenangan | 🔴 | K4 + M4-07 | Audit log approve oleh user tanpa izin | Tinjau sertifikat terkait sesuai prosedur mutu | Manajer teknis |
| R-E09 | Pelanggan mengubah identitas alat setelah sertifikat terbit → data tidak cocok dengan sertifikat | 🟠 | Penguncian REQ-ALT-04 | — | Koreksi lewat admin + revisi sertifikat bila perlu (§7.8.8) | Admin lab |
| R-E10 | Alat yang diinput pelanggan di luar ruang lingkup akreditasi tapi terlanjur dikerjakan sebagai terakreditasi | 🟠 | Verifikasi lab (REQ-ALT-06) dan tinjauan per item sebelum konfirmasi (REQ-PMT-05, §7.1) | — | Tinjau sesuai prosedur mutu | Admin lab |
| R-E11 | Server produksi down saat jam kerja | 🟠 | VPS + monitoring uptime + backup + runbook | Alarm uptime | 07 §9.3 | Raihan |
| R-E12 | Database rusak/terhapus tanpa backup yang bisa dipulihkan | 🔴 | Backup harian terpisah + uji restore bulanan (M8-01) | Uji restore gagal | Perbaiki proses backup hari itu juga | Raihan |
| R-E13 | File sertifikat/foto hilang karena storage tidak awet | 🟠 | `ARSIP_DRIVER=s3` wajib, flag upload mati otomatis kalau tidak awet | Health `arsip.awet=false` | Bangun ulang PDF dari snapshot, minta ulang foto | Raihan |
| R-E14 | Aplikasi versi lama di HP pelanggan rusak setelah backend berubah | 🟠 | NFR-12 + `kode` error stabil + versi minimum | Error parse di Crashlytics dari versi lama | Kembalikan kompatibilitas atau naikkan versi minimum + layar wajib update | Raihan + Arkaan |

## F. Organisasi, legal, bisnis

| ID | Risiko | Level | Pencegahan | Tanda | Kalau terjadi | Owner |
|---|---|---|---|---|---|---|
| R-F01 | **Magang selesai, tidak ada yang bisa merawat sistem** | 🔴 | K2, GitHub org milik PT Sidik, runbook, serah terima M8-03 dengan uji "orang PT Sidik deploy sendiri" | Semua akun atas nama developer | Serah terima darurat memakai 07 §10 | Supervisor |
| R-F02 | Istilah & tanggal di app bertentangan dengan ISO/IEC 17025 §7.8.4.3 (interval kalibrasi) | 🟠 | K3 sebelum M3, BR-09 | Temuan asesor | Ubah istilah/tampilan, catat kesepakatan dengan pelanggan | Manajer mutu |
| R-F03 | Belum terdaftar PSE Lingkup Privat saat app sudah publik | 🟠 | K6, M6-03 | Surat dari Komdigi | Daftar segera, konsultasi legal | PT Sidik |
| R-F04 | Kebijakan privasi tidak sesuai praktik (misal masa simpan tidak dijelaskan) | 🟡 | Dokumen legal ditinjau bersama dev di M6-03 | Pertanyaan pelanggan/regulator | Revisi dokumen, minta persetujuan ulang (versi baru) | PT Sidik |
| R-F05 | Perusahaan besar meminta kuesioner keamanan vendor sebelum mau memakai | 🟡 | Siapkan ringkasan keamanan 1–2 halaman dari SDD §3, §9, §11 dan 07 | Permintaan dari procurement pelanggan | Isi berdasarkan dokumen ini, jangan mengarang kontrol yang tidak ada | Arkaan + PT Sidik |
| R-F06 | Pelanggan menganggap app "resmi terakreditasi" untuk semua fitur | 🟢 | Teks jelas: akreditasi berlaku untuk ruang lingkup LK-285-IDN | Pertanyaan pelanggan | Klarifikasi di halaman bantuan | Admin lab |
| R-F07 | Biaya berjalan (VPS, domain, email, Play) tidak dianggarkan setelah uji coba | 🟡 | Rincian biaya produksi di 07 §2 diserahkan ke manajemen sebelum M6 | Layanan jatuh tempo pembayaran | Eskalasi ke manajemen | Supervisor |
