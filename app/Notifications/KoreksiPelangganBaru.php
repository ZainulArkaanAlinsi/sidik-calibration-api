<?php

namespace App\Notifications;

use App\Models\KoreksiPelanggan;

/**
 * Pelanggan mengajukan koreksi data alat/sertifikat — ke SEMUA admin aktif
 * lab (sama dengan permintaan kalibrasi, §41.1 butir 1).
 */
class KoreksiPelangganBaru extends NotifikasiSistem
{
    private function __construct(
        private readonly int $koreksiId,
        private readonly string $namaPerusahaan,
        private readonly string $sasaran,
    ) {}

    public static function dari(KoreksiPelanggan $koreksi): self
    {
        $koreksi->loadMissing(['customer:id,nama', 'equipment:id,nama_alat', 'certificate:id,nomor']);

        $sasaran = $koreksi->jenis === KoreksiPelanggan::JENIS_SERTIFIKAT
            ? 'sertifikat '.($koreksi->certificate?->nomor ?? '')
            : 'alat '.($koreksi->equipment?->nama_alat ?? '');

        return new self($koreksi->id, (string) ($koreksi->customer?->nama ?? 'Pelanggan'), trim($sasaran));
    }

    protected function judul(): string
    {
        return 'Permintaan koreksi data';
    }

    protected function isi(): string
    {
        return "{$this->namaPerusahaan} minta koreksi data {$this->sasaran}. Menunggu ditinjau.";
    }

    protected function kategori(): string
    {
        return 'koreksi_pelanggan_baru';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'koreksi_pelanggan', 'id' => $this->koreksiId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-pencil-square';
    }

    protected function warna(): string
    {
        return 'warning';
    }
}
