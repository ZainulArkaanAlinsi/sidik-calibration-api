# 08 — Prompt Claude Code untuk M0 & M1

> Tempel **satu blok per sesi**, jangan sekaligus. Jalankan di repo `sidik-calibration-api` setelah paket dokumen ini disalin ke `docs/pelanggan/`.
> Setiap blok berhenti di titik yang butuh review manusia. Jangan lanjut ke blok berikutnya sebelum PR sebelumnya di-merge.

---

## Blok 0 — Tambahkan aturan modul pelanggan ke CLAUDE.md

```
Baca CLAUDE.md, docs/BACA-DULU-BACKEND.md, dan docs/pelanggan/03-SDD.md §2–§3.

Tambahkan satu bagian baru di CLAUDE.md berjudul "Modul Pelanggan — aturan keras" yang merangkum:
1. Rute pelanggan hanya di routes/api_pelanggan.php, prefix api/pelanggan/v1.
2. Controller/Request/Resource pelanggan hanya di namespace Pelanggan; Resource pelanggan tidak boleh memakai ulang Resource internal.
3. customer_id tidak pernah diambil dari request; selalu dari KonteksPerusahaan.
4. Data milik perusahaan lain dijawab 404.
5. Rute ber-parameter baru wajib punya kasus di IsolasiPerusahaanTest.
6. Migrasi untuk pelanggan additive saja.

Jangan ubah kode. Tunjukkan diff CLAUDE.md, lalu berhenti.
```

## Blok 1 — M0-05: sinkron jadwal alat

```
Konteks: docs/pelanggan/03-SDD.md §6 dan 02-SRS.md REQ-ADM-04.
Masalah: approve menyimpan berlaku_sampai di certificates, tapi PengingatJatuhTempo membaca
equipments.tanggal_jatuh_tempo yang tidak pernah diperbarui otomatis.

Kerjakan:
1. Service App\Services\SinkronJadwalAlat dengan method untuk(Equipment $alat): void sesuai SDD §6.
   Sertifikat aktif = status terbit, belum digantikan revisi, diterbitkan_pada terbaru.
   Kalau tidak ada sertifikat aktif, JANGAN timpa tanggal yang sudah ada.
2. Panggil di GenerateCertificate saat sertifikat menjadi terbit (di transaksi yang sama),
   dan di alur revisi sertifikat (cari tempatnya; laporkan kalau alurnya tidak ada).
3. Command alat:sinkron-jadwal dengan --dry-run yang mencetak tabel: equipment_id, nama_alat,
   tanggal lama → tanggal baru, sumber sertifikat. Tanpa --dry-run baru menulis.
4. Test (dua suite): approve dengan berlaku_sampai kustom; approve tanpa berlaku_sampai
   (default masa berlaku organisasi); revisi dengan tanggal baru; sertifikat gagal generate
   tidak mengubah alat; alat impor tanpa sertifikat tetap.

Jalankan php artisan test dan php artisan test -c phpunit.mysql.xml. Pint hanya file yang disentuh.
Catat perubahan di docs/BACA-DULU-BACKEND.md. JANGAN jalankan command tanpa --dry-run
di database mana pun. Berhenti dan tampilkan ringkasan + hasil test.
```

## Blok 2 — M0-06: gerbang rute deny-by-default

```
Konteks: docs/pelanggan/03-SDD.md §3.2, 06-Risk-Register.md R-D01.
Masalah: rute GET di grup auth:sanctum hanya disaring organization_id; channel organisasi.{id}
menerima user apa pun di organisasi itu. Role pelanggan belum ada, tapi celah ini harus
ditutup SEBELUM role itu dibuat.

Kerjakan:
1. Bungkus seluruh grup auth:sanctum di routes/api.php dengan role:admin,teknisi,viewer.
   Pastikan rute yang memang untuk semua role internal (me, notifications, dll) tetap jalan
   untuk ketiga role itu.
2. Channel organisasi.{organizationId} hanya untuk role admin/teknisi/viewer.
3. Test RuteInternalMenolakPelangganTest: iterasi semua rute api/* (kecuali publik:
   health, login, register, forgot/reset-password, app/versi-terbaru, verify/{qr_token},
   dan prefix api/pelanggan) memakai user dengan role fiktif 'pelanggan' (buat langsung di
   factory, tanpa migrasi enum dulu kalau kolom role berupa string; kalau enum, beri tahu saya
   dan berhenti). Harapan: 403 untuk semua. Test harus otomatis mencakup rute baru.
4. Test channel auth untuk role fiktif itu → ditolak.
5. Pastikan seluruh test yang ada tetap hijau di dua suite.

Jangan mengubah perilaku untuk admin/teknisi/viewer. Berhenti dengan daftar rute yang tercakup
dan hasil test.
```

## Blok 3 — M1-01 & M1-02: migrasi identitas + registrasi rute pelanggan

```
Konteks: docs/pelanggan/03-SDD.md §4.1–§4.2 (tabel identitas saja: customer_members,
undangan_pelanggan, pengajuan_akun_pelanggan, persetujuan_dokumen) dan §10.

Kerjakan:
1. Cek tipe kolom users.role dan users.status di MySQL. Laporkan dulu sebelum menulis migrasi
   yang mengubahnya.
2. Migrasi additive sesuai SDD, semuanya punya down() yang benar.
3. Model + factory untuk tabel baru. Pakai trait Diaudit di model yang menyimpan aksi manusia.
4. config/pelanggan.php membaca FITUR_PELANGGAN, FITUR_PELANGGAN_PILOT_IDS,
   PELANGGAN_VERSI_MINIMUM, PELANGGAN_MAINTENANCE (lewat config, bukan env() di luar config —
   ikuti alasan di config/deploy.php).
5. routes/api_pelanggan.php terdaftar di bootstrap/app.php dengan prefix api/pelanggan/v1.
   Middleware FiturPelanggan: flag mati → 503 {kode: "belum_tersedia", message}.
   Satu rute publik GET /app/status.
6. Test: flag mati/nyala, /app/status tanpa DB query, migrasi up-down-up di suite MySQL.

Update docs/BACA-DULU-BACKEND.md. Berhenti.
```

## Blok 4 — M1-03 s.d. M1-06: auth pelanggan

```
Konteks: docs/pelanggan/02-SRS.md REQ-AUTH-01..10 dan 03-SDD.md §3.1, §7.1, §7.2 (bagian akun).

Kerjakan bertahap, satu commit per langkah:
a. Middleware aplikasi:{internal|pelanggan} berbasis ability token. Login internal mulai
   memberi ability 'internal'. Token lama tanpa ability diterima di middleware internal
   sampai tanggal di config pelanggan.cutoff_token_lama (default +30 hari dari deploy).
b. Daftar + OTP email (hash) + kirim ulang + status pending_email/pending_verifikasi.
c. Masuk pelanggan: ability pelanggan / pelanggan:menunggu, expires_at 90 hari, cek idle 30 hari
   dari last_used_at. Login silang (REQ-AUTH-07) ditolak dua arah dengan kode stabil.
d. Keluar, keluar-semua, saya (GET/PATCH), ganti sandi (mencabut token lain), lupa & atur ulang sandi.
e. Throttle bernama sesuai 02-SRS NFR-02, didefinisikan di AppServiceProvider::rateLimiters()
   seperti pola yang sudah ada.
f. Fixture JSON respons di tests/Fixtures/pelanggan/ dan docs/kontrak-api-pelanggan.md versi 0.1.

Semua error non-422 punya field "kode". Pesan login tidak membedakan email tidak terdaftar vs
sandi salah. Jangan log sandi/OTP/token. Test tiap REQ disebut di nama method.
Berhenti setelah (f) dengan daftar endpoint + hasil dua suite.
```

## Blok 5 — M1-05, M1-07, M1-08: persetujuan, konteks perusahaan, test isolasi

```
Konteks: 02-SRS REQ-AUTH-04..06, REQ-ANG-01..04; 03-SDD §3.3, §3.4, §7.3 (pengajuan-akun,
undangan), §12 baris "Keamanan".

Kerjakan:
1. Endpoint admin pengajuan-akun (list + saran pelanggan mirip via customers.nama_normal,
   setujui dengan customer_id atau pelanggan_baru, tolak) dan undangan.
2. Terima undangan (publik), kode hash, sekali pakai, 7 hari, terikat email.
3. Middleware KonteksPerusahaan + header X-Perusahaan-Id + otorisasi pic_utama/staf.
4. Endpoint anggota (list, undang, batalkan undangan, nonaktifkan) + pencabutan token & device
   token saat nonaktif.
5. IsolasiPerusahaanTest: baca Route::getRoutes() untuk prefix api/pelanggan/v1 yang punya
   parameter; gagal kalau ada rute tanpa entri di data provider. Dua perusahaan fiktif, silang
   ID → 404.

Notifikasi memakai PenerimaNotifikasi dan channel database + push yang sudah ada.
Berhenti dengan hasil dua suite dan daftar rute yang tercakup test isolasi.
```
