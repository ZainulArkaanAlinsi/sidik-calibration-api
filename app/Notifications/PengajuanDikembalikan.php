<?php

namespace App\Notifications;

use App\Models\CalibrationSession;
use Illuminate\Support\Str;

/**
 * Pengesah mengembalikan pengajuan — admin yang perlu tahu.
 *
 * Bedanya dari `SesiPerluRevisi` (yang ke teknisi): yang ini berhenti di admin.
 * Angkanya tidak dipersoalkan; yang dipersoalkan kelengkapan berkas,
 * penandatangan, atau masa berlaku. Salah kirim di sini akibatnya nyata —
 * teknisi membuka lagi lembar kerja yang sudah benar.
 *
 * Alasannya dibawa di badan pesan, dipotong supaya push FCM tidak terpangkas di
 * tengah kata. Versi utuhnya ada di `calibration_sessions` → riwayat audit, dan
 * layar admin menampilkannya penuh.
 */
class PengajuanDikembalikan extends NotifikasiSistem
{
    /** Batas aman satu baris notifikasi di HP, sesudah nomor & nama alat. */
    private const BATAS_ALASAN = 120;

    public function __construct(
        private readonly int $sesiId,
        private readonly string $nomorSesi,
        private readonly string $namaAlat,
        private readonly string $namaPengesah,
        private readonly string $alasan,
    ) {}

    public static function dariSesi(CalibrationSession $sesi, string $namaPengesah, string $alasan): self
    {
        return new self(
            $sesi->id,
            (string) $sesi->nomor_sesi,
            (string) ($sesi->equipment?->nama_alat ?? 'alat'),
            $namaPengesah,
            $alasan,
        );
    }

    protected function judul(): string
    {
        return 'Pengajuan sertifikat dikembalikan';
    }

    protected function isi(): string
    {
        $alasan = Str::limit($this->alasan, self::BATAS_ALASAN);

        return "{$this->nomorSesi} — {$this->namaAlat}. {$this->namaPengesah}: \"{$alasan}\"";
    }

    protected function kategori(): string
    {
        return 'pengajuan_dikembalikan';
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
}
