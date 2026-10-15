<?php

namespace Tests\Unit;

use App\Services\Studio\Workbook\KanonikWorkbook;
use App\Services\Studio\Workbook\PembacaCsvWorkbook;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * Pembaca CSV Workbook Alat (Studio S1b): posisi sel apa adanya, nilai dua
 * bentuk (`s` teks asli, `n` float round-trip), rumus ekspor `nilai [=rumus]`,
 * urutan lembar, DATABASE/SERTIFIKAT dibaca apa adanya dengan tautan
 * (K-49-12), lebar kolom perkiraan, dan sha256 kanonik stabil.
 */
class WorkbookPembacaCsvTest extends TestCase
{
    use WorkbookUji;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanFolderUji();
        $this->folder = $this->buatAkarCsv().'/Panjang_CSV/Master_Olah_Data_Micrometer_0-25mm';
    }

    protected function tearDown(): void
    {
        $this->bersihkanFolderUji();
        parent::tearDown();
    }

    public function test_urutan_lembar_input_data_dulu_lalu_alfabet_alami(): void
    {
        $hasil = app(PembacaCsvWorkbook::class)->baca($this->folder);

        $this->assertSame(
            ['INPUT DATA', 'DATABASE', 'Drift AT', 'PERHITUNGAN', 'SERTIFIKAT'],
            array_column($hasil['lembar'], 'nama'),
        );
        $this->assertSame([1, 2, 3, 4, 5], array_column($hasil['lembar'], 'urutan'));
        $this->assertSame(array_sum(array_map(filesize(...), glob($this->folder.'/*.csv'))), $hasil['ukuran']);
    }

    public function test_sel_posisi_nilai_rumus_galat_dan_bom(): void
    {
        $hasil = app(PembacaCsvWorkbook::class)->baca($this->folder);
        $perhitungan = $this->lembar($hasil, 'PERHITUNGAN');
        $sel = json_decode($perhitungan['sel_kanonik'], true);
        $per = array_column($sel, null, 'a');

        // BOM dibuang, sel kosong tidak disimpan, kolom A kosong → B1 yang pertama.
        $this->assertSame('B1', $sel[0]['a']);
        $this->assertSame('Judul', $per['B1']['s']);
        $this->assertSame('s', $per['B1']['t']);

        // Angka: teks asli + float, tidak dibulatkan.
        $this->assertSame('0.1', $per['B2']['s']);
        $this->assertSame(0.1, $per['B2']['n']);
        $this->assertSame('n', $per['B2']['t']);
        $this->assertSame(-0.20000000000000284, $per['D4']['n']);
        $this->assertSame('-0.20000000000000284', $per['D4']['s']);

        // Rumus ekspor: nilai cache di s/n, rumus DENGAN '=' di r.
        $this->assertSame(['f', 0.2, '0.2', '=B2*2'], [$per['C2']['t'], $per['C2']['n'], $per['C2']['s'], $per['C2']['r']]);
        // Larik → {=…}.
        $this->assertSame('{=SUM(IF(B2:B3>0,1))}', $per['C3']['r']);
        $this->assertSame(3.0, $per['C3']['n']);

        $this->assertSame('e', $per['D3']['t']);
        $this->assertNull($per['D3']['n']);
        $this->assertSame('b', $per['B4']['t']);
        $this->assertSame('teks, berkoma', $per['C4']['s']);

        $this->assertSame(['b' => 3, 'k' => 3], ['b' => $per['C3']['b'], 'k' => $per['C3']['k']]);
        $this->assertNull($per['C2']['f']);
        $this->assertNull($per['C2']['g']);

        $this->assertSame(2, $perhitungan['jumlah_rumus']);
        $this->assertSame(count($sel), $perhitungan['jumlah_sel']);
        $this->assertSame(4, $perhitungan['jumlah_baris']);
        $this->assertSame(4, $perhitungan['jumlah_kolom']);
    }

    /** K-49-12: isi DATABASE/SERTIFIKAT dibaca apa adanya; lembarnya hanya bertautan. */
    public function test_lembar_database_dan_sertifikat_dibaca_dengan_tautan(): void
    {
        $hasil = app(PembacaCsvWorkbook::class)->baca($this->folder);

        foreach (['DATABASE' => [self::KANARI_DATABASE, 'customers', 'B2'], 'SERTIFIKAT' => [self::KANARI_SERTIFIKAT, 'certificates', 'C1']] as $nama => [$kanari, $tautan, $alamat]) {
            $l = $this->lembar($hasil, $nama);
            $this->assertFalse($l['dikecualikan'], $nama);
            $this->assertNull($l['alasan_dikecualikan'], $nama);
            $this->assertSame($tautan, $l['tautan_tabel'], $nama);
            $this->assertSame($kanari, array_column(json_decode($l['sel_kanonik'], true), 's', 'a')[$alamat], $nama);
            $this->assertGreaterThan(0, $l['jumlah_sel'], $nama);
        }

        $this->assertNull($this->lembar($hasil, 'PERHITUNGAN')['tautan_tabel']);
    }

    /**
     * CSV tanpa info lebar: `lebar_kolom` = panjang teks terpanjang + 1,
     * dibatasi 4..60, per baris untuk teks bergaris baru; kolom tanpa sel
     * tidak dicantumkan; `lebar_perkiraan` jujur true.
     */
    public function test_lebar_kolom_perkiraan_dari_panjang_teks(): void
    {
        $perhitungan = $this->lembar(app(PembacaCsvWorkbook::class)->baca($this->folder), 'PERHITUNGAN');

        // B: "Judul" (5) → 6; C: "teks, berkoma" (13) → 14; D: "-0.20000000000000284" (20) → 21; A kosong.
        $this->assertSame(['B' => 6.0, 'C' => 14.0, 'D' => 21.0], $perhitungan['tata_letak']['lebar_kolom']);
        $this->assertTrue($perhitungan['tata_letak']['lebar_perkiraan']);
        $this->assertNull($perhitungan['tata_letak']['lebar_kolom_bawaan']);
        $this->assertSame(array_keys(KanonikWorkbook::tataLetakKosong()), array_keys($perhitungan['tata_letak']));

        $folder = $this->folderUji.'/batas';
        $this->tulisCsv($folder, [
            'Lebar.csv' => implode("\r\n", [
                'x,'.str_repeat('w', 100).',"pendek'."\n".'baris kedua lebih panjang"',
            ])."\r\n",
        ]);
        $lebar = $this->lembar(app(PembacaCsvWorkbook::class)->baca($folder), 'Lebar')['tata_letak']['lebar_kolom'];

        // A: "x" → batas bawah 4; B: 100 huruf → batas atas 60; C: baris terpanjang (25) → 26.
        $this->assertSame(['A' => 4.0, 'B' => 60.0, 'C' => 26.0], $lebar);
    }

    public function test_sha256_kanonik_stabil_dan_peka_isi(): void
    {
        $pembaca = app(PembacaCsvWorkbook::class);
        $sha = static fn (array $h): string => KanonikWorkbook::sha256(KanonikWorkbook::workbook($h['lembar']));

        $satu = $sha($pembaca->baca($this->folder));
        $dua = $sha($pembaca->baca($this->folder));
        $this->assertSame($satu, $dua);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $satu);

        // K-49-12: isi DATABASE ikut dibaca → berubah = hash berubah.
        file_put_contents($this->folder.'/DATABASE.csv', "lain\r\n");
        $dua = $sha($pembaca->baca($this->folder));
        $this->assertNotSame($satu, $dua);

        // Satu angka berubah → hash berubah.
        file_put_contents($this->folder.'/Drift AT.csv', "a,b\r\n1,3\r\n");
        $this->assertNotSame($dua, $sha($pembaca->baca($this->folder)));
    }

    /** @param  array{lembar: list<array<string, mixed>>}  $hasil */
    private function lembar(array $hasil, string $nama): array
    {
        foreach ($hasil['lembar'] as $l) {
            if ($l['nama'] === $nama) {
                return $l;
            }
        }

        $this->fail("lembar {$nama} tidak ada");
    }
}
