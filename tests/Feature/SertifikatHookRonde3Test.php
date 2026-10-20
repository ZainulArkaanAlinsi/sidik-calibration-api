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
 * K-50-15 putaran 3: hook profil yang menyambung mekanisme template baru
 * (`satuanTitikSesi`/`remarkTitikSesi`, `kolom_hasil.pecah`, `kunciGabungTitik`,
 * `tambahan:`, `faktor`, `desimalKelembabanEnvSesi`) — semuanya CETAK saja.
 * Sesi contoh seeder diterbitkan dan dirender sungguhan; satu seeder per test.
 */
class SertifikatHookRonde3Test extends TestCase
{
    use RefreshDatabase;

    /**
     * Thermohygro `154`: seksi `1. Temperature` (`E19`) dan `2. Humidity`
     * (`E32`) masing-masing tabel sendiri, satuan `(oC)` / `(%RH)`, UUT suhu
     * `0.0` (`K22`) dan %RH `0` (`K35`).
     */
    public function test_thermohygro_seksi_humidity_terpisah_bersatuan_rh(): void
    {
        $this->siap();
        [$s, $html] = $this->terbitkan('0312-CAL-624');
        $hasil = $s->snapshot['hasil'];

        $suhu = array_values(array_filter($hasil, static fn (array $b): bool => $b['satuan'] === '°C'));
        $rh = array_values(array_filter($hasil, static fn (array $b): bool => $b['satuan'] === '%RH'));
        $this->assertCount(5, $suhu);
        $this->assertCount(5, $rh);

        foreach ($suhu as $b) {
            $this->assertSame('1. Temperature', $b['remark']);
            $this->assertSame('0.0', $b['format']['uut']);
        }

        foreach ($rh as $b) {
            $this->assertSame('2. Humidity', $b['remark']);
            $this->assertSame('0', $b['format']['uut']);
            $this->assertSame('0.00', $b['format']['standar']);
        }

        $this->assertLessThan(strpos($html, '2. Humidity'), strpos($html, '1. Temperature'));
        $humidity = substr($html, (int) strpos($html, '2. Humidity'));
        $this->assertMatchesRegularExpression('#<tr class="satuan">\s*<th>%RH</th>#u', $humidity);
        // UUT %RH tanpa desimal (`K35` `0`), contoh baris 30 %RH: 29.
        $this->assertMatchesRegularExpression('#<td>29\.\d\d</td>\s*<td>29</td>#u', $humidity);
        $this->assertSame(1, $this->halamanTerbaik($s));
    }

    private function siap(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
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
