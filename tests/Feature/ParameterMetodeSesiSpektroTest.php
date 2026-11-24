<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use App\Services\CalibrationValidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PenetapanParameterUji;
use Tests\TestCase;

/**
 * Parameter keputusan metode, JALUR PENUH lewat database: simpan, preview,
 * validator, dan `kalibrasi:hitung-ulang` memakai pilihan yang SAMA untuk satu
 * sesi, pilihan itu tercatat di jejak sesi, dan sesi lama tidak bergeser saat
 * penetapan baru ditambahkan.
 *
 * Dipilih Spectrophotometer (kelompok Holmium) karena hanya profil ini yang
 * menghitung per kelompok lewat ketiga pintu (simpan, validator, hitung ulang);
 * Refractometer dan Conductivity lewat jalur per titik yang sama
 * (`GumCalculator::hitungTitik`) dan dijaga di tes unitnya.
 *
 * Angka acuan = cache sel workbook (`Master Olah Data_Spectrofotometer.xlsm`):
 * `PERHITUNGAN!M36 =MAX(M27:M35)` = 0,15588457268125408, `'PERHITUNGAN U95%'!J17`
 * uc = 0,1359196779955751. Titik 279,6 nm sengaja diberi STDEV 2,0 (di workbook
 * sampel M26 = 0), supaya pilihan `semua_titik` dan `tanpa_titik_pertama`
 * menghasilkan angka yang BERBEDA.
 */
class ParameterMetodeSesiSpektroTest extends TestCase
{
    use PenetapanParameterUji;
    use RefreshDatabase;

    /** Toleransi simpan: kolom `decimal(20,8)` (lihat `SpectrophotometerApiTest`). */
    private const TOLERANSI_SIMPAN = 1e-8;

    private const KUNCI = 'spectrophotometer.max_stdev_holmium';

    private const UC_EXCEL = 0.1359196779955751;

    private User $teknisi;

    private Equipment $alat;

    private Standard $holmium;

    protected function setUp(): void
    {
        parent::setUp();

        ParameterKeputusanMetode::lupakan();

        // `id` dipatok 1: lihat catatan di `SpectrophotometerApiTest::setUp()`.
        $org = Organization::factory()->create(['id' => 1]);
        $this->teknisi = User::factory()->create(['organization_id' => $org->id]);
        $kategori = EquipmentCategory::factory()->create(['organization_id' => $org->id]);

        $this->holmium = Standard::factory()->create([
            'organization_id' => $org->id,
            'nama' => 'Filter Standard 1',
            'merk' => 'PG.Instrument',
            'serial_number' => 'SPG080982.A',
            'no_sertifikat' => 'SPG080982.A',
            'tertelusur_ke' => 'BSN-SNSU',
            'berlaku_sampai' => now()->addYear(),
            'ketidakpastian' => 0.1,
            'satuan_ketidakpastian' => 'nm',
            'faktor_cakupan' => 2,
            'koefisien_suhu' => null,
            'parameter_kondisi' => null,
        ]);

        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Spectrophotometer',
            'parameter' => 'panjang gelombang (nm)-Holmium',
            'range_min' => 283,
            'range_max' => 641,
            'satuan' => 'nm',
            'ketidakpastian_terbaik' => 0.4,
            'satuan_ketidakpastian' => 'nm',
            'faktor_cakupan' => 2,
            'metode' => SpectrophotometerProfile::KODE_METODE,
        ]);

        $this->alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Visible Spectrofotometer',
            'nama_alat_kemampuan' => 'Spectrophotometer',
            'merk' => 'Perkin Elmer',
            'model' => 'Lambda 25',
            'serial_number' => '501S13102801',
            'satuan' => 'nm',
            'resolusi' => 0.01,
            'toleransi' => null,
        ]);
    }

    protected function tearDown(): void
    {
        ParameterKeputusanMetode::lupakan();
        parent::tearDown();
    }

    private function hariIni(int $mundur): string
    {
        return CarbonImmutable::today()->subDays($mundur)->toDateString();
    }

    /**
     * Tiga titik Holmium; titik 279,6 nm ber-STDEV 2,0. Dua titik lain dipilih
     * seperti `SpectrophotometerApiTest` (STDEV 0,14224 dan 0,15588 = M36).
     *
     * @return array<string, mixed>
     */
    private function payload(string $tanggal): array
    {
        $titik = [
            [279.6, [278.0, 280.0, 282.0]],
            [360.9, [360.35, 360.59, 360.6]],
            [637.9, [637.45, 637.18, 637.18]],
        ];

        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->holmium->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => $tanggal,
            'suhu_awal' => 22.0,
            'suhu_akhir' => 22.0,
            'kelembaban_awal' => 57.0,
            'kelembaban_akhir' => 58.0,
            'measurements' => array_map(fn (array $t): array => [
                'titik_ukur' => $t[0],
                'satuan' => 'nm',
                'pembacaan' => $t[1],
                'standard_id' => $this->holmium->id,
            ], $titik),
        ];
    }

    private function simpan(string $tanggal): CalibrationSession
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload($tanggal))
            ->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    /** @return list<float> uc tiap titik, urut titik_ke */
    private function ucTersimpan(CalibrationSession $sesi): array
    {
        return $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get()
            ->map(fn ($t): float => (float) $t->ketidakpastian_gabungan)->all();
    }

    /** @return array<string, mixed>|null baris jejak parameter titik pertama */
    private function barisParameter(CalibrationSession $sesi): ?array
    {
        $titik = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->first();

        return collect($titik->type_b_components)->firstWhere('sumber', 'parameter_metode');
    }

    /** @return list<string> */
    private function kodeTemuan(CalibrationSession $sesi): array
    {
        $sesi = CalibrationSession::query()->findOrFail($sesi->id);

        return array_column(app(CalibrationValidator::class)->periksa($sesi)['temuan'], 'kode');
    }

    public function test_sesi_baru_memakai_pilihan_tm_dan_cocok_workbook_lalu_tercatat_di_jejak(): void
    {
        $this->pakaiPenetapan(self::KUNCI, 'tanpa_titik_pertama', $this->hariIni(5));

        $sesi = $this->simpan($this->hariIni(1));

        foreach ($this->ucTersimpan($sesi) as $uc) {
            $this->assertEqualsWithDelta(self::UC_EXCEL, $uc, self::TOLERANSI_SIMPAN, 'uc kelompok Holmium harus = J17 workbook');
        }

        $baris = $this->barisParameter($sesi);
        $this->assertNotNull($baris, 'jejak sesi wajib memuat baris parameter_metode');
        $this->assertSame(self::KUNCI, $baris['parameter']);
        $this->assertSame('tanpa_titik_pertama', $baris['pilihan']);
        $this->assertSame(1, $baris['penetapan']);
        $this->assertSame($this->hariIni(5), $baris['berlaku_mulai']);
        $this->assertSame($this->hariIni(1), $baris['tanggal_acuan']);
        $this->assertStringContainsString('SAMA dengan workbook master', $baris['keterangan']);

        $kode = collect($sesi->uncertaintyCalculations()->first()->type_b_components)->pluck('sumber');
        $this->assertContains('max_stdev_tanpa_titik_pertama', $kode->all(), 'selisih dari bawaan harus terbaca di jejak');
    }

    public function test_sesi_bertanggal_sebelum_berlaku_mulai_tetap_bawaan_dan_tercatat_bawaan(): void
    {
        $this->pakaiPenetapan(self::KUNCI, 'tanpa_titik_pertama', $this->hariIni(5));

        $lama = $this->simpan($this->hariIni(9));

        // STDEV titik 279,6 = 2,0 ikut MAX: uc jauh di atas workbook.
        foreach ($this->ucTersimpan($lama) as $uc) {
            $this->assertGreaterThan(self::UC_EXCEL * 2, $uc);
        }

        $baris = $this->barisParameter($lama);
        $this->assertSame('semua_titik', $baris['pilihan']);
        $this->assertSame(0, $baris['penetapan']);
        $this->assertTrue($baris['bawaan_kode']);
        $this->assertStringContainsString('BERBEDA dari workbook master', $baris['keterangan']);
    }

    public function test_preview_validator_dan_hitung_ulang_memakai_pilihan_yang_sama_dengan_simpan(): void
    {
        $this->pakaiPenetapan(self::KUNCI, 'tanpa_titik_pertama', $this->hariIni(5));

        $payload = $this->payload($this->hariIni(1));
        $sesi = $this->simpan($this->hariIni(1));
        $tersimpan = $this->ucTersimpan($sesi);

        // Preview: jalur hitung yang sama dengan simpan.
        $preview = $this->actingAs($this->teknisi)->postJson('/api/calibrations/preview', $payload)->assertOk()->json('data.titik');
        $this->assertEqualsWithDelta($tersimpan[0], (float) $preview[0]['ketidakpastian_gabungan'], self::TOLERANSI_SIMPAN);

        // Validator menghitung ulang dari mentah: tidak boleh ada selisih.
        $kode = $this->kodeTemuan($sesi);
        $this->assertNotContains('hitung_ulang_beda', $kode);
        $this->assertNotContains('hitung_ulang_gagal', $kode);

        // `kalibrasi:hitung-ulang` menulis ulang dengan pilihan yang sama: hasil dan jejak tidak berubah.
        $sebelum = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get()->map(fn ($t) => $t->type_b_components)->all();
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [(string) $sesi->id]])->assertExitCode(0);
        $sesudah = $sesi->fresh()->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertEqualsWithDelta($tersimpan[0], (float) $sesudah[0]->ketidakpastian_gabungan, self::TOLERANSI_SIMPAN);
        $this->assertSame(
            'tanpa_titik_pertama',
            collect($sesudah[0]->type_b_components)->firstWhere('sumber', 'parameter_metode')['pilihan'],
        );
        $this->assertCount(count($sebelum), $sesudah);
    }

    public function test_sesi_lama_tidak_bergeser_saat_penetapan_digeser_ke_belakang_harinya(): void
    {
        // Sesi dihitung dengan bawaan, SEBELUM ada penetapan apa pun.
        $lama = $this->simpan($this->hariIni(9));
        $sebelum = $this->ucTersimpan($lama);

        // Penetapan baru berlaku mulai 5 hari lalu: sesi 9 hari lalu di luar jangkauannya.
        $this->pakaiPenetapan(self::KUNCI, 'tanpa_titik_pertama', $this->hariIni(5));

        $this->assertNotContains('hitung_ulang_beda', $this->kodeTemuan($lama), 'validator tidak boleh menganggap sesi lama berubah');

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [(string) $lama->id]])->assertExitCode(0);
        $sesudah = $this->ucTersimpan($lama->fresh());

        foreach ($sebelum as $i => $uc) {
            $this->assertEqualsWithDelta($uc, $sesudah[$i], self::TOLERANSI_SIMPAN, "titik {$i} sesi lama bergeser");
        }
    }

    public function test_penetapan_yang_dicabut_sesudah_sesi_dihitung_tidak_diam_validator_melaporkan(): void
    {
        $this->pakaiPenetapan(self::KUNCI, 'tanpa_titik_pertama', $this->hariIni(5));
        $sesi = $this->simpan($this->hariIni(1));

        // Registri kembali ke berkas asli (tanpa penetapan): sesi yang dihitung dengan pilihan TM
        // sekarang dihitung ulang dengan bawaan. Itu pelanggaran aturan append-only dan harus berisik.
        ParameterKeputusanMetode::lupakan();

        $this->assertContains('hitung_ulang_beda', $this->kodeTemuan($sesi));
    }
}
