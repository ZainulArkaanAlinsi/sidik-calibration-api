<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Nahan notifikasi berulang yang isinya sama.
 *
 * Ini yang bikin notifikasi kepakai, bukan cuma ada. Pengingat standar acuan jalan
 * dari scheduler TIAP PAGI dan ambangnya 30 hari — tanpa penjaga ini, admin dapat
 * baris yang persis sama 30 kali berturut-turut buat satu buffer pH yang mau habis.
 * Sesudah minggu pertama nggak ada yang buka loncengnya lagi, dan waktu ada yang
 * beneran penting, itu ikut ketimbun.
 *
 * Aturannya dua, sengaja:
 *
 * 1. **Isi sama** dalam masa tenang → dilewat.
 * 2. **Isi berubah** → dikirim SEKARANG, nggak nunggu masa tenang habis. Standar
 *    baru masuk jendela peringatan atau satu standar berubah jadi kadaluarsa itu
 *    kabar baru, dan itu justru saat yang paling nggak boleh telat.
 *
 * Nggak butuh tabel/kolom baru: tanda tangannya nebeng di payload notifikasi yang
 * udah kesimpen di `notifications.data`.
 */
class PenjagaNotifikasiUlang
{
    /** Kunci tanda tangan di payload notifikasi. */
    public const KEY_TANDA_TANGAN = 'tanda_tangan';

    /**
     * Boleh kirim ke orang ini?
     *
     * @param  class-string  $kelasNotifikasi
     * @param  string  $tandaTangan  ringkasan isi; beda tanda tangan = kabar baru
     * @param  int  $masaTenangHari  isi yang sama nggak diulang selama ini
     */
    public function bolehKirim(
        User $penerima,
        string $kelasNotifikasi,
        string $tandaTangan,
        int $masaTenangHari,
    ): bool {
        $terakhir = $penerima->notifications()
            ->where('type', $kelasNotifikasi)
            ->latest('created_at')
            ->first();

        if (! $terakhir) {
            return true;
        }

        $tandaTanganLama = $terakhir->data[self::KEY_TANDA_TANGAN] ?? null;

        // Isinya berubah → kabar baru, kirim sekarang.
        if ($tandaTanganLama !== $tandaTangan) {
            return true;
        }

        // Isi sama: cuma boleh kalau masa tenangnya udah lewat. Notifikasi lama
        // yang udah dibaca TETAP nahan — kalau yang udah dibaca dianggap boleh
        // diulang, admin yang rajin baca justru yang paling sering dispam.
        return Carbon::parse($terakhir->created_at)
            ->addDays($masaTenangHari)
            ->isPast();
    }
}
