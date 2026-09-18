# 02 — SRS: SIDIK Pelanggan

> Versi 0.1 · 16 Sep 2026 · Turunan dari `01-PRD.md` · Dokumen berikutnya: `03-SDD.md`, `04-UI-UX-Flow.md`

Tiap requirement ditulis Given/When/Then dan bisa langsung jadi test. ID requirement dirujuk di 05-Task dan di nama test (misal `REQ_ALT_03_...`).

---

## 1. Akun & akses (AUTH)

**REQ-AUTH-01 — Daftar perusahaan baru**
Given pengguna belum punya akun
When dia mengisi nama, email, sandi, nomor HP, jabatan, nama perusahaan, alamat perusahaan, lalu mengirim
Then akun dibuat dengan status `pending_email`, OTP 6 digit dikirim ke email (berlaku 10 menit), dan belum ada akses data apa pun.

**REQ-AUTH-02 — Verifikasi email**
Given akun berstatus `pending_email`
When pengguna memasukkan OTP yang benar
Then status menjadi `pending_verifikasi`, dibuat baris `pengajuan_akun_pelanggan` berstatus `menunggu`, dan semua admin aktif (atau PIC admin kalau nama perusahaan cocok dengan pelanggan yang punya PIC) mendapat notifikasi.
And OTP salah 5 kali mengunci verifikasi selama 15 menit.

**REQ-AUTH-03 — Menunggu verifikasi**
Given akun `pending_verifikasi`
When pengguna login
Then login berhasil tetapi token hanya berkemampuan `pelanggan:menunggu`. Aplikasi hanya menampilkan layar status pengajuan dan kontak PT Sidik. Semua endpoint data membalas 403 `akun_belum_diverifikasi`.

**REQ-AUTH-04 — Admin menyetujui pengajuan**
Given pengajuan `menunggu`
When admin memilih pelanggan yang sudah ada (dari saran nama mirip berdasarkan `nama_normal`) atau membuat pelanggan baru, lalu menyetujui
Then dibuat `customer_members` dengan peran `pic_utama` kalau perusahaan belum punya anggota aktif, atau `staf` kalau sudah ada. Status akun menjadi `aktif`, dan pengguna menerima push + email.
And kalau perusahaan sudah punya PIC utama aktif, PIC utama itu juga dikabari bahwa ada anggota baru.

**REQ-AUTH-05 — Admin menolak pengajuan**
Given pengajuan `menunggu`
When admin menolak dengan alasan (wajib, minimal 10 karakter)
Then pengajuan `ditolak`, pengguna dikabari beserta alasannya, dan akun tetap tanpa akses data.

**REQ-AUTH-06 — Aktivasi dengan kode undangan**
Given admin atau PIC utama membuat undangan untuk email X dan perusahaan Y
When pemilik email X membuka aplikasi, memilih "Punya kode undangan", memasukkan kode, dan membuat sandi
Then akun langsung `aktif` sebagai anggota Y dengan peran yang ditentukan di undangan, tanpa antrean verifikasi.
And kode sekali pakai, berlaku 7 hari, disimpan dalam bentuk hash, dan hanya cocok untuk email yang diundang.

**REQ-AUTH-07 — Login dipisah per aplikasi**
Given akun role `pelanggan`
When akun itu login di aplikasi internal (`POST /api/login`)
Then ditolak 403 dengan pesan yang mengarahkan ke aplikasi SIDIK Pelanggan.
And sebaliknya akun `admin`/`teknisi`/`viewer` yang login di `POST /api/pelanggan/v1/auth/masuk` juga ditolak 403.

**REQ-AUTH-08 — Masa berlaku token**
Given anggota login dari aplikasi pelanggan
When token tidak dipakai 30 hari, atau sudah 90 hari sejak dibuat
Then token tidak berlaku lagi (401) dan aplikasi mengarahkan ke layar masuk tanpa kehilangan draf lokal.

**REQ-AUTH-09 — Pencabutan akses**
Given anggota aktif
When keanggotaannya dinonaktifkan (oleh PIC utama atau admin), sandinya diganti, atau akunnya dihapus
Then semua token anggota itu dicabut dalam request yang sama, dan token perangkatnya (FCM) dihapus.

**REQ-AUTH-10 — Pembatasan percobaan**
Given siapa pun
When gagal login 5 kali berturut-turut untuk email yang sama dalam 15 menit
Then login untuk email itu diblokir 15 menit. Pesannya tidak membedakan "email tidak terdaftar" dan "sandi salah".

**REQ-AUTH-11 — Hapus akun**
Given anggota aktif
When memilih Hapus Akun, mengetik ulang sandi, dan mengonfirmasi
Then data pribadi anggota (nama, email, HP, jabatan, foto profil) dianonimkan, token & perangkat dihapus, dan keanggotaan dinonaktifkan.
And data perusahaan, alat, permintaan, dan sertifikat **tidak** dihapus (rekaman lab), dan hal ini dijelaskan di layar konfirmasi.
And kalau dia satu-satunya PIC utama aktif, penghapusan tetap boleh, dan admin dikabari bahwa perusahaan tidak punya PIC utama.

## 2. Anggota perusahaan (ANG)

**REQ-ANG-01** Given PIC utama · When mengundang email baru dengan peran `staf` atau `pic_utama` · Then undangan dibuat (REQ-AUTH-06) dan email undangan terkirim. Maksimal 50 anggota aktif per perusahaan (bisa diubah admin).

**REQ-ANG-02** Given PIC utama · When menonaktifkan anggota lain · Then REQ-AUTH-09 berlaku. PIC utama tidak bisa menonaktifkan dirinya sendiri kalau dia satu-satunya PIC utama aktif.

**REQ-ANG-03** Given staf · When membuka menu Anggota · Then hanya bisa melihat daftar, tanpa aksi undang atau nonaktifkan (API membalas 403).

**REQ-ANG-04** Given pengguna anggota dari lebih dari satu perusahaan (misal konsultan) · When membuka aplikasi · Then dia memilih perusahaan aktif. Semua request membawa header `X-Perusahaan-Id` yang divalidasi ke keanggotaan aktif. Header yang tidak valid membalas 404.

## 3. Alat (ALT)

**REQ-ALT-01 — Isolasi**
Given anggota perusahaan A
When meminta daftar atau detail alat
Then hanya alat dengan `customer_id` = A yang dikembalikan. ID alat milik perusahaan lain membalas **404** (bukan 403).

**REQ-ALT-02 — Tambah alat**
Given anggota aktif
When menambah alat dengan data valid
Then alat tersimpan dengan `diinput_oleh = pelanggan` dan `status_verifikasi_lab = belum`, lalu admin (PIC admin kalau ada) mendapat notifikasi verifikasi alat.

**REQ-ALT-03 — Nomor seri kembar**
Given perusahaan sudah punya alat dengan nomor seri S (tanpa membedakan huruf besar/kecil dan spasi)
When anggota menambah alat dengan nomor seri S
Then aplikasi menampilkan peringatan berisi alat yang sudah ada, dan pengguna harus memilih "Tetap simpan" secara eksplisit.

**REQ-ALT-04 — Penguncian identitas**
Given alat sudah punya minimal satu sertifikat terbit
When anggota mencoba mengubah nama alat, merk, model, nomor seri, nomor identifikasi, range, atau resolusi
Then field itu terkunci (API 422 `field_terkunci`). Aplikasi menawarkan "Minta koreksi ke lab", yang membuat pesan ke admin.
And `lokasi` dan `catatan` tetap boleh diubah.

**REQ-ALT-05 — Status kalibrasi yang ditampilkan** (dihitung server, zona waktu Asia/Jakarta)

| Kondisi (dicek berurutan) | Status |
|---|---|
| Ada item permintaan aktif untuk alat ini (belum selesai/batal/ditolak) | `dalam_proses` |
| Belum pernah ada sertifikat terbit | `belum_pernah` |
| `tanggal_jatuh_tempo` < hari ini | `lewat_jadwal` |
| `tanggal_jatuh_tempo` ≤ hari ini + 30 hari | `mendekati_jadwal` |
| Ada sertifikat terbit | `terkalibrasi` |
| Alat berstatus `nonaktif` | `nonaktif` (disembunyikan dari filter bawaan) |

**REQ-ALT-06 — Verifikasi lab**
Given alat `status_verifikasi_lab = belum`
When admin memverifikasi dengan memilih kategori dan `nama_alat_kemampuan`
Then status menjadi `terverifikasi`. Kalau alat di luar ruang lingkup akreditasi, admin wajib memilih `di_luar_ruang_lingkup` dengan catatan, dan pelanggan melihat keterangan itu di detail alat.

**REQ-ALT-07 — Foto** · Foto nameplate maksimal 3 per alat, ≤ 5 MB sebelum kompresi. Server membuang metadata EXIF (termasuk lokasi GPS) dan mengompres ulang.

## 4. Sertifikat (SRT)

**REQ-SRT-01** Given alat milik perusahaan A · When anggota A membuka riwayat sertifikat · Then semua sertifikat berstatus `terbit` ditampilkan, terbaru di atas, dengan nomor, tanggal kalibrasi, tanggal terbit, jadwal kalibrasi ulang, dan keputusan.

**REQ-SRT-02 — Revisi** Given sertifikat X direvisi menjadi X' · When pelanggan membuka X · Then X ditandai "Digantikan oleh X'" dengan tautan ke X'. Halaman verifikasi QR publik untuk X juga menunjukkan bahwa X sudah digantikan (ISO/IEC 17025 §7.8.8).

**REQ-SRT-03 — Unduh** Given anggota berhak · When mengunduh PDF · Then server mengembalikan URL bertanda tangan yang berlaku ≤ 5 menit. Tautan unduh tidak pernah permanen dan tidak bisa dibagikan ke luar.

**REQ-SRT-04 — Keputusan FAIL** Given keputusan sertifikat FAIL · When ditampilkan · Then label yang dipakai "Tidak memenuhi toleransi", dengan penjelasan singkat dan tombol "Tanya lab". Tidak disembunyikan dan tidak dilunakkan.

**REQ-SRT-05 — Status sesi internal disembunyikan** Pelanggan tidak pernah melihat data mentah pengukuran, budget ketidakpastian, nama reviewer, catatan revisi internal, atau status `perlu_revisi`.

## 5. Permintaan kalibrasi (PMT)

**REQ-PMT-01 — Membuat permintaan**
Given anggota aktif dan minimal satu alat miliknya
When memilih 1–50 alat, memilih metode (`onsite` / `kirim_ke_lab`), mengisi detail wajib, lalu mengirim
Then permintaan dibuat dengan status `diajukan`, nomor `PMT/{tahun}/{bulan}/{4-digit}`, dan riwayat status pertama.
And request membawa `client_request_id` (UUID). Kiriman ulang dengan ID yang sama mengembalikan permintaan yang sama, bukan duplikat.

**REQ-PMT-02 — Detail wajib**
- Onsite: alamat lokasi, nama & HP kontak di lokasi, rentang tanggal yang diinginkan (mulai ≥ besok, rentang ≤ 60 hari).
- Kirim ke lab: tidak ada field wajib tambahan. Aplikasi menampilkan alamat lab dan petunjuk pengemasan. Resi bisa diisi nanti.

**REQ-PMT-03 — Alat yang tidak boleh diajukan**
Given alat sudah ada di permintaan aktif lain, atau alat `nonaktif`
When dipilih
Then tidak bisa dipilih, dan aplikasi menunjukkan alasannya.

**REQ-PMT-04 — Inbox & ambil alih**
Given permintaan `diajukan`
When dua admin menekan "Ambil" hampir bersamaan
Then hanya satu yang berhasil (update atomik `WHERE ditangani_oleh IS NULL`). Yang lain menerima 409 berisi nama admin yang menangani. Status menjadi `ditinjau`.

**REQ-PMT-05 — Konfirmasi per alat**
Given permintaan `ditinjau` oleh admin X
When X menilai tiap item (`diterima` / `di_luar_ruang_lingkup` / `ditolak` + catatan) lalu mengonfirmasi
Then minimal satu item harus `diterima`. Order internal dibuat berisi item yang diterima, status permintaan menjadi `dikonfirmasi`, dan pelanggan dikabari berikut hasil per item.
And kalau semua item ditolak, admin harus memakai aksi Tolak (REQ-PMT-06).

**REQ-PMT-06 — Tolak** Admin menolak dengan alasan wajib. Status `ditolak` (final), dan pelanggan dikabari.

**REQ-PMT-07 — Transisi status** mengikuti tabel di 03-SDD §5. Transisi di luar tabel ditolak 422 `transisi_tidak_valid`. Tiap transisi mencatat riwayat (dari, ke, oleh, catatan, waktu).

**REQ-PMT-08 — Pembatalan oleh pelanggan**
Given permintaan
When pelanggan membatalkan
Then hanya diizinkan pada `diajukan`, `ditinjau`, `dikonfirmasi`, dan `menunggu_alat`. Untuk onsite, `terjadwal` hanya bisa dibatalkan kalau jadwal masih ≥ 2 hari lagi. Alasan wajib. Admin yang menangani dikabari.
And di status lain, tombol berubah menjadi "Hubungi lab untuk membatalkan", yang membuka pesan.

**REQ-PMT-09 — Jadwal onsite** Given `dikonfirmasi` onsite · When admin menetapkan tanggal & jam mulai dan teknisi · Then status `terjadwal`, pelanggan dikabari, dan pengingat otomatis dikirim H-1 pukul 16.00 waktu lokasi (default WIB).

**REQ-PMT-10 — Alat diterima di lab** Given `menunggu_alat` · When admin menandai diterima, mengisi kondisi & kelengkapan per alat (ke `order_items.kondisi_terima`), dan opsional foto · Then status `alat_diterima`, dan pelanggan dikabari. Kondisi "rusak/tidak lengkap" wajib foto.

**REQ-PMT-11 — Progres otomatis dari data internal**
- Sesi kalibrasi pertama dibuat untuk salah satu item → `dikerjakan`.
- Semua item yang diterima punya sertifikat `terbit` atau hasil `tidak_dapat_dikalibrasi` → `menunggu_pengiriman_balik` (kirim ke lab) atau `selesai` (onsite).
- Admin mengisi kurir & resi balik → `dikirim_balik`. Pelanggan menekan "Alat sudah diterima", atau 14 hari lewat → `selesai`.

**REQ-PMT-12 — Progres per item** Pelanggan melihat status tiap item, karena item di satu permintaan bisa selesai di hari yang berbeda.

## 6. Pesan (PSN)

**REQ-PSN-01** Pesan hanya bisa dibuat di dalam permintaan yang bukan `ditolak`, `dibatalkan`, atau `selesai` lebih dari 30 hari.
**REQ-PSN-02** Pesan dari pelanggan memberi notifikasi ke admin yang menangani. Kalau belum ada yang menangani, notifikasi ke PIC admin, atau semua admin aktif kalau PIC admin tidak ada.
**REQ-PSN-03** Pesan dari lab memberi notifikasi ke semua anggota aktif perusahaan yang punya preferensi pesan menyala.
**REQ-PSN-04** Pesan tidak bisa diedit atau dihapus setelah terkirim (jejak komunikasi). Lampiran: gambar JPG/PNG atau PDF, ≤ 10 MB, maksimal 3 per pesan.
**REQ-PSN-05** Sisi pelanggan melihat nama pengirim dari lab sebagai "Tim PT Sidik · {nama depan admin}". Ini bisa dimatikan lewat setelan organisasi.

## 7. Notifikasi & pengingat (NTF)

**REQ-NTF-01 — Titik pengingat** Untuk tiap alat aktif dengan `tanggal_jatuh_tempo`, pengingat dikirim di H-30, H-7, H-1, H-0, H+7, dan H+30 (tanggal Asia/Jakarta). Setelah H+30 tidak ada pengingat lagi, tetapi status tetap `lewat_jadwal`.

**REQ-NTF-02 — Tidak dobel** Kombinasi (alat, nilai tanggal jatuh tempo, titik) hanya terkirim sekali. Kalau admin mengubah tanggal jatuh tempo, titik dihitung ulang dari tanggal baru.

**REQ-NTF-03 — Tidak meledak** Kalau tanggal jatuh tempo baru menyebabkan beberapa titik sudah terlewati, hanya titik terdekat yang belum lewat yang dikirim.

**REQ-NTF-04 — Digabung** Semua pengingat untuk satu anggota di hari yang sama digabung menjadi satu push, misal "3 alat mendekati jadwal kalibrasi ulang". Push membuka daftar alat terfilter.

**REQ-NTF-05 — Alat dalam proses** Alat berstatus `dalam_proses` tidak dikirimi pengingat.

**REQ-NTF-06 — Kotak notifikasi** Setiap push juga tersimpan di kotak notifikasi aplikasi (database). Kalau push gagal atau diblokir HP, informasinya tetap ada.

**REQ-NTF-07 — Email cadangan** H-30 dan H-0 juga dikirim lewat email ke PIC utama, begitu email produksi aktif.

**REQ-NTF-08 — Izin notifikasi** Aplikasi meminta izin notifikasi (Android 13+) setelah pengguna menambah alat pertama atau membuat permintaan pertama, tidak saat pertama kali dibuka. Kalau ditolak, beranda menampilkan banner yang menjelaskan akibatnya.

**REQ-NTF-09 — Daftar event**

| Event | Penerima | Kanal |
|---|---|---|
| Akun disetujui / ditolak | Pemohon | Push + email |
| Anggota baru bergabung | PIC utama | Push |
| Permintaan dikonfirmasi / ditolak | Semua anggota aktif | Push |
| Jadwal onsite ditetapkan / diubah | Semua anggota aktif | Push + email |
| Pengingat H-1 kedatangan teknisi | Pembuat permintaan + kontak onsite (email kalau ada) | Push |
| Alat diterima di lab | Semua anggota aktif | Push |
| Sertifikat terbit | Semua anggota aktif | Push + email PIC utama |
| Alat dikirim balik (resi) | Semua anggota aktif | Push |
| Pesan baru | REQ-PSN-03 | Push |
| Pengingat jadwal kalibrasi ulang | Semua anggota aktif | Push (+ email REQ-NTF-07) |

**REQ-NTF-10 — Isi di layar kunci** Isi push tidak memuat nomor seri, nomor sertifikat, atau nama perusahaan. Cukup nama alat dan jumlah.

## 8. Sisi lab / admin (ADM)

**REQ-ADM-01** Inbox punya filter: Belum diambil · Ditangani saya · Semua aktif · Selesai/ditolak. Urutan bawaan: belum diambil tertua di atas.
**REQ-ADM-02** Admin bisa mengalihkan permintaan ke admin lain dengan catatan. Admin tujuan dikabari.
**REQ-ADM-03** Permintaan `diajukan` yang belum diambil lebih dari 4 jam kerja memicu notifikasi ulang ke semua admin (sekali per permintaan).
**REQ-ADM-04** Saat approve sesi, sistem memperbarui `equipments.tanggal_kalibrasi_terakhir` dan `tanggal_jatuh_tempo` dari sertifikat aktif terakhir alat tersebut (03-SDD §6).
**REQ-ADM-05** Hanya pengguna dengan izin `otorisasi_sertifikat` yang bisa approve. Admin tanpa izin itu tetap bisa mengelola permintaan (K4).
**REQ-ADM-06** Semua aksi admin atas data pelanggan tercatat di `audit_logs` (trait `Diaudit` yang sudah ada).

## 9. Privasi (PRV)

**REQ-PRV-01** Tautan kebijakan privasi & syarat ketentuan ada di layar daftar, layar profil, dan listing Play Store. Persetujuan dicatat dengan versi dokumen dan waktunya.
**REQ-PRV-02** Log server tidak menyimpan sandi, OTP, token, isi pesan, atau nomor HP.
**REQ-PRV-03** Tautan hapus akun versi web tersedia (syarat Google Play), mengarah ke formulir yang diproses admin ≤ 7 hari.

---

## 10. Aturan bisnis

| ID | Aturan |
|---|---|
| BR-01 | Perusahaan aktif selalu diturunkan dari token + keanggotaan. Server tidak pernah memercayai `customer_id` dari request. |
| BR-02 | Akses ke data milik perusahaan lain selalu dijawab 404, supaya keberadaan data tidak bisa ditebak. |
| BR-03 | Jadwal kalibrasi ulang ditentukan lab, tercatat di sertifikat (`berlaku_sampai`). Tanggal di alat adalah turunan dari sertifikat aktif terakhir. |
| BR-04 | Pelanggan tidak pernah bisa mengubah data yang sudah tercetak di sertifikat terbit. |
| BR-05 | Satu alat hanya boleh berada di satu permintaan aktif. |
| BR-06 | Permintaan hanya menjadi Order internal lewat konfirmasi admin. |
| BR-07 | Rekaman lab (alat, permintaan, sertifikat, riwayat, pesan) tidak ikut terhapus saat akun dihapus. Data pribadi dianonimkan. |
| BR-08 | Seluruh perhitungan "hari ini" untuk jadwal memakai tanggal Asia/Jakarta. |
| BR-09 | Istilah di aplikasi pelanggan: "Jadwal kalibrasi ulang", bukan "kadaluarsa" (menunggu K3). |
| BR-10 | Aplikasi di bawah versi minimum wajib update sebelum bisa dipakai. |

## 11. Validasi field utama

| Field | Aturan |
|---|---|
| Email | Format valid, maks 254, disimpan huruf kecil, unik per akun |
| Sandi | Minimal 10 karakter, ditolak kalau ada di daftar sandi umum / bocor (`Password::uncompromised()`) |
| Nomor HP | Format Indonesia, dinormalisasi ke `+62…` |
| Nama perusahaan | 3–150 karakter |
| Nama alat | 2–120 karakter |
| Nomor seri | Maks 80 karakter, boleh kosong (dengan peringatan) |
| Range | `range_min` < `range_max` kalau dua-duanya diisi, satuan wajib kalau range diisi |
| Tanggal onsite | Mulai ≥ besok (Asia/Jakarta), rentang ≤ 60 hari |
| Resi | 5–40 karakter alfanumerik, kurir dari daftar + "Lainnya" |
| Isi pesan | 1–2.000 karakter |

## 12. Requirement non-fungsional (NFR)

| ID | Kategori | Requirement |
|---|---|---|
| NFR-01 | Keamanan | Semua trafik HTTPS. Token disimpan di `flutter_secure_storage`. Tidak ada secret di dalam APK. |
| NFR-02 | Keamanan | Rate limit: daftar 5/jam per IP, masuk 10/menit per IP, OTP 5/15 menit per akun, pesan 30/menit per anggota, upload 20/jam per anggota. |
| NFR-03 | Keamanan | Test isolasi (IDOR) mencakup **setiap** endpoint `/pelanggan/v1` yang menerima ID, di suite SQLite dan MySQL. |
| NFR-04 | Performa (produksi) | p95 respons API list ≤ 800 ms dan detail ≤ 500 ms di VPS produksi. Tidak berlaku untuk staging gratis. |
| NFR-05 | Performa (app) | Cold start ke beranda ≤ 3 detik di HP kelas menengah dengan cache. Ukuran unduh AAB ≤ 30 MB. |
| NFR-06 | Jaringan | Timeout 20 detik, retry otomatis 2× untuk GET saja. Daftar alat & sertifikat bisa dibaca offline dari cache terakhir, dengan penanda waktu sinkron. Draf permintaan tersimpan lokal. |
| NFR-07 | Ketersediaan | Target produksi 99,5% per bulan. Maintenance terjadwal di luar jam kerja (≥ 20.00 WIB) dengan pemberitahuan di aplikasi. |
| NFR-08 | Data | Backup database harian di lokasi terpisah, retensi ≥ 30 hari, uji restore sebulan sekali. |
| NFR-09 | Kompatibilitas | Target API sesuai syarat Google Play yang berlaku (API 36 per 31 Agu 2026). Min SDK tidak diturunkan dari default Flutter yang dipakai. Diuji di minimal 1 HP Xiaomi/Redmi, 1 Samsung, 1 Oppo/Vivo. |
| NFR-10 | Aksesibilitas | Mendukung skala huruf sistem sampai 130% tanpa teks terpotong di layar utama. Kontras warna WCAG AA. |
| NFR-11 | Observabilitas | Crash dan error non-fatal terlapor (Firebase Crashlytics). Setiap request API membawa `X-Request-Id` yang tercatat di log server. |
| NFR-12 | Kompatibilitas mundur | Endpoint `/pelanggan/v1` tidak boleh berubah secara merusak. Perubahan merusak = `/v2` + versi minimum dinaikkan. |
| NFR-13 | Lokalisasi | Bahasa Indonesia. Tanggal ditampilkan `12 Apr 2026`, dikirim ISO 8601. |
| NFR-14 | Isolasi operasional | Deploy fitur pelanggan tidak boleh mengganggu alur teknisi. Fitur pelanggan di produksi dibungkus feature flag `FITUR_PELANGGAN`. |

## 13. Kriteria penerimaan rilis (global)

Rilis ke produksi hanya jika semua berikut terpenuhi:

1. Semua REQ bertanda W di PRD punya test otomatis yang hijau di dua suite backend.
2. Test IDOR dan test "rute internal menolak pelanggan" hijau.
3. UAT dengan minimal 3 perusahaan pilot selesai tanpa bug kritis/tinggi terbuka.
4. Checklist di 07-Runbook §6 dan §7 tercentang dan ditandatangani pemegang akun PT Sidik.
