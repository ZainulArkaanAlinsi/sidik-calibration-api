<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rumus kalibrasi berversi (arsitektur-desktop-database.md Keputusan 5).
 *
 * Yang paling menentukan dari Keputusan 5 bukan "rumusnya bisa diubah", tapi:
 * **tiap hasil hitung nyatet versi rumus yang dipakainya.** Kalau rumus diubah
 * hari ini, angka di sertifikat yang terbit tahun lalu nggak boleh ikut berubah —
 * dan yang lebih penting, harus bisa DIBUKTIKAN kenapa angkanya beda.
 *
 * Tanpa itu, "rumus bisa diubah" justru bikin seluruh riwayat kalibrasi nggak bisa
 * dipertanggungjawabkan: nggak ada cara tahu sertifikat mana dihitung pakai aturan
 * yang mana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Kode stabil yang dirujuk KODE PROGRAM (mis. `gum-ph`). Nama boleh
            // diubah admin kapan aja; kode nggak — dia yang nyambungin rumus ke
            // jalur hitung yang bener.
            $table->string('kode', 64);
            $table->string('nama');

            // Besaran yang dihitung (pH, massa, suhu, …). Nyocokin `kode` di
            // `equipment_categories` biar bisa disambungin per kategori alat.
            $table->string('besaran', 64)->nullable();
            $table->text('deskripsi')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Nama index dieksplisitkan & pendek — MySQL nolak >64 karakter, dan
            // bug itu udah pernah bikin `migrate` mentok permanen di repo ini.
            $table->unique(['organization_id', 'kode'], 'formula_org_kode_unik');
        });

        Schema::create('formula_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formula_id')->constrained()->cascadeOnDelete();

            // Ikut disimpen (bukan cuma lewat `formula_id`) supaya query audit &
            // laporan bisa nyaring per organisasi tanpa join.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('nomor_versi');

            /**
             * Dari mana angkanya dihitung.
             *
             * `kode`     — dihitung `GumCalculator` yang ditanam di kode program.
             * `database` — dihitung dari `ekspresi` di baris ini.
             *
             * Kolom ini yang bikin versi 1 jujur: rumus yang sekarang jalan MEMANG
             * ada di kode, dan nyatetnya sebagai "dari database" bikin riwayatnya
             * bohong sejak baris pertama.
             */
            $table->string('sumber', 16)->default('kode');

            // Parameter bernilai: faktor cakupan k, koefisien suhu buffer, aturan
            // pembulatan, dst. Ini yang dipakai versi `kode`.
            $table->json('parameter')->nullable();

            // Ekspresi per besaran turunan (koreksi, u tiap komponen, U95, …).
            // Kosong buat versi `kode`; keisi begitu evaluator ekspresinya ada.
            $table->json('ekspresi')->nullable();

            $table->string('status', 16)->default('draft');

            // Rentang berlakunya. `effective_until` null = masih berlaku sampai
            // sekarang. Rentang antar-versi satu rumus NGGAK BOLEH tumpang tindih —
            // divalidasi di aplikasi (lihat `FormulaVersion::bentrokRentang`),
            // bukan di DB: batasan rentang tanggal nggak bisa diungkapin sebagai
            // unique index.
            $table->date('effective_from');
            $table->date('effective_until')->nullable();

            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['formula_id', 'nomor_versi'], 'fversi_formula_nomor_unik');
            $table->index(['formula_id', 'status'], 'fversi_formula_status_idx');
            $table->index(['formula_id', 'effective_from'], 'fversi_formula_mulai_idx');
        });

        Schema::table('uncertainty_calculations', function (Blueprint $table) {
            /**
             * Versi rumus yang MENGHASILKAN angka di baris ini.
             *
             * Inilah inti Keputusan 5. `nullable` karena baris yang udah ada
             * sebelum migrasi ini nggak bisa ditebak versinya — dan nebak lebih
             * buruk daripada mengaku nggak tahu. Seeder ngisi versi 1 buat data
             * baru; baris lama tetap null dan itu jujur.
             *
             * `nullOnDelete` bukan `cascade`: kalau versi rumusnya kehapus, hasil
             * hitungnya JANGAN ikut kehapus. Angkanya udah dipakai di sertifikat.
             */
            $table->foreignId('formula_version_id')
                ->nullable()
                ->after('metode')
                ->constrained('formula_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('uncertainty_calculations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('formula_version_id');
        });

        Schema::dropIfExists('formula_versions');
        Schema::dropIfExists('formulas');
    }
};
