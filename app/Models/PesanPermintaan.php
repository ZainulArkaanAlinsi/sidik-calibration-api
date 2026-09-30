<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu pesan di utas permintaan kalibrasi. Tidak pernah diedit atau dihapus. */
class PesanPermintaan extends Model
{
    protected $table = 'pesan_permintaan';

    public const SISI_PELANGGAN = 'pelanggan';

    public const SISI_LAB = 'lab';

    protected $guarded = ['id'];

    /** @return BelongsTo<PermintaanKalibrasi, $this> */
    public function permintaan(): BelongsTo
    {
        return $this->belongsTo(PermintaanKalibrasi::class, 'permintaan_kalibrasi_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pengirim(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pengirim_id');
    }
}
