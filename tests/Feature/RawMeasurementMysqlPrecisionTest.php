<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CalibrationController;
use App\Models\CalibrationSession;
use App\Models\RawMeasurement;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Storage integration only: JSON numeric -> production rounder -> Eloquent/PDO
 * -> real MySQL DECIMAL text -> reloaded model JSON. These deliberately large
 * synthetic readings do not assert profile range, CMC, calibration validity,
 * complete FormRequest/HTTP behavior, certificate correctness, or OCR accuracy.
 */
class RawMeasurementMysqlPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = ['pembacaan', 'titik_ukur'];

    /** Guard BEFORE RefreshDatabase can connect/migrate, not merely in the test body. */
    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires real MySQL DECIMAL storage; SQLite is not evidence.');
        }
        if (getenv('GITHUB_ACTIONS') !== 'true') {
            $this->markTestSkipped('Restricted to the existing ephemeral GitHub Actions MySQL service.');
        }

        // Fail closed without including actual connection settings/credentials in output.
        $this->assertTrue(app()->environment('testing'), 'Requires testing environment.');
        $this->assertTrue(config('database.connections.mysql.host') === '127.0.0.1', 'Requires the loopback CI service.');
        $this->assertTrue((string) config('database.connections.mysql.port') === '3307', 'Requires the isolated CI service port.');
        $this->assertTrue(config('database.connections.mysql.database') === 'asmo_db_test', 'Requires the dedicated CI database.');
        $this->assertTrue(config('database.connections.mysql.username') === 'sidik_test', 'Requires the narrow CI test user.');
        $this->assertTrue(empty(config('database.connections.mysql.url')), 'Connection URLs are forbidden for this fixture.');
        $this->assertTrue(empty(config('database.connections.mysql.unix_socket')), 'Unix sockets are forbidden for this fixture.');
    }

    /** @return array<string, array{string, string}> */
    public static function decimalCases(): array
    {
        return [
            'positive large tenth' => ['100000000.1', '100000000.10000000'],
            'negative large tenth' => ['-100000000.1', '-100000000.10000000'],
            'smallest nonzero scale eight' => ['0.00000001', '0.00000001'],
            'twelve integer digits with fraction' => ['100000000000.00002', '100000000000.00002000'],
            'near maximum magnitude' => ['999999999999.9999', '999999999999.99990000'],
            'exact binary eighth with fifteen digits' => ['100000000000.125', '100000000000.12500000'],
            'seventeen significant decimal digits' => ['123456789.12345678', '123456789.12345678'],
        ];
    }

    #[DataProvider('decimalCases')]
    public function test_numeric_request_survives_rounding_storage_and_reloaded_json(string $inputLexeme, string $expectedStored): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $session = CalibrationSession::factory()->create([
            'status' => CalibrationSession::STATUS_DRAFT,
            'keputusan' => null,
            'nomor_order' => null,
        ]);
        $controller = app(CalibrationController::class);
        $rounder = new ReflectionMethod($controller, 'bulatkanKolom');

        // Fixed, source-controlled column identifiers only. Readings are checked first.
        foreach (self::COLUMNS as $column) {
            $request = Request::create('/__test/raw-storage', 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], '{"value":'.$inputLexeme.'}');
            $validated = Validator::make($request->json()->all(), ['value' => ['required', 'numeric']])->validate();
            $number = $validated['value'];
            $this->assertTrue(is_float($number) || is_int($number), 'Fixture must enter as a JSON number, not a numeric string.');

            // Invoke the real rounder; do not copy round() or change PHP INI precision.
            // Both columns share this bounded storage contract, not a full controller-route claim.
            $rounded = $rounder->invoke($controller, $number, 8);
            $raw = RawMeasurement::query()->create([
                'calibration_session_id' => $session->id,
                'titik_ke' => 1,
                'pembacaan_ke' => 1,
                'titik_ukur' => 0,
                'pembacaan' => 0,
                $column => $rounded,
                'satuan' => 'unit',
                'input_source' => 'manual',
                'is_verified' => true,
            ]);
            $context = $column.' input='.$inputLexeme
                .' precision='.ini_get('precision').' serialize_precision='.ini_get('serialize_precision');
            $stored = DB::table('raw_measurements')->where('id', $raw->id)
                ->selectRaw('CAST('.$column.' AS CHAR) AS exact_value')->first();
            $this->assertNotNull($stored, $context);
            $this->assertIsString($stored->exact_value, $context.' raw DB value must bypass float model casts.');
            $this->assertSame($expectedStored, $stored->exact_value, $context.' stored decimal changed.');

            $fresh = $raw->fresh();
            $this->assertNotNull($fresh, $context);
            $json = $fresh->toJson(JSON_THROW_ON_ERROR);
            $matched = preg_match('/"'.preg_quote($column, '/').'"\s*:\s*(-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=\s*[,}])/', $json, $matches);
            $this->assertSame(1, $matched, $context.' reloaded JSON must contain a numeric lexeme.');
            $this->assertTrue(
                BigDecimal::of($matches[1])->isEqualTo(BigDecimal::of($inputLexeme)),
                $context.' reloaded JSON numeric lexeme changed to '.$matches[1],
            );
        }
    }

    public function test_model_update_and_relation_create_many_preserve_both_raw_columns(): void
    {
        $session = CalibrationSession::factory()->create([
            'status' => CalibrationSession::STATUS_DRAFT,
            'keputusan' => null,
            'nomor_order' => null,
        ]);
        $attributes = [
            'titik_ke' => 1,
            'pembacaan_ke' => 1,
            'titik_ukur' => 0,
            'pembacaan' => 0,
            'satuan' => 'unit',
            'input_source' => 'manual',
            'is_verified' => true,
        ];

        $raw = $session->rawMeasurements()->create($attributes);
        $raw->update([
            'pembacaan' => 123456789.12345678,
            'titik_ukur' => 123456789.12345678,
        ]);
        $this->assertExactStoredColumns($raw, '123456789.12345678');

        $batch = $session->rawMeasurements()->createMany([
            array_replace($attributes, ['titik_ke' => 2, 'pembacaan' => 123456789.12345678, 'titik_ukur' => 123456789.12345678]),
            array_replace($attributes, ['titik_ke' => 3, 'pembacaan' => 100000000000.125, 'titik_ukur' => 100000000000.125]),
        ]);
        $this->assertCount(2, $batch);
        $this->assertExactStoredColumns($batch[0], '123456789.12345678');
        $this->assertExactStoredColumns($batch[1], '100000000000.12500000');
    }

    private function assertExactStoredColumns(RawMeasurement $raw, string $expected): void
    {
        foreach (self::COLUMNS as $column) {
            $stored = DB::table('raw_measurements')->where('id', $raw->id)
                ->selectRaw('CAST('.$column.' AS CHAR) AS exact_value')->first();
            $this->assertNotNull($stored);
            $this->assertSame($expected, $stored->exact_value, $column.' must survive its ORM write path unchanged.');
        }
    }
}
