<?php

namespace Tests\Feature;

use App\Filament\Pages\PengaturanOrganisasi;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Halaman singleton "Pengaturan Organisasi" di panel admin. Beda dari resource
 * biasa (nggak ada list/create), jadi jalur simpannya diuji langsung lewat
 * komponen Livewire-nya, bukan cuma render HTTP.
 */
class PengaturanOrganisasiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bisa_simpan_perubahan_data_organisasi(): void
    {
        $org = Organization::factory()->create(['nama' => 'Nama Lama']);
        $admin = User::factory()->admin()->create(['organization_id' => $org->id]);

        Livewire::actingAs($admin)
            ->test(PengaturanOrganisasi::class)
            // Form keisi dari record pas mount — pastiin nama lama kebaca dulu.
            ->assertFormSet(['nama' => 'Nama Lama'])
            ->fillForm([
                'nama' => 'PT Sidik Kalibrasi Baru',
                'no_akreditasi' => 'LK-999-IDN',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('organizations', [
            'id' => $org->id,
            'nama' => 'PT Sidik Kalibrasi Baru',
            'no_akreditasi' => 'LK-999-IDN',
        ]);
    }

    public function test_nama_organisasi_wajib_diisi(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->admin()->create(['organization_id' => $org->id]);

        Livewire::actingAs($admin)
            ->test(PengaturanOrganisasi::class)
            ->fillForm(['nama' => ''])
            ->call('save')
            ->assertHasFormErrors(['nama' => 'required']);
    }
}
