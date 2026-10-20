<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * K-50-15 (pemilik, 18 Okt 2026; ditegaskan 20 Okt): sertifikat persis lembar
 * `SERTIFIKAT` master. Hook kelompok PISTON VOLUME — Fixed `160` (Micropipette
 * & Dispensett contoh) dan Graduated `164` (Buret Digital contoh), dipilih
 * keluarga sesi — dijaga lewat sesi contoh seeder yang diterbitkan dan
 * dirender sungguhan.
 *
 * Angka diadu ke teks sel master dengan pemisah desimal `[.,]`; baris satuan
 * (`E20` `ml`) urusan template bersama, tidak diadu di sini.
 */
class SertifikatPistonBentukMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_sertifikat_piston_ikut_lembar_sertifikat_master(): void
    {
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        foreach (['DEMO-PP-FIX-001', 'DEMO-DS-FIX-001', 'DEMO-BD-GRD-001'] as $nomor) {
            [$s, $html] = $this->terbitkan($nomor);

            // Sama di 160 & 164: `O11`, `E18:N18`, kalimat k `E23`/`E25`, `G45`/`G47`.
            $this->assertStringContainsString('Environmental Condition', $html, $nomor);
            $this->assertStringContainsString('Tekanan Udara 933 hPa', $s->snapshot['header']['env_condition'], "{$nomor}: `U13` format `0`.");
            $this->assertMatchesRegularExpression('#<td>Suhu Dasar Volume</td>\s*<td>:</td>\s*<td>20 °C</td>#u', $html, $nomor);
            $this->assertLessThan(strpos($html, '<table class="data">'), strpos($html, 'Suhu Dasar Volume'), "{$nomor}: di atas tabel hasil.");
            $this->assertMatchesRegularExpression('#coverage factor \(k\) =\s*2\s*</div>#u', $html, "{$nomor}: k `0`.");
            $this->assertStringContainsString('------------------ End of Certificate ------------------', $html, $nomor);
            $this->assertSame(1, $this->halamanTerbaik($s), "{$nomor}: wajib satu halaman.");
        }

        // ---- Fixed (`160`) ----------------------------------------------------
        [$s, $html] = $this->terbitkan('DEMO-PP-FIX-001');
        $this->assertSame(['standar' => '0', 'uut' => '0.0000', 'koreksi' => '0.0000', 'u95' => '0.000', 'k' => '0'], $s->snapshot['hasil'][0]['format']);
        // `E21:S21` 10 | 10.0312 | 0.0312, `Q22` 0.019 (dulu 0.0188).
        $this->assertMatchesRegularExpression('#<td>10</td>\s*<td>10[.,]0312</td>\s*<td>0[.,]0312</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>0[.,]019 \S+</td>#u', $html);

        // ---- Graduated (`164`) ------------------------------------------------
        [$s, $html] = $this->terbitkan('DEMO-BD-GRD-001');
        $this->assertSame(['standar' => '0.00', 'uut' => '0.00', 'koreksi' => '0.00', 'u95' => '0.00', 'k' => '0'], $s->snapshot['hasil'][0]['format']);
        // `E21:S23` 2.00 | 1.97 | -0.03; 4.00 | 3.98 | -0.02; 10.00 | 9.99 | -0.01.
        $this->assertMatchesRegularExpression('#<td>2[.,]00</td>\s*<td>1[.,]97</td>\s*<td>-0[.,]03</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>4[.,]00</td>\s*<td>3[.,]98</td>\s*<td>-0[.,]02</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>10[.,]00</td>\s*<td>9[.,]99</td>\s*<td>-0[.,]01</td>#u', $html);
        // `Q24` 0.02.
        $this->assertMatchesRegularExpression('#<tr class="u95">\s*<td colspan="2">Uncertainty U<sub>95%</sub> = &plusmn;</td>\s*<td>0[.,]02 \S+</td>#u', $html);
    }

    /** @return array{0: Certificate, 1: string} */
    private function terbitkan(string $nomor): array
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
        $sertifikat = $sesi->certificate()->first();

        if ($sertifikat === null) {
            $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)
                ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
                ->assertOk();
            $sertifikat = $sesi->fresh()->certificate()->firstOrFail();
        }

        $html = view('sertifikat.pdf', [...app(DataTampilanSertifikat::class)->untuk($sertifikat), 'paksaPadat' => false])->render();

        return [$sertifikat, $html];
    }

    /** Halaman terkecil: normal dulu, padat kalau meluap — urutan `SertifikatSatuHalaman`. */
    private function halamanTerbaik(Certificate $sertifikat): int
    {
        $bahan = app(DataTampilanSertifikat::class)->untuk($sertifikat);
        $halaman = PHP_INT_MAX;

        foreach ([false, true] as $padat) {
            $pdf = Pdf::loadView('sertifikat.pdf', [...$bahan, 'paksaPadat' => $padat]);
            $pdf->output();
            $halaman = min($halaman, (int) $pdf->getDomPDF()->getCanvas()->get_page_count());

            if ($halaman === 1) {
                break;
            }
        }

        return $halaman;
    }
}
