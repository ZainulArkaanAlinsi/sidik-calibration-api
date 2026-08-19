---
name: sidik-api-scaffolder
description: Generate endpoint API baru (Controller, FormRequest, Resource, route, role gate) sesuai pola sidik-calibration-api. Pakai saat user minta bikin endpoint baru, tambah aksi ke controller yang ada, atau expose data baru ke mobile/frontend.
---

# Sidik API Scaffolder

Repo ini punya satu API surface (`routes/api.php`, `auth:sanctum` + role
middleware), dikonsumsi mobile & web frontend terpisah (bukan monorepo). Bentuk
respons dikunci kontrak — lihat komentar `docs/kontrak-api.md bagian 4` di atas
`CalibrationResource`. **Jangan ubah bentuk response existing tanpa memastikan
kontrak ikut di-update**, karena sisi mobile membaca bentuk lama secara buta.

## Anatomi Endpoint Baru

### 1. Route (`routes/api.php`)
```php
Route::middleware('auth:sanctum')->group(function () {
    // role tunggal
    Route::middleware('role:admin')->group(function () {
        Route::post('/resource', [ResourceController::class, 'store']);
    });

    // multi-role, cek granular di controller kalau perlu
    Route::middleware('role:admin,teknisi')->group(function () {
        Route::get('/resource/{resource}', [ResourceController::class, 'show']);
    });
});
```
Endpoint yang rate-sensitif (upload, generate, notifikasi) pakai
`->middleware('throttle:N,1')` — cek throttle serupa yang sudah ada untuk
endpoint sejenis sebelum menentukan angka baru.

### 2. FormRequest (`app/Http/Requests/`)
- Validasi referensi ke tabel lain SELALU discope organisasi:
  ```php
  Rule::exists('equipments', 'id')
      ->where('organization_id', $this->user()->organization_id)
      ->whereNull('deleted_at'),
  ```
- Kalau field tertentu cuma boleh diisi role tertentu, buang (bukan tolak)
  field itu di `prepareForValidation()` untuk non-admin — pola di
  `CalibrationRequest::prepareForValidation()`. Alasan: field basi dari
  client versi lama tidak boleh bikin request gagal total.
- Pesan error custom (`messages()`) ditulis dalam Bahasa Indonesia, jelas dan
  actionable — bukan pesan default Laravel.

### 3. Controller (`app/Http/Controllers/Api/`)
- Constructor property promotion untuk dependency Service:
  `private readonly XxxService $xxx`.
- Method publik = satu aksi HTTP. Logika berat (perhitungan, validasi
  bisnis kompleks) didelegasikan ke `app/Services/`, TIDAK ditulis inline di
  controller kalau lebih dari beberapa baris.
- Guard organisasi/kepemilikan dicek di awal method lewat helper privat
  (`pastikanSatuOrganisasi()`, `pastikanBolehLihat()` — cek yang sudah ada di
  controller sejenis sebelum menulis ulang).
- Transaksi DB (`DB::transaction(...)`) untuk operasi yang menulis ke lebih
  dari satu tabel terkait (sesi + measurement + calculation sekaligus).
- Response sukses: `response()->json(['data' => ...], $status)`. Response
  error bisnis (bukan validasi): `response()->json(['message' => '...'], 422)`
  dengan pesan Bahasa Indonesia yang menjelaskan APA yang salah dan (kalau
  relevan) apa yang harus dilakukan user.

### 4. Resource (`app/Http/Resources/`)
- `toArray()` return array asosiatif eksplisit, bukan `parent::toArray()`
  otomatis — bentuknya dikunci kontrak, jadi field harus dipetakan manual.
- Kalau field butuh perhitungan tampilan (pembulatan, format), lakukan di
  method statis/privat di Resource itu sendiri agar reusable, bukan
  duplikasi rumus di controller (lihat `CalibrationResource::petakanHasil`
  dipakai ulang oleh endpoint `preview` dan `show`).

## Checklist Sebelum Selesai
- [ ] Route didaftarkan dengan middleware role yang benar
- [ ] FormRequest scope organisasi di semua `Rule::exists`
- [ ] Controller mendelegasikan logika berat ke Service
- [ ] Resource tidak membocorkan field internal (mis. `organization_id` mentah
      kalau tidak perlu, foreign key tanpa relasi termuat)
- [ ] Kalau endpoint baru dikonsumsi frontend terpisah, siapkan draft handoff
      — lihat `[[sidik-handoff-docs]]`

## Guidelines
- Jangan bikin repository/interface layer baru — proyek ini sengaja pakai
  Eloquent + Service langsung (lihat `[[sidik-data-layer]]`).
- Jangan bikin endpoint OCR/vision terpisah dari endpoint input manual — pola
  proyek ini menyatukan lewat parameter `input_method`, bukan endpoint ganda
  (lihat komentar kelas `CalibrationController`).
