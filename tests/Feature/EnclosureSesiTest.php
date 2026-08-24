<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\Enclosure\BathProfile;
use App\Services\Calibration\Profiles\Enclosure\FurnaceProfile;
use App\Services\Calibration\Profiles\Enclosure\InkubatorProfile;
use App\Services\Calibration\Profiles\Enclosure\OvenProfile;
use App\Services\Calibration\Profiles\Enclosure\RefrigeratorProfile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dua sesi Enclosure dari ujung ke ujung: seeder → `EnclosureProfileBase::
 * hitungPerGrup()` → baris `uncertainty_calculations`, diadu ke kedua master.
 *
 * Beda dari `Tests\Unit\EnclosureBudgetTest` (murni, cuma kalkulator): yang diuji
 * di sini rantai lengkapnya lewat database — pencocokan alat ke profil per JENIS
 * enclosure, baris CMC per jenis dari lampiran akreditasi, penyimpanan grid ke
 * `raw_measurements` (kolom `sensor_ke`/`peran_sensor`), dan `keputusan` NULL.
 *
 * Jalankan juga di MySQL (`php artisan test -c phpunit.mysql.xml`): kolom
 * `decimal(20,8)` dibaca float oleh SQLite tapi string oleh MySQL, dan grid
 * enclosure nulis JAUH lebih banyak kolom desimal per sesi dari alat mana pun.
 */
class EnclosureSesiTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 1e-7;

    private function seedDanSesi(string $nomorSesi): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        return CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();
    }

    public function test_inkubator_ketemu_profilnya(): void
    {
        $sesi = $this->seedDanSesi('2405.03.AV');
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

        $this->assertInstanceOf(InkubatorProfile::class, $profil);
        $this->assertSame('inkubator', $profil->kode());
        $this->assertSame('Inkubator', $sesi->equipment->nama_alat_kemampuan);
    }

    public function test_oven_ketemu_profilnya(): void
    {
        $sesi = $this->seedDanSesi('2406.25.AI');
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

        $this->assertInstanceOf(OvenProfile::class, $profil);
        $this->assertSame('oven', $profil->kode());
    }

    /**
     * @return array<string, array{string, string, class-string, float}>
     *                                                                   [nama_alat_kemampuan, kode_harap, kelas_harap, cmc_harap]
     */
    public static function profilEnclosure(): array
    {
        return [
            'Oven' => ['Oven', 'oven', OvenProfile::class, 1.5],
            'Furnace' => ['Furnace', 'furnace', FurnaceProfile::class, 3.0],
            'Bath' => ['Bath', 'bath', BathProfile::class, 1.2],
            'Inkubator' => ['Inkubator', 'inkubator', InkubatorProfile::class, 1.4],
            'Refrigerator' => ['Refrigerator', 'refrigerator', RefrigeratorProfile::class, 1.5],
        ];
    }

    /**
     * U95 HITUNG (bukan dilaporkan) buat grid Yokogawa SP1 (15 °C) yang dipakai
     * `test_kelima_jenis_enclosure_ketemu_registry_dan_cmc` — sama persis buat
     * kelima jenis enclosure, karena jenis enclosure nggak mengubah tabel
     * koreksi meter/sensor, cuma baris CMC-nya (lantai ILAC-P14).
     *
     * Angka ini < CMC Oven/Furnace/Inkubator/Refrigerator, jadi keempatnya
     * lantai CMC yang menang. Tapi CMC Bath (1,2 °C, rentang 0–100 °C) justru
     * LEBIH KETAT dari angka ini — jadi buat Bath yang dilaporkan U95 HITUNG
     * ini, bukan CMC-nya. Bukan bug: ILAC-P14 melaporkan MAX(hitung, CMC),
     * dan grid ini kebetulan sedikit lebih berisik dari CMC Bath yang ketat.
     */
    private const U_HITUNG_SP1 = 1.223690240614575;

    /**
     * Kelima jenis enclosure ketemu profilnya lewat registry DAN ketemu CMC-nya
     * di database — bukan cuma Oven & Inkubator, yang satu-satunya lewat sesi
     * demo `EnclosureSeeder`. `FurnaceProfile`, `BathProfile`, &
     * `RefrigeratorProfile` nggak pernah kalibrasi sungguhan di suite ini;
     * tanpa test ini nggak ada yang ketahuan kalau CMC-nya kehapus dari
     * `kemampuan-kalibrasi.json` atau `equipment_category_id`-nya nyasar ke
     * kategori organisasi lain (lihat `EnclosureProfileBase::kemampuanEnclosure()`).
     *
     * Grid & standar yang dipakai SAMA PERSIS dengan Yokogawa SP1 (15 °C) yang
     * sudah diadu ke master di `test_sesi_yokogawa_cocok_master` — jenis
     * enclosure nggak mengubah tabel koreksi meter/sensor, cuma baris CMC-nya.
     * Jadi Uc yang keluar wajib identik (0,62381769) di kelima jenis. U95
     * dilaporkan = MAX(U hitung, CMC) per ILAC-P14 — lantai CMC menang buat
     * empat jenis, tapi BUKAN buat Bath (lihat [U_HITUNG_SP1]).
     */
    #[DataProvider('profilEnclosure')]
    public function test_kelima_jenis_enclosure_ketemu_registry_dan_cmc(
        string $namaAlatKemampuan,
        string $kodeHarap,
        string $kelasHarap,
        float $cmcHarap,
    ): void {
        $this->seed(DatabaseSeeder::class);

        $kategori = EquipmentCategory::where('organization_id', 1)
            ->where('nama', 'Suhu dan Kelembapan')
            ->firstOrFail();

        $equipment = Equipment::factory()->create([
            'organization_id' => 1,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => $namaAlatKemampuan,
            'nama_alat_kemampuan' => $namaAlatKemampuan,
            'satuan' => '°C',
            'resolusi' => 0.1,
        ]);

        $profil = app(CalibrationProfileRegistry::class)->untukAlat($equipment);
        $this->assertInstanceOf($kelasHarap, $profil, "{$namaAlatKemampuan} nggak ketemu profilnya lewat registry");
        $this->assertSame($kodeHarap, $profil->kode());

        $standar = Standard::where('nama', 'Temperature Calibrator Yokogawa CA 150 Handy Cal')->firstOrFail();
        $fix = self::fixtureGolden()['yokogawa']['setpoints'][0]; // SP1 (15 °C)

        $titik = [[
            'titik_ke' => 1,
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
        ]];

        $hasil = $profil->hitungPerGrup($titik, $equipment);

        // Yang paling penting: BUKAN "CMC belum ada". Kalau CMC-nya nggak
        // ketemu, titik ini jatuh ke belum_dihitung dengan alasan itu persis,
        // dan hitungan-nya kosong — assertSame([], ...) di bawah bakal gagal
        // duluan dan nunjukkin alasannya lewat pesan PHPUnit.
        $this->assertSame(
            [],
            $hasil['belum_dihitung'],
            "{$namaAlatKemampuan} semestinya kehitung, bukan jatuh ke belum_dihitung",
        );
        $this->assertCount(1, $hasil['hitungan']);
        $this->assertEqualsWithDelta(
            0.62381769,
            (float) $hasil['hitungan'][0]['ketidakpastian_gabungan'],
            self::TOLERANSI,
            "{$namaAlatKemampuan}: Uc mestinya identik lintas jenis enclosure — cuma CMC yang beda",
        );
        // U95 dilaporkan = MAX(U hitung, CMC) — lantai CMC menang buat empat
        // jenis, tapi Bath CMC-nya (1,2) lebih ketat dari U hitung grid ini
        // (~1,2237), jadi yang dilaporkan buat Bath ya U hitungnya sendiri.
        $this->assertEqualsWithDelta(
            max(self::U_HITUNG_SP1, $cmcHarap),
            (float) $hasil['hitungan'][0]['ketidakpastian_diperluas'],
            self::TOLERANSI,
            "U95 dilaporkan {$namaAlatKemampuan} nggak sesuai MAX(U hitung, CMC)",
        );
        $this->assertSame(
            $cmcHarap > self::U_HITUNG_SP1 ? 'cmc' : 'hitung',
            collect($hasil['hitungan'][0]['type_b_components'])->firstWhere('sumber', 'konteks_sesi')['sumber_u95'],
            "sumber_u95 {$namaAlatKemampuan} nggak sesuai mana yang menang",
        );
    }

    /**
     * Yokogawa (Inkubator, Type N): 4 set point, semua U95 dilaporkan 1,4 (lantai
     * CMC). Uc diadu untuk set point yang master-nya benar (SP1/SP2/SP4).
     */
    public function test_sesi_yokogawa_cocok_master(): void
    {
        $sesi = $this->seedDanSesi('2405.03.AV');
        $baris = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertCount(4, $baris);

        // Set point (titik_ukur) dan U95 dilaporkan.
        $harapUc = [0 => 0.62381769, 1 => 0.62309591, 3 => 0.62650074];
        $setpoints = [15.0, 35.0, 75.0, 100.0];

        foreach ($baris as $i => $b) {
            $this->assertEqualsWithDelta($setpoints[$i], (float) $b->titik_ukur, self::TOLERANSI, "setpoint ke-{$i}");
            $this->assertEqualsWithDelta(1.4, (float) $b->ketidakpastian_diperluas, self::TOLERANSI, "U95 ke-{$i}");
            $this->assertNull($b->keputusan);
            $this->assertNull($b->toleransi);

            if (isset($harapUc[$i])) {
                $this->assertEqualsWithDelta($harapUc[$i], (float) $b->ketidakpastian_gabungan, self::TOLERANSI, "Uc ke-{$i}");
            }
        }

        // SP3 (index 2): kalkulator menghitung Uc BENAR (0,6346), bukan bug sel
        // master (0,6234). Yang tercetak tetap 1,4.
        $this->assertEqualsWithDelta(0.63463317, (float) $baris[2]->ketidakpastian_gabungan, self::TOLERANSI);

        // Jejak audit membawa konteks enclosure + lantai CMC.
        $konteks = collect($baris[0]->type_b_components)->firstWhere('sumber', 'konteks_sesi');
        $this->assertSame('cmc', $konteks['sumber_u95']);
        $this->assertSame('Inkubator', $konteks['enclosure']);
        $this->assertSame('yokogawa', $konteks['merk_kalibrator']);
        $this->assertSame('Type N', $konteks['tipe_sensor']);
        $this->assertEqualsWithDelta(1.4, (float) $konteks['cmc'], self::TOLERANSI);

        // 11 komponen budget (termasuk konduksi_panas).
        $sumber = collect($baris[0]->type_b_components)->pluck('sumber');
        $this->assertTrue($sumber->contains('konduksi_panas'));
        $this->assertTrue($sumber->contains('efek_radiasi'));
    }

    /**
     * Recorder (Oven, Type K): 3 set point @ 67 °C, semua U95 dilaporkan 1,5
     * (lantai CMC Oven), Uc identik 0,5954.
     */
    public function test_sesi_recorder_cocok_master(): void
    {
        $sesi = $this->seedDanSesi('2406.25.AI');
        $baris = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertCount(3, $baris);

        foreach ($baris as $b) {
            $this->assertEqualsWithDelta(67.0, (float) $b->titik_ukur, self::TOLERANSI);
            $this->assertEqualsWithDelta(1.5, (float) $b->ketidakpastian_diperluas, self::TOLERANSI);
            $this->assertEqualsWithDelta(0.59543500, (float) $b->ketidakpastian_gabungan, self::TOLERANSI);
            $this->assertNull($b->keputusan);
        }

        $konteks = collect($baris[0]->type_b_components)->firstWhere('sumber', 'konteks_sesi');
        $this->assertSame('Oven', $konteks['enclosure']);
        $this->assertSame('recorder', $konteks['merk_kalibrator']);
        $this->assertSame('Type K', $konteks['tipe_sensor']);

        // 10 komponen — Recorder TIDAK punya konduksi_panas.
        $sumber = collect($baris[0]->type_b_components)->pluck('sumber');
        $this->assertFalse($sumber->contains('konduksi_panas'));
    }

    /** Grid tersimpan di raw_measurements dengan sumbu sensor (9 termokopel × 5). */
    public function test_grid_tersimpan_dengan_sumbu_sensor(): void
    {
        $sesi = $this->seedDanSesi('2405.03.AV');

        $sp1 = $sesi->rawMeasurements()->where('titik_ke', 1);

        // 9 termokopel × 5 pengulangan = 45 baris termokopel.
        $this->assertSame(45, (clone $sp1)->where('peran_sensor', 'termokopel')->count());
        // 5 baris Indikator.
        $this->assertSame(5, (clone $sp1)->where('peran_sensor', 'indikator')->count());

        // Tiap termokopel No.3..11 hadir, MASING-MASING lima kali. Jumlah total
        // 45 saja nggak cukup: grid yang mengulang sensor 3 sembilan kali juga
        // berjumlah 45, dan itu justru kesalahan yang paling mungkin terjadi.
        $perSensor = (clone $sp1)->where('peran_sensor', 'termokopel')
            ->get()
            ->groupBy('sensor_ke')
            ->map->count();

        $this->assertSame(range(3, 11), $perSensor->keys()->map(fn ($k): int => (int) $k)->sort()->values()->all());

        foreach ($perSensor as $no => $jumlah) {
            $this->assertSame(5, $jumlah, "termokopel no. {$no} harus punya 5 pembacaan");
        }
    }

    /**
     * Kalibrator Recorder: nomor KANAL ikut tersimpan. Tanpa kolom ini sesi
     * tersimpan kehilangan masukan koreksi (koreksi meter recorder dibaca per
     * kanal), jadi hitung ulang & telusur audit nggak bisa memulihkan grid-nya.
     */
    public function test_kanal_recorder_tersimpan(): void
    {
        $sesi = $this->seedDanSesi('2406.25.AI');

        $termokopel = $sesi->rawMeasurements()
            ->where('titik_ke', 1)
            ->where('peran_sensor', 'termokopel')
            ->orderBy('sensor_ke')
            ->get();

        $this->assertNotEmpty($termokopel);

        foreach ($termokopel as $baris) {
            $this->assertNotNull($baris->channel, "termokopel no. {$baris->sensor_ke} kehilangan nomor kanal");
            $this->assertSame((int) $baris->sensor_ke, (int) $baris->channel);
        }
    }

    /**
     * Jalur API sungguhan: POST grid ke `/api/calibrations/preview` → controller
     * `susunGridEnclosure` → `hitungPerGrup`. Ini yang membuktikan ingest grid
     * dari request beneran jalan, bukan cuma lewat seeder.
     */
    public function test_api_preview_grid_enclosure(): void
    {
        $this->seed(DatabaseSeeder::class);

        $equipment = Equipment::where('serial_number', 'D132469')->firstOrFail(); // Incubator-02
        $standar = Standard::where('nama', 'Temperature Calibrator Yokogawa CA 150 Handy Cal')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();

        $fix = self::fixtureGolden()['yokogawa']['setpoints'][0]; // SP1 (15 °C)

        $sensorGrid = array_map(
            static fn (array $s): array => ['no' => $s['no'], 'pembacaan' => $s['pembacaan']],
            $fix['sensors'],
        );

        $respons = $this->actingAs($teknisi)->postJson('/api/calibrations/preview', [
            'equipment_id' => $equipment->id,
            'standard_id' => $standar->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2024-05-02',
            'tipe_sensor' => 'Type N',
            'suhu_awal' => 23.7, 'suhu_akhir' => 23.7, 'kelembaban_awal' => 47, 'kelembaban_akhir' => 46,
            'measurements' => [[
                'titik_ukur' => 15.0,
                'satuan' => '°C',
                'sensor_grid' => $sensorGrid,
                'indikator' => $fix['indikator'],
            ]],
        ])->assertOk();

        $titik = $respons->json('data.titik');
        $this->assertCount(1, $titik);
        $this->assertEqualsWithDelta(15.0, (float) $titik[0]['titik_ukur'], self::TOLERANSI);
        $this->assertEqualsWithDelta(1.4, (float) $titik[0]['ketidakpastian_diperluas'], self::TOLERANSI);
    }

    /** @return array<string, mixed> */
    private static function fixtureGolden(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/enclosure-golden.json')), true);
    }

    /**
     * Jalur Recorder BENERAN lewat HTTP: `POST /api/calibrations` (bukan
     * `/preview`, dan bukan cuma lewat `EnclosureSeeder`). Yang dibuktikan di
     * sini: grid Recorder yang datang dari REQUEST (bukan array PHP yang
     * disusun seeder) nyimpen `channel` per termokopel ke `raw_measurements`,
     * dan hasil hitungnya identik dengan jalur seeder untuk angka yang sama
     * (`test_sesi_recorder_cocok_master`: Uc 0,59543500, U95 dilaporkan 1,5).
     */
    public function test_recorder_tersimpan_lewat_api_dan_cocok_jalur_seeder(): void
    {
        $this->seed(DatabaseSeeder::class);

        $equipment = Equipment::where('serial_number', 'B616-0871')->firstOrFail(); // Oven
        $standar = Standard::where('nama', 'Temperature Recorder Graphtech GL840')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();

        $fix = self::fixtureGolden()['recorder']['setpoints'][0]; // SP1 (67 °C)

        $sensorGrid = array_map(
            static fn (array $s): array => ['no' => $s['no'], 'channel' => $s['channel'], 'pembacaan' => $s['pembacaan']],
            $fix['sensors'],
        );

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $equipment->id,
            'standard_id' => $standar->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2024-06-27',
            'tipe_sensor' => 'Type K',
            'measurements' => [[
                'titik_ukur' => 67.0,
                'satuan' => '°C',
                'sensor_grid' => $sensorGrid,
                'indikator' => $fix['indikator'],
            ]],
        ])->assertCreated()->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);

        // 1. `channel` beneran kesimpen di raw_measurements, per termokopel —
        // tanpa ini sesi tersimpan kehilangan masukan koreksi meter Recorder
        // (dibaca PER KANAL) dan nggak bisa dihitung ulang.
        $termokopel = $sesi->rawMeasurements()->where('peran_sensor', 'termokopel')->orderBy('sensor_ke')->get();
        $this->assertCount(45, $termokopel, '9 termokopel × 5 pengulangan lewat jalur API');

        foreach ($termokopel as $baris) {
            $this->assertNotNull($baris->channel, "termokopel no. {$baris->sensor_ke} kehilangan channel lewat jalur API");
            $this->assertSame((int) $baris->sensor_ke, (int) $baris->channel);
        }

        // 2. Hasil hitungnya identik dengan jalur seeder buat angka yang sama.
        $baris = $sesi->uncertaintyCalculations()->firstOrFail();
        $this->assertEqualsWithDelta(67.0, (float) $baris->titik_ukur, self::TOLERANSI);
        $this->assertEqualsWithDelta(
            0.59543500,
            (float) $baris->ketidakpastian_gabungan,
            self::TOLERANSI,
            'Uc lewat API harus sama dengan jalur seeder buat angka yang sama',
        );
        $this->assertEqualsWithDelta(
            1.5,
            (float) $baris->ketidakpastian_diperluas,
            self::TOLERANSI,
            'U95 lewat API harus sama dengan jalur seeder buat angka yang sama',
        );
    }

    /**
     * Termokopel Recorder yang dikirim TANPA `channel` bikin set point-nya
     * NGGAK dihitung sama sekali — bukan dihitung diam-diam dengan koreksi
     * meter dianggap nol. Koreksi meter Recorder dibaca PER KANAL; tanpa kanal
     * nggak ada baris tabel yang bisa dibaca, dan menganggapnya nol persis
     * yang dihindari `EnclosureCalculator::hitungSensor()` (koreksi nol itu
     * PERNYATAAN, bukan ketiadaan data).
     */
    public function test_recorder_tanpa_channel_tidak_dihitung_dengan_koreksi_nol(): void
    {
        $this->seed(DatabaseSeeder::class);

        $equipment = Equipment::where('serial_number', 'B616-0871')->firstOrFail();
        $standar = Standard::where('nama', 'Temperature Recorder Graphtech GL840')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();

        $fix = self::fixtureGolden()['recorder']['setpoints'][0];

        $sensorGrid = array_map(
            // Sensor no. 5 SENGAJA nggak bawa channel — simulasi teknisi lupa
            // isi kolom Channel di lembar kerja Recorder.
            static fn (array $s): array => [
                'no' => $s['no'],
                'channel' => $s['no'] === 5 ? null : $s['channel'],
                'pembacaan' => $s['pembacaan'],
            ],
            $fix['sensors'],
        );

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $equipment->id,
            'standard_id' => $standar->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2024-06-27',
            'tipe_sensor' => 'Type K',
            'measurements' => [[
                'titik_ukur' => 67.0,
                'satuan' => '°C',
                'sensor_grid' => $sensorGrid,
                'indikator' => $fix['indikator'],
            ]],
        ])->assertCreated()->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);

        // Grid mentahnya TETAP kesimpen (teknisi nggak kehilangan input yang
        // udah diketik, termasuk sensor no. 5 yang channel-nya kosong)...
        $this->assertCount(45, $sesi->rawMeasurements()->where('peran_sensor', 'termokopel')->get());

        $sensorLima = $sesi->rawMeasurements()->where('peran_sensor', 'termokopel')->where('sensor_ke', 5)->first();
        $this->assertNull($sensorLima?->channel, 'sensor no. 5 sengaja dikirim tanpa channel di test ini');

        // ...tapi TIDAK ada satu baris uncertainty_calculations pun buat set
        // point ini. Kalau kode diam-diam menganggap koreksi kanal kosong = 0,
        // baris ini bakal ADA dengan U95 yang kelihatan sah — dan itu justru
        // yang paling berbahaya: sebaran suhu & keseragaman yang tercetak
        // ikut salah tanpa satu angka pun kelihatan janggal.
        $this->assertSame(
            0,
            $sesi->uncertaintyCalculations()->count(),
            'set point dengan channel kosong nggak boleh kehitung sama sekali, apalagi dengan koreksi nol',
        );
    }

    /** Enclosure nggak divonis PASS/FAIL. */
    public function test_tidak_divonis(): void
    {
        $sesi = $this->seedDanSesi('2405.03.AV');

        $this->assertNull($sesi->fresh()->keputusan);
        foreach ($sesi->uncertaintyCalculations as $b) {
            $this->assertNull($b->keputusan);
            $this->assertNull($b->toleransi);
        }
    }
}
