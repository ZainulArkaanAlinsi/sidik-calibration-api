<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
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
    use Diaudit, HasFactory, SoftDeletes;

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
            'resolusi_rentang' => 'array',
            'toleransi' => 'float',
        ];
    }

    /**
     * Resolusi alat DI TITIK [$nilai].
     *
     * Sebagian alat resolusinya berubah menurut rentang — Turbidimeter PT Sidik
     * 0,01 di bawah 10 NTU, 0,1 di 10–100, dan 1 di atas itu. Selama cuma ada
     * satu kolom `resolusi`, titik 100 NTU kecetak `101,00`: dua digit yang
     * alatnya nggak bisa tampilkan. Lihat migrasi
     * `2026_08_04_120000_tambah_resolusi_rentang_ke_equipments`.
     *
     * `maks` bersifat EKSKLUSIF, jadi 100 NTU jatuh ke golongan di atasnya —
     * itu yang bikin hasilnya cocok sama Excel master lab (`100` / `101`,
     * bukan `100,0` / `101,0`).
     *
     * Balik ke [resolusi] biasa kalau `resolusi_rentang` kosong. Alat
     * ber-resolusi seragam (pH meter) karena itu nggak berubah perilakunya.
     */
    public function resolusiPada(?float $nilai): ?float
    {
        $rentang = $this->resolusi_rentang;

        if ($nilai === null || ! is_array($rentang) || $rentang === []) {
            return $this->resolusi !== null ? (float) $this->resolusi : null;
        }

        foreach ($rentang as $band) {
            if (! is_array($band) || ! isset($band['resolusi'])) {
                continue;
            }
            $maks = $band['maks'] ?? null;

            // `maks: null` = golongan terakhir, nampung sisanya.
            if ($maks === null || abs($nilai) < (float) $maks) {
                return (float) $band['resolusi'];
            }
        }

        return $this->resolusi !== null ? (float) $this->resolusi : null;
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
