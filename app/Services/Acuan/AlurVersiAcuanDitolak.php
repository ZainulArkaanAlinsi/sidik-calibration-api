<?php

namespace App\Services\Acuan;

use RuntimeException;

/** Transisi versi data acuan ditolak aturan alur — dijawab `{message, kode, rincian}`. */
final class AlurVersiAcuanDitolak extends RuntimeException
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
