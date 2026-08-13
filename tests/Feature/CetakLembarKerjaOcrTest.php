<?php

namespace Tests\Feature;

use App\Services\Ocr\TataLetakLembar;
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
     * Kotak selnya nggak boleh nabrak apa pun yang BUKAN sel.
     *
     * Penanda sudut itu titik acuan homography dan QR itu penentu versi lembar:
     * sel yang menimpa salah satunya bikin coretan teknisi merusak justru dua
     * hal yang dipakai buat mbaca coretan itu. Blok kepala lebih halus lagi —
     * garis isian yang lewat di dalam sel kebaca OCR sebagai coretan.
     */
    #[DataProvider('alat')]
    public function test_sel_nggak_nabrak_penanda_qr_atau_blok_kepala(string $kode): void
    {
        $geometri = json_decode(
            (string) File::get(database_path("ocr-templates/{$kode}-v1.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $tinggi = (int) $geometri['ukuran_referensi']['h'];
        $tataLetak = app(TataLetakLembar::class);

        $terlarang = [];

        foreach ($geometri['marker'] as $m) {
            $u = (int) $m['ukuran'];
            $terlarang['penanda '.$m['id']] = [
                'x' => (int) $m['x'] - intdiv($u, 2),
                'y' => (int) $m['y'] - intdiv($u, 2),
                'w' => $u,
                'h' => $u,
            ];
        }

        $terlarang['qr'] = $geometri['qr']['kotak'];

        foreach ($geometri['tabel'] as $t) {
            foreach ($t['sel'] as $kunci => $sel) {
                // Judul tabel & label kolomnya digambar di atas selnya sendiri,
                // jadi yang harus bebas bukan cuma baris selnya.
                $this->assertGreaterThanOrEqual(
                    $tataLetak->atasGrid($tinggi),
                    $sel['y'] - $tataLetak->kepalaTabel($tinggi),
                    "Kepala tabel {$kunci} naik ke blok isian identitas",
                );

                $this->assertLessThanOrEqual(
                    $tataLetak->bawahGrid($tinggi),
                    $sel['y'] + $sel['h'],
                    "Sel {$kunci} turun ke area penanda bawah",
                );

                foreach ($terlarang as $nama => $l) {
                    $tindih = $sel['x'] < $l['x'] + $l['w']
                        && $l['x'] < $sel['x'] + $sel['w']
                        && $sel['y'] < $l['y'] + $l['h']
                        && $l['y'] < $sel['y'] + $sel['h'];

                    $this->assertFalse($tindih, "Sel {$kunci} nabrak {$nama}");
                }
            }
        }
    }

    /**
     * Satu lembar = SATU halaman.
     *
     * Halaman dua nggak pernah difoto, dan sel yang mendarat di sana hilang
     * tanpa gejala: PDF-nya kelihatan normal, gridnya cuma kurang satu kotak di
     * pojok. Kejadian di lembar Conductivity gara-gara halaman disetel `a4`
     * (595,28 pt) sementara lembarnya sendiri 595,44 pt.
     */
    #[DataProvider('alat')]
    public function test_lembar_cetak_muat_satu_halaman(string $kode): void
    {
        $keluar = storage_path("app/uji-satu-halaman-{$kode}.pdf");
        File::delete($keluar);

        $this->artisan('ocr:cetak-lembar', ['kode' => $kode, '--keluar' => $keluar])
            ->assertSuccessful();

        $isi = (string) File::get($keluar);
        File::delete($keluar);

        $this->assertSame(
            1,
            preg_match_all('#/Type\s*/Page[^s]#', $isi),
            "Lembar {$kode} tumpah ke halaman berikutnya",
        );
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

    /**
     * Lembar Conductivity Repeat-nya TURUN, bukan melintang.
     *
     * Selama ini cuma ada satu bentuk yang bisa digambar — bentuk lembar pH —
     * jadi lembar Conductivity dicetak terbalik dari kertasnya tanpa ada yang
     * memberi tahu. Yang menentukan sekarang `sumbu_pengulangan` dari profil
     * alatnya, dan berkas geometrinya harus ikut.
     */
    public function test_conductivity_repeatnya_turun_ke_bawah(): void
    {
        $geometri = json_decode(
            (string) File::get(database_path('ocr-templates/conductivity_meter-v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('baris', $geometri['sumbu_pengulangan']);

        $sel = $geometri['tabel'][0]['sel'];

        // Lima Repeat titik pertama harus berbagi satu x dan turun di y.
        $kolomPertama = [];
        foreach ([1, 2, 3, 4, 5] as $r) {
            $kolomPertama[] = $sel["sebelum_adjustment|1|{$r}|pembacaan"];
        }

        $this->assertCount(1, array_unique(array_column($kolomPertama, 'x')),
            'Lima Repeat satu titik harus sekolom — kalau x-nya beda, Repeat-nya masih melintang.');

        $y = array_column($kolomPertama, 'y');
        $this->assertSame($y, array_values(array_unique($y)), 'Repeat 1..5 harus turun, bukan menumpuk.');
        $this->assertTrue($y === array_values(array_filter($y, static fn ($v, $i): bool => $i === 0 || $v > $y[$i - 1], ARRAY_FILTER_USE_BOTH)));

        // Dua titik beda harus beda kolom.
        $this->assertNotSame(
            $sel['sebelum_adjustment|1|1|pembacaan']['x'],
            $sel['sebelum_adjustment|2|1|pembacaan']['x'],
            'Dua larutan berbeda harus berjajar ke samping.',
        );
    }

    /**
     * Alat lain TIDAK boleh ikut berubah — empat lembar yang sudah jalan
     * bentuknya Repeat melintang, dan itu benar untuk kertasnya masing-masing.
     */
    public function test_lembar_lain_tetap_repeat_melintang(): void
    {
        foreach (['ph_meter', 'turbidimeter', 'chlorine_meter', 'refractometer'] as $kode) {
            $geometri = json_decode(
                (string) File::get(database_path("ocr-templates/{$kode}-v1.json")),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $this->assertSame('kolom', $geometri['sumbu_pengulangan'] ?? 'kolom', "Lembar {$kode} ikut kebalik.");
        }
    }

    /**
     * Kepala kolom lembar Conductivity memakai nama yang TERCETAK di kertas
     * (`84` / `1413 µS`), bukan nilai titik yang dihitung (`25` / `1412`).
     *
     * Botol yang sama dikirim dua varian satuan, dan dua-duanya menunjuk slot
     * cetak yang sama. Di kertas itu satu kolom bercentang; di lembar cetak
     * kotaknya nggak bisa dicentang, jadi yang kedua harus memakai nama varian
     * — kalau nggak, dua kolom bersebelahan sama-sama berkepala `1413 µS`.
     */
    public function test_kepala_kolom_conductivity_pakai_nama_kertas(): void
    {
        $tabel = collect(app(TemplateLembarKerja::class)->untukKode('conductivity_meter')['tabel'])
            ->firstWhere('tabel_id', 'sebelum_adjustment');

        $this->assertSame('baris', $tabel['sumbu_pengulangan']);

        $this->assertSame(
            ['84', '1413 µS', '5000 µS', '80000 µS'],
            array_column($tabel['slot_cetak'], 'label'),
        );

        // Slot titik tengah menaungi DUA baris; slot terakhir nggak menaungi
        // satu pun karena larutannya nggak ada di master.
        $this->assertSame(
            [1, 2, 1, 0],
            array_map(static fn (array $s): int => count($s['titik_ukur']), $tabel['slot_cetak']),
        );
    }
}
