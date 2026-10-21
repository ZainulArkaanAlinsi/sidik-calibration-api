<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alur versi data acuan lapis 1 (Studio Data Acuan T2.2/T2.3, AGENTS.md
 * §Olah data butir 3): draf → ajukan → simulasi → sahkan / tolak.
 *
 * - `simulasi_acuan`: laporan simulasi yang DILIHAT pengesah, terikat ke
 *   sha256 isi draf saat simulasi jalan. Per-sesi disimpan di `laporan`
 *   (JSON di longText) — tabel `simulasi_acuan_sesi` 03-SDD §2.4 belum dibuat:
 *   belum ada pembaca yang butuh query per sesi, dan satu dokumen yang
 *   dibekukan lebih jujur sebagai bukti "inilah yang disimulasikan".
 * - `paket_acuan_log`: log metode append-only tiap transisi status versi
 *   (siapa, kapan, alasan, sumber workbook master + sha256, pengakuan).
 *
 * `paket_acuan_versi.simulasi_terakhir_id` sengaja TETAP tanpa FK: menambah FK
 * ke kolom yang sudah ada berarti membangun ulang tabel di SQLite, dan kolom itu
 * hanya ditulis `AlurVersiAcuan` dengan id yang baru saja dibuatnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulasi_acuan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paket_acuan_versi_id')
                ->constrained('paket_acuan_versi', 'id', 'sa_versi_fk')
                ->restrictOnDelete();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'sa_org_fk')
                ->cascadeOnDelete();
            // sha256 isi draf yang disimulasikan; beda dengan isi sekarang = kedaluwarsa.
            $table->char('isi_sha256_saat_jalan', 64);
            // selesai | gagal
            $table->string('status', 16);
            $table->json('ringkasan')->nullable();
            // LONGTEXT: laporan per sesi bisa besar; tidak di-query isinya.
            $table->longText('laporan')->nullable();
            $table->foreignId('dijalankan_oleh')->nullable()
                ->constrained('users', 'id', 'sa_user_fk')->nullOnDelete();
            $table->timestamp('mulai')->nullable();
            $table->timestamp('selesai')->nullable();
            $table->timestamps();

            $table->index(['paket_acuan_versi_id', 'id'], 'sa_versi_idx');
        });

        Schema::create('paket_acuan_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'pal_org_fk')
                ->cascadeOnDelete();
            // RESTRICT: baris log adalah jejak; cascade akan menghapusnya diam-diam.
            $table->foreignId('paket_acuan_versi_id')
                ->constrained('paket_acuan_versi', 'id', 'pal_versi_fk')
                ->restrictOnDelete();
            // dibuat | ajukan | simulasi | sahkan | pensiun | tolak | buang
            $table->string('aksi', 32);
            $table->string('status_dari', 16)->nullable();
            $table->string('status_ke', 16)->nullable();
            $table->char('isi_sha256', 64)->nullable();
            $table->foreignId('user_id')->nullable()
                ->constrained('users', 'id', 'pal_user_fk')->nullOnDelete();
            $table->text('alasan')->nullable();
            // {workbook_master_id, workbook_sha256, workbook_nama, peta_sel_sha256, varian}
            $table->json('sumber')->nullable();
            // rincian aksi: berlaku_mulai, pengakuan, simulasi_id, …
            $table->json('rincian')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['paket_acuan_versi_id', 'id'], 'pal_versi_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paket_acuan_log');
        Schema::dropIfExists('simulasi_acuan');
    }
};
