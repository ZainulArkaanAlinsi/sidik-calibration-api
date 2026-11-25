# Parameter keputusan metode

Dibuat 24 Nov 2026. Pemilik (Zainul) memutuskan: pertanyaan metode yang masih
menunggu Manajer Teknis (TM) **tidak boleh menghentikan pekerjaan**. Tiap
pertanyaan dijadikan PARAMETER yang bisa disetel kemudian, dengan bawaan =
perilaku kode PADA `79280fc2`, sehingga tidak ada angka sertifikat yang berubah
diam-diam. Akurat dan terbukti lebih penting daripada cepat.

Ini data **lapis 1** (AGENTS.md §Olah data butir 1): pilihan di antara
perilaku yang sudah ada, bukan rumus baru. Struktur rumus (lapis 2) tidak
disentuh.

## Status implementasi (24 Nov 2026, PR #298)

| Bagian | Status |
|---|---|
| Registri 14 parameter (7 awal PR #298 + 7 saklar keputusan default 25 Nov 2026), pembaca satu pintu, ledger append-only | SELESAI, `ParameterKeputusanMetodeTest` |
| Saklar keputusan default (vonis PASS/FAIL 4 alat, tanda minus nol turbidimeter, sumber U koreksi timbangan kg, sumber resolusi refractometer) | SELESAI: bawaan = perilaku kode sebelumnya, pilihan lain diuji (`ParameterMetodeVonisTest`, `ParameterMetodeTurbidimeterSesiTest`, `ParameterMetodeTimbanganTest`, `ParameterMetodeSumberResolusiTest`) |
| Lingkup tanggal di pintu hitung (`PenentuVersiAcuan::jalankan/jalankanDengan`: simpan, update, preview, `kalibrasi:hitung-ulang`, validator, simulasi) | SELESAI |
| Refractometer#24, Spectrophotometer#15, Conductivity#18 (parameter `hitung`) | SELESAI: tes vs sel workbook + bawaan identik terhadap rekaman kode lama |
| Tanggal terbit ph_meter#28, conductivity#36, spectrophotometer#4, turbidimeter#4 (parameter `cetak`) | SELESAI: tes vs sel workbook; nilai beku di snapshot, versi penetapan TIDAK distempel di snapshot |
| Jejak sesi (`type_b_components`, `sumber: parameter_metode`) | SELESAI untuk profil yang memanggil `jejakParameterMetode()` (jalur generik `GumCalculator` + `SpectrophotometerProfile`). BELUM untuk Timbangan: `TimbanganCalculator` tidak menulis baris itu (tinjauan PR #306) |
| Jalur penuh lewat DB (simpan/preview/validator/hitung-ulang, sesi lama, penetapan dicabut) | `ParameterMetodeSesiSpektroTest` (profil per kelompok); Refractometer dan Conductivity (per titik) diuji di tingkat unit |
| Penetapan lewat Studio Web, sisi API (Studio Tahap 1, 25 Nov 2026) | SELESAI sisi API: tabel `parameter_metode_penetapan`, 4 endpoint di grup Studio, aturan D3 di server, kontrak di `docs/perintah-frontend-parameter-metode-studio.md`. Layar Studio Web belum (pekerjaan frontend) |
| Suite MySQL penuh | belum dijalankan lokal (3 jam); gerbang CI |

## Yang ada sekarang

| Bagian | Tempat |
|---|---|
| Registri: DEFINISI parameter (kunci, pilihan, bawaan, bukti) | `database/data/parameter-keputusan-metode.json`. `penetapan[]` di berkas ini kini catatan sejarah beku (lihat "Penetapan: berkas dan DB") |
| Registri: PENETAPAN baru (25 Nov 2026) | tabel `parameter_metode_penetapan` + jejak transisi `parameter_metode_penetapan_jejak` (migrasi `2026_11_25_100000_*`), model `ParameterMetodePenetapan`, `ParameterMetodePenetapanJejak` |
| Penulis penetapan (aturan D3, transaksi, jejak) | `App\Services\Acuan\PenetapanParameterMetode`; galat domain `PenetapanMetodeDitolak` |
| Endpoint Studio | `App\Http\Controllers\Api\Studio\ParameterMetodeController`, FormRequest di `app/Http/Requests/Studio/`, rute `/api/studio/parameter-metode...` |
| Pintu baca TUNGGAL | `App\Services\Acuan\ParameterKeputusanMetode` (terikat `scoped`, bukan singleton lagi, supaya cache penetapan tidak basi), jawabannya `PutusanMetode` |
| Lingkup tanggal | dibuka `PenentuVersiAcuan::jalankan()/jalankanDengan()` (simpan, preview, hitung ulang, validator, simulasi) dan `CalibrationProfile::tataLetakSertifikat($sesi)` (cetak) |
| Jejak sesi | baris `parameter_metode` di `uncertainty_calculations.type_b_components` untuk parameter berlingkup `hitung`; untuk `cetak`, nilai beku di snapshot (`tata_letak.tanggal_master.tambah_hari`) |
| Penjaga | `ParameterKeputusanMetodeTest`, `ParameterMetodeBawaanIdentikTest`, `ParameterMetode{Refractometer,Spektro,Konduktivitas,TanggalTerbit,Sesi}Test`, ledger `tests/Fixtures/parameter-metode-ledger.json`, golden `tests/Fixtures/parameter-metode-golden-bawaan.json`. Studio Tahap 1: `StudioParameterMetodeApiTest` (akses, kontrak, aturan D3), `ParameterKeputusanMetodePenetapanDbTest` (resolusi dari DB), `ParameterMetodePenetapanStudioSesiTest` (sesi sebelum/sesudah `berlaku_mulai`), `CorsStudioTest` |

Pola yang dipakai ulang, bukan penyimpanan baru: `log-metode-tekanan-piston.json`
(log append-only + pembaca `App\Support\LogMetodeTekananPiston`), ledger berhash
`VersiTataLetakTemplatTest`, pintu satu-lingkup `SumberAcuan`, dan stempel jejak
`type_b_components` (aturan Olah data butir 4).

## Empat belas parameter

Semua `status: menunggu_tm` dan `penetapan: []` pada saat dibuat. Nilai
bawaan tujuh parameter pertama dibuktikan identik dengan sebelum perubahan lewat
`tests/Fixtures/parameter-metode-golden-bawaan.json` (direkam dari kode
`79280fc2` sebelum satu baris profil diubah, diadu `assertSame`).

| Kunci | Butir TM | Bawaan (= kode sekarang) | Pilihan lain (= workbook) | Dampak angka |
|---|---|---|---|---|
| `refractometer.sumber_deviasi_suhu` | refractometer#24 | `suhu_ruang` | `baris_tetap_21c`: sel tetap `'Tab Konversi Temperatur'!H24/E24` (B24 = 21,0 C) | U cetak sampai ~3 kali pada suhu ruang 23 C |
| `spectrophotometer.max_stdev_holmium` | spectrophotometer#15 (ikat #12) | `semua_titik` | `tanpa_titik_pertama`: `PERHITUNGAN!M36 =MAX(M27:M35)` | hanya bila STDEV 279,6 nm yang terbesar |
| `conductivity.nilai_acuan_larutan_1412` | conductivity_meter#18 | `konstanta_1412` | `polinomial_suhu`: `PERHITUNGAN!J26`, 1413,0 pada 25 C | nilai acuan titik tengah, Correction, U95 |
| `sertifikat.tambah_hari.ph_meter` | ph_meter#28 | `terbit_sistem` | `tambah_3`, `tambah_4` | tanggal tercetak saja |
| `sertifikat.tambah_hari.conductivity_meter` | conductivity_meter#36 | `terbit_sistem` | `tambah_2`, `tambah_4` | tanggal tercetak saja |
| `sertifikat.tambah_hari.spectrophotometer` | spectrophotometer#4 | `tambah_1` | `tambah_4`, `terbit_sistem` | tanggal tercetak saja |
| `sertifikat.tambah_hari.turbidimeter` | turbidimeter#4 | `terbit_sistem` | `tambah_1`, `tambah_4` | tanggal tercetak saja |
| `turbidimeter.tanda_minus_nol` | turbidimeter#29 | `dipertahankan` (koreksi yang membulat ke nol dicetak `-0,00`) | `tanpa_minus` (PDF master `0.00`) | tanda minus tercetak saja; nilai beku per baris snapshot (`baris[].tanda_nol`) |
| `chlorine_meter.vonis_pass_fail` | chlorine_meter#31 | `dengan_toleransi` (PASS/FAIL terhadap `equipments.toleransi`; kosong = titik ditahan) | `tanpa_vonis` (master tanpa sel toleransi/PASS-FAIL) | tanpa angka hitung; hanya `toleransi`, `keputusan` kosong dan gerbang toleransi lepas |
| `ph_meter.vonis_pass_fail` | ph_meter#26 | `dengan_toleransi` | `tanpa_vonis` | sama |
| `refractometer.vonis_pass_fail` | refractometer#31 | `dengan_toleransi` | `tanpa_vonis` | sama |
| `turbidimeter.vonis_pass_fail` | turbidimeter#27 | `dengan_toleransi` | `tanpa_vonis` | sama |
| `timbangan.sumber_ui_koreksi_kg` | timbangan B1 | `bagi_k` (`ui = U/k`, keputusan 16 Sep 2026) | `master` (`ui = U` mentah, hanya varian kg; `_budget.penimbangan` master kg) | budget Weighing (uc, veff, k, U penimbangan) varian kg; koreksi dan U koreksi tidak berubah. Penahanan sertifikat timbangan (`profil_ditahan`) TIDAK dilepas |
| `refractometer.sumber_resolusi` | refractometer#15 | `konstanta` (`resolusiTitik()` = 0,0001) | `isian_teknisi` (`equipments.resolusi`; kosong/nol jatuh ke konstanta), `lebih_besar` (maks konstanta dan isian) | komponen Daya baca dan `ci` komponen suhu |

Bukti per pilihan (sel workbook + `path:baris @79280fc2`) ada di registri
(`pilihan[].bukti`), dan ringkasnya ikut ke jejak sesi (`bukti_singkat`).

### Yang SENGAJA tidak dijadikan parameter

| Butir | Alasan (bukti) |
|---|---|
| spectrophotometer#12 (10 atau 9 titik Holmium, nilai standar berbeda) | Bukan parameter kecil. Daftar titik dan nilai standarnya konstanta `SpectrophotometerProfile::TITIK` (`SpectrophotometerProfile.php:209`, `:223`; lapis 1 di kode), dan mengubahnya mengubah jumlah baris lembar kerja, baris sertifikat, dan nilai standar (master 287,7 lawan kertas 287,4). Itu bentuk lembar (lapis 3) + data standar, dengan validasi "perhitungan tidak kehilangan input". Perlu paket data acuan sendiri. |
| spectrophotometer#43 (MAX STDEV tampilan gabungan) | Bukan keputusan metode: tampilan admin yang salah (`PerhitunganBuilder.php:216-219`). Memperbaikinya mengubah perilaku BAWAAN, jadi bukan tempatnya parameter. Perbaikan terpisah dengan tes. |
| U95 termometer 0,72 atau 0,5 (chlorine#23, conductivity#27, ph_meter#19, refractometer#26) | Nilainya SUDAH data per organisasi (`calibration_capabilities.u_temperature`, `PhMeterCapabilitySeeder.php:111-168`; `PhMeterProfile.php:132-135` hanya membaca kolom itu). "Ikut termometer sesi" adalah logika baru (cari standar suhu sesi, ambil U95-nya), bukan data. Menyetel kolom itu tanpa versi mengubah hasil hitung ulang sesi lama; butuh versi kemampuan, langkah terpisah. |
| Nilai toleransi alat (angka 0,15/0,2/0,005/24 pada seed demo) | Tetap data PER ALAT (`equipments.toleransi`, `GumCalculator::hitungTitik`: `toleransiTitik() ?? $equipment->toleransi`; seed demo `ChlorineSeeder.php:159`, `RefractometerSeeder.php:190`, `TurbidimeterSeeder.php:123`). Yang dijadikan parameter hanya APAKAH titik divonis (`*.vonis_pass_fail`), bukan angka toleransinya. Catatan: `CategoryController` (`punya_toleransi` di form alat mobile) membaca `punyaToleransi()` tanpa lingkup tanggal, jadi form itu tetap menjawab bawaan sampai ada keputusan. |
| `spectrophotometer.sumber_resolusi` (butir TM resolusi spektrofotometer) | Tidak ada sumber resolusi numerik yang aman dibaca: `spesifikasi_alat.resolusi_*` teks bebas, dan `equipments.resolusi` satu angka untuk dua satuan (nm dan %T). Memilih salah satunya berarti mengarang angka. Perlu kolom/field resolusi per besaran dari lab lebih dulu. |

## Cara kerja

1. **Bawaan.** `putusan($kunci)` tanpa lingkup tanggal dan tanpa argumen
   memulangkan `bawaan` registri (nomor penetapan 0). Pembaca yang lupa membuka
   lingkup salah ke arah perilaku lama, tidak pernah ke arah angka yang bergeser.
2. **Penetapan** (sejak 25 Nov 2026 jalur normalnya baris di tabel DB lewat
   Studio, lihat "Penetapan: berkas dan DB"; bentuk berkas di bawah tetap sah dan
   tetap dibaca). Satu entri di `penetapan[]`: `nomor` (1, 2, 3 tanpa celah),
   `tanggal_keputusan`, `dicatat_pada`, `berlaku_mulai`, `nilai` (kode pilihan),
   `diputuskan_oleh`, `alasan`, `rujukan` (min. 10 karakter). Registri menegakkan
   tiga hal saat dibaca (kalau salah satu dilanggar, registri gagal keras):
   - `tanggal_keputusan <= dicatat_pada <= berlaku_mulai`. `dicatat_pada` = hari
     entri itu masuk registri (hari PR penetapannya dibuat).
   - `berlaku_mulai` tidak boleh turun antar nomor penetapan satu parameter
     (nomor 2 tidak boleh berlaku lebih awal dari nomor 1; sama boleh, nomor
     terbesar menang).
   - Setiap entri wajib punya ketiga tanggal itu (tanggal Y-m-d yang sah).

   **Batasnya, supaya tidak dibaca lebih dari yang sebenarnya:** registri hanya
   menjaga urutan antar tanggal yang tertulis di berkas. `dicatat_pada` diketik
   penulis PR, jadi registri TIDAK bisa membuktikan bahwa tanggal itu sama dengan
   hari PR dibuat atau hari deploy; PR yang menulis `dicatat_pada` lebih awal dari
   kenyataan lolos tes. Penutup celah itu dua hal di luar kode: peninjau PR
   mencocokkan `dicatat_pada` dengan hari PR dibuat dan `berlaku_mulai` dengan hari
   deploy, dan ledger hash (butir 4) mengunci tanggal itu setelah masuk.
3. **Tanggal.** Pilihan yang berlaku = penetapan dengan `berlaku_mulai <=
   tanggal_kalibrasi` yang nomornya terbesar (entri berkas dan baris DB
   `disetujui` digabung; `menunggu_tm` dan `ditarik` tidak ikut). Tidak ada
   penetapan = bawaan kode. Sesi dengan `tanggal_kalibrasi` sebelumnya tidak
   bergeser.
4. **Append-only.** Entri berkas lama tidak diubah/dihapus. Tes membaca
   `tests/Fixtures/parameter-metode-ledger.json` (hash tiap entri): entri baru
   di BERKAS WAJIB ditambahkan ke ledger di PR yang sama, entri lama yang
   berubah atau hilang = tes merah. Baris DB dijaga lain: model menolak
   mengubah kolom selain `status`, `keputusan_oleh`, `tanggal_keputusan` dan
   menolak menghapus; tiap perubahan status menulis satu baris
   `parameter_metode_penetapan_jejak` yang juga tidak bisa diubah atau dihapus.
5. **Jejak.** Hasil hitung yang melewati `jejakParameterMetode()` (jalur generik
   `GumCalculator` dan `SpectrophotometerProfile`) membawa satu baris
   `sumber: parameter_metode` dengan `parameter`, `pilihan`, `penetapan`,
   `berlaku_mulai`, `status`, `tanggal_acuan`, dan kalimat yang menyebut apakah
   perilaku itu SAMA/BERBEDA/SEBAGIAN dari workbook master beserta sel dasarnya.
   Hasil lama (tanpa baris itu) dibaca sebagai bawaan pada skema registri 1.
   **Timbangan belum:** `TimbanganCalculator` tidak memanggilnya, jadi saklar
   `master` hanya terjejak sebagai nama parameter di teks komponen "Uncertainty
   of Correction", tanpa nomor penetapan, `berlaku_mulai`, dan status.

## Penetapan: berkas dan DB (Studio Tahap 1, 25 Nov 2026)

Penetapan jawaban TM dulu hanya lewat PR ke berkas registri. Sekarang jalur
normalnya tabel `parameter_metode_penetapan`, ditulis dari Sidik Studio lewat API
(kontrak: `docs/perintah-frontend-parameter-metode-studio.md`). DEFINISI parameter
(kunci, pilihan, bawaan, bukti) tetap di `database/data/parameter-keputusan-metode.json`.

**Pembagian tugas**

| Hal | Tempat |
|---|---|
| Definisi, pilihan, bawaan, bukti per pilihan | berkas JSON (berubah lewat PR kode, seperti dulu) |
| Penetapan baru (jawaban TM) | tabel DB, lewat Studio |
| Riwayat perubahan status penetapan | `parameter_metode_penetapan_jejak` (siapa, kapan, dari/ke status, catatan) |

**Keputusan: berkas `penetapan[]` DIPERTAHANKAN sebagai catatan sejarah beku, tidak dihapus.**
Alasannya:

1. Perilaku dan tes #298 tidak berubah. Tes-tesnya menyuntik penetapan berkas lewat
   `pakai()`; menghapus jalur itu berarti mengubah tes yang sudah hijau.
2. Ledger hash (`tests/Fixtures/parameter-metode-ledger.json`) menjaga entri berkas yang
   pernah masuk. Menghapus jalurnya menghapus bukti yang dijaga ledger itu.
3. Biayanya nol: keempat belas parameter berisi `penetapan: []`, jadi tidak ada data yang
   harus dimigrasi dan tidak ada angka yang bergeser.

Pilihan yang berlaku dihitung dari GABUNGAN entri berkas dan baris DB berstatus
`disetujui` (satu query untuk semua parameter, tanpa N+1; cache dibersihkan setiap
penetapan berubah). Penetapan dengan `berlaku_mulai <= tanggal_kalibrasi` sesi dan
nomor terbesar menang; tidak ada = bawaan kode. **Hanya `disetujui` yang menggeser
angka.** `menunggu_tm` dan `ditarik` tidak pernah.

Nomor penetapan baru = (jumlah entri berkas atau nomor DB terbesar, mana yang lebih
besar) + 1, jadi satu urutan untuk berkas dan DB. **Jangan menambah entri berkas baru
sesudah parameter itu punya baris DB**: nomornya bisa bentrok. Jalur berkas masih
diterima registri dan tesnya, tetapi bukan lagi jalur normal.

**Aturan yang ditegakkan server (D3)**

- Waktu pencatatan (`dicatat_pada`) diambil dari jam server; klien tidak bisa
  mengirimnya.
- `berlaku_mulai` tidak boleh lebih awal dari tanggal server (tidak berlaku surut),
  diperiksa saat dicatat DAN saat disetujui. Bila lewat sebelum disetujui: tarik lalu
  catat ulang.
- `berlaku_mulai` tidak boleh turun menurut nomor; pembandingnya penetapan terakhir
  yang belum ditarik (entri berkas selalu ikut), supaya salah ketik tanggal yang sudah
  ditarik tidak mengunci parameter.
- Append-only, dua lapis: model menolak mengubah kolom selain `status`, `keputusan_oleh`,
  `tanggal_keputusan` dan menolak menghapus; trigger database (migrasi
  `2026_11_25_110000`) menahan hal yang sama untuk UPDATE/DELETE mentah lewat query
  builder, dan membekukan keputusan yang sudah dicatat. Jejak transisi tidak bisa
  diubah atau dihapus di kedua lapis. Batas trigger: lihat "Append-only di database"
  di bagian "Risiko yang diketahui".
- Transisi sah hanya `menunggu_tm` ke `disetujui` atau `ditarik`, dan `disetujui` ke
  `ditarik`. Tarik wajib beralasan (min. 10 karakter); setujui wajib menyebut TM yang
  memutuskan dan tanggal keputusannya.
- Pencatatan dan setiap transisi berjalan dalam transaksi dengan kunci baris; dua
  pencatatan serentak pada parameter yang sama: yang kedua dapat 409 `konflik_nomor`
  (nomor bentrok pada indeks unik, atau deadlock MySQL 1213 / SQLSTATE 40001 karena
  dua transaksi saling mengunci rentang kosong). Yang kalah tidak menyimpan apa pun.

**Kenapa tabel sendiri, bukan `paket_acuan`.** Rancangan lama (bagian "Yang tidak
dibangun" di bawah) menyandarkan penetapan pada `paket_acuan` dan `acuan.sumber = db`.
Tabel khusus tidak bergantung pada rollout `DATA_ACUAN_SUMBER`, tidak menambah kolom
di `uncertainty_calculations` (jejak parameter tetap di `type_b_components`), dan
tidak menyentuh tabel lain: migrasinya murni tambahan (dua tabel baru), jalan di
SQLite dan MySQL 8. Lingkup tanggal, jejak `parameter_metode` di
`type_b_components`, dan angka bawaan (golden tidak berubah) sama seperti #298.

**Akses.** Hanya grup Studio (`auth:sanctum` plus gerbang `studio`: token ability
`studio` dan akun sama dengan `STUDIO_AKUN`). Rute ini tidak dilebarkan ke lapisan 1.
Aplikasi mobile tidak berubah.

### Cara menetapkan jawaban TM (sekarang)

1. Studio mencatat jawabannya: `POST /api/studio/parameter-metode/{kunci}/penetapan`
   dengan `nilai`, `berlaku_mulai`, `catatan`. Status `menunggu_tm`; belum memengaruhi
   angka.
2. TM memutuskan. Studio menyetujui: `POST .../penetapan/{id}/setujui` dengan
   `keputusan_oleh` dan `tanggal_keputusan`. Status `disetujui`; sesi bertanggal
   kalibrasi `berlaku_mulai` atau sesudahnya memakai pilihan itu.
3. Salah catat atau keputusan berubah: `POST .../penetapan/{id}/tarik` dengan alasan,
   lalu catat yang baru. Menarik penetapan yang sudah berlaku mengembalikan hitung ulang
   sesi bertanggal sesudahnya ke penetapan sebelumnya atau bawaan; hasil yang sudah
   tersimpan tidak berubah, tetapi validator melaporkan `hitung_ulang_beda` untuk
   sesi yang dulu dihitung dengan penetapan itu. Respons `tarik` membawa `peringatan`
   untuk kasus ini.

Agen otomatis TIDAK PERNAH menyetujui penetapan di produksi: keputusan metode
adalah keputusan Manajer Teknis, dan setujui hanya dari akun Studio manusia.

## Yang tidak dibangun: penetapan lewat `paket_acuan` (rancangan 24 Nov, ditinggalkan)

Rancangan awal memakai pola Data Acuan versi (`paket_acuan` + `AlurVersiAcuan`: draf,
ajukan, simulasi, sahkan oleh `super_admin` lain). Tidak dipakai, dengan bukti:

1. **Sumbernya masih berkas.** `config('acuan.sumber')` dipatok `json`
   (`config/acuan.php:23`, `render.yaml`); versi DB baru dibaca setelah rollout
   tahap 3, yang bukan efek samping deploy. Penetapan di DB acuan tidak akan dibaca
   produksi.
2. **Stempel versi satu kolom.** `uncertainty_calculations.paket_acuan_versi_id`
   menunjuk SATU versi per hasil; parameter lintas profil tidak bisa distempel di
   kolom itu tanpa kolom baru (pilihan terakhir menurut AGENTS.md).
3. **Dua dari tiga profil bukan paket.** `conductivity_meter` dan `ph_meter`
   tidak ada di `SkemaAcuanProfilTest::BERSKEMA`; paketnya belum ada.
4. **Berkas tabel acuan digenerate.** `tabel-standar-*.json` tidak boleh diedit
   tangan (AGENTS.md §Generator).

Tabel khusus di atas menghindari keempatnya. Yang hilang dibanding rancangan itu:
alur draf, ajukan, simulasi, sahkan oleh `super_admin` lain. Penggantinya adalah
dua langkah (catat, setujui) dengan jejak transisi; pemisahan wewenang antar akun
tidak ditegakkan karena Studio memakai satu akun (`STUDIO_AKUN`). Simulasi dampak
tidak ada: yang ada hanya teks `dampak` per parameter di registri. Itu celah yang
diketahui, bukan kelupaan.

## Risiko yang diketahui

- **Hasil lama tanpa baris `parameter_metode`.** Dibaca sebagai bawaan pada
  skema 1. Benar selama tidak ada penetapan berlaku surut. Registri dan ledger
  hanya menjaga urutan tanggal yang tertulis (`tanggal_keputusan <= dicatat_pada
  <= berlaku_mulai`, `berlaku_mulai` tidak turun antar nomor); tanggal
  `dicatat_pada` sendiri diketik penulis PR. Jadi salah bila seseorang menulis
  `dicatat_pada` dan `berlaku_mulai` lebih awal dari hari deploy sebenarnya lalu
  menghitung ulang sesi lama; yang menahannya peninjau PR, bukan kode.
- **Jalur hitung yang belum membuka lingkup.** Empat pintu sudah membukanya
  (store/update/preview, `kalibrasi:hitung-ulang`, `CalibrationValidator`,
  simulasi versi acuan) lewat `PenentuVersiAcuan`. Jalur baru yang memanggil
  profil langsung tanpa lingkup memakai bawaan.
- **Parameter `cetak` tidak berbaris jejak.** Nilainya beku di snapshot
  sertifikat saat terbit; nomor penetapannya tidak ikut distempel di sana.
- **Conductivity, polinomial pada dua varian.** Excel memakai konstanta pada
  varian uS/cm dan polinomial pada varian mS/cm; pilihan `polinomial_suhu`
  diterapkan ke keduanya karena pertanyaan TM menyangkut nilai tersertifikasi
  larutan. TM wajib mengetahui ini sebelum menjawab.
- **Gerbang MySQL** suite penuh belum dijalankan (3 jam); CI yang menjalankan.
- **Menarik penetapan yang sudah `disetujui`.** Hitung ulang sesi bertanggal
  sesudah `berlaku_mulai` kembali ke penetapan sebelumnya atau bawaan. Sesi yang
  sudah tersimpan dengan penetapan itu tidak berubah, tetapi validator melaporkan
  `hitung_ulang_beda` / `ketidakpastian_beda` untuk sesi tersebut. Disengaja dan
  dijaga tes; respons `tarik` membawa `peringatan`. Menarik sebelum ada sesi yang
  memakainya tidak berdampak.
- **Tanggal server, bukan tanggal WIB.** `berlaku_mulai >= tanggal server` dibaca
  dari `APP_TIMEZONE` (bawaan UTC). Mendekati tengah malam WIB tanggal server bisa
  tertinggal sehari; tarik dan catat ulang bila tertolak `berlaku_surut` padahal
  tanggalnya hari ini menurut WIB.
- **Satu akun Studio.** Pemisahan pencatat dan penyetuju tidak bisa ditegakkan
  (`STUDIO_AKUN` tunggal). Jejak tetap mencatat user, email, dan waktu tiap transisi.
- **CORS dipersempit.** Sebelum `config/cors.php` ada, bawaan framework
  mengizinkan `*` untuk `api/*`. Sekarang bawaan kode (tanpa env apa pun) = empat
  origin yang terbukti ada di repo: dua dev lokal Studio Web (`localhost:5173`,
  `127.0.0.1:5173`) dan dua domain Firebase Hosting bundel web Flutter
  (`https://sidik-kalibrasi.web.app`, `https://sidik-kalibrasi.firebaseapp.com`;
  sumber di kepala `config/cors.php`). `CORS_ALLOWED_ORIGINS` MENAMBAH, tidak pernah
  mengganti, jadi mengisinya tidak bisa memutus bundel web. Origin produksi Studio
  Web belum ada di repo mana pun, jadi tetap lewat env. Kunci itu BELUM ada di
  `render.yaml`/`.env.example` dan env Render belum diubah (butuh pemilik; tinjauan
  PR #304 meminta keduanya, lihat serah terima di bawah). Aplikasi mobile native
  dan panel Filament tidak terkena CORS.
- **Append-only di database.** Selain penjaga model, migrasi
  `2026_11_25_110000_kunci_append_only_penetapan_metode` memasang trigger
  (`pmp_tolak_hapus`, `pmp_tolak_ubah_isi`, `pmpj_tolak_ubah`, `pmpj_tolak_hapus`)
  supaya UPDATE/DELETE mentah lewat query builder ditolak. Penetapan hanya boleh
  berubah di `status`, `keputusan_oleh`, `tanggal_keputusan`, `updated_at`;
  keputusan dibekukan begitu status keluar dari `menunggu_tm`; `ditarik` status
  akhir; penetapan tidak bisa dihapus; jejak tidak bisa diubah atau dihapus.
  Batasnya: (1) MySQL ber-binlog tanpa `log_bin_trust_function_creators` menolak
  `CREATE TRIGGER` dan migrasi sengaja tidak gagal (galat ke log + stderr), jadi
  trigger ini BELUM terpantau `/api/health` (`append_only_db`) dan belum ikut
  `acuan:pasang-trigger`; (2) `TRUNCATE` dan aksi FK berantai tidak menyalakan
  trigger di MySQL (`oleh_user_id ... nullOnDelete`), sedangkan di SQLite
  menghapus user yang punya baris jejak gagal; (3) transisi status lewat SQL
  mentah tanpa baris jejak tidak tertahan (trigger tidak bisa menuntut baris di
  tabel lain). Dijaga `tests/Feature/PenetapanMetodeAppendOnlyDbTest.php`.
  `down()` migrasi tabel menolak jalan selama tabel berisi baris.
- **Binding `scoped`.** `ParameterKeputusanMetode` kini `scoped` (bukan singleton)
  supaya cache penetapan tidak bertahan melewati satu request/job; worker antrean
  menyegarkannya per job. Pemanggil yang menyimpan instance antar job tidak akan
  melihat penetapan baru: ambil lewat container tiap kali.
- **Suite MySQL.** Tes baru berjalan di dua suite lewat shard CI (profil MySQL
  enam shard); hasil lokal MySQL hanya yang tertulis di PR.
