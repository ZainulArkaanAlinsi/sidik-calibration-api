<?php

namespace App\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR Code sertifikat (spesifikasi poin 13).
 *
 * Isinya URL verifikasi publik (`/verify/{qr_token}`), bukan data sertifikatnya
 * — QR yang nyimpen datanya sendiri bisa dipalsuin siapa aja yang bisa bikin
 * QR. Yang ini maksa siapa pun yang scan buat balik nanya ke server kita.
 *
 * PNG, bukan SVG: hasilnya dipakai di PDF (dompdf), di panel admin, dan di
 * layar HP. PNG satu-satunya yang aman kebaca ketiganya.
 */
class QrCodeGenerator
{
    /**
     * Tingkat koreksi error QUARTILE (~25%): sertifikat itu dokumen kertas yang
     * difotokopi, distempel, dan discan dalam kondisi seadanya. Kode yang
     * tercoret dikit harus tetap kebaca.
     */
    private const ECC = EccLevel::Q;

    /** Buat disematkan di PDF/HTML: `data:image/png;base64,...`. */
    public function dataUri(string $isi, int $skala = 5): string
    {
        return (new QRCode($this->opsi($skala, base64: true)))->render($isi);
    }

    /** PNG mentah — buat respons HTTP `image/png`. */
    public function png(string $isi, int $skala = 8): string
    {
        return (new QRCode($this->opsi($skala, base64: false)))->render($isi);
    }

    private function opsi(int $skala, bool $base64): QROptions
    {
        return new QROptions([
            // `outputInterface` cuma dilirik kalau `outputType`-nya CUSTOM —
            // tanpa baris itu, library balik ke bawaannya (SVG) diam-diam.
            'outputType' => QROutputInterface::CUSTOM,
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => self::ECC,
            'scale' => $skala,
            'outputBase64' => $base64,
            // Batas putih di sekeliling kode. Tanpa ini, scanner sering gagal
            // ngunci kode yang nempel langsung sama border tabel di sertifikat.
            'quietzoneSize' => 2,
            'imageTransparent' => false,
        ]);
    }
}
