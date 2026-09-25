<?php

namespace Tests\Feature;

use App\Services\LembarKerjaTemplate;
use App\Services\Ocr\TemplateLembarKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Geometri formulir ASLI pH Meter (SIDIK-FM-CAL-0509_Rev.4) — pilot §5
 * `docs/PANDUAN-OCR-LEMBAR-KERJA.md`.
 *
 * Dua berkas, dan pemisahannya yang dijaga di sini:
 *
 * - `asli/ph_meter-0509.draf.json` — keluaran `docs/skrip/draf-geometri-dari-pdf.py`
 *   APA ADANYA. Tidak pernah disunting tangan; kalau ada yang salah, skripnya
 *   yang dibetulkan lalu dijalankan ulang.
 * - `asli/ph_meter-0509.peta.json` — keputusan manusia: kotak draf mana jadi
 *   kunci sel profil yang mana. Kotak dirujuk lewat TITIK TENGAH ternormal,
 *   bukan id `sNNN`, karena id bergeser begitu skripnya diperbaiki.
 *
 * Belum ada satu pun pindaian yang memakainya: `TemplateLembarKerja` cuma
 * membaca `{kode}-v{n}.json` di folder atas, dan `ph_meter-v1.json` — kertas
 * bermarker yang sudah dipakai uji lapangan — tidak boleh ikut berubah.
 */
class GeometriFormulirAsliPhTest extends TestCase
{
    // Kunci sel profil dibangun lewat `TemplateLembarKerja`, dan template
    // menarik master `standards` buat nautin standar per baris.
    use RefreshDatabase;

    private const PDF = 'Project-PT-Sidik/worksheet_alat_calibration/SIDIK-FM-CAL-0509_Rev.4 - LEMBAR KERJA pH METER.pdf';

    /**
     * sha256 `database/ocr-templates/ph_meter-v1.json` di commit `615b691a`.
     * Pilot formulir asli sengaja dipisah ke `asli/` justru supaya berkas ini
     * tidak tersentuh; kalau hash-nya berubah, pemisahannya yang bocor.
     */
    private const SHA_PH_METER_V1 = 'c1b5ffedb27b7bf4a94532a3a138becd803de0ece9fc6e0b853ef2d1e23e59b6';

    /**
     * Dua sel bertetangga berbagi SATU garis, jadi irisannya nol lebar. Lebih
     * dari setengah poin sudah tumpang-tindih beneran — potongan sel yang satu
     * ikut memuat tulisan tangan di sel sebelahnya.
     */
    private const TOLERANSI_PT = 0.5;

    /** Cara isi per jenis kotak — kosakata §4 panduan. */
    private const CARA = [
        'isian' => ['angka', 'tanggal', 'banding', 'usulan'],
        'centang' => ['centang'],
    ];

    private ?string $folderFixture = null;

    protected function tearDown(): void
    {
        if ($this->folderFixture !== null) {
            foreach ([...glob($this->folderFixture.'/*.json') ?: [], ...glob($this->folderFixture.'/asli/*.json') ?: []] as $berkas) {
                @unlink($berkas);
            }

            @rmdir($this->folderFixture.'/asli');
            @rmdir($this->folderFixture);
        }

        parent::tearDown();
    }

    public function test_draf_dan_peta_menunjuk_pdf_formulir_yang_sama(): void
    {
        $sha = hash_file('sha256', base_path(self::PDF));

        $this->assertSame($sha, $this->draf()['sumber']['sha256'], 'Draf lahir dari PDF lain — jalankan ulang skripnya.');
        $this->assertSame($sha, $this->peta()['sumber_sha256'], 'Peta ditulis untuk PDF lain.');
        $this->assertSame(basename(self::PDF), $this->draf()['sumber']['pdf']);
        $this->assertSame('ph_meter-0509.draf.json', $this->peta()['draf']);
    }

    public function test_kode_dokumen_tercetak_sama_dengan_kode_profil(): void
    {
        $draf = $this->draf();
        $tercetak = $draf['kode_dokumen'].'_Rev.'.$draf['revisi_tercetak'];

        $this->assertSame(LembarKerjaTemplate::KODE_DOKUMEN, $tercetak);
        $this->assertSame(LembarKerjaTemplate::KODE_DOKUMEN, $this->peta()['kode_dokumen']);
        $this->assertSame(LembarKerjaTemplate::KODE_DOKUMEN, $this->template()['kode_dokumen']);
        $this->assertSame('ph_meter', $this->peta()['template_id']);
    }

    /**
     * Keduanya belum diadu ke satu pun foto nyata (§5 langkah 5).
     */
    public function test_belum_ada_yang_terverifikasi(): void
    {
        $this->assertFalse($this->draf()['terverifikasi']);
        $this->assertFalse($this->peta()['terverifikasi']);
    }

    public function test_tiap_kunci_sel_profil_terpetakan_tepat_sekali(): void
    {
        $kunciProfil = array_keys($this->template()['sel']);
        $kunciPeta = array_column($this->peta()['sel'], 'kunci');

        $this->assertCount(60, $kunciProfil, 'Profil pH berubah bentuk — petanya wajib ditinjau ulang.');
        $this->assertSame([], $this->kembar($kunciPeta), 'Satu kunci dipetakan ke lebih dari satu kotak.');
        $this->assertEqualsCanonicalizing($kunciProfil, $kunciPeta);
    }

    public function test_kode_isian_dan_centang_dikenal_profil(): void
    {
        $kodeProfil = [];

        foreach (app(LembarKerjaTemplate::class)->phMeter()['bagian'] as $bagian) {
            foreach ($bagian['field'] ?? [] as $field) {
                $kodeProfil[] = $field['kode'];
            }
        }

        foreach (['isian', 'centang'] as $jenis) {
            foreach ($this->peta()[$jenis] as $entri) {
                $this->assertContains($entri['kode'], $kodeProfil, "Peta {$jenis} menunjuk kode yang tidak ada di lembar pH.");
                $this->assertContains($entri['cara'] ?? null, self::CARA[$jenis], "Cara isi {$entri['kode']} bukan kosakata §4.");
            }
        }

        $this->assertSame([], $this->kembar(array_column($this->peta()['isian'], 'kode')), 'Satu kode isian dipetakan dua kali.');
    }

    /**
     * Blok Env. Condition dulu terbaca SATU kotak kosong di luar bloknya, jadi
     * empat angka lingkungan tidak punya tempat. Keempatnya wajib berdiri
     * sendiri-sendiri, di empat kotak draf yang berbeda.
     */
    public function test_empat_isian_lingkungan_terpisah(): void
    {
        $kotak = [];

        foreach ($this->peta()['isian'] as $e) {
            if (in_array($e['kode'], ['suhu_awal', 'kelembaban_awal', 'suhu_akhir', 'kelembaban_akhir'], true)) {
                $kotak[$e['kode']] = $this->kotakBerisiTitik('isian', (float) $e['x'], (float) $e['y'])[0]['id'] ?? null;
            }
        }

        $this->assertEqualsCanonicalizing(['suhu_awal', 'kelembaban_awal', 'suhu_akhir', 'kelembaban_akhir'], array_keys($kotak));
        $this->assertCount(4, array_unique(array_filter($kotak)), 'Dua isian lingkungan menunjuk kotak draf yang sama.');
    }

    /**
     * Usage Check: baris ke-n peta = baris ke-n `STANDARD_TERCETAK`, dan kotak
     * centang yang ditunjuk memang berdiri di samping nama standar itu di
     * kertas. Tertukar satu baris = buffer 7 tercatat dipakai padahal yang
     * dicentang buffer 4, tanpa satu error pun.
     */
    public function test_centang_usage_check_sejajar_baris_tercetak(): void
    {
        $baris = array_values(array_filter($this->peta()['centang'], fn (array $e): bool => $e['kode'] === 'standar_dicek.*.dipakai'));

        $this->assertEqualsCanonicalizing(range(1, count(LembarKerjaTemplate::STANDARD_TERCETAK)), array_column($baris, 'baris_ke'));

        foreach ($baris as $e) {
            $label = LembarKerjaTemplate::STANDARD_TERCETAK[$e['baris_ke'] - 1]['label'];
            $kotak = $this->kotakBerisiTitik('centang', (float) $e['x'], (float) $e['y'])[0];

            $this->assertSame($label, $e['label'], "Baris {$e['baris_ke']} peta berlabel lain dari baris tercetaknya.");
            $this->assertStringEndsWith((string) $kotak['label_dekat'], $label, "Centang baris {$e['baris_ke']} berdiri di samping standar lain.");
        }
    }

    /**
     * Tiap kotak centang TH-n yang TERCETAK di draf wajib punya pilihan TH-n di
     * peta, dan sebaliknya. Daftarnya diturunkan dari draf, bukan diketik di
     * sini: TH yang terhapus dari peta langsung merah, walau kotaknya ikut
     * dipindah ke `abaikan` sehingga uji "tiap kotak punya nasib" tetap hijau.
     */
    public function test_centang_thermohygro_menunjuk_label_tercetak(): void
    {
        $dikenal = array_column(LembarKerjaTemplate::THERMOHYGRO_TERCETAK, 'label');
        $th = array_values(array_filter($this->peta()['centang'], fn (array $e): bool => $e['kode'] === 'thermohygro_standard_id'));
        $tercetak = array_values(array_map(
            fn (array $k): string => (string) $k['label_dekat'],
            array_filter($this->semuaKotak(), fn (array $k): bool => $k['jenis'] === 'centang' && in_array($k['label_dekat'], $dikenal, true)),
        ));

        $this->assertNotEmpty($tercetak, 'Draf pH tidak memuat satu pun kotak centang TH-n.');
        $this->assertEqualsCanonicalizing($tercetak, array_column($th, 'pilihan'), 'Kotak TH-n tercetak dan pilihan TH-n di peta tidak sama.');
        $this->assertSame([], $this->kembar(array_column($th, 'pilihan')));

        foreach ($th as $e) {
            $this->assertContains($e['pilihan'], $dikenal);
            $this->assertSame(
                $e['pilihan'],
                $this->kotakBerisiTitik('centang', (float) $e['x'], (float) $e['y'])[0]['label_dekat'],
                "Centang {$e['pilihan']} di peta menunjuk kotak berlabel lain.",
            );
        }
    }

    /**
     * Titik yang jatuh di NOL kotak berarti drafnya bergeser dan petanya
     * menunjuk ruang kosong; di DUA kotak berarti skripnya melahirkan kotak
     * kembar. Dua-duanya merah — yang dibetulkan skrip atau petanya, bukan
     * toleransinya.
     */
    public function test_tiap_titik_peta_jatuh_di_tepat_satu_kotak_draf(): void
    {
        foreach ($this->titikPeta() as [$jenis, $label, $x, $y]) {
            $kena = $this->kotakBerisiTitik($jenis, $x, $y);

            $this->assertCount(1, $kena, "{$label} ({$x}, {$y}) jatuh di ".count($kena)." kotak {$jenis} draf.");
        }
    }

    /**
     * Kebalikannya: tiap kotak isian draf punya nasib yang diputuskan manusia —
     * dipetakan ke kunci, atau diabaikan dengan alasan. Kotak yang terlupa
     * bukan kotak yang aman; dia kotak yang belum ada yang memeriksa.
     */
    public function test_tiap_kotak_isian_draf_punya_nasib(): void
    {
        $dirujuk = [];

        foreach ($this->titikPeta() as [$jenis, , $x, $y]) {
            foreach ($this->kotakBerisiTitik($jenis, $x, $y) as $kotak) {
                $dirujuk[$kotak['id']] = ($dirujuk[$kotak['id']] ?? 0) + 1;
            }
        }

        foreach ($this->kotakIsian() as $kotak) {
            $this->assertSame(
                1,
                $dirujuk[$kotak['id']] ?? 0,
                "Kotak {$kotak['jenis']} {$kotak['id']} tidak dipetakan dan tidak diabaikan (atau dirujuk dua kali).",
            );
        }
    }

    public function test_abaikan_selalu_beralasan(): void
    {
        foreach ($this->peta()['abaikan'] as $kelompok) {
            $this->assertNotSame('', trim((string) ($kelompok['alasan'] ?? '')));
            $this->assertContains($kelompok['jenis'] ?? null, ['sel', 'isian', 'centang']);
            $this->assertNotEmpty($kelompok['titik']);
        }
    }

    /**
     * SEMUA kotak isian draf — bukan cuma yang sudah dipetakan — dilarang
     * saling tumpang, dan dilarang memotong sebagian sel tercetak. Sebelum
     * skripnya memotong isian berlabel di batas panel, kotak identitas pH
     * melebar sampai 0,95 lebar halaman dan memotong sebagian 84 kotak lain,
     * termasuk sel tabel pembacaan. Kalau test ini merah lagi, yang dibetulkan
     * skripnya — bukan toleransinya.
     */
    public function test_kotak_isian_draf_tidak_bertumpukan(): void
    {
        $isian = $this->kotakIsian();
        $pembungkus = array_values(array_filter($this->semuaKotak(), fn (array $k): bool => $k['jenis'] === 'tercetak'));

        foreach ($isian as $i => $a) {
            foreach (array_slice($isian, $i + 1) as $b) {
                $this->assertFalse(
                    $this->beririsan($a['pt'], $b['pt']),
                    "{$a['jenis']} {$a['id']} menumpuk {$b['jenis']} {$b['id']}.",
                );
            }

            foreach ($pembungkus as $c) {
                $this->assertFalse(
                    $this->beririsan($a['pt'], $c['pt']) && ! $this->membungkus($c['pt'], $a['pt']),
                    "{$a['jenis']} {$a['id']} memotong sebagian sel {$c['id']}.",
                );
            }
        }
    }

    public function test_semua_kotak_dan_titik_di_dalam_halaman(): void
    {
        foreach ($this->semuaKotak() as $k) {
            [$x0, $y0, $x1, $y1] = $k['norm'];

            $this->assertTrue(
                $x0 >= 0 && $y0 >= 0 && $x1 <= 1 && $y1 <= 1 && $x1 > $x0 && $y1 > $y0,
                "Kotak {$k['jenis']} {$k['id']} di luar halaman.",
            );
        }

        foreach ($this->titikPeta() as [, $label, $x, $y]) {
            $this->assertTrue($x > 0 && $x < 1 && $y > 0 && $y < 1, "Titik {$label} di luar halaman.");
        }
    }

    public function test_ph_meter_v1_tetap_byte_identik(): void
    {
        $this->assertSame(
            self::SHA_PH_METER_V1,
            hash_file('sha256', database_path('ocr-templates/ph_meter-v1.json')),
        );
    }

    public function test_template_lembar_kerja_tidak_membaca_folder_asli(): void
    {
        // Berkas asli sengaja tidak berpola `{kode}-v{n}.json`, jadi glob
        // pemuat tidak mungkin menangkapnya walau suatu saat foldernya ditunjuk.
        $this->assertSame([], glob(database_path('ocr-templates/asli').'/*-v*.json') ?: []);

        // Pemuat yang dipakai lapangan memulangkan v1, bukan salah satu berkas asli.
        $this->assertSame(
            json_decode((string) file_get_contents(database_path('ocr-templates/ph_meter-v1.json')), true),
            app(TemplateLembarKerja::class)->geometri('ph_meter'),
        );

        // Dan glob-nya tidak rekursif: `asli/ph_meter-v9.json` di subfolder
        // KALAH dari `ph_meter-v1.json` di folder atas, padahal versinya lebih
        // tinggi — kalau glob-nya menyelam, v9 yang terpilih.
        $this->folderFixture = storage_path('framework/testing/ocr-asli-'.getmypid());
        @mkdir($this->folderFixture.'/asli', 0755, true);
        file_put_contents($this->folderFixture.'/ph_meter-v1.json', json_encode(['versi' => 1]));
        file_put_contents($this->folderFixture.'/asli/ph_meter-v9.json', json_encode(['versi' => 9]));
        Config::set('ocr.folder_template', $this->folderFixture);

        $this->assertSame(1, app(TemplateLembarKerja::class)->geometri('ph_meter')['versi']);
    }

    /**
     * @return array<string, mixed>
     */
    private function draf(): array
    {
        return json_decode((string) file_get_contents(database_path('ocr-templates/asli/ph_meter-0509.draf.json')), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function peta(): array
    {
        return json_decode((string) file_get_contents(database_path('ocr-templates/asli/ph_meter-0509.peta.json')), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function template(): array
    {
        return app(TemplateLembarKerja::class)->untukKode('ph_meter');
    }

    /**
     * @param  list<mixed>  $nilai
     * @return list<int|string>
     */
    private function kembar(array $nilai): array
    {
        return array_keys(array_filter(array_count_values(array_map('strval', $nilai)), fn (int $n): bool => $n > 1));
    }

    /**
     * Semua titik di peta: `[jenis kotak draf, label, x, y]`.
     *
     * @return list<array{0: string, 1: string, 2: float, 3: float}>
     */
    private function titikPeta(): array
    {
        $peta = $this->peta();
        $titik = [];

        foreach ($peta['sel'] as $e) {
            $titik[] = ['sel', $e['kunci'], (float) $e['x'], (float) $e['y']];
        }

        foreach (['isian', 'centang'] as $jenis) {
            foreach ($peta[$jenis] as $e) {
                $titik[] = [$jenis, $e['kode'], (float) $e['x'], (float) $e['y']];
            }
        }

        foreach ($peta['abaikan'] as $kelompok) {
            foreach ($kelompok['titik'] as $e) {
                $titik[] = [$kelompok['jenis'], 'abaikan', (float) $e['x'], (float) $e['y']];
            }
        }

        return $titik;
    }

    /**
     * Kotak draf per jenis. Sel `calon_isian` → `sel`; sel `tercetak` dan
     * `wadah` (sel kosong yang membungkus kotak isian lain) → `tercetak`, yaitu
     * pembungkus: boleh MEMBUNGKUS kotak isian, tidak boleh memotongnya.
     *
     * @return list<array{jenis: string, id: string, label_dekat: string|null, norm: array{0: float, 1: float, 2: float, 3: float}, pt: array{0: float, 1: float, 2: float, 3: float}}>
     */
    private function semuaKotak(): array
    {
        $draf = $this->draf();
        $w = (float) $draf['sumber']['ukuran_pt']['w'];
        $h = (float) $draf['sumber']['ukuran_pt']['h'];
        $kotak = [];

        $tambah = function (string $jenis, array $o) use (&$kotak, $w, $h): void {
            $norm = [(float) $o['x'], (float) $o['y'], (float) $o['x'] + (float) $o['w'], (float) $o['y'] + (float) $o['h']];
            $kotak[] = [
                'jenis' => $jenis,
                'id' => (string) $o['id'],
                'label_dekat' => $o['label_dekat'] ?? null,
                'norm' => $norm,
                'pt' => [$norm[0] * $w, $norm[1] * $h, $norm[2] * $w, $norm[3] * $h],
            ];
        };

        foreach ($draf['sel'] as $s) {
            $tambah($s['jenis'] === 'calon_isian' ? 'sel' : 'tercetak', $s);
        }

        foreach ($draf['isian_berlabel'] as $s) {
            $tambah('isian', $s);
        }

        foreach ($draf['kotak_centang'] as $s) {
            $tambah('centang', $s);
        }

        return $kotak;
    }

    /**
     * Kotak yang bisa diisi teknisi: sel calon isian, isian berlabel, centang.
     *
     * @return list<array<string, mixed>>
     */
    private function kotakIsian(): array
    {
        return array_values(array_filter($this->semuaKotak(), fn (array $k): bool => $k['jenis'] !== 'tercetak'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function kotakBerisiTitik(string $jenis, float $x, float $y): array
    {
        return array_values(array_filter(
            $this->semuaKotak(),
            fn (array $k): bool => $k['jenis'] === $jenis
                && $x >= $k['norm'][0] && $x <= $k['norm'][2]
                && $y >= $k['norm'][1] && $y <= $k['norm'][3],
        ));
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $a
     * @param  array{0: float, 1: float, 2: float, 3: float}  $b
     */
    private function beririsan(array $a, array $b): bool
    {
        return min($a[2], $b[2]) - max($a[0], $b[0]) > self::TOLERANSI_PT
            && min($a[3], $b[3]) - max($a[1], $b[1]) > self::TOLERANSI_PT;
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $luar
     * @param  array{0: float, 1: float, 2: float, 3: float}  $dalam
     */
    private function membungkus(array $luar, array $dalam): bool
    {
        return $luar[0] <= $dalam[0] + self::TOLERANSI_PT
            && $luar[1] <= $dalam[1] + self::TOLERANSI_PT
            && $luar[2] >= $dalam[2] - self::TOLERANSI_PT
            && $luar[3] >= $dalam[3] - self::TOLERANSI_PT;
    }
}
