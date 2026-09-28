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

> **Susulan 2 Sep 2026 — perintah "model pembaca sel buatan sendiri" BERHENTI di Gerbang 0.**
> Perintah itu minta mengganti `MlKitPembacaSel`. Ditelusuri, komponen itu **tidak pernah
> dipanggil aplikasi**: tombol `PINDAI LEMBAR KERJA` dicabut permanen 26 Agt 2026, dan
> `JalankanPindai` cuma diinstansiasi di test. Jadi M0 "selamatkan dataset" mengumpulkan **nol
> crop** — koreksi teknisi tidak punya pemanggil. Rantai buktinya, plus rekomendasi penggantinya
> (perekam ground truth di jalur `FOTO TABEL INI` yang hidup — satu berkas HP + satu perintah
> artisan, bukan 22 hari model), ada di **`docs/temuan-gerbang0-ocr-model-lokal.md`**.
> **SELESAI 2 Sep 2026.** Disetujui lalu dikerjakan: perekam tebakan mesin tersambung di
> **keenam** jalur kamera (titik × Repeat, grid Enclosure, matriks Autoklaf, pasangan
> standar/UUT, keterulangan Timbangan, Timer/Stopwatch), plus `ocr:akurasi-kamera` yang
> membaca tiga sumber (`raw_measurements`, `hasil_autoclave`, `spesifikasi_alat`).
> Kontraknya di `docs/kontrak-api.md` §"TEBAKAN MESIN per sel". Empat keputusan yang
> tersisa cuma bisa dijawab lab — `docs/pertanyaan-lab-akurasi-kamera.md`.

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
| **C** | Pencarian standar nggak disaring per lab | **BERES SELURUHNYA** (27 Agt 2026) — 13 tempat, dijaga `StandarTidakBocorAntarLabTest` yang daftarnya dari registry |
| **D** | Baris Suhu Ruang diadu ke rentang chamber — 20 palsu/sesi | **BERES** — pitanya sendiri, 5–45 °C |
| **E** | Pesan "titik tidak terhitung" menebak tiga sebab | **BERES** — sebabnya ditanya ke profil |
| **F** | TITS Measure: 25 tuduhan salah ketik per sesi | **BERES** — TITS nggak diadu ke `equipments.resolusi` |
| **G** | Peringatan grid nggak nyebut baris mana | **BERES** — perannya ikut di label |
| **H** | Komentar "6 dari 17 lembar tanpa vonis" (sebenarnya 12) | **BERES** (27 Agt 2026) — daftarnya dibuang, bukan dipanjangin; sekarang 15 dari 20, dan dijaga sapuan registry |

### Yang TIDAK diubah, dan kenapa

- **`equipments.resolusi` TITS tetap `0,1`.** Angka itu kecetak di sertifikat dan ikut ngitung
  budget ketidakpastian (`Z21`); menggesernya ke `0,01` menggeser U95 yang sekarang cocok dengan
  Excel lab sampai digit terakhir. Yang dimatikan pemeriksanya, bukan datanya.
- **Toleransi Enclosure tetap NULL.** Master enclosure memang nggak punya batas keberterimaan.
  Sudah dibuktikan nggak memblokir apa pun: sesi Oven `2406.25.AI` bertoleransi NULL menghasilkan
  3 titik terhitung.
- **Baris Victor 14+ tetap tercetak** sebagai baris tidak terdaftar.

### ~~C di 12 profil lain~~ — SUDAH BERES (27 Agt 2026)

Polanya (`Standard::query()->whereNull('parameter_kondisi')` tanpa saringan organisasi) sudah
ditutup di **ketiga belas** tempatnya: Refractometer, Spectrophotometer, Viscometer, Gas
Detector, TITS, TIDS, Conductivity, DO, Chlorine, Autoclave, Turbidimeter, `EnclosureProfileBase`,
`LembarKerjaTemplate` (×2), dan pintu bersamanya `CalibrationProfile::masterStandarTertaut()`.

Dijaga `StandarTidakBocorAntarLabTest` + `BatasAntarLabTest` — 41 test, dan daftar profilnya
**diambil dari registry, bukan ditulis tangan**, jadi profil ke-18 ikut kesapu tanpa ada yang
perlu ingat menambahkannya. Itu yang bikin perbaikan ini nggak balik lagi: polanya dulu menyebar
justru karena disalin satu-satu.

### ~~H · Komentar "lembar tanpa vonis"~~ — BERES 27 Agt 2026

Butir ini ditulis sebagai "6 dari 17 (sebenarnya 12)". Waktu ditelusuri, **dua-duanya
sudah basi**: hari ini registry punya 20 profil dan **15** di antaranya
`punyaToleransi() === false`. Angkanya bergerak tiap kali lembar baru mendarat — tiga alat
suhu terakhir menggesernya 12 → 15 sendirian.

| Yang dicek | Hasil |
|---|---|
| Bunyi komentarnya di `CalibrationValidator` | "kelima Enclosure, TITS, Autoklaf, DO, Gas Detector, Conductivity, Spectro" → **11 nama** |
| Kebenarannya hari ini | **15** lembar (11 itu + TIDS + Thermocouple + Termometer Gelas + Thermohygro) |
| Kodenya sendiri | **nggak pernah salah** — dia nanya `punyaToleransi()`, bukan daftar |
| Test yang menjaganya | cuma `test_sebab_yang_disebut_beneran_berlaku`, dan itu **satu lembar** (Inkubator) |

Jadi ini nggak pernah jadi bug perilaku. Yang bolong: prosa yang basi di sebelah kode yang
benar, plus klaim luas yang cuma dibuktikan di satu lembar.

**Yang dikerjakan — daftarnya dibuang, bukan dipanjangin.** Menulis ulang "15 lembar" cuma
memindahkan tanggal kebasiannya ke lembar ke-21. Komentarnya sekarang menyebut PREDIKATNYA
(`punyaToleransi() === false`) plus satu baris yang beneran bisa dijalankan buat
menghitungnya, dan cerita kebasiannya ditinggal sebagai alasan kenapa nggak boleh ada
daftar di situ lagi. Nol baris non-komentar berubah di `CalibrationValidator.php`.

**Penjaganya diperluas dari 1 lembar ke 15, daftarnya dari registry.**
`test_sebab_toleransi_disaring_dari_registry_bukan_daftar_tulis_tangan` menyapu tiap sesi
ter-seed dan menurunkan harapannya dari `punyaToleransi()` profil sesi itu — jadi lembar
ke-21 ikut kesapu tanpa ada yang perlu ingat. Dijaga dua arah: cabut `if`-nya → Conductivity
merah; hapus sebabnya total → pH merah.

Cakupannya apa adanya: **10 dari 15** lembar tanpa vonis punya sesi contoh buat diadu. TIDS,
Furnace, Bath, dan Refrigerator belum punya sesi sama sekali; Autoklaf punya sesi tapi nol
pembacaan mentah, jadi pesannya nggak pernah lahir. Kelima itu ditulis di docblock test-nya,
bukan didiamkan.

### ~~Rentang inkubator 30–300 °C~~ — HANTU, ditelusuri 27 Agt 2026

Butir ini berbunyi *"perlu dicek ke spesifikasi unit fisiknya — angka itu lebih mirip oven"*.
Dugaan itu benar: **angkanya memang punya oven.** Yang salah alamatnya.

| Yang dicek | Hasil |
|---|---|
| `equipments` inkubator | INCUCELL LSIS-B2Y/IC 55 → **15–100 °C** |
| Riwayat `EnclosureSeeder` | `range_min => 15` sejak commit `0247205` yang menambahkannya; `git log -S "'range_min' => 30, 'range_max' => 300"` **nol hasil** |
| Pemilik angka 30–300 | `Oven Memmert UN55` (alat #4) dari `DemoDataSeeder` — **nol sesi kalibrasi** |
| Sesi Inkubator yang ditolak (`2405.03.AV`) | pakai alat #19, jadi rentang 15–100; dan **nol baris `suhu_ruang`** |

Jadi kekhawatiran aslinya — "rentang ukur ikut tercetak" — nggak pernah berlaku: nggak ada
sertifikat yang pernah mencetak 30–300 sebagai rentang inkubator.

**Asal-usulnya komentar ilustrasi yang kebaca sebagai data.**
`CalibrationValidator` menjelaskan bug D dengan tabel contoh jenis chamber (`Inkubator 30–300`,
`Furnace 300–1000`, `Refrigerator −20–10`) buat menunjukkan cara gagalnya. Ketiganya hipotetis;
`EnclosureSeeder` cuma menyemai dua alat, dan **25 °C masuk rentang dua-duanya**. Komentarnya
sekarang menyatakan itu eksplisit supaya nggak kebaca ulang sebagai data.

Yang TIDAK berubah: perbaikan D tetap benar. Mengadu suhu ruang ke rentang ukur chamber itu
penggaris yang salah terlepas dari angkanya, dan dia mulai menyala di hari lab mendaftarkan
furnace atau refrigerator — dua-duanya pekerjaan enclosure biasa. Yang dikoreksi cuma klaim
bahwa kebakarannya sudah menyala hari ini.

> **Batas bukti ini.** Semuanya dari database dev yang ter-seed. Kalau di produksi ada alat
> inkubator yang rentangnya beneran 30–300, itu baris data yang perlu dibetulkan di sana — dan
> nggak akan kelihatan dari sini.

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

Urutan bagian: **SUDAH diseragamkan** (26 Agt 2026, atas keputusan pemilik lab).

Pola bersama tujuh belas lembar: `identitas_alat` > blok pemilik & lokasi > `usage_check` >
...pengukuran... > `penutup`. Dua yang dulu melenceng:

- **TIDS** naruh kotak dryblock SEBELUM blok `Standard used:`, ngikut `SIDIK-FM-CAL-0506 Rev.4`
  dibaca dari atas — satu-satunya dari tujuh belas yang `usage_check`-nya nggak di posisi ketiga.
- **Autoclave** membuka dengan `informasi_umum` + `kondisi_lokasi` lalu `identitas_alat` di urutan
  ketiga, dan blok standarnya nongol SESUDAH tabel hasil — jadi satu-satunya lembar yang nanya
  "standar mana yang dipakai" sesudah angkanya terlanjur diketik.

Yang **TIDAK** ikut berubah, dan ini yang bikin keputusannya aman buat lab terakreditasi:

- **Kertasnya.** Formulir terkendalinya nggak disentuh sama sekali.
- **Lembar cetak buat dipindai.** Jalur cetak punya definisinya sendiri (`bentukPindaiFoto()` +
  template OCR) dan nggak baca urutan array `bagian`. Yang digeser cuma urutan baca di LAYAR.
- **Isi tiap bagian.** Yang dipindah blok utuh; nggak ada field yang pindah rumah. Autoclave tetap
  punya DUA blok konteks karena di kertasnya General Information emang dua blok — maksa jadi satu
  bakal mengubah isi formulir, bukan cuma urutannya.

Dijaga `SemuaProfilLembarKerjaTest::test_urutan_bagian_seragam_di_semua_lembar()` — sapuan
registry, jadi profil ke-18 ikut kena tanpa ada yang perlu ingat. Dibuktikan menggigit: dengan
urutan lama, dua profil itu persis yang merah.

**TIDS masih menaruh 19 field di `identitas_alat`** (tujuh lembar lain: 10) — kondisi lingkungan,
lokasi, dan metode ikut di blok itu. Itu soal ISI bagian, bukan urutan, dan belum diseragamkan.

## 11. Tiga alat suhu baru — Thermocouple, Termometer Gelas, Thermohygrometer

Ditambahkan pemilik proyek 26 Agt 2026 bersama tiga workbook master ber-password:

> *"ada 3 alat itu parhatikan buat kanyak biasa nya serta olah data nya … sekrang
> buatkan bagian backendnya dulu dan juga tolong itu bagian kemera nya juga harus
> bisa juga"*

| | Isi | Status |
|---|---|---|
| **A** | Backend tiga alat (profil, lembar kerja, jalur simpan) | **BERES** — alat ke-18, 19, 20 |
| **B** | Olah data (koreksi + budget U95) sesuai master | **BERES** — cocok sampai digit terakhir, dijaga `Suhu3AlatMasterTest` |
| **C** | Bagian kamera (pindai lembar kerja) | **BERES 27 Agt 2026** — tombol `FOTO TABEL INI` ketiga lembar dulu selalu pulang nol sel; lihat §12 |
| **D** | Excel → CSV | **BERES** — 43 sheet di `sidik-calibration-mobile/Project-PT-Sidik/suhu CSV` |
| **E** | Sisi mobile (layar lembar kerja) | **BERES** (26–27 Agt 2026) — baris ini sempat basi, lihat §Gelombang G7 yang sudah mencatatnya selesai: layar tabel pasangan (mobile#108), golden ketiga lembar (mobile#111), dua deret dipecah di layar detail (mobile#112), tiga field sesi kebaca admin (mobile#113) |

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

**Waktu itu K2 tetap terbuka** — `TidsProfile` tidak disentuh dan blokir U95 TIDS tetap
berdiri. **Sehari kemudian (28 Agt 2026) workbook TIDS-nya benar-benar turun**, dan kali ini
label DAN angkanya cocok: tabel CMC-nya berbunyi 0,86 / 1,4 / 3,1 — baris no. 2 TIDS. Lihat §13.

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

### Susulan: tiga field sesi itu kelihatan admin, bukan cuma teknisi

`alat_bantu`, `tipe_pencelupan`, dan `titik_es` sempat cuma hidup di jalur
lembar kerja teknisi — dipulangkan supaya draf yang dibuka lagi utuh, tapi tidak
pernah tergambar di layar detail yang dibaca admin sebelum menerbitkan
sertifikat. Bukan lubang perhitungan: kontribusi titik es tetap kebaca sebagai
komponen `stabilitas_titik_es` di tabel Type B. Yang hilang tiga keterangan yang
justru dipakai mengadu sesi dengan lembar cetak di meja.

Status: **selesai** — API menambah `alat_bantu_label`, layar detail mobile
menampilkan ketiganya.

Satu keputusan yang pantas dicatat: **nama alat bantu diresolusi di SERVER,
bukan dipetakan ulang di HP.** Kolomnya menyimpan kode (`A`, `B`, `satu`,
`dua`) yang cuma punya arti di daftar `pilihan` milik profilnya. Peta kode→nama
yang disalin ke HP gagal dengan cara paling sepi: lab beli dryblock ketiga,
seseorang menambahkannya ke `DRYBLOCK`, dan layar admin memajang `C` mentah
tanpa satu pun error. Hook-nya `CalibrationProfile::labelAlatBantu()`, default
null buat tujuh belas alat lain.

### Audit ulang 27 Agt 2026 — 43 sheet diadu, satu bug ketemu

Pemilik proyek minta ketiga alat dicek ulang: *"beneran bisa dipakai apa cuma gimmick"*, dan
apakah ada sheet master yang kelewat. Ketiga workbook dibongkar sheet demi sheet (19 + 15 + 9)
dan jalur pakainya dijalankan ujung-ke-ujung.

**Olah datanya bersih.** Tiap komponen budget diadu ulang ke kolom `ui` master, bukan cuma angka
akhirnya: Thermocouple 9 komponen, Termometer Gelas 11, Thermohygro 6 × 3 grup. Semuanya sama
sampai digit terakhir, termasuk kejanggalan yang gampang kelewat — komponen drift chamber **GEA**
punya pembagi (√3) DAN `vi` (200) yang beda dari chamber **Biobase** (0,866 dan 10⁶) padahal
`U`-nya sama-sama 0,635. `Uc` ketiga grup cocok sampai 10 desimal.

**Nol sheet kelewat.** Dua yang tidak diekstrak, dua-duanya benar:

| Sheet | Kenapa tidak dipakai |
|---|---|
| `STANDAR-VICTOR` | Sertifikat kalibratornya kedaluwarsa **25 Sep 2021**; Victor memang tidak ditawarkan ketiga profil |
| `gea_suhu` (blok suhu chamber GEA) | Blok suhu master memakai angka Biobase (0,5 / 0,1), jadi "suhu selalu Biobase" di kode memang ikut master |

Satu temuan data diangkat ke lab sebagai **K12** (sheet dryblock A berisi data blok B).

**Bug yang ketemu: hitung ulang mati buat ketiga alat.** Setiap titik di setiap sesi ketiga alat
pulang sebagai `hitung_ulang_gagal` — padahal datanya lengkap di database. `CalibrationValidator`
menyusun `konteks` buat hitung ulang, dan ketiga alat ini tidak kebagian kuncinya
(`alat_bantu`, `titik_es`, pasangan `standar`/`uut`, `no_probe`, `parameter`).

Ini kejadian **kelima** dengan pola yang sama — sesudah Viscometer, Gas Detector, TITS, dan
Enclosure — dan bahayanya sudah ditulis di kelas itu sendiri: peringatan yang selalu muncul
melatih admin menekan "setujui tetap" tanpa membaca. Yang hilang bukan cuma ketenangan layar,
tapi pemeriksaan "apakah angka tersimpan masih bisa direproduksi" — mati total buat ketiga alat.

Diperbaiki lewat `App\Support\PasanganStandarUutMentah`, saudaranya `GridSensorMentah`, dipakai
**dua** jalur hitung ulang (validator + `kalibrasi:hitung-ulang`). Sesudahnya ketiga sesi contoh
pulang `hitung_ulang_gagal = 0` **dan** `hitung_ulang_beda = 0` — jalan, dan hasilnya sama dengan
yang tersimpan.

> **Jebakan yang nyaris lolos, dan pantas dicatat.** Perbaikan sisi perintah sempat SALAH dan
> test-nya tetap hijau. Sebabnya `GridSensorMentah::dari()` balik `[]` cuma kalau tidak ada baris
> ber-`peran_sensor` sama sekali — dan baris ketiga alat suhu PUNYA `peran_sensor`, cuma
> kosakatanya lain (`standar`/`uut`). Jadi cabang `$grid === []` tidak pernah kena, tiap titik
> di-`continue`, dan perintahnya "sukses" tanpa menghitung apa pun. Angkanya kelihatan utuh
> karena memang tidak pernah disentuh. Test-nya sekarang MERUSAK satu angka dulu sebelum
> menjalankan perintah: angka yang dirusak cuma balik kalau perintahnya beneran menghitung.

#### Kejadian keenam sudah dicegah, bukan ditunggu (27 Agt 2026)

Lima kali pola yang sama, dan lima kali ditutup dengan test yang menyebut alatnya **satu per
satu** — jadi tiap kali alat berikutnya jatuh ke lubang yang sama, karena tidak ada yang
mengingatkan. `HitungUlangTigaAlatSuhuTest` sendiri cuma menyebut tiga nomor sesi; alat ke-21
tidak akan kesapu olehnya.

`HitungUlangSemuaSesiTest` menutup pengulangannya: daftarnya **seluruh sesi ter-seed yang punya
titik terhitung**, diambil dari database, bukan diketik. Hari ini 18 sesi, dan tiga penanda
ditegakkan sekaligus — `hitung_ulang_gagal`, `hitung_ulang_beda`, `keputusan_titik_beda`.

Yang kedua dan ketiga bukan hiasan. `hitung_ulang_gagal` nol cuma membuktikan hitung ulangnya
JALAN; konteks yang **salah isi** (dryblock B dikirim untuk sesi dryblock A) tetap lolos di situ —
hitung ulangnya sukses, angkanya saja yang meleset, dan itu justru yang tidak kelihatan sebagai
kegagalan.

Dibuktikan menggigit dengan mematikan `PasanganStandarUutMentah::dari()`: ketiga sesi suhu langsung
merah, **disebut nomor sesi dan nama alatnya**, bukan cuma "ada yang gagal".

Test per-alat yang sudah ada TIDAK diganti — keduanya menegakkan hal yang lebih dalam (bentuk yang
disusun ulang beneran dari baris mentah; perintah `kalibrasi:hitung-ulang` memulihkan angka yang
sengaja dirusak). Yang baru ini lantainya.

### Susulan 27 Agt — form Tambah Alat: yang perlu saja, sisanya dari data PT Sidik

Permintaan pemilik proyek sambil menunjukkan tangkapan layar form "TAMBAH ALAT" dengan kolom
TOLERANSI dilingkari:

> *"pas nambah alat itu cuma yang perlu aja kalo yang penting pentign itu bisa kita bikin jadi
> ootmatis masuk ke dalam sistem … toleransi dan juga rentang min dan juga maks nya kan di
> tentukan sama si PT sidik dan juga kan udah ada di exel itu … dan juga kalo misal nya nanti
> beda bisa tuh yang otomatis itu di edit manual"*

**Toleransi berhenti diwajibkan buat alat yang tidak divonis.** Form itu meminta `toleransi` untuk
SEMUA alat, alasannya ditulis di kodenya sendiri: *"alat tanpa toleransi nggak bisa dikalibrasi —
422 belakangan"*. Alasan itu keliru untuk **15 dari 20** profil — Conductivity, Spectro, Autoklaf,
DO, Gas Detector, TITS, TIDS, kelima Enclosure, dan ketiga alat suhu berhenti di `U95%` tanpa batas
keberterimaan — dan `CalibrationValidator::periksaKelengkapanHitung()` memang melewatinya
(`$profilAlat?->punyaToleransi() !== false`), jadi 422 yang ditakutkan itu tidak pernah datang.

Yang datang justru sebaliknya: teknisi dipaksa **mengarang angka toleransi** untuk alat yang tidak
divonis — mengarang kriteria kelulusan. Mengisi kolom itu pernah mematikan seluruh sesi
Conductivity.

Jawabannya sekarang dituturkan server: `punya_toleransi` per baris kemampuan di
`GET /api/categories/{kode}`, lahir dari `CalibrationProfileRegistry` — bukan daftar nama alat
yang disalin ke HP. Alasan yang sama persis dengan `profil` di baris yang sama: **profil ke-21
ikut terjawab tanpa rilis APK baru.** Field yang tidak ada (server lama) dibaca `true`, yaitu
perilaku lama — salah di sisi yang aman.

**Rentang & satuan terisi sendiri dari lampiran akreditasi.** Angkanya sudah dikirim server setiap
kali kategori dibuka; teknisi tidak perlu menyalinnya lagi dari kertas. Yang perlu diputuskan cuma
satu, dan keputusannya menentukan benar/salahnya angka di sertifikat:

**Baris satu `nama_alat` bisa beda SATUAN, dan yang beda satuan tidak boleh digabung.**

| `nama_alat` | baris master | hasil |
|---|---|---|
| Thermocouple | −20–150, 150–400, 400–600 °C | −20–600 °C — **terisi otomatis** |
| Termometer Gelas | 0–100, 100–200 °C | 0–200 °C — terisi otomatis |
| Thermohygrometer | Suhu 15–50 °C · Kelembapan 30–90 %RH | **dua tombol**, teknisi yang menekan |
| Autoklaf | Suhu 105–121 °C · Tekanan 0–4 bar | dua tombol |
| Mesin UTM | 0–500 kgf · 10–3000 kN | dua tombol |

Kolom `range_min`/`range_max` alat cuma **sepasang**, jadi memilihkan salah satu berarti menebak
besaran mana yang dimaksud lembarnya. Menggabungnya lebih buruk lagi: Thermohygro jadi "15–90
tanpa satuan", Autoklaf jadi "0–121" — angka yang tidak ada di master mana pun, dan tidak ada satu
pun yang menolaknya. Dia lolos ke kolom alat, ikut ke lembar kerja, ikut ke sertifikat. Jadi yang
satu satuan diisi sendiri; yang lebih dari satu **disodorkan sebagai tombol** berikut angkanya.

Batas yang bukan angka tetap kosong: Oven `range_min`-nya "ambient", dan nol itu **suhu**, bukan
"tidak ada angkanya".

**Yang sudah diketik teknisi tidak pernah ditimpa.** Isian otomatis cuma menyentuh kotak kosong
atau kotak yang isinya persis angka yang kita taruh sendiri terakhir kali — jadi ganti pilihan
tetap memperbarui angkanya, tapi alat pelanggan yang rentangnya memang lain aman.

> **Lubang yang ikut ketutup:** `MockCategoryService` tidak pernah punya baris kemampuan untuk
> ketiga alat suhu baru. Sama persis dengan yang dulu terjadi pada Viscometer, Spectrophotometer,
> dan TITS: di build `USE_MOCK=true` kartunya tidak muncul di picker, jadi ketiga lembarnya —
> yang sudah jadi dan teruji — tidak bisa dibuka lewat jalur mana pun.

### Susulan 27 Agt — pemilih pelanggan: nama besar, alamat kecil, tinggal ditekan

Permintaan yang sama:

> *"kan ini PT nya dari indoenesia semua nay … nama pt nya gede terus bawah nya alamat nya kecil
> terus tinggla di pencet aja"*

Tampilannya bagian yang gampang. Yang ditemukan waktu mengerjakannya jauh lebih berat: **daftar
pelanggan di form Tambah Alat selama ini ditarik dari `GET /api/arsip/perusahaan`, yang me-list
FOLDER, bukan pelanggan.**

| | Akibatnya |
|---|---|
| `id` yang datang itu **id folder** | Folder id 1 bisa milik pelanggan id 3. `pelanggan_id` yang terkirim **sah** tetapi menunjuk PT LAIN — alatnya tersimpan ke pelanggan yang salah, nol error di sepanjang jalur |
| Folder hanya ada untuk PT yang pernah punya sertifikat | Pelanggan **baru** — justru yang paling sering diinput — tidak muncul sama sekali |
| Daftarnya disaring lagi per-role | Teknisi biasa hanya diberi folder yang ada berkasnya untuk dia; sering **nol baris** — persis dead-end 403 yang dulu mau dihindari |
| `?search=` diabaikan | Server itu membaca `q`. Daftarnya kembali utuh tiap ketik, terlihat seperti pencariannya rusak |

Yang pantas dicatat: **koreksi ini sudah tertulis di `docs/kontrak-api.md` sejak 25 Juli 2026**,
lengkap dengan keempat baris tabel di atas, dan `GET /api/customers/lookup` sudah live hari itu
juga. Yang tidak pernah terjadi cuma satu: sisi mobile-nya tidak pernah pindah. Jadi keempatnya
berjalan di APK selama sebulan penuh — dokumen yang benar tidak memperbaiki kode dengan
sendirinya.

Sekarang `ApiCustomerLookupService` menembak `/customers/lookup`: `customers.id` yang benar,
`alamat` ikut, pelanggan baru muncul, teknisi dapat daftar penuh. Satu tambahan di sisi API —
`?search=` mencari **nama ATAU alamat**, kurungnya eksplisit supaya saringan organisasinya tidak
bocor ke lab sebelah. Itu cara teknisi mengingat pelanggannya: satu kawasan industri berisi
belasan PT bernama mirip, dan yang dia pegang alamat penjemputannya.

### Audit ulang 27 Agt — pola yang sama KAMBUH di layar Arsip

Pemilik proyek minta dicek lagi: *"takutnya masih ada yang kelewat"*. Tiga temuan, dan yang
pertama bentuknya persis sama dengan yang barusan diperbaiki.

**1 · Layar Arsip membuka arsip PT yang salah.** `GET /arsip/perusahaan` me-list FOLDER;
`ArsipPerusahaan.fromJson` membaca `json['id']` (id folder) dan layarnya mengirimkannya ke
`GET /arsip/perusahaan/{customer}/folder`, yang ngiket ke `Customer`. Diuji tiga PT: **dua kebuka
arsip PT lain** — status 200, nol error, judulnya tetap nama PT yang dipencet, isinya sertifikat
& alat milik pelanggan lain.

Ini kejadian **kedua** dengan bentuk yang sama dalam satu hari. Yang pertama pemilih pelanggan di
form Tambah Alat, yang juga menarik id folder dari endpoint yang sama. Satu endpoint salah
dipahami, dua layar terkena — dan keduanya diam.

Sekarang tiap baris membawa `pelanggan.id` sendiri, dan `ArsipPerusahaan` menyimpan dua id
terpisah: `id` (folder) dan `pelangganId`. Folder akar tanpa pelanggan (`customer_id` boleh
kosong) dibuka sebagai folder biasa lewat `id`, bukan ditebak ke pelanggan.

**2 · Alamat di kartu PT tidak pernah muncul.** Kartunya sudah menggambar nama tebal + alamat
kecil di bawahnya, tapi `FolderResource` tidak pernah mengirim `alamat`, dan modelnya membacanya
dari tingkat atas. Jadi cabang itu tidak pernah menyala, dan tidak ada satu pun error. Sekarang
`pelanggan.alamat` ikut dikirim.

**3 · Dua alat kelewat di tabel vonis mock.** `DO Meter` dan `Gas Detector` masih
`punyaToleransi: true` di `MockCategoryService` padahal registry bilang `false` — di build
`USE_MOCK=true` dua alat itu masih memaksa teknisi mengisi toleransi yang masternya tidak punya.
Ketahuan waktu mengadu seluruh 21 nama alat di mock satu per satu ke registry.

> **Yang dipetik.** Salinan tulis tangan ketinggalan tanpa bunyi — itu alasan yang sama yang
> dipakai menaruh `punya_toleransi` di server, dan tabel di mock ini melanggarnya sendiri. Karena
> mobile tidak bisa memanggil registry PHP, penjagaannya sekarang `VonisToleransiMockTest`: tabel
> vonis dipatok eksplisit, dan **nama alat baru yang tidak ada di tabel itu bikin test MERAH** —
> bukan diam-diam ikut bawaan `true`.

### Audit ulang 27 Agt (2) — layar Arsip nggak bisa membuka folder sama sekali

Pemilik proyek minta dicek sekali lagi. Yang ketemu jauh lebih besar dari empat temuan sebelumnya,
dan bentuknya beda: bukan angka yang salah, tapi **dua repo yang bicara bahasa berbeda.**

`GET /arsip/folders/{id}` mengirim `{data: {…, sub_folder[], file[]}}`. Parser mobile membaca
`{folder, subfolder, data[]}` — nggak satu pun kunci itu ada. Yang menentukan: `json['data']` yang
sebenarnya OBJEK bikin `as List` di Dart **melempar**, jadi **tiap folder yang dibuka gagal** dan
layarnya berhenti di pesan error. Dibuktikan dengan menyuapkan respons rekaman asli ke parser-nya:

```
NGELEMPAR: type '_Map<String, dynamic>' is not a subtype of type 'List<dynamic>?'
```

Hidup di APK rilis: `apk-rilis-cloud.yml` & `rilis-desktop.yml` nggak menyetel `USE_MOCK`, jadi yang
jalan `ApiArsipService`. Layarnya kejangkau dari Profil dan navbar desktop.

**Kenapa nol test menangkapnya, di kedua repo.** Semua test arsip lewat `MockArsipService`, yang
membangun `ArsipIsiFolder` lewat konstruktor. Parser JSON-nya nggak pernah sekali pun dilewati —
mock yang memulangkan objek jadi memang nggak bisa menguji pembacaan JSON. Itu bukan kelalaian
kecil; itu lubang berbentuk kelas, dan kelas yang sama masih dipakai di tempat lain.

**Keputusan pemilik proyek: dua-duanya menyesuaikan, seperlunya.**

| Sisi | Yang berubah |
|---|---|
| **API** | Tambah `breadcrumb` (cuma di tampilan isi folder, bukan di daftar akar), dan lengkapi `lembar_kerja` tiap berkas dengan `equipment`, `teknisi`, `tanggal_kalibrasi`, `keputusan` |
| **Mobile** | Ikut penamaan server: envelope `data`, `sub_folder`, `file`. `is_root` diturunkan dari `parent_id`. `ArsipBerkas.id` diambil dari `lembar_kerja.calibration_session_id` |

Pembagiannya bukan selera: yang ditambahkan di API cuma yang **mustahil diturunkan di HP** — nama
folder induk ada di baris induk yang nggak ikut terkirim, dan empat field kartu itu nggak ada di
baris `folder_files` sama sekali. Sisanya penamaan, dan penamaan lebih murah diikuti klien.

Penjaganya sekarang `BentukIsiFolderArsipTest` (API) dan `arsip_bentuk_asli_test.dart` (mobile,
menyuapkan **JSON rekaman respons sungguhan**, bukan objek buatan mock).

> **Jebakan yang nyaris lolos.** Dua dari empat penjaga di sisi mobile awalnya **nggak gigit**:
> fixture-nya kebetulan punya id sesi = id berkas (jadi "pakai id yang salah" nggak kelihatan
> bedanya), dan folder yang dipakai kebetulan bukan akar (jadi `is_root` yang diturunkan maupun
> yang dibaca dari kunci-yang-nggak-ada sama-sama `false`). Ketahuannya cuma karena tiap penjaga
> dibalikin dulu ke bentuk lama dan dicek beneran merah. **Fixture yang nggak bisa membedakan itu
> test yang lulus tanpa menguji apa pun** — dan lebih berbahaya daripada nggak ada test, karena
> dia bikin orang berhenti mencari.

**Sumber nama PT di luar data PT Sidik: SUDAH DIPILIH** (29 Agt 2026) — lihat K16. Tidak ada API
resmi & gratis untuk daftar perusahaan Indonesia: AHU (Kemenkumham) memegang data PT terdaftar tapi
tidak membuka API publik, dan OSS/BKPM hanya untuk mitra berizin. Yang tersedia sumber peta.

**Penyedianya diganti 31 Agt 2026 atas keputusan pemilik proyek: nol tagihan.** Yang gratis dan
bisa dipakai tanpa kunci cuma **OpenStreetMap lewat Nominatim**, dan itu yang jadi bawaan sekarang.
Google Places tetap ada di balik satu setelan (`DIREKTORI_PERUSAHAAN_DRIVER=google`) — antarmuka
`DirektoriPerusahaan` memang dipasang untuk ini, jadi controller, layar HP, dan bentuk datanya nol
berubah.

> **Keputusan itu baru benar-benar mendarat di kode 1 Sep 2026.** Paragraf di atas sudah ditulis
> 31 Agt, tapi nilainya tertinggal di `auto` (Google duluan) di TIGA tempat sekaligus —
> `config/services.php`, `.env.example`, dan `render.yaml` — jadi Places tetap ditembak tiap
> teknisi menekan cari. Itu yang jadi tagihan Google Cloud yang masuk 1 Sep 2026.
>
> Pelajarannya, dan alasan catatan ini nggak dihapus: **dokumen yang bilang "sekarang bawaannya X"
> bukan bukti bahwa bawaannya X.** Yang mengikat cuma nilai di berkas setelan dan test yang
> mengadunya. Sekarang keduanya ada — lihat `test_bawaannya_osm_dan_tetap_siap_tanpa_key`.
>
> Ikut dibetulkan sekalian: nilai yang **tidak dikenali** dulu jatuh ke susunan berlapis, yang ikut
> membangun `GooglePlacesDirektori`. Artinya satu huruf yang meleset di `.env` (`osmm`) menyalakan
> lagi jalur berbayar tanpa satu pun error. Sekarang jatuhnya ke `osm`.

Harganya nyata dan sudah diterima: cakupan OSM **lebih tipis**, jadi pabrik di kawasan industri
yang belum ada yang memetakan memang tidak akan ketemu. Lapis 3 ada justru untuk itu.

### `DIREKTORI_PERUSAHAAN_DRIVER` SENGAJA dipatok `value:` di blueprint — jangan "dibetulkan"

**Keputusan pemilik proyek 2 Sep 2026.** Ditulis di sini justru karena membalikkannya kelihatan
seperti perbaikan.

`render.yaml` menulis `value: osm`, artinya **blueprint yang menang**: tiap sync, nilai itu menimpa
apa pun yang diketik di dashboard Render. Aturan umum yang lahir dari insiden `ARSIP_DRIVER`
(PR #145) berbunyi *"apa pun yang diputuskan operator, bukan kode, jangan dipatok `value:` di
blueprint"* — jadi siapa pun yang membaca aturan itu lalu melihat baris ini akan mengira ini
pelanggaran yang belum dibereskan.

**Bukan.** Aturan yang sama menghasilkan jawaban berbeda karena yang dipatok berbeda sifatnya:

| | Kalau blueprint menang | Akibatnya |
|---|---|---|
| `ARSIP_DRIVER: local` | menimpa `s3` | **berkas arsip hilang** tiap deploy |
| `DIREKTORI_PERUSAHAAN_DRIVER: osm` | menimpa `auto`/`google` | cakupan pabrik lebih tipis |

`ARSIP_DRIVER` dipatok ke nilai yang **merusak**; yang ini dipatok ke nilai yang **aman**.

Yang lebih menentukan, bandingkan bentuk kegagalannya:

- **Dengan `value: osm`** → gagalnya *kelihatan*: ada yang mencoba menyalakan Google, lalu balik
  sendiri sesudah deploy. Menjengkelkan, nol rupiah.
- **Dengan `sync: false`** → gagalnya *diam*: `auto` tertinggal di dashboard, tidak ada yang
  menimpanya, tagihan jalan. **Itu persis yang sudah terjadi**, dan yang menemukannya tagihan
  Google Cloud — bukan seorang pun.

Kegagalan yang kelihatan jauh lebih murah daripada kegagalan yang diam.

**Harga yang sudah diterima:** kalau suatu hari OSM memblokir alamat IP server dan pencarian
direktori mati, jalur cepatnya **tidak bisa** dipindah ke Google lewat dashboard — harus ubah
`render.yaml` lalu deploy. Dampaknya terbatas: pendaftaran manual dan pencarian master lab tetap
jalan penuh, jadi yang hilang jalan pintasnya, bukan kemampuan kerjanya.

Mana yang sebenarnya menang bisa diperiksa tanpa dashboard:
`curl -s https://<domain>/api/health | jq .direktori_perusahaan` → `"bisa_ditagih": false` = aman.

Bentuknya **internal dulu, direktori luar sebagai jalan keluar** — bukan salah satunya:

| Lapis | Yang dipakai |
|---|---|
| 1 | `GET /customers/lookup` — master lab, gratis, instan. Sejak 29 Agt tahan tanda baca (`nama_normal`). Sejak 31 Agt daftar utuhnya disalin ke HP, jadi lapis ini tetap hidup waktu server tak terjangkau |
| 2 | `GET /customers/direktori` — proxy ke OpenStreetMap (bawaan) atau Google. Dipanggil hanya kalau teknisi menekannya |
| 3 | Ketik tangan — pabrik yang tidak pernah didaftarkan ke peta memang tidak akan ketemu |

Empat hal yang **tidak boleh dibongkar tanpa alasan baru**:

1. **Kredensial hidup di server, tidak pernah di APK.** Berlaku walau sekarang tidak ada kunci sama
   sekali: kalau penyedianya suatu saat ditukar balik ke yang berbayar, key di dalam aplikasi bisa
   dicabut siapa pun dari berkasnya lalu dipakai orang lain atas tagihan lab ini. Karena itu HP
   menembak `/customers/direktori`, bukan penyedianya langsung.
2. **"Belum disetel" ≠ "PT tidak ditemukan".** `503` (belum disetel) dan `502` (direktori mati)
   sengaja dipisah dari `200` + daftar kosong. Diratakan, teknisi membacanya sebagai PT-nya tidak
   ada di direktori lalu mendaftarkan ulang perusahaan yang sebenarnya ada di sana. Dengan driver
   `osm`, `503` berhenti pernah terjadi — tidak ada yang perlu disetel.
3. **Hasil direktori bukan data akta.** Nama & alamat di sana perusahaan sebagaimana muncul di
   peta. Selalu bisa disunting teknisi sebelum tersimpan, dan batas itu ditulis di layar — karena
   yang dipilih mendarat di blok OWNER sertifikat.
4. **Kewajiban ke Nominatim dijaga kode, bukan niat baik.** Layanan sukarela dengan kebijakan
   tegas, dan melanggarnya memblokir alamat IP server lab tanpa peringatan: User-Agent yang
   menyebut diri (dijamin `NominatimDirektori`, bukan setelan — setelan kosong mengembalikan
   string kosong, bukan `null`), limiter `direktori-luar` yang dihitung **global** bukan per-IP,
   bukan untuk autocomplete, dan atribusi ODbL yang ikut di badan respons.

**Yang MASIH tersisa:** satu uji nyata ke Nominatim dari server. Bentuk jawabannya di test ditulis
dari dokumentasi, bukan dari respons asli — jaringan lingkungan pengembangan tidak bisa menembus ke
`nominatim.openstreetmap.org`. Parsernya sengaja toleran, tapi itu bukan bukti.

---

## 12. Kamera tiap lembar kerja — audit 27 Agt 2026

Pemilik proyek: *"ini yang ada di dalam bagian kamera nya masing masing lembar kerja nya tolong
usahakan bisa karena tadi aku coba coba gk bisa, bisa sih bisa tapi kalo nangkap cuma berapa
table table aja sih."*

Ditelusuri, dan gejalanya persis apa adanya. Dari **20 lembar: 7 tidak punya tombol kamera sama
sekali, 3 punya tombol yang MUSTAHIL menghasilkan satu sel pun, dan 10 sisanya punya jalur jangkar
yang bisa ketemu.** Tiga sebab yang beda, dan cuma satu yang selama ini tercatat.

> **Batas klaim ini — dibetulkan 27 Agt 2026, karena versi sebelumnya di sini SALAH.**
>
> Yang dibuktikan seluruh pekerjaan §12 ini **PENEMPATAN, bukan PEMBACAAN**: kalau ML Kit
> memulangkan teks `"97,3"` di koordinat tertentu, angka itu pasti mendarat di sel yang benar —
> atau ditolak dan dilaporkan. Itu yang dijaga delapan berkas test foto.
>
> **Bahwa ML Kit bisa membaca tulisan tangan dari foto kertas: NOL bukti.** Kedelapan berkas test
> itu menyuapkan pasangan `(teks, kotak)` yang **ditulis test itu sendiri** — nggak satu pun lewat
> OCR beneran.
>
> Paragraf ini sebelumnya menulis *"yang diadu ke foto asli baru Viscometer dan Conductivity"*.
> Itu keliru, dan keliru di dua-duanya:
>
> | Aset | Isinya sebenarnya |
> |---|---|
> | `test/assets/tabel-viscometer-uji.png` | **Render komputer**, angka KETIKAN, lurus sempurna, latar putih bersih. Bukan foto |
> | `test/assets/lembar-conductivity-v1.png` | Template bermarker hasil `ocr:cetak-lembar` — **KOSONG**, nol angka di dalamnya |
>
> Nggak ada satu pun berkas foto (`.jpg`/`.heic`) di seluruh repo mobile, dan nggak ada satu pun
> citra bertulisan tangan. Jadi yang belum pernah diuji itu justru **satu-satunya hal yang
> menentukan fiturnya berguna atau tidak di lapangan.**
>
> **Kalau OCR-nya jelek, gagalnya ke arah mana.** Jangkar baris & kolom (`Set point 1`, `X1`,
> `0" (UUT1)`, `Temp. Disk 1`) itu TERCETAK, jadi besar kemungkinan kebaca; angkanya tulisan
> tangan. Hasil paling mungkin: tabel dikenali, sedikit/nol sel terisi, dan aplikasi bilang begitu
> — **bikin kesal, bukan bikin salah**. Yang berbahaya salah baca yang tetap berbentuk angka wajar
> (`4,04` → `404`, kasus yang docblock `ambil_foto_tabel.dart` sendiri sudah sebut). Penahannya
> tiga, semuanya heuristik: pemeriksaan beda orde (faktor 10), pemeriksaan satu Repeat menyimpang
> dari saudaranya, dan tiap sel hasil kamera **ditandai kuning "PERIKSA"**
> (`_isiSel(..., perluDicek: true)`).
>
> Jadi rancangannya: kamera itu **jalan pintas mengetik yang wajib dicek teknisi**, bukan sumber
> angka yang dipercaya. Klaim yang jujur buat pekerjaan ini **"jalur kameranya lengkap dan
> penempatannya benar di 20 dari 20 lembar"** — BUKAN "difoto langsung dapat angkanya".
>
> **Yang menyelesaikannya tetap F1: satu foto HP dari satu lembar yang sudah diisi tangan.**

### Yang sebenarnya jalan di HP hari ini — cuma SATU tombol

Ini yang paling gampang salah baca dari catatan lama, jadi ditulis eksplisit:

| Tombol | Statusnya |
|---|---|
| `PINDAI LEMBAR KERJA` (OCR template lokal, lembar bermarker) | **DICABUT PERMANEN** dari layar, 26 Agt 2026 |
| `FOTO TABEL INI` (ML Kit, satu jepretan per tabel) | satu-satunya yang tersisa |

Akibat yang perlu dicatat: **F1 sudah tidak menahan apa pun yang bisa disentuh teknisi.**
`terverifikasi` cuma menggerbangi jalur lembar bermarker, dan `PindaiReviewScreen` — layar review
per selnya — sekarang tidak pernah dibuka dari mana pun di aplikasi. Jadi "14 dari 20 lembar
`terverifikasi: false`" itu benar, tapi bukan sebab yang dirasakan pemilik proyek. Foto lembar
cetak tetap dibutuhkan kalau jalur bermarker suatu saat dipasang lagi; dia bukan blocker hari ini.

### Sebab 1 — tiga lembar yang tombolnya nyala tapi mustahil menghasilkan apa pun

`PetaTabelFoto` mengunci tiap angka ke DUA jangkar sebelum menaruhnya: nilai di kolom kiri
(baris), dan **tulisan kepala kolom** (kolom). Yang dicarinya `Xn`, `Repeat n`, atau deret nomor
polos. Tiga lembar tidak mencetak satu pun dari ketiganya:

| Lembar | Kepala kolom yang kecetak |
|---|---|
| TITS | `UP X1` `UP X2` `UP X3` `DOWN X1` `DOWN X2` `DOWN X3` |
| Thermocouple & Termometer Gelas (sisi standar) | `0″` `20″` `40″` `60″` `80″` |
| idem (sisi UUT) | `10″` `30″` `50″` `70″` `90″` |

Server **sudah** mengirim tulisan itu (`pengulangan_arah[].label`) dan layar **sudah**
menggambarnya sebagai kepala kolom — cuma pemetanya yang tidak pernah dikasih tahu.
`_kepalaPengulangan()` di HP membaca `prefiks_pengulangan` saja, dan cuma Spectrophotometer yang
mengirimnya. Jadi ketiganya pulang **nol sel** di tiap jepretan, sebagus apa pun fotonya.

Yang sampai ke teknisi bukan "kolomnya nggak kebaca" melainkan
*"tabelnya dikenali, tapi selnya masih kosong — isi dulu lembarnya"* — menyuruh dia mengisi lembar
yang sudah penuh di tangannya. Itu yang bikin gejalanya kebaca sebagai "kameranya gk bisa".

**Kenapa tulisannya tidak cukup dicocokkan apa adanya.** `MlKitPembacaHalaman` memulangkan hasil
OCR per **ELEMENT** — kira-kira per kata. `UP X1` tidak pernah datang utuh; yang sampai potongan
`UP` dan potongan `X1`. Dan `X1` kecetak DUA KALI di lembar TITS. Jadi selama `Xn` ikut jadi
calon, jangkar Repeat 1 bisa mendarat di kolom **DOWN**, dan yang masuk ke situ pembacaan arah
sebaliknya — tanpa satu pun error, dengan jumlah sel yang tetap pas dan angka yang tetap wajar.

Diperbaiki dua sisi sekaligus:

- `PetaTabelFoto` sekarang ikut mencocokkan **frasa** — gabungan elemen yang bersebelahan di baris
  yang sama (tumpang tindih tegak > ½ tinggi huruf, celah mendatar < 1 tinggi huruf). Kotaknya
  gabungan kotak keduanya, jadi jangkarnya duduk di tengah tulisan yang tercetak. Elemen aslinya
  tetap ikut, jadi kepala satu kata tidak berubah perilakunya.
- `_kepalaPengulangan()` memakai `pengulangan_arah[].label`, dan begitu labelnya bukan `Xn`,
  dia **sendirian** — bawaan `Xn`/`Repeat n` tidak boleh ikut bersuara. Label yang kebetulan `Xn`
  persis (Thermohygro, yang menerimanya sebagai nilai bawaan `tabelPembacaan`) digabung seperti
  dulu.

Dijaga `foto_tabel_kepala_tercetak_test.dart`. Dibuktikan menggigit: dengan frasa dimatikan,
jangkar kolom TITS `[]` — bukan berkurang, **nol**.

### Sebab 2 — kolom yang kepalanya kepotong menyedot kolom sebelahnya, diam-diam

Ini yang paling berbahaya dari ketiganya, dan berlaku di **semua** lembar, bukan cuma yang di
atas. `petakan()` memanggil `_kolomTerdekat()` **tanpa batas jarak**, padahal jalur ke-bawah
(Conductivity) sudah memakai batas setengah lebar kolom sejak lama.

Bawaan tanpa batas itu punya alasan yang ditulis di methodnya sendiri — "kolom Repeat selalu
berdampingan rapat, jadi yang paling dekat memang pemiliknya" — dan premis itu cuma berlaku waktu
SEMUA kolom kejangkar. Begitu satu kepala kolom kepotong dari frame, dia terbalik jadi bahaya:
angka di bawah kolom tanpa jangkar ditarik ke jangkar terdekat **sejauh apa pun**.

Akibatnya berlipat, dan dua-duanya senyap:

1. Angka mendarat di Repeat yang bukan miliknya.
2. Angka itu bentrok dengan angka sah di sel yang sama, lalu `_buangSelKembar` membuang
   **KEDUANYA** — jadi satu kepala kolom yang kepotong ikut menghapus kolom yang fotonya
   baik-baik saja.

Sekarang batasnya setengah lebar kolom, sama dengan jalur ke-bawah. Yang di luar itu DIBUANG dan
ikut kehitung `angkaTakTerpetakan`, jadi teknisi diberitahu ada yang tidak keangkut — persis janji
yang sudah tertulis di docblock `petakan`: *kolom yang kepalanya nggak kebaca nggak pernah keisi.*

### Sebab 3 — tujuh lembar belum punya jalur kamera sama sekali → **habis**

`pindai_foto.didukung = false` di **Autoklaf, TIDS, dan kelima Enclosure** (Oven, Bath, Inkubator,
Furnace, Refrigerator), dan penanda itu **tetap `false` sampai sekarang** — dengan benar: dia
menjawab pertanyaan "kertas alat ini muat di bentuk *titik ukur × Repeat* yang bisa dituturkan ke
pembaca foto CLOUD?", dan buat ketujuhnya jawabannya memang tidak. Yang menyalakan tombol kamera
di HP penanda yang LAIN (`pindai_foto.lokal`) — lihat susulan "satu penanda menggerbangi DUA hal"
di bawah, dan kenapa keduanya sempat jadi satu.

Yang dikerjakan bukan membalik penanda itu, tapi **memberi dua bentuk kertas itu jangkar barisnya
sendiri**:

| Kertas | Sumbu | Jangkar barisnya sekarang |
|---|---|---|
| **Grid** (kelima Enclosure) | set point × termokopel × pengulangan | **nomor termokopel yang DIBACA DARI FOTO**; sumbu KETIGA-nya dari blok tempat tombolnya ditekan, bukan dari citra |
| **Matriks** (Autoklaf) | besaran × titik waktu | **tulisan nama besaran** (`Temp. Disk 1`, `Indikator Pressure`) di kolom kiri |
| **Dua tabel interval** (TIDS) | set point × UUT | **tulisan `Set point 1`…`Set point 7`** di kolom kiri; kolomnya dari `0" (UUT1)`…`90" (UUT5)` yang sudah dikirim `pengulangan_uut[].label` |

> **Keputusan pemilik lab, 27 Agt 2026: teknisi MOTRET DULU, nomornya belakangan.** Rancangan
> pertama menjangkar baris grid ke nomor termokopel yang sudah diketik di layar — dan itu salah
> untuk urutan kerja yang sebenarnya: waktu tombolnya ditekan, layarnya memang masih kosong.
> Nomornya sekarang dibaca dari kolom `No.` di fotonya.
>
> Risikonya nyata dan ditanggung sadar: nomor yang salah baca memindahkan SELURUH baris ke
> termokopel yang salah, dan nomor itu yang menentukan koreksi mana yang dipakai. Yang bikin dia
> bisa ditanggung — **nomornya ikut ditaruh di kotaknya sendiri dan ikut ditandai kuning**, jadi
> kelihatan dan bisa dibetulkan di satu tempat; membetulkannya memindahkan barisnya utuh.
> Yang dijamin utuh **kebersamaan satu baris**, bukan ketepatan nomornya.

Dua hal yang bikin ini aman, dan dua-duanya aturan yang sudah berlaku di seluruh pemeta:

1. **Baris yang jangkarnya nggak kebaca nggak pernah keisi.** Termokopel yang nomornya belum
   diketik, atau baris matriks yang namanya kepotong dari frame, dilewat — bukan ditarik ke baris
   terdekat. Yang kebuang dilaporkan sebagai "ada yang nggak keangkut".
2. **Sumbu yang nggak bisa dibaca aman dari citra diambil dari LAYAR.** Aturan yang sama sudah
   dipakai lembar Conductivity buat slot bersatuan dobel: yang dituju titik yang lagi dicentang
   teknisi, bukan ditebak dari angka yang kebaca.

Baris `Time` di matriks Autoklaf ikut jadi jangkar tapi **tidak pernah diisi**: isinya jam
(`HH:mm:ss`), bukan hasil ukur. Dia ikut justru supaya angka yang kebetulan jatuh di barisnya
diklaim lalu dibuang — bukan melayang ke baris `Temp. Disk 1` di bawahnya.

Dijaga `foto_grid_enclosure_test.dart` (10 test) & `foto_matriks_autoclave_test.dart` (5 test).
Dua-duanya dibuktikan menggigit: penanda baris matriks dikembalikan ke `titik_ukur` aslinya (nol
semua) → **0 dari 5 baris kejangkar**; jalur label kata dimatikan → baris `Indikator` & `Suhu
Ruang` grid hilang, 15 dari 25 sel.

### Hitungannya sebelum & sesudah

| | Sebelum | Sesudah |
|---|---|---|
| Punya jalur jangkar yang bisa ketemu | **10** dari 20 | **20 dari 20** |
| Tombol nyala tapi mustahil dapat satu sel pun | 3 (TITS, Thermocouple, Termometer Gelas) | 0 |
| Belum punya jalur kamera sama sekali | 7 | **0** |

### ~~K18~~ — lembar TIDS terbuka dengan NOL baris — **DIJAWAB & DIKERJAKAN 27 Agt 2026**

Ketemu waktu menyiapkan jalur kamera buat ketujuh lembar itu, dan jauh lebih mahal daripada yang
dicari.

`TidsProfile` mengirim `titik_ukur: null` di **ketujuh** barisnya, di dua tabel sekaligus — dan itu
disengaja serta terdokumentasi: kertasnya mencetak tujuh baris set point KOSONG, jadi angkanya
ditentukan teknisi di lapangan (`titik_bisa_diubah: true`).

Sisi HP membacanya `(json['titik_ukur'] as num).toDouble()` — **cast keras**. Baris ber-null bikin
dia melempar, `parseListAman` menelan lemparannya, dan barisnya **dilewat diam-diam**. Jadi lembar
TIDS terbuka dengan dua kepala tabel dan nol kotak isian, tanpa satu pun error.

| Yang dicek | Hasil |
|---|---|
| `TabelHasil.fromJson` disuapi baris TIDS asli | `baris` → **`[]`** |
| Kenapa nggak ketangkap penjaga | `MockLembarKerjaService` **nggak punya bentuk TIDS sama sekali** — satu-satunya sumber bentuknya server, dan nggak ada test yang menyuapkan bentuk aslinya ke parser |
| Kelas kegagalannya | sama persis dengan `CalibrationHistoryItem`: *"draf tanpa tanggal cast-nya melempar, `parseListAman` nelen lemparannya, dan barisnya DILEWAT diam-diam"* |

**Pemilik proyek memilih A** (27 Agt 2026): tujuh baris tetap digambar, dan **tiap baris punya
kotak `Setpoint` sendiri** yang diisi teknisi — persis kertasnya.

Kenapa pilihan itu tidak bisa diambil sambil ngoding: `titikUkur` di HP yang dikirim sebagai
`measurements[].titik_ukur`. Menambal parser dengan memberi baris null sebuah angka (nomor
barisnya, 1–7) **membuat bug yang lebih buruk** — set point sesi terkirim sebagai "1 °C … 7 °C",
angka yang tidak pernah diketik siapa pun dan tidak ditolak apa pun.

Yang dikerjakan:

- Angkanya tetap ada sebagai **penanda posisi** (baris butuh identitas buat dibedakan dari enam
  tetangganya), ditandai `titikDitentukan: false`. Yang menentukan apa yang DIKIRIM
  `TitikState.titikUkurEfektif`, dan baris yang kotaknya dibiarkan kosong tidak ikut dikirim
  sama sekali.
- `PengaturTitik` tidak digambar untuk lembar begini: dua jalan mengisi satu hal yang sama bikin
  teknisi tidak tahu yang mana yang berlaku.
- Set point yang sudah diketik ikut selamat waktu tabelnya dibangun ulang.

Dijaga `tids_baris_tanpa_titik_test.dart` (8 test), dibuktikan menggigit dengan mengembalikan cast
lamanya: baris → `[]`.

### Dua lubang lain yang ketemu di lembar yang sama

Keduanya baru **hidup** sesudah barisnya ada — sebelum ini tabelnya kosong, jadi tidak ada yang
bisa hilang. Ditutup di commit yang sama, bukan ditinggal sebagai utang:

**1 · Kepala kolom TIDS tidak pernah kejangkar.** Kertasnya mencetak `0" (UUT1)`…`90" (UUT5)`, dan
server sudah mengirimnya di `pengulangan_uut[].label` — tapi sisi HP cuma membaca
`pengulangan_arah`. Kelas kegagalan yang sama persis dengan TITS & dua lembar suhu di §12 sebab 1.
Sekarang dua kunci itu dibaca ke peta yang sama.

**2 · Tabel `Pembacaan Standard` isinya tidak punya tempat di server.** Backend menyatakannya
eksplisit lewat `simpan_ke: null`, lengkap dengan peringatan di docblock-nya: *"Layar HP wajib
membaca kunci ini sebelum menyalakan tombol kirim untuk tabel ini — kalau tidak, teknisi mengisi
35 kotak yang hilang tanpa pesan apa pun."* **HP tidak pernah membacanya.**

Kotaknya tidak dimatikan — teknisi memang mencatat deret itu di kertas, dan layar yang menolak
angka yang sudah ada di tangannya lebih membingungkan daripada layar yang jujur. Yang ditambah
keterangannya, **di ATAS tabelnya**: yang membaca setelah mengisi 35 kotak sudah terlambat diberi
tahu.

Baru sesudah ketiganya beres `TidsProfile::bentukPindaiFoto()` dinyalakan — lewat `lokal: true`,
lihat susulan di bawah. Menyalakannya lebih dulu cuma menghasilkan tombol yang tiap jepretannya
nol sel — dan, lebih buruk, kamera yang mempercepat pengisian kotak yang memang belum punya
tempat.

### Susulan: satu penanda menggerbangi DUA hal — dan salah satunya mengirim foto pelanggan keluar

Ketemu waktu review PR, dan ini **regresi yang beneran kelepas**, bukan temuan teoretis.

Tombol kamera TIDS dinyalakan dengan menaikkan `pindai_foto.didukung` — satu-satunya gerbang yang
ada waktu itu. Yang ikut kebawa: penanda yang sama juga menggerbangi
`POST /raw-measurements/extract-from-photo`, endpoint AI Vision **yang mengirim foto lembar kerja
pelanggan ke layanan pihak ketiga** (Gemini/Anthropic). Jadi menyalakan kamera on-device buat satu
lembar diam-diam bikin lembar itu **memenuhi syarat dikirim keluar** begitu Vision di server nyala.
Tidak ada yang berniat begitu; gerbangnya cuma kebetulan satu.

Penjaga yang ada tidak menangkapnya karena cuma menguji **Autoklaf**, satu-satunya lembar yang
`didukung`-nya memang `false` waktu test itu ditulis.

Dibetulkan dengan **memisahkan gerbangnya**, bukan menerima pelebarannya:

| Penanda | Menggerbangi | Pertanyaannya | TIDS |
|---|---|---|---|
| `didukung` | `raw-measurements/extract-from-photo` — **foto keluar HP** | "kertas ini muat di bentuk *titik ukur × Repeat* yang bisa dituturkan ke pembaca cloud?" | **`false`** (tetap) |
| `lokal` | tombol `FOTO TABEL INI` — ML Kit, **sepenuhnya di perangkat** | "pemeta di HP bisa menjangkar baris & kolom kertas ini?" | **`true`** |

Bawaan `lokal` mengikuti `didukung`, jadi tujuh belas profil yang tidak menyebutnya tidak berubah
perilakunya, dan APK baru yang ketemu server lama (cuma mengirim `didukung`) tetap jalan. Yang
perlu memisahkan cuma profil yang jalur lokalnya hidup sementara bentuk dua-penandanya tidak — dan
profil begitu wajib menyebut **dua-duanya**, supaya pilihannya tertulis, bukan tersirat.

Empat penjaga baru berdiri di jalur itu, dan yang pertama yang paling penting:

| Penjaga | Yang ditegakkan |
|---|---|
| `WorksheetExtractionTest::test_tiap_lembar_tak_didukung_ditolak_sebelum_foto_keluar` | **Sapuan seluruh registry**: tiap profil ber-`didukung: false` ditolak 422 sebelum HTTP apa pun keluar (`Http::assertNothingSent()`). Lantai 7 profil |
| `BentukPindaiFotoCocokTabelTest` | Lembar tanpa tabel wajib mematikan **dua-duanya**, bukan salah satu |
| `LembarKerjaTest` + `TidsLembarKerjaTest` | Isi `pindai_foto` diadu utuh; kunci yang hilang atau nambah bikin merah |
| `pindai_ui_nyala_test.dart` (grup `gerbang lokal vs cloud`) | Di HP: `didukung: false` + `lokal: true` tombolnya **tetap ada**; `didukung: true` + `lokal: false` tombolnya **hilang** |

`WorksheetExtractionController::bentukKertas()` **membuang** `lokal` yang ikut pulang dari profil —
itu inti pemisahannya, dan alasannya ditulis di docblock-nya supaya tidak disatukan lagi.

### Susulan: angka TIDS yang diketik teknisi nggak pernah nyampe server

Ketemu waktu menulis test bolak-balik buat temuan review di atas — dan ini yang
**paling dalam dari semuanya**, karena dia bikin seluruh pekerjaan kamera TIDS
sia-sia tanpa satu pun gejala.

Kunci sel tiap tabel di HP itu `TabelHasil.kunciTabel`, isinya `tahap` yang
dikirim backend. Sembilan belas lembar mengirim `sesudah_adjustment`. Lembar
TIDS mengirim **`pembacaan_uut`**. Payload-nya sendiri dirakit
`TitikState.toSubmission()` dari kunci **MATI** `sesudah_adjustment`.

Dua sisi itu tidak pernah bertemu, dan yang terjadi bukan error:

| | |
|---|---|
| Yang diketik teknisi masuk ke | `pembacaan_uut\|pembacaan\|i` |
| Yang dibaca perakit payload | `sesudah_adjustment\|pembacaan\|i` — tidak pernah ada |
| Yang terkirim ke server | set point yang benar, `pembacaan` **null semua** |

Lembarnya penuh di layar, tombol kirimnya jalan mulus, kameranya mengisi tiga
puluh lima kotak — dan tak satu pun angka itu ada di server. Persis kelas
kegagalan yang §12 ini dibuka untuk menutupnya, cuma satu lapis lebih dalam
daripada semua yang sudah ketemu.

Tidak ada test yang kena karena tidak ada satu pun fixture TIDS yang memakai
`tahap` aslinya: yang ada menyalin tabel `Pembacaan Standard` dan memeriksa set
point-nya saja, tidak pernah angkanya.

**Yang membetulkan: kunci utamanya sekarang datang dari `simpan_ke`**, kunci
yang backend memang sudah mengirimkannya (`measurements[].pembacaan`) dan yang
selama ini cuma dibaca null-nya. Lembar ke-21 yang tahapnya beda lagi ikut benar
tanpa satu berkas pun disentuh; sembilan belas lembar yang tidak mengirim
`simpan_ke` jatuh ke bawaan `sesudah_adjustment` dan tidak bergeser sedikit pun.
Tiga tempat yang membaca kunci itu — perakit payload, pemulihan dari server, dan
ringkasan sebelum kirim — sekarang memakai satu sumber yang sama.

Dibuktikan merah dengan mengembalikan kunci matinya.

### Susulan: enam temuan review di sisi HP

Lima yang beneran menggigit, satu yang dipasang sebagai jaring:

| Temuan | Akibat kalau dibiarkan |
|---|---|
| **Draf TIDS yang dibuka ulang tampil kosong** | Yang dikirim `titikUkurEfektif` (`121,5`); yang mencari `_titikTerdekat` di kunci `titik` yang isinya NOMOR BARIS (1–7). Tidak pernah ketemu, tiap baris kehitung `kebuang`. Di sesi revisi: yang dikirim balik ke admin cuma sisa yang sempat diketik ulang dari kertas |
| **Penjaga orde menolak angka yang sah** | `adaPembacaanJauhDariTitik` mengadu pembacaan ke nomor baris. Set point 121,5 dengan pembacaan 121,5 kena rasio 121,5 dan barisnya ditahan — penjaga yang melatih teknisi menekan "lanjut" tanpa membaca |
| **Set point cacat membuang seluruh baris diam-diam** | Kotaknya menerima `12..5` / `1-2` / `--3`; `parseAngka` pulang null, `siapKirim` false, dan kelima kotak pembacaan yang sudah diisi ikut hilang. Sekarang kotaknya dibatasi seperti sel angka lain, DAN ada penjaga yang menahan sebelum kirim |
| **Set point yang baru diketik hilang tanpa konfirmasi** | `TitikState.adaIsian` tidak membaca kotak `Setpoint`, jadi lembar yang ketujuh set point-nya sudah diisi masih dianggap perawan waktu teknisi menekan back |
| **Kolom grid bernomor tak berurut salah tempat** | `terapkanHasilFoto` memakai `repeatNo - 1`. Kertas bernomor `2, 4, 6` bikin angka kolom `2` mendarat di kolom `4`, dan dua sisanya kebuang di pemeriksaan batas |
| **Penanda baris kembar menyuruh jepret ulang** | Grid & matriks memperlakukan hasil kosong sebagai salah framing. Baris kembar itu bentuk lembarnya — jepret ulang tidak pernah bisa menolong. (Tidak punya jalan masuk hari ini; dipasang sebagai jaring) |

Ditambah satu di pemeta yang sudah disebut §12 sebab 2, tapi dari sisi yang
belum ketutup: **`batasKolom` salah waktu kepala kolom yang hilang ada di
TENGAH.** `X1` & `X4` kejangkar sementara `X2` & `X3` hilang berarti
satu-satunya jarak yang tersisa **tiga kali** lebar kolom, jadi batasnya ikut
tiga kali lipat dan angka di bawah `X2` lolos lalu tersimpan sebagai `X1`.
Kolom tujuannya kosong di baris itu, jadi `_buangSelKembar` tidak punya apa pun
untuk dibandingkan dan `angkaTakTerpetakan` tetap **nol**. Sekarang tiap selisih
pusat dibagi jarak POSISI kolomnya dulu.

> **Jebakan yang ikut tercatat:** test pertama untuk temuan itu **hijau walau
> perbaikannya dicabut**, karena fixture-nya menyisakan dua jangkar yang
> bertetangga (`X4` & `X5`) — dan jarak tersempitnya jadi satu lebar kolom
> secara kebetulan. Syaratnya: **tidak boleh ada dua jangkar bertetangga.**

### Susulan: gerbangnya bisa dilewati dengan MENGHILANGKAN satu kolom opsional

Ketemu review CodeRabbit **sesudah PR-nya ke-merge**, dan temuannya benar.

`calibration_session_id` divalidasi `sometimes|nullable`. Dihilangkan dari
permintaan, `sesiTervalidasi` pulang null tanpa error, `bentukKertas` nggak punya
alat buat ditanya, dan bawaannya `didukung: true`.

Akibatnya: seluruh lembar yang sengaja ditolak — Autoklaf, TIDS, kelima
Enclosure — bisa dikirim ke penyedia AI pihak ketiga **cukup dengan
menghilangkan satu kolom opsional.** Dan `VISION_AKTIF` bawaannya `true`, jadi
ini lubang yang hidup, bukan teoretis.

**Pemisahan `didukung`/`lokal` nggak menutupnya — dia cuma memindahkan
pintunya.** Sapuan `test_tiap_lembar_tak_didukung_ditolak_sebelum_foto_keluar`
juga nggak: dia **selalu membuat sesi**, jadi buta persis di jalur yang nggak
punya profil sama sekali. Kebutaan yang sama dengan penjaga yang dia gantikan —
yang lama berdiri di satu profil, yang ini berdiri di satu bentuk permintaan.

Yang bikin ini paling pantas dicatat: **dua penjaga berturut-turut dibuat khusus
buat menutup kelas kegagalan ini, dan dua-duanya bolong di tempat yang sama.**
Sapuan lintas profil tidak menjamin apa pun tentang permintaan yang tidak punya
profil.

**Ditutup atas keputusan pemilik lab: tanpa sesi = ditolak.** Bawaan `didukung`
jatuh ke `false`; tiap profil menyebutnya eksplisit, jadi yang punya sesi tetap
dapat nilainya sendiri. Fitur "ekstrak tanpa sesi" berikut testnya dicabut, dan
biayanya nol nyata — aplikasi mobile **nggak punya satu pun call site** ke
endpoint ini.

Dua penjaga baru berdiri di dua bentuk permintaan yang beda, karena `nullable`
bikin keduanya sampai ke jalur yang sama:

| Penjaga | Bentuk permintaannya |
|---|---|
| `test_session_id_null_ditolak_sebelum_foto_keluar` | `calibration_session_id: null` eksplisit |
| `test_tanpa_kunci_session_id_ditolak_sebelum_foto_keluar` | kuncinya **nggak ada sama sekali** |

Dibuktikan merah dengan mengembalikan bawaannya ke `true` — dua-duanya jatuh.

### Susulan: `kolom_suhu` bohong di lima lembar

Ketemu waktu mengadu tiap profil ke tabel yang benar-benar dikirimnya. `bentukPindaiFoto()`
bawaannya `kolom_suhu = true` — bentuk lembar pH, yang tiap selnya memuat SEPASANG angka
(pembacaan + °C dicatat bersamaan). Lima lembar cuma punya kolom `pembacaan` tapi masih mengaku
punya kolom suhu: **TITS, Gas Detector, Thermocouple, Termometer Gelas, Thermohygrometer**.

Belum pernah menggigit hari ini karena penanda itu cuma memberi makan endpoint AI Vision cloud,
dan aplikasi mobile tidak pernah memanggilnya lagi. Tapi endpointnya masih hidup, dan yang terjadi
kalau dipanggil sudah tertulis di docblock bawaannya: modelnya diminta membaca kolom yang tidak
ada di kertasnya, lalu mengarang angka supaya kolomnya kelihatan terisi.

Seperti biasa yang bolong justru yang paling baru, dan sebabnya penjaganya berdiri di sisi yang
salah: penanda ini cuma pernah diuji di lembar pH (yang memang benar) dan lembar grid Enclosure.
Sekarang `BentukPindaiFotoCocokTabelTest` **menurunkan harapannya dari kolom tabel yang beneran
dikirim** — bukan dari daftar nama alat — jadi profil ke-21 ikut kesapu tanpa ada yang perlu
ingat. Tiga aturan × 20 profil, dan dibuktikan merah dengan mengembalikan `kolom_suhu` TITS ke
`true`.

---

## 13. Dua workbook master TIDS — 28 Agt 2026

Ditambahkan pemilik proyek bersama dua berkas ber-password (passwordnya dikirim terpisah di
percakapan — **jangan ditulis di repo**):

> *"ok jadi gw ada alat baru ini ya tolong cek aja ya terus bikin kanyak alat alat sebelum nya
> itu ada 2 alat okk okk buatkan dengan baik baik danjuga tolong ini juga hasil dari olah data
> nya yang bener yaa jangan sampai gk jelas gitu ookk"*

| | Isi | Status |
|---|---|---|
| **A** | Cek dua workbook & petakan olah datanya | **BERES** — `docs/pertanyaan-lab-tids-workbook.md` |
| **B** | Olah data (koreksi + budget U95) sesuai master | **BERES** — cocok sampai digit terakhir di DUA sesi contoh, dijaga `TidsMasterTest` |
| **C** | Backend "kayak alat-alat sebelumnya" | **BERES** — `TabelStandarTids` + `TidsCalculator`, pola yang sama dengan `TabelKalibratorSuhu3Alat`/`ThermocoupleCalculator` |
| **D** | Sisi mobile | **BERES** — bentuk mock TIDS, fixture dari server, 9 test, plus SATU BUG diperbaiki (lihat di bawah) |

### Verifikasi

| | |
|---|---|
| `TidsMasterTest` (unit) | 8 test — dua sesi contoh master diadu sel demi sel |
| Suite API di MySQL | 2.268 test · 1 gagal, dan gagal yang **sama persis** muncul di HEAD bersih `03a4d1c` (2.257 test, 1 gagal) — `IdPelangganDiDaftarArsipTest`, soal id folder arsip, nol sentuhan ke TIDS. Bukan regresi; sebabnya ada di jebakan AUTO_INCREMENT di bagian jebakan. |
| `flutter test` | 1240 test · `flutter analyze` bersih |
| `pint` | bersih untuk seluruh berkas yang disentuh |

### Bug sisi mobile yang ketangkap gara-gara ini

`toSubmissionPasangan()` mengirim `titikUkur` mentah, bukan `titikUkurEfektif`. Untuk tiga lembar
pasangan pertama keduanya SELALU sama — set point-nya tercetak di kertas. Lembar TIDS tidak:
kertasnya mencetak tujuh baris Setpoint **kosong**, dan `titikUkur` di situ cuma nomor barisnya
(1..7). Dibiarkan, tiap sesi TIDS terkirim dengan set point 1, 2, 3… — angkanya lengkap, kolom
`Correction` terbit, dan yang salah cuma titik yang diklaim sertifikat. Nol error di sepanjang
jalurnya.

Yang menemukannya bukan pembacaan kode, tapi **bentuk mock TIDS yang selama ini tidak ada**:
`MockLembarKerjaService` diam-diam jatuh ke bentuk pH untuk profil `tids`, jadi tidak ada satu
pun test yang pernah menyuapkan bentuk TIDS asli ke `LembarKerjaState`.

### Dua workbook = dua KELUARGA STANDAR, bukan dua alat baru

Ini yang paling gampang salah baca dari kalimat "ada 2 alat". Dua-duanya berkop
`KALIBRASI TEMPERATURE INDIKATOR DENGAN SENSOR (TIDS)`, bernomor lingkup `LK-285-IDN`, bermetode
`SIDIK-IK-CAL-0503_Rev.6`, dan bertabel CMC **0,86 / 1,4 / 3,1 °C** — satu baris lampiran
akreditasi yang sama. Yang berbeda **standar yang dipakai mengalibrasi**:

| workbook | standar meter | bentuk tabel koreksinya |
|---|---|---|
| `… Recorder Graptech.xlsm` | Temperature Recorder Graptech GL840 | per **KANAL** (CH1..CH20) × tipe sensor |
| `… Yokogawa K,N.xlsm` | Constant 40T & Yokogawa CA 150 | per **tipe sensor** |

Jadi hasilnya SATU profil dengan tiga keluarga standar — pola yang persis sama dengan TITS (dua
workbook: fungsi Measure & Source) dan Enclosure (dua workbook: Recorder & Constant/Yokogawa).
Memecahnya jadi dua profil mustahil: `CalibrationProfileRegistry` melempar `LogicException`
begitu dua profil mengaku ejaan nama alat yang sama.

### Yang dibalik workbook: K1 gugur, bukan terjawab

Kepala kolom PDF berbunyi `0" (UUT1)`…`90" (UUT5)` dan selama ini dibaca sebagai LIMA ALAT dalam
satu lembar — sampai-sampai keputusan "1 sesi 5 UUT vs 5 sesi terpisah" ditahan menunggu jawaban
lab. Dua workbook menulis kolom yang sama sebagai `PRT1`…`PRT5` lalu memakainya `AVERAGE(D:I)` +
`STDEV(D:I)` **per baris**. Satu baris = satu set point; lima kolom = lima ULANGAN.

Akibatnya lembar TIDS ternyata sekeluarga dengan Thermocouple/Gelas/Thermohygro
(`butuhPasanganStandarUut`), dan **tabel Pembacaan Standard akhirnya punya tempat simpan** —
sebelumnya `simpan_ke: null`, artinya 35 kotak yang diisi teknisi tidak pernah sampai ke server.

Label cetaknya TIDAK diubah (`0" (UUT1)` tetap): itu yang tertulis di kertas yang dipegang
teknisi dan yang jadi jangkar sumbu mendatar jalur foto. Yang berubah artinya, dan artinya ditulis
di `sumbu_uut.keputusan_skema = "lima_ulangan"`.

### Empat penyimpangan master yang DITIRU

Keempatnya menggeser U95 dan tidak satu pun memunculkan error. Aturan repo ini sudah dipakai TITS
(`SERTAKAN_DRIFT_MATI`) dan Thermocouple (`type_a_tidak_masuk_budget`): **master direproduksi apa
adanya** karena sertifikat yang sudah diserahkan ke pelanggan lahir dari workbook itu — lalu tiap
penyimpangan melahirkan catatan audit yang menyebut berapa angkanya kalau dibetulkan, DAN
peringatan sesi yang menahan tombol APPROVE.

| | Isi | Arah |
|---|---|---|
| **D1** | `O24` Recorder menunjuk sel tetap `T30` (0,83) — tabel Type K berbunyi 0,67 | U95 lebih BESAR |
| **D2** | `O25` Recorder literal 0,14 — tabel berbunyi 0,44 (K) / 0,76 (N) | U95 lebih kecil |
| **D3** | `N27` Recorder menunjuk `AM9` di tabel KOREKSI (−0,2) — `Tabel_Drift_Recorder` (0,25/0,5) ada & nggak dipakai | U95 lebih kecil |
| **D4** | `AC36` Constant/Yokogawa cuma menjumlah 9 dari 12 komponen | U95 lebih kecil |

**D4 yang paling mendesak dijawab lab**: workbook Recorder untuk alat yang SAMA menjumlah
keduabelasnya. Kalau ketiganya ikut, U95 sesi contoh Yokogawa 1,1411 °C, bukan 1,0674.

### Yang TIDAK ditiru

`IFNA(…,"")` yang bikin sel hilang dibaca NOL. Paling nyata di **PRT PT100 + recorder**: cabang
terakhir kedua rumus jatuh ke `VLOOKUP(…, 100, 0)` di tabel 42 kolom, jadi koreksi meter DAN
koreksi sensor dua-duanya hilang tanpa satu pun error. Kombinasi itu sekarang DIBLOKIR dengan
alasan yang kebaca.

### Bonus: K12 punya jawabannya di sini

Sheet `Variasi axial Dryblok A` & `B` di kedua workbook TIDS **berbeda** — A bertitik 0/50/150
(rentang Isotech −20…150 °C) dengan keseragaman 0,47 & stabilitas 0,0005; B bertitik 300/450/600
dengan keseragaman 0,1 & stabilitas 0,03. Bandingkan dengan workbook Thermocouple, yang dua
sheet-nya identik byte-per-byte dan dua-duanya berisi data blok B (itu isi K12). Angka blok A yang
asli akhirnya ada — **tapi belum dipakai ulang untuk Thermocouple**, karena sertifikat dryblock-nya
belum tentu sama tanggal. Lihat K12.


---

## 14. Alat baru **Timbangan** (Massa) — 31 Agt 2026

Ditambahkan pemilik proyek 31 Agt 2026 bersama tiga workbook master ber-password (ber-password):

> *"kita ada alat baru lagi yaitu jenis nya TIMBANGAN … pastikan harus beres juga olah data nya
> gk ada yang error dan juga aneh aneh"*

| | Isi | Status |
|---|---|---|
| **A** | Backend alat ke-21 (profil, lembar kerja, registry) | **BERES** — `TimbanganProfile`, alat pertama di kelompok **Massa** |
| **B** | Olah data sesuai master (koreksi + DUA budget U95) | **BERES** — 1.099 angka diadu, cocok sampai digit terakhir; `TimbanganMasterTest` |
| **C** | Tabel anak timbangan, CMC, drift | **BERES** — `database/data/tabel-standar-timbangan.json`, tiga snapshot |
| **D** | CMC diadu ke lampiran akreditasi | **BERES** — `TimbanganCmcCocokAkreditasiTest`, 17 pita cocok |
| **E** | Sisi mobile (layar lembar kerja) | **BERES** (31 Agt 2026) — lembarnya kegambar & payloadnya sampai; 13 test baru (`timbangan_lembar_test.dart`, `timbangan_layar_test.dart`). Lima cacat sunyi ditemukan & ditutup, lihat di bawah |
| **F** | Jalur kamera / pindai lembar | **NYALA per tabel** (31 Agt 2026, sesudah kertas masternya dikirim) — Repeatability ON, Accuracy OFF. Tiga cacat sunyi ditutup dulu; lihat di bawah |

### E — lima cacat SUNYI yang ketemu waktu HP disambungkan

Tidak satu pun menghasilkan error. Ini bagian yang paling mahal dari pekerjaan ini, dan urutannya
urutan ketemunya:

| # | Cacat | Kalau lolos |
|---|---|---|
| 1 | Kode kotak empat blok field bertitik **tanpa** awalan `spesifikasi_alat.` | Di HP itu berarti kolom TURUNAN: read-only, tidak pernah ikut payload. **39 kotak** digambar rapi, diisi teknisi dari kertas, lalu hilang waktu tombol kirim ditekan |
| 2 | Titik di dalam `spesifikasi_alat.*` dikirim DATAR | `spesifikasi_alat` kolom JSON tanpa skema, jadi lolos validasi tanpa keluhan — lalu dibaca **nol** kalkulatornya. Komponen Eccentricity nol di setiap sesi |
| 3 | Kedua tabel memakai `peran` sebagai nama blok | Di HP `peran` bukan label bebas: nilainya-yang-bukan-null berarti "lembar pasangan standar/UUT". SELURUH lembar belok ke jalur pasangan — payload berangkat berisi `standar`/`uut` **tanpa satu pun nominal** |
| 4 | Baris Accuracy 50/100 kg bentrok dengan Middle/Maximum Capacity | Empat baris berbagi dua kotak isian; angka yang diketik di satu tabel muncul di tabel satunya |
| 5 | `titik_bisa_diubah` nyala di DUA tabel | `titikKustom` di HP itu SATU daftar untuk seluruh lembar. Menyusun sepuluh titik Accuracy ikut mengubah tabel Repeatability jadi sepuluh baris Middle/Maximum yang tidak ada di kertas mana pun |

Yang menutupnya: awalan `spesifikasi_alat.` + peta bersarang (1 & 2), `grup` menggantikan `peran`
(3, dijaga aturan umum `SemuaProfilLembarKerjaTest::test_peran_tabel_cuma_buat_lembar_pasangan`),
`offset_kunci: 1000` (4), dan `titik_bisa_diubah: false` di Repeatability (5).

Dua kunci bentuk baru lahir dari sini, dua-duanya umum bukan khusus Timbangan:

- **`tabel.simpan_ke: "spesifikasi_alat.<kunci>"`** — isi tabel itu besaran tingkat-SESI, bukan
  titik. HP mengirimkannya sebagai cerminan tabelnya (`{baris: [{titik_ukur, <kode kolom>: […]}]}`)
  dan barisnya TIDAK ikut `measurements[]`. Tanpa ini, sertifikat terbit dengan dua baris titik
  tambahan yang tidak pernah diminta siapa pun — angkanya sah, set point-nya sah, nol error.
- **`tipe: "daftar_angka"`** pada `kolom_baris` — satu kotak, beberapa angka (`20+20+10`). Koma di
  situ koma DESIMAL, bukan pemisah: `20,5+10` wajib jadi dua keping, bukan tiga.

### F — kamera: dibatalkan, lalu DIHIDUPKAN LAGI waktu kertasnya dikirim

Pemilik proyek mengirim cetakan `CALIBRATION RESULT` ketiga master (31 Agt 2026, sesudah keputusan
di bawah), dan cetakan itu membatalkan dua dari tiga alasannya. Yang berlaku sekarang:

| Blok | Kamera | Sebabnya, dari kertasnya |
|---|---|---|
| `keterulangan` | **ON** | Grid sempurna: `No.` 1..10 turun, dua kapasitas ke samping, sub-kolom `Zero (kg)`/`Reading (kg)`. Ketiga jangkarnya tercetak |
| `akurasi` | **OFF** | Daftar MENURUN (`z1`, `m1`, `m1'`, `z2`…), pembedanya tulisan per baris. Pemeta yang ada menjangkar kolom ke nomor pengulangan |

Tiga hal yang harus dibetulkan supaya jangkarnya ada, dan ketiganya cacat SUNYI:

1. **Bentuk tabelnya transposed dari kertasnya.** Kami mengirim kapasitas sebagai baris dan
   pengulangan sebagai kolom; kertasnya kebalikan. Dua jangkar di sumbu yang salah = nol sel tiap
   jepretan. Ini juga yang bikin alasan lama *"kepala kolomnya tidak terjangkau"* keliru — yang
   salah bentuk kami, bukan kertasnya.
2. **Nomor baris polos bikin jangkar LENGKAP TAPI SALAH.** Kertas menomori `1`..`10`, dan angka `1`
   juga muncul di baris penomoran sub-kolom TEPAT DI ATAS isi tabel. Pencarian teks memilih
   kemunculan paling atas → `1` & `2` dari baris itu, `3`..`10` dari kolom `No.` → jumlahnya pas
   sepuluh, tidak ada penjagaan berbunyi, dan SELURUH grid bergeser satu baris. Ditutup
   `_jangkarNomorPolosBaris` (deret utuh, tegak satu kolom, di kiri kolom data — atau nol sel).
3. **Kapasitas uji diturunkan dari `range_max`.** Master gram membantahnya: alat 54 g diuji di
   25 g & 50 g, bukan 27/54. Angka itu masuk rumus lewat `deviasiKurangiNominal` (gram DAN
   substitusi) dan `srTerdekat()`. Sekarang diketik lewat `spesifikasi_alat.keterulangan.*.nominal`.

Satuan ikut jadi jangkar: label sub-kolom ditulis persis seperti tercetak (`Zero (g)` di master
gram), jadi lembar gram yang difoto ke sesi kilogram pulang NOL sel — gagal berisik, bukan
memindahkan `24,9999 g` ke kotak kilogram.

Yang HILANG: lembar ini tidak punya berkas geometri, jadi tidak bisa dipindai satu-halaman-penuh.
Pipeline geometri menurunkan tinggi sel & kotak jangkar SEKALI per lembar, sementara lembar ini
mencampur dua orientasi tabel — `ocr:rangka-geometri` sekarang menolaknya di muka daripada
menerbitkan kertas yang bertentangan dengan bentuknya sendiri.

### F (lama) — kamera: dicoba, lalu dibatalkan hari yang sama

Sempat dinyalakan per-tabel (`lokal: true` + `tabel[].pindai_foto`) dengan alasan bentuk layar
Repeatability memang grid sempurna. Dibatalkan setelah tiga hal terbukti, dan ketiganya bisa dicek
tanpa memegang kertasnya:

1. **Kertasnya belum ada** — `kode_dokumen` lembar ini `null`. Tombol "foto tabel ini" untuk
   formulir yang belum diterbitkan lab menjanjikan sesuatu yang tidak ada.
2. **Kepala kolomnya tidak terjangkau** — `PetaTabelFoto` menjangkar tiap pengulangan ke tulisan
   kepala kolomnya, bawaannya `X1` / `Repeat 1`. Tabel ini tidak mengirim `pengulangan_arah` maupun
   `prefiks_pengulangan`, jadi tiap jepretan pulang NOL sel.
3. **Blok Accuracy tidak sebentuk** — di kertas master dia daftar MENURUN (`z1`, `m1`, `m1'`, `z2`,
   …); grid empat kolom yang digambar layar itu bentuk LAYAR.

Mekanisme per-tabel yang sempat dibuat ikut dibuang: mesin tanpa pemakai lebih buruk daripada tidak
ada mesinnya. Syarat menyalakannya nanti ada di `docs/perintah-frontend-timbangan.md` §6.

**Baris lampiran akreditasi LK-285-IDN no. 12**, kelompok **Massa**, satu-satunya baris di
kelompok itu: *"Timbangan (Elektronik, mekanik)"*, 17 pita CMC dari 0–200 g (0,57 mg) sampai
1800–2000 kg (0,52 kg). Baris CMC-nya sudah ter-seed sejak dulu — yang belum ada cuma profil &
mesin hitungnya, sama persis seperti ketiga alat suhu di §11.

### Tiga workbook = tiga REVISI, bukan tiga alat

| Berkas | Sesi contoh | Basis | Metode |
|---|---|---|---|
| `New_Master_Olda_Timbangan_kg.xlsm` | `011-CAL-525` Bestar 100 kg | kg | langsung |
| `New_Master_Olda_Timbangan_gram.xlsm` | `019-CAL-425` Moisture Analyzer 54 g | g | langsung |
| `TERBARU_Master_Olda_Timbangan_Subtitusi_291025.xlsm` | `0136-CAL-123` Dini Argeo 2000 kg | kg | beban substitusi |

Dua yang pertama tinggal di folder yang namanya sendiri berbunyi *"Temuan No. 34 - (blm rampung
total)"* — kebaca dari tautan luar yang tertanam di workbook ketiga. Yang ketiga bernama "TERBARU"
dan bertanggal 29 Okt 2025.

Godaannya besar membuat tiga profil. Yang membantahnya lampiran akreditasi: **satu baris, satu
nama alat**. `CalibrationProfileRegistry` sendiri melempar `LogicException` kalau dua profil
mengaku ejaan nama yang sama. Jadi: satu profil, tiga varian master
(`VarianMasterTimbangan`), variannya properti SESI. Pola yang sama sudah dipakai TITS, Enclosure,
dan TIDS.

### Bentuk lembarnya BEDA dari 20 alat sebelumnya

Dua puluh alat sebelumnya punya SATU tabel: titik ukur turun, pengulangan ke kanan. Timbangan
punya **tujuh blok** yang tidak sebentuk — Scale Observation, Effect of Tare, Accuracy,
Repeatability, Loading Influence, Hysterisis, Drift — dan cuma satu (Accuracy) yang jadi baris
titik di sertifikat. Empat blok lain menyumbang ke budget atau ke pernyataan terpisah (LOP).

Jalur datar `measurements[i].pembacaan` maupun jalur pasangan standar/UUT dua-duanya tidak
cukup, jadi seluruh sesi dihitung sekali lewat `hitungPerGrup()`. **Nol kolom baru di
`raw_measurements`.**

### DUA ketidakpastian per titik, dua-duanya tercetak

Acuannya ditulis sendiri di sheet `Sekilas Info` ketiga workbook: **NMI Monograph 4 (CSIRO
2010)**, yang memisahkan *Uncertainty of Correction* (koreksi kalibrasi, tanpa keterulangan
pemakaian) dari *Uncertainty of Weighing* (seluruh proses, MEMUAT yang pertama sebagai
komponen). Sertifikat mencetak keduanya — bagian 3 dan bagian 7.

Urutannya searah dan mengikat: Correction dulu, hasilnya jadi bahan Weighing di titik yang sama.

### Angka yang dicocokkan ke master

| Sesi | Titik | U95 Correction titik 1 | U95 Weighing titik 1 | Sumber |
|---|---|---|---|---|
| `011-CAL-525` (kg) | 10 | **0,033 kg** | **0,04251 kg** | lantai CMC / hitungan |
| `019-CAL-425` (gram) | 10 | **0,00057298 g** | **0,00059956 g** | hitungan budget |
| `0136-CAL-123` (substitusi) | 10 | **0,52 kg** | **0,52 kg** | lantai CMC (hitungan 0,2801 / 0,3477) |

Yang diadu bukan cuma angka akhirnya: tiap `ui × ci`, tiap `vi`, `uc`, `veff`, `k`, `U`, `U95`
kedua budget × 10 titik × 3 workbook, plus tiap kolom turunan blok akurasi. **1.099 angka**,
toleransi 5·10⁻⁶.

Yang ikut terbukti di situ: `GumCalculator::agregasiBudget()` yang sudah ada — dengan `veff`
DIPOTONG ke bawah lalu t-student — cocok dengan `TINV(0,05; veff)` Excel di keenam puluh budget
itu. Tidak ada mesin agregasi kedua yang dibuat.

### Penyimpangan master yang SENGAJA ditiru

Sepuluh butir, semuanya di `docs/pertanyaan-lab-timbangan.md`. Yang paling menggeser angka:

1. **`ui` "U of Correction" di budget Weighing** — ketiganya memasukkan ketidakpastian DIPERLUAS
   sebagai komponen BAKU, lalu memperlakukannya beda: kg memakainya mentah, gram membaginya `k`
   (yang benar menurut GUM), substitusi membaginya `√3`. Bedanya **hampir dua kali** di U95
   Weighing (T2).
2. **`ci = 10`** di baris drift Mref master substitusi, tanpa keterangan (T4).
3. **Dua sel silang-kabel** master substitusi: `Sres MID` dibaca dari kolom **Maximum**, dan
   `Sres MAX` dari kolom ketiga yang di workbook itu tidak ada. Menaikkan U95 titik 1 **18%** (T5).
4. **`Rounding of Final Result`** — kg memakai resolusi alat dibagi `2√3`; dua lainnya angka
   tetap `0,5/1000` dibagi `√3` (T8).
5. **Enam baris drift** berlabel pembagi `Ö3` yang rumusnya tidak membagi (T3).

### Yang TIDAK ditiru, dan kenapa

- **Sel kosong dibaca nol.** Tiap `VLOOKUP` dibungkus `IFERROR(…,"")`, jadi nominal anak
  timbangan yang tidak ada di tabel pulang kosong dan kosong ikut dijumlah sebagai nol —
  sertifikat terbit dengan massa standar HILANG, tanpa error. Di sini titik seperti itu
  **diblokir dengan alasan yang kebaca**.
- **Dua sel yang rusak.** Master gram titik 9 membaca rujukan massa tiga baris terlalu jauh (T6);
  master substitusi titik 9 membaca komponen `Weight Standard` dari **workbook lain** lewat
  tautan luar `[3]` yang nilainya tinggal cache (T7). Dua-duanya kerusakan salin-tempel yang
  perilaku benarnya tidak ambigu — sembilan titik tetangga di berkas yang sama melakukannya
  dengan benar. Dihitung BENAR, selisihnya ditulis, dan `TimbanganMasterTest` menegakkan arah:
  hitungan kita harus lebih BESAR, bukan sekadar berbeda.

### Temuan yang paling mahal: tiga snapshot sertifikat anak timbangan

Ketiga workbook memuat **sertifikat anak timbangan yang berbeda untuk keping fisik yang sama**.
Keping E2 100 g: `100,0004 g` (kg) vs `100,000033 g` (gram) vs `99,999984 g` (substitusi) —
selisih kg↔gram **16× ketidakpastian keping itu sendiri**. Dan kolom ketidakpastian keping
0,1 g–500 g di master kg **seribu kali** lebih kecil daripada baris yang sama di master gram,
sementara baris 1 kg ke atas di berkas yang sama cocok persis.

Ketiganya disimpan dan dipilih per sesi, supaya tiap sesi bisa dihitung ulang jadi angka yang
sama dengan kertas yang menerbitkannya. Mana yang berlaku = **T1**, dan itu keputusan manajer
teknis lab.

### Dugaan awal yang KELIRU, dicatat supaya tidak diulang

Sempat disimpulkan bahwa pita CMC I..Q (200–2000 kg) **di luar** lampiran akreditasi, dan
peringatan sesi sempat ditulis atas dasar itu. **Salah** — bacaan tabel lampiran terpotong di
baris 45; lampiran no. 12 memang berlanjut sampai 1800–2000 kg, 17 pita, dan cocok angka demi
angka dengan `DATABASE!R5:T21`. Kalau dibiarkan, tiap sesi kapasitas besar terbit dengan
peringatan "di luar akreditasi" yang tidak benar — dan peringatan palsu melatih admin menekan
"setujui tetap" tanpa membaca, kelas kerusakan yang persis sudah ditulis di §9.

Yang menutupnya sekarang `TimbanganCmcCocokAkreditasiTest`: dua berkas diadu baris demi baris,
jadi salah satu yang digeser tanpa yang lain langsung merah.

### Hitung ulang: kejadian ketujuh dicegah, bukan ditunggu

Pola yang sudah menggigit enam kali (Viscometer, Gas Detector, TITS, Enclosure, tiga alat suhu,
lalu perintah `kalibrasi:hitung-ulang` yang "sukses" tanpa menghitung apa pun) ditutup
BERSAMAAN dengan profilnya, bukan ditemukan belakangan:
`App\Support\TimbanganMentah` disambung ke DUA jalur (`CalibrationValidator` &
`HitungUlangSesi`), dan di perintah itu blok Timbangan diperiksa **duluan** — `GridSensorMentah`
balik `[]` cuma kalau tidak ada `peran_sensor` sama sekali, dan kosakata Timbangan
(`z1`/`m`/`m_aksen`/`z2`/`nominal`) tetap punya `peran_sensor`.

Dibuktikan MENGGIGIT: dengan `TimbanganMentah::dari()` dimatikan,
`HitungUlangSemuaSesiTest` langsung merah dan menyebut ketiga sesi berikut nama alatnya —

```
011-CAL-525  [Timbangan (Elektronik, mekanik)] → hitung_ulang_gagal
019-CAL-425  [Timbangan (Elektronik, mekanik)] → hitung_ulang_gagal
0136-CAL-123 [Timbangan (Elektronik, mekanik)] → hitung_ulang_gagal
```

### Satu bug yang ditangkap penjaga waktu dikerjakan

`EquipmentFactory` mengundi `nama_alat` dari empat nama generik, dan salah satunya
**"Timbangan"** — nama yang sampai hari itu memang tidak diklaim profil mana pun. Begitu
`TimbanganProfile` lahir, satu dari empat fixture acak mendarat di lembar Timbangan, dan karena
namanya diundi yang merah **bergantian tiap jalan**: Sertifikat, Masa Berlaku, Pembacaan
Mustahil — sebelas test yang sama sekali tidak berhubungan dengan Timbangan.

Yang bikin ini pantas dicatat: peringatannya **sudah tertulis di berkas itu sendiri**, satu baris
di atas daftarnya (*"JANGAN pakai nama yang sama dengan `namaAlatKemampuan()` profil mana pun"*) —
persis bentuk yang sama dengan jebakan `calibration_method_id` di §Jebakan. Diganti
`Dial Indicator`, dan alasannya ditulis di tempat daftarnya.

### Dua bug SAYA sendiri di rumus LOP, ketangkap membaca ulang master

Bukan penyimpangan lab — dua-duanya salah saya, dan dua-duanya lolos dari test parity budget
karena LOP memang tidak diadu di situ. Yang menangkapnya membaca ulang `D155` master sel demi sel
sesudah semua budget hijau.

1. **`U(C max)` diambil dari U95 yang sudah dilantai CMC.** Master melihat
   `VLOOKUP(Cmax, Tabel_U_Correction, 3)`, dan kolom ketiga tabel itu berisi `k · uc` — bukan
   baris `U95% Sertifikat` dua baris di bawahnya. Di sesi kg lantai CMC 0,033 kg menang atas
   hitungan 0,0240 kg, jadi LOP terbit **0,0885 alih-alih 0,0795 kg — 11% terlalu besar**.
2. **`Maximun STDEV` diambil dari lantai `Sres` budget.** Di varian substitusi lantai itu memang
   sengaja disilang-kabel (T5), jadi mencampurnya melesetkan LOP
   2,26 × (0,041 − 0,0316) = **0,0212 kg**.

Pelajarannya bukan "kurang teliti": budget yang cocok 1.099 angka **tidak** membuktikan angka di
luar budget benar. Sekarang LOP, rentang eksentrisitas, dan histeresis ikut diadu ke master, plus
dua test yang MENGGIGIT — satu memastikan titik ber-|C| terbesar sesi kg memang yang U95-nya
dilantai (kalau tidak, test-nya berhenti membedakan dua angka itu), satu lagi memastikan
silang-kabel T5 masih ada di lantai budget dan TIDAK bocor ke `stdev_terbesar`.

### Sisi mobile: kenapa BELUM, dan apa yang sudah siap menerimanya

**Bukan dikecilkan ruang lingkupnya — kepentok alat.** Container sesi ini tidak punya toolchain
Flutter (`flutter: command not found`), sementara `sidik-calibration-mobile` punya **162 berkas
test**. Menulis layar baru yang tidak bisa dikompilasi maupun dijalankan test-nya, lalu
mendorongnya ke suite sebesar itu, lebih berbahaya daripada handoff yang jelas — dan
`[[sidik-fe-test-generator]]` memang mensyaratkan test menyertai tiap pekerjaan FE.

Yang sudah diperiksa dan **tidak perlu dibangun ulang** di sisi HP:

- **Tabel dua sub-kolom sudah didukung.** `TabelHasil.kolom` (`List<KolomTabelHasil>`) memang
  lahir buat sel pH yang isinya DUA angka (pembacaan + °C). Blok Keterulangan Timbangan
  (`zero` + `pembacaan` per pengulangan) memakai bentuk yang sama persis.
- **`pengulanganArah`, `peran`, `sumbuPengulangan` sudah ada** — dipakai lembar TIDS & ketiga
  alat suhu. Label `z / m / m' / z'` blok Akurasi tinggal ikut jalur itu.
- **Payload non-datar sudah punya tempatnya.** `LembarKerjaSubmission.measurementsGrid`
  (JSON mentah, dipakai Enclosure) yang akan membawa `measurements[].nominal` + empat
  pembacaannya; `measurements` datar tidak perlu disentuh.

Jadi sisa pekerjaannya pemetaan + layar + test, bukan kemampuan baru di model.

### Kamera SENGAJA belum

`bentukPindaiFoto()` memulangkan `didukung: false`. Tujuh blok yang tidak sebentuk tidak bisa
diungkapkan lewat `kolom_suhu` / `standar_di_baris` yang cuma memodelkan satu tabel datar.
Dibiarkan `true`, prompt & skema JSON yang dikirim ke pembaca foto dibangun untuk tabel yang
tidak ada di kertasnya — dan yang balik bukan error, tapi angka yang dikarang supaya kolomnya
kelihatan terisi. Alasan yang sama persis dipakai lembar Autoklaf & grid Enclosure.

### Sertifikatnya: delapan bagian, bukan tabel empat kolom

Yang terakhir dikerjakan, dan yang paling gampang dikira sudah beres karena lembarnya memang
terbit rapi. Sertifikat Timbangan di master (`SERTIFIKAT` di ketiga workbook) punya **delapan**
bagian, dan cuma satu (§3 ACCURACY) yang bentuknya mirip tabel `Standard | UUT | Correction`.
Lewat jalur generik, tujuh bagian sisanya hilang tanpa satu pun error — lembarnya tetap bernomor,
ber-QR, dan tinggal seperdelapan isinya.

Arsitekturnya ikut preseden **Autoklaf**: satu kunci di snapshot (`timbangan`) + satu cabang di
`resources/views/sertifikat/pdf.blade.php`. Bedanya, Autoklaf punya kolom sesi sendiri
(`hasil_autoclave`) sementara Timbangan tidak — angka jadinya (STDEV keterulangan, selisih tiap
posisi, perbandingan histeresis) tidak pernah tersimpan, cuma masukannya yang ada di
`spesifikasi_alat`. Jadi bloknya **disusun waktu sertifikat terbit** lalu dibekukan, dan test
mengunci §3-nya ke isi `uncertainty_calculations` supaya PDF, Excel, dan API tidak pernah bisa
memuat dua generasi angka.

Peta lengkap sel-per-sel, empat penyimpangan dari master berikut alasannya, dan aturan desimalnya
ada di `docs/perintah-frontend-timbangan.md` §5. Pertanyaan lab baru: **T14** (tiga workbook
memformat sel yang sama dengan jumlah desimal yang berbeda — §6 substitusi bahkan nol desimal,
jadi LOP 13,66 kg terbit `± 14 kg`).

---

## 15. Alat baru **kelompok Waktu dan Frekuensi** — 1 Sep 2026

Tiga workbook master turun sekaligus (`Master Olda Tachometer.xlsm`,
`Master Olda Centrifuge.xlsm`, `Master Olda Timer dan Stopwatch.xlsm`, semuanya
ber-password). Ketiganya baris lampiran akreditasi LK-285-IDN yang selama ini
kosong: **no. 37 Timer/Stopwatch, no. 38 Centrifuge, no. 39 Infrared
Tachometer** — dan dengan ini kelompok "Waktu dan Frekuensi" **lengkap**.

Baris CMC ketiganya **sudah ter-seed sejak dulu** lewat
`CalibrationCapabilitySeeder` yang membaca lampiran akreditasi, dan angkanya
cocok persis dengan tabel `DATABASE` di masing-masing workbook. Jadi tidak ada
seeder kemampuan baru — yang belum ada cuma profil & mesin hitungnya.

### Berkas yang dibuat/diubah

Disebut SEBELUM mengetik, sesuai §12:

| Berkas | Isi |
|---|---|
| `database/data/tabel-standar-{putaran,waktu}.json` | tabel sertifikat kalibrator, drift, human reaction — **digenerate skrip** |
| `database/data/sesi-master-waktu-frekuensi.json` | tiga sesi contoh, digenerate skrip |
| `app/Services/Calibration/TabelStandar{Putaran,Waktu}.php` | pembaca tabel + penjagaan "tidak ketemu = null" |
| `app/Services/Calibration/{Putaran,Waktu}Calculator.php` | dua mesin hitung |
| `app/Services/Calibration/Profiles/ProfilPutaran.php` | basis bersama Tachometer & Centrifuge |
| `…/Profiles/{Tachometer,Centrifuge,TimerStopwatch}Profile.php` | tiga profil |
| `app/Support/WaktuMentah.php` | penyusun ulang dua deret waktu |
| `database/seeders/WaktuFrekuensiSeeder.php` | tiga sesi contoh, angkanya DIHITUNG |
| `database/ocr-templates/{tachometer,centrifuge,timer_stopwatch}-v1.json` | rangka geometri (`terverifikasi: false`) |

**Nol kolom baru di `raw_measurements`** — sumbu `peran_sensor`/`sensor_ke` yang
sudah ada cukup, dan blok tingkat-sesi masuk `spesifikasi_alat`. Alat ke-22..24
berturut-turut mendarat tanpa migrasi.

### Dua bentuk, bukan tiga

Tachometer & Centrifuge berbagi **satu** mesin hitung: sheet `PERHITUNGAN` kedua
workbook identik baris demi baris, sampai ke data contohnya — workbook Tachometer
bahkan masih menyimpan tautan luar ke `Master Olda Centrifuge.xlsm` sebagai jejak
bahwa ia disalin dari sana. Yang benar-benar membedakan cuma pita CMC dan judul
lembarnya. Titiknya **deret datar**, jadi tidak butuh kelas `*Mentah`.

Timer/Stopwatch berdiri sendiri: satu titiknya **dua deret waktu** yang ditekan
berbarengan, tiap ulangan ditulis di empat kotak J/M/S/ms. Itu bentuk kedelapan
yang butuh penyusunan ulang, dan `WaktuMentah` ditulis **bersamaan** dengan
profilnya — bukan ditemukan belakangan lewat `hitung_ulang_gagal`.

### Pembuktiannya: 464 nilai, 5·10⁻⁶

Reimplementasi Python diadu ke ketiga workbook sel demi sel SEBELUM satu baris
PHP ditulis. **Setiap** selisih yang muncul punya penjelasan — tidak ada satu pun
yang dibiarkan. Yang menegakkannya di CI: `WaktuFrekuensiMasterTest` (16 test,
402 asersi) mengadu tiap kolom turunan tiap titik DAN tiap komponen budget tiap
blok, bukan cuma U95 akhirnya.

Satu temuan yang menyenangkan: master memakai `TINV(0,05; veff)` dan Excel
**memotong** derajat kebebasan ke bilangan bulat — jadi `GumCalculator::agregasiBudget()`
yang sudah memotong ke bawah (GUM G.4.1) cocok dengan master **tanpa satu pun
penyesuaian**. Dibuktikan di blok 5 Tachometer: `k` master `1,9725950790996611`
= `TINV(0,05; 189)`, bukan `TINV(0,05; 189,5179)`.

### Empat kerusakan master yang TIDAK ditiru

Semuanya membuat U95 master terbit lebih **kecil**, dan perilaku benarnya tidak
ambigu — blok tetangga di berkas yang sama, atau workbook saudaranya, melakukannya
dengan benar:

1. **Blok 5 Tachometer rusak di tiga tempat sekaligus** — pita sertifikat meleset
   satu baris (`F15` alih-alih `F18`), `u_drift` menunjuk `'[1]Drift Std
   Kalibrator'!K54` (sel KOSONG di workbook LAIN, ter-cache 0), dan pengulangannya
   menunjuk baris kosong. U95 master 1,69 rpm lawan 4,04 rpm yang benar.
2. **Blok 1 Centrifuge** memakai `PERHITUNGAN!G34` (satu sel) sementara sepuluh
   blok lain memakai `MAX(...)` sebaris penuh — dan workbook Tachometer memakai
   `MAX(G34:L34)` di blok yang datanya sama persis.
3. **Kolom `K` tabel drift** cuma berrumus di 5 dari 15 baris (rpm) dan 4 dari 13
   (waktu). Dilengkapi: 2,25 → 2,75 rpm, 298 → 322 ms.
4. **`uHRTB` Timer** `MAX(N21:N22)` — dua dari empat operator, sementara sel
   sebelahnya `P23 = MAX(P19:P22)` mencakup keempatnya.

Arah keempatnya ditegakkan test: hitungan kita wajib **lebih besar**, bukan
sekadar berbeda.

### Lima titik hantu, diblokir

Master Timer punya sepuluh blok set point; **lima kosong seluruhnya** — dan sel
kosong yang dibaca nol tetap melahirkan `CORRECTION = 30 ms` yang tercetak persis
seperti titik sungguhan. Kelimanya diblokir dengan alasan yang kebaca, tidak
pernah ditiru. Dijaga `test_titik_hantu_timer_diblokir`.

### Yang perlu jawaban lab

**Tiga belas** pertanyaan bernomor di `docs/pertanyaan-lab-waktu-frekuensi.md`.

> **Diputuskan 1 Sep 2026.** Pemilik proyek: *"rumus yang ada di Excel itu yang
> dipakai."* Arahan itu menutup **§4, §5, §7, dan §11** — keempatnya dengan
> membenarkan perilaku yang sudah berjalan, bukan mengubah kode.
>
> Yang **tetap terbuka** dan tidak bisa ditutup arahan itu, karena keduanya
> bukan soal rumus melainkan soal dokumen yang sudah dipegang pelanggan:
>
> - **§10** — sertifikat Tachometer yang sudah terbit memakai tanda koreksi
>   terbalik. Terbitkan ulang, atau berlaku maju?
> - **§13** — kalimat faktor cakupan mencetak satu `k` untuk baris yang `k`-nya
>   beda. Saran paling kecil risikonya: tambah "≈".
>
> Ditambah **§8 & §9** (pembagi drift dan jumlah hari drift — ditiru, belum
> dikonfirmasi) dan **satu permintaan DATA**: workbook Timer yang keempat
> bloknya hidup, supaya titik ke-2+ punya pembanding.

Yang terbesar:

- **§4** — arah pemutusan seri nominal **berbeda** antar kelompok: rpm ke atas
  (80 → 100, 150 → 200), waktu ke bawah (900 s → 600). Ditiru masing-masing;
  menyeragamkannya menggeser koreksi satu titik sebesar 10 ms.
- **§5** — master Timer cuma punya SATU blok yang menghitung; empat sisanya
  `#REF!` dengan penjumlahan yang memotong dua komponen. Artinya hitungan kita
  **belum bisa dibuktikan** di titik ke-2 dan seterusnya. Diminta satu workbook
  Timer yang keempat bloknya hidup.
- **§7** — blok 5 Centrifuge mengukur 15000–25000 rpm, **di luar** pita
  akreditasi yang berhenti di 9000 rpm, dan master tetap memakai CMC 1,6 rpm.
  Diangkat jadi peringatan sesi yang harus dilewati admin secara sadar.

### Yang belum: kertas & kamera

Ketiga workbook sudah disapu untuk pola `SIDIK-FM-…` dan yang ketemu cuma
`SIDIK-FM-CAL-2403_Rev. 0` — footer sertifikat bersama, bukan nomor lembar
kerjanya. Jadi `bentukPindaiFoto()['didukung'] = false` dan berkas geometrinya
lahir `terverifikasi: false`. **Input manual dulu**; jalur kamera dibuka begitu
kertasnya turun.

Untuk Timer ada alasan kedua yang lebih tajam: satu ulangan ditulis di EMPAT
kotak yang harus dibaca sebagai satu angka, dan satu kotak yang terbaca meleset
satu kolom mengubah waktunya ribuan kali lipat — tetap kelihatan seperti angka
yang sah.

Serah-terima frontend: `docs/perintah-frontend-waktu-frekuensi.md`.

---

## 16. Data pelanggan — nama PT & alamat (2 Sep 2026)

Permintaannya: teknisi berhenti mengetik ulang nama & alamat PT, **tanpa** memasukkan data yang
tidak bisa dipertanggungjawabkan ke sertifikat.

### A. Yang sudah ada — jangan dibangun ulang

Ditelusuri sebelum menulis kode, dan **seluruh rangkanya sudah lengkap server→HP**: enam kelas di
`app/Services/Direktori/` (kontrak, driver Google Places, driver Nominatim, berlapis, bercache,
pemilih driver), tiga endpoint (`GET /customers/direktori`, `GET /customers/lookup`,
`POST /customers/cepat`), `config/services.php:165`, dan di sisi HP
`customer_lookup_service.dart` + `pelanggan_baru_screen.dart` lengkap dengan string l10n ID & EN.

Skema `customers` juga sudah punya penjaganya: `nama_normal` (penjaga kembar), `sumber`,
`dibuat_oleh_user_id`, `direktori_ref`.

**Yang kurang isinya, bukan kodenya.**

### B. Yang tidak bisa disediakan, dan kenapa

Permintaan awalnya memuat "daftar nama PT beserta alamat lengkap seluruh Indonesia untuk ditanam
sebagai data awal". Itu **tidak bisa dipenuhi**, dan alasannya bukan formalitas:

- **AHU Online** (Kemenkumham) memegang nama badan hukum resmi, tapi **tidak punya API publik**;
  scraping-nya melanggar ketentuan pemakaian.
- **Google Places** dan **OpenStreetMap** punya API, tapi isinya **alamat peta, bukan alamat
  akta** — persis yang sudah ditulis komentar `config/services.php:147`.
- **OSS/BKPM** cuma buat mitra berizin.

Jadi tidak ada sumber yang bisa dikueri yang memuat nama badan hukum **sekaligus** alamat
terverifikasi. Yang bisa ditulis dari ingatan itu **karangan** — dan karangannya berbentuk wajar
(`Jl. Raya … KM 27, Kawasan Industri …`), jadi tidak ada yang curiga saat diperiksa.

Yang mengunci semuanya: `certificates.snapshot` membekukan data pelanggan saat sertifikat terbit,
jadi **alamat salah tidak bisa ditarik**. Memperbaiki `customers` besok tidak memperbaiki
sertifikat yang sudah dipegang pelanggan. Untuk lab terakreditasi SNI ISO/IEC 17025 itu temuan
audit, dan kelas kesalahan yang paling sulit ketahuan karena kelihatan benar.

### C. Milestone A — impor pelanggan historis lab · **BERES** (2 Sep 2026)

`php artisan customers:impor {berkas} --organization= --oleh= --sumber= --uji-coba --laporan=`

Tiga keranjang, dan yang meragukan **berhenti di laporan, bukan di database**: `baru` (ditulis),
`kembar_pasti` (dilewati), `perlu_tinjau` (TIDAK ditulis), plus `ditolak` untuk baris yang tidak
terbaca. Serah-terima operator: `docs/perintah-impor-pelanggan.md`.

Nol kolom baru, nol dependensi baru, nol perubahan di jalur tulis yang sudah ada.

**Enam jebakan yang dijaga test, semuanya gagal TANPA error kalau lolos:**

1. **Pemisah `;`.** Excel berlokal Indonesia menulis begitu. Dibaca dengan `,`, seluruh berkas jadi
   SATU kolom bernama `nama;alamat;telepon` dan yang lahir 300 pelanggan bernama sampah. Pemisahnya
   ditebak dari **baris header saja** — alamat penuh koma bikin `,` menang telak di seluruh berkas.
2. **`levenshtein()` PHP menyerah di atas 255 byte dan mengembalikan −1.** Dan `−1 <= 2` itu BENAR,
   jadi tanpa penjaga tiap nama panjang jadi "mirip" dengan tiap nama panjang lain.
3. **`PT` vs `CV` jaraknya cuma 2** (`UD` vs `PD` cuma 1). Tanpa penjaga bentuk badan usaha,
   keduanya muncul berpasangan di layar tinjauan sebagai "nyaris sama" — dan itu yang paling
   gampang di-"gabung saja". Dua badan hukum, dua NPWP.
4. **Soft delete tetap memegang unique index.** `customers_organization_id_nama_unique` jalan di
   baris yang `deleted_at`-nya terisi juga. Tanpa `withTrashed()`, pelanggan yang pernah dihapus
   terbaca "belum ada", lalu database menolak insert-nya di tengah transaksi dan **semua** baris
   lain ikut batal.
5. **Telepon jadi notasi ilmiah.** Excel menyimpan `081234567890` sebagai angka →
   `8.1234567890E+11`. Dikosongkan + peringatan, bukan disimpan: yang tersimpan kelihatan wajar di
   kolom sempit, tapi tidak ada nomor di baliknya.
6. **Riwayat audit tanpa penanggung jawab.** `Diaudit` mengambil pelakunya dari `Auth::id()`, dan
   di baris perintah itu selalu kosong. `--oleh` diteruskan ke sana, kalau tidak impor 500
   pelanggan mendarat di `audit_logs` sebagai 500 pembuatan "oleh entah siapa".

**Tiga janji yang dikunci test:** idempoten (jalan kedua nol baris baru), `perlu_tinjau` tidak
pernah menyentuh database, dan impor **tidak pernah meng-update** baris yang sudah ada — satu
jalan ulang dengan berkas lama tidak boleh menimpa alamat yang sudah dibetulkan admin.

**Kenapa CSV saja, bukan xlsx.** Membaca xlsx butuh PhpSpreadsheet, dan yang dibeli cuma satu
langkah manual ("Save As CSV") untuk perintah yang jalan beberapa kali seumur hidup lab. Kalau
suatu saat lab memintanya, yang ditambah pembaca baru — pemilah, laporan, dan perintahnya tidak
perlu berubah.

### D. Milestone B — nyalakan driver direktori · **MENUNGGU KEPUTUSAN BIAYA**

**Nol kode.** Keempat variabel `DIREKTORI_PERUSAHAAN_*` sudah ada di `.env.example`, dan `auto`
(Google dulu, OSM di belakang) sudah didukung `PilihanDriver`. Yang tersisa satu baris `.env` plus
API key Places.

Tapi itu **membatalkan K16** ("keputusan pemilik proyek 31 Agt: nol tagihan"), jadi bukan keputusan
yang boleh diambil sendiri. Lihat pertanyaan P1 di `docs/pertanyaan-lab-data-pelanggan.md`.

Kalau disetujui, yang **wajib** menyertainya: batas kuota dipasang di konsol penyedia (cache cuma
melindungi dari pencarian berulang, bukan dari pencarian baru yang membanjir), key tidak pernah
masuk APK, dan harga/kuota diverifikasi **saat itu** — angka di komentar config ditulis per Maret
2025.

### E. Milestone C & D — belum dikerjakan

C (perapian & penggabungan kembar) dan D (status verifikasi alamat) menunggu A dipakai dulu dengan
data sungguhan. Rinciannya di `docs/pertanyaan-lab-data-pelanggan.md`.

**Yang tidak boleh dilanggar waktu C dikerjakan:** penggabungan **tidak menyentuh**
`certificates.snapshot`. Sertifikat yang sudah terbit tetap memuat data lama — itu benar, bukan
bug.

### F. Direktori lokal — 10.320 PT bisa dicari tanpa keluar server (2 Sep 2026)

Pemilik proyek mengirim dua berkas: **Kawasan Industri Jababeka** (450 PT, sumbernya blog
Rotogravure bertanggal 4 Okt 2020) dan **Indonetwork Jawa Barat** (9.870 PT, hasil pengambilan 333
halaman situs Indonetwork, 2 Sep 2026). Permintaannya: teknisi & admin tinggal cari nama PT,
alamatnya ikut — **tanpa bikin APK maupun panel admin berat**.

**Rancangan yang paling kelihatan benar justru yang paling merusak.** Menyeed 10.320 baris ke
`customers` akan lolos semua test dan kelihatan berhasil, lalu:
`lib/services/simpanan_pelanggan.dart` menyalin SELURUH daftar pelanggan ke SharedPreferences HP
tiap teknisi (supaya pemilih pelanggan jalan di pabrik nol sinyal), dan SharedPreferences dibaca
**utuh ke memori tiap aplikasi nyala**. Diukur dari berkas aslinya: **1,36 MB JSON**, diurai tiap
buka aplikasi, selamanya. Ukuran unduhan APK tidak berubah — yang berubah waktu nyalanya, dan itu
tidak kelihatan dari mana pun sampai ada yang mengeluh lemot.

Yang dibangun: tabel rujukan **terpisah** `direktori_lokal` (tanpa `organization_id` — isinya data
publik, bukan data lab), dibaca lewat driver baru yang **memenuhi kontrak `DirektoriPerusahaan`
yang sudah ada**. Akibatnya **nol berkas berubah di sisi HP**: `GET /customers/direktori` sudah
dipanggil `cariDirektori()`, dan atribusinya sudah dirender di `pelanggan_baru_screen.dart:458`.

Lapis lokal ditaruh **paling depan, selalu**, apa pun setelan drivernya. Aman karena tiga hal: nol
jaringan/kuota/tagihan; nol hasil bukan jawaban akhir buat `DirektoriBerlapis` jadi cakupan tidak
berkurang; dan tabel kosong bikin `tersedia()` false sehingga pemasangan yang belum mengimpor
berperilaku **sama persis** seperti sebelum fitur ini ada. Yang ikut didapat: pencarian yang ketemu
lokal tidak pernah sampai ke Google, jadi lapis ini justru **mengurangi** request berbayar.

**Satu bug nyata ketemu waktu membangunnya, dan bentuk gagalnya paling buruk:**
`AppServiceProvider` memanggil `tersedia()` waktu membangun `DirektoriPerusahaan`, dan
`GET /api/health` menyelesaikan `DirektoriPerusahaan` — jadi pemasangan yang migrasinya belum jalan
bikin **health membalas 500**, endpoint yang justru dipakai buat mendiagnosis kenapa pemasangannya
belum benar. Ditutup dengan menelan kegagalan BACA jadi 0, dan test-nya dibuktikan merah dulu.

Isinya **petunjuk, bukan kebenaran** — kedua sumber memperingatkan dirinya sendiri (Jababeka: "banyak
perusahaan sudah pindah, berganti nama, atau tutup"; Indonetwork: "keakuratannya bervariasi"). Layar
teknisi memajang "Belum diverifikasi — cocokkan dengan surat pesanan sebelum dipakai di sertifikat",
dan itu bukan hiasan: `certificates.snapshot` bikin alamat salah **tidak bisa ditarik**.

Baris jadi data lab HANYA setelah teknisi memilihnya — lahir sebagai `customers` baru dengan
`sumber`, `dibuat_oleh_user_id`, dan `direktori_ref` berawalan `lokal:`.

**Pemasangan di produksi terpaksa lewat boot, dan sebabnya di luar kendali kode.** Rencana awalnya
satu perintah manual sekali seumur hidup lewat Render Shell — ternyata **paket gratis Render tidak
menyediakan shell sama sekali** (*"Shell is not supported for free compute plans"*). Jadi impornya
disambungkan ke `docker/entrypoint.sh` di belakang `--lewati-kalau-terisi`, yang memeriksa ISI tabel
sebelum membaca berkas: boot pertama membayar penuh, boot berikutnya cuma dua query `COUNT`.
Diperiksa isinya, bukan penanda "sudah pernah jalan", supaya database yang direset terisi lagi
sendiri — tanpa shell, tidak ada jalan lain membetulkannya. Impor yang gagal **tidak menjatuhkan
boot**: direktori ini fitur kenyamanan, dan menukarnya dengan seluruh server yang dipakai teknisi di
lokasi adalah pertukaran yang salah arah.

**Catatan kepatuhan, diangkat bukan didiamkan:** data Indonetwork hasil pengambilan 333 halaman
situs, sementara §10 no. 3 dokumen strategi pelanggan melarang scraping situs direktori. Berkasnya
sudah ada di tangan pemilik proyek dan pemakaiannya keputusan dia; dicatat di sini supaya kalau
ditanya asesor jawabannya sudah tertulis. Serah-terima: `docs/perintah-direktori-lokal.md`.

---

## 17. Alat baru **Micrometer** (Panjang) — 4 Sep 2026

Empat workbook master turun sekaligus (`Master_Olah_Data_Micrometer_025mm.xlsm`,
`_2550mm`, `_5075mm`, `_75100mm`, semuanya ber-password). Pemilik proyek
menyebutnya "4 alat baru" — **ternyata satu alat, empat rentang ukur**: baris
lampiran akreditasi LK-285-IDN **no. 34 Micrometer**, kelompok **Panjang** — dan
yang pertama BERPROFIL di kelompok itu.

Yang membuktikan itu satu alat: sheet `PERHITUNGAN` dan `PERHITUNGAN U95%`
keempat workbook identik baris demi baris, tabel balok ukurnya identik (32
keping, diadu otomatis oleh skrip generatornya), dan yang berbeda cuma pita
CMC, kapasitas, serta balok ukur yang dipakai. Jadi **satu profil dengan empat
pita**, bukan empat profil — pola yang sama dengan `TimbanganProfile`.

Baris CMC-nya **sudah ter-seed sejak dulu** lewat `CalibrationCapabilitySeeder`
yang membaca lampiran akreditasi, dan angkanya (0,83 / 0,87 / 0,91 / 0,91 µm)
cocok **persis** dengan `DATABASE!S5:T8` keempat workbook. Jadi tidak ada
seeder kemampuan baru.

### Berkas yang dibuat/diubah

Disebut SEBELUM mengetik, sesuai §12:

| Berkas | Isi |
|---|---|
| `database/data/tabel-standar-micrometer.json` | 32 balok ukur + 4 pita CMC + tetapan — **digenerate skrip** |
| `database/data/sesi-master-micrometer.json` | empat sesi contoh, digenerate skrip |
| `docs/skrip/gen-{tabel-standar,fixture,sesi}-micrometer.py` | tiga generator |
| `app/Services/Calibration/TabelStandarMicrometer.php` | pembaca tabel + penjagaan "tidak ketemu = null" |
| `app/Services/Calibration/MicrometerCalculator.php` | mesin hitung, 9 komponen tingkat-sesi |
| `app/Services/Calibration/Profiles/MicrometerProfile.php` | bentuk lembar + `hitungPerGrup()` |
| `app/Support/MicrometerMentah.php` | penyusun ulang tumpukan balok + deret pembacaan |
| `database/seeders/MicrometerSeeder.php` | **tiga** sesi contoh (dari empat varian — §21), angkanya DIHITUNG |
| `database/ocr-templates/micrometer-v1.json` | rangka geometri, digenerate `ocr:rangka-geometri` |
| `CalibrationProfileRegistry`, `CalibrationValidator`, `HitungUlangSesi`, `CalibrationController` | pendaftaran + dua jalur hitung ulang + jalur simpan |
| `tests/Unit/MicrometerMasterTest.php` + `tests/Fixtures/micrometer-master.json` | adu ke empat master |

**Nol kolom baru** di `raw_measurements` — sumbu `peran_sensor`/`sensor_ke`
cukup (`mikro_balok` / `mikro_pembacaan`), dan blok tingkat-sesi (pra-evaluasi,
suhu, kapasitas, resolusi) masuk `spesifikasi_alat`. Alat kelima berturut-turut
yang mendarat tanpa migrasi.

### Rumusnya dibuktikan dulu, baru ditulis

53 nilai diadu ke keempat workbook pada toleransi 5·10⁻⁶ — sembilan komponen
budget, `uc`, `veff`, `k`, `U95`, plus rumus drift dari tanggalnya sendiri.
Semuanya cocok. Termasuk konfirmasi bahwa `TINV` Excel memotong `veff` ke
bilangan bulat, yang sudah dilakukan `GumCalculator::agregasiBudget()`.

### Temuan yang mengubah angka tercetak

Lengkapnya di `docs/pertanyaan-lab-micrometer.md`. Dua yang paling tajam:

1. **Sesi 0-25 mm master terbit DI BAWAH lantai CMC-nya sendiri** — 0,735 µm
   padahal pitanya 0,83 µm. Rantainya: satuan tersetel `inch` → kapasitas
   25 × 25,4 = 635 mm → jatuh di luar keempat pita → lookup CMC memulangkan
   teks `"cek range"` → `MAX()` Excel mengabaikan teks → U95 terbit telanjang.
   **Persis bahaya yang sudah ditulis di permintaan 1**, sekarang terbukti
   terjadi di master lab sendiri. Kode memblokir sesi seperti itu.
2. **Umur drift dari `NOW()`** — U95 sesi yang sama tidak pernah terulang.
   Keempat workbook disimpan selang dua menit dan umur driftnya sudah beda
   (695,4212 vs 695,4225 hari). Kode memakai tanggal kalibrasi sesi.

### Kertasnya turun belakangan — lembar disetel ulang ke kertas

Sesudah semuanya di atas hijau, pemilik proyek mengirim **kertas lembar kerja
resminya**: `SIDIK-FM-CAL-0522.{A,B,C,D}_Rev.1`, empat, satu per rentang. Bentuk
lembar yang tadinya ditebak dari sheet `INPUT DATA` Excel disetel ulang
mengikuti kertas — permintaan 6 memang memerintahkan lembar mengikuti PDF resmi.

Yang berubah:

| | Sebelum (dari `INPUT DATA`) | Sesudah (dari kertas) |
|---|---|---|
| Nomor formulir | `null` — dikira belum ada kertasnya | **empat**, dipilih dari `equipments.range_max` |
| Bagian | 7 | **6** — `pra_evaluasi` & `pemeriksaan_muka` hilang |
| Tabel `hasil` | 2 (`mikro_balok` + `mikro_pembacaan`) | **1**, 11 baris **pra-cetak**, `titik_bisa_diubah = false` |
| Nominal titik | diketik teknisi | **dipatok kertas** — server memenangkannya atas kiriman HP |
| Tumpukan balok ukur | dikirim HP | **disusun server** dari varian |
| Blok pra-evaluasi | 2 tabel + 2 field suhu | **1 baris `Evaluasi`** (X1..X10) |
| Suhu balok ukur & UUT | 2 kotak isian | **diturunkan** dari rata-rata suhu ruangan |
| Template OCR | field datar | **2 tabel / 65 sel** |

Dua yang perlu dicatat karena keduanya keputusan, bukan penyesuaian mekanis:

1. **Suhu balok ukur & UUT diturunkan, tidak diminta.** Kertas tidak punya
   kotaknya, dan di **keempat** workbook master `suhu_balok = suhu_uut =
   (suhu_awal + suhu_akhir) / 2`. Jadi identitas itu diberlakukan dan
   ditegakkan test. Akibatnya komponen ke-9 budget ("selisih suhu mikrometer
   dengan balok ukur") selalu nol menurut konstruksi — ditiru apa adanya, dan
   diangkat sebagai pertanyaan lab §8.
2. **Kategori alatnya `Panjang`, bukan "Dimensi".** Seeder-nya sempat membuat
   kategori sendiri bernama Dimensi — kelompok yang tidak ada di lampiran.
   Yang lahir dari situ kategori HANTU: kartu kesebelas di layar pilih
   kategori HP, nol kemampuan kalibrasi di dalamnya, sementara alat contohnya
   duduk di sana dan baris CMC Micrometer tetap di Panjang. Nol error di kedua
   sisi; ketahuan waktu daftar kategori HP diadu ke lampiran. Sekarang dijaga
   `KategoriAlatIkutLampiranTest`, yang menyapu SEMUA kategori ter-seed, bukan
   cuma Micrometer.
3. **44 nominal pra-cetak diadu ke total tumpukan master**, toleransi 0,06 mm,
   nol selisih — generatornya menolak menulis kalau ada yang meleset. Termasuk
   titik 3 varian 25-50 mm (31 mm) dan 50-75 mm (51 mm), yang keluar dari pola
   +2,6 mm dan sempat disangka salah ketik kertas. **Kertasnya benar**: total
   masternya 30,99997 dan 51,00025 mm. Yang menentukan nominal adalah tumpukan
   keping yang tersedia, bukan deret aritmetika.

Serah-terima frontend: `docs/perintah-frontend-micrometer.md` — **ditulis ulang
penuh** untuk bentuk kertas ini; revisi lamanya sudah tidak berlaku.

---

## 18. Angkat helper profil yang terduplikasi — 4 Sep 2026

Diminta pemilik proyek sesudah alat ke-25 mendarat, karena Micrometer baru saja
menyalin keempatnya sekali lagi.

**Yang ditemukan waktu keempat salinan diadu** (langkah yang diminta duluan,
sebelum satu baris pun diubah):

| Helper | Salinan | Varian | Sebab variasinya |
|---|---|---|---|
| `field()` | 16 | 4 | dua profil menambah kunci sendiri (`di_kertas`, `ekstra`) |
| `isiPilihanThermohygro()` | 16 | **7** | bentuk `THERMOHYGRO_TERCETAK`-nya sendiri berbeda |
| `tautkanStandarTercetak()` | 3 | 2 | cuma visibilitas |
| `kemampuanSesi()` | 2 | 1 | identik |

Variasi `isiPilihanThermohygro` **bukan kecerobohan**: konstantanya ditulis dua
gaya — larik string untuk lembar yang kop masternya tidak memisahkan
Inlab/Insitu, larik objek untuk yang memisahkan — dan itu menuruti kertasnya.
Jadi yang diseragamkan **pembacanya, bukan datanya**: induk menerima kedua
bentuk dan meneruskan kunci tambahan (`di_kertas`, `tercetak`) apa adanya, di
posisi yang sama.

**Hasil:** 37 salinan → 6 (4 di induk + 2 override bersebab). Lapisan profil
menyusut **1.109 baris**, bertambah 227 (188 di antaranya kelas induk).

**Dua override yang DIPERTAHANKAN, masing-masing dengan komentar WHY:**

- `TidsProfile::isiPilihanThermohygro()` — kelas itu punya
  `THERMOHYGRO_TERCETAK` sendiri yang **artinya berbeda** (baris tabel kondisi
  lingkungan, berkunci `nama`/`lokasi`); daftar dropdown-nya di
  `THERMOHYGRO_PILIHAN`.
- `SpectrophotometerProfile::field()` — `di_kertas` duduk SEBELUM
  `tampil_kalau`, dan jalur `$ekstra` induk menyebar di belakang. Memakainya
  menggeser urutan kunci, dan bentuk field ikut dibandingkan utuh oleh test.

Perilaku tidak berubah: yang menjaganya `SemuaProfilLembarKerjaTest` dan
`ThermohygroSemuaLembarTest`, dua-duanya menyapu SEMUA profil terdaftar.

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

## 19. Sisi HP lembar Micrometer — 4 Sep 2026

Diminta pemilik proyek langsung sesudah §17: *"sekarang kerjain sisi mobile nya ya"*.

### Yang ternyata TIDAK perlu dibuat

Penggambar lembar di HP jauh lebih data-driven daripada dugaan awal. Empat hal
yang disangka pekerjaan baru sudah ada dan generik: `titik_bisa_diubah` (bawaan
`false`, jadi baris terkunci jalan sendiri), `simpan_ke`, `offset_kunci`, dan
render `belum_dihitung`. Kategorinya pun tidak perlu ditambah — lihat catatan
kategori hantu di §17.

Yang dikerjakan tinggal empat: mock lembar (**digenerate** dari respons server,
bukan diketik), cabang `'micrometer'` di `lembar_kerja_service.dart`, satuan
alat masuk `_kodePenentuAngka` supaya teknisi DITANYA kalau belum memilihnya,
dan `test/micrometer_lembar_test.dart` (9 test).

### Tiga cacat SERVER yang ketahuan justru dari sini

Ini nilai sebenarnya dari mengerjakan sisi HP-nya. Ketiganya lolos seluruh
sapuan backend — 3.128 test hijau — karena test backend memakai payload yang
DITULIS backend sendiri. Yang menangkapnya cuma mengadu payload HP yang asli ke
bentuk lembarnya:

1. **Kode kolom tabel `nilai`.** Dua puluh empat lembar lain memakai
   `pembacaan`, dan itu satu-satunya kode yang dibaca jalur datar HP
   (`TitikState.toSubmission()` membacanya harfiah). Server menengok
   `measurements[].mikro_pembacaan` yang tidak pernah dikirim siapa pun:
   **nol baris tersimpan, nol hitungan**, tanpa satu pun error di kedua sisi.
   Sesi yang dikirim teknisi bakal sampai ke admin sebagai lembar KOSONG.
2. **Blok Evaluasi bentuk tabel.** HP mengirim setiap tabel ber-`simpan_ke`
   sebagai cerminan tabelnya (`{baris: [{titik_ukur, pembacaan: […]}]}`), bukan
   larik datar — sama seperti blok keterulangan Timbangan. Server menuntut
   larik datar, jadi **setiap sesi Micrometer dari HP pulang 422**, dengan
   keluhan yang menunjuk sepuluh angka yang sudah benar diisi teknisi.
3. **Pembacaan Evaluasi tidak ikut dikonversi satuan.** Sekarang dikonversi, dan
   di **kedua** bentuk (tabel maupun datar). Melewatkan yang datar berarti dua
   bentuk yang membawa angka sama berarti beda, tanpa ada error yang
   membedakannya — satuan itu sifat SESI, bukan sifat pembungkus payload.

Ketiganya ditambal di sisi server, dan yang menjaganya sekarang test di KEDUA
repo: `MicrometerSesiTest` mengirim bentuk HP yang asli, `micrometer_lembar_test.dart`
mengunci bentuk yang dikirim HP. Satu test saja di salah satu sisi tidak cukup —
itu yang membuat ketiganya bisa lolos sejak awal.

### Dua sapuan umum yang lahir dari sini

Ditulis sebagai ATURAN, bukan tambalan satu profil — keduanya kelas kegagalan
yang sudah berulang:

- **`SemuaProfilLembarKerjaTest::test_tabel_sekunci_tidak_berbagi_kunci_baris`.**
  Dua tabel yang di HP berbagi `kunciTabel` tidak boleh berbagi kunci baris.
  Sudah tiga kali: Thermohygrometer (set point 50 di dua blok), Timbangan
  (Accuracy vs Repeatability), Micrometer (Evaluasi vs Data Kalibrasi — belum
  sempat menggigit, cuma karena tidak ada nominal 1,0 mm). Sapuan ini
  membuktikan gigitannya dengan mencabut `offset_kunci` Timbangan: merah, lalu
  hijau lagi sesudah dikembalikan.
- **`KategoriAlatIkutLampiranTest`.** Kategori alat = sepuluh kelompok
  pengukuran lampiran akreditasi, titik. Menyapu SEMUA kategori ter-seed, bukan
  cuma Micrometer.

Serah-terima lengkapnya: `docs/perintah-frontend-micrometer.md` §9 & §9a.

---

## 20. Audit adversarial Micrometer — 4 Sep 2026

Diminta pemilik proyek: selesaikan sampai sertifikat terbit, lalu jalankan Bug
Hunter Protocol. Keduanya dikerjakan berurutan.

### Sertifikat diadu ke sheet `SERTIFIKAT` master

Sesi contoh `0106-CAL-1023` diterbitkan sungguhan, HTML-nya dirender, lalu
diadu baris demi baris ke `SERTIFIKAT!D18:L28`. Kesebelas baris
(Standar Reading / Unit Under Test / Correction) **cocok pada 5·10⁻⁶**.

U95 kita 0,00087097 mm vs master 0,00087377 mm. Selisihnya PERSIS komponen
drift yang di-nol-kan karena sesi contoh mendahului sertifikat balok ukurnya
(0,06192/√3 = 0,03575 µm dalam kuadratur) — penyimpangan §2 yang memang
disengaja. Di lima desimal keduanya tercetak `0,00087`.

Dijaga `MicrometerSertifikatTest` (5 test), yang merender HTML-nya, bukan cuma
memeriksa snapshot.

### Empat cacat yang ketemu — semuanya TANPA error

| # | Cacat | Dampak | Terbukti lewat |
|---|---|---|---|
| 1 | U95 disimpan µm, dicetak di kolom mm tanpa label satuan | Sertifikat menampilkan `0,00027` dan `0,871` di kolom yang SAMA — pembaca membaca U95 **1000× lebih besar** | render HTML diadu ke kertas master |
| 2 | Konversi satuan di ujung MASUK tidak idempoten | Simpan draft → buka → simpan lagi mengalikan 25,4 **tiap kali**: 1 inch → 25,4 → **645,16 mm** | reproduksi jalur draft PUT |
| 3 | Resolusi kosong menghapus komponen budget | U95 0,8722 → 0,6638 µm, lalu **ditutupi lantai CMC 0,87** jadi tercetak 0,8700 — selisih 0,25 % | dihitung dua kali dengan/tanpa resolusi |
| 4 | Baris dipetakan lewat POSISI indeks | Kalau HP membuang baris kosong, pembacaan mendarat di nominal yang salah (~4 mm meleset) | pembacaan kode lintas repo |

Cacat 1–3 sudah ditambal; cacat 4 dijaga dengan pemeriksa `titik_ukur` yang
mengubah salah-pemetaan jadi penolakan yang kebaca.

**Cacat 2 yang paling mahal**, dan cara ketemunya yang layak dicatat: dia cuma
muncul di jalur DRAFT. Sesi final berstatus `menunggu_approval` menolak PUT
dari teknisi, jadi test apa pun yang lewat jalur final hijau tanpa pernah
menyentuh bug-nya. Reproduksinya wajib memakai `status: draft`.

Akar sebabnya sekarang dihapus, bukan ditambal: **konversi satuan tidak lagi
terjadi waktu menyimpan.** Yang tersimpan angka mentah yang diketik teknisi
berikut satuannya (`raw_measurements.satuan`, `spesifikasi_alat.micrometer.satuan`),
dan yang mengubahnya ke mm `MicrometerMentah::keMm()` di tempat pakai. Menyimpan
payload yang sama dua kali sekarang menghasilkan baris yang sama persis.

### Dua pertanyaan lab baru

- **§9** — sel sertifikat master berformat `0.000`, jadi cetakannya menampilkan
  koreksi `0.000` di kesebelas titik dan U95 `0.001`. TIDAK ditiru (kita lima
  desimal); perlu diputuskan apakah sertifikat lama ditinjau.
- **§10** — kertas Rev.1 membuang Kerataan/Kesejajaran Muka Ukur, sementara
  formulir sertifikat masih menyediakan barisnya. Kode mengikuti KERTAS; lab
  yang memutuskan mana yang menyusul.

---

## 21. Cuma SATU dari empat rentang Micrometer yang ter-seed — 4 Sep 2026

Pemilik proyek bertanya lurus: *"empat yang saya kirim itu udah bener-bener apa
cuma gimmick, ada yang bolong?"* Jawabannya ada yang bolong, dan bolongnya di
tempat yang tidak berbunyi.

**Yang ketemu.** `MicrometerSeeder` menanam **satu** sesi (`_dipakai_seeder:
"2550"`), padahal `TimbanganSeeder` — pola yang seharusnya diikuti — menanam
ketiga variannya. Tiga pita CMC sisanya lolos SELURUH sapuan registry tanpa
pernah dijalankan ujung ke ujung sekali pun. Bukan test yang merah: test-nya
memang tidak pernah menyentuh varian itu.

Ini juga membuat catatan §17 dan `docs/perintah-frontend-micrometer.md` **salah**:
keduanya menulis "empat sesi contoh ter-seed". Keduanya sudah dikoreksi.

**Yang ditambal.** Varian C (50-75 mm, `002-UB.P-11-20`, Mahr) dan D (75-100 mm,
`003-UB.P-11-20`, Mitutoyo) sekarang ikut ditanam. Keduanya lengkap: 11 titik
ukur, sertifikat terbit, dan U95 masing-masing 0,00091 mm — di atas lantai CMC
pitanya. `MicrometerSertifikatTest` sekarang mengadu **ketiganya** ke sheet
`SERTIFIKAT` masternya lewat DataProvider, bukan cuma varian B.

**Yang TIDAK ditambal, dan kenapa.** Varian A (0-25 mm, `095-CAL-324`) tetap
tidak ditanam. Sebabnya bukan label satuannya — itu bisa dibetulkan — melainkan
blok pra-evaluasinya: **635,0 sepuluh kali**, nilai kapasitas hasil bug inch
yang bocor ke sana. Simpangan bakunya NOL. Menanamnya berarti menerbitkan sesi
yang komponen keterulangannya nol lalu ditutupi lantai CMC dan tampak wajar —
persis jebakan yang dijaga seluruh alat ini (§20 cacat no. 3, bentuk yang sama).
Membetulkannya berarti MENGARANG data keterulangan, dan keterulangan itu yang
jadi dasar seluruh budget.

Jalur blokirnya sendiri tidak bergantung pada sesi ter-seed:
`MicrometerMasterTest::test_kapasitas_di_luar_pita_cmc_diblokir_bukan_diterbitkan`
dan `MicrometerSesiTest::test_kapasitas_di_luar_pita_cmc_diblokir` menegakkannya
dari fixture.

**Pelajaran yang mahal.** Sapuan yang daftarnya datang dari database gagal tanpa
bersuara: `SertifikatSemuaAlatSatuHalamanTest` hijau dengan satu sertifikat
Micrometer persis seperti dia hijau dengan tiga. Yang menangkapnya cuma
pertanyaan manusia. Untuk alat berikutnya: **jumlah sesi ter-seed per varian
adalah angka yang harus dipatok di test**, bukan diserahkan ke seeder.

---

## 22. Keterulangan nol ditahan, dan §1/§3/§9 dijawab — 5 Sep 2026

Pemilik proyek minta ketiga pertanyaan lab yang menyangkut sertifikat terbit
dijawab sendiri: *"pake sepengatahuan kamu dan juga se enak nya kamu"*.

**Yang ternyata bukan cuma urusan dokumen.** §3 menyimpan cacat kode yang belum
ketahuan: gerbang penerbitan menghitung `n >= 2` pembacaan pra-evaluasi, tapi
tidak memeriksa apakah pembacaannya BERAGAM. Sepuluh nilai identik lolos mulus.

Diukur pada sesi 25-50 mm, hanya blok pra-evaluasinya diganti:

| Pra-evaluasi | `uc` | U95 terbit |
|---|---|---|
| beragam (asli) | 0,4439 µm | 0,8722 µm |
| sepuluh nilai identik | 0,4325 µm | **0,8700 µm** |

Angka kedua persis lantai CMC pita B. Bentuk kegagalan yang **sama persis**
dengan resolusi kosong (§20 cacat no. 3): komponen lenyap, U95 jatuh, lantai
menutupinya, hasilnya tampak wajar, nol error.

**Penjaga yang hampir dipasang, dan kenapa salah.** Refleks pertama: tolak
pembacaan di luar rentang alat. Itu **tidak menangkap kasus ini** — kapasitas di
workbook 0-25 mm ikut terkonversi jadi 635, jadi 635 memang di dalam rentangnya
sendiri. Bug-nya konsisten dengan dirinya. Yang membedakan varian cacat dari tiga
varian sehat cuma simpangan baku nol (3,2e-4 sampai 5,3e-4 mm di yang sehat).

Ini kedua kalinya "penjaga yang kedengarannya benar" ternyata tidak menyentuh
cacatnya. Pelajarannya: **ukur dulu penjaganya pada data cacat yang nyata**,
jangan pilih penjaga dari nalar saja.

**Yang sengaja TIDAK dilakukan:** memberi keterulangan lantai berbasis resolusi
waktu sebarannya memang nol — perlakuan lazim EA-4/02. Memilih lantai berarti
MENGUBAH U95 yang terbit, dan itu keputusan metode milik manajer teknis, bukan
keputusan yang boleh diambil diam-diam oleh kode.

### Berkas

| Berkas | Isi |
|---|---|
| `app/Services/Calibration/MicrometerCalculator.php` | gerbang stdev nol + alasan yang kebaca |
| `app/Console/Commands/AuditMicrometerCmc.php` | **BARU** — pelingkup arsip untuk tinjauan ketidaksesuaian |
| `tests/Feature/AuditMicrometerCmcTest.php` | **BARU** — 5 test, perintahnya benar-benar dijalankan |
| `tests/Unit/MicrometerMasterTest.php` | `test_pra_evaluasi_seragam_memblokir_penerbitan` |
| `docs/keputusan-lab-micrometer.md` | **BARU** — formulir keputusan satu halaman, siap diteken |

### Kenapa ada perintah artisan baru

`analisis-pertanyaan-lab-micrometer.md` §1 langkah 2 meminta lab "telusuri
arsip, daftar sertifikat yang U95-nya di bawah pita CMC". Selama itu kerjaan
tangan, tinjauannya mandek — bukan karena keputusannya sulit, tapi karena
datanya belum ada di meja.

`php artisan micrometer:audit-cmc --csv=...` menyisir seluruh arsip lintas
organisasi dan memulangkan tiap sesi yang kena salah satu dari **tiga** cacat
sebentuk: U95 di bawah lantai, keterulangan nol, resolusi kosong. Mencari satu
tanpa dua lainnya berarti melingkupi sepertiga masalah lalu mengira selesai.

Sengaja lintas organisasi (menyimpang dari aturan penyaringan `organization_id`
di seluruh repo) karena ini perkakas terminal, bukan endpoint — dan tinjauan
akreditasi memang harus melihat seluruh arsip.

Test-nya menjalankan perintahnya sungguhan, bukan memeriksa kodenya. Yang mahal
kalau dilewat bukan kegagalan besar melainkan salah nama kolom: `nomor` versus
`nomor_sertifikat` lolos `php -l`, lolos review mata, dan baru mati waktu
manajer teknis menjalankannya buat rapat. (Kejadian, dan ketangkap di sini.)

### Batas yang tidak dilanggar

Ketiga temuan menunjuk ke satu sertifikat yang sudah di tangan pelanggan
(`095-CAL-324`, PT Nusantara Perawatan Kulit, terbit 14 Mar 2024). Menarik atau menerbitkan ulang
sertifikat itu tindakan formal manajer teknis di bawah klausa Pekerjaan Tidak
Sesuai ISO/IEC 17025 — **tidak diputuskan sistem**, dan tidak akan.

Yang disiapkan: rekomendasi tegas, angka pendukungnya, alat pelingkupnya, dan
formulir siap teken. Yang tidak: tanda tangannya.

---

## 23. Alat baru **Height Gauge 600 mm** (Panjang) — 7 Sep 2026

Satu workbook master turun (`Master_olda_Height_Gauge_600_mm_2026.xlsm`,
ber-password), sudah diekspor ke CSV di
`Project-PT-Sidik/alat-alat-Pt-Sidik/panjang/Height_Gauge_600mm_CSV/`. Alat
ke-26, kelompok **Panjang** — yang KEDUA berprofil di kelompok itu sesudah
Micrometer.

Ekstensinya `.xlsm` tapi **tidak ada `vbaProject.bin`**: checkbox
"Good / Not Good"-nya Form Control biasa, jadi tidak ada logika tersembunyi.
Ada dua tautan luar (`SERTIFIKAT!Q11` dan `D34`) tapi keduanya cuma LABEL
(`⁰C`, `Uncertainty U95% = ±`) — literalnya ditulis di profil, bukan ditiru.

### Yang membuat alat ini beda dari 25 lainnya

**Dia DI LUAR lampiran akreditasi LK-285-IDN.** Kelompok Panjang di
`kemampuan-kalibrasi.json` cuma memuat Sieve, Micrometer, Vernier Caliper, dan
Dial Indicator. Masternya sendiri mengakuinya: sel lantai CMC
(`PERHITUNGAN U95%!AA19`) **kosong**, jadi `U95 = U` telanjang.

Itu membalik taruhan penjagaan komponen. Di Micrometer, budget yang kehilangan
komponen mendarat di lantai CMC dan hasilnya masih di atas kemampuan
terakreditasi — salah, tapi tertampung, dan justru lantai itu yang
menyamarkannya. Di sini **tidak ada yang menampung maupun menahan**: U95
langsung terbit terlalu kecil, tanpa satu pun angka yang terlihat ganjil. Jadi
gerbang penerbitannya dipatok eksplisit (tiga syarat) dan yang menahan
**ketiadaan baris hitungan**, bukan peringatan sesi.

Perlakuannya ikut preseden **Gas Detector**: baris kemampuan tetap dibuat
dengan CMC nol supaya jalur budget penuh tetap jalan.

### Tiga blok yang TIDAK sebangun

Yang paling membedakan bentuknya: satu sesi punya tiga blok pengukuran, dan
cuma satu di antaranya berbentuk titik ukur.

| Blok | Bentuk | Tempat simpan |
|---|---|---|
| 1. Paralelisme Ujung Scriber | 3 pembacaan, tingkat SESI | `spesifikasi_alat.height_gauge.paralelisme` |
| 2. Evaluation (pra-evaluasi) | 10 pembacaan berulang, tingkat SESI | `spesifikasi_alat.height_gauge.pra_evaluasi` |
| 3. Measurement | 10 titik ber-nominal PRA-CETAK × 3 pembacaan | `raw_measurements` |

Blok 1 tidak masuk budget dan tidak melahirkan titik ukur — dia catatan
kelulusan di kaki sertifikat. Blok 2 satu-satunya sumber Repeatability seluruh
sesi. **Nol kolom baru** di `raw_measurements`.

### Berkas yang dibuat/diubah

Disebut SEBELUM mengetik, sesuai §12:

| Berkas | Isi |
|---|---|
| `database/data/tabel-standar-height-gauge.json` | tabel Caliper Checker (Outside + Inside) & tetapan — **digenerate skrip** |
| `database/data/sesi-master-height-gauge.json` | sesi contoh + `_acuan_master`, digenerate skrip |
| `docs/skrip/gen-tabel-standar-height-gauge.py` | generator tabel standar |
| `docs/skrip/gen-sesi-height-gauge.py` | generator sesi contoh |
| `app/Services/Calibration/TabelStandarHeightGauge.php` | pembaca tabel, "nominal tidak ketemu = `null`" |
| `app/Services/Calibration/HeightGaugeCalculator.php` | 10 titik + 9 komponen budget (satuan **mm**) |
| `app/Services/Calibration/Profiles/HeightGaugeProfile.php` | bentuk lembar + `hitungPerGrup()` |
| `app/Support/HeightGaugeMentah.php` | susun ulang baris mentah + blok sesi |
| `database/seeders/HeightGaugeCapabilitySeeder.php` | baris kemampuan CMC nol (pola Gas Detector) |
| `database/seeders/HeightGaugeSeeder.php` | sesi contoh `001-UBLK-05.26`, angkanya DIHITUNG |
| `database/ocr-templates/height_gauge-v1.json` | digenerate `ocr:rangka-geometri`, `terverifikasi: false` |
| `tests/Unit/HeightGaugeMasterTest.php` | 17 test / 87 asersi, toleransi 5·10⁻⁶ |
| `tests/Feature/HeightGaugeSesiTest.php` | jalur simpan → hitung ulang + gerbang `boleh_terbit` |
| `tests/Feature/HeightGaugeSertifikatTest.php` | yang TERCETAK, termasuk satu temuan terbuka |
| `docs/pertanyaan-lab-height-gauge.md` | sepuluh butir bernomor |
| `docs/perintah-frontend-height-gauge.md` | kontrak buat repo mobile |

Yang **diubah** di luar berkas baru — dan semuanya satu baris/blok:
`CalibrationProfileRegistry` (satu `new HeightGaugeProfile`),
`CalibrationProfile` (hook `butuhBlokHeightGauge()`), `CalibrationValidator` +
`HitungUlangSesi` + `CalibrationController` + `CalibrationRequest` (wiring
`HeightGaugeMentah`), `UjiProfilKalibrasi` (ikut cabang Micrometer),
`DatabaseSeeder`, `EquipmentFactory` (cabut `'Height Gauge'`), plus tiga daftar
pengecualian sapuan dan hitungan profil di `docs/kontrak-api.md`.

### Angkanya dibuktikan SEBELUM PHP ditulis

Reimplementasi Python diadu ke workbook sel demi sel lebih dulu: kesepuluh
koreksi, kesembilan `ui`/`ci`/`vi`, dan kelima agregat — **nol beda** pada
5·10⁻⁶. Satu-satunya yang tidak cocok justru kontrol yang benar: `veff` harus
**dipotong ke bawah** sebelum `TINV`, persis yang sudah dilakukan
`GumCalculator::agregasiBudget()`.

```
Σ(ui·ci)² = 5,6417135376875704e-05
uc        = 0,0075111340939218825 mm
veff      = 20,474220669021
k         = 2,085963447265865
U         = 0,01566795116743346   mm
```

### Penyimpangan master — tiga ditiru, tiga tidak

**Ditiru** (kejanggalan METODE, diangkat jadi pertanyaan lab): pembagi drift
`/12` padahal selisihnya HARI (§1 — dipertahankan karena `/365` membuat U
**lebih kecil**, dan aturan proyek melarang penyimpangan yang mengecilkan
ketidakpastian); pembagi `√6` pada komponen berlabel `rect.` (§2); paralelisme
`STDEV(Max; Min)` alih-alih `Max − Min` (§5).

**Tidak ditiru** (kerusakan yang menggeser angka):

1. **Kolom termal cuma terisi di baris 35.** Baris 38..62 kosong dan rumus
   `Y`-nya membaca sel kosong sebagai nol. Hari ini tidak menggeser apa pun
   (`T35 = 0` juga), tapi begitu lab mencatat suhu UUT ≠ suhu standar, **cuma
   titik pertama yang terkoreksi**. Suku termal kami hidup di kesepuluh titik;
   arahnya ditegakkan test.
2. **Umur drift dari `NOW()`** — dipakai tanggal kalibrasi sesi. Akibatnya sesi
   contoh terbit `0,0156260 mm`, bukan `0,0156680 mm`; selisih 0,27 % yang
   seluruhnya berasal dari tanggal.
3. **`SERTIFIKAT!L27` cabang `inch` menunjuk kolom `M`** (rata-rata pembacaan)
   alih-alih `AA` (koreksi) — salin-tempel murni; kolom koreksi kami satu jalur.

Dua lagi yang **tidak dicetak** karena tidak bisa dikarang: tabel "Kesejajaran
Muka Ukur" Atas/Tengah/Bawah yang sembilan selnya `#REF!` (§4), dan checkbox
Kerataan Muka Ukur yang **dua-duanya tercentang** di sesi contoh — di lembar
kami dia satu field pilihan, karena dua boolean yang saling meniadakan tidak
bisa divalidasi.

### Satu bug SUNYI yang ketemu waktu test ditulis

Gerbang "sepuluh pembacaan identik ditolak" ditulis `stdev > 0` mengikuti
Micrometer — dan **diam-diam tidak pernah menyala**. Simpangan baku sepuluh
nilai identik cuma nol EKSAK kalau nilainya bisa direpresentasikan persis dalam
biner:

```
sepuluh kali 50,00  -> stdev 0,0e+0    (penjaga menyala)
sepuluh kali 599,95 -> stdev 1,2e-13   (penjaga TIDAK menyala)
```

599,95 justru nilai yang dipakai blok Evaluation sesi contoh. Jadi versi
`stdev > 0` lolos di test berangka bulat dan diam di data sungguhan.
Diganti `max !== min`, yang menguji hal yang sama tapi eksak apa pun nilainya.

> Penjaga yang sama di `MicrometerCalculator` **belum disentuh** — di luar
> lingkup permintaan ini, dan di sana lantai CMC masih menampungnya. Perlu
> dijadwalkan tersendiri.

### Status

**BERES di server** (7 Sep 2026), **dan sisi mobile juga** (7 Sep 2026, PR
mobile #161) — lembarnya kegambar, payload-nya sampai, 8 test baru. Menyambungkannya
membongkar satu drift lama: `Micrometer` masih ditulis `punyaToleransi=true` di
mock DAN di tabel vonisnya padahal server berbalik ke `false` sejak 4 Sep, jadi
di build `USE_MOCK=true` Micrometer memaksa teknisi mengisi toleransi yang
masternya tidak punya.

**Ekor 8 Sep 2026 — ritual `SEED_ON_BOOT` dicabut.** Menaruh baris kemampuan
Height Gauge ke produksi ternyata butuh menyalakan `SEED_ON_BOOT=true`,
redeploy, lalu mematikannya lagi — dan itu menjalankan `db:seed` PENUH (4,5
menit, plus data demo 26 alat), sementara langkah "matikan lagi" tidak
menerbitkan error kalau terlupa. Sekarang `entrypoint.sh` menjalankan
`kemampuan:pastikan` tiap boot: menanam baris kemampuan & nomor IK profil yang
belum ada, dan CUMA itu. Pemeriksaannya satu query, jadi boot yang sudah lengkap
tidak membayar apa-apa — penting, karena `CalibrationCapabilitySeeder` sendiri
makan 24,5 detik di produksi. Ikut ketahuan di situ: nomor IK
`SIDIK-IK-CAL-0539` tidak pernah ada di master `calibration_methods`, karena
`MetodeKalibrasiSeeder` membaca CSV ekspor manual berisi 34 baris yang lahir
sebelum alat ini.

**Klaim akreditasi diperbaiki di hari yang sama.** Sertifikat sempat membawa
`Terakreditasi … No. LK-285-IDN` untuk alat yang tidak diakreditasi, karena
klaim itu dicetak **tanpa syarat** di tingkat organisasi — dan **Gas Detector
sudah kena hal yang sama sejak alat ke-10**. Sekarang bersyarat lewat
`CalibrationProfile::dalamLingkupAkreditasi()` (bawaan `true`, `false` untuk dua
alat itu), dibekukan ke snapshot, dan kop banner ikut disetop karena
`kop-surat.png` memuat nomornya di dalam gambar. Dijaga
`KlaimAkreditasiIkutLingkupTest`.

Yang **belum** dan memang bukan keputusan kode: sertifikat kedua alat yang
**sudah terbit** tetap membawa klaim lamanya (snapshot-nya beku). Pertanyaan lab
§6 sekarang menanyakan perlakuannya, bukan lagi apakah klaimnya boleh dicetak.

---

## Permintaan 16 — U95 per titik di sertifikat instrumen analitik

Dari pemilik lab (Pak Rohman) lewat pemilik proyek, 3 September 2026: di
sertifikat **instrumen analitik**, nilai Uncertainty harus muncul di **tiap titik
pengukuran**, bukan satu angka untuk seluruh tabel.

Pertanyaan yang menyertainya — "cuma di analitik saja atau ada di yang lain
juga?" — dijawab dengan **mengukur**, bukan menebak: seluruh 24 sertifikat
bawaan disapu, dibandingkan bentuk cetaknya (`u95_per_titik`) dengan apakah U95
memang berbeda antar titik dalam satu kelompok cetak.

### Yang ditemukan

Empat alat analitik kehilangan informasi — U95-nya beda tiap titik, tapi
tercetak satu angka:

| alat | U95 tiap titik | akibat |
|---|---|---|
| Turbidimeter | 0,041 / 3,1 / 22 NTU | rentang **537×** diringkas jadi satu angka |
| Conductivity Meter | 0,499 / 8,109 / 1,7 µS/cm | selisih 16× |
| pH Meter | 0,023 / 0,021 / 0,031 pH | titik ketiga (0,03) hilang |
| Refractometer | 0,000527 / 0,00053 nD | beda di bawah presisi cetak |

Yang **tidak** perlu diubah, dan alasannya diukur bukan diasumsikan:

- **Autoclave, DO Meter, Chlorine Meter, Spectrophotometer** — analitik, tapi
  U95-nya memang sama di dalam tiap kelompok cetaknya. Spectrophotometer
  sempat kelihatan bermasalah (3 nilai beda dari 24 titik) sampai
  pengelompokannya diperiksa: ketiganya jatuh di tiga blok `remark` yang
  berbeda, dan masing-masing sudah mencetak U95-nya sendiri.
- **Gas Detector & Viscometer** — analitik, sudah per titik sejak awal.
- **Massa, Waktu-Frekuensi, Enclosure** — sudah per titik.

### Jawaban untuk "apa ada di yang lain juga"

**Ada satu, dan bukan soal U95.** Thermohygrometer (kelompok
`suhu-dan-kelembapan`) mencetak lima baris **kelembaban** di bawah kepala kolom
**°C**, dan U95 RH-nya (4,8) tidak muncul sama sekali. Itu lebih berat daripada
U95 yang diringkas, dan **sengaja tidak ikut diperbaiki di sini** — bentuk
dokumen terkendali itu keputusan pemilik lab. Rinciannya beserta tiga pertanyaan
bernomor: `docs/pertanyaan-lab-thermohygro-satuan.md`.

### Status

**BERES di server** (3 Sep 2026) — `u95PerTitik()` dinyalakan di keempat profil,
dijaga `U95PerTitikInstrumenAnalitikTest` yang mengadu tiap nilai U95 per titik
ke HTML sertifikat yang dirender (bukan sekadar memeriksa flag-nya menyala).
Faktor cakupan sengaja tidak ikut dikunci: `k` keempat alat ini lahir per titik,
jadi judul `k=2` bakal jadi pernyataan yang salah — yang tercetak `U95% (±)`,
preseden Gas Detector.

Sertifikat yang sudah terbit membekukan bentuk cetaknya di
`snapshot['u95_per_titik']`, jadi tidak berubah sendiri. Keputusan pemilik
proyek 3 Sep 2026: **dibangun ulang semuanya** lewat `sertifikat:bangun-ulang
--render-ulang-pdf`. Peringatan yang menyertainya ada di §Jebakan.

---

## 24. Master dirapikan ke `alat-alat-Pt-Sidik/`, dan `.gitignore` yang jadi tumpul — 8 Sep 2026

Pemilik proyek merapikan seluruh master lab dari jalur **datar**
(`Project-PT-Sidik/<Alat>_CSV/`) ke pohon berkelompok
(`Project-PT-Sidik/alat-alat-Pt-Sidik/<kelompok>/<Alat>/`). Rapi — tapi
memindahkannya **mematikan sepuluh aturan `.gitignore` sekaligus**, dan itu
terjadi **tanpa satu pun error**.

### Kenapa ini kelas jebakan, bukan sekadar rapi-rapi

Sepuluh aturan itu ditulis dengan alasan yang sama, tercatat di komentarnya
masing-masing: **CSV master membawa nama & alamat pelanggan asli**, sementara
angkanya sudah masuk `*CapabilitySeeder`/`*Seeder`. Begitu jalurnya bergeser,
polanya tidak cocok lagi — dan yang tersisa cuma `git status` yang menawarkan
97 berkas baru sebagai "untracked", persis seperti berkas biasa.

Repo ini **PUBLIK** (`github.com/ZainulArkaanAlinsi/sidik-calibration-api`).

Yang tertahan gara-gara aturan barunya, dihitung bukan dikira-kira: **12
direktori alat, 12 nama + alamat pelanggan** — pabrik consumer goods, perusahaan niaga komoditas,
pabrik es krim, perkebunan tebu, PDAM kota, kampus, pabrik logam, sampai lembaga biologi
militer.

### Yang sudah dikerjakan

- `.gitignore` ditulis ulang untuk tata letak baru, pakai pola `/*` + `!` supaya
  Height Gauge (satu-satunya yang sudah terlanjur ke-commit) tetap bisa
  di-*include* balik. Diuji satu per satu dengan `git check-ignore`.
- CSV Height Gauge **diredaksi**: `Nama Custromer`/`Alamat Customer`/`Owner`/
  `Address` jadi `[NAMA PELANGGAN DIREDAKSI]`/`[ALAMAT PELANGGAN DIREDAKSI]`.
  Tepat 6 baris, **nol angka bergeser**, dan `gen-tabel-standar-height-gauge.py`
  tetap keluarkan JSON identik byte-per-byte.
- Ekspor ulang Autoclave & DO Meter diadu sel-demi-sel ke versi lama: **nol
  angka kalibrasi berubah.** Delapan sel yang bergeser semuanya hitung-mundur
  `Due Date − NOW()` (ekspor lama 13 Jan 2026, baru 21 Agt 2026, selisih persis
  220 hari), dan status `VALID → WARNING/EXPIRED` mengikuti sebab yang sama.
- Lima sel `#REF!`/`#DIV/0!` yang dulu disembunyikan eksportir lama kini
  kelihatan. Ketiganya terbukti **di luar rantai hitung** — pada baris
  "Ketidakpastian Baku Daya Baca", `ui = 0,005/1,7320508 = 0,0028867513` tetap
  benar dan `#REF!`-nya duduk di kolom yang pada baris tetangga kosong.

### K27 — paparan yang JAUH lebih luas dari CSV, dan bukan keputusan kami

Redaksi di atas cuma menutup CSV. Sapuan ke seluruh repo menemukan nama &
alamat pelanggan yang sama di **~30 berkas terlacak lain**, sudah lama ter-push:

| Tempat | Contoh |
|---|---|
| Seeder sesi contoh | `AutoclaveSeeder`, `SpectrophotometerSeeder`, `GasDetectorSeeder`, `TitsSeeder`, `Suhu3AlatSeeder`, `EnclosureSeeder`, `ViscometerSeeder`, `RefractometerSeeder`, `ConductivitySeeder`, `DoMeterSeeder`, `DemoDataSeeder` |
| Data JSON | `sesi-master-height-gauge.json`, `sesi-master-micrometer.json`, `sesi-master-waktu-frekuensi.json` |
| Docblock profil | `DoMeterProfile`, `GasDetectorProfile`, `ThermohygroProfile`, `ThermometerGlassProfile`, `TitsProfile`, `ViscometerProfile` |
| Direktori perusahaan | `database/direktori/jababeka.csv` |
| Dokumen | 9 berkas `docs/` termasuk `pertanyaan-lab-*.md` |

**Kenapa tidak kami sapu sendiri:** seeder itu **membutuhkan** nama pelanggan
untuk membuat baris `customers` sesi demo. Menggantinya dengan nama sintetis
mengubah data demo yang dipakai belasan test, dan itu perubahan yang harus
disengaja — bukan efek samping dari commit rapi-rapi. Kemungkinan juga ini
memang diterima: nama PT pelanggan lab kalibrasi bukan rahasia dagang.

> **K27:** repo ini dibiarkan publik dengan nama & alamat pelanggan di dalamnya,
> atau (a) repo dijadikan privat, atau (b) nama pelanggan diganti sintetis di
> seeder & dokumen? Kalau (b), riwayat git tetap memuat yang lama — membersihkan
> riwayat berarti **force-push**, dan itu tidak diambil tanpa perintah eksplisit.

#### DIJAWAB 10 Sep 2026 — (a) DAN (b), dua-duanya

Pemilik proyek memilih **keduanya**, dijalankan berurutan hari itu juga.

**(a) Repo dijadikan PRIVAT.** Dilakukan pemilik proyek sendiri lewat setelan
GitHub. Ini yang membalikkan risiko terbesar dalam hitungan detik, dan sengaja
didahulukan supaya (b) tidak dikerjakan sambil dikejar waktu.

> ⚠️ **KOREKSI 10 Sep 2026 — di sini dulu tertulis "efeknya ke deploy NOL".**
> **Itu salah, dan sempat dicabut lalu ditegakkan lagi sebelum akhirnya
> terbukti.** Repo privat **mematahkan deploy Render**: Deploy Hook memulangkan
> **HTTP 400**. Alasan yang dulu ditulis benar sejauh yang disebutnya —
> `render.yaml` memang `autoDeploy: false`, dan Deploy Hook memang URL rahasia
> yang tidak peduli visibility — tapi tidak lengkap: Render tetap harus
> **menarik kode**, dan izin GitHub App-nya tidak mencakup repo privat ini.
>
> Buktinya garis waktu, dua konfirmasi ke masing-masing arah:
>
> | Waktu (WIB) | Commit | Repo | Hook |
> |---|---|---|---|
> | 9 Sep 18:33–20:55 | `c89d31e`, `ed2ea0f`, `8f266ed` | publik | ✅ |
> | 10 Sep 06:58 | `c0645f6` | publik | ✅ |
> | 10 Sep 09:46 | `1cbfa548` | **privat** | ❌ 400 |
> | 10 Sep 11:22 | `c02004e` | **privat** | ❌ 400 |
> | 10 Sep 13:08 | `41de058` | publik | ✅ |
>
> Sepanjang privat, `phpunit` SELALU hijau — yang gagal cuma langkah
> "Ketuk Deploy Hook". Jadi gejalanya CI merah padahal kodenya sehat, dan
> server tetap melayani versi lama tanpa satu pun tanda di aplikasi.
>
> **Jebakan pengukurannya, supaya tidak terulang:** koreksi ini sempat DICABUT
> karena run `a0bfa531` terlihat "success" sesudah repo privat. Ternyata run
> itu jalan di branch `chore/deploy-gratis-render` dan job `deploy ke Render`-nya
> **tidak ikut jalan sama sekali** — "success" di situ artinya test lulus, bukan
> deploy berhasil. Jangan membaca kesimpulan status run tanpa memeriksa job
> mana yang benar-benar jalan.
>
> **Keputusan pemilik proyek (10 Sep 2026):** tetap privat, dan Render diberi
> akses ke repo privatnya — GitHub → Settings → Applications → Render →
> Configure → masukkan `sidik-calibration-api` ke daftar repo yang diizinkan.

Yang berubah selain itu cuma kuota GitHub Actions: repo privat kena jatah
2.000 menit/bulan, dan satu run suite ini 12–17 menit — jadi ±130 push/bulan.

**(b) Nama pelanggan diganti sintetis.** Empat gelombang sapuan, karena tiap
gelombang menemukan yang tidak terlihat dari gelombang sebelumnya:

| Gel. | Temuan |
|---|---|
| 1 | delapan identitas yang terdaftar di komentar `.gitignore` + 24 alamat jalan — 65 berkas |
| 2 | **enam nama yang TIDAK ada di daftar itu** (GE Nusantara Turbine, Kaldu Sari Nabati, JABIL Circuit, MATRA Unikatama, Lamurindo, Trimandiri Plasindo) + varian `PT.` bertitik yang lolos peta pertama |
| 3 | `Puskesad` yang dieja panjang jadi "Pusat Kesehatan Angkatan Darat …" |
| 4 | `LDC` & `IPB` telanjang — termasuk penanda sesi `DEMO-SPECTRO-LDC` yang dipakai seeder + empat test, diganti serentak jadi `DEMO-SPECTRO-NIAGA` |

Total 81 berkas, commit `c0645f6`. Daftar di `.gitignore` sendiri ternyata
**tidak lengkap**, dan berkas itu terlacak — mendaftar nama pelanggan di
komentar aturan yang tugasnya menyembunyikan nama pelanggan membatalkan
aturannya sendiri. Sekarang digenerikkan.

**Celah yang bikin semua ini bisa batal dalam sekali jalan, ikut ditutup:**
`gen-sesi-micrometer.py` dan `gen-sesi-waktu-frekuensi.py` menyalin sel
identitas pelanggan langsung ke `database/data/*.json` yang ter-commit.
Keduanya sekarang meredaksi. Fixture-nya sengaja tetap memakai nama sintetis
yang berbeda per sesi — data demo yang bisa dibedakan lebih berguna daripada
satu placeholder seragam — dan perbedaan yang disengaja itu ditulis di skripnya
supaya tidak "dibetulkan" dengan regenerate lalu commit.

**Yang sengaja TIDAK diubah, dan alasannya:**

- empat nama di field `"lab"` (Eastern, Envirotama, GIN, Heksa) itu
  **laboratorium lain** dalam rantai ketertelusuran, bukan pelanggan —
  mengubahnya merusak logika batas antar-lab;
- `database/direktori/*.csv` itu **direktori bisnis publik**; isinya memang
  ribuan nama PT, dan itu justru gunanya fitur itu;
- nomor seri alat pelanggan (tag alat, bukan nama).

**Riwayat git juga ditulis ulang** — lihat §27.

#### Aturan `.gitignore` yang lahir dari sapuan ini

`Project-PT-Sidik/worksheet_alat_calibration/` (41 PDF lembar kerja) ternyata
**tidak terlacak dan tidak punya aturan** di `.gitignore`. Isinya formulir
kosong, jadi kemungkinan nol data pelanggan — dan itu justru alasan aturannya
ditulis, bukan alasan melewatkannya. Pola yang sama sudah menggigit dua kali:
aturan lama menyebut jalur DATAR, master dipindah ke `alat-alat-Pt-Sidik/`, dan
yang selama ini ditahan ikut kebawa **tanpa satu pun error**. Direktori
tak-terlacak yang tidak punya aturan itu bom waktu yang menunggu satu
`git add -A`.

---

## 25. Alat baru **Flowmeter Ultrasonic** (Aliran) — 8 Sep 2026

Dua workbook master turun (`1.3 Master Olah Data Flowmeter_Ultrasonic Totalizer
(1000-1900L) 2026.xlsm` dan `Master olda Ultrasonic Flowrate (100-300 lpm)
2026.xlsm`, ber-password), plus kertas lembar kerjanya
`SIDIK-FM-CAL-0538_Rev.0`. Alat **ke-27 dan ke-28**, kelompok **Aliran** —
kelompok yang sebelumnya belum punya satu pun profil, dan sekarang LENGKAP
(lampiran akreditasi cuma memuat no. 30 & 31).

Dua profil, satu mesin hitung, satu tabel standar, satu kertas. Presedennya
`ProfilPutaran` (Centrifuge + Tachometer).

### Yang membuat alat ini beda dari 26 lainnya

**Satu titik punya DUA deret berdampingan** — pembacaan UUT dan pembacaan
totalizer standar UFM — dan pada varian Flowrate deret UUT-nya **bersarang**
(tiga ulangan × tiga durasi 20″/40″/60″). Kalau keduanya tertukar atau saling
tertimpa, yang terbit bukan error melainkan **deviasi nol di setiap titik**:
sertifikat yang mencetak koreksi 0,000 dan terlihat seperti alat yang sangat
akurat.

**Budget-nya PER TITIK**, bukan per sesi — tiap titik punya blok penuh sendiri,
jadi `k` dan `U95` juga per titik.

**Dua workbook, dua GENERASI budget.** Totalizer 8 komponen, Flowrate 9.
Bukan karena besarannya beda: `FORM VALIDASI` Flowrate punya baris kedua
(20 Mei 2026) yang menambahkan komponen "Pengulangan Pembacaan UUT", dan
Totalizer belum ikut revisi itu. Ditiru masing-masing.

### Bukti sebelum kode

Reimplementasi Python independen membaca sel `INPUT DATA` MENTAH dan menghitung
ulang dari nol, lalu diadu ke `PERHITUNGAN FC` / `PERHITUNGAN U95%` **sel demi
sel**: tiap kolom turunan, tiap `u`/`ci`/`vi`, `uc`, `veff`, `k`, `U`. **Semua
cocok pada 5·10⁻⁶** di keempat blok titik kedua workbook, sebelum satu baris PHP
ditulis. Dijaga `FlowmeterMasterTest` (9 test / 213 asersi).

`k` cocok HANYA kalau `veff` dipotong ke bawah sebelum `TINV` — perilaku yang
sudah dimiliki `GumCalculator::agregasiBudget()`.

### Tiga kerusakan master yang dihitung benar

Ketiganya membesarkan angkanya atau menolak menerbitkan — tidak pernah diam-diam
mengecilkan:

1. **Lantai CMC hilang, dan sudah menerbitkan angka di bawah akreditasi.** Sel
   U95 sertifikat berbunyi `=MAX(J60:K61)` dengan `K61` **kosong**. Sertifikat
   Flowrate titik 2 terbit **1,0466 %OR** pada pita terakreditasi **1,2 %** —
   0,153 poin persen di bawah yang diakui KAN, tanpa satu pun sel yang
   memprotes. Lantainya dipasang: **3,2512388 → 3,7277107 Lpm**. Arahnya
   ditegakkan `FlowmeterLantaiCmcTest` (kita wajib lebih BESAR, bukan sekadar
   beda).
2. **Rentang densitas Totalizer melenceng satu kolom.** `PERHITUNGAN FC!H64`
   (titik 2) membaca `H44:K46`, dan kolom `K` itu titik 3. Deviasi titik 2
   bergeser **−18,907204 → −18,890667 L**. Workbook Flowrate tidak punya cacat
   ini.
3. **`Ut-water` menunjuk sel KOSONG** — `I24 = Q52−Q54` (Totalizer) dan
   `P53−P55` (Flowrate), keduanya satu kolom di luar blok suhu. Suku itu selalu
   nol padahal labelnya `(Tmax−Tmin)Water`. `U_temperature` **0,2780288 →
   0,2795234 °C**.

### Dua cacat SUNYI yang ketemu waktu test ditulis

- **Tanda kolom `Correction` terbalik** — profil menyimpan `koreksi = −deviasi`,
  padahal sertifikat master menamainya `Correction` dan mengisinya `=H26-E26`
  (Standard − UUT). Besarnya tetap benar, jadi tidak ada satu pun angka yang
  terlihat ganjil; yang terbit menyuruh pelanggan menggeser alatnya ke arah yang
  salah.
- **Resolusi satuan massa dikonversi tanpa densitas** — dikonversi di tingkat
  sesi dengan densitas `null`, resolusinya selalu pulang `null` dan SELURUH sesi
  ber-`kg/min` ditolak dengan alasan "resolusi belum diisi", padahal resolusinya
  ada dan densitasnya juga.

### Yang ditiru walau janggal

π = 3,14; pembagi `1,73` alih-alih `√3`; `vi` suhu 2 (Totalizer) vs 50
(Flowrate); pembagi cross-sectional 2 vs 1,73; pencocokan tabel standar
TERDEKAT bukan interpolasi (ditambah **peringatan sesi** waktu jaraknya > 10 % —
di sesi contoh Flowrate 23,8 %). Semuanya diangkat bernomor.

### Sertifikat

Blok **PIPE SPECIFICATION & SENSOR MOUNTING** baru: material pipa, jenis fluida,
path configuration (Z/V/W), diameter luar/dalam, ketebalan, liner. Sertifikat
Flowrate master punya **labelnya** (`B19`..`B22`, hasil revisi 20 Mei) tapi sel
isinya **kosong tanpa rumus**; sertifikat Totalizer tidak punya labelnya sama
sekali. `k` dicetak per titik (master mencetak `k` Titik 1 untuk semua titik —
di sesi contoh 2,1009 vs 1,9908, beda 5,5 %).

### Nol kolom baru

Alat kelima berturut-turut yang mendarat tanpa satu pun kolom baru di
`raw_measurements` — sumbu `peran_sensor`/`pembacaan_ke`/`sensor_ke` cukup, dan
blok tingkat-sesi masuk `spesifikasi_alat.flowmeter`.

### Pertanyaan lab

Tujuh belas butir di `docs/pertanyaan-lab-flowmeter.md`. Yang paling mendesak
**§1** (kedua master kolom VALIDATION-nya KOSONG — belum ditandatangani
Technical Manager), **§2** (sertifikat yang sudah terbit di bawah pita CMC:
ditarik atau direvisi?), dan **§16** (lampiran akreditasi menyebut *static
weighing method* `Rev.4`, master memakai `Rev.6`, kertasnya *"Perbandingan
Langsung dengan UFM"* — apakah metode yang dikerjakan tercakup akreditasi yang
sekarang?).

### Pelingkup arsip untuk §2

`php artisan flowmeter:audit-cmc` (`--org=`, `--csv=`) menyapu SELURUH arsip dan
menyodorkan daftar titik yang perlu ditinjau, per TITIK bukan per sesi. Tiga
temuan dibedakan: `di_bawah_cmc` (mengklaim ketidakpastian lebih baik dari yang
diakui KAN), `di_luar_pita` (membawa nomor lingkup untuk pengukuran yang tidak
diakreditasi), dan `jarak_tabel_NNpct`. Read-only, dan sengaja exit 0 walau ada
temuan — perintah kesiapan yang selalu merah berhenti dibaca. Presedennya
`micrometer:audit-cmc`. Dijaga `AuditFlowmeterCmcTest` (6 test).

### Sisi mobile — BERES (9 Sep 2026)

`dart analyze` bersih, `flutter test` **1627/1627**. Yang mendarat: berkas contoh
`contoh_lembar_kerja_aliran.dart` (1.762 baris, **digenerate**
`docs/skrip/gen-contoh-lembar-kerja.php` dari bentuk yang beneran
dikirim server — bukan disusun tangan), dua cabang di `lembar_kerja_service.dart`
(dipilih dari **kode profil**, bukan nama alat), lima kode penentu angka di
`lembar_kerja_state.dart`, dan empat baris kemampuan di `category_service.dart`
(dua alat × dua pita CMC, satuannya `% of reading`).

Ikon `Icons.waves_outlined` ternyata sudah benar tanpa disentuh — cabang
`contains('flow')` sudah ada dan tidak ada cabang `contains('meter')` telanjang
yang mendahuluinya.

Satu test ikut diperbarui: `vonis_toleransi_mock_test.dart` menuntut tiap baris
kemampuan mock punya padanan vonis di server. Kontraknya di
`docs/perintah-frontend-flowmeter.md`.

---

## §26 — Audit 33 master lab lawan yang sudah dibangun (9 Sep 2026)

Permintaan pemilik proyek: *"kamu buka itu isi alat alat yang udha kita pernah
buat okk tapi kammu cek semua nya okk kalo ada salah langusng benarkan okk"*.

Yang disapu enam dimensi, bukan satu:

| Dimensi | Cara | Hasil |
|---|---|---|
| Nomor metode | 33 master → `INPUT DATA` atau footer `SERTIFIKAT`, diadu ke `kodeMetode()` tiap profil | 30 cocok, 3 beda **dan ketiganya sudah tercatat sebelum audit ini** |
| Pita CMC | tabel `Jenis UTM`/`CMC` di sheet `DATABASE` → `database/data/kemampuan-kalibrasi.json` | 4 master memuatnya eksplisit; keempatnya cocok persis |
| Nomor formulir | sapuan `SIDIK-FM-` seluruh CSV | tidak ada di workbook olah data — adanya di PDF lembar kerja. Yang muncul (`SIDIK-FM-CAL-2403_Rev. 0`) formulir SERTIFIKAT bersama, sudah dikenali `SemuaProfilLembarKerjaTest` |
| Cakupan | tiap direktori master → profil | 33/33 punya profil; nol master yatim |
| Varian | alat bermaster jamak (Micrometer 4, Timbangan 3, TITS 2, TIDS 2, Enclosure 2, Flowmeter 2, Viscometer 2) | semua varian terwakili di `*MasterTest` masing-masing |
| **Bukti angka** | tiap snapshot punya test yang mengadunya? | **33/33 ada** — dua celah tipis di pH (lihat bawah) |

### Klaim pertama audit ini SALAH — dan sebabnya lebih berguna dari temuannya

Sempat disimpulkan **"pH satu-satunya alat yang masternya tidak pernah diadu"**.
Itu tidak benar. `tests/Unit/UncertaintyBudgetTest.php` sudah mengadu KEDUA
lembar pH ke workbook aslinya sejak lama — tiga test untuk lembar lama (`Uc`,
`v_eff`, `U95` sampai 6 desimal) dan satu data provider untuk lembar
IMTE-WQ-129 (`Uc` 1e-9, `v_eff` 1e-6) — dan docblock-nya bahkan sudah
membedakan kedua termometernya lebih dulu: *"Dua lembar beda, dua alat beda —
dicampur, hasilnya nggak reproducible ke mana pun."*

Sapuan yang "membuktikan" tidak ada testnya cacat sendiri:

    grep -rln "assertEqualsWithDelta" tests/ | xargs grep -lі "pH"

`-lі` diketik dengan huruf **і Kiril**, bukan `i` Latin. `grep` menolak opsinya,
`xargs` memulangkan nol berkas, dan keluaran kosongnya kebaca persis seperti
"tidak ada yang menguji pH".

**Pelajarannya bukan soal pH.** Sapuan yang memulangkan "tidak ada" wajib
dibuktikan dulu bisa memulangkan "ada" — hasil nol dari perintah yang rusak
tidak bisa dibedakan dari temuan, dan yang kedua terasa jauh lebih memuaskan.
Ini masuk daftar jebakan di bawah.

### Yang TERSISA sebagai celah nyata: dua, dan dua-duanya tipis

1. **`k` eksak & `U` untuk lembar IMTE-WQ-129.** Di `UncertaintyBudgetTest`, `k`
   cuma dipatok `assertEqualsWithDelta(1.97, …, 5e-3)` dan `U` tidak diperiksa
   sama sekali. Padahal `U` yang menentukan angka tercetak, dan `k` datang dari
   `floor(v_eff)` — pemotongan yang meleset satu derajat kebebasan lolos dari
   toleransi 5e-3 tanpa jejak.
2. **Lantai CMC, dua arah.** Lembar lama menembus CMC di pH 4 & pH 7 (sertifikat
   mencetak 0,02343221 & 0,02110895), lembar baru ketutup di ketiganya. Dua
   sifat berlawanan yang tidak dipegang test mana pun.

Keduanya ditutup `PhMeterMasterTest` (4 test / 36 asersi).

### Yang komentarnya perlu dilengkapi

`PhMeterCapabilitySeeder` menjelaskan `0.25179356624028343` sebagai **salah
baca** (*"angka termometernya kebaca 0.25, bukan 0.36"*). Kesimpulannya benar —
baris kemampuan itu memang harus yang Yokogawa — tapi sebabnya tidak lengkap:
angka itu nilai SAH lembar IMTE-WQ-129, yang menuliskannya sendiri di kepala
sheet (`U95% Thermometer : 0.5`, `k : 2`). Jadi yang salah bukan angkanya
melainkan lembarnya. Bedanya penting: "salah ketik" mengundang orang
membetulkannya balik begitu dia menemukan 0,25179 tertulis di salah satu
workbook — dan dia pasti menemukannya. Termometer standarnya memang beda benda:

| | `…pH for trial` | `pH_meter_IMTE-WQ-129` |
|---|---|---|
| Termometer | Yokogawa CA 150 | Constant/SH 10 (S/N 99875850/20) |
| U95 | 0,72 °C | 0,5 °C |
| UTemperature | 0,36124783736376886 | 0,25179356624028343 |
| Resolusi UUT | 0,01 pH | 0,001 pH |

Sisa konstantanya (`ci_suhu`, `u_perbedaan_suhu`, `ci_perbedaan_suhu` — ketiga
titik) **identik** di kedua workbook. Itu yang membuktikan cuma `UTemperature`
yang ikut perangkat, bukan seluruh bloknya.

### Kenapa angkanya TIDAK ditukar

Menukar 0,36 → 0,25 cuma memindahkan ketidakcocokannya ke master satunya. Yang
benar `UTemperature` ikut standar suhu sesi, dan jalurnya belum ada — jadi
diangkat jadi **K28** + `docs/pertanyaan-lab-ph-dua-master.md`.

Sapuan lanjutan memastikan ini tidak menyebar: ketujuh `*CapabilitySeeder` yang
punya `u_temperature` (Chlorine, Conductivity, DO Meter, pH, Refractometer,
Turbidimeter, Viscometer) diadu ke kepala sheet `PERHITUNGAN U95%` masternya
masing-masing. **Ketujuhnya cocok** — lima memakai Yokogawa 0,72/0,06 dan
Refractometer memang bertermometer lain (0,07/0,036), yang juga sudah tersimpan
benar. pH satu-satunya yang punya master kedua dengan perangkat berbeda.

Dampaknya hari ini nol, dan itu diukur bukan dikira: untuk alat resolusi 0,001
ketiga titiknya di bawah CMC dengan UTemperature mana pun (0,022757/0,019772/
0,029842 lawan CMC 0,023/0,021/0,031), dan yang dipakai sistem selalu yang
**lebih besar** — arah aman, sistem tidak pernah melaporkan U lebih kecil dari
yang lab hitung sendiri.

Tapi itu kebetulan, dan `PhMeterMasterTest` menahannya sebagai kebetulan: satu
test menuntut master LAMA **tetap menembus** CMC di pH 4 & pH 7 (sertifikatnya
memang mencetak 0,02343221 & 0,02110895, bukan CMC), satu lagi menuntut master
BARU tetap ketutup di ketiga titik. Yang pertama runtuh kalau budgetnya diam-diam
menyusut; yang kedua runtuh kalau pilihan UTemperature mulai menentukan angka
tercetak. Dua-duanya menyebut §1 pertanyaan labnya di pesan gagalnya, supaya
yang menemukan merahnya tidak "membetulkan" batasnya.

### Yang dikonfirmasi BENAR dan sengaja dibiarkan

- **Flowmeter** — profil `0528_Rev.4`, master `0528_Rev.6`. Profil ikut
  **lampiran akreditasi**; Rev.6 belum masuk lingkup. Sertifikat tidak boleh
  mengklaim revisi metode di luar yang diakreditasi. (§16 pertanyaan flowmeter.)
- **Thermocouple** — master men-VLOOKUP `Calibration Method : 2` ke baris TITS
  (`0502_Rev.3`), padahal barisnya sendiri ada di nomor 29 (`0529_Rev.2`).
  `ThermocoupleProfile` sudah memakai yang benar sejak awal; kerusakan
  salin-tempel ini tercatat `pertanyaan-lab-suhu-3alat.md` §1.
- **Timbangan** — master menulis `SIDIK-IK-CAL-0505-Rev.7` (tanda hubung)
  sendirian di antara 30+ IK ber-garis bawah. Sudah tercatat di docblock
  `TimbanganProfile`.
- **Centrifuge & Tachometer berbagi `0511_Rev.6`** — bukan cacat: keduanya
  memang satu Instruksi Kerja dan satu `ProfilPutaran`.

## §27 — Riwayat git ditulis ulang: atribusi Claude dicabut (10 Sep 2026)

Permintaan pemilik proyek: dia tidak mau Claude tampil di GitHub. Dikerjakan
sesudah repo diprivatkan (K27), supaya tidak dikejar waktu.

### Jejaknya empat bentuk, bukan satu

Rencana awal cuma menyasar trailer `Co-Authored-By`. Hitungan sebenarnya atas
686 commit:

| Jejak | Jumlah | Ditangani rencana awal? |
|---|---|---|
| `Co-Authored-By: Claude` | 440 commit | ya |
| `Claude-Session:` | 164 commit | **tidak** |
| Commit ber-**author** `Claude <noreply@anthropic.com>` | **143 commit** | **tidak — dan ini yang paling menentukan** |
| Deskripsi PR memuat Claude | 137 dari 181 PR | **tidak — ini tidak ada di git sama sekali** |
| Branch bernama `claude/*` | 37 branch | **tidak — dan ini yang paling kelihatan** |

Daftar Contributors GitHub dibaca terutama dari **author**, bukan trailer.
`--message-callback` saja tidak akan menyentuhnya — "claude" tetap muncul walau
seluruh trailer bersih. Itu perlu `--mailmap`.

### Cara

1. **Cadangan mirror dulu** — `backup-sidik-api-20260910-0927.git`, 686 commit,
   semua ref. Wajib, dan jadi satu-satunya jalan pulang.
2. **Klon terpisah** `rw-sidik`, ditarik dari GitHub supaya ke-91 branch ikut.
   Rewrite TIDAK pernah menyentuh repo kerja — waktu itu sesi Claude lain masih
   menggarap fitur Flowmeter Gravimetri yang belum ter-commit di situ.
3. `git filter-repo` dua pass: `--mailmap` (author Claude → pemilik proyek) +
   `--message-callback` (lima pola trailer), lalu pass kedua menetralkan rujukan
   nama branch `claude/…` → `work/…`.
4. **Branch diganti nama, BUKAN dihapus.** 32 dari 37 bukan leluhur `main`, dan
   menebak mana yang aman dibuang itu taruhan yang tidak perlu. Ke-37 disalin
   utuh ke `work/<nama-sama>` dan diverifikasi ada di origin, baru nama lamanya
   dilepas. Nol commit hilang.
5. Deskripsi 181 PR disapu lewat `gh pr edit` — 137 disunting, sisa nol.
6. Tag `arsip/ph-lengkap-dan-order` ikut dipindah. **Tanpa ini rewrite-nya
   bocor:** tag yang masih menunjuk commit lama membuat seluruh riwayat lama
   tetap terjangkau di GitHub.
7. Tiga branch **lokal** basi (`claude/ocr-jalur-windows`,
   `claude/recursing-borg-fa0373`, `claude/upbeat-almeida-602947`) dibuang —
   sekali di-push, branch `claude/*` lahir lagi berikut atribusinya.

### Gerbang yang menahan sebelum push

Tree `main` sebelum dan sesudah rewrite diadu: **`b349453c…` identik
byte-per-byte**. Nol byte kode berubah; yang berubah cuma metadata. Push tidak
dilakukan sebelum angka itu cocok.

### Hasil

Contributors GitHub tinggal dua orang. Trailer 0, author Claude 0, branch
`claude/*` 0, deskripsi PR 0. Commit 686 dan branch 91 utuh. `main` = `1cbfa548`.

### Yang TIDAK dihapus, dan kenapa itu benar

Tiga belas pesan commit masih menyebut "Claude". Itu **arsitektur, bukan
atribusi**: `claude-opus-4-8` adalah model yang dipanggil fitur Vision aplikasi
ini (`VISION_DRIVER=anthropic`), dan `AGENTS.md` adalah nama berkas instruksi
proyek. Menghapusnya membuat pesan commit berbohong tentang kodenya sendiri.
Kalau `AGENTS.md` pun harus hilang, repo ini sudah punya `AGENTS.md` yang cuma
menunjuk ke sana — tinggal dibalik.

### Pencegahan

`~/.claude/settings.json` → `"includeCoAuthoredBy": false`. Setelan resmi Claude
Code; commit berikutnya tidak menambah trailer lagi. Setelan `{"attribution":
{…}}` yang sempat diusulkan **bukan** setelan yang dikenal Claude Code dan tidak
akan berefek.

### Temuan sampingan yang lebih mendesak dari pekerjaannya sendiri

`~/.claude/settings.json` menyimpan **Personal Access Token GitHub polos** di
blok `env`. Dilaporkan ke pemilik proyek, dicabut olehnya di GitHub, lalu
dihapus dari berkas berikut kedua cadangannya.

---

---

## §28 — Varian metode kedua **Flowmeter Gravimetri (ISO 4185)** — 10 Sep 2026

Dua workbook master turun (ber-password):

- `1.2 Master olda Flowmeter Totalizer dini (2026) 140-2500L.xlsm`
- `2.1 Master olda Flowmeter Flowrate 100-980lpm 2026.xlsm`

Berkas ketiga yang ikut terkirim, `Master olda Height Gauge 600 mm 2026 (1).xlsm`,
identik dengan yang sudah dikerjakan 7 Sep (alat ke-26). Tidak dibongkar ulang.

### Ini BUKAN alat ke-29

Keduanya mengukur **alat yang sama, besaran yang sama, pita CMC yang sama**
dengan alat ke-27 & ke-28 — dengan metode yang sama sekali lain: penimbangan
statis menurut ISO 4185, timbangan digital sebagai standar.
`PERHITUNGAN FC!B74` menulis judulnya sendiri: `ISO 4185`, `Laju alir masa`.

Memecahnya jadi profil ketiga & keempat tidak bisa: `CalibrationProfileRegistry`
melempar `LogicException` begitu dua profil mengaku ejaan nama alat yang sama,
dan lampiran akreditasi LK-285-IDN cuma punya SATU baris per mode. Jadi yang
dibangun **sumbu `varian_metode`** di dua profil yang sudah ada. Presedennya
tiga: `TimbanganProfile` (kg/gram/substitusi), TITS (Measure/Source), TIDS
(Recorder/Constant-Yokogawa).

### Bukti sebelum kode

Reimplementasi mandiri di Python diadu ke KEDUA workbook sel demi sel sebelum
satu baris PHP ditulis: **107 pengaduan, nol beda** pada toleransi 5·10⁻⁶ —
tiap kolom turunan, tiap `u`/`ci`/`vi` kesembilan dan kesebelas komponen, lalu
`uc`, `veff`, `k`, dan `U`. `k` cocok hanya kalau `veff` dipotong ke bawah
sebelum `TINV`, perilaku yang sudah dimiliki `GumCalculator::agregasiBudget()`.

**Temuan terbesar pembongkaran, dan yang prompt-nya sendiri salah:** densitas air
di master gravimetri **bukan rumus**. Prompt menyebut "tabel densitas
`STANDAR KALIBRATOR!P73:P80`, salin ke JSON". Yang ada di situ kolom TURUNAN per
titik sesi. Tabel sesungguhnya `N61:P65`: piknometer **50,3139 ml** ditimbang di
empat suhu (20 / 27 / 30 / 50,5 °C), densitasnya `gram / volume`, dan suhu di
antaranya **diinterpolasi linier**. Memakai Tanaka/Kell (yang dipakai varian UFM)
menggeser tiap sertifikat gravimetri di digit belakang — kecil, dan justru karena
itu tidak akan pernah terlihat.

### Delapan penyimpangan master yang DIBETULKAN

Tiap-tiapnya terukur, dan arahnya ditegakkan test:

1. **Koreksi timer dihitung lalu dibuang.** `D48` (waktu terkoreksi) tidak dibaca
   satu sel pun; laju alir massa memakai `D45` mentah. Deviasi titik 1 bergerak
   −0,0041534 → −0,0101469 Lpm, dan **deviasi titik 2 BERGANTI TANDA**:
   +0,0299162 → −0,0011816. Alat berubah dari membaca rendah jadi membaca tinggi.
2. **Dua rumus koreksi apung dalam satu sheet** — `M·(1+E)` di titik 1,
   `M/(1−ρa/ρw)` di titik 2 & 3. Dipilih bentuk KALI (mayoritas + konsisten
   dengan Totalizer). Mt titik 2: 9,9410046 → 9,9394996 kg.
3. **Suhu terkoreksi Flowrate memakai INDEX, bukan rata-rata** (`D66 = D64+D65`).
   ρ_air 0,9964893 → 0,9959691 kg/L. Workbook Totalizer melakukannya benar.
4. **`Ut-water` menunjuk sel kosong** — sama persis dengan master UFM.
   `U_temperature` 0,2780288 → 0,3437053 °C (Totalizer), 0,2795234 °C (Flowrate).
5. **Komponen Koreksi Bouyancy lenyap di titik 2, 3, 4 Totalizer** — rumusnya
   tidak ikut tersalin dari titik 1.
6. **Tabel koreksi timbangan bersatuan gram dicocokkan ke penimbangan kilogram.**
   Tanpa konversi, penimbangan 27 kg memungut koreksi titik 27 g sebesar 0,1 kg —
   588 kali U95 timbangannya sendiri.
7. **Lantai CMC tidak pernah dipasang.** Keempat sel `CMC` kosong, tabelnya
   lengkap di `DATABASE!R5:S6`. Titik 1 naik 1,06076 → **1,68128 L**.
8. **Sel U95 sertifikat berhenti dikonversi di titik 3 & 4** — mencetak kilogram
   di kolom berjudul liter.

### Sepuluh yang DITIRU walau janggal

Pembagi `1,73` alih-alih `√3`; komponen suhu `normal`/2/vi 60 di Flowrate lawan
`rectangular`/1,73/vi 50 di Totalizer; drift dibagi 2 lagi sesudah sheet-nya
sendiri sudah memotong `0,5·ΔC` (dan `√3` yang dijanjikan judul kolomnya tidak
pernah dipakai); `ci` suhu ber-`0,00021` tanpa sumber; densitas anak timbang
8 kg/L dan densitas udara 1,2 g/L nominal; `vi` blok titik 2–4 yang tidak
sebangun dengan titik 1 (blok titik 1 yang diikuti); timbangan ke-3 yang **beda
alat** di kedua workbook (keduanya disimpan, dipilih per mode).

Seluruhnya diangkat jadi **23 butir** di
`docs/pertanyaan-lab-flowmeter-gravimetri.md`, berikut formulir keputusan.

### Yang TIDAK ditiru: titik di luar lingkup

Titik 4 Totalizer (2.498 L, pita berhenti 1.991 L) dan **seluruh sesi Flowrate**
(2 dan 10 Lpm, pita mulai 75 Lpm) **DIBLOKIR**, bukan diterbitkan dengan
peringatan. Peringatan yang bisa dilewati admin adalah persis kelas kerusakan
yang sudah ditulis di §9 dokumen ini.

### Satu bug LAMA ikut ketemu

`FlowmeterProfile::peringatanSesi()` memulangkan deret **string**, sementara
`CalibrationValidator::periksaPeringatanProfil()` memetakannya dengan
`fn (array $p)`. Begitu satu cabang peringatan menyala, seluruh endpoint
`/validasi` pulang **500**. Hidup diam-diam sejak 8 Sep karena kedua sesi contoh
UFM selalu punya geometri pipa DAN path configuration — tanpa keduanya tidak ada
cabang yang menyala. Ketahuan karena sesi gravimetri memang tidak punya geometri
pipa. Dibetulkan, dijaga
`FlowmeterVarianTest::test_peringatan_sesi_berbentuk_kode_dan_pesan`.

### Nol kolom baru

Tiga peran `raw_measurements` baru (`flow_berat_isi`, `flow_berat_kosong`,
`flow_waktu_menit`) memakai sumbu `peran_sensor`/`pembacaan_ke`/`sensor_ke` yang
sudah ada. `varian_metode`, `kode_timbangan`, dan `volume_pipa_l` masuk
`spesifikasi_alat.flowmeter`. Alat keenam berturut-turut tanpa satu pun kolom
baru.

### Susulan — "belum terdaftar di master standar" di layar HP

Dilaporkan pemilik proyek lewat tangkapan layar lembar Flow Meter Cairan
(Totalizer): tiga dari lima baris `STANDARD USED` merah. Disapu ke seluruh
registry: **13 baris di 13 profil**, dan ternyata cuma **tiga alat fisik**.

Yang diseed (2 alat, menutup 4 baris): **Digital Caliper Tesa Cal-IP67** dan
**Ultrasonic Thickness Gauge TM-8812**. Keduanya bukan pelengkap — diameter luar
dan ketebalan pipa yang mereka ukur melahirkan `u_A`, dan `u_A` masuk DUA
komponen budget varian UFM. Datanya sudah ada di `tabel-standar-flowmeter.json`
sejak alat ke-27; yang tidak ada cuma baris `standards`-nya.

Yang TIDAK diseed (1 alat, 9 baris): **Victor 14+ / 992613877**. Label merahnya
JUJUR. `FORM VALIDASI` TITS rev. 11 (24 Mei 2024) berbunyi *"Remove std. Victor /
Add std kalibrator yokogawa"*, tabel koreksinya sudah `#REF!` semua, dan
penggantinya Yokogawa CA 150 (23P1005) yang justru sudah terdaftar. Menyeed
Victor berarti menghidupkan kembali ketertelusuran ke alat yang sudah dicabut
lab. Barisnya tetap tercetak karena kertasnya memang masih memuatnya.

Penjaganya `StandarTercetakTerdaftarTest`, daftarnya dari registry bukan diketik,
dengan `BOLEH_TIDAK_TERDAFTAR` yang punya test kedua untuk memastikan
pengecualiannya benar-benar terpakai — pengecualian mati bikin sapuan terlihat
lebih ketat daripada yang sebenarnya. Dibuktikan menggigit: seed dicabut, sapuan
menyebut keempat barisnya berikut kode profilnya.

Catatan penamaan: serial `992613877` yang sama tercatat sebagai **"Victor 14+"**
di sebelas profil dan **"Constant 40T"** di master Flowmeter — satu alat, dua
nama. Belum diangkat jadi pertanyaan lab karena alatnya sudah pensiun.

### Sisi mobile — BELUM

`docs/perintah-frontend-flowmeter-gravimetri.md` §6 memasang syaratnya: repo
mobile belum punya penjaga sapuan registry (tujuh dari 28 kode profil jatuh ke
lembar pH tanpa satu test pun menyebutnya). Sapuan itu dipasang DULU, baru
cabang varian — kalau tidak, varian gravimetri diam-diam memajang lembar UFM:
bentuk yang sah, kolom yang salah, nol error.

## Yang MASIH menunggu jawaban

| Kode | Pertanyaan | Menahan apa |
|---|---|---|
| ~~**K27**~~ | ~~Repo PUBLIK memuat nama & alamat ~13 pelanggan di ~30 berkas terlacak~~ | **DIJAWAB: (a) DAN (b)** (10 Sep 2026). Repo **dijadikan PRIVAT** oleh pemilik proyek — nol efek ke deploy (`autoDeploy: false`, deploy lewat Deploy Hook), yang berubah cuma kuota Actions repo privat (2.000 menit/bulan; satu run 12–17 menit). Lalu nama pelanggan **diganti sintetis**, empat gelombang, 81 berkas, commit `c0645f6` — dan gelombang kedua menemukan **enam nama yang tidak ada di daftar `.gitignore`**, jadi daftar itu sendiri tidak lengkap. Celah generator (`gen-sesi-micrometer.py`, `gen-sesi-waktu-frekuensi.py` menyalin identitas pelanggan ke JSON ter-commit) ikut ditutup. **Riwayat git juga ditulis ulang** — §27. Sisa yang sengaja dibiarkan: nama laboratorium lain di field `"lab"`, direktori bisnis publik `database/direktori/*.csv`, dan nomor seri alat |
| **K28** | **pH Meter punya DUA master, dan `UTemperature` beda karena TERMOMETERNYA beda** (Yokogawa CA 150 U95 0,72 °C → 0,36125; Constant/SH 10 U95 0,5 °C → 0,25179). Sistem memakai yang Yokogawa untuk semua sesi | Tidak menahan apa pun **hari ini** — buat alat resolusi 0,001 ketiga titiknya di bawah CMC dengan angka mana pun, jadi yang tercetak tetap CMC, dan yang dipakai sistem yang lebih BESAR (arah aman). Tapi master LAMA membuktikan itu kebetulan: di resolusi 0,01 hasil hitungnya menembus CMC di dua titik dan sertifikatnya mencetak angka hitung (0,02343221 & 0,02110895), bukan CMC. Menahan **keputusan**: `UTemperature` ikut standar suhu sesi, atau tetap dipatok per jenis alat. Rinciannya `docs/pertanyaan-lab-ph-dua-master.md` |
| ~~K1~~ | ~~TIDS: 5 UUT jadi 1 sesi, atau 5 sesi terpisah?~~ | **GUGUR** (28 Agt 2026) — nggak pernah ada lima UUT. Dua workbook master menamai kolom yang sama `PRT1`…`PRT5` lalu memakainya `AVERAGE`+`STDEV` per baris: lima ULANGAN, satu alat, satu baris = satu set point |
| ~~K2~~ | ~~Workbook Excel TIDS — kapan dari lab?~~ | **BERES** (28 Agt 2026) — dua workbook turun, budget-nya jalan, blokir U95 dicabut. Lihat §13 |
| K8 | Inlab: ruangan wajib dipilih atau boleh kosong? | Kalau wajib penuh, semua APK lama ditolak 422 |
| K10 | Layar Draf: pintu masuknya di mana; admin boleh lihat draf teknisi lain? | Layar Draf |
| K11 | Perlu tombol hapus draf? | `DELETE /api/calibrations/{id}` belum ada sama sekali |
| **K12** | **Sheet `Variasi axial Dryblok A` isinya data blok B** — kapan hasil ukur Isotech yang asli bisa dikirim? | Komponen `variasi_aksial` & `variasi_antar_lubang` sesi Thermocouple yang memakai blok A |
| **F1** | **Satu foto lembar cetak yang sudah diisi tangan**, dari lembar mana saja | Berhenti menggerbangi jalur lembar bermarker (sudah dicabut dari aplikasi, §12) — tapi **naik lagi jadi satu-satunya hal yang menahan klaim jalur ML Kit** (§12, "Batas klaim ini"). Nggak ada satu pun foto asli maupun citra bertulisan tangan di repo mobile, jadi yang belum pernah diuji justru yang menentukan fiturnya berguna di lapangan atau tidak |
| **K19** | **Empat penyimpangan master TIDS (D1–D4)** — sel tetap `T30`, literal `0,14`, sel `AM9` di tabel koreksi, dan `SUM` yang berhenti di baris 32 | Angka U95 tiap sertifikat TIDS. Ditiru apa adanya + catatan audit + peringatan sesi; **D4 paling mendesak** karena arahnya bikin U95 lebih kecil. Rinciannya `docs/pertanyaan-lab-tids-workbook.md` |
| **K20** | Komponen `Interpolasi` TIDS (`0,19788162882115856`) datang dari workbook luar yang tidak ikut dikirim | Konstanta yang ikut tiap budget TIDS tapi sumbernya belum bisa ditelusuri |
| **K21** | Drift sensor Type K: 0,55 (workbook Recorder) lawan 0,5 (workbook Constant/Yokogawa) | Dua sertifikat berbeda, atau satu angka yang belum diseragamkan |
| **K22** | PRT PT100 + recorder: master memulangkan koreksi KOSONG (kolom ke-100 di tabel 42 kolom) | Aplikasi memblokir kombinasinya. Perlu konfirmasi: memang nggak pernah dipakai, atau tabelnya yang belum dibuat |
| **K13** | `Multimeter Texio/DL` tercetak di `Standar Used` lembar Thermocouple, tapi tidak ada barisnya di master `standards` | Dropdown standar lembar Thermocouple kurang satu pilihan yang ada di kertas |
| **K14** | Nomor seri standar di kertas beda dari yang tersimpan (`TN-02`/`TCK-02` lawan `TCN-06`, `TCN-11`, `TC-01`, `TC-02`) | Teknisi mengadu lembar cetak dengan dropdown dan menemukan nomor yang tidak cocok |
| **K15** | Lembar Termometer Gelas mencantumkan `Sensor Termocouple Type N` & `Type K` di `Standar Used`, sementara pemeriksaan pakai kita belum mengenalinya | Peringatan "standar tidak dipakai" bisa menyala untuk pemakaian yang sah |
| ~~**K16**~~ | ~~Sumber nama + alamat PT Indonesia untuk pencarian pelanggan~~ | **BERES** (31 Agt 2026) — internal dulu, direktori luar sebagai jalan keluar, ketik tangan sebagai dasar. Teknisi juga boleh mendaftarkan PT sendiri (sejalan K3/K4). Rinciannya di §11. **Keputusan pemilik proyek 31 Agt: nol tagihan** — penyedianya pindah ke OpenStreetMap/Nominatim, jadi **tidak ada API key sama sekali** dan keadaan "belum disetel" berhenti ada. Google tetap bisa dipilih lewat satu setelan. Daftar pelanggan juga disalin ke HP, jadi pemilihnya tetap jalan waktu server tak terjangkau. **Sisa: satu uji nyata ke Nominatim dari server** — bentuk jawabannya ditulis dari dokumentasi, jaringan lingkungan pengembangan tidak bisa menembus ke sana |
| ~~**K23**~~ | ~~Direktori luar: tetap `osm`, atau pindah ke `auto` dengan tagihan?~~ | **DIJAWAB: TETAP `osm`** (2 Sep 2026) — menegaskan K16, bukan mengubahnya. **Nol perubahan kode**: `config/services.php:190`, `.env.example:255`, dan `render.yaml:189` ketiganya sudah `osm`, dan yang terakhir memakunya lewat `value:` bukan `sync: false`. Konsekuensi yang ikut disetujui: pabrik yang belum dipetakan sukarelawan memang tidak ketemu, ditutup teknisi lewat `POST /customers/cepat`; jalur Google mati total selama `DIREKTORI_PERUSAHAAN_KEY` kosong. Bahan peninjauan ulang tetap disimpan di P1 `docs/pertanyaan-lab-data-pelanggan.md` |
| **K24** | **Berkas arsip pelanggan lab + ID user penanggung jawab impornya** | `customers:impor` sudah jalan tapi belum ada yang diimpor. Tanpa `--oleh`, 500 baris mendarat di `audit_logs` tanpa penanggung jawab — persis yang ditanya asesor. P2 |
| **K25** | **Siapa yang memutuskan baris `perlu_tinjau`?** | Menentukan apakah Milestone C (aksi gabung di panel admin) perlu dibangun, atau laporan CSV sudah cukup. P3 |
| **K26** | **Perlu status verifikasi alamat + peringatan saat terbit sertifikat?** | Milestone D. Cuma layak kalau alamatnya memang akan diverifikasi — peringatan yang selalu menyala melatih admin menekan "terbitkan saja", dan itu lebih buruk daripada tidak ada peringatan. P4 |
| ~~**K17**~~ | ~~Tujuh lembar bentuk matriks/grid belum punya jalur kamera~~ | **SUDAH DIKERJAKAN** (27 Agt 2026) — grid kelima Enclosure & matriks Autoklaf punya jangkar barisnya sendiri; sisa satu (TIDS) tertahan K18. Lihat §12 sebab 3 |
| ~~**K18**~~ | ~~Lembar TIDS: tujuh baris Setpoint sendiri, atau pengatur titik?~~ | **DIJAWAB: tujuh baris, tiap baris punya kotaknya sendiri** (27 Agt 2026). Sudah dikerjakan berikut dua lubang lain di lembar yang sama — lihat §12 K18 |

### K12 — dryblock A memakai angka dryblock B

Ditemukan 27 Agt 2026 waktu mengadu ulang ketiga workbook suhu sheet demi sheet.

`Variasi axial Dryblok A` dan `Variasi axial Dryblok B` **identik byte-per-byte**, dan kepala
kedua sheet menulis alat yang sama:

| | Isinya |
|---|---|
| Kepala sheet A **dan** B | `Techne TeCal 700xs`, SN `DB-B-2`, kapasitas `0~600 °C` |
| Yang seharusnya di sheet A | `Isotech Fast Cal Low`, rentang −20…150 °C |
| Angka yang terbawa ke budget | `variasi_aksial` 0,2 °C · `variasi_antar_lubang` 0,13 °C — **sama buat A & B** |

Jadi sesi Thermocouple yang memakai blok **A** (Isotech) mendapat dua komponen ketidakpastian
yang diukur di blok **B** (Techne). Ini bukan salah hitung sistem: kita menyalin master apa
adanya, dan `Suhu3AlatMasterTest` menjaga angka kita tetap sama dengan Excel. Yang salah datanya
di master lab.

**Yang TIDAK dilakukan:** mengarang angka Isotech supaya kelihatan berbeda. Itu mengarang
komponen ketidakpastian, dan begitu dikarang U95-nya berhenti bisa diadu ke Excel lab —
kehilangan satu-satunya oracle yang kita punya.

Begitu lab mengirim hasil ukur Isotech yang sebenarnya, cukup ekstraksi ulang
`database/data/tabel-master-suhu-3alat.json`; nol baris kode berubah.

### F1 — kenapa satu foto dulu menahan sebelas lembar, dan kenapa sekarang tidak lagi

Koordinat di berkas geometri **eksak menurut definisi**: `ocr:cetak-lembar` menggambar kertasnya
DARI koordinat itu, jadi kotaknya nggak mungkin meleset dari yang tercetak. Yang belum pernah
diuji sekali pun bagian sesudahnya — rantai **kamera → warp perspektif → potong sel** — diadu ke
kertas yang beneran dicetak, difoto miring, di bawah lampu lab.

`terverifikasi: true` artinya rantai itu **sudah dibuktikan**, bukan "koordinatnya sudah benar".
Jadi cuma manusia yang boleh menyetelnya, dan bukti yang dibutuhkan cuma satu: satu foto lembar
cetak yang sudah diisi. Enam lembar kimia sudah punya bukti itu; empat belas sisanya belum.

**Yang berubah 27 Agt 2026: butir ini berhenti menahan apa pun yang bisa disentuh teknisi.**
`terverifikasi` cuma menggerbangi tombol `PINDAI LEMBAR KERJA` — jalur lembar bermarker — dan
tombol itu dicabut permanen dari layar 26 Agt 2026 atas permintaan pemilik lab. `PindaiReviewScreen`
yang jadi ujungnya sekarang tidak pernah dibuka dari mana pun di aplikasi.

Jadi waktu pemilik proyek melaporkan kameranya "cuma nangkap berapa tabel aja", sebabnya BUKAN ini
(lihat §12). Mesinnya sengaja ditinggal utuh — dia satu-satunya kode yang sudah terbukti bisa
memetakan foto kertas bermarker ke sel — jadi butirnya tetap terbuka, cuma turun jadi prasyarat
kalau jalur itu dipasang lagi, bukan blocker yang berjalan hari ini.

---

---

## §29 — Alat ke-29: **Anak Timbangan (OIML R111)** — 10 Sep 2026

Master `1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx` (21 sheet, tanpa
password) + kertas `SIDIK-FM-CAL-0541_Rev.0 - LEMBAR KERJA ANAK TIMBANGAN
(Non KAN)`. Alat KEDUA di kelompok Massa sesudah Timbangan (alat ke-21), dan
alat ketiga yang berprofil tapi **di luar lampiran akreditasi** (sesudah Gas
Detector dan Height Gauge).

**Verifikasi sebelum PHP ditulis: 1033 nilai, nol beda pada 5·10⁻⁶** — 20 titik
x (ms, de, b, mT) plus 20 blok budget x 6 komponen x (U, divisor, vi, ui, ci,
uici, uici²) plus uc, veff, k, U95 tiap blok. Ditegakkan
`AnakTimbanganMasterTest` (12 test / 1011 asersi).

**Nol kolom baru** di `raw_measurements` — sumbu `peran_sensor`/`pembacaan_ke`
yang sudah ada cukup, dan blok tingkat-sesi masuk `spesifikasi_alat`.

### Yang DIBETULKAN dari master (tiga kerusakan rujukan)

| Yang rusak | Dampak terukur |
|---|---|
| Kolom `b` memakai massa keping PERTAMA (100,000144 g), bukan massa keping yang sedang dihitung | sampai **2,58 mg pada keping 1 g yang toleransinya 0,10 mg** (25,8x MPE); sertifikat master mencetak keping 0,1 g sebagai 0,09884965 g — meleset 1,16 mg, 9,4x ketidakpastian yang dicetak di baris yang sama |
| Koreksi apung dua keping 200 g HILANG (rumusnya menunjuk sel kosong) | +0,0169 mg, kecil — yang tidak boleh ditiru cara diamnya |
| Ketidakpastian tekanan di sertifikat memakai angka KELEMBABAN meternya (3 hPa, bukan 2) | 3,0017 → 2,0025 hPa |

### Yang DITIRU + diangkat jadi pertanyaan (23 butir)

Yang paling menentukan, dan dampaknya sudah dihitung:

- **§1 keterulangan neraca.** Sel berlabel `Rata-rata STDev` sebenarnya berisi
  **SIMPANGAN BAKU dari keenam simpangan baku harian** — terbukti untuk kelima
  neraca sampai epsilon mesin (beda relatif 1,5·10⁻¹⁶). Nilainya (0,046363 mg)
  lebih kecil daripada keterulangan hari **mana pun** (0,0497–0,1732 mg), dan
  dia komponen terbesar di budget. Kalau diganti gabungan kuadrat harian,
  **U95 seluruh sertifikat naik 1,88–1,95x**. Ditiru karena `FORM VALIDASI`
  mencatatnya sebagai perubahan metode yang SENGAJA (revisi 3, 29 Mei 2026,
  sudah divalidasi Manajer Teknis) — yang memutuskan lab.
- **§6 tabel densitas** memuat nilai yang mustahil (10650 kg/m³ = timbal untuk
  F1 pada 5 g; 14400 kg/m³) dan kolom E2/F1 tertukar antara 0,1 g dan 0,2 g.
  Disalin apa adanya + ditandai `disengketakan`, karena dampaknya justru KECIL:
  koreksi apung yang benar untuk seluruh keping cuma 0,0012–0,0303 mg, di bawah
  seperempat U95.
- **§20 dimensi `ci` apung** tidak konsisten (kg/m³ x m³/kg x GRAM dibaca
  miligram). Dibaca konsisten, U95 naik 0,35 %.

### Gerbang yang menahan, bukan yang memperingatkan

Sertifikat master menerbitkan **`#VALUE!` di lima dari dua puluh baris** dan
satu keping 10 g sebagai **5,500163 g** — meleset 45 %, karena `T1` tertulis
0,9998 alih-alih 9,9998 — dengan ketidakpastian yang tetap rapi 0,1226 mg.
Nol sel memprotes.

Enam gerbang dipasang, dan semuanya **menolak titiknya**, bukan memperingatkan:
peran ABBA kosong, nominal tidak ada di tabel keping, densitas kelas tidak
ditabelkan, keping kembar tanpa `no_identitas`, `|de| > 10 x MPE`, dan blok
sesi belum lengkap. Ambang `|de|` memisahkan salah ketik (22.500x MPE) dari
titik sehat terjauh (di bawah 2x MPE) dengan jarak sangat lebar.

### Temuan dari KERTASNYA, yang tidak ada di workbook

Kertas Rev.0 dibaca lebih dulu supaya bentuk lembarnya benar, dan empat hal
cuma kelihatan dari situ: dia minta **tiga** pembacaan per baris ABBA (`X1 X2
X3`, dua belas angka per keping) sementara workbook memakai satu (§23); dia
**tidak punya kolom tekanan udara** sama sekali padahal tanpa tekanan densitas
udara tidak bisa dihitung (§21); dia menyebut `TH-3` sementara workbook memakai
`Thermobarometer`; dan merk Analytical Balance tertulis `X5204` di kertas lawan
`XS204` di workbook — beda satu karakter (§22).

Satu lagi dari generatornya sendiri: meter `TH-7` **tidak rekonsiliasi dengan
dirinya sendiri** (offset tetap 0,04 °C dan 0,40 %RH, meleset di empat dari
lima baris). Generatornya menolak menulis, dan penolakan itu memang menggigit
waktu pertama dijalankan — tabelnya karena itu tidak disalin ke server (§19).

### Status akreditasi

Kelompok Massa di LK-285-IDN cuma memuat no. 12 "Timbangan (Elektronik,
mekanik)" — alat yang MENIMBANG. `dalamLingkupAkreditasi() === false`, dan itu
bukan tafsiran: nama formulirnya sendiri menyebut **(Non KAN)**. Tapi kop
kertasnya **tetap mencetak `LK-285-IDN`** — satu lembar, dua pernyataan yang
saling meniadakan (§15).

Tabel CMC yang menggoda di `DATABASE` (sembilan pita) **tidak dipungut**:
labelnya sendiri berbunyi "Jenis Timbangan", dan namanya menunjuk workbook lain
lewat tautan luar.

## §30 — Alat ke-30..32: **Dial Indicator, Jangka Sorong, Sieve Mesh** — 15 Sep 2026

Tiga workbook master (ber-password) dari pemilik proyek, beserta analisis & prompt
implementasi yang ditempel. Kelompok Panjang di lampiran LK-285-IDN sekarang **lengkap
berprofil**: Sieve (no. 33), Micrometer (34), Vernier Caliper (35), Dial Indicator (36).

### Bukti sebelum kode

Ketiga workbook didekripsi dan tiap sel di-dump nilai + rumusnya, lalu direimplementasi di
Python dari INPUT mentah:

| Alat | Sel diadu | Beda | Test PHP yang menjaga |
|---|---|---|---|
| Dial Indicator | 108 | 0 (1·10⁻⁹) | `DialIndicatorMasterTest` 9 test / 109 asersi |
| Jangka Sorong | 311 | 0 (5·10⁻⁶) | `JangkaSorongMasterTest` 10 test |
| Sieve Mesh | 189 | 0 (5·10⁻⁶) | `SieveMasterTest` 16 test / 168 asersi |

Jalur HP → server → hitung ulang dijaga `DialIndicatorSesiTest` (6) dan `JangkaSorongSieveSesiTest` (4).

### Klaim prompt yang ternyata SALAH atau kurang

- Dial Indicator "5 repeat, √5" — Evaluation-nya **sepuluh** bacaan, dibagi √5 (pertanyaan §1).
  Kertas FM-0526 malah memungut **enam** bacaan per nominal (UP×3 + DOWN×3), workbook lima (§6).
- Nomor **formulir** `SIDIK-FM-CAL-0526` itu kertas **Dial Indicator**; Sieve `0536`, Jangka Sorong
  `0527`. (Nomor IK `IK-CAL-0526` memang Sieve — dua deret nomor yang berbeda.)
- Sieve "6 pengulangan H89:M91" — rentangnya H89:N91 dan isinya **rumus salinan opening 1..6**,
  bukan pengukuran ulang. Koreksi standar master **selalu 0** (VLOOKUP menunjuk kolom kosong).
- Sieve "CMC 45 µm–2 mm / 2–150 mm" benar untuk master, **berbeda** dari lampiran (45–4000 µm / 4–100 mm).
- Jangka Sorong "Kerataan boolean eksklusif" — dua checkbox terpisah; satuan µm tidak ada. Master
  **tanpa lantai CMC** padahal Vernier Caliper ada di lampiran; repeatability Depth selalu 0.

### Keputusan yang diambil hari ini

- **Koma desimal:** `App\Support\AngkaDesimal` — `"19,06"` → 19.06 di pembacaan, nominal, titik ukur,
  suhu, kelembapan untuk SEMUA lembar (sebelumnya 422). Bentuk ambigu `1.234,5` tetap ditolak. HP sudah
  membakukan sendiri (`parseAngka`); ini lapis kedua.
- **k:** Dial & Jangka Sorong dari t-Student v_eff (`GumCalculator::agregasiBudget`); Sieve dipatok 2
  (ditiru dari master, pertanyaan lab).
- **Lantai CMC dipasang di ketiga alat.** Dial Indicator & Sieve di luar pita lampiran → sesi diblokir.
- **Jangka Sorong > 300 mm** (sesi contoh master 600 mm): terbit **tanpa lantai CMC dan tanpa klaim
  akreditasi** — hook baru `CalibrationProfile::dalamLingkupAkreditasiSesi()`, dibekukan ke snapshot.
  Preseden Height Gauge, tapi per SESI karena lampiran membatasi rentang, bukan jenis alat.
  **Diputuskan** 15 Sep 2026 — pemilik proyek menyerahkan keputusannya ("pake keputusan mu").
- **Dial Indicator UP/DOWN digabung** jadi satu rata-rata & satu koreksi, mengikuti `AVERAGE` master dan
  format sertifikat satu kolom. Posisi kotak tetap tersimpan (`sensor_ke`) kalau histeresis kelak diminta.
- **Dua test yang selama ini `markTestSkipped` di CI kini memeriksa sungguhan:** kolom sumber input
  dibaca dari `sqlite_master` (enum SQLite = `CHECK … IN`), dan dropdown thermohygro Gas Detector wajib
  persis unit ber-kalibrasi tekanan. CI tinggal nol test dilewati.
- **Nominal di luar daftar terkalibrasi** (balok ukur, caliper checker, Tabel_MPE) → titik diblokir
  dengan alasan yang menyebut angkanya. Master menghilangkannya diam-diam lewat `IFERROR`.
- **Dial Indicator Evaluation identik terbit + peringatan** (beda dari Micrometer/Height Gauge, di mana
  itu terbukti data rusak).
- **Sieve:** minimum opening ditegakkan dengan kolom tipe yang BENAR (Calibration = kolom 7), nominal
  tidak dijepret ke terdekat, standar kedaluwarsa diblokir, verdict ±Y memakai guarded acceptance
  (keputusan 14 Jul).
- `kalibrasi:uji-profil` sekarang mendahulukan alat yang punya sesi terhitung — alat demo "Jangka Sorong
  Mitutoyo" (id kecil, nol sesi) sempat menutupi sesi contoh master.

### Nol kolom baru

`raw_measurements` memakai `peran_sensor`/`sensor_ke` yang sudah ada: `di_balok`/`di_pembacaan`,
`js_<grup>_nominal`/`js_<grup>_pembacaan` (titik_ke 1../101../201..), `sieve_warp|weft|kawat`
(`sensor_ke` = nomor opening). Blok tingkat-sesi di `spesifikasi_alat.dial_indicator|jangka_sorong|sieve`.

### Pertanyaan lab

`docs/pertanyaan-lab-dial-indicator.md` (13), `docs/pertanyaan-lab-jangka-sorong.md` (13),
`docs/pertanyaan-lab-sieve.md` (16).

### Belum

- ~~Sertifikat Jangka Sorong & Sieve~~ **BERES 15 Sep 2026.** Hook baru `remarkTitikKe()` (remark dari
  `titik_ke`, bukan nominal) memisahkan tiga tabel Jangka Sorong dengan judul persis master dan U95/`k`
  masing-masing; Sieve berlabel `Wrap (x')`/`Weft (y')`/`Wire Diameter (Ø)`, kolom UUT `Standard Indication`,
  dan `tandaKoreksiSertifikat() = -1` supaya `Correction` = terukur − nominal seperti master. Nilai
  `koreksi` tersimpan tidak berubah. Dijaga `DimensiSertifikatTest`; Sieve masuk mode padat (tiga kelompok).
- ~~Sisi mobile~~ **BERES 15 Sep 2026** (mobile `ccdb2bc`) — bentuk lembar digenerate dari server, test payload.
- Suite MySQL (`phpunit.mysql.xml`) belum dijalankan untuk perubahan ini.

## §31 — Lembar kerja disejajarkan ke kertas resmi, semua alat — 15 Sep 2026

Permintaan: *"bikin lembar kerja yang bener dari atas sampai bawah strukturnya … cek semua alat … gk
bingungin"*. Sumbernya 41 kertas di `Project-PT-Sidik/worksheet_alat_calibration/`, dibaca sebagai
GAMBAR (bukan dari ringkasan), diadu ke `bentukLembarKerja()` tiap profil. HP merender `bagian` persis
urutan server, jadi seluruh perbaikan ada di profil PHP.

### Yang diubah

| Alat | Selisih kertas | Perbaikan |
|---|---|---|
| Flowmeter gravimetri | Kotak "Volume Pipa dari Std. ke UUT (V) Liter" (FM-0538.A/.B) tidak punya field — `FlowmeterMentah` membacanya tapi nilainya tak pernah bisa sampai | Field `spesifikasi_alat.flowmeter.volume_pipa_l` (tampil hanya varian gravimetri) + aturan request. Belum masuk budget (pertanyaan lab §5 flowmeter gravimetri) |
| Turbidimeter, Conductivity | Kertas FM-0530/0510: "tulis resolusi UUT di masing-masing titik" — tidak ada field | `spesifikasi_alat.resolusi_titik_N` per titik. **Dicatat, angka tidak digeser** (pola Viscometer): beda dari resolusi yang dipakai budget → peringatan sesi `resolusi_titik_beda_dari_master`. Titik tengah Conductivity lolos di bentuk µS/cm maupun mS/cm |
| Dial Indicator | Kertas: Data Kalibrasi → Evaluasi | Evaluasi dipindah ke sesudah Data Kalibrasi |
| Jangka Sorong | Kertas: Pengukuran Luar → Evaluasi → Dalam → Kedalaman | Diurut begitu; Kesejajaran (tanpa kotak di kertas) ke akhir |
| TITS | Environment Condition + Thermohygro tercetak DI panel identitas FM-0505 | Dipindah dari bagian `hasil` ke `identitas_alat` |
| Thermocouple, Termometer Gelas, Thermohygro | Tanggal di kolom kanan, bukan baris teratas | Tanggal menutup blok identitas (sama dengan TITS/TIDS/Autoclave) |
| Refractometer | Judul disalin dari template pH | "General Information", "Standard Used", "Location of Calibration", "Methode", "Data Result", "Corrected by" — `kode` bagian tidak berubah |
| Timbangan | Nomor label 1..9 tidak sejajar nomor kertas FM-0508 | 1 Name · 2 Capacity · 3 Resolution · 4 e & kelas · 5 Type · 6 Serial · 7 Merk; Rentang Ukur tanpa nomor |
| 9 profil | Judul "Identitas Alat dan Data Customer" diikuti bagian "Data Customer"; dua kotak berlabel "Nama Alat" | Judul identitas → "Identitas Alat"; dropdown alat → "Pilih Alat" |
| HP | Petunjuk kotak `daftar_angka` dipatok `20+20+10` (gram) juga di Dial Indicator (mm) | "Pisahkan tiap keping dengan +" |

### Yang SENGAJA tidak diubah

- **Standar dipilih sebelum mengukur.** Di kertas kotak Standard selalu di bawah tabel; di aplikasi tetap di
  atas karena standar yang menentukan koreksi tiap pembacaan (`SemuaProfilLembarKerjaTest`).
- **Timer/Stopwatch kolom ke-4.** Kertas FM-0512 mencetak `0.01 S`, workbook master (sumber rumus) `0.001 S`
  dengan isi 123/211/45 — jelas milidetik. Kode ikut master; diangkat sebagai pertanyaan.
- **Nomor revisi.** Audit mengira FM-0515 Rev.5 & FM-0525 Rev.3 — kaki halaman kertasnya menulis `Revise : 4`
  dan `Revise : 2`, sama dengan kode. pH `kode_dokumen` ada di `LembarKerjaTemplate`.
- **Anak Timbangan "Technician ID".** Teknisi tercatat otomatis dari akun yang login; kotak kedua hanya
  menggandakan isian.

### Pertanyaan lab baru

1. Timer FM-0512: kolom ke-4 kertas `0.01 S`, master `0.001 S`. Stopwatch lab menampilkan berapa digit? Kalau
   centidetik, teknisi yang menyalin `12` dari layar akan tercatat 12 ms, bukan 120 ms.
2. Turbidimeter FM-0530 Rev.2 mencetak LIMA larutan (0.04 / 15 / 100 / 750 / 2000 NTU); master dan lampiran
   memakai TIGA (1 / 100 / 1000 NTU). Kertas mana yang berlaku?
3. Resolusi UUT per titik (Turbidimeter/Conductivity): cukup dicatat, atau menggantikan resolusi master di
   budget?
4. Centrifuge/Tachometer FM-0515 mencetak tabel **Before** dan **After adjustment** (5 repeat × 3 set point),
   sedangkan master hanya punya satu blok pembacaan per rentang (6 rentang × 3 set point × 5 repeat) tanpa
   adjustment. Lembar aplikasi ikut master. Apakah pembacaan sebelum adjustment perlu disimpan?

### Pengecekan visual susulan (15 Sep 2026)

- **Sieve FM-0536:** kertasnya 15 baris × dua blok berdampingan, masing-masing bernomor 1..15; master
  menomori opening berurutan. Nomor tetap berurutan 1..30 (yang disimpan & dihitung), tapi label baris
  sekarang `1 (kiri 1)` … `16 (kanan 1)` … `30 (kanan 15)`. Dropdown alat berlabel "Pilih Alat". Bagian 2
  "Diameter dan Ketinggian Rangka" (3 baris) sudah cocok.
- **Centrifuge/Tachometer FM-0515:** tidak diubah — lihat pertanyaan 4.

## §32 — Jawaban lab dikerjakan jadi kode — 16 Sep 2026

Sumbernya `jawaban.md` (16 Sep 2026): jawaban teknis atas ±270 butir pertanyaan lab. Yang dikerjakan
di sini butir yang **menaikkan U atau membetulkan rujukan yang rusak** — arah yang tidak pernah
membuat sertifikat mengaku lebih teliti dari yang bisa dibuktikan.

### Sudah mendarat

| Butir | Perubahan | Arah |
|---|---|---|
| §3.1 Timer | Kotak keempat boleh `sentidetik` (kertas FM-0512 `0.01 S`) selain `milidetik`; satuannya dari NAMA kotak, dipilih lembar dari resolusi alat sesi | — (mencegah salah 10×) |
| §3.3 Turbidimeter & Conductivity | Resolusi per titik masuk budget, diambil yang TERBESAR antara tulisan teknisi dan master | U naik / tetap |
| §3.4 Centrifuge & Tachometer | Tabel as-found (`sebelum_adjustment`) sesuai ISO/IEC 17025 §7.8.4.1; dicatat, tidak dihitung | — |
| §14.1 Sieve | Pengulangan `s/√n`, bukan `s/n` (GUM 4.2.3) | U naik ≈2× |
| §14.2 Sieve | `k` dari t-Student pada v_eff, bukan dipatok 2 | U naik |
| §14.9 Sieve | Tiga sel Tabel_MPE dibetulkan ke ASTM E11 saat dibaca (Ø kawat 80 µm 0,56 → 0,056) | Deviasi kawat benar |
| §11.5 Height Gauge | Paralelisme = `Max − Min` (ISO 1101), bukan `STDEV(Max;Min)` | Alat yang dulu lolos karena ÷√2 sekarang gagal |
| §17.3 Termometer Gelas | Keterulangan standar ÷√n, sama dengan baris UUT di atasnya | U naik |
| §17.5 Thermocouple | Komponen keterulangan yang master hitung tapi buang, sekarang masuk budget | U naik |
| §16 TIDS D2 | U95 sensor Recorder dari tabel termokopel, bukan literal 0,14 | U naik |
| §16 TIDS D3 | Drift Recorder dari `Tabel_Drift_Recorder`, bukan sel tabel KOREKSI (−0,2 — negatif) | U naik |
| §2.9 TIDS D4 | Dua belas komponen dijumlah, bukan sembilan (`SUM` yang berhenti di baris 32) | U naik |
| §8 T4 Timbangan | `ci` drift Mref dihitung (kapasitas ÷ nominal Mref); sesi master tetap 10 | Benar per sesi |

Tiap penyimpangan dari master tetap melahirkan catatan audit yang menyebut angka versi master, dan
test masternya sekarang menegakkan ARAH (kita wajib lebih besar), bukan kesamaan.

### SENGAJA belum dikerjakan — semuanya MENGECILKAN U

Aturan emas proyek: perbaikan yang mengecilkan U menunggu tanda tangan Manajer Teknis (jawaban.md
§21 "Rapat 3"). Yang masuk daftar ini: A-1 √(√3) TITS/Thermohygro, B-1 drift ganda TITS, Timbangan
T2 (`U/k`, kg 0,0425 → 0,0248) & T5, drift `/12 → /365` (Height Gauge, Dial, Jangka Sorong), dan
Dial §1 (√6).

**Micrometer §11 juga ditahan**, dan alasannya beda: satuan `ci` termalnya memang salah (mm di
budget µm), tapi membetulkannya sendirian menaikkan U95 ke ~0,978 µm — DI ATAS pita CMC 0,87 µm —
sementara `u` kedua komponennya juga belum benar (master memakai besaran itu sendiri sebagai
ketidakpastiannya). `docs/analisis-pertanyaan-lab-micrometer.md` §11 menyebut dua angka yang cuma
dimiliki lab: ketidakpastian pengukuran suhu Lab Dimensi, dan δα balok ukur vs rangka mikrometer.
Membetulkan satu sisi saja menukar kesalahan kecil dengan kesalahan yang lebih besar.

### Yang tetap milik lab

Butir 🔴 di `jawaban.md` §2 — sertifikat yang sudah di tangan pelanggan (Anak Timbangan §1 & §12,
Flowmeter CMC, Height Gauge §6, Micrometer 095-CAL-324, Suhu C-12/C-13, nomor metode Thermocouple,
satuan Thermohygro). Itu prosedur Pekerjaan Tidak Sesuai ISO/IEC 17025 §7.10 + sertifikat pengganti
§7.8.8, bukan pekerjaan kode.

## §33 — Paket keputusan Manajer Teknis, Bagian 1 dikerjakan — 16 Sep 2026

Sumbernya `jawaban.md` + paket keputusan MT yang dikirim pemilik proyek. Formulir parafnya masih
kosong; yang dipakai sebagai dasar **instruksi pemilik proyek** ("terapkan sekarang"), dan tiap
perubahan mencatat itu di jejak auditnya sendiri: *"disetujui pemilik proyek 16 Sep 2026, paraf
Manajer Teknis menyusul"*. Paraf MT tetap harus turun — dia catatan validasi metode
(ISO/IEC 17025 §7.2.2), bukan formalitas.

Ketujuh butir ini MENGECILKAN U, jadi sebelumnya ditahan aturan emas proyek. Yang berubah bukan
aturannya melainkan dasarnya: aturan itu berbunyi "U tidak boleh turun **tanpa dasar tertulis dan
persetujuan**", bukan "U tidak boleh turun".

### Yang mendarat

| # | Butir | Sifat kesalahan | Perubahan angka |
|---|---|---|---|
| 1 | A-1 pembagi √(√3) | `U = N/SQRT(Q)` padahal `Q` sudah berisi pembagi — 3^¼ bukan pembagi distribusi mana pun (GUM 4.3.7) | TITS AC Pick Up 0,15197 → 0,11547; Uc measure 0,3553 → 0,3351; Enclosure Recorder Uc 0,59543 → 0,59535; Thermohygro tiga grup bergeser |
| 2 | B-1 drift ganda TITS Source | Baris 22 menambah drift Constant Type N lewat alamat MUTLAK, di luar baris drift kalibrator yang benar (GUM 5.1) | Uc sesi contoh 0,5761 → 0,3600; U95 dilaporkan TETAP 1,2 (lantai CMC menang) |
| 3 | T2 Timbangan `U/k` | `U of Correction` itu ketidakpastian DIPERLUAS, masuk budget Weighing sebagai baku (GUM 4.3.3, EURAMET cg-18 §7). Master: kg mentah, gram ÷k, substitusi ÷√3 | Weighing kg titik 1 uc 0,02130 → 0,01293 |
| 5 | Drift `/12` → `/365` | Komponen mm/tahun (atau µm/tahun) dikali umur berSATUAN HARI | Height Gauge U 0,015668 → 0,015499; Dial & Jangka Sorong komponen drift ~30× lebih kecil, U95 umumnya tertutup lantai CMC. Depth Jangka Sorong tidak tersentuh — masternya memang tanpa faktor umur |
| 6 | Dial §1 √5 → √6 | `s/√n` dengan n = bacaan yang DIRATA-RATA per titik; kertas FM-0526 Rev.3 memungut 6 (UP×3 + DOWN×3), `vi` = 10 − 1 = 9 | U95 sesi contoh 0,008811 → 0,008642 |
| 7 | Pita CMC Termometer Gelas | Pita bertumpuk di batasnya dimenangkan pita BAWAH — ikut lampiran LK-285-IDN no. 4 dan dua alat saudaranya (`orderBy('range_max')`) | Nol hari ini; berlaku di titik batas 100 °C |

**Syarat butir 6 dipenuhi di kode, bukan di kertas.** UP & DOWN cuma boleh digabung sebagai
pengulangan kalau histeresisnya dinilai sendiri, jadi `DialIndicatorCalculator` sekarang menerbitkan
komponen ke-11 `histeresis`: setengah-lebar |rata UP − rata DOWN| terbesar sesi, distribusi persegi.
Nol berarti "tidak ada histeresis terukur", bukan "tidak diperiksa".

### Butir 4 (T5 silang-kabel Timbangan) — DICOBA lalu DIKEMBALIKAN

Ini satu-satunya butir yang tidak bisa diterapkan seperti tertulis, dan alasannya terukur: waktu
`K6`/`K7` dibaca dari kolomnya sendiri, kolom STDEV **Middle** workbook substitusi ternyata seragam
— simpangan bakunya 2,7·10⁻¹³, jadi komponen `Repeatability MID-range` praktis LENYAP dari budget
(0,0707 → ~0).

Itu menukar angka yang salah dengan komponen yang hilang, dan komponen hilang adalah kelas kegagalan
paling mahal di repo ini: nol error, U-nya cuma mengecil. Rujukan master tetap ditiru sampai lab
menjawab satu pertanyaan baru: **kolom Middle sesi substitusi itu memang tidak diukur, atau
salinannya rusak?** Kalau tidak diukur, yang benar bukan membaca kolomnya melainkan memblokir
titiknya. Alasannya ditulis di `TimbanganCalculator` supaya tidak "dibetulkan" balik tanpa membaca.

### Test master: dari "sama dengan master" ke "arahnya benar"

Belasan test master beralih dari mengadu nilai ke mengadu ARAH berikut batas bawahnya (mis. "uc
wajib < master TAPI > 0,9 × master"). Batas bawah itu bukan hiasan: tanpa dia, komponen yang HILANG
lolos sebagai "turun tipis" — persis yang menangkap butir 4 di atas.

### Berlaku maju

Sertifikat lama tidak diterbitkan ulang: versi lamanya mencetak U yang lebih BESAR — konservatif,
tidak merugikan pelanggan, dan ILAC P14 tidak melarang U di atas CMC. Yang perlu dicatat lab: nomor
revisi master & FORM VALIDASI, plus paraf MT di formulir paket keputusan.

## §34 — Paket keputusan Bagian 3: tekanan Autoclave, JALAN C — 17 Sep 2026

Pemilik proyek memilih **jalan C** dari tiga pilihan paket keputusan. Isinya: angka tetap dicetak,
tapi ketidakpastian pinjamannya dihitung dan klaim akreditasinya dicabut untuk baris itu saja.

**Masalahnya.** Siklus sterilisasi 121 °C berjalan di ~1,12 bar, sementara tabel sertifikat Pressure
Disk Logger mulai 1,5 bar. Koreksi & U95 standar di titik itu DIPINJAM dari baris 1,5 bar — 25 % dari
titik sebenarnya — dan sampai 16 Sep 2026 itu terjadi tanpa satu pun tanda. Nilai standar di titik
yang tidak pernah dikalibrasi tidak tertelusur (ISO/IEC 17025 §6.5).

**Yang dikerjakan:**

1. **Komponen ketidakpastian ekstrapolasi** masuk budget tekanan. Batasnya selisih koreksi antara dua
   baris tabel terdekat ke ujung yang terlampaui — ukuran seberapa cepat koreksi standar bergerak di
   sana, yaitu persis yang tidak diketahui. Distribusi persegi, `vi` besar (GUM G.4.2). Sesi contoh:
   Uc 0,0044284 → 0,0044419 bar.
2. **Baris tekanannya dicetak tanpa klaim akreditasi**, dengan sebabnya tertulis di sertifikat:
   titiknya di bawah titik kalibrasi terendah standar. **Baris suhu tidak terpengaruh** — standarnya
   memang terkalibrasi di rentang yang dipakai.
3. Penanda `ekstrapolasi_tekanan` yang sudah ada sejak audit tetap melahirkan catatan tingkat INFO
   di validator, jadi jejaknya ada di sesi maupun di dokumen.

**Yang membuat ini sementara.** Komponen dan catatannya HILANG SENDIRI begitu tabel kalibrator
diperluas sampai titik yang benar-benar dipakai — tidak ada kode yang perlu diubah, cukup tabelnya.
Itu jalan A, dan tetap yang disarankan di kalibrasi ulang logger berikutnya.

## §35 — Alur verifikasi lembar kerja & peran Super Admin — 17 Sep 2026

Permintaan pemilik proyek, disampaikan 17 Sep 2026. Intinya satu kalimat: **rantai teknisi →
pemeriksa → sertifikat harus kelihatan utuh, salahnya ditandai bukan dihapus, dan super admin
memegang hak penuh atas seluruh rantai itu.**

### Yang ternyata SUDAH ADA — dibaca sebelum satu baris ditulis

Ini ditulis paling atas karena menentukan ukuran pekerjaannya. Sebagian besar kerangkanya sudah
berdiri sejak lama, dan mengarang ulang berarti membangun yang kedua di sebelah yang pertama:

| Yang diminta | Sudah ada? | Di mana |
|---|---|---|
| Alur teknisi → pemeriksa → sertifikat | **ADA** | `draft` → `menunggu_approval` → `disetujui`/`perlu_revisi`, `CalibrationSession` |
| Pemeriksa membuka lembar kerja teknisi | **ADA** | Panel Filament, `CalibrationSessionsTable::detail()` |
| Tolak → balik ke teknisi + catatan | **ADA** | Aksi `reject`, menulis `catatan_revisi`, `reviewed_by`, `reviewed_at` |
| Teknisi mengerjakan ulang sesi yang ditolak | **ADA** | `CalibrationController` §"revisi" |
| Siapa yang mengirim & kapan | **ADA** | kolom teknisi + `submitted_at` di sesi |
| Notifikasi HP | **ADA** | `FcmPengirimPush`, `PengirimPush`, plus 7 kelas `Notification` (`SesiPerluRevisi`, `SesiDisetujui`, `SesiMenungguApproval`, `SertifikatTerbit`, …) |
| Aplikasi pelanggan | **ADA** (M0–M1 mendarat) | Modul CertiCal, `routes/api_pelanggan.php` |

**Jadi yang benar-benar baru cuma enam hal di bawah.** Sisanya menyambung, bukan membangun.

### Keputusan yang SUDAH diambil — jangan ditanya ulang

Empat keputusan pemilik proyek, 17 Sep 2026:

1. **"Master Data" itu label tampilan, bukan nilai `users.role`.** Database tetap `admin`. Yang
   berubah tulisan di panel dan aplikasi. Alasannya bukan malas: mengganti nilai ENUM menyentuh
   tiap `role:admin` di `routes/api.php`, tiap test, ability token, plus migrasi ENUM berikut
   datanya — untuk hasil yang identik di mata pengguna.
2. **Super admin melihat LINTAS laboratorium.** Bukan cuma semua peran dalam satu lab.
3. **Super admin boleh mengedit lembar kerja kapan saja, termasuk sesudah sertifikat terbit.**
4. **Notifikasi untuk SEMUA kejadian**: lembar masuk ke pemeriksa, lembar ditolak balik ke
   teknisi, sertifikat terbit ke pelanggan, sertifikat diunduh kembali ke lab.

### Yang bertabrakan dengan aturan yang sudah berlaku — dicatat, bukan didebat

Dua dari empat keputusan itu diambil SESUDAH risikonya disebutkan. Ditulis di sini supaya yang
membaca setahun lagi tahu ini pilihan sadar, bukan kelalaian:

**(a) Super admin lintas laboratorium menembus `organization_id`.** Seluruh data lab disaring
`organization_id`, dan penyaringan itu yang menjaga kerahasiaan antar pelanggan (ISO/IEC 17025
§4.2). Peran yang menembusnya berarti satu akun bisa membaca pekerjaan lab lain. Konsekuensinya
mengikat, bukan pilihan: **tiap akses lintas-organisasi WAJIB tercatat** — siapa, kapan, baris
mana, dari IP mana. Tanpa itu lab tidak bisa menjawab pertanyaan asesor "siapa yang pernah
melihat data pelanggan saya".

**(b) Edit sesudah sertifikat terbit menembus kebekuan angka.** `uncertainty_calculations`
DISIMPAN dan tidak pernah dihitung ulang saat dibaca — sertifikat 5 tahun lalu wajib tetap sama
angkanya. Kalau lembar kerjanya bisa berubah sesudah terbit, sertifikat yang SUDAH DIPEGANG
pelanggan bisa berbeda dari yang di server, dan itu persis yang dicari asesor akreditasi.
Konsekuensinya juga mengikat: **tiap edit sesudah terbit menyimpan snapshot sertifikat lama dan
melahirkan REVISI bernomor**, bukan menimpa diam-diam. Hak super admin tidak dikurangi — dia tetap
bisa mengubah apa pun, kapan pun; yang ditambahkan cuma jejaknya.

### Enam yang benar-benar baru

**1. Penandaan per titik, bukan satu catatan untuk seluruh sesi.**
Hari ini `catatan_revisi` satu kolom teks untuk satu sesi — teknisi dapat satu paragraf dan harus
menebak baris mana. Yang diminta: tanda menempel pada BAGIAN yang salah, isinya tidak dihapus.
Tabel baru `tanda_revisi` (bukan kolom baru di `raw_measurements` — sumbu yang ada tidak cocok
untuk data yang lahir dari orang lain di waktu lain): sesi, sasaran (baris mentah / blok
tingkat-sesi), catatan, ditandai_oleh, ditandai_pada, selesai_pada. Isi lembar tidak pernah
disentuh penandaan.

**2. Pemeriksa bisa memperbaiki sendiri, bukan cuma menolak.**
Hari ini pemeriksa cuma punya setujui/tolak. Ditambah: edit nilai, DENGAN jejak siapa yang
mengubah dari berapa jadi berapa. Untuk lab terakreditasi, "siapa yang memasukkan angka ini"
tidak boleh kabur.

**3. Peran `super_admin` dihidupkan.** Sudah ada di ENUM `users.role` sejak migrasi modul
pelanggan, perilakunya belum dibangun. Isinya: semua yang teknisi bisa + semua yang Master Data
bisa + lintas organisasi + edit sesudah terbit + kirim ke pelanggan.

**4. Pencatatan akses lintas-organisasi.** Konsekuensi (a) di atas. — **SEBAGIAN
SELESAI 23 Sep 2026:** lintas organisasi dibuka di **panel** lewat satu pintu
(`ScopesToOrganization`), dan tiap layar yang dibuka super admin dengan lingkup
lintas lab dicatat `App\Support\JejakLintasOrganisasi` ke `audit_logs`
(`action = dibaca`, satu baris per layar per request; diam total selama
`organizations` cuma satu baris — produksi masih satu). Dijaga
`SuperAdminLintasOrganisasiTest`. **Sisi API sengaja belum**: ~60 penyaring
`organization_id` tulis tangan di 15 controller, dan melebarkannya demi lab kedua
yang belum ada menukar risiko kebocoran dengan manfaat nol. Urutan yang benar
kalau lab kedua mendarat: pindahkan penyaring API ke satu tempat dulu, baru
lebarkan.

**5. Revisi sertifikat bernomor.** Konsekuensi (b) di atas.

**6. Notifikasi "sertifikat diunduh".** Enam kejadian lain sudah punya kelas `Notification`-nya;
yang ini belum ada karena peristiwanya sendiri belum dicatat.

### Sertifikat sampai ke HP pelanggan

Jalurnya sudah setengah berdiri dan tidak perlu dibangun dari nol: modul CertiCal (M0–M1) sudah
mendarat — pendaftaran, verifikasi, konteks perusahaan, keanggotaan. Grup rute
`auth:sanctum` + `aplikasi:pelanggan` + `pelanggan.aktif` + `perusahaan` sudah berdiri dan
menunggu diisi. Yang kurang endpoint `/sertifikat` di grup itu, dan itu memang sudah terjadwal
sebagai pekerjaan berikutnya modul pelanggan.

Yang mengikat di sini: sertifikat yang dilihat pelanggan HARUS berkas yang sama dengan yang
diserahkan bersama alatnya — satu sumber, `CertificateSnapshotBuilder`, bukan dokumen kedua yang
dibangun ulang untuk aplikasi. Dokumen yang berbeda isinya antara cetak dan layar itu temuan
asesor, bukan ketidaknyamanan.

### Berkas yang akan disentuh — ditulis SEBELUM diketik

Mengikat, sesuai §12 permintaan 7.

**Baru:** migrasi `tanda_revisi` + `akses_lintas_organisasi` + kolom revisi di `certificates`;
`app/Models/TandaRevisi.php`; `app/Services/Verifikasi/PenandaRevisi.php`;
`app/Services/Verifikasi/SuntinganPemeriksa.php`; `app/Policies/SesiPolicy.php`;
`app/Http/Middleware/LintasOrganisasi.php`; `app/Notifications/SertifikatDiunduh.php`;
`app/Filament/Resources/CalibrationSessions/…` (aksi tandai & sunting).

**Diubah:** `app/Models/User.php` (super_admin masuk `roles()` internal — hari ini SENGAJA tidak,
dan itu harus jadi perubahan sadar, bukan efek samping); `routes/api.php`;
`app/Services/MatriksIzin.php`; `app/Filament/**` (label "Master Data");
`app/Http/Controllers/Api/AuthController.php` (guard `super_admin` — lihat catatan di bawah).

### Yang HARUS dikerjakan lebih dulu, di luar urutan — **SELESAI 23 Sep 2026**

Prasyaratnya dulu begini: `AuthController` menolak `super_admin` di pintu login internal, lalu
menyuruhnya "pakai panel admin di peramban" sementara `canAccessPanel()` cuma menerima
`ROLE_ADMIN` — petunjuk ke pintu yang ikut terkunci, jadi akun `super_admin` pertama tidak bisa
masuk ke mana pun.

Dibuka 23 Sep 2026, **baca saja**: login aplikasi, panel `/admin`, channel organisasi, dan semua
rute `GET`/`HEAD` lewat `EnsureUserHasRole::lolosBacaSuperAdmin()`. Menulis tetap 403 di API dan
tersembunyi di panel (`App\Filament\Concerns\HakTulisPanel`) karena K4 belum dijawab manajer
teknis — tombol `approve` di panel menerbitkan sertifikat berlogo akreditasi, dan itu wewenang
yang belum diputuskan. `User::roles()` sengaja tetap tidak memuat `super_admin` supaya admin biasa
tidak bisa mencetaknya; daftar "boleh masuk" pindah ke `User::rolesInternal()`. Dijaga
`SuperAdminAksesTest` (13 kasus).

**Sisa yang belum:** baca lintas organisasi (59 tempat penyaring `organization_id`) berikut
pencatatan aksesnya — slice terpisah, supaya perubahan isolasi data ditinjau sendiri.

## §36 — Alat ke-34..39: **Volumetric Glassware** (Fixed & Graduated) — 22 Sep 2026

Dua workbook master ber-password (`Volumetric_Glassware_2026`, Fixed & Graduated) dari
pemilik proyek, beserta analisis & prompt implementasi. Kelompok Volume di lampiran
LK-285-IDN sekarang berprofil untuk enam alat: Buret (13), Gelas Ukur (17), Labu Ukur (18),
Pipet Ukur (19), Pipet Volume (20), Picnometer (21). `Buret Digital` (14) tetap generik.

**Keputusan pemilik proyek (21 Sep):** dua mesin, enam pintu — dua kelas keluarga
(`FixedVolumetricGlasswareProfile`, `GraduatedVolumetricGlasswareProfile`) dan enam profil
konkret yang cuma menyumbang nama lampiran. Nol hantu `IFERROR` di keterulangan Graduated
dihitung benar, angka master disimpan sebagai pembanding di jejak sesi.

### Bukti

| Yang diadu | Hasil | Test |
|---|---|---|
| ρ udara, ρ air (Tanaka), V20 per ulangan & rata-rata, kedua keluarga | selisih nol | `VolumetricGlasswareMasterTest` |
| 8 koefisien sensitivitas Fixed & Graduated, `uc`, Veff, k, U Graduated | cocok master sampai U | `VolumetricGlasswareBudgetTest` |
| Masukan MENTAH `INPUT_DATA` → V20, deviasi, masukan budget, pembanding K3/K4 | cocok master | `VolumetricGlasswareSesiTest` (Unit, 6) |
| Payload HP → simpan → angka CETAK `SERTIFIKAT` (V20, Correction, U95 0,003 & 0,34) + validator + `kalibrasi:hitung-ulang` | cocok | `VolumetricGlasswareSesiTest` (Feature, 5) |
| Koreksi suhu kalibrator Yokogawa + sensor PRT (dari sheet LOKAL `FC_Prt_Pt100`, bukan tautan luar `[4]`) | 27,0 → 27,32502900705911 | `TabelStandarVolumetricTest` |

### Temuan yang mengubah rancangan

- **Lantai CMC dari KAPASITAS alat**, bukan nominal titik (`PERHITUNGAN_U95%!C27 = INPUT DATA!E15`),
  dan U95 dicetak SATU angka di bawah tabel. Blok sesi dapat kunci `kapasitas_ml`.
- **Correction tercetak = V20 − Nominal**; validator menegakkan `koreksi = −error`, jadi profilnya
  memakai `tandaKoreksiSertifikat() = −1` (pertanyaan lab no. 11).
- Generator tabel neraca versi pertama membaca kolom lewat INDEKS dan menulis stdev Graduated
  sebagai resolusi (Graduated tidak punya kolom Res). Dibetulkan: kolom dicari lewat judulnya.
- Registry mencocokkan nama lewat substring, jadi "Buret" menangkap "Buret Digital". Kait baru
  `CalibrationProfile::namaBukanMilik()` (bawaan kosong) menahannya.
- Graduated: satu budget untuk semua titik, jadi satu titik yang ditolak **menahan seluruh sesi**
  — di kalkulator DAN di jalur simpan. Kurang dari dua titik ditahan (pertanyaan lab no. 12).

Nol kolom baru. Pertanyaan lab: `docs/pertanyaan-lab-volumetric.md` (13). Serah-terima HP:
`docs/perintah-frontend-volumetric.md`. Sisa & riset: `docs/volumetric-sisa-pekerjaan.md`.

---

## §37 — Alat ke-40 & ke-41: **Mesin UTM** dan **Load Cell** (Gaya) — 24 Sep 2026

Kelompok besaran **Gaya** mendarat, dua dari tiga alatnya. Masternya
`Project-PT-Sidik/alat-alat-Pt-Sidik/Alat_Gaya/` (tiga workbook + panduan
gabungan); Proving Ring menyusul sebagai tahap tersendiri, alasannya di bawah.

### Kenapa gaya tidak bisa menumpang pola alat sebelumnya

**Satu titik = dua belas pembacaan.** Empat posisi (0°, 90°, 180°, 270°) × tiga
replikat. Bukan pengulangan biasa: kalau load cell standar tidak tepat di sumbu
piringan mesin uji, gaya tidak jatuh lurus dan ada momen lentur yang ikut
terbaca. Kesalahan itu **cuma kelihatan** kalau alat dihadapkan ke arah berbeda
— diuji satu posisi saja, dia tersembunyi. Karena itu pula ada komponen
`misalignment` di budget yang tidak ada di satu pun alat lain di repo ini.

**Dua blok tingkat-SESI yang bukan titik ukur:** preload (zero & kapasitas
maks, 3 replikat) dan empat pengukuran misalignment. Keduanya masuk budget, jadi
bukan catatan tambahan. Ditaruh di `spesifikasi_alat`, **nol kolom baru** —
sesuai §Alur Kerja poin 4.

**Sebaran dilaporkan dua kali dengan angka berbeda:** RSD masuk budget, RRPE
tercetak di sertifikat. Yang kedua yang memberi tahu pelanggan seberapa
konsisten mesinnya, dan yang membedakan "meleset tapi konsisten" (bisa disetel)
dari "rata-ratanya pas tapi acak" (masalah mekanis).

### Yang dibuktikan sebelum satu baris PHP ditulis

Rumusnya diadu di Python lawan ketiga workbook, sel demi sel, sebelum kode.
Hasil yang menentukan: **mesin GUM yang sudah ada di repo mereproduksi master
persis**, termasuk pemotongan `v_eff` ke bawah sebelum mencari `t` — jadi tidak
ada mesin agregasi kedua yang lahir. Nilai CMC diadu ke lampiran akreditasi dan
**cocok persis**, yang sekaligus menggugurkan satu dari enam temuan panduan
(G9): pita `Tarik 10–88 kN` memang tidak ada di akreditasi Load Cell, workbook
benar.

### Yang BEDA antara kedua alat, dan ketiganya menggeser angka kalau tertukar

| | UTM | Load Cell |
|---|---|---|
| Satuan sesi master | kgf | kN |
| Desimal sertifikat | 1 | 2 |
| Kolom `Standard Value` | `Z` (sesudah koreksi termal) | `Y` (sebelum) |
| Drift standar arah Tarik | `0` | `0,04` |

Desimalnya datang dari resolusi alat (0,1 kgf lawan 0,01 kN), bukan selera:
menyamakannya membuat `2,15` runtuh jadi `2,2`. Dan sertifikat dicetak dalam
satuan ALAT sementara hitungannya hidup dalam kN — tanpa `cetakDalamSatuanAlat()`
titik 200 kgf tercetak `2,0`, meleset 100 kali lipat tanpa satu pun error.
Presedennya G19 (Flowmeter), cacat yang persis sama.

### Satu penyimpangan master yang DISENGAJA

Workbook Load Cell memakai `Y` di kolom `Standard Value` **cuma pada cabang
satuan kN**; cabang N/lbf/kgf/tnf — dan penjaga `IF(…="")` di depannya — masih
menunjuk `Z`. Satu cabang disunting, lima tertinggal: itu suntingan yang
berhenti di tengah, bukan keputusan metode. Ditiru apa adanya, angka yang
TERCETAK berubah arti tergantung satuan tampilan yang dipilih teknisi.

Sistem memakai `Y` untuk semua satuan. Sesi bersatuan kN — satu-satunya yang
pernah dijalankan lab untuk alat ini, termasuk sesi masternya sendiri — identik
dengan master. Selisihnya ditulis di **jejak audit sesi**
(`penyimpangan_master.standard_value_hanya_cabang_kn`), bukan cuma di komentar
kode, sesuai §Olah data butir 4.

### Kenapa Proving Ring ditunda, bukan dikerjakan sekalian

Tiga dari enam temuan panduan ada di sana, dan yang terbesar baru terbukti di
sesi ini: **koreksi standarnya HILANG di semua titik**. `VLOOKUP`-nya gagal di
setiap baris, `ISERROR` menggantinya dengan string kosong, dan `Y = (B + W) × G52`
membacanya sebagai nol. Kolom set point-nya pun nol — kuncinya tidak pernah
terbentuk, kemungkinan besar karena identitas standarnya `#REF!` (G4).

Itu kelas kesalahan yang §Aturan yang Lahir dari Kesalahan Nyata melarang
ditiru. Titiknya akan **diblokir dengan alasan yang kebaca**, bukan dihitung
dengan `W = 0` — dan konsekuensinya perlu diketahui lab lebih dulu: sertifikat
Proving Ring yang sudah terbit dari workbook ini angkanya tidak memuat koreksi
standar. Itu G11, dan itu yang menahan tahapnya.

### Dua kekeliruan yang ditangkap gerbangnya sendiri, sebelum sertifikat terbit

Keduanya ditemukan `GayaSesiContohCocokMasterTest` di jalan PERTAMANYA, dan
keduanya **tidak menghasilkan satu pun error**.

**1. Kolom sertifikat tertukar, dan satu di antaranya 102x terlalu besar.**
`CertificateSnapshotBuilder` mengambil kolom `Standard Value` dari `titik_ukur`
selama `nilaiStandarDariKoreksi()` masih `false`. Untuk dua puluh satu alat itu
benar — buffer pH 4,01 memang nilai acuan. Di alat gaya kebalikannya: yang
dibaca berulang justru STANDARNYA (load cell), dan `titik_ukur` menyimpan set
point dalam satuan ALAT (kgf) sementara seluruh kolom lain bersatuan kN dan ikut
dibagi faktor satuan waktu dicetak. Akibatnya `Standard Value` dan `Unit Under
Test` bertukar tempat, DAN yang mendarat di `Standard Value` dibagi 0,00981
sekali lagi: titik 100 kgf tercetak `10193,7`.

Presedennya sudah ada dan terlewat — kelompok Waktu dan Frekuensi menghadapi
bentuk yang sama persis dan itulah kenapa hook-nya dibuat. Perbaikannya:
`rata_rata` menyimpan nominal mesin dalam kN (kolom UUT), `koreksi` tetap
`Standard − UUT`, dan hook-nya dinyalakan.

**Kenapa PDF-nya tidak pernah kelihatan salah:** `SertifikatSemuaAlatSatuHalamanTest`
merender sertifikatnya, tapi yang diperiksa cuma dia muat satu halaman. Test
unit berhenti di nilai antara. Di antara keduanya ada celah selebar seluruh
tabel hasil — dan celah itu yang sekarang ditutup.

**2. Sesi contoh UTM titik 300 kgf kehilangan satu pembacaan.** Master punya DUA
sel bernilai `300,2` (ke-4 dan ke-9 di deret `C:Q`); seeder menulis satu.
Rata-ratanya meleset 0,0084 kgf — pada satu desimal tetap tercetak `300,7`,
jadi angka CETAKNYA sama dan pemeriksaan yang berhenti di pembulatan akan
meluluskannya. Yang menangkapnya justru asersi nilai PENUH pada toleransi
5x10⁻⁶.

Penjaga ketiganya yang paling murah dan paling tajam: `Correction` wajib sama
dengan `Standard Value − UUT`. Itu berlaku di ketiga workbook
(`SERTIFIKAT!Q = E − L`), dan dia yang membuat pertukaran kolom ketahuan
walaupun kedua angkanya kelihatan wajar — di titik 100 kN bedanya cuma 0,13 %.

Nol kolom baru. Pertanyaan lab: `docs/pertanyaan-lab-gaya.md` (12, satu sudah
terjawab sendiri). Serah-terima HP: `docs/perintah-frontend-gaya.md`.

---

### Sesudah §37 mendarat: empat hal yang ketahuan karena gerbangnya dijalankan penuh

Ditulis terpisah karena keempatnya ditemukan SESUDAH commit pertama naik, dan
tiga di antaranya tidak akan pernah muncul dari membaca kode.

**1. HP belum bisa menyimpan lembar gaya sama sekali.**
`CalibrationController::susunPengukuran()` punya sebelas cabang alat dan nol
untuk gaya. Sesi contoh kedua alat lahir dari seeder yang menulis
`raw_measurements` LANGSUNG, jadi seluruh rantai hitung terbukti benar — sel
demi sel, sampai kolom sertifikat — sementara **pintu masuknya tidak ada**.
Fitur yang kelihatan selesai dari semua sudut kecuali yang dipakai orang.

Bentuknya sekarang: hook `butuhBlokGaya()` + `susunBlokGaya()` yang menyimpan
keempat deret posisi ke `peran_sensor` masing-masing. Yang digabung cuma
BUDGET-nya (dua belas bacaan jadi satu deret); penyimpanannya tetap terpisah,
karena lembar yang dibuka ulang harus tahu angka mana milik kotak mana — tanpa
itu teknisi yang mengoreksi satu bacaan mengoreksi kotak yang salah, tanpa
error. Dijaga `GayaSesiSimpanTest` (5 kasus, lewat HTTP).

**2. `spesifikasi_alat.gaya` belum terdaftar sebagai blok objek**, jadi kiriman
HP jatuh ke penjaga "harus teks, bukan objek" dan SELURUH sesi ditolak 422.
Kasus yang sama persis dengan Volumetric Glassware dan Anak Timbangan
sebelumnya — ketiga kalinya pola ini terulang.

**3. Koma desimal blok sesi tidak dinormalisasi.** `(float) "8,237"` di PHP
bukan galat melainkan **8.0**. Misalignment yang mendarat sebagai 8 meruntuhkan
simpangan bakunya dan MENGECILKAN U95 yang tercetak — arah yang salah, tanpa
satu pun gejala. Panduan §8.4 memintanya eksplisit dan itu terlewat.

**4. Aturan validasi §8.1/§8.2 dipasang** — 5 pemblokir, 8 peringatan.

Satu di antaranya SENGAJA menyimpang dari panduan: "nominal harus naik monoton"
didaftarkan panduan sebagai **pemblokir**, tapi sesi master Load Cell sendiri
urutannya `0 → 100 → 2 → 3 … 9 kN`. Ditegakkan sebagai pemblokir, lembar yang
benar-benar dipakai lab tidak bisa dikirim. Diturunkan jadi peringatan dan
diangkat sebagai pertanyaan lab G13.

Dan satu cacat implementasi yang ditangkap testnya sendiri: detektor pencilan
MAD **mati** persis pada bentuk data gaya yang paling normal. Sembilan bacaan
identik + tiga beda membuat median simpangannya NOL, jadi pembaginya nol dan
detektornya berhenti — justru di bentuk data yang paling sering muncul.
Diperbaiki dengan mengambil skala dari simpangan yang bukan nol; satu kasus
yang tetap tidak tertangkap (satu bacaan nyasar di antara sebelas yang identik)
ditulis terang di kodenya dan ditangkap RRPE di tingkat titik.

**Gerbang dua-suite akhirnya utuh.** MySQL lokal disetel 24 Sep 2026 (user
`sidik_test`, hak dikunci ke `asmo_db_test` saja), dan jalan pertamanya langsung
menemukan 9 test merah yang **tidak pernah terlihat di SQLite**: `AUTO_INCREMENT`
MySQL tidak ikut di-rollback antar test, jadi organisasi buatan `setUp()` dapat
id 2, 3, … sementara `PerintahAkunSuperAdminTest` dan `GantiSandiSendiriTest`
memaku id 1. Keduanya lahir bersama commit super admin 24 Sep dan tidak pernah
diuji di MySQL. Yang salah penjaganya, bukan kodenya.

### §37b — Alat ke-42: **Proving Ring**, dan tiga koreksi atas temuan sendiri (25 Sep 2026)

Kelompok Gaya lengkap. Proving Ring sengaja dikerjakan terakhir karena tiga dari
enam temuan panduan ada di sana — dan tiga di antaranya, sesudah diperiksa sel
demi sel, **ternyata salah arah**. Ditulis lengkap di sini karena ketiganya
pernah saya sampaikan sebagai fakta.

#### Rantainya paling tidak sebangun dua alat gaya lain

| | UTM / Load Cell | Proving Ring |
|---|---|---|
| Yang dibaca | gaya (kgf / kN) | **jumlah DIVISI dial** |
| Bacaan per titik | 12 (4 posisi × 3) | **6 (UP 3× + DOWN 3×)** |
| Keluaran sertifikat | Correction | **Calibration Factor (kgf/Div)** |
| Kolom sebaran | RRPE (dibagi beban) | **Repeatability (dibagi rata-rata bacaan)** |
| Koreksi suhu | termal ke suhu sertifikat | **faktor ruangan, acuan 23 °C** |
| Komponen budget dijumlahkan | delapan | **enam** |
| Divisor pengulangan | akar 12 | **akar 6** |
| Daya baca alat | resolusi gaya / rentang | **resolusi DIAL / kapasitas dial (mm)** |

Dibuktikan sebelum PHP, lalu diadu ulang di PHP: `J`, `K`, `L` cocok master di
kesepuluh titik pada 5×10⁻⁶, dan seluruh budget — `u_c` 0,2168694867,
`v_eff` 102,52446920, `k` 1,9834952586, `U95%` 0,4301595985 — cocok persis.
`ProvingRingMasterTest` (11 test / 90 asersi).

#### Tiga koreksi atas temuan yang sudah saya sampaikan

**G11 — saya nyatakan terlalu jauh.** Semula: "koreksi standar HILANG di semua
titik, ditelan `ISERROR`", dengan kesimpulan sertifikat yang sudah terbit
kehilangan koreksinya. Kenyataannya: dengan standar yang disebut workbook
(3000 kN), pencarian yang BENAR pun memulangkan nol — baris tabel di bawah
300 kN cuma baris nol. `ISERROR` **menyembunyikan**, bukan menyebabkan.

Masalah sebenarnya lebih dalam: alat **500 kgf** dikalibrasi dengan standar yang
titik terkalibrasi terendahnya **300 kN**, 61× kapasitasnya. Tabel 5 kN justru
pas menutupinya.

**G6 — "koreksi suhu ganda" ganda di rumus, TUNGGAL di hasil.** Lembar Proving
Ring tidak punya field *Actual Temperature of Standard* yang dipunyai lembar UTM,
jadi pengurangnya nol dan `Z` sama persis dengan `Y` di kesembilan barisnya.

**G8 — dari dugaan jadi terbukti.** Jumlah master cocok NOL BEDA dengan enam
komponen pertama; selisih terhadap delapan persis suku misalignment.

#### Satu temuan BARU, dan yang paling material

**G14 — pita CMC terbaca dari baris yang SALAH, sekitar 10×.** Lampiran
akreditasi memuat Proving Ring `0–500 kgf → 2,3 kgf`; budget master memakai
`0,22 kN`, yaitu baris `10–88 kN` yang **tidak mencakup** alat 500 kgf. Yang
benar 0,022563 kN.

Lantai CMC repo ini memilih pita berdasarkan kapasitas alat, jadi dia memakai
yang benar tanpa disetel — dan itu berarti U95 kita **~10× lebih kecil** dari
yang tercetak di sertifikat yang sudah terbit. Mengaku ketidakpastian yang lebih
KECIL dari yang pernah diterbitkan bukan keputusan yang boleh diambil di kode.

#### Dua celah bersama yang ikut tertutup

**1. Penjaga rentang tabel terlalu sempit.** `di_luar_rentang` cuma menangkap
yang melewati ujung tabel. Titik 0,29 kN yang mengambil koreksi dari baris 0 kN
sementara baris berikutnya 300 kN **ada di dalam** [0, 3000] — lolos tanpa suara.
Sekarang jaraknya diukur, dan yang meleset lebih dari 10 % dari bebannya jadi
temuan. Berlaku untuk ketiga alat gaya.

**2. Kolom sebaran tidak pernah ada di sertifikat.** Template cuma mencetak
`Standard | UUT | Correction | U95%`, padahal ketiga master punya kolom keempat
— dan dokumen serah-terima yang saya tulis 24 Sep terlanjur menjanjikannya.
Sekarang jadi kolom opsional: `RRPE` untuk UTM & Load Cell, `Repeatability`
untuk Proving Ring. Ikut lahir: judul kolom ketiga yang bisa diganti
(`Calibration Factor`), desimal khusus kolom ketiga (lima, bukan dua — faktor
0,126 kgf/Div runtuh jadi `0,13` di dua desimal), dan penanda bahwa kolom UUT
Proving Ring **tidak** ikut konversi satuan karena isinya divisi, bukan gaya.

Nol kolom baru di database. Pertanyaan lab: `docs/pertanyaan-lab-gaya.md` (14).

### §37c — Lembar Gaya & Timbangan dari HP: yang terkirim bukan yang diketik (25 Sep 2026)

Laporan pemilik proyek ("masih pada ngebug, apalagi 3 alat Gaya") diadu ke
bentuk kiriman HP yang SEBENARNYA — bukan payload rapi buatan test. Empat cacat,
semuanya tanpa error:

1. **Tabel Preload menghapus blok Gaya.** `simpan_ke` tabelnya
   `spesifikasi_alat.gaya`, dan HP menanam tabel dengan menimpa kunci tujuannya
   utuh (`{baris: …}`). Satuan, standar, kapasitas, dan misalignment ketiga alat
   hilang di tiap kiriman. Sekarang `spesifikasi_alat.gaya.preload`, dan
   `CalibrationRequest::bakukanBlokGaya()` menerjemahkan barisnya ke
   `preload_zero`/`preload_max`. Tanpa terjemahan itu preload bentuk HP
   diabaikan dan U95 Load Cell terbit **lebih kecil**: 0,29761 lawan 0,29858.
2. **Bacaan UP/DOWN Proving Ring tidak pernah dibaca jalur simpan.**
   `susunBlokGaya()` memutar `PERAN_POSISI` untuk ketiga alat, jadi sesinya
   tersimpan 201 tanpa satu bacaan pun. Sekarang deretnya milik profil
   (`GayaProfile::peranBacaan()`), dan `gaya_up`/`gaya_down` punya aturan
   validasi.
3. **Beban keterulangan Timbangan yang diketik hilang.** Diketik 40/90, yang
   tersimpan 50/100 bawaan kapasitas — penyebabnya sama dengan butir 1. Tabelnya
   pindah ke `spesifikasi_alat.keterulangan.tabel`, dan beban yang diketik menang.
4. **Deret bernama Flowmeter tanpa aturan validasi sama sekali.** Diberi aturan
   longgar (cuma "harus deret") karena Flowrate mengirim deret bersarang.

Penjaganya `KontrakLembarSemuaAlatTest`, yang menyapu SEMUA profil dari
registry — bukan daftar tangan. Dia memeriksa dua hal: tabel tidak boleh
`simpan_ke` ke kunci yang juga memuat isian lain, dan tiap deret bernama wajib
punya aturan validasi. Sebelum perbaikan dia menandai Timbangan, UTM, Load Cell,
Proving Ring, dan Flowmeter. Ditambah `GayaDariHpTest` (payload berbentuk HP,
U95 diadu ke payload baku) dan satu kasus di `TimbanganSesiTest`. HP membaca
`simpan_ke` dari server, jadi perbaikannya ikut naik bersama server; mock & test
mobile menyusul di PR mobile.

### §37d — Semua lembar kerja jadi dua halaman (26 Sep 2026)

Permintaan pemilik proyek 25 Sep ("jadi 2 aja lebih rapih"), disetujui bersama
§37c tapi tertinggal di belakang pilot OCR — sampai pemilik proyek memasang APK
terbaru dan melihat lembarnya masih satu gulungan. Sensus: cuma ketiga lembar
Gaya yang dua halaman; 39 lainnya satu.

Satu aturan, `CalibrationProfile::susunDuaHalaman()`, dipasang di endpoint
`GET /calibrations/lembar-kerja` dan di generator mock HP — bukan di 39
`bentukLembarKerja()` (186 titik sunting). Halaman 1 = sampai `usage_check`
plus bagian persiapan yang langsung menyusul (tanpa tabel, tanpa isian angka:
lokasi, ruang, metode, tipe sensor); halaman 2 = mulai bagian pengukuran pertama
sampai penutup. Diturunkan dari isi bagian, jadi profil baru ikut tanpa disentuh;
lembar Gaya yang menyusun halamannya sendiri tidak diubah. Membalik keputusan
"satu gulungan" `3ab1d09` untuk pH — atas permintaan pemilik proyek. Kertas
cetak, OCR, dan sertifikat tidak membaca `halaman`.

Ikut ketemu: generator mock membaca database `.env` (produksi), jadi
`standard_id` mock ikut keadaan produksi hari itu. Sekarang generator selalu
memakai SQLite in-memory yang dimigrasi & di-seed, dan berhenti kalau koneksinya
bukan itu — tujuh dari sembilan mock yang sudah ter-commit terbukti byte-identik
dengan keluarannya. Di HP, grid sensor Enclosure pindah ke bagian pertama
halaman terakhir supaya ikut halaman pengukuran.

## Gelombang & status

Urutannya ditentukan berkas yang bertabrakan, bukan selera — G1 dan G3 sama-sama menyentuh 12
berkas profil.

| Gel. | Isi | Status |
|---|---|---|
| G0 | Sertifikat Insitu, draf tanpa tanggal, ruangan ke-16, cabut UI pindai (perm. 3) | **TERKIRIM** — di `main`, ada di APK **v1.0.42** |
| G1 | Profil dari server (perm. 1a) + lokasi Inlab/Insitu (perm. 2) | **TERKIRIM** (v1.0.42) — perm. 2 jalan di **17/17** profil, dijaga `SemuaProfilLembarKerjaTest` (88 test = 17×5 aturan per-profil + 3 aturan lintas-profil) |
| G2 | Kelola daftar alat (perm. 1b) + layar Draf (perm. 4) | 1b jalan; perm. 4 **TERKIRIM** (v1.0.42). K10/K11 masih menahan pintu masuk & tombol hapus |
| G3 | Lembar kerja ikut PDF (perm. 6) | **sebagian TERKIRIM** (v1.0.42) — TITS `0505 Rev.3` & Enclosure `0504 Rev.3` (kepala lembar, `equipment_id`, blok dimensi + volume, nomor formulir, baris Suhu Ruang) sudah ikut PDF. **TIDS `0506 Rev.4` belum dibandingkan field-per-field** |
| G6 | Kolom "Environmental Meter Used" hidup di **17/17** lembar | **BERES di server** (25 Agt 2026) — TITS, TIDS & kelima Enclosure dropdown-nya nggak pernah diisi siapa pun, dan TIDS jalur cadangannya (`baris_thermohygro`) juga mati. Dijaga `ThermohygroSemuaLembarTest` + penjaga golongan sumber master. **Sisi HP menyusul** (27 Agt 2026, mobile#114): `thermohygro_dropdown_hidup_test.dart` menjaga dua arah — ada isi → dropdown kegambar, kosong → pesannya. Cakupannya 12 profil, yaitu yang bentuknya beneran dimodelkan mock; lima sisanya jatuh ke bentuk pH di sana, jadi klaim 17/17 tetap di sisi server. Yang ketangkap waktu penjaganya dipasang: bentuk mock Spectro, Visco & Gas punya kolomnya tapi daftar pilihannya KOSONG — di mode mock ketiga lembar itu memajang "belum ada unit" padahal server ngirim tujuh |
| G4 | TIDS (perm. 5) | **BERES di server** (28 Agt 2026) — dua workbook master turun, `TidsCalculator` lahir, dan angkanya cocok sampai digit terakhir dengan dua sesi contoh (`TidsMasterTest`). Lembarnya pindah ke jalur PASANGAN standar/UUT, jadi tabel Pembacaan Standard akhirnya punya tempat simpan. **Sisi mobile juga BERES** — bentuknya data-driven (`tabel[].peran`), plus bentuk mock TIDS, fixture dari server, 9 test, dan satu bug `titikUkur` vs `titikUkurEfektif` yang ketangkap gara-gara itu |
| G5 | Scan Tabel (perm. 7) — **perm. 3 DIBATALKAN oleh S1, UI pindai nyala lagi** | **S1/S2/S3 semuanya sudah dijawab**, dan kodenya sudah mendarat. Peta: `docs/peta-permintaan-7-scan-tabel.md`. Sebagian besar spec memang SUDAH terbangun sebelum permintaan 7 ditulis (`worksheet_scans`, pipeline 7 tahap, ML Kit, layar review). Yang ditambah: 9 berkas geometri baru (jadi **17/17**), gerbang bentuk kertas buat jalur foto AI, dan alasan pindai jadi kalimat. **Sisa satu-satunya: F1** — nunggu satu foto, bukan nunggu kode. **26 Sep 2026: pilot geometri formulir ASLI pH** (§5 panduan langkah 1–4) — draf dari vektor PDF `SIDIK-FM-CAL-0509_Rev.4` + peta tulisan manusia di `database/ocr-templates/asli/`, dirujuk lewat titik tengah; 60 sel tabel, 13 isian (termasuk 4 Env.), 9 centang. **Belum dipakai memindai**: `TemplateLembarKerja` tidak memuatnya, `ph_meter-v1.json` dikunci hash. Dijaga `GeometriFormulirAsliPhTest`. Langkah 5 (≥20 foto nyata) belum; dua pertanyaan baru di `docs/PANDUAN-OCR-LEMBAR-KERJA.md` §7 butir 7–8 |
| G8 | Alat baru **Timbangan** (perm. 14) — kelompok Massa, alat ke-21 | **BERES di server** (31 Agt 2026) — satu profil, tiga varian master (kg / gram / substitusi), dua budget U95 per titik ikut NMI Monograph 4. Angkanya cocok sampai digit terakhir dengan ketiga workbook: **1.099 angka** diadu `TimbanganMasterTest` (tiap `ui×ci`, tiap `vi`, `uc`, `veff`, `k`, `U`, `U95`), plus `TimbanganCmcCocokAkreditasiTest` yang mengadu 17 pita CMC ke lampiran akreditasi. Sepuluh pertanyaan lab di `docs/pertanyaan-lab-timbangan.md` — yang terbesar T1 (tiga snapshot sertifikat anak timbangan buat keping fisik yang sama) dan T2 (`ui` U-of-Correction: tiga perlakuan, selisih hampir 2×). **Sisi mobile BERES** (31 Agt 2026): lembarnya kegambar & payloadnya sampai, 13 test baru. Lima cacat SUNYI ketemu waktu disambungkan — 39 kotak yang read-only tanpa sadar, blok bersarang yang dibaca nol, `peran` yang membelokkan seluruh lembar ke jalur pasangan, kunci baris yang bentrok antar tabel, dan pengatur titik yang dipakai bersama; rinciannya di §14 E. Jalur kamera per TABEL nyala di blok Keterulangan saja (§14 F). **Sertifikatnya juga BERES** (31 Agt 2026): delapan bagian master (Repeatability · Effect of Tare · Accuracy · Loading Influence · Hysterisis · Limit of Performance · Weighing Uncertainty · Standard Used) dicetak lewat `snapshot['timbangan']` + cabang blade, ikut preseden Autoklaf; sebelumnya tujuh dari delapan bagian hilang diam-diam di tabel empat kolom generik. Dijaga `TimbanganSertifikatTest` (13 test) — angkanya diadu ke sel master DAN ke HTML yang dirender, plus penjaga satu halaman. Satu cacat SUNYI ketemu di situ: kolom `Correction` varian substitusi menyimpan `ΔI`, bukan kumulatif `Cn` yang dicetak master — titik terakhir terbit 1,4559 kg untuk lembar yang masternya menulis 13,309 kg |
| G7 | Tiga alat suhu baru (perm. 10) — Thermocouple, Termometer Gelas, Thermohygrometer | **BERES di server** (26 Agt 2026) — profil + olah data + geometri OCR + CSV. Angkanya cocok sama ketiga workbook master sampai digit terakhir; dijaga `Suhu3AlatMasterTest` (15 test) & `Suhu3AlatLembarKerjaTest` (14 test). **Sisi mobile BERES** (26–27 Agt 2026): layar lembar kerja tabel pasangan (mobile#108), golden ketiga lembar + generator golden tanpa Mac (mobile#111), dua deret pembacaan dipecah di layar detail (mobile#112), dan tiga field sesi (`alat_bantu`, `tipe_pencelupan`, `titik_es`) kebaca admin (api#111 + mobile#113). Nama alat bantu diresolusi SERVER lewat `CalibrationProfile::labelAlatBantu()` — kodenya (`A`/`satu`) cuma punya arti di daftar `pilihan` milik profilnya, jadi peta kode→nama JANGAN disalin ke HP |
| G9 | Alat baru **kelompok Waktu dan Frekuensi** (perm. 15) — Timer/Stopwatch, Centrifuge, Infrared Tachometer; alat ke-22..24 | **BERES di server** (1 Sep 2026) — dua mesin hitung untuk tiga alat, nol kolom baru di `raw_measurements`, dan lampiran akreditasi kelompok "Waktu dan Frekuensi" jadi LENGKAP. Rumusnya dibuktikan di Python SEBELUM PHP ditulis: **464 nilai** diadu sel demi sel ke ketiga workbook pada 5·10⁻⁶, dan setiap selisih punya penjelasan. Dijaga `WaktuFrekuensiMasterTest` (16 test, 402 asersi) yang mengadu tiap kolom turunan DAN tiap komponen budget, bukan cuma U95 akhirnya. Empat kerusakan master dihitung benar (arahnya ditegakkan test: kita wajib lebih BESAR) dan lima titik hantu diblokir. Tiga belas pertanyaan lab di `docs/pertanyaan-lab-waktu-frekuensi.md`; §4/§5/§7/§11 **ditutup 1 Sep 2026** oleh arahan pemilik proyek "pakai rumus Excel", menyisakan §8/§9 dan dua yang menyangkut dokumen terbit (§10 tanda koreksi, §13 kalimat `k`) plus satu permintaan data (workbook Timer yang keempat bloknya hidup). **Sisi mobile BERES** (1 Sep 2026, PR mobile #139) — ketiga lembar bisa diisi & dikirim dari HP tanpa layar baru; menyambungkannya membongkar tiga cacat lama yang gagal tanpa error: lembar Thermohygro terkirim KOSONG, tombol FOTO TABEL INI mengisi nol sel di lima lembar berpasangan, dan kolom U95 memakai desimal kolom hasil. Jalur kamera cloud tetap MATI sampai kertas ber-nomor `SIDIK-FM-` turun |
| G10 | Data pelanggan — nama PT & alamat (perm. 16) | **A BERES di server** (2 Sep 2026) — `customers:impor` mendarat dengan **43 test** (17 perintah + 15 pembaca CSV + 11 pemilah kembar), nol kolom baru dan nol dependensi baru. Rangka direktorinya ternyata **sudah lengkap server→HP** sejak sebelumnya; yang kurang isinya. Enam jebakan sunyi dikunci test — pemisah `;` Excel lokal ID, `levenshtein()` yang balik −1 di atas 255 byte, `PT`/`CV` yang jaraknya cuma 2, soft delete yang tetap memegang unique index, telepon yang jadi `8.12E+11`, dan riwayat audit tanpa penanggung jawab. **B menunggu keputusan biaya** (membatalkan K16, nol kode). **C & D belum** — nunggu A dipakai dengan data sungguhan. Daftar PT nasional **tidak bisa disediakan**: AHU punya datanya tanpa API, Places/OSM punya API tapi alamat peta bukan alamat akta — rinciannya §16 B  **Ditambah 2 Sep 2026: direktori lokal** — 10.320 PT (Jababeka 450 + Indonetwork 9.870) bisa dicari ±10 ms tanpa keluar server, lewat tabel rujukan terpisah `direktori_lokal` dan driver baru yang memenuhi kontrak `DirektoriPerusahaan` yang sudah ada. **Nol berkas berubah di sisi HP, nol tambahan ukuran APK.** Menyeed ke `customers` sengaja DITOLAK: `SimpananPelanggan` menyalin seluruh daftar pelanggan ke SharedPreferences yang dibaca utuh ke memori tiap aplikasi nyala — diukur **1,36 MB JSON** per buka aplikasi. Satu bug ketemu & dikunci test: `tersedia()` di service provider bikin **`/api/health` 500** waktu tabelnya belum ada. 22 test baru. Rinciannya §16 F |
| G11 | Alat baru **Micrometer** (Panjang, lampiran no. 34) — §17 | **BERES di server** (4 Sep 2026) — empat workbook master jadi SATU profil empat pita CMC; 53 nilai diadu ke keempat master pada 5·10⁻⁶, nol beda. Nol kolom baru di `raw_measurements`. Dua temuan yang mengubah angka tercetak (U95 terbit di bawah lantai CMC, umur drift dari `NOW()`) ditambal + diangkat jadi pertanyaan lab bernomor. Lembar lalu **disetel ulang ke kertas resmi** `SIDIK-FM-CAL-0522.{A,B,C,D}_Rev.1` yang turun belakangan: nomor formulir per rentang, 6 bagian, 11 nominal pra-cetak, suhu balok/UUT diturunkan dari suhu ruangan. **Sisi HP BERES** juga (§19) — dan justru dari situ tiga cacat server ketahuan, ketiganya lolos 3.128 test backend karena test backend memakai payload yang ditulis backend sendiri. **Sapuan lanjutan (§21):** seeder ternyata cuma menanam SATU dari empat rentang; varian C & D sekarang ikut, varian A tetap tidak (pra-evaluasinya 635,0 sepuluh kali → simpangan baku nol). **§22:** stdev nol itu ternyata juga lolos gerbang penerbitan untuk sesi BARU — sekarang ditahan, plus `micrometer:audit-cmc` buat melingkupi arsip dan formulir keputusan siap teken |
| G13 | Alat baru **Height Gauge 600 mm** (Panjang, DI LUAR lampiran akreditasi) — §23 | **BERES di server** (7 Sep 2026) — alat ke-26, satu workbook master, **nol kolom baru** di `raw_measurements`. Rumusnya dibuktikan di Python SEBELUM PHP ditulis: kesepuluh koreksi, kesembilan `ui`/`ci`/`vi`, dan kelima agregat cocok pada 5·10⁻⁶ — nol beda. Dijaga `HeightGaugeMasterTest` (17 test / 87 asersi). Bentuknya paling tidak biasa dari 26: **tiga blok yang tidak sebangun**, dan cuma satu yang berbentuk titik ukur. Yang membalik taruhannya — alat ini **tidak punya lantai CMC** (di luar LK-285-IDN, dan sel lantai masternya memang kosong), jadi komponen budget yang hilang tidak tertampung apa pun; gerbang penerbitannya dipatok tiga syarat dan yang menahan ketiadaan baris hitungan, bukan peringatan sesi. Tiga kejanggalan metode ditiru + diangkat (`/12` untuk selisih HARI, `√6` pada komponen `rect.`, paralelisme `STDEV(Max;Min)`), tiga kerusakan dihitung benar (suku termal yang di master cuma hidup di titik pertama, umur drift dari `NOW()`, rujukan sel `L27` yang meleset). Satu bug SUNYI ketemu waktu test ditulis: gerbang "sepuluh nilai identik" yang ditulis `stdev > 0` **tidak pernah menyala** untuk nilai yang tidak bisa direpresentasikan persis dalam biner (599,95 → stdev 1,2e-13) — diganti `max !== min`. Sepuluh pertanyaan lab di `docs/pertanyaan-lab-height-gauge.md`; §6 **prioritas satu** (sertifikat masih membawa klaim akreditasi untuk lingkup yang tidak diakreditasi — Gas Detector pun sudah begitu sejak alat ke-10). **Sisi mobile BERES** (7 Sep 2026) — `height_gauge_lembar_test.dart` ada dan hijau, dan `docs/perintah-frontend-height-gauge.md` sendiri sudah berstatus "SUDAH DIKERJAKAN". Baris ini tertinggal menulis "BELUM" sampai 9 Sep 2026: tabel Gelombang yang berkata sebaliknya dari badan dokumen mengembalikan persis masalah yang bikin dokumen ini ada, cuma arah kebalikannya |
| G14 | Alat baru **Flowmeter Ultrasonic** (Aliran, lampiran no. 30 & 31) — §25 | **BERES di server** (8 Sep 2026) — alat ke-27 & ke-28, dua workbook master jadi DUA profil + satu mesin hitung, **nol kolom baru** di `raw_measurements`. Kelompok Aliran sekarang LENGKAP. Rumusnya dibuktikan di Python SEBELUM PHP ditulis: tiap kolom turunan, tiap `u`/`ci`/`vi`, `uc`, `veff`, `k`, `U` keempat blok titik kedua workbook cocok pada 5·10⁻⁶ — nol beda. Dijaga `FlowmeterMasterTest` (9 test / 213 asersi). Bentuknya paling berbahaya dari 28: **satu titik punya DUA deret berdampingan** (UUT + totalizer standar), dan pada Flowrate deret UUT-nya **bersarang** tiga durasi per ulangan — tertukar atau tertimpa, yang terbit bukan error melainkan **deviasi nol di setiap titik**. Tiga kerusakan master dihitung benar dan arahnya ditegakkan test: lantai CMC yang hilang (**sertifikat lab sudah terbit 1,0466 % pada pita terakreditasi 1,2 %** → 3,2512 naik ke **3,7277 Lpm**), rentang densitas Totalizer yang melenceng satu kolom ke titik 3 (deviasi −18,9072 → **−18,8907 L**), dan `Ut-water` yang menunjuk sel kosong (`U_temperature` 0,27803 → **0,27952 °C**). Dua workbook ternyata **dua generasi budget** (8 vs 9 komponen) — ditiru masing-masing, bukan diseragamkan. Dua cacat SUNYI ketemu waktu test ditulis: tanda kolom `Correction` terbalik, dan resolusi satuan massa yang dikonversi tanpa densitas sehingga seluruh sesi `kg/min` ditolak. Sertifikatnya dapat blok **PIPE SPECIFICATION & SENSOR MOUNTING** yang di master ada labelnya tapi sel isinya kosong, plus `k` per titik. Tujuh belas pertanyaan lab di `docs/pertanyaan-lab-flowmeter.md`; **§1 prioritas satu** (kedua master kolom VALIDATION-nya KOSONG) dan **§16** (lampiran menyebut *static weighing method*, yang dikerjakan perbandingan langsung dengan UFM). **Sisi mobile BERES** (9 Sep 2026) — `dart analyze` bersih, `flutter test` 1627/1627; bentuk contohnya DIGENERATE dari respons server, bukan disusun tangan. Kontraknya di `docs/perintah-frontend-flowmeter.md`. **Plus satu penjaga yang bukan milik alat ini:** `bentuk_mock_semua_profil_test.dart` menyapu daftar kode profil yang digenerate registry server (`docs/skrip/gen-kode-profil-mobile.php`) dan menuntut tiap kode punya bentuk mock-nya sendiri. Waktu dipasang dia menemukan **tujuh** profil yang selama ini diam-diam memajang lembar pH di mode mock — `autoclave`, `conductivity_meter`, dan kelima Enclosure (lembar GRID 9 termokopel, yang bentuk pH-nya nggak punya satu pun kotak yang cocok). Ketujuhnya terdaftar sebagai UTANG berikut akibatnya, dan penjaganya menggigit dua arah: kode baru tanpa cabang merah, dan entri utang yang sudah lunas wajib dicabut. **Lima di antaranya — kelima Enclosure — DILUNASI hari yang sama** (9 Sep 2026): bentuknya digenerate dari server ke `contoh_lembar_kerja_enclosure.dart` (2.824 baris, 5 profil), dan generator contohnya sekalian disatukan jadi `gen-contoh-lembar-kerja.php` supaya emitter Dart-nya nggak digandakan per kelompok — pola yang §18 sudah cabut sekali. `conductivity_meter` menyusul lunas hari itu juga (`contoh_lembar_kerja_analitik.dart`) — dan sambil melunasinya ketahuan bahwa alasan utang yang pertama ditulis KELIRU: dia disebut "divonis PASS/FAIL", padahal `punyaToleransi()`-nya `false` dan `kontrak-api.md` sudah menempatkannya di kelompok yang berhenti di `U95%`. `autoclave` — utang terakhir — ikut lunas hari itu juga (`contoh_lembar_kerja_autoclave.dart`), jadi **ketujuh utangnya NOL**. Petanya sengaja dibiarkan ada walau kosong: dia tempat utang berikutnya mendarat, dan ketiga test menggantung padanya. Dihapus, profil ke-29 yang belum punya bentuk mock nggak punya jalan mendarat selain bikin sapuannya merah tanpa tempat mencatat alasannya — dan yang biasanya terjadi berikutnya bukan bentuk mock-nya dibuat, tapi sapuannya dilonggarkan. Itu jawaban atas pola yang berulang di dokumen ini — penjaga yang daftarnya diambil dari registry bertahan, yang ditulis tangan selalu ketinggalan |
| G12 | Angkat helper profil terduplikasi ke kelas induk — §18 | **BERES** (4 Sep 2026) — 37 salinan jadi 6; lapisan profil menyusut 1.109 baris. Dua override dipertahankan karena menyimpang bersebab (Tids konstantanya berarti lain, Spectro urutan kuncinya beda), masing-masing dengan komentar WHY. Perilaku tidak berubah — dijaga sapuan lembar kerja & thermohygro yang menyapu SEMUA profil |
| G15 | **Audit seluruh 33 master di `alat-alat-Pt-Sidik` lawan yang sudah dibangun** — §26 | **BERES** (9 Sep 2026) — enam dimensi disapu: nomor metode ke-33 master, pita CMC, nomor formulir, cakupan profil, varian per alat, dan apakah tiap snapshot punya test yang mengadu angkanya. **Nol kode produksi berubah** — dan itu hasilnya, bukan kemalasan. **Klaim pertama audit ini SALAH dan dicabut hari yang sama:** sempat disimpulkan "pH satu-satunya alat yang masternya tidak pernah diadu". Tidak benar — `UncertaintyBudgetTest` sudah mengadu KEDUA lembar pH ke workbook aslinya sejak lama, dan docblock-nya sudah membedakan kedua termometernya dengan benar. Sapuan yang melewatkannya cacat sendiri: `grep -lі` yang diketik memakai huruf `і` Kiril, jadi diam-diam memulangkan nol berkas. **Pelajarannya bukan soal pH** — sapuan yang memulangkan "tidak ada" wajib dibuktikan dulu bisa memulangkan "ada", karena hasil nol dari perintah yang rusak kelihatan persis seperti temuan. Yang TERSISA sebagai celah nyata dan ditutup `PhMeterMasterTest` (4 test / 36 asersi) cuma dua: `k` eksak + `U` lembar IMTE-WQ-129 (di sana `k` cuma dipatok ±5e-3 dan `U` tidak diperiksa sama sekali), dan lantai CMC dua arah — lembar lama WAJIB tetap menembus CMC di pH 4 & pH 7, lembar baru WAJIB tetap ketutup di tiga. Yang tetap berdiri dari temuan awal: termometer standarnya memang beda benda (U95 0,5 lawan 0,72 °C) dan `ci_suhu`/`u_perbedaan_suhu`/`ci_perbedaan_suhu` IDENTIK di kedua workbook, jadi cuma `UTemperature` yang ikut perangkat. Konstantanya sengaja **tidak** ditukar (K28). Sapuan lanjutan: ketujuh `*CapabilitySeeder` ber-`u_temperature` diadu ke kepala sheet masternya — **ketujuhnya cocok**, jadi kelas cacat ini tidak menyebar ke alat lain. Yang dikonfirmasi BENAR dan dibiarkan: Flowmeter `0528_Rev.4` (profil ikut lampiran akreditasi, master sudah Rev.6 yang belum diakreditasi — §16 pertanyaan flowmeter), Thermocouple `0529_Rev.2` (master men-VLOOKUP indeks 2 → metode TITS `0502_Rev.3`; sudah tercatat `pertanyaan-lab-suhu-3alat.md` §1), Timbangan `0505-Rev.7` yang bertanda hubung sendirian di antara 30+ IK ber-garis bawah, dan `SIDIK-FM-CAL-2403_Rev. 0` yang muncul di banyak master karena dia formulir SERTIFIKAT bersama, bukan lembar kerja. Keempat tabel CMC master yang eksplisit (Conductivity, Refractometer, Turbidimeter, pH) cocok persis dengan lampiran LK-285-IDN |
| G16 | **Data pelanggan disapu + riwayat git ditulis ulang** — §27 & K27 | **BERES** (10 Sep 2026) — dua pekerjaan yang saling mengunci. **(1) Sanitasi:** 81 berkas, commit `c0645f6`, empat gelombang — dan tiap gelombang menemukan yang tidak terlihat dari sebelumnya, termasuk **enam nama pelanggan yang tidak ada di daftar `.gitignore`** (jadi daftar itu sendiri tidak lengkap) dan `Puskesad` yang dieja panjang. Celah yang bisa membatalkan semuanya dalam sekali jalan ikut ditutup: dua generator menyalin sel identitas pelanggan langsung ke `database/data/*.json` yang ter-commit. **(2) Rewrite:** 686 commit, 91 branch. Jejaknya empat bentuk, bukan satu — 440 trailer `Co-Authored-By`, 164 `Claude-Session`, **143 commit ber-AUTHOR Claude** (ini yang menentukan daftar Contributors, dan `--message-callback` tidak menyentuhnya), 137 deskripsi PR (tidak ada di git sama sekali), dan 37 branch `claude/*` (yang paling kelihatan). Gerbang sebelum push: tree `main` sebelum/sesudah diadu dan **identik byte-per-byte** — nol byte kode berubah. Branch **diganti nama, bukan dihapus**, karena 32 dari 37 bukan leluhur `main`. Tag ikut dipindah — tanpa itu seluruh riwayat lama tetap terjangkau lewat tag. Hasil: Contributors tinggal dua orang. **Temuan sampingan yang lebih mendesak dari pekerjaannya sendiri:** `~/.claude/settings.json` menyimpan Personal Access Token GitHub polos — dilaporkan, dicabut, dihapus |
| G17 | Varian metode kedua **Flowmeter Gravimetri (ISO 4185)** untuk alat ke-27 & ke-28 — §27 | **BERES di server** (10 Sep 2026) — bukan alat ke-29: dua workbook master baru mengukur alat, besaran, dan pita CMC yang SAMA dengan varian UFM, dengan metode yang sama sekali lain. Yang dibangun sumbu `varian_metode` di dua profil yang sudah ada, presedennya TimbanganProfile / TITS / TIDS. Rumusnya dibuktikan di Python SEBELUM PHP: **107 pengaduan sel-demi-sel, nol beda** pada 5·10⁻⁶ — termasuk tabel densitas yang ternyata BUKAN rumus melainkan piknometer 50,3139 ml di empat suhu plus interpolasi linier. Delapan penyimpangan master dibetulkan dengan arah yang ditegakkan test, sepuluh ditiru + diangkat jadi 23 pertanyaan lab. Yang paling menentukan: koreksi timer yang dihitung lalu dibuang **membalik TANDA** deviasi Flowrate titik 2 (+0,0299 → −0,0012 Lpm), dan lantai CMC yang tidak pernah dipasang membuat keempat titik terbit di bawah pita — titik 3 mengklaim ketidakpastian sebelas kali lebih baik dari yang diakui KAN. **Nol kolom baru** di `raw_measurements`. Satu bug LAMA ikut ketemu: `peringatanSesi()` memulangkan deret string sementara `CalibrationValidator` menuntut `['kode','pesan']`, jadi endpoint `/validasi` pulang **500** untuk sesi UFM mana pun yang geometri pipanya kosong — hidup diam-diam sejak 8 Sep karena kedua sesi contoh selalu punya geometri pipa. **Sisi mobile BELUM** — `docs/perintah-frontend-flowmeter-gravimetri.md` §6 memasang syaratnya: sapuan mock registry dulu, baru cabang varian |
| G18 | **Alur verifikasi lembar kerja & peran Super Admin** — §35 | **PRD DITULIS** (17 Sep 2026), nol baris kode. Yang menentukan ukurannya: sebagian besar kerangkanya SUDAH ADA — alur `draft`→`menunggu_approval`→`disetujui`/`perlu_revisi`, aksi tolak berikut `catatan_revisi`, jalur teknisi mengerjakan ulang, tujuh kelas `Notification`, dan pengirim push FCM. Yang benar-benar baru cuma enam: penandaan per titik (tabel `tanda_revisi`, isi lembar TIDAK dihapus), suntingan pemeriksa berikut jejaknya, peran `super_admin` dihidupkan, pencatatan akses lintas-organisasi, revisi sertifikat bernomor, dan notifikasi sertifikat diunduh. Dua keputusan diambil pemilik proyek SESUDAH risikonya disebutkan dan itu tertulis di §35: super admin menembus `organization_id` (ISO/IEC 17025 §4.2 — karena itu tiap akses lintas-lab wajib tercatat), dan edit sesudah sertifikat terbit menembus kebekuan `uncertainty_calculations` (karena itu tiap edit melahirkan revisi bernomor, bukan menimpa). Prasyarat di luar urutan sudah **SELESAI 23 Sep 2026**: `super_admin` kini bisa login, buka panel, dan membaca seluruh rute `GET` — menulis masih ditahan sampai K4 turun, dan lintas organisasi belum dibuka. Delapan pertanyaan terbuka di `docs/pertanyaan-lab-alur-verifikasi.md` |

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

- **Sapuan yang memulangkan "tidak ada" wajib dibuktikan bisa memulangkan "ada".**
  Audit 9 Sep 2026 (§26) sempat menyimpulkan pH tidak punya test yang mengadu
  budgetnya ke master. Testnya ada — `UncertaintyBudgetTest`, dan malah untuk
  KEDUA lembar pH. Yang salah sapuannya: `grep -lі` diketik dengan huruf **і
  Kiril**, `grep` menolak opsinya, `xargs` memulangkan nol berkas, dan keluaran
  kosongnya kebaca persis seperti temuan. Bentuk kegagalan ini berbahaya karena
  **hasil nol dari perintah rusak tidak bisa dibedakan dari hasil nol yang
  benar**, dan yang kedua terasa jauh lebih memuaskan buat ditulis. Sebelum
  memercayai sapuan negatif: jalankan dengan pola yang PASTI ada dan lihat dia
  memulangkan sesuatu, atau periksa exit code-nya — bukan cuma keluarannya.

- **`sertifikat:bangun-ulang` TIDAK punya penjaga penandatangan.** Tombol
  "Cetak ulang PDF" di panel menolak sertifikat yang penandatangan bekunya beda
  dari yang berlaku sekarang (lihat `CetakUlangSertifikat`); perintah artisan-nya
  tidak. Lebih jauh: dia menyusun ulang snapshot, dan `footer.penandatangan`
  diambil dari **setelan organisasi yang berlaku saat itu**
  (`CertificateSnapshotBuilder` baris 446). Jadi sertifikat yang dulu
  ditandatangani orang lain akan diam-diam berganti nama jadi penandatangan
  sekarang — dokumen menyatakan seseorang menandatangani sesuatu yang tidak
  pernah dia tandatangani, dan nol error muncul. Periksa dulu sebelum
  menjalankannya di server.

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

- **Penjaga yang mengikat kode ke dokumen terkendali cuma boleh digeser sama pemilik lab, bukan
  sama yang lagi ngoding.** `TidsLembarKerjaTest` dulu menjaga urutan bagian *"ngikut urutan
  kertasnya dibaca dari atas"*. Menyeragamkan tata letak antar-lembar terdengar seperti kerapian
  murni, padahal dia memindahkan kotak relatif terhadap `SIDIK-FM-CAL-0506 Rev.4` — jadi waktu
  penjaganya merah, yang dibatalkan perubahannya, dan pilihannya diangkat ke pemilik lab.
  Pemilik memutuskan seragam (26 Agt 2026), dan baru sesudah itu penjaganya diganti — berikut
  alasan barunya, bukan dihapus. Yang bikin ini aman: kertas dan lembar cetaknya nggak ikut
  berubah, cuma urutan baca di layar. Kalau penjaga semacam itu merah dan nggak ada keputusan
  pemilik yang menyertainya, yang salah perubahannya — bukan penjaganya.

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
- **Test yang menyandar ke rentang AUTO_INCREMENT merah cuma waktu suite PENUH jalan di
  MySQL.** `RefreshDatabase` di MySQL memigrasi sekali lalu membungkus tiap test dalam
  transaksi. Rollback mengembalikan barisnya, tapi **counter AUTO_INCREMENT-nya tidak ikut
  balik** — dan tiap tabel naik dengan laju berbeda tergantung berapa baris yang dibikin
  test-test *sebelumnya*. Jadi dua tabel yang id-nya berdekatan di awal suite melar berjauhan
  makin ke belakang.

  `IdPelangganDiDaftarArsipTest::test_id_folder_yang_dikirim_ke_rute_pelanggan_membuka_pt_lain`
  berdiri di atas premis "id FOLDER yang dikirim ke rute pelanggan membuka PT LAIN yang ADA" —
  premis yang cuma berlaku selama rentang `folders` dan `customers` bertumpang tindih. Kelasnya
  hijau 6/6 sendirian; dijalankan sesudah `GlobalSearchTest` (yang bikin Customer tanpa bikin
  Folder, jadi `customers.AUTO_INCREMENT` = 23 sementara `folders.AUTO_INCREMENT` = 17) rutenya
  balas 404, `continue` melewatinya, dan assert-nya merah **tanpa satu pun kode produksi yang
  rusak**. CI tidak pernah melihatnya: `phpunit.xml` jalan di `sqlite::memory:`, yang dibangun
  ulang tiap test sehingga id-nya selalu balik ke 1 dan premisnya selalu kebetulan berlaku.

  **Sudah dibereskan** (28 Agt 2026), dengan membetulkan test-nya — endpoint-nya memang sudah
  benar. Dua hal yang dikerjakan, dan dua-duanya perlu:

  1. Id folder **dipatok** ke id pelanggan urutan kebalik (`forceCreate(['id' => ...])`, karena
     `id` sengaja di luar `#[Fillable]` milik `Folder`), diturunkan dari id pelanggan yang
     benar-benar terbentuk — bukan angka harfiah, jadi kebal terhadap pergeseran counter.
  2. `continue` waktu bukan-200 diganti `assertOk()`. Yang bikin ini bertahan lama bukan
     404-nya, tapi **404 yang dilewat diam-diam**: setup yang bubar kelihatan persis seperti
     "tidak ada yang tertukar".

  Aturan umumnya: kalau sebuah test perlu id dari dua tabel saling menabrak (atau saling
  menghindar), **patok id-nya dari nilai yang dibaca saat itu juga** — jangan pernah berharap
  AUTO_INCREMENT-nya berdekatan, dan jangan `continue` melewati respons tak terduga di dalam
  loop yang menghitung. Sekaligus: `phpunit.mysql.xml` sudah mewanti "seeder/test yang mematok
  `id => 1` putus FK-nya di MySQL saja" — mematok **angka harfiah** memang salah; yang benar
  mematok dari id yang barusan terbentuk.
- **`flutter analyze` mengedit `analysis_options.yaml` sendiri** ("Upgrading analysis_options.yaml
  to exclude build and platform directories"). Selalu keluarkan dari commit.
- **`flutter test` hijau bukan bukti UI hilang** — sebagian besar test pindai menguji layanan
  langsung, tidak lewat tombol.
- **AUTO_INCREMENT MySQL nggak ikut di-rollback, jadi test yang menyandar ke "dua id berdekatan"
  merah CUMA di suite penuh.** `RefreshDatabase` di MySQL memigrasi sekali lalu membungkus tiap
  test dalam transaksi — rollback-nya mengembalikan barisnya, tapi **counter AUTO_INCREMENT-nya
  nggak ikut balik**. Tiap tabel naik dengan laju berbeda, tergantung berapa baris yang dibikin
  test-test sebelumnya, jadi `customers` dan `folders` **melar berjauhan** makin ke belakang suite.

  `IdPelangganDiDaftarArsipTest::test_id_folder_yang_dikirim_ke_rute_pelanggan_membuka_pt_lain`
  berdiri di atas premis "id folder yang dikirim ke rute pelanggan membuka PT LAIN yang ada" —
  dan premis itu cuma berlaku selama dua rentang id-nya masih bertumpang tindih. Begitu melar,
  rute pelanggannya balas 404, `continue` melewatinya, `$tertukar` tetap 0, dan
  `assertGreaterThan(0, ...)` merah. Yang merah **penjaganya, bukan kodenya**.

  Dibuktikan 28 Agt 2026 di HEAD bersih (`03a4d1c`, nol kode TIDS): kelasnya **sendirian → 6/6
  hijau**; didahului satu kelas lain yang bikin Customer tanpa Folder (`GlobalSearchTest`) →
  **test yang sama merah**, dengan `folders` berhenti di 17 dan `customers` di 23. Di suite penuh
  MySQL dia merah baik di HEAD bersih (2.257 test, 1 gagal) maupun di cabang TIDS (2.268 test,
  1 gagal) — kegagalannya itu-itu juga, jadi **bukan regresi**.

  CI hijau karena jalan di `sqlite::memory:`: di sana `RefreshDatabase` membangun ulang
  database-nya, id-nya balik ke 1 tiap test, dan premisnya selalu kebetulan berlaku. Jadi ini
  gerbang MySQL lokal doang. Kalau mau dibereskan, yang dibetulkan **test-nya** — patok id-nya
  eksplisit atau bandingkan lewat nama, jangan menyandar ke rentang id — bukan endpoint-nya.
| G18 | Alat baru **Anak Timbangan (OIML R111)** — §29 | **BERES di server** (10 Sep 2026) — alat ke-29, kelompok Massa, **di luar lampiran akreditasi** (kertasnya sendiri menyebut Non KAN). Rumusnya dibuktikan di Python SEBELUM PHP: **1033 pengaduan sel-demi-sel, nol beda** pada 5·10⁻⁶, ditegakkan `AnakTimbanganMasterTest` (12 test / 1011 asersi). **Nol kolom baru**. Tiga kerusakan rujukan dibetulkan dengan ARAH yang ditegakkan test — yang terbesar kolom koreksi apung yang memakai massa keping PERTAMA untuk dua belas keping lain, meleset sampai 2,58 mg pada keping bertoleransi 0,10 mg. Temuan terbesar justru bukan itu: sel berlabel `Rata-rata STDev` ternyata berisi SIMPANGAN BAKU dari enam simpangan baku harian, lebih kecil dari keterulangan hari mana pun — kalau lab menjawab yang dimaksud gabungan harian, **U95 seluruh sertifikat naik ~1,9x**. Ditiru karena `FORM VALIDASI` mencatatnya sebagai perubahan metode yang sengaja & sudah divalidasi. Enam gerbang penerbitan dipasang; master sendiri menerbitkan `#VALUE!` di lima dari dua puluh baris dan satu keping 10 g sebagai 5,500163 g (meleset 45 %). Kertas Rev.0 dibaca lebih dulu dan menyumbang empat temuan yang tidak ada di workbook. 23 pertanyaan lab. **Sisi mobile BELUM** — `docs/perintah-frontend-anak-timbangan.md` §5 memasang lima butirnya |
| G19 | **Sertifikat Flowmeter tercetak dalam satuan hitung, bukan satuan alat pelanggan** — cacat di dalam G14/G17 | **BERES di server** (17 Sep 2026) — bukan alat baru: satu cacat cetak yang hidup diam-diam sejak alat ke-27 & ke-28 mendarat. Mesin hitungnya sengaja dipindah ke L (Totalizer) / Lpm (Flowrate) supaya satu mesin melayani semua satuan, dan `uncertainty_calculations` menyimpan angka yang SUDAH dikonversi. Yang tidak pernah terjadi: membaliknya lagi waktu mencetak. Alat yang layarnya menunjukkan **3,0 m3/h terbit dengan 50,0 Lpm** di kolom Unit Under Test. `FlowmeterCalculator::konversiBalik()` ditulis untuk ini sejak awal — docblock-nya bahkan menyebut *"Dipakai jalur SERTIFIKAT"* dan menghitung selisih 16,7x-nya — lalu **nol pemanggil**. Master membagi balik dengan faktor yang sama (`SERTIFIKAT!E26 = 'PERHITUNGAN FC'!D63 / DATABASE!$S$22`). **Kenapa tidak ketahuan:** dua sesi contohnya bersatuan `L` dan `LPM`, dua-duanya faktor 1,0 — nol test yang pernah melewati jalur konversi sama sekali. Dan angkanya sendiri tidak pernah terlihat ganjil, karena **kolom satuannya ikut berubah**: tabelnya konsisten dengan dirinya sendiri, cuma tidak dengan alat yang dikalibrasi, tidak dengan kop sertifikat (yang membaca `raw_measurements.satuan` dan sudah menulis `m3/h`), dan tidak dengan jumlah desimalnya (yang lahir dari `equipments.resolusi`, juga bersatuan alat). Satu dokumen, dua satuan untuk besaran yang sama. **Bentuknya:** hook baru `CalibrationProfile::cetakDalamSatuanAlat()` yang memulangkan CLOSURE, bukan faktor — satuan berbasis massa butuh densitas, dan densitas dibaca PER TITIK. Bawaannya `null`, jadi profil lain nol tersentuh. Densitasnya diambil dari sumber yang SAMA yang dipakai waktu menghitung: UFM membaca `flow_densitas_uut` yang diketik teknisi, gravimetri memanggil `FlowmeterGravimetriCalculator::densitasTerkoreksi()` — fungsinya, bukan salinannya, yang karena itu dijadikan publik. **Yang sengaja tidak berubah:** sesi bersatuan `L`/`LPM` nol pergeseran (hook-nya memulangkan `null` untuk faktor identitas, bukan closure identitas, supaya ejaan `Lpm` tidak diam-diam jadi `LPM`), sertifikat yang sudah terbit nol pergeseran (snapshot dibekukan waktu terbit — ada test yang menguncinya), dan bentuk JSON snapshot nol kunci berubah, jadi **sisi mobile nol pekerjaan** (dia sudah membaca `hasil[].satuan` per baris). Titik yang bahan baliknya HILANG sesudah hitungannya tersimpan **memblokir sertifikatnya** dengan pesan yang menyebut nomor titiknya — bukan diam-diam mencetak angka hitung berlabel `kg/h`; `GenerateCertificate` menangkapnya, menyetempel sertifikatnya `gagal`, dan mengirim pesannya ke admin. Dijaga `FlowmeterSatuanSertifikatTest` (7 test / 64 asersi), dan penjaganya **dibuktikan menahan**: hook-nya dimatikan → 5 dari 7 merah; labelnya dibiarkan pindah tapi angkanya tidak → 3 merah lagi. Kontraknya ditulis di `docs/perintah-frontend-flowmeter.md` §0.1 |
| G20 | Alat baru **Volumetric Glassware** (enam alat lampiran, dua keluarga) — §36 | **BERES di server** (22 Sep 2026) — V20 dan budget diadu ke kedua workbook dari masukan mentah, angka cetak sertifikat master (V20, Correction, U95 0,003 & 0,34) terbukti lewat jalur HP → simpan → validator → hitung ulang. Nol kolom baru. 13 pertanyaan lab. **Sisi mobile BELUM** — `docs/perintah-frontend-volumetric.md` |
| G21 | Alat baru **kelompok Gaya**: Mesin UTM & Load Cell — §37 | **BERES di server** (24 Sep 2026) — alat ke-40 & ke-41, kelompok besaran baru. Rumusnya dibuktikan di Python lawan ketiga workbook SEBELUM PHP, dan hasil yang menentukan: **mesin GUM yang sudah ada mereproduksi master persis**, termasuk pemotongan `v_eff` ke bawah — nol mesin agregasi kedua. CMC diadu ke lampiran akreditasi, cocok persis, sekaligus menggugurkan satu dari enam temuan panduan (G9). **Nol kolom baru**: dua blok tingkat-sesi (preload & misalignment) masuk `spesifikasi_alat`, dua belas bacaan per titik memakai sumbu `peran_sensor` yang sudah ada. Kedua alat berbagi satu kelas induk `GayaProfile`, jadi sisi HP **satu layar untuk dua alat**. Satu penyimpangan master DISENGAJA: workbook Load Cell memakai `Y` di kolom `Standard Value` cuma pada cabang satuan kN sementara lima cabang lain dan penjaganya masih `Z` — suntingan yang berhenti di tengah, yang kalau ditiru bikin angka tercetak berubah arti tergantung satuan tampilan; sistem memakai `Y` untuk semua satuan dan menulis selisihnya di jejak audit sesi. **Proving Ring SENGAJA ditunda** (G11): koreksi standarnya hilang di semua titik karena `ISERROR` menelan `VLOOKUP` yang gagal jadi sel kosong yang dibaca nol — kelas kesalahan yang AGENTS.md larang ditiru, dan konsekuensinya (sertifikat Proving Ring yang sudah terbit tidak memuat koreksi standar) perlu diketahui lab lebih dulu. 12 pertanyaan lab. **Gerbangnya menangkap dua kekeliruan sendiri sebelum satu pun sertifikat gaya terbit**, dua-duanya tanpa error: kolom `Standard Value` & `Unit Under Test` TERTUKAR dan yang satu 102x terlalu besar (titik 100 kgf tercetak `10193,7`) karena `nilaiStandarDariKoreksi()` belum dinyalakan — preseden Waktu & Frekuensi yang terlewat; dan sesi contoh UTM titik 300 kgf kehilangan satu pembacaan `300,2` sehingga rata-ratanya meleset 0,0084 kgf — pada satu desimal angka cetaknya SAMA, jadi yang menangkapnya asersi nilai penuh 5x10⁻⁶, bukan pemeriksaan angka cetak. Penjaga barunya mengadu **snapshot sertifikat** (bukan kolom mentah) untuk keenam belas titik kedua alat, plus satu asersi hubungan: `Correction` wajib sama dengan `Standard Value − UUT`. **Sisi mobile BELUM** — `docs/perintah-frontend-gaya.md`. **25 Sep 2026: jalur simpan dari HP dibetulkan** (§37c) — tabel Preload menimpa seluruh blok Gaya, bacaan UP/DOWN Proving Ring tidak pernah dibaca, dan beban keterulangan Timbangan yang diketik hilang; dijaga `KontrakLembarSemuaAlatTest` yang menyapu semua profil |
| G22 | Semua lembar kerja jadi **dua halaman** (persiapan \| pengukuran) — §37d | **BERES di server** (26 Sep 2026) — satu aturan `CalibrationProfile::susunDuaHalaman()` di endpoint lembar kerja & generator mock, diturunkan dari isi bagian; 39 lembar yang tadinya satu gulungan kini dua halaman, Gaya tidak diubah. Dijaga `LembarKerjaDuaHalamanTest` (ke-42 profil lewat endpoint: tepat [1, 2], standar di 1, tabel & penutup di 2, isi bagian lain tidak berubah). Generator mock kini SELALU SQLite in-memory yang di-seed — tidak pernah membaca produksi. **Sisi mobile**: grid Enclosure pindah ke halaman terakhir, mock & test disesuaikan — PR mobile |
