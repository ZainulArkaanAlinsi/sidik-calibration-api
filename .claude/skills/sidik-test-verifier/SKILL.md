---
name: sidik-test-verifier
description: Generate PHPUnit test (Feature/Unit) ala sidik-calibration-api dan tegakkan gate verifikasi MySQL sebelum kerja dianggap selesai. Pakai saat user minta bikin test, nambah test coverage, atau bilang "udah selesai" untuk perubahan backend.
---

# Sidik Test & MySQL Verification Gate

Proyek ini pakai **PHPUnit murni** (bukan Pest) — lihat `phpunit.xml`, tiap test
class `extends Tests\TestCase`, pakai `RefreshDatabase`. Tapi test hijau di
sini TIDAK CUKUP: test jalan di SQLite in-memory, produksi jalan di MySQL, dan
empat bug pernah lolos dari suite hijau gara-gara beda perilaku dua database
itu (lihat memori `[[verifikasi-mysql-sebelum-selesai]]`).

## Pola Test di Proyek Ini

```php
class NamaFiturTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        // ...factory setup lain
    }

    public function test_nama_skenario_bahasa_indonesia_jelas(): void
    {
        $response = $this->actingAs($this->teknisi)->postJson('/api/...', [...]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    }
}
```

Konvensi penamaan method: `test_snake_case_bahasa_indonesia` yang menjelaskan
skenario, bukan `testCreate()` generik — grep `tests/Feature/CalibrationValidationTest.php`
untuk pola nyata.

## Yang WAJIB Di-cover
1. **Happy path** sesuai peran (admin vs teknisi vs viewer) — role beda,
   assertion beda.
2. **Scoping organisasi** — test eksplisit bahwa user organisasi lain TIDAK
   bisa lihat/ubah data organisasi ini (403/404), bukan cuma test "yang benar
   bisa".
3. **Status guard** — test bahwa aksi ditolak pada status yang salah (mis.
   approve sesi yang belum `menunggu_approval`).
4. **Storage**: kalau test menyentuh upload/PDF, pastikan `Storage::fake('local')`
   sudah aktif (sudah otomatis lewat `Tests\TestCase::setUp()` — jangan
   panggil ulang manual kecuali disk lain).
5. **Queue**: job async (`GenerateCertificate`, dll) — pakai `Queue::fake()`
   kalau cuma mau test dispatch-nya, atau panggil `->handle()` langsung kalau
   mau test efek sinkronnya (lihat catatan: `approve()` di
   `CalibrationController` sengaja memanggil job secara sinkron, bukan
   `dispatch()` — jangan "perbaiki" jadi async tanpa diminta).

## Gate Verifikasi MySQL — TIDAK BOLEH DILEWATI

Sebelum bilang ke user "sudah selesai" / "sudah berhasil" untuk perubahan yang
menyentuh database (migrasi baru, query baru, precision kolom, seeder):

1. Jalankan test suite (`php artisan test` atau filter spesifik).
2. **Reproduksi ulang di MySQL nyata** (bukan cuma percaya suite SQLite hijau)
   — pakai `php artisan tinker` terhadap DB lokal user (`asmo_db` di MAMP,
   lihat `[[db-lokal-bukan-share-lan]]`), jalankan skenario yang sama, cek
   hasil sungguhan.
3. Kalau ada migrasi baru yang mengubah kolom CMC/precision, ingat seeder CMC
   harus di-run ulang setelah migrate (`[[git-sinkron-tapi-data-basi]]`) —
   branch sama tidak berarti data seed sama.
4. Laporkan ke user: perintah apa yang dijalankan + hasil aktualnya (bukan
   asumsi "harusnya jalan").

## Larangan
- Jangan klaim "sudah bisa dipakai" hanya dari suite hijau tanpa reproduksi di
  MySQL — lihat `[[buktikan-jalan-bukan-tanya]]`: buktikan dengan menjalankan
  end-to-end di DB nyata, jangan menyerahkan daftar blocker ke lab.
- Jangan biarkan `ANTHROPIC_BASE_URL` dari shell CLI bocor ke test yang pakai
  `Http::fake` — ini sudah dibetulin di `Tests\TestCase::setUp()`, jangan
  dihapus/dilewati.
- Test yang menulis file sertifikat/PDF ke disk asli itu bug, bukan fitur —
  harus lewat `Storage::fake()`.
