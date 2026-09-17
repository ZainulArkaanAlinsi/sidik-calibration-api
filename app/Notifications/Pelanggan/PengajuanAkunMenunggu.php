<?php

namespace App\Notifications\Pelanggan;

use App\Models\User;
use App\Notifications\NotifikasiSistem;

/**
 * Ada pengajuan akun pelanggan yang menunggu ditinjau admin (REQ-AUTH-02).
 *
 * Dikirim SESUDAH OTP-nya cocok, bukan waktu orangnya menekan "Daftar". Kalau
 * dikirim lebih awal, siapa pun bisa membanjiri lonceng admin dengan mengetik
 * email orang lain — dan banjir itu melatih admin berhenti membaca isinya,
 * kelas kegagalan yang sama dengan peringatan sesi palsu.
 *
 * Isinya nama perusahaan yang DIKLAIM pendaftar, bukan nama pelanggan yang
 * sudah ada di database. Itu memang klaim sampai admin menautkannya (R-D02),
 * dan menampilkannya sebagai nama pelanggan yang sudah pasti justru menuntun
 * admin menyetujui tanpa memeriksa.
 */
class PengajuanAkunMenunggu extends NotifikasiSistem
{
    public function __construct(
        private readonly int $userId,
        private readonly string $nama,
        private readonly string $namaPerusahaanDiklaim,
        private readonly ?string $jabatan = null,
    ) {}

    public static function dariUser(User $user): self
    {
        return new self(
            (int) $user->getKey(),
            (string) $user->name,
            (string) ($user->pengajuanAkun?->nama_perusahaan ?? '—'),
            $user->jabatan,
        );
    }

    protected function judul(): string
    {
        return 'Pengajuan akun pelanggan menunggu ditinjau';
    }

    protected function isi(): string
    {
        return implode(' · ', array_filter([
            $this->nama,
            $this->jabatan,
            'mengaku dari '.$this->namaPerusahaanDiklaim,
        ]));
    }

    protected function kategori(): string
    {
        return 'pelanggan.pengajuan_menunggu';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return [
            'tipe' => 'pengajuan-akun',
            'filter' => 'menunggu',
            'id' => $this->userId,
        ];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-building-office-2';
    }

    protected function warna(): string
    {
        return 'warning';
    }
}
