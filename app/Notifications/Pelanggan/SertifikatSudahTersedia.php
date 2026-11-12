<?php

namespace App\Notifications\Pelanggan;

use App\Notifications\NotifikasiSistem;

/**
 * "Sertifikat perusahaan Anda sudah tersedia" — kabar SUSULAN untuk anggota
 * yang baru bergabung (diundang/diaktifkan SESUDAH sertifikatnya terbit).
 *
 * `SertifikatAndaTerbit` hanya menjangkau anggota yang sudah aktif saat
 * sertifikat terbit; anggota yang masuk belakangan tidak pernah dikabari,
 * padahal sertifikat perusahaannya terbuka untuknya (isolasi per perusahaan,
 * bukan per tanggal masuk).
 *
 * Isinya netral dan hanya JUMLAH: push mendarat di layar kunci, jadi tidak ada
 * nomor sertifikat, nama alat, nama perusahaan, atau hasil di sini. Tidak ada
 * tautan ke sertifikat tertentu (kosong = buka beranda); rinciannya ditarik app
 * lewat REST ber-otorisasi.
 *
 * Tanda tangannya per (perusahaan) dan dipakai `KabarSertifikatUntukAnggotaBaru`
 * agar susulan ini terkirim SEKALI per orang per perusahaan.
 */
class SertifikatSudahTersedia extends NotifikasiSistem
{
    private function __construct(
        private readonly int $customerId,
        private readonly int $jumlah,
    ) {}

    public static function untuk(int $customerId, int $jumlah): self
    {
        return new self($customerId, $jumlah);
    }

    /** Nilai `tanda_tangan` di `notifications.data` untuk susulan perusahaan ini. */
    public static function tandaTanganUntuk(int $customerId): string
    {
        return 'pelanggan.sertifikat_tersedia:'.$customerId;
    }

    protected function tandaTangan(): ?string
    {
        return self::tandaTanganUntuk($this->customerId);
    }

    protected function judul(): string
    {
        return 'Sertifikat kalibrasi sudah tersedia';
    }

    protected function isi(): string
    {
        return $this->jumlah === 1
            ? '1 sertifikat kalibrasi perusahaan Anda sudah bisa dibuka di aplikasi.'
            : "{$this->jumlah} sertifikat kalibrasi perusahaan Anda sudah bisa dibuka di aplikasi.";
    }

    protected function kategori(): string
    {
        return 'pelanggan.sertifikat_tersedia';
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
