<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use App\Services\Acuan\SumberAcuan;
use App\Services\Calibration\DialIndicatorCalculator;
use App\Services\Calibration\HeightGaugeCalculator;
use App\Services\Calibration\JangkaSorongCalculator;
use App\Services\Calibration\PistonVolumeCalculator;
use App\Services\Calibration\TabelKalibratorEnclosure;
use App\Services\Calibration\TabelKalibratorSuhu;
use App\Services\Calibration\TabelStandarDialIndicator;
use App\Services\Calibration\TabelStandarFlowmeter;
use App\Services\Calibration\TabelStandarGaya;
use App\Services\Calibration\TabelStandarHeightGauge;
use App\Services\Calibration\TabelStandarJangkaSorong;
use App\Services\Calibration\TabelStandarPistonVolume;
use App\Services\Calibration\TabelStandarPutaran;
use App\Services\Calibration\TabelStandarTekanan;
use App\Services\Calibration\TabelStandarVolumetric;
use App\Services\Calibration\TabelStandarWaktu;
use App\Services\Calibration\TekananCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\DialIndicatorMasterTest;
use Tests\Unit\HeightGaugeMasterTest;
use Tests\Unit\JangkaSorongMasterTest;

/**
 * Paritas versi 1 untuk profil yang dipindah ke Studio sesudah Micrometer
 * (pola `TabelAcuanSetaraJsonTest`): isi versi 1 hasil `acuan:impor-awal`
 * dibaca lewat lingkup memberi tabel DAN hasil hitung sesi master (fixture
 * `sesi-master-*.json`, masukan yang sama dengan `*MasterTest`) yang identik
 * dengan jalur berkas JSON — pindah ke SumberAcuan tidak menggeser angka.
 */
class TabelAcuanProfilSetaraJsonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['acuan.sumber' => 'db']);
        SumberAcuan::lupakanCache();
    }

    private function v1(string $kode): PaketAcuanVersi
    {
        $org = Organization::factory()->create();
        $this->artisan('acuan:impor-awal', ['profil' => $kode, '--organisasi' => $org->id])->assertSuccessful();

        return PaketAcuanVersi::query()
            ->where('paket_acuan_id', PaketAcuan::query()->where('kode_profil', $kode)->sole()->id)
            ->sole();
    }

    /**
     * @param  callable(): mixed  $aksi
     * @return array{mixed, mixed}
     */
    private function duaJalur(string $kode, PaketAcuanVersi $v1, callable $aksi): array
    {
        $sumber = app(SumberAcuan::class);
        $this->assertNull($sumber->versiDalamLingkup($kode));

        return [$aksi(), $sumber->denganVersi($v1, $aksi)];
    }

    /** Masukan `hitungSesi()` dari `*MasterTest` (metode privat, dipinjam supaya tidak disalin). */
    private static function masukan(string $kelasTest): array
    {
        $t = new $kelasTest('masukan');

        return (fn (): array => $this->masukan())->call($t);
    }

    public function test_dial_indicator_v1_setara_json(): void
    {
        $v1 = $this->v1('dial_indicator');
        $berkas = database_path('data/tabel-standar-dial-indicator.json');

        // Jalur DB benar-benar membaca isi versi (bukan diam-diam berkas).
        $dalam = app(SumberAcuan::class)->denganVersi($v1, fn () => app(SumberAcuan::class)->isi('dial_indicator', $berkas));
        $this->assertEquals($v1->isiTerurai(), $dalam);

        [$a, $b] = $this->duaJalur('dial_indicator', $v1, function (): array {
            $t = new TabelStandarDialIndicator;

            return [$t->standar(), $t->konstanta(), array_map(fn (float $k) => $t->pitaCmc($k), [0.0, 1.0, 25.0, 25.4, 50.0, 100.0, 300.0, 300.1])];
        });
        // Isi versi DB kanonik: urutan kunci objek boleh lain (SumberAcuan::isi), nilai & tipe sama.
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));

        [$titik, $konteks] = self::masukan(DialIndicatorMasterTest::class);
        [$json, $db] = $this->duaJalur('dial_indicator', $v1, fn () => (new DialIndicatorCalculator)->hitungSesi($titik, $konteks));
        $this->assertNotEmpty($json['titik']);
        // Identik bit & tipe; hanya urutan kunci objek yang boleh lain (pita_cmc dari isi kanonik).
        $this->assertSame(self::urutKunci($json), self::urutKunci($db));
    }

    public function test_height_gauge_v1_setara_json(): void
    {
        $v1 = $this->v1('height_gauge');
        $berkas = database_path('data/tabel-standar-height-gauge.json');

        $dalam = app(SumberAcuan::class)->denganVersi($v1, fn () => app(SumberAcuan::class)->isi('height_gauge', $berkas));
        $this->assertEquals($v1->isiTerurai(), $dalam);

        [$a, $b] = $this->duaJalur('height_gauge', $v1, function (): array {
            $t = new TabelStandarHeightGauge;

            return [
                $t->standar(), $t->konstanta(), $t->outside(), $t->inside(), $t->titikPraCetak(),
                $t->ketidakpastianStandarMm(),
                array_map(fn (float $n) => $t->nilaiTerkoreksi($n), [25.0, 250.0, 550.0, 600.0]),
            ];
        });
        // Isi versi DB kanonik: urutan kunci objek boleh lain (SumberAcuan::isi), nilai & tipe sama.
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));

        [$titik, $konteks] = self::masukan(HeightGaugeMasterTest::class);
        [$json, $db] = $this->duaJalur('height_gauge', $v1, fn () => (new HeightGaugeCalculator)->hitungSesi($titik, $konteks));
        $this->assertNotEmpty($json['titik']);
        // Identik bit & tipe; hanya urutan kunci objek yang boleh lain (pita_cmc dari isi kanonik).
        $this->assertSame(self::urutKunci($json), self::urutKunci($db));
    }

    public function test_jangka_sorong_v1_setara_json(): void
    {
        $v1 = $this->v1('jangka_sorong');
        $berkas = database_path('data/tabel-standar-jangka-sorong.json');

        $dalam = app(SumberAcuan::class)->denganVersi($v1, fn () => app(SumberAcuan::class)->isi('jangka_sorong', $berkas));
        $this->assertEquals($v1->isiTerurai(), $dalam);

        [$a, $b] = $this->duaJalur('jangka_sorong', $v1, function (): array {
            $t = new TabelStandarJangkaSorong;

            return [
                $t->standarBalokUkur(), $t->konstanta(), $t->tumpukanDepth(),
                array_map(fn (float $n) => [$t->nilaiKeping($n), $t->u95Keping($n)], [1.0, 2.5, 7.5, 50.0, 3.3]),
                array_map(fn (float $k) => $t->pitaCmc($k), [0.0, 150.0, 300.0, 300.1]),
            ];
        });
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));

        [$titik, $konteks] = self::masukan(JangkaSorongMasterTest::class);
        [$json, $db] = $this->duaJalur('jangka_sorong', $v1, fn () => (new JangkaSorongCalculator)->hitungSesi($titik, $konteks));
        $this->assertNotEmpty($json);
        $this->assertSame(self::urutKunci($json), self::urutKunci($db));
    }

    /**
     * Volumetric: SATU paket (`volumetric`) untuk enam profil. Semua pembaca
     * publik TabelStandarVolumetric, di sapuan masukan, identik antara jalur
     * berkas dan versi 1 DB; dan lingkupnya dibuka dengan kode PAKET.
     */
    public function test_volumetric_v1_setara_json(): void
    {
        $v1 = $this->v1('volumetric');
        $this->assertSame('volumetric', $v1->paketAcuan?->kode_profil);

        [$a, $b] = $this->duaJalur('volumetric', $v1, function (): array {
            $t = new TabelStandarVolumetric;
            $hasil = ['jenis' => $t->jenisAlat(), 'u95' => $t->u95Suhu()];

            foreach ($t->jenisAlat() as $jenis) {
                foreach ([0.4, 1.0, 5.0, 25.0, 50.0, 100.0, 1000.0, 2500.0] as $n) {
                    $hasil['cmc'][$jenis][] = [$t->cmc($jenis, $n), $t->nominalTerindeks($jenis, $n)];
                }
            }

            foreach ([0.0001, 0.0007, 0.02, 1.0, 10.0, 20.0] as $g) {
                $hasil['diameter'][] = $t->diameterMaksimum($g);
            }

            foreach ([-45.0, -40.0, -12.5, 0.0, 20.0, 27.0, 199.0, 850.0] as $c) {
                $hasil['suhu'][] = $t->koreksiSuhu($c);
            }

            foreach (['fixed', 'graduated'] as $k) {
                $hasil['neraca'][$k] = $t->semuaNeraca($k);

                foreach ($t->semuaNeraca($k) as $n) {
                    $hasil['neraca_satu'][$k][] = $t->neraca($k, (string) $n['nama']);
                }
            }

            return $hasil;
        });
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));
    }

    /** Flowmeter Ultrasonic: satu paket untuk Totalizer & Flowrate. */
    public function test_flowmeter_v1_setara_json(): void
    {
        $v1 = $this->v1('flowmeter');

        [$a, $b] = $this->duaJalur('flowmeter', $v1, function (): array {
            $t = new TabelStandarFlowmeter;
            $hasil = ['konstanta' => $t->konstanta(), 'standar' => $t->standar()];

            foreach ([TabelStandarFlowmeter::MODE_TOTALIZER, TabelStandarFlowmeter::MODE_FLOWRATE] as $m) {
                $hasil[$m]['baris'] = $t->baris($m);
                $hasil[$m]['pita'] = $t->semuaPitaCmc($m);
                $hasil[$m]['standar'] = $t->standarUntuk($m);

                foreach ($t->satuanDikenal($m) as $s) {
                    $hasil[$m]['satuan'][$s] = [$t->faktorSatuan($m, $s), $t->satuanBerbasisMassa($m, $s)];
                }

                foreach ([5.0, 50.0, 100.0, 120.0, 236.0, 500.0, 1000.0, 1900.0, 2500.0] as $n) {
                    $hasil[$m]['titik'][] = [$t->cocokTerdekat($m, $n), $t->jarakRelatif($m, $n), $t->dalamJangkauan($m, $n), $t->pitaCmc($m, $n)];
                }
            }

            return $hasil;
        });
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));

        // Urutan satuan (pilihan lembar kerja) tidak bergantung sumber isi.
        foreach ([TabelStandarFlowmeter::MODE_TOTALIZER, TabelStandarFlowmeter::MODE_FLOWRATE] as $m) {
            [$x, $y] = $this->duaJalur('flowmeter', $v1, fn (): array => (new TabelStandarFlowmeter)->satuanDikenal($m));
            $this->assertSame($x, $y, $m);
        }
    }

    /** Gaya: satu paket untuk UTM, Load Cell, Proving Ring. */
    public function test_gaya_v1_setara_json(): void
    {
        $v1 = $this->v1('gaya');

        [$a, $b] = $this->duaJalur('gaya', $v1, function (): array {
            $hasil = [
                'satuan' => TabelStandarGaya::satuanYangDikenal(),
                'standar' => TabelStandarGaya::daftarStandar(),
                'k' => TabelStandarGaya::koefisienSuhu(),
            ];

            foreach (TabelStandarGaya::satuanYangDikenal() as $s) {
                $hasil['faktor'][$s] = TabelStandarGaya::faktorSatuan($s);
            }

            foreach (TabelStandarGaya::daftarStandar() as $k) {
                $hasil['isi'][$k] = TabelStandarGaya::standar($k);

                foreach (['Push', 'Pull'] as $arah) {
                    $hasil['drift'][$k][$arah] = TabelStandarGaya::driftSemuaSumber($k, $arah);

                    foreach ([0.0, 0.3, 4.0, 9.0, 50.0, 88.0, 1500.0, 3100.0] as $kn) {
                        $hasil['koreksi'][$k][$arah][] = TabelStandarGaya::koreksi($kn, $k, $arah);
                    }
                }
            }

            return $hasil;
        });
        // Daftar (urutan tampil) identik apa adanya; isi lain tanpa memandang urutan kunci objek.
        $this->assertSame([$a['satuan'], $a['standar']], [$b['satuan'], $b['standar']]);
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));
    }

    /** Putaran (Centrifuge & Tachometer) dan Timer/Stopwatch. */
    public function test_putaran_dan_waktu_v1_setara_json(): void
    {
        $vP = $this->v1('putaran');
        [$a, $b] = $this->duaJalur('putaran', $vP, function (): array {
            $t = new TabelStandarPutaran;
            $h = ['standar' => $t->standar(), 'sertifikat' => $t->sertifikat(), 'n' => $t->jumlahSnapshotDrift(), 'drift' => [$t->driftSetengahLebar(true), $t->driftSetengahLebar(false)]];

            foreach ([10.0, 60.0, 150.0, 2500.0, 30000.0, 90000.0] as $n) {
                $h['titik'][] = [$t->koreksi($n), $t->u95Sertifikat($n), $t->nominalTerdekat($n)];
            }

            return $h;
        });
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));

        $vW = $this->v1('timer_stopwatch');
        [$a, $b] = $this->duaJalur('timer_stopwatch', $vW, function (): array {
            $t = new TabelStandarWaktu;
            $h = [
                'standar' => $t->standar(), 'sertifikat' => $t->sertifikat(), 'n' => $t->jumlahSnapshotDrift(),
                'drift' => [$t->driftMs(true), $t->driftMs(false)],
                'hr' => [$t->uHumanReactionRata(true), $t->uHumanReactionRata(false), $t->uHumanReactionStdev()],
            ];

            foreach ([3.0, 5.0, 61.0, 600.0, 3600.0, 7200.0] as $n) {
                $h['titik'][] = [$t->koreksiMs($n), $t->u95Sertifikat($n), $t->nominalTerdekat($n)];
            }

            return $h;
        });
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));
    }

    /** Tekanan: satu paket untuk Pressure Gauge, Vacuum Gauge, Differential (empat master). */
    public function test_tekanan_v1_setara_json(): void
    {
        $v1 = $this->v1('tekanan');

        [$a, $b] = $this->duaJalur('tekanan', $v1, function (): array {
            $h = [
                'beda_level' => TabelStandarTekanan::bedaLevel(),
                'g' => TabelStandarTekanan::gravitasiLokal(),
                'resolusi' => [TabelStandarTekanan::pembagiResolusi('digital', null), TabelStandarTekanan::pembagiResolusi('analog', '1/5'), TabelStandarTekanan::pembagiResolusi('analog', null)],
            ];

            foreach (TabelStandarTekanan::VARIAN as $v) {
                $h[$v] = [
                    'satuan_kerja' => TabelStandarTekanan::satuanKerja($v),
                    'satuan' => TabelStandarTekanan::satuanTersedia($v),
                    'faktor' => array_map(static fn (string $s): ?float => TabelStandarTekanan::faktor($v, $s), [...TabelStandarTekanan::satuanTersedia($v), 'mpa', 'tidak_ada']),
                    'standar' => TabelStandarTekanan::standar($v),
                    'drift' => TabelStandarTekanan::drift($v),
                    'media' => TabelStandarTekanan::daftarMedia($v),
                    'rho' => [TabelStandarTekanan::massaJenisMedia($v, 1), TabelStandarTekanan::massaJenisMedia($v, 3)],
                    'cmc' => [TabelStandarTekanan::cmcMaster($v, TabelStandarTekanan::kunciCmcMaster($v, true)), TabelStandarTekanan::cmcMaster($v, TabelStandarTekanan::kunciCmcMaster($v, false))],
                    'varian' => TabelStandarTekanan::varian($v),
                ];

                // Sesi master (masukan `TekananMasterTest`), kedua mode.
                $sesi = json_decode((string) file_get_contents(database_path('data/sesi-master-tekanan.json')), true)['sesi'][$v]['masukan'];

                foreach ([TekananCalculator::MODE_MASTER, TekananCalculator::MODE_BENAR] as $mode) {
                    $h[$v]['hitung'][$mode] = (new TekananCalculator)->hitungSesi($v, $sesi, $mode);
                }
            }

            return $h;
        });
        // Daftar satuan (urutan dropdown) identik apa adanya; isi lain tanpa memandang urutan kunci objek.
        foreach (TabelStandarTekanan::VARIAN as $v) {
            $this->assertSame($a[$v]['satuan'], $b[$v]['satuan'], $v);
        }

        $this->assertSame(self::urutKunci($a), self::urutKunci($b));
    }

    /** Piston Volume: satu paket untuk Piston Pipette, Dispensett, Buret Digital. */
    public function test_piston_volume_v1_setara_json(): void
    {
        $v1 = $this->v1('piston_volume');

        [$a, $b] = $this->duaJalur('piston_volume', $v1, function (): array {
            $t = TabelStandarPistonVolume::class;
            $h = [
                'nama' => $t::namaTimbangan(),
                'indeks' => $t::indeksSuhu(),
                'termometer' => $t::termometer(),
                'u95' => [$t::u95Termometer(), $t::u95Sensor()],
                'konstanta' => $t::konstanta(),
            ];

            foreach ($t::namaTimbangan() as $n) {
                $h['timbangan'][$n] = $t::timbangan($n);
            }

            foreach ([-20.0, 0.0, 21.4, 25.0, 50.0, 199.0] as $suhu) {
                $i = $t::indeksSuhuTerdekat($suhu);
                $h['suhu'][] = [$i, $t::koreksiMeter($i), $t::koreksiSensor($i)];
            }

            foreach (['buret_digital', 'piston_pipette', 'dispensett'] as $jenis) {
                $h['cmc_maks'][$jenis] = $t::kapasitasMaksCmc($jenis);

                foreach ([0.5, 1.0, 5.0, 10.0, 50.0, 100.0] as $ml) {
                    $h['cmc'][$jenis][] = $t::cmc($jenis, $ml);
                }
            }

            foreach ([['piston_pipette', null], ['buret_digital', 'hand_driven'], ['buret_digital', 'motor_driven'], ['dispensett', 'single_stroke'], ['dispensett', 'multi_stroke']] as [$jenis, $sub]) {
                foreach ([0.001, 0.01, 1.0, 10.0, 100.0] as $ml) {
                    $h['mpe'][] = $t::mpe($jenis, $sub, $ml);
                }
            }

            // Sesi master (masukan `PistonVolumeMasterTest`), kedua mode.
            foreach (json_decode((string) file_get_contents(database_path('data/sesi-master-piston-volume.json')), true)['sesi'] as $kel => $s) {
                foreach ([PistonVolumeCalculator::MODE_MASTER, PistonVolumeCalculator::MODE_BENAR] as $mode) {
                    $h['hitung'][$kel][$mode] = (new PistonVolumeCalculator)->hitungSesi($s['masukan'], $mode);
                }
            }

            return $h;
        });
        $this->assertSame([$a['nama'], $a['indeks']], [$b['nama'], $b['indeks']]);
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));
    }

    /** Kalibrator suhu (TITS Measure & Source), dibaca juga TIDS/Enclosure/3 alat suhu. */
    public function test_kalibrator_suhu_v1_setara_json(): void
    {
        $v1 = $this->v1('kalibrator_suhu');

        [$a, $b] = $this->duaJalur('kalibrator_suhu', $v1, function (): array {
            $t = new TabelKalibratorSuhu;
            $h = [];

            foreach ([TabelKalibratorSuhu::MODE_MEASURE, TabelKalibratorSuhu::MODE_SOURCE] as $mode) {
                foreach (TabelKalibratorSuhu::MERK as $merk) {
                    $h[$mode][$merk]['tipe'] = $t->tipeSensorTersedia($mode, $merk);

                    foreach (['RTD', 'Type K', 'Type N', 'Type B', 'Type T', 'Type R', 'Type S', 'Type J'] as $tipe) {
                        $h[$mode][$merk][$tipe] = ['drift' => $t->drift($mode, $merk, $tipe), 'u95_maks' => $t->u95Maksimum($mode, $merk, $tipe)];

                        foreach ([-100.0, -20.0, 1.0, 37.5, 250.0, 1100.0, 1500.0] as $titik) {
                            $h[$mode][$merk][$tipe]['titik'][] = [$t->koreksi($mode, $merk, $tipe, $titik), $t->u95($mode, $merk, $tipe, $titik)];
                        }
                    }
                }
            }

            return $h;
        });
        // Urutan tipe (dropdown) identik apa adanya; isi lain tanpa memandang urutan kunci objek.
        $this->assertSame(array_map(static fn (array $m): array => array_column($m, 'tipe'), $a), array_map(static fn (array $m): array => array_column($m, 'tipe'), $b));
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));
    }

    /** Enclosure (Oven, Furnace, Bath, Inkubator, Refrigerator). */
    public function test_enclosure_v1_setara_json(): void
    {
        $v1 = $this->v1('enclosure');

        [$a, $b] = $this->duaJalur('enclosure', $v1, function (): array {
            $t = new TabelKalibratorEnclosure;
            $h = [];

            foreach (['constant', 'yokogawa', 'recorder'] as $merk) {
                foreach ([-30.0, 0.0, 37.5, 75.0, 151.0, 1100.0] as $rata) {
                    $h[$merk]['index'][] = $t->index($merk, $rata);
                }

                foreach (['PT100', 'Type N', 'Type K'] as $tipe) {
                    $h[$merk][$tipe] = ['drift_meter' => $t->driftMeter($merk, $tipe), 'drift_sensor' => $t->driftSensor($merk, $tipe)];

                    foreach ([-20.0, 0.0, 25.0, 75.0, 200.0, 1200.0] as $idx) {
                        foreach ($merk === 'recorder' ? [1, 7, 20] : [null] as $kanal) {
                            $h[$merk][$tipe]['meter'][] = [$t->koreksiMeter($merk, $tipe, $idx, $kanal), $t->u95Meter($merk, $tipe, $idx, $kanal)];
                        }

                        foreach ([1, 3, 12, 16] as $no) {
                            $h[$merk][$tipe]['sensor'][] = [$t->koreksiSensor($merk, $tipe, $no, $idx), $t->u95Sensor($merk, $tipe, $no, $idx)];
                        }
                    }
                }
            }

            return $h;
        });
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));
    }

    /** Urutkan kunci objek rekursif; urutan list dipertahankan. */
    private static function urutKunci(mixed $x): mixed
    {
        if (! is_array($x)) {
            return $x;
        }

        $x = array_map(self::urutKunci(...), $x);

        if (! array_is_list($x)) {
            ksort($x);
        }

        return $x;
    }
}
