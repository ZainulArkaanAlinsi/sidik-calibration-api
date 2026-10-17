# 02 — SRS Sidik Studio

Kebutuhan perangkat lunak Sidik Studio. Setiap kebutuhan fungsional bernomor `FR-STU-xxx`, setiap
kebutuhan non-fungsional `NFR-STU-xx`. Nomor diberi celah per modul supaya bisa disisipi tanpa
menomori ulang. Nomor yang sudah terbit **tidak boleh dipakai ulang** untuk arti lain.

| Modul | Rentang | Tahap utama |
|---|---|---|
| A. Autentikasi & sesi | FR-STU-001 – 009 | S1 |
| B. Cangkang (shell) Windows + tablet | FR-STU-010 – 029 | S1 |
| C. Kelola Data | FR-STU-030 – 069 | S2 |
| D. Data Acuan | FR-STU-070 – 099 | S3 (baca: P1/M2 sudah ada) |
| E. Lembar Hitung | FR-STU-100 – 159 | S4, S5 |
| F. Impor `.xlsm` | FR-STU-160 – 179 | S0c, S5 |
| G. Sinkron ke aplikasi lapangan & sertifikat | FR-STU-180 – 189 | S3, S4 |
| H. Audit & jejak | FR-STU-190 – 199 | semua |

Kata **wajib** = harus ada sebelum tahapnya disebut selesai. **Sebaiknya** = boleh ditunda dengan
catatan. Rujukan `CR-48 FR-nn` menunjuk `docs/studio-data-acuan/02-SRS.md`; yang dirujuk tetap
berlaku kecuali disebut diganti (lihat `00-BACA-DULU.md` §5).

---

## 1. Kebutuhan fungsional

### A. Autentikasi & sesi

**FR-STU-001 Masuk dengan akun internal.** Studio memakai `POST /api/login` yang sudah ada
(`AuthController::login()`, token ber-ability `internal`, `app/Http/Controllers/Api/AuthController.php:81`).
Akun peran `pelanggan` dan akun yang statusnya bukan `aktif` ditolak sesuai aturan yang berlaku
hari ini. Studio tidak punya pintu daftar (`AGENTS.md` §Akun Lahir dari Undangan).

**FR-STU-002 Token di penyimpanan aman platform.** Token disimpan lewat `flutter_secure_storage`
(sudah dipakai paket `sidik_calibration`, `pubspec.yaml:38`), tidak di SharedPreferences. Studio
punya `applicationId` sendiri (`com.ptsidik.studio`, `studio/android/app/build.gradle.kts:36` di
worktree mobile), jadi token Studio terpisah dari token aplikasi lapangan di perangkat yang sama.

**FR-STU-003 Identitas klien.** Setiap request Studio membawa header `X-Klien: sidik-studio` dan
`X-Versi-Klien: <versi build>`. Server tidak memakainya untuk izin; dipakai untuk log, `audit_logs.note`
konteks ekspor, dan penolakan versi Studio yang terlalu lama (`klien_kedaluwarsa`, 426) bila
kontrak API berubah. Tanpa header tetap dilayani (aplikasi lapangan tidak mengirimnya).

**FR-STU-004 Sesi kedaluwarsa.** Respons `401` di mana pun → dialog "Sesi berakhir, masuk lagi".
Isian yang belum terkirim tetap di layar; suntingan draf yang sudah tersimpan otomatis tidak
hilang. Sesudah masuk ulang, Studio kembali ke layar yang sama.

**FR-STU-005 Keluar.** `POST /api/logout` mencabut token; cache lokal Studio dibersihkan.

**FR-STU-006 Konfirmasi sandi untuk aksi pengesahan.** `sahkan` dan `tarik` (Data Acuan dan Lembar
Hitung) mengirim `sandi`; server memeriksa `Hash::check` dan menjawab `422 sandi_salah` bila salah
(pola CR-48 FR-16). Sandi tidak dicatat di log mana pun.

**FR-STU-007 Izin dari server.** Sesudah masuk dan tiap kembali dari latar, Studio membaca
`GET /api/me/permissions` (`MatriksIzin::untuk()`, `app/Services/MatriksIzin.php:169`). Seksi,
tombol, dan menu mengikuti daftar `boleh`. Server tetap menolak sendiri.

**FR-STU-008 Tanpa push.** Studio tidak mendaftarkan token FCM dan tidak memakai notifikasi lokal
(K-49-01; `studio/lib/inti/penimpaan.dart`). Kabar (versi menunggu pengesahan, simulasi selesai)
muncul di Beranda dan lencana seksi lewat tarikan berkala.

**FR-STU-009 Batas percobaan masuk.** Mengikuti limiter `login` yang ada (10/menit per akun + IP,
`app/Providers/AppServiceProvider.php:252`). Studio menampilkan pesan 429 server apa adanya.

### B. Cangkang Windows + tablet

**FR-STU-010 Dua platform, satu kode.** Studio dibangun untuk Windows 10/11 x64 dan tablet Android.
Identitas sendiri (nama "Sidik Studio", ikon, `applicationId`, `BINARY_NAME`/`ProductName` Windows)
dan bisa dipasang berdampingan dengan aplikasi lapangan (K-49-01).

**FR-STU-011 Navigasi menurut lebar.** Windows ≥ 1280 dp: sidebar tetap 240 dp
(`UkuranStudio.lebarSidebar`). Tablet lanskap (±1138 dp pada perangkat uji): rail ikon + label.
Tablet potret (±711 dp): rail ikon tanpa label, label di tooltip/tekan lama. Rinci di `04-UI-UX-Flow.md` §2.

**FR-STU-012 Seksi.** Beranda, Kelola Data, Data Acuan, Lembar Hitung, Pengesahan, Impor,
Pengaturan. Seksi yang tahapnya belum mendarat tampil nonaktif dengan label tahap (pola
`SeksiStudio.tahap`, `studio/lib/layar/seksi_studio.dart`), tanpa layar tiruan di baliknya.
Seksi yang izinnya tidak dimiliki tidak tampil.

**FR-STU-013 Bilah status.** Menampilkan server yang dituju, status koneksi, status realtime
(`GET /api/health` → `realtime.nyala`), nama pengguna dan label peran ("Master Data" untuk `admin`).

**FR-STU-014 Beranda.** Ringkasan untuk peran pengguna: versi menunggu pengesahan (pengesah),
draf saya, simulasi/paritas yang sedang berjalan atau baru selesai, status paritas per alat,
standar dengan `berlaku_sampai` ≤ 30 hari (CR-48 FR-29). Setiap kartu bisa dibuka ke layarnya.

**FR-STU-015 Pengaturan.** Tema (terang, gelap, ikut sistem), bahasa (Indonesia, Inggris), dan
"Tentang": versi Studio, versi API (`/api/health` → `deploy.versi`), nama + versi mesin rumus
dari server. Alamat server hanya bisa diganti di build internal/uji.

**FR-STU-016 Offline.** Studio mendeteksi putus jaringan. Selama offline: layar yang sudah dimuat
tetap bisa dibaca dengan label "offline sejak hh:mm", semua aksi tulis nonaktif dengan alasan,
tidak ada antrean tulis offline. Data yang boleh disimpan di disk diatur NFR-STU-08.

**FR-STU-017 Pembaruan Studio terpisah.** Studio tidak memakai pembaru aplikasi lapangan
(URL manifest bawaan di `lib/services/pembaru_windows.dart:85-88` repo mobile). Mekanisme
distribusi Q-49-09; sampai dijawab, pemasangan manual.

**FR-STU-018 Pintasan keyboard global (Windows).** `Ctrl+F` cari di layar aktif, `F5` muat ulang,
`Esc` tutup panel/dialog, `Alt+1…7` pindah seksi, `Ctrl+,` Pengaturan. Pintasan grid ada di
modul masing-masing.

**FR-STU-019 Aksesibilitas dasar.** Setiap kontrol punya label semantik, bisa dijangkau keyboard
(Windows), fokus terlihat, dan target sentuh ≥ 48 dp di tablet (`tinggiKontrol` sentuh 48,
`studio/lib/tema/tema_studio.dart`).

### C. Kelola Data

Dasar: `docs/arsitektur-desktop-database.md` Keputusan 4 (baris 137-167), K-49-03, K-49-06.
Kelola Data menggantikan phpMyAdmin dengan keleluasaan yang sama **tetapi setiap perubahan
tercatat** dan tetap lewat aturan yang sama dengan API.

**FR-STU-030 Registry daftar putih.** Server memegang registry per tabel (`App\Services\KelolaData\RegistriTabel`,
`03-SDD.md` §5). Setiap tabel punya: nama tampilan, kelompok, **kelas akses** (`ubah`, `arsip`,
`baca saja`, `tersembunyi`), definisi kolom (label, tipe, `bisa_urut`, `bisa_saring`, `bisa_cari`,
`bisa_ubah`, `wajib`, aturan validasi, relasi), cara lingkup organisasi, dan alasan kelasnya.
Tabel atau kolom di luar registry tidak bisa diakses. Daftar 56 tabel di §5.

**FR-STU-031 Registry menjaga dirinya.** Test sapu skema gagal bila ada tabel di migrasi yang
tidak terdaftar di registry, atau kolom baru di tabel kelas `ubah`/`arsip` yang belum diputuskan
(tampil? ubah? rahasia?). Penjaga dua arah: tabel terdaftar yang sudah tidak ada juga gagal.

**FR-STU-032 Daftar tabel.** Dikelompokkan Master / Transaksi / Audit / Sistem; menampilkan nama
tampilan, nama tabel, kelas akses (lencana), jumlah baris dalam lingkup organisasi. Tabel
`tersembunyi` tidak muncul.

**FR-STU-033 Jelajah.** Grid berhalaman di server (bawaan 100 baris, maksimum 500). Kolom tampil
mengikuti registry; nilai relasi tampil sebagai label (mis. nama pelanggan, bukan `customer_id`)
dengan id di tooltip.

**FR-STU-034 Urut.** Hanya kolom `bisa_urut`, maksimum dua tingkat. Urutan bawaan per tabel
ditetapkan registry (mis. `updated_at` turun).

**FR-STU-035 Saring.** Per kolom `bisa_saring` menurut tipe: teks (berisi, sama dengan), angka
(=, ≠, <, ≤, >, ≥, di antara), tanggal (rentang), pilihan/enum (satu atau beberapa), boolean,
kosong/tidak kosong. Beberapa saringan digabung AND. Saringan dikirim sebagai parameter
terstruktur, bukan potongan SQL.

**FR-STU-036 Cari.** Teks bebas minimal 2 karakter pada kolom `bisa_cari` (pencocokan `LIKE`
ter-escape). Tanpa full-text.

**FR-STU-037 Tampilan arsip.** Untuk tabel ber-`SoftDeletes`: pilihan `tanpa arsip` (bawaan),
`termasuk arsip`, `hanya arsip`. Baris terarsip ditandai.

**FR-STU-038 Detail baris.** Semua kolom yang boleh tampil, relasi yang bisa dibuka (hanya ke tabel
dalam registry dan organisasi yang sama), `created_at`, `updated_at`, dan untuk tabel ber-arsip
`deleted_at`.

**FR-STU-039 Riwayat per baris.** Dari `audit_logs` dengan `entity_type` + `entity_id` (indeks
`audit_entitas_idx`). Tampil: waktu, pelaku, aksi, kolom yang berubah (lama → baru), catatan.
Untuk pembacaan teknisi, riwayat koreksi tinggal di entitas **sesi** (`CalibrationController.php:957-969`),
jadi detail sesi dan baris `raw_measurements` menautkan ke riwayat sesinya.

**FR-STU-040 Sunting baris.** Hanya tabel kelas `ubah`/`arsip`, hanya kolom `bisa_ubah`. Validasi
server **sama** dengan aturan endpoint API yang sudah menulis tabel itu hari ini (aturan diangkat ke
satu tempat yang dipakai keduanya, bukan disalin). **Alasan wajib**, 5–500 karakter (batas
`audit_logs.note` string(500), migrasi `2026_07_27_100000_create_audit_logs_table.php:50`).
Optimistic lock: request membawa `If-Match` berisi token versi baris; baris yang sudah berubah →
`409 baris_berubah` beserta nilai server terbaru.

**FR-STU-041 Tambah baris.** Hanya tabel yang definisinya `bisa_tambah` (yang hari ini punya
endpoint tambah: alat, pelanggan, standar, ruangan, metode, kategori, folder). Validasi = endpoint
yang ada. Alasan opsional untuk tambah, wajib untuk ubah.

**FR-STU-042 Arsip dan pulihkan.** Hanya kelas `arsip` (model ber-`SoftDeletes`). Alasan wajib.
Dialog konfirmasi menyebut dampak yang bisa dihitung server (mis. "alat ini dipakai 12 sesi;
sesinya tidak ikut terarsip"). Aturan penolakan arsip mengikuti endpoint hapus yang ada. `Diaudit`
mencatat `dihapus` dan `dipulihkan` (`app/Models/Concerns/Diaudit.php:107-118`).

**FR-STU-043 Tanpa hapus permanen.** Tidak ada rute Kelola Data yang memanggil `forceDelete()` atau
`delete()` pada model tanpa `SoftDeletes` (K-49-06). Untuk `users` (tanpa SoftDeletes), pengganti
arsip adalah `status = nonaktif` lewat aturan `UserController` yang ada (`UserController.php:96, 110`).
Dijaga test sapu rute: tidak ada rute Kelola Data ber-metode `DELETE`.

**FR-STU-044 Ekspor CSV.** Mengikuti kolom tampil dan saringan aktif; maksimum 50.000 baris per
ekspor (lebih dari itu → minta saring dulu); UTF-8 dengan BOM, pemisah `;` (Excel lokal Indonesia,
pelajaran `customers:impor` §16); sel yang diawali `=`, `+`, `-`, `@`, tab, atau CR diberi awalan
`'` (pelindung injeksi rumus CSV). Setiap ekspor tercatat di `audit_logs` (aksi baru `diekspor`)
dengan tabel, saringan, jumlah baris. Siapa yang boleh: bawaan `admin`; viewer/super_admin Q-49-05.

**FR-STU-045 Koreksi nilai teknisi.** Dari detail sesi atau grid `raw_measurements`, `admin` membuka
"Koreksi pembacaan". Studio memanggil jalur yang **sudah ada** `PUT /api/calibrations/{c}`, yang
menyimpan potret pembacaan lama dan baru beserta catatan di `audit_logs`
(`catatKoreksiPembacaan()`, `CalibrationController.php:945-970`). Alasan wajib. Hanya untuk sesi
yang menurut aturan hari ini masih boleh diubah admin; sesi `disetujui` atau bersertifikat →
ditolak dan diarahkan ke alur revisi sertifikat. Admin yang mengoreksi tercatat "ikut mengisi"
untuk `PemisahanWewenang` (`KODE_PENYETUJU_MENGOREKSI`, `app/Services/PemisahanWewenang.php:49`).
Kelola Data tidak pernah menulis `raw_measurements` langsung.

**FR-STU-046 Kelas baca saja.** Tidak ada kontrol ubah/arsip. Rute tulis Kelola Data untuk tabel
ini menjawab `403 tabel_baca_saja`. Bila tabel punya alur domain (setujui sesi, terbitkan ulang
sertifikat, putuskan permintaan), Studio menampilkan keterangan di mana alur itu dikerjakan;
Studio tidak membuat jalan pintas ke alur itu dari Kelola Data.

**FR-STU-047 Kelas tersembunyi.** Tidak tampil di daftar; semua rute Kelola Data untuk tabel itu
menjawab `404 tabel_tidak_dikenal` (sama dengan tabel yang tidak ada, supaya tidak mengakui
keberadaannya).

**FR-STU-048 Kolom rahasia.** Tidak pernah keluar di jelajah, detail, riwayat, maupun ekspor:
`password`, `remember_token`, token/hash token, hash OTP, dan kolom yang disensor
`Diaudit::kolomRahasia()` (`Diaudit.php:71`). Ditegakkan di registry (daftar kolom tampil adalah
daftar putih).

**FR-STU-049 Lingkup organisasi.** Setiap query lewat lingkup yang didefinisikan registry: kolom
`organization_id`, atau rantai relasi untuk tabel anak tanpa kolom itu (mis. `raw_measurements`
lewat `calibration_sessions`). Baris organisasi lain → `404`, juga untuk `super_admin` (API tetap
terkurung, `AGENTS.md` §Keadaan nyata `super_admin`). Bila kelak lintas organisasi dibuka, setiap
akses wajib lewat `JejakLintasOrganisasi` di percabangan yang sama.

**FR-STU-050 Pemilih relasi.** Kolom kunci asing tampil sebagai pemilih yang hanya memuat baris
organisasi sendiri dan tidak terarsip. Mengetik id langsung tidak didukung.

**FR-STU-051 Kolom yang memindahkan jalur hitung.** Mengubah `equipments.nama_alat_kemampuan` atau
`equipment_category_id` memindahkan alat ke profil hitung lain untuk sesi berikutnya (pencocokan
alat → profil, `AGENTS.md` §Profil kalibrasi). Aturan validasinya ikut endpoint alat yang ada; Studio
menampilkan peringatan "sesi baru alat ini akan dihitung dengan profil X" sebelum simpan.

**FR-STU-052 Konflik.** `409` menampilkan per kolom: nilai saya, nilai server terbaru, dan siapa
yang mengubah. Pilihan: muat ulang (isian saya tetap terlihat sebagai catatan di layar, tidak
dikirim) atau batal.

**FR-STU-053 Validasi lintas baris.** Pelanggaran unik/FK dijawab `422 nilai_tidak_valid` dengan
pesan yang menyebut kolom dan nilai yang bentrok, bukan pesan SQL.

### D. Data Acuan

Isi modul ini = P2 CR-48 yang dipindah ke Studio. Fondasi baca (P1) sudah ada di branch
`feat/studio-data-acuan`: tujuh rute `GET /api/data-acuan/*` (`routes/api.php:421-429`), izin
`data-acuan.lihat` (`MatriksIzin.php:134`), stempel `paket_acuan_versi_id`.

**FR-STU-070 Daftar paket dan versi.** Memakai `GET /api/data-acuan/paket` dan garis waktu (P1).
Profil yang punya skema tapi belum diimpor tampil abu-abu "belum diimpor".

**FR-STU-071 Grid lembar acuan.** Tab per lembar dari `skemaAcuan()` profil (CR-48 FR-02). Angka
tampil dengan koma desimal; nilai persis di tooltip dan panel sel (`JSON_PRESERVE_ZERO_FRACTION`,
`docs/kontrak-api.md` §15).

**FR-STU-072 Buat draf.** CR-48 FR-05. Draf baru menyalin isi versi dasar persis (sha256 isi = dasar).

**FR-STU-073 Sunting sel.** CR-48 FR-06 dan BR-04 (koma dan titik desimal diterima; pemisah ribuan
ditolak sebagai ambigu). Simpan otomatis per 2 detik diam, per batch.

**FR-STU-074 Tempel blok.** CR-48 FR-07: pratinjau sel berubah dan sel ditolak sebelum diterapkan.

**FR-STU-075 Tambah/hapus baris di draf.** CR-48 FR-08, hanya di lembar yang skemanya mengizinkan.
"Hapus baris" mengubah **isi draf**, bukan menghapus baris database; versi beku tidak tersentuh.

**FR-STU-076 Kunci edit.** CR-48 FR-09: `lock_versi` + `If-Match`; usang → `409 draf_berubah`.

**FR-STU-077 Banding versi.** CR-48 FR-11 (rute P1 sudah ada).

**FR-STU-078 Riwayat sel dan garis waktu.** CR-48 FR-03/FR-28 (rute P1 sudah ada).

**FR-STU-079 Simulasi dampak.** CR-48 FR-12/FR-13, memakai tabel simulasi bersama dengan Lembar
Hitung (`03-SDD.md` §4.4).

**FR-STU-080 Vektor pembanding.** CR-48 FR-14. Selama toleransi Lab (K-48-07) belum diisi, vektor
dilaporkan beserta selisihnya **tanpa** vonis lulus; alat itu belum boleh disebut selesai.

**FR-STU-081 Ajukan.** CR-48 FR-15.

**FR-STU-082 Sahkan orang kedua.** CR-48 FR-16 dengan K-49-05: `super_admin`, bukan pembuat,
penyunting, pengaju, atau pengunggah versi itu.

**FR-STU-083 Tolak dan kembalikan.** CR-48 FR-17.

**FR-STU-084 Jadwal dan berlaku mundur.** CR-48 FR-18 dan BR-09.

**FR-STU-085 Tarik versi aktif.** CR-48 FR-19.

**FR-STU-086 Buang draf.** Pemilik draf yang belum pernah diajukan boleh membuangnya: status
`dibuang`, baris tetap ada, tidak bisa dipakai lagi.

**FR-STU-087 Butir "dibawa ke P2" dari §48.** Wajib di S3:
(a) `lembar-kerja` memilih versi menurut `tanggal_kalibrasi` sesi yang sedang dibuka (sesi baru:
tanggal yang diisi), bukan tanggal hari ini; klien mengirim balik sha versi yang ditampilkan dan
server menolak simpan bila berbeda dari versi berlaku (`versi_acuan_berubah`, 409);
(b) template OCR (`TemplateLembarKerja`) dan `dalamLingkupAkreditasiSesi` di
`CertificateSnapshotBuilder` membaca dalam lingkup versi berstempel;
(c) temuan validator PERINGATAN baru bila stempel tidak seragam antar titik, atau menunjuk versi
organisasi/paket lain (`ringkasanStempel` ikut memeriksa `kode_profil`);
(d) penjaga bahwa isi versi yang terkunci tidak bisa diubah atau dihapus, ditegakkan di model
(bukan hanya pemeriksaan sha saat cache miss).

**FR-STU-088 Pengingat standar.** CR-48 FR-29, ditampilkan di Beranda Studio (tanpa push).

**FR-STU-089 Teknisi hanya versi aktif.** K-48-06; sudah ditegakkan P1 dan tetap berlaku untuk rute
tulis baru (teknisi tidak punya izin tulis apa pun di modul ini).

### E. Lembar Hitung

**Model**

**FR-STU-100 Satu Lembar Hitung per rumus profil.** Rumus diidentifikasi `formulas.kode` =
`CalibrationProfile::kodeFormula()` (pola `RumusKalibrasi::formulaUntukProfil()`,
`app/Services/RumusKalibrasi.php:103-120`). Versi Lembar Hitung disimpan sebagai
`formula_versions` dengan `sumber = database` (`FormulaVersion::SUMBER_DATABASE`,
`app/Models/FormulaVersion.php:44`). Versi `sumber = kode` yang ada tetap sah dan tetap dihitung
profil PHP; sertifikat lama bisa dihitung ulang persis (`AGENTS.md` §Olah data butir 2).

**FR-STU-101 Isi Lembar Hitung.** Daftar sheet; tiap sheet berisi sel beralamat A1 berupa rumus
(teks diawali `=`), angka (string desimal kanonik), teks, atau kosong; metadata sel (label, satuan,
format tampil, catatan, asal master `Sheet!Sel` + sha256 workbook bila ada, tanda `tampilan`);
named range lingkup buku; tabel acuan terikat; mode iterasi `per_titik` atau `per_sesi`. Format
kanonik di `03-SDD.md` §6.

**FR-STU-102 Pengikat masukan.** Sel atau rentang masukan diisi server dari sumber yang disebut
pengikat, dan **tidak bisa diketik** di editor. Jenis sumber:

| Jenis | Isi | Contoh |
|---|---|---|
| `pembacaan` | `raw_measurements` per `titik_ke`, disaring `peran_sensor`, `sensor_ke`, `tahap`, diurut `pembacaan_ke` | tiga berat isi Volumetric: `peran_sensor = vol_isi` |
| `titik` | atribut titik: `titik_ukur`, `titik_ke` | nominal |
| `spesifikasi` | jalur di `spesifikasi_alat` sesi | `volumetric.kelas`, `volumetric.toleransi_ml` |
| `sesi` | `tanggal_kalibrasi`, suhu/kelembaban/tekanan awal dan akhir, lokasi | rata-rata kondisi ruang |
| `acuan` | rentang tabel Data Acuan, dibaca lewat kelas `Tabel*` yang **sama** dengan profil (lingkup versi `PenentuVersiAcuan`), versi dari stempel | `koreksi_suhu.kalibrator` |
| `standar` | kolom tabel `standards` yang dipilih sesi | serial, U95 sertifikat |
| `resolver` | fungsi PHP ber-daftar-putih milik profil untuk hal yang tidak bisa ditulis sebagai rumus (mis. pita CMC terdekat lewat query ber-lingkup organisasi); tiap resolver punya test sendiri | `volumetric.cmc_terdekat(kapasitas)` |

Pengikat ke kunci yang tidak dihasilkan `bentukLembarKerja()`/`*Mentah` profil itu ditolak
`kontrak_input_dilanggar` (FR-STU-186).

**FR-STU-103 Pengikat keluaran.** Lembar menyetor ke server: `rata_rata`, `error`, `koreksi`,
`standar_deviasi`, `jumlah_pengulangan`, `type_a`; **tabel komponen budget** (per baris: `sumber`,
`keterangan`, `distribusi`, `u_diperluas`, `pembagi`, `u`, `ci`, `vi`); `cmc`; opsional `k_tetap`
dan `toleransi`; catatan penyimpangan (FR-STU-128); dan **blok `agregat`** wajib: `uc`, `veff`,
`k`, `u_hitung`, `u95` (sesudah lantai CMC), `keputusan`. Sejak K-49-07 angka agregat yang
disimpan dan dicetak untuk alat ber-Lembar Hitung **dibaca dari blok `agregat` lembar**
(ADR-49-08, `AGENTS.md` §Profil kalibrasi untuk alat ber-Lembar Hitung), dengan `GumCalculator`
sebagai pengawas (FR-STU-134). `type_b` dan baris `perbandingan_cmc`
(`CalibrationProfile::barisPerbandinganCmc()`, `CalibrationProfile.php:2344`) tetap dibangun
evaluator dari komponen dan agregat lembar, supaya gerbang validator yang ada tetap bekerja.

**FR-STU-104 Aturan tahan.** Lembar mendeklarasikan daftar `{sel, pesan}`: bila sel itu galat
(`#N/A`, `#VALUE!`, `#DIV/0!`, `#NUM!`, `#REF!`) atau kosong, titik (mode `per_titik`) atau
seluruh sesi (mode `per_sesi`) ditahan dengan pesan itu. Sel keluaran angka yang galat, kosong,
teks, NaN, atau tak hingga **selalu** menahan titiknya walau tidak dideklarasikan, dengan pesan
otomatis yang menyebut alamat selnya. Pengecualian hanya untuk keluaran yang memang opsional
(`cmc`, `toleransi`, `k_tetap`): `#N/A` atau kosong di sana berarti "tidak ada" (tanpa lantai CMC,
tanpa keputusan, k dari t-Student), sama dengan perilaku profil hari ini (`$cmc['cmc'] ?? 0.0`,
`VolumetricGlasswareProfile.php:367`). Tidak ada keluaran yang dibaca nol dari sel kosong
(`AGENTS.md` §Aturan yang Lahir dari Kesalahan Nyata, butir `IFERROR(…,"")`).

**FR-STU-105 Fungsi yang didukung.** Hanya daftar putih di `03-SDD.md` §6.4. Fungsi lain →
`fungsi_tidak_didukung` saat simpan. Sesudah spike S0a (10 Okt 2026, `03-SDD.md` §6.4 butir 1 dan
3): `k` di blok agregasi (ikut jalur terbit, K-49-07) memakai fungsi terdaftar `SIDIK.KFAKTOR`, dan
simpangan baku memakai `SIDIK.STDEV`; `STDEV`/`STDEV.S` mesin (rumus satu lintasan, beda s.d.
1,4·10⁻⁴ relatif pada data terkondisi buruk) dan `TINV`/`T.INV.2T` mesin (2.162,91 ms pada df 10⁶)
ditolak. Setiap fungsi di daftar putih wajib punya test perilaku lawan nilai Excel acuan. Perilaku
tepi yang sudah diukur spike: `TINV` dengan derajat bebas pecahan dipotong ke bawah seperti Excel;
`VLOOKUP` cocok-terdekat dan `MATCH` tipe 0 (termasuk atas hasil `MIN(ABS(…))`) sama dengan Excel;
`STDEV(IFERROR(rentang,""))` membaca sel kosong sebagai 0 seperti Excel, karena itu
`IFERROR(…,"")` di hulu keluaran tetap ditolak (FR-STU-104). ⏳ Sisa: perbandingan teks peka huruf
(`=`, `MATCH`), bentuk sel kosong/NaN di keluaran, dan `SIDIK.STDEV` atas sel kosong — tindak lanjut
ST-T-090, menunggu S4 (per 13 Okt 2026 belum diuji).

**FR-STU-106 Fungsi volatile dan berisiko.** `NOW()` dan `TODAY()` ditolak; penggantinya adalah
pengikat `sesi.tanggal_kalibrasi` (beku per sesi), mengikuti preseden yang sudah memperlakukan
`NOW()` di master sebagai kerusakan (`app/Services/Calibration/MicrometerCalculator.php:55, 164`;
`HeightGaugeCalculator.php:77, 210`). `OFFSET`, `INDIRECT`, `RAND`, `RANDBETWEEN`, `RANDARRAY`,
`CELL`, `INFO` ditolak dan harus ditulis ulang (mis. `OFFSET` → `INDEX`). `WEBSERVICE`,
`FILTERXML`, `ENCODEURL`, `HYPERLINK` ditolak (I/O jaringan atau tidak relevan); `WEBSERVICE` ada
di tabel fungsi PhpSpreadsheet 5.10.0 (`vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Calculation/FunctionArray.php:2578`),
jadi penolakannya wajib di validator kita, bukan diandalkan dari mesin.

**FR-STU-107 Rujukan melingkar ditolak.** Graf ketergantungan statis dihitung saat simpan; siklus →
`rujukan_melingkar` dengan rantai selnya. Mesin dijalankan dengan iterasi siklik dimatikan
(`cyclicFormulaCount = 0`); bawaan PhpSpreadsheet `1` (`Calculation.php:166`) diam-diam
mengizinkan satu putaran.

**FR-STU-108 Rujukan luar dan rusak ditolak.** Rujukan `[n]Sheet!A1`, nama yang menunjuk buku
lain, `#REF!` di dalam rumus, dan rujukan ke sheet yang tidak ada → ditolak saat simpan.

**FR-STU-109 Pembulatan hanya di tampilan.** `ROUND`, `ROUNDUP`, `ROUNDDOWN`, `TRUNC`, `MROUND`,
`FIXED`, `TEXT` ditolak di sel yang menjadi hulu keluaran terikat (`AGENTS.md` §Olah data butir 5);
boleh di sel bertanda `tampilan` yang tidak punya hilir keluaran.

**FR-STU-110 Batas ukuran.** Batas sementara: ≤ 30 sheet, ≤ 20.000 sel berisi, satu rumus
≤ 8.192 karakter (batas Excel), kedalaman ketergantungan ≤ 512, isi kanonik ≤ 4 MB. Lewat batas →
`lembar_terlalu_besar` dengan angka batasnya. Spike S0a hanya mengukur lembar kecil (3 sheet, 116
sel keluaran: 103,0 ms total, ±0,9 ms per sel keluaran termasuk hulunya; puncak memori ±314 KiB;
`tests/Unit/Spike/MesinRumusSpikeTest.php:352-426`), jadi batas di atas belum bisa disetel dari
bukti. ⏳ Tindak lanjut ST-T-093 (validator ukuran) + ST-T-098 (pratinjau), menunggu pengukuran
lembar sintetis 5.000 dan 20.000 sel di S4 (per 13 Okt 2026 belum diukur).

**Penyuntingan**

**FR-STU-111 Editor sel.** Ketik nilai/rumus, salin-tempel (rujukan relatif bergeser seperti Excel,
rujukan absolut `$A$1` tidak), isi ke bawah/kanan, sisip/hapus baris dan kolom di draf (rujukan
disesuaikan), urungkan/ulangi dalam sesi suntingan, simpan otomatis per batch dengan `lock_versi`.
Rinci interaksi di `04-UI-UX-Flow.md` ST-31.

**FR-STU-112 Pemeriksaan statis langsung.** Setiap simpan mengembalikan galat statis per sel:
fungsi tidak didukung/volatile, siklus, rujukan luar/rusak, pembulatan di hulu keluaran, sel
terikat yang diketik, keluaran wajib yang tidak terikat, nama tanpa definisi. Draf boleh disimpan
bergalat; **pengajuan** butuh nol galat statis.

**FR-STU-113 Pratinjau per sesi.** Pengguna memilih sesi tersimpan (organisasi sendiri, profil
sama) atau vektor master. Server mengevaluasi draf dengan input sesi itu dan versi Data Acuan dari
stempel sesi, lalu memulangkan nilai setiap sel yang diminta, keluaran, hasil pengawas `GumCalculator`, dan
perbandingan tiga kolom: **tersimpan** (`uncertainty_calculations`), **profil PHP hari ini**,
**draf**. Tidak menyimpan apa pun dan tidak mengubah stempel.

**FR-STU-114 Jejak ketergantungan.** Untuk sel terpilih: daftar hulu (rumus yang dirujuk) dan hilir
(sel yang merujuknya), lintas sheet dan named range, sampai kedalaman yang diminta; dari keluaran
sampai pengikat masukan.

**FR-STU-115 Banding versi.** Dua versi Lembar Hitung: sel ditambah/dihapus/diubah (rumus lama →
baru, nilai lama → baru), named range dan pengikat yang berubah, ringkasan jumlah per sheet.

**Simulasi, paritas, bayangan**

**FR-STU-116 Simulasi semua sesi.** Job antrean menjalankan draf ke semua sesi tersimpan profil itu
(semua status) + semua vektor master. Laporan per sesi: nilai antara berubah (kolom, lama, baru,
selisih absolut dan relatif), U95 berubah, **angka cetak berubah** (sesudah pembulatan per alat),
keputusan berubah, lantai CMC terpicu/lepas, titik tertahan berubah, sudah terbit atau belum.
Tidak menyimpan ke sesi. Ringkasan: total, berubah, cetak berubah, cetak berubah pada sertifikat
terbit, keputusan berubah.

**FR-STU-117 Simulasi kedaluwarsa.** Suntingan sesudah simulasi menandai simulasinya
`kedaluwarsa`. Pengajuan butuh simulasi `selesai` atas sha256 isi yang diajukan
(`simulasi_belum_ada`, `simulasi_kedaluwarsa`).

**FR-STU-118 Gerbang paritas.** Versi Lembar Hitung **pertama** sebuah rumus (belum pernah ada
versi `sumber = database` yang aktif untuk rumus itu) hanya bisa diajukan bila laporan paritas
atas sha256 isi yang sama **LULUS** menurut definisi `01-PRD.md` §5 pada himpunan S (semua sesi
tersimpan + vektor master + sesi seeder) **ditambah** laporan bayangan (FR-STU-119). Laporan
memuat jumlah identik bit / dalam toleransi / gagal per besaran dan daftar gagal, serta nama
pemeriksa independen yang memeriksanya (Q-49-02). Gagal satu = tidak bisa diajukan
(`paritas_belum_lulus`).

**FR-STU-119 Bayangan, lalu pengawasan.** Urutan untuk versi pertama: **bayangan → paritas →
aktif** (ADR-49-04).
(a) *Bayangan, sebelum aktif:* kandidat beku didaftarkan (`POST …/bayangan`); setiap sesi nyata
profil itu yang disimpan/dihitung ulang tetap dihitung dan distempel profil PHP, dan kandidat
dievaluasi di belakang (antrean, sesudah simpan selesai, tidak pernah menggagalkan simpan) lalu
dibandingkan menurut definisi paritas. Suntingan kandidat → bayangan `kedaluwarsa`. Minimal
jumlah sesi/lama bayangan: Q-49-03.
(b) *Pengawasan, sesudah aktif:* versi pertama yang berlaku bertanda `setara_php`; setiap hitung
sesi juga menjalankan profil PHP. Beda → temuan validator `paritas_php_beda` (PERINGATAN) yang
menahan terbit sampai ditinjau. `setara_php` lepas otomatis begitu versi berikutnya berlaku.

**FR-STU-120 Versi berikutnya.** Versi kedua dan seterusnya tidak diadu ke PHP sebagai syarat
(perbedaan memang disengaja), tetapi wajib simulasi (FR-STU-116) dan pengesahan. Perbandingan
dengan PHP tetap ditampilkan sebagai informasi selama profil PHP masih ada.

**Alur versi**

**FR-STU-121 Ajukan.** Wajib: nol galat statis, simulasi terbaru `selesai`, paritas lulus (bila
versi pertama), setiap beda agregat dari pengawas `GumCalculator` punya catatan penyimpangan
bersumber (FR-STU-135), `alasan` (≥ 10 karakter), `rujukan[]` ≥ 1, dan **sumber metode**: nomor IK +
revisi, sel master yang menjadi dasar (atau pernyataan "keputusan Lab, tanpa sel master"), siapa
yang memutuskan, tanggal keputusan. Isi dibekukan dan `isi_sha256` dihitung.

**FR-STU-122 Sahkan.** Hanya `super_admin` yang bukan pembuat, penyunting, pengaju, atau pengunggah
workbook versi itu (`PemisahanWewenang`, kode `pengesah_ikut_menyunting`). Konfirmasi sandi.
`berlaku_mulai` ≥ tanggal pengesahan kecuali syarat berlaku mundur (CR-48 FR-18: simulasi
menunjukkan nol sertifikat terbit yang angka cetaknya berubah). Rentang versi sebelumnya ditutup
dalam **satu transaksi**, tanpa tumpang tindih (`FormulaVersion::bentrokRentang()`,
`FormulaVersion.php:118`). Hasil: `terjadwal` atau `aktif`.

**FR-STU-123 Tolak, kembalikan, tarik.** Tolak: final, alasan wajib. Kembalikan: versi jadi
`ditolak` dan lahir draf baru milik pengaju berdasar isi itu, catatan wajib. Tarik (versi aktif,
alasan wajib): versi sebelumnya berlaku lagi untuk sesi baru; sesi belum terbit yang dihitung
dengan versi tertarik ditahan (`versi_rumus_ditarik`) sampai dihitung ulang atau diputuskan.

**FR-STU-124 Berlaku menurut tanggal kalibrasi.** Versi untuk sebuah sesi = versi yang rentangnya
memuat `tanggal_kalibrasi` sesi (`RumusKalibrasi::versiUntukSesi()`, `RumusKalibrasi.php:53-79`),
bukan versi aktif hari ini.

**FR-STU-125 Stempel.** Setiap `uncertainty_calculations` menyimpan `formula_version_id` versi yang
benar-benar dipakai (dan `paket_acuan_versi_id` seperti P1). Validator memeriksa kesepadanan
stempel dengan versi berlaku (pola `periksaVersiRumus()`, `CalibrationValidator.php:2140`).
Hasil hitung tanpa stempel = cacat jalur simpan (`AGENTS.md` §Olah data butir 2).

**FR-STU-126 Satu fungsi untuk semua jalur.** Store, update, preview, `CalibrationValidator`, dan
`HitungUlangSesi` memanggil **evaluator yang sama**, memilih jalur dari versi: `sumber = kode` →
profil PHP; `sumber = database` → evaluator Lembar Hitung. Tidak ada jalur kedua yang menghitung
Lembar Hitung dengan cara lain (pelajaran "alat baru wajib lahir bareng jalur hitung ulangnya",
`AGENTS.md` §Aturan yang Lahir dari Kesalahan Nyata).

**FR-STU-127 Log metode append-only.** Per rumus, garis waktu versi beku + sumber metode + transisi
status di `audit_logs` membentuk log metode. Tidak ada yang bisa diubah sesudah diajukan. Ekspor
"Log Metode" per rumus (CSV dan Markdown). Log metode tekanan/piston yang ada
(`database/data/log-metode-tekanan-piston.json`, dibaca `app/Support/LogMetodeTekananPiston.php`)
tetap berlaku untuk versi `sumber = kode` alat itu.

**FR-STU-128 Catatan penyimpangan dari master.** Lembar Hitung yang menghitung berbeda dari master
wajib mendeklarasikan catatan `{kode, pesan bertemplate, nilai}` yang ditulis ke
`type_b_components` setiap hasil, menyebut sumbernya (IK, butir lampiran, atau alamat sel master)
(`AGENTS.md` §Olah data butir 4). Contoh yang sudah ada: `volumetric_veff_dibagi_jumlah`
(`app/Services/Calibration/Profiles/FixedVolumetricGlasswareProfile.php:74`).

**FR-STU-129 Kerusakan master tidak ditiru.** `#REF!`, tautan luar `[n]`, dan rujukan meleset tidak
dibawa ke Lembar Hitung; dihitung benar dan wajib disertai catatan penyimpangan (FR-STU-128).
Kejanggalan **metode** (bukan kerusakan salin-tempel) ditiru dan diangkat sebagai pertanyaan lab.
Siapa yang memutuskan sebuah kasus termasuk "kerusakan" atau "kejanggalan metode" di Studio:
Q-49-14.

**FR-STU-130 Konstanta.** Angka literal ditulis sebagai sel konstanta (sebaiknya bernama) atau
literal di dalam rumus, disimpan sebagai string desimal kanonik, boleh membawa asal (sel master/IK).
Satuan sel hanya metadata; tidak ada konversi satuan otomatis.

**FR-STU-131 Hak lihat.** `admin`, `viewer`, `super_admin` melihat semua versi Lembar Hitung
organisasinya. Teknisi Q-49-04 (bawaan: tidak).

**FR-STU-132 Versi mesin terkunci.** Saat pengesahan, nama dan versi mesin rumus (dari
`composer.lock`) dicatat di versi. Test CI gagal bila versi mesin terpasang berbeda dari versi
yang tercatat pada versi aktif mana pun, sampai paritas/simulasi dijalankan ulang dan dicatat.
Di runtime, beda versi memunculkan temuan validator `mesin_rumus_berbeda` (PERINGATAN, menahan
terbit) dan tanda di `/api/health`.

**FR-STU-133 Varian dan keluarga.** Satu profil satu rumus. Varian dalam satu profil (Micrometer
A–D, Timbangan gram/kg/substitusi, Volumetric Fixed per kapasitas) menjadi sheet atau cabang di
Lembar Hitung yang sama, dipilih lewat pengikat `spesifikasi`. Profil yang berbagi kelas induk
(Labu Ukur, Pipet Volume, Picnometer) punya rumus masing-masing; Studio menyediakan "salin ke rumus
lain" yang tetap melewati gerbang paritas per rumus.

**Agregasi di lembar (K-49-07)**

**FR-STU-134 Blok agregasi bawaan dan pengawas `GumCalculator`.** (a) Setiap Lembar Hitung baru
(tulis tangan, salinan, atau hasil impor) lahir dengan blok agregasi GUM bawaan yang setara
`GumCalculator`: `uc = √Σ(ui·ci)²`; penyebut Welch–Satterthwaite = jumlah `(ui·ci)⁴/vi` semua baris
ber-`vi > 0` dan `ui·ci ≠ 0`; veff dibatasi `MAKS_V_EFF` dan kosong bila penyebut nol; `k` = `k_tetap`
bila diisi, kalau tidak t-quantile 0,975 dengan derajat bebas `max(1, floor(veff))`, atau 2 bila veff
kosong; `U = k·uc`; U95 = `max(U, CMC)` bila ada CMC; PASS bila `|error| + U95 ≤ toleransi`
(`GumCalculator.php:552-607, 475, 888-895`; rumus sel di `03-SDD.md` §6.2). Impor `.xlsm` tidak
mengganti blok bawaan dengan agregasi master; agregasi master yang berbeda dilaporkan sebagai temuan
impor. (b) Setiap evaluasi lembar (sesi nyata, hitung ulang, pratinjau, simulasi, paritas) juga
menghitung agregasi `GumCalculator` atas komponen yang sama sebagai **pengawas**. Beda menurut
definisi paritas (`01-PRD.md` §5 syarat 1–3, termasuk angka cetak) → di sesi nyata: temuan
validator `agregasi_beda_dari_gum` (PERINGATAN, **tidak** menahan terbit) di jejak sesi berisi kunci,
nilai lembar, nilai pengawas, selisih, dan kode catatan penyimpangan yang menjelaskannya; di
pratinjau: blok `pengawas_gum`; di simulasi: kolom `agregasi_beda_gum` per sesi dan di ringkasan.
Pengawas tidak bisa dimatikan dari Studio.

**FR-STU-135 Penyimpangan agregasi wajib bercatatan.** Versi yang simulasinya menunjukkan agregat
beda dari pengawas hanya bisa diajukan bila versi itu mendeklarasikan catatan penyimpangan
bersumber (FR-STU-128: IK, butir lampiran, alamat sel master, atau keputusan Lab bernomor) yang
menjelaskan bedanya; kalau tidak → `422 agregasi_beda_tanpa_catatan`. Ini penegakan `AGENTS.md`
§Olah data butir 4, bukan larangan menyunting. Kesalahan master yang sudah diketahui (veff
Volumetric Fixed dibagi satu baris `K43`, `pertanyaan-lab-volumetric.md` no. 1) tidak ditiru di
versi pertama (gerbang paritas); menirunya butuh versi baru, simulasi, pengesahan, dan jawaban lab
yang dirujuk di catatan.

### F. Impor `.xlsm`

**FR-STU-160 Unggah.** `.xlsm` atau `.xlsx` ≤ 20 MB untuk satu profil. Jenis diperiksa dari isi
(arsip ZIP OOXML), bukan ekstensi. Ukuran terurai dibatasi (≤ 200 MB) untuk mencegah bom zip.
Diproses di antrean.

**FR-STU-161 Workbook terenkripsi.** Pengguna mengetik sandi di dialog Studio. Sandi ikut request
yang sama lewat HTTPS, dipakai di memori untuk mendekripsi, lalu dibuang: tidak disimpan di
database, berkas, antrean (payload job), log, atau audit, dan tidak pernah dikirim ke agen. Sandi
salah → `422 sandi_workbook_salah`. ⏳ **Cara dekripsi menunggu spike S0c** (lihat `03-SDD.md` ADR-49-10)
— tindak lanjut ST-T-006, menunggu `.xlsm` asli Volumetric + Micrometer dari pemilik (ST-T-008);
per 13 Okt 2026 belum dimulai.

**FR-STU-162 Yang dibaca.** Sheet, nilai dan rumus asli (A1), array formula, named range beserta
definisinya, format angka tampil. Makro VBA tidak pernah dijalankan.

**FR-STU-163 Data pelanggan tidak diimpor.** Sheet `DATABASE` dan sheet lain yang ditandai berisi
data pelanggan tidak dibaca, kecuali sel yang dipetakan eksplisit (mis. pita CMC) dan tanpa
nama/alamat pelanggan (BR-13 CR-48; sanitasi K27).

**FR-STU-164 Laporan per sel.** Fungsi tidak didukung, volatile, rujukan luar `[n]`, `#REF!`,
rujukan diri, siklus, array formula, nama tanpa definisi, `IFERROR(…,"")` yang menjadi hulu
keluaran, rujukan yang diduga meleset (alamat bergeser dari pola tetangganya). Setiap temuan punya
kode dan usulan tindakan.

**FR-STU-165 Hitung ulang pembanding.** Sesudah impor, mesin menghitung ulang workbook dengan isian
contoh yang ada di workbook dan membandingkannya dengan nilai cache workbook; beda dilaporkan
(cache usang atau mesin beda) sebelum apa pun dipakai.

**FR-STU-166 Hasil impor = draf.** Draf Lembar Hitung baru, tidak pernah aktif otomatis. Pengikat
masukan **diusulkan** dari sheet `INPUT DATA` dan wajib dikonfirmasi satu per satu. Asal setiap sel
= `berkas!Sheet!Sel` + sha256 workbook.

**FR-STU-167 Berkas asli.** Bawaan: yang disimpan hanya sha256, nama asli, ukuran, waktu, pengunggah,
dan laporan. Berkasnya tidak disimpan (Q-49-06).

**FR-STU-168 Unggahan kembar.** sha256 sama di organisasi sama → laporan lama ditampilkan; diproses
ulang hanya bila diminta.

### G. Sinkron ke aplikasi lapangan & sertifikat

**FR-STU-180 Server satu-satunya evaluator.** Aplikasi lapangan tidak berubah cara hitungnya: tetap
memanggil `/calibrations/preview` dan `/perhitungan` server (ADR-49-01).

**FR-STU-181 Versi rumus di lembar kerja.** `GET /api/calibrations/lembar-kerja` menambah blok
`versi_rumus = {kode_formula, nomor, sumber, sha256}` di samping `versi_acuan` (additive). `ETag`
mencakup keduanya.

**FR-STU-182 Siaran.** `PerubahanDataOrganisasi::siarkanAman()` jenis `lembar_hitung` saat versi
aktif, terjadwal, atau ditarik. Klien menarik ulang saat sinyal, saat lembar dibuka (ETag), dan
lewat tarikan 3 menit yang sudah ada.

**FR-STU-183 Draf terdampak.** Banner draf terdampak (CR-48 FR-23) di aplikasi lapangan juga
menyebut perubahan versi Lembar Hitung.

**FR-STU-184 Snapshot sertifikat.** Snapshot dan PDF memuat "Lembar Hitung `<kode_formula>` v`<n>`
(sha256 8 karakter)" bila `sumber = database`. Kunci additive, hanya ditulis bila berstempel,
`VERSI` snapshot tetap (pola P1, supaya `sertifikat:bangun-ulang` tidak menulis ulang sertifikat lama).

**FR-STU-185 Menu Data Acuan di aplikasi lapangan dicabut** dari PR M1/M2 (`06-rencana-rescope.md`
butir 6). Aplikasi lapangan tetap membaca `versi_acuan` dan `versi_rumus` untuk label.

**FR-STU-186 Kontrak input.** Pengikat masukan Lembar Hitung hanya boleh merujuk kunci yang
dihasilkan `bentukLembarKerja()` profil dan `App\Support\*Mentah`-nya. Kunci yang tidak ada →
`kontrak_input_dilanggar` saat simpan. Lapis 3 (bentuk lembar) tidak diubah oleh Lembar Hitung.

### H. Audit & jejak

**FR-STU-190 Tulis lewat model beraudit.** Setiap tulis dari Studio lewat Eloquent pada model yang
memakai `Diaudit` (23 model per 9 Okt 2026, dihitung dari pernyataan `use` di `app/Models/*.php`).
Alasan pengguna masuk `audit_logs.note`. Model tanpa `Diaudit` tidak boleh kelas `ubah`/`arsip`.

**FR-STU-191 Suntingan per sel append-only.** Suntingan sel Data Acuan (`paket_acuan_suntingan`)
dan Lembar Hitung (tabel baru `lembar_hitung_suntingan`, `03-SDD.md` §4.2) mencatat lama, baru,
sumber (`ketik`, `tempel`, `impor`, `ambil_alih`, `sisip_baris`…), user, waktu; model menolak
update/delete (pola `PaketAcuanSuntingan.php:60-72`).

**FR-STU-192 Transisi status tercatat.** Ajukan, sahkan, tolak, kembalikan, tarik, buang: pelaku,
waktu, alasan; lewat `Diaudit` pada `FormulaVersion`/`PaketAcuanVersi` plus kolom pelaku.

**FR-STU-193 Aksi baca berbiaya tercatat.** Ekspor CSV, simulasi, paritas, impor: pelaku, waktu,
parameter (tanpa data pribadi, tanpa sandi).

**FR-STU-194 Bertindak sebagai diri sendiri.** `super_admin` yang bertindak tercatat sebagai
dirinya, tidak pernah menyamar (`AGENTS.md` §Peran butir 3).

**FR-STU-195 Jejak sesi.** Layar perhitungan sesi (Studio dan aplikasi lapangan admin) menampilkan
versi Lembar Hitung + versi Data Acuan + catatan penyimpangan + temuan pengawasan PHP.

**FR-STU-196 Laporan Perubahan per versi.** CSV + Markdown (PDF sebaiknya) berisi beda, simulasi,
paritas (bila ada), sumber metode, pengaju, pengesah, untuk rekaman mutu.

---

## 2. Kebutuhan non-fungsional

| ID | Kategori | Kebutuhan | Cara uji |
|---|---|---|---|
| NFR-STU-01 | Akurasi | Nilai antara tidak dibulatkan; angka acuan dan konstanta disimpan string desimal kanonik dan diubah ke float di **satu** titik; isi kanonik + sha256 stabil lintas SQLite dan MySQL | `JsonKanonikTest`; test sha256 Lembar Hitung di kedua suite |
| NFR-STU-02 | Determinisme | Input sama → keluaran identik bit; tanpa fungsi volatile; tanpa cache perhitungan lintas evaluasi (pola jebakan cache statis CR-48 §4) | test evaluasi dua kali berurutan dengan input beda lalu sama |
| NFR-STU-03 | Kinerja server | Mesin rumus, disetel dari spike S0a (10 Okt 2026; lembar Volumetric Fixed 1 titik: bangun 11,82 ms + evaluasi 107,98 ms median, ≈26× jalur PHP 4,148 ms; `03-SDD.md` §9): pratinjau 1 sesi (≤ 10 titik) p95 ≤ 3 s **dipertahankan**, sinkron (perkiraan ±1,2 s untuk 10 titik); simulasi 500 sesi ≤ 10 menit **wajib** di antrean (perkiraan ±1 menit bila 1 titik/sesi, ±10 menit bila 10 titik/sesi, tepat di batas); kandidat bayangan dievaluasi di job sesudah simpan (`03-SDD.md` §7.5), tetapi sesudah versi aktif simpan sesi ikut memanggil mesin (±120 ms per titik menurut spike). Perkiraan itu ekstrapolasi linear dari laptop kerja, diukur ulang di ST-T-098/099 dan di Render saat bayangan pilot (ST-T-103). Di luar mesin (tidak diukur spike, target awal tetap): Kelola Data 100 baris p95 ≤ 500 ms (ST-T-033); simpan batch sel draf p95 ≤ 300 ms (ST-T-097); periksa statis 5.000 sel ≤ 1 s (ST-T-093) | log durasi + test beban sintetis |
| NFR-STU-04 | Kinerja klien | Grid 2.000 × 20 (Kelola Data, Data Acuan) dan 200 × 30 sel berumus (Lembar Hitung) bergulir ≥ 55 fps di laptop kantor dan tablet uji; masuk sampai Beranda ≤ 3 s di jaringan kantor | profil Flutter, rekam frame time |
| NFR-STU-05 | Keamanan | Lihat §3 (OWASP ASVS) | test HTTP per rute; peninjau |
| NFR-STU-06 | Isolasi organisasi | Semua rute ber-parameter: organisasi lain → 404; rute baru otomatis masuk `SapuRuteIsolasiOrganisasiTest`, `RuteInternalMenolakRoleLainTest`, `BatasAntarLabTest` | test sapu yang membaca daftar rute |
| NFR-STU-07 | Ketersediaan | Studio mati tidak menghentikan perhitungan server (versi aktif tetap dipakai). Simulasi/paritas idempoten per sesi dan bisa dilanjutkan sesudah worker restart. Gagal jaringan tidak menghilangkan suntingan yang sudah tersimpan | test job dihentikan di tengah |
| NFR-STU-08 | Offline & penyimpanan lokal | Offline = baca saja. Isi Data Acuan dan Lembar Hitung (tanpa data pribadi) boleh di-cache di disk; baris Kelola Data yang memuat data pribadi **tidak** di-cache di disk sampai Q-49-13 dijawab. Logout menghapus cache | test widget offline; inspeksi direktori data |
| NFR-STU-09 | Aksesibilitas | Kontras teks ≥ 4,5:1 (WCAG 2.1 AA) di tema terang dan gelap (dijaga `studio/test/tema_studio_test.dart`); navigasi keyboard penuh di Windows; skala teks tablet sampai 130% tanpa terpotong; target sentuh ≥ 48 dp | test kontras; uji manual |
| NFR-STU-10 | Platform | Windows 10 22H2/11 x64; tablet Android (perangkat uji Xiaomi 25040RP0AG, Android 16, 1600×2560); Flutter mengikuti repo mobile (3.44.x lokal, CI 3.44.6 per riset) | build kedua platform di CI |
| NFR-STU-11 | Bahasa | Indonesia bawaan, Inggris. Angka di layar koma desimal; nilai persis di tooltip. Istilah layar `04-UI-UX-Flow.md` §6 | test l10n |
| NFR-STU-12 | Terverifikasi | Suite SQLite **dan** MySQL hijau sebelum tahap selesai (`AGENTS.md` §Test); `flutter analyze` 0 isu; test widget/golden Studio; penjaga-angka untuk setiap perubahan angka | gate per tahap |
| NFR-STU-13 | Observabilitas | `GET /api/health` menambah blok `studio`: `lembar_hitung.aktif_sumber_database`, `lembar_hitung.tanpa_simulasi` (harus 0), `lembar_hitung.bayangan_beda` (kandidat), `lembar_hitung.pengawasan_beda_terbuka` (versi aktif `setara_php`), `mesin_rumus = {nama, versi}`, `data_acuan.versi_aktif_tanpa_simulasi` | test health |
| NFR-STU-14 | Pemeliharaan | Menambah alat ke Lembar Hitung tanpa mengubah `GumCalculator` (pengawas tetap satu kode untuk semua alat); registry tabel dijaga sapu skema; tidak ada daftar tulis tangan yang bisa basi | test sapu |

## 3. Keamanan (OWASP ASVS yang relevan)

Penomoran bab mengikuti OWASP ASVS 4.0.3. ASVS 5.0 (2025) memakai penomoran lain; ⚠️ pemetaan ke
5.0 belum dicek ke dokumen resminya.

| Bab ASVS 4.0.3 | Kebutuhan Studio |
|---|---|
| V1 Arsitektur | Server satu-satunya evaluator dan penegak izin; Studio klien tipis (ADR-49-01). Tidak ada kredensial database di Studio |
| V2 Autentikasi | Login yang ada + throttle; konfirmasi sandi untuk sahkan/tarik; tanpa pintu daftar |
| V3 Manajemen sesi | Token Sanctum ber-ability `internal`; dicabut saat logout; disimpan di penyimpanan aman platform |
| V4 Kontrol akses | `role:` + `MatriksIzin` + registry daftar putih per tabel dan kolom; lingkup organisasi → 404; `PemisahanWewenang` untuk pengesahan; `lolosBacaSuperAdmin` tidak dilonggarkan; rute tulis `super_admin` di grup saudara (`routes/api.php:874-892`) |
| V5 Validasi & encoding | Aturan validasi = API yang ada; saringan terstruktur (bukan SQL); rumus diurai dan divalidasi daftar putih; ekspor CSV dilindungi dari injeksi rumus |
| V7 Galat & log | Respons galat tanpa jejak tumpukan/SQL; log tanpa sandi, token, sandi workbook, data pribadi; audit lengkap untuk tulis |
| V8 Perlindungan data | Kolom rahasia tidak pernah keluar; cache tablet dibatasi (NFR-STU-08); ekspor data pribadi tercatat (Q-49-05) |
| V10 Kode berbahaya | Tidak ada `eval`; mesin rumus dibatasi daftar putih fungsi (tanpa I/O); makro VBA tidak dijalankan |
| V11 Logika bisnis | Mesin status versi ditegakkan server; throttle untuk tulis, simulasi, impor, ekspor; idempoten |
| V12 Berkas | Unggahan dibatasi ukuran, jenis dari isi, ukuran terurai; XML dibaca dengan pemindai XXE bawaan PhpSpreadsheet; berkas tidak disimpan (bawaan) |
| V13 API | JSON, kode galat stabil (`kode`), metode yang tidak dipakai → 405, header `If-Match` untuk tulis bersaing |
| V14 Konfigurasi | `APP_DEBUG` mati di produksi; versi mesin rumus dipatok (`composer.json`); kunci env baru masuk `.env.example` **dan** `render.yaml` (`AGENTS.md` §Alur Kerja 9) |

## 4. Matriks izin

Nama izin baru masuk `MatriksIzin::PETA` (satu izin = satu rute penentu; boleh/tidak dibaca dari
`role:` rutenya, `MatriksIzin.php:10-28`). Nama dijaga stabil karena disimpan klien.

| Izin | Rute penentu | admin | super_admin | viewer | teknisi |
|---|---|---|---|---|---|
| `kelola-data.lihat` | `GET api/kelola-data/tabel` | ✓ | ✓ (baca) | ✓ | Q-49-04 (bawaan —) |
| `kelola-data.ubah` | `PATCH api/kelola-data/tabel/{tabel}/baris/{baris}` | ✓ | — | — | — |
| `kelola-data.arsip` | `POST api/kelola-data/tabel/{tabel}/baris/{baris}/arsip` | ✓ | — | — | — |
| `kelola-data.ekspor` | `GET api/kelola-data/tabel/{tabel}/ekspor` | ✓ | Q-49-05 | Q-49-05 | — |
| `data-acuan.lihat` | sudah ada (`MatriksIzin.php:134`) | ✓ | ✓ | ✓ | ✓ aktif saja |
| `data-acuan.sunting` | `POST api/data-acuan/paket/{kode}/draf` | ✓ | — | — | — |
| `data-acuan.ajukan` | `POST api/data-acuan/versi/{versi}/ajukan` | ✓ | — | — | — |
| `data-acuan.sahkan` | `POST api/data-acuan/versi/{versi}/sahkan` | — | ✓ (BR-08) | — | — |
| `lembar-hitung.lihat` | `GET api/lembar-hitung/alat` | ✓ | ✓ | ✓ | Q-49-04 (bawaan —) |
| `lembar-hitung.sunting` | `POST api/lembar-hitung/alat/{kode}/draf` | usulan ✓ (Q-49-01) | — | — | — |
| `lembar-hitung.impor` | `POST api/lembar-hitung/impor` | usulan ✓ (Q-49-01) | — | — | — |
| `lembar-hitung.ajukan` | `POST api/lembar-hitung/versi/{versi}/ajukan` | usulan ✓ (Q-49-01) | — | — | — |
| `lembar-hitung.sahkan` | `POST api/lembar-hitung/versi/{versi}/sahkan` | — | ✓ orang kedua | — | — |

`super_admin` menulis hanya lewat grup rute saudara `role:super_admin` (pola §40, `routes/api.php:874`).
Pratinjau dan simulasi memakai izin `*.sunting` (berbiaya dan hanya bermakna bagi penyunting);
membaca laporan simulasi memakai `*.lihat`.

## 5. Registry Kelola Data — 56 tabel

Dihitung dari `Schema::create` unik di `database/migrations/*.php` pada worktree 9 Okt 2026 (56).
Kelompok adalah pengelompokan dokumen ini (Master 17, Transaksi 22, Audit 4, Sistem 13 — sama
jumlahnya dengan riset `01-riset-rescope-tabel.md`). Kolom `Audit`/`Arsip` dari pernyataan `use`
di model (`Diaudit`: 23 model; `SoftDeletes`: 14 model).

Kelas:
- **ubah**: sunting kolom daftar putih, tanpa arsip.
- **arsip**: ubah + arsip/pulihkan (soft delete).
- **baca saja**: jelajah, detail, riwayat, ekspor; perubahan hanya lewat alur domain yang ada.
- **tersembunyi**: tidak tampil; 404.

Aturan penentu: kelas `ubah`/`arsip` hanya untuk model ber-`Diaudit`; `arsip` hanya untuk model
ber-`SoftDeletes`; tabel yang dikunci §49 (invarian) selalu `baca saja`; data acuan dan rumus
berversi dikelola modulnya sendiri, bukan Kelola Data.

| # | Tabel | Kelompok | Model | Lingkup org | Audit | Arsip | Kelas | Alasan |
|---|---|---|---|---|---|---|---|---|
| 1 | `organizations` | Master | Organization | diri sendiri | ✓ | ✓ | **ubah** | Pengaturan lab lewat aturan `OrganizationController`; mengarsipkan organisasi = mengunci seluruh lab, ditolak |
| 2 | `users` | Master | User | `organization_id` | ✓ | — | **ubah** | Kolom & aturan `UserController` (tanpa `super_admin`: `User::roles()`); pengganti arsip `status = nonaktif`; sandi tidak pernah tampil |
| 3 | `customers` | Master | Customer | `organization_id` | ✓ | ✓ | **arsip** | Data pelanggan; ekspor Q-49-05 |
| 4 | `customer_members` | Master | CustomerMember | `organization_id` | ✓ | — | baca saja | Akun pelanggan lahir dari undangan; aksi undang/PIC lewat alur pelanggan yang ada |
| 5 | `equipments` | Master | Equipment | `organization_id` | ✓ | ✓ | **arsip** | FR-STU-051 untuk kolom yang memindahkan profil |
| 6 | `equipment_categories` | Master | EquipmentCategory | `organization_id` | ✓ | ✓ | **arsip** | — |
| 7 | `standards` | Master | Standard | `organization_id` | ✓ | ✓ | **arsip** | Nilai yang dipakai rumus tinggal di paket Data Acuan; ketidakcocokan ditampilkan, tidak ditimpa (BR-12 CR-48) |
| 8 | `rooms` | Master | Room | `organization_id` | ✓ | ✓ | **arsip** | — |
| 9 | `calibration_methods` | Master | CalibrationMethod | `organization_id` | ✓ | ✓ | **arsip** | — |
| 10 | `calibration_capabilities` | Master | CalibrationCapability | `organization_id` (ditambah migrasi lanjutan) | ✓ | ✓ | baca saja | Pita CMC = lapis 1, wajib lewat alur versi (`AGENTS.md` §Olah data butir 1, 3). Hari ini masih bisa disunting di Filament (`CalibrationCapabilityResource.php:121-122`) — Q-49-12 |
| 11 | `formulas` | Master | Formula | `organization_id` | ✓ | ✓ | baca saja | Dikelola modul Lembar Hitung |
| 12 | `formula_versions` | Master | FormulaVersion | `organization_id` | ✓ | — | baca saja | Versi beku; dikelola modul Lembar Hitung |
| 13 | `paket_acuan` | Master | PaketAcuan | `organization_id` | ✓ | ✓ | baca saja | Dikelola modul Data Acuan |
| 14 | `paket_acuan_versi` | Master | PaketAcuanVersi | `organization_id` | ✓ | — | baca saja | Versi beku; dikelola modul Data Acuan |
| 15 | `folders` | Master | Folder | `organization_id` | ✓ | ✓ | **arsip** | Arsip dokumen lab |
| 16 | `folder_files` | Master | FolderFile | `organization_id` | ✓ | ✓ | **arsip** | Hanya metadata; unggah/unduh berkas tetap lewat rute arsip yang ada |
| 17 | `direktori_lokal` | Master | DirektoriLokal | tanpa (rujukan umum) | — | — | baca saja | Direktori perusahaan rujukan (§16 F), bukan data lab |
| 18 | `calibration_sessions` | Transaksi | CalibrationSession | `organization_id` | ✓ | — | baca saja | Sesi berjalan diubah lewat alur lembar kerja & persetujuan; sesi disetujui terkunci (`CalibrationSession::booted()`, `CalibrationSession.php:119`) |
| 19 | `calibration_session_standard` | Transaksi | (pivot) | lewat sesi | — | — | baca saja | Ikut sesi |
| 20 | `raw_measurements` | Transaksi | RawMeasurement | lewat sesi | — | — | baca saja + **koreksi** (FR-STU-045) | Nilai teknisi tidak pernah dihapus; koreksi lewat `PUT /calibrations/{c}` yang menyimpan lama + baru |
| 21 | `uncertainty_calculations` | Transaksi | UncertaintyCalculation | lewat sesi | — | — | baca saja | Disimpan, tidak dihitung ulang saat dibaca (`AGENTS.md` §Jalur angka) |
| 22 | `certificates` | Transaksi | Certificate | `organization_id` | ✓ | — | baca saja | Snapshot beku; revisi/pembatalan lewat alur sertifikat |
| 23 | `orders` | Transaksi | Order | `organization_id` | — | ✓ | baca saja | Model tanpa `Diaudit`; perubahan lewat `OrderController` |
| 24 | `order_items` | Transaksi | OrderItem | lewat order | — | — | baca saja | Idem |
| 25 | `penugasan` | Transaksi | Penugasan | `organization_id` | ✓ | ✓ | baca saja | Alur penugasan (`POST /penugasan`) |
| 26 | `penugasan_item` | Transaksi | PenugasanItem | lewat penugasan | ✓ | — | baca saja | Alur lapor progres |
| 27 | `penugasan_teknisi` | Transaksi | PenugasanTeknisi | lewat penugasan | — | — | baca saja | — |
| 28 | `permintaan_kalibrasi` | Transaksi | PermintaanKalibrasi | `organization_id` | ✓ | — | baca saja | Alur putuskan/balas permintaan pelanggan |
| 29 | `permintaan_kalibrasi_item` | Transaksi | PermintaanKalibrasiItem | lewat permintaan | — | — | baca saja | — |
| 30 | `pesan_permintaan` | Transaksi | PesanPermintaan | lewat permintaan | — | — | baca saja | Percakapan dengan pelanggan |
| 31 | `koreksi_pelanggan` | Transaksi | KoreksiPelanggan | `organization_id` | — | — | baca saja | Alur koreksi pelanggan |
| 32 | `foto_pelanggan` | Transaksi | FotoPelanggan | `organization_id` | — | — | baca saja | Berkas pelanggan |
| 33 | `worksheet_scans` | Transaksi | WorksheetScan | `organization_id` | — | — | baca saja | Usulan OCR mentah, dijaga apa adanya |
| 34 | `worksheet_scan_cells` | Transaksi | WorksheetScanCell | lewat scan | — | — | baca saja | Idem |
| 35 | `dokumen_bacaan` | Transaksi | DokumenBacaan | `organization_id` | — | — | baca saja | Usulan, bukan data terakreditasi (docblock model) |
| 36 | `dokumen_bacaan_nilai` | Transaksi | DokumenBacaanNilai | lewat dokumen | — | — | baca saja | Koreksi lewat kolom `dikoreksi_oleh` di alurnya |
| 37 | `undangan_pelanggan` | Transaksi | UndanganPelanggan | `organization_id` | ✓ | — | baca saja | Alur undang/cabut |
| 38 | `pengajuan_akun_pelanggan` | Transaksi | PengajuanAkunPelanggan | `organization_id` | ✓ | — | baca saja | Bukti persetujuan syarat (`restrictOnDelete`, UU PDP) |
| 39 | `riwayat_tersembunyi` | Transaksi | (tanpa model) | per user | — | — | tersembunyi | Preferensi tampilan pribadi (§47) |
| 40 | `audit_logs` | Audit | AuditLog | `organization_id` | — | — | baca saja | Append-only, model melempar exception (`AuditLog.php:99-110`) |
| 41 | `certificate_email_logs` | Audit | CertificateEmailLog | `organization_id` | — | — | baca saja | Append-only (`CertificateEmailLog.php:83`) |
| 42 | `paket_acuan_suntingan` | Audit | PaketAcuanSuntingan | lewat versi | — | — | baca saja | Append-only (`PaketAcuanSuntingan.php:60-72`) |
| 43 | `worksheet_extraction_logs` | Audit | WorksheetExtractionLog | lewat sesi | — | — | baca saja | Log ekstraksi |
| 44 | `persetujuan_dokumen` | Sistem | PersetujuanDokumen | lewat pelanggan | — | — | baca saja | Bukti persetujuan syarat & privasi |
| 45 | `preferensi_notifikasi_anggota` | Sistem | PreferensiNotifikasiAnggota | lewat anggota | — | — | tersembunyi | Preferensi pribadi pelanggan |
| 46 | `notifications` | Sistem | (Laravel) | per user | — | — | tersembunyi | Milik penerimanya, dilihat di layar notifikasi |
| 47 | `device_tokens` | Sistem | DeviceToken | per user | — | — | tersembunyi | Token FCM |
| 48 | `otp_pelanggan` | Sistem | OtpPelanggan | — | — | — | tersembunyi | Hash OTP |
| 49 | `personal_access_tokens` | Sistem | (Sanctum) | — | — | — | tersembunyi | Hash token |
| 50 | `password_reset_tokens` | Sistem | (Laravel) | — | — | — | tersembunyi | Token reset |
| 51 | `sessions` | Sistem | (Laravel) | — | — | — | tersembunyi | Sesi web panel |
| 52 | `cache` | Sistem | (Laravel) | — | — | — | tersembunyi | Sistem |
| 53 | `cache_locks` | Sistem | (Laravel) | — | — | — | tersembunyi | Sistem |
| 54 | `jobs` | Sistem | (Laravel) | — | — | — | tersembunyi | Payload bisa memuat data |
| 55 | `job_batches` | Sistem | (Laravel) | — | — | — | tersembunyi | Sistem |
| 56 | `failed_jobs` | Sistem | (Laravel) | — | — | — | tersembunyi | Payload + jejak galat |

Kolom "Lingkup org" diperiksa langsung di migrasi untuk tabel yang punya `organization_id`; untuk
tabel anak ("lewat …") lingkupnya diturunkan dari induk dan wajib dibuktikan test isolasi di S2
sebelum tabelnya dibuka.

Ringkasan: **ubah 2**, **arsip 8**, **baca saja 33** (termasuk `raw_measurements` dengan koreksi
lewat alur), **tersembunyi 13**. Tabel baru yang lahir di S3–S4 (`simulasi_versi`,
`simulasi_versi_sesi`, `lembar_hitung_suntingan`, `unggahan_workbook`) masuk registry sebagai
**baca saja** (yang tiga terakhir append-only) pada migrasi yang sama.

Catatan yang perlu diketahui peninjau (bukan perubahan di paket ini): `orders` punya `SoftDeletes`
tetapi tanpa `Diaudit`, jadi perubahan order hari ini tidak tercatat nilai lama/barunya.

## 6. Status dan transisi versi Lembar Hitung

Status disimpan di `formula_versions.status` (string(16), bukan enum, migrasi
`2026_07_27_110000_create_formulas_table.php:76`). Nilai lama **tidak diganti nama**: `draft`,
`aktif`, `arsip` tetap; ditambah `diajukan`, `terjadwal`, `ditolak`, `ditarik`, `dibuang`.

```
            ┌────────── buang (pemilik draf) ──────────► dibuang
            │
draft ──ajukan──► diajukan ──sahkan──► terjadwal ──(tanggal tiba)──► aktif ──(versi baru aktif)──► arsip
  ▲                  │   │                 │                          │
  │                  │   └──tolak──► ditolak └──batalkan──► ditolak    └──tarik──► ditarik
  └──kembalikan──────┘ (versi ini → ditolak, lahir draft baru)
```

| Dari | Ke | Siapa | Syarat |
|---|---|---|---|
| — | `draft` | penyunting (Q-49-01) | izin `lembar-hitung.sunting` |
| `draft` | `dibuang` | pemilik draf | belum pernah diajukan |
| `draft` | `diajukan` | pemilik draf | FR-STU-121 (nol galat statis, simulasi terbaru, paritas bila pertama, alasan, rujukan, sumber metode) |
| `diajukan` | `terjadwal` / `aktif` | `super_admin`, bukan BR-08 | FR-STU-122 |
| `diajukan` | `ditolak` | `super_admin` | alasan |
| `diajukan` | `ditolak` + `draft` baru | `super_admin` | catatan (kembalikan) |
| `terjadwal` | `aktif` | sistem (penjadwal harian) | tanggal tiba |
| `terjadwal` | `ditolak` | `super_admin` | alasan (batalkan jadwal) |
| `aktif` | `arsip` | sistem | versi lain menjadi aktif (penutupan rentang) |
| `aktif` | `ditarik` | `super_admin` | alasan, sandi; FR-STU-123 |

Versi yang pernah menstempel hasil hitung tidak pernah dihapus; hanya `arsip` atau `ditarik`
(`AGENTS.md` §Olah data butir 2). Data Acuan memakai mesin status CR-48 02-SRS §3 yang sama
(nilainya `draf`, `pensiun` — tidak disamakan supaya data P1 tidak berubah).

## 7. Skenario penerimaan

Format Given / When / Then. Setiap skenario menjadi minimal satu test (nama rencana di kurung).

**SK-01 (FR-STU-001, 007).** *Given* akun `viewer` aktif. *When* masuk ke Studio. *Then* token
ber-ability `internal` tersimpan di penyimpanan aman, seksi Kelola Data dan Lembar Hitung tampil
baca saja, tombol ubah tidak ada, dan `PATCH api/kelola-data/...` dengan token itu dijawab 403.
(`StudioMasukViewerTest`, `studio/test/masuk_viewer_test.dart`)

**SK-02 (FR-STU-001).** *Given* akun `pelanggan`. *When* memanggil `GET api/kelola-data/tabel` dengan
tokennya. *Then* 403 dari `aplikasi:internal`; tidak ada data yang keluar. (`KelolaDataTolakPelangganTest`)

**SK-03 (FR-STU-030, 047).** *Given* admin. *When* `GET api/kelola-data/tabel/personal_access_tokens/baris`.
*Then* 404 `tabel_tidak_dikenal`, sama persis dengan tabel yang tidak ada. (`KelolaDataTabelTersembunyiTest`)

**SK-04 (FR-STU-031).** *Given* migrasi baru membuat tabel `contoh_baru` tanpa entri registry.
*When* suite dijalankan. *Then* `RegistriKelolaDataSapuSkemaTest` merah menyebut `contoh_baru`.

**SK-05 (FR-STU-040, 190).** *Given* admin dan alat milik organisasinya dengan `serial = A1`. *When*
`PATCH` kolom `serial` jadi `A2` dengan alasan "salah ketik dari label" dan `If-Match` terkini.
*Then* 200; satu baris `audit_logs` `diubah` dengan `old_data.serial = A1`, `new_data.serial = A2`,
`changed_by` = admin, `note` = alasan. (`KelolaDataUbahTercatatTest`)

**SK-06 (FR-STU-040).** *Given* yang sama. *When* `PATCH` tanpa alasan. *Then* 422 `alasan_wajib`;
tidak ada yang berubah.

**SK-07 (FR-STU-040, 052).** *Given* dua admin membuka baris yang sama. *When* admin A menyimpan,
lalu admin B menyimpan dengan `If-Match` lama. *Then* B menerima 409 `baris_berubah` berisi nilai
terbaru dan pengubahnya; nilai A tetap. (`KelolaDataKonflikTest`)

**SK-08 (FR-STU-042, 043).** *Given* ruangan tanpa sesi berjalan. *When* admin mengarsipkan dengan
alasan lalu memulihkannya. *Then* `deleted_at` terisi lalu kosong; `audit_logs` punya `dihapus` dan
`dipulihkan`; tidak ada rute `DELETE` di grup Kelola Data (sapu rute). (`KelolaDataArsipPulihkanTest`)

**SK-09 (FR-STU-046).** *Given* admin. *When* `PATCH api/kelola-data/tabel/uncertainty_calculations/baris/{id}`.
*Then* 403 `tabel_baca_saja`; baris tidak berubah.

**SK-10 (FR-STU-045).** *Given* sesi `menunggu_approval` dengan pembacaan 10,01 pada titik 1. *When*
admin mengoreksi jadi 10,02 lewat Studio dengan alasan. *Then* `audit_logs` entitas sesi memuat
potret lama (10,01) dan baru (10,02) + alasan; `PemisahanWewenang` menolak admin itu sebagai
penyetuju sesi (`penyetuju_mengoreksi_pembacaan`). (`KoreksiPembacaanDariStudioTest`)

**SK-11 (FR-STU-045).** *Given* sesi `disetujui`. *When* admin mencoba koreksi. *Then* ditolak
dengan arahan ke revisi sertifikat; tidak ada baris mentah yang berubah.

**SK-12 (FR-STU-049).** *Given* admin organisasi A. *When* membuka baris pelanggan organisasi B
lewat id-nya. *Then* 404. Sama untuk `super_admin` organisasi A. (`KelolaDataIsolasiTest` +
sapuan otomatis)

**SK-13 (FR-STU-044).** *Given* admin menyaring pelanggan kota "Bekasi". *When* ekspor. *Then* CSV
UTF-8 BOM, pemisah `;`, sel `=SUM(1)` keluar sebagai `'=SUM(1)`, dan `audit_logs` mencatat
`diekspor` dengan tabel, saringan, dan jumlah baris. (`KelolaDataEksporTest`)

**SK-14 (FR-STU-082, 122).** *Given* draf Data Acuan/Lembar Hitung dibuat dan diajukan oleh `super_admin`
X. *When* X mengesahkan. *Then* 422 `pengesah_ikut_menyunting`; versi tetap `diajukan`. *When*
`super_admin` Y yang tidak menyunting mengesahkan dengan sandi benar. *Then* `aktif`, rentang versi
lama ditutup dalam satu transaksi. (`PemisahanWewenangVersiTest`)

**SK-15 (FR-STU-104).** *Given* Lembar Hitung Volumetric Fixed dan sesi dengan kelas alat "C". *When*
dievaluasi. *Then* sel γ menghasilkan `#N/A`, titik ditahan dengan pesan aturan tahan; tidak ada
baris `uncertainty_calculations`; profil PHP juga menahan titik yang sama (paritas syarat 4).
(`LembarHitungAturanTahanTest`)

**SK-16 (FR-STU-106, 107, 108, 109).** *Given* draf. *When* menyimpan sel `=NOW()`, `=A1+B1` dengan
`B1 = A1`, `=[1]Sheet1!A1`, dan `=ROUND(C5,4)` di hulu keluaran `u`. *Then* keempatnya tersimpan
sebagai draf tetapi `galat_statis` berisi `fungsi_volatile`, `rujukan_melingkar`, `rujukan_luar`,
`pembulatan_di_hulu_keluaran`; `ajukan` ditolak sampai nol galat. (`ValidatorLembarHitungTest`)

**SK-17 (FR-STU-113).** *Given* sesi Volumetric Fixed tersimpan. *When* pratinjau draf yang sama
dengan PHP. *Then* tiga kolom (tersimpan, PHP, draf) identik untuk U95 dan angka cetak; tidak ada
baris database yang berubah (hitung baris sebelum/sesudah). (`PratinjauLembarHitungTidakMenyimpanTest`)

**SK-18 (FR-STU-116, 117).** *Given* draf v2 yang mengubah konstanta ρ anak timbangan. *When*
simulasi. *Then* laporan menyebut setiap sesi Fixed, kolom yang berubah, dan apakah angka cetak
berubah; *When* draf disunting lagi. *Then* simulasi `kedaluwarsa` dan `ajukan` ditolak
`simulasi_kedaluwarsa`. (`SimulasiLembarHitungTest`)

**SK-19 (FR-STU-103, 118).** *Given* kandidat Lembar Hitung pertama Volumetric Fixed yang memakai
ρ anak timbangan `8` (angka lembar pemilik, V-1) alih-alih `7.95`. *When* paritas dijalankan.
*Then* gagal di `rata_rata` (V20), `ci` komponen, dan U untuk semua sesi; `ajukan` ditolak
`paritas_belum_lulus`. *Given* kandidat lain yang mengubah penyebut veff blok agregasi menjadi
satu baris (meniru master `K43`). *When* paritas dijalankan. *Then* gagal di
`derajat_kebebasan_efektif`, `faktor_cakupan_k`, U hitung (dan U95 bila di atas CMC), dan laporan
juga menampilkan `agregasi_beda_gum` dari pengawas; versi pertama tidak bisa meniru master.
(`GerbangParitasMenolakBedaTest`)

**SK-20 (FR-STU-118).** *Given* Lembar Hitung Volumetric Fixed yang ditulis dari PHP (`03-SDD.md` §6.5).
*When* paritas atas semua sesi tersimpan + fixture master + sesi seeder. *Then* LULUS 100%, angka
cetak identik, laporan mencatat jumlah identik bit. (`GerbangParitasVolumetricFixedTest`, diperiksa
penjaga-angka)

**SK-21 (FR-STU-124, 125, 126).** *Given* v1 (`sumber = kode`) berlaku sampai 2026-11-30 dan v2
(`sumber = database`) mulai 2026-12-01. *When* sesi bertanggal 2026-11-15 dan 2026-12-02 disimpan,
divalidasi, dan dihitung ulang. *Then* yang pertama distempel v1 dan dihitung profil PHP; yang kedua
distempel v2 dan dihitung evaluator; `kalibrasi:hitung-ulang` memberi angka identik dengan simpan
awal untuk keduanya. (`JalurHitungMenurutVersiTest`)

**SK-22 (FR-STU-132).** *Given* versi aktif mencatat mesin `phpoffice/phpspreadsheet 5.10.0`. *When*
`composer.lock` berisi versi lain. *Then* `MesinRumusVersiTerkunciTest` merah sampai paritas dan
simulasi dijalankan ulang dan versinya dicatat.

**SK-23 (FR-STU-161).** *Given* `.xlsm` terenkripsi. *When* diunggah dengan sandi benar. *Then*
laporan impor terbit; sandi tidak muncul di `audit_logs`, `jobs.payload`, `failed_jobs`,
`storage/logs`, maupun respons. (`ImporXlsmSandiTidakTersimpanTest`)

**SK-24 (FR-STU-163).** *Given* workbook dengan sheet `DATABASE` berisi nama pelanggan. *When* diimpor.
*Then* tidak ada sel dari `DATABASE` di draf maupun laporan selain yang dipetakan; sapu nama
pelanggan pada isi draf kosong.

**SK-25 (FR-STU-181, 184).** *Given* v2 Lembar Hitung aktif. *When* aplikasi lapangan meminta
`lembar-kerja` dengan `If-None-Match` lama. *Then* 200 dengan `versi_rumus.nomor = 2`; field lama
identik. Sertifikat dari sesi berstempel v2 memuat baris Lembar Hitung v2; sertifikat lama tidak
berubah sesudah `sertifikat:bangun-ulang --dry-run`.

**SK-26 (FR-STU-134, 135).** *Given* Volumetric Fixed v1 aktif (blok bawaan) dan draf v2 yang
mengubah penyebut veff menjadi satu baris `K43`. *When* simulasi. *Then* setiap sesi Fixed
menampilkan `agregasi_beda_gum` dengan selisih veff/k/U dan apakah angka cetak berubah; *When*
diajukan tanpa catatan penyimpangan. *Then* 422 `agregasi_beda_tanpa_catatan`; *When* diajukan
dengan catatan bersumber (merujuk `pertanyaan-lab-volumetric.md` no. 1 + jawaban Lab) lalu
disahkan orang kedua dan sesi baru dihitung. *Then* sesi tersimpan dan sertifikatnya memakai
agregat lembar, terbit **tidak** tertahan oleh pengawas, dan jejak sesi memuat PERINGATAN
`agregasi_beda_dari_gum` (nilai lembar, nilai pengawas, selisih, kode catatan). Pengawas tidak
bisa dimatikan lewat API mana pun. (`PengawasAgregasiGumTest`)

## 8. Matriks keterlacakan FR → tahap

| FR | S0 | S1 | S2 | S3 | S4 | S5 | S6 |
|---|---|---|---|---|---|---|---|
| FR-STU-001 – 009 Autentikasi | spike b | **✓** | | | | | |
| FR-STU-010 – 019 Cangkang | spike b | **✓** | | | | | |
| FR-STU-030 – 053 Kelola Data | | | **✓** | | | | |
| FR-STU-070 – 071, 077, 078, 089 Data Acuan baca | | **✓** (pindah M2) | | | | | |
| FR-STU-072 – 076, 079 – 088 Data Acuan tulis | | | | **✓** | | | |
| FR-STU-100 – 110 Model, validator, fungsi | **spike a** | | | | **✓** | | |
| FR-STU-111 – 112, 114 – 115 Editor, jejak, banding | | | | | API ✓ | **UI ✓** | |
| FR-STU-113 Pratinjau | spike a | | | | API ✓ | UI ✓ | |
| FR-STU-116 – 120 Simulasi, paritas, bayangan | spike a | | | tabel simulasi ✓ | **✓** | UI ✓ | per alat |
| FR-STU-121 – 127, 132 Alur versi, stempel, mesin | | | | | **✓** | UI ✓ | |
| FR-STU-128 – 130, 133 Penyimpangan, konstanta, varian | | | | | **✓** | ✓ | per alat |
| FR-STU-134 – 135 Blok agregasi bawaan, pengawas `GumCalculator` | spike a (TINV) | | | | **✓** | UI ✓ | per alat |
| FR-STU-131 Hak lihat | | | | | ✓ | ✓ | |
| FR-STU-160 – 168 Impor `.xlsm` | **spike c** | | | | parser ✓ | **UI ✓** | per alat |
| FR-STU-180 – 186 Sinkron | | ✓ (185) | | ✓ (183) | **✓** | | |
| FR-STU-190 – 196 Audit | | | ✓ | ✓ | ✓ | ✓ | |
| NFR-STU-01 – 14 | sebagian | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

Nomor tugas pelaksana per FR ada di `05-Task-Breakdown.md` (kolom Link).
