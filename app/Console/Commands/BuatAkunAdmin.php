<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\Concerns\MenyetelSandiAwal;
use Illuminate\Console\Command;

/**
 * Bikin SATU akun admin dari environment — buat memberi orang akses penuh
 * tanpa ada yang perlu membuka panel.
 *
 * ## Kenapa ada
 *
 * Menambah orang ke lab ini normalnya lewat `/admin` → Users → New, dan itu
 * memang jalan yang benar buat sehari-hari. Yang tidak bisa dilakukan lewat
 * situ: menambah orang ketika yang memegang panelnya sedang tidak bisa
 * membukanya. Paket gratis Render TIDAK menyediakan shell sama sekali, jadi
 * `php artisan tinker` juga bukan jalan keluar. Sisanya tinggal boot.
 *
 * ## Dua jebakan yang perintah ini ada buat menutupnya
 *
 * Form Filament-nya memberi dua nilai bawaan yang keduanya salah buat orang
 * yang mau dikasih akses penuh:
 *
 *   - **status** bawaannya `pending`, dan `AuthController` menolak akun
 *     pending SEBELUM sandinya dicek: *"Akun kamu belum disetujui admin."*
 *   - **role** bawaannya `teknisi`.
 *
 * Dan `User::canAccessPanel()` menuntut admin DAN aktif, jadi salah satu saja
 * keliru berarti akunnya kelihatan jadi tapi menolak orangnya masuk — tanpa
 * pesan yang menjelaskan kenapa. Perintah ini menulis dua-duanya sendiri, jadi
 * tidak ada yang bisa lupa.
 *
 * ## Kenapa cuma MEMBUAT, tidak pernah mengubah
 *
 * Akun yang sudah ada TIDAK disentuh sama sekali — bukan role-nya, bukan
 * status-nya, apalagi sandinya. Perintah ini jalan tiap container nyala, dan
 * variabel environment yang ditinggal terisi itu keadaan normal, bukan
 * kelalaian. Kalau dia ikut menaikkan role, orang yang sengaja diturunkan
 * lewat panel (berhenti kerja, pindah bagian) akan naik lagi sendiri di deploy
 * berikutnya — diam-diam, tanpa ada yang menyetujuinya. Buat lab terakreditasi
 * itu temuan audit.
 *
 * Jadi batasnya jelas: yang belum ada, dibuatkan. Yang sudah ada, urusan
 * `/admin`.
 *
 * ## Sandinya
 *
 * Ikut aturan yang sudah ada di [MenyetelSandiAwal], bukan aturan baru: dari
 * `SEED_ADMIN_PASSWORD` kalau disetel, kalau tidak dibuat acak 32 karakter dan
 * dicetak SEKALI ke log deploy. Log Render tidak abadi — salin waktu deploy,
 * lalu suruh orangnya ganti lewat `/admin`.
 */
class BuatAkunAdmin extends Command
{
    use MenyetelSandiAwal;

    protected $signature = 'akun:admin';

    protected $description = 'Bikin satu akun admin dari environment, kalau emailnya belum kedaftar.';

    /**
     * Dipakai [MenyetelSandiAwal] buat mencetak sandi acaknya.
     *
     * Trait itu lahir buat Seeder, yang punya properti `$command` bawaan.
     * Diisi `$this` supaya aturan sandinya punya SATU definisi — kalau
     * disalin ke sini, dua tempat itu bisa berbeda diam-diam, dan yang
     * berbeda diam-diam soal sandi adalah hal yang paling tidak boleh.
     */
    public ?Command $command = null;

    /** Lab ini. Sama persis dengan yang dipakai `DatabaseSeeder::seedUsers()`. */
    private const ORGANISASI = 1;

    public function handle(): int
    {
        $this->command = $this;

        $email = trim((string) config('seeding.akun_admin.email'));

        // Tidak disetel = fitur ini memang tidak dipakai. Bukan kesalahan, dan
        // tidak boleh bikin boot berisik: perintah ini jalan tiap container
        // nyala, termasuk tiap Render membangunkan service yang ketiduran.
        if ($email === '') {
            return self::SUCCESS;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Diperiksa karena email yang salah ketik TIDAK menghasilkan error
            // di mana pun: akunnya kebentuk, lalu orangnya tidak pernah bisa
            // login dan tidak ada yang tahu kenapa.
            $this->error("AKUN_ADMIN_EMAIL bukan email yang sah: {$email}");

            return self::FAILURE;
        }

        $sudahAda = User::query()->where('email', $email)->first();

        if ($sudahAda !== null) {
            $this->line(sprintf(
                'Akun %s sudah ada (role: %s, status: %s) — tidak disentuh.',
                $email,
                $sudahAda->role,
                $sudahAda->status,
            ));

            // Sengaja disebut walau bukan kegagalan. Yang menyetel variabelnya
            // menyetelnya supaya orang itu punya akses penuh; kalau akunnya
            // ternyata belum admin/aktif, dia harus tahu dari log ini — bukan
            // dari orangnya mengeluh tidak bisa masuk.
            if ($sudahAda->role !== User::ROLE_ADMIN || $sudahAda->status !== User::STATUS_AKTIF) {
                $this->warn(
                    '   Akun itu BUKAN admin-aktif. Perintah ini sengaja tidak '
                    .'mengubah akun yang sudah ada — betulkan lewat /admin.'
                );
            }

            return self::SUCCESS;
        }

        if (! Organization::query()->whereKey(self::ORGANISASI)->exists()) {
            // Tanpa penjagaan ini yang muncul cuma galat foreign key di tengah
            // boot, dan sebabnya ("master data belum di-seed") tidak kelihatan
            // dari situ sama sekali.
            $this->error(
                'Organisasi #'.self::ORGANISASI.' belum ada, jadi akunnya belum bisa '
                .'dibuat. Nyalakan SEED_ON_BOOT sekali dulu biar master datanya keisi.'
            );

            return self::FAILURE;
        }

        $idPegawai = trim((string) config('seeding.akun_admin.id_pegawai')) ?: null;

        if ($idPegawai !== null
            && User::query()->where('employee_id', $idPegawai)->exists()) {
            // ID pegawai juga dipakai buat login (`AuthController` memilih
            // kolomnya dari ada-tidaknya '@'), jadi yang kembar bukan sekadar
            // data kotor — dia bikin dua orang berebut satu identitas login.
            $this->error("ID pegawai {$idPegawai} sudah dipakai akun lain.");

            return self::FAILURE;
        }

        $akun = User::create([
            'organization_id' => self::ORGANISASI,
            'name' => trim((string) config('seeding.akun_admin.nama')) ?: $email,
            'email' => $email,
            'employee_id' => $idPegawai,
            'department' => trim((string) config('seeding.akun_admin.departemen')) ?: null,

            // Dua nilai ini yang jadi alasan perintah ini ada. Lihat docblock.
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_AKTIF,

            'password' => $this->sandiAwal(),
        ]);

        $this->info("Akun admin dibuat: {$akun->email} (role: admin, status: aktif).");

        return self::SUCCESS;
    }
}
