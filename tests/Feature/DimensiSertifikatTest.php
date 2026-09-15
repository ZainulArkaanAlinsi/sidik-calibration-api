<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bentuk SERTIFIKAT Jangka Sorong & Sieve Mesh — dua hal yang tidak kelihatan
 * dari angka hitungannya:
 *
 *  1. **Jangka Sorong: tiga tabel, tiga U95.** Outside & Inside memakai nominal
 *     yang sama, jadi remark dari nominal saja menaruh baris Inside di kelompok
 *     Outside dan U95 Outside tercetak untuk titik Inside. Dikelompokkan lewat
 *     `titik_ke` (`remarkTitikKe`), dengan judul persis `SERTIFIKAT!D22/D38/D54`.
 *  2. **Sieve: tanda `Correction` ikut master** (`K20 = H20 − E20`, terukur −
 *     nominal), label baris `Wrap (x')`/`Weft (y')`/`Wire Diameter (Ø)`, dan
 *     kolom UUT berjudul `Standard Indication`.
 */
class DimensiSertifikatTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> snapshot sertifikat */
    private function terbitkan(string $nomorSesi): array
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();

        $this->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail()->snapshot;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
    }

    public function test_jangka_sorong_tiga_kelompok_dengan_u95_masing_masing(): void
    {
        $snapshot = $this->terbitkan('001-CAL-126');
        $kelompok = collect($snapshot['hasil'])->groupBy('remark');

        $this->assertSame(
            [
                'Pengukuran Luar (Outside Measurement)',
                'Pengukuran Dalam (Inside Measurement)',
                '<50 mm Kedalaman (Depth Measurement)',
            ],
            $kelompok->keys()->all(),
        );

        foreach ($kelompok as $judul => $baris) {
            $this->assertCount(1, $baris->pluck('u95')->unique(), "{$judul}: U95 satu per tabel");
        }

        // Di luar 0-300 mm: sertifikat terbit TANPA klaim akreditasi.
        $this->assertFalse($snapshot['meta']['organization']['dalam_lingkup_akreditasi']);
        $this->assertNull($snapshot['meta']['organization']['no_akreditasi']);
    }

    public function test_sieve_label_baris_judul_kolom_dan_tanda_koreksi_ikut_master(): void
    {
        $snapshot = $this->terbitkan('0736-CAL-526');

        $this->assertSame('Standard Indication', $snapshot['judul_uut']);
        $this->assertSame(
            ["Wrap (x')", "Weft (y')", 'Wire Diameter (Ø)'],
            array_column($snapshot['hasil'], 'remark'),
        );

        foreach ($snapshot['hasil'] as $b) {
            // Master: Correction = Standard Indication − Nominal.
            $this->assertEqualsWithDelta(
                $b['unit_under_test'] - $b['standard_value'],
                $b['correction'],
                1e-9,
                "{$b['remark']}: tanda Correction berlawanan dengan sertifikat master",
            );
        }

        // Master mencetak koreksi POSITIF untuk ketiganya (K20:K22 = 0,139 / 0,183 / 0,226).
        $this->assertGreaterThan(0, $snapshot['hasil'][0]['correction']);
        $this->assertTrue($snapshot['meta']['organization']['dalam_lingkup_akreditasi']);
    }

    public function test_alat_lain_tidak_berubah_tanda_maupun_kelompok(): void
    {
        $snapshot = $this->terbitkan('0392-CAL-324');

        foreach ($snapshot['hasil'] as $b) {
            $this->assertNull($b['remark']);
            $this->assertEqualsWithDelta($b['standard_value'] - $b['unit_under_test'], $b['correction'], 1e-6);
        }
    }
}
