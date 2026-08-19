<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur API Autoklaf: lembar kerja (bentuknya) + preview olah data (angkanya).
 *
 * `AutoclaveCalculatorTest` ngadu ANGKA murni ke master; yang ini mastiin
 * angka yang sama nyampe lewat HTTP + auth + `config/autoclave.php` (tabel
 * kalibrator server-side), bukan cuma dari objek yang dirakit di test.
 */
class AutoclaveApiTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create(['id' => 1]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
    }

    public function test_lembar_kerja_autoclave_punya_matriks_suhu_dan_tabel_tekanan(): void
    {
        $data = $this->actingAs($this->teknisi, 'sanctum')
            ->getJson('/api/calibrations/lembar-kerja?profil=autoclave')
            ->assertOk()
            ->json('data');

        $this->assertSame('Calibration Worksheet - Autoclave', $data['judul']);
        $this->assertSame(3, $data['jumlah_disk']);

        $bagian = collect($data['bagian']);
        $hasilSuhu = $bagian->firstWhere('kode', 'hasil_suhu');
        $this->assertNotNull($hasilSuhu, 'Ada section hasil_suhu.');
        // 3 disk + Indikator + Suhu Ruang = 5 baris matriks.
        $this->assertCount(5, $hasilSuhu['matriks_suhu']['baris']);

        $hasilTekanan = $bagian->firstWhere('kode', 'hasil_tekanan');
        $this->assertNotNull($hasilTekanan, 'Ada section hasil_tekanan.');
        $this->assertSame('Bar', $hasilTekanan['tabel_tekanan']['kolom']['satuan']);
    }

    public function test_preview_reproduksi_angka_master(): void
    {
        $payload = [
            'set_point' => 121.0,
            'suhu' => [
                'disk' => [
                    [121.27, 121.26, 121.26, 121.26, 121.28],
                    [121.30, 121.26, 121.26, 121.25, 121.25],
                    [121.26, 121.26, 121.28, 121.35, 121.28],
                ],
                'indikator' => [121, 121, 121, 121, 121],
                'suhu_ruang' => [25, 25, 25, 25, 25],
            ],
            'tekanan' => [
                'uut_setting' => 0.112,
                'satuan' => 'MPa',
                'display' => 'Digital',
                'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
            ],
        ];

        $data = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave/preview', $payload)
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(0.4419439029528431, $data['suhu']['u95'], 1e-9);
        $this->assertEqualsWithDelta(1.9713602363081708, $data['suhu']['k'], 1e-9);
        $this->assertEqualsWithDelta(0.045, $data['suhu']['kestabilan'], 1e-9);
        $this->assertEqualsWithDelta(0.464, $data['suhu']['keseragaman'], 1e-9);
        $this->assertEqualsWithDelta(0.0059, $data['tekanan']['u95'], 1e-9);
        $this->assertEqualsWithDelta(0.0111, $data['tekanan']['koreksi'], 1e-9);
    }

    public function test_preview_wajib_set_point(): void
    {
        $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave/preview', ['suhu' => ['disk' => [[121.0]]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('set_point');
    }
}
