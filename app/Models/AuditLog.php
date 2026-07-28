<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris jejak perubahan data (Keputusan 4).
 *
 * **Baris audit itu tulis-sekali.** Update & delete ditolak di level model, bukan
 * cuma "nggak disediain endpoint-nya": riwayat yang bisa diubah berhenti jadi
 * bukti, dan yang paling mungkin ngubahnya justru orang yang mau nutupin sesuatu.
 * Kalau ada kode yang nyoba, dia dapat exception — bukan diam-diam berhasil.
 *
 * @mixin IdeHelperAuditLog
 */
#[Fillable([
    'organization_id', 'entity_type', 'entity_id', 'action',
    'old_data', 'new_data', 'changed_by', 'note',
])]
class AuditLog extends Model
{
    public const ACTION_DIBIKIN = 'dibikin';

    public const ACTION_DIUBAH = 'diubah';

    public const ACTION_DIHAPUS = 'dihapus';

    public const ACTION_DIPULIHKAN = 'dipulihkan';

    /** @return list<string> */
    public static function actions(): array
    {
        return [
            self::ACTION_DIBIKIN,
            self::ACTION_DIUBAH,
            self::ACTION_DIHAPUS,
            self::ACTION_DIPULIHKAN,
        ];
    }

    /** Nggak ada `updated_at` — barisnya nggak pernah diubah. */
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_data' => 'array',
            'new_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException(
                'Baris audit nggak boleh diubah. Riwayat yang bisa diedit berhenti jadi bukti.',
            );
        });

        static::deleting(function (): never {
            throw new \RuntimeException(
                'Baris audit nggak boleh dihapus. Kalau perlu dibatasi umurnya, itu urusan '
                .'kebijakan retensi terjadwal, bukan tombol hapus.',
            );
        });
    }

    /** @return BelongsTo<User, $this> */
    public function pelaku(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
