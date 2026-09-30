<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Penyaring tambahan untuk layar jatuh tempo & pusat pelanggan di aplikasi lab
 * (30 Sep 2026): `GET /api/equipments` dan `GET /api/certificates`.
 *
 * Dua hal yang dijaga: (1) TANPA parameter baru perilakunya sama persis dengan
 * sebelumnya, dan (2) `customer_id` milik lab lain tidak membuka data lab lain —
 * `organization_id` tetap mengurung hasilnya.
 */
class FilterAlatDanSertifikatPerPelangganTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private User $admin;

    private Customer $pelangganA;

    private Customer $pelangganB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminLab();
        $org = $this->organisasi()->id;

        $this->pelangganA = Customer::factory()->create(['organization_id' => $org, 'nama' => 'PT Alpha']);
        $this->pelangganB = Customer::factory()->create(['organization_id' => $org, 'nama' => 'PT Beta']);
    }

    private function alat(Customer $pelanggan, ?string $jatuhTempo, array $tambahan = []): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $pelanggan->organization_id,
            'customer_id' => $pelanggan->id,
            'tanggal_jatuh_tempo' => $jatuhTempo,
            ...$tambahan,
        ]);
    }

    private function ambil(string $uri)
    {
        $this->permintaanBaru();

        return $this->withHeaders($this->bearerInternal($this->admin))->getJson($uri);
    }

    /** @return list<int> */
    private function idAlat(string $uri): array
    {
        return collect($this->ambil($uri)->assertOk()->json('data'))->pluck('id')->all();
    }

    // ------------------------------------------------------------------ alat

    public function test_tanpa_parameter_baru_daftar_persis_seperti_dulu_terbaru_dulu(): void
    {
        $tua = $this->alat($this->pelangganA, now()->addDays(5)->toDateString());
        $tengah = $this->alat($this->pelangganB, now()->addDays(100)->toDateString());
        $baru = $this->alat($this->pelangganA, null);

        $this->assertSame([$baru->id, $tengah->id, $tua->id], $this->idAlat('/api/equipments'));
    }

    public function test_saring_per_pelanggan(): void
    {
        $a = $this->alat($this->pelangganA, now()->addDays(5)->toDateString());
        $this->alat($this->pelangganB, now()->addDays(5)->toDateString());

        $this->assertSame([$a->id], $this->idAlat('/api/equipments?customer_id='.$this->pelangganA->id));
    }

    public function test_customer_id_lab_lain_memulangkan_kosong_bukan_data_lab_lain(): void
    {
        $labLain = Organization::factory()->create();
        $pelangganLain = Customer::factory()->create(['organization_id' => $labLain->id]);
        Equipment::factory()->create([
            'organization_id' => $labLain->id,
            'customer_id' => $pelangganLain->id,
            'nama_alat' => 'BOCOR-ALAT-LAB-LAIN',
        ]);

        $respons = $this->ambil('/api/equipments?customer_id='.$pelangganLain->id)->assertOk();

        $this->assertSame([], $respons->json('data'));
        $this->assertStringNotContainsString('BOCOR-ALAT-LAB-LAIN', $respons->getContent());
    }

    public function test_jatuh_tempo_dalam_n_hari_hanya_yang_aktif_dan_belum_lewat(): void
    {
        $dalam = $this->alat($this->pelangganA, now()->addDays(10)->toDateString());
        $batas = $this->alat($this->pelangganA, now()->addDays(30)->toDateString());
        $this->alat($this->pelangganA, now()->addDays(31)->toDateString());          // di luar
        $this->alat($this->pelangganA, now()->subDays(3)->toDateString());           // sudah lewat
        $this->alat($this->pelangganA, null);                                         // tanpa jadwal
        $this->alat($this->pelangganA, now()->addDays(5)->toDateString(), ['status' => Equipment::STATUS_NONAKTIF]);
        $hariIni = $this->alat($this->pelangganA, now()->toDateString());

        $hasil = $this->idAlat('/api/equipments?jatuh_tempo_dalam=30');

        $this->assertEqualsCanonicalizing([$dalam->id, $batas->id, $hariIni->id], $hasil);
    }

    public function test_termasuk_lewat_menyertakan_yang_sudah_lewat(): void
    {
        $lewat = $this->alat($this->pelangganA, now()->subDays(3)->toDateString());
        $dalam = $this->alat($this->pelangganA, now()->addDays(10)->toDateString());
        $this->alat($this->pelangganA, now()->addDays(90)->toDateString());

        $hasil = $this->idAlat('/api/equipments?jatuh_tempo_dalam=30&termasuk_lewat=1');

        $this->assertEqualsCanonicalizing([$lewat->id, $dalam->id], $hasil);
    }

    public function test_urut_jatuh_tempo_paling_lama_dulu_dan_tanpa_jadwal_di_dasar(): void
    {
        $tanpa = $this->alat($this->pelangganA, null);
        $jauh = $this->alat($this->pelangganA, now()->addDays(60)->toDateString());
        $lewat = $this->alat($this->pelangganA, now()->subDays(12)->toDateString());
        $dekat = $this->alat($this->pelangganA, now()->addDays(3)->toDateString());

        $this->assertSame(
            [$lewat->id, $dekat->id, $jauh->id, $tanpa->id],
            $this->idAlat('/api/equipments?urut=jatuh_tempo'),
        );
    }

    public function test_kombinasi_pelanggan_jatuh_tempo_dan_urut(): void
    {
        $dekat = $this->alat($this->pelangganA, now()->addDays(3)->toDateString());
        $jauh = $this->alat($this->pelangganA, now()->addDays(20)->toDateString());
        $this->alat($this->pelangganB, now()->addDays(2)->toDateString());

        $this->assertSame(
            [$dekat->id, $jauh->id],
            $this->idAlat('/api/equipments?customer_id='.$this->pelangganA->id.'&jatuh_tempo_dalam=30&urut=jatuh_tempo'),
        );
    }

    public function test_parameter_ngawur_ditolak_bukan_dibaca_nol(): void
    {
        $this->ambil('/api/equipments?jatuh_tempo_dalam=abc')->assertUnprocessable();
        $this->ambil('/api/equipments?jatuh_tempo_dalam=-1')->assertUnprocessable();
        $this->ambil('/api/equipments?urut=acak')->assertUnprocessable();
        $this->ambil('/api/equipments?customer_id=abc')->assertUnprocessable();
    }

    public function test_penyaring_lama_tetap_jalan_berdampingan_dengan_yang_baru(): void
    {
        $overdue = $this->alat($this->pelangganA, now()->subDays(3)->toDateString());
        $this->alat($this->pelangganA, now()->addDays(3)->toDateString());

        $this->assertSame([$overdue->id], $this->idAlat('/api/equipments?status=overdue&customer_id='.$this->pelangganA->id));
    }

    // ------------------------------------------------------------------ sertifikat

    private function sertifikat(Customer $pelanggan, string $nomor, bool $alatDihapus = false): Certificate
    {
        $alat = $this->alat($pelanggan, now()->addYear()->toDateString());

        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $pelanggan->organization_id,
            'equipment_id' => $alat->id,
            'status' => CalibrationSession::STATUS_DISETUJUI,
        ]);

        $sertifikat = Certificate::factory()->create([
            'organization_id' => $pelanggan->organization_id,
            'calibration_session_id' => $sesi->id,
            'nomor' => $nomor,
        ]);

        if ($alatDihapus) {
            $alat->delete();
        }

        return $sertifikat;
    }

    public function test_sertifikat_bisa_disaring_per_pelanggan(): void
    {
        $a1 = $this->sertifikat($this->pelangganA, 'CAL/2026/10/0001');
        $a2 = $this->sertifikat($this->pelangganA, 'CAL/2026/10/0002');
        $this->sertifikat($this->pelangganB, 'CAL/2026/10/0003');

        $semua = $this->ambil('/api/certificates')->assertOk();
        $this->assertCount(3, $semua->json('data'));

        $hasil = $this->ambil('/api/certificates?customer_id='.$this->pelangganA->id)->assertOk();

        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], collect($hasil->json('data'))->pluck('id')->all());
    }

    public function test_sertifikat_alat_yang_sudah_dihapus_tetap_milik_pelanggannya(): void
    {
        $s = $this->sertifikat($this->pelangganA, 'CAL/2026/10/0009', alatDihapus: true);

        $hasil = $this->ambil('/api/certificates?customer_id='.$this->pelangganA->id)->assertOk();

        $this->assertSame([$s->id], collect($hasil->json('data'))->pluck('id')->all());
    }

    public function test_sertifikat_customer_id_lab_lain_tidak_membuka_data_lab_lain(): void
    {
        $labLain = Organization::factory()->create();
        $pelangganLain = Customer::factory()->create(['organization_id' => $labLain->id]);
        $this->sertifikat($pelangganLain, 'BOCOR-CAL/LAB-LAIN');

        $respons = $this->ambil('/api/certificates?customer_id='.$pelangganLain->id)->assertOk();

        $this->assertSame([], $respons->json('data'));
        $this->assertStringNotContainsString('BOCOR-CAL', $respons->getContent());
    }
}
