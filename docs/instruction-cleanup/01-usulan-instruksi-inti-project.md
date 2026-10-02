# 01 — Usulan instruksi inti project (pengganti `AGENTS.md`)

> **DRAFT, belum berlaku.** `AGENTS.md` aktif tidak diubah. Kalau disetujui, isi di bawah
> garis `--- MULAI ---` menggantikan `AGENTS.md`. Bagian yang dipindah ke panduan ada di
> `03-panduan-khusus.md`. Pemetaan per aturan ada di `04-pemetaan-aturan.md`.
>
> Prinsip: semua aturan yang **mengikat** tetap di sini. Yang dipindah hanya cerita
> kejadian, angka sensus bertanggal, langkah setup sekali jalan, dan rincian yang hanya
> perlu dibaca saat menyentuh area tertentu. Tiap pemindahan meninggalkan satu baris
> penunjuk.
>
> Tanda `[K-I1]` dan sejenisnya menunjuk konflik di `05-konflik-penerapan-rollback.md`.
> Teksnya sengaja dibiarkan sama dengan `AGENTS.md` sampai konflik itu diputuskan.

--- MULAI ---

# Panduan repo — aturan kerja `sidik-calibration-api`

Satu sumber: berkas ini. `CLAUDE.md` hanya berisi `@AGENTS.md`. Jangan dikembar.

Backend REST API kalibrasi alat ukur & sertifikat digital PT Sidik (Laravel 13 +
Filament 5). Klien utamanya repo `sidik-calibration-mobile` dan `sidik-pelanggan-mobile`.
Selain panel `/admin` dan halaman publik Blade, tidak ada frontend web.

Rincian yang hanya perlu dibaca saat menyentuh area tertentu ada di `docs/panduan/`.
Daftarnya ada di §Panduan sesuai kebutuhan, di akhir berkas. Panduan itu **tidak**
diimpor otomatis.

## Perintah

```bash
composer setup                      # install + .env + key + migrate + npm build
composer dev                        # serve + queue:listen + pail + vite
php artisan serve --host=0.0.0.0    # mobile dites dari HP fisik lewat LAN
```

### Test

```bash
php artisan test                                  # SQLite in-memory (phpunit.xml)
php artisan test --filter='NamaTest::nama_metode'
php artisan test -c phpunit.mysql.xml             # suite yang sama di MySQL
```

- **Dua suite wajib hijau sebelum modul disebut selesai.** Produksi memakai MySQL.
  SQLite membaca `decimal(20,8)` sebagai float, MySQL sebagai string. `AUTO_INCREMENT`
  dan strict mode juga beda. Empat bug pernah lolos lewat celah itu (kepala
  `phpunit.mysql.xml`).
- Suite MySQL memakai **database khusus test** dan user yang haknya **dikunci ke database
  itu saja**. `RefreshDatabase` mengosongkan database yang ditunjuknya. Sandi tidak masuk
  repo; pembungkusnya `jalankan-test-mysql.ps1` (gitignored). Setup sekali per mesin:
  `docs/panduan/test-mysql-lokal.md`.
- CI hanya menjalankan suite SQLite. CI hijau tidak membuktikan suite MySQL hijau.
- Durasi suite penuh (24 Sep 2026): MySQL ±3 jam, SQLite ±56 menit.

### Dua jebakan yang membuat hasil test PALSU

1. **Jangan jalankan dua suite sekaligus di satu checkout.** `Storage::fake()` memakai
   satu direktori bersama dan menghapus isinya tiap dipanggil. Gejalanya: test yang tidak
   berhubungan merah, berbeda tiap putaran. Kalau dua suite gagal di test yang berbeda
   tanpa irisan, curigai ini dulu.
2. **Worktree dengan `vendor` symlink menjalankan kode checkout UTAMA.** Laravel 11+
   menyimpulkan base path dari lokasi `ClassLoader`. Salin `vendor` sungguhan, setel
   `APP_BASE_PATH`, atau checkout branch di direktori utama.

### CI dan deploy

CI memakai **PHP 8.4**, bukan 8.3 di `composer.json`. `config/database.php` memakai
`Pdo\Mysql::ATTR_SSL_CA`, kelas yang baru ada di 8.4. `.github/workflows/tes.yml`
menjalankan test lalu mengetuk Deploy Hook Render. Commit merah berhenti di GitHub.

### Format, perintah proyek, generator

- `vendor/bin/pint <berkas yang kamu sentuh>` saja. Dijalankan pada direktori, pint ikut
  merapikan berkas lain dan mengotori diff. `composer ide-helper` untuk regenerasi helper.
- Perintah artisan proyek ada di `app/Console/Commands/`. Yang sering dipakai:
  `kalibrasi:hitung-ulang`, `kalibrasi:uji-profil`, `kalibrasi:sapu-sesi`,
  `kemampuan:pastikan`, `akun:admin`, `akun:super-admin <email>`, `flowmeter:audit-cmc`.
- **Generator dijalankan, hasilnya tidak disunting tangan:**
  `docs/skrip/gen-contoh-lembar-kerja.php`, `docs/skrip/gen-kode-profil-mobile.php`,
  `docs/skrip/gen-tabel-standar-*.py`, `docs/skrip/gen-sesi-*.py`. Menyunting hasilnya
  sudah tiga kali meloloskan bug (TIDS, Timbangan, Micrometer).

## Arsitektur

### Profil kalibrasi — sumbu utama

- Satu jenis alat = satu subclass `CalibrationProfile` di
  `app/Services/Calibration/Profiles/`. Alat baru = satu subclass + satu seeder CMC + satu
  baris di `CalibrationProfileRegistry::daftarProfil()`. Bukan `if (besaran == …)` di kelas
  bersama.
- Pencocokan alat → profil memakai `equipments.nama_alat_kemampuan` (+ `aliasNama()`),
  **bukan kategori**. pH dan Turbidimeter satu kategori.
- Yang **tetap** di kelas bersama: agregasi budget (u_c, Welch–Satterthwaite, k), lantai
  CMC, keputusan PASS/FAIL. Profil hanya menyetor daftar komponen lewat `komponenBudget()`.

### Jalur angka

```
raw_measurements                      sumbu: peran_sensor / sensor_ke / tahap
      │                               blok tingkat-sesi → spesifikasi_alat
      ├─ App\Support\*Mentah          bentuk ulang baris mentah per keluarga alat
      ▼
GumCalculator                         JCGM 100:2008 — Type A+B → u_c → U = k·u_c
      │                               k dikunci 2 (lampiran akreditasi LK-285-IDN)
      ▼
uncertainty_calculations              DISIMPAN, tidak pernah dihitung ulang saat dibaca
      ▼
CalibrationValidator                  sebelum terbit: hitung ULANG dari mentah, adu ke
      │                               yang tersimpan → error / peringatan / info
      ▼
PerhitunganBuilder → CertificateSnapshotBuilder → BerkasPdfSertifikat (dompdf)
                                      + QrCodeGenerator → endpoint verifikasi publik
```

- PASS/FAIL memakai *guarded acceptance* (ILAC-G8): lulus kalau |error| **+ U** masih
  dalam toleransi. Keputusan lab 14 Jul.
- `php artisan kalibrasi:hitung-ulang` adalah jalur hitung kedua. Alat baru wajib
  menyambung `*Mentah`-nya ke `CalibrationValidator` **dan** `HitungUlangSesi`.

### Lapisan lain

| Lapisan | Tempat | Catatan |
|---|---|---|
| Rute API | `routes/api.php`; pelanggan `routes/api_pelanggan.php` | Sanctum + `role:`; hampir tiap aksi tulis punya `throttle:` sendiri |
| Panel admin | `app/Filament/` | `Concerns/ScopesToOrganization` menyaring per organisasi |
| Model | `app/Models/` | Data lab disaring `organization_id` — skill `[[sidik-query-organisasi]]` |
| OCR / Vision | `app/Services/Ocr/`, `WorksheetVisionExtractor` | `VISION_DRIVER` dipatok `anthropic` di kedua phpunit.xml |
| Realtime | Reverb, `routes/channels.php` | `docs/realtime-sync.md` |
| Migrasi | `database/migrations/` | Kolom baru pilihan TERAKHIR — §Alur kerja butir 4 |

### Data sumber & dokumen

- `Project-PT-Sidik/alat-alat-Pt-Sidik/` — workbook master lab per alat, kebenaran rumus.
- **Status publik/privat repo tidak pernah diambil dari dokumen.** Jalankan
  `gh repo view ZainulArkaanAlinsi/<repo> --json visibility`. Dokumen ini sudah dua kali
  salah menulisnya.
- **Sapu nama & alamat pelanggan sebelum `git add` apa pun dari `Project-PT-Sidik/`**,
  apa pun status repo. 19 Sep 2026 satu nama pelanggan asli lolos ke
  `database/seeders/HydrometerSeeder.php`.
- **Riwayat git masih memuat kredensial dan nama pelanggan.** Dua hal berlaku sekarang:
  password MySQL LAN user `asmo_dev` dianggap bocor dan harus diganti (atau `DROP USER`);
  menulis ulang riwayat butuh force-push dan **hanya dengan perintah eksplisit**. Rincian &
  urutannya: `docs/panduan/riwayat-git-publik.md`.
- `rahasia123` **bukan** rahasia. Itu fixture lokal yang hanya dipakai saat
  `local`/`testing`. Jangan diganti; dokumentasi, skrip, dan test bergantung padanya.
- CMC dari lampiran akreditasi **LK-285-IDN**, diseed lewat `*CapabilitySeeder`,
  diringkas di `docs/Rekap-Data-Kemampuan-Kalibrasi.md`.
- `docs/BACA-DULU-BACKEND.md` satu-satunya dokumen status yang boleh dipercaya. Berkas
  `docs/permintaan-*.md` adalah permintaan dari mobile; sebagian tanda ✅-nya salah.
- Komentar `spesifikasi poin N` merujuk `docs/Spesifikasi-Aplikasi-Kalibrasi.md`.

## Modul Pelanggan — aturan keras

Ketujuh aturan ini tidak menghasilkan error kalau dilanggar.

1. Rute pelanggan **hanya** di `routes/api_pelanggan.php`, prefix `api/pelanggan/v1`.
   Tidak ada yang menumpang `routes/api.php` (kerahasiaan antar pelanggan, ISO/IEC 17025
   klausul 4.2).
2. Controller/Request/Resource pelanggan hanya di namespace `Pelanggan`. Resource
   pelanggan **tidak boleh** memakai ulang atau mewarisi Resource internal.
3. `customer_id` tidak pernah diambil dari request — selalu dari `KonteksPerusahaan`.
4. Data milik perusahaan lain dijawab **404, bukan 403**.
5. Setiap rute pelanggan ber-parameter wajib punya kasus di `IsolasiPerusahaanTest`
   (test itu membaca daftar rute sendiri).
6. Migrasi untuk pelanggan hanya **additive**; perubahan merusak pada `/pelanggan/v1`
   dilarang — buat `/v2`.
7. Rujukan: `docs/pelanggan/03-SDD.md`.

## Olah data — aturan keras

ISO/IEC 17025 klausul 7.2.1.5 & 7.11 meminta metode perhitungan divalidasi. Dua kesalahan
yang lolos bertahun-tahun di workbook lab berbentuk rujukan sel yang meleset dan pita CMC
dari baris yang salah (`docs/pertanyaan-lab-hydrometer.md` §7, §14).

1. **Tiga lapis; hanya lapis 1 & 3 yang boleh berubah lewat konfigurasi.**

   | Lapis | Isi | Lewat konfigurasi? |
   |---|---|---|
   | 1. Parameter & tabel referensi | pita CMC, tabel koreksi standar, tabel MPE, nominal balok ukur, identitas & jatuh tempo standar, konstanta metode, batas jumlah titik | **Ya**, lewat alur versi butir 3 |
   | 2. Struktur perhitungan | rantai perhitungan, struktur budget, koefisien sensitivitas, urutan konversi satuan | **Tidak.** Hanya PR kode + test rekonsiliasi master |
   | 3. Bentuk lembar kerja | jumlah titik, label, satuan | Ya, terbatas — wajib divalidasi tidak ada input yang hilang |

   Sumbu ini berbeda dari §Profil kalibrasi: yang sana mengatur **di mana** kode duduk,
   yang ini mengatur **boleh tidaknya** diubah tanpa PR.
2. **Tiap sertifikat menyimpan `formula_version_id`.** Versi yang sudah dipakai sertifikat
   terbit tidak boleh diubah atau dihapus, hanya dipensiunkan (`FormulaVersion::STATUS_ARSIP`).
   Sesi tersimpan tanpa stempel versi adalah **cacat jalur penyimpanan**, bukan data lama.
3. **Versi parameter baru tidak aktif sebelum simulasinya ditinjau.** Simulasi menunjukkan
   sesi yang berubah, berapa, kolom apa, dan mana yang **angka cetaknya** ikut berubah.
4. **Penyimpangan dari master wajib punya catatan audit di `type_b_components` sesi**,
   lengkap dengan sumbernya (nomor IK, halaman & butir lampiran, atau alamat sel master).
   Pola baku: `pengulangan_standar_dibagi_n` (`ThermometerGlassProfile`). Komentar di kode
   bukan catatan audit.
5. **Nilai antara tidak dibulatkan.** Pembulatan hanya di render sertifikat
   (`desimalSertifikat`, `desimalU95`, `desimalFaktorCakupan`).
6. Rujukan: `docs/pelanggan/09-Adendum-Olah-Data-Peran.md` §2.1 dan §3.

## Peran & pemisahan wewenang

1. Kunci peran **tetap**: `admin`, `teknisi`, `viewer`, `super_admin`, `pelanggan`. Label
   tampilan `admin` = **"Master Data"**. Jangan pernah mengubah nilai kolom `role`.
2. `super_admin` boleh membaca semua data lab. Lintas organisasi saat ini **hanya di
   panel** (dicatat); API masih terkurung di organisasinya sendiri.
3. `super_admin` boleh bertindak atas nama peran lain, tapi aksinya **tercatat sebagai
   `super_admin`**. Tidak pernah menyamar.
4. **Satu `user_id` tidak boleh menjadi pengirim lembar kerja DAN pengesah sertifikat pada
   sesi yang sama**, termasuk `super_admin`, **tanpa pengecualian** (K-30-03, 1 Okt 2026).
   Satu pintu: `App\Services\PemisahanWewenang` (API `approve()`, panel, `sahkan`); "ikut
   mengisi" = pengisi, pengoreksi pembacaan, pengonfirmasi hasil pindai. Tidak ada sakelar
   env. Jangan menambah jalur pengecualian tanpa keputusan baru pemilik.
5. **Nilai yang diisi teknisi tidak pernah dihapus.** Kesalahan ditandai; koreksi
   menyimpan nilai lama & baru beserta alasan (ISO/IEC 17025 klausul 7.5.2).
6. Rujukan: `docs/pelanggan/09-Adendum-Olah-Data-Peran.md` §2.3 dan §4.

### `super_admin` — aturan yang mengikat

Keadaan rinci per pintu, alasan, dan sejarahnya: `docs/panduan/super-admin.md`. Baca
sebelum menyentuh rute, panel, atau channel yang berhubungan dengan peran ini.

- Akun `super_admin` **hanya** lahir dari `php artisan akun:super-admin <email>`. Tidak dari
  panel, tidak dari `docker/entrypoint.sh`, dan perintah itu tidak menaikkan akun yang ada.
- **Dua daftar peran jangan digabung.** `User::roles()` = peran yang boleh DIBERIKAN admin
  (dipakai `Rule::in`; `super_admin` tidak ada di sini). `User::rolesInternal()` = siapa
  yang bukan orang luar.
- Tulis oleh `super_admin` masih tertutup, kecuali empat rute di grup `role:super_admin` /
  `role:admin,super_admin` di ekor `routes/api.php`.
- `GERBANG_PENGESAHAN` **tidak dinyalakan tanpa perintah pemilik**. Satu akun super
  admin sudah ada di produksi sejak 24 Sep 2026; pastikan pemegangnya bisa masuk dulu.
- **Aksi tulis panel baru wajib lewat `HakTulisPanel`.** Tanpa itu tombol tetap menyala
  untuk orang yang belum boleh menekannya, tanpa error.
- **Tiap akses lintas organisasi wajib dicatat** lewat `App\Support\JejakLintasOrganisasi`,
  di percabangan yang sama dengan pelebarannya.
- Pelebaran lintas organisasi di API ditunda sampai penyaring API (±60 tempat) dipindah ke
  satu pintu lewat refactor tersendiri.

## Akun lahir dari undangan

- **Tidak ada pendaftaran mandiri** di kedua sisi (keputusan 18 Sep 2026). Orang lab
  dibuat admin di panel; pelanggan lewat undangan berkode
  (`POST /pelanggan/v1/auth/terima-undangan`).
- **Jangan dibangun ulang** `register`/`daftar`. Dijaga `PintuDaftarMandiriTertutupTest`.
- **Sengaja dibiarkan berdiri, jangan dirapikan:** mesin OTP (`otp_pelanggan`, `KodeOtp`,
  masih dipakai lupa/atur-ulang sandi), konstanta `OtpPelanggan::TUJUAN_VERIFIKASI_EMAIL`,
  tabel `pengajuan_akun_pelanggan` (bukti persetujuan syarat & privasi, UU PDP), status
  `pending`, `pending_email`, `pending_verifikasi`.
- Celah yang diterima sadar: sandi awal orang lab diketahui admin pembuatnya.
- Ganti sandi sendiri lewat profil panel; `HakTulisPanel` sengaja tidak menutupnya.

Alasan & sejarah: `docs/panduan/akun-undangan.md`.

## Git

- Mulai sesi: `git pull origin main` sebelum mengubah kode.
- JANGAN commit atau push otomatis. Tunggu perintah eksplisit. `[K-I1]`
- Saat diminta: `git add` per berkas (hindari `-A`/`.`), commit, `git push origin main`.
- Konflik saat pull/push: jangan force-push. Tampilkan konfliknya dan minta arahan.
- Sebutkan ringkasan berkas yang berubah sebelum commit.

### SATU PUSH, SATU DEPLOY

Push ke `main` = deploy produksi. Langkah terakhir workflow menunggu sampai 25 menit sampai
`/api/health` menyajikan SHA baru.

1. Tunggu deploy commit sebelumnya **terverifikasi** (`gh run list` hijau **dan**
   `deploy.versi` di `/api/health` cocok) sebelum push berikutnya.
2. Beberapa perubahan yang siap digabung jadi **satu** push.

Kalau langkah verifikasi merah: cek dulu `phpunit` hijau atau tidak. Kalau hijau, hampir
pasti balapan deploy; cek `deploy.versi`. Kejadian 19 Sep: `docs/panduan/deploy-satu-push.md`.

## Pemilihan model

- Subagent pengumpul data (Explore, cari berkas, hitung, baca mentah): `model="haiku"`.
- Subagent analisis/review/sintesis: `model="sonnet"`.
- Opus untuk thread utama dan keputusan arsitektur.

## MCP

- **Aiven MCP tidak dipasang** (keputusan 19 Sep 2026). Kalau suatu saat dipasang: read-only
  di tingkat **organisasi**, dan `AIVEN_ALLOW_SECRETS=false` selalu.
- **Render MCP baca saja** (log & metrik). Menyetel env var atau memicu deploy butuh izin
  eksplisit pemilik, **sekali per kejadian**.
- Menulis ke produksi lewat MCP mana pun: **tidak pernah**. Izin baca berlaku untuk
  pertanyaan yang sedang dibahas saja.
- Sebelum memasang MCP baru: laporkan apakah tools-nya bisa destruktif dan apakah
  pembatasannya bisa ditegakkan di tingkat organisasi.

Alasannya: `docs/panduan/mcp.md`.

### Akses database

@docs/aturan-akses-database.md

## Daftar permintaan

- `docs/permintaan-user-7.md` adalah pegangan, bukan ingatan percakapan. Baca dulu sebelum
  kerja; perbarui kolom Status di commit yang sama dengan perubahannya.
- Berkas itu menyimpan keputusan yang SUDAH diambil (jangan ditanya ulang), pertanyaan
  terbuka, dan jebakan yang sudah terbukti.

## Alur kerja fitur besar

Untuk pekerjaan sebesar alat atau modul baru.

1. **IDE → PRD.** Tanyakan yang paling menentukan dulu. PRD = §baru di
   `docs/permintaan-user-7.md` + baris §Gelombang. Persyaratan yang belum jelas ditulis
   sebagai pertanyaan bernomor di `docs/pertanyaan-lab-*.md`.
2. **Tech stack** sudah dipatok (Laravel + MySQL + Filament; Flutter + Riverpod). Kode baru
   hampir selalu mengikuti pola alat yang sudah ada.
3. **Arsitektur.** Sebut berkas yang akan dibuat/diubah SEBELUM mengetik.
4. **Database.** Kolom baru pilihan terakhir. Sumbu `peran_sensor`/`sensor_ke`/`tahap` dan
   `spesifikasi_alat` hampir selalu cukup.
5. **Satu fitur satu waktu.** Jangan menyentuh kode yang tidak diminta.
6. **Review:** `[[sidik-code-reviewer]]`; angka: `[[sidik-kalkulasi-presisi]]`.
7. **Test:** `[[sidik-test-verifier]]`. Alat baru: tiap komponen budget diadu ke master,
   bukan hanya U95.
8. **Debug.** Cari akar sebab. Sebelum menyalahkan perubahan sendiri, bandingkan ke
   baseline (`git stash`, jalankan test yang sama).
9. **Deploy.** `render.yaml` + `docs/CHECKLIST-DEPLOY-VPS.md`. Key baru masuk `.env.example`
   DAN blueprint.
10. **Refactor** tidak mengubah perilaku; `pint` hanya berkas yang disentuh.
11. **Dokumentasi.** Alat/modul baru belum selesai sebelum ada `docs/perintah-frontend-*.md`.

## Aturan yang lahir dari kesalahan nyata

Semuanya tidak menghasilkan error waktu dilanggar.

- **Master lab ditiru, bukan dibetulkan diam-diam.** Kejanggalan metode → tiru + pertanyaan
  lab. Kerusakan salin-tempel (rujukan meleset, tautan `[n]` ke workbook lain) → hitung
  benar + tulis selisihnya. `IFERROR(…,"")` yang membuat sel kosong dibaca nol → **jangan
  pernah** ditiru; blokir titiknya dengan alasan yang terbaca.
- **Jangan percaya bacaan tabel lampiran yang terpotong.** Barisnya menyambung dengan kolom
  kosong; bacaan sebagian pernah melahirkan peringatan palsu.
- **Daftar nama alat di `EquipmentFactory` tidak boleh memuat nama yang punya profil.**
- **Alat baru WAJIB lahir bareng jalur hitung ulangnya** (`App\Support\*Mentah` →
  `CalibrationValidator` DAN `HitungUlangSesi`). Sudah menggigit tujuh kali.
- **Seeder tidak boleh menimpa baris yang sudah maju.** Penurunan status sesi `disetujui`
  yang sudah punya sertifikat ditahan di `CalibrationSession::booted()` (dijaga
  `SesiDisetujuiTidakBisaDimundurkanTest`). Nasib enam baris & nomor `CAL/2026/09/0011`–`0017`
  dari kejadian 18 Sep **belum diputuskan**; itu tulis produksi, ikuti
  `docs/aturan-akses-database.md` butir 4.

Playbook alat baru: `[[sidik-alat-baru-dari-master]]`.

## Instruksi compaction

Saat konteks dipadatkan, pertahankan:
- Penunjuk ke `docs/permintaan-user-7.md` dan permintaan yang sedang dikerjakan.
- Branch yang dipakai dan berkas yang berubah tapi belum di-commit.
- Keputusan teknis + alasannya, bukan proses perdebatannya.
- Status verifikasi: perintah test/tinker yang sudah dijalankan di MySQL dan hasilnya.
- Blocker dan langkah berikutnya.
- Alat baru: varian master yang sudah dibuktikan cocok, dan penyimpangan yang sudah jadi
  pertanyaan lab bernomor.

Boleh dibuang: isi berkas yang sudah dibaca utuh, output test hijau, eksplorasi yang tidak
dipakai.

## Panduan sesuai kebutuhan

Tidak diimpor. Baca saat pemicunya terjadi.

| Panduan | Baca saat |
|---|---|
| `docs/panduan/test-mysql-lokal.md` | menyiapkan suite MySQL di mesin baru |
| `docs/panduan/super-admin.md` | menyentuh rute, panel, channel, atau izin yang melibatkan `super_admin` |
| `docs/panduan/akun-undangan.md` | menyentuh auth, OTP, pendaftaran, atau akun |
| `docs/panduan/riwayat-git-publik.md` | membahas visibilitas repo, sanitasi riwayat, atau rotasi kredensial |
| `docs/panduan/deploy-satu-push.md` | langkah verifikasi deploy merah, atau merencanakan beberapa push |
| `docs/panduan/mcp.md` | menimbang pemasangan MCP |
