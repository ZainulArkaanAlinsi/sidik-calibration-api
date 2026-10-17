<?php

namespace Tests\Unit;

use App\Support\JsonKanonik;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

/**
 * Bentuk kanonik `paket_acuan_versi.isi` (Studio Data Acuan, 03-SDD §2.6).
 *
 * ## Kenapa tes ini ada
 *
 * `isi_sha256` mengunci versi yang sudah diajukan. Hash itu cuma bermakna
 * kalau isi yang SAMA selalu menjadi byte yang SAMA — dan kalau byte itu
 * dibaca balik, angkanya identik BIT dengan angka sebelum disimpan. Yang
 * pertama gagal → versi yang tidak disentuh kelihatan berubah. Yang kedua
 * gagal → calculator menerima angka yang bergeser satu ulp, tanpa error, dan
 * selisihnya baru kelihatan waktu sertifikat diadu ke master.
 *
 * Nilai harapan string di sini ditulis tangan dari aturan, bukan disalin dari
 * keluaran kodenya.
 */
class JsonKanonikTest extends TestCase
{
    public function test_urutan_kunci_acak_menghasilkan_byte_dan_sha256_sama(): void
    {
        $dasar = [
            'skema' => 'micrometer@1',
            'lembar' => [
                'konstanta' => ['wringing_um' => 0.111803398875, 'delta_alpha_per_c' => 1e-05, 'vi_type_b' => 200],
                'balok_ukur' => ['baris' => [['kunci' => '1.0', 'nilai' => 1.00011], ['kunci' => '2.5', 'nilai' => 2.50014]]],
                'standar' => ['seri' => '160006', 'nama' => 'Gauge Block Standard'],
            ],
            'Zulu' => null,
            'alfa' => true,
        ];

        $acuan = JsonKanonik::serialisasi($dasar);

        mt_srand(4848);

        for ($i = 0; $i < 25; $i++) {
            $acak = $this->acakUrutanKunci($dasar);

            $this->assertSame($acuan, JsonKanonik::serialisasi($acak));
            $this->assertSame(JsonKanonik::sha256($acuan), JsonKanonik::sha256(JsonKanonik::serialisasi($acak)));
            // Jalur teks juga: JSON yang sama dengan urutan kunci lain.
            $this->assertSame($acuan, JsonKanonik::dariTeks(json_encode($acak, JSON_THROW_ON_ERROR)));
        }
    }

    public function test_objek_bersarang_diurutkan_rekursif_secara_byte(): void
    {
        $teks = '{"b":1,"a":{"z":[3,1.5,{"d":null,"c":"x"}],"B":2,"_":0},"A":false}';

        // Urutan byte: "A"(0x41) < "B"(0x42) < "_"(0x5F) < "a"(0x61) < "b" < "z".
        $this->assertSame(
            '{"A":false,"a":{"B":2,"_":0,"z":[3,1.5,{"c":"x","d":null}]},"b":1}',
            JsonKanonik::dariTeks($teks),
        );
    }

    public function test_list_tetap_urut_termasuk_list_objek(): void
    {
        $this->assertSame('[3,1,2]', JsonKanonik::serialisasi([3, 1, 2]));
        $this->assertSame(
            '[{"maks_mm":"50.0","u_um":"0.25"},{"maks_mm":"10.0","u_um":"0.12"}]',
            JsonKanonik::dariTeks('[{"u_um":"0.25","maks_mm":"50.0"},{"u_um":"0.12","maks_mm":"10.0"}]'),
        );
    }

    public function test_tanpa_spasi_dan_string_tidak_di_escape_berlebihan(): void
    {
        $this->assertSame(
            '{"a":"Metrology/GB-9122-0","b":"suhu 20 °C","c":"kutip \" dan \\\\ miring"}',
            JsonKanonik::serialisasi(['c' => 'kutip " dan \\ miring', 'b' => 'suhu 20 °C', 'a' => 'Metrology/GB-9122-0']),
        );
    }

    public function test_int_dan_float_dibedakan(): void
    {
        $this->assertSame('200', JsonKanonik::serialisasi(200));
        $this->assertSame('200.0', JsonKanonik::serialisasi(200.0));

        $this->assertIsInt(json_decode(JsonKanonik::serialisasi(200)));
        $this->assertIsFloat(json_decode(JsonKanonik::serialisasi(200.0)));

        // Lewat teks juga: `200` dan `200.0` di masukan tetap beda tipenya.
        $this->assertSame('{"f":200.0,"i":200}', JsonKanonik::dariTeks('{"i":200,"f":200.0}'));
        $this->assertSame('{"f":200.0}', JsonKanonik::dariTeks('{"f":2e2}'));
    }

    /** @return array<string, array{float, string}> */
    public static function bentukFloat(): array
    {
        return [
            '1e-05' => [1e-05, '0.00001'],
            '0.1' => [0.1, '0.1'],
            '2.50014' => [2.50014, '2.50014'],
            '100.00012' => [100.00012, '100.00012'],
            '0.111803398875' => [0.111803398875, '0.111803398875'],
            '0.00025' => [0.00025, '0.00025'],
            '1.0' => [1.0, '1.0'],
            '0.0' => [0.0, '0.0'],
            '-0.0' => [-0.0, '0.0'],
            '-2.50014' => [-2.50014, '-2.50014'],
            '-1e-05' => [-1e-05, '-0.00001'],
            '-200.0' => [-200.0, '-200.0'],
            '1.5e-07' => [1.5e-07, '0.00000015'],
            '1e+25' => [1e25, '10000000000000000000000000.0'],
            '0.1+0.2' => [0.1 + 0.2, '0.30000000000000004'],
        ];
    }

    #[DataProvider('bentukFloat')]
    public function test_float_ditulis_desimal_terpendek_tanpa_eksponen(float $angka, string $harapan): void
    {
        $this->assertSame($harapan, JsonKanonik::serialisasi($angka));
    }

    public function test_nol_negatif_dinormalkan_ke_nol_positif(): void
    {
        $teks = JsonKanonik::serialisasi(-0.0);

        $this->assertSame('0.0', $teks);
        // Satu-satunya float yang SENGAJA tidak kembali identik bit: tanda nol
        // dibuang supaya `0.0` dan `-0.0` tidak menghasilkan hash berbeda.
        $this->assertSame(pack('e', 0.0), pack('e', json_decode($teks)));
    }

    /**
     * Invarian inti: tiap float hingga (selain -0.0) kembali identik bit.
     *
     * Pola bit acak menyapu seluruh rentang eksponen termasuk subnormal;
     * angka "desimal rapi" menyapu bentuk yang benar-benar dipakai tabel
     * acuan. Benih tetap supaya kegagalan bisa diulang.
     */
    public function test_float_kembali_identik_bit_dan_selalu_berbentuk_desimal_biasa(): void
    {
        mt_srand(20261008);

        $kandidat = [
            PHP_FLOAT_MAX, -PHP_FLOAT_MAX, PHP_FLOAT_MIN, PHP_FLOAT_EPSILON,
            5e-324, -5e-324, 2.2250738585072009e-308, 1 / 3, 2 / 3, 9007199254740993.0,
            1.00011, 0.83, 0.12, 0.26, 0.73, 1e-05, 0.00025, 0.111803398875,
        ];

        for ($i = 0; $i < 20000; $i++) {
            $kandidat[] = unpack('e', pack('V2', mt_rand(0, 0xFFFFFFFF), mt_rand(0, 0xFFFFFFFF)))[1];
        }

        for ($i = 0; $i < 5000; $i++) {
            $kandidat[] = round((mt_rand() / mt_getrandmax() - 0.5) * 10 ** mt_rand(-6, 6), mt_rand(0, 12));
        }

        $diuji = 0;

        foreach ($kandidat as $f) {
            if (is_nan($f) || is_infinite($f) || ($f == 0.0 && pack('e', $f) !== pack('e', 0.0))) {
                continue;
            }

            $teks = JsonKanonik::serialisasi($f);
            $balik = json_decode($teks, false, 512, JSON_THROW_ON_ERROR);

            $this->assertIsFloat($balik, "{$teks} tidak terbaca balik sebagai float");
            $this->assertSame(bin2hex(pack('e', $f)), bin2hex(pack('e', $balik)), "Bit berubah: {$teks}");
            // Desimal biasa: tanpa eksponen, satu digit pecahan minimal, tanpa
            // nol buntut selain `.0`, tanpa nol depan berlebih.
            $this->assertMatchesRegularExpression('/^-?(0|[1-9]\d*)\.(0|\d*[1-9])$/', $teks);
            $diuji++;
        }

        // Penjaga lantai: sapuan yang menyusut diam-diam tetap menulis "OK".
        // 24602 dengan benih ini (8 Okt 2026); sisanya NaN/INF dari pola bit
        // acak dan -0.0 dari round() yang memang dilewati.
        $this->assertGreaterThanOrEqual(24000, $diuji);
    }

    public function test_int_tetap_int(): void
    {
        foreach ([0, 1, -5, 200, PHP_INT_MAX, PHP_INT_MIN] as $n) {
            $balik = json_decode(JsonKanonik::serialisasi($n));

            $this->assertIsInt($balik);
            $this->assertSame($n, $balik);
        }
    }

    public function test_inf_dan_nan_ditolak(): void
    {
        foreach ([INF, -INF, NAN] as $buruk) {
            try {
                JsonKanonik::serialisasi(['x' => $buruk]);
                $this->fail('Nilai tak hingga/NaN harus ditolak: '.var_export($buruk, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_objek_selain_stdclass_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonKanonik::serialisasi(['tanggal' => new \DateTimeImmutable('2024-01-24')]);
    }

    public function test_dari_teks_mempertahankan_objek_kosong_dan_kunci_numerik(): void
    {
        $this->assertSame(
            '{"10":2,"200":1,"a":[],"b":{}}',
            JsonKanonik::dariTeks('{"b":{},"a":[],"200":1,"10":2}'),
        );

        $objek = new stdClass;
        $objek->{'200'} = 0.73;
        $objek->{'101.6'} = 0.26;

        $this->assertSame('{"101.6":0.26,"200":0.73}', JsonKanonik::serialisasi($objek));
    }

    public function test_teks_bukan_json_ditolak(): void
    {
        $this->expectException(JsonException::class);

        JsonKanonik::dariTeks('{"a":1,}');
    }

    public function test_tidak_bergantung_serialize_precision_server(): void
    {
        $asli = ini_get('serialize_precision');

        try {
            ini_set('serialize_precision', '17');

            $this->assertSame('0.1', JsonKanonik::serialisasi(0.1));
            $this->assertSame('2.50014', JsonKanonik::serialisasi(2.50014));
            // Setelan pemanggil dipulihkan, bukan ditimpa diam-diam.
            $this->assertSame('17', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', (string) $asli);
        }
    }

    public function test_sha256_atas_byte_kanonik(): void
    {
        $this->assertSame('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', JsonKanonik::sha256(''));

        $kanonik = JsonKanonik::dariTeks('{"b":1,"a":2}');

        $this->assertSame('{"a":2,"b":1}', $kanonik);
        $this->assertSame(hash('sha256', '{"a":2,"b":1}'), JsonKanonik::sha256($kanonik));
        $this->assertSame(64, strlen(JsonKanonik::sha256($kanonik)));
    }

    /**
     * Sapuan atas SELURUH angka tabel standar Micrometer hari ini — sumber
     * yang akan diimpor jadi versi 1. Urutan kunci boleh beda; nilai dan
     * TIPE tidak, dan float wajib identik bit.
     */
    public function test_sapu_tabel_standar_micrometer_identik_nilai_dan_tipe(): void
    {
        $teks = file_get_contents(base_path('database/data/tabel-standar-micrometer.json'));
        $this->assertIsString($teks);

        $asli = json_decode($teks, true, 512, JSON_THROW_ON_ERROR);
        $kanonik = JsonKanonik::dariTeks($teks);
        $ulang = json_decode($kanonik, true, 512, JSON_THROW_ON_ERROR);

        $asliUrut = $this->urutkanKunci($asli);
        $ulangUrut = $this->urutkanKunci($ulang);

        // assertSame atas array = perbandingan `===`: kunci, urutan (sudah
        // disamakan), nilai, DAN tipe (int lawan float, string lawan angka).
        $this->assertSame($asliUrut, $ulangUrut);

        $floatAsli = $this->kumpulkanFloat($asliUrut);
        $floatUlang = $this->kumpulkanFloat($ulangUrut);

        $this->assertSame(
            array_map(static fn (float $f): string => bin2hex(pack('e', $f)), $floatAsli),
            array_map(static fn (float $f): string => bin2hex(pack('e', $f)), $floatUlang),
        );

        // Penjaga lantai: 232 float + 1 int dihitung 8 Okt 2026. Kalau berkasnya
        // menyusut atau tipe angkanya berubah jadi string, sapuan ini tidak
        // boleh diam-diam memeriksa lebih sedikit.
        $this->assertGreaterThanOrEqual(232, count($floatAsli));

        // Idempoten: kanonik dari kanonik adalah dirinya sendiri.
        $this->assertSame($kanonik, JsonKanonik::dariTeks($kanonik));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function acakUrutanKunci(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(fn ($v) => is_array($v) ? $this->acakUrutanKunci($v) : $v, $data);
        }

        $kunci = array_keys($data);
        shuffle($kunci);

        $hasil = [];

        foreach ($kunci as $k) {
            $hasil[$k] = is_array($data[$k]) ? $this->acakUrutanKunci($data[$k]) : $data[$k];
        }

        return $hasil;
    }

    private function urutkanKunci(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $hasil = array_map(fn ($v) => $this->urutkanKunci($v), $data);

        if (! array_is_list($hasil)) {
            ksort($hasil, SORT_STRING);
        }

        return $hasil;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<float>
     */
    private function kumpulkanFloat(array $data): array
    {
        $hasil = [];

        array_walk_recursive($data, function (mixed $v) use (&$hasil): void {
            if (is_float($v)) {
                $hasil[] = $v;
            }
        });

        return $hasil;
    }
}
