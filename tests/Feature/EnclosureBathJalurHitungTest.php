<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Standard;
use App\Models\User;
use App\Services\CalibrationValidator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enclosure **Bath** (efek radiasi & pembebanan = 0, master 12 Nov 2026) lewat
 * SEMUA jalur hitung: simpan (`POST /api/calibrations`), validator, dan
 * `kalibrasi:hitung-ulang` — ketiganya memanggil `EnclosureProfileBase::
 * hitungPerGrup()`, jadi aturan Bath harus muncul identik di ketiganya.
 *
 * Murni kalkulatornya diadu di `Tests\Unit\EnclosureBathNolTest`; di sini yang
 * dijaga: bendera Bath benar-benar sampai ke kalkulator lewat profil, catatan
 * sumber sel terbaca di `type_b_components` sesi, jenis lain (Oven) pada grid
 * yang SAMA tidak berubah, dan hitung ulang memulihkan angka Bath (bukan
 * aturan jenis lain).
 *
 * Grid = Yokogawa SP1 (15 °C, Incubator-02, Type N, master lama) yang diadu ke
 * master di `EnclosureBudgetTest`.
 */
class EnclosureBathJalurHitungTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 1e-9;

    /** u_c SISTEM grid ini untuk jenis LAIN (`EnclosureSesiTest::UC_SISTEM['yokogawa'][0]`). */
    private const UC_JENIS_LAIN = 0.624351802823;

    /** U hitung grid ini untuk jenis LAIN (`EnclosureSesiTest::U_HITUNG_SP1`) — di atas CMC Bath 1,2. */
    private const U_HITUNG_JENIS_LAIN = 1.224734443603675;

    private function alat(string $nama): Equipment
    {
        $kategori = EquipmentCategory::where('organization_id', 1)->where('nama', 'Suhu dan Kelembapan')->firstOrFail();

        return Equipment::factory()->create([
            'organization_id' => 1,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => $nama,
            'nama_alat_kemampuan' => $nama,
            'satuan' => '°C',
            'resolusi' => 0.1,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Equipment $alat, Standard $kalibrator): array
    {
        $fix = json_decode((string) file_get_contents(base_path('tests/Fixtures/enclosure-golden.json')), true)['yokogawa']['setpoints'][0];

        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2024-05-02',
            'tipe_sensor' => 'Type N',
            'suhu_awal' => 23.7, 'suhu_akhir' => 23.7,
            'kelembaban_awal' => 47, 'kelembaban_akhir' => 46,
            'standar_dicek' => [['standard_id' => $kalibrator->id, 'dipakai' => true]],
            'measurements' => [[
                'titik_ukur' => 15.0,
                'satuan' => '°C',
                'sensor_grid' => array_map(
                    static fn (array $s): array => ['no' => $s['no'], 'pembacaan' => $s['pembacaan']],
                    $fix['sensors'],
                ),
                'indikator' => $fix['indikator'],
            ]],
        ];
    }

    /** @return array{CalibrationSession, User, Standard} */
    private function simpan(string $namaAlat): array
    {
        $this->seed(DatabaseSeeder::class);

        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();
        $kalibrator = Standard::where('nama', 'Temperature Calibrator Yokogawa CA 150 Handy Cal')->firstOrFail();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($this->alat($namaAlat), $kalibrator))
            ->assertCreated()
            ->json('data.id');

        return [CalibrationSession::findOrFail($id), $teknisi, $kalibrator];
    }

    public function test_sesi_bath_tersimpan_dengan_aturan_bath_dan_catatan_sumber_sel(): void
    {
        [$sesi] = $this->simpan('Bath');
        $baris = $sesi->uncertaintyCalculations()->sole();
        $komponen = collect($baris->type_b_components);

        foreach (['efek_radiasi', 'efek_pembebanan'] as $sumber) {
            $k = $komponen->firstWhere('sumber', $sumber);
            $this->assertNotNull($k, $sumber);
            $this->assertEqualsWithDelta(0.0, (float) $k['nilai'], self::TOLERANSI, "{$sumber} Bath = 0");
        }
        // Konduksi panas (N33) TIDAK ikut dikosongkan.
        $this->assertEqualsWithDelta(0.1 / sqrt(3.0), (float) $komponen->firstWhere('sumber', 'konduksi_panas')['nilai'], self::TOLERANSI);

        // u_c Bath = u_c jenis lain tanpa dua komponen itu (Pembebanan = 20% × Keseragaman).
        $ks = (float) $komponen->firstWhere('sumber', 'sebaran_sensor')['keseragaman'];
        $this->assertEqualsWithDelta(
            sqrt(self::UC_JENIS_LAIN ** 2 - (0.6 ** 2 + (0.2 * $ks) ** 2) / 3.0),
            (float) $baris->ketidakpastian_gabungan,
            1e-7,
        );

        // Catatan penyimpangan: sumber sel master + angka pembanding, terbaca tanpa membuka kode.
        $catatan = $komponen->firstWhere('sumber', 'bath_radiasi_pembebanan_nol');
        $this->assertNotNull($catatan);
        $this->assertStringContainsString('PERHITUNGAN U95%!N31', $catatan['keterangan']);
        $this->assertStringContainsString('N32', $catatan['keterangan']);
        $this->assertStringContainsString('N33', $catatan['keterangan']);
        $this->assertSame('PERHITUNGAN U95%!N31,N32 (+N57/N58, N83/N84, N109/N110, N135/N136, N161/N162)', $catatan['rincian']['sel_master']);
        $this->assertGreaterThan(
            (float) $catatan['rincian']['u_hitung_sistem'],
            (float) $catatan['rincian']['u_hitung_aturan_jenis_lain'],
        );

        // CMC Bath 1,2 °C: U hitung Bath (~1,0) di bawahnya, jadi lantai CMC yang tercetak.
        $this->assertEqualsWithDelta(1.2, (float) $baris->ketidakpastian_diperluas, self::TOLERANSI);
        $this->assertSame('cmc', $komponen->firstWhere('sumber', 'konteks_sesi')['sumber_u95']);
    }

    public function test_oven_pada_grid_yang_sama_tidak_berubah(): void
    {
        [$sesi] = $this->simpan('Oven');
        $baris = $sesi->uncertaintyCalculations()->sole();
        $komponen = collect($baris->type_b_components);

        $this->assertEqualsWithDelta(self::UC_JENIS_LAIN, (float) $baris->ketidakpastian_gabungan, 1e-7);
        $this->assertEqualsWithDelta(0.6 / sqrt(3.0), (float) $komponen->firstWhere('sumber', 'efek_radiasi')['nilai'], self::TOLERANSI);
        $this->assertNull($komponen->firstWhere('sumber', 'bath_radiasi_pembebanan_nol'), 'catatan Bath hanya untuk Bath');
    }

    /**
     * Validator menghitung ulang dari mentah dengan aturan Bath: sesi Bath utuh
     * tidak kena `hitung_ulang_beda`, dan angka tersimpan yang diganti ke versi
     * jenis lain KETAHUAN (membuktikan hitung ulangnya memakai aturan Bath).
     */
    public function test_validator_memakai_aturan_bath(): void
    {
        [$sesi] = $this->simpan('Bath');

        $kode = static fn (CalibrationSession $s): array => array_column(
            app(CalibrationValidator::class)->periksa($s->fresh())['temuan'],
            'kode',
        );

        $this->assertNotContains('hitung_ulang_beda', $kode($sesi));
        $this->assertNotContains('hitung_ulang_gagal', $kode($sesi));

        // Validator membandingkan U95 yang DILAPORKAN (bukan u_c): versi jenis lain 1,2247
        // (di atas lantai CMC Bath), hitung ulang Bath 1,2 (lantai CMC).
        $sesi->uncertaintyCalculations()->update(['ketidakpastian_diperluas' => self::U_HITUNG_JENIS_LAIN]);

        $this->assertContains('hitung_ulang_beda', $kode($sesi), 'U95 versi jenis lain harus ketahuan beda dari hitung ulang Bath');
    }

    /** `kalibrasi:hitung-ulang` memulihkan angka Bath — bukan menulis aturan jenis lain. */
    public function test_perintah_hitung_ulang_memakai_aturan_bath(): void
    {
        [$sesi] = $this->simpan('Bath');
        $asli = (float) $sesi->uncertaintyCalculations()->sole()->ketidakpastian_gabungan;

        $sesi->uncertaintyCalculations()->update([
            'ketidakpastian_gabungan' => self::UC_JENIS_LAIN,
            'ketidakpastian_diperluas' => self::U_HITUNG_JENIS_LAIN,
        ]);

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [(string) $sesi->id]])->assertSuccessful();

        $baris = $sesi->fresh()->uncertaintyCalculations()->sole();
        $this->assertEqualsWithDelta($asli, (float) $baris->ketidakpastian_gabungan, self::TOLERANSI);
        $this->assertEqualsWithDelta(1.2, (float) $baris->ketidakpastian_diperluas, self::TOLERANSI);
        $this->assertNotNull(collect($baris->type_b_components)->firstWhere('sumber', 'bath_radiasi_pembebanan_nol'));
    }
}
