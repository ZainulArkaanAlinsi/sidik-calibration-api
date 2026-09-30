<?php

namespace App\Notifications\Pelanggan;

use App\Models\PermintaanKalibrasi;
use App\Notifications\NotifikasiSistem;

/**
 * Lab menerima atau menolak permintaan kalibrasi — ke anggota perusahaan.
 *
 * Satu kelas untuk dua keputusan: bentuknya sama, yang beda cuma kalimat dan
 * warna. Alasan penolakan IKUT di isi notifikasi karena pelanggan harus tahu
 * kenapa tanpa membuka aplikasi — tapi itu teks yang memang ditulis admin
 * untuk dibaca pelanggan, bukan catatan internal.
 *
 * Nama admin yang memutuskan TIDAK disebut (hanya "Tim lab"): pelanggan tidak
 * perlu tahu siapa, dan nama orang lab tidak boleh bocor lewat satu field yang
 * tidak sengaja ditambahkan.
 */
class PermintaanDiputuskan extends NotifikasiSistem
{
    private function __construct(
        private readonly int $permintaanId,
        private readonly string $nomor,
        private readonly bool $diterima,
        private readonly ?string $alasan,
    ) {}

    public static function diterima(PermintaanKalibrasi $permintaan): self
    {
        return new self($permintaan->id, $permintaan->nomor, true, null);
    }

    public static function ditolak(PermintaanKalibrasi $permintaan): self
    {
        return new self($permintaan->id, $permintaan->nomor, false, $permintaan->alasan_penolakan);
    }

    protected function judul(): string
    {
        return $this->diterima ? 'Permintaan kalibrasi diterima' : 'Permintaan kalibrasi ditolak';
    }

    protected function isi(): string
    {
        if ($this->diterima) {
            return "Permintaan {$this->nomor} diterima tim lab. Pantau perkembangannya di menu Paket.";
        }

        return "Permintaan {$this->nomor} belum bisa kami proses. Alasan: {$this->alasan}";
    }

    protected function kategori(): string
    {
        return $this->diterima ? 'pelanggan.permintaan_diterima' : 'pelanggan.permintaan_ditolak';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'pelanggan_permintaan', 'id' => $this->permintaanId];
    }

    protected function ikon(): string
    {
        return $this->diterima ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle';
    }

    protected function warna(): string
    {
        return $this->diterima ? 'success' : 'danger';
    }
}
