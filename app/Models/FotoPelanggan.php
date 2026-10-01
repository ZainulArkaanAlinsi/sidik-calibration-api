<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Foto pelat nama dari aplikasi pelanggan. Pemiliknya alat, item permintaan
 * (alat baru), atau koreksi. Berkasnya di disk `arsip` (privat) — tidak
 * pernah punya URL publik; dibuka lewat rute yang memeriksa kepemilikan.
 */
#[Fillable([
    'organization_id', 'customer_id', 'pemilik_type', 'pemilik_id', 'path', 'mime', 'ukuran', 'diunggah_oleh',
])]
class FotoPelanggan extends Model
{
    protected $table = 'foto_pelanggan';

    /** Batas per pemilik — PL_Form_Alat "2 dari 3". */
    public const BATAS_PER_PEMILIK = 3;

    /** Kilobyte, untuk aturan validasi `max:`. */
    public const UKURAN_MAKS_KB = 5120;

    /** @return MorphTo<Model, $this> */
    public function pemilik(): MorphTo
    {
        return $this->morphTo();
    }
}
