<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'calibration_session_id', 'issued_by', 'revision_of', 'nomor', 'qr_token',
    'qr_payload', 'pdf_path', 'diterbitkan_pada', 'berlaku_sampai', 'status', 'alasan_revisi',
])]
class Certificate extends Model
{
    use HasFactory;

    public const STATUS_MENUNGGU_GENERATE = 'menunggu_generate';

    public const STATUS_TERBIT = 'terbit';

    public const STATUS_GAGAL = 'gagal';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'diterbitkan_pada' => 'date',
            'berlaku_sampai' => 'date',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<CalibrationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class, 'calibration_session_id');
    }

    /** Sertifikat asal yang direvisi sama sertifikat ini. */
    /** @return BelongsTo<Certificate, $this> */
    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'revision_of');
    }
}
