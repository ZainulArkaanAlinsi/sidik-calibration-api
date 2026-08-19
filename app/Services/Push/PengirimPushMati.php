<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;

/**
 * Pengirim yang nggak mengirim apa-apa — dipakai waktu push belum disetel.
 *
 * Ini BAWAANNYA, dan itu disengaja. Kredensial FCM cuma ada di server yang
 * beneran; mesin developer, CI, dan test nggak punya. Tanpa implementasi diam
 * ini, tiap notifikasi di lingkungan itu bakal melempar — dan yang gagal bukan
 * push-nya doang, tapi seluruh aksi yang memicunya (teknisi nyubmit sesi,
 * admin menyetujui).
 *
 * Push itu lapisan tambahan: notifikasinya tetap masuk database, tetap muncul
 * di lonceng, dan tetap disiarkan lewat Reverb ke aplikasi yang lagi jalan.
 */
class PengirimPushMati implements PengirimPush
{
    public function kirim(
        DeviceToken $perangkat,
        string $judul,
        string $isi,
        array $data = [],
    ): bool {
        // Dicatat, bukan didiamkan: "notifikasi HP-nya nggak masuk" adalah
        // laporan yang bakal datang, dan jawaban pertamanya harus bisa dicari
        // di log — belum disetel, bukan gagal kirim.
        Log::debug('Push nggak dikirim: pengirim belum disetel.', [
            'user_id' => $perangkat->user_id,
            'platform' => $perangkat->platform,
        ]);

        return false;
    }

    public function tokenMati(): bool
    {
        // Nggak pernah mengirim, jadi nggak pernah tahu tokennya mati. Balikin
        // `true` di sini bakal menghapus token yang sebenarnya sehat begitu
        // ada satu lingkungan yang push-nya belum disetel.
        return false;
    }
}
