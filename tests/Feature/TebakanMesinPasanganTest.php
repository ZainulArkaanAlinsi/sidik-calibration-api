<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Tebakan mesin di lembar BERPASANGAN (Thermocouple, Termometer Gelas,
 * Thermohygro) ikut tersimpan — dan mendarat di SISI yang benar.
 *
 * ## Yang paling mahal kalau salah di sini
 *
 * Tiap titik punya DUA deret: sisi standar dan sisi UUT. Yang tercetak di
 * sertifikat `Correction` — selisih keduanya. Jadi tebakan yang tertukar sisi
 * nggak cuma salah alamat: dia bikin selisih yang diukur bergeser, dan
 * geserannya nggak ngasih gejala apa pun karena kedua angkanya tetap wajar.
 *
 * Ketiga lembar ini `pindai_foto.didukung = true` — kameranya beneran dipakai
 * di sini, beda dari Timbangan & Timer yang jalur fotonya mendarat di tempat
 * lain.
 */
class TebakanMesinPasanganTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisasi;

    private User $teknisi;

    private Equipment $alat;

    private Standard $kalibrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisasi = Organization::factory()->create();
        $this->teknisi = User::factory()->create(['organization_id' => $this->organisasi->id]);

        $this->alat = Equipment::factory()->create([
            'organization_id' => $this->organisasi->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->organisasi->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $this->organisasi->id,
                'kode' => 'suhu',
                'nama' => 'Suhu dan Kelembapan',
            ])->id,
            'nama_alat' => 'Thermocouple Thermometer',
            'nama_alat_kemampuan' => 'Thermocouple',
            'satuan' => '°C',
            'resolusi' => 0.1,
            'toleransi' => null,
            'range_min' => 0,
            'range_max' => 600,
        ]);

        $this->kalibrator = Standard::factory()->create([
            'organization_id' => $this->organisasi->id,
            'nama' => 'Temperature Calibrator Yokogawa CA 150 Handy Cal',
            'merk' => 'Yokogawa',
        ]);
    }

    /**
     * @param  array<string, mixed>  $tambahan
     */
    private function kirim(array $tambahan, string $metode = 'ocr'): int
    {
        return $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->kalibrator->id,
            'input_method' => $metode,
            'tanggal_kalibrasi' => '2024-12-03',
            'lokasi' => 'lab',
            'tipe_sensor' => 'Type K',
            'suhu_awal' => 24.5, 'suhu_akhir' => 24.6,
            'kelembaban_awal' => 61, 'kelembaban_akhir' => 62,
            'measurements' => [[
                'titik_ukur' => 50,
                'no_probe' => 1,
                'standar' => [49.5, 49.6],
                'uut' => [49.9, 50.0],
                ...$tambahan,
            ]],
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, RawMeasurement>
     */
    private function baris(int $sesi, string $peran)
    {
        return RawMeasurement::where('calibration_session_id', $sesi)
            ->where('peran_sensor', $peran)
            ->orderBy('pembacaan_ke')
            ->get();
    }

    public function test_tebakan_mendarat_di_sisi_yang_benar(): void
    {
        // Angka yang sengaja mirip antar-sisi: kalau tertukar, jumlah barisnya
        // tetap pas dan angkanya tetap wajar — cuma `Correction`-nya bergeser.
        $sesi = $this->kirim([
            'standar_ocr' => [['raw_text' => '49.5', 'confidence' => 0.9], null],
            'uut_ocr' => [['raw_text' => '49.9', 'confidence' => 0.8], null],
        ]);

        $this->assertSame('49.5', $this->baris($sesi, 'standar')[0]->ocr_raw_text);
        $this->assertSame('49.9', $this->baris($sesi, 'uut')[0]->ocr_raw_text);

        $this->assertSame(0.9, (float) $this->baris($sesi, 'standar')[0]->ocr_confidence);
        $this->assertSame(0.8, (float) $this->baris($sesi, 'uut')[0]->ocr_confidence);
    }

    public function test_deret_sejajar_indeks_bukan_dirapatkan(): void
    {
        // Cuma pembacaan KEDUA yang dari kamera.
        $sesi = $this->kirim([
            'standar_ocr' => [null, ['raw_text' => '49.6']],
        ]);

        $baris = $this->baris($sesi, 'standar');

        $this->assertNull($baris[0]->ocr_raw_text, 'Pembacaan 1 diketik tangan.');
        $this->assertSame('49.6', $baris[1]->ocr_raw_text);
    }

    public function test_baris_bermetadata_nunggu_verifikasi_walau_sesinya_manual(): void
    {
        $sesi = $this->kirim([
            'standar_ocr' => [['raw_text' => '49.5'], null],
        ], metode: 'manual');

        $baris = $this->baris($sesi, 'standar');

        $this->assertFalse((bool) $baris[0]->is_verified);
        $this->assertSame('ocr', $baris[0]->input_source);

        $this->assertTrue((bool) $baris[1]->is_verified);
        $this->assertSame('manual', $baris[1]->input_source);
    }

    public function test_tanpa_metadata_perilakunya_persis_seperti_sebelumnya(): void
    {
        $sesi = $this->kirim([], metode: 'manual');

        foreach (['standar', 'uut'] as $peran) {
            foreach ($this->baris($sesi, $peran) as $b) {
                $this->assertTrue((bool) $b->is_verified);
                $this->assertSame('manual', $b->input_source);
                $this->assertNull($b->ocr_raw_text);
            }
        }
    }

    public function test_bentuk_lama_pembacaan_bikin_tebakannya_ikut_pindah_ke_sisi_uut(): void
    {
        // Klien lama mengirim `pembacaan` polos; server memetakannya ke sisi
        // UUT. Kalau tebakannya nggak ikut pindah, deret `uut` punya angka tapi
        // tebakannya nyangkut di kunci yang nggak pernah dibaca.
        $sesi = $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->kalibrator->id,
            'input_method' => 'ocr',
            'tanggal_kalibrasi' => '2024-12-03',
            'tipe_sensor' => 'Type K',
            'suhu_awal' => 24.5, 'suhu_akhir' => 24.6,
            'kelembaban_awal' => 61, 'kelembaban_akhir' => 62,
            'measurements' => [[
                'titik_ukur' => 50,
                'pembacaan' => [49.9, 50.0],
                'ocr' => [['raw_text' => '49.9', 'confidence' => 0.7], null],
            ]],
        ])->assertCreated()->json('data.id');

        $this->assertSame('49.9', $this->baris($sesi, 'uut')[0]->ocr_raw_text);
    }

    public function test_sel_pasangan_kehitung_di_ocr_akurasi_kamera(): void
    {
        $this->kirim([
            // Sisi UUT dibaca 58.9 padahal teknisi mengirim 49.9, dan mesinnya
            // yakin — hijau palsu.
            'uut_ocr' => [['raw_text' => '58.9', 'confidence' => 0.96], null],
        ]);

        Artisan::call('ocr:akurasi-kamera');
        $keluaran = Artisan::output();

        $this->assertStringContainsString('HIJAU PALSU', $keluaran);
        $this->assertStringContainsString('58.9', $keluaran);
    }
}
