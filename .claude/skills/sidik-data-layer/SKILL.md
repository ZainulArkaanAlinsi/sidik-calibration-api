---
name: sidik-data-layer
description: Generate migrasi & model Eloquent sesuai konvensi sidik-calibration-api — scoping organization_id, soft delete, presisi kolom desimal, trait Diaudit, atribut #[Fillable]. Pakai saat user mau bikin tabel baru, kolom baru, atau model baru.
---

# Sidik Data Layer (Migrasi & Model)

Proyek ini TIDAK pakai repository pattern — akses data langsung lewat Eloquent
model dari Controller/Service. Jangan usulkan menambah layer repository/
interface; itu bukan celah, itu keputusan sadar.

## Migrasi

- Tabel yang datanya milik satu lab HARUS punya `organization_id` (foreign key
  ke `organizations`), dan tabel master (equipment, standard, dll) harus soft
  delete (`softDeletes()`), bukan hard delete — data kalibrasi lama tetap
  harus bisa dirujuk walau alat/standarnya sudah "dihapus".
- **Presisi kolom desimal HARUS dicocokkan ke workbook Excel master lab**,
  bukan ke resolusi alat atau ke feeling. Nilai yang cocok tapi bentuknya
  (jumlah desimal) beda dari master dianggap SALAH oleh lab — lihat
  `[[format-cetak-dari-sel-master]]`. Tanya/cek workbook dulu sebelum menebak
  `decimal(x, y)`.
- Kalau kolom presisi diubah, cek apakah ada konstanta cermin di controller
  (mis. `CalibrationController::DESIMAL_PEMBACAAN`) yang juga harus ikut naik
  — dua tempat itu sengaja disinkronkan manual, bukan baca dari schema.
- Migrasi baru yang menyentuh CMC/kolom yang di-seed: ingatkan user seeder
  harus di-run ulang setelah `migrate` (`[[git-sinkron-tapi-data-basi]]`),
  jangan asumsikan data seed lama masih valid.

## Model

```php
#[Fillable([
    'organization_id', 'field_a', 'field_b',
    // Komentar WHY untuk field yang non-obvious, rujuk nomor migrasi.
])]
class NamaModel extends Model
{
    use Diaudit, HasFactory; // Diaudit WAJIB untuk data yang bisa diubah lewat API

    public const STATUS_X = 'x'; // konstanta status, bukan string literal tersebar

    protected function casts(): array
    {
        return [
            'kolom_tanggal' => 'date',
            'kolom_array' => 'array',
        ];
    }
}
```

- Pakai atribut `#[Fillable([...])]`, BUKAN property `protected $fillable`.
- `Diaudit` trait wajib untuk model yang perubahannya harus tercatat di
  `audit_logs` (dipasang lewat model event, otomatis menangkap semua jalur:
  API, Filament, artisan command, job). Jangan catat audit manual per
  endpoint — itu justru pola yang trait ini sengaja hindari (endpoint yang
  lupa dipasangi jadi tidak kelihatan sebagai bug).
- Jangan menelan exception dari proses audit — kalau pencatatan audit gagal,
  perubahan datanya HARUS ikut gagal (constraint akreditasi, bukan bug).

## Naming
- Nama kelas/file model, migrasi, dan field pakai istilah domain kalibrasi
  Indonesia (`CalibrationSession`, `nomor_sesi`, `tanggal_kalibrasi`) —
  konsisten dengan kode yang sudah ada.
- **Jangan pernah** memakai nama PT/customer di nama file/kelas/tabel — pakai
  jenis alat (`ViscometerProfile`, bukan nama customer tertentu). Lihat
  `[[jangan-pakai-nama-pt-di-nama-file]]`.

## Setelah Ubah Model
- Regenerasi ide-helper pakai `composer ide-helper` (script proyek, sudah
  termasuk `--write-mixin`) — JANGAN pakai `--nowrite` manual, itu yang bikin
  Problems panel penuh error palsu (`[[regenerasi-ide-helper]]`).

## Guidelines
- Kalau perlu query kompleks berulang, taruh sebagai local scope
  (`scopeXxx()`) di model atau method di Service — bukan repository baru.
- Cek `[[sidik-kalkulasi-presisi]]` sebelum mengubah kolom yang terlibat
  perhitungan GUM/ketidakpastian — presisi kolom dan presisi hitung saling
  bergantung.
