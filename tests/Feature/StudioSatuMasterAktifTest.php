<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use App\Services\Studio\Workbook\JenisWorkbook;
use App\Services\Studio\Workbook\KunciJenisWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * K-49-13 (keputusan pemilik 15 Okt 2026): Studio hanya memegang master asli,
 * SATU master aktif per jenis/varian. Impor master baru untuk jenis yang sama
 * MENGGANTI yang aktif; yang lama jadi riwayat tersembunyi yang bisa dibuka —
 * tidak pernah dihapus. Olah data/pekerjaan dikeluarkan lewat
 * `studio:rapikan-workbook --arsipkan` (baris & berkas tetap). Fixture sintetis.
 */
class StudioSatuMasterAktifTest extends TestCase
{
    use RefreshDatabase;
    use WorkbookUji;

    private const MICRO = 'Master_Olah_Data_Micrometer_0-25mm';

    private const VOL = 'Fixed_Volumetric_Glassware_2026';

    private string $akar;

    private Organization $lab;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->siapkanFolderUji();
        $this->akar = $this->buatAkarCsv();
        $this->lab = Organization::factory()->create();
        $this->admin = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_AKTIF]);
    }

    protected function tearDown(): void
    {
        $this->bersihkanFolderUji();
        parent::tearDown();
    }

    public function test_impor_ulang_jenis_sama_mengganti_aktif_dan_yang_lama_jadi_riwayat_bukan_dihapus(): void
    {
        $this->imporCsv()->assertSuccessful();
        $lama = $this->satu(self::MICRO);
        $lembarLama = $lama->lembar()->pluck('id')->all();
        $this->assertSame([true, 1, 'micrometer:micrometer-0-25mm'], [$lama->aktif, $lama->versi, $lama->kunci_jenis]);

        // Master yang sama direvisi lab (isi berubah, nama sama) → impor = versi baru.
        $this->tulisCsv($this->akar.'/Panjang_CSV/'.self::MICRO, ['INPUT DATA.csv' => "Nama Alat,:,Micrometer\r\nResolusi,:,0.0005\r\n"]);
        $this->imporCsv()->expectsOutputToContain('(baru 1, sudah_ada 1)')->assertSuccessful();

        $versi = WorkbookMaster::query()->where('nama', self::MICRO)->orderBy('id')->get();
        $this->assertCount(2, $versi, 'yang lama TIDAK dihapus');
        [$lama, $baru] = [$versi[0], $versi[1]];
        $this->assertSame([false, $baru->id, 1], [$lama->aktif, $lama->digantikan_oleh_id, $lama->versi]);
        $this->assertSame([true, null, 2], [$baru->aktif, $baru->digantikan_oleh_id, $baru->versi]);
        $this->assertSame($lama->kunci_jenis, $baru->kunci_jenis);
        $this->assertSame(count($lembarLama), WorkbookMasterLembar::query()->whereIn('id', $lembarLama)->count(), 'lembar riwayat tetap');
        $this->assertStringContainsString("Digantikan impor #{$baru->id}", (string) $lama->catatan);
        $this->assertTrue(AuditLog::query()->where('entity_type', 'workbook_master')->where('entity_id', $lama->id)
            ->where('action', AuditLog::ACTION_DIUBAH)->exists(), 'penggantian tercatat di audit');

        // Isi (sha) yang sama tidak pernah dobel — termasuk sha milik riwayat.
        $this->imporCsv()->expectsOutputToContain('sudah_ada 2')->assertSuccessful();
        $this->assertSame(3, WorkbookMaster::query()->count());

        // API: daftar hanya master aktif; riwayat & detail versi lama tetap terbaca.
        $daftar = $this->actingAs($this->admin)->getJson('/api/studio/workbook')->assertOk()->json('data');
        $this->assertSame([self::VOL, self::MICRO], array_column($daftar, 'nama'));
        $this->assertSame([$baru->id, 1, 2], [$daftar[1]['id'], $daftar[1]['jumlah_riwayat'], $daftar[1]['versi']]);

        $riwayat = $this->actingAs($this->admin)->getJson("/api/studio/workbook/{$lama->id}/riwayat")->assertOk()->json('data');
        $this->assertSame([$baru->id, $lama->id], array_column($riwayat, 'id'));
        $this->actingAs($this->admin)->getJson("/api/studio/workbook/{$lama->id}")->assertOk()
            ->assertJsonPath('data.status', 'riwayat')
            ->assertJsonPath('data.digantikan_oleh_id', $baru->id);
        $this->actingAs($this->admin)->getJson("/api/studio/workbook/{$lama->id}/lembar/{$lembarLama[0]}")->assertOk();
    }

    public function test_xlsm_kembaran_menjadikan_csv_riwayat_dan_csv_tidak_pernah_menggantikan_xlsm(): void
    {
        $this->imporCsv()->assertSuccessful();
        $csv = $this->satu(self::MICRO);

        // `.xlsm` asli master yang sama (nama ternormalisasi sama) → master aktif, csv jadi riwayatnya.
        $this->artisan('studio:impor-workbook', ['--xlsm' => [$this->buatXlsm()], '--organisasi' => (string) $this->lab->id, '--oleh' => $this->admin->email])
            ->expectsQuestion('Sandi workbook (tidak ditampilkan, tidak disimpan)', self::SANDI_UJI)
            ->assertSuccessful();
        $xlsm = WorkbookMaster::query()->where('sumber', WorkbookMaster::SUMBER_XLSM)->sole();
        $this->assertSame($csv->fresh()->kunci_jenis, $xlsm->kunci_jenis);
        $this->assertSame([true, false, $xlsm->id], [$xlsm->aktif, $csv->fresh()->aktif, $csv->fresh()->digantikan_oleh_id]);

        // Ekspor csv yang lebih baru tetap lahir sebagai riwayat xlsm, tidak menggesernya.
        $this->tulisCsv($this->akar.'/Panjang_CSV/'.self::MICRO, ['INPUT DATA.csv' => "Nama Alat,:,Micrometer\r\nResolusi,:,0.002\r\n"]);
        $this->imporCsv()->assertSuccessful();
        $csvBaru = WorkbookMaster::query()->where('nama', self::MICRO)->where('sumber', 'csv')->latest('id')->first();
        $this->assertNotSame($csv->id, $csvBaru->id);
        $this->assertSame([false, $xlsm->id], [$csvBaru->aktif, $csvBaru->digantikan_oleh_id]);
        $this->assertTrue($xlsm->fresh()->aktif);
        $this->assertSame([3, 1, 2], [$csvBaru->versi, $csv->fresh()->versi, $xlsm->fresh()->versi]);

        // FORMAT kanonik naik → `--ganti` mengekstrak ulang csv riwayat DI TEMPAT: statusnya
        // diwarisi (tetap riwayat xlsm, versi sama), master xlsm tidak tergeser.
        DB::table('workbook_master')->where('id', $csvBaru->id)->update(['sha256' => str_repeat('a', 64)]);
        $this->imporCsv(['--ganti' => true])->expectsOutputToContain('(diganti 1, sudah_ada 1)')->assertSuccessful();
        $pengganti = WorkbookMaster::query()->where('nama', self::MICRO)->where('sumber', 'csv')->latest('id')->first();
        $this->assertNotSame($csvBaru->id, $pengganti->id);
        $this->assertSame([false, $xlsm->id, 3, $csvBaru->kunci_jenis], [$pengganti->aktif, $pengganti->digantikan_oleh_id, $pengganti->versi, $pengganti->kunci_jenis]);
        $this->assertTrue($xlsm->fresh()->aktif);
        $this->assertNotNull($csv->fresh(), 'versi csv lain tidak tersentuh --ganti');
    }

    public function test_gantikan_dan_kunci_jenis_untuk_master_bernama_lain(): void
    {
        $lab2 = Organization::factory()->create();
        $this->imporCsv()->assertSuccessful();
        $micro = $this->satu(self::MICRO);
        $folder = $this->folderUji.'/baru/Mikrometer_Nol_Dua_Lima_Revisi_Lab';
        $this->tulisCsv($folder, ['INPUT DATA.csv' => "Nama Alat,:,Micrometer\r\n"]);
        $lain = $this->folderUji.'/baru2/Timbangan_Lab_X';
        $this->tulisCsv($lain, ['INPUT DATA.csv' => "Nama Alat,:,Timbangan\r\n"]);

        // Ditolak: lebih dari satu workbook, pengganti lab lain, digabung --ganti, kunci tidak sah.
        $this->imporCsv(['--akar' => $this->folderUji, '--gantikan' => (string) $micro->id])->assertFailed();
        $this->imporCsv(['--akar' => $folder, '--gantikan' => '999999'])->assertFailed();
        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $folder, '--organisasi' => (string) $lab2->id, '--gantikan' => (string) $micro->id, '--oleh' => $this->olehLab($lab2)])->assertFailed();
        $this->imporCsv(['--akar' => $folder, '--gantikan' => (string) $micro->id, '--ganti' => true])->assertFailed();
        $this->imporCsv(['--akar' => $folder, '--kunci-jenis' => 'Bukan Kunci!'])->assertFailed();
        $this->assertSame(2, WorkbookMaster::query()->count());

        // `--kering` melaporkan siapa yang akan digantikan, tanpa menulis.
        $this->imporCsv(['--akar' => $folder, '--gantikan' => (string) $micro->id, '--kering' => true])->assertSuccessful();
        $this->assertSame(2, WorkbookMaster::query()->count());

        $this->imporCsv(['--akar' => $folder, '--gantikan' => (string) $micro->id])->assertSuccessful();
        $baru = $this->satu('Mikrometer_Nol_Dua_Lima_Revisi_Lab');
        $this->assertSame([$micro->kunci_jenis, true, 2], [$baru->kunci_jenis, $baru->aktif, $baru->versi]);
        $this->assertSame([false, $baru->id], [$micro->fresh()->aktif, $micro->fresh()->digantikan_oleh_id]);

        $this->imporCsv(['--akar' => $lain, '--kunci-jenis' => 'Timbangan:Timbangan Gram'])->assertSuccessful();
        $this->assertSame('timbangan:timbangan-gram', $this->satu('Timbangan_Lab_X')->kunci_jenis);
    }

    public function test_rapikan_arsip_riwayat_aktifkan_tanpa_menghapus_dan_izin_baca_tetap(): void
    {
        $lab2 = Organization::factory()->create();
        $orangLain = User::factory()->create(['organization_id' => $lab2->id, 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_AKTIF]);
        $this->imporCsv()->assertSuccessful();
        $micro = $this->satu(self::MICRO);
        $vol = $this->satu(self::VOL);
        $lembarVol = $vol->lembar()->first();
        $perintah = fn (array $opsi) => $this->artisan('studio:rapikan-workbook', $opsi);

        // Penjaga perintah.
        $perintah(['workbook' => (string) $vol->id, '--arsipkan' => true])->assertFailed(); // tanpa --alasan
        $perintah(['workbook' => (string) $vol->id, '--alasan' => 'x'])->assertFailed(); // tanpa aksi
        $perintah(['workbook' => (string) $vol->id, '--arsipkan' => true, '--aktifkan' => true, '--alasan' => 'x'])->assertFailed();
        $perintah(['workbook' => (string) $vol->id, '--arsipkan' => true, '--alasan' => 'x', '--oleh' => $orangLain->email])->assertFailed();
        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->akar, '--organisasi' => (string) $lab2->id, '--oleh' => $orangLain->email])->assertSuccessful();
        $microLab2 = WorkbookMaster::query()->where('organization_id', $lab2->id)->where('nama', self::MICRO)->sole();
        $perintah(['workbook' => (string) $vol->id, '--riwayat-dari' => (string) $microLab2->id, '--alasan' => 'x', '--oleh' => $this->admin->email])->assertFailed();

        // Tinjau C-3 #2: tanpa --oleh perubahan DITOLAK (jejak audit tanpa pelaku); --kering boleh.
        $perintah(['workbook' => (string) $vol->id, '--arsipkan' => true, '--alasan' => 'uji'])
            ->expectsOutputToContain('--oleh wajib')->assertFailed();
        $this->assertNull($vol->fresh()->diarsipkan_pada);

        // --kering: tidak ada yang tersimpan.
        $perintah(['workbook' => (string) $vol->id, '--arsipkan' => true, '--alasan' => 'uji', '--kering' => true])
            ->expectsOutputToContain('KERING')->assertSuccessful();
        $this->assertNull($vol->fresh()->diarsipkan_pada);

        // Arsip: keluar dari Studio (404 di semua rute), baris & lembar tetap.
        $perintah(['workbook' => (string) $vol->id, '--arsipkan' => true, '--alasan' => 'olah data per alat, bukan master', '--oleh' => $this->admin->email])
            ->assertSuccessful();
        $this->assertNotNull($vol->fresh()->diarsipkan_pada);
        $this->assertTrue(WorkbookMasterLembar::query()->whereKey($lembarVol->id)->exists());
        $this->assertSame([self::MICRO], array_column($this->actingAs($this->admin)->getJson('/api/studio/workbook')->json('data'), 'nama'));

        foreach (['', '/riwayat', "/lembar/{$lembarVol->id}"] as $akhir) {
            $this->actingAs($this->admin)->getJson("/api/studio/workbook/{$vol->id}{$akhir}")->assertNotFound();
        }

        $this->actingAs($this->admin)->postJson("/api/studio/workbook/{$vol->id}/hitung", ['edit' => []])->assertNotFound();
        $this->assertSame($this->admin->id, AuditLog::query()->where('entity_type', 'workbook_master')->where('entity_id', $vol->id)
            ->where('action', AuditLog::ACTION_DIUBAH)->latest('id')->value('changed_by'));
        $this->assertStringContainsString('olah data per alat, bukan master', (string) $vol->fresh()->catatan);

        // --aktifkan memulihkan arsip.
        $perintah(['workbook' => (string) $vol->id, '--aktifkan' => true, '--alasan' => 'salah arsip', '--oleh' => $this->admin->email])->assertSuccessful();
        $this->assertSame([true, null], [$vol->fresh()->aktif, $vol->fresh()->diarsipkan_pada]);

        // --riwayat-dari: vol (dan jenisnya) pindah jadi riwayat micro.
        $perintah(['workbook' => (string) $vol->id, '--riwayat-dari' => (string) $micro->id, '--alasan' => 'uji gabung', '--oleh' => $this->admin->email])
            ->assertSuccessful();
        $vol = $vol->fresh();
        $this->assertSame([$micro->kunci_jenis, false, $micro->id, 2], [$vol->kunci_jenis, $vol->aktif, $vol->digantikan_oleh_id, $vol->versi]);
        $daftar = $this->actingAs($this->admin)->getJson('/api/studio/workbook')->json('data');
        $this->assertSame([[$micro->id], [1]], [array_column($daftar, 'id'), array_column($daftar, 'jumlah_riwayat')]);

        // --aktifkan versi lama: tukar master aktif; yang tadinya aktif jadi riwayat.
        $perintah(['workbook' => (string) $vol->id, '--aktifkan' => true, '--alasan' => 'kembali ke versi ini', '--oleh' => $this->admin->email])->assertSuccessful();
        $this->assertSame([true, false, $vol->id], [$vol->fresh()->aktif, $micro->fresh()->aktif, $micro->fresh()->digantikan_oleh_id]);
        $this->assertSame(1, WorkbookMaster::query()->where('organization_id', $this->lab->id)->where('aktif', true)->count());

        // Tinjau C-3 #3: aktifkan lagi versi 1 → riwayat memuat master AKTIF dulu walau
        // versinya lebih kecil; `versi` tidak dinomori ulang (urutan impor).
        $perintah(['workbook' => (string) $micro->id, '--aktifkan' => true, '--alasan' => 'kembali ke master awal', '--oleh' => $this->admin->email])->assertSuccessful();
        $riwayat = $this->actingAs($this->admin)->getJson("/api/studio/workbook/{$vol->id}/riwayat")->assertOk()->json('data');
        $this->assertSame(
            [[$micro->id, 'aktif', 1, null], [$vol->id, 'riwayat', 2, $micro->id]],
            array_map(static fn (array $r): array => [$r['id'], $r['status'], $r['versi'], $r['digantikan_oleh_id']], $riwayat),
        );

        // Baca riwayat: lab lain 404, pelanggan 403, tanpa token 401, viewer boleh.
        $viewer = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_VIEWER, 'status' => User::STATUS_AKTIF]);
        $this->actingAs($viewer)->getJson("/api/studio/workbook/{$micro->id}/riwayat")->assertOk();
        $this->actingAs($orangLain)->getJson("/api/studio/workbook/{$micro->id}/riwayat")->assertNotFound();
        $pelanggan = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_PELANGGAN, 'status' => User::STATUS_AKTIF]);
        Sanctum::actingAs($pelanggan, ['internal']);
        $this->getJson("/api/studio/workbook/{$micro->id}/riwayat")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/studio/workbook/{$micro->id}/riwayat")->assertUnauthorized();

        $perintah(['--daftar' => true, '--organisasi' => (string) $this->lab->id])->expectsOutputToContain('aktif 1, riwayat 1')->assertSuccessful();
        $this->assertSame(2, WorkbookMaster::query()->where('organization_id', $this->lab->id)->count(), 'tidak ada yang dihapus');
        // Setiap perubahan status master oleh perintah ini punya pelaku.
        $this->assertSame([], AuditLog::query()->where('entity_type', 'workbook_master')->where('action', AuditLog::ACTION_DIUBAH)
            ->whereNull('changed_by')->pluck('id')->all());
    }

    /** Migrasi data lama: kembaran csv/xlsm → csv riwayat xlsm; nama lain tetap master aktif sendiri. */
    public function test_tata_awal_data_lama(): void
    {
        // Baris seperti sebelum migrasi: kunci_jenis kosong, kolom aktif = bawaan.
        $org = $this->lab->id;
        $baris = static fn (string $nama, string $sumber, ?string $kode): int => (int) DB::table('workbook_master')->insertGetId([
            'organization_id' => $org, 'nama' => $nama, 'sumber' => $sumber, 'kode_profil' => $kode, 'kunci_jenis' => null,
            'sha256' => hash('sha256', $nama.$sumber), 'ukuran' => 1, 'jumlah_lembar' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $csv = $baris('1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp', 'csv', 'anak_timbangan');
        $xlsm = $baris('1. 1 Anak Timbangan F1 1mg-500 g 202501022 imp', 'xlsm', 'anak_timbangan');
        $sendiri = $baris('Anak timbangan F2_IMTE-LQ-197 hal 1', 'xlsm', 'anak_timbangan');

        JenisWorkbook::tataAwal();

        $per = WorkbookMaster::query()->whereIn('id', [$csv, $xlsm, $sendiri])->get()->keyBy('id');
        $this->assertSame(KunciJenisWorkbook::dari('anak_timbangan', null, '1. 1 Anak Timbangan F1 1mg-500 g 202501022 imp'), $per[$csv]->kunci_jenis);
        $this->assertSame([false, $xlsm, 1], [$per[$csv]->aktif, $per[$csv]->digantikan_oleh_id, $per[$csv]->versi]);
        $this->assertSame([true, null, 2], [$per[$xlsm]->aktif, $per[$xlsm]->digantikan_oleh_id, $per[$xlsm]->versi]);
        $this->assertSame([true, 1, 'anak_timbangan:anak-timbangan-f2-imte-lq-197-hal-1'], [$per[$sendiri]->aktif, $per[$sendiri]->versi, $per[$sendiri]->kunci_jenis]);
    }

    /** @param  array<string, mixed>  $opsi */
    private function imporCsv(array $opsi = []): PendingCommand
    {
        return $this->artisan('studio:impor-workbook', $opsi + ['--csv' => true, '--akar' => $this->akar, '--organisasi' => (string) $this->lab->id, '--oleh' => $this->admin->email]);
    }

    private function satu(string $nama): WorkbookMaster
    {
        return WorkbookMaster::query()->where('organization_id', $this->lab->id)->where('nama', $nama)->sole();
    }
}
