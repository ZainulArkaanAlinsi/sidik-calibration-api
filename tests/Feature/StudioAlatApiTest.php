<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Studio mengelola ALAT (26 Nov 2026): `/api/studio/equipments`.
 * Kontrak: `docs/perintah-frontend-studio-alat.md`.
 *
 * Yang dikunci:
 *  - Pintu: token `studio` + `STUDIO_AKUN` + peran `super_admin`. Admin/teknisi/
 *    viewer yang memegang token Studio sah tetap 403 (seragam untuk ID apa pun).
 *  - Isolasi lab: daftar hanya alat lab sendiri; alat lab lain 404 di detail,
 *    ubah, dan hapus, dan percobaannya tercatat `akses_lintas_lab`.
 *  - Hapus = hapus-lunak: baris tetap ada, jejak `dihapus` tercatat.
 *  - Tidak ada pelonggaran: token Studio tetap 403 `bukan_aplikasi_ini` di rute
 *    lab (termasuk `/api/equipments`), dan gerbang `role:super_admin` tidak
 *    merembes ke rute Studio lain.
 */
class StudioAlatApiTest extends TestCase
{
    use RefreshDatabase;

    private const SANDI = 'sandi-akun-studio-1';

    private const STUDIO = ['X-Klien' => 'sidik-studio', 'X-Versi-Klien' => '1.0.0'];

    private const TAK_ADA = 99999999;

    private Organization $lab;

    private Organization $labLain;

    private User $pemilik;

    private Customer $pt;

    private EquipmentCategory $kategori;

    private Customer $ptLain;

    private EquipmentCategory $kategoriLain;

    /** @var array<int, string> token Studio per id user */
    private array $token = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
        $this->labLain = Organization::factory()->create();

        $this->pemilik = $this->akun($this->lab, User::ROLE_SUPER_ADMIN, 'pemilik@sidik.test');

        $this->pt = Customer::factory()->create(['organization_id' => $this->lab->id]);
        $this->kategori = EquipmentCategory::factory()->create([
            'organization_id' => $this->lab->id, 'kode' => 'panjang', 'nama' => 'Panjang',
        ]);
        $this->ptLain = Customer::factory()->create(['organization_id' => $this->labLain->id]);
        $this->kategoriLain = EquipmentCategory::factory()->create([
            'organization_id' => $this->labLain->id, 'kode' => 'massa-lain', 'nama' => 'Massa Lain',
        ]);
    }

    private function akun(Organization $lab, string $peran, string $email): User
    {
        return User::factory()->create([
            'organization_id' => $lab->id,
            'role' => $peran,
            'status' => User::STATUS_AKTIF,
            'email' => $email,
            'password' => Hash::make(self::SANDI),
        ]);
    }

    /** Request dengan token `studio` sungguhan milik `$pengguna` (default: pemilik). */
    private function studio(string $metode, string $uri, array $data = [], ?User $pengguna = null): TestResponse
    {
        $pengguna ??= $this->pemilik;

        config(['studio.akun' => strtolower((string) $pengguna->email)]);
        $this->token[$pengguna->id] ??= $pengguna->createToken('studio', ['studio'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token[$pengguna->id])->json($metode, $uri, $data, self::STUDIO);
    }

    private function alat(?Organization $lab = null, array $atribut = []): Equipment
    {
        $lab ??= $this->lab;
        $punyaLab = $lab->is($this->lab);

        return Equipment::factory()->create([
            'organization_id' => $lab->id,
            'customer_id' => ($punyaLab ? $this->pt : $this->ptLain)->id,
            'equipment_category_id' => ($punyaLab ? $this->kategori : $this->kategoriLain)->id,
            ...$atribut,
        ]);
    }

    /** @return array<string, mixed> */
    private function badanBaru(array $timpa = []): array
    {
        return [
            'nama_alat' => 'Feeler Gauge',
            'serial_number' => 'SN-STUDIO-001',
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pt->id,
            'merk' => 'Merk Uji',
            'satuan' => 'mm',
            ...$timpa,
        ];
    }

    // ---------------------------------------------------------------- CRUD

    public function test_daftar_hanya_memuat_alat_lab_sendiri_dan_bisa_dicari(): void
    {
        $a = $this->alat(null, ['serial_number' => 'AAA-111']);
        $b = $this->alat(null, ['serial_number' => 'BBB-222']);
        $asing = $this->alat($this->labLain, ['serial_number' => 'ZZZ-999']);

        $respons = $this->studio('GET', '/api/studio/equipments')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'nama_alat', 'serial_number', 'kategori', 'pelanggan' => ['id', 'nama'], 'status']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 2);

        $id = collect($respons->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $id);
        $this->assertNotContains($asing->id, $id);

        // Penyaring/pencarian lab dipakai ulang apa adanya.
        $this->studio('GET', '/api/studio/equipments?search=AAA-111')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $a->id);

        // Serial lab lain tidak ketemu walau persis.
        $this->studio('GET', '/api/studio/equipments?search=ZZZ-999')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_detail_alat_lab_sendiri(): void
    {
        $alat = $this->alat();

        $this->studio('GET', "/api/studio/equipments/{$alat->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $alat->id)
            ->assertJsonPath('data.serial_number', $alat->serial_number)
            ->assertJsonPath('data.kategori', 'panjang');
    }

    public function test_tambah_alat_membuat_baris_di_lab_sendiri_dengan_jejak_audit(): void
    {
        // `organization_id` dari body tidak pernah dipakai (bukan bagian `validated()`).
        $respons = $this->studio('POST', '/api/studio/equipments', $this->badanBaru([
            'organization_id' => $this->labLain->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.serial_number', 'SN-STUDIO-001')
            ->assertJsonPath('data.kategori', 'panjang')
            ->assertJsonPath('data.pelanggan.id', $this->pt->id);

        $id = $respons->json('data.id');
        $baris = Equipment::query()->findOrFail($id);
        $this->assertSame($this->lab->id, $baris->organization_id);
        $this->assertSame($this->kategori->id, $baris->equipment_category_id);
        $this->assertSame($this->pt->id, $baris->customer_id);

        $jejak = AuditLog::query()
            ->where('entity_type', 'equipments')->where('entity_id', $id)
            ->where('action', AuditLog::ACTION_DIBIKIN)
            ->sole();
        $this->assertSame($this->pemilik->id, $jejak->changed_by);
        $this->assertSame($this->lab->id, $jejak->organization_id);
    }

    public function test_tambah_alat_memakai_aturan_validasi_lab(): void
    {
        $this->alat(null, ['serial_number' => 'DUP-1']);

        // Wajib isi.
        $this->studio('POST', '/api/studio/equipments', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nama_alat', 'serial_number', 'kategori', 'pelanggan_id']);

        // Serial kembar di lab yang sama.
        $this->studio('POST', '/api/studio/equipments', $this->badanBaru(['serial_number' => 'DUP-1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('serial_number');

        // `overdue` tidak boleh dikirim.
        $this->studio('POST', '/api/studio/equipments', $this->badanBaru(['status' => 'overdue']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(1, Equipment::query()->count());
    }

    public function test_tambah_alat_menolak_pelanggan_dan_kategori_lab_lain(): void
    {
        $this->studio('POST', '/api/studio/equipments', $this->badanBaru(['pelanggan_id' => $this->ptLain->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pelanggan_id');

        $this->studio('POST', '/api/studio/equipments', $this->badanBaru(['kategori' => 'massa-lain']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('kategori');

        $this->assertSame(0, Equipment::query()->count());
    }

    public function test_ubah_alat_dengan_put_dan_patch_mencatat_nilai_sebelum_dan_sesudah(): void
    {
        $alat = $this->alat(null, ['nama_alat' => 'Feeler Gauge', 'merk' => 'Lama', 'lokasi' => 'Rak A']);

        $this->studio('PUT', "/api/studio/equipments/{$alat->id}", ['merk' => 'Baru'])
            ->assertOk()
            ->assertJsonPath('data.merk', 'Baru');

        // PATCH parsial: field yang tidak dikirim tidak ikut berubah.
        $this->studio('PATCH', "/api/studio/equipments/{$alat->id}", ['lokasi' => 'Rak B'])
            ->assertOk()
            ->assertJsonPath('data.lokasi', 'Rak B')
            ->assertJsonPath('data.merk', 'Baru')
            ->assertJsonPath('data.nama_alat', 'Feeler Gauge');

        $baris = $alat->fresh();
        $this->assertSame('Baru', $baris->merk);
        $this->assertSame('Rak B', $baris->lokasi);
        $this->assertSame($this->lab->id, $baris->organization_id);

        $jejak = AuditLog::query()
            ->where('entity_type', 'equipments')->where('entity_id', $alat->id)
            ->where('action', AuditLog::ACTION_DIUBAH)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $jejak);
        $this->assertSame('Lama', $jejak[0]->old_data['merk']);
        $this->assertSame('Baru', $jejak[0]->new_data['merk']);
        $this->assertSame('Rak A', $jejak[1]->old_data['lokasi']);
        $this->assertSame('Rak B', $jejak[1]->new_data['lokasi']);
        $this->assertSame($this->pemilik->id, $jejak[1]->changed_by);
    }

    public function test_ubah_alat_menolak_pelanggan_lab_lain(): void
    {
        $alat = $this->alat();

        $this->studio('PATCH', "/api/studio/equipments/{$alat->id}", ['pelanggan_id' => $this->ptLain->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pelanggan_id');

        $this->assertSame($this->pt->id, $alat->fresh()->customer_id);
    }

    public function test_hapus_alat_adalah_hapus_lunak_dan_tercatat(): void
    {
        $alat = $this->alat();
        $lain = $this->alat();
        $jumlahBaris = Equipment::withTrashed()->count();

        $this->studio('DELETE', "/api/studio/equipments/{$alat->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Alat dihapus.');

        // Baris TIDAK hilang: hanya `deleted_at` yang terisi.
        $this->assertSame($jumlahBaris, Equipment::withTrashed()->count());
        $this->assertSoftDeleted('equipments', ['id' => $alat->id]);
        $this->assertNull($lain->fresh()->deleted_at);
        $this->assertDatabaseHas('equipments', ['id' => $alat->id, 'serial_number' => $alat->serial_number]);

        $jejak = AuditLog::query()
            ->where('entity_type', 'equipments')->where('entity_id', $alat->id)
            ->where('action', AuditLog::ACTION_DIHAPUS)
            ->sole();
        $this->assertSame($this->pemilik->id, $jejak->changed_by);

        // Setelah hapus: hilang dari daftar, detail/ubah/hapus-ulang 404.
        $this->studio('GET', '/api/studio/equipments')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $lain->id);
        $this->studio('GET', "/api/studio/equipments/{$alat->id}")->assertNotFound();
        $this->studio('PATCH', "/api/studio/equipments/{$alat->id}", ['merk' => 'X'])->assertNotFound();
        $this->studio('DELETE', "/api/studio/equipments/{$alat->id}")->assertNotFound();
    }

    // -------------------------------------------------------- isolasi lab

    public function test_alat_lab_lain_dijawab_404_di_semua_aksi_dan_tidak_berubah(): void
    {
        $asing = $this->alat($this->labLain, ['merk' => 'Asli', 'serial_number' => 'ASING-1']);
        $url = "/api/studio/equipments/{$asing->id}";

        $this->studio('GET', $url)->assertNotFound();
        $this->studio('PUT', $url, ['merk' => 'Diubah'])->assertNotFound();
        $this->studio('PATCH', $url, ['merk' => 'Diubah'])->assertNotFound();
        $this->studio('DELETE', $url)->assertNotFound();

        $baris = $asing->fresh();
        $this->assertSame('Asli', $baris->merk);
        $this->assertSame($this->labLain->id, $baris->organization_id);
        $this->assertNull($baris->deleted_at);

        // Percobaan tercatat di lab PEMANGGIL (bukan lab pemilik data).
        $this->assertSame(4, AuditLog::query()
            ->where('organization_id', $this->lab->id)
            ->where('entity_type', 'equipments')->where('entity_id', $asing->id)
            ->where('action', AuditLog::ACTION_AKSES_LINTAS_LAB)
            ->count());
        $this->assertSame(0, AuditLog::query()
            ->where('organization_id', $this->labLain->id)
            ->where('action', AuditLog::ACTION_AKSES_LINTAS_LAB)
            ->count());

        // 404 yang sama dengan ID yang memang tidak ada.
        $this->studio('GET', '/api/studio/equipments/'.self::TAK_ADA)->assertNotFound();
    }

    // ------------------------------------------------------------ peran

    public function test_peran_selain_super_admin_dijawab_403_seragam_walau_memegang_token_studio_sah(): void
    {
        $milik = $this->alat(null, ['merk' => 'Asli']);
        $asing = $this->alat($this->labLain, ['merk' => 'Asli']);

        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER] as $peran) {
            $pengguna = $this->akun($this->lab, $peran, "{$peran}@sidik.test");

            foreach ([$milik->id, $asing->id, self::TAK_ADA] as $id) {
                $this->studio('GET', "/api/studio/equipments/{$id}", [], $pengguna)->assertForbidden();
                $this->studio('PUT', "/api/studio/equipments/{$id}", ['merk' => 'X'], $pengguna)->assertForbidden();
                $this->studio('PATCH', "/api/studio/equipments/{$id}", ['merk' => 'X'], $pengguna)->assertForbidden();
                $this->studio('DELETE', "/api/studio/equipments/{$id}", [], $pengguna)->assertForbidden();
            }

            $this->studio('GET', '/api/studio/equipments', [], $pengguna)->assertForbidden();
            $this->studio('POST', '/api/studio/equipments', $this->badanBaru(), $pengguna)->assertForbidden();
        }

        // Tidak ada yang berubah akibat percobaan-percobaan itu.
        $this->assertSame('Asli', $milik->fresh()->merk);
        $this->assertSame('Asli', $asing->fresh()->merk);
        $this->assertNull($milik->fresh()->deleted_at);
        $this->assertSame(2, Equipment::withTrashed()->count());
    }

    public function test_gerbang_super_admin_tidak_merembes_ke_rute_studio_lain(): void
    {
        $admin = $this->akun($this->lab, User::ROLE_ADMIN, 'admin@sidik.test');

        // Akun Studio berperan admin tetap boleh memakai rute Studio lain...
        $this->studio('GET', '/api/studio/me', [], $admin)->assertOk();
        // ...tetapi tidak alat.
        $this->studio('GET', '/api/studio/equipments', [], $admin)->assertForbidden();
    }

    public function test_super_admin_yang_bukan_akun_studio_dan_token_lab_ditolak(): void
    {
        $alat = $this->alat();
        $lain = $this->akun($this->lab, User::ROLE_SUPER_ADMIN, 'lain@sidik.test');

        // Super admin lain yang BUKAN `STUDIO_AKUN`.
        config(['studio.akun' => 'pemilik@sidik.test']);
        $tokenLain = $lain->createToken('studio', ['studio'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($tokenLain)->getJson('/api/studio/equipments', self::STUDIO)
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_akun_studio');

        // Sesi/token lab milik pemilik sendiri (bukan token `studio`).
        $this->app['auth']->forgetGuards();
        $tokenLab = $this->pemilik->createToken('lab', ['internal'])->plainTextToken;
        $this->withToken($tokenLab)->deleteJson("/api/studio/equipments/{$alat->id}")
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_token_studio');

        $this->assertNull($alat->fresh()->deleted_at);
    }

    public function test_tanpa_token_dijawab_401(): void
    {
        $alat = $this->alat();

        $this->getJson('/api/studio/equipments')->assertUnauthorized();
        $this->getJson("/api/studio/equipments/{$alat->id}")->assertUnauthorized();
        $this->postJson('/api/studio/equipments', $this->badanBaru())->assertUnauthorized();
        $this->patchJson("/api/studio/equipments/{$alat->id}", ['merk' => 'X'])->assertUnauthorized();
        $this->deleteJson("/api/studio/equipments/{$alat->id}")->assertUnauthorized();
    }

    // ------------------------------------------------ tidak ada pelonggaran

    public function test_token_studio_tetap_403_di_rute_lab_termasuk_rute_alat_lab(): void
    {
        $alat = $this->alat();

        $cobaan = [
            ['GET', '/api/equipments'],
            ['GET', "/api/equipments/{$alat->id}"],
            ['POST', '/api/equipments'],
            ['PUT', "/api/equipments/{$alat->id}"],
            ['DELETE', "/api/equipments/{$alat->id}"],
            ['GET', '/api/customers'],
            ['GET', '/api/categories'],
            ['GET', '/api/calibrations'],
            ['GET', '/api/me'],
            ['GET', '/api/users'],
        ];

        foreach ($cobaan as [$metode, $uri]) {
            $this->studio($metode, $uri, $metode === 'GET' ? [] : $this->badanBaru())
                ->assertForbidden()
                ->assertJsonPath('kode', 'bukan_aplikasi_ini');
        }

        $this->assertNull($alat->fresh()->deleted_at);
        $this->assertSame(1, Equipment::withTrashed()->count());
    }

    public function test_semua_rute_alat_studio_bergerbang_studio_super_admin_dan_throttle(): void
    {
        $ditemukan = [];

        foreach (Route::getRoutes()->getRoutes() as $rute) {
            if (! str_starts_with($rute->uri(), 'api/studio/equipments')) {
                continue;
            }

            $mw = $rute->gatherMiddleware();
            $this->assertContains('studio', $mw, $rute->uri().': gerbang studio hilang');
            $this->assertContains('role:super_admin', $mw, $rute->uri().': role:super_admin hilang');
            $this->assertNotEmpty(
                array_filter($mw, fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:studio-')),
                $rute->uri().': throttle Studio hilang',
            );
            // Kebalikan dari rute lab: tidak boleh ikut gerbang `aplikasi:internal`.
            $this->assertNotContains('aplikasi:internal', $mw);

            foreach ($rute->methods() as $metode) {
                if ($metode !== 'HEAD') {
                    $ditemukan[] = $metode.' '.$rute->uri();
                }
            }
        }

        sort($ditemukan);
        $this->assertSame([
            'DELETE api/studio/equipments/{equipment}',
            'GET api/studio/equipments',
            'GET api/studio/equipments/{equipment}',
            'PATCH api/studio/equipments/{equipment}',
            'POST api/studio/equipments',
            'PUT api/studio/equipments/{equipment}',
        ], $ditemukan);

        // Rute lab tidak disentuh: masih tertutup untuk token `studio`.
        $lab = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r): bool => $r->uri() === 'api/equipments' && in_array('GET', $r->methods(), true));
        $this->assertNotNull($lab);
        $this->assertContains('aplikasi:internal', $lab->gatherMiddleware());
        $this->assertNotContains('studio', $lab->gatherMiddleware());
    }

    public function test_tulis_alat_dibatasi_laju_per_menit(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->studio('POST', '/api/studio/equipments', [])->assertStatus(422);
        }

        $this->studio('POST', '/api/studio/equipments', [])->assertStatus(429);
    }
}
