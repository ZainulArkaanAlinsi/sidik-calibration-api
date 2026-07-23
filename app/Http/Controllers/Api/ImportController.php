<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExcelImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Import master data dari Excel (spesifikasi poin 12C). Admin doang.
 *
 * Alurnya dua langkah dan itu disengaja: unggah dengan `uji_coba` (default)
 * buat lihat hasil pemetaan kolom & apa yang bakal kejadian, baru kirim ulang
 * dengan `uji_coba=false` kalau udah cocok. Satu tombol yang langsung nulis
 * ratusan baris ke database itu cara paling cepat ngerusak master data.
 */
class ImportController extends Controller
{
    public function __construct(private readonly ExcelImporter $importer) {}

    public function excel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
            'tipe' => ['required', Rule::in(ExcelImporter::TIPE)],
            'uji_coba' => ['sometimes', 'boolean'],
        ], [
            'file.mimes' => 'File-nya harus .xlsx, .xls, atau .csv.',
            'file.max' => 'File maksimal 10 MB.',
            'tipe.in' => 'Tipe import cuma boleh: '.implode(', ', ExcelImporter::TIPE).'.',
        ]);

        // Default UJI COBA. Kalau kunci `uji_coba` nggak dikirim sama sekali,
        // yang aman itu nggak nulis apa-apa.
        $ujiCoba = ! $request->has('uji_coba') || $request->boolean('uji_coba');

        $hasil = $this->importer->jalankan(
            $request->file('file')->getRealPath(),
            $data['tipe'],
            $request->user()->organization_id,
            $ujiCoba,
        );

        return response()->json([
            'data' => $hasil,
            'message' => $ujiCoba
                ? 'Uji coba selesai — belum ada data yang disimpan. Kirim ulang dengan `uji_coba: false` buat nerapin.'
                : 'Import selesai.',
        ]);
    }

    /**
     * Daftar kolom yang dikenali per tipe — biar admin bisa nyiapin/ngerapiin
     * header Excel-nya duluan, bukan trial & error nebak nama kolom.
     */
    public function format(): JsonResponse
    {
        return response()->json([
            'data' => [
                'tipe' => ExcelImporter::TIPE,
                'kolom' => ExcelImporter::daftarKolom(),
                'catatan' => [
                    'Header dicocokin tanpa peduli huruf besar/kecil, spasi, atau tanda baca.',
                    'Kolom yang nggak dikenal diabaikan, nggak bikin import gagal.',
                    'Import alat butuh PT-nya udah ada duluan — jalanin import pelanggan lebih dulu.',
                ],
            ],
        ]);
    }
}
