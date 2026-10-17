<?php

namespace App\Services\Studio\Workbook;

use RuntimeException;
use Throwable;

/**
 * Workbook tidak bisa dibaca. `kode` stabil (kamus galat 03-SDD §8), pesannya
 * Bahasa Indonesia dan TIDAK PERNAH memuat sandi.
 */
final class GagalBacaWorkbook extends RuntimeException
{
    public const SANDI_SALAH = 'sandi_workbook_salah';

    public const PROFIL_ENKRIPSI_DITOLAK = 'profil_enkripsi_ditolak';

    public const BUKAN_OOXML = 'berkas_bukan_ooxml';

    public const BERKAS_RUSAK = 'berkas_rusak';

    public const GAGAL_MUAT = 'gagal_muat';

    public function __construct(public readonly string $kode, string $pesan, ?Throwable $sebab = null)
    {
        parent::__construct($pesan, 0, $sebab);
    }
}
