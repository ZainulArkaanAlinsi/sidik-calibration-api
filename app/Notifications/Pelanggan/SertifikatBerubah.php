<?php

namespace App\Notifications\Pelanggan;

use App\Models\Certificate;
use App\Notifications\NotifikasiSistem;

/**
 * Sertifikat milik perusahaan direvisi atau dibatalkan lab — ke anggota aktif.
 *
 * Isinya cuma `catatan_pelanggan` (teks yang memang ditulis admin untuk
 * dibaca pelanggan). Alasan internal TIDAK PERNAH ikut (D4, REQ-SRT-05), dan
 * nama orang lab tidak disebut — cukup "lab".
 */
class SertifikatBerubah extends NotifikasiSistem
{
    private function __construct(
        private readonly int $sertifikatId,
        private readonly string $nomor,
        private readonly ?string $nomorLama,
        private readonly bool $dibatalkan,
        private readonly ?string $catatan,
    ) {}

    /** `$revisi` = baris revisi yang baru terbit. */
    public static function direvisi(Certificate $revisi): self
    {
        $revisi->loadMissing('revisionOf:id,nomor');

        return new self(
            $revisi->id,
            (string) $revisi->nomor,
            $revisi->revisionOf?->nomor,
            false,
            $revisi->catatan_pelanggan,
        );
    }

    public static function dibatalkan(Certificate $sertifikat): self
    {
        return new self($sertifikat->id, (string) $sertifikat->nomor, null, true, $sertifikat->catatan_pelanggan);
    }

    protected function judul(): string
    {
        return $this->dibatalkan ? 'Sertifikat dibatalkan' : 'Sertifikat direvisi';
    }

    protected function isi(): string
    {
        $kalimat = $this->dibatalkan
            ? "Sertifikat {$this->nomor} dibatalkan lab dan tidak berlaku lagi."
            : "Sertifikat {$this->nomorLama} sudah direvisi. Yang berlaku sekarang {$this->nomor}.";

        return filled($this->catatan) ? "{$kalimat} Catatan lab: {$this->catatan}" : $kalimat;
    }

    protected function kategori(): string
    {
        return $this->dibatalkan ? 'pelanggan.sertifikat_dibatalkan' : 'pelanggan.sertifikat_direvisi';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'pelanggan_sertifikat', 'id' => $this->sertifikatId];
    }

    protected function ikon(): string
    {
        return $this->dibatalkan ? 'heroicon-o-x-circle' : 'heroicon-o-document-duplicate';
    }

    protected function warna(): string
    {
        return $this->dibatalkan ? 'danger' : 'warning';
    }
}
