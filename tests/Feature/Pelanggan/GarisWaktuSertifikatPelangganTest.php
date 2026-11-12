<?php

namespace Tests\Feature\Pelanggan;

use App\Models\Certificate;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\DokumenArsipUji;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * `GET /pelanggan/v1/sertifikat/garis-waktu` -- sertifikat perusahaan pemanggil
 * dikelompokkan TAHUN > BULAN > PEKAN > HARI menurut tanggal terbit. Kembaran
 * `GET /arsip/garis-waktu` di sisi lab; yang dijaga di sini terutama
 * kerahasiaan antar-perusahaan dan kecocokan bentuk dengan `/sertifikat`.
 */
class GarisWaktuSertifikatPelangganTest extends TestCase
{
    use DokumenArsipUji, JalurPelanggan, RefreshDatabase;

    private const URI = '/api/pelanggan/v1/sertifikat/garis-waktu';

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

    /** @return array<string, mixed> */
    private function ambil(string $query = ''): array
    {
        $this->permintaanBaru();

        return $this->withHeaders($this->bearer($this->pic))
            ->getJson(self::URI.($query !== '' ? "?{$query}" : ''))
            ->assertOk()
            ->json();
    }

    private function saya(array $o = []): array
    {
        return $this->bangunDokumen($this->milikSaya->organization, ['pt' => $this->milikSaya, ...$o]);
    }

    public function test_mengelompokkan_sertifikat_perusahaan_sendiri_di_semua_tingkat(): void
    {
        $this->saya(['terbit' => '2026-10-26']);
        $this->saya(['terbit' => '2026-11-09']);
        $this->saya(['terbit' => '2026-11-12']);
        $this->saya(['terbit' => '2026-11-12']);
        $this->saya(['terbit' => '2025-12-30']);

        $tahun = $this->ambil();
        $this->assertSame([4, 1], collect($tahun['data']['kelompok'])->pluck('jumlah')->all());
        $this->assertSame(5, $tahun['data']['total_dokumen']);

        $bulan = $this->ambil('tingkat=bulan&tahun=2026');
        $this->assertSame(['2026-11' => 3, '2026-10' => 1], collect($bulan['data']['kelompok'])->pluck('jumlah', 'kunci')->all());

        $pekan = $this->ambil('tingkat=pekan&tahun=2026&bulan=11');
        $this->assertSame(['Pekan 46 (9–15 Nov 2026)'], collect($pekan['data']['kelompok'])->pluck('label')->all());

        $hari = $this->ambil('tingkat=hari&tahun=2026&bulan=11&pekan=46');
        $this->assertSame(['2026-11-12' => 2, '2026-11-09' => 1], collect($hari['data']['kelompok'])->pluck('jumlah', 'kunci')->all());
        $this->assertCount(3, $hari['data']['item']);
        $this->assertSame(3, $hari['meta']['total']);
    }

    public function test_bentuk_item_sama_dengan_daftar_sertifikat_ditambah_nomor_order(): void
    {
        $d = $this->saya([
            'terbit' => '2026-11-12', 'nomor' => 'ISO/SAYA/0001',
            'sertifikat' => ['snapshot' => ['header' => ['order_number' => 'PO-2026-0042', 'equipment_name' => 'Busur Derajat', 'serial_number' => 'PL-SERI-1']]],
        ]);

        $item = $this->ambil('tingkat=hari&tahun=2026&bulan=11&pekan=46')['data']['item'][0];

        $this->permintaanBaru();
        $daftar = $this->withHeaders($this->bearer($this->pic))->getJson('/api/pelanggan/v1/sertifikat')->assertOk()->json('data.0');

        $dasar = $item;
        unset($dasar['nomor_order']);
        $this->assertSame($daftar, $dasar, 'Item garis waktu harus identik dengan baris /sertifikat.');
        $this->assertSame('PO-2026-0042', $item['nomor_order']);
        $this->assertSame($d['sertifikat']->id, $item['id']);
        $this->assertSame('berlaku', $item['status']);
        // Tidak membocorkan kunci internal.
        foreach (['organization_id', 'customer_id', 'pdf_path', 'snapshot', 'alasan_revisi', 'issued_by'] as $dilarang) {
            $this->assertArrayNotHasKey($dilarang, $item);
        }
    }

    public function test_tidak_menghitung_sertifikat_perusahaan_lain(): void
    {
        $this->saya(['terbit' => '2026-11-12']);
        $this->bangunDokumen($this->milikLain->organization, ['pt' => $this->milikLain, 'terbit' => '2026-11-12']);
        $this->bangunDokumen($this->milikLain->organization, ['pt' => $this->milikLain, 'terbit' => '2026-11-13']);

        $this->assertSame(1, $this->ambil()['data']['total_dokumen']);
        // `customer_id` dari query string tidak pernah dipercaya.
        $this->assertSame(1, $this->ambil("customer_id={$this->milikLain->id}")['data']['total_dokumen']);
        $hari = $this->ambil('tingkat=hari&tahun=2026&bulan=11&pekan=46');
        $this->assertCount(1, $hari['data']['item']);
    }

    public function test_yang_digantikan_revisi_disembunyikan_kecuali_diminta(): void
    {
        $asli = $this->saya(['terbit' => '2026-10-01', 'nomor' => 'ISO/SAYA/0100']);
        $revisi = $this->saya(['terbit' => '2026-11-12', 'nomor' => 'ISO/SAYA/0100-R1']);
        $revisi['sertifikat']->update(['revision_of' => $asli['sertifikat']->id, 'revisi_ke' => 1]);

        $this->assertSame(1, $this->ambil()['data']['total_dokumen']);
        $semua = $this->ambil('termasuk_digantikan=1');
        $this->assertSame(2, $semua['data']['total_dokumen']);

        $okt = $this->ambil('tingkat=hari&tahun=2026&bulan=10&pekan=40&termasuk_digantikan=1');
        $this->assertSame('digantikan', $okt['data']['item'][0]['status']);
    }

    public function test_hanya_sertifikat_terbit_atau_dibatalkan(): void
    {
        $this->saya(['terbit' => '2026-11-12']);
        $this->saya(['terbit' => '2026-11-12', 'status' => Certificate::STATUS_DIBATALKAN]);
        $this->saya(['terbit' => '2026-11-12', 'status' => Certificate::STATUS_GAGAL]);
        $this->saya(['terbit' => '2026-11-12', 'status' => Certificate::STATUS_DITARIK]);
        $this->saya(['terbit' => null, 'status' => Certificate::STATUS_MENUNGGU_GENERATE]);

        $this->assertSame(2, $this->ambil()['data']['total_dokumen']);
    }

    public function test_q_memakai_normalisasi_nomor_dan_dipagari_ke_perusahaan_sendiri(): void
    {
        $milikku = $this->saya(['terbit' => '2026-11-12', 'nomor' => 'CAL/2026/11/0001', 'serial' => 'PL-QSERI-1', 'order' => 'ORD/SAYA/1']);
        $this->saya(['terbit' => '2026-11-12', 'nomor' => 'CAL/2026/11/0002']);
        $this->bangunDokumen($this->milikLain->organization, [
            'pt' => $this->milikLain, 'terbit' => '2026-11-12', 'nomor' => 'CAL/2026/11/0003', 'serial' => 'PL-QSERI-LAIN', 'order' => 'ORD/LAIN/1',
        ]);

        foreach (['cal-2026-11-0001', 'CAL/2026/11/0001', 'pl-qseri-1', 'ord-saya-1'] as $q) {
            $this->assertSame(1, $this->ambil('q='.urlencode($q))['data']['total_dokumen'], "q={$q}");
        }

        // Dokumen perusahaan lain tidak tertemu lewat nomor, serial, maupun order-nya.
        foreach (['CAL/2026/11/0003', 'PL-QSERI-LAIN', 'ORD/LAIN/1'] as $q) {
            $this->assertSame(0, $this->ambil('q='.urlencode($q))['data']['total_dokumen'], "q={$q}");
        }

        $this->assertSame($milikku['sertifikat']->id, $this->ambil('tingkat=hari&tahun=2026&bulan=11&pekan=46&q=cal-2026-11-0001')['data']['item'][0]['id']);
    }

    public function test_parameter_tidak_sesuai_tingkat_ditolak_422(): void
    {
        foreach (['tingkat=bulan', 'tingkat=hari&tahun=2026&bulan=11', 'tingkat=pekan&tahun=2026&bulan=11&pekan=46', 'per_page=51', 'tingkat=minggu', 'q=a', 'q[]=ab'] as $query) {
            $this->permintaanBaru();
            $this->withHeaders($this->bearer($this->pic))->getJson(self::URI."?{$query}")->assertStatus(422);
        }
    }

    public function test_rute_terdaftar_di_atas_rute_ber_id_sehingga_tidak_dibaca_sebagai_id(): void
    {
        // Kalau urutannya terbalik, "garis-waktu" dibaca sebagai {sertifikat} -> 404.
        $this->permintaanBaru();
        $this->withHeaders($this->bearer($this->pic))->getJson(self::URI)->assertOk();

        $this->assertSame(
            'pelanggan.sertifikat.garis-waktu',
            app('router')->getRoutes()->match(Request::create(self::URI))->getName(),
        );
    }

    public function test_butuh_login_pelanggan(): void
    {
        $this->permintaanBaru();
        $this->getJson(self::URI)->assertUnauthorized();

        // Token internal (lab) ditolak di dunia pelanggan.
        $this->permintaanBaru();
        $this->withHeaders($this->bearerInternal($this->adminLab()))->getJson(self::URI)->assertForbidden();
    }
}
