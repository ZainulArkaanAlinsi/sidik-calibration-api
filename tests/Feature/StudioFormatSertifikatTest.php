<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\Enclosure\EnclosureProfileBase;
use App\Services\Calibration\TabelStandarTids;
use App\Services\Studio\PetaSel\PetaSel;
use App\Support\FlowmeterMentah;
use App\Support\PistonVolumeMentah;
use App\Support\TekananMentah;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fase 3 (format sertifikat dari master, K-50-15: sertifikat = lembar
 * SERTIFIKAT master persis): `format_sertifikat` peta sel — number format sel
 * lembar SERTIFIKAT .xlsm ASLI (diverifikasi `docs/skrip/verifikasi-peta-xlsm.py`)
 * — wajib SAMA dengan format yang dipakai render (`formatKolomSertifikat()`)
 * untuk sesi varian itu.
 *
 * Sampai 22 Okt 2026 test ini mematok beda yang "diketahui" (Tekanan tanpa
 * format, TITS Source, TIDS, Micrometer 0-25, Flowmeter UFM). Keputusan
 * pemilik K-50-15 menjadikannya bug: profil-profil itu kini membaca
 * `format_sertifikat` peta (lewat `CalibrationProfile::formatSertifikatMaster()`),
 * dan tidak ada lagi beda yang dipatok. Sertifikat yang SUDAH terbit tidak
 * berubah — formatnya beku di snapshot.
 */
class StudioFormatSertifikatTest extends TestCase
{
    /**
     * Sesi contoh varian itu: konteks yang dibaca profil untuk menentukan
     * varian master (spesifikasi_alat, mode, standar, alat).
     *
     * @param  array<string, mixed>  $spesifikasi
     * @param  array<string, mixed>  $atribut
     */
    private static function sesi(array $spesifikasi = [], array $atribut = [], ?Equipment $alat = null, ?Standard $standar = null): CalibrationSession
    {
        $sesi = new CalibrationSession(['spesifikasi_alat' => $spesifikasi] + $atribut);
        $sesi->setRelation('equipment', $alat);
        $sesi->setRelation('standard', $standar);

        return $sesi;
    }

    /**
     * paket => list [varian, kode profil, titik ukur, pembuat sesi].
     *
     * @return array<string, array{string, list<array{?string, string, float, callable(): CalibrationSession}>}>
     */
    public static function kasus(): array
    {
        $flow = static fn (string $mode): callable => static fn (): CalibrationSession => self::sesi([FlowmeterMentah::KUNCI_SESI => ['mode' => $mode, 'varian_metode' => 'ufm']]);
        $piston = static fn (string $keluarga): callable => static fn (): CalibrationSession => self::sesi([PistonVolumeMentah::KUNCI_SESI => ['keluarga' => $keluarga]]);
        $kosong = static fn (): CalibrationSession => self::sesi();
        $mikro = static fn (float $maks): callable => static fn (): CalibrationSession => self::sesi(alat: new Equipment(['range_min' => $maks - 25.0, 'range_max' => $maks]));
        $tits = static fn (string $mode): callable => static fn (): CalibrationSession => self::sesi(atribut: ['mode_kalibrasi' => $mode]);
        $tids = static fn (string $keluarga): callable => static fn (): CalibrationSession => self::sesi(standar: new Standard(['serial_number' => TabelStandarTids::KELUARGA_SERTIFIKAT[$keluarga]['serial']]));
        $tekanan = static fn (string $varian): callable => static fn (): CalibrationSession => self::sesi([TekananMentah::KUNCI_SESI => ['varian' => $varian]]);

        return [
            'micrometer' => ['micrometer', [['A', 'micrometer', 10.0, $mikro(25.0)], ['B', 'micrometer', 30.0, $mikro(50.0)], ['C', 'micrometer', 60.0, $mikro(75.0)], ['D', 'micrometer', 80.0, $mikro(100.0)]]],
            'dial_indicator' => ['dial_indicator', [[null, 'dial_indicator', 5.0, $kosong]]],
            'height_gauge' => ['height_gauge', [[null, 'height_gauge', 100.0, $kosong]]],
            'jangka_sorong' => ['jangka_sorong', [[null, 'jangka_sorong', 100.0, $kosong]]],
            'volumetric' => ['volumetric', [['fixed', 'labu_ukur', 100.0, $kosong], ['fixed', 'pipet_volume', 10.0, $kosong], ['graduated', 'buret', 50.0, $kosong], ['graduated', 'gelas_ukur', 100.0, $kosong]]],
            'flowmeter' => ['flowmeter', [['totalizer', 'flowmeter_totalizer', 100.0, $flow('totalizer')], ['flowrate', 'flowmeter_flowrate', 100.0, $flow('flowrate')]]],
            'gaya' => ['gaya', [['utm', 'utm', 10.0, $kosong], ['load_cell', 'load_cell', 10.0, $kosong], ['proving_ring', 'proving_ring', 10.0, $kosong]]],
            'putaran' => ['putaran', [['tachometer', 'tachometer', 500.0, $kosong], ['tachometer', 'tachometer', 20000.0, $kosong], ['centrifuge', 'centrifuge', 500.0, $kosong]]],
            'timer_stopwatch' => ['timer_stopwatch', [[null, 'timer_stopwatch', 60.0, $kosong]]],
            'piston_volume' => ['piston_volume', [['fixed', 'piston_pipette', 1.0, $piston('fixed')], ['fixed', 'dispensett', 10.0, $piston('fixed')], ['graduated', 'buret_digital', 10.0, $piston('graduated')]]],
            'kalibrator_suhu' => ['kalibrator_suhu', [['measure', 'tits', 100.0, $tits('measure')], ['source', 'tits', 100.0, $tits('source')]]],
            'tids' => ['tids', [['yokogawa', 'tids', 100.0, $tids('yokogawa')], ['yokogawa', 'tids', 100.0, $tids('constant')], ['recorder', 'tids', 100.0, $tids('recorder')]]],
            'sieve' => ['sieve', [[null, 'sieve', 19.0, $kosong]]],
            'tekanan' => ['tekanan', [['druck07g', 'pressure_gauge', 100.0, $tekanan('druck07g')], ['druck13g', 'pressure_gauge', 100.0, $tekanan('druck13g')], ['spmk', 'pressure_gauge', 5.0, $tekanan('spmk')], ['differential', 'differential_pressure', 2.0, $tekanan('differential')]]],
        ];
    }

    /**
     * Enclosure tidak memakai formatKolomSertifikat(): desimal cetaknya
     * `EnclosureProfileBase::FORMAT_CETAK` per varian, plus format `Suhu
     * Setting` per baris (`FORMAT_SETTING_PER_BARIS`, CY `C46:C47` General).
     * Peta mencatat varian Constant_Yokogawa (Recorder belum dipetakan) —
     * wajib sama persis.
     */
    public function test_format_master_enclosure_lawan_format_cetak(): void
    {
        $entri = PetaSel::untuk('enclosure')?->isi['format_sertifikat']['entri'] ?? $this->fail('enclosure tanpa format_sertifikat');
        $kode = EnclosureProfileBase::FORMAT_CETAK['constant_yokogawa'];
        $format = static fn (?int $n): string => $n === null ? 'General' : ($n === 0 ? '0' : '0.'.str_repeat('0', $n));
        $master = [];

        foreach ($entri as $f) {
            $master[$f['kolom']] = $f['format'];
        }

        $harap = array_map($format, $kode);

        foreach (EnclosureProfileBase::FORMAT_SETTING_PER_BARIS['constant_yokogawa'] as $i => $d) {
            $harap['setting_baris_'.($i + 1)] = $format($d);
        }

        ksort($master);
        ksort($harap);
        $this->assertSame($harap, $master);
    }

    /** @param  list<array{?string, string, float, callable(): CalibrationSession}>  $daftar */
    #[DataProvider('kasus')]
    public function test_format_master_sama_dengan_format_render(string $paket, array $daftar): void
    {
        $peta = PetaSel::untuk($paket) ?? $this->fail("peta {$paket}");
        $entri = $peta->isi['format_sertifikat']['entri'] ?? $this->fail("{$paket} tanpa format_sertifikat");
        $beda = [];
        $diadu = 0;

        foreach ($daftar as [$varian, $kodeProfil, $titik, $buatSesi]) {
            $profil = app(CalibrationProfileRegistry::class)->untukKode($kodeProfil) ?? $this->fail($kodeProfil);
            $this->assertSame($paket, $profil->kodePaketAcuan(), $kodeProfil);
            $kode = $profil->formatKolomSertifikat($buatSesi(), $titik);

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
        $this->assertSame([], $beda, "{$paket}: format render beda dari lembar SERTIFIKAT master (K-50-15)");
    }
}
