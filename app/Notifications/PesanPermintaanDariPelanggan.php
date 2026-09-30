<?php

namespace App\Notifications;

use App\Models\PermintaanKalibrasi;

/**
 * Pelanggan membalas di utas permintaannya — dikabarkan ke semua admin aktif.
 *
 * Tanpa ini utas dua arah jadi satu arah: lab baru tahu ada pertanyaan kalau
 * kebetulan membuka permintaannya. Isi pesan tidak ikut di notifikasi (layar
 * kunci); cukup "ada pesan baru".
 */
class PesanPermintaanDariPelanggan extends NotifikasiSistem
{
    private function __construct(
        private readonly int $permintaanId,
        private readonly string $nomor,
        private readonly string $namaPerusahaan,
    ) {}

    public static function dari(PermintaanKalibrasi $permintaan): self
    {
        return new self(
            $permintaan->id,
            $permintaan->nomor,
            (string) ($permintaan->customer?->nama ?? 'Pelanggan'),
        );
    }

    protected function judul(): string
    {
        return 'Pesan baru dari pelanggan';
    }

    protected function isi(): string
    {
        return "{$this->namaPerusahaan} menulis pesan di permintaan {$this->nomor}.";
    }

    protected function kategori(): string
    {
        return 'permintaan_pesan';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'permintaan_pelanggan', 'id' => $this->permintaanId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }
}
