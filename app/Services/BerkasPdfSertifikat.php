<?php

namespace App\Services;

use App\Models\Certificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Pastikan berkas PDF sertifikat ADA DAN UTUH di disk arsip — bangun ulang dari
 * snapshot kalau raib atau rusak.
 *
 * ## Kenapa ini ada
 *
 * Disk container Render itu SEMENTARA: kehapus tiap deploy dan tiap container
 * restart (`docs/deploy-gratis-render.md` §227), dan produksi masih jalan
 * dengan `ARSIP_DRIVER=local` karena bucket R2-nya belum dibuat. Akibatnya tiap
 * deploy menghapus SELURUH PDF sertifikat yang pernah terbit — barisnya di
 * database tetap `terbit` dengan `pdf_path` terisi, tapi berkasnya tidak ada.
 *
 * Yang dilihat pengguna: sesi yang sudah disetujui, tombol unduh diklik, lalu
 * 404. Bukan sekali — tiap deploy. Dan bentuknya paling membingungkan karena
 * halaman QR dan unduhan Excel tetap jalan (dua-duanya dirakit dari snapshot di
 * database), jadi kelihatannya "cuma PDF-nya yang rusak".
 *
 * ## Kenapa membangun ulang itu SAH, bukan menerbitkan dokumen baru
 *
 * PDF sertifikat itu turunan, bukan sumber. Isinya dirender dari
 * `certificates.snapshot` yang SUDAH DIBEKUKAN waktu terbit — lihat docblock
 * [CertificateSnapshotBuilder]. Merender ulang snapshot yang sama dengan blade
 * yang sama menghasilkan lembar yang sama; nomor sertifikat, tanggal terbit,
 * dan seluruh angkanya ikut beku di situ, bukan dihitung ulang dari sesi.
 *
 * SATU hal yang bisa berbeda, dan disebut di sini supaya tidak jadi kejutan:
 * kop, logo, dan gambar tanda tangan dibaca dari pengaturan organisasi yang
 * BERLAKU SEKARANG, dan berkas-berkas itu tinggal di disk yang sama-sama
 * kehapus. Jadi lembar hasil bangun ulang bisa kehilangan gambar tanda
 * tangannya (ruang kosong buat tanda tangan basah — state yang memang sah di
 * blade-nya). Itu sebabnya tiap bangun ulang DICATAT ke log: hilangnya berkas
 * arsip masalah infrastruktur yang obatnya `ARSIP_DRIVER=s3`, dan kelas ini
 * jaring pengaman, bukan penggantinya.
 *
 * ## Dua hal yang TIDAK boleh disederhanakan jadi `exists()`
 *
 * **1. Ada ≠ utuh.** `Storage::put()` menganggap menulis string KOSONG sebagai
 * sukses, dan `exists()` sesudahnya balik `true`. Jadi satu penulisan yang
 * terpotong — disk penuh di tengah jalan, yang justru paling mungkin di
 * container yang disknya memang sempit — mendarat sebagai berkas 0 byte yang
 * dianggap sehat SELAMANYA, dan jalur bangun-ulangnya tidak pernah menyala
 * lagi. Itu lebih buruk daripada 404 yang digantikannya: 404 kelihatan dan bisa
 * ditelusuri, sementara PDF 0 byte sampai ke pelanggan sebagai unduhan rusak
 * yang disangka masalah browsernya sendiri. Karena itu gerbangnya [sehat],
 * bukan `exists()`, dan hasil tulisnya diperiksa lagi sesudah mendarat.
 *
 * **2. Satu berkas raib = SEMUA permintaan merender bersamaan.** Sesudah deploy
 * menghapus disk, tiap permintaan yang masuk menemukan berkasnya hilang pada
 * saat yang sama — dan jalur unduh QR itu tanpa login. Tanpa kunci, tiap
 * permintaan menjalankan dompdf-nya sendiri; di jatah 512 MB yang dibagi
 * bersama queue worker dan scheduler, itu jalan tercepat menuju OOM. Yang
 * kedua dan seterusnya MENUNGGU lalu memakai hasil yang pertama.
 */
class BerkasPdfSertifikat
{
    /**
     * Ambang "jelas terpotong", bukan ukuran wajar.
     *
     * Sertifikat sungguhan ratusan KB sampai megabyte; angka ini cuma menyaring
     * berkas yang mustahil berisi lembar apa pun. Dibikin longgar dengan
     * sengaja — yang dijaga di sini kerusakan yang nyata, bukan menebak berapa
     * besar PDF yang "pantas".
     */
    private const MINIMUM_BYTE = 1024;

    /** Detik menunggu giliran render sebelum menyerah dan balik apa adanya. */
    private const TUNGGU_KUNCI = 15;

    /** Detik kunci dipegang. Lebih lama dari render terlama yang masuk akal. */
    private const UMUR_KUNCI = 120;

    public function __construct(private readonly DataTampilanSertifikat $tampilan) {}

    /**
     * Path PDF yang dijamin ada DAN utuh di disk `arsip`, atau `null` kalau
     * memang belum pernah ada dan tidak bisa dibangun.
     *
     * `null` di sini beda artinya dari lempar: pemanggilnya yang memutuskan
     * mau 404, 422, atau menyembunyikan tombol — dan ketiganya sudah dipakai di
     * tempat yang berbeda.
     */
    public function pastikanAda(Certificate $sertifikat): ?string
    {
        $path = (string) ($sertifikat->pdf_path ?? '');

        // Belum pernah terbit sama sekali: bukan berkas yang hilang, melainkan
        // berkas yang memang belum dibikin. Yang benar di situ tetap 404/422 —
        // membangunnya di sini bakal menerbitkan PDF buat sertifikat yang
        // statusnya `gagal` atau `draf`.
        if ($path === '' || $sertifikat->status !== Certificate::STATUS_TERBIT) {
            return null;
        }

        if ($this->sehat($path)) {
            return $path;
        }

        // Tanpa snapshot tidak ada yang bisa dirender. Sertifikat lama sebelum
        // snapshot ada memang begini, dan obatnya `sertifikat:bangun-ulang`,
        // bukan render kosong.
        if (blank($sertifikat->snapshot)) {
            return null;
        }

        return $this->bangunSekaliSaja($sertifikat, $path);
    }

    /**
     * Render DIKUNCI per sertifikat, dan pemenangnya cuma satu.
     *
     * Pola periksa-dua-kali: yang menunggu memeriksa ulang sesudah dapat
     * giliran, karena besar kemungkinan yang duluan sudah selesai dan tidak ada
     * lagi yang perlu dirender.
     */
    private function bangunSekaliSaja(Certificate $sertifikat, string $path): ?string
    {
        $kunci = Cache::lock('sertifikat-pdf:'.$sertifikat->getKey(), self::UMUR_KUNCI);

        try {
            $kunci->block(self::TUNGGU_KUNCI);
        } catch (LockTimeoutException) {
            // Yang memegang kunci kelamaan. Jangan ikut merender — di kotak
            // 512 MB itu justru yang bikin dua-duanya mati. Layani kalau
            // berkasnya sudah keburu jadi, kalau belum biar pemanggilnya yang
            // memutuskan (404).
            Log::warning('Nunggu giliran render PDF sertifikat kelamaan.', [
                'certificate_id' => $sertifikat->getKey(),
                'detik' => self::TUNGGU_KUNCI,
            ]);

            return $this->sehat($path) ? $path : null;
        }

        try {
            if ($this->sehat($path)) {
                return $path;
            }

            return $this->tulisUlang($sertifikat, $path);
        } finally {
            $kunci->release();
        }
    }

    /** Render dari snapshot beku, lalu pastikan yang mendarat memang utuh. */
    private function tulisUlang(Certificate $sertifikat, string $path): ?string
    {
        $isi = Pdf::loadView('sertifikat.pdf', $this->tampilan->untuk($sertifikat))->output();

        // Diperiksa SEBELUM ditulis: menulis keluaran yang cacat berarti
        // menyimpan kerusakan itu secara permanen, karena panggilan berikutnya
        // menemukannya "ada" dan berhenti di situ.
        if (! $this->bentukPdf($isi)) {
            Log::error('Render ulang PDF sertifikat menghasilkan keluaran yang nggak utuh.', [
                'certificate_id' => $sertifikat->getKey(),
                'byte' => strlen($isi),
            ]);

            return null;
        }

        // Disk-nya disetel `throw => false`, jadi tulis yang gagal balik `false`
        // tanpa suara. Diperiksa dengan alasan yang sama seperti di
        // [\App\Jobs\GenerateCertificate]: mengembalikan path yang tidak
        // menunjuk ke berkas apa pun cuma memindahkan kegagalannya satu langkah
        // ke belakang.
        if (Storage::disk('arsip')->put($path, $isi) === false) {
            Log::error('Bangun ulang PDF sertifikat gagal ditulis.', [
                'certificate_id' => $sertifikat->getKey(),
                'path' => $path,
            ]);

            return null;
        }

        // Dan diperiksa lagi SESUDAH mendarat. `put()` balik `true` untuk
        // penulisan yang terpotong di tengah, jadi satu-satunya cara tahu
        // berkasnya utuh ya membacanya balik. Yang cacat DIHAPUS, bukan
        // dibiarkan — meninggalkannya berarti tiap unduhan berikutnya melayani
        // berkas rusak tanpa pernah mencoba membetulkannya lagi.
        if (! $this->sehat($path)) {
            Storage::disk('arsip')->delete($path);

            Log::error('PDF sertifikat mendarat nggak utuh di disk lalu dihapus.', [
                'certificate_id' => $sertifikat->getKey(),
                'path' => $path,
                'byte_dikirim' => strlen($isi),
            ]);

            return null;
        }

        Log::warning(
            'PDF sertifikat raib dari disk arsip lalu dibangun ulang dari snapshot. '
            .'Kalau ini muncul sehabis tiap deploy, penyebabnya disk container Render yang '
            .'sementara — setel ARSIP_DRIVER=s3 (bucket R2) supaya berkasnya awet.',
            ['certificate_id' => $sertifikat->getKey(), 'nomor' => $sertifikat->nomor, 'path' => $path],
        );

        return $path;
    }

    /**
     * Berkas di disk ada DAN masuk akal sebagai PDF.
     *
     * Ukurannya dibaca dari metadata dan kepalanya dari lima byte pertama —
     * dua panggilan murah di disk lokal maupun S3, dan sengaja TIDAK menarik
     * seluruh berkasnya cuma buat memeriksa.
     */
    private function sehat(string $path): bool
    {
        $disk = Storage::disk('arsip');

        if (! $disk->exists($path)) {
            return false;
        }

        try {
            if ((int) $disk->size($path) < self::MINIMUM_BYTE) {
                return false;
            }

            $aliran = $disk->readStream($path);

            if ($aliran === null || $aliran === false) {
                return false;
            }

            $kepala = (string) fread($aliran, 5);
            fclose($aliran);
        } catch (\Throwable $e) {
            // Disk yang nggak bisa dibaca itu bukan berkas yang sehat. Jangan
            // melempar dari sini — pemanggilnya menerjemahkan `false` jadi
            // "bangun ulang", dan itu perilaku yang benar.
            Log::warning('Gagal memeriksa keutuhan PDF sertifikat.', [
                'path' => $path,
                'galat' => $e->getMessage(),
            ]);

            return false;
        }

        return str_starts_with($kepala, '%PDF');
    }

    /** Keluaran render yang masuk akal sebagai PDF utuh. */
    private function bentukPdf(string $isi): bool
    {
        return strlen($isi) >= self::MINIMUM_BYTE && str_starts_with($isi, '%PDF');
    }
}
