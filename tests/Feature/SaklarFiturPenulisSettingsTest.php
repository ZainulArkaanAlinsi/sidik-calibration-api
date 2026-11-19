<?php

namespace Tests\Feature;

use App\Filament\Pages\PengaturanOrganisasi;
use App\Models\Organization;
use App\Models\User;
use App\Support\FiturSaklar;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Penulis LAIN kolom `organizations.settings` tidak boleh menimpa saklar fitur
 * (`fitur_nonaktif`) yang dipasang Super Admin (paket OPS-KODE butir 3).
 *
 * ## Kenapa ada
 *
 * `settings` satu JSON yang dipakai bareng. Tiga jalur lain menulisnya tanpa
 * tahu soal saklar, dan tiga-tiganya membaca `settings` dari salinan yang bisa
 * basi lalu menulis SELURUH JSON:
 *
 *  - `PATCH /organization/tanda-tangan/posisi` — membaca relasi `organization`
 *    milik pengguna (bisa sudah dimuat lebih awal) lalu `update()`;
 *  - panel Filament `PengaturanOrganisasi::save()` — menggabung ke `$this->record`
 *    yang dimuat saat halaman DIBUKA (bisa menit sebelumnya);
 *  - `OrganizationSeeder` — menulis array tetap utuh.
 *
 * Kalau saklar tertimpa, fitur yang dimatikan MENYALA LAGI diam-diam sementara
 * audit tetap berkata "dimatikan". Tanpa error di mana pun.
 */
class SaklarFiturPenulisSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisasi = Organization::factory()->create([
            'settings' => ['penandatangan_nama' => 'Alex Misramto'],
        ]);
    }

    /** Pasang saklar lewat salinan LAIN model (seperti `PUT /organization/fitur` di request lain). */
    private function pasangSaklarDiRequestLain(): void
    {
        $lain = Organization::findOrFail($this->organisasi->id);
        $lain->update(['settings' => array_merge($lain->settings ?? [], [
            FiturSaklar::KUNCI_SETTINGS => [FiturSaklar::PINDAI_LEMBAR],
        ])]);
    }

    public function test_posisi_tanda_tangan_dengan_relasi_basi_tidak_menimpa_saklar(): void
    {
        $admin = User::factory()->admin()->create(['organization_id' => $this->organisasi->id]);

        // Relasi dimuat SEBELUM saklar dipasang: salinan basi yang dipegang request.
        $this->assertNull($admin->organization->settings[FiturSaklar::KUNCI_SETTINGS] ?? null);

        $this->pasangSaklarDiRequestLain();

        // Instans yang SAMA (bukan `fresh()`): `actingAs` membawa relasi basinya.
        $this->actingAs($admin)
            ->patchJson('/api/organization/tanda-tangan/posisi', ['geser_x_mm' => 3.5])
            ->assertOk();

        $settings = $this->organisasi->fresh()->settings;

        $this->assertSame([FiturSaklar::PINDAI_LEMBAR], $settings[FiturSaklar::KUNCI_SETTINGS] ?? null, 'Saklar tertimpa.');
        $this->assertSame(3.5, (float) $settings[Organization::KEY_TTD_GESER_X], 'Geseran tidak tersimpan.');
        $this->assertSame('Alex Misramto', $settings['penandatangan_nama']);
    }

    public function test_panel_simpan_dengan_halaman_basi_tidak_menimpa_saklar(): void
    {
        $admin = User::factory()->admin()->create(['organization_id' => $this->organisasi->id]);

        $halaman = Livewire::actingAs($admin)->test(PengaturanOrganisasi::class);

        // Halaman SUDAH terbuka (record dimuat) ketika saklar dipasang.
        $this->pasangSaklarDiRequestLain();

        $halaman->fillForm(['nama' => 'Nama Baru Lewat Panel'])
            ->call('save')
            ->assertHasNoFormErrors();

        $organisasi = $this->organisasi->fresh();

        $this->assertSame('Nama Baru Lewat Panel', $organisasi->nama);
        $this->assertSame(
            [FiturSaklar::PINDAI_LEMBAR],
            $organisasi->settings[FiturSaklar::KUNCI_SETTINGS] ?? null,
            'Saklar tertimpa oleh simpan dari panel.',
        );
    }

    /** `db:seed` menulis array tetap; saklar yang sudah dipasang harus selamat. */
    public function test_seeder_organisasi_tidak_menimpa_saklar(): void
    {
        $this->seed(OrganizationSeeder::class);

        $org = Organization::findOrFail(1);
        $org->update(['settings' => array_merge($org->settings ?? [], [
            FiturSaklar::KUNCI_SETTINGS => [FiturSaklar::KIRIM_EMAIL_SERTIFIKAT],
        ])]);

        $this->seed(OrganizationSeeder::class);

        $this->assertSame(
            [FiturSaklar::KIRIM_EMAIL_SERTIFIKAT],
            Organization::findOrFail(1)->settings[FiturSaklar::KUNCI_SETTINGS] ?? null,
            'db:seed menyalakan kembali fitur yang dimatikan.',
        );
    }

    /** Tanpa saklar terpasang, seeder tidak menambahkan kunci itu. */
    public function test_seeder_tanpa_saklar_tidak_menambah_kunci(): void
    {
        $this->seed(OrganizationSeeder::class);
        $this->seed(OrganizationSeeder::class);

        $this->assertArrayNotHasKey(FiturSaklar::KUNCI_SETTINGS, Organization::findOrFail(1)->settings ?? []);
    }
}
