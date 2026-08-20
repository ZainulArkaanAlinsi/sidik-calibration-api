<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baris "Pembacaan Standar" Autoklaf SELALU Bar; salah satuan di situ nggak
 * boleh lolos jadi sertifikat.
 *
 * ## Kenapa test ini ada
 *
 * `uut_setting` & `resolusi_alat` ngikut satuan display alat (Bar/MPa/kPa/…),
 * tapi `pembacaan_standar` itu hasil unduh Pressure Disk Logger dan selalu Bar
 * — lihat `AutoclaveProfile::tabelPindai`. Jadi set point 0,11 MPa harus
 * dipasangin pembacaan standar 1,12 Bar, bukan 0,112.
 *
 * Diuji di MySQL 20 Agu 2026: ngetik 0,112 di baris Bar bikin koreksi keluar
 * −0,0988 MPa padahal semestinya +0,002 MPa — meleset 50 kali. Yang bikin ini
 * berbahaya bukan salahnya, tapi diamnya: hitungannya jalan mulus, validasinya
 * nol temuan, `boleh_terbit` true, approve 200, sertifikat TERBIT.
 *
 * Yang dikunci di sini dua-duanya — ketangkep waktu salah, DAN nggak berisik
 * waktu bener.
 */
class KoreksiTekananMustahilTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private User $admin;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create(['id' => 1]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
        $this->admin = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);

        $customer = Customer::factory()->create(['organization_id' => $org->id]);
        $category = EquipmentCategory::factory()->create(['organization_id' => $org->id]);
        $this->alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $customer->id,
            'equipment_category_id' => $category->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);
    }

    /**
     * @param  list<float>  $pembacaanStandar
     * @return array<string, mixed>
     */
    private function payload(array $pembacaanStandar): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'suhu_awal' => 25.0,
            'suhu_akhir' => 25.4,
            'kelembaban_awal' => 55,
            'kelembaban_akhir' => 55,
            'set_point' => 121.0,
            'suhu' => [
                'disk' => [
                    [121.1, 121.2, 121.0, 121.1, 121.2],
                    [121.0, 121.1, 121.1, 121.2, 121.0],
                    [121.2, 121.0, 121.1, 121.1, 121.1],
                ],
                'indikator' => [121, 121, 121, 121, 121],
                'suhu_ruang' => [25.0, 25.1, 25.2, 25.2, 25.3],
                'resolusi_alat' => 0.1,
            ],
            'tekanan' => [
                // 0,11 MPa = 1,1 Bar.
                'uut_setting' => 0.11,
                'satuan' => 'MPa',
                'display' => 'Digital',
                'resolusi_alat' => 0.001,
                'indikator_pressure' => [0.11, 0.11, 0.11, 0.11, 0.11],
                'pembacaan_standar' => $pembacaanStandar,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function temuanValidasi(int $id): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/calibrations/{$id}/validasi")
            ->assertOk()
            ->json('data.temuan');
    }

    public function test_pembacaan_standar_dalam_bar_nggak_bikin_peringatan(): void
    {
        // Bener: 1,12 Bar lawan set point 1,1 Bar → koreksi 0,02 Bar (1,8 %).
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payload([1.12, 1.11, 1.12, 1.13, 1.12]))
            ->assertCreated()
            ->json('data.id');

        $kode = array_column($this->temuanValidasi($id), 'kode');

        $this->assertNotContains(
            'koreksi_tekanan_mustahil',
            $kode,
            'Lembar yang satuannya bener nggak boleh kena peringatan — penjaga yang cerewet bakal dimatiin orang.',
        );
    }

    public function test_pembacaan_standar_salah_satuan_ketangkep_dan_nahan_sertifikat(): void
    {
        // Salah: 0,112 (angka MPa) diketik di baris yang satuannya Bar.
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payload([0.112, 0.111, 0.112, 0.113, 0.112]))
            ->assertCreated()
            ->json('data.id');

        $this->assertContains('koreksi_tekanan_mustahil', array_column($this->temuanValidasi($id), 'kode'));

        // Sebelum ditambal, ini 200 dan sertifikatnya terbit.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$id}/approve")
            ->assertStatus(422);

        $this->assertDatabaseMissing('certificates', ['calibration_session_id' => $id]);
    }

    public function test_admin_masih_bisa_lanjut_kalau_alatnya_emang_serusak_itu(): void
    {
        // PERINGATAN, bukan error: autoklaf yang beneran ngaco tetap harus bisa
        // disertifikasi — yang diminta cuma admin ngelihat dulu.
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payload([0.112, 0.111, 0.112, 0.113, 0.112]))
            ->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();
    }
}
