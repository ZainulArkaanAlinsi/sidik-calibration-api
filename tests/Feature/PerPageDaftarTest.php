<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Support\PerHalaman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BUG-16 (QA ronde 2-B): `per_page` dihormati di lima daftar yang dulu
 * mengabaikannya (selalu 15): `/equipments`, `/customers`, `/certificates`,
 * `/orders`, `/users`.
 */
class PerPageDaftarTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);

        $this->isiDaftar();
    }

    private function isiDaftar(): void
    {
        Customer::factory()->count(18)->create(['organization_id' => $this->lab->id])
            ->each(fn (Customer $pt) => $this->isiTurunan($pt));
        User::factory()->count(18)->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI]);
    }

    /** Satu alat, satu sertifikat, dan satu order untuk PT ini. */
    private function isiTurunan(Customer $pt): void
    {
        $alat = Equipment::factory()->create(['organization_id' => $this->lab->id, 'customer_id' => $pt->id]);
        $sesi = CalibrationSession::factory()->create(['organization_id' => $this->lab->id, 'equipment_id' => $alat->id]);

        Certificate::factory()->create(['organization_id' => $this->lab->id, 'calibration_session_id' => $sesi->id]);
        Order::factory()->create(['organization_id' => $this->lab->id, 'customer_id' => $pt->id]);
    }

    /** @return array<string, array{0: string}> */
    public static function daftar(): array
    {
        return [
            '/equipments' => ['/api/equipments'],
            '/customers' => ['/api/customers'],
            '/certificates' => ['/api/certificates'],
            '/orders' => ['/api/orders'],
            '/users' => ['/api/users'],
        ];
    }

    #[DataProvider('daftar')]
    public function test_per_page_dihormati_dan_dilaporkan_di_meta(string $uri): void
    {
        // Tiap daftar memuat 18 baris atau lebih: 18 = semuanya dalam satu halaman.
        foreach ([2, 7, 18] as $n) {
            $r = $this->actingAs($this->admin)->getJson("{$uri}?per_page={$n}")->assertOk();

            $this->assertSame($n, $r->json('meta.per_page'), "{$uri} per_page={$n}: meta.per_page");
            $this->assertCount($n, $r->json('data'), "{$uri} per_page={$n}: jumlah baris");
        }
    }

    #[DataProvider('daftar')]
    public function test_bawaan_tetap_15_tanpa_per_page(string $uri): void
    {
        $r = $this->actingAs($this->admin)->getJson($uri)->assertOk();

        $this->assertSame(15, $r->json('meta.per_page'));
        $this->assertCount(15, $r->json('data'));

        // `per_page=` kosong diperlakukan sama dengan tidak dikirim.
        $kosong = $this->actingAs($this->admin)->getJson("{$uri}?per_page=")->assertOk();
        $this->assertSame(15, $kosong->json('meta.per_page'));
    }

    #[DataProvider('daftar')]
    public function test_di_luar_batas_atau_bukan_bilangan_bulat_ditolak_422(string $uri): void
    {
        foreach (['0', '-1', '101', '5000', 'abc', '2.5'] as $nilai) {
            $this->actingAs($this->admin)->getJson("{$uri}?per_page={$nilai}")
                ->assertStatus(422)
                ->assertJsonValidationErrors('per_page');
        }

        // Batas tepat 1 dan 100 diterima.
        $this->assertSame(1, $this->actingAs($this->admin)->getJson("{$uri}?per_page=1")->assertOk()->json('meta.per_page'));
        $this->assertSame(PerHalaman::MAKS, $this->actingAs($this->admin)->getJson("{$uri}?per_page=100")->assertOk()->json('meta.per_page'));
    }

    #[DataProvider('daftar')]
    public function test_halaman_berikutnya_membawa_per_page_dan_bentuk_respons_tidak_berubah(string $uri): void
    {
        $halaman1 = $this->actingAs($this->admin)->getJson("{$uri}?per_page=5")->assertOk();

        $this->assertEqualsCanonicalizing(['data', 'links', 'meta'], array_keys($halaman1->json()));
        $this->assertStringContainsString('per_page=5', (string) $halaman1->json('links.next'));
        $this->assertGreaterThan(1, $halaman1->json('meta.last_page'));

        $halaman2 = $this->actingAs($this->admin)->getJson("{$uri}?per_page=5&page=2")->assertOk();
        $this->assertSame(2, $halaman2->json('meta.current_page'));
        $this->assertCount(5, $halaman2->json('data'));
        $this->assertNotSame(
            array_column($halaman1->json('data'), 'id'),
            array_column($halaman2->json('data'), 'id'),
        );
    }
}
