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

**Sumber nama PT di luar data PT Sidik belum dipilih** — lihat K16. Tidak ada API resmi & gratis
untuk daftar perusahaan Indonesia; yang tersedia sumber peta (Google Places, Nominatim) dengan
syarat pemakaian & biaya masing-masing. Keputusan pemilik proyek: **internal dulu, luar
menyusul.**

---

## 12. Kamera tiap lembar kerja — audit 27 Agt 2026

Pemilik proyek: *"ini yang ada di dalam bagian kamera nya masing masing lembar kerja nya tolong
usahakan bisa karena tadi aku coba coba gk bisa, bisa sih bisa tapi kalo nangkap cuma berapa
table table aja sih."*

Ditelusuri, dan gejalanya persis apa adanya. Dari **20 lembar: 7 tidak punya tombol kamera sama
sekali, 3 punya tombol yang MUSTAHIL menghasilkan satu sel pun, dan 10 sisanya punya jalur jangkar
yang bisa ketemu.** Tiga sebab yang beda, dan cuma satu yang selama ini tercatat.

> **Batas klaim ini.** Yang bisa dibuktikan dari sini cuma dua yang pertama — keduanya keputusan
> kode, dan dua-duanya diuji. Buat kesepuluh sisanya, yang dibuktikan cuma "jangkarnya ADA di
> jalur yang dikenali" (`Xn` / `Repeat n` / deret nomor polos / kepala slot). Apakah tulisan itu
> beneran kecetak di kertas lab cuma bisa dijawab jepretan nyata; sejauh ini yang diadu ke foto
> asli baru Viscometer (`integration_test/foto_tabel_viscometer_hp_test.dart`) dan Conductivity.

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
barisnya, 1..7) **membuat bug yang lebih buruk** — set point sesi terkirim sebagai "1 °C … 7 °C",
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
| **Draf TIDS yang dibuka ulang tampil kosong** | Yang dikirim `titikUkurEfektif` (`121,5`); yang mencari `_titikTerdekat` di kunci `titik` yang isinya NOMOR BARIS (1..7). Tidak pernah ketemu, tiap baris kehitung `kebuang`. Di sesi revisi: yang dikirim balik ke admin cuma sisa yang sempat diketik ulang dari kertas |
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
| **K12** | **Sheet `Variasi axial Dryblok A` isinya data blok B** — kapan hasil ukur Isotech yang asli bisa dikirim? | Komponen `variasi_aksial` & `variasi_antar_lubang` sesi Thermocouple yang memakai blok A |
| **F1** | **Satu foto lembar cetak yang sudah diisi tangan**, dari lembar mana saja | `terverifikasi: true` di **14 dari 20** berkas geometri. **Berhenti jadi blocker 27 Agt 2026** — jalur lembar bermarker yang digerbanginya sudah tidak punya pintu masuk di aplikasi (§12). Tetap dibutuhkan kalau jalur itu dipasang lagi |
| **K13** | `Multimeter Texio/DL` tercetak di `Standar Used` lembar Thermocouple, tapi tidak ada barisnya di master `standards` | Dropdown standar lembar Thermocouple kurang satu pilihan yang ada di kertas |
| **K14** | Nomor seri standar di kertas beda dari yang tersimpan (`TN-02`/`TCK-02` lawan `TCN-06`, `TCN-11`, `TC-01`, `TC-02`) | Teknisi mengadu lembar cetak dengan dropdown dan menemukan nomor yang tidak cocok |
| **K15** | Lembar Termometer Gelas mencantumkan `Sensor Termocouple Type N` & `Type K` di `Standar Used`, sementara pemeriksaan pakai kita belum mengenalinya | Peringatan "standar tidak dipakai" bisa menyala untuk pemakaian yang sah |
| **K16** | Sumber nama + alamat PT Indonesia untuk pencarian pelanggan: pakai data internal PT Sidik dulu, atau langsung sambung ke sumber luar? | Sudah dijawab: **internal dulu, luar menyusul** — dicatat di sini karena sumber luarnya belum dipilih |
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
| G4 | TIDS (perm. 5) | bentuk lembar kerja jalan; **budget ketidakpastian TERBLOKIR K2**. Blokirnya sekarang dijaga `TidsU95TidakBocorTest` — dibuktikan merah dengan melepas blokirnya (U95 langsung lahir dari lantai CMC 0,86 °C) |
| G5 | Scan Tabel (perm. 7) — **perm. 3 DIBATALKAN oleh S1, UI pindai nyala lagi** | **S1/S2/S3 semuanya sudah dijawab**, dan kodenya sudah mendarat. Peta: `docs/peta-permintaan-7-scan-tabel.md`. Sebagian besar spec memang SUDAH terbangun sebelum permintaan 7 ditulis (`worksheet_scans`, pipeline 7 tahap, ML Kit, layar review). Yang ditambah: 9 berkas geometri baru (jadi **17/17**), gerbang bentuk kertas buat jalur foto AI, dan alasan pindai jadi kalimat. **Sisa satu-satunya: F1** — nunggu satu foto, bukan nunggu kode |
| G7 | Tiga alat suhu baru (perm. 10) — Thermocouple, Termometer Gelas, Thermohygrometer | **BERES di server** (26 Agt 2026) — profil + olah data + geometri OCR + CSV. Angkanya cocok sama ketiga workbook master sampai digit terakhir; dijaga `Suhu3AlatMasterTest` (15 test) & `Suhu3AlatLembarKerjaTest` (14 test). **Sisi mobile BERES** (26–27 Agt 2026): layar lembar kerja tabel pasangan (mobile#108), golden ketiga lembar + generator golden tanpa Mac (mobile#111), dua deret pembacaan dipecah di layar detail (mobile#112), dan tiga field sesi (`alat_bantu`, `tipe_pencelupan`, `titik_es`) kebaca admin (api#111 + mobile#113). Nama alat bantu diresolusi SERVER lewat `CalibrationProfile::labelAlatBantu()` — kodenya (`A`/`satu`) cuma punya arti di daftar `pilihan` milik profilnya, jadi peta kode→nama JANGAN disalin ke HP |

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
- **`flutter analyze` mengedit `analysis_options.yaml` sendiri** ("Upgrading analysis_options.yaml
  to exclude build and platform directories"). Selalu keluarkan dari commit.
- **`flutter test` hijau bukan bukti UI hilang** — sebagian besar test pindai menguji layanan
  langsung, tidak lewat tombol.
