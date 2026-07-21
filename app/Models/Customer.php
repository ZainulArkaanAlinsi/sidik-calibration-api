<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'nama', 'alamat', 'contact_person', 'telepon', 'email'])]
class Customer extends Model
{
    use HasFactory, SoftDeletes;

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
     * Semua sesi kalibrasi dari alat-alat milik pelanggan ini — dipakai buat
     * ngitung isi "folder" pelanggan di Arsip (jumlah sesi & sertifikat) tanpa
     * query bertingkat per alat. Alat yang di-soft-delete otomatis nggak keitung.
     *
     * @return HasManyThrough<CalibrationSession, Equipment, $this>
     */
    public function calibrationSessions(): HasManyThrough
    {
        return $this->hasManyThrough(CalibrationSession::class, Equipment::class);
    }
}
