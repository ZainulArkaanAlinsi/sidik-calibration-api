<?php

use App\Models\CalibrationSession;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gerbang pengesahan sertifikat — "sahkan dulu, terbit belakangan".
 *
 * Keputusan 26 Sep 2026 (`claude/keputusan-26sep-gerbang-sertifikat.md` §1).
 * Alur barunya: teknisi kirim → admin "Setujui & ajukan terbit" →
 * `menunggu_pengesahan` → super admin "Sahkan & terbitkan" → sertifikat lahir.
 *
 * ## Kenapa statusnya nambah, bukan pakai kolom terpisah
 *
 * Pernah dipertimbangkan kolom `pengesahan_status` sendiri supaya `status`
 * lama nggak disentuh dan test yang mencocokkan `disetujui` tetap hijau. Itu
 * ditolak: mobile, Filament, papan admin, dan filter `?status=` semuanya baca
 * SATU kolom. Dua sumber status berarti tiap layar harus tahu urutan
 * penggabungannya, dan yang lupa nggak memunculkan error — dia cuma
 * menampilkan sesi di kolom yang salah.
 *
 * `menunggu_pengesahan` diselipkan DI ANTARA `menunggu_approval` dan
 * `disetujui`. Arti `disetujui` sengaja TIDAK berubah: "sudah sah, sertifikat
 * terbit / sedang dicetak". Jadi semua yang memeriksa keadaan AKHIR tetap
 * benar; yang pecah cuma yang mengasumsikan approve() langsung mendarat di
 * sana — dan itu memang perilaku yang berubah. Daftar lengkapnya di
 * `../README.md` §"Yang bakal merah".
 *
 * ## Kenapa ENUM-nya di-ALTER, dan kenapa SQLite meloloskannya
 *
 * `calibration_sessions.status` ENUM sungguhan di MySQL (migrasi
 * 2026_07_14_120600). Nilai di luar daftarnya kena `SQLSTATE[01000] Data
 * truncated`. Test jalan di SQLite in-memory yang NGGAK punya tipe ENUM — dia
 * simpan string apa adanya, jadi seluruh suite bisa hijau sementara MySQL
 * produksi menolaknya. Pola di
 * `2026_09_16_100100_tambah_role_pelanggan_dan_status_pending_ke_users.php`
 * ditiru di sini, bukan dikarang ulang.
 *
 * ## Kenapa `down()` menurunkan barisnya, bukan melempar
 *
 * Beda dari migrasi `users` yang melempar: baris `menunggu_pengesahan` itu
 * sesi yang MENGGANTUNG — belum sah, belum punya sertifikat, belum ada nomor
 * yang terpakai. Menurunkannya kembali ke `menunggu_approval` memulihkan
 * keadaan tepat sebelum admin mencet "ajukan terbit", dan tidak ada bukti
 * apa pun yang hilang (`diajukan_pada`/`diajukan_oleh` ikut dinolkan, dan
 * baris auditnya tetap ada di `audit_logs`). Yang TIDAK boleh diturunkan
 * diam-diam itu sesi yang sudah `disetujui` — dan dia tidak disentuh di sini.
 */
return new class extends Migration
{
    private const STATUS_BARU = ['draft', 'menunggu_approval', 'menunggu_pengesahan', 'disetujui', 'perlu_revisi'];

    private const STATUS_LAMA = ['draft', 'menunggu_approval', 'disetujui', 'perlu_revisi'];

    public function up(): void
    {
        $this->ubahEnum(self::STATUS_BARU);

        Schema::table('calibration_sessions', function (Blueprint $table): void {
            // Diajukan = admin selesai memeriksa dan melempar ke pengesah.
            // Dipisah dari `reviewed_at` karena dua peristiwa itu bisa berjarak
            // hari kalau pengesahnya sedang di luar, dan jarak itu yang mau
            // diukur admin ("sudah berapa lama nunggu Pak Rohman?").
            $table->timestamp('diajukan_pada')->nullable()->after('reviewed_at');
            $table->foreignId('diajukan_oleh')->nullable()->after('diajukan_pada')->constrained('users');

            // Disahkan = keputusan yang MENERBITKAN sertifikat. Ini kolom yang
            // ditunjuk auditor kalau bertanya "siapa yang mengesahkan dokumen
            // ini", jadi dia berdiri sendiri dan tidak pernah ditimpa
            // `reviewed_by`.
            $table->timestamp('disahkan_pada')->nullable()->after('diajukan_oleh');
            $table->foreignId('disahkan_oleh')->nullable()->after('disahkan_pada')->constrained('users');

            // Siapa yang namanya TERCETAK di kotak tanda tangan. Beda dari
            // `disahkan_oleh`: default-nya `organizations.settings.penandatangan_nama`
            // (satu nama untuk seluruh lab), tapi permintaan 26 Sep poin 13 itu
            // "bisa Pak Rohman atau Alex tergantung siapa yang mengesahkan" —
            // jadi per sertifikat, dipilih di layar pengajuan.
            //
            // Null = pakai pengaturan organisasi, persis seperti hari ini.
            $table->foreignId('penandatangan_user_id')->nullable()->after('disahkan_oleh')->constrained('users');

            // Masa berlaku yang DIMINTA admin waktu mengajukan, dititipkan ke
            // pengesah. Sebelum gerbang ini ada, `berlaku_sampai` dikirim di
            // body approve() dan langsung dipakai job — sekarang approve() dan
            // penerbitan terpisah hari, jadi angkanya harus ada yang menyimpan.
            // Pengesah boleh menggantinya; yang dipakai job itu yang terakhir.
            $table->date('berlaku_sampai_diminta')->nullable()->after('penandatangan_user_id');

            // Catatan admin untuk pengesah ("alat ini FAIL, pelanggan sudah
            // diberi tahu lewat telepon"). Bukan `catatan_revisi` — itu arahnya
            // ke teknisi dan ikut terhapus waktu sesi disetujui.
            $table->text('catatan_pengajuan')->nullable()->after('berlaku_sampai_diminta');

            // Antrean pengesahan disortir umur pengajuan dan disaring per lab.
            // Tanpa indeks ini `GET /pengesahan/antrean` memindai seluruh tabel
            // sesi tiap kali layar super admin dibuka.
            $table->index(['organization_id', 'status', 'diajukan_pada'], 'sesi_antrean_pengesahan_idx');
        });
    }

    public function down(): void
    {
        // Turunkan dulu barisnya, baru sempitkan ENUM-nya — urutan sebaliknya
        // menabrak `Data truncated` di MySQL.
        $diturunkan = DB::table('calibration_sessions')
            ->where('status', 'menunggu_pengesahan')
            ->update([
                'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                'diajukan_pada' => null,
                'diajukan_oleh' => null,
                'updated_at' => now(),
            ]);

        if ($diturunkan > 0) {
            // Bukan exception: ini keadaan yang memang dipulihkan. Tapi harus
            // kebaca di log deploy, karena sesudah rollback ada N lembar kerja
            // yang muncul lagi di antrean admin dan adminnya perlu tahu kenapa.
            logger()->warning(
                "Rollback gerbang pengesahan: {$diturunkan} sesi dikembalikan dari ".
                '`menunggu_pengesahan` ke `menunggu_approval`. Nggak ada sertifikat '.
                'yang terpengaruh — sesi itu belum pernah punya nomor.'
            );
        }

        Schema::table('calibration_sessions', function (Blueprint $table): void {
            $table->dropIndex('sesi_antrean_pengesahan_idx');
            $table->dropConstrainedForeignId('diajukan_oleh');
            $table->dropConstrainedForeignId('disahkan_oleh');
            $table->dropConstrainedForeignId('penandatangan_user_id');
            $table->dropColumn([
                'diajukan_pada',
                'disahkan_pada',
                'berlaku_sampai_diminta',
                'catatan_pengajuan',
            ]);
        });

        $this->ubahEnum(self::STATUS_LAMA);
    }

    /** @param  list<string>  $nilai */
    private function ubahEnum(array $nilai): void
    {
        // Cuma MySQL/MariaDB yang punya ENUM. Di SQLite (test) `ALTER ... MODIFY`
        // bukan sintaks yang sah, dan kolomnya memang sudah menerima string apa
        // adanya — jadi nggak ada yang perlu diubah.
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $daftar = implode(', ', array_map(fn (string $v): string => "'{$v}'", $nilai));

        DB::statement(
            "ALTER TABLE calibration_sessions MODIFY COLUMN status ENUM({$daftar}) NOT NULL DEFAULT 'draft'"
        );
    }
};
