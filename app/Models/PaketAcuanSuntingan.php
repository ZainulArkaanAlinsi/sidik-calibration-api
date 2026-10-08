<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Satu suntingan sel pada draf versi data acuan (03-SDD §2.3).
 *
 * **Append-only.** Update & delete ditolak di model — pola `AuditLog`. Baris
 * ini yang menjawab "siapa mengubah sel apa, dari berapa ke berapa, kenapa"
 * (ISO/IEC 17025 7.5.2: data asli dan perubahannya sama-sama disimpan).
 * Riwayat yang bisa disunting berhenti jadi bukti.
 *
 * TIDAK memakai `Diaudit`: tabel ini tidak punya `organization_id`, jadi
 * `Diaudit::catatAudit()` diam-diam tidak mencatat apa pun — memasangnya
 * cuma memberi kesan ada jejak ganda yang sebenarnya tidak ada. Barisnya
 * sendiri sudah jejak, dan organisasinya diraih lewat versi induknya.
 *
 * @mixin IdeHelperPaketAcuanSuntingan
 */
#[Fillable([
    'paket_acuan_versi_id', 'lembar', 'kunci_baris', 'kolom',
    'nilai_lama', 'nilai_baru', 'sumber', 'alasan', 'user_id',
])]
class PaketAcuanSuntingan extends Model
{
    protected $table = 'paket_acuan_suntingan';

    /** Tidak ada `updated_at` — barisnya tidak pernah diubah. */
    public const UPDATED_AT = null;

    public const SUMBER_KETIK = 'ketik';

    public const SUMBER_TEMPEL = 'tempel';

    public const SUMBER_IMPOR = 'impor';

    public const SUMBER_AMBIL_ALIH = 'ambil_alih';

    /** @return list<string> */
    public static function sumbers(): array
    {
        return [self::SUMBER_KETIK, self::SUMBER_TEMPEL, self::SUMBER_IMPOR, self::SUMBER_AMBIL_ALIH];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException(
                'Suntingan data acuan tidak boleh diubah. Koreksi dicatat sebagai suntingan baru '
                .'(nilai_lama = nilai yang salah), supaya riwayatnya tetap utuh.',
            );
        });

        static::deleting(function (): never {
            throw new LogicException(
                'Suntingan data acuan tidak boleh dihapus. Riwayat suntingan adalah jejak audit versi '
                .'(ISO/IEC 17025 7.5.2); draf yang dibuang ditandai lewat status versinya.',
            );
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
