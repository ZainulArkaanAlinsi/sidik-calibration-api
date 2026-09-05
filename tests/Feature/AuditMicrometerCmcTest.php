<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Support\MicrometerMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perintah `micrometer:audit-cmc` — pelingkup tinjauan ketidaksesuaian.
 *
 * ## Kenapa test ini ada, dan kenapa dia menjalankan perintahnya sungguhan
 *
 * Perintah artisan di repo ini nggak bisa dicoba dari terminal sesi kerja, jadi
 * satu-satunya cara membuktikan dia jalan ya membungkusnya di test. Yang mahal
 * kalau dilewat bukan kegagalan besar melainkan salah nama kolom: `nomor`
 * versus `nomor_sertifikat` lolos `php -l`, lolos review mata, dan baru mati
 * waktu manajer teknis menjalankannya buat rapat ketidaksesuaian.
 *
 * ## Yang dijaga
 *
 * 1. Arsip bawaan BERSIH — nol temuan. Ini pengaman arah: kalau seeder suatu
 *    saat menanam sesi cacat, yang merah test ini, bukan angka di sertifikat
 *    pelanggan.
 * 2. Tiap cacat KETANGKAP waktu benar-benar ada, dan kodenya kebaca.
 */
class AuditMicrometerCmcTest extends TestCase
{
    use RefreshDatabase;

    public function test_arsip_bawaan_nol_temuan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('micrometer:audit-cmc')
            ->expectsOutputToContain('Nol temuan')
            ->assertSuccessful();
    }

    public function test_pra_evaluasi_seragam_ketangkap(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = $this->sesiMicrometer();
        $spek = $sesi->spesifikasi_alat;
        $spek[MicrometerMentah::KUNCI_SESI]['pra_evaluasi'] = array_fill(0, 10, 50.0);
        $sesi->update(['spesifikasi_alat' => $spek]);

        $this->artisan('micrometer:audit-cmc')
            ->expectsOutputToContain('Perlu ditinjau: 1')
            ->assertSuccessful();
    }

    public function test_resolusi_kosong_ketangkap(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = $this->sesiMicrometer();
        $spek = $sesi->spesifikasi_alat;
        $spek[MicrometerMentah::KUNCI_SESI]['resolusi_mm'] = 0.0;
        $sesi->update(['spesifikasi_alat' => $spek]);

        $this->artisan('micrometer:audit-cmc')
            ->expectsOutputToContain('Perlu ditinjau: 1')
            ->assertSuccessful();
    }

    /**
     * U95 di bawah lantai CMC — cacat §1, dan yang paling penting dari ketiganya.
     *
     * Diturunkan lewat baris hitungan TERSIMPAN, bukan lewat hitung ulang:
     * yang dilingkupi memang apa yang tercetak di sertifikat pelanggan.
     */
    public function test_u95_di_bawah_lantai_cmc_ketangkap(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = $this->sesiMicrometer();
        $sesi->uncertaintyCalculations()->update([
            // 0,50 µm — jauh di bawah lantai pita mana pun (terkecil 0,83 µm).
            'ketidakpastian_diperluas' => 0.0005,
        ]);

        $this->artisan('micrometer:audit-cmc')
            ->expectsOutputToContain('di_bawah_cmc')
            ->assertSuccessful();
    }

    public function test_penyaring_org_dipatuhi(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = $this->sesiMicrometer();
        $spek = $sesi->spesifikasi_alat;
        $spek[MicrometerMentah::KUNCI_SESI]['resolusi_mm'] = 0.0;
        $sesi->update(['spesifikasi_alat' => $spek]);

        // Organisasi yang nggak punya sesi Micrometer sama sekali.
        $this->artisan('micrometer:audit-cmc', ['--org' => 999999])
            ->expectsOutputToContain('Nggak ada sesi Micrometer')
            ->assertSuccessful();
    }

    private function sesiMicrometer(): CalibrationSession
    {
        return CalibrationSession::query()
            ->whereHas('equipment', fn ($q) => $q->where('nama_alat_kemampuan', 'Micrometer'))
            ->firstOrFail();
    }
}
