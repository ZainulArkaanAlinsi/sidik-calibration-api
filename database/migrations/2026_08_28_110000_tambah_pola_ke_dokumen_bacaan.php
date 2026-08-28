<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas POLA LEMBAR: kode dokumen + revisinya, dirapikan.
 *
 * ## Kenapa kolom sendiri, bukan mencocokkan dua kolom yang sudah ada
 *
 * `kode_dokumen` dan `revisi` itu teks yang DIBACA AI dari kertas, jadi ejaannya
 * bergoyang: `Rev.5`, `Rev 5`, `rev.5` — tiga tulisan buat satu lembar yang
 * sama. Dicocokkan mentah, riwayat koreksi satu lembar terpecah jadi tiga
 * tumpukan yang masing-masing terlalu sedikit buat dipercaya, dan fiturnya
 * diam-diam nggak pernah menyala.
 *
 * Dirapikan waktu DITULIS, bukan waktu dibaca: dirapikan waktu dibaca berarti
 * tiap query harus memanggil fungsi ke seluruh kolom, dan indeksnya nggak
 * kepakai.
 *
 * Yang asli tetap disimpan apa adanya di `kode_dokumen`/`revisi` — kolom ini
 * tambahan buat mencocokkan, bukan pengganti.
 *
 * `null` = lembarnya nggak punya kode dokumen yang kebaca. Itu bukan pola,
 * jadi riwayat koreksinya nggak boleh dicampur sama lembar lain yang juga
 * nggak berkode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dokumen_bacaan', function (Blueprint $table) {
            $table->string('pola', 140)->nullable()->after('revisi');

            // Nama eksplisit — auto-generate gampang lewat 64 karakter dan
            // MySQL nolak di situ (error 1059), SQLite yang dipakai test nggak.
            $table->index(['organization_id', 'pola'], 'db_org_pola_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dokumen_bacaan', function (Blueprint $table) {
            $table->dropIndex('db_org_pola_idx');
            $table->dropColumn('pola');
        });
    }
};
