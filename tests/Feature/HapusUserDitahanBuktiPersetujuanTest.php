<?php

namespace Tests\Feature;

use App\Models\OtpPelanggan;
use App\Models\PersetujuanDokumen;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Menghapus baris `users` TIDAK boleh ikut membuang bukti persetujuannya.
 *
 * ## Yang dijaga, dan kenapa dia sunyi
 *
 * `users` tidak memakai `SoftDeletes`, dan panel admin punya `DeleteAction`
 * plus `DeleteBulkAction`. Selama `persetujuan_dokumen.user_id` masih
 * `cascadeOnDelete`, satu klik di panel menghapus bukti persetujuan syarat &
 * kebijakan privasi — yang UU PDP menuntut penyelenggara bisa MENUNJUKKANnya.
 *
 * Penghapusannya berhasil, notifikasinya hijau, dan tidak ada satu pun error.
 * Ketahuannya baru waktu ada yang memintanya, dan saat itu tidak ada jalan
 * pulang.
 *
 * ## Dua arah, bukan satu
 *
 * Test ini juga membuktikan yang SEBALIKNYA: user yang cuma punya OTP
 * menganggur tetap bisa dihapus. Tanpa arah kedua, "aman" bisa dicapai dengan
 * me-restrict semuanya — dan itu mengunci akun yang seharusnya bisa dibersihkan
 * cuma karena ada kredensial berumur 10 menit yang belum kedaluwarsa.
 *
 * ## Kenapa cuma MySQL
 *
 * `ALTER TABLE` SQLite tidak bisa mengganti definisi foreign key, jadi
 * migrasinya melewati SQLite dan constraint-nya memang tidak ada di sana. Yang
 * dijaga integritas PRODUKSI, dan produksi jalan di MySQL.
 */
class HapusUserDitahanBuktiPersetujuanTest extends TestCase
{
    use RefreshDatabase;

    private function hanyaMysql(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped(
                'Constraint-nya cuma dipasang di MySQL — `ALTER TABLE` SQLite nggak bisa '
                .'ngeganti definisi foreign key. Jalanin: php artisan test -c phpunit.mysql.xml'
            );
        }
    }

    public function test_user_dengan_bukti_persetujuan_tidak_bisa_dihapus(): void
    {
        $this->hanyaMysql();

        $user = User::factory()->create();
        PersetujuanDokumen::factory()->create(['user_id' => $user->id]);

        try {
            $user->delete();
            $this->fail(
                'Baris users kehapus padahal masih punya bukti persetujuan. Itu bukti UU PDP '
                .'yang lenyap tanpa satu pun error — persis yang constraint ini ada buat nahan.'
            );
        } catch (QueryException) {
            // Ditahan database. Inilah yang diinginkan.
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('persetujuan_dokumen', ['user_id' => $user->id]);
    }

    /**
     * Arah kedua: OTP menganggur TIDAK boleh mengunci penghapusan akun.
     *
     * Kalau ini merah, yang terjadi bukan "lebih aman" — migrasinya kelewat
     * rajin dan akun yang seharusnya bisa dibersihkan jadi tersandera
     * kredensial berumur sepuluh menit.
     */
    public function test_user_yang_cuma_punya_otp_tetap_bisa_dihapus(): void
    {
        $this->hanyaMysql();

        $user = User::factory()->create();
        OtpPelanggan::factory()->create(['user_id' => $user->id]);

        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('otp_pelanggan', ['user_id' => $user->id]);
    }
}
