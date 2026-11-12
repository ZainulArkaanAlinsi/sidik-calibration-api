<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Float -> teks desimal round-trip terpendek, tidak bergantung `serialize_precision`.
 *
 * PDO mengikat float ke MySQL lewat konversi string PHP, yang memotong float
 * ber-15 digit signifikan atau lebih. `json_encode` membaca
 * `serialize_precision`: -1 memberi representasi terpendek yang tetap kembali
 * ke float yang sama, 14 memotong, 17 menambah noise (`0.10000000000000001`).
 * Jadi setelan itu dipatok -1 SEMENTARA di sini (try/finally, nilai asal
 * dipulihkan), sama polanya dengan `JsonKanonik::terpendek()`.
 *
 * Setelan server yang menyimpang tidak menggagalkan tulis: nilainya dipaksa
 * dan dicatat SEKALI per proses. Gagal keras hanya bila PHP menolak mengubah
 * setelan itu, atau hasil kodenya tidak kembali ke float yang sama.
 */
final class AngkaFloatEksak
{
    private static bool $sudahDicatat = false;

    /**
     * @throws \JsonException float tak hingga / NAN
     * @throws RuntimeException `ini_set` ditolak, atau round-trip gagal
     */
    public static function kodekan(float $nilai): string
    {
        $terkonfigurasi = (string) ini_get('serialize_precision');
        $dipaksa = $terkonfigurasi !== '-1';

        if ($dipaksa) {
            self::paksaTerpendek();
        }

        try {
            $kode = json_encode($nilai, JSON_THROW_ON_ERROR);
        } finally {
            if ($dipaksa) {
                ini_set('serialize_precision', $terkonfigurasi);
            }
        }

        if ((float) $kode !== $nilai) {
            throw new RuntimeException('Kode float tidak kembali ke nilai yang sama (round-trip gagal); nilai tidak ditulis.');
        }

        if ($dipaksa) {
            self::catatSekali($terkonfigurasi);
        }

        return $kode;
    }

    private static function paksaTerpendek(): void
    {
        // `disable_functions=ini_set` membuat fungsinya tidak ada (Error), bukan false.
        if (! function_exists('ini_set') || ini_set('serialize_precision', '-1') === false) {
            throw new RuntimeException('serialize_precision server bukan -1 dan PHP menolak mengubahnya; float tidak boleh ditulis dengan setelan ini. Setel serialize_precision = -1 di php.ini.');
        }
    }

    /** Sekali per proses, tanpa nilai pembacaan; gagal mencatat tidak boleh menggagalkan tulis. */
    private static function catatSekali(string $terkonfigurasi): void
    {
        if (self::$sudahDicatat) {
            return;
        }
        self::$sudahDicatat = true;

        try {
            Log::warning('serialize_precision server bukan -1; penulisan float mentah memaksanya sementara. Setel serialize_precision = -1 di php.ini.', [
                'serialize_precision_terkonfigurasi' => $terkonfigurasi,
            ]);
        } catch (Throwable) {
            // sengaja diam: log hanya informasi, bukan syarat tulis
        }
    }
}
