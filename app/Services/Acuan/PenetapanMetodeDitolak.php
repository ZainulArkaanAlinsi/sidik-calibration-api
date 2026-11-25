<?php

namespace App\Services\Acuan;

use RuntimeException;

/**
 * Penulisan penetapan keputusan metode ditolak aturan alur (D3 Studio Tahap 1).
 *
 * Dijawab `{message, kode, errors?}`: `kode` bentuk galat domain yang sudah
 * dipakai alur Data Acuan (`AlurVersiAcuanDitolak`), dan `errors` (bila `$field`
 * terisi) bentuk validasi Laravel supaya klien yang sudah menampilkan galat per
 * kolom tidak butuh cabang baru.
 */
final class PenetapanMetodeDitolak extends RuntimeException
{
    /** @param  array<string, mixed>  $rincian */
    public function __construct(
        public readonly string $kode,
        string $pesan,
        public readonly ?string $field = null,
        public readonly array $rincian = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($pesan);
    }

    /** @return array<string, mixed> */
    public function badan(): array
    {
        return [
            'message' => $this->getMessage(),
            'kode' => $this->kode,
            ...($this->field === null ? [] : ['errors' => [$this->field => [$this->getMessage()]]]),
            ...($this->rincian === [] ? [] : ['rincian' => $this->rincian]),
        ];
    }
}
