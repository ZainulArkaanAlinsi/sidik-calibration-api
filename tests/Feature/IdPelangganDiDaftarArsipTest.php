<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/arsip/perusahaan` — dua id yang datang dari satu baris, dan kenapa
 * membedakannya menentukan arsip SIAPA yang kebuka.
 *
 * ## Kenapa berkas ini ada
 *
 * Endpoint ini me-list **FOLDER**. `data[].id` itu id folder; id pelanggannya
 * ada di `data[].pelanggan.id`. Rute yang membuka arsip satu PT
 * (`/arsip/perusahaan/{customer}/folder`) ngiket ke `Customer` — jadi mengirim
 * id FOLDER ke situ membuka arsip **pelanggan lain** yang id-nya kebetulan
 * sama, dengan **status 200 dan nol error**. Yang kelihatan di layar admin:
 * sertifikat & alat milik PT yang salah, di bawah judul PT yang benar.
 *
 * Bukan bahaya teoretis. Folder akar PT dibikin belakangan (find-or-create
 * waktu PT-nya pertama dibuka), jadi urutannya nggak ikut urutan pelanggan dan
 * dua id itu memang sering beda. Diuji dengan tiga PT: dua di antaranya kebuka
 * arsip PT lain.
 *
 * Layar Arsip di mobile membaca `data[].id` dan mengirimnya ke rute pelanggan
 * sampai 27 Agt 2026. Ini kejadian KEDUA dengan bentuk yang sama persis —
 * yang pertama pemilih pelanggan di form Tambah Alat, yang juga narik id folder
 * dari endpoint ini dan mengirimnya sebagai `pelanggan_id`.
 *
 * Jadi yang dikunci di sini: **baris ini wajib membawa id pelanggannya sendiri**,
 * biar yang membaca nggak perlu menebak dari `id`.
 */
class IdPelangganDiDaftarArsipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var array<string, Customer> */
    private array $pelanggan = [];

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['id' => 1]);
        $this->admin = User::factory()->admin()->create(['organization_id' => 1]);

        foreach ([
            'Alfa' => 'Jl. Raya Cikarang KM 27, Bekasi',
            'Beta' => 'Kawasan Industri Pulogadung Blok B-4, Jakarta Timur',
            'Gamma' => 'Jl. Industri Selatan No. 88, Bekasi',
        ] as $nama => $alamat) {
            $this->pelanggan[$nama] = Customer::factory()->create([
                'organization_id' => 1,
                'nama' => "PT {$nama}",
                'alamat' => $alamat,
            ]);
        }
    }

    /**
     * Tiap folder akar dipatok id-nya ke id pelanggan URUTAN KEBALIK. Dengan
     * tiga PT, yang tengah kebetulan dapat id-nya sendiri dan dua sisanya
     * menabrak PT lain — dan waktu id folder kebetulan sama dengan id
     * pelanggan, jalur yang salah kelihatan jalan mulus. Itu yang bikin bug-nya
     * bertahan.
     *
     * ## Kenapa id-nya DIPATOK, bukan diserahkan ke AUTO_INCREMENT
     *
     * Premis berkas ini — "id folder membuka PT lain yang ADA" — cuma berlaku
     * selama rentang id `folders` dan `customers` masih bertumpang tindih.
     * `RefreshDatabase` di MySQL memigrasi sekali lalu membungkus tiap test
     * dalam transaksi; rollback mengembalikan barisnya, tapi counter
     * AUTO_INCREMENT-nya **nggak ikut balik**. Dua tabel itu naik dengan laju
     * berbeda tergantung berapa baris yang dibikin test-test sebelumnya (mis.
     * `GlobalSearchTest` bikin Customer tanpa bikin Folder), jadi makin ke
     * belakang suite rentangnya makin melar berjauhan sampai berhenti
     * bertumpang tindih. Begitu itu kejadian premisnya bubar: rutenya balas 404
     * buat semua baris, dan test-nya merah tanpa ada satu pun kode produksi
     * yang rusak.
     *
     * Cuma kelihatan waktu suite PENUH dijalankan di MySQL. Di SQLite (yang
     * dipakai CI lewat `phpunit.xml`) `RefreshDatabase` membangun ulang
     * databasenya tiap test, jadi id-nya selalu balik ke 1 dan premisnya selalu
     * kebetulan berlaku — gerbang yang ketutup cuma yang lokal.
     */
    private function bikinFolderTerbalik(): void
    {
        $pelanggan = array_values($this->pelanggan);
        $idFolder = array_reverse(array_map(fn (Customer $c): int => $c->id, $pelanggan));

        foreach ($pelanggan as $i => $c) {
            // `forceCreate`: `id` sengaja di luar `#[Fillable]` milik Folder,
            // jadi lewat `create()` dia bakal dibuang diam-diam.
            Folder::query()->forceCreate([
                'id' => $idFolder[$i],
                'organization_id' => 1,
                'customer_id' => $c->id,
                'nama' => $c->nama,
                'tipe' => 'sistem',
                'parent_id' => null,
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function daftar(): array
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/arsip/perusahaan')
            ->assertOk()
            ->json('data');
    }

    public function test_tiap_baris_bawa_id_pelanggannya_sendiri(): void
    {
        $this->bikinFolderTerbalik();

        foreach ($this->daftar() as $baris) {
            $this->assertArrayHasKey(
                'pelanggan',
                $baris,
                "Baris '{$baris['nama']}' nggak bawa blok `pelanggan`. Tanpa itu yang membaca "
                .'kepaksa nebak id pelanggan dari `id` — dan `id` itu id FOLDER.'
            );
            $this->assertNotNull($baris['pelanggan']);
            $this->assertArrayHasKey('id', $baris['pelanggan']);
        }
    }

    public function test_id_folder_beda_dari_id_pelanggan_dan_dua_duanya_kekirim(): void
    {
        $this->bikinFolderTerbalik();

        $beda = 0;
        foreach ($this->daftar() as $baris) {
            $idPelanggan = $baris['pelanggan']['id'];
            $milik = Customer::query()->findOrFail($idPelanggan);

            // Nama di baris ini milik pelanggan yang ditunjuk `pelanggan.id`,
            // BUKAN yang ditunjuk `id`.
            $this->assertSame($milik->nama, $baris['nama']);

            if ($baris['id'] !== $idPelanggan) {
                $beda++;
            }
        }

        $this->assertGreaterThan(
            0,
            $beda,
            'Skenarionya nggak kepasang: semua id folder kebetulan sama dengan id pelanggannya, '
            .'jadi test ini nggak lagi menguji apa-apa.'
        );
    }

    public function test_id_folder_yang_dikirim_ke_rute_pelanggan_membuka_pt_lain(): void
    {
        // Inti berkas ini. Bukan mengunci perilaku yang benar — mengunci bahwa
        // salahnya BISA terjadi dan diam, supaya siapa pun yang tergoda
        // menyederhanakan "pakai `id` aja" ketemu bukti duluan.
        $this->bikinFolderTerbalik();

        $tertukar = 0;
        foreach ($this->daftar() as $baris) {
            // `assertOk`, bukan dilewat diam-diam: tiap id folder dipatok
            // menabrak id pelanggan yang ADA, jadi rutenya wajib ketemu baris.
            // 404 di sini artinya skenarionya nggak kepasang — dan dulu itu
            // kelewat lewat `continue`, jadi setup yang bubar kelihatan persis
            // seperti "nggak ada yang tertukar".
            $respons = $this->actingAs($this->admin)
                ->getJson('/api/arsip/perusahaan/'.$baris['id'].'/folder')
                ->assertOk();

            $kebuka = $respons->json('data.pelanggan.nama') ?? $respons->json('data.nama');
            if ($kebuka !== $baris['nama']) {
                $tertukar++;
            }
        }

        $this->assertGreaterThan(
            0,
            $tertukar,
            'Kalau ini nol, artinya rute pelanggan sekarang nolak id folder — bagus, tapi '
            .'berarti bentuk penjagaannya berubah dan catatan di berkas ini perlu diperbarui.'
        );
    }

    public function test_id_pelanggan_yang_dikirim_membuka_pt_yang_benar(): void
    {
        $this->bikinFolderTerbalik();

        foreach ($this->daftar() as $baris) {
            $kebuka = $this->actingAs($this->admin)
                ->getJson('/api/arsip/perusahaan/'.$baris['pelanggan']['id'].'/folder')
                ->assertOk()
                ->json('data.pelanggan.nama');

            $this->assertSame(
                $baris['nama'],
                $kebuka,
                "Daftar nampilin '{$baris['nama']}' tapi yang kebuka '{$kebuka}'."
            );
        }
    }

    public function test_alamat_pelanggan_ikut_kekirim(): void
    {
        // Kartu PT di layar Arsip memang nampilin alamat di bawah namanya —
        // kodenya udah ada. Selama `alamat` nggak dikirim, baris kecil itu
        // nggak pernah kelihatan, dan nggak ada error yang bunyi.
        $this->bikinFolderTerbalik();

        foreach ($this->daftar() as $baris) {
            $this->assertArrayHasKey('alamat', $baris['pelanggan']);
            $this->assertSame(
                Customer::query()->findOrFail($baris['pelanggan']['id'])->alamat,
                $baris['pelanggan']['alamat'],
            );
        }
    }

    public function test_folder_akar_tanpa_pelanggan_pulang_pelanggan_null(): void
    {
        // `folders.customer_id` boleh kosong. Yang membaca harus bisa
        // membedakannya, bukan dikasih tebakan.
        Folder::query()->create([
            'organization_id' => 1,
            'customer_id' => null,
            'nama' => 'Dokumen Mutu',
            'tipe' => 'manual',
            'parent_id' => null,
        ]);

        $baris = collect($this->daftar())->firstWhere('nama', 'Dokumen Mutu');

        $this->assertNotNull($baris, 'folder akar tanpa pelanggan ikut kelist');
        $this->assertNull($baris['pelanggan']);
    }
}
