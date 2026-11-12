<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\KonflikSerialAlat;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Nomor seri alat yang sudah DIHAPUS (soft delete) masih memegang indeks unik
 * `(organization_id, serial_number)`. Dulu validasi mengabaikan baris terhapus,
 * jadi nomor seri itu lolos lalu INSERT gagal di indeks (500).
 *
 * Sekarang 422 dengan pesan yang menyebut alat yang dihapus; baris lama TIDAK
 * dipulihkan otomatis; balapan (indeks yang memutuskan) dijawab 422 yang sama.
 * Unik per LABORATORIUM tetap: lab lain boleh memakai nomor yang sama.
 */
class SerialAlatTerhapusTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Customer $pt;

    private EquipmentCategory $kategori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->pt = Customer::factory()->create(['organization_id' => $this->org->id]);
        $this->kategori = EquipmentCategory::factory()->create(['organization_id' => $this->org->id, 'kode' => 'panjang']);
    }

    /** @return array<string, mixed> */
    private function isian(string $serial): array
    {
        return [
            'nama_alat' => 'Feeler Gauge',
            'serial_number' => $serial,
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pt->id,
        ];
    }

    private function alat(string $serial, ?Organization $org = null): Equipment
    {
        $org ??= $this->org;

        return Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $org->is($this->org)
                ? $this->pt->id
                : Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => $org->is($this->org)
                ? $this->kategori->id
                : EquipmentCategory::factory()->create(['organization_id' => $org->id])->id,
            'serial_number' => $serial,
        ]);
    }

    public function test_serial_alat_yang_sudah_dihapus_ditolak_422_dan_tidak_dipulihkan(): void
    {
        $lama = $this->alat('SN-LAMA-1');
        $lama->delete();

        $balasan = $this->actingAs($this->admin)->postJson('/api/equipments', $this->isian('SN-LAMA-1'));

        $balasan->assertStatus(422)
            ->assertJsonPath('errors.serial_number.0', KonflikSerialAlat::PESAN_DIPAKAI_ALAT_DIHAPUS);
        $this->assertStringContainsString('sudah dihapus', $balasan->json('errors.serial_number.0'));

        // Tidak ada alat baru, dan baris lama tetap terhapus (tidak dipulihkan).
        $this->assertSame(1, Equipment::withTrashed()->where('serial_number', 'SN-LAMA-1')->count());
        $this->assertTrue(Equipment::withTrashed()->findOrFail($lama->id)->trashed());
    }

    public function test_serial_alat_hidup_tetap_pesan_lama(): void
    {
        $this->alat('SN-HIDUP-1');

        $this->actingAs($this->admin)->postJson('/api/equipments', $this->isian('SN-HIDUP-1'))
            ->assertStatus(422)
            ->assertJsonPath('errors.serial_number.0', KonflikSerialAlat::PESAN_DIPAKAI_ALAT_LAIN);
    }

    public function test_serial_baru_dan_serial_lab_lain_tetap_boleh(): void
    {
        $this->actingAs($this->admin)->postJson('/api/equipments', $this->isian('SN-BARU-1'))->assertCreated();

        // Unik per LABORATORIUM: lab lain yang punya nomor sama (juga yang sudah
        // dihapus di sana) tidak menghalangi.
        $labLain = Organization::factory()->create();
        $this->alat('SN-LAB-LAIN', $labLain)->delete();

        $this->actingAs($this->admin)->postJson('/api/equipments', $this->isian('SN-LAB-LAIN'))->assertCreated();
    }

    public function test_ubah_serial_ke_serial_alat_terhapus_ditolak_tapi_serial_sendiri_boleh(): void
    {
        $this->alat('SN-DIHAPUS')->delete();
        $aktif = $this->alat('SN-AKTIF');

        $this->actingAs($this->admin)->putJson("/api/equipments/{$aktif->id}", ['serial_number' => 'SN-DIHAPUS'])
            ->assertStatus(422)
            ->assertJsonPath('errors.serial_number.0', KonflikSerialAlat::PESAN_DIPAKAI_ALAT_DIHAPUS);

        $this->actingAs($this->admin)->putJson("/api/equipments/{$aktif->id}", ['serial_number' => 'SN-AKTIF', 'lokasi' => 'Lab 2'])
            ->assertOk();
        $this->assertSame('SN-AKTIF', $aktif->fresh()->serial_number);
    }

    /**
     * Balapan: dua request lolos validasi bersamaan, INSERT kedua dihentikan
     * indeks. Disimulasikan dengan baris pesaing yang masuk TEPAT sebelum
     * INSERT (sesudah validasi lewat).
     */
    public function test_balapan_dengan_alat_hidup_dijawab_422_bukan_500(): void
    {
        Equipment::creating(function (Equipment $baru): void {
            $this->sisipkanPesaing($baru->serial_number, hapus: false);
        });

        $this->actingAs($this->admin)->postJson('/api/equipments', $this->isian('SN-BALAP-1'))
            ->assertStatus(422)
            ->assertJsonPath('errors.serial_number.0', KonflikSerialAlat::PESAN_DIPAKAI_ALAT_LAIN);

        $this->assertSame(1, Equipment::withTrashed()->where('serial_number', 'SN-BALAP-1')->count());
    }

    public function test_balapan_dengan_alat_terhapus_memakai_pesan_alat_dihapus(): void
    {
        Equipment::creating(function (Equipment $baru): void {
            $this->sisipkanPesaing($baru->serial_number, hapus: true);
        });

        $this->actingAs($this->admin)->postJson('/api/equipments', $this->isian('SN-BALAP-2'))
            ->assertStatus(422)
            ->assertJsonPath('errors.serial_number.0', KonflikSerialAlat::PESAN_DIPAKAI_ALAT_DIHAPUS);
    }

    public function test_balapan_saat_ubah_serial_juga_422(): void
    {
        $aktif = $this->alat('SN-UBAH-ASAL');

        Equipment::updating(function (Equipment $model): void {
            if ($model->isDirty('serial_number')) {
                $this->sisipkanPesaing($model->serial_number, hapus: false);
            }
        });

        $this->actingAs($this->admin)->putJson("/api/equipments/{$aktif->id}", ['serial_number' => 'SN-UBAH-BARU'])
            ->assertStatus(422)
            ->assertJsonPath('errors.serial_number.0', KonflikSerialAlat::PESAN_DIPAKAI_ALAT_LAIN);

        $this->assertSame('SN-UBAH-ASAL', $aktif->fresh()->serial_number);
    }

    public function test_galat_unik_yang_bukan_soal_serial_tidak_ditelan(): void
    {
        $galat = new UniqueConstraintViolationException(
            'mysql',
            'insert into x',
            [],
            new \Exception("Duplicate entry 'a' for key 'x.nomor_unik'"),
        );

        $this->expectException(UniqueConstraintViolationException::class);
        KonflikSerialAlat::terjemahkan($galat, $this->org->id, 'SN-APA-SAJA');
    }

    private function sisipkanPesaing(string $serial, bool $hapus): void
    {
        DB::table('equipments')->insert([
            'organization_id' => $this->org->id,
            'customer_id' => $this->pt->id,
            'equipment_category_id' => $this->kategori->id,
            'nama_alat' => 'Pesaing',
            'serial_number' => $serial,
            'status' => 'aktif',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $hapus ? now() : null,
        ]);
    }
}
