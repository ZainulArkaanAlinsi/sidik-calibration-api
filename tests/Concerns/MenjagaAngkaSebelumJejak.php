<?php

namespace Tests\Concerns;

/**
 * Pembanding "angka SEBELUM catatan `penyimpangan_master` = angka SESUDAH".
 *
 * PK-9 menambah baris jejak, tidak boleh menggeser satu angka pun. Golden-nya
 * direkam dari kode `origin/main` SEBELUM paket ini (7e510410) lewat
 * `REKAM_ANGKA_PK9=1`, dengan baris jejak ber-`sumber` `penyimpangan_master.*`
 * (dan kunci petanya) DIBUANG. Test kemudian menjalankan kode sekarang, membuang
 * hal yang sama, lalu menuntut sisanya sama: nilai hasil, budget, uc, veff, k,
 * U95, keputusan, dan baris jejak lama.
 *
 * Pola sama dengan `PutaranJejakPenyimpanganTest`. Merekam ulang HANYA bila angka
 * memang sengaja berubah lewat PR rumus + simulasi — bukan untuk menghijaukan:
 *
 *     REKAM_ANGKA_PK9=1 php artisan test --filter=<KelasTest>
 *
 * Toleransi 1e-12 relatif (bukan bit-per-bit): golden direkam di Windows, CI di
 * Linux, dan `pow()`/`exp()` libm boleh beda satu ulp. Ketelitian itu jauh di
 * bawah 1e-8 yang menentukan angka cetak.
 */
trait MenjagaAngkaSebelumJejak
{
    /**
     * @param  array<mixed>  $sekarang
     */
    protected function cocokkanGoldenAngka(string $nama, array $sekarang): void
    {
        $berkas = dirname(__DIR__).'/Fixtures/penyimpangan-master/'.$nama.'.json';

        ini_set('serialize_precision', '-1');
        $json = json_encode(
            $sekarang,
            JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if (getenv('REKAM_ANGKA_PK9') === '1') {
            if (! is_dir(dirname($berkas))) {
                mkdir(dirname($berkas), 0777, true);
            }

            file_put_contents($berkas, $json."\n");
            $this->markTestSkipped("Mode rekam: golden {$nama} ditulis ulang.");
        }

        $this->assertFileExists($berkas, "Golden {$nama} belum direkam (REKAM_ANGKA_PK9=1 pada kode SEBELUM perubahan).");

        $this->bandingkanPohonAngka(
            json_decode((string) file_get_contents($berkas), true, flags: JSON_THROW_ON_ERROR),
            json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            $nama,
        );
    }

    private function bandingkanPohonAngka(mixed $harap, mixed $dapat, string $jalur): void
    {
        if (is_array($harap) && is_array($dapat)) {
            $this->assertSame(
                array_keys($harap),
                array_keys($dapat),
                "{$jalur}: kunci berubah — catatan penyimpangan tidak boleh menambah/menghapus kunci hasil.",
            );

            foreach ($harap as $kunci => $nilai) {
                $this->bandingkanPohonAngka($nilai, $dapat[$kunci], $jalur.'.'.$kunci);
            }

            return;
        }

        if ((is_int($harap) || is_float($harap)) && (is_int($dapat) || is_float($dapat))) {
            $this->assertEqualsWithDelta(
                (float) $harap,
                (float) $dapat,
                1e-12 * max(1.0, abs((float) $harap)),
                "{$jalur}: angka hasil hitung BERGESER dibanding sebelum catatan penyimpangan ada.",
            );

            return;
        }

        $this->assertSame($harap, $dapat, "{$jalur}: nilai berubah dibanding sebelum catatan penyimpangan ada.");
    }
}
