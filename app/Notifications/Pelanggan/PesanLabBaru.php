<?php

namespace App\Notifications\Pelanggan;

use App\Models\PermintaanKalibrasi;
use App\Notifications\NotifikasiSistem;

/**
 * Admin lab membalas di utas permintaan — ke anggota perusahaan.
 *
 * Isi pesan sengaja TIDAK dikirim di notifikasi: push mendarat di layar kunci
 * dan terbaca siapa pun yang memegang HP-nya. Yang sampai cuma "ada balasan";
 * isinya ditarik lewat `GET /permintaan/{id}/pesan` yang ber-otorisasi.
 */
class PesanLabBaru extends NotifikasiSistem
{
    private function __construct(
        private readonly int $permintaanId,
        private readonly string $nomor,
    ) {}

    public static function dari(PermintaanKalibrasi $permintaan): self
    {
        return new self($permintaan->id, $permintaan->nomor);
    }

    protected function judul(): string
    {
        return 'Balasan dari tim lab';
    }

    protected function isi(): string
    {
        return "Ada pesan baru di permintaan {$this->nomor}.";
    }

    protected function kategori(): string
    {
        return 'pelanggan.pesan_lab';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'pelanggan_permintaan', 'id' => $this->permintaanId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }
}
