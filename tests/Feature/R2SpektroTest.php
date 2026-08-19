<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use App\Services\CertificateExcelExporter;
use App\Services\DataTampilanSertifikat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Kolom **R2** di blok %T Spectrophotometer.
 *
 * ## Yang dijaga tes ini
 *
 * Kolomnya sempat MATI: sel R² di master isinya `0,9359`, sertifikat cetak
 * nulis `1`, dan nggak satu pun dari dua angka itu bisa dilahirkan dari data
 * blok tersebut (RSQ atas seluruh titiknya = 0,999922).
 *
 * Yang mbukain FORMAT SELNYA, bukan rumusnya: sel itu nol desimal, jadi
 * `0,9359` maupun 0,999922 sama-sama tercetak `1`. Dua kandidat yang belum bisa
 * dibedain nyetak angka yang sama, jadi kolomnya nyala — dan yang dijaga tes
 * ini tiga hal:
 *
 *  1. **Bawaannya nyala & nyetak `1`.** Nol desimal, di PDF maupun Excel.
 *  2. **Sekali per kelompok.** Satu kotak `rowspan` setinggi tabelnya, niru sel
 *     merge master — bukan lima angka yang kebetulan sama.
 *  3. **Mati beneran waktu dimatiin.** `off` (dan salah ketik) = nggak ada
 *     header R², nggak ada kolom kosong yang bikin tabel geser.
 *
 * @see docs/pertanyaan-lab-r2-spektro.md
 */
class R2SpektroTest extends TestCase
{
    use RefreshDatabase;

    /** Judul kelompok %T — satu-satunya blok yang punya kolom R2 di master. */
    private const BLOK_TRANSMITAN = 'Accuracy %T and Linierity at λ = 560nm';

    /** `RSQ(standar; UUT)` atas kelima titik %T sesi demo. */
    private const R2_HARAPAN = 0.9999219438582861;

    /**
     * Bawaan sistem = kolomnya ADA. Ini yang jalan di produksi hari ini.
     */
    public function test_bawaannya_kolom_r2_dicetak_di_pdf_dan_excel(): void
    {
        $this->assertSame(
            SpectrophotometerProfile::R2_RSQ_STANDAR_UUT,
            config('kalibrasi.r2_spektro'),
            'Bawaan config-nya yang bikin kolomnya nyala di produksi.',
        );

        $sertifikat = $this->terbitkanSpektro();

        $this->assertStringContainsString('R<sup>2</sup>', $this->pdf($sertifikat));
        $this->assertContains('R2', $this->headerTabelExcel($sertifikat));
    }

    /**
     * Angkanya tercetak `1`, BUKAN `0,9999` — sel masternya nol desimal.
     *
     * Ini yang bikin kolomnya boleh nyala walau rumus aslinya belum ketahuan:
     * 0,9359 (isi sel master) dan 0,999922 (hitung ulang) sama-sama jadi `1`.
     */
    public function test_r2_dicetak_nol_desimal_persis_master(): void
    {
        $html = $this->pdf($this->terbitkanSpektro());

        $this->assertStringNotContainsString('0,9999', $html, 'R² nggak boleh kecetak presisi penuh.');
        $this->assertMatchesRegularExpression(
            '/<td class="r2" rowspan="5">1<\/td>/',
            $html,
            'R² wajib `1` di satu kotak setinggi lima baris blok %T.',
        );
    }

    /** Sakelarnya masih ada — sekali dibalik ke `off`, kolomnya ilang lagi. */
    public function test_kalau_dimatikan_kolom_r2_tidak_muncul_di_pdf_maupun_excel(): void
    {
        $this->matikanR2();

        $sertifikat = $this->terbitkanSpektro();

        foreach ($sertifikat->snapshot['hasil'] as $baris) {
            // Kuncinya ADA (biar pembaca snapshot nggak perlu nebak), isinya
            // null.
            $this->assertArrayHasKey('r2', $baris);
            $this->assertNull($baris['r2'], "Titik {$baris['titik_ke']} nggak boleh punya R² waktu fiturnya mati.");
        }

        $this->assertStringNotContainsString('R<sup>2</sup>', $this->pdf($sertifikat));
        $this->assertNotContains('R2', $this->headerTabelExcel($sertifikat));
    }

    public function test_hanya_blok_transmitan_yang_punya_r2(): void
    {
        $baris = collect($this->terbitkanSpektro()->snapshot['hasil']);

        $transmitan = $baris->where('remark', self::BLOK_TRANSMITAN);
        $this->assertCount(5, $transmitan);

        foreach ($transmitan as $b) {
            $this->assertEqualsWithDelta(self::R2_HARAPAN, $b['r2'], 1e-9);
        }

        // Dua blok panjang gelombang nggak punya kolom R2 di master, jadi
        // titik-titiknya wajib tetap null walau fiturnya nyala.
        foreach ($baris->where('remark', '!=', self::BLOK_TRANSMITAN) as $b) {
            $this->assertNull($b['r2'], "Blok '{$b['remark']}' nggak punya kolom R2 di master.");
        }
    }

    /**
     * R² itu angka SATU KELOMPOK, bukan angka per titik. Diulang di lima baris,
     * dia kebaca kayak lima R² yang kebetulan sama — padahal cuma ada satu.
     */
    public function test_r2_dicetak_sekali_per_kelompok(): void
    {
        $html = $this->pdf($this->terbitkanSpektro());

        $this->assertSame(1, substr_count($html, 'R<sup>2</sup>'), 'Header R² cuma boleh di tabel blok %T.');
        $this->assertSame(1, substr_count($html, 'class="r2"'), 'Selnya cuma satu — di-rowspan, bukan diulang tiap baris.');
    }

    public function test_excel_ikut_nyetak_kolom_yang_sama(): void
    {
        $sertifikat = $this->terbitkanSpektro();

        $this->assertContains('R2', $this->headerTabelExcel($sertifikat));

        // Nilainya nempel di baris pertama blok %T aja; baris lain kolomnya
        // kosong, persis kayak masternya (`SERTIFIKAT!R47:R51`) — dan angkanya
        // `1`, sama kayak PDF & sel master yang nol desimal.
        $kolom = $this->kolomR2Excel($sertifikat);

        $this->assertCount(24, $kolom, '24 titik: 10 Holmium + 9 Didynium + 5 %T.');
        // `assertEquals`, bukan `assertSame`: Excel nyimpen angka bulat tanpa
        // desimal, jadi pembacanya balikin `int 1` walau yang ditulis `1.0`.
        $this->assertEquals([19 => 1], array_filter($kolom, static fn ($v) => $v !== ''));
    }

    /**
     * Alat lain nggak kecipratan — config-nya khusus spektro, dan profil lain
     * nggak override hooknya.
     */
    public function test_alat_lain_tidak_terpengaruh(): void
    {
        $sertifikat = $this->terbitkan($this->sesi('2405.13.A'));

        foreach ($sertifikat->snapshot['hasil'] as $baris) {
            $this->assertNull($baris['r2'] ?? null);
        }

        $this->assertStringNotContainsString('R<sup>2</sup>', $this->pdf($sertifikat));
    }

    /**
     * Sertifikat yang terbit SEBELUM kolom ini ada nggak punya kunci `r2` di
     * snapshot-nya sama sekali. Snapshot itu beku — nggak boleh ada rilis yang
     * bikin dokumen lama gagal dirender cuma gara-gara kunci baru.
     */
    public function test_snapshot_lama_tanpa_kunci_r2_tetap_bisa_dirender(): void
    {
        $sertifikat = $this->terbitkanSpektro();
        $snapshot = $sertifikat->snapshot;
        $snapshot['hasil'] = array_map(
            static function (array $b): array {
                unset($b['r2']);

                return $b;
            },
            $snapshot['hasil'],
        );
        $sertifikat->update(['snapshot' => $snapshot]);

        $html = $this->pdf($sertifikat->fresh());

        $this->assertStringContainsString('CALIBRATION REPORT', $html);
        $this->assertStringNotContainsString('R<sup>2</sup>', $html);
        $this->assertNotContains('R2', $this->headerTabelExcel($sertifikat->fresh()));
    }

    /** Nilai config yang salah ketik nggak boleh diam-diam nyalain kolomnya. */
    public function test_nilai_config_asing_dianggap_mati(): void
    {
        Config::set('kalibrasi.r2_spektro', 'rsq');

        foreach ($this->terbitkanSpektro()->snapshot['hasil'] as $baris) {
            $this->assertNull($baris['r2']);
        }
    }

    private function matikanR2(): void
    {
        Config::set('kalibrasi.r2_spektro', 'off');
    }

    private function terbitkanSpektro(): Certificate
    {
        return $this->terbitkan($this->sesi('DEMO-SPECTRO-LDC'));
    }

    private function sesi(string $nomorSesi): CalibrationSession
    {
        if (CalibrationSession::query()->doesntExist()) {
            $this->seed(DatabaseSeeder::class);
        }

        return CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();
    }

    /** Lewat endpoint approve admin — jalur yang sama dipakai orang beneran. */
    private function terbitkan(CalibrationSession $sesi): Certificate
    {
        $sertifikat = $sesi->certificate()->first();

        if ($sertifikat !== null) {
            return $sertifikat;
        }

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        // `abaikan_peringatan` bukan buat nutupin error: sesi spektro kena
        // peringatan `pembacaan_di_luar_rentang` karena titik %T (0–100) diadu
        // ke rentang alat yang kesimpen dalam nm (200–700). Itu batasan satu
        // kolom rentang buat alat dua besaran, bukan salah data — `boleh_terbit`
        // tetap true. Lihat catatan `range_min/max` di SpectrophotometerSeeder.
        $this->actingAs($admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    private function pdf(Certificate $sertifikat): string
    {
        return view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();
    }

    /** @return list<string> */
    private function headerTabelExcel(Certificate $sertifikat): array
    {
        $baris = collect($this->selExcel($sertifikat))
            ->first(fn (array $b) => ($b[0] ?? null) === 'Standard Value');

        return array_map(static fn ($v) => (string) $v, $baris ?? []);
    }

    /**
     * Isi kolom R² buat SETIAP baris tabel hasil, urut cetak — `''` di baris
     * yang kolomnya kosong.
     *
     * @return list<mixed>
     */
    private function kolomR2Excel(Certificate $sertifikat): array
    {
        $sel = $this->selExcel($sertifikat);
        $awal = null;

        foreach ($sel as $i => $baris) {
            if (($baris[0] ?? null) === 'Standard Value') {
                $awal = $i + 1;
                break;
            }
        }

        $this->assertNotNull($awal, 'Tabel hasil nggak ketemu di Excel-nya.');

        $kolom = [];

        foreach (array_slice($sel, $awal) as $baris) {
            // Tabelnya berhenti di baris pertama yang kolom Standard Value-nya
            // bukan angka (baris kosong pemisah, lalu catatan).
            if (! is_numeric($baris[0] ?? null)) {
                break;
            }

            $kolom[] = end($baris);
        }

        return $kolom;
    }

    /** @return list<list<mixed>> */
    private function selExcel(Certificate $sertifikat): array
    {
        $berkas = tempnam(sys_get_temp_dir(), 'uji-r2-').'.xlsx';
        app(CertificateExcelExporter::class)->satu($sertifikat, $berkas);

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

        return $sel;
    }
}
