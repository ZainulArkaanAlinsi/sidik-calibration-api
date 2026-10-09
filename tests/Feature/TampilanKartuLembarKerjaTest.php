<?php

namespace Tests\Feature;

use App\Services\Calibration\CalibrationProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kontrak tampilan kartu di bentuk lembar kerja (revisi lapangan #6, 6 Okt 2026).
 *
 * Kuncinya dibaca HP. `kartu_per_baris` dipakai Anak Timbangan dan dikenal APK
 * lama (kartu bintang, satu kolom); lembar lain memakai `kartu_per_set_point`
 * supaya APK lama jatuh ke tabel biasa — bukan ke kartu yang kehilangan kolom
 * durasi Flowmeter. Kalau nilainya tertukar, tidak ada error di server mana pun.
 */
class TampilanKartuLembarKerjaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, list<string>, bool}> */
    public static function lembar(): array
    {
        return [
            'pressure_gauge' => ['pressure_gauge', ['hasil'], true],
            'vacuum_gauge' => ['vacuum_gauge', ['hasil'], true],
            'utm' => ['utm', ['hasil'], true],
            'load_cell' => ['load_cell', ['hasil'], true],
            'proving_ring' => ['proving_ring', ['hasil'], true],
            'thermohygro' => ['thermohygro', ['hasil_suhu', 'hasil_kelembaban'], true],
            'flowmeter_flowrate' => ['flowmeter_flowrate', ['hasil'], false],
            'flowmeter_totalizer' => ['flowmeter_totalizer', ['hasil'], false],
            // Ikut kertas W1 mekanik (9 Okt 2026): tabel data biasa yang
            // sebelumnya tanpa kartu.
            'dial_indicator' => ['dial_indicator', ['hasil'], false],
            'jangka_sorong' => ['jangka_sorong', ['hasil_outside', 'hasil_inside', 'hasil_depth'], false],
            'sieve' => ['sieve', ['hasil', 'frame'], false],
            'hydrometer' => ['hydrometer', ['hasil'], true],
        ];
    }

    /** @param  list<string>  $kodeBagian */
    #[DataProvider('lembar')]
    public function test_lembar_berkartu_per_set_point(string $profil, array $kodeBagian, bool $sejajar): void
    {
        $bentuk = app(CalibrationProfileRegistry::class)->untukKode($profil)->bentukLembarKerja();
        $bagian = collect($bentuk['bagian'])->keyBy('kode');

        foreach ($kodeBagian as $kode) {
            $this->assertSame('kartu_per_set_point', $bagian[$kode]['tampilan'] ?? null, "{$profil}.{$kode}");
            $this->assertSame($sejajar, $bagian[$kode]['kartu_sejajar'] ?? null, "{$profil}.{$kode}");
            $this->assertFalse($bagian[$kode]['nominal_berbintang'] ?? null, "{$profil}.{$kode}: bintang cuma Anak Timbangan");
        }
    }

    /**
     * Accuracy Timbangan: kartu per titik beban dengan bacaan MENURUN (z, m,
     * m', z') — master menyusun dan menghitungnya per titik (`INPUT DATA!S37`,
     * `PERHITUNGAN FC!B50:K86`).
     */
    public function test_accuracy_timbangan_kartu_vertikal(): void
    {
        $bentuk = app(CalibrationProfileRegistry::class)->untukKode('timbangan')->bentukLembarKerja();
        $akurasi = collect($bentuk['bagian'])->firstWhere('kode', 'akurasi');

        $this->assertSame('kartu_per_set_point', $akurasi['tampilan']);
        $this->assertTrue($akurasi['kartu_vertikal']);
        $this->assertFalse($akurasi['kartu_sejajar']);
        $this->assertFalse($akurasi['nominal_berbintang']);
    }

    public function test_anak_timbangan_tetap_kartu_per_baris_berbintang(): void
    {
        $bentuk = app(CalibrationProfileRegistry::class)->untukKode('anak_timbangan')->bentukLembarKerja();
        $hasil = collect($bentuk['bagian'])->firstWhere('kode', 'hasil');

        $this->assertSame('kartu_per_baris', $hasil['tampilan']);
        $this->assertTrue($hasil['nominal_berbintang']);
    }
}
