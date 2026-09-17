# 05 — Task Breakdown: SIDIK Pelanggan

> Versi 0.1 · 16 Sep 2026 · Turunan dari 02/03/04
> **Owner di bawah adalah usulan** berdasarkan pembagian yang terlihat di repo (Raihan = backend, Arkaan = mobile). Sesuaikan.
> Estimasi = hari kerja efektif satu orang, termasuk menulis test. Belum termasuk menunggu keputusan PT Sidik atau review Google.

## Ringkasan milestone

| Milestone | Isi | Estimasi | Syarat mulai | Gerbang keluar |
|---|---|---|---|---|
| **M0** | Darurat & pondasi (sebagian menyentuh produksi internal) | 6 hari | Sekarang | U1–U4 selesai, K1/K2 diputuskan |
| **M1** | Backend: identitas, keanggotaan, isolasi | 9 hari | M0-06 | Test IDOR & rute-internal hijau di 2 suite |
| **M2** | Repo & kerangka aplikasi pelanggan | 6 hari | M0-03, M1-06 kontrak auth | Build staging terpasang via Play internal testing |
| **M3** | Alat & sertifikat | 9 hari | M1, M2 | Pelanggan staging bisa lihat alat & unduh PDF |
| **M4** | Permintaan, inbox admin, pesan | 14 hari | M3 | Alur onsite & kirim-lab jalan ujung ke ujung di staging |
| **M5** | Notifikasi & pengingat | 7 hari | M4 | Simulasi 60 hari lolos |
| **M6** | Hardening, legal, UAT pilot | 10 hari | M5, K6, K7 | Tidak ada bug kritis/tinggi terbuka |
| **M7** | Rilis Play Store bertahap | ≥ 14 hari kalender | M6, K1 | Rollout 100% stabil 7 hari |
| **M8** | Operasional & serah terima | 4 hari + rutin | M7 | Dokumen serah terima ditandatangani |

Total kerja ±65 hari kerja orang. Dengan dua orang yang juga menyelesaikan MVP internal, perkiraan realistis: M0 selesai 30 Sep, M1–M5 Oktober–November, M6 awal Desember, produksi paling cepat pertengahan Desember 2026 (lebih lambat kalau D-U-N-S/verifikasi akun makan waktu).

---

## M0 — Darurat & pondasi

| ID | Task | DoD | Estimasi | Dependency | Owner | Link |
|---|---|---|---|---|---|---|
| M0-01 | Kunci repo & audit secret | Kedua repo privat (dicek dari browser tanpa login). `gitleaks` dijalankan di **seluruh histori** kedua repo, hasil dicatat. Semua key yang pernah muncul di histori dirotasi (Anthropic, Google, R2, FCM service account, DB). `APP_KEY` **tidak** dirotasi tanpa rencana, karena QR terenkripsi bergantung padanya. Temuan dicatat di `BACA-DULU-BACKEND.md`. | 0,5 | — | Raihan | 00-BACA-DULU U2 |
| M0-02 | Verifikasi developer Android untuk app internal | Identitas developer terverifikasi (atas nama PT Sidik kalau K1 sudah jalan; kalau belum, catat sebagai risiko terbuka). Package `com.ptsidik.kalibrasi` terdaftar dengan sertifikat signing yang dipakai build rilis. Satu HP uji di Indonesia berhasil memasang dan update APK via jalur normal setelah 30 Sep. | 1 + waktu tunggu | K1 (atau keputusan darurat) | Arkaan + pemegang akun PT Sidik | 00 U1; support.google.com/android-developer-console/answer/16561738 |
| M0-03 | GitHub Organization milik PT Sidik | Org dibuat dengan owner dari PT Sidik + dua developer sebagai member. `sidik-calibration-api` dan `sidik-calibration-mobile` ditransfer (redirect otomatis diverifikasi, remote lokal diperbarui, Render/Firebase deploy hook dicek masih jalan). Branch protection `main`: wajib PR, wajib CI hijau, tanpa force push. | 0,5 | K2 | Arkaan | ADR-001 |
| M0-04 | Pisahkan staging dari produksi internal | Service staging baru (Render free) + DB staging baru + bucket R2 staging + Firebase project `sidik-staging`. Seeder data dummy (3 perusahaan fiktif, 40 alat, sertifikat contoh). **Tidak ada** dump data produksi. URL staging dicatat. | 1,5 | M0-01 | Raihan | 07-Runbook §1–2 |
| M0-05 | Perbaiki sinkron jadwal alat (U3) | `SinkronJadwalAlat` + dipanggil di `GenerateCertificate` dan alur revisi. Command `alat:sinkron-jadwal --dry-run`. Lima test di SDD §6 hijau di 2 suite. Dry-run di salinan data produksi (lokal, bukan staging) ditinjau Pak Rohman/admin sebelum dijalankan di produksi. | 1,5 | — | Raihan | 03-SDD §6 |
| M0-06 | Gerbang rute deny-by-default | Grup rute internal diberi `role:admin,teknisi,viewer`. `RuteInternalMenolakPelangganTest` (loop semua rute, memakai user fiktif ber-role tak dikenal) hijau. Channel `organisasi.{id}` menolak role selain tiga itu. Smoke test app internal (login teknisi, simpan sesi, approve) lulus di staging. | 1 | — | Raihan | 03-SDD §3.2 |

## M1 — Backend: identitas, keanggotaan, isolasi

> **M1 SELESAI per 16 Sep 2026** — M1-01 s/d M1-07 semuanya sudah mendarat
> (branch `pelanggan/fase-4-auth-pelanggan` lalu `pelanggan/fase-5-persetujuan-dan-anggota`).
> Berikutnya M2 (mobile) dan M3 (alat).
>
> Kolom Status sengaja TIDAK ditambahkan ke tabel di bawah: `CLAUDE.md` menunjuk
> `docs/BACA-DULU-BACKEND.md` sebagai satu-satunya dokumen status yang boleh
> dipercaya, dan dua tempat yang mengaku tahu status itu persis cara `docs/permintaan-*.md`
> jadi punya tanda ✅ yang salah. Rincian tiap task yang mendarat — termasuk yang
> menyimpang dari DoD di bawah dan alasannya — ada di sana.

| ID | Task | DoD | Estimasi | Dependency | Owner | Link |
|---|---|---|---|---|---|---|
| M1-01 | Migrasi identitas | Migrasi §4.1 (users, customers, device_tokens) + tabel `customer_members`, `undangan_pelanggan`, `pengajuan_akun_pelanggan`, `persetujuan_dokumen`. Semua bisa rollback. Diuji di suite MySQL (enum). | 1 | M0-06 | Raihan | 03-SDD §4 |
| M1-02 | Feature flag & registrasi rute pelanggan | `routes/api_pelanggan.php` terdaftar dengan prefix `api/pelanggan/v1`. `FITUR_PELANGGAN=false` → 503 `belum_tersedia`. `/app/status` jalan. Test untuk kedua kondisi flag. | 0,5 | M1-01 | Raihan | 03-SDD §10 |
| M1-03 | Middleware `aplikasi:` & ability token | Login internal membuat token ability `internal`. Masa transisi token lama terdokumentasi. Login pelanggan membuat ability `pelanggan` + `expires_at` 90 hari + cek idle 30 hari. REQ-AUTH-07/08 punya test. | 1 | M1-02 | Raihan | REQ-AUTH-07/08 |
| M1-04 | Daftar, OTP, menunggu verifikasi | REQ-AUTH-01/02/03/10 + throttle NFR-02. OTP disimpan hash. Email lewat mailable (terkirim di staging). | 1,5 | M1-03, M0-04 (email staging) | Raihan | REQ-AUTH-01..03 |
| M1-05 | Undangan & persetujuan akun (sisi admin) | REQ-AUTH-04/05/06 + endpoint §7.3 pengajuan-akun & undangan. Saran pelanggan mirip memakai `nama_normal`. Notifikasi ke admin/PIC admin. | 1,5 | M1-04 | Raihan | REQ-AUTH-04..06 |
| M1-06 | Masuk, keluar, profil, ganti sandi, lupa sandi | Endpoint §7.1–7.2 terkait akun. REQ-AUTH-09. Fixture JSON respons disimpan di `tests/Fixtures/pelanggan/`. `docs/kontrak-api-pelanggan.md` versi pertama. | 1 | M1-03 | Raihan | 03-SDD §7 |
| M1-07 | `KonteksPerusahaan` + anggota | Middleware + REQ-ANG-01..04 + tabel otorisasi §3.4. | 1 | M1-05 | Raihan | REQ-ANG |
| M1-08 | Test isolasi otomatis | `IsolasiPerusahaanTest` membaca daftar rute pelanggan ber-parameter dan gagal kalau ada rute tanpa kasus uji. Dua perusahaan fiktif, silang semua ID → 404. | 1 | M1-07 | Raihan | NFR-03 |
| M1-09 | Hapus akun | REQ-AUTH-11 + `PenganonimAkun` + halaman web hapus akun sederhana (REQ-PRV-03). | 0,5 | M1-06 | Raihan | REQ-AUTH-11 |

## M2 — Repo & kerangka aplikasi pelanggan

| ID | Task | DoD | Estimasi | Dependency | Owner | Link |
|---|---|---|---|---|---|---|
| M2-01 | Buat repo `sidik-pelanggan-mobile` | Repo di org PT Sidik. Flutter versi sama dengan app internal (dipatok). `applicationId` produksi `com.ptsidik.pelanggan` (**permanen**, dicek ulang dengan PT Sidik sebelum build pertama diunggah). Flavor lingkungan: `staging` (`.staging`) & `produksi`. `README`, `CONTRIBUTING`, `docs/` berisi paket ini. | 1 | M0-03 | Arkaan | ADR-001 |
| M2-02 | CI | PR: `flutter analyze`, `flutter test`, build AAB staging. Tag `v*`: build AAB produksi bertanda tangan dengan upload key dari GitHub Secrets. Tidak ada keystore atau `google-services.json` produksi di repo. | 1 | M2-01 | Arkaan | 07-Runbook §4 |
| M2-03 | Fondasi aplikasi | Salin **sekali** dari app internal: token desain Precision Clean, pola `api_client`, `token_storage`. Riverpod 3. Router + deep link `sidikpelanggan://`. Interceptor: header wajib §7, 401 → layar masuk, `kode` error → pesan. | 1,5 | M2-01 | Arkaan | 03-SDD §7 |
| M2-04 | Gerbang versi & maintenance | Cek `/app/status` saat start & resume. S21/S22. Menggunakan Play In-App Updates untuk update fleksibel. | 0,5 | M1-02 | Arkaan | 04 S21–S22 |
| M2-05 | Layar auth | S01–S06 + S23 terhubung ke staging. Golden test S02, S05, S06. | 2 | M1-04..06 | Arkaan | 04 Flow A/B |
| M2-06 | Crashlytics & FCM dasar | Crashlytics aktif di kedua flavor (user ID hash). Token FCM didaftarkan setelah login, dihapus saat keluar. | 0,5 | M2-05 | Arkaan | NFR-11 |
| M2-07 | Akun Play Console + internal testing | App dibuat di Play Console (akun K1), Play App Signing aktif, build staging diunggah ke track **internal testing**, 2 developer + 2 admin lab bisa memasang. | 0,5 + tunggu | K1 | Arkaan + pemegang akun | 07-Runbook §3 |

## M3 — Alat & sertifikat

| ID | Task | DoD | Estimasi | Dependency | Owner | Link |
|---|---|---|---|---|---|---|
| M3-01 | Migrasi alat | Kolom §4.1 `equipments`, `certificates.digantikan_oleh` (atau turunan dari `revision_of`). Default aman untuk data lama. | 0,5 | M1-01 | Raihan | 03-SDD §4.1 |
| M3-02 | `StatusAlatPelanggan` | REQ-ALT-05 dengan tanggal Asia/Jakarta. Unit test tiap baris tabel + batas H-30 persis. | 1 | M3-01, M0-05 | Raihan | REQ-ALT-05 |
| M3-03 | Endpoint alat | GET list/detail, POST, PATCH + REQ-ALT-03/04. `Resources\Pelanggan\AlatResource` sesuai contoh §7.4. Fixture JSON. | 1,5 | M3-02, M1-07 | Raihan | REQ-ALT-01..04 |
| M3-04 | Foto alat | `PembersihFoto` (EXIF dibuang, diuji dengan foto ber-GPS), upload/hapus, URL bertanda tangan. | 1 | M3-03 | Raihan | 03-SDD §9 |
| M3-05 | Endpoint sertifikat + revisi di halaman verifikasi | REQ-SRT-01..05. Halaman `/verify/{qr_token}` menampilkan "digantikan oleh". Resource pelanggan tidak memuat data internal (ada test yang memeriksa daftar key respons). | 1 | M3-01 | Raihan | REQ-SRT |
| M3-06 | Verifikasi alat oleh lab | Endpoint §7.3 + layar internal "Verifikasi alat pelanggan". | 1 | M3-03 | Raihan (API) + Arkaan (app internal) | REQ-ALT-06 |
| M3-07 | Layar alat | S07 (versi awal), S08, S09, S10 dengan 4 state + cache offline. | 2,5 | M3-03, M2-05 | Arkaan | 04 Flow C |
| M3-08 | Layar sertifikat | S11 + unduh & buka PDF + label revisi & FAIL. | 1 | M3-05 | Arkaan | REQ-SRT |

## M4 — Permintaan, inbox admin, pesan

| ID | Task | DoD | Estimasi | Dependency | Owner | Link |
|---|---|---|---|---|---|---|
| M4-01 | Migrasi permintaan | Tabel `permintaan_kalibrasi`, `_items`, `_riwayat`, `pesan_permintaan`, `pesan_permintaan_dibaca`. Penomoran `PMT/{Y}/{m}/{4-digit}` aman dari balapan (diuji paralel di MySQL). | 1 | M3 | Raihan | 03-SDD §4.2 |
| M4-02 | `AlurPermintaan` | Tabel transisi §5 penuh. Transaksi + `lockForUpdate`. Riwayat. Event. Unit test semua transisi valid & tidak valid. | 2 | M4-01 | Raihan | 03-SDD §5 |
| M4-03 | Endpoint pelanggan permintaan | Buat (idempoten), list, detail, batal, pengiriman, alat-sudah-kembali. REQ-PMT-01..03, 08, 12. | 1,5 | M4-02 | Raihan | REQ-PMT |
| M4-04 | Endpoint admin permintaan | Ambil (atomik + test paralel), alihkan, konfirmasi → Order/OrderItem, tolak, jadwal, alat-diterima, kirim-balik, batal. REQ-PMT-04..06, 09, 10 + REQ-ADM-01..03. | 2,5 | M4-02 | Raihan | 03-SDD §7.3 |
| M4-05 | Progres otomatis dari sesi & sertifikat | Listener `afterCommit` untuk `SesiKalibrasiDibuat` & `SertifikatTerbit`. REQ-PMT-11. Test: sesi yang rollback tidak memajukan permintaan. | 1 | M4-04, M0-05 | Raihan | REQ-PMT-11 |
| M4-06 | Pesan | Endpoint kedua sisi, REQ-PSN-01..05, lampiran lewat `PembersihFoto`/validasi PDF. | 1 | M4-02 | Raihan | REQ-PSN |
| M4-07 | Izin otorisasi sertifikat (K4) | Kolom + middleware + `MatriksIzin` + migrasi yang mempertahankan perilaku sekarang. App internal menyembunyikan tombol approve. | 0,5 | K4 | Raihan + Arkaan | REQ-ADM-05 |
| M4-08 | Layar pelanggan permintaan | S12 (dengan draf lokal & kirim ulang idempoten), S13, S14, S15. | 3 | M4-03, M4-06 | Arkaan | 04 Flow D/E |
| M4-09 | Layar admin di app internal | Inbox, detail permintaan admin, pengajuan akun, detail pelanggan (undangan, PIC admin). Disembunyikan kalau flag mati. | 3 | M4-04, M1-05 | Arkaan | 04 §7 |
| M4-10 | Uji ujung ke ujung staging | Skenario onsite & kirim-lab dijalankan dua developer + 1 admin lab di HP asli, dicatat di `docs/uji/M4.md` dengan tangkapan layar. | 1 | M4-08, M4-09 | Arkaan + Raihan | 07-Runbook §5 |

## M5 — Notifikasi & pengingat

| ID | Task | DoD | Estimasi | Dependency | Owner | Link |
|---|---|---|---|---|---|---|
| M5-01 | Penerima & kanal | `PenerimaNotifikasi::anggotaAktifPerusahaan`, filter `device_tokens.aplikasi`, preferensi anggota (tabel + endpoint). | 1 | M1-07 | Raihan | 03-SDD §8.1 |
| M5-02 | Notifikasi event | Semua baris REQ-NTF-09 terkirim (database + push), isi sesuai REQ-NTF-10, deep link valid. | 1,5 | M5-01, M4 | Raihan | REQ-NTF-09 |
| M5-03 | `PengingatPelanggan` | Algoritma §8.2, tabel dedup, scheduler dengan timezone, penggabungan per anggota. Test: server "tidur" 3 hari, tanggal diubah maju & mundur, alat dalam proses, perusahaan tanpa anggota. | 2 | M5-01, M3-02 | Raihan | REQ-NTF-01..05 |
| M5-04 | Simulasi 60 hari | Command uji yang memajukan waktu (`Carbon::setTestNow`) atas data dummy 40 alat dan menghasilkan laporan: jumlah push per hari per anggota, tidak ada duplikat, tidak ada titik terlewat. Laporan dilampirkan di PR. | 0,5 | M5-03 | Raihan | REQ-NTF-02/03 |
| M5-05 | Pemicu scheduler di staging | Endpoint `POST /api/internal/cron` dilindungi `CRON_PEMICU_TOKEN`, dipanggil pinger eksternal gratis. Health menampilkan waktu run terakhir. | 0,5 | M5-03 | Raihan | 07-Runbook §2 |
| M5-06 | Sisi aplikasi | Channel Android, izin notifikasi kontekstual (REQ-NTF-08), S16, S19, banner izin, penanganan tap push → deep link (app mati/latar belakang/depan). Diuji di Xiaomi dengan hemat baterai aktif. | 2 | M5-02, M2-06 | Arkaan | REQ-NTF-06..08 |

## M6 — Hardening, legal, UAT

| ID | Task | DoD | Estimasi | Dependency | Owner | Link |
|---|---|---|---|---|---|---|
| M6-01 | Tinjauan keamanan internal | Sesi review adversarial (skill audit kode di project ini) atas modul pelanggan: IDOR, mass assignment, upload, throttle, log PII. Semua temuan tinggi diperbaiki. | 2 | M5 | Raihan + Arkaan | 06-Risk bagian D |
| M6-02 | Uji beban ringan | 200 anggota fiktif × skenario baca beranda/alat di VPS produksi (sebelum flag nyala). p95 sesuai NFR-04. | 1 | Produksi VPS siap | Raihan | NFR-04 |
| M6-03 | Dokumen legal | Kebijakan privasi & syarat ketentuan disetujui PT Sidik, di-host di domain PT Sidik, versinya tercatat di `persetujuan_dokumen`. Pendaftaran PSE Lingkup Privat dimulai/selesai. | 1 (dev) + legal | K6 | PT Sidik + Arkaan | 07-Runbook §3 |
| M6-04 | Data safety & listing Play | Formulir Data safety sesuai data yang benar-benar dikumpulkan (termasuk Crashlytics). Screenshot, ikon, deskripsi. Akun demo untuk reviewer Google berisi perusahaan fiktif di produksi yang terisolasi. | 1 | M6-03 | Arkaan | 07-Runbook §3 |
| M6-05 | Aksesibilitas & perangkat | NFR-09/10 dicek dan dicatat. | 1 | M5-06 | Arkaan | NFR-09/10 |
| M6-06 | UAT pilot | 3–5 perusahaan pilot memakai build closed testing melawan **produksi dengan `FITUR_PELANGGAN` menyala**. Pembatasan ke perusahaan pilot MANUAL lewat persetujuan admin: cuma `PengajuanAkunPelanggan` milik perusahaan pilot yang disetujui. Endpoint pendaftaran tetap terbuka, jadi rilis ini tidak boleh mendahului tinjauan keamanan M6-01. Minimal 2 minggu. Bug dicatat & ditriase harian. | 3 (dev) + 10 kalender | K7, M6-01 | Semua | 07-Runbook §5 |
| M6-07 | Pelatihan admin lab | Sesi 1 jam + panduan 2 halaman untuk inbox & verifikasi. Admin pilot menjalankan skenario tanpa bantuan dev. | 1 | M4-09 | Arkaan | 04 §7 |

## M7 — Rilis Play Store bertahap

| ID | Task | DoD | Estimasi | Dependency | Owner | Link |
|---|---|---|---|---|---|---|
| M7-01 | Closed testing | Kalau akun pribadi (tidak disarankan): 12 penguji × 14 hari berturut-turut. Kalau akun organisasi: closed testing dengan pilot tetap dijalankan sebagai UAT. | 14 hari kalender | M6-06 | Arkaan | 07-Runbook §3 |
| M7-02 | Rilis produksi bertahap | Checklist 07 §6–7 tercentang. Rollout 10% → 50% → 100% dengan jeda ≥ 2 hari. Kriteria henti di 07 §8 dipantau. | 7 hari kalender | M7-01 | Arkaan + Raihan | 07-Runbook §7–8 |
| M7-03 | Undangan massal pelanggan lama | Admin mengirim undangan bertahap (misal 10 perusahaan/hari) supaya beban inbox dan pertanyaan terkendali. | Rutin | M7-02 | Admin lab | Flow A |

## M8 — Operasional & serah terima

| ID | Task | DoD | Estimasi | Dependency | Owner | Link |
|---|---|---|---|---|---|---|
| M8-01 | Backup & uji restore | Backup harian di lokasi terpisah berjalan, satu kali restore penuh ke server uji berhasil dan dicatat. | 1 | VPS | Raihan | NFR-08 |
| M8-02 | Monitoring & alarm | Alarm §11 SDD aktif, dikirim ke email/WA grup PT Sidik (bukan hanya HP developer). | 1 | M7 | Raihan | 03-SDD §11 |
| M8-03 | Dokumen serah terima | Checklist 07 §10 lengkap, akses semua akun dipindah/diverifikasi, satu orang PT Sidik berhasil menjalankan deploy backend dan rilis app mengikuti runbook tanpa bantuan. | 2 | M7 | Arkaan + Raihan + PT Sidik | 07-Runbook §10 |
