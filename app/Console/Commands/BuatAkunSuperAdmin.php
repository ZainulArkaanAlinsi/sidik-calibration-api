<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bikin satu akun `super_admin` — satu-satunya jalan yang ada, dan itu disengaja.
 *
 * ## Kenapa tidak lewat panel
 *
 * `User::roles()` sengaja cuma memuat admin/teknisi/viewer, dan daftar itulah
 * yang mengisi dropdown role di `/admin` serta `Rule::in()` di `UserController`.
 * Kalau `super_admin` dimasukkan ke sana, admin biasa bisa mempromosikan dirinya
 * sendiri jadi super admin — peran yang seharusnya mengawasi admin jadi bisa
 * dicetak yang diawasi. Jadi pintunya memang ditutup di panel, dan dibuka di
 * sini: di mesin, oleh orang yang memegang kredensial database.
 *
 * ## Kenapa TIDAK dari environment seperti [BuatAkunAdmin]
 *
 * `akun:admin` jalan tiap container nyala, dan itu benar untuk perannya:
 * memulihkan akses admin ketika panelnya sendiri tidak bisa dibuka. Super admin
 * beda — dia membaca menembus `organization_id`, menembus kerahasiaan antar
 * pelanggan (ISO/IEC 17025 klausul 4.2). Peran seperti itu tidak boleh lahir
 * sebagai efek samping sebuah deploy; dia lahir karena ada orang yang
 * memutuskannya, di waktu yang dia pilih. Karena itu argumennya di baris
 * perintah, ada konfirmasi, dan perintah ini TIDAK dipasang di
 * `docker/entrypoint.sh` maupun penjadwal.
 *
 * ## Yang didapat akunnya, dan yang tidak
 *
 * Baca: aplikasi, panel `/admin`, seluruh rute `GET`/`HEAD`, dan lintas
 * organisasi di panel (tiap layarnya tercatat `App\Support\JejakLintasOrganisasi`).
 * Menulis: tidak ada — `EnsureUserHasRole` cuma meloloskan GET/HEAD, dan
 * `App\Filament\Concerns\HakTulisPanel` menyembunyikan aksi tulis di panel.
 * Itu bukan pekerjaan setengah jadi: K4 (siapa yang boleh mengesahkan
 * sertifikat) belum dijawab manajer teknis, dan tombol `approve` di panel
 * menerbitkan sertifikat berlogo akreditasi.
 *
 * Ringkasan itu dicetak sesudah akunnya jadi, supaya yang membuatnya tidak
 * menghabiskan waktu mencari tombol yang memang sengaja tidak ada.
 *
 * ## Kenapa salah setelan di sini pulang GAGAL
 *
 * [BuatAkunAdmin] memulangkan SUKSES buat email salah ketik atau organisasi
 * yang belum ada, karena dia jalan waktu boot dan tidak boleh mematikan API.
 * Perintah ini dijalankan orang yang sedang menatap layarnya — di situ exit code
 * yang jujur lebih berguna daripada boot yang selamat, dan "sukses" untuk akun
 * yang tidak jadi cuma bikin orangnya mengira sudah beres.
 */
class BuatAkunSuperAdmin extends Command
{
    protected $signature = 'akun:super-admin
        {email : alamat email buat login}
        {--nama= : nama tampilan; default dari emailnya}
        {--id-pegawai= : ID pegawai, dipakai login juga}
        {--departemen=}
        {--organisasi=1 : organisasi tempat akunnya duduk}
        {--paksa : lewati konfirmasi (buat pemakaian terskrip)}';

    protected $description = 'Bikin satu akun super admin — baca semua lab, nol wewenang menulis.';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Bukan email yang sah: {$email}");

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            // Sengaja TIDAK menaikkan akun yang sudah ada jadi super admin.
            // Aturannya sama dengan `akun:admin`: yang belum ada dibuatkan, yang
            // sudah ada tidak disentuh. Menaikkan peran lewat perintah berarti
            // ada akun yang berubah wewenangnya tanpa satu pun layar yang
            // menunjukkannya ke orang lain.
            $this->error("Email {$email} sudah dipakai akun lain — tidak disentuh.");

            return self::FAILURE;
        }

        $organisasi = (int) $this->option('organisasi');

        if (! Organization::query()->whereKey($organisasi)->exists()) {
            $this->error("Organisasi #{$organisasi} tidak ada.");

            return self::FAILURE;
        }

        $idPegawai = trim((string) $this->option('id-pegawai')) ?: null;

        if ($idPegawai !== null && User::query()->where('employee_id', $idPegawai)->exists()) {
            // ID pegawai ikut dipakai login (`AuthController` memilih kolomnya
            // dari ada-tidaknya '@'), jadi yang kembar bikin dua orang berebut
            // satu identitas login.
            $this->error("ID pegawai {$idPegawai} sudah dipakai akun lain.");

            return self::FAILURE;
        }

        if (! $this->option('paksa') && ! $this->confirm(
            "Bikin akun super admin {$email}? Dia bisa MEMBACA data seluruh lab, termasuk lab lain.",
            false,
        )) {
            $this->line('Dibatalkan. Tidak ada yang dibuat.');

            return self::FAILURE;
        }

        // Transaksi karena `User` memakai [\App\Models\Concerns\Diaudit]:
        // barisnya ditulis DULU, baru event `created` menulis `audit_logs`.
        // Tanpa rollback, pencatatan audit yang gagal meninggalkan akun super
        // admin yang sudah jadi tanpa jejak — dan akun paling berwenang yang
        // lahir tanpa jejak persis temuan yang paling mahal buat lab
        // terakreditasi.
        $akun = DB::transaction(fn (): User => User::create([
            'organization_id' => $organisasi,
            'name' => trim((string) $this->option('nama')) ?: $email,
            'email' => $email,
            'employee_id' => $idPegawai,
            'department' => trim((string) $this->option('departemen')) ?: null,
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
            'password' => $this->sandiBaru(),
        ]));

        $this->newLine();
        $this->info("Akun super admin dibuat: {$akun->email} (organisasi #{$organisasi}).");
        $this->line('  BISA   : login aplikasi & panel /admin, baca seluruh rute GET,');
        $this->line('           dan baca lintas organisasi di panel (tiap layarnya tercatat).');
        $this->line('  TIDAK  : menulis apa pun — tombol approve, retry, reset sandi,');
        $this->line('           create/edit/delete semuanya tertutup sampai K4 dijawab');
        $this->line('           manajer teknis. Itu disengaja, bukan setengah jadi.');
        $this->newLine();
        $this->warn('Akun ini tidak muncul di daftar pengguna /admin dan tidak bisa diubah');
        $this->warn('lewat API — penjagaan yang sama yang bikin admin nggak bisa mencetaknya.');

        return self::SUCCESS;
    }

    /**
     * Selalu acak 32 karakter, dicetak SEKALI. Sengaja TIDAK memakai
     * [MenyetelSandiAwal] — dan itu penyimpangan yang dipikirkan, bukan lupa.
     *
     * Aturan bersama itu memulangkan `rahasia123` begitu
     * `app()->environment(['local','testing'])`, dan itu benar untuk asalnya:
     * dia lahir buat SEEDER, yang menanam akun fixture di mesin orang.
     *
     * Di sini kondisinya lain dan berbahaya. Mesin kerja pemilik proyek
     * ber-`APP_ENV=local` sementara `.env`-nya menunjuk database PRODUKSI — jadi
     * akun super admin sungguhan akan lahir dengan sandi fixture. Sandi itu
     * tertulis terbuka di `AGENTS.md`, di repo yang publik, dan akun ini yang
     * paling berwenang di seluruh sistem. Kombinasi itu: siapa pun yang
     * meng-clone repo bisa masuk sebagai pengawas lab.
     *
     * `SEED_ADMIN_PASSWORD` juga sengaja tidak dibaca. Variabel itu milik alur
     * seeding admin; memakainya di sini berarti satu nilai yang mungkin sudah
     * dipakai akun lain, dan sandi bersama antar akun menghapus arti jejak
     * audit — "siapa yang melakukan ini" tidak lagi punya jawaban tunggal.
     *
     * Konsekuensinya sandi ini cuma ada di layar, sekali. Itu memang yang
     * diinginkan: yang tidak pernah tersimpan tidak bisa bocor belakangan.
     */
    private function sandiBaru(): string
    {
        $sandi = Str::password(32);

        $this->newLine();
        $this->warn('Sandi akun ini — SALIN SEKARANG, cuma dicetak sekali:');
        $this->line('  '.$sandi);
        $this->warn('Ganti lewat /admin sesudah masuk pertama kali.');

        return $sandi;
    }
}
