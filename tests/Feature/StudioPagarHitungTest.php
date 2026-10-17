<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use App\Services\Studio\Hitung\GagalHitung;
use App\Services\Studio\Hitung\GrafDependensi;
use App\Services\Studio\Hitung\KalkulasiBerpagar;
use App\Services\Studio\Hitung\MesinHitung;
use App\Services\Studio\Hitung\ModelHitung;
use App\Services\Studio\Hitung\PagarMemori;
use App\Services\Studio\Hitung\SumberModelHitung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * Studio tahap C — pagar DoS `POST /studio/workbook/{w}/hitung` (tinjau C #1).
 *
 * Tiga lapis yang dikunci di sini:
 *  1. rumus EDIT ditimbang sebelum dievaluasi (`PagarRumus`): rentang raksasa,
 *     kolom/baris penuh (dipotong ke area terpakai SESUDAH edit), pasangan
 *     rentang kuadratik, dan rujukan dinamis → 422 `rumus_terlalu_berat`;
 *  2. mesin berpagar (`KalkulasiBerpagar`) untuk SEMUA rumus: rentang > batas
 *     tidak dirinci (galat sel, bukan PHP Fatal) — rumus asli INDIRECT/OFFSET
 *     tetap jalan;
 *  3. batas waktu juga diperiksa di DALAM evaluasi satu sel → 504, bukan hang.
 *
 * Plus 429 `studio-hitung` membawa `Retry-After`. Fixture SINTETIS.
 */
class StudioPagarHitungTest extends TestCase
{
    use RefreshDatabase;
    use WorkbookUji;

    private Organization $lab;

    private User $admin;

    private WorkbookMaster $wb;

    private WorkbookMasterLembar $input;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        SumberModelHitung::lupakan();
        $this->siapkanFolderUji();
        $this->lab = Organization::factory()->create();
        $this->admin = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_AKTIF]);

        $this->artisan('studio:impor-workbook', ['--xlsm' => [$this->buatXlsxHitung()], '--organisasi' => (string) $this->lab->id, '--oleh' => $this->admin->email])
            ->assertSuccessful();

        $this->wb = WorkbookMaster::query()->sole();
        $this->input = $this->wb->lembar()->where('nama', 'INPUT DATA')->sole();
    }

    protected function tearDown(): void
    {
        SumberModelHitung::lupakan();
        $this->bersihkanFolderUji();
        parent::tearDown();
    }

    public function test_probe_dos_ditolak_422_rumus_terlalu_berat_dengan_indeks_edit_yang_benar(): void
    {
        $probe = [
            '=SUM(A1:XFD1048576)' => 'rumus_terlalu_berat',
            // Baris penuh ditolak lebih dulu: mesin memotongnya beda dari Excel.
            '=SUMPRODUCT(ROW(1:1048576))' => 'edit_tidak_valid',
            '=SUM(INDIRECT("A1:XFD1048576"))' => 'rumus_terlalu_berat',
            '=SUM(OFFSET(A1,0,0,1048576,16384))' => 'rumus_terlalu_berat',
            '=SUM(A1:INDEX(C:C,5))' => 'rumus_terlalu_berat',
            // Kuadratik: MATCH larik 2.000×2.000 = 61 s dalam satu sel (diukur), siaran 1.000×1.000.
            '=SUMPRODUCT(IFERROR(MATCH(ROW(A1:A2000),ROW(B1:B2000),0),0))' => 'rumus_terlalu_berat',
            '=SUMPRODUCT(A1:A1000*TRANSPOSE(B1:B1000))' => 'rumus_terlalu_berat',
        ];

        foreach ($probe as $rumus => $kode) {
            $mulai = microtime(true);
            $respons = $this->hitung([['B2', '3'], ['H1', $rumus]])
                ->assertStatus(422)
                ->assertJsonPath('kode', $kode);
            $this->assertSame(['edit.1.isi'], array_keys($respons->json('errors')), $rumus);
            $this->assertIsString($respons->json('message'));
            // Ditolak sebelum mesin jalan, bukan sesudah menghabiskan CPU.
            $this->assertLessThan(5.0, microtime(true) - $mulai, $rumus);
        }
    }

    public function test_kolom_penuh_dipotong_ke_area_terpakai_sum_sama_dengan_rentang_eksplisit(): void
    {
        $penuh = $this->sel($this->hitung([['H1', '=SUM(C:C)']])->assertOk(), 'H1');
        $eksplisit = $this->sel($this->hitung([['H1', '=SUM(C1:C12)']])->assertOk(), 'H1');
        $this->assertSame($eksplisit['n'], $penuh['n']);
        $this->assertSame('=SUM(C:C)', $penuh['r']);
        $this->hitung([['H5', '=COUNT(B:D)+SUM(Data!A:A)']])->assertOk()->assertJsonPath('data.galat', []);
        $this->hitung([['H5', '=SUM(1:2)']])->assertStatus(422)->assertJsonPath('kode', 'edit_tidak_valid');

        // INPUT DATA terisi sampai baris 12 → C:C = 12 sel. Batas tepat 12 lolos, 11 ditolak.
        config(['studio.hitung.maks_sel_rumus_edit' => 12]);
        $this->hitung([['H1', '=SUM(C:C)']])->assertOk();
        config(['studio.hitung.maks_sel_rumus_edit' => 11]);
        $this->hitung([['H1', '=SUM(C:C)']])->assertStatus(422)->assertJsonPath('kode', 'rumus_terlalu_berat');

        // Edit jauh di bawah memperluas area terpakai: C:C jadi sejuta baris → ditolak.
        config(['studio.hitung.maks_sel_rumus_edit' => 10000]);
        $this->hitung([['C1048576', '1'], ['H1', '=SUM(C:C)']])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'rumus_terlalu_berat')
            ->assertJsonStructure(['errors' => ['edit.1.isi']]);
    }

    public function test_rumus_normal_tetap_200_dan_batas_pasangan_tepat(): void
    {
        $data = $this->hitung([
            ['H1', '=SUM(C2:C11)*2'],
            ['H2', '=VLOOKUP(B2,Kelas!$A$1:$B$4,2,0)'],
            ['H3', '=Faktor*2'],
            ['H4', '=AVERAGE(Data!A1:A3)'],
        ])->assertOk()->json('data');

        $this->assertSame([], $data['galat']);
        $sel = collect($data['lembar'][0]['sel'])->keyBy('a');
        $this->assertSame('F2', $sel['H2']['s']);
        $this->assertSame(116.0, $sel['H3']['n']);
        $this->assertSame((1.5 + 2.25 + 3.125) / 3, $sel['H4']['n']);

        // Pasangan = hasil kali dua rentang terbesar: 10×10 = 100 lolos, 10×11 = 110 ditolak.
        config(['studio.hitung.maks_pasangan_rumus_edit' => 100]);
        $this->hitung([['H1', '=SUMPRODUCT(C2:C11*TRANSPOSE(C2:C11))']])->assertOk();
        $this->hitung([['H1', '=SUMPRODUCT(C2:C11*TRANSPOSE(C1:C11))']])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'rumus_terlalu_berat');
    }

    public function test_rumus_asli_indirect_offset_tetap_jalan_dan_rentang_dinamis_raksasa_jadi_galat_sel(): void
    {
        $wb = $this->imporWorkbookDinamis();
        $lembar = $wb->lembar()->where('nama', 'Dinamis')->sole();
        $uri = "/api/studio/workbook/{$wb->id}/hitung";
        $edit = static fn (array $daftar): array => ['edit' => array_map(
            static fn (array $e): array => ['lembar_id' => $lembar->id, 'a' => $e[0], 'isi' => $e[1]],
            $daftar,
        )];

        // Rumus asli INDIRECT / OFFSET dihitung ulang dari input baru.
        $data = $this->sebagaiStudio($this->admin)->postJson($uri, $edit([['B1', '4']]))->assertOk()->json('data');
        $sel = collect($data['lembar'][0]['sel'])->keyBy('a');
        $this->assertSame([], $data['galat']);

        foreach (['C1', 'C2'] as $a) {
            $this->assertSame(10.0, $sel[$a]['n'], $a);
        }

        // Mengetik ulang rumus asli apa adanya boleh; mengubahnya (masih dinamis) tidak.
        $this->sebagaiStudio($this->admin)->postJson($uri, $edit([['B1', '2'], ['C1', '=SUM(INDIRECT("A1:A"&B1))']]))->assertOk();
        $this->sebagaiStudio($this->admin)->postJson($uri, $edit([['C1', '=SUM(INDIRECT("A1:A"&B1))+0']]))
            ->assertStatus(422)->assertJsonPath('kode', 'rumus_terlalu_berat');
        $this->sebagaiStudio($this->admin)->postJson($uri, $edit([['D1', '=SUM(OFFSET(A1,0,0,2,1))']]))
            ->assertStatus(422)->assertJsonPath('kode', 'rumus_terlalu_berat');

        // Input yang membuat rentang dinamis rumus ASLI jadi sejuta baris: tidak dirinci,
        // sel itu jadi galat (nilai cache Excel), request tetap 200 dan cepat.
        $mulai = microtime(true);
        $data = $this->sebagaiStudio($this->admin)->postJson($uri, $edit([['B1', '1048576']]))->assertOk()->json('data');
        $this->assertLessThan(10.0, microtime(true) - $mulai);
        $galat = collect($data['galat'])->keyBy('a');
        $this->assertStringContainsString('melebihi batas', $galat['C1']['pesan']);
        $this->assertStringContainsString('melebihi batas', $galat['C2']['pesan']);
    }

    public function test_rentang_raksasa_di_mesin_jadi_galat_sel_bukan_php_fatal(): void
    {
        $model = $this->model([
            'A1' => ['f', '=SUM(B1:XFD1048576)', 7.0, null, null],
            'A2' => ['f', '=A1+1', 8.0, null, null],
            'B1' => ['n', 1.0, null, null, null],
        ]);

        $hasil = app(MesinHitung::class)->hitung($model, GrafDependensi::bangun($model), [], 46309.5, true, microtime(true) + 30);

        $this->assertSame(7.0, $hasil->nilai['S!A1'], 'nilai cache Excel dipakai');
        // B1:XFD1048576 = 16.383 × 1.048.576 alamat — ditolak sebelum dirinci.
        $this->assertStringContainsString('17178820608 sel melebihi batas', $hasil->galat['S!A1']);
        $this->assertSame(8.0, $hasil->nilai['S!A2']);
        $this->assertSame('Dihitung dari nilai lama sel yang gagal dihitung.', $hasil->galat['S!A2']);
    }

    /**
     * Tinjau C-2 #1: bom lewat ARGUMEN SKALAR tidak menyentuh rentang, jadi
     * tidak tertangkap `PagarRumus`. Pagar runtime fungsi (`BatasFungsiExcel`)
     * memulangkan galat Excel — lewat rumus EDIT maupun lewat nilai sunting
     * yang masuk ke rumus ASLI workbook. Sebelumnya: PHP Fatal memori / > 40 s.
     */
    public function test_bom_argumen_skalar_jadi_galat_excel_lewat_rumus_edit_dan_nilai_sunting(): void
    {
        $probe = [
            '=FIXED(1,1000000000)' => '#VALUE!',
            '=FACT(1E9)' => '#NUM!',
            '=COMBIN(1E9,5E8)' => '#NUM!',
            '=LEN(REPT("abcdefghij",5E7))' => '#VALUE!',
        ];

        foreach ($probe as $rumus => $galat) {
            $mulai = microtime(true);
            $respons = $this->hitung([['H1', $rumus]])->assertOk();
            $this->assertLessThan(5.0, microtime(true) - $mulai, $rumus);
            $this->assertSame($galat, $this->sel($respons, 'H1')['s'], $rumus);
        }

        $wb = $this->imporWorkbookBom();
        $lembar = $wb->lembar()->where('nama', 'Bom')->sole();
        $mulai = microtime(true);
        $data = $this->sebagaiStudio($this->admin)->postJson("/api/studio/workbook/{$wb->id}/hitung", ['edit' => [
            ['lembar_id' => $lembar->id, 'a' => 'B1', 'isi' => '1000000000'],
        ]])->assertOk()->json('data');

        $this->assertLessThan(5.0, microtime(true) - $mulai);
        $this->assertSame([], $data['galat']);
        $sel = collect($data['lembar'][0]['sel'])->keyBy('a');
        $this->assertSame(
            ['C1' => '#VALUE!', 'C2' => '#VALUE!', 'C3' => '#NUM!', 'C5' => '#NUM!'],
            ['C1' => $sel['C1']['s'], 'C2' => $sel['C2']['s'], 'C3' => $sel['C3']['s'], 'C5' => $sel['C5']['s']],
        );
        // Argumen besar yang SAH tetap dihitung (Excel COM: COMBIN(1E9,2) = 4,999999995E+17).
        $this->assertSame(4.999999995E+17, $sel['C4']['n']);
    }

    /**
     * Tinjau C-3 #1: amplifikasi TEKS atas larik. Tiap unsur sah (≤ 32.767
     * huruf) dan `&`/siaran dijalankan PhpSpreadsheet dalam satu operasi yang
     * tidak bisa diputus — terukur +366 MB untuk 10.000 unsur. Rumus edit
     * seperti ini ditolak SEBELUM mesin jalan; yang wajar tetap 200.
     */
    public function test_bom_amplifikasi_teks_rumus_edit_ditolak_422_sebelum_mesin_jalan(): void
    {
        $probe = [
            '=SUMPRODUCT(LEN(REPT("a",32767)&ROW(A1:A10000)))',
            '=SUMPRODUCT(LEN(REPT("a",32767+0*ROW(A1:A10000))))',
            '=SUMPRODUCT(LEN(MID(REPT("a",32767),ROW(A1:A10000),32767)))',
            // Siaran 100×100 lewat TRANSPOSE: rentangnya kecil, lariknya 10.000 unsur.
            '=SUMPRODUCT(LEN(REPT("a",32767)&(ROW(A1:A100)*TRANSPOSE(ROW(A1:A100)))))',
            // Siaran kolom × baris TANPA TRANSPOSE: 100 × 100.
            '=SUMPRODUCT(LEN(UPPER(A1:A100&B1:CV1)))',
        ];

        foreach ($probe as $rumus) {
            $mulai = microtime(true);
            $respons = $this->hitung([['B2', '3'], ['H1', $rumus]])
                ->assertStatus(422)
                ->assertJsonPath('kode', 'rumus_terlalu_berat');
            $this->assertSame(['edit.1.isi'], array_keys($respons->json('errors')), $rumus);
            $this->assertStringContainsString('membangun teks', $respons->json('errors')['edit.1.isi'][0], $rumus);
            $this->assertLessThan(5.0, microtime(true) - $mulai, $rumus);
        }

        // Varian tinjauan: teks 32 rb di sel yang diedit, lalu digandeng larik.
        $this->hitung([['Z1', str_repeat('a', 32767)], ['H1', '=SUMPRODUCT(LEN(Z1&ROW(A1:A10000)))']])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'rumus_terlalu_berat')
            ->assertJsonStructure(['errors' => ['edit.1.isi']]);

        // Wajar tetap jalan: `&` skalar sebanyak apa pun, larik 2.000 dengan satu operasi teks.
        $this->assertSame('Judul uji|2|20.4', $this->sel($this->hitung([['H1', '=A1&"|"&B2&"|"&B3']])->assertOk(), 'H1')['s']);
        // "Judul uji" & "x" = 10 huruf + 1.999 sel kosong & "x" = 1 huruf.
        $this->assertSame(2009.0, $this->sel($this->hitung([['H1', '=SUMPRODUCT(LEN(A1:A2000&"x"))']])->assertOk(), 'H1')['n']);

        // Batas tepat: 1 operasi × 2.000 = 2.000 lolos; 3 operasi × 2.000 = 6.000 > 4.000 ditolak.
        config(['studio.hitung.maks_larik_teks_rumus_edit' => 2000]);
        $this->hitung([['H1', '=SUMPRODUCT(LEN(C1:C2000&"x"))']])->assertOk();
        $this->hitung([['H1', '=SUMPRODUCT(LEN(C1:C2001&"x"))']])->assertStatus(422)->assertJsonPath('kode', 'rumus_terlalu_berat');
    }

    /**
     * Tinjau C-3 #1, pagar LUNAK: rumus ASLI workbook tidak ditimbang
     * `PagarRumus`, jadi nilai sunting yang meledakkannya diputus di tengah
     * evaluasi (per unsur REPT) → 422 `hitung_terlalu_berat`, bukan PHP Fatal.
     */
    public function test_pagar_memori_lunak_memutus_rumus_asli_jadi_422_hitung_terlalu_berat(): void
    {
        $wb = $this->imporWorkbookBomTeks();
        $lembar = $wb->lembar()->where('nama', 'Teks')->sole();
        $uri = "/api/studio/workbook/{$wb->id}/hitung";
        $kirim = fn (string $b1) => $this->sebagaiStudio($this->admin)->postJson($uri, ['edit' => [
            ['lembar_id' => $lembar->id, 'a' => 'B1', 'isi' => $b1],
        ]]);

        // Wajar: 10.000 unsur × 5 huruf tetap dihitung.
        $sel = collect($kirim('5')->assertOk()->json('data.lembar.0.sel'))->keyBy('a');
        $this->assertSame(50000.0, $sel['C1']['n']);

        // Ambang lunak = pemakaian sekarang + 48 MB (bawaan 0,8 × memory_limit; proses
        // test berbagi memori dengan PHPUnit). B1 = 32.767 → 10.000 × 32 KB = 320 MB
        // → diputus di tengah REPT larik.
        $batas = PagarMemori::byte((string) ini_get('memory_limit'));
        $this->assertGreaterThan(0, $batas);
        $bawaan = config('studio.hitung.ambang_memori');
        config(['studio.hitung.ambang_memori' => (memory_get_usage(true) + 48 * 1024 * 1024) / $batas]);

        $mulai = microtime(true);
        memory_reset_peak_usage();
        $sebelum = memory_get_usage(true);
        $respons = $kirim('32767')->assertStatus(422)->assertJsonPath('kode', 'hitung_terlalu_berat');

        $this->assertIsString($respons->json('message'));
        $this->assertNull($respons->json('errors'));
        $this->assertLessThan(5.0, microtime(true) - $mulai);
        // Diputus dekat ambang, bukan sesudah 320 MB terbentuk.
        $this->assertLessThan(96 * 1024 * 1024, memory_get_peak_usage(true) - $sebelum);
        $this->assertSame(PHP_INT_MAX, PagarMemori::ambang(), 'pagar dilepas sesudah hitung');

        // Request berikutnya bersih lagi.
        config(['studio.hitung.ambang_memori' => $bawaan]);
        $this->assertSame(50000.0, collect($kirim('5')->assertOk()->json('data.lembar.0.sel'))->keyBy('a')['C1']['n']);
    }

    public function test_pagar_memori_di_baca_rentang_mesin(): void
    {
        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([[1, 2], [3, 4]]);
        $ss->getActiveSheet()->setCellValue('C1', '=SUM(A1:B2)');
        KalkulasiBerpagar::pasang($ss, microtime(true) + 30, 100);
        config(['studio.hitung.ambang_memori' => 0.000001]);
        PagarMemori::pasang();

        try {
            $ss->getActiveSheet()->getCell('C1')->getCalculatedValue();
            $this->fail('Pagar memori tidak memutus baca rentang.');
        } catch (\Throwable $e) {
            $gagal = null;

            for ($x = $e; $x !== null; $x = $x->getPrevious()) {
                $gagal = $x instanceof GagalHitung ? $x : $gagal;
            }

            $this->assertNotNull($gagal, get_class($e).': '.$e->getMessage());
            $this->assertSame('hitung_terlalu_berat', $gagal->kode);
            $this->assertSame(422, $gagal->status);
        } finally {
            PagarMemori::lepas();
        }

        // Tanpa pagar (dilepas) rumus yang sama dihitung biasa.
        Calculation::getInstance($ss)->clearCalculationCache();
        $this->assertSame(10, $ss->getActiveSheet()->getCell('C1')->getCalculatedValue());
        $ss->disconnectWorksheets();
        $this->assertSame(384 * 1024 * 1024, PagarMemori::byte('384M'));
        $this->assertSame(-1, PagarMemori::byte('-1'));
    }

    public function test_batas_waktu_diperiksa_di_dalam_evaluasi_satu_sel(): void
    {
        // Satu sel, 12 rentang 20 rb sel: penuh ±10 s. Batas 0,3 s harus memutusnya di
        // tengah sel (sesudah rentang pertama), bukan sesudah sel selesai.
        $suku = implode('+', array_map(static fn (string $k): string => "SUM({$k}1:{$k}20000)", range('B', 'M')));
        $model = $this->model(['A1' => ['f', '='.$suku, null, null, null]]);
        $graf = GrafDependensi::bangun($model);

        $mulai = microtime(true);

        try {
            app(MesinHitung::class)->hitung($model, $graf, [], 46309.5, true, microtime(true) + 0.3);
            $this->fail('Batas waktu tidak memutus evaluasi.');
        } catch (GagalHitung $e) {
            $this->assertSame('hitung_terlalu_lama', $e->kode);
            $this->assertSame(504, $e->status);
        }

        $this->assertLessThan(5.0, microtime(true) - $mulai);
    }

    public function test_jumlah_sel_rentang_mesin(): void
    {
        $this->assertSame(1, KalkulasiBerpagar::jumlahSel('A1'));
        $this->assertSame(1, KalkulasiBerpagar::jumlahSel("'INPUT DATA'!B4"));
        $this->assertSame(4, KalkulasiBerpagar::jumlahSel("'My Sheet'!A1:B2"));
        $this->assertSame(7, KalkulasiBerpagar::jumlahSel('A1:B2,C1:C3'));
        $this->assertSame(16384 * 1048576, KalkulasiBerpagar::jumlahSel('$A$1:$XFD$1048576'));

        // Mesin pagar terpasang dan tetap memulangkan nilai yang sama untuk rentang wajar.
        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([[1, 2], [3, 4]]);
        $ss->getActiveSheet()->setCellValue('C1', '=SUM(A1:B2)');
        KalkulasiBerpagar::pasang($ss, microtime(true) + 30, 3);
        $this->assertInstanceOf(KalkulasiBerpagar::class, Calculation::getInstance($ss));

        try {
            $ss->getActiveSheet()->getCell('C1')->getCalculatedValue();
            $this->fail('Rentang 4 sel lolos batas 3.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('4 sel melebihi batas 3', $e->getMessage());
        }

        KalkulasiBerpagar::pasang($ss, microtime(true) + 30, 4);
        $this->assertSame(10, $ss->getActiveSheet()->getCell('C1')->getCalculatedValue());
        $ss->disconnectWorksheets();
    }

    public function test_429_studio_hitung_membawa_retry_after(): void
    {
        $uri = "/api/studio/workbook/{$this->wb->id}/hitung";

        // Jatah 30/menit/pengguna; request yang gagal validasi tetap dihitung.
        for ($i = 0; $i < 30; $i++) {
            $this->sebagaiStudio($this->admin)->postJson($uri, [])->assertStatus(422);
        }

        $respons = $this->sebagaiStudio($this->admin)->postJson($uri, ['edit' => []])->assertStatus(429);
        $this->assertNotNull($respons->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $respons->headers->get('Retry-After'));
        $this->assertSame('30', (string) $respons->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', (string) $respons->headers->get('X-RateLimit-Remaining'));
        $this->assertIsString($respons->json('message'));
    }

    /**
     * @param  list<array{0: string, 1: string}>  $edit  [alamat, isi] di INPUT DATA
     */
    private function hitung(array $edit): TestResponse
    {
        return $this->sebagaiStudio($this->admin)->postJson("/api/studio/workbook/{$this->wb->id}/hitung", [
            'edit' => array_map(fn (array $e): array => ['lembar_id' => $this->input->id, 'a' => $e[0], 'isi' => $e[1]], $edit),
            'waktu_acuan' => '2026-10-14T10:00:00+07:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function sel(TestResponse $respons, string $alamat): array
    {
        return collect($respons->json('data.lembar.0.sel'))->keyBy('a')[$alamat];
    }

    /**
     * @param  array<string, array{0: string, 1: mixed, 2: mixed, 3: null, 4: null}>  $sel
     */
    private function model(array $sel): ModelHitung
    {
        return new ModelHitung([['judul' => 'S', 'status' => 'visible', 'sel' => $sel]], [], '1900');
    }

    /** Workbook kecil dengan rumus ASLI sah yang argumennya dari sel input B1. */
    private function imporWorkbookBom(): WorkbookMaster
    {
        $ss = new Spreadsheet;
        $ws = $ss->getActiveSheet();
        $ws->setTitle('Bom');
        $ws->setCellValue('B1', 3);
        $ws->setCellValue('C1', '=REPT("-",B1)');
        $ws->setCellValue('C2', '=FIXED(1,B1)');
        $ws->setCellValue('C3', '=FACT(B1)');
        $ws->setCellValue('C4', '=COMBIN(B1,2)');
        $ws->setCellValue('C5', '=COMBIN(B1,B1/2)');
        $jalur = $this->folderUji.DIRECTORY_SEPARATOR.'Lembar Bom Uji.xlsx';
        (new Xlsx($ss))->save($jalur);
        $ss->disconnectWorksheets();

        $this->artisan('studio:impor-workbook', ['--xlsm' => [$jalur], '--organisasi' => (string) $this->lab->id, '--oleh' => $this->admin->email])
            ->assertSuccessful();

        return WorkbookMaster::query()->where('nama', 'Lembar Bom Uji')->sole();
    }

    /** Workbook kecil dengan rumus ASLI teks-per-unsur atas larik 10.000 (sah di Excel). */
    private function imporWorkbookBomTeks(): WorkbookMaster
    {
        $ss = new Spreadsheet;
        $ws = $ss->getActiveSheet();
        $ws->setTitle('Teks');
        $ws->setCellValue('B1', 3);
        $ws->setCellValue('C1', '=SUMPRODUCT(LEN(REPT("a",B1+0*ROW(A1:A10000))))');
        $jalur = $this->folderUji.DIRECTORY_SEPARATOR.'Lembar Bom Teks Uji.xlsx';
        (new Xlsx($ss))->save($jalur);
        $ss->disconnectWorksheets();

        $this->artisan('studio:impor-workbook', ['--xlsm' => [$jalur], '--organisasi' => (string) $this->lab->id, '--oleh' => $this->admin->email])
            ->assertSuccessful();

        return WorkbookMaster::query()->where('nama', 'Lembar Bom Teks Uji')->sole();
    }

    /** Workbook kecil dengan rumus ASLI INDIRECT dan OFFSET. */
    private function imporWorkbookDinamis(): WorkbookMaster
    {
        $ss = new Spreadsheet;
        $ws = $ss->getActiveSheet();
        $ws->setTitle('Dinamis');

        foreach ([1, 2, 3, 4, 5] as $i => $v) {
            $ws->setCellValue('A'.($i + 1), $v);
        }

        $ws->setCellValue('B1', 3);
        $ws->setCellValue('C1', '=SUM(INDIRECT("A1:A"&B1))');
        $ws->setCellValue('C2', '=SUM(OFFSET(A1,0,0,B1,1))');
        $jalur = $this->folderUji.DIRECTORY_SEPARATOR.'Lembar Dinamis Uji.xlsx';
        (new Xlsx($ss))->save($jalur);
        $ss->disconnectWorksheets();

        $this->artisan('studio:impor-workbook', ['--xlsm' => [$jalur], '--organisasi' => (string) $this->lab->id, '--oleh' => $this->admin->email])
            ->assertSuccessful();

        $wb = WorkbookMaster::query()->where('nama', 'like', '%Dinamis%')->sole();
        [, $graf] = app(SumberModelHitung::class)->ambil($wb);
        $this->assertEqualsCanonicalizing(['Dinamis!C1', 'Dinamis!C2'], $graf->dinamis);

        return $wb;
    }
}
