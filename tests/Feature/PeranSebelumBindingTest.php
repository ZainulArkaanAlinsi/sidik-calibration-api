<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Rute;
use Tests\TestCase;

/**
 * R2-7 (QA ronde 2): peran diperiksa SEBELUM route model binding.
 *
 * `SubstituteBindings` dulu jalan lebih awal daripada `role:`, jadi peran tanpa
 * hak mendapat 404 untuk ID yang tidak ada tetapi 403 untuk ID yang ADA —
 * termasuk ID milik lab lain. Selisih itu adalah oracle keberadaan ID
 * (133 dari 805 langkah matriks peran di QA ronde 2).
 *
 * Yang dijaga: peran tanpa hak atas sebuah rute mendapat jawaban SERAGAM (403)
 * untuk ID mana pun — ada, tidak ada, atau milik lab lain. Peran yang BERHAK
 * tidak berubah: 404 lintas organisasi tetap 404.
 */
class PeranSebelumBindingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private Organization $labLain;

    /** @var array<string, User> */
    private array $orang = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Pembatas laju tidak relevan untuk urutan middleware, dan sapuan ratusan
        // permintaan akan menabraknya (429 menutupi jawaban yang diuji).
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->lab = Organization::factory()->create(['nama' => 'Lab Satu']);
        $this->labLain = Organization::factory()->create(['nama' => 'Lab Dua']);

        foreach ([User::ROLE_VIEWER, User::ROLE_TEKNISI, User::ROLE_ADMIN] as $peran) {
            $this->orang[$peran] = User::factory()->create([
                'organization_id' => $this->lab->id,
                'role' => $peran,
                'status' => User::STATUS_AKTIF,
            ]);
        }
    }

    /**
     * Peran yang lolos SEMUA middleware `role:` sebuah rute (gerbang luar dan dalam
     * sama-sama harus dilewati). Super admin tidak ikut: aturannya sendiri.
     *
     * @return list<string>
     */
    private function peranYangBoleh(Route $rute): array
    {
        $semua = [User::ROLE_VIEWER, User::ROLE_TEKNISI, User::ROLE_ADMIN];

        foreach ($rute->gatherMiddleware() as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'role:')) {
                $semua = array_values(array_intersect($semua, explode(',', substr($mw, 5))));
            }
        }

        return $semua;
    }

    /** @return list<array{0: string, 1: string, 2: Route}> [metode, uri, rute] rute internal ber-parameter dan ber-`role:` */
    private function ruteBerParameter(): array
    {
        $hasil = [];

        foreach (Rute::getRoutes()->getRoutes() as $rute) {
            $uri = $rute->uri();

            if (! str_starts_with($uri, 'api/') || str_starts_with($uri, 'api/pelanggan/')) {
                continue;
            }

            if (! str_contains($uri, '{')) {
                continue;
            }

            $adaRole = collect($rute->gatherMiddleware())
                ->contains(fn ($mw): bool => is_string($mw) && str_starts_with($mw, 'role:'));

            if (! $adaRole) {
                continue;
            }

            foreach ($rute->methods() as $metode) {
                if (in_array($metode, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $hasil[] = [$metode, $uri, $rute];
                }
            }
        }

        return $hasil;
    }

    private function isi(string $uri, int|string $id): string
    {
        return '/'.preg_replace('/\{[^}]+\}/', (string) $id, $uri);
    }

    public function test_peran_tanpa_hak_dijawab_403_seragam_untuk_id_mana_pun(): void
    {
        $rute = $this->ruteBerParameter();

        // Penjaga lantai: daftar rute datang dari router, jadi bisa menyusut
        // diam-diam dan PHPUnit tetap "OK" dengan lebih sedikit yang diperiksa.
        $this->assertGreaterThanOrEqual(100, count($rute), 'Daftar rute ber-parameter & ber-role menyusut.');

        $diperiksa = 0;
        $melenceng = [];

        foreach ($rute as [$metode, $uri, $objek]) {
            $boleh = $this->peranYangBoleh($objek);

            foreach ($this->orang as $peran => $pengguna) {
                if (in_array($peran, $boleh, true)) {
                    continue;
                }

                // 1 = kemungkinan besar ADA (fixture setUp), 99999999 = pasti tidak ada.
                foreach ([1, 99999999] as $id) {
                    $respons = $this->actingAs($pengguna, 'sanctum')
                        ->json($metode, $this->isi($uri, $id), []);

                    $diperiksa++;

                    if ($respons->getStatusCode() !== 403) {
                        $melenceng[] = "{$peran} {$metode} {$uri} id={$id} -> {$respons->getStatusCode()}";
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(300, $diperiksa, 'Terlalu sedikit pasangan peran x rute yang diperiksa.');
        $this->assertSame([], $melenceng, "Peran tanpa hak harus 403 untuk ID apa pun (oracle 403-vs-404):\n".implode("\n", array_slice($melenceng, 0, 25)));
    }

    /** Tamu (tanpa token) tetap 401 untuk ID apa pun: `auth:sanctum` tetap di depan peran. */
    public function test_tamu_tetap_401_untuk_id_apa_pun(): void
    {
        $milik = Customer::factory()->create(['organization_id' => $this->lab->id]);

        foreach ([$milik->id, 99999999] as $id) {
            $this->getJson("/api/customers/{$id}")->assertUnauthorized();
        }
    }

    /**
     * Pasangannya `aplikasi:internal`: token yang dikeluarkan untuk aplikasi lain
     * (mis. aplikasi pelanggan) ditolak 403 SEBELUM peran dan SEBELUM binding —
     * urutan yang sengaja (komentar rute M1-03). Tanpa memprioritaskannya, memindah
     * `role:` ke depan binding justru menaruh `aplikasi:` di belakang binding dan
     * membuka oracle yang sama untuk token aplikasi yang salah.
     */
    public function test_token_aplikasi_yang_salah_dijawab_403_seragam_untuk_id_mana_pun(): void
    {
        $milik = Customer::factory()->create(['organization_id' => $this->lab->id]);
        $asing = Customer::factory()->create(['organization_id' => $this->labLain->id]);

        $token = $this->orang[User::ROLE_ADMIN]->createToken('uji', ['pelanggan'])->plainTextToken;

        foreach ([$milik->id, $asing->id, 99999999] as $id) {
            $this->withToken($token)->getJson("/api/customers/{$id}")
                ->assertForbidden()
                ->assertJsonPath('kode', 'bukan_aplikasi_ini');
        }
    }

    /**
     * Matriks peran x ID dengan data nyata: ID ada (lab sendiri), ID milik lab lain,
     * dan ID yang tidak ada. Peran tanpa hak: 403 di ketiganya. Peran yang berhak:
     * perilakunya TIDAK berubah — lab sendiri 200, lab lain 404, tidak ada 404.
     */
    public function test_matriks_peran_x_id_ada_lab_lain_dan_tidak_ada(): void
    {
        $milik = Customer::factory()->create(['organization_id' => $this->lab->id]);
        $asing = Customer::factory()->create(['organization_id' => $this->labLain->id]);

        $kategori = EquipmentCategory::factory()->create(['organization_id' => $this->lab->id]);
        $alatMilik = Equipment::factory()->create([
            'organization_id' => $this->lab->id, 'customer_id' => $milik->id, 'equipment_category_id' => $kategori->id,
        ]);
        $kategoriAsing = EquipmentCategory::factory()->create(['organization_id' => $this->labLain->id]);
        $alatAsing = Equipment::factory()->create([
            'organization_id' => $this->labLain->id, 'customer_id' => $asing->id, 'equipment_category_id' => $kategoriAsing->id,
        ]);

        $kasus = [
            // [metode, template, id milik, id asing]
            ['GET', 'api/customers/{customer}', $milik->id, $asing->id],
            ['DELETE', 'api/equipments/{equipment}', $alatMilik->id, $alatAsing->id],
            ['POST', 'api/customers/{id}/pulihkan', $milik->id, $asing->id],
        ];

        $dicoba = 0;

        foreach ($kasus as [$metode, $uri, $idMilik, $idAsing]) {
            $objek = collect(Rute::getRoutes()->getRoutes())->first(
                fn (Route $r): bool => $r->uri() === $uri && in_array($metode, $r->methods(), true),
            );
            $this->assertNotNull($objek, "Rute {$metode} {$uri} tidak ditemukan; sesuaikan matriks.");

            $boleh = $this->peranYangBoleh($objek);
            $this->assertNotSame([], array_diff([User::ROLE_VIEWER, User::ROLE_TEKNISI, User::ROLE_ADMIN], $boleh), "{$uri}: semua peran berhak, matriks tidak menguji apa-apa.");

            foreach ($this->orang as $peran => $pengguna) {
                foreach (['milik' => $idMilik, 'asing' => $idAsing, 'tidak ada' => 99999999] as $nama => $id) {
                    $status = $this->actingAs($pengguna, 'sanctum')->json($metode, $this->isi($uri, $id), [])->getStatusCode();
                    $dicoba++;

                    if (! in_array($peran, $boleh, true)) {
                        $this->assertSame(403, $status, "{$peran} {$metode} {$uri} ID {$nama}: peran tanpa hak harus 403 seragam.");

                        continue;
                    }

                    // Peran yang berhak: lab lain & tidak ada tetap 404 (tidak diubah).
                    if ($nama !== 'milik') {
                        $this->assertSame(404, $status, "{$peran} {$metode} {$uri} ID {$nama}: peran berhak harus tetap 404.");
                    } else {
                        $this->assertNotContains($status, [403, 404], "{$peran} {$metode} {$uri} ID milik sendiri tidak boleh 403/404.");
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(27, $dicoba);
    }
}
