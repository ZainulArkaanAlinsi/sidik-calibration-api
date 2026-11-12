# Runbook — impor awal Data Acuan di produksi (`acuan:impor-awal`)

> Disiapkan 12 Nov 2026 di atas `origin/main` `006f1b68` + penambahan `--dry-run`, ringkasan, dan
> pemeriksa sha256 independen.
> **STATUS: impor nyata BELUM dijalankan di produksi.** Yang sudah dikerjakan 12 Nov ±10.48 hanya
> membaca atau membuat berkas lokal: sensus baca-saja, dry-run baca-saja, dan cadangan (§0).
> Percobaan menulis (§3) dilakukan di SQLite berkas sementara — bukan MySQL kerja, bukan produksi.
> Menulis ke produksi butuh instruksi eksplisit pemilik yang menyebut tabel dan jumlah baris
> (`docs/aturan-akses-database.md` butir 1, 2, 4) — kalimatnya ada di §6.
>
> Rancangan induk: `docs/studio-data-acuan/07-Migrasi-Rollout.md` §1, §2 tahap 2, §2a.

## 0. Keadaan produksi yang sudah dibaca (12 Nov 2026 ±10.48, baca-saja)

| Tabel | Baris | Catatan |
|---|---:|---|
| `organizations` | 1 | N = 1 |
| `paket_acuan` | 0 | kosong, sesuai harapan |
| `paket_acuan_versi` | 0 | kosong |
| `paket_acuan_log` | 0 | |
| `paket_acuan_suntingan` | 0 | |
| `simulasi_acuan` | 0 | |
| `calibration_sessions` | 25 | |
| `certificates` | 17 | |
| `uncertainty_calculations` | 74 | ber-stempel acuan: 0 (sumber `json`, stempel memang NULL) |
| `audit_logs` | 592 | pembanding; naik juga oleh aktivitas pengguna — adu dengan hitungan berfilter (§4 langkah 10) |

**Dry-run produksi (`acuan:impor-awal --dry-run`, dari mesin pemilik, baca-saja):** organisasi
1, paket per organisasi 25, paket baru 25, versi 1 baru 25, sudah ada 0, **galat 0**, baris AKAN
ditulis `paket_acuan 25, paket_acuan_versi 25, audit_logs 50`. sha256 25/25 cocok dengan
`docs/skrip/cek-sha-impor-acuan.py` (keluaran ber-BOM UTF-16LE terbaca). `berlaku_mulai` yang
dilaporkan: 19 paket `2024-01-01` (tanpa sesi), 6 paket mengikuti sesi tertuanya —
`autoclave 2026-11-09`, `enclosure 2026-09-28`, `suhu_3alat 2026-10-06`,
`timbangan 2026-10-15`, `anak_timbangan 2026-10-05`, `volumetric 2026-10-06`. Itulah daftar yang
harus muncul lagi di log deploy.

**Cadangan:** `C:\cadangan-sidik\produksi-20261112-104824.sql`, 16.515.725 byte, sha256
`5e855f5d1cab8d1962195b506668195019d246f7d04bc85ed0782773c7cb8e8a`. Jumlah baris DI DALAM
berkas (dihitung `docs/skrip/hitung-baris-cadangan.py`) cocok dengan sensus di atas.
**Pemulihan ke salinan lokal BELUM diuji** — lihat batasannya di §4 langkah 1.

## 1. Apa yang diimpor — fakta, bukan perkiraan

| Pertanyaan | Jawaban | Bukti |
|---|---|---|
| Sumber data | 25 berkas `database/data/*.json` (tabel di §2), satu per paket acuan, semua paket yang punya `skemaAcuan()` | `CalibrationProfileRegistry::paketAcuan()`; `SkemaAcuanProfilTest::BERSKEMA` |
| Dikerjakan per | paket × organisasi (semua organisasi yang belum soft delete), **25 × N** kombinasi | `ImporAwalAcuan::handle()` |
| Tabel yang ditulis | `paket_acuan`, `paket_acuan_versi`, **`audit_logs`** — hanya INSERT | percobaan lokal §3; kedua model memakai `Diaudit` |
| Baris per paket baru | 1 `paket_acuan` + 1 `paket_acuan_versi` + **2** `audit_logs` (satu `dibikin` per model) | percobaan lokal §3 |
| Tabel yang TIDAK disentuh **oleh `acuan:impor-awal`** | `paket_acuan_log`, `paket_acuan_suntingan`, `simulasi_acuan`, `uncertainty_calculations`, `calibration_sessions`, `certificates`, apa pun lainnya. Aplikasi yang tetap berjalan (pengguna, sesi, sertifikat) boleh menulis ke tabel-tabel itu dan ke `audit_logs` pada saat yang sama; itu bukan dari impor | selisih baris per tabel §3; `test_angka_dry_run_sama_dengan_baris_yang_tertulis_jalan_sungguhan` |
| Isi versi 1 | `jenis=data`, `nomor_versi=1`, `status=aktif`, `isi` = JSON berkas yang dikanonikkan (`JsonKanonik`), `isi_sha256` = sha256 byte itu, `rujukan` = berkas + sha | `ImporAwalAcuan::imporSatu()` |
| Disahkan siapa | tidak ada: `disahkan_oleh/pada` dan `dibuat_oleh` NULL — impor bukan pengesahan | diperiksa langsung di tabel hasil (§3) |
| `berlaku_mulai` | tanggal kalibrasi sesi tertua organisasi yang alatnya dihitung profil itu, atau `2024-01-01` | 07 §3 |
| Idempoten | **ya**. Paket yang sudah punya versi `data` tidak ditulis; versi 1-nya hanya diadu sha256 | run kedua §3: 0 baris baru |
| Menimpa yang sudah ada | **tidak pernah**. Versi 1 yang beda → galat (kode keluar 1) menyebut kedua sha256; hash tersimpan yang rusak → galat; paket soft delete → galat; semuanya tanpa tulis | `ImporAwalAcuanTest` (10 tes lama + 5 baru) |
| Mengubah angka sertifikat / hitung | **tidak**, selama `DATA_ACUAN_SUMBER=json` (dipatok di `render.yaml`): `SumberAcuan::isi()` memakai versi DB hanya bila sumbernya `db`, `PenentuVersiAcuan::jalankanDengan()` memulangkan hasil tanpa versi, dan lembar-kerja tidak memuat `versi_acuan`. Yang berubah hanya apa yang terlihat di layar Data Acuan Studio | `SumberAcuan.php:125`, `PenentuVersiAcuan.php:227,283`, `CalibrationController.php:415` |
| Akibat tak langsung | `GET /api/data-acuan/paket` berhenti menjawab "belum diimpor" untuk 25 paket; draf Studio dari master punya paket untuk dirujuk (`DrafAcuanMaster` sengaja tidak membuat paket sendiri) | `DataAcuanController.php:55-85`, `DrafAcuanMaster.php:33` |

**Risiko menimpa data produksi yang sudah ada: tidak ada jalur menimpa di kode.** Kasus yang bisa
terjadi, dan hasilnya:

| Keadaan produksi | Hasil |
|---|---|
| Tabel kosong (keadaan 12 Nov, §0) | semua 25 × N dibuat |
| Paket ada, versi `data` belum ada | hanya versi 1 dibuat (+1 `paket_acuan_versi`, +1 `audit_logs`) |
| Versi 1 ada dan sama | dilewati ("sudah ada, sama") |
| Versi 1 ada tapi isinya beda (mis. draf Studio yang sudah disunting) | **tidak ditimpa**, galat, kode keluar 1; boot tetap lanjut (`\|\| true`) |
| Paket soft delete | tidak diimpor, galat; **tidak dipulihkan otomatis** |
| Dua proses bersamaan | satu transaksi per paket + `lockForUpdate` + unique `(paket_acuan_id, jenis, nomor_versi)` |

## 2. Daftar 25 paket dan sha256 kanonik

Dihitung pada `006f1b68`. **Dua jalur** menghasilkan angka yang sama persis untuk 25/25 paket:
`JsonKanonik` (PHP, yang dipakai impor) dan `docs/skrip/cek-sha-impor-acuan.py` (Python
independen, tanpa Laravel dan tanpa database). Micrometer cocok dengan angka yang tercatat di
`docs/permintaan-user-7.md` §48 (`ba1fa153…f56f585`).

| # | `kode_profil` | Berkas | sha256 kanonik |
|---|---|---|---|
| 1 | `chlorine_meter` | `tabel-standar-chlorine-meter.json` | `8806ea79905d1a36ab436e32f862bac48431c371c23ec701f7748f1886de11e8` |
| 2 | `refractometer` | `tabel-standar-refractometer.json` | `24f963da9265e9545af100e7a1c6c5589fd6d6bcba83f51bd3c5fa8093b4a6a2` |
| 3 | `spectrophotometer` | `tabel-standar-spectrophotometer.json` | `7819eaaced7e59245b24a1cc113683dec5581d17bac71753405f29bf7528c833` |
| 4 | `viscometer` | `tabel-standar-viscometer.json` | `6e6353379ef872a14fe2f8740add1311fb574dbaaff31c045ca30dd6bb058169` |
| 5 | `autoclave` | `tabel-standar-autoclave.json` | `29e29aaa01a94b6767020efd48702013638b958d3285008a0287220431786175` |
| 6 | `do_meter` | `tabel-standar-do-meter.json` | `f108125120ae89077a2fdd0ab50b847d1677e9e7d3c4f55e954356f6ef74f771` |
| 7 | `kalibrator_suhu` | `tabel-kalibrator-suhu.json` | `fcf5aade7704188caec5b8954f3d1276588a62d9cbe30a0ac4e086673898a71c` |
| 8 | `tids` | `tabel-standar-tids.json` | `7b1e5e5617756078379a1c072585889d309ff4e9fc5d60ddec2ff27da40adc7c` |
| 9 | `enclosure` | `tabel-kalibrator-enclosure.json` | `6a3438229621f60ce1a2c68ca03936f64a84244f3119ca35afd65fc5a0a8d935` |
| 10 | `suhu_3alat` | `tabel-master-suhu-3alat.json` | `d1fa894d26f0f1db250ff533a1a85cfc950755c700f6e748e56d51d8c8b6abd2` |
| 11 | `timbangan` | `tabel-standar-timbangan.json` | `987858c4733cf828a8ce9c649a777f15fda143c7c328377e22c877d62fb8e421` |
| 12 | `timer_stopwatch` | `tabel-standar-waktu.json` | `e8ba4bdb174a0b37a248aff9acc203b1b82d5ac2d43dff91084092618d5692e6` |
| 13 | `putaran` | `tabel-standar-putaran.json` | `f494bc220a04e4e228f6ffe93192b1ed2133ae3ff97ae22941e7f49429d41526` |
| 14 | `micrometer` | `tabel-standar-micrometer.json` | `ba1fa153808d125a2ff1e54ca3589e25c723b59ccb23a68df7372862ef56f585` |
| 15 | `height_gauge` | `tabel-standar-height-gauge.json` | `d2596a8ad2d737c3573313138324de39fd434ffc7cd94773f8e29e65a25216eb` |
| 16 | `flowmeter` | `tabel-standar-flowmeter.json` | `6c9f8818230a5d5517a79e7d3967dc60d7a38c3bfea6b94a804ab1b4f260e01e` |
| 17 | `anak_timbangan` | `tabel-standar-anak-timbangan.json` | `5338327015123645b72765dfb23cf426d3285b70d3dfc810c3f1d162a04b60b0` |
| 18 | `dial_indicator` | `tabel-standar-dial-indicator.json` | `e87323fecb8c43c6d3b38e6d5ff7920a09c46852b58cc67e3a4734cafa90a157` |
| 19 | `jangka_sorong` | `tabel-standar-jangka-sorong.json` | `a6837d6384b39c2c8dd4cfa2799d00b8500345b9b0c9616b7b8c0a5406780e19` |
| 20 | `sieve` | `tabel-standar-sieve.json` | `a27b3c38f7442fb4147137818073ef9f143d65da913369c2f819eb1209d7dbf9` |
| 21 | `hydrometer` | `tabel-standar-hydrometer.json` | `85ab45a0a7c68571107f6f37554911598aa917bb29c07c90d42f440e8738e400` |
| 22 | `volumetric` | `tabel-standar-volumetric.json` | `3e12b9df8ba7be035d953f410b31f287e916eda4b41247ebe1e3c532004f8c17` |
| 23 | `gaya` | `tabel-standar-gaya.json` | `5146f38e2e4b551174a9ba64a77dff7489122d46a69e10d070189835974a4379` |
| 24 | `tekanan` | `tabel-standar-tekanan.json` | `4a91bb6aedfd7c05048314dae736d8c797ca4ad50017eeac1abe4c962f9cff6e` |
| 25 | `piston_volume` | `tabel-standar-piston-volume.json` | `48ee042d3e1a54ff3895d24a6dfac3f5c277684137ba72dd5b60880facdbdacc` |

Bila JSON di repo berubah antara dokumen ini dan hari rilis, tabel di atas basi — yang mengikat
adalah keluaran dry-run di hari rilis diadu ke repo lewat skrip Python (§4 langkah 5).

## 3. Hasil percobaan lokal (SQLite berkas sementara)

Lingkungan: PHP 8.4.25, Laravel 13.19.0, `origin/main` `006f1b68` + perubahan perintah ini.
Database: berkas SQLite di folder sementara sesi, di-migrasi penuh (`migrate --force`), **1
organisasi**, 0 sesi. `APP_KEY` acak lewat env, tanpa `.env`, `DB_DATABASE` dicek lewat
`config:show` menunjuk berkas itu. Tidak ada koneksi ke MySQL mana pun.

### Jumlah baris per tabel

| Tabel | Sebelum | Sesudah dry-run | Sesudah impor nyata | Sesudah impor ke-2 | Selisih |
|---|---:|---:|---:|---:|---:|
| `organizations` | 1 | 1 | 1 | 1 | 0 |
| `paket_acuan` | 0 | 0 | **25** | 25 | **+25** |
| `paket_acuan_versi` | 0 | 0 | **25** | 25 | **+25** |
| `audit_logs` | 1 | 1 | **51** | 51 | **+50** (semua `entity_type` `paket_acuan` / `paket_acuan_versi`, `action` `dibikin`, `changed_by` NULL) |
| `paket_acuan_suntingan` | 0 | 0 | 0 | 0 | 0 |
| `paket_acuan_log` | 0 | 0 | 0 | 0 | 0 |
| `simulasi_acuan` | 0 | 0 | 0 | 0 | 0 |
| `uncertainty_calculations` | 0 | 0 | 0 | 0 | 0 |
| `calibration_sessions` | 0 | 0 | 0 | 0 | 0 |

### Hasil lain

- **Dry-run:** kode keluar 0, ±7 detik, nol baris berubah, keluaran menyebut
  `baris AKAN ditulis: paket_acuan 25, paket_acuan_versi 25, audit_logs 50`. Tes
  `test_dry_run_tidak_menulis_apa_pun_…` menambah bukti: nol pernyataan
  INSERT/UPDATE/DELETE/DDL dan nol transaksi dibuka (kontrolnya: jalan nyata memang membukanya).
- **Impor nyata:** kode keluar 0, ±6 detik untuk 25 paket. `RINGKASAN` mencetak
  `baris ditulis: paket_acuan 25, paket_acuan_versi 25, audit_logs 50` — sama dengan dry-run —
  dan baris `database : <driver> / <nama database>` (tanpa host, user, atau sandi) supaya jelas
  database mana yang dituju.
- **Pernyataan SQL yang dikirim** (dihitung lewat `DB::listen`): impor nyata = 25 `INSERT
  paket_acuan` + 25 `INSERT paket_acuan_versi` + 50 `INSERT audit_logs` + 54 SELECT, **nol
  UPDATE, nol DELETE**; dry-run = hanya SELECT (54 pada database kosong, 79 pada database
  yang sudah terisi).
- **Run kedua:** kode keluar 0, 25 × `versi 1 sudah ada, sama`, **0 baris baru** di tabel mana pun
  (idempoten). Dry-run sesudahnya melaporkan `baris AKAN ditulis: … 0, … 0, … 0`.
- **Versi hasil:** 25 baris `jenis=data`, `nomor_versi=1`, `status=aktif`, `disahkan_oleh` NULL,
  `dibuat_oleh` NULL; `isi` total **240.466 karakter = 240.507 byte UTF-8** (terbesar `tids`
  38.594 byte); 25 sha256 berbeda.
- **Audit tidak menyalin `isi`:** 0 baris `audit_logs` memuat kunci `isi` (hanya `isi_sha256`).
- **sha256:** 25/25 cocok dengan penghitung Python independen (§2).
- **Latihan mundur (§7 jalur B):** syarat 6 pemeriksaan = 0; hapus versi lalu paket → 25 + 25
  baris; urutan terbalik ditolak FK; sesudahnya impor ulang menghasilkan 25/25 lagi.
- **Tidak diuji:** MySQL. Selisih SQLite/MySQL yang relevan (kunci baris `FOR UPDATE`, tipe
  `date`, `LONGTEXT`) belum dibuktikan di sini; gerbang suite MySQL penuh tetap wajib sebelum
  modul disebut selesai. Waktu di MySQL jarak jauh (Aiven) belum terukur; perkiraan kasar:
  ±300 round-trip untuk 25 paket, jauh di bawah jendela health check Render 15 menit.

## 4. Urutan eksekusi (tiap langkah menunggu yang sebelumnya)

Syarat tetap (`07` §2a): tahap 1 (migrasi) sudah live dan `GET /api/health` memulangkan
`deploy.versi` yang cocok; `DATA_ACUAN_SUMBER` masih `json`; tidak ada deploy lain yang
menggantung (satu push, satu deploy). Akibatnya ada **tiga deploy berurutan**, masing-masing
menunggu yang sebelumnya terverifikasi: (0) PR kode+dokumen ini, (1) commit penyalaan,
(2) commit pengembalian.

### A. Persiapan — hanya membaca atau membuat berkas lokal, TIDAK butuh kalimat izin tulis

0. **PR ini HARUS sudah merge dan ter-deploy sebelum commit 1.** Alasannya dua:
   (a) log deploy produksi hanya memuat blok `RINGKASAN` (angka yang diadu ke kalimat izin) dan
   baris `database` kalau image yang jalan sudah memuat perubahan `ImporAwalAcuan` ini; tanpa itu
   log cuma memuat baris per paket dan tidak ada hitungan baris; (b) commit 1 melonggarkan satu tes
   (langkah 6) yang harus mendarat di atas `main` yang sama. Verifikasi seperti biasa: `gh run
   list` hijau dan `/api/health` → `deploy.versi` cocok.
1. **Cadangan — prosedur yang BENAR-BENAR dijalankan, bukan yang dibayangkan 07 §1.1.**
   Dikerjakan 12 Nov oleh `siapkan-impor-acuan-produksi.ps1` (pembantu lokal di mesin pemilik,
   TIDAK masuk repo; hanya membaca produksi dan menulis berkas lokal):
   - `mysqldump` ke `C:\cadangan-sidik\produksi-<stempel>.sql`, sha256 dicatat (data §0);
   - jumlah baris DI DALAM berkas dihitung `python -I docs/skrip/hitung-baris-cadangan.py
     <berkas.sql> organizations,paket_acuan,paket_acuan_versi,...` lalu diadu ke sensus (§0).
     Hasil 12 Nov: cocok.
   - **Belum dilakukan: memulihkan berkas ke salinan lokal.** 07 §1.1 mewajibkannya ("diverifikasi
     bisa dipulihkan di mesin lokal"). Yang terbukti sekarang hanya: berkasnya ada, utuh
     (sha256), dan memuat jumlah baris yang sama dengan produksi. Itu bukti bahwa datanya
     tersimpan, bukan bahwa dump-nya bisa dimuat ulang. Pemilik memutuskan: menerima batas ini,
     atau menjalankan pemulihan uji (impor ke database lokal sementara, hitung ulang) sebelum
     kalimat izin. Berkas privat `storage` tidak termasuk cadangan ini.
2. **Sensus baca-saja** — izin baca sekali per sesi (`aturan-akses-database.md` butir 1–2).
   Sebut ke pemilik sebelum jalan: *baca* (SELECT) tabel `organizations`, `paket_acuan`,
   `paket_acuan_versi`, `paket_acuan_suntingan`, `paket_acuan_log`, `simulasi_acuan`,
   `uncertainty_calculations`, `calibration_sessions`, `certificates`, `audit_logs`. Hanya
   angka, tidak ada nama atau isi baris. Ulangi tepat sebelum commit 1 bila sudah lewat satu hari
   atau ada deploy lain sejak §0.

   ```powershell
   php artisan db:show --counts --no-ansi        # jumlah baris SEMUA tabel (baca saja)
   php artisan tinker --execute="echo DB::table('organizations')->whereNull('deleted_at')->count();"
   php artisan tinker --execute="echo DB::table('uncertainty_calculations')->whereNotNull('paket_acuan_versi_id')->count();"
   php artisan tinker --execute="echo DB::table('calibration_sessions')->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n','status')->toJson();"
   ```

   Harapan (terpenuhi 12 Nov, §0): `organizations` (belum soft delete) = N yang tertulis di kalimat
   izin; `paket_acuan`, `paket_acuan_versi`, `paket_acuan_log`, `paket_acuan_suntingan`,
   `simulasi_acuan` = 0; `uncertainty_calculations` ber-stempel = 0. **Catat
   `audit_logs` total** — pembanding langkah 10. Bila `paket_acuan` atau `paket_acuan_versi`
   TIDAK kosong: berhenti dan laporkan; angka di §6 tidak berlaku lagi.
3. **Dry-run baca-saja terhadap produksi** (izin baca yang sama; tabel yang dibaca:
   `organizations`, `paket_acuan`, `paket_acuan_versi`, `equipments`, `calibration_sessions`,
   dan `information_schema` untuk memastikan tabel ada). Dari mesin kerja pemilik, dengan
   `.env` produksi. **Simpan keluaran dengan `Out-File -Encoding utf8`**: `Tee-Object` dan `>`
   di PowerShell 5.1 menulis UTF-16LE ber-BOM, dan `Out-File -Encoding utf8` menulis UTF-8
   ber-BOM; `cek-sha-impor-acuan.py` membaca byte dan mendeteksi BOM keduanya (tanpa itu,
   keluaran UTF-16 terbaca sebagai nol blok).

   ```powershell
   $env:CACHE_STORE='array'; $env:SESSION_DRIVER='array'; $env:QUEUE_CONNECTION='sync'
   php artisan acuan:impor-awal --dry-run --no-ansi 2>&1 | Out-File -Encoding utf8 -FilePath acuan-dry-run-produksi.txt
   ```

   `--dry-run` tidak membuka transaksi dan tidak mengirim INSERT/UPDATE/DELETE (dijaga tes).
   Syarat lulus — **semuanya**, bukan hanya tiga baris pertama:
   - kode keluar 0 dan `galat (tidak ditimpa) : 0`;
   - `paket baru : 25N`, `versi 1 baru : 25N`, `baris AKAN ditulis: paket_acuan 25N,
     paket_acuan_versi 25N, audit_logs 50N`;
   - baris `database : <driver> / <nama>` menunjuk database produksi yang dimaksud;
   - **`berlaku_mulai` tiap paket masuk akal:** `2024-01-01` bila organisasi belum punya sesi
     profil itu, atau tanggal kalibrasi sesi tertuanya — tidak ada tanggal kosong atau di masa
     depan. Per 12 Nov: 19 paket `2024-01-01` dan 6 paket bertanggal (daftar di §0); selisih dari
     daftar itu harus dijelaskan oleh sesi yang masuk sesudahnya, bukan diabaikan.

   Angka lain = berhenti. *Alternatif tanpa menyentuh produksi sama sekali:* jalankan perintah
   yang sama pada salinan hasil pemulihan cadangan — sekaligus menutup celah "pemulihan belum
   diuji" di langkah 1.
4. Konfirmasi `render.yaml` masih `ACUAN_IMPOR_AWAL_ON_BOOT` = `"false"` dan
   `DATA_ACUAN_SUMBER` = `json`.
5. **Cocokkan sha256 dengan repo secara independen** (argumen ketiga = jumlah paket yang wajib
   terbaca; skrip gagal bila kurang, jadi log terpotong tidak lolos):

   ```powershell
   python -I docs/skrip/cek-sha-impor-acuan.py . acuan-dry-run-produksi.txt 25
   ```

   Harus `HASIL: SEMUA COCOK (25 dari 25 paket diharapkan, …)`, kode keluar 0. Hanya berkas di
   bawah `database/data/` yang dibaca. Tes skripnya: `python -I
   docs/skrip/test_cek_sha_impor_acuan.py`.

### B. Menyalakan — HANYA setelah pemilik mengetik kalimat izin §6

**Temuan penting — commit 1 tidak lolos CI apa adanya.** `tests/Feature/EntrypointBootTest.php`
baris 188–192 memaksa `render.yaml` memuat `value: "false"` untuk `ACUAN_IMPOR_AWAL_ON_BOOT`
(`/-\s*key:\s*ACUAN_IMPOR_AWAL_ON_BOOT\s*\n\s*value:\s*"false"/`). Dengan `"true"` tes itu merah,
`tes.yml` berhenti, dan Deploy Hook tidak pernah diketuk. Diverifikasi 12 Nov: regex ketat
cocok dengan `"false"` dan tidak dengan `"true"`; tidak ada tes lain yang memeriksa nilainya
(`BlueprintDanEnvExampleSejalanTest` hanya memeriksa keberadaan kunci). Rencananya: **tes itu
dilonggarkan SECARA TERARAH di commit 1 dan dikembalikan ketat di commit 2.** Kedua perubahan
tes harus disebut di pesan commit dan deskripsi PR masing-masing.

6. **Commit 1** (satu perubahan, satu deploy), dua berkas:
   - `render.yaml`, blok `ACUAN_IMPOR_AWAL_ON_BOOT`: `value: "false"` → `value: "true"`;
   - `tests/Feature/EntrypointBootTest.php`, asersi `render.yaml` di akhir
     `test_impor_awal_acuan_digerbangi_tidak_menjatuhkan_boot_dan_bawaannya_mati` diganti:

     ```php
        // SEMENTARA, hanya untuk commit penyalaan impor awal acuan (docs/runbook-impor-acuan-produksi.md
        // §4B). "true" sah di render.yaml SELAMA SATU deploy; commit pengembalian MENGEMBALIKAN
        // asersi ini ke ketat `value: "false"`. Yang tetap dijaga: bentuk `value:` (bukan `sync: false`)
        // dan nilainya hanya "true" atau "false".
        $this->assertMatchesRegularExpression(
            '/-\s*key:\s*ACUAN_IMPOR_AWAL_ON_BOOT\s*\n\s*value:\s*"(?:true|false)"/',
            (string) file_get_contents(base_path('render.yaml')),
            'render.yaml harus mematok ACUAN_IMPOR_AWAL_ON_BOOT `value: "true"` atau `"false"` (bukan sync: false).',
        );
     ```

     (`.env.example` tetap `false`; asersinya di atasnya tidak diubah.)
     Regex longgar sudah dicoba terhadap `render.yaml` yang diganti `"true"`: lolos, dan menolak
     `sync: false` serta nilai lain.
   Pesan commit/PR menyalin kalimat izin §6, nama + sha256 cadangan (§0), dan kalimat "tes
   EntrypointBootTest dilonggarkan sementara; dikembalikan ketat di commit berikutnya". Lewat PR →
   merge → CI hijau → Deploy Hook. Jangan menumpuk perubahan lain.
7. **Baca log deploy** (Render MCP baca-saja atau dashboard → Logs). Cari
   `→ impor awal data acuan`. Simpan potongan log ke berkas teks, lalu:
   - 25 × N baris `versi 1 dibuat (aktif, berlaku_mulai …), sha256 …`, dengan `berlaku_mulai`
     sama dengan daftar dry-run (§0);
   - blok `RINGKASAN` sama persis dengan kalimat izin: `paket baru 25N`, `versi 1 baru 25N`,
     `galat 0`, `baris ditulis: paket_acuan 25N, paket_acuan_versi 25N, audit_logs 50N`, dan baris
     `database` menunjuk produksi;
   - `acuan:impor-awal selesai tanpa galat. Kembalikan ACUAN_IMPOR_AWAL_ON_BOOT ke false.`;
   - `python -I docs/skrip/cek-sha-impor-acuan.py . potongan-log.txt 25` → `SEMUA COCOK`.
8. Tunggu deploy 1 terverifikasi (`gh run list` hijau **dan** `/api/health` → `deploy.versi`
   cocok; `data_acuan.sumber` tetap `json`).
9. **Commit 2:** `render.yaml` `value: "true"` → `value: "false"` **dan** asersi
   `EntrypointBootTest` dikembalikan persis ke bentuk semula:

   ```php
        $this->assertMatchesRegularExpression(
            '/-\s*key:\s*ACUAN_IMPOR_AWAL_ON_BOOT\s*\n\s*value:\s*"false"/',
            (string) file_get_contents(base_path('render.yaml')),
            'render.yaml harus mematok ACUAN_IMPOR_AWAL_ON_BOOT `value: "false"` (bukan sync: false, bukan true).',
        );
   ```

   Commit 2 belum selesai bila tes tidak ikut dikembalikan: asersi longgar yang tertinggal
   membiarkan `"true"` lolos CI tanpa ada yang menyadari. Pesan commit menyebut pengembalian
   keduanya. Satu deploy lagi, diverifikasi sama. Selama masih `true`, tiap boot (termasuk Render
   membangunkan service) menjalankan impor yang isinya "sudah ada, sama" dan mencetak peringatan
   keras — tidak merusak, hanya memakan jendela health check.

### C. Verifikasi sesudahnya — baca-saja

10. Ulangi sensus (langkah 2) dan adu. Aplikasi tetap dipakai selama itu, jadi yang dibandingkan
    adalah yang berasal dari impor, bukan total:
    - `paket_acuan` = 25N dan `paket_acuan_versi` = 25N;
    - `audit_logs` **berfilter** naik tepat 50N:
      `DB::table('audit_logs')->whereIn('entity_type',['paket_acuan','paket_acuan_versi'])->count()`
      (sebelum impor 0). Total `audit_logs` boleh naik lebih banyak oleh aktivitas pengguna;
    - tabel yang tidak ditulis impor (`paket_acuan_log`, `paket_acuan_suntingan`,
      `simulasi_acuan`) tetap 0, dan `uncertainty_calculations` ber-stempel tetap 0. Selisih
      `uncertainty_calculations`/`calibration_sessions`/`certificates` dibandingkan §0 sepenuhnya
      dari aktivitas pengguna; yang dicari hanya baris `paket_acuan_*` yang tak terduga.
    Semua versi `status=aktif`, `nomor_versi=1`, `disahkan_oleh` NULL:

    ```powershell
    php artisan tinker --execute="echo DB::table('paket_acuan_versi')->select('jenis','nomor_versi','status', DB::raw('count(*) n'))->groupBy('jenis','nomor_versi','status')->get()->toJson();"
    ```

11. Dry-run ulang (langkah 3) harus melapor `versi 1 sudah ada, sama : 25N`, `paket baru : 0`,
    `baris AKAN ditulis: … 0, … 0, … 0`.
12. Layar Data Acuan di Studio (akun `super_admin`/Master Data) menampilkan 25 paket per
    organisasi. Angka sertifikat tidak berubah: sumbernya masih `json`.

## 5. Kriteria berhenti

Berhenti dan laporkan ke pemilik — jangan melanjutkan, jangan "membereskan" — bila:

- sensus menemukan `paket_acuan`/`paket_acuan_versi` tidak kosong, atau `organizations` ≠ angka
  di kalimat izin;
- dry-run produksi: kode keluar ≠ 0, `galat` > 0, atau satu angka berbeda dari kalimat izin, atau
  daftar `berlaku_mulai` menyimpang tanpa penjelasan;
- sha256 di keluaran ≠ hitungan Python independen, atau skrip melapor `KURANG`/`DITOLAK`;
- PR kode+dokumen (langkah 0) belum ter-deploy;
- CI commit 1 merah selain karena asersi `EntrypointBootTest` yang memang dilonggarkan;
- deploy 1 tidak lolos health check (cek apakah penyebabnya impor; jangan menyalakan ulang);
- log deploy 1 memuat `galat` > 0 atau `acuan:impor-awal berhenti dengan kode …`: tidak ada
  yang ditimpa, tetapi tahap 3 rollout dilarang sebelum semua paket ber-versi 1.

Kegagalan di tengah impor tidak meninggalkan paket setengah jadi: satu transaksi per paket,
jadi paket sebelumnya sudah utuh dan yang gagal tidak ada. Menjalankan lagi melanjutkan sisanya
karena yang selesai hanya dilaporkan "sudah ada, sama".

## 6. Kalimat izin — pemilik cukup mengetik ini

Angka di bawah memakai **N = 1 organisasi** dan **25 paket**; keduanya sudah cocok dengan sensus
dan dry-run produksi 12 Nov (§0). Bila sensus ulang menemukan N ≠ 1, ganti angkanya menjadi 25N,
25N, 50N, 100N dan jalankan ulang dry-run — angka dry-run produksi harus persis sama dengan
kalimat.

> Saya mengizinkan impor awal data acuan ke database PRODUKSI: INSERT 25 baris `paket_acuan`,
> 25 baris `paket_acuan_versi`, dan 50 baris `audit_logs` (total 100 baris = 25 paket × 1
> organisasi; nol UPDATE, nol DELETE, dan `acuan:impor-awal` tidak menyentuh tabel lain),
> dijalankan lewat satu commit `render.yaml` `ACUAN_IMPOR_AWAL_ON_BOOT` `"true"` + satu deploy
> lalu satu commit pengembalian ke `"false"`, dengan syarat cadangan produksi sudah dibuat dan
> jumlah barisnya cocok dengan sensus, dan dry-run baca-saja produksi
> (`acuan:impor-awal --dry-run`) melaporkan persis angka itu — kalau satu angka saja beda,
> berhenti dan tanya saya.

Kalimat itu mencakup: tabel (3), jumlah baris (25 + 25 + 50), jenis operasi (INSERT saja),
jalur (flag boot), dan syarat berhenti. "Tidak menyentuh tabel lain" berlaku untuk perintah
impornya; aplikasi yang sedang dipakai tetap menulis ke tabel-tabelnya sendiri, dan itu bukan
bagian dari izin. Commit/push/merge/deploy yang dipakai untuk menyalakannya sudah termasuk dalam
"satu commit … satu deploy … satu commit pengembalian", **termasuk perubahan tes
`EntrypointBootTest` di kedua commit itu (§4B)**; selebihnya tidak. PR kode+dokumen langkah 0
adalah deploy terpisah dan tidak menulis ke database produksi.

## 7. Rencana mundur

Urutan dari paling ringan. Jalur A cukup untuk hampir semua keadaan.

**A. Biarkan baris, matikan sakelar (commit 2).** Tidak menghapus apa pun. Aman karena selama
`DATA_ACUAN_SUMBER=json` tidak ada jalur hitung atau sertifikat yang membaca baris-baris ini
(§1). Dipakai bila yang mengganggu hanya sakelarnya (impor jalan tiap boot).

**B. Hapus baris impor secara terarah** — tulis ke produksi: lewat urutan
`aturan-akses-database.md` butir 4 (rencana tertulis = bagian ini → cadangan (§4 langkah 1) →
tunjukkan hitungan dulu → eksekusi menunggu instruksi TERPISAH yang menyebut tabel dan jumlah
baris, mis. "hapus 25 baris `paket_acuan_versi` dan 25 baris `paket_acuan`"). Hanya bila
belum ada hasil hitung yang distempel versi ini dan tahap 3 belum jalan.

*Jebakan yang membuat "hapus baris paket" (07 §2a) tidak sesederhana kedengarannya:*

- `paket_acuan` soft delete (`PaketAcuan::delete()`). Baris ter-soft-delete **tetap memegang**
  unique `(organization_id, kode_profil)`, jadi impor ulang menolaknya ("paket sudah dihapus
  (soft delete)"). Yang dibutuhkan hapus **permanen**.
- `paket_acuan_versi.paket_acuan_id` ber-FK `restrictOnDelete`: versi dihapus **dulu**, baru
  paket. Urutan terbalik ditolak (dilatih di §3).
- `php artisan migrate:rollback` JANGAN dipakai: menjatuhkan tabel, bukan hanya baris impor.
- Menghapus lewat SQL melewati model, jadi tidak ada baris `audit_logs` `dihapus`. 50N baris
  `dibikin` dibiarkan sebagai riwayat; tulis kejadiannya di catatan rilis.

Syarat (semua harus 0; satu saja bukan 0 = berhenti, ada yang bergantung pada baris itu):

```sql
SELECT COUNT(*) FROM paket_acuan_suntingan;
SELECT COUNT(*) FROM paket_acuan_log;
SELECT COUNT(*) FROM simulasi_acuan;
SELECT COUNT(*) FROM uncertainty_calculations WHERE paket_acuan_versi_id IS NOT NULL;
-- versi yang BUKAN hasil impor awal:
SELECT COUNT(*) FROM paket_acuan_versi
 WHERE NOT (jenis = 'data' AND nomor_versi = 1 AND status = 'aktif'
            AND alasan LIKE 'Impor awal dari %'
            AND disahkan_oleh IS NULL AND dibuat_oleh IS NULL AND diajukan_oleh IS NULL);
-- paket dengan lebih dari satu versi:
SELECT COUNT(*) FROM (SELECT paket_acuan_id FROM paket_acuan_versi GROUP BY 1 HAVING COUNT(*) > 1) t;
```

Eksekusi (MySQL; SQLite memakai `BEGIN`), dalam satu transaksi, **COMMIT hanya bila kedua
hitungan sama persis dengan angka di instruksi** (25N dan 25N), selain itu ROLLBACK:

```sql
START TRANSACTION;
DELETE FROM paket_acuan_versi
 WHERE jenis = 'data' AND nomor_versi = 1 AND status = 'aktif' AND alasan LIKE 'Impor awal dari %';
SELECT ROW_COUNT();   -- harus 25N
DELETE FROM paket_acuan WHERE id NOT IN (SELECT paket_acuan_id FROM paket_acuan_versi);
SELECT ROW_COUNT();   -- harus 25N
-- cocok -> COMMIT;   beda -> ROLLBACK;
```

Sesudahnya: `paket_acuan` dan `paket_acuan_versi` kosong lagi, dan impor dapat dijalankan ulang
dari awal (dilatih di §3).

**C. Pulihkan cadangan.** Hanya bila ada keraguan pada tabel LAIN. Menghilangkan semua data
sejak cadangan — butuh keputusan pemilik tersendiri. **Berkas cadangan belum pernah diuji
dipulihkan** (§0, §4 langkah 1): kalau jalur ini mungkin dipakai, uji pemulihannya lebih dulu.

## 8. Yang ditemukan saat menyiapkan (di luar tugas, tidak diubah kecuali disebut)

- `EntrypointBootTest` baris 188–192 memblokir commit penyalaan (§4B). Rencananya di atas;
  tesnya sendiri sengaja TIDAK diubah di PR ini — perubahan itu milik commit 1 dan 2.
- `07-Migrasi-Rollout.md` §6 menyebut `/api/health` → `data_acuan.versi_aktif_tanpa_simulasi`.
  Health hanya melaporkan `data_acuan.sumber` (`routes/api.php:275`). Sudah dikoreksi di 07 §6;
  verifikasi pascarilis memakai sensus baca-saja (§4 langkah 10).
- Impor menyentuh SEMUA organisasi yang belum soft delete, termasuk yang dibuat belakangan
  (boot tidak punya cara membatasi ke satu organisasi; opsi `--organisasi=` hanya lewat shell).
  Organisasi baru yang lahir sesudah impor tidak otomatis mendapat paket.
- Dokumen induk menulis tabel yang ditulis "`paket_acuan` dan `paket_acuan_versi`"; kenyataannya
  tiga, karena `audit_logs` (diperbaiki di `07` §2a).
- 07 §1.1 mensyaratkan cadangan yang "diverifikasi bisa dipulihkan"; prosedur yang dijalankan
  baru memverifikasi isi berkas (§4 langkah 1). Keputusan pemilik.
