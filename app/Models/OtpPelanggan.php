<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OTP 6 digit buat verifikasi email & atur ulang sandi pelanggan.
 *
 * ## Kenapa TANPA `Diaudit`, dan ini bukan kelupaan
 *
 * `audit_logs` menyimpan nilai LAMA dan BARU tiap kolom yang berubah. Memasang
 * `Diaudit` di sini berarti `kode_hash`, jumlah percobaan, dan waktu penguncian
 * ikut tersalin ke tabel lain tiap kali barisnya disentuh — dan REQ-PRV-02
 * melarang log server menyimpan OTP. Rahasia yang bocor ke tabel kedua itu satu
 * kebocoran jadi dua, alasan yang sama dengan `kolomRahasia()` di trait itu.
 *
 * Yang tetap bisa ditelusuri: barisnya sendiri menyimpan kapan dibuat, kapan
 * dipakai, berapa kali salah, dan sampai kapan terkunci.
 *
 * @mixin IdeHelperOtpPelanggan
 */
#[Fillable(['user_id', 'tujuan', 'kedaluwarsa_pada', 'percobaan', 'dikunci_sampai', 'dipakai_pada'])]
class OtpPelanggan extends Model
{
    use HasFactory;

    protected $table = 'otp_pelanggan';

    public const TUJUAN_VERIFIKASI_EMAIL = 'verifikasi_email';

    public const TUJUAN_ATUR_ULANG_SANDI = 'atur_ulang_sandi';

    /** REQ-AUTH-01: OTP berlaku 10 menit. */
    public const BERLAKU_MENIT = 10;

    /** REQ-AUTH-02: salah 5 kali → dikunci. */
    public const MAKS_PERCOBAAN = 5;

    /** REQ-AUTH-02: lama penguncian sesudah percobaan habis. */
    public const KUNCI_MENIT = 15;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kedaluwarsa_pada' => 'datetime',
            'dikunci_sampai' => 'datetime',
            'dipakai_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sedangDikunci(): bool
    {
        return $this->dikunci_sampai !== null && $this->dikunci_sampai->isFuture();
    }

    public function masihBerlaku(): bool
    {
        return $this->dipakai_pada === null
            && ! $this->sedangDikunci()
            && $this->kedaluwarsa_pada !== null
            && $this->kedaluwarsa_pada->isFuture();
    }
}
