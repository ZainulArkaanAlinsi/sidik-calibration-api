<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Str;

/**
 * Sandi buat akun yang BARU dibentuk seeder — dan cuma yang baru.
 *
 * ## Kenapa perlu
 *
 * Sebelum ini dua seeder nulis `'password' => 'rahasia123'` di dalam `update()`
 * (`DatabaseSeeder`) dan `updateOrCreate()` (`PhMeterSeeder`). Dua akibatnya,
 * dua-duanya nggak kelihatan sampai kejadian:
 *
 * **1. Sandi bawaan ikut naik ke server.** Repo ini publik, jadi `rahasia123`
 * bukan sekadar sandi lemah — dia tertulis terbuka di docs/kontrak-api.md, di
 * docs/BACA-DULU-BACKEND.md, dan di seeder-nya sendiri. Nama service-nya juga
 * publik di render.yaml, jadi alamat servernya tinggal ditebak sekali. Yang
 * kebuka bukan cuma data: panel `/admin` bisa nerbitin, ngubah, dan ngapus
 * sertifikat kalibrasi. Buat lab terakreditasi itu temuan audit, bukan sekadar
 * data berantakan.
 *
 * **2. Seed ulang NGERESET sandi yang sudah diganti.** `SEED_ON_BOOT` memang
 * dimaksud dinyalain sekali doang waktu deploy pertama, tapi "sekali doang" itu
 * janji manusia, bukan jaminan sistem. Sekali dia keteken `true` lagi — buat
 * nambal master data, misalnya — semua sandi balik ke bawaan. Diam-diam, tanpa
 * error, tanpa ada yang dikabarin. Admin yang sudah rajin ganti sandi nggak
 * akan tahu sandinya balik ke yang tercetak di dokumentasi publik.
 *
 * ## Aturannya
 *
 * - **Sandi cuma dipasang waktu barisnya BARU.** Akun yang sudah ada nggak
 *   pernah disentuh, di lingkungan mana pun — termasuk lokal. Nambal
 *   nama/role/departemen lewat seeder tetap boleh; nambal sandi nggak.
 * - **Lokal & test tetap `rahasia123`.** Dokumentasi, skrip e2e, dan test
 *   bersandar ke situ, dan di laptop nggak ada yang dipertaruhkan.
 * - **Di luar itu** sandinya dari `SEED_ADMIN_PASSWORD` (lewat
 *   `config/seeding.php`, bukan `env()` langsung — config-nya dicache waktu
 *   boot). Kalau kosong, seeder bikin sandi acak 32 karakter.
 *
 * Sandi acaknya SENGAJA dicetak ke log, bukan disimpan diam-diam: paket gratis
 * Render nggak ngasih akses shell, jadi akun yang sandinya nggak diketahui
 * siapa-siapa itu akun yang nggak bisa dipakai selamanya — dan seeder nggak
 * bakal nyetel ulang buat nolong, karena justru itu yang lagi dicegah. Log
 * Render nggak abadi, jadi salin waktu deploy dan langsung ganti lewat panel.
 *
 * Dipakai oleh kelas turunan `Illuminate\Database\Seeder` — `$this->command`
 * dipakai buat nyetak, dan dia null kalau seeder dipanggil di luar konsol.
 */
trait MenyetelSandiAwal
{
    private ?string $sandiAcak = null;

    /**
     * Sandi buat SATU akun yang baru kebentuk.
     *
     * Sandi acaknya dibikin sekali per seeder, bukan per akun: yang baca log
     * deploy itu manusia, dan empat sandi acak berbeda di satu layar log lebih
     * gampang salah salin daripada satu.
     */
    protected function sandiAwal(): string
    {
        if (app()->environment(['local', 'testing'])) {
            return 'rahasia123';
        }

        $disetel = config('seeding.sandi_awal');

        if (is_string($disetel) && $disetel !== '') {
            return $disetel;
        }

        if ($this->sandiAcak === null) {
            $this->sandiAcak = Str::password(32);

            $this->command?->warn(
                'SEED_ADMIN_PASSWORD kosong. Sandi akun baru dibikin acak: '
                .$this->sandiAcak
                .' — SALIN SEKARANG, lalu ganti lewat /admin. Sandi ini cuma dicetak sekali.'
            );
        }

        return $this->sandiAcak;
    }
}
