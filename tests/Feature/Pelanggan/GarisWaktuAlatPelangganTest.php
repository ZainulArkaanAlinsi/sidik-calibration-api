<?php

namespace Tests\Feature\Pelanggan;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\TahapPaket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * `sedang_dikerjakan.garis_waktu` (additive, 14 Nov 2026) di `GET /alat`, `GET /alat/{id}`,
 * dan `GET /beranda` (`perlu_perhatian[]`).
 *
 * Aplikasi Pelanggan dulu menyalin urutan tahap ke Dart. Sekarang server mengirim jejak
 * tahap ALAT itu, dari fungsi yang sama dengan `garis_waktu` di `GET /paket/{id}`
 * (`TahapPaket::garisWaktu($tahap, untukPelanggan: true)`) -- satu sumber. Urutan dan label
 * sendiri dikunci `Tests\Unit\TahapPaketTerkunciTest`.
 */
class GarisWaktuAlatPelangganTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const V1 = '/api/pelanggan/v1';

    private User $pic;

    private Customer $ptA;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        $this->pic = $this->anggota();
        $this->ptA = $this->pic->keanggotaan()->first()->customer;
        $this->teknisi = User::factory()->create([
            'organization_id' => $this->ptA->organization_id,
            'role' => User::ROLE_TEKNISI,
        ]);
    }

    private function alat(array $isi = []): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $this->ptA->organization_id,
            'customer_id' => $this->ptA->id,
            'status' => Equipment::STATUS_AKTIF,
            // Dalam jendela "segera": alatnya ikut `perlu_perhatian` di `/beranda`.
            'tanggal_jatuh_tempo' => now()->addDays(5),
            ...$isi,
        ]);
    }

    private function order(array $isi = []): Order
    {
        return Order::factory()->create([
            'organization_id' => $this->ptA->organization_id,
            'customer_id' => $this->ptA->id,
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
            ...$isi,
        ]);
    }

    private function sesi(Equipment $alat, OrderItem $item, string $status): CalibrationSession
    {
        return CalibrationSession::factory()->create([
            'organization_id' => $alat->organization_id,
            'equipment_id' => $alat->id,
            'order_item_id' => $item->id,
            'teknisi_id' => $this->teknisi->id,
            'status' => $status,
        ]);
    }

    private function ambil(string $uri)
    {
        $this->permintaanBaru();

        return $this->withHeaders($this->bearer($this->pic))->getJson(self::V1.$uri)->assertOk();
    }

    /**
     * Skenario -> [tahap yang DILIHAT pelanggan, jumlah langkah jejak].
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function skenarioTahap(): array
    {
        return [
            'diterima (belum ada sesi)' => ['diterima', 'diterima', 6],
            'dikalibrasi (sesi draft)' => ['dikalibrasi', 'dikalibrasi', 6],
            'perlu diulang (sesi perlu revisi)' => ['perlu_diulang', 'perlu_diulang', 7],
            'menunggu pemeriksaan' => ['menunggu_pemeriksaan', 'menunggu_pemeriksaan', 6],
            'menunggu pengesahan dilipat ke pemeriksaan' => ['menunggu_pengesahan', 'menunggu_pemeriksaan', 6],
            'disetujui tanpa sertifikat terbit = masih diperiksa' => ['disetujui_tanpa_sertifikat', 'menunggu_pemeriksaan', 6],
            'sertifikat terbit' => ['sertifikat_terbit', 'sertifikat_terbit', 6],
            'siap diambil (fisik)' => ['siap_diambil', 'siap_diambil', 6],
        ];
    }

    /** Menyiapkan satu alat di satu paket aktif pada skenario tertentu. */
    private function siapkan(string $skenario): array
    {
        $alat = $this->alat();
        $order = $this->order();
        $item = $this->item($order, $alat, $skenario === 'siap_diambil'
            ? ['tahap_fisik' => TahapPaket::SIAP_DIAMBIL, 'tahap_fisik_pada' => now()]
            : []);

        match ($skenario) {
            'dikalibrasi' => $this->sesi($alat, $item, CalibrationSession::STATUS_DRAFT),
            'perlu_diulang' => $this->sesi($alat, $item, CalibrationSession::STATUS_PERLU_REVISI),
            'menunggu_pemeriksaan' => $this->sesi($alat, $item, CalibrationSession::STATUS_MENUNGGU_APPROVAL),
            'menunggu_pengesahan' => $this->sesi($alat, $item, CalibrationSession::STATUS_MENUNGGU_PENGESAHAN),
            'disetujui_tanpa_sertifikat' => $this->sesi($alat, $item, CalibrationSession::STATUS_DISETUJUI),
            'sertifikat_terbit' => Certificate::factory()->create([
                'organization_id' => $alat->organization_id,
                'calibration_session_id' => $this->sesi($alat, $item, CalibrationSession::STATUS_DISETUJUI)->id,
                'status' => Certificate::STATUS_TERBIT,
            ]),
            default => null,
        };

        return [$alat, $order];
    }

    #[DataProvider('skenarioTahap')]
    public function test_garis_waktu_di_alat_detail_dan_beranda_sama_dengan_garis_waktu_paket(string $skenario, string $tahapDilihat, int $langkah): void
    {
        [$alat, $order] = $this->siapkan($skenario);
        $harap = (new TahapPaket)->garisWaktu($tahapDilihat, untukPelanggan: true);

        $daftar = collect($this->ambil('/alat')->json('data'))->firstWhere('id', $alat->id)['sedang_dikerjakan'];
        $detail = $this->ambil("/alat/{$alat->id}")->json('data.sedang_dikerjakan');
        $beranda = collect($this->ambil('/beranda')->json('data.perlu_perhatian'))->firstWhere('id', $alat->id)['sedang_dikerjakan'];
        // Paket berisi satu alat: jejak alat == jejak paket. Satu sumber, bukan dua yang kebetulan sama.
        $paket = $this->ambil("/paket/{$order->id}")->json('garis_waktu');

        foreach (['/alat' => $daftar, '/alat/{id}' => $detail, '/beranda' => $beranda] as $rute => $sedang) {
            $this->assertSame($tahapDilihat, $sedang['tahap'], "{$rute}: tahap");
            $this->assertSame($harap, $sedang['garis_waktu'], "{$rute}: garis_waktu");
            $this->assertSame($paket, $sedang['garis_waktu'], "{$rute}: beda dari garis_waktu `/paket/{id}`");
            $this->assertCount($langkah, $sedang['garis_waktu'], "{$rute}: jumlah langkah");
            $this->assertSame(
                [$tahapDilihat],
                array_column(array_filter($sedang['garis_waktu'], fn (array $l): bool => $l['sekarang']), 'kode'),
                "{$rute}: tepat satu langkah `sekarang`, yaitu tahap yang dilihat",
            );
            foreach ($sedang['garis_waktu'] as $baris) {
                $this->assertSame(['kode', 'label', 'lewat', 'sekarang'], array_keys($baris), "{$rute}: bentuk langkah");
            }
        }
    }

    public function test_enam_kunci_sedang_dikerjakan_dan_tanpa_data_internal(): void
    {
        [$alat] = $this->siapkan('menunggu_pengesahan');

        $respons = $this->ambil("/alat/{$alat->id}");

        $this->assertSame(
            ['paket_id', 'order_item_id', 'nomor_order', 'tahap', 'tahap_label', 'garis_waktu'],
            array_keys($respons->json('data.sedang_dikerjakan')),
        );
        // Dua lapis persetujuan lab tidak boleh terlihat di jejak, tidak lewat kode maupun label internal.
        $isi = $respons->getContent();
        foreach ([TahapPaket::MENUNGGU_PENGESAHAN, ...array_values(TahapPaket::LABEL_INTERNAL)] as $internal) {
            if (! in_array($internal, TahapPaket::LABEL_PELANGGAN, true)) {
                $this->assertStringNotContainsString($internal, $isi, "`{$internal}` (istilah internal) bocor.");
            }
        }
    }

    public function test_alat_tanpa_paket_aktif_tidak_membawa_garis_waktu(): void
    {
        $alat = $this->alat();
        $this->item($this->order(['status' => Order::STATUS_SELESAI]), $alat);
        $diserahkan = $this->alat();
        $this->item($this->order(), $diserahkan, ['tahap_fisik' => TahapPaket::DISERAHKAN, 'tahap_fisik_pada' => now(), 'diserahkan_kepada' => 'Pak Budi']);

        $daftar = collect($this->ambil('/alat')->json('data'))->keyBy('id');

        foreach ([$alat, $diserahkan] as $a) {
            $this->assertNull($daftar[$a->id]['sedang_dikerjakan']);
        }
        $this->assertStringNotContainsString('garis_waktu', $this->ambil('/alat')->getContent());
    }

    public function test_jejak_mengikuti_tahap_alat_bukan_tahap_paket(): void
    {
        // Satu paket, dua alat: paket "paling tertinggal" = dikalibrasi, tapi alat A sudah siap diambil.
        $siap = $this->alat();
        $masihDikerjakan = $this->alat();
        $order = $this->order();
        $this->item($order, $siap, ['tahap_fisik' => TahapPaket::SIAP_DIAMBIL, 'tahap_fisik_pada' => now()]);
        $item = $this->item($order, $masihDikerjakan);
        $this->sesi($masihDikerjakan, $item, CalibrationSession::STATUS_DRAFT);

        $daftar = collect($this->ambil('/alat')->json('data'))->keyBy('id');
        $paket = $this->ambil("/paket/{$order->id}");

        $sekarang = fn (array $sedang): string => collect($sedang['garis_waktu'])->firstWhere('sekarang', true)['kode'];
        $this->assertSame('siap_diambil', $sekarang($daftar[$siap->id]['sedang_dikerjakan']));
        $this->assertSame('dikalibrasi', $sekarang($daftar[$masihDikerjakan->id]['sedang_dikerjakan']));
        $this->assertSame('dikalibrasi', collect($paket->json('garis_waktu'))->firstWhere('sekarang', true)['kode']);
    }

    public function test_dua_paket_aktif_jejaknya_dari_paket_terbaru(): void
    {
        $alat = $this->alat();
        $lama = $this->order(['tanggal_masuk' => '2026-10-01']);
        $baru = $this->order(['tanggal_masuk' => '2026-11-10']);
        $this->item($lama, $alat, ['tahap_fisik' => TahapPaket::SIAP_DIAMBIL, 'tahap_fisik_pada' => now()]);
        $this->item($baru, $alat); // belum disentuh: "diterima"

        $sedang = $this->ambil("/alat/{$alat->id}")->json('data.sedang_dikerjakan');

        $this->assertSame($baru->id, $sedang['paket_id']);
        $this->assertSame('diterima', collect($sedang['garis_waktu'])->firstWhere('sekarang', true)['kode']);
    }
}
