<?php

namespace App\Notifications;

use App\Models\CalibrationSession;

/** Teknisi baru nyubmit sesi — admin yang perlu tau, biar antreannya jalan. */
class SesiMenungguApproval extends NotifikasiSistem
{
    public function __construct(
        private readonly int $sesiId,
        private readonly string $nomorSesi,
        private readonly string $namaAlat,
        private readonly string $namaTeknisi,
    ) {}

    public static function dariSesi(CalibrationSession $sesi): self
    {
        return new self(
            $sesi->id,
            (string) $sesi->nomor_sesi,
            (string) ($sesi->equipment?->nama_alat ?? 'alat'),
            (string) ($sesi->teknisi?->name ?? 'Teknisi'),
        );
    }

    protected function judul(): string
    {
        return 'Sesi kalibrasi menunggu persetujuan';
    }

    protected function isi(): string
    {
        return "{$this->nomorSesi} — {$this->namaAlat}, dikerjakan {$this->namaTeknisi}.";
    }

    protected function kategori(): string
    {
        return 'sesi_menunggu_approval';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'calibration', 'id' => $this->sesiId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-inbox-arrow-down';
    }
}
