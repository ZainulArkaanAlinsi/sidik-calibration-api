<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pemasangan aplikasi yang boleh dikirimi push notification.
 *
 * @mixin IdeHelperDeviceToken
 */
#[Fillable(['user_id', 'token', 'platform', 'versi_app', 'terakhir_aktif'])]
class DeviceToken extends Model
{
    /** Platform yang payload push-nya kita tahu bentuknya. */
    public const PLATFORM = ['android', 'ios', 'windows', 'macos'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['terakhir_aktif' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Catat token milik pengguna ini — mendaftar ulang MEMINDAHKAN kepemilikan.
     *
     * Bukan "bikin kalau belum ada": satu HP yang dipakai gantian dua teknisi
     * dapat token FCM yang sama, dan kalau barisnya nggak dipindahtangankan,
     * notifikasi teknisi sebelumnya terus mendarat di HP yang sekarang
     * dipegang orang lain. Di tim ini HP tes memang dipakai gantian, jadi itu
     * jalur yang beneran kejadian, bukan kemungkinan di atas kertas.
     */
    public static function catat(
        User $user,
        string $token,
        string $platform,
        ?string $versiApp = null,
    ): self {
        return static::updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $user->id,
                'platform' => $platform,
                'versi_app' => $versiApp,
                'terakhir_aktif' => now(),
            ],
        );
    }
}
