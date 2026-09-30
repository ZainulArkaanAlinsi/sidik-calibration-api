<?php

namespace App\Notifications;

use App\Models\Penugasan;

/**
 * Teknisi kena penugasan baru.
 *
 * Polanya ditiru apa adanya dari `SesiMenungguApproval` (extends
 * `NotifikasiSistem`, factory `dari*`) — kontrak kelas induknya yang menentukan
 * notifikasi ini muncul di lonceng, di push FCM, dan di panel.
 *
 * Isinya menyebut JUMLAH, bukan cuma judul. "Kalibrasi minggu ini" tidak memberi
 * tahu apa pun yang bisa direncanakan; "10 autoklaf, 4 timbangan — target 30 Sep"
 * bisa langsung dibandingkan dengan sisa harinya.
 */
class PenugasanBaru extends NotifikasiSistem
{
    public function __construct(
        private readonly int $penugasanId,
        private readonly string $judul,
        private readonly string $ringkasanItem,
        private readonly ?string $tanggalTarget,
        private readonly string $namaPembagi,
        private readonly bool $grup,
    ) {}

    public static function dariPenugasan(Penugasan $penugasan, string $namaPembagi): self
    {
        // Maksimal tiga baris disebut; sisanya diringkas. Notifikasi yang memuat
        // 12 baris terpotong di tengah oleh sistem operasi, dan yang terpotong
        // biasanya justru yang terakhir — jadi lebih baik dipotong di sini dengan
        // hitungan yang jujur.
        $item = $penugasan->item->take(3)
            ->map(fn ($i): string => $i->jumlah.' '.$i->jenis_alat)
            ->implode(', ');

        $sisa = $penugasan->item->count() - 3;

        return new self(
            $penugasan->id,
            $penugasan->judul,
            $sisa > 0 ? $item.", +{$sisa} lagi" : $item,
            $penugasan->tanggal_target?->translatedFormat('j M Y'),
            $namaPembagi,
            $penugasan->tipe === Penugasan::TIPE_GRUP,
        );
    }

    protected function judul(): string
    {
        return $this->grup ? 'Penugasan grup baru' : 'Penugasan baru';
    }

    protected function isi(): string
    {
        $target = $this->tanggalTarget !== null ? " — target {$this->tanggalTarget}" : '';

        return "{$this->judul}: {$this->ringkasanItem}{$target}. Dari {$this->namaPembagi}.";
    }

    protected function kategori(): string
    {
        return 'penugasan_baru';
    }

    /** @return array<string, mixed> */
    protected function tautan(): array
    {
        return ['tipe' => 'penugasan', 'id' => $this->penugasanId];
    }

    protected function ikon(): string
    {
        return 'heroicon-o-clipboard-document-list';
    }
}
