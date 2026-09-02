<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direktori perusahaan yang disimpan SENDIRI, bukan ditembak ke luar.
 *
 * ## Kenapa tabel sendiri, bukan diseed ke `customers`
 *
 * Ini keputusan yang paling menentukan di seluruh fitur, dan alasannya terukur:
 * `SimpananPelanggan` di HP menyalin SELURUH daftar pelanggan ke
 * SharedPreferences supaya pemilih pelanggan tetap jalan di pabrik yang nol
 * sinyal — dan SharedPreferences dibaca utuh ke memori TIAP aplikasi nyala.
 * Sepuluh ribu baris direktori = ~1,4 MB JSON yang diurai tiap buka aplikasi,
 * di HP teknisi, selamanya. Ukuran unduhan APK-nya tidak berubah; yang berubah
 * waktu nyalanya — dan itu tidak kelihatan dari mana pun sampai ada yang
 * mengeluh aplikasinya lemot.
 *
 * Tiga alasan lain, dan tiap satunya sudah cukup:
 *
 *  - `customers` punya `sumber` + `dibuat_oleh_user_id`: tiap baris ada yang
 *    bertanggung jawab. Sepuluh ribu baris hasil seed tidak punya siapa-siapa
 *    di belakangnya, tapi KELIHATAN sama sahihnya di layar.
 *  - Unique index `(organization_id, nama)` bakal bentrok dengan pelanggan
 *    asli, dan yang mentok teknisi di lapangan.
 *  - Panel "Kelola Pelanggan" jadi sepuluh ribu baris yang 99%-nya bukan
 *    pelanggan.
 *
 * ## Kenapa TANPA `organization_id`
 *
 * Isinya data publik, bukan data lab. Menempelkannya ke organisasi berarti
 * menyalin sepuluh ribu baris yang sama untuk tiap lab, dan tidak ada satu pun
 * yang dibeli dari situ — tidak ada lab yang "memiliki" alamat pabrik orang.
 *
 * Konsekuensinya harus dipegang siapa pun yang menambah query ke tabel ini:
 * dia **sengaja tidak tersaring `organization_id`**, jadi jangan pernah
 * menyimpan apa pun milik lab di sini. Yang boleh masuk cuma hasil impor
 * direktori. Barisnya jadi milik lab HANYA setelah teknisi memilihnya — dan
 * saat itu yang lahir baris `customers` baru lewat `POST /customers/cepat`,
 * lengkap dengan `sumber`, `dibuat_oleh_user_id`, dan `direktori_ref`.
 *
 * ## Yang di sini BUKAN kebenaran
 *
 * Sumbernya direktori pihak ketiga yang memperingatkan dirinya sendiri:
 * daftar Jababeka dari 2020 ("banyak perusahaan sudah pindah, berganti nama,
 * atau tutup"), dan Indonetwork isian mandiri tiap perusahaan ("keakuratannya
 * bervariasi"). Jadi isinya PETUNJUK buat mempercepat ketik, bukan alamat yang
 * boleh langsung tercetak — dan itu bukan kehati-hatian berlebihan:
 * `certificates.snapshot` membekukan alamat saat sertifikat terbit, jadi yang
 * salah di sana tidak bisa ditarik lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direktori_lokal', function (Blueprint $table) {
            $table->id();

            // Dari berkas mana barisnya datang. Dipakai buat mengganti satu
            // sumber tanpa menyentuh sumber lain waktu datanya diperbarui, dan
            // buat memberi tahu layar seberapa tua data yang dia pajang.
            $table->string('sumber', 32);

            // Id baris di sumbernya. Bersama `sumber` bikin impor ulang
            // menimpa baris yang sama, bukan menggandakannya.
            $table->string('ref', 64);

            $table->string('nama');

            // Diturunkan `Customer::normalkanNama()` — SATU aturan buat dua
            // tabel. Dua aturan berbeda bikin pencarian di sini memulangkan
            // hasil yang tidak pernah cocok dengan penjaga kembar `customers`.
            $table->string('nama_normal');

            $table->string('alamat')->nullable();
            $table->string('kota', 128)->nullable();
            $table->string('provinsi', 64)->nullable();

            $table->timestamps();

            $table->unique(['sumber', 'ref']);

            // Pencarian jalan di `nama_normal`, bukan `nama`. Indeksnya ada
            // supaya prefiks (`LIKE 'pt maju%'`) tidak memindai seluruh tabel;
            // pencarian di tengah kata memang tetap memindai, dan pada sepuluh
            // ribu baris itu masih di bawah satu milidetik.
            $table->index('nama_normal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direktori_lokal');
    }
};
