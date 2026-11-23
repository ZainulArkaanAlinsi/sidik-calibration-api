<?php

namespace Tests\Unit;

use App\Services\Ocr\NormalisasiAngka;
use App\Services\Ocr\ValidasiSel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tingkat vonis sel (`ValidasiSel`): masukan acak tidak boleh pernah jadi usulan
 * tanpa status ragu.
 *
 * Aturan pemilik (23 Nov 2026): validasi hanya boleh MENOLAK atau MENANDAI.
 * "Diterima sebagai usulan" di sini = status kuning atau hijau dengan nilai.
 * Tes ini memberi bacaan sampah (derau, goresan garis tabel, huruf mirip digit,
 * teks yang tidak sesuai format) dengan keyakinan OCR TINGGI, supaya yang
 * menahan sel itu hanya penolakan format, bukan skor yang kebetulan rendah.
 */
class ValidasiSelTanpaKoreksiTest extends TestCase
{
    private ValidasiSel $validasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validasi = new ValidasiSel(new NormalisasiAngka);
    }

    /** @return array<string, mixed> */
    private function sel(): array
    {
        return [
            'kunci' => 'uji|1|1|pembacaan', 'tabel_id' => 'uji', 'tahap' => 'sesudah_adjustment', 'grup' => 'uji',
            'baris_ke' => 1, 'repeat_no' => 1, 'field_id' => 'pembacaan', 'titik_ukur' => 4.0, 'standard_id' => null,
            'aturan' => [
                'tipe' => 'angka', 'satuan' => 'pH', 'desimal' => 2, 'resolusi' => 0.01, 'nominal' => 4.0,
                'min' => 3.6, 'maks' => 4.4, 'rasio_min' => null, 'rasio_maks' => null,
                'maks_digit' => 9, 'izinkan_minus' => false, 'boleh_kosong' => true, 'tulisan_tangan' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function periksa(?string $teks): array
    {
        return $this->validasi->periksa($this->sel(), [
            'teks_mentah' => $teks,
            'confidence_ocr' => 0.97,
            'kotak_teks_di_dalam_sel' => true,
        ]);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function sampah(): array
    {
        return [
            'huruf O' => ['4,O1'], 'huruf S' => ['4,S0'], 'huruf l' => ['4,l0'], 'huruf B' => ['B,01'],
            'garis tegak' => ['|'], 'garis tegak di angka' => ['4,|1'], 'garis miring' => ['4/01'],
            'garis bawah' => ['____'], 'strip panjang' => ['——'], 'noise campur' => ['qqs,v'],
            'huruf semua' => ['HBs'], 'spasi di tengah' => ['4, 01'], 'ribuan' => ['1.234,56'],
            'ambigu' => ['1,413'], 'dua sel nyambung' => ['4,01-4,02'], 'koma ganda' => ['4,,01'],
            'angka plus huruf satuan' => ['4,01pH'], 'simbol' => ['$4,01'], 'kurung' => ['(4,01)'],
        ];
    }

    #[DataProvider('sampah')]
    public function test_sampah_tidak_pernah_jadi_usulan(?string $teks): void
    {
        $r = $this->periksa($teks);

        $this->assertContains($r['status'], [ValidasiSel::MERAH, ValidasiSel::KOSONG], "`{$teks}` diterima sebagai usulan ({$r['status']})");
        $this->assertNull($r['nilai'], "`{$teks}` punya nilai padahal ditolak");
        $this->assertSame($teks, $r['teks_mentah'], 'teks mentah harus tersimpan apa adanya');
        $this->assertNotContains('ada_koreksi_karakter', $r['alasan']);
    }

    public function test_sel_kosong_tetap_kosong_dan_tidak_diisi(): void
    {
        foreach ([null, '', '   ', "\u{00A0}"] as $teks) {
            $r = $this->periksa($teks);

            $this->assertSame(ValidasiSel::KOSONG, $r['status']);
            $this->assertNull($r['nilai']);
        }
    }

    /**
     * Bacaan acak seed tetap: setiap sel yang jadi usulan (kuning/hijau) wajib
     * berasal dari teks yang SUDAH berupa angka murni, dan tulisan tangan tidak
     * pernah hijau.
     */
    public function test_masukan_acak_hanya_angka_murni_yang_bisa_jadi_usulan(): void
    {
        mt_srand(20261123);
        $alfabet = array_merge(
            str_split('0123456789'), str_split('0123456789'), str_split(',.,.-'),
            str_split('OolIiSsBGgqQDZzAT|!/\\_()%$'), [' ', "\u{00A0}"],
        );

        $usulan = 0;
        $ditahan = 0;

        for ($i = 0; $i < 3000; $i++) {
            $teks = '';
            for ($j = mt_rand(0, 8); $j > 0; $j--) {
                $teks .= $alfabet[mt_rand(0, count($alfabet) - 1)];
            }

            $r = $this->periksa($teks);

            if (in_array($r['status'], [ValidasiSel::KUNING, ValidasiSel::HIJAU], true)) {
                $usulan++;
                $pangkas = preg_replace('/^[\x{00A0}\s]+|[\x{00A0}\s]+$/u', '', $teks) ?? '';
                $this->assertMatchesRegularExpression('/^[0-9]+(?:[.,][0-9]+)?$/', $pangkas, "`{$teks}` jadi usulan padahal bukan angka murni");
                $this->assertNotNull($r['nilai']);
                $this->assertNotSame(ValidasiSel::HIJAU, $r['status'], 'tulisan tangan tidak pernah hijau');
            } else {
                $ditahan++;
                if ($r['status'] === ValidasiSel::MERAH) {
                    // Sel ragu selalu membawa alasan yang bisa dibaca teknisi.
                    $this->assertNotSame([], $r['alasan'], "`{$teks}` merah tanpa alasan");
                }
            }
        }

        $this->assertGreaterThan(2, $usulan);
        $this->assertGreaterThan(1000, $ditahan);
    }
}
