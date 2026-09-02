<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Support\Angka;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `resolusi = 0` ditolak di batas masuk, bukan diam-diam jadi 4 desimal.
 *
 * ## Kenapa berkas ini ada
 *
 * `EquipmentRequest` punya DUA aturan resolusi yang terpaut 22 baris, dan cuma
 * satu yang benar:
 *
 *     'resolusi'                    => ['nullable', 'numeric', 'min:0'];   // bocor
 *     'resolusi_rentang.*.resolusi' => ['required',  'numeric', 'gt:0'];   // benar
 *
 * Yang kedua bahkan membawa komentar yang menjelaskan bug di yang pertama:
 * *"`min:0` nggak cukup: resolusi 0 bikin `desimalDariResolusi()` jatuh ke
 * default 4 desimal diam-diam, dan sertifikatnya ngaku presisi yang alatnya
 * nggak punya."*
 *
 * `min:0` mengizinkan angka nol, dan `Angka::desimalDariResolusi()` menyamakan
 * 0 dengan null — dua-duanya jatuh ke `DESIMAL_DEFAULT = 4`. Untuk profil yang
 * tidak meng-override `desimalSertifikat()`, kolom Standard Value / UUT /
 * Correction / U95% tercetak empat desimal. Untuk alat beresolusi 0,1 itu
 * persis "presisi yang alatnya nggak punya" — di dokumen terakreditasi, dan
 * tanpa satu pun error di sepanjang jalurnya.
 *
 * Nol bukan "belum diisi". Yang belum diisi itu `null`, dan `null` tetap
 * diterima — lihat test di bawah.
 */
class ResolusiNolDitolakTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->pelanggan = Customer::factory()->create();
        EquipmentCategory::factory()->create(['kode' => 'panjang', 'nama' => 'Panjang']);
    }

    /**
     * @param  array<string, mixed>  $tambahan
     * @return array<string, mixed>
     */
    private function payload(array $tambahan = []): array
    {
        return [
            'nama_alat' => 'Jangka Sorong',
            'serial_number' => 'SN-'.fake()->unique()->numerify('#####'),
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
            ...$tambahan,
        ];
    }

    public function test_bikin_alat_dengan_resolusi_nol_ditolak_422(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/equipments', $this->payload(['resolusi' => 0]))
            ->assertStatus(422)
            ->assertJsonPath('errors.resolusi.0', 'Resolusi harus lebih besar dari nol.');
    }

    /**
     * Jalur ubah juga, bukan cuma jalur bikin.
     *
     * Alat biasanya lahir tanpa resolusi lalu diisi belakangan lewat panel —
     * jadi kalau cuma `store` yang dijaga, angka nol tetap punya pintu masuk.
     */
    public function test_ubah_alat_jadi_resolusi_nol_ditolak_422(): void
    {
        $alat = Equipment::factory()->create([
            'customer_id' => $this->pelanggan->id,
            'resolusi' => 0.01,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/equipments/{$alat->id}", $this->payload([
                'serial_number' => $alat->serial_number,
                'resolusi' => 0,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.resolusi.0', 'Resolusi harus lebih besar dari nol.');

        $this->assertSame(0.01, (float) $alat->fresh()->resolusi, 'Nilai lamanya ikut tertimpa padahal request-nya ditolak.');
    }

    /**
     * JANGAN kebablasan: `null` itu "belum diisi", dan itu sah.
     *
     * Alat yang resolusinya memang tidak diketahui harus tetap bisa didaftarkan;
     * yang dilarang cuma mengaku punya resolusi nol.
     */
    public function test_resolusi_null_tetap_diterima(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/equipments', $this->payload(['resolusi' => null]))
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson('/api/equipments', $this->payload())
            ->assertCreated();
    }

    public function test_resolusi_wajar_tetap_diterima(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/equipments', $this->payload(['resolusi' => 0.1]))
            ->assertCreated()
            ->assertJsonPath('data.resolusi', 0.1);
    }

    /**
     * Saudaranya yang sudah benar tetap benar — penjaga regresi, bukan
     * pengulangan.
     */
    public function test_resolusi_rentang_nol_tetap_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/equipments', $this->payload([
                'resolusi_rentang' => [['maks' => 100, 'resolusi' => 0]],
            ]))
            ->assertStatus(422)
            // `assertJsonValidationErrors`, bukan `assertJsonPath`: kunci error
            // buat field bersarang itu SATU string berdot
            // (`resolusi_rentang.0.resolusi`), bukan jalur bersarang yang bisa
            // ditelusuri titik demi titik.
            ->assertJsonValidationErrors([
                'resolusi_rentang.0.resolusi' => 'Resolusi harus lebih besar dari nol.',
            ]);
    }

    /**
     * ALASAN aturannya, dikunci di test supaya tidak bisa dilonggarkan balik
     * tanpa sadar.
     *
     * Kalau suatu hari ada yang menyederhanakan `gt:0` kembali jadi `min:0`,
     * test-test di atas yang merah. Test ini yang menjelaskan KENAPA: nol dan
     * null diperlakukan sama oleh pemformat angka, dan hasilnya empat desimal
     * di dokumen terakreditasi.
     */
    public function test_nol_dan_null_sama_sama_jatuh_ke_empat_desimal(): void
    {
        $this->assertSame(Angka::DESIMAL_DEFAULT, Angka::desimalDariResolusi(0.0));
        $this->assertSame(Angka::DESIMAL_DEFAULT, Angka::desimalDariResolusi(null));
        $this->assertSame(4, Angka::DESIMAL_DEFAULT);

        // Bandingannya: resolusi yang benar memang menurunkan desimalnya.
        $this->assertSame(1, Angka::desimalDariResolusi(0.1));
        $this->assertSame(2, Angka::desimalDariResolusi(0.01));
    }
}
