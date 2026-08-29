<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `GET /api/customers/direktori` — cari nama & alamat PT di direktori LUAR.
 *
 * Dipakai waktu pencarian di master lab nol hasil: pelanggannya beneran belum
 * pernah masuk. Tanpa ini teknisi mengetik nama & alamat dari ingatan, dan
 * alamat yang salah ketik mendarat di blok OWNER sertifikat.
 *
 * ## Yang paling dijaga berkas ini
 *
 * **Tiga keadaan yang di layar gampang kelihatan sama, tapi artinya jauh
 * berbeda:** direktorinya menjawab dan memang nihil, key-nya belum disetel, dan
 * direktorinya lagi mati. Diratakan jadi "daftar kosong", teknisi membacanya
 * sebagai "PT-nya nggak ada di direktori" lalu mendaftarkan ulang perusahaan
 * yang sebenarnya ada di sana — nambah kembar justru lewat fitur yang dipasang
 * buat menguranginya.
 */
class DirektoriPerusahaanTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/customers/direktori';

    private User $teknisi;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        $this->teknisi = User::factory()->create();
        $this->viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        config()->set('services.direktori_perusahaan.key', 'kunci-uji');
    }

    /** @param  array<int, array<string, mixed>>  $tempat */
    private function jawabanDirektori(array $tempat): void
    {
        Http::fake([
            'places.googleapis.com/*' => Http::response(['places' => $tempat]),
        ]);
    }

    /**
     * Satu tempat seperti yang dipulangkan Places API.
     *
     * `addressComponents` selalu ikut karena di situlah saringan negaranya
     * bekerja — tempat tanpa komponen itu memang dibuang, dan test yang lupa
     * memasangnya bakal hijau/merah karena sebab yang salah.
     *
     * @return array<string, mixed>
     */
    private function tempat(
        string $id,
        ?string $nama = null,
        ?string $alamat = null,
        ?string $negara = 'ID',
    ): array {
        return [
            'id' => $id,
            ...($nama === null ? [] : ['displayName' => ['text' => $nama]]),
            ...($alamat === null ? [] : ['formattedAddress' => $alamat]),
            ...($negara === null ? [] : ['addressComponents' => [
                ['types' => ['locality', 'political'], 'shortText' => 'Bekasi'],
                ['types' => ['country', 'political'], 'shortText' => $negara],
            ]]),
        ];
    }

    public function test_hasil_direktori_dipulangkan_sebagai_nama_alamat_dan_ref(): void
    {
        $this->jawabanDirektori([
            $this->tempat(
                'tempat-abc123',
                'PT Sinar Rejeki',
                'Kawasan Industri MM2100 Blok C-3, Bekasi',
            ),
        ]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar Rejeki')
            ->assertOk()
            ->assertJsonPath('data.0.ref', 'tempat-abc123')
            ->assertJsonPath('data.0.nama', 'PT Sinar Rejeki')
            ->assertJsonPath('data.0.alamat', 'Kawasan Industri MM2100 Blok C-3, Bekasi');
    }

    /**
     * Key kosong = 503 dengan sebabnya, BUKAN daftar kosong.
     *
     * Ini butir terpenting di berkas ini. Daftar kosong di layar kebaca "PT-nya
     * nggak ada di direktori", dan teknisi yang percaya itu mendaftarkan ulang
     * perusahaan yang sebenarnya ada.
     */
    public function test_key_belum_disetel_bilang_belum_disetel_bukan_nol_hasil(): void
    {
        config()->set('services.direktori_perusahaan.key', null);
        Http::fake();

        $respons = $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar Rejeki')
            ->assertStatus(503)
            ->assertJsonPath('tersedia', false);

        $this->assertStringNotContainsString('data', json_encode($respons->json('data') ?? []));
        Http::assertNothingSent();
    }

    /**
     * Direktorinya nolak/mati = 502, juga bukan daftar kosong. Bedanya dengan
     * 503 penting buat yang membaca log: yang satu setelan, yang satu jaringan.
     */
    public function test_direktori_yang_menolak_jadi_502_bukan_nol_hasil(): void
    {
        Http::fake([
            'places.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid']], 403),
        ]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar Rejeki')
            ->assertStatus(502)
            ->assertJsonPath('tersedia', true);
    }

    /**
     * Pesan penyedianya nggak boleh nembus ke klien: dia bisa memuat potongan
     * key atau id proyek, dan HP-nya megang layar yang dilihat orang luar lab.
     */
    public function test_pesan_penyedia_tidak_bocor_ke_klien(): void
    {
        Http::fake([
            'places.googleapis.com/*' => Http::response(
                ['error' => ['message' => 'API key not valid: AIzaRAHASIA123']],
                403,
            ),
        ]);

        $isi = $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar Rejeki')
            ->assertStatus(502)
            ->getContent();

        $this->assertStringNotContainsString('AIzaRAHASIA123', (string) $isi);
        $this->assertStringNotContainsString('API key not valid', (string) $isi);
    }

    /**
     * Direktorinya menjawab dan memang nihil = 200 + daftar kosong. Ini SATU-
     * SATUNYA keadaan yang boleh kelihatan begitu.
     */
    public function test_nihil_beneran_tetap_200_dengan_daftar_kosong(): void
    {
        $this->jawabanDirektori([]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Perusahaan Yang Tidak Ada')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Baris tanpa id atau tanpa nama dilewat, bukan diisi tanda tanya. Yang
     * dipilih dari daftar ini mendarat di blok OWNER sertifikat — baris setengah
     * jadi di situ lebih buruk daripada baris yang nggak ada.
     */
    public function test_baris_setengah_jadi_dari_direktori_dilewat(): void
    {
        $this->jawabanDirektori([
            // Tanpa `id` — dan komponen negaranya SENGAJA lengkap, biar yang
            // membuangnya beneran penjaga id, bukan saringan negara.
            [...$this->tempat('x', 'PT Tanpa Id', 'Jl. Mana Saja'), 'id' => null],
            $this->tempat('tempat-tanpa-nama', null, 'Jl. Mana Saja'),
            $this->tempat('tempat-utuh', 'PT Utuh', 'Jl. Ada'),
        ]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'PT Utuh');
    }

    /** Alamat boleh kosong di direktori — itu bukan alasan membuang barisnya. */
    public function test_tempat_tanpa_alamat_tetap_ikut_dengan_alamat_null(): void
    {
        $this->jawabanDirektori([
            $this->tempat('tempat-abc', 'PT Tanpa Alamat'),
        ]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Tanpa Alamat')
            ->assertOk()
            ->assertJsonPath('data.0.nama', 'PT Tanpa Alamat')
            ->assertJsonPath('data.0.alamat', null);
    }

    /**
     * Perusahaan di luar Indonesia DIBUANG, bukan diserahkan ke mata teknisi.
     *
     * `regionCode` di badan request cuma mencondongkan, nggak menyaring — jadi
     * tempat di Johor atau Singapura beneran bisa nongol buat kata kunci yang
     * mirip. Alamatnya memang ikut dipajang, tapi orang yang lagi buru-buru di
     * gerbang pabrik membaca nama dulu, dan yang dia pilih mendarat di blok
     * OWNER sertifikat.
     */
    public function test_tempat_luar_negeri_dibuang(): void
    {
        $this->jawabanDirektori([
            $this->tempat('tempat-my', 'Sinar Rejeki Sdn Bhd', 'Johor Bahru, Malaysia', 'MY'),
            $this->tempat('tempat-id', 'PT Sinar Rejeki', 'Cikarang, Bekasi'),
        ]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar Rejeki')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'PT Sinar Rejeki');
    }

    /**
     * Tempat yang komponen negaranya nggak ada juga dibuang.
     *
     * Dua kerugiannya diadu: yang kebuang keliru tinggal diketik tangan — jalur
     * itu selalu jalan — sementara yang lolos keliru mendarat di sertifikat
     * sebagai perusahaan yang salah negara.
     */
    public function test_tempat_tanpa_komponen_negara_dibuang(): void
    {
        $this->jawabanDirektori([
            $this->tempat('tempat-entah', 'PT Entah Di Mana', 'Jl. Tanpa Negara', null),
        ]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Entah')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Di bawah tiga huruf hasilnya sampah buat orang yang lagi mencocokkan satu
     * papan nama, dan requestnya tetap ditagih ke pihak ketiga.
     */
    public function test_kata_kunci_terlalu_pendek_ditolak_tanpa_nembak_keluar(): void
    {
        Http::fake();

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=PT')
            ->assertStatus(422)
            ->assertJsonValidationErrors('search');

        Http::assertNothingSent();
    }

    /** Viewer nggak mendaftarkan pelanggan, jadi nggak perlu bakar kuota berbayar. */
    public function test_viewer_ditolak_tanpa_nembak_keluar(): void
    {
        Http::fake();

        $this->actingAs($this->viewer)
            ->getJson(self::URL.'?search=Sinar Rejeki')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * Cuma tiga field yang diminta. `X-Goog-FieldMask` yang menentukan golongan
     * tagihan — minta field yang nggak dipakai berarti bayar golongan lebih
     * mahal buat data yang langsung dibuang.
     */
    public function test_permintaan_dikunci_ke_indonesia_dan_tiga_field(): void
    {
        $this->jawabanDirektori([]);

        $this->actingAs($this->teknisi)->getJson(self::URL.'?search=Sinar Rejeki')->assertOk();

        Http::assertSent(function ($request) {
            return $request['regionCode'] === 'ID'
                && $request['textQuery'] === 'Sinar Rejeki'
                && $request->hasHeader('X-Goog-FieldMask', 'places.id,places.displayName,places.formattedAddress,places.addressComponents')
                && $request->hasHeader('X-Goog-Api-Key', 'kunci-uji');
        });
    }
}
