<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu teknisi di dalam satu penugasan.
 *
 * Pivot dengan isi: `peran` dan `dilihat_pada`. Alasannya di docblock
 * migrasinya — ringkasnya, grup tanpa ketua jadi pekerjaan yang semua orang
 * anggap sedang dikerjakan orang lain, dan "notifikasi terkirim" bukan jawaban
 * untuk "dia udah tau belum?".
 */
class PenugasanTeknisi extends Model
{
    use HasFactory;

    protected $table = 'penugasan_teknisi';

    public const PERAN_KETUA = 'ketua';

    public const PERAN_ANGGOTA = 'anggota';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['dilihat_pada' => 'datetime'];
    }

    public function penugasan(): BelongsTo
    {
        return $this->belongsTo(Penugasan::class);
    }

    public function teknisi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
