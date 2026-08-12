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
            ->assertSchemaStateSet(['nama' => 'Nama Lama'])
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

    /**
     * Nyimpan dari panel nggak boleh ngehapus setelan yang NGGAK ada di form.
     *
     * `settings` itu satu kolom JSON yang dipakai bareng-bareng, tapi form ini cuma
     * nampilin sebagian kuncinya. Tiga kunci nggak punya field di sini:
     * `reminder_hari_sebelum`, `catatan_ketidakpastian`, `catatan_penggandaan`.
     *
     * Kalau `save()` nimpa seluruh array, ketiganya ilang tiap admin nyimpan halaman
     * ini — dan ilangnya SUNYI: nggak ada error, pengingat jatuh tempo diam-diam
     * balik ke default 30 hari dan catatan sertifikat ilang dari PDF. Ketahuannya
     * baru pas ada yang nyariin.
     */
    public function test_simpan_nggak_ngehapus_setelan_yang_nggak_ada_di_form(): void
    {
        $org = Organization::factory()->create([
            'nama' => 'Nama Lama',
            'settings' => [
                'penandatangan_nama' => 'Alex Misramto',
                // Tiga ini nggak punya field di form.
                Organization::KEY_AMBANG_HARI => 7,
                'catatan_ketidakpastian' => 'Catatan U khusus lab ini.',
                'catatan_penggandaan' => 'Dilarang digandakan sebagian.',
            ],
        ]);
        $admin = User::factory()->admin()->create(['organization_id' => $org->id]);

        Livewire::actingAs($admin)
            ->test(PengaturanOrganisasi::class)
            ->fillForm(['nama' => 'Nama Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = $org->fresh()->settings;

        $this->assertSame(7, $settings[Organization::KEY_AMBANG_HARI] ?? null);
        $this->assertSame('Catatan U khusus lab ini.', $settings['catatan_ketidakpastian'] ?? null);
        $this->assertSame('Dilarang digandakan sebagian.', $settings['catatan_penggandaan'] ?? null);
        // Yang emang ada di form tetap kesimpen.
        $this->assertSame('Alex Misramto', $settings['penandatangan_nama'] ?? null);
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
