<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Muatan Sentry yang BENAR-BENAR sampai ke transport (paket OPS-KODE butir 2).
 *
 * Tes lain memeriksa config dan penyaring terpisah; berkas ini memeriksa
 * jumlahnya: satu permintaan sungguhan melewati kernel Laravel, melempar galat
 * 500, dan yang tiba di transport PALSU dibaca sebagai teks — persis yang akan
 * dilihat server Sentry. Tidak ada satu pun pengiriman jaringan (transport
 * palsu mencatat, tidak mengirim).
 *
 * Datanya sengaja diisi di SEMUA tempat yang diketahui bisa membocorkan: badan
 * permintaan, header otorisasi, cookie, parameter query, pengguna yang masuk,
 * `Context` Laravel, `exception->context()`, pesan galat, dan SQL ber-nilai.
 */
class SentryPrivasiTest extends TestCase
{
    use RefreshDatabase;

    private const SURAT = 'budi.santoso@contoh-pelanggan.co.id';

    private const NAMA_PT = 'PT Sumber Rahasia Makmur';

    private const TOKEN = '41|abcdefghijklmnopqrstuvwxyz0123456789ABCD';

    private const QR = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8';

    private const SANDI = 'sandi-super-rahasia-123';

    private object $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new class implements TransportInterface
        {
            /** @var list<Event> */
            public array $peristiwa = [];

            public function send(Event $event): Result
            {
                $this->peristiwa[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub(new Hub);

        parent::tearDown();
    }

    /**
     * Klien BERDSN dengan transport palsu. DSN-nya sintetis (tidak menunjuk
     * proyek mana pun) dan hanya hidup di memori proses tes.
     */
    private function nyalakanSentryPalsu(): HubInterface
    {
        config()->set('sentry.dsn', 'https://publickey@o0.ingest.sentry.io/0');

        $this->app->extend(ClientBuilder::class, fn (ClientBuilder $b) => $b->setTransport($this->transport));
        $this->app->forgetInstance(HubInterface::class);

        return $this->app->make(HubInterface::class);
    }

    /** Muatan seperti yang diterima server Sentry, sebagai satu teks. */
    private function muatan(HubInterface $hub, Event $event): string
    {
        return (new PayloadSerializer($hub->getClient()->getOptions()))->serialize($event);
    }

    private function semuaMuatan(HubInterface $hub): string
    {
        return implode("\n", array_map(fn (Event $e) => $this->muatan($hub, $e), $this->transport->peristiwa));
    }

    // -------------------------------------------------------- inti privasi

    public function test_galat_500_berangkat_tanpa_data_sensitif(): void
    {
        $hub = $this->nyalakanSentryPalsu();

        Organization::factory()->create();
        $pengguna = User::factory()->create([
            'name' => 'Nama Teknisi Unik',
            'email' => 'teknisi.unik@lab-sidik.example',
        ]);

        Route::post('api/_uji-sentry/meledak', function (Request $request): never {
            Context::add('klien_lab', self::NAMA_PT);

            throw new class('Gagal kirim ke '.self::SURAT.' dengan Bearer '.self::TOKEN.' untuk CAL/2026/09/0011 qr '.self::QR) extends \RuntimeException
            {
                public function context(): array
                {
                    return ['pelanggan' => 'PT Sumber Rahasia Makmur', 'sandi' => 'sandi-super-rahasia-123'];
                }
            };
        });

        $this->actingAs($pengguna, 'sanctum')
            ->withHeaders([
                'Authorization' => 'Bearer '.self::TOKEN,
                'Cookie' => 'sesi_rahasia='.self::SANDI,
                'X-Klien' => 'lab-'.self::NAMA_PT,
            ])
            ->postJson('api/_uji-sentry/meledak?token='.self::TOKEN.'&qr='.self::QR, [
                'nama_pelanggan' => self::NAMA_PT,
                'email' => self::SURAT,
                'password' => self::SANDI,
                'pembacaan' => [['titik' => 1.2345, 'nilai' => 9.8765]],
            ])
            ->assertStatus(500);

        $this->assertCount(1, $this->transport->peristiwa, 'Galat 500 harus dilaporkan tepat sekali.');

        $muatan = $this->semuaMuatan($hub);

        foreach ([
            self::SURAT, self::NAMA_PT, self::TOKEN, self::QR, self::SANDI,
            'Nama Teknisi Unik', 'teknisi.unik@lab-sidik.example', 'sesi_rahasia',
            'CAL/2026/09/0011', '9.8765', '1.2345', 'Authorization', 'lab-PT',
        ] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $muatan, "Bocor ke Sentry: {$terlarang}");
        }

        // Yang DIBUTUHKAN untuk memperbaiki galat tetap ada.
        $this->assertStringContainsString('RuntimeException', $muatan);
        $this->assertStringContainsString('"lineno"', $muatan, 'Berkas dan baris tumpukan harus tetap ada.');
        $this->assertStringContainsString('[email]', $muatan);
        $this->assertStringContainsString('[nomor-sertifikat]', $muatan);
    }

    /** Rilis dan lingkungan ikut di muatan, tanpa nama pengguna atau IP. */
    public function test_rilis_dan_lingkungan_ikut_serta(): void
    {
        config()->set('sentry.release', 'abc1234');
        $hub = $this->nyalakanSentryPalsu();

        $hub->captureException(new \RuntimeException('meledak'));

        $this->assertCount(1, $this->transport->peristiwa);
        $this->assertSame('abc1234', $this->transport->peristiwa[0]->getRelease());
        $this->assertSame('testing', $this->transport->peristiwa[0]->getEnvironment());
        $this->assertStringNotContainsString('"user"', $this->muatan($hub, $this->transport->peristiwa[0]));
    }

    public function test_pesan_query_exception_tidak_membawa_sql_ber_nilai(): void
    {
        $hub = $this->nyalakanSentryPalsu();

        $hub->captureException(new \RuntimeException(
            "SQLSTATE[23000]: Duplicate entry (Connection: mysql, Host: db.internal.example, Port: 3306, Database: sidik, SQL: insert into customers (nama) values ('".self::NAMA_PT."'))",
        ));

        $muatan = $this->semuaMuatan($hub);

        $this->assertStringContainsString('SQLSTATE[23000]', $muatan);
        $this->assertStringNotContainsString('Sumber Rahasia', $muatan);
        $this->assertStringNotContainsString('db.internal.example', $muatan);
    }

    /**
     * Rantai pengecualian NYATA seperti yang dibuat Laravel: `QueryException`
     * membungkus `PDOException` driver, dan pesan driver MySQL memantulkan NILAI
     * kolom (nama pelanggan di `customers (organization_id, nama)` unik, nomor
     * seri alat, bacaan lembar). Syarat pemilik: tanpa nama pelanggan dan isi
     * lembar. Dibaca dari muatan akhir (amplop yang diserialisasi, byte yang
     * sama dengan yang dikirim transport HTTP): nilai utama, nilai bersarang
     * (`previous`), dan pesan peristiwa.
     */
    public function test_nilai_kolom_dari_pesan_driver_db_tidak_ikut_terkirim(): void
    {
        $hub = $this->nyalakanSentryPalsu();

        $kasus = [
            ["Duplicate entry '1-PT Maju Jaya' for key 'customers.customers_org_nama_unique'", 'PT Maju Jaya', 23000, 'Integrity constraint violation: 1062'],
            ["Incorrect decimal value: '12,345' for column 'pembacaan' at row 1", '12,345', 'HY000', 'General error: 1366'],
            ["Duplicate entry 'SN-ZZ-98765' for key 'equipments_org_serial_unique'", 'SN-ZZ-98765', 23000, 'Integrity constraint violation: 1062'],
        ];

        foreach ($kasus as [$sisa, $nilai, $sqlstate, $kategori]) {
            $driver = new \PDOException("SQLSTATE[{$sqlstate}]: {$kategori} {$sisa}");
            $query = new QueryException('mysql', 'insert into customers (organization_id, nama) values (?, ?)', [1, $nilai], $driver);

            $hub->captureException(new \RuntimeException('Gagal menyimpan', 0, $query));
            $hub->captureMessage($query->getMessage());
        }

        $this->assertNotEmpty($this->transport->peristiwa);

        $muatan = $this->semuaMuatan($hub);

        foreach (['PT Maju Jaya', '12,345', 'SN-ZZ-98765', 'Duplicate entry', 'Incorrect decimal', 'customers_org_nama_unique', "'pembacaan'"] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $muatan, "Bocor ke Sentry: {$terlarang}");
        }

        // Yang dibutuhkan untuk mendiagnosis tetap ada: jenis galat dan kodenya.
        $this->assertStringContainsString('SQLSTATE[23000]: Integrity constraint violation: 1062', $muatan);
        $this->assertStringContainsString('SQLSTATE[HY000]: General error: 1366', $muatan);
        $this->assertStringContainsString('PDOException', $muatan);
        $this->assertStringContainsString('QueryException', $muatan);
    }

    // --------------------------------------------------- galat non-gangguan

    /**
     * Pembuat galat, bukan galatnya: penyedia data berjalan SEBELUM aplikasi
     * ada, dan sebagian galat Laravel butuh facade (penerjemah) di konstruktornya.
     *
     * @return array<string, array{0: \Closure(): \Throwable}>
     */
    public static function bukanGangguan(): array
    {
        return [
            'validasi' => [fn () => ValidationException::withMessages(['a' => 'salah'])],
            'belum masuk' => [fn () => new AuthenticationException],
            'tidak berhak' => [fn () => new AuthorizationException],
            'model tak ada' => [fn () => new ModelNotFoundException],
            'rute tak ada' => [fn () => new NotFoundHttpException],
            'kena batas laju' => [fn () => new ThrottleRequestsException],
            '403' => [fn () => new AccessDeniedHttpException],
            '409' => [fn () => new HttpException(409)],
            '419' => [fn () => new HttpException(419)],
        ];
    }

    #[DataProvider('bukanGangguan')]
    public function test_galat_bukan_gangguan_tidak_dilaporkan(\Closure $buat): void
    {
        $galat = $buat();
        $hub = $this->nyalakanSentryPalsu();

        $hub->captureException($galat);

        $this->assertCount(0, $this->transport->peristiwa, $galat::class);
    }

    /** Lewat jalur permintaan sungguhan, bukan hanya pemanggilan langsung. */
    public function test_respons_4xx_dari_permintaan_tidak_dilaporkan(): void
    {
        $this->nyalakanSentryPalsu();

        Route::get('api/_uji-sentry/403', fn () => abort(403));
        Route::get('api/_uji-sentry/404', fn () => abort(404));
        Route::get('api/_uji-sentry/validasi', function () {
            throw ValidationException::withMessages(['a' => 'salah']);
        });

        $this->getJson('api/_uji-sentry/403')->assertStatus(403);
        $this->getJson('api/_uji-sentry/404')->assertStatus(404);
        $this->getJson('api/_uji-sentry/validasi')->assertStatus(422);
        $this->getJson('api/_uji-sentry/tidak-ada')->assertStatus(404);

        $this->assertCount(0, $this->transport->peristiwa);
    }

    /** 5xx dari `abort()` adalah gangguan dan tidak ditelan penyaring. */
    public function test_galat_runtime_tetap_dilaporkan(): void
    {
        $hub = $this->nyalakanSentryPalsu();

        $hub->captureException(new \RuntimeException('meledak'));
        $hub->captureException(new HttpException(503));

        $this->assertCount(2, $this->transport->peristiwa);
    }

    // ------------------------------------------------ tanpa pengiriman nyata

    /**
     * Seluruh suite berjalan dengan DSN `null` (config/sentry.php memaksanya di
     * `testing`): tes mana pun yang melempar galat tidak punya jalan keluar.
     */
    public function test_bawaan_suite_tidak_punya_jalan_keluar(): void
    {
        $this->assertNull(config('sentry.dsn'));
        $this->assertNull($this->app->make(HubInterface::class)->getClient()->getOptions()->getDsn());
    }
}
