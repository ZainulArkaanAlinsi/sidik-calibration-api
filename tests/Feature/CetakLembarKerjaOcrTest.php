<?php

namespace Tests\Feature;

use App\Services\Ocr\TemplateLembarKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lembar kerja cetak digambar DARI berkas geometri yang sama yang dipakai
 * server buat motong sel.
 *
 * Yang dijaga di sini cuma satu, dan itu inti keselamatan fitur pindai: **tiap
 * kunci sel yang dikenal server punya kotaknya di kertas, dan sebaliknya.**
 * Kunci yang ada di template tapi nggak kegambar berarti teknisi nulis angka di
 * tempat yang nggak pernah dipotong; kunci yang kegambar tapi nggak dikenal
 * server bikin seluruh kiriman ditolak.
 */
class CetakLembarKerjaOcrTest extends TestCase
{
    // Template narik master `standards` buat nautin standar per baris — bukan
    // buat koordinatnya, tapi tabelnya tetap mesti ada.
    use RefreshDatabase;

    /** @return list<array{string}> */
    public static function alat(): array
    {
        return [
            ['ph_meter'],
            ['turbidimeter'],
            ['chlorine_meter'],
            ['refractometer'],
            ['conductivity_meter'],
            ['spectrophotometer'],
        ];
    }

    #[DataProvider('alat')]
    public function test_kunci_sel_di_kertas_sama_persis_dengan_yang_dikenal_server(string $kode): void
    {
        $geometri = json_decode(
            (string) File::get(database_path("ocr-templates/{$kode}-v1.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $template = app(TemplateLembarKerja::class)->untukKode($kode);

        $diKertas = [];
        foreach ($geometri['tabel'] as $t) {
            foreach (array_keys($t['sel']) as $kunci) {
                $diKertas[] = $kunci;
            }
        }

        $diServer = [];
        foreach ($template['tabel'] as $t) {
            foreach ($t['baris'] as $b) {
                foreach ($t['pengulangan'] as $repeat) {
                    foreach ($t['kolom'] as $k) {
                        $diServer[] = app(TemplateLembarKerja::class)->kunci(
                            $t['tabel_id'],
                            (int) $b['baris_ke'],
                            (int) $repeat,
                            (string) $k['field_id'],
                        );
                    }
                }
            }
        }

        sort($diKertas);
        sort($diServer);

        $this->assertSame($diServer, $diKertas, "Kunci sel {$kode} beda antara kertas & server");
    }

    /**
     * Kotaknya nggak boleh keluar halaman atau tumpang tindih.
     *
     * Sel yang saling tumpang tindih artinya satu coretan kepotong dua kali —
     * angka yang sama mendarat di dua kunci, dan yang satu pasti salah.
     */
    #[DataProvider('alat')]
    public function test_kotak_sel_muat_di_halaman_dan_nggak_tumpang_tindih(string $kode): void
    {
        $geometri = json_decode(
            (string) File::get(database_path("ocr-templates/{$kode}-v1.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $w = (int) $geometri['ukuran_referensi']['w'];
        $h = (int) $geometri['ukuran_referensi']['h'];

        $kotak = [];

        foreach ($geometri['tabel'] as $t) {
            foreach ($t['sel'] as $kunci => $b) {
                $this->assertGreaterThanOrEqual(0, $b['x'], "{$kunci} keluar kiri");
                $this->assertGreaterThanOrEqual(0, $b['y'], "{$kunci} keluar atas");
                $this->assertLessThanOrEqual($w, $b['x'] + $b['w'], "{$kunci} keluar kanan");
                $this->assertLessThanOrEqual($h, $b['y'] + $b['h'], "{$kunci} keluar bawah");

                $kotak[$kunci] = $b;
            }
        }

        foreach ($kotak as $kunciA => $a) {
            foreach ($kotak as $kunciB => $b) {
                if ($kunciA >= $kunciB) {
                    continue;
                }

                $tindih = $a['x'] < $b['x'] + $b['w']
                    && $b['x'] < $a['x'] + $a['w']
                    && $a['y'] < $b['y'] + $b['h']
                    && $b['y'] < $a['y'] + $a['h'];

                $this->assertFalse($tindih, "Sel {$kunciA} & {$kunciB} tumpang tindih");
            }
        }
    }

    /**
     * Perintahnya beneran ngeluarin PDF, dan nolak kalau selnya belum diisi.
     */
    public function test_cetak_ngasilin_pdf(): void
    {
        $keluar = storage_path('app/uji-lembar-ocr.pdf');
        File::delete($keluar);

        $this->artisan('ocr:cetak-lembar', ['kode' => 'ph_meter', '--keluar' => $keluar])
            ->assertSuccessful();

        $this->assertTrue(File::exists($keluar));
        $this->assertStringStartsWith('%PDF', (string) File::get($keluar));

        File::delete($keluar);
    }

    public function test_kode_alat_asing_ditolak(): void
    {
        $this->artisan('ocr:cetak-lembar', ['kode' => 'alat-yang-nggak-ada'])
            ->assertFailed();
    }
}
