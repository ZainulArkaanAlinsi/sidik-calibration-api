<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Studio\PetaSel\PetaSel;
use App\Support\FlowmeterMentah;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fase 3 (format sertifikat dari master): `format_sertifikat` peta sel —
 * number format sel lembar SERTIFIKAT .xlsm ASLI (diverifikasi
 * `docs/skrip/verifikasi-peta-xlsm.py`) — diadu ke konstanta yang DIPAKAI
 * render hari ini (`formatKolomSertifikat()`). Render belum dipindah ke peta.
 *
 * Beda yang sudah diketahui DIPATOK di [BEDA_DIKETAHUI]: itu temuan untuk
 * diputuskan, bukan untuk "dibetulkan" diam-diam di salah satu sisi. Beda baru
 * (atau beda lama yang hilang) membuat test ini merah.
 */
class StudioFormatSertifikatTest extends TestCase
{
    /** paket/varian/kolom => [format master, format kode] */
    private const BEDA_DIKETAHUI = [
        // Master 0-25 mm berisi contoh sesi INCH (J29 0.0000); kode memakai 0.000 untuk
        // semua varian karena sesi selalu mm (MicrometerProfile::formatKolomSertifikat).
        'micrometer/A/u95' => ['0.0000', '0.000'],
        // Master UFM mencetak U95 (% of reading) 0.0; kode tidak memberi format U95 UFM
        // (FlowmeterProfile::FORMAT_SERTIFIKAT [.., null]) -> jatuh ke desimal lama.
        'flowmeter/totalizer/u95' => ['0.0', null],
        'flowmeter/flowrate/u95' => ['0.0', null],
    ];

    /**
     * paket => list [varian, kode profil, titik ukur, spesifikasi_alat].
     *
     * @return array<string, array{string, list<array{?string, string, float, array<string, mixed>}>}>
     */
    public static function kasus(): array
    {
        $flow = static fn (string $mode): array => [FlowmeterMentah::KUNCI_SESI => ['mode' => $mode, 'varian_metode' => 'ufm']];

        return [
            'micrometer' => ['micrometer', [['A', 'micrometer', 10.0, []], ['B', 'micrometer', 30.0, []], ['C', 'micrometer', 60.0, []], ['D', 'micrometer', 80.0, []]]],
            'dial_indicator' => ['dial_indicator', [[null, 'dial_indicator', 5.0, []]]],
            'height_gauge' => ['height_gauge', [[null, 'height_gauge', 100.0, []]]],
            'jangka_sorong' => ['jangka_sorong', [[null, 'jangka_sorong', 100.0, []]]],
            'volumetric' => ['volumetric', [['fixed', 'labu_ukur', 100.0, []], ['fixed', 'pipet_volume', 10.0, []], ['graduated', 'buret', 50.0, []], ['graduated', 'gelas_ukur', 100.0, []]]],
            'flowmeter' => ['flowmeter', [['totalizer', 'flowmeter_totalizer', 100.0, $flow('totalizer')], ['flowrate', 'flowmeter_flowrate', 100.0, $flow('flowrate')]]],
            'gaya' => ['gaya', [['utm', 'utm', 10.0, []], ['load_cell', 'load_cell', 10.0, []], ['proving_ring', 'proving_ring', 10.0, []]]],
            'putaran' => ['putaran', [['tachometer', 'tachometer', 500.0, []], ['tachometer', 'tachometer', 20000.0, []], ['centrifuge', 'centrifuge', 500.0, []]]],
            'timer_stopwatch' => ['timer_stopwatch', [[null, 'timer_stopwatch', 60.0, []]]],
        ];
    }

    /** @param  list<array{?string, string, float, array<string, mixed>}>  $daftar */
    #[DataProvider('kasus')]
    public function test_format_master_lawan_konstanta_kode(string $paket, array $daftar): void
    {
        $peta = PetaSel::untuk($paket) ?? $this->fail("peta {$paket}");
        $entri = $peta->isi['format_sertifikat']['entri'] ?? $this->fail("{$paket} tanpa format_sertifikat");
        $beda = [];
        $diadu = 0;

        foreach ($daftar as [$varian, $kodeProfil, $titik, $spesifikasi]) {
            $profil = app(CalibrationProfileRegistry::class)->untukKode($kodeProfil) ?? $this->fail($kodeProfil);
            $this->assertSame($paket, $profil->kodePaketAcuan(), $kodeProfil);
            $kode = $profil->formatKolomSertifikat(new CalibrationSession(['spesifikasi_alat' => $spesifikasi]), $titik);

            foreach (PetaSel::untukVarian($entri, $varian) as $f) {
                if ((isset($f['titik_min']) && $titik < $f['titik_min']) || (isset($f['titik_di_bawah']) && $titik >= $f['titik_di_bawah'])) {
                    continue;
                }

                $diadu++;
                $kunci = $paket.'/'.($varian ?? '-').'/'.$f['kolom'];

                if (($kode[$f['kolom']] ?? null) !== $f['format']) {
                    $beda[$kunci] = [$f['format'], $kode[$f['kolom']] ?? null];
                }
            }
        }

        $this->assertGreaterThan(0, $diadu);
        $harap = array_filter(self::BEDA_DIKETAHUI, static fn (string $k): bool => str_starts_with($k, $paket.'/'), ARRAY_FILTER_USE_KEY);
        ksort($beda);
        ksort($harap);
        $this->assertSame($harap, $beda, "{$paket}: beda format master lawan kode berubah");
    }
}
