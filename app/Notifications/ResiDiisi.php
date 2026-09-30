<?php

namespace App\Notifications;

use App\Models\PermintaanKalibrasi;

/** Pelanggan mengisi nomor resi pengiriman alat — ke semua admin aktif lab. */
class ResiDiisi extends NotifikasiSistem
{
    private function __construct(
        private readonly int $permintaanId,
        private readonly string $nomor,
        private readonly string $namaPerusahaan,
        private readonly string $resi,
    ) {}

    public static function dari(PermintaanKalibrasi $permintaan): self
    {
        $permintaan->loadMissing('customer:id,nama');

        return new self(
            $permintaan->id,
            $permintaan->nomor,
            (string) ($permintaan->customer?->nama ?? 'Pelanggan'),
            trim(($permintaan->kurir ?? '').' '.($permintaan->nomor_resi ?? '')),
        );
    }

    protected function judul(): string
    {
        return 'Alat dalam pengiriman';
    }

    protected function isi(): string
    {
        return "{$this->namaPerusahaan} mengirim alat untuk {$this->nomor}. Resi: {$this->resi}.";
    }

    protected function kategori(): string
    {
        return 'resi_diisi';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'permintaan_pelanggan', 'id' => $this->permintaanId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-truck';
    }

    protected function warna(): string
    {
        return 'info';
    }
}
