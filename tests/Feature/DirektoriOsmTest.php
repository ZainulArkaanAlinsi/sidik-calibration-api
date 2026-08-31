<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Direktori\DirektoriBerlapis;
use App\Services\Direktori\DirektoriPerusahaan;
use App\Services\Direktori\NominatimDirektori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /**
     * Bawaannya BERLAPIS, dan tetap hidup tanpa disetel apa-apa.
     *
     * Bagian kedua yang penting: `tersedia()` tetap `true` walau key Google
     * kosong, karena OSM di lapis belakang nggak butuh key. Kalau ini pernah
     * jadi `false` di lingkungan tanpa key, tombol cari hilang dari layar
     * teknisi — dan itu keadaan yang paling sering bikin aplikasinya kelihatan
     * rusak di tengah kerjaan.
     */
    public function test_bawaannya_berlapis_dan_tetap_siap_tanpa_key(): void
    {
        config()->offsetUnset('services.direktori_perusahaan.driver');
        config()->set('services.direktori_perusahaan.key', null);

        $this->assertInstanceOf(
            DirektoriBerlapis::class,
            app(DirektoriPerusahaan::class),
        );
        $this->assertTrue(app(DirektoriPerusahaan::class)->tersedia());
    }

    /** Diminta OSM saja → yang lahir OSM saja, tanpa lapis lain di belakangnya. */
    public function test_driver_osm_eksplisit_cuma_osm(): void
    {
        config()->set('services.direktori_perusahaan.driver', 'osm');

        $this->assertInstanceOf(
            NominatimDirektori::class,
            app(DirektoriPerusahaan::class),
        );
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
     * Setelan User-Agent yang KOSONG tetap tidak berangkat anonim.
     *
     * Bukan kasus tepi: `DIREKTORI_PERUSAHAAN_USER_AGENT=` yang dibiarkan
     * kosong di `.env` mulangin string kosong, bukan `null` — jadi nilai bawaan
     * di `config/services.php` nggak pernah kepakai. Persis itu yang kejadian
     * di CI, tempat `.env.example` disalin apa adanya.
     *
     * Di sini merah. Di server sungguhan nggak ada yang merah — Nominatim cuma
     * berhenti menjawab, dan alamat IP server labnya diblokir.
     */
    public function test_user_agent_kosong_tetap_menyebut_dirinya(): void
    {
        config()->set('services.direktori_perusahaan.user_agent', '');

        $this->jawaban([]);

        $this->actingAs($this->teknisi)->getJson(self::URL.'?search=Sinar')->assertOk();

        Http::assertSent(function ($request) {
            $ua = $request->header('User-Agent')[0] ?? '';

            return $ua !== '' && ! str_starts_with($ua, 'GuzzleHttp');
        });
    }

    /**
     * Direktorinya menjawab tapi nol baris lolos dibaca → dicatat, bukan diam.
     *
     * Ini satu-satunya kegagalan yang pulang sebagai `200` + daftar kosong,
     * jadi di layar teknisi dia terbaca persis sama dengan "PT-nya memang belum
     * dipetakan". Tanpa jejak ini, pembacaan yang rusak bisa hidup
     * berbulan-bulan dan yang kelihatan cuma "direktorinya kok nggak pernah
     * nemu apa-apa".
     *
     * Yang dikunci di sini kuncinya ikut tercatat — itu yang menjawab
     * "bentuknya berubah di mana", dan tanpa itu lognya cuma bikin tahu ada
     * masalah tanpa tahu di mana.
     */
    public function test_semua_baris_terbuang_dicatat_di_log(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $pesan, array $konteks) {
                return str_contains($pesan, 'nol baris')
                    && $konteks['jumlah_baris'] === 2
                    && $konteks['kunci_baris_pertama'] === ['tipe_yang_kita_kenal', 'id'];
            });

        // Dua baris yang bentuknya nggak dikenali sama sekali — persis yang
        // terjadi kalau Nominatim mengganti nama fieldnya.
        $this->jawaban([
            ['tipe_yang_kita_kenal' => 'way', 'id' => 1],
            ['tipe_yang_kita_kenal' => 'node', 'id' => 2],
        ]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Nol hasil yang SUNGGUHAN nggak ikut dicatat.
     *
     * Bedanya itu seluruh gunanya. Log yang ikut menyala tiap kali teknisi
     * mencari PT yang memang belum dipetakan — kejadian normal, sering — bikin
     * jejak yang tadi berharga tenggelam, dan orang yang memeriksanya berhenti
     * membacanya.
     */
    public function test_nol_hasil_sungguhan_tidak_dicatat(): void
    {
        Log::shouldReceive('warning')->never();

        $this->jawaban([]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar')
            ->assertOk()
            ->assertJsonCount(0, 'data');
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
     * Ujung ke ujung: Google terpasang tapi nol hasil → OSM yang menjawab.
     *
     * Ini keadaan yang paling sering terjadi di lapangan dan paling gampang
     * salah dikodekan. Nol hasil dari lapis pertama gampang diperlakukan
     * sebagai jawaban akhir — dan begitu itu terjadi, lapis kedua jadi hiasan
     * yang tidak pernah dipakai, persis hal yang bikin cakupannya terasa tipis.
     *
     * Atribusinya ikut diuji, dan itu bukan detail administratif: memajang
     * "Powered by Google" di atas baris yang datang dari OpenStreetMap
     * menyebut sumber yang salah, dan itu pelanggaran lisensi yang tidak
     * meninggalkan satu pun error.
     */
    public function test_google_nol_hasil_dilanjut_ke_osm_berikut_atribusinya(): void
    {
        config()->set('services.direktori_perusahaan.driver', 'auto');
        config()->set('services.direktori_perusahaan.key', 'kunci-uji');

        Http::fake([
            'places.googleapis.com/*' => Http::response(['places' => []]),
            'nominatim.openstreetmap.org/*' => Http::response([$this->tempat()]),
        ]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'PT Sinar Rejeki')
            ->assertJsonPath('atribusi', NominatimDirektori::ATRIBUSI);
    }

    /**
     * Google mati total → OSM tetap menjawab, teknisi nggak kehilangan apa pun.
     *
     * Tanpa lapis kedua, kuota habis atau key ditolak di tengah hari kerja
     * mematikan pencarian PT untuk semua teknisi sekaligus.
     */
    public function test_google_mati_dilanjut_ke_osm(): void
    {
        config()->set('services.direktori_perusahaan.driver', 'auto');
        config()->set('services.direktori_perusahaan.key', 'kunci-uji');

        Http::fake([
            'places.googleapis.com/*' => Http::response(['error' => 'kuota habis'], 429),
            'nominatim.openstreetmap.org/*' => Http::response([$this->tempat()]),
        ]);

        $this->actingAs($this->teknisi)
            ->getJson(self::URL.'?search=Sinar')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('atribusi', NominatimDirektori::ATRIBUSI);
    }

    /**
     * Salah ketik nama driver di `.env` jatuh ke susunan berlapis, BUKAN
     * melempar: mematikan pendaftaran pelanggan di lapangan itu hukuman yang
     * jauh lebih besar daripada kesalahannya.
     */
    public function test_driver_yang_tidak_dikenali_jatuh_ke_berlapis(): void
    {
        config()->set('services.direktori_perusahaan.driver', 'salah-ketik');

        $this->assertInstanceOf(
            DirektoriBerlapis::class,
            app(DirektoriPerusahaan::class),
        );
    }
}
