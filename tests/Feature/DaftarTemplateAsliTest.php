<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Ocr\TemplateLembarKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET /api/worksheet-templates` — kunci `asli` per entri (14 Nov 2026).
 *
 * Beranda mobile ingin tahu "ada formulir ASLI yang siap dipindai?" tanpa satu
 * panggilan `?kertas=asli` per kode (48 entri). Kunci `asli` menjawabnya dari
 * jawaban `FormulirAsli::cari()` yang SAMA — bukan aturan kedua — jadi yang
 * dijaga di sini terutama tiga hal yang tidak menghasilkan error kalau lepas:
 *
 * - kunci LAMA tidak bergeser (aplikasi terpasang membacanya);
 * - `asli` sama dengan `?kertas=asli` untuk tiap kode, bukan sekadar mirip;
 * - saklar mati (produksi) tidak pernah menampilkan "boleh dipindai".
 */
class DaftarTemplateAsliTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/worksheet-templates';

    /** Tujuh kunci entri daftar SEBELUM `asli` ada — urutan ikut dijaga. */
    private const KUNCI_LAMA = [
        'template_id', 'versi', 'kode_dokumen', 'judul', 'jumlah_sel', 'siap_pindai', 'alasan_belum_siap',
    ];

    private const KUNCI_ASLI = [
        'ada', 'kode_dokumen', 'revisi', 'siap_pindai', 'mode_uji', 'perlu_konfirmasi_lab',
    ];

    private Organization $lab;

    private User $teknisi;

    private ?string $folderFixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->createOne();
        $this->teknisi = User::factory()->createOne(['organization_id' => $this->lab->id]);
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

    public function test_tiap_entri_membawa_asli_berenam_kunci_bertipe_tetap(): void
    {
        $data = $this->daftar();

        $this->assertCount(count(app(CalibrationProfileRegistry::class)->semua()), $data);

        foreach ($data as $entri) {
            $id = $entri['template_id'];
            $asli = $entri['asli'];

            $this->assertSame(self::KUNCI_ASLI, array_keys($asli), "{$id}: kunci/urutan `asli`.");
            $this->assertIsBool($asli['ada'], $id);
            $this->assertIsBool($asli['siap_pindai'], $id);
            $this->assertIsBool($asli['mode_uji'], $id);
            // Kunci ini DIKIRIM server (`FormulirAsli::cari()` `perlu_konfirmasi_lab`).
            // Tidak boleh hilang: HP menganggap kunci yang tak ada = false.
            $this->assertIsBool($asli['perlu_konfirmasi_lab'], $id);
            $this->assertTrue($asli['kode_dokumen'] === null || is_string($asli['kode_dokumen']), $id);
            $this->assertTrue($asli['revisi'] === null || is_string($asli['revisi']), $id);

            if ($asli['ada']) {
                $this->assertNotSame('', (string) $asli['kode_dokumen'], "{$id}: ada tapi tanpa kode dokumen.");
            } else {
                // Tidak ada formulir yang bisa dibuka = tidak ada yang boleh dipindai.
                $this->assertNull($asli['kode_dokumen'], $id);
                $this->assertNull($asli['revisi'], $id);
                $this->assertFalse($asli['siap_pindai'], $id);
                $this->assertFalse($asli['mode_uji'], $id);
                $this->assertFalse($asli['perlu_konfirmasi_lab'], $id);
            }
        }
    }

    /**
     * Regresi kontrak: aplikasi yang sudah terpasang membaca ketujuh kunci lama.
     * `asli` hanya MENAMBAH di ujung; isi kunci lama sama dengan keluaran
     * `TemplateLembarKerja::daftar()` tanpa tambahan.
     */
    public function test_kunci_lama_tidak_berubah(): void
    {
        $respons = $this->actingAs($this->teknisi)->getJson(self::URL)->assertOk();

        $this->assertSame(['data'], array_keys($respons->json()));

        $lama = json_decode((string) json_encode(
            app(TemplateLembarKerja::class)->daftar(new Equipment(['organization_id' => $this->lab->id])),
        ), true);
        $baru = $respons->json('data');

        $this->assertCount(count($lama), $baru);

        foreach ($baru as $i => $entri) {
            $this->assertSame([...self::KUNCI_LAMA, 'asli'], array_keys($entri), "entri {$i}: kunci/urutan.");

            unset($entri['asli']);
            $this->assertSame($lama[$i], $entri, "entri {$i}: nilai kunci lama berubah.");
            $this->assertSame(self::KUNCI_LAMA, array_keys($lama[$i]), "entri {$i}: `daftar()` tanpa tambahan bergeser.");

            // Pembanding BEBAS dari `daftar()`: rute detail membangun lembar yang
            // sama lewat jalur lain. Tanpa ini, perubahan nilai di `daftar()`
            // sendiri (mis. `jumlah_sel` menghitung hal lain) ikut mengubah
            // pembanding di atas dan lolos diam-diam.
            $detail = $this->actingAs($this->teknisi)->getJson(self::URL.'/'.$entri['template_id'])->assertOk()->json('data');

            foreach (['versi', 'kode_dokumen', 'judul', 'siap_pindai', 'alasan_belum_siap'] as $kunci) {
                $this->assertSame($detail[$kunci], $entri[$kunci], "{$entri['template_id']}: `{$kunci}` di daftar beda dari rute detail.");
            }

            $this->assertSame(count($detail['sel']), $entri['jumlah_sel'], "{$entri['template_id']}: `jumlah_sel` bukan jumlah sel lembarnya.");
        }

        // Satu nilai yang diketahui dari formulir pH (`TemplateFormulirAsliTest`: 60 sel).
        $this->assertSame(60, array_column($baru, null, 'template_id')['ph_meter']['jumlah_sel']);
    }

    /**
     * Sumber kebenaran tunggal: untuk KODE MANA PUN `asli` = jawaban
     * `?kertas=asli`; 404 (tidak ada/tidak bisa dipilih) = `ada: false`.
     */
    public function test_sama_dengan_kertas_asli_untuk_tiap_kode(): void
    {
        $ada = 0;
        $tidakAda = 0;

        foreach ($this->daftar() as $entri) {
            $kode = $entri['template_id'];
            $asli = $entri['asli'];
            $satu = $this->actingAs($this->teknisi)->getJson(self::URL.'/'.$kode.'?kertas=asli');

            if ($satu->status() === 404) {
                $tidakAda++;
                $this->assertFalse($asli['ada'], "{$kode}: `?kertas=asli` 404 tapi daftar bilang ada.");
                $this->assertSame(
                    ['ada' => false, 'kode_dokumen' => null, 'revisi' => null, 'siap_pindai' => false, 'mode_uji' => false, 'perlu_konfirmasi_lab' => false],
                    $asli,
                    $kode,
                );

                continue;
            }

            $satu->assertOk();
            $ada++;
            $template = $satu->json('data');

            $this->assertSame(
                [
                    'ada' => true,
                    'kode_dokumen' => $template['kode_dokumen'],
                    'revisi' => $template['revisi'],
                    'siap_pindai' => $template['siap_pindai'],
                    'mode_uji' => $template['mode_uji'],
                    'perlu_konfirmasi_lab' => $template['perlu_konfirmasi_lab'],
                ],
                $asli,
                "{$kode}: `asli` di daftar beda dari `?kertas=asli`.",
            );
        }

        // Penjaga supaya testnya tidak lulus karena semuanya 404 (atau semuanya 200).
        $this->assertGreaterThan(20, $ada, 'terlalu sedikit formulir asli yang terbaca — testnya tidak membuktikan apa-apa.');
        $this->assertGreaterThan(0, $tidakAda, 'tidak ada satu pun kode tanpa formulir asli — cabang `ada: false` tidak teruji.');
    }

    /**
     * Saklar mati (produksi: `OCR_FORMULIR_ASLI_UJI=false`) dan belum ada
     * formulir terverifikasi => tidak satu pun entri boleh tampak "bisa dipindai".
     */
    public function test_saklar_mati_tidak_pernah_boleh_dipindai(): void
    {
        Config::set('ocr.formulir_asli.mode_uji', false);

        $data = $this->daftar();

        foreach ($data as $entri) {
            $this->assertFalse($entri['asli']['mode_uji'], $entri['template_id'].': mode_uji menyala padahal saklar mati.');
            // Belum ada formulir yang lulus >=20 foto nyata (`TemplateFormulirAsliTest`
            // menjaga hal yang sama di jalur satu kode). Kalau lab kelak
            // memverifikasi satu formulir, test ini sengaja ikut diperbarui —
            // menyalakan tombol pindai di produksi harus keputusan sadar.
            $this->assertFalse($entri['asli']['siap_pindai'], $entri['template_id'].': siap_pindai menyala tanpa verifikasi.');
        }

        // Sanity: formulirnya tetap ADA, hanya tidak dibuka.
        $this->assertTrue(collect($data)->firstWhere('template_id', 'ph_meter')['asli']['ada']);
    }

    public function test_saklar_uji_nyala_menyalakan_mode_uji_hanya_untuk_yang_ada(): void
    {
        // phpunit*.xml menyetel OCR_FORMULIR_ASLI_UJI=true; dipasang eksplisit
        // supaya test ini tidak bergantung pada berkas itu.
        Config::set('ocr.formulir_asli.mode_uji', true);

        $data = $this->daftar();
        $ada = 0;

        foreach ($data as $entri) {
            $asli = $entri['asli'];

            $this->assertSame($asli['ada'], $asli['mode_uji'], $entri['template_id'].': mode_uji harus ikut `ada`.');
            // Mode uji tidak pernah menyalakan siap_pindai (config/ocr.php).
            $this->assertFalse($asli['siap_pindai'], $entri['template_id']);
            $ada += $asli['ada'] ? 1 : 0;
        }

        $this->assertGreaterThan(0, $ada);

        // Kebalikannya: saklar dimatikan di request berikutnya, mode_uji ikut mati.
        Config::set('ocr.formulir_asli.mode_uji', false);
        $this->assertSame([false], array_values(array_unique(array_column(array_column($this->daftar(), 'asli'), 'mode_uji'))));
    }

    /**
     * `perlu_konfirmasi_lab` ada sumbernya di server (peta formulir) dan ikut
     * terkirim — kelima formulir berasumsi posisional menyalakannya, pH tidak.
     */
    public function test_perlu_konfirmasi_lab_berasal_dari_peta(): void
    {
        $per = array_column($this->daftar(), 'asli', 'template_id');

        foreach (['conductivity_meter', 'refractometer', 'chlorine_meter', 'do_meter', 'turbidimeter'] as $kode) {
            $this->assertTrue($per[$kode]['ada'], $kode);
            $this->assertTrue($per[$kode]['perlu_konfirmasi_lab'], "{$kode} wajib menunggu konfirmasi lab.");
        }

        $this->assertFalse($per['ph_meter']['perlu_konfirmasi_lab']);
    }

    /**
     * Formulir terverifikasi (draf & peta) => `siap_pindai: true`, dan saklar
     * uji TIDAK ikut menentukannya; bendera peta ikut terbawa.
     */
    public function test_formulir_terverifikasi_siap_pindai_terlepas_dari_saklar(): void
    {
        $this->fixturePh(terverifikasi: true);

        foreach ([true, false] as $saklar) {
            Config::set('ocr.formulir_asli.mode_uji', $saklar);

            $per = array_column($this->daftar(), 'asli', 'template_id');
            $satu = $this->actingAs($this->teknisi)->getJson(self::URL.'/ph_meter?kertas=asli')->assertOk()->json('data');

            $this->assertSame(
                ['ada' => true, 'kode_dokumen' => 'SIDIK-FM-CAL-0509', 'revisi' => '4', 'siap_pindai' => true, 'mode_uji' => $saklar, 'perlu_konfirmasi_lab' => false],
                $per['ph_meter'],
            );
            $this->assertTrue($satu['siap_pindai']);
            $this->assertSame($saklar, $satu['mode_uji']);
            // Folder fixture cuma memuat pH — yang lain tidak punya formulir.
            $this->assertFalse($per['turbidimeter']['ada']);
        }

        $this->fixturePh(terverifikasi: true, ubahPeta: function (array $peta): array {
            $peta['perlu_konfirmasi_lab'] = true;

            return $peta;
        });

        $this->assertTrue(array_column($this->daftar(), 'asli', 'template_id')['ph_meter']['perlu_konfirmasi_lab']);
    }

    public function test_formulir_belum_terverifikasi_tidak_siap_pindai(): void
    {
        $this->fixturePh(terverifikasi: false);

        $this->assertFalse(array_column($this->daftar(), 'asli', 'template_id')['ph_meter']['siap_pindai']);
    }

    /**
     * Beberapa peta per template dan tanpa kode FM yang dibaca dari kertas
     * (Micrometer 0522.A–D): `?kertas=asli` menjawab 404, jadi daftar = `ada: false`.
     * Server tidak menebak salah satunya.
     */
    public function test_template_beda_peta_tanpa_kode_tidak_ditebak(): void
    {
        $this->assertGreaterThan(1, count(glob(database_path('ocr-templates/asli/micrometer-*.peta.json')) ?: []));

        $this->actingAs($this->teknisi)->getJson(self::URL.'/micrometer?kertas=asli')->assertNotFound();

        $this->assertFalse(array_column($this->daftar(), 'asli', 'template_id')['micrometer']['ada']);
    }

    /**
     * Tanpa N+1: jumlah kueri tidak tumbuh dengan data lab, dan cuma 2 di atas
     * daftar lama (Anak Timbangan 0541 Rev.0 membangun lembarnya sekali lagi
     * dengan X1–X3 — sama seperti `?kertas=asli`).
     */
    public function test_jumlah_kueri_tetap(): void
    {
        $konteks = new Equipment(['organization_id' => $this->lab->id]);

        $lama = $this->hitungKueri(fn () => app(TemplateLembarKerja::class)->daftar($konteks));
        $this->daftar(); // pemanasan: kelas & berkas dimuat sekali
        $baru = $this->hitungKueri(fn () => $this->actingAs($this->teknisi)->getJson(self::URL)->assertOk());

        $this->assertGreaterThan(0, $lama);
        $this->assertGreaterThanOrEqual($lama, $baru);
        $this->assertLessThanOrEqual(
            $lama + 2,
            $baru,
            'Daftar bertambah '.($baru - $lama).' kueri dari daftar lama ('.$lama.'). Satu-satunya pembangunan lembar kedua yang sah '
            .'adalah Anak Timbangan 0541 (2 kueri). Lebih dari itu = ada N+1 baru di `asli`.',
        );

        // Data lab menggemuk: standar, alat. Jumlah kueri tidak boleh ikut.
        Standard::factory()->count(25)->create(['organization_id' => $this->lab->id]);
        Equipment::factory()->count(10)->create(['organization_id' => $this->lab->id]);

        $sesudah = $this->hitungKueri(fn () => $this->actingAs($this->teknisi)->getJson(self::URL)->assertOk());

        $this->assertSame($baru, $sesudah, 'Jumlah kueri daftar ikut tumbuh dengan data lab (N+1).');
    }

    /**
     * Isolasi: `asli` adalah katalog (berkas repo), bukan data milik lab. Lab A
     * dan lab B melihat `asli` yang sama; baris standar lab lain tidak ikut keluar;
     * dan tidak ada kueri `standards` tanpa saringan organisasi.
     */
    public function test_tidak_bocor_lintas_lab(): void
    {
        $labLain = Organization::factory()->createOne();
        Standard::factory()->create([
            'organization_id' => $labLain->id,
            'nama' => 'Kalibrator Rahasia Lab Sebelah',
            'no_sertifikat' => 'JANGAN-SAMPAI-KELUAR/2026',
        ]);
        $teknisiLain = User::factory()->createOne(['organization_id' => $labLain->id]);

        $tanpaSaringan = [];
        DB::listen(function ($q) use (&$tanpaSaringan): void {
            $dariStandards = str_contains($q->sql, 'from "standards"') || str_contains($q->sql, 'from `standards`');

            if ($dariStandards && ! str_contains($q->sql, 'organization_id')) {
                $tanpaSaringan[] = $q->sql;
            }
        });

        $respons = $this->actingAs($this->teknisi)->getJson(self::URL)->assertOk();

        $this->assertStringNotContainsString('JANGAN-SAMPAI-KELUAR', $respons->getContent());
        $this->assertStringNotContainsString('Kalibrator Rahasia', $respons->getContent());
        $this->assertSame([], $tanpaSaringan, 'ada kueri `standards` tanpa saringan organisasi.');

        $milikLain = $this->actingAs($teknisiLain)->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame(
            array_column($respons->json('data'), 'asli', 'template_id'),
            array_column($milikLain, 'asli', 'template_id'),
            '`asli` berbeda antar lab — dia seharusnya katalog, bukan data milik lab.',
        );
    }

    /** Pagar akses tidak bergeser: tanpa token 401, viewer 403 (rute `role:admin,teknisi`). */
    public function test_pagar_akses_tidak_berubah(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        $viewer = User::factory()->createOne(['organization_id' => $this->lab->id, 'role' => User::ROLE_VIEWER]);
        $this->actingAs($viewer)->getJson(self::URL)->assertForbidden();

        $admin = User::factory()->admin()->createOne(['organization_id' => $this->lab->id]);
        $this->actingAs($admin)->getJson(self::URL)->assertOk()->assertJsonStructure(['data' => [['asli' => self::KUNCI_ASLI]]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function daftar(): array
    {
        return $this->actingAs($this->teknisi)->getJson(self::URL)->assertOk()->json('data');
    }

    private function hitungKueri(callable $kerja): int
    {
        $jumlah = 0;
        DB::listen(function () use (&$jumlah): void {
            $jumlah++;
        });

        $kerja();

        return $jumlah;
    }

    /**
     * Salin draf & peta pH ASLI ke folder sementara, dengan status verifikasi
     * yang ditentukan; `ocr.folder_template` dialihkan ke sana.
     */
    private function fixturePh(bool $terverifikasi, ?callable $ubahPeta = null): void
    {
        $this->folderFixture ??= storage_path('framework/testing/daftar-asli-'.getmypid());
        @mkdir($this->folderFixture.'/asli', 0755, true);

        $draf = json_decode((string) file_get_contents(database_path('ocr-templates/asli/ph_meter-0509.draf.json')), true);
        $draf['terverifikasi'] = $terverifikasi;

        $peta = json_decode((string) file_get_contents(database_path('ocr-templates/asli/ph_meter-0509.peta.json')), true);
        $peta['terverifikasi'] = $terverifikasi;
        $peta = $ubahPeta !== null ? $ubahPeta($peta) : $peta;

        file_put_contents($this->folderFixture.'/asli/ph_meter-0509.draf.json', json_encode($draf));
        file_put_contents($this->folderFixture.'/asli/ph_meter-0509.peta.json', json_encode($peta));

        Config::set('ocr.folder_template', $this->folderFixture);
    }
}
