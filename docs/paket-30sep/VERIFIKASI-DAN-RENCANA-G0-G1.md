# Verifikasi Temuan 30 Sep & Rencana Gelombang 0–1

> Disusun 1 Okt 2026. Tahap ini hanya pemeriksaan, uji aman, dan perencanaan.
> Tidak ada kode aplikasi, instruksi aktif, konfigurasi, atau database yang diubah.
> Sumber klaim: `docs/paket-30sep/PERINTAH-CLAUDE-CODE-PERBAIKAN-30SEP.md` (dibaca utuh,
> 1757 baris). Berkas itu disusun dari API `078314896b0d`. Sejak itu sudah masuk
> PR #204–#207, jadi semua klaim dicek ulang ke kode sekarang.

## 1. Status lingkungan

### 1.1 Repo (sesudah `git pull --ff-only origin main`)

| Repo | Branch | Commit | Visibilitas | Perubahan lokal |
|---|---|---|---|---|
| `sidik-calibration-api` | `main` | `0beb5bb` (merge PR #207) | **PUBLIC** | `.claude/settings.json`, `.gitattributes` (graphify). Empat profil (`DialIndicator`, `HeightGauge`, `JangkaSorong`, `Sieve`) bertanda `M`, tapi diff-nya kosong saat spasi diabaikan, jadi isinya cuma beda akhir baris. Untracked: `docs/paket-30sep/`, `PROMTYAAA/`, `SIDIK-Paket-Lengkap-29Sep2026/`, `draf-geometri-41-formulir/`, `lanjutan-codex.txt`, `.claude/usulansettingskeystore.json` |
| `sidik-calibration-mobile` | `main` | `16e13d1` (merge PR #189) | **PUBLIC** | `.gitattributes`, `pubspec.lock`; untracked `CLAUDE.md`, `.claude/`, `flutter_01.png`, `flutter_02.png` |
| `sidik-pelanggan-mobile` | `main` | `99c3aef` (merge PR #3) | **PUBLIC** | bersih |

CI API: run `Tes` untuk `0beb5bb` dan `090c467` = `completed success`. Endpoint
`/api/health` produksi tidak diperiksa di sesi ini karena URL di `tes.yml` hanya contoh
(`namamu.onrender.com`).

Versi: PHP 8.4.25 (lokal), Laravel `v13.19.0`, Filament `v5.6.8`, Sanctum `^4.0`,
Flutter 3.44.8 (lokal; golden resmi dibuat di macOS dengan 3.44.6), Dart SDK `^3.11.5`.

### 1.2 Instruksi yang berlaku (dibaca utuh)

`AGENTS.md` (43.522 byte, 744 baris), `CLAUDE.md` (penunjuk `@AGENTS.md`),
`CLAUDE.local.md`, `docs/aturan-akses-database.md`, `~/.claude/CLAUDE.md`, tujuh berkas
`~/.claude/rules/*.md`, dan `MEMORY.md`. Total sekitar 78,8 KB.

`verified-coding.md` **tidak ada** di `~/.claude`, di repo, maupun di `C:\Users\USER`
(cari sampai kedalaman 5). Tidak ada yang perlu digabung.

### 1.3 Tools

| Tool | Terdaftar | Terhubung | Dipakai & berhasil di sesi ini |
|---|---|---|---|
| Bash/Read/Grep/Write | ya | ya | ya |
| `git`, `gh` (login `repo`,`workflow`) | ya | ya | ya (`fetch`, `pull --ff-only`, `run list`, `repo view`) |
| `php artisan test` (SQLite `:memory:`) | ya | ya | ya, 5 test lulus |
| Flutter CLI | ya | ya | hanya `--version`; `analyze`/`test` belum dijalankan |
| graphify (hook) | ya | ya | tidak dipakai; graph sedang di-rebuild sesudah ganti branch. Pencarian memakai Grep terarah |
| MCP GitHub | ya | **gagal** (400 header) | tidak |
| MCP agent-memory, aws-amplify, data:definite | ya | **gagal** | tidak |
| Context7, Chrome, Render, ECC, UI UX Pro Max | ya | tidak diuji | tidak dibutuhkan untuk tahap ini |
| Skill Bug Hunter (`protokol-audit-…`) | ya | — | tidak dimuat; lihat konflik K-I3 di `docs/instruction-cleanup/05` |

## 2. Tabel verifikasi B01–B18

Kategori status: **R** = terkonfirmasi lewat reproduksi test · **S** = terkonfirmasi lewat
penelusuran statis · **F** = sudah diperbaiki · **X** = tidak terbukti/tidak berlaku ·
**T** = terblokir konteks.

Reproduksi memakai satu berkas test sementara di scratchpad (bukan di repo), dijalankan
dengan `phpunit.xml` (SQLite `:memory:`, `QUEUE_CONNECTION=sync`). Test yang **lulus**
berarti perilaku bug masih terjadi. Belum dijalankan di suite MySQL.

| ID | Status | File/fungsi | Pemicu | Dampak | Bukti | Pemeriksaan lanjutan |
|---|---|---|---|---|---|---|
| B01 | **R** | `CalibrationController::approve()` (`:846`–`:1004`) | Admin membuat sesi (`POST /api/calibrations/autoclave`), lalu `POST /calibrations/{id}/approve` dengan gerbang mati | Sesi `disetujui` + job sertifikat; `reviewed_by == teknisi_id` | `test_b01` lulus. `approve()` tidak memanggil `PemisahanWewenang`; kelas itu hanya dipakai `PengesahanController::sahkan()` | Ulang di MySQL; pertimbangkan penyunting (koreksi admin) sebagai pihak yang juga dibandingkan |
| B02 | **S** | `CalibrationSessionsTable.php:198`–`:228` (aksi Setujui panel) | Admin menekan Setujui di `/admin` saat `GERBANG_PENGESAHAN=true` | Status langsung `disetujui` + `GenerateCertificate`; gerbang & pemisahan wewenang dilewati | Panel menulis `STATUS_DISETUJUI` tanpa membaca `kalibrasi.gerbang_pengesahan`; pembaca config itu hanya `CalibrationController.php:949` | Test Livewire panel dengan gerbang nyala |
| B03 | **S** | `CalibrationController::verifyMeasurements()` (`:1296`–`:1323`), rute `role:admin,teknisi` | Admin (bukan teknisi pemilik) memanggil `…/measurements/verify` tanpa `measurement_ids`, di status apa pun | Semua pembacaan OCR jadi `is_verified=true` tanpa pelaku & waktu | Tidak ada cek status; kolom hanya boolean; `update()` lewat query builder → tidak ada event; `RawMeasurement` tanpa `Diaudit` | Test: verifikasi pada sesi `disetujui` |
| B04 | **R** (bagian 1) | `PengesahanController::tarikPengajuan()` (`:305`–`:326`); `TarikPengajuanRequest::authorize()` = `true` | Admin B menarik pengajuan milik admin A | Pengajuan orang lain kembali ke `menunggu_approval` | `test_b04` lulus | Bagian 2 klaim (`abaikan_peringatan` oleh pelaku sama di `sahkan`) = perilaku **disengaja** selama `PEMISAHAN_WEWENANG_MEMBLOKIR=false` (docblock `PemisahanWewenang`, keputusan 26 Sep). Bukan bug sampai K-30-03 dijawab |
| B05 | **R** | `UserController::update()` (`:86`–`:118`), `resetPassword()` (`:125`–`:143`); panel `UsersTable.php:99`–`:120` | Admin A me-reset sandi admin B, lalu menurunkan peran B jadi viewer | Admin A bisa login sebagai B (aksi tercatat atas nama B); tanpa penjaga admin terakhir | `test_b05` lulus | Ubah peran/status diri sendiri belum diuji |
| B06 | **R** | `EnsureUserHasRole::handle()` (tanpa cek `status`); `config/sanctum.php:53` `expiration => null`; panel `EditUser` tidak mencabut token | Status akun diubah ke `nonaktif` lewat jalur model (seperti form panel) | Token lama tetap diterima | `test_b06` lulus (`/api/me` & `/api/calibrations` = 200) | Jalur API `PUT /users/{id}` **sudah** mencabut token (`UserController:110`–`:112`). Celahnya di panel & tidak adanya cek di middleware |
| B07 | **S**, jangkauan terbatas | `User` tanpa `SoftDeletes`; `audit_logs.changed_by` `nullOnDelete` (migrasi `2026_07_27_100000:48`); panel `DeleteAction`, `DeleteBulkAction` | Hapus akun yang punya baris audit, tapi bukan teknisi/reviewer/penerbit | `changed_by` jadi NULL; pelaku hilang dari jejak audit | FK `teknisi_id`, `reviewed_by`, `issued_by` default RESTRICT, jadi akun yang pernah mengerjakan sesi tidak bisa dihapus. Yang rawan: admin/viewer yang hanya mengubah data master | Test hapus viewer yang punya baris audit |
| B08 | **S** (belum direproduksi) | `CalibrationController::reject()` (`:1230`–`:1244`) | Admin B (layar basi) menolak tepat saat admin A menyetujui | Sesi bisa turun ke `perlu_revisi` setelah disetujui | Cek status di `:1230`, lalu `update()` tanpa syarat status di `:1238`. Penjaga `CalibrationSession::booted()` membaca status asli **di memori**, jadi model basi lolos | Test dengan lock/urutan paksa |
| B09 | **S**, sebagian | `PengesahanController::sahkan()` tidak memanggil `CalibrationValidator`; `updateAdminFields()` (`:1145`) hanya menolak `disetujui`; `DataTampilanSertifikat::tandaTanganIsi()` (`:304`) membaca berkas organisasi saat render | Data admin diubah saat `menunggu_pengesahan`; tanda tangan organisasi diganti sebelum job/render ulang | Data yang disahkan bisa beda dari yang diajukan; PDF render ulang memakai tanda tangan terbaru | Pembacaan kode | PDF yang sudah tersimpan tidak berubah sendiri. Dampaknya ada pada render pertama yang tertunda, "Terbitkan ulang", dan `BangunUlangSnapshotSertifikat` |
| B10 | **S** | `RawMeasurement`, `UncertaintyCalculation`, `Order`, `OrderItem` | Tulis ke model tersebut | Perubahan tidak masuk `audit_logs` | `grep Diaudit` = 0 di keempat model | `OrderController` (~`:184`) belum dicek |
| B11 | **S** | `CalibrationController::index()` (`:142`–`:165`); `GET /pengesahan/antrean` di grup `role:admin,teknisi,viewer` (`routes/api.php:461`) | Viewer membuka daftar sesi; teknisi/viewer membuka antrean pengesahan | Viewer melihat draf semua teknisi; teknisi & viewer melihat antrean berikut catatan pengajuan | Hanya teknisi yang disaring `teknisi_id` | Tergantung K-30-05 (viewer boleh lihat apa) |
| B12 | **S** | `PelacakanController::index()` (`:87`–`:110`) | `GET /pelacakan?tahap=…` | Halaman bisa kosong padahal data ada; `meta.total` tetap total tanpa saringan | Penyaringan sesudah `paginate()`; komentar kode menyebutnya pilihan sadar, tapi `meta.total` tetap menyesatkan | Test dua halaman |
| B13 | **S** | `sidik-pelanggan-mobile`: `android/app/src/main/AndroidManifest.xml`, `android/app/build.gradle.kts:31`–`:32`, `lib/core/konfigurasi.dart:13` | Build release | Tanpa `INTERNET` di manifest main → request gagal; ditandatangani kunci debug; label `sidik_pelanggan`; tanpa `google-services.json`; `API_BASE_URL` bawaan `http://10.0.2.2:8000` | Pembacaan berkas | Build release + pasang di HP nyata |
| B14 | **S** (sebagian berubah) | `GenerateCertificate::kabarin()` (`:405`–`:417`) | Sertifikat pertama terbit | Pelanggan tidak dikabari saat sertifikat terbit pertama | Penerima hanya teknisi & penerbit. Sejak #207, revisi & batal **sudah** mengabari pelanggan (`SertifikatBerubah`) | Teks `push_pelanggan.dart:10` masih menjanjikan "sertifikat terbit" |
| B15 | **S** | `sidik-calibration-mobile`: `lib/screens/notification/notification_screen.dart:131`–`:150` | Ketuk notifikasi bertipe `certificate`, `certificates`, `equipments`, `standards`, `penugasan`, `customers` | Tidak ke mana pun | Hanya `calibration`, `permintaan_pelanggan`, `koreksi_pelanggan` yang dipetakan; server memakai 14 tipe berbeda | — |
| B16 | **S** (sebagian sudah berubah) | `dashboard_screen.dart:131`–`:132`; `calibration_detail_screen.dart:248`, `:1476`–`:1485`; `models/user.dart:46` | Viewer membuka beranda/detail sesi draf | Viewer masuk cabang beranda lab; tombol lanjutkan lembar muncul untuk sesi `draft`/`perlu_revisi` → 403 saat disimpan; label `admin` = "Admin" | Super admin **sudah** punya beranda sendiri (`dashboard_screen.dart:51`) | Widget test per peran |
| B17 | **S** | `sidik-calibration-mobile/lib/screens/calibration/*` | App mati sebelum "Simpan draft" | Isian hilang | Nol `AppLifecycleState`/autosave/simpanan lokal di modul lembar kerja | — |
| B18 | **F** | `GenerateCertificate::nomorBerikutnya()` (`:480`–`:494`) | Nomor revisi `…-R1` ada di bulan yang sama | — | Saringan `not like '%-R%'`; `RevisiSertifikatTest::test_sertifikat_baru_sesudah_revisi_dapat_nomor_urut_yang_benar` lulus | Docblock menyebut `NomorSertifikatSesudahRevisiTest`, padahal test itu tidak ada. Perlu dirapikan |

**Di luar cakupan G0–1, belum diverifikasi:** P01–P11, M01–M10 (performa) kecuali
klaim jumlah templat OCR. Klaim "46 templat, 6 `terverifikasi: true`" **cocok** dengan
`database/ocr-templates/*.json`.

### 2.1 Catatan bukti tambahan

- Salinan workbook di repo: `Project-PT-Sidik/` berisi 533 CSV, 43 PDF, nol
  `.xlsx`/`.xlsm` (berkas 30 Sep menulis 473 CSV). Ada 58 berkas `.xlsx/.xlsm` di
  `Downloads/` dan `Downloads/suhu/`. Asal & versinya **belum** diverifikasi dan isinya
  tidak dibuka (bisa memuat nama pelanggan atau dikunci sandi).
- Berkas 30 Sep meminta menambah **§41** ke `docs/permintaan-user-7.md`. Nomor itu sudah
  terpakai (§41 permintaan kalibrasi pelanggan, §42 koreksi). Paket ini perlu nomor baru
  (usul **§43**).
- Dokumen `claude/keputusan-26sep-gerbang-sertifikat.md` yang dirujuk berkas 30 Sep tidak
  ada di workspace.

## 3. Keputusan bisnis yang masih diperlukan

Tidak ada jawaban tertulis untuk K-30-01 sampai K-30-04 di `permintaan-user-7.md`,
memory, maupun dokumen lain. K4 juga masih "belum dijawab" di `AGENTS.md`.

| Kode | Butuh untuk | Tabrakan yang perlu dipilih pemilik |
|---|---|---|
| K-30-01 server/worker | P01, target performa | Belum ada bukti runtime bahwa hosting adalah penyebab utama. Lihat §4 G0-T0.4 |
| K-30-02 aturan PIC | G4 (bukan G0–1) | — |
| K-30-03 pemisahan wewenang | T1.1, T1.4 | `AGENTS.md` §Peran butir 4 (blokir dulu sebagai default aman) **vs** docblock `PemisahanWewenang` + `PEMISAHAN_WEWENANG_MEMBLOKIR=false` (keputusan 26 Sep: peringatan dulu). Konsekuensi blokir: sesi yang diisi satu-satunya admin aktif tidak bisa terbit tanpa admin kedua. Sensus 19 Sep: 2 admin, jadi masih mungkin |
| K-30-04 cakupan super admin & akun | T1.6 | Membalik keputusan 26 Sep (SA tidak menyetujui sesi/mengedit/kelola pengguna). AGENTS.md: "Menulis dibuka setelah K4 turun" |
| K-30-05 viewer | T1.9 | — |
| K-30-10 pemilik Firebase/Play/kunci rilis | T1.8 | — |
| K-30-16 masa berlaku token | T1.5 (bagian expiration) | — |

Bagian yang **tidak** bergantung pada keputusan: T1.4 (bagian pemilik pengajuan), T1.5
(cek status di middleware + cabut token di panel), T1.7, T1.10, B13 bagian `INTERNET`.

## 4. Rencana Gelombang 0–1

Semua langkah tetap menunggu persetujuan implementasi. Tiap slice: test penjaga dibuktikan
merah sebelum patch dan hijau sesudahnya, dua suite API hijau (SQLite + MySQL), `pint`
hanya untuk berkas yang disentuh, dan kontrak/`perintah-frontend` diperbarui.

### Gelombang 0 — Persiapan

| ID | Perubahan | Dependensi | Acceptance criteria | Verifikasi | Risiko |
|---|---|---|---|---|---|
| T0.1 | Pemilik menjawab K-30-01, 03, 04, 05, 10, 16 | — | Jawaban tertulis | — | G1 tertahan sebagian |
| T0.2 | Tulis §43 di `permintaan-user-7.md` + baris Gelombang; selaraskan K4 di `AGENTS.md` sesuai jawaban | T0.1 | Tidak ada kalimat yang saling bertentangan antara AGENTS.md, §43, dan docblock `PemisahanWewenang` | Grep "K4", "memblokir" | Instruksi aktif berubah → butuh persetujuan instruksi terpisah |
| T0.3 | Pemilik menunjuk workbook master resmi (versi + asal) | — | Daftar berkas + versi `FORM VALIDASI` tercatat | Tanpa membuka isi sebelum izin | Hanya untuk G9; **tidak** memblokir G1 |
| T0.4 | Baseline: suite SQLite & MySQL, `flutter analyze/test` dua app, ukuran APK; p95 endpoint terautentikasi | Izin baca produksi + token uji dari pemilik untuk p95 | Angka tertulis sebagai pembanding | `curl -w '%{time_total}'` 20× per endpoint; catat jam & kondisi tidur Render | Membaca produksi juga menulis `sessions` kalau lewat web; pakai API token saja |

### Gelombang 1 — Wewenang & blokir rilis

| ID | Perubahan | Dep. | Acceptance criteria | Verifikasi | Risiko |
|---|---|---|---|---|---|
| T1.1 (B01) | Panggil pemisahan wewenang di `approve()` lewat satu service bersama; bentuk sesuai K-30-03 (blokir atau ikut sakelar) | K-30-03 | Pengisi tidak bisa menyetujui sesinya sendiri (422 `pemisahan_wewenang`); SA ikut aturan sama | `test_b01` dibalik jadi test penjaga; dua suite | Kalau blokir: lab dengan satu admin aktif berhenti menerbitkan |
| T1.2 (B02) | Aksi Setujui panel memanggil service yang sama dengan API (gerbang + pemisahan wewenang) | T1.1 | Gerbang nyala → panel mendarat di `menunggu_pengesahan`, tanpa job | Test Livewire `CalibrationSessionsTable` | Tetap jaga `lockForUpdate` + `Diaudit` panel |
| T1.3 (B03) | Migrasi additive `raw_measurements.verified_by`, `verified_at`; cek status (`draft`/`menunggu_approval`); catat audit | — | Verifikasi menyimpan pelaku & waktu; sesi `disetujui` ditolak | Migrasi di MySQL; test API | Mobile lama tidak mengirim apa pun baru, jadi kompatibel |
| T1.4 (B04) | `tarik-pengajuan` hanya `diajukan_oleh` (atau SA); orang lain 404 | — (bagian `abaikan_peringatan`: K-30-03) | Admin B → 404 | `test_b04` dibalik | — |
| T1.5 (B06) | Middleware cek `status` sebelum `role:` + hapus token; panel `EditUser` cabut token saat nonaktif; `expiration` sesuai K-30-16 | K-30-16 hanya untuk expiration | Token akun nonaktif → 401 `akun_nonaktif` | `test_b06` dibalik | Expiration memaksa login ulang di lapangan; perlu sosialisasi |
| T1.6 (B05, B07) | Admin tidak bisa reset sandi/ubah peran admin lain & diri sendiri; penjaga admin terakhir; soft delete user | K-30-04 | Reset silang & penurunan peran ditolak; audit tetap punya pelaku | `test_b05` dibalik; test hapus | Migrasi `users.deleted_at` + `SoftDeletes` menyentuh banyak query `User::` (cek scope global) |
| T1.7 (B08) | `reject()` jadi UPDATE bersyarat + 409 | — | Penolakan sesi yang sudah disetujui → 409 | Test urutan paksa | Kabar & siaran hanya dikirim kalau baris berubah |
| T1.8 (B13) | Apk pelanggan: `INTERNET`, kunci rilis, label, `POST_NOTIFICATIONS`, `API_BASE_URL` via `--dart-define` di CI, Firebase | K-30-10 (kecuali `INTERNET` & label) | Build release terpasang di HP nyata, login & unduh sertifikat berhasil | HP nyata | Kunci rilis & berkas Firebase **tidak boleh** masuk repo publik |
| T1.9 (B16) | Viewer: beranda baca-saja, tombol tulis disembunyikan & dijaga; label "Master Data" | K-30-05 | Widget test per peran | `flutter test` + golden macOS | Golden hanya diperbarui di macOS 3.44.6 |
| T1.10 (B12) | Filter `tahap` di SQL (atau hitung total sesudah filter) | — | Halaman 2 berisi data yang benar; `meta.total` benar | Test dua halaman | Tahap diturunkan, bukan kolom; perlu ekspresi SQL setara `TahapPaket` atau kolom turunan |

## 5. Estimasi

Perkiraan kasar dalam hari kerja (Claude Code + tinjauan), **belum** termasuk waktu
menunggu keputusan dan uji HP nyata.

| Bagian | Estimasi | Asumsi | Ketidakpastian |
|---|---|---|---|
| G0 (T0.2, T0.4) | 1,5–2,5 | Token uji & izin baca tersedia | Suite MySQL 3 jam per putaran (dicek 24 Sep) |
| G1 API (T1.1–T1.7, T1.10) | 6–8 | Jawaban K-30-03/04/16 tidak mengubah bentuk besar | Soft delete user bisa menyentuh banyak query; tiap rute baru wajib masuk empat test penjaga daftar rute |
| G1 mobile lab (T1.9) | 1–1,5 | Golden dibuat di macOS | Akses macOS |
| G1 apk pelanggan (T1.8) | 1–2 | Pemilik menyiapkan Firebase & kunci | Bergantung pihak luar |
| **Total G0–1** | **±10–14** | | Berkas 30 Sep memperkirakan ±12,5 |

## 6. Batas pemeriksaan ini

- Semua reproduksi baru di SQLite. MySQL belum.
- Panel Filament (B02, B05 jalur panel, B06 jalur panel) hanya dibaca, belum diuji lewat Livewire.
- Performa (P/M) tidak diukur. Tidak ada kesimpulan tentang hosting tanpa data runtime.
- Excel & OCR: hanya inventaris artefak. Tidak ada rumus yang dibaca atau direkonstruksi.
