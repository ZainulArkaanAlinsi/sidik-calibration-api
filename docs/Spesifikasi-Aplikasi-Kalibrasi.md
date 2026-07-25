# Spesifikasi Pengembangan Aplikasi Kalibrasi (Sidik Calibration)

Dokumen ini merangkum seluruh kebutuhan pengembangan aplikasi kalibrasi, dari sisi Teknisi, Admin, hingga sistem sertifikat otomatis.

---

## 1. Form Input Data — Sisi Teknisi
- Field berikut **dihapus dari tampilan Teknisi** karena bersifat administratif dan hanya dikelola Admin:
  - Order Number
  - Certificate Number
  - Technical ID
  - Calibration Method
  - Thermohygro Used
- Field-field tersebut tetap ada di sistem (database), hanya saja **hanya muncul & bisa diisi/dilihat di sisi Admin**.
- Field yang bersifat **opsional** (sesuai contoh gambar) **dihapus saja** dari tampilan Teknisi — tidak perlu ditampilkan.

## 2. Perbaikan Fitur OCR (Kamera/Foto)
- Saat ini proses OCR **sering error setiap kali mengambil foto**.
- Perlu diperbaiki agar alur ambil foto → OCR **stabil dan tidak error**.

## 3. Navbar Bawah — Ganti "Notifikasi" Menjadi "Folder Manager"
- Menu Notifikasi di navbar bawah **diganti nama menjadi "Folder Manager"**.
- Fungsinya sebagai tempat penyimpanan data sementara agar rapi, dengan fitur:
  - Bisa melihat lokasi/struktur penyimpanan file.
  - Menerapkan sistem **CRUD** (Create, Read, Update, Delete) untuk folder & file.
  - Folder otomatis terbentuk mengikuti pengelompokan **per PT/klien** (misal banyak alat dari PT A dan PT B → otomatis terpisah per folder).

## 4. Notifikasi Dipindah ke Bagian Atas
- Ikon **notifikasi dipindahkan ke bagian atas layar** (seperti aplikasi pada umumnya), tidak lagi di navbar bawah.
- Saat ditekan, akan membuka **halaman notifikasi** tersendiri.

## 5. Tombol Back di Setiap Halaman
- Setiap halaman yang dibuka wajib memiliki **tombol back**, agar user bisa kembali ke halaman sebelumnya dengan mudah.

## 6. Fungsi Notifikasi Sebagai Reminder System
Notifikasi digunakan sebagai pengingat otomatis untuk:
- Alat yang **jatuh tempo** kalibrasi.
- Alat yang masa pemakaiannya (misal 1 tahun) **hampir habis** — notifikasi dikirim beberapa minggu/bulan sebelum jatuh tempo agar terkontrol.
- **Sesi kalibrasi yang telah disetujui** → kirim notifikasi konfirmasi.
- Kejadian/aksi penting lain dalam sistem.

## 7. Sistem Folder untuk Riwayat/Revisi
- Data riwayat kalibrasi disusun otomatis dalam struktur **folder & file**, dikelompokkan per PT/klien.
- Folder **otomatis terbentuk** seiring bertambahnya data — tidak perlu dibuat manual.
- Di halaman **Riwayat**, bagian "Folder" **dihapus** (karena folder sudah dipindahkan sepenuhnya ke menu **Folder Manager** di navbar bawah).

## 8. Navbar Bawah — Hanya Menu Paling Penting
- Navbar bawah **hanya menampilkan menu-menu utama/esensial saja**.
- Tidak menambahkan menu lain yang tidak perlu, supaya navbar tidak terlalu ramai.

## 9. Sertifikat Kalibrasi pH — Khusus Dikelola Admin
- Sertifikat **hanya dibuat/di-generate oleh Admin**, bukan oleh Teknisi.
- Alur kerja: **Teknisi input data pengukuran** → data selesai → **masuk ke Admin** → Admin menyusun & menerbitkan sertifikat.
- Format & isi sertifikat pH **mengikuti struktur baku berikut, tidak boleh menambah field lain di luar ini**:

**Header Informasi**
| Field | Contoh Isi |
|---|---|
| Certificate Number | 012-CAL-524 |
| Page | 1 of 1 |
| Owner | PT TIRTA GRACIA SEMESTA MANDIRI |
| Order Number | 2405.13.A |
| Address | Jl. Arteri Primer A-10 RT. 01 RW.12 Nyalindung Kec. Cicalengka, Kab. Bandung, Jawa Barat |
| Received Date | 26 Mei 2024 |
| Equipment Name | pH Meter |
| Manufacturer | Mettler Toledo |
| Calibration Location | Lab. Uji A |
| Model/Type | Five Easy |
| Calibration Date | 26 Mei 2024 |
| Serial Number | B628755900 |
| Calibration Method | SIDIK-IK-CAL-0506_Rev.6 |
| Capacity/Graduation | 0–14 pH / 0,01 pH |
| Env. Condition | T: 21,0°C ± 1,7°C — %RH: 51,95% ± 5,7% |
| Technician ID | DR |

**Tabel Hasil Kalibrasi (Calibration Report)**
| Standard Value | Unit Under Test | Correction | U95% (±) |
|---|---|---|---|
| 4,01 | 4,00 | 0,01 | 0,02 |
| 6,99 | 7,00 | -0,02 | 0,02 |
| 9,98 | 10,11 | -0,13 | 0,03 |

Catatan tetap di bawah tabel:
- *"The Uncertainty is taken at a Confidence Level 95% and Coverage Factor (k) = 2"*
- *"Calibration results are not to be announced and only apply to related tools"*

**Tabel Standar yang Digunakan (Standard Used)**
| Name | Merk/Type | Serial Number | Traceable to SI through |
|---|---|---|---|
| pH Buffer Solution 4 | Supelco/Merck | HC32513535 | Merck KGaA |
| pH Buffer Solution 7 | Supelco/Merck | HC46341939 | Merck KGaA |
| pH Buffer Solution 10 | Supelco/Merck | HC45400338 | Merck KGaA |
| Termometer & Sensor Std. | Yokogawa/CA 150 Handy Cal | 23P1005 | LK-285-IDN |

**Footer**
| Field | Contoh Isi |
|---|---|
| Issuance Date | 30 Mei 2024 |
| Penandatangan | Alex Misramto |
| Jabatan | Technical Manager |
| Kode Dokumen | SIDIK-FM-CAL-2403_Rev. 0 |

**Aturan penting:**
- Tidak boleh menambah field/section di luar struktur ini.
- Template bersifat **baku (fixed layout)** — hanya isi datanya yang berubah.
- Teknisi **tidak perlu melihat/mengisi bagian pembuatan sertifikat** — cukup input data pengukuran mentah saja.

## 10. Export Sertifikat ke Excel
- Setelah sertifikat pH selesai dibuat Admin, sistem bisa **export data tersebut ke file Excel (.xlsx)**.
- Kegunaan: arsip, rekap, atau backup data di luar aplikasi.
- Ketentuan:
  - Struktur/isi Excel **mengikuti format sertifikat yang sama** (header, tabel hasil kalibrasi, tabel standar, footer).
  - Tombol **"Export to Excel"** tersedia di halaman sertifikat (khusus tampilan Admin).
  - File hasil export bisa langsung **diunduh (download)**.
  - Perlu didiskusikan: export per-sertifikat satuan, atau juga tersedia **export banyak sertifikat sekaligus** (rekap bulanan/per PT).

## 11. Validasi Perhitungan & Struktur Database Sertifikat
- Sebelum sertifikat **final/diterbitkan**, sistem harus **menghitung ulang & memverifikasi semua nilai** (Correction, U95%, dll) agar sesuai rumus kalibrasi — tidak asal ambil dari input mentah.
  - Jika ada perhitungan tidak sesuai/anomali → sistem memberi **peringatan (warning)** ke Admin sebelum sertifikat bisa disetujui/diterbitkan.
- Agar field sertifikat (Owner, Address, Equipment Name, Manufacturer, Calibration Method, Standard Used, dll) bisa **terisi otomatis**, dibutuhkan struktur database yang rapi & saling terhubung, contoh:
  - Tabel **Client/PT** (Owner, Address).
  - Tabel **Equipment/Alat** (Equipment Name, Manufacturer, Model/Type, Serial Number, Capacity/Graduation).
  - Tabel **Calibration Method** (kode metode, revisi).
  - Tabel **Standard/Alat Standar** (Name, Merk/Type, Serial Number, Traceable to SI).
  - Tabel **Technician** (ID Teknisi).
  - Tabel **Calibration Result** (Standard Value, Unit Under Test, Correction, U95%).
  - Tabel **Certificate** (menggabungkan semua relasi di atas jadi satu output sertifikat).
- Tujuan: tidak ada data tidak sinkron/salah ketik ulang, semua otomatis tertarik dari database dengan relasi yang benar, dan **perhitungan akhir tervalidasi sistem** sebelum sertifikat resmi diterbitkan.

## 12. Admin Panel Terpisah (Desktop/Web & Mobile) + Auto-Sync Data

**A. Pemisahan Tampilan (Teknisi vs Admin)**
- Tampilan Admin dibuat **terpisah total** dari tampilan Teknisi karena bersifat **privasi internal kantor**.
- Field yang tidak muncul di Teknisi → **otomatis terisi di sisi Admin** dari database (sesuai poin 11), tidak perlu diketik manual.

**B. Admin Panel Bisa Edit Langsung (Tanpa Excel)**
- Admin bisa **mengubah data langsung dari panel**, tanpa perlu Excel lagi:
  - Ubah data klien/PT, data alat, data standar.
  - Ubah **rumus perhitungan** (Correction, U95%, dsb).
  - Ubah **struktur/layout isi sertifikat**.
- Semua perubahan **otomatis berlaku ke seluruh sistem**, termasuk ke aplikasi mobile — tanpa update manual dua kali.

**C. Fitur Import Excel (Untuk Transisi/Kemudahan Admin)**
- Tersedia fitur **upload/drag & drop file Excel** di admin panel.
- Sistem (dibantu AI/parser otomatis) akan:
  - Membaca isi Excel.
  - Mencocokkan/memetakan data ke struktur database yang sudah ada.
  - Mengisi database secara otomatis sesuai struktur yang benar.
- Tujuan: mempermudah transisi dari Excel manual → Admin Panel, hasil harus **sama persis/akurat** dengan data Excel aslinya.
- Ke depannya, proses bisa **sepenuhnya lewat Admin Panel** tanpa Excel.

**D. Sinkronisasi Otomatis Desktop ↔ Mobile**
- Admin Panel tersedia dalam **2 versi**:
  1. **Desktop/Web** — untuk kerja detail: kelola database, struktur, rumus, import Excel, dll.
  2. **Mobile** — versi ringkas, tetap terpisah dari tampilan Teknisi.
- **Setiap perubahan di Desktop otomatis ter-update di Mobile**, dan sebaliknya — karena keduanya terhubung ke **database yang sama** (real-time sync).

**Intinya:** Excel yang tadinya dipakai admin manual, digantikan sepenuhnya oleh **Admin Panel** (Desktop & Mobile) yang terhubung ke database — hasil tetap akurat & konsisten seperti Excel, tapi lebih cepat, otomatis, dan tersinkron di semua platform.

## 13. Export Sertifikat ke Excel/PDF + Fitur QR Code
- Sertifikat yang sudah jadi bisa di-**export ulang menjadi Excel atau PDF**, sesuai kebutuhan Admin.
- Setiap sertifikat juga bisa dibuatkan **QR Code**:
  - QR Code ini bisa **di-scan/foto** untuk langsung mengakses sertifikat terkait.
  - Dari hasil scan QR, user bisa langsung **download sertifikat** atau **share/kirim** sertifikat tersebut.
  - Format hasil download/kirim bisa dalam bentuk **Excel atau PDF**, sesuai pilihan.
- Tujuan akhir: sistem sertifikat harus **fleksibel dan membantu di segala skenario** — baik untuk arsip (Excel), untuk dikirim resmi ke klien (PDF), maupun untuk akses cepat (QR Code).

---

## Ringkasan Alur Kerja Sistem (End-to-End)

1. **Teknisi** input data pengukuran mentah di lapangan (tanpa field administratif/opsional, OCR yang sudah stabil).
2. Data masuk otomatis ke **Admin**, field administratif (Order Number, Certificate Number, dll) sudah otomatis terisi dari database.
3. **Admin Panel** (Desktop/Web atau Mobile) digunakan untuk mengelola, mengedit, dan memvalidasi data — tanpa perlu Excel manual (kecuali untuk import awal/transisi).
4. Sistem melakukan **validasi & perhitungan ulang** otomatis sebelum sertifikat bisa diterbitkan.
5. **Sertifikat pH** dibuat sesuai format baku, lalu bisa di-**export ke Excel/PDF** dan dilengkapi **QR Code** untuk akses/download/kirim cepat.
6. Data riwayat & dokumen tersimpan rapi di **Folder Manager** (navbar bawah), otomatis terstruktur per PT/klien.
7. **Notifikasi** (di bagian atas layar) mengingatkan soal jatuh tempo alat, masa pakai alat, dan status persetujuan sesi kalibrasi.
8. Semua perubahan struktur/rumus di Admin Panel **otomatis sinkron** antara Desktop dan Mobile secara real-time.

---

*Dokumen ini dapat digunakan sebagai acuan untuk tim developer atau AI coding assistant dalam melanjutkan pengembangan aplikasi.*
