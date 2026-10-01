<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Organization;
use App\Services\BerkasPdfSertifikat;
use App\Services\CertificateExcelExporter;
use App\Services\DataTampilanSertifikat;
use App\Support\SamarkanNama;
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
            // Yang DIBATALKAN justru harus menjawab — kertasnya masih beredar,
            // dan halaman ini satu-satunya yang bisa memberi tahu pemindainya
            // (§38.2, alasan utama QR dipertahankan di kertas).
            ->whereIn('status', [Certificate::STATUS_TERBIT, Certificate::STATUS_DIBATALKAN])
            ->first();

        if (! $certificate) {
            return response()->view(
                'verifikasi.tidak-ketemu',
                ['organization' => Organization::first()],
                404,
            );
        }

        // Dibatalkan: kartu status saja, TANPA lembar & TANPA alasan (D4) —
        // lembarnya tidak boleh tampil seolah masih dokumen yang sah.
        if ($certificate->status === Certificate::STATUS_DIBATALKAN) {
            return response()->view('verifikasi.dibatalkan', [
                'organization' => $certificate->organization,
                'certificate' => $certificate,
            ]);
        }

        // Digantikan revisi: lembar lama tetap tampil sebagai riwayat, dengan
        // bilah yang menunjuk ke yang berlaku.
        $pengganti = $certificate->penggantiSah();
        $pemilikSamar = SamarkanNama::untuk(
            $certificate->snapshot['header']['owner'] ?? $certificate->session?->equipment?->customer?->nama,
        );

        // Yang ditampilin LEMBAR SERTIFIKATNYA SENDIRI — blade yang sama persis
        // dengan yang dicetak jadi PDF, bukan ringkasan versi web.
        //
        // Sebelumnya halaman ini bikin kartu ringkas sendiri, dan itu keliru:
        // orang yang nycan QR lagi mencocokkan lembar di tangannya dengan yang
        // asli. Kalau bentuknya beda, nggak ada yang bisa dicocokin — dia cuma
        // dikasih tahu "terdaftar", tanpa bisa lihat angka mana yang bener.
        //
        // Ini nggak nambah data yang kebuka: tombol unduh PDF di halaman ini
        // udah tanpa auth dari dulu (spesifikasi poin 13), jadi siapa pun yang
        // megang token QR-nya emang udah bisa lihat lembar penuhnya. Yang
        // jagain tetap `qr_token`-nya: 10 karakter acak, bukan id berurutan.
        //
        // Sertifikat lama yang snapshot-nya kosong nggak bisa dirender begitu —
        // buat mereka kartu ringkas yang lama tetap dipakai.
        if (blank($certificate->snapshot)) {
            $settings = $certificate->organization->settings ?? [];

            return response()->view('verifikasi.sertifikat', [
                'organization' => $certificate->organization,
                'certificate' => $certificate,
                'pengganti' => $pengganti,
                'pemilikSamar' => $pemilikSamar,
                'catatanKetidakpastian' => $settings['catatan_ketidakpastian'] ?? null,
                'catatanPenggandaan' => $settings['catatan_penggandaan'] ?? null,
            ]);
        }

        return response()->view('sertifikat.pdf', [
            ...app(DataTampilanSertifikat::class)->untuk($certificate, web: true),
            'pengganti' => $pengganti,
            'pemilikSamar' => $pemilikSamar,
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
    public function download(
        Request $request,
        string $qrToken,
        CertificateExcelExporter $excel,
        BerkasPdfSertifikat $berkas,
    ): mixed {
        $certificate = Certificate::query()
            ->with(['session.equipment.customer', 'organization'])
            ->where('qr_token', $qrToken)
            ->whereIn('status', [Certificate::STATUS_TERBIT, Certificate::STATUS_DIBATALKAN])
            ->firstOr(fn () => abort(404, 'Sertifikat dengan kode QR ini tidak terdaftar.'));

        // 410, bukan 404: barisnya ADA, dan pemegang QR berhak tahu kenapa
        // berkasnya tidak diberikan. PDF batal tidak boleh beredar lagi sebagai
        // dokumen sah (§38.2). Yang DIGANTIKAN tetap boleh diunduh — riwayat.
        abort_if(
            $certificate->status === Certificate::STATUS_DIBATALKAN,
            410,
            'Sertifikat ini sudah dibatalkan, jadi berkasnya tidak bisa diunduh lagi.',
        );

        $format = $request->string('format', 'pdf')->lower()->value();

        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404, 'Format yang tersedia cuma pdf atau xlsx.');

        if ($format === 'xlsx') {
            abort_unless(filled($certificate->snapshot), 404);

            $tmp = tempnam(sys_get_temp_dir(), 'sertifikat-').'.xlsx';
            $excel->satu($certificate, $tmp);

            return response()->download($tmp, $certificate->namaFile('xlsx'))->deleteFileAfterSend();
        }

        // Dibangun ulang dari snapshot kalau berkasnya raib — disk arsip Render
        // kehapus tiap deploy, dan sebelum ini pemegang QR dapat 404 buat
        // sertifikat yang datanya lengkap di database. Lihat [BerkasPdfSertifikat].
        $path = $berkas->pastikanAda($certificate);

        abort_unless($path !== null, 404);

        return Storage::disk('arsip')->download($path, $certificate->namaFile('pdf'));
    }

    public function beranda(): View
    {
        return view('beranda', ['organization' => Organization::first()]);
    }
}
