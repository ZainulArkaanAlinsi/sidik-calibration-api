<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\TrenPekerjaan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `GET /api/dashboard/tren` (permintaan-endpoint.md §2).
 *
 * Yang paling dijaga: **angkanya harus sama dengan `grafik_pekerjaan` di
 * `/dashboard`.** Dua grafik di app yang sama nunjukin angka beda buat bulan yang
 * sama itu bikin dua-duanya nggak kepakai — yang lihat nggak punya cara tahu mana
 * yang bener. Makanya dua-duanya narik dari `TrenPekerjaan`, dan itu dikunci test
 * di sini.
 */
class DashboardTrenTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/dashboard/tren';

    private User $admin;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        Customer::factory()->create();
        EquipmentCategory::factory()->create(['kode' => 'panjang']);
        Equipment::factory()->create();

        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
    }

    private function sesi(
        User $teknisi,
        string|Carbon $tanggalKalibrasi,
        ?string $status = null,
        string|Carbon|null $reviewedAt = null,
    ): CalibrationSession {
        return CalibrationSession::create([
            'organization_id' => $teknisi->organization_id,
            'equipment_id' => Equipment::query()->value('id'),
            'teknisi_id' => $teknisi->id,
            'status' => $status ?? CalibrationSession::STATUS_DRAFT,
            'tanggal_kalibrasi' => $tanggalKalibrasi,
            'reviewed_at' => $reviewedAt,
        ]);
    }

    /** @return array<string, array<string, mixed>> periode => baris */
    private function perPeriode(array $data): array
    {
        $hasil = [];

        foreach ($data as $baris) {
            $hasil[$baris['periode']] = $baris;
        }

        return $hasil;
    }

    // -------------------------------------------------------------- bentuk

    public function test_tanpa_token_ditolak(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_bentuk_responsnya_sesuai_permintaan(): void
    {
        $this->actingAs($this->admin)
            ->getJson(self::URL.'?satuan=bulan')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['periode', 'label', 'masuk', 'selesai']],
                'penyaring' => ['dari', 'sampai', 'satuan'],
            ])
            ->assertJsonPath('penyaring.satuan', 'bulan');
    }

    /** Semua role boleh — viewer juga, sama kayak `/dashboard`. */
    public function test_viewer_boleh_baca(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]))
            ->getJson(self::URL)
            ->assertOk();
    }

    // ------------------------------------------------------------ hitungan

    public function test_ngitung_masuk_dan_selesai_per_bulan(): void
    {
        // Masuk Mei, disetujui Juni → kehitung di dua periode yang beda.
        $this->sesi(
            $this->admin,
            '2026-05-10',
            CalibrationSession::STATUS_DISETUJUI,
            Carbon::parse('2026-06-02 09:00'),
        );
        $this->sesi($this->admin, '2026-05-20');

        $data = $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2026-05-01&sampai=2026-07-31&satuan=bulan')
            ->assertOk()
            ->json('data');

        $per = $this->perPeriode($data);

        $this->assertSame(2, $per['2026-05']['masuk']);
        $this->assertSame(0, $per['2026-05']['selesai']);
        $this->assertSame(0, $per['2026-06']['masuk']);
        $this->assertSame(1, $per['2026-06']['selesai'], '`selesai` diambil dari reviewed_at, bukan tanggal_kalibrasi.');
    }

    /**
     * Periode KOSONG tetap keluar dengan nilai 0 — permintaannya eksplisit.
     *
     * Kalau di-skip, grafiknya bohong: jeda kosong ketutup dan tren naik-turunnya
     * kelihatan lebih mulus dari kenyataan.
     */
    public function test_periode_kosong_tetap_dikirim_dengan_nol(): void
    {
        $this->sesi($this->admin, '2026-05-10');
        $this->sesi($this->admin, '2026-07-10');

        $data = $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2026-05-01&sampai=2026-07-31&satuan=bulan')
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $data, 'Juni yang kosong harus tetap ada.');
        $this->assertSame(['2026-05', '2026-06', '2026-07'], array_column($data, 'periode'));
        $this->assertSame(0, $this->perPeriode($data)['2026-06']['masuk']);
    }

    public function test_satuan_hari(): void
    {
        $this->sesi($this->admin, '2026-07-02');
        $this->sesi($this->admin, '2026-07-02');

        $data = $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2026-07-01&sampai=2026-07-03&satuan=hari')
            ->assertOk()
            ->json('data');

        $this->assertSame(['2026-07-01', '2026-07-02', '2026-07-03'], array_column($data, 'periode'));
        $this->assertSame(2, $this->perPeriode($data)['2026-07-02']['masuk']);
    }

    public function test_satuan_minggu(): void
    {
        // 2026-07-06 itu Senin; 07-08 masih minggu yang sama.
        $this->sesi($this->admin, '2026-07-06');
        $this->sesi($this->admin, '2026-07-08');
        $this->sesi($this->admin, '2026-07-14');

        $data = $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2026-07-06&sampai=2026-07-19&satuan=minggu')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);
        $this->assertSame(2, $data[0]['masuk'], 'Dua sesi di minggu yang sama harus kegabung.');
        $this->assertSame(1, $data[1]['masuk']);
        // Label minggu dipakai tanggal MULAInya — "W29" nggak berarti apa-apa
        // buat orang yang lihat grafik.
        $this->assertMatchesRegularExpression('/^\d{2} \w+$/', $data[0]['label']);
    }

    /**
     * Minggu ISO di pergantian tahun.
     *
     * 29 Des 2025 itu minggu ke-1 tahun **2026** menurut ISO. Kalau kuncinya pakai
     * `Y-\WW` (tahun kalender) bukan `o-\WW` (tahun ISO), dua minggu yang beda
     * dapat kunci sama (`2025-W01`) dan angkanya kegabung diam-diam.
     */
    public function test_minggu_di_pergantian_tahun_nggak_kegabung(): void
    {
        $this->sesi($this->admin, '2025-12-29'); // minggu ISO 2026-W01
        $this->sesi($this->admin, '2025-12-22'); // minggu ISO 2025-W52

        $data = $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2025-12-22&sampai=2026-01-04&satuan=minggu')
            ->assertOk()
            ->json('data');

        $periode = array_column($data, 'periode');

        $this->assertSame(count($periode), count(array_unique($periode)), 'Kunci minggu nggak boleh dobel.');
        $this->assertContains('2025-W52', $periode);
        $this->assertContains('2026-W01', $periode);
        foreach ($data as $baris) {
            $this->assertSame(1, $baris['masuk'], "Periode {$baris['periode']} harusnya 1, bukan kegabung.");
        }
    }

    /**
     * `dari` di tengah/akhir bulan tetap dinormalisasi ke awal bulan.
     *
     * Dikirim `dari=2026-01-31`, yang keluar harus mulai dari `2026-01` — bukan
     * ngelewatin Januari karena tanggalnya udah lewat, dan bukan ngelewatin
     * Februari karena 31 Jan + 1 bulan nyelonong ke Maret.
     *
     * Catatan jujur: bagian "nyelonong ke Maret" itu **nggak** kena di test ini,
     * karena kursornya udah dinormalisasi ke tanggal 1 sebelum dimajukan — jadi
     * `addMonth()` biasa pun lolos. Yang beneran dijaga di sini normalisasi
     * INPUT-nya.
     */
    public function test_dari_di_akhir_bulan_tetap_mulai_dari_awal_bulan(): void
    {
        $data = $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2026-01-31&sampai=2026-04-30&satuan=bulan')
            ->assertOk()
            ->json('data');

        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04'], array_column($data, 'periode'));
    }

    // -------------------------------------------------------------- akses

    /** Teknisi cuma dapat pekerjaannya sendiri — aturan yang sama kayak `/dashboard`. */
    public function test_teknisi_cuma_dapat_pekerjaannya_sendiri(): void
    {
        $this->sesi($this->teknisi, '2026-07-10');
        $this->sesi($this->admin, '2026-07-10');

        $adminLihat = $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2026-07-01&sampai=2026-07-31&satuan=bulan')
            ->json('data.0.masuk');

        $teknisiLihat = $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?dari=2026-07-01&sampai=2026-07-31&satuan=bulan')
            ->json('data.0.masuk');

        $this->assertSame(2, $adminLihat);
        $this->assertSame(1, $teknisiLihat);
    }

    public function test_sesi_organisasi_lain_nggak_kehitung(): void
    {
        $orgLain = Organization::factory()->create();
        $alatLain = Equipment::factory()->create(['organization_id' => $orgLain->id]);
        $userLain = User::factory()->create(['organization_id' => $orgLain->id]);

        CalibrationSession::create([
            'organization_id' => $orgLain->id,
            'equipment_id' => $alatLain->id,
            'teknisi_id' => $userLain->id,
            'status' => CalibrationSession::STATUS_DRAFT,
            'tanggal_kalibrasi' => '2026-07-10',
        ]);

        $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2026-07-01&sampai=2026-07-31&satuan=bulan')
            ->assertOk()
            ->assertJsonPath('data.0.masuk', 0);
    }

    // ----------------------------------------------------------- validasi

    public function test_satuan_ngawur_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->getJson(self::URL.'?satuan=jam')
            ->assertStatus(422)
            ->assertJsonValidationErrors('satuan');
    }

    public function test_sampai_sebelum_dari_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2026-07-31&sampai=2026-07-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sampai');
    }

    /**
     * Rentang kepanjangan DITOLAK, bukan dipotong.
     *
     * Hasil yang dipotong sepi-sepi bikin orang salah baca tren — dan 400+ batang
     * di layar HP itu garis abu-abu, bukan grafik.
     */
    public function test_terlalu_banyak_periode_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2020-01-01&sampai=2026-12-31&satuan=hari')
            ->assertStatus(422)
            ->assertJsonValidationErrors('satuan');
    }

    /** Satuan yang lebih besar bikin rentang yang sama jadi masuk. */
    public function test_rentang_panjang_lolos_kalau_satuannya_dinaikin(): void
    {
        $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2020-01-01&sampai=2026-12-31&satuan=bulan')
            ->assertOk();
    }

    public function test_tanpa_penyaring_pakai_bawaan(): void
    {
        $res = $this->actingAs($this->admin)->getJson(self::URL)->assertOk();

        $this->assertSame('bulan', $res->json('penyaring.satuan'));
        $this->assertCount(6, $res->json('data'), 'Bawaannya 6 bulan, sepanjang grafik Dashboard.');
    }

    // ----------------------------------------- angkanya sama dengan /dashboard

    /**
     * INTI: `/dashboard/tren?satuan=bulan` harus ngasih angka yang SAMA dengan
     * `grafik_pekerjaan` di `/dashboard` buat bulan yang sama.
     *
     * Kalau dua-duanya ngitung sendiri, satu bisa mulai geser dari yang lain tanpa
     * ada yang sadar — dan dua grafik dengan angka beda buat bulan yang sama bikin
     * dua-duanya nggak kepakai.
     */
    public function test_angkanya_sama_dengan_grafik_pekerjaan_di_dashboard(): void
    {
        $this->sesi($this->admin, now()->subMonths(2), CalibrationSession::STATUS_DISETUJUI, now()->subMonth());
        $this->sesi($this->admin, now()->subMonth());
        $this->sesi($this->admin, now());
        $this->sesi($this->teknisi, now());

        $dashboard = $this->actingAs($this->admin)->getJson('/api/dashboard')
            ->assertOk()->json('data.grafik_pekerjaan');

        $tren = $this->actingAs($this->admin)->getJson(self::URL.'?satuan=bulan')
            ->assertOk()->json('data');

        $this->assertCount(count($dashboard), $tren);

        foreach ($dashboard as $i => $baris) {
            $this->assertSame($baris['bulan'], $tren[$i]['periode'], "Periode ke-{$i} beda.");
            $this->assertSame($baris['masuk'], $tren[$i]['masuk'], "`masuk` bulan {$baris['bulan']} beda.");
            $this->assertSame($baris['selesai'], $tren[$i]['selesai'], "`selesai` bulan {$baris['bulan']} beda.");
            $this->assertSame($baris['label'], $tren[$i]['label']);
        }
    }

    /**
     * Nama kuncinya beda per endpoint, dan itu SENGAJA.
     *
     * `grafik_pekerjaan` pakai `bulan`; `/dashboard/tren` pakai `periode`.
     * `kontrak-api.md` §7 udah bilang eksplisit, dan mobile pernah kena bug nyata
     * gara-gara ketuker — sumbu X grafik Dashboard kosong melompong padahal semua
     * test ijo. Refactor ke service bersama nggak boleh diam-diam ngerapiin ini
     * jadi satu nama: yang pecah nanti layar yang udah jalan.
     */
    public function test_nama_kunci_dashboard_dan_tren_sengaja_beda(): void
    {
        $dashboard = $this->actingAs($this->admin)->getJson('/api/dashboard')
            ->assertOk()->json('data.grafik_pekerjaan.0');

        $this->assertArrayHasKey('bulan', $dashboard);
        $this->assertArrayNotHasKey('periode', $dashboard, '`grafik_pekerjaan` cuma pakai `bulan`.');

        $tren = $this->actingAs($this->admin)->getJson(self::URL)->assertOk()->json('data.0');

        $this->assertArrayHasKey('periode', $tren);
        $this->assertArrayNotHasKey('bulan', $tren, '`/dashboard/tren` cuma pakai `periode`.');
    }

    /** Batas periode dihitung SEBELUM datanya ditarik. */
    public function test_jumlah_periode_dihitung_bener_per_satuan(): void
    {
        $tren = app(TrenPekerjaan::class);
        $dari = Carbon::parse('2026-01-01');
        $sampai = Carbon::parse('2026-03-31');

        $this->assertSame(90, $tren->jumlahPeriode($dari, $sampai, TrenPekerjaan::SATUAN_HARI));
        $this->assertSame(3, $tren->jumlahPeriode($dari, $sampai, TrenPekerjaan::SATUAN_BULAN));
    }
}
