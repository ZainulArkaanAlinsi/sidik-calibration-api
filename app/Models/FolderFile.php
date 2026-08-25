<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu file di Folder Manager.
 *
 * @mixin IdeHelperFolderFile
 */
#[Fillable([
    'organization_id', 'folder_id', 'certificate_id', 'calibration_session_id',
    'uploaded_by', 'nama', 'sumber', 'path', 'mime', 'ukuran', 'keterangan',
])]
class FolderFile extends Model
{
    use Diaudit, HasFactory, SoftDeletes;

    /** Tautan ke sertifikat resmi — file-nya nggak disalin ke sini. */
    public const SUMBER_SERTIFIKAT = 'sertifikat';

    public const SUMBER_UNGGAHAN = 'unggahan';

    /**
     * Tautan ke lembar kerja teknisi — `path` NULL, karena yang ditaut itu
     * record-nya, bukan berkas hasil render.
     *
     * Lembar kerja bisa direvisi. Kalau tiap kirim dirender jadi berkas, satu
     * sesi yang ditolak lalu dikirim ulang ninggalin beberapa berkas yang
     * isinya beda dan nggak ada yang tau mana yang berlaku. Nunjuk ke record
     * bikin isinya selalu ikut keadaan terakhir.
     */
    public const SUMBER_LEMBAR_KERJA = 'lembar_kerja';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ukuran' => 'integer'];
    }

    /** @return BelongsTo<Folder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /** @return BelongsTo<Certificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    /** @return BelongsTo<CalibrationSession, $this> */
    public function calibrationSession(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
