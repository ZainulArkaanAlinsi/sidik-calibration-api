# P6 — Bentuk lembar (lapis 3): kontrak input, override, editor

Repo: `sidik-calibration-api` + `sidik-calibration-mobile`. Rujukan: `02-SRS.md` FR-24/25,
ADR-06, `ui-rujukan/EditorBentuk.dc.html`, `05` T3.4, T3.4a, T3.5.

## Tugas (server)

1. `CalibrationProfile::kontrakInput(): array` (kunci field yang dibaca `*Mentah`/kalkulator) dan
   `batasBentuk(): array` (jumlah titik min/maks, satuan tampil yang diizinkan, field bernominal
   dipatok IK). Isi untuk Micrometer dengan membaca `MicrometerProfile::bentukLembarKerja()` dan
   `MicrometerCalculator` — kontrak **dibuktikan** dari kode yang membaca field, bukan ditebak.
2. Versi `jenis = bentuk` di `paket_acuan_versi`: override hanya label, urutan, satuan tampil (dari
   daftar), jumlah titik (dalam batas), wajib/opsional non-kontrak. `GET /calibrations/lembar-kerja`
   menerapkan override versi bentuk yang berlaku, lalu `susunDuaHalaman()` seperti sekarang.
3. Tolak `kontrak_input_dilanggar` & `klien_belum_mendukung` (jenis field di luar kosakata
   `form_dinamis` versi APK tertua yang masih dirilis).
4. `calibration_sessions.bentuk_versi_id` diisi saat lembar pertama kali disimpan.

## Tugas (mobile)

S9 Editor Bentuk: tabel field per bagian + pratinjau memakai **renderer yang sama**
(`lib/widgets/dinamis/form_dinamis.dart`), bukan tiruan.

## Test

`BentukLembarOverrideTest` (hapus field kontrak 422; nominal dipatok terkunci; label berubah
tampil di HP), regresi seluruh test lembar kerja Micrometer.
