<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DokumenArsipUji;
use Tests\TestCase;

/**
 * `GET /arsip/garis-waktu` -- arsip sertifikat HARI -> PEKAN -> BULAN -> TAHUN
 * menurut tanggal terbit, sebagai TAMPILAN. Pekan = ISO (Senin-Minggu), dipotong
 * per bulan agar hitungan pekan menjumlah ke bulannya.
 */
class ArsipGarisWaktuTest extends TestCase
{
    use DokumenArsipUji, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Customer $ptA;

    private Customer $ptB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->ptA = Customer::factory()->create(['organization_id' => $this->org->id, 'nama' => 'PT Garis Alfa']);
        $this->ptB = Customer::factory()->create(['organization_id' => $this->org->id, 'nama' => 'PT Garis Beta']);
    }

    /**
     * Data tetap: 2025 -> 1; 2026 -> Jan 1, Okt 2, Nov 6 = 9 (total 10).
     *
     * @param  array<string, mixed>  $tambahan
     */
    private function isiData(array $tambahan = []): void
    {
        $terbit = [
            '2025-12-30' => $this->ptA, // pekan 1/2026 (Senin 29 Des 2025)
            '2026-01-02' => $this->ptA, // pekan 1/2026
            '2026-10-26' => $this->ptA, // pekan 44
            '2026-10-31' => $this->ptB, // pekan 44
            '2026-11-01' => $this->ptA, // pekan 44 (Minggu)
            '2026-11-02' => $this->ptB, // pekan 45
            '2026-11-09' => $this->ptA, // pekan 46
            '2026-11-12' => $this->ptA, // pekan 46
            '2026-11-15' => $this->ptB, // pekan 46 (Minggu)
        ];

        foreach ($terbit as $tanggal => $pt) {
            $this->bangunDokumen($this->org, ['pt' => $pt, 'terbit' => $tanggal, ...$tambahan]);
        }

        // Kamis 12 Nov kedua, supaya satu hari punya dua dokumen.
        $this->bangunDokumen($this->org, ['pt' => $this->ptA, 'terbit' => '2026-11-12', 'serial' => 'GARIS-KEMBAR']);
    }

    /** @return array<string, mixed> */
    private function ambil(string $query = '', ?User $sebagai = null): array
    {
        return $this->actingAs($sebagai ?? $this->admin)
            ->getJson('/api/arsip/garis-waktu'.($query !== '' ? "?{$query}" : ''))
            ->assertOk()
            ->json();
    }

    /** @return array<string, int> kunci => jumlah */
    private function jumlahPerKunci(array $balasan): array
    {
        return collect($balasan['data']['kelompok'])->pluck('jumlah', 'kunci')->all();
    }

    public function test_tingkat_tahun_adalah_bawaan_dan_terbaru_dulu(): void
    {
        $this->isiData();

        $balasan = $this->ambil();

        $this->assertSame('tahun', $balasan['data']['tingkat']);
        $this->assertSame(['2026', '2025'], collect($balasan['data']['kelompok'])->pluck('kunci')->map(fn ($k) => (string) $k)->all());
        $this->assertSame([9, 1], collect($balasan['data']['kelompok'])->pluck('jumlah')->all());
        $this->assertSame(10, $balasan['data']['total_dokumen']);
        $this->assertSame([], $balasan['data']['item']);
        $this->assertSame(['tingkat' => 'bulan', 'tahun' => 2026], $balasan['data']['kelompok'][0]['telusuri']);
        $this->assertSame('2026', $balasan['data']['kelompok'][0]['label']);
        $this->assertSame(['2026-01-01', '2026-12-31'], [$balasan['data']['kelompok'][0]['mulai'], $balasan['data']['kelompok'][0]['selesai']]);
    }

    public function test_tingkat_bulan_menjumlah_ke_tahunnya(): void
    {
        $this->isiData();

        $balasan = $this->ambil('tingkat=bulan&tahun=2026');

        $this->assertSame(['2026-11' => 6, '2026-10' => 2, '2026-01' => 1], $this->jumlahPerKunci($balasan));
        $this->assertSame(9, array_sum($this->jumlahPerKunci($balasan)));
        $this->assertSame('November 2026', $balasan['data']['kelompok'][0]['label']);
        $this->assertSame(['tingkat' => 'pekan', 'tahun' => 2026, 'bulan' => 11], $balasan['data']['kelompok'][0]['telusuri']);
        $this->assertEquals(['tahun' => 2026], $balasan['data']['induk']);
    }

    public function test_tingkat_pekan_dipotong_per_bulan_dan_menjumlah_ke_bulannya(): void
    {
        $this->isiData();

        $nov = $this->ambil('tingkat=pekan&tahun=2026&bulan=11');
        $this->assertSame(['2026-W46' => 4, '2026-W45' => 1, '2026-W44' => 1], $this->jumlahPerKunci($nov));
        $this->assertSame(6, array_sum($this->jumlahPerKunci($nov)));
        $this->assertSame(
            ['Pekan 46 (9–15 Nov 2026)', 'Pekan 45 (2–8 Nov 2026)', 'Pekan 44 (1 Nov 2026)'],
            collect($nov['data']['kelompok'])->pluck('label')->all(),
        );
        $this->assertSame(
            ['tingkat' => 'hari', 'tahun' => 2026, 'bulan' => 11, 'pekan' => 46],
            $nov['data']['kelompok'][0]['telusuri'],
        );

        // Pekan 44 yang sama di Oktober hanya berisi hari-hari Oktober-nya.
        $okt = $this->ambil('tingkat=pekan&tahun=2026&bulan=10');
        $this->assertSame(['2026-W44' => 2], $this->jumlahPerKunci($okt));
        $this->assertSame('Pekan 44 (26–31 Okt 2026)', $okt['data']['kelompok'][0]['label']);
    }

    public function test_tingkat_hari_memuat_kelompok_harian_item_dan_halaman(): void
    {
        $this->isiData();

        $balasan = $this->ambil('tingkat=hari&tahun=2026&bulan=11&pekan=46&per_page=2');

        $this->assertSame(['2026-11-15' => 1, '2026-11-12' => 2, '2026-11-09' => 1], $this->jumlahPerKunci($balasan));
        $this->assertSame('Kamis, 12 Nov 2026', $balasan['data']['kelompok'][1]['label']);
        $this->assertSame(4, $balasan['data']['total_dokumen']);

        // Item: terbaru dulu, dua per halaman.
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 4], $balasan['meta']);
        $this->assertSame(['2026-11-15', '2026-11-12'], collect($balasan['data']['item'])->pluck('tanggal')->all());

        $halaman2 = $this->ambil('tingkat=hari&tahun=2026&bulan=11&pekan=46&per_page=2&page=2');
        $this->assertSame(['2026-11-12', '2026-11-09'], collect($halaman2['data']['item'])->pluck('tanggal')->all());
    }

    public function test_item_memuat_kolom_yang_diminta_dan_satu_hari_bisa_dipersempit(): void
    {
        $this->bangunDokumen($this->org, [
            'pt' => $this->ptA, 'terbit' => '2026-11-12', 'nomor' => 'CAL/2026/11/0077',
            'serial' => 'ITEM-SERI-1', 'alat' => 'Busur Derajat', 'order' => 'ORD/ITEM/1',
        ]);
        $this->bangunDokumen($this->org, ['pt' => $this->ptB, 'terbit' => '2026-11-13']);

        $balasan = $this->ambil('tingkat=hari&tahun=2026&bulan=11&pekan=46&tanggal=2026-11-12');

        $this->assertCount(1, $balasan['data']['item']);
        $item = $balasan['data']['item'][0];
        $this->assertSame('CAL/2026/11/0077', $item['nomor']);
        $this->assertSame('2026-11-12', $item['tanggal']);
        $this->assertSame('Busur Derajat', $item['nama_alat']);
        $this->assertSame('ITEM-SERI-1', $item['serial_number']);
        $this->assertSame('PT Garis Alfa', $item['perusahaan']);
        $this->assertSame($this->ptA->id, $item['customer_id']);
        $this->assertSame('ORD/ITEM/1', $item['nomor_order']);
        $this->assertSame('terbit', $item['status']);
        $this->assertNotEmpty($item['pdf_url']);
        $this->assertEquals(['tahun' => 2026, 'bulan' => 11, 'pekan' => 46, 'tanggal' => '2026-11-12'], $balasan['data']['induk']);
    }

    public function test_pekan_1_di_akhir_desember_dan_awal_januari(): void
    {
        $this->isiData();

        $des = $this->ambil('tingkat=hari&tahun=2025&bulan=12&pekan=1');
        $this->assertSame(['2025-12-30' => 1], $this->jumlahPerKunci($des));

        $jan = $this->ambil('tingkat=hari&tahun=2026&bulan=1&pekan=1');
        $this->assertSame(['2026-01-02' => 1], $this->jumlahPerKunci($jan));
    }

    public function test_tanggal_terbit_pada_batas_pekan_tidak_hilang(): void
    {
        // Senin 2 Nov & Minggu 8 Nov = ujung pekan 45; kolom tanggal di SQLite
        // bertanda jam, jadi `BETWEEN` akan melewatkan hari terakhir.
        $this->bangunDokumen($this->org, ['terbit' => '2026-11-02']);
        $this->bangunDokumen($this->org, ['terbit' => '2026-11-08']);

        $this->assertSame(
            ['2026-11-08' => 1, '2026-11-02' => 1],
            $this->jumlahPerKunci($this->ambil('tingkat=hari&tahun=2026&bulan=11&pekan=45')),
        );
    }

    public function test_penyaring_customer_id_dan_q_menyempitkan_semua_tingkat(): void
    {
        $this->isiData();

        $this->assertSame(
            ['2026' => 6, '2025' => 1],
            collect($this->ambil("customer_id={$this->ptA->id}")['data']['kelompok'])->pluck('jumlah', 'kunci')->all(),
        );

        $q = $this->ambil('q=garis-kembar');
        $this->assertSame([1], collect($q['data']['kelompok'])->pluck('jumlah')->all());

        // Nomor sertifikat dinormalisasi dan tidak menggandakan hitungan.
        $this->bangunDokumen($this->org, ['terbit' => '2026-11-12', 'nomor' => 'CAL/2026/11/0123']);
        $this->assertSame([1], collect($this->ambil('q=cal-2026-11-0123')['data']['kelompok'])->pluck('jumlah')->all());
        $this->assertSame(1, $this->ambil('q=cal+2026+11+0123')['data']['total_dokumen']);

        $this->assertSame(0, $this->ambil('q=tidak-ada-begini')['data']['total_dokumen']);
    }

    public function test_hanya_dokumen_yang_sudah_terbit_dan_hanya_lab_sendiri(): void
    {
        $this->bangunDokumen($this->org, ['terbit' => '2026-11-12']);
        $this->bangunDokumen($this->org, ['terbit' => '2026-11-12', 'status' => Certificate::STATUS_DIBATALKAN]);
        $this->bangunDokumen($this->org, ['terbit' => '2026-11-12', 'status' => Certificate::STATUS_DITARIK]);
        $this->bangunDokumen($this->org, ['terbit' => '2026-11-12', 'status' => Certificate::STATUS_GAGAL]);
        $this->bangunDokumen($this->org, ['terbit' => null, 'status' => Certificate::STATUS_MENUNGGU_GENERATE]);
        $this->bangunDokumen(Organization::factory()->create(), ['terbit' => '2026-11-12']);

        $this->assertSame(3, $this->ambil()['data']['total_dokumen']);
    }

    public function test_teknisi_hanya_melihat_sertifikat_sesinya_dan_viewer_semuanya(): void
    {
        $teknisi = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_TEKNISI]);
        $viewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_VIEWER]);
        $this->bangunDokumen($this->org, ['terbit' => '2026-11-12', 'teknisi' => $teknisi]);
        $this->bangunDokumen($this->org, ['terbit' => '2026-11-12']);

        $this->assertSame(1, $this->ambil('', $teknisi)['data']['total_dokumen']);
        $this->assertSame(2, $this->ambil('', $viewer)['data']['total_dokumen']);
        $this->assertSame(2, $this->ambil('', $this->admin)['data']['total_dokumen']);
    }

    public function test_parameter_yang_tidak_sesuai_tingkat_ditolak_422(): void
    {
        foreach ([
            'tingkat=bulan',
            'tingkat=pekan&tahun=2026',
            'tingkat=hari&tahun=2026&bulan=11',
            'tingkat=tahun&tahun=2026',
            'tingkat=pekan&tahun=2026&bulan=11&pekan=46',
            'tingkat=hari&tahun=2026&bulan=11&pekan=20',
            'tingkat=hari&tahun=2026&bulan=11&pekan=46&tanggal=2026-11-20',
            'tingkat=mingguan',
            'tingkat=bulan&tahun=1999',
            'tingkat=pekan&tahun=2026&bulan=13',
            'tingkat=hari&tahun=2026&bulan=11&pekan=46&tanggal=12-11-2026',
            'per_page=51',
            // `q`: rute baru, minimal 2 huruf dan harus teks.
            'q=a',
            'q[]=ab',
        ] as $query) {
            $this->actingAs($this->admin)->getJson("/api/arsip/garis-waktu?{$query}")
                ->assertStatus(422);
        }
    }

    public function test_tidak_menambah_query_per_item(): void
    {
        $hitung = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->ambil('tingkat=hari&tahun=2026&bulan=11&pekan=46&per_page=50');

            return count(DB::getQueryLog());
        };

        $this->bangunDokumen($this->org, ['terbit' => '2026-11-12', 'order' => 'ORD/N1/1']);
        $this->bangunDokumen($this->org, ['terbit' => '2026-11-12']);
        $sedikit = $hitung();

        foreach (range(1, 10) as $i) {
            $this->bangunDokumen($this->org, ['terbit' => '2026-11-12', 'order' => 'ORD/N1/'.($i + 1)]);
        }

        $this->assertSame($sedikit, $hitung(), 'Jumlah query bertambah menurut jumlah item (N+1).');
    }

    public function test_alat_yang_dihapus_lunak_tetap_tampil_di_item(): void
    {
        $this->bangunDokumen($this->org, ['terbit' => '2026-11-12', 'serial' => 'DIHAPUS-GW', 'hapus_alat' => true, 'pt' => $this->ptA]);

        $item = $this->ambil('tingkat=hari&tahun=2026&bulan=11&pekan=46')['data']['item'][0];

        $this->assertSame('DIHAPUS-GW', $item['serial_number']);
        $this->assertSame('PT Garis Alfa', $item['perusahaan']);
    }
}
