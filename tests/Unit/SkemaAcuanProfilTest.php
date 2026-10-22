<?php

namespace Tests\Unit;

use App\Services\Calibration\CalibrationProfileRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Concerns\PeriksaSkemaAcuan;
use Tests\TestCase;

/**
 * Aturan `SkemaAcuanMicrometerTest` untuk SETIAP profil ber-`skemaAcuan()`:
 * bentuk lembar/kolom sah, tiap daun JSON tercakup tepat satu kolom, tidak
 * ada kolom hantu, dan tipe kolom = tipe nilai berkas (int tetap int).
 */
class SkemaAcuanProfilTest extends TestCase
{
    use PeriksaSkemaAcuan;

    /** Profil yang sudah dipindah ke Studio, urut registry (bukan urut migrasi). Menambah = keputusan yang terlihat di diff. */
    public const BERSKEMA = ['micrometer', 'height_gauge', 'dial_indicator', 'jangka_sorong'];

    private const JENIS = ['objek', 'baris', 'daftar'];

    private const TIPE = ['teks', 'tanggal', 'desimal', 'bulat', 'daftar_angka'];

    /** @return array<string, array{string}> */
    public static function profil(): array
    {
        return array_combine(self::BERSKEMA, array_map(static fn (string $k): array => [$k], self::BERSKEMA));
    }

    /** @return array{skema_versi: int, sumber_json: string, lembar: list<array<string, mixed>>} */
    private function skema(string $kode): array
    {
        $skema = app(CalibrationProfileRegistry::class)->untukPaketAcuan($kode)?->skemaAcuan();
        $this->assertNotNull($skema, $kode);

        return $skema;
    }

    /** Daftar dipatok = paket yang benar-benar ber-skema di registry. */
    public function test_daftar_paket_dipatok(): void
    {
        $this->assertSame(self::BERSKEMA, array_keys(app(CalibrationProfileRegistry::class)->paketAcuan()));
    }

    /**
     * Profil sepaket membaca SATU tabel: skemanya wajib identik, kalau tidak
     * Studio menampilkan/menyunting struktur yang beda tergantung profil
     * mana yang kebetulan jadi wakil.
     */
    public function test_profil_sepaket_skemanya_identik(): void
    {
        $registry = app(CalibrationProfileRegistry::class);

        foreach ($registry->paketAcuan() as $kodePaket => $wakil) {
            foreach ($registry->profilPaketAcuan($kodePaket) as $profil) {
                $this->assertSame($wakil->skemaAcuan(), $profil->skemaAcuan(), "{$profil->kode()} vs wakil paket {$kodePaket}");
            }
        }
    }

    #[DataProvider('profil')]
    public function test_bentuk_lembar_dan_kolom(string $kode): void
    {
        $skema = $this->skema($kode);
        $this->assertSame(1, $skema['skema_versi']);
        $this->assertFileExists(database_path('data/'.$skema['sumber_json']));
        $kodeLembar = [];

        foreach ($skema['lembar'] as $lembar) {
            $this->assertSame(['kode', 'label', 'jalur', 'jenis', 'kunci', 'kolom', 'boleh_tambah_baris'], array_keys($lembar));
            $this->assertContains($lembar['jenis'], self::JENIS);
            $kodeLembar[] = $lembar['kode'];
            $kodeKolom = array_column($lembar['kolom'], 'kode');
            $this->assertSame(array_unique($kodeKolom), $kodeKolom, "kolom kembar di {$lembar['kode']}");

            if ($lembar['jenis'] === 'objek') {
                $this->assertNull($lembar['kunci']);
            }

            if ($lembar['kunci'] !== null) {
                $this->assertContains($lembar['kunci'], $kodeKolom);
            }

            foreach ($lembar['kolom'] as $kolom) {
                $this->assertSame(['kode', 'label', 'tipe', 'satuan', 'asal', 'perlu_konfirmasi'], array_keys($kolom));
                $this->assertContains($kolom['tipe'], self::TIPE, "{$lembar['kode']}.{$kolom['kode']}");

                if ($kolom['asal'] !== null) {
                    $this->assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9 _%.]*![A-Z]{1,3}\d+(:[A-Z]{1,3}\d+)?$/', $kolom['asal']);
                }
            }
        }

        $this->assertSame(array_unique($kodeLembar), $kodeLembar);
    }

    #[DataProvider('profil')]
    public function test_setiap_daun_json_tercakup_tepat_satu_kolom(string $kode): void
    {
        $skema = $this->skema($kode);
        $isi = json_decode((string) file_get_contents(database_path('data/'.$skema['sumber_json'])), false, 512, JSON_THROW_ON_ERROR);
        $daun = [];

        foreach (get_object_vars($isi) as $k => $v) {
            if (! str_starts_with((string) $k, '_')) {
                self::kumpulkanDaun($v, (string) $k, $daun);
            }
        }

        $tercakup = [];

        foreach ($skema['lembar'] as $lembar) {
            foreach ($this->daunLembar($lembar, $isi) as $jalur) {
                $tercakup[$jalur][] = $lembar['kode'];
            }
        }

        $this->assertSame([], array_filter($tercakup, static fn (array $o): bool => count($o) > 1), 'daun ganda');
        $this->assertSame([], array_values(array_diff($daun, array_keys($tercakup))), 'daun tidak tercakup');
        $this->assertSame([], array_values(array_diff(array_keys($tercakup), $daun)), 'kolom hantu');
    }

    #[DataProvider('profil')]
    public function test_tipe_kolom_cocok_dengan_nilai_berkas(string $kode): void
    {
        $skema = $this->skema($kode);
        $isi = json_decode((string) file_get_contents(database_path('data/'.$skema['sumber_json'])), false, 512, JSON_THROW_ON_ERROR);

        foreach ($skema['lembar'] as $lembar) {
            $kolomNilai = $lembar['jenis'] === 'baris'
                ? array_values(array_filter($lembar['kolom'], static fn (array $k): bool => $k['kode'] !== $lembar['kunci']))
                : $lembar['kolom'];

            foreach (self::simpul($isi, explode('/', $lembar['jalur']), '') as [$jalur, $simpul]) {
                $baris = match ($lembar['jenis']) {
                    'objek' => [$simpul],
                    'daftar' => $simpul,
                    'baris' => array_map(static fn ($v): stdClass => (object) [$kolomNilai[0]['kode'] => $v], is_array($simpul) ? $simpul : get_object_vars($simpul)),
                };

                foreach ($baris as $i => $b) {
                    foreach ($kolomNilai as $kolom) {
                        $this->assertTipe($kolom['tipe'], $b->{$kolom['kode']}, "{$jalur}/{$i}/{$kolom['kode']}");
                    }
                }
            }
        }
    }
}
