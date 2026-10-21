<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Log metode versi data acuan — satu baris per transisi (AGENTS.md §Olah data
 * butir 1 "log metode append-only"; 02-SRS FR-28).
 *
 * **Append-only.** Update & delete ditolak di model, pola
 * `PaketAcuanSuntingan`. Baris ini yang menjawab "versi ini lahir dari master
 * mana (id + sha256), siapa mengajukan, siapa mengesahkan, kapan, kenapa, dan
 * apa yang dia akui" tanpa membuka kode.
 */
#[Fillable([
    'organization_id', 'paket_acuan_versi_id', 'aksi', 'status_dari', 'status_ke',
    'isi_sha256', 'user_id', 'alasan', 'sumber', 'rincian',
])]
class PaketAcuanLog extends Model
{
    protected $table = 'paket_acuan_log';

    public const UPDATED_AT = null;

    public const AKSI_DIBUAT = 'dibuat';

    public const AKSI_AJUKAN = 'ajukan';

    public const AKSI_SIMULASI = 'simulasi';

    public const AKSI_SAHKAN = 'sahkan';

    public const AKSI_PENSIUN = 'pensiun';

    public const AKSI_TOLAK = 'tolak';

    public const AKSI_BUANG = 'buang';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sumber' => 'array',
            'rincian' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Log versi data acuan tidak boleh diubah; catat baris baru.');
        });

        static::deleting(function (): never {
            throw new LogicException('Log versi data acuan tidak boleh dihapus (jejak audit ISO/IEC 17025 7.5).');
        });
    }

    /** @return BelongsTo<PaketAcuanVersi, $this> */
    public function paketAcuanVersi(): BelongsTo
    {
        return $this->belongsTo(PaketAcuanVersi::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
