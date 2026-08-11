<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sesi yang cuma sebagian titiknya dikalibrasi TETAP boleh terbit sertifikat,
 * dan tabelnya cuma memuat yang beneran diisi.
 *
 * Arahan lab 11 Agt 2026: "walau misal cuma 2 atau berapa aja, yang penting
 * hasilnya nanti bakal keluar jadi sertifikat sesuai dengan tabel data yang
 * diisi pas input kalibrasi."
 *
 * Ini bukan kasus karangan. Di lapangan larutan standar bisa habis, dan buat
 * Conductivity malah NORMAL: titik tengah punya dua varian satuan yang saling
 * meniadakan, jadi salah satunya SELALU kosong by design.
 *
 * `LembarKerjaTest` udah ngunci sisi perhitungannya (titik kosong nggak bikin
 * baris hantu, pengulangan kosong nggak nyeret rata-rata). Yang belum kekunci
 * sisi SERTIFIKATNYA — dan itu ujung yang dilihat pelanggan. Docblock
 * `LembarKerjaTest` bahkan nulis "lembar setengah jadi nggak pernah lolos jadi
 * sertifikat", yang gampang kebaca sebagai "titik kosong = approve diblokir".
 * Bukan: yang diblokir titik yang datanya SETENGAH (1 pembacaan), bukan titik
 * yang sengaja nggak dikalibrasi.
 */
class SertifikatTitikSebagianTest extends TestCase
{
    use RefreshDatabase;

    public function test_titik_yang_nggak_diisi_nggak_muncul_di_sertifikat(): void
    {
        Organization::factory()->create();
        $admin = User::factory()->admin()->create();
        $teknisi = User::factory()->create();

        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'nama_alat' => 'pH Meter',
            'range_min' => 0,
            'range_max' => 14,
            'satuan' => 'pH',
            'resolusi' => 0.01,
            'toleransi' => 0.2,
        ]);
        $buffer4 = Standard::factory()->create(['nama' => 'pH Buffer Solutions 4']);

        // Satu titik keisi 3 dari 5 pengulangan; dua titik lain nggak
        // dikalibrasi sama sekali (dua bentuk kosong: null semua & array kosong).
        $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => $buffer4->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'suhu_awal' => 24.5,
            'suhu_akhir' => 24.5,
            'kelembaban_awal' => 55,
            'kelembaban_akhir' => 55,
            'measurements' => [
                [
                    'titik_ukur' => 4.00,
                    'pembacaan' => [4.01, 4.00, 4.01, null, null],
                    'suhu' => [24.5, 24.6, 24.5, null, null],
                ],
                ['titik_ukur' => 7.00, 'pembacaan' => [null, null, null, null, null]],
                ['titik_ukur' => 10.01, 'pembacaan' => []],
            ],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $this->assertSame(1, $sesi->uncertaintyCalculations()->count());

        // Rata-ratanya ikut 3 pembacaan, bukan 5 — slot kosong nggak kehitung
        // sebagai nol, yang bakal nyeret rata-rata ke bawah.
        $this->assertSame(
            3,
            $sesi->uncertaintyCalculations()->where('titik_ke', 1)->value('jumlah_pengulangan'),
        );

        // Titik yang nggak dikalibrasi TIDAK nahan penerbitan.
        $validasi = $this->actingAs($admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->json();
        $v = $validasi['data'] ?? $validasi;

        $this->assertTrue($v['boleh_terbit'], 'Titik kosong nggak boleh nahan sertifikat.');

        $kode = array_column($v['temuan'] ?? [], 'kode');
        $this->assertNotContains('titik_belum_dihitung', $kode);

        $this->actingAs($admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        // Inti tes ini: tabel sertifikat ikut apa yang DIISI, bukan bentuk
        // lembar kerjanya.
        $snapshot = Certificate::latest('id')->firstOrFail()->snapshot;

        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        $this->assertCount(
            1,
            $snapshot['hasil'],
            'Tabel sertifikat harus cuma punya titik yang beneran dikalibrasi.',
        );
        $this->assertSame(1, $snapshot['hasil'][0]['titik_ke']);
        $this->assertEqualsWithDelta(4.0, (float) $snapshot['hasil'][0]['standard_value'], 1e-9);
    }
}
