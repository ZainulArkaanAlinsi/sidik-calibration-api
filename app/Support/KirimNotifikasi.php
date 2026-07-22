<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\NotifikasiApp;

/**
 * Pengirim notifikasi. Dibikin terpusat biar aturan "siapa yang dikabarin"
 * cuma ditulis sekali — kalau tersebar di tiap controller, gampang ada
 * kejadian yang lupa nyaring organisasi atau nyaring akun nonaktif.
 *
 * @see \App\Http\Controllers\Api\NotificationController buat sisi bacanya
 */
class KirimNotifikasi
{
    /**
     * Kabarin SEMUA admin aktif di satu organisasi.
     *
     * Disaring `organization_id` — admin PT lain nggak boleh tahu ada kerjaan
     * masuk di PT kita. Disaring `status` aktif juga: akun pending/nonaktif
     * nggak bisa login, jadi notifikasinya cuma numpuk nggak kebaca.
     *
     * @param  array{jenis: string, id: int}|null  $tautan
     */
    public static function keAdmin(
        int $organizationId,
        string $tipe,
        string $judul,
        string $isi,
        ?array $tautan = null,
    ): void {
        $admins = User::query()
            ->where('organization_id', $organizationId)
            ->where('role', User::ROLE_ADMIN)
            ->where('status', User::STATUS_AKTIF)
            ->get();

        foreach ($admins as $admin) {
            $admin->notify(new NotifikasiApp($tipe, $judul, $isi, $tautan));
        }
    }

    /**
     * Kabarin satu orang. `null` dilewat diam-diam — pemanggilnya sering punya
     * relasi opsional (mis. teknisi yang akunnya udah dihapus), dan gagal keras
     * cuma gara-gara notifikasi nggak sepadan.
     *
     * @param  array{jenis: string, id: int}|null  $tautan
     */
    public static function kePengguna(
        ?User $user,
        string $tipe,
        string $judul,
        string $isi,
        ?array $tautan = null,
    ): void {
        $user?->notify(new NotifikasiApp($tipe, $judul, $isi, $tautan));
    }
}
