<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Organization;
use App\Models\PaketAcuanLog;
use App\Models\PaketAcuanVersi;
use App\Models\SimulasiAcuan;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\Acuan\SimulasiVersiAcuan;
use App\Services\Acuan\SumberAcuan;
use App\Services\Studio\Workbook\ImporWorkbook;
use App\Support\JsonKanonik;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use RuntimeException;
use Tests\Concerns\SesiDataAcuan;
use Tests\TestCase;

/**
 * Alur resmi versi data acuan lapis 1: master v2 → draf → ajukan → simulasi →
 * sahkan (orang kedua) → berlaku untuk sesi baru (AGENTS.md §Olah data butir 3;
 * Studio Data Acuan T2.2/T2.3).
 *
 * Masternya ekspor CSV ASLI workbook Micrometer di repo; v2 = balok ukur 19 mm
 * digeser 2 µm di Standar_GB (varian B) — titik 1 sesi contoh (25,0 = 6+19 mm)
 * & titik 2 (27,5 = 2,5+6+19 mm) bergeser, titik 3 (31,0 = 14+17 mm) tidak.
 */
class AlurVersiAcuanTest extends TestCase
{
    use RefreshDatabase;
    use SesiDataAcuan;

    private const AKAR = 'Project-PT-Sidik/alat-alat-Pt-Sidik/Panjang_CSV/';

    private const MASTER_A = 'Master_Olah_Data_Micrometer_0-25mm';

    private const MASTER_B = 'Master_Olah_Data_Micrometer_25-50mm';

    private const ALASAN = 'Rekalibrasi balok ukur 19 mm, sertifikat GB terbaru.';

    private string $temp = '';

    private Organization $org;

    private User $admin;

    private User $sa1;

    private User $sa2;

    protected function setUp(): void
    {
        parent::setUp();
        SumberAcuan::lupakanCache();
        config(['studio.peta_sel.direktori' => database_path('data/peta-sel')]);

        $this->siapkanAcuan();
        $this->org = Organization::query()->findOrFail($this->teknisi->organization_id);
        $this->admin = User::query()->where('organization_id', $this->org->id)->where('role', User::ROLE_ADMIN)->firstOrFail();
        $this->sa1 = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->sa2 = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_SUPER_ADMIN]);
    }

    protected function tearDown(): void
    {
        if ($this->temp !== '' && is_dir($this->temp)) {
            (new Filesystem)->deleteDirectory($this->temp);
        }

        parent::tearDown();
    }

    /**
     * Master A asli (varian lain, aktif) + master B v2 (balok 19 → 19,00211)
     * diimpor admin → draf v2.
     */
    private function drafV2(?int $pengimpor = null): PaketAcuanVersi
    {
        $impor = app(ImporWorkbook::class);
        $pengimpor ??= $this->admin->id;

        $a = $impor->imporCsv(base_path(self::AKAR.self::MASTER_A), self::MASTER_A, 'uji', $this->org, $pengimpor, false);
        $this->assertSame('sama_dengan_dasar', $a['acuan']['status']);

        $this->temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sidik-alur-'.bin2hex(random_bytes(5));
        $folder = $this->temp.DIRECTORY_SEPARATOR.self::MASTER_B;
        (new Filesystem)->copyDirectory(base_path(self::AKAR.self::MASTER_B), $folder);

        $berkas = $folder.'/Standar_GB.csv';
        $h = fopen($berkas, 'rb');
        $baris = [];

        while (($r = fgetcsv($h, null, ',', '"', '')) !== false) {
            $baris[] = $r;
        }

        fclose($h);
        $baris[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $baris[0][0]);

        foreach ($baris as $i => $r) {
            if (($r[16] ?? '') === '19') { // kolom Q
                $baris[$i][17] = '19.00211'; // kolom R
            }
        }

        $h = fopen($berkas, 'wb');

        foreach ($baris as $r) {
            fputcsv($h, $r, ',', '"', '');
        }

        fclose($h);

        $b = $impor->imporCsv($folder, self::MASTER_B, 'uji', $this->org, $pengimpor, false);
        $this->assertSame('draf_dibuat', $b['acuan']['status']);

        return PaketAcuanVersi::query()->findOrFail($b['acuan']['versi_id']);
    }

    private function ajukan(User $oleh, PaketAcuanVersi $v): TestResponse
    {
        return $this->actingAs($oleh)->postJson("/api/data-acuan/versi/{$v->id}/ajukan", ['alasan' => self::ALASAN]);
    }

    private function simulasi(User $oleh, PaketAcuanVersi $v): TestResponse
    {
        return $this->actingAs($oleh)->postJson("/api/data-acuan/versi/{$v->id}/simulasi");
    }

    /** @param  array<string, string>  $pengakuan */
    private function sahkan(User $oleh, PaketAcuanVersi $v, array $pengakuan = [], ?string $mulai = null): TestResponse
    {
        return $this->actingAs($oleh)->postJson("/api/data-acuan/versi/{$v->id}/aktifkan", [
            'berlaku_mulai' => $mulai ?? now()->toDateString(),
            'password' => 'password',
            'pengakuan' => $pengakuan,
        ]);
    }

    /** @return array<string, mixed> laporan sesi `$id` di simulasi terakhir */
    private function laporanSesi(PaketAcuanVersi $v, int $id): array
    {
        $laporan = SimulasiAcuan::query()->findOrFail($v->fresh()->simulasi_terakhir_id)->laporanTerurai();

        return collect($laporan['sesi'])->firstWhere('sesi_id', $id) ?? $this->fail("sesi {$id} tidak ada di laporan");
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_alur_penuh_master_v2_sampai_berlaku_untuk_sesi_baru_saja(): void
    {
        // Sesi berstempel v1 yang sertifikatnya sudah TERBIT.
        $idV1 = $this->simpanSesi($this->payloadMicrometer());
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$idV1]])->assertSuccessful();
        $ulangV1 = $this->angkaSesi($idV1);
        Certificate::factory()->create(['calibration_session_id' => $idV1, 'organization_id' => $this->org->id, 'status' => Certificate::STATUS_TERBIT]);

        // Pembanding hari ini, dihitung v1 (sebelum v2 sah).
        $hariIni = $this->payloadMicrometer(['tanggal_kalibrasi' => now()->toDateString()]);
        $idHariIniV1 = $this->simpanSesi($hariIni);
        $angkaHariIniV1 = $this->angkaSesi($idHariIniV1);

        $v2 = $this->drafV2();

        // Ajukan (Master Data) → isi dibekukan.
        $this->ajukan($this->admin, $v2)->assertOk()->assertJsonPath('data.status', 'diajukan');
        $v2->refresh();
        $this->assertSame(JsonKanonik::sha256((string) $v2->isi), $v2->isi_sha256);

        // Sahkan tanpa simulasi → ditolak.
        $this->sahkan($this->sa1, $v2)->assertStatus(422)->assertJsonPath('kode', 'simulasi_belum_ada');

        // Simulasi: titik 1 bergeser, titik 3 tidak; angka cetak ikut.
        $sim = $this->simulasi($this->admin, $v2)->assertCreated()->json('data');
        $this->assertSame('terbaru', $sim['status']);
        $this->assertGreaterThanOrEqual(1, $sim['ringkasan']['sesi_berubah']);
        $this->assertGreaterThanOrEqual(1, $sim['ringkasan']['terbit_cetak_berubah']);
        $this->assertSame(1, $sim['ringkasan']['bersama_antar_varian']);
        $this->assertSame(['terbit_cetak_berubah', 'bersama_antar_varian'], $sim['wajib_diakui']);

        $lap = $this->laporanSesi($v2, $idV1);
        $this->assertTrue($lap['sudah_terbit']);
        $this->assertTrue($lap['cetak_berubah']);
        $titikBerubah = collect($lap['selisih'])->pluck('titik')->unique()->values()->all();
        $this->assertSame([1, 2], $titikBerubah, 'titik 1 (6+19) & 2 (2,5+6+19) memakai balok 19; titik 3 (14+17) tidak');
        $error = collect($lap['selisih'])->firstWhere('kolom', 'error');
        $this->assertNotNull($error);
        $this->assertEqualsWithDelta(-0.002, $error['selisih'], 1e-6, 'balok +2 µm → error −2 µm');
        $this->assertContains(1, collect($lap['cetak'])->pluck('titik')->all());
        $this->assertContains('koreksi', collect($lap['cetak'])->where('titik', 1)->pluck('kolom')->all());
        $this->assertNotContains(3, collect($lap['cetak'])->pluck('titik')->all());

        // Data bersama antar varian: master A aktif tidak sepakat soal balok 19.
        $bersama = SimulasiAcuan::query()->findOrFail($sim['id'])->laporanTerurai()['bersama_antar_varian'];
        $this->assertSame('B', $bersama['varian_draf']);
        $this->assertSame([['jalur' => 'balok_ukur/19.0', 'lama' => '19.00011', 'baru' => '19.00211']], $bersama['berubah']);
        $masterA = collect($bersama['master_lain'])->firstWhere('varian', 'A');
        $this->assertSame([['jalur' => 'balok_ukur/19.0', 'master' => '19.00011', 'draf' => '19.00211']], $masterA['beda']);

        // Simulasi tidak menulis apa pun ke sesi.
        $this->assertEquals($ulangV1, $this->angkaSesi($idV1));

        // Admin (Master Data) bukan pengesah; super admin tanpa pengakuan ditolak.
        $this->sahkan($this->admin, $v2)->assertForbidden();
        $this->sahkan($this->sa1, $v2)->assertStatus(422)->assertJsonPath('kode', 'pengakuan_wajib')
            ->assertJsonPath('rincian.butir', ['terbit_cetak_berubah', 'bersama_antar_varian']);
        $this->sahkan($this->sa1, $v2, ['bersama_antar_varian' => 'Balok 19 mm direkalibrasi; master A-D akan diselaraskan.'])
            ->assertStatus(422)->assertJsonPath('rincian.butir', ['terbit_cetak_berubah']);
        $this->actingAs($this->sa1)->postJson("/api/data-acuan/versi/{$v2->id}/aktifkan", [
            'berlaku_mulai' => now()->toDateString(), 'password' => 'salah',
        ])->assertStatus(422)->assertJsonPath('kode', 'konfirmasi_sandi_salah');
        $this->sahkan($this->sa1, $v2, [], now()->subDay()->toDateString())->assertStatus(422);

        $pengakuan = [
            'terbit_cetak_berubah' => 'Sertifikat terbit tetap memakai v1 (BR-11); tidak diterbitkan ulang.',
            'bersama_antar_varian' => 'Balok 19 mm direkalibrasi; master A-D akan diselaraskan.',
        ];
        $this->sahkan($this->sa1, $v2, $pengakuan)->assertOk()->assertJsonPath('data.status', 'aktif');

        $v2->refresh();
        $this->v1->refresh();
        $this->assertSame([PaketAcuanVersi::STATUS_AKTIF, now()->toDateString(), $this->sa1->id], [$v2->status, $v2->berlaku_mulai->toDateString(), $v2->disahkan_oleh]);
        $this->assertSame([PaketAcuanVersi::STATUS_PENSIUN, now()->subDay()->toDateString()], [$this->v1->status, $this->v1->berlaku_sampai->toDateString()]);

        // Sesi baru hari ini → v2, titik 1 bergeser, titik 3 tetap.
        $idHariIniV2 = $this->simpanSesi($hariIni);
        $this->assertSame([$v2->id, $v2->id, $v2->id], $this->stempelSesi($idHariIniV2));
        $angkaV2 = $this->angkaSesi($idHariIniV2);
        $this->assertNotEquals($angkaHariIniV1[0]['error'], $angkaV2[0]['error']);
        $this->assertEquals($angkaHariIniV1[2]['error'], $angkaV2[2]['error']);

        // Sesi berstempel v1 tetap v1 sesudah dihitung ulang.
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$idV1]])->assertSuccessful();
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($idV1));
        $this->assertEquals($ulangV1, $this->angkaSesi($idV1));

        // Log metode append-only: sumber master + pengakuan tercatat.
        $log = PaketAcuanLog::query()->where('paket_acuan_versi_id', $v2->id)->orderBy('id')->get();
        $this->assertSame(['dibuat', 'ajukan', 'simulasi', 'sahkan'], $log->pluck('aksi')->all());
        $masterB = WorkbookMaster::query()->where('nama', self::MASTER_B)->where('aktif', true)->sole();
        foreach ($log as $l) {
            $this->assertSame($masterB->id, $l->sumber['workbook_master_id']);
            $this->assertSame($masterB->sha256, $l->sumber['workbook_sha256']);
        }
        $sah = $log->last();
        $this->assertSame([$this->sa1->id, $pengakuan], [$sah->user_id, $sah->rincian['pengakuan']]);
        $this->assertSame([$this->v1->id], $sah->rincian['versi_dipensiunkan']);
        $this->assertSame('pensiun', PaketAcuanLog::query()->where('paket_acuan_versi_id', $this->v1->id)->sole()->aksi);

        $this->expectException(LogicException::class);
        $sah->update(['alasan' => 'diubah']);
    }

    public function test_pembuat_pengaju_atau_pengimpor_tidak_bisa_mengesahkan(): void
    {
        // Super admin 1 mengimpor master & mengajukan.
        $v2 = $this->drafV2($this->sa1->id);
        $this->ajukan($this->sa1, $v2)->assertOk();
        $this->simulasi($this->sa2, $v2)->assertCreated();

        $this->sahkan($this->sa1, $v2, ['bersama_antar_varian' => 'Balok 19 mm direkalibrasi (uji).'])
            ->assertStatus(422)->assertJsonPath('kode', 'pengesah_ikut_menyunting');

        // Orang kedua boleh.
        $this->sahkan($this->sa2, $v2, ['bersama_antar_varian' => 'Balok 19 mm direkalibrasi (uji).'])->assertOk();
    }

    public function test_simulasi_kedaluwarsa_ditolak(): void
    {
        $v2 = $this->drafV2();
        $this->simulasi($this->admin, $v2)->assertCreated();

        // Draf disunting sesudah simulasi.
        $isi = $v2->isiTerurai();
        $isi['balok_ukur']['19.0'] = 19.00311;
        $v2->forceFill(['isi' => JsonKanonik::serialisasi($isi)])->save();

        $this->ajukan($this->admin, $v2)->assertOk();
        $this->actingAs($this->admin)->getJson("/api/data-acuan/versi/{$v2->id}/simulasi")
            ->assertOk()->assertJsonPath('data.status', 'kedaluwarsa');
        $this->sahkan($this->sa1, $v2, ['bersama_antar_varian' => 'Balok 19 mm direkalibrasi (uji).'])
            ->assertStatus(422)->assertJsonPath('kode', 'simulasi_kedaluwarsa');

        // Sesi baru profil ini sesudah simulasi → kedaluwarsa.
        $this->simulasi($this->admin, $v2)->assertCreated();
        $id = $this->simpanSesi($this->payloadMicrometer());
        $this->sahkan($this->sa1, $v2, ['bersama_antar_varian' => 'Balok 19 mm direkalibrasi (uji).'])
            ->assertStatus(422)->assertJsonPath('kode', 'simulasi_kedaluwarsa');

        // Sertifikat terbit sesudah simulasi → kedaluwarsa.
        $this->simulasi($this->admin, $v2)->assertCreated();
        Certificate::factory()->create(['calibration_session_id' => $id, 'organization_id' => $this->org->id, 'status' => Certificate::STATUS_TERBIT]);
        $this->sahkan($this->sa1, $v2, ['bersama_antar_varian' => 'Balok 19 mm direkalibrasi (uji).'])
            ->assertStatus(422)->assertJsonPath('kode', 'simulasi_kedaluwarsa');

        $this->simulasi($this->admin, $v2)->assertCreated()->assertJsonPath('data.status', 'terbaru');
        $this->sahkan($this->sa1, $v2, [
            'bersama_antar_varian' => 'Balok 19 mm direkalibrasi (uji).',
            'terbit_cetak_berubah' => 'Sertifikat terbit tetap memakai v1 (uji).',
        ])->assertOk();
    }

    public function test_tolak_dan_buang_dengan_alasan_tanpa_menghapus(): void
    {
        $v2 = $this->drafV2();
        $this->actingAs($this->admin)->postJson("/api/data-acuan/versi/{$v2->id}/tolak", ['alasan' => 'pendek'])->assertStatus(422);

        $this->ajukan($this->admin, $v2)->assertOk();
        $this->actingAs($this->admin)->postJson("/api/data-acuan/versi/{$v2->id}/tolak", ['alasan' => 'Nilai balok belum diverifikasi lab.'])
            ->assertForbidden()->assertJsonPath('kode', 'hanya_super_admin');
        $this->actingAs($this->sa1)->postJson("/api/data-acuan/versi/{$v2->id}/tolak", ['alasan' => 'Nilai balok belum diverifikasi lab.'])
            ->assertOk()->assertJsonPath('data.status', 'ditolak');
        $this->ajukan($this->admin, $v2)->assertStatus(422)->assertJsonPath('kode', 'status_tidak_sesuai');

        $this->assertSame(PaketAcuanVersi::STATUS_DITOLAK, $v2->fresh()->status);
        $this->assertSame(['dibuat', 'ajukan', 'tolak'], PaketAcuanLog::query()->where('paket_acuan_versi_id', $v2->id)->orderBy('id')->pluck('aksi')->all());

        // Draf dibuang Master Data.
        $isi = $v2->isiTerurai();
        $isi['balok_ukur']['19.0'] = 19.00411;
        $draf = PaketAcuanVersi::create([
            ...$v2->only(['paket_acuan_id', 'organization_id', 'jenis', 'skema_versi', 'dasar_versi_id']),
            'nomor_versi' => 3, 'status' => PaketAcuanVersi::STATUS_DRAF, 'isi' => JsonKanonik::serialisasi($isi),
        ]);
        $this->actingAs($this->admin)->postJson("/api/data-acuan/versi/{$draf->id}/tolak", ['alasan' => 'Salah impor, master keliru.'])
            ->assertOk()->assertJsonPath('data.status', 'dibuang');
        $this->assertNotNull($draf->fresh());
    }

    public function test_teknisi_viewer_dan_pelanggan_ditolak(): void
    {
        $v2 = $this->drafV2();
        $viewer = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_VIEWER]);
        $pelanggan = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_PELANGGAN]);

        foreach ([$this->teknisi, $viewer, $pelanggan] as $u) {
            foreach (['ajukan', 'simulasi', 'tolak', 'aktifkan'] as $aksi) {
                $s = $this->actingAs($u)->postJson("/api/data-acuan/versi/{$v2->id}/{$aksi}", ['alasan' => self::ALASAN])->getStatusCode();
                $this->assertSame(403, $s, "{$u->role} {$aksi}");
            }
        }

        $this->actingAs($this->teknisi)->getJson('/api/data-acuan/paket/micrometer/alur')->assertNotFound();
        $this->actingAs($this->teknisi)->getJson("/api/data-acuan/versi/{$v2->id}/simulasi")->assertNotFound();
        $this->actingAs($viewer)->getJson('/api/data-acuan/paket/micrometer/alur')->assertOk();
        $this->assertSame(PaketAcuanVersi::STATUS_DRAF, $v2->fresh()->status);
    }

    public function test_organisasi_lain_404(): void
    {
        $v2 = $this->drafV2();
        $lab2 = Organization::factory()->create();
        $admin2 = User::factory()->admin()->create(['organization_id' => $lab2->id]);
        $sa2 = User::factory()->create(['organization_id' => $lab2->id, 'role' => User::ROLE_SUPER_ADMIN]);

        foreach ([$admin2, $sa2] as $u) {
            foreach (['ajukan', 'simulasi', 'tolak'] as $aksi) {
                $this->actingAs($u)->postJson("/api/data-acuan/versi/{$v2->id}/{$aksi}", ['alasan' => self::ALASAN])->assertNotFound();
            }

            $this->actingAs($u)->getJson("/api/data-acuan/versi/{$v2->id}/simulasi")->assertNotFound();
            $this->actingAs($u)->getJson('/api/data-acuan/paket/micrometer/alur')->assertNotFound();
        }

        $this->actingAs($sa2)->postJson("/api/data-acuan/versi/{$v2->id}/aktifkan", ['berlaku_mulai' => now()->toDateString(), 'password' => 'password'])
            ->assertNotFound();
        $this->assertSame(PaketAcuanVersi::STATUS_DRAF, $v2->fresh()->status);
        $this->assertSame(0, SimulasiAcuan::query()->count());
    }

    /** Semua butir pengakuan sekaligus — butir yang tidak diwajibkan diabaikan. */
    private const AKUI_SEMUA = [
        'terbit_cetak_berubah' => 'Sertifikat terbit tetap memakai versinya (uji).',
        'bersama_antar_varian' => 'Balok 19 mm direkalibrasi (uji).',
        'sesi_gagal' => 'Sesi gagal disimulasikan sudah ditinjau (uji).',
    ];

    public function test_pengaktifan_kedua_di_paket_sama_melihat_yang_pertama(): void
    {
        $v2 = $this->drafV2();
        $this->ajukan($this->admin, $v2)->assertOk();
        $this->simulasi($this->admin, $v2)->assertCreated();
        $this->sahkan($this->sa1, $v2, self::AKUI_SEMUA)->assertOk();

        // v3 berdasar v2, dibuat Master Data.
        $isi = $v2->fresh()->isiTerurai();
        $isi['balok_ukur']['19.0'] = 19.00311;
        $v3 = PaketAcuanVersi::create([
            ...$v2->only(['paket_acuan_id', 'organization_id', 'jenis', 'skema_versi']),
            'dasar_versi_id' => $v2->id, 'nomor_versi' => 3, 'status' => PaketAcuanVersi::STATUS_DRAF,
            'isi' => JsonKanonik::serialisasi($isi), 'dibuat_oleh' => $this->admin->id,
        ]);
        $this->ajukan($this->admin, $v3)->assertOk();
        $this->simulasi($this->admin, $v3)->assertCreated();

        // Mulai hari yang sama dengan v2 → bentrok (BR-03), tidak ada yang berubah.
        $this->sahkan($this->sa2, $v3, self::AKUI_SEMUA)->assertStatus(422)->assertJsonPath('kode', 'rentang_bentrok');
        $this->assertSame(PaketAcuanVersi::STATUS_AKTIF, $v2->fresh()->status);

        $besok = now()->addDay()->toDateString();
        $this->sahkan($this->sa2, $v3, self::AKUI_SEMUA, $besok)->assertOk();

        $v2->refresh();
        $this->assertSame([PaketAcuanVersi::STATUS_PENSIUN, now()->toDateString()], [$v2->status, $v2->berlaku_sampai->toDateString()]);
        $this->assertSame(1, PaketAcuanVersi::query()->where('paket_acuan_id', $v2->paket_acuan_id)->where('status', PaketAcuanVersi::STATUS_AKTIF)->count());
        $this->assertSame([$v2->id], PaketAcuanLog::query()->where('paket_acuan_versi_id', $v3->id)->where('aksi', 'sahkan')->sole()->rincian['versi_dipensiunkan']);
        $this->assertSame(['pensiun', 'pensiun'], [$this->v1->fresh()->status, $v2->status]);
    }

    public function test_log_dan_simulasi_tidak_bisa_diubah_atau_dihapus_massal(): void
    {
        $v2 = $this->drafV2();
        $this->simulasi($this->admin, $v2)->assertCreated();
        $this->assertGreaterThan(0, PaketAcuanLog::query()->count());
        $this->assertGreaterThan(0, SimulasiAcuan::query()->count());

        foreach ([
            fn () => PaketAcuanLog::query()->update(['alasan' => 'diubah']),
            fn () => PaketAcuanLog::query()->delete(),
            fn () => SimulasiAcuan::query()->update(['status' => 'gagal']),
            fn () => SimulasiAcuan::query()->delete(),
            fn () => DB::table('paket_acuan_log')->delete(),
        ] as $i => $aksi) {
            try {
                $aksi();
                $this->fail("aksi massal #{$i} lolos");
            } catch (QueryException) {
                // ditahan trigger
            }
        }

        $this->assertSame(['dibuat', 'simulasi'], PaketAcuanLog::query()->where('paket_acuan_versi_id', $v2->id)->orderBy('id')->pluck('aksi')->all());
    }

    public function test_simulasi_tidak_menulis_sesi_hasil_maupun_sertifikat(): void
    {
        $id = $this->simpanSesi($this->payloadMicrometer());
        Certificate::factory()->create(['calibration_session_id' => $id, 'organization_id' => $this->org->id, 'status' => Certificate::STATUS_TERBIT]);
        $v2 = $this->drafV2();

        $keadaan = static fn (): array => collect(['calibration_sessions', 'uncertainty_calculations', 'certificates', 'raw_measurements'])
            ->mapWithKeys(static fn (string $t): array => [$t => [DB::table($t)->count(), DB::table($t)->max('updated_at')]])
            ->all();

        $sebelum = $keadaan();
        $this->travel(5)->minutes();
        $this->simulasi($this->admin, $v2)->assertCreated()->assertJsonPath('data.ringkasan.sesi_berubah', fn ($n) => $n >= 1);
        $this->assertSame($sebelum, $keadaan());
    }

    public function test_token_studio_ditolak_di_rute_tulis(): void
    {
        $v2 = $this->drafV2();

        $this->sebagaiStudio($this->sa1)->postJson("/api/data-acuan/versi/{$v2->id}/ajukan", ['alasan' => self::ALASAN])->assertForbidden();
        $this->sebagaiStudio($this->sa1)->postJson("/api/data-acuan/versi/{$v2->id}/simulasi")->assertForbidden();
        $this->sebagaiStudio($this->sa1)->postJson("/api/data-acuan/versi/{$v2->id}/aktifkan", ['berlaku_mulai' => now()->toDateString(), 'password' => 'password'])->assertForbidden();
        $this->assertSame(PaketAcuanVersi::STATUS_DRAF, $v2->fresh()->status);
    }

    public function test_sumber_acuan_dipulihkan_walau_simulasi_melempar(): void
    {
        $v2 = $this->drafV2();
        config(['acuan.sumber' => 'json']);

        CalibrationSession::retrieved(static function (): never {
            throw new RuntimeException('galat uji di tengah simulasi');
        });

        try {
            app(SimulasiVersiAcuan::class)->jalankan($v2);
            $this->fail('simulasi seharusnya melempar');
        } catch (RuntimeException $e) {
            $this->assertSame('galat uji di tengah simulasi', $e->getMessage());
        }

        $this->assertSame('json', config('acuan.sumber'));
    }
}
