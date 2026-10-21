<?php

namespace Tests\Concerns;

/**
 * Kolom JSON MySQL mengurutkan ulang kunci objek (panjang kunci, lalu byte),
 * jadi peta snapshot yang dibaca balik dari MySQL berurutan lain dari waktu
 * dibangun. Urutan kunci peta tidak dibaca siapa pun (dijaga
 * `SnapshotUrutanKunciMysqlTest`); urutan DAFTAR tetap berarti dan tetap
 * diadu. Pembandingan tetap `assertSame` (tipe ketat) sesudah kunci tiap peta
 * diurutkan rekursif.
 */
trait PetaJsonTanpaUrutan
{
    protected function assertSamaPetaJson(mixed $harapan, mixed $nyata, string $pesan = ''): void
    {
        $this->assertSame(self::urutKunciPeta($harapan), self::urutKunciPeta($nyata), $pesan);
    }

    private static function urutKunciPeta(mixed $nilai): mixed
    {
        if (! is_array($nilai)) {
            return $nilai;
        }

        $nilai = array_map(self::urutKunciPeta(...), $nilai);

        if (! array_is_list($nilai)) {
            ksort($nilai, SORT_STRING);
        }

        return $nilai;
    }
}
