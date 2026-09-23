<?php

namespace App\Filament\Concerns;

use App\Models\User;

/**
 * Satu jawaban buat "orang yang lagi buka panel ini boleh MENULIS nggak".
 *
 * Ada karena Fase 2 membuka `/admin` buat `super_admin`, dan panel ini bukan
 * layar baca: tombol `approve` di tabel sesi menerbitkan sertifikat berlogo
 * akreditasi, `retry` mencetak ulang PDF, `resetPassword` menyetel sandi orang
 * lain. Sampai K4 dijawab manajer teknis (siapa yang boleh mengesahkan), super
 * admin masuk buat melihat — bukan buat memutuskan.
 *
 * `ScopesToOrganization` sudah menutup create/edit/delete bawaan Filament. Yang
 * TIDAK ketutup di sana aksi kustom `Action::make(...)`: Filament nggak tahu
 * aksi itu menulis atau nggak, jadi nggak ada policy yang bisa menebaknya.
 * Kesembilan aksi yang menulis memanggil kelas ini lewat `->visible()`.
 *
 * Kenapa kelas, bukan `->visible(fn () => ! auth()->user()?->isSuperAdmin())`
 * ditulis sembilan kali: yang kesepuluh nanti lupa ditulis, dan lupanya nggak
 * memunculkan error — cuma satu tombol yang tetap nyala buat orang yang belum
 * boleh menekannya. Nama kelas ini gampang di-grep waktu menambah aksi baru.
 */
final class HakTulisPanel
{
    /** Panel lagi dibuka orang yang boleh menulis? */
    public static function boleh(): bool
    {
        return ! (User::yangLogin()?->isSuperAdmin() ?? false);
    }
}
