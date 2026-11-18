<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\MatriksIzin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pengelolaan akun internal oleh SUPER ADMIN di aplikasi lab (18 Nov 2026,
 * menggantikan layar Pengguna di panel web):
 * `POST /users` (tambah admin), `POST /users/{u}/nonaktifkan|aktifkan`, dan
 * saringan `role`/`search` di `GET /users`.
 * Kontrak: `docs/perintah-frontend-super-admin.md` §4.
 *
 * Yang dijaga dan kenapa:
 *
 * - **Izin** — tiga rute baru super-admin-saja. Admin yang bisa menambah admin
 *   lain melonggarkan pemisahan wewenang tanpa satu error pun.
 * - **`super_admin` tidak pernah lahir lewat API** dan tidak pernah jadi sasaran
 *   (404), termasuk dirinya sendiri — jadi tidak ada cara mengunci diri.
 * - **Isolasi** — lab lain dijawab 404 walau `alasan` kosong.
 * - **Token dicabut** waktu dinonaktifkan; **sandi & role tidak berubah** waktu
 *   diaktifkan kembali.
 * - **Jejak audit** — pelaku asli, peran, alasan, status lama → baru.
 */
class KelolaAkunInternalSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
    }

    private function akun(string $role = User::ROLE_TEKNISI, array $tambahan = []): User
    {
        return User::factory()->create([
            'organization_id' => $this->lab->id,
            'role' => $role,
            'status' => User::STATUS_AKTIF,
            ...$tambahan,
        ]);
    }

    private function superAdmin(): User
    {
        return $this->akun(User::ROLE_SUPER_ADMIN);
    }

    /** @return array<string, mixed> */
    private function badanAdminBaru(array $timpa = []): array
    {
        return [
            'role' => 'admin',
            'nama' => 'Rina Master Data',
            'email' => 'rina@lab.test',
            'employee_id' => 'SDK-0200',
            'department' => 'Master Data',
            'password' => 'sandiawal-8',
            ...$timpa,
        ];
    }

    // ===================================================== POST /users (tambah admin)

    public function test_super_admin_menambah_akun_admin_aktif_di_lab_sendiri(): void
    {
        $superAdmin = $this->superAdmin();
        $labLain = Organization::factory()->create();

        $balasan = $this->actingAs($superAdmin)
            // `organization_id` dari klien TIDAK boleh dibaca.
            ->postJson('/api/users', $this->badanAdminBaru(['organization_id' => $labLain->id]))
            ->assertCreated()
            ->assertJsonPath('message', 'Akun admin dibuat.')
            ->assertJsonPath('data.nama', 'Rina Master Data')
            ->assertJsonPath('data.email', 'rina@lab.test')
            ->assertJsonPath('data.employee_id', 'SDK-0200')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.department', 'Master Data')
            ->assertJsonPath('data.organization_id', $this->lab->id);

        // Sandi tidak pernah ikut di balasan, dalam bentuk apa pun.
        $this->assertStringNotContainsString('sandiawal-8', (string) $balasan->getContent());
        $this->assertArrayNotHasKey('password', $balasan->json('data'));

        $baru = User::query()->findOrFail($balasan->json('data.id'));
        $this->assertSame($this->lab->id, $baru->organization_id);
        $this->assertSame(User::ROLE_ADMIN, $baru->role);
        $this->assertTrue(Hash::check('sandiawal-8', $baru->password));

        // Jejak `dibikin`: pelaku = super admin, sandi disensor.
        $jejak = AuditLog::query()->where('entity_type', 'users')->where('entity_id', $baru->id)->where('action', AuditLog::ACTION_DIBIKIN)->sole();
        $this->assertSame($superAdmin->id, (int) $jejak->changed_by);
        $this->assertSame('[disensor]', $jejak->new_data['password']);

        // Akun baru bisa masuk dengan sandi awalnya.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['identifier' => 'SDK-0200', 'password' => 'sandiawal-8'])
            ->assertOk();
    }

    public function test_selain_super_admin_ditolak_menambah_akun(): void
    {
        $jumlahAwal = User::query()->count();

        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER] as $peran) {
            $this->actingAs($this->akun($peran))
                ->postJson('/api/users', $this->badanAdminBaru(['email' => "{$peran}@lab.test", 'employee_id' => "X-{$peran}"]))
                ->assertForbidden();
        }

        $pelanggan = $this->akun(User::ROLE_PELANGGAN, ['employee_id' => null]);
        $this->actingAs($pelanggan)->postJson('/api/users', $this->badanAdminBaru())->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/users', $this->badanAdminBaru())->assertUnauthorized();

        // Hanya empat akun penguji (admin, teknisi, viewer, pelanggan) yang ada;
        // tidak satu pun akun hasil POST.
        $this->assertSame($jumlahAwal + 4, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'rina@lab.test']);
    }

    public function test_peran_selain_admin_tidak_pernah_bisa_dicetak_dari_sini(): void
    {
        $superAdmin = $this->superAdmin();

        foreach (['super_admin', 'teknisi', 'viewer', 'pelanggan', 'ADMIN', '', 'root'] as $peran) {
            $this->actingAs($superAdmin)
                ->postJson('/api/users', $this->badanAdminBaru(['role' => $peran, 'email' => "x{$peran}@lab.test", 'employee_id' => "E-{$peran}"]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('role');
        }

        // `role` hilang sama sekali juga ditolak — tidak ada bawaan diam-diam.
        $badan = $this->badanAdminBaru();
        unset($badan['role']);
        $this->actingAs($superAdmin)->postJson('/api/users', $badan)->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertSame(1, User::query()->count(), 'Hanya akun super admin penguji yang boleh ada.');
    }

    public function test_validasi_tambah_akun_admin(): void
    {
        $superAdmin = $this->superAdmin();
        $this->akun(User::ROLE_TEKNISI, ['email' => 'dipakai@lab.test', 'employee_id' => 'SDK-DIPAKAI']);

        $this->actingAs($superAdmin)->postJson('/api/users', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role', 'nama', 'email', 'employee_id', 'password']);

        $this->actingAs($superAdmin)->postJson('/api/users', $this->badanAdminBaru(['email' => 'dipakai@lab.test']))
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Email ini sudah terdaftar.');

        $this->actingAs($superAdmin)->postJson('/api/users', $this->badanAdminBaru(['employee_id' => 'SDK-DIPAKAI']))
            ->assertStatus(422)
            ->assertJsonPath('errors.employee_id.0', 'ID pegawai ini sudah terdaftar.');

        $this->actingAs($superAdmin)->postJson('/api/users', $this->badanAdminBaru(['password' => 'pendek']))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Password minimal 8 karakter.');

        $this->actingAs($superAdmin)->postJson('/api/users', $this->badanAdminBaru(['email' => 'bukan-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['employee_id' => 'SDK-0200']);
    }

    public function test_tambah_akun_admin_dibatasi_throttle_per_akun(): void
    {
        $superAdmin = $this->superAdmin();

        // 10/menit per akun; badan kosong tetap menghitung satu ketukan.
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($superAdmin)->postJson('/api/users', [])->assertStatus(422);
        }

        $this->actingAs($superAdmin)->postJson('/api/users', [])->assertStatus(429);
    }

    // ================================================== POST /users/{u}/nonaktifkan

    public function test_super_admin_menonaktifkan_teknisi_token_dicabut_dan_jejaknya_lengkap(): void
    {
        $superAdmin = $this->superAdmin();
        $teknisi = $this->akun(User::ROLE_TEKNISI);
        $tokenTeknisi = $teknisi->createToken('hp', ['internal'])->plainTextToken;
        $teknisi->createToken('tablet', ['internal']);

        $this->actingAs($superAdmin)
            ->postJson("/api/users/{$teknisi->id}/nonaktifkan", ['alasan' => '  Resign per 15 Nov 2026  '])
            ->assertOk()
            ->assertJsonPath('message', 'Akun dinonaktifkan. Seluruh sesinya dicabut.')
            ->assertJsonPath('data.id', $teknisi->id)
            ->assertJsonPath('data.status', 'nonaktif')
            ->assertJsonPath('data.role', 'teknisi');

        $this->assertSame(User::STATUS_NONAKTIF, $teknisi->fresh()->status);
        $this->assertSame(0, $teknisi->tokens()->count());

        // Token lamanya benar-benar mati (bukan cuma baris status).
        $this->app['auth']->forgetGuards();
        $this->withToken($tokenTeknisi)->getJson('/api/me')->assertUnauthorized();

        // Baris `diubah` otomatis + baris beralasan, dua-duanya atas nama super admin.
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'users', 'entity_id' => $teknisi->id,
            'action' => AuditLog::ACTION_DIUBAH, 'changed_by' => $superAdmin->id,
        ]);

        $jejak = AuditLog::query()->where('action', AuditLog::ACTION_AKUN_DINONAKTIFKAN)->sole();
        $this->assertSame($this->lab->id, $jejak->organization_id);
        $this->assertSame('users', $jejak->entity_type);
        $this->assertSame($teknisi->id, (int) $jejak->entity_id);
        $this->assertSame($superAdmin->id, (int) $jejak->changed_by);
        $this->assertSame(['status' => 'aktif'], $jejak->old_data);
        $this->assertSame('nonaktif', $jejak->new_data['status']);
        $this->assertSame('teknisi', $jejak->new_data['role_akun']);
        $this->assertSame('super_admin', $jejak->new_data['peran_pelaku']);
        $this->assertSame('Resign per 15 Nov 2026', $jejak->new_data['alasan']);
        $this->assertStringContainsString('Resign per 15 Nov 2026', (string) $jejak->note);
    }

    public function test_super_admin_boleh_menonaktifkan_admin_dan_viewer_juga(): void
    {
        $superAdmin = $this->superAdmin();

        foreach ([User::ROLE_ADMIN, User::ROLE_VIEWER] as $peran) {
            $target = $this->akun($peran);

            $this->actingAs($superAdmin)
                ->postJson("/api/users/{$target->id}/nonaktifkan", ['alasan' => 'Rotasi tugas'])
                ->assertOk()
                ->assertJsonPath('data.status', 'nonaktif');
        }
    }

    public function test_selain_super_admin_ditolak_menonaktifkan_dan_mengaktifkan(): void
    {
        $target = $this->akun(User::ROLE_TEKNISI);
        $nonaktif = $this->akun(User::ROLE_TEKNISI, ['status' => User::STATUS_NONAKTIF]);

        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER] as $peran) {
            $pemanggil = $this->akun($peran);

            $this->actingAs($pemanggil)
                ->postJson("/api/users/{$target->id}/nonaktifkan", ['alasan' => 'Tidak berwenang'])
                ->assertForbidden();
            $this->actingAs($pemanggil)
                ->postJson("/api/users/{$nonaktif->id}/aktifkan", ['alasan' => 'Tidak berwenang'])
                ->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/users/{$target->id}/nonaktifkan", ['alasan' => 'Tamu'])->assertUnauthorized();

        $this->assertSame(User::STATUS_AKTIF, $target->fresh()->status);
        $this->assertSame(User::STATUS_NONAKTIF, $nonaktif->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_AKUN_DINONAKTIFKAN]);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_AKUN_DIAKTIFKAN]);
    }

    public function test_super_admin_dan_pelanggan_tidak_pernah_jadi_sasaran_termasuk_diri_sendiri(): void
    {
        $superAdmin = $this->superAdmin();
        $superLain = $this->superAdmin();
        $pelanggan = $this->akun(User::ROLE_PELANGGAN, ['employee_id' => null]);

        foreach ([$superAdmin, $superLain, $pelanggan] as $sasaran) {
            $this->actingAs($superAdmin)
                ->postJson("/api/users/{$sasaran->id}/nonaktifkan", ['alasan' => 'Mencoba mengunci'])
                ->assertNotFound();
        }

        $this->assertSame(User::STATUS_AKTIF, $superAdmin->fresh()->status);
        $this->assertSame(User::STATUS_AKTIF, $superLain->fresh()->status);
        $this->assertSame(User::STATUS_AKTIF, $pelanggan->fresh()->status);

        // Mengaktifkan pun sama: akun nonaktif berperan super_admin/pelanggan tak tersentuh.
        $superMati = $this->akun(User::ROLE_SUPER_ADMIN, ['status' => User::STATUS_NONAKTIF]);
        $this->actingAs($superAdmin)->postJson("/api/users/{$superMati->id}/aktifkan", [])->assertNotFound();
        $this->assertSame(User::STATUS_NONAKTIF, $superMati->fresh()->status);
    }

    public function test_akun_lab_lain_dijawab_404_walau_alasan_kosong(): void
    {
        $labLain = Organization::factory()->create();
        $asing = User::factory()->create([
            'organization_id' => $labLain->id, 'role' => User::ROLE_TEKNISI, 'status' => User::STATUS_AKTIF,
        ]);
        $superAdmin = $this->superAdmin();

        // Kalau validasi jalan sebelum penjaga lab, badan kosong = 422 dan id yang
        // tidak ada = 404: bedanya sudah membuktikan akun lab lain itu ada.
        $this->actingAs($superAdmin)->postJson("/api/users/{$asing->id}/nonaktifkan", [])->assertNotFound();
        $this->actingAs($superAdmin)->postJson("/api/users/{$asing->id}/nonaktifkan", ['alasan' => 'Menyusup'])->assertNotFound();
        $this->actingAs($superAdmin)->postJson("/api/users/{$asing->id}/aktifkan", [])->assertNotFound();
        $this->actingAs($superAdmin)->postJson('/api/users/999999/nonaktifkan', [])->assertNotFound();

        $this->assertSame(User::STATUS_AKTIF, $asing->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_AKUN_DINONAKTIFKAN]);
    }

    public function test_alasan_nonaktifkan_wajib_3_sampai_500_karakter(): void
    {
        $superAdmin = $this->superAdmin();
        $target = $this->akun();

        foreach ([[], ['alasan' => ''], ['alasan' => '   '], ['alasan' => 'ab'], ['alasan' => str_repeat('x', 501)]] as $badan) {
            $this->actingAs($superAdmin)
                ->postJson("/api/users/{$target->id}/nonaktifkan", $badan)
                ->assertStatus(422)
                ->assertJsonValidationErrors('alasan');
        }

        $this->assertSame(User::STATUS_AKTIF, $target->fresh()->status);
    }

    public function test_menonaktifkan_akun_yang_sudah_nonaktif_dijawab_sudah_nonaktif(): void
    {
        $target = $this->akun(User::ROLE_TEKNISI, ['status' => User::STATUS_NONAKTIF]);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/users/{$target->id}/nonaktifkan", ['alasan' => 'Sekali lagi'])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'sudah_nonaktif');

        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_AKUN_DINONAKTIFKAN]);
    }

    public function test_pendaftar_pending_boleh_ditutup_lewat_nonaktifkan(): void
    {
        $pending = $this->akun(User::ROLE_TEKNISI, ['status' => User::STATUS_PENDING]);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/users/{$pending->id}/nonaktifkan", ['alasan' => 'Bukan pegawai kami'])
            ->assertOk()
            ->assertJsonPath('data.status', 'nonaktif');
    }

    // ===================================================== POST /users/{u}/aktifkan

    public function test_super_admin_mengaktifkan_kembali_tanpa_menyentuh_sandi_dan_role(): void
    {
        $superAdmin = $this->superAdmin();
        $teknisi = $this->akun(User::ROLE_TEKNISI, [
            'status' => User::STATUS_NONAKTIF,
            'employee_id' => 'SDK-0777',
            'password' => Hash::make('sandi-lama-ku'),
        ]);
        $hashSebelum = $teknisi->password;

        $this->actingAs($superAdmin)
            ->postJson("/api/users/{$teknisi->id}/aktifkan", ['alasan' => 'Kembali bertugas'])
            ->assertOk()
            ->assertJsonPath('message', 'Akun diaktifkan kembali.')
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.role', 'teknisi');

        $teknisi->refresh();
        $this->assertSame(User::STATUS_AKTIF, $teknisi->status);
        $this->assertSame(User::ROLE_TEKNISI, $teknisi->role);
        $this->assertSame($hashSebelum, $teknisi->password, 'Sandi tidak boleh tersentuh.');

        $jejak = AuditLog::query()->where('action', AuditLog::ACTION_AKUN_DIAKTIFKAN)->sole();
        $this->assertSame($superAdmin->id, (int) $jejak->changed_by);
        $this->assertSame(['status' => 'nonaktif'], $jejak->old_data);
        $this->assertSame('aktif', $jejak->new_data['status']);
        $this->assertSame('super_admin', $jejak->new_data['peran_pelaku']);
        $this->assertSame('Kembali bertugas', $jejak->new_data['alasan']);

        // Orangnya masuk lagi dengan sandi lamanya.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['identifier' => 'SDK-0777', 'password' => 'sandi-lama-ku'])->assertOk();
    }

    public function test_alasan_aktifkan_boleh_kosong_tapi_tetap_dibatasi_500(): void
    {
        $superAdmin = $this->superAdmin();
        $a = $this->akun(User::ROLE_TEKNISI, ['status' => User::STATUS_NONAKTIF]);
        $b = $this->akun(User::ROLE_TEKNISI, ['status' => User::STATUS_NONAKTIF]);

        $this->actingAs($superAdmin)
            ->postJson("/api/users/{$a->id}/aktifkan", ['alasan' => str_repeat('x', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('alasan');
        $this->assertSame(User::STATUS_NONAKTIF, $a->fresh()->status);

        $this->actingAs($superAdmin)->postJson("/api/users/{$b->id}/aktifkan")->assertOk();

        $jejak = AuditLog::query()->where('action', AuditLog::ACTION_AKUN_DIAKTIFKAN)->where('entity_id', $b->id)->sole();
        $this->assertNull($jejak->new_data['alasan']);
    }

    public function test_aktifkan_akun_yang_sudah_aktif_dan_akun_pending_ditolak_dengan_kode(): void
    {
        $superAdmin = $this->superAdmin();
        $aktif = $this->akun();
        $pending = $this->akun(User::ROLE_TEKNISI, ['status' => User::STATUS_PENDING]);

        $this->actingAs($superAdmin)->postJson("/api/users/{$aktif->id}/aktifkan", [])
            ->assertStatus(422)->assertJsonPath('kode', 'sudah_aktif');

        $this->actingAs($superAdmin)->postJson("/api/users/{$pending->id}/aktifkan", [])
            ->assertStatus(422)->assertJsonPath('kode', 'perlu_persetujuan');

        $this->assertSame(User::STATUS_PENDING, $pending->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_AKUN_DIAKTIFKAN]);
    }

    public function test_aktifkan_dan_nonaktifkan_dibatasi_throttle_per_akun(): void
    {
        $superAdmin = $this->superAdmin();
        $target = $this->akun();

        // 30/menit per akun, SATU ember untuk dua rute (izin dan sifatnya sama).
        for ($i = 0; $i < 15; $i++) {
            $this->actingAs($superAdmin)->postJson("/api/users/{$target->id}/nonaktifkan", [])->assertStatus(422);
            $this->actingAs($superAdmin)->postJson("/api/users/{$target->id}/aktifkan", ['alasan' => str_repeat('x', 501)])->assertStatus(422);
        }

        $this->actingAs($superAdmin)->postJson("/api/users/{$target->id}/nonaktifkan", [])->assertStatus(429);
    }

    // ======================================================== GET /users (saringan)

    public function test_daftar_pengguna_bisa_disaring_per_role_dan_dicari(): void
    {
        $admin = $this->akun(User::ROLE_ADMIN, ['name' => 'Rina Master', 'email' => 'rina@lab.test', 'employee_id' => 'SDK-1']);
        $teknisiA = $this->akun(User::ROLE_TEKNISI, ['name' => 'Dedi Teknisi', 'email' => 'dedi@lab.test', 'employee_id' => 'SDK-2']);
        $teknisiB = $this->akun(User::ROLE_TEKNISI, ['name' => 'Eko Rinaldi', 'email' => 'eko@lab.test', 'employee_id' => 'SDK-3', 'status' => User::STATUS_NONAKTIF]);
        $viewer = $this->akun(User::ROLE_VIEWER, ['name' => 'Vina Viewer', 'email' => 'vina@lab.test', 'employee_id' => 'SDK-4']);
        $superAdmin = $this->akun(User::ROLE_SUPER_ADMIN, ['name' => 'Super Rina', 'email' => 'super@lab.test', 'employee_id' => 'SDK-5']);
        $pelanggan = $this->akun(User::ROLE_PELANGGAN, ['name' => 'Rina Pelanggan', 'email' => 'pelanggan@lab.test', 'employee_id' => null]);
        $labLain = Organization::factory()->create();
        User::factory()->create(['organization_id' => $labLain->id, 'role' => User::ROLE_TEKNISI, 'name' => 'Rina Lab Lain', 'email' => 'asing@lab.test']);

        foreach ([$superAdmin, $admin] as $pemanggil) {
            $ids = fn (array $query) => collect($this->actingAs($pemanggil)->getJson('/api/users?'.http_build_query($query))->assertOk()->json('data'))
                ->pluck('id')->sort()->values()->all();

            // Tanpa saringan: tiga role internal di lab ini; super admin, pelanggan, lab lain TIDAK ada.
            $semua = $ids([]);
            foreach ([$admin, $teknisiA, $teknisiB, $viewer] as $akun) {
                $this->assertContains($akun->id, $semua);
            }
            $this->assertNotContains($superAdmin->id, $semua);
            $this->assertNotContains($pelanggan->id, $semua);

            $this->assertSame([$teknisiA->id, $teknisiB->id], $ids(['role' => 'teknisi']));
            $this->assertSame([$admin->id], $ids(['role' => 'admin']));
            $this->assertSame([$viewer->id], $ids(['role' => 'viewer']));
            $this->assertSame([$teknisiB->id], $ids(['role' => 'teknisi', 'status' => 'nonaktif']));

            // Cari: nama, email, atau ID pegawai; huruf besar-kecil tidak dibedakan.
            $this->assertSame([$admin->id, $teknisiB->id], $ids(['search' => 'rina']));
            $this->assertSame([$teknisiA->id], $ids(['search' => 'dedi@']));
            $this->assertSame([$viewer->id], $ids(['search' => 'SDK-4']));
            // Gabungan: kurung pencarian tidak boleh menyeret role/status lain.
            $this->assertSame([$teknisiB->id], $ids(['search' => 'rina', 'role' => 'teknisi']));
            $this->assertSame([], $ids(['search' => 'rina', 'role' => 'viewer']));
        }
    }

    public function test_saringan_role_menolak_nilai_yang_tidak_pernah_tampil(): void
    {
        $superAdmin = $this->superAdmin();

        foreach (['super_admin', 'pelanggan', 'bukan-role'] as $nilai) {
            $this->actingAs($superAdmin)->getJson('/api/users?role='.$nilai)
                ->assertStatus(422)
                ->assertJsonValidationErrors('role');
        }

        $this->actingAs($superAdmin)->getJson('/api/users?search='.str_repeat('x', 101))
            ->assertStatus(422)
            ->assertJsonValidationErrors('search');

        // Saringan kosong = tanpa saringan (klien yang mengirim `role=` tidak jadi 422).
        $this->actingAs($superAdmin)->getJson('/api/users?role=&search=')->assertOk();
    }

    public function test_daftar_pengguna_tetap_tertutup_untuk_teknisi_dan_viewer(): void
    {
        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $peran) {
            $this->actingAs($this->akun($peran))->getJson('/api/users?role=teknisi')->assertForbidden();
        }
    }

    // ============================================================= /me/permissions

    public function test_izin_baru_hanya_untuk_super_admin_dan_cocok_dengan_penjagaan_asli(): void
    {
        $matriks = app(MatriksIzin::class);

        $this->assertContains('pengguna.buat-admin', $matriks->bolehUntuk(User::ROLE_SUPER_ADMIN));
        $this->assertContains('pengguna.ubah-status', $matriks->bolehUntuk(User::ROLE_SUPER_ADMIN));

        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER] as $peran) {
            $this->assertNotContains('pengguna.buat-admin', $matriks->bolehUntuk($peran), $peran);
            $this->assertNotContains('pengguna.ubah-status', $matriks->bolehUntuk($peran), $peran);
        }

        // Dan lewat HTTP: jawaban `/me/permissions` sama dengan yang dijanjikan.
        $bolehSuper = $this->actingAs($this->superAdmin())->getJson('/api/me/permissions')
            ->assertOk()
            ->json('data.boleh');
        $this->assertContains('pengguna.buat-admin', $bolehSuper);
        $this->assertContains('pengguna.ubah-status', $bolehSuper);

        $bolehAdmin = $this->actingAs($this->akun(User::ROLE_ADMIN))->getJson('/api/me/permissions')->json('data.boleh');
        $this->assertNotContains('pengguna.buat-admin', $bolehAdmin);
        $this->assertNotContains('pengguna.ubah-status', $bolehAdmin);
        $this->assertContains('pengguna.kelola', $bolehAdmin);

        $this->assertSame([], $matriks->petaYangNgawur());
    }

    // ================================= kemampuan lama admin TIDAK diperluas / dicabut

    public function test_kemampuan_lama_admin_di_put_users_tidak_berubah(): void
    {
        $admin = $this->akun(User::ROLE_ADMIN);
        $teknisi = $this->akun(User::ROLE_TEKNISI);

        $this->actingAs($admin)->putJson("/api/users/{$teknisi->id}", ['status' => 'nonaktif'])
            ->assertOk()
            ->assertJsonPath('data.status', 'nonaktif');

        // Sebaliknya: admin tetap tidak bisa mencetak super_admin lewat jalur lama.
        $this->actingAs($admin)->putJson("/api/users/{$teknisi->id}", ['role' => User::ROLE_SUPER_ADMIN])
            ->assertStatus(422);
    }
}
