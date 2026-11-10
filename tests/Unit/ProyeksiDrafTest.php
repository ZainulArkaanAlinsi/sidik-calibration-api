<?php

namespace Tests\Unit;

use App\Services\Studio\Draf\ProyeksiDraf;
use App\Support\JsonKanonik;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/** Proyeksi respons: nilai = STRING kanonik daun, tanpa pembulatan/ubah tipe. */
class ProyeksiDrafTest extends TestCase
{
    private const PEMETAAN = [
        ['sel' => 'DATABASE!T5', 'jalur' => 'cmc[kode=A].u95_um', 'tipe' => 'angka', 'bulat' => 10],
        ['sel' => 'DATABASE!T6', 'jalur' => 'cmc[kode=B].u95_um', 'tipe' => 'angka', 'bulat' => 10],
    ];

    /** @param  int|float  $a */
    private function isi(mixed $a, mixed $b = 1.5): array
    {
        return ['cmc' => [['kode' => 'A', 'u95_um' => $a, 'lain' => 7], ['kode' => 'B', 'u95_um' => $b]], 'x' => 'y'];
    }

    /** @return iterable<string, array{int|float, string}> */
    public static function daun(): iterable
    {
        yield 'int tetap int' => [200, '200'];
        yield 'float bulat tetap float' => [200.0, '200.0'];
        yield 'nol int' => [0, '0'];
        yield 'nol float' => [0.0, '0.0'];
        yield 'minus nol float tanpa tanda' => [-0.0, '0.0'];
        yield 'negatif int' => [-3, '-3'];
        yield 'negatif float' => [-3.25, '-3.25'];
        yield 'pecahan' => [0.83, '0.83'];
        yield 'klasik 0.1' => [0.1, '0.1'];
        yield '15 digit' => [0.123456789012345, '0.123456789012345'];
        yield '15 digit bulat besar' => [123456789012345.0, '123456789012345.0'];
        yield '10 pecahan' => [1.2345678901, '1.2345678901'];
        yield 'sangat kecil tanpa eksponen' => [1.0E-7, '0.0000001'];
        yield 'sangat besar tanpa eksponen' => [1.0E+25, '10000000000000000000000000.0'];
        yield 'int besar' => [9007199254740993, '9007199254740993'];
    }

    #[DataProvider('daun')]
    public function test_nilai_adalah_string_kanonik_daun(int|float $daun, string $harapan): void
    {
        $hasil = ProyeksiDraf::dari($this->isi($daun), self::PEMETAAN);

        $this->assertCount(2, $hasil);
        $this->assertIsString($hasil[0]['nilai']);
        $this->assertSame($harapan, $hasil[0]['nilai']);
        $this->assertSame(['sel' => 'DATABASE!T5', 'jalur' => 'cmc[kode=A].u95_um', 'nilai' => $harapan], $hasil[0]);
        $this->assertSame(['sel', 'jalur', 'nilai'], array_keys($hasil[0]));
    }

    public function test_respons_json_membawa_string_bukan_angka(): void
    {
        $json = json_encode(ProyeksiDraf::dari($this->isi(200.0, 200), self::PEMETAAN), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"nilai":"200.0"', $json);
        $this->assertStringContainsString('"nilai":"200"', $json);
        $this->assertDoesNotMatchRegularExpression('/"nilai":[0-9-]/', $json);
    }

    public function test_dibaca_dari_teks_tersimpan_int_dan_float_tidak_tertukar(): void
    {
        // Teks kanonik seperti yang tersimpan: `200` (int) dan `200.0` (float) berbeda byte.
        $teks = '{"cmc":[{"kode":"A","u95_um":200},{"kode":"B","u95_um":200.0}]}';
        $isi = json_decode($teks, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($teks, JsonKanonik::serialisasi($isi));
        $this->assertSame(['200', '200.0'], array_column(ProyeksiDraf::dari($isi, self::PEMETAAN), 'nilai'));
    }

    public function test_minus_nol_integer_dari_teks_menjadi_nol_int(): void
    {
        $isi = json_decode('{"cmc":[{"kode":"A","u95_um":-0},{"kode":"B","u95_um":1.0}]}', true, 512, JSON_THROW_ON_ERROR);

        // Premis: PHP mendekode literal `-0` sebagai INT 0 (bukan float -0.0).
        $this->assertSame(0, $isi['cmc'][0]['u95_um']);
        $this->assertSame('0', ProyeksiDraf::dari($isi, self::PEMETAAN)[0]['nilai']);
    }

    public function test_minus_nol_float_dari_teks_menjadi_nol_float_tanpa_tanda(): void
    {
        $isi = json_decode('{"cmc":[{"kode":"A","u95_um":-0.0},{"kode":"B","u95_um":1.0}]}', true, 512, JSON_THROW_ON_ERROR);

        // Premis: float dengan bit tanda menyala (1/-0.0 = -INF), bukan 0.0 biasa.
        $this->assertIsFloat($isi['cmc'][0]['u95_um']);
        $this->assertSame(-INF, fdiv(1.0, $isi['cmc'][0]['u95_um']));
        $this->assertSame('0.0', ProyeksiDraf::dari($isi, self::PEMETAAN)[0]['nilai']);
    }

    /**
     * Harapan ditulis sebagai string literal (representasi terpendek round-trip),
     * bukan dihitung ulang oleh kode yang diuji.
     *
     * @return iterable<string, array{float, string}>
     */
    public static function roundTrip(): iterable
    {
        yield '0.83' => [0.83, '0.83'];
        yield '0.87' => [0.87, '0.87'];
        yield '0.1 + 0.2' => [0.1 + 0.2, '0.30000000000000004'];
        yield '1/3' => [1 / 3, '0.3333333333333333'];
        yield '17 angka penting' => [123456789.123456789, '123456789.12345679'];
        yield '0.12345678905' => [0.12345678905, '0.12345678905'];
        yield 'subnormal terkecil' => [5e-324, '0.'.str_repeat('0', 323).'5'];
        yield 'float maksimum' => [1.7976931348623157e308, '17976931348623157'.str_repeat('0', 292).'.0'];
    }

    #[DataProvider('roundTrip')]
    public function test_round_trip_nilai_string_kembali_ke_float_identik_bit(float $f, string $harapan): void
    {
        $nilai = ProyeksiDraf::dari($this->isi($f), self::PEMETAAN)[0]['nilai'];

        $this->assertSame($harapan, $nilai);
        $this->assertSame($f, (float) $nilai);
    }

    public function test_urutan_mengikuti_pemetaan_dan_isi_tak_berubah(): void
    {
        $isi = $this->isi(0.83, 0.87);
        $salin = $isi;
        $terbalik = array_reverse(self::PEMETAAN);

        $hasil = ProyeksiDraf::dari($isi, $terbalik);

        $this->assertSame(['DATABASE!T6', 'DATABASE!T5'], array_column($hasil, 'sel'));
        $this->assertSame(['0.87', '0.83'], array_column($hasil, 'nilai'));
        $this->assertSame($salin, $isi);
        $this->assertSame([], ProyeksiDraf::dari($isi, []));
    }

    /** @return iterable<string, array{mixed}> */
    public static function daunBukanAngka(): iterable
    {
        yield 'string angka' => ['200.0'];
        yield 'string kosong' => [''];
        yield 'null' => [null];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'array' => [[1, 2]];
        yield 'array kosong' => [[]];
        yield 'INF' => [INF];
        yield 'NAN' => [NAN];
        yield '-INF' => [-INF];
    }

    #[DataProvider('daunBukanAngka')]
    public function test_daun_bukan_angka_hingga_melempar_bukan_null(mixed $daun): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('DATABASE!T5');

        ProyeksiDraf::dari($this->isi($daun), self::PEMETAAN);
    }

    public function test_daun_hilang_melempar_dengan_sel_dan_jalur(): void
    {
        foreach ([
            ['cmc' => [['kode' => 'B', 'u95_um' => 1.0]]], // pita A tidak ada
            ['cmc' => [['kode' => 'A'], ['kode' => 'B', 'u95_um' => 1.0]]], // kunci tidak ada
            ['cmc' => 'bukan list'],
            ['lain' => 1],
            [],
        ] as $i => $isi) {
            try {
                ProyeksiDraf::dari($isi, self::PEMETAAN);
                $this->fail("kasus {$i}: seharusnya melempar");
            } catch (UnexpectedValueException $e) {
                $this->assertStringContainsString('DATABASE!T5', $e->getMessage(), "kasus {$i}");
                $this->assertStringContainsString('cmc[kode=A].u95_um', $e->getMessage(), "kasus {$i}");
            }
        }
    }

    public function test_daun_kedua_hilang_tetap_melempar_tanpa_hasil_sebagian(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('DATABASE!T6');

        ProyeksiDraf::dari(['cmc' => [['kode' => 'A', 'u95_um' => 1.0], ['kode' => 'B']]], self::PEMETAAN);
    }

    public function test_entri_pemetaan_rusak_melempar(): void
    {
        foreach ([[['jalur' => 'a.b']], [['sel' => 'S!A1']], [['sel' => 1, 'jalur' => 'a.b']], ['bukan array']] as $pemetaan) {
            try {
                ProyeksiDraf::dari(['a' => ['b' => 1]], $pemetaan);
                $this->fail('seharusnya melempar');
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_micrometer_asli_empat_nilai_dari_berkas_repo(): void
    {
        $isi = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/database/data/tabel-standar-micrometer.json'), true, 512, JSON_THROW_ON_ERROR);
        $pemetaan = [];

        foreach (['A' => 'T5', 'B' => 'T6', 'C' => 'T7', 'D' => 'T8'] as $kode => $sel) {
            $pemetaan[] = ['sel' => "DATABASE!{$sel}", 'jalur' => "cmc[kode={$kode}].u95_um", 'tipe' => 'angka', 'bulat' => 10];
        }

        $this->assertSame(['0.83', '0.87', '0.91', '0.91'], array_column(ProyeksiDraf::dari($isi, $pemetaan), 'nilai'));
    }
}
