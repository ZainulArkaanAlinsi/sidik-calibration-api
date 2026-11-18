<?php

namespace Tests\Unit;

use App\Services\Calibration\Profiles\TimerStopwatchProfile;
use App\Services\Calibration\TabelStandarWaktu;
use App\Services\Calibration\WaktuCalculator;
use App\Support\PenyimpanganMaster;
use ReflectionProperty;
use Tests\TestCase;

/**
 * PK-9 / PK5-6: catatan `penyimpangan_master.timer.*` — drift 322 ms (master 298 ms) dan uHRTB empat
 * operator (master dua). Angka "versi master" gabungan di catatan wajib sama dengan sel master Set Point 1
 * (`waktu-frekuensi-master.json`: uc 0,19320, v_eff 457,7, k 1,965168). Catatan HANYA muncul bila tabel
 * yang dipakai memang beda dari master; seluruh angka hasil hitung tidak disentuh (kelas pembanding
 * cuma MENAMBAH method; `WaktuFrekuensiMasterTest` tetap penjaga angkanya).
 */
class PenyimpanganMasterTimerTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__).'/Fixtures/waktu-frekuensi-master.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['timer'];
    }

    /** @return array{hasil: array<string, mixed>, budget: list<array<string, mixed>>} */
    private function titikSatu(?WaktuCalculator $kalk = null): array
    {
        $data = self::fixture();
        $titik = collect($data['titik'])->firstWhere('titik_ke', 1);

        $keluar = ($kalk ?? new WaktuCalculator)->hitungTitik(
            [
                'titik_ke' => 1,
                'titik_ukur' => (float) $titik['set_point_detik'],
                'standar' => $titik['standar_ms'],
                'uut' => $titik['uut_ms'],
            ],
            ['resolusi_uut_detik' => (float) $data['budget_set_point_1']['resolusi_uut_detik'], 'cmc' => (float) $data['budget_set_point_1']['harap']['cmc']],
        );

        $this->assertNotNull($keluar['hasil']);

        return $keluar;
    }

    private function profil(?WaktuCalculator $kalk = null): TimerStopwatchProfile
    {
        $profil = new TimerStopwatchProfile;

        if ($kalk !== null) {
            (new ReflectionProperty(TimerStopwatchProfile::class, 'kalk'))->setValue($profil, $kalk);
        }

        return $profil;
    }

    public function test_tabel_sekarang_mencatat_drift_dan_uhrtb_dengan_angka_master_set_point_1(): void
    {
        $k = $this->titikSatu();
        $catatan = PenyimpanganMaster::dari(
            $this->profil()->catatanPenyimpanganMaster($k['budget'], $k['hasil'], null),
        );

        $this->assertSame(
            ['penyimpangan_master.timer.drift_stopwatch_322_vs_298', 'penyimpangan_master.timer.uhrtb_dua_dari_empat_operator'],
            array_column($catatan, 'sumber'),
        );

        $harap = self::fixture()['budget_set_point_1']['harap'];
        [$drift, $uhrtb] = $catatan;
        $a = $drift['penyimpangan']['angka'];

        // Doc PK5-6: drift 298 -> 322 ms (ui 0,17205 | 0,18591); uHRTB ui 0,00551 | 0,01013.
        $this->assertEqualsWithDelta(298.0, $a['drift_ms_master'], 1e-9);
        $this->assertEqualsWithDelta(322.0, $a['drift_ms_sistem'], 1e-9);
        $this->assertEqualsWithDelta(0.17205, $harap['ui_drift'], 1e-5);
        $this->assertEqualsWithDelta(0.00551, $uhrtb['penyimpangan']['angka']['ui_uhrtb_master'], 5e-6);
        $this->assertEqualsWithDelta(0.01013, $uhrtb['penyimpangan']['angka']['ui_uhrtb_sistem'], 5e-6);

        // Versi master GABUNGAN = sel master Set Point 1 (uc 0,19320, v_eff 457,7, k 1,965168).
        $this->assertEqualsWithDelta($harap['uc'], $a['uc_master_gabungan'], 5e-6);
        $this->assertEqualsWithDelta($harap['veff'], $a['veff_master_gabungan'], 1e-3);
        $this->assertEqualsWithDelta($harap['k'], $a['k_master_gabungan'], 5e-6);

        // Sistem: uc 0,20581 lebih besar 6,5 % dari master = KONSERVATIF (di cetak lantai CMC 0,81 s menang).
        $this->assertEqualsWithDelta(0.20581, $a['uc_sistem'], 5e-5);
        $this->assertSame(PenyimpanganMaster::ARAH_KONSERVATIF, $drift['penyimpangan']['arah']);
        $this->assertStringContainsString('Drift Stopwatch!K26', $drift['keterangan']);
        $this->assertStringContainsString('N23', $uhrtb['keterangan']);

        foreach ($catatan as $c) {
            foreach (['Sumber:', 'Master:', 'Sistem:', 'Arah:'] as $penanda) {
                $this->assertStringContainsString($penanda, $c['keterangan']);
            }
        }
    }

    public function test_tidak_ada_catatan_bila_tabel_sama_dengan_master(): void
    {
        $tabelMaster = new class extends TabelStandarWaktu
        {
            public function driftMs(bool $lengkap = true): float
            {
                return parent::driftMs(false);
            }

            public function uHumanReactionRata(bool $lengkap = true): float
            {
                return parent::uHumanReactionRata(false);
            }
        };
        $kalk = new WaktuCalculator($tabelMaster);
        $k = $this->titikSatu($kalk);

        $this->assertSame(
            [],
            $this->profil($kalk)->catatanPenyimpanganMaster($k['budget'], $k['hasil'], null),
            'Tabel bernilai master tidak boleh melahirkan catatan "berbeda dari master".',
        );
    }

    public function test_hanya_satu_catatan_bila_hanya_satu_nilai_beda(): void
    {
        $hanyaDrift = new class extends TabelStandarWaktu
        {
            public function uHumanReactionRata(bool $lengkap = true): float
            {
                return parent::uHumanReactionRata(false);
            }
        };
        $kalk = new WaktuCalculator($hanyaDrift);
        $k = $this->titikSatu($kalk);

        $this->assertSame(
            ['penyimpangan_master.timer.drift_stopwatch_322_vs_298'],
            array_column($this->profil($kalk)->catatanPenyimpanganMaster($k['budget'], $k['hasil'], null), 'sumber'),
        );
    }
}
