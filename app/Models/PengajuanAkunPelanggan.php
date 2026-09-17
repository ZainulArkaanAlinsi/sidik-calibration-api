<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan akun dari perusahaan yang mendaftar sendiri (REQ-AUTH-02/04/05).
 *
 * Isinya KLAIM pemohon, bukan data lab. Nama & alamat perusahaan di sini tidak
 * pernah otomatis jadi baris `customers` — admin yang menautkannya, dan itu
 * satu-satunya yang menahan R-D02 (orang mengaku sebagai PT X lalu melihat
 * sertifikat PT X).
 *
 * @mixin IdeHelperPengajuanAkunPelanggan
 */
#[Fillable([
    'organization_id', 'user_id', 'nama_perusahaan', 'alamat_perusahaan', 'jabatan',
    'status', 'customer_id', 'diputus_oleh', 'diputus_pada', 'alasan_tolak',
])]
class PengajuanAkunPelanggan extends Model
{
    use Diaudit, HasFactory;

    protected $table = 'pengajuan_akun_pelanggan';

    public const STATUS_MENUNGGU = 'menunggu';

    public const STATUS_DISETUJUI = 'disetujui';

    public const STATUS_DITOLAK = 'ditolak';

    /** Alasan tolak minimal segini karakter (REQ-AUTH-05). */
    public const MIN_ALASAN_TOLAK = 10;

    /**
     * Pengajuan yang BOLEH muncul di antrean admin.
     *
     * Barisnya lahir waktu orangnya menekan "Daftar" (lihat
     * `AuthPelangganController::daftar()`), jadi `status = menunggu` saja tidak
     * cukup: yang emailnya belum diverifikasi belum boleh terlihat sama sekali.
     * Tanpa saringan ini, siapa pun bisa membanjiri antrean admin dengan
     * mengetik email orang lain — REQ-AUTH-02 menahan persis itu.
     *
     * Ditulis sebagai scope, bukan diketik ulang di tiap query, supaya yang
     * membangun endpoint admin (M1-05) tidak bisa lupa separuhnya.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSiapDitinjau(Builder $query): void
    {
        $query->where('status', self::STATUS_MENUNGGU)
            ->whereHas('pemohon', fn (Builder $pemohon) => $pemohon
                ->where('status', User::STATUS_PENDING_VERIFIKASI));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['diputus_pada' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function pemohon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
