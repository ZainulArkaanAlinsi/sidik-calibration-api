<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'nama', 'merk', 'model', 'serial_number', 'koef_suhu_a',
    'koef_suhu_b', 'koef_suhu_c', 'no_sertifikat', 'tertelusur_ke', 'berlaku_sampai',
    'ketidakpastian', 'satuan_ketidakpastian', 'faktor_cakupan', 'drift',
])]
class Standard extends Model
{
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'berlaku_sampai' => 'date',
            'koef_suhu_a' => 'float',
            'koef_suhu_b' => 'float',
            'koef_suhu_c' => 'float',
            'ketidakpastian' => 'float',
            'faktor_cakupan' => 'float',
            'drift' => 'float',
        ];
    }

    /** Standar ini nilainya berubah ikut suhu (mis. buffer pH). */
    public function sensitifSuhu(): bool
    {
        return $this->koef_suhu_a !== null
            && $this->koef_suhu_b !== null
            && $this->koef_suhu_c !== null;
    }

    /**
     * Nilai standar di suhu tertentu, dari kurva sertifikatnya.
     *
     *     y = a·x² + b·x + c
     *
     * Rumus & koefisiennya diambil apa adanya dari workbook
     * `Master Olah Data_pH for trial.xlsm` sheet `Nilai koefisien Sensitifitas`
     * — ini MINDAHIN hitungan yang tadinya manual di Excel, bukan bikin rumus
     * baru. Hasilnya udah dicocokin ke sertifikat 012-CAL-524 sampai digit
     * terakhir (lihat StandardSuhuTest).
     *
     * `$suhu` itu suhu LARUTAN waktu pembacaan, bukan suhu ruang — dua-duanya
     * beda (ruang 21,4 °C vs larutan 22,2 °C di sesi itu), dan yang nentuin
     * nilai standar cuma yang pertama.
     *
     * null kalau standarnya nggak sensitif suhu — pemanggil yang mutusin mau
     * pakai nilai nominal atau nolak.
     */
    public function nilaiPadaSuhu(float $suhu): ?float
    {
        if (! $this->sensitifSuhu()) {
            return null;
        }

        return $this->koef_suhu_a * ($suhu ** 2)
            + $this->koef_suhu_b * $suhu
            + $this->koef_suhu_c;
    }

    /** Standar yang sertifikatnya kadaluarsa nggak boleh dipakai kalibrasi. */
    public function masihBerlaku(): bool
    {
        return $this->berlaku_sampai === null || $this->berlaku_sampai->isFuture();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
