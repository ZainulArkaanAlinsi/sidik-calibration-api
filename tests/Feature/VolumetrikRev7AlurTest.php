<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CalibrationValidator;
use App\Services\DataTampilanSertifikat;
use App\Support\VolumetricGlasswareMentah as M;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Alur Labu Ukur & Pipet Volume (workbook lab Rev.7) dari kiriman HP sampai
 * sertifikat — celah yang ditemukan simulasi 7 workbook (8 Okt 2026):
 *
 *  - sesi HP tanpa `standard_id` memunculkan `standar_titik_hilang` palsu dan
 *    hitung ulang validator DILEWATI;
 *  - sesi tersimpan 201 dengan nol hitungan tanpa satu kalimat pun;
 *  - neraca Fujitsu, thermobarometer Lutron, dan termometer standar Yokogawa
 *    tidak bisa dipilih / tidak tertaut, dan masa berlakunya tidak diperiksa
 *    terhadap tanggal kalibrasi;
 *  - isi sertifikat di luar angka berbeda dari sheet `SERTIFIKAT`.
 *
 * Angka masukan = `INPUT DATA` workbook LU-200-1 (nama pelanggan tidak
 * disalin). Kondisi lingkungan & thermobarometer Lutron = `PERHITUNGAN!E16:Q19`.
 */
class VolumetrikRev7AlurTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private EquipmentCategory $kategori;

    private User $teknisi;

    /** @var array<string, Standard> */
    private array $standar = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Jam dipatok: masa berlaku standar dibandingkan dengan hari ini DAN
        // dengan tanggal kalibrasi, jadi test tidak boleh bergantung jam dinding.
        $this->travelTo('2026-10-08 10:00:00');
        Storage::fake('local');

        $this->org = Organization::factory()->create();
        $this->kategori = EquipmentCategory::factory()->create([
            'organization_id' => $this->org->id, 'kode' => 'volume', 'nama' => 'Volume',
        ]);

        foreach ([
            'Labu Ukur' => [[100, 0.026], [200, 0.044], [250, 0.044], [500, 0.077]],
            'Pipet Volume' => [[0.5, 0.002], [1, 0.003], [2, 0.0034]],
            'Picnometer' => [[10, 0.004], [25, 0.006], [50, 0.01]],
            'Gelas Ukur' => [[10, 0.067], [50, 0.17], [100, 0.34]],
            'Pipet Ukur' => [[1, 0.006], [5, 0.012], [10, 0.025]],
        ] as $nama => $pita) {
            foreach ($pita as [$maks, $cmc]) {
                // `forceCreate`, bukan factory: `nama_alat` bawaan factory itu
                // `unique()` dari enam nama, dan lima belas baris di sini
                // menghabiskannya walau nilainya ditimpa.
                CalibrationCapability::forceCreate([
                    'sumber' => CalibrationCapability::SUMBER_AKREDITASI,
                    'organization_id' => $this->org->id,
                    'equipment_category_id' => $this->kategori->id,
                    'nama_alat' => $nama, 'range_min' => null, 'range_max' => $maks,
                    'satuan' => 'mL', 'ketidakpastian_terbaik' => $cmc, 'satuan_ketidakpastian' => 'mL',
                    'faktor_cakupan' => 2, 'metode' => 'SIDIK-IK-CAL-0510_Rev.7',
                ]);
            }
        }

        $buat = fn (string $kunci, array $atribut): Standard => $this->standar[$kunci] = Standard::factory()->create([
            'organization_id' => $this->org->id,
            'berlaku_sampai' => '2027-01-19',
            ...$atribut,
        ]);

        $buat('mettler', ['nama' => 'Analytical Balance', 'merk' => 'Mettler Toledo', 'model' => 'XS204',
            'serial_number' => '1129063525', 'tertelusur_ke' => 'LK-305-IDN']);
        $buat('excellent', ['nama' => 'Electronic Balance Excellent', 'merk' => 'Excellent', 'model' => 'DJ',
            'serial_number' => 'HSEX1403752', 'tertelusur_ke' => 'LK-305-IDN']);
        $buat('fujitsu', ['nama' => 'Electronic Balance Fujitsu', 'merk' => 'Fujitsu', 'model' => 'FSR-A',
            'serial_number' => 'SIDIK/134/2024', 'tertelusur_ke' => 'LK-305-IDN']);
        $buat('termometer', ['nama' => 'Termometer & Sensor Std.', 'merk' => 'Yokogawa', 'model' => 'CA 150 Handy Cal',
            'serial_number' => '23P1005', 'tertelusur_ke' => 'LK-285-IDN', 'berlaku_sampai' => '2027-08-12']);
        $buat('prt', ['nama' => 'PRT Pt-100', 'merk' => null, 'model' => 'PT100',
            'serial_number' => 'SH1/20', 'tertelusur_ke' => 'SNSU-BSN', 'berlaku_sampai' => '2027-02-14']);
        $buat('lutron', ['nama' => 'Thermobarometer Lutron', 'serial_number' => 'AM02225',
            'tertelusur_ke' => 'LK-172-IDN', 'berlaku_sampai' => '2027-06-26',
            'parameter_kondisi' => [
                'suhu' => ['indexed_value' => 20.1, 'correction' => 0.1, 'u95' => 1.2],
                'kelembaban' => ['indexed_value' => 48.5, 'correction' => -1.5, 'u95' => 3],
                'tekanan' => ['indexed_value' => 1001, 'correction' => 1, 'u95' => 2],
            ]]);

        $this->teknisi = User::factory()->create([
            'organization_id' => $this->org->id, 'role' => User::ROLE_TEKNISI, 'status' => User::STATUS_AKTIF,
            'kode_teknisi' => 'CA',
        ]);
    }

    private function alat(string $kemampuan, float $kapasitas, ?float $resolusi = null): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => Customer::firstOrCreate(
                ['organization_id' => $this->org->id, 'nama' => 'PT CONTOH VOLUMETRIK'],
                ['alamat' => 'Jl. Contoh 1'],
            )->id,
            'equipment_category_id' => $this->kategori->id,
            'nama_alat' => $kemampuan, 'nama_alat_kemampuan' => $kemampuan,
            'satuan' => 'ml', 'range_min' => 0, 'range_max' => $kapasitas, 'resolusi' => $resolusi,
            'serial_number' => 'VOL-'.uniqid(),
        ]);
    }

    /**
     * Kiriman persis bentuk HP lembar Labu Ukur: TANPA `standard_id` (lembar
     * Volumetric tidak punya kotaknya), thermohygro Lutron.
     *
     * @return array<string, mixed>
     */
    private function payloadLabu(Equipment $alat, array $timpa = []): array
    {
        return array_replace_recursive([
            'equipment_id' => $alat->id,
            'thermohygro_standard_id' => $this->standar['lutron']->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-10-06',
            'tanggal_terima' => '2026-09-26',
            'lokasi' => 'lab',
            'suhu_awal' => 20.9, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 51, 'kelembaban_akhir' => 50,
            'tekanan_awal' => 1001.2, 'tekanan_akhir' => 1001.5,
            'measurements' => [[
                'titik_ukur' => 200,
                M::PERAN_KOSONG => [0, 0, 0],
                M::PERAN_ISI => [199.026, 199.027, 199.031],
                M::PERAN_SUHU => [25.2, 25.3, 25.2],
            ]],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'kelas' => 'A', 'toleransi_ml' => 0.15, 'kapasitas_ml' => 200,
                'neraca' => 'Electronic Balance Fujitsu',
            ]],
        ], $timpa);
    }

    private function simpan(array $payload): CalibrationSession
    {
        $id = $this->actingAs($this->teknisi)->postJson('/api/calibrations', $payload)
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    /** @return list<string> */
    private function kodeTemuan(CalibrationSession $sesi): array
    {
        return array_column(app(CalibrationValidator::class)->periksa($sesi->fresh())['temuan'], 'kode');
    }

    /** @return array<string, mixed> */
    private function temuanBerkode(CalibrationSession $sesi, string $kode): array
    {
        return array_values(array_filter(
            app(CalibrationValidator::class)->periksa($sesi->fresh())['temuan'],
            static fn (array $t): bool => $t['kode'] === $kode,
        ));
    }

    // ── Bagian 1.1 — validator benar-benar menghitung ulang sesi HP ──────────

    public function test_sesi_hp_labu_ukur_tanpa_standard_id_tanpa_peringatan_palsu_dan_dihitung_ulang(): void
    {
        $sesi = $this->simpan($this->payloadLabu($this->alat('Labu Ukur', 200)));

        // Neraca blok `spesifikasi_alat` jadi standar acuan sesi.
        $this->assertSame($this->standar['fujitsu']->id, $sesi->standard_id);
        $this->assertSame($this->standar['fujitsu']->id, $sesi->uncertaintyCalculations()->sole()->standard_id);

        $hasil = app(CalibrationValidator::class)->periksa($sesi->fresh());
        $kode = array_column($hasil['temuan'], 'kode');
        $pesan = implode(' | ', array_column($hasil['temuan'], 'pesan'));

        $this->assertNotContains('standar_titik_hilang', $kode, $pesan);
        $this->assertNotContains('hitung_ulang_gagal', $kode, $pesan);
        $this->assertNotContains('hitung_ulang_beda', $kode, $pesan);
        $this->assertNotContains('standar_kadaluarsa', $kode, $pesan);
        $this->assertTrue($hasil['boleh_terbit'], $pesan);

        // BUKTI hitung ulangnya jalan: angka tersimpan yang digeser ketahuan.
        $sesi->uncertaintyCalculations()->update(['rata_rata' => 199.5]);
        $this->assertContains('hitung_ulang_beda', $this->kodeTemuan($sesi));
    }

    /** Pengecualian berlaku untuk seluruh keluarga Volumetric, termasuk Graduated. */
    public function test_sesi_hp_gelas_ukur_tanpa_standard_id_ikut_dihitung_ulang(): void
    {
        $alat = $this->alat('Gelas Ukur', 100, 1);
        $sesi = $this->simpan([
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-10-06',
            'suhu_awal' => 20.4, 'suhu_akhir' => 20.5,
            'kelembaban_awal' => 64, 'kelembaban_akhir' => 62,
            'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
            'measurements' => [
                ['titik_ukur' => 10, M::PERAN_KOSONG => [60.234, 60.24, 60.243],
                    M::PERAN_ISI => [70.7791, 70.7965, 70.8854], M::PERAN_SUHU => [25.4, 25.3, 25.4]],
                ['titik_ukur' => 50, M::PERAN_KOSONG => [60.255, 60.258, 60.263],
                    M::PERAN_ISI => [110.944, 110.9023, 111.0863], M::PERAN_SUHU => [25.4, 25.3, 25.5]],
            ],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'kelas' => 'B', 'toleransi_ml' => 0.5, 'resolusi_ml' => 1, 'kapasitas_ml' => 100,
                'neraca' => 'Electronic Balance Precisa',
            ]],
        ]);

        $this->assertNull($sesi->standard_id, 'Graduated tidak ikut turunan neraca Rev.7');
        $this->assertCount(2, $sesi->uncertaintyCalculations);

        $kode = $this->kodeTemuan($sesi);
        $this->assertNotContains('standar_titik_hilang', $kode);
        $this->assertNotContains('hitung_ulang_gagal', $kode);

        $sesi->uncertaintyCalculations()->where('titik_ke', 1)->update(['rata_rata' => 11.5]);
        $this->assertContains('hitung_ulang_beda', $this->kodeTemuan($sesi));
    }

    // ── Bagian 1.2 — sesi tanpa angka punya alasan yang terbaca ─────────────

    public function test_sesi_tanpa_hitungan_memulangkan_alasan_di_respons_dan_validator(): void
    {
        // Persis probe simulasi: alat bernama kemampuan "Pipet Ukur" (berskala)
        // dengan satu titik.
        $alat = $this->alat('Pipet Ukur', 2, 0.1);

        $balasan = $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-10-06',
            'suhu_awal' => 20.9, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 51, 'kelembaban_akhir' => 50,
            'tekanan_awal' => 1001.2, 'tekanan_akhir' => 1001.5,
            'measurements' => [[
                'titik_ukur' => 2,
                M::PERAN_KOSONG => [0, 0, 0],
                M::PERAN_ISI => [1.9816, 1.9827, 1.9815],
                M::PERAN_SUHU => [25.2, 25.3, 25.2],
            ]],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'kelas' => 'A', 'toleransi_ml' => 0.01, 'resolusi_ml' => 0.1, 'kapasitas_ml' => 2,
                'neraca' => 'Analytical Balance',
            ]],
        ])->assertCreated();

        $sesi = CalibrationSession::findOrFail($balasan->json('data.id'));
        $this->assertSame(0, $sesi->uncertaintyCalculations()->count());

        // Respons simpan: bentuk sama dengan `data.belum_dihitung` preview.
        $belum = $balasan->json('meta.belum_dihitung');
        $this->assertCount(1, $belum);
        $this->assertSame(1, $belum[0]['titik_ke']);
        $this->assertStringContainsString('minimal dua titik', $belum[0]['alasan']);

        // Validator: alasan aslinya, bukan cuma sebab umum.
        $temuan = $this->temuanBerkode($sesi, 'volumetric_titik_belum_dihitung');
        $this->assertCount(1, $temuan);
        $this->assertStringContainsString('minimal dua titik', $temuan[0]['pesan']);
        $this->assertContains('titik_kosong', $this->kodeTemuan($sesi));
    }

    public function test_sesi_yang_terhitung_memulangkan_belum_dihitung_kosong(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payloadLabu($this->alat('Labu Ukur', 200)))
            ->assertCreated()
            ->assertJsonPath('meta.belum_dihitung', []);
    }

    // ── Bagian 2.1 & 2.2 — Fujitsu, termometer, Lutron bisa dipilih ──────────

    public function test_lembar_labu_ukur_menawarkan_fujitsu_termometer_dan_lutron(): void
    {
        foreach (['Labu Ukur' => 200, 'Pipet Volume' => 2] as $kemampuan => $kapasitas) {
            $alat = $this->alat($kemampuan, $kapasitas);
            $bentuk = app(CalibrationProfileRegistry::class)->untukAlat($alat)->bentukLembarKerja(false, $alat);
            $bagian = collect($bentuk['bagian'])->keyBy('kode');

            $baris = collect($bagian['usage_check']['baris'])->keyBy('label');
            $this->assertSame($this->standar['fujitsu']->id, $baris['Balance Fujitsu']['standard_id'], $kemampuan);
            $this->assertSame(
                $this->standar['termometer']->id,
                $baris['Termometer & Sensor Std. (Yokogawa CA 150)']['standard_id'],
                $kemampuan,
            );

            $thermohygro = collect($bagian['identitas_alat']['field'])->firstWhere('kode', 'thermohygro_standard_id');
            $this->assertContains((string) $this->standar['lutron']->id, array_column($thermohygro['pilihan'], 'nilai'));

            $this->assertStringContainsString('Rev.7', $bentuk['budget_ketidakpastian']['sumber']);
            $this->assertStringContainsString('Tujuh komponen', $bentuk['budget_ketidakpastian']['catatan']);
        }
    }

    public function test_lembar_picnometer_tidak_berubah(): void
    {
        $alat = $this->alat('Picnometer', 25);
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($alat);
        $bentuk = $profil->bentukLembarKerja(false, $alat);
        $bagian = collect($bentuk['bagian'])->keyBy('kode');

        $this->assertSame(
            ['Balance Excellent', 'Balance Mettler Toledo', 'RTD Sensor'],
            array_column($bagian['usage_check']['baris'], 'label'),
        );
        $thermohygro = collect($bagian['identitas_alat']['field'])->firstWhere('kode', 'thermohygro_standard_id');
        $this->assertNotContains((string) $this->standar['lutron']->id, array_column($thermohygro['pilihan'], 'nilai'));
        $this->assertStringContainsString('Delapan komponen', $bentuk['budget_ketidakpastian']['catatan']);
        $this->assertNull($profil->standarSesiDariSpesifikasi([M::KUNCI_SESI => ['neraca' => 'Analytical Balance']], $alat));
    }

    /** Centang satu neraca = neraca blok (pola Piston/Gaya); dua neraca ditolak saat dikirim. */
    public function test_neraca_dari_centang_menang_atas_dropdown_dan_dua_neraca_ditolak(): void
    {
        $alat = $this->alat('Labu Ukur', 200);

        $sesi = $this->simpan($this->payloadLabu($alat, [
            'standar_dicek' => [['standard_id' => $this->standar['fujitsu']->id, 'dipakai' => true]],
            'spesifikasi_alat' => [M::KUNCI_SESI => ['neraca' => 'Analytical Balance']],
        ]));

        $this->assertSame('Electronic Balance Fujitsu', $sesi->spesifikasi_alat[M::KUNCI_SESI]['neraca']);
        $this->assertSame($this->standar['fujitsu']->id, $sesi->standard_id);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payloadLabu($alat, [
            'standar_dicek' => [
                ['standard_id' => $this->standar['fujitsu']->id, 'dipakai' => true],
                ['standard_id' => $this->standar['mettler']->id, 'dipakai' => true],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('standar_dicek');
    }

    // ── Bagian 2.3 & 2.4 — termometer tertaut, masa berlaku vs tanggal kalibrasi

    public function test_termometer_kedaluwarsa_pada_tanggal_kalibrasi_jadi_error(): void
    {
        // Workbook: `DATABASE!Z22` 12 Agt 2026, dikalibrasi 6 Okt 2026.
        $this->standar['termometer']->update(['berlaku_sampai' => '2026-08-12']);
        $sesi = $this->simpan($this->payloadLabu($this->alat('Labu Ukur', 200)));

        // Hari ini dimundurkan ke SEBELUM masa berlakunya habis: aturan lama
        // (`masihBerlaku()`, hari ini) diam — yang menangkap tanggal kalibrasi.
        $this->travelTo('2026-08-01 10:00:00');

        $temuan = $this->temuanBerkode($sesi, 'standar_kadaluarsa');
        $this->assertCount(1, $temuan);
        $this->assertSame('error', $temuan[0]['tingkat']);
        $this->assertSame($this->standar['termometer']->id, $temuan[0]['konteks']['standard_id'] ?? $temuan[0]['standard_id'] ?? null);
        $this->assertStringContainsString('kedaluwarsa pada tanggal kalibrasi', $temuan[0]['pesan']);
        $this->assertFalse(app(CalibrationValidator::class)->periksa($sesi->fresh())['boleh_terbit']);
    }

    public function test_thermohygro_dan_neraca_kedaluwarsa_pada_tanggal_kalibrasi_jadi_error(): void
    {
        $this->standar['lutron']->update(['berlaku_sampai' => '2026-06-26']);
        $this->standar['fujitsu']->update(['berlaku_sampai' => '2026-10-05']);
        $sesi = $this->simpan($this->payloadLabu($this->alat('Labu Ukur', 200)));
        $this->travelTo('2026-06-01 10:00:00');

        $id = array_map(
            static fn (array $t): ?int => $t['konteks']['standard_id'] ?? $t['standard_id'] ?? null,
            $this->temuanBerkode($sesi, 'standar_kadaluarsa'),
        );
        sort($id);
        $harap = [$this->standar['fujitsu']->id, $this->standar['lutron']->id];
        sort($harap);
        $this->assertSame($harap, $id);
    }

    /** Aturan lama TIDAK dilonggarkan: sah saat kalibrasi tapi lewat hari ini tetap error. */
    public function test_standar_yang_lewat_hari_ini_tetap_error(): void
    {
        $this->standar['prt']->update(['berlaku_sampai' => '2026-10-07']);
        $sesi = $this->simpan($this->payloadLabu($this->alat('Labu Ukur', 200)));

        $temuan = $this->temuanBerkode($sesi, 'standar_kadaluarsa');
        $this->assertCount(1, $temuan);
        $this->assertStringContainsString('udah lewat masa berlaku', $temuan[0]['pesan']);
    }

    public function test_dikalibrasi_tepat_di_hari_terakhir_masa_berlaku_masih_sah(): void
    {
        $this->standar['termometer']->update(['berlaku_sampai' => '2026-10-06']);
        $sesi = $this->simpan($this->payloadLabu($this->alat('Labu Ukur', 200)));
        $this->travelTo('2026-10-05 10:00:00');

        $this->assertSame([], $this->temuanBerkode($sesi, 'standar_kadaluarsa'));
    }

    public function test_standar_suhu_yang_tidak_terdaftar_diperingatkan(): void
    {
        $this->standar['prt']->delete();
        $sesi = $this->simpan($this->payloadLabu($this->alat('Labu Ukur', 200)));

        $temuan = $this->temuanBerkode($sesi, 'volumetric_standar_suhu_tidak_terdaftar');
        $this->assertCount(1, $temuan);
        $this->assertStringContainsString('PRT Pt-100', $temuan[0]['pesan']);
    }

    // ── Keputusan 8 Okt 2026 (terakhir): angka ikut workbook + penjaga ────────

    public function test_kondisi_workbook_lu200_tidak_memunculkan_peringatan_pergeseran(): void
    {
        $sesi = $this->simpan($this->payloadLabu($this->alat('Labu Ukur', 200)));

        // Bawaan `master`: V20 = cache workbook `PERHITUNGAN!H60`.
        $this->assertEqualsWithDelta(199.85125467654402, (float) $sesi->uncertaintyCalculations()->sole()->rata_rata, 1e-8);
        $this->assertSame([], $this->temuanBerkode($sesi, 'volumetric_suhu_25_5_menggeser_cetak'));
    }

    /** LU-500: `master` 500,24 lawan suhu terukur 500,25 — peringatan HARUS muncul. */
    public function test_lu500_memunculkan_peringatan_pergeseran_angka_cetak(): void
    {
        $sesi = $this->simpan($this->payloadLabu($this->alat('Labu Ukur', 500), [
            'measurements' => [[
                'titik_ukur' => 500,
                M::PERAN_ISI => [498.184, 498.184, 498.184],
            ]],
            'spesifikasi_alat' => [M::KUNCI_SESI => ['toleransi_ml' => 0.25, 'kapasitas_ml' => 500]],
        ]));

        $this->assertEqualsWithDelta(500.2446764263289, (float) $sesi->uncertaintyCalculations()->sole()->rata_rata, 1e-8);

        $temuan = $this->temuanBerkode($sesi, 'volumetric_suhu_25_5_menggeser_cetak');
        $this->assertCount(1, $temuan);
        $this->assertSame('peringatan', $temuan[0]['tingkat']);
        $this->assertStringContainsString('V20 500,24 mL, Correction 0,24 mL', $temuan[0]['pesan']);
        $this->assertStringContainsString('V20 500,25 mL, Correction 0,25 mL', $temuan[0]['pesan']);
        $this->assertStringContainsString('PERHITUNGAN!H40', $temuan[0]['pesan']);
        $this->assertStringContainsString('no. 14', $temuan[0]['pesan']);
        // Peringatan, bukan penahan: masih boleh terbit dengan konfirmasi.
        $this->assertTrue(app(CalibrationValidator::class)->periksa($sesi->fresh())['boleh_terbit']);
    }

    public function test_suhu_air_jauh_dari_25_5_memunculkan_peringatan(): void
    {
        $sesi = $this->simpan($this->payloadLabu($this->alat('Labu Ukur', 200), [
            'measurements' => [[M::PERAN_SUHU => [22.0, 22.0, 22.0]]],
        ]));

        $this->assertCount(1, $this->temuanBerkode($sesi, 'volumetric_suhu_25_5_menggeser_cetak'));
    }

    /** PV 2 mL: `SERTIFIKAT!N21` `0.00` — layar HP & sertifikat sama-sama 2 desimal. */
    public function test_pipet_volume_2_ml_dua_desimal_di_layar_dan_sertifikat(): void
    {
        $sesi = $this->simpan($this->payloadLabu($this->alat('Pipet Volume', 2), [
            'measurements' => [[
                'titik_ukur' => 2,
                M::PERAN_ISI => [1.9816, 1.9827, 1.9815],
            ]],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'toleransi_ml' => 0.01, 'kapasitas_ml' => 2, 'neraca' => 'Analytical Balance',
            ]],
        ]));

        $this->actingAs($this->teknisi)->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.titik.0.desimal', 2);

        $sertifikat = $this->terbitkan($sesi);
        $this->assertSame(2, $sertifikat->snapshot['hasil'][0]['desimal']);
        $this->assertSame(4, $sertifikat->snapshot['hasil'][0]['desimal_u95']);

        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();
        $this->assertStringContainsString('2,00', $html);
        $this->assertStringContainsString('1,99', $html);
        $this->assertStringContainsString('0,0034', $html);
    }

    // ── Bagian 3 — isi sertifikat mengikuti sheet `SERTIFIKAT` ───────────────

    private function terbitkan(CalibrationSession $sesi): Certificate
    {
        $this->sebagaiPemeriksaLain($sesi)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    public function test_sertifikat_labu_ukur_mengikuti_sheet_sertifikat_workbook(): void
    {
        $sertifikat = $this->terbitkan($this->simpan($this->payloadLabu($this->alat('Labu Ukur', 200))));
        $snapshot = $sertifikat->snapshot;

        // `SERTIFIKAT!T11:Y13`: T 20.9 ± 1.2 °C, RH 49 ± 3.2 %, tekanan 1001 ± 2.0 hPa
        // (rata-rata BACAAN tekanan, `G19`, tanpa koreksi Lutron).
        $this->assertSame(
            'T: 20,9°C ± 1,2°C — %RH: 49% ± 3,2% — P: 1001 hPa ± 2,0 hPa',
            $snapshot['header']['env_condition'],
        );

        // `SERTIFIKAT!O14` & `E18`.
        $this->assertSame(
            'Class/Permitted Error : A/±0,15 ml — Suhu Dasar Volume : 20 °C',
            $snapshot['catatan_atas_hasil'],
        );

        // `SERTIFIKAT!B29:W30`: neraca + Termometer & Sensor Std., sensor PRT
        // tidak dicetak terpisah.
        $this->assertSame(
            [
                ['name' => 'Electronic Balance Fujitsu', 'merk_type' => 'Fujitsu/FSR-A', 'serial_number' => 'SIDIK/134/2024', 'traceable_to' => 'LK-305-IDN'],
                ['name' => 'Termometer & Sensor Std.', 'merk_type' => 'Yokogawa/CA 150 Handy Cal', 'serial_number' => '23P1005', 'traceable_to' => 'LK-285-IDN'],
            ],
            $snapshot['standar_digunakan'],
        );

        // Nominal dengan nol di belakang (`E21` format `0.00`).
        $this->assertFalse($snapshot['hasil'][0]['standar_ringkas']);

        $bahan = app(DataTampilanSertifikat::class)->untuk($sertifikat);
        $html = view('sertifikat.pdf', $bahan)->render();
        $this->assertStringContainsString('200,00', $html);
        $this->assertStringContainsString('199,85', $html);
        $this->assertStringContainsString('Class/Permitted Error : A/±0,15 ml', $html);

        // Tetap SATU halaman, mode normal.
        $pdf = Pdf::loadView('sertifikat.pdf', [...$bahan, 'paksaPadat' => false]);
        $pdf->output();
        $this->assertSame(1, (int) $pdf->getDomPDF()->getCanvas()->get_page_count());
    }

    public function test_sertifikat_pipet_volume_mencetak_nominal_tiga_desimal(): void
    {
        $alat = $this->alat('Pipet Volume', 0.5);
        $sertifikat = $this->terbitkan($this->simpan($this->payloadLabu($alat, [
            'measurements' => [[
                'titik_ukur' => 0.5,
                M::PERAN_ISI => [0.4927, 0.4935, 0.4929],
            ]],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'toleransi_ml' => 0.005, 'kapasitas_ml' => 0.5, 'neraca' => 'Analytical Balance',
            ]],
        ])));

        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();
        $this->assertStringContainsString('0,500', $html);
        $this->assertStringContainsString('Class/Permitted Error : A/±0,005 ml', $html);
        $this->assertSame('Analytical Balance', $sertifikat->snapshot['standar_digunakan'][0]['name']);
        $this->assertSame('Termometer & Sensor Std.', $sertifikat->snapshot['standar_digunakan'][1]['name']);
    }

    /** Sertifikat alat lain (Picnometer, Fixed lama) tidak berubah bentuk. */
    public function test_sertifikat_picnometer_tidak_ikut_berubah(): void
    {
        $alat = $this->alat('Picnometer', 25);
        $sesi = $this->simpan([
            'equipment_id' => $alat->id,
            'standard_id' => $this->standar['mettler']->id,
            'thermohygro_standard_id' => $this->standar['lutron']->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-10-06',
            'suhu_awal' => 20.9, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 51, 'kelembaban_akhir' => 50,
            'tekanan_awal' => 1001.2, 'tekanan_akhir' => 1001.5,
            'measurements' => [[
                'titik_ukur' => 25,
                M::PERAN_KOSONG => [20.1, 20.1, 20.1],
                M::PERAN_ISI => [45.03, 45.04, 45.03],
                M::PERAN_SUHU => [25.2, 25.3, 25.2],
            ]],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'kelas' => 'A', 'toleransi_ml' => 0.04, 'kapasitas_ml' => 25, 'neraca' => 'Analytical Balance',
            ]],
        ]);

        $snapshot = $this->terbitkan($sesi)->snapshot;

        $this->assertNull($snapshot['catatan_atas_hasil']);
        $this->assertStringNotContainsString('hPa', (string) $snapshot['header']['env_condition']);
        $this->assertSame(['Analytical Balance'], array_column($snapshot['standar_digunakan'], 'name'));
        $this->assertTrue($snapshot['hasil'][0]['standar_ringkas']);
    }
}
