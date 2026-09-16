<?php

namespace App\Notifications\Pelanggan;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\User;
use App\Notifications\NotifikasiSistem;

/**
 * PIC utama dikabari ada anggota baru di perusahaannya (REQ-AUTH-04 kalimat 2).
 *
 * Bukan basa-basi: anggota baru bisa melihat SELURUH alat, sertifikat, dan
 * permintaan perusahaan. PIC utama yang tidak tahu siapa saja yang masuk tidak
 * punya kesempatan menonaktifkan orang yang seharusnya tidak di sana — dan
 * REQ-ANG-02 memberinya wewenang itu justru supaya dipakai.
 */
class AnggotaBaruBergabung extends NotifikasiSistem
{
    private function __construct(
        private readonly int $userId,
        private readonly string $nama,
        private readonly string $namaPerusahaan,
        private readonly string $peran,
    ) {}

    public static function dari(User $orang, Customer $pelanggan, CustomerMember $anggota): self
    {
        return new self(
            (int) $orang->getKey(),
            (string) $orang->name,
            (string) $pelanggan->nama,
            (string) $anggota->peran,
        );
    }

    protected function judul(): string
    {
        return 'Anggota baru di '.$this->namaPerusahaan;
    }

    protected function isi(): string
    {
        $sebutan = $this->peran === CustomerMember::PERAN_PIC_UTAMA ? 'PIC utama' : 'staf';

        return "{$this->nama} bergabung sebagai {$sebutan}. Dia bisa melihat seluruh alat & sertifikat perusahaan.";
    }

    protected function kategori(): string
    {
        return 'pelanggan.anggota_baru';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'anggota', 'id' => $this->userId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-user-group';
    }

    protected function warna(): string
    {
        return 'info';
    }
}
