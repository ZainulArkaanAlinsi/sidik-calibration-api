<?php

namespace Tests\Unit;

use App\Models\Certificate;
use App\Services\DataTampilanSertifikat;
use App\Services\QrCodeGenerator;
use App\Services\SertifikatSatuHalaman;
use App\Support\FormatMasterSertifikat;
use App\Support\SkalaUkuranSertifikat;
use App\Support\TataLetakSertifikat;
use Tests\TestCase;

/**
 * A3: ukuran huruf sertifikat mengikuti median lembar master.
 *
 * Yang dikunci: (1) urutan percobaan ukuran + gerbang yang tidak dilonggarkan,
 * (2) profil/snapshot TANPA `font_master_pt` memakai tangga lama persis,
 * (3) CSS skala hanya muncul bila diminta. Angka ukuran hasil render sungguhan
 * (font median PDF vs master) diukur terpisah dan dilaporkan; tes ini tidak
 * meniru implementasinya.
 */
class UkuranHurufMengikutiMasterTest extends TestCase
{
    public function test_kandidat_turun_dari_persis_master_ke_95_persen_dan_tak_melewati_normal(): void
    {
        $k = SkalaUkuranSertifikat::kandidat(7.44);

        $this->assertEqualsWithDelta(7.44 / 0.75, $k[0], 0.01, 'Kandidat pertama = persis ukuran master.');
        $this->assertSame($k, array_values($k));
        $this->assertGreaterThanOrEqual(2, count($k));

        foreach ($k as $i => $px) {
            $this->assertLessThanOrEqual(7.44 / 0.75 + 0.01, $px, 'Tidak boleh lebih besar dari master.');
            $this->assertGreaterThanOrEqual(0.95 * 7.44 / 0.75 - 0.01, $px, 'Tidak boleh di bawah 0,95 x master.');

            if ($i > 0) {
                $this->assertEqualsWithDelta(0.25, $k[$i - 1] - $px, 0.011);
            }
        }

        // Master 8,3 pt (> normal 11 px = 8,25 pt): dipatok normal, tak lebih besar.
        $this->assertSame(11.0, SkalaUkuranSertifikat::kandidat(8.4)[0]);
    }

    public function test_css_menimpa_semua_ukuran_keluarga_dengan_skala_yang_sama(): void
    {
        $css = SkalaUkuranSertifikat::css(10.0, 'padat');

        // padat: tabel dasar 8 px -> k = 1,25
        $this->assertStringContainsString('body.skala-ukuran table.data { font-size: 10px !important; }', $css);
        $this->assertStringContainsString('body.skala-ukuran .judul { font-size: 17.5px !important; }', $css);
        $this->assertStringContainsString('body.skala-ukuran table.ttd td { font-size: 11.88px !important; }', $css);

        $rapat = SkalaUkuranSertifikat::css(9.5, 'rapat');
        $this->assertStringContainsString('body.skala-ukuran table.data { font-size: 9.5px !important; }', $rapat, 'k = 1: sama dengan rapat-2.');
        $this->assertStringContainsString('body.skala-ukuran { font-size: 10px !important; }', $rapat);
    }

    public function test_hanya_profil_yang_diaudit_punya_ukuran_master_dan_berada_di_rentang_baca(): void
    {
        $this->assertSame(
            ['oven', 'timbangan', 'tits', 'conductivity_meter', 'pressure_gauge', 'vacuum_gauge', 'timer_stopwatch', 'gas_detector', 'viscometer', 'sieve'],
            array_keys(FormatMasterSertifikat::FONT_MEDIAN_PT),
        );
        $this->assertNull(FormatMasterSertifikat::fontMasterPt('thermohygro'), 'Master 4,8 pt tak terbaca: sengaja tidak diikuti.');
        $this->assertNull(FormatMasterSertifikat::fontMasterPt('autoclave'));

        foreach (FormatMasterSertifikat::FONT_MEDIAN_PT as $pt) {
            $this->assertGreaterThanOrEqual(6.0, $pt);
        }
    }

    public function test_tata_letak_menerima_dan_memeriksa_font_master(): void
    {
        $this->assertNull(TataLetakSertifikat::BAWAAN['font_master_pt']);
        $this->assertSame(7.44, TataLetakSertifikat::normalisasi(['font_master_pt' => 7.44])['font_master_pt']);

        $this->expectException(\InvalidArgumentException::class);
        TataLetakSertifikat::normalisasi(['font_master_pt' => 4.8]);
    }

    // ------------------------------------------------------------ pemilihan

    /**
     * Penghitung percobaan: `cetak()` diganti supaya tidak merender dompdf.
     *
     * @param  callable(?float, string, bool): int  $halaman  ukuran, keluarga, apakah kotak uji -> jumlah halaman
     */
    private function layanan(array $snapshot, callable $halaman, array &$percobaan): SertifikatSatuHalaman
    {
        $bahan = ['snapshot' => $snapshot];
        $tampilan = new class(new QrCodeGenerator, $bahan) extends DataTampilanSertifikat
        {
            public function __construct(QrCodeGenerator $qr, private array $bahan)
            {
                parent::__construct($qr);
            }

            public function untuk(Certificate $sertifikat, bool $web = false): array
            {
                return $this->bahan;
            }
        };

        return new class($tampilan, $halaman, $percobaan) extends SertifikatSatuHalaman
        {
            public function __construct(DataTampilanSertifikat $t, private $halaman, private array &$percobaan)
            {
                parent::__construct($t);
            }

            protected function cetak(array $bahan, bool $paksaPadat, int $longgar = 0, bool $ujiCadangan = false, ?float $ukuranTabel = null, string $keluargaUkuran = 'rapat'): array
            {
                $this->percobaan[] = [$ukuranTabel, $ukuranTabel === null ? ($paksaPadat ? 'padat' : "longgar{$longgar}") : $keluargaUkuran, $ujiCadangan];

                return [
                    'isi' => $ukuranTabel === null ? 'TANGGA' : "SKALA-{$ukuranTabel}-{$keluargaUkuran}",
                    'halaman' => ($this->halaman)($ukuranTabel, $ukuranTabel === null ? 'tangga' : $keluargaUkuran, $ujiCadangan),
                ];
            }
        };
    }

    public function test_memilih_ukuran_terdekat_ke_master_yang_muat_dengan_kotak_uji(): void
    {
        $percobaan = [];
        // rapat tidak pernah muat; padat muat hanya sampai 9,7 px.
        $layanan = $this->layanan(
            ['tata_letak' => ['font_master_pt' => 7.44]],
            fn (?float $u, string $k): int => $k === 'padat' && $u <= 9.7 ? 1 : 2,
            $percobaan,
        );

        $this->assertSame('SKALA-9.67-padat', $layanan->isi(new Certificate));

        // Tiap ukuran: rapat (uji) lalu padat (uji); yang muat dirender SEKALI LAGI tanpa kotak uji.
        $this->assertSame([9.92, 'rapat', true], $percobaan[0]);
        $this->assertSame([9.92, 'padat', true], $percobaan[1]);
        $this->assertSame([9.67, 'rapat', true], $percobaan[2]);
        $this->assertSame([9.67, 'padat', true], $percobaan[3]);
        $this->assertSame([9.67, 'padat', false], $percobaan[4]);
        $this->assertCount(5, $percobaan);
    }

    public function test_tak_ada_yang_muat_jatuh_ke_tangga_lama(): void
    {
        $percobaan = [];
        $layanan = $this->layanan(
            ['tata_letak' => ['font_master_pt' => 7.44]],
            fn (?float $u, string $k, bool $uji): int => $k === 'tangga' && $u === null ? 1 : 2,
            $percobaan,
        );

        $this->assertSame('TANGGA', $layanan->isi(new Certificate), 'Tangga lama tetap jaring pengaman.');
    }

    public function test_snapshot_tanpa_font_master_memakai_tangga_lama_tanpa_ukuran(): void
    {
        foreach ([['tata_letak' => []], [], ['tata_letak' => ['font_master_pt' => null]]] as $snapshot) {
            $percobaan = [];
            $layanan = $this->layanan($snapshot, fn (): int => 1, $percobaan);

            $this->assertSame('TANGGA', $layanan->isi(new Certificate));

            foreach ($percobaan as [$ukuran]) {
                $this->assertNull($ukuran, 'Snapshot lama tidak boleh menyentuh jalur ukuran-master.');
            }

            // Uji longgar-2 (muat) lalu cetak longgar-2: dua percobaan, urutan lama.
            $this->assertSame([[null, 'longgar2', true], [null, 'longgar2', false]], $percobaan);
        }
    }

    public function test_timbangan_hanya_mencoba_keluarga_padat(): void
    {
        $percobaan = [];
        $layanan = $this->layanan(
            ['timbangan' => ['x' => 1], 'tata_letak' => ['font_master_pt' => 6.84]],
            fn (): int => 1,
            $percobaan,
        );

        $this->assertSame('SKALA-9.12-padat', $layanan->isi(new Certificate));

        foreach ($percobaan as [, $keluarga]) {
            $this->assertSame('padat', $keluarga);
        }
    }

    // ------------------------------------------------------------ blade

    private function render(array $tambahan): string
    {
        app()->setLocale('en');
        $snapshot = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/sertifikat-beku/labu_ukur.snapshot.json'), true, 512, JSON_THROW_ON_ERROR);

        return view('sertifikat.pdf', [
            'sertifikat' => (new Certificate)->forceFill(['qr_token' => 'abc123']),
            'snapshot' => $snapshot,
            'logo' => null,
            'kop' => null,
            'tandaTangan' => null,
            'posisiTtd' => [],
            'ukuranTtd' => [],
            'qr' => null,
            'keputusan' => null,
            'web' => false,
            'paksaPadat' => false,
            ...$tambahan,
        ])->render();
    }

    public function test_blade_tanpa_ukuran_tidak_memuat_apa_pun_dari_skala(): void
    {
        $html = $this->render([]);

        $this->assertStringNotContainsString('skala-ukuran', $html);
        $this->assertStringContainsString("    </style>\n</head>", str_replace("\r\n", "\n", $html));
    }

    public function test_blade_dengan_ukuran_menambah_kelas_dan_css_serta_keluarga_padat_memadatkan(): void
    {
        $rapat = $this->render(['ukuranTabel' => 9.75, 'keluargaUkuran' => 'rapat', 'longgar' => -2]);
        $this->assertStringContainsString('body.skala-ukuran table.data { font-size: 9.75px !important; }', $rapat);
        $this->assertMatchesRegularExpression('/<body class="[^"]*rapat-2[^"]*skala-ukuran"/', $rapat);
        $this->assertDoesNotMatchRegularExpression('/<body class="padat/', $rapat);

        $padat = $this->render(['ukuranTabel' => 9.0, 'keluargaUkuran' => 'padat', 'paksaPadat' => true]);
        $this->assertStringContainsString('body.skala-ukuran table.data { font-size: 9px !important; }', $padat);
        $this->assertMatchesRegularExpression('/<body class="padat[^"]*skala-ukuran"/', $padat);

        // Halaman web (QR) tidak pernah kena skala.
        $web = $this->render(['ukuranTabel' => 9.0, 'keluargaUkuran' => 'padat', 'web' => true]);
        $this->assertStringNotContainsString('skala-ukuran', $web);
    }
}
