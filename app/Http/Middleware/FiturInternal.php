<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\FiturSaklar;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saklar fitur berbasis basis data untuk aplikasi lab (paket OPS-KODE butir 3).
 * Dipasang per rute sebagai `fitur:<kode>`; kode harus terdaftar di
 * [FiturSaklar::DAFTAR]. Mati -> 503 `fitur_dimatikan`.
 *
 * ## Urutannya
 *
 * Didaftarkan di daftar prioritas SESUDAH `role:` dan SEBELUM
 * `SubstituteBindings` (bootstrap/app.php), dengan dua akibat yang disengaja:
 *
 *  - peran tanpa hak mendapat 403 dulu, bukan 503 — status fitur tidak
 *    dibocorkan ke yang memang tidak berhak memakainya;
 *  - 503 datang SEBELUM route model binding, jadi id yang tidak ada tidak
 *    dijawab 404 sementara id yang ada dijawab 503 (oracle keberadaan id yang
 *    sama dengan yang ditutup R2-7 untuk `role:`).
 *
 * ## Per organisasi
 *
 * Keadaannya dibaca dari organisasi PEMANGGIL; saklar satu lab tidak pernah
 * menyentuh lab lain.
 *
 * ## Kenapa 503 dengan `kode`, bukan 404/403
 *
 * Sama dengan `FiturPelanggan`: 503 + `kode` yang stabil bisa diterjemahkan
 * aplikasi jadi pesan "fitur dimatikan", sedangkan 404 dibaca sebagai alamat
 * salah dan 403 sebagai "kamu tidak berhak". Kodenya tidak berubah walau
 * kalimatnya diperbaiki.
 */
class FiturInternal
{
    public function handle(Request $request, Closure $next, string $kode): Response
    {
        $pengguna = $request->user();

        // Tanpa pengguna (tamu) bukan urusan di sini: `auth:sanctum` yang
        // menjawab 401. Rute ber-`fitur:` selalu berada di belakangnya.
        if ($pengguna instanceof User && ! FiturSaklar::aktif($pengguna->organization, $kode)) {
            return response()->json([
                'kode' => 'fitur_dimatikan',
                'fitur' => $kode,
                'message' => 'Fitur "'.FiturSaklar::label($kode).'" sedang dimatikan oleh Super Admin. '
                    .'Hubungi Super Admin lab kalau fitur ini dibutuhkan.',
            ], 503);
        }

        return $next($request);
    }
}
