<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\Enclosure\EnclosureProfileBase;
use App\Services\Calibration\TabelStandarTids;
use App\Services\Studio\PetaSel\Alamat;
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
 * `format_sertifikat` peta (lewat `CalibrationProfile::formatSertifikatMaster()`).
 * Sertifikat yang SUDAH terbit tidak berubah — formatnya beku di snapshot.
 *
 * Peta = render tidak membuktikan peta = master per sel (temuan F-3, 22 Okt
 * 2026): `test_entri_peta_sama_dengan_format_tiap_sel_master` mengadu tiap sel
 * ke number_format master yang dibekukan. Satu-satunya beda master vs render
 * yang tersisa dipatok di [BEDA_DIKETAHUI] (Tekanan baris 7-13, menunggu lab).
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
     * wajib sama persis. `Pembacaan Indikator` set point 5/6 (`B33`/`B35`
     * `0.00`) = `FORMAT_INDIKATOR_PER_BARIS` (temuan F-1, 22 Okt 2026).
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

        foreach (EnclosureProfileBase::FORMAT_INDIKATOR_PER_BARIS['constant_yokogawa'] as $i => $d) {
            $harap['indikator_baris_'.($i + 1)] = $format($d);
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
                // Entri per baris (`koreksi_baris_7`) diadu ke format kolom
                // induknya yang dipakai render untuk SEMUA baris.
                $kolomRender = (string) preg_replace('/_baris_[0-9_]+$/', '', $f['kolom']);

                if (($kode[$kolomRender] ?? null) !== $f['format']) {
                    $beda[$kunci] = [$f['format'], $kode[$kolomRender] ?? null];
                }
            }
        }

        $this->assertGreaterThan(0, $diadu);
        $diketahui = array_filter(self::BEDA_DIKETAHUI, static fn (string $k): bool => str_starts_with($k, $paket.'/'), ARRAY_FILTER_USE_KEY);
        ksort($beda);
        ksort($diketahui);
        $this->assertSame($diketahui, $beda, "{$paket}: format render beda dari lembar SERTIFIKAT master (K-50-15)");
    }

    /**
     * Beda master vs render yang SENGAJA dipatok (kunci => [format master,
     * format render]) — bukan diterima diam-diam. Temuan F-2 paritas format
     * (22 Okt 2026): master DRUCK07G/DRUCK13G/Differential memformat UUT dan
     * Correction baris ke-7..13 (`E28:E34`, `N28:P34`) lain dari baris 1-6
     * (N28 `0.000`, N29:N34 `0.00`, E28 `0.0`, E29:E34 `0.000`). Kode lama
     * menilainya salin-tempel; render TIDAK diubah sampai lab menjawab
     * `docs/pertanyaan-lab-format-sertifikat.md` #5. Hanya berdampak pada sesi
     * tekanan 7 titik atau lebih.
     *
     * @var array<string, array{string, string}>
     */
    private const BEDA_DIKETAHUI = [
        'tekanan/druck07g/uut_baris_7' => ['0.0', '0'],
        'tekanan/druck07g/uut_baris_8_13' => ['0.000', '0'],
        'tekanan/druck07g/koreksi_baris_7' => ['0.000', '0.0'],
        'tekanan/druck07g/koreksi_baris_8_13' => ['0.00', '0.0'],
        'tekanan/druck13g/uut_baris_7' => ['0.0', '0'],
        'tekanan/druck13g/uut_baris_8_13' => ['0.000', '0'],
        'tekanan/druck13g/koreksi_baris_7' => ['0.000', '0.0'],
        'tekanan/druck13g/koreksi_baris_8_13' => ['0.00', '0.0'],
        'tekanan/differential/uut_baris_8_13' => ['0.000', '0.0'],
        'tekanan/differential/koreksi_baris_7' => ['0.000', '0.0'],
        'tekanan/differential/koreksi_baris_8_13' => ['0.00', '0.0'],
    ];

    /** Satu berkas, bukan subdirektori: `StudioPetaSelXlsmTest` membaca `peta-sel-xlsm/<paket>/*.json`. */
    private const FIXTURE_FORMAT = 'tests/Fixtures/peta-sel-xlsm/format-sertifikat.json';

    /**
     * Temuan F-3 (22 Okt 2026): test render di atas hanya membuktikan peta =
     * render, bukan peta = master PER SEL — rentang `B25:B35` bisa menyimpan
     * `0.0` sementara `B33` master `0.00`. Test ini mengadu tiap sel tiap
     * rentang entri `format_sertifikat` ke number_format sel master yang
     * DIBEKUKAN (`docs/skrip/bekukan-format-sertifikat-xlsm.py`, hanya alamat +
     * kode format, tanpa nilai). `null` = sel bagian merge, tidak tercetak.
     * Rentang yang digabung tidak bisa lagi menyembunyikan baris yang beda:
     * peta harus dipecah per baris (`indikator_baris_5`, `koreksi_baris_7`).
     */
    public function test_entri_peta_sama_dengan_format_tiap_sel_master(): void
    {
        $paketPeta = [];

        foreach (glob(PetaSel::direktori().'/*.json') ?: [] as $berkas) {
            $paket = basename($berkas, '.json');
            $peta = PetaSel::untuk($paket);

            if ($peta !== null && isset($peta->isi['format_sertifikat'])) {
                $paketPeta[] = $paket;
            }
        }

        $semua = json_decode((string) file_get_contents(base_path(self::FIXTURE_FORMAT)), true, flags: JSON_THROW_ON_ERROR)['paket'];
        $paketFixture = array_keys($semua);
        sort($paketPeta);
        sort($paketFixture);
        $this->assertNotSame([], $paketPeta);
        $this->assertSame($paketPeta, $paketFixture, 'Tiap peta ber-format_sertifikat wajib punya fixture format sel master (jalankan docs/skrip/bekukan-format-sertifikat-xlsm.py).');

        $beda = [];

        foreach ($paketPeta as $paket) {
            $entri = PetaSel::untuk($paket)->isi['format_sertifikat']['entri'];
            $fixture = $semua[$paket];
            $this->assertNotEmpty($fixture['varian'] ?? [], $paket);

            foreach ($entri as $f) {
                foreach ($f['varian'] ?? [] as $v) {
                    $this->assertArrayHasKey($v, $fixture['varian'], "{$paket}: varian {$v} tanpa fixture master");
                }
            }

            foreach ($fixture['varian'] as $varian => $isi) {
                $sel = $isi['sel'];
                $tercakup = [];

                foreach (PetaSel::untukVarian($entri, $varian === '-' ? null : $varian) as $f) {
                    $dicetak = 0;

                    foreach ($f['sel'] as $rentang) {
                        $a = Alamat::urai($rentang) ?? $this->fail("{$paket}: alamat {$rentang}");

                        for ($b = $a['b1']; $b <= $a['b2']; $b++) {
                            for ($k = $a['k1']; $k <= $a['k2']; $k++) {
                                $alamat = Alamat::sel($a['lembar'], $k, $b);
                                $this->assertArrayHasKey($alamat, $sel, "{$paket}/{$varian}: {$alamat} tidak ada di fixture — bekukan ulang");
                                $tercakup[$alamat] = true;

                                if ($sel[$alamat] === null) {
                                    continue;
                                }

                                $dicetak++;

                                if ($sel[$alamat] !== $f['format']) {
                                    $beda["{$paket}/{$varian}/{$f['kolom']}/{$alamat}"] = [$f['format'], $sel[$alamat]];
                                }
                            }
                        }
                    }

                    $this->assertGreaterThan(0, $dicetak, "{$paket}/{$varian}/{$f['kolom']}: tidak ada sel tercetak di rentangnya");
                }

                $this->assertSame([], array_keys(array_diff_key(array_filter($sel, static fn ($x): bool => $x !== null), $tercakup)), "{$paket}/{$varian}: sel fixture tak tercakup entri peta");
            }
        }

        $this->assertSame([], $beda, 'Entri format_sertifikat peta beda dari number_format sel master: [peta, master]');
    }
}
