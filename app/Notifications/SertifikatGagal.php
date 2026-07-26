<?php

namespace App\Notifications;

use App\Models\Certificate;
use Illuminate\Support\Str;

/**
 * Pembuatan sertifikat GAGAL (fase-2 §2).
 *
 * Kegagalan paling sunyi di sistem ini. Sesinya udah disetujui, teknisi udah
 * dikabarin "disetujui", tapi PDF-nya nggak pernah jadi — statusnya cuma diem di
 * `gagal` dan `Log::error`. Satu-satunya cara ketahuan itu kalau ada yang
 * kebetulan buka layar sertifikatnya. Sementara itu pelanggan nungguin dokumen
 * yang, dari sisi lab, kelihatan udah selesai.
 *
 * Sengaja dikirim tiap kali gagal, termasuk waktu retry gagal lagi: kegagalan
 * kedua itu kabar baru (berarti bukan gangguan sesaat), bukan pengulangan.
 */
class SertifikatGagal extends NotifikasiSistem
{
    /** Pesan error dipotong: yang panjang bikin lonceng nggak kebaca. */
    private const MAKS_ALASAN = 160;

    public function __construct(
        private readonly int $sertifikatId,
        private readonly ?string $nomor,
        private readonly ?string $nomorSesi,
        private readonly ?string $alasan = null,
    ) {}

    public static function dariSertifikat(Certificate $sertifikat, ?string $alasan = null): self
    {
        return new self(
            $sertifikat->id,
            $sertifikat->nomor,
            $sertifikat->session?->nomor_sesi,
            $alasan,
        );
    }

    protected function judul(): string
    {
        return 'Sertifikat gagal dibuat'.($this->nomor ? " — {$this->nomor}" : '');
    }

    protected function isi(): string
    {
        $bagian = array_filter([
            $this->nomorSesi ? "Sesi {$this->nomorSesi}" : null,
            // Alasan teknisnya ikut: ini kebaca admin lab (pemilik sistemnya
            // sendiri), dan tanpa itu dia nggak bisa mutusin ini cukup di-retry
            // atau perlu diteruskan ke yang ngurus server.
            $this->alasan ? Str::limit($this->alasan, self::MAKS_ALASAN) : null,
            'Coba terbitkan ulang dari layar sertifikat.',
        ]);

        return implode(' · ', $bagian);
    }

    protected function kategori(): string
    {
        return 'sertifikat.gagal';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        // Nunjuk sertifikatnya langsung — di situ tombol "Terbitkan ulang"-nya.
        return [
            'tipe' => 'certificates',
            'id' => $this->sertifikatId,
        ];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-document-minus';
    }

    protected function warna(): string
    {
        return 'danger';
    }
}
