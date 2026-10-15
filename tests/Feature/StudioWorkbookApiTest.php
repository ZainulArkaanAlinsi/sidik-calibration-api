<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use App\Services\Studio\Workbook\JenisWorkbook;
use App\Services\Studio\Workbook\KanonikWorkbook;
use App\Services\Studio\Workbook\PembacaCsvWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * API baca Workbook Alat (Studio S1b, `docs/kontrak-api.md` §Studio Workbook):
 * izin per peran (lewat HTTP), batas organisasi 404, lembar milik workbook
 * lain 404, paginasi sel, isi DATABASE/SERTIFIKAT terbaca oleh lab pemiliknya
 * dan tidak pernah oleh lab lain (K-49-12), serta pasangan csv → xlsm.
 */
class StudioWorkbookApiTest extends TestCase
{
    use RefreshDatabase;
    use WorkbookUji;

    private const PENANDA_LAB2 = 'BOCOR-WORKBOOK-LAB2';

    private Organization $lab1;

    private Organization $lab2;

    private WorkbookMaster $micro;

    private WorkbookMaster $vol;

    private WorkbookMaster $milikLab2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanFolderUji();
        $akar = $this->buatAkarCsv();

        $this->lab1 = Organization::factory()->create();
        $this->lab2 = Organization::factory()->create();

        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $akar, '--organisasi' => (string) $this->lab1->id, '--oleh' => $this->olehLab($this->lab1)])->assertSuccessful();

        $this->micro = WorkbookMaster::query()->where('nama', 'Master_Olah_Data_Micrometer_0-25mm')->sole();
        $this->vol = WorkbookMaster::query()->where('nama', 'Fixed_Volumetric_Glassware_2026')->sole();

        // Lab 2: workbook sendiri berpenanda di nama DAN di sel.
        $this->tulisCsv($this->folderUji.'/lab2/'.self::PENANDA_LAB2, [
            'INPUT DATA.csv' => self::PENANDA_LAB2.",1\r\n",
        ]);
        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->folderUji.'/lab2', '--organisasi' => (string) $this->lab2->id, '--oleh' => $this->olehLab($this->lab2)])->assertSuccessful();
        $this->milikLab2 = WorkbookMaster::query()->where('organization_id', $this->lab2->id)->sole();
    }

    protected function tearDown(): void
    {
        $this->bersihkanFolderUji();
        parent::tearDown();
    }

    public function test_admin_teknisi_viewer_super_admin_boleh_membaca(): void
    {
        $perhitungan = $this->micro->lembar()->where('nama', 'PERHITUNGAN')->sole();

        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER, User::ROLE_SUPER_ADMIN] as $role) {
            $user = $this->pengguna($this->lab1, $role);

            $daftar = $this->actingAs($user)->getJson('/api/studio/workbook')->assertOk()->json('data');
            $this->assertSame(['Fixed_Volumetric_Glassware_2026', 'Master_Olah_Data_Micrometer_0-25mm'], array_column($daftar, 'nama'), $role);
            $this->assertSame(
                ['id', 'nama', 'kode_profil', 'keluarga', 'sumber', 'sha256', 'ukuran', 'jumlah_lembar', 'berkas_asli_tersimpan', 'berkas_asli_terenkripsi', 'ada_versi_xlsm', 'versi_xlsm_id', 'kunci_jenis', 'aktif', 'status', 'versi', 'digantikan_oleh_id', 'jumlah_riwayat', 'diimpor_pada'],
                array_keys($daftar[0]),
                $role,
            );

            $this->actingAs($user)->getJson("/api/studio/workbook/{$this->micro->id}")->assertOk()
                ->assertJsonPath('data.kode_profil', 'micrometer')
                ->assertJsonPath('data.lembar.0.nama', 'INPUT DATA');

            $this->actingAs($user)->getJson("/api/studio/workbook/{$this->micro->id}/lembar/{$perhitungan->id}")->assertOk()
                ->assertJsonPath('data.jumlah_baris', 4);

            $boleh = $this->actingAs($user)->getJson('/api/me/permissions')->assertOk()->json('data.boleh');
            $this->assertContains('workbook.lihat', $boleh, $role);
        }
    }

    public function test_pelanggan_ditolak_dan_tanpa_token_401(): void
    {
        $pelanggan = $this->pengguna($this->lab1, User::ROLE_PELANGGAN);
        $lembar = $this->micro->lembar()->first();
        $rute = [
            '/api/studio/workbook',
            "/api/studio/workbook/{$this->micro->id}",
            "/api/studio/workbook/{$this->micro->id}/riwayat",
            "/api/studio/workbook/{$this->micro->id}/lembar/{$lembar->id}",
        ];

        Sanctum::actingAs($pelanggan, ['internal']);

        foreach ($rute as $uri) {
            $this->getJson($uri)->assertForbidden();
        }

        $this->app['auth']->forgetGuards();

        foreach ($rute as $uri) {
            $this->getJson($uri)->assertUnauthorized();
        }
    }

    public function test_detail_tanpa_sel_dan_lembar_database_bertautan(): void
    {
        $admin = $this->pengguna($this->lab1, User::ROLE_ADMIN);

        $respons = $this->actingAs($admin)->getJson("/api/studio/workbook/{$this->vol->id}")->assertOk();
        $detail = $respons->json('data');

        // Kontrak mobile `TataLetakLembar.fromJson`: peta = OBJEK JSON, kosong `{}` (bukan `[]`), kunci `freeze`.
        // CSV: lebar kolom PERKIRAAN (objek berisi) dan ditandai jujur.
        $mentah = (string) $respons->getContent();
        $this->assertStringContainsString('"lebar_kolom":{"A":', $mentah);
        $this->assertStringContainsString('"lebar_perkiraan":true', $mentah);
        $this->assertStringContainsString('"tinggi_baris":{}', $mentah);
        $this->assertStringContainsString('"freeze":null', $mentah);
        $this->assertStringContainsString('"garis_kisi":true', $mentah);
        $this->assertStringNotContainsString('"beku"', $mentah);
        $this->assertNull($detail['berkas_asli_terenkripsi']);

        $this->assertNull($detail['kode_profil']);
        $this->assertSame('volumetric_fixed', $detail['keluarga']);
        $this->assertFalse($detail['berkas_asli_tersimpan']);
        $this->assertStringContainsString('Sumber CSV', $detail['catatan']);
        $this->assertSame(['INPUT_DATA', 'DATABASE', 'SERTIFIKAT (2)', 'SERTIFIKAT KALIBRATOR'], array_column($detail['lembar'], 'nama'));

        foreach ($detail['lembar'] as $l) {
            $this->assertArrayNotHasKey('sel', $l);
            $this->assertSame(
                ['id', 'urutan', 'nama', 'tersembunyi', 'dikecualikan', 'alasan_dikecualikan', 'tautan_tabel', 'jumlah_baris', 'jumlah_kolom', 'jumlah_sel', 'jumlah_rumus', 'tata_letak'],
                array_keys($l),
            );
            $this->assertSame(array_keys(KanonikWorkbook::tataLetakKosong()), array_keys($l['tata_letak']));
        }

        // K-49-12: DATABASE tidak dikecualikan, hanya bertautan; isinya terkirim apa adanya.
        $db = $detail['lembar'][1];
        $this->assertFalse($db['dikecualikan']);
        $this->assertNull($db['alasan_dikecualikan']);
        $this->assertSame('customers', $db['tautan_tabel']);
        $this->assertSame('certificates', $detail['lembar'][2]['tautan_tabel']);
        $this->assertNull($detail['lembar'][3]['tautan_tabel'], 'SERTIFIKAT KALIBRATOR bukan cermin tabel certificates');

        $sel = $this->actingAs($admin)->getJson("/api/studio/workbook/{$this->vol->id}/lembar/{$db['id']}?baris_dari=1&baris_sampai=500")->assertOk();
        $this->assertSame(self::KANARI_DATABASE, collect($sel->json('data.sel'))->firstWhere('a', 'B1')['s']);
        $this->assertSame(1, $sel->json('data.jumlah_baris'));
    }

    /**
     * K-49-12: isi DATABASE/SERTIFIKAT terbaca oleh lab pemiliknya (semua
     * peran baca), dan TERKURUNG organisasi — lab lain dijawab 404 untuk
     * lembarnya, dan kanari tidak pernah muncul di respons mana pun milik lab
     * lain (daftar, detail, sel).
     */
    public function test_isi_database_terbaca_lab_sendiri_dan_terkurung_organisasi(): void
    {
        $admin = $this->pengguna($this->lab1, User::ROLE_ADMIN);
        $isi = '';

        foreach ([$this->micro, $this->vol] as $wb) {
            foreach ($wb->lembar as $l) {
                $isi .= (string) $this->actingAs($admin)->getJson("/api/studio/workbook/{$wb->id}/lembar/{$l->id}?baris_dari=1&baris_sampai=500")->assertOk()->getContent();
            }
        }

        $this->assertStringContainsString(self::KANARI_DATABASE, $isi);
        $this->assertStringContainsString(self::KANARI_SERTIFIKAT, $isi);

        $bertautan = WorkbookMasterLembar::query()->whereIn('workbook_master_id', [$this->micro->id, $this->vol->id])->whereNotNull('tautan_tabel')->get();
        $this->assertCount(4, $bertautan);

        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER, User::ROLE_SUPER_ADMIN] as $role) {
            $orangLab2 = $this->pengguna($this->lab2, $role);
            $bocor = (string) $this->actingAs($orangLab2)->getJson('/api/studio/workbook')->assertOk()->getContent();

            foreach ($bertautan as $l) {
                $bocor .= (string) $this->actingAs($orangLab2)->getJson("/api/studio/workbook/{$l->workbook_master_id}")->assertNotFound()->getContent();
                $bocor .= (string) $this->actingAs($orangLab2)->getJson("/api/studio/workbook/{$l->workbook_master_id}/lembar/{$l->id}?baris_dari=1&baris_sampai=500")->assertNotFound()->getContent();
                // Workbook sendiri + lembar DATABASE lab lain.
                $bocor .= (string) $this->actingAs($orangLab2)->getJson("/api/studio/workbook/{$this->milikLab2->id}/lembar/{$l->id}")->assertNotFound()->getContent();
            }

            $this->assertStringNotContainsString(self::KANARI_DATABASE, $bocor, $role);
            $this->assertStringNotContainsString(self::KANARI_SERTIFIKAT, $bocor, $role);
        }
    }

    /**
     * Tahap A + K-49-13 (15 Okt 2026): sesudah `.xlsm` setara diimpor, baris
     * csv-nya jadi RIWAYAT master xlsm — hilang dari daftar (hanya master
     * aktif), tetap terbuka lewat `…/riwayat` dan detail, dan tetap membawa
     * `versi_xlsm_id` (nama setara atau profil sama + akhiran nama). Rentang
     * lain = jenis lain, tetap master sendiri. Hanya di lab yang sama.
     */
    public function test_daftar_hanya_master_aktif_dan_csv_kembaran_jadi_riwayat_xlsm(): void
    {
        $xlsmMicro = $this->workbookXlsm($this->lab1, 'Master Olah Data_Micrometer 0-25mm', 'micrometer', null);
        $xlsmVol = $this->workbookXlsm($this->lab1, '(NEW) Master Olah Data_Fixed Volumetric Glassware_2026', null, 'volumetric_fixed');
        // Profil sama tetapi rentang lain → jenis lain, BUKAN pasangan Micrometer 0-25.
        $xlsmLain = $this->workbookXlsm($this->lab1, 'Master Olah Data_Micrometer 25-50mm', 'micrometer', null);
        // Nama setara di lab lain → tidak boleh dipasangkan dengan csv lab 1.
        $this->workbookXlsm($this->lab2, 'Master_Olah_Data_Micrometer_0-25mm (lab2)', 'micrometer', null);

        $admin = $this->pengguna($this->lab1, User::ROLE_ADMIN);
        $daftar = collect($this->actingAs($admin)->getJson('/api/studio/workbook')->assertOk()->json('data'));

        $this->assertSame([$xlsmVol->id, $xlsmMicro->id, $xlsmLain->id], $daftar->pluck('id')->all(), 'csv kembaran tidak tampil');
        $this->assertSame(['aktif', 'aktif', 'aktif'], $daftar->pluck('status')->all());
        $this->assertSame([1, 1, 0], $daftar->pluck('jumlah_riwayat')->all());
        $per = $daftar->keyBy('id');
        $this->assertSame([true, $xlsmLain->id], [$per[$xlsmLain->id]['ada_versi_xlsm'], $per[$xlsmLain->id]['versi_xlsm_id']], 'baris xlsm menunjuk dirinya sendiri');

        $riwayat = $this->actingAs($admin)->getJson("/api/studio/workbook/{$xlsmMicro->id}/riwayat")->assertOk();
        $this->assertSame([$xlsmMicro->id, $this->micro->id], array_column($riwayat->json('data'), 'id'));
        $this->assertSame([2, 1], array_column($riwayat->json('data'), 'versi'));
        $this->assertSame(['aktif', 'riwayat'], array_column($riwayat->json('data'), 'status'));
        $this->assertSame([null, $xlsmMicro->id], array_column($riwayat->json('data'), 'digantikan_oleh_id'));
        $this->assertSame($xlsmMicro->kunci_jenis, $riwayat->json('kunci_jenis'));
        $vol = $this->actingAs($admin)->getJson("/api/studio/workbook/{$this->vol->id}")->assertOk()->json('data');
        $this->assertSame([true, $xlsmVol->id, 'riwayat'], [$vol['ada_versi_xlsm'], $vol['versi_xlsm_id'], $vol['status']]);

        $this->actingAs($admin)->getJson("/api/studio/workbook/{$this->micro->id}")->assertOk()
            ->assertJsonPath('data.versi_xlsm_id', $xlsmMicro->id)
            ->assertJsonPath('data.ada_versi_xlsm', true)
            ->assertJsonPath('data.status', 'riwayat')
            ->assertJsonPath('data.aktif', false)
            ->assertJsonPath('data.digantikan_oleh_id', $xlsmMicro->id);

        // Lab 2: csv-nya tidak berpasangan dengan xlsm lab 1, dan xlsm lab 1 tidak terlihat.
        $pemilik = $this->pengguna($this->lab2, User::ROLE_ADMIN);
        $lab2 = collect($this->actingAs($pemilik)->getJson('/api/studio/workbook')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([false, null], [$lab2[$this->milikLab2->id]['ada_versi_xlsm'], $lab2[$this->milikLab2->id]['versi_xlsm_id']]);
        $this->assertFalse($lab2->has($xlsmMicro->id));
    }

    public function test_paginasi_sel_per_rentang_baris(): void
    {
        $admin = $this->pengguna($this->lab1, User::ROLE_ADMIN);
        $lembar = $this->micro->lembar()->where('nama', 'PERHITUNGAN')->sole();
        $url = "/api/studio/workbook/{$this->micro->id}/lembar/{$lembar->id}";

        $semua = $this->actingAs($admin)->getJson($url)->assertOk()->json('data');
        $this->assertSame([1, 200, 4], [$semua['baris_dari'], $semua['baris_sampai'], $semua['jumlah_baris']]);
        $this->assertCount($lembar->jumlah_sel, $semua['sel']);

        $dua = $this->actingAs($admin)->getJson($url.'?baris_dari=2&baris_sampai=3')->assertOk()->json('data');
        $this->assertSame([2, 3], array_values(array_unique(array_column($dua['sel'], 'b'))));
        $c2 = collect($dua['sel'])->firstWhere('a', 'C2');
        // Bentuk sel kanonik persis (kunci urut byte, float tetap float).
        $this->assertSame(['a' => 'C2', 'b' => 2, 'f' => null, 'g' => null, 'k' => 3, 'n' => 0.2, 'r' => '=B2*2', 's' => '0.2', 't' => 'f'], $c2);

        // Float bulat tetap float di kabel (JSON_PRESERVE_ZERO_FRACTION): sel larik C3 = 3.0.
        $mentah = (string) $this->actingAs($admin)->getJson($url.'?baris_dari=3&baris_sampai=3')->getContent();
        $this->assertStringContainsString('"n":3.0', $mentah);

        // Rentang > 500 dipangkas; baris_sampai di jawaban menyebut batas nyata.
        $this->actingAs($admin)->getJson($url.'?baris_dari=10&baris_sampai=5000')->assertOk()
            ->assertJsonPath('data.baris_dari', 10)
            ->assertJsonPath('data.baris_sampai', 509)
            ->assertJsonPath('data.sel', []);

        // Tanpa baris_sampai: 200 baris MULAI baris_dari (tinjau S1b #4), bukan 1..200 → 422.
        $this->actingAs($admin)->getJson($url.'?baris_dari=300')->assertOk()
            ->assertJsonPath('data.baris_dari', 300)
            ->assertJsonPath('data.baris_sampai', 499)
            ->assertJsonPath('data.sel', []);
        $this->actingAs($admin)->getJson($url.'?baris_dari=2')->assertOk()
            ->assertJsonPath('data.baris_sampai', 201)
            ->assertJsonCount(count(array_filter($semua['sel'], static fn (array $s): bool => $s['b'] >= 2)), 'data.sel');

        $this->actingAs($admin)->getJson($url.'?baris_dari=5&baris_sampai=2')->assertUnprocessable();
        $this->actingAs($admin)->getJson($url.'?baris_dari=0')->assertUnprocessable();
        $this->actingAs($admin)->getJson($url.'?baris_dari=abc')->assertUnprocessable();
    }

    public function test_filter_kode_profil_dan_cari(): void
    {
        $admin = $this->pengguna($this->lab1, User::ROLE_ADMIN);

        $this->assertSame(
            ['Master_Olah_Data_Micrometer_0-25mm'],
            array_column($this->actingAs($admin)->getJson('/api/studio/workbook?kode_profil=micrometer')->assertOk()->json('data'), 'nama'),
        );
        $this->assertSame(
            ['Fixed_Volumetric_Glassware_2026'],
            array_column($this->actingAs($admin)->getJson('/api/studio/workbook?cari=volumetric')->assertOk()->json('data'), 'nama'),
        );
        $this->assertSame([], $this->actingAs($admin)->getJson('/api/studio/workbook?kode_profil=timbangan')->assertOk()->json('data'));
    }

    public function test_organisasi_lain_404_dan_lembar_workbook_lain_404(): void
    {
        $lembarLab2 = $this->milikLab2->lembar()->first();
        $lembarVol = $this->vol->lembar()->first();

        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER, User::ROLE_SUPER_ADMIN] as $role) {
            $user = $this->pengguna($this->lab1, $role);

            $daftar = (string) $this->actingAs($user)->getJson('/api/studio/workbook')->assertOk()->getContent();
            $this->assertStringNotContainsString(self::PENANDA_LAB2, $daftar, $role);

            foreach ([
                "/api/studio/workbook/{$this->milikLab2->id}",
                "/api/studio/workbook/{$this->milikLab2->id}/lembar/{$lembarLab2->id}",
                // Workbook sendiri + lembar lab lain, dan sebaliknya.
                "/api/studio/workbook/{$this->micro->id}/lembar/{$lembarLab2->id}",
                "/api/studio/workbook/{$this->milikLab2->id}/lembar/{$this->micro->lembar()->first()->id}",
                // Workbook sendiri + lembar milik workbook sendiri yang LAIN.
                "/api/studio/workbook/{$this->micro->id}/lembar/{$lembarVol->id}",
                '/api/studio/workbook/999999',
            ] as $uri) {
                $r = $this->actingAs($user)->getJson($uri);
                $this->assertSame(404, $r->getStatusCode(), "{$role} GET {$uri}");
                $this->assertStringNotContainsString(self::PENANDA_LAB2, (string) $r->getContent(), "{$role} GET {$uri}");
            }
        }

        // Pemilik lab 2 sendiri tetap bisa (kontrol: 404 di atas soal lab, bukan soal data).
        $pemilik = $this->pengguna($this->lab2, User::ROLE_ADMIN);
        $this->actingAs($pemilik)->getJson("/api/studio/workbook/{$this->milikLab2->id}/lembar/{$lembarLab2->id}")->assertOk()
            ->assertJsonPath('data.sel.0.s', self::PENANDA_LAB2);

        $this->assertTrue(AuditLog::query()
            ->where('organization_id', $this->lab1->id)
            ->where('action', AuditLog::ACTION_AKSES_LINTAS_LAB)
            ->where('entity_type', 'workbook_master')
            ->exists());
    }

    public function test_daftar_sel_model_tetap_kosong_untuk_lembar_lama_dikecualikan(): void
    {
        // Baris lama (format 1-2, K-49-08 sebelum dicabut) ber-`dikecualikan`:
        // kalaupun kolom `sel`-nya terisi, model tidak memulangkannya.
        $lembar = $this->vol->lembar()->where('nama', 'DATABASE')->sole();
        $lembar->forceFill(['dikecualikan' => true, 'sel' => KanonikWorkbook::sel([PembacaCsvWorkbook::sel(1, 1, self::KANARI_DATABASE)])])->save();

        $this->assertSame([], $lembar->fresh()->daftarSel());
        $this->assertArrayNotHasKey('sel', $lembar->fresh()->toArray());
    }

    /** Baris xlsm yang lahir seperti impor: jadi master aktif jenisnya (K-49-13). */
    private function workbookXlsm(Organization $lab, string $nama, ?string $kode, ?string $keluarga): WorkbookMaster
    {
        $w = WorkbookMaster::query()->create([
            'organization_id' => $lab->id, 'nama' => $nama, 'sumber' => WorkbookMaster::SUMBER_XLSM,
            'kode_profil' => $kode, 'keluarga' => $keluarga, 'aktif' => false,
            'sha256' => hash('sha256', $lab->id.$nama), 'ukuran' => 1, 'jumlah_lembar' => 0,
        ]);
        app(JenisWorkbook::class)->aktifkan($w, null, 'uji');

        return $w->fresh();
    }

    private function pengguna(Organization $lab, string $role): User
    {
        return User::factory()->create([
            'organization_id' => $lab->id,
            'role' => $role,
            'status' => User::STATUS_AKTIF,
        ]);
    }
}
