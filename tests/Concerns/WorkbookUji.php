<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Fixture Workbook Alat (Studio S1b): folder CSV & `.xlsm` terenkripsi yang
 * DIBANGKITKAN di test — tidak ada berkas workbook lab yang dipakai, dan isi
 * fixture seluruhnya sintetis (repo PUBLIC: jangan pernah menyalin isi
 * workbook atau nama pelanggan asli ke sini).
 *
 * Sheet DATABASE / SERTIFIKAT memuat kanari. Sejak K-49-12 (13 Okt 2026)
 * kanari itu WAJIB tersimpan dan terbaca oleh lab pemiliknya, dan WAJIB tidak
 * pernah sampai ke lab lain (404).
 */
trait WorkbookUji
{
    protected const KANARI_DATABASE = 'KANARI-DATABASE-7f3a1c';

    protected const KANARI_SERTIFIKAT = 'KANARI-SERTIFIKAT-91bc4e';

    protected const SANDI_UJI = 'sandi-uji-Workbook-#42';

    /** Folder sementara milik test ini (dihapus di tearDown). */
    protected string $folderUji = '';

    protected function siapkanFolderUji(): string
    {
        $this->folderUji = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sidik-uji-wb-'.bin2hex(random_bytes(6));
        mkdir($this->folderUji, 0700, true);

        // Temp dekripsi privat ikut diarahkan ke folder test, supaya test
        // bisa menghitung isinya persis dan tidak menyentuh storage kerja.
        config(['studio.temp_workbook' => $this->folderUji.DIRECTORY_SEPARATOR.'tmp-workbook']);

        // Workbook di sini SINTETIS (nama "Micrometer" tanpa struktur master
        // Micrometer), jadi peta sel repo diganti folder kosong: test ini
        // menguji mekanik impor, bukan struktur master. Validasi struktur &
        // ekstraksi dengan peta sungguhan dikunci StudioPetaSel*Test, yang
        // TIDAK memakai siapkanFolderUji untuk petanya.
        config(['studio.peta_sel.direktori' => $this->folderUji.DIRECTORY_SEPARATOR.'peta-sel-kosong']);

        return $this->folderUji;
    }

    /**
     * Nilai `--oleh` untuk `studio:impor-workbook` / `studio:rapikan-workbook`
     * (wajib kecuali `--kering`, tinjau C-3 #2): email admin lab di organisasi
     * itu — yang sudah ada, atau dibuat. Tanpa argumen = satu-satunya organisasi.
     */
    protected function olehLab(?Organization $org = null): string
    {
        $org ??= Organization::query()->sole();
        $admin = User::query()->where('organization_id', $org->id)->where('role', User::ROLE_ADMIN)->orderBy('id')->first()
            ?? User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_AKTIF]);

        return (string) $admin->email;
    }

    protected function bersihkanFolderUji(): void
    {
        if ($this->folderUji !== '' && is_dir($this->folderUji)) {
            (new Filesystem)->deleteDirectory($this->folderUji);
        }
    }

    /**
     * Isi akar CSV: dua workbook (Micrometer → profil tunggal, Fixed Volumetric
     * → keluarga), masing-masing dengan DATABASE & SERTIFIKAT berkanari.
     */
    protected function buatAkarCsv(): string
    {
        $akar = $this->folderUji.DIRECTORY_SEPARATOR.'alat';

        $this->tulisCsv($akar.'/Panjang_CSV/Master_Olah_Data_Micrometer_0-25mm', [
            // BOM UTF-8 + rumus ekspor `nilai [=rumus]` + larik + galat + kosong.
            'PERHITUNGAN.csv' => "\xEF\xBB\xBF".implode("\r\n", [
                ',Judul,,',
                ',0.1,"0.2 [=B2*2]",',
                ',,"3 [ARRAY:=SUM(IF(B2:B3>0,1))]",#REF!',
                ',TRUE,"teks, berkoma",-0.20000000000000284',
            ])."\r\n",
            'INPUT DATA.csv' => "Nama Alat,:,Micrometer\r\nResolusi,:,0.001\r\n",
            'DATABASE.csv' => "No,Pelanggan\r\n1,".self::KANARI_DATABASE."\r\n",
            'SERTIFIKAT.csv' => 'Owner,:,'.self::KANARI_SERTIFIKAT."\r\n",
            'Drift AT.csv' => "a,b\r\n1,2\r\n",
        ]);

        $this->tulisCsv($akar.'/Volumetric_Glassware_2026/Fixed_Volumetric_Glassware_2026', [
            'INPUT_DATA.csv' => "Volume,:,100\r\n",
            'DATABASE.csv' => 'x,'.self::KANARI_DATABASE."\r\n",
            'SERTIFIKAT (2).csv' => 'y,'.self::KANARI_SERTIFIKAT."\r\n",
            'SERTIFIKAT KALIBRATOR.csv' => "Nominal,10\r\n",
        ]);

        return $akar;
    }

    /** @param  array<string, string>  $berkas */
    protected function tulisCsv(string $folder, array $berkas): void
    {
        if (! is_dir($folder)) {
            mkdir($folder, 0700, true);
        }

        foreach ($berkas as $nama => $isi) {
            file_put_contents($folder.DIRECTORY_SEPARATOR.$nama, $isi);
        }
    }

    /**
     * `.xlsm` terenkripsi Agile (AES-256/SHA-512) berisi: INPUT DATA (nilai,
     * rumus, gaya tebal/miring/isi/teks/rata/garis/bungkus/vertikal/ukuran/
     * font, merge, lebar, lebar & tinggi bawaan, baris/kolom tersembunyi,
     * freeze yang disimpan dalam keadaan digulir, garis kisi mati, zoom,
     * komentar, sel kosong ber-isi & sel kosong yang cuma tebal),
     * DATABASE & SERTIFIKAT berkanari, satu sheet hidden, satu veryHidden.
     *
     * Putaran kunci 1.000 (bukan 100.000 profil lab) supaya test cepat; jalur
     * dekripsinya sama. `$sandi = null` → ZIP polos tanpa enkripsi.
     */
    protected function buatXlsm(string $nama = 'Master Olah Data Micrometer 0-25mm.xlsm', int $spin = 1000, ?string $sandi = self::SANDI_UJI): string
    {
        $ss = new Spreadsheet;
        $a = $ss->getActiveSheet();
        $a->setTitle('INPUT DATA');
        $a->setCellValue('A1', 'Judul');
        $a->getStyle('A1')->getFont()->setBold(true);
        $a->getStyle('A1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFFFF00');
        $a->setCellValue('B2', 1.5);
        $a->getStyle('B2')->getFont()->setItalic(true)->getColor()->setARGB('FFC00000');
        $a->getStyle('B2')->getAlignment()->setHorizontal('center');
        $a->setCellValue('C2', '=B2*2');
        $a->getStyle('C2')->getNumberFormat()->setFormatCode('0.000');
        $a->setCellValue('D2', '=SUM(B2:C2)');
        $a->mergeCells('A5:C5');
        $a->getColumnDimension('B')->setWidth(20);
        $a->getRowDimension(3)->setRowHeight(30);
        $a->getColumnDimension('E')->setVisible(false);
        $a->getRowDimension(4)->setVisible(false);
        // Beku kolom A + baris 1-2, tetapi master disimpan sambil digulir ke D10
        // (pane `topLeftCell`): freeze wajib tetap B3 (dari xSplit/ySplit).
        $a->freezePane('B3', 'D10');
        $a->getStyle('F6')->getBorders()->getAllBorders()->setBorderStyle('thin');
        $a->getComment('A1')->getText()->createTextRun('catatan');
        // Format kanonik 3 (tahap A).
        $a->getDefaultColumnDimension()->setWidth(12.5);
        $a->getDefaultRowDimension()->setRowHeight(18);
        $a->setShowGridlines(false);
        $a->getSheetView()->setZoomScale(85);
        $a->setCellValue('A7', "baris satu\nbaris dua");
        $a->getStyle('A7')->getAlignment()->setWrapText(true)->setVertical('top');
        $a->setCellValue('B7', 'Kecil');
        $a->getStyle('B7')->getFont()->setSize(8)->setName('Arial');
        $a->getStyle('G7')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFDDEBF7');
        $a->getStyle('H7')->getFont()->setBold(true);

        $d = $ss->createSheet();
        $d->setTitle('DATABASE');
        $d->setCellValue('A1', self::KANARI_DATABASE);

        $h = $ss->createSheet();
        $h->setTitle('Bantu');
        $h->setSheetState('hidden');
        $h->setCellValue('A1', 5);

        $v = $ss->createSheet();
        $v->setTitle('Rahasia Makro');
        $v->setSheetState('veryHidden');
        $v->setCellValue('A1', 'x');

        $s = $ss->createSheet();
        $s->setTitle('SERTIFIKAT');
        $s->setCellValue('A1', self::KANARI_SERTIFIKAT);

        $jalur = $this->folderUji.DIRECTORY_SEPARATOR.$nama;
        $w = new Xlsx($ss);

        if ($sandi !== null) {
            $w->setEncryptionPassword($sandi);
            $w->setEncryptionProfile(256, 'SHA512', $spin);
        }

        $w->save($jalur);
        $ss->disconnectWorksheets();

        return $jalur;
    }

    /**
     * Workbook `.xlsx` SINTETIS untuk Lembar Hitung (tahap C): input, rumus
     * lintas lembar, VLOOKUP ke lembar tersembunyi, nama terdefinisi, IF
     * bersarang, STDEV/AVERAGE/TINV, NOW, conditional formatting cellIs, dan
     * tiga gambar (PNG, JPEG, GIF → "lain"). Nilai cache = hasil PhpSpreadsheet
     * saat menulis (bukan Excel) — cukup untuk kontrak & paritas kotor-vs-penuh;
     * angka acuan Excel ada di test unit `StudioFungsiExcelTest`.
     *
     * Peta sel `INPUT DATA`: B2 kode kelas (1..4) → D2 VLOOKUP kelas; B3 suhu;
     * B4 RH (nama `Faktor`); C2:C11 bacaan; D3 STDEV; D4 AVERAGE; D5 TINV(0.05,D7);
     * D6 IF(D2="F1","OK","TIDAK OK") ber-CF; D7 = 9+B2; D8 NOW(); D9 = D8+1;
     * D10 = SUM(Data!A1:A3)*B3; D11 = Faktor*2; D12 IF bersarang.
     */
    protected function buatXlsxHitung(string $nama = 'Lembar Hitung Uji.xlsx', ?string $sandi = null): string
    {
        $ss = new Spreadsheet;
        $a = $ss->getActiveSheet();
        $a->setTitle('INPUT DATA');
        $a->setCellValue('A1', 'Judul uji');
        $a->setCellValue('B2', 2);
        $a->setCellValue('B3', 20.4);
        $a->setCellValue('B4', 58);

        foreach ([1000.00021, 1000.00018, 1000.00025, 1000.0002, 1000.00019, 1000.00023, 1000.00017, 1000.00022, 1000.00024, 1000.0002] as $i => $x) {
            $a->setCellValue('C'.($i + 2), $x);
        }

        $a->setCellValue('D2', '=VLOOKUP(B2,Kelas!$A$1:$B$4,2,0)');
        $a->setCellValue('D3', '=STDEV(C2:C11)');
        $a->setCellValue('D4', '=AVERAGE(C2:C11)');
        $a->setCellValue('D5', '=TINV(0.05,D7)');
        $a->setCellValue('D6', '=IF(D2="F1","OK","TIDAK OK")');
        $a->setCellValue('D7', '=9+B2');
        $a->setCellValue('D8', '=NOW()');
        $a->setCellValue('D9', '=D8+1');
        $a->setCellValue('D10', '=SUM(Data!A1:A3)*B3');
        $a->setCellValue('D11', '=Faktor*2');
        $a->setCellValue('D12', '=IF(B2=1,"a",IF(B2=2,IF(B3>20,"b","c"),IF(B2=3,"d","e")))');
        $a->getStyle('D4')->getNumberFormat()->setFormatCode('0.000');

        $ok = new Conditional;
        $ok->setConditionType(Conditional::CONDITION_CELLIS)
            ->setOperatorType(Conditional::OPERATOR_EQUAL)
            ->addCondition('"OK"');
        $ok->getStyle()->getFill()->setFillType('solid')->getEndColor()->setARGB('FF92D050');
        $ok->getStyle()->getFill()->getStartColor()->setARGB('FF92D050');
        $tidak = new Conditional;
        $tidak->setConditionType(Conditional::CONDITION_CELLIS)
            ->setOperatorType(Conditional::OPERATOR_EQUAL)
            ->addCondition('"TIDAK OK"');
        $tidak->getStyle()->getFill()->setFillType('solid')->getEndColor()->setARGB('FFFF0000');
        $tidak->getStyle()->getFill()->getStartColor()->setARGB('FFFF0000');
        $tidak->getStyle()->getFont()->setBold(true);
        $a->getStyle('D6')->setConditionalStyles([$ok, $tidak]);

        $k = $ss->createSheet();
        $k->setTitle('Kelas');
        $k->setSheetState('hidden');

        foreach ([[1, 'E2'], [2, 'F2'], [3, 'F1'], [4, 'M1']] as $i => [$kode, $kelas]) {
            $k->setCellValue('A'.($i + 1), $kode);
            $k->setCellValue('B'.($i + 1), $kelas);
        }

        $d = $ss->createSheet();
        $d->setTitle('Data');
        $d->setSheetState('veryHidden');
        $d->setCellValue('A1', 1.5);
        $d->setCellValue('A2', 2.25);
        $d->setCellValue('A3', 3.125);

        $ss->addNamedRange(new NamedRange('Faktor', $a, '$B$4'));

        foreach ([['png', 'E2', $a], ['jpeg', 'F5', $a], ['gif', 'B2', $d]] as $i => [$jenis, $sel, $ws]) {
            $berkas = $this->folderUji.DIRECTORY_SEPARATOR.'gambar-'.$i.'.'.($jenis === 'jpeg' ? 'jpg' : $jenis);
            $img = imagecreatetruecolor(8 + $i, 4 + $i);
            imagefill($img, 0, 0, (int) imagecolorallocate($img, 40 * $i, 120, 200));
            match ($jenis) {
                'png' => imagepng($img, $berkas),
                'jpeg' => imagejpeg($img, $berkas),
                default => imagegif($img, $berkas),
            };
            $gambar = new Drawing;
            $gambar->setPath($berkas);
            $gambar->setCoordinates($sel);
            $gambar->setOffsetX(3 + $i);
            $gambar->setOffsetY(4 + $i);
            $gambar->setWorksheet($ws);
        }

        $jalur = $this->folderUji.DIRECTORY_SEPARATOR.$nama;
        $w = new Xlsx($ss);

        if ($sandi !== null) {
            $w->setEncryptionPassword($sandi);
            $w->setEncryptionProfile(256, 'SHA512', 1000);
        }

        $w->save($jalur);
        $ss->disconnectWorksheets();

        return $jalur;
    }

    /**
     * `.xlsx` SINTETIS tanpa sandi untuk proteksi lembar (K-49-15).
     *
     * `INPUT DATA` DILINDUNGI (`sheetProtection sheet="1"`):
     *  - A1 judul, C2 `=B2*2`, C3 `=B3+1` → terkunci (bawaan Excel);
     *  - B2 = 5, B3 = 7 → tidak terkunci (gaya sel);
     *  - B5:C6 sel kosong bergaya tidak terkunci;
     *  - kolom E tidak terkunci lewat GAYA KOLOM (`<col style>`), kecuali E4
     *    yang dikunci di selnya sendiri;
     *  - baris 8 tidak terkunci lewat GAYA BARIS (`<row s customFormat>`),
     *    kecuali F8 yang dikunci di selnya sendiri.
     *
     * `Bebas` TIDAK dilindungi: A1 = 3, B1 `=A1*2`.
     */
    protected function buatXlsxDilindungi(string $nama = 'Lembar Dilindungi Uji.xlsx'): string
    {
        $buka = Protection::PROTECTION_UNPROTECTED;
        $kunci = Protection::PROTECTION_PROTECTED;

        $ss = new Spreadsheet;
        $a = $ss->getActiveSheet();
        $a->setTitle('INPUT DATA');
        $a->setCellValue('A1', 'Judul uji');
        $a->setCellValue('B2', 5);
        $a->setCellValue('B3', 7);
        $a->setCellValue('C2', '=B2*2');
        $a->setCellValue('C3', '=B3+1');
        $a->getStyle('B2:B3')->getProtection()->setLocked($buka);
        $a->getStyle('B5:C6')->getProtection()->setLocked($buka);
        $a->getStyle('E:E')->getProtection()->setLocked($buka);
        $a->setCellValue('E4', 1);
        $a->getStyle('E4')->getProtection()->setLocked($kunci);
        $a->getStyle('8:8')->getProtection()->setLocked($buka);
        $a->setCellValue('F8', 'x');
        $a->getStyle('F8')->getProtection()->setLocked($kunci);
        $a->getProtection()->setSheet(true);

        $b = $ss->createSheet();
        $b->setTitle('Bebas');
        $b->setCellValue('A1', 3);
        $b->setCellValue('B1', '=A1*2');

        $jalur = $this->folderUji.DIRECTORY_SEPARATOR.$nama;
        (new Xlsx($ss))->save($jalur);
        $ss->disconnectWorksheets();

        return $jalur;
    }

    /** Isi akar temp privat (rekursif), tanpa `.`/`..`. @return list<string> */
    protected function isiTemp(): array
    {
        $akar = (string) config('studio.temp_workbook');

        if (! is_dir($akar)) {
            return [];
        }

        $hasil = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($akar, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iter as $info) {
            $hasil[] = $info->getPathname();
        }

        return $hasil;
    }
}
