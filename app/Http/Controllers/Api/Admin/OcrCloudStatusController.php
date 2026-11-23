<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\StatusOcrCloud;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/admin/ocr-cloud`: status konfigurasi OCR awan (Google Vision) dan hitungan kegagalan
 * 24 jam, supaya setelan yang salah cepat terlihat oleh admin.
 *
 * Sengaja BUKAN bagian `GET /api/health`: endpoint itu publik, dan status konfigurasi layanan
 * berbayar pihak ketiga tidak untuk dibaca siapa saja. Di sini gerbangnya sama dengan semua
 * endpoint admin lain (`auth:sanctum`, `aplikasi:internal`, `role:admin`; super admin lewat aturan
 * baca umum), jadi aturan token dan peran tidak ditiru di tempat kedua. TIDAK di belakang saklar
 * `fitur:ocr_cloud`: admin harus tetap bisa melihat statusnya justru saat fitur dimatikan.
 */
class OcrCloudStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(StatusOcrCloud::blok((int) $request->user()->organization_id));
    }
}
