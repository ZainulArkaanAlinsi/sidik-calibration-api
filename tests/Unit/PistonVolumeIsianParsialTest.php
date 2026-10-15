<?php

namespace Tests\Unit;

use App\Services\Calibration\PistonVolumeCalculator as K;
use App\Support\PistonVolumeMentah;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Isian tak penuh PISTON VOLUME (keputusan pemilik 14 Okt 2026): Fixed boleh
 * 2..10 pemindahan, Graduated tetap tepat sebelas kumulatif karena cabang
 * tara-ulangnya membaca M4/M7/M10 di posisi tetap.
 */
class PistonVolumeIsianParsialTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function sesi(string $kel): array
    {
        $isi = json_decode((string) file_get_contents(database_path('data/sesi-master-piston-volume.json')), true);

        return $isi['sesi'][$kel]['masukan'];
    }

    public function test_graduated_kurang_dari_sebelas_kumulatif_tetap_ditolak(): void
    {
        $m = self::sesi('graduated');
        $m['titik'][0]['kumulatif'] = array_slice($m['titik'][0]['kumulatif'], 0, 7);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TEPAT 11');

        (new K)->hitungSesi($m);
    }

    public function test_fixed_enam_pemindahan_rata_dan_stdev_dari_yang_ada(): void
    {
        $m = self::sesi('fixed');
        $kum = array_slice($m['titik'][0]['kumulatif'], 0, 7);
        $m['titik'][0]['kumulatif'] = $kum;

        $p = (new K)->hitungSesi($m)['per_titik'][0];

        $this->assertCount(6, $p['m']);
        $this->assertEqualsWithDelta(($kum[6] - $kum[0]) / 6, $p['m_rata'], 1e-12);
    }

    /**
     * Kotak kosong di TENGAH (M0..M4, M6..M10): dirapatkan, M6 − M4 terhitung
     * satu pemindahan padahal dua. Wajib ditolak dengan menyebut kotaknya.
     */
    public function test_celah_di_tengah_ditolak(): void
    {
        $m = self::sesi('fixed');
        unset($m['titik'][0]['kumulatif'][5]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('kotak massa kumulatif M5 kosong padahal M10 terisi');

        (new K)->hitungSesi($m);
    }

    /** M0 kosong: tanpa penolakan, M1 terbaca sebagai massa awal (tara). */
    public function test_m0_kosong_ditolak(): void
    {
        $m = self::sesi('fixed');
        unset($m['titik'][0]['kumulatif'][0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('M1 terbaca sebagai massa awal');

        (new K)->hitungSesi($m);
    }

    /**
     * Jalur baca ulang dari `raw_measurements` (validator & hitung-ulang) tidak
     * boleh merapatkan celah — kalau dirapatkan, penjaga di atas tidak pernah
     * melihatnya.
     */
    public function test_baris_mentah_mempertahankan_celah(): void
    {
        $baris = collect([0, 1, 2, 3, 4, 6, 7, 8, 9, 10])->map(static fn (int $i): object => (object) [
            'peran_sensor' => PistonVolumeMentah::PERAN_KUMULATIF,
            'sensor_ke' => $i,
            'pembacaan' => 10.0 * $i,
        ])->push((object) ['peran_sensor' => PistonVolumeMentah::PERAN_SUHU_AIR, 'sensor_ke' => 1, 'pembacaan' => 24.0])
            ->push((object) ['peran_sensor' => PistonVolumeMentah::PERAN_SUHU_AIR, 'sensor_ke' => 2, 'pembacaan' => 24.2]);

        $konteks = PistonVolumeMentah::dari($baris);

        $this->assertSame([0, 1, 2, 3, 4, 6, 7, 8, 9, 10], array_keys($konteks[PistonVolumeMentah::KONTEKS_KUMULATIF]));
        $this->assertSame([24.0, 24.2], $konteks[PistonVolumeMentah::KONTEKS_SUHU_AIR]);
        $this->assertStringContainsString('M5', (string) K::celahKumulatif($konteks[PistonVolumeMentah::KONTEKS_KUMULATIF], '1'));
        $this->assertNull(K::celahKumulatif([0 => 1.0, 1 => 2.0, 2 => 3.0], '1'));
    }

    public function test_batas_jumlah_kumulatif(): void
    {
        $this->assertFalse(K::jumlahKumulatifDiterima(K::FIXED, 2));
        $this->assertTrue(K::jumlahKumulatifDiterima(K::FIXED, 3));
        $this->assertTrue(K::jumlahKumulatifDiterima(K::FIXED, 11));
        $this->assertFalse(K::jumlahKumulatifDiterima(K::FIXED, 12));
        $this->assertFalse(K::jumlahKumulatifDiterima(K::GRADUATED, 10));
        $this->assertTrue(K::jumlahKumulatifDiterima(K::GRADUATED, 11));
    }
}
