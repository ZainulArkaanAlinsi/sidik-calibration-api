<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permintaan kalibrasi yang diajukan pelanggan dari aplikasinya.
 *
 * Nama tabelnya `permintaan_kalibrasi` (tunggal), mengikuti `penugasan` dan
 * `undangan_pelanggan` — `$table` disebut eksplisit supaya Eloquent tidak
 * mencari `permintaan_kalibrasis`.
 *
 * `Diaudit` dipasang karena tiap perubahan status di sini keputusan manusia
 * (admin menerima/menolak, pelanggan membatalkan) dan "siapa memutuskan apa,
 * kapan" harus terjawab tanpa menebak.
 */
class PermintaanKalibrasi extends Model
{
    use Diaudit;

    protected $table = 'permintaan_kalibrasi';

    public const STATUS_BARU = 'baru';

    public const STATUS_DITERIMA = 'diterima';

    public const STATUS_DITOLAK = 'ditolak';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    public const METODE_DIANTAR_SENDIRI = 'diantar_sendiri';

    public const METODE_DIAMBIL_LAB = 'diambil_lab';

    /** @return list<string> */
    public static function metodePengantaran(): array
    {
        return [self::METODE_DIANTAR_SENDIRI, self::METODE_DIAMBIL_LAB];
    }

    /**
     * Status yang masih "berjalan" di layar pelanggan (tab Aktif) — sisanya
     * riwayat. `diterima` tetap aktif: ordernya sudah lahir tapi pelanggan
     * masih menunggu alatnya dikerjakan, dan pelacakan detailnya ada di /paket.
     *
     * @return list<string>
     */
    public static function statusAktif(): array
    {
        return [self::STATUS_BARU, self::STATUS_DITERIMA];
    }

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_diinginkan_dari' => 'date',
            'tanggal_diinginkan_sampai' => 'date',
            'diputuskan_pada' => 'datetime',
            'dibatalkan_pada' => 'datetime',
            'resi_diisi_pada' => 'datetime',
            'jadwal_pada' => 'datetime',
            'alat_tiba_pada' => 'datetime',
        ];
    }

    /** Masih menunggu keputusan lab — satu-satunya keadaan yang boleh dibatalkan/diputuskan. */
    public function masihBaru(): bool
    {
        return $this->status === self::STATUS_BARU;
    }

    /** Percakapan hanya terbuka selama permintaan belum ditolak/dibatalkan. */
    public function percakapanTerbuka(): bool
    {
        return in_array($this->status, self::statusAktif(), true);
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
    public function pemohon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    /** @return BelongsTo<User, $this> */
    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<PermintaanKalibrasiItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PermintaanKalibrasiItem::class, 'permintaan_kalibrasi_id');
    }

    /** @return HasMany<PesanPermintaan, $this> */
    public function pesan(): HasMany
    {
        return $this->hasMany(PesanPermintaan::class, 'permintaan_kalibrasi_id');
    }
}
