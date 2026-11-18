<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\DeviceToken;
use App\Models\Organization;
use App\Models\UndanganPelanggan;
use App\Models\User;
use App\Services\MatriksIzin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Sisi LAB untuk anggota perusahaan pelanggan di aplikasi lab (18 Nov 2026,
 * menggantikan panel web): `GET /customers/{c}/anggota` dan
 * `POST /customers/{c}/anggota/{a}/nonaktifkan`.
 * Kontrak: `docs/perintah-frontend-super-admin.md` §3.
 *
 * Yang dijaga, dan kenapa tiap bagian punya test sendiri:
 *
 * - **Izin per peran** — admin & super admin boleh; teknisi, viewer, pelanggan,
 *   dan tanpa login tidak. Yang melonggarkan ini tidak menghasilkan error.
 * - **Isolasi** — perusahaan lab lain DAN anggota perusahaan lain dijawab 404,
 *   termasuk waktu `alasan` kosong (422 lebih dulu dari 404 = bukti barisnya ada).
 * - **PIC utama terakhir** dan **token dicabut** — dua aturan yang ditegakkan
 *   `Keanggotaan::nonaktifkan()`, dicek lewat pintu lab supaya dua jalur tidak
 *   bisa berbeda.
 * - **Jejak audit** — pelaku asli (tidak menyamar), peran, alasan.
 */
class AnggotaPelangganSisiLabTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private Organization $lab;

    private Customer $pt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanJalurPelanggan();
        $this->lab = $this->organisasi();
        $this->pt = $this->perusahaan('PT Anggota Uji');
    }

    private function jadiAnggota(Customer $perusahaan, string $peran, ?User $akun = null, string $status = CustomerMember::STATUS_AKTIF): CustomerMember
    {
        return CustomerMember::create([
            'organization_id' => $perusahaan->organization_id,
            'customer_id' => $perusahaan->id,
            'user_id' => ($akun ?? $this->pelanggan())->id,
            'peran' => $peran,
            'status' => $status,
        ]);
    }

    private function urlCabut(Customer $perusahaan, CustomerMember $anggota): string
    {
        return "/api/customers/{$perusahaan->id}/anggota/{$anggota->id}/nonaktifkan";
    }

    private function labLain(): Organization
    {
        return Organization::factory()->create(['nama' => 'Lab Lain']);
    }

    // ------------------------------------------------------------ daftar

    public function test_admin_dan_super_admin_melihat_anggota_dan_undangan_menunggu(): void
    {
        $admin = $this->adminLab();
        $picAdmin = $this->adminLab();
        $this->pt->forceFill(['pic_admin_id' => $picAdmin->id, 'maks_anggota' => 7])->save();

        $pic = $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA);
        $staf = $this->jadiAnggota($this->pt, CustomerMember::PERAN_STAF);
        $lama = $this->jadiAnggota($this->pt, CustomerMember::PERAN_STAF, null, CustomerMember::STATUS_NONAKTIF);
        $lama->forceFill(['dinonaktifkan_oleh' => $admin->id, 'dinonaktifkan_pada' => now()])->save();

        $menunggu = UndanganPelanggan::factory()->create([
            'organization_id' => $this->lab->id,
            'customer_id' => $this->pt->id,
            'email' => 'baru@contoh.test',
            'dibuat_oleh' => $admin->id,
        ]);
        // Tiga yang TIDAK boleh tampil: kedaluwarsa, sudah dipakai, dibatalkan.
        UndanganPelanggan::factory()->kedaluwarsa()->create(['organization_id' => $this->lab->id, 'customer_id' => $this->pt->id]);
        UndanganPelanggan::factory()->sudahDipakai()->create(['organization_id' => $this->lab->id, 'customer_id' => $this->pt->id]);
        UndanganPelanggan::factory()->create(['organization_id' => $this->lab->id, 'customer_id' => $this->pt->id, 'dibatalkan_pada' => now()]);

        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $peran) {
            $pemanggil = $this->adminLab($peran);

            $balasan = $this->actingAs($pemanggil)
                ->getJson("/api/customers/{$this->pt->id}/anggota")
                ->assertOk()
                ->assertJsonPath('data.customer.id', $this->pt->id)
                ->assertJsonPath('data.customer.nama', 'PT Anggota Uji')
                ->assertJsonPath('data.maks_anggota', 7)
                ->assertJsonPath('data.jumlah_aktif', 2)
                ->assertJsonPath('data.pic_admin.id', $picAdmin->id)
                ->assertJsonPath('data.pic_admin.email', $picAdmin->email);

            $anggota = $balasan->json('data.anggota');
            // Aktif dulu (urut id), nonaktif terakhir.
            $this->assertSame([$pic->id, $staf->id, $lama->id], array_column($anggota, 'id'));
            $this->assertSame(['aktif', 'aktif', 'nonaktif'], array_column($anggota, 'status'));

            $this->assertTrue($anggota[0]['pic_utama_terakhir']);
            $this->assertFalse($anggota[0]['bisa_dicabut']);
            $this->assertFalse($anggota[1]['pic_utama_terakhir']);
            $this->assertTrue($anggota[1]['bisa_dicabut']);
            $this->assertFalse($anggota[2]['bisa_dicabut']);
            $this->assertSame(['id' => $admin->id, 'nama' => $admin->name], $anggota[2]['dinonaktifkan_oleh']);
            $this->assertNull($anggota[1]['dinonaktifkan_oleh']);

            $this->assertSame(
                ['id', 'nama', 'email', 'telepon', 'jabatan'],
                array_keys($anggota[1]['orang']),
            );

            $undangan = $balasan->json('data.undangan_menunggu');
            $this->assertCount(1, $undangan);
            $this->assertSame($menunggu->id, $undangan[0]['id']);
            $this->assertSame('baru@contoh.test', $undangan[0]['email']);
            $this->assertSame(['id' => $admin->id, 'nama' => $admin->name], $undangan[0]['diundang_oleh']);

            // Kode undangan (maupun hash-nya) tidak pernah keluar dari daftar.
            $this->assertStringNotContainsString('kode', json_encode($undangan));
            $this->assertStringNotContainsString('$2y$', (string) $balasan->getContent());
        }
    }

    public function test_dua_pic_utama_aktif_dua_duanya_boleh_dicabut(): void
    {
        $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA);
        $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA);

        $anggota = $this->actingAs($this->adminLab())
            ->getJson("/api/customers/{$this->pt->id}/anggota")
            ->assertOk()
            ->json('data.anggota');

        $this->assertSame([false, false], array_column($anggota, 'pic_utama_terakhir'));
        $this->assertSame([true, true], array_column($anggota, 'bisa_dicabut'));
    }

    // -------------------------------------------------------------- izin

    public function test_teknisi_viewer_pelanggan_dan_tamu_ditolak_di_daftar_dan_cabut(): void
    {
        $anggota = $this->jadiAnggota($this->pt, CustomerMember::PERAN_STAF);
        $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA);

        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $peran) {
            $akun = $this->adminLab($peran);

            $this->actingAs($akun)->getJson("/api/customers/{$this->pt->id}/anggota")->assertForbidden();
            $this->actingAs($akun)
                ->postJson($this->urlCabut($this->pt, $anggota), ['alasan' => 'Tidak berwenang'])
                ->assertForbidden();
        }

        // Akun pelanggan dengan token pelanggan tidak menembus rute internal.
        $this->app['auth']->forgetGuards();
        $pelanggan = $this->pelanggan();
        $this->withHeaders(['Authorization' => 'Bearer '.$pelanggan->createToken('uji', ['pelanggan'])->plainTextToken])
            ->postJson($this->urlCabut($this->pt, $anggota), ['alasan' => 'Tidak berwenang'])
            ->assertForbidden();

        // `withHeaders()` menempel di instance test: tanpa ini "tamu" masih membawa
        // token pelanggan di atas.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/customers/{$this->pt->id}/anggota")->assertUnauthorized();
        $this->postJson($this->urlCabut($this->pt, $anggota), ['alasan' => 'Tidak berwenang'])->assertUnauthorized();

        $this->assertSame(CustomerMember::STATUS_AKTIF, $anggota->fresh()->status);
    }

    public function test_izin_matriks_admin_dan_super_admin_ya_teknisi_dan_viewer_tidak(): void
    {
        $matriks = app(MatriksIzin::class);

        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $peran) {
            $this->assertContains('anggota-pelanggan.lihat', $matriks->bolehUntuk($peran), $peran);
            $this->assertContains('anggota-pelanggan.cabut', $matriks->bolehUntuk($peran), $peran);
        }

        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $peran) {
            $this->assertNotContains('anggota-pelanggan.lihat', $matriks->bolehUntuk($peran), $peran);
            $this->assertNotContains('anggota-pelanggan.cabut', $matriks->bolehUntuk($peran), $peran);
        }

        $this->assertSame([], $matriks->petaYangNgawur());
    }

    // ---------------------------------------------------------- isolasi

    public function test_perusahaan_lab_lain_dijawab_404_di_daftar_dan_cabut_walau_alasan_kosong(): void
    {
        $labLain = $this->labLain();
        $asing = Customer::factory()->create(['organization_id' => $labLain->id, 'nama' => 'PT Asing']);
        $anggotaAsing = $this->jadiAnggota($asing, CustomerMember::PERAN_STAF);
        $this->jadiAnggota($asing, CustomerMember::PERAN_PIC_UTAMA);

        $admin = $this->adminLab(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->getJson("/api/customers/{$asing->id}/anggota")->assertNotFound();

        // Badan KOSONG: kalau validasi jalan sebelum penjaga lab, ini 422 — dan 422
        // vs 404 untuk id yang tidak ada membuktikan barisnya ada.
        $this->actingAs($admin)->postJson($this->urlCabut($asing, $anggotaAsing), [])->assertNotFound();
        $this->actingAs($admin)->postJson($this->urlCabut($asing, $anggotaAsing), ['alasan' => 'Menyusup'])->assertNotFound();

        // Sama persis dengan id yang tidak ada.
        $this->actingAs($admin)->postJson('/api/customers/999999/anggota/999999/nonaktifkan', [])->assertNotFound();

        $this->assertSame(CustomerMember::STATUS_AKTIF, $anggotaAsing->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_ANGGOTA_DICABUT]);
    }

    public function test_anggota_perusahaan_lain_di_lab_yang_sama_dijawab_404(): void
    {
        $lain = $this->perusahaan('PT Tetangga');
        $anggotaLain = $this->jadiAnggota($lain, CustomerMember::PERAN_STAF);
        $this->jadiAnggota($lain, CustomerMember::PERAN_PIC_UTAMA);

        // `{customer}` milik kita, `{anggota}` milik perusahaan lain.
        $this->actingAs($this->adminLab())
            ->postJson($this->urlCabut($this->pt, $anggotaLain), ['alasan' => 'Salah alamat'])
            ->assertNotFound();

        $this->assertSame(CustomerMember::STATUS_AKTIF, $anggotaLain->fresh()->status);
    }

    // ------------------------------------------------------------- cabut

    public function test_admin_mencabut_staf_token_dicabut_dan_jejaknya_lengkap(): void
    {
        $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA);
        $orang = $this->pelanggan();
        $anggota = $this->jadiAnggota($this->pt, CustomerMember::PERAN_STAF, $orang);

        $orang->createToken('hp-1', ['pelanggan']);
        $orang->createToken('hp-2', ['pelanggan']);
        DeviceToken::query()->create([
            'user_id' => $orang->id,
            'aplikasi' => DeviceToken::APLIKASI_PELANGGAN,
            'token' => 'fcm-uji-'.$orang->id,
            'platform' => 'android',
        ]);

        $admin = $this->adminLab();

        $this->actingAs($admin)
            ->postJson($this->urlCabut($this->pt, $anggota), ['alasan' => '  Pindah kerja, diminta PIC  '])
            ->assertOk()
            ->assertJsonPath('message', 'Anggota dinonaktifkan. Seluruh sesinya dicabut.')
            ->assertJsonPath('data.id', $anggota->id)
            ->assertJsonPath('data.status', 'nonaktif')
            ->assertJsonPath('data.bisa_dicabut', false)
            ->assertJsonPath('data.dinonaktifkan_oleh.id', $admin->id)
            ->assertJsonPath('data.dinonaktifkan_oleh.nama', $admin->name)
            ->assertJsonPath('data.orang.email', $orang->email);

        $anggota->refresh();
        $this->assertSame(CustomerMember::STATUS_NONAKTIF, $anggota->status);
        $this->assertSame($admin->id, (int) $anggota->dinonaktifkan_oleh);
        $this->assertNotNull($anggota->dinonaktifkan_pada);

        // REQ-AUTH-09: tidak anggota di tempat lain -> semua sesi & perangkat dicabut.
        $this->assertSame(0, $orang->tokens()->count());
        $this->assertSame(0, DeviceToken::query()->where('user_id', $orang->id)->count());

        // Jejak: baris `diubah` otomatis (status lama -> baru) DAN baris beralasan.
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'customer_members',
            'entity_id' => $anggota->id,
            'action' => AuditLog::ACTION_DIUBAH,
            'changed_by' => $admin->id,
        ]);

        $jejak = AuditLog::query()
            ->where('action', AuditLog::ACTION_ANGGOTA_DICABUT)
            ->where('entity_id', $anggota->id)
            ->sole();
        $this->assertSame($this->lab->id, $jejak->organization_id);
        $this->assertSame($admin->id, (int) $jejak->changed_by);
        $this->assertSame('customer_members', $jejak->entity_type);
        $this->assertSame(['status' => 'aktif'], $jejak->old_data);
        $this->assertSame('nonaktif', $jejak->new_data['status']);
        $this->assertSame('admin', $jejak->new_data['peran_pelaku']);
        $this->assertSame('staf', $jejak->new_data['peran_anggota']);
        $this->assertSame($this->pt->id, $jejak->new_data['customer_id']);
        $this->assertSame($orang->id, $jejak->new_data['user_id']);
        $this->assertSame('Pindah kerja, diminta PIC', $jejak->new_data['alasan']);
        $this->assertStringContainsString('Pindah kerja, diminta PIC', (string) $jejak->note);
    }

    public function test_super_admin_mencabut_dan_tercatat_sebagai_super_admin(): void
    {
        $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA);
        $anggota = $this->jadiAnggota($this->pt, CustomerMember::PERAN_STAF);
        $superAdmin = $this->adminLab(User::ROLE_SUPER_ADMIN);

        $this->actingAs($superAdmin)
            ->postJson($this->urlCabut($this->pt, $anggota), ['alasan' => 'Permintaan tertulis perusahaan'])
            ->assertOk()
            ->assertJsonPath('data.status', 'nonaktif');

        $jejak = AuditLog::query()->where('action', AuditLog::ACTION_ANGGOTA_DICABUT)->sole();
        $this->assertSame($superAdmin->id, (int) $jejak->changed_by);
        $this->assertSame('super_admin', $jejak->new_data['peran_pelaku']);
        $this->assertSame($superAdmin->id, (int) $anggota->fresh()->dinonaktifkan_oleh);
    }

    public function test_konsultan_yang_masih_anggota_di_perusahaan_lain_tidak_ikut_keluar(): void
    {
        $konsultan = $this->pelanggan();
        $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA);
        $dicabut = $this->jadiAnggota($this->pt, CustomerMember::PERAN_STAF, $konsultan);

        $tempatLain = $this->perusahaan('PT Klien Kedua');
        $this->jadiAnggota($tempatLain, CustomerMember::PERAN_STAF, $konsultan);

        $konsultan->createToken('hp', ['pelanggan']);

        $this->actingAs($this->adminLab())
            ->postJson($this->urlCabut($this->pt, $dicabut), ['alasan' => 'Kontrak berakhir'])
            ->assertOk();

        $this->assertSame(CustomerMember::STATUS_NONAKTIF, $dicabut->fresh()->status);
        $this->assertSame(1, $konsultan->tokens()->count(), 'Sesinya untuk perusahaan lain harus tetap hidup.');
    }

    public function test_pic_utama_terakhir_tidak_bisa_dicabut(): void
    {
        $orang = $this->pelanggan();
        $pic = $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA, $orang);
        $this->jadiAnggota($this->pt, CustomerMember::PERAN_STAF);
        $orang->createToken('hp', ['pelanggan']);

        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $peran) {
            $this->actingAs($this->adminLab($peran))
                ->postJson($this->urlCabut($this->pt, $pic), ['alasan' => 'Mau dicabut'])
                ->assertStatus(422)
                ->assertJsonPath('kode', 'pic_utama_terakhir');
        }

        $this->assertSame(CustomerMember::STATUS_AKTIF, $pic->fresh()->status);
        $this->assertSame(1, $orang->tokens()->count(), 'Penolakan tidak boleh mencabut apa pun.');
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_ANGGOTA_DICABUT]);
    }

    public function test_pic_utama_boleh_dicabut_kalau_ada_pic_utama_aktif_lain(): void
    {
        $pic = $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA);
        $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA);

        $this->actingAs($this->adminLab())
            ->postJson($this->urlCabut($this->pt, $pic), ['alasan' => 'PIC lain sudah ada'])
            ->assertOk()
            ->assertJsonPath('data.status', 'nonaktif');

        // Sekarang sisa satu PIC utama aktif: dia yang terakhir.
        $sisa = $this->actingAs($this->adminLab())
            ->getJson("/api/customers/{$this->pt->id}/anggota")
            ->json('data.anggota');
        $aktif = array_values(array_filter($sisa, fn ($a) => $a['status'] === 'aktif'));
        $this->assertCount(1, $aktif);
        $this->assertTrue($aktif[0]['pic_utama_terakhir']);
    }

    public function test_pic_utama_nonaktif_tidak_dihitung_sebagai_pengaman(): void
    {
        // Satu PIC aktif + satu PIC nonaktif = yang aktif TETAP yang terakhir.
        $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA, null, CustomerMember::STATUS_NONAKTIF);
        $aktif = $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA);

        $this->actingAs($this->adminLab())
            ->postJson($this->urlCabut($this->pt, $aktif), ['alasan' => 'Mencoba'])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'pic_utama_terakhir');
    }

    public function test_anggota_yang_sudah_nonaktif_dijawab_sudah_nonaktif(): void
    {
        $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA);
        $anggota = $this->jadiAnggota($this->pt, CustomerMember::PERAN_STAF, null, CustomerMember::STATUS_NONAKTIF);

        $this->actingAs($this->adminLab())
            ->postJson($this->urlCabut($this->pt, $anggota), ['alasan' => 'Sekali lagi'])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'sudah_nonaktif');

        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_ANGGOTA_DICABUT]);
    }

    public function test_alasan_wajib_3_sampai_500_karakter(): void
    {
        $this->jadiAnggota($this->pt, CustomerMember::PERAN_PIC_UTAMA);
        $anggota = $this->jadiAnggota($this->pt, CustomerMember::PERAN_STAF);
        $admin = $this->adminLab();

        foreach ([[], ['alasan' => ''], ['alasan' => '   '], ['alasan' => 'ab'], ['alasan' => str_repeat('x', 501)]] as $badan) {
            $this->actingAs($admin)
                ->postJson($this->urlCabut($this->pt, $anggota), $badan)
                ->assertStatus(422)
                ->assertJsonValidationErrors('alasan');
        }

        $this->assertSame(CustomerMember::STATUS_AKTIF, $anggota->fresh()->status);

        // 501 karakter MULTIBYTE ditolak (panjang dihitung karakter, bukan byte).
        $alasanMaks = str_repeat('é', 250).str_repeat('😀', 250);
        $this->assertSame(500, mb_strlen($alasanMaks));
        $this->assertGreaterThan(500, strlen($alasanMaks), 'Data uji harus multibyte supaya karakter vs byte teruji.');

        $this->actingAs($admin)
            ->postJson($this->urlCabut($this->pt, $anggota), ['alasan' => $alasanMaks.'é'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('alasan');

        // Batas atas persis 500 karakter lolos — dan MUAT di `audit_logs.note` (string(500)).
        // MySQL menegakkan panjang kolom, SQLite tidak: yang diperiksa di sini panjang `note`
        // dalam KARAKTER (itu yang dihitung utf8mb4), alasan lengkap tetap di `new_data`.
        $this->actingAs($admin)
            ->postJson($this->urlCabut($this->pt, $anggota), ['alasan' => $alasanMaks])
            ->assertOk();

        $jejak = AuditLog::query()->where('action', AuditLog::ACTION_ANGGOTA_DICABUT)->sole();
        $this->assertLessThanOrEqual(AuditLog::NOTE_MAKS, mb_strlen((string) $jejak->note));
        $this->assertSame($alasanMaks, $jejak->new_data['alasan'], 'Alasan LENGKAP harus utuh di new_data.');
    }

    public function test_cabut_dibatasi_throttle_per_akun(): void
    {
        $admin = $this->adminLab();
        $anggota = $this->jadiAnggota($this->pt, CustomerMember::PERAN_STAF);

        // 30/menit per akun; badan kosong tetap menghitung satu ketukan.
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($admin)->postJson($this->urlCabut($this->pt, $anggota), [])->assertStatus(422);
        }

        $this->actingAs($admin)->postJson($this->urlCabut($this->pt, $anggota), [])->assertStatus(429);

        // Ember per akun: admin lain tidak ikut terkunci.
        $this->actingAs($this->adminLab())->getJson("/api/customers/{$this->pt->id}/anggota")->assertOk();
    }
}
