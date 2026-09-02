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

            // Exception DITANGKAP per sertifikat, bukan dibiarkan naik.
            //
            // Temuan review, dan dia benar: tanpa ini satu dompdf yang meledak
            // menghentikan seluruh batch di tengah jalan — sisanya nggak
            // diproses, dan log rekapnya di bawah nggak pernah kejalan. Yang
            // dilihat admin cuma layar galat, tanpa tahu mana yang keburu
            // jadi dan mana yang belum.
            //
            // Itu juga melanggar janji docblock fungsi ini sendiri: "yang
            // ditolak nggak menghentikan sisanya" cuma berlaku buat penolakan,
            // sementara yang melempar justru menghentikannya.
            try {
                $path = $this->berkas->cetakUlang($sertifikat);
            } catch (\Throwable $e) {
                Log::error('Cetak ulang PDF sertifikat melempar exception.', [
                    'certificate_id' => $sertifikat->getKey(),
                    'nomor' => $nomor,
                    'exception' => $e,
                ]);

                $ditolak[] = ['nomor' => $nomor, 'alasan' => 'render PDF-nya meledak, cek log server'];

                continue;
            }

            if ($path === null) {
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

        // Setelan organisasinya kosong. Itu konfigurasi yang SAH — waktu terbit
        // namanya jatuh ke reviewer sesi (lihat `CertificateSnapshotBuilder`),
        // bukan ke setelan — jadi yang dibandingkan ikut pindah ke sana.
        //
        // Temuan review sebelumnya minta kasus ini DITOLAK karena identitas
        // penandatangan aktif tidak bisa dibuktikan. Arahnya benar, tapi
        // menolak mentah-mentah bikin fiturnya mati total buat organisasi yang
        // memang memakai jalur reviewer — dan itu mayoritas sesi di repo ini.
        // Yang diambil jalan tengahnya: dibandingkan ke sumber yang SAMA dengan
        // yang dipakai waktu membekukan namanya.
        if ($sekarang === '') {
            $sertifikat->loadMissing('session.reviewer');
            $sekarang = trim((string) ($sertifikat->session?->reviewer?->name ?? ''));
        }

        // Dua-duanya kosong: tidak ada satu pun sumber yang bisa menyebut siapa
        // pemilik tanda tangan yang berlaku sekarang. DI SINI baru ditolak —
        // mencetak ulang berarti menempelkan gambar yang tidak bisa
        // dipertanggungjawabkan ke bawah nama yang beku.
        if ($sekarang === '') {
            return 'nama penandatangan yang berlaku sekarang nggak bisa dipastikan '
                .'(setelan organisasi kosong dan reviewer sesinya nggak ketemu). '
                .'Isi dulu nama penandatangan di Pengaturan → Organisasi.';
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
