<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use App\Services\Studio\Hitung\SumberModelHitung;
use App\Services\Studio\Workbook\KanonikWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * K-49-15 (15 Okt 2026): proteksi lembar Excel ditegakkan di Studio.
 *
 *  - impor `.xlsx` mengekstrak `tata_letak.dilindungi` + `sel_tidak_terkunci`
 *    (format kanonik 5), termasuk kolom/baris yang dibuka lewat gaya
 *    kolom/baris utuh;
 *  - `/hitung`: sel TERKUNCI di lembar dilindungi → 422 `sel_terkunci` untuk
 *    admin/teknisi, 200 untuk super_admin; sel tidak terkunci dan lembar
 *    tanpa proteksi tetap boleh;
 *  - baris lama tanpa kunci proteksi = tidak dilindungi.
 *
 * Fixture SINTETIS (`WorkbookUji::buatXlsxDilindungi`), bukan workbook lab.
 */
class StudioProteksiLembarTest extends TestCase
{
    use RefreshDatabase;
    use WorkbookUji;

    private const TIDAK_TERKUNCI = ['E1:E3', 'B2:B3', 'B5:C6', 'E5:E1048576', 'A8:E8', 'G8:XFD8'];

    private Organization $lab;

    private WorkbookMaster $wb;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        SumberModelHitung::lupakan();
        $this->siapkanFolderUji();
        $this->lab = Organization::factory()->create();

        $this->artisan('studio:impor-workbook', ['--xlsm' => [$this->buatXlsxDilindungi()], '--organisasi' => (string) $this->lab->id, '--oleh' => $this->olehLab($this->lab)])
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

    public function test_impor_mengekstrak_dilindungi_dan_rentang_sel_tidak_terkunci(): void
    {
        $input = $this->lembar('INPUT DATA')->tata_letak;
        $bebas = $this->lembar('Bebas')->tata_letak;

        $this->assertSame(5, KanonikWorkbook::FORMAT);
        $this->assertTrue($input['dilindungi']);
        // Sel bergaya, sel kosong bergaya, gaya kolom E (kecuali E4), gaya baris 8 (kecuali F8).
        $this->assertSame(self::TIDAK_TERKUNCI, $input['sel_tidak_terkunci']);
        $this->assertFalse($bebas['dilindungi']);
        $this->assertSame([], $bebas['sel_tidak_terkunci']);
        $this->assertSame(array_keys(KanonikWorkbook::tataLetakKosong()), array_keys($input));

        // API detail: tata letak membawa proteksi; izin melewati kunci dari server.
        $admin = $this->pengguna(User::ROLE_ADMIN);
        $detail = $this->actingAs($admin)->getJson("/api/studio/workbook/{$this->wb->id}")->assertOk()->json('data');
        $this->assertFalse($detail['boleh_edit_sel_terkunci']);
        $tata = array_column($detail['lembar'], 'tata_letak', 'nama');
        $this->assertSame([true, self::TIDAK_TERKUNCI], [$tata['INPUT DATA']['dilindungi'], $tata['INPUT DATA']['sel_tidak_terkunci']]);
        $this->assertSame([false, []], [$tata['Bebas']['dilindungi'], $tata['Bebas']['sel_tidak_terkunci']]);
        $this->app['auth']->forgetGuards();

        foreach ([User::ROLE_TEKNISI => false, User::ROLE_SUPER_ADMIN => true] as $role => $boleh) {
            $this->actingAs($this->pengguna($role))->getJson("/api/studio/workbook/{$this->wb->id}")
                ->assertOk()
                ->assertJsonPath('data.boleh_edit_sel_terkunci', $boleh);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_sel_terkunci_ditolak_422_untuk_admin_dan_teknisi(): void
    {
        $id = $this->lembar('INPUT DATA')->id;
        $badan = ['edit' => [
            ['lembar_id' => $id, 'a' => 'B2', 'isi' => '6'],      // tidak terkunci
            ['lembar_id' => $id, 'a' => 'C2', 'isi' => '99'],     // rumus terkunci
            ['lembar_id' => $id, 'a' => 'a20', 'isi' => '1'],     // kosong, xf bawaan → terkunci
            ['lembar_id' => $id, 'a' => 'E4', 'isi' => '2'],      // dikunci di dalam kolom terbuka
            ['lembar_id' => $id, 'a' => '$F$8', 'isi' => ''],     // dikunci di dalam baris terbuka
        ]];

        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI] as $role) {
            $respons = $this->actingAs($this->pengguna($role))
                ->postJson("/api/studio/workbook/{$this->wb->id}/hitung", $badan)
                ->assertStatus(422)
                ->assertJsonPath('kode', 'sel_terkunci');

            $this->assertSame([
                ['lembar_id' => $id, 'a' => 'C2'],
                ['lembar_id' => $id, 'a' => 'A20'],
                ['lembar_id' => $id, 'a' => 'E4'],
                ['lembar_id' => $id, 'a' => 'F8'],
            ], $respons->json('sel_terkunci'), $role);
            $this->assertSame(['edit.1.a', 'edit.2.a', 'edit.3.a', 'edit.4.a'], array_keys($respons->json('errors')));
            $this->assertStringContainsString('INPUT DATA!C2, INPUT DATA!A20, INPUT DATA!E4, INPUT DATA!F8', (string) $respons->json('message'));
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_super_admin_boleh_menyunting_sel_terkunci(): void
    {
        $id = $this->lembar('INPUT DATA')->id;

        $sel = $this->hitung(User::ROLE_SUPER_ADMIN, [
            ['lembar_id' => $id, 'a' => 'B2', 'isi' => '6'],
            ['lembar_id' => $id, 'a' => 'C3', 'isi' => '=B2*10'],
            ['lembar_id' => $id, 'a' => 'A20', 'isi' => 'catatan'],
        ])->assertOk()->json('data.lembar.0.sel');

        $sel = array_column($sel, null, 'a');
        $this->assertSame(12.0, $sel['C2']['n']);
        $this->assertSame([60.0, '=B2*10'], [$sel['C3']['n'], $sel['C3']['r']]);
        $this->assertSame('catatan', $sel['A20']['s']);
    }

    public function test_sel_tidak_terkunci_boleh_untuk_admin(): void
    {
        $id = $this->lembar('INPUT DATA')->id;

        $sel = $this->hitung(User::ROLE_ADMIN, [
            ['lembar_id' => $id, 'a' => 'B2', 'isi' => '6'],     // gaya sel
            ['lembar_id' => $id, 'a' => 'C5', 'isi' => '1'],     // sel kosong bergaya
            ['lembar_id' => $id, 'a' => 'E20', 'isi' => '2'],    // gaya kolom E
            ['lembar_id' => $id, 'a' => 'H8', 'isi' => '3'],     // gaya baris 8
        ])->assertOk()->json('data.lembar.0.sel');

        $sel = array_column($sel, null, 'a');
        $this->assertSame(12.0, $sel['C2']['n']);
        $this->assertSame([1.0, 2.0, 3.0], [$sel['C5']['n'], $sel['E20']['n'], $sel['H8']['n']]);
    }

    public function test_lembar_tanpa_proteksi_tidak_terpengaruh(): void
    {
        $id = $this->lembar('Bebas')->id;

        $sel = $this->hitung(User::ROLE_ADMIN, [
            ['lembar_id' => $id, 'a' => 'A1', 'isi' => '4'],
            ['lembar_id' => $id, 'a' => 'C9', 'isi' => '=B1+1'],
        ])->assertOk()->json('data.lembar.0.sel');

        $sel = array_column($sel, null, 'a');
        $this->assertSame([8.0, 9.0], [$sel['B1']['n'], $sel['C9']['n']]);
    }

    public function test_baris_lama_tanpa_kunci_proteksi_dianggap_tidak_dilindungi(): void
    {
        // Baris format ≤ 4: tata_letak tanpa `dilindungi`/`sel_tidak_terkunci`.
        $input = $this->lembar('INPUT DATA');
        $tata = $input->tata_letak;
        unset($tata['dilindungi'], $tata['sel_tidak_terkunci']);
        $input->forceFill(['tata_letak' => $tata])->save();

        $this->hitung(User::ROLE_ADMIN, [['lembar_id' => $input->id, 'a' => 'C2', 'isi' => '99']])->assertOk();
        $this->app['auth']->forgetGuards();

        $lembar = $this->actingAs($this->pengguna(User::ROLE_ADMIN))->getJson("/api/studio/workbook/{$this->wb->id}")->json('data.lembar');
        $kabel = array_column($lembar, 'tata_letak', 'nama')['INPUT DATA'];
        $this->assertSame([false, []], [$kabel['dilindungi'], $kabel['sel_tidak_terkunci']]);
    }

    /** @param  list<array{lembar_id: int, a: string, isi: string}>  $edit */
    private function hitung(string $role, array $edit): TestResponse
    {
        return $this->actingAs($this->pengguna($role))->postJson("/api/studio/workbook/{$this->wb->id}/hitung", [
            'edit' => $edit,
            'waktu_acuan' => '2026-10-15T10:00:00+07:00',
        ]);
    }

    private function lembar(string $nama): WorkbookMasterLembar
    {
        return $this->wb->lembar()->where('nama', $nama)->sole();
    }

    private function pengguna(string $role): User
    {
        return User::factory()->create(['organization_id' => $this->lab->id, 'role' => $role, 'status' => User::STATUS_AKTIF]);
    }
}
