<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Organization;
use App\Services\CertificateExcelExporter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Unduh sertifikat langsung dari hasil scan QR (spesifikasi poin 13),
     * dalam bentuk PDF atau Excel.
     *
     * TANPA AUTH — dan itu memang maunya: QR di lembar sertifikat discan
     * pelanggan/auditor yang nggak punya akun di sistem kita, terus mereka
     * langsung bisa nyimpen atau nerusin dokumennya.
     *
     * Yang jagain kerahasiaan di sini `qr_token`-nya sendiri: 10 karakter acak,
     * bukan id berurutan, jadi nggak bisa ditebak/disisir. Siapa pun yang
     * megang QR-nya emang udah dikasih lembar sertifikatnya.
     */
    public function download(Request $request, string $qrToken, CertificateExcelExporter $excel): mixed
    {
        $certificate = Certificate::query()
            ->with(['session.equipment.customer', 'organization'])
            ->where('qr_token', $qrToken)
            ->where('status', Certificate::STATUS_TERBIT)
            ->firstOr(fn () => abort(404, 'Sertifikat dengan kode QR ini tidak terdaftar.'));

        $format = $request->string('format', 'pdf')->lower()->value();

        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404, 'Format yang tersedia cuma pdf atau xlsx.');

        if ($format === 'xlsx') {
            abort_unless(filled($certificate->snapshot), 404);

            $tmp = tempnam(sys_get_temp_dir(), 'sertifikat-').'.xlsx';
            $excel->satu($certificate, $tmp);

            return response()->download($tmp, $certificate->namaFile('xlsx'))->deleteFileAfterSend();
        }

        abort_unless(
            $certificate->pdf_path && Storage::disk('local')->exists($certificate->pdf_path),
            404,
        );

        return Storage::disk('local')->download($certificate->pdf_path, $certificate->namaFile('pdf'));
    }

    public function beranda(): View
    {
        return view('beranda', ['organization' => Organization::first()]);
    }
}
