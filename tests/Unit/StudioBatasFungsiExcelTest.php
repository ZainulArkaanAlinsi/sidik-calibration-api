<?php

namespace Tests\Unit;

use App\Services\Studio\Hitung\BatasFungsiExcel;
use App\Services\Studio\Hitung\FungsiExcel;
use App\Services\Studio\Hitung\GagalHitung;
use App\Services\Studio\Hitung\GrafDependensi;
use App\Services\Studio\Hitung\HasilHitung;
use App\Services\Studio\Hitung\MesinHitung;
use App\Services\Studio\Hitung\ModelHitung;
use App\Services\Studio\Hitung\TabelFungsiPhpSpreadsheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * Tinjau C-2 #1 — bom lewat ARGUMEN SKALAR di `/hitung`. Pagar dipasang di
 * runtime fungsi (`BatasFungsiExcel`), jadi berlaku juga untuk rumus ASLI yang
 * argumennya datang dari sel yang disunting (`=REPT("-",B1)`, B1 = 1e9).
 *
 * Angka & galat acuan dibaca dari Excel 365 (16.0) lewat COM pada 15 Okt 2026
 * (`docs/skrip/paritas-excel-com.ps1`-gaya, rumus sintetis). Kecuali disebut
 * "batas mesin", setiap galat di sini adalah galat yang sama yang dipulangkan
 * Excel. Sebelum pagar: FIXED/DOLLAR/REPT = PHP Fatal memori, FACT/COMBIN/
 * LCM/TDIST/CHISQ.INV > 4 s (dihentikan), SUBSTITUTE/TEXT `@` = Fatal memori.
 */
class StudioBatasFungsiExcelTest extends TestCase
{
    /** @return array<string, array{0: string, 1: mixed}> rumus → nilai Excel (COM) */
    public static function probeExcel(): array
    {
        return [
            // Probe tinjau C-2.
            'FIXED desimal 1e9' => ['=FIXED(1,1000000000)', '#VALUE!'],
            'FACT 1e9' => ['=FACT(1E9)', '#NUM!'],
            'COMBIN 1e9,5e8' => ['=COMBIN(1E9,5E8)', '#NUM!'],
            'REPT 489 MB' => ['=LEN(REPT("abcdefghij",5E7))', '#VALUE!'],
            'REPT 965 MB' => ['=REPT("a",1E9)', '#VALUE!'],
            // FIXED / DOLLAR: desimal ≤ 127, hasil ≤ 255 karakter.
            'FIXED 128' => ['=FIXED(1,128)', '#VALUE!'],
            'FIXED "128"' => ['=FIXED(1,"128")', '#VALUE!'],
            'FIXED 127 boleh' => ['=LEN(FIXED(1,127))', 129],
            'FIXED 127,9 dipotong' => ['=LEN(FIXED(1,127.9))', 129],
            'FIXED hasil > 255' => ['=FIXED(1E200,50)', '#VALUE!'],
            'FIXED galat nilai dulu' => ['=FIXED(NA(),128)', '#N/A'],
            'DOLLAR 1e9' => ['=DOLLAR(1,1E9)', '#VALUE!'],
            'DOLLAR 127 boleh' => ['=LEN(DOLLAR(1,127))', 130],
            'DOLLAR galat nilai dulu' => ['=DOLLAR(NA(),128)', '#N/A'],
            // REPT: hasil ≤ 32.767, hitungan < 2³¹.
            'REPT 32767 boleh' => ['=LEN(REPT("a",32767))', 32767],
            'REPT 32768' => ['=REPT("a",32768)', '#VALUE!'],
            'REPT ab 16384' => ['=REPT("ab",16384)', '#VALUE!'],
            'REPT kosong 2^31-1' => ['=LEN(REPT("",2147483647))', 0],
            'REPT kosong 2^31' => ['=LEN(REPT("",2147483648))', '#VALUE!'],
            'REPT galat teks' => ['=REPT(NA(),1E9)', '#N/A'],
            // TEXT: format & hasil ≤ 255; format tanggal untuk serial di luar 1900..9999.
            'TEXT format 256' => ['=TEXT(1,REPT("0",256))', '#VALUE!'],
            'TEXT format 255 boleh' => ['=LEN(TEXT(1,REPT("0",255)))', 255],
            'TEXT hasil 256' => ['=TEXT(REPT("a",256),"@")', '#VALUE!'],
            'TEXT bom @' => ['=LEN(TEXT(REPT("a",32767),REPT("@",8000)))', '#VALUE!'],
            'TEXT tanggal 1e9' => ['=TEXT(1E9,"yyyy")', '#VALUE!'],
            'TEXT tanggal negatif' => ['=TEXT(-0.5,"hh:mm")', '#VALUE!'],
            'TEXT galat nilai dulu' => ['=TEXT(NA(),REPT("0",300))', '#N/A'],
            // SUBSTITUTE: panjang dihitung sebelum dibangun.
            'SUBSTITUTE 1 GB' => ['=LEN(SUBSTITUTE(REPT("a",32767),"a",REPT("b",32767)))', '#VALUE!'],
            'SUBSTITUTE 65 rb' => ['=SUBSTITUTE(REPT("a",32767),"a","bb")', '#VALUE!'],
            'SUBSTITUTE 32 rb boleh' => ['=LEN(SUBSTITUTE(REPT("a",16000),"a","bb"))', 32000],
            // FACT / COMBIN.
            'FACT 171' => ['=FACT(171)', '#NUM!'],
            'FACT 1e300' => ['=FACT(1E300)', '#NUM!'],
            'COMBIN 1e9,2' => ['=COMBIN(1E9,2)', 4.999999995E+17],
            'COMBIN 1e9,3' => ['=COMBIN(1E9,3)', 1.6666666616666667E+26],
            'COMBIN 1e9,10' => ['=COMBIN(1E9,10)', 2.7557317983906554E+83],
            'COMBIN 200,2' => ['=COMBIN(200,2)', 19900.0],
            'COMBIN 171.9,2.9' => ['=COMBIN(171.9,2.9)', 14535.0],
            'COMBIN 1000,3' => ['=COMBIN(1000,3)', 166167000.0],
            'COMBIN 1030,515 luap' => ['=COMBIN(1030,515)', '#NUM!'],
            'COMBIN 1e9,40 luap' => ['=COMBIN(1E9,40)', '#NUM!'],
            'COMBIN n 2^31-1' => ['=COMBIN(2147483647,1)', '#NUM!'],
            'COMBIN 1e15,2' => ['=COMBIN(1E15,2)', '#NUM!'],
            'COMBIN galat' => ['=COMBIN(1E10,NA())', '#N/A'],
            // LCM / GCD: > 2⁵³ (15 digit bermakna) → #NUM!.
            'LCM prima dekat 2^53' => ['=LCM(9007199254740881)', 9007199254740881],
            'LCM hasil 2^53 boleh' => ['=LCM(9007199254740992,2)', 9007199254740992],
            'LCM hasil > 2^53' => ['=LCM(4503599627370496,3)', '#NUM!'],
            'LCM 1e300' => ['=LCM(1E300)', '#NUM!'],
            'LCM nol tetap dicek' => ['=LCM(0,1E300)', '#NUM!'],
            'LCM galat dulu' => ['=LCM(NA(),1E300)', '#N/A'],
            'GCD 1e300' => ['=GCD(1E300,2)', '#NUM!'],
            'GCD 15 digit' => ['=GCD(9007199254741000)', '#NUM!'],
            'GCD nol dicek' => ['=GCD(0,1E300)', '#NUM!'],
            'GCD galat dulu' => ['=GCD(1E300,NA())', '#N/A'],
            // Distribusi: df > 10¹⁰ = batas Excel; 10⁶ < df ≤ 10¹⁰ = batas MESIN (Excel memberi nilai).
            'TDIST df 1e11 (Excel)' => ['=TDIST(1,1E11,2)', '#NUM!'],
            'T.DIST.2T df 2e10 (Excel)' => ['=T.DIST.2T(1,2E10)', '#NUM!'],
            'T.DIST.RT df 2e10 (Excel)' => ['=T.DIST.RT(1,2E10)', '#NUM!'],
            'CHISQ.INV df 2e10 (Excel)' => ['=CHISQ.INV(0.5,2E10)', '#NUM!'],
            'TDIST df 1e9 (batas mesin)' => ['=TDIST(1,1E9,2)', '#NUM!'],
            'CHISQ.INV df 1e9 (batas mesin)' => ['=CHISQ.INV(0.5,1E9)', '#NUM!'],
            // Tanggal di luar 1900..9999.
            'DATE 9999-12-32' => ['=DATE(9999,12,32)', '#NUM!'],
            'DATE 1900-01-(-1)' => ['=DATE(1900,1,-1)', '#NUM!'],
            'DATE 9999-12-31 boleh' => ['=DATE(9999,12,31)', 2958465.0],
            'EDATE 1e9 bulan' => ['=EDATE(1,1E9)', '#NUM!'],
            'EDATE sebelum 1900' => ['=EDATE(31,-1)', '#NUM!'],
            'EDATE awal > 9999' => ['=EDATE(2958466,0)', '#NUM!'],
            'EOMONTH 1e9 bulan' => ['=EOMONTH(1,1E9)', '#NUM!'],
            'EOMONTH sebelum 1900' => ['=EOMONTH(1,-1)', '#NUM!'],
            'EOMONTH 32,-1 boleh' => ['=EOMONTH(32,-1)', 31.0],
            'WORKDAY 1e9' => ['=WORKDAY(1,1E9)', '#NUM!'],
            'WORKDAY negatif' => ['=WORKDAY(2,-1)', '#NUM!'],
            'WORKDAY awal > 9999' => ['=WORKDAY(2958466,0)', '#NUM!'],
            'WORKDAY ke 9999-12-31 boleh' => ['=WORKDAY(2958464,1)', 2958465.0],
            'NETWORKDAYS 1e9' => ['=NETWORKDAYS(1,1E9)', '#NUM!'],
            'NETWORKDAYS sampai 9999 boleh' => ['=NETWORKDAYS(1,2958465)', 2113190],
        ];
    }

    #[DataProvider('probeExcel')]
    public function test_probe_selesai_cepat_dengan_nilai_excel(string $rumus, mixed $excel): void
    {
        $mulai = microtime(true);
        $hasil = $this->hitungMesin(['A1' => ['f', $rumus, null, null, null]]);
        $detik = microtime(true) - $mulai;

        $this->assertSame([], $hasil->galat, $rumus);
        is_float($excel)
            ? $this->assertSame($excel, (float) $hasil->nilai['S!A1'], $rumus)
            : $this->assertSame($excel, $hasil->nilai['S!A1'], $rumus);
        $this->assertLessThan(1.0, $detik, $rumus);
    }

    /**
     * Tinjau C-3 #5 — angka acuan Excel 365 (16.0, build 20326) lewat COM,
     * 15 Okt 2026 (1.192 rumus probe; ringkasan di laporan bangun). Batas ULP
     * = selisih terukur mesin, bukan toleransi longgar.
     *
     * @return array<string, array{0: string, 1: float|string, 2: int}> rumus → [nilai Excel, ULP maks]
     */
    public static function paritasStatistikExcel(): array
    {
        return [
            // T.DIST.RT x < 0 = 1 − T.DIST.RT(−x) (sebelumnya #NUM!).
            'T.DIST.RT(-1,5)' => ['=T.DIST.RT(-1,5)', 0.81839126617543867, 0],
            'T.DIST.RT(-1,1)' => ['=T.DIST.RT(-1,1)', 0.75, 0],
            'T.DIST.RT(-0.5,2)' => ['=T.DIST.RT(-0.5,2)', 0.66666666666666674, 1],
            'T.DIST.RT(-2,10)' => ['=T.DIST.RT(-2,10)', 0.96330598261462974, 1],
            'T.DIST.RT(-3,30)' => ['=T.DIST.RT(-3,30)', 0.99730501796717408, 2],
            'T.DIST.RT(-1,100)' => ['=T.DIST.RT(-1,100)', 0.84013792210793836, 0],
            'T.DIST.RT(-0,5)' => ['=T.DIST.RT(-0,5)', 0.5, 0],
            // TDIST & T.DIST.2T x < 0 tetap #NUM! — Excel juga.
            'TDIST(-1,5,1)' => ['=TDIST(-1,5,1)', '#NUM!', 0],
            'TDIST(-1,5,2)' => ['=TDIST(-1,5,2)', '#NUM!', 0],
            'T.DIST.2T(-1,5)' => ['=T.DIST.2T(-1,5)', '#NUM!', 0],
            'T.DIST.RT(-1,0.5)' => ['=T.DIST.RT(-1,0.5)', '#NUM!', 0],
            'T.DIST.RT(-1,1E6+1) batas mesin' => ['=T.DIST.RT(-1,1E6+1)', '#NUM!', 0],
            // CHIINV / CHISQ.INV.RT: akar > 100 dulu = 100 DIAM-DIAM.
            'CHIINV(0.05,150) dulu 100' => ['=CHIINV(0.05,150)', 179.58063415418053, 6],
            'CHIINV(0.5,250) dulu 100' => ['=CHIINV(0.5,250)', 249.33365030643904, 65],
            'CHIINV(0.95,1000) dulu 100' => ['=CHIINV(0.95,1000)', 927.594363020979, 4],
            'CHIINV(0.01,500) dulu 100' => ['=CHIINV(0.01,500)', 576.49281251165451, 22],
            'CHIINV(0.75,1E4) dulu 100' => ['=CHIINV(0.75,1E4)', 9904.2528435147324, 187],
            'CHISQ.INV.RT(0.05,150) dulu 100' => ['=CHISQ.INV.RT(0.05,150)', 179.58063415418053, 6],
            'CHISQ.INV.RT(0.95,1000) dulu 100' => ['=CHISQ.INV.RT(0.95,1000)', 927.594363020979, 4],
            // p dekat 1: dulu meleset 2,6·10¹¹ ULP (relatif 3·10⁻⁵).
            'CHIINV(0.999999,1)' => ['=CHIINV(0.999999,1)', 1.5707963268860579E-12, 14],
            'CHIINV(0.999999,100)' => ['=CHIINV(0.999999,100)', 46.501330715941727, 8],
            'CHIINV(0.5,1)' => ['=CHIINV(0.5,1)', 0.45493642311957289, 6],
            'CHIINV(0.05,1)' => ['=CHIINV(0.05,1)', 3.8414588206941236, 15],
            'CHIINV(1E-10,10)' => ['=CHIINV(1E-10,10)', 68.167618138617925, 0],
            'CHIINV(1,1)' => ['=CHIINV(1,1)', 0.0, 0],
            'CHIINV(0,1)' => ['=CHIINV(0,1)', '#NUM!', 0],
            'CHIINV(-0.1,1)' => ['=CHIINV(-0.1,1)', '#NUM!', 0],
            'CHIINV(0.5,0.5)' => ['=CHIINV(0.5,0.5)', '#NUM!', 0],
            'CHIINV(0.05,1E7) batas mesin' => ['=CHIINV(0.05,1E7)', '#NUM!', 0],
        ];
    }

    #[DataProvider('paritasStatistikExcel')]
    public function test_paritas_t_dist_rt_negatif_dan_chiinv_terhadap_excel(string $rumus, float|string $excel, int $ulpMaks): void
    {
        $mulai = microtime(true);
        $hasil = $this->hitungMesin(['A1' => ['f', $rumus, null, null, null]]);
        $nilai = $hasil->nilai['S!A1'];

        $this->assertSame([], $hasil->galat, $rumus);
        $this->assertLessThan(1.0, microtime(true) - $mulai, $rumus);

        if (is_string($excel)) {
            $this->assertSame($excel, $nilai, $rumus);

            return;
        }

        $this->assertTrue(is_int($nilai) || is_float($nilai), $rumus.': '.var_export($nilai, true));
        $a = unpack('q', pack('d', (float) $nilai))[1];
        $b = unpack('q', pack('d', $excel))[1];
        $this->assertLessThanOrEqual($ulpMaks, abs($a - $b), sprintf('%s: mesin %.17g, Excel %.17g', $rumus, $nilai, $excel));
    }

    /** Argumen larik (rujukan) tetap diproses per unsur, x negatif ikut jalur Excel. */
    public function test_t_dist_rt_dan_chiinv_per_unsur_rujukan(): void
    {
        $hasil = $this->hitungMesin([
            'B1' => ['n', -1.0, null, null, null],
            'B2' => ['n', 1.0, null, null, null],
            'C1' => ['n', 0.05, null, null, null],
            'C2' => ['n', 0.95, null, null, null],
            'A1' => ['f', '=INDEX(T.DIST.RT(B1:B2,5),1)', null, null, null],
            'A2' => ['f', '=INDEX(CHIINV(C1:C2,1000),1)', null, null, null],
        ]);

        $this->assertSame([], $hasil->galat);
        $this->assertEqualsWithDelta(0.81839126617543867, $hasil->nilai['S!A1'], 1e-16);
        // Excel COM: CHIINV(0.05,1000) = 1074.6794488034409 (mesin 19 ULP); dulu 100.
        $this->assertEqualsWithDelta(1074.6794488034409, $hasil->nilai['S!A2'], 1e-11);
    }

    /**
     * Jalan kedua yang dikhawatirkan tinjau: rumus ASLI yang sah, argumennya
     * dari sel input yang disunting. Rujukan sel datang ke fungsi sebagai
     * larik — pagar tetap berlaku per unsur.
     */
    public function test_nilai_sunting_ke_rumus_asli_dipagar_per_unsur(): void
    {
        $sel = [
            'B1' => ['n', 1.0E9, null, null, null],
            'B2' => ['n', 9007199254740881.0, null, null, null],
            'B3' => ['n', 2.0, null, null, null],
            'B4' => ['n', 1.0E9, null, null, null],
            'C1' => ['f', '=REPT("-",B1)', '', null, null],
            'C2' => ['f', '=FIXED(1,B1)', '', null, null],
            'C3' => ['f', '=FACT(B1)', 1.0, null, null],
            'C4' => ['f', '=COMBIN(B1,B1/2)', 1.0, null, null],
            'C5' => ['f', '=LCM(B2)', 1.0, null, null],
            'C6' => ['f', '=TDIST(1,B1,2)', 1.0, null, null],
            'C7' => ['f', '=EDATE(1,B1)', 1.0, null, null],
            'C8' => ['f', '=SUBSTITUTE(REPT("a",32767),"a",REPT("b",B3*16384))', '', null, null],
            // Larik: unsur yang meledak jadi galat, unsur lain tetap dihitung.
            'C9' => ['f', '=SUMPRODUCT(--ISERROR(FACT(B1:B4)))', 0.0, null, null],
            'C10' => ['f', '=SUMPRODUCT(--ISERROR(REPT("a",B1:B4)))', 0.0, null, null],
        ];

        $mulai = microtime(true);
        $hasil = $this->hitungMesin($sel);

        $this->assertLessThan(2.0, microtime(true) - $mulai);
        $this->assertSame([], $hasil->galat);
        $this->assertSame([
            'S!C1' => '#VALUE!', 'S!C2' => '#VALUE!', 'S!C3' => '#NUM!', 'S!C4' => '#NUM!',
            'S!C5' => 9007199254740881, 'S!C6' => '#NUM!', 'S!C7' => '#NUM!', 'S!C8' => '#VALUE!',
        ], array_intersect_key($hasil->nilai, array_flip(['S!C1', 'S!C2', 'S!C3', 'S!C4', 'S!C5', 'S!C6', 'S!C7', 'S!C8'])));
        // FACT(1e9), FACT(9e15) → #NUM!; FACT(2) dan FACT(1e9) lagi: 3 dari 4 unsur galat.
        $this->assertSame(3, (int) $hasil->nilai['S!C9']);
        $this->assertSame(3, (int) $hasil->nilai['S!C10']);
    }

    /** @return list<string> rumus wajar — hasil dengan pagar HARUS identik dengan PhpSpreadsheet polos */
    public static function rumusWajar(): array
    {
        return [
            '=FIXED(1234.5678,2)', '=FIXED(-1234.5,1,TRUE)', '=FIXED(1234.5,-2)', '=FIXED(B1)', '=FIXED(B1,B2)',
            '=DOLLAR(1234.567,2)', '=DOLLAR(-0.5)',
            '=REPT("ab",3)', '=REPT("x",0)', '=REPT(B1,2)', '=REPT("-",B2)', '=REPT(TRUE,2)',
            '=TEXT(1234.5,"0.00")', '=TEXT(46309,"dd/mm/yyyy")', '=TEXT(0.5,"hh:mm")', '=TEXT("abc","@")', '=TEXT(B1,"0.0000E+00")',
            '=SUBSTITUTE("a-b-c","-","+")', '=SUBSTITUTE("a-b-c","-","+",2)', '=SUBSTITUTE("abc","","x")', '=SUBSTITUTE(B1,"2","9")',
            '=FACT(5)', '=FACT(0)', '=FACT(170)', '=FACT(B2)', '=FACT(-1)', '=FACT("x")',
            '=COMBIN(10,3)', '=COMBIN(52,5)', '=COMBIN(170,85)', '=COMBIN(B2,2)', '=COMBIN(5,6)', '=COMBIN(5.5,5.9)',
            '=LCM(4,6,10)', '=LCM(12,18)', '=LCM(B2,4)', '=LCM(0,3)', '=LCM(2.9,3)', '=LCM(-1,2)', '=LCM("a")',
            '=GCD(12,18,24)', '=GCD(0)', '=GCD(B2,10)', '=GCD(-1,2)',
            '=TDIST(1.5,10,2)', '=TDIST(B1,30,1)', '=T.DIST.2T(2,30)', '=T.DIST.RT(2,30)', '=TDIST(1,1000000,2)', '=TDIST(-1,10,2)',
            '=CHISQ.INV(0.5,10)', '=CHISQ.INV(0.95,1000000)', '=CHISQ.INV(1.5,10)',
            '=DATE(2026,10,15)', '=DATE(2026,14,1)', '=DATE(1899,1,1)', '=DATE(0,1,1)',
            '=EDATE(46309,1)', '=EDATE(46309,-13)', '=EDATE(60,0)', '=EOMONTH(46309,0)', '=EOMONTH(46309,-1)',
            '=WORKDAY(46309,10)', '=WORKDAY(46309,-10)', '=WORKDAY(46309,5,46310)', '=NETWORKDAYS(46300,46330)', '=NETWORKDAYS(46330,46300,46310)',
            '=SUMPRODUCT(LEN(REPT("a",B1:B2)))', '=SUM(FACT(B1:B2))',
        ];
    }

    public function test_nilai_wajar_tidak_berubah_oleh_pagar(): void
    {
        $polos = new Spreadsheet;
        $ws = $polos->getActiveSheet();
        $ws->setTitle('S');
        $ws->setCellValue('B1', 12.345);
        $ws->setCellValue('B2', 7);
        $sel = ['B1' => ['n', 12.345, null, null, null], 'B2' => ['n', 7, null, null, null]];
        $harap = [];

        foreach (self::rumusWajar() as $i => $rumus) {
            $alamat = 'D'.($i + 1);
            $ws->setCellValue($alamat, $rumus);
            $harap['S!'.$alamat] = $ws->getCell($alamat)->getCalculatedValue();
            $sel[$alamat] = ['f', $rumus, null, null, null];
        }

        $polos->disconnectWorksheets();
        $hasil = $this->hitungMesin($sel);

        foreach ($harap as $kunci => $nilai) {
            $this->assertSame($nilai, $hasil->nilai[$kunci], $sel[substr($kunci, 2)][1]);
        }
    }

    public function test_tenggat_diperiksa_di_dalam_fungsi_berpagar(): void
    {
        $ss = new Spreadsheet;
        $ws = $ss->getActiveSheet();
        $ws->setCellValue('A1', '=TDIST(1,10,2)+TDIST(1,10,2)');
        FungsiExcel::pasang(46309.5, microtime(true) - 1.0);

        try {
            $ws->getCell('A1')->getCalculatedValue();
            $this->fail('Tenggat lewat harus memutus evaluasi.');
        } catch (Throwable $e) {
            $rantai = [];

            for ($x = $e; $x !== null; $x = $x->getPrevious()) {
                $rantai[] = $x::class;
            }

            $this->assertContains(GagalHitung::class, $rantai);
        } finally {
            FungsiExcel::lepas();
            $ss->disconnectWorksheets();
        }
    }

    public function test_pasang_lepas_mengembalikan_entri_asli_semua_fungsi_berpagar(): void
    {
        $asli = [];

        foreach (array_keys(BatasFungsiExcel::GANTI) as $nama) {
            $asli[$nama] = TabelFungsiPhpSpreadsheet::tabel()[$nama]['functionCall'];
            $this->assertNotSame(BatasFungsiExcel::class, $asli[$nama][0], $nama);
        }

        FungsiExcel::pasang(46309.5);

        try {
            foreach (BatasFungsiExcel::GANTI as $nama => $metode) {
                $this->assertSame([BatasFungsiExcel::class, $metode], TabelFungsiPhpSpreadsheet::tabel()[$nama]['functionCall'], $nama);
                $this->assertSame($asli[$nama], FungsiExcel::asli($nama), $nama);
            }
        } finally {
            FungsiExcel::lepas();
        }

        foreach ($asli as $nama => $panggil) {
            $this->assertSame($panggil, TabelFungsiPhpSpreadsheet::tabel()[$nama]['functionCall'], $nama);
        }
    }

    /**
     * Jalur mesin `/hitung` yang sebenarnya (mode penuh): `MesinHitung` memasang
     * `KalkulasiBerpagar` + `FungsiExcel` (termasuk pagar ini) lalu melepasnya.
     *
     * @param  array<string, array{0: string, 1: mixed, 2: mixed, 3: null, 4: null}>  $sel
     */
    private function hitungMesin(array $sel): HasilHitung
    {
        $model = new ModelHitung([['judul' => 'S', 'status' => 'visible', 'sel' => $sel]], [], '1900');

        return app(MesinHitung::class)->hitung($model, GrafDependensi::bangun($model), [], 46309.5, true, microtime(true) + 30);
    }
}
