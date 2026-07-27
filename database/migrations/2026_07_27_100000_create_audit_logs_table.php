<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak perubahan data (arsitektur-desktop-database.md Keputusan 4).
 *
 * Alasannya bukan kerapian: ISO/IEC 17025 minta rekaman bisa ditelusuri — siapa
 * ngubah apa, kapan, dari nilai berapa ke berapa. Menu "Kelola Data" ngasih admin
 * keleluasaan kayak phpMyAdmin, dan phpMyAdmin nggak nyatet apa pun. Tabel ini
 * yang bikin keleluasaan itu boleh ada.
 *
 * Satu tabel buat SEMUA entitas, bukan satu tabel riwayat per tabel data. Kalau
 * dipecah, nambah entitas baru berarti nambah tabel riwayat baru — dan yang lupa
 * dibikin nggak keliatan sebagai error, cuma sebagai perubahan yang nggak kecatat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Multi-tenant. WAJIB ada di sini: tanpa ini, riwayat perubahan satu
            // lab kebaca lab lain, dan justru tabel audit yang jadi kebocoran
            // paling parah — isinya nilai sebelum & sesudah dari semua data.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Entitasnya disimpen sebagai NAMA TABEL (mis. `standards`), bukan
            // nama kelas PHP. Nama kelas bisa berubah kalau kodenya di-refactor,
            // dan riwayat lama nggak boleh ikut basi gara-gara ganti nama kelas.
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id');

            $table->string('action', 20);

            // Cuma kolom yang BERUBAH yang disimpen, bukan seluruh baris. Snapshot
            // penuh tiap update bikin riwayatnya nggak kebaca manusia — dan yang
            // dicari asesor itu "apa yang berubah", bukan "seluruh isi baris".
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();

            // `nullable` karena nggak semua perubahan datang dari orang yang login:
            // `GenerateCertificate` jalan di queue tanpa sesi. Dibiarin null lebih
            // jujur daripada dituduhin ke user acak.
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('note', 500)->nullable();

            // `created_at` doang, TANPA `updated_at`. Baris audit nggak boleh bisa
            // diubah — kalau bisa, dia berhenti jadi bukti. `AuditLog` model juga
            // nolak update & delete.
            $table->timestamp('created_at')->useCurrent();

            // Nama index DIEKSPLISITKAN dan pendek. MySQL nolak nama index lewat
            // 64 karakter (error 1059), dan nama otomatis Laravel
            // (`audit_logs_entity_type_entity_id_index`) gampang kelewat batas
            // kalau nanti kolomnya nambah. Di repo ini bug itu udah pernah
            // kejadian dan bikin `migrate` mentok permanen.
            $table->index(['entity_type', 'entity_id'], 'audit_entitas_idx');
            $table->index(['organization_id', 'created_at'], 'audit_org_waktu_idx');
            $table->index('changed_by', 'audit_pelaku_idx');
            $table->index('action', 'audit_aksi_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
