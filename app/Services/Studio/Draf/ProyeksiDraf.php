<?php

namespace App\Services\Studio\Draf;

use App\Services\Studio\PetaSel\JalurIsi;
use App\Support\JsonKanonik;
use RuntimeException;
use UnexpectedValueException;

/**
 * Proyeksi sel yang bisa disunting dari isi draf tersimpan, untuk respons API
 * (A1): `list<{sel, jalur, nilai}>` menurut [PemetaanSuntingan].
 *
 * `nilai` SELALU string kanonik daun (`JsonKanonik::serialisasi($daun)`), bukan
 * float: int tetap `"200"`, float tetap `"200.0"`, `-0.0` menjadi `"0.0"`
 * (kanonik membuang tanda nol), dan float terpendek round-trip (15 digit tetap
 * 15 digit). Klien tidak mengurai JSON number, jadi tidak ada pembulatan
 * diam-diam di sisi klien maupun `json_encode`.
 *
 * Daun diambil dengan [JalurIsi::ambil] dari isi yang SUDAH didekode (array
 * asosiatif); proyeksi tidak mengubah tipe atau membulatkan apa pun.
 *
 * Gagal keras, bukan `null` diam-diam: daun hilang, bukan int/float, atau tidak
 * hingga -> [UnexpectedValueException] (pesan menyebut sel dan jalur). Proyeksi
 * yang bohong lebih buruk daripada respons yang gagal.
 */
final class ProyeksiDraf
{
    private function __construct() {}

    /**
     * @param  array<array-key, mixed>  $isi  isi draf hasil decode (assoc)
     * @param  list<array{sel: string, jalur: string}>  $pemetaan  keluaran [PemetaanSuntingan]
     * @return list<array{sel: string, jalur: string, nilai: string}>
     *
     * @throws UnexpectedValueException daun hilang / bukan angka hingga / entri pemetaan rusak
     */
    public static function dari(array $isi, array $pemetaan): array
    {
        $hasil = [];

        foreach ($pemetaan as $entri) {
            if (! is_array($entri) || ! is_string($entri['sel'] ?? null) || ! is_string($entri['jalur'] ?? null)) {
                throw new UnexpectedValueException('Entri pemetaan harus memuat sel dan jalur bertipe teks.');
            }

            $sel = $entri['sel'];
            $jalur = $entri['jalur'];

            try {
                $daun = JalurIsi::ambil($isi, $jalur);
            } catch (RuntimeException $e) {
                throw new UnexpectedValueException("Daun {$sel} ({$jalur}) tidak ada di isi draf.", previous: $e);
            }

            if ((! is_int($daun) && ! is_float($daun)) || (is_float($daun) && ! is_finite($daun))) {
                throw new UnexpectedValueException("Daun {$sel} ({$jalur}) bukan angka hingga (".get_debug_type($daun).').');
            }

            $hasil[] = ['sel' => $sel, 'jalur' => $jalur, 'nilai' => JsonKanonik::serialisasi($daun)];
        }

        return $hasil;
    }
}
