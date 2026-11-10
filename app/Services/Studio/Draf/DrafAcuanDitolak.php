<?php

namespace App\Services\Studio\Draf;

use RuntimeException;

/**
 * Simpan draf data acuan dari Studio ditolak aturan alur (rencana A1) --
 * dijawab `{message, kode, rincian?}` dengan status HTTP-nya. Pola
 * `AlurVersiAcuanDitolak`.
 *
 * Dilempar dari DALAM transaksi: melemparnya membatalkan seluruh tulis.
 */
final class DrafAcuanDitolak extends RuntimeException
{
    /** @param  array<string, mixed>  $rincian */
    public function __construct(
        public readonly string $kode,
        string $pesan,
        public readonly array $rincian = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($pesan);
    }
}
