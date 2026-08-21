<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\GasDetectorProfile;
use App\Services\GumCalculator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Budget ketidakpastian Multi Gas Detector (alat ke-10), dicocokin ke
 * `Gas Detector Uli Skin (std Rigaz).xlsm` (sheet `PERHITUNGAN U95%`, keempat
 * blok gas). Angka acuan DISALIN dari workbook, bukan dari keluaran kode —
 * kalau engine-nya bener, dia reproduksi persis.
 *
 * **5 komponen** per gas, urutan persis sheet:
 *   1. Pengulangan (Type A) : u = stdev/√3                          (vi 2)
 *   2. Resolusi alat        : u = (resolusi/2)/√3                   (vi 60)
 *   3. Sertifikat kalibrator: u = U95%_botol / 2                    (vi 200)
 *   4. Pengaruh suhu        : u = Δsuhu/√3   ; ci = 0,34 %·titik    (vi 200)
 *   5. Pengaruh tekanan     : u = Δtekanan_kPa/√5 ; ci = 0,98 %·titik (vi 200)
 *
 *   Δsuhu    = |22,9 − 22,8|          = 0,1 °C
 *   Δtekanan = |922,8 − 923,5| / 10   = 0,07 kPa
 *
 * Tiga hal yang dijaga test ini dan nggak dijaga di tempat lain:
 *
 *  - **Δ, bukan U95 sertifikat.** Delapan alat lain menyusun komponen suhu dari
 *    ketidakpastian sertifikat termometer. Master gas detector pakai pergeseran
 *    ruangan sesi itu sendiri. Kalau ada yang "menyeragamkan" ke pola alat lain,
 *    keempat angka di bawah meleset sekaligus.
 *  - **Pembagi tekanan √5.** Master menandai `rect.` (yang seharusnya √3) tapi
 *    selnya `T13 = M13/SQRT(5)`, konsisten di keempat blok.
 *  - **vi resolusi 60.** Delapan alat lain 1.000.000.
 */
class GasDetectorBudgetTest extends TestCase
{
    private GumCalculator $gum;

    /** Δ suhu ruangan sesi master (`PERHITUNGAN!M19`). */
    private const DELTA_SUHU = 0.09999999999999787;

    /** Δ tekanan dalam kPa (`PERHITUNGAN U95%!M13` = `M21/10`). */
    private const DELTA_TEKANAN_KPA = 0.07000000000000454;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gum = new GumCalculator;
    }

    /**
     * Δ dihitung dari angka mentah lembar kerja, bukan diketik — dan hasilnya
     * memang bukan 0,1 & 0,07 bulat. Kalau suatu saat ada yang membulatkannya
     * "biar rapi", angka U95 di bawah bergeser di digit ke-13, dan pembandingan
     * ke master jadi longgar tanpa ada yang sadar.
     */
    public function test_delta_kondisi_sama_dengan_sel_master(): void
    {
        $this->assertSame(self::DELTA_SUHU, abs(22.9 - 22.8));
        $this->assertSame(self::DELTA_TEKANAN_KPA, abs(922.8 - 923.5) / 10);
    }

    /**
     * Keempat gas diadu agregat ke sheet: `uc`, `v_eff`, `k` (TINV), dan `U`.
     *
     * Excel memotong derajat kebebasan TINV ke integer, dan
     * `GumCalculator::agregasiBudget()` juga (`floor($veff)`) — jadi `k`-nya
     * bukan "mirip", tapi dihitung dari bilangan yang sama.
     *
     * @param  list<float>  $pembacaan
     */
    #[DataProvider('gasMaster')]
    public function test_tiap_gas_reproduksi_workbook(
        string $nama,
        float $titik,
        float $resolusi,
        float $u95Botol,
        array $pembacaan,
        float $ucMaster,
        float $veffMaster,
        float $kMaster,
        float $uMaster,
    ): void {
        $r = $this->gum->agregasiBudget(
            $this->komponen($titik, $resolusi, $u95Botol, $pembacaan),
        );

        $this->assertEqualsWithDelta($ucMaster, $r['ketidakpastian_gabungan'], 1e-12, "uc {$nama}");
        $this->assertEqualsWithDelta($veffMaster, $r['derajat_kebebasan_efektif'], 1e-9, "v_eff {$nama}");
        $this->assertEqualsWithDelta($kMaster, $r['faktor_cakupan_k'], 1e-9, "k {$nama}");
        $this->assertEqualsWithDelta($uMaster, $r['ketidakpastian_diperluas'], 1e-9, "U {$nama}");
    }

    /**
     * Sel `AB14` tiap blok — jumlah (uici)². Diadu terpisah dari `uc` supaya
     * kalau meleset ketahuan di komponen mana, bukan cuma "hasil akhir beda".
     */
    public function test_jumlah_kuadrat_tiap_gas_reproduksi_workbook(): void
    {
        $harapan = [
            'CO' => 6.57142263293698,
            'H2S' => 0.5851523522777778,
            'CH4' => 1.7572760757777777,
            'O2' => 0.2022431976426498,
        ];

        foreach (self::gasMaster() as $baris) {
            [$nama, $titik, $resolusi, $u95Botol, $pembacaan] = $baris;

            $jumlah = array_sum(array_map(
                fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
                $this->komponen($titik, $resolusi, $u95Botol, $pembacaan),
            ));

            $this->assertEqualsWithDelta($harapan[$nama], $jumlah, 1e-12, "Σ(uici)² {$nama}");
        }
    }

    /**
     * Profil menyusun komponen dalam URUTAN & bentuk yang sama seperti sheet,
     * dengan `ci` dua komponen turunan yang diadu ke selnya (`W12`/`W13` blok
     * CO).
     */
    public function test_komponen_profil_urut_dan_ci_turunan_titik(): void
    {
        $komponen = $this->dariProfil(101.0, 1.0, 5.05, 0.3333333333333333, 3);

        $this->assertCount(5, $komponen);
        $this->assertSame([
            'pengulangan_pembacaan',
            'resolusi_alat',
            'ketidakpastian_standar',
            'pengaruh_suhu',
            'pengaruh_tekanan',
        ], array_column($komponen, 'sumber'));

        $suhu = collect($komponen)->firstWhere('sumber', 'pengaruh_suhu');
        // `W12 = 0.34%*C7` → 0,34 % × 101.
        $this->assertEqualsWithDelta(0.34340000000000004, $suhu['ci'], 1e-15);
        $this->assertEqualsWithDelta(self::DELTA_SUHU / sqrt(3), $suhu['u'], 1e-15);
        $this->assertSame(200, $suhu['vi']);

        $tekanan = collect($komponen)->firstWhere('sumber', 'pengaruh_tekanan');
        // `W13 = 0.98%*C7` → 0,98 % × 101.
        $this->assertEqualsWithDelta(0.9898, $tekanan['ci'], 1e-15);
        // `T13 = M13/SQRT(5)` — √5, BUKAN √3 walau kolom J menulis `rect.`.
        $this->assertEqualsWithDelta(self::DELTA_TEKANAN_KPA / sqrt(5), $tekanan['u'], 1e-15);

        $resolusi = collect($komponen)->firstWhere('sumber', 'resolusi_alat');
        // `R10 = 60`, bukan 1e6 kayak delapan alat lain.
        $this->assertSame(60, $resolusi['vi']);
    }

    /**
     * Tanpa Δ lengkap profil balik `null` — sesi jatuh ke jalur CMC, BUKAN
     * menyusun budget yang dua komponennya diam-diam nol.
     *
     * Komponen bernilai nol bukan sekadar "kurang teliti": dia mengecilkan
     * `uc`, menaikkan `v_eff`, mengecilkan `k`, dan U95 yang keluar lebih kecil
     * dari yang bisa dipertanggungjawabkan — arah kesalahan yang paling
     * berbahaya di dokumen terakreditasi.
     */
    public function test_tanpa_delta_jatuh_ke_jalur_cmc_bukan_ngarang(): void
    {
        foreach ([
            [],
            ['delta_suhu' => 0.1],
            ['delta_tekanan' => 0.7],
            ['delta_suhu' => 0.1, 'delta_tekanan' => null],
        ] as $konteks) {
            $this->assertNull(
                (new GasDetectorProfile)->komponenBudget(
                    new CalibrationCapability,
                    new Equipment(['satuan' => 'ppm', 'resolusi' => 1]),
                    new Standard(['nama' => 'CO', 'ketidakpastian' => 5.05, 'faktor_cakupan' => 2]),
                    101.0,
                    0.3333333333333333,
                    3,
                    22.85,
                    $konteks,
                ),
                'Konteks '.json_encode($konteks).' mestinya nggak cukup buat nyusun budget.',
            );
        }
    }

    /**
     * Resolusi dibaca PER GAS, bukan dikunci ke sel CO seperti master.
     *
     * `M28`/`M46` di master menulis `$G$6*0.5` — absolut ke resolusi CO. Blok
     * O2 menulis relatif (`G60*0.5`) dan karena itu benar. Ketiganya kebetulan
     * beresolusi 1 di alat contoh, jadi kesalahannya nggak kelihatan; di alat
     * dengan resolusi H2S 0,1 baru berbeda.
     */
    public function test_resolusi_dan_satuan_dibaca_per_gas(): void
    {
        $profil = new GasDetectorProfile;

        $this->assertSame(1.0, $profil->resolusiTitik(101.0));
        $this->assertSame(1.0, $profil->resolusiTitik(25.0));
        $this->assertSame(1.0, $profil->resolusiTitik(50.0));
        $this->assertSame(0.1, $profil->resolusiTitik(17.9));

        $this->assertSame('ppm', $profil->satuanTitik(101.0));
        $this->assertSame('ppm', $profil->satuanTitik(25.0));
        $this->assertSame('%LEL', $profil->satuanTitik(50.0));
        $this->assertSame('%', $profil->satuanTitik(17.9));

        $this->assertSame(0, $profil->desimalTitik(50.0));
        $this->assertSame(1, $profil->desimalTitik(17.9));
    }

    /**
     * Titik dicocokkan ke gas TERDEKAT dalam toleransi, dan yang di luar
     * toleransi balik null — bukan nyangkut ke tetangga.
     *
     * Pasangan paling rawan H2S (25) & O2 (17,9), berjarak 7,1 — dua kali lebih
     * lebar dari toleransi 3,0 dikali dua, jadi nggak ada nilai yang bisa
     * ambigu di antara keduanya.
     */
    public function test_pencocokan_gas_pakai_yang_terdekat_dan_nolak_yang_jauh(): void
    {
        $profil = new GasDetectorProfile;

        $this->assertSame('H2S', $profil->gasUntukTitik(25.0)['kode']);
        // Botol pengganti yang konsentrasinya sedikit beda tetap kena.
        $this->assertSame('H2S', $profil->gasUntukTitik(26.5)['kode']);
        $this->assertSame('O2', $profil->gasUntukTitik(20.5)['kode']);
        // 21,45 titik tengah antara O2 (17,9) & H2S (25) — dua-duanya di luar
        // toleransi 3,0, jadi nggak ada yang menang.
        $this->assertNull($profil->gasUntukTitik(21.45));
        $this->assertNull($profil->gasUntukTitik(500.0));
    }

    /**
     * Peringatan muncul kalau dua titik terbaca sebagai gas yang sama —
     * kesalahan yang angkanya benar tapi SATUANNYA salah, dan nggak akan
     * memunculkan error di mana pun kalau lolos.
     */
    public function test_peringatan_kalau_dua_titik_kebaca_gas_yang_sama(): void
    {
        $sesi = new CalibrationSession(['tekanan_awal' => 923.5, 'tekanan_akhir' => 922.8]);
        $sesi->setRelation('uncertaintyCalculations', new EloquentCollection([
            new UncertaintyCalculation(['titik_ke' => 1, 'titik_ukur' => 25.0]),
            new UncertaintyCalculation(['titik_ke' => 2, 'titik_ukur' => 26.0]),
        ]));

        $peringatan = (new GasDetectorProfile)->peringatanSesi($sesi);

        $this->assertCount(1, $peringatan);
        $this->assertSame('gas_titik_kembar', $peringatan[0]['kode']);
        $this->assertStringContainsString('ke-1 & ke-2', $peringatan[0]['pesan']);
        $this->assertStringContainsString('H2S', $peringatan[0]['pesan']);
    }

    /**
     * Tekanan yang belum dicatat diperingatkan SEBELUM sertifikat terbit —
     * waktu barometernya masih di tangan teknisi.
     */
    public function test_peringatan_kalau_tekanan_belum_lengkap(): void
    {
        $sesi = new CalibrationSession(['tekanan_awal' => 923.5, 'tekanan_akhir' => null]);
        $sesi->setRelation('uncertaintyCalculations', new EloquentCollection);

        $peringatan = (new GasDetectorProfile)->peringatanSesi($sesi);

        $this->assertCount(1, $peringatan);
        $this->assertSame('gas_tekanan_belum_lengkap', $peringatan[0]['kode']);
        $this->assertStringContainsString('Tekanan udara', $peringatan[0]['pesan']);
    }

    /**
     * Gas Detector NGGAK divonis PASS/FAIL — master nggak punya MPE,
     * sertifikat berhenti di kolom U95%. Sama kayak Autoklaf & DO Meter.
     */
    public function test_gas_detector_nggak_divonis(): void
    {
        $this->assertFalse((new GasDetectorProfile)->punyaToleransi());
    }

    /**
     * `k` NGGAK dikunci — master pakai `TINV(0.05, v_eff)` di keempat blok,
     * dan keempat gas memang keluar dengan `k` berbeda (1,9715 / 2,0106 /
     * 1,9744 / 1,9717). Dikunci 2, ketiganya meleset.
     */
    public function test_faktor_cakupan_dari_t_student_bukan_dikunci(): void
    {
        $this->assertNull((new GasDetectorProfile)->faktorCakupanTetap());
    }

    /**
     * U95 dicetak per baris. Keempat gas punya U95 yang beda besaran DAN beda
     * satuan — diringkas jadi satu baris, tiga dari empat angka hilang.
     */
    public function test_u95_dicetak_per_titik(): void
    {
        $this->assertTrue((new GasDetectorProfile)->u95PerTitik());
    }

    /**
     * Nomor formulir lembar kerjanya sengaja `null`, bukan ditebak. Yang
     * muncul di workbook cuma `SIDIK-FM-CAL-2403_Rev. 0` — formulir
     * SERTIFIKAT, dipakai bersama semua alat. Kalau ada yang mengisinya dengan
     * nomor karangan, test ini yang teriak.
     */
    public function test_kode_dokumen_lembar_kerja_belum_ada_dan_nggak_ditebak(): void
    {
        $this->assertNull(GasDetectorProfile::KODE_DOKUMEN);
        $this->assertSame('SIDIK-IK-CAL-0536_Rev.0', GasDetectorProfile::KODE_METODE);
    }

    /** TIGA pengulangan — `INPUT DATA` D38:D40, dikonfirmasi `R9 = 3-1`. */
    public function test_tiga_pengulangan_bukan_lima(): void
    {
        $this->assertSame(3, GasDetectorProfile::JUMLAH_PENGULANGAN);
    }

    public function test_registry_nemu_profil_dari_nama_dan_kode(): void
    {
        $registry = new CalibrationProfileRegistry;

        $this->assertInstanceOf(GasDetectorProfile::class, $registry->untukNamaAlat('Gas Detector'));
        $this->assertInstanceOf(GasDetectorProfile::class, $registry->untukKode('gas_detector'));
    }

    /**
     * Angka master keempat blok `PERHITUNGAN U95%`.
     *
     * @return list<array{0: string, 1: float, 2: float, 3: float, 4: list<float>, 5: float, 6: float, 7: float, 8: float}>
     */
    public static function gasMaster(): array
    {
        return [
            // nama, titik, resolusi, U95 botol, pembacaan, uc, v_eff, k, U
            ['CO', 101.0, 1.0, 5.05, [100.0, 100.0, 101.0],
                2.5634786195591683, 206.09590353218718, 1.9715466694452266, 5.054017734585925],
            ['H2S', 25.0, 1.0, 1.25, [24.0, 24.0, 23.0],
                0.7649525163549551, 48.55737329832666, 2.0106347576242314, 1.538040117315391],
            ['CH4', 50.0, 1.0, 2.5, [50.0, 49.0, 50.0],
                1.325622901046062, 166.9595597339689, 1.9743577636580343, 2.617253866363179],
            ['O2', 17.9, 0.1, 0.895, [16.7, 16.8, 16.7],
                0.4497145735270871, 203.35073577395218, 1.9717188484634527, 0.886710701052061],
        ];
    }

    /**
     * Komponen budget SATU gas, disusun lewat profilnya sendiri — bukan
     * disalin ulang di test. Kalau rumusnya digeser di profil, test ini ikut
     * geser dan yang teriak pembandingan ke master di atas, bukan test yang
     * kebetulan mencocokkan dirinya sendiri.
     *
     * @param  list<float>  $pembacaan
     * @return list<array{u: float, ci: float, vi: float}>
     */
    private function komponen(float $titik, float $resolusi, float $u95Botol, array $pembacaan): array
    {
        $n = count($pembacaan);
        $rata = array_sum($pembacaan) / $n;
        $jumlah = 0.0;
        foreach ($pembacaan as $p) {
            $jumlah += ($p - $rata) ** 2;
        }
        $typeA = sqrt($jumlah / ($n - 1)) / sqrt($n);

        return array_map(
            fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $this->dariProfil($titik, $resolusi, $u95Botol, $typeA, $n),
        );
    }

    /**
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>
     */
    private function dariProfil(float $titik, float $resolusi, float $u95Botol, float $typeA, int $n): array
    {
        return (new GasDetectorProfile)->komponenBudget(
            new CalibrationCapability,
            new Equipment(['satuan' => 'ppm', 'resolusi' => $resolusi]),
            new Standard(['nama' => 'Gas', 'ketidakpastian' => $u95Botol, 'faktor_cakupan' => 2]),
            $titik,
            $typeA,
            $n,
            22.85,
            ['delta_suhu' => self::DELTA_SUHU, 'delta_tekanan' => self::DELTA_TEKANAN_KPA * 10],
        );
    }
}
