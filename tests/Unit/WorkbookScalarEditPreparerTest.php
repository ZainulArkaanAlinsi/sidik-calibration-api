<?php

namespace Tests\Unit;

use App\Services\Studio\PetaSel\JalurIsi;
use App\Services\Studio\PetaSel\PemeriksaMaster;
use App\Services\Studio\WorkbookScalarEditPreparer;
use App\Support\JsonKanonik;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Pure synthetic overlay, not workbook parity, valid CMC, persistence, or activation. */
class WorkbookScalarEditPreparerTest extends TestCase
{
    private function baseline(): array
    {
        // Full existing schema structure; only explicit synthetic pilot leaves change.
        $base = json_decode(file_get_contents(dirname(__DIR__, 2).'/database/data/tabel-standar-micrometer.json'), true, 512, JSON_THROW_ON_ERROR);
        JalurIsi::tulis($base, 'cmc[kode=A].u95_um', 1.25);
        JalurIsi::tulis($base, 'cmc[kode=B].u95_um', 3.5); // Previous active edit must survive.

        return $base;
    }

    private function mappings(): array
    {
        $map = json_decode(file_get_contents(dirname(__DIR__, 2).'/database/data/peta-sel/micrometer.json'), true, 512, JSON_THROW_ON_ERROR);

        return array_values(array_filter($map['ekstrak'], static fn (array $entry): bool => in_array($entry['sel'] ?? null, ['DATABASE!T5', 'DATABASE!T6'], true)));
    }

    private function preparer(): WorkbookScalarEditPreparer
    {
        return new WorkbookScalarEditPreparer(new PemeriksaMaster);
    }

    public function test_only_submitted_leaf_changes_in_a_full_cloned_baseline(): void
    {
        $base = $this->baseline();
        $before = JsonKanonik::serialisasi($base);
        $expected = $base;
        JalurIsi::tulis($expected, 'cmc[kode=A].u95_um', 2.5);
        $result = $this->preparer()->prepare($base, $this->mappings(), [['sel' => 'DATABASE!T5', 'nilai' => '2.5']]);

        $this->assertSame(JsonKanonik::serialisasi($expected), JsonKanonik::serialisasi($result['isi']));
        $this->assertSame($before, JsonKanonik::serialisasi($base));
        $this->assertSame(3.5, JalurIsi::ambil($result['isi'], 'cmc[kode=B].u95_um'));
        $this->assertSame([['sel' => 'DATABASE!T5', 'jalur' => 'cmc[kode=A].u95_um', 'nilai_lama' => 1.25, 'nilai_baru' => 2.5]], $result['perubahan']);
    }

    public function test_noop_preserves_original_integer_or_float_types_and_hash(): void
    {
        foreach ([200, 200.0] as $old) {
            $base = $this->baseline();
            JalurIsi::tulis($base, 'cmc[kode=A].u95_um', $old);
            $hash = JsonKanonik::sha256(JsonKanonik::serialisasi($base));
            $result = $this->preparer()->prepare($base, $this->mappings(), [['sel' => 'DATABASE!T5', 'nilai' => '200.000']]);
            $this->assertSame($old, JalurIsi::ambil($result['isi'], 'cmc[kode=A].u95_um'));
            $this->assertSame([], $result['perubahan']);
            $this->assertSame($hash, JsonKanonik::sha256(JsonKanonik::serialisasi($result['isi'])));
        }

        $base = $this->baseline();
        $this->assertSame(['isi' => $base, 'perubahan' => []], $this->preparer()->prepare($base, $this->mappings(), []));
    }

    public static function invalidWireValues(): iterable
    {
        foreach ([null, true, 2.5, 2, [], new \stdClass, '', ' 2.5', '2.5 ', '+2.5', '2,5', '1,000.5', '=1+2', '1e2', 'NAN', 'INF', '-INF', '0.00000000001', '01.5', '.5', '2.', str_repeat('9', 129), '99999999999999999999999999999999999999999999999999', '123456789.1234567891', '123456789123456789.1'] as $i => $value) {
            yield 'invalid '.$i => [$value];
        }
    }

    #[DataProvider('invalidWireValues')]
    public function test_invalid_second_edit_rejects_all_without_mutating_input(mixed $value): void
    {
        $base = $this->baseline();
        $before = JsonKanonik::serialisasi($base);

        try {
            $this->preparer()->prepare($base, $this->mappings(), [
                ['sel' => 'DATABASE!T5', 'nilai' => '2.5'], ['sel' => 'DATABASE!T6', 'nilai' => $value],
            ]);
            $this->fail('Invalid batch must produce no prepared result.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, JsonKanonik::serialisasi($base));
        }
    }

    public function test_duplicate_address_and_different_addresses_resolving_to_same_leaf_are_rejected(): void
    {
        $mappings = $this->mappings();
        $alias = $mappings[0];
        $alias['sel'] = 'DATABASE!T7';
        $alias['jalur'] = 'cmc[0].u95_um';
        foreach ([$mappings, [...$mappings, $alias]] as $i => $definitions) {
            $this->expectRejected($this->baseline(), $definitions, [
                ['sel' => 'DATABASE!T5', 'nilai' => '2.5'],
                ['sel' => $i === 0 ? 'DATABASE!T5' : 'DATABASE!T7', 'nilai' => '2.5'],
            ]);
        }
    }

    public function test_duplicate_mapping_address_is_not_silently_overwritten(): void
    {
        $mappings = $this->mappings();
        $mappings[] = $mappings[0];
        $this->expectRejected($this->baseline(), $mappings, [['sel' => 'DATABASE!T5', 'nilai' => '2.5']]);
    }

    public static function dotNumericAliases(): iterable
    {
        yield 'code and dot numeric key' => ['cmc[kode=A].u95_um', 'cmc.0.u95_um'];
        yield 'nested bracket and dot numeric keys' => ['cmc[0].titik[0].nominal_cetak_mm', 'cmc.0.titik.0.nominal_cetak_mm'];
    }

    #[DataProvider('dotNumericAliases')]
    public function test_dot_numeric_key_aliases_are_rejected(string $firstPath, string $secondPath): void
    {
        $mappings = $this->mappings();
        $mappings[0]['jalur'] = $firstPath;
        $mappings[1]['jalur'] = $secondPath;
        $this->expectRejected($this->baseline(), $mappings, [
            ['sel' => 'DATABASE!T5', 'nilai' => '2.5'], ['sel' => 'DATABASE!T6', 'nilai' => '3.5'],
        ]);
    }

    public function test_leading_zero_object_keys_remain_distinct(): void
    {
        $base = $this->baseline();
        // Synthetic path-parser edge fixture, not an addition to the real profile schema.
        $base['synthetic_keys'] = ['01' => ['value' => 1.25], 1 => ['value' => 1.25]];
        $before = JsonKanonik::serialisasi($base);
        $mappings = $this->mappings();
        $mappings[0]['jalur'] = 'synthetic_keys.01.value';
        $mappings[1]['jalur'] = 'synthetic_keys.1.value';
        $result = $this->preparer()->prepare($base, $mappings, [
            ['sel' => 'DATABASE!T5', 'nilai' => '2.5'], ['sel' => 'DATABASE!T6', 'nilai' => '3.5'],
        ]);

        $this->assertCount(2, $result['perubahan']);
        $this->assertSame(2.5, $result['isi']['synthetic_keys']['01']['value']);
        $this->assertSame(3.5, $result['isi']['synthetic_keys'][1]['value']);
        $this->assertSame($before, JsonKanonik::serialisasi($base));
    }

    public function test_missing_paths_duplicate_codes_and_non_numeric_leaves_are_rejected(): void
    {
        foreach (['missing', 'duplicate code', 'wrong list shape', 'string', 'null', 'infinite'] as $fault) {
            $base = $this->baseline();
            if ($fault === 'missing') {
                unset($base['cmc'][0]['u95_um']);
            } elseif ($fault === 'duplicate code') {
                $base['cmc'][] = $base['cmc'][0];
            } elseif ($fault === 'wrong list shape') {
                $base['cmc'] = ['A' => $base['cmc'][0]];
            } else {
                $base['cmc'][0]['u95_um'] = match ($fault) {
                    'string' => '1.25', 'null' => null, 'infinite' => INF,
                };
            }
            $this->expectRejected($base, $this->mappings(), [['sel' => 'DATABASE!T5', 'nilai' => '2.5']]);
        }
    }

    public static function unsupportedMappings(): iterable
    {
        foreach ([['bagi' => 1], ['ulang' => 1], ['varian' => ['A']], ['nol_di_bawah' => 0.1], ['angka_penting' => 10], ['bulat' => 8], ['tipe' => 'teks'], ['sel' => 'DATABASE!T5:T6'], ['jalur' => 'cmc[kode=A].new_leaf'], ['gabungan' => true], ['unknown' => true]] as $i => $patch) {
            yield 'mapping '.$i => [$patch];
        }
    }

    #[DataProvider('unsupportedMappings')]
    public function test_unsupported_shape_or_transform_cannot_become_editable(array $patch): void
    {
        $mappings = $this->mappings();
        $mappings[0] = array_replace($mappings[0], $patch);
        $this->expectRejected($this->baseline(), $mappings, [['sel' => 'DATABASE!T5', 'nilai' => '2.5']]);
    }

    public function test_batch_and_shape_resource_guards_are_explicit(): void
    {
        $edit = ['sel' => 'DATABASE!T5', 'nilai' => '2.5'];
        foreach ([['bad' => $edit], [['sel' => 'DATABASE!T5']], [array_merge($edit, ['jalur' => 'cmc[0].u95_um'])], [['sel' => 'DATABASE!T99', 'nilai' => '2.5']], array_fill(0, 301, $edit)] as $edits) {
            $this->expectRejected($this->baseline(), $this->mappings(), $edits);
        }
    }

    public function test_existing_forward_rounder_is_actually_used_and_loss_is_rejected(): void
    {
        $checker = new PemeriksaMaster;
        $this->assertSame(2.5, $checker->normalizeScalarNumber(2.5, 'DATABASE!T5', 10));
        $this->assertSame(0.1234567891, $checker->normalizeScalarNumber(0.12345678905, 'DATABASE!T5', 10));
        $this->expectRejected($this->baseline(), $this->mappings(), [['sel' => 'DATABASE!T5', 'nilai' => '123456789123456789.1']]);
    }

    public function test_wrapper_rejects_nonfinite_candidates(): void
    {
        foreach ([NAN, INF, -INF] as $value) {
            try {
                (new PemeriksaMaster)->normalizeScalarNumber($value, 'DATABASE!T5', 10);
                $this->fail('The public forward wrapper must reject nonfinite candidates.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_multiple_valid_edits_are_applied_together(): void
    {
        $base = $this->baseline();
        $result = $this->preparer()->prepare($base, $this->mappings(), [
            ['sel' => 'DATABASE!T5', 'nilai' => '2.5'], ['sel' => 'DATABASE!T6', 'nilai' => '4.5'],
        ]);
        $this->assertCount(2, $result['perubahan']);
        $this->assertSame(2.5, JalurIsi::ambil($result['isi'], 'cmc[kode=A].u95_um'));
        $this->assertSame(4.5, JalurIsi::ambil($result['isi'], 'cmc[kode=B].u95_um'));
        $this->assertSame(1.25, JalurIsi::ambil($base, 'cmc[kode=A].u95_um'));
        $this->assertSame(3.5, JalurIsi::ambil($base, 'cmc[kode=B].u95_um'));
    }

    private function expectRejected(array $base, array $mappings, array $edits): void
    {
        // Input may deliberately contain INF, so do not canonicalize malformed bases.
        $before = serialize($base);
        try {
            $this->preparer()->prepare($base, $mappings, $edits);
            $this->fail('Invalid scalar preparation must throw.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, serialize($base));
        }
    }
}
