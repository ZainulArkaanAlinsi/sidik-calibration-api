<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users.role` menerima `pelanggan` & `super_admin`; `users.status` menerima
 * `pending_email` & `pending_verifikasi`.
 *
 * ## Kenapa ENUM-nya harus di-ALTER, dan kenapa SQLite meloloskannya
 *
 * Dua kolom itu ENUM sungguhan di MySQL (migrasi 2026_07_14_100000 & _110000).
 * Menulis nilai di luar daftarnya kena `SQLSTATE[01000] Data truncated`. Test
 * jalan di SQLite in-memory, dan SQLite NGGAK punya tipe ENUM — dia menyimpan
 * string apa adanya. Jadi seluruh suite bisa hijau sementara MySQL asli
 * menolaknya. Preseden persisnya:
 * `2026_07_30_110000_tambah_lembar_kerja_ke_enum_sumber_folder_files.php`.
 * Pola di berkas itu ditiru di sini, bukan dikarang ulang.
 *
 * ## Kenapa `super_admin` ikut sekarang padahal belum dipakai
 *
 * Permintaan pemilik proyek 16 Sep: nanti ada role super admin yang melihat
 * seluruh proses. Perilakunya BELUM dibangun — dia menunggu K4 (siapa yang
 * boleh mengesahkan sertifikat) dijawab manajer teknis. Yang diputuskan
 * sekarang cuma nilai ENUM-nya, dan itu disengaja: `ALTER TABLE` pada `users`
 * di produksi menyentuh tabel yang dipakai teknisi di lapangan, jadi sekali
 * jalan jauh lebih baik daripada dua kali. Nilai ENUM yang menganggur nol
 * risikonya; `User::roles()` sengaja TIDAK memuatnya (lihat docblock di sana).
 *
 * ## Kenapa `down()` MELEMPAR, bukan menghapus
 *
 * Preseden `folder_files` menghapus baris bernilai baru sebelum menyempitkan
 * ENUM. Di sana itu wajar. Di sini barisnya AKUN ORANG — pelanggan yang sudah
 * mendaftar, lengkap dengan keanggotaan, permintaan, dan persetujuan dokumen
 * yang menggantung padanya. Rollback yang diam-diam menghapusnya itu kehilangan
 * data yang nggak bisa direkonstruksi, dan untuk lab terakreditasi itu temuan
 * audit. Jadi kalau masih ada barisnya, migrasi ini berhenti dan menyebut
 * jumlahnya; yang mau rollback harus memutuskan sendiri nasib akun-akun itu.
 */
return new class extends Migration
{
    private const ROLE_BARU = ['admin', 'teknisi', 'viewer', 'pelanggan', 'super_admin'];

    private const ROLE_LAMA = ['admin', 'teknisi', 'viewer'];

    private const STATUS_BARU = ['aktif', 'pending', 'nonaktif', 'pending_email', 'pending_verifikasi'];

    private const STATUS_LAMA = ['aktif', 'pending', 'nonaktif'];

    public function up(): void
    {
        if (! $this->perluDiubah()) {
            return;
        }

        $this->ubahEnum('role', self::ROLE_BARU, User::ROLE_TEKNISI);
        $this->ubahEnum('status', self::STATUS_BARU, User::STATUS_PENDING);
    }

    public function down(): void
    {
        if (! $this->perluDiubah()) {
            return;
        }

        $this->pastikanTidakAdaBarisBaru('role', array_diff(self::ROLE_BARU, self::ROLE_LAMA));
        $this->pastikanTidakAdaBarisBaru('status', array_diff(self::STATUS_BARU, self::STATUS_LAMA));

        $this->ubahEnum('role', self::ROLE_LAMA, User::ROLE_TEKNISI);
        $this->ubahEnum('status', self::STATUS_LAMA, User::STATUS_PENDING);
    }

    /** @param  list<string>  $nilai */
    private function ubahEnum(string $kolom, array $nilai, string $default): void
    {
        $daftar = implode(', ', array_map(fn (string $v): string => "'{$v}'", $nilai));

        DB::statement("ALTER TABLE users MODIFY COLUMN {$kolom} ENUM({$daftar}) NOT NULL DEFAULT '{$default}'");
    }

    /** @param  iterable<string>  $nilaiBaru */
    private function pastikanTidakAdaBarisBaru(string $kolom, iterable $nilaiBaru): void
    {
        $jumlah = DB::table('users')->whereIn($kolom, iterator_to_array($nilaiBaru, false))->count();

        if ($jumlah > 0) {
            throw new RuntimeException(
                "Rollback dibatalkan: masih ada {$jumlah} baris `users` dengan `{$kolom}` bernilai baru. ".
                'Menyempitkan ENUM sekarang berarti menghapus akun beserta keanggotaan & '.
                'persetujuan dokumennya. Putuskan dulu nasib akun-akun itu, baru rollback.'
            );
        }
    }

    /**
     * Cuma MySQL/MariaDB yang punya ENUM. Di SQLite (test) `ALTER ... MODIFY`
     * bukan sintaks yang sah, dan kolomnya memang sudah menerima string apa
     * adanya — jadi nggak ada yang perlu diubah.
     */
    private function perluDiubah(): bool
    {
        return Schema::hasTable('users')
            && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
