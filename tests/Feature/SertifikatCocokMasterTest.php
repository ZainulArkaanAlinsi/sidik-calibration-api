<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\KondisiLingkungan;
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
            //
            // **5 desimal, bukan 4.** Angka `cetak` di bawah sempat ditulis 4
            // desimal — ditebak dari resolusi alat (0,0001), bukan dibaca dari
            // sertifikat masternya. Waktu PDF master diadu langsung 7 Agt 2026,
            // yang kecetak ternyata `1,33659 | 1,33935 | −0,00276 | 0,00053`.
            // Di 4 desimal U95% runtuh jadi `0,0005` — kehilangan angka penting
            // di kolom yang justru jadi inti sertifikat kalibrasi.
            'Refractometer — 2211.11.R' => [
                'nomorSesi' => '2211.11.R',
                'hasil' => [
                    ['mentah' => [1.33659, 1.33935, -0.00276, 0.0005271534327323267], 'cetak' => ['1,33659', '1,33935', '-0,00276', '0,00053']],
                    ['mentah' => [1.39986, 1.40085, -0.00099, 0.0005295557108700615], 'cetak' => ['1,39986', '1,40085', '-0,00099', '0,00053']],
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
     * Env. Condition kecetak persis kayak master lab — buat KEEMPAT alat.
     *
     * Baris ini udah dua kali salah dan dua kali "dibetulin" dari nalar, bukan
     * dari kertasnya: 7 Agt kecetak `60% ± 5,2%` (nilai bulat, U 1 desimal),
     * lalu disejajarkan jadi `60% ± 5%` — sejajar, tapi ke sisi yang salah, dan
     * 9 Agt dikeluhkan lagi buat tiga alat sekaligus.
     *
     * Yang bikin bisa balik dua kali: tabel hasilnya diadu ke master (tes di
     * atas), header-nya nggak. Jadi kelembaban bisa digeser-geser tanpa ada
     * satu pun tes yang merah. Sekarang ikut kepatok ke sel yang sama.
     *
     * Angkanya dari `Env. Condition` di tiap `SERTIFIKAT.csv`:
     *   pH        T 20,97      ± 1,7117242768623688 · %RH 51,95 ± 5,660388679233963
     *   Turbidi   T 23,07      ± 1,7117242768623688 · %RH 51,83 ± 4,8
     *   Chlorine  T 23,21      ± 1,802775637731995  · %RH 53    ± 4,903060268852505
     *   Refracto  T 21,96      ± 1,824828759089466  · %RH 60,41 ± 5,292447448959697
     */
    #[DataProvider('envKondisi')]
    public function test_env_condition_sama_dengan_master_excel(string $nomorSesi, string $harapan): void
    {
        $this->assertSame(
            $harapan,
            $this->terbitkan($this->sesi($nomorSesi))->snapshot['header']['env_condition'],
        );
    }

    /**
     * @return array<string, array{nomorSesi: string, harapan: string}>
     */
    public static function envKondisi(): array
    {
        return [
            'pH Meter' => [
                'nomorSesi' => '2405.13.A',
                'harapan' => 'T: 21,0°C ± 1,7°C — %RH: 51,95% ± 5,66%',
            ],
            'Turbidimeter' => [
                'nomorSesi' => '2406.32.A',
                'harapan' => 'T: 23,1°C ± 1,7°C — %RH: 51,83% ± 4,80%',
            ],
            'Chlorine Meter' => [
                'nomorSesi' => '2406.32.C',
                'harapan' => 'T: 23,2°C ± 1,8°C — %RH: 53,00% ± 4,90%',
            ],
            // U95 kelembaban SENGAJA beda dari master: 5,20 punya kita vs
            // 5,292447448959697 di sheet. Nilai & koreksinya sendiri cocok
            // persis (60,41 = rata-rata 61 + koreksi −0,59), jadi yang beda
            // cuma satu masukan: U95 sertifikat TH-5 di titik itu.
            //
            //   punya kita  √(4,8² + 2²) = 5,2
            //   master      √(4,9² + 2²) = 5,2924474489…
            //
            // 4,8 itu yang tercatat di titik 60 %RH pada arsip titik kalibrasi
            // TH-5 (`database/data/thermohygro-lab.json`). 4,9 itu angka di
            // titik PERTAMA-nya (29,8 %RH) — dan itu juga satu-satunya titik
            // TH-5 yang U95-nya bukan 4,8. Bacaan yang paling masuk akal:
            // sheet lab ngambil U95 dari satu sel tetap per unit, sementara
            // koreksinya dicocokin per titik.
            //
            // Nggak diikutin diam-diam, dan nggak juga dianggap master yang
            // salah — dua-duanya ngubah angka di dokumen terakreditasi. Ini
            // mesti ditanyain ke lab: U95 thermohygro itu per titik atau satu
            // buat seluruh rentang? Cuma kelihatan di Refractometer karena cuma
            // sesi ini yang pakai TH-5 di kelembaban ~60 %RH.
            'Refractometer' => [
                'nomorSesi' => '2211.11.R',
                'harapan' => 'T: 22,0°C ± 1,8°C — %RH: 60,41% ± 5,20%',
            ],
        ];
    }

    /**
     * Blok **PERHITUNGAN KONDISI LINGKUNGAN** diadu sel per sel ke master —
     * bukan cuma hasil cetaknya di header sertifikat.
     *
     * Bedanya penting: `env_condition` itu string yang udah dibulatkan, jadi
     * pemilihan titik koreksi yang salah bisa lolos di sana kalau kebetulan
     * pembulatannya nutupin. Yang diperiksa di sini rantai lengkapnya —
     * average → indexed value → correction → Δ → U95% — persis kolom di sheet
     * `PERHITUNGAN` baris "Suhu Ruangan" & "Kelembaban".
     *
     * `indexed_value` yang ikut dipatok itu intinya: dia yang mbuktiin titik
     * koreksi thermohygro-nya kepilih bener. Pernah salah sekali — sesi
     * turbidimeter 25,7 °C kena titik 19,6 °C dan kecetak 25,47 padahal master
     * 26,05, selisih 0,58 °C di dokumen terakreditasi.
     *
     * @param  array<string, list<float|null>>  $harapan
     */
    #[DataProvider('kondisiLingkungan')]
    public function test_blok_kondisi_lingkungan_sama_dengan_master_excel(string $nomorSesi, array $harapan): void
    {
        $sesi = $this->sesi($nomorSesi);
        $hitung = app(KondisiLingkungan::class);

        foreach ($harapan as $parameter => [$awal, $akhir, $average, $indexed, $correction, $delta, $u95StdTh, $u95Sertifikat]) {
            $h = $hitung->hitung($sesi, $parameter);

            $this->assertNotNull($h, "{$nomorSesi} {$parameter}: blok kondisi lingkungannya kosong");

            foreach ([
                'awal' => $awal,
                'akhir' => $akhir,
                'average' => $average,
                'indexed_value' => $indexed,
                'correction' => $correction,
                'delta' => $delta,
                'u95_std_th' => $u95StdTh,
                'u95_sertifikat' => $u95Sertifikat,
            ] as $kolom => $nilai) {
                $this->assertEqualsWithDelta(
                    $nilai,
                    (float) $h[$kolom],
                    1e-9,
                    "{$nomorSesi} {$parameter}: kolom {$kolom} meleset dari master Excel",
                );
            }
        }
    }

    /**
     * Kolom per parameter, urut kayak di sheet:
     * `[awal, akhir, average, indexed_value, correction, Δ, U95 std TH, U95 sertifikat]`.
     *
     * @return array<string, array{nomorSesi: string, harapan: array<string, list<float>>}>
     */
    public static function kondisiLingkungan(): array
    {
        return [
            'pH Meter' => [
                'nomorSesi' => '2405.13.A',
                'harapan' => [
                    'suhu' => [21.3, 21.5, 21.4, 19.83, -0.43, 0.2, 1.7, 1.7117242768623688],
                    'kelembaban' => [53, 56, 54.5, 47.05, -2.55, 3, 4.8, 5.660388679233963],
                ],
            ],
            'Turbidimeter' => [
                'nomorSesi' => '2406.32.A',
                'harapan' => [
                    'suhu' => [23.2, 23.4, 23.3, 19.33, -0.23, 0.2, 1.7, 1.7117242768623688],
                    // Δ = 0 → U95% sertifikat = U95% std TH apa adanya.
                    'kelembaban' => [55, 55, 55, 46.83, -3.17, 0, 4.8, 4.8],
                ],
            ],
            'Chlorine Meter' => [
                'nomorSesi' => '2406.32.C',
                'harapan' => [
                    'suhu' => [24.1, 23.5, 23.8, 19.37, -0.59, 0.6, 1.7, 1.802775637731995],
                    'kelembaban' => [56, 55, 55.5, 47.1, -2.5, 1, 4.8, 4.903060268852505],
                ],
            ],
            // Kelembaban: `u95_std_th` 4,8 punya kita vs 4,9 yang ketulis di
            // sheet refractometer. SEMBILAN kolom lainnya cocok persis, jadi
            // yang beda cuma satu sel.
            //
            // ## Sel mana persisnya, dan kenapa kita nggak ngikut
            //
            // Tiap unit TH punya 5 baris titik kalibrasi, dan tiap baris muat
            // titik suhu DAN titik kelembaban sekaligus. Sesi refractometer ini
            // makai baris yang BEDA buat dua parameternya:
            //
            //     suhu       indexed 21,51  → baris 1  (u95 %RH di baris itu 4,9)
            //     kelembaban indexed 59,41  → baris 3  (u95 %RH di baris itu 4,8)
            //
            // Sheet lab ngambil koreksi kelembaban dari baris 3 (−0,59, dan itu
            // yang bikin 60,41 cocok), tapi U95%-nya dari baris 1. Jadi aturan
            // yang beneran jalan di workbook itu: **U95 kelembaban ikut baris
            // yang kepilih lewat SUHU**, bukan lewat titik kelembabannya
            // sendiri — ciri khas VLOOKUP yang salah kolom acuan.
            //
            // Tiga alat lain nggak bisa mbuktiin apa-apa soal ini: di pH,
            // Turbidimeter & Chlorine baris suhu dan baris kelembabannya
            // KEBETULAN sama (dua-duanya baris 2), jadi dua aturan yang beda
            // ngasih angka yang sama persis. Cuma refractometer yang misahin
            // keduanya.
            //
            // Aturan itu bisa ditiru dan hasilnya bakal cocok 4/4 — tapi
            // sengaja NGGAK ditiru (keputusan Raihan, 10 Agt 2026): nyalin bug
            // lookup ke perhitungan terakreditasi berarti begitu lab mbetulin
            // workbook-nya, kode kita yang jadi salah. Yang perlu dibetulin sel
            // `U95% Std TH` di sheet PERHITUNGAN refractometer: 4,9 → 4,8.
            // Sudah diteruskan ke lab.
            'Refractometer' => [
                'nomorSesi' => '2211.11.R',
                'harapan' => [
                    'suhu' => [20.9, 21.2, 21.05, 21.51, 0.91, 0.3, 1.8, 1.824828759089466],
                    'kelembaban' => [62, 60, 61, 59.41, -0.59, 2, 4.8, 5.2],
                ],
            ],
        ];
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
     * `desimal` yang dikirim API sesi WAJIB sama dengan yang kecetak di
     * sertifikatnya — buat keempat alat.
     *
     * Ini jahitan yang sempat lepas. `CertificateSnapshotBuilder` ngikutin
     * timpaan profil alat, `CalibrationResource` nggak: dia langsung nurunin
     * dari resolusi. Buat tiga alat kebetulan sama, tapi Refractometer
     * (profilnya nyatain 5, resolusinya ngasih 4) jadi punya dua jawaban buat
     * satu sertifikat — layar HP nulis `1,3394` / `0,0005`, PDF-nya `1,33935` /
     * `0,00053`. Yang salah justru yang dipegang teknisi waktu ngecek, dan
     * nggak ada satu pun tes yang merah karena tabel hasil diadu ke master
     * lewat snapshot, bukan lewat API.
     *
     * @param  list<array{mentah: list<float>, cetak: list<string>}>  $hasil
     */
    #[DataProvider('alat')]
    public function test_desimal_di_api_sesi_sama_dengan_yang_kecetak(string $nomorSesi, array $hasil): void
    {
        $sesi = $this->sesi($nomorSesi);
        $sertifikat = $this->terbitkan($sesi);

        $admin = User::where('role', 'admin')->firstOrFail();

        $respons = $this->actingAs($admin)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk();

        // Level sesi: satu angka, dibandingin ke `desimal` snapshot.
        $this->assertSame(
            (int) $sertifikat->snapshot['desimal'],
            (int) $respons->json('data.desimal'),
            "desimal sesi {$nomorSesi} beda dari sertifikatnya",
        );

        // Per titik: alat yang resolusinya berubah per rentang (Turbidimeter)
        // bawa angkanya sendiri, dan itu juga harus sama baris per baris.
        foreach ($sertifikat->snapshot['hasil'] as $i => $baris) {
            $this->assertSame(
                (int) $baris['desimal'],
                (int) ($respons->json("data.titik.{$i}.desimal") ?? $respons->json('data.desimal')),
                'desimal titik '.($i + 1)." sesi {$nomorSesi} beda dari sertifikatnya",
            );
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
