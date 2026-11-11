<?php

namespace Tests\Feature\Pelanggan;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Pelanggan mencari dari NOMOR ORDER.
 *
 * - `GET /sertifikat?q=` kini juga mencocokkan nomor order yang tercetak di
 *   sertifikat (`calibration_sessions.nomor_order`) dan nomor order pelacakan
 *   (`orders.nomor`) milik pelanggan itu sendiri.
 * - `GET /paket?q=` mencari `orders.nomor`.
 *
 * Yang dijaga bukan cuma "ketemu", melainkan "tidak menembus": nomor order PT
 * lain dijawab daftar kosong, persis seperti nomor yang tidak ada.
 */
class CariDariNomorOrderTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const V1 = '/api/pelanggan/v1';

    private User $pic;

    private Customer $milikSaya;

    private Customer $milikLain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        $this->pic = $this->anggota();
        $this->milikSaya = $this->pic->keanggotaan()->first()->customer;
        $this->milikLain = $this->perusahaan();
    }

    private function sertifikat(Customer $pemilik, string $nomorSertifikat, ?string $nomorOrder = null, ?OrderItem $item = null): Certificate
    {
        $alat = Equipment::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'customer_id' => $pemilik->id,
            'status' => Equipment::STATUS_AKTIF,
        ]);
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'equipment_id' => $alat->id,
            'nomor_order' => $nomorOrder,
            'order_item_id' => $item?->id,
        ]);

        return Certificate::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'calibration_session_id' => $sesi->id,
            'nomor' => $nomorSertifikat,
            'status' => Certificate::STATUS_TERBIT,
        ]);
    }

    private function order(Customer $pemilik, string $nomor, string $status = Order::STATUS_DIPROSES): Order
    {
        return Order::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'customer_id' => $pemilik->id,
            'nomor' => $nomor,
            'status' => $status,
        ]);
    }

    private function itemOrder(Order $order): OrderItem
    {
        $alat = Equipment::factory()->create([
            'organization_id' => $order->organization_id,
            'customer_id' => $order->customer_id,
        ]);

        return OrderItem::factory()->create(['order_id' => $order->id, 'equipment_id' => $alat->id]);
    }

    /** @return list<int> */
    private function idDari(string $uri): array
    {
        $this->permintaanBaru();

        return array_column(
            $this->withHeaders($this->bearer($this->pic))->getJson(self::V1.$uri)->assertOk()->json('data'),
            'id',
        );
    }

    // --- /sertifikat?q= -------------------------------------------------------

    public function test_sertifikat_ditemukan_dari_nomor_order_yang_tercetak(): void
    {
        $cocok = $this->sertifikat($this->milikSaya, 'ISO/SAYA/0001', 'PO-2026-0042');
        $this->sertifikat($this->milikSaya, 'ISO/SAYA/0002', 'PO-2026-9999');

        $this->assertSame([$cocok->id], $this->idDari('/sertifikat?q=PO-2026-0042'));
        // Sebagian nomor juga ketemu (pola yang sama dengan nomor sertifikat/alat).
        $this->assertSame([$cocok->id], $this->idDari('/sertifikat?q=2026-0042'));
    }

    public function test_sertifikat_ditemukan_dari_nomor_order_pelacakan_milik_sendiri(): void
    {
        $order = $this->order($this->milikSaya, 'ORD/2026/11/0007');
        $cocok = $this->sertifikat($this->milikSaya, 'ISO/SAYA/0003', null, $this->itemOrder($order));
        $this->sertifikat($this->milikSaya, 'ISO/SAYA/0004');

        $this->assertSame([$cocok->id], $this->idDari('/sertifikat?q=ORD/2026/11/0007'));
    }

    public function test_pencarian_lama_tetap_jalan_nomor_sertifikat_nama_alat_dan_seri(): void
    {
        $s = $this->sertifikat($this->milikSaya, 'ISO/SAYA/0005', 'PO-1');
        $s->session->equipment->update(['nama_alat' => 'Termometer Unik Sekali', 'serial_number' => 'SN-UNIK-123']);

        $this->assertSame([$s->id], $this->idDari('/sertifikat?q=ISO/SAYA/0005'));
        $this->assertSame([$s->id], $this->idDari('/sertifikat?q=Unik Sekali'));
        $this->assertSame([$s->id], $this->idDari('/sertifikat?q=SN-UNIK-123'));
    }

    public function test_nomor_order_perusahaan_lain_tidak_menembus(): void
    {
        $this->sertifikat($this->milikLain, 'ISO/LAIN/0001', 'PO-RAHASIA-LAIN');
        $this->sertifikat($this->milikSaya, 'ISO/SAYA/0006', 'PO-MILIK-SAYA');

        // Sama persis dengan nomor yang memang tidak ada: daftar kosong, bukan 403.
        $this->assertSame([], $this->idDari('/sertifikat?q=PO-RAHASIA-LAIN'));
        $this->assertSame([], $this->idDari('/sertifikat?q=PO-TIDAK-ADA'));
        // Dan tanpa `q`, yang lain tidak ikut muncul.
        $this->assertCount(1, $this->idDari('/sertifikat'));
    }

    public function test_nomor_order_pelacakan_perusahaan_lain_tidak_menembus_walau_item_tertaut_silang(): void
    {
        // Order milik PT lain, tetapi sesi sertifikat PT SAYA tertaut ke itemnya
        // (data yang salah-tautan). Pencocokan order dikunci ke customer_id
        // pemanggil, jadi nomor order PT lain tetap tidak bisa dipakai mencari.
        $orderLain = $this->order($this->milikLain, 'ORD/LAIN/0001');
        $this->sertifikat($this->milikSaya, 'ISO/SAYA/0007', null, $this->itemOrder($orderLain));

        $this->assertSame([], $this->idDari('/sertifikat?q=ORD/LAIN/0001'));
    }

    // --- /paket?q= --------------------------------------------------------------

    public function test_paket_dicari_dari_nomor_order(): void
    {
        $cocok = $this->order($this->milikSaya, 'ORD/2026/11/0101');
        $this->order($this->milikSaya, 'ORD/2026/11/0202');

        $this->assertSame([$cocok->id], $this->idDari('/paket?q=0101'));
        $this->assertCount(2, $this->idDari('/paket'));
        // `selesai` tetap berlaku bersama `q`.
        $this->assertSame([], $this->idDari('/paket?q=0101&selesai=1'));
    }

    public function test_paket_nomor_order_perusahaan_lain_tidak_menembus(): void
    {
        $this->order($this->milikLain, 'ORD/LAIN/0555');
        $punyaSaya = $this->order($this->milikSaya, 'ORD/SAYA/0556');

        $this->assertSame([], $this->idDari('/paket?q=ORD/LAIN/0555'));
        $this->assertSame([], $this->idDari('/paket?q=0555'));
        $this->assertSame([$punyaSaya->id], $this->idDari('/paket?q=0556'));
    }

    public function test_q_terlalu_panjang_ditolak_422(): void
    {
        foreach (['/paket', '/sertifikat'] as $rute) {
            $this->permintaanBaru();
            $this->withHeaders($this->bearer($this->pic))
                ->getJson(self::V1.$rute.'?q='.str_repeat('x', 101))
                ->assertUnprocessable();
        }
    }
}
