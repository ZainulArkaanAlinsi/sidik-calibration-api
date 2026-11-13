<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Order tidak boleh memuat alat milik PELANGGAN LAIN (13 Nov 2026).
 *
 * `OrderTest` sudah menjaga batas antar LAB. Yang dijaga di sini batas antar
 * PELANGGAN di lab yang SAMA: alat PT B masuk order PT A bikin `/paket` PT A
 * menampilkan nama + nomor seri alat PT B (ISO/IEC 17025 klausul 4.2), dan tidak
 * ada error di mana pun yang memberitahu. Semua kasus di sini satu organisasi.
 */
class OrderAlatLintasPelangganTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $ptA;

    private Customer $ptB;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->ptA = Customer::factory()->create(['nama' => 'PT Contoh A']);
        $this->ptB = Customer::factory()->create(['nama' => 'PT Contoh B']);
    }

    private function alat(Customer $pemilik, array $isi = []): Equipment
    {
        return Equipment::factory()->create(['customer_id' => $pemilik->id, ...$isi]);
    }

    /** @param  array<int, Equipment>  $alat */
    private function orderBaru(Customer $pemilik, array $alat): Order
    {
        $order = Order::factory()->create(['customer_id' => $pemilik->id]);

        foreach ($alat as $a) {
            OrderItem::factory()->create(['order_id' => $order->id, 'equipment_id' => $a->id]);
        }

        return $order;
    }

    private function bikin(Customer $untuk, array $alat)
    {
        return $this->actingAs($this->admin)->postJson('/api/orders', [
            'customer_id' => $untuk->id,
            'tanggal_masuk' => '2026-11-13',
            'items' => collect($alat)->map(fn (Equipment $a) => ['equipment_id' => $a->id])->values()->all(),
        ]);
    }

    /** @return list<int> */
    private function idAlatDiOrder(Order $order): array
    {
        return OrderItem::query()->where('order_id', $order->id)->orderBy('id')->pluck('equipment_id')->all();
    }

    // --- buat ---------------------------------------------------------------

    public function test_buat_order_dengan_alat_pelanggan_lain_ditolak_422_dan_tidak_ada_yang_tersimpan(): void
    {
        $milikB = $this->alat($this->ptB, ['nama_alat' => 'Termometer Milik B', 'serial_number' => 'SN-B-001']);

        $respons = $this->bikin($this->ptA, [$milikB])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.equipment_id');

        // Kunci galatnya mengandung titik, jadi dibaca dari array — bukan lewat `assertJsonPath`.
        $pesan = $respons->json('errors')['items.0.equipment_id'][0];
        $this->assertStringContainsString('pelanggan lain', $pesan);
        $this->assertStringContainsString('Termometer Milik B', $pesan);
        $this->assertStringContainsString('SN-B-001', $pesan);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_satu_alat_asing_di_antara_alat_sendiri_menolak_seluruh_order_dan_menunjuk_barisnya(): void
    {
        $sendiri = $this->alat($this->ptA);
        $asing = $this->alat($this->ptB);

        $respons = $this->bikin($this->ptA, [$sendiri, $asing])->assertStatus(422);

        $this->assertSame(['items.1.equipment_id'], array_keys($respons->json('errors')));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_buat_order_dengan_alat_milik_pelanggan_order_itu_sendiri_tetap_berhasil(): void
    {
        $alat = [$this->alat($this->ptA), $this->alat($this->ptA)];

        $this->bikin($this->ptA, $alat)->assertCreated()->assertJsonCount(2, 'data.items');
    }

    // --- ubah ---------------------------------------------------------------

    public function test_ubah_isi_order_dengan_alat_pelanggan_lain_ditolak_dan_isi_lama_utuh(): void
    {
        $sendiri = $this->alat($this->ptA);
        $order = $this->orderBaru($this->ptA, [$sendiri]);
        $asing = $this->alat($this->ptB);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", [
            'items' => [['equipment_id' => $asing->id]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.equipment_id');

        $this->assertSame([$sendiri->id], $this->idAlatDiOrder($order));
    }

    public function test_ubah_isi_order_dengan_alat_sendiri_tetap_berhasil(): void
    {
        $order = $this->orderBaru($this->ptA, [$this->alat($this->ptA)]);
        $baru = $this->alat($this->ptA);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", [
            'items' => [['equipment_id' => $baru->id]],
        ])->assertOk();

        $this->assertSame([$baru->id], $this->idAlatDiOrder($order));
    }

    public function test_ganti_pelanggan_order_saja_ditolak_bila_alat_yang_ada_milik_pelanggan_lama(): void
    {
        // Celah yang sama lewat pintu lain: `items` tidak disentuh sama sekali.
        $sendiri = $this->alat($this->ptA);
        $order = $this->orderBaru($this->ptA, [$sendiri]);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", [
            'customer_id' => $this->ptB->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');

        $this->assertSame($this->ptA->id, $order->fresh()->customer_id);
    }

    public function test_ganti_pelanggan_beserta_alat_pelanggan_baru_berhasil(): void
    {
        $order = $this->orderBaru($this->ptA, [$this->alat($this->ptA)]);
        $milikB = $this->alat($this->ptB);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", [
            'customer_id' => $this->ptB->id,
            'items' => [['equipment_id' => $milikB->id]],
        ])->assertOk();

        $this->assertSame($this->ptB->id, $order->fresh()->customer_id);
        $this->assertSame([$milikB->id], $this->idAlatDiOrder($order));
    }

    public function test_ganti_pelanggan_ditolak_bila_item_yang_dipertahankan_sudah_punya_sesi(): void
    {
        // `gantiItems` MEMPERTAHANKAN item yang sudah punya sesi kalibrasi. Alat
        // PT A itu tetap tinggal di order, jadi order tidak boleh pindah ke PT B.
        $dipakai = $this->alat($this->ptA);
        $order = $this->orderBaru($this->ptA, [$dipakai]);
        $item = OrderItem::query()->where('order_id', $order->id)->sole();
        CalibrationSession::create([
            'organization_id' => $this->admin->organization_id,
            'equipment_id' => $dipakai->id,
            'order_item_id' => $item->id,
            'teknisi_id' => $this->admin->id,
            'status' => CalibrationSession::STATUS_DRAFT,
            'tanggal_kalibrasi' => now(),
        ]);
        $milikB = $this->alat($this->ptB);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", [
            'customer_id' => $this->ptB->id,
            'items' => [['equipment_id' => $milikB->id]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');

        $this->assertSame($this->ptA->id, $order->fresh()->customer_id);
    }

    public function test_order_lama_yang_sudah_tercampur_tidak_memblokir_ubah_status(): void
    {
        // Data lama yang sudah salah tidak boleh membuat order tak bisa diselesaikan:
        // PUT yang tidak menyentuh `customer_id`/`items` jalan terus.
        $order = $this->orderBaru($this->ptA, [$this->alat($this->ptA), $this->alat($this->ptB)]);

        $this->actingAs($this->admin)->putJson("/api/orders/{$order->id}", [
            'status' => Order::STATUS_DIPROSES,
        ])->assertOk()->assertJsonPath('data.status', Order::STATUS_DIPROSES);
    }

    public function test_order_milik_lab_lain_tetap_404_bukan_422(): void
    {
        $labLain = Organization::factory()->create();
        $orderLab = Order::factory()->create([
            'organization_id' => $labLain->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $labLain->id])->id,
        ]);

        $this->actingAs($this->admin)->putJson("/api/orders/{$orderLab->id}", [
            'items' => [['equipment_id' => $this->alat($this->ptA)->id]],
        ])->assertNotFound();
    }
}
