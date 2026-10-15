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

        // Akun yang tidak aktif ditolak SEBELUM pertanyaan peran, dan semua
        // tokennya dicabut. Jalur penulisan status tidak semuanya mencabut
        // token (form Edit di panel cuma menyimpan kolomnya), dan Sanctum di
        // sini tanpa masa berlaku — tanpa penjagaan ini karyawan yang keluar
        // tetap masuk dari HP lamanya (temuan B06, paket 30 Sep). 401, bukan
        // 403: buat aplikasi artinya "sesi berakhir", jadi dia keluar sendiri.
        if ($user instanceof User && $user->status !== User::STATUS_AKTIF) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Akun kamu tidak aktif. Hubungi admin lab.',
                'kode' => 'akun_nonaktif',
            ], 401);
        }

        $boleh = $user && (
            in_array($user->role, $roles, true)
            || self::lolosSuperAdmin($user->role, $request->getMethod(), $roles)
        );

        if (! $boleh) {
            return response()->json(['message' => 'Kamu nggak punya akses ke sini.'], 403);
        }

        return $next($request);
    }

    /**
     * Super admin lewat gerbang `role:` ini walau namanya tidak ditulis?
     *
     * Satu pintu yang dipanggil middleware DAN `MatriksIzin`, supaya jawaban
     * `/me/permissions` tidak pernah berbeda dari penjagaan yang sebenarnya.
     * Isinya dua aturan: baca (sejak 23 Sep 2026) dan tulis (sejak K-49-14).
     *
     * @param  array<int, string>  $rolesRute  role yang ditulis di `role:` rutenya
     */
    public static function lolosSuperAdmin(string $role, string $method, array $rolesRute): bool
    {
        return self::lolosBacaSuperAdmin($role, $method, $rolesRute)
            || self::lolosTulisSuperAdmin($role, $rolesRute);
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
     * Batasnya:
     *
     * 1. **Cuma GET/HEAD.** Menulis punya aturannya sendiri,
     *    [lolosTulisSuperAdmin()].
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
     * @param  array<int, string>  $rolesRute  role yang ditulis di `role:` rutenya
     */
    public static function lolosBacaSuperAdmin(string $role, string $method, array $rolesRute): bool
    {
        return $role === User::ROLE_SUPER_ADMIN
            && in_array(strtoupper($method), ['GET', 'HEAD'], true)
            && ! in_array(User::ROLE_PELANGGAN, $rolesRute, true);
    }

    /**
     * Super admin lewat di rute TULIS yang menerima admin atau teknisi.
     *
     * Keputusan K-49-14 (pemilik proyek, 15 Okt 2026): super admin boleh SEMUA
     * aksi tulis admin dan teknisi di organisasinya sendiri, dari satu tempat
     * (aplikasi). Ditaruh di sini, bukan dengan menambahkan `super_admin` ke
     * puluhan `role:` di `routes/api.php`: rute tulis baru yang lupa
     * menuliskannya tidak memunculkan error, cuma 403 yang kelihatan seperti
     * hak yang memang tidak pernah diberikan — alasan yang sama dengan aturan
     * baca di atas.
     *
     * Batasnya, dan ketiganya sengaja:
     *
     * 1. **Rute harus menerima `admin` atau `teknisi`.** Rute `role:viewer`
     *    saja (belum ada satu pun) tidak ikut — super admin itu gabungan dua
     *    peran yang menulis, bukan "semua peran".
     * 2. **Rute yang menyebut `pelanggan` tidak ikut**, alasan yang sama dengan
     *    aturan baca: dunia pelanggan disaring `KonteksPerusahaan`.
     * 3. **Bukan gerbang organisasi dan bukan gerbang pemisahan wewenang.**
     *    Penyaring `organization_id` di controller tidak berubah (tulis lintas
     *    lab TIDAK dibuka), dan `PemisahanWewenang` tetap memblokir super admin
     *    yang ikut mengisi sesi dari menyetujui/mengesahkannya (K-30-03).
     *
     * Panel Filament tidak memakai jalur ini dan tetap baca-saja untuk super
     * admin (`HakTulisPanel`).
     *
     * @param  array<int, string>  $rolesRute  role yang ditulis di `role:` rutenya
     */
    public static function lolosTulisSuperAdmin(string $role, array $rolesRute): bool
    {
        return $role === User::ROLE_SUPER_ADMIN
            && ! in_array(User::ROLE_PELANGGAN, $rolesRute, true)
            && array_intersect([User::ROLE_ADMIN, User::ROLE_TEKNISI], $rolesRute) !== [];
    }
}
