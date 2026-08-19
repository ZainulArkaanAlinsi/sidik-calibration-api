<?php

namespace Tests\Feature;

use App\Console\Commands\CetakLembarKerjaOcr;
use App\Services\Ocr\LetakLabelLembar;
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
            ['viscometer'],
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
     * **Kotak jangkar menaungi label yang beneran tercetak.**
     *
     * Jangkar itu satu-satunya penjagaan yang MEMBACA ISI lembar, bukan
     * mengukur geometrinya: kalau gridnya bergeser satu baris, label yang
     * kebaca di posisi Repeat 2 bakal `X3` dan seluruh lembar ditolak sebelum
     * satu angka pun dipetakan.
     *
     * Tapi dia cuma berguna kalau kotaknya jatuh pas di atas tulisannya.
     * Kotak yang meleset bikin HP motong kertas kosong, `cocok: false`, dan
     * lembar yang sebenarnya BENAR ditolak — kegagalan yang kelihatannya
     * seperti "OCR-nya jelek" padahal koordinat.
     *
     * Dua sisinya sekarang dihitung `LetakLabelLembar`, jadi mestinya nggak
     * bisa meleset. Test ini yang mbuktiin mestinya itu.
     */
    #[DataProvider('alat')]
    public function test_kotak_jangkar_menaungi_label_yang_tercetak(string $kode): void
    {
        $geometri = json_decode(
            (string) File::get(database_path("ocr-templates/{$kode}-v1.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertNotEmpty(
            $geometri['jangkar'] ?? [],
            "Geometri {$kode} nggak punya jangkar — penjagaan geser-satu-baris mati.",
        );

        $html = tempnam(sys_get_temp_dir(), 'lembar').'.html';

        $this->artisan("ocr:cetak-lembar {$kode} --keluar=".tempnam(sys_get_temp_dir(), 'lembar').'.pdf'
            ." --html={$html}")->assertSuccessful();

        $isi = (string) File::get($html);
        $dpi = (int) ($geometri['ukuran_referensi']['dpi'] ?? 200);

        // Tiap label yang tercetak: teks + kotaknya dalam piksel ruang template.
        preg_match_all(
            '/<div class="abs label[^"]*" style="([^"]*)">([^<]*)<\/div>/',
            $isi,
            $cocok,
            PREG_SET_ORDER,
        );

        $tercetak = [];

        foreach ($cocok as $m) {
            preg_match('/left:\s*([0-9.]+)mm/', $m[1], $x);
            preg_match('/top:\s*([0-9.]+)mm/', $m[1], $y);
            preg_match('/width:\s*([0-9.]+)mm/', $m[1], $w);

            if ($x === [] || $y === [] || $w === []) {
                continue;
            }

            $tercetak[] = [
                'teks' => trim(html_entity_decode($m[2])),
                'x' => (float) $x[1] * $dpi / 25.4,
                'y' => (float) $y[1] * $dpi / 25.4,
                'w' => (float) $w[1] * $dpi / 25.4,
            ];
        }

        $this->assertNotEmpty($tercetak, "Nggak ada label kecetak di lembar {$kode}.");

        foreach ($geometri['jangkar'] as $j) {
            $kotak = $j['kotak'];

            $this->assertGreaterThanOrEqual(
                1,
                $kotak['w'] * $kotak['h'],
                "Kotak jangkar {$j['teks']} ukurannya nol — nggak bisa dipotong HP.",
            );

            $ketemu = false;

            foreach ($tercetak as $label) {
                if ($label['teks'] !== $j['teks']) {
                    continue;
                }

                // Tulisannya duduk DI DALAM kotak jangkarnya. Toleransi 1 px:
                // koordinatnya bolak-balik lewat milimeter berdesimal tiga.
                $didalam = $label['x'] >= $kotak['x'] - 1
                    && $label['x'] + $label['w'] <= $kotak['x'] + $kotak['w'] + 1
                    && $label['y'] >= $kotak['y'] - 1
                    && $label['y'] <= $kotak['y'] + $kotak['h'] + 1;

                if ($didalam) {
                    $ketemu = true;

                    break;
                }
            }

            $this->assertTrue(
                $ketemu,
                "Label {$j['teks']} nggak kecetak di dalam kotak jangkarnya ({$kode}).",
            );
        }
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
     * QR nggak boleh nyentuh penanda sudut, dan gridnya berhenti di margin.
     *
     * Kotak QR yang lama melar sampai `lebar - 90` sementara penanda kanan-atas
     * mulai di `lebar - 135`: 45x5 px tinta QR duduk DI DALAM kotak penanda.
     * Deteksi sudut di HP cuma ambang gelap + titik berat gumpalan, jadi tinta
     * asing di situ menggeser titik acuan homography — seluruh grid ikut geser
     * dan gejalanya cuma angka yang mendarat di sel tetangga. Sel yang nabrak
     * QR udah dijaga di atas; yang ini penanda lawan QR-nya sendiri.
     */
    #[DataProvider('alat')]
    public function test_qr_nggak_nyentuh_penanda_dan_grid_berhenti_di_margin(string $kode): void
    {
        $geometri = json_decode(
            (string) File::get(database_path("ocr-templates/{$kode}-v1.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $lebar = (int) $geometri['ukuran_referensi']['w'];
        $qr = $geometri['qr']['kotak'];

        foreach ($geometri['marker'] as $m) {
            $u = (int) $m['ukuran'];
            $p = [
                'x' => (int) $m['x'] - intdiv($u, 2),
                'y' => (int) $m['y'] - intdiv($u, 2),
                'w' => $u,
                'h' => $u,
            ];

            $tindih = $qr['x'] < $p['x'] + $p['w']
                && $p['x'] < $qr['x'] + $qr['w']
                && $qr['y'] < $p['y'] + $p['h']
                && $p['y'] < $qr['y'] + $qr['h'];

            $this->assertFalse($tindih, "QR {$kode} nabrak penanda {$m['id']}");
        }

        // Tepi kanan grid sejajar tepi kanan isian identitas di atasnya. Lebar
        // sel bilangan bulat nggak pernah pas ngisi ruangnya, dan sisanya
        // dulu digantung di kanan: dua garis vertikal yang meleset beberapa
        // piksel — beda per lembar, karena jumlah kolomnya beda.
        $tataLetak = app(TataLetakLembar::class);
        $kanan = 0;

        foreach ($geometri['tabel'] as $t) {
            foreach ($t['sel'] as $sel) {
                $kanan = max($kanan, (int) $sel['x'] + (int) $sel['w']);
            }
        }

        $this->assertSame(
            $tataLetak->kananGrid($lebar),
            $kanan,
            "Tepi kanan grid {$kode} nggak sejajar blok kepala",
        );
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
     * Nama kolom (`Reading` / `°C`) berdiri DI ATAS grid, bukan di dalamnya.
     *
     * Letaknya diturunkan dari label kelompok di atasnya. Waktu diambil dari
     * kotak sel yang tersimpan per field, yang kesimpan sel TERAKHIR yang
     * kebaca — di lembar yang Repeat-nya turun ke bawah itu baris X5, jadi
     * `Reading` & `°C` kecetak di tengah grid, menimpa kotak yang mestinya
     * diisi teknisi. PDF-nya tetap terbit dan jumlah selnya tetap benar, jadi
     * nggak ada satu pun test lama yang merah.
     */
    #[DataProvider('alat')]
    public function test_nama_kolom_nggak_kecetak_di_dalam_sel(string $kode): void
    {
        $geometri = json_decode(
            (string) File::get(database_path("ocr-templates/{$kode}-v1.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $perintah = app(CetakLembarKerjaOcr::class);
        $tinggi = (int) $geometri['ukuran_referensi']['h'];
        $template = app(TemplateLembarKerja::class)->untukKode($kode);

        $keBawah = [];
        $kolom = [];

        foreach ($template['tabel'] as $t) {
            $keBawah[$t['tabel_id']] = ($t['sumbu_pengulangan'] ?? 'kolom') === 'baris';
            $kolom[$t['tabel_id']] = array_column($t['kolom'], 'label', 'field_id');
        }

        // Metodenya `private` — yang diuji di sini letak cetakannya, dan itu
        // nggak kebaca lagi begitu sudah jadi PDF.
        $labelKolom = new \ReflectionMethod($perintah, 'labelKolom');
        $labelKolom->setAccessible(true);

        foreach ($geometri['tabel'] as $t) {
            $sel = $t['sel'];
            $atasGrid = min(array_column($sel, 'y'));

            $label = $labelKolom->invoke(
                $perintah,
                $sel,
                $kolom[$t['tabel_id']] ?? [],
                [],
                $keBawah[$t['tabel_id']] ?? false,
                app(TataLetakLembar::class)->jarakBarisKepala($tinggi),
            );

            $this->assertNotEmpty($label, "Tabel {$t['tabel_id']} nggak punya kepala kolom sama sekali");

            foreach ($label as $l) {
                $this->assertLessThan(
                    $atasGrid,
                    $l['y'],
                    "Kepala kolom `{$l['teks']}` di {$kode}/{$t['tabel_id']} kecetak di dalam grid",
                );
            }
        }
    }

    /**
     * Label kiri tiap tabel berdiri di kolomnya sendiri, bukan di dalam sel.
     *
     * Lembar Spectrophotometer menggambar dua tabel BERDAMPINGAN supaya
     * selnya nggak jatuh ke 34 px. Begitu ada tabel yang nggak mulai di margin
     * halaman, label barisnya harus ikut pindah: kalau nggak, `475,2` sampai
     * `806,1` kecetak di dalam kotak Holmium yang mestinya diisi teknisi —
     * PDF-nya tetap terbit, jumlah selnya tetap benar, dan nggak ada test lama
     * yang merah.
     */
    #[DataProvider('alat')]
    public function test_label_baris_nggak_kecetak_di_dalam_sel(string $kode): void
    {
        $geometri = json_decode(
            (string) File::get(database_path("ocr-templates/{$kode}-v1.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $lebar = (int) $geometri['ukuran_referensi']['w'];
        $tataLetak = app(TataLetakLembar::class);
        $perintah = app(CetakLembarKerjaOcr::class);

        $keBawah = [];

        foreach (app(TemplateLembarKerja::class)->untukKode($kode)['tabel'] as $t) {
            $keBawah[$t['tabel_id']] = ($t['sumbu_pengulangan'] ?? 'kolom') === 'baris';
        }

        $semuaSel = [];

        foreach ($geometri['tabel'] as $t) {
            foreach ($t['sel'] as $kunci => $s) {
                $semuaSel[$kunci] = $s;
            }
        }

        // Metodenya `private` — yang diuji letak cetakannya, dan itu nggak
        // kebaca lagi begitu sudah jadi PDF.
        $labelBaris = new \ReflectionMethod($perintah, 'labelBaris');
        $labelBaris->setAccessible(true);

        foreach ($geometri['tabel'] as $t) {
            $label = $labelBaris->invoke(
                $perintah,
                $t['sel'],
                [],
                $keBawah[$t['tabel_id']] ?? false,
                (int) ($t['kiri'] ?? $tataLetak->kiri($lebar)),
                $tataLetak->jarakLabelBaris($lebar),
            );

            $this->assertNotEmpty($label, "Tabel {$t['tabel_id']} nggak punya label baris sama sekali");

            foreach ($label as $l) {
                $this->assertGreaterThan(
                    0,
                    $l['w'],
                    "Label `{$l['teks']}` di {$kode}/{$t['tabel_id']} nggak kebagian lebar",
                );

                foreach ($semuaSel as $kunci => $s) {
                    $tindih = $l['x'] < $s['x'] + $s['w']
                        && $s['x'] < $l['x'] + $l['w']
                        && $l['y'] < $s['y'] + $s['h']
                        && $s['y'] < $l['y'] + LetakLabelLembar::TINGGI_TEKS;

                    $this->assertFalse(
                        $tindih,
                        "Label `{$l['teks']}` di {$kode}/{$t['tabel_id']} kecetak di dalam sel {$kunci}",
                    );
                }
            }
        }
    }

    /**
     * Sel Spectrophotometer harus muat ditulisi tangan.
     *
     * 24 baris dari tiga tabel yang ditumpuk kebagian 34 px (4,3 mm) per sel —
     * masih di atas ambang mutu pindai (`ocr.kualitas.px_per_sel_min` = 24),
     * jadi nggak ada satu pun penjagaan yang berbunyi, tapi nggak ada angka
     * tulisan tangan yang muat di situ. Dua tabel panjang gelombangnya
     * masing-masing cuma butuh 3 kolom, jadi separuh lebar kertasnya nganggur:
     * yang ditukar lebar nganggur itu jadi tinggi sel.
     *
     * Ambangnya 60 px (7,6 mm) — kira-kira setinggi baris buku tulis. Angka
     * ini yang jatuh duluan kalau ada yang menaikkan jumlah titik ukur atau
     * mengembalikan ketiga tabel ke satu tumpukan.
     */
    public function test_sel_spektro_muat_ditulisi_tangan(): void
    {
        $geometri = json_decode(
            (string) File::get(database_path('ocr-templates/spectrophotometer-v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($geometri['tabel'] as $t) {
            foreach ($t['sel'] as $kunci => $s) {
                $this->assertGreaterThanOrEqual(60, $s['h'], "Sel {$kunci} kependekan buat tulisan tangan");
            }
        }

        // Dua tabel panjang gelombang berdampingan: barisnya mulai di ketinggian
        // yang sama, kolomnya nggak. Kalau ini jebol, tabelnya balik bertumpuk
        // dan selnya kembali ke 34 px.
        $holmium = collect($geometri['tabel'])->firstWhere('tabel_id', 'holmium');
        $didynium = collect($geometri['tabel'])->firstWhere('tabel_id', 'didynium');

        $this->assertSame(
            min(array_column($holmium['sel'], 'y')),
            min(array_column($didynium['sel'], 'y')),
            'Holmium & Didynium mestinya sepita — baris pertamanya harus sama tinggi.',
        );

        $this->assertGreaterThan(
            max(array_map(static fn (array $s): int => $s['x'] + $s['w'], $holmium['sel'])),
            (int) $didynium['kiri'],
            'Blok Didynium mestinya mulai sesudah sel terakhir Holmium.',
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
