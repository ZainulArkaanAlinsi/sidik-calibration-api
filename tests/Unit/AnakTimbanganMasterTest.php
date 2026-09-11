<?php

namespace Tests\Unit;

use App\Services\Calibration\AnakTimbanganCalculator;
use App\Services\Calibration\TabelStandarAnakTimbangan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adu `AnakTimbanganCalculator` ke master `1.1 Anak Timbangan F1 1mg-500 g
 * 202501022 imp.xlsx`, sesi contoh sertifikat `001-CAL-126`.
 *
 * Fixture-nya `tests/Fixtures/anak-timbangan-master.json` — dua puluh keping
 * berikut nilai master tiap tahapnya, digenerate dari CSV masternya. Penanda
 * keping di situ SINTETIS: master mencetak `-` dua puluh kali, dan nama serta
 * alamat pelanggan sengaja tidak ikut.
 *
 * ## Yang WAJIB cocok, dan yang wajib BEDA
 *
 * Test ini bukan "semuanya harus sama". Tiga tahap wajib cocok sampai 5·10⁻⁶
 * karena di situ kita meniru master; satu tahap wajib BERBEDA dengan arah dan
 * besar yang ditentukan, karena di situ master rusak rujukannya:
 *
 *  - `ms`, `de` — cocok. Tabel standar dan rantai ABBA ditiru apa adanya.
 *  - keenam komponen budget, `uc`, `veff`, `k`, `U95` — cocok. Metodenya ditiru,
 *    termasuk yang dipertanyakan (pertanyaan lab §1, §18, §20).
 *  - `b_jalur_master_g` — cocok. Ini nilai master yang sengaja tetap dihitung
 *    supaya selisihnya bisa dibaca.
 *  - `b_g` — BEDA, dan bedanya ditegakkan: koreksi yang benar wajib lebih KECIL
 *    daripada yang diterbitkan master, dengan rasio yang persis sama dengan
 *    `ms_titik / ms_keping_pertama`. Master mengalikan dengan massa keping 100 g
 *    alih-alih massa keping yang sedang dihitung (pertanyaan lab §2).
 *
 * Tanpa penegakan arah itu, "test hijau" cuma berarti kodenya konsisten dengan
 * dirinya sendiri.
 */
class AnakTimbanganMasterTest extends TestCase
{
    private const TOL = 5e-6;

    /** @var array<string, mixed> */
    private static array $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        self::$fixture ??= json_decode(
            (string) file_get_contents(__DIR__.'/../Fixtures/anak-timbangan-master.json'),
            true,
        );

        TabelStandarAnakTimbangan::lupakanCache();
    }

    #[Test]
    public function densitas_udara_cocok_dengan_master(): void
    {
        $kalk = new AnakTimbanganCalculator;
        $ling = self::$fixture['master_lingkungan'];

        $this->assertCocok(
            $ling['rho_udara'],
            $kalk->densitasUdara(
                $ling['suhu']['rata'],
                $ling['kelembaban']['rata'],
                $ling['tekanan']['rata'],
            ),
            'rho_udara',
        );
    }

    #[Test]
    public function rata_rata_lingkungan_memakai_kedua_ujung_bukan_yang_akhir(): void
    {
        $hasil = $this->hitung();
        $master = self::$fixture['master_lingkungan'];

        foreach (['suhu', 'kelembaban', 'tekanan'] as $besaran) {
            $this->assertCocok(
                $master[$besaran]['rata'],
                $hasil['lingkungan'][$besaran]['rata'],
                "{$besaran}.rata",
            );

            $this->assertCocok(
                $master[$besaran]['titik_indeks'],
                $hasil['lingkungan'][$besaran]['titik_indeks'],
                "{$besaran}.titik_indeks",
            );

            $this->assertCocok(
                $master[$besaran]['koreksi'],
                $hasil['lingkungan'][$besaran]['koreksi_meter'],
                "{$besaran}.koreksi_meter",
            );
        }
    }

    /**
     * Ketidakpastian tekanan yang tercetak DIBETULKAN — sertifikat master
     * memakai angka kelembaban meternya (3 hPa) untuk kolom tekanan, sementara
     * `PERHITUNGAN FC` di workbook yang sama memakai yang benar (2 hPa).
     * Pertanyaan lab §7.
     */
    #[Test]
    public function ketidakpastian_lingkungan_tercetak_cocok_dan_tekanan_bukan_angka_kelembaban(): void
    {
        $hasil = $this->hitung();
        $master = self::$fixture['master_lingkungan'];

        foreach (['suhu', 'kelembaban', 'tekanan'] as $besaran) {
            $this->assertCocok(
                $master[$besaran]['u95_tercetak'],
                $hasil['lingkungan'][$besaran]['u95_tercetak'],
                "{$besaran}.u95_tercetak",
            );
        }

        $this->assertGreaterThan(
            $hasil['lingkungan']['tekanan']['u95_tercetak'],
            sqrt(3 ** 2 + 0.1 ** 2),
            'Sertifikat master mencetak √(3²+0,1²) untuk tekanan — angka 3 itu milik kelembaban.',
        );
    }

    #[Test]
    public function ms_dan_de_cocok_untuk_setiap_titik_yang_terbit(): void
    {
        $hasil = $this->hitung();
        $master = $this->masterPerTitik();
        $diperiksa = 0;

        foreach ($hasil['titik'] as $t) {
            $m = $master[$t['titik_ke']];

            $this->assertCocok($m['ms_g'], $t['ms_g'], "titik {$t['titik_ke']} ms");
            $this->assertCocok($m['de_g'], $t['de_g'], "titik {$t['titik_ke']} de");
            $diperiksa += 2;
        }

        $this->assertSame(28, $diperiksa, 'Empat belas titik wajib terbit, dua nilai per titik.');
    }

    #[Test]
    public function keenam_komponen_budget_cocok_untuk_setiap_titik(): void
    {
        $hasil = $this->hitung();
        $master = $this->masterPerTitik();
        $diperiksa = 0;

        foreach ($hasil['titik'] as $t) {
            $komponenMaster = $master[$t['titik_ke']]['komponen'];

            $this->assertCount(6, $t['budget'], "titik {$t['titik_ke']} jumlah komponen");

            foreach ($t['budget'] as $i => $k) {
                $m = $komponenMaster[$i];
                $label = "titik {$t['titik_ke']} komponen {$k['sumber']}";

                $this->assertCocok($m['ui'], $k['u'], "{$label} ui");
                $this->assertCocok($m['ci'], $k['ci'], "{$label} ci");
                $this->assertCocok($m['vi'], $k['vi'], "{$label} vi");
                $this->assertCocok($m['uici'], $k['u'] * $k['ci'], "{$label} uici");
                $diperiksa += 4;
            }
        }

        $this->assertSame(14 * 6 * 4, $diperiksa);
    }

    #[Test]
    public function uc_veff_k_dan_u95_cocok_untuk_setiap_titik(): void
    {
        $hasil = $this->hitung();
        $master = $this->masterPerTitik();

        foreach ($hasil['titik'] as $t) {
            $m = $master[$t['titik_ke']];
            $label = "titik {$t['titik_ke']}";

            // Budget master miligram; kita menyimpan gram.
            $this->assertCocok($m['uc_mg'] / 1000, $t['ketidakpastian_gabungan_g'], "{$label} uc");
            $this->assertCocok($m['veff'], $t['derajat_kebebasan_efektif'], "{$label} veff");
            $this->assertCocok($m['k'], $t['faktor_cakupan_k'], "{$label} k");
            $this->assertCocok($m['u95_mg'] / 1000, $t['u95_g'], "{$label} U95");
        }
    }

    /**
     * Lantai CMC memang TIDAK ada — sel `CMC PT. SIDIK` kosong di kedua puluh
     * blok master, dan anak timbangan di luar lampiran LK-285-IDN
     * (pertanyaan lab §15 & §16).
     */
    #[Test]
    public function master_tidak_punya_lantai_cmc_di_satu_pun_blok(): void
    {
        foreach (self::$fixture['titik'] as $t) {
            $this->assertNull(
                $t['master']['cmc'],
                "Titik {$t['titik_ke']} master ternyata punya lantai CMC — asumsi §16 gugur.",
            );
        }
    }

    #[Test]
    public function jalur_master_koreksi_apung_direproduksi_persis(): void
    {
        $hasil = $this->hitung();
        $master = $this->masterPerTitik();
        $diperiksa = 0;

        foreach ($hasil['titik'] as $t) {
            $bMaster = $master[$t['titik_ke']]['b_g'];

            // Titik 2 & 3: master menerbitkan 0 karena rumusnya menunjuk sel
            // kosong (pertanyaan lab §5), bukan karena jalur `ms` keping
            // pertama. Yang diadu di sini cuma titik yang masternya menghitung.
            if ($bMaster === null || $bMaster === 0.0) {
                continue;
            }

            $this->assertCocok(
                $bMaster,
                $t['b_jalur_master_g'],
                "titik {$t['titik_ke']} b jalur master",
            );
            $diperiksa++;
        }

        $this->assertSame(12, $diperiksa, 'Dua belas titik masternya menghitung koreksi apung.');
    }

    /** Inti perbaikan §2 — dan penegakan ARAHNYA, bukan cuma "berbeda". */
    #[Test]
    public function koreksi_apung_yang_benar_lebih_kecil_daripada_yang_master_terbitkan(): void
    {
        $hasil = $this->hitung();
        $master = $this->masterPerTitik();
        $diperiksa = 0;

        foreach ($hasil['titik'] as $t) {
            $bMaster = $master[$t['titik_ke']]['b_g'];

            if ($bMaster === null || $bMaster === 0.0) {
                continue;
            }

            // Titik 1 memang keping 100 g itu sendiri, jadi kedua jalur berimpit.
            if ((float) $t['nominal_g'] === 100.0) {
                $this->assertCocok($bMaster, $t['b_g'], 'titik 1 b (keping 100 g, jalurnya berimpit)');

                continue;
            }

            $this->assertLessThan(
                abs($bMaster),
                abs($t['b_g']),
                "Titik {$t['titik_ke']}: koreksi apung yang benar wajib lebih kecil daripada "
                .'jalur master yang mengalikan dengan massa keping 100 g.',
            );

            // Rasionya = ms_titik / ms_keping_pertama, bukan angka sembarang.
            $this->assertCocok(
                $t['ms_g'] / 100.000144,
                $t['b_g'] / $bMaster,
                "titik {$t['titik_ke']} rasio b benar : b master",
            );
            $diperiksa++;
        }

        $this->assertSame(11, $diperiksa);
    }

    #[Test]
    public function massa_konvensional_adalah_ms_ditambah_de_ditambah_b(): void
    {
        foreach ($this->hitung()['titik'] as $t) {
            $this->assertCocok(
                $t['ms_g'] + $t['de_g'] + $t['b_g'],
                $t['mt_g'],
                "titik {$t['titik_ke']} mT",
            );
        }
    }

    /**
     * Lima keping yang master terbitkan sebagai `#VALUE!` DAN keping 10 g yang
     * terbit 45 % meleset wajib DITOLAK di sini — bukan diterbitkan.
     */
    #[Test]
    public function titik_yang_master_terbitkan_rusak_ditolak_dengan_alasan_yang_kebaca(): void
    {
        $hasil = $this->hitung();
        $ditolak = [];

        foreach ($hasil['ditolak'] as $d) {
            $ditolak[(int) $d['titik_ke']] = (string) $d['alasan'];
        }

        // Titik 4-8: densitas kelas F1 tidak ada di tabel (§4).
        foreach ([4, 5, 6, 7, 8] as $titikKe) {
            $this->assertArrayHasKey($titikKe, $ditolak, "Titik {$titikKe} harusnya ditolak.");
            $this->assertStringContainsString(
                'tabel densitas',
                $ditolak[$titikKe],
                "Alasan titik {$titikKe} harus menyebut tabel densitas.",
            );
        }

        // Titik 17: T1 = 0,9998 untuk keping 10 g (§3).
        $this->assertArrayHasKey(17, $ditolak, 'Keping 10 g yang salah ketik harusnya ditolak.');
        $this->assertStringContainsString('|de|', $ditolak[17]);

        $this->assertCount(6, $hasil['ditolak'], 'Enam titik ditolak, tidak lebih tidak kurang.');
        $this->assertCount(14, $hasil['titik']);
    }

    /**
     * Keping 10 g yang master terbitkan 5,500163 g memang akan lahir seperti itu
     * kalau gerbangnya dicabut — jadi gerbangnya MENGGIGIT, bukan hiasan. Dan
     * ambangnya tidak menyentuh satu pun titik sehat.
     */
    #[Test]
    public function ambang_de_memisahkan_salah_ketik_dari_titik_sehat_dengan_jarak_lebar(): void
    {
        $titik = null;

        foreach (self::$fixture['titik'] as $t) {
            if ((int) $t['titik_ke'] === 17) {
                $titik = $t;
            }
        }

        $de = ($titik['at_t1'] - $titik['at_s1'] - $titik['at_s2'] + $titik['at_t2']) / 2;
        $mpe = TabelStandarAnakTimbangan::mpe(10.0, 'F1');

        $this->assertCocok(-4.50005, $de, 'de titik 17');
        $this->assertSame(0.2, $mpe, 'MPE F1 10 g');
        $this->assertGreaterThan(
            AnakTimbanganCalculator::AMBANG_DE_KALI_MPE * $mpe,
            abs($de) * 1000,
            'Ambang 10× MPE wajib dilewati titik 17.',
        );

        $terjauh = 0.0;

        foreach (self::$fixture['titik'] as $t) {
            if ((int) $t['titik_ke'] === 17) {
                continue;
            }

            $d = abs(($t['at_t1'] - $t['at_s1'] - $t['at_s2'] + $t['at_t2']) / 2) * 1000;
            $mpeT = TabelStandarAnakTimbangan::mpe((float) $t['nominal_g'], 'F1');

            if ($mpeT !== null) {
                $terjauh = max($terjauh, $d / $mpeT);
            }
        }

        $this->assertLessThan(
            AnakTimbanganCalculator::AMBANG_DE_KALI_MPE,
            $terjauh,
            'Ambangnya harus tidak menyentuh satu pun titik sehat.',
        );
    }

    /** @return array<string, mixed> */
    private function hitung(): array
    {
        $fx = self::$fixture;

        $titik = array_map(static fn (array $t): array => [
            'titik_ke' => (int) $t['titik_ke'],
            'nominal_g' => (float) $t['nominal_g'],
            'at_s1' => $t['at_s1'],
            'at_t1' => $t['at_t1'],
            'at_t2' => $t['at_t2'],
            'at_s2' => $t['at_s2'],
        ], $fx['titik']);

        $k = $fx['konteks'];
        $identitas = [];

        foreach ($k['identitas'] as $titikKe => $penanda) {
            $identitas[(int) $titikKe] = (string) $penanda;
        }

        return (new AnakTimbanganCalculator)->hitungSesi($titik, [
            'kelas_uut' => $k['kelas_uut'],
            'kelas_standar' => $k['kelas_standar'],
            'timbangan' => $k['timbangan'],
            'meter_lingkungan' => $k['meter_lingkungan'],
            'suhu' => ['awal' => $k['suhu_awal'], 'akhir' => $k['suhu_akhir']],
            'kelembaban' => ['awal' => $k['kelembaban_awal'], 'akhir' => $k['kelembaban_akhir']],
            'tekanan' => ['awal' => $k['tekanan_awal'], 'akhir' => $k['tekanan_akhir']],
            'identitas' => $identitas,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function masterPerTitik(): array
    {
        $peta = [];

        foreach (self::$fixture['titik'] as $t) {
            $peta[(int) $t['titik_ke']] = $t['master'];
        }

        return $peta;
    }

    private function assertCocok(?float $master, ?float $kita, string $label): void
    {
        if ($master === null) {
            $this->assertNull($kita, "{$label}: master null, kita bukan.");

            return;
        }

        $this->assertNotNull($kita, "{$label}: kita null, master {$master}.");

        $beda = abs($kita - $master);
        $rel = $master === 0.0 ? $beda : $beda / abs($master);

        $this->assertTrue(
            $beda <= self::TOL || $rel <= self::TOL,
            sprintf('%s: kita=%.17g master=%.17g beda=%.3e rel=%.3e', $label, $kita, $master, $beda, $rel),
        );
    }
}
