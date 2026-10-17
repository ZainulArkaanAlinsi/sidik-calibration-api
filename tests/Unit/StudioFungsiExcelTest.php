<?php

namespace Tests\Unit;

use App\Services\Studio\Hitung\DaftarPutihFungsi;
use App\Services\Studio\Hitung\FungsiExcel;
use App\Services\Studio\Hitung\IsiSel;
use App\Services\Studio\Hitung\PenulisUlangPangkat;
use App\Services\Studio\Hitung\TabelFungsiPhpSpreadsheet;
use App\Services\Studio\Hitung\TabelTinvExcel;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Calculation\Statistical\StandardDeviations;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fungsi lembar Studio tahap C lawan ANGKA ACUAN EXCEL. Angka di bawah dibaca
 * dari Excel 365 (16.0) lewat COM (`docs/skrip/paritas-excel-com.ps1`) pada
 * workbook vektor SINTETIS (seed 20261014) — bukan data lab. Bandingan bit,
 * bukan toleransi, kecuali yang disebut.
 */
class StudioFungsiExcelTest extends TestCase
{
    /** @return array<string, array{0: list<float>, 1: float}> vektor → STDEV Excel */
    public static function stdevExcel(): array
    {
        return [
            'terkondisi buruk n=2' => [[100.000436, 100.0003001], 9.6095811555770252E-05],
            'kecil n=4' => [[0.500741555, 0.500435971, 0.500275991, 0.500531899], 0.00019458754318127868],
            'massa n=5' => [[1000.66201, 1000.3413, 1000.98069, 1000.44283, 1000.44344], 0.2557065085796475],
            'massa n=9' => [[1000.73771, 1000.23767, 1000.38709, 1000.7709, 1000.32834, 1000.29606, 1000.53771, 1000.7684, 1000.15999], 0.24060150126529356],
            'terkondisi buruk n=14' => [[100.000418, 100.0006281, 100.0005169, 100.0001557, 100.0005404, 100.0001201, 100.0001354, 100.0000413, 100.0005964, 100.0008858, 100.0000075, 100.000281, 100.0001886, 100.0008623], 0.00029353333833534841],
            'kecil n=3' => [[0.500409127, 0.500498374, 0.500554055], 7.3108966565942917E-05],
            'bertanda campur n=3' => [[283.532, 158.696, -183.909], 242.02730610477266],
        ];
    }

    #[DataProvider('stdevExcel')]
    public function test_stdev_dua_lintasan_identik_bit_dengan_excel(array $x, float $excel): void
    {
        $this->assertSame($excel, FungsiExcel::stdevDuaLintasan($x));
    }

    public function test_stdev_bawaan_phpspreadsheet_memang_meleset_dan_sisa_beda_dua_lintasan_terdokumentasi(): void
    {
        // Bukti kenapa bawaan ditukar: satu lintasan meleset jauh pada data terkondisi buruk.
        $x = [100.000418, 100.0006281, 100.0005169, 100.0001557, 100.0005404, 100.0001201, 100.0001354, 100.0000413, 100.0005964, 100.0008858, 100.0000075, 100.000281, 100.0001886, 100.0008623];
        $this->assertNotSame(0.00029353333833534841, StandardDeviations::STDEV($x));

        // Batas yang diketahui: data bertanda campur bersebaran besar — dua lintasan 1 ULP dari Excel
        // (Excel 345.37309951531859, baris 10 vektor uji). Dikunci supaya perubahan algoritma ketahuan.
        $campur = [216.341, -399.113, 498.347, 321.691, 449.652, -208.592, -26.196, -472.028, 49.59, 234.991, -342.705];
        $ulp = abs(unpack('q', pack('d', (float) FungsiExcel::stdevDuaLintasan($campur)))[1] - unpack('q', pack('d', 345.37309951531859))[1]);
        $this->assertLessThanOrEqual(4, $ulp);
    }

    public function test_stdev_aturan_argumen_excel(): void
    {
        $this->assertSame('#DIV/0!', FungsiExcel::stdev([[1.0]]));
        $this->assertSame('#N/A', FungsiExcel::stdev([[1.0, '#N/A', 2.0]]));
        // Teks & boolean di RUJUKAN diabaikan; argumen langsung TRUE dihitung 1.
        $this->assertSame(FungsiExcel::stdevDuaLintasan([1.0, 3.0]), FungsiExcel::stdev([[1.0, 'x', true, 3.0, null]]));
        $this->assertSame(FungsiExcel::stdevDuaLintasan([1.0, 3.0, 1.0]), FungsiExcel::stdev([[1.0, 3.0]], true));
        $this->assertSame('#VALUE!', FungsiExcel::stdev([[1.0, 3.0]], 'abc'));
    }

    /** @return array<string, array{0: float, 1: float, 2: float}> p, df → TINV Excel */
    public static function tinvExcel(): array
    {
        return [
            'df 1' => [0.05, 1, 12.706204736174707],
            'df 2' => [0.05, 2, 4.3026527297494637],
            'df 3' => [0.05, 3, 3.1824463052837091],
            'df 10' => [0.05, 10, 2.2281388519862744],
            'df 30' => [0.05, 30, 2.0422724563012378],
            'df 150' => [0.05, 150, 1.9759053308966197],
            'df 400' => [0.05, 400, 1.9659123432294732],
            'df 2.5 dipotong' => [0.05, 2.5, 4.3026527297494637],
            'df 9.7 dipotong' => [0.05, 9.7, 2.2621571627982053],
        ];
    }

    #[DataProvider('tinvExcel')]
    public function test_tinv_identik_bit_dengan_excel_di_dalam_tabel(float $p, float $df, float $excel): void
    {
        $this->assertSame($excel, FungsiExcel::tinv($p, $df));
        $this->assertSame($excel, FungsiExcel::tinv([[$p]], [[$df]]), 'rujukan sel datang sebagai larik');
    }

    public function test_tabel_tinv_utuh_dan_cadangan_dekat_excel(): void
    {
        $this->assertSame(5000, TabelTinvExcel::dfMaks(0.05));
        $this->assertSame(0, TabelTinvExcel::dfMaks(1 - 0.95), '0.050000000000000044 bukan 0.05');
        $this->assertSame('0979ef89626c7f962adb4913e6a3b11d20a41dc813cf16be4c4c1b2805cfb0c8', hash_file('sha256', resource_path('studio/tinv-excel/p0.05.bin')));

        // Di luar tabel: algoritma cadangan, tidak dijamin bit — batas relatif dikunci.
        foreach ([[0.05, 10000, 1.9602012398903417], [0.05, 1000000, 1.9599663568095012], [0.0455, 5, 2.6486542542831191], [0.0455, 50, 2.0512507818518828], [0.01, 9, 3.2498355415921263], [0.3173105, 10000, 1.0000500187493713], [0.5, 9, 0.70272214675132494]] as [$p, $df, $excel]) {
            $hasil = FungsiExcel::tinv($p, $df);
            // df besar: tahap akhir Excel √(df·(1/x−1)) dengan x ≈ 1 memperbesar galat x
            // ~df/t² kali — nilai Excel sendiri hanya ±1e-12 relatif di sana.
            $this->assertEqualsWithDelta($excel, $hasil, abs($excel) * ($df >= 1000 ? 1e-11 : 1e-12), "p={$p} df={$df}");
        }

        $this->assertSame('#NUM!', FungsiExcel::tinv(0.0, 5));
        $this->assertSame('#NUM!', FungsiExcel::tinv(1.5, 5));
        $this->assertSame('#NUM!', FungsiExcel::tinv(0.05, 0.9));
        $this->assertSame('#VALUE!', FungsiExcel::tinv('abc', 5));
        $this->assertSame(0.0, FungsiExcel::tinv(1.0, 5));
    }

    public function test_pasang_lepas_menukar_lalu_mengembalikan_tabel_fungsi_phpspreadsheet(): void
    {
        $asli = TabelFungsiPhpSpreadsheet::tabel()['STDEV']['functionCall'];
        $ss = new Spreadsheet;
        $ws = $ss->getActiveSheet();
        $ws->fromArray([[100.000418], [100.0006281], [100.0005169], [100.0001557]], null, 'A1');
        $ws->setCellValue('B1', '=STDEV(A1:A4)');
        $ws->setCellValue('B2', '=NOW()');
        $ws->setCellValue('B3', '=TODAY()');
        $ws->setCellValue('B4', '=T.INV.2T(0.05,12)');

        FungsiExcel::pasang(46309.75);

        try {
            $this->assertSame(FungsiExcel::stdevDuaLintasan([100.000418, 100.0006281, 100.0005169, 100.0001557]), $ws->getCell('B1')->getCalculatedValue());
            $this->assertSame(46309.75, $ws->getCell('B2')->getCalculatedValue());
            $this->assertSame(46309.0, (float) $ws->getCell('B3')->getCalculatedValue());
            $this->assertSame(2.1788128296672284, $ws->getCell('B4')->getCalculatedValue());
        } finally {
            FungsiExcel::lepas();
        }

        $this->assertSame($asli, TabelFungsiPhpSpreadsheet::tabel()['STDEV']['functionCall']);
        Calculation::getInstance($ss)->clearCalculationCache();
        $this->assertNotSame(46309.75, $ws->getCell('B2')->getCalculatedValue(), 'NOW kembali ke jam sistem sesudah lepas');
        $ss->disconnectWorksheets();
    }

    /** @return array<string, array{0: float, 1: float, 2: float}> x, y → x^y Excel (COM, vektor sintetis seed 7) */
    public static function pangkatExcel(): array
    {
        return [
            '^2' => [1.741615, 2, 3.0332228082249997],
            '^3' => [1.741615, 3, 5.2827063411467829],
            '^4' => [1.741615, 4, 9.2004406043363538],
            '^4 besar' => [6413.99, 4, 1692439348546062],
            '^4 kecil' => [0.000729071, 4, 2.8253957997488411E-13],
            '^0.5' => [6413.99, 0.5, 80.087389768926798],
            '^-1' => [6413.99, -1, 0.00015590919225006588],
            '^-2' => [0.000729071, -2, 1881309.9498776444],
            '^2.5' => [1.741615, 2.5, 4.0029520810423476],
        ];
    }

    #[DataProvider('pangkatExcel')]
    public function test_pangkat_identik_bit_dengan_excel(float $x, float $y, float $excel): void
    {
        $this->assertSame($excel, FungsiExcel::pangkat($x, $y));
        $this->assertSame($excel, FungsiExcel::pangkat([[$x]], $y), 'rujukan sel datang sebagai larik');
    }

    public function test_pangkat_galat_seperti_excel(): void
    {
        $this->assertSame('#NUM!', FungsiExcel::pangkat(0.0, 0.0));
        $this->assertSame('#DIV/0!', FungsiExcel::pangkat(0.0, -1.0));
        $this->assertSame('#NUM!', FungsiExcel::pangkat(-8.0, 1 / 3));
        $this->assertSame(-8.0, FungsiExcel::pangkat(-2.0, 3.0));
        $this->assertSame('#N/A', FungsiExcel::pangkat('#N/A', 2.0));
        $this->assertSame([[1.0, 4.0]], FungsiExcel::pangkat([[1.0, 2.0]], 2.0));
    }

    public function test_penulis_ulang_pangkat_memakai_presedensi_parser(): void
    {
        $this->assertNull(PenulisUlangPangkat::tulisUlang('=A1*2+"x^y"'), 'tanpa ^ di luar teks');
        $this->assertSame('=SIDIK.PANGKAT(A1,2)', PenulisUlangPangkat::tulisUlang('=A1^2'));
        // Excel: -2^2 = 4 (negasi mengikat lebih kuat), a^b^c kiri-asosiatif.
        $this->assertSame('=SIDIK.PANGKAT((-A1),2)', PenulisUlangPangkat::tulisUlang('=-A1^2'));
        $this->assertSame('=SIDIK.PANGKAT(SIDIK.PANGKAT(A1,2),3)', PenulisUlangPangkat::tulisUlang('=A1^2^3'));
        $this->assertSame('=(SIDIK.PANGKAT(AA17,4)/AE16)', PenulisUlangPangkat::tulisUlang('=AA17^4/AE16'));
        $this->assertSame('=SQRT((SIDIK.PANGKAT(\'My S\'!$C$3,2)+SIDIK.PANGKAT(SUM(B1:B4),2)))', PenulisUlangPangkat::tulisUlang("=SQRT('My S'!\$C\$3^2+SUM(B1:B4)^2)"));
        $this->assertSame('=IF((A1="a""b"),SIDIK.PANGKAT(Nama,0.5),#N/A)', PenulisUlangPangkat::tulisUlang('=IF(A1="a""b",Nama^0.5,#N/A)'));
        $this->assertSame('=VLOOKUP(SIDIK.PANGKAT(A1,2),Tabel!$A$1:$B$9,2,)', PenulisUlangPangkat::tulisUlang('=VLOOKUP(A1^2,Tabel!$A$1:$B$9,2,)'));
        // Tidak ditulis ulang: larik konstan, rujukan kolom utuh.
        $this->assertNull(PenulisUlangPangkat::tulisUlang('=SUM({1,2}^2)'));
        $this->assertNull(PenulisUlangPangkat::tulisUlang('=SUM(A:A)^2'));
    }

    public function test_rumus_ditulis_ulang_hasilnya_sama_selain_pangkat(): void
    {
        $ss = new Spreadsheet;
        $ws = $ss->getActiveSheet();
        $ws->fromArray([[1.741615, 'a"b', 3], [6413.99, 'x', 4]], null, 'A1');
        $rumus = ['=IF(B1="a""b",A1^2+C1*3%,0)', '=-A1^2&"|"&(A2-A1)^0.5', '=SUM(A1:A2)^2/C2', '=A1^C1^0.5', '=(A1>1)*A2^-1'];

        FungsiExcel::pasang(46309.5);

        try {
            foreach ($rumus as $i => $r) {
                $baru = PenulisUlangPangkat::tulisUlang($r);
                $this->assertNotNull($baru, $r);
                $ws->setCellValue('E'.($i + 1), $r);
                $ws->setCellValue('F'.($i + 1), $baru);
                $asli = $ws->getCell('E'.($i + 1))->getCalculatedValue();
                $tulis = $ws->getCell('F'.($i + 1))->getCalculatedValue();
                // Pembanding: hasil bawaan (pow) dan tulis-ulang (Excel) sama sampai pembulatan terakhir.
                is_float($asli) ? $this->assertEqualsWithDelta($asli, $tulis, abs($asli) * 1e-14, $r) : $this->assertSame($asli, $tulis, $r);
            }
        } finally {
            FungsiExcel::lepas();
        }

        $ss->disconnectWorksheets();
    }

    public function test_isi_sel_diketik_seperti_excel(): void
    {
        $kasus = [
            '' => [IsiSel::KOSONG, null],
            '=A1*2' => [DataType::TYPE_FORMULA, '=A1*2'],
            '12.5' => [DataType::TYPE_NUMERIC, 12.5],
            '-3' => [DataType::TYPE_NUMERIC, -3.0],
            '1e-3' => [DataType::TYPE_NUMERIC, 0.001],
            '.5' => [DataType::TYPE_NUMERIC, 0.5],
            '5%' => [DataType::TYPE_NUMERIC, 0.05],
            'true' => [DataType::TYPE_BOOL, true],
            'FALSE' => [DataType::TYPE_BOOL, false],
            "'123" => [DataType::TYPE_STRING, '123'],
            'F2' => [DataType::TYPE_STRING, 'F2'],
            '1,5' => [DataType::TYPE_STRING, '1,5'],
            '14/10/2026' => [DataType::TYPE_STRING, '14/10/2026'],
        ];

        foreach ($kasus as $isi => [$tipe, $nilai]) {
            $hasil = IsiSel::dariKetikan((string) $isi);
            $this->assertSame([$tipe, $nilai], [$hasil->tipe, $hasil->nilai], (string) $isi);
        }
    }

    public function test_daftar_putih_fungsi(): void
    {
        $this->assertSame([], DaftarPutihFungsi::terlarang('=IF(ISERROR(VLOOKUP(A1,B:C,2,0)),"",_xlfn.STDEV.S(D1:D9)*TINV(0.05,E1))'));
        $this->assertSame(['WEBSERVICE'], DaftarPutihFungsi::terlarang('=WEBSERVICE("http://x")'));
        $this->assertSame(['CELL', 'INFO'], DaftarPutihFungsi::terlarang('=CELL("filename")&INFO("system")'));
        $this->assertSame(['RANDBETWEEN'], DaftarPutihFungsi::terlarang('=RANDBETWEEN(1,6)'));
        $this->assertNull(DaftarPutihFungsi::terlarang('=SUM(1,'));
    }
}
