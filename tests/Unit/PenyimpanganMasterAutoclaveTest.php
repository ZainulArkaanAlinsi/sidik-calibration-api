<?php

namespace Tests\Unit;

use App\Services\Calibration\AutoclaveCalculator;
use App\Support\PenyimpanganMaster;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Concerns\MenjagaAngkaSebelumJejak;

/**
 * PK-9 / PK5-4: catatan `penyimpangan_master.autoclave.titik_waktu_ke5_ikut` di `hasil_autoclave`.
 *
 * Master membaca titik waktu D,F,H,H,I (ke-5 terbuang, ke-3 terhitung dua kali); sistem merata-ratakan
 * semua titik yang terisi (keputusan pemilik). Catatan hanya ada bila rata-rata master benar-benar
 * beda; angka "versi master" di dalamnya = angka cetak master di `docs/pertanyaan-lab-adu-cetak-b.md`
 * PK5-4 (Terukur 121,39 / 121,47, Keseragaman 0,466), dan seluruh angka hasil hitung identik dengan
 * keluaran SEBELUM catatan ada (golden `autoclave.json`).
 */
class PenyimpanganMasterAutoclaveTest extends TestCase
{
    use MenjagaAngkaSebelumJejak;

    /** @return array<string, mixed> masukan sesi contoh master 0281-CAL-624 (milik AutoclaveCalculatorTest) */
    private function masukan(): array
    {
        return (new ReflectionMethod(AutoclaveCalculatorTest::class, 'inputContoh'))
            ->invoke(new AutoclaveCalculatorTest('x'));
    }

    /** @return array<string, mixed> */
    private function empatTitikWaktu(): array
    {
        $in = $this->masukan();
        $in['suhu']['disk'] = array_map(static fn (array $d): array => array_slice($d, 0, 4), $in['suhu']['disk']);
        $in['suhu']['indikator'] = array_slice($in['suhu']['indikator'], 0, 4);

        return $in;
    }

    /** @return array<string, mixed> */
    private function tanpaSuhu(): array
    {
        $in = $this->masukan();
        $in['suhu'] = [];

        return $in;
    }

    /** @return array<string, mixed> */
    private function titikKetigaKosong(): array
    {
        $in = $this->masukan();
        $in['suhu']['disk'] = array_map(static function (array $d): array {
            $d[2] = null;

            return $d;
        }, $in['suhu']['disk']);

        return $in;
    }

    /**
     * @param  array<string, mixed>  $hasil
     * @return list<array<string, mixed>>
     */
    private function catatan(array $hasil): array
    {
        return PenyimpanganMaster::dari($hasil['suhu']['penyimpangan_master'] ?? []);
    }

    public function test_angka_hasil_hitung_identik_dengan_sebelum_catatan_ada(): void
    {
        $sekarang = [];

        foreach ([
            'contoh_lima_titik_waktu' => $this->masukan(),
            'empat_titik_waktu' => $this->empatTitikWaktu(),
            'tanpa_suhu' => $this->tanpaSuhu(),
            'titik_ketiga_kosong' => $this->titikKetigaKosong(),
        ] as $nama => $masukan) {
            $hasil = (new AutoclaveCalculator)->hitung($masukan);
            unset($hasil['suhu']['penyimpangan_master']);
            $sekarang[$nama] = $hasil;
        }

        $this->cocokkanGoldenAngka('autoclave', $sekarang);
    }

    public function test_sesi_contoh_mencatat_selisih_dengan_angka_cetak_master(): void
    {
        $hasil = (new AutoclaveCalculator)->hitung($this->masukan());
        $catatan = $this->catatan($hasil);

        $this->assertCount(1, $catatan);
        $c = $catatan[0];
        $this->assertSame('penyimpangan_master.autoclave.titik_waktu_ke5_ikut', $c['sumber']);
        $a = $c['penyimpangan']['angka'];

        // PK5-4: Terukur disk 1 121,39 -> 121,40; disk 2 121,47 -> 121,46; Keseragaman 0,466 -> 0,464.
        $this->assertEqualsWithDelta(121.39, $a['disk_master'][0]['terukur'], 0.0051);
        $this->assertEqualsWithDelta(121.40, $a['disk_sistem'][0]['terukur'], 0.0051);
        $this->assertEqualsWithDelta(121.47, $a['disk_master'][1]['terukur'], 0.0051);
        $this->assertEqualsWithDelta(121.46, $a['disk_sistem'][1]['terukur'], 0.0051);
        $this->assertEqualsWithDelta(0.466, $a['keseragaman_master'], 1e-9 + 1e-6);
        $this->assertEqualsWithDelta(0.464, $a['keseragaman_sistem'], 1e-6);
        $this->assertEqualsWithDelta($hasil['suhu']['keseragaman'], $a['keseragaman_sistem'], 1e-12);

        $this->assertStringContainsString('PERHITUNGAN FC!D23:I25', $c['keterangan']);
        $this->assertStringContainsString('PK5-4', $c['keterangan']);
        $this->assertStringContainsString('Master:', $c['keterangan']);
        $this->assertStringContainsString('Sistem:', $c['keterangan']);
        $this->assertStringContainsString('Keseragaman 0,466', $c['keterangan']);
        $this->assertStringContainsString('Keseragaman 0,464', $c['keterangan']);
    }

    public function test_tidak_ada_catatan_bila_titik_ke5_tidak_ada_atau_master_tidak_terhitung(): void
    {
        foreach ([
            'empat titik waktu' => $this->empatTitikWaktu(),
            'titik ketiga kosong (master tak bisa menghitung D,F,H,H,I)' => $this->titikKetigaKosong(),
        ] as $nama => $masukan) {
            $hasil = (new AutoclaveCalculator)->hitung($masukan);
            $this->assertArrayNotHasKey('penyimpangan_master', $hasil['suhu'], $nama);
        }
    }

    public function test_sesi_tekanan_saja_tidak_punya_blok_suhu_dan_tidak_error(): void
    {
        $hasil = (new AutoclaveCalculator)->hitung($this->tanpaSuhu());

        $this->assertNull($hasil['suhu']);
        $this->assertNotNull($hasil['tekanan']);
    }
}
