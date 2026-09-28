<?php

namespace App\Console\Commands;

use App\Services\Calibration\PistonVolumeCalculator;
use App\Services\Calibration\Profiles\BuretDigitalProfile;
use App\Services\Calibration\Profiles\DifferentialPressureProfile;
use App\Services\Calibration\Profiles\DispensettProfile;
use App\Services\Calibration\Profiles\PistonPipetteProfile;
use App\Services\Calibration\Profiles\PressureGaugeProfile;
use App\Services\Calibration\Profiles\VacuumGaugeProfile;
use App\Services\Calibration\TabelStandarPistonVolume;
use App\Services\Calibration\TabelStandarTekanan;
use App\Services\Calibration\TekananCalculator;
use App\Support\LogMetodeTekananPiston as LogMetode;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Mesin hitung APLIKASI untuk percobaan paralel tekanan & piston volume
 * (`docs/skrip/uji-paralel-tekanan-piston.py`).
 *
 * Masukan: JSON di stdin, bentuknya persis `masukan` di
 * `database/data/sesi-master-{tekanan,piston-volume}.json` — data mentah yang
 * dibaca skrip dari INPUT DATA workbook sesi. Keluaran: JSON
 * `{master, benar, versi_rumus, kode_formula}`, dua mode dari kalkulator yang
 * SAMA dengan yang dipakai profil waktu menyimpan sesi.
 *
 * Sengaja TANPA database. Yang diuji di sini rantai hitungnya — rata-rata,
 * simpangan baku, indeks, koreksi standar, tiap komponen budget, u_c, v_eff,
 * k, U, CMC — dari data mentah yang sama persis dengan yang dihitung Excel.
 * Menempuh jalur simpan sesi berarti butuh alat, standar, dan organisasi di
 * database, dan `.env` mesin kerja menunjuk produksi.
 *
 * CMC tekanan di sini CMC MASTER (`cmc_master` workbook), bukan baris
 * `calibration_capabilities` organisasi: yang diadu adalah angka Excel.
 */
class HitungMentahKalibrasi extends Command
{
    protected $signature = 'kalibrasi:hitung-mentah
        {keluarga : tekanan atau piston}
        {--varian= : kalibrator tekanan: druck07g, druck13g, spmk, differential}
        {--berkas= : baca masukan dari berkas JSON ini, bukan stdin}';

    protected $description = 'Hitung satu sesi tekanan/piston dari data mentah JSON (stdin), mode master & benar — untuk uji paralel';

    public function handle(): int
    {
        $berkas = $this->option('berkas');
        $mentah = is_string($berkas) && $berkas !== ''
            ? (is_file($berkas) ? file_get_contents($berkas) : false)
            : stream_get_contents(STDIN);
        $masukan = json_decode((string) $mentah, true);

        if (! is_array($masukan)) {
            $this->error('Masukan stdin bukan objek JSON.');

            return self::FAILURE;
        }

        try {
            $hasil = match ((string) $this->argument('keluarga')) {
                LogMetode::TEKANAN => $this->tekanan((string) $this->option('varian'), $masukan),
                LogMetode::PISTON => $this->piston($masukan),
                default => throw new InvalidArgumentException('Keluarga harus `tekanan` atau `piston`.'),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // `serialize_precision = -1`: float dicetak sependek mungkin yang tetap
        // balik ke bit yang sama — perbandingan 1e-12 butuh itu.
        $this->line((string) json_encode($hasil, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function tekanan(string $varian, array $m): array
    {
        if (! in_array($varian, TabelStandarTekanan::VARIAN, true)) {
            throw new InvalidArgumentException('--varian wajib salah satu: '.implode(', ', TabelStandarTekanan::VARIAN));
        }

        $vakum = ($m['jenis_tekanan'] ?? null) === 'vakum';
        $cmc = TabelStandarTekanan::cmcMaster($varian, TabelStandarTekanan::kunciCmcMaster($varian, $vakum));

        // U95 master = MAX(U, CMC) — baris akhir `PERHITUNGAN U95%`.
        $lengkapi = static fn (array $r): array => [
            ...$r,
            'cmc' => $cmc,
            'u95' => $cmc === null ? $r['U'] : max($r['U'], $cmc),
        ];

        $kalk = new TekananCalculator;
        $profil = match (true) {
            $varian === TabelStandarTekanan::DIFFERENTIAL => new DifferentialPressureProfile,
            $vakum => new VacuumGaugeProfile,
            default => new PressureGaugeProfile,
        };

        return [
            'master' => $lengkapi($kalk->hitungSesi($varian, $m, TekananCalculator::MODE_MASTER)),
            'benar' => $lengkapi($kalk->hitungSesi($varian, $m, TekananCalculator::MODE_BENAR)),
            'versi_rumus' => LogMetode::versiTerakhir(LogMetode::TEKANAN),
            'kode_formula' => $profil->kodeFormula(),
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function piston(array $m): array
    {
        $profil = match ($m['jenis'] ?? null) {
            TabelStandarPistonVolume::PISTON_PIPETTE => new PistonPipetteProfile,
            TabelStandarPistonVolume::DISPENSETT => new DispensettProfile,
            TabelStandarPistonVolume::BURET_DIGITAL => new BuretDigitalProfile,
            default => throw new InvalidArgumentException('`jenis` piston tidak dikenal: '.json_encode($m['jenis'] ?? null)),
        };
        $kalk = new PistonVolumeCalculator;

        return [
            'master' => $kalk->hitungSesi($m, PistonVolumeCalculator::MODE_MASTER),
            'benar' => $kalk->hitungSesi($m, PistonVolumeCalculator::MODE_BENAR),
            'versi_rumus' => LogMetode::versiTerakhir(LogMetode::PISTON),
            'kode_formula' => $profil->kodeFormula(),
        ];
    }
}
