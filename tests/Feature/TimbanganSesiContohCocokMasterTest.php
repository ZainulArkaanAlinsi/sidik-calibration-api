<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenegakkanCetakMaster;
use Tests\Concerns\MenerbitkanSesiContoh;
use Tests\Concerns\PembandingCetakMaster;
use Tests\TestCase;

/**
 * Angka CETAK sertifikat Timbangan (kg, gram, substitusi) diadu ke angka yang
 * dicetak lembar `SERTIFIKAT` ketiga workbook master — lewat jalur produksi
 * (seeder -> `POST /approve` -> `DataTampilanSertifikat` -> `sertifikat.pdf`),
 * bukan lewat nilai antara. PK-4, 17 Nov 2026.
 *
 * ## Bahan
 *
 * Sesi `011-CAL-525` (kg), `019-CAL-425` (gram), `0136-CAL-123` (substitusi)
 * ditanam `TimbanganSeeder` dari `database/data/sesi-master-timbangan.json`.
 * Angka pembandingnya `tests/Fixtures/cetak-master/massa-volume.json`
 * (`docs/skrip/gen-cetak-master-massa-volume.py`): sel angka lembar
 * `SERTIFIKAT` + number_format master, teks cetaknya dicocokkan ke `Range.Text`
 * Excel — bukan tebakan pembulatan.
 *
 * ## Temuan yang dikunci di sini (bukan disetel supaya hijau)
 *
 * 1. **Sampel seeder tidak membawa dua masukan master**: Effect of Tare
 *    (`INPUT DATA!K40/O40/O41`) dan beban Loading Influence (`I67`/`I69`).
 *    Tanpa keduanya sertifikat mencetak `= — kg` dan kalimat tanpa angka, sedangkan
 *    master mencetak `0.00` dan `Weight mass 20 kg`. Test melengkapi kedua
 *    masukan dari master (jalur cetaknya diadu), dan menegaskan bahwa seeder
 *    memang belum membawanya — kalau seeder dilengkapi, test ini minta
 *    dilengkapi juga.
 * 2. **kg §7 Weighing Uncertainty**: master 0,043-0,053 kg, aplikasi 0,033 kg
 *    (lantai CMC) di sepuluh titik — keputusan pemilik 16 Sep 2026 (butir 3,
 *    `ui = U/k` untuk Uncertainty of Correction; `pertanyaan-lab-timbangan.md`
 *    T2). Gram dan substitusi tidak terkena karena lantai CMC menang di dua sisi.
 * 3. **kg & substitusi baris ketiga `Penuh`** (0 · 0 · 0) tidak dicetak:
 *    kerusakan salin-tempel master, aplikasi sengaja dua baris.
 *
 * ## Lingkup klaim GRAM (PK4-10)
 *
 * "Gram cocok 23/23" hanya terhadap master gram LAMA (`New Master Olda Timbangan gram.xlsm`,
 * sha256 `ce1a3936...`, yang dipakai seeder). Master gram revisi 29 Mei 2026 (`xlsm2`, sha256
 * `7b288cdb...`) TIDAK disentuh PK-4 — ditahan menunggu jawaban lab
 * (`docs/pertanyaan-lab-timbangan.md` T9/T11/T14) — dan terhadapnya 12 dari 68 sel gram
 * tercetak berbeda (mis. `U29` 0,00058 -> 0,00057; `M46` LOP 0,00077 -> 0,00030; `H49:H53` dan
 * `W49:W53` 0,00060 -> 0,00057). Jadi hijau di sini TIDAK membuktikan sertifikat gram sesuai
 * master yang sekarang dipegang lab.
 *
 * ## Pembulatan titik seri (PK4-9)
 *
 * Tidak ada nilai sampel yang jatuh tepat di titik seri pada jalur cetak Timbangan, jadi tes ini
 * TIDAK membuktikan pembulatan di sana. Jalur Timbangan memakai `Angka::id` (pembulatan nilai
 * biner eksak) sedangkan profil lain `Angka::tampilanExcel`; penandanya di
 * `TimbanganTitikSeriCetakTest`.
 */
class TimbanganSesiContohCocokMasterTest extends TestCase
{
    use MenegakkanCetakMaster;
    use MenerbitkanSesiContoh;
    use RefreshDatabase;

    /** Sama dengan `TimbanganMasterTest::SEL_MASTER_RUSAK`: master rusak, hitungan kita benar. */
    private const TITIK_MASTER_RUSAK = ['gram' => 9, 'sub' => 9];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSesiContoh();
    }

    /**
     * @return array<string, array{
     *     nomor: string, varian: string, cocok: int,
     *     selisih: array<int, array{0: string, 1: list<string>|null}>
     * }>
     */
    private static function kasus(): array
    {
        return [
            'timbangan/kg' => [
                'nomor' => '011-CAL-525', 'varian' => 'kg', 'cocok' => 18,
                'selisih' => [
                    21 => ['Master mencetak baris ketiga "Penuh" (0 · 0 · 0): rujukan ke kolom yang tidak ada di blok Repeatability, kerusakan salin-tempel. Aplikasi sengaja dua baris (TimbanganProfile::bagianSertifikatKeterulangan).', null],
                    49 => ['T2 / keputusan pemilik 16 Sep 2026: Uncertainty of Correction ÷ k, lantai CMC 0,033 menang. Master 0,043 / 0,049.', ['10.00', '0.033', '60.00', '0.033']],
                    50 => ['T2 (sama).', ['20.00', '0.033', '70.00', '0.033']],
                    51 => ['T2 (sama).', ['30.00', '0.033', '80.00', '0.033']],
                    52 => ['T2 (sama).', ['40.00', '0.033', '90.00', '0.033']],
                    53 => ['T2 (sama).', ['50.00', '0.033', '100.00', '0.033']],
                ],
            ],
            // Hanya terhadap master gram LAMA (sha256 ce1a3936...); xlsm2 (29 Mei 2026) tidak disentuh — PK4-10.
            'timbangan/gram' => [
                'nomor' => '019-CAL-425', 'varian' => 'gram', 'cocok' => 23,
                'selisih' => [],
            ],
            'timbangan/substitusi' => [
                'nomor' => '0136-CAL-123', 'varian' => 'sub', 'cocok' => 23,
                'selisih' => [
                    21 => ['Baris ketiga "Penuh" tidak dicetak (kerusakan salin-tempel master), sama dengan kg.', null],
                ],
            ],
        ];
    }

    public function test_angka_cetak_tiga_varian_sama_dengan_master_kecuali_temuan_yang_tercatat(): void
    {
        foreach (self::kasus() as $kunci => $k) {
            $masukan = PembandingCetakMaster::master($kunci)['masukan'];

            // Temuan 1: seeder belum membawa tare & beban eksentrisitas.
            $seeder = CalibrationSession::where('nomor_sesi', $k['nomor'])->firstOrFail()->spesifikasi_alat;
            $this->assertArrayNotHasKey(
                'effect_of_tare',
                $seeder,
                "{$kunci}: seeder sekarang membawa Effect of Tare — hapus pelengkap di test ini dan turunkan temuan 1.",
            );
            $this->assertNull($seeder['eksentrisitas']['beban'] ?? null, "{$kunci}: seeder sekarang membawa beban eksentrisitas.");

            [, $html] = $this->terbitkanDanRender($k['nomor'], function (CalibrationSession $sesi) use ($masukan): void {
                $spek = $sesi->spesifikasi_alat;
                $spek['effect_of_tare'] = [
                    'standar' => $masukan['standar']['nilai'],
                    'm1' => $masukan['m1']['nilai'],
                    'm2' => $masukan['m2']['nilai'],
                ];
                $spek['eksentrisitas']['beban'] = $masukan['beban']['nilai'];
                $sesi->update(['spesifikasi_alat' => $spek]);
            });

            $this->assertCetakSamaMaster($kunci, $html, $k['cocok'], $k['selisih']);
        }
    }

    /**
     * Hasil hitung YANG TERSIMPAN (`uncertainty_calculations`) sama dengan
     * master: budget koreksi per komponen (`ui × ci` dan `vi`), uc, veff, k,
     * dan U95 per titik — lewat jalur simpan/approve, bukan kalkulator
     * telanjang (itu `TimbanganMasterTest`).
     *
     * Dua titik master rusak (gram 9, substitusi 9) diadu ke arah yang benar:
     * hitungan kita tidak boleh LEBIH KECIL dari master.
     */
    public function test_hasil_tersimpan_budget_koreksi_sama_dengan_master(): void
    {
        $fixture = json_decode((string) file_get_contents(database_path('data/sesi-master-timbangan.json')), true);
        $diadu = 0;

        foreach (['011-CAL-525' => 'kg', '019-CAL-425' => 'gram', '0136-CAL-123' => 'sub'] as $nomor => $tag) {
            $sesi = CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
            $this->terbitkanDanRender($nomor);
            $hitungan = $sesi->fresh()->uncertaintyCalculations()->orderBy('titik_ke')->get();
            $harap = $fixture[$tag]['_budget']['koreksi'];

            $this->assertCount(count($harap), $hitungan, "{$tag}: jumlah titik tersimpan.");

            foreach ($hitungan as $i => $h) {
                $m = $harap[$i];
                $titik = $i + 1;
                $rusak = (self::TITIK_MASTER_RUSAK[$tag] ?? null) === $titik;
                $label = "{$tag} titik {$titik}";

                $this->assertNull($h->keputusan, "{$label}: master Timbangan tidak mencetak keputusan lulus/tidak; aplikasi pun tidak.");

                if ($rusak) {
                    $this->assertGreaterThanOrEqual((float) $m['U'] * (1 - 5e-6), (float) $h->ketidakpastian_gabungan * (float) $h->faktor_cakupan_k, "{$label}: U hitung tidak boleh lebih kecil dari master rusak.");

                    continue;
                }

                // Substitusi menyimpan koreksi KUMULATIF (kolom Correction sertifikat), dua varian lain koreksi titiknya.
                $koreksiMaster = $fixture[$tag]['_harap']['kumulatif'][$i] ?? $fixture[$tag]['_harap']['koreksi'][$i];
                $this->assertEqualsWithDelta((float) $koreksiMaster, (float) $h->koreksi, 5e-6, "{$label}: koreksi tersimpan");
                $this->assertEqualsWithDelta((float) $m['uc'], (float) $h->ketidakpastian_gabungan, 5e-6, "{$label}: uc");
                $this->assertEqualsWithDelta((float) $m['veff'], (float) $h->derajat_kebebasan_efektif, 5e-3, "{$label}: veff");
                $this->assertEqualsWithDelta((float) $m['k'], (float) $h->faktor_cakupan_k, 5e-6, "{$label}: k");
                $this->assertEqualsWithDelta((float) $m['u95'], (float) $h->ketidakpastian_diperluas, 5e-6, "{$label}: U95 tersimpan");

                $komponen = array_values(array_filter(
                    $h->type_b_components,
                    static fn (array $c): bool => ($c['budget'] ?? null) === 'koreksi',
                ));
                $this->assertCount(count($m['komponen']), $komponen, "{$label}: jumlah komponen budget koreksi.");

                foreach ($komponen as $j => $c) {
                    $mk = $m['komponen'][$j];
                    $this->assertEqualsWithDelta((float) $mk['ui'] * (float) ($mk['ci'] ?: 1), (float) $c['nilai'] * (float) $c['ci'], 5e-6, "{$label}: ui×ci komponen {$j} ({$c['keterangan']})");
                    $this->assertEqualsWithDelta((float) $mk['vi'], (float) $c['vi'], 5e-6, "{$label}: vi komponen {$j}");
                    $diadu += 2;
                }
            }
        }

        // Komponen budget koreksi: kg 10 titik x 9, gram 9 titik x 9 (titik 9 rusak di master),
        // substitusi 9 titik x 10 — masing-masing dua angka (ui×ci dan vi).
        $this->assertSame(2 * (90 + 81 + 90), $diadu, 'Jumlah angka komponen yang diadu berubah.');
    }
}
