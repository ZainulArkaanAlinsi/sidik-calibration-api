<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Order kalibrasi = satu pengiriman alat dari pelanggan, dicatat di meja
 * penerimaan. Keputusan yang dikunci test ini: SATU ORDER BOLEH BANYAK ALAT,
 * dan daftar alatnya berdiri sendiri (nggak diturunin dari sesi kalibrasi),
 * biar work order bisa dicetak sebelum kalibrasinya mulai.
 */
class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->pelanggan = Customer::factory()->create(['nama' => 'PT Maju Jaya']);
        EquipmentCategory::factory()->create(['kode' => 'panjang']);
    }

    public function test_satu_order_bisa_banyak_alat(): void
    {
        $alat = Equipment::factory()->count(3)->create(['customer_id' => $this->pelanggan->id]);

        $response = $this->actingAs($this->admin)->postJson('/api/orders', [
            'customer_id' => $this->pelanggan->id,
            'tanggal_masuk' => '2026-07-20',
            'items' => $alat->map(fn (Equipment $a) => ['equipment_id' => $a->id])->all(),
        ])->assertCreated();

        // Klien nganter 3 alat sekaligus -> tetap satu order, bukan tiga.
        $this->assertCount(3, $response->json('data.items'));
        $this->assertSame(3, $response->json('data.jumlah_alat'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_nomor_order_dibikin_backend_dan_urut(): void
    {
        $alat = Equipment::factory()->create();

        $bikin = fn () => $this->actingAs($this->admin)->postJson('/api/orders', [
            'customer_id' => $this->pelanggan->id,
            'tanggal_masuk' => '2026-07-20',
            // Nomor yang dikirim client harus DIABAIKAN — kalau nurut, urutannya
            // bolong dan gampang dobel.
            'nomor' => 'NGAWUR/999',
            'items' => [['equipment_id' => $alat->id]],
        ])->assertCreated();

        $prefix = 'ORD/'.now()->format('Y/m').'/';

        $this->assertSame($prefix.'0001', $bikin()->json('data.nomor'));
        $this->assertSame($prefix.'0002', $bikin()->json('data.nomor'));
    }

    public function test_order_tanpa_alat_ditolak(): void
    {
        // Order kosong nggak ada gunanya — work order-nya nggak ada isinya.
        $this->actingAs($this->admin)->postJson('/api/orders', [
            'customer_id' => $this->pelanggan->id,
            'tanggal_masuk' => '2026-07-20',
            'items' => [],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_alat_dan_pelanggan_dari_organisasi_lain_ditolak(): void
    {
        $ptLain = Organization::factory()->create();
        $pelangganLain = Customer::factory()->create(['organization_id' => $ptLain->id]);
        $alatLain = Equipment::factory()->create(['organization_id' => $ptLain->id]);

        // Tanpa penjaga ini, order bisa dibikin atas nama pelanggan PT lain
        // modal nebak ID.
        $this->actingAs($this->admin)->postJson('/api/orders', [
            'customer_id' => $pelangganLain->id,
            'tanggal_masuk' => '2026-07-20',
            'items' => [['equipment_id' => $alatLain->id]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id', 'items.0.equipment_id']);
    }

    public function test_order_punya_organisasi_lain_nggak_kelihatan(): void
    {
        $ptLain = Organization::factory()->create();
        $orderLain = Order::factory()->create(['organization_id' => $ptLain->id]);

        $this->actingAs($this->admin)->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->admin)->getJson("/api/orders/{$orderLain->id}")->assertNotFound();
    }

    public function test_update_status_nggak_ngosongin_daftar_alat(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->count(2)->create(['order_id' => $order->id]);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", [
            'status' => Order::STATUS_DIPROSES,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_DIPROSES)
            // `items` nggak dikirim -> jangan disentuh sama sekali.
            ->assertJsonCount(2, 'data.items');
    }

    public function test_alat_dobel_di_satu_order_ditolak(): void
    {
        $alat = Equipment::factory()->create();

        // Selalu salah input, dan bikin work order nampilin baris dobel.
        $this->expectException(QueryException::class);

        $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id, 'equipment_id' => $alat->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'equipment_id' => $alat->id]);
    }

    /**
     * Sesi & sertifikat nunjuk ke order lewat item. Hapus ordernya = mutusin
     * jejak yang justru dicari asesor waktu audit.
     */
    public function test_order_yang_alatnya_udah_dikalibrasi_nggak_boleh_dihapus(): void
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->create(['order_id' => $order->id]);

        CalibrationSession::create([
            'organization_id' => $this->admin->organization_id,
            'equipment_id' => $item->equipment_id,
            'order_item_id' => $item->id,
            'teknisi_id' => $this->admin->id,
            'status' => CalibrationSession::STATUS_DISETUJUI,
            'tanggal_kalibrasi' => now(),
        ]);

        $this->actingAs($this->admin)->deleteJson("/api/orders/{$order->id}")
            ->assertStatus(422);

        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);

        // Jalan keluarnya: dibatalin, bukan dihapus.
        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", [
            'status' => Order::STATUS_DIBATALKAN,
        ])->assertOk();
    }

    public function test_order_yang_belum_dikalibrasi_boleh_dihapus(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->actingAs($this->admin)->deleteJson("/api/orders/{$order->id}")->assertOk();
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_teknisi_boleh_baca_order_tapi_nggak_boleh_nulis(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);
        Order::factory()->create();

        // Teknisi butuh lihat alat apa aja yang masuk dan harus dikerjain.
        $this->actingAs($teknisi)->getJson('/api/orders')->assertOk();

        $this->actingAs($teknisi)->postJson('/api/orders', [
            'customer_id' => $this->pelanggan->id,
            'tanggal_masuk' => '2026-07-20',
            'items' => [['equipment_id' => Equipment::factory()->create()->id]],
        ])->assertForbidden();
    }

    public function test_tanggal_janji_selesai_sebelum_tanggal_masuk_ditolak(): void
    {
        $this->actingAs($this->admin)->postJson('/api/orders', [
            'customer_id' => $this->pelanggan->id,
            'tanggal_masuk' => '2026-07-20',
            'tanggal_janji_selesai' => '2026-07-10',
            'items' => [['equipment_id' => Equipment::factory()->create()->id]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal_janji_selesai');
    }

    public function test_teknisi_bisa_ditugaskan_per_alat_bukan_per_order(): void
    {
        $andi = User::factory()->create(['role' => User::ROLE_TEKNISI, 'name' => 'Andi']);
        $budi = User::factory()->create(['role' => User::ROLE_TEKNISI, 'name' => 'Budi']);
        $alat = Equipment::factory()->count(2)->create();

        // Satu pengiriman, dua alat, dua orang berbeda — ini yang nggak bisa
        // diwakili kalau penugasannya nempel di order.
        $response = $this->actingAs($this->admin)->postJson('/api/orders', [
            'customer_id' => $this->pelanggan->id,
            'tanggal_masuk' => '2026-07-20',
            'items' => [
                ['equipment_id' => $alat[0]->id, 'teknisi_id' => $andi->id],
                ['equipment_id' => $alat[1]->id, 'teknisi_id' => $budi->id],
            ],
        ])->assertCreated();

        $nama = collect($response->json('data.items'))->pluck('teknisi.nama')->sort()->values()->all();
        $this->assertSame(['Andi', 'Budi'], $nama);
    }

    public function test_penugasan_bisa_diubah_tanpa_ngirim_ulang_seluruh_order(): void
    {
        $andi = User::factory()->create(['role' => User::ROLE_TEKNISI, 'name' => 'Andi']);
        $order = Order::factory()->create();
        $item = OrderItem::factory()->create(['order_id' => $order->id]);
        $lain = OrderItem::factory()->create(['order_id' => $order->id, 'teknisi_id' => $andi->id]);

        $response = $this->actingAs($this->admin)->postJson("/api/orders/{$order->id}/penugasan", [
            'penugasan' => [
                ['item_id' => $item->id, 'teknisi_id' => $andi->id],
                // null = lepas penugasan, bukan diabaikan.
                ['item_id' => $lain->id, 'teknisi_id' => null],
            ],
        ])->assertOk();

        $this->assertSame($andi->id, $item->fresh()->teknisi_id);
        $this->assertNull($lain->fresh()->teknisi_id);

        // Data penerimaan nggak boleh ikut kesenggol.
        $this->assertNotNull($item->fresh()->kondisi_terima);
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_yang_ditugaskan_harus_teknisi_aktif_seorganisasi(): void
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->create(['order_id' => $order->id]);

        $kandidat = [
            // Admin — bukan teknisi.
            User::factory()->admin()->create()->id,
            // Teknisi tapi nonaktif: kerjaan bakal nyangkut, nggak ada yang ngerjain.
            User::factory()->create(['role' => User::ROLE_TEKNISI, 'status' => User::STATUS_NONAKTIF])->id,
            // Teknisi PT lain.
            User::factory()->create([
                'role' => User::ROLE_TEKNISI,
                'organization_id' => Organization::factory()->create()->id,
            ])->id,
        ];

        foreach ($kandidat as $id) {
            $this->actingAs($this->admin)->postJson("/api/orders/{$order->id}/penugasan", [
                'penugasan' => [['item_id' => $item->id, 'teknisi_id' => $id]],
            ])->assertStatus(422);
        }

        $this->assertNull($item->fresh()->teknisi_id);
    }

    public function test_penugasan_nggak_bisa_nyentuh_item_order_lain(): void
    {
        $order = Order::factory()->create();
        $orderLain = Order::factory()->create();
        $itemOrderLain = OrderItem::factory()->create(['order_id' => $orderLain->id]);
        $andi = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        $this->actingAs($this->admin)->postJson("/api/orders/{$order->id}/penugasan", [
            'penugasan' => [['item_id' => $itemOrderLain->id, 'teknisi_id' => $andi->id]],
        ])->assertStatus(422);

        $this->assertNull($itemOrderLain->fresh()->teknisi_id);
    }

    /** Layar "Tugas Saya" — mobile nggak perlu tau ID-nya sendiri. */
    public function test_filter_teknisi_saya_balikin_antrean_sendiri(): void
    {
        $andi = User::factory()->create(['role' => User::ROLE_TEKNISI]);
        $budi = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        $punyaAndi = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $punyaAndi->id, 'teknisi_id' => $andi->id]);

        $punyaBudi = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $punyaBudi->id, 'teknisi_id' => $budi->id]);

        $this->actingAs($andi)->getJson('/api/orders?teknisi_id=saya')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nomor', $punyaAndi->nomor);
    }

    public function test_cari_order_lewat_nomor_atau_nama_pelanggan(): void
    {
        Order::factory()->create(['customer_id' => $this->pelanggan->id]);
        $lain = Customer::factory()->create(['nama' => 'CV Sentosa']);
        Order::factory()->create(['customer_id' => $lain->id]);

        // Petugas depan seringnya cuma inget nama pelanggannya.
        $this->actingAs($this->admin)->getJson('/api/orders?search=Maju')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pelanggan.nama', 'PT Maju Jaya');
    }
}
