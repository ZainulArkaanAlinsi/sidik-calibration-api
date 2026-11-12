<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DokumenArsipUji;
use Tests\TestCase;

/**
 * `GET /calibrations` (Riwayat) -- parameter TAMBAHAN `q`, `dari`, `sampai`,
 * `customer_id`, `per_page`, `bentuk=ringan`. Tanpa satu pun dari mereka
 * respons dan urutannya sama dengan sebelumnya (15 per halaman, `id` menurun,
 * bentuk penuh).
 */
class RiwayatSesiDaftarTest extends TestCase
{
    use DokumenArsipUji, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_TEKNISI]);
    }

    /** @return list<int> */
    private function idSesi(string $uri, ?User $sebagai = null): array
    {
        return collect($this->actingAs($sebagai ?? $this->admin)->getJson($uri)->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    public function test_tanpa_parameter_baru_bentuk_urutan_dan_halaman_tidak_berubah(): void
    {
        $dibuat = collect(range(1, 17))->map(fn () => $this->bangunDokumen($this->org)['sesi']->id);

        $balasan = $this->actingAs($this->admin)->getJson('/api/calibrations')->assertOk();

        $this->assertSame(15, $balasan->json('meta.per_page'));
        $this->assertSame(17, $balasan->json('meta.total'));
        $this->assertSame($dibuat->reverse()->take(15)->values()->all(), collect($balasan->json('data'))->pluck('id')->all());
        // Bentuk penuh: `titik` ada (kecuali `ringkas=1`), begitu pula blok berat.
        $this->assertArrayHasKey('titik', $balasan->json('data.0'));
        $this->assertArrayHasKey('standar_acuan', $balasan->json('data.0'));
    }

    public function test_cari_q_lewat_serial_sertifikat_order_pt_dan_nomor_sesi(): void
    {
        $a = $this->bangunDokumen($this->org, ['serial' => 'RWY-SERI-1', 'nomor' => 'CAL/2026/10/0001', 'order' => 'ORD/RWY/1', 'pt' => 'PT Riwayat Alfa']);
        $b = $this->bangunDokumen($this->org, ['serial' => 'RWY-SERI-2', 'nomor' => 'CAL/2026/10/0002', 'nomor_order' => 'PO-RWY-2', 'pt' => 'PT Riwayat Beta']);

        $this->assertSame([$a['sesi']->id], $this->idSesi('/api/calibrations?q=rwy-seri-1'));
        $this->assertSame([$a['sesi']->id], $this->idSesi('/api/calibrations?q='.urlencode('CAL/2026/10/0001')));
        $this->assertSame([$a['sesi']->id], $this->idSesi('/api/calibrations?q=cal-2026-10-0001'));
        $this->assertSame([$a['sesi']->id], $this->idSesi('/api/calibrations?q='.urlencode('ORD/RWY/1')));
        $this->assertSame([$b['sesi']->id], $this->idSesi('/api/calibrations?q=po-rwy-2'));
        $this->assertSame([$b['sesi']->id], $this->idSesi('/api/calibrations?q='.urlencode('riwayat beta')));
        $this->assertSame([$a['sesi']->id], $this->idSesi('/api/calibrations?q='.urlencode((string) $a['sesi']->nomor_sesi)));
        $this->assertSame([], $this->idSesi('/api/calibrations?q=tidak-ada-yang-begini'));
    }

    public function test_q_memberi_peringkat_serial_persis_di_atas_awalan(): void
    {
        $awalan = $this->bangunDokumen($this->org, ['serial' => '9001X']);
        $persis = $this->bangunDokumen($this->org, ['serial' => '9001']);
        $mengandung = $this->bangunDokumen($this->org, ['serial' => 'Z9001']);

        $this->assertSame(
            [$persis['sesi']->id, $awalan['sesi']->id, $mengandung['sesi']->id],
            $this->idSesi('/api/calibrations?q=9001'),
        );
    }

    public function test_teknisi_hanya_melihat_sesinya_sendiri_walau_q_cocok_ke_sesi_orang_lain(): void
    {
        $lain = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_TEKNISI]);
        $milikku = $this->bangunDokumen($this->org, ['serial' => 'ISO-SAMA-1', 'teknisi' => $this->teknisi]);
        $this->bangunDokumen($this->org, ['serial' => 'ISO-SAMA-2', 'teknisi' => $lain]);

        $this->assertSame([$milikku['sesi']->id], $this->idSesi('/api/calibrations?q=ISO-SAMA', $this->teknisi));
        $this->assertCount(2, $this->idSesi('/api/calibrations?q=ISO-SAMA', $this->admin));
    }

    public function test_lab_lain_tidak_pernah_ikut(): void
    {
        $labLain = Organization::factory()->create();
        $punyaLain = $this->bangunDokumen($labLain, ['serial' => 'LAB-LAIN-1', 'pt' => 'PT Lab Lain']);
        $mine = $this->bangunDokumen($this->org, ['serial' => 'LAB-LAIN-2']);

        $this->assertSame([$mine['sesi']->id], $this->idSesi('/api/calibrations?q=LAB-LAIN'));
        $this->assertSame([], $this->idSesi('/api/calibrations?customer_id='.$punyaLain['pt']->id));
    }

    public function test_rentang_tanggal_kalibrasi_kedua_ujungnya_termasuk(): void
    {
        $s1 = $this->bangunDokumen($this->org, ['tanggal_kalibrasi' => '2026-11-01']);
        $s5 = $this->bangunDokumen($this->org, ['tanggal_kalibrasi' => '2026-11-05']);
        $s10 = $this->bangunDokumen($this->org, ['tanggal_kalibrasi' => '2026-11-10']);
        $s11 = $this->bangunDokumen($this->org, ['tanggal_kalibrasi' => '2026-11-11']);

        $this->assertEqualsCanonicalizing(
            [$s5['sesi']->id, $s10['sesi']->id],
            $this->idSesi('/api/calibrations?dari=2026-11-05&sampai=2026-11-10'),
        );
        $this->assertEqualsCanonicalizing(
            [$s10['sesi']->id, $s11['sesi']->id],
            $this->idSesi('/api/calibrations?dari=2026-11-10'),
        );
        $this->assertEqualsCanonicalizing(
            [$s1['sesi']->id],
            $this->idSesi('/api/calibrations?sampai=2026-11-01'),
        );
    }

    public function test_rentang_tanggal_yang_salah_ditolak_422(): void
    {
        $this->actingAs($this->admin)->getJson('/api/calibrations?dari=2026-11-10&sampai=2026-11-01')
            ->assertStatus(422)->assertJsonValidationErrors('sampai');
        $this->actingAs($this->admin)->getJson('/api/calibrations?dari=10-11-2026')
            ->assertStatus(422)->assertJsonValidationErrors('dari');
        // `sampai` sendirian tidak boleh dibandingkan dengan kata "dari".
        $this->actingAs($this->admin)->getJson('/api/calibrations?sampai=2026-11-01')->assertOk();
    }

    public function test_filter_customer_id_termasuk_alat_yang_sudah_dihapus(): void
    {
        $pt = Customer::factory()->create(['organization_id' => $this->org->id, 'nama' => 'PT Filter Satu']);
        $milikPt = $this->bangunDokumen($this->org, ['pt' => $pt, 'hapus_alat' => true]);
        $this->bangunDokumen($this->org);

        $this->assertSame([$milikPt['sesi']->id], $this->idSesi('/api/calibrations?customer_id='.$pt->id));
    }

    public function test_per_page_maksimal_50(): void
    {
        foreach (range(1, 3) as $i) {
            $this->bangunDokumen($this->org);
        }

        $this->actingAs($this->admin)->getJson('/api/calibrations?per_page=50')
            ->assertOk()->assertJsonPath('meta.per_page', 50);
        $this->actingAs($this->admin)->getJson('/api/calibrations?per_page=2')
            ->assertOk()->assertJsonPath('meta.per_page', 2)->assertJsonCount(2, 'data');
        $this->actingAs($this->admin)->getJson('/api/calibrations?per_page=51')
            ->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->actingAs($this->admin)->getJson('/api/calibrations?per_page=0')->assertStatus(422);
    }

    public function test_memuat_semua_halaman_tanpa_ganda_dan_tanpa_lompat_walau_tanggal_dan_peringkat_kembar(): void
    {
        // 23 sesi, tanggal kalibrasi SAMA, semuanya cocok ke q yang sama dan
        // terbagi tiga peringkat (persis, awalan, mengandung) -- urutan harus
        // tetap total walau banyak baris berperingkat kembar.
        foreach (range(1, 23) as $i) {
            $this->bangunDokumen($this->org, [
                'serial' => match (true) {
                    $i === 23 => 'PAGE',
                    $i % 2 === 0 => 'PAGE-'.$i,
                    default => 'XPAGE-'.$i,
                },
                'tanggal_kalibrasi' => '2026-11-12',
            ]);
        }

        $terkumpul = [];
        $halaman = 1;
        do {
            $balasan = $this->actingAs($this->admin)
                ->getJson("/api/calibrations?q=PAGE&per_page=5&page={$halaman}&bentuk=ringan")
                ->assertOk();
            array_push($terkumpul, ...collect($balasan->json('data'))->pluck('id')->all());
            $terakhir = $balasan->json('meta.last_page');
            $halaman++;
        } while ($halaman <= $terakhir);

        $this->assertCount(23, $terkumpul);
        $this->assertSame($terkumpul, array_values(array_unique($terkumpul)), 'Ada baris ganda antar halaman.');

        // Urutan = peringkat (persis, awalan, mengandung) lalu id menurun.
        $peringkat = fn (CalibrationSession $s): int => match (true) {
            $s->equipment->serial_number === 'PAGE' => 0,
            str_starts_with($s->equipment->serial_number, 'PAGE') => 1,
            default => 2,
        };
        $diharapkan = CalibrationSession::query()->with('equipment')->get()
            ->sortBy([
                fn ($a, $b) => $peringkat($a) <=> $peringkat($b),
                fn ($a, $b) => $b->id <=> $a->id,
            ])->pluck('id')->values()->all();
        $this->assertSame($diharapkan, $terkumpul);
    }

    public function test_bentuk_ringan_membuang_blok_berat_tetapi_memuat_yang_dibaca_daftar(): void
    {
        $d = $this->bangunDokumen($this->org, ['serial' => 'RINGAN-1', 'nomor' => 'CAL/2026/10/0007', 'pt' => 'PT Ringan Uji']);

        $baris = $this->actingAs($this->admin)->getJson('/api/calibrations?bentuk=ringan')
            ->assertOk()->json('data.0');

        foreach (['titik', 'standar_acuan', 'metode_kalibrasi', 'reviewer', 'pembacaan_mentah', 'hasil_autoclave', 'spesifikasi_alat'] as $berat) {
            $this->assertArrayNotHasKey($berat, $baris, "Kunci berat `{$berat}` ikut terkirim di bentuk ringan.");
        }

        $this->assertSame($d['sesi']->id, $baris['id']);
        $this->assertSame($d['sesi']->nomor_sesi, $baris['nomor_sesi']);
        $this->assertSame('RINGAN-1', $baris['equipment']['serial_number']);
        $this->assertSame($d['alat']->nama_alat, $baris['equipment']['nama_alat']);
        $this->assertNotNull($baris['equipment']['profil']);
        $this->assertSame('PT Ringan Uji', $baris['pelanggan']['nama']);
        $this->assertSame('CAL/2026/10/0007', $baris['sertifikat']['nomor']);
        $this->assertSame($d['sertifikat']->id, $baris['certificate_id']);
        $this->assertSame('PASS', $baris['hasil']['keputusan']);
        $this->assertFalse($baris['tersembunyi']);
        $this->assertSame($d['sesi']->tanggal_kalibrasi->toDateString(), $baris['tanggal_kalibrasi']);
    }

    public function test_bentuk_ringan_tidak_menambah_query_per_baris_dan_alat_terhapus_tetap_bernama(): void
    {
        $this->bangunDokumen($this->org, ['serial' => 'N1-A', 'hapus_alat' => true]);
        $this->bangunDokumen($this->org);

        $hitung = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->getJson('/api/calibrations?bentuk=ringan&per_page=50')->assertOk();

            return count(DB::getQueryLog());
        };

        $dua = $hitung();
        foreach (range(1, 10) as $i) {
            $this->bangunDokumen($this->org);
        }
        $dua_belas = $hitung();

        $this->assertSame($dua, $dua_belas, 'Jumlah query bertambah menurut jumlah baris (N+1).');

        $baris = collect($this->actingAs($this->admin)->getJson('/api/calibrations?bentuk=ringan&per_page=50')->json('data'))
            ->firstWhere('equipment.serial_number', 'N1-A');
        $this->assertNotNull($baris, 'Sesi dari alat yang dihapus lunak hilang dari daftar ringan.');
        $this->assertSame('Feeler Gauge', $baris['equipment']['nama_alat']);
    }

    public function test_bentuk_yang_tidak_dikenal_ditolak(): void
    {
        $this->actingAs($this->admin)->getJson('/api/calibrations?bentuk=mini')
            ->assertStatus(422)->assertJsonValidationErrors('bentuk');
    }

    public function test_status_tersembunyi_tetap_benar_saat_q_dan_bentuk_ringan_dipakai(): void
    {
        $d = $this->bangunDokumen($this->org, ['serial' => 'SEMBUNYI-1']);
        $this->actingAs($this->admin)->postJson("/api/calibrations/{$d['sesi']->id}/sembunyikan")->assertOk();

        foreach (['/api/calibrations?q=SEMBUNYI-1', '/api/calibrations?q=SEMBUNYI-1&bentuk=ringan'] as $uri) {
            $this->assertTrue(
                $this->actingAs($this->admin)->getJson($uri)->assertOk()->json('data.0.tersembunyi'),
                "tersembunyi hilang di {$uri}",
            );
        }
    }
}
