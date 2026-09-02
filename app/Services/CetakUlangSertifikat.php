<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;

/**
 * Cetak ulang PDF sertifikat yang SUDAH terbit, pakai tanda tangan terbaru.
 *
 * ## Masalah yang diselesaikan
 *
 * Gambar tanda tangan, logo, dan kop dibaca dari pengaturan organisasi pada
 * saat render — tapi PDF sertifikat DISIMPAN, dan yang tersimpan dilayani apa
 * adanya selamanya (lihat [BerkasPdfSertifikat]). Jadi admin yang mengganti
 * gambar tanda tangan di panel melihat sertifikat lamanya tidak berubah sama
 * sekali, tanpa satu pun pesan yang menjelaskan kenapa.
 *
 * Yang tersedia sebelumnya cuma `sertifikat:bangun-ulang --render-ulang-pdf` —
 * perintah baris perintah, dan semua-atau-tidak-sama-sekali. Di sini admin bisa
 * memilih sertifikat mana saja yang ikut.
 *
 * ## Yang TIDAK berubah, dan itu inti keamanannya
 *
 * Snapshot tidak disentuh. Nomor, tanggal, seluruh angka, nama penandatangan,
 * dan jabatannya tetap beku seperti waktu terbit — yang dirender ulang cuma
 * lembarnya, dari snapshot yang sama persis. Sertifikat ini dokumen terkendali;
 * mencetak ulang boleh, mengarang ulang isinya tidak.
 *
 * ## Penjaga yang paling penting: penandatangan yang sudah ganti orang
 *
 * Nama dan jabatan penandatangan DIBEKUKAN ke snapshot waktu terbit, sementara
 * gambar tanda tangannya TIDAK — dia dibaca live tiap render. Selisih itu tidak
 * kelihatan sampai penandatangannya berganti orang, dan waktu itu terjadi,
 * mencetak ulang sertifikat lama menghasilkan **tanda tangan orang baru di atas
 * nama orang lama**.
 *
 * Itu bukan tata letak yang jelek. Itu dokumen yang menyatakan seseorang
 * menandatangani sesuatu yang tidak pernah dia tandatangani — dan dia lolos
 * tanpa satu pun error, karena dari sisi kode semuanya berhasil.
 *
 * Karena itu sertifikat yang penandatangannya sudah beda DITOLAK, dan alasannya
 * disebut dengan nama kedua orangnya. Mengganti gambar tanda tangan orang yang
 * sama — kasus yang justru paling sering, misalnya menukar hasil pindai yang
 * ada watermark-nya dengan yang bersih — tetap jalan normal.
 */
class CetakUlangSertifikat
{
    public function __construct(private readonly BerkasPdfSertifikat $berkas) {}

    /**
     * Cetak ulang sekumpulan sertifikat, satu per satu.
     *
     * Yang ditolak TIDAK menghentikan sisanya: admin yang memilih dua puluh
     * baris lalu satu di antaranya bermasalah tetap dapat sembilan belas yang
     * lain, plus alasan buat yang satu itu. Menggagalkan semuanya cuma bikin
     * dia menebak yang mana.
     *
     * @param  iterable<Certificate>  $daftar
     * @return array{berhasil: list<string>, ditolak: list<array{nomor: string, alasan: string}>}
     */
    public function jalankan(iterable $daftar): array
    {
        $berhasil = [];
        $ditolak = [];

        foreach ($daftar as $sertifikat) {
            $nomor = (string) ($sertifikat->nomor ?? "#{$sertifikat->getKey()}");
            $alasan = $this->alasanTolak($sertifikat);

            if ($alasan !== null) {
                $ditolak[] = ['nomor' => $nomor, 'alasan' => $alasan];

                continue;
            }

            if ($this->berkas->cetakUlang($sertifikat) === null) {
                // Sebabnya sudah dicatat [BerkasPdfSertifikat] berikut
                // konteksnya; di sini yang perlu cuma admin tahu mana yang
                // gagal.
                $ditolak[] = ['nomor' => $nomor, 'alasan' => 'render PDF-nya gagal, cek log server'];

                continue;
            }

            $berhasil[] = $nomor;
        }

        // Dicatat sebagai satu peristiwa, bukan per berkas: yang berguna waktu
        // ditelusuri nanti justru "siapa mencetak ulang apa, sekaligus".
        Log::info('Cetak ulang PDF sertifikat dijalankan.', [
            'berhasil' => count($berhasil),
            'ditolak' => count($ditolak),
            'nomor_berhasil' => $berhasil,
            'nomor_ditolak' => array_column($ditolak, 'nomor'),
        ]);

        return ['berhasil' => $berhasil, 'ditolak' => $ditolak];
    }

    /** Kenapa sertifikat ini tidak boleh dicetak ulang, atau `null` kalau boleh. */
    public function alasanTolak(Certificate $sertifikat): ?string
    {
        if ($sertifikat->status !== Certificate::STATUS_TERBIT) {
            return 'statusnya belum terbit';
        }

        if (blank($sertifikat->pdf_path)) {
            return 'belum punya berkas PDF sama sekali';
        }

        // Tanpa snapshot tidak ada yang bisa dirender. Sertifikat lama dari
        // sebelum snapshot ada memang begini, dan obatnya `sertifikat:bangun-ulang`.
        if (blank($sertifikat->snapshot)) {
            return 'nggak punya data beku (snapshot), jadi lembarnya nggak bisa dirender';
        }

        return $this->penandatanganBeda($sertifikat);
    }

    /**
     * Nama penandatangan sekarang vs yang dibekukan di sertifikat.
     *
     * Yang dibandingkan namanya saja, bukan jabatannya: jabatan berganti tanpa
     * ganti orang itu wajar (promosi, penataan ulang struktur) dan tidak bikin
     * tanda tangannya jadi milik orang lain. Nama yang berganti persis
     * sebaliknya.
     */
    private function penandatanganBeda(Certificate $sertifikat): ?string
    {
        $beku = trim((string) ($sertifikat->snapshot['footer']['penandatangan'] ?? ''));

        // Snapshot lama yang footernya belum punya nama: tidak ada yang bisa
        // dibandingkan, jadi tidak ada yang bisa ditegakkan. Dibiarkan lewat —
        // menolak berdasarkan data yang memang tidak ada cuma memblokir
        // sertifikat yang sebenarnya baik-baik saja.
        if ($beku === '') {
            return null;
        }

        $sertifikat->loadMissing('organization');
        $sekarang = trim((string) ($sertifikat->organization?->settings[Organization::KEY_PENANDATANGAN_NAMA] ?? ''));

        // Setelannya kosong: yang dipakai waktu terbit dulu nama reviewer, dan
        // yang bakal dipakai sekarang juga bukan nama dari setelan. Tidak ada
        // pergantian orang yang bisa dibuktikan dari sini.
        if ($sekarang === '') {
            return null;
        }

        if (mb_strtolower($beku) === mb_strtolower($sekarang)) {
            return null;
        }

        return sprintf(
            'penandatangannya sudah ganti — sertifikat ini beku atas nama "%s", '
            .'sementara tanda tangan yang berlaku sekarang punya "%s". '
            .'Mencetak ulang bakal nempelin tanda tangan orang lain di atas nama yang lama.',
            $beku,
            $sekarang,
        );
    }
}
