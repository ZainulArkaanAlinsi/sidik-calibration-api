<?php

namespace Tests\Feature;

use App\Services\Acuan\SumberAcuan;
use App\Services\Studio\PetaSel\JalurIsi;
use App\Services\Studio\PetaSel\PemeriksaMaster;
use App\Services\Studio\PetaSel\PetaSel;
use App\Services\Studio\Workbook\GagalBacaWorkbook;
use App\Support\JsonKanonik;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Paritas peta sel untuk paket TANPA ekspor CSV di repo (Tekanan, Piston
 * Volume, ...): sel yang dirujuk peta dibekukan dari master .xlsm ASLI oleh
 * `docs/skrip/bekukan-sel-peta-xlsm.py` ke `tests/Fixtures/peta-sel-xlsm/`
 * (bentuk keluaran `PembacaXlsmWorkbook`, hanya sel yang dirujuk peta — bukan
 * workbook-nya). Ekstraksi PHP format xlsm di atas dasar JSON repo wajib =
 * berkas JSON (sha256 kanonik), dan tiap daun yang dipetakan benar-benar
 * ditulis ulang dari master. Verifikasi penuh terhadap berkas aslinya:
 * `docs/skrip/verifikasi-peta-xlsm.py` (sha256 di `peta-sel/manifest-xlsm.json`).
 */
class StudioPetaSelXlsmTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SumberAcuan::lupakanCache();
        config(['studio.peta_sel.direktori' => database_path('data/peta-sel')]);
    }

    /** @return array<string, array{string, string}> */
    public static function fixture(): array
    {
        $hasil = [];

        // Data provider jalan sebelum aplikasi hidup: base_path() belum ada.
        foreach (glob(dirname(__DIR__).'/Fixtures/peta-sel-xlsm/*/*.json') ?: [] as $berkas) {
            $paket = basename(dirname($berkas));
            $hasil[$paket.'/'.basename($berkas, '.json')] = [$paket, $berkas];
        }

        return $hasil;
    }

    #[DataProvider('fixture')]
    public function test_ekstraksi_sel_master_xlsm_identik_dengan_json(string $paket, string $berkas): void
    {
        $fx = json_decode((string) file_get_contents($berkas), true, 512, JSON_THROW_ON_ERROR);
        $peta = PetaSel::untuk($paket) ?? $this->fail("peta {$paket} tidak ada");
        $this->assertSame([], PetaSel::validasi($paket, $peta->isi));
        $json = json_decode((string) file_get_contents(database_path('data/'.$peta->isi['sumber_json'])), true);
        $sha = JsonKanonik::sha256(JsonKanonik::serialisasi($json));

        // sha256 berkas yang dibekukan = yang tercatat terverifikasi di manifest.
        $manifest = json_decode((string) file_get_contents(database_path('data/peta-sel/manifest-xlsm.json')), true);
        $tercatat = array_filter($manifest['berkas'], static fn (array $b): bool => $b['paket'] === $paket && $b['sha256'] === $fx['sha256'] && $b['terverifikasi']);
        $this->assertNotEmpty($tercatat, "{$fx['berkas']}: sha256 tidak tercatat terverifikasi di manifest-xlsm.json");

        $periksa = app(PemeriksaMaster::class);
        $hasil = $periksa->periksa($peta, $fx['berkas'], $fx['lembar'], $json, PemeriksaMaster::FORMAT_XLSM);
        $this->assertSame($fx['varian'], $hasil['varian']);
        $this->assertSame($sha, JsonKanonik::sha256(JsonKanonik::serialisasi($hasil['isi'])), "{$fx['berkas']}: isi ekstraksi beda dari JSON");
        $this->assertNotEmpty($hasil['jalur']);

        $rusak = $json;

        foreach ($hasil['jalur'] as $jalur) {
            JalurIsi::tulis($rusak, $jalur, '__rusak__');
        }

        $pulih = $periksa->periksa($peta, $fx['berkas'], $fx['lembar'], $rusak, PemeriksaMaster::FORMAT_XLSM);
        $this->assertSame($sha, JsonKanonik::sha256(JsonKanonik::serialisasi($pulih['isi'])), "{$fx['berkas']}: dasar rusak tidak dipulihkan");
    }

    /**
     * U95 DRUCK07G `G10:G17` sel gabungan: tanpa merge di tata letak, baris
     * G11..G17 kosong dan master DITOLAK — bukan diisi nol atau dilewati.
     */
    public function test_tanpa_merge_u95_gabungan_ditolak(): void
    {
        $fx = json_decode((string) file_get_contents(base_path('tests/Fixtures/peta-sel-xlsm/tekanan/druck07g.json')), true);
        $peta = PetaSel::untuk('tekanan') ?? $this->fail('peta tekanan');
        $json = json_decode((string) file_get_contents(database_path('data/tabel-standar-tekanan.json')), true);
        $lembar = array_map(static function (array $l): array {
            $l['tata_letak']['merge'] = [];

            return $l;
        }, $fx['lembar']);

        try {
            app(PemeriksaMaster::class)->periksa($peta, $fx['berkas'], $lembar, $json, PemeriksaMaster::FORMAT_XLSM);
            $this->fail('U95 gabungan tanpa merge seharusnya ditolak');
        } catch (GagalBacaWorkbook $e) {
            $this->assertStringContainsString('STANDAR_DRUCK!G11 kosong, padahal wajib angka', $e->getMessage());
        }
    }
}
