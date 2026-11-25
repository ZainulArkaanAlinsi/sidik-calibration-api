<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Jejak audit satu transisi status penetapan keputusan metode: siapa, kapan,
 * dari status apa ke status apa, dan catatannya (mis. alasan menarik).
 *
 * Tulis-sekali seperti `AuditLog`: update dan delete ditolak di model.
 *
 * @property int $id
 * @property int $penetapan_id
 * @property ?string $dari_status
 * @property string $ke_status
 * @property ?int $oleh_user_id
 * @property ?string $oleh_email
 * @property Carbon $terjadi_pada
 * @property ?string $catatan
 */
#[Fillable([
    'penetapan_id', 'dari_status', 'ke_status', 'oleh_user_id', 'oleh_email', 'terjadi_pada', 'catatan',
])]
class ParameterMetodePenetapanJejak extends Model
{
    protected $table = 'parameter_metode_penetapan_jejak';

    protected function casts(): array
    {
        return ['terjadi_pada' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Jejak penetapan keputusan metode tulis-sekali: tidak boleh diubah.');
        });

        static::deleting(function (): void {
            throw new LogicException('Jejak penetapan keputusan metode tulis-sekali: tidak boleh dihapus.');
        });
    }

    /** @return BelongsTo<ParameterMetodePenetapan, $this> */
    public function penetapan(): BelongsTo
    {
        return $this->belongsTo(ParameterMetodePenetapan::class, 'penetapan_id');
    }
}
