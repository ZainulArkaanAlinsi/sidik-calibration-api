<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaketAcuanVersi;
use App\Models\SimulasiAcuan;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\Acuan\AlurVersiAcuan;
use App\Services\Acuan\SumberAcuan;
use App\Services\Studio\PetaSel\DataBersamaPaket;
use App\Services\Studio\PetaSel\PetaSel;
use App\Services\Studio\Workbook\ImporWorkbook;
use App\Services\Studio\Workbook\PembacaCsvWorkbook;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `paket_lain` peta (keputusan orkestrator 22 Okt 2026): master Dial membawa
 * balok ukur Micrometer, master Jangka Sorong membawa Caliper Checker Height
 * Gauge. Seperti generator, peta mengadunya ke versi berlaku paket itu; beda =
 * `bersama_antar_paket` — tercatat di impor & catatan master, dan jadi butir
 * wajib diakui saat pengesahan. Paket lain tidak pernah ditulis.
 */
class StudioBersamaPaketTest extends TestCase
{
    use RefreshDatabase;

    private const AKAR = 'Project-PT-Sidik/alat-alat-Pt-Sidik/Sieve_Dial_Caliper_CSV (4)/';

    private const DIAL = 'Master_Olah_Data_Dial_Indicator';

    private const CALIPER = 'Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_';

    private string $temp = '';

    protected function setUp(): void
    {
        parent::setUp();
        SumberAcuan::lupakanCache();
        config(['studio.peta_sel.direktori' => database_path('data/peta-sel'), 'acuan.sumber' => 'db']);
    }

    protected function tearDown(): void
    {
        if ($this->temp !== '' && is_dir($this->temp)) {
            (new Filesystem)->deleteDirectory($this->temp);
        }

        parent::tearDown();
    }

    /** Salinan master dengan satu penggantian teks di satu CSV. */
    private function salinUbah(string $master, string $csv, string $cari, string $ganti): string
    {
        $this->temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sidik-paket-'.bin2hex(random_bytes(5));
        $tujuan = $this->temp.DIRECTORY_SEPARATOR.$master;
        (new Filesystem)->copyDirectory(base_path(self::AKAR.$master), $tujuan);
        $isi = (string) file_get_contents($tujuan.'/'.$csv);
        $this->assertSame(1, substr_count($isi, $cari), "prasyarat: {$cari}");
        file_put_contents($tujuan.'/'.$csv, str_replace($cari, $ganti, $isi));

        return $tujuan;
    }

    /** @return list<array<string, mixed>> */
    private function beda(string $kode, string $folder, int $org): array
    {
        $peta = PetaSel::untuk($kode) ?? $this->fail("peta {$kode}");

        return app(DataBersamaPaket::class)->periksa($peta, app(PembacaCsvWorkbook::class)->baca($folder)['lembar'], $org);
    }

    public function test_master_asli_sepakat_dengan_paket_lain_berkas_maupun_versi_db(): void
    {
        $org = Organization::factory()->create();

        // Paket lain belum diimpor → diadu ke berkas JSON-nya.
        $this->assertSame([], $this->beda('dial_indicator', base_path(self::AKAR.self::DIAL), $org->id));
        $this->assertSame([], $this->beda('jangka_sorong', base_path(self::AKAR.self::CALIPER), $org->id));

        // Sesudah diimpor → diadu ke versi berlaku; tetap sepakat.
        foreach (['micrometer', 'height_gauge'] as $kode) {
            $this->artisan('acuan:impor-awal', ['profil' => $kode, '--organisasi' => $org->id])->assertSuccessful();
        }

        $this->assertSame([], $this->beda('dial_indicator', base_path(self::AKAR.self::DIAL), $org->id));
        $this->assertSame([], $this->beda('jangka_sorong', base_path(self::AKAR.self::CALIPER), $org->id));
    }

    public function test_beda_ditandai_per_daun(): void
    {
        $org = Organization::factory()->create();

        // Balok 19 mm Dial digeser 2 µm (micrometer: 19,00011).
        $dial = $this->salinUbah(self::DIAL, 'Standar_GB.csv', ',19,19.00011', ',19,19.00211');
        $this->assertSame(
            [['paket' => 'micrometer', 'dasar' => 'berkas database/data/tabel-standar-micrometer.json', 'jalur' => 'balok_ukur/19.0', 'acuan' => '19.00011', 'master' => '19.00211']],
            $this->beda('dial_indicator', $dial, $org->id),
        );

        // Caliper Checker Outside 25 mm di master Caliper digeser.
        (new Filesystem)->deleteDirectory($this->temp);
        $caliper = $this->salinUbah(self::CALIPER, 'Std_CaliperCek.csv', ',1,25,,,25.0008,', ',1,25,,,25.0018,');
        $beda = $this->beda('jangka_sorong', $caliper, $org->id);
        $this->assertSame(['height_gauge'], array_values(array_unique(array_column($beda, 'paket'))));
        $this->assertSame([['outside[0].nilai_terkoreksi_mm', '25.0008', '25.0018']], array_map(static fn (array $b): array => [$b['jalur'], $b['acuan'], $b['master']], $beda));
    }

    /**
     * Impor master Dial yang balok ukurnya beda: tidak menulis paket
     * micrometer, menandai hasil impor & catatan master; draf Dial (pita CMC
     * ikut digeser) mewajibkan pengakuan `bersama_antar_paket`.
     */
    public function test_impor_menandai_dan_pengesahan_wajib_mengakui(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->admin()->create(['organization_id' => $org->id]);

        foreach (['micrometer', 'dial_indicator'] as $kode) {
            $this->artisan('acuan:impor-awal', ['profil' => $kode, '--organisasi' => $org->id])->assertSuccessful();
        }

        $versiMicrometer = PaketAcuanVersi::query()->whereHas('paketAcuan', fn ($q) => $q->where('kode_profil', 'micrometer'))->sole();

        $dial = $this->salinUbah(self::DIAL, 'Standar_GB.csv', ',19,19.00011', ',19,19.00211');
        $isi = (string) file_get_contents($dial.'/DATABASE.csv');
        $this->assertSame(1, substr_count($isi, '0-25mm,6.5,'));
        file_put_contents($dial.'/DATABASE.csv', str_replace('0-25mm,6.5,', '0-25mm,6.6,', $isi));

        $r = app(ImporWorkbook::class)->imporCsv($dial, self::DIAL, 'uji', $org, $admin->id, false);

        $this->assertSame('draf_dibuat', $r['acuan']['status']);
        $this->assertCount(1, $r['acuan']['bersama_antar_paket']);
        $this->assertStringContainsString('PERINGATAN bersama_antar_paket: 1 daun data paket lain (micrometer)', (string) WorkbookMaster::query()->sole()->catatan);
        $this->assertSame(1, PaketAcuanVersi::query()->whereHas('paketAcuan', fn ($q) => $q->where('kode_profil', 'micrometer'))->count(), 'paket micrometer tidak ditulis');
        $this->assertSame($versiMicrometer->isi, $versiMicrometer->fresh()->isi);

        $draf = PaketAcuanVersi::query()->findOrFail($r['acuan']['versi_id']);
        $this->actingAs($admin)->postJson("/api/data-acuan/versi/{$draf->id}/simulasi")->assertCreated();
        $sim = SimulasiAcuan::query()->findOrFail($draf->fresh()->simulasi_terakhir_id);

        $this->assertSame(1, $sim->ringkasan['bersama_antar_paket']);
        $this->assertContains(AlurVersiAcuan::AKUI_BERSAMA_PAKET, AlurVersiAcuan::butirWajibDiakui($sim));
    }
}
