# Catatan: apa yang ikut mati waktu UI pindai dicabut dari HP

**Buat:** siapa pun yang megang backend sesudah Gelombang 0
**Dari:** telusur backend, 24 Agustus 2026
**Status:** dokumentasi doang — **nol baris kode PHP diubah** waktu catatan ini ditulis

> ## KOREKSI 2 September 2026 — §Ringkas & §2a sudah tidak benar
>
> Catatan ini benar **pada 24 Agustus 2026**. Tiga hari kemudian jalur
> `FOTO TABEL INI` mendarat di HP, dan dia **mengirim `input_method: 'ocr'`**
> waktu ada sel yang keisi dari foto
> (`lembar_kerja_submission.dart`, ditegaskan `lembar_kerja_state.dart`).
>
> Jadi kalimat "mobile selalu ngirim `input_method: manual`" **tidak berlaku
> lagi**, dan seluruh §2a yang berdiri di atasnya ikut gugur: gerbang approve
> `is_verified` **aktif**, temuan `ocr_belum_diverifikasi` **muncul lagi**, dan
> `perlu_verifikasi` di detail sesi **tidak selalu `false`**.
>
> Yang **tetap benar**: seluruh §2b (jalur lembar bermarker memang tidak punya
> pemanggil), §3 (endpointnya terbuka tanpa pemanggil), dan §1a–1d (mekanisme
> dua pintunya).
>
> Dibiarkan tertulis di sini, bukan dihapus, karena §1a sudah memperingatkan
> persis skenario ini — "kalau ada jalur lain yang bisa nulis `is_verified:
> false`, gerbangnya masih bisa nyala" — dan yang menutup jalurnya memang
> `$meta !== null` di pintu kedua. Peringatan yang terbukti benar lebih berguna
> utuh daripada dirapikan.
>
> Rantai buktinya di `docs/temuan-gerbang0-ocr-model-lokal.md` §6.

---

## Ringkas

UI pindai/kamera disembunyikan di balik saklar di aplikasi HP. Efek yang sudah
diperkirakan: mobile selalu ngirim `input_method: manual`, jadi gerbang
verifikasi sebelum approve nggak pernah aktif lagi.

**Perkiraan itu benar arahnya, tapi mekanismenya beda dari yang dituliskan di
tugas — dan bedanya penting.** Gerbangnya sama sekali nggak baca
`input_method`. Rinciannya di §1.

Yang mati: seluruh jalur pindai + gerbang verifikasi + loop pengukuran akurasi.
Yang **tidak** mati: statistik, dashboard, laporan, notifikasi — nggak satu pun
dari mereka pernah baca `input_method`, jadi nggak ada angka yang jadi nol atau
grafik yang patah. Yang paling perlu diurus justru bukan yang mati, tapi yang
**tetap hidup tanpa satu pun pemanggil**: `POST /worksheet-scans` (§3).

---

## 1. Gerbangnya: bukan `input_method`, tapi `is_verified`

Baris yang disebut di tugas (`CalibrationController.php:1327-1329`) itu bukan
gerbangnya — itu jalur PENULISAN grid Enclosure. Gerbangnya ada di
`app/Http/Controllers/Api/CalibrationController.php:567-576`:

```php
if ($calibration->rawMeasurements()->where('is_verified', false)->exists()) {
```

Jadi rantainya dua langkah, bukan satu:

1. **Waktu nulis** — `input_method` menentukan baris `raw_measurements` lahir
   dengan `is_verified` true atau false.
2. **Waktu approve** — yang dibaca cuma `is_verified`. `input_method` nggak
   pernah disentuh di sini.

Artinya gerbangnya bukan "mati" dalam arti kodenya nggak jalan. Dia tetap jalan
tiap approve, cuma nggak pernah nemu apa pun. Bedanya kelihatan sepele, tapi
menentukan: **kalau ada jalur lain yang bisa nulis `is_verified: false`,
gerbangnya masih bisa nyala walaupun `input_method`-nya `manual`.** Dan jalur
itu ada.

### 1a. Ada PINTU KEDUA — dan ini yang bikin ceritanya nggak seragam

Di jalur pengukuran umum, `CalibrationController.php:1169`:

```php
$dariKamera = $meta !== null || $sesiKamera;
```

`$meta` itu metadata OCR per baris (`measurements[i].ocr[j]`), divalidasi di
`app/Http/Requests/CalibrationRequest.php:278-286` dan `:425-453`. `$sesiKamera`
baru turunan `input_method` (`:1052-1053`).

Karena `||`, **satu baris yang bawa metadata `ocr` tetap lahir
`is_verified: false` walaupun sesinya `input_method: manual`**
(`:1189`, `:1197`). Jadi buat alat yang lewat jalur umum — sepuluh profil di
`app/Services/Calibration/Profiles/`, semua kecuali Autoclave & Enclosure — gerbangnya
mati cuma kalau HP berhenti ngirim DUA hal: `input_method: 'ocr'` **dan**
`measurements[i].ocr`. Kalau saklar UI cuma nyetel `input_method` dan payload
`ocr` masih kekirim dari sisa kode lama, gerbangnya masih nyala — dan itu bakal
kelihatan sebagai "approve tiba-tiba ditolak" tanpa ada tombol kamera di layar.
Perlu dicek di repo mobile, bukan di sini.

### 1b. Enclosure cuma punya SATU pintu — di situ gerbangnya mati total

Jalur grid Enclosure (`CalibrationController.php:1319-1329`) nggak punya
metadata per baris sama sekali:

```php
$metodeInput = (string) $request->string('input_method', 'manual');
$dariKamera = in_array($metodeInput, ['ocr', 'ai_vision'], true);
```

Hasilnya dipakai di `:1383-1384` (termokopel) & `:1414-1415` (indikator). Nggak
ada `$meta` yang bisa nolong. Jadi buat Enclosure — dan cuma Enclosure —
`input_method: manual` berarti gerbangnya **pasti** nggak pernah aktif, tanpa
pengecualian.

Ironisnya komentar di `:1323-1326` menjelaskan bug yang baru saja dibetulin:
jalur grid dulu selalu nulis `manual` + `is_verified` true apa pun
`input_method`-nya, jadi gerbangnya diam-diam nggak pernah aktif buat Enclosure.
Perbaikan itu sekarang efektif nggak ada bedanya, karena yang ngirim `ocr`-nya
yang hilang. Kalau nanti ada yang ngelihat baris itu dan mengira "nggak ada
efeknya, hapus aja" — jangan. Yang hilang pemanggilnya, bukan gunanya.

### 1c. Jalur yang memang tidak pernah kena gerbang ini

- **Autoclave.** `simpanAutoclave()` (`CalibrationController.php:303`) nggak
  pernah nulis `raw_measurements` sama sekali — hasilnya snapshot JSON di
  `hasil_autoclave`. Jadi gerbang `is_verified` nggak pernah berlaku buat
  Autoclave, sebelum maupun sesudah UI pindai dicabut.
  `AutoclaveStoreRequest.php:48` masih nerima `input_method` — itu murni catatan,
  nggak nyetel apa pun.
- **Baris as-found** (`pembacaan_sebelum`) selalu `manual` + `is_verified` true
  (`CalibrationController.php:1222-1223`). Nggak pernah ada jalur OCR buat
  tahap itu.

### 1d. Yang tidak perlu dikhawatirkan

Kolom `is_verified` default-nya **false** di database
(`database/migrations/2026_07_14_120700_create_raw_measurements_table.php:34`),
jadi wajar khawatir ada baris nyelip tanpa nilai eksplisit. Nggak ada: satu-satunya
tempat `raw_measurements` dibikin itu `CalibrationController.php:893`, dan semua
susunan yang nyampe ke situ selalu nyetel `is_verified` eksplisit.

---

## 2. Yang mati, yang tetap hidup

### 2a. Mati karena nggak ada lagi baris `is_verified: false`

> **SUDAH TIDAK BERLAKU sejak 27 Agt 2026** — lihat koreksi di kepala berkas.
> `FOTO TABEL INI` mengirim `input_method: 'ocr'`, jadi baris
> `is_verified: false` lahir lagi dan seluruh tabel di bawah ini kembali aktif.
> Dipertahankan sebagai catatan sejarah, bukan sebagai keadaan sekarang.

| Apa | Alamat | Yang terjadi |
|---|---|---|
| Gerbang approve API | `app/Http/Controllers/Api/CalibrationController.php:567-576` | Tetap dieksekusi, nggak pernah nemu baris |
| Temuan `ocr_belum_diverifikasi` | `app/Services/CalibrationValidator.php:191-197` | Temuan level ERROR (nahan `boleh_terbit`) yang nggak pernah muncul lagi — termasuk di `GET /calibrations/{id}/validasi` |
| Gerbang approve di panel Filament | `app/Filament/Resources/CalibrationSessions/Tables/CalibrationSessionsTable.php:134-148` | Sama, cabang yang nggak pernah kepilih |
| `POST /calibrations/{id}/measurements/verify` | rute `routes/api.php:258-262`, isi `CalibrationController.php:833-872` | Masih bisa dipanggil, tapi `meta.diverifikasi` selalu 0 |
| `perlu_verifikasi` di detail sesi | `app/Http/Resources/CalibrationResource.php:277-280` | Selalu `false` |
| `input_source` per pembacaan | `app/Http/Resources/CalibrationResource.php:306-307` | Selalu `"manual"`; `photo_path`, `ocr_confidence`, `ocr_raw_text` selalu null |

### 2b. Mati karena nggak ada pemanggilnya (pipeline pindai itu sendiri)

Semua rute di bawah masih terdaftar dan masih jalan — cuma nggak ada yang
manggil:

| Endpoint | Rute | Akibat sampingnya |
|---|---|---|
| `GET /worksheet-templates` & `/{kode}` | `routes/api.php:244-245` | Ini yang dipakai HP buat mutusin tombol kamera nyala atau nggak (`WorksheetScanController.php:48-53`). Sekarang jawabannya nggak ada yang baca |
| `POST /worksheet-scans` | `routes/api.php:248-249` | Nggak ada baris `worksheet_scans` baru. **Lihat §3 — ini yang paling perlu diurus** |
| `GET /worksheet-scans/{id}` | `routes/api.php:250` | — |
| `GET /worksheet-scans/{id}/sel/{kunci}/crop` | `routes/api.php:253-255` | — |
| `POST /worksheet-scans/{id}/koreksi` | `routes/api.php:256` | Yang ini paling mahal, lihat di bawah |

**Loop akurasi berhenti, dan diamnya menyesatkan.** `nilai_final` cuma keisi
lewat endpoint koreksi (`WorksheetScanController.php:224-284`). Tanpa itu,
`php artisan ocr:akurasi` cuma bilang "belum ada koreksi teknisi"
(`app/Console/Commands/AkurasiOcr.php:47-60`). Yang berbahaya: metrik **hijau
palsu** — sel yang keisi otomatis padahal salah, ukuran paling penting menurut
`docs/SPEC-ocr-template-lokal.md` §7 — bakal turun ke nol. Nol di situ artinya
"nggak ada data", **bukan** "OCR-nya sudah sempurna". Siapa pun yang lihat
angkanya sesudah Gelombang 0 harus tahu bedanya.

**Retensi citra tetap jalan dan itu memang benar.**
`php artisan ocr:bersihkan-citra` masih terjadwal harian 02:30
(`routes/console.php:30`, isinya `app/Console/Commands/BersihkanCitraPindai.php`).
Nggak ada pindai baru, tapi dia masih ngabisin tunggakan yang ada. Konsekuensinya
yang perlu diingat: sesudah `umur_citra_hari` (default 90,
`config/ocr.php:226`) lewat, `citra_warp_path` semua pindai lama sudah kosong,
jadi buka ulang hasil pindai lama bakal 404 di endpoint crop
(`WorksheetScanController.php:182`). Itu perilaku yang dirancang, bukan bug.

**Jalur AI Vision sudah mati duluan, sebelum ini.**
`POST /raw-measurements/extract-from-photo` (`routes/api.php:235-236`) sudah
nggak punya pemanggil sejak mobile pindah ke pindai lokal — ditulis terus terang
di `app/Http/Controllers/Api/WorksheetExtractionController.php:18-31`. Dia punya
saklar sendiri, `VISION_AKTIF=false` (`:93-99`). Jalur lokal **nggak punya**
saklar sejenis; ini yang jadi rekomendasi utama di §3.

### 2c. Tetap hidup — jangan diutak-atik "karena OCR-nya dimatikan"

- **Statistik & dashboard: nggak kena sama sekali.** `DashboardController` nggak
  pernah baca `input_method` maupun `input_source` — hitungannya semua
  berdasarkan `status` sesi (`app/Http/Controllers/Api/DashboardController.php:43-65`).
  `LaporanController` juga nggak. Nggak ada grafik yang patah, nggak ada
  pembagian yang jadi nol.
- **Notifikasi: nggak kena.** Nggak ada satu pun kelas di `app/Notifications/`
  yang nyinggung pindai/OCR/verifikasi pembacaan.
- **`input_method` masih dikirim balik ke klien** di
  `app/Http/Resources/CalibrationResource.php:157` — nilainya cuma jadi konstan
  `"manual"`. Kontraknya di `docs/kontrak-api.md:508` & `:524` tetap benar apa
  adanya.
- **Seluruh pipeline OCR sisi server** (`app/Services/Ocr/*`), template yang
  diturunin dari profil alat (`app/Services/Calibration/CalibrationProfileRegistry.php:78`),
  dan perintah cetak `ocr:rangka-geometri` / `ocr:cetak-lembar` — semuanya utuh
  dan masih bisa diuji.
- **Test-nya semua tetap hijau, dan itu justru jebakan.**
  `tests/Feature/WorksheetScanTest.php` (32 test) dan
  `tests/Feature/OcrMeasurementTest.php` (17 test) manggil API langsung, bukan
  lewat UI HP. Jadi suite test **nggak akan pernah ngasih tau** kalau fiturnya
  nggak dipakai siapa-siapa. Jangan pakai "test-nya hijau" sebagai bukti jalur
  ini masih hidup.
- **Kolom `input_method` & `input_source` sengaja VARCHAR(20), bukan ENUM**
  (`database/migrations/2026_08_07_150000_change_input_method_to_string_on_calibration_sessions.php`
  & `database/migrations/2026_07_24_100100_change_input_source_to_string_on_raw_measurements.php`).
  Godaan buat "merapikan" balik ke ENUM sekarang mumpung isinya cuma `manual`
  itu langsung nabrak `OcrMeasurementTest::test_kolom_sumber_input_bukan_enum_sempit`
  (`tests/Feature/OcrMeasurementTest.php:386`) — dan itu memang penjaganya.

---

## 3. Sisi keamanan: `POST /worksheet-scans` terbuka tanpa pemanggil

Ini bagian yang paling perlu keputusan. Endpoint yang nggak dipakai siapa-siapa
itu justru yang paling gampang lolos dari perhatian waktu ditinjau — persis
alasan kenapa jalur AI Vision dikasih saklar.

### 3a. Siapa yang boleh manggil

Berlapis dua, dan dua-duanya beneran jalan:

- `auth:sanctum` — grup besar di `routes/api.php:75`.
- `role:admin,teknisi` — grup di `routes/api.php:200`, ditegakkan
  `app/Http/Middleware/EnsureUserHasRole.php:14-22`. Role `viewer` ketolak 403.

`WorksheetScanRequest::authorize()` sendiri `return true`
(`app/Http/Requests/WorksheetScanRequest.php:28-31`) — itu benar, izinnya
memang di middleware.

Scoping organisasi juga beneran ada:

- sesi divalidasi seorganisasi + teknisi cuma boleh mindai lembar pekerjaannya
  sendiri (`WorksheetScanController.php:411-431`);
- alat dibatasi ke organisasi pemakainya (`:396-405`);
- baca hasil pindai: lintas organisasi jadi **404** (bukan 403, jadi nggak bocor
  keberadaan barisnya), teknisi lain jadi 403 (`:430-446`).

**Catatan yang perlu diketahui**: repo ini nggak punya `app/Policies/` sama
sekali. Seluruh otorisasi bersandar pada middleware `role:` di rute plus
`abort_if` di dalam controller. Itu bukan bug, tapi artinya nggak ada lapis
kedua kalau suatu hari ada yang mindahin rute keluar dari grup di
`routes/api.php:200` — dan nggak ada test yang bakal ngeluh.

### 3b. Rate limit: ada di dua rute, kosong di sisanya

| Rute | Batas | Alamat |
|---|---|---|
| `POST /worksheet-scans` | `throttle:60,1` | `routes/api.php:248-249` |
| `GET .../sel/{kunci}/crop` | `throttle:300,1` | `routes/api.php:253-255` |
| `GET /worksheet-scans/{id}` | **nggak ada** | `routes/api.php:250` |
| `POST .../koreksi` | **nggak ada** | `routes/api.php:256` |
| `GET /worksheet-templates` & `/{kode}` | **nggak ada** | `routes/api.php:244-245` |

Dan nggak ada jaring pengaman global: `bootstrap/app.php:17-35` nggak masang
`throttle:api` di grup mana pun, dan limiter bernama di
`app/Providers/AppServiceProvider.php:84` cuma kepasang di rute publik
(`routes/api.php:52-66`). Jadi "nggak ada throttle" di tabel itu benar-benar
tanpa batas.

### 3c. Risiko nyatanya, dari yang paling gawat

1. **Disk penuh diam-diam.** Di `store()`, penyimpanan dipanggil
   **sebelum** cabang kegagalan (`WorksheetScanController.php:107` vs
   `:112-131`). Jadi pindai yang ditolak pun tetap nulis baris `worksheet_scans`
   **plus dua berkas citra**. Dua citra × 8 MB
   (`app/Http/Requests/WorksheetScanRequest.php:107-108`) × 60 request/menit ≈
   ~960 MB/menit dari satu akun sah, dan retensinya 90 hari
   (`config/ocr.php:226`). Kalau `OCR_DISK` masih `local`
   (`config/ocr.php:209`), yang penuh itu disk aplikasi — yang mati bukan cuma
   pindai, tapi seluruh API. Nyimpen yang ditolak itu keputusan yang benar
   (SPEC §7: yang ditolak paling berguna buat nyetel ambang), tapi keputusan itu
   dibikin waktu ada teknisi yang beneran mindai dan ada yang melihat angkanya.
2. **Banjir baris tanpa batas.** `POST .../koreksi` nggak punya throttle dan
   nulis sampai 600 baris per panggilan dalam satu transaksi
   (`WorksheetScanController.php:239-270`, batas 600 di
   `app/Http/Requests/WorksheetScanRequest.php:26` & `:79`). `store()` juga bikin
   sampai 600 `worksheet_scan_cells` sekali jalan.
3. **Citra lembar kerja pelanggan, dan nggak ada jejak siapa yang bacanya.**
   `WorksheetScan` **nggak** pakai trait `Diaudit`
   (`app/Models/WorksheetScan.php:23`) — padahal `CalibrationSession` pakai
   (`app/Models/CalibrationSession.php:49`). Yang tercatat cuma kegagalan, lewat
   `Log::warning('ocr.scan_ditolak')` (`WorksheetScanController.php:112`).
   Artinya: akun teknisi yang jatuh ke tangan orang lain bisa nyusurin
   `GET /worksheet-scans/{id}` (id-nya berurutan, `$table->id()`) tanpa throttle
   dan narik potongan citranya, dan **nggak ada satu baris pun di sistem yang
   nyatet itu terjadi**. Buat lab terakreditasi, "siapa yang lihat lembar kerja
   pelanggan itu" adalah pertanyaan yang harus bisa dijawab.
4. **Endpoint crop mahal per panggilan.** `crop()` men-decode ulang citra pakai
   `imagecreatefromstring` tiap request (`WorksheetScanController.php:170-215`),
   sampai 300×/menit. Berkasnya milik server sendiri, jadi ini bukan celah baca
   berkas sembarangan — tapi tetap CPU & memori per panggilan di fitur yang
   nggak ada yang mantau.
5. **Nggak ada saklar.** Jalur AI Vision bisa dimatikan lewat `VISION_AKTIF=false`
   (`WorksheetExtractionController.php:93-99`). Jalur lokal nggak punya
   padanannya — satu-satunya `aktif` di `config/ocr.php` itu
   `tulisan_tangan.aktif` (`:132`), beda urusan.

### 3d. Rekomendasi (belum dikerjakan, sengaja)

Urut dari yang paling banyak nutup dengan paling sedikit kode:

1. **Bikin saklar `OCR_PINDAI_AKTIF`, cerminan `VISION_AKTIF`.** Waktu mati:
   `store`, `koreksi`, dan `crop` balikin 503 dengan pesan yang jelas; sementara
   `GET /worksheet-templates` tetap jawab tapi dengan `siap_pindai: false`, biar
   APK lama yang masih beredar di lapangan turun ke input manual dengan rapi
   alih-alih kena error keras. Satu tuas ini nutup risiko 1, 2, dan 4 sekaligus,
   dan Gelombang 5 tinggal membalikkannya.
2. **Kalau saklar dianggap kebanyakan buat sekarang:** minimal pindahkan
   penulisan citra ke SESUDAH gerbang mutu/geometri lolos, atau jangan simpan
   citra buat pindai yang gagal. Yang bikin pindai gagal berguna buat nyetel
   ambang itu `payload` mentahnya (SPEC §7), bukan foto 8 MB-nya.
3. **Pasang throttle di dua rute yang kosong.** `throttle:120,1` buat
   `GET /worksheet-scans/{id}` dan `throttle:30,1` buat `koreksi` sejalan sama
   tetangganya. Yang tanpa batas jangan dibiarin cuma karena "toh nggak ada yang
   manggil" — justru itu alasannya.
4. **Pertimbangkan `Diaudit` di `WorksheetScan`,** atau minimal catat pembacaan
   crop. Ini perlu buat akreditasi apa pun kondisi UI-nya, dan jauh lebih murah
   dipasang sekarang waktu tabelnya nggak tumbuh.
5. **Biarkan `ocr:bersihkan-citra` tetap terjadwal** (`routes/console.php:30`).
   Dia yang ngabisin tunggakan citra selama fiturnya tidur. Mematikannya "karena
   pindainya juga mati" bakal ninggalin foto lembar kerja pelanggan di disk tanpa
   batas.

---

## 4. Waktu Gelombang 5 datang: yang harus dihidupkan lagi

Sisi server nggak ada yang perlu dibangun ulang. Yang perlu dicek:

1. **Dua pintu, bukan satu.** Mobile harus ngirim `input_method: 'ocr'` (atau
   `'ai_vision'`) lagi, **dan/atau** metadata `measurements[i].ocr`. Ingat
   §1a-1b: jalur umum nyala dari salah satu (`CalibrationController.php:1169`),
   **Enclosure cuma dari `input_method`** (`:1327-1329`). Kalau Enclosure balik
   dipindai tapi cuma metadata per baris yang dikirim, gerbang verifikasinya
   nggak nyala dan nggak ada yang ngasih tau.
2. **Balikin saklarnya** kalau rekomendasi §3d.1 jadi dikerjakan.
3. **Jangan kaget kalau pindai lama nggak bisa dibuka ulang.** Semua pindai yang
   umurnya lewat `umur_citra_hari` sudah kehilangan citra warp-nya, jadi
   endpoint crop bakal 404 (`WorksheetScanController.php:182`). Itu retensi
   bekerja, bukan data hilang.
4. **Naikin `aturan_versi`** (`config/ocr.php:49`) kalau ada ambang di
   `config/ocr.php` yang digeser selama masa tidur. Tanpa itu, hasil pindai
   sebelum dan sesudah jeda nggak bisa dibandingin dengan jujur.
5. **Baseline akurasi mulai dari kosong.** `ocr:akurasi --hari=30` bakal sepi
   sebulan pertama sesudah balik. Kalau mau ada pembanding, **catat angka
   terakhir sebelum jeda sekarang**, jangan nanti — sesudah 30 hari
   `--hari=30` sudah nggak bisa nyampe ke sana.
6. **Jangan nyempitin kolomnya** selama jeda — lihat §2c poin terakhir.
7. **Test yang wajib dijalankan waktu menghidupkan lagi:**
   `tests/Feature/OcrMeasurementTest.php` (gerbang verifikasi & approve, 17 test)
   dan `tests/Feature/WorksheetScanTest.php` (anti-tertukar, 32 test).

---

## Lampiran: daftar alamat yang dipakai catatan ini

Gerbang & jalur tulis
- `app/Http/Controllers/Api/CalibrationController.php:47` — docblock yang bilang `input_method` "buat statistik, bukan buat logic beda"
- `app/Http/Controllers/Api/CalibrationController.php:567-576` — gerbang approve
- `app/Http/Controllers/Api/CalibrationController.php:833-872` — endpoint verifikasi
- `app/Http/Controllers/Api/CalibrationController.php:893` — satu-satunya tempat `raw_measurements` dibikin
- `app/Http/Controllers/Api/CalibrationController.php:1052-1056`, `:1169`, `:1189`, `:1197` — jalur umum
- `app/Http/Controllers/Api/CalibrationController.php:1222-1223` — as-found
- `app/Http/Controllers/Api/CalibrationController.php:1319-1329`, `:1383-1384`, `:1414-1415` — jalur grid Enclosure
- `app/Http/Controllers/Api/CalibrationController.php:303` — Autoclave (nggak nulis `raw_measurements`)
- `app/Http/Requests/CalibrationRequest.php:119`, `:278-286`, `:425-453`
- `app/Http/Requests/AutoclaveStoreRequest.php:48`
- `app/Services/CalibrationValidator.php:191-197`
- `app/Filament/Resources/CalibrationSessions/Tables/CalibrationSessionsTable.php:134-148`
- `app/Http/Resources/CalibrationResource.php:157`, `:277-280`, `:306-307`

Jalur pindai
- `routes/api.php:75`, `:200`, `:235-236`, `:244-245`, `:248-249`, `:250`, `:253-255`, `:256`, `:258-262`
- `app/Http/Controllers/Api/WorksheetScanController.php:48-53`, `:83`, `:107`, `:112-131`, `:151`, `:170-215`, `:224-284`, `:290`, `:396-405`, `:411-431`, `:430-446`
- `app/Http/Requests/WorksheetScanRequest.php:26`, `:28-31`, `:79`, `:107-108`
- `app/Http/Controllers/Api/WorksheetExtractionController.php:18-31`, `:93-99`
- `app/Models/WorksheetScan.php:23` (tanpa `Diaudit`) vs `app/Models/CalibrationSession.php:49` (pakai)
- `config/ocr.php:48-49`, `:132`, `:209`, `:226-227`
- `routes/console.php:30`, `app/Console/Commands/BersihkanCitraPindai.php:37-46`
- `app/Console/Commands/AkurasiOcr.php:47-60`

Yang nggak kena
- `app/Http/Controllers/Api/DashboardController.php:43-65`
- `app/Http/Middleware/EnsureUserHasRole.php:14-22`, `bootstrap/app.php:17-35`
- `database/migrations/2026_07_14_120700_create_raw_measurements_table.php:28`, `:34`
- `database/migrations/2026_07_24_100100_change_input_source_to_string_on_raw_measurements.php`
- `database/migrations/2026_08_07_150000_change_input_method_to_string_on_calibration_sessions.php`
- `tests/Feature/OcrMeasurementTest.php:219`, `:232`, `:386`; `tests/Feature/WorksheetScanTest.php`
- `docs/SPEC-ocr-template-lokal.md` §7 & §9, `docs/kontrak-api.md:508` & `:524`
