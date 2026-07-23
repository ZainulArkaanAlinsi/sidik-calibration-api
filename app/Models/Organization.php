<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperOrganization
 */
#[Fillable([
    'nama', 'alamat', 'telepon', 'email', 'no_akreditasi', 'standar_akreditasi',
    'akreditasi_mulai', 'akreditasi_berakhir', 'logo_path', 'settings',
])]
class Organization extends Model
{
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'akreditasi_mulai' => 'date',
            'akreditasi_berakhir' => 'date',
        ];
    }

    /**
     * Lab yang akreditasinya kadaluarsa nggak boleh nerbitin sertifikat
     * terakreditasi. Dipakai waktu generate sertifikat (Minggu 08).
     */
    public function akreditasiMasihBerlaku(): bool
    {
        return $this->akreditasi_berakhir === null || $this->akreditasi_berakhir->isFuture();
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** @return HasMany<Equipment, $this> */
    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }
}
