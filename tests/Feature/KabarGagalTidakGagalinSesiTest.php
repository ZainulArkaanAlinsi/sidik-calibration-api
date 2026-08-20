<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Kabar (notifikasi & siaran) yang gagal NGGAK boleh bikin pengiriman lembar
 * kerja gagal.
 *
 * ## Kejadian yang dikunci di sini
 *
 * 20 Agu 2026: migrasi `device_tokens` ketinggalan jalan di satu mesin.
 * `SaluranPush` query tabel itu tanpa penjaga, exception-nya naik lewat
 * `$user->notify()` sampai ke respons, dan SETIAP pengiriman lembar kerja —
 * sembilan alat sekaligus — balik HTTP 500. Sesinya sebenernya UDAH kesimpen
 * (notifikasi dikirim sesudah transaksinya commit), tapi dari layar teknisi
 * kelihatan gagal total, dan yang kejadian berikutnya dia ngetik ulang
 * semuanya dari lapangan.
 *
 * Dua saluran, dua sebab, satu akibat — dua-duanya dikunci:
 *
 *  - **push**: butuh tabel `device_tokens`.
 *  - **siaran**: `PerubahanDataOrganisasi` itu `ShouldBroadcastNow` alias
 *    SINKRON, jadi server Reverb yang mati bikin exception yang sama persis.
 *
 * Yang diuji BUKAN kabarnya sampai — tapi kerjaan teknisinya selamat.
 */
class KabarGagalTidakGagalinSesiTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create(['id' => 1]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
        ]);

        // Admin aktif WAJIB ada: `kabarinAdmin()` cuma jalan kalau ada yang
        // dikabarin. Tanpa baris ini test-nya lulus tanpa nyentuh jalur push
        // sama sekali.
        User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);

        $customer = Customer::factory()->create(['organization_id' => $org->id]);
        $category = EquipmentCategory::factory()->create(['organization_id' => $org->id]);
        $this->alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $customer->id,
            'equipment_category_id' => $category->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
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
    }

    public function test_lembar_kerja_tetap_kesimpen_walau_tabel_push_nggak_ada(): void
    {
        Schema::drop('device_tokens');

        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->status);
        $this->assertNotNull(
            $sesi->hasil_autoclave,
            'Hasil hitungnya harus utuh — kabar yang gagal nggak boleh nyentuh datanya.',
        );
    }

    public function test_lembar_kerja_tetap_kesimpen_walau_siaran_meledak(): void
    {
        // Driver siaran yang nggak ada = exception waktu dispatch, persis
        // kayak server Reverb yang lagi mati.
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'x',
            'secret' => 'x',
            'app_id' => 'x',
            'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http'],
            'client_options' => ['timeout' => 1],
        ]]);

        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            CalibrationSession::findOrFail($id)->status,
        );
    }
}
