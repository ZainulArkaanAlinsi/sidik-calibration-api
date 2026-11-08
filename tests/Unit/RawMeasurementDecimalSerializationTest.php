<?php

namespace Tests\Unit;

use App\Models\RawMeasurement;
use Brick\Math\BigDecimal;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Model-only boundary: no application bootstrap, connection, or persisted-data claim. */
class RawMeasurementDecimalSerializationTest extends TestCase
{
    private const COLUMNS = ['pembacaan', 'titik_ukur'];

    /** @return iterable<string, array{string, float, string}> */
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
        ];

        foreach (self::COLUMNS as $column) {
            foreach ($cases as $label => [$value, $expected]) {
                yield $column.' '.$label => [$column, $value, $expected];
            }
        }
    }

    #[DataProvider('floatCases')]
    public function test_float_writes_store_literal_numeric_strings_and_keep_float_reads(string $column, float $value, string $expected): void
    {
        $this->assertSame('-1', ini_get('serialize_precision'), 'Run normal cases with the required unmodified PHP configuration.');
        $models = [new RawMeasurement([$column => $value]), new RawMeasurement, new RawMeasurement];
        $models[1]->fill([$column => $value]);
        $models[2]->{$column} = $value;

        foreach ($models as $model) {
            $this->assertSame($expected, $model->getAttributes()[$column]);
            $this->assertIsFloat($model->{$column});
            $this->assertSame($value, $model->{$column});
            $json = $model->toJson(JSON_THROW_ON_ERROR);
            $matched = preg_match('/"'.preg_quote($column, '/').'"\s*:\s*(-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=\s*[,}])/', $json, $matches);
            $this->assertSame(1, $matched, 'Read serialization must still emit a JSON number.');
            $this->assertTrue(BigDecimal::of($expected)->isEqualTo(BigDecimal::of($matches[1])));
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

    /** @return iterable<string, array{string, float}> */
    public static function nonfiniteCases(): iterable
    {
        foreach (self::COLUMNS as $column) {
            foreach (['NAN' => NAN, 'positive infinity' => INF, 'negative infinity' => -INF] as $label => $value) {
                yield $column.' '.$label => [$column, $value];
            }
        }
    }

    #[DataProvider('nonfiniteCases')]
    public function test_nonfinite_values_throw_without_changing_attributes(string $column, float $value): void
    {
        $this->assertSame('-1', ini_get('serialize_precision'));
        $model = new RawMeasurement(['pembacaan' => '1.25000000', 'titik_ukur' => '2.50000000']);
        $before = $model->getAttributes();

        try {
            $model->{$column} = $value;
            $this->fail('Nonfinite input must throw rather than replacing the previous attribute.');
        } catch (JsonException $exception) {
            $this->assertSame(JSON_ERROR_INF_OR_NAN, $exception->getCode());
            $this->assertSame($before, $model->getAttributes());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafePrecisionCases(): iterable
    {
        foreach (self::COLUMNS as $column) {
            foreach (['14', '17'] as $precision) {
                yield $column.' precision '.$precision => [$column, $precision];
            }
        }
    }

    #[DataProvider('unsafePrecisionCases')]
    public function test_unsafe_precision_fails_closed_in_an_isolated_php_process(string $column, string $precision): void
    {
        // -d applies only to the child process, never this runner or a PHP INI file.
        $probe = <<<'PHP'
require $argv[1];
if (ini_get('serialize_precision') !== $argv[2]) {
    fwrite(STDERR, 'Unexpected child precision.');
    exit(10);
}
$model = new \App\Models\RawMeasurement(['pembacaan' => '1.25000000', 'titik_ukur' => '2.50000000']);
$before = $model->getAttributes();
try {
    $model->{$argv[3]} = 1.25;
    fwrite(STDERR, 'Unsafe float write did not throw.');
    exit(11);
} catch (\LogicException $exception) {
    if ($model->getAttributes() !== $before) {
        fwrite(STDERR, 'Rejected float write changed attributes.');
        exit(12);
    }
}
fwrite(STDOUT, 'guarded');
PHP;
        $process = new Process([PHP_BINARY, '-d', 'serialize_precision='.$precision, '-r', $probe,
            dirname(__DIR__, 2).'/vendor/autoload.php', $precision, $column]);
        $process->setTimeout(15);
        $exitCode = $process->run();

        $this->assertSame(0, $exitCode, $process->getErrorOutput());
        $this->assertSame('guarded', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }
}
