<?php

namespace Tests\Concerns;

use stdClass;

/**
 * Pembantu pemeriksa `skemaAcuan()` lawan berkas JSON-nya (dipindah dari
 * `SkemaAcuanMicrometerTest`, dipakai juga `SkemaAcuanProfilTest`). Aturan
 * daun sama dengan `docs/studio-data-acuan/alat-bantu/bangun_katalog.py`.
 */
trait PeriksaSkemaAcuan
{
    private function assertTipe(string $tipe, mixed $nilai, string $jalur): void
    {
        match ($tipe) {
            'teks' => $this->assertIsString($nilai, $jalur),
            'tanggal' => $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $nilai, $jalur),
            'desimal' => $this->assertIsFloat($nilai, $jalur),
            'bulat' => $this->assertIsInt($nilai, $jalur),
            'daftar_angka' => $this->assertTrue(
                is_array($nilai) && array_is_list($nilai) && array_filter($nilai, 'is_float') === $nilai,
                "{$jalur} bukan list float",
            ),
        };
    }

    /**
     * Jalur daun yang diwakili satu lembar.
     *
     * @param  array<string, mixed>  $lembar
     * @return list<string>
     */
    private function daunLembar(array $lembar, stdClass $isi): array
    {
        $hasil = [];
        $simpulDitemukan = self::simpul($isi, explode('/', $lembar['jalur']), '');
        $this->assertNotEmpty($simpulDitemukan, "jalur {$lembar['jalur']} tidak ada di JSON");

        foreach ($simpulDitemukan as [$jalur, $simpul]) {
            switch ($lembar['jenis']) {
                case 'objek':
                    $this->assertInstanceOf(stdClass::class, $simpul, "{$jalur} bukan objek");

                    foreach ($lembar['kolom'] as $kolom) {
                        $hasil[] = "{$jalur}/{$kolom['kode']}";
                    }

                    break;

                case 'baris':
                    // Peta `kunci => nilai`, atau list yang kuncinya POSISI
                    // (kolom kunci bertipe `bulat`, mis. tumpukan_depth_mm).
                    $posisi = is_array($simpul) && array_is_list($simpul);
                    $this->assertTrue($simpul instanceof stdClass || $posisi, "{$jalur} bukan peta/list");

                    foreach (array_keys($posisi ? $simpul : get_object_vars($simpul)) as $k) {
                        $hasil[] = "{$jalur}/{$k}";
                    }

                    break;

                case 'daftar':
                    $this->assertTrue(is_array($simpul) && array_is_list($simpul), "{$jalur} bukan list");

                    foreach ($simpul as $i => $baris) {
                        $this->assertInstanceOf(stdClass::class, $baris, "{$jalur}/{$i} bukan objek");

                        foreach ($lembar['kolom'] as $kolom) {
                            $hasil[] = "{$jalur}/{$i}/{$kolom['kode']}";
                        }
                    }

                    break;
            }
        }

        return $hasil;
    }

    /**
     * Simpul JSON pada jalur `a/b`, dengan `*` = tiap elemen list.
     *
     * @param  list<string>  $segmen
     * @return list<array{string, mixed}>
     */
    private static function simpul(mixed $nilai, array $segmen, string $jalur): array
    {
        if ($segmen === []) {
            return [[$jalur, $nilai]];
        }

        $kepala = array_shift($segmen);
        $awalan = $jalur === '' ? '' : "{$jalur}/";

        if ($kepala === '*') {
            if (! is_array($nilai)) {
                return [];
            }

            $hasil = [];

            foreach ($nilai as $i => $anak) {
                array_push($hasil, ...self::simpul($anak, $segmen, "{$awalan}{$i}"));
            }

            return $hasil;
        }

        if (! $nilai instanceof stdClass || ! property_exists($nilai, $kepala)) {
            return [];
        }

        return self::simpul($nilai->{$kepala}, $segmen, "{$awalan}{$kepala}");
    }

    /** @param  list<string>  $hasil */
    private static function kumpulkanDaun(mixed $nilai, string $jalur, array &$hasil): void
    {
        if ($nilai instanceof stdClass) {
            foreach (get_object_vars($nilai) as $k => $v) {
                self::kumpulkanDaun($v, "{$jalur}/{$k}", $hasil);
            }

            return;
        }

        if (is_array($nilai) && $nilai !== [] && array_filter($nilai, 'is_scalar') !== $nilai) {
            foreach ($nilai as $i => $v) {
                self::kumpulkanDaun($v, "{$jalur}/{$i}", $hasil);
            }

            return;
        }

        $hasil[] = $jalur;
    }
}
