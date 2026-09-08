<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Services\Calibration\FlowmeterCalculator;
use App\Services\Calibration\TabelStandarFlowmeter;
use App\Support\FlowmeterMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gerbang `boleh_terbit` lembar **Flowmeter Ultrasonic**, plus bukti bahwa
 * kedua sesi contoh benar-benar menghasilkan baris hitungan.
 *
 * ## Kenapa test ini ada
 *
 * Pola "alat yang satu titiknya bukan satu deret datar" sudah menggigit
 * **sepuluh kali**. Flowmeter bentuk kesebelas, dan dia yang paling berbahaya
 * dari seluruhnya: satu titiknya punya DUA deret berdampingan (pembacaan UUT
 * dan pembacaan totalizer standar), dan kalau keduanya tertukar atau tertimpa,
 * yang terbit bukan error melainkan **deviasi nol** — sertifikat yang mencetak
 * koreksi 0,000 di setiap titik dan terlihat seperti alat yang sangat akurat.
 *
 * ## Yang paling dijaga: gerbangnya MENAHAN, bukan memperingatkan
 *
 * `CalibrationValidator::periksaPeringatanProfil()` membungkus
 * `peringatanSesi()` jadi temuan tingkat PERINGATAN yang boleh dilewati admin
 * lewat `abaikan_peringatan`. Jadi yang membuktikan sebuah syarat benar-benar
 * menahan bukan adanya pesan, melainkan **ketiadaan baris hitungan**.
 *
 * Keenam syaratnya diuji satu per satu lewat kalkulatornya langsung, karena di
 * situlah keputusannya diambil.
 */
class FlowmeterSesiTest extends TestCase
{
    use RefreshDatabase;

    /** Toleransi yang sama dengan `FlowmeterMasterTest` — 5·10⁻⁶ relatif. */
    private const TOL = 5e-6;

    /** Geometri pipa sesi contoh (`INPUT DATA!E25:G25` & `E26:G26`). */
    private const PIPA = [
        'diameter_pipa_mm' => [50.81, 50.82, 50.81],
        'ketebalan_pipa_mm' => [2.32, 2.31, 2.32],
    ];

    /**
     * Satu titik Flowrate yang SEHAT — dipakai sebagai dasar tiap gerbang,
     * supaya yang berubah cuma satu hal per pengujian.
     *
     * @return array<string, mixed>
     */
    private static function titikSehat(): array
    {
        return [
            'titik_ke' => 1,
            'uut' => [
                [101.255, 101.276, 101.289],
                [102.654, 102.625, 102.678],
                [101.986, 101.910, 101.945],
            ],
            'std' => [101.998, 101.897, 101.123],
            'suhu_awal' => [24.5, 24.5, 24.5],
            'suhu_akhir' => [24.5, 24.6, 24.6],
            'densitas_uut' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $ubah
     * @param  array<string, mixed>  $konteksUbah
     * @return array<string, mixed>
     */
    private function hitung(array $ubah = [], array $konteksUbah = []): array
    {
        return (new FlowmeterCalculator)->hitungSesi(
            [array_replace(self::titikSehat(), $ubah)],
            array_replace([
                'mode' => TabelStandarFlowmeter::MODE_FLOWRATE,
                'satuan' => 'LPM',
                'resolusi' => 0.001,
            ] + self::PIPA, $konteksUbah),
        );
    }

    /** Titik contoh yang sehat memang terbit — kontrol positif tiap gerbang. */
    public function test_titik_sehat_terbit(): void
    {
        $hasil = $this->hitung();

        $this->assertSame([], $hasil['ditolak'], 'Titik contoh yang sehat justru ditolak.');
        $this->assertCount(1, $hasil['titik']);
    }

    /**
     * Syarat 1 — nominal standar WAJIB di dalam jangkauan tabel sertifikat UFM.
     *
     * Di master, bacaan di luar jangkauan tetap memungut baris terdekat apa pun
     * jaraknya — termasuk baris KOSONG yang dibacanya nol, yang menerbitkan
     * `#N/A` ke sertifikat pelanggan. Di sini titiknya tidak terbit.
     */
    public function test_standar_di_luar_jangkauan_tabel_ditahan(): void
    {
        // Tabel flowrate berhenti di 506,822 Lpm; 900 jauh di luarnya.
        $hasil = $this->hitung(['std' => [900.1, 900.4, 900.9]]);

        $this->assertSame([], $hasil['titik'], 'Titik di luar jangkauan tabel standar tetap terbit.');
        $this->assertStringContainsString('LUAR jangkauan tabel', $hasil['ditolak'][0]['alasan']);
    }

    /** Syarat 2 — minimal dua pembacaan UUT dan dua pembacaan standar. */
    public function test_pembacaan_kurang_dari_dua_ditahan(): void
    {
        $hasil = $this->hitung(['std' => [101.998]]);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('minimal dua pembacaan', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Syarat 3 — simpangan bakunya bukan nol eksak.
     *
     * Tanda tangan "satu nilai disalin n kali". Diuji dengan angka yang TIDAK
     * bisa direpresentasikan persis dalam biner (101,276), karena penjaga
     * ber-`stdev > 0` lolos begitu saja untuk nilai seperti itu — dia keluar
     * 1e-13, bukan nol.
     */
    public function test_pembacaan_tanpa_sebaran_ditahan(): void
    {
        $hasil = $this->hitung([
            'uut' => [
                [101.276, 101.276, 101.276],
                [101.276, 101.276, 101.276],
                [101.276, 101.276, 101.276],
            ],
            'std' => [101.998, 101.998, 101.998],
        ]);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('tidak punya sebaran', $hasil['ditolak'][0]['alasan']);
    }

    /** Syarat 4 — resolusi > 0, kalau tidak komponen resolusi bernilai nol. */
    public function test_resolusi_kosong_ditahan(): void
    {
        $hasil = $this->hitung(konteksUbah: ['resolusi' => 0.0]);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('Resolusi alat belum diisi', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Syarat 5 — diameter & ketebalan pipa terisi.
     *
     * Tanpa keduanya `u_A` nol dan DUA komponen budget lenyap sekaligus, dan di
     * alat ini master tidak punya lantai CMC yang menyamarkannya.
     */
    public function test_geometri_pipa_kosong_ditahan(): void
    {
        $hasil = $this->hitung(konteksUbah: ['diameter_pipa_mm' => [], 'ketebalan_pipa_mm' => []]);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('Diameter luar dan ketebalan pipa', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Syarat 6 — titik WAJIB masuk salah satu pita CMC terakreditasi.
     *
     * Di luar kedua pita, sertifikat yang terbit membawa nomor lingkup
     * LK-285-IDN untuk pengukuran yang tidak diakreditasi. Aturannya sama
     * dengan `TabelStandarMicrometer::pitaCmc()`: `null` itu pemblokir, bukan
     * izin terbit tanpa lantai.
     */
    public function test_di_luar_kedua_pita_cmc_ditahan(): void
    {
        // Pita Flowrate mulai 75 Lpm; ~50 Lpm ada di bawahnya, tapi bacaan
        // standarnya (~60) masih di dalam jangkauan tabel yang mulai 100,185?
        // Tidak — jadi yang menahan bisa saja gerbang tabel. Karena itu
        // standarnya dibuat 101-an (di dalam jangkauan) sementara UUT-nya 50-an:
        // yang tersisa sebagai penahan cuma pita CMC.
        $hasil = $this->hitung([
            'uut' => [[50.11, 50.13, 50.15], [50.21, 50.24, 50.27], [50.31, 50.33, 50.36]],
            'std' => [101.998, 101.897, 101.123],
        ]);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('LUAR kedua pita CMC', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Densitas UUT kosong TIDAK menahan — dia jatuh ke densitas air pada suhu
     * yang tercatat, persis `IFERROR(IF(AVERAGE(...)=0; AVERAGE(rho); ...))`
     * master.
     */
    public function test_densitas_uut_kosong_tidak_menahan(): void
    {
        $hasil = $this->hitung(['densitas_uut' => []]);

        $this->assertCount(1, $hasil['titik']);
        $this->assertFalse($hasil['titik'][0]['densitas_uut_diketik']);
        // Densitas UUT jatuh ke densitas STANDAR, jadi rasionya persis 1 dan
        // standar terkoreksi ber-rasio sama dengan yang tanpa rasio.
        $this->assertSame(
            $hasil['titik'][0]['densitas_standar'],
            $hasil['titik'][0]['densitas_uut'],
        );
    }

    /**
     * Satuan berbasis MASSA tanpa densitas UUT DIBLOKIR.
     *
     * Master pun tidak punya faktornya: `DATABASE!S25` Totalizer berisi teks
     * `'perlu dibagi densitas'`, `S25` Flowrate berisi `=S23/1000` (0,0166667 —
     * itu m³/h dibagi seribu, salah dimensi), dan `S26` berisi `#REF!`. Menebak
     * densitas air pada suhu sesi berarti mengarang angka untuk fluida yang
     * mungkin bukan air.
     */
    public function test_satuan_massa_tanpa_densitas_diblokir(): void
    {
        $hasil = $this->hitung(konteksUbah: ['satuan' => 'kg/min']);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('berbasis massa', $hasil['ditolak'][0]['alasan']);
    }

    /** Satuan massa DENGAN densitas UUT jalan — jalurnya hidup, bukan dimatikan. */
    public function test_satuan_massa_dengan_densitas_jalan(): void
    {
        // ~101 kg/min air pada 0,997 kg/L = ~101,6 Lpm — di dalam pita 75–191.
        $hasil = $this->hitung(
            ['densitas_uut' => [0.997, 0.997, 0.997]],
            ['satuan' => 'kg/min'],
        );

        $this->assertCount(1, $hasil['titik'], 'Satuan massa dengan densitas justru ditolak.');
        $this->assertTrue($hasil['titik'][0]['densitas_uut_diketik']);
    }

    /**
     * Kedua sesi contoh benar-benar melahirkan baris hitungan — dan angkanya
     * yang sudah diadu ke reimplementasi Python.
     *
     * Ini yang membuktikan seeder, `FlowmeterMentah`, profil, dan kalkulator
     * bersambung. Tanpa test ini, seeder yang menghasilkan NOL baris tetap
     * membuat `HitungUlangSemuaSesiTest` hijau — dia cuma menuntut sesi yang
     * ADA hitungannya konsisten, bukan bahwa sesi ini punya.
     */
    public function test_kedua_sesi_contoh_terhitung(): void
    {
        $this->seed(DatabaseSeeder::class);

        $harapan = [
            'DEMO-FM-TOT-001' => [
                'mode' => FlowmeterMentah::MODE_TOTALIZER,
                'titik' => [
                    1 => ['uut' => 1004.5766666666667, 'koreksi' => -6.088666666666768, 'u95' => 13.572337131483136],
                    2 => ['uut' => 1902.7666666666664, 'koreksi' => -18.890666666666675, 'u95' => 25.62276555272631],
                ],
            ],
            'DEMO-FM-FLW-001' => [
                'mode' => FlowmeterMentah::MODE_FLOWRATE,
                'titik' => [
                    1 => ['uut' => 101.95755555555554, 'koreksi' => -1.278888888888872, 'u95' => 1.9309242290111408],
                    // Lantai CMC 1,2 % × 310,6426 — lihat `FlowmeterLantaiCmcTest`.
                    2 => ['uut' => 310.6425555555555, 'koreksi' => -3.8145555555555006, 'u95' => 3.727710666666666],
                ],
            ],
        ];

        foreach ($harapan as $nomorSesi => $h) {
            $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->first();

            $this->assertNotNull($sesi, "Sesi contoh `{$nomorSesi}` nggak ter-seed.");

            $blok = FlowmeterMentah::blokSesi($sesi->spesifikasi_alat);
            $this->assertNotNull($blok, "Sesi `{$nomorSesi}` nggak punya blok `spesifikasi_alat.flowmeter`.");
            $this->assertSame($h['mode'], $blok['mode']);

            $hitungan = $sesi->uncertaintyCalculations->keyBy('titik_ke');

            $this->assertCount(
                count($h['titik']),
                $hitungan,
                "Sesi `{$nomorSesi}` nggak melahirkan baris hitungan sebanyak titiknya — jalur "
                .'seeder → FlowmeterMentah → profil → kalkulator putus di suatu tempat, dan '
                .'putusnya nggak menerbitkan error.',
            );

            foreach ($h['titik'] as $titikKe => $angka) {
                $baris = $hitungan[$titikKe];

                $this->assertEqualsWithDelta($angka['uut'], (float) $baris->rata_rata, abs($angka['uut']) * self::TOL);
                $this->assertEqualsWithDelta($angka['koreksi'], (float) $baris->koreksi, abs($angka['koreksi']) * self::TOL);
                $this->assertEqualsWithDelta(
                    $angka['u95'],
                    (float) $baris->ketidakpastian_diperluas,
                    abs($angka['u95']) * self::TOL,
                    "U95 titik {$titikKe} sesi `{$nomorSesi}` bergeser.",
                );
                // `k` PER TITIK, bukan satu angka sesi — master mencetak `k`
                // Titik 1 untuk semua titik. Lihat pertanyaan lab §14.
                $this->assertGreaterThan(1.9, (float) $baris->faktor_cakupan_k);
            }
        }
    }

    /**
     * Kedua sesi contoh memakai profil yang BERBEDA, dan masing-masing punya
     * jumlah komponen budget yang berbeda pula (8 vs 9).
     *
     * Kalau keduanya salah rute ke satu profil, angkanya tetap keluar dan tetap
     * terlihat wajar — yang beda cuma budgetnya, dan itu tidak tercetak di
     * kolom mana pun.
     */
    public function test_dua_sesi_memakai_profil_yang_berbeda(): void
    {
        $this->seed(DatabaseSeeder::class);

        $komponen = [];

        foreach (['DEMO-FM-TOT-001' => 8, 'DEMO-FM-FLW-001' => 9] as $nomorSesi => $jumlah) {
            $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();
            $baris = $sesi->uncertaintyCalculations->sortBy('titik_ke')->firstOrFail();

            $budget = array_filter(
                (array) $baris->type_b_components,
                static fn (array $k): bool => ($k['distribusi'] ?? null) !== 'jejak'
                    && ($k['sumber'] ?? null) !== 'perbandingan_cmc',
            );

            $komponen[$nomorSesi] = count($budget);

            $this->assertCount(
                $jumlah,
                $budget,
                "Sesi `{$nomorSesi}` punya ".count($budget)." komponen budget, bukan {$jumlah}. "
                .'Totalizer 8 dan Flowrate 9 — bedanya komponen "Pengulangan Pembacaan UUT" yang '
                .'lahir dari revisi 20 Mei 2026 dan belum masuk workbook Totalizer.',
            );
        }

        $this->assertNotSame(
            $komponen['DEMO-FM-TOT-001'],
            $komponen['DEMO-FM-FLW-001'],
            'Kedua varian menghasilkan budget yang sama banyak — salah satunya salah rute.',
        );
    }
}
