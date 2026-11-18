<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\TabelStandarTekanan as Tabel;
use App\Services\CalibrationValidator;
use App\Services\PenahananProfil;
use App\Support\TekananMentah as M;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pasangan `ProfilDitahanTest` yang memakai DATA SEEDER (sesi contoh dari master
 * lab), bukan sesi kosong: ketujuh jenis alat yang ditahan memang punya sesi
 * nyata di sini, dan Tekanan hanya bisa dibuktikan lewat hasil hitung tersimpan
 * (penahanannya bersyarat dan dibaca dari jejak hasil).
 *
 * Dipisah dari kelas induknya karena seeder mengasumsikan organisasi 1 belum
 * dibuat oleh `setUp()`.
 */
class ProfilDitahanDataSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $daftarAsli;

    protected function setUp(): void
    {
        parent::setUp();

        // Daftar NYATA dari berkas config — jangan diganti salinan.
        $this->daftarAsli = (require base_path('config/sertifikat.php'))['profil_ditahan'];
        config(['sertifikat.profil_ditahan' => $this->daftarAsli]);
    }

    public function test_data_seeder_ketujuh_jenis_alat_ditahan_dan_hitung_tetap_jalan(): void
    {
        Queue::fake();
        $this->seed(DatabaseSeeder::class);

        $registry = app(CalibrationProfileRegistry::class);
        $penahan = app(PenahananProfil::class);
        $perProfil = [];

        foreach (CalibrationSession::query()->with(['equipment', 'uncertaintyCalculations', 'certificate'])->get() as $sesi) {
            $kode = $registry->untukAlat($sesi->equipment)->kode();
            $varian = $kode === 'timbangan' ? ($sesi->spesifikasi_alat['varian_master'] ?? '?') : null;
            $perProfil[$kode.($varian !== null ? ":{$varian}" : '')][] = $penahan->periksa($sesi) !== null;
        }

        // Bath tidak punya sesi contoh di seeder (hanya Oven, Inkubator, dst.); ia dibuktikan
        // dengan sesi kosong di `ProfilDitahanTest`. Kalau suatu hari seeder menambahkannya,
        // sesi itu otomatis ikut diperiksa di bawah.
        $tujuh = ['timbangan:kg', 'proving_ring', 'picnometer', 'pressure_gauge', 'vacuum_gauge', 'differential_pressure'];

        // Tujuh jenis alat: Timbangan kg, Proving Ring, Bath, Picnometer, Tekanan x3. Tekanan
        // ditahan SESI YANG MEMICU cacat master (hasil tersimpan), jadi sesi contohnya cukup
        // satu yang ditahan; empat lainnya ditahan semua sesinya.
        foreach ($tujuh as $kunci) {
            $this->assertArrayHasKey($kunci, $perProfil, "Seeder tidak punya sesi {$kunci}.");
            $this->assertContains(true, $perProfil[$kunci], "Tidak ada sesi {$kunci} dari seeder yang ditahan.");
        }

        foreach (['timbangan:kg', 'proving_ring', 'bath', 'picnometer'] as $kunci) {
            $this->assertNotContains(false, $perProfil[$kunci] ?? [], "Ada sesi {$kunci} dari seeder yang TIDAK ditahan.");
        }

        // Gram dan substitusi, serta semua profil lain, tidak ditahan.
        $this->assertArrayHasKey('timbangan:gram', $perProfil);
        $this->assertArrayHasKey('timbangan:substitusi', $perProfil);

        foreach ($perProfil as $kunci => $hasil) {
            if ($kunci === 'bath' || in_array($kunci, $tujuh, true)) {
                continue;
            }

            $this->assertNotContains(true, $hasil, "{$kunci} ikut tertahan.");
        }

        // Approve tiap sesi yang ditahan: 422 `profil_ditahan`, status & sertifikat tak berubah.
        $diuji = [];
        $percobaan = 0;

        foreach (CalibrationSession::query()->with('equipment')->get() as $sesi) {
            if ($penahan->periksa($sesi) === null || $sesi->status !== CalibrationSession::STATUS_MENUNGGU_APPROVAL) {
                continue;
            }

            $kode = $registry->untukAlat($sesi->equipment)->kode();
            $diuji[$kode] = true;
            $percobaan++;

            $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)
                ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
                ->assertStatus(422)
                ->assertJsonPath('kode', 'profil_ditahan')
                ->assertJsonPath('profil', $kode);

            $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status, $sesi->nomor_sesi);
            $this->assertNull($sesi->fresh()->certificate()->first(), $sesi->nomor_sesi);
        }

        foreach (['timbangan', 'proving_ring', 'picnometer', 'pressure_gauge', 'vacuum_gauge', 'differential_pressure'] as $kode) {
            $this->assertArrayHasKey($kode, $diuji, "Tidak ada sesi contoh {$kode} berstatus menunggu_approval yang ditolak.");
        }

        // Tiap penolakan (ketujuh jenis alat, Tekanan juga) meninggalkan tepat satu jejak audit.
        $jejak = AuditLog::query()->where('action', AuditLog::ACTION_PENERBITAN_DITAHAN)->get();
        $this->assertCount($percobaan, $jejak);
        $this->assertEqualsCanonicalizing(array_keys($diuji), $jejak->pluck('new_data.profil')->unique()->values()->all());
        $this->assertTrue(Queue::pushed(GenerateCertificate::class)->isEmpty());

        // Hitung tetap jalan: lembar PERHITUNGAN, dan validator memuat penahanannya sebagai temuan.
        $proving = CalibrationSession::query()
            ->whereHas('equipment', fn ($q) => $q->where('nama_alat_kemampuan', $registry->untukKode('proving_ring')->namaAlatKemampuan()))
            ->firstOrFail();
        $admin = User::query()->where('organization_id', $proving->organization_id)->where('role', User::ROLE_ADMIN)->firstOrFail();

        $this->actingAs($admin, 'sanctum')->getJson("/api/calibrations/{$proving->id}/perhitungan")->assertOk();
        $validasi = $this->actingAs($admin, 'sanctum')->getJson("/api/calibrations/{$proving->id}/validasi")->assertOk();
        $validasi->assertJsonPath('data.boleh_terbit', false);
        $tahan = collect($validasi->json('data.temuan'))->firstWhere('konteks.penyimpangan', 'profil_ditahan');
        $this->assertSame(CalibrationValidator::MENUNGGU_KEPUTUSAN_TM, $tahan['kode']);
        $this->assertSame('TM-4', $tahan['konteks']['rujukan_tm']);
        $this->assertSame(CalibrationValidator::ERROR, $tahan['tingkat']);
    }

    public function test_tekanan_tetap_bersyarat_dan_lepasnya_lewat_log_bukan_config(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::query()
            ->whereHas('equipment', fn ($q) => $q->where('serial_number', 'DEMO-VG-07G-001'))
            ->firstOrFail();

        $respons = $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'profil_ditahan')
            ->assertJsonPath('profil', 'vacuum_gauge');

        // Butir asal: log metode (T-1 dan T-2 untuk DRUCK07G), bukan config.
        $this->assertEqualsCanonicalizing(['T-1', 'T-2'], array_column($respons->json('penahan'), 'penyimpangan'));
        $this->assertStringContainsString('P-1', $respons->json('rujukan_tm'));
        $this->assertStringContainsString('P-2', $respons->json('rujukan_tm'));
        $this->assertArrayNotHasKey('vacuum_gauge', $this->daftarAsli, 'Tekanan sengaja tidak ada di config: penahanannya bersyarat (SPMK tidak ditahan).');

        // Jejaknya sama dengan jenis alat lain.
        $jejak = AuditLog::query()->where('action', AuditLog::ACTION_PENERBITAN_DITAHAN)->sole();
        $this->assertSame('vacuum_gauge', $jejak->new_data['profil']);
        $this->assertSame(PenahananProfil::PINTU_APPROVE_API, $jejak->new_data['pintu']);
    }

    /**
     * Pratinjau dan simpan (hitung) TETAP jalan untuk jenis alat yang ditahan:
     * yang ditolak hanya penerbitan. Dibuktikan di Tekanan DRUCK13G vakum, yang
     * punya payload lembar lengkap, dan klien langsung diberi tahu di respons simpan.
     */
    public function test_pratinjau_dan_simpan_tetap_jalan_untuk_jenis_alat_yang_ditahan(): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = Equipment::where('serial_number', 'DEMO-VG-07G-001')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();

        $badan = [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-09-24',
            'suhu_awal' => 24.5, 'suhu_akhir' => 24.7,
            'kelembaban_awal' => 51, 'kelembaban_akhir' => 52,
            'measurements' => [
                ['titik_ukur' => '0', M::PERAN_UP => ['0', '0', '0'], M::PERAN_DOWN => ['0', '0', '0']],
                ['titik_ukur' => '100', M::PERAN_UP => ['99,8', '99,9', '99,8'], M::PERAN_DOWN => ['99,7', '99,7', '99,8']],
                ['titik_ukur' => '200', M::PERAN_UP => ['199,7', '199,6', '199,7'], M::PERAN_DOWN => ['199,6', '199,6', '199,7']],
            ],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'varian' => Tabel::DRUCK13G, 'satuan' => 'Psi', 'tampilan' => M::TAMPILAN_ANALOG,
                'rasio_jarum' => '1/5', 'resolusi' => '1', 'kapasitas' => '200',
            ]],
        ];

        $this->actingAs($teknisi)->postJson('/api/calibrations/preview', $badan)->assertOk();

        $simpan = $this->actingAs($teknisi)->postJson('/api/calibrations', $badan)->assertCreated();
        $simpan->assertJsonPath('data.penerbitan_ditahan.ditahan', true);
        $this->assertNotEmpty($simpan->json('data.penerbitan_ditahan.alasan'));
        $this->assertGreaterThan(0, CalibrationSession::findOrFail($simpan->json('data.id'))->uncertaintyCalculations()->count());
    }
}
