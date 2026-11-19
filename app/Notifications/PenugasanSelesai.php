<?php

namespace App\Notifications;

use App\Models\Penugasan;

/**
 * Penugasan ditutup (`selesai`) -- anggotanya dikabari bahwa pekerjaannya
 * sudah dinyatakan tuntas dan tidak perlu dilaporkan lagi.
 *
 * Pola ditiru dari `PenugasanDibatalkan` (induk `NotifikasiSistem`: lonceng,
 * push, dan halaman notifikasi satu payload). Tanpa alasan: menutup penugasan
 * yang tuntas tidak perlu dijelaskan, berbeda dengan membatalkan atau membuka
 * lagi.
 */
class PenugasanSelesai extends NotifikasiSistem
{
    public function __construct(
        private readonly int $penugasanId,
        private readonly string $judul,
        private readonly string $namaPenutup,
    ) {}

    public static function dariPenugasan(Penugasan $penugasan, string $namaPenutup): self
    {
        return new self($penugasan->id, $penugasan->judul, $namaPenutup);
    }

    protected function judul(): string
    {
        return 'Penugasan selesai';
    }

    protected function isi(): string
    {
        return "{$this->judul} dinyatakan selesai oleh {$this->namaPenutup}.";
    }

    protected function kategori(): string
    {
        return 'penugasan_selesai';
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
        return 'success';
    }
}
