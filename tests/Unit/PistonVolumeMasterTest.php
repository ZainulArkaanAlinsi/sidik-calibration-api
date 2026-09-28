<?php

namespace Tests\Unit;

use App\Services\Calibration\PistonVolumeCalculator as K;
use App\Services\Calibration\TabelStandarPistonVolume as Tabel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rekonsiliasi PISTON VOLUME ke kedua workbook master.
 *
 * Angka harapan dari `database/data/sesi-master-piston-volume.json`, keluaran
 * `docs/skrip/gen-tabel-standar-piston-volume.py` yang menolak menulis kalau
 * satu saja dari 274 sel master tidak tereproduksi. Toleransi
 * `1e-12 × max(1, |harapan|)` — jangan dilonggarkan untuk membuat hijau.
 */
class PistonVolumeMasterTest extends TestCase
{
    private const TOL = 1e-12;

    /** @return array<string, array{0: string, 1: string}> */
    public static function kasus(): array
    {
        return [
            'Fixed master' => ['fixed', 'harapan_master_excel'],
            'Fixed benar' => ['fixed', 'harapan_benar'],
            'Graduated master' => ['graduated', 'harapan_master_excel'],
            'Graduated benar' => ['graduated', 'harapan_benar'],
        ];
    }

    /** @return array<string, mixed> */
    private static function sesi(string $kel): array
    {
        $isi = json_decode((string) file_get_contents(database_path('data/sesi-master-piston-volume.json')), true);

        return $isi['sesi'][$kel];
    }

    private function sama(float|int|null $harap, float|int|null $dapat, string $label): void
    {
        if ($harap === null || $dapat === null) {
            $this->assertSame($harap, $dapat, $label);

            return;
        }

        $this->assertLessThanOrEqual(
            self::TOL * max(1.0, abs((float) $harap)),
            abs((float) $harap - (float) $dapat),
            sprintf('%s: harapan %.17g, dapat %.17g', $label, $harap, $dapat),
        );
    }

    #[DataProvider('kasus')]
    public function test_rantai_lengkap_sama_dengan_harapan(string $kel, string $kunci): void
    {
        $s = self::sesi($kel);
        $h = $s[$kunci];
        $mode = $kunci === 'harapan_benar' ? K::MODE_BENAR : K::MODE_MASTER;
        $r = (new K)->hitungSesi($s['masukan'], $mode);

        foreach ($h['per_titik'] as $i => $x) {
            $p = $r['per_titik'][$i];
            $t = $x['label'].' ';

            foreach (range(0, 9) as $j) {
                $this->sama($x['m'][$j], $p['m'][$j], $t."m{$j}");
                $this->sama($x['m_terkoreksi'][$j], $p['m_terkoreksi'][$j], $t."m'{$j}");
            }

            foreach (['m_rata', 'stdev', 'suhu_rata_baca', 'indeks_suhu', 'koreksi_meter', 'koreksi_sensor',
                't_rata', 'rho_air', 'V20', 'deviasi', 'deviasi_ul'] as $kn) {
                $this->sama($x[$kn], $p[$kn], $t.$kn);
            }

            $this->sama($x['t_terkoreksi'][0], $p['t_terkoreksi'][0], $t.'t1');
            $this->sama($x['t_terkoreksi'][1], $p['t_terkoreksi'][1], $t.'t2');
            $this->sama($x['rho_air_titik'][0], $p['rho_air_titik'][0], $t.'rho1');
            $this->sama($x['rho_air_titik'][1], $p['rho_air_titik'][1], $t.'rho2');
        }

        foreach (['rho_udara', 'maks_stdev', 'U_massa', 'U_suhu', 'U_rho', 'delta_suhu', 'delta_rho'] as $kn) {
            $this->sama($h[$kn], $r[$kn], $kn);
        }

        foreach ($h['komponen'] as $i => $k) {
            $d = $r['komponen'][$i];
            $this->assertSame($k['kode'], $d['sumber'], "urutan komponen ke-{$i}");

            foreach (['U', 'pembagi', 'vi', 'ci', 'u', 'uici'] as $kn) {
                $this->sama($k[$kn], $d[$kn], "{$k['kode']} {$kn}");
            }
        }

        $this->sama($h['uc'], $r['uc'], 'uc');
        $this->sama($h['veff'], $r['veff'], 'v_eff');
        $this->assertSame($h['df'], $r['df'], 'df bulat');
        $this->sama($h['k'], $r['k'], 'k');
        $this->sama($h['U'], $r['U'], 'U');
        $this->sama($h['cmc'], $r['cmc'], 'CMC');
        $this->sama($h['u95'], $r['u95'], 'U95 sertifikat');
    }

    /** Panduan eksternal menulis V20 Fixed = 10,031193123295512 dan k dicetak penuh. */
    public function test_fixed_v20_dan_koefisien_sensitivitas(): void
    {
        $r = (new K)->hitungSesi(self::sesi('fixed')['masukan']);

        $this->sama(10.031193123295512, $r['per_titik'][0]['V20'], 'V20');
        $this->sama(1.0023505094887732, $r['komponen'][0]['ci'], 'c1');
        $this->sama(-10.053587577111031, $r['komponen'][5]['ci'], 'c6');
        $this->assertSame(31, $r['df']);
    }

    /** Graduated: CMC 0,023 ml MENANG atas U hitung — lantai akreditasi. */
    public function test_graduated_cmc_menang(): void
    {
        $r = (new K)->hitungSesi(self::sesi('graduated')['masukan']);

        $this->assertLessThan(0.023, $r['U']);
        $this->sama(0.023, $r['u95'], 'U95 = CMC');
    }

    /** G-7: pipet 1,5 ml — master jatuh di celah pita, sistem memakai pita (1, 5]. */
    public function test_pita_cmc_kontinu_menutup_celah_bilangan_bulat(): void
    {
        $this->assertSame(0.0042, round((float) Tabel::cmc(Tabel::PISTON_PIPETTE, 1.5), 10));
        $this->assertSame(0.0083, round((float) Tabel::cmc(Tabel::PISTON_PIPETTE, 5.5), 10));
        $this->assertNull(Tabel::cmc(Tabel::PISTON_PIPETTE, 11.0), 'Di luar lampiran = tanpa lantai, bukan pita terakhir.');
    }

    /** MPE: nominal di luar tabel → null (verdict tidak terbit), bukan #N/A. */
    public function test_mpe_nominal_di_luar_tabel_null(): void
    {
        $this->assertSame(60.0, Tabel::mpe(Tabel::PISTON_PIPETTE, null, 10.0));
        $this->assertSame(30.0, Tabel::mpe(Tabel::BURET_DIGITAL, Tabel::HAND_DRIVEN, 10.0));
        $this->assertSame(20.0, Tabel::mpe(Tabel::BURET_DIGITAL, Tabel::MOTOR_DRIVEN, 10.0));
        $this->assertNull(Tabel::mpe(Tabel::PISTON_PIPETTE, null, 7.0));
        $this->assertNull(Tabel::mpe(Tabel::BURET_DIGITAL, null, 10.0), 'Sub-jenis buret wajib dipilih.');
        $this->assertNull(Tabel::mpe(Tabel::DISPENSETT, Tabel::SINGLE_STROKE, 0.001), 'Tabel master "-" = tidak ada MPE.');
    }

    /** Kumulatif yang satu digitnya salah: m̄ nyaris sama, STDEV membengkak — adendum OCR K-1. */
    public function test_satu_digit_kumulatif_salah_cuma_terlihat_di_stdev(): void
    {
        $m = self::sesi('fixed')['masukan'];
        $asli = (new K)->hitungSesi($m);
        $m['titik'][0]['kumulatif'][3] = 30.7368;
        $cacat = (new K)->hitungSesi($m);

        $this->assertEqualsWithDelta($asli['per_titik'][0]['m_rata'], $cacat['per_titik'][0]['m_rata'], 1e-12);
        $this->assertGreaterThan(9 * $asli['per_titik'][0]['stdev'], $cacat['per_titik'][0]['stdev']);
    }
}
