<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\Profiles\ChlorineProfile;
use App\Services\Calibration\Profiles\PhMeterProfile;
use App\Services\Calibration\Profiles\TurbidimeterProfile;
use App\Services\GumCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pengulangan boleh berapa kali, bukan wajib 5 — dan hasilnya tetap bener.
 *
 * ## Kenapa tes ini ada
 *
 * Lembar kerja nyetak 5 kolom, jadi gampang dikira angka 5 itu ikut ke dalam
 * rumus — "dibagi 5" gara-gara kolomnya lima. Kalau iya, teknisi yang cuma
 * sempat ngambil 3 pembacaan bakal dapet rata-rata yang dibagi 5 (dua nol ikut
 * kehitung) dan Type A yang dibagi √5. Dua-duanya bikin sertifikat salah tanpa
 * ada error apa pun yang muncul.
 *
 * Yang bener: 5 itu cuma jumlah kolom yang DIGAMBAR
 * (`JUMLAH_PENGULANGAN` di template & profil). Yang masuk rumus selalu jumlah
 * pembacaan yang BENERAN diisi — kolom kosong dibuang sebelum disimpan
 * (`CalibrationController`), jadi `count()` cuma ngitung yang ada isinya.
 *
 * Tes ini ngunci itu buat KETIGA alat sekaligus, karena tiap alat punya profil
 * budget sendiri dan tiap profil nerima `$typeA` + `$n` masing-masing.
 *
 * Angka acuannya dihitung tangan, bukan disalin dari keluaran kode.
 */
class PengulanganBebasTest extends TestCase
{
    private GumCalculator $gum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gum = new GumCalculator;
    }

    /**
     * Standar deviasi sampel (pembagi n−1), dihitung terpisah dari kode yang
     * diuji. Kalau dua-duanya salah dengan cara yang sama, tesnya nggak ada
     * gunanya — makanya ditulis ulang di sini.
     *
     * @param  list<float>  $nilai
     */
    private function stdevSampel(array $nilai): float
    {
        $n = count($nilai);
        $rata = array_sum($nilai) / $n;
        $jumlah = 0.0;
        foreach ($nilai as $x) {
            $jumlah += ($x - $rata) ** 2;
        }

        return sqrt($jumlah / ($n - 1));
    }

    /** @return list<array{list<float>, int}> */
    public static function jumlahPembacaan(): array
    {
        return [
            'dua kali (minimum)' => [[7.01, 7.03], 2],
            'tiga kali' => [[7.01, 7.03, 7.02], 3],
            'empat kali' => [[7.01, 7.03, 7.02, 7.04], 4],
            'lima kali (bawaan lembar kerja)' => [[7.01, 7.03, 7.02, 7.04, 7.00], 5],
            'tujuh kali' => [[7.01, 7.03, 7.02, 7.04, 7.00, 7.02, 7.01], 7],
        ];
    }

    /**
     * Rata-rata dibagi n YANG DIISI, dan Type A pakai √n yang sama.
     *
     * Ini inti keluhannya: "kan nanti datanya dibagi 5 gara-gara pengulangan 5
     * kali". Kalau benar begitu, `dua kali` di bawah bakal ngasih rata-rata
     * 2,808 (14,04 ÷ 5) — bukan 7,02.
     *
     * @param  list<float>  $pembacaan
     */
    #[DataProvider('jumlahPembacaan')]
    public function test_rata_rata_dan_type_a_ikut_jumlah_yang_diisi(array $pembacaan, int $n): void
    {
        $hasil = $this->gum->hitungTitik(
            1, 7.0, $pembacaan,
            new Equipment(['nama_alat' => 'pH Meter', 'satuan' => 'pH', 'resolusi' => 0.01, 'toleransi' => 0.05]),
            new Standard(['nama' => 'Buffer 7', 'ketidakpastian' => 0.01, 'faktor_cakupan' => 2]),
        );

        $rataHarapan = array_sum($pembacaan) / $n;
        $stdevHarapan = $this->stdevSampel($pembacaan);

        $this->assertSame($n, $hasil['jumlah_pengulangan'], 'n harus ikut yang diisi');
        $this->assertEqualsWithDelta($rataHarapan, $hasil['rata_rata'], 1e-12, 'rata-rata dibagi n, bukan 5');
        $this->assertEqualsWithDelta($stdevHarapan, $hasil['standar_deviasi'], 1e-12, 'stdev pembagi n−1');
        $this->assertEqualsWithDelta($stdevHarapan / sqrt($n), $hasil['type_a'], 1e-12, 'Type A pakai √n');

        // Koreksi = −(rata−titik). Ini yang kecetak di sertifikat, jadi kalau
        // rata-ratanya salah gara-gara pembagi, yang salah dokumennya.
        $this->assertEqualsWithDelta(-($rataHarapan - 7.0), $hasil['koreksi'], 1e-12);
    }

    /**
     * Derajat kebebasan Type A = n−1, buat ketiga profil.
     *
     * Dipatok 4 (=5−1) bakal bikin Welch–Satterthwaite ngasih k yang kegedean
     * buat n kecil — ketidakpastiannya keliatan lebih bagus dari yang sebenarnya.
     */
    public function test_derajat_kebebasan_type_a_ikut_n_di_ketiga_profil(): void
    {
        $kemampuan = new CalibrationCapability([
            'u_temperature' => sqrt((0.72 / 2) ** 2 + (0.06 / 2) ** 2),
            'ci_suhu' => 0.001,
            'u_perbedaan_suhu' => 0.02,
            'ci_perbedaan_suhu' => 0.001,
        ]);
        $standard = new Standard(['nama' => 'Std', 'ketidakpastian' => 0.01, 'faktor_cakupan' => 2]);

        $profil = [
            'pH' => [new PhMeterProfile, new Equipment(['satuan' => 'pH', 'resolusi' => 0.01]), 7.0],
            'Turbidimeter' => [new TurbidimeterProfile, new Equipment(['satuan' => 'NTU', 'resolusi' => 0.01]), 1.0],
            'Chlorine' => [new ChlorineProfile, new Equipment(['satuan' => 'mg/L', 'resolusi' => 0.01]), 1.74],
        ];

        foreach ($profil as $nama => [$p, $alat, $titik]) {
            foreach ([2, 3, 5, 8] as $n) {
                $komponen = $p->komponenBudget($kemampuan, $alat, $standard, $titik, 0.002, $n);

                $this->assertNotNull($komponen, "$nama: budget nggak kebentuk");

                $typeA = collect($komponen)->firstWhere('sumber', 'pengulangan_pembacaan');
                $this->assertNotNull($typeA, "$nama: komponen pengulangan nggak ada");
                $this->assertSame(
                    (float) max($n - 1, 1),
                    (float) $typeA['vi'],
                    "$nama n=$n: derajat kebebasan harus n−1",
                );
                $this->assertStringContainsString(
                    "Pengulangan $n pembacaan",
                    $typeA['keterangan'],
                    "$nama n=$n: keterangan budget harus nyebut n yang beneran",
                );
            }
        }
    }

    /**
     * Sebaran yang sama persis, jumlah pembacaan beda → Type A HARUS beda.
     *
     * Kalau angka 5 kepatok di suatu tempat, tiga kasus di bawah bakal ngasih
     * Type A yang sama, dan itu ketahuannya cuma dari sini.
     */
    public function test_type_a_mengecil_kalau_pembacaannya_lebih_banyak(): void
    {
        $alat = new Equipment(['nama_alat' => 'pH Meter', 'satuan' => 'pH', 'resolusi' => 0.01, 'toleransi' => 0.05]);
        $standar = new Standard(['nama' => 'Buffer 7', 'ketidakpastian' => 0.01, 'faktor_cakupan' => 2]);

        // n=2: selisih 0,02 → s = 0,02/√2 = 0,01414214
        $hasil2 = $this->gum->hitungTitik(1, 7.0, [6.99, 7.01], $alat, $standar);
        // n=3: simpangan ±0,01 dan 0 → s = √(2·0,0001/2) = 0,01
        $hasil3 = $this->gum->hitungTitik(1, 7.0, [6.99, 7.00, 7.01], $alat, $standar);

        $this->assertEqualsWithDelta(0.02 / sqrt(2), $hasil2['standar_deviasi'], 1e-12);
        $this->assertEqualsWithDelta((0.02 / sqrt(2)) / sqrt(2), $hasil2['type_a'], 1e-12);

        $this->assertEqualsWithDelta(0.01, $hasil3['standar_deviasi'], 1e-12);
        $this->assertEqualsWithDelta(0.01 / sqrt(3), $hasil3['type_a'], 1e-12);

        // Arahnya juga harus bener, bukan sekadar beda: pembacaan lebih banyak
        // (dan lebih rapat) = Type A lebih kecil.
        $this->assertLessThan($hasil2['type_a'], $hasil3['type_a']);
    }

    /**
     * Satu pembacaan doang ditolak, bukan dipaksa jalan.
     *
     * n=1 nggak punya standar deviasi (pembagi n−1 = 0). Ngarang Type A = 0 di
     * situ bikin sertifikat ngeklaim ketidakpastian lebih bagus dari yang bisa
     * dibuktiin.
     */
    public function test_satu_pembacaan_ditolak(): void
    {
        $this->assertSame(2, GumCalculator::MIN_PENGULANGAN);

        $this->expectException(\InvalidArgumentException::class);

        $this->gum->hitungTitik(
            1, 7.0, [7.01],
            new Equipment(['nama_alat' => 'pH Meter', 'satuan' => 'pH', 'resolusi' => 0.01, 'toleransi' => 0.05]),
            new Standard(['nama' => 'Buffer 7', 'ketidakpastian' => 0.01, 'faktor_cakupan' => 2]),
        );
    }
}
