<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/customers/lookup` — dropdown pelanggan buat SEMUA role.
 *
 * Kenapa ada endpoint sendiri padahal `/customers` udah ada: `POST /equipments`
 * boleh dipakai teknisi dan `pelanggan_id` itu **wajib**, tapi seluruh
 * `/customers` admin-only. Efeknya form Tambah Alat mulus waktu dites pakai akun
 * admin lalu mentok total di akun teknisi — dropdown-nya `403`, alatnya nggak
 * bisa disimpen sama sekali.
 *
 * `kontrak-api.md` §8 sempat nyaranin pakai `GET /arsip/perusahaan` sebagai
 * gantinya. Itu nggak nyelesaiin masalahnya: endpoint itu ngelist FOLDER, dan
 * folder cuma ada buat PT yang udah pernah punya sertifikat — jadi pelanggan
 * BARU, yang justru paling sering diinput, nggak akan nongol.
 */
class CustomerLookupTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/customers/lookup';

    private User $admin;

    private User $teknisi;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        $this->viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
    }

    /** Ini inti endpoint-nya: teknisi harus bisa, dan sebelumnya nggak bisa. */
    public function test_teknisi_boleh_ambil_daftar_pelanggan(): void
    {
        Customer::factory()->create(['nama' => 'PT Tirta Gracia', 'alamat' => 'Jl. Arteri Primer A-10']);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.nama', 'PT Tirta Gracia')
            ->assertJsonPath('data.0.alamat', 'Jl. Arteri Primer A-10');
    }

    /**
     * Pelanggan yang belum punya sertifikat/arsip TETAP nongol.
     *
     * Ini bedanya sama `GET /arsip/perusahaan` yang dulu disaranin: yang itu
     * ngelist folder, dan folder baru ada sesudah sertifikat pertama terbit.
     */
    public function test_pelanggan_baru_tanpa_arsip_tetap_nongol(): void
    {
        Customer::factory()->create(['nama' => 'PT Baru Banget']);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'PT Baru Banget');
    }

    /** `id` yang dikirim harus id PELANGGAN — langsung kepakai jadi `pelanggan_id`. */
    public function test_id_yang_dikirim_kepakai_langsung_buat_simpan_alat(): void
    {
        $pelanggan = Customer::factory()->create(['nama' => 'PT Maju Jaya']);

        $id = $this->actingAs($this->teknisi)
            ->getJson(self::URL)
            ->assertOk()
            ->json('data.0.id');

        $this->assertSame($pelanggan->id, $id);
    }

    public function test_viewer_dan_admin_juga_boleh(): void
    {
        Customer::factory()->create(['nama' => 'PT Contoh']);

        $this->actingAs($this->viewer)->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    /**
     * Cuma id/nama/alamat. Kontak pelanggan SENGAJA nggak ikut: ini dropdown,
     * bukan layar CRUD — role yang nggak boleh ngelola pelanggan nggak perlu
     * megang nomor telepon & emailnya.
     */
    public function test_nggak_bawa_kontak_pelanggan(): void
    {
        Customer::factory()->create([
            'nama' => 'PT Contoh',
            'contact_person' => 'Budi',
            'telepon' => '08123456789',
            'email' => 'budi@ptcontoh.test',
        ]);

        $baris = $this->actingAs($this->teknisi)->getJson(self::URL)->assertOk()->json('data.0');

        $this->assertSame(['id', 'nama', 'alamat'], array_keys($baris));
    }

    public function test_nyaring_pakai_search(): void
    {
        Customer::factory()->create(['nama' => 'PT Tirta Gracia']);
        Customer::factory()->create(['nama' => 'PT Maju Jaya']);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Tirta')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'PT Tirta Gracia');
    }

    /**
     * `q` diterima juga. Dokumen lama nyebut param ini buat lookup pelanggan, dan
     * filter yang diabaikan diam-diam itu bug mahal: daftarnya balik utuh dan
     * kelihatan kayak pencariannya rusak, bukan kayak param-nya salah.
     */
    public function test_nyaring_pakai_q_juga_jalan(): void
    {
        Customer::factory()->create(['nama' => 'PT Tirta Gracia']);
        Customer::factory()->create(['nama' => 'PT Maju Jaya']);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?q=Maju')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'PT Maju Jaya');
    }

    /** Dipaginasi — pencarian dilempar ke server, bukan nyaring halaman pertama. */
    public function test_dipaginasi_15_per_halaman(): void
    {
        Customer::factory()->count(20)->create();

        $this->actingAs($this->teknisi)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 20);
    }

    public function test_pelanggan_organisasi_lain_nggak_keliatan(): void
    {
        Customer::factory()->create(['nama' => 'PT Punya Kita']);

        $lain = Organization::factory()->create();
        Customer::factory()->create(['organization_id' => $lain->id, 'nama' => 'PT Sebelah']);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'PT Punya Kita');
    }

    /** `lookup` jangan kebaca sebagai `{customer}` di `GET /customers/{customer}`. */
    public function test_route_lookup_nggak_ketutup_show(): void
    {
        Customer::factory()->create(['nama' => 'PT Contoh']);

        // Kalau route-nya ketuker, teknisi bakal kena 403 (show admin-only)
        // atau 404 (binding "lookup" gagal) — bukan 200 dengan daftar.
        $this->actingAs($this->teknisi)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'nama', 'alamat']], 'meta']);
    }

    /** Yang admin-only tetap admin-only — lookup bukan pintu belakang ke CRUD. */
    public function test_crud_pelanggan_tetap_admin_only(): void
    {
        $pelanggan = Customer::factory()->create();

        $this->actingAs($this->teknisi)->getJson('/api/customers')->assertForbidden();
        $this->actingAs($this->teknisi)->getJson("/api/customers/{$pelanggan->id}")->assertForbidden();
        $this->actingAs($this->teknisi)->postJson('/api/customers', ['nama' => 'PT Nyelip'])->assertForbidden();
    }
}
