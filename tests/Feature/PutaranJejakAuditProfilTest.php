<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jejak sesi (`type_b_components`) Centrifuge & Tachometer memuat catatan
 * penyimpangan dari master — dan angka cetak/hasil hitungnya TIDAK berubah.
 *
 * Jalur yang diuji sama dengan penyimpanan sesi: `ProfilPutaran::hitungPerGrup`
 * (preview dan simpan memakai fungsi yang sama). Pembanding angka =
 * `tests/Fixtures/putaran-angka-profil-sebelum-jejak-audit.json`, direkam dari
 * kode main b1b1d10e SEBELUM catatan ada:
 *
 *     REKAM_ANGKA_PUTARAN=1 php artisan test --filter=PutaranJejakAuditProfilTest
 *
 * (hanya dijalankan di kode tanpa catatan; jangan untuk menghijaukan tes).
 */
class PutaranJejakAuditProfilTest extends TestCase
{
    use RefreshDatabase;

    private const GOLDEN = 'tests/Fixtures/putaran-angka-profil-sebelum-jejak-audit.json';

    /** @return array<string, array{string, string}> */
    public static function alat(): array
    {
        return [
            'tachometer' => ['tachometer', '0140-CAL-424'],
            'centrifuge' => ['centrifuge', '0133-CAL-324'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function hitung(string $alat, string $nomorSesi): array
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->with('equipment')->firstOrFail();
        $peralatan = $sesi->equipment;
        $this->assertInstanceOf(Equipment::class, $peralatan);

        $standar = Standard::find($sesi->standard_id);
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($peralatan);
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/waktu-frekuensi-master.json')), true, flags: JSON_THROW_ON_ERROR);

        $siap = array_map(static fn (array $t): array => [
            'titik_ke' => $t['titik_ke'],
            'titik_ukur' => $t['set_point'],
            'pembacaan' => $t['pembacaan'],
            'standard' => $standar,
        ], $fixture[$alat]['titik']);

        $hasil = $profil->hitungPerGrup($siap, $peralatan);

        $this->assertNotEmpty($hasil['hitungan'], "{$alat}: tidak ada titik terhitung: ".json_encode($hasil['belum_dihitung'] ?? []));

        return $hasil['hitungan'];
    }

    /**
     * Kolom hitung + jejak LAMA (tanpa baris `putaran_*`), apa adanya.
     *
     * @param  list<array<string, mixed>>  $hitungan
     * @return list<array<string, mixed>>
     */
    private function angka(array $hitungan): array
    {
        return array_map(static function (array $h): array {
            $jejak = array_values(array_filter(
                $h['type_b_components'],
                static fn (array $k): bool => ! str_starts_with((string) $k['sumber'], 'putaran_'),
            ));

            return [
                'titik_ke' => $h['titik_ke'],
                'titik_ukur' => $h['titik_ukur'],
                'rata_rata' => $h['rata_rata'],
                'error' => $h['error'],
                'koreksi' => $h['koreksi'],
                'standar_deviasi' => $h['standar_deviasi'],
                'jumlah_pengulangan' => $h['jumlah_pengulangan'],
                'type_a' => $h['type_a'],
                'type_b' => $h['type_b'],
                'ketidakpastian_gabungan' => $h['ketidakpastian_gabungan'],
                'faktor_cakupan_k' => $h['faktor_cakupan_k'],
                'derajat_kebebasan_efektif' => $h['derajat_kebebasan_efektif'],
                'ketidakpastian_diperluas' => $h['ketidakpastian_diperluas'],
                'toleransi' => $h['toleransi'],
                'keputusan' => $h['keputusan'],
                'metode' => $h['metode'],
                'type_b_components' => $jejak,
            ];
        }, $hitungan);
    }

    /** Angka hasil hitung sesi identik dengan SEBELUM catatan ada (per titik, bit-per-bit). */
    public function test_angka_profil_tidak_berubah_dibanding_sebelum_jejak_audit(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sekarang = [];

        foreach (self::alat() as [$alat, $nomorSesi]) {
            $sekarang[$alat] = $this->angka($this->hitung($alat, $nomorSesi));
        }

        $json = json_encode($sekarang, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        if (getenv('REKAM_ANGKA_PUTARAN') === '1') {
            file_put_contents(base_path(self::GOLDEN), $json."\n");
            $this->markTestSkipped('Mode rekam: golden ditulis ulang.');
        }

        $this->assertSame(
            json_decode((string) file_get_contents(base_path(self::GOLDEN)), true, flags: JSON_THROW_ON_ERROR),
            json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            'Angka hasil hitung profil Putaran bergeser — catatan audit tidak boleh mengubah angka.',
        );
    }

    #[DataProvider('alat')]
    public function test_jejak_sesi_memuat_catatan_drift_dengan_sel_selisih_dan_dasar(string $alat, string $nomorSesi): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ($this->hitung($alat, $nomorSesi) as $h) {
            $catatan = collect($h['type_b_components'])->firstWhere('sumber', 'putaran_drift_standar_kolom_k_tidak_lengkap');

            if ($alat === 'tachometer' && $h['titik_ke'] >= 13 && $h['titik_ke'] <= 15) {
                $this->assertNull($catatan, 'Blok 5 Tachometer punya catatan K54, bukan K35.');

                continue;
            }

            $this->assertNotNull($catatan, "{$alat} titik {$h['titik_ke']}: catatan drift hilang dari jejak.");
            $this->assertStringContainsString('K35', $catatan['keterangan']);
            $this->assertStringContainsString('selisih +0.5', $catatan['keterangan']);
            $this->assertStringContainsString('Dasar:', $catatan['keterangan']);
            $this->assertSame('-', $catatan['distribusi']);
        }
    }

    public function test_centrifuge_blok1_dan_tachometer_blok5_mencatat_kerusakan_masternya(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sentri = $this->hitung('centrifuge', '0133-CAL-324');
        $tacho = $this->hitung('tachometer', '0140-CAL-424');

        $kodeSentri = static fn (int $titikKe): array => array_column(
            collect($sentri)->firstWhere('titik_ke', $titikKe)['type_b_components'], 'sumber',
        );
        $kodeTacho = static fn (int $titikKe): array => array_column(
            collect($tacho)->firstWhere('titik_ke', $titikKe)['type_b_components'], 'sumber',
        );

        // Blok 1 = titik 1-3 (ketiganya membawa catatan yang sama, seperti budget-nya).
        foreach ([1, 2, 3] as $ke) {
            $this->assertContains('putaran_pengulangan_blok1_sel_tunggal_g34', $kodeSentri($ke));
        }
        $this->assertNotContains('putaran_pengulangan_blok1_sel_tunggal_g34', $kodeSentri(4));
        $this->assertNotContains('putaran_pengulangan_blok1_sel_tunggal_g34', $kodeTacho(1), 'G34 hanya milik Centrifuge.');

        foreach ([13, 14, 15] as $ke) {
            $sumber = $kodeTacho($ke);
            $this->assertContains('putaran_pita_sertifikat_blok5_f15', $sumber);
            $this->assertContains('putaran_drift_blok5_tautan_luar_k54', $sumber);
            $this->assertContains('putaran_pengulangan_blok5_baris_kosong', $sumber);
        }

        $this->assertNotContains('putaran_pita_sertifikat_blok5_f15', $kodeSentri(13), 'Blok 5 hanya milik Tachometer.');
    }
}
