<?php

namespace Tests\Unit;

use App\Models\RawMeasurement;
use App\Support\AngkaFloatEksak;
use Brick\Math\BigDecimal;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Process\Process;

/** Model-only boundary: no application bootstrap, connection, or persisted-data claim. */
class RawMeasurementDecimalSerializationTest extends TestCase
{
    private const COLUMNS = ['pembacaan', 'titik_ukur'];

    /** Server settings that a misconfigured PHP may carry: shortest, truncating, noisy. */
    private const PRECISIONS = ['-1', '14', '17'];

    private string $precisionBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->precisionBefore = (string) ini_get('serialize_precision');
        $this->forgetLoggedOnce();
    }

    protected function tearDown(): void
    {
        ini_set('serialize_precision', $this->precisionBefore);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    /** @return iterable<string, array{string, float, string, string}> */
    public static function floatCases(): iterable
    {
        // Literal shortest JSON spellings, independent of the encoder being tested.
        $cases = [
            'positive large tenth' => [100000000.1, '100000000.1'],
            'negative large tenth' => [-100000000.1, '-100000000.1'],
            'smallest nonzero scale eight' => [0.00000001, '1.0e-8'],
            'twelve integer digits with fraction' => [100000000000.00002, '100000000000.00002'],
            'near maximum magnitude' => [999999999999.9999, '999999999999.9999'],
            'exact binary eighth with fifteen digits' => [100000000000.125, '100000000000.125'],
            'seventeen significant decimal digits' => [123456789.12345678, '123456789.12345678'],
            'tenth stays short, no seventeen-digit noise' => [0.1, '0.1'],
        ];

        foreach (self::COLUMNS as $column) {
            foreach (self::PRECISIONS as $precision) {
                foreach ($cases as $label => [$value, $expected]) {
                    yield $column.' precision '.$precision.' '.$label => [$column, $value, $expected, $precision];
                }
            }
        }
    }

    #[DataProvider('floatCases')]
    public function test_float_writes_store_literal_numeric_strings_whatever_serialize_precision_is(string $column, float $value, string $expected, string $precision): void
    {
        ini_set('serialize_precision', $precision);
        $models = [new RawMeasurement([$column => $value]), new RawMeasurement, new RawMeasurement];
        $models[1]->fill([$column => $value]);
        $models[2]->{$column} = $value;

        foreach ($models as $model) {
            $this->assertSame($expected, $model->getAttributes()[$column]);
            $this->assertIsFloat($model->{$column});
            $this->assertSame($value, $model->{$column});
            if ($precision !== '-1') {
                // Reading is NOT forced: toJson() follows the server's serialize_precision
                // (14 truncates, 17 adds noise). That is what /api/health reports.
                continue;
            }

            $json = $model->toJson(JSON_THROW_ON_ERROR);
            $matched = preg_match('/"'.preg_quote($column, '/').'"\s*:\s*(-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=\s*[,}])/', $json, $matches);
            $this->assertSame(1, $matched, 'Read serialization must still emit a JSON number.');
            $this->assertTrue(BigDecimal::of($expected)->isEqualTo(BigDecimal::of($matches[1])));
        }

        $this->assertSame($precision, ini_get('serialize_precision'), 'The server setting must be restored after the write.');
    }

    /** @return iterable<string, array{string}> */
    public static function precisionCases(): iterable
    {
        foreach (self::PRECISIONS as $precision) {
            yield 'precision '.$precision => [$precision];
        }
    }

    #[DataProvider('precisionCases')]
    public function test_values_up_to_fourteen_digits_equal_the_pre_pr_float_cast(string $precision): void
    {
        $legacyPrecision = (string) ini_get('precision');
        ini_set('precision', '14'); // PHP's default float-to-string cast, which PDO applied before this fix.
        ini_set('serialize_precision', $precision);

        try {
            foreach ([0.1, 1.5, 12.34, 100000000.0, -250.25, 0.00000001, 99999999999999.0, 1234.5678] as $value) {
                $this->assertTrue(
                    BigDecimal::of((string) $value)->isEqualTo(BigDecimal::of(AngkaFloatEksak::kodekan($value))),
                    'A value of at most 14 significant digits must keep its pre-PR decimal value.',
                );
            }
        } finally {
            ini_set('precision', $legacyPrecision);
        }
    }

    /** @return iterable<string, array{string, int|string|null}> */
    public static function unchangedCases(): iterable
    {
        $cases = ['null' => null, 'zero' => 0, 'integer' => 12, 'negative integer' => -12,
            'numeric string' => '100000000000.00002000', 'trailing zero string' => '1.23000000'];

        foreach (self::COLUMNS as $column) {
            foreach ($cases as $label => $value) {
                yield $column.' '.$label => [$column, $value];
            }
        }
    }

    #[DataProvider('unchangedCases')]
    public function test_non_float_values_keep_their_raw_type_and_value(string $column, int|string|null $value): void
    {
        $model = new RawMeasurement([$column => $value]);
        $this->assertSame($value, $model->getAttributes()[$column]);

        if ($value === null) {
            $this->assertNull($model->{$column});
        } else {
            $this->assertIsFloat($model->{$column});
        }
    }

    /** @return iterable<string, array{string, float, string}> */
    public static function nonfiniteCases(): iterable
    {
        foreach (self::COLUMNS as $column) {
            foreach (['-1', '17'] as $precision) {
                foreach (['NAN' => NAN, 'positive infinity' => INF, 'negative infinity' => -INF] as $label => $value) {
                    yield $column.' precision '.$precision.' '.$label => [$column, $value, $precision];
                }
            }
        }
    }

    #[DataProvider('nonfiniteCases')]
    public function test_nonfinite_values_throw_without_changing_attributes_or_the_server_setting(string $column, float $value, string $precision): void
    {
        ini_set('serialize_precision', $precision);
        $model = new RawMeasurement(['pembacaan' => '1.25000000', 'titik_ukur' => '2.50000000']);
        $before = $model->getAttributes();

        try {
            $model->{$column} = $value;
            $this->fail('Nonfinite input must throw rather than replacing the previous attribute.');
        } catch (JsonException $exception) {
            $this->assertSame(JSON_ERROR_INF_OR_NAN, $exception->getCode());
            $this->assertSame($before, $model->getAttributes());
            $this->assertSame($precision, ini_get('serialize_precision'), 'The server setting must be restored when encoding throws.');
        }
    }

    public function test_a_forced_write_is_logged_once_per_process_without_reading_data(): void
    {
        $log = new class
        {
            /** @var list<array{string, array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function warning(string $message, array $context = []): void
            {
                $this->records[] = [$message, $context];
            }
        };
        $app = new Container;
        $app->instance('log', $log);
        Facade::setFacadeApplication($app);

        ini_set('serialize_precision', '-1');
        $unforced = new RawMeasurement;
        $unforced->pembacaan = 123456789.12345678;
        $this->assertSame([], $log->records, 'Nothing is forced, so nothing is logged.');

        ini_set('serialize_precision', '17');
        $model = new RawMeasurement;
        $model->pembacaan = 123456789.12345678;
        $model->titik_ukur = 100000000000.125;
        $model->pembacaan = 0.1;

        $this->assertCount(1, $log->records, 'Log once per process, not once per value.');
        [$message, $context] = $log->records[0];
        $this->assertSame(['serialize_precision_terkonfigurasi' => '17'], $context);
        $this->assertStringNotContainsString('123456789', $message.json_encode($context));
        $this->assertStringNotContainsString('100000000000', $message.json_encode($context));
    }

    public function test_a_failing_logger_never_blocks_the_write(): void
    {
        // No facade root at all: Log::warning() itself throws inside the encoder.
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        ini_set('serialize_precision', '14');
        $model = new RawMeasurement;
        $model->pembacaan = 100000000000.00002;

        $this->assertSame('100000000000.00002', $model->getAttributes()['pembacaan']);
        $this->assertSame('14', ini_get('serialize_precision'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function misconfiguredPrecisionCases(): iterable
    {
        foreach (self::COLUMNS as $column) {
            foreach (['14', '17'] as $precision) {
                yield $column.' precision '.$precision => [$column, $precision];
            }
        }
    }

    #[DataProvider('misconfiguredPrecisionCases')]
    public function test_misconfigured_precision_stays_exact_in_an_isolated_php_process(string $column, string $precision): void
    {
        // -d applies only to the child process, never this runner or a PHP INI file.
        $probe = <<<'PHP'
require $argv[1];
if (ini_get('serialize_precision') !== $argv[2]) {
    fwrite(STDERR, 'Unexpected child precision.');
    exit(10);
}
$model = new \App\Models\RawMeasurement;
$model->{$argv[3]} = 100000000000.00002;
if ($model->getAttributes()[$argv[3]] !== '100000000000.00002') {
    fwrite(STDERR, 'Float was not stored exactly: '.var_export($model->getAttributes()[$argv[3]], true));
    exit(11);
}
if (ini_get('serialize_precision') !== $argv[2]) {
    fwrite(STDERR, 'Server precision was not restored.');
    exit(12);
}
fwrite(STDOUT, 'exact');
PHP;
        [$exitCode, $stdout, $stderr] = $this->runChild($probe, ['serialize_precision='.$precision], [$precision, $column]);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertSame('exact', $stdout);
        $this->assertSame('', $stderr);
    }

    #[DataProvider('misconfiguredPrecisionCases')]
    public function test_rejected_ini_set_throws_a_clear_error_and_changes_nothing(string $column, string $precision): void
    {
        $probe = <<<'PHP'
require $argv[1];
if (function_exists('ini_set')) {
    fwrite(STDERR, 'ini_set should be disabled in this child.');
    exit(10);
}
$model = new \App\Models\RawMeasurement(['pembacaan' => '1.25000000', 'titik_ukur' => '2.50000000']);
$before = $model->getAttributes();
try {
    $model->{$argv[3]} = 100000000000.00002;
    fwrite(STDERR, 'Write did not throw although ini_set is unavailable.');
    exit(11);
} catch (\RuntimeException $exception) {
    if (! str_contains($exception->getMessage(), 'serialize_precision')) {
        fwrite(STDERR, 'Message does not name the setting: '.$exception->getMessage());
        exit(12);
    }
    if ($model->getAttributes() !== $before || ini_get('serialize_precision') !== $argv[2]) {
        fwrite(STDERR, 'Rejected write changed state.');
        exit(13);
    }
}
fwrite(STDOUT, 'rejected');
PHP;
        [$exitCode, $stdout, $stderr] = $this->runChild($probe, ['serialize_precision='.$precision, 'disable_functions=ini_set'], [$precision, $column]);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertSame('rejected', $stdout);
        $this->assertSame('', $stderr);
    }

    public function test_correct_precision_needs_no_ini_set_even_when_it_is_disabled(): void
    {
        $probe = <<<'PHP'
require $argv[1];
$model = new \App\Models\RawMeasurement;
$model->pembacaan = 100000000000.00002;
fwrite(STDOUT, $model->getAttributes()['pembacaan']);
PHP;
        [$exitCode, $stdout, $stderr] = $this->runChild($probe, ['serialize_precision=-1', 'disable_functions=ini_set'], []);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertSame('100000000000.00002', $stdout);
        $this->assertSame('', $stderr);
    }

    /**
     * @param  list<string>  $ini  `-d` settings, applied to the child only
     * @param  list<string>  $args  passed after the autoloader path
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runChild(string $probe, array $ini, array $args): array
    {
        $command = [PHP_BINARY];
        foreach ($ini as $setting) {
            array_push($command, '-d', $setting);
        }
        array_push($command, '-r', $probe, dirname(__DIR__, 2).'/vendor/autoload.php', ...$args);

        $process = new Process($command);
        $process->setTimeout(15);
        $exitCode = $process->run();

        return [$exitCode, $process->getOutput(), $process->getErrorOutput()];
    }

    private function forgetLoggedOnce(): void
    {
        (new ReflectionProperty(AngkaFloatEksak::class, 'sudahDicatat'))->setValue(null, false);
    }
}
