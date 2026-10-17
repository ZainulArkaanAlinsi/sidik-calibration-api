<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\Studio\Hitung\SumberModelHitung;
use App\Services\Studio\Workbook\ImporWorkbook;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * Studio tahap C — gambar (drawing) lembar: jangkar + offset + ukuran di
 * `tata_letak.gambar`, byte lewat `GET /studio/workbook/{w}/gambar/{id}`
 * (PNG/JPEG; jenis lain 415 + jenisnya). Izin = baca workbook. Fixture sintetis.
 */
class StudioGambarWorkbookTest extends TestCase
{
    use RefreshDatabase;
    use WorkbookUji;

    private Organization $lab1;

    private WorkbookMaster $wb;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        SumberModelHitung::lupakan();
        $this->siapkanFolderUji();
        $this->lab1 = Organization::factory()->create();
        $this->artisan('studio:impor-workbook', ['--xlsm' => [$this->buatXlsxHitung()], '--organisasi' => (string) $this->lab1->id, '--oleh' => $this->olehLab($this->lab1)])->assertSuccessful();
        $this->wb = WorkbookMaster::query()->sole();
    }

    protected function tearDown(): void
    {
        SumberModelHitung::lupakan();
        $this->bersihkanFolderUji();
        parent::tearDown();
    }

    public function test_tata_letak_gambar_dan_byte_png_jpeg_serta_415_untuk_jenis_lain(): void
    {
        $viewer = $this->pengguna($this->lab1, User::ROLE_VIEWER);
        $lembar = collect($this->sebagaiStudio($viewer)->getJson("/api/studio/workbook/{$this->wb->id}")->assertOk()->json('data.lembar'))->keyBy('nama');

        $input = $lembar['INPUT DATA']['tata_letak']['gambar'];
        $this->assertCount(2, $input);
        [$png, $jpeg] = $input;
        // Urutan kunci objek tidak dijamin: kolom JSON MySQL mengurutkan ulang kunci (panjang, lalu byte).
        $this->assertEqualsCanonicalizing(['id', 'dari', 'lebar', 'tinggi', 'jenis'], array_keys($png));
        $this->assertEquals(['kolom' => 'E', 'baris' => 2, 'dx' => 3, 'dy' => 4], $png['dari']);
        $this->assertSame([8, 4, 'png'], [$png['lebar'], $png['tinggi'], $png['jenis']]);
        $this->assertEquals(['kolom' => 'F', 'baris' => 5, 'dx' => 4, 'dy' => 5], $jpeg['dari']);
        $this->assertSame('jpeg', $jpeg['jenis']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $png['id']);

        $gif = $lembar['Data']['tata_letak']['gambar'][0];
        $this->assertSame(['lain', 'gif'], [$gif['jenis'], $gif['ekstensi']]);
        $this->assertSame([], $lembar['Kelas']['tata_letak']['gambar']);

        // Byte PNG = byte gambar asli; tersimpan terenkripsi (bukan PNG polos di disk).
        $byte = $this->sebagaiStudio($viewer)->get("/api/studio/workbook/{$this->wb->id}/gambar/{$png['id']}")
            ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->getContent();
        $this->assertSame((string) file_get_contents($this->folderUji.DIRECTORY_SEPARATOR.'gambar-0.png'), $byte);
        $disk = (string) Storage::disk('local')->get(ImporWorkbook::jalurGambar($this->lab1->id, $this->wb->sha256, $png['id']));
        $this->assertStringStartsNotWith("\x89PNG", $disk);

        $this->sebagaiStudio($viewer)->get("/api/studio/workbook/{$this->wb->id}/gambar/{$jpeg['id']}")
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->sebagaiStudio($viewer)->getJson("/api/studio/workbook/{$this->wb->id}/gambar/{$gif['id']}")
            ->assertStatus(415)->assertJsonPath('kode', 'jenis_gambar_tidak_didukung')->assertJsonPath('jenis', 'gif');

        $this->sebagaiStudio($viewer)->getJson("/api/studio/workbook/{$this->wb->id}/gambar/".str_repeat('0', 32))->assertNotFound();
        $this->sebagaiStudio($viewer)->getJson("/api/studio/workbook/{$this->wb->id}/gambar/bukan-id")->assertNotFound();
    }

    public function test_izin_gambar_sama_dengan_baca_workbook(): void
    {
        $id = $this->wb->lembar()->where('nama', 'INPUT DATA')->sole()->tata_letak['gambar'][0]['id'];
        $uri = "/api/studio/workbook/{$this->wb->id}/gambar/{$id}";

        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER, User::ROLE_SUPER_ADMIN] as $role) {
            $this->sebagaiStudio($this->pengguna($this->lab1, $role))->get($uri)->assertOk();
            $this->app['auth']->forgetGuards();
        }

        $this->sebagaiStudio($this->pengguna(Organization::factory()->create(), User::ROLE_ADMIN))->getJson($uri)->assertNotFound();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($this->pengguna($this->lab1, User::ROLE_PELANGGAN), ['internal']);
        $this->getJson($uri)->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->getJson($uri)->assertUnauthorized();
    }

    public function test_gambar_gagal_ditulis_impor_batal_tanpa_baris_berkas_asli_maupun_gambar(): void
    {
        // Tinjau C #5: dulu gambar ditulis SESUDAH transaksi — gagal tulis = baris
        // menunjuk gambar yang tidak ada (404 permanen; impor ulang cuma `sudah_ada`).
        $lab2 = Organization::factory()->create();
        $asli = Storage::disk('local');
        Storage::set('local', new class($asli->getDriver(), $asli->getAdapter(), $asli->getConfig()) extends FilesystemAdapter
        {
            public function put($path, $contents, $options = [])
            {
                return str_contains((string) $path, '-gambar/') ? false : parent::put($path, $contents, $options);
            }
        });

        $this->artisan('studio:impor-workbook', ['--xlsm' => [$this->buatXlsxHitung('Lembar Hitung Uji Lab 2.xlsx')], '--organisasi' => (string) $lab2->id, '--oleh' => $this->olehLab($lab2)])
            ->expectsOutputToContain('Gambar workbook tidak bisa ditulis')
            ->assertFailed();

        $this->assertSame(0, WorkbookMaster::query()->where('organization_id', $lab2->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles("workbook-master/{$lab2->id}"), 'berkas asli .enc ikut dibersihkan');
        $this->assertSame(1, WorkbookMaster::query()->count(), 'lab 1 tidak tersentuh');
    }

    public function test_sapu_folder_gambar_yatim_hanya_yang_tanpa_baris_dan_cukup_tua(): void
    {
        $disk = Storage::disk('local');
        $dipakai = dirname(ImporWorkbook::jalurGambar($this->lab1->id, $this->wb->sha256, 'x'));
        $this->assertCount(2, $disk->files($dipakai), 'PNG + JPEG tersimpan');
        // Sisa `--ganti` (sha lama tanpa baris) dan folder sha yang sama di lab LAIN.
        $yatim = ImporWorkbook::jalurGambar($this->lab1->id, str_repeat('c', 64), str_repeat('0', 32));
        $labLain = ImporWorkbook::jalurGambar($this->lab1->id + 1000, $this->wb->sha256, str_repeat('0', 32));
        $disk->put($yatim, 'sisa');
        $disk->put($labLain, 'sisa');

        // Masih muda (< 60 menit): tidak disentuh — impor yang sedang berjalan menulis gambar sebelum barisnya.
        $this->artisan('studio:sapu-temp-workbook')->expectsOutputToContain('Folder gambar yatim: 0 dihapus')->assertSuccessful();
        $this->assertTrue($disk->exists($yatim));

        $this->artisan('studio:sapu-temp-workbook', ['--umur' => '0'])->expectsOutputToContain('Folder gambar yatim: 2 dihapus')->assertSuccessful();
        $this->assertFalse($disk->exists($yatim));
        $this->assertFalse($disk->exists($labLain), 'sha sama tapi lab lain tanpa baris = yatim');
        $this->assertCount(2, $disk->files($dipakai), 'gambar workbook yang masih ada barisnya tetap');

        $this->sebagaiStudio($this->pengguna($this->lab1, User::ROLE_VIEWER))
            ->get("/api/studio/workbook/{$this->wb->id}/gambar/".$this->wb->lembar()->where('nama', 'INPUT DATA')->sole()->tata_letak['gambar'][0]['id'])
            ->assertOk();
    }

    private function pengguna(Organization $lab, string $role): User
    {
        return User::factory()->create(['organization_id' => $lab->id, 'role' => $role, 'status' => User::STATUS_AKTIF]);
    }
}
