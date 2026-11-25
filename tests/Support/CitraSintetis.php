<?php

namespace Tests\Support;

/**
 * Citra kecil yang HEADER-nya mengaku berukuran besar.
 *
 * Tes batas piksel (`App\Support\PengamanCitra`) tidak boleh mengalokasi 48 MP sungguhan (sekitar 190 MB di
 * GD). Yang dibaca pengaman hanya header, jadi cukup menambal dimensi di berkas 8x8: berkasnya tetap
 * ratusan byte, tapi `getimagesizefromstring` melaporkan ukuran yang diklaim.
 *
 * Jangan dipakai untuk membuktikan bahwa citra besar BISA didekode: datanya tidak sepadan dengan klaimnya.
 * Tempat yang benar-benar mendekode (dan lolos) memakai citra sungguhan yang kecil.
 */
final class CitraSintetis
{
    /** JPEG baseline 8x8 yang header SOF0-nya ditambal jadi `$lebar` x `$tinggi`. */
    public static function jpegMengaku(int $lebar, int $tinggi): string
    {
        $gambar = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($gambar);
        $isi = (string) ob_get_clean();
        imagedestroy($gambar);

        // SOF0: FF C0, panjang 0x0011, presisi 8 bit, lalu TINGGI (2 byte) dan LEBAR (2 byte).
        $posisi = strpos($isi, "\xFF\xC0\x00\x11\x08");

        if ($posisi === false) {
            throw new \RuntimeException('Penanda SOF0 tidak ada: GD menulis JPEG progresif atau format lain.');
        }

        return substr_replace($isi, pack('nn', $tinggi, $lebar), $posisi + 5, 4);
    }

    /** PNG 8x8 yang IHDR-nya ditambal (lebar, tinggi: 4 byte big-endian, offset 16). CRC sengaja dibiarkan. */
    public static function pngMengaku(int $lebar, int $tinggi): string
    {
        $gambar = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($gambar);
        $isi = (string) ob_get_clean();
        imagedestroy($gambar);

        return substr_replace($isi, pack('NN', $lebar, $tinggi), 16, 8);
    }

    /** JPEG sungguhan (didekode penuh dengan benar) berukuran `$lebar` x `$tinggi`, putih. */
    public static function jpegSungguhan(int $lebar, int $tinggi): string
    {
        $gambar = imagecreatetruecolor($lebar, $tinggi);
        imagefill($gambar, 0, 0, (int) imagecolorallocate($gambar, 255, 255, 255));
        ob_start();
        imagejpeg($gambar, null, 80);
        $isi = (string) ob_get_clean();
        imagedestroy($gambar);

        return $isi;
    }
}
