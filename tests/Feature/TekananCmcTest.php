<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Services\Calibration\Profiles\DifferentialPressureProfile;
use App\Services\Calibration\Profiles\PressureGaugeProfile;
use App\Services\Calibration\Profiles\TekananProfile;
use App\Services\Calibration\Profiles\VacuumGaugeProfile;
use App\Services\Calibration\TabelStandarTekanan as Tabel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * CMC tiap varian × jenis tekanan, dibaca dari baris lampiran LK-285-IDN lalu
 * dikonversi ke satuan kerja varian itu, WAJIB sama dengan sel master
 * `DATABASE!S5/S6`.
 *
 * Ini yang membantah kecurigaan panduan eksternal bahwa CMC vakum
 * (1,4561477 kPa; 0,21119628545744232 Psi) "hasil tempel": keduanya
 * `0,43*S34` — 0,43 inHg lampiran no. 25 dikonversi. Kalau lampirannya suatu
 * saat berubah, test ini yang pertama merah, sebelum sertifikat terbit.
 */
class TekananCmcTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: TekananProfile, 1: string, 2: string}> */
    public static function kasus(): array
    {
        return [
            '07G non-vakum' => [new PressureGaugeProfile, Tabel::DRUCK07G, 'Non Vacum'],
            '13G non-vakum' => [new PressureGaugeProfile, Tabel::DRUCK13G, 'Non Vacum'],
            'SPMK' => [new PressureGaugeProfile, Tabel::SPMK, 'Pressure'],
            '07G vakum' => [new VacuumGaugeProfile, Tabel::DRUCK07G, 'Vacum'],
            '13G vakum' => [new VacuumGaugeProfile, Tabel::DRUCK13G, 'Vacum'],
            'Differential' => [new DifferentialPressureProfile, Tabel::DIFFERENTIAL, '-10 mbar ~ 10 mbar'],
        ];
    }

    #[DataProvider('kasus')]
    public function test_cmc_lampiran_terkonversi_sama_dengan_sel_master(TekananProfile $profil, string $varian, string $kunciMaster): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = Equipment::where('organization_id', 1)->firstOrFail();

        $cmc = (new ReflectionMethod($profil, 'cmcKerja'))->invoke($profil, $alat, $varian);
        $master = Tabel::cmcMaster($varian, $kunciMaster);

        $this->assertNotNull($cmc, "Baris lampiran untuk {$varian} ({$kunciMaster}) tidak ketemu.");
        $this->assertNotNull($master);
        $this->assertEqualsWithDelta($master, $cmc, 1e-15 * max(1.0, $master));
    }
}
