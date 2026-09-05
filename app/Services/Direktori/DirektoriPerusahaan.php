<?php

namespace App\Services\Direktori;

/**
 * Pencarian nama & alamat perusahaan di direktori LUAR.
 *
 * ## Apa yang direktori ini bukan
 *
 * Ini **bukan** registri badan hukum. Nggak ada API publik yang memulangkan
 * seluruh PT terdaftar di Indonesia: AHU (Kemenkumham) memegang datanya tapi
 * nggak membuka API, dan OSS/BKPM cuma buat mitra berizin. Yang bisa dipakai
 * dengan API key itu direktori TEMPAT USAHA — perusahaan sebagaimana dia
 * muncul di peta.
 *
 * Konsekuensinya harus dipegang siapa pun yang membaca ini:
 *
 *  - Nama & alamat di sini **belum tentu sama** dengan yang tertulis di akta.
 *    Buat blok OWNER sertifikat, yang mengikat itu yang di surat pesanan
 *    pelanggan — makanya hasil direktori selalu bisa disunting teknisi sebelum
 *    tersimpan, nggak pernah masuk langsung.
 *  - Pabrik yang nggak pernah didaftarkan ke peta **nggak akan ketemu**, dan
 *    itu bukan kerusakan. Jalur ketik tangan tetap ada justru buat ini.
 *  - Tiap pencarian mengirim kata kunci teknisi ke pihak ketiga. Yang keluar
 *    cuma kata kunci — nol data ukur, nol citra lembar kerja.
 *
 * ## Kenapa antarmuka, bukan langsung panggil
 *
 * Penyedianya belum tentu tetap: yang dipilih sekarang paling lengkap buat
 * Indonesia, tapi dia berbayar per request. Kalau suatu saat ditukar, yang
 * berubah cuma satu kelas di belakang antarmuka ini — controller, layar HP, dan
 * bentuk datanya nggak ikut.
 */
interface DirektoriPerusahaan
{
    /**
     * Direktorinya siap dipakai (API key-nya ada).
     *
     * WAJIB diperiksa sebelum [cari]. Ini yang bikin "key-nya belum diisi" bisa
     * dibedakan dari "PT-nya nggak ketemu" — dua keadaan yang di layar teknisi
     * kelihatan sama persis kalau yang belum disetel diam-diam mulangin daftar
     * kosong, dan yang kedua bikin dia mendaftarkan ulang PT yang sebenarnya
     * ada di sana.
     */
    public function tersedia(): bool;

    /**
     * @return list<PerusahaanDitemukan>
     *
     * @throws DirektoriGagal kalau penyedianya nolak, timeout, atau mulangin
     *                        bentuk yang nggak dikenali. Sengaja melempar, bukan
     *                        mulangin array kosong — lihat [tersedia].
     */
    public function cari(string $kata): array;

    /**
     * Kalimat atribusi yang WAJIB dipajang di layar yang memperlihatkan
     * hasilnya, atau `null` kalau penyedianya nggak menuntut apa-apa.
     *
     * Ditaruh di antarmuka, bukan di layar HP, karena kewajibannya melekat ke
     * PENYEDIANYA — dan penyedianya bisa ditukar. Kalau kalimatnya ditulis di
     * sisi klien, menukar penyedia diam-diam bikin lab memajang atribusi yang
     * salah (atau nggak memajangnya sama sekali), dan itu pelanggaran lisensi
     * yang nggak ninggalin satu pun error.
     */
    public function atribusi(): ?string;
}
