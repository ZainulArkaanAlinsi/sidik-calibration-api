<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Satu kelas notifikasi buat semua kejadian di app — yang ngebedain isinya
 * `tipe`, bukan kelasnya.
 *
 * Kenapa nggak satu kelas per kejadian (konvensi Laravel): mobile ngebedain
 * notifikasi lewat field `tipe` di payload, bukan lewat `type` (nama kelas).
 * Bikin 6 kelas yang isinya cuma beda string bakal nambah berkas tanpa nambah
 * kejelasan, dan gampang nyimpang bentuk payload-nya satu sama lain.
 *
 * Bentuk payload dikunci sama kontrak mobile (Permintaan Backend Fase 2 §2):
 * `tipe`, `judul`, `isi`, `tautan`. `tautan` yang bikin notifikasi berguna —
 * mobile bisa langsung buka layar yang dimaksud, bukan cuma nampilin teks.
 *
 * Catatan: lonceng panel Filament nyimpen notifikasinya sendiri di tabel yang
 * SAMA tapi bentuknya beda (`title`/`body`). Dua-duanya diseragamkan waktu
 * dibaca di `NotificationResource`, jadi nggak ada yang perlu dikirim dua kali.
 */
class NotifikasiApp extends Notification
{
    use Queueable;

    /**
     * @param  string  $tipe  pengenal kejadian, mis. `kalibrasi.menunggu_approval`
     * @param  array{jenis: string, id: int}|null  $tautan  layar tujuan di mobile
     */
    public function __construct(
        private readonly string $tipe,
        private readonly string $judul,
        private readonly string $isi,
        private readonly ?array $tautan = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'tipe' => $this->tipe,
            'judul' => $this->judul,
            'isi' => $this->isi,
            'tautan' => $this->tautan,
        ];
    }
}
