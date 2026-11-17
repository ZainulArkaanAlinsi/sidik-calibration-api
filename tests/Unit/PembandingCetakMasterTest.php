<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\PembandingCetakMaster;

/**
 * Penjaga pembanding angka cetak (PK-4): pembanding yang tidak pernah terbukti
 * bisa MERAH melahirkan test yang hijau selamanya. Yang dijaga di sini bahwa
 * satu digit berbeda, satu kolom tertukar, atau satu baris hilang benar-benar
 * ketahuan — dan bahwa angka yang sama dengan pemisah desimal Indonesia
 * tidak dianggap beda.
 */
class PembandingCetakMasterTest extends TestCase
{
    private const HTML = <<<'HTML'
        <style>td { color: red }</style>
        <div class="judul-kelompok">3. ACCURACY</div>
        <table class="data">
            <thead><tr><th>Nominal Standard (kg)</th><th>Correction (kg)</th><th>Uncertainty &plusmn; (kg)</th></tr></thead>
            <tbody>
                <tr><td>10,00</td><td>0,00</td><td>0,033</td></tr>
                <tr><td>20,00</td><td>-0,00</td><td>0,033</td></tr>
            </tbody>
        </table>
        <div class="baris-angka"><span class="nama">6. LIMIT OF PERFORMANCE</span> = &plusmn; 0,08 kg</div>
        <div class="ket-k">That weighing uncertainty is measured at confidence level 95 % &amp; coverage factor ( K ) = 2</div>
        HTML;

    /** @return list<array{baris: int, sel: list<string>, token: list<string>, status: string}> */
    private static function master(array ...$baris): array
    {
        $hasil = [];

        foreach ($baris as $i => $token) {
            $hasil[] = ['baris' => $i + 1, 'sel' => ['X'.($i + 1)], 'token' => $token, 'status' => 'ada'];
        }

        return $hasil;
    }

    /** @return list<string> */
    private static function statusBaris(array $master): array
    {
        return array_column(PembandingCetakMaster::cocokkan($master, PembandingCetakMaster::barisCetak(self::HTML)), 'status');
    }

    public function test_angka_sama_dengan_koma_desimal_cocok_dan_penomoran_bagian_dibuang(): void
    {
        $this->assertSame(
            ['cocok', 'cocok', 'cocok', 'cocok'],
            self::statusBaris(self::master(['10.00', '0.00', '0.033'], ['20.00', '-0.00', '0.033'], ['0.08'], ['2'])),
        );
    }

    public function test_satu_digit_beda_ketahuan(): void
    {
        $this->assertSame(['cocok', 'beda'], self::statusBaris(self::master(['10.00', '0.00', '0.033'], ['20.00', '-0.01', '0.033'])));
    }

    public function test_kolom_tertukar_ketahuan(): void
    {
        $this->assertSame(['beda'], self::statusBaris(self::master(['10.00', '0.033', '0.00'])));
    }

    public function test_baris_yang_tidak_dicetak_ketahuan_dan_membawa_kandidat(): void
    {
        $hasil = PembandingCetakMaster::cocokkan(
            self::master(['30.00', '0.01', '0.033']),
            PembandingCetakMaster::barisCetak(self::HTML),
        );

        $this->assertSame('beda', $hasil[0]['status']);
        $this->assertNotNull($hasil[0]['kandidat'], 'Pesan gagal harus menunjuk baris cetak terdekat.');
    }

    public function test_urutan_dijaga_dan_baris_kembar_tidak_saling_menutupi(): void
    {
        // Dua baris master identik, tetapi cetak cuma memuat SATU baris itu.
        $this->assertSame(
            ['cocok', 'beda'],
            self::statusBaris(self::master(['10.00', '0.00', '0.033'], ['10.00', '0.00', '0.033'])),
        );
        // Baris yang ada, tetapi di urutan terbalik, tidak lolos.
        $this->assertSame(
            ['cocok', 'beda'],
            self::statusBaris(self::master(['20.00', '-0.00', '0.033'], ['10.00', '0.00', '0.033'])),
        );
    }

    public function test_angka_tunggal_harus_di_ujung_baris(): void
    {
        // `95` ada di kalimat k, tetapi angka k-nya `2`.
        $this->assertSame(['beda'], self::statusBaris(self::master(['95'])));
        $this->assertSame(['cocok'], self::statusBaris(self::master(['2'])));
    }

    public function test_sel_berstatus_galat_dan_tak_pasti_dilewati_bukan_dicocokkan(): void
    {
        $hasil = PembandingCetakMaster::cocokkan(
            [
                ['baris' => 1, 'sel' => ['I20'], 'token' => [], 'status' => 'galat_master'],
                ['baris' => 2, 'sel' => ['I21'], 'token' => [], 'status' => 'tak_pasti'],
            ],
            PembandingCetakMaster::barisCetak(self::HTML),
        );

        $this->assertSame(['galat_master', 'tak_pasti'], array_column($hasil, 'status'));
    }

    public function test_fixture_cetak_master_memuat_semua_master_yang_dijanjikan(): void
    {
        foreach ([
            'timbangan/kg', 'timbangan/gram', 'timbangan/substitusi',
            'anak_timbangan/f1', 'anak_timbangan/f2_hal1', 'anak_timbangan/f2_hal2', 'anak_timbangan/f2_hal3',
            'volumetric/fixed', 'volumetric/graduated',
            'piston_volume/fixed', 'piston_volume/graduated',
        ] as $kunci) {
            $this->assertNotSame([], PembandingCetakMaster::barisMaster($kunci), "Fixture {$kunci} kosong.");
        }
    }
}
