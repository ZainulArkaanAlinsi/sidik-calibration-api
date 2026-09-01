<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/calibrations/lembar-kerja` tidak boleh membocorkan kalibrator lab
 * lain waktu `equipment_id` tidak dikirim.
 *
 * ## Cacat yang ditutup berkas ini
 *
 * `CalibrationProfile::masterStandarTertaut()` dan `masterThermohygro()`
 * menyaring organisasi lewat `$equipment`:
 *
 *     ->when($equipment?->organization_id !== null, fn ($q) => $q->where(...))
 *
 * `$equipment` null berarti syaratnya `false` — jadi saringannya **tidak
 * dipasang sama sekali**, dan yang pulang seluruh baris `standards` milik SEMUA
 * lab. Docblock-nya menyebut keadaan itu "sah" karena "yang dipakai cuma label
 * baris". Barisnya ternyata membawa lebih dari label.
 *
 * `equipment_id` di endpoint itu `sometimes`, jadi keadaan itu bukan teori:
 * layar teknisi memang membuka lembar sebelum alat pelanggan dipilih.
 *
 * Terukur sebelum perbaikan, teknisi lab 1 memanggil tanpa `equipment_id`:
 *
 *  - baris `usage_check` memuat `no_sertifikat`, `tertelusur_ke`, dan
 *    `serial_number` kalibrator milik lab 2; dan
 *  - dropdown "Environmental Meter Used" menawarkan `standard_id` milik lab 2
 *    sebagai pilihan yang bisa diklik. Yang kepilih masuk ke sesi, koreksi
 *    kondisi lingkungannya dibaca dari sertifikat lab itu, dan angkanya
 *    kecetak di sertifikat lab ini.
 *
 * Nol error di sepanjang jalur itu — yang terbit data lab lain di layar lab ini.
 */
class LembarKerjaTidakBocorLintasLabTest extends TestCase
{
    use RefreshDatabase;

    public function test_lembar_tanpa_equipment_id_tidak_memuat_kalibrator_lab_lain(): void
    {
        $this->seed(DatabaseSeeder::class);

        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();
        $labLain = Organization::factory()->create(['nama' => 'Lab Sebelah']);

        // Namanya sengaja PERSIS seperti yang tercetak di kop master Putaran —
        // pencocokannya lewat nama, jadi nama yang asal-asalan lolos begitu saja
        // dan bikin test ini hijau tanpa membuktikan apa pun.
        Standard::factory()->create([
            'organization_id' => $labLain->id,
            'nama' => 'Infrared Tachometer NK-300',
            'serial_number' => 'BOCOR-SN-1',
            'no_sertifikat' => 'BOCOR-SERT-999',
            'tertelusur_ke' => 'BOCOR-TERTELUSUR',
            'parameter_kondisi' => null,
        ]);

        $thermohygroLabLain = Standard::factory()->create([
            'organization_id' => $labLain->id,
            'nama' => 'TH-3',
            'no_sertifikat' => 'BOCOR-SERT-888',
            'parameter_kondisi' => 'suhu',
        ]);

        // Punya lab sendiri dihapus supaya kalau baris/pilihan itu tetap muncul,
        // yang muncul PASTI milik lab sebelah.
        Standard::where('organization_id', $teknisi->organization_id)
            ->where(fn ($q) => $q->where('nama', 'like', '%NK-300%')->orWhere('nama', 'TH-3'))
            ->delete();

        $respons = $this->actingAs($teknisi)
            ->getJson('/api/calibrations/lembar-kerja?instrumen=Centrifuge')
            ->assertOk();

        $isi = (string) $respons->getContent();

        foreach (['BOCOR-SN-1', 'BOCOR-SERT-999', 'BOCOR-TERTELUSUR', 'BOCOR-SERT-888'] as $jarum) {
            $this->assertStringNotContainsString(
                $jarum, $isi,
                "Lembar kerja lab 1 memuat `{$jarum}` — data kalibrator milik lab lain.",
            );
        }

        $pilihan = collect($respons->json('data.bagian') ?? [])
            ->flatMap(static fn (array $b): array => $b['field'] ?? [])
            ->firstWhere('kode', 'thermohygro_standard_id')['pilihan'] ?? [];

        $this->assertNotContains(
            (string) $thermohygroLabLain->id,
            array_column($pilihan, 'nilai'),
            'Dropdown "Environmental Meter Used" menawarkan thermohygro milik lab lain. '
            .'Yang kepilih masuk ke sesi, dan koreksi kondisi lingkungannya dibaca dari '
            .'sertifikat lab itu.',
        );
    }

    /**
     * Dan lembarnya tetap BERGUNA: pilihan lab sendiri tidak ikut hilang.
     *
     * Tanpa ini, "menyaring semuanya" jadi hijau — dan dropdown kosong artinya
     * kotak mati di layar teknisi.
     */
    public function test_pilihan_lab_sendiri_tetap_ada(): void
    {
        $this->seed(DatabaseSeeder::class);

        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        $pilihan = collect(
            $this->actingAs($teknisi)
                ->getJson('/api/calibrations/lembar-kerja?instrumen=Centrifuge')
                ->assertOk()
                ->json('data.bagian') ?? []
        )
            ->flatMap(static fn (array $b): array => $b['field'] ?? [])
            ->firstWhere('kode', 'thermohygro_standard_id')['pilihan'] ?? [];

        $this->assertNotEmpty($pilihan, 'Dropdown thermohygro pulang kosong — kotak mati di layar.');

        foreach ($pilihan as $p) {
            $this->assertSame(
                $teknisi->organization_id,
                Standard::findOrFail($p['nilai'])->organization_id,
                "Pilihan `{$p['label']}` menunjuk standar milik organisasi lain.",
            );
        }
    }
}
