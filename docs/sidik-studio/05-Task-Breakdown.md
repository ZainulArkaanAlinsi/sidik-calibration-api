# 05 — Task Breakdown Sidik Studio

Tugas per tahap S0–S6 dengan ID `ST-T-xxx`. Estimasi dalam **hari-orang** kerja efektif, jujur:
sudah termasuk menulis test dan memperbaiki temuan putaran pertama peninjau, **belum** termasuk waktu
menunggu suite MySQL penuh (±3–5 jam mesin per putaran, `AGENTS.md` §Test) dan menunggu jawaban
Lab/pemilik.

**Owner**

| Kode | Peran |
|---|---|
| PA | pembangun API (`sidik-pembangun` di `sidik-calibration-api`) |
| PM | pembangun mobile/Studio (`sidik-pembangun` di `sidik-calibration-mobile`) |
| PN | peninjau (`sidik-peninjau`) |
| PJA | penjaga-angka (`sidik-penjaga-angka`) |
| PL | pemilik proyek (Zainul) — keputusan, izin dependensi, commit/push, berkas `.xlsm` |
| LAB | Lab / Manajer Teknis — jawaban pertanyaan, tanda tangan paritas |
| SA | pemegang akun `super_admin` pengesah (orang kedua) |

**DoD umum (berlaku untuk setiap tugas API, tidak diulang di tabel):** test terkait hijau di
SQLite; sebelum tahap ditutup, suite penuh SQLite **dan** MySQL hijau lewat
`jalankan-test-mysql.ps1`; `vendor/bin/pint` hanya pada berkas yang disentuh; tidak ada commit/push
tanpa perintah pemilik; kunci env baru masuk `.env.example` **dan** `render.yaml`; rute baru
tersapu `SapuRuteIsolasiOrganisasiTest`, `RuteInternalMenolakRoleLainTest`, `BatasAntarLabTest`,
`MeIzinTest` tanpa melonggarkan asersi.

**DoD umum mobile/Studio:** `flutter analyze` 0 isu di akar dan `studio/`; `flutter test` berkas
terkait hijau; golden terang + gelap pada lebar 1280/1138/711 untuk layar baru; tidak ada klaim
"jalan di perangkat" tanpa uji di perangkat.

**DoD angka:** setiap tugas yang menyentuh perhitungan atau membandingkan angka diperiksa PJA
secara independen (hitung ulang, bukan membaca test pembangun), dan laporannya disimpan di
`_agents_workspace/`.

---

## S0 — Fondasi

| ID | Tugas | Berkas dibuat / diubah | DoD khusus | Est. | Dep. | Owner | Link |
|---|---|---|---|---|---|---|---|
| ST-T-001 | Tutup P1: rapikan commit B1–B5 dan buka PR P1 | (git) branch `feat/studio-data-acuan` | dua suite penuh hijau sudah tercatat 9 Okt (SQLite 5408+2 dilewati/5410; MySQL 5410/5410); PR dibuka atas perintah PL | 0,5 | — | PA, PL | §48, `BACA-DULU-BACKEND.md` 9 Okt |
| ST-T-002 | Catat keputusan §49 dan perubahan `AGENTS.md` §Olah data butir 1 sebagai commit terpisah | `AGENTS.md`, `docs/permintaan-user-7.md` §49 | diff hanya dua berkas itu; pesan commit menyebut K-49-02 | 0,25 | — | PL | K-49-02, `06-rencana-rescope.md` butir 7 |
| ST-T-003 | **Spike S0a** mesin rumus: hitung ulang budget Volumetric Fixed dari input sesi tersimpan lewat PhpSpreadsheet, bandingkan per sel dengan PHP | `tests/Unit/Spike/MesinRumusSpikeTest.php`, laporan `_agents_workspace/studio-data-acuan/03-spike-S0a-lapor.md` | menjawab pertanyaan tepi `03-SDD.md` §6.4 dengan angka: identik bit atau selisih relatif per besaran; `TINV` vs `StudentTDistribution`; perbandingan teks; nilai galat; larik; waktu & memori per sesi; siklus. **Selesai 10 Okt 2026** (6/6 hijau): butir 1–3 dan 6 terjawab berangka, butir 5 sebagian, butir 4 dan 7 (siklus/cache) tidak diuji → ST-T-090/094/095 | 3 | `composer require` (sudah, 5.10.0) | PA, PJA | ADR-49-02, SDD §13 |
| ST-T-004 | Patok versi `phpoffice/phpspreadsheet` persis dan audit dependensi | `composer.json` (L18 `"*"` → versi persis) | `composer.lock` tidak berubah isi; `composer audit` dilaporkan; PL menyetujui. **10 Okt 2026:** L18 = `"^5.10"`, lock 5.10.0 + 3 paket baru MIT (`maennchen/zipstream-php`, `markbaker/complex`, `markbaker/matrix`), `composer audit` tanpa advisory baru. `^5.10` belum patok persis seperti ADR-49-02 → keputusan PL | 0,25 | ST-T-003 | PA, PL | R-02, FR-STU-132 |
| ST-T-005 | **Spike S0b** aplikasi `studio/`: build Windows + Android dengan identitas sendiri, berdampingan dengan aplikasi lapangan; putus kopling FCM | `studio/**` | exe dan APK terpasang di perangkat uji; folder data Windows terpisah; tidak ada inisialisasi Firebase di Studio; laporan. **13 Okt 2026:** build debug exe + APK ada (10 Okt), identitas sendiri, tanpa Google Services, FCM diputus `TanpaTokenPush`, `flutter analyze` 0 isu, `flutter test` 28/28 (`03-SDD.md` ADR-49-06). Pemasangan di perangkat dan folder data saat jalan belum diuji → pindah ke ST-T-020 | 3 | — | PM | ADR-49-06, FR-STU-010 |
| ST-T-006 | **Spike S0c** pembaca `.xlsm`: dekripsi (Agile?) lewat `Reader\Xlsx::setEncryptionPassword()`, baca rumus/larik/nama, ukur waktu | skrip spike lokal (tidak di-commit) | sandi diketik saat jalan, tidak ada di berkas/log/riwayat shell; laporan opsi A/B/C ADR-49-10 | 2 | ST-T-008 | PA | ADR-49-10, FR-STU-161 |
| ST-T-007 | Paket dokumen `docs/sidik-studio/` + Q-49 + baris peta BACA-DULU | `docs/sidik-studio/*.md`, `docs/pertanyaan-lab-studio-acuan.md`, `docs/BACA-DULU-BACKEND.md` | ditinjau PN; konsisten K-49; klaim kode ber-path:baris | 1,5 | — | PA (agen dok), PN | permintaan pemilik 9 Okt |
| ST-T-008 | Serahkan `.xlsm` asli Volumetric (Fixed + Graduated) dan pastikan 4 `.xlsm` Micrometer di mesin pemilik; catat sha256 | `database/data/manifest-workbook-volumetric.json` (baru, tanpa sandi) | sha256 cocok berkas; sandi tidak ditulis | 0,25 | — | PL | Q-49-11, §49 "Butuh dari pemilik" |
| ST-T-009 | Jawab Q-49-01 s.d. Q-49-04 (minimal) | `docs/pertanyaan-lab-studio-acuan.md` | jawaban tertulis + tanggal | 0,5 | — | PL, LAB | `01-PRD.md` §3 |
| ST-T-010 | Revisi `03-SDD.md` sesudah spike: cabut tanda tunggu yang terjawab | `docs/sidik-studio/03-SDD.md`, `02-SRS.md` (NFR-STU-03) | setiap tanda tunggu terjawab atau diberi tanggal tindak lanjut. **13 Okt 2026:** tanda S0a dan S0b yang terjawab dicabut di `docs/sidik-studio/*`; sisa (S0c, S1, ukur S4) bertindak lanjut di `03-SDD.md` §13; S0c (ST-T-006) belum, jadi revisi kedua menyusul sesudah ST-T-006 | 0,5 | ST-T-003, 005, 006 | PA, PN | SDD §13 |

**Subtotal S0: 11,75 hari-orang.**

## S1 — Kerangka aplikasi

| ID | Tugas | Berkas dibuat / diubah | DoD khusus | Est. | Dep. | Owner | Link |
|---|---|---|---|---|---|---|---|
| ST-T-011 | Cangkang final: navigasi tiga lebar, seksi berizin, bilah atas/status, Beranda dasar | `studio/lib/layar/cangkang_studio.dart`, `seksi_studio.dart`, `layar/beranda/beranda_screen.dart`, `widget/bilah_status.dart` | golden 1280/1138/711 terang+gelap; seksi tanpa izin tidak tampil (test) | 3 | ST-T-005 | PM | FR-STU-011–014, ST-02 |
| ST-T-012 | Masuk, sesi, keluar, izin, header `X-Klien`/`X-Versi-Klien` | `studio/lib/layar/masuk_screen.dart`, `gerbang_masuk.dart`, `inti/lingkungan_studio.dart` | test: 401 → dialog, layar kembali; logout menyapu cache; izin dimuat ulang saat kembali dari latar | 2 | ST-T-011 | PM | FR-STU-001–009, ST-01 |
| ST-T-013 | Server: catat `X-Klien`/`X-Versi-Klien` di log; mekanisme `426 klien_kedaluwarsa` dengan versi minimum Studio dari config | `app/Http/Middleware/` (baru), `bootstrap/app.php`, `config/` + `.env.example` + `render.yaml` | tanpa header tetap 200 (test); versi di bawah minimum 426 hanya untuk `X-Klien: sidik-studio`. **13 Okt 2026, dikerjakan di S1b:** `IdentitasKlien` (grup `api`), `config/studio.php` `versi_minimum` ← `STUDIO_VERSI_MINIMUM` (bawaan 0.0.0), `IdentitasKlienTest` 4/4 | 1 | — | PA | FR-STU-003 |
| ST-T-014 | Pindah layar Data Acuan M2 ke Studio; cabut menu Data Acuan dari aplikasi lapangan (data layer tetap) | `studio/lib/layar/seksi_studio.dart`; repo mobile `lib/screens/shell/*` | suite flutter akar & studio hijau; aplikasi lapangan tanpa menu Data Acuan; label `versi_acuan` tetap | 2 | ST-T-011 | PM | FR-STU-070, 185; ST-20–23 |
| ST-T-015 | Grid bersama `widget/grid/grid_sel.dart` dasar: virtualisasi, seleksi, navigasi keyboard §4.1 (tanpa rumus), salin TSV; keputusan widget (bawaan vs `two_dimensional_scrollables`) | `studio/lib/widget/grid/*` (+ `pubspec.yaml` bila dependensi baru) | 2.000 × 20 bergulir ≥ 55 fps di laptop kantor & tablet uji (rekam frame); dependensi baru hanya dengan izin PL | 4 | ST-T-011 | PM, PL | NFR-STU-04, SDD §12 |
| ST-T-016 | Keadaan global ST-90: pita offline, dialog 409, simpan gagal, 429, 426, sesi berakhir | `studio/lib/widget/keadaan_layar.dart`, `dialog_alasan.dart`, `dialog_sandi.dart` | widget test per keadaan; isian tetap sesudah gagal | 2 | ST-T-011 | PM | `04-UI-UX-Flow.md` §3 |
| ST-T-017 | Pengaturan + tema + l10n id/en | `studio/lib/layar/pengaturan/pengaturan_screen.dart`, `l10n/*.arb` | kontras tetap lulus `tema_studio_test.dart` | 1 | ST-T-011 | PM | FR-STU-015, ST-70 |
| ST-T-018 | CI: job analyze/test/build Studio (Windows + Android); `tool/cek-berkas-yatim.sh` tahu paket kedua | `.github/workflows/periksa-pr.yml` (repo mobile), `tool/cek-berkas-yatim.sh` | job hijau di PR; job rilis aplikasi lapangan tidak berubah | 1,5 | ST-T-005 | PM | riset C |
| ST-T-019 | Tinjau S1 lintas batas (izin dari server, identitas, kopling FCM, token aman) | laporan `_agents_workspace/` | 0 temuan kritis/tinggi terbuka | 0,5 | ST-T-011–018 | PN | ADR-49-06 |
| ST-T-020 | Uji perangkat: exe di Windows kantor, APK di tablet uji (sideload karena HyperOS memblokir `adb install`, `00-status.md`) | catatan uji + tangkapan layar | tiga lebar diperiksa; daftar yang tidak bisa diuji ditulis | 0,5 | ST-T-019 | PL, PM | NFR-STU-10 |

**Subtotal S1: 17,5 hari-orang.**

## S1b — Workbook Alat, mode Asli Excel baca-saja (K-49-08..12, 13 Okt 2026)

Tahap baru yang dimajukan pemilik (K-49-09) sesudah S1, sebelum S2. Rencana
`_agents_workspace/studio-data-acuan/02-rencana-S1b.md` disetujui 13 Okt 2026. Rancangan:
`03-SDD.md` ADR-49-10 (revisi) dan §4.6; kontrak `docs/kontrak-api.md` §Studio Workbook.

| ID | Tugas | Berkas dibuat / diubah | DoD khusus | Est. | Dep. | Owner | Link |
|---|---|---|---|---|---|---|---|
| ST-T-201 | Migrasi additive `workbook_master` + `workbook_master_lembar`, model (`WorkbookMaster` Diaudit; lembar tanpa `organization_id`, `sel` tersembunyi dari `toArray`) | `database/migrations/2026_10_13_100000_create_workbook_master_tables.php`, `app/Models/WorkbookMaster.php`, `WorkbookMasterLembar.php` | nama index eksplisit ≤ 64; unik `(organization_id, sha256)`; nol kolom di tabel lama. **Selesai 13 Okt** | 0,5 | — | PA | SDD §4.6, K-49-08 |
| ST-T-202 | Pembaca CSV + kanonik + pengecualian lembar + peta profil | `app/Services/Studio/Workbook/{PembacaCsvWorkbook,KanonikWorkbook,PengecualianLembar,PetaProfilWorkbook}.php` | sha256 stabil; sel kosong tidak disimpan; `nilai [=rumus]`/`[ARRAY:=…]` terurai; DATABASE/SERTIFIKAT tidak dibuka; semua kode peta ada di registry. **Selesai 13 Okt** (`WorkbookPembacaCsvTest`, `WorkbookPetaDanPengecualianTest`) | 1,5 | ST-T-201 | PA | 01-riset-peta-workbook.md |
| ST-T-203 | Pembaca `.xlsm`: dekripsi sendiri ke temp privat per impor, `setMaxEncryptionSpinCount(100000)`, nilai cache + rumus + format + gaya ringkas + tata letak; penyapu temp | `app/Services/Studio/Workbook/{PembacaXlsmWorkbook,GagalBacaWorkbook,PenyapuTempWorkbook}.php`, `app/Console/Commands/SapuTempWorkbook.php`, `routes/console.php`, `config/studio.php` | jalur sukses, sandi salah, spin > batas, bukan OOXML, exception DI TENGAH `load()` → temp privat kosong; sandi tidak di pesan/jejak; isi sheet dikecualikan tidak dibuat di memori. **Selesai 13 Okt** (`StudioImporWorkbookXlsmTest`) | 2 | ST-T-202, ST-T-006 | PA | ADR-49-10, 03-spike-S0c.md |
| ST-T-204 | Perintah `studio:impor-workbook` (`--csv`, `--xlsm`, `--akar`, `--organisasi`, `--kering`, `--oleh`, `--ganti` csv saja, `--saya-paham-data-pelanggan` wajib untuk `--xlsm`); berkas asli disimpan identik byte (K-49-11), ZIP polos ditandai `berkas_terenkripsi=false` | `app/Console/Commands/ImporWorkbookStudio.php`, `app/Services/Studio/Workbook/ImporWorkbook.php` | idempoten per sha256; sandi lewat `secret()`, tidak tampil; gagal sandi per berkas → lanjut; `--kering` nol tulis; kanari DATABASE/SERTIFIKAT tidak di DB/keluaran. **Selesai 13 Okt** (`StudioImporWorkbookCsvTest`, `StudioImporWorkbookXlsmTest`) | 1,5 | ST-T-202, 203 | PA | K-49-10, K-49-11 |
| ST-T-205 | API baca `GET /studio/workbook`, `/{workbook}`, `/{workbook}/lembar/{lembar}` + Resource; izin `workbook.lihat`; throttle `studio-baca` | `app/Http/Controllers/Api/Studio/WorkbookController.php`, `app/Http/Resources/Studio/*`, `routes/api.php`, `app/Services/MatriksIzin.php`, `app/Providers/AppServiceProvider.php` | admin/teknisi/viewer/super_admin 200, pelanggan 403, tanpa token 401; lab lain 404 (+ jejak lintas lab); lembar workbook lain 404; maks 500 baris/halaman; lembar dikecualikan `sel: []`; rute tersapu tiga test sapu. **Selesai 13 Okt** (`StudioWorkbookApiTest`) | 1,5 | ST-T-201 | PA | FR-STU-003, SDD §4.6 |
| ST-T-206 | ST-T-013 (lihat S1) dikerjakan bersama S1b | lihat ST-T-013 | lihat ST-T-013. **Selesai 13 Okt** | — | — | PA | FR-STU-003 |
| ST-T-207 | Impor CSV nyata ke DB lokal tablet + pindah server lokal ke worktree S1b | (data lokal, tidak di-commit) | ringkasan jumlah workbook/lembar/sel/rumus/dikecualikan dilaporkan. **13 Okt:** 46 workbook, 473 lembar, 135.489 sel, 6.360 rumus, 99 dikecualikan, 4 tanpa `kode_profil`; impor ulang = `sudah_ada 46`. Pindah server: orkestrator | 0,25 | ST-T-204 | PA, PL | `03-bangun-S1b-api.md` |
| ST-T-208 | Impor `.xlsm` semua alat (57 berkas) dengan sandi di terminal pemilik — lewat `impor-xlsm.ps1` (ST-T-214) | (data, tidak di-commit) | jumlah berkas sukses/gagal sandi/duplikat dilaporkan; temp privat kosong sesudahnya; keputusan redaksi INPUT DATA **sudah diambil: K-49-12, tidak diredaksi** | 0,25 | ST-T-204, ST-T-211..214 | PL | K-49-10, K-49-11, K-49-12 |
| ST-T-209 | Studio: grid `TableView` (merge, kepala terkunci, lebar, freeze), daftar per keluarga, layar workbook (tab sheet, bilah rumus, inspektur, penanda lembar dikecualikan), seksi `workbookAlat` bergerbang `workbook.lihat` | `studio/lib/widget/grid/grid_sel.dart`, `studio/lib/data/workbook_*`, `studio/lib/layar/workbook/*`, `pubspec.yaml` (`two_dimensional_scrollables ^0.5.5`, diizinkan PL 13 Okt) | 2.000 × 20 ≥ 55 fps Windows + tablet; golden; kontrak sel sama persis `kontrak-api.md`. Berjalan (agen mobile, `03-bangun-S1b-mobile.md`) | 5 | ST-T-205, ST-T-015 | PM | 02-rencana-S1b.md |
| ST-T-210 | Tinjau S1b (API↔mobile, izin, kebocoran DATABASE) + suite SQLite & MySQL penuh | laporan `_agents_workspace/` | 0 temuan kritis/tinggi; dua suite hijau sebelum S1b disebut selesai | 1 | ST-T-201–209 | PN, PL | DoD umum |

**Tahap A — "Workbook Alat persis Excel" (sisi API, K-49-12, 13 Okt 2026).** Laporan
`_agents_workspace/studio-data-acuan/03-bangun-A-api.md`.

| ID | Tugas | Berkas dibuat / diubah | DoD khusus | Est. | Dep. | Owner | Link |
|---|---|---|---|---|---|---|---|
| ST-T-211 | K-49-12: isi DATABASE/SERTIFIKAT/konsep disimpan (xlsm & csv); `PengecualianLembar` tinggal penanda `tautan_tabel`; penahan `--saya-paham-data-pelanggan` dicabut | `app/Services/Studio/Workbook/{PengecualianLembar,PembacaCsvWorkbook,PembacaXlsmWorkbook,ImporWorkbook}.php`, `app/Console/Commands/ImporWorkbookStudio.php`, `app/Models/WorkbookMasterLembar.php` | kanari sintetis TERSIMPAN & terbaca lab pemilik; 404 + tanpa kanari untuk 4 peran lab lain; fixture sintetis saja (repo PUBLIC). **Selesai 13 Okt** (`StudioImporWorkbook{Csv,Xlsm}Test`, `StudioWorkbookApiTest`, `WorkbookPembacaCsvTest`) | 0,5 | ST-T-201..205 | PA | K-49-12 |
| ST-T-212 | Format kanonik 3 — tata letak `.xlsm` setia Excel: lebar/tinggi bawaan, `freeze` dari xSplit/ySplit, `garis_kisi`, `zoom`; `g` + `bungkus`/`vertikal`/`ukuran`/`font`; sel kosong hanya bila ber-isi/garis | `KanonikWorkbook.php`, `PembacaXlsmWorkbook.php`, `tests/Concerns/WorkbookUji.php` | fixture: freeze disimpan dalam keadaan digulir tetap `B3`; bawaan sheet 12,5/18; tanpa bawaan → 9,140625/15; sel kosong cuma-tebal tidak disimpan. **Selesai 13 Okt** | 1 | ST-T-203 | PA | SDD §4.6, kontrak §16 |
| ST-T-213 | CSV: `lebar_kolom` perkiraan (panjang teks + 1, 4..60) + `lebar_perkiraan = true`; impor ulang CSV ke DB lokal `--csv --ganti` (cadangan dulu) | `PembacaCsvWorkbook.php`; data lokal | cadangan `studio-lokal.sebelum-A.sqlite` terverifikasi; ringkasan impor dilaporkan; ulang = `sudah_ada`. **Selesai 13 Okt:** 46 diganti, 473 lembar, 169.521 sel, 7.186 rumus, 0 dikecualikan, 99 bertautan; ulang `sudah_ada 46` | 0,5 | ST-T-202 | PA | `03-bangun-A-api.md` |
| ST-T-214 | `--ganti` untuk xlsm (sumber sama, nama sama, lab sama; ekstrak ulang berkas sama); API daftar xlsm dulu + `ada_versi_xlsm`/`versi_xlsm_id`; skrip pemilik `impor-xlsm.ps1` (di luar repo) | `ImporWorkbook.php`, `ImporWorkbookStudio.php`, `VersiXlsmWorkbook.php` (baru), `WorkbookMasterResource.php`, `WorkbookController.php`; `C:\Users\USER\sidik-studio-lokal\impor-xlsm.ps1` | csv/xlsm lain/lab lain tidak tersentuh `--ganti`; pasangan hanya di lab sama; sandi hanya lewat prompt pemilik. **Selesai 13 Okt** (skrip belum dijalankan — butuh sandi pemilik, ST-T-208) | 0,5 | ST-T-204, ST-T-205 | PA | K-49-10, K-49-12 |

**Subtotal S1b: 13,5 hari-orang** (PA ±7, PM ±5, sisanya tinjau/pemilik) **+ tahap A sisi API
2,5** (ST-T-211..214).

## S2 — Kelola Data

| ID | Tugas | Berkas dibuat / diubah | DoD khusus | Est. | Dep. | Owner | Link |
|---|---|---|---|---|---|---|---|
| ST-T-030 | `RegistriTabel`, `DefinisiTabel`, `DefinisiKolom` + 56 definisi + test sapu skema dua arah | `app/Services/KelolaData/*.php`; `tests/Unit/RegistriKelolaDataSapuSkemaTest.php` | sapu menjaring tabel/kolom baru dan tabel hilang; setiap definisi tampil punya lingkup (test) | 4 | S1 selesai | PA | FR-STU-030, 031; SRS §5; ADR-49-05 |
| ST-T-031 | Angkat aturan validasi 10 tabel kelas `ubah`/`arsip` ke sumber bersama (refactor perilaku sama) | FormRequest/controller alat, pelanggan, standar, ruangan, metode, kategori, folder, berkas folder, pengguna, organisasi | test API lama hijau **tanpa diubah** | 3 | ST-T-030 | PA | FR-STU-040, SDD §5.2 |
| ST-T-032 | `Diaudit::denganCatatan()` supaya alasan masuk `audit_logs.note` | `app/Models/Concerns/Diaudit.php`, test baru | event di dalam lingkup menulis `note`; di luar tidak; bersarang aman | 0,5 | — | PA | FR-STU-040, 190 |
| ST-T-033 | Baca: jelajah (paginasi, urut, saring terstruktur, cari, arsip), detail, riwayat; rute GET + izin `kelola-data.lihat` | `app/Http/Controllers/Api/Studio/KelolaDataController.php`, `app/Services/KelolaData/LayananKelolaData.php`, `app/Http/Requests/Studio/SaringKelolaDataRequest.php`, `routes/api.php`, `app/Services/MatriksIzin.php` | test per peran; org lain 404 (termasuk SA); tersembunyi 404 sama dengan tidak ada; kolom rahasia tidak pernah keluar (test sapu kolom) | 3 | ST-T-030 | PA | FR-STU-032–039, 047–050; SK-03, SK-12 |
| ST-T-034 | Tulis: ubah (`If-Match`, alasan), tambah, arsip, pulihkan; izin `kelola-data.ubah`/`arsip`; throttle `kelola-data-tulis`; nol rute `DELETE` | idem + `app/Providers/AppServiceProvider.php` (limiter) | SK-05–SK-09; sapu: tidak ada `DELETE`/`forceDelete` di namespace Studio | 3 | ST-T-031, 032, 033 | PA | FR-STU-040–043, 046, 051–053; ADR-49-07 |
| ST-T-035 | Ekspor CSV stream + pelindung injeksi + audit `diekspor` + izin + throttle | idem + `app/Models/AuditLog.php` (konstanta `ACTION_DIEKSPOR`) | SK-13; memori tetap rata pada 50.000 baris sintetis | 1,5 | ST-T-033 | PA | FR-STU-044, Q-49-05 |
| ST-T-036 | Koreksi pembacaan dari Studio lewat `PUT /api/calibrations/{c}`: pastikan jalur, tambah test dari sudut Studio | test baru `KoreksiPembacaanDariStudioTest` (tanpa ubah controller kecuali ditemukan cacat) | SK-10, SK-11; nilai lama & baru di `audit_logs` entitas sesi | 0,5 | — | PA | FR-STU-045 |
| ST-T-037 | `kontrak-api.md` § Kelola Data; regenerasi nama izin untuk mobile | `docs/kontrak-api.md`, `docs/skrip/gen-nama-izin-mobile.php` (jalankan), `test/fixtures/nama_izin.json` (mobile) | contoh JSON sama dengan respons test | 0,5 | ST-T-034, 035 | PA | SDD §8.2 |
| ST-T-038 | Studio ST-10–ST-13 (daftar, grid, detail, riwayat) | `studio/lib/layar/kelola_data/*`, `studio/lib/data/kelola_data_*` | golden 3 lebar; matriks keadaan `04-UI-UX-Flow.md` §3.1 teruji | 4 | ST-T-015, 033 | PM | ST-10–13 |
| ST-T-039 | Studio ST-14–ST-16 (form, arsip, ekspor) | idem | alasan wajib di klien **dan** server; konflik 409 dialog | 3 | ST-T-034, 035, 038 | PM | ST-14–16 |
| ST-T-040 | Studio ST-17 koreksi pembacaan | `studio/lib/layar/kelola_data/koreksi_pembacaan_screen.dart` | sesi disetujui → baca saja; peringatan pemisahan wewenang tampil | 2 | ST-T-036, 038 | PM | ST-17 |
| ST-T-041 | Tinjau S2 + suite penuh SQLite & MySQL | laporan | 0 kritis/tinggi; dua suite hijau dicatat jumlah & durasi | 1 | ST-T-030–040 | PN, PA | NFR-STU-05, 06 |
| ST-T-042 | Uji staging: Master Data membetulkan data nyata (nama/alamat pelanggan disamarkan di laporan) | catatan uji | G4 terukur: setiap perubahan ada di `audit_logs` | 0,5 | ST-T-041 | LAB, PL | `01-PRD.md` G4 |

**Subtotal S2: 26,5 hari-orang.**

## S3 — Data Acuan tulis (P2 CR-48 di Studio)

| ID | Tugas | Berkas dibuat / diubah | DoD khusus | Est. | Dep. | Owner | Link |
|---|---|---|---|---|---|---|---|
| ST-T-060 | Migrasi `simulasi_versi` + `simulasi_versi_sesi` + model + entri registry (baca saja) | `database/migrations/2026_xx_xx_*_create_simulasi_versi_tables.php`, `app/Models/SimulasiVersi*.php`, `RegistriTabel` | migrate + rollback bersih di SQLite & MySQL; nama index ≤ 64 | 1 | S2 | PA | ADR-49-09, SDD §4.3 |
| ST-T-061 | Draf, sunting sel (BR-04/07), tempel, tambah/hapus baris draf, lock; izin `data-acuan.sunting` | `app/Http/Controllers/Api/DataAcuanController.php`, `app/Services/Acuan/LayananDataAcuan.php` (baru), `routes/api.php`, `MatriksIzin.php` | test per kode galat CR-48 03 §3; teknisi 404/403 | 2 | ST-T-060 | PA | FR-STU-072–076, 086 |
| ST-T-062 | Simulasi dampak Data Acuan (job + potongan idempoten, angka cetak via desimal profil) | `app/Jobs/JalankanSimulasi*.php`, `app/Services/Simulasi/*` | ubah satu balok Micrometer → laporan menyebut titik & kolom tepat; tidak menyimpan ke sesi (hitung baris) | 2,5 | ST-T-061 | PA, PJA | FR-STU-079, 080 |
| ST-T-063 | Ajukan/sahkan/tolak/kembalikan/tarik + `PemisahanWewenang` versi + grup rute `role:super_admin` + izin `ajukan`/`sahkan` + throttle `pengesahan-versi` | `app/Services/PemisahanWewenang.php`, `routes/api.php` (grup saudara), `LayananDataAcuan` | SK-14; transisi CR-48 02 §3 lengkap; `lolosBacaSuperAdmin` tidak disentuh (diff) | 1,5 | ST-T-062 | PA | FR-STU-081–085, K-49-05 |
| ST-T-064 | Penjadwal `terjadwal → aktif`, siaran `data_acuan`, pengingat standar | `routes/console.php`/scheduler, `PerubahanDataOrganisasi` | test waktu tiruan | 0,5 | ST-T-063 | PA | FR-STU-088, CR-48 FR-22 |
| ST-T-065 | Butir "dibawa ke P2" §48 (a)–(d) | `CalibrationController` (lembar-kerja), `TemplateLembarKerja`, `CertificateSnapshotBuilder`, `CalibrationValidator`, `PaketAcuanVersi` | satu test per butir; suite Micrometer + Dial Indicator hijau tanpa diubah | 2 | ST-T-061 | PA, PJA | FR-STU-087 |
| ST-T-066 | Studio ST-21 mode draf (sunting, tempel dengan pratinjau, lock, konflik) | `studio/lib/layar/...` + paket bersama `lib/screens/data_acuan/*` bila perlu | 2.000 baris; konflik 409 per sel | 3 | ST-T-015, 061 | PM | ST-21 |
| ST-T-067 | Studio ST-40 laporan simulasi (bersama), ST-24 ajukan, ST-50/51 pengesahan | `studio/lib/layar/simulasi/*`, `pengesahan/*` | `pengesah_ikut_menyunting` tampil sebagai kartu merah tanpa tombol | 3 | ST-T-062, 063 | PM | ST-24, 40, 50, 51 |
| ST-T-068 | Aplikasi lapangan: kirim sha versi saat simpan, banner draf terdampak, label versi | repo mobile `lib/services/lembar_kerja_service.dart`, layar lembar kerja | uji HP nyata; test widget banner | 1 | ST-T-065 | PM | FR-STU-087(a), 183 |
| ST-T-069 | Rekalibrasi standar Micrometer nyata end-to-end di staging | catatan uji | G1 diukur (≤ 30 menit, nol commit); PJA mengadu angka sesudah sah | 1 | ST-T-064–068 | LAB, SA, PJA | CR-48 T2.10 |
| ST-T-070 | Tinjau S3 + dua suite | laporan | 0 kritis/tinggi | 1 | ST-T-060–069 | PN, PA | — |

**Subtotal S3: 18,5 hari-orang.**

## S4 — Mesin rumus, Lembar Hitung, gerbang paritas (server + pilot)

| ID | Tugas | Berkas dibuat / diubah | DoD khusus | Est. | Dep. | Owner | Link |
|---|---|---|---|---|---|---|---|
| ST-T-090 | `MesinRumus` + `MesinRumusPhpSpreadsheet` (siklik 0, cache dibersihkan per evaluasi, identitas) + test perilaku setiap fungsi daftar putih lawan nilai Excel acuan | `app/Services/Rumus/MesinRumus.php`, `MesinRumusPhpSpreadsheet.php`, `tests/Unit/Rumus/*` | nilai acuan Excel tiap fungsi dari workbook uji yang dihitung ulang; tidak ada fungsi ditolak yang bisa dievaluasi | 5 | ST-T-003, 004, 010 | PA, PJA | ADR-49-02, SDD §6.4 |
| ST-T-091 | `LembarHitung` (objek nilai) + urai/serialisasi kanonik + sha256 + validasi format | `app/Services/Rumus/LembarHitung.php`, `Pengurai*.php` | contoh SDD §6.5 terurai dan terserialisasi ulang identik byte | 3 | ST-T-090 | PA | SDD §6.1–6.3 |
| ST-T-092 | Migrasi kolom `formula_versions` §4.1 + `lembar_hitung_suntingan` + `unggahan_workbook`; penjaga model (beku, tanpa hapus); `IsiLembarHitungSha256StabilTest` di MySQL **memutuskan** ADR-49-03 (json vs fallback `isi longText`) | `database/migrations/…`, `app/Models/FormulaVersion.php`, `app/Models/LembarHitungSuntingan.php`, `app/Models/UnggahanWorkbook.php` | migrate + rollback dua DB; test sha stabil; keputusan dicatat di SDD | 2 | ST-T-091 | PA | ADR-49-03, SDD §4 |
| ST-T-093 | `ValidatorLembarHitung` statis: fungsi, volatile, siklus, rujukan luar/rusak, pembulatan di hulu, ukuran, sel terikat, keluaran wajib, nama, kontrak input | `app/Services/Rumus/ValidatorLembarHitung.php`, `GrafKetergantungan.php` | SK-16; graf benar untuk rujukan lintas sheet, nama, rentang | 4 | ST-T-091 | PA | FR-STU-104–112, 186 |
| ST-T-094 | `PengikatMasukan`: 7 jenis sumber; aturan kosong (`#N/A`); acuan lewat `Tabel*` di lingkup `PenentuVersiAcuan`; registry resolver per profil (`volumetric.cmc_terdekat`, dst.) | `app/Services/Rumus/PengikatMasukan.php`, `app/Services/Rumus/Resolver/*.php` | setiap resolver punya test; acuan dari DB dan JSON memberi float identik bit (pola `TabelAcuanSetaraJsonTest`) | 4 | ST-T-091 | PA, PJA | FR-STU-102 |
| ST-T-095 | `EvaluatorLembarHitung` / `JalurLembarHitung`: iterasi, aturan tahan, komponen, **agregat dibaca dari blok `agregat` lembar** (K-49-07), `perbandingan_cmc` dari agregat lembar, catatan & jejak bertemplat; bentuk keluaran = `hitungPerGrup()` | `app/Services/Rumus/EvaluatorLembarHitung.php` | keluaran contoh SDD §6.5 (termasuk `BUDGET!C30:C36`) = PHP untuk satu sesi; keluaran opsional `#N/A` = tidak ada | 5 | ST-T-093, 094, 106 | PA, PJA | ADR-49-08, SDD §7.2 |
| ST-T-096 | `PemilihJalurHitung` satu pintu: store, update, preview, `CalibrationValidator`, `HitungUlangSesi`; `versi_rumus` + ETag di `lembar-kerja`; snapshot sertifikat additive; blok `studio` di `/api/health`; pesan `FormulaController` | `app/Services/Calibration/PemilihJalurHitung.php`, `CalibrationController`, `CalibrationValidator`, `HitungUlangSesi`, `CertificateSnapshotBuilder`, `HealthController` | SK-21, SK-25; semua test jalur lama hijau tanpa diubah; `sertifikat:bangun-ulang --dry-run` nol perubahan pada sertifikat lama | 5 | ST-T-095 | PA | FR-STU-124–126, 180–184 |
| ST-T-097 | `LayananLembarHitung`: draf, sunting sel/nama/pengikat/struktur (geser rujukan di server), periksa, ajukan, buang, sahkan, tolak, kembalikan, tarik; `PemisahanWewenang` versi; rute, izin, throttle; `kontrak-api.md` | `app/Services/Rumus/LayananLembarHitung.php`, `app/Http/Controllers/Api/Studio/LembarHitungController.php`, `routes/api.php`, `MatriksIzin.php`, `PemisahanWewenang.php`, `docs/kontrak-api.md` | SK-14; transisi SRS §6 lengkap; isolasi org 404 | 5 | ST-T-092, 093 | PA | FR-STU-111, 121–123, 127 |
| ST-T-098 | API pratinjau, ketergantungan, banding, log metode | `LembarHitungController`, `app/Services/Rumus/Pratinjau.php` | SK-17 (nol baris berubah); angka sebagai string | 3 | ST-T-096, 097 | PA | FR-STU-113–115, 127 |
| ST-T-099 | Simulasi + `PembandingParitas` (definisi PRD §5, render angka cetak) + bayangan kandidat (job sesudah simpan sesi nyata, tanpa stempel) + pengawasan PHP sesudah aktif (`paritas_php_beda` di validator) + `MesinRumusVersiTerkunciTest` | `app/Services/Rumus/PembandingParitas.php`, `app/Jobs/*`, `CalibrationValidator` | SK-18, SK-19, SK-22; potongan idempoten sesudah worker dimatikan di tengah | 5 | ST-T-096, ST-T-060 | PA, PJA | FR-STU-116–120, 132; ADR-49-04 |
| ST-T-100 | Lembar Hitung v1 Volumetric **Fixed** × 3 rumus (`labu_ukur`, `pipet_volume`, `picnometer`) ditulis dari PHP | `database/data/lembar-hitung/volumetric-fixed-v1.json` (isi kanonik, sumber impor awal), perintah `lembar-hitung:impor-awal` | SK-20: paritas pra-bayangan LULUS 100% atas semua sesi tersimpan + vektor `VolumetricGlasswareMasterTest` + seeder; laporan PJA | 3 | ST-T-099, 106, 107 | PA, PJA | `04-paritas-volumetric.md`, SDD §6.5 |
| ST-T-101 | Lembar Hitung v1 Volumetric **Graduated** × 3 rumus (`buret`, `gelas_ukur`, `pipet_ukur`), mode `per_sesi`, pembanding nol hantu sebagai catatan | `database/data/lembar-hitung/volumetric-graduated-v1.json` | paritas pra-bayangan LULUS; V-4 (Pipet Ukur Fixed vs Graduated) tetap meniru kode sampai dijawab | 3 | ST-T-100 | PA, PJA | V-4, `pertanyaan-lab-volumetric.md` no. 2 |
| ST-T-102 | Lembar Hitung v1 **Micrometer** (4 varian; Data Acuan berstempel; `NOW()` → `sesi.tanggal_kalibrasi`) | `database/data/lembar-hitung/micrometer-v1.json` | paritas pra-bayangan LULUS termasuk sesi berstempel `paket_acuan_versi_id` v1 dan JSON; Dial Indicator tidak berubah | 4 | ST-T-099 | PA, PJA | `MicrometerCalculator.php:55,164` |
| ST-T-103 | Bayangan pilot di produksi: daftarkan 7 kandidat v1 sebagai bayangan; PHP tetap menghitung | catatan rilis + laporan bayangan | deploy atas perintah PL; nol beda sampai syarat Q-49-03 terpenuhi; simpan teknisi tidak melambat (ukur) | 1 (+ waktu kalender) | ST-T-100–102, Q-49-03 | PA, PL | FR-STU-119(a), ADR-49-04 |
| ST-T-104 | Paritas final (sesi tersimpan + vektor + laporan bayangan) + pemeriksaan independen + tanda tangan Lab + ajukan/sahkan pilot → aktif dengan pengawasan PHP | laporan `simulasi_versi.pemeriksa`, catatan rilis | PJA menghitung ulang dari master (bukan dari test pembangun); orang Lab tercatat (Q-49-02); SA orang kedua; `berlaku_mulai` tidak mundur | 2 | ST-T-103, Q-49-02 | PJA, LAB, SA, PL | FR-STU-118, 119(b), ADR-49-04 |
| ST-T-106 | Blok agregasi GUM bawaan di templat Lembar Hitung (uc, penyebut WS bersyarat, veff + `MAKS_V_EFF`, k / `k_tetap` / 2, U, U95 berlantai, keputusan) + validator: keluaran `agregat` wajib terikat; impor `.xlsm` tidak mengganti blok bawaan | `app/Services/Rumus/TemplatLembarHitung.php`, `ValidatorLembarHitung.php`, `tests/Unit/Rumus/BlokAgregasiBawaanTest.php` | blok bawaan diadu ke `GumCalculator::agregasiBudget()` + lantai + keputusan **per sel** atas set komponen acak terkendali (termasuk `vi ≤ 0`, `ui·ci = 0`, penyebut nol, veff < 1, veff sangat besar, `k_tetap`, CMC kosong, toleransi kosong/≤ 0): identik bit atau dalam toleransi PRD §5 — keputusan pemakaian `TINV` vs `SIDIK.TKUANTIL` dicatat dari hasil ini dan spike S0a | 3 | ST-T-090, 091, ST-T-003 | PA, PJA | K-49-07, ADR-49-08, FR-STU-134(a), SDD §6.2 |
| ST-T-107 | Pengawas `GumCalculator` di setiap evaluasi lembar + temuan validator `agregasi_beda_dari_gum` (PERINGATAN, tidak menahan terbit) di jejak sesi + `pengawas_gum` di pratinjau + `agregasi_beda_gum` di simulasi/paritas + `422 agregasi_beda_tanpa_catatan` saat ajukan + pintu publik `GumCalculator::keputusan()` tanpa ubah perilaku + hitungan di `/api/health` | `app/Services/Rumus/PengawasAgregasi.php`, `EvaluatorLembarHitung.php`, `app/Services/CalibrationValidator.php`, `app/Services/GumCalculator.php`, `LayananLembarHitung.php`, `docs/kontrak-api.md` | SK-26; test `GumCalculator` lama hijau tanpa diubah; temuan memuat kunci, nilai lembar, nilai pengawas, selisih, kode catatan; tidak ada sakelar/env/rute yang mematikan pengawas (test sapu); SQLite + MySQL | 3 | ST-T-095, 097, 099 | PA, PJA | K-49-07, FR-STU-134(b), 135, SDD §7.5 |
| ST-T-105 | Tinjau S4 lintas batas (pratinjau vs simpan satu fungsi; izin; audit; sandbox) + dua suite | laporan | 0 kritis/tinggi; dua suite hijau | 2 | ST-T-090–107 | PN, PA | NFR-STU-05 |

**Subtotal S4: 62 hari-orang** (belum termasuk lama kalender tahap bayangan).

## S5 — Editor rumus Studio

| ID | Tugas | Berkas dibuat / diubah | DoD khusus | Est. | Dep. | Owner | Link |
|---|---|---|---|---|---|---|---|
| ST-T-130 | Grid mode Lembar Hitung: alamat A1, kepala baris/kolom, jenis sel (terikat/tampilan/galat/berubah/keluaran), tab sheet, zoom, tampilkan rumus | `studio/lib/widget/grid/*`, `studio/lib/layar/lembar_hitung/editor_screen.dart` | 200 × 30 sel berumus ≥ 55 fps; golden | 5 | ST-T-015, S4 API | PM | ST-31, NFR-STU-04 |
| ST-T-131 | Bilah rumus: nama kotak, sunting, pelengkapan fungsi daftar putih + nama, petunjuk argumen, pewarnaan rujukan, `F4` | `studio/lib/layar/lembar_hitung/panel/bilah_rumus.dart` | daftar fungsi dibaca dari server (tidak ditulis tangan di klien) | 4 | ST-T-130 | PM | FR-STU-105, 111 |
| ST-T-132 | Operasi struktur: salin/tempel rumus, isi bawah/kanan, sisip/hapus baris & kolom — penggeseran rujukan dikerjakan **server** (`POST …/struktur`) | server: `LayananLembarHitung` (+ test); klien: editor | hasil geser rujukan sama dengan Excel untuk kasus uji (relatif, absolut, campuran, lintas sheet, nama) | 6 | ST-T-097, 130 | PA, PM | FR-STU-111 |
| ST-T-133 | Inspektur sel + panel nama (ST-32) + jejak ketergantungan (ST-33) | `studio/lib/layar/lembar_hitung/panel/*` | navigasi `Ctrl+[`/`Ctrl+]` | 4 | ST-T-098, 130 | PM | FR-STU-114 |
| ST-T-134 | Panel pengikat (ST-34) | `panel/pengikat.dart` | kontrak input dari server; galat `kontrak_input_dilanggar` tampil per baris | 3 | ST-T-130 | PM | FR-STU-102, 103, 186 |
| ST-T-135 | Pratinjau (ST-35), banding (ST-36), laporan paritas (ST-37), log metode (ST-38); tampilkan pengawas `GumCalculator` (blok `pengawas_gum`, kolom `agregasi_beda_gum` di simulasi, PERINGATAN `agregasi_beda_dari_gum` di jejak sesi) | `studio/lib/layar/lembar_hitung/*`, `studio/lib/layar/simulasi/*` | tiga kolom tersimpan/PHP/draf + baris pengawas; beda angka cetak merah; beda agregat dari pengawas amber dengan kode catatannya | 5 | ST-T-098, 099, 107 | PM | ST-35–38, ST-40, FR-STU-134 |
| ST-T-136 | Simulasi, ajukan, pengesahan untuk Lembar Hitung (pakai ST-40/24/50/51 dari S3; tambah sumber metode & paritas) | `studio/lib/layar/simulasi/*`, `pengesahan/*` | ajukan menolak tanpa sumber metode (klien & server) | 1,5 | ST-T-067, 097 | PM | FR-STU-121, 122 |
| ST-T-137 | Impor `.xlsm` server: pembaca (dekripsi per hasil S0c), laporan temuan, hitung ulang pembanding, usulan pengikat, draf; job; `unggahan_workbook` | `app/Services/Rumus/PembacaWorkbook.php`, `app/Http/Controllers/Api/Studio/ImporWorkbookController.php`, `app/Jobs/ImporWorkbook.php` | SK-23 (sandi tidak di `audit_logs`, `jobs`, `failed_jobs`, log, respons), SK-24 (data pelanggan tidak terbaca); bom zip ditolak | 5 | ST-T-006, 093 | PA | FR-STU-160–168, ADR-49-10 |
| ST-T-138 | Impor UI ST-60/61 | `studio/lib/layar/impor/*` | sandi tidak disimpan di klien (state dibuang sesudah kirim) | 2 | ST-T-137 | PM | ST-60, 61 |
| ST-T-139 | Interaksi sentuh tablet di editor (menu tekan lama, pegangan seleksi, bilah tombol papan ketik) sesuai jawaban Q-49-08 | `studio/lib/layar/lembar_hitung/*` | uji di tablet uji | 3 | ST-T-130, Q-49-08 | PM | ST-31 |
| ST-T-140 | Versi 2 Lembar Hitung pilot lewat Studio end-to-end (contoh: jawaban V-1..V-4 bila sudah ada) | catatan uji | simulasi dibaca SA; PJA mengadu angka v2 ke master/keputusan Lab | 1,5 | ST-T-131–136 | LAB, SA, PJA | `01-PRD.md` G1 |
| ST-T-141 | Tinjau S5 + golden + uji perangkat | laporan | 0 kritis/tinggi | 2 | ST-T-130–140 | PN, PM | — |

**Subtotal S5: 42 hari-orang.**

## S6 — Migrasi per alat

Setiap alat mengikuti langkah `03-SDD.md` §11 (bahan → tulis v1 → paritas → pemeriksa independen →
bayangan → paritas → pemeriksa independen → ajukan/sahkan → aktif dengan pengawasan). DoD per alat: paritas LULUS 100%; test master alat itu hijau
**tanpa diubah**; PJA independen; matriks `06-Matriks-Paritas-Alat.md` diperbarui. Estimasi per
alat menurut kerumitan: rumus di CSV (13 folder) lebih ringan daripada yang butuh `.xlsm`;
`per_sesi`/multi-varian/larik lebih berat.

| ID | Gelombang | Alat (kode profil) | Est. per alat | Est. total | Dep. | Owner | Link |
|---|---|---|---|---|---|---|---|
| ST-T-160 | W1 Panjang | `dial_indicator`, `jangka_sorong`, `height_gauge`, `sieve` | 4 | 16 | S5, `.xlsm` (Q-49-11) | PA, PJA | Matriks §3 |
| ST-T-161 | W2 Gaya | `utm`, `load_cell`, `proving_ring` | 4 | 12 | S5 | PA, PJA | CSV berumus, larik 16–18 |
| ST-T-162 | W3 Waktu & frekuensi | `timer_stopwatch`, `centrifuge`, `tachometer` | 4 | 12 | S5, `.xlsm` | PA, PJA | — |
| ST-T-163 | W4 Massa | `timbangan` (3 varian), `anak_timbangan` | 7 | 14 | S5, `.xlsm` | PA, PJA | — |
| ST-T-164 | W5 Suhu | `tits`, `tids`, `thermocouple`, `thermometer_glass`, `thermohygro`, `oven`, `furnace`, `bath`, `inkubator`, `refrigerator` | 3–6 (Enclosure lima rumus satu rancangan) | 33 | S5, `.xlsm` | PA, PJA | — |
| ST-T-165 | W6 Aliran | `flowmeter_totalizer`, `flowmeter_flowrate` (varian UFM + gravimetri) | 8 | 16 | S5, `.xlsm` | PA, PJA | — |
| ST-T-166 | W7 Hydrometer | `hydrometer` | 8 | 8 | S5 | PA, PJA | CSV: OFFSET, FORECAST, TINV, IFERROR 38 |
| ST-T-167 | W8 Analitik | `ph_meter`, `turbidimeter`, `chlorine_meter`, `refractometer`, `conductivity_meter`, `spectrophotometer`, `viscometer`, `autoclave`, `do_meter`, `gas_detector` | 4 | 40 | S5, `.xlsm`, ekstraksi konstanta (CR-48 G4) | PA, PJA | — |
| ST-T-168 | W9 Tekanan & Piston | `pressure_gauge`, `vacuum_gauge`, `differential_pressure`, `piston_pipette`, `dispensett`, `buret_digital` | 5 | 30 | S5, ST-T-169 | PA, PJA | manifest `.xlsm` 6 berkas |
| ST-T-169 | Rekonsiliasi log metode tekanan/piston (`versiRumus()`, `periksaVersiRumus()`, `log-metode-tekanan-piston.json`) dengan versi Lembar Hitung | `app/Support/LogMetodeTekananPiston.php`, `CalibrationValidator`, profil Tekanan & Piston | versi kode lama tetap tervalidasi; versi Lembar Hitung punya log sendiri | 3 | S4 | PA, PJA | FR-STU-127 |
| ST-T-170 | Keputusan pemeliharaan kode PHP profil sesudah semua alat aktif (tetap wajib untuk versi `kode` lama) | catatan keputusan di §49 | keputusan tertulis | 0,5 | ST-T-160–168 | PL | ADR-49-04 |

**Subtotal S6: 184,5 hari-orang** (181 per alat + 3 rekonsiliasi + 0,5 keputusan).

## Ringkasan

| Tahap | Hari-orang | Catatan kalender |
|---|---|---|
| S0 | 11,75 | sebagian sudah berjalan paralel (S0a, S0b) |
| S1 | 17,5 | PM; bisa paralel dengan S2 server |
| S1b | 13,5 | dimajukan K-49-09; sisi API selesai 13 Okt, mobile berjalan |
| S2 | 26,5 | PA ±16, PM ±9, sisanya tinjau/Lab |
| S3 | 18,5 | PA ±11,5, PM ±7 |
| S4 | 62 | jalur kritis; PA + PJA; tahap bayangan memakan waktu kalender (Q-49-03); +6 karena K-49-07 (ST-T-106, 107) |
| S5 | 42 | PM ±31, PA ±8 |
| **S0–S5** | **≈ 178** (+13,5 S1b = ≈ 191,5) | dengan PA dan PM paralel + tinjau: ±4–5 bulan kalender, belum termasuk menunggu jawaban Lab dan suite MySQL |
| S6 | ≈ 184,5 | 41 profil sisanya (48 profil − 7 profil pilot S4); bergantung penyerahan `.xlsm` (39 dari 52 folder CSV tanpa rumus) |

Jalur kritis: ST-T-003 → 004 → 090 → 091 → 106 → 093/094 → 095 → 096 → 099 → 107 → 100 → 103 → 104. Tanpa
jawaban Q-49-01 (pembuat draf) rute tulis S4/S5 tetap bisa dibangun dan diuji, tetapi tidak boleh
dibuka di produksi. Tanpa Q-49-02/03 versi pilot tidak bisa keluar dari tahap paritas/bayangan.
