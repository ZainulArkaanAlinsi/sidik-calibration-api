<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Satu endpoint biar mobile nggak perlu nembak 5 endpoint sekaligus buat
 * ngisi 1 layar.
 *
 * Isinya beda tergantung role: teknisi cuma lihat kalibrasi miliknya sendiri,
 * admin & viewer lihat lintas-teknisi. Role diambil dari token, mobile nggak
 * ngirim apa-apa — kalau dikirim dari client, gampang dibohongin.
 */
class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->organization_id;

        $sesi = CalibrationSession::where('organization_id', $organizationId)
            ->when(
                $user->role === User::ROLE_TEKNISI,
                fn ($query) => $query->where('teknisi_id', $user->id),
            );

        return response()->json([
            'data' => [
                'total_alat' => Equipment::where('organization_id', $organizationId)->count(),
                'alat_overdue' => Equipment::where('organization_id', $organizationId)->overdue()->count(),
                'kalibrasi_draft' => (clone $sesi)->where('status', CalibrationSession::STATUS_DRAFT)->count(),
                'menunggu_approval' => (clone $sesi)->where('status', CalibrationSession::STATUS_MENUNGGU_APPROVAL)->count(),
                'sertifikat_bulan_ini' => Certificate::where('organization_id', $organizationId)
                    ->where('status', Certificate::STATUS_TERBIT)
                    ->whereBetween('diterbitkan_pada', [now()->startOfMonth(), now()->endOfMonth()])
                    ->count(),
            ],
        ]);
    }
}
