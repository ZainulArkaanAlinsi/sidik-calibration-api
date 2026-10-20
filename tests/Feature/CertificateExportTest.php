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
use App\Support\TataLetakSertifikat;
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

    /**
     * Bentuk master (K-50-15): Excel mengikuti tabel PDF — urutan & judul
     * kolom `kolom_hasil` (Tachometer menukar Standard/UUT), tanda koreksi
     * yang dibekukan di snapshot, dan angka tercetak menurut format sel.
     */
    public function test_excel_bentuk_master_ikut_kolom_dan_angka_pdf(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $sertifikat->update(['snapshot' => [
            'desimal' => 2,
            'header' => ['certificate_number' => $sertifikat->nomor],
            'tata_letak' => [
                ...TataLetakSertifikat::normalisasi(TataLetakSertifikat::MAYORITAS_MASTER),
                ...TataLetakSertifikat::normalisasi(['kolom_hasil' => [
                    ['kolom' => 'standar', 'judul' => 'Standard Value'],
                    ['kolom' => 'uut', 'judul' => 'Unit Under Test'],
                    'koreksi',
                    ['kolom' => 'u95', 'judul' => 'U95% (±)'],
                ]]),
            ],
            'hasil' => [[
                'titik_ke' => 1, 'standard_value' => 60.0, 'unit_under_test' => 59.78, 'correction' => 0.22,
                'u95' => 2.61, 'desimal' => 2, 'satuan' => 'Rpm',
                'format' => ['standar' => '0.0', 'uut' => '0.0', 'koreksi' => '0.0', 'u95' => '0.0'],
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
            break;
        }
        $pembaca->close();
        @unlink($berkas);

        $kepala = collect($sel)->first(fn ($b) => ($b[0] ?? null) === 'Standard Value');
        $this->assertSame(['Standard Value', 'Unit Under Test', 'Correction', 'U95% (±)'], $kepala);
        $this->assertContains(['Rpm', 'Rpm', 'Rpm', 'Rpm'], $sel);

        $angka = collect($sel)->first(fn ($b) => is_numeric($b[0] ?? null));
        $this->assertEqualsWithDelta(60.0, (float) $angka[0], 1e-12);
        $this->assertEqualsWithDelta(59.8, (float) $angka[1], 1e-12, 'UUT harus ikut format `0.0` PDF.');
        $this->assertEqualsWithDelta(0.2, (float) $angka[2], 1e-12);
        $this->assertEqualsWithDelta(2.6, (float) $angka[3], 1e-12);
    }

    /**
     * Format sel master per baris (`baris.format`, K-50-15) menang atas
     * `desimal` di Excel, sama dengan PDF: `desimal` 5 tetapi format `0.000`
     * → tiga desimal. Baris yang disembunyikan master tidak ditulis.
     */
    public function test_excel_ikut_format_sel_master_bukan_desimal(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $sertifikat->update(['snapshot' => [
            'desimal' => 5,
            'header' => ['certificate_number' => $sertifikat->nomor],
            'hasil' => [
                ['titik_ke' => 1, 'standard_value' => 0.0, 'unit_under_test' => 0.0, 'correction' => 0.0, 'u95' => 0.1, 'desimal' => 5, 'dicetak' => false],
                [
                    'titik_ke' => 2, 'standard_value' => 25.0, 'unit_under_test' => 25.00027, 'correction' => -0.00027,
                    'u95' => 0.00087, 'desimal' => 5, 'desimal_u95' => 5,
                    'format' => ['standar' => '0.000', 'uut' => '0.000', 'koreksi' => '0.000', 'u95' => '0.0000'],
                ],
            ],
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
            break;
        }
        $pembaca->close();
        @unlink($berkas);

        $angka = array_values(array_filter($sel, static fn ($b) => is_float($b[0] ?? null) || is_int($b[0] ?? null)));
        $this->assertCount(1, $angka, 'Baris tersembunyi ikut ditulis ke Excel.');
        $this->assertEqualsWithDelta(25.0, (float) $angka[0][0], 1e-12);
        $this->assertEqualsWithDelta(25.0, (float) $angka[0][1], 1e-12, 'UUT harus 3 desimal seperti format sel master, bukan 5.');
        $this->assertEqualsWithDelta(0.0, (float) $angka[0][2], 1e-12);
        $this->assertEqualsWithDelta(0.0009, (float) $angka[0][3], 1e-12);
    }

    /**
     * Env. Condition bertumpuk (K-50-15: T / %RH / Tekanan Udara sebaris-
     * sebaris seperti lembar SERTIFIKAT master) ditulis ke Excel sebagai SATU
     * sel berbungkus — barisnya sama dengan PDF, bukan satu baris panjang
     * dengan karakter baris baru yang tersembunyi.
     */
    public function test_env_condition_bertumpuk_ditulis_berbungkus_di_excel(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $env = "T 21.0 °C ± 1.7 °C\n%RH 52 % ± 4.9 %\nTekanan Udara 1001 hPa ± 2.0 hPa";
        $snapshot = $sertifikat->snapshot;
        $snapshot['header']['env_condition'] = $env;
        $sertifikat->update(['snapshot' => $snapshot]);

        $berkas = tempnam(sys_get_temp_dir(), 'uji-').'.xlsx';
        app(CertificateExcelExporter::class)->satu($sertifikat->fresh(), $berkas);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($berkas) === true);
        $lembar = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $gaya = (string) $zip->getFromName('xl/styles.xml');
        $zip->close();

        $pembaca = new Reader;
        $pembaca->open($berkas);
        $nilaiEnv = null;
        $nomorBaris = null;
        foreach ($pembaca->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $i => $baris) {
                $sel = $baris->getCells();
                if (($sel[0] ?? null)?->getValue() === 'Env. Condition') {
                    $nilaiEnv = $sel[1]->getValue();
                    $nomorBaris = $i;
                }
            }
            break;
        }
        $pembaca->close();
        @unlink($berkas);

        // Isinya tiga baris yang sama persis dengan PDF.
        $this->assertSame($env, $nilaiEnv);

        // Sel B baris itu memakai gaya ber-wrapText.
        $this->assertNotNull($nomorBaris);
        $this->assertSame(1, preg_match('#<c r="B\d+" s="(\d+)"[^>]*><is><t[^>]*>T 21\.0#u', $lembar, $m), 'Sel Env. Condition tanpa gaya.');
        preg_match('#<cellXfs[^>]*>(.*?)</cellXfs>#s', $gaya, $xfs);
        preg_match_all('#<xf\b.*?(?:/>|</xf>)#s', $xfs[1], $daftar);
        $this->assertStringContainsString('wrapText="1"', $daftar[0][(int) $m[1]]);
    }
}
