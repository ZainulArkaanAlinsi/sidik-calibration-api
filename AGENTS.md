# Panduan repo — aturan kerja `sidik-calibration-api`

Pegangan siapa pun yang ngoding di repo ini. Dulu isinya ada di berkas lain dan
`AGENTS.md` cuma symlink ke situ; sekarang dibalik — yang ini berkas aslinya,
dan cuma ada satu. Satu sumber, jangan dikembar.

Backend REST API kalibrasi alat ukur & sertifikat digital PT Sidik (Laravel 13 +
Filament 5). Klien utamanya repo terpisah `sidik-calibration-mobile` — tidak ada
frontend web sendiri di luar panel admin Filament.

## Perintah

```bash
composer setup                      # install + .env + key + migrate + npm build
composer dev                        # serve + queue:listen + pail + vite, sekaligus
php artisan serve --host=0.0.0.0    # kalau mobile dites dari HP fisik lewat LAN
```

### Test

```bash
php artisan test                                  # SQLite in-memory (phpunit.xml) — dipakai sehari-hari
php artisan test --filter=NamaTest                # satu kelas test
php artisan test --filter='NamaTest::nama_metode' # satu metode
php artisan test tests/Feature/AutoclaveApiTest.php
php artisan test -c phpunit.mysql.xml             # suite yang sama di MySQL
```

**Dua suite itu bukan pilihan — dua-duanya wajib hijau sebelum modul disebut
selesai.** Produksi jalan di MySQL; SQLite membaca `decimal(20,8)` sebagai float
sementara MySQL memberi string, `AUTO_INCREMENT`-nya beda, dan strict mode-nya
beda. Empat bug pernah bersembunyi di celah itu. Alasan lengkapnya ditulis di
kepala `phpunit.mysql.xml`.

Sekali seumur mesin, buat database khusus test (BUKAN database kerja —
`RefreshDatabase` menghapus isinya):

```sql
CREATE DATABASE asmo_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Format & bantu ketik

```bash
vendor/bin/pint app/Services/Foo.php   # HANYA berkas yang kamu sentuh — lihat §Alur Kerja poin 10
composer ide-helper                    # regenerate _ide_helper.php + mixin model
```

### Perintah artisan milik proyek ini

```bash
php artisan kalibrasi:hitung-ulang     # hitung ulang sesi dari pembacaan mentah
php artisan kalibrasi:uji-profil       # sapu profil alat lawan datanya
php artisan kalibrasi:sapu-sesi
php artisan kemampuan:pastikan         # tegakkan baris CMC dari lampiran akreditasi
php artisan akun:admin
php artisan flowmeter:audit-cmc
```

Daftar lengkapnya di `app/Console/Commands/` (20 perintah).

### Generator — jangan diketik tangan, dijalankan

```bash
php docs/skrip/gen-contoh-lembar-kerja.php   # tulis contoh_lembar_kerja_*.dart ke repo mobile
php docs/skrip/gen-kode-profil-mobile.php    # ekspor daftar kode profil ke mobile
```

`docs/skrip/gen-tabel-standar-*.py` dan `gen-sesi-*.py` membangun tabel standar &
fixture dari workbook master. Keluarannya disalin apa adanya — menyunting
hasilnya dengan tangan berarti berkasnya menyimpang diam-diam dari server, dan
itu sudah tiga kali meloloskan bug (TIDS, Timbangan, Micrometer).

### Dua jebakan waktu menjalankan suite — keduanya bikin hasil PALSU

**1. Jangan pernah dua suite sekaligus di satu checkout.** `Storage::fake()` menulis ke
`storage/framework/testing/disks`, satu direktori bersama, dan MENGHAPUS subdirektorinya tiap
dipanggil. Dua proses test di checkout yang sama berarti yang satu menghapus berkas milik yang
lain di tengah jalan. Gejalanya menyesatkan: yang merah test yang tidak berhubungan, beda-beda
tiap jalan — PDF hilang, berkas Excel hilang, `422` karena berkas yang divalidasi lenyap. Kalau
dua suite gagal di test yang BERBEDA tanpa satu pun irisan, curigai ini duluan, bukan kodenya.

**2. Worktree ber-`vendor` symlink menjalankan kode yang SALAH.** Laravel 11+ menyimpulkan base
path dari lokasi `ClassLoader` yang terdaftar — yaitu direktori induk `vendor`. Kalau `vendor` di
worktree cuma symlink ke checkout utama, base path-nya resolve ke CHECKOUT UTAMA, dan seluruh
`app/`, `config/`, serta `database/migrations/` dibaca dari sana. Berkas testnya tetap ditemukan
dari worktree (itu ikut `phpunit.xml`), jadi test barunya JALAN — melawan kode lama. Ini sudah
memakan satu jam: migrasi baru tidak pernah jalan, dan test penjaganya merah seolah migrasinya
salah. Kalau harus pakai worktree, salin `vendor` sungguhan atau setel `APP_BASE_PATH`; paling
aman, checkout branch-nya di direktori utama.

### Yang bikin CI beda dari lokal

CI memakai **PHP 8.4**, bukan 8.3 yang tertulis di `composer.json`. Itu batas
bawah beneran: `config/database.php` menyebut `Pdo\Mysql::ATTR_SSL_CA`, kelas
yang baru ada di 8.4 — di 8.3 config-nya fatal sebelum satu test pun jalan.
`.github/workflows/tes.yml` menjalankan test dulu, lalu mengetuk Deploy Hook
Render; commit merah berhenti di GitHub dan tidak sampai ke server yang dipakai
teknisi di lokasi.

## Arsitektur

### Profil kalibrasi — sumbu utama repo ini

Satu jenis alat = satu berkas di `app/Services/Calibration/Profiles/`, turunan
`CalibrationProfile`. Lab menargetkan 48 jenis alat; 29 profil konkret sudah
mendarat. Nambah alat = **satu subclass + satu seeder CMC + satu baris di
`CalibrationProfileRegistry::daftarProfil()`** — bukan `if (besaran == ...)` di
kelas bersama.

Pencocokan alat → profil lewat `equipments.nama_alat_kemampuan` (+ `aliasNama()`),
**bukan kategori**: pH dan Turbidimeter satu kategori yang sama, jadi kategori
tidak cukup memisahkan.

Yang **tetap** di kelas bersama dan tidak boleh pindah ke profil: agregasi budget
(u_c, Welch–Satterthwaite, k), lantai CMC, dan keputusan PASS/FAIL. Profil cuma
menyetor DAFTAR KOMPONEN lewat `komponenBudget()`.

### Jalur angka, dari pembacaan sampai sertifikat

```
raw_measurements                      sumbu: peran_sensor / sensor_ke / tahap
      │                               blok tingkat-sesi → spesifikasi_alat
      ├─ App\Support\*Mentah          bentuk ulang baris mentah per keluarga alat
      ▼
GumCalculator                         JCGM 100:2008 — Type A+B → u_c → U = k·u_c
      │                               k dikunci 2 (lampiran akreditasi LK-285-IDN)
      ▼
uncertainty_calculations              DISIMPAN, tidak pernah dihitung ulang saat dibaca
      │                               sertifikat 5 tahun lalu wajib tetap sama angkanya
      ▼
CalibrationValidator                  sebelum terbit: hitung ULANG dari mentah, adu
      │                               ke yang tersimpan → error / peringatan / info
      ▼
PerhitunganBuilder → CertificateSnapshotBuilder → BerkasPdfSertifikat (dompdf)
                                      + QrCodeGenerator → endpoint verifikasi publik
```

PASS/FAIL memakai *guarded acceptance* (ILAC-G8): lulus kalau |error| **ditambah
U** masih masuk toleransi — bukan |error| saja. Keputusan lab, 14 Jul.

`php artisan kalibrasi:hitung-ulang` adalah jalur kedua yang menghitung hal yang
sama. Itu sebabnya alat baru wajib menyambung `*Mentah`-nya ke
`CalibrationValidator` **dan** `HitungUlangSesi` — pola ini sudah menggigit tujuh
kali (lihat §Aturan yang Lahir dari Kesalahan Nyata).

### Lapisan lain

| Lapisan | Tempat | Catatan |
|---|---|---|
| Rute API | `routes/api.php` (satu berkas, ~640 baris) | Sanctum + `role:admin,teknisi`; hampir tiap aksi tulis punya `throttle:` sendiri |
| Panel admin | `app/Filament/` | Filament 5; `Concerns/ScopesToOrganization` yang menyaring per organisasi |
| Model | `app/Models/` | Semua data lab disaring `organization_id` — lihat skill `[[sidik-query-organisasi]]` |
| OCR / Vision | `app/Services/Ocr/`, `WorksheetVisionExtractor` | `VISION_DRIVER` dipatok ke `anthropic` di kedua phpunit.xml, jangan diwarisi dari `.env` |
| Realtime | Laravel Reverb, `routes/channels.php` | `docs/realtime-sync.md` |
| Migrasi | `database/migrations/` (86) | Kolom baru itu pilihan TERAKHIR — lihat §Alur Kerja poin 4 |

### Data sumber & dokumen

- `Project-PT-Sidik/alat-alat-Pt-Sidik/` — workbook master lab per alat. Ini
  kebenaran untuk rumus. Repo **sekarang PRIVAT** (dicek 10 Sep 2026 lewat
  `gh repo view --json visibility`) — sempat PUBLIK sampai K27 dijawab, dan
  status itu bukan alasan longgar: tetap sapu nama/alamat pelanggan sebelum
  `git add` apa pun dari direktori ini. Lihat §Sebelum repo dibalik jadi PUBLIK.
- Nilai CMC berasal dari lampiran akreditasi **LK-285-IDN**, diseed lewat
  `*CapabilitySeeder`, diringkas di `docs/Rekap-Data-Kemampuan-Kalibrasi.md`.
- `docs/BACA-DULU-BACKEND.md` — **satu-satunya dokumen status yang boleh
  dipercaya**. Berkas `docs/permintaan-*.md` itu permintaan dari mobile, dan
  beberapa tanda ✅-nya salah.
- Komentar `spesifikasi poin N` yang tersebar di ~45 tempat merujuk ke
  `docs/Spesifikasi-Aplikasi-Kalibrasi.md`.

### Sebelum repo dibalik jadi PUBLIK — yang menghalangi RIWAYAT, bukan berkasnya

Berkas kerjanya sudah bersih: nama & alamat pelanggan diganti sintetis di 81
berkas, commit `c0645f6`. **Riwayat git-nya belum.** Rewrite 10 Sep cuma
mencabut atribusi, dan tree `main` sebelum/sesudah sengaja diadu sampai identik
byte-per-byte — nol byte isi berubah, jadi commit lama masih memuat isinya yang
dulu. Dihitung ulang 10 Sep 2026 dengan `git log -S ... --all`:

| Yang masih terbaca di riwayat | Jumlah commit | Rahasia beneran? |
|---|---|---|
| Password MySQL LAN user `asmo_dev` | 3 | **YA** — kredensial asli |
| Nama pelanggan asli (sebelum `c0645f6`) | 3+ | **YA** |
| `rahasia123` | 46 | **BUKAN** — lihat bawah |

`rahasia123` **bukan** rahasia dan tidak perlu diganti. `MenyetelSandiAwal::sandiAwal()`
memulangkannya hanya kalau `app()->environment(['local','testing'])`; di luar itu
nilainya dari `SEED_ADMIN_PASSWORD`, dan kalau kosong seeder bikin acak 32 karakter.
Seeder juga tidak pernah menyetel ulang sandi baris yang sudah ada. Dia fixture lokal
yang disengaja — dokumentasi, `docs/skrip/e2e-ph.py`, dan delapan berkas test bersandar
padanya, jadi menggantinya memecahkan test tanpa menutup apa pun.

Menjadikan repo publik menerbitkan **seluruh riwayat**, bukan cuma keadaan
terakhir. `git log -S` bisa dijalankan siapa pun yang meng-clone. Urutan yang
mengikat:

1. **Ganti password `asmo_dev` di MySQL lebih dulu** — dan itu berlaku sekarang,
   tidak menunggu repo jadi publik: nilainya pernah tertulis polos di README dan
   sudah harus dianggap bocor ke siapa pun yang pernah punya akses repo. Kalau
   jalur LAN memang sudah pensiun, `DROP USER` lebih baik daripada ganti sandi.
2. Putuskan nasib yang **sengaja dibiarkan** waktu K27 dijawab: nama laboratorium
   lain di field `"lab"`, direktori bisnis `database/direktori/*.csv`, dan nomor
   seri alat. Ketiganya lolos sanitasi karena dinilai bukan data pelanggan —
   penilaian itu perlu diulang kalau pembacanya jadi umum.
3. **Baru** bahas menulis ulang riwayat. Itu butuh force-push, dan force-push
   **tidak boleh dijalankan tanpa perintah eksplisit** — lihat §Git Workflow.

Jangan menyimpulkan status repo dari ingatan atau dari dokumen ini; jalankan
`gh repo view --json visibility`. Berkas ini sendiri sempat salah menulis
"PUBLIK" selama dua hari setelah statusnya dibalik, dan tidak ada yang error.

## Modul Pelanggan — aturan keras

Role ketiga CertiCal (`pelanggan`) dan aplikasi Android yang memakainya. Per
16 Sep 2026 **belum ada satu baris pun** di repo ini — yang mendarat duluan
aturannya, karena ketujuhnya kalau dilanggar TIDAK menghasilkan error. Paket
rancangannya (`docs/pelanggan/`, 00–08 + ADR-001) juga belum masuk repo; poin 7
menunjuk ke sana supaya jelas ke mana arahnya begitu berkasnya mendarat.

1. **Rute pelanggan hanya di `routes/api_pelanggan.php`**, prefix
   `api/pelanggan/v1`. Jangan ada satu pun yang menumpang `routes/api.php` —
   begitu bercampur, gerbang `role:` di sana jadi satu-satunya yang memisahkan
   dua dunia, dan itu penjagaan yang terlalu tipis untuk kerahasiaan antar
   pelanggan (ISO/IEC 17025 klausul 4.2).
2. **Controller/Request/Resource pelanggan hanya di namespace `Pelanggan`.**
   Resource pelanggan TIDAK BOLEH memakai ulang atau mewarisi Resource
   internal. Resource internal memuat `nama_alat_kemampuan`, reviewer, dan
   status sesi; kalau diwarisi, satu field baru di sana bocor ke pelanggan
   tanpa ada yang sengaja menambahkannya.
3. **`customer_id` tidak pernah diambil dari request** — selalu dari
   `KonteksPerusahaan`. Body dan query string itu milik pemanggil, bukan milik
   kita.
4. **Data milik perusahaan lain dijawab 404, bukan 403.** 403 mengakui
   barangnya ada, dan itu sudah bocor: pelanggan bisa menyisir ID untuk
   memetakan alat pesaingnya.
5. **Setiap rute pelanggan ber-parameter wajib punya kasus di
   `IsolasiPerusahaanTest`.** Test itu membaca daftar rute sendiri, jadi rute
   baru yang lupa dikasih kasus uji bikin suite merah — bukan lolos diam-diam.
6. **Migrasi untuk pelanggan hanya additive**, dan perubahan merusak pada
   `/pelanggan/v1` dilarang. Aplikasi yang sudah terpasang di ribuan HP tidak
   bisa disuruh ikut berubah hari itu juga; kalau memang harus, buat `/v2`.
7. Rujukan lengkap: `docs/pelanggan/03-SDD.md`.

## Akun Lahir dari Undangan

Tidak ada pendaftaran mandiri, di kedua sisi. Ini keputusan pemilik proyek
(18 Sep 2026), dan yang dicabut adalah pintunya — bukan penjagaan di baliknya.

| Siapa | Akunnya lahir dari | Yang DICABUT |
| --- | --- | --- |
| Orang lab (teknisi/admin/viewer) | admin membuatnya di panel Filament | `POST /register` |
| Pelanggan | undangan berkode dari admin lab atau PIC utama, ditukar di `POST /pelanggan/v1/auth/terima-undangan` | `POST /pelanggan/v1/auth/daftar`, `/verifikasi-email`, `/kirim-ulang-otp` |

**Kenapa.** Yang lama tidak pernah memberi akses langsung — statusnya `pending`
dan admin tetap harus menyetujui. Yang dicabut dua hal lain: antrean persetujuan
yang bisa dibanjiri siapa pun dari internet, dan pemohon yang mengaku dari PT X
lalu lolos karena admin sedang buru-buru. Undangan menutup dua-duanya sekaligus,
karena yang menjamin seseorang berhak bukan klaim yang dia ketik sendiri,
melainkan bahwa orang yang SUDAH berwenang mengirim kode ke alamat emailnya.

**Jangan dibangun ulang.** Kalau ada yang membaca `AuthPelangganController` dan
merasa `terimaUndangan()` kurang lengkap tanpa `daftar()` — itu bukan kelupaan.
Dijaga `PintuDaftarMandiriTertutupTest`, yang menguji lewat HTTP (bukan dengan
membaca daftar rute) dan ikut menjaring rute baru mana pun yang namanya
berakhiran `register` atau `daftar`.

**Yang SENGAJA ditinggal berdiri, jangan ikut dirapikan:**

- **Mesin OTP** (`otp_pelanggan`, `KodeOtp`). Bukan lagi untuk verifikasi email,
  tapi `lupa-sandi` dan `atur-ulang-sandi` masih memakainya — dan sesudah
  pencabutan, itu satu-satunya pintu yang menerbitkan OTP. Penjagaannya (kunci 5
  percobaan, hash, sekali pakai) ikut pindah ke `SayaDanSandiTest`.
- **Konstanta `OtpPelanggan::TUJUAN_VERIFIKASI_EMAIL`.** Nilainya tersimpan di
  baris yang mungkin masih ada; menghapus konstanta tidak menghapus barisnya.
- **Tabel `pengajuan_akun_pelanggan` beserta layar peninjauannya.** Barisnya
  bukan sekadar antrean — di situ tercatat bukti persetujuan syarat & kebijakan
  privasi, yang justru baru dikunci `restrictOnDelete` supaya tidak bisa
  terhapus (UU PDP). Antreannya memang tidak akan terisi lagi; membuang
  tabelnya demi kerapian berarti membuang bukti yang wajib disimpan.
- **Status `pending`, `pending_email`, `pending_verifikasi`.** Tidak dipakai
  akun baru, tapi baris lama bisa masih memakainya, dan jalur persetujuannya
  (`PersetujuanAkunLabTest`) tetap harus bisa memutuskannya.

**Satu hal yang belum tertutup.** Admin membuat akun orang lab sambil mengetik
sandinya, jadi sandi awal itu diketahui admin. Belum ada mekanisme "wajib ganti
sandi saat pertama masuk". Diterima sadar, bukan kelupaan — dicatat di sini
supaya tidak ditemukan ulang sebagai temuan baru.

## Git Workflow
- Setiap mulai sesi kerja, jalankan `git pull origin main` dulu sebelum mengubah kode apapun.
- JANGAN commit atau push otomatis setiap habis mengubah kode. Tunggu sampai user minta eksplisit, misal: "commit dan push ya", "commit ini dong".
- Begitu diminta commit, baru jalankan urutan ini sekaligus:
  1. `git add` (file yang relevan dengan perubahan, hindari `git add -A`/`.` kalau ada file mencurigakan/besar)
  2. `git commit -m "pesan singkat sesuai perubahan yang dibuat"`
  3. `git push origin main`
- Kalau ada conflict saat pull atau push, jangan force push. Tampilkan conflict-nya ke user dan minta arahan.
- Selalu kasih tau user ringkasan file apa saja yang berubah sebelum commit.

### SATU PUSH, SATU DEPLOY — tunggu yang sebelumnya terverifikasi

**Push ke `main` = deploy ke produksi.** `.github/workflows/tes.yml` mengetuk
Deploy Hook Render begitu test hijau, dan langkah terakhirnya (`Pastiin versi
barunya beneran naik`) menunggu **25 menit** sampai `/api/health` menyajikan SHA
yang baru.

Aturannya:

1. **Tunggu deploy commit sebelumnya selesai diverifikasi sebelum push
   berikutnya.** Verifikasi = `gh run list` hijau **dan** `curl .../api/health`
   memulangkan `deploy.versi` yang cocok.
2. **Kalau ada beberapa perubahan siap, kumpulkan jadi SATU push.** Beberapa
   commit dalam satu `git push` cuma memicu satu run CI dan satu deploy — itu
   yang diinginkan, bukan dihindari.

**Kenapa ini aturan, bukan kerapian.** 19 Sep 2026: lima commit naik ke `main`
dalam ~1 jam. Waktu workflow `a9a1ed0` menunggu, Render sudah keburu membangun
`a697b0c`, jadi `a9a1ed0` **tidak pernah disajikan** dan langkah verifikasinya
merah — padahal `phpunit`-nya hijau penuh dan kodenya tidak salah sedikit pun.

Kali itu tidak ada kerusakan karena riwayatnya linear: `a697b0c` memuat isi
`a9a1ed0`. Tapi yang ditinggalkan mahal — **riwayat deploy berhenti cocok dengan
riwayat commit.** Begitu suatu saat perlu rollback, pertanyaan "versi mana yang
BENAR-BENAR pernah jalan di produksi" tidak bisa dijawab dari `git log`, dan CI
merah yang sebetulnya cuma balapan bikin orang berikutnya mengira ada kode yang
rusak. Untuk lab terakreditasi, "sertifikat ini terbit dari kode yang mana"
bukan pertanyaan opsional.

Kalau langkah `Pastiin versi barunya beneran naik` merah, **periksa dulu apakah
`phpunit`-nya hijau**. Kalau ya, hampir pasti balapan deploy — bukan kode. Cek
`deploy.versi` di `/api/health`; kalau yang live commit yang LEBIH BARU, isinya
sudah ikut naik dan tidak ada yang perlu diperbaiki selain menunggu.

## Pemilihan Model
- Subagent yang tugasnya mengumpulkan data (Explore, pencarian file, penghitungan, pembacaan mentah) jalankan dengan `model="haiku"`.
- Subagent yang tugasnya menganalisis, mereview, atau menyintesis jalankan dengan `model="sonnet"`.
- Sisakan Opus untuk thread utama dan keputusan arsitektur.

## Daftar Permintaan
- Tujuh permintaan besar dari pemilik proyek ada di `docs/permintaan-user-7.md` — itu yang jadi
  pegangan, bukan ingatan percakapan. Baca dulu sebelum mulai kerja, dan perbarui kolom Status di
  commit yang sama dengan perubahannya.
- Berkas itu juga menyimpan keputusan yang SUDAH diambil (jangan ditanya ulang), pertanyaan yang
  masih menunggu jawaban, dan jebakan yang sudah terbukti bikin salah.

## Alur Kerja Fitur Besar (vibe coding)

Dipakai buat pekerjaan sebesar "alat baru" atau modul baru — bukan buat perbaikan sebaris.
Urutannya dari pemilik proyek, disetel ke kenyataan repo ini:

1. **IDE → PRD.** Tanya dulu yang paling menentukan, baru tulis PRD-nya. Di repo ini PRD-nya
   BUKAN berkas baru: dia jadi §baru di `docs/permintaan-user-7.md` + baris di §Gelombang.
   Jangan mengarang persyaratan yang belum jelas — tulis sebagai pertanyaan bernomor di
   `docs/pertanyaan-lab-*.md`.
2. **Tech stack.** Sudah dipatok: Laravel + MySQL + Filament (API), Flutter + Riverpod (mobile).
   Yang masih perlu diputuskan cuma *di mana* kode barunya duduk — dan jawabannya hampir selalu
   "ikut pola alat yang sudah ada", bukan lapisan baru.
3. **Arsitektur.** Sebutkan berkas yang akan dibuat/diubah SEBELUM mengetik. Ini mengikat, bukan
   kebiasaan: §12 spesifikasi permintaan 7 memintanya eksplisit.
4. **Database.** Kolom baru itu pilihan TERAKHIR. Empat alat terakhir mendarat dengan **nol kolom
   baru** di `raw_measurements` — sumbu `peran_sensor`/`sensor_ke`/`tahap` yang sudah ada hampir
   selalu cukup, dan blok tingkat-sesi masuk `spesifikasi_alat`.
5. **Satu fitur satu waktu.** Jangan menyentuh kode yang tidak diminta.
6. **Review.** Jalankan `[[sidik-code-reviewer]]`; untuk angka `[[sidik-kalkulasi-presisi]]`.
7. **Test.** Gate-nya di `[[sidik-test-verifier]]`. Untuk alat baru, yang mengikat: tiap komponen
   budget diadu ke master, bukan cuma U95 akhirnya.
8. **Debug.** Cari akar sebabnya, jangan menebak. **Sebelum menyalahkan perubahan sendiri,
   bandingkan ke baseline** (`git stash` lalu jalankan test yang sama) — kegagalan yang muncul
   belum tentu milikmu.
9. **Deploy.** `render.yaml` + `docs/CHECKLIST-DEPLOY-VPS.md`. Key baru wajib ikut ke
   `.env.example` DAN blueprint.
10. **Refactor.** Perilaku tidak boleh berubah. `vendor/bin/pint` **cuma pada berkas yang kamu
    sentuh** — dijalankan pada direktori, dia merapikan berkas lain dan mengotori diff.
11. **Dokumentasi.** Alat/modul baru belum selesai sebelum ada `docs/perintah-frontend-*.md` yang
    berdiri sendiri.

## Aturan yang Lahir dari Kesalahan Nyata

Ditulis di sini karena keempatnya **tidak menghasilkan error** waktu dilanggar:

- **Master lab ditiru, bukan dibetulkan diam-diam.** Kejanggalan metode → tiru + angkat sebagai
  pertanyaan lab. Kerusakan salin-tempel (rujukan meleset, tautan luar `[n]` ke workbook lain) →
  hitung benar + tulis selisihnya. `IFERROR(…,"")` yang bikin sel kosong dibaca nol → **jangan
  pernah** ditiru; blokir titiknya dengan alasan yang kebaca.
- **Jangan percaya bacaan tabel yang terpotong.** Tabel lampiran akreditasi barisnya menyambung
  dengan kolom kosong. Membacanya sebagian pernah melahirkan peringatan sesi yang salah — dan
  peringatan palsu melatih admin menekan "setujui tetap" tanpa membaca.
- **Daftar nama alat di `EquipmentFactory` wajib tidak memuat nama yang punya profil.** Kalau
  memuat, fixture acak mendarat di lembar alat itu dan yang merah adalah test yang tidak
  berhubungan, bergantian tiap jalan.
- **Alat baru WAJIB lahir bareng jalur hitung ulangnya** (`App\Support\*Mentah`, disambung ke
  `CalibrationValidator` DAN `HitungUlangSesi`). Pola ini sudah menggigit tujuh kali.

Playbook lengkapnya: `[[sidik-alat-baru-dari-master]]`.

## Instruksi Compaction
Saat konteks dipadatkan, pertahankan hal berikut:
- Penunjuk ke `docs/permintaan-user-7.md` beserta permintaan mana yang sedang dikerjakan.
- Nama branch yang sedang dipakai dan file yang sudah diubah tapi belum di-commit.
- Keputusan teknis yang sudah diambil beserta alasannya, bukan proses perdebatannya.
- Status verifikasi: perintah test/tinker yang sudah dijalankan di MySQL berikut hasilnya.
- Blocker yang belum selesai dan langkah berikutnya.
- Untuk pekerjaan alat baru: varian master mana yang sudah dibuktikan cocok, dan penyimpangan
  master mana yang sudah diangkat jadi pertanyaan lab bernomor.
Boleh dibuang: isi file yang sudah dibaca utuh, output test yang sudah hijau, dan eksplorasi yang tidak jadi dipakai.
