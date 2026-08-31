<?php

namespace Tests\Unit;

use App\Services\Calibration\TabelStandarTimbangan;
use App\Services\Calibration\TimbanganCalculator;
use App\Services\Calibration\VarianMasterTimbangan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adu mesin hitung Timbangan ke TIGA workbook master ber-password yang turun
 * dari lab 31 Agt 2026 — sel demi sel, bukan cuma angka akhirnya.
 *
 * Fixture `tests/Fixtures/timbangan-master.json` memuat, per workbook:
 * pembacaan mentah tiap blok (akurasi, keterulangan, eksentrisitas,
 * histeresis), DAN nilai yang diharapkan untuk tiap kolom turunan plus tiap
 * komponen kedua budget. Total **1.200 angka**.
 *
 * ## Kenapa tiap KOMPONEN diadu, bukan cuma U95-nya
 *
 * U95 akhir bisa cocok sementara isinya salah — dua komponen yang saling
 * menutupi menghasilkan `uc` yang sama. Pelajaran itu sudah mahal sekali di
 * repo ini (§11: perbaikan hitung ulang yang "sukses" tanpa menghitung apa
 * pun). Jadi yang ditegakkan `ui × ci` DAN `vi` tiap baris budget, baru
 * `uc`, `veff`, `k`, `U`, dan `U95`.
 *
 * ## Dua titik yang SENGAJA beda dari master — dan kenapa
 *
 * Dua sel di master rusak, dan dua-duanya membuat U95 terbit lebih KECIL:
 *
 *  - **gram titik 9** — rujukan Mass 2 & Mass 3 di sheet `PERHITUNGAN U95% -
 *    Correction` menunjuk `FC!B80` & `FC!B81`, tiga baris terlalu jauh ke
 *    bawah (blok titik 9 ada di baris 76–78). Sel yang ditunjuk kosong, jadi
 *    keping 20 g & 5 g titik itu menyumbang drift NOL.
 *  - **substitusi titik 9** — komponen `Weight Standard` dibaca dari
 *    `'[3]PERHITUNGAN FC'!F74:G76`, yaitu **workbook lain** (master kg) lewat
 *    tautan luar yang nilainya tinggal cache. Hasilnya 2,4055e-5 alih-alih
 *    0,01348.
 *
 * Dua-duanya kerusakan salin-tempel yang perilaku benarnya tidak ambigu —
 * sembilan titik tetangga di berkas yang sama melakukannya dengan benar. Jadi
 * ditiru DAN dilaporkan bukan pilihan yang masuk akal di sini: yang dilakukan
 * menghitungnya BENAR, lalu menuliskan selisihnya di sini dan di
 * `docs/pertanyaan-lab-timbangan.md` (T6 & T7) supaya lab bisa menilai.
 *
 * Selisihnya kecil dan arahnya aman (U95 kita lebih besar): gram titik 9
 * 0,00057460 → 0,00057477 g; substitusi titik 9 0,27978 → 0,28014 kg, dan di
 * situ lantai CMC 0,52 kg menang sehingga sertifikatnya tidak bergerak sama
 * sekali.
 */
class TimbanganMasterTest extends TestCase
{
    /** Sama dengan yang dipakai Suhu3AlatMasterTest. */
    private const TOLERANSI = 5e-6;

    /**
     * Titik yang selnya rusak di master — diadu ke angka BENAR, bukan ke
     * angka master. Kuncinya `varian:titik_ke`.
     */
    private const SEL_MASTER_RUSAK = [
        'gram:9' => 'rujukan Mass 2 & 3 menunjuk tiga baris terlalu jauh (FC!B80/B81)',
        'sub:9' => "komponen Weight Standard dibaca dari workbook lain lewat tautan luar '[3]'",
    ];

    /** @return array<string, array{string}> */
    public static function varian(): array
    {
        return ['kg' => ['kg'], 'gram' => ['gram'], 'substitusi' => ['sub']];
    }

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        // Berkas yang SAMA dipakai `TimbanganSeeder` buat membuat sesi demo.
        // Dipisah jadi dua salinan, sesi demo dan angka acuan bisa berbeda
        // diam-diam — dan yang merah bukan angkanya, melainkan tidak ada.
        $berkas = database_path('data/sesi-master-timbangan.json');
        static $isi = null;

        return $isi ??= json_decode((string) file_get_contents($berkas), true);
    }

    #[DataProvider('varian')]
    public function test_kolom_turunan_akurasi_cocok_master(string $tag): void
    {
        $sesi = self::fixture()[$tag];
        $harap = $sesi['_harap'];
        $hasil = (new TimbanganCalculator)->hitung($this->masukan($sesi));

        $this->assertSame(
            count($harap['total_cn']),
            count($hasil['titik']),
            "Jumlah titik akurasi {$tag} nggak sama dengan master.",
        );

        foreach ($hasil['titik'] as $i => $t) {
            $this->dekat($t['titik_ukur'], $harap['total_cn'][$i], "{$tag} titik ".($i + 1).' total CN');
            $this->dekat($t['koreksi'], $harap['koreksi'][$i], "{$tag} titik ".($i + 1).' koreksi');
            $this->dekat($t['sr'], $harap['sr'][$i], "{$tag} titik ".($i + 1).' Sr');

            if (($harap['kumulatif'] ?? null) !== null) {
                $this->dekat(
                    $t['koreksi_kumulatif'],
                    $harap['kumulatif'][$i],
                    "{$tag} titik ".($i + 1).' koreksi kumulatif',
                );
            }
        }
    }

    #[DataProvider('varian')]
    public function test_tiap_komponen_kedua_budget_cocok_master(string $tag): void
    {
        $sesi = self::fixture()[$tag];
        $hasil = (new TimbanganCalculator)->hitung($this->masukan($sesi));
        $diadu = 0;

        foreach ([
            'koreksi' => ['budget_koreksi', 'uc_koreksi', 'veff_koreksi', 'k_koreksi', 'u95_koreksi_hitung'],
            'penimbangan' => ['budget_penimbangan', 'uc_penimbangan', 'veff_penimbangan', 'k_penimbangan', 'u95_penimbangan_hitung'],
        ] as $nama => [$kBudget, $kUc, $kVeff, $kK, $kU]) {
            foreach ($hasil['titik'] as $i => $t) {
                $harap = $sesi['_budget'][$nama][$i] ?? null;

                if ($harap === null) {
                    continue;
                }

                $rusak = self::SEL_MASTER_RUSAK[$tag.':'.($i + 1)] ?? null;

                if ($rusak !== null) {
                    // Titik ini SENGAJA beda — yang ditegakkan cuma bahwa
                    // hitungan kita LEBIH BESAR (arah aman), bukan sama.
                    $this->assertGreaterThan(
                        (float) $harap['U'] * (1 - self::TOLERANSI),
                        (float) $t[$kU],
                        "{$tag} titik ".($i + 1)." {$nama}: master rusak ({$rusak}), tapi hitungan kita "
                        .'malah lebih KECIL dari master. Arahnya harus sebaliknya.',
                    );

                    continue;
                }

                $this->assertSame(
                    count($harap['komponen']),
                    count($t[$kBudget]),
                    "{$tag} titik ".($i + 1)." {$nama}: jumlah komponen budget beda dari master.",
                );

                foreach ($t[$kBudget] as $j => $komp) {
                    $m = $harap['komponen'][$j];
                    $this->dekat(
                        $komp['u'] * $komp['ci'],
                        (float) $m['ui'] * (float) ($m['ci'] ?: 1),
                        "{$tag} titik ".($i + 1)." {$nama} ui×ci [{$j}] {$komp['keterangan']}",
                    );
                    $this->dekat(
                        (float) $komp['vi'],
                        (float) $m['vi'],
                        "{$tag} titik ".($i + 1)." {$nama} vi [{$j}] {$komp['keterangan']}",
                    );
                    $diadu += 2;
                }

                $this->dekat($t[$kUc], $harap['uc'], "{$tag} titik ".($i + 1)." {$nama} uc");
                $this->dekat($t[$kVeff], $harap['veff'], "{$tag} titik ".($i + 1)." {$nama} veff");
                $this->dekat($t[$kK], $harap['k'], "{$tag} titik ".($i + 1)." {$nama} k");
                $this->dekat($t[$kU], $harap['U'], "{$tag} titik ".($i + 1)." {$nama} U");
                $diadu += 4;
            }
        }

        // Penjagaan atas test-nya sendiri: kalau fixture-nya kosong atau
        // strukturnya berubah, assert di atas nggak pernah jalan dan test
        // tetap hijau. Sudah kejadian sekali di repo ini (§11).
        $this->assertGreaterThan(150, $diadu, "Cuma {$diadu} angka yang diadu buat {$tag} — fixture-nya curiga kosong.");
    }

    /**
     * Titik yang selnya rusak di master TETAP dihitung — bukan diblokir, dan
     * bukan diam-diam dibiarkan nol.
     */
    public function test_titik_bersel_rusak_tetap_terhitung(): void
    {
        foreach (self::SEL_MASTER_RUSAK as $kunci => $sebab) {
            [$tag, $ke] = explode(':', $kunci);
            $hasil = (new TimbanganCalculator)->hitung($this->masukan(self::fixture()[$tag]));

            $titik = null;
            foreach ($hasil['titik'] as $t) {
                if ($t['titik_ke'] === (int) $ke) {
                    $titik = $t;
                }
            }

            $this->assertNotNull($titik, "Titik {$ke} varian {$tag} nggak kehitung sama sekali ({$sebab}).");
            $this->assertGreaterThan(0.0, $titik['u95_koreksi'], "U95 titik {$ke} varian {$tag} nol.");
        }
    }

    /** Nominal yang nggak ada di tabel anak timbangan DIBLOKIR, bukan dibaca nol. */
    public function test_nominal_asing_diblokir_bukan_dianggap_nol(): void
    {
        $sesi = $this->masukan(self::fixture()['kg']);
        $sesi['akurasi'][0]['nominal'] = [7.5];   // nggak ada keping 7,5 kg di lab

        $hasil = (new TimbanganCalculator)->hitung($sesi);

        $this->assertCount(1, $hasil['belum_dihitung']);
        $this->assertSame(1, $hasil['belum_dihitung'][0]['titik_ke']);
        $this->assertStringContainsString('nggak ada di tabel standar lab', $hasil['belum_dihitung'][0]['alasan']);
        $this->assertCount(9, $hasil['titik'], 'Titik lain harusnya tetap kehitung.');
    }

    /**
     * Urutan Mass 1..6 itu KOLOM-MAJOR, dan slot drift harus mengikutinya.
     *
     * Diadu LANGSUNG ke slotnya, bukan lewat `uc`: `uc` itu akar jumlah
     * kuadrat, jadi menukar urutan komponen TIDAK menggesernya sama sekali
     * selama `ci`-nya sama. Test yang mengadu `uc` bakal hijau untuk urutan
     * yang salah — persis kelas kesalahan yang bikin §11 mahal.
     *
     * Titik kg ke-7 memakai 20 + 20 + 20 kg (kolom kiri) + 10 kg (kolom
     * kanan). Kalau dibaca baris-major urutannya jadi 20, 10, 20, 20.
     */
    public function test_slot_drift_mengikuti_urutan_kolom_major(): void
    {
        $hasil = (new TimbanganCalculator)->hitung($this->masukan(self::fixture()['kg']));

        $drift20 = TabelStandarTimbangan::cariMassa(20.0, VarianMasterTimbangan::KG)['u_drift'];
        $drift10 = TabelStandarTimbangan::cariMassa(10.0, VarianMasterTimbangan::KG)['u_drift'];

        $this->assertNotEqualsWithDelta($drift20, $drift10, 1e-12, 'Dua keping ini harus beda drift.');

        $slot = [];
        foreach ($hasil['titik'][6]['budget_koreksi'] as $k) {
            if ($k['sumber'] === 'mass_instability') {
                $slot[] = $k['u'];
            }
        }

        $this->assertSame([$drift20, $drift20, $drift20, $drift10, 0.0, 0.0], $slot);
    }

    /**
     * Varian substitusi memberi `ci` = 10 ke slot drift PERTAMA (Mref) saja.
     * Di sinilah urutan benar-benar menggeser `uc` — dan test ini yang
     * membuktikannya menggigit.
     */
    public function test_ci_sepuluh_cuma_di_slot_mref(): void
    {
        $hasil = (new TimbanganCalculator)->hitung($this->masukan(self::fixture()['sub']));

        $ci = [];
        foreach ($hasil['titik'][1]['budget_koreksi'] as $k) {
            if ($k['sumber'] === 'mass_instability') {
                $ci[] = $k['ci'];
            }
        }

        $this->assertSame([10.0, 1.0, 1.0, 1.0, 1.0, 1.0], $ci);
    }

    /** Ketiga varian punya tabel anak timbangannya sendiri, dan memang beda. */
    public function test_tiga_varian_punya_tabel_massa_yang_beda(): void
    {
        $kg = TabelStandarTimbangan::cariMassa(0.1, VarianMasterTimbangan::KG, TabelStandarTimbangan::ANALYTICAL);
        $gram = TabelStandarTimbangan::cariMassa(100.0, VarianMasterTimbangan::GRAM, TabelStandarTimbangan::ANALYTICAL);
        $sub = TabelStandarTimbangan::cariMassa(0.1, VarianMasterTimbangan::SUBSTITUSI, TabelStandarTimbangan::ANALYTICAL);

        $this->assertNotNull($kg);
        $this->assertNotNull($gram);
        $this->assertNotNull($sub);

        // Keping fisik yang SAMA (E2 100 g), tiga sertifikat yang beda.
        $this->assertEqualsWithDelta(100.0004, $kg['konvensional'] * 1000, 1e-9);
        $this->assertEqualsWithDelta(100.000033, $gram['konvensional'], 1e-9);
        $this->assertEqualsWithDelta(99.999984, $sub['konvensional'] * 1000, 1e-9);
    }

    /** Tipe Timbangan yang memilih tabel — bukan satuan, bukan nominal. */
    public function test_tipe_timbangan_memilih_tabel_e2_atau_f1(): void
    {
        $analytical = TabelStandarTimbangan::cariMassa(
            20.0, VarianMasterTimbangan::GRAM, TabelStandarTimbangan::ANALYTICAL,
        );
        $non = TabelStandarTimbangan::cariMassa(
            20.0, VarianMasterTimbangan::GRAM, TabelStandarTimbangan::NON_ANALYTICAL,
        );

        $this->assertEqualsWithDelta(20.000001, $analytical['konvensional'], 1e-9, 'Analytical harus baca E2.');
        $this->assertEqualsWithDelta(19.99999, $non['konvensional'], 1e-9, 'Non-Analytical harus baca F1.');
    }

    /** @param array<string, mixed> $sesi @return array<string, mixed> */
    private function masukan(array $sesi): array
    {
        unset($sesi['_harap'], $sesi['_budget']);

        return $sesi;
    }

    private function dekat(mixed $dapat, mixed $harap, string $pesan): void
    {
        if (! is_numeric($harap) || ! is_numeric($dapat)) {
            return;
        }

        $this->assertEqualsWithDelta(
            (float) $harap,
            (float) $dapat,
            self::TOLERANSI * max(abs((float) $harap), 1e-9) + 1e-12,
            $pesan,
        );
    }
}
