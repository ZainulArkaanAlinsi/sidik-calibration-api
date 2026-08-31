<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Direktori\DirektoriPerusahaan;
use App\Services\Direktori\NominatimDirektori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Direktori GRATIS: OpenStreetMap lewat Nominatim.
 *
 * Dipilih pemilik proyek supaya nol tagihan dan nol API key. Yang dijaga berkas
 * ini bukan cuma "hasilnya kebaca", tapi **kewajiban kita ke penyedianya** —
 * Nominatim layanan sukarela, dan melanggarnya bikin alamat IP server lab
 * diblokir, bukan diperingatkan dulu.
 *
 * CATATAN: bentuk jawaban Nominatim di sini ditulis dari dokumentasinya, BUKAN
 * dari respons asli — jaringan lingkungan pengembangan ini nggak bisa menembus
 * ke sana. Parsernya sengaja toleran (field yang nggak ada dilewat, bukan bikin
 * seluruh pencarian gagal), dan satu uji nyata di server tetap perlu.
 */
class DirektoriOsmTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/customers/direktori';

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();

        config()->set('services.direktori_perusahaan.driver', 'osm');
    }

    /** @param  array<int, array<string, mixed>>  $tempat */
    private function jawaban(array $tempat): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($tempat)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tempat(
        string $tipe = 'way',
        int $id = 12345,
        ?string $nama = 'PT Sinar Rejeki',
        string $alamat = 'PT Sinar Rejeki, Jalan Industri, Cikarang, Bekasi, Jawa Barat, Indonesia',
    ): array {
        return [
            'osm_type' => $tipe,
            'osm_id' => $id,
            'place_id' => 999,
            ...($nama === null ? [] : ['name' => $nama]),
            'display_name' => $alamat,
        ];
    }

    /** Driver bawaannya OSM — nggak perlu disetel apa-apa buat hidup. */
    public function test_bawaannya_osm_dan_selalu_siap(): void
    {
        config()->offsetUnset('services.direktori_perusahaan.driver');

        $this->assertInstanceOf(
            NominatimDirektori::class,
            app(DirektoriPerusahaan::class),
        );
        $this->assertTrue(app(DirektoriPerusahaan::class)->tersedia());
    }

    /**
     * Tanpa key, keadaan "belum disetel" berhenti ada — dan itu keadaan yang
     * paling sering bikin layar teknisi kelihatan rusak.
     */
    public function test_tidak_pernah_503_walau_tidak_ada_key(): void
    {
        config()->set('services.direktori_perusahaan.key', null);
        $this->jawaban([$this->tempat()]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar Rejeki')
            ->assertOk();
    }

    public function test_hasilnya_jadi_nama_alamat_dan_ref(): void
    {
        $this->jawaban([$this->tempat()]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar Rejeki')
            ->assertOk()
            ->assertJsonPath('data.0.nama', 'PT Sinar Rejeki')
            ->assertJsonPath('data.0.ref', 'osm:way/12345')
            ->assertJsonPath(
                'data.0.alamat',
                'Jalan Industri, Cikarang, Bekasi, Jawa Barat, Indonesia',
            );
    }

    /**
     * `place_id` itu nomor internal Nominatim dan BERUBAH tiap mereka impor
     * ulang. Dipakai sebagai `direktori_ref`, penjaga kembar berhenti
     * mencocokkan perusahaan yang sama beberapa bulan kemudian — kegagalan
     * diam, persis yang bikin kolom itu ada.
     */
    public function test_ref_dari_osm_id_bukan_place_id(): void
    {
        $this->jawaban([$this->tempat()]);

        $ref = $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar')
            ->json('data.0.ref');

        $this->assertSame('osm:way/12345', $ref);
        $this->assertStringNotContainsString('999', (string) $ref);
    }

    /** Nama diambil dari `display_name` kalau `name` nggak ada. */
    public function test_nama_jatuh_ke_potongan_pertama_display_name(): void
    {
        $this->jawaban([$this->tempat(nama: null)]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar')
            ->assertOk()
            ->assertJsonPath('data.0.nama', 'PT Sinar Rejeki');
    }

    public function test_baris_tanpa_penanda_tetap_dilewat(): void
    {
        $this->jawaban([
            ['display_name' => 'PT Tanpa Osm Id, Bekasi'],
            $this->tempat(),
        ]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'PT Sinar Rejeki');
    }

    /**
     * WAJIB: Nominatim menolak klien anonim, dan `countrycodes` di sini
     * penyaring SUNGGUHAN — beda dari `regionCode` penyedia komersial yang cuma
     * mencondongkan.
     */
    public function test_permintaan_menyebut_dirinya_dan_dikunci_ke_indonesia(): void
    {
        $this->jawaban([]);

        $this->actingAs($this->teknisi)->getJson(self::URL.'?search=Sinar')->assertOk();

        Http::assertSent(function ($request) {
            $ua = $request->header('User-Agent')[0] ?? '';

            return str_contains($request->url(), 'countrycodes=id')
                && str_contains($request->url(), 'format=jsonv2')
                && $ua !== ''
                && ! str_starts_with($ua, 'GuzzleHttp');
        });
    }

    /**
     * Atribusi ODbL ikut di badan respons, bukan dikarang klien: kewajibannya
     * melekat ke penyedianya, dan penyedianya bisa ditukar lewat satu setelan.
     */
    public function test_atribusi_openstreetmap_ikut_dikirim(): void
    {
        $this->jawaban([$this->tempat()]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar')
            ->assertOk()
            ->assertJsonPath('atribusi', NominatimDirektori::ATRIBUSI);
    }

    /** Ditukar ke Google, atribusinya ikut berubah — bukan tertinggal. */
    public function test_atribusi_ikut_berganti_kalau_penyedianya_ditukar(): void
    {
        config()->set('services.direktori_perusahaan.driver', 'google');
        config()->set('services.direktori_perusahaan.key', 'kunci-uji');
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => []])]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar')
            ->assertOk()
            ->assertJsonPath('atribusi', 'Powered by Google');
    }

    /**
     * Salah ketik nama driver di `.env` jatuh ke OSM, BUKAN melempar:
     * mematikan pendaftaran pelanggan di lapangan itu hukuman yang jauh lebih
     * besar daripada kesalahannya.
     */
    public function test_driver_yang_tidak_dikenali_jatuh_ke_osm(): void
    {
        config()->set('services.direktori_perusahaan.driver', 'salah-ketik');

        $this->assertInstanceOf(
            NominatimDirektori::class,
            app(DirektoriPerusahaan::class),
        );
    }
}
