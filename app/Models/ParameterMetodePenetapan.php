<?php

namespace App\Models;

use App\Services\Acuan\ParameterKeputusanMetode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Satu penetapan keputusan metode (Studio Tahap 1, 25 Nov 2026).
 *
 * **Append-only.** Isi baris (`kunci`, `nomor`, `nilai`, `berlaku_mulai`,
 * `dicatat_pada`, `catatan`) tidak boleh diubah dan barisnya tidak boleh
 * dihapus; yang boleh berubah hanya `status` beserta `keputusan_oleh` dan
 * `tanggal_keputusan` (diisi saat `disetujui`). Penjaganya di model — sama
 * polanya dengan `AuditLog` — supaya berlaku sama di SQLite dan MySQL dan tidak
 * bergantung trigger. Pelanggaran = exception, bukan diam-diam berhasil.
 *
 * Hanya status `disetujui` yang dibaca perhitungan
 * (`ParameterKeputusanMetode`). Tanggal disimpan `Y-m-d` dan SENGAJA tidak
 * di-cast: cast tanggal Eloquent menulis `Y-m-d 00:00:00`, dan di SQLite itu
 * terbanding sebagai teks yang lebih besar dari `Y-m-d` polos.
 *
 * @property int $id
 * @property string $kunci
 * @property int $nomor
 * @property string $nilai
 * @property string $berlaku_mulai Y-m-d
 * @property Carbon $dicatat_pada
 * @property string $status
 * @property ?string $keputusan_oleh
 * @property ?string $tanggal_keputusan Y-m-d
 * @property ?string $catatan
 */
#[Fillable([
    'kunci', 'nomor', 'nilai', 'berlaku_mulai', 'dicatat_pada',
    'status', 'keputusan_oleh', 'tanggal_keputusan', 'catatan',
])]
class ParameterMetodePenetapan extends Model
{
    public const STATUS_MENUNGGU_TM = 'menunggu_tm';

    public const STATUS_DISETUJUI = 'disetujui';

    public const STATUS_DITARIK = 'ditarik';

    /** Kolom yang boleh berubah sesudah baris lahir. */
    private const BOLEH_BERUBAH = ['status', 'keputusan_oleh', 'tanggal_keputusan', 'updated_at'];

    protected $table = 'parameter_metode_penetapan';

    protected function casts(): array
    {
        return [
            'nomor' => 'integer',
            'dicatat_pada' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $baris): void {
            $terlarang = array_diff(array_keys($baris->getDirty()), self::BOLEH_BERUBAH);

            if ($terlarang !== []) {
                throw new LogicException(
                    'Penetapan keputusan metode append-only: kolom ['.implode(', ', $terlarang).'] tidak boleh diubah; hanya status, keputusan_oleh, dan tanggal_keputusan.'
                );
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Penetapan keputusan metode append-only: baris tidak boleh dihapus (tarik, bukan hapus).');
        });

        // Cache pembaca perhitungan dibuang tiap penetapan berubah (dibuat atau
        // transisi status), supaya request/proses yang sama langsung melihatnya.
        static::saved(fn () => ParameterKeputusanMetode::penetapanBerubah());
    }

    /** @return HasMany<ParameterMetodePenetapanJejak, $this> */
    public function jejak(): HasMany
    {
        return $this->hasMany(ParameterMetodePenetapanJejak::class, 'penetapan_id')->orderBy('id');
    }

    /** `berlaku_mulai` sebagai Y-m-d (driver tertentu mengembalikan stempel panjang). */
    public function berlakuMulaiTeks(): string
    {
        return substr((string) $this->getRawOriginal('berlaku_mulai'), 0, 10);
    }

    public function tanggalKeputusanTeks(): ?string
    {
        $mentah = $this->getRawOriginal('tanggal_keputusan');

        return $mentah === null ? null : substr((string) $mentah, 0, 10);
    }
}
