<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\CertificateExcelExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Export sertifikat ke Excel & QR Code (spesifikasi poin 10 & 13).
 */
class CertificateExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();

        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create(['nama' => 'PT Contoh Jaya'])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        $this->standar = Standard::factory()->create();
    }

    private function sertifikatTerbit(): Certificate
    {
        Storage::fake('local');

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI, 'reviewed_by' => $this->admin->id]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        return $sesi->certificate()->firstOrFail();
    }

    public function test_sertifikat_bisa_diexport_ke_excel(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $respons = $this->actingAs($this->admin)
            ->get("/api/certificates/{$sertifikat->id}/excel")
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );

        $isi = $respons->streamedContent();

        // XLSX itu file ZIP — dua huruf pertamanya `PK`. Cukup buat mastiin
        // yang kekirim beneran workbook, bukan halaman error.
        $this->assertStringStartsWith('PK', $isi);
        $this->assertGreaterThan(1000, strlen($isi));
    }

    /**
     * Satu sertifikat, tiga tampilan (PDF, Excel, pratinjau di app) — angkanya
     * WAJIB sama.
     *
     * Dulu Excel nulis nilai mentah dari snapshot (`1.758`, `0.091`) sementara
     * PDF nulis `1,76` dan `0,09`. Buat sertifikat terakreditasi itu temuan
     * audit: pelanggan yang mbandingin dua berkas nggak punya cara tahu mana
     * yang resmi. Kolom Remark juga cuma ada di PDF.
     */
    public function test_angka_di_excel_sama_persis_dengan_yang_dicetak_di_pdf(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        // Snapshot chlorine beneran (CAL/2026/08/0009): nilai mentahnya lebih
        // panjang dari resolusi alatnya, jadi bedanya kelihatan.
        $sertifikat->update(['snapshot' => [
            'desimal' => 2,
            'header' => ['certificate_number' => $sertifikat->nomor],
            'hasil' => [[
                'titik_ke' => 1,
                'standard_value' => 1.74,
                'unit_under_test' => 1.758,
                'correction' => -0.018,
                'u95' => 0.091,
                'desimal' => 2,
                'remark' => 'Free Chlorine',
            ]],
        ]]);

        $berkas = tempnam(sys_get_temp_dir(), 'uji-').'.xlsx';
        app(CertificateExcelExporter::class)->satu($sertifikat->fresh(), $berkas);

        $sel = [];
        $pembaca = new Reader;
        $pembaca->open($berkas);
        foreach ($pembaca->getSheetIterator() as $lembar) {
            foreach ($lembar->getRowIterator() as $baris) {
                $sel[] = array_map(
                    static fn ($c) => $c->getValue(),
                    $baris->getCells(),
                );
            }
        }
        $pembaca->close();
        @unlink($berkas);

        $hasil = collect($sel)->first(fn ($b) => ($b[0] ?? null) === 1.74);

        $this->assertNotNull($hasil, 'Baris hasil nggak ketemu di Excel-nya.');
        $this->assertSame(1.76, $hasil[1], 'Unit Under Test harus dibulatkan kayak di PDF.');
        $this->assertSame(-0.02, $hasil[2], 'Correction harus dibulatkan kayak di PDF.');
        $this->assertSame(0.09, $hasil[3], 'U95 harus dibulatkan kayak di PDF, bukan 0,091.');
        $this->assertSame('Free Chlorine', $hasil[4], 'Kolom Remark harus ikut kayak di PDF.');

        // Tetap ANGKA, bukan teks — Excel dipakai buat ngerekap & ngitung, dan
        // `number_format` bikin kolomnya nggak bisa dijumlah.
        $this->assertIsFloat($hasil[3]);
    }

    /**
     * Correction yang membulat ke nol nggak boleh ketulis `-0`.
     *
     * `round(-0.2, 0)` di PHP balikin negatif nol, dan Excel nulisnya apa
     * adanya. Nilainya sama dengan nol, tapi di kolom Correction sertifikat itu
     * kebaca kayak salah cetak. PDF-nya nggak kena karena `number_format()`
     * emang buang tandanya — jadi dua berkas buat sertifikat yang sama beda
     * tampilan lagi.
     *
     * Kejadian beneran di Turbidimeter titik 100 NTU (resolusi 1 NTU).
     */
    public function test_correction_yang_membulat_ke_nol_nggak_ketulis_negatif_nol(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $sertifikat->update(['snapshot' => [
            'desimal' => 0,
            'header' => ['certificate_number' => $sertifikat->nomor],
            'hasil' => [[
                'titik_ke' => 1,
                'standard_value' => 100.0,
                'unit_under_test' => 100.2,
                'correction' => -0.2,
                'u95' => 3.1,
                'desimal' => 0,
            ]],
        ]]);

        $berkas = tempnam(sys_get_temp_dir(), 'uji-').'.xlsx';
        app(CertificateExcelExporter::class)->satu($sertifikat->fresh(), $berkas);

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

        // Dicocokin longgar: pembaca XLSX balikin 100 sebagai int, bukan float.
        $hasil = collect($sel)->first(
            fn ($b) => is_numeric($b[0] ?? null) && (float) $b[0] === 100.0,
        );

        $this->assertNotNull($hasil);
        // `assertSame` nggak bisa bedain -0.0 sama 0.0, jadi tandanya yang dicek.
        $this->assertDoesNotMatchRegularExpression('/^-/', (string) $hasil[2]);
        $this->assertEqualsWithDelta(0.0, $hasil[2], 1e-12);
    }

    public function test_rekap_banyak_sertifikat_bisa_diexport_sekaligus(): void
    {
        $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->get('/api/certificates/export/excel?bulan='.now()->format('Y-m'))
            ->assertOk();

        // Bulan yang nggak ada sertifikatnya jangan ngasih file kosong —
        // itu bikin orang ngira datanya ilang.
        $this->actingAs($this->admin)
            ->get('/api/certificates/export/excel?bulan='.now()->subYears(3)->format('Y-m'))
            ->assertNotFound();
    }

    public function test_rekap_cuma_buat_admin(): void
    {
        $this->sertifikatTerbit();

        $this->actingAs($this->teknisi)
            ->get('/api/certificates/export/excel')
            ->assertForbidden();
    }

    public function test_qr_sertifikat_kekirim_sebagai_png(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $respons = $this->actingAs($this->admin)
            ->get("/api/certificates/{$sertifikat->id}/qr")
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        // Tanda tangan berkas PNG.
        $this->assertStringStartsWith("\x89PNG", $respons->getContent());
    }

    public function test_hasil_scan_qr_bisa_langsung_unduh_pdf_maupun_excel_tanpa_login(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $token = $sertifikat->qr_token;

        $this->get("/verify/{$token}/download")->assertOk();
        $this->get("/verify/{$token}/download?format=xlsx")->assertOk();

        // Token ngawur nggak boleh bocorin apa pun.
        $this->get('/verify/tokenpalsu/download')->assertNotFound();
    }

    public function test_teknisi_nggak_bisa_export_sertifikat_punya_orang_lain(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $teknisiLain = User::factory()->create();

        $this->actingAs($teknisiLain)
            ->get("/api/certificates/{$sertifikat->id}/excel")
            ->assertNotFound();

        $this->actingAs($teknisiLain)
            ->get("/api/certificates/{$sertifikat->id}/qr")
            ->assertNotFound();
    }

    public function test_detail_sertifikat_bawa_snapshot_dan_hasil_validasi(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->getJson("/api/certificates/{$sertifikat->id}")
            ->assertOk()
            ->assertJsonPath('data.snapshot.header.owner', 'PT Contoh Jaya')
            ->assertJsonPath('data.validasi.boleh_terbit', true);
    }
}
