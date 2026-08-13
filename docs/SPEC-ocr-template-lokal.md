# SPEC — OCR Template Lokal (pindai lembar kerja tanpa AI berbayar)

Status: **Tahap 1 (fondasi server) selesai & bertes.** Geometri semua template
masih rangka (`terverifikasi: false`), jadi pindai masih ditolak sistem sampai
koordinatnya diukur dari formulir cetak asli.

Pendamping: `SPEC-vision-ai-worksheet-extraction.md` (jalur AI Vision yang lama).
Dua jalur ini hidup berdampingan — bedanya dijelasin di §9.

---

## 0. Batasan yang jadi dasar rancangan

Diminta eksplisit, dan tiap keputusan di bawah bisa dilacak balik ke sini:

1. Nggak boleh pakai Gemini / Claude / OpenAI / Vision API / LLM cloud buat baca
   foto.
2. Nggak boleh ada biaya per pindai.
3. OCR jalan lokal / on-device / server milik sendiri.
4. Foto & data nggak boleh ke pihak ketiga.
5. Angka nggak boleh mendarat di sel yang salah — nggak boleh ngacak urutan
   Repeat, nggak boleh nyampur nilai antar kolom.

Konsekuensi paling penting dari (1)–(4): **server nggak pernah ngolah citra buat
OCR.** Yang baca angka itu HP. Server nerima teks per sel + koordinat + skor mutu
foto, lalu mutusin. Citra cuma disimpen buat audit & bahan dataset, dan
disimpennya di infrastruktur lab sendiri.

---

## 1. Asumsi

| # | Asumsi | Dasarnya |
|---|--------|----------|
| A1 | Lembar kerja diisi TULISAN TANGAN | `config/services.php` nyebut angka tulisan tangan dari lembar ini nyampe sertifikat resmi |
| A2 | Formulirnya tercetak & bentuknya tetap per revisi | tiap profil punya `kode_dokumen`, mis. `SIDIK-FM-CAL-0509_Rev.4` |
| A3 | Formulir bisa dicetak ulang dengan 4 penanda sudut + QR | prasyarat; tanpa ini fitur ini nggak bisa jalan (§10) |
| A4 | HP teknisi Android, bisa CameraX + ML Kit (marker dideteksi sendiri, nggak butuh OpenCV) | aplikasi lapangan sekarang udah Android |
| A5 | Sel kosong itu SAH | `LembarKerjaTemplate` — nggak ada kolom yang nahan tombol kirim |
| A6 | Produksi sekarang di Render plan free (disk sementara) | `render.yaml`; lihat §8 |

---

## 2. Arsitektur

```
┌─ HP TEKNISI (semua pengolahan citra di sini) ──────────────────────────┐
│                                                                        │
│  CameraX ──▶ cek mutu (blur/terang/glare/miring/px-per-sel)            │
│                 │                                                      │
│                 ▼                                                      │
│           deteksi 4 marker + baca QR  ──▶ QR nggak kebaca? BERHENTI    │
│                 │                                                      │
│                 ▼                                                      │
│           homography ──▶ citra rata di ukuran referensi template       │
│                 │            (+ residual reproyeksi, px)               │
│                 ▼                                                      │
│           snap ke garis tabel ──▶ kotak sel                            │
│                 │                                                      │
│                 ▼                                                      │
│           potong PER SEL ──▶ ML Kit baca TIAP CROP, bukan halaman      │
│                 │            (+ kotak teks tiap hasil)                 │
│                 ▼                                                      │
│        payload: teks per sel + kunci + mutu + geometri                 │
└────────────────────────────────┬───────────────────────────────────────┘
                                 │ POST /api/worksheet-scans
┌─ SERVER (nggak nyentuh citra buat OCR) ────────────────────────────────┐
│  WorksheetScanRequest    bentuk & batas atas                           │
│  TemplateLembarKerja     template dari profil alat + geometri          │
│  PemrosesScanLembarKerja 7 tahap penjagaan (§4)                        │
│  NormalisasiAngka        teks → angka, tanpa ngarang                   │
│  ValidasiSel             vonis hijau/kuning/merah/kosong               │
│  worksheet_scans(+cells) audit + dataset ground truth                  │
└────────────────────────────────┬───────────────────────────────────────┘
                                 │ 201 + tabel bervonis warna
                    layar review teknisi (tap sel → crop aslinya)
                                 │
                                 ▼
              POST/PUT /calibrations  ← DI SINI data baru lahir
```

**Kenapa OCR-nya per crop, bukan sehalaman.** OCR halaman penuh ngasih daftar
teks + kotak, lalu ada yang harus nebak teks mana masuk sel mana. Tebakan itu
persis sumber bug "angka pindah baris". Baca per crop bikin pertanyaannya nggak
pernah muncul: satu crop = satu sel, kuncinya udah ditentukan SEBELUM dibaca.

**Kenapa OCR-nya di HP, bukan di server sendiri.** PaddleOCR jauh lebih kuat dari
Tesseract buat foto nyata (Tesseract dirancang buat hasil scan bersih; di foto
lapangan yang agak miring & remang, akurasinya jatuh). Tapi PaddleOCR butuh
~1–2 GB RAM, sementara produksi sekarang di Render plan free (512 MB) — dia nggak
akan muat, dan tiap pindai jadi rebutan CPU sama request lain. ML Kit on-device
gratis, jalan offline (lab pelanggan sering nggak ada sinyal), dan fotonya nggak
pernah keluar dari HP. Kalau nanti lab punya VPS sendiri, PaddleOCR bisa
ditambahin sebagai pembanding TANPA ngubah kontrak: yang dikirim ke server tetap
teks per sel.

**Tulisan tangan.** ML Kit dilatih buat teks cetak. Angka tulisan tangan kebaca
lebih meleset — dan yang paling bahaya, skor keyakinannya tetap tinggi waktu
salah. Makanya `ocr.tulisan_tangan.aktif` bikin sel paling bagus pun mentok
KUNING: keisi otomatis, tapi mata teknisi tetap lewat. Sel hijau baru mungkin ada
kalau nanti ada kolom yang tercetak/dari printer alat.

---

## 3. Kunci sel — inti anti-tertukar

```
kunci = "{tabel_id}|{baris_ke}|{repeat_no}|{field_id}"
        contoh: sebelum_adjustment|1|3|pembacaan
```

- `tabel_id` = `grup ?? tahap`. **Bukan `tahap` doang**: Spectrophotometer punya
  tiga tabel dengan `tahap` sama (`sesudah_adjustment`) yang cuma dibedain
  `grup`. Pakai `tahap` doang bikin tiga tabel itu saling nimpa.
- `titik_ukur` & `standard_id` **bukan bagian kunci** — dia bukti pembanding.
  Kalau HP ngirim nilai yang beda dari template, seluruh pemetaan dibatalin.
  Kalau dijadiin kunci, ketidakcocokannya malah nyamar jadi "kunci nggak ketemu".
- Nggak ada satu tahap pun yang boleh ngandelin urutan array. Dikunci tes:
  urutan kiriman dibalik → hasilnya identik.

**Templatenya diturunin otomatis dari `CalibrationProfile::bentukLembarKerja()`,
bukan ditulis ulang per alat.** Lab ini bakal punya sampai 48 jenis alat; nyalin
bentuk tabel 48 kali sama dengan 48 kesempatan geser satu baris. Alat baru
(profil ke-7, ke-8, …) otomatis kebagian template OCR-nya. Yang tetap manual cuma
geometrinya (§7).

---

## 4. Tujuh tahap penjagaan (`PemrosesScanLembarKerja`)

Urutannya dari yang paling murah & paling menentukan. **Gagal di tahap mana pun =
seluruh lembar ditolak, bukan diisi sebagian.** Lembar yang keisi separuh di
posisi salah jauh lebih mahal dari lembar yang nggak keisi: yang kedua kelihatan,
yang pertama ketahuan sesudah sertifikatnya kekirim.

| # | Tahap | Ditolak kalau | Status |
|---|-------|---------------|--------|
| 1 | Template & versi | QR nggak kebaca, `template_id` beda, versi formulir beda, geometri belum diverifikasi | `template_tidak_dikenali` |
| 2 | Mutu foto | blur < 90, terang < 60 atau > 225, glare > 5%, miring > 8°, tinggi sel < 24 px | `ditolak_kualitas` |
| 3 | Geometri | marker < 3, residual > 2 px, garis tabel nggak ke-snap | `geometri_meragukan` |
| 4 | Jangkar | label Repeat tercetak nggak cocok urutannya | `mapping_gagal` |
| 5 | Pemetaan | kunci asing, kunci dobel, ada sel yang nggak kekirim, titik ukur/standar beda | `mapping_gagal` |
| 6 | Vonis per sel | (nggak nolak lembar — nolak per sel) | — |
| 7 | Sebar antar-Repeat | (nurunin hijau → kuning) | — |

Tahap 4 itu penangkal paling ampuh buat "geser satu baris": penjagaan lain ngukur
geometri, yang ini **baca isinya**. Kalau grid kegeser, label yang kebaca di
posisi baris ke-2 bakal `R3`.

Tahap 7 pakai median + MAD (bukan standar deviasi — yang lagi dicari justru nilai
nyasarnya). Yang nyasar ditandai KUNING, bukan dibuang: sebaran lebar bisa jadi
alat pelanggannya emang nggak stabil, dan itu justru temuan kalibrasi yang
berharga. Mesin nggak berhak mutusin mana anomali alat & mana salah baca; yang
bisa dia lakukan cuma nunjuk.

---

## 5. Aturan angka (`NormalisasiAngka`)

Boleh: buang spasi; ganti karakter yang mustahil ada di sel angka dengan digit
sebentuk (`O→0`, `S→5`, `I→1`, …). Tiap penggantian **dicatat** dan nurunin skor
0,15 — penggantian itu tebakan, bukan bacaan. Lebih dari 2 penggantian = ditolak
(sel yang butuh tiga tebakan buat jadi angka itu bukan angka yang kebaca).

**Nggak boleh: nyisipin atau mindahin koma biar angkanya "masuk akal".** `133659`
di kolom yang nominalnya 1,33659 itu tanda salah baca, bukan izin naruh koma di
posisi yang kelihatannya pas. Dibaca apa adanya, lalu ditolak lewat rentang.
Dikunci tes `test_koma_tidak_pernah_disisipkan`.

Titik vs koma memang ambigu (`1.413` bisa 1,413 atau 1413). Yang dilakukan: SEMUA
tafsiran yang mungkin disusun, lalu disaring pakai bukti template (jumlah desimal
& rentang titik ukurnya). Lolos satu → dipakai. Lolos nol atau lebih dari satu →
`desimal_ambigu`, biar orang yang mutusin.

### Vonis per sel (`ValidasiSel`)

| Warna | Artinya | Yang bikin |
|-------|---------|------------|
| hijau | keisi otomatis, mesin nanggung | conf ≥ 0,85, nggak ada catatan, **dan bukan tulisan tangan** |
| kuning | keisi, mata teknisi wajib lewat | tulisan tangan; ada koreksi karakter; desimal kelebihan; bukan kelipatan resolusi; jauh dari Repeat lain; conf 0,60–0,85 |
| merah | kosong, teknisi ngetik | conf < 0,60; gagal jadi angka; di luar rentang; magnitudo meleset (rasio 0,5–2,0 dari nominal); teks meluber dari sel |
| kosong | sel emang kosong di kertasnya | teks kosong (SAH, bukan kegagalan) |

Ambangnya sengaja **nggak simetris**. Salah baca yang lolos jadi hijau mendarat
di sertifikat terakreditasi tanpa ada yang lihat; sel merah yang sebenarnya
kebaca cuma bikin teknisi ngetik satu angka. Ongkos dua kesalahan itu jauh beda.

---

## 6. Kontrak API (buat mobile)

Semua di grup `auth:sanctum` + `role:admin,teknisi`.

### `GET /api/worksheet-templates`

Daftar SEMUA alat yang dikenal sistem + `siap_pindai`. **Jangan hardcode
daftarnya di APK** — alat baru nambah terus.

```json
{ "data": [ { "template_id": "ph_meter", "versi": 1,
  "kode_dokumen": "SIDIK-FM-CAL-0509_Rev.4", "judul": "Calibration Worksheet - pH Meter",
  "jumlah_sel": 60, "siap_pindai": false, "alasan_belum_siap": "geometri_belum_diverifikasi" } ] }
```

`siap_pindai: false` → **tombol kamera dimatiin** buat alat itu. Lebih baik
teknisi ngetik daripada koordinat rancangan dipakai naruh angka di sertifikat.

### `GET /api/worksheet-templates/{kode}`

Query opsional: `equipment_id` (Conductivity milih satuan dari resolusi alat),
`jumlah_pengulangan` (kalau lembarnya dipangkas). Balikannya: `tabel`, `sel`
(berkunci, lengkap `aturan` tiap sel), `jangkar`, `geometri` (marker, QR, kotak
tiap sel, `ukuran_referensi`, `sumbu_pengulangan`).

### `POST /api/worksheet-scans`

```jsonc
{
  "template_id": "ph_meter", "template_versi": 1,
  "calibration_session_id": 12,            // opsional
  "qr":       { "terbaca": true, "isi": "ph_meter|v1" },
  "geometri": { "marker": [{"id":0,"x":90,"y":90}, …],
                "residual_reproyeksi_px": 0.8, "grid_tersnap": true,
                "ukuran_referensi": {"w":1654,"h":2339} },
  "kualitas": { "blur_laplacian": 240, "kecerahan_rata": 150, "rasio_glare": 0.01,
                "sudut_kemiringan_deg": 1.2, "px_per_sel_tinggi": 48 },
  "perangkat": { "model": "…", "os": "…", "app": "1.4.0", "ocr": "mlkit-v2" },
  "sel": [ { "tabel_id": "sebelum_adjustment", "baris_ke": 1, "repeat_no": 1,
             "field_id": "pembacaan", "teks_mentah": "4,01", "confidence_ocr": 0.97,
             "kotak_teks_di_dalam_sel": true,
             "kotak": {"x":100,"y":200,"w":80,"h":40}, "titik_ukur": 4.0 } ],
  "sel_jangkar": [ { "field_id": "label_repeat", "repeat_no": 1,
                     "teks_mentah": "1", "cocok": true } ],
  "citra": "<file>", "citra_warp": "<file>"   // opsional
}
```

Aturan wajib buat sisi HP:

- **Semua** sel template harus dikirim, termasuk yang kosong (`teks_mentah: ""`).
  Sel yang nggak ikut = seluruh lembar ditolak. Sel yang hilang tanpa suara lebih
  bahaya dari scan yang ditolak.
- `teks_mentah` dikirim **apa adanya**, termasuk yang jelas ngawur. Jangan
  dibersihin di HP — yang mutusin dia angka atau bukan cuma server, dan cuma
  server yang nyimpen jejaknya.
- `kotak_teks_di_dalam_sel` = kotak hasil OCR duduk di dalam poligon sel (dikasih
  margin `ocr.geometri.margin_dalam_sel`). `false` = merah.
- Citra opsional: sinyal lapangan sering lemah, dan nolak hasil pindai gara-gara
  fotonya gagal naik itu ngorbanin kerjaan teknisi demi arsip.

**201** — hasil pindai:

```jsonc
{ "scan_id": 7, "status": "perlu_review",
  "template": {"id":"ph_meter","versi":1,"kode_dokumen":"…"},
  "pipeline_versi": "ocr-1.0.0", "aturan_versi": "val-1.0.0",
  "ringkasan": {"total_sel":60,"hijau":0,"kuning":58,"merah":1,"kosong":1},
  "tabel": [ /* bentuk baris lembar kerja, siap digambar */ ],
  "boleh_auto_isi": false,   // ada merah → tombol simpan ditahan
  "wajib_dicek": true }
```

`boleh_auto_isi` & `wajib_dicek` **dihitung di server**, biar aturannya nggak
beda-beda antar versi APK.

**422** — ditolak: `{ scan_id, status, message, fallback_manual: true }`.
`message` selalu nyuruh ngapain ("Fotonya buram. Tahan HP lebih diam, lalu foto
ulang."), bukan cuma bilang gagal — teknisi lagi berdiri di depan alat pelanggan.
`fallback_manual` nandain jalur ngetik tetap kebuka; gagal pindai nggak boleh
bikin buntu.

### `GET /api/worksheet-scans/{scan}` · `GET …/{scan}/sel/{kunci}/crop`

`crop` balikin JPEG potongan sel dari citra yang udah diratain. Ini yang bikin
layar review ada gunanya: teknisi bandingin angka hasil baca sama coretan aslinya
di layar yang sama. Kalau dia harus buka kertasnya lagi, mending ngetik dari awal.

### `POST /api/worksheet-scans/{scan}/koreksi`

```json
{ "koreksi": [ { "kunci": "sebelum_adjustment|1|1|pembacaan", "nilai_final": 4.01 } ] }
```

Dipanggil pas teknisi nekan simpan di layar review — kirim **semua** sel yang dia
konfirmasi/betulin, bukan cuma yang diubah. Ini satu-satunya sumber ground truth
(§7).

---

## 7. Akurasi, dataset, & feedback loop

**Ground truth cuma dari koreksi yang lewat mata orang.** `nilai_final` cuma
keisi lewat endpoint koreksi. Tebakan mentah nggak pernah masuk — ngumpulin
dataset dari tebakan sendiri bikin mesin belajar dari kesalahannya sendiri. Nggak
ada retraining otomatis: dataset dipakai buat NGUKUR & nyetel ambang, dan tiap
perubahan ambang naikin `aturan_versi`.

```
php artisan ocr:akurasi --template=ph_meter --hari=30
```

Ngeluarin akurasi **per kolom**, bukan per halaman. "Akurasi 94%" nggak berarti
apa-apa kalau yang 6% itu selalu kolom suhu di Repeat 5.

Angka yang paling penting: **HIJAU PALSU** — sel yang keisi otomatis padahal
salah. Satu hijau palsu lebih gawat dari lima puluh merah palsu, karena nggak ada
yang lihat sampai sertifikatnya terbit. Ditampilin satu-satu, nggak dilebur ke
rata-rata.

Versi yang ikut kesimpen tiap pindai: `pipeline_versi`, `aturan_versi`,
`template_versi`. Tanpa itu, hasil lama nggak bisa dibandingin sama yang baru
waktu ambangnya disetel ulang.

**Scan yang DITOLAK tetap disimpen**, lengkap payload mentahnya. Justru yang
ditolak yang paling berguna waktu ambangnya mau disetel — tanpa itu, satu-satunya
bukti yang tersisa adalah teknisi yang bilang "kameranya nggak jalan".

Log terstruktur: `ocr.scan_ditolak` (warning, lengkap alasan + mutu + geometri) &
`ocr.scan_banyak_merah` (info). Yang kedua penting: kalau cuma yang ditolak yang
kelihatan, pindai "berhasil" dengan separuh sel merah lewat tanpa jejak — padahal
itu yang paling sering bikin teknisi diam-diam balik ngetik manual.

### Tes yang ngunci anti-tertukar (`tests/Feature/WorksheetScanTest.php`)

- urutan kiriman dibalik → nilai per kunci identik
- nilai Before nggak bocor ke After (dua tabel bentuknya sama persis)
- tiap sel template dapat **tepat satu** baris, nggak ada kunci dobel
- kunci asing / kunci dobel / sel kurang / titik ukur beda → tolak seluruh lembar
- versi formulir beda → tolak
- label baris nggak cocok → tolak
- geometri belum diverifikasi → tolak

Tambahan penjaga di tingkat DB: unique `(worksheet_scan_id, kunci)`. Kalau ada
bug pemetaan yang lolos semua penjagaan aplikasi, dia mentok di sini dan gagal
keras — bukan diam-diam nimpa.

---

## 8. Risiko & mitigasi

| Risiko | Mitigasi |
|--------|----------|
| Koordinat geometri meleset → seluruh grid geser | `terverifikasi: false` sampai diukur dari formulir asli; residual < 2 px; snap garis; jangkar label baris |
| Formulir dicetak ulang dengan skala beda | versi formulir di QR + dicek; snap ke garis tabel, bukan cuma koordinat |
| Tulisan tangan kebaca salah tapi skornya tinggi | tulisan tangan nggak pernah hijau |
| Teknisi nge-skip layar review | sel merah nahan `boleh_auto_isi`; `wajib_dicek` dihitung server |
| **Disk Render free itu sementara** — citra audit ilang tiap deploy | produksi WAJIB nyetel `OCR_DISK` ke object storage milik sendiri (lihat `config/ocr.php`) |
| Model OCR di HP diganti versi baru diam-diam | `perangkat.ocr` ikut kesimpen tiap pindai; akurasi bisa dipecah per versi |
| Alat baru kelewat nggak kebagian template | template diturunin dari profil, jadi otomatis ikut; yang ketinggalan cuma geometri, dan itu ketahan `siap_pindai: false` |

---

## 9. Hubungannya sama jalur AI Vision yang lama

`POST /api/raw-measurements/extract-from-photo` (Gemini/Anthropic) **masih ada dan
nggak disentuh**. Bedanya:

| | AI Vision | OCR Template Lokal |
|---|---|---|
| Foto keluar ke pihak ketiga | ya | **nggak** |
| Biaya per pindai | ada | **nggak ada** |
| Butuh sinyal | ya | nggak (OCR di HP) |
| Cara naruh angka | model yang nyusun bentuk tabel | kunci eksplisit dari template |
| Butuh formulir khusus | nggak | **ya** (marker + QR) |

**Rekomendasi**: begitu geometri satu alat terverifikasi & akurasinya keukur,
setel `VISION_DRIVER=off` buat alat itu. Selama formulir bermarker belum dicetak,
jalur AI Vision masih satu-satunya yang jalan — jadi jangan dimatiin sekarang.
Keputusan matiin totalnya ada di lab, bukan di kode.

---

## 10. Tahapan

- **Tahap 1 — fondasi server. SELESAI.** Template dari profil (6 alat, otomatis
  buat alat ke-7 dst), normalisasi, validasi berlapis, pemetaan berkunci,
  `POST /api/worksheet-scans`, endpoint crop, koreksi, tabel audit + dataset,
  logging, 59 tes (SQLite & MySQL).
- **Tahap 2 — formulir & geometri.** Arahnya dibalik: koordinat JSON yang jadi
  sumber, kertasnya yang mengikuti. `php artisan ocr:rangka-geometri {kode}`
  bikin geometrinya, `php artisan ocr:cetak-lembar {kode}` mencetak lembar yang
  tiap kotaknya digambar persis di `x/y/w/h` itu — jadi nggak ada acara ngukur
  cetakan pakai penggaris. Markernya kotak hitam pejal berlubang putih (bukan
  ArUco) + QR `{template_id}|v{versi}`. Tetap adu ke ≥ 20 foto nyata sebelum
  `terverifikasi: true`.
- **Tahap 3 — sisi HP.** CameraX + deteksi marker sendiri (ambang gelap + titik
  berat gumpalan, homography, snap garis, potong sel) + ML Kit per crop +
  overlay indikator (lurus/terang/fokus/4 sudut). Nggak ada pemindaian
  terus-menerus — teknisi yang mutusin kapan jepret.
- **Tahap 4 — layar review.** Tabel berwarna, tap sel → crop aslinya, teknisi
  cuma betulin sel bermasalah, simpan → endpoint koreksi → `POST /calibrations`.
- **Tahap 5 — sebar ke alat lain.** Ulang tahap 2 per alat. Kode servernya nggak
  perlu disentuh. Gerbang rilis: akurasi per kolom alat baru keukur, DAN akurasi
  alat lama nggak turun.

## 11. Yang masih perlu keputusan lab

1. **Formulir boleh dicetak ulang dengan marker + QR?** Ini prasyarat mutlak.
   Kalau nggak boleh, seluruh pendekatan template gugur dan fitur ini nggak bisa
   lanjut ke tahap 2.
2. **Ukuran & posisi marker** nabrak kop surat / kolom tanda tangan nggak?
3. Formulir lama yang terlanjur kecetak (tanpa marker) mau diapain — tetap
   ngetik manual, atau ditempelin stiker QR?
4. Object storage buat citra audit: pakai apa, dan retensinya berapa lama?
   Default sekarang 90 hari (citra) & 365 hari (crop).
