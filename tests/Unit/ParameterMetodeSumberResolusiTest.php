<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Calibration\Profiles\RefractometerProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PenetapanParameterUji;
use Tests\TestCase;

/**
 * Parameter `refractometer.sumber_resolusi` (butir TM refractometer#15): dari mana
 * resolusi budget diambil, yaitu komponen "Daya baca" (`resolusi / 2 / akar 3`) dan `ci`
 * komponen suhu (`ci = resolusi`, ikut sel master).
 *
 *  - `konstanta` (bawaan, perilaku kode): `RefractometerProfile::resolusiTitik()` = 0,0001,
 *    data alat tidak dibaca;
 *  - `isian_teknisi`: `equipments.resolusi` bila terisi dan > 0, selain itu konstanta;
 *  - `lebih_besar`: yang terbesar dari konstanta dan isian, tidak pernah di bawah konstanta.
 *
 * Spektrofotometer sengaja tidak punya pasangan parameter ini: tidak ada sumber resolusi
 * numerik yang aman dibaca (lihat laporan pekerjaan).
 *
 * Penetapannya disimulasikan, BUKAN keputusan Manajer Teknis.
 */
class ParameterMetodeSumberResolusiTest extends TestCase
{
    use PenetapanParameterUji;

    private const KUNCI = 'refractometer.sumber_resolusi';

    private const KONSTANTA = 0.0001;

    private const BERLAKU = '2026-12-01';

    private const SESUDAH = '2026-12-05';

    private RefractometerProfile $profil;

    protected function setUp(): void
    {
        parent::setUp();
        ParameterKeputusanMetode::lupakan();
        $this->profil = new RefractometerProfile;
    }

    protected function tearDown(): void
    {
        ParameterKeputusanMetode::lupakan();
        parent::tearDown();
    }

    /**
     * @return array<string, array{float|null, string, float}> isian alat, pilihan, resolusi budget yang diharapkan
     */
    public static function kasus(): array
    {
        return [
            'isian lebih kasar: isian_teknisi memakai isian' => [0.001, 'isian_teknisi', 0.001],
            'isian lebih kasar: lebih_besar memakai isian' => [0.001, 'lebih_besar', 0.001],
            'isian lebih halus: isian_teknisi memakai isian' => [0.00001, 'isian_teknisi', 0.00001],
            'isian lebih halus: lebih_besar tetap konstanta' => [0.00001, 'lebih_besar', self::KONSTANTA],
            'isian sama dengan konstanta' => [0.0001, 'isian_teknisi', 0.0001],
            'isian kosong: isian_teknisi jatuh ke konstanta' => [null, 'isian_teknisi', self::KONSTANTA],
            'isian nol: isian_teknisi jatuh ke konstanta' => [0.0, 'isian_teknisi', self::KONSTANTA],
            'isian kosong: lebih_besar tetap konstanta' => [null, 'lebih_besar', self::KONSTANTA],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function komponen(?float $resolusiAlat): array
    {
        $kem = new CalibrationCapability(['nama_alat' => 'Refractometer', 'ketidakpastian_terbaik' => 6.2e-05, 'u_temperature' => sqrt((0.07 / 2) ** 2 + (0.036 / 2) ** 2)]);
        $alat = new Equipment(['satuan' => 'n20D', 'resolusi' => $resolusiAlat]);
        $std = new Standard(['nama' => 'Refractometer Std Solution 1.33659 n20D', 'ketidakpastian' => 2.6e-05, 'faktor_cakupan' => 2]);

        return $this->profil->komponenBudget($kem, $alat, $std, 1.33659, 0.0, 5, 21.0);
    }

    /** @return array<string, array<string, mixed>> */
    private function perSumber(array $komponen): array
    {
        return array_column($komponen, null, 'sumber');
    }

    public function test_bawaan_memakai_konstanta_tanpa_membaca_data_alat(): void
    {
        $this->pakaiPenetapan(self::KUNCI, 'lebih_besar', self::BERLAKU);

        // Tanpa lingkup tanggal dan SEBELUM penetapan berlaku: jawabannya bawaan, apa pun isian alatnya.
        foreach ([0.001, 0.00001, null] as $isian) {
            $k = $this->perSumber($this->komponen($isian));

            $this->assertSame((self::KONSTANTA / 2.0) / sqrt(3), $k['resolusi_alat']['u']);
            $this->assertSame(self::KONSTANTA, $k['ketidakpastian_temperature']['ci']);
        }

        $sebelum = app(ParameterKeputusanMetode::class)->untukTanggal('2026-11-30', fn (): array => $this->komponen(0.001));
        $this->assertSame($this->komponen(0.001), $sebelum);
    }

    #[DataProvider('kasus')]
    public function test_pilihan_non_bawaan_menurut_isian_alat(?float $isian, string $pilihan, float $harap): void
    {
        $this->pakaiPenetapan(self::KUNCI, $pilihan, self::BERLAKU);

        $k = $this->perSumber(app(ParameterKeputusanMetode::class)->untukTanggal(self::SESUDAH, fn (): array => $this->komponen($isian)));

        $this->assertSame(($harap / 2.0) / sqrt(3), $k['resolusi_alat']['u'], 'Komponen Daya baca.');
        $this->assertSame($harap, $k['ketidakpastian_temperature']['ci'], 'ci komponen suhu = resolusi (ikut sel master).');
        $this->assertStringContainsString((string) $harap, $k['resolusi_alat']['keterangan']);
    }

    public function test_pilihan_ini_hanya_menggeser_dua_komponen_resolusi(): void
    {
        $bawaan = $this->perSumber($this->komponen(0.001));

        $this->pakaiPenetapan(self::KUNCI, 'isian_teknisi', self::BERLAKU);
        $baru = $this->perSumber(app(ParameterKeputusanMetode::class)->untukTanggal(self::SESUDAH, fn (): array => $this->komponen(0.001)));

        foreach (['ketidakpastian_standar', 'perbedaan_suhu', 'pengulangan_pembacaan'] as $sumber) {
            $this->assertSame($bawaan[$sumber], $baru[$sumber], "{$sumber} tidak boleh bergeser.");
        }

        $this->assertSame($bawaan['ketidakpastian_temperature']['u'], $baru['ketidakpastian_temperature']['u']);
        $this->assertNotSame($bawaan['resolusi_alat']['u'], $baru['resolusi_alat']['u']);
    }
}
