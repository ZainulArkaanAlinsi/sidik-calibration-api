<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JsonException;
use LogicException;

/**
 * Satu simulasi versi data acuan (02-SRS FR-12/13, 03-SDD §2.4): laporan yang
 * dilihat pengesah, dibekukan bersama sha256 isi draf saat simulasi jalan.
 *
 * Laporan tidak boleh diubah sesudah selesai — yang disahkan harus persis yang
 * disimulasikan. Simulasi ulang = baris baru.
 */
#[Fillable([
    'paket_acuan_versi_id', 'organization_id', 'isi_sha256_saat_jalan', 'status',
    'ringkasan', 'laporan', 'dijalankan_oleh', 'mulai', 'selesai',
])]
class SimulasiAcuan extends Model
{
    protected $table = 'simulasi_acuan';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_GAGAL = 'gagal';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ringkasan' => 'array',
            'mulai' => 'datetime',
            'selesai' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Laporan simulasi data acuan tidak boleh diubah; jalankan simulasi baru.');
        });

        static::deleting(function (): never {
            throw new LogicException('Laporan simulasi data acuan tidak boleh dihapus.');
        });
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function laporanTerurai(): array
    {
        return (array) json_decode((string) $this->laporan, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return BelongsTo<PaketAcuanVersi, $this> */
    public function paketAcuanVersi(): BelongsTo
    {
        return $this->belongsTo(PaketAcuanVersi::class);
    }
}
