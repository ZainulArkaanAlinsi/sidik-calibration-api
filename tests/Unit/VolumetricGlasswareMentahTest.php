<?php

namespace Tests\Unit;

use App\Support\VolumetricGlasswareMentah as M;
use PHPUnit\Framework\TestCase;

class VolumetricGlasswareMentahTest extends TestCase
{
    private function baris(string $peran, int $ke, float $nilai): object
    {
        return (object) ['peran_sensor' => $peran, 'sensor_ke' => $ke, 'pembacaan_ke' => $ke, 'pembacaan' => $nilai];
    }

    /**
     * Ketiga deret tersusun ulang urut `sensor_ke`, dari kolom `pembacaan`.
     *
     * Diberikan TERACAK supaya urutan simpan tidak kebetulan menyelamatkan.
     */
    public function test_tiga_deret_tersusun_ulang_urut_ulangan(): void
    {
        $hasil = M::dari(collect([
            $this->baris(M::PERAN_ISI, 2, 70.7965),
            $this->baris(M::PERAN_KOSONG, 3, 60.243),
            $this->baris(M::PERAN_SUHU, 1, 25.4),
            $this->baris(M::PERAN_KOSONG, 1, 60.234),
            $this->baris(M::PERAN_ISI, 1, 70.7791),
            $this->baris(M::PERAN_SUHU, 3, 25.4),
            $this->baris(M::PERAN_KOSONG, 2, 60.24),
            $this->baris(M::PERAN_ISI, 3, 70.8854),
            $this->baris(M::PERAN_SUHU, 2, 25.3),
        ]));

        $this->assertSame([60.234, 60.24, 60.243], $hasil[M::KONTEKS_KOSONG]);
        $this->assertSame([70.7791, 70.7965, 70.8854], $hasil[M::KONTEKS_ISI]);
        $this->assertSame([25.4, 25.3, 25.4], $hasil[M::KONTEKS_SUHU]);
    }

    public function test_bukan_sesi_volumetric_memulangkan_kosong(): void
    {
        $this->assertSame([], M::dari(collect([$this->baris('hydro_massa', 1, 10.0)])));
    }

    /** Kelas kosong TIDAK diberi bawaan — harus berhenti, bukan jadi Class B. */
    public function test_blok_sesi_tidak_mengarang_kelas(): void
    {
        $this->assertNull(M::blokSesi(null));
        $this->assertNull(M::blokSesi(['lain' => []]));

        $blok = M::blokSesi([M::KUNCI_SESI => ['kelas' => ' b ', 'toleransi_ml' => '0.008', 'resolusi_ml' => 1, 'kapasitas_ml' => '100']]);
        $this->assertSame('B', $blok['kelas']);
        $this->assertSame(0.008, $blok['toleransi_ml']);
        $this->assertSame(1.0, $blok['resolusi_ml']);
        $this->assertSame(100.0, $blok['kapasitas_ml']);
        $this->assertNull($blok['neraca']);

        $this->assertNull(M::blokSesi([M::KUNCI_SESI => ['kelas' => '']])['kelas']);
    }
}
