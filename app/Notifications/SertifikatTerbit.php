<?php

namespace App\Notifications;

use App\Models\Certificate;

/** Sertifikat udah jadi & siap diunduh/dikirim ke pelanggan. */
class SertifikatTerbit extends NotifikasiSistem
{
    public function __construct(
        private readonly int $sertifikatId,
        private readonly ?string $nomor,
        private readonly string $namaAlat,
    ) {}

    public static function dariSertifikat(Certificate $sertifikat): self
    {
        return new self(
            $sertifikat->id,
            $sertifikat->nomor,
            (string) ($sertifikat->session?->equipment?->nama_alat ?? 'alat'),
        );
    }

    protected function judul(): string
    {
        return 'Sertifikat terbit';
    }

    protected function isi(): string
    {
        return "{$this->nomor} — {$this->namaAlat}. PDF-nya sudah bisa diunduh.";
    }

    protected function kategori(): string
    {
        return 'sertifikat_terbit';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'certificate', 'id' => $this->sertifikatId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-document-check';
    }

    protected function warna(): string
    {
        return 'success';
    }
}
