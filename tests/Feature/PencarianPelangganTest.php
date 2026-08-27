<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/customers/lookup` — pemilih pelanggan di form Tambah Alat.
 *
 * ## Kenapa berkas ini ada
 *
 * Permintaan pemilik proyek 27 Agt: *"nama pt nya gede terus bawah nya alamat
 * nya kecil terus tinggal di pencet aja"*. Yang bikin ini bukan sekadar
 * pekerjaan tampilan: mobile selama ini narik daftarnya dari
 * `GET /api/arsip/perusahaan`, yang ngelist **FOLDER**, bukan pelanggan.
 *
 * | | Akibatnya |
 * |---|---|
 * | `id` yang datang itu id folder | Folder id 1 bisa milik pelanggan id 3 — alatnya kesimpen ke PT LAIN, `pelanggan_id`-nya sah, nol error |
 * | Folder cuma ada buat PT yang udah pernah punya sertifikat | Pelanggan BARU nggak nongol sama sekali |
 * | Daftarnya disaring per-role | Teknisi biasa sering dapat NOL baris |
 * | `?search=` diabaikan (server baca `q`) | Daftarnya balik utuh tiap ketik |
 *
 * Endpoint `lookup` ini yang benar buat itu, dan yang dikunci di sini bukan
 * cuma "dia jalan" — tapi **bahwa dia beda dari `/arsip/perusahaan` di
 * keempat titik itu.**
 */
class PencarianPelangganTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private Customer $alfa;

    private Customer $beta;

    private Customer $gamma;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['id' => 1]);
        $this->teknisi = User::factory()->create([
            'organization_id' => 1,
            'role' => User::ROLE_TEKNISI,
        ]);

        $this->alfa = Customer::factory()->create([
            'organization_id' => 1,
            'nama' => 'PT Alfa Presisi',
            'alamat' => 'Jl. Raya Cikarang KM 27, Bekasi',
        ]);
        $this->beta = Customer::factory()->create([
            'organization_id' => 1,
            'nama' => 'PT Beta Instrumen',
            'alamat' => 'Kawasan Industri Pulogadung Blok B-4, Jakarta Timur',
        ]);
        $this->gamma = Customer::factory()->create([
            'organization_id' => 1,
            'nama' => 'CV Gamma Kalibrasi',
            'alamat' => 'Jl. Industri Cikarang Selatan No. 88, Bekasi',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function cari(?string $kunci = null): array
    {
        $url = '/api/customers/lookup'.($kunci === null ? '' : '?search='.urlencode($kunci));

        return $this->actingAs($this->teknisi)->getJson($url)->assertOk()->json('data');
    }

    public function test_teknisi_boleh_buka_dan_dapat_semua_pelanggan(): void
    {
        // Beda pertama dari `/arsip/perusahaan`: di situ teknisi biasa disaring
        // ke folder yang ada berkasnya buat dia — sering nol baris, dan karena
        // `pelanggan_id` wajib, alatnya nggak bisa disimpan sama sekali.
        $this->assertCount(3, $this->cari());
    }

    public function test_id_yang_dikirim_itu_id_pelanggan_bukan_id_folder(): void
    {
        // Bug paling berat yang ditutup endpoint ini, dan yang paling sepi:
        // `pelanggan_id` yang salah tetap sah, alatnya kesimpen ke PT lain.
        //
        // Folder sengaja dibikin dengan urutan kebalik, jadi id folder NGGAK
        // sama dengan id pelanggannya.
        foreach ([$this->gamma, $this->beta, $this->alfa] as $c) {
            Folder::query()->create([
                'organization_id' => 1,
                'customer_id' => $c->id,
                'nama' => $c->nama,
                'tipe' => 'sistem',
                'parent_id' => null,
            ]);
        }

        $folderAlfa = Folder::query()->where('customer_id', $this->alfa->id)->firstOrFail();
        $this->assertNotSame(
            $this->alfa->id,
            $folderAlfa->id,
            'Skenarionya nggak kepasang: id folder kebetulan sama dengan id pelanggan.'
        );

        $baris = collect($this->cari('Alfa'))->firstOrFail();

        $this->assertSame($this->alfa->id, $baris['id']);
        $this->assertNotSame($folderAlfa->id, $baris['id']);
    }

    public function test_pelanggan_baru_yang_belum_punya_folder_tetap_nongol(): void
    {
        // Nol folder di seluruh database, dan ketiganya tetap harus ada. Di
        // `/arsip/perusahaan` hasilnya kosong — padahal pelanggan yang baru
        // didaftarkan justru yang paling sering dicari di form Tambah Alat.
        $this->assertSame(0, Folder::query()->count());
        $this->assertCount(3, $this->cari());
    }

    public function test_alamat_ikut_kekirim(): void
    {
        $baris = collect($this->cari('Beta'))->firstOrFail();

        $this->assertSame('Kawasan Industri Pulogadung Blok B-4, Jakarta Timur', $baris['alamat']);
    }

    public function test_kontak_pelanggan_nggak_ikut_bocor(): void
    {
        // Endpoint ini kebuka SEMUA role, termasuk viewer. Nambah satu field
        // buat layar admin nggak boleh diam-diam ngirimnya ke sini juga.
        $baris = collect($this->cari())->firstOrFail();

        $this->assertSame(['id', 'nama', 'alamat'], array_keys($baris));
    }

    public function test_pencarian_nyari_nama_dan_alamat_sekaligus(): void
    {
        // Begini cara teknisi mengingat pelanggannya: satu kawasan industri
        // isinya belasan PT bernama mirip, dan yang dia pegang alamat
        // penjemputannya.
        $lewatNama = collect($this->cari('Beta'))->pluck('nama')->all();
        $this->assertSame(['PT Beta Instrumen'], $lewatNama);

        $lewatAlamat = collect($this->cari('Cikarang'))->pluck('nama')->all();
        $this->assertSame(['CV Gamma Kalibrasi', 'PT Alfa Presisi'], $lewatAlamat);

        // Kunci yang nggak kena nama MAUPUN alamat balik kosong — bukan balik
        // utuh. Daftar utuh yang kelihatan kayak "pencariannya rusak" itu
        // persis yang kejadian waktu mobile ngirim `?search=` ke endpoint yang
        // bacanya `q`.
        $this->assertCount(0, $this->cari('Surabaya'));
    }

    public function test_pencarian_lewat_alamat_nggak_nembus_ke_organisasi_lain(): void
    {
        // `orWhere` tanpa kurung naik ke tingkat atas dan bikin saringan
        // organisasinya bocor. Pelanggan lab sebelah yang alamatnya kena bakal
        // ikut kebawa — ke endpoint yang kebuka semua role.
        Organization::factory()->create(['id' => 2]);
        Customer::factory()->create([
            'organization_id' => 2,
            'nama' => 'PT Lab Sebelah',
            'alamat' => 'Jl. Raya Cikarang KM 31, Bekasi',
        ]);

        $hasil = collect($this->cari('Cikarang'))->pluck('nama')->all();

        $this->assertNotContains('PT Lab Sebelah', $hasil);
        $this->assertSame(['CV Gamma Kalibrasi', 'PT Alfa Presisi'], $hasil);
    }

    public function test_param_q_masih_diterima(): void
    {
        // Dokumen lama nyebut `q` buat lookup pelanggan. Filter yang diabaikan
        // diam-diam itu bug yang mahal.
        $hasil = $this->actingAs($this->teknisi)
            ->getJson('/api/customers/lookup?q=Cikarang')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $hasil);
    }
}
