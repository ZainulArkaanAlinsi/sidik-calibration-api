<?php

namespace Tests\Feature;

use App\Support\LogMetodeTekananPiston as LogMetode;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `kalibrasi:hitung-mentah` — mesin hitung aplikasi yang dipanggil harness
 * percobaan paralel (`docs/skrip/uji-paralel-tekanan-piston.py`).
 *
 * Harness itu cuma sekuat perintah ini: kalau perintahnya memakai jalur hitung
 * yang BEDA dari profil (atau lupa CMC), "cocok" di laporan uji paralel tidak
 * membuktikan apa-apa tentang angka yang tersimpan. Jadi keluarannya diadu ke
 * angka yang sama dengan yang dipakai `TekananMasterTest`/`PistonVolumeMasterTest`.
 */
class PerintahHitungMentahTest extends TestCase
{
    private const TOL = 1e-12;

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function sesiMaster(): array
    {
        return [
            'DRUCK07G' => [LogMetode::TEKANAN, 'sesi-master-tekanan', 'druck07g'],
            'DRUCK13G vakum' => [LogMetode::TEKANAN, 'sesi-master-tekanan', 'druck13g'],
            'SPMK' => [LogMetode::TEKANAN, 'sesi-master-tekanan', 'spmk'],
            'Differential' => [LogMetode::TEKANAN, 'sesi-master-tekanan', 'differential'],
            'Fixed' => [LogMetode::PISTON, 'sesi-master-piston-volume', 'fixed'],
            'Graduated' => [LogMetode::PISTON, 'sesi-master-piston-volume', 'graduated'],
        ];
    }

    /**
     * @param  array<string, mixed>  $masukan
     * @param  array<string, mixed>  $opsi
     */
    private function jalankan(array $masukan, array $opsi): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hitung-mentah');
        file_put_contents($tmp, json_encode($masukan));

        try {
            return Artisan::call('kalibrasi:hitung-mentah', [...$opsi, '--berkas' => $tmp]);
        } finally {
            unlink($tmp);
        }
    }

    private function sama(float $harapan, mixed $nyata, string $label): void
    {
        $this->assertIsNumeric($nyata, $label);
        $this->assertLessThanOrEqual(self::TOL * max(1.0, abs($harapan)), abs($harapan - (float) $nyata), $label);
    }

    #[DataProvider('sesiMaster')]
    public function test_keluaran_dua_mode_sama_dengan_master(string $keluarga, string $berkas, string $varian): void
    {
        $sesi = json_decode((string) file_get_contents(database_path("data/{$berkas}.json")), true)['sesi'][$varian];

        $kode = $this->jalankan($sesi['masukan'], [
            'keluarga' => $keluarga,
            ...($keluarga === LogMetode::TEKANAN ? ['--varian' => $varian] : []),
        ]);

        // `Artisan::output()` MENGOSONGKAN buffernya — dibaca sekali saja.
        $keluaran = Artisan::output();
        $this->assertSame(0, $kode, $keluaran);
        $o = json_decode($keluaran, true, flags: JSON_THROW_ON_ERROR);

        foreach (['master' => 'harapan_master_excel', 'benar' => 'harapan_benar'] as $mode => $kunci) {
            foreach (['uc', 'veff', 'k', 'U', 'cmc', 'u95'] as $k) {
                $this->sama((float) $sesi[$kunci][$k], $o[$mode][$k], "{$mode} {$k}");
            }

            foreach ($sesi[$kunci]['komponen'] as $i => $komp) {
                foreach (['U', 'pembagi', 'vi', 'ci', 'u'] as $k) {
                    $this->sama((float) $komp[$k], $o[$mode]['komponen'][$i][$k], "{$mode} komponen {$i} {$k}");
                }
            }
        }

        $this->assertSame(LogMetode::versiTerakhir($keluarga), $o['versi_rumus']);
        $this->assertStringStartsWith($keluarga === LogMetode::TEKANAN ? 'TEKANAN-' : 'PISTON-VOLUME-', $o['kode_formula']);
    }

    public function test_varian_tekanan_wajib_dan_keluarga_asing_ditolak(): void
    {
        $this->assertSame(1, $this->jalankan(['titik' => []], ['keluarga' => LogMetode::TEKANAN]));
        $this->assertStringContainsString('--varian', Artisan::output());

        $this->assertSame(1, $this->jalankan(['titik' => []], ['keluarga' => 'suhu']));
        $this->assertStringContainsString('tekanan', Artisan::output());
    }
}
