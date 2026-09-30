<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Satu alat dalam permintaan kalibrasi — alat terdaftar ATAU alat baru (JSON). */
class PermintaanKalibrasiItem extends Model
{
    protected $table = 'permintaan_kalibrasi_item';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['alat_baru' => 'array'];
    }

    /** Alat yang diketik pelanggan di formulir dan belum jadi baris `equipments`. */
    public function alatBaru(): bool
    {
        return $this->alat_baru !== null;
    }

    /** @return BelongsTo<PermintaanKalibrasi, $this> */
    public function permintaan(): BelongsTo
    {
        return $this->belongsTo(PermintaanKalibrasi::class, 'permintaan_kalibrasi_id');
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * Foto pelat nama alat BARU (PL_Form_Alat). Waktu admin menerima
     * permintaannya, foto yang sama ikut terlihat dari alat yang lahir —
     * lihat `Equipment::fotoPelat()`.
     *
     * @return MorphMany<FotoPelanggan, $this>
     */
    public function foto(): MorphMany
    {
        return $this->morphMany(FotoPelanggan::class, 'pemilik')->orderBy('id');
    }
}
