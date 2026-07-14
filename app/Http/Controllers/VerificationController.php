<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * Halaman verifikasi QR — SATU-SATUNYA tampilan web di project ini.
 *
 * Aplikasinya mobile-only, tapi QR di sertifikat itu discan orang luar (auditor,
 * pelanggan) pakai kamera HP biasa — yang kebuka browser, bukan app kita. Jadi
 * hasilnya harus halaman yang kebaca manusia, bukan JSON mentah.
 *
 * Tanpa auth. Karena itu isinya sengaja dibatesin: cukup buat mastiin sertifikat
 * itu asli (nomor, alat, pemilik, tanggal, hasil) — nggak ada data mentah
 * pengukuran, harga, apalagi data akun.
 */
class VerificationController extends Controller
{
    public function show(string $qrToken): Response
    {
        $certificate = Certificate::query()
            ->with(['session.equipment.customer', 'organization'])
            ->where('qr_token', $qrToken)
            // Sertifikat yang belum kelar digenerate belum boleh diverifikasi.
            ->where('status', Certificate::STATUS_TERBIT)
            ->first();

        if (! $certificate) {
            return response()->view(
                'verifikasi.tidak-ketemu',
                ['organization' => Organization::first()],
                404,
            );
        }

        $settings = $certificate->organization->settings ?? [];

        return response()->view('verifikasi.sertifikat', [
            'organization' => $certificate->organization,
            'certificate' => $certificate,
            'catatanKetidakpastian' => $settings['catatan_ketidakpastian'] ?? null,
            'catatanPenggandaan' => $settings['catatan_penggandaan'] ?? null,
        ]);
    }

    public function beranda(): View
    {
        return view('beranda', ['organization' => Organization::first()]);
    }
}
