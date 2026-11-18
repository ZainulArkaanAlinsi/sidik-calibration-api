<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\User;
use App\Support\MicrometerMentah;
use Database\Seeders\KemampuanKalibrasiSeeder;
use Database\Seeders\MetodeKalibrasiSeeder;
use Database\Seeders\MicrometerSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\ThermohygroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-3 uji E2E-QA 18 Nov 2026: tiap sesi Micrometer yang wajar memuntahkan
 * puluhan peringatan palsu `pembacaan_di_luar_rentang`, jadi approve SELALU 422
 * sampai admin mengirim `abaikan_peringatan`.
 *
 * Penyebabnya baris `mikro_balok` — nominal keping balok ukur (6, 14, 17, 19 mm)
 * yang BUKAN pembacaan UUT — diadu ke rentang ukur mikrometer (25–50 mm).
 * Hydrometer, Volumetric, Gaya, dan Piston Volume sudah dikecualikan lewat
 * `PERAN_BUKAN_BESARAN_ALAT`; Micrometer ketinggalan.
 *
 * Peringatan palsu yang selalu muncul melatih admin menekan "setujui tetap"
 * tanpa membaca (AGENTS.md §Aturan yang Lahir dari Kesalahan Nyata), jadi
 * pengecualiannya tidak boleh membutakan: pembacaan UUT yang SUNGGUHAN di luar
 * rentang tetap memperingatkan.
 */
class MicrometerValidasiBalokTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(Equipment $alat, array $titik): array
    {
        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2025-05-02',
            'suhu_awal' => 20.5, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 41, 'kelembaban_akhir' => 40,
            'measurements' => $titik,
            'spesifikasi_alat' => [
                'rentang_ukur' => '25-50', 'kapasitas' => '50', 'resolusi' => '0.001',
                MicrometerMentah::KUNCI_SESI => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => 50.0,
                    'resolusi_mm' => 0.001,
                    'pra_evaluasi' => [50.0, 50.0, 50.0, 49.999, 50.0, 50.0, 50.0, 50.001, 50.0, 50.0],
                ],
            ],
        ];
    }

    /**
     * Seeder secukupnya (bukan `DatabaseSeeder` penuh): organisasi, CMC, dan
     * mikrometer contoh `ZQ-100` (rentang 25–50 mm). Lembar sesinya dibuat
     * test lewat API, bukan dipakai dari seeder.
     *
     * @return array{Equipment, User, User}
     */
    private function siapkan(): array
    {
        $this->seed([
            OrganizationSeeder::class,
            MetodeKalibrasiSeeder::class,
            KemampuanKalibrasiSeeder::class,
            ThermohygroSeeder::class,
        ]);

        $teknisi = User::factory()->create(['organization_id' => 1]);
        $admin = User::factory()->admin()->create(['organization_id' => 1]);

        $this->seed(MicrometerSeeder::class);

        return [Equipment::where('serial_number', 'ZQ-100')->firstOrFail(), $teknisi, $admin];
    }

    /** @return list<array<string, mixed>> temuan `pembacaan_di_luar_rentang` pada sesi itu */
    private function temuanDiLuarRentang(User $admin, int $sesiId): array
    {
        $temuan = $this->actingAs($admin)
            ->getJson("/api/calibrations/{$sesiId}/validasi")
            ->assertOk()
            ->json('data.temuan');

        return array_values(array_filter(
            $temuan,
            static fn (array $t): bool => $t['kode'] === 'pembacaan_di_luar_rentang',
        ));
    }

    public function test_keping_balok_ukur_tidak_dilaporkan_di_luar_rentang(): void
    {
        [$alat, $teknisi, $admin] = $this->siapkan();

        // Tiga titik pertama kertas varian B (25,0 / 27,5 / 31,0 mm). Tumpukan
        // balok tiap titik diturunkan server (mis. titik 31,0 = 14 + 17 mm) —
        // semuanya DI BAWAH rentang ukur 25–50 mm dan jauh dari titik standar,
        // persis yang dulu dilaporkan sebagai "pembacaan 14 mm di luar rentang".
        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', $this->payload($alat, [
            ['titik_ukur' => 25.0, 'pembacaan' => [25.001, 25.0, 25.001, 25.0, 25.001]],
            ['titik_ukur' => 27.5, 'pembacaan' => [27.502, 27.501, 27.502, 27.501, 27.502]],
            ['titik_ukur' => 31.0, 'pembacaan' => [31.003, 31.002, 31.003, 31.002, 31.003]],
        ]))->assertSuccessful()->json('data.id');

        $this->assertSame(
            [],
            $this->temuanDiLuarRentang($admin, $id),
            'nominal keping balok ukur bukan pembacaan UUT, jangan diadu ke rentang alat',
        );
    }

    public function test_pembacaan_uut_sungguhan_di_luar_rentang_tetap_memperingatkan(): void
    {
        [$alat, $teknisi, $admin] = $this->siapkan();

        // Koma kegeser di pembacaan UUT ke-2 titik 25,0: 2,5001 bukan 25,001.
        // Itu kesalahan ketik yang memang mau ditangkap penjaga rentang — dan
        // tidak boleh ikut dibutakan oleh pengecualian balok.
        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', $this->payload($alat, [
            ['titik_ukur' => 25.0, 'pembacaan' => [25.001, 2.5001, 25.001, 25.0, 25.001]],
            ['titik_ukur' => 27.5, 'pembacaan' => [27.502, 27.501, 27.502, 27.501, 27.502]],
        ]))->assertSuccessful()->json('data.id');

        $temuan = $this->temuanDiLuarRentang($admin, $id);

        $this->assertCount(1, $temuan, 'tepat satu: pembacaan UUT yang komanya kegeser');
        $this->assertEqualsWithDelta(2.5001, (float) $temuan[0]['konteks']['nilai'], 1e-9);
        $this->assertSame(1, $temuan[0]['konteks']['titik_ke']);
    }
}
