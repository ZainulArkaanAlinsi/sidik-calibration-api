<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\Calibration\Profiles\PressureGaugeProfile;
use App\Services\Calibration\TabelStandarTekanan as Tabel;
use App\Support\TekananMentah as M;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur HP → server keluarga TEKANAN: simpan, baku koma, penjaga yang
 * MEMBLOKIR (bukan sekadar label), dan angka tersimpan = master SPMK.
 */
class TekananSesiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Equipment, 1: User} */
    private function siapkan(string $serial = 'DEMO-PG-13G-001'): array
    {
        $this->seed(DatabaseSeeder::class);

        return [
            Equipment::where('serial_number', $serial)->firstOrFail(),
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
        ];
    }

    /**
     * Data contoh master SPMK — ditulis dengan KOMA seperti yang diketik di HP.
     *
     * @param  array<string, mixed>  $gantiBlok
     * @param  list<array<string, mixed>>|null  $titik
     * @return array<string, mixed>
     */
    private function payload(Equipment $alat, array $gantiBlok = [], ?array $titik = null): array
    {
        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-07-16',
            'suhu_awal' => '25,1', 'suhu_akhir' => 25,
            'kelembaban_awal' => 53, 'kelembaban_akhir' => 52,
            'measurements' => $titik ?? [
                ['titik_ukur' => '0', M::PERAN_UP => ['0,01', '0', '0'], M::PERAN_DOWN => ['0', '0', '0']],
                ['titik_ukur' => '5', M::PERAN_UP => ['5,01', '5', '5,01'], M::PERAN_DOWN => ['5', '5', '5,02']],
                ['titik_ukur' => '10', M::PERAN_UP => ['10,03', '10', '10,02'], M::PERAN_DOWN => ['10', '10,01', '10']],
            ],
            'spesifikasi_alat' => [
                M::KUNCI_SESI => [
                    'varian' => Tabel::SPMK,
                    'satuan' => 'kg/cm2',
                    'tampilan' => M::TAMPILAN_ANALOG,
                    'rasio_jarum' => '1/2',
                    'resolusi' => '0,5',
                    'kapasitas' => '20',
                    'media' => 2,
                    'tinggi_standar' => '0,18',
                    'tinggi_uut' => '0',
                    'beda_tinggi' => '0,18',
                    ...$gantiBlok,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function harapanSpmk(): array
    {
        $isi = json_decode((string) file_get_contents(database_path('data/sesi-master-tekanan.json')), true);

        return $isi['sesi'][Tabel::SPMK];
    }

    public function test_lembar_tekanan_tersimpan_dengan_arahnya(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');

        $baris = RawMeasurement::where('calibration_session_id', $id)->get();
        $this->assertCount(18, $baris, 'Tiga titik x (3 UP + 3 DOWN) harus tersimpan utuh.');

        foreach ([1, 2, 3] as $titikKe) {
            foreach (M::PERAN_SEMUA as $peran) {
                $deret = $baris->where('titik_ke', $titikKe)->where('peran_sensor', $peran);
                $this->assertSame([1, 2, 3], $deret->pluck('sensor_ke')->map(fn ($v): int => (int) $v)->sort()->values()->all(),
                    "Titik {$titikKe} {$peran}: nomor pengulangan hilang — histeresis per pengulangan jadi tidak bisa dipasangkan.");
            }
        }
    }

    public function test_koma_desimal_tersimpan_sebagai_angka_yang_benar(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');

        $nilai = (float) RawMeasurement::where('calibration_session_id', $id)
            ->where('titik_ke', 2)->where('peran_sensor', M::PERAN_UP)->where('sensor_ke', 1)
            ->value('pembacaan');
        $this->assertEqualsWithDelta(5.01, $nilai, 1e-12, '"5,01" tersimpan bukan sebagai 5,01 (bukan 501, bukan 5).');

        $blok = M::blokSesi(CalibrationSession::findOrFail($id)->spesifikasi_alat);
        $this->assertEqualsWithDelta(0.18, $blok['beda_tinggi'], 1e-12, 'Beda tinggi "0,18" masuk koreksi setiap bacaan standar.');
        $this->assertEqualsWithDelta(0.5, $blok['resolusi'], 1e-12);
    }

    public function test_dua_pemisah_desimal_ditolak_bukan_ditebak(): void
    {
        [$alat, $teknisi] = $this->siapkan();
        $titik = [['titik_ukur' => '0', M::PERAN_UP => ['1.234,5', '0', '0'], M::PERAN_DOWN => ['0', '0', '0']]];

        $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, titik: $titik))
            ->assertStatus(422);
    }

    /**
     * Angka yang tersimpan = rantai hitung-benar yang diadu ke master SPMK
     * (SPMK tidak punya cacat T-1/T-2/T-11, jadi = cache Excel).
     */
    public function test_u95_tersimpan_sama_dengan_master_spmk(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');

        $h = self::harapanSpmk()['harapan_benar'];
        $baris = UncertaintyCalculation::where('calibration_session_id', $id)->orderBy('titik_ke')->get();
        $this->assertCount(3, $baris);

        foreach ($baris as $i => $b) {
            $budget = $b->type_b_components['tekanan_budget'];
            $this->assertEqualsWithDelta($h['u95'], $budget['u95'], 1e-12, 'U95 satuan kerja');
            $this->assertEqualsWithDelta($h['k'], $budget['k'], 1e-12);
            $this->assertSame($h['df'], $budget['df_inverse_t']);
            // Kolom tabel dibulatkan ke decimal(20,8) — presisi penuh ada di jejak.
            $this->assertEqualsWithDelta($h['u95'] / $h['faktor'], (float) $b->ketidakpastian_diperluas, 1e-8, 'U95 tampil kg/cm²');

            $rantai = $b->type_b_components['tekanan_rantai'];
            $this->assertEqualsWithDelta($h['per_titik'][$i]['terkoreksi_up'], $rantai['terkoreksi_up'], 1e-12);
            $this->assertEqualsWithDelta($h['per_titik'][$i]['terkoreksi_down'], $rantai['terkoreksi_down'], 1e-12);
        }
    }

    public function test_titik_di_luar_rentang_kalibrator_diblokir(): void
    {
        [$alat, $teknisi] = $this->siapkan();
        // 700 kg/cm² = 686 bar, di atas tabel SPMK (0–600 bar). Master Excel
        // tetap menerapkan koreksi 600 bar; sistem memblokir (T-4).
        $titik = [
            ['titik_ukur' => '0', M::PERAN_UP => ['0', '0', '0'], M::PERAN_DOWN => ['0', '0', '0']],
            ['titik_ukur' => '700', M::PERAN_UP => ['700', '700', '700'], M::PERAN_DOWN => ['700', '700', '700']],
        ];

        $belum = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $this->payload($alat, ['kapasitas' => '800'], $titik))
            ->assertOk()
            ->json('data.belum_dihitung');

        $this->assertNotEmpty($belum);
        $this->assertStringContainsString('di luar rentang', $belum[0]['alasan']);
    }

    public function test_analog_tanpa_rasio_jarum_diblokir(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $belum = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $this->payload($alat, ['rasio_jarum' => null]))
            ->assertOk()
            ->json('data.belum_dihitung');

        $this->assertNotEmpty($belum);
        $this->assertStringContainsString('rasio jarum', $belum[0]['alasan']);
    }

    public function test_satuan_di_luar_daftar_varian_diblokir(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        // Torr ada di DRUCK tapi TIDAK di SPMK (7 satuan).
        $belum = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $this->payload($alat, ['satuan' => 'Torr']))
            ->assertOk()
            ->json('data.belum_dihitung');

        $this->assertNotEmpty($belum);
        $this->assertStringContainsString('tidak ada di daftar', $belum[0]['alasan']);
    }

    public function test_jumlah_pengulangan_kurang_ditahan(): void
    {
        [$alat, $teknisi] = $this->siapkan();
        $titik = [['titik_ukur' => '0', M::PERAN_UP => ['0', '0'], M::PERAN_DOWN => ['0', '0', '0']]];

        $belum = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $this->payload($alat, titik: $titik))
            ->assertOk()
            ->json('data.belum_dihitung');

        $this->assertNotEmpty($belum);
        $this->assertStringContainsString('TEPAT', $belum[0]['alasan']);
    }

    public function test_varian_yang_tidak_berlaku_untuk_alatnya_diblokir(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $belum = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $this->payload($alat, ['varian' => Tabel::DIFFERENTIAL]))
            ->assertOk()
            ->json('data.belum_dihitung');

        $this->assertNotEmpty($belum);
        $this->assertStringContainsString('Kalibrator standar', $belum[0]['alasan']);
    }

    /**
     * Blok sertifikat dari hasil TERSIMPAN (sesi SPMK lewat API): dua arah +
     * histeresis + `k` dicetak ROUND(k;1).
     */
    public function test_tabel_sertifikat_dua_arah_dari_hasil_tersimpan(): void
    {
        [$alat, $teknisi] = $this->siapkan();
        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');
        $h = self::harapanSpmk()['harapan_benar'];

        $tabel = (new PressureGaugeProfile)->tabelSertifikatTekanan(CalibrationSession::findOrFail($id));

        $this->assertSame('kg/cm2', $tabel['satuan']);
        $this->assertCount(3, $tabel['baris']);
        $this->assertSame(2.0, $tabel['k_cetak']);
        $this->assertSame(1, $tabel['desimal'], 'Resolusi 0,5 → satu desimal.');

        foreach ($tabel['baris'] as $i => $b) {
            $p = $h['per_titik'][$i];
            $this->assertEqualsWithDelta($p['terkoreksi_up'] / $h['faktor'], $b['standar_up'], 1e-12);
            $this->assertEqualsWithDelta($p['terkoreksi_down'] / $h['faktor'], $b['standar_down'], 1e-12);
            $this->assertEqualsWithDelta($p['hys'][0] / $h['faktor'], $b['histeresis'][0], 1e-12);
        }
    }
}
