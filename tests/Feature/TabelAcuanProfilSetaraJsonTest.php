<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use App\Services\Acuan\SumberAcuan;
use App\Services\Calibration\DialIndicatorCalculator;
use App\Services\Calibration\HeightGaugeCalculator;
use App\Services\Calibration\JangkaSorongCalculator;
use App\Services\Calibration\PistonVolumeCalculator;
use App\Services\Calibration\Profiles\ChlorineProfile;
use App\Services\Calibration\Profiles\DoMeterProfile;
use App\Services\Calibration\Profiles\RefractometerProfile;
use App\Services\Calibration\TabelKalibratorEnclosure;
use App\Services\Calibration\TabelKalibratorSuhu;
use App\Services\Calibration\TabelKalibratorSuhu3Alat;
use App\Services\Calibration\TabelStandarAnakTimbangan;
use App\Services\Calibration\TabelStandarDialIndicator;
use App\Services\Calibration\TabelStandarFlowmeter;
use App\Services\Calibration\TabelStandarGaya;
use App\Services\Calibration\TabelStandarHeightGauge;
use App\Services\Calibration\TabelStandarJangkaSorong;
use App\Services\Calibration\TabelStandarPistonVolume;
use App\Services\Calibration\TabelStandarPutaran;
use App\Services\Calibration\TabelStandarSieve;
use App\Services\Calibration\TabelStandarTekanan;
use App\Services\Calibration\TabelStandarTids;
use App\Services\Calibration\TabelStandarTimbangan;
use App\Services\Calibration\TabelStandarVolumetric;
use App\Services\Calibration\TabelStandarWaktu;
use App\Services\Calibration\TekananCalculator;
use App\Services\Calibration\ThermocoupleCalculator;
use App\Services\Calibration\ThermohygroCalculator;
use App\Services\Calibration\ThermometerGlassCalculator;
use App\Services\Calibration\TimbanganCalculator;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;
use Tests\Unit\AnakTimbanganMasterTest;
use Tests\Unit\DialIndicatorMasterTest;
use Tests\Unit\HeightGaugeMasterTest;
use Tests\Unit\JangkaSorongMasterTest;
use Tests\Unit\Suhu3AlatMasterTest;

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

    /** TIDS (Yokogawa K N & Recorder Graptech). */
    public function test_tids_v1_setara_json(): void
    {
        $v1 = $this->v1('tids');

        [$a, $b] = $this->duaJalur('tids', $v1, function (): array {
            $t = new TabelStandarTids;
            $h = ['cmc' => $t->cmcMaster(), 'dryblock' => [$t->dryblock('A'), $t->dryblock('B'), $t->dryblock('C')]];

            foreach (TabelStandarTids::KELUARGA as $kel) {
                $h[$kel]['indeks'] = $t->indeks($kel);

                foreach ([-30.0, 12.0, 37.5, 160.0, 650.0] as $n) {
                    $h[$kel]['terdekat'][] = $t->indeksTerdekat($kel, $n);
                }

                foreach (['RTD', 'Type N', 'Type K', 'Type J'] as $tipe) {
                    $h[$kel][$tipe] = ['drift_meter' => $t->driftMeter($kel, $tipe), 'drift_sensor' => $t->driftSensor($kel, $tipe), 'nomor' => $t->nomorSensorTersedia($kel, $tipe)];

                    foreach ([-20.0, 0.0, 25.0, 150.0, 400.0] as $idx) {
                        foreach ($kel === TabelStandarTids::KELUARGA_BERKANAL ? [1, 9, 20] : [null] as $kanal) {
                            $h[$kel][$tipe]['meter'][] = [$t->koreksiMeter($kel, $tipe, $idx, $kanal), $t->u95Meter($kel, $tipe, $idx, $kanal)];
                        }

                        foreach ([1, 3, 12, 16, 17] as $no) {
                            $h[$kel][$tipe]['sensor'][] = [$t->koreksiSensor($kel, $tipe, $no, $idx), $t->u95Sensor($kel, $tipe, $no, $idx), $t->kanalRecorder($tipe, $no)];
                        }
                    }
                }
            }

            return $h;
        });
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));
    }

    /** Sieve: `tanda` kini diturunkan kode — hasilnya wajib sama dengan yang dulu disimpan generator. */
    public function test_sieve_v1_setara_json_dan_tanda_diturunkan(): void
    {
        $v1 = $this->v1('sieve');

        [$a, $b] = $this->duaJalur('sieve', $v1, function (): array {
            $t = new TabelStandarSieve;
            $h = ['mpe' => $t->semuaMpe(), 'konstanta' => $t->konstanta(), 'standar' => [$t->standar('caliper'), $t->standar('mikroskop')]];

            foreach ([[19.0, 'mm'], [80.0, 'µm'], [1.0, 'mm'], [0.394, 'inch'], [0.279, 'inch'], [7.0, 'mm']] as [$n, $s]) {
                $baris = $t->barisMpe($n, $s);
                $h['baris'][] = [$baris, $baris === null ? null : $t->kolomJanggalTerpakai($baris, $s)];
            }

            foreach ([0.05, 1.0, 2.0, 3.0, 50.0, 120.0] as $mm) {
                $h['lantai'][] = [$t->lantaiCmc($mm, 'mikroskop'), $t->lantaiCmc($mm, 'caliper')];
            }

            foreach ([['caliper', 'warp'], ['mikroskop', 'warp'], ['mikroskop', 'weft'], ['mikroskop', 'kawat']] as [$std, $par]) {
                $h['koreksi'][] = [$t->titikKoreksi($std, $par, 0.5), $t->titikKoreksi($std, $par, 5.0), $t->u95Sertifikat($std, $par)];
            }

            return $h;
        });
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));

        // Tanda turunan = tanda yang dulu disimpan generator (sebelum 22 Okt 2026).
        $tanda = [];

        foreach ($a['mpe'] as $baris) {
            if ($baris['tanda'] !== []) {
                $tanda[(string) $baris['ukuran_mm']] = $baris['tanda'];
            }
        }

        $this->assertSame(['10' => ['ukuran_inch'], '1' => ['ukuran_um'], '0.08' => ['kawat_preferred_mm']], $tanda);
    }

    /**
     * Chlorine Meter & DO Meter: U95 micropipette & labu ukur pindah dari
     * konstanta kode ke data acuan. Uc pengenceran lewat DB == lewat berkas ==
     * rumus konstanta lama (angka tidak berubah).
     */
    public function test_chlorine_dan_do_meter_v1_setara_json_dan_konstanta_lama(): void
    {
        $lama = [sqrt((0.00089 / 2) ** 2 + (0.033 / 2) ** 2) / 1000, sqrt((0.89 / 2 / 1000) ** 2 + (0.033 / 2) ** 2)];
        $this->assertSame($lama, [ChlorineProfile::ucPengenceran(), DoMeterProfile::ucPengenceran()]);

        $vC = $this->v1('chlorine_meter');
        [$a, $b] = $this->duaJalur('chlorine_meter', $vC, fn (): float => ChlorineProfile::ucPengenceran());
        $this->assertSame($lama[0], $b);
        $this->assertSame($a, $b);

        $vD = $this->v1('do_meter');
        [$a, $b] = $this->duaJalur('do_meter', $vD, fn (): float => DoMeterProfile::ucPengenceran());
        $this->assertSame($lama[1], $b);
        $this->assertSame($a, $b);
    }

    /**
     * Refractometer: koefisien & batas `Tab Konversi Temperatur` pindah dari
     * konstanta kode (`KOEF_SUHU` n20D 0.00045 / °Brix 0.07, `SUHU_TABEL_MIN`
     * 19.0, `SUHU_TABEL_MAKS` 30.2) ke data acuan. Deviasi budget & normalisasi
     * 20 °C lewat berkas == lewat DB v1 == rumus konstanta lama, bit demi bit,
     * termasuk clamp di kedua ujung tabel.
     */
    public function test_refractometer_v1_setara_json_dan_konstanta_lama(): void
    {
        $koef = ['n20D' => 0.00045, '°Brix' => 0.07];
        $suhu = [10.0, 18.99, 19.0, 21.05, 21.1, 25.0, 27.0, 30.19, 30.2, 30.25, 45.0];
        $profil = new RefractometerProfile;
        $lama = [];

        foreach ($koef as $satuan => $k) {
            foreach ($suhu as $t) {
                $tt = floor(round(max(19.0, min(30.2, $t)) * 10, 6)) / 10;
                $lama[] = -$k * ($tt - 20.0);
                $lama[] = 1.3362 + $k * ($t - 20.0);
            }
        }

        $hitung = function () use ($koef, $suhu, $profil): array {
            $hasil = [];

            foreach (array_keys($koef) as $satuan) {
                $alat = new Equipment(['satuan' => $satuan]);

                foreach ($suhu as $t) {
                    $hasil[] = $profil->deviasiSuhu($t, $satuan);
                    $hasil[] = $profil->rataRataPadaSuhuAcuan(1.3362, $t, $alat);
                }
            }

            return $hasil;
        };

        $this->assertSame($lama, $hitung());
        [$a, $b] = $this->duaJalur('refractometer', $this->v1('refractometer'), $hitung);
        $this->assertSame($lama, $b);
        $this->assertSame($a, $b);
    }

    /** Anak Timbangan (workbook F2 IMTE-LQ-197): tabel + hitung sesi master (fixture AnakTimbanganMasterTest). */
    public function test_anak_timbangan_v1_setara_json(): void
    {
        $v1 = $this->v1('anak_timbangan');
        $t = new AnakTimbanganMasterTest('hitung');
        Closure::bind(static function (): void {
            self::$fixture ??= json_decode((string) file_get_contents(base_path('tests/Fixtures/anak-timbangan-master.json')), true);
        }, null, AnakTimbanganMasterTest::class)();

        [$a, $b] = $this->duaJalur('anak_timbangan', $v1, function () use ($t): array {
            $h = [
                'keping' => TabelStandarAnakTimbangan::semuaKeping(), 'kembar' => TabelStandarAnakTimbangan::kepingKembar(),
                'set' => TabelStandarAnakTimbangan::setStandar(), 'neraca' => TabelStandarAnakTimbangan::semuaTimbangan(),
                'konstanta' => TabelStandarAnakTimbangan::konstanta(), 'sengketa' => TabelStandarAnakTimbangan::densitasDisengketakan(),
                'meter' => TabelStandarAnakTimbangan::meterLingkungan('Thermobarometer'),
            ];

            foreach ([0.001, 0.002, 0.1, 0.5, 2.0, 100.0, 500.0, 1000.0, 10000.0, 20000.0] as $n) {
                $h['cari'][] = TabelStandarAnakTimbangan::cariKeping($n);

                foreach (['E2', 'F1', 'F2', 'M1', 'M2', 'M3'] as $kelas) {
                    $h['dm'][] = [TabelStandarAnakTimbangan::densitas($n, $kelas), TabelStandarAnakTimbangan::mpe($n, $kelas)];
                }

                $h['neraca_terbaik'][] = TabelStandarAnakTimbangan::timbanganTerbaikUntuk($n);
            }

            foreach ([['suhu', 22.4], ['kelembaban', 61.0], ['tekanan', 955.0]] as [$besaran, $nilai]) {
                $h['indeks'][] = TabelStandarAnakTimbangan::titikIndeks('Thermobarometer', $besaran, $nilai);
            }

            $h['hitung'] = (fn (): array => $this->hitung())->call($t);

            return $h;
        });
        $this->assertNotEmpty($a['hitung']['titik']);
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));
    }

    /** Suhu 3 alat (Thermocouple, Thermometer Glass, Thermohygro): tabel + sesi master ketiganya. */
    public function test_suhu_3alat_v1_setara_json(): void
    {
        $v1 = $this->v1('suhu_3alat');
        $c = new ReflectionClass(Suhu3AlatMasterTest::class);

        [$a, $b] = $this->duaJalur('suhu_3alat', $v1, function () use ($c): array {
            $t = new TabelKalibratorSuhu3Alat;
            $h = ['th' => $t->thermohygro(), 'tipe' => $t->tipeThermometer(), 'dry' => [$t->dryblock('A'), $t->dryblock('B')], 'oil' => [$t->oilbath('satu'), $t->oilbath('dua')]];

            foreach ([TabelKalibratorSuhu3Alat::ALAT_THERMOCOUPLE, TabelKalibratorSuhu3Alat::ALAT_GLASS] as $alat) {
                $h[$alat]['cmc'] = $t->cmcMaster($alat);

                foreach ([-100.0, -20.0, 0.0, 25.0, 148.6, 150.1, 600.0, 1700.0] as $n) {
                    $i = (float) $t->indeksTerdekat($alat, $n);
                    $h[$alat]['i'][] = $i;

                    foreach (TabelKalibratorSuhu3Alat::MERK as $m) {
                        foreach (['RTD', 'Type K', 'Type N', 'Type B', 'Type T', 'Type R', 'Type S', 'Type J'] as $tipe) {
                            $h[$alat]['k'][] = [$t->koreksiKalibrator($alat, $m, $tipe, $i), $t->u95Kalibrator($alat, $m, $tipe, $i), $t->driftKalibrator($alat, $m, $tipe)];
                        }
                    }

                    foreach (['RTD', 'TCK-01', 'TCK-14', 'TCN3', 'TCN12'] as $p) {
                        $h[$alat]['s'][] = [$t->koreksiSensor($alat, $p, $i), $t->u95Sensor($alat, $p, $i)];
                    }
                }

                foreach (['RTD', 'Type K', 'Type N'] as $tipe) {
                    $h[$alat]['ds'][] = $t->driftSensor($alat, $tipe);
                    $h[$alat]['probe'][] = [$t->nomorProbeTersedia($alat, $tipe), $t->probe($alat, $tipe, 3), $t->probe($alat, $tipe, 17)];
                }
            }

            foreach ([15.0, 33.0, 50.0] as $n) {
                $h['koreksi_th'][] = [$t->koreksiThermohygro('suhu', $n), $t->koreksiThermohygro('kelembaban', $n)];
            }

            $h['hitung'] = [
                (new ThermocoupleCalculator)->hitungSesi($c->getConstant('TITIK_THERMOCOUPLE'), $c->getConstant('SPEK_THERMOCOUPLE')),
                (new ThermometerGlassCalculator)->hitungSesi($c->getConstant('TITIK_GELAS'), $c->getConstant('SPEK_GELAS')),
                (new ThermohygroCalculator)->hitungSesi($c->getConstant('TITIK_THERMOHYGRO'), $c->getConstant('SPEK_THERMOHYGRO')),
            ];

            return $h;
        });
        $this->assertNotEmpty($a['hitung'][0]['titik']);
        $this->assertSame(self::urutKunci($a), self::urutKunci($b));
    }

    /** Timbangan (master gram, kg, substitusi): tabel + hitung sesi master ketiga varian. */
    public function test_timbangan_v1_setara_json(): void
    {
        $v1 = $this->v1('timbangan');

        [$a, $b] = $this->duaJalur('timbangan', $v1, function (): array {
            $h = ['cmc' => TabelStandarTimbangan::semuaPitaCmc()];

            foreach (TabelStandarTimbangan::varianTersedia() as $v) {
                $h[$v]['basis'] = TabelStandarTimbangan::basis($v);
                $h[$v]['semua'] = TabelStandarTimbangan::semuaMassa($v);

                foreach ([0.0001, 0.002, 0.2, 1.0, 2.0, 20.0, 200.0, 20000.0] as $n) {
                    foreach ([TabelStandarTimbangan::ANALYTICAL, TabelStandarTimbangan::NON_ANALYTICAL] as $tipe) {
                        $h[$v]['cari'][] = TabelStandarTimbangan::cariMassa($n, $v, $tipe);
                    }
                }
            }

            foreach ([0.1, 0.2, 1.2005, 15.0, 2000.0, 2000.0005, 2500.0] as $kg) {
                $h['pita'][] = TabelStandarTimbangan::pitaCmc($kg);
                $h['drift'][] = TabelStandarTimbangan::driftKeluarga($kg);
            }

            // Sesi master (masukan `TimbanganMasterTest`), ketiga varian.
            foreach (json_decode((string) file_get_contents(database_path('data/sesi-master-timbangan.json')), true) as $tag => $sesi) {
                if (is_array($sesi) && ! str_starts_with((string) $tag, '_')) {
                    unset($sesi['_harap'], $sesi['_budget']);
                    $h['hitung'][$tag] = (new TimbanganCalculator)->hitung($sesi);
                }
            }

            return $h;
        });
        $this->assertNotEmpty($a['hitung']);
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
