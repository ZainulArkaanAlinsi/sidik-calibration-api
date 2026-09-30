<?php

namespace App\Notifications;

use App\Models\PermintaanKalibrasi;

/**
 * Pelanggan mengajukan kalibrasi — dikabarkan ke SEMUA admin aktif lab.
 *
 * Keputusan pemilik proyek (30 Sep): ajuan masuk ke semua admin dan siapa pun
 * boleh memprosesnya. Makanya tidak ada "ditugaskan ke"; yang lebih dulu
 * menerima/menolak yang menang, dan yang kedua dijawab 422 (sudah diputuskan).
 *
 * Kerahasiaan: notifikasi ini ke orang lab, jadi nama perusahaan boleh
 * disebut. Yang TIDAK ikut adalah isi catatan pelanggan — push mendarat di
 * layar kunci, dan isinya ditarik lewat REST setelah dibuka.
 */
class PermintaanKalibrasiBaru extends NotifikasiSistem
{
    private function __construct(
        private readonly int $permintaanId,
        private readonly string $nomor,
        private readonly string $namaPerusahaan,
        private readonly int $jumlahAlat,
        private readonly string $metode,
    ) {}

    public static function dari(PermintaanKalibrasi $permintaan): self
    {
        return new self(
            $permintaan->id,
            $permintaan->nomor,
            (string) ($permintaan->customer?->nama ?? 'Pelanggan'),
            $permintaan->items->count(),
            $permintaan->metode_pengantaran,
        );
    }

    protected function judul(): string
    {
        return 'Permintaan kalibrasi baru';
    }

    protected function isi(): string
    {
        $cara = $this->metode === PermintaanKalibrasi::METODE_DIAMBIL_LAB
            ? 'minta diambil lab'
            : 'diantar sendiri';

        return "{$this->namaPerusahaan} mengajukan {$this->jumlahAlat} alat ({$this->nomor}), {$cara}. Menunggu ditinjau.";
    }

    protected function kategori(): string
    {
        return 'permintaan_baru';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'permintaan_pelanggan', 'id' => $this->permintaanId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-inbox-arrow-down';
    }

    protected function warna(): string
    {
        return 'warning';
    }
}
