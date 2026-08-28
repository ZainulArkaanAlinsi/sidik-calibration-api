<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kali lembar kerja dibaca lewat jalur generik (tanpa template).
 *
 * ## Kenapa NGGAK pakai `Diaudit`
 *
 * Sama alasannya kayak `WorksheetScan`, dan itu keputusan sadar: baris di sini
 * bukan data terakreditasi, melainkan USULAN. Yang dipertanggungjawabkan ke
 * asesor itu `raw_measurements` lewat `CalibrationSession` — dan itu yang
 * diaudit.
 *
 * Yang tetap harus bisa dijawab — siapa yang mengoreksi satu nilai dan kapan —
 * dijawab kolom `dikoreksi_oleh`/`dikoreksi_pada` di [DokumenBacaanNilai].
 * Itu lebih tepat daripada `Diaudit` di sini: koreksinya BUKAN perubahan
 * sampingan yang kebetulan perlu dicatat, dia inti dari barisnya.
 *
 * @mixin IdeHelperDokumenBacaan
 */
#[Fillable([
    'organization_id', 'user_id', 'calibration_session_id',
    // Yang DIPILIH teknisi, dipisah dari yang TERBACA di kertas — bedanya
    // yang menunjukkan petunjuknya bikin salah atau nggak.
    'nama_alat_konteks',
    'judul', 'nama_alat', 'kode_dokumen', 'revisi', 'pola',
    'keyakinan', 'status', 'pesan',
    'jumlah_field', 'jumlah_sel', 'perlu_review',
    'peringatan', 'skema', 'model', 'usage', 'citra_path',
])]
class DokumenBacaan extends Model
{
    use HasFactory;

    protected $table = 'dokumen_bacaan';

    public const STATUS_OK = 'ok';

    public const STATUS_PERLU_REVIEW = 'perlu_review';

    public const STATUS_GAGAL = 'gagal';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'keyakinan' => 'float',
            'jumlah_field' => 'integer',
            'jumlah_sel' => 'integer',
            'perlu_review' => 'integer',
            'peringatan' => 'array',
            'skema' => 'array',
            'usage' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CalibrationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class, 'calibration_session_id');
    }

    /** @return HasMany<DokumenBacaanNilai, $this> */
    public function nilai(): HasMany
    {
        return $this->hasMany(DokumenBacaanNilai::class);
    }

    /**
     * Boleh dipakai buat ngisi lembar kerja atau nggak.
     *
     * Pembacaan yang GAGAL tetap disimpan (bahan nyetel ambang & bahan tahu
     * lembar mana yang belum kebaca), jadi keberadaan barisnya bukan tanda
     * hasilnya kepakai — statusnya yang nentuin.
     */
    public function bisaDipakai(): bool
    {
        return in_array($this->status, [self::STATUS_OK, self::STATUS_PERLU_REVIEW], true);
    }
}
