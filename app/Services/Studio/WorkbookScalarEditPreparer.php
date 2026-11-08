<?php

namespace App\Services\Studio;

use App\Services\Studio\PetaSel\Alamat;
use App\Services\Studio\PetaSel\JalurIsi;
use App\Services\Studio\PetaSel\PemeriksaMaster;
use App\Support\JsonKanonik;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pure overlay for trusted server-selected direct numeric mappings, not an HTTP
 * payload, authorization check, schema validator, persistence, or CMC approval.
 */
final class WorkbookScalarEditPreparer
{
    // Resource limits for this prototype, never calibration ranges.
    public const MAX_EDITS = 300;

    public const MAX_MAPPINGS = 300;

    public const MAX_WIRE_BYTES = 128;

    public const MAX_ADDRESS_BYTES = 256;

    public const MAX_PATH_BYTES = 512;

    public function __construct(private readonly PemeriksaMaster $checker) {}

    /**
     * @param  array<array-key, mixed>  $base  Canonical decoded locked basis; never a workbook re-extraction.
     * @param  list<array<string, mixed>>  $serverMappings  Trusted extraction subset, not request-controlled paths.
     * @param  list<array{sel: string, nilai: string}>  $edits
     * @return array{isi: array<array-key, mixed>, perubahan: list<array{sel: string, jalur: string, nilai_lama: int|float, nilai_baru: float}>}
     *
     * @throws InvalidArgumentException unsupported mapping or invalid batch; no partial result
     */
    public function prepare(array $base, array $serverMappings, array $edits): array
    {
        if (! array_is_list($edits) || count($edits) > self::MAX_EDITS
            || ! array_is_list($serverMappings) || count($serverMappings) > self::MAX_MAPPINGS) {
            throw new InvalidArgumentException('Scalar edits and server mappings must be bounded lists.');
        }

        // Validate every wire value before any mapping lookup or overlay.
        $parsed = [];
        foreach ($edits as $edit) {
            if (! is_array($edit) || count($edit) !== 2 || ! array_key_exists('sel', $edit) || ! array_key_exists('nilai', $edit)
                || ! is_string($edit['sel']) || strlen($edit['sel']) > self::MAX_ADDRESS_BYTES
                || array_key_exists($edit['sel'], $parsed)) {
                throw new InvalidArgumentException('Each scalar edit needs one unique address and one numeric wire value.');
            }
            $parsed[$edit['sel']] = $this->parseWire($edit['nilai']);
        }

        $mappings = [];
        $resolvedPaths = [];
        foreach ($serverMappings as $mapping) {
            if (! is_array($mapping) || count($mapping) !== 4
                || array_diff(array_keys($mapping), ['sel', 'jalur', 'tipe', 'bulat']) !== []
                || ($mapping['tipe'] ?? null) !== 'angka' || ($mapping['bulat'] ?? null) !== 10
                || ! is_string($mapping['sel'] ?? null) || strlen($mapping['sel']) > self::MAX_ADDRESS_BYTES
                || ! is_string($mapping['jalur'] ?? null) || strlen($mapping['jalur']) > self::MAX_PATH_BYTES) {
                throw new InvalidArgumentException('Only direct numeric single-cell mappings with the existing bulat10 rule are supported.');
            }
            $address = Alamat::urai($mapping['sel']);
            if ($address === null || $address['rentang'] || array_key_exists($mapping['sel'], $mappings)) {
                throw new InvalidArgumentException('Server mapping addresses must be unique single cells.');
            }

            $resolved = $this->resolveExistingPath($base, $mapping['jalur']);
            if (isset($resolvedPaths[$resolved])) {
                throw new InvalidArgumentException('Server mappings must not alias the same resolved leaf.');
            }
            $resolvedPaths[$resolved] = true;
            // tulis() may create object keys: ambil() is deliberately required first.
            try {
                $current = JalurIsi::ambil($base, $mapping['jalur']);
            } catch (RuntimeException $exception) {
                throw new InvalidArgumentException('Mapped scalar leaf must already exist.', previous: $exception);
            }
            if ((! is_int($current) && ! is_float($current)) || ! is_finite((float) $current)) {
                throw new InvalidArgumentException('Mapped scalar leaves must already contain finite numbers.');
            }
            $mappings[$mapping['sel']] = $mapping + ['current' => $current];
        }

        $changes = [];
        foreach ($parsed as $address => [$decimal, $candidate]) {
            if (! isset($mappings[$address])) {
                throw new InvalidArgumentException('Scalar edit address is not in the server mapping subset.');
            }
            $mapping = $mappings[$address];
            // No new rounding algorithm: delegate to actual master extraction, without division.
            $normalized = $this->checker->normalizeScalarNumber($candidate, $address, $mapping['bulat']);
            if (! is_finite($normalized) || ! $this->sameDecimal($decimal, $normalized)) {
                throw new InvalidArgumentException('Existing forward normalization changes the submitted decimal.');
            }
            if ($this->sameDecimal($decimal, $mapping['current'])) {
                continue; // Preserve original int/float type, canonical hash, and empty audit delta.
            }
            $changes[] = ['sel' => $address, 'jalur' => $mapping['jalur'], 'nilai_lama' => $mapping['current'], 'nilai_baru' => $normalized];
        }

        // Apply only after the whole batch validates; PHP array copy preserves all other leaves/types.
        $result = $base;
        foreach ($changes as $change) {
            JalurIsi::tulis($result, $change['jalur'], $change['nilai_baru']);
        }

        return ['isi' => $result, 'perubahan' => $changes];
    }

    /** @return array{BigDecimal, float} */
    private function parseWire(mixed $wire): array
    {
        if (! is_string($wire) || strlen($wire) > self::MAX_WIRE_BYTES
            || preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,10})?\z/', $wire) !== 1) {
            throw new InvalidArgumentException('Scalar wire values require plain ASCII decimals with at most ten fractional digits.');
        }
        $decimal = BigDecimal::of($wire);
        $candidate = (float) $wire;
        if (! is_finite($candidate) || ! $this->sameDecimal($decimal, $candidate)) {
            throw new InvalidArgumentException('Scalar wire value loses decimal information in the existing numeric representation.');
        }

        return [$decimal, $candidate];
    }

    private function sameDecimal(BigDecimal $expected, int|float $value): bool
    {
        return $expected->isEqualTo(BigDecimal::of(JsonKanonik::serialisasi($value)));
    }

    /** Reject missing leaves, wrong list shapes and duplicate matching codes before JalurIsi writes. */
    private function resolveExistingPath(array $base, string $path): string
    {
        $segments = JalurIsi::urai($path);
        if ($segments === null) {
            throw new InvalidArgumentException('Server mapping path is malformed.');
        }
        $value = $base;
        $resolved = [];
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment['kunci'], $value)) {
                throw new InvalidArgumentException('Server mapping path does not exist in the basis.');
            }
            // Match native PHP array-key coercion: '0' aliases 0, while '01' stays distinct.
            $effectiveKey = array_key_first([$segment['kunci'] => true]);
            $resolved[] = $effectiveKey;
            $value = $value[$segment['kunci']];
            if ($segment['kode'] === null && $segment['indeks'] === null) {
                continue;
            }
            if (! is_array($value) || ! array_is_list($value)) {
                throw new InvalidArgumentException('Server mapping list must retain its existing list shape.');
            }
            $index = $segment['indeks'];
            if ($segment['kode'] !== null) {
                $matches = array_keys(array_filter($value, static fn (mixed $row): bool => is_array($row) && (string) ($row['kode'] ?? '') === $segment['kode']));
                if (count($matches) !== 1) {
                    throw new InvalidArgumentException('Server mapping code must identify exactly one existing row.');
                }
                $index = $matches[0];
            }
            if (! array_key_exists($index, $value)) {
                throw new InvalidArgumentException('Server mapping list index does not exist.');
            }
            $resolved[] = $index;
            $value = $value[$index];
        }

        return JsonKanonik::serialisasi($resolved);
    }
}
