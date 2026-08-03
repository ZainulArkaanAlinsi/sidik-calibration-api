<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nama PT jadi kunci: unique per organisasi.
 *
 * Folder PT itu jangkar seluruh berkas pelanggan — sertifikat, lembar kerja,
 * dan daftar alat semuanya nempel ke situ, dan yang dipakai orang buat nyari
 * itu NAMA-nya, bukan id. Dua PT bernama sama bikin pertanyaan "berkas ini
 * punya siapa" nggak punya jawaban tunggal, dan folder yang salah baru
 * ketahuan waktu sertifikat udah dikirim ke pelanggan yang keliru.
 *
 * Indeks `(organization_id, nama)` sebelumnya udah ada tapi **nggak unique** —
 * dia cuma bikin pencarian cepat, nggak nahan kembar.
 *
 * Unique-nya per organisasi, bukan global: dua lab beda sah punya pelanggan
 * bernama sama, dan itu bukan urusan satu sama lain.
 *
 * Dipasang sekarang justru karena datanya masih bersih (0 kembar per 30 Juli).
 * Begitu ada kembar, constraint ini nggak bisa dipasang tanpa mutusin dulu
 * mana yang dipertahankan — dan itu keputusan yang butuh orang, bukan migrasi.
 */
return new class extends Migration
{
    private const NAMA_INDEKS = 'customers_organization_id_nama_unique';

    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        // Kembar yang udah ada nggak digabung otomatis: nggak ada cara aman
        // buat nebak mana yang bener, dan salah gabung berarti berkas dua
        // pelanggan nyampur. Migrasinya berhenti dengan pesan yang nyebut
        // nama-namanya biar bisa dibersihin manual dulu.
        $kembar = DB::table('customers')
            ->selectRaw('organization_id, nama, COUNT(*) as jumlah')
            ->groupBy('organization_id', 'nama')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($kembar->isNotEmpty()) {
            $daftar = $kembar
                ->map(fn ($b) => "\"{$b->nama}\" ({$b->jumlah}x)")
                ->implode(', ');

            throw new RuntimeException(
                'Nama pelanggan kembar masih ada, jadi unique-nya nggak bisa '
                ."dipasang: {$daftar}. Gabungin atau ganti namanya dulu — "
                .'mana yang dipertahanin itu keputusan orang, bukan migrasi.',
            );
        }

        if ($this->indeksAda()) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['organization_id', 'nama'], self::NAMA_INDEKS);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers') || ! $this->indeksAda()) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(self::NAMA_INDEKS);
        });
    }

    private function indeksAda(): bool
    {
        foreach (Schema::getIndexes('customers') as $indeks) {
            if ($indeks['name'] === self::NAMA_INDEKS) {
                return true;
            }
        }

        return false;
    }
};
