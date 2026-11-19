<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * QA ronde 2-B, butir 1 (sisi Studio): `studio` (`PastikanAkunStudio`) jalan
 * SEBELUM route model binding.
 *
 * Lima rute `/api/studio/workbook/{workbook}…` mengikat `WorkbookMaster`. Dulu
 * `studio` berjalan sesudah `SubstituteBindings`, jadi akun lab (token `internal`)
 * yang menembak ID workbook mendapat 403 untuk yang ADA dan 404 untuk yang tidak
 * ada — oracle keberadaan workbook. Sekarang 403 seragam untuk ID mana pun.
 * Akun Studio sendiri tidak berubah (ada → 200, tidak ada → 404).
 */
class StudioPenjagaSebelumBindingTest extends TestCase
{
    use RefreshDatabase;
    use WorkbookUji;

    private const TAK_ADA = 99999999;

    private Organization $lab;

    private User $pemilik;

    private WorkbookMaster $wb;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->siapkanFolderUji();

        $this->lab = Organization::factory()->create();
        $this->pemilik = $this->akun(User::ROLE_SUPER_ADMIN, 'pemilik@sidik.test');
        config(['studio.akun' => 'pemilik@sidik.test']);

        $this->artisan('studio:impor-workbook', ['--csv' => true, '--akar' => $this->buatAkarCsv(), '--organisasi' => (string) $this->lab->id, '--oleh' => $this->olehLab($this->lab)])->assertSuccessful();
        $this->wb = WorkbookMaster::query()->where('nama', 'Master_Olah_Data_Micrometer_0-25mm')->sole();
        $this->app['auth']->forgetGuards();
    }

    protected function tearDown(): void
    {
        $this->bersihkanFolderUji();
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string}> [metode, template] kelima rute Studio ber-binding `WorkbookMaster` */
    private function ruteWorkbook(): array
    {
        return [
            ['GET', '/api/studio/workbook/{w}'],
            ['GET', '/api/studio/workbook/{w}/riwayat'],
            ['GET', '/api/studio/workbook/{w}/lembar/apa-saja'],
            ['GET', '/api/studio/workbook/{w}/gambar/'.str_repeat('0', 32)],
            ['POST', '/api/studio/workbook/{w}/hitung'],
        ];
    }

    public function test_akun_lab_dijawab_403_seragam_untuk_workbook_ada_dan_tidak_ada(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER, User::ROLE_SUPER_ADMIN] as $role) {
            $user = $this->akun($role, "{$role}@lab.test");

            foreach ($this->ruteWorkbook() as [$metode, $template]) {
                $badan = [];

                foreach (['ada' => $this->wb->id, 'tidak ada' => self::TAK_ADA] as $nama => $id) {
                    // Token lab biasa (`internal`) dan token wildcard lama.
                    foreach ([['internal'], ['*']] as $abilities) {
                        Sanctum::actingAs($user, $abilities);
                        $respons = $this->json($metode, str_replace('{w}', (string) $id, $template), $metode === 'POST' ? ['edit' => []] : []);
                        $this->app['auth']->forgetGuards();

                        $this->assertSame(403, $respons->getStatusCode(), "{$role} {$metode} {$template} workbook {$nama}: harus 403 seragam.");
                        $badan[] = $respons->getContent();
                    }
                }

                $this->assertCount(1, array_unique($badan), "{$role} {$metode} {$template}: badan jawaban berbeda antara workbook ada dan tidak ada.");
            }
        }
    }

    public function test_akun_studio_tidak_berubah_ada_200_tidak_ada_404(): void
    {
        $token = $this->pemilik->createToken('studio', ['studio'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson("/api/studio/workbook/{$this->wb->id}")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/studio/workbook/'.self::TAK_ADA)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/studio/workbook/'.self::TAK_ADA.'/riwayat')->assertNotFound();
    }

    public function test_tamu_tetap_401_untuk_workbook_ada_dan_tidak_ada(): void
    {
        foreach ([$this->wb->id, self::TAK_ADA] as $id) {
            $this->getJson("/api/studio/workbook/{$id}")->assertUnauthorized();
        }
    }

    private function akun(string $role, string $email): User
    {
        return User::factory()->create([
            'organization_id' => $this->lab->id,
            'role' => $role,
            'status' => User::STATUS_AKTIF,
            'email' => $email,
            'password' => Hash::make('sandi-akun-studio-1'),
        ]);
    }
}
