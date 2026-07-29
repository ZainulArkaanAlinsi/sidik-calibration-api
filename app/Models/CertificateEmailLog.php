<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kejadian pengiriman sertifikat lewat email (fase-2 §3d).
 *
 * Tulis-sekali, sama alasannya kayak `AuditLog`: catatan pengiriman yang bisa
 * diubah berhenti jadi bukti "kita udah kirim ke alamat ini, tanggal ini".
 *
 * @mixin IdeHelperCertificateEmailLog
 */
#[Fillable([
    'organization_id', 'certificate_id', 'ke', 'cc', 'format', 'status', 'error', 'dikirim_oleh',
])]
class CertificateEmailLog extends Model
{
    public const STATUS_TERKIRIM = 'terkirim';

    public const STATUS_GAGAL = 'gagal';

    /** Dokumen resmi, dilampirkan. */
    public const FORMAT_PDF = 'pdf';

    /** Lembar kerja Excel, dilampirkan — buat pelanggan yang ngolah datanya lagi. */
    public const FORMAT_XLSX = 'xlsx';

    /**
     * Tautan verifikasi doang, TANPA lampiran. Buat penerima yang kotak masuknya
     * nolak lampiran, atau yang cuma perlu mastiin sertifikatnya asli.
     */
    public const FORMAT_TAUTAN = 'tautan';

    /**
     * Dikirim lewat WhatsApp dari HP admin, bukan dari server.
     *
     * Isinya selalu tautan verifikasi — WhatsApp nggak bisa dititipin lampiran
     * tanpa Business API, dan nempelin PDF ke pesan pribadi juga bikin dokumen
     * resmi nyebar tanpa jejak. Tautannya sendiri udah cukup: halaman verifikasi
     * nampilin lembar sertifikat utuh plus tombol unduh.
     */
    public const FORMAT_WHATSAPP = 'whatsapp';

    /** @return list<string> */
    public static function formatTersedia(): array
    {
        return [self::FORMAT_PDF, self::FORMAT_XLSX, self::FORMAT_TAUTAN];
    }

    /** Format yang beneran dikirim server (punya lampiran / badan email). */
    public static function formatEmail(): array
    {
        return self::formatTersedia();
    }

    /** Nggak ada `updated_at` — barisnya nggak pernah diubah. */
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ke' => 'array',
            'cc' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException(
                'Catatan pengiriman nggak boleh diubah — kalau bisa, dia berhenti jadi bukti '
                .'bahwa sertifikat pernah dikirim ke alamat itu.',
            );
        });
    }

    /** @return BelongsTo<Certificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function pengirim(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikirim_oleh');
    }
}
