<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorksheetScan;
use App\Models\WorksheetScanCell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Retensi citra pindai — `php artisan ocr:bersihkan-citra`.
 *
 * `config/ocr.php` sudah menuliskan dua jendela retensi sejak awal, tapi
 * sampai perintah ini dibuat NGGAK ADA satu baris kode pun yang membacanya.
 * Foto lembar kerja pelanggan menumpuk tanpa batas sementara yang membaca
 * config-nya percaya sebaliknya — dan buat lab terakreditasi, selisih antara
 * kebijakan tertulis dan perilaku sistem itu temuan audit.
 *
 * Test ini yang bikin janji itu ditepati: kalau ada yang menghapus perintahnya,
 * melepas jadwalnya, atau bikin dia berhenti menghapus, yang merah di sini.
 */
class BersihkanCitraPindaiTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $org = Organization::factory()->create(['id' => 1]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
    }

    public function test_citra_lewat_batas_dibuang_dan_kolomnya_dikosongkan(): void
    {
        $lama = $this->pindai(hariLalu: 120);

        $this->artisan('ocr:bersihkan-citra')->assertSuccessful();

        Storage::disk('local')->assertMissing('worksheet-scans/lama.jpg');
        Storage::disk('local')->assertMissing('worksheet-scans/lama-warp.png');

        $lama->refresh();
        // Path yang menunjuk berkas yang sudah nggak ada lebih buruk daripada
        // null: layar koreksi bakal menjanjikan gambar yang nggak akan muncul.
        $this->assertNull($lama->citra_path);
        $this->assertNull($lama->citra_warp_path);
    }

    /**
     * Barisnya TETAP. Di situ ada teks yang kebaca, skor, status, dan koreksi
     * teknisi — itu dataset akurasi & jejak audit, bukan citra pelanggan.
     */
    public function test_baris_pindai_dan_selnya_nggak_ikut_kehapus(): void
    {
        $lama = $this->pindai(hariLalu: 120);
        WorksheetScanCell::create([
            'worksheet_scan_id' => $lama->id,
            'kunci' => 'sebelum_adjustment|1|1|pembacaan',
            'tabel_id' => 'sebelum_adjustment',
            'baris_ke' => 1,
            'repeat_no' => 1,
            'field_id' => 'pembacaan',
            'teks_normal' => '7,01',
            'nilai' => 7.01,
            'status' => 'kuning',
        ]);

        $this->artisan('ocr:bersihkan-citra')->assertSuccessful();

        $this->assertDatabaseHas('worksheet_scans', ['id' => $lama->id]);
        $this->assertDatabaseHas('worksheet_scan_cells', [
            'worksheet_scan_id' => $lama->id,
            'nilai' => 7.01,
        ]);
    }

    public function test_citra_yang_masih_dalam_batas_ditinggal(): void
    {
        $baru = $this->pindai(hariLalu: 10, nama: 'baru');

        $this->artisan('ocr:bersihkan-citra')->assertSuccessful();

        Storage::disk('local')->assertExists('worksheet-scans/baru.jpg');
        $this->assertNotNull($baru->refresh()->citra_path);
    }

    public function test_mode_kering_nggak_menghapus_apa_pun(): void
    {
        $lama = $this->pindai(hariLalu: 120);

        $this->artisan('ocr:bersihkan-citra', ['--kering' => true])->assertSuccessful();

        Storage::disk('local')->assertExists('worksheet-scans/lama.jpg');
        $this->assertNotNull($lama->refresh()->citra_path);
    }

    /**
     * Ambang boleh dipaksa dari terminal — buat lab yang kebijakannya beda,
     * dan buat memeriksa dampaknya sebelum menyetel `.env` produksi.
     */
    public function test_umur_bisa_dipaksa_lewat_opsi(): void
    {
        $baru = $this->pindai(hariLalu: 10, nama: 'baru');

        $this->artisan('ocr:bersihkan-citra', ['--umur-citra' => 5])->assertSuccessful();

        Storage::disk('local')->assertMissing('worksheet-scans/baru.jpg');
        $this->assertNull($baru->refresh()->citra_path);
    }

    /** Perintahnya harus BENERAN terjadwal — bukan cuma ada di `artisan list`. */
    public function test_pembersihan_terjadwal_harian(): void
    {
        $perintah = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($e): string => (string) $e->command);

        $this->assertTrue(
            $perintah->contains(fn (string $c): bool => str_contains($c, 'ocr:bersihkan-citra')),
            'ocr:bersihkan-citra harus terdaftar di scheduler — retensi yang cuma bisa dijalankan '
                .'manual sama saja dengan tidak ada.',
        );
    }

    private function pindai(int $hariLalu, string $nama = 'lama'): WorksheetScan
    {
        Storage::disk('local')->put("worksheet-scans/{$nama}.jpg", 'isi-foto');
        Storage::disk('local')->put("worksheet-scans/{$nama}-warp.png", 'isi-warp');

        $pindai = WorksheetScan::create([
            'organization_id' => $this->teknisi->organization_id,
            'user_id' => $this->teknisi->id,
            'template_id' => 'ph_meter',
            'template_versi' => 1,
            'pipeline_versi' => 'ocr-1.0.0',
            'aturan_versi' => 'val-1.0.0',
            'status' => 'selesai',
            'citra_path' => "worksheet-scans/{$nama}.jpg",
            'citra_warp_path' => "worksheet-scans/{$nama}-warp.png",
        ]);

        // `created_at` diisi belakangan: kolom itu diurus timestamps, dan yang
        // diuji di sini justru umurnya.
        $pindai->forceFill(['created_at' => now()->subDays($hariLalu)])->saveQuietly();

        return $pindai->refresh();
    }
}
