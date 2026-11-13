<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kata cari (`q` / `search`) pada rute lab yang sudah ada sebelum pencarian
 * terpadu, dan urutan pencarian alat.
 *
 * - Larik (`q[]=x`) dulu jatuh jadi 500 di arsip dan alat; sekarang 422 seperti
 *   Telusuri, Riwayat, dan garis waktu.
 * - Batas bawah TIDAK dipasang di `GET /equipments?search` dan
 *   `GET /arsip/berkas?q`: aplikasi lab yang sudah terpasang mengirim `search=`
 *   mulai dari satu huruf, dan itu tidak boleh menjadi 422. (Rute BARU --
 *   Riwayat dan garis waktu -- memasang `min:2`; tesnya di kelas masing-masing.)
 * - `urut=jatuh_tempo` menang atas peringkat pencarian; peringkat hanya
 *   memutuskan yang tanggal jatuh temponya sama.
 */
class KataCariValidasiDanUrutanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Customer $pt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->pt = Customer::factory()->create(['organization_id' => $this->org->id, 'nama' => 'PT Uji Satu']);
    }

    private function alat(string $serial, string $jatuhTempo): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->pt->id,
            'serial_number' => $serial,
            'tanggal_jatuh_tempo' => $jatuhTempo,
        ]);
    }

    /** @return list<string> */
    private function serial(string $query): array
    {
        return collect($this->actingAs($this->admin)->getJson("/api/equipments?{$query}")->assertOk()->json('data'))
            ->pluck('serial_number')->all();
    }

    public function test_larik_dan_teks_kepanjangan_dijawab_422_bukan_500(): void
    {
        foreach ([
            '/api/equipments?search[]=x',
            '/api/equipments?search='.str_repeat('a', 101),
            '/api/arsip/berkas?q[]=x',
            '/api/arsip/berkas?q='.str_repeat('a', 101),
        ] as $uri) {
            $this->actingAs($this->admin)->getJson($uri)->assertStatus(422);
        }
    }

    public function test_satu_huruf_tetap_diterima_di_rute_lama_demi_klien_yang_terpasang(): void
    {
        $this->alat('A-1', '2027-01-01');

        $this->actingAs($this->admin)->getJson('/api/equipments?search=A')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/arsip/berkas?q=A')->assertOk();
    }

    public function test_tanpa_urut_peringkat_mendahului_id(): void
    {
        $this->alat('AXYZ-9', '2027-01-01'); // mengandung
        $this->alat('XYZ-1', '2027-01-01');  // awalan
        $this->alat('XYZ', '2027-03-01');    // persis

        $this->assertSame(['XYZ', 'XYZ-1', 'AXYZ-9'], $this->serial('search=XYZ'));
    }

    public function test_urut_jatuh_tempo_menang_dan_peringkat_hanya_memutuskan_tanggal_yang_sama(): void
    {
        // id naik: mengandung (A), awalan (C), persis (B).
        $this->alat('AXYZ-9', '2027-01-01');
        $this->alat('XYZ-1', '2027-01-01');
        $this->alat('XYZ', '2027-03-01');
        // Tanpa tanggal jadwal: tetap di dasar, apa pun peringkatnya.
        $tanpaJadwal = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->pt->id,
            'serial_number' => 'XYZ-TANPA',
        ]);
        $tanpaJadwal->forceFill(['tanggal_jatuh_tempo' => null])->save();

        // Tanggal 2027-01-01 dulu (peringkat awalan sebelum mengandung walau
        // id-nya lebih besar), baru 2027-03-01 yang persis, lalu tanpa jadwal.
        $this->assertSame(
            ['XYZ-1', 'AXYZ-9', 'XYZ', 'XYZ-TANPA'],
            $this->serial('search=XYZ&urut=jatuh_tempo'),
        );
    }

    public function test_urut_jatuh_tempo_tanpa_pencarian_tidak_berubah(): void
    {
        $this->alat('S-3', '2027-03-01');
        $this->alat('S-1', '2027-01-01');
        $this->alat('S-2', '2027-01-01');

        // Tanggal naik, lalu id naik -- persis perilaku sebelum pencarian terpadu.
        $this->assertSame(['S-1', 'S-2', 'S-3'], $this->serial('urut=jatuh_tempo'));
    }
}
