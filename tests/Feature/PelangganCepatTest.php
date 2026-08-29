<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `POST /api/customers/cepat` — teknisi mendaftarkan PT baru dari lapangan.
 *
 * Alasannya sama persis dengan nama alat baru (K3/K4): `pelanggan_id` itu WAJIB
 * di `POST /equipments`, jadi pelanggan yang belum kedaftar bikin kerjaan
 * teknisi berhenti total sampai ada admin yang buka laptop.
 *
 * Yang dijaga berkas ini bukan "barisnya kesimpen", tapi **harga yang dibayar
 * buat itu**: kembar. Folder arsip, sertifikat, dan daftar alat semuanya nempel
 * ke baris pelanggan, jadi satu perusahaan yang kedaftar dua kali punya riwayat
 * kalibrasi yang terbelah — dan yang kelihatan di layar cuma separuhnya.
 */
class PelangganCepatTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/customers/cepat';

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

    /** Inti endpoint-nya: sebelum ini, teknisi mentok di sini. */
    public function test_teknisi_boleh_mendaftarkan_pt_baru(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson(self::URL, [
                'nama' => 'PT Sinar Rejeki',
                'alamat' => 'Kawasan Industri MM2100 Blok C-3',
            ])
            ->assertCreated()
            ->assertJsonPath('data.nama', 'PT Sinar Rejeki')
            ->assertJsonPath('data.alamat', 'Kawasan Industri MM2100 Blok C-3');

        $this->assertDatabaseHas('customers', [
            'nama' => 'PT Sinar Rejeki',
            'sumber' => Customer::SUMBER_TEKNISI,
            'dibuat_oleh_user_id' => $this->teknisi->id,
            'organization_id' => $this->teknisi->organization_id,
        ]);
    }

    public function test_viewer_ditolak(): void
    {
        $this->actingAs($this->viewer)
            ->postJson(self::URL, ['nama' => 'PT Sinar Rejeki'])
            ->assertForbidden();

        $this->assertDatabaseCount('customers', 0);
    }

    /**
     * `sumber` nggak boleh datang dari payload.
     *
     * Kalau boleh, satu `{"sumber":"admin"}` dari HP cukup buat bikin baris
     * hasil ketikan lapangan menyamar jadi baris yang sudah diperiksa admin —
     * dan sesudah itu nggak ada satu pun penjagaan di sistem ini yang bisa
     * ngebedain.
     */
    public function test_sumber_dari_payload_diabaikan(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson(self::URL, [
                'nama' => 'PT Sinar Rejeki',
                'sumber' => Customer::SUMBER_ADMIN,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('customers', [
            'nama' => 'PT Sinar Rejeki',
            'sumber' => Customer::SUMBER_TEKNISI,
        ]);
    }

    /**
     * Nitipin pelanggan ke organisasi lain lewat body request nggak bisa.
     */
    public function test_organization_id_dari_payload_diabaikan(): void
    {
        $labSebelah = Organization::factory()->create();

        $this->actingAs($this->teknisi)
            ->postJson(self::URL, [
                'nama' => 'PT Sinar Rejeki',
                'organization_id' => $labSebelah->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('customers', [
            'nama' => 'PT Sinar Rejeki',
            'organization_id' => $this->teknisi->organization_id,
        ]);
    }

    /**
     * Inti penjagaannya: yang MIRIP ditunjukkan dulu, sebelum barisnya lahir.
     *
     * `PT. Maju Jaya` lawan `PT Maju Jaya` lolos unique index yang jalan di
     * teks mentah — di situlah kembar lahir, dan lahirnya justru waktu teknisi
     * yakin pelanggannya belum ada.
     */
    public function test_nama_mirip_ditolak_dan_kandidatnya_ditunjukkan(): void
    {
        $sudahAda = Customer::factory()->create(['nama' => 'PT. Maju Jaya']);

        $this->actingAs($this->teknisi)
            ->postJson(self::URL, ['nama' => 'PT Maju Jaya'])
            ->assertStatus(409)
            ->assertJsonPath('nama_persis_sudah_ada', false)
            ->assertJsonPath('kandidat.0.id', $sudahAda->id)
            ->assertJsonPath('kandidat.0.nama', 'PT. Maju Jaya');

        $this->assertDatabaseCount('customers', 1);
    }

    /**
     * Tapi kemiripan BUKAN vonis. Dua PT yang beneran beda boleh punya nama
     * yang mirip, dan teknisi di lapangan nggak boleh mentok tanpa jalan keluar
     * — itu justru keadaan yang bikin dia mengarang nama pembeda.
     */
    public function test_tetap_buat_menembus_kemiripan(): void
    {
        Customer::factory()->create(['nama' => 'PT. Maju Jaya']);

        $this->actingAs($this->teknisi)
            ->postJson(self::URL, ['nama' => 'PT Maju Jaya', 'tetap_buat' => true])
            ->assertCreated();

        $this->assertDatabaseCount('customers', 2);
    }

    /**
     * Nama yang PERSIS sama tetap buntu, dan `tetap_buat` nggak menembusnya:
     * yang menahan di situ unique index di database, dan menembusnya cuma
     * menghasilkan 500 dari driver — bukan pelanggan baru.
     */
    public function test_tetap_buat_tidak_menembus_nama_yang_persis_sama(): void
    {
        Customer::factory()->create(['nama' => 'PT Maju Jaya']);

        $this->actingAs($this->teknisi)
            ->postJson(self::URL, ['nama' => 'PT Maju Jaya', 'tetap_buat' => true])
            ->assertStatus(409)
            ->assertJsonPath('nama_persis_sudah_ada', true);

        $this->assertDatabaseCount('customers', 1);
    }

    /**
     * Kemiripan dicari CUMA di organisasi pemanggil.
     *
     * Kalau nggak, kandidat yang dipulangkan membocorkan nama pelanggan lab
     * sebelah ke layar teknisi — lewat endpoint yang justru dipasang buat
     * merapikan data.
     */
    public function test_kandidat_tidak_bocor_dari_organisasi_lain(): void
    {
        $labSebelah = Organization::factory()->create();
        Customer::factory()->create([
            'nama' => 'PT Maju Jaya',
            'organization_id' => $labSebelah->id,
        ]);

        $this->actingAs($this->teknisi)
            ->postJson(self::URL, ['nama' => 'PT Maju Jaya'])
            ->assertCreated();

        $this->assertDatabaseHas('customers', [
            'nama' => 'PT Maju Jaya',
            'organization_id' => $this->teknisi->organization_id,
        ]);
    }

    /**
     * Baris yang dipilih dari direktori ditandai asalnya, dan `direktori_ref`
     * ikut tersimpan — itu yang bikin perusahaan yang sama bisa dikenali PERSIS
     * waktu teknisi lain memilihnya lagi, tanpa mengadu ejaan nama.
     */
    public function test_pilihan_dari_direktori_ditandai_dan_refnya_disimpan(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson(self::URL, [
                'nama' => 'PT Sinar Rejeki',
                'alamat' => 'Kawasan Industri MM2100 Blok C-3',
                'direktori_ref' => 'tempat-abc123',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('customers', [
            'nama' => 'PT Sinar Rejeki',
            'sumber' => Customer::SUMBER_DIREKTORI,
            'direktori_ref' => 'tempat-abc123',
        ]);
    }

    /**
     * Perusahaan yang sama dipilih dua kali dari direktori kena tahan lewat
     * `direktori_ref`, WALAUPUN direktorinya menulis namanya beda.
     */
    public function test_ref_direktori_yang_sama_dikenali_walau_namanya_beda(): void
    {
        Customer::factory()->create(['nama' => 'PT Sinar Rejeki'])
            ->forceFill(['direktori_ref' => 'tempat-abc123'])->save();

        $this->actingAs($this->teknisi)
            ->postJson(self::URL, [
                'nama' => 'Sinar Rejeki (Pabrik Cikarang)',
                'direktori_ref' => 'tempat-abc123',
            ])
            ->assertStatus(409)
            ->assertJsonPath('kandidat.0.nama', 'PT Sinar Rejeki');

        $this->assertDatabaseCount('customers', 1);
    }

    /**
     * Kontak sengaja nggak diterima di jalur cepat. Teknisi yang lagi berdiri di
     * gerbang pabrik nggak punya data itu, dan kolom yang ada di form pasti ada
     * yang mengisinya dengan tebakan.
     */
    public function test_kontak_tidak_bisa_diisi_lewat_jalur_cepat(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson(self::URL, [
                'nama' => 'PT Sinar Rejeki',
                'email' => 'tebakan@contoh.test',
                'telepon' => '0800000000',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('customers', [
            'nama' => 'PT Sinar Rejeki',
            'email' => null,
            'telepon' => null,
        ]);
    }

    public function test_nama_wajib_diisi(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson(self::URL, ['alamat' => 'Jl. Tanpa Nama'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nama');
    }

    /** Admin yang lewat jalur ini tetap ditandai admin, bukan teknisi. */
    public function test_admin_lewat_jalur_cepat_ditandai_admin(): void
    {
        $this->actingAs($this->admin)
            ->postJson(self::URL, ['nama' => 'PT Sinar Rejeki'])
            ->assertCreated();

        $this->assertDatabaseHas('customers', [
            'nama' => 'PT Sinar Rejeki',
            'sumber' => Customer::SUMBER_ADMIN,
        ]);
    }
}
