<?php

namespace Tests\Feature\Pelanggan;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\UndanganPelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RuteTerdaftar;
use Illuminate\Support\Facades\Route as Router;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * NFR-03 — tiap rute pelanggan ber-ID dicoba dengan ID milik perusahaan LAIN.
 *
 * ## Yang dijaga, dan seberapa besar cakupannya HARI INI
 *
 * Daftar rutenya dibaca dari router, bukan diketik — jadi rute ber-parameter
 * yang lahir nanti ikut tersapu otomatis. Per 16 Sep 2026 rute pelanggan
 * ber-parameter baru **dua** (`/anggota/undangan/{undangan}` dan
 * `/anggota/{anggota}/nonaktifkan`), dan dua-duanya sudah punya test 404
 * sendiri di `AnggotaDanPeranTest`.
 *
 * Ditulis terang supaya tidak salah dibaca: **nilai test ini bukan di hari
 * ini.** Nilainya waktu `/alat/{id}`, `/sertifikat/{id}`, dan
 * `/permintaan/{ulid}` mendarat — mereka tidak bisa lahir tanpa bukti isolasi,
 * karena parameter tanpa fixture memerahkan
 * [test_tiap_parameter_rute_punya_fixture()].
 *
 * ## Sumbu yang TIDAK diuji di sini
 *
 * Isolasi lewat header `X-Perusahaan-Id` ada di `KonteksPerusahaanTest`. Dua
 * sumbu yang berbeda: yang di sana "perusahaan mana yang saya sebut", yang di
 * sini "ID milik siapa yang saya kirim di URL". Rute bisa aman di satu sumbu
 * dan bocor di sumbu lain.
 *
 * ## 404, bukan 403 (BR-02)
 *
 * 403 sudah memberi tahu bahwa baris dengan ID itu ADA — cukup buat menyisir
 * pelanggan PT Sidik dengan menembak ID satu per satu.
 */
class IsolasiPerusahaanTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const PREFIX = 'api/pelanggan/v1/';

    private User $picA;

    private Customer $perusahaanA;

    private Customer $perusahaanB;

    /** @var array<string, string> nama parameter → ID milik perusahaan B */
    private array $milikB = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        // A = perusahaan si pemanggil. Dia PIC utama supaya gerbang `peran:`
        // tidak menjawab lebih dulu — yang diuji isolasi ID, bukan peran.
        $this->picA = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $this->perusahaanA = $this->picA->keanggotaan()->first()->customer;

        // B = perusahaan orang lain, lengkap dengan barisnya sendiri.
        $picB = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $this->perusahaanB = $picB->keanggotaan()->first()->customer;

        $this->milikB = [
            'anggota' => (string) $picB->keanggotaan()->first()->getKey(),
            'undangan' => (string) UndanganPelanggan::factory()->create([
                'organization_id' => $this->perusahaanB->organization_id,
                'customer_id' => $this->perusahaanB->getKey(),
            ])->getKey(),
        ];
    }

    /** @return list<RuteTerdaftar> */
    private function ruteBerParameter(): array
    {
        $hasil = [];

        foreach (Router::getRoutes() as $rute) {
            if (! str_starts_with($rute->uri(), self::PREFIX) || ! str_contains($rute->uri(), '{')) {
                continue;
            }

            $hasil[] = $rute;
        }

        return $hasil;
    }

    /**
     * INTI — tiap rute ber-ID dijawab 404 buat ID milik perusahaan lain.
     *
     * Pelanggarnya dikumpulkan, bukan gagal di rute pertama: kalau tiga rute
     * bocor, yang berguna melihat ketiganya sekaligus.
     */
    public function test_NFR_03_rute_ber_id_menolak_id_perusahaan_lain(): void
    {
        $pelanggar = [];
        $diuji = 0;

        foreach ($this->ruteBerParameter() as $rute) {
            $uri = $rute->uri();
            $method = collect($rute->methods())->first(fn (string $m) => $m !== 'HEAD') ?? 'GET';

            foreach ($rute->parameterNames() as $nama) {
                $uri = str_replace('{'.$nama.'}', $this->milikB[$nama] ?? '', $uri);
            }

            $this->permintaanBaru();

            $status = $this->withHeaders($this->bearer($this->picA))
                ->json($method, '/'.$uri)
                ->status();

            $diuji++;

            if ($status !== 404) {
                $pelanggar[] = "{$method} {$rute->uri()} → {$status}";
            }
        }

        $this->assertGreaterThan(
            0,
            $diuji,
            'Nol rute pelanggan ber-parameter yang keuji — daftar rutenya kemungkinan nggak kebaca, '
            .'dan test ini jadi hijau tanpa memeriksa apa pun.'
        );

        $this->assertSame([], $pelanggar, sprintf(
            "%d dari %d rute pelanggan ber-ID nggak menjawab 404 buat ID milik perusahaan lain.\n".
            "403 pun nggak cukup: dia sudah memberi tahu bahwa baris dengan ID itu ADA (BR-02).\n\n  %s",
            count($pelanggar),
            $diuji,
            implode("\n  ", $pelanggar),
        ));
    }

    /**
     * PENJAGA yang bikin test di atas tidak pernah jadi hijau palsu.
     *
     * Parameter tanpa fixture disulih jadi string kosong, dan URL yang jadi
     * cacat membalas 404 karena RUTENYA tidak ketemu — bukan karena isolasinya
     * bekerja. Test di atas tidak bisa membedakan keduanya, jadi yang
     * membedakan test ini.
     */
    public function test_tiap_parameter_rute_punya_fixture(): void
    {
        $tanpaFixture = [];

        foreach ($this->ruteBerParameter() as $rute) {
            foreach ($rute->parameterNames() as $nama) {
                if (! array_key_exists($nama, $this->milikB)) {
                    $tanpaFixture[] = "{$nama} (dipakai {$rute->uri()})";
                }
            }
        }

        $this->assertSame([], array_unique($tanpaFixture), sprintf(
            "Parameter rute pelanggan ini belum punya fixture milik perusahaan B di setUp():\n  - %s\n\n".
            "Rute baru ber-ID WAJIB dikasih fixture, kalau nggak URL-nya jadi cacat, membalas 404 karena\n".
            'RUTENYA nggak ketemu, dan sapuan isolasi di atas lolos tanpa pernah menguji apa pun.',
            implode("\n  - ", array_unique($tanpaFixture)),
        ));
    }

    /**
     * Fixture-nya beneran hidup — bukan ID yang kebetulan tidak ada.
     *
     * Tanpa ini, fixture yang salah bikin seluruh sapuan di atas membalas 404
     * karena barisnya memang tidak ada, dan hasilnya hijau yang tidak berarti
     * apa-apa.
     */
    public function test_fixture_perusahaan_b_beneran_ada(): void
    {
        $this->assertDatabaseHas('customer_members', [
            'id' => $this->milikB['anggota'],
            'customer_id' => $this->perusahaanB->getKey(),
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $this->assertDatabaseHas('undangan_pelanggan', [
            'id' => $this->milikB['undangan'],
            'customer_id' => $this->perusahaanB->getKey(),
        ]);

        $this->assertNotSame($this->perusahaanA->getKey(), $this->perusahaanB->getKey());
    }

    /**
     * Sisi positifnya: ID milik perusahaan SENDIRI memang tidak 404.
     *
     * Tanpa ini, sapuan di atas sama-sama hijau seandainya seluruh rutenya
     * rusak dan selalu membalas 404 buat siapa pun.
     */
    public function test_id_milik_sendiri_tidak_ikut_ditolak(): void
    {
        $undanganSendiri = UndanganPelanggan::factory()->create([
            'organization_id' => $this->perusahaanA->organization_id,
            'customer_id' => $this->perusahaanA->getKey(),
        ]);

        $this->withHeaders($this->bearer($this->picA))
            ->deleteJson("/api/pelanggan/v1/anggota/undangan/{$undanganSendiri->getKey()}")
            ->assertOk();
    }
}
