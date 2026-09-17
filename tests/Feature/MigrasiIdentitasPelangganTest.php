<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Migrasi identitas pelanggan: skema terbentuk, ENUM melebar, `down()` benar.
 *
 * ## Kenapa BUKAN `DatabaseMigrations`
 *
 * Refleks pertama buat menguji migrasi memang trait itu, dan di repo ini dia
 * TIDAK BISA dipakai: `DatabaseMigrations` memundurkan SELURUH riwayat migrasi
 * sesudah tiap test, dan tujuh migrasi lama memanggil `dropForeign` — yang di
 * SQLite melempar "This database driver does not support dropping foreign keys
 * by name". Jadi rollback penuh memang mustahil di suite harian, terlepas dari
 * apa pun yang saya tulis di sini.
 *
 * Gantinya berkas migrasinya di-`require` dan `down()`/`up()`-nya dipanggil
 * LANGSUNG. Itu juga lebih tepat sasaran: `migrate:rollback --step=N` menghitung
 * mundur dari migrasi terakhir, jadi begitu ada orang menambah migrasi SESUDAH
 * milik saya, angka itu memundurkan migrasi yang salah — dan testnya tetap
 * hijau sambil menguji hal yang berbeda.
 *
 * ## Yang dibuktikan di masing-masing driver
 *
 * - **MySQL:** ENUM `users.role` & `users.status` beneran menerima nilai baru.
 *   Ini yang SQLite nggak bisa buktikan sama sekali — dia nggak punya tipe
 *   ENUM dan menyimpan string apa adanya, jadi suite harian bisa hijau
 *   sementara MySQL asli menolak menulisnya.
 * - **SQLite:** migrasi ENUM-nya no-op, dan yang diadu justru itu — dia harus
 *   diam, bukan meledak.
 */
class MigrasiIdentitasPelangganTest extends TestCase
{
    use RefreshDatabase;

    private const BERKAS_ENUM = '2026_09_16_100100_tambah_role_pelanggan_dan_status_pending_ke_users.php';

    /** Tabel baru → berkas migrasinya. */
    private const TABEL_BARU = [
        'customer_members' => '2026_09_16_100400_create_customer_members_table.php',
        'undangan_pelanggan' => '2026_09_16_100500_create_undangan_pelanggan_table.php',
        'pengajuan_akun_pelanggan' => '2026_09_16_100600_create_pengajuan_akun_pelanggan_table.php',
        'persetujuan_dokumen' => '2026_09_16_100700_create_persetujuan_dokumen_table.php',
        'otp_pelanggan' => '2026_09_16_100800_create_otp_pelanggan_table.php',
    ];

    /** Kolom tambahan di tabel yang sudah ada → berkas migrasinya. */
    private const KOLOM_TAMBAHAN = [
        '2026_09_16_100000_tambah_kolom_identitas_pelanggan_ke_users.php' => ['users', ['telepon', 'jabatan', 'dianonimkan_pada']],
        '2026_09_16_100200_tambah_pic_admin_dan_maks_anggota_ke_customers.php' => ['customers', ['pic_admin_id', 'maks_anggota']],
        '2026_09_16_100300_tambah_aplikasi_ke_device_tokens.php' => ['device_tokens', ['aplikasi']],
    ];

    private function migrasi(string $berkas): object
    {
        return require database_path('migrations/'.$berkas);
    }

    private function diMySql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** @return array<string, mixed> */
    private function barisUser(int $org, string $email, string $role, string $status): array
    {
        return [
            'organization_id' => $org,
            'name' => 'Uji Migrasi',
            'email' => $email,
            'password' => Hash::make('rahasia123'),
            'role' => $role,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** Seluruh tabel & kolom baru memang terbentuk waktu `migrate`. */
    public function test_skema_barunya_terbentuk(): void
    {
        foreach (array_keys(self::TABEL_BARU) as $tabel) {
            $this->assertTrue(Schema::hasTable($tabel), "Tabel {$tabel} nggak kebentuk.");
        }

        foreach (self::KOLOM_TAMBAHAN as [$tabel, $kolom]) {
            foreach ($kolom as $k) {
                $this->assertTrue(Schema::hasColumn($tabel, $k), "Kolom {$tabel}.{$k} nggak kebentuk.");
            }
        }
    }

    /**
     * INTI yang SQLite nggak bisa buktikan.
     *
     * `users.role` & `users.status` itu ENUM sungguhan di MySQL. Sebelum
     * migrasi ini, menulis `pelanggan` ke situ kena `Data truncated` — dan
     * seluruh suite SQLite tetap hijau karena SQLite nggak punya tipe ENUM.
     */
    public function test_enum_users_menerima_role_dan_status_baru(): void
    {
        $org = Organization::factory()->create()->id;

        DB::table('users')->insert([
            $this->barisUser($org, 'enum-a@contoh.test', User::ROLE_PELANGGAN, User::STATUS_PENDING_EMAIL),
            $this->barisUser($org, 'enum-b@contoh.test', User::ROLE_PELANGGAN, User::STATUS_PENDING_VERIFIKASI),
            $this->barisUser($org, 'enum-c@contoh.test', User::ROLE_SUPER_ADMIN, User::STATUS_AKTIF),
        ]);

        $this->assertSame(2, DB::table('users')->where('role', User::ROLE_PELANGGAN)->count());
        $this->assertSame(1, DB::table('users')->where('role', User::ROLE_SUPER_ADMIN)->count());
        $this->assertSame(1, DB::table('users')->where('status', User::STATUS_PENDING_EMAIL)->count());
        $this->assertSame(1, DB::table('users')->where('status', User::STATUS_PENDING_VERIFIKASI)->count());
    }

    /**
     * `down()` MENOLAK selama masih ada akun pelanggan — dan menolaknya SEBELUM
     * menyentuh skema, jadi nggak ada DDL yang terlanjur jalan.
     *
     * Preseden repo (`folder_files`) menghapus baris bernilai baru sebelum
     * menyempitkan ENUM. Di sini barisnya AKUN ORANG, lengkap dengan keanggotaan
     * & persetujuan dokumen yang menggantung padanya — rollback yang diam-diam
     * menghapusnya itu kehilangan data yang nggak bisa direkonstruksi.
     */
    public function test_rollback_ditolak_kalau_masih_ada_akun_pelanggan(): void
    {
        $org = Organization::factory()->create()->id;
        DB::table('users')->insert(
            $this->barisUser($org, 'pelanggan-contoh@contoh.test', User::ROLE_PELANGGAN, User::STATUS_AKTIF),
        );

        if (! $this->diMySql()) {
            // Di SQLite migrasinya memang no-op (nggak ada tipe ENUM), jadi yang
            // benar di sana `down()` diam saja. Diadu apa adanya per driver,
            // BUKAN di-skip: skip yang muncul tiap kali suite jalan melatih
            // orang berhenti membaca daftar skip.
            $this->migrasi(self::BERKAS_ENUM)->down();

            $this->assertSame(
                1,
                DB::table('users')->where('role', User::ROLE_PELANGGAN)->count(),
                'down() menyentuh data di SQLite, padahal di sana dia harusnya no-op.'
            );

            return;
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Rollback dibatalkan/');

        $this->migrasi(self::BERKAS_ENUM)->down();
    }

    /** Status `pending_*` juga menahan rollback, bukan cuma role. */
    public function test_rollback_ditolak_kalau_masih_ada_status_pending_baru(): void
    {
        if (! $this->diMySql()) {
            $this->assertTrue(true, 'ENUM cuma ditegakkan MySQL; jalur SQLite sudah diadu di test sebelumnya.');

            return;
        }

        $org = Organization::factory()->create()->id;
        DB::table('users')->insert(
            $this->barisUser($org, 'pending-contoh@contoh.test', User::ROLE_TEKNISI, User::STATUS_PENDING_EMAIL),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Rollback dibatalkan/');

        $this->migrasi(self::BERKAS_ENUM)->down();
    }

    /**
     * Tiap tabel baru punya `down()` yang beneran menghapusnya, dan `up()` yang
     * membangunnya lagi.
     *
     * Dijalankan tanpa satu pun baris data supaya DDL-nya nggak meninggalkan
     * apa pun buat test berikutnya — DDL memicu commit implisit di MySQL, jadi
     * transaksi `RefreshDatabase` nggak bisa diandalkan membersihkannya.
     */
    public function test_tabel_baru_bisa_turun_dan_naik_lagi(): void
    {
        foreach (self::TABEL_BARU as $tabel => $berkas) {
            $migrasi = $this->migrasi($berkas);

            $migrasi->down();
            $this->assertFalse(Schema::hasTable($tabel), "down() {$berkas} nggak menghapus {$tabel}.");

            $migrasi->up();
            $this->assertTrue(Schema::hasTable($tabel), "up() {$berkas} nggak membangun ulang {$tabel}.");
        }
    }

    /** Kolom tambahan di tabel yang sudah ada juga turun-naik dengan benar. */
    public function test_kolom_tambahan_bisa_turun_dan_naik_lagi(): void
    {
        foreach (self::KOLOM_TAMBAHAN as $berkas => [$tabel, $kolom]) {
            $migrasi = $this->migrasi($berkas);

            $migrasi->down();

            foreach ($kolom as $k) {
                $this->assertFalse(Schema::hasColumn($tabel, $k), "down() {$berkas} nggak menghapus {$tabel}.{$k}.");
            }

            $migrasi->up();

            foreach ($kolom as $k) {
                $this->assertTrue(Schema::hasColumn($tabel, $k), "up() {$berkas} nggak mengembalikan {$tabel}.{$k}.");
            }
        }
    }

    /** ENUM-nya turun-naik tanpa meledak selama nggak ada baris bernilai baru. */
    public function test_enum_bisa_turun_dan_naik_lagi(): void
    {
        $migrasi = $this->migrasi(self::BERKAS_ENUM);

        $migrasi->down();
        $migrasi->up();

        $org = Organization::factory()->create()->id;
        DB::table('users')->insert(
            $this->barisUser($org, 'sesudah-naik@contoh.test', User::ROLE_PELANGGAN, User::STATUS_PENDING_EMAIL),
        );

        $this->assertSame(1, DB::table('users')->where('role', User::ROLE_PELANGGAN)->count());
    }

    /** `User::roles()` TETAP bertiga — lihat RolePelangganTidakMasukRoleInternalTest. */
    public function test_role_internal_tidak_ikut_melebar(): void
    {
        $this->assertSame([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER], User::roles());
    }
}
