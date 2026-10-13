<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\MicrometerProfile;
use App\Support\JsonKanonik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `php artisan acuan:impor-awal` — rollout Studio Data Acuan tahap 2
 * (`07-Migrasi-Rollout.md` §2–3).
 *
 * Yang dijaga: versi 1 = JSON berkas yang dikanonikkan (sha256-nya bisa
 * dicocokkan siapa pun dengan repo), perintahnya idempoten dan TIDAK PERNAH
 * menimpa isi yang beda, `berlaku_mulai` mengikuti sesi tertua profil itu
 * sendiri, dan organisasi lain tidak ikut tersentuh.
 */
class ImporAwalAcuanTest extends TestCase
{
    use RefreshDatabase;

    private const BERKAS = 'database/data/tabel-standar-micrometer.json';

    private static function kanonik(): string
    {
        return JsonKanonik::dariTeks((string) file_get_contents(base_path(self::BERKAS)));
    }

    private static function kodeMicrometer(): string
    {
        return (new MicrometerProfile)->kode();
    }

    /** Satu sesi beralat `$namaAlat` bertanggal `$tanggal` milik `$organisasi`. */
    private function sesi(Organization $organisasi, string $namaAlat, ?string $tanggal): CalibrationSession
    {
        $alat = Equipment::factory()->create([
            'organization_id' => $organisasi->id,
            'nama_alat_kemampuan' => $namaAlat,
        ]);

        return CalibrationSession::factory()->create([
            'organization_id' => $organisasi->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => User::factory()->create(['organization_id' => $organisasi->id])->id,
            'status' => CalibrationSession::STATUS_DRAFT,
            'tanggal_kalibrasi' => $tanggal,
        ]);
    }

    public function test_membuat_versi_satu_aktif_dengan_sha256_kanonik(): void
    {
        $organisasi = Organization::factory()->create();
        $kanonik = self::kanonik();
        $sha = JsonKanonik::sha256($kanonik);

        $this->artisan('acuan:impor-awal', ['profil' => self::kodeMicrometer()])
            ->expectsOutputToContain('kode_profil    : micrometer')
            ->expectsOutputToContain('berkas         : '.self::BERKAS)
            ->expectsOutputToContain("sha256 kanonik : {$sha}")
            ->expectsOutputToContain("organisasi #{$organisasi->id}")
            ->assertExitCode(0);

        $paket = PaketAcuan::query()->sole();
        $this->assertSame($organisasi->id, (int) $paket->organization_id);
        $this->assertSame('micrometer', $paket->kode_profil);
        $this->assertSame('Micrometer', $paket->nama);
        $this->assertSame(1, $paket->skema_versi);

        $versi = PaketAcuanVersi::query()->sole();
        $this->assertSame($paket->id, (int) $versi->paket_acuan_id);
        $this->assertSame($organisasi->id, (int) $versi->organization_id);
        $this->assertSame(PaketAcuanVersi::JENIS_DATA, $versi->jenis);
        $this->assertSame(1, $versi->nomor_versi);
        $this->assertSame(PaketAcuanVersi::STATUS_AKTIF, $versi->status);
        $this->assertSame($kanonik, $versi->isi);
        $this->assertSame($sha, $versi->isi_sha256);
        $this->assertSame(1, $versi->skema_versi);
        $this->assertSame('Impor awal dari '.self::BERKAS, $versi->alasan);
        $this->assertSame([[
            'jenis' => 'berkas_repo',
            'nilai' => self::BERKAS,
            'catatan' => $sha,
        ]], $versi->rujukan);
        $this->assertSame('2024-01-01', $versi->berlaku_mulai->toDateString());
        $this->assertNull($versi->berlaku_sampai);

        // Impor bukan pengesahan — tidak ada yang dikarang menyetujuinya.
        $this->assertNull($versi->disahkan_oleh);
        $this->assertNull($versi->disahkan_pada);

        // Nilai & tipe isi = berkas (urutan kunci objek boleh lain: kanonik).
        $this->assertEquals(
            json_decode((string) file_get_contents(base_path(self::BERKAS)), true),
            $versi->isiTerurai(),
        );
    }

    public function test_tanpa_argumen_mengimpor_profil_yang_punya_skema(): void
    {
        Organization::factory()->create();

        $this->artisan('acuan:impor-awal')->assertExitCode(0);

        $this->assertSame(['micrometer'], PaketAcuan::query()->pluck('kode_profil')->all());
    }

    public function test_jalan_kedua_tidak_menambah_baris(): void
    {
        Organization::factory()->create();
        $sha = JsonKanonik::sha256(self::kanonik());

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer'])->assertExitCode(0);
        $sebelum = PaketAcuanVersi::query()->sole()->only(['id', 'isi', 'isi_sha256', 'berlaku_mulai', 'updated_at']);

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer'])
            ->expectsOutputToContain('sudah ada, sama')
            ->expectsOutputToContain($sha)
            ->assertExitCode(0);

        $this->assertSame(1, PaketAcuan::query()->count());
        $this->assertSame(1, PaketAcuanVersi::query()->count());
        $this->assertEquals($sebelum, PaketAcuanVersi::query()->sole()->only(['id', 'isi', 'isi_sha256', 'berlaku_mulai', 'updated_at']));
    }

    public function test_isi_versi_satu_yang_beda_gagal_menyebut_kedua_sha256_dan_tidak_ditimpa(): void
    {
        Organization::factory()->create();
        $shaBerkas = JsonKanonik::sha256(self::kanonik());

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer'])->assertExitCode(0);

        // Satu nilai terkoreksi bergeser — sha256 versi di DB ikut dihitung
        // ulang dengan benar, jadi yang beda memang ISInya.
        $isi = PaketAcuanVersi::query()->sole()->isiTerurai();
        $isi['balok_ukur']['2.5'] = 2.50015;
        $isiLain = JsonKanonik::serialisasi($isi);
        $shaLain = JsonKanonik::sha256($isiLain);
        DB::table('paket_acuan_versi')->update(['isi' => $isiLain, 'isi_sha256' => $shaLain]);

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer'])
            ->expectsOutputToContain($shaLain)
            ->expectsOutputToContain($shaBerkas)
            ->assertExitCode(1);

        $this->assertSame(1, PaketAcuanVersi::query()->count());
        $this->assertSame($isiLain, PaketAcuanVersi::query()->sole()->isi);
    }

    public function test_isi_sha256_tersimpan_yang_tidak_cocok_dengan_isinya_ditolak(): void
    {
        Organization::factory()->create();

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer'])->assertExitCode(0);
        DB::table('paket_acuan_versi')->update(['isi_sha256' => str_repeat('0', 64)]);

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer'])
            ->expectsOutputToContain('tidak cocok dengan isi_sha256')
            ->assertExitCode(1);
    }

    public function test_berlaku_mulai_dari_sesi_micrometer_tertua_organisasi(): void
    {
        $organisasi = Organization::factory()->create();
        $lain = Organization::factory()->create();
        $namaPh = app(CalibrationProfileRegistry::class)->untukKode('ph_meter')->namaAlatKemampuan();

        $this->sesi($organisasi, 'Micrometer', '2025-03-10');
        $this->sesi($organisasi, 'Mikrometer', '2023-11-05'); // alias, profil sama
        $this->sesi($organisasi, 'Micrometer', null);
        // Lebih tua, tapi bukan Micrometer — atau bukan organisasi ini.
        $this->sesi($organisasi, $namaPh, '2022-01-01');
        $this->sesi($lain, 'Micrometer', '2021-01-01');

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer', '--organisasi' => $organisasi->id])
            ->assertExitCode(0);

        $this->assertSame('2023-11-05', PaketAcuanVersi::query()->sole()->berlaku_mulai->toDateString());
    }

    public function test_alat_yang_sudah_dihapus_tetap_dihitung_untuk_berlaku_mulai(): void
    {
        $organisasi = Organization::factory()->create();
        $sesi = $this->sesi($organisasi, 'Micrometer', '2023-06-01');
        $sesi->equipment->delete();

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer'])->assertExitCode(0);

        $this->assertSame('2023-06-01', PaketAcuanVersi::query()->sole()->berlaku_mulai->toDateString());
    }

    public function test_organisasi_lain_terisolasi(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();
        $this->sesi($b, 'Micrometer', '2024-08-20');

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer', '--organisasi' => $a->id])
            ->assertExitCode(0);

        $this->assertSame([$a->id], PaketAcuan::query()->pluck('organization_id')->map(fn ($id) => (int) $id)->all());

        $versiA = PaketAcuanVersi::query()->sole();
        $this->assertSame('2024-01-01', $versiA->berlaku_mulai->toDateString(), 'sesi organisasi B tidak boleh menggeser A');

        // Tanpa --organisasi: B dapat paketnya sendiri, A tidak berubah.
        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer'])->assertExitCode(0);

        $this->assertSame(2, PaketAcuan::query()->count());
        $this->assertSame(1, PaketAcuanVersi::query()->where('organization_id', $a->id)->count());

        $versiB = PaketAcuanVersi::query()->where('organization_id', $b->id)->sole();
        $paketB = PaketAcuan::query()->where('organization_id', $b->id)->sole();
        $this->assertSame($paketB->id, (int) $versiB->paket_acuan_id);
        $this->assertSame('2024-08-20', $versiB->berlaku_mulai->toDateString());
        $this->assertSame($versiA->isi_sha256, $versiB->isi_sha256);
        $this->assertEquals($versiA->fresh()->updated_at, $versiA->updated_at);
    }

    public function test_profil_tanpa_skema_atau_tak_dikenal_ditolak(): void
    {
        Organization::factory()->create();

        $this->artisan('acuan:impor-awal', ['profil' => 'ph_meter'])
            ->expectsOutputToContain('belum punya skemaAcuan()')
            ->assertExitCode(1);

        $this->artisan('acuan:impor-awal', ['profil' => 'tidak_ada'])
            ->expectsOutputToContain('tidak dikenal')
            ->assertExitCode(1);

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer', '--organisasi' => 999999])
            ->expectsOutputToContain('tidak ada')
            ->assertExitCode(1);

        $this->assertSame(0, PaketAcuan::query()->count());
    }

    /**
     * `isi` tidak disalin ke `audit_logs` (kolomDilewat), `isi_sha256` tetap.
     */
    public function test_audit_versi_tidak_menyalin_isi_tapi_mencatat_sha256(): void
    {
        Organization::factory()->create();

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer'])->assertExitCode(0);

        $versi = PaketAcuanVersi::query()->sole();
        $audit = AuditLog::query()
            ->where('entity_type', 'paket_acuan_versi')
            ->where('entity_id', $versi->id)
            ->where('action', AuditLog::ACTION_DIBIKIN)
            ->sole();

        $this->assertArrayNotHasKey('isi', $audit->new_data);
        $this->assertSame($versi->isi_sha256, $audit->new_data['isi_sha256']);
    }
}
