<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Undangan berkode buat pelanggan lama (REQ-AUTH-06).
 *
 * `kode_hash` NGGAK ada di `$fillable`, dan itu disengaja: kodenya cuma boleh
 * disetel lewat jalur yang sekaligus menghasilkannya, supaya nggak ada
 * controller yang bisa kebetulan menyimpan kode mentah dari request.
 *
 * @mixin IdeHelperUndanganPelanggan
 */
#[Fillable([
    'organization_id', 'customer_id', 'email', 'peran', 'dibuat_oleh',
    'kedaluwarsa_pada', 'dipakai_pada', 'dipakai_oleh', 'dibatalkan_pada',
])]
class UndanganPelanggan extends Model
{
    use Diaudit, HasFactory;

    protected $table = 'undangan_pelanggan';

    /** Masa berlaku undangan, sesuai REQ-AUTH-06. */
    public const BERLAKU_HARI = 7;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kedaluwarsa_pada' => 'datetime',
            'dipakai_pada' => 'datetime',
            'dibatalkan_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Masih bisa ditukar?
     *
     * Tiga syaratnya diperiksa bareng di satu tempat supaya nggak ada pemanggil
     * yang cuma mengecek salah satunya — undangan yang sudah dibatalkan tapi
     * belum kedaluwarsa itu kasus yang paling gampang kelewat.
     */
    public function masihBisaDipakai(): bool
    {
        return $this->dipakai_pada === null
            && $this->dibatalkan_pada === null
            && $this->kedaluwarsa_pada !== null
            && $this->kedaluwarsa_pada->isFuture();
    }
}
