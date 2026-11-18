<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Services\PenomoranOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-1 uji E2E-QA 18 Nov 2026: order bernomor tertinggi bulan itu dihapus
 * (soft-delete), lalu `POST /orders` berikutnya 500 untuk SELALU — pencarian
 * nomor terakhir tidak melihat baris terhapus, padahal indeks unik
 * `(organization_id, nomor)` di database melihatnya. Nomor yang pernah terbit
 * di work order tidak boleh dipakai ulang, jadi nomor berikutnya harus
 * melompati nomor baris terhapus, bukan mendarat di atasnya.
 */
class PenomoranOrderSetelahHapusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $pelanggan;

    private Equipment $alat;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->pelanggan = Customer::factory()->create();
        EquipmentCategory::factory()->create(['kode' => 'panjang']);
        $this->alat = Equipment::factory()->create(['customer_id' => $this->pelanggan->id]);
        $this->prefix = 'ORD/'.now()->format('Y/m').'/';
    }

    private function buatOrder()
    {
        return $this->actingAs($this->admin)->postJson('/api/orders', [
            'customer_id' => $this->pelanggan->id,
            'tanggal_masuk' => now()->format('Y-m-d'),
            'items' => [['equipment_id' => $this->alat->id]],
        ]);
    }

    public function test_order_bisa_dibuat_lagi_sesudah_order_bernomor_tertinggi_dihapus(): void
    {
        $pertama = $this->buatOrder()->assertCreated();
        $this->assertSame($this->prefix.'0001', $pertama->json('data.nomor'));

        $this->actingAs($this->admin)->deleteJson('/api/orders/'.$pertama->json('data.id'))->assertOk();

        $kedua = $this->buatOrder()->assertCreated();

        // Nomor 0001 sudah pernah dipakai (baris terhapus tetap memegangnya),
        // jadi yang berikutnya 0002 — bukan 0001 lagi (500 duplikat).
        $this->assertSame($this->prefix.'0002', $kedua->json('data.nomor'));
    }

    public function test_hapus_lalu_buat_dua_kali_berturut_tetap_unik(): void
    {
        $nomor = [];

        foreach ([1, 2] as $putaran) {
            $order = $this->buatOrder()->assertCreated();
            $nomor[] = $order->json('data.nomor');

            $this->actingAs($this->admin)->deleteJson('/api/orders/'.$order->json('data.id'))->assertOk();
        }

        $ketiga = $this->buatOrder()->assertCreated();
        $nomor[] = $ketiga->json('data.nomor');

        $this->assertSame([
            $this->prefix.'0001',
            $this->prefix.'0002',
            $this->prefix.'0003',
        ], $nomor);
        $this->assertCount(3, array_unique($nomor));
    }

    public function test_nomor_yang_dilompati_tidak_menggeser_urutan_order_yang_masih_hidup(): void
    {
        $a = $this->buatOrder()->assertCreated();
        $b = $this->buatOrder()->assertCreated();
        $this->assertSame($this->prefix.'0002', $b->json('data.nomor'));

        // Yang terhapus nomor TENGAH-bawah (0001), bukan yang tertinggi: nomor
        // berikutnya tetap 0003, tidak mundur mengisi lubang.
        $this->actingAs($this->admin)->deleteJson('/api/orders/'.$a->json('data.id'))->assertOk();

        $c = $this->buatOrder()->assertCreated();

        $this->assertSame($this->prefix.'0003', $c->json('data.nomor'));
    }

    public function test_penomoran_hanya_melihat_organisasi_sendiri_walau_ada_baris_terhapus(): void
    {
        $lain = Organization::factory()->create();
        $pelangganLain = Customer::factory()->create(['organization_id' => $lain->id]);
        $terhapus = Order::factory()->create([
            'organization_id' => $lain->id,
            'customer_id' => $pelangganLain->id,
            'nomor' => $this->prefix.'0007',
        ]);
        $terhapus->delete();

        // Nomor tertinggi (terhapus) milik lab LAIN tidak boleh memengaruhi
        // lab ini: urutan tiap organisasi berdiri sendiri.
        $this->assertSame(
            $this->prefix.'0001',
            app(PenomoranOrder::class)->berikutnya($this->admin->organization_id),
        );
        $this->assertSame(
            $this->prefix.'0008',
            app(PenomoranOrder::class)->berikutnya($lain->id),
        );
    }
}
