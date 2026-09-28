<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;
use App\Services\Calibration\PistonVolumeCalculator;
use App\Services\Calibration\TabelStandarTekanan as Tabel;
use App\Services\CalibrationValidator;
use App\Support\LogMetodeTekananPiston as LogMetode;
use App\Support\PistonVolumeMentah as PM;
use App\Support\TekananMentah as M;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keputusan pemilik proyek 28 Sep 2026 (keputusan 2 & 3 di
 * `database/data/log-metode-tekanan-piston.json`): sesi yang memicu cacat
 * master yang belum diputuskan Technical Manager DIHITUNG dua mode, KEDUA
 * angkanya ditampilkan, tapi sertifikatnya TIDAK terbit.
 *
 * Kenapa ditahan, bukan dihitung benar atau ditiru: menghitung beda dari
 * metode yang divalidasi = menerbitkan dari metode yang belum disahkan
 * (ISO/IEC 17025 7.2.1.5); meniru T-11 = sadar menerbitkan koreksi bertanda
 * terbalik sebesar 65,5 % U95.
 *
 * Yang dijaga: penahanannya ADA (dan tidak bisa "disetujui tetap"), terbatas
 * ke sesi yang memicunya, memuat dua angka, dan LEPAS lewat log — bukan lewat
 * suntingan kode.
 */
class PenahananKeputusanTmTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        LogMetode::lupakan();
        parent::tearDown();
    }

    private function sesi(string $serial): CalibrationSession
    {
        return CalibrationSession::query()
            ->whereHas('equipment', fn ($q) => $q->where('serial_number', $serial))
            ->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function ditahan(CalibrationSession $sesi): array
    {
        return array_values(array_filter(
            app(CalibrationValidator::class)->periksa($sesi->fresh())['temuan'],
            fn (array $t): bool => $t['kode'] === CalibrationValidator::MENUNGGU_KEPUTUSAN_TM,
        ));
    }

    /** @return list<string> */
    private function penyimpangan(CalibrationSession $sesi): array
    {
        $kode = array_map(fn (array $t): string => $t['konteks']['penyimpangan'], $this->ditahan($sesi));
        sort($kode);

        return $kode;
    }

    public function test_sesi_contoh_tekanan_ditahan_sesuai_varian_dan_kalibratornya(): void
    {
        $this->seed(DatabaseSeeder::class);

        // DRUCK13G tekanan positif: cuma T-2. DRUCK07G: T-1 & T-2 (T-11 milik
        // DRUCK13G). Differential: T-2.
        $this->assertSame(['T-2'], $this->penyimpangan($this->sesi('DEMO-PG-13G-001')));
        $this->assertSame(['T-1', 'T-2'], $this->penyimpangan($this->sesi('DEMO-VG-07G-001')));
        $this->assertSame(['T-2'], $this->penyimpangan($this->sesi('DEMO-DP-ADT-001')));
    }

    public function test_temuannya_error_memuat_kedua_angka_dan_approve_ditolak(): void
    {
        $this->seed(DatabaseSeeder::class);
        $sesi = $this->sesi('DEMO-VG-07G-001');
        $hasil = app(CalibrationValidator::class)->periksa($sesi->fresh());
        $t2 = collect($this->ditahan($sesi))->firstWhere('konteks.penyimpangan', 'T-2');

        $this->assertFalse($hasil['boleh_terbit']);
        $this->assertSame(CalibrationValidator::ERROR, $t2['tingkat']);
        $this->assertSame('P-2', $t2['konteks']['pertanyaan']);

        // Dua angka yang dibandingkan admin — dari hasil TERSIMPAN.
        $jejak = $sesi->uncertaintyCalculations()->first()->type_b_components;
        $this->assertSame((float) $jejak['versi_master']['u_hitung'], $t2['konteks']['u_master']);
        $this->assertSame((float) $jejak['tekanan_budget']['u_hitung'], $t2['konteks']['u_benar']);
        $this->assertLessThan($t2['konteks']['u_benar'], $t2['konteks']['u_master'], 'T-2: pengulangan nol di master mengecilkan U.');
        // Sesi ini: dua-duanya jatuh ke lantai CMC Vacum (1,4561477 kPa), jadi
        // U95 TERCETAK sama walau metodenya beda — karena itu pesan wajib
        // memuat U, bukan cuma U95.
        $this->assertSame($t2['konteks']['u95_master'], $t2['konteks']['u95_benar']);
        foreach (['u_master', 'u_benar', 'u95_benar'] as $k) {
            $this->assertStringContainsString(LogMetode::angka($t2['konteks'][$k]), $t2['pesan'], $k);
        }

        // Tidak ada "setujui tetap" untuk ini.
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail())
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertStatus(422);
        $this->assertNull($sesi->fresh()->certificate()->first());
    }

    /** T-11: koreksi standar UP bertanda terbalik — ditampilkan per titik. */
    public function test_druck13g_vakum_menahan_t11_dengan_koreksi_per_titik(): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = Equipment::where('serial_number', 'DEMO-VG-07G-001')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-09-24',
            'suhu_awal' => 24.5, 'suhu_akhir' => 24.7,
            'kelembaban_awal' => 51, 'kelembaban_akhir' => 52,
            'measurements' => [
                ['titik_ukur' => '0', M::PERAN_UP => ['0', '0', '0'], M::PERAN_DOWN => ['0', '0', '0']],
                ['titik_ukur' => '100', M::PERAN_UP => ['99,8', '99,9', '99,8'], M::PERAN_DOWN => ['99,7', '99,7', '99,8']],
                ['titik_ukur' => '200', M::PERAN_UP => ['199,7', '199,6', '199,7'], M::PERAN_DOWN => ['199,6', '199,6', '199,7']],
            ],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'varian' => Tabel::DRUCK13G, 'satuan' => 'Psi', 'tampilan' => M::TAMPILAN_ANALOG,
                'rasio_jarum' => '1/5', 'resolusi' => '1', 'kapasitas' => '200',
            ]],
        ])->assertCreated()->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $this->assertSame(['T-11', 'T-2'], $this->penyimpangan($sesi));

        $t11 = collect($this->ditahan($sesi))->firstWhere('konteks.penyimpangan', 'T-11');
        $titikNol = $t11['konteks']['koreksi_up_per_titik'][0];

        $this->assertSame('P-11', $t11['konteks']['pertanyaan']);
        $this->assertCount(3, $t11['konteks']['koreksi_up_per_titik']);
        $this->assertSame(0.0, $titikNol['setelan']);
        $this->assertEqualsWithDelta(0.077, $titikNol['master'], 1e-12, 'master: kolom U95 dipakai sebagai koreksi');
        $this->assertEqualsWithDelta(-0.0613, $titikNol['benar'], 1e-12);
        $this->assertStringContainsString('+0,077 → -0,0613', $t11['pesan']);
    }

    /** Penahanannya ikut LOG: jawaban TM dicatat di versi baru → lepas, tanpa sunting kode. */
    public function test_penahanan_lepas_begitu_log_mencatat_jawaban_tm(): void
    {
        $this->seed(DatabaseSeeder::class);
        $sesi = $this->sesi('DEMO-PG-13G-001');
        $this->assertSame(['T-2'], $this->penyimpangan($sesi));

        $log = LogMetode::semua();
        $akhir = array_key_last(array_filter($log['versi'], fn (array $v): bool => $v['keluarga'] === LogMetode::TEKANAN));
        foreach ($log['versi'][$akhir]['perubahan'] as $i => $p) {
            if ($p['kode'] === 'T-2') {
                $log['versi'][$akhir]['perubahan'][$i]['tahan_terbit'] = false;
                $log['versi'][$akhir]['perubahan'][$i]['status_tm'] = 'dijawab P-2 (simulasi test)';
            }
        }
        LogMetode::pakai($log);

        $this->assertSame([], $this->penyimpangan($sesi));
    }

    public function test_spmk_tidak_tersentuh_cacat_ini(): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = Equipment::where('serial_number', 'DEMO-PG-13G-001')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-07-16',
            'suhu_awal' => 25, 'suhu_akhir' => 25,
            'kelembaban_awal' => 53, 'kelembaban_akhir' => 52,
            'measurements' => [
                ['titik_ukur' => '0', M::PERAN_UP => ['0', '0', '0'], M::PERAN_DOWN => ['0', '0', '0']],
                ['titik_ukur' => '5', M::PERAN_UP => ['5', '5', '5'], M::PERAN_DOWN => ['5', '5', '5']],
                ['titik_ukur' => '10', M::PERAN_UP => ['10', '10', '10'], M::PERAN_DOWN => ['10', '10', '10']],
            ],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'varian' => Tabel::SPMK, 'satuan' => 'kg/cm2', 'tampilan' => M::TAMPILAN_ANALOG,
                'rasio_jarum' => '1/2', 'resolusi' => '0,5', 'kapasitas' => '20', 'media' => 2,
                'tinggi_standar' => '0', 'tinggi_uut' => '0', 'beda_tinggi' => '0',
            ]],
        ])->assertCreated()->json('data.id');

        $this->assertSame([], $this->penyimpangan(CalibrationSession::findOrFail($id)));
    }

    /** Piston: sesi contoh tidak memicu G-2/G-7/G-8, jadi TIDAK ditahan. */
    public function test_sesi_contoh_piston_tidak_ditahan(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['DEMO-PP-FIX-001', 'DEMO-BD-GRD-001', 'DEMO-DS-FIX-001'] as $serial) {
            $sesi = $this->sesi($serial);

            $this->assertSame([], $this->penyimpangan($sesi), $serial);
            $this->assertSame([], $sesi->uncertaintyCalculations()->first()->type_b_components['penyimpangan_terpicu'], $serial);
        }
    }

    /** G-7: kapasitas di celah pita bulat master → ditahan, CMC kedua versi di pesan. */
    public function test_piston_di_celah_pita_cmc_ditahan(): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = Equipment::where('serial_number', 'DEMO-PP-FIX-001')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();
        $m = json_decode((string) file_get_contents(database_path('data/sesi-master-piston-volume.json')), true)['sesi']['fixed']['masukan'];
        // Sepuluh pemindahan ±1,5 g dari M0 = 0.
        $kum = array_map(fn (int $i): float => round($i * 1.4981, 4), range(0, 10));

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-02-13',
            'suhu_awal' => $m['suhu_ruang'][0], 'suhu_akhir' => $m['suhu_ruang'][1],
            'kelembaban_awal' => $m['kelembaban'][0], 'kelembaban_akhir' => $m['kelembaban'][1],
            'tekanan_awal' => $m['tekanan_udara'][0], 'tekanan_akhir' => $m['tekanan_udara'][1],
            'measurements' => [[
                'titik_ukur' => '1.5',
                PM::PERAN_KUMULATIF => $kum,
                PM::PERAN_SUHU_AIR => $m['titik'][0]['suhu_air'],
            ]],
            'spesifikasi_alat' => [PM::KUNCI_SESI => [
                'keluarga' => 'fixed', 'satuan' => 'ml', 'kapasitas' => '1.5', 'timbangan' => $m['timbangan'],
            ]],
        ])->assertCreated()->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $g7 = collect($this->ditahan($sesi))->firstWhere('konteks.penyimpangan', 'G-7');

        $this->assertNotNull($g7, 'Kapasitas 1,5 ml jatuh di celah pita bulat master — wajib ditahan.');
        $this->assertSame('V-7', $g7['konteks']['pertanyaan']);
        $this->assertNull($g7['konteks']['cmc_master_ml']);
        $this->assertNotNull($g7['konteks']['cmc_benar_ml']);
        $this->assertStringContainsString('"cek range"', $g7['pesan']);
    }

    /** Syarat pemicu piston persis syarat cabang di `selisih()`/`cmcMaster()`. */
    public function test_pemicu_piston_mengikuti_syarat_cabang_master(): void
    {
        $k = new PistonVolumeCalculator;
        $grad = json_decode((string) file_get_contents(database_path('data/sesi-master-piston-volume.json')), true)['sesi']['graduated']['masukan'];
        $skala = function (array $m, int $i, float $m10): array {
            $kum = $m['titik'][$i]['kumulatif'];
            $f = $m10 / $kum[10];
            $m['titik'][$i]['kumulatif'] = array_map(fn (float $x): float => $x * $f, $kum);

            return $m;
        };

        $this->assertSame([], $k->penyimpanganTerpicu($grad), 'Sesi contoh (M10 MIN 19,67 g) tidak memicu apa pun.');
        $this->assertSame(['G-2'], $k->penyimpanganTerpicu($skala($grad, 0, 25.0)));
        $this->assertSame([], $k->penyimpanganTerpicu($skala($grad, 0, 250.0)), 'Di atas 200 g master & aplikasi sama-sama mengambil M7.');
        $this->assertSame(['G-8'], $k->penyimpanganTerpicu($skala($grad, 1, 250.0)));
        $this->assertSame(['G-8'], $k->penyimpanganTerpicu($skala($grad, 2, 250.0)));
        $this->assertSame([], $k->penyimpanganTerpicu([...$skala($grad, 2, 250.0), 'satuan' => 'µl', 'kapasitas' => 50000.0]), 'Tara ulang cuma untuk satuan ml.');
        $this->assertSame(['G-7'], $k->penyimpanganTerpicu([...$grad, 'jenis' => 'piston_pipette', 'kapasitas' => 1.5]));
        $this->assertSame([], $k->penyimpanganTerpicu([...$grad, 'jenis' => 'piston_pipette', 'kapasitas' => 2.0]));
    }
}
