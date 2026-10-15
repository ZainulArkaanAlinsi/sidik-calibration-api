<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use App\Services\Studio\Hitung\GrafDependensi;
use App\Services\Studio\Hitung\IsiSel;
use App\Services\Studio\Hitung\MesinHitung;
use App\Services\Studio\Hitung\ModelHitung;
use App\Services\Studio\Hitung\SumberModelHitung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Shared\Date as SharedDate;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * Studio tahap C — impor `.xlsx` tanpa sandi + `POST /studio/workbook/{w}/hitung`.
 *
 * Fixture SINTETIS (`WorkbookUji::buatXlsxHitung`), bukan workbook lab. Yang
 * dikunci: kontrak respons (format sel = GET lembar), izin 4 peran + lab lain,
 * daftar putih fungsi, batas edit/panjang/waktu, 409 sumber CSV, sel kotor
 * vs penuh sama persis, NOW = waktu_acuan, conditional formatting di `g`,
 * berkas asli tanpa sandi tersimpan terenkripsi kunci aplikasi.
 */
class StudioHitungWorkbookTest extends TestCase
{
    use RefreshDatabase;
    use WorkbookUji;

    private Organization $lab1;

    private Organization $lab2;

    private WorkbookMaster $wb;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        SumberModelHitung::lupakan();
        $this->siapkanFolderUji();
        $this->lab1 = Organization::factory()->create();
        $this->lab2 = Organization::factory()->create();

        // .xlsx tanpa sandi: TIDAK ada pertanyaan sandi.
        $this->artisan('studio:impor-workbook', ['--xlsm' => [$this->buatXlsxHitung()], '--organisasi' => (string) $this->lab1->id, '--oleh' => $this->olehLab($this->lab1)])
            ->expectsOutputToContain('baru 1')
            ->assertSuccessful();

        $this->wb = WorkbookMaster::query()->sole();
    }

    protected function tearDown(): void
    {
        SumberModelHitung::lupakan();
        $this->bersihkanFolderUji();
        parent::tearDown();
    }

    public function test_impor_xlsx_tanpa_sandi_tersimpan_terenkripsi_kunci_aplikasi_dan_model_hitung_siap(): void
    {
        $this->assertSame('xlsm', $this->wb->sumber);
        $this->assertFalse($this->wb->berkas_terenkripsi);
        $this->assertStringEndsWith('.xlsx.enc', (string) $this->wb->berkas_path);
        $this->assertStringContainsString('kunci aplikasi', (string) $this->wb->catatan);

        $tersimpan = (string) Storage::disk('local')->get((string) $this->wb->berkas_path);
        $this->assertStringStartsNotWith("PK\x03\x04", $tersimpan, 'salinan asli tidak boleh ZIP polos di disk');
        $this->assertSame($this->wb->sha256, hash('sha256', Crypt::decryptString($tersimpan)));

        // Model hitung disiapkan saat impor (terenkripsi juga).
        $this->assertTrue(Storage::disk('local')->exists(SumberModelHitung::jalur($this->wb->sha256)));

        // tersembunyi per lembar: kontrak S1b (string), diisi dari state sheet.
        $admin = $this->pengguna($this->lab1, User::ROLE_ADMIN);
        $lembar = $this->actingAs($admin)->getJson("/api/studio/workbook/{$this->wb->id}")->assertOk()->json('data.lembar');
        $this->assertSame(['INPUT DATA' => 'tidak', 'Kelas' => 'hidden', 'Data' => 'veryHidden'], array_column($lembar, 'tersembunyi', 'nama'));
    }

    public function test_kontrak_hitung_sel_beda_saja_format_sama_dengan_get_lembar(): void
    {
        $admin = $this->pengguna($this->lab1, User::ROLE_ADMIN);
        $input = $this->lembar('INPUT DATA');

        $respons = $this->actingAs($admin)->postJson("/api/studio/workbook/{$this->wb->id}/hitung", [
            'edit' => [['lembar_id' => $input->id, 'a' => 'B2', 'isi' => '3']],
            'waktu_acuan' => '2026-10-14T10:00:00+07:00',
        ])->assertOk();

        $data = $respons->json('data');
        $this->assertSame(['lembar', 'jumlah_dihitung', 'durasi_ms', 'galat'], array_keys($data));
        $this->assertIsInt($data['durasi_ms']);
        $this->assertSame([], $data['galat']);
        $this->assertCount(1, $data['lembar']);
        $this->assertSame($input->id, $data['lembar'][0]['lembar_id']);

        $sel = collect($data['lembar'][0]['sel'])->keyBy('a');

        foreach ($sel as $s) {
            // Kunci & urutan = sel kanonik GET lembar.
            $this->assertSame(['a', 'b', 'f', 'g', 'k', 'n', 'r', 's', 't'], array_keys($s));
        }

        // Sel diedit selalu ikut; konstanta diketik seperti Excel.
        $this->assertSame(['n' => 3.0, 'r' => null, 's' => '3', 't' => 'n'], array_intersect_key($sel['B2'], array_flip(['t', 'n', 's', 'r'])));
        // VLOOKUP lintas lembar tersembunyi, IF bersarang, rumus bergantung kode.
        $this->assertSame('F1', $sel['D2']['s']);
        $this->assertSame('f', $sel['D2']['t']);
        $this->assertSame('=VLOOKUP(B2,Kelas!$A$1:$B$4,2,0)', $sel['D2']['r']);
        $this->assertSame('OK', $sel['D6']['s']);
        $this->assertSame(12.0, $sel['D7']['n']);
        $this->assertSame('d', $sel['D12']['s']);
        // TINV(0.05, 12) = nilai Excel (tabel acuan).
        $this->assertSame(2.1788128296672284, $sel['D5']['n']);
        // CF cellIs: "OK" → hijau, gaya tebal milik aturan "TIDAK OK" hilang.
        $this->assertSame(['isi' => '#92D050'], $sel['D6']['g']);

        // Sel yang nilainya tidak berubah TIDAK dikirim (D3/D4 bacaan tak diedit, D10/D11).
        foreach (['D3', 'D4', 'D10', 'D11', 'C2'] as $tetap) {
            $this->assertArrayNotHasKey($tetap, $sel->all(), $tetap);
        }

        // Format sel dari jalur hitung = format GET lembar (bandingkan sel yang tidak berubah gayanya).
        $get = collect($this->actingAs($admin)->getJson("/api/studio/workbook/{$this->wb->id}/lembar/{$input->id}")->json('data.sel'))->keyBy('a');
        $this->assertSame(array_keys($get['D7']), array_keys($sel['D7']));
        $this->assertSame($get['D7']['r'], $sel['D7']['r']);
        // Snapshot impor: CF diterapkan dari nilai cache ("TIDAK OK" → merah tebal).
        $this->assertSame(['isi' => '#FF0000', 'tebal' => true], $get['D6']['g']);

        // Tidak menulis apa pun ke DB.
        $this->assertSame(1, WorkbookMaster::query()->count());
        $this->assertSame($get->all(), collect($this->actingAs($admin)->getJson("/api/studio/workbook/{$this->wb->id}/lembar/{$input->id}")->json('data.sel'))->keyBy('a')->all());
    }

    public function test_tanpa_edit_hanya_sel_bergantung_now_dan_now_sama_dengan_waktu_acuan(): void
    {
        $admin = $this->pengguna($this->lab1, User::ROLE_ADMIN);
        $input = $this->lembar('INPUT DATA');

        $data = $this->actingAs($admin)->postJson("/api/studio/workbook/{$this->wb->id}/hitung", [
            'edit' => [],
            'waktu_acuan' => '2026-10-14T10:00:00+07:00',
        ])->assertOk()->json('data');

        $sel = collect($data['lembar'][0]['sel'] ?? [])->keyBy('a');
        $this->assertSame(['D8', 'D9'], $sel->keys()->sort()->values()->all());
        $serial = (float) SharedDate::dateTimeToExcel(new \DateTimeImmutable('2026-10-14 10:00:00'));
        $this->assertSame($serial, $sel['D8']['n']);
        $this->assertSame($serial + 1, $sel['D9']['n']);
        $this->assertSame(2, $data['jumlah_dihitung']);
        $this->assertSame($input->id, $data['lembar'][0]['lembar_id']);
    }

    public function test_izin_empat_peran_viewer_pelanggan_dan_lab_lain(): void
    {
        $input = $this->lembar('INPUT DATA');
        $badan = ['edit' => [['lembar_id' => $input->id, 'a' => 'B3', 'isi' => '21.5']], 'waktu_acuan' => '2026-10-14T10:00:00+07:00'];
        $uri = "/api/studio/workbook/{$this->wb->id}/hitung";

        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_SUPER_ADMIN] as $role) {
            $this->actingAs($this->pengguna($this->lab1, $role))->postJson($uri, $badan)->assertOk();
            $this->app['auth']->forgetGuards();
        }

        $this->actingAs($this->pengguna($this->lab1, User::ROLE_VIEWER))->postJson($uri, $badan)->assertForbidden();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($this->pengguna($this->lab1, User::ROLE_PELANGGAN), ['internal']);
        $this->postJson($uri, $badan)->assertForbidden();
        $this->app['auth']->forgetGuards();

        // Lab lain: 404 untuk admin dan super admin lab itu (API belum lintas organisasi).
        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $role) {
            $this->actingAs($this->pengguna($this->lab2, $role))->postJson($uri, $badan)->assertNotFound();
            $this->app['auth']->forgetGuards();
        }

        $this->postJson($uri, $badan)->assertUnauthorized();

        // Izin tercermin di matriks: viewer tidak dapat `workbook.hitung`.
        $boleh = $this->actingAs($this->pengguna($this->lab1, User::ROLE_TEKNISI))->getJson('/api/me/permissions')->json('data.boleh');
        $this->assertContains('workbook.hitung', $boleh);
        $this->app['auth']->forgetGuards();
        $boleh = $this->actingAs($this->pengguna($this->lab1, User::ROLE_VIEWER))->getJson('/api/me/permissions')->json('data.boleh');
        $this->assertNotContains('workbook.hitung', $boleh);
    }

    public function test_daftar_putih_fungsi_dan_batas_request_422(): void
    {
        $admin = $this->pengguna($this->lab1, User::ROLE_ADMIN);
        $input = $this->lembar('INPUT DATA');
        $uri = "/api/studio/workbook/{$this->wb->id}/hitung";
        $satu = static fn (string $isi, string $a = 'H1', ?int $lembar = null): array => ['edit' => [['lembar_id' => $lembar ?? $input->id, 'a' => $a, 'isi' => $isi]]];

        foreach (['=WEBSERVICE("http://127.0.0.1/")', '=FILTERXML("<a/>","//a")', '=CELL("filename")', '=INFO("system")', '=RAND()', '=CALL("x")', '=SUM(1,2)+WEBSERVICE("x")'] as $rumus) {
            $this->actingAs($admin)->postJson($uri, $satu($rumus))->assertStatus(422)
                ->assertJsonPath('kode', 'edit_tidak_valid')
                ->assertJsonStructure(['errors' => ['edit.0.isi']]);
        }

        // Rumus yang tidak bisa diurai, terlalu panjang, alamat tidak valid, lembar workbook lain.
        $this->actingAs($admin)->postJson($uri, $satu('=SUM(1,'))->assertStatus(422);
        $this->actingAs($admin)->postJson($uri, $satu('='.str_repeat('1+', 4096).'1'))->assertStatus(422);
        $this->actingAs($admin)->postJson($uri, $satu('5', 'ZZZZ1'))->assertStatus(422);
        $this->actingAs($admin)->postJson($uri, $satu('5', 'A0'))->assertStatus(422);
        $lain = WorkbookMasterLembar::query()->create([
            'workbook_master_id' => WorkbookMaster::query()->create([
                'organization_id' => $this->lab1->id, 'nama' => 'lain', 'sumber' => 'csv', 'sha256' => str_repeat('a', 64), 'ukuran' => 1, 'jumlah_lembar' => 1,
            ])->id,
            'urutan' => 1, 'nama' => 'X', 'tata_letak' => [], 'sel' => '[]',
        ]);
        $this->actingAs($admin)->postJson($uri, $satu('5', 'A1', $lain->id))->assertStatus(422)->assertJsonPath('kode', 'edit_tidak_valid');

        // Maks 300 edit.
        $banyak = array_map(static fn (int $i): array => ['lembar_id' => $input->id, 'a' => 'H'.($i + 1), 'isi' => (string) $i], range(0, 300));
        $this->actingAs($admin)->postJson($uri, ['edit' => $banyak])->assertStatus(422)->assertJsonValidationErrors('edit');
        $this->actingAs($admin)->postJson($uri, ['edit' => array_slice($banyak, 0, 300)])->assertOk();

        // Rumus yang boleh tetap jalan; nilai baru dari rumus edit.
        $sel = collect($this->actingAs($admin)->postJson($uri, $satu('=SQRT(B4*2)+INDEX(Kelas!A1:A4,2)'))->assertOk()->json('data.lembar.0.sel'))->keyBy('a');
        $this->assertSame(sqrt(116) + 2, $sel['H1']['n']);
        $this->assertSame('=SQRT(B4*2)+INDEX(Kelas!A1:A4,2)', $sel['H1']['r']);
    }

    public function test_csv_409_dan_batas_waktu_504(): void
    {
        $admin = $this->pengguna($this->lab1, User::ROLE_ADMIN);
        $csv = WorkbookMaster::query()->create([
            'organization_id' => $this->lab1->id, 'nama' => 'csv', 'sumber' => 'csv', 'sha256' => str_repeat('b', 64), 'ukuran' => 1, 'jumlah_lembar' => 0,
        ]);
        $this->actingAs($admin)->postJson("/api/studio/workbook/{$csv->id}/hitung", ['edit' => []])
            ->assertStatus(409)->assertJsonPath('kode', 'berkas_asli_tidak_ada');

        config(['studio.hitung.batas_detik' => 0.0]);
        $this->actingAs($admin)->postJson("/api/studio/workbook/{$this->wb->id}/hitung", [
            'edit' => [['lembar_id' => $this->lembar('INPUT DATA')->id, 'a' => 'B2', 'isi' => '1']],
        ])->assertStatus(504)->assertJsonPath('kode', 'hitung_terlalu_lama');
    }

    public function test_cache_model_hilang_dibangun_ulang_dari_berkas_enc(): void
    {
        $admin = $this->pengguna($this->lab1, User::ROLE_ADMIN);
        Storage::disk('local')->delete(SumberModelHitung::jalur($this->wb->sha256));
        SumberModelHitung::lupakan();

        $this->actingAs($admin)->postJson("/api/studio/workbook/{$this->wb->id}/hitung", [
            'edit' => [['lembar_id' => $this->lembar('INPUT DATA')->id, 'a' => 'B2', 'isi' => '4']],
        ])->assertOk()->assertJsonPath('data.galat', []);

        $this->assertTrue(Storage::disk('local')->exists(SumberModelHitung::jalur($this->wb->sha256)));
        $this->assertSame([], $this->isiTemp(), 'temp dekripsi berkas .enc dibersihkan');
    }

    public function test_sel_kotor_dan_hitung_penuh_hasilnya_identik(): void
    {
        [$asli, $graf] = app(SumberModelHitung::class)->ambil($this->wb);
        $mesin = app(MesinHitung::class);
        $waktu = 46309.5;

        // Cache fixture ditulis PhpSpreadsheet (STDEV satu lintasan, TINV Newton, NOW saat
        // menulis), bukan Excel. Supaya yang diuji hanya logika "sel kotor", cache diganti
        // dulu dengan hasil mesin penuh tanpa edit — keadaan yang sama dengan workbook Excel
        // yang cache-nya identik dengan mesin.
        $nol = $mesin->hitung($asli, $graf, [], $waktu, true, microtime(true) + 60);
        $lembar = $asli->lembar;

        foreach ($lembar as $i => $l) {
            foreach ($l['sel'] as $a => $s) {
                if ($s[0] === 'f') {
                    $lembar[$i]['sel'][$a][2] = $nol->nilai[$l['judul'].'!'.$a] ?? null;
                }
            }
        }

        $model = new ModelHitung($lembar, $asli->nama, $asli->kalender);
        $skenario = [
            [],
            [['INPUT DATA', 'B2', '3']],
            [['INPUT DATA', 'C5', '1000.00031'], ['INPUT DATA', 'B3', '19.75']],
            [['INPUT DATA', 'B4', '61'], ['Data', 'A2', '7'], ['Kelas', 'B3', 'M2']],
            [['INPUT DATA', 'H5', '=D3*2+D11'], ['INPUT DATA', 'C2', '']],
        ];

        foreach ($skenario as $i => $daftar) {
            $edit = array_map(static fn (array $e): array => ['judul' => $e[0], 'alamat' => $e[1], 'isi' => IsiSel::dariKetikan($e[2])], $daftar);
            $kotor = $mesin->hitung($model, $graf, $edit, $waktu, false, microtime(true) + 60);
            $penuh = $mesin->hitung($model, $graf, $edit, $waktu, true, microtime(true) + 60);
            $lengkap = $kotor->nilai + $this->cache($model);

            foreach ($penuh->nilai as $k => $v) {
                $this->assertSame($v, $lengkap[$k] ?? null, "skenario {$i} sel {$k}");
            }

            $this->assertLessThanOrEqual(count($penuh->kotor), count($kotor->kotor));
        }

        // Tanpa edit: hanya NOW() dan hilirnya yang dihitung.
        $this->assertSame(['INPUT DATA!D8', 'INPUT DATA!D9'], $mesin->hitung($model, $graf, [], $waktu, false, microtime(true) + 60)->kotor);
    }

    public function test_graf_dependensi_nama_rentang_lintas_lembar(): void
    {
        [$model] = app(SumberModelHitung::class)->ambil($this->wb);
        $graf = GrafDependensi::bangun($model);

        $this->assertContains('INPUT DATA!D11', $graf->pengguna('INPUT DATA!B4'), 'nama terdefinisi Faktor → B4');
        $this->assertContains('INPUT DATA!D10', $graf->pengguna('Data!A2'), 'rentang lintas lembar');
        $this->assertContains('INPUT DATA!D2', $graf->pengguna('Kelas!B3'), 'VLOOKUP ke lembar tersembunyi');
        $this->assertSame(['INPUT DATA!D8'], $graf->volatil);
        $this->assertSame([], $graf->dinamis);
        // D5 = TINV(.., D7), D7 = 9+B2 → D2, D6, D5, D7, D12 hilir B2.
        $hilir = $graf->kotor(['INPUT DATA!B2']);
        foreach (['INPUT DATA!D2', 'INPUT DATA!D6', 'INPUT DATA!D7', 'INPUT DATA!D5', 'INPUT DATA!D12'] as $k) {
            $this->assertContains($k, $hilir);
        }
        $this->assertLessThan(array_search('INPUT DATA!D5', $hilir, true), array_search('INPUT DATA!D7', $hilir, true), 'urutan topologis: pendahulu dulu');
        $this->assertNotContains('INPUT DATA!D3', $hilir);
    }

    private function lembar(string $nama): WorkbookMasterLembar
    {
        return $this->wb->lembar()->where('nama', $nama)->sole();
    }

    /** @return array<string, mixed> */
    private function cache(ModelHitung $model): array
    {
        $hasil = [];

        foreach ($model->lembar as $l) {
            foreach ($l['sel'] as $a => $s) {
                if ($s[0] === 'f') {
                    $hasil[$l['judul'].'!'.$a] = $s[2];
                }
            }
        }

        return $hasil;
    }

    private function pengguna(Organization $lab, string $role): User
    {
        return User::factory()->create(['organization_id' => $lab->id, 'role' => $role, 'status' => User::STATUS_AKTIF]);
    }
}
