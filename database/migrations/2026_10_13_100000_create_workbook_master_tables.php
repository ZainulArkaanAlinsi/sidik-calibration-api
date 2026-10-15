<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sidik Studio S1b — "Workbook Alat" mode Asli Excel, BACA-SAJA
 * (`docs/permintaan-user-7.md` §49 K-49-08..11, `docs/sidik-studio/03-SDD.md` §4).
 *
 * Dua tabel additive. Isinya hasil ekstraksi workbook master per alat (CSV
 * ekspor di repo atau `.xlsm` asli ber-sandi): nama & urutan sheet, sel (nilai,
 * teks tampil, rumus, format, gaya ringkas), dan tata letak. Tidak ada kolom
 * yang ditambahkan ke tabel lama.
 *
 * Sheet DATABASE / SERTIFIKAT: dulu `dikecualikan = true` + `sel` kosong
 * (K-49-08); sejak K-49-12 (13 Okt 2026) isinya disimpan apa adanya dan
 * lembarnya hanya ditandai `tautan_tabel` (`customers` / `certificates`).
 * Kolom `dikecualikan` dibiarkan untuk baris lama.
 *
 * Nama index, unique, dan foreign ditulis eksplisit dan pendek (MySQL menolak
 * nama > 64 karakter) — pola `create_paket_acuan_tables`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbook_master', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'wm_org_fk')
                ->cascadeOnDelete();

            // Null bila satu workbook melayani beberapa profil (keluarga) atau
            // pemetaannya belum dijawab lab (01-riset-peta-workbook.md).
            $table->string('kode_profil', 64)->nullable();
            $table->string('keluarga', 64)->nullable();

            $table->string('nama');

            // `csv` (ekspor di repo, nilai saja kecuali 13 folder berumus) atau
            // `xlsm` (berkas asli ber-sandi: rumus + tata letak persis).
            $table->string('sumber', 8);

            // csv: sha256 isi kanonik hasil ekstraksi (KanonikWorkbook).
            // xlsm: sha256 BYTE berkas asli terenkripsi, sama dengan nama
            // berkasnya di disk (K-49-11).
            $table->char('sha256', 64);

            // csv: jumlah byte semua CSV di folder. xlsm: byte berkas asli.
            $table->unsignedBigInteger('ukuran');
            $table->unsignedInteger('jumlah_lembar');

            // K-49-11: berkas `.xlsm` asli disimpan APA ADANYA (terenkripsi,
            // byte asli) di disk privat. Null untuk sumber csv.
            $table->string('berkas_disk', 32)->nullable();
            $table->string('berkas_path')->nullable();

            $table->text('catatan')->nullable();

            $table->foreignId('diimpor_oleh')->nullable()
                ->constrained('users', 'id', 'wm_diimpor_oleh_fk')->nullOnDelete();

            $table->timestamps();

            // Impor idempoten: isi yang sama tidak lahir dua kali di satu lab.
            $table->unique(['organization_id', 'sha256'], 'wm_org_sha_unik');
            $table->index(['organization_id', 'kode_profil'], 'wm_org_profil_idx');
        });

        Schema::create('workbook_master_lembar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workbook_master_id')
                ->constrained('workbook_master', 'id', 'wml_wm_fk')
                ->cascadeOnDelete();

            $table->unsignedInteger('urutan');
            $table->string('nama');

            // `tidak` | `hidden` | `veryHidden` (status sheet Excel; csv selalu `tidak`).
            $table->string('tersembunyi', 12)->default('tidak');

            $table->boolean('dikecualikan')->default(false);
            $table->string('alasan_dikecualikan')->nullable();
            // Tabel yang menjadi sumber kebenaran isi lembar yang dikecualikan.
            $table->string('tautan_tabel', 64)->nullable();

            $table->unsignedInteger('jumlah_baris')->default(0);
            $table->unsignedInteger('jumlah_kolom')->default(0);
            $table->unsignedInteger('jumlah_sel')->default(0);
            $table->unsignedInteger('jumlah_rumus')->default(0);

            // merge, lebar kolom, tinggi baris, kolom/baris tersembunyi,
            // freeze pane, jumlah komentar. Kecil — kolom JSON cukup.
            $table->json('tata_letak');

            // LONGTEXT, BUKAN `json`: isinya bisa megabyte, dan kolom JSON MySQL
            // menormalkan angka & mengurutkan ulang kunci — byte kanonik yang
            // ditulis `KanonikWorkbook` tidak boleh berubah di jalan (alasan yang
            // sama dengan `paket_acuan_versi.isi`).
            $table->longText('sel');

            $table->timestamps();

            $table->unique(['workbook_master_id', 'urutan'], 'wml_wm_urutan_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workbook_master_lembar');
        Schema::dropIfExists('workbook_master');
    }
};
