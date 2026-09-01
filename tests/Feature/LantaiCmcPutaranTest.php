<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Services\Calibration\CalibrationProfileRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lantai CMC kelompok Putaran tidak boleh hilang gara-gara satu set point di
 * luar lingkup akreditasi.
 *
 * ## Cacat yang ditutup berkas ini
 *
 * `ProfilPutaran::kemampuanUntukBlok()` memilih pita CMC dari titik TERTINGGI
 * di blok — dan dulu memulangkan `null` kalau tidak ada pita yang memuatnya.
 * `PutaranCalculator` memasang lantainya sebagai
 * `max($u95, (float) ($cmc ?? 0.0))`, jadi `null` berarti **lantainya hilang
 * seluruhnya** — untuk satu blok penuh, termasuk dua titik lain yang justru
 * berada DI DALAM lingkup.
 *
 * Arah salahnya yang bikin mahal: U95 terbit lebih KECIL. Diadu ke sistem yang
 * berjalan sebelum perbaikannya, dengan pembacaan rapat di 60 & 100 rpm:
 *
 *     blok {60, 100, 12000} rpm  ->  U95 = 5,00 rpm   (pita 7000–30000)
 *     blok {60, 100, 40000} rpm  ->  U95 = 4,44 rpm   (TANPA lantai)
 *
 * Mendorong titik ketiganya makin jauh ke luar lingkup justru memperbaiki
 * ketidakpastian yang tercetak. Tidak ada satu pun error yang terbit; yang
 * terbit sertifikat terakreditasi yang mengaku lebih baik daripada CMC yang
 * terdaftar di lampiran KAN LK-285-IDN.
 *
 * Sekaligus menutup peringatan yang isinya tidak benar: teks
 * `centrifuge_di_luar_akreditasi` sudah menjanjikan "lantai CMC yang terpasang
 * diambil dari pita tertinggi yang ada" sementara kodenya tidak memasang lantai
 * apa pun. Peringatan yang isinya salah melatih admin menekan "setujui tetap"
 * tanpa membaca.
 */
class LantaiCmcPutaranTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sesi ter-seed, titik ketiga DI LUAR pita akreditasi teratas alat itu, dan
     * CMC pita teratas yang harus dipinjam sebagai lantai.
     *
     * Centrifuge berhenti di 9000 rpm (CMC 1,6); Tachometer di 30000 rpm
     * (CMC 5,0) — lampiran LK-285-IDN no. 38 & 39.
     *
     * @return array<string, array{string, float, float, float}>
     */
    public static function diLuarPitaTeratas(): array
    {
        return [
            //                  sesi,     di DALAM pita teratas, di LUAR, CMC pita teratas
            'Centrifuge' => ['0133-CAL-324', 5000.0, 12000.0, 1.6],
            'Tachometer' => ['0140-CAL-424', 12000.0, 40000.0, 5.0],
        ];
    }

    #[DataProvider('diLuarPitaTeratas')]
    public function test_lantai_cmc_tetap_terpasang_di_luar_pita(
        string $nomorSesi,
        float $dalamPitaTeratas,
        float $diLuar,
        float $cmcTeratas,
    ): void {
        $this->seed(DatabaseSeeder::class);

        $u95 = $this->u95Blok($nomorSesi, [60.0, 100.0, $diLuar]);

        $this->assertGreaterThanOrEqual(
            $cmcTeratas - 1e-9, $u95,
            sprintf(
                'Blok dengan set point %s (di luar pita teratas) terbit U95 %s — di bawah CMC %s '
                .'yang terdaftar di lampiran akreditasi. Lantai CMC-nya hilang.',
                $diLuar, $u95, $cmcTeratas,
            ),
        );
    }

    /**
     * Yang paling telanjang: menyeberangi batas atas lingkup akreditasi tidak
     * boleh MEMPERBAIKI ketidakpastian yang tercetak.
     *
     * Pasangannya persis pembalikan yang terukur di sistem yang berjalan —
     * Tachometer {60, 100, 12000} rpm terbit 5,00 sementara {60, 100, 40000}
     * rpm terbit 4,44. Satu-satunya bedanya titik ketiga yang keluar lingkup,
     * dan yang keluar lingkup justru dapat angka yang lebih bagus.
     */
    #[DataProvider('diLuarPitaTeratas')]
    public function test_menyeberang_batas_lingkup_tidak_menurunkan_u95(
        string $nomorSesi,
        float $dalamPitaTeratas,
        float $diLuar,
    ): void {
        $this->seed(DatabaseSeeder::class);

        $dalam = $this->u95Blok($nomorSesi, [60.0, 100.0, $dalamPitaTeratas]);
        $luar = $this->u95Blok($nomorSesi, [60.0, 100.0, $diLuar]);
        $jauh = $this->u95Blok($nomorSesi, [60.0, 100.0, $diLuar * 10]);

        $this->assertGreaterThanOrEqual(
            $dalam - 1e-9, $luar,
            sprintf(
                'Set point %s di LUAR lingkup terbit U95 %s — lebih kecil daripada %s yang terbit '
                .'buat set point %s yang masih DI DALAM lingkup.',
                $diLuar, $luar, $dalam, $dalamPitaTeratas,
            ),
        );

        $this->assertGreaterThanOrEqual(
            $luar - 1e-9, $jauh,
            sprintf(
                'Set point %s (sepuluh kali lebih jauh di luar lingkup) menurunkan U95 dari %s ke %s — '
                .'makin jauh dari lingkup akreditasi malah makin bagus sertifikatnya.',
                $diLuar * 10, $luar, $jauh,
            ),
        );
    }

    /**
     * Pita yang dipinjam ikut TERTULIS di jejak audit, bukan cuma angkanya.
     *
     * Tanpa batas pitanya, sengketa setahun lagi tidak bisa membedakan lantai
     * yang memang menaungi titiknya dari lantai pinjaman.
     */
    public function test_jejak_audit_menyebut_pita_yang_dipakai(): void
    {
        $this->seed(DatabaseSeeder::class);

        $hitungan = $this->hitungBlok('0140-CAL-424', [60.0, 100.0, 40000.0]);
        $jejak = collect($hitungan[0]['type_b_components'])
            ->firstWhere('sumber', 'jejak_titik');

        $this->assertNotNull($jejak, 'Jejak audit per titik nggak terbit.');
        $this->assertStringContainsString(
            'pita 7000', (string) $jejak['keterangan'],
            'Jejak audit nggak menyebut pita CMC yang dipakai: '.($jejak['keterangan'] ?? ''),
        );
    }

    /** Blok yang seluruh titiknya DI DALAM lingkup tidak boleh bergeser. */
    public function test_blok_di_dalam_lingkup_tidak_bergeser(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Pita bawah Tachometer 60–7000 rpm, CMC 1,5 — U95 hitungannya di atas
        // itu, jadi lantainya memang tidak menggigit dan angkanya harus persis
        // seperti sebelum perbaikan.
        $this->assertEqualsWithDelta(
            3.169454742851, $this->u95Blok('0140-CAL-424', [60.0, 100.0, 200.0]), 5e-6,
            'Blok yang seluruhnya di dalam lingkup ikut bergeser — perbaikan lantai CMC kesenggol.',
        );
    }

    /**
     * Lantai pinjaman tidak boleh terbit DIAM-DIAM: kedua alat wajib
     * memperingatkan set point di luar pita akreditasinya.
     *
     * Dulu cuma Centrifuge yang punya peringatan ini. Sesi Tachometer di atas
     * 30000 rpm meminjam lantai CMC pita teratas tanpa satu pun peringatan —
     * dan lantai pinjaman yang tidak diumumkan persis yang bikin sertifikat
     * mengaku terakreditasi di titik yang lampirannya tidak mencakup.
     */
    #[DataProvider('diLuarPitaTeratas')]
    public function test_set_point_di_luar_lingkup_diperingatkan(
        string $nomorSesi,
        float $dalamPitaTeratas,
        float $diLuar,
    ): void {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->with('equipment')->firstOrFail();
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

        $sesi->setRelation('uncertaintyCalculations', new EloquentCollection([
            new UncertaintyCalculation(['titik_ke' => 1, 'titik_ukur' => 60.0]),
            new UncertaintyCalculation(['titik_ke' => 2, 'titik_ukur' => $diLuar]),
        ]));

        $peringatan = $profil->peringatanSesi($sesi);

        $this->assertCount(
            1, $peringatan,
            "Set point {$diLuar} di luar lingkup nggak diperingatkan sama sekali.",
        );
        $this->assertSame($profil->kode().'_di_luar_akreditasi', $peringatan[0]['kode']);

        // Dan sesi yang seluruhnya DI DALAM lingkup tidak boleh diperingatkan:
        // peringatan palsu melatih admin menekan "setujui tetap" tanpa membaca.
        $sesi->setRelation('uncertaintyCalculations', new EloquentCollection([
            new UncertaintyCalculation(['titik_ke' => 1, 'titik_ukur' => 60.0]),
            new UncertaintyCalculation(['titik_ke' => 2, 'titik_ukur' => $dalamPitaTeratas]),
        ]));

        $this->assertSame(
            [], $profil->peringatanSesi($sesi),
            "Set point {$dalamPitaTeratas} masih di dalam lingkup tapi diperingatkan.",
        );
    }

    /** @param list<float> $setPoint */
    private function u95Blok(string $nomorSesi, array $setPoint): float
    {
        return (float) $this->hitungBlok($nomorSesi, $setPoint)[0]['ketidakpastian_diperluas'];
    }

    /**
     * Satu blok tiga titik dihitung lewat profilnya langsung.
     *
     * Pembacaannya sengaja RAPAT (persis di set point): lantai CMC cuma
     * menggigit kalau U95 hitungannya di bawahnya, jadi sebaran yang lebar
     * menyembunyikan cacat yang sedang diuji.
     *
     * @param  list<float>  $setPoint
     * @return list<array<string, mixed>>
     */
    private function hitungBlok(string $nomorSesi, array $setPoint): array
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->with('equipment')->firstOrFail();
        $alat = $sesi->equipment;

        $this->assertInstanceOf(Equipment::class, $alat);

        $standar = Standard::find($sesi->standard_id);
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($alat);

        $siap = [];

        foreach ($setPoint as $i => $nilai) {
            $siap[] = [
                'titik_ke' => $i + 1,
                'titik_ukur' => $nilai,
                'pembacaan' => [$nilai, $nilai, $nilai],
                'standard' => $standar,
            ];
        }

        $hasil = $profil->hitungPerGrup($siap, $alat);

        $this->assertNotEmpty(
            $hasil['hitungan'] ?? [],
            'Blok '.json_encode($setPoint).' nggak menghasilkan titik: '
            .json_encode($hasil['belum_dihitung'] ?? []),
        );

        return $hasil['hitungan'];
    }
}
