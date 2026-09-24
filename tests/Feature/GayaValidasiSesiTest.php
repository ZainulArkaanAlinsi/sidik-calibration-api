<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Organization;
use App\Services\Calibration\Profiles\LoadCellProfile;
use App\Support\GayaMentah as M;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aturan tingkat-SESI dari panduan §8.1 & §8.2.
 *
 * ## Dua jenis yang tidak boleh tertukar
 *
 * **Pemblokir** (§8.1) menahan SELURUH sesi: misalignment kurang dari empat,
 * suhu ruangan di luar 10–35 °C. Keduanya merusak angka yang keluar, bukan
 * sekadar mencurigakan — misalignment yang cuma terisi dua menerbitkan U95
 * dari sebaran yang tidak pernah diukur penuh.
 *
 * **Peringatan** (§8.2) terlihat tapi tidak menahan. Yang paling menentukan di
 * berkas ini ada di `test_urutan_titik_tidak_naik_cuma_peringatan`: panduan
 * menaruh "nominal harus naik monoton" di daftar PEMBLOKIR, dan itu tidak bisa
 * diikuti. Sesi master Load Cell sendiri melanggarnya — urutannya
 * `0, 100, 2, 3, … 9 kN` — jadi menjadikannya pemblokir berarti lembar yang
 * benar-benar dipakai lab tidak bisa dikirim.
 *
 * Antara panduan dan master, yang menang master (AGENTS.md §Aturan yang Lahir
 * dari Kesalahan Nyata). Pertentangannya diangkat sebagai pertanyaan lab G13,
 * bukan diputuskan diam-diam di kode.
 *
 * ## Kenapa peringatan diulang di tiap baris
 *
 * `temuan_sesi` sengaja disalin ke jejak audit SETIAP titik. Layar persetujuan
 * membaca jejak per titik; ditaruh sekali di satu baris saja, sembilan titik
 * lain tidak memperlihatkannya dan orang yang membuka titik ke-3 mengira
 * sesinya bersih.
 */
class GayaValidasiSesiTest extends TestCase
{
    use RefreshDatabase;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        $this->alat = Equipment::factory()->create([
            'nama_alat' => 'Load Cell',
            'nama_alat_kemampuan' => (new LoadCellProfile)->namaAlatKemampuan(),
            'satuan' => 'kN',
            'range_min' => 0,
            'range_max' => 100,
            'resolusi' => 0.01,
        ]);
    }

    /**
     * Blok sesi yang SAH, untuk dirusak satu bagian per test.
     *
     * @param  array<string, mixed>  $ganti
     * @return array<string, mixed>
     */
    private function blok(array $ganti = []): array
    {
        return [M::KUNCI_SESI => [
            'satuan' => 'kN',
            'tipe_beban' => M::ARAH_PULL,
            'standar' => '100kN',
            'suhu_sertifikat_standar' => 23.45,
            'kapasitas' => 100.0,
            'resolusi_uut' => 0.01,
            'resolusi_standar' => 0.001,
            'kapasitas_standar' => 100.0,
            'preload_zero' => [0.0, 0.0, 0.0],
            'preload_max' => [68.86, 70.89, 71.86],
            'misalignment' => [8.237, 8.234, 8.237, 8.238],
            ...$ganti,
        ]];
    }

    /**
     * Dua belas bacaan — sembilan `a`, tiga `b`, persis pola sesi master.
     *
     * @return list<float>
     */
    private function bacaan(float $a, float $b): array
    {
        return [...array_fill(0, 9, $a), ...array_fill(0, 3, $b)];
    }

    /**
     * @param  list<array{0: float, 1: float, 2: float}>  $titik  [nominal, a, b]
     * @param  array<string, mixed>  $blokGanti
     * @param  array<string, mixed>  $lingkungan
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array<string, mixed>>}
     */
    private function hitung(
        array $titik,
        array $blokGanti = [],
        array $lingkungan = ['suhu_awal' => 27.7, 'suhu_akhir' => 27.7],
        ?int $jumlahBacaan = null,
    ): array {
        $spesifikasi = $this->blok($blokGanti);

        $masukan = [];

        foreach ($titik as $i => [$nominal, $a, $b]) {
            $bacaan = $this->bacaan($a, $b);

            if ($jumlahBacaan !== null) {
                $bacaan = array_slice($bacaan, 0, $jumlahBacaan);
            }

            $masukan[] = [
                'titik_ke' => $i + 1,
                'titik_ukur' => $nominal,
                'pembacaan' => [],
                'standard' => null,
                'konteks' => [
                    'bacaan' => $bacaan,
                    'spesifikasi_alat' => $spesifikasi,
                    ...$lingkungan,
                ],
            ];
        }

        return (new LoadCellProfile)->hitungPerGrup($masukan, $this->alat);
    }

    /**
     * @param  array<string, mixed>  $hasil
     * @return list<string>
     */
    private function alasanBlokir(array $hasil): array
    {
        return array_map(
            static fn (array $b): string => (string) $b['alasan'],
            $hasil['belum_dihitung'],
        );
    }

    /**
     * @param  array<string, mixed>  $hasil
     * @return list<string>
     */
    private function temuanSesi(array $hasil): array
    {
        return $hasil['hitungan'][0]['type_b_components']['temuan_sesi'] ?? [];
    }

    // ---------------------------------------------------------------
    // §8.1 — pemblokir
    // ---------------------------------------------------------------

    public function test_misalignment_kurang_dari_empat_memblokir_seluruh_sesi(): void
    {
        $hasil = $this->hitung(
            [[2.0, 2.16, 2.14], [3.0, 3.48, 3.45]],
            ['misalignment' => [8.237, 8.234]],
        );

        $this->assertSame([], $hasil['hitungan'], 'Sesi dengan misalignment kurang tetap terhitung.');
        $this->assertCount(2, $hasil['belum_dihitung'], 'Pemblokir sesi harus menyebut SEMUA titik.');

        foreach ($this->alasanBlokir($hasil) as $alasan) {
            $this->assertStringContainsString('Misalignment perlu 4 pengukuran', $alasan);
            $this->assertStringContainsString('yang terisi 2', $alasan);
        }
    }

    public function test_suhu_ruangan_di_luar_rentang_memblokir(): void
    {
        $hasil = $this->hitung(
            [[2.0, 2.16, 2.14]],
            [],
            ['suhu_awal' => 38.2, 'suhu_akhir' => 38.0],
        );

        $this->assertSame([], $hasil['hitungan']);
        $this->assertStringContainsString('38.2 °C di luar rentang metode', $this->alasanBlokir($hasil)[0]);
        $this->assertStringContainsString('10–35 °C', $this->alasanBlokir($hasil)[0]);
    }

    public function test_suhu_di_batas_rentang_masih_diterima(): void
    {
        $hasil = $this->hitung(
            [[2.0, 2.16, 2.14]],
            [],
            ['suhu_awal' => 10.0, 'suhu_akhir' => 35.0],
        );

        $this->assertCount(1, $hasil['hitungan'], 'Batasnya inklusif — 10 dan 35 masih sah.');
    }

    /**
     * Kurang dari dua belas memblokir TITIKNYA, bukan seluruh sesi.
     *
     * Bedanya penting: lembar yang baru terisi sebagian itu keadaan normal di
     * tengah pengerjaan, dan teknisi harus tetap bisa melihat titik yang sudah
     * lengkap.
     */
    public function test_bacaan_kurang_dari_dua_belas_memblokir_titiknya(): void
    {
        $hasil = $this->hitung([[2.0, 2.16, 2.14]], [], ['suhu_awal' => 27.7, 'suhu_akhir' => 27.7], 9);

        $this->assertSame([], $hasil['hitungan']);
        $this->assertStringContainsString('baru terisi 9 dari 12 pembacaan', $this->alasanBlokir($hasil)[0]);
        $this->assertStringContainsString('lebih kecil dari yang seharusnya', $this->alasanBlokir($hasil)[0]);
    }

    public function test_dua_belas_bacaan_lolos(): void
    {
        $this->assertCount(1, $this->hitung([[2.0, 2.16, 2.14]])['hitungan']);
    }

    /** Dua belas itu ANGKA TETAP, bukan minimum. */
    public function test_lebih_dari_dua_belas_juga_ditolak(): void
    {
        $hasil = (new LoadCellProfile)->hitungPerGrup([[
            'titik_ke' => 1,
            'titik_ukur' => 2.0,
            'pembacaan' => [],
            'standard' => null,
            'konteks' => [
                'bacaan' => [...$this->bacaan(2.16, 2.14), 2.16],
                'spesifikasi_alat' => $this->blok(),
                'suhu_awal' => 27.7,
                'suhu_akhir' => 27.7,
            ],
        ]], $this->alat);

        $this->assertSame([], $hasil['hitungan']);
        $this->assertStringContainsString('terisi 13 dari 12', $this->alasanBlokir($hasil)[0]);
    }

    // ---------------------------------------------------------------
    // §8.2 — peringatan
    // ---------------------------------------------------------------

    /**
     * INI yang paling menentukan di berkas ini.
     *
     * Urutan titik sesi master Load Cell memang tidak naik. Kalau aturan
     * panduan diikuti mentah, sesi contoh repo ini sendiri tidak bisa dihitung.
     */
    public function test_urutan_titik_tidak_naik_cuma_peringatan(): void
    {
        $hasil = $this->hitung([
            [0.0, 0.0, 0.0],
            [100.0, 100.0, 100.0],
            [2.0, 2.16, 2.14],
        ]);

        $this->assertCount(3, $hasil['hitungan'], 'Urutan turun TIDAK boleh memblokir — sesi master begitu.');

        $this->assertNotEmpty(array_filter(
            $this->temuanSesi($hasil),
            static fn (string $t): bool => str_contains($t, 'urutan bebannya tidak naik'),
        ), 'Urutan turun harus tetap TERLIHAT. Temuan: '.implode(' | ', $this->temuanSesi($hasil)));
    }

    public function test_urutan_naik_tidak_menghasilkan_peringatan(): void
    {
        $hasil = $this->hitung([[2.0, 2.16, 2.14], [3.0, 3.48, 3.45]]);

        $this->assertEmpty(array_filter(
            $this->temuanSesi($hasil),
            static fn (string $t): bool => str_contains($t, 'urutan bebannya tidak naik'),
        ));
    }

    public function test_suhu_bergeser_lebih_dari_dua_derajat_disorot(): void
    {
        $hasil = $this->hitung(
            [[2.0, 2.16, 2.14]],
            [],
            ['suhu_awal' => 22.0, 'suhu_akhir' => 25.5],
        );

        $this->assertCount(1, $hasil['hitungan'], 'Sebaran suhu menyorot, tidak menahan.');
        $this->assertNotEmpty(array_filter(
            $this->temuanSesi($hasil),
            static fn (string $t): bool => str_contains($t, 'kondisi tidak stabil'),
        ), 'Temuan: '.implode(' | ', $this->temuanSesi($hasil)));
    }

    public function test_suhu_stabil_tidak_disorot(): void
    {
        $hasil = $this->hitung(
            [[2.0, 2.16, 2.14]],
            [],
            ['suhu_awal' => 27.7, 'suhu_akhir' => 27.7],
        );

        $this->assertEmpty(array_filter(
            $this->temuanSesi($hasil),
            static fn (string $t): bool => str_contains($t, 'kondisi tidak stabil'),
        ));
    }

    public function test_zero_error_bukan_nol_disorot(): void
    {
        $hasil = $this->hitung([[2.0, 2.16, 2.14]], ['preload_zero' => [0.0, 0.3, 0.0]]);

        $this->assertCount(1, $hasil['hitungan'], 'Zero error masuk budget — menyorot, bukan menahan.');
        $this->assertNotEmpty(array_filter(
            $this->temuanSesi($hasil),
            static fn (string $t): bool => str_contains($t, 'Zero error'),
        ), 'Temuan: '.implode(' | ', $this->temuanSesi($hasil)));
    }

    public function test_zero_error_nol_tidak_disorot(): void
    {
        $hasil = $this->hitung([[2.0, 2.16, 2.14]]);

        $this->assertEmpty(array_filter(
            $this->temuanSesi($hasil),
            static fn (string $t): bool => str_contains($t, 'Zero error'),
        ));
    }

    /** Peringatan sesi wajib terbaca dari titik MANA PUN, bukan cuma yang pertama. */
    public function test_peringatan_sesi_muncul_di_tiap_titik(): void
    {
        $hasil = $this->hitung(
            [[2.0, 2.16, 2.14], [3.0, 3.48, 3.45], [4.0, 4.06, 4.06]],
            ['preload_zero' => [0.0, 0.3, 0.0]],
        );

        $this->assertCount(3, $hasil['hitungan']);

        foreach ($hasil['hitungan'] as $h) {
            $this->assertNotEmpty(array_filter(
                $h['type_b_components']['temuan_sesi'],
                static fn (string $t): bool => str_contains($t, 'Zero error'),
            ), "Titik {$h['titik_ke']} tidak memperlihatkan peringatan sesi.");
        }
    }
}
