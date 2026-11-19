<?php

namespace Tests\Unit;

use App\Support\PenyaringSentry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Severity;
use Sentry\Stacktrace;
use Sentry\UserDataBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Penyaring privasi Sentry (paket OPS-KODE butir 2) — unit: peristiwa masuk,
 * peristiwa keluar.
 *
 * Dua pertanyaan yang diuji, dan hanya keduanya: apa yang SAMPAI ke transport
 * (tidak boleh data sensitif), dan apa yang DIBUANG sebelum sampai (galat 4xx).
 * Pembuktian lewat aplikasi sungguhan ada di `SentryPrivasiTest`.
 */
class PenyaringSentryTest extends TestCase
{
    private const SURAT = 'budi.santoso@contoh-pelanggan.co.id';

    private const NAMA_PT = 'PT Sumber Rahasia Makmur';

    private const SANCTUM = '12|abcdefghijklmnopqrstuvwxyz0123456789ABCD';

    private const QR = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8';

    /**
     * Peristiwa yang sengaja diisi SEMUA tempat data bisa mendarat.
     */
    private function peristiwaKotor(): Event
    {
        $event = Event::createEvent();
        $event->setLevel(Severity::error());
        $event->setTransaction('api/calibrations/{calibration}/approve');

        $pengguna = new UserDataBag;
        $pengguna->setId(77);
        $pengguna->setEmail(self::SURAT);
        $pengguna->setUsername(self::NAMA_PT);
        $pengguna->setIpAddress('203.0.113.9');
        $event->setUser($pengguna);

        $event->setRequest([
            'url' => 'https://api.contoh.id/api/verify/'.self::QR.'?token='.self::SANCTUM,
            'method' => 'POST',
            'query_string' => 'token='.self::SANCTUM,
            'headers' => ['Authorization' => 'Bearer '.self::SANCTUM, 'Cookie' => 'sesi=rahasia'],
            'cookies' => ['sesi' => 'rahasia'],
            'data' => ['nama_pelanggan' => self::NAMA_PT, 'pembacaan' => [1.23, 4.56]],
            'env' => ['REMOTE_ADDR' => '203.0.113.9'],
        ]);

        $event->setExtra([
            'exception_context' => ['pelanggan' => self::NAMA_PT],
            'lembar' => ['titik' => [1, 2, 3]],
        ]);
        $event->setContext('laravel', ['klien' => 'lab-'.self::NAMA_PT, 'sesi' => 991]);
        $event->setContext('trace', ['trace_id' => 'abc', 'span_id' => 'def']);

        $event->setTags(['runtime' => 'php 8.4', 'transaction' => 'x', 'pelanggan' => self::NAMA_PT]);
        $event->setAttachments([]);

        $event->setBreadcrumb([
            new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'Kirim ke '.self::SURAT),
            new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'cache', 'hit:qr:'.self::QR),
            new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'sql.query',
                "select * from customers where nama = '".self::NAMA_PT."' and id = ?",
                ['connectionName' => 'mysql', 'executionTimeMs' => 3.1, 'bindings' => [self::NAMA_PT]],
            ),
        ]);

        $frame = new Frame('Foo::bar', '/app/Foo.php', 10);
        $frame->setVars(['nama' => self::NAMA_PT]);
        $frame->setPreContext(['$a = "'.self::NAMA_PT.'";']);
        $frame->setContextLine('throw new X("'.self::SURAT.'");');
        $frame->setPostContext(['$b = "'.self::QR.'";']);
        $galat = new ExceptionDataBag(
            new \RuntimeException('x'),
            new Stacktrace([$frame]),
        );
        $galat->setValue(
            'Gagal kirim ke '.self::SURAT.' dengan Bearer '.self::SANCTUM.' untuk CAL/2026/09/0011-R2 qr '.self::QR.' dari 203.0.113.9',
        );
        $event->setExceptions([$galat]);

        return $event;
    }

    /** Muatan akhir sebagai satu string, untuk dicari kata terlarangnya. */
    private function muatan(Event $event): string
    {
        $bagian = [
            json_encode($event->getRequest()),
            json_encode($event->getExtra()),
            json_encode($event->getContexts()),
            json_encode($event->getTags()),
            json_encode($event->getUser()),
            (string) $event->getMessage(),
        ];

        foreach ($event->getExceptions() as $galat) {
            $bagian[] = $galat->getValue();
            foreach ($galat->getStacktrace()?->getFrames() ?? [] as $frame) {
                $bagian[] = json_encode([
                    $frame->getVars(), $frame->getPreContext(), $frame->getContextLine(), $frame->getPostContext(),
                    $frame->getFile(), $frame->getLine(), $frame->getFunctionName(),
                ]);
            }
        }

        foreach ($event->getBreadcrumbs() as $remah) {
            $bagian[] = json_encode([$remah->getCategory(), $remah->getMessage(), $remah->getMetadata()]);
        }

        return implode("\n", $bagian);
    }

    public function test_tidak_ada_data_sensitif_yang_tersisa(): void
    {
        $hasil = PenyaringSentry::sebelumKirim($this->peristiwaKotor());

        $this->assertNotNull($hasil);

        $muatan = $this->muatan($hasil);

        foreach ([self::SURAT, self::NAMA_PT, self::SANCTUM, self::QR, 'rahasia', '203.0.113.9', 'Authorization', 'CAL/2026'] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $muatan, "Bocor: {$terlarang}");
        }
    }

    /** Berkas, baris, dan fungsi tinggal (itu yang dibutuhkan); isi kodenya tidak. */
    public function test_frame_menyisakan_berkas_baris_dan_fungsi_saja(): void
    {
        $frame = PenyaringSentry::sebelumKirim($this->peristiwaKotor())
            ->getExceptions()[0]->getStacktrace()->getFrames()[0];

        $this->assertSame('/app/Foo.php', $frame->getAbsoluteFilePath() ?? $frame->getFile());
        $this->assertSame(10, $frame->getLine());
        $this->assertSame('Foo::bar', $frame->getFunctionName());
        $this->assertSame([], $frame->getVars());
        $this->assertSame([], $frame->getPreContext());
        $this->assertNull($frame->getContextLine());
        $this->assertSame([], $frame->getPostContext());
    }

    public function test_pengguna_dibuang(): void
    {
        $this->assertNull(PenyaringSentry::sebelumKirim($this->peristiwaKotor())->getUser());
    }

    /** Alamat, header, cookie, query, dan badan dibuang; metode tinggal. */
    public function test_permintaan_hanya_menyisakan_metode(): void
    {
        $hasil = PenyaringSentry::sebelumKirim($this->peristiwaKotor());

        $this->assertSame(['method' => 'POST'], $hasil->getRequest());
    }

    public function test_extra_dan_konteks_laravel_dibuang_tapi_konteks_teknis_tinggal(): void
    {
        $hasil = PenyaringSentry::sebelumKirim($this->peristiwaKotor());

        $this->assertSame([], $hasil->getExtra());
        $this->assertSame(['disamarkan' => 'ya'], $hasil->getContexts()['laravel']);
        $this->assertSame(['trace_id' => 'abc', 'span_id' => 'def'], $hasil->getContexts()['trace']);
    }

    public function test_hanya_tag_teknis_yang_tinggal_dan_transaksi_templat_tetap(): void
    {
        $hasil = PenyaringSentry::sebelumKirim($this->peristiwaKotor());

        $this->assertSame(['runtime' => 'php 8.4', 'transaction' => 'x'], $hasil->getTags());
        $this->assertSame('api/calibrations/{calibration}/approve', $hasil->getTransaction());
    }

    public function test_remah_selain_sql_dibuang_dan_sql_tanpa_binding_dan_literal(): void
    {
        $remah = PenyaringSentry::sebelumKirim($this->peristiwaKotor())->getBreadcrumbs();

        $this->assertCount(1, $remah);
        $this->assertSame('sql.query', $remah[0]->getCategory());
        $this->assertSame("select * from customers where nama = '?' and id = ?", $remah[0]->getMessage());
        $this->assertSame(['connectionName' => 'mysql', 'executionTimeMs' => 3.1], $remah[0]->getMetadata());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function polaPesan(): array
    {
        return [
            'surel' => ['kirim ke budi@contoh.id gagal', 'kirim ke [email] gagal'],
            'bearer' => ['header Bearer abc.DEF-123_xyz== ditolak', 'header Bearer [token] ditolak'],
            'sanctum' => ['token 3|'.str_repeat('a', 40).' dicabut', 'token [token] dicabut'],
            'nomor sertifikat' => ['sertifikat CAL/2026/09/0011 ganda', 'sertifikat [nomor-sertifikat] ganda'],
            'nomor revisi' => ['sertifikat CAL/2026/09/0011-R2 ganda', 'sertifikat [nomor-sertifikat] ganda'],
            'nomor awalan lab' => ['SIDIK-LAB/2026/11/00042 ganda', '[nomor-sertifikat] ganda'],
            'token buram' => ['qr '.str_repeat('f', 40).' tidak valid', 'qr [token] tidak valid'],
            'ip' => ['dari 10.20.30.40 ditolak', 'dari [ip] ditolak'],
            'tanpa pola' => ['Call to undefined method', 'Call to undefined method'],
        ];
    }

    #[DataProvider('polaPesan')]
    public function test_pola_di_pesan_disamarkan(string $masuk, string $keluar): void
    {
        $this->assertSame($keluar, PenyaringSentry::samarkan($masuk));
    }

    /**
     * `QueryException` Laravel menempelkan nama host, basis data, dan SQL dengan
     * nilai TERISI ke pesannya. Semuanya dari `(Connection:` ke akhir dibuang.
     */
    public function test_pesan_query_exception_dibuang_dari_connection(): void
    {
        $pesan = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' (Connection: mysql, Host: db.internal.example, Port: 3306, Database: sidik, SQL: insert into customers (nama) values ('PT Sumber Rahasia Makmur'))";

        $hasil = PenyaringSentry::samarkan($pesan);

        $this->assertStringContainsString('SQLSTATE[23000]', $hasil);
        $this->assertStringNotContainsString('Sumber Rahasia', $hasil);
        $this->assertStringNotContainsString('db.internal.example', $hasil);
        $this->assertStringEndsWith('(Connection: [disamarkan])', $hasil);
    }

    public function test_pesan_peristiwa_non_exception_disamarkan(): void
    {
        $event = Event::createEvent();
        $event->setMessage('Gagal untuk '.self::SURAT, ['%s' => self::SURAT], 'Gagal untuk '.self::SURAT);

        $hasil = PenyaringSentry::sebelumKirim($event);

        $this->assertSame('Gagal untuk [email]', $hasil->getMessage());
        $this->assertSame('Gagal untuk [email]', $hasil->getMessageFormatted());
        $this->assertSame([], $hasil->getMessageParams());
    }

    // ------------------------------------------------------ bukan gangguan

    /**
     * @return array<string, array{0: \Throwable}>
     */
    public static function galat4xx(): array
    {
        return [
            '403' => [new AccessDeniedHttpException],
            '404' => [new HttpException(404)],
            '405' => [new MethodNotAllowedHttpException(['GET'])],
            '409' => [new HttpException(409)],
            '419' => [new HttpException(419)],
            '422' => [new HttpException(422)],
            '429' => [new TooManyRequestsHttpException],
        ];
    }

    #[DataProvider('galat4xx')]
    public function test_http_4xx_dibuang(\Throwable $galat): void
    {
        $hint = EventHint::fromArray(['exception' => $galat]);

        $this->assertNull(PenyaringSentry::sebelumKirim($this->peristiwaKotor(), $hint));
    }

    public function test_http_5xx_dan_galat_biasa_diteruskan(): void
    {
        foreach ([new HttpException(500), new HttpException(503), new \RuntimeException('meledak')] as $galat) {
            $hint = EventHint::fromArray(['exception' => $galat]);

            $this->assertNotNull(PenyaringSentry::sebelumKirim($this->peristiwaKotor(), $hint), $galat::class);
        }
    }

    public function test_tanpa_hint_diteruskan(): void
    {
        $this->assertNotNull(PenyaringSentry::sebelumKirim($this->peristiwaKotor(), null));
    }

    public function test_bukan_gangguan_hanya_untuk_4xx(): void
    {
        $this->assertTrue(PenyaringSentry::bukanGangguan(new HttpException(400)));
        $this->assertTrue(PenyaringSentry::bukanGangguan(new HttpException(499)));
        $this->assertFalse(PenyaringSentry::bukanGangguan(new HttpException(500)));
        $this->assertFalse(PenyaringSentry::bukanGangguan(new \LogicException('x')));
    }

    public function test_breadcrumb_non_sql_dibuang_langsung(): void
    {
        foreach (['log', 'cache', 'queue.job', 'http', 'artisan.command'] as $kategori) {
            $this->assertNull(
                PenyaringSentry::sebelumBreadcrumb(
                    new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, $kategori, 'x'),
                ),
                $kategori,
            );
        }
    }
}
