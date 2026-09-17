<?php

namespace App\Http\Middleware;

use App\Models\CustomerMember;
use App\Support\Pelanggan\Konteks;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tentukan perusahaan aktif dari header `X-Perusahaan-Id` (REQ-ANG-04, §3.3).
 *
 * ## Header tidak valid dijawab 404, bukan 403
 *
 * Sengaja, dan sejalan dengan REQ-ALT-01: 403 sudah memberi tahu bahwa
 * perusahaan dengan ID itu ADA — cukup buat menyisir daftar pelanggan PT Sidik
 * dengan menembak ID satu per satu. 404 tidak menjawab apa pun.
 *
 * Perlakuan yang sama buat keanggotaan yang NONAKTIF: orang yang baru
 * dinonaktifkan tidak boleh bisa membedakan "saya dikeluarkan" dari
 * "perusahaan itu tidak ada", karena yang pertama adalah informasi soal
 * perusahaan yang bukan lagi urusannya.
 *
 * ## Header kosong
 *
 * Satu keanggotaan aktif → dipakai otomatis; aplikasi tidak perlu menyimpan
 * apa pun buat kasus yang paling umum. Lebih dari satu → **400
 * `perusahaan_belum_dipilih`** beserta daftar pilihannya, supaya aplikasi bisa
 * langsung menampilkan pemilih tanpa memanggil endpoint lain.
 *
 * Nol keanggotaan aktif → 404 juga. Itu bisa terjadi pada akun yang keanggotaan
 * terakhirnya baru dinonaktifkan tapi tokennya masih hidup karena dia masih
 * anggota di tempat lain — perlombaan yang nyata, bukan teoretis.
 */
class KonteksPerusahaan
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $aktif = CustomerMember::query()
            ->with('customer')
            ->where('user_id', $user?->getKey())
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->get();

        $diminta = trim((string) $request->header('X-Perusahaan-Id', ''));

        if ($diminta === '') {
            if ($aktif->count() === 1) {
                return $this->lanjut($request, $next, $aktif->first());
            }

            if ($aktif->isEmpty()) {
                abort(404);
            }

            return response()->json([
                'kode' => 'perusahaan_belum_dipilih',
                'message' => 'Pilih perusahaan dulu, lalu kirim ulang dengan header X-Perusahaan-Id.',
                'data' => [
                    'pilihan' => $aktif->map(fn (CustomerMember $anggota) => [
                        'customer_id' => $anggota->customer_id,
                        'nama_perusahaan' => $anggota->customer?->nama,
                        'peran' => $anggota->peran,
                    ])->values(),
                ],
            ], 400);
        }

        // Diadu sebagai STRING angka, bukan `(int)` di kedua sisi: `(int) 'abc'`
        // itu 0, dan `(int) '12abc'` itu 12 — dua-duanya bikin header ngawur
        // diam-diam dibaca sebagai ID yang sah.
        if (! ctype_digit($diminta)) {
            abort(404);
        }

        $anggota = $aktif->firstWhere('customer_id', (int) $diminta);

        abort_if($anggota === null, 404);

        return $this->lanjut($request, $next, $anggota);
    }

    private function lanjut(Request $request, Closure $next, CustomerMember $anggota): Response
    {
        $konteks = Konteks::dari($anggota);

        // Dua tempat sekaligus, dan dua-duanya dipakai: container buat
        // controller yang menerimanya lewat constructor, dan atribut request
        // buat middleware yang jalan sesudah ini (`peran:`).
        app()->instance(Konteks::class, $konteks);
        $request->attributes->set('konteks_pelanggan', $konteks);

        return $next($request);
    }
}
