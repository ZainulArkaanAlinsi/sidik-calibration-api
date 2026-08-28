<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dokumen\EkstraktorDokumenGenerik;
use App\Services\Dokumen\PembuatSkemaDinamis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Baca lembar kerja APA PUN jadi skema form — termasuk yang belum pernah
 * dilihat sistem.
 *
 * ## Kenapa ini ada
 *
 * `POST /worksheet-scans` menolak lembar yang belum punya profil: "Template
 * lembar kerja nggak dikenal." Artinya sistem cuma sanggup membaca lembar yang
 * dia cetak sendiri, dan lembar baru butuh dua kerjaan manual — satu kelas
 * profil, plus koordinat tiap sel yang diukur tangan dari kertasnya.
 *
 * Endpoint ini jalur satunya: baca dokumennya dulu, bentuk formnya menyusul
 * dari isi kertas. Bukan pengganti jalur template — yang template lebih teliti
 * justru karena dia tahu bentuk yang dicari. Ini yang menjawab lembar yang
 * belum dikenal, biar jawabannya bukan "nggak didukung".
 *
 * ## SATU SAKLAR SAMA dengan AI Vision, dan itu disengaja
 *
 * Endpoint ini MENGIRIM FOTO LEMBAR KERJA PELANGGAN KE LAYANAN PIHAK KETIGA,
 * dan cakupannya LEBIH LUAS dari jalur AI Vision yang sudah ada: yang itu
 * mengirim foto tabel, yang ini mengirim seluruh halaman — kop surat, nama
 * pelanggan, dan nomor sertifikat ikut di dalamnya.
 *
 * Jadi dia nempel di `VISION_AKTIF` yang sama. Bikin saklar kedua berarti lab
 * yang sudah mematikan pengiriman foto lewat `VISION_AKTIF=false` tetap
 * mengirim foto lewat jalur ini tanpa sadar — dan jalur keluar data yang
 * nggak keliatan itu persis yang paling gampang lolos waktu ditinjau.
 *
 * ## Hasilnya USULAN, bukan data tersimpan
 *
 * Nggak ada yang ditulis ke database di sini. Teknisi mengoreksi dulu di layar
 * review, dan penyimpanan final tetap lewat jalur yang sudah ada. Sama seperti
 * dua jalur pindai lainnya: kamera mempercepat input, bukan jadi syaratnya.
 */
class DokumenGenerikController extends Controller
{
    public function baca(
        Request $request,
        EkstraktorDokumenGenerik $ekstraktor,
        PembuatSkemaDinamis $pembuatSkema,
    ): JsonResponse {
        $data = $request->validate([
            'foto' => ['required', 'image', 'max:8192'],
            // Nama alat itu KONTEKS opsional. Sengaja string bebas, bukan
            // pilihan dari daftar tetap: sistem harus menerima nama alat apa
            // pun, dan daftar yang menentukan kemampuan sistem itu persis yang
            // bikin lembar baru mentok.
            'nama_alat' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [
            'foto.required' => 'Fotonya belum ada.',
            'foto.image' => 'File-nya harus berupa gambar.',
            'foto.max' => 'Foto maksimal 8 MB.',
        ]);

        if (! (bool) config('services.vision.aktif', true)) {
            return response()->json([
                'ok' => false,
                'status' => 'dimatikan',
                'pesan' => 'Baca dokumen dimatikan di server (`VISION_AKTIF=false`). '
                    .'Pakai pindai lembar bertemplate atau isi manual.',
            ], 503);
        }

        $berkas = $request->file('foto');

        try {
            $hasil = $ekstraktor->ekstrak(
                base64_encode((string) file_get_contents($berkas->getRealPath())),
                (string) $berkas->getMimeType(),
                $data['nama_alat'] ?? null,
            );
        } catch (RuntimeException $e) {
            // Kunci API belum diisi itu salah SETUP, bukan salah teknisi.
            // Dibedain dari kegagalan baca supaya yang di lapangan nggak
            // motret ulang buat sesuatu yang mustahil berhasil.
            return response()->json([
                'ok' => false,
                'status' => 'salah_setup',
                'pesan' => $e->getMessage(),
            ], 503);
        }

        if (! $hasil['ok']) {
            return response()->json([
                'ok' => false,
                'status' => $hasil['status'],
                'pesan' => $hasil['error'],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => $pembuatSkema->dari($hasil['dokumen']),
            'model' => $hasil['model'],
            'usage' => $hasil['usage'],
        ]);
    }
}
