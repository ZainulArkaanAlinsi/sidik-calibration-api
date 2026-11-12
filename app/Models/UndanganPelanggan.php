<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Undangan berkode buat pelanggan lama (REQ-AUTH-06).
 *
 * `kode_hash` NGGAK ada di `$fillable`, dan itu disengaja: kodenya cuma boleh
 * disetel lewat jalur yang sekaligus menghasilkannya, supaya nggak ada
 * controller yang bisa kebetulan menyimpan kode mentah dari request.
 *
 * @mixin IdeHelperUndanganPelanggan
 */
#[Fillable([
    'organization_id', 'customer_id', 'email', 'peran', 'dibuat_oleh',
    'kedaluwarsa_pada', 'dipakai_pada', 'dipakai_oleh', 'dibatalkan_pada',
])]
class UndanganPelanggan extends Model
{
    use Diaudit {
        kolomRahasia as protected kolomRahasiaDasar;
    }
    use HasFactory;

    protected $table = 'undangan_pelanggan';

    /** Masa berlaku undangan, sesuai REQ-AUTH-06. */
    public const BERLAKU_HARI = 7;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kedaluwarsa_pada' => 'datetime',
            'dipakai_pada' => 'datetime',
            'dibatalkan_pada' => 'datetime',
        ];
    }

    /**
     * `kode_hash` ikut disensor di `audit_logs`.
     *
     * Itu bcrypt dari kode undangan — kredensial berumur 7 hari yang menukar
     * keanggotaan perusahaan. `audit_logs` menyalin nilai tiap kolom yang
     * berubah, jadi tanpa ini hash-nya tersimpan di tabel kedua yang dibaca
     * jauh lebih banyak orang (admin lab, asesor, ekspor audit): satu
     * kebocoran jadi dua. Daftar dasar trait (`password`, dst.) tetap berlaku.
     * Yang tercatat tinggal penanda `[disensor]` — undangan terbit/berubah
     * tetap kelihatan, isi kredensialnya tidak.
     *
     * @return list<string>
     */
    protected function kolomRahasia(): array
    {
        return [...$this->kolomRahasiaDasar(), 'kode_hash'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Siapa yang mengundang — admin lab ATAU PIC utama perusahaan.
     *
     * Dua peran yang sangat berbeda menulis kolom yang sama, dan itu disengaja:
     * yang dijawab kolom ini "atas tanggung jawab siapa orang ini masuk", dan
     * jawabannya sama-sama mengikat entah dari lab atau dari perusahaannya.
     *
     * @return BelongsTo<User, $this>
     */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    /** @return BelongsTo<User, $this> */
    public function pemakai(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dipakai_oleh');
    }

    /**
     * Masih bisa ditukar?
     *
     * Tiga syaratnya diperiksa bareng di satu tempat supaya nggak ada pemanggil
     * yang cuma mengecek salah satunya — undangan yang sudah dibatalkan tapi
     * belum kedaluwarsa itu kasus yang paling gampang kelewat.
     */
    public function masihBisaDipakai(): bool
    {
        return $this->dipakai_pada === null
            && $this->dibatalkan_pada === null
            && $this->kedaluwarsa_pada !== null
            && $this->kedaluwarsa_pada->isFuture();
    }
}
