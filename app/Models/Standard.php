<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'nama', 'merk', 'model', 'serial_number', 'nilai_konvensional',
    'suhu_referensi', 'no_sertifikat', 'tertelusur_ke', 'berlaku_sampai', 'ketidakpastian',
    'satuan_ketidakpastian', 'faktor_cakupan', 'drift',
])]
class Standard extends Model
{
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'berlaku_sampai' => 'date',
            'nilai_konvensional' => 'float',
            'suhu_referensi' => 'float',
            'ketidakpastian' => 'float',
            'faktor_cakupan' => 'float',
            'drift' => 'float',
        ];
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
