<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @mixin IdeHelperUser
 */
#[Fillable([
    'organization_id', 'employee_id', 'kode_teknisi', 'name', 'department',
    'email', 'role', 'status', 'password',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_TEKNISI = 'teknisi';

    public const ROLE_VIEWER = 'viewer';

    /** Akun baru hasil daftar mandiri. Belum boleh login sampai admin approve. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_NONAKTIF = 'nonaktif';

    /** @return array<int, string> */
    public static function roles(): array
    {
        return [self::ROLE_ADMIN, self::ROLE_TEKNISI, self::ROLE_VIEWER];
    }

    /**
     * Pengguna yang lagi login, sudah pasti bertipe `User`.
     *
     * Dipakai menggantikan pemanggilan `user()` langsung dari helper `auth()`
     * di seluruh panel admin. Alasannya bukan gaya-gayaan: helper itu
     * dideklarasiin balikin `AuthFactory|Guard`, dan `AuthFactory` NGGAK punya
     * method `user()` — jadi analisa statis (intelephense/PHPStan) nandainnya
     * sebagai method nggak dikenal. Perkaranya bukan cuma garis merah di
     * editor: karena tipenya ngambang, salah ketik nama kolom
     * (`->organisation_id`) juga nggak ketahuan sampai jalan di browser.
     *
     * Lewat `auth()->guard()` tipenya jelas `Guard|StatefulGuard` yang
     * dua-duanya punya `user()`, terus dipersempit ke `User` di sini.
     */
    public static function yangLogin(): ?self
    {
        $user = auth()->guard()->user();

        return $user instanceof self ? $user : null;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * "Technician ID" yang dicetak di sertifikat (mis. `DR`). Kalau admin belum
     * ngisi kodenya, jatuh ke inisial nama — lebih baik inisial daripada kolom
     * kosong di dokumen resmi.
     */
    public function kodeTeknisi(): string
    {
        if (filled($this->kode_teknisi)) {
            return strtoupper($this->kode_teknisi);
        }

        $inisial = collect(preg_split('/\s+/', trim((string) $this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $kata): string => strtoupper(mb_substr($kata, 0, 1)))
            ->implode('');

        return $inisial !== '' ? $inisial : '—';
    }

    /**
     * Benteng panel admin web (Filament). Ini SATU-SATUNYA yang misahin panel
     * dari sesi login biasa — tanpa ini, teknisi & viewer yang punya akun tetap
     * bisa buka /admin. Wajib admin DAN aktif: akun yang dinonaktifin harus
     * langsung kehilangan akses panel, bukan cuma ditolak API.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === self::ROLE_ADMIN && $this->status === self::STATUS_AKTIF;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<CalibrationSession, $this> */
    public function calibrationSessions(): HasMany
    {
        return $this->hasMany(CalibrationSession::class, 'teknisi_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
