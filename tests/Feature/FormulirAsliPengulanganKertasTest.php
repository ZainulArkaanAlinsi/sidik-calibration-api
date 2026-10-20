<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ocr\FormulirAsli;
use App\Support\AnakTimbanganMentah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keputusan 20 Okt 2026: kertas ASLI tanpa `jumlah_pengulangan` memakai jumlah
 * repeat MAKSIMUM yang tercetak di peta. Anak Timbangan 0541 Rev.0 mencetak
 * X1–X3 per baris Standard/UUT/UUT/Standard × 10 blok = 120 sel, sedangkan
 * bawaan profil 1 (`AnakTimbanganMentah::PENGULANGAN_KERTAS`). HP tidak mengirim
 * `jumlah_pengulangan`, jadi dulu irisan profil × peta membuang 80 sel diam-diam.
 *
 * `GET /worksheet-templates/{kode}?kertas=asli` dan `POST /worksheet-scans`
 * (keduanya lewat `FormulirAsli::cari`) harus sepakat. Perhitungannya tidak
 * berubah: tiga pembacaan per peran dirata-ratakan server — dijaga
 * `AlurPenuhAnakTimbanganTest`.
 */
class FormulirAsliPengulanganKertasTest extends TestCase
{
    use RefreshDatabase;

    private const KODE = 'SIDIK-FM-CAL-0541';

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $this->alat = new Equipment(['organization_id' => $org->id]);
    }

    public function test_tanpa_jumlah_pengulangan_memakai_repeat_maksimum_peta(): void
    {
        $template = app(FormulirAsli::class)->cari('anak_timbangan', $this->alat, null, self::KODE);

        $this->assertIsArray($template);
        $this->assertCount(120, $template['sel']);
        $this->assertSame(3, $template['jumlah_pengulangan']);
        $this->assertSame([1, 2, 3], array_values(array_unique(array_column($template['sel'], 'repeat_no'))));
        // Konstanta perhitungan tidak ikut digeser.
        $this->assertSame(1, AnakTimbanganMentah::PENGULANGAN_KERTAS);
    }

    public function test_jalur_pindai_tanpa_kode_dokumen_ikut_sepakat(): void
    {
        // `WorksheetScanController::store` memanggil `untukKode(kode, alat, jp)`
        // tanpa kode dokumen.
        $this->assertCount(120, app(FormulirAsli::class)->untukKode('anak_timbangan', $this->alat)['sel']);
    }

    public function test_jumlah_pengulangan_satu_tetap_empat_puluh(): void
    {
        $template = app(FormulirAsli::class)->cari('anak_timbangan', $this->alat, 1, self::KODE);

        $this->assertIsArray($template);
        $this->assertCount(40, $template['sel']);
        $this->assertSame(1, $template['jumlah_pengulangan']);
    }

    public function test_jumlah_pengulangan_dua_dihormati(): void
    {
        $this->assertCount(80, app(FormulirAsli::class)->cari('anak_timbangan', $this->alat, 2, self::KODE)['sel']);
    }

    public function test_endpoint_template_kertas_asli_tanpa_jumlah_pengulangan_120_sel(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        $this->actingAs($teknisi)
            ->getJson('/api/worksheet-templates/anak_timbangan?kertas=asli&kode_dokumen='.self::KODE)
            ->assertOk()
            ->assertJsonPath('data.jumlah_pengulangan', 3)
            ->assertJsonCount(120, 'data.sel');

        $this->actingAs($teknisi)
            ->getJson('/api/worksheet-templates/anak_timbangan?kertas=asli&jumlah_pengulangan=2&kode_dokumen='.self::KODE)
            ->assertOk()
            ->assertJsonCount(80, 'data.sel');
    }
}
