<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Support\BandingkanLembar;
use Tests\TestCase;

/**
 * BUG-6 uji E2E-QA 18 Nov 2026: `GET /calibrations/{id}/bandingkan` menandai
 * sel `berubah: true` padahal asli == sekarang.
 *
 * `BandingkanLembar` membandingkan dengan `!==`. `input_asli` kembali dari
 * kolom JSON; TiDB menormalkan `6.0` jadi `6` (`select cast('[6.0]' as json)`
 * → `[6]`, MySQL 8 → `[6.0]`), sedangkan nilai "sekarang" datang dari kolom
 * DECIMAL sebagai float. `6 !== 6.0` → 8 dari 9 "perubahan" adalah sel palsu,
 * dan satu koreksi sungguhan tenggelam di antaranya — di layar yang dipakai
 * pemeriksa untuk mengadu data asli lawan koreksi (ISO/IEC 17025 §7.5.2).
 *
 * Yang dijaga: angka dibandingkan sebagai ANGKA (6 == 6.0 == "6.00000000"),
 * non-angka tetap ketat, dan sel yang benar-benar berubah tetap terdeteksi.
 */
class BandingkanLembarAngkaTest extends TestCase
{
    /**
     * @param  list<int|float|string|null>  $nilai
     * @return array{jenis: string, pembacaan: list<array<string, mixed>>}
     */
    private function lembar(array $nilai): array
    {
        $baris = [];
        foreach ($nilai as $i => $v) {
            $baris[] = [
                'titik_ke' => 1, 'pembacaan_ke' => $i + 1, 'sensor_ke' => null,
                'peran_sensor' => null, 'tahap' => null, 'titik_ukur' => 50.0,
                'pembacaan' => $v, 'satuan' => 'mm',
            ];
        }

        return ['jenis' => 'pembacaan', 'pembacaan' => $baris];
    }

    /** @return list<array<string, mixed>> */
    private function yangBerubah(array $hasil): array
    {
        return array_values(array_filter($hasil['sel'], static fn (array $s): bool => $s['berubah']));
    }

    public function test_int_lawan_float_yang_sama_bukan_perubahan(): void
    {
        $hasil = BandingkanLembar::susun(
            $this->lembar([6, 50, 25]),
            $this->lembar([6.0, 50.0, 25.0]),
            [],
        );

        $this->assertSame(3, $hasil['total_sel']);
        $this->assertSame(0, $hasil['jumlah_berubah']);
        $this->assertSame([], $this->yangBerubah($hasil));
    }

    public function test_float_lawan_int_dan_string_desimal_yang_sama_juga_bukan_perubahan(): void
    {
        $hasil = BandingkanLembar::susun(
            $this->lembar([6.0, 25.001, 7]),
            // MySQL mengembalikan DECIMAL(20,8) sebagai string.
            $this->lembar([6, '25.00100000', '7.00000000']),
            [],
        );

        $this->assertSame(0, $hasil['jumlah_berubah']);
    }

    public function test_sel_yang_benar_benar_berubah_tetap_terdeteksi_di_antara_yang_palsu(): void
    {
        // Sembilan sel, delapan hanya beda int/float — persis laporan QA.
        $asli = $this->lembar([6, 6, 6, 6, 6, 6, 6, 6, 25.001]);
        $sekarang = $this->lembar([6.0, 6.0, 6.0, 6.0, 6.0, 6.0, 6.0, 6.0, 25.004]);

        $hasil = BandingkanLembar::susun($asli, $sekarang, []);

        $this->assertSame(9, $hasil['total_sel']);
        $this->assertSame(1, $hasil['jumlah_berubah'], 'hanya satu sel yang benar-benar berubah');

        $berubah = $this->yangBerubah($hasil);
        $this->assertCount(1, $berubah);
        $this->assertEquals(25.001, $berubah[0]['asli']);
        $this->assertEquals(25.004, $berubah[0]['sekarang']);
        $this->assertStringEndsWith('pembacaan_9', $berubah[0]['kunci']);
    }

    public function test_perubahan_sangat_kecil_tetap_terdeteksi(): void
    {
        $hasil = BandingkanLembar::susun(
            $this->lembar([25.001]),
            $this->lembar([25.00100001]),
            [],
        );

        $this->assertSame(1, $hasil['jumlah_berubah'], 'perbandingan angka tidak boleh memakai toleransi longgar');
    }

    public function test_bukan_angka_tetap_dibandingkan_ketat(): void
    {
        $kasus = [
            'teks sama' => ['abc', 'abc', false],
            'teks beda' => ['abc', 'abd', true],
            'null lawan nol' => [null, 0, true],
            'true lawan satu' => [true, 1, true],
            'teks kosong lawan nol' => ['', 0, true],
            'null sama null' => [null, null, false],
            'teks angka lawan teks non-angka' => ['6', 'enam', true],
        ];

        foreach ($kasus as $nama => [$a, $s, $harusBeda]) {
            $hasil = BandingkanLembar::susun($this->lembar([$a]), $this->lembar([$s]), []);

            $this->assertSame($harusBeda ? 1 : 0, $hasil['jumlah_berubah'], $nama);
        }
    }

    public function test_sel_yang_hanya_ada_di_satu_sisi_dihitung_berubah(): void
    {
        $hasil = BandingkanLembar::susun($this->lembar([6, 7]), $this->lembar([6.0]), []);

        $this->assertSame(1, $hasil['jumlah_berubah']);
    }

    public function test_tanpa_asli_tidak_ada_yang_ditandai_berubah(): void
    {
        $hasil = BandingkanLembar::susun(null, $this->lembar([6.0]), []);

        $this->assertFalse($hasil['ada_asli']);
        $this->assertSame(0, $hasil['jumlah_berubah']);
    }

    /**
     * Pemutaran ulang jejak audit memakai perbandingan yang sama. Kalau baris
     * koreksi KEDUA ikut membawa sel pertama dengan bentuk angka yang beda
     * (6 di nilai lama, 6.0 di nilai baru), pelaku koreksi kedua tidak boleh
     * menimpa pelaku koreksi pertama pada sel yang bukan miliknya.
     */
    public function test_jejak_tidak_menimpa_pelaku_sel_dengan_selisih_bentuk_angka(): void
    {
        $asli = $this->lembar([6, 25.001]);
        $sekarang = $this->lembar([6.0, 25.004]);

        $koreksiPertama = (new AuditLog)->forceFill([
            'old_data' => ['pembacaan' => $this->lembar([6, 25.001])['pembacaan']],
            'new_data' => ['pembacaan' => $this->lembar([6, 25.004])['pembacaan']],
            'note' => 'Koreksi 25.004',
        ]);
        // Koreksi kedua tidak mengubah apa pun pada angka, hanya bentuk JSON-nya.
        $koreksiKedua = (new AuditLog)->forceFill([
            'old_data' => ['pembacaan' => $this->lembar([6, 25.004])['pembacaan']],
            'new_data' => ['pembacaan' => $this->lembar([6.0, 25.004])['pembacaan']],
            'note' => 'Hanya bentuk angka',
        ]);

        $hasil = BandingkanLembar::susun($asli, $sekarang, [$koreksiPertama, $koreksiKedua]);

        $berubah = $this->yangBerubah($hasil);
        $this->assertCount(1, $berubah);
        $this->assertSame('Koreksi 25.004', $berubah[0]['alasan'], 'alasan sel itu tetap dari koreksi yang mengubahnya');
    }
}
