# Temuan: Gerbang Keputusan 0 — model pembaca sel buatan sendiri

**Buat:** pemilik proyek
**Dari:** verifikasi repo atas perintah "Model Pembaca Sel Buatan Sendiri (OCR Lokal)", 2 Sep 2026
**Status:** **BERHENTI di Gerbang 0** — nol baris kode model ditulis, sesuai §2 perintahnya sendiri

---

## Ringkas

Perintahnya minta mengganti **satu komponen**: `MlKitPembacaSel` di
`mobile/lib/services/pembaca_sel.dart`. Rancangannya bagus dan alasannya sah —
tiga justifikasi di §1 saya cek satu per satu dan **semuanya benar**.

Yang tidak benar cuma satu kata, dan kebetulan kata itu yang menyangga seluruh
proyeknya. Tabel §0 menulis komponen-komponen pipeline berstatus **"jalan"**.
Yang benar: **utuh dan masih lolos test, tapi tidak bisa dicapai dari aplikasi.**

Tombol yang menyalakan jalur ini — `PINDAI LEMBAR KERJA` — **dicabut permanen
dari layar 26 Agustus 2026**, atas permintaan pemilik lab sendiri. Akibatnya
`MlKitPembacaSel` hari ini **tidak pernah dipanggil satu kali pun** oleh
aplikasi yang dipegang teknisi.

Mengganti komponen yang tidak pernah dipanggil tidak mengubah apa pun yang
dilihat teknisi — sebagus apa pun modelnya.

---

## 1. Rantai buktinya

Ditelusuri dari tombol ke bawah. Tiap baris bisa dicek sendiri.

| # | Yang dicek | Hasilnya |
|---|---|---|
| 1 | Tombol di layar | `mobile/lib/screens/calibration/lembar_kerja_screen.dart:1712` — *"Tombol PINDAI LEMBAR KERJA **DICABUT PERMANEN** (26 Agt 2026, atas permintaan pemilik lab: 'hapus dari semua alat, gk butuh, gk jelas juga')"* |
| 2 | Mesin pindainya | `JalankanPindai(` diinstansiasi **cuma di `test/jalankan_pindai_test.dart`**. Nol pemanggil di seluruh `lib/` |
| 3 | Pengiriman ke server | `kirimPindai` (`POST /worksheet-scans`) — **nol pemanggil** di seluruh repo mobile |
| 4 | Layar review | `PindaiReviewScreen(` cuma muncul sebagai konstruktornya sendiri. **Tidak pernah dibuka dari mana pun** |
| 5 | Endpoint koreksi | `kirimKoreksi` satu-satunya pemanggilnya `pindai_review_screen.dart:93` — layar di baris 4 yang tidak pernah dibuka |
| 6 | Pembaca selnya | `MlKitPembacaSel.new` terdaftar di `worksheet_scan_provider.dart:54`, tapi **tidak ada yang memanggil `.sel()`**. Yang dipanggil cuma `.halaman()` (`ambil_foto_tabel.dart:99`) |

Rantainya putus di langkah 1 dan tidak pernah nyambung lagi.

### Satu-satunya tombol kamera yang hidup tidak lewat sini

`FOTO TABEL INI` (`ambil_foto_tabel.dart`) memakai `MlKitPembacaHalaman`, bukan
`PembacaSel` — dan §3 perintah ini menaruh `MlKitPembacaHalaman` sebagai
**non-tujuan** secara eksplisit.

Lebih menentukan lagi: **`ambil_foto_tabel.dart` tidak mengirim apa pun ke
server.** Tidak ada `POST`, tidak ada `scan_id`. Jadi jalur kamera yang benar-benar
dipakai teknisi hari ini **tidak melahirkan satu baris `worksheet_scans` pun.**

---

## 2. Akibatnya ke tiap milestone

### M0 "Selamatkan dataset" — mengumpulkan NOL crop

Ini yang paling perlu diluruskan, karena §5 menandainya "PALING MENDESAK" dengan
alasan waktu kalender: *"Setiap hari Milestone 0 tertunda adalah satu hari data
yang hilang permanen."*

Di keadaan sekarang **kalimat itu terbalik.** Crop ditulis waktu teknisi
mengoreksi; koreksi tidak punya pemanggil (rantai §1 langkah 5). Jadi:

- data latih **tidak sedang hilang** — dia **tidak sedang lahir**;
- menunda M0 satu bulan **tidak menghilangkan apa pun**;
- mengerjakan M0 minggu ini menghasilkan kode yang, seperti komponen lain di
  tabel §0, benar dan teruji tapi tidak pernah dipanggil.

Diagnosis teknis M0 sendiri **tepat sepenuhnya** dan tetap perlu dikerjakan
begitu jalurnya hidup lagi: `crop_path` memang ada
(`2026_08_13_090100_create_worksheet_scan_cells_table.php:62`) dan memang tidak
pernah diisi — `config/ocr.php` dan `BersihkanCitraPindai.php` dua-duanya sudah
mengakuinya sendiri di komentar. Yang salah cuma urgensinya.

### M1 "Ukur baseline ML Kit" — tidak ada yang bisa diukur

`ocr:akurasi` bersumber cuma dari `nilai_final`, yang cuma lahir dari endpoint
koreksi. `ocr:akurasi --hari=60` hari ini pulang *"belum ada koreksi teknisi"*.

`docs/catatan-cabut-ui-pindai.md` sudah memperingatkan jebakan ini duluan:
**nol hijau palsu artinya "tidak ada data", bukan "OCR-nya sudah sempurna".**
Tanpa M1, M7 tidak punya pembanding — dan §12 memakai perbandingan itu sebagai
syarat promosi yang tidak bisa ditawar.

### M4 "butuh ≥3.000 crop asli" — lajunya nol per hari

Tanpa langkah 3–5 di rantai §1, angkanya tidak pernah bertambah. §16 sudah
menyiapkan jawaban jujurnya: *"Model bisa saja tidak pernah mengalahkan ML Kit
dengan data yang tersedia."* Di sini bahkan lebih tajam — datanya tidak akan
pernah ada, bukan tidak cukup.

---

## 3. Dua temuan susulan yang perlu keputusan

### 3a. Cek privasi §14.2 menolak SEMUA template

§14.2 minta ekspor dataset ditolak untuk template yang tidak mendefinisikan
region kepala lembar — *"bukan diloloskan dengan asumsi"*. Itu fail-closed yang
benar, dan saya tidak mengusulkan melonggarkannya.

Faktanya: **0 dari 23** berkas geometri punya region kepala. Kuncinya belum ada
sama sekali di skemanya (`template_id`, `versi`, `marker`, `qr`, `jangkar`,
`tabel`, `sel`, ...). Jadi `ocr:ekspor-dataset` sebagaimana dispesifikasikan
akan menolak semuanya, dan DoD M0 *"menghasilkan folder yang bisa dibaca skrip
latih"* **tidak bisa dipenuhi** tanpa menambah region kepala ke berkas geometri —
sementara §15.8 melarang menyentuh berkas geometri template.

Itu kontradiksi di dalam perintahnya sendiri, dan yang memutuskan pemilik
proyek: koordinat kepala lembar ikut revisi FORMULIR, yaitu dokumen mutu.

### 3b. 17 dari 23 geometri masih `terverifikasi: false`

Kalaupun tombolnya dipasang lagi besok, cuma **6 template** yang jalan. Sisanya
tombolnya digambar tapi mati — perilaku yang memang dirancang begitu, tapi perlu
masuk hitungan waktu memperkirakan biaya menghidupkan jalur ini.

---

## 4. Jawaban Gerbang 0

Pertanyaan §2: *"lembar yang akan difoto teknisi itu yang mana?"*

Repo menjawabnya sendiri: yang difoto teknisi hari ini **formulir lama lab yang
tidak bermarker**, lewat `FOTO TABEL INI` — dinyatakan terang-terangan di
`app_config.dart`. Itu **Jalur B**.

Dan keadaannya satu tingkat lebih jauh dari yang §2 bayangkan. Jalur B
memperkirakan scan-nya ditolak di gerbang geometri. Kenyataannya **tidak pernah
sampai ke gerbang mana pun** — tidak ada pengiriman sama sekali.

Instruksi §2 untuk Jalur B: **BERHENTI, laporkan ke pemilik proyek, jangan
akali dengan melonggarkan `geometri.marker_min` atau mematikan cek QR.** Itu yang
dikerjakan dokumen ini. Nol ambang digeser, nol gerbang dilonggarkan.

---

## 5. Yang TETAP benar dari perintah itu

Supaya tidak terbaca sebagai penolakan — rancangannya sendiri saya cek dan tahan
uji:

- **§1.1 benar.** `MlKitPembacaSel.baca()` memang selalu pulang `keyakinan: null`.
  Seluruh arsitektur ambang (`ocr.ambang.hijau` 0,85 / `kuning` 0,60) memang
  berjalan tanpa masukan dari pembacanya. Ini tetap keuntungan terbesar dari
  pekerjaan ini kalau jadi dikerjakan.
- **§1.2 benar.** `tulisan_tangan.status_maks` memang terkunci `kuning`.
- **§1.3 benar.** Komentar `_tinggiMin` memang mencatat hurufnya selamat dan
  angkanya hilang di uji HP 14 Agt 2026.
- **§5 diagnosisnya benar.** `crop_path` memang tidak pernah diisi.
- **§10 (kalibrasi keyakinan) dan §12 (shadow mode)** justru bagian paling kuat
  dari rancangan ini, dan tetap berlaku kapan pun dikerjakan.
- **Kriteria §4 yang memilih hijau palsu sebagai metrik utama** — bukan akurasi
  rata-rata — itu pilihan yang benar untuk lab terakreditasi.

Tidak ada satu pun dari itu yang perlu dirancang ulang. Yang hilang cuma
pemanggilnya.

---

## 6. Jalan keempat — yang tidak ada di perintah, dan paling murah

Waktu menelusuri jalur hidup, ketemu sesuatu yang mengubah rekomendasi: **loop
ground truth di jalur `FOTO TABEL INI` sudah terpasang separuh.** Bukan nol.

Yang **sudah** jalan hari ini:

| Bagian | Bukti |
|---|---|
| HP mengirim `input_method: 'ocr'` waktu sel terisi dari foto | `lembar_kerja_submission.dart:95` & `:329`; ditegaskan `lembar_kerja_state.dart:2988` |
| Server menyalakan gerbang verifikasi dari situ | `CalibrationController.php:1167-1169` — `$sesiKamera = in_array($metodeInput, ['ocr','ai_vision'])` |
| Baris kamera lahir `is_verified: false` → approve tertahan | gerbangnya `CalibrationController.php:567-576` |
| Kolom penampung tebakan mesin **sudah ada** | `raw_measurements`: `input_source`, `ocr_confidence`, `ocr_raw_text`, `photo_path` (migrasi `:28-31`) |
| Server **sudah** memvalidasi & menulis metadata per baris | `CalibrationRequest.php:497-505` (`measurements.*.ocr.*.raw_text`, `.confidence`) |

> Catatan: ini **membantah** `docs/catatan-cabut-ui-pindai.md` §2a yang menulis
> mobile selalu mengirim `input_method: manual`. Catatan itu benar waktu ditulis
> (24 Agt), lalu jalur `FOTO TABEL INI` mendarat 27 Agt dan menghidupkannya
> lagi. Gerbang verifikasi **tidak mati**. Catatan itu perlu dikoreksi.

Yang **belum** ada, dan cuma ini:

**HP tidak pernah mengisi `measurements[i].ocr[]`.** Slotnya ada di kontrak,
divalidasi server, kolomnya ada di tabel — cuma tidak pernah dikirim. Akibatnya
`ocr_raw_text` selalu null.

### Kenapa satu kekosongan itu yang menghabisi seluruh pengukuran

Teknisi mengoreksi angka kamera **di tempat**, di kotak yang sama. Begitu dia
mengetik ulang, tebakan mesinnya **tertimpa dan hilang selamanya**. Yang sampai
`raw_measurements.pembacaan` cuma angka akhir.

Jadi walaupun jalur kameranya hidup dan dipakai tiap hari, sistem ini
**tidak menyimpan satu pun bukti apa yang ditebak mesin.** Tanpa itu:

- akurasi jalur hidup **tidak bisa dihitung**, hari ini maupun nanti;
- **hijau palsu tidak bisa dihitung** — metrik yang §4 perintah itu sendiri
  tetapkan sebagai satu-satunya yang menentukan;
- klaim "kameranya jelek" atau "kameranya bagus" dua-duanya **tidak bisa
  dibuktikan maupun dibantah.**

Dan ini berlaku **tidak peduli pembacanya ML Kit, model CRNN buatan sendiri,
atau apa pun berikutnya.** Mengganti pembaca tanpa memasang perekam ini berarti
mengganti sesuatu yang tidak pernah diukur dengan sesuatu yang juga tidak akan
pernah diukur.

---

## 7. Rekomendasi

**Kerjakan perekam ground truth di jalur yang hidup. Jangan mulai dari model.**

Alasannya bukan "model itu susah" — rancangan modelnya bagus. Alasannya urutan:

1. Perintah §4 menetapkan **hijau palsu** sebagai metrik yang memutuskan, dan
   §12 mewajibkan shadow mode membandingkan model lawan ML Kit. **Dua-duanya
   mustahil dijalankan** sebelum tebakan mesin tersimpan. Model yang dibangun
   sekarang tidak akan punya cara membuktikan dirinya lebih baik.
2. Biayanya sangat timpang. Perekam ini **satu berkas di HP** (mengisi array
   yang kontraknya sudah ada) + **satu perintah artisan** di API. Bandingkan
   dengan 22 hari kerja M0–M7 yang bermuara ke komponen tak terpanggil.
3. Dia **berguna di ketiga jalur** Gerbang 0. Kalau jalur bermarker dihidupkan
   lagi, datanya sudah mengalir. Kalau tidak, jalur hidup akhirnya terukur.
   Kalau ternyata Jalur C (Excel), angkanya yang membuktikan kamera memang tidak
   perlu — dengan bukti, bukan pendapat.
4. Dia menutup lubang akreditasi yang berdiri sendiri: `input_source` per
   pembacaan sekarang selalu `manual` di `CalibrationResource.php:306-307`,
   padahal angkanya datang dari kamera. Untuk lab terakreditasi, **selisih
   antara yang tercatat dan yang terjadi itu temuan audit** — kalimat yang
   `config/ocr.php` sendiri sudah tulis.

### Rencana berkas — **DISETUJUI & DIKERJAKAN 2 Sep 2026**

Ditulis di sini karena §7 permintaan 7 mengikat: *"Tunjukkan rencana file yang
akan dibuat/diubah lebih dulu, tunggu saya setujui, baru eksekusi."*

**Mobile** (`sidik-calibration-mobile`)

| Berkas | Perubahan |
|---|---|
| `lib/models/lembar_kerja_submission.dart` | Isi `measurements[i].ocr[]` = `{raw_text, confidence}` per pembacaan yang lahir dari foto. Bentuknya sudah dipatok `CalibrationRequest.php:497-505` |
| `lib/screens/calibration/lembar_kerja_state.dart` | Simpan teks mentah + skor per sel waktu `_isiSel` menuang hasil foto, supaya masih ada waktu submit |
| `test/` (berkas baru) | Sel diisi foto lalu **diketik ulang teknisi** → payload tetap membawa tebakan aslinya. Ini test yang menjaga seluruh gunanya |

**API** (`sidik-calibration-api`)

| Berkas | Perubahan |
|---|---|
| `app/Console/Commands/AkurasiKamera.php` (baru) | Akurasi per kolom dari `raw_measurements`: `ocr_raw_text` lawan `pembacaan` final. Meniru `AkurasiOcr` apa adanya, termasuk memisahkan **hijau palsu** |
| `docs/catatan-cabut-ui-pindai.md` | Koreksi §2a — gerbang verifikasi **tidak** mati (lihat §6 di atas). Catatan yang salah lebih berbahaya daripada tidak ada catatan |
| `tests/Feature/` (berkas baru) | Baris ber-metadata `ocr` menyimpan `ocr_raw_text` & lahir `is_verified: false` |

**Nol** perubahan di: `config/ocr.php`, berkas geometri template, `ValidasiSel.php`,
`pindai_lembar.dart`, `jalankan_pindai.dart`, `pembaca_sel.dart`. Ambang tidak
digeser, gerbang tidak dilonggarkan, `MlKitPembacaSel` tidak dihapus — §15
larangan 1–10 semuanya tetap utuh.

### Yang TIDAK dikerjakan, dan kapan ditinjau ulang

M2–M8 (harness, sintetis, CRNN+CTC, kalibrasi, integrasi, shadow) **ditunda,
bukan dibatalkan.** Ditinjau ulang begitu ada **≥2 minggu data akurasi jalur
hidup**. Waktu itu pertanyaannya bisa dijawab pakai angka: kolom mana yang
gagal, seberapa sering, dan apakah plafon tulisan tangan memang yang menahan —
persis yang M1 mau capai, tapi di jalur yang benar-benar dipakai teknisi.

---

## 8. Yang benar-benar dibangun (2 Sep 2026)

Disetujui pemilik proyek, lalu dikerjakan. Yang berubah dari rencana: **nol**
perubahan di sisi tulis API — ternyata `CalibrationController` sudah menulis
`ocr_raw_text`, `ocr_confidence`, dan `input_source` sejak lama
(`:1283-1312`). Yang kurang cuma pengirimnya di HP, dan alat ukurnya.

**Mobile**

| Berkas | Yang dikerjakan |
|---|---|
| `lib/models/lembar_kerja_submission.dart` | Kelas `BacaanMesin` + deret `ocr` sejajar indeks dengan `pembacaan`. Kunci `ocr` cuma ikut terkirim kalau ADA yang dari foto |
| `lib/screens/calibration/lembar_kerja_state.dart` | Peta `bacaanMesinSel` (kunci `kunciSel`, sama seperti `selRendahKeyakinan`), diisi di `_isiSel`, ditempelkan ke payload lewat `_lampirkanBacaanMesin` |
| `test/tebakan_mesin_ikut_terkirim_test.dart` | 5 test; yang menentukan: sel yang **diketik ulang teknisi** tetap membawa tebakan aslinya |

**API**

| Berkas | Yang dikerjakan |
|---|---|
| `app/Console/Commands/AkurasiKamera.php` | `ocr:akurasi-kamera --hari= --kategori=` — akurasi per kolom, memisahkan **hijau palsu**, memakai `NormalisasiAngka` yang sama dengan jalur bermarker supaya dua jalur bisa diadu lurus. Membaca DUA sumber: `raw_measurements` dan `hasil_autoclave` |
| `app/Http/Requests/CalibrationRequest.php` | Slot `sensor_grid.*.ocr`, `indikator_ocr`, `suhu_ruang_ocr` buat lembar grid |
| `app/Http/Requests/AutoclaveStoreRequest.php` | Blok `ocr` bercermin ke jalur nilainya, plus `bacaanMesin()` yang sengaja dipisah dari `dataUkur()` |
| `app/Http/Controllers/Api/CalibrationController.php` | `susunGridEnclosure` menulis `ocr_raw_text`/`ocr_confidence` dan menghitung asal-kamera **per baris**; `simpanAutoclave` menyimpan blok `ocr` di sebelah `lembar` |
| `tests/Feature/AkurasiKameraTest.php` | 6 test |
| `tests/Feature/TebakanMesinGridEnclosureTest.php` | 5 test |
| `tests/Feature/TebakanMesinAutoclaveTest.php` | 4 test |
| `docs/catatan-cabut-ui-pindai.md` | §Ringkas & §2a dikoreksi — lihat §6 |

### Dua hal yang ikut terbetulkan

1. **Grid akhirnya punya pintu kedua.** `catatan-cabut-ui-pindai.md` §1b
   mencatat Enclosure cuma punya SATU gerbang (`input_method`), jadi baris yang
   beneran dari kamera lolos jadi terverifikasi begitu sesinya tercatat manual.
   Sekarang baris yang membawa tebakan mesin dikenali sendiri — dan tanpa
   metadata, perilakunya sama persis seperti sebelumnya.
2. **Autoklaf akhirnya terukur.** Dia nggak pernah menulis `raw_measurements`,
   jadi tanpa pembaca kedua seluruh lembarnya hilang dari pengukuran — dan
   diamnya bakal kebaca sebagai "kameranya bagus di Autoklaf", padahal artinya
   nol data.

### Cakupan per jalur — dan koreksi atas klaim sebelumnya

**Koreksi:** laporan pertama menyebut celahnya cuma "matriks + grid". Itu
**salah**. Ditelusuri lebih jauh, `susunPengukuran()` bercabang ke **lima**
pembangun baris, dan empat di antaranya cuma punya gerbang tingkat-sesi
(`input_method`), tanpa slot metadata per baris:

| Pembangun (server) | Lembar | Status |
|---|---|---|
| `susunPengukuran` — jalur umum, `measurements[].pembacaan` | ~13 | **tersambung** |
| `susunGridEnclosure` | 5 Enclosure | **tersambung** |
| Autoklaf (`simpanAutoclave`, di luar `raw_measurements`) | Autoklaf | **tersambung** |
| `susunPasanganStandarUut` | Thermocouple, Termometer Gelas, Thermohygro, TIDS | **belum** |
| `susunBlokTimbangan` | Timbangan | **belum** |
| `susunBlokWaktu` | Timer/Stopwatch | **belum** |

Ketiga yang belum itu punya jebakan yang sama dan halus: selnya **diisi lewat
`_isiSel`**, jadi tebakannya SUDAH tersimpan di `bacaanMesinSel` — tapi
payloadnya lewat `toSubmissionPasangan()` / blok Timbangan / blok Waktu, bukan
`TitikLembarKerja.ocr`. Jadi tebakannya direkam lalu dibuang diam-diam di
gerbang terakhir. Itu persis kelas kegagalan yang paling mahal di repo ini:
tidak ada error, dan yang hilang cuma buktinya.

Menyambungkannya butuh slot baru di tiga bentuk payload yang berbeda-beda
(`standar`/`uut` berpasangan, empat pembacaan Timbangan, jam/menit/detik
Waktu). Belum dikerjakan — menunggu keputusan pemilik proyek.

### Verifikasi

- API: `php artisan test --filter="OcrMeasurementTest|WorksheetScanTest|AkurasiKameraTest"`
  → **59 test, 58 lulus, 1 skip**. Dijalankan di **SQLite**, bukan MySQL —
  MySQL tidak tersedia di lingkungan kerja ini, jadi gate MySQL di
  `CLAUDE.md` **belum terpenuhi** dan wajib dijalankan ulang sebelum rilis.
- Mobile: **belum dijalankan lokal** — Flutter SDK tidak ada di lingkungan ini.
  Yang memverifikasi `flutter analyze` + `flutter test` di CI (`periksa-pr.yml`).
