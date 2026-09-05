<?php

namespace App\Services;

use App\Models\Certificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

/**
 * Merender PDF sertifikat dan MEMASTIKAN dia satu halaman — bukan berharap.
 *
 * ## Kenapa ini ada
 *
 * Header sertifikat mencetak `Page : 1 of 1`, dan angka itu **ditulis mati** di
 * `CertificateSnapshotBuilder` lalu dibekukan ke snapshot waktu terbit. Dia
 * tidak pernah dihitung dari halaman yang benar-benar dirender. Jadi sertifikat
 * yang meluap ke halaman dua tetap mencetak "1 of 1" — di kedua halamannya.
 *
 * Itu bukan tata letak yang jelek. Itu dokumen terkendali yang menyatakan hal
 * yang tidak benar tentang dirinya sendiri.
 *
 * ## Yang dipakai sebelum ini, dan kenapa bisa meleset
 *
 * Blade menyalakan mode padat dari TEBAKAN: `> 12 baris hasil`, atau lembar
 * Timbangan. Tebakan itu memakai jumlah baris sebagai wakil dari tinggi
 * halaman — dan wakilnya bocor. Logo potret, kop surat tinggi, atau catatan
 * panjang menambah tinggi tanpa menambah satu baris pun, jadi pemicunya tidak
 * kena dan sertifikatnya meluap diam-diam.
 *
 * Di sini yang dipakai kenyataan: dompdf melaporkan jumlah halaman SESUDAH
 * render (`get_page_count()`), jadi tidak perlu menebak sama sekali.
 *
 * ## Urutannya
 *
 *   1. Render apa adanya. Muat satu halaman → selesai, tidak ada ongkos tambahan.
 *   2. Belum muat → render ulang dengan mode padat DIPAKSA.
 *   3. Masih belum muat → yang paling ringkas yang dipakai, dan kegagalannya
 *      DICATAT sebagai error. Tidak ada yang dipalsukan: kalau lembarnya memang
 *      tidak bisa dipadatkan lagi, yang salah datanya atau gambarnya, dan itu
 *      harus kelihatan — bukan ditelan diam-diam seperti sebelumnya.
 *
 * Render kedua cuma jalan buat sertifikat yang memang bermasalah. Yang sudah
 * muat — mayoritasnya — tetap satu kali render.
 */
class SertifikatSatuHalaman
{
    public function __construct(private DataTampilanSertifikat $tampilan) {}

    /** Isi PDF sertifikat, sepadat yang diperlukan supaya muat satu halaman. */
    public function isi(Certificate $sertifikat): string
    {
        $bahan = $this->tampilan->untuk($sertifikat);

        return $this->paling(
            fn (bool $paksaPadat): array => $this->cetak($bahan, $paksaPadat),
            $sertifikat->getKey(),
        );
    }

    /**
     * Aturan pemilihannya, dipisah dari dompdf supaya bisa diuji sendirian.
     *
     * @param  callable(bool): array{isi: string, halaman: int}  $cetak
     */
    public function paling(callable $cetak, int|string|null $id = null): string
    {
        // Urutannya dari yang paling ringan: percobaan pertama TIDAK memaksa
        // apa pun, jadi sertifikat yang memang sudah muat tidak membayar
        // ongkos render kedua.
        $hasil = $cetak(false);

        if ($hasil['halaman'] <= 1) {
            return $hasil['isi'];
        }

        $padat = $cetak(true);

        if ($padat['halaman'] <= 1) {
            return $padat['isi'];
        }

        // Sudah sepadat yang bisa, masih meluap. Yang dikembalikan tetap versi
        // terpadatnya — dua halaman yang terbaca lebih berguna daripada tidak
        // ada PDF sama sekali — tapi kegagalannya TIDAK ditelan: headernya
        // terlanjur mencetak "1 of 1", jadi selisih itu harus ada jejaknya.
        Log::error('Sertifikat nggak muat satu halaman walau mode padat dipaksa.', [
            'certificate_id' => $id,
            'halaman_normal' => $hasil['halaman'],
            'halaman_padat' => $padat['halaman'],
            'petunjuk' => 'Tersangka yang nambah tinggi tanpa nambah baris: logo potret, '
                .'kop surat tinggi, catatan panjang. Lihat `.kop td.logo img` & `.kop-gambar img` '
                .'di resources/views/sertifikat/pdf.blade.php — dua-duanya `height: auto`.',
        ]);

        return $padat['isi'];
    }

    /** @return array{isi: string, halaman: int} */
    private function cetak(array $bahan, bool $paksaPadat): array
    {
        $pdf = Pdf::loadView('sertifikat.pdf', [...$bahan, 'paksaPadat' => $paksaPadat]);

        // `output()` dipanggil DULU: jumlah halaman baru ada sesudah dompdf
        // benar-benar merender, dan `getCanvas()` sebelum itu balik nol.
        $isi = $pdf->output();

        return [
            'isi' => $isi,
            'halaman' => (int) $pdf->getDomPDF()->getCanvas()->get_page_count(),
        ];
    }
}
