<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan bahwa seseorang menyetujui satu VERSI dokumen (REQ-PRV-01).
 *
 * Sengaja TANPA `Diaudit`: barisnya sendiri sudah jejak audit, dan
 * mengauditnya berarti menyimpan hal yang sama dua kali. Dokumen naik versi =
 * baris baru, bukan baris lama yang diperbarui.
 *
 * @mixin IdeHelperPersetujuanDokumen
 */
#[Fillable(['user_id', 'jenis', 'versi', 'disetujui_pada', 'ip'])]
class PersetujuanDokumen extends Model
{
    use HasFactory;

    protected $table = 'persetujuan_dokumen';

    public const JENIS_KEBIJAKAN_PRIVASI = 'kebijakan_privasi';

    public const JENIS_SYARAT_KETENTUAN = 'syarat_ketentuan';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['disetujui_pada' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
