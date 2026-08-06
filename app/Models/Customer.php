<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperCustomer
 */
#[Fillable(['organization_id', 'nama', 'alamat', 'contact_person', 'telepon', 'email'])]
class Customer extends Model
{
    use Diaudit, HasFactory, SoftDeletes;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Equipment, $this> */
    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /**
     * Semua sesi kalibrasi punya PT ini, lewat alat-alatnya.
     *
     * Ada supaya jumlah sertifikat per PT bisa dihitung sekali jalan
     * (`withCount`) di daftar arsip. Tanpa ini tiap baris PT butuh query
     * sendiri, dan yang kelihatan di layar cuma angka nol.
     *
     * @return HasManyThrough<CalibrationSession, Equipment, $this>
     */
    public function calibrationSessions(): HasManyThrough
    {
        return $this->hasManyThrough(CalibrationSession::class, Equipment::class);
    }
}
