<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/pelanggan/v1/app/status` — versi minimum & keadaan maintenance.
 *
 * ## Kenapa TANPA menyentuh database sama sekali
 *
 * Ini endpoint pertama yang dipanggil aplikasi tiap kali dibuka dan tiap kali
 * kembali dari latar belakang. Dia harus tetap menjawab justru waktu keadaan
 * sedang buruk — database penuh, koneksi habis, migrasi sedang jalan — karena
 * jawabannyalah yang memberi tahu aplikasi untuk menampilkan layar maintenance
 * alih-alih galat teknis. Endpoint yang ikut mati waktu database bermasalah
 * tidak berguna persis di saat dia paling dibutuhkan.
 *
 * Dijaga test yang MENGHITUNG query, bukan cuma niat baik di komentar ini.
 *
 * ## Kenapa di luar gerbang `fitur.pelanggan`
 *
 * Kalau ikut kena 503, aplikasi tidak punya cara mengetahui versinya usang atau
 * server sedang maintenance — dua layar yang justru paling dibutuhkan saat
 * fiturnya dimatikan.
 */
class AppStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'versi_minimum' => (string) config('pelanggan.versi_minimum'),
                'versi_terbaru' => (string) config('pelanggan.versi_terbaru'),
                'maintenance' => (bool) config('pelanggan.maintenance'),
                'pesan_maintenance' => (string) config('pelanggan.pesan_maintenance'),
            ],
        ]);
    }
}
