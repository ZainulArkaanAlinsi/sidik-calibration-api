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

## Keputusan yang SUDAH diambil

Jangan ditanyakan ulang.

| Kode | Keputusan | Dari |
|---|---|---|
| K3 | Alat tambahan teknisi **langsung bisa dipakai**, tidak menunggu persetujuan admin | pemilik proyek, eksplisit |
| K4 | Nama alat baru **masuk master**, ditandai asalnya, supaya bisa dipakai ulang | pemilik proyek |
| K6 | **Dua** tombol kamera dicabut — `PINDAI LEMBAR KERJA` dan `FOTO TABEL INI` | disetujui lewat "gas G0" |
| K7 | UI pindai **disembunyikan di balik saklar**, bukan dihapus (`--dart-define=PINDAI_LEMBAR=true`) | idem |
| — | Isi Excel master dianggap benar & aman; tidak perlu ditanyakan ulang ke lab | pemilik proyek |

## Yang MASIH menunggu jawaban

| Kode | Pertanyaan | Menahan apa |
|---|---|---|
| K1 | TIDS: 5 UUT jadi 1 sesi, atau 5 sesi terpisah? | 70% ukuran pekerjaan TIDS |
| K2 | Workbook Excel TIDS — kapan dari lab? | **Blocker mutlak** budget ketidakpastian TIDS |
| K8 | Inlab: ruangan wajib dipilih atau boleh kosong? | Kalau wajib penuh, semua APK lama ditolak 422 |
| K10 | Layar Draf: pintu masuknya di mana; admin boleh lihat draf teknisi lain? | Layar Draf |
| K11 | Perlu tombol hapus draf? | `DELETE /api/calibrations/{id}` belum ada sama sekali |

---

## Gelombang & status

Urutannya ditentukan berkas yang bertabrakan, bukan selera — G1 dan G3 sama-sama menyentuh 12
berkas profil.

| Gel. | Isi | Status |
|---|---|---|
| G0 | Sertifikat Insitu, draf tanpa tanggal, ruangan ke-16, cabut UI pindai (perm. 3) | **selesai, di PR draft** — belum masuk `main`, jadi belum ada di APK mana pun |
| G1 | Profil dari server (perm. 1a) + lokasi Inlab/Insitu (perm. 2) | **selesai, di PR draft** — perm. 2 jalan di 12/12 profil, aturannya datang dari server |
| G2 | Kelola daftar alat (perm. 1b) + layar Draf (perm. 4) | 1b jalan; perm. 4 **selesai, di PR draft** (K10/K11 masih menahan pintu masuk & tombol hapus) |
| G3 | Lembar kerja ikut PDF (perm. 6) | **jalan** — kop TITS `SIDIK-FM-CAL-0505 Rev.3` + kepala lembar & blok dimensi Enclosure `SIDIK-FM-CAL-0504 Rev.3` sudah ikut PDF; TIDS `0506 Rev.4` belum |
| G4 | TIDS (perm. 5) | bentuk lembar kerja jalan; **budget ketidakpastian TERBLOKIR K2** |
| G5 | Scan Tabel (perm. 7) | belum — petakan dulu ke yang sudah ada |

### Yang sudah ADA sebelum pekerjaan ini dimulai

Supaya tidak dibangun ulang:

- Alur berjenjang 2 langkah di HP **sudah jalan** — kategori Suhu sudah menampilkan 11 alat,
  termasuk TITS dan TIDS.
- `lokasi` (lab/onsite), tabel `rooms`, `room_id` & `lokasi_nama` di sesi, endpoint CRUD ruangan,
  layar master ruangan di HP. `lokasi_nama` baru terpasang di 2 dari 12 profil.
- Status `draft` + filter `?status=draft` + tombol Simpan Draf di setiap halaman lembar kerja.
- Seluruh backend OCR (lihat perm. 7).
- `AppMotion` + `TampilMasuk` — animasi sudah ada dan sudah dijaga test. Aturannya sengaja:
  bagian tanpa tabel dianimasikan, bagian bertabel polos (60 kotak angka jadi berat).
- Baris CMC TIDS **sudah ter-seed**: 3 rentang (0,86 / 1,4 / 3,1 °C).

### Jebakan yang sudah terbukti — jangan diulang

- **Jatuh diam-diam ke profil pH.** Nama alat yang tidak cocok memulangkan profil pH tanpa error;
  teknisi mengisi lembar pH untuk alat lain. Ejaan TIDS yang mengikat:
  `Temperatur Indikator dengan Sensor` — "Temperatur" bukan "Temperature", "dengan" huruf kecil.
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
- **`flutter analyze` mengedit `analysis_options.yaml` sendiri** ("Upgrading analysis_options.yaml
  to exclude build and platform directories"). Selalu keluarkan dari commit.
- **`flutter test` hijau bukan bukti UI hilang** — sebagian besar test pindai menguji layanan
  langsung, tidak lewat tombol.
