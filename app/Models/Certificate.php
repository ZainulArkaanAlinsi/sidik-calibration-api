<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperCertificate
 */
#[Fillable([
    'organization_id', 'calibration_session_id', 'issued_by', 'revision_of', 'nomor', 'qr_token',
    'qr_payload', 'snapshot', 'validasi', 'pdf_path', 'xlsx_path', 'diterbitkan_pada',
    'berlaku_sampai', 'status', 'alasan_revisi',
])]
class Certificate extends Model
{
    use Diaudit, HasFactory;

    public const STATUS_MENUNGGU_GENERATE = 'menunggu_generate';

    public const STATUS_TERBIT = 'terbit';

    public const STATUS_GAGAL = 'gagal';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'diterbitkan_pada' => 'date',
            'berlaku_sampai' => 'date',
            'snapshot' => 'array',
            'validasi' => 'array',
        ];
    }

    /**
     * Nama file yang aman dipakai di disk & header unduhan. `nomor` ada
     * slash-nya (`CAL/2026/07/0001`) — kalau dipakai apa adanya, itu bikin
     * subdirektori, bukan nama file.
     */
    public function namaFile(string $ekstensi): string
    {
        $nomor = str_replace(['/', '\\'], '-', (string) ($this->nomor ?? $this->qr_token));

        return "Sertifikat-{$nomor}.{$ekstensi}";
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
