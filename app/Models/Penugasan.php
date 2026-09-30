<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pembagian kerja dari super admin ke teknisi — personal atau grup.
 *
 * Nama tabelnya `penugasan` (tunggal), bukan `penugasans`. Itu disengaja dan
 * mengikuti tabel Indonesia lain di repo ini (`dokumen_bacaan`,
 * `undangan_pelanggan`, `persetujuan_dokumen`) — bahasa Indonesia tidak
 * memajemukkan dengan -s, dan `penugasans` terbaca salah oleh siapa pun yang
 * membacanya. Karena itu `$table` disebut eksplisit; tanpa itu Eloquent mencari
 * `penugasans` dan gagal dengan pesan yang membingungkan.
 */
class Penugasan extends Model
{
    use Diaudit, HasFactory, SoftDeletes;

    protected $table = 'penugasan';

    public const TIPE_PERSONAL = 'personal';

    public const TIPE_GRUP = 'grup';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_target' => 'date',
            'diselesaikan_pada' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function anggota(): HasMany
    {
        return $this->hasMany(PenugasanTeknisi::class);
    }

    public function item(): HasMany
    {
        return $this->hasMany(PenugasanItem::class);
    }

    /**
     * Ketua penugasan. Untuk personal, dia satu-satunya anggotanya.
     *
     * Dibuat sebagai relasi, bukan `$this->anggota->firstWhere(...)`, supaya bisa
     * di-eager-load — papan penugasan menampilkan ketua tiap baris, dan tanpa
     * eager load itu satu kueri per baris.
     */
    public function ketua(): HasMany
    {
        return $this->anggota()->where('peran', PenugasanTeknisi::PERAN_KETUA);
    }

    /**
     * Berapa persen tuntas, dari jumlah yang DIRENCANAKAN.
     *
     * Penyebutnya `jumlah`, bukan `jumlah_selesai` yang dilaporkan — kalau
     * teknisi melaporkan lebih banyak dari yang ditugaskan (kejadian wajar:
     * paketnya ternyata berisi 12, bukan 10), persentasenya dipotong di 100.
     * Angka 120% di layar terbaca seperti bug, dan yang menjelaskannya bukan
     * layar.
     */
    public function persenTuntas(): int
    {
        $rencana = (int) $this->item->sum('jumlah');

        if ($rencana === 0) {
            return 0;
        }

        $selesai = (int) $this->item->sum('jumlah_selesai');

        return (int) min(100, round($selesai / $rencana * 100));
    }

    public function terlambat(): bool
    {
        return $this->tanggal_target !== null
            && $this->status === self::STATUS_AKTIF
            && $this->tanggal_target->isPast();
    }
}
