<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaketAcuanVersi;
use App\Services\Acuan\SumberAcuan;
use App\Services\Calibration\MicrometerCalculator;
use App\Services\Calibration\Profiles\MicrometerProfile;
use App\Services\Calibration\TabelStandarMicrometer;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Versi 1 hasil `acuan:impor-awal micrometer` memberi `TabelStandarMicrometer`
 * angka yang IDENTIK BIT dengan berkas JSON — bukti setara 07 §3 sebelum
 * sumber DB boleh dinyalakan (rollout tahap 3).
 *
 * Tiap keluaran publik kelas itu diadu antara jalur berkas (di luar lingkup)
 * dan jalur DB (`SumberAcuan::denganVersi(v1, …)`), ditambah `hitungSesi()`
 * keempat workbook master supaya setiap float yang benar-benar sampai ke
 * calculator ikut teradu, bukan cuma yang kebetulan dipanggil test ini.
 *
 * ## Satu beda yang DIKETAHUI dan sengaja tidak diadu: urutan kunci objek
 *
 * Isi versi = JSON berkas yang dikanonikkan, dan `JsonKanonik` mengurutkan
 * kunci objek secara byte. Jadi `balokUkur()`, `konstanta()`, `standar()`, dan
 * tiap pita memulangkan peta yang SAMA isinya tapi urutan kuncinya lain
 * (`"100.0"` sebelum `"11.0"`; `drift_a_um` sebelum `geometri_um`).
 *
 * Diterima karena tidak ada yang bergantung padanya — diperiksa 8 Okt 2026:
 * `nilaiTerkoreksi()`/`ketidakpastianBalok()` mencari kunci yang unik (selisih
 * antar nominal ≥ 0,1 mm, toleransi 1e-9); `TabelStandarDialIndicator::daftarNominal()`
 * mengurutkan ulang; MicrometerCalculator, MicrometerProfile,
 * CalibrationController, dan AuditMicrometerCmc membaca peta lewat kunci.
 * Urutan LIST (tangga, pita, titik kertas, tumpukan keping) tetap diadu ketat:
 * di situ urutan adalah data.
 */
class TabelAcuanSetaraJsonTest extends TestCase
{
    use RefreshDatabase;

    private PaketAcuanVersi $v1;

    private SumberAcuan $sumber;

    protected function setUp(): void
    {
        parent::setUp();

        config(['acuan.sumber' => 'db']);
        SumberAcuan::lupakanCache();

        $organisasi = Organization::factory()->create();

        $this->artisan('acuan:impor-awal', [
            'profil' => (new MicrometerProfile)->kode(),
            '--organisasi' => $organisasi->id,
        ])->assertSuccessful();

        $this->v1 = PaketAcuanVersi::query()->sole();
        $this->sumber = app(SumberAcuan::class);
    }

    /**
     * Jalankan `$aksi` lewat jalur berkas lalu lewat jalur DB.
     *
     * @return array{mixed, mixed}
     */
    private function duaJalur(callable $aksi): array
    {
        $this->assertNull($this->sumber->versiDalamLingkup('micrometer'));

        return [$aksi(), $this->sumber->denganVersi($this->v1, $aksi)];
    }

    public function test_jalur_db_benar_benar_membaca_isi_versi(): void
    {
        $berkas = database_path('data/tabel-standar-micrometer.json');

        $luar = $this->sumber->isi('micrometer', $berkas);
        $dalam = $this->sumber->denganVersi($this->v1, fn () => $this->sumber->isi('micrometer', $berkas));

        // Identik termasuk URUTAN dengan sumbernya masing-masing — bukti bahwa
        // jalur DB tidak diam-diam jatuh ke berkas.
        $this->assertSame(json_decode((string) file_get_contents($berkas), true), $luar);
        $this->assertSame($this->v1->isiTerurai(), $dalam);
        $this->assertSetaraKetat($luar, $dalam);
    }

    public function test_nilai_terkoreksi_setara_untuk_semua_nominal_dan_yang_tidak_ada(): void
    {
        $tabel = new TabelStandarMicrometer;
        $nominal = array_map('floatval', array_keys($tabel->balokUkur()));
        $this->assertCount(32, $nominal);

        // Yang tidak ada di tabel, termasuk tepat di luar toleransi 1e-9.
        array_push($nominal, -1.0, 0.0, 0.5, 1.4, 3.0, 25.0, 300.0, 2.5 + 5e-10, 2.5 + 2e-9, 101.6 - 2e-9);

        foreach ($nominal as $n) {
            [$json, $db] = $this->duaJalur(fn () => $tabel->nilaiTerkoreksi($n));
            $this->assertSetaraKetat($json, $db, "nilaiTerkoreksi({$n})");
        }
    }

    public function test_ketidakpastian_balok_setara_di_seluruh_tangga(): void
    {
        $tabel = new TabelStandarMicrometer;

        $nominal = [
            -5.0, 0.0, 0.5, 9.999999, 10.0, 10.0 + 1e-12, 10.5,
            20.9, 21.0, 21.0 + 1e-12, 21.01, 49.99, 50.0, 50.0 + 1e-12, 50.5,
            76.2, 99.99, 100.0, 100.0 + 1e-12, 100.01,
            101.6, 101.6 + 5e-10, 101.6 + 2e-9, 150.0, 200.0, 200.0 + 5e-10, 200.0 + 2e-9, 250.0,
        ];

        // Sapuan rapat 0..260 mm per 0,1 mm, dibentuk dari bilangan bulat
        // supaya tiap batas tangga ikut tersapu persis.
        for ($i = 0; $i <= 2600; $i++) {
            $nominal[] = $i / 10;
        }

        foreach ($nominal as $n) {
            [$json, $db] = $this->duaJalur(fn () => $tabel->ketidakpastianBalok($n));
            $this->assertSetaraKetat($json, $db, "ketidakpastianBalok({$n})");
        }
    }

    public function test_pita_cmc_setara_di_tiap_batas_dan_di_luar(): void
    {
        $tabel = new TabelStandarMicrometer;

        $kapasitas = [-1.0, 0.0, 1e-9, 0.0001, 12.5, 37.5, 62.5, 87.5, 101.0, 635.0];

        foreach ($tabel->semuaPitaCmc() as $pita) {
            foreach ([$pita['kapasitas_min_mm'], $pita['kapasitas_maks_mm']] as $batas) {
                array_push($kapasitas, $batas - 1e-9, $batas, $batas + 1e-9);
            }
        }

        foreach ($kapasitas as $k) {
            [$json, $db] = $this->duaJalur(fn () => $tabel->pitaCmc($k));
            $this->assertSetaraKetat($json, $db, "pitaCmc({$k})");
        }
    }

    public function test_konstanta_standar_balok_ukur_dan_semua_pita_setara(): void
    {
        $tabel = new TabelStandarMicrometer;

        foreach (['konstanta', 'standar', 'balokUkur', 'semuaPitaCmc'] as $metode) {
            [$json, $db] = $this->duaJalur(fn () => $tabel->{$metode}());
            $this->assertSetaraKetat($json, $db, "{$metode}()");
        }

        // `vi_type_b` int di berkas; tipe itu wajib selamat lewat kanonik.
        [$json, $db] = $this->duaJalur(fn () => $tabel->konstanta()['vi_type_b']);
        $this->assertSame(200, $json);
        $this->assertSame(200, $db);
    }

    /**
     * Keempat sesi master dihitung lewat kedua jalur; seluruh keluaran
     * `hitungSesi()` — tiap komponen budget, uc, veff, k, U95, pita, titik —
     * wajib identik bit.
     */
    public function test_hitung_sesi_keempat_workbook_master_identik(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/micrometer-master.json')),
            true,
        );

        $this->assertCount(4, $fixture['workbook']);

        foreach ($fixture['workbook'] as $kode => $wb) {
            $titik = array_map(static fn (array $t): array => [
                'titik_ke' => $t['titik_ke'],
                'nominal' => $t['nominal_mm'],
                'pembacaan' => $t['pembacaan_mm'],
            ], $wb['titik']);

            $konteks = [
                'kapasitas_mm' => $wb['masukan']['kapasitas_mm'],
                'resolusi_mm' => $wb['masukan']['resolusi_mm'],
                'suhu_ruang_rata_c' => $wb['masukan']['suhu_balok_c'],
                'pra_evaluasi' => $wb['masukan']['pra_evaluasi_mm'],
                'balok_pra_evaluasi' => $wb['masukan']['balok_pra_evaluasi_mm'],
                'tanggal_kalibrasi' => new DateTimeImmutable($wb['masukan']['saat_master_dihitung']),
            ];

            [$json, $db] = $this->duaJalur(fn () => (new MicrometerCalculator)->hitungSesi($titik, $konteks));

            $this->assertNotEmpty($json['budget'], "budget {$kode}");
            $this->assertSetaraKetat($json, $db, "hitungSesi[{$kode}]");
        }
    }

    /**
     * Setara KETAT: tipe sama, float identik bit (`pack('e')`), list sama
     * urutan, peta sama himpunan kunci BESERTA tipe kuncinya (`200` int lawan
     * `"200"`). Urutan kunci peta tidak diadu — alasannya di docblock kelas.
     */
    private function assertSetaraKetat(mixed $json, mixed $db, string $jalur = '$'): void
    {
        $this->assertSame(get_debug_type($json), get_debug_type($db), "{$jalur}: tipe beda");

        if (is_array($json)) {
            $this->assertSame(array_is_list($json), array_is_list($db), "{$jalur}: list lawan peta");

            if (array_is_list($json)) {
                $this->assertCount(count($json), $db, "{$jalur}: panjang list beda");

                foreach ($json as $i => $nilai) {
                    $this->assertSetaraKetat($nilai, $db[$i], "{$jalur}[{$i}]");
                }

                return;
            }

            $this->assertSame(self::kunciBertipe($json), self::kunciBertipe($db), "{$jalur}: kunci peta beda");

            foreach ($json as $kunci => $nilai) {
                $this->assertSetaraKetat($nilai, $db[$kunci], "{$jalur}.{$kunci}");
            }

            return;
        }

        if (is_float($json)) {
            $this->assertSame(
                bin2hex(pack('e', $json)),
                bin2hex(pack('e', $db)),
                "{$jalur}: float tidak identik bit ({$json} lawan {$db})",
            );

            return;
        }

        if (is_object($json)) {
            $this->assertEquals($json, $db, "{$jalur}: objek beda");

            return;
        }

        $this->assertSame($json, $db, "{$jalur}: nilai beda");
    }

    /**
     * @param  array<array-key, mixed>  $peta
     * @return list<string>
     */
    private static function kunciBertipe(array $peta): array
    {
        $kunci = array_map(static fn (int|string $k): string => get_debug_type($k).':'.$k, array_keys($peta));
        sort($kunci, SORT_STRING);

        return $kunci;
    }
}
