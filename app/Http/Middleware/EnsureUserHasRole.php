<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dipakai di routes: `->middleware('role:admin')` atau `role:admin,teknisi`.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $boleh = $user && (
            in_array($user->role, $roles, true)
            || self::lolosBacaSuperAdmin($user->role, $request->getMethod(), $roles)
        );

        if (! $boleh) {
            return response()->json(['message' => 'Kamu nggak punya akses ke sini.'], 403);
        }

        return $next($request);
    }

    /**
     * Super admin lewat di rute BACA walau namanya tidak disebut `role:`.
     *
     * AGENTS.md §Peran butir 2: super admin boleh membaca semua data lab tanpa
     * batas — itu yang bikin pelacakan menyeluruh berguna. Kalau tiap rute baca
     * harus menuliskan `role:admin,teknisi,viewer,super_admin`, yang lupa
     * ditulis TIDAK memunculkan error: dia cuma jadi 403 yang kelihatan seperti
     * hak yang memang tidak pernah diberikan. Satu aturan di sini lebih susah
     * meleset daripada 40 daftar yang harus kompak.
     *
     * Tiga batasnya, dan ketiganya sengaja:
     *
     * 1. **Cuma GET/HEAD.** Menulis tetap butuh namanya disebut. Fase 2 membuka
     *    pintu masuk, bukan wewenang; K4 (siapa yang boleh mengesahkan
     *    sertifikat) belum dijawab manajer teknis, dan super admin yang bisa
     *    approve sebelum itu turun persis hal yang ditahan.
     * 2. **Rute yang menyebut `pelanggan` tidak ikut.** Dunia pelanggan disaring
     *    `KonteksPerusahaan`, bukan `organization_id`; super admin yang masuk ke
     *    sana tidak punya `customer_id`, dan hasilnya bukan "semua data" tapi
     *    kebocoran antar pelanggan atau error. Per hari ini
     *    `routes/api_pelanggan.php` memang tidak memakai middleware ini sama
     *    sekali — penjagaan ini buat rute yang belum ditulis.
     * 3. **Bukan gerbang organisasi.** Penyaring `organization_id` di controller
     *    tidak disentuh, jadi super admin hari ini membaca lab-nya sendiri.
     *    Lintas lab slice terpisah — 59 tempat, dan menyelipkannya di sini
     *    berarti mengubah isolasi data tanpa ada yang meninjaunya.
     *
     * Dipakai `MatriksIzin` juga, supaya jawaban `/me/izin` tidak pernah
     * berbeda dari penjagaan yang sebenarnya.
     *
     * @param  array<int, string>  $rolesRute  role yang ditulis di `role:` rutenya
     */
    public static function lolosBacaSuperAdmin(string $role, string $method, array $rolesRute): bool
    {
        return $role === User::ROLE_SUPER_ADMIN
            && in_array(strtoupper($method), ['GET', 'HEAD'], true)
            && ! in_array(User::ROLE_PELANGGAN, $rolesRute, true);
    }
}
