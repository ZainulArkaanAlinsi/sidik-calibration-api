<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kejadian pengiriman sertifikat lewat email (fase-2 §3d).
 *
 * Tulis-sekali, sama alasannya kayak `AuditLog`: catatan pengiriman yang bisa
 * diubah berhenti jadi bukti "kita udah kirim ke alamat ini, tanggal ini".
 *
 * @mixin IdeHelperCertificateEmailLog
 */
#[Fillable([
    'organization_id', 'certificate_id', 'ke', 'cc', 'status', 'error', 'dikirim_oleh',
])]
class CertificateEmailLog extends Model
{
    public const STATUS_TERKIRIM = 'terkirim';

    public const STATUS_GAGAL = 'gagal';

    /** Nggak ada `updated_at` — barisnya nggak pernah diubah. */
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ke' => 'array',
            'cc' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException(
                'Catatan pengiriman nggak boleh diubah — kalau bisa, dia berhenti jadi bukti '
                .'bahwa sertifikat pernah dikirim ke alamat itu.',
            );
        });
    }

    /** @return BelongsTo<Certificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function pengirim(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikirim_oleh');
    }
}
