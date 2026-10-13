<?php

namespace Tests\Unit;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\MicrometerProfile;
use App\Services\Calibration\Profiles\ProfilGenerik;
use stdClass;
use Tests\TestCase;

/**
 * `MicrometerProfile::skemaAcuan()` menutup SELURUH `tabel-standar-micrometer.json`
 * — tiap daun tepat satu kolom, tidak ada kolom hantu (ADR-03).
 *
 * Daun yang lolos dari skema adalah angka yang dibaca calculator tapi tidak
 * pernah bisa dilihat/disunting di Studio; daun yang tercakup dua kali adalah
 * satu angka dengan dua tempat sunting. Dua-duanya tidak menghasilkan error di
 * mana pun selain di sini.
 *
 * Aturan daun sama dengan `docs/studio-data-acuan/alat-bantu/bangun_katalog.py`
 * (`daun()`): kunci tingkat atas berawalan `_` itu metadata generator, list
 * yang seluruh isinya skalar (termasuk list kosong) satu daun.
 */
class SkemaAcuanMicrometerTest extends TestCase
{
    private const JENIS = ['objek', 'baris', 'daftar'];

    private const TIPE = ['teks', 'tanggal', 'desimal', 'bulat', 'daftar_angka'];

    /** @return array{skema_versi: int, sumber_json: string, lembar: list<array<string, mixed>>} */
    private static function skema(): array
    {
        $skema = (new MicrometerProfile)->skemaAcuan();
        self::assertNotNull($skema);

        return $skema;
    }

    private static function isiBerkas(string $berkas): stdClass
    {
        // Diurai sebagai stdClass: objek tetap objek, list tetap list, dan
        // kunci "200" tidak berubah jadi indeks.
        return json_decode((string) file_get_contents(database_path('data/'.$berkas)), false, 512, JSON_THROW_ON_ERROR);
    }

    public function test_identitas_skema(): void
    {
        $skema = self::skema();

        $this->assertSame(1, $skema['skema_versi']);
        $this->assertSame('tabel-standar-micrometer.json', $skema['sumber_json']);
        $this->assertNotEmpty($skema['lembar']);
    }

    public function test_bentuk_tiap_lembar_dan_kolom(): void
    {
        $kodeLembar = [];

        foreach (self::skema()['lembar'] as $lembar) {
            $this->assertSame(
                ['kode', 'label', 'jalur', 'jenis', 'kunci', 'kolom', 'boleh_tambah_baris'],
                array_keys($lembar),
                "kunci lembar {$lembar['kode']}",
            );
            $this->assertContains($lembar['jenis'], self::JENIS, "jenis lembar {$lembar['kode']}");
            $this->assertIsBool($lembar['boleh_tambah_baris']);
            $this->assertNotSame('', $lembar['label']);
            $kodeLembar[] = $lembar['kode'];

            $kodeKolom = array_column($lembar['kolom'], 'kode');
            $this->assertSame(array_unique($kodeKolom), $kodeKolom, "kolom kembar di {$lembar['kode']}");

            match ($lembar['jenis']) {
                'objek' => $this->assertNull($lembar['kunci'], "lembar objek {$lembar['kode']} tidak berkunci"),
                'baris' => $this->assertCount(2, $lembar['kolom'], "lembar baris {$lembar['kode']}: kunci + nilai"),
                'daftar' => null,
            };

            if ($lembar['kunci'] !== null) {
                $this->assertContains($lembar['kunci'], $kodeKolom, "kunci {$lembar['kode']} menamai kolomnya");
            }

            foreach ($lembar['kolom'] as $kolom) {
                $this->assertSame(
                    ['kode', 'label', 'tipe', 'satuan', 'asal', 'perlu_konfirmasi'],
                    array_keys($kolom),
                    "kunci kolom {$lembar['kode']}.{$kolom['kode']}",
                );
                $this->assertContains($kolom['tipe'], self::TIPE, "tipe {$lembar['kode']}.{$kolom['kode']}");
                $this->assertIsBool($kolom['perlu_konfirmasi']);

                if ($kolom['asal'] !== null) {
                    $this->assertMatchesRegularExpression(
                        '/^[A-Za-z][A-Za-z0-9 _%.]*![A-Z]{1,3}\d+(:[A-Z]{1,3}\d+)?$/',
                        $kolom['asal'],
                        "asal {$lembar['kode']}.{$kolom['kode']} bukan alamat sel",
                    );
                }
            }
        }

        $this->assertSame(array_unique($kodeLembar), $kodeLembar, 'kode lembar kembar');
    }

    /**
     * Daftar kolom yang menunggu Lab dipatok di sini, supaya menambah atau
     * melepasnya jadi keputusan yang kelihatan di diff — bukan efek samping.
     */
    public function test_kolom_perlu_konfirmasi_hanya_yang_masih_pertanyaan_lab(): void
    {
        $perlu = [];

        foreach (self::skema()['lembar'] as $lembar) {
            foreach ($lembar['kolom'] as $kolom) {
                if ($kolom['perlu_konfirmasi']) {
                    $perlu[] = "{$lembar['kode']}.{$kolom['kode']}";
                }
            }
        }

        $this->assertSame(['konstanta.delta_alpha_per_c', 'konstanta.vi_type_b'], $perlu);
    }

    public function test_setiap_daun_json_tercakup_tepat_satu_kolom_skema(): void
    {
        $skema = self::skema();
        $isi = self::isiBerkas($skema['sumber_json']);

        $daun = [];

        foreach (get_object_vars($isi) as $kunci => $nilai) {
            if (! str_starts_with((string) $kunci, '_')) {
                self::kumpulkanDaun($nilai, (string) $kunci, $daun);
            }
        }

        $tercakup = [];

        foreach ($skema['lembar'] as $lembar) {
            foreach ($this->daunLembar($lembar, $isi) as $jalur) {
                $tercakup[$jalur][] = $lembar['kode'];
            }
        }

        $ganda = array_filter($tercakup, static fn (array $oleh): bool => count($oleh) > 1);
        $this->assertSame([], $ganda, 'daun tercakup lebih dari satu lembar');

        $hilang = array_values(array_diff($daun, array_keys($tercakup)));
        $this->assertSame([], $hilang, 'daun JSON tidak tercakup skema');

        $hantu = array_values(array_diff(array_keys($tercakup), $daun));
        $this->assertSame([], $hantu, 'kolom skema menunjuk sesuatu yang bukan daun JSON');

        // Sama dengan hitungan katalog (`skema.json` → cakupan.daun = 175).
        $this->assertCount(175, $daun);
    }

    /**
     * Tipe kolom cocok dengan tipe nilai yang benar-benar diurai dari berkas —
     * termasuk `vi_type_b` yang int (200), bukan float. Tipe itu ikut sampai ke
     * calculator, jadi Studio tidak boleh diam-diam menyimpannya sebagai 200.0.
     */
    public function test_tipe_kolom_cocok_dengan_nilai_berkas(): void
    {
        $skema = self::skema();
        $isi = self::isiBerkas($skema['sumber_json']);

        foreach ($skema['lembar'] as $lembar) {
            $kolomNilai = $lembar['jenis'] === 'baris'
                ? array_values(array_filter($lembar['kolom'], static fn (array $k): bool => $k['kode'] !== $lembar['kunci']))
                : $lembar['kolom'];

            foreach (self::simpul($isi, explode('/', $lembar['jalur']), '') as [$jalur, $simpul]) {
                $baris = match ($lembar['jenis']) {
                    'objek' => [$simpul],
                    'daftar' => $simpul,
                    'baris' => array_map(
                        static fn ($v): stdClass => (object) [$kolomNilai[0]['kode'] => $v],
                        get_object_vars($simpul),
                    ),
                };

                foreach ($baris as $i => $b) {
                    foreach ($kolomNilai as $kolom) {
                        $this->assertTipe($kolom['tipe'], $b->{$kolom['kode']}, "{$jalur}/{$i}/{$kolom['kode']}");
                    }
                }

                if ($lembar['jenis'] === 'baris') {
                    $kolomKunci = array_values(array_filter($lembar['kolom'], static fn (array $k): bool => $k['kode'] === $lembar['kunci']))[0];

                    foreach (array_keys(get_object_vars($simpul)) as $k) {
                        $this->assertSame('desimal', $kolomKunci['tipe']);
                        $this->assertTrue(is_numeric((string) $k), "kunci baris {$jalur}/{$k} bukan angka");
                    }
                }
            }
        }
    }

    public function test_profil_lain_tidak_punya_skema_acuan(): void
    {
        $denganSkema = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            if ($profil->skemaAcuan() !== null) {
                $denganSkema[] = $profil->kode();
            }
        }

        $this->assertSame([(new MicrometerProfile)->kode()], $denganSkema);
        $this->assertNull((new ProfilGenerik)->skemaAcuan());
    }

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
                    $this->assertInstanceOf(stdClass::class, $simpul, "{$jalur} bukan peta");

                    foreach (array_keys(get_object_vars($simpul)) as $k) {
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
