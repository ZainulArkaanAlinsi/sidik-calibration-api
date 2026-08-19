<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use App\Services\Push\PengirimPush;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Saluran notifikasi `push` — kirim ke semua perangkat milik penerima.
 *
 * Jadi saluran Laravel (bukan dipanggil manual dari tiap tempat) supaya
 * notifikasi yang sudah ada nggak perlu disentuh satu-satu: cukup `push` ikut
 * di `via()`, dan sembilan notifikasi turunan `NotifikasiSistem` langsung ikut
 * terkirim. Kalau dipanggil manual, cepat atau lambat ada satu jalur yang
 * kelewat, dan yang ketahuan bukan errornya — cuma satu jenis kabar yang
 * diam-diam nggak pernah sampai ke HP.
 */
class SaluranPush
{
    public function __construct(private readonly PengirimPush $pengirim) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        /** @var array{judul: string, isi: string, data?: array<string, string>} $isi */
        $isi = $notification->toPush($notifiable);

        $perangkat = DeviceToken::query()
            ->where('user_id', $notifiable->getKey())
            ->get();

        foreach ($perangkat as $satu) {
            $terkirim = $this->pengirim->kirim(
                $satu,
                $isi['judul'],
                $isi['isi'],
                $isi['data'] ?? [],
            );

            if ($terkirim) {
                continue;
            }

            // Cuma token yang ditolak PERMANEN yang dihapus. Gagal sesaat
            // (jaringan, layanan lagi down) nggak boleh mencabut perangkat
            // yang sebenarnya sehat — orangnya bakal berhenti dapat notifikasi
            // dan nggak ada yang tahu kenapa.
            if ($this->pengirim->tokenMati()) {
                Log::info('Token push dihapus: ditolak permanen.', [
                    'user_id' => $satu->user_id,
                    'platform' => $satu->platform,
                ]);
                $satu->delete();
            }
        }
    }
}
