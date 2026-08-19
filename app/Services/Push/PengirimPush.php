<?php

namespace App\Services\Push;

use App\Models\DeviceToken;

/**
 * Pengirim push notification ke satu perangkat.
 *
 * **Sengaja antarmuka, bukan kelas FCM langsung.** Firebase di proyek ini
 * statusnya sementara — dipakai buat lihat dulu hasil deploy-nya seperti apa.
 * Kalau nanti diganti (atau dilepas sama sekali), yang dibuang cuma satu
 * implementasi; `via()` di notifikasi, tabel `device_tokens`, dan endpoint
 * pendaftarannya tetap berdiri.
 *
 * Push cuma menutup SATU celah: HP dengan aplikasi ketutup total. Selama
 * aplikasinya jalan, kabarnya sudah sampai lewat channel privat Reverb, dan
 * itu jalur yang nggak butuh layanan pihak ketiga sama sekali.
 */
interface PengirimPush
{
    /**
     * Kirim satu notifikasi. Balikin `false` kalau nggak terkirim.
     *
     * @param  array<string, string>  $data  muatan buat aplikasi (mis. tautan
     *                                       layar tujuan), bukan buat dibaca orang
     */
    public function kirim(
        DeviceToken $perangkat,
        string $judul,
        string $isi,
        array $data = [],
    ): bool;

    /**
     * Perangkat ini ditolak PERMANEN sama layanan push?
     *
     * Dipisah dari [kirim] karena tindak lanjutnya beda: gagal sesaat (jaringan,
     * layanan lagi down) itu wajar dan tokennya tetap dipakai, sedangkan token
     * yang sudah nggak sah — aplikasinya dicopot, tokennya dirotasi — harus
     * DIHAPUS. FCM nggak pernah ngabarin kalau aplikasi dicopot, jadi ini
     * satu-satunya cara tabelnya nggak menumpuk token mati selamanya.
     */
    public function tokenMati(): bool;
}
