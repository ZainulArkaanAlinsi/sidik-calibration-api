<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nyimpen `cache_read_input_tokens` dari respons Anthropic.
 *
 * Kenapa perlu kolom sendiri: docs/SPEC-vision-prompt.md §5 nyuruh mastiin
 * prompt caching kena dengan ngecek angka ini > 0 sesudah panggilan ke-2 —
 * tapi sebelumnya angkanya cuma mampir di memori dan nggak pernah disimpen,
 * jadi klaim "hemat ~90%" nggak pernah bisa dibuktiin ATAU dibantah.
 *
 * Itu bukan cuma soal rapi-rapi. Prefix baru bisa di-cache kalau panjangnya
 * lewat ambang minimum model (claude-opus-4-8: 1024 token; claude-opus-5: 512).
 * Di bawah ambang, API nggak ngasih error apa pun — dia cuma diam-diam nggak
 * nge-cache. Tanpa kolom ini, satu-satunya gejalanya adalah tagihan yang lebih
 * mahal dari yang diharapkan, dan itu baru ketahuan di akhir bulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dijaga `hasColumn`: kolomnya bisa udah kepasang duluan di database
        // yang dipakai bareng sementara tabel `migrations` nggak nyatetnya.
        Schema::table('worksheet_extraction_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('worksheet_extraction_logs', 'cache_read_input_tokens')) {
                $table->unsignedInteger('cache_read_input_tokens')->nullable()->after('output_tokens');
            }
        });
    }

    public function down(): void
    {
        Schema::table('worksheet_extraction_logs', function (Blueprint $table) {
            $table->dropColumn('cache_read_input_tokens');
        });
    }
};
