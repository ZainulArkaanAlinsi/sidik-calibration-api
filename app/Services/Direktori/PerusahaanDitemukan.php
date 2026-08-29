<?php

namespace App\Services\Direktori;

/**
 * Satu perusahaan dari hasil pencarian direktori luar.
 *
 * Cuma tiga field, dan itu disengaja. Direktori tempat usaha punya belasan
 * field lain (telepon, jam buka, rating, foto) yang nggak satu pun dipakai
 * sertifikat — menariknya cuma nambah biaya per request dan nambah data pihak
 * ketiga yang nyangkut di database lab tanpa ada yang minta.
 */
final readonly class PerusahaanDitemukan
{
    public function __construct(
        /**
         * Id tempat menurut direktorinya. Dipakai buat mengenali perusahaan yang
         * sama dipilih dua kali, tanpa harus mengadu ejaan nama.
         */
        public string $ref,
        public string $nama,
        public ?string $alamat,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['ref' => $this->ref, 'nama' => $this->nama, 'alamat' => $this->alamat];
    }
}
