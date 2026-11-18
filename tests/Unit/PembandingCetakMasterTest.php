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

    /**
     * Mutan P1 (PK-4, tinjauan 18 Nov): pembanding yang mengabaikan token TERAKHIR (atau
     * PERTAMA) tetap hijau di delapan tes awal, karena semua kasusnya membedakan kolom
     * tengah. Kolom U95 adalah yang terakhir di hampir semua tabel sertifikat — paling
     * mahal bila lolos.
     */
    public function test_beda_hanya_di_kolom_terakhir_atau_pertama_ketahuan(): void
    {
        $this->assertSame(['beda'], self::statusBaris(self::master(['10.00', '0.00', '0.034'])), 'kolom terakhir');
        $this->assertSame(['beda'], self::statusBaris(self::master(['11.00', '0.00', '0.033'])), 'kolom pertama');
        $this->assertSame(['beda'], self::statusBaris(self::master(['10.00', '0.00'])), 'master kurang satu kolom');
        $this->assertSame(['beda'], self::statusBaris(self::master(['10.00', '0.00', '0.033', '0.5'])), 'master lebih satu kolom');
    }

    public function test_mode_akhiran_tetap_peka_di_kolom_terakhir_dan_di_batas_kiri(): void
    {
        $cetak = PembandingCetakMaster::barisCetak(self::HTML);
        $status = static fn (array $token): string => PembandingCetakMaster::cocokkan(self::master($token), $cetak, true)[0]['status'];

        // Kolom kiri (ID/nominal) boleh membawa angkanya sendiri: deret hasil di UJUNG baris.
        $this->assertSame('cocok', $status(['0.00', '0.033']));
        $this->assertSame('beda', $status(['0.00', '0.034']), 'kolom terakhir');
        $this->assertSame('beda', $status(['0.01', '0.033']), 'kolom tengah');
        $this->assertSame('beda', $status(['10.00', '0.00', '0.033', '1']), 'lebih panjang dari baris cetak');
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

    /**
     * PK-5: satu fixture per kelompok alat dibaca berdampingan, dan baris yang disembunyikan
     * master (tidak dicetak Excel) tidak ikut diadu — kalau ikut, titik nol Gaya menjadi
     * "selisih" palsu.
     */
    public function test_fixture_kelompok_b_dibaca_berdampingan_dan_baris_tersembunyi_tidak_ikut(): void
    {
        foreach ([
            'gaya/load_cell', 'gaya/utm', 'gaya/proving_ring',
            'enclosure/constant_yokogawa', 'enclosure/recorder',
            'suhu/thermocouple', 'suhu/thermometer_glass',
            'sieve/mesh', 'autoclave/autoclave', 'timer/stopwatch',
            'ph/imte_wq_129', 'ph/trial',
            'flowmeter/ultrasonic_totalizer', 'flowmeter/ultrasonic_flowrate',
        ] as $kunci) {
            $this->assertNotSame([], PembandingCetakMaster::barisMaster($kunci), "Fixture {$kunci} kosong.");
        }

        // Load Cell: baris 21 (beban 0 kN) disembunyikan master; baris 22 (100 kN) tercetak.
        $this->assertContains(21, PembandingCetakMaster::master('gaya/load_cell')['baris_tersembunyi']);
        $nomor = array_column(PembandingCetakMaster::barisMaster('gaya/load_cell'), 'baris');
        $this->assertNotContains(21, $nomor);
        $this->assertContains(22, $nomor);

        // Fixture kelompok A tetap terbaca lewat pembanding yang sama.
        $this->assertNotSame([], PembandingCetakMaster::barisMaster('timbangan/kg'));
    }
}
