<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tiga foreign key `user_id` pindah dari `cascade` ke `restrict`.
 *
 * ## Yang hilang tanpa ini, dan tanpa satu pun error
 *
 * `users` TIDAK memakai `SoftDeletes` — hapusnya permanen. Panel admin punya
 * `DeleteAction` (`Users/Pages/EditUser.php`) DAN `DeleteBulkAction`
 * (`Users/Tables/UsersTable.php`), jadi satu klik bisa menghapus banyak baris
 * sekaligus. Dengan `cascadeOnDelete`, yang ikut lenyap:
 *
 * | Tabel | Yang hilang | Kenapa mahal |
 * |---|---|---|
 * | `persetujuan_dokumen` | Bukti persetujuan syarat & kebijakan privasi | **UU PDP** menuntut penyelenggara bisa MENUNJUKKAN persetujuannya. Tanpa barisnya, yang tersisa cuma klaim lisan |
 * | `pengajuan_akun_pelanggan` | Siapa mengajukan, siapa menyetujui, kapan | Jejak audit "kenapa perusahaan ini dapat akses" |
 * | `customer_members` | Siapa pernah punya akses ke data perusahaan mana | Catatan akses, ISO/IEC 17025 klausul 4.2 |
 *
 * Ketiganya hilang DIAM-DIAM: penghapusannya sukses, panelnya menampilkan
 * notifikasi hijau, dan yang baru ketahuan waktu ada yang memintanya.
 *
 * ## Kenapa `restrict`, bukan `nullOnDelete`
 *
 * Kolomnya `NOT NULL` di ketiganya, dan lebih dari itu: baris persetujuan tanpa
 * siapa yang menyetujui bukan bukti apa pun. Yang benar bukan menyimpan
 * bangkainya, melainkan MENAHAN penghapusannya.
 *
 * ## Yang SENGAJA tetap cascade
 *
 * - `otp_pelanggan.user_id` — kredensial berumur 10 menit. Dia memang harus
 *   mati bersama pemiliknya, dan me-restrict-nya berarti akun tidak bisa
 *   dihapus selama ada satu OTP menganggur.
 * - `device_tokens.user_id` — token push. `PenganonimAkun::…` sudah menghapusnya
 *   sendiri waktu akun dianonimkan, jadi cascade di sini sejalan, bukan
 *   bertentangan.
 *
 * ## Akibat yang ditanggung sadar
 *
 * Sesudah ini, menghapus user yang punya salah satu baris di atas GAGAL di
 * tingkat database. Di panel Filament itu muncul sebagai galat query, bukan
 * pesan ramah — dan itu memang lebih baik daripada berhasil: jalur yang benar
 * untuk "pelanggan minta akunnya dihapus" sudah ada sejak fase 6, yaitu
 * ANONIMISASI (`users.dianonimkan_pada`), yang menyimpan barisnya dan mencabut
 * isinya.
 *
 * ## SQLite dilewati
 *
 * `ALTER TABLE` SQLite tidak bisa mengganti definisi foreign key, dan
 * `dropForeign` di sana ditolak. Yang dijaga constraint ini integritas
 * PRODUKSI, dan produksi jalan di MySQL — sama alasannya dengan
 * `customers.pic_admin_id` di migrasi 16 Sep.
 */
return new class extends Migration
{
    /** @var list<array{tabel: string, indeks: string}> */
    private const SASARAN = [
        ['tabel' => 'persetujuan_dokumen', 'indeks' => 'persetujuan_dokumen_user_id_index'],
        ['tabel' => 'pengajuan_akun_pelanggan', 'indeks' => 'pengajuan_akun_pelanggan_user_id_index'],
        ['tabel' => 'customer_members', 'indeks' => 'customer_members_user_id_index'],
    ];

    public function up(): void
    {
        $this->pasang('restrict');
    }

    public function down(): void
    {
        $this->pasang('cascade');
    }

    private function pasang(string $aksi): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::SASARAN as $sasaran) {
            if (! Schema::hasTable($sasaran['tabel'])) {
                continue;
            }

            Schema::table($sasaran['tabel'], function (Blueprint $table) use ($aksi): void {
                $table->dropForeign(['user_id']);

                $fk = $table->foreign('user_id')->references('id')->on('users');

                // `restrictOnDelete()` / `cascadeOnDelete()` dipilih lewat
                // cabang, bukan string yang disisipkan ke SQL — nama aksinya
                // datang dari konstanta di berkas ini, tapi membiarkannya
                // mengalir ke query builder tetap bentuk yang tidak perlu.
                $aksi === 'restrict' ? $fk->restrictOnDelete() : $fk->cascadeOnDelete();
            });
        }
    }
};
