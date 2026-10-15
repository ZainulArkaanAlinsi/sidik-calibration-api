# 03 — SDD Sidik Studio

Rancangan perangkat lunak untuk kebutuhan di `02-SRS.md`. Bagian yang bergantung pada hasil spike
atau pengukuran lanjutan diberi tanda **⏳** beserta tugas tindak lanjutnya; jangan dibangun sebelum
tandanya dicabut di revisi dokumen ini. **Revisi 13 Okt 2026 (ST-T-010):** hasil spike S0a (10 Okt)
dan S0b (13 Okt) dimasukkan dan tanda yang terjawab dicabut; yang tersisa menunggu S0c, S1, atau
pengukuran di S4 (ringkasan §13).

Semua rujukan `path:baris` diperiksa 9 Okt 2026 pada worktree `C:\Users\USER\sidik-api-studio`
(branch `feat/studio-data-acuan`, termasuk perubahan yang belum di-commit) dan worktree mobile
`C:\Users\USER\sidik-mobile-studio`. Rujukan yang ditambahkan revisi 13 Okt (hasil spike) diperiksa
13 Okt 2026 pada HEAD `11f83f5`, termasuk `vendor/phpoffice/phpspreadsheet` 5.10.0. Baris bisa
bergeser sesudah commit berikutnya.

---

## 1. Keadaan hari ini yang menjadi pijakan

| Hal | Keadaan | Bukti |
|---|---|---|
| Profil alat | 48 profil di registry | `app/Services/Calibration/CalibrationProfileRegistry.php:95-266` |
| Kelas bersama | Agregasi budget: `uc = √Σ(u·ci)²`, veff Welch–Satterthwaite dengan batas `MAKS_V_EFF`, `k = t(0,975; ⌊veff⌋)` minimal 1, `kTetap` menang bila ada | `app/Services/GumCalculator.php:552-607` (`MAKS_V_EFF` L54) |
| Lantai CMC | `U = max(U hitung, CMC)` di jalur budget | `GumCalculator.php:475`; Volumetric di profil `VolumetricGlasswareProfile.php:367` |
| PASS/FAIL | `|error| + U ≤ toleransi` (ILAC-G8 guarded) | `GumCalculator.php:888-895` |
| t-quantile | Biseksi 200 langkah atas CDF beta tak lengkap milik repo | `app/Services/StudentTDistribution.php:41-57` |
| Versi rumus | `formula_versions` dengan `sumber` (`kode`/`database`), `parameter` json, `ekspresi` json, `status` (`draft`/`aktif`/`arsip`), rentang berlaku | migrasi `2026_07_27_110000_create_formulas_table.php:46-91`; `app/Models/FormulaVersion.php:41-50` |
| `sumber = database` | Ditolak 422 karena evaluator belum ada | `app/Http/Controllers/Api/FormulaController.php:137-148` |
| Stempel rumus | `uncertainty_calculations.formula_version_id`, dicari per `tanggal_kalibrasi` | migrasi yang sama L108-111; `app/Services/RumusKalibrasi.php:53-79` |
| Pemeriksa stempel | `periksaVersiRumus()` hanya untuk profil ber-`versiRumus()` (tekanan & piston) | `app/Services/CalibrationValidator.php:2140` |
| Data acuan P1 | `paket_acuan`, `paket_acuan_versi` (isi `longText` kanonik + `isi_sha256`), `paket_acuan_suntingan` (append-only), stempel `paket_acuan_versi_id` | migrasi `2026_10_08_100500_create_paket_acuan_tables.php`; `2026_10_08_100600_…` |
| JSON kanonik | Kunci terurut byte, float round-trip terpendek, `200` ≠ `200.0` | `app/Support/JsonKanonik.php` |
| Audit | `Diaudit` di 23 model, `audit_logs` (`note` string(500)), model audit menolak update/delete | `app/Models/Concerns/Diaudit.php`; `app/Models/AuditLog.php:99-110` |
| Izin | `MatriksIzin::PETA` nama izin → rute; boleh/tidak dibaca dari `role:` | `app/Services/MatriksIzin.php:44-149` |
| Pemisahan wewenang | Satu pintu `PemisahanWewenang`, tanpa sakelar | `app/Services/PemisahanWewenang.php:47-63` (kode-kode penolakan) |
| Rute tulis super admin | Grup saudara `role:super_admin` di ekor berkas | `routes/api.php:874-882` |
| Mesin rumus | `phpoffice/phpspreadsheet` 5.10.0 terpasang; `composer.json:18` = `"^5.10"` (ST-T-004, 10 Okt; belum patok persis seperti ADR-49-02). Spike S0a selesai 10 Okt (§6.4) | `composer.lock:5342-5343`; `tests/Unit/Spike/MesinRumusSpikeTest.php` |
| Aplikasi Studio | Proyek `studio/` di worktree mobile (S0b, 13 Okt): `sidik_studio`, `com.ptsidik.studio`, paket `sidik_calibration` lewat path; build debug Windows + APK ada (10 Okt), `flutter analyze` 0 isu, `flutter test` 28/28; belum diuji di perangkat (ST-T-020) | `studio/pubspec.yaml`; `studio/android/app/build.gradle.kts:18,36` |

## 2. Arsitektur

```
┌───────────────────────── Sidik Studio (Flutter, Windows + tablet Android) ─────────────────────────┐
│ cangkang_studio ─ seksi: Beranda │ Kelola Data │ Data Acuan │ Lembar Hitung │ Pengesahan │ Impor     │
│   widget/grid (grid sel bersama: Kelola Data, Data Acuan, Lembar Hitung)                           │
│   data/: kelola_data_service, lembar_hitung_service, simulasi_service (+ provider Riverpod)        │
│   paket sidik_calibration (path ../): ApiClient, token, izin, model+layanan data acuan, layar M2    │
│   TIDAK menghitung angka. TIDAK ada database lokal. Cache baca terbatas (NFR-STU-08).              │
└───────────────────────────────┬────────────────────────────────────────────────────────────────────┘
                                │ HTTPS REST, Sanctum token ability `internal`, header X-Klien
┌───────────────────────────────▼────────────────────────────────────────────────────────────────────┐
│ Laravel (routes/api.php — grup role:admin,teknisi,viewer + grup saudara role:super_admin)          │
│                                                                                                    │
│  Api\Studio\KelolaDataController ─► KelolaData\LayananKelolaData ─► RegistriTabel/DefinisiTabel     │
│                                        └─ Eloquent model (+Diaudit, event, guard) ─► audit_logs     │
│  Api\DataAcuanController (P1 baca + S3 tulis) ─► Acuan\LayananDataAcuan                             │
│  Api\Studio\LembarHitungController ─► Rumus\LayananLembarHitung (draf, ajukan, sahkan, tarik)       │
│        ├─ Rumus\ValidatorLembarHitung (statis: fungsi, siklus, rujukan, pembulatan, ukuran)         │
│        ├─ Rumus\EvaluatorLembarHitung ─► Rumus\MesinRumus (antarmuka)                               │
│        │        │                           └─ Rumus\MesinRumusPhpSpreadsheet (implementasi)        │
│        │        ├─ Rumus\PengikatMasukan (raw_measurements, spesifikasi, sesi, acuan via Tabel*,    │
│        │        │                          standar, resolver profil)                                │
│        │        └─ GumCalculator::agregasiBudget() + lantai CMC + keputusan  (PENGAWAS, K-49-07)    │
│        ├─ Rumus\PembandingParitas (Lembar Hitung vs profil PHP, definisi PRD §5)                    │
│        └─ Jobs\JalankanSimulasi / Jobs\JalankanSimulasiPotongan (antrean)                           │
│  Api\Studio\ImporWorkbookController ─► Rumus\PembacaWorkbook (PhpSpreadsheet Reader, di antrean)   │
│                                                                                                    │
│  Jalur hitung sesi (SATU pintu untuk store/update/preview/validator/hitung-ulang):                 │
│   Calibration\PemilihJalurHitung::untuk(sesi) ─► versi berlaku (RumusKalibrasi)                    │
│        sumber=kode     ─► profil PHP (hari ini, tidak berubah)                                     │
│        sumber=database ─► EvaluatorLembarHitung (+ pengawas PHP bila setara_php)                   │
│        (+ kandidat bayangan, bila ada, dievaluasi di belakang tanpa menstempel)                    │
│   ─► uncertainty_calculations {formula_version_id, paket_acuan_versi_id}                            │
└───────────────────────────────┬────────────────────────────────────────────────────────────────────┘
                                │ satu MySQL (produksi) / SQLite (test)
              ┌─────────────────┴──────────────────┐
              ▼                                    ▼
   Aplikasi lapangan (tidak berubah cara     Panel Filament /admin (tidak berubah)
   hitungnya; membaca versi_acuan +
   versi_rumus, ETag, tarikan 3 menit)
```

Tiga prinsip yang menentukan semua keputusan di bawah:

1. **Server menghitung, Studio menyunting** (ADR-49-01).
2. **Agregasi boleh dirumuskan di lembar, tetapi tidak pernah tanpa pengawas**: `GumCalculator`
   menghitung ulang setiap sesi dan beda selalu tercatat (ADR-49-08, K-49-07).
3. **Tidak ada pintu belakang**: semua tulis lewat Eloquent pada model beraudit, izin dibaca dari
   rute, organisasi lain 404 (ADR-49-05).

## 3. Keputusan desain (ADR)

### ADR-49-01 — Server satu-satunya evaluator

**Konteks.** Studio berjalan di Windows dan tablet; aplikasi lapangan di HP. Kalau Studio atau HP
ikut menghitung, ada dua mesin yang bisa berbeda, dan "sertifikat ini dihitung oleh apa" tidak
punya satu jawaban. **Keputusan.** Hanya server yang mengevaluasi Lembar Hitung: pratinjau,
simulasi, paritas, dan sesi nyata. Studio mengirim suntingan dan menampilkan hasil server.
**Akibat.** Pratinjau butuh jaringan; offline Studio hanya baca. Mesin rumus hanya ada di PHP,
jadi versinya bisa dipatok dan diuji (ADR-49-02). Pola ini sama dengan aplikasi lapangan hari ini
(`/calibrations/preview`).

### ADR-49-02 — PhpSpreadsheet di belakang antarmuka `MesinRumus`

**Konteks.** K-49-04 memilih `phpoffice/phpspreadsheet`. Mesin itu dirancang untuk spreadsheet
umum, bukan untuk sertifikat terakreditasi: tabel fungsinya memuat `WEBSERVICE` (I/O jaringan,
`vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Calculation/FunctionArray.php:2578`), iterasi
siklik bawaannya 1 (`Calculation.php:166`), dan `TINV`-nya Newton–Raphson dengan presisi
`8.88E-16` dan maksimum 256 iterasi (`Statistical/Distributions/StudentT.php:193-226`,
`NewtonRaphson.php:10-60`, `Functions.php:12`) — beda algoritme dari `StudentTDistribution`
repo. **Keputusan.**

```php
namespace App\Services\Rumus;

interface MesinRumus
{
    /** Nama + versi mesin yang dipakai, untuk dicatat di versi Lembar Hitung. */
    public function identitas(): array;                       // ['nama' => ..., 'versi' => ...]

    /** Urai satu rumus jadi token/rujukan; tidak mengevaluasi. Dipakai validator statis. */
    public function urai(string $rumus, string $lembarAsal): RumusTerurai;

    /**
     * Evaluasi satu konteks (satu titik atau satu sesi).
     * $isiMasukan: alamat → nilai (float|string|ErrorSel) dari pengikat.
     * $diminta: daftar alamat yang nilainya dipulangkan.
     */
    public function evaluasi(LembarHitung $lembar, array $isiMasukan, array $diminta): HasilEvaluasi;
}
```

Implementasi tunggal `MesinRumusPhpSpreadsheet`. Aturan pembungkus yang **wajib**:
- daftar putih fungsi ditegakkan validator kita sebelum mesin dipanggil (§6.4), bukan diandalkan
  dari mesin;
- `cyclicFormulaCount = 0` (siklus jadi galat, bukan satu putaran diam-diam);
- cache perhitungan mesin dibersihkan **setiap** evaluasi (pelajaran cache statis CR-48 §4);
- `composer.json` mematok versi persis (`"phpoffice/phpspreadsheet": "5.10.0"` atau versi yang
  lolos spike) dan test `MesinRumusVersiTerkunciTest` membandingkan `composer.lock` dengan versi
  yang tercatat pada versi Lembar Hitung aktif (FR-STU-132).

**Alternatif yang ditolak.** Parser sendiri: lebih kecil permukaannya tapi lebih jauh dari Excel dan
lebih lama (`06-rencana-rescope.md` "Butuh dari pemilik" no. 2). Pemilik sudah memilih.
**Akibat.** Semua kelas lain hanya tahu `MesinRumus`; mengganti mesin = satu kelas baru + paritas
ulang semua Lembar Hitung aktif.

**Hasil spike S0a (10 Okt 2026): satu objek `Spreadsheet` baru per evaluasi cukup; templat yang
direset tidak dipakai.** Lembar uji Volumetric Fixed 1 titik (3 sheet, 116 sel keluaran), 20 putaran
sesudah pemanasan: bangun median 11,82 ms (min 8,14, maks 17,66), evaluasi median 107,98 ms (min
79,52, maks 126,36) (`tests/Unit/Spike/MesinRumusSpikeTest.php:352-426`). Membangun hanya ±10% dari
total, jadi templat menghemat paling banyak itu dan membawa risiko cache basi antar-evaluasi.
Pembanding jalur PHP `hitungSesi()` median 4,148 ms, jadi mesin ≈26× lebih lambat: pratinjau 1 sesi
tetap sinkron, simulasi wajib lewat antrean (§9). Dua temuan pembungkus dari spike: (1) fungsi
kustom didaftarkan lewat `Calculation::addFunction()` ke registry statis global
(`vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Calculation/CalculationBase.php:30-42`) dan
**tidak bisa menimpa** fungsi bawaan (`addFunction('TINV', …)` memulangkan `false`,
`MesinRumusSpikeTest.php:323-347`), jadi penolakan fungsi bawaan tetap tugas validator kita;
(2) argumen rujukan sel datang ke fungsi kustom sebagai larik dan wajib
`Functions::flattenSingleValue()` — tanpa itu `k` diam-diam bernilai 12,706 (df 1) tanpa galat
(`MesinRumusSpikeTest.php:102-104`).

### ADR-49-03 — Lembar Hitung disimpan di `formula_versions` (`sumber = database`), isi kanonik + sha256

**Konteks.** §49 syarat 2 dan `AGENTS.md` §Olah data butir 1 menetapkan tempatnya:
`formula_versions`, `sumber = database`, isi kanonik + sha256. P1 menyimpan isi Data Acuan di
`longText` (bukan `json`) karena MySQL menormalkan kolom `json` sehingga hash bisa berubah
(`docs/BACA-DULU-BACKEND.md` bagian 9 Okt, butir Tabel). Kolom `formula_versions.ekspresi` bertipe
`json` (migrasi L74).

**Keputusan.**
1. Isi Lembar Hitung disimpan di `formula_versions.ekspresi` dalam format kanonik §6 di mana
   **setiap angka adalah string** (konstanta `{"angka": "7.95"}`, angka di dalam rumus memang
   teks). Dengan begitu normalisasi MySQL hanya bisa mengubah urutan kunci dan spasi, tidak
   nilai. `isi_sha256` = sha256 dari `JsonKanonik::serialisasi()` atas isi yang **diurai ulang**,
   sehingga urutan kunci versi MySQL tidak berpengaruh.
2. Test `IsiLembarHitungSha256StabilTest` di kedua suite (SQLite dan MySQL) menyimpan, membaca,
   dan menghitung ulang sha256. **Bila test itu merah di MySQL**, jatuh ke pola P1: kolom
   tambahan `isi longText` dan `ekspresi` dibiarkan `null` untuk versi `sumber = database`.
   Keputusan ini dicatat di tugas ST-T-092.
3. Metadata alur (pengaju, pengesah, dasar versi, lock, sumber metode) di kolom tambahan §4.1,
   bukan di `parameter`, karena `parameter` ikut beku dan pengesah ditulis **sesudah** isi beku.

**Akibat.** Stempel `formula_version_id` yang sudah ada langsung berlaku untuk Lembar Hitung; tidak
ada kolom stempel baru di `uncertainty_calculations`.

### ADR-49-04 — Bayangan → paritas → aktif terhadap profil PHP

**Konteks.** `AGENTS.md` §Olah data: metode harus divalidasi sebelum dipakai, dan "divalidasi" tidak
boleh berarti "seseorang mengetik lalu menekan simpan". Profil PHP sudah dibuktikan setara master
per alat. Produksi hanya punya sedikit sesi tersimpan (`AGENTS.md` §Olah data butir 2: 2 sesi
nyata, nol hasil hitung per catatan terakhir), jadi bukti dari sesi tersimpan saja tipis.
**Keputusan.** Versi Lembar Hitung **pertama** sebuah rumus melewati tiga tahap, dan sertifikat
tidak pernah dihitung Lembar Hitung sebelum tahap ketiga:

1. **Bayangan.** Kandidat (draf beku pada satu `isi_sha256`) didaftarkan sebagai simulasi jenis
   `bayangan` yang terbuka. Setiap simpan/hitung ulang sesi nyata profil itu tetap dihitung dan
   distempel **profil PHP** seperti hari ini; di belakangnya kandidat ikut dievaluasi dan
   perbandingannya (definisi `01-PRD.md` §5) ditambahkan ke laporan bayangan. Suntingan kandidat
   membuat bayangan `kedaluwarsa` dan harus dimulai ulang. Minimal berapa sesi/berapa lama: Q-49-03.
2. **Paritas.** `PembandingParitas` menjalankan kandidat atas semua sesi tersimpan + vektor master
   + sesi seeder; laporan bayangan ikut dihitung. Lulus 100% + diperiksa pemeriksa independen
   (penjaga-angka + orang Lab, Q-49-02) → kandidat boleh diajukan.
3. **Aktif dengan pengawasan.** Sesudah disahkan orang kedua dan berlaku, versi bertanda
   `setara_php = true`: Lembar Hitung menjadi jalur hitung, profil PHP tetap dihitung sebagai
   pengawas. Beda → temuan validator `paritas_php_beda` (PERINGATAN) yang menahan terbit sampai
   ditinjau. `setara_php` lepas otomatis saat versi berikutnya berlaku.

Versi berikutnya tidak diadu ke PHP sebagai syarat (bedanya memang disengaja); PHP tetap tampil
sebagai kolom informasi di pratinjau dan simulasi. Profil PHP **tidak dihapus**: versi
`sumber = kode` yang pernah menstempel sertifikat harus tetap bisa dihitung ulang persis
(`AGENTS.md` §Olah data butir 2). Rencana orkestrator 9 Okt (`06-rencana-rescope.md` butir 3)
menyebut "sesudah itu PHP profil jadi pembanding (shadow)"; tahap 3 di atas adalah bentuk itu.

### ADR-49-05 — Kelola Data lewat registry daftar putih + Eloquent, bukan SQL

**Konteks.** K-49-03 (semua tabel) dan Keputusan 4 `arsitektur-desktop-database.md`. phpMyAdmin
tidak mencatat apa pun; SQL mentah melewati event model, `Diaudit`, guard
(`CalibrationSession::booted()` yang menahan status mundur, `CalibrationSession.php:119`), dan
validasi. **Keputusan.** Satu `RegistriTabel` di kode berisi `DefinisiTabel` per tabel (56,
`02-SRS.md` §5). Semua baca lewat query builder Eloquent dengan lingkup organisasi dari definisi;
semua tulis lewat `Model::fill(kolom yang diizinkan)->save()` di dalam transaksi, dengan alasan
pengguna diteruskan ke `audit_logs.note`. Tidak ada rute yang menerima nama kolom atau potongan SQL
bebas; saringan dikirim sebagai struktur yang divalidasi terhadap definisi. **Akibat.** Menambah
tabel ke Studio = menambah definisi (dijaga test sapu skema). Aturan validasi diangkat dari
FormRequest/controller yang ada ke satu tempat yang dipakai bersama, bukan disalin.

### ADR-49-06 — Aplikasi Flutter terpisah `studio/` memakai paket `sidik_calibration` lewat path

**Konteks.** K-49-01: identitas berbeda total, Windows + tablet. Riset C: `flutter build windows
--flavor` tidak didukung di Flutter 3.44.8, identitas tertanam keras di runner Windows dan Gradle,
dan `auth_provider` terkopling ke FCM (`01-riset-rescope-flutter.md`). **Keputusan.** Opsi C riset:
proyek Flutter kedua `studio/` di repo mobile, dependensi `sidik_calibration: path: ../`
(`studio/pubspec.yaml`), identitas sendiri (`com.ptsidik.studio`), tema sendiri, tanpa Firebase dan
tanpa pembaru aplikasi lapangan. Kopling FCM diputus dengan penimpaan provider
(`studio/lib/inti/penimpaan.dart`). **Alternatif yang ditolak.** `lib/main_studio.dart` (identitas
tetap satu); monorepo `apps/` + `packages/` (235/238 test mengimpor `package:sidik_calibration`,
mahal). **Akibat.** Plugin native milik aplikasi lapangan ikut terdaftar di build Studio walau tidak
dipanggil (diterima sadar, komentar `studio/pubspec.yaml`). CI perlu job build Studio sendiri dan
`tool/cek-berkas-yatim.sh` perlu tahu paket kedua. **Hasil spike S0b (13 Okt 2026, worktree
mobile).** Build debug keduanya ada: `studio/build/windows/x64/runner/Debug/sidik_studio.exe` dan
`studio/build/app/outputs/flutter-apk/app-debug.apk` (keduanya 10 Okt). Windows: `ProductName` dan
`FileDescription` "Sidik Studio", `OriginalFilename` `sidik_studio.exe`
(`studio/windows/runner/Runner.rc:92-98`) lawan "PT Sidik - Kalibrasi" di aplikasi lapangan
(`windows/runner/Runner.rc:98`), jadi folder data `path_provider_windows` terpisah menurut
konstruksi. Android: `applicationId` `com.ptsidik.studio`, tanpa plugin Google Services dan tanpa
`google-services.json` (`studio/android/app/build.gradle.kts:14-16, 36`); kopling FCM diputus
`sumberTokenPushProvider.overrideWithValue(const TanpaTokenPush())`
(`studio/lib/inti/penimpaan.dart:30`). `flutter analyze` 0 isu, `flutter test` 28/28 (13 Okt).
⏳ Sisa: folder data terpisah dan pemasangan berdampingan **di perangkat** belum diuji sesudah build
itu — tindak lanjut ST-T-020 (S1), menunggu uji exe di Windows kantor dan APK sideload di tablet uji
(per 13 Okt 2026).

### ADR-49-07 — Tanpa hapus permanen dari Studio

**Keputusan (K-49-06).** Tidak ada rute Studio yang menghapus baris secara permanen. Kelas `arsip`
memakai soft delete; `users` memakai `status = nonaktif`; versi memakai `dibuang`/`arsip`/`ditarik`;
"hapus baris" di draf hanya mengubah isi draf. Hapus permanen tetap lewat
`docs/aturan-akses-database.md` butir 4 (rencana tertulis → cadangan → dry-run → instruksi
terpisah). Dijaga test sapu: tidak ada rute ber-metode `DELETE` di prefix Studio, dan tidak ada
pemanggilan `forceDelete()` di namespace Studio.

### ADR-49-08 — Agregasi bisa disunting per alat, dengan `GumCalculator` sebagai pengawas

*Direvisi 9 Okt 2026 oleh K-49-07.* Versi awal ADR ini menahan agregasi di kelas bersama dan
menjadikan sel `uc`/`veff`/`k`/`U` di lembar sekadar tampilan. Pemilik lalu memutuskan agregasi
ikut bisa disunting per alat (K-49-07, `docs/permintaan-user-7.md` §49; `AGENTS.md` §Profil
kalibrasi untuk alat yang punya Lembar Hitung aktif). Q-49-10 terjawab oleh keputusan ini.

**Keputusan.**
1. **Blok agregasi bawaan.** Setiap Lembar Hitung lahir dari templat yang memuat blok agregasi GUM
   setara `GumCalculator::agregasiBudget()` (`GumCalculator.php:552-607`), lantai CMC
   (`GumCalculator.php:475`) dan keputusan (`GumCalculator.php:888-895`, dipanggil dengan U sesudah
   lantai, L302): `uc = √Σ(ui·ci)²`; penyebut Welch–Satterthwaite = **jumlah** `(ui·ci)⁴/vi`
   semua baris ber-`vi > 0` dan `ui·ci ≠ 0`; veff dibatasi `999 999 999 999` (`MAKS_V_EFF`, L54) dan
   kosong bila penyebut nol; `k` = `k_tetap` bila diisi, kalau tidak t-quantile 0,975 dengan derajat
   bebas `max(1, floor(veff))`, atau 2 bila veff kosong; `U = k·uc`; U95 = `max(U, CMC)` bila ada CMC;
   PASS bila `|error| + U95 ≤ toleransi`, kosong bila toleransi tidak ada atau ≤ 0. Bentuk selnya di
   §6.5 baris `BUDGET!C30:C36`.
2. **Lembar yang terbit.** Untuk alat ber-Lembar Hitung aktif, angka agregat yang disimpan dan
   dicetak (`ketidakpastian_gabungan`, `derajat_kebebasan_efektif`, `faktor_cakupan_k`, U hitung,
   U95, keputusan) dibaca dari sel keluaran `agregat` lembar. Lab boleh mengubah rumus blok itu
   lewat versi baru + simulasi + pengesahan.
3. **`GumCalculator` sebagai pengawas.** Setiap evaluasi lembar juga memanggil
   `GumCalculator::agregasiBudget()` atas komponen yang sama, lantai CMC, dan keputusan bersama.
   Hasil lembar dibandingkan dengan pengawas menurut definisi paritas (`01-PRD.md` §5 syarat 1–3).
   Beda → validator memberi PERINGATAN `agregasi_beda_dari_gum` yang terbaca di jejak sesi (sel,
   nilai lembar, nilai pengawas, selisih), simulasi menampilkan selisihnya per sesi, dan versi yang
   sengaja menyimpang wajib membawa catatan penyimpangan bersumber (FR-STU-128, `AGENTS.md`
   §Olah data butir 4). Tidak memblokir terbit (pemilik mengizinkan suntingan), kecuali digabung
   dengan penahan lain; tetapi tidak pernah diam.
4. **Versi pertama tetap identik.** Gerbang paritas (ADR-49-04) membandingkan seluruh agregat
   dengan profil PHP, jadi versi pertama setiap alat harus memakai blok bawaan apa adanya.
   Kesalahan master yang sudah diketahui — veff Volumetric Fixed dibagi satu baris `K43`
   (`docs/pertanyaan-lab-volumetric.md` no. 1) — tidak ditiru di versi pertama; meniru master
   butuh versi baru, simulasi, pengesahan, dan jawaban lab.
5. **Baris `perbandingan_cmc`** (`CalibrationProfile.php:2344`, dibutuhkan gerbang
   `u95_meledak_dari_cmc`) tetap dibangun evaluator dari U hitung dan CMC lembar, supaya validator
   yang sudah ada tetap bekerja.

**Akibat.** `k` lembar sekarang ikut jalur terbit. `TINV` mesin dan `StudentTDistribution` sama-sama
memotong derajat bebas ke bawah, tetapi algoritmenya beda (Newton–Raphson lawan biseksi). Spike S0a
(10 Okt 2026, §6.4 butir 3) mengukur bedanya kecil (≤ 3,1·10⁻¹³ relatif pada 3 sesi, angka cetak
sama), tetapi waktu `TINV` mesin tumbuh linear terhadap derajat bebas (2.162,91 ms pada df 10⁶) dan
blok bawaan membiarkan `veff` sampai 999.999.999.999. Keputusan sesudah spike (dicatat orkestrator
10 Okt): blok bawaan memakai fungsi terdaftar `SIDIK.KFAKTOR(veff)` yang memanggil
`StudentTDistribution::quantile(0.975, max(1, floor(veff)))` — ekspresi yang sama dengan pengawas
(`GumCalculator.php:598`), sehingga `k` identik bit — dan `TINV`/`T.INV.2T` ditolak di lembar
(§6.4). `GumCalculator::keputusan()` hari ini `private` (L888); pengawas butuh pintu
publik yang memakai fungsi yang sama tanpa mengubah perilakunya.

### ADR-49-09 — Satu pasang tabel simulasi untuk Data Acuan dan Lembar Hitung

**Konteks.** CR-48 merancang `simulasi_acuan` + `simulasi_acuan_sesi` untuk P2; keduanya **belum
dibuat** (§48 P1 penyimpangan 1). Lembar Hitung butuh simulasi, paritas, dan bayangan dengan
laporan yang sama bentuknya. **Keputusan.** Buat satu pasang tabel umum `simulasi_versi` +
`simulasi_versi_sesi` dengan target polimorfik (`paket_acuan_versi` atau `formula_versions`) dan
`jenis` (`dampak`, `paritas`, `bayangan`). Laporan bayangan adalah simulasi terbuka yang bertambah
satu baris tiap sesi nyata disimpan (ADR-49-04 tahap 1). Pengawasan PHP sesudah aktif (tahap 3)
tidak masuk tabel ini; dicatat sebagai temuan validator (lihat §7.5). **Akibat.** `paket_acuan_versi.simulasi_terakhir_id` (sudah
ada, tanpa FK, migrasi L108) menunjuk ke `simulasi_versi`.

### ADR-49-10 — Impor `.xlsm`: dekripsi ke temp PRIVAT di disk, berkas asli terenkripsi disimpan (revisi 13 Okt 2026)

**Konteks.** Workbook master terenkripsi (Agile 4.4, SHA-512, AES-256, spin 100.000 — enam berkas
diperiksa S0c) dan memuat sheet `DATABASE`/`SERTIFIKAT` berisi data pelanggan. Sandi tidak boleh
tersimpan atau sampai ke agen.

**Yang berubah dari rancangan awal, dan kenapa.**
1. *"Dekripsi di memori" tidak berlaku.* Spike S0c (`_agents_workspace/studio-data-acuan/03-spike-S0c.md`)
   membuktikan Opsi A (`Reader\Xlsx::setEncryptionPassword()`) menulis ZIP TERDEKRIPSI — termasuk
   sheet DATABASE — ke `sys_get_temp_dir()` (`Xlsx.php:162-203`). Jalur sukses dan sandi salah
   bersih, tetapi berkas itu **tertinggal** bila exception terjadi di tengah `load()` (Windows:
   `@unlink` gagal karena ZIP masih dipegang reader) atau proses mati. `AgileEncryption::decrypt()`
   memang di memori, tetapi `ZipArchive` tetap butuh berkas. `sys_temp_dir` itu PHP_INI_SYSTEM, jadi
   tidak bisa dipindah dari dalam proses.
2. *Berkas asli ikut disimpan* (K-49-11, membalik Q-49-06): byte asli yang masih terenkripsi, di disk
   privat, tidak pernah versi terdekripsi.

**Keputusan (S1b, dikerjakan).** Opsi A dipertahankan untuk MEMBACA, tetapi dekripsinya tidak
lewat reader:
- `PembacaXlsmWorkbook` mendekripsi sendiri lewat API publik `OLE` + `AgileEncryption::parse(…, 100000)`
  + `AgileEncryption::decryptFile()` ke **subfolder acak per impor** di
  `config('studio.temp_workbook')` = `storage/app/private/tmp-workbook/impor-<acak>/`, lalu memberi
  reader ZIP polos itu (reader tidak lagi membuat temp sendiri). Batas putaran kunci 100.000:
  berkas ber-spin lebih besar ditolak sebelum penurunan kunci yang mahal (`profil_enkripsi_ditolak`).
- Reader dan `Spreadsheet` hanya hidup di satu fungsi; begitu fungsi itu selesai (sukses atau
  exception) ZIP-nya terlepas, lalu `finally` memanggil `gc_collect_cycles()` dan menghapus subfolder.
  Exception reader **tidak dirantai** keluar (jejaknya menahan argumen objek).
- Proses mati di tengah jalan: shutdown handler menghapus subfolder yang masih terdaftar aktif.
  Dibunuh paksa: `studio:sapu-temp-workbook` (terjadwal tiap jam, juga dipanggil di awal tiap impor
  `--xlsm`) menghapus sisa berumur > 60 menit.
- Sandi: diminta lewat prompt tersembunyi perintah artisan (`$this->secret()`), sekali per jalan;
  parameter yang membawanya bertanda `#[SensitiveParameter]`, galat dekripsi tidak dirantai (jejak
  `decryptFile()` membawa sandi sebagai argumen), pesan galat tidak pernah memuatnya.
- Berkas asli: disalin apa adanya ke disk `local` di `workbook-master/{organization_id}/{sha256}.xlsm`,
  dibaca balik dan diadu sha256-nya sebelum baris ditulis; transaksi gagal → berkas yang baru
  ditulis dihapus. Kolom `berkas_disk`, `berkas_path`, `sha256`, `ukuran` di `workbook_master` (§4.6).
- ~~Isi sheet dikecualikan tidak pernah dibuat di memori (`IReadFilter`), dan tidak pernah
  ditulis.~~ **Dicabut K-49-12 (13 Okt 2026):** semua sheet dibaca dan disimpan apa adanya,
  termasuk DATABASE/SERTIFIKAT/konsep; lembar itu hanya ditandai `tautan_tabel`. Saringan baca
  (`saringan()`) tinggal `DefaultReadFilter`, dipertahankan sebagai titik suntik test.
- Sumber `.xlsm` yang ternyata ZIP polos (tidak bersandi) tetap disimpan apa adanya (K-49-11),
  tetapi ditandai `workbook_master.berkas_terenkripsi = false` + catatan, dan API mengirim
  `berkas_asli_terenkripsi: false` (tinjau S1b #5, 13 Okt 2026). Dipilih menyimpan + menandai,
  bukan menolak: K-49-11 meminta byte asli "apa adanya", dan penolakan akan membuat master yang
  sah tetapi tidak bersandi tidak bisa dilihat sama sekali.
- ~~Jalur `--xlsm` ditahan tanpa `--saya-paham-data-pelanggan`~~ — **dicabut K-49-12**: pemilik
  memutuskan data pelanggan (isi DATABASE/SERTIFIKAT/konsep dan blok identitas di INPUT DATA)
  ditampilkan apa adanya, tanpa redaksi. Batasnya di penyimpanan: DB dan disk privat di luar repo
  (repo PUBLIC; test hanya memakai fixture sintetis), isi terkurung organisasi di API (404).
- **`--ganti` untuk xlsm (tahap A):** sha xlsm = byte berkas, tidak ikut format kanonik. Dengan
  `--ganti`, berkas yang byte-nya sudah ada di baris xlsm bernama sama (lab sama) didekripsi &
  diekstrak ulang lalu menggantikan baris itu dalam satu transaksi (audit `dihapus`); berkas asli
  di disk tidak dihapus. Tidak pernah menyeberang sumber (csv tidak mengganti xlsm dan sebaliknya).
- **Pembungkus untuk pemilik:** `C:\Users\USER\sidik-studio-lokal\impor-xlsm.ps1` (lokal, di luar
  repo) menjalankan perintah ini terhadap DB SQLite lokal; sandi tetap diketik pemilik di prompt
  `secret()`.
- Dijaga `StudioImporWorkbookXlsmTest`: fixture terenkripsi dibangkitkan di test; jalur sukses, sandi
  salah, profil spin > batas, bukan OOXML, dan exception DI TENGAH `load()` (saringan yang meledak
  selagi ZIP terdekripsi terbukti ada) — semuanya diakhiri temp privat kosong.

**Yang belum (tahap unggah dari Studio, ST-T-137).** S1b mengimpor lewat perintah artisan di mesin
pemilik. Unggahan lewat Studio memakai kelas yang sama; job antrean tetap **tidak** boleh membawa
sandi: dekripsi di request (sinkron, berbatas ukuran & waktu) atau seluruh impor sinkron bila cukup
cepat. Opsi B (dekripsi di klien) dan C (alat terpisah) tidak dipakai.

## 4. Model data

Prinsip: **pakai tabel yang ada; kolom baru pilihan terakhir** (`AGENTS.md` §Alur Kerja 4).
Tidak ada kolom baru di `raw_measurements`, `uncertainty_calculations`, atau `calibration_sessions`.
Semua migrasi additive; nama index eksplisit ≤ 64 karakter (MySQL).

```
organizations 1─* formulas 1─* formula_versions 1─* lembar_hitung_suntingan
                                       │ 1
                                       ├─* uncertainty_calculations (formula_version_id, sudah ada)
                                       ├─0..1 dasar_versi (self)
                                       └─* simulasi_versi (target polimorfik) 1─* simulasi_versi_sesi
organizations 1─* paket_acuan 1─* paket_acuan_versi ─* simulasi_versi (target polimorfik)
organizations 1─* unggahan_workbook ─0..1 formula_versions (draf hasil impor)
organizations 1─* workbook_master 1─* workbook_master_lembar        (S1b, mode Asli Excel, §4.6)
users ─ dibuat/diajukan/disahkan/ditolak oleh ─ formula_versions
```

### 4.1 `formula_versions` — kolom tambahan

Kolom yang ada tidak diubah. Status baru hanya nilai string (kolom `status` string(16), L76),
tanpa migrasi enum.

| Kolom baru | Tipe | Null | Catatan |
|---|---|---|---|
| `isi_sha256` | char(64) | ya | diisi saat `diajukan`; sesudah itu tetap (ADR-49-03) |
| `dasar_versi_id` | foreignId → `formula_versions` | ya | `nullOnDelete`; versi asal draf |
| `alasan` | text | ya | wajib saat ajukan |
| `rujukan` | json | ya | `[{jenis: ik\|sel_master\|lampiran\|sertifikat_standar\|keputusan_lab, nilai, catatan}]` |
| `sumber_metode` | json | ya | `{ik, revisi_ik, sel_master: [..], diputuskan_oleh, tanggal_keputusan, catatan}` — wajib saat ajukan; ikut beku |
| `diajukan_oleh` | foreignId → `users` | ya | `nullOnDelete` |
| `diajukan_pada` | timestamp | ya | |
| `disahkan_oleh` | foreignId → `users` | ya | `nullOnDelete` |
| `disahkan_pada` | timestamp | ya | |
| `ditolak_oleh` | foreignId → `users` | ya | juga untuk `ditarik` |
| `ditolak_pada` | timestamp | ya | |
| `catatan_keputusan` | text | ya | alasan tolak/kembalikan/tarik |
| `lock_versi` | unsignedInteger | tidak, default 0 | optimistic lock draf |
| `simulasi_terakhir_id` | unsignedBigInteger | ya | menunjuk `simulasi_versi` jenis `dampak` (tanpa FK, pola P1) |
| `paritas_id` | unsignedBigInteger | ya | menunjuk `simulasi_versi` jenis `paritas` yang lulus |
| `setara_php` | boolean | tidak, default false | pengawasan PHP aktif sesudah versi pertama berlaku (ADR-49-04 tahap 3) |
| `mesin` | json | ya | `{nama, versi}` dicatat saat sahkan (FR-STU-132) |

Index baru: `fversi_formula_status_mulai_idx (formula_id, status, effective_from)`.

**Kenapa kolom, bukan JSON di `parameter`.** Pengesah dan waktunya ditulis sesudah isi beku, butuh
FK ke `users` untuk integritas, dan dibaca `PemisahanWewenang`. Alasan yang sama dipakai P1 untuk
kolom `paket_acuan_versi_id` (`permintaan-user-7.md` §48 "Alasan kolom stempel"). Pola kolomnya
sengaja sama dengan `paket_acuan_versi` (migrasi `2026_10_08_100500`, L80-112).

**Model.** `FormulaVersion` menambah konstanta status (`STATUS_DIAJUKAN`, `STATUS_TERJADWAL`,
`STATUS_DITOLAK`, `STATUS_DITARIK`, `STATUS_DIBUANG`), penjaga `updating` yang menolak perubahan
`ekspresi`/`isi_sha256`/`sumber_metode` bila status bukan `draft`, dan penjaga `deleting` yang
menolak penghapusan versi yang pernah menstempel hasil (`jumlahHasilHitung() > 0`, L104) atau
yang pernah diajukan. `bentrokRentang()` (L118) ikut menghitung `terjadwal` sebagai berlaku.

### 4.2 `lembar_hitung_suntingan` (baru, append-only)

| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint | |
| formula_version_id | foreignId → `formula_versions` | `cascadeOnDelete` tidak dipakai; `restrictOnDelete` |
| lembar | string(64) | nama sheet; `_nama` untuk named range, `_pengikat` untuk pengikat |
| alamat | string(32) null | `C22`, atau null untuk perubahan nama/pengikat |
| jenis | string(16) | `sel`, `nama`, `pengikat`, `struktur` (sisip/hapus baris/kolom), `lembar` |
| nilai_lama | longText null | isi sel/nama/pengikat sebelumnya (kanonik) |
| nilai_baru | longText null | |
| sumber | string(16) | `ketik`, `tempel`, `isi_bawah`, `impor`, `ambil_alih`, `struktur` |
| alasan | text null | opsional per suntingan |
| user_id | foreignId → `users` null | `nullOnDelete` |
| created_at | timestamp | `useCurrent()` |

Index `lhs_versi_lembar_idx (formula_version_id, lembar)`. Model menolak `updating`/`deleting`
(pola `PaketAcuanSuntingan.php:60-72`). `PemisahanWewenang` membaca tabel ini untuk daftar
penyunting sebuah versi.

### 4.3 `simulasi_versi` dan `simulasi_versi_sesi` (baru, ADR-49-09)

`simulasi_versi`:

| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint | |
| organization_id | foreignId | `cascadeOnDelete` tidak; `restrictOnDelete` |
| target_type | string(32) | `formula_version` atau `paket_acuan_versi` |
| target_id | unsignedBigInteger | |
| jenis | string(16) | `dampak`, `paritas`, atau `bayangan` (terbuka, bertambah tiap sesi nyata) |
| isi_sha256_saat_jalan | char(64) | sha isi target saat simulasi dimulai (dihitung walau masih draf) |
| status | string(16) | `antre`, `jalan`, `selesai`, `gagal`, `kedaluwarsa`, `dibatalkan` |
| jumlah_total, jumlah_selesai | unsignedInteger | progres |
| ringkasan | json null | `{sesi_total, vektor_total, berubah, cetak_berubah, terbit_cetak_berubah, keputusan_berubah, tertahan_berubah, agregasi_beda_gum, identik_bit, dalam_toleransi, gagal, lulus}` |
| pemeriksa | json null | khusus paritas: `{agen: "sidik-penjaga-angka", catatan, diperiksa_pada, orang_lab: {user_id?, nama?}}` (Q-49-02) |
| mesin | json | `{nama, versi}` saat jalan |
| dijalankan_oleh | foreignId → `users` | |
| mulai_pada, selesai_pada | timestamp null | |
| timestamps | | |

Index `sv_target_idx (target_type, target_id, jenis, status)`, `sv_org_idx (organization_id, created_at)`.

`simulasi_versi_sesi`:

| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint | |
| simulasi_versi_id | foreignId | `cascadeOnDelete` (laporan anak ikut induk; induk tidak pernah dihapus dari Studio) |
| calibration_session_id | foreignId null | `nullOnDelete` |
| vektor_kode | string(96) null | untuk vektor master/fixture |
| status_sesi | string(32) null | status sesi saat simulasi |
| sudah_terbit | boolean | |
| hasil | string(16) | `sama`, `berubah`, `gagal_hitung` (dampak); `identik`, `toleransi`, `gagal` (paritas) |
| cetak_berubah, keputusan_berubah, tertahan_berubah | boolean | |
| selisih | json | `[{titik_ke, kolom, lama, baru, abs, rel, cetak_lama, cetak_baru}]`, hanya yang beda |
| galat | text null | pesan bila evaluasi gagal |
| created_at | timestamp | |

Unique `svs_simulasi_sesi_unik (simulasi_versi_id, calibration_session_id, vektor_kode)` supaya
potongan job idempoten (NFR-STU-07).

### 4.4 `unggahan_workbook` (baru, tanpa berkas)

`id, organization_id, kode_profil string(64), nama_asli, ukuran unsignedBigInteger, sha256 char(64),
terenkripsi boolean, hasil string(24) (draf_dibuat|struktur_bermasalah|gagal), laporan json,
formula_version_id null (draf hasil), user_id, timestamps`. Unique `uw_org_sha_unik (organization_id,
sha256)`. **Tidak ada kolom path berkas** (ADR-49-10, Q-49-06). Bila Q-49-06 dijawab "simpan",
tambah `berkas_path` nullable ke disk privat pada migrasi tersendiri.

> 13 Okt 2026: Q-49-06 dijawab "simpan" (K-49-11). Untuk mode Asli Excel, berkas asli disimpan
> lewat `workbook_master.berkas_disk/berkas_path` (§4.6). `unggahan_workbook` (draf Lembar Hitung,
> S5) belum dibuat; saat lahir, dia merujuk `workbook_master` alih-alih menyimpan berkas kedua.

### 4.6 `workbook_master` dan `workbook_master_lembar` (baru, S1b — K-49-08..12)

Migrasi `2026_10_13_100000_create_workbook_master_tables.php`. Hasil ekstraksi workbook master
alat, mode **Asli Excel** baca-saja. Dua tabel additive; nol kolom di tabel lama.

`workbook_master` (model `WorkbookMaster`, `Diaudit`):

| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint | |
| organization_id | foreignId → organizations | `wm_org_fk`, cascade; disaring tangan (`sidik-query-organisasi`) |
| kode_profil | string(64) null | null = keluarga banyak profil / belum terpetakan (`PetaProfilWorkbook`) |
| keluarga | string(64) null | `volumetric_fixed`, `volumetric_graduated`, `enclosure`, `piston_volume`, `tekanan_druck`, `volumetric` |
| nama | string | csv: nama folder; xlsm: nama berkas tanpa ekstensi |
| sumber | string(8) | `csv` \| `xlsm` |
| sha256 | char(64) | csv: sha256 isi kanonik (`KanonikWorkbook`); xlsm: sha256 BYTE berkas asli terenkripsi |
| ukuran | unsignedBigInteger | csv: jumlah byte semua CSV folder; xlsm: byte berkas asli |
| jumlah_lembar | unsignedInteger | |
| berkas_disk, berkas_path | string null | K-49-11: `local`, `workbook-master/{org}/{sha256}.xlsm`; null untuk csv |
| berkas_terenkripsi | boolean null | migrasi `2026_10_13_100100`: true = berkas asli tersimpan terenkripsi; false = sumber `.xlsm` ZIP polos, disimpan apa adanya; null = tanpa berkas (csv) |
| catatan | text null | asal folder CSV, catatan peta profil (keluarga, salinan per-alat, pertanyaan lab) |
| diimpor_oleh | foreignId → users null | `wm_diimpor_oleh_fk`, nullOnDelete; artisan: dari `--oleh=<id\|email>` (orang lab di organisasi tujuan; juga jadi pelaku `audit_logs`), null bila tidak diisi |
| timestamps | | |

Unique `wm_org_sha_unik (organization_id, sha256)` (impor idempoten); index
`wm_org_profil_idx (organization_id, kode_profil)`.

`workbook_master_lembar` (model `WorkbookMasterLembar`; tanpa `organization_id`, diraih lewat induk):

| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint | |
| workbook_master_id | foreignId | `wml_wm_fk`, cascade |
| urutan | unsignedInteger | xlsm: urutan sheet Excel; csv: INPUT DATA dulu, lalu nama berkas alami tanpa peka huruf |
| nama | string | nama sheet / nama berkas CSV |
| tersembunyi | string(12) | `tidak` \| `hidden` \| `veryHidden` |
| dikecualikan | boolean | dulu K-49-08 (DATABASE, SERTIFIKAT, …); **sejak K-49-12 selalu false** untuk impor format 3 — kolom dibiarkan untuk baris lama |
| alasan_dikecualikan | string null | format 3: selalu null |
| tautan_tabel | string(64) null | `customers` \| `certificates` — penanda informatif DATABASE / SERTIFIKAT (+ `(n)`, `STYLE n`), `Certificate (n)`, `konsep`; isinya tetap disimpan (K-49-12) |
| jumlah_baris, jumlah_kolom, jumlah_sel, jumlah_rumus | unsignedInteger | baris lama ber-`dikecualikan` → semua 0 |
| tata_letak | json | `{merge[], lebar_kolom{huruf: lebar}, tinggi_baris{baris: tinggi}, kolom_tersembunyi[], baris_tersembunyi[], lebar_kolom_bawaan, tinggi_baris_bawaan, lebar_perkiraan, freeze, garis_kisi, zoom, jumlah_komentar}` — kunci kontrak mobile `TataLetakLembar.fromJson`. Di kolom DB peta kosong boleh `[]`; ke kabel dan ke hash selalu lewat `KanonikWorkbook::tataLetakKabel()` yang menulis `{}` dan melengkapi kunci yang hilang (baris lama) |
| sel | longText | JSON kanonik byte-stabil (alasan LONGTEXT = `paket_acuan_versi.isi`); baris lama ber-`dikecualikan` → `[]`; tidak ikut `toArray()` |
| timestamps | | |

Unique `wml_wm_urutan_unik (workbook_master_id, urutan)`.

**Format sel** (dipakai API apa adanya, `KanonikWorkbook`):
`{"a":"C5","b":5,"k":3,"t":"n|s|b|e|f|kosong","n":<float|null>,"s":"<teks tampil>","r":"=…"|"{=…}"|null,"f":"<numfmt>"|null,"g":{tebal,miring,isi,teks,rata,border}|null}`.
`n` tidak pernah dibulatkan; `s` xlsm = nilai CACHE diformat number format sel (rumus tidak
dihitung ulang); `r` larik ditulis `{=…}`; `kosong` = sel tanpa nilai yang disimpan karena bergaya.
Gaya `g` (kunci kontrak mobile `GayaSel.fromJson`): `tebal`/`miring` `true`, `isi`/`teks` warna
`#RRGGBB`, `rata` perataan horizontal Excel apa adanya (tanpa `general`), `border` objek per sisi
`{atas,kanan,bawah,kiri}` → gaya garis Excel, hanya sisi bergaris; format 3 menambah `vertikal`
(tanpa kunci = `bottom`), `bungkus`, `ukuran` (pt, bila beda dari font bawaan workbook), `font`
(bila beda). Sel `kosong` disimpan hanya bila ber-`isi` atau ber-`border`.
sha256 workbook = sha256 kanonik `{format:3, lembar:[meta + tata_letak kabel + sel_sha256]}`.
**Format 2** (13 Okt 2026, tinjau S1b #1-#2): bentuk `tata_letak` dan kunci `g` mengikuti mobile,
jadi sha256 csv berubah walau CSV-nya sama. Baris csv format 1 diganti lewat
`studio:impor-workbook --csv --ganti` (hapus baris csv bernama sama di lab yang sama, lalu tulis
yang baru, satu transaksi per workbook; baris xlsm tidak pernah disentuh).
**Format 3** (13 Okt 2026, tahap A, K-49-12): isi DATABASE/SERTIFIKAT/konsep ikut diekstrak;
tata letak `.xlsm` setia Excel — lebar/tinggi bawaan sheet (cadangan: tabel `Shared\Font`
PhpSpreadsheet untuk font bawaan workbook), `freeze` dari `xSplit`/`ySplit` pane beku (bukan
`topLeftCell`), `garis_kisi`, `zoom`; CSV `lebar_kolom` perkiraan (panjang teks + 1, 4..60) dengan
`lebar_perkiraan = true`. Batas yang diketahui: `baseColWidth` tanpa `defaultColWidth` tidak
dibaca PhpSpreadsheet (memakai lebar bawaan font); gaya baris/kolom utuh tanpa elemen sel tidak
ikut. `--ganti` kini juga untuk xlsm (ADR-49-10). API daftar: xlsm dulu + `versi_xlsm_id`
(`VersiXlsmWorkbook`).
### 4.5 Yang tidak butuh skema baru

- **Kelola Data**: registry di kode; jejak di `audit_logs` (aksi baru `diekspor` cukup konstanta,
  kolom `action` string(20)).
- **Log metode**: versi beku + `sumber_metode` + transisi di `audit_logs` (FR-STU-127).
- **Temuan pengawasan PHP** (`paritas_php_beda`): temuan validator saat hitung (tidak disimpan terpisah), plus hitungan
  terbuka di `/api/health`.
- **Data Acuan tulis (S3)**: kolom alur `paket_acuan_versi` sudah ada sejak P1.

## 5. Kelola Data

### 5.1 Komponen

| Kelas | Tanggung jawab |
|---|---|
| `App\Services\KelolaData\RegistriTabel` | Daftar 56 `DefinisiTabel`; `untuk(string $tabel): ?DefinisiTabel` (tersembunyi → `null`) |
| `App\Services\KelolaData\DefinisiTabel` | Nama, label, kelompok, kelas, model, kolom (`DefinisiKolom[]`), lingkup organisasi (closure atas query), aturan validasi ubah/tambah (dari sumber bersama), urutan bawaan, kolom pencarian, `bisa_tambah`, aturan tolak arsip |
| `App\Services\KelolaData\DefinisiKolom` | nama, label, tipe (`teks`, `angka`, `desimal`, `tanggal`, `waktu`, `boolean`, `pilihan`, `relasi`, `json_ringkas`), `tampil`, `bisa_urut`, `bisa_saring`, `bisa_cari`, `bisa_ubah`, `wajib`, `relasi` (tabel + kolom label) |
| `App\Services\KelolaData\LayananKelolaData` | jelajah (paginasi, urut, saring, cari, arsip), detail, riwayat, ubah, tambah, arsip, pulihkan, ekspor stream |
| `App\Http\Controllers\Api\Studio\KelolaDataController` | HTTP; tidak berisi logika domain |
| `App\Http\Requests\Studio\SaringKelolaDataRequest` | validasi struktur saringan terhadap definisi |

### 5.2 Aturan pelaksanaan

- **Lingkup.** `DefinisiTabel::lingkup(Builder $q, User $u)` wajib ada untuk setiap tabel yang
  tampil; test sapu memastikan tidak ada definisi tampil tanpa lingkup. Tabel anak memakai
  `whereHas` ke induk ber-`organization_id`.
- **Validasi bersama.** Aturan untuk `equipments`, `customers`, `standards`, `rooms`,
  `calibration_methods`, `equipment_categories`, `folders`, `folder_files`, `users`,
  `organizations` diangkat dari FormRequest/controller yang ada ke metode statis bersama (mis.
  `AturanAlat::ubah()`), dipakai controller lama **dan** definisi Studio. Refactor itu wajib
  berperilaku sama (test API lama hijau tanpa diubah).
- **Alasan ke audit.** `Diaudit` hari ini tidak menerima catatan untuk event otomatis
  (`catatAudit()` menerima `$catatan`, `Diaudit.php:125`, tapi event `updated` tidak memberinya).
  Tambah konteks catatan berlingkup (mis. `Diaudit::denganCatatan(string $catatan, callable $aksi)`,
  pola `tanpaAudit()` L49-58) supaya event yang lahir di dalam aksi itu menulis `note`.
- **Optimistic lock.** Token versi baris = sha1 dari `updated_at` + id (dikirim sebagai `ETag` di
  detail, diterima sebagai `If-Match`). Tabel tanpa `updated_at` tidak boleh kelas `ubah`.
- **Ekspor.** Stream (`StreamedResponse`) berhalaman 1.000 baris lewat `lazyById`, tanpa memuat
  semua ke memori; pelindung injeksi rumus CSV; satu baris `audit_logs` aksi `diekspor`.
- **Koreksi pembacaan.** Studio memanggil `PUT /api/calibrations/{c}` (bukan Kelola Data). Kelola
  Data hanya menampilkan tombol yang membuka layar koreksi.

## 6. Format kanonik Lembar Hitung

### 6.1 Prinsip

1. Satu dokumen JSON per versi, diserialisasi `JsonKanonik` (kunci terurut byte, tanpa spasi),
   sha256 atas hasil serialisasi.
2. **Setiap angka adalah string desimal** (`"7.95"`, `"1e-9"` tidak boleh; tulis `"0.000000001"`)
   supaya tidak ada float yang dinormalkan database atau JSON. Diubah ke float di **satu** tempat
   (`PengikatMasukan`/pembangun lembar) dengan `(float)` yang sama dengan `json_decode`.
3. Urutan daftar bermakna (urutan sheet, urutan baris komponen); urutan kunci objek tidak.
4. Sel terikat (diisi pengikat) **tidak boleh** punya isi di `sel`.
5. Teks sel tidak boleh memuat nama/alamat pelanggan (BR-13).

### 6.2 Skema (ringkas)

```
LembarHitung {
  format: "sidik.lembar-hitung"            // tetap
  format_versi: 1                          // naik bila skema ini berubah (lewat PR)
  kode_formula: string                     // = CalibrationProfile::kodeFormula()
  kode_profil: string                      // = CalibrationProfile::kode()
  mode_iterasi: "per_titik" | "per_sesi"
  titik_maks: string|null                  // angka sebagai string; lebih → titik ditahan
  satuan_hasil: string
  asal: { jenis: "ditulis_dari_profil_php" | "impor_xlsm" | "salinan_versi" | "tulis_tangan",
          rujukan_kode: [string], workbook: {nama, sha256} | null, catatan: string|null }
  lembar: [ { nama: string, sel: { "<A1>": Sel } } ]
  nama:   [ { nama: string, rujuk: "<Sheet>!$A$1[:$B$2]" } ]
  masukan: [ Pengikat ]
  keluaran: Keluaran
  aturan_tahan: [ { sel: "<Sheet>!A1", syarat: null | "> 0" | ">= 0", lingkup: "titik"|"sesi", pesan: string } ]
  catatan_penyimpangan: [ { kode, nilai: "<Sheet>!A1"|null, pesan: string, sumber: {jenis, nilai} } ]
  jejak: [ { sumber: string, keterangan: string } ]       // baris jejak bertemplate di type_b_components
}

Sel = { rumus: "=..." } | { angka: "<desimal>" } | { teks: string }
      + opsional { label, satuan, format, tampilan: bool, larik: bool, asal_master: string, catatan }

Pengikat = { kunci, rentang: "<Sheet>!C3[:E3]" | null, mulai: "<Sheet>!D2" | null,
             sumber: SumberPengikat, wajib: bool, pesan_kosong: string|null,
             nama_kolom: { "<kolom sumber>": "<nama range>" } | null, maks_baris: string|null }

SumberPengikat =
  { jenis: "pembacaan", peran_sensor, sensor_ke?: string, tahap?: string, urut: "pembacaan_ke", jumlah: "3" }
| { jenis: "titik", atribut: "titik_ukur" | "titik_ke" }
| { jenis: "spesifikasi", jalur: "volumetric.kelas" }
| { jenis: "sesi", atribut: "tanggal_kalibrasi" | "suhu_awal" | ... }
| { jenis: "acuan", tabel: "volumetric", jalur: "koreksi_suhu.kalibrator", kolom: [..] }
| { jenis: "standar", atribut: "..." }
| { jenis: "resolver", nama: "volumetric.cmc_terdekat", argumen: { ... SumberPengikat ... } }

Keluaran = {
  titik: { rata_rata, error, koreksi, standar_deviasi, jumlah_pengulangan, type_a, cmc, toleransi? : "<Sheet>!A1" },
  komponen: { rentang: "<Sheet>!B20:L27",
              kolom: { keterangan, u_diperluas, pembagi, u, ci, vi, sumber, distribusi : "<huruf kolom>" },
              templat_keterangan: string },
  agregat: { uc, veff, k, u_hitung, u95, keputusan : "<Sheet>!A1" }   // WAJIB; dari blok bawaan (ADR-49-08)
}
```

Templat teks memakai `{<Sheet>!A1}`, `{<Sheet>!A1:%.12g}`, `{titik_ke}`, `{agregat.<kunci>:%.12g}`,
`{u_hitung:%.12g}`, `{u95:%.12g}`. Format `%.12g` sama dengan `sprintf` di profil hari ini, supaya
teks jejak bisa identik. `agregat.<kunci>` dibaca dari sel keluaran `agregat` lembar (yang terbit),
bukan dari pengawas.

**Blok agregasi bawaan.** Templat setiap Lembar Hitung baru memuat blok ini (contoh lengkap §6.5
`BUDGET!C30:C36`, tabel komponen `B20:L27`, konstanta `k_tetap` dan `toleransi`). Validator statis
menolak pengajuan bila keluaran `agregat` tidak terikat. Lab boleh menyunting rumusnya di versi
berikutnya; pengawas (ADR-49-08 butir 3) melaporkan setiap beda dari `GumCalculator`.

| Kunci `agregat` | Rumus bawaan (setara) | Kode pengawas |
|---|---|---|
| `uc` | `SQRT(SUM(H))`, `H = (ui·ci)²` | `GumCalculator.php:554-557` |
| penyebut WS | `SUM(J)`, `J = IF(AND(vi>0, ui·ci<>0), (ui·ci)⁴/vi, 0)` | L561-566 |
| `veff` | `IF(penyebut>0, MIN(SUM(H)^2/penyebut, 999999999999), NA())` | L568-570 |
| `k` | `IF(ISNA(k_tetap), IF(ISNA(veff), 2, SIDIK.KFAKTOR(veff)), k_tetap)` | L596-599 |
| `u_hitung` | `k*uc` | L605 |
| `u95` | `IF(ISNA(cmc), u_hitung, MAX(u_hitung, cmc))` | L475; Volumetric `VolumetricGlasswareProfile.php:367` |
| `keputusan` | `IF(OR(ISNA(toleransi), toleransi<=0), NA(), IF(ABS(error)+u95<=toleransi, "PASS", "FAIL"))` | L888-895, dipanggil dengan U sesudah lantai (L302) |

`SIDIK.KFAKTOR(x)` menjalankan `max(1.0, floor($x))` lalu `StudentTDistribution::quantile(0.975, …)`,
ekspresi yang sama dengan `GumCalculator.php:598`, jadi `k` lembar identik bit dengan pengawas
(spike S0a, §6.4 butir 3; `TINV` mesin ditolak). Keluaran `keputusan` `#N/A` = tidak ada pernyataan
kesesuaian.

**Aturan masukan kosong.** Pengikat `wajib` yang tidak berisi → titik/sesi ditahan **sebelum**
evaluasi dengan `pesan_kosong`. Pengikat tidak wajib yang kosong diisi galat `#N/A`, **bukan** sel
kosong, supaya tidak pernah dibaca nol oleh aritmetika (Excel membaca sel kosong sebagai 0).
Keluaran opsional (`cmc`, `toleransi`, `k_tetap`) yang bernilai `#N/A` dibaca "tidak ada", bukan
galat (FR-STU-104); contoh §6.5: alat di luar pita lampiran → resolver CMC kosong → tanpa lantai,
sama dengan profil.

### 6.3 Alamat dan nama

- Gaya A1, nama sheet `[A-Z0-9_]{1,31}` (huruf besar, tanpa spasi) supaya rujukan tidak perlu
  tanda kutip; nama asli master disimpan di `asal_master`.
- Named range lingkup buku, `[a-z][a-z0-9_]{0,63}`, tidak boleh berbentuk alamat sel (`c3`) atau
  nama fungsi.
- Rentang Data Acuan **wajib** dirujuk lewat nama yang dibuat pengikat (`nama_kolom`), karena
  jumlah barisnya ikut versi Data Acuan. Pengikat mengisi baris ke bawah dari `mulai` dan
  mendefinisikan nama sepanjang data; area di bawahnya sampai `maks_baris` wajib kosong.

### 6.4 Daftar putih fungsi

Diambil dari fungsi yang dipakai master (riset D: IF, ISERROR, VLOOKUP, ABS, IFNA, AVERAGE, MIN,
SQRT, STDEV, MATCH, INDEX, AND, MAX, IFERROR, SUM, DATE/YEAR/MONTH/DAY, TINV, EXP, FORECAST, ROUND,
CONCATENATE) ditambah pelengkap yang tidak berisiko. Semuanya ada di tabel fungsi PhpSpreadsheet
5.10.0 (`FunctionArray.php`, mis. `TINV` L2423, `VLOOKUP` L2568, `STDEV` L2202, `FORECAST` L947).

| Kelompok | Fungsi | Catatan |
|---|---|---|
| Logika | `IF`, `IFERROR`, `IFNA`, `AND`, `OR`, `NOT`, `TRUE`, `FALSE` | `IFERROR(…,"")` di hulu keluaran → galat statis |
| Informasi | `ISERROR`, `ISNA`, `ISNUMBER`, `ISBLANK`, `NA` | `NA()` untuk menahan dengan sengaja |
| Matematika | `ABS`, `SQRT`, `EXP`, `LN`, `LOG10`, `POWER`, `PI`, `SUM`, `SUMPRODUCT` | `PI()` boleh; master Volumetric memakai literal 3,14 dan itu ditiru apa adanya |
| Statistik | `AVERAGE`, `MIN`, `MAX`, `COUNT`, `FORECAST`, `SIDIK.STDEV`, `SIDIK.KFAKTOR` | `SIDIK.STDEV(rentang)` = simpangan baku sampel dua lintasan, `SIDIK.KFAKTOR(veff)` = k 95% dari `StudentTDistribution`; keduanya fungsi terdaftar yang memanggil ekspresi PHP yang sama dengan profil/pengawas (spike S0a, butir 1 dan 3 di bawah). `k` ikut jalur terbit lewat blok agregasi (ADR-49-08, K-49-07) |
| Cari & rujuk | `VLOOKUP`, `HLOOKUP`, `INDEX`, `MATCH` | cocok persis (argumen terakhir 0/FALSE) disarankan; cocok perkiraan diizinkan dengan peringatan |
| Tanggal | `DATE`, `YEAR`, `MONTH`, `DAY`, `DAYS` | tanggal hanya dari pengikat (tanpa `NOW`/`TODAY`) |
| Teks | `CONCATENATE`, `&`, `TEXT` | `TEXT` hanya di sel tampilan |
| Pembulatan | `ROUND`, `ROUNDUP`, `ROUNDDOWN`, `TRUNC`, `MROUND` | hanya sel `tampilan` tanpa hilir keluaran (FR-STU-109) |
| **Ditolak** | `NOW`, `TODAY`, `OFFSET`, `INDIRECT`, `RAND`, `RANDBETWEEN`, `RANDARRAY`, `CELL`, `INFO`, `WEBSERVICE`, `FILTERXML`, `ENCODEURL`, `HYPERLINK`, fungsi makro/UDF, semua yang tidak tercantum | FR-STU-106 |
| **Ditolak sesudah spike S0a** | `STDEV`, `STDEV.S` (rumus satu lintasan, butir 1), `TINV`, `T.INV.2T` (waktu tumbuh linear terhadap derajat bebas, butir 3) | Pengganti `SIDIK.STDEV`, `SIDIK.KFAKTOR`; tempel/impor dari Excel: Q-49-15 |

Operator: `+ - * / ^ & = <> < <= > >=`, persen tidak didukung. **Jebakan yang wajib diuji:**
negasi tunggal di Excel mengikat lebih kuat dari pangkat (`=-2^2` = 4), di PHP sebaliknya
(`-2 ** 2` = -4). Lembar yang ditulis dari PHP harus memakai kurung eksplisit.

**Hasil spike S0a** (10 Okt 2026; `tests/Unit/Spike/MesinRumusSpikeTest.php`, 6/6 hijau; laporan
lengkap ditulis bila env `SPIKE_LAPOR` berisi path, salinan
`_agents_workspace/studio-data-acuan/03-spike-S0a-lapor.md`; path vendor di bawah disingkat
`PhpSpreadsheet/` = `vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/`). Lembar uji INPUT +
PERHITUNGAN + TABEL dibangun dari program dengan urutan operasi PHP — **bukan** contoh §6.5 — untuk
3 sesi Fixed: Pipet Volume 1 mL (contoh master/seeder) dan dua sesi rekaan untuk kasus tepi, Labu
Ukur 100 mL kelas A (massa besar) dan Picnometer 50 mL (veff 2,02) — bukan sesi tersimpan di
database (L112-151). Tiap sesi 116 sel keluaran: 114 diadu bit per bit ke PHP, 2 sel
`ROUND` diadu ke `round()` PHP, ditambah 5 angka cetak/keputusan lewat fungsi render sertifikat.
Tiga varian: (A) simpangan baku ditulis dua lintasan eksplisit + `TINV`; (B) `STDEV` + `TINV`
bawaan; (C) `SIDIK.STDEV` + `SIDIK.KFAKTOR`. **Varian C identik bit di 116 sel × 3 sesi**, angka
cetak dan keputusan sama. Jawaban per pertanyaan tepi:

1. **`AVERAGE`, `SUM`, `STDEV`.** `AVERAGE` (2 dan 3 sel) dan `SUM` (8 sel) **identik bit** dengan
   PHP di 3 sesi (varian A: semua sel selain `k`, `U`, dan `U95` Picnometer identik bit).
   `STDEV`/`STDEV.S` mesin **tidak**: rumus satu lintasan `(n·Σx² − (Σx)²) / (n(n−1))`
   (`PhpSpreadsheet/Calculation/Statistical/Variances.php:36-46`; `STDEV.S` memanggil fungsi yang
   sama, `PhpSpreadsheet/Calculation/FunctionArray.php:2207-2209`) lawan dua lintasan PHP
   (`VolumetricGlasswareCalculator.php:660-669`). Varian B: `stdev_v20` beda 8,3·10⁻⁹ / 1,7·10⁻⁸ /
   3,1·10⁻¹¹ relatif (Pipet / Labu / Picnometer) dan merambat ke `uc`, penyebut WS, `veff`, `U`
   sampai 6,7·10⁻⁸ — angka cetak masih sama, tetapi melewati gerbang 1·10⁻¹². Data terkondisi buruk
   `100,0001; 100,0002; 100,0003`: 9,9985827805·10⁻⁵ lawan 9,9999999996·10⁻⁵ (1,4·10⁻⁴ relatif).
   **Keputusan:** `STDEV`/`STDEV.S` ditolak; pengganti `SIDIK.STDEV` (hanya nilai int/float,
   `#DIV/0!` bila n < 2; L84-97). Beda bentuk n < 2: `stdev()` PHP memulangkan 0,0 dan pemanggil
   wajib menolak lebih dulu (`VolumetricGlasswareCalculator.php:655-664`), sedangkan `SIDIK.STDEV`
   memulangkan galat yang menahan titik (FR-STU-104) — diperiksa sebagai syarat 4 paritas di
   ST-T-100/101.
2. **`^` lawan `**`.** Sama: mesin mengevaluasi `^` dengan operator `**` PHP
   (`PhpSpreadsheet/Calculation/Calculation.php:2654-2655`; bentuk larik L2610-2611), dan sel
   berpangkat (ρ air, `(ui·ci)²`, `(ui·ci)⁴/vi`, `veff`) identik bit di 3 sesi. Jebakan
   negasi-pangkat di atas tidak diuji spike; test-nya bagian ST-T-090.
3. **`TINV` lawan `StudentTDistribution`.** Selisih relatif ≤ 5,5·10⁻¹³ untuk df 1–277, 2,0·10⁻¹¹
   pada df 10⁵, 1,05·10⁻¹⁰ pada df 10⁶; df pecahan dipotong ke bawah (`TINV(0,05; 9,7)` = df 9);
   terhadap bentuk tertutup df 1 dan 2 keduanya ≤ 2,1·10⁻¹⁵ (L225-268). Di 3 sesi (varian A) `k`
   beda 3,1·10⁻¹³ / 4,2·10⁻¹⁴ / 1,2·10⁻¹⁵ relatif, angka cetak sama. **Waktunya yang
   menggugurkan:** 19,88 ms (df 10⁴), 181,94 ms (10⁵), 2.162,91 ms (10⁶), lawan 3,6–4,5 ms PHP —
   CDF mesin mengulang ±n/2 kali per panggilan
   (`PhpSpreadsheet/Calculation/Statistical/Distributions/StudentT.php:96`) di dalam Newton–Raphson
   ≤ 256 iterasi (`PhpSpreadsheet/Calculation/Statistical/Distributions/NewtonRaphson.php:10`). Blok
   bawaan membiarkan `veff` sampai 999.999.999.999 (`GumCalculator.php:54`); pada df sebesar itu
   `TINV` praktis tidak selesai (ekstrapolasi linear ±2·10⁶ s, tidak diukur). **Keputusan (opsi b +
   c draf lama):** blok bawaan memakai `SIDIK.KFAKTOR(veff)` (L99-110), identik bit dengan pengawas;
   `TINV` dan `T.INV.2T` (keduanya `StudentT::inverse`, `FunctionArray.php:2423-2425, 2433-2435`)
   ditolak di lembar. Nama `SIDIK.TKUANTIL(p, df)` di draf lama diganti `SIDIK.KFAKTOR(veff)` (p
   tetap 0,975 seperti `GumCalculator`). Lembar yang ditempel atau diimpor dari Excel: Q-49-15.
4. **Perbandingan teks.** ⏳ Belum terjawab: spike hanya menguji `=` dengan huruf sama
   (`IF(INPUT!B2="A",…)`, L591), tidak menguji beda huruf besar/kecil pada `=` dan `MATCH`.
   Masalahnya tetap: Excel tidak peka huruf besar, `TabelStandarVolumetric::neraca()` membandingkan
   `===`; bila beda, pengikat menormalkan teks atau validator meminta `EXACT()`. Tindak lanjut
   ST-T-090 (test perilaku lawan Excel) + ST-T-094 (pengikat), menunggu S4; per 13 Okt 2026 belum
   dikerjakan.
5. **Sel kosong, `#N/A`, galat lain.** Sebagian terjawab. Galat dipulangkan sebagai string kode
   Excel (`#N/A`, `#DIV/0!`, `#VALUE!`, …; `PhpSpreadsheet/Calculation/Information/ExcelError.php:16-30`);
   `ISERROR`, `IFERROR`, `IFNA`, `_xlfn.IFNA`, dan `VLOOKUP` tak ketemu → `#N/A` berperilaku seperti
   Excel (L275-349). `STDEV(IFERROR(rentang,""))` membaca sel kosong sebagai 0 persis Excel (nol
   hantu Graduated), jadi penolakan `IFERROR(…,"")` di hulu keluaran (FR-STU-104) tetap wajib di
   validator. Usulan deteksi galat: cocokkan dengan `ExcelError::ERROR_CODES`, bukan awalan `#`
   (teks literal bisa berawalan `#`). ⏳ Sisa: bentuk sel kosong di keluaran (`null`/`""`/0), NaN,
   tak hingga, dan perilaku `SIDIK.STDEV` atas sel kosong — tindak lanjut ST-T-090 + ST-T-095 (aturan
   tahan), menunggu S4; per 13 Okt 2026 belum diuji.
6. **Larik.** Terjawab: `INDEX/MATCH(MIN(ABS(…)))` benar di mode biasa dan mode larik
   (`setFormulaAttributes(['t' => 'array', …])` + `RETURN_ARRAY_AS_ARRAY`, L756-759), termasuk seri →
   yang pertama (L313-314). Di lembar uji, sel `titik_kal_*` dan `cmc_nominal` ditulis sebagai larik
   (L577-584, 602-603, 657-658) dan identik bit di 3 sesi. Atribut `larik: true` format kanonik
   (§6.1) dipetakan ke `setFormulaAttributes`. Pola `MATCH(TRUE, ABS(…)<ε, 0)` di contoh §6.5 belum
   diuji spike; dibuktikan saat contoh §6.5 dievaluasi (DoD ST-T-095).
7. **Cache dan siklus.** ⏳ Tidak diuji spike: spike membangun `Spreadsheet` baru tiap evaluasi lalu
   `disconnectWorksheets()`, dan tidak menguji `cyclicFormulaCount = 0` maupun pembersihan cache
   antar-evaluasi dengan masukan berubah. Tindak lanjut ST-T-090 (DoD: siklik 0, cache dibersihkan
   per evaluasi) + test NFR-STU-02, menunggu S4; per 13 Okt 2026 belum dikerjakan.

Fungsi daftar putih lain yang diuji spike (`IF`, `ISERROR`, `VLOOKUP` persis dan hampiran, `ABS`,
`MIN`, `MAX`, `SQRT`, `MATCH` 0 dan 1, `INDEX`, `AND`, `EXP`, `FORECAST`, `DATE`/`YEAR`/`MONTH`/`DAY`,
`CONCATENATE`, named range lintas sheet, `ROUND` 2,345 → 2,35, 1,005 → 1,01, 0,285 → 0,29, −2,5 → −3)
cocok dengan harapan Excel ≤ 1·10⁻¹² relatif: 37 dari 38 kasus di mode biasa dan mode larik; satu
yang tidak cocok adalah `STDEV` terkondisi buruk (butir 1) (L275-349).

### 6.5 Contoh lengkap: Volumetric Fixed (`pipet_volume`), versi 1 ditulis dari PHP

Rantai mengikuti `VolumetricGlasswareCalculator` dan `VolumetricGlasswareProfile` hari ini, yang
sudah identik dengan cache CSV master (V20 Fixed 1,0042575664928188 dan uc pipet 1 ml
0,000508808998503895 selisih 0, `04-paritas-volumetric.md`). Urutan operasi setiap rumus disalin
dari PHP supaya hasilnya bisa identik bit. `asal_master` diambil dari docblock kode yang sudah diadu
ke master; **peta sel final menunggu `.xlsm` asli** (CSV bergeser 1–3 baris). Pertanyaan V-1 s.d.
V-4 (`pertanyaan-lab-studio-acuan.md`) belum dijawab, jadi versi 1 meniru kode; jawaban Lab masuk
sebagai versi 2 lewat simulasi dan pengesahan.

Tiga profil Fixed (`labu_ukur`, `pipet_volume`, `picnometer`) memakai rantai yang sama; masing-masing
punya rumus sendiri (`gum-volumetric-<kode>`, `VolumetricGlasswareProfile.php:111-114`).

Untuk keterbacaan contoh ini diformat dengan spasi; bentuk tersimpan adalah serialisasi kanonik
tanpa spasi dengan kunci terurut.

```json
{
  "format": "sidik.lembar-hitung",
  "format_versi": 1,
  "kode_formula": "gum-volumetric-pipet_volume",
  "kode_profil": "pipet_volume",
  "mode_iterasi": "per_titik",
  "titik_maks": "1",
  "satuan_hasil": "ml",
  "asal": {
    "jenis": "ditulis_dari_profil_php",
    "rujukan_kode": [
      "app/Services/Calibration/VolumetricGlasswareCalculator.php",
      "app/Services/Calibration/Profiles/VolumetricGlasswareProfile.php",
      "app/Services/Calibration/Profiles/FixedVolumetricGlasswareProfile.php",
      "app/Services/Calibration/TabelStandarVolumetric.php"
    ],
    "workbook": null,
    "catatan": "Versi 1 untuk gerbang paritas. Commit sumber dicatat di sumber_metode saat diajukan."
  },

  "lembar": [
    {
      "nama": "MASUKAN",
      "sel": {
        "A1": { "teks": "MASUKAN — diisi server dari sesi; tidak bisa diketik" },
        "C2": { "teks": "Ulangan 1" }, "D2": { "teks": "Ulangan 2" }, "E2": { "teks": "Ulangan 3" },
        "B3": { "teks": "Berat wadah kosong", "satuan": "g" },
        "B4": { "teks": "Berat wadah berisi air suling", "satuan": "g" },
        "B5": { "teks": "Suhu air (bacaan)", "satuan": "°C" },
        "B7": { "teks": "Nominal", "satuan": "ml" },
        "B8": { "teks": "Kelas (A/B)" },
        "B9": { "teks": "Toleransi / batas galat", "satuan": "ml" },
        "B11": { "teks": "Neraca yang dipakai" },
        "C12": { "teks": "Awal" }, "D12": { "teks": "Akhir" },
        "B13": { "teks": "Suhu ruang", "satuan": "°C" },
        "B14": { "teks": "Kelembaban", "satuan": "%RH" },
        "B15": { "teks": "Tekanan udara", "satuan": "hPa" },
        "B17": { "teks": "CMC pita terdekat kapasitas", "satuan": "ml" }
      }
    },
    {
      "nama": "ACUAN",
      "sel": {
        "A1": { "teks": "Diameter ISO 4787: batas galat (ml) → diameter maks (mm)" },
        "D1": { "teks": "Kalibrator suhu: titik, koreksi, U95 (°C)" },
        "H1": { "teks": "Sensor PRT: titik, koreksi (°C)" },
        "J2": { "teks": "U95 termometer (°C)" },
        "J3": { "teks": "U95 sensor (°C)" },
        "M1": { "teks": "Neraca keluarga Fixed: nama, merk/tipe, serial, U95 g, resolusi g, stdev g" }
      }
    },
    {
      "nama": "KONSTANTA",
      "sel": {
        "B3":  { "teks": "ρ anak timbangan" },
        "C3":  { "angka": "7.95", "satuan": "g/ml", "asal_master": "PERHITUNGAN!H58", "catatan": "V-1: lembar pemilik menampilkan 8" },
        "B4":  { "teks": "γ kelas A (borosilicate 3.3)" },
        "C4":  { "angka": "0.0000099", "satuan": "/°C" },
        "B5":  { "teks": "γ kelas B (borosilicate 5.0)" },
        "C5":  { "angka": "0.000015", "satuan": "/°C" },
        "B6":  { "teks": "Suhu acuan" },
        "C6":  { "angka": "20.0", "satuan": "°C" },
        "B7":  { "teks": "U densitas air (Fixed)" },
        "C7":  { "angka": "0.00005", "satuan": "g/ml", "asal_master": "=0,05/1000 (pertanyaan-lab-volumetric no. 10)" },
        "B8":  { "teks": "n pembagi stdev neraca" },
        "C8":  { "angka": "10", "asal_master": "PERHITUNGAN_U95%!C16" },
        "B9":  { "teks": "Jumlah ulangan" },
        "C9":  { "angka": "3" },
        "B10": { "teks": "Tanda ci muai (Fixed)" },
        "C10": { "angka": "1", "catatan": "pertanyaan-lab-volumetric no. 5" },
        "B11": { "teks": "Tanaka s" },   "C11": { "angka": "0.99997495" },
        "B12": { "teks": "Tanaka a1" },  "C12": { "angka": "-3.983035" },
        "B13": { "teks": "Tanaka a2" },  "C13": { "angka": "301.797" },
        "B14": { "teks": "Tanaka a3" },  "C14": { "angka": "522528.9" },
        "B15": { "teks": "Tanaka a4" },  "C15": { "angka": "69.34881" },
        "B16": { "teks": "Tebal garis meniskus" },
        "C16": { "angka": "0.1", "satuan": "mm", "asal_master": "PERHITUNGAN_U95%!B27:B32" },
        "B17": { "teks": "π (literal master, bukan PI())" },
        "C17": { "angka": "3.14", "asal_master": "PERHITUNGAN_U95%!B27:B32" },
        "B18": { "teks": "k tetap (kosong = t-Student dari veff)" },
        "C18": { "rumus": "=NA()", "catatan": "profil Volumetric tidak menimpa faktorCakupanTetap()" },
        "B19": { "teks": "Toleransi PASS/FAIL (kosong = tanpa pernyataan kesesuaian)" },
        "C19": { "rumus": "=NA()", "catatan": "VolumetricGlasswareProfile::punyaToleransi() = false" }
      }
    },
    {
      "nama": "PERHITUNGAN",
      "sel": {
        "B3":  { "teks": "Massa air" , "satuan": "g" },
        "C3":  { "rumus": "=MASUKAN!C4-MASUKAN!C3" },
        "D3":  { "rumus": "=MASUKAN!D4-MASUKAN!D3" },
        "E3":  { "rumus": "=MASUKAN!E4-MASUKAN!E3" },

        "B4":  { "teks": "Indeks titik kalibrator terdekat" },
        "C4":  { "rumus": "=MATCH(MIN(ABS(kal_titik-MASUKAN!C5)),ABS(kal_titik-MASUKAN!C5),0)", "larik": true },
        "D4":  { "rumus": "=MATCH(MIN(ABS(kal_titik-MASUKAN!D5)),ABS(kal_titik-MASUKAN!D5),0)", "larik": true },
        "E4":  { "rumus": "=MATCH(MIN(ABS(kal_titik-MASUKAN!E5)),ABS(kal_titik-MASUKAN!E5),0)", "larik": true },

        "B5":  { "teks": "Titik kalibrator terpilih", "satuan": "°C" },
        "C5":  { "rumus": "=INDEX(kal_titik,C4)" },
        "D5":  { "rumus": "=INDEX(kal_titik,D4)" },
        "E5":  { "rumus": "=INDEX(kal_titik,E4)" },

        "B6":  { "teks": "Koreksi kalibrator", "satuan": "°C" },
        "C6":  { "rumus": "=INDEX(kal_koreksi,C4)" },
        "D6":  { "rumus": "=INDEX(kal_koreksi,D4)" },
        "E6":  { "rumus": "=INDEX(kal_koreksi,E4)" },

        "B7":  { "teks": "Koreksi sensor PRT (titik yang sama)", "satuan": "°C" },
        "C7":  { "rumus": "=INDEX(prt_koreksi,MATCH(TRUE,ABS(prt_titik-C5)<0.000000001,0))", "larik": true },
        "D7":  { "rumus": "=INDEX(prt_koreksi,MATCH(TRUE,ABS(prt_titik-D5)<0.000000001,0))", "larik": true },
        "E7":  { "rumus": "=INDEX(prt_koreksi,MATCH(TRUE,ABS(prt_titik-E5)<0.000000001,0))", "larik": true },

        "B8":  { "teks": "Suhu air terkoreksi", "satuan": "°C" },
        "C8":  { "rumus": "=MASUKAN!C5+C6+C7" },
        "D8":  { "rumus": "=MASUKAN!D5+D6+D7" },
        "E8":  { "rumus": "=MASUKAN!E5+E6+E7" },

        "B9":  { "teks": "ρ air suling (Tanaka)", "satuan": "g/ml", "asal_master": "PERHITUNGAN!H45" },
        "C9":  { "rumus": "=tanaka_s*(1-(((C8+tanaka_a1)^2)*(C8+tanaka_a2))/(tanaka_a3*(C8+tanaka_a4)))" },
        "D9":  { "rumus": "=tanaka_s*(1-(((D8+tanaka_a1)^2)*(D8+tanaka_a2))/(tanaka_a3*(D8+tanaka_a4)))" },
        "E9":  { "rumus": "=tanaka_s*(1-(((E8+tanaka_a1)^2)*(E8+tanaka_a2))/(tanaka_a3*(E8+tanaka_a4)))" },

        "B11": { "teks": "Suhu ruang rata-rata", "satuan": "°C", "asal_master": "PERHITUNGAN!G16" },
        "C11": { "rumus": "=(MASUKAN!C13+MASUKAN!D13)/2" },
        "B12": { "teks": "Kelembaban rata-rata", "satuan": "%RH", "asal_master": "PERHITUNGAN!G17" },
        "C12": { "rumus": "=(MASUKAN!C14+MASUKAN!D14)/2" },
        "B13": { "teks": "Tekanan rata-rata", "satuan": "hPa", "asal_master": "PERHITUNGAN!G19" },
        "C13": { "rumus": "=(MASUKAN!C15+MASUKAN!D15)/2" },
        "B14": { "teks": "ρ udara", "satuan": "g/ml", "asal_master": "PERHITUNGAN!H57" },
        "C14": { "rumus": "=((0.34848*C13)-(0.009*C12)*EXP(0.061*C11))/(C11+273.15)/1000" },
        "B15": { "teks": "γ koefisien muai", "satuan": "/°C" },
        "C15": { "rumus": "=IF(MASUKAN!C8=\"A\",gamma_a,IF(MASUKAN!C8=\"B\",gamma_b,NA()))" },

        "B17": { "teks": "V20 per ulangan", "satuan": "ml", "asal_master": "PERHITUNGAN!H60" },
        "C17": { "rumus": "=C3*(1/(C9-rho_u)*(1-(rho_u/rho_at)*(1-(gamma*(C8-suhu_acuan)))))" },
        "D17": { "rumus": "=D3*(1/(D9-rho_u)*(1-(rho_u/rho_at)*(1-(gamma*(D8-suhu_acuan)))))" },
        "E17": { "rumus": "=E3*(1/(E9-rho_u)*(1-(rho_u/rho_at)*(1-(gamma*(E8-suhu_acuan)))))" },

        "B19": { "teks": "Massa rata-rata", "satuan": "g", "asal_master": "PERHITUNGAN!N29" },
        "C19": { "rumus": "=AVERAGE(C3:E3)" },
        "B20": { "teks": "Suhu air rata-rata", "satuan": "°C", "asal_master": "PERHITUNGAN!N35" },
        "C20": { "rumus": "=AVERAGE(C8:E8)" },
        "B21": { "teks": "ρ air rata-rata", "satuan": "g/ml", "asal_master": "PERHITUNGAN!N45" },
        "C21": { "rumus": "=AVERAGE(C9:E9)" },
        "B22": { "teks": "V20 (dari rata-rata) — terbit", "satuan": "ml", "asal_master": "PERHITUNGAN!H60" },
        "C22": { "rumus": "=m*(1/(rho_w-rho_u)*(1-(rho_u/rho_at)*(1-(gamma*(t_w-suhu_acuan)))))" },
        "B23": { "teks": "Deviasi (V20 − nominal)", "satuan": "ml" },
        "C23": { "rumus": "=C22-MASUKAN!C7" },
        "B24": { "teks": "Stdev V20 per ulangan", "satuan": "ml" },
        "C24": { "rumus": "=SIDIK.STDEV(C17:E17)" },
        "B25": { "teks": "Rentang suhu air (Tmax − Tmin)", "satuan": "°C" },
        "C25": { "rumus": "=MAX(C8:E8)-MIN(C8:E8)" },
        "B26": { "teks": "Koreksi (= −deviasi)", "satuan": "ml" },
        "C26": { "rumus": "=-C23" }
      }
    },
    {
      "nama": "BUDGET",
      "sel": {
        "A1":  { "teks": "CALIBRATION BUDGET UNCERTAINTY" },
        "B3":  { "teks": "Utimb — resolusi neraca/√3", "satuan": "g", "asal_master": "PERHITUNGAN_U95%" },
        "C3":  { "rumus": "=INDEX(neraca_resolusi,MATCH(MASUKAN!C11,neraca_nama,0))/SQRT(3)" },
        "B4":  { "teks": "Urepeat neraca — stdev/√n", "satuan": "g", "asal_master": "PERHITUNGAN_U95%!C16" },
        "C4":  { "rumus": "=INDEX(neraca_stdev,MATCH(MASUKAN!C11,neraca_nama,0))/SQRT(n_stdev_neraca)" },
        "B5":  { "teks": "Umassa = √(Utimb² + Urepeat²)", "satuan": "g" },
        "C5":  { "rumus": "=SQRT(C3^2+C4^2)" },
        "B6":  { "teks": "Utherm — U95 termometer/2", "satuan": "°C" },
        "C6":  { "rumus": "=u95_termometer/2" },
        "B7":  { "teks": "Usensor — U95 sensor/2", "satuan": "°C" },
        "C7":  { "rumus": "=u95_sensor/2" },
        "B8":  { "teks": "Ut-water — (Tmax − Tmin)/(2√3)", "satuan": "°C" },
        "C8":  { "rumus": "=PERHITUNGAN!C25/(2*SQRT(3))" },
        "B9":  { "teks": "Utemp = √(Utherm² + Usensor² + Ut-water²)", "satuan": "°C", "asal_master": "PERHITUNGAN_U95%!H25" },
        "C9":  { "rumus": "=SQRT(C6^2+C7^2+C8^2)" },
        "B10": { "teks": "Diameter maks ISO 4787 (cocok persis)", "satuan": "mm" },
        "C10": { "rumus": "=INDEX(diam_maks,MATCH(TRUE,ABS(diam_batas-MASUKAN!C9)<0.000000001,0))", "larik": true },
        "B11": { "teks": "Umeniscus = (0,1/2)·(3,14·d²/4)/1000", "satuan": "ml", "asal_master": "PERHITUNGAN_U95%!B27:B32" },
        "C11": { "rumus": "=((tebal_garis/2)*((pi_master*(C10^2))/4))/1000" },
        "B12": { "teks": "Urepeat pengukuran — stdev V20/√3", "satuan": "ml" },
        "C12": { "rumus": "=PERHITUNGAN!C24/SQRT(n_ulangan)" },
        "B14": { "teks": "Faktor muai 1 − γ(t − 20)" },
        "C14": { "rumus": "=1-(gamma*(t_w-suhu_acuan))" },
        "B15": { "teks": "ci ρ" },
        "C15": { "rumus": "=m*((rho_at-rho_w)/(rho_at*((rho_w-rho_u)^2)))*faktor_muai" },

        "B19": { "teks": "Komponen" }, "C19": { "teks": "U" }, "D19": { "teks": "Pembagi" },
        "E19": { "teks": "ui" }, "F19": { "teks": "ci" }, "G19": { "teks": "ui·ci" },
        "H19": { "teks": "(ui·ci)²" }, "I19": { "teks": "vi" }, "J19": { "teks": "(ui·ci)⁴/vi" },
        "K19": { "teks": "sumber" }, "L19": { "teks": "distribusi" },

        "B20": { "teks": "Weight of Destillate Water" },
        "C20": { "rumus": "=C5" }, "D20": { "angka": "2" }, "E20": { "rumus": "=C20/D20" },
        "F20": { "rumus": "=((rho_at-rho_u)/(rho_at*(rho_w-rho_u)))*faktor_muai" },
        "G20": { "rumus": "=E20*F20" }, "H20": { "rumus": "=G20^2" }, "I20": { "angka": "18" }, "J20": { "rumus": "=IF(AND(I20>0,G20<>0),G20^4/I20,0)" },
        "K20": { "teks": "volumetric_weight_of_destillate_water" }, "L20": { "teks": "normal" },

        "B21": { "teks": "Density of Air" },
        "C21": { "rumus": "=0.1*rho_u" }, "D21": { "rumus": "=SQRT(3)" }, "E21": { "rumus": "=C21/D21" },
        "F21": { "rumus": "=ci_rho" },
        "G21": { "rumus": "=E21*F21" }, "H21": { "rumus": "=G21^2" }, "I21": { "angka": "50" }, "J21": { "rumus": "=IF(AND(I21>0,G21<>0),G21^4/I21,0)" },
        "K21": { "teks": "volumetric_density_of_air" }, "L21": { "teks": "rectangular" },

        "B22": { "teks": "Density of Destillate Water" },
        "C22": { "rumus": "=u_rho_air" }, "D22": { "angka": "2" }, "E22": { "rumus": "=C22/D22" },
        "F22": { "rumus": "=-ci_rho" },
        "G22": { "rumus": "=E22*F22" }, "H22": { "rumus": "=G22^2" }, "I22": { "angka": "60" }, "J22": { "rumus": "=IF(AND(I22>0,G22<>0),G22^4/I22,0)" },
        "K22": { "teks": "volumetric_density_of_destillate_water" }, "L22": { "teks": "normal" },

        "B23": { "teks": "Density of Weight Standard" },
        "C23": { "rumus": "=0.1*rho_at" }, "D23": { "rumus": "=SQRT(3)" }, "E23": { "rumus": "=C23/D23" },
        "F23": { "rumus": "=m*rho_u/(rho_at^2*(rho_w-rho_u))*faktor_muai" },
        "G23": { "rumus": "=E23*F23" }, "H23": { "rumus": "=G23^2" }, "I23": { "angka": "50" }, "J23": { "rumus": "=IF(AND(I23>0,G23<>0),G23^4/I23,0)" },
        "K23": { "teks": "volumetric_density_of_weight_standard" }, "L23": { "teks": "rectangular" },

        "B24": { "teks": "Temperature of Destillate Water" },
        "C24": { "rumus": "=C9" }, "D24": { "angka": "2" }, "E24": { "rumus": "=C24/D24" },
        "F24": { "rumus": "=((-m)*gamma*(rho_at-rho_u)*(t_w-suhu_acuan)/(rho_at*(rho_w-rho_u)))" },
        "G24": { "rumus": "=E24*F24" }, "H24": { "rumus": "=G24^2" }, "I24": { "angka": "60" }, "J24": { "rumus": "=IF(AND(I24>0,G24<>0),G24^4/I24,0)" },
        "K24": { "teks": "volumetric_temperature_of_destillate_water" }, "L24": { "teks": "normal" },

        "B25": { "teks": "Expansion Coefficient of Material" },
        "C25": { "rumus": "=0.1*gamma" }, "D25": { "rumus": "=SQRT(3)" }, "E25": { "rumus": "=C25/D25" },
        "F25": { "rumus": "=tanda_ci_muai*(m*(rho_at-rho_u))/(rho_at*(rho_w-rho_u))" },
        "G25": { "rumus": "=E25*F25" }, "H25": { "rumus": "=G25^2" }, "I25": { "angka": "40" }, "J25": { "rumus": "=IF(AND(I25>0,G25<>0),G25^4/I25,0)" },
        "K25": { "teks": "volumetric_expansion_coefficient_of_material" }, "L25": { "teks": "rectangular" },

        "B26": { "teks": "Meniscus" },
        "C26": { "rumus": "=C11" }, "D26": { "rumus": "=SQRT(3)" }, "E26": { "rumus": "=C26/D26" },
        "F26": { "angka": "1" },
        "G26": { "rumus": "=E26*F26" }, "H26": { "rumus": "=G26^2" }, "I26": { "angka": "50" }, "J26": { "rumus": "=IF(AND(I26>0,G26<>0),G26^4/I26,0)" },
        "K26": { "teks": "volumetric_meniscus" }, "L26": { "teks": "rectangular" },

        "B27": { "teks": "Repeated measurements" },
        "C27": { "rumus": "=C12" }, "D27": { "angka": "1" }, "E27": { "rumus": "=C27/D27" },
        "F27": { "angka": "1" },
        "G27": { "rumus": "=E27*F27" }, "H27": { "rumus": "=G27^2" }, "I27": { "angka": "2" }, "J27": { "rumus": "=IF(AND(I27>0,G27<>0),G27^4/I27,0)" },
        "K27": { "teks": "volumetric_repeated_measurements" }, "L27": { "teks": "normal (tipe A)" },

        "A29": { "teks": "BLOK AGREGASI BAWAAN (K-49-07) — setara GumCalculator; diawasi GumCalculator" },
        "B30": { "teks": "uc = √Σ(ui·ci)²" },
        "C30": { "rumus": "=SQRT(SUM(H20:H27))" },
        "B31": { "teks": "Penyebut Welch–Satterthwaite Σ(ui·ci)⁴/vi" },
        "C31": { "rumus": "=SUM(J20:J27)" },
        "B32": { "teks": "veff" },
        "C32": { "rumus": "=IF(C31>0,MIN(SUM(H20:H27)^2/C31,999999999999),NA())" },
        "B33": { "teks": "k = t(0,975; max(1, ⌊veff⌋)) atau k tetap" },
        "C33": { "rumus": "=IF(ISNA(k_tetap),IF(ISNA(C32),2,SIDIK.KFAKTOR(C32)),k_tetap)" },
        "B34": { "teks": "U hitung = k·uc" },
        "C34": { "rumus": "=C33*C30" },
        "B35": { "teks": "U95% terbit = max(U hitung, CMC)" },
        "C35": { "rumus": "=IF(ISNA(MASUKAN!C17),C34,MAX(C34,MASUKAN!C17))" },
        "B36": { "teks": "Keputusan (|error| + U95 ≤ toleransi)" },
        "C36": { "rumus": "=IF(ISNA(toleransi),NA(),IF(toleransi<=0,NA(),IF(ABS(PERHITUNGAN!C23)+C35<=toleransi,\"PASS\",\"FAIL\")))" },
        "B38": { "teks": "veff cara master (penyebut baris terakhir K43) — pembanding catatan" },
        "C38": { "rumus": "=C30^4/J27", "tampilan": true, "asal_master": "PERHITUNGAN U95%!K45" }
      }
    }
  ],

  "nama": [
    { "nama": "rho_at",          "rujuk": "KONSTANTA!$C$3" },
    { "nama": "gamma_a",         "rujuk": "KONSTANTA!$C$4" },
    { "nama": "gamma_b",         "rujuk": "KONSTANTA!$C$5" },
    { "nama": "suhu_acuan",      "rujuk": "KONSTANTA!$C$6" },
    { "nama": "u_rho_air",       "rujuk": "KONSTANTA!$C$7" },
    { "nama": "n_stdev_neraca",  "rujuk": "KONSTANTA!$C$8" },
    { "nama": "n_ulangan",       "rujuk": "KONSTANTA!$C$9" },
    { "nama": "tanda_ci_muai",   "rujuk": "KONSTANTA!$C$10" },
    { "nama": "tanaka_s",        "rujuk": "KONSTANTA!$C$11" },
    { "nama": "tanaka_a1",       "rujuk": "KONSTANTA!$C$12" },
    { "nama": "tanaka_a2",       "rujuk": "KONSTANTA!$C$13" },
    { "nama": "tanaka_a3",       "rujuk": "KONSTANTA!$C$14" },
    { "nama": "tanaka_a4",       "rujuk": "KONSTANTA!$C$15" },
    { "nama": "tebal_garis",     "rujuk": "KONSTANTA!$C$16" },
    { "nama": "pi_master",       "rujuk": "KONSTANTA!$C$17" },
    { "nama": "k_tetap",         "rujuk": "KONSTANTA!$C$18" },
    { "nama": "toleransi",       "rujuk": "KONSTANTA!$C$19" },
    { "nama": "rho_u",           "rujuk": "PERHITUNGAN!$C$14" },
    { "nama": "gamma",           "rujuk": "PERHITUNGAN!$C$15" },
    { "nama": "m",               "rujuk": "PERHITUNGAN!$C$19" },
    { "nama": "t_w",             "rujuk": "PERHITUNGAN!$C$20" },
    { "nama": "rho_w",           "rujuk": "PERHITUNGAN!$C$21" },
    { "nama": "faktor_muai",     "rujuk": "BUDGET!$C$14" },
    { "nama": "ci_rho",          "rujuk": "BUDGET!$C$15" }
  ],

  "masukan": [
    { "kunci": "kosong",   "rentang": "MASUKAN!C3:E3", "wajib": true,
      "sumber": { "jenis": "pembacaan", "peran_sensor": "vol_kosong", "urut": "pembacaan_ke", "jumlah": "3" },
      "pesan_kosong": "Titik {titik_ke}: deret berat kosong harus tepat 3 angka." },
    { "kunci": "isi",      "rentang": "MASUKAN!C4:E4", "wajib": true,
      "sumber": { "jenis": "pembacaan", "peran_sensor": "vol_isi", "urut": "pembacaan_ke", "jumlah": "3" },
      "pesan_kosong": "Titik {titik_ke}: deret berat berisi harus tepat 3 angka." },
    { "kunci": "suhu",     "rentang": "MASUKAN!C5:E5", "wajib": true,
      "sumber": { "jenis": "pembacaan", "peran_sensor": "vol_suhu", "urut": "pembacaan_ke", "jumlah": "3" },
      "pesan_kosong": "Titik {titik_ke}: deret suhu harus tepat 3 angka." },
    { "kunci": "nominal",  "rentang": "MASUKAN!C7", "wajib": true,
      "sumber": { "jenis": "titik", "atribut": "titik_ukur" } },
    { "kunci": "kelas",    "rentang": "MASUKAN!C8", "wajib": true,
      "sumber": { "jenis": "spesifikasi", "jalur": "volumetric.kelas" },
      "pesan_kosong": "Sesi Volumetric belum punya kelas alat (A/B)." },
    { "kunci": "toleransi", "rentang": "MASUKAN!C9", "wajib": true,
      "sumber": { "jenis": "spesifikasi", "jalur": "volumetric.toleransi_ml" },
      "pesan_kosong": "Toleransi alat belum diisi; ketidakpastian meniskus Fixed lahir dari situ." },
    { "kunci": "neraca",   "rentang": "MASUKAN!C11", "wajib": true,
      "sumber": { "jenis": "spesifikasi", "jalur": "volumetric.neraca" } },
    { "kunci": "suhu_awal", "rentang": "MASUKAN!C13", "wajib": true, "sumber": { "jenis": "sesi", "atribut": "suhu_awal" },
      "pesan_kosong": "Kondisi lingkungan belum lengkap (suhu, kelembaban, tekanan udara — awal & akhir)." },
    { "kunci": "suhu_akhir", "rentang": "MASUKAN!D13", "wajib": true, "sumber": { "jenis": "sesi", "atribut": "suhu_akhir" },
      "pesan_kosong": "Kondisi lingkungan belum lengkap (suhu, kelembaban, tekanan udara — awal & akhir)." },
    { "kunci": "rh_awal",  "rentang": "MASUKAN!C14", "wajib": true, "sumber": { "jenis": "sesi", "atribut": "kelembaban_awal" },
      "pesan_kosong": "Kondisi lingkungan belum lengkap (suhu, kelembaban, tekanan udara — awal & akhir)." },
    { "kunci": "rh_akhir", "rentang": "MASUKAN!D14", "wajib": true, "sumber": { "jenis": "sesi", "atribut": "kelembaban_akhir" },
      "pesan_kosong": "Kondisi lingkungan belum lengkap (suhu, kelembaban, tekanan udara — awal & akhir)." },
    { "kunci": "p_awal",   "rentang": "MASUKAN!C15", "wajib": true, "sumber": { "jenis": "sesi", "atribut": "tekanan_awal" },
      "pesan_kosong": "Kondisi lingkungan belum lengkap (suhu, kelembaban, tekanan udara — awal & akhir)." },
    { "kunci": "p_akhir",  "rentang": "MASUKAN!D15", "wajib": true, "sumber": { "jenis": "sesi", "atribut": "tekanan_akhir" },
      "pesan_kosong": "Kondisi lingkungan belum lengkap (suhu, kelembaban, tekanan udara — awal & akhir)." },
    { "kunci": "cmc",      "rentang": "MASUKAN!C17", "wajib": false,
      "sumber": { "jenis": "resolver", "nama": "volumetric.cmc_terdekat",
                  "argumen": { "kapasitas": { "jenis": "spesifikasi", "jalur": "volumetric.kapasitas_ml",
                                              "cadangan": { "jenis": "titik", "atribut": "titik_ukur" } } } } },

    { "kunci": "diameter_iso4787", "mulai": "ACUAN!A2", "maks_baris": "200", "wajib": true,
      "sumber": { "jenis": "acuan", "tabel": "volumetric", "jalur": "diameter_iso4787", "kolom": ["batas_galat_ml", "diameter_maks_mm"] },
      "nama_kolom": { "batas_galat_ml": "diam_batas", "diameter_maks_mm": "diam_maks" } },
    { "kunci": "kalibrator", "mulai": "ACUAN!D2", "maks_baris": "200", "wajib": true,
      "sumber": { "jenis": "acuan", "tabel": "volumetric", "jalur": "koreksi_suhu.kalibrator", "kolom": ["titik_c", "koreksi_c", "u95_c"] },
      "nama_kolom": { "titik_c": "kal_titik", "koreksi_c": "kal_koreksi", "u95_c": "kal_u95" } },
    { "kunci": "sensor_prt", "mulai": "ACUAN!H2", "maks_baris": "200", "wajib": true,
      "sumber": { "jenis": "acuan", "tabel": "volumetric", "jalur": "koreksi_suhu.sensor_prt", "kolom": ["titik_c", "koreksi_c"] },
      "nama_kolom": { "titik_c": "prt_titik", "koreksi_c": "prt_koreksi" } },
    { "kunci": "u95_termometer", "rentang": "ACUAN!K2", "wajib": true,
      "sumber": { "jenis": "acuan", "tabel": "volumetric", "jalur": "koreksi_suhu.u95.termometer_c" },
      "nama_kolom": { "nilai": "u95_termometer" } },
    { "kunci": "u95_sensor", "rentang": "ACUAN!K3", "wajib": true,
      "sumber": { "jenis": "acuan", "tabel": "volumetric", "jalur": "koreksi_suhu.u95.sensor_c" },
      "nama_kolom": { "nilai": "u95_sensor" } },
    { "kunci": "neraca_fixed", "mulai": "ACUAN!M2", "maks_baris": "20", "wajib": true,
      "sumber": { "jenis": "acuan", "tabel": "volumetric", "jalur": "neraca.fixed",
                  "kolom": ["nama", "merk_type", "serial", "u95_g", "resolusi_g", "stdev_g"] },
      "nama_kolom": { "nama": "neraca_nama", "resolusi_g": "neraca_resolusi", "stdev_g": "neraca_stdev", "u95_g": "neraca_u95" } }
  ],

  "keluaran": {
    "titik": {
      "rata_rata": "PERHITUNGAN!C22",
      "error": "PERHITUNGAN!C23",
      "koreksi": "PERHITUNGAN!C26",
      "standar_deviasi": "PERHITUNGAN!C24",
      "jumlah_pengulangan": "KONSTANTA!C9",
      "type_a": "BUDGET!C12",
      "cmc": "MASUKAN!C17",
      "toleransi": "KONSTANTA!C19"
    },
    "komponen": {
      "rentang": "BUDGET!B20:L27",
      "kolom": { "keterangan": "B", "u_diperluas": "C", "pembagi": "D", "u": "E", "ci": "F", "vi": "I",
                 "sumber": "K", "distribusi": "L" },
      "templat_keterangan": "{keterangan} — U {u_diperluas:%.12g} ÷ {pembagi:%.12g}"
    },
    "k_tetap": null,
    "agregat": { "uc": "BUDGET!C30", "veff": "BUDGET!C32", "k": "BUDGET!C33", "u_hitung": "BUDGET!C34",
                 "u95": "BUDGET!C35", "keputusan": "BUDGET!C36" }
  },

  "aturan_tahan": [
    { "sel": "PERHITUNGAN!C15", "syarat": null, "lingkup": "sesi",
      "pesan": "Kelas alat \"{MASUKAN!C8}\" bukan A atau B. Koefisien muai cuma dipetakan untuk dua kelas itu, dan γ yang salah menggeser seluruh V20 tanpa error — pilih kelasnya dulu." },
    { "sel": "BUDGET!C3", "syarat": null, "lingkup": "sesi",
      "pesan": "Neraca \"{MASUKAN!C11}\" bukan neraca workbook Fixed. Neraca ketiga beda fisik antar keluarga (Fujitsu di Fixed, Precisa di Graduated), jadi tidak dicocokkan lintas keluarga." },
    { "sel": "BUDGET!C10", "syarat": null, "lingkup": "sesi",
      "pesan": "Toleransi {MASUKAN!C9} mL tidak ada di tabel diameter ISO 4787. Master memakai pencocokan PERSIS (`VLOOKUP(..., 0)` → #N/A); ketidakpastian meniskus tidak bisa dihitung dari diameter tetangga." },
    { "sel": "PERHITUNGAN!C3", "syarat": "> 0", "lingkup": "titik",
      "pesan": "Titik ke-{titik_ke} ulangan 1: berat berisi air ({MASUKAN!C4} g) tidak lebih besar dari berat kosong ({MASUKAN!C3} g) — kemungkinan kedua deret tertukar." },
    { "sel": "PERHITUNGAN!D3", "syarat": "> 0", "lingkup": "titik",
      "pesan": "Titik ke-{titik_ke} ulangan 2: berat berisi air ({MASUKAN!D4} g) tidak lebih besar dari berat kosong ({MASUKAN!D3} g) — kemungkinan kedua deret tertukar." },
    { "sel": "PERHITUNGAN!E3", "syarat": "> 0", "lingkup": "titik",
      "pesan": "Titik ke-{titik_ke} ulangan 3: berat berisi air ({MASUKAN!E4} g) tidak lebih besar dari berat kosong ({MASUKAN!E3} g) — kemungkinan kedua deret tertukar." },
    { "sel": "PERHITUNGAN!C7", "syarat": null, "lingkup": "titik",
      "pesan": "Titik ke-{titik_ke} ulangan 1: suhu {MASUKAN!C5} °C tidak punya koreksi sensor di tabel standar." },
    { "sel": "PERHITUNGAN!D7", "syarat": null, "lingkup": "titik",
      "pesan": "Titik ke-{titik_ke} ulangan 2: suhu {MASUKAN!D5} °C tidak punya koreksi sensor di tabel standar." },
    { "sel": "PERHITUNGAN!E7", "syarat": null, "lingkup": "titik",
      "pesan": "Titik ke-{titik_ke} ulangan 3: suhu {MASUKAN!E5} °C tidak punya koreksi sensor di tabel standar." }
  ],

  "catatan_penyimpangan": [
    { "kode": "volumetric_veff_dibagi_jumlah",
      "nilai": "BUDGET!C38",
      "sumber": { "jenis": "sel_master", "nilai": "PERHITUNGAN U95%!K43, K45 (docs/pertanyaan-lab-volumetric.md no. 1)" },
      "pesan": "Derajat kebebasan efektif dihitung {agregat.derajat_kebebasan_efektif:%.12g} (Welch–Satterthwaite, penyebut = JUMLAH (ui·ci)⁴/vi seluruh komponen). Workbook Fixed `PERHITUNGAN U95%!K45` membagi dengan `K43` — baris terakhir saja — sehingga menulis {nilai:%.12g} dan k-nya mendekati 1,96. Kerusakan salin-tempel (docs/pertanyaan-lab-volumetric.md no. 1); dihitung benar, k {agregat.faktor_cakupan_k:%.12g}, U hitung {u_hitung:%.12g} mL — lebih besar dari angka master." }
  ],

  "jejak": [
    { "sumber": "jejak_titik",
      "keterangan": "ρ udara {PERHITUNGAN!C14:%.12g} g/mL (T {PERHITUNGAN!C11:%.12g} °C · RH {PERHITUNGAN!C12:%.12g} % · P {PERHITUNGAN!C13:%.12g} hPa) · kelas {MASUKAN!C8} γ {PERHITUNGAN!C15:%.12g} /°C · neraca {MASUKAN!C11} (u timbang {BUDGET!C3:%.12g} g) · massa per ulangan {PERHITUNGAN!C3:E3:%.12g} g · suhu terkoreksi {PERHITUNGAN!C8:E8:%.12g} °C · ρ air per ulangan {PERHITUNGAN!C9:E9:%.12g} g/mL · V20 per ulangan {PERHITUNGAN!C17:E17:%.12g} mL · lantai CMC {cmc_jejak} pada kapasitas {kapasitas:%.12g} mL · U95% terbit {u95:%.12g} mL" }
  ]
}
```

Catatan atas contoh:
- `C24` memakai `SIDIK.STDEV` dan `C33` memakai `SIDIK.KFAKTOR`, bukan `STDEV`/`TINV` bawaan mesin
  (spike S0a, §6.4 butir 1 dan 3). Spike membuktikan rantai yang setara di lembar uji program, bukan
  contoh ini; paritas contoh ini sendiri dibuktikan di ST-T-095 dan ST-T-100.
- Urutan operasi `F24` sengaja `((-m)*…)`: PHP menulis `-$r3 * $r8 * …` dengan negasi pada `$r3`
  saja (`VolumetricGlasswareCalculator.php:227`); kurung eksplisit menghindari jebakan
  negasi-pangkat Excel walau di sini tidak ada pangkat.
- Blok agregasi `C30:C36` adalah blok bawaan (ADR-49-08) dan **yang terbit**. Urutan operasinya
  disalin dari `GumCalculator::agregasiBudget()`: veff `SUM(H)^2/penyebut` sama dengan
  `$jumlahKuadrat ** 2 / $penyebutWs` (L569), kolom `J` bersyarat sama dengan L563, `k` dan lantai
  sama dengan L596-599 dan L475. `C38` (tampilan) ditulis `C30^4/J27` supaya sama dengan
  pembanding profil (`VolumetricGlasswareCalculator.php:587-597`), dan hanya dipakai sebagai
  `nilai` catatan penyimpangan.
- Versi 1 **tidak** meniru penyebut veff master `K43`; kalau Lab memutuskan meniru master, `C31`
  diubah menjadi `=J27` di versi baru lewat simulasi + pengesahan + jawaban
  `pertanyaan-lab-volumetric.md` no. 1, dan pengawas akan melaporkan `agregasi_beda_dari_gum` di
  setiap sesi sampai itu dicabut.
- Pengikat `resolver` `volumetric.cmc_terdekat` membungkus `pitaKemampuan()` + `cmcTerdekat()`
  (`VolumetricGlasswareProfile.php:517-559`) dan `kapasitasUntukCmc()` Fixed (kapasitas, cadangan
  nominal, `FixedVolumetricGlasswareProfile.php:55-58`). Resolver ada karena pita CMC dibaca dari
  database dengan lingkup organisasi, yang tidak bisa ditulis sebagai rumus.
- `{cmc_jejak}` dan `{kapasitas}` diisi evaluator dari hasil resolver, dengan format kalimat yang
  sama dengan `VolumetricGlasswareProfile.php:498`.

### 6.6 Pemetaan ke istilah lembar pemilik

Dari riset D (CSV `Volumetric_Glassware_2026/Fixed_Volumetric_Glassware_2026/PERHITUNGAN_U95pct.csv`
= "CALIBRATION BUDGET UNCERTAINTY"). Dikonfirmasi ulang begitu `.xlsm` asli tersedia.

| Istilah lembar pemilik | Sel contoh di atas | Kode PHP |
|---|---|---|
| Utimb | `BUDGET!C3` | `$uTimbang`, `VolumetricGlasswareCalculator.php:408` |
| Urepeat (neraca) | `BUDGET!C4` | bagian `stdev_g/√10`, L422 |
| Umassa | `BUDGET!C5` | `$uMassa`, L422 |
| Utherm, Usensor | `BUDGET!C6`, `C7` | `uSuhu()`, L305-308 |
| Ut-water | `BUDGET!C8` | `rentang/(2√3)` di `uSuhu()` |
| Utemp | `BUDGET!C9` | `uSuhu()` |
| Umeniscus | `BUDGET!C11` | `meniskusFixed()`, L254-260 |
| Urepeat (pengukuran) | `BUDGET!C12` | `stdev_v20/√3`, L579 |
| tabel U / divisor / ui / ci / ui·ci / (ui·ci)² / (ui·ci)⁴/vi / vi | `BUDGET!B20:L27` | `komponenBudget()`, L205-242 |
| veff, k = TINV(0,05; veff), U = k·uc | `BUDGET!C30:C34` (blok agregasi bawaan, terbit) | `GumCalculator::agregasiBudget()` (pengawas, K-49-07) |
| CMC (INDEX/MATCH + VLOOKUP CMC) + lantai MAX | `MASUKAN!C17` (resolver) + `BUDGET!C35` | `cmcTerdekat()` + `max()` L367 (pengawas) |

## 7. Alur evaluasi

### 7.1 Satu pintu jalur hitung

```
PemilihJalurHitung::untuk(CalibrationSession $sesi, ?FormulaVersion $paksa = null)
  versi := $paksa ?? RumusKalibrasi::versiUntukSesi($sesi)          // per tanggal_kalibrasi
  jika versi.sumber == kode     → JalurProfilPhp (perilaku hari ini, tidak berubah)
  jika versi.sumber == database → JalurLembarHitung(versi)

Dipanggil oleh: CalibrationController store/update/preview, HitungUlangSesi, CalibrationValidator,
PembandingParitas, JalankanSimulasi. Tidak ada pemanggil lain yang boleh memanggil evaluator langsung.
```

Pratinjau draf memakai `$paksa = draf` tanpa menyimpan.

### 7.2 Langkah `JalurLembarHitung`

```
1. Muat LembarHitung terkompilasi untuk (versi.id, isi_sha256)       // cache, §9
2. PenentuVersiAcuan::jalankan($sesi, function () {                  // lingkup Data Acuan berstempel (P1)
3.   Bentuk grup: per_titik → tiap titik; per_sesi → satu grup        // dari App\Support\*Mentah profil
4.   Untuk tiap grup:
       a. PengikatMasukan mengisi nilai: pembacaan, titik, spesifikasi, sesi, acuan (lewat Tabel* yang
          sama dengan profil), standar, resolver. Wajib kosong → tahan (pesan_kosong). Tidak wajib
          kosong → #N/A.
       b. MesinRumus::evaluasi(lembar, isi, diminta = keluaran + agregat + aturan_tahan + sel jejak)
       c. Aturan tahan: sel galat/kosong/syarat gagal → titik/sesi ditahan (alasan dari templat)
       d. Keluaran angka galat/kosong/teks/NaN/INF → tahan otomatis (alasan menyebut alamat);
          keluaran opsional (cmc, toleransi, k_tetap, keputusan) #N/A = "tidak ada"
       e. komponen := baris B20:L27 → [{sumber, keterangan(templat), distribusi, u, ci, vi, u_diperluas, pembagi}]
       f. agregat := sel keluaran `agregat` lembar {uc, veff, k, u_hitung, u95, keputusan}       // YANG TERBIT (K-49-07)
       g. pengawas := GumCalculator::agregasiBudget(komponen → {u, ci, vi}, k_tetap)
                      + lantai max(U, cmc ?? 0) + GumCalculator::keputusan(error, u95, toleransi)  // PENGAWAS
       h. Bandingkan agregat dengan pengawas menurut PRD §5 syarat 1–3 (per kunci, plus angka cetak);
          beda → temuan `agregasi_beda_dari_gum` (PERINGATAN) berisi kunci, nilai lembar, nilai
          pengawas, selisih abs/rel, dan kode catatan penyimpangan yang menjelaskannya (bila ada)
       i. type_b_components := komponen + barisPerbandinganCmc(agregat.u_hitung, cmc)
                               + catatan_penyimpangan + jejak + ringkasan pengawas (bila beda)
5. })
6. Pulangkan bentuk yang SAMA dengan hitungPerGrup() profil: {hitungan: [...], belum_dihitung: [...]}
```

Langkah 6 penting: pemanggil hari ini (store, `HitungUlangSesi` L584, validator) sudah tahu bentuk
`hitungPerGrup()`, jadi jalur Lembar Hitung masuk tanpa mengubah cara penyimpanan
`uncertainty_calculations` (`HitungUlangSesi.php:721-728`).

### 7.3 Jawaban atas "GumCalculator bersama?"

Ya, sebagai **pengawas** (ADR-49-08, K-49-07). Untuk alat ber-Lembar Hitung, angka agregat yang
terbit dibaca dari blok agregasi lembar; `GumCalculator` menghitung ulang u_c, veff, k, U, lantai
CMC, dan keputusan atas komponen yang sama di setiap evaluasi (sesi nyata, pratinjau, simulasi,
paritas, hitung ulang). Selama blok bawaan tidak diubah, hasil keduanya harus identik bit: blok
bawaan memakai `SIDIK.KFAKTOR`, yang memanggil `StudentTDistribution` yang sama dengan pengawas
(spike S0a: nol beda di 116 sel × 3 sesi Volumetric Fixed, §6.4 butir 3). Begitu
Lab mengubah blok itu, setiap sesi yang terdampak membawa PERINGATAN `agregasi_beda_dari_gum` di
jejaknya, dan simulasi versi itu menampilkan selisihnya sebelum disahkan. Baris `perbandingan_cmc`
tetap ditulis supaya gerbang `u95_meledak_dari_cmc` yang ada tetap bekerja.

### 7.4 Pratinjau

`POST /lembar-hitung/versi/{v}/pratinjau` memanggil `PemilihJalurHitung::untuk($sesi, $paksa = draf)`
dan `JalurProfilPhp` untuk sesi yang sama, lalu memuat `uncertainty_calculations` tersimpan.
Pratinjau berjalan sinkron dengan batas ukuran (FR-STU-110) dan batas waktu (NFR-STU-03); lewat
batas → `422 pratinjau_terlalu_berat` dengan saran memakai simulasi.

### 7.5 Simulasi, paritas, bayangan

- `JalankanSimulasi(simulasi_id)` membuat potongan 25 sesi (`JalankanSimulasiPotongan`), tiap potongan
  idempoten (unique §4.3). Sebelum mulai dan sebelum menulis, sha isi target dibandingkan dengan
  `isi_sha256_saat_jalan`; beda → `kedaluwarsa`.
- Paritas = simulasi jenis `paritas` dengan pembanding `JalurProfilPhp` dan definisi PRD §5. Angka
  cetak dibandingkan lewat fungsi render yang sama dengan sertifikat (`desimalSertifikat`,
  `desimalU95`, `desimalFaktorCakupan`).
- **Bayangan (sebelum aktif).** `PemilihJalurHitung` sesudah menyelesaikan jalur PHP untuk sesi
  nyata memeriksa apakah ada simulasi `bayangan` berstatus `jalan` untuk rumus itu. Bila ada,
  kandidat dievaluasi **sesudah transaksi simpan selesai** (job antrean, supaya simpan teknisi tidak
  melambat dan galat kandidat tidak pernah menggagalkan simpan) dan satu baris
  `simulasi_versi_sesi` ditulis atau diganti (unique per sesi; simpan ulang sesi mengganti barisnya).
  Kandidat tidak pernah menstempel apa pun.
- **Pengawasan PHP (sesudah aktif).** Di `JalurLembarHitung` bila `versi.setara_php`, jalankan juga
  `JalurProfilPhp` dan bandingkan. Beda → temuan `paritas_php_beda` di `CalibrationValidator`
  (PERINGATAN, menahan terbit lewat `penahanTerbit`, `CalibrationProfile.php:122`). Hitungan
  terbuka masuk `/api/health`.
- **Pengawas agregasi `GumCalculator` (selalu, K-49-07).** Lain dari pengawasan PHP di atas yang
  hanya untuk versi pertama, pengawas agregasi berjalan untuk **setiap** versi Lembar Hitung di
  setiap evaluasi (§7.2 langkah g–h). Di simulasi dan paritas, ringkasan menambah
  `agregasi_beda_gum` (jumlah sesi yang agregat lembarnya beda dari pengawas) dan baris
  `simulasi_versi_sesi.selisih` memuat kunci agregat yang beda; di sesi nyata menjadi temuan
  validator `agregasi_beda_dari_gum` (PERINGATAN, tidak menahan terbit) yang tampil di jejak sesi
  bersama kode catatan penyimpangan yang menjelaskannya. Pengawas tidak pernah diam: beda tanpa
  catatan penyimpangan bersumber membuat `ajukan` versi itu ditolak (`agregasi_beda_tanpa_catatan`,
  FR-STU-135).

## 8. Kontrak API baru

Semua di `routes/api.php`, middleware `auth:sanctum`, `aplikasi:internal`, `role:` sesuai tabel,
throttle per rute tulis (pola `rateLimiters()`, `AppServiceProvider.php:226-235`). Respons sukses
`{data: …}`; galat `{message, kode, rincian?}` (dan `errors` untuk validasi Laravel). Angka di isi
kanonik dan nilai acuan di-encode `JSON_PRESERVE_ZERO_FRACTION` (pola `kontrak-api.md` §15).

### 8.1 Rute

| Metode & path | Grup `role:` | Izin `MatriksIzin` | Throttle (usulan) | FR |
|---|---|---|---|---|
| `GET /api/kelola-data/tabel` | admin,teknisi,viewer (+SA baca) | `kelola-data.lihat` | — | 030, 032 |
| `GET /api/kelola-data/tabel/{tabel}` | idem | lihat | — | 030 |
| `GET /api/kelola-data/tabel/{tabel}/baris` | idem | lihat | — | 033–037 |
| `GET /api/kelola-data/tabel/{tabel}/baris/{baris}` | idem | lihat | — | 038 |
| `GET /api/kelola-data/tabel/{tabel}/baris/{baris}/riwayat` | idem | lihat | — | 039 |
| `PATCH /api/kelola-data/tabel/{tabel}/baris/{baris}` | admin | `kelola-data.ubah` | `kelola-data-tulis` 60/mnt | 040 |
| `POST /api/kelola-data/tabel/{tabel}/baris` | admin | ubah | `kelola-data-tulis` | 041 |
| `POST /api/kelola-data/tabel/{tabel}/baris/{baris}/arsip` | admin | `kelola-data.arsip` | `kelola-data-tulis` | 042 |
| `POST /api/kelola-data/tabel/{tabel}/baris/{baris}/pulihkan` | admin | arsip | `kelola-data-tulis` | 042 |
| `GET /api/kelola-data/tabel/{tabel}/ekspor` | admin (Q-49-05) | `kelola-data.ekspor` | `kelola-data-ekspor` 5/mnt | 044 |
| `POST /api/data-acuan/paket/{kode}/draf` dst. | sesuai CR-48 03-SDD §3 | `data-acuan.sunting`/`ajukan` | per CR-48 | 072–086 |
| `POST /api/data-acuan/versi/{versi}/sahkan`, `/tarik` | **super_admin** (grup saudara) | `data-acuan.sahkan` | `pengesahan-versi` 10/mnt | 082, 085 |
| `GET /api/lembar-hitung/alat` | admin,viewer (+SA baca) | `lembar-hitung.lihat` | — | 100, 131 |
| `GET /api/lembar-hitung/alat/{kode}/versi` | idem | lihat | — | 127 |
| `GET /api/lembar-hitung/versi/{versi}` | idem | lihat | — | 101 |
| `GET /api/lembar-hitung/versi/{versi}/ketergantungan` | idem | lihat | — | 114 |
| `GET /api/lembar-hitung/versi/{versi}/banding/{pembanding}` | idem | lihat | — | 115 |
| `GET /api/lembar-hitung/versi/{versi}/log-metode` | idem | lihat | — | 127 |
| `POST /api/lembar-hitung/alat/{kode}/draf` | admin (Q-49-01) | `lembar-hitung.sunting` | `lembar-hitung-tulis` 120/mnt | 100 |
| `PATCH /api/lembar-hitung/versi/{versi}/sel` | admin | sunting | `lembar-hitung-tulis` | 111, 112 |
| `PUT /api/lembar-hitung/versi/{versi}/nama` | admin | sunting | `lembar-hitung-tulis` | 101 |
| `PUT /api/lembar-hitung/versi/{versi}/pengikat` | admin | sunting | `lembar-hitung-tulis` | 102, 103 |
| `POST /api/lembar-hitung/versi/{versi}/struktur` | admin | sunting | `lembar-hitung-tulis` | 111 |
| `POST /api/lembar-hitung/versi/{versi}/periksa` | admin | sunting | `lembar-hitung-tulis` | 112 |
| `POST /api/lembar-hitung/versi/{versi}/pratinjau` | admin | sunting | `lembar-hitung-pratinjau` 20/mnt | 113 |
| `POST /api/lembar-hitung/versi/{versi}/simulasi` | admin | sunting | `simulasi-jalan` 5/mnt | 116 |
| `POST /api/lembar-hitung/versi/{versi}/bayangan` · `/bayangan/hentikan` | admin | sunting | `simulasi-jalan` | 119 |
| `POST /api/lembar-hitung/versi/{versi}/paritas` | admin | sunting | `simulasi-jalan` | 118 |
| `GET /api/simulasi/{simulasi}` · `/sesi` | admin,viewer (+SA) | `lembar-hitung.lihat` atau `data-acuan.lihat` | — | 116 |
| `POST /api/lembar-hitung/versi/{versi}/ajukan` | admin | `lembar-hitung.ajukan` | `lembar-hitung-tulis` | 121 |
| `POST /api/lembar-hitung/versi/{versi}/buang` | admin | sunting | `lembar-hitung-tulis` | — |
| `POST /api/lembar-hitung/versi/{versi}/sahkan` | **super_admin** | `lembar-hitung.sahkan` | `pengesahan-versi` | 122 |
| `POST /api/lembar-hitung/versi/{versi}/tolak` · `/kembalikan` · `/tarik` | **super_admin** | sahkan | `pengesahan-versi` | 123 |
| `POST /api/lembar-hitung/impor` (multipart) | admin | `lembar-hitung.impor` | `impor-workbook` 5/jam | 160–168 |
| `GET /api/lembar-hitung/impor/{unggahan}` | admin,viewer (+SA) | lihat | — | 164 |

Rute ber-parameter otomatis disapu `SapuRuteIsolasiOrganisasiTest` (nama parameter baru `tabel`,
`baris`, `kode`, `simulasi`, `unggahan`, `pembanding` ditambahkan ke peta `PARAMETER`-nya, pola P1),
`RuteInternalMenolakRoleLainTest`, dan `BatasAntarLabTest`.

### 8.2 Contoh — Kelola Data

`GET /api/kelola-data/tabel`

```json
{
  "data": [
    { "tabel": "equipments", "label": "Alat", "kelompok": "master", "kelas": "arsip",
      "jumlah_baris": 412, "bisa_tambah": true },
    { "tabel": "uncertainty_calculations", "label": "Hasil hitung ketidakpastian", "kelompok": "transaksi",
      "kelas": "baca_saja", "jumlah_baris": 0, "bisa_tambah": false,
      "alasan_kelas": "Disimpan, tidak dihitung ulang saat dibaca (AGENTS.md §Jalur angka)." }
  ]
}
```

`GET /api/kelola-data/tabel/equipments/baris?halaman=1&per_halaman=100&urut=-updated_at&arsip=tanpa&cari=mettler&saring[equipment_category_id][sama]=4&saring[tanggal_jatuh_tempo][antara]=2026-10-01,2026-10-31`

```json
{
  "data": [
    { "id": 381, "versi_baris": "W/\"6a1f…\"",
      "nilai": { "nama_alat": "Timbangan Analitik", "merk": "Mettler Toledo", "serial": "B0123",
                 "equipment_category_id": { "id": 4, "label": "Massa" },
                 "customer_id": { "id": 77, "label": "PT Contoh Sintetis" },
                 "tanggal_jatuh_tempo": "2026-10-14", "updated_at": "2026-10-02T09:11:04+07:00",
                 "deleted_at": null } }
  ],
  "meta": { "halaman": 1, "per_halaman": 100, "total": 1 }
}
```

`PATCH /api/kelola-data/tabel/equipments/baris/381`
Header `If-Match: W/"6a1f…"`

```json
{ "perubahan": { "serial": "B0124" }, "alasan": "Salah ketik dari label pelat alat, dicek fisik 9 Okt." }
```

200:

```json
{ "data": { "id": 381, "versi_baris": "W/\"9c0d…\"", "nilai": { "serial": "B0124", "…": "…" } },
  "audit_id": 99812 }
```

409:

```json
{ "message": "Baris ini sudah diubah orang lain sejak Anda membukanya.", "kode": "baris_berubah",
  "rincian": { "kolom": { "serial": { "punya_anda": "B0124", "di_server": "B0125" } },
               "diubah_oleh": { "id": 12, "nama": "Rina" }, "diubah_pada": "2026-10-09T10:42:00+07:00",
               "versi_baris": "W/\"77aa…\"" } }
```

`POST /api/kelola-data/tabel/rooms/baris/9/arsip` → `{ "alasan": "Ruang pindah ke gedung B." }` → 200
`{ "data": { "id": 9, "deleted_at": "…" }, "audit_id": … }`.

`GET /api/kelola-data/tabel/equipments/baris/381/riwayat`

```json
{ "data": [
  { "audit_id": 99812, "aksi": "diubah", "pada": "2026-10-09T10:45:12+07:00",
    "oleh": { "id": 12, "nama": "Rina", "peran": "admin" },
    "perubahan": [ { "kolom": "serial", "lama": "B0123", "baru": "B0124" } ],
    "catatan": "Salah ketik dari label pelat alat, dicek fisik 9 Okt." } ] }
```

### 8.3 Contoh — Lembar Hitung

`GET /api/lembar-hitung/alat`

```json
{ "data": [
  { "kode_profil": "pipet_volume", "kode_formula": "gum-volumetric-pipet_volume", "nama": "Pipet Volume",
    "versi_berlaku": { "id": 31, "nomor": 1, "sumber": "kode", "status": "aktif" },
    "lembar_hitung": { "status_paritas": "belum", "draf": 1, "diajukan": 0, "aktif_sumber_database": null } } ] }
```

`status_paritas`: `belum` | `bayangan` | `paritas` | `aktif` (definisi `06-Matriks-Paritas-Alat.md` §1).

`PATCH /api/lembar-hitung/versi/77/sel` Header `If-Match: 14`

```json
{ "perubahan": [
  { "lembar": "KONSTANTA", "alamat": "C3", "isi": { "angka": "7.95" }, "alasan": "V-1 belum dijawab; tetap master." },
  { "lembar": "BUDGET", "alamat": "C38", "isi": { "rumus": "=C30^4/J27", "tampilan": true } },
  { "lembar": "PERHITUNGAN", "alamat": "C40", "isi": { "rumus": "=NOW()" } }
] }
```

200:

```json
{ "data": {
  "lock_versi": 15,
  "diterima": [ "KONSTANTA!C3", "BUDGET!C38", "PERHITUNGAN!C40" ],
  "ditolak": [],
  "galat_statis": [
    { "sel": "PERHITUNGAN!C40", "kode": "fungsi_volatile",
      "pesan": "NOW() tidak boleh dipakai. Ikat sel ke tanggal kalibrasi sesi (pengikat sesi.tanggal_kalibrasi)." } ] } }
```

`ditolak` berisi suntingan yang tidak bisa disimpan sama sekali (mis. menulis sel terikat:
`sel_terikat`, alamat di luar batas: `alamat_tidak_sah`). `galat_statis` tidak menghalangi simpan
draf, tetapi menghalangi ajukan.

`POST /api/lembar-hitung/versi/77/pratinjau`

```json
{ "calibration_session_id": 5012, "sel": [ "BUDGET!C3", "BUDGET!C5", "PERHITUNGAN!C22" ] }
```

200:

```json
{ "data": {
  "sesi": { "id": 5012, "nomor_sesi": "…", "tanggal_kalibrasi": "2026-09-21", "paket_acuan_versi": null },
  "sel": { "BUDGET!C3": "0.0000577350269189626", "BUDGET!C5": "…", "PERHITUNGAN!C22": "1.0042575664928188" },
  "titik": [ {
    "titik_ke": 1,
    "draf":      { "rata_rata": "1.0042575664928188", "u95": "…", "k": "…", "uc": "0.000508808998503895",
                   "cetak": { "rata_rata": "1,0043", "u95": "…", "k": "2" }, "keputusan": null },
    "php":       { "rata_rata": "1.0042575664928188", "u95": "…", "k": "…", "uc": "0.000508808998503895",
                   "cetak": { "rata_rata": "1,0043", "u95": "…", "k": "2" }, "keputusan": null },
    "tersimpan": { "rata_rata": "…", "u95": "…", "cetak": { "…": "…" } },
    "pengawas_gum": { "uc": "0.000508808998503895", "veff": "…", "k": "…", "u_hitung": "…", "u95": "…",
                      "keputusan": null, "beda": [] },
    "beda": [] } ],
  "ditahan": [] } }
```

Angka di respons pratinjau dikirim sebagai **string** (representasi round-trip terpendek), supaya
klien tidak membulatkan diam-diam. `pengawas_gum` = hasil `GumCalculator` atas komponen draf
(ADR-49-08); `pengawas_gum.beda` berisi `{kunci, lembar, pengawas, abs, rel, cetak_berubah}` dan
menjadi PERINGATAN `agregasi_beda_dari_gum` bila draf itu kelak dipakai sesi nyata. Nilai contoh di atas diambil dari `04-paritas-volumetric.md`;
yang bertanda `…` tidak dikutip di sini.

`POST /api/lembar-hitung/versi/77/simulasi` → 202 `{ "data": { "simulasi_id": 501, "status": "antre" } }`

`GET /api/simulasi/501`

```json
{ "data": { "id": 501, "jenis": "dampak", "status": "selesai", "isi_sha256_saat_jalan": "…",
  "ringkasan": { "sesi_total": 14, "vektor_total": 6, "berubah": 14, "cetak_berubah": 3,
                 "terbit_cetak_berubah": 0, "keputusan_berubah": 0, "tertahan_berubah": 0 },
  "mesin": { "nama": "phpoffice/phpspreadsheet", "versi": "5.10.0" } } }
```

`POST /api/lembar-hitung/versi/77/ajukan`

```json
{ "alasan": "Versi 1 Lembar Hitung Pipet Volume, setara profil PHP untuk gerbang paritas.",
  "rujukan": [ { "jenis": "ik", "nilai": "SIDIK-IK-CAL-0510_Rev.7" },
               { "jenis": "sel_master", "nilai": "Fixed_Volumetric_Glassware_2026 PERHITUNGAN_U95%" } ],
  "sumber_metode": { "ik": "SIDIK-IK-CAL-0510", "revisi_ik": "Rev.7",
                     "sel_master": [ "PERHITUNGAN!H45", "PERHITUNGAN!H57", "PERHITUNGAN!H58", "PERHITUNGAN!H60" ],
                     "diputuskan_oleh": "…", "tanggal_keputusan": "2026-…",
                     "catatan": "Ditulis dari profil PHP pada commit …" } }
```

Galat yang mungkin: `galat_statis_ada`, `simulasi_belum_ada`, `simulasi_kedaluwarsa`,
`paritas_belum_lulus`, `sumber_metode_kurang`.

`POST /api/lembar-hitung/versi/77/sahkan` (grup `role:super_admin`)

```json
{ "berlaku_mulai": "2026-11-01", "sandi": "••••••" }
```

200 `{ "data": { "id": 77, "status": "terjadwal", "berlaku_mulai": "2026-11-01", "versi_sebelumnya_ditutup": { "id": 31, "effective_until": "2026-10-31" } } }`.
422 `pengesah_ikut_menyunting` dengan `rincian.peran = ["pembuat", "penyunting"]`.

### 8.4 Contoh — Impor

`POST /api/lembar-hitung/impor` multipart: `berkas` (.xlsm), `kode_profil=pipet_volume`, `sandi` (opsional).
202 `{ "data": { "unggahan_id": 9, "status": "diproses" } }`. Sandi salah → 422
`sandi_workbook_salah` (tanpa menyebut sandinya).

`GET /api/lembar-hitung/impor/9`

```json
{ "data": { "id": 9, "hasil": "draf_dibuat", "formula_version_id": 78, "sha256": "…", "terenkripsi": true,
  "laporan": { "sheet_dibaca": ["INPUT DATA", "PERHITUNGAN", "PERHITUNGAN_U95%", "…"],
               "sheet_dilewati": [ { "sheet": "DATABASE", "alasan": "data_pelanggan" } ],
               "jumlah_sel_rumus": 1070, "jumlah_larik": 7,
               "temuan": [ { "sel": "SENSOR_PT100!C5", "kode": "rujukan_luar", "rumus_asli": "=[4]Sheet1!C5",
                             "usulan": "Ikat ke Data Acuan koreksi_suhu.sensor_prt (sheet lokal FC_Prt_Pt100)." },
                           { "sel": "PERHITUNGAN U95%!K45", "kode": "dugaan_rujukan_meleset",
                             "usulan": "Penyebut veff hanya K43; hitung benar + catatan penyimpangan." } ],
               "hitung_ulang": { "sel_dibanding": 1070, "sama": 1063, "beda": 7 } } } }
```

### 8.5 Perubahan additive pada rute lama

| Rute | Tambahan |
|---|---|
| `GET /api/calibrations/lembar-kerja` | `data.versi_rumus = {kode_formula, nomor, sumber, sha256}`; `ETag` mencakup `versi_acuan` dan `versi_rumus` |
| `GET /api/calibrations/{c}/perhitungan`, `/validasi` | `formula_version = {nomor, sumber, sha256}`; temuan baru `agregasi_beda_dari_gum`, `paritas_php_beda`, `mesin_rumus_berbeda`, `versi_rumus_ditarik` |
| `POST /api/formulas/{formula}/versions` | tetap menolak `sumber: database` (`FormulaController.php:137-148`); Lembar Hitung hanya lewat `/api/lembar-hitung/*`, pesan galat menyebut jalurnya |
| `GET /api/health` | blok `studio` (NFR-STU-13) |
| `GET /api/me/permissions` | izin baru §4 `02-SRS.md` (otomatis dari `MatriksIzin::PETA`) |

### 8.6 Kode galat baru

| Kode | HTTP | Arti |
|---|---|---|
| `tabel_tidak_dikenal` | 404 | tabel tidak di registry atau tersembunyi |
| `baris_tidak_ditemukan` | 404 | tidak ada atau milik organisasi lain |
| `tabel_baca_saja` | 403 | tulis ke tabel baca saja |
| `kolom_tidak_boleh_diubah` | 422 | kolom bukan `bisa_ubah` |
| `alasan_wajib` | 422 | alasan kosong atau < 5 karakter |
| `baris_berubah` | 409 | `If-Match` usang |
| `arsip_tidak_didukung` | 422 | tabel tanpa SoftDeletes |
| `baris_terkunci` | 422 | aturan domain menolak (mis. sesi disetujui) |
| `nilai_tidak_valid` | 422 | validasi/unik/FK |
| `ekspor_terlalu_besar` | 422 | > 50.000 baris |
| `sel_terikat` | 422 (per item) | menulis sel yang diisi pengikat |
| `alamat_tidak_sah` | 422 (per item) | alamat/sheet di luar format |
| `fungsi_tidak_didukung`, `fungsi_volatile`, `rujukan_melingkar`, `rujukan_luar`, `rujukan_rusak`, `pembulatan_di_hulu_keluaran`, `keluaran_tidak_terikat`, `nama_tanpa_definisi`, `kontrak_input_dilanggar`, `lembar_terlalu_besar` | — (galat statis) | FR-STU-105–112, 186 |
| `galat_statis_ada` | 422 | ajukan dengan galat statis |
| `agregasi_beda_tanpa_catatan` | 422 | ajukan versi yang simulasinya menunjukkan agregat beda dari pengawas tanpa catatan penyimpangan bersumber (FR-STU-135) |
| `simulasi_belum_ada`, `simulasi_kedaluwarsa` | 422 | FR-STU-117 |
| `paritas_belum_lulus` | 422 | FR-STU-118 |
| `sumber_metode_kurang` | 422 | FR-STU-121 |
| `pengesah_ikut_menyunting` | 422 | FR-STU-122 |
| `sandi_salah` | 422 | konfirmasi sandi pengesahan |
| `rentang_bentrok` | 422 | `bentrokRentang()` |
| `berlaku_mundur_menyentuh_terbit` | 422 | CR-48 FR-18 |
| `pratinjau_terlalu_berat` | 422 | lewat batas pratinjau sinkron |
| `agregasi_beda_dari_gum` | — (temuan validator PERINGATAN, tidak memblokir) | agregat lembar beda dari pengawas `GumCalculator` (FR-STU-134) |
| `paritas_php_beda` | — (temuan validator PERINGATAN, menahan terbit) | pengawasan PHP versi `setara_php` (FR-STU-119b) |
| `sandi_workbook_salah`, `berkas_bukan_ooxml`, `berkas_terlalu_besar` | 422 | impor |
| `profil_enkripsi_ditolak`, `berkas_rusak`, `gagal_muat` | 422 (saat unggah lahir) | impor; S1b sudah memakainya di keluaran `studio:impor-workbook` (`GagalBacaWorkbook`) |
| `klien_kedaluwarsa` | 426 | versi Studio terlalu lama untuk kontrak API |

## 9. Kinerja

| Bagian | Rancangan | Status |
|---|---|---|
| Lembar terkompilasi | Urai sekali per `(formula_version_id, isi_sha256)`: graf ketergantungan, token rumus, nama; disimpan di cache aplikasi dengan kunci itu (bukan statis per kelas) | ✓ S0a (10 Okt): `Spreadsheet` baru per evaluasi cukup — bangun median 11,82 ms, ±10% dari bangun + evaluasi; templat yang direset tidak dipakai (ADR-49-02). Rancangan cache urai di kolom kiri tidak diubah spike |
| Evaluasi per grup | Isi masukan → evaluasi hanya sel yang diminta beserta hulunya → bersihkan cache mesin | ✓ S0a (10 Okt): lembar Volumetric Fixed 1 titik, 116 sel keluaran — evaluasi median 107,98 ms (maks 126,36), memori tertahan ±239 KiB, puncak ±314 KiB (PHP 8.4.25, `memory_limit` 512M, laptop kerja, bukan Render); ≈26× jalur PHP (4,148 ms) (`MesinRumusSpikeTest.php:352-426`). Target pratinjau p95 ≤ 3 s dipertahankan: 10 titik ±1,2 s (ekstrapolasi linear, diukur ulang di ST-T-098) |
| Simulasi | Antrean, potongan 25 sesi, idempoten, progres di `simulasi_versi`; worker `queue:work` yang ada | ✓ rancangan; antrean **wajib** (S0a: ≈26× PHP). Perkiraan 500 sesi: ±1 menit bila 1 titik/sesi, ±10 menit bila 10 titik/sesi (tepat di batas NFR-STU-03; ekstrapolasi, diukur di ST-T-099) |
| Kelola Data | Paginasi server, urut hanya kolom berindeks atau tabel kecil, `LIKE` hanya kolom `bisa_cari`; ekspor stream `lazyById` | ✓ rancangan |
| Riwayat baris | Index `audit_entitas_idx` sudah ada | ✓ |
| Studio | Grid tervirtualisasi; muat halaman berikut saat gulir; pratinjau tidak dipanggil per ketikan (tombol atau 1,5 s diam) | ⏳ pilihan widget grid — tindak lanjut ST-T-015 (S1), menunggu prototipe grid 2.000 × 20 ≥ 55 fps dan izin PL bila memakai dependensi baru; per 13 Okt 2026 belum diputuskan |

## 10. Keamanan rancangan

- **Kotak pasir rumus.** Daftar putih fungsi (§6.4) ditegakkan sebelum evaluasi; tidak ada `eval`;
  tidak ada fungsi I/O; rujukan luar ditolak; siklus ditolak; batas ukuran (FR-STU-110); evaluasi
  sinkron dibatasi waktu, simulasi berjalan di antrean dengan `--timeout`. Rumus disimpan sebagai
  data dan tidak pernah dijalankan di klien.
- **Kelola Data.** Daftar putih tabel dan kolom; saringan terstruktur; lingkup organisasi wajib per
  definisi; kolom rahasia tidak pernah keluar; tulis hanya lewat model beraudit; tanpa `DELETE`.
- **Impor.** Ukuran berkas dan ukuran terurai dibatasi; XML dibaca lewat pemindai XXE bawaan
  PhpSpreadsheet (`Reader/BaseReader.php:79, 233-240`); batas spin count dekripsi
  (`Reader/Xlsx.php:83-88`); makro tidak dijalankan; sandi tidak pernah ditulis ke mana pun.
- **Pengesahan.** Grup rute saudara `role:super_admin`; konfirmasi sandi; `PemisahanWewenang`.
- **Token Studio.** Ability `internal`; penyimpanan aman platform; dicabut saat logout.
- **Log.** Tidak mencatat sandi, token, isi baris berdata pribadi, atau isi workbook.

## 11. Migrasi per alat: bayangan → paritas → aktif

| Langkah | Isi | Bukti keluar | Status matriks | Pelaku |
|---|---|---|---|---|
| 1 Bahan | Profil PHP + test master alat hijau; `.xlsm` bila ada (Q-49-11); daftar pertanyaan lab terbuka | catatan alat di matriks | `belum` | pembangun API |
| 2 Tulis kandidat v1 | Lembar Hitung dari PHP (urutan operasi disalin), atau impor `.xlsm` lalu disetel ke PHP; penyimpangan master dicatat | draf, nol galat statis, pratinjau satu sesi sama | `belum` | pembangun API |
| 3 Bayangan | `POST …/bayangan`: kandidat beku dievaluasi di belakang setiap sesi nyata baru; PHP tetap menghitung dan menstempel | laporan bayangan N sesi / M minggu tanpa beda (Q-49-03) | `bayangan` | sistem, admin meninjau |
| 4 Paritas | `POST …/paritas` atas sesi tersimpan + vektor + seeder; laporan bayangan ikut dihitung | laporan LULUS 100% | `bayangan` | sistem |
| 5 Pemeriksaan independen | Penjaga-angka menghitung ulang dari master untuk vektor + memeriksa laporan; orang Lab menandatangani (Q-49-02) | catatan di `simulasi_versi.pemeriksa` | `paritas` | penjaga-angka, Lab |
| 6 Ajukan → sahkan | Sumber metode "setara profil PHP commit …"; pengesah orang kedua; `berlaku_mulai` ≥ hari pengesahan | versi `terjadwal`/`aktif`, `setara_php = true` | `paritas` sampai tanggal berlaku | Master Data, super_admin |
| 7 Aktif dengan pengawasan | Lembar Hitung menghitung dan menstempel; PHP pengawas; beda → `paritas_php_beda` menahan terbit | versi berlaku; nol beda terbuka | `aktif` | sistem, admin meninjau |

Versi berikutnya (v2 dst.) boleh mengubah rumus lewat simulasi + pengesahan; pengawasan PHP lepas
saat v2 berlaku.

**Rollback.** Masalah di langkah 7 atau sesudahnya: `tarik` versi Lembar Hitung → versi `sumber = kode`
sebelumnya berlaku lagi untuk sesi baru; sesi belum terbit yang dihitung dengan versi tertarik
ditahan dan dihitung ulang dengan profil PHP; sertifikat terbit tidak berubah. Masalah di langkah
3–5: hentikan bayangan, perbaiki kandidat, mulai ulang; tidak ada sesi yang terdampak karena PHP
tetap jalur hitung. Profil PHP tidak pernah dihapus (ADR-49-04).

## 12. Struktur aplikasi Flutter Studio

Berkas yang sudah ada di `studio/` (S0b, belum commit, bisa berubah) ditandai ✓.

```
sidik-calibration-mobile/
├─ lib/ …                                  paket sidik_calibration (dipakai lewat path)
│   ├─ services/api_client.dart, data_acuan_service.dart, …
│   ├─ providers/data_acuan_provider.dart (impor auth_provider — kopling diputus lewat penimpaan)
│   └─ screens/data_acuan/* (layar M2: daftar paket, studio grid, banding, garis waktu, riwayat sel)
└─ studio/
   ├─ pubspec.yaml ✓                       sidik_studio; sidik_calibration: path ../
   ├─ android/ ✓  (com.ptsidik.studio)      windows/ ✓ (runner sendiri)
   ├─ assets/merek/ikon-studio.png ✓
   ├─ l10n.yaml ✓, lib/l10n/studio_{id,en}.arb ✓
   ├─ lib/
   │  ├─ main.dart ✓, app.dart ✓
   │  ├─ inti/lingkungan_studio.dart ✓, inti/penimpaan.dart ✓ (FCM/push dimatikan)
   │  ├─ tema/warna_studio.dart ✓, tema_studio.dart ✓, font_studio.dart ✓
   │  ├─ layar/cangkang_studio.dart ✓, gerbang_masuk.dart ✓, masuk_screen.dart ✓, seksi_studio.dart ✓
   │  ├─ layar/beranda/beranda_screen.dart
   │  ├─ layar/kelola_data/{daftar_tabel,grid_tabel,detail_baris,riwayat_baris,form_sunting,dialog_arsip,dialog_ekspor,koreksi_pembacaan}_screen.dart
   │  ├─ layar/lembar_hitung/{daftar_alat,editor,pratinjau,banding,paritas,log_metode}_screen.dart
   │  ├─ layar/lembar_hitung/panel/{bilah_rumus,inspektur_sel,nama_range,ketergantungan,pengikat}.dart
   │  ├─ layar/simulasi/laporan_simulasi_screen.dart   (dipakai Data Acuan & Lembar Hitung)
   │  ├─ layar/pengesahan/{antrean,keputusan}_screen.dart
   │  ├─ layar/impor/{unggah,laporan}_screen.dart
   │  ├─ layar/pengaturan/pengaturan_screen.dart
   │  ├─ widget/grid/grid_sel.dart          grid bersama (virtualisasi, seleksi, keyboard, salin/tempel)
   │  ├─ widget/{merek_studio ✓, keadaan_layar, bilah_status, dialog_alasan, dialog_sandi}.dart
   │  └─ data/{kelola_data,lembar_hitung,simulasi,impor}_{model,service,provider}.dart
   └─ test/ (tema_studio_test ✓ …)
```

Aturan: layanan Studio mengikuti pola abstrak + `Api…` + `Mock…` repo mobile; provider Riverpod;
tidak ada logika hitung di klien; angka diterima sebagai string dan hanya diformat untuk tampilan.
Pilihan widget grid (bawaan vs `two_dimensional_scrollables`, dependensi baru yang butuh izin)
⏳ diputuskan di S1 — tindak lanjut ST-T-015, menunggu prototipe grid dan izin PL bila dependensi
baru; per 13 Okt 2026 belum diputuskan.

## 13. Ringkasan spike S0 dan yang masih menunggu

Status per 13 Okt 2026 (revisi ST-T-010). Bukti rinci di bagian yang dirujuk.

| Spike | Status | Jawaban singkat | Bagian dokumen |
|---|---|---|---|
| S0a | **Selesai 10 Okt 2026** (`tests/Unit/Spike/MesinRumusSpikeTest.php` 6/6, Volumetric 41/41, suite SQLite sesudah S0: 5416 test, 5414 lulus, 2 dilewati) | Varian `SIDIK.STDEV` + `SIDIK.KFAKTOR` identik bit di 116 sel × 3 sesi uji (1 contoh seeder + 2 rekaan; bukan sesi tersimpan DB), angka cetak dan keputusan sama; `AVERAGE`/`SUM`/`^` identik bit; `STDEV`/`STDEV.S` mesin satu lintasan (s.d. 6,7·10⁻⁸ relatif di sesi, 1,4·10⁻⁴ di data terkondisi buruk) dan `TINV`/`T.INV.2T` mesin (2.162,91 ms pada df 10⁶) **ditolak**; larik benar; bangun 11,82 ms + evaluasi 107,98 ms per lembar 1 titik, ≈26× PHP, puncak memori ±314 KiB; `composer.json:18` `"^5.10"`, lock 5.10.0 | ADR-49-02, ADR-49-08, §6.4 butir 1–3 dan 6, §6.5, §7.3, §9, NFR-STU-03, PRD §5, R-10 |
| S0a — sisa | ⏳ tindak lanjut S4 | Perbandingan teks peka huruf (§6.4 butir 4) → ST-T-090 + ST-T-094; bentuk sel kosong/NaN/tak hingga di keluaran (butir 5) → ST-T-090 + ST-T-095; siklus `cyclicFormulaCount = 0` dan pembersihan cache antar-evaluasi (butir 7) → ST-T-090 + test NFR-STU-02; batas ukuran lembar (FR-STU-110) → ST-T-093 + ST-T-098; semuanya menunggu S4 | §6.4, FR-STU-105, FR-STU-110 |
| S0a — keputusan pemilik | Pertanyaan baru | Rumus Excel yang ditempel/diimpor memakai `STDEV`/`TINV`: terjemahkan atau tolak (Q-49-15); syarat 1 paritas diperketat jadi identik bit (Q-49-16); `^5.10` lawan patok persis ADR-49-02 (ST-T-004) | PRD §5, FR-STU-160–165, ST-T-131 |
| S0b | **Selesai di tingkat build, 13 Okt 2026** | exe `sidik_studio.exe` ("Sidik Studio") + APK `com.ptsidik.studio` debug (10 Okt); tanpa Google Services/`google-services.json`; FCM diputus `TanpaTokenPush`; `flutter analyze` 0 isu, `flutter test` 28/28; `--flavor` Windows tidak didukung Flutter 3.44.8 → proyek terpisah `studio/` | ADR-49-06, FR-STU-010, FR-STU-017 |
| S0b — sisa | ⏳ tindak lanjut S1 | Folder data terpisah dan pemasangan berdampingan di perangkat belum diuji sesudah build itu → ST-T-020, menunggu uji exe di Windows kantor + APK sideload di tablet uji | ADR-49-06 |
| S0c | ⏳ belum dimulai | Dekripsi `.xlsm` lab (skema Agile?); membaca rumus, larik, nama dari `.xlsm` asli; ukuran/waktu → ST-T-006, menunggu `.xlsm` asli Volumetric + Micrometer dari pemilik (ST-T-008) | ADR-49-10, FR-STU-161–165 |
| S1 (bukan spike) | ⏳ tindak lanjut S1 | Pilihan widget grid (§9, §12) dan tinggi baris grid (`04-UI-UX-Flow.md` §5.3) → ST-T-015 (+ ST-T-020 untuk uji perangkat), menunggu prototipe grid | §9, §12, UI §5.3 |
