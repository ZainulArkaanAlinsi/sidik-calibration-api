---
name: sidik-query-organisasi
description: Jaga setiap query Eloquent/DB di sidik-calibration-api tersaring organization_id — peta model yang wajib disaring, model anak yang diraih lewat induk, penjaga kepemilikan 404, dan test isolasi yang wajib menyertai. Pakai saat menulis atau mengubah query, menambah endpoint, menyalin query yang sudah ada, atau sebelum bilang perubahan yang menyentuh data lab sudah selesai.
---

# Sidik Query Organisasi (Scoping `organization_id`)

Proyek ini **TIDAK punya global scope**. Tidak ada trait, tidak ada
`addGlobalScope`, tidak ada middleware yang menyaring otomatis. Semua saringan
`organization_id` ditulis tangan — 315 kemunculan di `app/`. Itu keputusan
sadar, bukan celah yang belum sempat ditambal.

Konsekuensinya satu, dan itu alasan berkas ini ada: **query yang lupa disaring
tetap jalan, tetap balas 200, dan tetap hijau di test.** Yang hilang bukan
error, tapi batas antar-lab.

## Kenapa lupa scoping tidak pernah ketahuan sendiri

Database hari ini masih **satu organisasi**. Jadi query yang lupa disaring
mengembalikan hasil yang persis sama dengan query yang benar. Dites manual:
lolos. Dilihat di Postman: lolos. Dipakai di lapangan: lolos — sampai lab kedua
di-onboard, dan sejak itu semua yang lupa disaring bocor sekaligus.

Artinya: **tidak ada satu pun cara mendeteksi lubang ini dengan cara mencoba.**
Yang bisa menangkapnya cuma dua — membaca query-nya, dan test yang sengaja
membuat dua organisasi. Selain itu semua bentuk "sudah saya cek" adalah klaim
yang meleset.

## Peta: mana yang wajib disaring

**18 model punya kolomnya sendiri — saring langsung:**

`AuditLog` · `CalibrationCapability` · `CalibrationMethod` ·
`CalibrationSession` · `Certificate` · `CertificateEmailLog` · `Customer` ·
`Equipment` · `EquipmentCategory` · `Folder` · `FolderFile` · `Formula` ·
`FormulaVersion` · `Order` · `Room` · `Standard` · `User` · `WorksheetScan`

**6 model TIDAK punya kolomnya — diraih lewat induk:**

| Model | Induk yang membawa organisasi |
|---|---|
| `RawMeasurement` | `CalibrationSession` |
| `UncertaintyCalculation` | `CalibrationSession` |
| `WorksheetExtractionLog` | `CalibrationSession` |
| `WorksheetScanCell` | `WorksheetScan` |
| `OrderItem` | `Order` |
| `DeviceToken` | `User` |

Model anak **tidak boleh** dikueri dari akarnya. `RawMeasurement::query()->...`
tanpa lewat sesi itu query lintas-organisasi, walau tidak ada kolom yang
kelihatan salah. Masuk lewat relasi induknya yang sudah tersaring, atau pakai
`whereHas` ke induk itu.

`Organization` sendiri adalah akarnya — dia yang menyimpan batasnya, bukan yang
dibatasi.

## Empat pola yang dipakai di repo ini

### 1. Baca

```php
->where('organization_id', $request->user()->organization_id)
```

Selalu diturunkan dari **user yang sedang login**, tidak pernah dari input
request. `organization_id` yang datang dari body/query string itu parameter
serangan, bukan data.

### 2. Tulis

```php
'organization_id' => $request->user()->organization_id,
```

Ikut ditulis saat `create()`, bukan dibiarkan diisi mass-assignment dari
request. Kalau baris baru menunjuk induk (mis. kemampuan menunjuk kategori),
organisasinya **diturunkan dari induk itu**, bukan dari user — lihat jebakan
"dua tempat" di bawah.

### 3. Penjaga kepemilikan

```php
abort_if($sesi->organization_id !== $request->user()->organization_id, 404);
```

**404, bukan 403.** Konsisten di seluruh controller. 403 mengakui barisnya ada
— itu membocorkan keberadaan data lab lain walau isinya tidak ikut keluar.

Dipasang setiap kali baris diambil lewat ID dari route, karena route model
binding tidak tahu apa-apa soal organisasi.

### 4. Baris bersama lintas organisasi

```php
->where(fn ($q) => $q->where('organization_id', $organizationId)
                     ->orWhereNull('organization_id'))
```

Dipakai **sekali** di repo ini (`CalibrationController` baris ~1823), untuk
baris master yang sengaja dibagi ke semua lab. Kalau menulis `orWhereNull`
yang kedua, itu bukan mengikuti pola — itu keputusan baru soal data mana yang
boleh dibagi, dan pantas ditanyakan ke pemilik lab dulu.

## Jebakan yang sudah terbukti

**Menyalin query = menyalin lubangnya.** Tiga belas tempat menyalin
`Standard::query()->whereNull('parameter_kondisi')` dan **ketiga belasnya lupa
menyaring organisasi** — padahal di berkas yang sama, 250 baris di bawahnya,
pola itu sudah diperingatkan sendiri. Yang ikut bocor bukan cuma daftar: nomor
sertifikat & ketertelusuran lab lain mendarat di layar teknisi, dan
`standard_id` yang bocor bikin sesinya **ditolak sistem** dengan pesan menyebut
kolom yang tidak pernah dia ketik. Dijaga sekarang oleh
`tests/Feature/StandarTidakBocorAntarLabTest.php`.

Pelajarannya: waktu menyalin query yang sudah ada, saringan organisasinya
**tidak ikut tersalin kalau aslinya juga tidak punya**. Periksa aslinya dulu,
jangan asumsikan yang lama sudah benar.

**Kepemilikan yang ditulis di dua tempat bisa berbeda tanpa error.**
`calibration_capabilities.organization_id` dan
`equipment_categories.organization_id` dua-duanya menyatakan pemilik baris CMC
yang sama. Selama sama, tidak ada yang kelihatan. Begitu beda, `GumCalculator`
mencari kandidat CMC lewat `equipment_category_id` — jadi angka ketidakpastian
terbaik lab A terpasang sebagai **lantai U95 di sertifikat lab B**, sertifikat
yang mengklaim kemampuan yang tidak pernah diakreditasi untuk lab itu, tanpa
satu pun error di mana pun.

Yang benar: lempar `App\Exceptions\KemampuanLintasOrganisasi`. **Jangan tambal
otomatis** dengan menimpa `organization_id` pakai punya kategorinya — itu
kelihatan ramah tapi memindahkan kepemilikan satu baris CMC tanpa ada yang
minta.

## Sebelum bilang selesai

1. **Sapu query yang baru ditulis atau disentuh.** Setiap `::query()`,
   `::where(`, `DB::table(`, dan `whereHas` yang menyentuh 18 model di atas —
   ada saringan organisasinya atau tidak.
2. **Cek model anak.** Kalau menyentuh 6 model tanpa kolom, telusuri sampai
   induknya dan pastikan induk itu yang tersaring.
3. **Cek arah datangnya nilai.** `organization_id` harus datang dari
   `$request->user()`, bukan dari input.
4. **Cek `first()` dan `firstWhere()`.** Dua yang paling berbahaya — dia
   memulangkan satu baris tanpa protes walau kandidatnya dari lab lain.
5. **Test dua organisasi**, lihat bagian berikutnya. Tanpa ini, poin 1–4 cuma
   klaim.
6. **Verifikasi di MySQL**, bukan SQLite — `[[verifikasi-mysql-sebelum-selesai]]`.

## Test yang wajib menyertai

Perubahan yang menyentuh query berdata lab **tidak selesai tanpa test yang
membuat dua organisasi**. Pola acuannya ada di
`tests/Feature/StandarTidakBocorAntarLabTest.php`:

- Bikin organisasi kedua berikut datanya, lalu pastikan jalur yang diuji
  **tidak pernah** memulangkan baris milik organisasi itu.
- Kalau yang dijaga satu pola yang tersebar di banyak profil/endpoint, ambil
  daftarnya dari **registry**, bukan ditulis tangan — perbaikan satu-satu
  meninggalkan yang lain, dan penyalin berikutnya menyalin yang mana pun yang
  kebetulan dia lihat.
- Sweep yang daftarnya datang dari luar wajib punya **penjaga lantai**
  (`assertGreaterThanOrEqual`). Tanpa itu daftarnya bisa menyusut diam-diam dan
  PHPUnit tetap menulis "OK" dengan lebih sedikit yang diperiksa.

Bentuk testnya ikut `[[sidik-test-verifier]]`.

## Rujukan

- Model & migrasi baru: `[[sidik-data-layer]]`
- Endpoint baru: `[[sidik-api-scaffolder]]`
- Review sebelum commit: `[[sidik-code-reviewer]]`
- Gate verifikasi: `[[verifikasi-mysql-sebelum-selesai]]`
