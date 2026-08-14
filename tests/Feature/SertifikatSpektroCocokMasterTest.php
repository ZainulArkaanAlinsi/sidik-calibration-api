<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\CertificateExcelExporter;
use App\Services\DataTampilanSertifikat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Tabel CALIBRATION REPORT Spectrophotometer diadu ke **lembar tercetaknya**,
 * karakter per karakter.
 *
 * ## Kenapa perlu tes sendiri, padahal angkanya udah cocok
 *
 * `SertifikatSpektroBudgetTest` & kawan-kawannya ngejaga NILAI-nya, dan
 * nilainya emang udah sama persis sama master sampai batas presisi
 * penyimpanan. Yang nggak dijaga siapa pun: BENTUK cetaknya. Sampai 14 Agt 2026
 * sistem nyetak
 *
 *     334   | 333,74 | 0,26     ← punya kita
 *     334,0 | 333,7  | 0,3      ← master
 *
 * Angka yang sama, tiga kolom yang beda bunyinya, di dokumen yang dipegang
 * pelanggan buat alat yang sama. Sebabnya desimalnya diturunkan dari RESOLUSI
 * alat (0,01 nm & 0,001 %T) — aturan yang bener buat lembar kerja, salah buat
 * sertifikat: sel `SERTIFIKAT` di workbook master diformat 1 desimal.
 *
 * Empat hal yang cuma kelihatan waktu diadu ke kertasnya, dan semuanya dijaga
 * di sini:
 *
 *  1. **1 desimal, ketiga blok.** Bukan 2 (nm) & 3 (%T).
 *  2. **Nol di belakang dipertahankan** di kolom Standard: `334,0`, bukan `334`.
 *  3. **Koreksi negatif yang membulat ke nol tanpa minus**: titik 0 %T & 100 %T
 *     koreksinya negatif, masternya nyetak `0,0`.
 *  4. **`k` nol desimal**: `3` · `2` · `2`, bukan `3,18` · `2,36` · `2,01`.
 *
 * Nilai `Standard`/`UUT`/`Correction` di bawah ini disalin dari
 * `Master_Olah_Data_Spectrofotometer_CSV/SERTIFIKAT.csv` baris 19–52, teks
 * cetaknya dari tangkapan layar workbook-nya (14 Agt 2026).
 */
class SertifikatSpektroCocokMasterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `SERTIFIKAT!C17` — blok Holmium, 10 titik.
     *
     * Master di layar cuma nampilin 9 baris (mulai 287,7); baris 279,6 ada di
     * selnya (`SERTIFIKAT.csv` baris 19) tapi kesembunyi di tampilan. Titiknya
     * TETAP dicetak di sini — buang satu titik ukur dari sertifikat itu
     * keputusan lab, bukan penyesuaian tampilan.
     *
     * @var list<list<string>>
     */
    private const HOLMIUM = [
        ['279,6', '280,0', '-0,4'],
        ['287,7', '288,0', '-0,3'],
        ['334,0', '333,7', '0,3'],
        ['360,9', '360,5', '0,4'],
        ['418,6', '418,4', '0,2'],
        ['445,8', '445,6', '0,2'],
        ['453,6', '453,4', '0,2'],
        ['460,0', '459,3', '0,7'],
        ['536,3', '536,5', '-0,2'],
        ['637,9', '637,3', '0,6'],
    ];

    /** `SERTIFIKAT!C32` — blok Didynium, 9 titik. @var list<list<string>> */
    private const DIDYNIUM = [
        ['475,2', '477,9', '-2,7'],
        ['513,7', '513,5', '0,2'],
        ['529,7', '529,4', '0,3'],
        ['572,7', '572,4', '0,3'],
        ['585,7', '585,4', '0,3'],
        ['684,9', '684,4', '0,5'],
        ['738,5', '738,6', '-0,1'],
        ['748,0', '747,8', '0,2'],
        ['806,1', '806,4', '-0,3'],
    ];

    /**
     * `SERTIFIKAT!C46` — blok %T, 5 titik.
     *
     * Baris pertama & terakhir itu buktinya `tandaNolDicetak()` mesti false:
     * koreksinya -0,000666… & -0,002666…, dua-duanya negatif, dua-duanya
     * tercetak `0,0` tanpa minus.
     *
     * @var list<list<string>>
     */
    private const TRANSMITAN = [
        ['0,0', '0,0', '0,0'],
        ['9,9', '9,7', '0,2'],
        ['20,0', '19,5', '0,5'],
        ['30,1', '29,2', '0,9'],
        ['100,0', '100,0', '0,0'],
    ];

    public function test_tiga_blok_kecetak_persis_master(): void
    {
        $tabel = $this->tabelHasil();

        $this->assertSame(self::HOLMIUM, $tabel[0]['baris']);
        $this->assertSame(self::DIDYNIUM, $tabel[1]['baris']);
        $this->assertSame(self::TRANSMITAN, $tabel[2]['baris']);
    }

    /**
     * Baris U95 punya desimalnya SENDIRI: 2, sementara kolom di atasnya 1.
     * Dua angka, dua format, satu tabel — dan yang menentukan masternya.
     */
    public function test_u95_tiap_blok_kecetak_persis_master(): void
    {
        $tabel = $this->tabelHasil();

        $this->assertSame('0,43 nm', $tabel[0]['u95']);
        $this->assertSame('0,40 nm', $tabel[1]['u95']);
        $this->assertSame('0,50 %T', $tabel[2]['u95']);
    }

    /**
     * `k` disimpan presisi penuh (3,182446…) tapi dicetak bulat — sel masternya
     * nol desimal. Sistem sempat nyetak `3,18`: angka yang bener, bukan angka
     * yang ada di dokumen lab.
     */
    public function test_faktor_cakupan_kecetak_bulat_persis_master(): void
    {
        $html = $this->pdf();

        preg_match_all('/Coverage Factor \( k \) =\s*([^<\s]+)/u', $html, $cocok);

        $this->assertSame(['3', '2', '2'], $cocok[1]);
    }

    /**
     * Kepala kolom kedua: `UUT (nm)`, bukan `Unit Under Test (nm)`.
     *
     * Master spektro nulis `UUT` di KETIGA bloknya — jadi bukan sel yang
     * kepotong, itu pilihan lab. Lima master lain nulis panjang, dan sistem
     * dulu maksa satu judul buat semuanya.
     *
     * Yang SENGAJA nggak ditiru: spasi yang ilang di `Correction(nm)`. Master
     * spektro sendiri nulisnya dua cara (`Correction (%T)` di blok %T,
     * `Correction(%T)` di blok SRE), jadi itu kelalaian ketik — bukan aturan.
     */
    public function test_kepala_kolom_uut_ikut_master(): void
    {
        $html = $this->pdf();

        $this->assertStringContainsString('<th>UUT (nm)</th>', $html);
        $this->assertStringContainsString('<th>UUT (%T)</th>', $html);
        $this->assertStringNotContainsString('Unit Under Test', $html);

        // Correction tetap pakai spasi — master sendiri nggak konsisten.
        $this->assertStringContainsString('<th>Correction (nm)</th>', $html);
    }

    /** Lima alat lain nggak kesenggol — judulnya tetap panjang. */
    public function test_alat_lain_tetap_unit_under_test(): void
    {
        if (CalibrationSession::query()->doesntExist()) {
            $this->seed(DatabaseSeeder::class);
        }

        $sesi = CalibrationSession::where('nomor_sesi', '2405.13.A')->firstOrFail();
        $sertifikat = $sesi->certificate()->first() ?? $this->terbitkanSesi($sesi);

        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();

        $this->assertStringContainsString('Unit Under Test', $html);
        $this->assertStringNotContainsString('<th>UUT', $html);
    }

    /**
     * Excel itu SALINAN sertifikat yang sama, jadi kolom U95-nya wajib bunyi
     * sama kayak PDF-nya.
     *
     * Sempat nggak: exporter mbulatin U95 pakai desimal BARIS (1), bukan
     * `desimal_u95` (2) — PDF nulis `0,43`, Excel nulis `0,4`. Dua angka, dua
     * berkas, satu sertifikat terakreditasi, dan pelanggan yang mbandingin
     * dua-duanya nggak punya cara tahu mana yang resmi.
     *
     * Bedanya kesembunyi selama desimal titik spektro masih 2: `0,43` kebetulan
     * keluar bener lewat jalur yang salah. Turun ke 1, langsung meleset.
     */
    public function test_u95_di_excel_sama_dengan_yang_kecetak_di_pdf(): void
    {
        $berkas = tempnam(sys_get_temp_dir(), 'uji-spektro-').'.xlsx';
        app(CertificateExcelExporter::class)->satu($this->terbitkan(), $berkas);

        $sel = [];
        $pembaca = new Reader;
        $pembaca->open($berkas);
        foreach ($pembaca->getSheetIterator() as $lembar) {
            foreach ($lembar->getRowIterator() as $baris) {
                $sel[] = array_map(static fn ($c) => $c->getValue(), $baris->getCells());
            }
        }
        $pembaca->close();
        @unlink($berkas);

        // Kolom ke-4 tabel hasil = `U95% (±)`. Satu angka per kelompok, jadi
        // yang unik cuma tiga — dan ketiganya wajib 2 desimal.
        $u95 = collect($sel)
            ->filter(fn (array $b) => is_numeric($b[0] ?? null) && isset($b[3]))
            ->map(fn (array $b) => (float) $b[3])
            ->unique()
            ->values()
            ->all();

        $this->assertEqualsWithDelta([0.43, 0.4, 0.5], $u95, 1e-9);
    }

    /**
     * Judul kelompok dicetak sekali per blok, urut kayak masternya — itu yang
     * bikin `0,40 nm` kebaca punya Didynium, bukan Holmium.
     */
    public function test_urutan_dan_judul_blok_ikut_master(): void
    {
        $this->assertSame(
            [
                'Wave Length ( λ ) - Filter Holmium',
                'Wave Length ( λ ) - Filter Didynium',
                'Accuracy %T and Linierity at λ = 560nm',
            ],
            array_column($this->tabelHasil(), 'judul'),
        );
    }

    /**
     * Tiga tabel hasil di CALIBRATION REPORT, urut cetak.
     *
     * Sengaja diambil dari HTML yang BENERAN dirender, bukan dari snapshot
     * plus formatter yang dipanggil ulang di tes: yang salah kemarin justru
     * pilihan formatter-nya (`nilaiStandar` vs `hasil`), dan tes yang manggil
     * formatter sendiri bakal ikut salah dengan cara yang sama persis.
     *
     * @return list<array{judul: string, baris: list<list<string>>, u95: string}>
     */
    private function tabelHasil(): array
    {
        $html = $this->pdf();

        $awal = strpos($html, 'CALIBRATION REPORT');
        $akhir = strpos($html, 'STANDARD USED');
        $this->assertIsInt($awal);
        $this->assertIsInt($akhir);

        $potongan = substr($html, $awal, $akhir - $awal);

        preg_match_all(
            '/<div class="judul-kelompok">(.*?)<\/div>.*?<tbody>(.*?)<\/tbody>/su',
            $potongan,
            $blok,
            PREG_SET_ORDER,
        );

        return array_map(function (array $b): array {
            preg_match_all('/<tr(?<u95> class="u95")?>(?<isi>.*?)<\/tr>/su', $b[2], $baris, PREG_SET_ORDER);

            $data = [];
            $u95 = null;

            foreach ($baris as $r) {
                $sel = $this->sel($r['isi']);

                if ($r['u95'] !== '') {
                    // `[0]` label, `[1]` angkanya + satuan.
                    $u95 = $sel[1];

                    continue;
                }

                // Kolom R² (`rowspan`) cuma nempel di baris pertama blok %T;
                // yang diadu ke master di sini tiga kolom pertama doang —
                // R²-nya punya tesnya sendiri di `R2SpektroTest`.
                $data[] = array_slice($sel, 0, 3);
            }

            return ['judul' => $this->teks($b[1]), 'baris' => $data, 'u95' => (string) $u95];
        }, $blok);
    }

    /** @return list<string> */
    private function sel(string $baris): array
    {
        preg_match_all('/<td[^>]*>(.*?)<\/td>/su', $baris, $sel);

        return array_map(fn (string $s): string => $this->teks($s), $sel[1]);
    }

    private function teks(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))));
    }

    private function pdf(): string
    {
        $sertifikat = $this->terbitkan();

        return view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();
    }

    private function terbitkan(): Certificate
    {
        if (CalibrationSession::query()->doesntExist()) {
            $this->seed(DatabaseSeeder::class);
        }

        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-SPECTRO-LDC')->firstOrFail();

        return $sesi->certificate()->first() ?? $this->terbitkanSesi($sesi);
    }

    private function terbitkanSesi(CalibrationSession $sesi): Certificate
    {
        // `abaikan_peringatan`: titik %T (0–100) diadu ke rentang alat yang
        // kesimpen dalam nm (200–700). Batasan satu kolom rentang buat alat dua
        // besaran, bukan salah data — lihat `SpectrophotometerSeeder`.
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail())
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail();
    }
}
