<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint DATA pelanggan cuma buat akun yang sudah ditautkan admin
 * (REQ-AUTH-03).
 *
 * Akun `pending_verifikasi` boleh masuk dan boleh membaca dirinya sendiri —
 * layar S06 "menunggu verifikasi" butuh itu. Yang tidak boleh dia sentuh apa
 * pun yang berisi data perusahaan: alat, sertifikat, permintaan, pesan.
 *
 * ## Kenapa dua gerbang, bukan satu
 *
 * `PastikanAplikasi` menjawab "token ini dari aplikasi mana". Middleware ini
 * menjawab "pemiliknya sudah diverifikasi belum". Menggabungkannya bikin layar
 * S06 mustahil: token `pelanggan:menunggu` harus lolos gerbang pertama supaya
 * `GET /saya` bisa dijawab, tapi wajib ditahan gerbang kedua.
 *
 * ## Yang dipakai `status` akun, bukan ability tokennya
 *
 * Ability dibekukan waktu token terbit. Akun yang disetujui admin lima menit
 * lalu masih memegang token ber-ability `pelanggan:menunggu` sampai dia masuk
 * lagi — dan memaksa orang keluar-masuk sesudah disetujui itu langkah yang
 * tidak dimengerti siapa pun. `status` dibaca dari baris user tiap request,
 * jadi persetujuan admin langsung berlaku.
 */
class PelangganAktif
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->status === User::STATUS_AKTIF && $user->dianonimkan_pada === null) {
            return $next($request);
        }

        return response()->json([
            'kode' => 'akun_belum_diverifikasi',
            'message' => 'Akun Anda masih menunggu verifikasi PT Sidik. Kami kabari begitu selesai.',
        ], 403);
    }
}
