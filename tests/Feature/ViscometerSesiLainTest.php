<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\Profiles\ViscometerProfile;
use Database\Seeders\ViscometerCapabilitySeeder;
use Database\Seeders\ViscometerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Viscometer lewat API dengan sesi yang **BUKAN** sesi master.
 *
 * `ViscometerApiTest` mengirim sesi master dan mengadu hasilnya ke workbook.
 * Itu membuktikan mesinnya sepadan dengan cara lab menghitung — tapi tidak
 * membuktikan apa pun tentang sesi berikutnya, dan sesi berikutnya itu yang
 * dipakai pelanggan.
 *
 * Di sini spindle, RPM, suhu larutan, dan seluruh pembacaannya berbeda dari
 * master. Angka pembandingnya **dihitung ulang di dalam test dari rumusnya**,
 * bukan disalin dari mana pun — jadi implementasi yang mengambil jalan pintas
 * yang kebetulan pas di master akan tertangkap di sini.
 *
 * Yang dijaga:
 *
 *  1. MPE ikut spindle & RPM sesi ini, bukan angka master yang tertinggal.
 *  2. Nilai acuan diinterpolasi pada suhu sesi ini — dan suhunya sengaja
 *     dipilih di ruas tabel yang berbeda dari master.
 *  3. Vonis PASS/FAIL benar-benar mengikuti perbandingan koreksi vs MPE,
 *     bukan selalu PASS.
 *  4. Angka `preview` identik dengan angka yang tersimpan. Viscometer belum
 *     pernah diuji di jalur ini — `CalibrationPreviewTest` memakai pH Meter,
 *     dan jalur MPE per titik milik alat ini tidak ada di sana.
 */
class ViscometerSesiLainTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI_SIMPAN = 1e-8;

    private User $teknisi;

    private Equipment $alat;

    /** @var array<string, Standard> */
    private array $standar = [];

    private ViscometerProfile $profil;

    protected function setUp(): void
    {
        parent::setUp();

        // `id` dipatok 1 — seeder proyek ini menulis `organization_id => 1`,
        // dan di MySQL rollback `RefreshDatabase` tidak mengembalikan
        // penghitung AUTO_INCREMENT. Lihat catatan panjang di
        // `ViscometerApiTest::setUp()`.
        $org = Organization::factory()->create(['id' => 1]);
        User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_TEKNISI]);

        $this->seed([ViscometerCapabilitySeeder::class, ViscometerSeeder::class]);

        $this->alat = Equipment::where('serial_number', '86068360')->firstOrFail();
        $this->teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();
        $this->profil = new ViscometerProfile;

        // Dikunci lewat LABEL titik, bukan urutan array — lihat alasan yang
        // sama di `ViscometerApiTest::setUp()`.
        foreach (ViscometerProfile::TITIK as $t) {
            $this->standar[$t['label']] = Standard::where('nama', $t['standar'][0])->firstOrFail();
        }
    }

    /**
     * Sesi karangan sendiri — tidak satu angka pun dari master.
     *
     * Spindle & RPM sengaja beda ketiganya (RV7/HB4/LV5 dengan 30/50/10 rpm),
     * dan suhunya dipilih di ruas tabel yang lain: master memakai 26,52 /
     * 27,3 / 24,6 °C, di sini 22,0 / 30,0 / 21,5 °C.
     *
     * @return list<array<string, mixed>>
     */
    private function measurementsLain(): array
    {
        return [
            [
                'titik_ukur' => 99.65,
                'standard_id' => $this->standar['100']->id,
                'satuan' => 'cP',
                'spindle' => 'RV7',   // SMC 400
                'rpm' => 30,
                'pembacaan' => [118.4, 118.1, 118.9, 118.2, 118.4],
                'suhu' => [22.0, 22.0, 22.0, 22.0, 22.0],
            ],
            [
                'titik_ukur' => 1018.0,
                'standard_id' => $this->standar['1000']->id,
                'satuan' => 'cP',
                'spindle' => 'HB4',   // SMC 20
                'rpm' => 50,
                'pembacaan' => [780.5, 781.2, 779.8, 780.9, 780.1],
                'suhu' => [30.0, 30.0, 30.0, 30.0, 30.0],
            ],
            [
                'titik_ukur' => 59003.0,
                'standard_id' => $this->standar['60000']->id,
                'satuan' => 'cP',
                'spindle' => 'LV5',   // SMC 1280
                'rpm' => 10,
                'pembacaan' => [79800.0, 79950.0, 79700.0, 79880.0, 79760.0],
                'suhu' => [21.5, 21.5, 21.5, 21.5, 21.5],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar['100']->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_awal' => 23.4,
            'suhu_akhir' => 23.9,
            'kelembaban_awal' => 61.0,
            'kelembaban_akhir' => 62.0,
            // Model lain juga: DV2TRV → layar RV → TK 1,0 (master pakai TK 2).
            'spesifikasi_alat' => ['model_visco' => 'DV2TRV'],
            'measurements' => $this->measurementsLain(),
        ];
    }

    private function simpanSesi(): CalibrationSession
    {
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations', $this->payload())
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    /** Rata-rata & interpolasi dihitung ulang di sini, bukan diambil dari kode. */
    private function rataRata(array $nilai): float
    {
        return array_sum($nilai) / count($nilai);
    }

    /**
     * Nilai acuan tiap titik = interpolasi linier tabel sertifikat larutan
     * pada suhu sesi ini — dan suhunya jatuh di ruas yang berbeda dari master.
     */
    public function test_nilai_acuan_ikut_suhu_sesi_ini_bukan_suhu_master(): void
    {
        $titik = $this->simpanSesi()
            ->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->get()
            ->keyBy(static fn ($t): int => (int) $t->titik_ke);

        // `titik_ke` -> [suhu sesi, label larutan yang dipakai baris itu].
        $suhuSesi = [
            1 => [22.0, '100'],
            2 => [30.0, '1000'],
            3 => [21.5, '60000'],
        ];

        foreach ($suhuSesi as $ke => [$suhu, $larutan]) {
            $harusnya = $this->standar[$larutan]->nilaiPadaSuhu($suhu);

            $this->assertNotNull($harusnya, "Suhu {$suhu} °C mestinya masih di dalam tabel.");
            $this->assertEqualsWithDelta(
                $harusnya,
                (float) $titik[$ke]->titik_ukur,
                self::TOLERANSI_SIMPAN,
                "Titik ke-{$ke} nggak digeser ke suhu sesi ini.",
            );
        }

        // Dan yang tersimpan memang BUKAN angka master — kalau sama, berarti
        // ada yang tertinggal dari sesi contoh.
        $this->assertNotEqualsWithDelta(93.87566510172147, (float) $titik[1]->titik_ukur, 1e-3);
        $this->assertNotEqualsWithDelta(910.2887323943662, (float) $titik[2]->titik_ukur, 1e-3);
        $this->assertNotEqualsWithDelta(61898.119999999995, (float) $titik[3]->titik_ukur, 1e-3);
    }

    /**
     * MPE lahir dari spindle & RPM sesi ini, dengan TK model sesi ini.
     *
     * `Fullscale = TK × SMC × 10000 / RPM` dan `MPE = 1 % × Fullscale +
     * 1 % × rata-rata` — dua-duanya dihitung ulang di sini.
     */
    public function test_mpe_ikut_spindle_rpm_dan_model_sesi_ini(): void
    {
        $titik = $this->simpanSesi()
            ->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->get()
            ->keyBy(static fn ($t): int => (int) $t->titik_ke);

        $m = $this->measurementsLain();

        foreach ([1, 2, 3] as $ke) {
            $baris = $m[$ke - 1];

            // TK dari model sesi ini (DV2TRV → 1,0), BUKAN dari master (2,0).
            $fullscale = $this->profil->fullscale('DV2TRV', $baris['spindle'], $baris['rpm']);
            $this->assertNotNull($fullscale);

            $mpeHarusnya = 0.01 * $fullscale + 0.01 * $this->rataRata($baris['pembacaan']);

            $this->assertEqualsWithDelta(
                $mpeHarusnya,
                (float) $titik[$ke]->toleransi,
                self::TOLERANSI_SIMPAN,
                "MPE titik ke-{$ke} nggak ikut spindle/RPM sesi ini.",
            );
        }

        // Sanity: MPE sesi ini beda jauh dari MPE master, jadi kalau ada yang
        // tertinggal dari sesi contoh, angkanya nggak akan lolos di atas.
        $this->assertNotEqualsWithDelta(4.141803174603175, (float) $titik[1]->toleransi, 1e-3);
        $this->assertNotEqualsWithDelta(22.079825806451613, (float) $titik[2]->toleransi, 1e-3);
    }

    /**
     * Vonis mengikuti perbandingan |koreksi| vs MPE — bukan selalu PASS.
     *
     * Di sesi master ketiga titik PASS, jadi cabang FAIL tidak pernah dilewati
     * satu test pun di alat ini. Titik yang pembacaannya digeser jauh harus
     * jatuh ke FAIL, dan titik lain di sesi yang sama tidak ikut terseret.
     */
    public function test_vonis_fail_kalau_koreksi_lewat_mpe(): void
    {
        $payload = $this->payload();

        // Titik kedua digeser jauh: MPE-nya ~44 cP, koreksi dibuat ratusan cP.
        $payload['measurements'][1]['pembacaan'] = [1250.0, 1251.0, 1249.0, 1250.5, 1250.2];

        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations', $payload)
            ->assertCreated()
            ->json('data.id');

        $titik = CalibrationSession::findOrFail($id)
            ->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->get()
            ->keyBy(static fn ($t): int => (int) $t->titik_ke);

        $this->assertSame('FAIL', $titik[2]->keputusan, 'Koreksi jauh di atas MPE mestinya FAIL.');
        $this->assertGreaterThan(
            (float) $titik[2]->toleransi,
            abs((float) $titik[2]->koreksi),
            'Prasyarat test: koreksinya memang harus lewat MPE.',
        );

        // Titik lain di sesi yang sama tidak ikut terseret.
        $this->assertSame('PASS', $titik[1]->keputusan);
        $this->assertSame('PASS', $titik[3]->keputusan);
    }

    /**
     * Angka `preview` identik dengan angka yang tersimpan — untuk Viscometer.
     *
     * `CalibrationPreviewTest` menjaga ini di pH Meter, dan jalur MPE per
     * titik milik alat ini tidak ada di sana: `toleransi` pH datang dari kolom
     * alat, sementara di sini dia dihitung dari spindle & RPM tiap baris.
     * Kalau preview dan simpan mengambil jalur yang berbeda untuk kolom itu,
     * teknisi melihat vonis yang berubah setelah menekan simpan.
     */
    public function test_angka_preview_identik_sama_yang_tersimpan(): void
    {
        $preview = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/preview', $this->payload())
            ->assertOk()
            ->json('data.titik');

        $tersimpan = $this->simpanSesi()
            ->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->get();

        $this->assertCount($tersimpan->count(), $preview);

        $kolom = [
            'titik_ukur',
            'rata_rata',
            'koreksi',
            'ketidakpastian_gabungan',
            'ketidakpastian_diperluas',
            'derajat_kebebasan_efektif',
            'faktor_cakupan_k',
            'toleransi',
        ];

        foreach ($tersimpan as $i => $baris) {
            foreach ($kolom as $k) {
                if (! array_key_exists($k, $preview[$i])) {
                    continue;
                }

                $this->assertEqualsWithDelta(
                    (float) $baris->{$k},
                    (float) $preview[$i][$k],
                    self::TOLERANSI_SIMPAN,
                    "Kolom `{$k}` titik ke-".($i + 1).' beda antara preview & tersimpan.',
                );
            }

            if (array_key_exists('keputusan', $preview[$i])) {
                $this->assertSame(
                    $baris->keputusan,
                    $preview[$i]['keputusan'],
                    'Vonis titik ke-'.($i + 1).' berubah sesudah disimpan.',
                );
            }
        }
    }

    /**
     * Mengirim sesi yang sama dua kali memberi angka yang sama.
     *
     * Penjaga sederhana untuk kebocoran keadaan: nilai yang dihitung tidak
     * boleh bergantung pada apa yang sudah pernah disimpan sebelumnya.
     */
    public function test_dua_sesi_identik_ngasih_angka_identik(): void
    {
        $ambil = fn (): array => $this->simpanSesi()
            ->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->get()
            ->map(static fn ($t): array => [
                (float) $t->titik_ukur,
                (float) $t->rata_rata,
                (float) $t->ketidakpastian_diperluas,
                (float) $t->toleransi,
            ])
            ->all();

        $pertama = $ambil();
        $kedua = $ambil();

        $this->assertEquals($pertama, $kedua);
    }
}
