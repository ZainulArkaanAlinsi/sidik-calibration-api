<?php

namespace App\Notifications;

use App\Models\CalibrationSession;
use Illuminate\Support\Str;

/** Sesi ditolak admin — teknisi harus tau APA yang mesti dibenerin. */
class SesiPerluRevisi extends NotifikasiSistem
{
    public function __construct(
        private readonly int $sesiId,
        private readonly string $nomorSesi,
        private readonly ?string $catatanRevisi,
    ) {}

    public static function dariSesi(CalibrationSession $sesi): self
    {
        return new self($sesi->id, (string) $sesi->nomor_sesi, $sesi->catatan_revisi);
    }

    protected function judul(): string
    {
        return 'Sesi kalibrasi perlu revisi';
    }

    protected function isi(): string
    {
        // Catatan revisi bisa panjang; yang di notifikasi cukup pengantar,
        // lengkapnya dibaca di layar sesinya.
        $catatan = $this->catatanRevisi ? ' '.Str::limit($this->catatanRevisi, 120) : '';

        return "{$this->nomorSesi} dikembalikan admin.{$catatan}";
    }

    protected function kategori(): string
    {
        return 'sesi_perlu_revisi';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'calibration', 'id' => $this->sesiId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-arrow-uturn-left';
    }

    protected function warna(): string
    {
        return 'warning';
    }
}
