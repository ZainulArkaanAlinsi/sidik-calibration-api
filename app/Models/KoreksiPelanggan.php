<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Permintaan koreksi data dari pelanggan — alat atau sertifikat (§42).
 *
 * Pelanggan tidak pernah mengubah data yang sudah tercetak sendiri (D5 §38):
 * dia MENGAJUKAN, lab yang memutuskan. Koreksi alat yang diterima mengubah
 * kolom alat; koreksi sertifikat yang diterima melahirkan revisi.
 *
 * @property array<int, array{field: string, label: string, lama: string|null, baru: string|null}> $perubahan
 */
#[Fillable([
    'organization_id', 'customer_id', 'diajukan_oleh', 'jenis', 'equipment_id', 'certificate_id',
    'perubahan', 'catatan', 'status', 'tanggapan', 'ditinjau_oleh', 'ditinjau_pada', 'certificate_revisi_id',
])]
class KoreksiPelanggan extends Model
{
    protected $table = 'koreksi_pelanggan';

    public const JENIS_ALAT = 'alat';

    public const JENIS_SERTIFIKAT = 'sertifikat';

    public const STATUS_MENUNGGU = 'menunggu';

    public const STATUS_DITERIMA = 'diterima';

    public const STATUS_DITOLAK = 'ditolak';

    /** @return list<string> */
    public static function daftarStatus(): array
    {
        return [self::STATUS_MENUNGGU, self::STATUS_DITERIMA, self::STATUS_DITOLAK];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'perubahan' => 'array',
            'ditinjau_pada' => 'datetime',
        ];
    }

    public function masihMenunggu(): bool
    {
        return $this->status === self::STATUS_MENUNGGU;
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

    /** @return BelongsTo<User, $this> */
    public function pengaju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    /** @return BelongsTo<User, $this> */
    public function peninjau(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditinjau_oleh');
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }

    /** @return BelongsTo<Certificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    /** @return BelongsTo<Certificate, $this> */
    public function revisi(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'certificate_revisi_id');
    }

    /** @return MorphMany<FotoPelanggan, $this> */
    public function foto(): MorphMany
    {
        return $this->morphMany(FotoPelanggan::class, 'pemilik')->orderBy('id');
    }
}
