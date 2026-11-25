<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\ParameterMetodePenetapan;
use App\Models\Standard;
use App\Models\User;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use App\Services\CalibrationValidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Studio Tahap 1 (25 Nov 2026), JALUR PENUH: penetapan dicatat, disetujui, dan
 * ditarik lewat endpoint Studio, lalu sesi kalibrasi sungguhan disimpan lewat
 * `POST /api/calibrations` dan dibaca angkanya.
 *
 * Dua hal yang dikunci di sini dan tidak cukup dijaga tes per-bagian:
 *
 *  - Hanya status `disetujui` yang menggeser angka. `menunggu_tm` dan `ditarik`
 *    adalah catatan: sesi yang dihitung selagi penetapan masih menunggu atau
 *    sudah ditarik harus identik dengan bawaan kode.
 *  - Lingkup tanggal #298 tetap berlaku untuk penetapan DB: sesi bertanggal
 *    sejak `berlaku_mulai` memakai pilihan baru, sesi bertanggal sebelumnya
 *    tetap bawaan, dan sesi yang sudah tersimpan tidak bergeser oleh penetapan
 *    yang datang sesudahnya.
 *
 * Data uji sama dengan `ParameterMetodeSesiSpektroTest` (titik 279,6 nm ber-STDEV
 * 2,0 supaya dua pilihan menghasilkan angka berbeda). Angka acuan = cache sel
 * workbook `Master Olah Data_Spectrofotometer.xlsm`, `'PERHITUNGAN U95%'!J17`.
 *
 * Tanggal memakai hari server sungguhan, bukan jam beku: aturan D3 membandingkan
 * `berlaku_mulai` dengan tanggal server saat dicatat, dan sesi tidak boleh
 * bertanggal di depan hari ini.
 */
class ParameterMetodePenetapanStudioSesiTest extends TestCase
{
    use RefreshDatabase;

    /** Toleransi simpan: kolom `decimal(20,8)` (lihat `SpectrophotometerApiTest`). */
    private const TOLERANSI_SIMPAN = 1e-8;

    private const KUNCI = 'spectrophotometer.max_stdev_holmium';

    private const UC_EXCEL = 0.1359196779955751;

    private const STUDIO = ['X-Klien' => 'sidik-studio', 'X-Versi-Klien' => '1.0.0'];

    private User $teknisi;

    private string $tokenStudio;

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

        $pemilik = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
            'email' => 'pemilik@sidik.test',
        ]);
        config(['studio.akun' => 'pemilik@sidik.test']);
        $this->tokenStudio = $pemilik->createToken('studio', ['studio'])->plainTextToken;

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

    private function hariIni(int $mundur = 0): string
    {
        return CarbonImmutable::today()->subDays($mundur)->toDateString();
    }

    /** Request sebagai akun Studio; header token dilepas sesudahnya supaya tidak bocor ke request teknisi. */
    private function studio(string $metode, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $respons = $this->withToken($this->tokenStudio)->json($metode, $uri, $data, self::STUDIO);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $respons;
    }

    private function catat(string $berlakuMulai): int
    {
        return (int) $this->studio('POST', '/api/studio/parameter-metode/'.self::KUNCI.'/penetapan', [
            'nilai' => 'tanpa_titik_pertama',
            'berlaku_mulai' => $berlakuMulai,
            'catatan' => 'Jawaban TM untuk uji alur penuh.',
        ])->assertCreated()->json('data.id');
    }

    private function setujui(int $id): void
    {
        $this->studio('POST', "/api/studio/parameter-metode/penetapan/{$id}/setujui", [
            'keputusan_oleh' => 'TM Spektro (uji)',
            'tanggal_keputusan' => $this->hariIni(),
        ])->assertOk()->assertJsonPath('data.status', 'disetujui');
    }

    private function tarik(int $id): TestResponse
    {
        return $this->studio('POST', "/api/studio/parameter-metode/penetapan/{$id}/tarik", [
            'alasan' => 'Penetapan dibatalkan dalam uji alur penuh.',
        ])->assertOk();
    }

    /** @return array<string, mixed> */
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
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

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

    /** @return array<string, mixed> */
    private function barisParameter(CalibrationSession $sesi): array
    {
        $titik = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->firstOrFail();
        $baris = collect($titik->type_b_components)->firstWhere('sumber', 'parameter_metode');

        $this->assertNotNull($baris, 'jejak sesi wajib memuat baris parameter_metode');

        return $baris;
    }

    /** @return list<string> */
    private function kodeTemuan(CalibrationSession $sesi): array
    {
        $sesi = CalibrationSession::query()->findOrFail($sesi->id);

        return array_column(app(CalibrationValidator::class)->periksa($sesi)['temuan'], 'kode');
    }

    private function assertBawaan(CalibrationSession $sesi, string $pesan): void
    {
        foreach ($this->ucTersimpan($sesi) as $uc) {
            $this->assertGreaterThan(self::UC_EXCEL * 2, $uc, $pesan.': STDEV 2,0 harus ikut MAX');
        }

        $baris = $this->barisParameter($sesi);
        $this->assertSame('semua_titik', $baris['pilihan'], $pesan);
        $this->assertSame(0, $baris['penetapan'], $pesan);
        $this->assertTrue($baris['bawaan_kode'], $pesan);
    }

    public function test_penetapan_menunggu_tm_tidak_menggeser_angka_sesi(): void
    {
        $id = $this->catat($this->hariIni());

        $this->assertSame(ParameterMetodePenetapan::STATUS_MENUNGGU_TM, ParameterMetodePenetapan::query()->findOrFail($id)->status);

        $this->assertBawaan($this->simpan($this->hariIni()), 'sesi hari ini, penetapan masih menunggu TM');
    }

    public function test_setelah_disetujui_sesi_sejak_berlaku_mulai_memakai_pilihan_baru_dan_sesi_lebih_lama_tetap_bawaan(): void
    {
        $id = $this->catat($this->hariIni());
        $this->setujui($id);

        $baru = $this->simpan($this->hariIni());

        foreach ($this->ucTersimpan($baru) as $uc) {
            $this->assertEqualsWithDelta(self::UC_EXCEL, $uc, self::TOLERANSI_SIMPAN, 'uc kelompok Holmium harus = J17 workbook');
        }

        $baris = $this->barisParameter($baru);
        $this->assertSame(self::KUNCI, $baris['parameter']);
        $this->assertSame('tanpa_titik_pertama', $baris['pilihan']);
        $this->assertSame(1, $baris['penetapan'], 'nomor penetapan DB ikut tercatat di jejak sesi');
        $this->assertFalse($baris['bawaan_kode']);
        $this->assertSame($this->hariIni(), $baris['berlaku_mulai']);
        $this->assertSame($this->hariIni(), $baris['tanggal_acuan']);

        // Sesi bertanggal SEBELUM berlaku_mulai: di luar jangkauan penetapan, tetap bawaan.
        $this->assertBawaan($this->simpan($this->hariIni(3)), 'sesi tiga hari sebelum berlaku_mulai');
    }

    public function test_sesi_yang_sudah_tersimpan_tidak_bergeser_saat_penetapan_baru_disetujui(): void
    {
        $lama = $this->simpan($this->hariIni(3));
        $sebelum = $this->ucTersimpan($lama);

        $this->setujui($this->catat($this->hariIni()));

        $this->assertNotContains('hitung_ulang_beda', $this->kodeTemuan($lama), 'validator tidak boleh menganggap sesi lama berubah');

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [(string) $lama->id]])->assertExitCode(0);

        foreach ($this->ucTersimpan($lama->fresh()) as $i => $uc) {
            $this->assertEqualsWithDelta($sebelum[$i], $uc, self::TOLERANSI_SIMPAN, "titik {$i} sesi lama bergeser");
        }
    }

    public function test_penetapan_yang_ditarik_mengembalikan_sesi_baru_ke_bawaan_dan_validator_melaporkan_sesi_yang_terlanjur_dihitung(): void
    {
        $id = $this->catat($this->hariIni());
        $this->setujui($id);

        $terlanjur = $this->simpan($this->hariIni());
        $this->assertEqualsWithDelta(self::UC_EXCEL, $this->ucTersimpan($terlanjur)[0], self::TOLERANSI_SIMPAN);
        $this->assertNotContains('hitung_ulang_beda', $this->kodeTemuan($terlanjur));

        $this->tarik($id)->assertJsonPath('data.status', 'ditarik');

        $this->assertBawaan($this->simpan($this->hariIni()), 'sesi hari ini sesudah penetapan ditarik');

        // Hasil tersimpan tidak berubah, tetapi hitung ulang kini memakai bawaan: harus berisik.
        $this->assertContains('hitung_ulang_beda', $this->kodeTemuan($terlanjur));
    }

    public function test_penetapan_ditarik_sebelum_disetujui_tidak_pernah_menggeser_angka(): void
    {
        $id = $this->catat($this->hariIni());
        $this->tarik($id);

        $this->assertBawaan($this->simpan($this->hariIni()), 'sesi hari ini, penetapan ditarik saat masih menunggu');
    }
}
