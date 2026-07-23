<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperEquipment
 */
#[Fillable([
    'organization_id', 'customer_id', 'equipment_category_id', 'nama_alat', 'nama_alat_kemampuan',
    'merk', 'model', 'serial_number', 'no_identifikasi', 'range_min', 'range_max', 'satuan', 'resolusi',
    'toleransi', 'lokasi', 'tanggal_kalibrasi_terakhir', 'tanggal_jatuh_tempo', 'status', 'catatan',
])]
class Equipment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Inflector Laravel nganggep "equipment" itu uncountable, jadi dia bakal nyari
     * tabel `equipment` (tanpa s). Nama tabelnya dipaksa di sini.
     */
    protected $table = 'equipments';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_NONAKTIF = 'nonaktif';

    /** Cuma dipakai di API, nggak pernah disimpen — turunan dari tanggal_jatuh_tempo. */
    public const STATUS_OVERDUE = 'overdue';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_kalibrasi_terakhir' => 'date',
            'tanggal_jatuh_tempo' => 'date',
            'range_min' => 'float',
            'range_max' => 'float',
            'resolusi' => 'float',
            'toleransi' => 'float',
        ];
    }

    /**
     * Status yang dilihat mobile: `overdue` menang di atas `aktif` kalau alatnya
     * udah lewat jatuh tempo.
     */
    public function statusUntukApi(): string
    {
        if ($this->status === self::STATUS_NONAKTIF) {
            return self::STATUS_NONAKTIF;
        }

        return $this->isOverdue() ? self::STATUS_OVERDUE : self::STATUS_AKTIF;
    }

    public function isOverdue(): bool
    {
        return $this->tanggal_jatuh_tempo !== null
            && $this->tanggal_jatuh_tempo->isPast()
            && $this->status === self::STATUS_AKTIF;
    }

    /** @param  Builder<Equipment>  $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', self::STATUS_AKTIF)
            ->whereNotNull('tanggal_jatuh_tempo')
            ->whereDate('tanggal_jatuh_tempo', '<', now());
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

    /** @return BelongsTo<EquipmentCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'equipment_category_id');
    }

    /** @return HasMany<CalibrationSession, $this> */
    public function calibrationSessions(): HasMany
    {
        return $this->hasMany(CalibrationSession::class);
    }
}
