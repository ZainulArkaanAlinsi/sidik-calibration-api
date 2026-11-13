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
use Illuminate\Testing\TestResponse;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Kata cari pelanggan (`q`) tidak peduli pemisah: `CAL-2026-10-0001` menemukan
 * `CAL/2026/10/0001`, `SN 123 4` menemukan `SN-123/4` (`PolaCari`).
 *
 * Yang dijaga bukan cuma "ketemu", tetapi tiga batas yang TIDAK boleh ikut
 * longgar: perusahaan lain tetap tidak muncul (daftar kosong, sama dengan
 * nomor yang tidak ada), `%`/`_`/tanda baca saja tidak menampilkan semuanya,
 * dan bentuk respons tidak berubah.
 */
class CariPemisahBedaTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const V1 = '/api/pelanggan/v1';

    private User $pic;

    private Customer $milikSaya;

    private Customer $milikLain;

    private int $urutAlat = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        $this->pic = $this->anggota();
        $this->milikSaya = $this->pic->keanggotaan()->first()->customer;
        $this->milikLain = $this->perusahaan();
    }

    /**
     * @param  array<string, mixed>  $alat  atribut alat (serial_number, nama_alat, ...)
     */
    private function sertifikat(Customer $pemilik, string $nomor, ?string $nomorOrder = null, ?OrderItem $item = null, array $alat = []): Certificate
    {
        $peralatan = Equipment::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'customer_id' => $pemilik->id,
            'status' => Equipment::STATUS_AKTIF,
            // Deterministik: seri acak pabrik (`??-####-##`) bisa kebetulan cocok
            // dengan kata cari pendek dan membuat test merah sesekali.
            'nama_alat' => 'Alat Uji',
            'serial_number' => 'UJI-'.(++$this->urutAlat),
            ...$alat,
        ]);
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'equipment_id' => $peralatan->id,
            'nomor_order' => $nomorOrder,
            'order_item_id' => $item?->id,
        ]);

        return Certificate::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'calibration_session_id' => $sesi->id,
            'nomor' => $nomor,
            'status' => Certificate::STATUS_TERBIT,
        ]);
    }

    private function order(Customer $pemilik, string $nomor): Order
    {
        return Order::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'customer_id' => $pemilik->id,
            'nomor' => $nomor,
            'status' => Order::STATUS_DIPROSES,
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
    private function idDari(string $rute, string $q): array
    {
        return array_column($this->ambil($rute, $q)->assertOk()->json('data'), 'id');
    }

    private function ambil(string $rute, string $q): TestResponse
    {
        $this->permintaanBaru();

        return $this->withHeaders($this->bearer($this->pic))
            ->getJson(self::V1.$rute.'?q='.urlencode($q));
    }

    // --- nomor sertifikat -------------------------------------------------------

    public function test_nomor_sertifikat_ditemukan_apa_pun_pemisah_yang_diketik(): void
    {
        $cocok = $this->sertifikat($this->milikSaya, 'CAL/2026/10/0001');
        $this->sertifikat($this->milikSaya, 'CAL/2026/10/0002');

        foreach ([
            'CAL-2026-10-0001',
            'CAL/2026/10/0001',
            'cal 2026 10 0001',
            'CAL.2026.10.0001',
            'CAL_2026_10_0001',
            'CAL\\2026\\10\\0001',
            'cal-2026/10 0001',
            '  CAL - 2026 - 10 - 0001  ',
        ] as $diketik) {
            $this->assertSame([$cocok->id], $this->idDari('/sertifikat', $diketik), "q = '{$diketik}'");
        }
    }

    public function test_pemisah_di_data_juga_boleh_berbeda_dari_yang_diketik(): void
    {
        $cocok = $this->sertifikat($this->milikSaya, 'ISO-A-0001');
        $this->sertifikat($this->milikSaya, 'ISO-A-0002');

        $this->assertSame([$cocok->id], $this->idDari('/sertifikat', 'iso/a/0001'));
        $this->assertSame([$cocok->id], $this->idDari('/sertifikat', 'ISO A 0001'));
    }

    public function test_potongan_harus_berurutan_dan_utuh(): void
    {
        $this->sertifikat($this->milikSaya, 'CAL/2026/10/0001');

        $this->assertSame([], $this->idDari('/sertifikat', 'CAL 0001 2026'), 'urutan terbalik');
        $this->assertSame([], $this->idDari('/sertifikat', 'CAL-2026-10-0009'), 'potongan terakhir lain');
        $this->assertSame([], $this->idDari('/sertifikat', 'CAL-2027-10-0001'), 'potongan tengah lain');
    }

    public function test_revisi_ikut_ketemu_lewat_nomor_dasar(): void
    {
        $dasar = $this->sertifikat($this->milikSaya, 'CAL/2026/10/0001');
        $revisi = $this->sertifikat($this->milikSaya, 'CAL/2026/10/0001-R1');

        $this->assertEqualsCanonicalizing([$dasar->id, $revisi->id], $this->idDari('/sertifikat', 'CAL-2026-10-0001'));
        $this->assertSame([$revisi->id], $this->idDari('/sertifikat', 'CAL-2026-10-0001-R1'));
    }

    // --- nomor seri -------------------------------------------------------------

    public function test_nomor_seri_berpemisah_beda_ditemukan_di_sertifikat_dan_alat(): void
    {
        $s = $this->sertifikat($this->milikSaya, 'ISO/SAYA/0001', null, null, ['serial_number' => 'SN-123/4']);
        $this->sertifikat($this->milikSaya, 'ISO/SAYA/0002', null, null, ['serial_number' => 'SN-999/9']);
        $idAlat = $s->session->equipment_id;

        foreach (['SN 123 4', 'sn.123.4', 'SN_123_4', 'sn/123-4', 'SN-123/4'] as $diketik) {
            $this->assertSame([$s->id], $this->idDari('/sertifikat', $diketik), "sertifikat q = '{$diketik}'");
            $this->assertSame([$idAlat], $this->idDari('/alat', $diketik), "alat q = '{$diketik}'");
        }
    }

    public function test_alat_dicari_lewat_no_identifikasi_dan_nama_berpemisah_beda(): void
    {
        $s = $this->sertifikat($this->milikSaya, 'ISO/SAYA/0003', null, null, [
            'no_identifikasi' => 'ID/77-A',
            'nama_alat' => 'Termometer Digital Presisi',
        ]);

        $this->assertSame([$s->session->equipment_id], $this->idDari('/alat', 'id 77 a'));
        $this->assertSame([$s->session->equipment_id], $this->idDari('/alat', 'termometer-digital'));
    }

    // --- Order Number -----------------------------------------------------------

    public function test_nomor_order_tercetak_ditemukan_walau_pemisahnya_beda(): void
    {
        $cocok = $this->sertifikat($this->milikSaya, 'ISO/SAYA/0004', 'PO-2026-0042');
        $this->sertifikat($this->milikSaya, 'ISO/SAYA/0005', 'PO-2026-9999');

        foreach (['PO/2026/0042', 'po 2026 0042', 'PO.2026.0042', 'PO-2026-0042'] as $diketik) {
            $this->assertSame([$cocok->id], $this->idDari('/sertifikat', $diketik), "q = '{$diketik}'");
        }
    }

    public function test_nomor_order_pelacakan_ditemukan_di_sertifikat_dan_paket(): void
    {
        $order = $this->order($this->milikSaya, 'ORD/2026/11/0007');
        $this->order($this->milikSaya, 'ORD/2026/11/0008');
        $sertifikat = $this->sertifikat($this->milikSaya, 'ISO/SAYA/0006', null, $this->itemOrder($order));

        foreach (['ORD-2026-11-0007', 'ord 2026 11 0007', 'ORD.2026.11.0007'] as $diketik) {
            $this->assertSame([$sertifikat->id], $this->idDari('/sertifikat', $diketik), "sertifikat q = '{$diketik}'");
            $this->assertSame([$order->id], $this->idDari('/paket', $diketik), "paket q = '{$diketik}'");
        }
    }

    // --- tidak menembus perusahaan lain -----------------------------------------

    public function test_milik_perusahaan_lain_tetap_tidak_muncul_walau_pemisahnya_dilonggarkan(): void
    {
        $orderLain = $this->order($this->milikLain, 'ORD/LAIN/0001');
        $this->sertifikat($this->milikLain, 'CAL/2026/10/0001', 'PO-LAIN-1', $this->itemOrder($orderLain), ['serial_number' => 'SN-LAIN/1']);
        $punyaSaya = $this->sertifikat($this->milikSaya, 'CAL/2026/10/0002');

        // Nomor yang sama persis ada di PT lain: yang ketemu hanya milik sendiri.
        $this->assertSame([], $this->idDari('/sertifikat', 'CAL-2026-10-0001'));
        $this->assertSame([$punyaSaya->id], $this->idDari('/sertifikat', 'CAL-2026-10-0002'));
        $this->assertSame([], $this->idDari('/sertifikat', 'PO LAIN 1'));
        $this->assertSame([], $this->idDari('/sertifikat', 'sn lain 1'));
        $this->assertSame([], $this->idDari('/sertifikat', 'ORD-LAIN-0001'));
        $this->assertSame([], $this->idDari('/paket', 'ORD-LAIN-0001'));
        $this->assertSame([], $this->idDari('/alat', 'SN LAIN 1'));
        // Dan potongan yang cuma "2026" tidak menyeret milik perusahaan lain.
        $this->assertSame([$punyaSaya->id], $this->idDari('/sertifikat', '2026'));
    }

    public function test_perusahaan_lain_dijawab_sama_dengan_nomor_yang_tidak_ada(): void
    {
        $this->sertifikat($this->milikLain, 'CAL/RAHASIA/0009');
        $this->sertifikat($this->milikSaya, 'CAL/2026/10/0001');

        $lain = $this->ambil('/sertifikat', 'CAL-RAHASIA-0009');
        $takAda = $this->ambil('/sertifikat', 'CAL-TIDAK-ADA-0009');

        $lain->assertOk();
        $this->assertSame($takAda->json(), $lain->json());
        $this->assertSame([], $lain->json('data'));
        $this->assertSame(0, $lain->json('meta.total'));
    }

    // --- tanpa hasil, dan kata yang cuma tanda baca -------------------------------

    public function test_kata_tanpa_hasil_tetap_kosong(): void
    {
        $this->sertifikat($this->milikSaya, 'CAL/2026/10/0001');
        $this->order($this->milikSaya, 'ORD/2026/11/0007');

        foreach (['/sertifikat', '/alat', '/paket'] as $rute) {
            $this->assertSame([], $this->idDari($rute, 'TIDAK-ADA-DI-MANA-MANA'), $rute);
        }
    }

    public function test_persen_dan_tanda_baca_saja_tidak_menampilkan_semuanya(): void
    {
        $this->sertifikat($this->milikSaya, 'CAL/2026/10/0001', 'PO-1');
        $this->sertifikat($this->milikSaya, 'CAL/2026/10/0002', 'PO-2');
        $this->order($this->milikSaya, 'ORD/2026/11/0007');

        // Tanpa `q`, daftarnya terisi: yang diuji memang "kosong", bukan "tidak ada data".
        $this->assertCount(2, array_column($this->withHeaders($this->bearer($this->pic))->getJson(self::V1.'/sertifikat')->assertOk()->json('data'), 'id'));

        foreach (['%', '%%%', '_', '-', '/', '...', ' - / - ', '%_%'] as $diketik) {
            foreach (['/sertifikat', '/alat', '/paket'] as $rute) {
                $this->assertSame([], $this->idDari($rute, $diketik), "{$rute} q = '{$diketik}'");
            }
        }
    }

    public function test_persen_di_tengah_kata_bukan_wildcard_penembus(): void
    {
        $this->sertifikat($this->milikSaya, 'CAL/2026/10/0001');

        // Dulu `%` di sini jadi wildcard SQL. Sekarang jadi pemisah: potongan
        // `ZZZ` tetap wajib ada di kolom, jadi tidak ada yang ikut terseret.
        $this->assertSame([], $this->idDari('/sertifikat', 'ZZZ%0001'));
        $this->assertSame([], $this->idDari('/sertifikat', '%ZZZ'));
    }

    // --- perilaku lama, batas, bentuk respons -------------------------------------

    public function test_kata_tanpa_pemisah_berperilaku_seperti_dulu(): void
    {
        $s = $this->sertifikat($this->milikSaya, 'ISO/SAYA/0005', 'PO-1', null, [
            'serial_number' => 'SN-UNIK-123',
            'nama_alat' => 'Termometer Unik Sekali',
        ]);
        $this->sertifikat($this->milikSaya, 'ISO/SAYA/0006', 'PO-2');

        foreach (['0005', 'unik', 'SN-UNIK-123', 'Unik Sekali', 'termometer', 'ISO/SAYA/0005'] as $diketik) {
            $this->assertSame([$s->id], $this->idDari('/sertifikat', $diketik), "q = '{$diketik}'");
        }
    }

    public function test_seratus_karakter_diterima_dan_seratus_satu_ditolak(): void
    {
        $this->sertifikat($this->milikSaya, 'CAL/2026/10/0001');

        foreach (['/sertifikat', '/alat', '/paket'] as $rute) {
            $this->assertSame([], $this->idDari($rute, str_repeat('x', 100)), $rute);
            $this->assertSame([], $this->idDari($rute, substr(str_repeat('ab-', 40), 0, 100)), $rute);
            $this->ambil($rute, str_repeat('x', 101))->assertUnprocessable();
        }
    }

    public function test_tanda_petik_dan_karakter_aneh_tidak_membuat_galat(): void
    {
        $this->sertifikat($this->milikSaya, 'CAL/2026/10/0001');

        foreach (["' OR 1=1 --", '"; DROP TABLE certificates; --', '[]{}()<>', '漢字', "\u{00C3}\u{00BC}"] as $diketik) {
            $this->assertSame([], $this->idDari('/sertifikat', $diketik), $diketik);
        }
    }

    public function test_bentuk_respons_tidak_berubah_saat_q_dipakai(): void
    {
        $this->sertifikat($this->milikSaya, 'CAL/2026/10/0001', 'PO-1');
        $this->order($this->milikSaya, 'ORD/2026/11/0007');

        foreach (['/sertifikat' => 'CAL-2026-10-0001', '/alat' => 'alat-uji', '/paket' => 'ORD-2026-11-0007'] as $rute => $q) {
            $this->permintaanBaru();
            $tanpa = $this->withHeaders($this->bearer($this->pic))->getJson(self::V1.$rute)->assertOk()->json();
            $dengan = $this->ambil($rute, $q)->assertOk()->json();

            $this->assertSame(array_keys($tanpa), array_keys($dengan), $rute);
            $this->assertSame(array_keys($tanpa['meta']), array_keys($dengan['meta']), $rute);
            $this->assertNotEmpty($dengan['data'], $rute);
            $this->assertSame(array_keys($tanpa['data'][0]), array_keys($dengan['data'][0]), $rute);
        }
    }
}
