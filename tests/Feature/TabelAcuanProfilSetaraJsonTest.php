<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use App\Services\Acuan\SumberAcuan;
use App\Services\Calibration\DialIndicatorCalculator;
use App\Services\Calibration\HeightGaugeCalculator;
use App\Services\Calibration\JangkaSorongCalculator;
use App\Services\Calibration\TabelStandarDialIndicator;
use App\Services\Calibration\TabelStandarHeightGauge;
use App\Services\Calibration\TabelStandarJangkaSorong;
use App\Services\Calibration\TabelStandarVolumetric;
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
