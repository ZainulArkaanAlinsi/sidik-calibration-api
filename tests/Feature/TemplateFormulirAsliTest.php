<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ocr\FormulirAsli;
use App\Services\Ocr\TemplateLembarKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * `GET /api/worksheet-templates/{kode}?kertas=asli` — geometri formulir ASLI
 * lab (pilot pH, SIDIK-FM-CAL-0509 Rev.4), supaya HP bisa memindai kertas yang
 * memang dipakai lab tanpa marker/QR. `docs/PANDUAN-OCR-LEMBAR-KERJA.md` §3.
 *
 * Yang dijaga di sini dua arah:
 *
 * - Kotak yang dikirim ke HP = kotak di `asli/*.draf.json` yang memuat titik
 *   peta. Salah ambil kotak = angka dipotong dari sel tetangga, tanpa error.
 * - Kontrak lama tidak bergeser satu byte: tanpa `kertas` (atau `kertas=cetak`)
 *   jawabannya persis jalur cetak bermarker yang sudah dipakai lapangan.
 *
 * Belum ada satu pun yang terverifikasi ke foto nyata (§5 langkah 5), jadi
 * `siap_pindai` wajib false — test ini juga menjaga itu.
 */
class TemplateFormulirAsliTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/worksheet-templates/ph_meter';

    private const ENV = ['suhu_awal', 'kelembaban_awal', 'suhu_akhir', 'kelembaban_akhir'];

    private User $teknisi;

    private ?string $folderFixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();
    }

    protected function tearDown(): void
    {
        if ($this->folderFixture !== null) {
            foreach (glob($this->folderFixture.'/asli/*.json') ?: [] as $berkas) {
                @unlink($berkas);
            }

            @rmdir($this->folderFixture.'/asli');
            @rmdir($this->folderFixture);
        }

        parent::tearDown();
    }

    public function test_identitas_formulir_dari_draf_dan_peta(): void
    {
        $data = $this->asli();
        $draf = $this->draf();

        $this->assertSame('ph_meter', $data['template_id']);
        $this->assertSame('asli', $data['kertas']);
        $this->assertSame('SIDIK-FM-CAL-0509', $data['kode_dokumen']);
        $this->assertSame($draf['kode_dokumen'], $data['kode_dokumen']);
        $this->assertSame('4', $data['revisi']);
        $this->assertSame($this->peta()['sumber_sha256'], $data['sumber_sha256']);
        $this->assertSame($draf['sumber']['sha256'], $data['sumber_sha256']);
    }

    /**
     * Bagian yang diturunkan dari PROFIL wajib sama dengan jalur cetak — satu
     * sumber kebenaran, bukan salinan kedua.
     */
    public function test_bagian_profil_sama_dengan_jalur_cetak(): void
    {
        $asli = $this->asli();
        $cetak = $this->cetak();

        foreach (['judul', 'satuan', 'jumlah_pengulangan', 'tabel', 'pipeline_versi'] as $kunci) {
            $this->assertSame($cetak[$kunci], $asli[$kunci], "`{$kunci}` formulir asli beda dari jalur cetak.");
        }

        // `aturan_versi` sengaja BEDA: vonis formulir asli ikut ambang jangkar
        // teks & centang miliknya sendiri (B2). Ambang sel lama tetap tercakup.
        $this->assertSame($cetak['aturan_versi'].'+'.config('ocr.formulir_asli.aturan_versi'), $asli['aturan_versi']);
        $this->assertSame(FormulirAsli::aturanVersi(), $asli['aturan_versi']);
    }

    public function test_geometri_ternormal_ukuran_lanskap_dan_93_jangkar_teks(): void
    {
        $geometri = $this->asli()['geometri'];
        $draf = $this->draf();

        $this->assertSame('ternormal_0_1', $geometri['koordinat']);
        $this->assertSame(['w' => 792, 'h' => 612, 'satuan' => 'pt', 'orientasi' => 'lanskap'], $geometri['ukuran_referensi']);
        $this->assertCount(93, $geometri['jangkar_teks']);

        foreach ($draf['jangkar_teks'] as $i => $j) {
            $this->assertSame(
                ['teks' => $j['teks'], 'kotak' => $this->kotakDari($j)],
                $geometri['jangkar_teks'][$i],
                "Jangkar teks ke-{$i} tidak sama dengan draf.",
            );
        }
    }

    /**
     * 60 kunci = 60 kunci profil, dan tiap kotak = kotak `calon_isian` draf
     * yang memuat titik peta kunci itu. Aturan angkanya ikut dari profil.
     */
    public function test_sel_60_kunci_profil_berkotak_dari_draf(): void
    {
        $sel = $this->asli()['sel'];
        $cetak = $this->cetak()['sel'];

        $this->assertCount(60, $sel);
        $this->assertSame(array_keys($cetak), array_keys($sel));

        $calon = array_filter($this->draf()['sel'], fn (array $s): bool => $s['jenis'] === 'calon_isian');

        foreach ($this->peta()['sel'] as $e) {
            $this->assertSame(
                $this->kotakTunggal($calon, (float) $e['x'], (float) $e['y']),
                $sel[$e['kunci']]['kotak'],
                "Kotak {$e['kunci']} bukan kotak draf yang memuat titik petanya.",
            );

            // Metadata sel tetap milik profil — `aturan` dkk. sama dengan cetak.
            $tanpaKotak = $sel[$e['kunci']];
            unset($tanpaKotak['kotak']);
            $this->assertSame($cetak[$e['kunci']], $tanpaKotak);
        }
    }

    /**
     * Tahap 1 (keputusan pemilik 9 Okt 2026): cuma empat isian kondisi
     * lingkungan. Identitas & tanggal BELUM dibuka.
     */
    public function test_isian_cuma_empat_kondisi_lingkungan(): void
    {
        $isian = $this->asli()['isian'];
        $draf = $this->draf();

        $this->assertEqualsCanonicalizing(self::ENV, array_column($isian, 'kode'));

        foreach ($isian as $e) {
            $peta = $this->entriPeta('isian', fn (array $p): bool => $p['kode'] === $e['kode']);

            $this->assertSame('angka', $e['cara']);
            $this->assertSame(['kode', 'cara', 'kotak'], array_keys($e));
            $this->assertSame(
                $this->kotakTunggal($draf['isian_berlabel'], (float) $peta['x'], (float) $peta['y']),
                $e['kotak'],
            );
            $this->assertNotNull($e['kotak']);
        }

        $this->assertCount(4, array_unique(array_map('json_encode', array_column($isian, 'kotak'))));
    }

    public function test_sembilan_centang_berkotak_dari_kotak_centang(): void
    {
        $centang = $this->asli()['centang'];
        $draf = $this->draf();

        $this->assertCount(9, $centang);

        foreach ($this->peta()['centang'] as $i => $p) {
            $e = $centang[$i];

            $this->assertSame($p['kode'], $e['kode']);
            $this->assertSame($p['pilihan'] ?? null, $e['pilihan']);
            $this->assertSame($p['label'] ?? null, $e['label']);
            $this->assertSame($p['baris_ke'] ?? null, $e['baris_ke']);
            $this->assertNotNull($e['kotak']);
            $this->assertSame(
                $this->kotakTunggal($draf['kotak_centang'], (float) $p['x'], (float) $p['y']),
                $e['kotak'],
                "Kotak centang {$p['kode']} bukan kotak_centang draf yang memuat titik petanya.",
            );
        }

        $this->assertCount(9, array_unique(array_map('json_encode', array_column($centang, 'kotak'))));
    }

    public function test_belum_siap_pindai_karena_belum_diverifikasi(): void
    {
        $data = $this->asli();

        $this->assertFalse($data['siap_pindai']);
        $this->assertSame('geometri_belum_diverifikasi', $data['alasan_belum_siap']);
    }

    /**
     * Kesiapan menuntut DUA berkas terverifikasi, bukan salah satu.
     */
    public function test_siap_pindai_butuh_draf_dan_peta_terverifikasi(): void
    {
        $this->fixtureAsli(petaTerverifikasi: true, drafTerverifikasi: false);
        $this->assertSame('geometri_belum_diverifikasi', $this->asli()['alasan_belum_siap']);

        $this->fixtureAsli(petaTerverifikasi: false, drafTerverifikasi: true);
        $this->assertSame('geometri_belum_diverifikasi', $this->asli()['alasan_belum_siap']);

        $this->fixtureAsli(petaTerverifikasi: true, drafTerverifikasi: true);
        $data = $this->asli();
        $this->assertTrue($data['siap_pindai']);
        $this->assertNull($data['alasan_belum_siap']);
    }

    /**
     * Titik peta yang jatuh di ruang kosong tidak boleh diam-diam jadi sel tanpa
     * kotak di formulir yang dinyatakan siap.
     */
    public function test_titik_peta_tanpa_kotak_membatalkan_kesiapan(): void
    {
        $this->fixtureAsli(petaTerverifikasi: true, drafTerverifikasi: true, ubahPeta: function (array $peta): array {
            $peta['sel'][0]['x'] = 0.001;
            $peta['sel'][0]['y'] = 0.001;

            return $peta;
        });

        $data = $this->asli();

        $this->assertFalse($data['siap_pindai']);
        $this->assertSame('geometri_kurang_1_sel', $data['alasan_belum_siap']);
        $this->assertNull($data['sel'][$this->peta()['sel'][0]['kunci']]['kotak']);
    }

    /**
     * Peta yang ditulis untuk PDF lain tidak boleh menunjuk kotak draf ini.
     */
    public function test_sha_peta_beda_dari_draf_tidak_siap(): void
    {
        $this->fixtureAsli(petaTerverifikasi: true, drafTerverifikasi: true, ubahPeta: function (array $peta): array {
            $peta['sumber_sha256'] = str_repeat('0', 64);

            return $peta;
        });

        $this->assertSame('geometri_belum_diverifikasi', $this->asli()['alasan_belum_siap']);
    }

    public function test_mode_uji_ikut_config(): void
    {
        $this->assertFalse($this->asli()['mode_uji']);

        Config::set('ocr.formulir_asli.mode_uji', true);

        $this->assertTrue($this->asli()['mode_uji']);
    }

    /**
     * Kontrak lama: tanpa `kertas`, `kertas=cetak`, dan panggilan langsung ke
     * `TemplateLembarKerja` menghasilkan JSON yang sama persis — dan tidak ada
     * field `kertas` yang tiba-tiba muncul.
     */
    public function test_tanpa_param_sama_persis_dengan_jalur_cetak(): void
    {
        $polos = $this->actingAs($this->teknisi)->getJson(self::URL)->assertOk()->getContent();
        $cetak = $this->actingAs($this->teknisi)->getJson(self::URL.'?kertas=cetak')->assertOk()->getContent();
        $langsung = json_encode(['data' => app(TemplateLembarKerja::class)->untukKode(
            'ph_meter',
            new Equipment(['organization_id' => $this->teknisi->organization_id]),
        )]);

        $this->assertSame($polos, $cetak);
        $this->assertSame($langsung, $polos);
        $this->assertArrayNotHasKey('kertas', json_decode($polos, true)['data']);
    }

    public function test_kertas_tak_dikenal_422(): void
    {
        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?kertas=foto')
            ->assertStatus(422)
            ->assertJsonValidationErrors('kertas');
    }

    public function test_alat_tanpa_formulir_asli_404(): void
    {
        $this->actingAs($this->teknisi)
            ->getJson('/api/worksheet-templates/conductivity_meter?kertas=asli')
            ->assertNotFound()
            ->assertJsonPath('message', 'Formulir asli lab buat alat ini belum dipetakan. Pakai lembar cetak atau isi manual dulu.');

        // Jalur cetak alat yang sama tetap jalan.
        $this->actingAs($this->teknisi)->getJson('/api/worksheet-templates/conductivity_meter')->assertOk();
    }

    public function test_kode_tak_dikenal_tetap_404_lama(): void
    {
        $this->actingAs($this->teknisi)
            ->getJson('/api/worksheet-templates/alat_ngawur?kertas=asli')
            ->assertNotFound()
            ->assertJsonPath('message', 'Template lembar kerja nggak dikenal.');
    }

    /**
     * Kode dipakai menyusun pola glob. Kode yang bukan profil terdaftar tidak
     * boleh sampai ke glob — `ph_*` atau `*` akan menangkap berkas alat lain.
     */
    public function test_kode_berpola_glob_tidak_menangkap_berkas_lain(): void
    {
        $formulir = app(FormulirAsli::class);

        $this->assertNull($formulir->untukKode('*'));
        $this->assertNull($formulir->untukKode('ph_*'));
        $this->assertNull($formulir->untukKode('ph_mete?'));
    }

    /**
     * Rute yang sama dengan jalur cetak, jadi gerbang perannya juga sama.
     */
    public function test_viewer_ditolak(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->actingAs($viewer)->getJson(self::URL.'?kertas=asli')->assertForbidden();
    }

    public function test_tanpa_login_ditolak(): void
    {
        $this->getJson(self::URL.'?kertas=asli')->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function asli(): array
    {
        return $this->actingAs($this->teknisi)->getJson(self::URL.'?kertas=asli')->assertOk()->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private function cetak(): array
    {
        return $this->actingAs($this->teknisi)->getJson(self::URL)->assertOk()->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private function draf(): array
    {
        return json_decode((string) file_get_contents(database_path('ocr-templates/asli/ph_meter-0509.draf.json')), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function peta(): array
    {
        return json_decode((string) file_get_contents(database_path('ocr-templates/asli/ph_meter-0509.peta.json')), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function entriPeta(string $jenis, callable $cocok): array
    {
        $hasil = array_values(array_filter($this->peta()[$jenis], $cocok));
        $this->assertCount(1, $hasil);

        return $hasil[0];
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array{x: float, y: float, w: float, h: float}
     */
    private function kotakDari(array $o): array
    {
        return ['x' => (float) $o['x'], 'y' => (float) $o['y'], 'w' => (float) $o['w'], 'h' => (float) $o['h']];
    }

    /**
     * Logika yang sama dengan `GeometriFormulirAsliPhTest::kotakBerisiTitik()`,
     * ditulis ulang di sini supaya test ini tidak meminjam kode yang diujinya.
     *
     * @param  iterable<array<string, mixed>>  $kotak
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private function kotakTunggal(iterable $kotak, float $x, float $y): ?array
    {
        $kena = [];

        foreach ($kotak as $k) {
            if ($x >= (float) $k['x'] && $x <= (float) $k['x'] + (float) $k['w']
                && $y >= (float) $k['y'] && $y <= (float) $k['y'] + (float) $k['h']) {
                $kena[] = $this->kotakDari($k);
            }
        }

        $this->assertLessThanOrEqual(1, count($kena), "Titik ({$x}, {$y}) jatuh di lebih dari satu kotak draf.");

        return $kena[0] ?? null;
    }

    /**
     * Salin draf & peta pH ke folder sementara, dengan flag `terverifikasi`
     * yang disetel — berkas produksinya sendiri tidak pernah disentuh.
     */
    private function fixtureAsli(bool $petaTerverifikasi, bool $drafTerverifikasi, ?callable $ubahPeta = null): void
    {
        $this->folderFixture ??= storage_path('framework/testing/ocr-formulir-asli-'.getmypid());
        @mkdir($this->folderFixture.'/asli', 0755, true);

        $draf = $this->draf();
        $draf['terverifikasi'] = $drafTerverifikasi;

        $peta = $this->peta();
        $peta['terverifikasi'] = $petaTerverifikasi;
        $peta = $ubahPeta !== null ? $ubahPeta($peta) : $peta;

        file_put_contents($this->folderFixture.'/asli/ph_meter-0509.draf.json', json_encode($draf));
        file_put_contents($this->folderFixture.'/asli/ph_meter-0509.peta.json', json_encode($peta));

        Config::set('ocr.folder_template', $this->folderFixture);
    }
}
