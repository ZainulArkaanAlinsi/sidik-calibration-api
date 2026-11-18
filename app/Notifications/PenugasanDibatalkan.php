<?php

namespace App\Notifications;

use App\Models\Penugasan;

/**
 * Penugasan dibatalkan -- anggotanya dikabari supaya tidak melanjutkan
 * pekerjaan yang sudah tidak berlaku.
 *
 * Pola ditiru dari `PenugasanBaru` (induk `NotifikasiSistem`: lonceng, push,
 * dan halaman notifikasi satu payload). Isinya menyebut ALASAN, karena
 * "dibatalkan" tanpa sebab membuat teknisi bertanya ke admin yang sama.
 */
class PenugasanDibatalkan extends NotifikasiSistem
{
    public function __construct(
        private readonly int $penugasanId,
        private readonly string $judul,
        private readonly string $namaPembatal,
        private readonly string $alasan,
    ) {}

    public static function dariPenugasan(Penugasan $penugasan, string $namaPembatal, string $alasan): self
    {
        return new self($penugasan->id, $penugasan->judul, $namaPembatal, $alasan);
    }

    protected function judul(): string
    {
        return 'Penugasan dibatalkan';
    }

    protected function isi(): string
    {
        return "{$this->judul} dibatalkan oleh {$this->namaPembatal}. Alasan: {$this->alasan}";
    }

    protected function kategori(): string
    {
        return 'penugasan_dibatalkan';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'penugasan', 'id' => $this->penugasanId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-clipboard-document-list';
    }

    protected function warna(): string
    {
        return 'warning';
    }
}
