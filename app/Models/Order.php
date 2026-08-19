<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Order kalibrasi — satu pengiriman alat dari pelanggan. */
#[Fillable([
    'organization_id', 'customer_id', 'diterima_oleh', 'nomor', 'tanggal_masuk',
    'tanggal_janji_selesai', 'status', 'catatan',
])]
class Order extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_BARU = 'baru';

    public const STATUS_DIPROSES = 'diproses';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    /** @return array<int, string> */
    public static function statuses(): array
    {
        return [self::STATUS_BARU, self::STATUS_DIPROSES, self::STATUS_SELESAI, self::STATUS_DIBATALKAN];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_masuk' => 'date',
            'tanggal_janji_selesai' => 'date',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Petugas meja depan yang nerima alatnya. */
    public function penerima(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diterima_oleh');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
