<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penetapan keputusan metode pindah dari ledger berkas ke DB (Studio Tahap 1,
 * 25 Nov 2026). Dua tabel BARU, tidak ada tabel lama yang disentuh.
 *
 * `parameter_metode_penetapan` = satu baris per penetapan. Definisi parameter
 * (kunci, pilihan, bawaan, sel workbook) TETAP di
 * `database/data/parameter-keputusan-metode.json`; `kunci` di sini hanya menunjuk
 * ke sana, sengaja BUKAN foreign key (registrinya berkas, bukan tabel).
 *
 *  - Append-only: isi baris (`kunci`, `nomor`, `nilai`, `berlaku_mulai`,
 *    `dicatat_pada`, `catatan`) tidak pernah diubah. Yang berubah hanya
 *    `status` (`menunggu_tm` -> `disetujui` | `ditarik`; `disetujui` -> `ditarik`)
 *    beserta `keputusan_oleh` + `tanggal_keputusan` saat disetujui. Dijaga DUA
 *    lapis: model (`ParameterMetodePenetapan`, sama di SQLite dan MySQL) dan
 *    trigger database (migrasi `2026_11_25_110000_kunci_append_only_penetapan_metode`,
 *    yang menahan UPDATE/DELETE mentah lewat query builder).
 *  - `dicatat_pada` diisi SERVER (bukan input klien).
 *  - Global, tanpa `organization_id`: registri berkasnya juga global (satu lab
 *    saat ini, satu akun Studio), jadi tabel ini mengikutinya.
 *  - Hanya status `disetujui` yang memengaruhi perhitungan.
 *
 * `parameter_metode_penetapan_jejak` = jejak audit tiap transisi status (siapa,
 * kapan, dari-ke, catatan). Tulis-sekali, dijaga model yang sama polanya dengan
 * `AuditLog` dan trigger database yang sama.
 *
 * Aditif murni dan kompatibel SQLite + MySQL 8: tanpa kolom JSON, tanpa
 * ekspresi spesifik mesin, `berlaku_mulai`/`tanggal_keputusan` bertipe `date`
 * dan dibaca sebagai teks Y-m-d (dibandingkan sebagai teks di PHP, bukan di SQL).
 *
 * `down()` menolak jalan bila salah satu tabel sudah berisi baris: isinya jejak
 * audit keputusan metode (siapa menetapkan dan menyetujui apa, kapan) dan
 * `dropIfExists` akan membuangnya tanpa bekas. Kosongkan tabelnya dengan sadar
 * (cadangan lebih dulu, lihat `docs/aturan-akses-database.md` butir 4) bila
 * memang itu yang dimaksud.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parameter_metode_penetapan', function (Blueprint $table) {
            $table->id();
            $table->string('kunci', 120);
            $table->unsignedInteger('nomor');
            $table->string('nilai', 80);
            $table->date('berlaku_mulai');
            $table->dateTime('dicatat_pada');
            $table->string('status', 20)->default('menunggu_tm');
            $table->string('keputusan_oleh', 150)->nullable();
            $table->date('tanggal_keputusan')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            // Nomor berurutan per parameter; unik menahan dua catatan serentak.
            $table->unique(['kunci', 'nomor'], 'pmp_kunci_nomor_unik');
            $table->index(['status', 'kunci'], 'pmp_status_kunci_idx');
        });

        Schema::create('parameter_metode_penetapan_jejak', function (Blueprint $table) {
            $table->id();
            $table->foreignId('penetapan_id')
                ->constrained('parameter_metode_penetapan')
                ->restrictOnDelete();
            $table->string('dari_status', 20)->nullable();
            $table->string('ke_status', 20);
            $table->foreignId('oleh_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('oleh_email', 191)->nullable();
            $table->dateTime('terjadi_pada');
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index('penetapan_id', 'pmpj_penetapan_idx');
        });
    }

    public function down(): void
    {
        foreach (['parameter_metode_penetapan_jejak', 'parameter_metode_penetapan'] as $tabel) {
            if (Schema::hasTable($tabel) && DB::table($tabel)->exists()) {
                throw new RuntimeException(
                    "Rollback ditolak: {$tabel} berisi jejak audit keputusan metode yang akan hilang. "
                    .'Cadangkan dan kosongkan tabelnya dengan sadar (docs/aturan-akses-database.md butir 4), baru ulangi.'
                );
            }
        }

        Schema::dropIfExists('parameter_metode_penetapan_jejak');
        Schema::dropIfExists('parameter_metode_penetapan');
    }
};
