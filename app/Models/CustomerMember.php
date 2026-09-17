<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keanggotaan satu orang di satu perusahaan pelanggan.
 *
 * Pakai `Diaudit` karena tiap barisnya hasil keputusan MANUSIA — admin
 * menyetujui pengajuan, PIC utama mengundang atau menonaktifkan staf. Dan itu
 * keputusan yang menentukan siapa boleh melihat data perusahaan mana, jadi
 * "siapa mengubahnya, kapan" harus bisa dijawab tanpa menebak (REQ-ADM-06).
 *
 * @mixin IdeHelperCustomerMember
 */
#[Fillable([
    'organization_id', 'customer_id', 'user_id', 'peran', 'status',
    'diundang_oleh', 'dinonaktifkan_oleh', 'dinonaktifkan_pada',
])]
class CustomerMember extends Model
{
    use Diaudit, HasFactory;

    public const PERAN_PIC_UTAMA = 'pic_utama';

    public const PERAN_STAF = 'staf';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_NONAKTIF = 'nonaktif';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['dinonaktifkan_pada' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeAktif(Builder $query): void
    {
        $query->where('status', self::STATUS_AKTIF);
    }

    public function adalahPicUtama(): bool
    {
        return $this->peran === self::PERAN_PIC_UTAMA;
    }
}
