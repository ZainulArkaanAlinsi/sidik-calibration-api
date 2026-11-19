<?php

namespace Tests\Feature;

use App\Support\PenyaringSentry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Env;
use Illuminate\Validation\ValidationException;
use Sentry\ClientBuilder;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Request;
use Sentry\HttpClient\Response;
use Sentry\Laravel\Integration;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Konfigurasi Sentry API (paket OPS-KODE butir 2): nonaktif tanpa DSN, dan
 * pengaturan privasinya terkunci.
 *
 * ## Yang dijaga
 *
 *  (a) Tanpa DSN: tidak ada panggilan jaringan dan tidak ada galat. Itu
 *      keadaan bawaan produksi sampai pemilik memasang DSN, dan keadaan SEMUA
 *      tes (config/sentry.php memaksa DSN `null` di lingkungan `testing`).
 *  (b) Angka-angka privasi di config tidak bergeser diam-diam: satu perubahan
 *      di sana membuka kebocoran tanpa error apa pun.
 *  (c) Config bisa di-`config:cache` (entrypoint menjalankannya): tidak ada
 *      Closure/objek di dalamnya.
 *
 * Pembuktian muatan yang berangkat ada di `SentryPrivasiTest`.
 */
class SentryKonfigurasiTest extends TestCase
{
    /** Klien HTTP pengintai: kalau SDK menyentuh jaringan, dia mencatatnya. */
    private object $pengintai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pengintai = new class implements HttpClientInterface
        {
            public int $panggilan = 0;

            public function sendRequest(Request $request, Options $options): Response
            {
                $this->panggilan++;

                return new Response(200, [], '');
            }
        };
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub(new Hub);

        parent::tearDown();
    }

    /** Bangun ulang hub memakai klien HTTP pengintai. */
    private function hubDenganPengintai(): HubInterface
    {
        $this->app->extend(ClientBuilder::class, fn (ClientBuilder $b) => $b->setHttpClient($this->pengintai));
        $this->app->forgetInstance(HubInterface::class);

        return $this->app->make(HubInterface::class);
    }

    // ------------------------------------------------------------ (a) DSN

    public function test_provider_terpasang_dan_aplikasi_boot_tanpa_galat(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(SentryServiceProvider::class));
        $this->assertInstanceOf(HubInterface::class, $this->app->make(HubInterface::class));
    }

    public function test_tanpa_dsn_klien_tidak_punya_dsn_dan_tidak_menyentuh_jaringan(): void
    {
        $this->assertNull(config('sentry.dsn'));

        $hub = $this->hubDenganPengintai();

        $this->assertNull($hub->getClient()->getOptions()->getDsn());

        // Galat sungguhan lewat jalur yang dipakai aplikasi (bootstrap/app.php).
        Integration::captureUnhandledException(new \RuntimeException('meledak'));

        $this->assertSame(0, $this->pengintai->panggilan, 'SDK menyentuh jaringan tanpa DSN.');
    }

    /** `report()` Laravel melewati `Integration::handles` dan tidak melempar. */
    public function test_report_tanpa_dsn_tidak_melempar(): void
    {
        $this->hubDenganPengintai();

        report(new \RuntimeException('dilaporkan'));

        $this->assertSame(0, $this->pengintai->panggilan);
    }

    public function test_dsn_string_kosong_juga_nonaktif(): void
    {
        config()->set('sentry.dsn', '');
        $hub = $this->hubDenganPengintai();

        $this->assertNull($hub->getClient()->getOptions()->getDsn());

        Integration::captureUnhandledException(new \RuntimeException('x'));

        $this->assertSame(0, $this->pengintai->panggilan);
    }

    /**
     * Lingkungan `testing` memaksa DSN `null` walau `.env` pengembang berisi
     * DSN sungguhan; lingkungan lain membacanya dari `SENTRY_LARAVEL_DSN`.
     */
    public function test_dsn_dibaca_dari_env_di_luar_testing_dan_dipaksa_null_di_testing(): void
    {
        $dsn = 'https://publickey@o0.ingest.sentry.io/0';

        $this->assertNull($this->bacaConfigSubproses(['APP_ENV' => 'testing', 'SENTRY_LARAVEL_DSN' => $dsn])['dsn']);
        $this->assertSame($dsn, $this->bacaConfigSubproses(['APP_ENV' => 'production', 'SENTRY_LARAVEL_DSN' => $dsn])['dsn']);
        $this->assertNull($this->bacaConfigSubproses(['APP_ENV' => 'production', 'SENTRY_LARAVEL_DSN' => null])['dsn']);
        $this->assertNull($this->bacaConfigSubproses(['APP_ENV' => 'production', 'SENTRY_LARAVEL_DSN' => ''])['dsn'] ?: null);
    }

    /**
     * Hanya SATU kunci env Sentry yang dibaca. Kunci milik paket
     * (SENTRY_SEND_DEFAULT_PII, SENTRY_TRACES_SAMPLE_RATE, ...) tidak boleh
     * bisa menggeser pengaturan privasi dari dashboard.
     */
    public function test_env_milik_paket_tidak_menggeser_pengaturan_privasi(): void
    {
        $config = $this->bacaConfigSubproses([
            'APP_ENV' => 'production',
            'SENTRY_SEND_DEFAULT_PII' => 'true',
            'SENTRY_TRACES_SAMPLE_RATE' => '1.0',
            'SENTRY_PROFILES_SAMPLE_RATE' => '1.0',
            'SENTRY_ENABLE_LOGS' => 'true',
            'SENTRY_BREADCRUMBS_LOGS_ENABLED' => 'true',
            'SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED' => 'true',
            'SENTRY_DSN' => 'https://lain@o0.ingest.sentry.io/1',
        ]);

        $this->assertFalse($config['send_default_pii']);
        $this->assertSame(0.0, $config['traces_sample_rate']);
        $this->assertSame(0.0, $config['profiles_sample_rate']);
        $this->assertFalse($config['enable_logs']);
        $this->assertFalse($config['breadcrumbs']['logs']);
        $this->assertFalse($config['breadcrumbs']['sql_bindings']);
        $this->assertNull($config['dsn']);
    }

    // --------------------------------------------------------- (b) privasi

    public function test_pengaturan_privasi_terkunci(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertSame('none', config('sentry.max_request_body_size'));
        $this->assertFalse(config('sentry.enable_tracing'));
        $this->assertSame(0.0, config('sentry.traces_sample_rate'));
        $this->assertSame(0.0, config('sentry.profiles_sample_rate'));
        $this->assertFalse(config('sentry.enable_logs'));
        $this->assertFalse(config('sentry.enable_metrics'));
        $this->assertFalse(config('sentry.attach_stacktrace'));
        $this->assertNull(config('sentry.context_lines'), 'Kode sumber tidak boleh ikut terkirim.');
        $this->assertFalse(config('sentry.send_client_reports'));
        $this->assertSame('sidik-api', config('sentry.server_name'));
    }

    public function test_remah_hanya_sql_dan_tanpa_binding(): void
    {
        $remah = config('sentry.breadcrumbs');

        $this->assertFalse($remah['sql_bindings']);
        $this->assertTrue($remah['sql_queries']);

        foreach (['logs', 'cache', 'livewire', 'queue_info', 'command_info', 'http_client_requests', 'notifications'] as $kunci) {
            $this->assertFalse($remah[$kunci], "breadcrumbs.{$kunci} harus mati");
        }
    }

    public function test_semua_cabang_pelacakan_mati(): void
    {
        foreach (config('sentry.tracing') as $kunci => $nilai) {
            if (is_bool($nilai)) {
                $this->assertFalse($nilai, "tracing.{$kunci} harus mati");
            }
        }

        $this->assertFalse(config('sentry.crons.monitor_scheduled_tasks'));
    }

    public function test_penyaring_terpasang_dan_bisa_dipanggil(): void
    {
        $this->assertSame([PenyaringSentry::class, 'sebelumKirim'], config('sentry.before_send'));
        $this->assertSame([PenyaringSentry::class, 'sebelumBreadcrumb'], config('sentry.before_breadcrumb'));
        $this->assertTrue(is_callable(config('sentry.before_send')));
        $this->assertTrue(is_callable(config('sentry.before_breadcrumb')));
    }

    /** Opsi yang benar-benar sampai ke SDK sama dengan yang ditulis di config. */
    public function test_opsi_klien_mengikuti_config(): void
    {
        config()->set('sentry.dsn', 'https://publickey@o0.ingest.sentry.io/0');
        $this->app->forgetInstance(HubInterface::class);

        $opsi = $this->app->make(HubInterface::class)->getClient()->getOptions();

        $this->assertFalse($opsi->shouldSendDefaultPii());
        $this->assertSame('none', $opsi->getMaxRequestBodySize());
        $this->assertFalse($opsi->isTracingEnabled());
        $this->assertFalse($opsi->getEnableLogs());
        $this->assertSame('sidik-api', $opsi->getServerName());
        $this->assertSame(config('sentry.environment'), $opsi->getEnvironment());
        $this->assertSame([PenyaringSentry::class, 'sebelumKirim'], $opsi->getBeforeSendCallback());
        $this->assertSame([PenyaringSentry::class, 'sebelumBreadcrumb'], $opsi->getBeforeBreadcrumbCallback());
    }

    public function test_galat_yang_bukan_gangguan_ditutup_di_ignore_exceptions(): void
    {
        $this->assertEqualsCanonicalizing([
            ValidationException::class,
            AuthenticationException::class,
            AuthorizationException::class,
            ModelNotFoundException::class,
            NotFoundHttpException::class,
            ThrottleRequestsException::class,
        ], config('sentry.ignore_exceptions'));
    }

    // ------------------------------------------------------------- rilis

    /**
     * Rilis = `deploy.versi`: dua berkas config membaca env yang sama dengan
     * urutan yang sama. Diadu di tiga keadaan env, bukan satu.
     */
    public function test_rilis_sama_dengan_versi_deploy(): void
    {
        foreach ([
            ['RENDER_GIT_COMMIT' => 'render99', 'APP_COMMIT' => 'appcommit11'],
            ['RENDER_GIT_COMMIT' => null, 'APP_COMMIT' => 'abc1234def'],
            ['RENDER_GIT_COMMIT' => null, 'APP_COMMIT' => null],
        ] as $env) {
            $sentry = $this->bacaConfigDenganEnv($env)['release'];
            $deploy = $this->bacaConfigDenganEnv($env, 'deploy')['versi'];

            $this->assertSame($deploy, $sentry, json_encode($env));
        }
    }

    public function test_lingkungan_mengikuti_app_env(): void
    {
        $this->assertSame('production', $this->bacaConfigSubproses(['APP_ENV' => 'production'])['environment']);
        $this->assertSame('staging', $this->bacaConfigSubproses(['APP_ENV' => 'staging'])['environment']);
    }

    // ------------------------------------------------------ (c) config:cache

    /**
     * `docker/entrypoint.sh` menjalankan `config:cache`, yang menulis config
     * lewat `var_export`: Closure atau objek di dalamnya membuat boot gagal.
     */
    public function test_config_aman_untuk_config_cache(): void
    {
        $diekspor = var_export(config('sentry'), true);

        $this->assertStringNotContainsString('Closure', $diekspor);
        $this->assertStringNotContainsString('__set_state', $diekspor);

        $config = config('sentry');

        array_walk_recursive(
            $config,
            fn ($nilai) => $this->assertFalse(is_object($nilai), 'Objek di config/sentry.php tidak bisa di-cache.'),
        );
    }

    /**
     * Baca config di PROSES PHP TERPISAH dengan env yang diberikan.
     *
     * Dipakai untuk kunci yang sudah didefinisikan dari luar (`APP_ENV` dari
     * phpunit.xml): repository env milik `env()` tidak mengizinkannya ditimpa
     * di dalam proses ini, jadi `bacaConfigDenganEnv()` diam-diam mengabaikan
     * penimpaan dan tesnya menguji keadaan yang salah.
     *
     * @param  array<string, string|null>  $env  `null` = variabel dihapus
     * @return array<string, mixed>
     */
    private function bacaConfigSubproses(array $env, string $berkas = 'sentry'): array
    {
        $lingkungan = array_merge(getenv() ?: [], $_ENV, $_SERVER);

        // Hanya string: `$_SERVER` memuat larik (argv) yang tidak boleh diteruskan.
        $lingkungan = array_filter($lingkungan, 'is_string');

        // Semua kunci SENTRY_* dan APP_* milik shell/phpunit dibuang dulu supaya
        // yang terbaca hanya yang diberikan di sini.
        foreach (array_keys($lingkungan) as $kunci) {
            if (str_starts_with($kunci, 'SENTRY_') || str_starts_with($kunci, 'APP_')) {
                unset($lingkungan[$kunci]);
            }
        }

        foreach ($env as $kunci => $nilai) {
            if ($nilai !== null) {
                $lingkungan[$kunci] = $nilai;
            }
        }

        $proses = proc_open(
            [PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo json_encode(require "config/'.$berkas.'.php", JSON_PRESERVE_ZERO_FRACTION);'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipa,
            base_path(),
            $lingkungan,
        );

        $this->assertIsResource($proses);

        $keluar = (string) stream_get_contents($pipa[1]);
        $galat = (string) stream_get_contents($pipa[2]);
        proc_close($proses);

        $hasil = json_decode($keluar, true);

        $this->assertIsArray($hasil, "Config tidak terbaca di subproses: {$galat}");

        return $hasil;
    }

    /**
     * Muat ulang berkas config dengan env yang disetel di sini — lewat
     * repository yang sama dengan yang dibaca `env()`, bukan `config()->set()`
     * (yang melompati pembacaan env, bagian yang justru diuji).
     *
     * @param  array<string, string|null>  $env
     * @return array<string, mixed>
     */
    private function bacaConfigDenganEnv(array $env, string $berkas = 'sentry'): array
    {
        $repo = Env::getRepository();
        $asal = [];

        foreach ($env as $kunci => $nilai) {
            $asal[$kunci] = $repo->get($kunci);
            $nilai === null ? $repo->clear($kunci) : $repo->set($kunci, $nilai);
        }

        try {
            return require base_path("config/{$berkas}.php");
        } finally {
            foreach ($asal as $kunci => $nilai) {
                $nilai === null ? $repo->clear($kunci) : $repo->set($kunci, $nilai);
            }
        }
    }
}
