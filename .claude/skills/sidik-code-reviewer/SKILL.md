---
name: sidik-code-reviewer
description: Review kode PHP/Laravel di sidik-calibration-api terhadap konvensi proyek — komentar WHY-only, penamaan domain Indonesia, scoping organization_id, pola #[Fillable], FormRequest, status transition guard. Pakai saat user minta review kode, cek sebelum commit, atau sebelum PR di repo ini.
---

# Sidik Code Reviewer

Reviewer khusus repo `sidik-calibration-api` (Laravel 13, Filament 5, PHP 8.3).
Proyek ini BUKAN Laravel generik — punya konvensi ketat sendiri karena dipakai
lab kalibrasi terakreditasi. Kode yang "jalan" tapi melanggar konvensi ini
lolos test tapi salah secara akreditasi/audit.

## Checklist Review

### 1. Komentar: WHY, bukan WHAT
- Komentar HANYA boleh menjelaskan alasan non-obvious (keputusan lab, insiden
  masa lalu, constraint akreditasi, kenapa BUKAN pendekatan lain).
- Tolak komentar yang cuma mendeskripsikan apa yang dilakukan baris di
  bawahnya — nama variabel/fungsi Indonesia yang deskriptif sudah cukup.
- Semua komentar dalam Bahasa Indonesia, gaya proyek (lihat
  `app/Http/Controllers/Api/CalibrationController.php` sebagai referensi).

### 2. Multi-tenant scoping — WAJIB tiap query
- Query terhadap model yang scoped ke lab (`CalibrationSession`, `Equipment`,
  `Standard`, `Customer`, dll) HARUS difilter `organization_id` dari
  `$request->user()->organization_id` — baik di controller maupun di
  `Rule::exists(...)->where('organization_id', ...)` pada FormRequest.
- Cek soft-delete: query referensi ke tabel master (`equipments`, `standards`,
  `rooms`, `calibration_methods`) HARUS ikut `whereNull('deleted_at')`, sesuai
  pola di `CalibrationController::lembarKerja()`.
- Flag keras kalau ketemu query tanpa scoping organisasi terhadap tabel yang
  seharusnya scoped — ini bug keamanan data-leak antar pelanggan.

### 3. Role & akses
- Endpoint admin-only harus dijaga di `routes/api.php` lewat
  `middleware('role:admin')`, BUKAN cuma dicek manual di controller. Kalau
  controller melakukan pengecekan role manual (`abort_if(... isAdmin())`),
  pastikan itu untuk kasus lebih spesifik (mis. "cuma teknisi pemilik sesi
  ATAU admin"), bukan pengganti middleware role dasar.

### 4. Mass assignment & atribut model
- Model pakai atribut PHP `#[Fillable([...])]`, bukan property `$fillable`.
  Field baru wajib ditambah ke daftar itu, dan kalau ada alasan non-obvious
  kenapa field itu fillable/tidak, tulis di komentar tepat di sebelahnya
  (lihat contoh migrasi-referenced comment di `CalibrationSession`).

### 5. Status transition guard
- Model dengan status berjenjang (`draft` → `menunggu_approval` →
  `disetujui`/`perlu_revisi`) harus menjaga transisi lewat konstanta
  `STATUS_*`, bukan string literal tersebar. Cek endpoint update/approve/
  reject selalu validasi status SEBELUM eksekusi, dan status `disetujui`
  (sertifikat sudah terbit) tidak pernah bisa diedit oleh siapa pun.

### 6. Larangan proyek (dari CLAUDE.md & memori)
- Nama file/kelas TIDAK boleh memuat nama PT/customer — pakai jenis alat.
- Jangan pakai `git add -A` / `git add .` dalam saran commit.
- Jangan sisipkan trailer `Co-Authored-By: Claude` di pesan commit yang
  disarankan.
- Kalau nemu file `IdeHelper*.php` yang basi/aneh, itu bukan bug kode — arahkan
  ke regenerasi `composer ide-helper`, bukan `git rm` manual.

## Format Output (per temuan)
- 📍 Lokasi (file:baris)
- ⚠️ Masalah & kenapa melanggar konvensi proyek (bukan idiom Laravel umum)
- ✅ Perbaikan konkret

## Guidelines
- Jangan usulkan pola Laravel "textbook" (repository pattern, service
  container binding berlebihan) kalau proyek ini sengaja tidak memakainya —
  proyek ini pakai Service class + Eloquent langsung, tanpa repository layer.
  Lihat `[[sidik-data-layer]]`.
- Controller di proyek ini boleh "gemuk" (banyak method) selama logika berat
  (perhitungan, validasi kompleks) tetap didelegasikan ke Service — bukan
  ditulis ulang di controller.
- Kalau ragu suatu pola sengaja atau kebetulan, cek dulu apakah ada komentar
  WHY di dekatnya sebelum menandainya sebagai masalah.
