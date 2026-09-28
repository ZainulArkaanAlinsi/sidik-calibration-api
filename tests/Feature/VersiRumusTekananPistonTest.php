<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\FormulaVersion;
use App\Models\UncertaintyCalculation;
use App\Services\CalibrationValidator;
use App\Support\LogMetodeTekananPiston as LogMetode;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tiap hasil hitung tekanan & piston membawa DUA catatan versi yang wajib
 * sama: versi rumus yang benar-benar dijalankan kode
 * (`type_b_components.versi_rumus`) dan versi formula yang distempelkan
 * (`formula_version_id` → `parameter.versi_rumus`).
 *
 * Kalau kode sudah menghitung dengan versi baru di log metode sementara versi
 * formula yang berlaku masih versi lama, sertifikatnya mengaku dihitung
 * dengan aturan yang tidak dipakai. Tidak ada angka yang salah di situ —
 * yang rusak ketertelusurannya — jadi satu-satunya tempat yang bisa
 * menangkapnya adalah validator sebelum terbit.
 */
class VersiRumusTekananPistonTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function sesiContoh(): array
    {
        return [
            'Pressure Gauge DRUCK13G' => ['DEMO-PG-13G-001', LogMetode::TEKANAN],
            'Vacuum Gauge DRUCK07G' => ['DEMO-VG-07G-001', LogMetode::TEKANAN],
            'Differential Additel' => ['DEMO-DP-ADT-001', LogMetode::TEKANAN],
            'Piston Pipette fixed' => ['DEMO-PP-FIX-001', LogMetode::PISTON],
            'Buret Digital graduated' => ['DEMO-BD-GRD-001', LogMetode::PISTON],
            'Dispensett fixed' => ['DEMO-DS-FIX-001', LogMetode::PISTON],
        ];
    }

    private function sesi(string $serial): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        return CalibrationSession::query()
            ->whereHas('equipment', fn ($q) => $q->where('serial_number', $serial))
            ->with('uncertaintyCalculations')
            ->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function temuanVersi(CalibrationSession $sesi): array
    {
        $hasil = app(CalibrationValidator::class)->periksa($sesi->fresh());

        return array_values(array_filter(
            $hasil['temuan'],
            fn (array $t): bool => $t['kode'] === 'versi_rumus_tidak_sepadan',
        ));
    }

    #[DataProvider('sesiContoh')]
    public function test_hasil_hitung_dan_versi_formula_membawa_versi_rumus_yang_sama(string $serial, string $keluarga): void
    {
        $sesi = $this->sesi($serial);
        $versi = LogMetode::versiTerakhir($keluarga);

        $this->assertNotEmpty($sesi->uncertaintyCalculations);

        foreach ($sesi->uncertaintyCalculations as $u) {
            $this->assertSame($versi, $u->type_b_components['versi_rumus']['versi'] ?? null, 'Jejak hasil hitung tanpa versi rumus.');
            $this->assertSame(LogMetode::BERKAS, $u->type_b_components['versi_rumus']['log']);
            $this->assertNotNull($u->formula_version_id, 'Sesi contoh tersimpan tanpa stempel versi formula.');
            $this->assertSame($versi, FormulaVersion::findOrFail($u->formula_version_id)->parameter['versi_rumus'] ?? null);
        }

        $this->assertSame([], $this->temuanVersi($sesi));
    }

    /** Kode sudah pindah ke versi baru, versi formula yang berlaku belum. */
    public function test_hasil_hitung_versi_baru_dengan_stempel_versi_lama_menahan_penerbitan(): void
    {
        $sesi = $this->sesi('DEMO-PP-FIX-001');

        $u = $sesi->uncertaintyCalculations->first();
        $jejak = $u->type_b_components;
        $jejak['versi_rumus']['versi'] = 'PISTON-2099.01.01-1';
        UncertaintyCalculation::whereKey($u->id)->update(['type_b_components' => json_encode($jejak)]);

        $temuan = $this->temuanVersi($sesi);

        $this->assertCount(1, $temuan);
        $this->assertSame(CalibrationValidator::ERROR, $temuan[0]['tingkat']);
        $this->assertStringContainsString('PISTON-2099.01.01-1', $temuan[0]['pesan']);
        $this->assertStringContainsString(LogMetode::versiTerakhir(LogMetode::PISTON), $temuan[0]['pesan']);
        $this->assertFalse(app(CalibrationValidator::class)->periksa($sesi->fresh())['boleh_terbit']);
    }

    /**
     * Versi formula yang dibuat lewat layar Rumus tanpa `versi_rumus` di
     * parameternya tidak bisa menjelaskan hasil hitung tekanan/piston.
     */
    public function test_versi_formula_tanpa_versi_rumus_menahan_penerbitan(): void
    {
        $sesi = $this->sesi('DEMO-PG-13G-001');
        $lama = FormulaVersion::findOrFail($sesi->uncertaintyCalculations->first()->formula_version_id);

        $baru = $lama->formula->versions()->create([
            'organization_id' => $lama->organization_id,
            'nomor_versi' => FormulaVersion::nomorBerikutnya($lama->formula_id),
            'sumber' => FormulaVersion::SUMBER_KODE,
            'parameter' => ['faktor_cakupan_k' => 2],
            'status' => FormulaVersion::STATUS_DRAFT,
            'effective_from' => '2000-01-01',
        ]);
        UncertaintyCalculation::where('calibration_session_id', $sesi->id)->update(['formula_version_id' => $baru->id]);

        $temuan = $this->temuanVersi($sesi);

        $this->assertNotEmpty($temuan);
        $this->assertSame(CalibrationValidator::ERROR, $temuan[0]['tingkat']);
    }

    /** Alat di luar enam alat ini tidak ikut diperiksa — log-nya bukan milik mereka. */
    public function test_alat_lain_tidak_disentuh_pemeriksaan_ini(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::query()
            ->whereHas('uncertaintyCalculations')
            ->whereHas('equipment', fn ($q) => $q->whereNotIn('serial_number', array_column(self::sesiContoh(), 0)))
            ->firstOrFail();

        $this->assertSame([], $this->temuanVersi($sesi));
    }
}
