<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\Studio\Hitung\SumberModelHitung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * `POST /api/studio/workbook/impor` (K-50-12): unggah master lewat API.
 *
 * Dikunci: semantik ganti-aktif/simpan-riwayat sama dengan perintah artisan,
 * pelaku audit = pemilik token, berkas asli di disk AWET (`arsip`, bukan
 * `local`) sehingga `/hitung` jalan lagi sesudah cache/containernya hilang,
 * sandi tidak bocor ke mana pun, dan pagar (ukuran, format, throttle, satu
 * impor sekali jalan) menjawab 4xx berkode -- bukan 500.
 */
class StudioImporApiTest extends TestCase
{
    use RefreshDatabase;
    use WorkbookUji;

    private const URI = '/api/studio/workbook/impor';

    private Organization $lab;

    private User $studio;

    protected function setUp(): void
    {
        parent::setUp();
        // `arsip` sudah dipalsukan TestCase::setUp; `local` dipalsukan agar
        // pemeriksaan "tidak menulis ke disk container" bermakna.
        Storage::fake('local');
        config(['studio.disk_berkas' => 'arsip']);
        SumberModelHitung::lupakan();
        $this->siapkanFolderUji();

        $this->lab = Organization::factory()->create();
        $this->studio = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI, 'status' => User::STATUS_AKTIF]);
    }

    protected function tearDown(): void
    {
        SumberModelHitung::lupakan();
        $this->bersihkanFolderUji();
        parent::tearDown();
    }

    public function test_unggah_xlsx_tanpa_sandi_tersimpan_di_disk_awet_dan_hitung_jalan_sesudah_cache_hilang(): void
    {
        $respons = $this->unggah($this->buatXlsxHitung('Lembar Hitung Uji.xlsx'), alasan: 'Master baru dari lab')->assertCreated();

        $data = $respons->json('data');
        $this->assertSame('baru', $data['status']);
        $this->assertSame('aktif', $data['status_jenis']);
        $this->assertSame('Lembar Hitung Uji', $data['nama']);

        $wb = WorkbookMaster::query()->findOrFail($data['id']);
        $this->assertSame($this->lab->id, (int) $wb->organization_id);
        $this->assertSame('arsip', $wb->berkas_disk);
        $this->assertSame($this->studio->id, (int) $wb->diimpor_oleh);
        $this->assertTrue(Storage::disk('arsip')->exists((string) $wb->berkas_path));
        $this->assertSame([], Storage::disk('local')->allFiles(), 'tidak boleh menulis ke disk container');
        $this->assertTrue(Storage::disk('arsip')->exists(SumberModelHitung::jalur($wb->sha256)), 'cache model hitung ikut disk awet');

        // "Deploy ulang": memo proses dan cache turunan hilang; berkas asli (awet) tetap.
        SumberModelHitung::lupakan();
        Storage::disk('arsip')->delete(SumberModelHitung::jalur($wb->sha256));

        $lembar = $wb->lembar()->where('nama', 'INPUT DATA')->sole();
        $this->sebagaiStudio($this->studio)
            ->postJson("/api/studio/workbook/{$wb->id}/hitung", ['edit' => [['lembar_id' => $lembar->id, 'a' => 'B3', 'isi' => '21.5']]])
            ->assertOk()
            ->assertJsonPath('data.jumlah_dihitung', fn ($n) => $n >= 1);
    }

    public function test_audit_mencatat_pelaku_token_dan_alasan_unggahan(): void
    {
        $id = $this->unggah($this->buatXlsxHitung(), alasan: 'Revisi master Oktober 2026')->assertCreated()->json('data.id');

        $log = AuditLog::query()->where('entity_type', 'workbook_master')->where('entity_id', $id)->where('action', AuditLog::ACTION_DIBIKIN)->sole();
        $this->assertSame($this->studio->id, (int) $log->changed_by);
        $this->assertSame($this->lab->id, (int) $log->organization_id);
        $this->assertStringContainsString('Alasan impor: Revisi master Oktober 2026', (string) json_encode($log->new_data));
    }

    public function test_xlsm_bersandi_sandi_salah_kosong_dan_benar_tanpa_kebocoran_sandi(): void
    {
        $berkas = $this->buatXlsxHitung('Terkunci Uji.xlsx', self::SANDI_UJI);

        $this->unggah($berkas, alasan: 'Uji sandi', salin: true)
            ->assertStatus(422)->assertJsonPath('kode', 'sandi_diperlukan');

        $salah = $this->unggah($berkas, sandi: 'sandi-yang-salah-zzz', alasan: 'Uji sandi', salin: true)
            ->assertStatus(422)->assertJsonPath('kode', 'sandi_workbook_salah');
        $this->assertStringNotContainsString('sandi-yang-salah-zzz', (string) $salah->getContent());
        $this->assertSame(0, WorkbookMaster::query()->count());

        $benar = $this->unggah($berkas, sandi: self::SANDI_UJI, alasan: 'Uji sandi')->assertCreated();
        $id = (int) $benar->json('data.id');

        $this->assertStringNotContainsString(self::SANDI_UJI, (string) $benar->getContent());
        $wb = WorkbookMaster::query()->findOrFail($id);
        $this->assertTrue((bool) $wb->berkas_terenkripsi);
        $this->assertSame('arsip', $wb->berkas_disk);

        $jejak = json_encode([
            $wb->getAttributes(),
            AuditLog::query()->get()->map->getAttributes()->all(),
        ]);
        $this->assertStringNotContainsString(self::SANDI_UJI, (string) $jejak);
        $berkasLog = storage_path('logs/laravel.log');
        $this->assertStringNotContainsString(self::SANDI_UJI, is_file($berkasLog) ? (string) file_get_contents($berkasLog) : '', 'log tidak boleh memuat sandi');

        // Cache model hitung ada di disk awet: /hitung tidak butuh sandi lagi sesudah "deploy".
        SumberModelHitung::lupakan();
        $lembar = $wb->lembar()->where('nama', 'INPUT DATA')->sole();
        $this->sebagaiStudio($this->studio)
            ->postJson("/api/studio/workbook/{$id}/hitung", ['edit' => [['lembar_id' => $lembar->id, 'a' => 'B3', 'isi' => '19']]])
            ->assertOk();
    }

    public function test_csv_tunggal_diimpor_sebagai_workbook_satu_lembar(): void
    {
        $csv = $this->folderUji.DIRECTORY_SEPARATOR.'Master Uji CSV.csv';
        file_put_contents($csv, "Judul,1\r\nB,2.5\r\n");

        $data = $this->unggah($csv, alasan: 'Uji csv')->assertCreated()->json('data');

        $this->assertSame('csv', $data['sumber']);
        $this->assertSame('Master Uji CSV', $data['nama']);
        $this->assertSame(1, $data['lembar']);
    }

    public function test_unggah_jenis_sama_menggantikan_aktif_dan_lama_jadi_riwayat_isi_sama_sudah_ada(): void
    {
        $asalA = $this->buatXlsxHitung('Master Uji A.xlsx');
        $pertama = $this->unggah($asalA, alasan: 'Versi pertama', salin: true)->assertCreated()->json('data');

        // Berkas BERBEDA isinya, jenis ditunjuk lewat gantikan_id.
        $kedua = $this->unggah($this->buatXlsm('Master Uji B.xlsm', 1000, null), gantikanId: $pertama['id'], alasan: 'Versi kedua')->assertCreated()->json('data');
        $this->assertSame($pertama['id'], $kedua['menggantikan']);
        $this->assertSame($pertama['kunci_jenis'], $kedua['kunci_jenis']);
        $this->assertSame('aktif', $kedua['status_jenis']);

        $lama = WorkbookMaster::query()->findOrFail($pertama['id']);
        $this->assertFalse((bool) $lama->aktif, 'master lama jadi riwayat');
        $this->assertSame((int) $kedua['id'], (int) $lama->digantikan_oleh_id);
        $this->assertTrue(Storage::disk('arsip')->exists((string) $lama->berkas_path), 'berkas lama tidak dihapus');
        $this->assertSame(2, WorkbookMaster::query()->count());

        // Isi yang sama persis dengan yang sudah ada: tidak diimpor dua kali.
        $ulang = $this->unggah($asalA, alasan: 'Unggah ulang', gantikanId: $pertama['id'], salin: true)->assertOk();
        $ulang->assertJsonPath('data.status', 'sudah_ada');
        $this->assertSame(2, WorkbookMaster::query()->count());
    }

    public function test_gantikan_id_dan_kunci_jenis_tidak_sah_422(): void
    {
        $lab2 = Organization::factory()->create();
        $asing = WorkbookMaster::create([
            'organization_id' => $lab2->id, 'nama' => 'Asing', 'sumber' => WorkbookMaster::SUMBER_CSV,
            'sha256' => str_repeat('d', 64), 'ukuran' => 1, 'jumlah_lembar' => 0, 'kunci_jenis' => 'asing',
        ]);
        $berkas = $this->buatXlsxHitung();

        $this->unggah($berkas, gantikanId: 99999, alasan: 'Uji batas', salin: true)->assertStatus(422)->assertJsonPath('kode', 'jenis_tidak_dikenal');
        // Workbook lab lain tidak boleh ditunjuk (dan tidak bocor sebagai "ada").
        $this->unggah($berkas, gantikanId: $asing->id, alasan: 'Uji batas', salin: true)->assertStatus(422)->assertJsonPath('kode', 'jenis_tidak_dikenal');
        $this->unggah($berkas, kunciJenis: 'Kunci Tidak Sah!', alasan: 'Uji batas', salin: true)->assertStatus(422)->assertJsonPath('kode', 'jenis_tidak_dikenal');
        $this->assertSame(1, WorkbookMaster::query()->count());
    }

    public function test_validasi_alasan_ukuran_dan_format_422(): void
    {
        $xlsx = $this->buatXlsxHitung();

        $this->unggah($xlsx, alasan: '', salin: true)->assertStatus(422)->assertJsonValidationErrors('alasan');
        $this->unggah($xlsx, alasan: 'abc', salin: true)->assertStatus(422)->assertJsonValidationErrors('alasan');

        $this->sebagaiStudio($this->studio)->withHeader('Accept', 'application/json')
            ->post(self::URI, ['alasan' => 'Tanpa berkas'])->assertStatus(422)->assertJsonValidationErrors('berkas');

        // 31 MB > 30 MB.
        $this->sebagaiStudio($this->studio)->withHeader('Accept', 'application/json')
            ->post(self::URI, ['alasan' => 'Terlalu besar', 'berkas' => UploadedFile::fake()->create('Besar.xlsx', 31 * 1024)])
            ->assertStatus(422)->assertJsonValidationErrors('berkas');

        // Ekstensi lain.
        $this->sebagaiStudio($this->studio)->withHeader('Accept', 'application/json')
            ->post(self::URI, ['alasan' => 'Bukan excel', 'berkas' => UploadedFile::fake()->create('Dokumen.pdf', 10)])
            ->assertStatus(422)->assertJsonPath('kode', 'format_tidak_didukung');

        // Ekstensi benar, isi bukan OOXML.
        $this->unggah($this->berkasPalsu('Palsu.xlsx', 'ini bukan workbook sama sekali'), alasan: 'Isi bukan excel')
            ->assertStatus(422)->assertJsonPath('kode', 'berkas_bukan_ooxml');

        $this->assertSame(0, WorkbookMaster::query()->count());
        $this->assertSame([], Storage::disk('arsip')->allFiles());
    }

    public function test_berkas_temp_privat_tidak_tersisa_sesudah_unggahan_baik_dan_gagal(): void
    {
        $this->unggah($this->buatXlsxHitung(), alasan: 'Uji temp', salin: true)->assertCreated();
        $this->unggah($this->berkasPalsu('Palsu.xlsx', 'bukan'), alasan: 'Isi bukan excel')
            ->assertStatus(422);

        $akar = (string) config('studio.temp_workbook');
        $sisa = is_dir($akar) ? array_values(array_diff(scandir($akar) ?: [], ['.', '..'])) : [];
        $this->assertSame([], $sisa, json_encode(array_map(fn ($d) => scandir($akar.'/'.$d), $sisa)));
    }

    public function test_hanya_akun_studio_yang_boleh_mengunggah(): void
    {
        $berkas = $this->buatXlsxHitung();

        foreach ([['internal'], ['*']] as $abilities) {
            Sanctum::actingAs($this->studio, $abilities);
            $this->withHeader('Accept', 'application/json')
                ->post(self::URI, ['alasan' => 'Seharusnya ditolak', 'berkas' => new UploadedFile($berkas, 'x.xlsx', null, null, true)])
                ->assertForbidden();
            $this->app['auth']->forgetGuards();
        }

        $this->withHeader('Accept', 'application/json')->post(self::URI, [])->assertUnauthorized();
        $this->assertSame(0, WorkbookMaster::query()->count());
    }

    public function test_throttle_enam_per_menit_dan_satu_impor_sekali_jalan(): void
    {
        $palsu = fn () => $this->unggah($this->berkasPalsu('Palsu.xlsx', 'bukan'), alasan: 'Uji batas');

        for ($i = 0; $i < 6; $i++) {
            $palsu()->assertStatus(422);
            $this->app['auth']->forgetGuards();
        }

        $palsu()->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_impor_kedua_ditolak_429_selama_yang_pertama_berjalan(): void
    {
        $kunci = Cache::lock('studio-impor', 60);
        $this->assertTrue($kunci->get());

        try {
            $this->unggah($this->buatXlsxHitung(), alasan: 'Saat impor lain jalan')
                ->assertStatus(429)->assertJsonPath('kode', 'impor_berjalan');
        } finally {
            $kunci->release();
        }

        $this->assertSame(0, WorkbookMaster::query()->count());
    }

    private function berkasPalsu(string $nama, string $isi): string
    {
        $jalur = $this->folderUji.DIRECTORY_SEPARATOR.bin2hex(random_bytes(4)).'-'.$nama;
        file_put_contents($jalur, $isi);

        return $jalur;
    }

    /**
     * @param  bool  $salin  true = unggah SALINAN (UploadedFile::move memindahkan berkasnya)
     */
    private function unggah(string $jalur, ?string $sandi = null, string $alasan = 'Uji unggah master', ?int $gantikanId = null, ?string $kunciJenis = null, bool $salin = false): TestResponse
    {
        $sumber = $jalur;

        if ($salin) {
            $sumber = $this->folderUji.DIRECTORY_SEPARATOR.'salinan-'.bin2hex(random_bytes(4)).'-'.basename($jalur);
            copy($jalur, $sumber);
        }

        $isi = ['alasan' => $alasan, 'berkas' => new UploadedFile($sumber, basename($jalur), null, null, true)];

        foreach (['sandi' => $sandi, 'gantikan' => $gantikanId, 'kunci_jenis' => $kunciJenis] as $k => $v) {
            if ($v !== null) {
                $isi[$k] = $v;
            }
        }

        return $this->sebagaiStudio($this->studio)->withHeader('Accept', 'application/json')->post(self::URI, $isi);
    }
}
