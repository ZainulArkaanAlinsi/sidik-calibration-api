<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris jejak perubahan data (Keputusan 4).
 *
 * **Baris audit itu tulis-sekali.** Update & delete ditolak di level model, bukan
 * cuma "nggak disediain endpoint-nya": riwayat yang bisa diubah berhenti jadi
 * bukti, dan yang paling mungkin ngubahnya justru orang yang mau nutupin sesuatu.
 * Kalau ada kode yang nyoba, dia dapat exception — bukan diam-diam berhasil.
 *
 * @mixin IdeHelperAuditLog
 */
#[Fillable([
    'organization_id', 'entity_type', 'entity_id', 'action',
    'old_data', 'new_data', 'changed_by', 'note',
])]
class AuditLog extends Model
{
    public const ACTION_DIBIKIN = 'dibikin';

    public const ACTION_DIUBAH = 'diubah';

    public const ACTION_DIHAPUS = 'dihapus';

    public const ACTION_DIPULIHKAN = 'dipulihkan';

    /**
     * Membaca — satu-satunya aksi di sini yang TIDAK mengubah apa pun.
     *
     * Dipakai `App\Support\JejakLintasOrganisasi` buat akses super admin yang
     * menembus `organization_id`. Pembacaan biasa nggak dicatat dan jangan
     * dibikin dicatat: yang bikin baris ini layak disimpan justru karena dia
     * melewati batas kerahasiaan antar pelanggan (ISO/IEC 17025 klausul 4.2),
     * bukan karena ada orang membuka layar.
     */
    public const ACTION_DIBACA = 'dibaca';

    /**
     * Percobaan membuka baris milik lab lain — ditolak 404.
     *
     * Bukan perubahan data: `new_data` memuat konteks request (method, path,
     * role, ip). Dicatat di riwayat lab PEMANGGIL, bukan lab pemilik data —
     * lihat `PenjagaOrganisasi::catat()`. 16 karakter: kolom `action` itu
     * `string(20)`, dan `akses_lintas_organisasi` (23) terpotong diam-diam di
     * MySQL sampai filter riwayat berhenti cocok.
     */
    public const ACTION_AKSES_LINTAS_LAB = 'akses_lintas_lab';

    /**
     * PDF sertifikat diunduh pemiliknya dari aplikasi pelanggan. `new_data`
     * memuat `customer_id`; `changed_by` = akun pelanggan yang mengunduh.
     * Menjawab "sertifikatnya sudah sampai belum?" tanpa menyisir log email.
     * 17 karakter — muat di `string(20)`.
     */
    public const ACTION_DIUNDUH_PELANGGAN = 'diunduh_pelanggan';

    /** @return list<string> */
    public static function actions(): array
    {
        return [
            self::ACTION_DIBIKIN,
            self::ACTION_DIUBAH,
            self::ACTION_DIHAPUS,
            self::ACTION_DIPULIHKAN,
        ];
    }

    /** Nggak ada `updated_at` — barisnya nggak pernah diubah. */
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_data' => 'array',
            'new_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException(
                'Baris audit nggak boleh diubah. Riwayat yang bisa diedit berhenti jadi bukti.',
            );
        });

        static::deleting(function (): never {
            throw new \RuntimeException(
                'Baris audit nggak boleh dihapus. Kalau perlu dibatasi umurnya, itu urusan '
                .'kebijakan retensi terjadwal, bukan tombol hapus.',
            );
        });
    }

    /** @return BelongsTo<User, $this> */
    public function pelaku(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
