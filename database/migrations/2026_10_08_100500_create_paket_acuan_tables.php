<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Studio Data Acuan — wadah berversi untuk data acuan lapis 1 (tabel standar,
 * pita CMC, konstanta metode) dan bentuk lembar lapis 3. Rancangan:
 * `docs/studio-data-acuan/03-SDD-ADR.md` §2.1–2.3.
 *
 * Tiga tabel SAJA. `simulasi_acuan` lahir di P2, `unggahan_workbook` di P5 —
 * tabel tanpa penulis maupun pembaca cuma jadi skema spekulatif.
 *
 * Semua nama index, unique, dan foreign ditulis eksplisit dan pendek: MySQL
 * menolak nama > 64 karakter, dan nama bawaan Laravel (tabel_kolom_..._foreign)
 * cepat melewatinya. Alasan yang sama ditulis di `create_formulas_table`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paket_acuan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'pa_org_fk')
                ->cascadeOnDelete();

            // Kunci stabil = `CalibrationProfile::kode()` / `kodeFormula()`.
            // `nama` boleh diganti; kode yang dirujuk jalur hitung tidak.
            $table->string('kode_profil', 64);
            $table->string('nama');

            // Versi skema lembar dari KODE; naik hanya lewat PR saat struktur
            // lembar berubah (lapis 2), bukan lewat studio.
            $table->unsignedInteger('skema_versi');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'kode_profil'], 'pa_org_profil_unik');
        });

        Schema::create('paket_acuan_versi', function (Blueprint $table) {
            $table->id();

            // RESTRICT, bukan cascade: versi yang pernah menghitung hasil
            // (`uncertainty_calculations.paket_acuan_versi_id`) tidak boleh
            // ikut lenyap karena paketnya terhapus. Paket sendiri soft delete.
            $table->foreignId('paket_acuan_id')
                ->constrained('paket_acuan', 'id', 'pav_paket_fk')
                ->restrictOnDelete();

            // Disimpan langsung (bukan cuma lewat paket) supaya penyaring
            // organisasi & Diaudit tidak perlu join — pola `formula_versions`.
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'pav_org_fk')
                ->cascadeOnDelete();

            // `data` (lapis 1) atau `bentuk` (lapis 3).
            $table->string('jenis', 16);
            $table->unsignedInteger('nomor_versi');

            // draf, diajukan, terjadwal, aktif, pensiun, ditolak, ditarik, dibuang.
            $table->string('status', 16);

            // LONGTEXT, BUKAN `json`: kolom JSON MySQL menormalkan angka dan
            // mengurutkan ulang kunci, jadi byte yang dibaca balik bisa beda dari
            // byte kanonik yang di-hash. `isi_sha256` hanya bermakna kalau byte
            // aslinya disimpan apa adanya.
            $table->longText('isi');

            // Diisi saat `diajukan`; sesudah itu tidak berubah (03-SDD §2.2).
            $table->char('isi_sha256', 64)->nullable();

            $table->unsignedInteger('skema_versi');

            // Versi asal draf. nullOnDelete: hilangnya asal tidak boleh
            // memblokir atau menghapus turunannya.
            $table->foreignId('dasar_versi_id')
                ->nullable()
                ->constrained('paket_acuan_versi', 'id', 'pav_dasar_fk')
                ->nullOnDelete();

            $table->date('berlaku_mulai')->nullable();
            $table->date('berlaku_sampai')->nullable();

            $table->text('alasan')->nullable();
            $table->json('rujukan')->nullable();

            $table->foreignId('dibuat_oleh')->nullable()
                ->constrained('users', 'id', 'pav_dibuat_oleh_fk')->nullOnDelete();
            $table->foreignId('diajukan_oleh')->nullable()
                ->constrained('users', 'id', 'pav_diajukan_oleh_fk')->nullOnDelete();
            $table->foreignId('disahkan_oleh')->nullable()
                ->constrained('users', 'id', 'pav_disahkan_oleh_fk')->nullOnDelete();
            $table->foreignId('ditolak_oleh')->nullable()
                ->constrained('users', 'id', 'pav_ditolak_oleh_fk')->nullOnDelete();

            $table->timestamp('diajukan_pada')->nullable();
            $table->timestamp('disahkan_pada')->nullable();
            $table->timestamp('ditolak_pada')->nullable();

            $table->text('catatan_keputusan')->nullable();

            // TANPA foreign key, dengan sengaja: tabel `simulasi_acuan` baru
            // lahir di P2. FK-nya ditambahkan migrasi P2 yang membuat tabel itu.
            $table->unsignedBigInteger('simulasi_terakhir_id')->nullable();

            // Optimistic lock: naik tiap simpan, ditolak kalau klien memegang
            // nilai lama.
            $table->unsignedInteger('lock_versi')->default(0);

            $table->timestamps();

            // Tidak ada di daftar index 03-SDD §2.2, tapi "nomor_versi unik per
            // (paket, jenis)" ditulis di kolomnya — dan aturan yang cuma dijaga
            // aplikasi kalah oleh dua penyimpan yang berbarengan.
            $table->unique(['paket_acuan_id', 'jenis', 'nomor_versi'], 'pav_paket_jenis_nomor_unik');
            $table->index(['paket_acuan_id', 'jenis', 'status'], 'pav_paket_jenis_status_idx');
            $table->index(['paket_acuan_id', 'jenis', 'berlaku_mulai'], 'pav_paket_jenis_mulai_idx');
        });

        Schema::create('paket_acuan_suntingan', function (Blueprint $table) {
            $table->id();

            // RESTRICT: baris suntingan itu jejak audit (append-only). Cascade
            // akan menghapusnya diam-diam lewat DB, melewati penjagaan model.
            $table->foreignId('paket_acuan_versi_id')
                ->constrained('paket_acuan_versi', 'id', 'pas_versi_fk')
                ->restrictOnDelete();

            $table->string('lembar', 64);
            // Null untuk lembar tanpa baris (mis. `konstanta`: kunci-nilai).
            $table->string('kunci_baris', 128)->nullable();
            $table->string('kolom', 64);

            $table->text('nilai_lama')->nullable();
            $table->text('nilai_baru')->nullable();

            // ketik | tempel | impor | ambil_alih
            $table->string('sumber', 16);
            $table->text('alasan')->nullable();

            $table->foreignId('user_id')->nullable()
                ->constrained('users', 'id', 'pas_user_fk')->nullOnDelete();

            // Hanya created_at: barisnya tidak pernah diubah.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['paket_acuan_versi_id', 'lembar'], 'pas_versi_lembar_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paket_acuan_suntingan');
        Schema::dropIfExists('paket_acuan_versi');
        Schema::dropIfExists('paket_acuan');
    }
};
