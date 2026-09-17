# 07 — Rilis, Operasional & Runbook: SIDIK Pelanggan

> Versi 0.1 · 16 Sep 2026 · Untuk developer **dan** orang PT Sidik yang nanti memegang sistem.
> Semua harga/kuota layanan pihak ketiga berubah-ubah. Cek halaman resmi tiap layanan saat mendaftar, lalu catat angkanya di tabel §2.

## 1. Tiga lingkungan dan aturan emasnya

| | Lokal | Staging (uji coba, gratis) | Produksi |
|---|---|---|---|
| Untuk | Ngoding | Uji fitur oleh dev, admin lab, penguji | Pelanggan asli + teknisi |
| Backend | `php artisan serve` | Service Render free **baru**, terpisah | VPS (lihat `docs/infrastruktur-vps-produksi.md`) |
| Database | Lokal | DB staging terpisah | DB produksi |
| Data | Dummy | **Dummy saja** (seeder) | Asli |
| Storage | local | Bucket R2 staging | Bucket R2 produksi |
| Firebase | `sidik-staging` | `sidik-staging` | `sidik-produksi` |
| App pelanggan | flavor staging, emulator/HP | flavor staging, **Play internal testing** | flavor produksi, Play closed → production |
| Nama/ikon app | "SIDIK Pelanggan STAGING" | sama | "SIDIK Pelanggan" |

**Aturan emas (ditempel di README kedua repo):**

1. Data pelanggan asli **tidak pernah** keluar dari produksi: tidak ke staging, laptop, fixture test, atau prompt AI.
2. Staging **tidak pernah** diam-diam jadi produksi. Kalau uji coba "sudah bagus", yang pindah adalah **kode**, lewat §6–7. Akun dan data uji tidak ikut pindah.
3. Semua akun layanan (Play Console, Firebase, R2, domain, email, VPS, GitHub org) **milik PT Sidik**. Developer diundang sebagai anggota.
4. Tidak ada yang di-deploy ke produksi hari Jumat sore atau di jam sibuk teknisi di lapangan.

## 2. Stack uji coba gratis vs produksi

| Komponen | Uji coba (gratis) | Jebakan yang harus diantisipasi | Produksi (berbayar) |
|---|---|---|---|
| Backend | Render free (service baru) | Tidur saat sepi → request pertama lambat, scheduler & queue berhenti | VPS (2 vCPU / 4 GB sesuai dokumen infra), provider Indonesia dipertimbangkan untuk latensi |
| Scheduler | Pinger cron eksternal gratis memanggil `POST /api/internal/cron` tiap 10–15 menit (token rahasia) | Pinger juga bisa gagal, jadi health menampilkan run terakhir | Cron sistem `* * * * * php artisan schedule:run` atau supervisor `schedule:work` |
| Queue | `database` di proses yang sama | Ikut tidur | Supervisor `queue:work` terpisah, restart otomatis |
| Database | MySQL paket gratis (seperti Aiven yang dipakai sekarang) | Batas ukuran/koneksi, kebijakan layanan idle, tanpa jaminan backup | MySQL di VPS atau managed DB + backup harian ke lokasi lain |
| Storage | Cloudflare R2 paket gratis | Batas penyimpanan & operasi | R2 (biasanya tetap murah), bucket terpisah |
| Email | Layanan email transaksional paket gratis + domain PT Sidik (SPF/DKIM) | Batas kirim harian, mudah masuk spam tanpa DKIM | Paket berbayar sesuai volume |
| Push | Firebase Cloud Messaging | Gratis, tapi tidak dijamin sampai (hemat baterai) | Sama |
| Crash report | Firebase Crashlytics | Wajib dideklarasikan di Data safety | Sama |
| Distribusi app | Play **internal testing** | Butuh akun Play Console (biaya daftar sekali bayar, saat ini US$25 — cek ulang) | Play closed testing → production |
| Domain & halaman legal | Firebase Hosting gratis (`*.web.app`) | Kurang meyakinkan untuk perusahaan besar | Domain PT Sidik |

**Catat di sini saat mendaftar** (supaya kuota habis tidak mengejutkan):

| Layanan | Paket | Batas yang relevan | Pemilik akun | Tanggal dicek |
|---|---|---|---|---|
| Render (staging) | free | | | |
| DB staging | | | | |
| R2 staging | | | | |
| Email | | | | |
| Pinger cron | | | | |

**Satu-satunya biaya yang tidak bisa dihindari sejak awal:** akun Play Console, karena internal testing pun butuh akun, dan jalur Play menghindarkan masalah verifikasi developer untuk APK sideload.

## 3. Jalur Google Play langkah demi langkah

1. **Akun organisasi PT Sidik (K1).** Siapkan nomor D-U-N-S PT Sidik, email khusus (misal `playconsole@domain-ptsidik`), dan pemegang akun dari manajemen. Akun organisasi tidak terkena syarat 12 penguji × 14 hari yang berlaku untuk akun **pribadi** yang dibuat setelah 13 Nov 2023.
2. **Verifikasi identitas developer** di Play Console. Ini sekaligus memenuhi verifikasi developer Android yang berlaku di Indonesia mulai **30 Sep 2026**. Daftarkan juga app internal `com.ptsidik.kalibrasi` (M0-02).
3. **Buat app** `SIDIK Pelanggan`, package `com.ptsidik.pelanggan` (permanen), aktifkan **Play App Signing**, simpan upload key di password manager PT Sidik + GitHub Secrets.
4. **Internal testing** (staging flavor, package `.staging` sebagai app terpisah, atau build produksi yang menunjuk staging, pilih satu dan konsisten). Penguji: dev + admin lab.
5. **Siapkan listing:** nama, deskripsi pendek/panjang (bahasa Indonesia), ikon 512 px, feature graphic, screenshot HP, kategori (Bisnis / Produktivitas), email & situs kontak.
6. **Kebijakan privasi** di URL publik milik PT Sidik. **Tautan hapus akun versi web** (REQ-PRV-03).
7. **Data safety:** inventaris data yang dikumpulkan — nama, email, nomor HP, jabatan, foto (alat), pesan, ID perangkat/token push, crash log & diagnostik. Dienkripsi saat transit: ya. Bisa minta hapus: ya. Dibagikan ke pihak ketiga: sesuaikan dengan layanan yang dipakai (Firebase, email).
8. **Content rating** (kuesioner) dan **Target audience**: dewasa/bisnis, bukan untuk anak.
9. **App access:** app butuh login, jadi berikan akun demo reviewer (perusahaan fiktif "PT Demo Reviewer" di produksi, flag pilot menyala untuknya, tanpa data asli).
10. **Target API:** app baru dan update wajib target Android 16 (API 36) sejak 31 Agu 2026. Cek ulang tiap tahun sekitar Juni.
11. **Closed testing** dengan perusahaan pilot (M6-06/M7-01). Kalau terpaksa akun pribadi: minimal 12 penguji tetap ikut 14 hari berturut-turut, lalu ajukan akses produksi.
12. **Production** dengan staged rollout (§7).
13. **Sertakan app internal:** pertimbangkan memindahkan distribusi app teknisi ke track internal testing/closed Play juga, supaya update tidak lagi bergantung pada APK sideload.

## 4. Alur kode

- Branch: `main` (terlindungi) ← PR dari `fitur/*`, `perbaikan/*`. Squash merge.
- PR wajib: CI hijau, 1 review dari developer lain, tautan ke task (M?-??) dan requirement (REQ-…).
- Backend: tiap merge ke `main` → deploy otomatis ke **staging**. Produksi di-deploy dari tag `api-vYYYY.MM.DD-N` secara manual setelah checklist §6.
- App: versi `MAJOR.MINOR.PATCH+versionCode`, versionCode selalu naik. Tag `v1.2.0` memicu build AAB produksi yang diunggah ke track internal dulu, lalu dipromosikan manual.
- `CHANGELOG.md` di kedua repo, bahasa manusia, per rilis.
- CODEOWNERS: `routes/api_pelanggan.php`, `app/**/Pelanggan/**`, `tests/Feature/Pelanggan/**`, dan migrasi wajib direview dua developer.

## 5. Skenario UAT (dijalankan di HP asli)

Setiap skenario dicatat: tanggal, HP & versi Android, versi app, lulus/gagal, catatan, tangkapan layar.

| # | Skenario | Hasil yang diharapkan |
|---|---|---|
| U01 | Pelanggan lama menerima undangan, aktivasi, melihat alat lama | Alat & sertifikat lama muncul, tanpa alat perusahaan lain |
| U02 | Perusahaan baru daftar → admin setujui → masuk | Notifikasi persetujuan sampai, beranda kosong dengan ajakan |
| U03 | Daftar dengan nama mirip pelanggan lama | Admin melihat saran & bisa menautkan dengan benar |
| U04 | Tambah alat dengan nomor seri kembar | Peringatan muncul, pilihan eksplisit |
| U05 | Ajukan onsite 3 alat → admin tinjau (1 di luar ruang lingkup) → jadwal → teknisi kerja → approve → sertifikat | Timeline benar, per-item benar, notifikasi tiap langkah, tanggal jadwal ulang muncul di alat |
| U06 | Ajukan kirim-lab → isi resi → diterima (1 alat rusak + foto) → kalibrasi → kirim balik → konfirmasi terima | Status & notifikasi benar |
| U07 | Dua admin menekan Ambil bersamaan | Satu berhasil, satu melihat nama penangan |
| U08 | Batalkan permintaan di status yang boleh & yang tidak | Aturan REQ-PMT-08 dipatuhi |
| U09 | Pesan bolak-balik dengan lampiran foto | Notifikasi ke pihak yang benar, foto tanpa EXIF |
| U10 | Sertifikat direvisi | Pelanggan melihat "digantikan", QR lama menunjukkan revisi |
| U11 | PIC utama menonaktifkan staf yang sedang login di HP lain | HP staf langsung ke layar masuk, push berhenti |
| U12 | Mode pesawat di tengah mengajukan | Draf aman, kirim ulang tidak membuat duplikat |
| U13 | Izin notifikasi ditolak | Banner muncul, kotak notifikasi tetap terisi |
| U14 | HP Xiaomi/Redmi dengan hemat baterai, app ditutup, pengingat pagi | Dicatat apakah push datang dan jam berapa |
| U15 | Pengguna login di app internal dengan akun pelanggan & sebaliknya | Ditolak dengan pesan pengarah |
| U16 | Hapus akun | Data pribadi hilang, data perusahaan & sertifikat tetap untuk anggota lain |
| U17 | Versi minimum dinaikkan di server | App lama menampilkan layar wajib update |
| U18 | Teknisi memakai app internal seperti biasa selama UAT | Tidak ada perubahan perilaku, tidak ada error baru |

## 6. Checklist deploy backend produksi

**Sebelum**
- [ ] CI hijau di SQLite **dan** MySQL untuk commit yang akan di-tag
- [ ] Sudah jalan ≥ 1 hari kerja di staging tanpa error baru
- [ ] Migrasi dibaca ulang: additive? bisa rollback? berapa lama di tabel besar?
- [ ] Backup DB produksi diambil tepat sebelum deploy, dan ukurannya masuk akal
- [ ] Tidak ada teknisi yang sedang input sesi di lapangan (cek dengan admin), di luar jam kerja
- [ ] Nilai flag `FITUR_PELANGGAN` yang diinginkan sudah jelas
- [ ] Kalau ini rilis pilot: daftar perusahaan pilot sudah disepakati, dan admin tahu
      bahwa pembatasannya MANUAL — cuma pengajuan akun milik perusahaan itu yang
      disetujui. Tidak ada allowlist otomatis di server.
- [ ] Rencana rollback ditulis: tag sebelumnya = `api-v…`

**Saat**
- [ ] Deploy tag, `php artisan migrate --force`, `config:cache`, restart queue
- [ ] `/api/health`: versi = tag, `arsip.awet=true`, DB ok, `pelanggan.fitur_nyala` sesuai

**Sesudah (≤ 15 menit)**
- [ ] Smoke internal: login teknisi, buka lembar kerja, simpan draf sesi di akun uji, admin buka sesi
- [ ] Smoke pelanggan: login akun demo, buka alat, unduh PDF, buat & batalkan permintaan uji
- [ ] Log error 15 menit tidak naik
- [ ] Catat di `CHANGELOG.md` + kabari grup PT Sidik

## 7. Checklist rilis aplikasi pelanggan

- [ ] Semua task milestone ditutup, tidak ada bug kritis/tinggi terbuka
- [ ] Backend yang dibutuhkan versi ini **sudah** di produksi (§6)
- [ ] Build dari tag, versionCode naik, flavor produksi, menunjuk URL produksi (dicek di layar Akun → versi)
- [ ] Diuji di minimal 3 merek HP dari build yang **sama** dengan yang diunggah
- [ ] Data safety & listing masih sesuai (ada dependency baru?)
- [ ] Catatan rilis bahasa Indonesia
- [ ] Rollout **10%** → pantau 48 jam → **50%** → pantau 48 jam → **100%**
- [ ] Versi minimum di server **tidak** dinaikkan sampai versi baru mencapai 100% dan stabil, kecuali ada perbaikan keamanan

## 8. Kriteria henti & rollback

**Hentikan rollout app kalau salah satu:** crash-free users < 99% · crash baru di alur masuk/beranda/unduh · lebih dari 3 laporan pelanggan tentang masalah yang sama dalam 24 jam · masalah data/keamanan apa pun.

Aplikasi yang sudah terpasang **tidak bisa ditarik**. Langkahnya: hentikan rollout → matikan fitur bermasalah dari server (flag) kalau bisa → rilis versi perbaikan dengan versionCode lebih tinggi → kalau versi rusak berbahaya, naikkan versi minimum setelah perbaikan tersedia.

**Rollback backend kalau:** error rate naik jelas setelah deploy · alur teknisi terganggu · 5xx di `/pelanggan/v1` > 2% selama 10 menit. Langkahnya: deploy tag sebelumnya. Kalau migrasi baru sudah jalan dan additive, biarkan kolomnya (kode lama mengabaikan). Jangan `migrate:rollback` di produksi tanpa backup baru dan persetujuan dua orang.

## 9. Runbook insiden

Untuk semua insiden: tulis log kejadian sejak menit pertama (waktu, siapa, apa yang dilihat, apa yang dilakukan) di `docs/insiden/YYYY-MM-DD-judul.md`.

### 9.1 Dugaan kebocoran data antar pelanggan / akses tidak sah — 🔴

1. **Hentikan dulu:** set `FITUR_PELANGGAN=false` (seluruh API pelanggan 503). App internal tetap jalan.
2. Cabut token user yang dicurigai. Kalau cakupan belum jelas, cabut semua token pelanggan (`personal_access_tokens` dengan ability `pelanggan`).
3. Kabari supervisor & manajemen PT Sidik **segera**.
4. Kumpulkan bukti: log akses dengan `X-Request-Id`, `audit_logs`, log 404 lintas perusahaan, query yang terlibat. Jangan hapus log.
5. Tentukan: data apa, milik perusahaan mana, dilihat siapa, sejak kapan.
6. Perbaiki + tambah test yang mereproduksi celah → deploy → nyalakan flag.
7. **Kewajiban pemberitahuan:** UU PDP (UU 27/2022) mewajibkan pemberitahuan tertulis kepada subjek data dan lembaga terkait paling lambat 3×24 jam bila terjadi kegagalan pelindungan data pribadi. Keputusan dan isi pemberitahuan oleh manajemen + legal, bukan developer. Kerahasiaan pelanggan juga menjadi urusan sistem mutu lab (ISO/IEC 17025 §4.2).
8. Tinjauan pasca-insiden dalam 5 hari kerja.

### 9.2 Pengingat tidak terkirim / terkirim berulang — 🟠

1. Cek `/api/health` → `jadwal_pengingat_terakhir`, dan tabel `pengingat_pelanggan_terkirim` hari ini.
2. **Tidak terkirim:** cek cron/supervisor, log scheduler, antrean. Jalankan `php artisan pelanggan:kirim-pengingat` manual. Algoritma "titik tercapai" akan mengirim yang tertinggal sekali saja.
3. **Berulang:** matikan scheduler pelanggan (flag/komentari jadwal), cari penyebab (constraint unik hilang? tanggal berubah-ubah?), perbaiki, jalankan simulasi M5-04 lagi sebelum menyalakan.
4. Kalau pelanggan sudah menerima spam: kirim satu pesan maaf lewat notifikasi database (bukan push lagi).

### 9.3 Server produksi mati — 🟠

1. Pastikan yang mati apa: VPS, web server, PHP-FPM, DB, disk penuh, sertifikat SSL kedaluwarsa.
2. Nyalakan `PELANGGAN_MAINTENANCE` kalau API masih bisa menjawab sebagian. App pelanggan akan menampilkan layar maintenance.
3. Kabari admin lab (teknisi mungkin tertahan di lapangan).
4. Kalau DB rusak: pulihkan dari backup terakhir ke server uji dulu, cek, baru ke produksi. Catat rentang data yang hilang.

### 9.4 Bug kritis di app yang sudah tersebar — 🟠

1. Hentikan rollout (§8).
2. Bisa dimatikan dari server? (flag, versi minimum, maintenance). Lakukan.
3. Perbaiki di branch `perbaikan/*`, uji di 3 HP, rilis dengan rollout cepat 20% → 100%.
4. Kabari pelanggan pilot/aktif lewat notifikasi database kalau berdampak ke data.

### 9.5 Pelanggan melihat data sertifikat yang salah — 🟠

1. Jangan ubah data di database secara langsung.
2. Admin & manajer teknis menilai lewat prosedur mutu: revisi sertifikat (§7.8.8) atau koreksi data alat.
3. Pastikan revisi memicu sinkron jadwal dan pelanggan melihat "digantikan oleh".
4. Kabari pelanggan lewat pesan di permintaan terkait.

### 9.6 Akun pelanggan dibajak / HP hilang — 🟡

1. PIC utama atau admin menonaktifkan anggota → token & perangkat langsung dicabut.
2. Pemilik akun melakukan lupa sandi dari HP lain. Admin mengaktifkan kembali setelah identitas dikonfirmasi PIC utama.
3. Cek `audit_logs` untuk aksi yang dilakukan selama akun dibajak (permintaan dibatalkan? pesan?).

## 10. Serah terima (sebelum magang selesai)

- [ ] Semua akun (§1 aturan 3) atas nama PT Sidik, minimal **dua** orang PT Sidik punya akses owner di tiap layanan
- [ ] Password manager PT Sidik berisi: upload key + sandinya, kredensial VPS, DB, R2, email, Firebase service account, token cron
- [ ] Akses pribadi developer diturunkan ke member/dicabut sesuai keputusan PT Sidik
- [ ] `BACA-DULU-BACKEND.md`, paket dokumen ini, dan runbook sesuai kondisi terakhir
- [ ] Satu orang PT Sidik berhasil: deploy backend dari tag (§6), rilis app ke internal testing (§7), menjalankan runbook 9.2 di staging
- [ ] Daftar pekerjaan belum selesai + bug terbuka + kalender kewajiban (target API tiap Agustus, perpanjangan domain, masa berlaku akreditasi, pembayaran layanan)
- [ ] Kontak eskalasi & vendor tercatat

## 11. Jadwal rutin

| Frekuensi | Kegiatan | Siapa |
|---|---|---|
| Harian | Cek health & alarm, inbox tidak menumpuk | Dev piket / admin |
| Mingguan | Query rekonsiliasi tanggal alat vs sertifikat, cek kuota layanan, review Crashlytics | Dev |
| Bulanan | Uji restore backup, update dependency keamanan, review akses akun & admin | Dev + PT Sidik |
| Tiap 6 bulan | Pengingat ke PIC utama untuk meninjau anggota, tinjau kebijakan privasi & data safety | Admin + PT Sidik |
| Tahunan (Juni) | Cek syarat target API Google Play, perpanjangan domain/SSL, tinjau risk register | Dev + PT Sidik |
