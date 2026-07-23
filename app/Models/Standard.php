<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperStandard
 */
#[Fillable([
    'organization_id', 'nama', 'merk', 'model', 'serial_number', 'no_sertifikat',
    'tertelusur_ke', 'berlaku_sampai', 'ketidakpastian', 'satuan_ketidakpastian',
    'faktor_cakupan', 'drift', 'koefisien_suhu', 'parameter_kondisi',
])]
class Standard extends Model
{
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'berlaku_sampai' => 'date',
            'ketidakpastian' => 'float',
            'faktor_cakupan' => 'float',
            'drift' => 'float',
            'koefisien_suhu' => 'array',
            'parameter_kondisi' => 'array',
        ];
    }

    /** Standar yang sertifikatnya kadaluarsa nggak boleh dipakai kalibrasi. */
    public function masihBerlaku(): bool
    {
        return $this->berlaku_sampai === null || $this->berlaku_sampai->isFuture();
    }

    /**
     * Nilai standar pada suhu larutan tertentu, dari persamaan di sertifikat
     * buffer: y = a·x² + b·x + c.
     *
     * Contoh nyata (buffer pH 4, sertifikat Merck): a = 3e-5, b = -0,0023,
     * c = 4,0455. Di suhu 22,2 °C hasilnya 4,0092252 — persis angka yang
     * dipakai lembar perhitungan lab.
     *
     * null kalau standarnya nggak punya persamaan (mis. gauge block, yang
     * nilainya nggak bergantung suhu larutan). Yang manggil harus siap balik ke
     * nilai nominal titiknya.
     */
    public function nilaiPadaSuhu(?float $suhu): ?float
    {
        $k = $this->koefisien_suhu;

        if ($suhu === null || ! is_array($k) || ! isset($k['a'], $k['b'], $k['c'])) {
            return null;
        }

        return (float) $k['a'] * $suhu ** 2 + (float) $k['b'] * $suhu + (float) $k['c'];
    }

    /**
     * Data sertifikat thermohygro buat satu parameter (`suhu`/`kelembaban`):
     * nilai terindeks, koreksi, dan U95%-nya.
     *
     * @return array{indexed_value: float|null, correction: float|null, u95: float|null}|null
     */
    public function parameterKondisi(string $parameter): ?array
    {
        $data = $this->parameter_kondisi[$parameter] ?? null;

        if (! is_array($data)) {
            return null;
        }

        return [
            'indexed_value' => isset($data['indexed_value']) ? (float) $data['indexed_value'] : null,
            'correction' => isset($data['correction']) ? (float) $data['correction'] : null,
            'u95' => isset($data['u95']) ? (float) $data['u95'] : null,
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
