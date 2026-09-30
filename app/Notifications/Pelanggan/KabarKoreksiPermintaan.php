<?php

namespace App\Notifications\Pelanggan;

use App\Models\KoreksiPelanggan;
use App\Models\PermintaanKalibrasi;
use App\Notifications\NotifikasiSistem;

/**
 * Dua kabar lab → pelanggan yang lahir bersama modul koreksi & jadwal (§42):
 * keputusan atas koreksi data, dan jadwal kunjungan teknisi.
 *
 * Nama orang lab TIDAK disebut (cuma "Tim lab") — sama dengan
 * `PermintaanDiputuskan`. Tanggapan yang ikut adalah teks yang memang ditulis
 * admin untuk dibaca pelanggan.
 */
class KabarKoreksiPermintaan extends NotifikasiSistem
{
    private function __construct(
        private readonly string $jenisKabar,
        private readonly int $idSasaran,
        private readonly string $isiKabar,
    ) {}

    public static function koreksiDiputus(KoreksiPelanggan $koreksi): self
    {
        $diterima = $koreksi->status === KoreksiPelanggan::STATUS_DITERIMA;

        $isi = $diterima
            ? 'Koreksi data yang kamu ajukan diterima tim lab.'
            : 'Koreksi data yang kamu ajukan belum bisa kami terapkan.';

        if ($diterima && $koreksi->revisi !== null) {
            $isi .= " Sertifikat penggantinya: {$koreksi->revisi->nomor}.";
        }

        if (filled($koreksi->tanggapan)) {
            $isi .= " Tanggapan lab: {$koreksi->tanggapan}";
        }

        return new self($diterima ? 'koreksi_diterima' : 'koreksi_ditolak', $koreksi->id, $isi);
    }

    public static function jadwalTeknisi(PermintaanKalibrasi $permintaan): self
    {
        $pada = $permintaan->jadwal_pada?->timezone('Asia/Jakarta')->locale('id')->translatedFormat('l, j F Y · H.i').' WIB';
        $isi = "Teknisi dijadwalkan datang {$pada} untuk {$permintaan->nomor}.";

        if (filled($permintaan->jadwal_lokasi)) {
            $isi .= " Lokasi: {$permintaan->jadwal_lokasi}.";
        }

        return new self('jadwal_teknisi', $permintaan->id, $isi);
    }

    protected function judul(): string
    {
        return match ($this->jenisKabar) {
            'koreksi_diterima' => 'Koreksi data diterima',
            'koreksi_ditolak' => 'Koreksi data ditolak',
            default => 'Teknisi dijadwalkan',
        };
    }

    protected function isi(): string
    {
        return $this->isiKabar;
    }

    protected function kategori(): string
    {
        return 'pelanggan.'.$this->jenisKabar;
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return $this->jenisKabar === 'jadwal_teknisi'
            ? ['tipe' => 'pelanggan_permintaan', 'id' => $this->idSasaran]
            : ['tipe' => 'pelanggan_koreksi', 'id' => $this->idSasaran];
    }

    protected function ikon(): string
    {
        return match ($this->jenisKabar) {
            'koreksi_diterima' => 'heroicon-o-check-circle',
            'koreksi_ditolak' => 'heroicon-o-x-circle',
            default => 'heroicon-o-calendar',
        };
    }

    protected function warna(): string
    {
        return match ($this->jenisKabar) {
            'koreksi_diterima' => 'success',
            'koreksi_ditolak' => 'danger',
            default => 'info',
        };
    }
}
