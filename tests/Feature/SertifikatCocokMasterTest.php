<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Support\Angka;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tabel **Calibration Report** yang keluar dari pipeline harus sama dengan
 * angka di master Excel lab — buat KETIGA alat sekaligus, dalam satu berkas.
 *
 * ## Kenapa tes ini ada
 *
 * 6 Agt 2026: sertifikat Chlorine di HP nampilin baris kedua `1,90` / `-0,07`,
 * padahal sertifikat asli `0189-CAL-624` nulis `1,86` / `-0,03`. Yang dicurigai
 * duluan olah datanya. Ternyata bukan — pembacaan yang KETIK di sesi itu emang
 * 1,90, dan hitungannya bener buat masukan itu. Tapi buat sampai ke kesimpulan
 * itu mesti bongkar database satu-satu, karena nggak ada satu pun tes yang
 * bilang "olah data alat X masih sama dengan kertas lab".
 *
 * Itu yang ditutup di sini: sekali `php artisan test`, ketiga alat diadu ke
 * masternya. Kalau nanti ada angka sertifikat yang kelihatan aneh, tes ini yang
 * mutusin duluan — pipeline atau masukan — tanpa perlu bongkar DB lagi.
 *
 * ## Angka acuannya
 *
 * Bukan karangan, disalin dari master Excel lab yang ada di repo mobile
 * (`Project-PT-Sidik/**\/SERTIFIKAT.csv`), yaitu berkas yang sama yang dipakai
 * lab buat nerbitin sertifikat kertasnya:
 *
 *  - pH `012-CAL-524` — `Master Olah Data_pH for trial_CSV` baris 21–23
 *  - Turbidimeter `0189-CAL-624` — `Master Data TurbidiMeter_CSV` baris 22–24
 *  - Chlorine `0189-CAL-624` — `Chlorine_Meter_CSV` baris 18–19
 *
 * Dua kolom diperiksa dua tingkat:
 *
 *  1. **nilai mentah** — dibandingin ke angka di sel Excel-nya, toleransi
 *     [TOLERANSI_SIMPAN] (= resolusi kolomnya).
 *     Ini yang nangkep rumus meleset walau pembulatannya nutupin.
 *  2. **hasil cetak** — lewat `Angka::id()` dengan desimal per baris, formatter
 *     yang sama persis dipakai `sertifikat/pdf.blade.php`. Ini yang nangkep
 *     beda TAMPILAN antara PDF, Excel, dan layar mobile.
 */
class SertifikatCocokMasterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Toleransi banding nilai mentah = **resolusi penyimpanannya**, bukan angka
     * yang dikarang. Kolom hasil hitung itu `decimal(20, 8)`, jadi 1e-8 adalah
     * sehalus-halusnya yang bisa dijanjiin DB.
     *
     * Dulu 1e-9 — lebih halus dari yang bisa disimpen, dan itu lolos CUMA karena
     * suite jalan di SQLite, yang nggak nerapin presisi `decimal` sama sekali.
     * Dijalanin ke MySQL (yang dipakai produksi) dua kasus langsung merah:
     *
     *   pH  titik 1 `standard_value`: 4,00924457   vs 4,009244572     (kepotong)
     *   Ref titik 1 `u95`           : 0,00052715   vs 0,0005271534327 (kepotong)
     *
     * Dua-duanya bukan salah hitung — angkanya bener sampai `round()` terakhir,
     * lalu kolomnya yang motong. Ditemukan 7 Agt 2026 waktu nambah Refractometer.
     *
     * ## Yang MASIH jadi PR, jangan dianggap kelar gara-gara test ini ijo
     *
     * 8 desimal itu longgar buat pH (0–14) & Turbidimeter (sampai 1000 NTU),
     * tapi buat Refractometer U95-nya ~5e-4 — jadi cuma nyisa **4 angka
     * penting**. Sertifikat yang kecetak nggak berubah (dibulatkan ke 4 desimal:
     * `0,0005`) dan keputusan PASS/FAIL juga nggak, jadi nggak ada yang salah
     * keluar HARI INI. Tapi kalau nanti ada alat yang skalanya lebih kecil lagi,
     * atau lab minta U95 dilaporin lebih presisi, kolomnya yang mesti dilebarin
     * (`decimal(28, 16)`) bareng `CalibrationController::DESIMAL_PEMBACAAN`.
     * Itu ganti skema di repo bersama — keputusannya bukan di test ini.
     */
    private const TOLERANSI_SIMPAN = 1e-8;

    /**
     * Satu kasus = satu alat.
     *
     * `hasil` per baris: `[standard_value, unit_under_test, correction, u95]`
     * nilai mentah, lalu `cetak` = empat kolom yang sama seperti yang kecetak
     * di sertifikat.
     *
     * @return array<string, array{nomorSesi: string, hasil: list<array{mentah: list<float>, cetak: list<string>}>}>
     */
    public static function alat(): array
    {
        return [
            // Master Olah Data_pH for trial_CSV/SERTIFIKAT.csv baris 21–23.
            // Standard Value-nya bukan 4,00/7,00/10,01 bulat karena buffer pH
            // dikoreksi kurva suhu dulu — 4,009244572 itu buffer 4 di 22,2 °C.
            'pH Meter — 012-CAL-524' => [
                'nomorSesi' => '2405.13.A',
                'hasil' => [
                    ['mentah' => [4.009244572, 4.0, 0.009244572, 0.02343221021262627], 'cetak' => ['4,01', '4,00', '0,01', '0,02']],
                    ['mentah' => [6.9889072, 7.004, -0.0150928, 0.02110894987572546], 'cetak' => ['6,99', '7,00', '-0,02', '0,02']],
                    ['mentah' => [9.9788769, 10.11, -0.1311231, 0.031], 'cetak' => ['9,98', '10,11', '-0,13', '0,03']],
                ],
            ],

            // Master Data TurbidiMeter_CSV/SERTIFIKAT.csv baris 22–24.
            // Tiga baris, TIGA resolusi (0,01 / 0,1 / 1 NTU) — itu sebabnya
            // baris 100 & 1.000 kecetak tanpa desimal sama sekali.
            'Turbidimeter — 0189-CAL-624' => [
                'nomorSesi' => '2406.32.A',
                'hasil' => [
                    ['mentah' => [1.0, 1.004, -0.004, 0.041], 'cetak' => ['1,00', '1,00', '0,00', '0,04']],
                    // Koreksi -0,02 membulat ke nol di resolusi 1 NTU. Yang
                    // kecetak `0`, BUKAN `-0`.
                    ['mentah' => [100.0, 100.02, -0.02, 3.1], 'cetak' => ['100', '100', '0', '3']],
                    ['mentah' => [1000.0, 1000.6, -0.6, 22.0], 'cetak' => ['1.000', '1.001', '-1', '22']],
                ],
            ],

            // Chlorine_Meter_CSV/SERTIFIKAT.csv baris 18–19.
            'Chlorine Meter — 0189-CAL-624' => [
                'nomorSesi' => '2406.32.C',
                'hasil' => [
                    ['mentah' => [1.74, 1.758, -0.018, 0.091], 'cetak' => ['1,74', '1,76', '-0,02', '0,09']],
                    // U95 mentah SENGAJA beda dari sheet (0,0801585…): sel
                    // pengulangan titik 1,83 di workbook lab ketinggalan isi
                    // walau STDEV-nya 0 — lihat docblock `ChlorineSeeder`.
                    // Punya kita 0,0596 kena batas CMC 0,08. Yang KECETAK tetap
                    // sama-sama `0,08`, jadi sertifikatnya nggak beda.
                    ['mentah' => [1.83, 1.86, -0.03, 0.08], 'cetak' => ['1,83', '1,86', '-0,03', '0,08']],
                ],
            ],

            // Refractometer_CSV 2/SERTIFIKAT.csv baris 18–19.
            //
            // Unit Under Test-nya BUKAN pembacaan mentah teknisi (1,3362 &
            // 1,3986) — itu pembacaan yang udah dinormalisasi ke suhu acuan
            // 20 °C. Titik 1 dibaca pada rata-rata 27 °C (repeat ke-5 kecatat
            // 35 °C di sheet), jadi 1,3362 + 0,00045 × 7 = 1,33935. Titik 2
            // pada 25 °C → 1,3986 + 0,00045 × 5 = 1,40085.
            //
            // Kalau suatu saat baris ini merah dengan UUT = pembacaan mentah,
            // yang rusak `RefractometerProfile::rataRataPadaSuhuAcuan()` atau
            // urutannya di `GumCalculator::hitungTitik()` — bukan angkanya.
            //
            // Baris ke-3 di CSV (`#REF!`) sengaja nggak ikut: sel rusak di
            // master, bukan titik ukur beneran.
            'Refractometer — 2211.11.R' => [
                'nomorSesi' => '2211.11.R',
                'hasil' => [
                    ['mentah' => [1.33659, 1.33935, -0.00276, 0.0005271534327323267], 'cetak' => ['1,3366', '1,3394', '-0,0028', '0,0005']],
                    ['mentah' => [1.39986, 1.40085, -0.00099, 0.0005295557108700615], 'cetak' => ['1,3999', '1,4009', '-0,0010', '0,0005']],
                ],
            ],
        ];
    }

    /**
     * @param  list<array{mentah: list<float>, cetak: list<string>}>  $hasil
     */
    #[DataProvider('alat')]
    public function test_tabel_calibration_report_sama_dengan_master_excel(string $nomorSesi, array $hasil): void
    {
        $baris = $this->tabelHasil($nomorSesi);

        $this->assertCount(count($hasil), $baris, "jumlah titik di sertifikat {$nomorSesi} beda dari master");

        foreach ($hasil as $i => $harapan) {
            $desimal = $baris[$i]['desimal'] ?? $this->desimalSertifikat($nomorSesi);

            $kolom = ['standard_value', 'unit_under_test', 'correction', 'u95'];

            foreach ($kolom as $k => $nama) {
                $nilai = (float) $baris[$i][$nama];

                $this->assertEqualsWithDelta(
                    $harapan['mentah'][$k],
                    $nilai,
                    self::TOLERANSI_SIMPAN,
                    'titik '.($i + 1)." kolom {$nama} meleset dari master Excel",
                );

                // Formatter yang sama persis dipakai `sertifikat/pdf.blade.php`.
                $this->assertSame(
                    $harapan['cetak'][$k],
                    Angka::id($nilai, $desimal),
                    'titik '.($i + 1)." kolom {$nama} kecetak beda dari sertifikat kertas",
                );
            }
        }
    }

    /**
     * Desimal per baris HARUS ikut resolusi titiknya, bukan satu angka buat
     * seluruh tabel. Turbidimeter yang mbuktiin: resolusinya berubah menurut
     * rentang, jadi baris 100 NTU nggak boleh kecetak `100,00` — dua digit yang
     * alatnya sendiri nggak bisa tampilkan.
     */
    public function test_turbidimeter_bawa_desimal_sendiri_per_titik(): void
    {
        $baris = $this->tabelHasil('2406.32.A');

        $this->assertSame([2, 0, 0], array_map(
            fn (array $b): ?int => $b['desimal'] ?? null,
            $baris,
        ));
    }

    /**
     * Alat yang resolusinya seragam: SEMUA baris kebagian desimal yang sama
     * dengan desimal sertifikat. Yang dijaga di sini bukan angkanya doang, tapi
     * juga bahwa baris chlorine nggak ikut-ikutan mad sendiri kayak
     * Turbidimeter — mobile baca `desimal` per baris duluan
     * (`BarisHasilSertifikat.desimalEfektif`), jadi kalau nilainya geser, layar
     * & PDF bisa beda jumlah desimal buat sertifikat yang sama.
     */
    public function test_chlorine_resolusinya_seragam_jadi_ikut_desimal_sertifikat(): void
    {
        $snapshot = $this->terbitkan($this->sesi('2406.32.C'))->snapshot;

        $this->assertSame(2, $snapshot['desimal']);

        foreach ($snapshot['hasil'] as $baris) {
            $this->assertSame(2, $baris['desimal'] ?? null);
        }
    }

    /**
     * Correction di sertifikat itu `standard − pembacaan`, BUKAN kebalikannya.
     * Ketuker berarti tanda koreksi di dokumen pelanggan kebalik semua, dan
     * angkanya tetap "kelihatan wajar" jadi nggak ada yang curiga.
     */
    /**
     * @param  list<array{mentah: list<float>, cetak: list<string>}>  $hasil
     */
    #[DataProvider('alat')]
    public function test_correction_itu_standard_dikurangi_pembacaan(string $nomorSesi, array $hasil): void
    {
        $tabel = $this->tabelHasil($nomorSesi);
        $this->assertCount(count($hasil), $tabel);

        foreach ($tabel as $baris) {
            $this->assertEqualsWithDelta(
                (float) $baris['standard_value'] - (float) $baris['unit_under_test'],
                (float) $baris['correction'],
                self::TOLERANSI_SIMPAN,
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function tabelHasil(string $nomorSesi): array
    {
        return array_values($this->terbitkan($this->sesi($nomorSesi))->snapshot['hasil']);
    }

    private function desimalSertifikat(string $nomorSesi): int
    {
        return (int) $this->terbitkan($this->sesi($nomorSesi))->snapshot['desimal'];
    }

    private function sesi(string $nomorSesi): CalibrationSession
    {
        // Seed-nya lengkap, bukan sesi karangan: yang mau dibuktiin justru
        // "data master lab kalau dijalanin lewat pipeline beneran, keluarnya
        // sama kayak kertasnya".
        if (CalibrationSession::query()->doesntExist()) {
            $this->seed(DatabaseSeeder::class);
        }

        return CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();
    }

    /**
     * Sertifikat yang udah ada dipakai apa adanya; yang belum, diterbitkan
     * lewat endpoint approve admin — jalur yang sama dipakai orang beneran.
     */
    private function terbitkan(CalibrationSession $sesi): Certificate
    {
        $sertifikat = $sesi->certificate()->first();

        if ($sertifikat !== null) {
            return $sertifikat;
        }

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail();
    }
}
