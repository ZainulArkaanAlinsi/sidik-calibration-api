<?php

namespace App\Notifications\Pelanggan;

use App\Models\Customer;
use App\Notifications\NotifikasiSistem;

/**
 * Perusahaan pelanggan kehilangan PIC utama aktif terakhirnya (REQ-AUTH-11).
 *
 * Dikirim ke admin lab, bukan ke perusahaannya — justru karena di sana sudah
 * tidak ada lagi yang berwenang mengundang siapa pun. Tanpa notifikasi ini,
 * perusahaan itu terkunci diam-diam: stafnya masih bisa melihat data tapi tidak
 * ada yang bisa menambah anggota, dan satu-satunya yang tahu cuma orang yang
 * baru saja pergi.
 *
 * Yang harus dilakukan admin: tunjuk PIC utama baru lewat
 * `POST /api/customers/{id}/undangan` dengan `peran = pic_utama`.
 */
class PerusahaanTanpaPicUtama extends NotifikasiSistem
{
    private function __construct(
        private readonly int $customerId,
        private readonly string $namaPerusahaan,
    ) {}

    public static function dari(Customer $perusahaan): self
    {
        return new self((int) $perusahaan->getKey(), (string) $perusahaan->nama);
    }

    protected function judul(): string
    {
        return 'Perusahaan tanpa PIC utama';
    }

    protected function isi(): string
    {
        return $this->namaPerusahaan.' sudah tidak punya PIC utama aktif. '
            .'Undang PIC utama baru supaya mereka bisa mengelola anggotanya sendiri lagi.';
    }

    protected function kategori(): string
    {
        return 'pelanggan.tanpa_pic_utama';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'customers', 'id' => $this->customerId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-exclamation-triangle';
    }

    protected function warna(): string
    {
        return 'warning';
    }
}
