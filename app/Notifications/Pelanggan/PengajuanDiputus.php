<?php

namespace App\Notifications\Pelanggan;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\PengajuanAkunPelanggan;
use App\Notifications\NotifikasiSistem;

/**
 * Keputusan admin atas pengajuan akun, dikirim ke pemohonnya
 * (REQ-AUTH-04 & REQ-AUTH-05).
 *
 * Satu kelas buat dua keputusan, bukan dua kelas: yang dibaca aplikasi sama —
 * layar S06 yang menampilkan status pengajuan — dan memisahkannya bikin layar
 * itu harus tahu dua kategori notifikasi buat satu hal yang sama.
 *
 * Alasan penolakan IKUT di badan notifikasi, bukan cuma di `GET /saya`. Kalau
 * tidak, orang yang ditolak dapat lonceng "pengajuan diputus" lalu harus
 * membuka layar lain buat tahu kenapa — dan yang terjadi berikutnya dia
 * menelepon lab.
 */
class PengajuanDiputus extends NotifikasiSistem
{
    private function __construct(
        private readonly int $pengajuanId,
        private readonly bool $disetujui,
        private readonly ?string $namaPerusahaan = null,
        private readonly ?string $peran = null,
        private readonly ?string $alasanTolak = null,
    ) {}

    public static function disetujui(?PengajuanAkunPelanggan $pengajuan, Customer $pelanggan, CustomerMember $anggota): self
    {
        return new self(
            (int) ($pengajuan?->getKey() ?? 0),
            true,
            (string) $pelanggan->nama,
            (string) $anggota->peran,
        );
    }

    public static function ditolak(?PengajuanAkunPelanggan $pengajuan): self
    {
        return new self(
            (int) ($pengajuan?->getKey() ?? 0),
            false,
            alasanTolak: $pengajuan?->alasan_tolak,
        );
    }

    protected function judul(): string
    {
        return $this->disetujui
            ? 'Akun Anda sudah aktif'
            : 'Pengajuan akun belum bisa disetujui';
    }

    protected function isi(): string
    {
        if ($this->disetujui) {
            $sebutan = $this->peran === CustomerMember::PERAN_PIC_UTAMA ? 'PIC utama' : 'staf';

            return "Anda terdaftar sebagai {$sebutan} di {$this->namaPerusahaan}.";
        }

        return $this->alasanTolak ?: 'Silakan hubungi PT Sidik untuk keterangan lebih lanjut.';
    }

    protected function kategori(): string
    {
        return $this->disetujui ? 'pelanggan.pengajuan_disetujui' : 'pelanggan.pengajuan_ditolak';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'pengajuan-akun', 'id' => $this->pengajuanId];
    }

    protected function ikon(): string
    {
        return $this->disetujui ? 'heroicon-o-check-badge' : 'heroicon-o-x-circle';
    }

    protected function warna(): string
    {
        return $this->disetujui ? 'success' : 'danger';
    }
}
