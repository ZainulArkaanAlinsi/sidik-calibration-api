<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jejak audit: sertifikat ini pernah dikirim ke siapa, oleh siapa, kapan. */
#[Fillable(['certificate_id', 'dikirim_oleh', 'penerima', 'cc', 'dikirim_pada'])]
class CertificateEmail extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'penerima' => 'array',
            'cc' => 'array',
            'dikirim_pada' => 'datetime',
        ];
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
