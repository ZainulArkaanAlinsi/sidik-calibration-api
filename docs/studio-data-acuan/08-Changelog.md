# 08 — Changelog Studio Data Acuan

Draf. Diisi ulang dengan nomor versi & tanggal saat tiap tahap 07 §2 benar-benar naik.

---

## A. Untuk tim (teknis)

### [Belum dirilis] — Fase 1: fondasi data acuan ber-versi

**Ditambahkan**
- Tabel `paket_acuan`, `paket_acuan_versi`, `paket_acuan_suntingan`, `simulasi_acuan`,
  `simulasi_acuan_sesi`, `unggahan_workbook` (additive).
- Kolom `uncertainty_calculations.paket_acuan_versi_id`, `calibration_sessions.bentuk_versi_id`
  (nullable, `nullOnDelete`).
- `App\Services\Calibration\SumberAcuan`, `PenentuVersiAcuan`, `JsonKanonik`.
- `CalibrationProfile::skemaAcuan()`, `kontrakInput()`, `batasBentuk()` (bawaan null/kosong).
- Perintah `php artisan acuan:impor-awal {profil?}`.
- Izin `data-acuan.lihat|sunting|impor|ajukan|sahkan`, `bentuk-lembar.sunting`.
- Grup rute `/api/data-acuan/*`; rute sahkan/tolak/kembalikan/tarik di grup `role:super_admin`.
- Sakelar env `DATA_ACUAN_SUMBER` (`db` | `json`).

**Diubah (kompatibel)**
- `TabelStandarMicrometer` membaca `SumberAcuan`; cache statis per proses diganti cache per
  (versi, sha256).
- `GET /calibrations/lembar-kerja`: + `versi_acuan`, + `ETag`.
- Perhitungan, validasi, snapshot sertifikat: + `paket_acuan_versi`.
- `CalibrationValidator`, `HitungUlangSesi`: memakai versi dari stempel.
- `PemisahanWewenang`: + aturan pengesah versi data acuan.

**Tidak diubah**
- Rumus (lapis 2) di semua profil dan calculator.
- `formulas` / `formula_versions` dan perilaku stempelnya.

### [Belum dirilis] — Fase 2: Studio desktop (pilot Micrometer)
- Seksi "Data Acuan" di `DesktopShell`; layar S1–S7; grid acuan; simulasi; pengesahan.
- HP: banner draf terdampak, `If-None-Match` di lembar kerja.

---

## B. Untuk pengguna lab

### Segera hadir — "Data Acuan" di aplikasi laptop

**Apa yang baru**
- Menu **Data Acuan** di aplikasi laptop. Isinya tabel acuan tiap alat — nilai standar, koreksi,
  ketidakpastian standar, pita CMC, konstanta — dengan tab yang sama dengan sheet di workbook
  master alat itu. Alat pertama: **Micrometer**.
- Master Data bisa membuat **draf**, mengetik atau menempel nilai dari Excel/sertifikat, lalu
  menekan **Simulasikan** untuk melihat sesi mana yang angka cetaknya ikut berubah.
- Draf yang diajukan **disahkan oleh orang lain** (super admin). Sesudah disahkan, semua HP dan
  laptop memakai data baru untuk sesi bertanggal sejak tanggal berlaku — paling lambat beberapa
  menit, atau saat lembar kerja dibuka.

**Yang tidak berubah**
- Sertifikat yang sudah terbit tidak pernah berubah angkanya.
- Cara mengisi lembar kerja di HP tetap sama.
- Rumus perhitungan tidak bisa diubah dari layar ini. Usulan perubahan rumus dikirim lewat
  tombol **Usulkan perubahan rumus** dan dikerjakan pengembang dengan pengujian terhadap
  workbook master.

**Yang perlu diperhatikan teknisi**
- Bila data acuan berubah saat Anda sedang mengisi draf, akan muncul pemberitahuan di atas
  lembar. Angka hasil memakai data terbaru untuk tanggal kalibrasi Anda; Anda tetap bisa
  mengirim seperti biasa.
