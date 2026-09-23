<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Equipment\Pages\ListEquipment;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Support\JejakLintasOrganisasi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Super admin membaca seluruh lab — dan tiap layarnya tercatat.
 *
 * ## Dua aturan yang harus jalan bersamaan
 *
 * AGENTS.md §Peran butir 2 memberi super admin bacaan tanpa batas; §35
 * `docs/permintaan-user-7.md` menerima penembusan `organization_id` itu DENGAN
 * syarat tiap aksesnya tercatat, karena yang ditembus kerahasiaan antar
 * pelanggan (ISO/IEC 17025 klausul 4.2).
 *
 * Yang berbahaya kalau keduanya dipisah: lingkupnya melebar, catatannya
 * tertinggal, dan nol error muncul — lab cuma kehilangan jawaban untuk "siapa
 * yang pernah melihat data PT B" pada saat asesor menanyakannya.
 *
 * ## Yang SENGAJA tidak dicatat
 *
 * Selama `organizations` cuma berisi satu baris, "lintas organisasi" belum ada
 * artinya: yang terbaca super admin persis sama dengan yang terbaca admin.
 * Mencatatnya cuma menumpuk baris yang tidak menjawab pertanyaan siapa pun, dan
 * jejak yang berisik sama tidak terbacanya dengan jejak yang kosong. Per 23 Sep
 * 2026 produksi memang masih satu organisasi.
 */
class SuperAdminLintasOrganisasiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $labSendiri;

    private Organization $labLain;

    private Equipment $alatLabLain;

    protected function setUp(): void
    {
        parent::setUp();

        JejakLintasOrganisasi::lupakan();

        $this->labSendiri = Organization::factory()->create(['nama' => 'PT Contoh Satu']);
        $this->labLain = Organization::factory()->create(['nama' => 'PT Contoh Dua']);

        $this->alatLabLain = Equipment::factory()->create([
            'organization_id' => $this->labLain->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $this->labLain->id,
            ])->id,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'organization_id' => $this->labSendiri->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);
    }

    public function test_super_admin_melihat_alat_lab_lain(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(ListEquipment::class)
            ->assertCanSeeTableRecords([$this->alatLabLain]);
    }

    /** Admin biasa tetap terkurung di labnya — yang melebar cuma satu peran. */
    public function test_admin_tetap_tidak_melihat_lab_lain(): void
    {
        $admin = User::factory()->admin()->create(['organization_id' => $this->labSendiri->id]);

        Livewire::actingAs($admin)
            ->test(ListEquipment::class)
            ->assertCanNotSeeTableRecords([$this->alatLabLain]);
    }

    public function test_aksesnya_tercatat(): void
    {
        $super = $this->superAdmin();

        Livewire::actingAs($super)->test(ListEquipment::class);

        $jejak = AuditLog::where('entity_type', 'akses_lintas_organisasi')->latest('id')->first();

        $this->assertNotNull($jejak, 'Super admin membaca lintas lab tanpa meninggalkan jejak.');
        $this->assertSame(AuditLog::ACTION_DIBACA, $jejak->action);
        $this->assertSame($super->id, $jejak->changed_by);
        $this->assertSame('Equipment', $jejak->new_data['layar'] ?? null);
    }

    /**
     * Satu baris per layar, bukan per pemanggilan.
     *
     * Filament memanggil `getEloquentQuery()` beberapa kali dalam satu request
     * (tabel, lencana, hitungan). Tanpa penjaga, satu kali buka layar
     * meninggalkan tiga sampai lima baris kembar dan jejaknya jadi tidak kebaca.
     */
    public function test_tidak_menumpuk_baris_kembar(): void
    {
        Livewire::actingAs($this->superAdmin())->test(ListEquipment::class);

        $this->assertSame(
            1,
            AuditLog::where('entity_type', 'akses_lintas_organisasi')->count(),
            'Jejak akses menumpuk untuk satu layar yang sama.',
        );
    }

    /** Satu lab saja: tidak ada yang diseberangi, jadi tidak ada yang dicatat. */
    public function test_lab_tunggal_tidak_meninggalkan_jejak(): void
    {
        $this->alatLabLain->forceDelete();
        $this->labLain->delete();

        Livewire::actingAs($this->superAdmin())->test(ListEquipment::class);

        $this->assertSame(
            0,
            AuditLog::where('entity_type', 'akses_lintas_organisasi')->count(),
            'Jejak lintas organisasi ditulis padahal labnya cuma satu.',
        );
    }
}
