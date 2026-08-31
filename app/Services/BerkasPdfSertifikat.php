<?php

namespace App\Services;

use App\Models\Certificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Pastikan berkas PDF sertifikat ADA di disk arsip — bangun ulang dari snapshot
 * kalau raib.
 *
 * ## Kenapa ini ada
 *
 * Disk container Render itu SEMENTARA: kehapus tiap deploy dan tiap container
 * restart (`docs/deploy-gratis-render.md` §227), dan produksi masih jalan
 * dengan `ARSIP_DRIVER=local` karena bucket R2-nya belum dibuat. Akibatnya tiap
 * deploy menghapus SELURUH PDF sertifikat yang pernah terbit — barisnya di
 * database tetap `terbit` dengan `pdf_path` terisi, tapi berkasnya tidak ada.
 *
 * Yang dilihat pengguna: sesi yang sudah disetujui, tombol unduh diklik, lalu
 * 404. Bukan sekali — tiap deploy. Dan bentuknya paling membingungkan karena
 * halaman QR dan unduhan Excel tetap jalan (dua-duanya dirakit dari snapshot di
 * database), jadi kelihatannya "cuma PDF-nya yang rusak".
 *
 * ## Kenapa membangun ulang itu SAH, bukan menerbitkan dokumen baru
 *
 * PDF sertifikat itu turunan, bukan sumber. Isinya dirender dari
 * `certificates.snapshot` yang SUDAH DIBEKUKAN waktu terbit — lihat docblock
 * [CertificateSnapshotBuilder]. Merender ulang snapshot yang sama dengan blade
 * yang sama menghasilkan lembar yang sama; nomor sertifikat, tanggal terbit,
 * dan seluruh angkanya ikut beku di situ, bukan dihitung ulang dari sesi.
 *
 * SATU hal yang bisa berbeda, dan disebut di sini supaya tidak jadi kejutan:
 * kop, logo, dan gambar tanda tangan dibaca dari pengaturan organisasi yang
 * BERLAKU SEKARANG, dan berkas-berkas itu tinggal di disk yang sama-sama
 * kehapus. Jadi lembar hasil bangun ulang bisa kehilangan gambar tanda
 * tangannya (ruang kosong buat tanda tangan basah — state yang memang sah di
 * blade-nya). Itu sebabnya tiap bangun ulang DICATAT ke log: hilangnya berkas
 * arsip masalah infrastruktur yang obatnya `ARSIP_DRIVER=s3`, dan kelas ini
 * jaring pengaman, bukan penggantinya.
 */
class BerkasPdfSertifikat
{
    public function __construct(private readonly DataTampilanSertifikat $tampilan) {}

    /**
     * Path PDF yang dijamin ada di disk `arsip`, atau `null` kalau memang belum
     * pernah ada dan tidak bisa dibangun.
     *
     * `null` di sini beda artinya dari lempar: pemanggilnya yang memutuskan
     * mau 404, 422, atau menyembunyikan tombol — dan ketiganya sudah dipakai di
     * tempat yang berbeda.
     */
    public function pastikanAda(Certificate $sertifikat): ?string
    {
        $path = (string) ($sertifikat->pdf_path ?? '');

        // Belum pernah terbit sama sekali: bukan berkas yang hilang, melainkan
        // berkas yang memang belum dibikin. Yang benar di situ tetap 404/422 —
        // membangunnya di sini bakal menerbitkan PDF buat sertifikat yang
        // statusnya `gagal` atau `draf`.
        if ($path === '' || $sertifikat->status !== Certificate::STATUS_TERBIT) {
            return null;
        }

        if (Storage::disk('arsip')->exists($path)) {
            return $path;
        }

        // Tanpa snapshot tidak ada yang bisa dirender. Sertifikat lama sebelum
        // snapshot ada memang begini, dan obatnya `sertifikat:bangun-ulang`,
        // bukan render kosong.
        if (blank($sertifikat->snapshot)) {
            return null;
        }

        $pdf = Pdf::loadView('sertifikat.pdf', $this->tampilan->untuk($sertifikat));

        // Disk-nya disetel `throw => false`, jadi tulis yang gagal balik `false`
        // tanpa suara. Diperiksa dengan alasan yang sama seperti di
        // [\App\Jobs\GenerateCertificate]: mengembalikan path yang tidak
        // menunjuk ke berkas apa pun cuma memindahkan kegagalannya satu langkah
        // ke belakang.
        if (Storage::disk('arsip')->put($path, $pdf->output()) === false) {
            Log::error('Bangun ulang PDF sertifikat gagal ditulis.', [
                'certificate_id' => $sertifikat->id,
                'path' => $path,
            ]);

            return null;
        }

        Log::warning(
            'PDF sertifikat raib dari disk arsip lalu dibangun ulang dari snapshot. '
            .'Kalau ini muncul sehabis tiap deploy, penyebabnya disk container Render yang '
            .'sementara — setel ARSIP_DRIVER=s3 (bucket R2) supaya berkasnya awet.',
            ['certificate_id' => $sertifikat->id, 'nomor' => $sertifikat->nomor, 'path' => $path],
        );

        return $path;
    }
}
