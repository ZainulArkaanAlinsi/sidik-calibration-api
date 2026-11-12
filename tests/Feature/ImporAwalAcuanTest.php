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
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tests\Unit\SkemaAcuanProfilTest;

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

        $this->assertSame(SkemaAcuanProfilTest::BERSKEMA, PaketAcuan::query()->pluck('kode_profil')->all());
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

    /** @return array{paket: int, versi: int, audit: int, audit_paket: int} */
    private function cacahBaris(): array
    {
        return [
            'paket' => DB::table('paket_acuan')->count(),
            'versi' => DB::table('paket_acuan_versi')->count(),
            'audit' => DB::table('audit_logs')->count(),
            'audit_paket' => DB::table('audit_logs')->whereIn('entity_type', ['paket_acuan', 'paket_acuan_versi'])->count(),
        ];
    }

    /**
     * Angka di baris ringkasan "label    : angka". Tidak bergantung pada akhir
     * baris (Windows memberi `\r\n`) maupun lebar kolom label.
     */
    private static function ringkas(string $keluaran, string $label): int
    {
        self::assertSame(
            1,
            preg_match('/^'.preg_quote($label, '/').'\s*:\s*(\d+)\s*$/m', $keluaran, $cocok),
            "baris ringkasan '{$label}' tidak ada di keluaran",
        );

        return (int) $cocok[1];
    }

    /** Jalankan perintah dan kembalikan [kode keluar, teks keluaran]. */
    private function jalankan(array $parameter): array
    {
        $kode = Artisan::call('acuan:impor-awal', $parameter);

        return [$kode, Artisan::output()];
    }

    /**
     * Dry-run HANYA membaca: nol INSERT/UPDATE/DELETE/DDL, nol `FOR UPDATE`,
     * nol baris berubah di tabel mana pun — dan angkanya sudah menyebut apa
     * yang akan ditulis jalan sungguhan.
     */
    public function test_dry_run_tidak_menulis_apa_pun_dan_menyebut_yang_akan_ditulis(): void
    {
        Organization::factory()->count(2)->create();
        $this->sesi(Organization::query()->orderBy('id')->first(), 'Micrometer', '2025-03-10');

        $sebelum = $this->cacahBaris();
        $sql = [];
        $transaksi = 0;
        DB::listen(static function ($q) use (&$sql): void {
            $sql[] = $q->sql;
        });
        Event::listen(TransactionBeginning::class, static function () use (&$transaksi): void {
            $transaksi++;
        });

        [$kode, $keluaran] = $this->jalankan(['--dry-run' => true]);

        $this->assertSame(0, $kode, $keluaran);
        $this->assertSame($sebelum, $this->cacahBaris(), 'dry-run tidak boleh mengubah satu baris pun');
        // `lockForUpdate` hanya berarti di dalam transaksi (dan SQLite membuang
        // klausanya), jadi "nol transaksi" yang menjaganya.
        $this->assertSame(0, $transaksi, 'dry-run tidak boleh membuka transaksi/kunci');
        $sqlDryRun = $sql;

        $menulis = array_values(array_filter($sqlDryRun, static fn (string $s): bool => (bool) preg_match(
            '/^\s*(insert|update|delete|replace|create|alter|drop|truncate|pragma\s+\w+\s*=)|for\s+update/i',
            $s,
        )));
        $this->assertSame([], $menulis, 'dry-run mengirim pernyataan tulis/kunci: '.implode(' | ', $menulis));

        $paket = count(SkemaAcuanProfilTest::BERSKEMA);
        $this->assertStringContainsString('MODE DRY-RUN', $keluaran);
        $this->assertStringContainsString('database                   : '.DB::connection()->getDriverName().' / '.DB::connection()->getDatabaseName(), $keluaran);
        $this->assertStringContainsString('[DRY-RUN] AKAN dibuat: paket baru + versi 1', $keluaran);
        $this->assertStringNotContainsString('versi 1 dibuat (aktif', $keluaran);
        $this->assertSame(2, self::ringkas($keluaran, 'organisasi diperiksa'));
        $this->assertSame($paket, self::ringkas($keluaran, 'paket per organisasi'));
        $this->assertSame(2 * $paket, self::ringkas($keluaran, 'kombinasi diperiksa'));
        $this->assertSame(2 * $paket, self::ringkas($keluaran, 'paket baru'));
        $this->assertSame(2 * $paket, self::ringkas($keluaran, 'versi 1 baru'));
        $this->assertSame(0, self::ringkas($keluaran, 'galat (tidak ditimpa)'));
        $this->assertStringContainsString(
            'baris AKAN ditulis: paket_acuan '.(2 * $paket).', paket_acuan_versi '.(2 * $paket).', audit_logs '.(4 * $paket),
            $keluaran,
        );

        // Kontrol detektor: jalan sungguhan MEMANG membuka transaksi per paket
        // dan mengirim INSERT — kalau tidak, dua asersi nol di atas tak berarti.
        $this->jalankan(['profil' => 'micrometer', '--organisasi' => Organization::query()->min('id')]);
        $this->assertGreaterThan(0, $transaksi, 'detektor transaksi tidak bekerja');
        $this->assertNotSame([], array_filter($sql, static fn (string $s): bool => (bool) preg_match('/^\s*insert/i', $s)), 'detektor INSERT tidak bekerja');
    }

    /**
     * Angka dry-run = yang benar-benar tertulis jalan sungguhan: tiga tabel,
     * dua `audit_logs` per paket baru, dan tidak ada tabel lain yang disentuh.
     */
    public function test_angka_dry_run_sama_dengan_baris_yang_tertulis_jalan_sungguhan(): void
    {
        Organization::factory()->count(2)->create();
        $paket = count(SkemaAcuanProfilTest::BERSKEMA);
        $sebelum = $this->cacahBaris();

        [, $kering] = $this->jalankan(['--dry-run' => true]);
        $this->assertSame($sebelum, $this->cacahBaris());

        [$kode, $nyata] = $this->jalankan([]);
        $this->assertSame(0, $kode, $nyata);

        $sesudah = $this->cacahBaris();
        $this->assertSame(2 * $paket, $sesudah['paket'] - $sebelum['paket']);
        $this->assertSame(2 * $paket, $sesudah['versi'] - $sebelum['versi']);
        $this->assertSame(4 * $paket, $sesudah['audit'] - $sebelum['audit']);
        $this->assertSame(4 * $paket, $sesudah['audit_paket'] - $sebelum['audit_paket'], 'semua audit baru milik paket_acuan/paket_acuan_versi');

        foreach (['paket_acuan_suntingan', 'paket_acuan_log', 'simulasi_acuan', 'uncertainty_calculations'] as $tabel) {
            $this->assertSame(0, DB::table($tabel)->count(), "{$tabel} tidak boleh disentuh impor awal");
        }

        // Kalimat ringkasan dua jalan identik kecuali kata kerjanya.
        $this->assertStringContainsString(
            'baris AKAN ditulis: paket_acuan '.(2 * $paket).', paket_acuan_versi '.(2 * $paket).', audit_logs '.(4 * $paket),
            $kering,
        );
        $this->assertStringContainsString(
            'baris ditulis: paket_acuan '.(2 * $paket).', paket_acuan_versi '.(2 * $paket).', audit_logs '.(4 * $paket),
            $nyata,
        );

        // Sesudah impor, dry-run melaporkan "tidak ada yang akan ditulis".
        [$kode2, $kering2] = $this->jalankan(['--dry-run' => true]);
        $this->assertSame(0, $kode2, $kering2);
        $this->assertSame(0, self::ringkas($kering2, 'paket baru'));
        $this->assertSame(0, self::ringkas($kering2, 'versi 1 baru'));
        $this->assertSame(2 * $paket, self::ringkas($kering2, 'versi 1 sudah ada, sama'));
        $this->assertStringContainsString('baris AKAN ditulis: paket_acuan 0, paket_acuan_versi 0, audit_logs 0', $kering2);
        $this->assertSame($sesudah, $this->cacahBaris());
    }

    public function test_dry_run_paket_yang_sudah_ada_tanpa_versi_data_hanya_menghitung_versi(): void
    {
        $organisasi = Organization::factory()->create();
        PaketAcuan::create([
            'organization_id' => $organisasi->id,
            'kode_profil' => 'micrometer',
            'nama' => 'Micrometer',
            'skema_versi' => 1,
        ]);
        $sebelum = $this->cacahBaris();

        [$kode, $keluaran] = $this->jalankan(['profil' => 'micrometer', '--dry-run' => true]);

        $this->assertSame(0, $kode, $keluaran);
        $this->assertStringContainsString('paket sudah ada, versi 1', $keluaran);
        $this->assertSame(0, self::ringkas($keluaran, 'paket baru'));
        $this->assertSame(1, self::ringkas($keluaran, 'versi 1 baru'));
        $this->assertStringContainsString('baris AKAN ditulis: paket_acuan 0, paket_acuan_versi 1, audit_logs 1', $keluaran);
        $this->assertSame($sebelum, $this->cacahBaris());

        // Jalan sungguhan menulis persis itu: paket yang ada dipakai, tidak diduplikasi.
        [$kode, $nyata] = $this->jalankan(['profil' => 'micrometer']);
        $this->assertSame(0, $kode, $nyata);
        $this->assertStringContainsString('baris ditulis: paket_acuan 0, paket_acuan_versi 1, audit_logs 1', $nyata);

        $sesudah = $this->cacahBaris();
        $this->assertSame(0, $sesudah['paket'] - $sebelum['paket']);
        $this->assertSame(1, $sesudah['versi'] - $sebelum['versi']);
        $this->assertSame(1, $sesudah['audit'] - $sebelum['audit']);
    }

    /**
     * Cabang galat dry-run sama dengan jalan sungguhan: versi 1 yang beda,
     * hash tersimpan yang rusak, dan paket soft delete semuanya exit 1, dan
     * tidak satu pun diubah.
     */
    public function test_dry_run_melaporkan_galat_yang_sama_dan_tidak_menyentuh_apa_pun(): void
    {
        Organization::factory()->create();
        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer'])->assertExitCode(0);

        $isi = PaketAcuanVersi::query()->sole()->isiTerurai();
        $isi['balok_ukur']['2.5'] = 2.50015;
        $isiLain = JsonKanonik::serialisasi($isi);
        $shaLain = JsonKanonik::sha256($isiLain);
        DB::table('paket_acuan_versi')->update(['isi' => $isiLain, 'isi_sha256' => $shaLain]);
        $sebelum = $this->cacahBaris();

        [$kode, $keluaran] = $this->jalankan(['profil' => 'micrometer', '--dry-run' => true]);

        $this->assertSame(1, $kode);
        $this->assertStringContainsString('BEDA', $keluaran);
        $this->assertStringContainsString($shaLain, $keluaran);
        $this->assertStringContainsString(JsonKanonik::sha256(self::kanonik()), $keluaran);
        $this->assertSame(1, self::ringkas($keluaran, 'galat (tidak ditimpa)'));
        $this->assertSame($sebelum, $this->cacahBaris());
        $this->assertSame($isiLain, PaketAcuanVersi::query()->sole()->isi);

        // Hash tersimpan tidak cocok dengan isinya.
        DB::table('paket_acuan_versi')->update(['isi_sha256' => str_repeat('0', 64)]);
        [$kode, $keluaran] = $this->jalankan(['profil' => 'micrometer', '--dry-run' => true]);
        $this->assertSame(1, $kode);
        $this->assertStringContainsString('tidak cocok dengan isi_sha256', $keluaran);

        // Paket soft delete.
        PaketAcuan::query()->sole()->delete();
        $sebelum = $this->cacahBaris();
        [$kode, $keluaran] = $this->jalankan(['profil' => 'micrometer', '--dry-run' => true]);
        $this->assertSame(1, $kode);
        $this->assertStringContainsString('soft delete', $keluaran);
        $this->assertSame($sebelum, $this->cacahBaris());
        $this->assertSame(1, PaketAcuan::withTrashed()->count());
    }

    /** Jalan sungguhan mencetak ringkasan yang bisa dibaca di deploy log. */
    public function test_jalan_sungguhan_mencetak_ringkasan_dan_jalan_kedua_menghitung_sama(): void
    {
        Organization::factory()->create();

        [$kode, $pertama] = $this->jalankan(['profil' => 'micrometer']);
        $this->assertSame(0, $kode, $pertama);
        $this->assertStringContainsString('RINGKASAN', $pertama);
        $this->assertStringNotContainsString('DRY-RUN', $pertama);
        $this->assertSame(1, self::ringkas($pertama, 'paket baru'));
        $this->assertStringContainsString('baris ditulis: paket_acuan 1, paket_acuan_versi 1, audit_logs 2', $pertama);

        [$kode, $kedua] = $this->jalankan(['profil' => 'micrometer']);
        $this->assertSame(0, $kode, $kedua);
        $this->assertSame(0, self::ringkas($kedua, 'paket baru'));
        $this->assertSame(1, self::ringkas($kedua, 'versi 1 sudah ada, sama'));
        $this->assertStringContainsString('baris ditulis: paket_acuan 0, paket_acuan_versi 0, audit_logs 0', $kedua);
    }
}
