<?php

namespace App\Services\Dokumen;

/**
 * Ambang keyakinan: angka 0..1 dari AI -> kategori yang dipakai layar review.
 *
 * ## Kenapa bisa disetel, bukan dipaku
 *
 * Ambang yang benar itu urusan lab, bukan urusan kode. Lembar yang difoto di
 * ruang terang dengan tulisan rapi pantas dipercaya di 0,85; lembar yang
 * difoto di lapangan nggak pantas dipercaya di 0,95. Yang dipaku di kode
 * bakal salah di salah satu dari dua keadaan itu, dan yang kena bukan
 * programmernya.
 *
 * ## Kenapa RAGU bukan berarti KOSONG
 *
 * Sel yang keyakinannya rendah TETAP dibawa, lengkap dengan nilai tebakannya,
 * tapi ditandai [PERLU_REVIEW]. Dibuang diam-diam, teknisi nggak punya apa-apa
 * buat dikoreksi dan harus ngetik ulang dari nol — padahal tebakan yang 60%
 * benar itu titik awal yang jauh lebih cepat daripada sel kosong.
 *
 * Yang NGGAK boleh: tebakan rendah mendarat di form sebagai angka final tanpa
 * ada yang tahu. Itu sebabnya statusnya ikut sampai ke UI, bukan cuma
 * angkanya.
 */
class AmbangKeyakinan
{
    public const TINGGI = 'HIGH';

    public const SEDANG = 'MEDIUM';

    public const RENDAH = 'LOW';

    public const OK = 'OK';

    public const PERLU_REVIEW = 'REVIEW_REQUIRED';

    /** Nggak ada angka keyakinan sama sekali dari penyedia. */
    public const TIDAK_DIKETAHUI = 'UNKNOWN';

    public function __construct(
        private readonly float $tinggi = 0.90,
        private readonly float $sedang = 0.70,
    ) {}

    /**
     * Ambil ambang dari config, supaya lab bisa nyetel tanpa nyentuh kode.
     */
    public static function dariConfig(): self
    {
        return new self(
            (float) config('dokumen.keyakinan.tinggi', 0.90),
            (float) config('dokumen.keyakinan.sedang', 0.70),
        );
    }

    /**
     * @return self::TINGGI|self::SEDANG|self::RENDAH|self::TIDAK_DIKETAHUI
     */
    public function kategori(?float $nilai): string
    {
        if ($nilai === null) {
            return self::TIDAK_DIKETAHUI;
        }

        if ($nilai >= $this->tinggi) {
            return self::TINGGI;
        }

        return $nilai >= $this->sedang ? self::SEDANG : self::RENDAH;
    }

    /**
     * Status yang menentukan apakah teknisi WAJIB melihat sel ini.
     *
     * Keyakinan yang nggak diketahui diperlakukan sebagai PERLU_REVIEW, bukan
     * OK. Penyedia yang nggak ngasih angka keyakinan bukan penyedia yang yakin
     * — dan menganggapnya OK berarti menaruh tebakan tanpa ukuran ke dalam
     * sertifikat kalibrasi.
     *
     * Nilai kosong juga PERLU_REVIEW meski keyakinannya tinggi: "saya yakin
     * nggak bisa baca" tetap sel yang harus diisi orang.
     */
    public function status(?float $keyakinan, mixed $nilai = null): string
    {
        if ($nilai === null || $nilai === '') {
            return self::PERLU_REVIEW;
        }

        return $this->kategori($keyakinan) === self::TINGGI ? self::OK : self::PERLU_REVIEW;
    }
}
