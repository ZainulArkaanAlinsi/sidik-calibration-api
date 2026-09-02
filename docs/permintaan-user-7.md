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

Ditambahkan pemilik proyek 31 Agt 2026 bersama tiga workbook master ber-password (pw `spirit285`):

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

**Catatan kepatuhan, diangkat bukan didiamkan:** data Indonetwork hasil pengambilan 333 halaman
situs, sementara §10 no. 3 dokumen strategi pelanggan melarang scraping situs direktori. Berkasnya
sudah ada di tangan pemilik proyek dan pemakaiannya keputusan dia; dicatat di sini supaya kalau
ditanya asesor jawabannya sudah tertulis. Serah-terima: `docs/perintah-direktori-lokal.md`.

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
| G5 | Scan Tabel (perm. 7) — **perm. 3 DIBATALKAN oleh S1, UI pindai nyala lagi** | **S1/S2/S3 semuanya sudah dijawab**, dan kodenya sudah mendarat. Peta: `docs/peta-permintaan-7-scan-tabel.md`. Sebagian besar spec memang SUDAH terbangun sebelum permintaan 7 ditulis (`worksheet_scans`, pipeline 7 tahap, ML Kit, layar review). Yang ditambah: 9 berkas geometri baru (jadi **17/17**), gerbang bentuk kertas buat jalur foto AI, dan alasan pindai jadi kalimat. **Sisa satu-satunya: F1** — nunggu satu foto, bukan nunggu kode |
| G8 | Alat baru **Timbangan** (perm. 14) — kelompok Massa, alat ke-21 | **BERES di server** (31 Agt 2026) — satu profil, tiga varian master (kg / gram / substitusi), dua budget U95 per titik ikut NMI Monograph 4. Angkanya cocok sampai digit terakhir dengan ketiga workbook: **1.099 angka** diadu `TimbanganMasterTest` (tiap `ui×ci`, tiap `vi`, `uc`, `veff`, `k`, `U`, `U95`), plus `TimbanganCmcCocokAkreditasiTest` yang mengadu 17 pita CMC ke lampiran akreditasi. Sepuluh pertanyaan lab di `docs/pertanyaan-lab-timbangan.md` — yang terbesar T1 (tiga snapshot sertifikat anak timbangan buat keping fisik yang sama) dan T2 (`ui` U-of-Correction: tiga perlakuan, selisih hampir 2×). **Sisi mobile BERES** (31 Agt 2026): lembarnya kegambar & payloadnya sampai, 13 test baru. Lima cacat SUNYI ketemu waktu disambungkan — 39 kotak yang read-only tanpa sadar, blok bersarang yang dibaca nol, `peran` yang membelokkan seluruh lembar ke jalur pasangan, kunci baris yang bentrok antar tabel, dan pengatur titik yang dipakai bersama; rinciannya di §14 E. Jalur kamera per TABEL nyala di blok Keterulangan saja (§14 F). **Sertifikatnya juga BERES** (31 Agt 2026): delapan bagian master (Repeatability · Effect of Tare · Accuracy · Loading Influence · Hysterisis · Limit of Performance · Weighing Uncertainty · Standard Used) dicetak lewat `snapshot['timbangan']` + cabang blade, ikut preseden Autoklaf; sebelumnya tujuh dari delapan bagian hilang diam-diam di tabel empat kolom generik. Dijaga `TimbanganSertifikatTest` (13 test) — angkanya diadu ke sel master DAN ke HTML yang dirender, plus penjaga satu halaman. Satu cacat SUNYI ketemu di situ: kolom `Correction` varian substitusi menyimpan `ΔI`, bukan kumulatif `Cn` yang dicetak master — titik terakhir terbit 1,4559 kg untuk lembar yang masternya menulis 13,309 kg |
| G7 | Tiga alat suhu baru (perm. 10) — Thermocouple, Termometer Gelas, Thermohygrometer | **BERES di server** (26 Agt 2026) — profil + olah data + geometri OCR + CSV. Angkanya cocok sama ketiga workbook master sampai digit terakhir; dijaga `Suhu3AlatMasterTest` (15 test) & `Suhu3AlatLembarKerjaTest` (14 test). **Sisi mobile BERES** (26–27 Agt 2026): layar lembar kerja tabel pasangan (mobile#108), golden ketiga lembar + generator golden tanpa Mac (mobile#111), dua deret pembacaan dipecah di layar detail (mobile#112), dan tiga field sesi (`alat_bantu`, `tipe_pencelupan`, `titik_es`) kebaca admin (api#111 + mobile#113). Nama alat bantu diresolusi SERVER lewat `CalibrationProfile::labelAlatBantu()` — kodenya (`A`/`satu`) cuma punya arti di daftar `pilihan` milik profilnya, jadi peta kode→nama JANGAN disalin ke HP |
| G9 | Alat baru **kelompok Waktu dan Frekuensi** (perm. 15) — Timer/Stopwatch, Centrifuge, Infrared Tachometer; alat ke-22..24 | **BERES di server** (1 Sep 2026) — dua mesin hitung untuk tiga alat, nol kolom baru di `raw_measurements`, dan lampiran akreditasi kelompok "Waktu dan Frekuensi" jadi LENGKAP. Rumusnya dibuktikan di Python SEBELUM PHP ditulis: **464 nilai** diadu sel demi sel ke ketiga workbook pada 5·10⁻⁶, dan setiap selisih punya penjelasan. Dijaga `WaktuFrekuensiMasterTest` (16 test, 402 asersi) yang mengadu tiap kolom turunan DAN tiap komponen budget, bukan cuma U95 akhirnya. Empat kerusakan master dihitung benar (arahnya ditegakkan test: kita wajib lebih BESAR) dan lima titik hantu diblokir. Tiga belas pertanyaan lab di `docs/pertanyaan-lab-waktu-frekuensi.md`; §4/§5/§7/§11 **ditutup 1 Sep 2026** oleh arahan pemilik proyek "pakai rumus Excel", menyisakan §8/§9 dan dua yang menyangkut dokumen terbit (§10 tanda koreksi, §13 kalimat `k`) plus satu permintaan data (workbook Timer yang keempat bloknya hidup). **Sisi mobile BERES** (1 Sep 2026, PR mobile #139) — ketiga lembar bisa diisi & dikirim dari HP tanpa layar baru; menyambungkannya membongkar tiga cacat lama yang gagal tanpa error: lembar Thermohygro terkirim KOSONG, tombol FOTO TABEL INI mengisi nol sel di lima lembar berpasangan, dan kolom U95 memakai desimal kolom hasil. Jalur kamera cloud tetap MATI sampai kertas ber-nomor `SIDIK-FM-` turun |
| G10 | Data pelanggan — nama PT & alamat (perm. 16) | **A BERES di server** (2 Sep 2026) — `customers:impor` mendarat dengan **43 test** (17 perintah + 15 pembaca CSV + 11 pemilah kembar), nol kolom baru dan nol dependensi baru. Rangka direktorinya ternyata **sudah lengkap server→HP** sejak sebelumnya; yang kurang isinya. Enam jebakan sunyi dikunci test — pemisah `;` Excel lokal ID, `levenshtein()` yang balik −1 di atas 255 byte, `PT`/`CV` yang jaraknya cuma 2, soft delete yang tetap memegang unique index, telepon yang jadi `8.12E+11`, dan riwayat audit tanpa penanggung jawab. **B menunggu keputusan biaya** (membatalkan K16, nol kode). **C & D belum** — nunggu A dipakai dengan data sungguhan. Daftar PT nasional **tidak bisa disediakan**: AHU punya datanya tanpa API, Places/OSM punya API tapi alamat peta bukan alamat akta — rinciannya §16 B  **Ditambah 2 Sep 2026: direktori lokal** — 10.320 PT (Jababeka 450 + Indonetwork 9.870) bisa dicari ±10 ms tanpa keluar server, lewat tabel rujukan terpisah `direktori_lokal` dan driver baru yang memenuhi kontrak `DirektoriPerusahaan` yang sudah ada. **Nol berkas berubah di sisi HP, nol tambahan ukuran APK.** Menyeed ke `customers` sengaja DITOLAK: `SimpananPelanggan` menyalin seluruh daftar pelanggan ke SharedPreferences yang dibaca utuh ke memori tiap aplikasi nyala — diukur **1,36 MB JSON** per buka aplikasi. Satu bug ketemu & dikunci test: `tersedia()` di service provider bikin **`/api/health` 500** waktu tabelnya belum ada. 22 test baru. Rinciannya §16 F |

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
