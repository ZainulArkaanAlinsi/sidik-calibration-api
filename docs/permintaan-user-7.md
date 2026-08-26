# Tujuh permintaan pemilik proyek — daftar resmi

**Kenapa berkas ini ada.** Tujuh permintaan ini cuma pernah hidup di percakapan. Percakapan
dipadatkan, container sesi direklaim, sesi baru mulai dari nol — dan waktu itu terjadi, yang
hilang bukan cuma detailnya, tapi *mana yang sudah dikerjakan dan mana yang belum*. Sekali itu
terjadi dan akibatnya nyata: pemilik proyek mengunduh APK jam 02:48 lalu mencari permintaan 1
dan 5 di layar, padahal dua-duanya belum pernah dibangun sebaris pun.

Jadi berkas ini yang jadi pegangan, bukan ingatan. **Perbarui kolom Status tiap kali ada yang
berubah**, di commit yang sama dengan perubahannya.

Urutan di bawah = urutan yang ditulis pemilik proyek, BUKAN urutan pengerjaan. Urutan
pengerjaan ada di §Gelombang.

---

## 1. Pilih alat berjenjang + kelola daftar alat

Waktu teknisi mau mulai, alatnya banyak. Jadi dibuat berjenjang: **pilih jenis dulu**
(mis. Suhu), lalu **alat-alat di dalamnya** yang muncul (mis. `Temperature Indicator tanpa
Sensor`, `Temperatur Indikator dengan Sensor`). Begitu alatnya dipilih, **sistem sendiri yang
tahu lembar kerjanya yang mana** — teknisi tidak memilih lembar.

Dua hal lagi yang menempel di permintaan ini:

- **Daftar alat tersaring ke lembar kerjanya** — ✅ **BERES di server** (26 Agt 2026):
  `GET /api/equipments?profil=<kode>`. Sebelumnya penyaringnya cuma `?category=`, dan kategori
  jauh lebih kasar: "Suhu dan Kelembapan" memuat **11 jenis alat** yang memetakan ke **tujuh**
  lembar berbeda, jadi lembar TITS ikut menyodorkan Oven, Bath, Inkubator, Furnace, Refrigerator,
  dan TIDS. **Sisi mobile masih harus memanggil parameternya** — tanpa itu perilakunya tetap
  seperti sebelumnya.
- **Admin bisa mengedit nama-nama alat itu** — bebas menamai apa saja untuk daftar pilih alat.
- **Kalau alatnya tidak ada di daftar, teknisi bisa menambahkan nama alat itu sendiri,
  TANPA harus minta ke admin.** Ini dipertegas pemilik proyek: langsung bisa dipakai, tidak
  menunggu persetujuan.

> **Bahaya yang menempel di sini — jangan dihapus dari catatan.** Nama alat baru tidak punya
> baris CMC. Yang menentukan angka ketidakpastian di sertifikat adalah pencocokan titik ukur ke
> baris CMC; baris tanpa rentang tidak akan pernah cocok, sesinya jatuh ke jalur generik, dan
> **U yang terbit lebih kecil daripada yang diakreditasi — tanpa satu pun error.** Teknisi tetap
> harus bisa kerja (itu yang diminta); yang tidak boleh adalah angkanya terbit seolah-olah
> terakreditasi. Sesi seperti itu wajib menandai dirinya sendiri.

## 2. Lokasi kalibrasi: Inlab vs Insitu

- **Inlab** → dipilih dari ruangan lab PT Sidik yang terdaftar (nama ruangannya didaftarkan
  pemilik proyek).
- **Insitu** → di luar, dan **di bawahnya ada kolom teks bebas** untuk nama perusahaan/tempatnya.

Berlaku di **semua** lembar kerja, bukan cuma sebagian.

## 3. Cabut UI pindai lembar kerja

Hilangkan UI "pindai lembar kerja" dari semua lembar kerja — **untuk sekarang**. Bukan selamanya:
permintaan 7 akan membangunnya lagi.

## 4. Layar Draf tersendiri

Layar khusus draf, seperti perpustakaan: **dikelompokkan per alat**, ada namanya, ada kapan
disimpannya. Gampang dicari, rapi.

## 5. TITS & TIDS satu pintu

Khusus Temperatur Indikator **tanpa sensor** dan **dengan sensor**: sebelum masuk ke lembarnya,
muncul **dua pilihan** — tanpa sensor / dengan sensor.

**Pilihan itu logika utamanya.** Semua isi lembar kerjanya mengikuti apa yang dipilih, dan harus
konsisten: kalau dipilih "dengan sensor", seluruhnya versi dengan sensor; begitu juga sebaliknya.

## 6. Lembar kerja ikut tiga PDF resmi

Tampilan, cara pakai, dan animasi lembar kerja mengikuti tiga PDF yang dikirim:

| Dokumen | Berkas |
|---|---|
| TITS | `SIDIK-FM-CAL-0505 Rev.3` |
| Enclosure | `SIDIK-FM-CAL-0504 Rev.3` |
| TIDS | `SIDIK-FM-CAL-0506 Rev.4` |

## 7. Scan Tabel (kamera + OCR)

Spesifikasi lengkap dari pemilik proyek, perkiraannya sendiri ~14,5 hari kerja: OCR di HP +
AI cloud, tabel staging `ocr_scans` / `ocr_scan_cells`, layar review per sel.

**§12 spesifikasi itu mengikat:** *"Tunjukkan rencana file yang akan dibuat/diubah lebih dulu,
tunggu saya setujui, baru eksekusi."* Dan: *"Bahasa komentar kode dan pesan commit:
Bahasa Indonesia."*

> **Temuan yang mengubah ukurannya:** sebagian besar spec ini **sudah terbangun dengan nama
> lain** — `worksheet_scans` / `worksheet_scan_cells`, ML Kit di HP, `WorksheetVisionExtractor`,
> `pindai_review_screen.dart`, `ValidasiSel.php`, pipeline 7 tahap. Membuat tabel `ocr_scans`
> baru berarti dua tabel staging yang saling menyaingi selamanya. Petakan dulu, jangan
> membangun ulang.

---

## 8. Revisi jangan menghapus satu tabel penuh

Ditambahkan pemilik proyek 26 Agt 2026, sesudah menolak satu sesi Inkubator:

> *"misal kalo ada bagian yang di revisi itu jangan di hapus full satu table tapi kasih aja
> misal bagian yang merah atau gimana lah intinya jangan asal hapus... perhatikan logic nya
> serta ui nya"*

Dua bagian, dan **dugaan awal saya soal bagian A keliru** — dicatat di sini supaya tidak
diulang: `terapkanPembacaan()` di HP **sudah ada, sudah dipanggil**
(`lembar_kerja_screen.dart:411`), mencocokkan per nilai titik ukur, tidak menimpa sel yang
sudah diketik, dan bertest penuh. Untuk **sepuluh lembar bertabel datar** pemulihan revisi
sudah jalan sejak lama. Yang benar-benar bolong jauh lebih sempit.

| | Isi | Status |
|---|---|---|
| **A** | Sesi yang dikembalikan pulang dengan isinya utuh | **grid Enclosure ditutup** — lihat di bawah |
| **B** | Yang salah ditandai per SEL, bukan per tabel | **jalan** — `KodeSelRevisi` + `TandaSel.revisi` |

**A — yang bolong cuma grid Enclosure.** Lembar Enclosure tidak punya kolom
`pembacaan`/`suhu` yang dicari jalur datar; angkanya duduk di sel *(set point, sensor ke-N,
repeat ke-M)*, dan yang membedakan satu baris dari yang lain justru `sensor_ke`. Barisnya
**tersimpan lengkap sejak ingest** — yang hilang cuma jalan pulangnya:
`CalibrationResource` tidak pernah mengirim `sensor_ke`, `peran_sensor`, maupun `channel`.
Akibatnya teknisi yang lembarnya dikembalikan mengetik ulang 9 termokopel × 5 repeat × tiap
set point — **180 sel** untuk sesi Inkubator 4 set point, termasuk angka yang sudah benar.

**B — mekanismenya sudah ada, tinggal sumbernya.** Penanda sel kuning
(`selRendahKeyakinan` + `kunciSel`) sudah dipakai jalur OCR sejak lama. Yang ditambah cuma
himpunan sejajar yang diisi dari kode sel di `revisi_field`, plus warna kedua
(`TandaSel.revisi`, merah) yang menang atas kuning: yang satu tebakan mesin, yang satu
keputusan orang yang akan menandatangani sertifikatnya.

Bentuk kodenya `sel:<tahap>:<titik_ukur>:<kolom>:<pembacaan_ke>` — muat di `max:64` yang
sudah berlaku, jadi **tidak ada migrasi**.

---

## 9. Sesi Enclosure harus bisa bersertifikat, dan layarnya berhenti berisik

Pemeriksaan 26 Agt 2026 (31 dugaan diuji, 11 terkonfirmasi) atas sesi Inkubator yang ditolak
pemilik lab. 25 baris "Butuh konfirmasi" di layar itu **satu sebab plus kebisingan**.

| Kode | Isi | Status |
|---|---|---|
| **A** | Nggak ada satu pun jalan mengisi kalibrator sesi Enclosure | **BERES** — server menurunkannya dari baris yang dicentang |
| **B** | Simpan sebagian menghapus `standard_id` & `tanggal_terima` senyap | **BERES** — dua-duanya cuma ditulis kalau dikirim |
| **C** | Pencarian standar nggak disaring per lab | **Enclosure BERES**; 12 profil lain **masih terbuka** |
| **D** | Baris Suhu Ruang diadu ke rentang chamber — 20 palsu/sesi | **BERES** — pitanya sendiri, 5–45 °C |
| **E** | Pesan "titik tidak terhitung" menebak tiga sebab | **BERES** — sebabnya ditanya ke profil |
| **F** | TITS Measure: 25 tuduhan salah ketik per sesi | **BERES** — TITS nggak diadu ke `equipments.resolusi` |
| **G** | Peringatan grid nggak nyebut baris mana | **BERES** — perannya ikut di label |
| **H** | Komentar "6 dari 17 lembar tanpa vonis" (sebenarnya 12) | belum |

### Yang TIDAK diubah, dan kenapa

- **`equipments.resolusi` TITS tetap `0,1`.** Angka itu kecetak di sertifikat dan ikut ngitung
  budget ketidakpastian (`Z21`); menggesernya ke `0,01` menggeser U95 yang sekarang cocok dengan
  Excel lab sampai digit terakhir. Yang dimatikan pemeriksanya, bukan datanya.
- **Toleransi Enclosure tetap NULL.** Master enclosure memang nggak punya batas keberterimaan.
  Sudah dibuktikan nggak memblokir apa pun: sesi Oven `2406.25.AI` bertoleransi NULL menghasilkan
  3 titik terhitung.
- **Baris Victor 14+ tetap tercetak** sebagai baris tidak terdaftar.

### Yang MASIH terbuka

- **C di 12 profil lain.** Polanya (`Standard::query()->whereNull('parameter_kondisi')` tanpa
  saringan organisasi) ada di Refractometer, Spectrophotometer ×2, Viscometer, TITS, TIDS,
  Conductivity, DO, Chlorine, Autoclave, Turbidimeter, `CalibrationProfile`, dan
  `LembarKerjaTemplate`. **Wajib beres sebelum lab kedua onboarding** — begitu itu terjadi,
  teknisi lab kedua nggak bisa menyimpan sesi sama sekali. Hari ini database masih satu
  organisasi, jadi belum ada yang kena.
- **Rentang inkubator 30–300 °C** perlu dicek ke spesifikasi unit fisiknya — angka itu lebih
  mirip oven. Sesudah D, dia nggak lagi memunculkan peringatan palsu, jadi ini bukan lagi
  penghalang; tetap perlu dibetulkan karena rentang ukur ikut tercetak.

---

## 10. Alat baru bisa didaftarkan dari lembar kerja

Pemilik proyek, 26 Agt 2026, sambil menunjukkan lembar **Bath** yang kotak "Pilih alat"-nya
berbunyi *"Belum ada alat."*:

> *"kalo semisal nya belum ada alat maka bisa langsung dari situ teknisi bikin sendiri…
> volume dan Calibration Methode itu nanti di isi nya manual… kalo udah pernah di daftarkan
> alat nya maka bakal ke isi otomatis"*

| Isi | Status |
|---|---|
| Lembar tanpa alat = jalan buntu | **BERES** — tombol "Alat baru", kategori & jenis sudah terisi dari lembar |
| `calibration_method_id` cuma bisa diisi admin | **BERES** — pindah jadi hak teknisi |
| Volume enclosure | sudah manual sejak awal — dihitung dari P×L×T / π·r²·t yang diketik teknisi |

**Kenapa buntunya lahir.** Dropdown "Pilih alat" disaring ke lembar yang lagi dibuka (26 Agt,
permintaan sebelumnya) — dan saringan itu benar: sebelum ada, teknisi yang membuka lembar
Refrigerator disodori SELURUH alat lab, dan salah pilih di situ tidak menghasilkan error di mana
pun. Sesinya tersimpan, lalu dihitung pakai aturan alat lain. **Yang ditambah jalan keluarnya,
bukan saringannya yang dilepas.**

Kategori & nama kemampuan dikirim **server** (`alat_baru` di bentuk lembar), bukan dipetakan di
HP: nama kemampuan itu satu-satunya kunci yang dipakai registry buat memilih profil, dan daftar
tandingan di sisi HP pasti ketinggalan begitu alat baru masuk. Alat yang jenisnya meleset satu
huruf jatuh ke form generik — persis masalah yang saringan tadi mau cegah.

### Urutan lembar suhu

Diperiksa kedelapan lembar kategori suhu. Yang benar-benar beda dan aman diseragamkan **satu**:
**TITS menaruh dua kotak tanggal di paling atas** sementara tujuh lembar lain menaruhnya di
bawah blok identitas. Sudah disamakan.

Yang **TIDAK** diseragamkan, dan alasannya:

- **TIDS menaruh 19 field di `identitas_alat`** (tujuh lembar lain: 10) — kondisi lingkungan,
  lokasi, dan metode ikut di blok itu. Pernah dicoba dipecah jadi `data_kalibrasi` +
  `kondisi_lingkungan` seperti lembar Enclosure; **dibatalkan**: `TidsLembarKerjaTest` menjaga
  urutan bagian dengan alasan *"ngikut urutan kertasnya dibaca dari atas"*, dan pemecahan itu
  memindahkan kotak yang di `SIDIK-FM-CAL-0506 Rev.4` tercetak di ATAS jadi di bawah
  `usage_check`. Buat lab terakreditasi, lembar kerja yang urutan bacanya beda dari formulir
  terkendali itu bukan soal rapi-rapi.
- **Autoclave** membuka dengan `informasi_umum` + `kondisi_lokasi` lalu `identitas_alat` di urutan
  ketiga. Formulirnya memang begitu.

Kalau pemilik lab memutuskan keseragaman layar lebih penting daripada kesamaan urutan dengan
kertas, dua-duanya bisa diseragamkan — tapi itu keputusan atas dokumen terkendali, bukan
keputusan teknis.

## 11. Tiga alat suhu baru — Thermocouple, Termometer Gelas, Thermohygrometer

Ditambahkan pemilik proyek 26 Agt 2026 bersama tiga workbook master ber-password:

> *"ada 3 alat itu parhatikan buat kanyak biasa nya serta olah data nya … sekrang
> buatkan bagian backendnya dulu dan juga tolong itu bagian kemera nya juga harus
> bisa juga"*

| | Isi | Status |
|---|---|---|
| **A** | Backend tiga alat (profil, lembar kerja, jalur simpan) | **BERES** — alat ke-18, 19, 20 |
| **B** | Olah data (koreksi + budget U95) sesuai master | **BERES** — cocok sampai digit terakhir, dijaga `Suhu3AlatMasterTest` |
| **C** | Bagian kamera (pindai lembar kerja) | **BERES di server** — 20/20 lembar punya berkas geometri; `terverifikasi: false` menunggu F1 |
| **D** | Excel → CSV | **BERES** — 43 sheet di `sidik-calibration-mobile/Project-PT-Sidik/suhu CSV` |
| **E** | Sisi mobile (layar lembar kerja) | **belum** — lihat `docs/perintah-frontend-suhu-3alat.md` |

**Ketiganya baris lampiran akreditasi LK-285-IDN yang selama ini kosong:** no. 5
Thermocouple, no. 4 Termometer Gelas, no. 11 Thermohygrometer. Baris CMC-nya
sudah ter-seed sejak dulu — yang belum ada cuma profil & mesin hitungnya.

### Bentuk lembarnya BEDA dari 17 alat sebelumnya

Ketiganya membaca **dua deret per titik** — probe standar dan UUT dicelup
bersamaan lalu dibaca bergantian tiap 10 detik. Jadi nilai standar itu **data
sesi**, bukan konstanta dari master `standards` seperti buffer pH 4,01. Jalur
datar `measurements[i].pembacaan` cuma punya tempat buat satu deret, jadi
ketiganya lewat jalur sendiri (`butuhPasanganStandarUut()` →
`susunPasanganStandarUut()`), memakai sumbu `peran_sensor` yang sudah ada sejak
Enclosure. **Nol kolom baru di `raw_measurements`.**

### Yang nyaris salah, dan pantas dicatat

Workbook Thermocouple memuat **persis keempat sheet yang selama ini disebut
hilang untuk TIDS** — `PERHITUNGAN U95%`, `Variasi axial Dryblok A`, `Variasi
axial Dryblok B`, `stdev drywell` — lengkap dengan dryblock Isotech & Techne yang
sebelumnya tidak pernah muncul di repo ini. Dan `PERHITUNGAN U95%!D6` menulis
persis: *"Temperature indikator dengan sensor"*.

Yang membantahnya angka, bukan label: tabel CMC workbook itu berbunyi **0,84 /
1,5 / 3,3 °C** — baris **no. 5 Thermocouple**, bukan **no. 2 TIDS** yang berbunyi
0,86 / 1,4 / 3,1 °C. `D6` itu sisa salinan dari master TIDS.

**Jadi K2 TETAP TERBUKA.** `TidsProfile` tidak disentuh, blokir U95 TIDS tetap
berdiri, `TidsU95TidakBocorTest` tetap hijau.

### Angka yang dicocokkan ke master

| Alat | Sesi master | U95 terbit | Sumber |
|---|---|---|---|
| Thermocouple | `0513-CAL-1124` | **0,84 °C** | lantai CMC (hitungan 0,7686) |
| Termometer Gelas | `0135-CAL-125` | **1,1174 °C** | hitungan budget (CMC 0,58) |
| Thermohygro suhu | `0312-CAL-624` | **1,9788 °C** | hitungan budget (CMC 1,7) |
| Thermohygro RH | idem, 2 chamber | **4,8 %RH** | lantai CMC (hitungan 4,334 & 3,327) |

Tiap kolom `Standard Reading` / `Unit Under Test` / `Correction`, tiap `ui`
komponen budget, `Uc`, dan `v_eff` cocok dalam 5·10⁻⁶.

### Penyimpangan master yang SENGAJA ditiru

Ketiganya melahirkan catatan audit tiap sesi yang menyebut berapa hasilnya kalau
dibetulkan — yang memutuskan manajer teknis lab, bukan diam-diam kode.

1. **Thermocouple:** budget sembilan komponen **tanpa keterulangan**, walau
   STDEV-nya dihitung & dipajang di `M23`.
2. **Gelas:** keterulangan STANDAR dibagi **5**, bukan √5 — sementara baris
   keterulangan UUT tepat di atasnya dibagi √5. Dibetulkan, U95 1,1174 → 1,1268.
3. **Thermohygro:** delapan baris memakai `U = N/SQRT(Q)` padahal `Q` sudah
   berisi pembaginya (kelas yang sama dengan `PEMBAGI_AC_PICKUP` TITS), dan baris
   drift budget GEA justru tidak. Tiga perlakuan, satu komponen, satu sheet.

Semuanya beserta enam butir lain ada di `docs/pertanyaan-lab-suhu-3alat.md`.

### Yang TIDAK ditiru: sel kosong dibaca nol

Tiap VLOOKUP master dibungkus `IFNA(…,"")`, jadi kombinasi yang tidak ada di
tabel pulang KOSONG dan kosong ikut dijumlah sebagai nol — sertifikat terbit
dengan koreksi yang hilang, tanpa error. Di sini titik seperti itu **diblokir
dengan alasan yang kebaca**. Bahayanya nyata: tabel Yokogawa Thermocouple datang
dari cache tautan luar yang memang berlubang (butir 8 dokumen pertanyaan).

### Satu bug yang ditangkap penjaga waktu dikerjakan

`Hydrometer` sempat didaftarkan sebagai alias Thermohygro — kelihatan
sekeluarga, ternyata alat **DENSITAS**. Ditangkap
`ProfilDariNamaAlatTest::test_nama_alat_generik_balik_null` sebelum mendarat.
Kalau lolos: teknisi mengisi tabel suhu & %RH untuk alat yang mengukur berat
jenis, dan U95-nya terbit berlantai CMC kelembapan 4,8 %RH. Nol error di
sepanjang jalur itu.

---

## Keputusan yang SUDAH diambil

Jangan ditanyakan ulang.

| Kode | Keputusan | Dari |
|---|---|---|
| K3 | Alat tambahan teknisi **langsung bisa dipakai**, tidak menunggu persetujuan admin | pemilik proyek, eksplisit |
| K4 | Nama alat baru **masuk master**, ditandai asalnya, supaya bisa dipakai ulang | pemilik proyek |
| K6 | **Dua** tombol kamera dicabut — `PINDAI LEMBAR KERJA` dan `FOTO TABEL INI` | disetujui lewat "gas G0" |
| K7 | UI pindai **disembunyikan di balik saklar**, bukan dihapus (`--dart-define=PINDAI_LEMBAR=true`) | idem |
| — | Isi Excel master dianggap benar & aman; tidak perlu ditanyakan ulang ke lab | pemilik proyek |
| **S1** | **UI pindai DINYALAKAN lagi** (25 Agt 2026) — ini MEMBALIK permintaan 3, yang dulu minta UI pindai dicabut "untuk sekarang". Saklarnya tetap ada supaya bisa dimatikan lagi tanpa ganti kode | pemilik proyek |
| **S2** | Pakai tabel `worksheet_scans`/`worksheet_scan_cells` yang sudah ada. **Tidak** membuat `ocr_scans` baru | pemilik proyek |
| **S3** | **SEMUA lembar bisa dipindai** (25 Agt 2026) — bukan cuma Enclosure, bukan cuma yang kimia. Kesembilan berkas geometri yang kurang sudah dibuat, jadi **17/17 punya template** | pemilik proyek |

## Yang MASIH menunggu jawaban

| Kode | Pertanyaan | Menahan apa |
|---|---|---|
| K1 | TIDS: 5 UUT jadi 1 sesi, atau 5 sesi terpisah? | 70% ukuran pekerjaan TIDS |
| K2 | Workbook Excel TIDS — kapan dari lab? | **Blocker mutlak** budget ketidakpastian TIDS |
| K8 | Inlab: ruangan wajib dipilih atau boleh kosong? | Kalau wajib penuh, semua APK lama ditolak 422 |
| K10 | Layar Draf: pintu masuknya di mana; admin boleh lihat draf teknisi lain? | Layar Draf |
| K11 | Perlu tombol hapus draf? | `DELETE /api/calibrations/{id}` belum ada sama sekali |
| **F1** | **Satu foto lembar cetak yang sudah diisi tangan**, dari lembar mana saja | `terverifikasi: true` di **11 dari 17** berkas geometri. Ini bukan pertanyaan, ini kiriman — dan bukan sesuatu yang bisa dikerjakan dari sini |

### F1 — kenapa satu foto menahan sebelas lembar

Koordinat di berkas geometri **eksak menurut definisi**: `ocr:cetak-lembar` menggambar kertasnya
DARI koordinat itu, jadi kotaknya nggak mungkin meleset dari yang tercetak. Yang belum pernah
diuji sekali pun bagian sesudahnya — rantai **kamera → warp perspektif → potong sel** — diadu ke
kertas yang beneran dicetak, difoto miring, di bawah lampu lab.

`terverifikasi: true` artinya rantai itu **sudah dibuktikan**, bukan "koordinatnya sudah benar".
Jadi cuma manusia yang boleh menyetelnya, dan bukti yang dibutuhkan cuma satu: satu foto lembar
cetak yang sudah diisi. Enam lembar kimia sudah punya bukti itu; sebelas sisanya belum.

Sampai foto itu ada, kesebelas lembar tombol pindainya digambar **MATI berikut alasannya** —
bukan hilang, bukan nyala dengan koordinat karangan.

---

## Gelombang & status

Urutannya ditentukan berkas yang bertabrakan, bukan selera — G1 dan G3 sama-sama menyentuh 12
berkas profil.

| Gel. | Isi | Status |
|---|---|---|
| G0 | Sertifikat Insitu, draf tanpa tanggal, ruangan ke-16, cabut UI pindai (perm. 3) | **TERKIRIM** — di `main`, ada di APK **v1.0.42** |
| G1 | Profil dari server (perm. 1a) + lokasi Inlab/Insitu (perm. 2) | **TERKIRIM** (v1.0.42) — perm. 2 jalan di **17/17** profil, dijaga `SemuaProfilLembarKerjaTest` (88 test = 17×5 aturan per-profil + 3 aturan lintas-profil) |
| G2 | Kelola daftar alat (perm. 1b) + layar Draf (perm. 4) | 1b jalan; perm. 4 **TERKIRIM** (v1.0.42). K10/K11 masih menahan pintu masuk & tombol hapus |
| G3 | Lembar kerja ikut PDF (perm. 6) | **sebagian TERKIRIM** (v1.0.42) — TITS `0505 Rev.3` & Enclosure `0504 Rev.3` (kepala lembar, `equipment_id`, blok dimensi + volume, nomor formulir, baris Suhu Ruang) sudah ikut PDF. **TIDS `0506 Rev.4` belum dibandingkan field-per-field** |
| G6 | Kolom "Environmental Meter Used" hidup di **17/17** lembar | **BERES di server** (25 Agt 2026) — TITS, TIDS & kelima Enclosure dropdown-nya nggak pernah diisi siapa pun, dan TIDS jalur cadangannya (`baris_thermohygro`) juga mati. Dijaga `ThermohygroSemuaLembarTest` + penjaga golongan sumber master. **Belum diadu ke layar HP**: yang dibuktikan responsnya sudah berisi, bukan bahwa cabang teks matinya sudah nggak kepakai |
| G4 | TIDS (perm. 5) | bentuk lembar kerja jalan; **budget ketidakpastian TERBLOKIR K2**. Blokirnya sekarang dijaga `TidsU95TidakBocorTest` — dibuktikan merah dengan melepas blokirnya (U95 langsung lahir dari lantai CMC 0,86 °C) |
| G5 | Scan Tabel (perm. 7) — **perm. 3 DIBATALKAN oleh S1, UI pindai nyala lagi** | **S1/S2/S3 semuanya sudah dijawab**, dan kodenya sudah mendarat. Peta: `docs/peta-permintaan-7-scan-tabel.md`. Sebagian besar spec memang SUDAH terbangun sebelum permintaan 7 ditulis (`worksheet_scans`, pipeline 7 tahap, ML Kit, layar review). Yang ditambah: 9 berkas geometri baru (jadi **17/17**), gerbang bentuk kertas buat jalur foto AI, dan alasan pindai jadi kalimat. **Sisa satu-satunya: F1** — nunggu satu foto, bukan nunggu kode |
| G7 | Tiga alat suhu baru (perm. 10) — Thermocouple, Termometer Gelas, Thermohygrometer | **BERES di server** (26 Agt 2026) — profil + olah data + geometri OCR + CSV. Angkanya cocok sama ketiga workbook master sampai digit terakhir; dijaga `Suhu3AlatMasterTest` (15 test) & `Suhu3AlatLembarKerjaTest` (13 test). **Sisi mobile belum** |

### Yang sudah ADA sebelum pekerjaan ini dimulai

Supaya tidak dibangun ulang:

- Alur berjenjang 2 langkah di HP **sudah jalan** — kategori Suhu sudah menampilkan 11 alat,
  termasuk TITS dan TIDS.
- `lokasi` (lab/onsite), tabel `rooms`, `room_id` & `lokasi_nama` di sesi, endpoint CRUD ruangan,
  layar master ruangan di HP. `lokasi_nama` baru terpasang di 2 profil waktu permintaan 2 dimulai.
- Status `draft` + filter `?status=draft` + tombol Simpan Draf di setiap halaman lembar kerja.
- Seluruh backend OCR (lihat perm. 7).
- `AppMotion` + `TampilMasuk` — animasi sudah ada dan sudah dijaga test. Aturannya sengaja:
  bagian tanpa tabel dianimasikan, bagian bertabel polos (60 kotak angka jadi berat).
- Baris CMC TIDS **sudah ter-seed**: 3 rentang (0,86 / 1,4 / 3,1 °C).

### Jebakan yang sudah terbukti — jangan diulang

- **Mengeluarkan field dari `fieldAdmin()` ikut menghapusnya dari daftar yang DISIMPAN.**
  `$opsional` di `atributDariRequest()` dibuka dengan `...fieldAdmin()`, jadi field yang
  dikeluarkan dari sana (biar teknisi boleh mengisinya) justru berhenti pernah ditulis ke
  database. Gejalanya persis seperti field yang dibuang: lolos validasi, respons 200, nilainya
  tidak pernah sampai. Jebakan ini **sudah tertulis** di komentar `thermohygro_standard_id` tepat
  di baris itu — dan `calibration_method_id` tetap terperosok ke lubang yang sama, satu baris di
  bawah peringatannya.

- **Saringan yang benar bisa melahirkan jalan buntu.** Menyaring dropdown alat ke lembar yang
  sedang dibuka menutup satu kelas kesalahan senyap (sesi dihitung pakai aturan alat lain), tapi
  membuat kategori yang belum punya alat mustahil dipakai — dropdown mati, tombol kirim menahan.
  Tiap saringan yang bisa menghasilkan **himpunan kosong** wajib punya jalan keluar di layar yang
  sama; menyuruh orang keluar ke menu lain dan menebak parameternya bukan jalan keluar.

- **Penjaga yang mengikat kode ke dokumen terkendali jangan ditimpa, walau permintaannya
  masuk akal.** `TidsLembarKerjaTest` menjaga urutan bagian *"ngikut urutan kertasnya dibaca dari
  atas"*. Menyeragamkan tata letak antar-lembar terdengar seperti kerapian murni, padahal dia
  memindahkan kotak relatif terhadap `SIDIK-FM-CAL-0506 Rev.4`. Kalau penjaga semacam itu merah,
  yang salah biasanya perubahannya — bukan penjaganya.

- **Mesin diberi PREMIS yang salah, lalu hasilnya dipercaya.** Pemeriksa
  `pembacaan_bukan_kelipatan_resolusi` berdiri di atas satu premis: angka yang dicatat dibaca di
  layar alat, dan layar itu punya satu daya baca tetap. Buat TITS premisnya nggak berlaku —
  alatnya pindah rentang (0,01 di bawah ~500 °C, 0,1 di atasnya), dan `equipments.resolusi` cuma
  satu skalar. Hasilnya 25 tuduhan salah ketik per sesi atas angka yang disalin apa adanya dari
  master lab. Sama persis dengan baris Suhu Ruang yang diadu ke rentang chamber: penggaris yang
  salah nggak menghasilkan error, dia menghasilkan **kebenaran yang dibalik** — angka yang benar
  diteriakin, yang salah lolos.

- **Peringatan palsu yang selalu muncul itu kerusakan, bukan kerapian.** Dia melatih admin
  menekan "SETUJUI TETAP" tanpa membaca, dan begitu itu jadi kebiasaan, peringatan yang benar
  ikut tenggelam. Di sesi Inkubator yang ditolak, satu-satunya temuan yang beneran menahan
  sertifikat berdiri di antara 24 baris yang menunjuk arah salah.

- **"Nggak dikirim" nggak boleh berarti "kosongkan".** `standard_id` & `tanggal_terima` ada di
  blok yang selalu ditulis, jadi tiap simpan ulang yang nggak membawanya menghapus isinya —
  tanpa error, dan yang menghapus bukan orang yang mengisi. Tetangganya sudah dilindungi sejak
  lama lengkap dengan komentarnya; dua kolom ini cuma nggak ikut. Buat lab terakreditasi,
  ketertelusuran yang hilang senyap itu temuan audit.

- **Satu `first()` yang menerima dua kunci sekaligus memilih diam-diam.** `$s->nama === $k ||
  $s->serial_number === $k` di dalam satu `first()` bikin yang menang cuma yang ID-nya terkecil.
  Di lab ini dua baris master berbagi seri `23P1005` — sensor RTD dan kalibrator Yokogawa yang
  menempel padanya — jadi baris Yokogawa di lembar tertaut ke dokumen sensor. Merknya kebetulan
  sama, jadi angkanya nggak salah; yang salah nomor sertifikat & ketertelusurannya. Prioritaskan
  kunci yang lebih spesifik, jangan gabungkan dalam satu lolos.

- **Penanda yang menempel ke POSISI, bukan ke nilai.** `titik_ke` dan indeks baris itu posisi,
  dan posisinya geser tiap bentuk lembar berubah — lembar generik Conductivity menyusut begitu
  alatnya dipilih. Kode sel revisi karena itu dikunci ke **titik ukur**, dan penandanya
  **disusun ulang tiap `_bangunTitik()`** jalan. Tanpa yang kedua, teknisi membuka lembar
  revisi tanpa satu pun kotak merah padahal admin sudah menandai — lalu kembali ke jalan aman:
  mengosongkan tabel dan mengetik ulang semuanya. Persis yang mau dicegah.

- **Satu kunci pilihan untuk banyak temuan sejenis.** `LembarTolak` mengunci pilihan ke
  `t.kode`, sementara kode mesinnya memang sama untuk temuan sejenis —
  `pembacaan_di_luar_rentang` muncul sekali per pembacaan. Empat baris di layar menyala-mati
  bersamaan. Selama yang disumbang cuma prosa itu cuma berisik; begitu temuan menyumbang
  **kode sel**, admin yang mau menandai satu kotak diam-diam menandai empat — dan tiga di
  antaranya angka yang justru sudah benar.

- **Kotak teks terisi, nilai di baliknya belum.** `BarisSensorState.pembacaan` (dan
  `BarisDeretState.nilai`) baru terisi sesudah `bacaUlang()`. Memulihkan grid dengan hanya
  menulis `TextEditingController.text` menghasilkan layar penuh angka yang oleh
  `sensorTerisi`, lencana Sensor Acuan, dan daftar peringatan dibaca sebagai **kosong** —
  sampai teknisi mengetuk satu sel. Payloadnya sendiri selamat (`toSubmission` memanggil
  `bacaUlang`), jadi yang rusak cuma apa yang dilihat orang: teknisi diberitahu "belum ada
  termokopel yang diisi" sambil menatap grid yang penuh.

- **Kode sel cuma jujur kalau menunjuk TEPAT SATU baris.** Matriks Autoklaf menaruh delapan
  baris besaran (`Temp. Disk 1`, `Indikator Pressure`, …) dengan `titik_ukur` **nol semua**.
  Satu kode yang cuma menyebut titik akan menunjuk delapan kotak sekaligus. Validator
  menghitung penghuni tiap (tahap, titik ukur, pengulangan) lebih dulu dan **tidak
  mengeluarkan kode sama sekali** kalau lebih dari satu — temuannya tetap muncul, yang hilang
  cuma kemampuan mengetuknya jadi penanda.

- **Jatuh diam-diam ke profil pH.** Nama alat yang tidak cocok memulangkan profil pH tanpa error;
  teknisi mengisi lembar pH untuk alat lain. Ejaan TIDS yang mengikat:
  `Temperatur Indikator dengan Sensor` — "Temperatur" bukan "Temperature", "dengan" huruf kecil.
- **Daftar yang ditulis tangan menyusut diam-diam waktu barang barunya nambah.** Data provider
  `CetakLembarKerjaOcrTest::alat()` mendaftar 7 kode alat. Waktu S3 dijawab dan 9 berkas geometri
  baru mendarat, daftarnya tetap 7 — jadi 17 lembar bisa dipindai sementara cuma 7 yang dijaga,
  dan yang 10 justru yang paling baru. Nol test merah, karena test yang tidak pernah dijalankan
  tidak pernah gagal. Sekarang providernya `glob` dari berkas yang benar-benar ada, plus
  `test_tiap_profil_punya_berkas_geometrinya_sendiri` yang menjaga arah sebaliknya.
- **Golden PNG cuma sah di macOS, dan ambangnya beda 150×.** `test/flutter_test_config.dart` di
  repo mobile: ambang **0,1% di macOS**, **15% di luar macOS**. `periksa-pr.yml` jalan di ubuntu,
  jadi memang buta terhadap pergeseran layout. Sebuah screenshot yang bergeser 7,14% lolos PR,
  mendarat di `main`, lalu **mematikan lima rilis desktop beruntun** — dan karena job `terbitkan`
  menunggu `build`, halaman unduh Firebase ikut beku ~23 jam. Dibereskan di PR #102: rilis
  desktop tidak lagi digerbangi golden, gerbangnya pindah ke job `Golden (macOS)` waktu PR.
  Konsekuensi yang menempel: **`--update-goldens` hanya boleh dijalankan di macOS**, dengan
  Flutter versi yang sama persis dengan CI (`3.44.6`), dan hanya berkas yang memang berubah yang
  di-commit — sisanya dikembalikan.
- **`git fetch <branch>` TIDAK memajukan branch lokal.** Dia cuma menulis `FETCH_HEAD`. Dan
  `git checkout <branch>` waktu kita memang sudah berada di branch itu menjawab `Already on
  '<branch>'` lalu tidak melakukan apa pun. Gabungan keduanya menghasilkan pohon kerja yang
  **masih tertinggal padahal kelihatan baru saja disegarkan** — tanpa satu pun peringatan.

  Ini memakan tiga putaran waktu memperbarui golden Chlorin (25 Agt 2026). Urutan
  `git fetch origin <branch>` → `git checkout <branch>` → `flutter test --update-goldens`
  merender **kode lama**, lalu menulisnya sebagai golden "baru". Yang lahir bukan error:
  sebuah PNG yang kelihatan sudah diperbarui tapi memotret tampilan sebelum perubahan.
  CI menolaknya dengan selisih **7,15%** — praktis sama dengan 7,14% milik golden lama.

  Tanda pengenalnya ada di ukuran berkas. Render ulang yang benar menggeser ukurannya jauh;
  yang salah cuma meng-encode ulang gambar yang sama:

  | | ukuran | selisih |
  |---|---|---|
  | render dari pohon basi | 234.478 → 234.567 | **+89 B** — bohong |
  | render dari pohon benar | 234.478 → 233.606 | **−872 B** — sah |

  Jadi sebelum commit golden, jalankan `git diff --stat`: pergeseran puluhan byte berarti
  pohonnya salah, bukan golden-nya. Yang memajukan branch lokal itu `git pull`, atau
  `git checkout -B <branch> origin/main` kalau memang mau menimpanya.
- **Dua repo ini punya branch bernama sama (`claude/hai-kp62fs`).** Jadi `git push origin
  claude/hai-kp62fs` yang dijalankan dari repo yang keliru **berhasil** — dia mendorong branch
  repo itu, dan yang keluar `Everything up-to-date`. Persis seperti push yang sukses, padahal
  yang mau didorong ada di repo sebelah dan tidak ke mana-mana. Pastikan `pwd` dulu; kalau
  ragu, `git log --oneline -1` menunjukkan repo mana yang sedang dipegang.
- **Nama alat pelanggan TIDAK pernah byte-exact — jangan pernah mencocokkannya dengan `=`.**
  `CalibrationProfileRegistry::cocokkanNama()` sengaja menerima kunci yang **nempel di tengah**
  nama ("Turbidimeter Hach", "pH Meter Mettler Toledo", "Water Bath"), mengabaikan besar-kecil
  huruf, dan mencoba kunci terpanjang duluan. Itu satu-satunya tempat aturannya hidup, dan
  docblock-nya sudah mewanti: *"kalau aturannya mau diubah, ubah di `cocokkanNama` — jangan bikin
  salinan ketiga."*

  Godaannya besar karena `WHERE nama_alat_kemampuan = ?` kelihatan jauh lebih sederhana. Yang
  terjadi kalau dituruti: alat pelanggan yang **sah dan terdaftar** hilang dari daftar pilih,
  teknisi mengira belum ada, lalu menambah duplikat — dan duplikat itu **tidak punya baris CMC**,
  jadi sesinya jatuh ke jalur generik dan U95-nya terbit lebih kecil daripada yang diakreditasi.
  Alatnya ada, tidak muncul, dan tidak ada satu pun error di sepanjang jalur itu.

  Dijaga `DaftarAlatPerLembarTest::test_alat_berejaan_alias_tetap_muncul` — dibuktikan merah
  dengan mengganti penyaringnya jadi perbandingan teks: "Turbidimeter Hach" langsung hilang total
  dari hasil.
- **Kotak yang mendeklarasikan sumbernya tapi tidak ada yang mengisi.** `$this->field(...)`
  memberi `pilihan` nilai bawaan `[]`. Jadi sebuah kotak bisa lahir lengkap dengan
  `sumber: 'master_thermohygro'` tanpa satu pun kode yang benar-benar mengisinya — dan itu
  **bukan error di mana pun**. Layar teknisi menggambar dropdown dari daftar yang dibawa bentuk,
  daftar kosong bikin dia jatuh ke cabang teks mati, dan sesinya tetap tersimpan dengan
  `thermohygro_standard_id` **null**.

  Kejadian di **7 dari 17 lembar** sekaligus — TITS, TIDS, dan kelima Enclosure — dan seperti
  biasa yang bolong justru yang paling baru. Akibatnya bukan kosmetik: koreksi kondisi lingkungan
  berikut U95-nya tidak menempel ke unit mana pun, kelas kesalahan yang sama dengan Env. Condition
  tiga alat yang meleset 10 Agustus 2026.

  TIDS bahkan punya jalur cadangan yang **juga** mati, dan yang ini lebih halus karena kelihatan
  bekerja: `baris_thermohygro` di kop terisi, labelnya benar, tapi dicocokkan ke koleksi
  `whereNull('parameter_kondisi')` milik `tautkanStandar()` — saringan untuk KALIBRATOR. Karena
  `ThermohygroSeeder` **selalu** mengisi kolom itu, keempat barisnya mustahil ketemu dan selalu
  pulang `terdaftar: false` dengan `standard_id` null.

  Dijaga sekarang oleh `ThermohygroSemuaLembarTest` (dropdown wajib berisi, tiap pilihan wajib
  menunjuk baris `standards` nyata yang memang thermohygro) dan
  `SemuaProfilLembarKerjaTest::test_tiap_sumber_master_punya_golongan_dan_alasan` — tiap
  `sumber: master_*` wajib digolongkan **diisi profil** atau **ditarik aplikasi**, berikut
  alasannya. Sumber master ke-5 tidak bisa lahir tanpa yang menulisnya memutuskan siapa
  yang mengisinya.
- **Grup Inlab/Insitu thermohygro memang BEDA per lembar — jangan diseragamkan.** Yang
  menentukan cetakan formulirnya, bukan tempat unitnya diparkir. `ConductivityProfile` dan
  `TidsProfile` menaruh TH-7 di **Insitu** mengikuti `SIDIK-FM-CAL-0510_Rev.5` dan kop TIDS,
  sementara lembar lain menaruhnya di **Inlab**, dan `thermohygro-lab.json` mencatat
  penempatannya `Inlab (Lab. Gaya)`. Ketiganya benar untuk konteksnya masing-masing —
  `standard_id` yang tersimpan sama persis, yang beda cuma di bawah judul mana kotaknya muncul.
  Menyeragamkannya ke satu daftar global akan membuat kop dan dropdown di lembar yang sama
  saling bertentangan. `ThermohygroSemuaLembarTest` sengaja cuma mengunci **keanggotaan**
  ketujuh unit, bukan grupnya.
- **Tahap build yang butuh `vendor/` jangan bikin `composer install` kedua.** Tahap aset Docker
  butuh `vendor/filament` (tema Filament v4/v5 mengimpor CSS-nya dari sana), padahal
  `.dockerignore` membuang `vendor` dari konteks build. Jawaban pertamanya — tahap composer
  terpisah di atas image `composer:2`, sengaja **bukan** menyalin dari tahap PHP supaya
  "resolusi platform tidak berubah diam-diam" — justru yang meledak di deploy pertama
  (26 Agt 2026, commit `3d02d73`):

  ```
  filament/support v5.6.8 requires ext-intl * -> it is missing from your system.
  ```

  Image resmi PHP **tidak pernah** membundel `intl`: dia butuh ICU dan harus dipasang eksplisit,
  dan `composer:2` tidak membutuhkannya untuk kerjanya sendiri. Tahap PHP di Dockerfile sudah
  memasangnya sejak lama lewat `install-php-extensions` — jadi satu-satunya tahap yang platformnya
  benar justru yang dihindari. Alasan yang ditulis waktu itu terbalik.

  Yang penting dipegang: kalau sebuah tahap build butuh isi `vendor/`, **ambil dari tahap yang
  memang menerbitkan `vendor/` itu**, jangan pasang ulang di image lain. Resolusi kedua bukan cuma
  mubazir — dia berjalan di atas platform yang berbeda dari yang benar-benar dikirim. Sekarang
  Dockerfile punya tahap `php-dasar` yang jadi dasar image akhir sekaligus sumber
  `vendor/filament`, dan `composer install` cuma jalan sekali per build.

  Cara membuktikannya tanpa Docker (sandbox tidak punya daemon): salin `composer.json` +
  `composer.lock` ke direktori lain, sisipkan `"platform-overrides": {"ext-intl": false}` ke
  **lock**-nya — bukan ke `composer.json`, karena `install` membaca override dari lock — lalu
  jalankan perintah yang sama persis dengan yang di Dockerfile.
- **Kotak yang menjanjikan pencocokan di docblock, tapi pencocoknya tidak pernah ditulis.**
  `EnclosureProfileBase::STANDARD_TERCETAK` punya kunci `cocok` berikut docblock yang menyatakan
  dia "dipakai mencocokkan ke baris master standar lewat nama ATAU nomor seri". Pencocoknya
  **tidak pernah ada**. Lembar Enclosure mengirim baris standar apa adanya — cuma `label` +
  `cocok`, tanpa `standard_id` — sementara `TitsProfile` memanggil `tautkanStandar()` dan
  karenanya jalan.

  Bandingkan satu baris:

  ```
  TitsProfile.php:682          return $this->tautkanStandarTitik($this->tautkanStandar(...));
  EnclosureProfileBase.php:730 return $this->isiPilihanThermohygro($bentuk);
  ```

  Jatuhnya beruntun dan senyap: HP membaca `json['standard_id']` → null → sesi tersimpan tanpa
  standar → `merkKalibrator(null)` → null → `syaratKurang()` → `semuaBelum()` → **SELURUH titik
  dicap belum dihitung**. Yang sampai ke admin bukan "standarnya belum dipilih", tapi
  `titik_kosong` plus `titik_tidak_terhitung` di tiap titik — enam peringatan dari satu sebab,
  dan sebabnya yang paling tidak kelihatan di antara semuanya. Kelima lembar Enclosure kena
  sekaligus karena mewarisi kelas yang sama. Dilaporkan pemilik lab 26 Agt 2026 sebagai sesi
  Inkubator yang di-reject.

  **`EnclosureSesiTest` hijau selama itu** karena dia menyuapkan `'standard_id' => $standar->id`
  langsung ke payload, diambil sendiri dari database — tidak pernah lewat `bentukLembarKerja()`.
  Test membuktikan kalkulatornya benar sambil membiarkan jalur yang dilewati manusia putus.
  Ini kelas kegagalan yang sama dengan template OCR 7 → 17: penjaga yang menguji dari sisi
  yang salah.

  Dijaga sekarang oleh `EnclosureStandarTertautTest` — dibuktikan merah dengan mematikan
  panggilan `tautkanStandar()`: **0 dari 11 lulus**.

  Dua hal ikutan yang ketemu waktu memperbaikinya:
  - Baris Recorder **cuma bisa tertaut lewat nomor seri**. Kertas nyetak "Graptech GL840-SDWV",
    master menulis "Graphtech GL840" — beda huruf DAN beda model. Kalau pencocokan seri suatu
    saat dibuang "biar sederhana", seluruh sesi Recorder diam-diam berhenti terhitung.
  - **Yokogawa CA 150 tidak tercetak di kertas Rev.3** padahal dia kalibrator enclosure yang
    paling kepakai (master olah datanya sendiri bernama `..._Constant_Yokogawa.xlsm`).
    Ditambahkan mengikuti `FORM VALIDASI rev. 11` (24 Mei 2024: *"Remove std. Victor / Add std
    kalibrator yokogawa"*). Victor sengaja TIDAK dibuang walau rev. 11 memintanya — kertas yang
    dipegang teknisi masih memuatnya, dan dia tampil sebagai baris `terdaftar: false`.
- ~~**Enclosure tidak punya `equipment_id`**~~ — sudah dibereskan di `dfe8ef8`; bagian
  `identitas_alat` sekarang membawanya, dan 15 test di `EnclosureKepalaLembarTest` merah kalau
  hilang lagi.
- **Titik di kode kolom = "nilai turunan".** `FieldLembarKerja.turunan` membacanya dari
  `kode.contains('.')`, dan kolom turunan tidak diberi kotak isian sama sekali. Jadi
  `dimensi.panjang` tampil di layar tapi **tidak bisa diketik**, tanpa satu pun error. Kolom yang
  diketik teknisi pakai garis bawah (`dimensi_panjang`), yang dihitung saja yang pakai titik
  (`dimensi.volume`).
- **Baris "Suhu Ruang"** di grid Enclosure sudah diisi teknisi tapi dibuang sebelum dikirim —
  backend belum punya tempat menampungnya.
- **`php artisan test -d "DB_CONNECTION=mysql"` DIABAIKAN.** `phpunit.xml` memaksa
  `sqlite::memory:` lewat `<env>`, dan cuma environment variable yang menimpanya:
  `DB_CONNECTION=mysql DB_DATABASE=sidik_test php artisan test`. Dengan flag `-d`
  test-nya tetap jalan di SQLite dan **tetap hijau** — jadi "sudah diverifikasi di
  MySQL" bisa jadi klaim palsu tanpa satu pun tanda. Buktikan dengan test kecil yang
  mencetak `DB::connection()->getDriverName()`.
- **Verifikasi di MySQL lokal MENYEMBUNYIKAN test yang kurang `RefreshDatabase`.** Kebalikan dari
  jebakan di atas, dan sama-sama bikin "sudah diverifikasi" jadi klaim yang meleset. Database
  MySQL lokal (`sidik_test`) **awet** — tabelnya masih ada dari run sebelumnya. Jadi test yang
  menyentuh database tanpa trait `RefreshDatabase` tetap **hijau di lokal**, karena tabelnya
  kebetulan ada. CI jalan di `sqlite::memory:` yang benar-benar kosong untuk test semacam itu,
  dan di sana yang keluar `no such table`.

  Kejadian 25 Agt 2026: `EnclosureProfileBase::bentukLembarKerja()` mulai membaca master
  `standards` (buat mengisi dropdown thermohygro). `Tests\Unit\EnclosureProfilTest` memanggilnya
  tanpa `RefreshDatabase` — **1.874 test hijau di MySQL lokal**, lalu **5 error di CI**.

  Jadi kalau perubahannya membuat kode yang tadinya bebas-database jadi menyentuh database,
  jalankan **dua-duanya**: `php artisan test` (SQLite, sama dengan CI) *dan*
  `DB_CONNECTION=mysql DB_DATABASE=sidik_test php artisan test`. Yang satu menangkap presisi
  desimal & FK, yang satu lagi menangkap tabel yang belum dimigrasi.
- **SQLite menyembunyikan FK.** `PRAGMA foreign_keys` diabaikan di dalam transaksi,
  dan `RefreshDatabase` membungkus tiap test dalam transaksi — jadi FK komposit
  antar-lab tidak pernah benar-benar diuji di SQLite.
- **`flutter analyze` mengedit `analysis_options.yaml` sendiri** ("Upgrading analysis_options.yaml
  to exclude build and platform directories"). Selalu keluarkan dari commit.
- **`flutter test` hijau bukan bukti UI hilang** — sebagian besar test pindai menguji layanan
  langsung, tidak lewat tombol.
