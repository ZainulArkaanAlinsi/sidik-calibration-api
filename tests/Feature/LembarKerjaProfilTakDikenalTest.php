<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uji kontrak (S-5): `GET /calibrations/lembar-kerja?profil=ngawur` menjawab 200
 * dengan lembar pH — teknisi yang memegang kode usang mendapat lembar yang SALAH
 * tanpa tanda apa pun. Kode yang tidak dikenal sekarang 422 (`profil_tidak_dikenal`),
 * sejalan dengan `GET /equipments?profil=`. Tidak berubah: tanpa `profil` (bawaan pH),
 * `profil` yang dikenal, `equipment_id` (menang atas `profil`), dan klien lama yang
 * mengirim `instrumen`.
 */
class LembarKerjaProfilTakDikenalTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        $lab = Organization::factory()->create();
        $this->teknisi = User::factory()->create(['organization_id' => $lab->id]);
    }

    public function test_kode_profil_tak_dikenal_ditolak_422_bukan_lembar_ph(): void
    {
        $respons = $this->actingAs($this->teknisi)->getJson('/api/calibrations/lembar-kerja?profil=ngawur');

        $respons->assertUnprocessable()->assertJsonPath('kode', 'profil_tidak_dikenal');
        $this->assertStringContainsString('ngawur', (string) $respons->json('message'));
    }

    public function test_yang_sah_tetap_200(): void
    {
        $this->actingAs($this->teknisi)->getJson('/api/calibrations/lembar-kerja')->assertOk();
        $this->actingAs($this->teknisi)->getJson('/api/calibrations/lembar-kerja?profil=ph_meter')->assertOk();
        $this->actingAs($this->teknisi)->getJson('/api/calibrations/lembar-kerja?profil=tids')->assertOk();
        // Klien lama yang mengirim nama instrumen tidak terpengaruh.
        $this->actingAs($this->teknisi)->getJson('/api/calibrations/lembar-kerja?instrumen=Turbidimeter')->assertOk();
        $this->actingAs($this->teknisi)->getJson('/api/calibrations/lembar-kerja?profil=kode-lama&instrumen=Turbidimeter')->assertOk();
    }
}
