<?php

namespace Tests\Unit;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\PistonVolumeProfile;
use App\Services\Calibration\Profiles\TekananProfile;
use App\Support\LogMetodeTekananPiston as LogMetode;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Acuan & catatan perubahan metode enam alat tekanan + piston volume.
 *
 * Tiga berkas yang harus selalu saling cocok, dan tidak satu pun dari
 * ketidakcocokannya menghasilkan error di tempat lain:
 *
 *  - `manifest-workbook-tekanan-piston.json` — workbook master yang DIBEKUKAN
 *    sebagai acuan (sha256);
 *  - `tabel-standar-*.json` — tabel yang dipakai aplikasi, dengan sha256
 *    workbook asalnya di `_sumber` (generator menolak menulis kalau sha256-nya
 *    tidak ada di manifest);
 *  - `log-metode-tekanan-piston.json` — tiap perbedaan aplikasi lawan acuan,
 *    dengan versi rumus yang membawanya.
 *
 * Kalau workbook master diganti tanpa log diperbarui, atau log diperbarui
 * tanpa profil ikut, angka bergeser tanpa ada yang bisa menunjuk kapan dan
 * kenapa — persis yang dilarang ISO/IEC 17025 klausul 7.2.1.5.
 */
class LogMetodeTekananPistonTest extends TestCase
{
    /** @var array<string, list<string>> varian per keluarga, dari manifest */
    private const VARIAN = [
        LogMetode::TEKANAN => ['druck07g', 'druck13g', 'spmk', 'differential'],
        LogMetode::PISTON => ['fixed', 'graduated'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        LogMetode::lupakan();
    }

    /** @return array<string, mixed> */
    private function bacaJson(string $berkas): array
    {
        return json_decode((string) file_get_contents(base_path($berkas)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> varian => sha256 */
    private function manifest(): array
    {
        return array_column($this->bacaJson('database/data/manifest-workbook-tekanan-piston.json')['berkas'], 'sha256', 'varian');
    }

    public function test_manifest_membekukan_enam_workbook_dengan_sha256_utuh(): void
    {
        $m = $this->manifest();

        $this->assertEqualsCanonicalizing([...self::VARIAN['tekanan'], ...self::VARIAN['piston']], array_keys($m));

        foreach ($m as $varian => $sha) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sha, $varian);
        }

        $this->assertCount(6, array_unique($m), 'Dua varian menunjuk workbook yang sama.');
    }

    /**
     * Tabel yang dipakai aplikasi lahir dari workbook yang DIBEKUKAN — bukan
     * dari versi yang kebetulan ada di laptop pembuatnya.
     */
    public function test_tabel_aplikasi_digenerate_dari_workbook_di_manifest(): void
    {
        $m = $this->manifest();

        foreach ([
            'database/data/tabel-standar-tekanan.json' => self::VARIAN['tekanan'],
            'database/data/sesi-master-tekanan.json' => self::VARIAN['tekanan'],
            'database/data/tabel-standar-piston-volume.json' => self::VARIAN['piston'],
            'database/data/sesi-master-piston-volume.json' => self::VARIAN['piston'],
        ] as $berkas => $varian) {
            $sha = $this->bacaJson($berkas)['_sumber']['workbook_sha256'] ?? null;

            $this->assertIsArray($sha, "{$berkas}: `_sumber.workbook_sha256` hilang — generate ulang lewat skripnya.");

            foreach ($varian as $v) {
                $this->assertSame($m[$v], $sha[$v] ?? null, "{$berkas}: workbook `{$v}` bukan versi yang dibekukan.");
            }
        }
    }

    public function test_setiap_versi_di_log_lengkap_dan_menunjuk_acuan_yang_dibekukan(): void
    {
        $log = LogMetode::semua();
        $m = $this->manifest();

        $this->assertSame('database/data/manifest-workbook-tekanan-piston.json', $log['acuan']['manifest']);
        $this->assertNotEmpty($log['acuan']['ditetapkan_oleh']);

        $versi = array_column($log['versi'], 'versi_rumus');
        $this->assertSame($versi, array_values(array_unique($versi)), 'Versi rumus dobel di log.');

        $sebelumnya = [];

        foreach ($log['versi'] as $e) {
            $label = $e['versi_rumus'];

            $this->assertArrayHasKey($e['keluarga'], self::VARIAN, $label);
            $this->assertNotEmpty($e['diputuskan_oleh'], "{$label}: siapa yang memutuskan wajib tertulis.");
            $this->assertNotEmpty($e['bukti'], $label);

            // Log append-only: tanggal berlaku per keluarga tidak boleh mundur.
            $tanggal = Carbon::parse($e['berlaku_sejak']);
            if (isset($sebelumnya[$e['keluarga']])) {
                $this->assertTrue($tanggal->gte($sebelumnya[$e['keluarga']]), "{$label}: berlaku_sejak mundur.");
            }
            $sebelumnya[$e['keluarga']] = $tanggal;

            // Acuannya tepat workbook keluarga itu di manifest — tidak kurang, tidak lebih.
            $harapan = array_map(fn (string $v): string => $m[$v], self::VARIAN[$e['keluarga']]);
            $this->assertEqualsCanonicalizing($harapan, $e['sha256_acuan'], "{$label}: sha256_acuan tidak sama dengan manifest.");

            foreach ($e['perubahan'] as $p) {
                foreach (['kode', 'berkas', 'sel', 'master', 'aplikasi', 'dampak', 'status_tm', 'terpicu_bila'] as $k) {
                    $this->assertNotEmpty($p[$k] ?? null, "{$label} {$p['kode']}: `{$k}` kosong.");
                }

                $this->assertIsBool($p['tahan_terbit'] ?? null, "{$label} {$p['kode']}: `tahan_terbit` wajib true/false.");
            }
        }

        $this->assertNotEmpty($log['keputusan'], 'Riwayat keputusan wajib tertulis.');
        foreach ($log['keputusan'] as $k) {
            $this->assertNotEmpty($k['oleh'] ?? null, "keputusan {$k['no']}: siapa yang memutuskan?");
            $this->assertNotEmpty($k['isi'] ?? null, "keputusan {$k['no']}: isinya?");
        }
    }

    /**
     * Kode yang menahan penerbitan harus bisa BENAR-BENAR dipicu kode program
     * (`TekananProfile::penyimpanganTerpicu()`, `PistonVolumeCalculator::
     * penyimpanganTerpicu()`). Entri `tahan_terbit: true` dengan kode yang
     * tidak pernah dipicu = penahanan yang tertulis tapi tidak pernah menahan.
     */
    public function test_kode_yang_menahan_bisa_dipicu_kode_program(): void
    {
        $bisaDipicu = [
            LogMetode::TEKANAN => ['T-1', 'T-2', 'T-11'],
            LogMetode::PISTON => ['G-2', 'G-7', 'G-8'],
        ];

        foreach ($bisaDipicu as $keluarga => $kode) {
            $menahan = array_column(array_filter(
                LogMetode::entriTerakhir($keluarga)['perubahan'],
                fn (array $p): bool => $p['tahan_terbit'] === true,
            ), 'kode');

            $this->assertSame([], array_values(array_diff($menahan, $kode)), "{$keluarga}: kode menahan yang tidak pernah dipicu.");
        }
    }

    /**
     * Tiap `menunggu P-n`/`V-n` di log wajib ADA sebagai pertanyaan bernomor —
     * kalau tidak, keputusan yang ditunggu tidak pernah sampai ke manajer teknis.
     */
    public function test_pertanyaan_lab_yang_ditunggu_benar_benar_ada(): void
    {
        $dok = [
            'P' => (string) file_get_contents(base_path('docs/pertanyaan-lab-tekanan.md')),
            'V' => (string) file_get_contents(base_path('docs/pertanyaan-lab-piston-volume.md')),
        ];

        foreach (LogMetode::semua()['versi'] as $e) {
            foreach ($e['perubahan'] as $p) {
                preg_match_all('/\b([PV])-(\d+)\b/', $p['status_tm'], $cocok, PREG_SET_ORDER);
                $this->assertNotEmpty($cocok, "{$p['kode']}: status_tm tidak menyebut nomor pertanyaan lab.");

                foreach ($cocok as [$nomor, $huruf]) {
                    $this->assertStringContainsString("| {$nomor} |", $dok[$huruf], "{$p['kode']}: {$nomor} tidak ada di dokumen pertanyaan lab.");
                }
            }
        }
    }

    /** Profil menghitung dengan versi TERAKHIR di log — tidak ada yang tertinggal. */
    public function test_keenam_profil_memakai_versi_terakhir_log(): void
    {
        $dicek = 0;

        foreach ((new CalibrationProfileRegistry)->semua() as $profil) {
            $keluarga = match (true) {
                $profil instanceof TekananProfile => LogMetode::TEKANAN,
                $profil instanceof PistonVolumeProfile => LogMetode::PISTON,
                default => null,
            };

            if ($keluarga === null) {
                $this->assertNull($profil->versiRumus(), $profil::class.' tidak dicatat di log metode ini.');

                continue;
            }

            $this->assertSame(LogMetode::versiTerakhir($keluarga), $profil->versiRumus(), $profil::class);
            $dicek++;
        }

        $this->assertSame(6, $dicek, 'Keenam alat tekanan & piston wajib terdaftar di registry.');
    }
}
