<?php

namespace App\Notifications;

use App\Models\CalibrationSession;

/**
 * Konfirmasi ke teknisi kalau sesinya disetujui (spesifikasi poin 6).
 *
 * Ini notifikasi yang paling ditunggu teknisi: tanpa ini, satu-satunya cara tau
 * kerjaannya diterima atau nggak ya buka daftar sesi berkali-kali.
 */
class SesiDisetujui extends NotifikasiSistem
{
    public function __construct(
        private readonly int $sesiId,
        private readonly string $nomorSesi,
        private readonly string $namaAlat,
        private readonly ?string $keputusan,
    ) {}

    public static function dariSesi(CalibrationSession $sesi): self
    {
        return new self(
            $sesi->id,
            (string) $sesi->nomor_sesi,
            (string) ($sesi->equipment?->nama_alat ?? 'alat'),
            $sesi->keputusan,
        );
    }

    protected function judul(): string
    {
        return 'Sesi kalibrasi disetujui';
    }

    protected function isi(): string
    {
        $hasil = $this->keputusan ? " Hasil: {$this->keputusan}." : '';

        return "{$this->nomorSesi} — {$this->namaAlat} sudah disetujui admin.{$hasil} Sertifikat lagi diproses.";
    }

    protected function kategori(): string
    {
        return 'sesi_disetujui';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'calibration', 'id' => $this->sesiId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-check-badge';
    }

    protected function warna(): string
    {
        // FAIL tetap "disetujui" — tapi jangan dikasih warna hijau, biar
        // teknisi nggak salah baca sekilas.
        return $this->keputusan === 'FAIL' ? 'warning' : 'success';
    }
}
