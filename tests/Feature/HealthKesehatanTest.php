<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Blok `kesehatan` di `GET /api/health` (paket OPS-KODE, butir 1).
 *
 * ## Kenapa ada
 *
 * Pemantau uptime luar memukul endpoint ini tiap 5 menit dan membaca DUA hal:
 * kode HTTP dan kata kunci `"status":"ok"`. Sebelum blok ini, `status` selalu
 * `ok` selama PHP masih menjawab — database mati, disk arsip tidak bisa ditulis,
 * antrean macet, dan sertifikat menggantung semuanya tetap "sehat" di mata
 * pemantau. Jadi yang dijaga di sini bukan angka-angkanya, melainkan KEPUTUSAN:
 * kapan `status` berubah, kapan kodenya 503, dan bahwa tidak ada rincian bocor.
 *
 * ## Aturan yang dikunci
 *
 *  - 503 HANYA untuk `db_gagal` dan `penyimpanan_gagal`. Antrean macet dan
 *    sertifikat menggantung adalah `degraded` dengan kode 200: server masih
 *    melayani teknisi, yang dibutuhkan pemantau cukup kata kuncinya.
 *  - `degraded` tidak boleh mengandung teks `"status":"ok"` di mana pun —
 *    termasuk di blok bersarang — karena pemantau kata kunci mencarinya
 *    sebagai substring dan satu kemunculan nyasar membuatnya melapor sehat.
 *  - Yang keluar hanya kata dari daftar tertutup dan angka. Tidak ada nama
 *    tabel, jalur berkas, atau pesan galat mentah.
 *  - Murah: jumlah query tetap, tidak tumbuh bersama isi tabel.
 *
 * Kasus "database mati" ada di `HealthKesehatanDbGagalTest` (butuh koneksi
 * yang benar-benar rusak, jadi tidak bisa memakai RefreshDatabase).
 */
class HealthKesehatanTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $berkasSementara = [];

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        // Produksi memakai antrean `database`; phpunit.xml memakai `sync`, yang
        // memang tidak punya antrean untuk diperiksa.
        config()->set('queue.default', 'database');
    }

    protected function tearDown(): void
    {
        foreach ($this->berkasSementara as $berkas) {
            @unlink($berkas);
        }

        parent::tearDown();
    }

    private function pekerjaan(array $timpa = []): void
    {
        DB::table('jobs')->insert(array_merge([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ], $timpa));
    }

    private function pekerjaanGagal(Carbon $kapan): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'x',
            'failed_at' => $kapan,
        ]);
    }

    private function menggantung(int $menit, ?CalibrationSession $sesi = null, array $timpa = []): Certificate
    {
        return Certificate::factory()->menungguGenerate()->create(array_merge([
            'calibration_session_id' => ($sesi ?? CalibrationSession::factory()->create())->id,
            'updated_at' => now()->subMinutes($menit),
        ], $timpa));
    }

    // ---------------------------------------------------------------- sehat

    public function test_sehat_status_ok_kode_200_dan_semua_blok_terisi(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('kesehatan.db', 'ok')
            ->assertJsonPath('kesehatan.penyimpanan', 'ok')
            ->assertJsonPath('kesehatan.antrean.menunggu', 0)
            ->assertJsonPath('kesehatan.antrean.gagal', 0)
            ->assertJsonPath('kesehatan.antrean.tertua_detik', 0)
            ->assertJsonPath('kesehatan.sertifikat_tertunda', 0)
            ->assertJsonPath('kesehatan.alasan', []);
    }

    /**
     * Pemantau kata kunci mencari `"status":"ok"` apa adanya.
     */
    public function test_sehat_memuat_kata_kunci_untuk_pemantau(): void
    {
        $this->assertStringContainsString('"status":"ok"', $this->getJson('/api/health')->getContent());
    }

    /**
     * Kontrak lama tidak boleh patah: tiap kunci yang sudah ada tetap ada
     * dengan bentuk yang sama; blok baru murni tambahan.
     */
    public function test_kunci_lama_tetap_ada(): void
    {
        config()->set('deploy.versi', 'abc1234');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonStructure([
                'status', 'app', 'time',
                'direktori_perusahaan' => ['disetel', 'driver', 'bisa_ditagih', 'lokal' => ['aktif', 'baris']],
                'deploy' => ['lingkungan', 'versi', 'arsip' => ['awet'], 'seed_saat_boot', 'bangun_ulang_saat_boot'],
                'pelanggan' => ['fitur', 'maintenance'],
                'realtime' => ['driver', 'nyala', 'paket_terpasang'],
                'push' => ['siap', 'proyek_terisi', 'kredensial_terbaca', 'kredensial_alasan'],
                'data_acuan' => ['sumber'],
                'append_only_db',
                'php_versi',
                'json_float_shortest',
                'kesehatan' => ['db', 'penyimpanan', 'antrean' => ['menunggu', 'gagal', 'tertua_detik'], 'sertifikat_tertunda', 'alasan'],
            ])
            ->assertJsonPath('deploy.versi', 'abc1234');
    }

    // -------------------------------------------------------------- antrean

    public function test_antrean_menghitung_menunggu_dan_umur_tertua(): void
    {
        $this->pekerjaan(['available_at' => now()->subMinutes(10)->getTimestamp()]);
        $this->pekerjaan(['available_at' => now()->subMinutes(2)->getTimestamp()]);

        $respons = $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('kesehatan.antrean.menunggu', 2);

        $umur = $respons->json('kesehatan.antrean.tertua_detik');
        $this->assertGreaterThanOrEqual(600, $umur);
        $this->assertLessThan(660, $umur);
    }

    /**
     * Job yang sedang dikerjakan (`reserved_at` terisi) dan yang baru jatuh
     * tempo di masa depan BUKAN "menunggu terlalu lama": yang pertama sedang
     * berjalan, yang kedua belum waktunya. Keduanya tetap dihitung di
     * `menunggu` (baris `jobs`), tapi tidak boleh menua-nuakan `tertua_detik`.
     */
    public function test_umur_tertua_mengabaikan_yang_dikerjakan_dan_yang_dijadwalkan(): void
    {
        $this->pekerjaan([
            'reserved_at' => now()->subHour()->getTimestamp(),
            'available_at' => now()->subHours(2)->getTimestamp(),
        ]);
        $this->pekerjaan(['available_at' => now()->addHours(3)->getTimestamp()]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('kesehatan.antrean.menunggu', 2)
            ->assertJsonPath('kesehatan.antrean.tertua_detik', 0);
    }

    public function test_antrean_macet_kalau_job_tertua_lebih_dari_30_menit(): void
    {
        $this->pekerjaan(['available_at' => now()->subMinutes(31)->getTimestamp()]);

        $respons = $this->getJson('/api/health');

        // 200, bukan 503: server masih melayani; ini urusan kata kunci.
        $respons->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('kesehatan.alasan', ['antrean_macet']);
        $this->assertStringNotContainsString('"status":"ok"', $respons->getContent());
    }

    public function test_antrean_tepat_di_bawah_ambang_masih_ok(): void
    {
        $this->pekerjaan(['available_at' => now()->subMinutes(29)->getTimestamp()]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('kesehatan.alasan', []);
    }

    public function test_gagal_menumpuk_sepuluh_atau_lebih_membuat_antrean_macet(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->pekerjaanGagal(now()->subHours(2));
        }

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('kesehatan.antrean.gagal', 10)
            ->assertJsonPath('kesehatan.alasan', ['antrean_macet']);
    }

    public function test_gagal_sembilan_masih_ok(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->pekerjaanGagal(now()->subHours(2));
        }

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('kesehatan.antrean.gagal', 9);
    }

    /**
     * `failed_jobs` tidak pernah dibersihkan otomatis dan Render Free tidak
     * punya shell untuk `queue:flush`. Tanpa jendela waktu, sepuluh kegagalan
     * lama (yang sudah diperbaiki) membuat status `degraded` SELAMANYA dan
     * pemantau berhenti dipercaya. Yang dihitung hanya yang baru.
     */
    public function test_gagal_lama_di_luar_jendela_tidak_dihitung(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->pekerjaanGagal(now()->subHours(25));
        }

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('kesehatan.antrean.gagal', 0);
    }

    /**
     * Kalau antreannya bukan `database` (lokal/CI memakai `sync`), tidak ada
     * yang bisa dihitung dan tidak ada yang boleh dituduh macet.
     */
    public function test_antrean_bukan_database_tidak_diperiksa(): void
    {
        config()->set('queue.default', 'sync');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('kesehatan.antrean.menunggu', null)
            ->assertJsonPath('kesehatan.antrean.gagal', null)
            ->assertJsonPath('kesehatan.antrean.tertua_detik', null);
    }

    /**
     * Antrean `database` yang tabelnya tidak bisa dibaca tidak memproses apa
     * pun: macet, dengan angka `null` (tidak diketahui) — bukan 500 dan bukan
     * "sehat karena tidak ada job yang kelihatan".
     */
    public function test_tabel_antrean_hilang_dianggap_macet(): void
    {
        // Menunjuk ke tabel yang tidak ada, BUKAN `Schema::drop('jobs')`: di
        // MySQL `DROP TABLE` meng-commit transaksi RefreshDatabase dan tabelnya
        // hilang untuk semua test sesudahnya di proses yang sama.
        config()->set('queue.connections.database.table', 'tabel_antrean_tidak_ada');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('kesehatan.alasan', ['antrean_macet'])
            ->assertJsonPath('kesehatan.antrean.menunggu', null)
            ->assertJsonPath('kesehatan.antrean.gagal', null)
            ->assertJsonPath('kesehatan.antrean.tertua_detik', null);
    }

    // ----------------------------------------------------------- sertifikat

    public function test_sertifikat_menggantung_lebih_dari_60_menit(): void
    {
        $this->menggantung(61);

        $respons = $this->getJson('/api/health');

        $respons->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('kesehatan.sertifikat_tertunda', 1)
            ->assertJsonPath('kesehatan.alasan', ['sertifikat_menggantung']);
        $this->assertStringNotContainsString('"status":"ok"', $respons->getContent());
    }

    public function test_sertifikat_yang_masih_segar_tidak_dihitung(): void
    {
        $this->menggantung(59);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('kesehatan.sertifikat_tertunda', 0);
    }

    /**
     * Yang sudah terbit, yang sudah punya PDF, dan yang sesinya sengaja tidak
     * disetujui (penyapu menolak mendorongnya) bukan "menggantung" — kalau
     * dihitung, status `degraded` menetap dan peringatannya berhenti berarti.
     * Definisinya SAMA dengan `sertifikat:sapu-tertunda`.
     */
    public function test_yang_bukan_menggantung_tidak_dihitung(): void
    {
        Certificate::factory()->create(['status' => Certificate::STATUS_TERBIT, 'updated_at' => now()->subDay()]);

        // Lewat atribut saat dibuat, BUKAN `update()` sesudahnya: `update()`
        // menyegarkan `updated_at` dan sertifikatnya jadi "baru" dengan
        // sendirinya — test lolos tanpa pernah menguji `pdf_path`.
        $this->menggantung(120, null, ['pdf_path' => 'certificates/sudah-ada.pdf']);

        $sesiDitarik = CalibrationSession::factory()->create(['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL]);
        $this->menggantung(120, $sesiDitarik);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('kesehatan.sertifikat_tertunda', 0);
    }

    // ---------------------------------------------------------- penyimpanan

    /**
     * Arsip tidak bisa ditulis -> 503 dan `status` bukan `ok`, supaya pemantau
     * kode status DAN kata kunci sama-sama menangkapnya.
     */
    public function test_penyimpanan_tidak_bisa_ditulis_503(): void
    {
        // Akar disk menunjuk ke "bawah sebuah berkas biasa": direktori tidak
        // mungkin dibuat, di Windows maupun Linux, tanpa perlu mengubah izin.
        $berkas = tempnam(sys_get_temp_dir(), 'kes');
        $this->berkasSementara[] = $berkas;
        config()->set('filesystems.disks.arsip.root', $berkas.DIRECTORY_SEPARATOR.'bawah');
        Storage::forgetDisk('arsip');

        $respons = $this->getJson('/api/health');

        $respons->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('kesehatan.penyimpanan', 'gagal')
            ->assertJsonPath('kesehatan.db', 'ok')
            ->assertJsonPath('kesehatan.alasan', ['penyimpanan_gagal']);
        $this->assertStringNotContainsString('"status":"ok"', $respons->getContent());
        $this->assertStringNotContainsString($berkas, $respons->getContent());
    }

    public function test_probe_penyimpanan_tidak_meninggalkan_berkas(): void
    {
        $akar = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kes-akar-'.bin2hex(random_bytes(5));
        mkdir($akar);
        config()->set('filesystems.disks.arsip.root', $akar);
        Storage::forgetDisk('arsip');

        try {
            $this->getJson('/api/health')->assertOk()->assertJsonPath('kesehatan.penyimpanan', 'ok');

            $sisa = array_values(array_diff(scandir($akar) ?: [], ['.', '..']));

            $this->assertSame([], $sisa, 'Probe meninggalkan berkas di disk arsip.');
        } finally {
            @rmdir($akar);
        }
    }

    /**
     * Arsip di object storage (s3/R2) TIDAK ditulisi: endpoint ini publik tanpa
     * auth, dan satu tulis per ketukan ke bucket berbayar adalah tuas kuota
     * (aturan lama blok ini: nol request ke penyedia). Dilaporkan jujur sebagai
     * `dilewati`, bukan `ok`, dan bukan kegagalan.
     */
    public function test_arsip_s3_tidak_ditulis_dan_dilaporkan_dilewati(): void
    {
        config()->set('filesystems.disks.arsip.driver', 's3');
        Storage::forgetDisk('arsip');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('kesehatan.penyimpanan', 'dilewati');
    }

    // -------------------------------------------------- kebocoran & ongkos

    public function test_respons_sehat_tidak_membocorkan_rincian(): void
    {
        $isi = $this->getJson('/api/health')->assertOk()->getContent();

        foreach ([
            'failed_jobs', 'certificates', 'calibration_sessions', 'jobs"',
            storage_path(), base_path(), sys_get_temp_dir(), 'SQLSTATE', 'Exception',
        ] as $rincian) {
            $this->assertStringNotContainsString($rincian, $isi, "Bocor: {$rincian}");
        }
    }

    /**
     * Dalam keadaan GAGAL pun yang keluar hanya kata dari daftar tertutup —
     * bukan pesan galat PHP/Flysystem yang memuat jalur berkas.
     */
    public function test_respons_gagal_tidak_membocorkan_rincian(): void
    {
        $berkas = tempnam(sys_get_temp_dir(), 'kes');
        $this->berkasSementara[] = $berkas;
        config()->set('filesystems.disks.arsip.root', $berkas.DIRECTORY_SEPARATOR.'bawah');
        Storage::forgetDisk('arsip');
        $this->pekerjaan(['available_at' => now()->subHours(2)->getTimestamp()]);

        $isi = $this->getJson('/api/health')->assertStatus(503)->getContent();

        foreach ([$berkas, storage_path(), base_path(), 'SQLSTATE', 'Exception', 'UnableTo'] as $rincian) {
            $this->assertStringNotContainsString($rincian, $isi, "Bocor: {$rincian}");
        }
    }

    /**
     * Jumlah query tidak tumbuh bersama isi tabel (tanpa N+1) dan semuanya
     * agregat: tidak ada baris `jobs`/`certificates` yang ditarik ke PHP.
     */
    public function test_ongkos_tetap_walau_tabel_terisi(): void
    {
        $hitung = function (): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/health')->assertOk();
            DB::disableQueryLog();

            return array_column(DB::getQueryLog(), 'query');
        };

        // Ketukan pertama membayar query pemanasan milik blok lain
        // (`append_only_db` dicache 5 menit); yang dibandingkan ketukan kedua
        // dengan tabel kosong lawan tabel terisi.
        $hitung();
        $kosong = $hitung();

        for ($i = 0; $i < 25; $i++) {
            $this->pekerjaan(['available_at' => now()->subSeconds(30)->getTimestamp()]);
            $this->pekerjaanGagal(now()->subHour());
        }
        $sesi = CalibrationSession::factory()->create();
        for ($i = 0; $i < 25; $i++) {
            $this->menggantung(10, $sesi);
        }

        $terisi = $hitung();

        $this->assertCount(count($kosong), $terisi, 'Jumlah query tumbuh bersama isi tabel.');

        $milikBlok = array_values(array_filter(
            $terisi,
            fn (string $q): bool => (bool) preg_match('/\b(jobs|failed_jobs|certificates)\b|^select 1/i', $q),
        ));

        // ping + jobs + failed_jobs + certificates
        $this->assertLessThanOrEqual(4, count($milikBlok), implode("\n", $milikBlok));

        foreach ($milikBlok as $q) {
            $this->assertMatchesRegularExpression('/^select (1$|.*(count|min)\()/i', $q, "Bukan agregat: {$q}");
        }
    }
}
