<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Tebakan mesin di lembar GRID (Enclosure) ikut tersimpan.
 *
 * ## Kenapa grid butuh jalurnya sendiri
 *
 * Sepuluh alat lain mengirim `measurements[].pembacaan` datar, dan tebakannya
 * nempel di `measurements[].ocr`. Grid nggak punya bentuk itu: tiap set point
 * berisi sampai 40 baris termokopel, plus baris Indikator dan Suhu Ruang yang
 * bentuknya DERET ANGKA POLOS — bukan objek — jadi tebakannya nggak bisa
 * dititipkan di dalam barisnya sendiri.
 *
 * ## Yang paling gampang salah, dan dijaga di sini
 *
 * Kesejajaran indeks. Server membaca `$ocr[$urutan]` dengan indeks `pembacaan`
 * yang sama persis; deret yang dirapatkan bikin tebakan Repeat 3 tercatat
 * sebagai tebakan Repeat 1. Salah pasangan begitu nggak pernah kelihatan —
 * angkanya wajar, jumlahnya pas, dan yang bohong cuma pasangannya.
 */
class TebakanMesinGridEnclosureTest extends TestCase
{
    use RefreshDatabase;

    private Equipment $alat;

    private Standard $standar;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->alat = Equipment::where('serial_number', 'D132469')->firstOrFail();
        $this->standar = Standard::where('nama', 'Temperature Calibrator Yokogawa CA 150 Handy Cal')->firstOrFail();
        $this->teknisi = User::where('role', User::ROLE_TEKNISI)
            ->where('status', User::STATUS_AKTIF)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $tambahan
     */
    private function kirim(array $tambahan, string $metode = 'ocr'): int
    {
        return $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => $metode,
            'tanggal_kalibrasi' => '2024-05-02',
            'tipe_sensor' => 'Type N',
            'suhu_awal' => 23.7, 'suhu_akhir' => 23.7,
            'kelembaban_awal' => 47, 'kelembaban_akhir' => 46,
            'measurements' => [[
                'titik_ukur' => 15.0,
                'satuan' => '°C',
                'sensor_grid' => [['no' => 3, 'pembacaan' => [15.1, 15.2]]],
                'indikator' => [15.0, 15.0],
                ...$tambahan,
            ]],
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return Collection<int, RawMeasurement>
     */
    private function baris(int $sesi, string $peran)
    {
        return RawMeasurement::where('calibration_session_id', $sesi)
            ->where('peran_sensor', $peran)
            ->orderBy('pembacaan_ke')
            ->get();
    }

    public function test_tebakan_termokopel_tersimpan_sejajar_indeksnya(): void
    {
        // Cuma Repeat 2 yang dari kamera. Kalau deretnya dirapatkan, tebakan
        // ini bakal mendarat di Repeat 1 — angkanya wajar, pasangannya salah.
        $sesi = $this->kirim([
            'sensor_grid' => [[
                'no' => 3,
                'pembacaan' => [15.1, 15.2],
                'ocr' => [null, ['raw_text' => '1S.2', 'confidence' => 0.88]],
            ]],
        ]);

        $baris = $this->baris($sesi, 'termokopel');

        $this->assertCount(2, $baris);
        $this->assertNull($baris[0]->ocr_raw_text, 'Repeat 1 diketik tangan.');
        $this->assertSame('1S.2', $baris[1]->ocr_raw_text);
        $this->assertSame(0.88, (float) $baris[1]->ocr_confidence);
    }

    public function test_tebakan_indikator_dan_suhu_ruang_tersimpan(): void
    {
        $sesi = $this->kirim([
            'indikator' => [15.0, 15.0],
            'indikator_ocr' => [['raw_text' => '15.O'], null],
            'suhu_ruang' => [24.6],
            'suhu_ruang_ocr' => [['raw_text' => '24,6', 'confidence' => 0.7]],
        ]);

        $this->assertSame('15.O', $this->baris($sesi, 'indikator')[0]->ocr_raw_text);

        $suhu = $this->baris($sesi, 'suhu_ruang')[0];
        $this->assertSame('24,6', $suhu->ocr_raw_text);
        $this->assertSame(0.7, (float) $suhu->ocr_confidence);
    }

    public function test_baris_bermetadata_nunggu_verifikasi_walau_sesinya_manual(): void
    {
        // Pintu KEDUA. Sebelum ini grid cuma membaca `input_method`, jadi baris
        // yang beneran dari kamera lolos jadi terverifikasi begitu sesinya
        // tercatat manual — dan gerbang approve nggak pernah bunyi.
        $sesi = $this->kirim([
            'sensor_grid' => [[
                'no' => 3,
                'pembacaan' => [15.1, 15.2],
                'ocr' => [['raw_text' => '15.1'], null],
            ]],
        ], metode: 'manual');

        $baris = $this->baris($sesi, 'termokopel');

        $this->assertFalse((bool) $baris[0]->is_verified, 'Baris dari kamera wajib nunggu mata teknisi.');
        $this->assertSame('ocr', $baris[0]->input_source);

        $this->assertTrue((bool) $baris[1]->is_verified, 'Baris yang diketik tangan langsung terverifikasi.');
        $this->assertSame('manual', $baris[1]->input_source);
    }

    public function test_tanpa_metadata_perilakunya_persis_seperti_sebelumnya(): void
    {
        $sesi = $this->kirim([], metode: 'manual');

        foreach ($this->baris($sesi, 'termokopel') as $b) {
            $this->assertTrue((bool) $b->is_verified);
            $this->assertSame('manual', $b->input_source);
            $this->assertNull($b->ocr_raw_text);
        }
    }

    public function test_baris_grid_ikut_kehitung_di_ocr_akurasi_kamera(): void
    {
        $this->kirim([
            'sensor_grid' => [[
                'no' => 3,
                'pembacaan' => [15.1, 15.2],
                // Yang kedua salah baca DAN mesinnya yakin — hijau palsu.
                'ocr' => [['raw_text' => '15.1', 'confidence' => 0.9], ['raw_text' => '18.2', 'confidence' => 0.95]],
            ]],
        ]);

        Artisan::call('ocr:akurasi-kamera');
        $keluaran = Artisan::output();

        $this->assertStringContainsString('HIJAU PALSU', $keluaran);
        $this->assertStringContainsString('18.2', $keluaran);
    }
}
