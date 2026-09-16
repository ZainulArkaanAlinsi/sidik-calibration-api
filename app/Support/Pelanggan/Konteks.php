<?php

namespace App\Support\Pelanggan;

use App\Models\CustomerMember;

/**
 * "Request ini atas nama perusahaan yang mana, dan sebagai siapa?" (03-SDD §3.3).
 *
 * Objek kecil yang disuntikkan per request oleh `KonteksPerusahaan`, lalu dibaca
 * middleware peran dan seluruh controller pelanggan.
 *
 * ## Kenapa objek, bukan `$request->user()->keanggotaan->first()`
 *
 * Karena jawabannya TIDAK selalu "yang pertama". Konsultan bisa jadi anggota
 * tiga pabrik (REQ-ANG-04), dan yang menentukan header `X-Perusahaan-Id`.
 * Controller yang memanggil `->first()` sendiri akan benar di 95% kasus dan
 * diam-diam salah di sisanya — mengambilkan data pabrik A waktu orangnya sedang
 * membuka pabrik B. Itu kebocoran antar-pelanggan, bukan bug tampilan.
 *
 * `readonly` supaya tidak ada yang bisa menukar konteksnya di tengah request.
 */
final class Konteks
{
    public function __construct(
        public readonly int $customerId,
        public readonly int $memberId,
        public readonly string $peran,
    ) {}

    public static function dari(CustomerMember $anggota): self
    {
        return new self(
            (int) $anggota->customer_id,
            (int) $anggota->getKey(),
            (string) $anggota->peran,
        );
    }

    public function picUtama(): bool
    {
        return $this->peran === CustomerMember::PERAN_PIC_UTAMA;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'member_id' => $this->memberId,
            'peran' => $this->peran,
        ];
    }
}
