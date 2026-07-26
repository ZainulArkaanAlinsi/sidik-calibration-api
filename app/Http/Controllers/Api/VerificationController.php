<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use Illuminate\Http\JsonResponse;

/**
 * Versi JSON dari verifikasi QR (kontrak: `GET /api/verify/{qr_token}`, tanpa auth).
 * Dipakai kalau nanti mobile mau nampilin hasil scan di dalam app.
 *
 * Isinya sama persis dengan halaman webnya — dibatesin, karena ini publik.
 */
class VerificationController extends Controller
{
    public function show(string $qrToken): JsonResponse
    {
        $certificate = Certificate::query()
            ->with(['session.equipment.customer', 'organization'])
            ->where('qr_token', $qrToken)
            ->where('status', Certificate::STATUS_TERBIT)
            ->first();

        if (! $certificate) {
            return response()->json([
                'message' => 'Sertifikat dengan kode QR ini tidak terdaftar.',
            ], 404);
        }

        $alat = $certificate->session->equipment;

        return response()->json([
            'data' => [
                'nomor' => $certificate->nomor,
                'status' => $certificate->status,
                'keputusan' => $certificate->session->keputusan,
                'diterbitkan_pada' => $certificate->diterbitkan_pada?->toDateString(),
                'berlaku_sampai' => $certificate->berlaku_sampai?->toDateString(),
                'kadaluarsa' => (bool) $certificate->berlaku_sampai?->isPast(),
                'alat' => [
                    'nama_alat' => $alat->nama_alat,
                    'serial_number' => $alat->serial_number,
                    'pemilik' => $alat->customer->nama,
                ],
                'tanggal_kalibrasi' => $certificate->session->tanggal_kalibrasi?->toDateString(),
                'diterbitkan_oleh' => [
                    'nama' => $certificate->organization->nama,
                    'no_akreditasi' => $certificate->organization->no_akreditasi,
                ],
            ],
        ]);
    }
}
