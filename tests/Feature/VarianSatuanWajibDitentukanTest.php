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
 * Teknisi nggak boleh disodorin dua varian satuan buat titik yang sama.
 *
 * Conductivity Meter punya titik tengah dalam DUA bentuk — `1412 µS/cm` dan
 * `1,412 mS/cm` — dan cuma satu yang benar per alat pelanggan. Yang nentuin itu
 * `resolusi_rentang` di master alat, bukan teknisi di lapangan: varian satuan
 * nentuin style sertifikat, dan salah pilih bikin nilai acuannya meleset 1000×.
 *
 * Sesi 53 (12 Agt 2026) kejeblos persis di situ — 1413 mendarat di baris
 * `1,412 mS/cm`, Correction keluar -1411,588, dan sesinya sempat disetujui.
 *
 * Waktu alat lab masih sedikit, orang hafal mana yang benar. Begitu alatnya
 * banyak, nggak ada yang tau — dan lembar yang ambigu itu diam, nggak error.
 */
class VarianSatuanWajibDitentukanTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private EquipmentCategory $kategori;

    private Customer $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        $this->teknisi = User::factory()->create();
        $this->pelanggan = Customer::factory()->create();
        $this->kategori = EquipmentCategory::factory()->create([
            'kode' => 'konduktivitas',
            'nama' => 'Konduktivitas',
        ]);
    }

    /** @param  list<array<string, mixed>>|null  $resolusi */
    private function alat(?array $resolusi): Equipment
    {
        return Equipment::factory()->create([
            'nama_alat' => 'Conductivitymeter',
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => $this->kategori->id,
            'satuan' => 'mS/cm',
            'toleransi' => null,
            'resolusi_rentang' => $resolusi,
        ]);
    }

    /** @return list<float> */
    private function titikDari(array $bentuk): array
    {
        $titik = [];

        foreach ($bentuk['bagian'] ?? [] as $bagian) {
            foreach ($bagian['tabel'] ?? [] as $tabel) {
                foreach ($tabel['baris'] ?? [] as $baris) {
                    $titik[(string) (float) $baris['titik_ukur']] = (float) $baris['titik_ukur'];
                }
            }
        }

        return array_values($titik);
    }

    public function test_tanpa_equipment_id_bentuk_generik_tetap_boleh_keluar(): void
    {
        // Layar kebuka sebelum teknisi milih alat — belum ada yang bisa
        // ditentuin, dan lembarnya emang cuma buat digambar.
        $bentuk = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?instrumen=Conductivitymeter')
            ->assertOk()
            ->json('data');

        $this->assertContains(1412.0, $this->titikDari($bentuk));
        $this->assertContains(1.412, $this->titikDari($bentuk));
    }

    public function test_alat_yang_resolusinya_kosong_ditolak_bukan_dikasih_dua_varian(): void
    {
        $alat = $this->alat(null);

        $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$alat->id)
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                fn (string $pesan): bool => str_contains($pesan, 'Resolusi per titik'),
            );
    }

    /**
     * Salah daftar juga ketahan, bukan diam-diam balik ambigu.
     *
     * `titik => 1.412` kelihatan masuk akal buat orang yang ngisinya, tapi
     * `bandResolusi()` nyocokin RELATIF ke nominal — 1,412 lawan 1412 beda tiga
     * orde, jadi baris µS/cm-nya nggak kena band mana pun dan tetap ikut
     * kegambar. Hasilnya dua varian lagi.
     */
    public function test_resolusi_yang_didaftarin_pakai_nilai_mili_juga_ditolak(): void
    {
        $alat = $this->alat([
            ['titik' => 25, 'satuan' => 'µS/cm', 'resolusi' => 0.1],
            ['titik' => 1.412, 'satuan' => 'mS/cm', 'resolusi' => 0.001],
            ['titik' => 111, 'satuan' => 'mS/cm', 'resolusi' => 0.01],
        ]);

        $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$alat->id)
            ->assertStatus(422);
    }

    public function test_alat_yang_resolusinya_lengkap_dapat_satu_varian_saja(): void
    {
        $alat = $this->alat([
            ['titik' => 25, 'satuan' => 'µS/cm', 'resolusi' => 0.1],
            ['titik' => 1412, 'satuan' => 'µS/cm', 'resolusi' => 1],
            ['titik' => 111, 'satuan' => 'mS/cm', 'resolusi' => 0.01],
        ]);

        $bentuk = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$alat->id)
            ->assertOk()
            ->json('data');

        $titik = $this->titikDari($bentuk);

        $this->assertContains(1412.0, $titik);
        $this->assertNotContains(1.412, $titik);
    }

    /**
     * `titik` tetap NOMINAL kanoniknya (1412), `satuan` yang milih varian.
     *
     * Ngikut kertasnya: kolom di master tetap berjudul "Std 1412 µS/cm", dan
     * yang dipindah cuma sel resolusinya — µS/cm atau mS/cm. `titik` itu
     * identitas titiknya, bukan angka yang bakal dibaca.
     */
    public function test_varian_mili_kepilih_lewat_satuan_bukan_lewat_nilai_titik(): void
    {
        $alat = $this->alat([
            ['titik' => 25, 'satuan' => 'µS/cm', 'resolusi' => 0.1],
            ['titik' => 1412, 'satuan' => 'mS/cm', 'resolusi' => 0.001],
            ['titik' => 111, 'satuan' => 'mS/cm', 'resolusi' => 0.01],
        ]);

        $bentuk = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$alat->id)
            ->assertOk()
            ->json('data');

        $titik = $this->titikDari($bentuk);

        $this->assertContains(1.412, $titik);
        $this->assertNotContains(1412.0, $titik);
    }
}
