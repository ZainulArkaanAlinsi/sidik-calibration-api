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

        // Tingkat LONGGAR dicoba lebih dulu, dari yang terbesar. Sertifikat
        // berisi sedikit dulu terbit memakai ~60 % kertas dengan huruf kecil
        // (keluhan pemilik proyek 15 Sep 2026, `CAL/2026/09/0007`); sekarang
        // yang dipakai ukuran TERBESAR yang masih satu halaman, lengkap dengan
        // cadangan ruang di bawah footer (`.cadangan-footer` di blade).
        //
        // Ongkosnya dibayar sertifikat panjang saja: yang pendek lolos di
        // percobaan pertama, sama seperti dulu lolos di mode normal.
        //
        // Urutannya: longgar-2, longgar-1, normal, rapat-1, rapat-2, lalu jalur
        // `paling()` (normal → padat). Normal ikut dicoba di sini supaya
        // sertifikat yang dulu muat normal tetap persis sama bentuknya.
        // Timbangan & Autoklaf punya pemadatan sendiri dan blade mengabaikan
        // `longgar` untuk keduanya — mencobanya cuma merender lembar identik
        // lima kali di server 0,1 CPU. Sertifikat berbaris banyak TETAP dicoba:
        // tebakan `> 12 baris = padat` di blade dimatikan begitu tingkat dikirim.
        $snapshot = $bahan['snapshot'] ?? [];
        $tingkat = ($snapshot['timbangan'] ?? null) !== null || ($snapshot['autoclave'] ?? null) !== null
            ? []
            : [2, 1, 0, -1, -2];

        foreach ($tingkat as $longgar) {
            // DIUKUR dengan kotak uji 6 px di kaki halaman, DICETAK tanpa dia.
            //
            // Tingkat yang muat "pas" — sisa nol piksel — ditolak di sini:
            // lembar seperti itu jatuh ke halaman dua begitu ada satu baris
            // tambahan (nama pelanggan lebih panjang, satu titik ukur lagi),
            // dan headernya tetap mencetak `Page : 1 of 1`. Ketahuan 16 Sep
            // 2026 waktu sertifikat Chlorine bertambah kolom U95 per titik.
            //
            // Kotaknya sengaja TIDAK ikut ke PDF terbit: menambah ruang di tiap
            // lembar akan mendorong sertifikat yang selama ini muat normal ke
            // mode yang lebih rapat, yaitu menggeser tata letak dokumen yang
            // sudah beredar. Yang berubah cuma SYARAT pemilihan tingkat.
            if ($this->cetak($bahan, false, $longgar, ujiCadangan: true)['halaman'] > 1) {
                continue;
            }

            $hasil = $this->cetak($bahan, false, $longgar);

            if ($hasil['halaman'] <= 1) {
                return $hasil['isi'];
            }
        }

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
    private function cetak(array $bahan, bool $paksaPadat, int $longgar = 0, bool $ujiCadangan = false): array
    {
        $pdf = Pdf::loadView('sertifikat.pdf', [
            ...$bahan,
            'paksaPadat' => $paksaPadat,
            'longgar' => $longgar,
            // Kotak uji ruang sisa — cuma dipasang waktu tingkat kerapatan
            // sedang dicari, tidak pernah di PDF yang diterbitkan.
            'ujiCadangan' => $ujiCadangan,
        ]);

        // `output()` dipanggil DULU: jumlah halaman baru ada sesudah dompdf
        // benar-benar merender, dan `getCanvas()` sebelum itu balik nol.
        $isi = $pdf->output();

        return [
            'isi' => $isi,
            'halaman' => (int) $pdf->getDomPDF()->getCanvas()->get_page_count(),
        ];
    }
}
