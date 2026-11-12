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
 *
 * TANPA nama pelanggan, alamat, dan nomor order: itu data rahasia klien
 * (ISO/IEC 17025 klausul 4.2) dan yang scan QR orang luar. Yang tetap ada
 * `serial_number`, supaya pemindai bisa mencocokkannya dengan label di alatnya.
 * Dijaga `VerifikasiPublikMinimalTest`.
 */
class VerificationController extends Controller
{
    public function show(string $qrToken): JsonResponse
    {
        $certificate = Certificate::query()
            ->with(['session.equipment', 'organization'])
            ->where('qr_token', $qrToken)
            // Kembaran JSON halaman web: yang dibatalkan ikut menjawab (§38.2).
            ->whereIn('status', [Certificate::STATUS_TERBIT, Certificate::STATUS_DIBATALKAN, Certificate::STATUS_DITARIK])
            ->first();

        if (! $certificate) {
            return response()->json([
                'message' => 'Sertifikat dengan kode QR ini tidak terdaftar.',
            ], 404);
        }

        $alat = $certificate->session->equipment;
        // DUA pertanyaan, jangan disatukan (sama dengan resource pelanggan):
        // - `status_dokumen`: revisi terbit ATAU dibatalkan sama-sama
        //   membuatnya `digantikan` (K38-2 — pembatalan revisi tidak
        //   menghidupkan pendahulunya).
        // - `digantikan_oleh`: hanya revisi TERBIT. Revisi yang dibatalkan
        //   bukan dokumen yang berlaku, jadi tidak boleh ditunjuk publik
        //   sebagai penggantinya.
        $revisi = in_array($certificate->status, [Certificate::STATUS_TERBIT, Certificate::STATUS_DITARIK], true) ? $certificate->revisiPenentuStatus() : null;
        $pengganti = Certificate::yangSah($revisi);

        return response()->json([
            'data' => [
                'nomor' => $certificate->nomor,
                'status' => $certificate->status,
                // §38.2 — tanpa alasan apa pun (D4).
                'status_dokumen' => Certificate::labelDokumen((string) $certificate->status, $revisi?->status),
                'digantikan_oleh' => $pengganti === null ? null : [
                    'nomor' => $pengganti->nomor,
                    'diterbitkan_pada' => $pengganti->diterbitkan_pada?->toDateString(),
                    'tautan' => route('verify', $pengganti->qr_token),
                ],
                'dibatalkan_pada' => $certificate->dibatalkan_pada?->toDateString(),
                'ditarik_pada' => $certificate->ditarik_pada?->toDateString(),
                'keputusan' => $certificate->session->keputusan,
                'diterbitkan_pada' => $certificate->diterbitkan_pada?->toDateString(),
                'berlaku_sampai' => $certificate->berlaku_sampai?->toDateString(),
                'kadaluarsa' => (bool) $certificate->berlaku_sampai?->isPast(),
                'alat' => [
                    'nama_alat' => $alat->nama_alat,
                    'serial_number' => $alat->serial_number,
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
