<?php

namespace Tests\Unit;

use App\Services\Studio\Workbook\ProteksiLembar;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PHPUnit\Framework\TestCase;

/**
 * Aturan `ProteksiLembar` (K-49-15) di luar fixture impor: lembar dilindungi
 * tanpa satu pun sel terbuka, gaya bawaan (xf 0) yang dibuka, dan pemeriksaan
 * keanggotaan rentang. Spreadsheet di memori, tanpa berkas.
 */
class StudioProteksiLembarAturanTest extends TestCase
{
    public function test_dilindungi_tanpa_sel_terbuka_semua_terkunci(): void
    {
        $ss = new Spreadsheet;
        $s = $ss->getActiveSheet();
        $s->setCellValue('A1', 1);
        $s->getProtection()->setSheet(true);

        $hasil = (new ProteksiLembar($ss))->dariSheet($s);

        $this->assertSame(['dilindungi' => true, 'sel_tidak_terkunci' => []], $hasil);
        $rentang = ProteksiLembar::dariTataLetak($hasil);
        $this->assertTrue(ProteksiLembar::terkunci($rentang, 1, 1));
        $this->assertTrue(ProteksiLembar::terkunci($rentang, 16384, 1048576));
    }

    public function test_gaya_bawaan_terbuka_satu_sel_dikunci(): void
    {
        $ss = new Spreadsheet;
        $ss->getDefaultStyle()->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        $s = $ss->getActiveSheet();
        $s->setCellValue('A1', 1);
        $s->setCellValue('B2', 2);
        $s->getStyle('B2')->getProtection()->setLocked(Protection::PROTECTION_PROTECTED);
        $s->getProtection()->setSheet(true);

        $hasil = (new ProteksiLembar($ss))->dariSheet($s);

        $this->assertSame(['A1:XFD1', 'A2:A1048576', 'C2:XFD1048576', 'A3:XFD1048576'], $hasil['sel_tidak_terkunci']);
        $rentang = ProteksiLembar::dariTataLetak($hasil);
        $this->assertTrue(ProteksiLembar::terkunci($rentang, 2, 2));

        foreach ([[1, 1], [2, 1], [1, 2], [3, 2], [2, 3], [16384, 1048576]] as [$k, $b]) {
            $this->assertFalse(ProteksiLembar::terkunci($rentang, $k, $b), "{$k},{$b}");
        }
    }

    public function test_tidak_dilindungi_dan_baris_lama_tidak_pernah_terkunci(): void
    {
        $ss = new Spreadsheet;
        $s = $ss->getActiveSheet();
        $s->setCellValue('A1', 1);

        $this->assertSame(['dilindungi' => false, 'sel_tidak_terkunci' => []], (new ProteksiLembar($ss))->dariSheet($s));
        $this->assertNull(ProteksiLembar::dariTataLetak([]));
        $this->assertNull(ProteksiLembar::dariTataLetak(['dilindungi' => false, 'sel_tidak_terkunci' => []]));
        $this->assertFalse(ProteksiLembar::terkunci(null, 1, 1));
    }
}
