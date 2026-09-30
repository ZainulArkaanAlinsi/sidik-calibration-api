<?php

namespace App\Notifications;

use App\Models\CalibrationSession;

/**
 * Admin sudah mengajukan — pengesah yang perlu tahu, biar antreannya jalan.
 *
 * Kembaran `SesiMenungguApproval`, satu langkah lebih ke hilir. Polanya ditiru
 * apa adanya dari sana (extends `NotifikasiSistem`, factory `dariSesi`), bukan
 * dikarang ulang: yang menentukan notifikasi ini muncul di lonceng, di push
 * FCM, dan di panel Filament adalah kontrak kelas induknya.
 *
 * Kenapa notifikasi ini WAJIB ada, bukan tambahan: gerbang pengesahan
 * memindahkan penerbitan sertifikat ke meja orang yang TIDAK sedang menunggu
 * di depan layar. Tanpa satu pesan yang menyusul ke HP-nya, akibat paling
 * mungkin dari gerbang ini bukan kontrol yang lebih baik — tapi sertifikat yang
 * tertahan tiga hari karena tidak ada yang tahu ada yang mengantre.
 */
class SesiPerluDisahkan extends NotifikasiSistem
{
    public function __construct(
        private readonly int $sesiId,
        private readonly string $nomorSesi,
        private readonly string $namaAlat,
        private readonly string $namaPengaju,
        private readonly ?string $keputusan,
    ) {}

    public static function dariSesi(CalibrationSession $sesi, string $namaPengaju): self
    {
        return new self(
            $sesi->id,
            (string) $sesi->nomor_sesi,
            (string) ($sesi->equipment?->nama_alat ?? 'alat'),
            $namaPengaju,
            $sesi->keputusan,
        );
    }

    protected function judul(): string
    {
        return 'Sertifikat menunggu pengesahan';
    }

    protected function isi(): string
    {
        // Keputusan FAIL disebut di depan. Sertifikat FAIL tetap sah dan tetap
        // terbit, tapi dia yang paling sering perlu ditelepon ke pelanggan dulu
        // — dan pengesah sebaiknya tahu itu sebelum membuka, bukan sesudah.
        $vonis = $this->keputusan === 'FAIL' ? ' [FAIL]' : '';

        return "{$this->nomorSesi} — {$this->namaAlat}{$vonis}, diajukan {$this->namaPengaju}.";
    }

    protected function kategori(): string
    {
        return 'sesi_perlu_disahkan';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'calibration', 'id' => $this->sesiId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-shield-check';
    }
}
