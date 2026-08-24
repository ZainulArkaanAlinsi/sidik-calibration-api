<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\EnclosureCalculator;
use App\Services\Calibration\Profiles\Enclosure\EnclosureProfileBase;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grid enclosure yang SEBAGIAN rusak lewat `EnclosureProfileBase::hitungPerGrup()`.
 *
 * Tiap test di sini nyusun SATU titik yang datanya kurang DI TENGAH sesi yang
 * isinya bagus, dan yang dijaga DUA hal sekaligus:
 *
 *  1. titik yang rusak masuk `belum_dihitung` — NGGAK PERNAH `hitungan` — dan
 *     alasannya nyebut angka spesifik (nomor sensor, jumlah pembacaan, dst.),
 *     bukan pesan generik yang nggak ngasih tau teknisi kolom mana yang mesti
 *     dibetulin;
 *  2. titik LAIN di sesi yang sama TETAP kehitung — satu titik rusak nggak
 *     boleh meracuni titik yang bagus, karena `hitungPerGrup()` dipanggil
 *     SEKALI per sesi (satu `foreach` atas semua titik), bukan sekali per
 *     titik.
 *
 * `Tests\Unit\EnclosureBudgetTest` sudah nguji kegagalan yang sama di level
 * `EnclosureCalculator::hitungSetpoint()` (kalkulator murni, satu titik, `cmc`
 * dioper manual). Yang beda di sini: rutenya lewat `hitungPerGrup()` — yang
 * MEMUTUSKAN apakah kalkulatornya dipanggil sama sekali — dan lewat CMC
 * BENERAN dari database, jalur yang beneran dipakai
 * `CalibrationController::susunGridEnclosure()`.
 *
 * @see EnclosureProfileBase::hitungPerGrup()
 */
class EnclosureGridParsialTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 1e-7;

    /** CMC Inkubator (lampiran akreditasi) — dipakai buat semua skenario Yokogawa/Type N di bawah. */
    private const CMC_INKUBATOR = 1.4;

    /**
     * Alat + standar Inkubator dari `EnclosureSeeder` (via `DatabaseSeeder`
     * penuh, biar CMC-nya BENERAN dari database, bukan angka yang dioper
     * manual seperti di `EnclosureBudgetTest`).
     *
     * @return array{equipment: Equipment, standar: Standard, profil: EnclosureProfileBase}
     */
    private function seedInkubator(): array
    {
        $this->seed(DatabaseSeeder::class);

        $equipment = Equipment::where('serial_number', 'D132469')->firstOrFail(); // Incubator-02
        $standar = Standard::where('nama', 'Temperature Calibrator Yokogawa CA 150 Handy Cal')->firstOrFail();

        /** @var EnclosureProfileBase $profil */
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($equipment);

        return ['equipment' => $equipment, 'standar' => $standar, 'profil' => $profil];
    }

    /** @return array<string, mixed> */
    private static function fixtureGolden(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/enclosure-golden.json')), true);
    }

    /**
     * Titik BAIK, Yokogawa SP1 (15 °C) dari fixture golden — dipakai sebagai
     * titik ke-1 di semua skenario Inkubator di bawah, biar tiap test
     * membuktikan titik lain di sesi yang sama nggak ikut jatuh.
     *
     * @return array<string, mixed>
     */
    private function titikBaik(int $titikKe, Standard $standar): array
    {
        $fix = self::fixtureGolden()['yokogawa']['setpoints'][0];

        return [
            'titik_ke' => $titikKe,
            'titik_ukur' => 15.0,
            'pembacaan' => [],
            'standard' => $standar,
            'konteks' => [
                'tipe_sensor' => 'Type N',
                'sensor_grid' => array_map(
                    static fn (array $s): array => ['no' => $s['no'], 'pembacaan' => $s['pembacaan']],
                    $fix['sensors'],
                ),
                'indikator' => $fix['indikator'],
            ],
        ];
    }

    /** @param  array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}  $hasil */
    private function assertTitikBaikKehitung(array $hasil, int $titikKe): void
    {
        $ini = collect($hasil['hitungan'])->firstWhere('titik_ke', $titikKe);
        $this->assertNotNull(
            $ini,
            "titik baik ke-{$titikKe} semestinya tetap kehitung, bukan ikut jatuh gara-gara titik lain rusak",
        );
        $this->assertEqualsWithDelta(self::CMC_INKUBATOR, (float) $ini['ketidakpastian_diperluas'], self::TOLERANSI);
    }

    /**
     * Set point yang `sensor_grid`-nya kosong/hilang masuk `belum_dihitung` —
     * bukan lolos ke kalkulator dengan grid kosong (yang bakal meledak di
     * `max()`/`min()` atas array kosong).
     */
    public function test_sensor_grid_kosong_masuk_belum_dihitung(): void
    {
        ['equipment' => $equipment, 'standar' => $standar, 'profil' => $profil] = $this->seedInkubator();

        $titik = [
            $this->titikBaik(1, $standar),
            [
                'titik_ke' => 2,
                'titik_ukur' => 50.0,
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => ['tipe_sensor' => 'Type N', 'sensor_grid' => [], 'indikator' => [50.0, 50.0, 50.0]],
            ],
        ];

        $hasil = $profil->hitungPerGrup($titik, $equipment);

        $this->assertTitikBaikKehitung($hasil, 1);
        $this->assertCount(1, $hasil['hitungan'], 'cuma titik baik yang boleh kehitung');

        $this->assertCount(1, $hasil['belum_dihitung']);
        $this->assertSame(2, $hasil['belum_dihitung'][0]['titik_ke']);
        $alasan = $hasil['belum_dihitung'][0]['alasan'];
        $this->assertStringContainsString('grid termokopel/Indikator', $alasan);
        // Nyebut angka set point-nya, biar teknisi langsung tau baris mana di
        // lembar kerja yang belum lengkap.
        $this->assertStringContainsString('50', $alasan);
    }

    /**
     * Set point yang `indikator`-nya kosong/hilang masuk `belum_dihitung` —
     * grid termokopelnya sendiri lengkap, tapi tanpa Indikator enclosure
     * `Pengulangan Indikator` (komponen D41) nggak punya sumber data.
     */
    public function test_indikator_kosong_masuk_belum_dihitung(): void
    {
        ['equipment' => $equipment, 'standar' => $standar, 'profil' => $profil] = $this->seedInkubator();
        $fixSensor = self::fixtureGolden()['yokogawa']['setpoints'][0]['sensors'];

        $titik = [
            $this->titikBaik(1, $standar),
            [
                'titik_ke' => 2,
                'titik_ukur' => 51.0,
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    'tipe_sensor' => 'Type N',
                    'sensor_grid' => array_map(
                        static fn (array $s): array => ['no' => $s['no'], 'pembacaan' => $s['pembacaan']],
                        array_slice($fixSensor, 0, 2),
                    ),
                    'indikator' => [],
                ],
            ],
        ];

        $hasil = $profil->hitungPerGrup($titik, $equipment);

        $this->assertTitikBaikKehitung($hasil, 1);
        $this->assertCount(1, $hasil['hitungan']);

        $this->assertCount(1, $hasil['belum_dihitung']);
        $this->assertSame(2, $hasil['belum_dihitung'][0]['titik_ke']);
        $this->assertStringContainsString('grid termokopel/Indikator', $hasil['belum_dihitung'][0]['alasan']);
    }

    /**
     * Set point dengan cuma SATU termokopel masuk `belum_dihitung` —
     * `EnclosureProfileBase::MIN_SENSOR` = 2. Di bawah itu Keseragaman &
     * Variasi nggak punya selisih antar-posisi buat diukur sama sekali, dan
     * kalau dipaksa jalan keduanya keluar `0,0` yang kebaca pelanggan sebagai
     * "sudah terbukti seragam" padahal cuma belum diukur.
     */
    public function test_satu_termokopel_masuk_belum_dihitung(): void
    {
        ['equipment' => $equipment, 'standar' => $standar, 'profil' => $profil] = $this->seedInkubator();
        $fixSensor = self::fixtureGolden()['yokogawa']['setpoints'][0]['sensors'];

        $this->assertSame(2, EnclosureProfileBase::MIN_SENSOR);

        $titik = [
            $this->titikBaik(1, $standar),
            [
                'titik_ke' => 2,
                'titik_ukur' => 52.0,
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    'tipe_sensor' => 'Type N',
                    // CUMA SATU termokopel, di bawah MIN_SENSOR — bukan
                    // pembacaan yang kurang (pembacaannya sendiri lengkap 5).
                    'sensor_grid' => [['no' => $fixSensor[0]['no'], 'pembacaan' => $fixSensor[0]['pembacaan']]],
                    'indikator' => [52.0, 52.0, 52.0, 52.0, 52.0],
                ],
            ],
        ];

        $hasil = $profil->hitungPerGrup($titik, $equipment);

        $this->assertTitikBaikKehitung($hasil, 1);
        $this->assertCount(1, $hasil['belum_dihitung']);
        $this->assertSame(2, $hasil['belum_dihitung'][0]['titik_ke']);

        $alasan = $hasil['belum_dihitung'][0]['alasan'];
        $this->assertStringContainsString('1 termokopel', $alasan);
        $this->assertStringContainsString((string) EnclosureProfileBase::MIN_SENSOR, $alasan);
    }

    /**
     * Set point dengan satu termokopel berpembacaan cuma 3 (di bawah
     * `EnclosureCalculator::MIN_PEMBACAAN` = 4) masuk `belum_dihitung` —
     * bukan ditambal diam-diam dengan mengulang pembacaan terakhir. Sensor
     * lain di titik yang SAMA (pembacaannya cukup) nggak menyelamatkannya:
     * satu termokopel kurang cukup buat menahan seluruh titik.
     */
    public function test_termokopel_kurang_dari_empat_pembacaan_masuk_belum_dihitung(): void
    {
        ['equipment' => $equipment, 'standar' => $standar, 'profil' => $profil] = $this->seedInkubator();
        $fixSensor = self::fixtureGolden()['yokogawa']['setpoints'][0]['sensors'];

        $this->assertSame(4, EnclosureCalculator::MIN_PEMBACAAN);

        $titik = [
            $this->titikBaik(1, $standar),
            [
                'titik_ke' => 2,
                'titik_ukur' => 53.0,
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    'tipe_sensor' => 'Type N',
                    'sensor_grid' => [
                        // Sensor no. 3: cuma 3 pembacaan.
                        ['no' => $fixSensor[0]['no'], 'pembacaan' => array_slice($fixSensor[0]['pembacaan'], 0, 3)],
                        // Sensor no. 4: lengkap — TIDAK cukup buat menyelamatkan titiknya.
                        ['no' => $fixSensor[1]['no'], 'pembacaan' => $fixSensor[1]['pembacaan']],
                    ],
                    'indikator' => [53.0, 53.0, 53.0, 53.0, 53.0],
                ],
            ],
        ];

        $hasil = $profil->hitungPerGrup($titik, $equipment);

        $this->assertTitikBaikKehitung($hasil, 1);
        $this->assertCount(1, $hasil['belum_dihitung']);
        $this->assertSame(2, $hasil['belum_dihitung'][0]['titik_ke']);

        $alasan = $hasil['belum_dihitung'][0]['alasan'];
        $this->assertStringContainsString("no. {$fixSensor[0]['no']}", $alasan);
        $this->assertStringContainsString('3 pembacaan', $alasan);
        $this->assertStringContainsString((string) EnclosureCalculator::MIN_PEMBACAAN, $alasan);
        // Sensor no. 4 (yang lengkap) nggak boleh ikut disebut sebagai penyebab.
        $this->assertStringNotContainsString("no. {$fixSensor[1]['no']} (", $alasan);
    }

    /**
     * Sesi RECORDER: termokopel tanpa `channel` masuk `belum_dihitung` —
     * koreksi meter Recorder dibaca PER KANAL, jadi tanpa kanal nggak ada
     * baris tabel yang bisa dibaca sama sekali (bukan koreksi 0).
     */
    public function test_recorder_termokopel_tanpa_channel_masuk_belum_dihitung(): void
    {
        $this->seed(DatabaseSeeder::class);

        $equipment = Equipment::where('serial_number', 'B616-0871')->firstOrFail(); // Oven
        $standar = Standard::where('nama', 'Temperature Recorder Graphtech GL840')->firstOrFail();
        /** @var EnclosureProfileBase $profil */
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($equipment);

        $fixRecorder = self::fixtureGolden()['recorder']['setpoints'][0];

        $titikBaik = [
            'titik_ke' => 1,
            'titik_ukur' => 67.0,
            'pembacaan' => [],
            'standard' => $standar,
            'konteks' => [
                'tipe_sensor' => 'Type K',
                'sensor_grid' => array_map(
                    static fn (array $s): array => ['no' => $s['no'], 'channel' => $s['channel'], 'pembacaan' => $s['pembacaan']],
                    $fixRecorder['sensors'],
                ),
                'indikator' => $fixRecorder['indikator'],
            ],
        ];

        $titikBuruk = [
            'titik_ke' => 2,
            'titik_ukur' => 67.0,
            'pembacaan' => [],
            'standard' => $standar,
            'konteks' => [
                'tipe_sensor' => 'Type K',
                'sensor_grid' => [
                    ['no' => 1, 'channel' => 1, 'pembacaan' => $fixRecorder['sensors'][0]['pembacaan']],
                    // Sensor no. 2 TANPA channel — teknisi lupa isi kolom Channel.
                    ['no' => 2, 'channel' => null, 'pembacaan' => $fixRecorder['sensors'][1]['pembacaan']],
                ],
                'indikator' => $fixRecorder['indikator'],
            ],
        ];

        $hasil = $profil->hitungPerGrup([$titikBaik, $titikBuruk], $equipment);

        $ini = collect($hasil['hitungan'])->firstWhere('titik_ke', 1);
        $this->assertNotNull($ini, 'titik Recorder yang channel-nya lengkap semestinya tetap kehitung');
        $this->assertEqualsWithDelta(1.5, (float) $ini['ketidakpastian_diperluas'], self::TOLERANSI); // CMC Oven

        $this->assertCount(1, $hasil['belum_dihitung']);
        $this->assertSame(2, $hasil['belum_dihitung'][0]['titik_ke']);
        $alasan = $hasil['belum_dihitung'][0]['alasan'];
        $this->assertStringContainsString('no. 2', $alasan);

        // Alasannya harus nyebut KANAL, bukan nyuruh cek nomor termokopel.
        //
        // Nomor 2 itu nomor Type K yang SAH — yang kosong kolom Channel-nya.
        // Pesan generik "cek nomor termokopel" bikin teknisi membongkar
        // penomoran yang nggak ada masalahnya, sementara kolom yang beneran
        // kosong nggak pernah disebut.
        $this->assertStringContainsString('Channel', $alasan);
        $this->assertStringNotContainsString(
            'Cek nomor termokopel',
            $alasan,
            'kanal kosong nggak boleh dikasih instruksi "cek nomor termokopel"',
        );
    }

    /**
     * Non-regresi yang paling penting: BANYAK titik rusak dengan alasan
     * BERBEDA-BEDA sekaligus, dicampur sama SATU titik baik, dalam SATU
     * panggilan `hitungPerGrup()`. Kalau ada bug yang bikin satu kegagalan
     * "bocor" ke iterasi lain — state kebawa antar titik lewat variabel yang
     * salah di-reset — sini yang ketahuan duluan. Test per skenario di atas
     * nggak bisa lihat itu karena masing-masing cuma punya SATU titik buruk.
     */
    public function test_banyak_titik_buruk_sekaligus_tidak_saling_meracuni(): void
    {
        ['equipment' => $equipment, 'standar' => $standar, 'profil' => $profil] = $this->seedInkubator();
        $fixSensor = self::fixtureGolden()['yokogawa']['setpoints'][0]['sensors'];

        $titik = [
            $this->titikBaik(1, $standar),
            // Titik 2: grid kosong.
            [
                'titik_ke' => 2,
                'titik_ukur' => 60.0,
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => ['tipe_sensor' => 'Type N', 'sensor_grid' => [], 'indikator' => [60.0]],
            ],
            // Titik 3: cuma satu termokopel.
            [
                'titik_ke' => 3,
                'titik_ukur' => 61.0,
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    'tipe_sensor' => 'Type N',
                    'sensor_grid' => [['no' => $fixSensor[0]['no'], 'pembacaan' => $fixSensor[0]['pembacaan']]],
                    'indikator' => [61.0, 61.0, 61.0, 61.0, 61.0],
                ],
            ],
            // Titik 4: satu termokopel pembacaannya kurang dari 4.
            [
                'titik_ke' => 4,
                'titik_ukur' => 62.0,
                'pembacaan' => [],
                'standard' => $standar,
                'konteks' => [
                    'tipe_sensor' => 'Type N',
                    'sensor_grid' => [
                        ['no' => $fixSensor[0]['no'], 'pembacaan' => array_slice($fixSensor[0]['pembacaan'], 0, 3)],
                        ['no' => $fixSensor[1]['no'], 'pembacaan' => $fixSensor[1]['pembacaan']],
                    ],
                    'indikator' => [62.0, 62.0, 62.0, 62.0, 62.0],
                ],
            ],
        ];

        $hasil = $profil->hitungPerGrup($titik, $equipment);

        // Titik baik tetap kehitung — nggak ikut jatuh gara-gara TIGA titik
        // lain di sesi yang sama rusak.
        $this->assertCount(1, $hasil['hitungan']);
        $this->assertTitikBaikKehitung($hasil, 1);

        // Ketiga titik buruk MASUK belum_dihitung, masing-masing alasannya
        // spesifik ke kegagalannya sendiri — bukan disamaratakan jadi satu
        // pesan generik.
        $this->assertCount(3, $hasil['belum_dihitung']);
        $alasanPerTitik = collect($hasil['belum_dihitung'])->pluck('alasan', 'titik_ke');

        $this->assertSame([2, 3, 4], $alasanPerTitik->keys()->sort()->values()->all());
        $this->assertStringContainsString('grid termokopel/Indikator', $alasanPerTitik[2]);
        $this->assertStringContainsString('1 termokopel', $alasanPerTitik[3]);
        $this->assertStringContainsString('3 pembacaan', $alasanPerTitik[4]);
    }
}
