<?php

namespace Tests\Feature\Pelanggan;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\TahapPaket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Pelacakan alat di aplikasi pelanggan (13 Nov 2026), dua jaminan:
 *
 * **B -- penjaga tampilan.** Item paket yang alatnya milik pelanggan LAIN
 * disembunyikan (item-nya, bukan paketnya) di `/paket`, `/paket/{id}`, dan
 * `/beranda` -- ketiganya memuat item lewat `LingkupData::relasiPaket()`.
 * Order PT A yang tercampur alat PT B dulu membocorkan nama + nomor seri alat B.
 *
 * **C -- field additive.** `equipment_id` di item paket dan `sedang_dikerjakan`
 * di objek alat, tanpa N+1, tanpa data internal.
 */
class PelacakanAlatPelangganTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const V1 = '/api/pelanggan/v1';

    private User $pic;

    private Customer $ptA;

    private Customer $ptB;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        $this->pic = $this->anggota();
        $this->ptA = $this->pic->keanggotaan()->first()->customer;
        $this->ptB = $this->perusahaan('PT Pesaing Beta');
        $this->teknisi = User::factory()->create([
            'organization_id' => $this->ptA->organization_id,
            'role' => User::ROLE_TEKNISI,
            'name' => 'Teknisi Rahasia Sekali',
        ]);
    }

    private function alat(Customer $pemilik, array $isi = []): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'customer_id' => $pemilik->id,
            'status' => Equipment::STATUS_AKTIF,
            ...$isi,
        ]);
    }

    private function order(Customer $pemilik, array $isi = []): Order
    {
        return Order::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'customer_id' => $pemilik->id,
            'status' => Order::STATUS_DIPROSES,
            ...$isi,
        ]);
    }

    private function item(Order $order, Equipment $alat, array $isi = []): OrderItem
    {
        return OrderItem::factory()->create([
            'order_id' => $order->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $this->teknisi->id,
            'kondisi_terima' => 'KONDISI-TERIMA-INTERNAL',
            'catatan' => 'CATATAN-INTERNAL-ITEM',
            ...$isi,
        ]);
    }

    private function ambil(string $uri)
    {
        $this->permintaanBaru();

        return $this->withHeaders($this->bearer($this->pic))->getJson(self::V1.$uri);
    }

    // --- B: penjaga tampilan ---------------------------------------------------

    /** @return array{0: Order, 1: Equipment, 2: Equipment} */
    private function orderTercampur(): array
    {
        $milikA = $this->alat($this->ptA, ['nama_alat' => 'Alat Milik A', 'serial_number' => 'SN-A-111']);
        $milikB = $this->alat($this->ptB, ['nama_alat' => 'Alat Rahasia B', 'merk' => 'MerkRahasiaB', 'serial_number' => 'SN-RAHASIA-B-999']);
        $order = $this->order($this->ptA);
        // Item A sudah diserahkan; item B belum disentuh (tahap "diterima") -- kalau item B
        // ikut dihitung, tahap paketnya jatuh ke "diterima" dan jumlah alatnya jadi 2.
        $this->item($order, $milikA, ['tahap_fisik' => TahapPaket::DISERAHKAN, 'tahap_fisik_pada' => now(), 'diserahkan_kepada' => 'Pak Budi']);
        $this->item($order, $milikB);

        return [$order, $milikA, $milikB];
    }

    private function pastikanTidakAdaMilikB(string $isi): void
    {
        foreach (['Alat Rahasia B', 'MerkRahasiaB', 'SN-RAHASIA-B-999'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $isi, "Data alat pelanggan lain (`{$rahasia}`) bocor.");
        }
    }

    public function test_daftar_paket_menyembunyikan_item_beralat_pelanggan_lain_bukan_paketnya(): void
    {
        [$order, $milikA] = $this->orderTercampur();

        $respons = $this->ambil('/paket')->assertOk();

        $this->assertSame([$order->id], array_column($respons->json('data'), 'id'), 'Paketnya ikut hilang.');
        $paket = $respons->json('data.0');
        $this->assertSame(1, $paket['jumlah_alat']);
        $this->assertSame([$milikA->id], array_column($paket['alat'], 'equipment_id'));
        $this->assertSame(TahapPaket::DISERAHKAN, $paket['tahap'], 'Item tersembunyi masih ikut menentukan tahap paket.');
        $this->pastikanTidakAdaMilikB($respons->getContent());
    }

    public function test_detail_paket_dan_garis_waktunya_hanya_dari_item_yang_terlihat(): void
    {
        [$order, $milikA] = $this->orderTercampur();

        $respons = $this->ambil("/paket/{$order->id}")->assertOk();

        $this->assertSame(1, $respons->json('data.jumlah_alat'));
        $this->assertSame([$milikA->id], array_column($respons->json('data.alat'), 'equipment_id'));
        $sekarang = collect($respons->json('garis_waktu'))->firstWhere('sekarang', true);
        $this->assertSame(TahapPaket::DISERAHKAN, $sekarang['kode']);
        $this->pastikanTidakAdaMilikB($respons->getContent());
    }

    public function test_beranda_paket_berjalan_ikut_menyembunyikan_item_beralat_pelanggan_lain(): void
    {
        $this->orderTercampur();

        $respons = $this->ambil('/beranda')->assertOk();

        $this->assertSame(1, $respons->json('data.paket_berjalan.0.jumlah_alat'));
        $this->pastikanTidakAdaMilikB($respons->getContent());
    }

    public function test_item_beralat_milik_lab_lain_walau_customer_id_sama_juga_disembunyikan(): void
    {
        // Dua saringan, bukan satu (sama dengan `LingkupData::alat()`): `customer_id` yang kebetulan
        // sama (impor, salah ketik admin) di lab KEDUA tidak boleh jadi jalan bocor.
        $labLain = Organization::factory()->create();
        $asing = Equipment::factory()->create([
            'organization_id' => $labLain->id,
            'customer_id' => $this->ptA->id,
            'nama_alat' => 'Alat Dari Lab Lain',
        ]);
        $sendiri = $this->alat($this->ptA, ['nama_alat' => 'Alat Sendiri']);
        $order = $this->order($this->ptA);
        $this->item($order, $sendiri);
        $this->item($order, $asing);

        $respons = $this->ambil("/paket/{$order->id}")->assertOk();

        $this->assertSame(['Alat Sendiri'], array_column($respons->json('data.alat'), 'nama'));
        $this->assertStringNotContainsString('Alat Dari Lab Lain', $respons->getContent());
    }

    public function test_paket_tanpa_campuran_tidak_berubah_dan_alat_terhapus_lunak_tetap_tampil(): void
    {
        $order = $this->order($this->ptA);
        $biasa = $this->alat($this->ptA, ['nama_alat' => 'Alat Biasa']);
        $dihapus = $this->alat($this->ptA, ['nama_alat' => 'Alat Sudah Dihapus']);
        $this->item($order, $biasa);
        $this->item($order, $dihapus);
        $dihapus->delete();

        $respons = $this->ambil("/paket/{$order->id}")->assertOk();

        $this->assertSame(2, $respons->json('data.jumlah_alat'));
        $this->assertEqualsCanonicalizing(['Alat Biasa', 'Alat Sudah Dihapus'], array_column($respons->json('data.alat'), 'nama'));
    }

    public function test_item_paket_membawa_equipment_id_dan_tetap_bebas_data_internal(): void
    {
        $alat = $this->alat($this->ptA);
        $order = $this->order($this->ptA);
        $item = $this->item($order, $alat);

        $respons = $this->ambil("/paket/{$order->id}")->assertOk();

        $this->assertSame($item->id, $respons->json('data.alat.0.id'));
        $this->assertSame($alat->id, $respons->json('data.alat.0.equipment_id'));
        $this->assertSame(
            ['id', 'equipment_id', 'nama', 'merk', 'serial', 'tahap', 'tahap_label', 'diserahkan_kepada', 'sertifikat_id'],
            array_keys($respons->json('data.alat.0')),
        );
        foreach (['Teknisi Rahasia', 'KONDISI-TERIMA-INTERNAL', 'CATATAN-INTERNAL-ITEM'] as $internal) {
            $this->assertStringNotContainsString($internal, $respons->getContent());
        }
    }

    // --- C: sedang_dikerjakan ---------------------------------------------------

    private function sedangDikerjakan(Equipment $alat): mixed
    {
        $respons = $this->ambil('/alat')->assertOk();

        return collect($respons->json('data'))->firstWhere('id', $alat->id)['sedang_dikerjakan'];
    }

    public function test_alat_di_paket_aktif_membawa_sedang_dikerjakan_dengan_enam_kunci_saja(): void
    {
        $alat = $this->alat($this->ptA);
        $order = $this->order($this->ptA, ['nomor' => 'ORD/2026/11/0007']);
        $item = $this->item($order, $alat);
        CalibrationSession::factory()->create([
            'organization_id' => $alat->organization_id,
            'equipment_id' => $alat->id,
            'order_item_id' => $item->id,
            'teknisi_id' => $this->teknisi->id,
            'status' => CalibrationSession::STATUS_MENUNGGU_PENGESAHAN,
        ]);

        $sedang = $this->sedangDikerjakan($alat);

        // Pengesahan dilipat ke pemeriksaan, sama dengan `/paket`. Lima kunci asli (13 Nov)
        // tidak berubah nilai maupun urutan; `garis_waktu` (14 Nov) ditambahkan di belakang.
        $this->assertSame(
            ['paket_id', 'order_item_id', 'nomor_order', 'tahap', 'tahap_label', 'garis_waktu'],
            array_keys($sedang),
        );
        $this->assertSame([
            'paket_id' => $order->id,
            'order_item_id' => $item->id,
            'nomor_order' => 'ORD/2026/11/0007',
            'tahap' => TahapPaket::MENUNGGU_PEMERIKSAAN,
            'tahap_label' => 'Hasil sedang diperiksa',
        ], array_slice($sedang, 0, 5, true));
        $this->assertSame((new TahapPaket)->garisWaktu(TahapPaket::MENUNGGU_PEMERIKSAAN, untukPelanggan: true), $sedang['garis_waktu']);

        $isi = $this->ambil('/alat')->getContent();
        foreach (['Teknisi Rahasia', 'KONDISI-TERIMA-INTERNAL', 'CATATAN-INTERNAL-ITEM', TahapPaket::MENUNGGU_PENGESAHAN, 'nomor_sesi'] as $internal) {
            $this->assertStringNotContainsString($internal, $isi);
        }
    }

    public function test_alat_tanpa_paket_aktif_membawa_kunci_dengan_nilai_null(): void
    {
        $alat = $this->alat($this->ptA);

        $this->assertNull($this->sedangDikerjakan($alat));
        $this->assertArrayHasKey('sedang_dikerjakan', $this->ambil("/alat/{$alat->id}")->json('data'));
    }

    public function test_paket_selesai_dibatalkan_atau_alat_sudah_diserahkan_bukan_sedang_dikerjakan(): void
    {
        $selesai = $this->alat($this->ptA);
        $this->item($this->order($this->ptA, ['status' => Order::STATUS_SELESAI]), $selesai);

        $batal = $this->alat($this->ptA);
        $this->item($this->order($this->ptA, ['status' => Order::STATUS_DIBATALKAN]), $batal);

        $diserahkan = $this->alat($this->ptA);
        $this->item($this->order($this->ptA), $diserahkan, [
            'tahap_fisik' => TahapPaket::DISERAHKAN, 'tahap_fisik_pada' => now(), 'diserahkan_kepada' => 'Pak Budi',
        ]);

        $respons = collect($this->ambil('/alat')->assertOk()->json('data'))->keyBy('id');

        foreach ([$selesai, $batal, $diserahkan] as $alat) {
            $this->assertNull($respons[$alat->id]['sedang_dikerjakan'], "Alat #{$alat->id} dikira sedang dikerjakan.");
        }
    }

    public function test_dua_paket_aktif_memakai_yang_terbaru(): void
    {
        $alat = $this->alat($this->ptA);
        $lama = $this->order($this->ptA, ['tanggal_masuk' => '2026-10-01', 'nomor' => 'ORD/LAMA']);
        $baru = $this->order($this->ptA, ['tanggal_masuk' => '2026-11-10', 'nomor' => 'ORD/BARU']);
        $this->item($baru, $alat);
        $this->item($lama, $alat);

        $this->assertSame('ORD/BARU', $this->sedangDikerjakan($alat)['nomor_order']);
        $this->assertSame($baru->id, $this->sedangDikerjakan($alat)['paket_id']);
    }

    public function test_alat_saya_di_order_perusahaan_lain_tidak_pernah_terlihat(): void
    {
        // Sisi sebaliknya dari penjaga B: order PT B memuat alat PT A. PT A tidak boleh
        // melihat bahwa order PT B itu ada, apalagi nomornya.
        $alat = $this->alat($this->ptA);
        $this->item($this->order($this->ptB, ['nomor' => 'ORD/MILIK-B-4711']), $alat);

        $this->assertNull($this->sedangDikerjakan($alat));
        $this->assertStringNotContainsString('ORD/MILIK-B-4711', $this->ambil('/alat')->getContent());
        $this->assertStringNotContainsString('ORD/MILIK-B-4711', $this->ambil("/alat/{$alat->id}")->getContent());
        $this->assertStringNotContainsString('ORD/MILIK-B-4711', $this->ambil('/beranda')->getContent());
    }

    public function test_detail_alat_dan_beranda_ikut_membawa_sedang_dikerjakan(): void
    {
        $alat = $this->alat($this->ptA, ['tanggal_jatuh_tempo' => now()->addDays(3)]);
        $order = $this->order($this->ptA);
        $item = $this->item($order, $alat, ['tahap_fisik' => TahapPaket::SIAP_DIAMBIL, 'tahap_fisik_pada' => now()]);

        $detail = $this->ambil("/alat/{$alat->id}")->assertOk()->json('data.sedang_dikerjakan');
        $this->assertSame($item->id, $detail['order_item_id']);
        $this->assertSame(TahapPaket::SIAP_DIAMBIL, $detail['tahap']);
        $this->assertSame('Siap diambil', $detail['tahap_label']);

        $beranda = $this->ambil('/beranda')->assertOk()->json('data.perlu_perhatian.0.sedang_dikerjakan');
        $this->assertSame($order->id, $beranda['paket_id']);
    }

    public function test_jumlah_kueri_daftar_alat_tidak_bertambah_dengan_jumlah_alat(): void
    {
        $buat = function (int $jumlah): void {
            for ($i = 0; $i < $jumlah; $i++) {
                $alat = $this->alat($this->ptA);
                $item = $this->item($this->order($this->ptA), $alat);
                CalibrationSession::factory()->create([
                    'organization_id' => $alat->organization_id,
                    'equipment_id' => $alat->id,
                    'order_item_id' => $item->id,
                    'teknisi_id' => $this->teknisi->id,
                    'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                ]);
            }
        };

        $hitung = function (): int {
            $this->ambil('/alat')->assertOk(); // hangatkan: cache/kueri sekali-pakai tidak ikut terhitung
            DB::flushQueryLog();
            DB::enableQueryLog();
            $respons = $this->ambil('/alat')->assertOk();
            $jumlah = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertNotNull($respons->json('data.0.sedang_dikerjakan'));

            return $jumlah;
        };

        $buat(2);
        $duaAlat = $hitung();
        $this->assertCount(2, $this->ambil('/alat')->json('data'));

        $buat(10);
        $this->assertCount(12, $this->ambil('/alat')->json('data'));
        $duabelasAlat = $hitung();

        $this->assertSame(
            $duaAlat,
            $duabelasAlat,
            "Kueri daftar alat bertambah dari {$duaAlat} ke {$duabelasAlat} saat alat bertambah -- N+1.",
        );
    }
}
