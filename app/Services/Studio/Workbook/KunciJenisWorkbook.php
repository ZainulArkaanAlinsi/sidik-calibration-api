<?php

namespace App\Services\Studio\Workbook;

/**
 * Kunci jenis workbook master (keputusan pemilik K-49-13, 15 Okt 2026): Studio
 * memegang SATU master aktif per jenis/varian alat. Varian beda rentang atau
 * standar = jenis berbeda (Micrometer 0-25 ≠ 25-50, Timbangan gram ≠ kg ≠
 * substitusi, Enclosure Constant-Yokogawa ≠ Recorder).
 *
 * Bentuk: `{keluarga ?? kode_profil ?? "tanpa-profil"}:{varian}`. Varian =
 * nama berkas/folder dalam huruf kecil, selain huruf/angka jadi pemisah, lalu
 * dibuang token yang bukan identitas varian: kata "master/olah/olda/data",
 * penanda revisi ("new/baru/terbaru/revisi/rev/final/copy/salinan"), tahun
 * empat digit 19xx/20xx, dan nomor urut di depan nama (`1_2_…`, `5__…`). Jadi
 * `Master_Olah_Data_Micrometer_0-25mm` (csv) dan `Master Olah Data Micrometer
 * 0-25mm.xlsm` mendarat di kunci yang sama → yang satu riwayat yang lain; dan
 * `Fixed_Volumetric_Glassware_2026` = `(NEW) Master Olah Data_Fixed Volumetric
 * Glassware_2026`. Angka lain (rentang, nomor seri, tanggal 6+ digit) TETAP
 * bagian varian — salinan per-alat tidak pernah menggantikan master umum
 * diam-diam.
 *
 * Kunci ditetapkan saat impor dan bisa ditimpa admin (`--kunci-jenis`,
 * `studio:rapikan-workbook`). Bila nama berganti total antar versi, impor
 * memakai `--gantikan=<id>`; tebakan nama tidak pernah dipaksakan.
 */
final class KunciJenisWorkbook
{
    public const PANJANG_MAKS = 160;

    /** Token yang dibuang dari varian (bukan identitas jenis). */
    private const DERAU = [
        'master', 'olah', 'olda', 'data', 'new', 'baru', 'terbaru', 'revisi', 'rev', 'final',
        'copy', 'salinan', 'update', 'updated',
    ];

    private function __construct() {}

    public static function dari(?string $kodeProfil, ?string $keluarga, string $nama): string
    {
        $profil = self::bersihkan((string) ($keluarga ?? $kodeProfil ?? ''));

        return self::potong(($profil !== '' ? $profil : 'tanpa-profil').':'.self::varian($nama));
    }

    public static function varian(string $nama): string
    {
        $nama = (string) preg_replace('/\.(xlsm|xlsx|csv)$/i', '', $nama);
        $token = preg_split('/[^a-z0-9]+/', mb_strtolower($nama, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $semua = $token;

        // Nomor urut di depan nama.
        while ($token !== [] && ctype_digit($token[0])) {
            array_shift($token);
        }

        $token = array_values(array_filter(
            $token,
            static fn (string $t): bool => ! in_array($t, self::DERAU, true) && preg_match('/^(19|20)\d{2}$/', $t) !== 1,
        ));

        // Nama yang seluruhnya derau ("Master Olah Data 2026") tetap punya kunci.
        return implode('-', $token !== [] ? $token : $semua);
    }

    /**
     * Kunci yang diketik admin (`--kunci-jenis`): huruf kecil, spasi jadi `-`,
     * hanya `a-z 0-9 : _ . -`. Kosong → null (ditolak pemanggil).
     */
    public static function normalkan(string $kunci): ?string
    {
        $kunci = mb_strtolower(trim($kunci), 'UTF-8');
        $kunci = (string) preg_replace('/\s+/', '-', $kunci);

        if ($kunci === '' || preg_match('/^[a-z0-9:_.\-]+$/', $kunci) !== 1 || mb_strlen($kunci) > self::PANJANG_MAKS) {
            return null;
        }

        return $kunci;
    }

    private static function bersihkan(string $profil): string
    {
        return trim((string) preg_replace('/[^a-z0-9_]+/', '-', mb_strtolower($profil, 'UTF-8')), '-');
    }

    private static function potong(string $kunci): string
    {
        return mb_substr($kunci, 0, self::PANJANG_MAKS);
    }
}
