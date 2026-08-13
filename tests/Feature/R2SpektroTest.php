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
 * Bawaannya kolomnya MATI, dan itu bukan kelalaian — itu keputusan. Sel R² di
 * master isinya `0,9359`, sertifikat cetak yang beredar nulis `1`, dan nggak
 * satu pun dari dua angka itu bisa dilahirkan dari data blok tersebut (RSQ atas
 * seluruh titiknya = 0,999922). Selama lab belum jawab dari mana angkanya,
 * kolomnya nggak dicetak sama sekali — kolom yang nggak ada lebih jujur
 * daripada kolom berisi angka yang nggak bisa dipertanggungjawabkan.
 *
 * Jadi tes ini punya dua tugas yang sama pentingnya:
 *
 *  1. **Mati beneran waktu mati.** Nggak ada header R², nggak ada kolom kosong
 *     yang bikin tabel geser, di PDF maupun Excel.
 *  2. **Bener waktu hidup.** Begitu lab jawab dan satu nilai config dibalik,
 *     angkanya sama persis `RSQ()` Excel, cuma nempel di blok %T, dan kecetak
 *     sekali per kelompok — bukan lima kali.
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
     * Bawaan sistem = kolomnya nggak ada. Ini yang bakal jalan di produksi hari
     * ini, dan yang bikin sertifikat yang udah terbit nggak berubah bentuk.
     */
    public function test_bawaannya_r2_tidak_dihitung_sama_sekali(): void
    {
        $sertifikat = $this->terbitkanSpektro();

        foreach ($sertifikat->snapshot['hasil'] as $baris) {
            // Kuncinya ADA (biar pembaca snapshot nggak perlu nebak), isinya
            // null.
            $this->assertArrayHasKey('r2', $baris);
            $this->assertNull($baris['r2'], "Titik {$baris['titik_ke']} nggak boleh punya R² selama lab belum jawab.");
        }
    }

    public function test_bawaannya_kolom_r2_tidak_muncul_di_pdf_maupun_excel(): void
    {
        $sertifikat = $this->terbitkanSpektro();

        $this->assertStringNotContainsString('R<sup>2</sup>', $this->pdf($sertifikat));
        $this->assertNotContains('R2', $this->headerTabelExcel($sertifikat));
    }

    /**
     * Sekali lab jawab, satu nilai config yang dibalik — bukan tambal kode.
     */
    public function test_kalau_dinyalakan_hanya_blok_transmitan_yang_punya_r2(): void
    {
        $this->nyalakanR2();

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
    public function test_kalau_dinyalakan_r2_dicetak_sekali_per_kelompok(): void
    {
        $this->nyalakanR2();

        $html = $this->pdf($this->terbitkanSpektro());

        $this->assertSame(1, substr_count($html, 'R<sup>2</sup>'), 'Header R² cuma boleh di tabel blok %T.');
        $this->assertSame(1, substr_count($html, '0,9999'), 'Nilainya cuma di baris pertama kelompoknya.');
    }

    public function test_kalau_dinyalakan_excel_ikut_nyetak_kolom_yang_sama(): void
    {
        $this->nyalakanR2();

        $sertifikat = $this->terbitkanSpektro();
        $sel = $this->selExcel($sertifikat);

        $this->assertContains('R2', $this->headerTabelExcel($sertifikat));

        // Nilainya nempel di baris pertama blok %T aja; baris lain kolomnya
        // kosong, persis kayak masternya (`SERTIFIKAT!R47:R51`).
        $angka = collect($sel)
            ->map(fn (array $b) => end($b))
            ->filter(fn ($v) => is_float($v) && abs($v - 0.9999) < 1e-9);

        $this->assertCount(1, $angka, 'R² di Excel harus muncul sekali, bukan tiap baris.');
    }

    /**
     * Alat lain nggak kecipratan walau fiturnya nyala — config-nya khusus
     * spektro, dan profil lain nggak override hooknya.
     */
    public function test_alat_lain_tidak_terpengaruh(): void
    {
        $this->nyalakanR2();

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
        $this->nyalakanR2();

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

    private function nyalakanR2(): void
    {
        Config::set('kalibrasi.r2_spektro', SpectrophotometerProfile::R2_RSQ_STANDAR_UUT);
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
