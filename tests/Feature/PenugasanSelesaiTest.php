<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Penugasan;
use App\Models\PenugasanItem;
use App\Models\User;
use App\Notifications\PenugasanDibukaLagi;
use App\Notifications\PenugasanSelesai;
use App\Services\MatriksIzin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * QA ronde 2-B, R2-2: penugasan BISA diselesaikan.
 *
 * Dulu teknisi melapor 1/1 (`persen_tuntas` 100, `siap_ditutup` true) tetapi
 * status tetap `aktif`: tidak ada rute yang menyetel `selesai`, `STATUS_SELESAI`
 * yatim, dan pesan "Minta super admin membukanya lagi" menunjuk aksi yang tak
 * terjangkau. Sekarang `POST /penugasan/{p}/selesaikan` (admin & super admin,
 * hanya bila tuntas) dan `POST /penugasan/{p}/buka-lagi` (super admin, alasan).
 */
class PenugasanSelesaiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private Organization $labLain;

    private User $admin;

    private User $superAdmin;

    private User $teknisi;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
        $this->labLain = Organization::factory()->create();

        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->teknisi = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI, 'name' => 'Teknisi Satu']);
        $this->viewer = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_VIEWER]);
    }

    /** @return array{0: Penugasan, 1: PenugasanItem} satu baris, `$selesai` dari `$jumlah` */
    private function penugasan(int $selesai = 10, int $jumlah = 10, string $status = Penugasan::STATUS_AKTIF, ?Organization $lab = null): array
    {
        $p = Penugasan::create([
            'organization_id' => ($lab ?? $this->lab)->id,
            'judul' => 'Kalibrasi minggu ini',
            'tipe' => Penugasan::TIPE_PERSONAL,
            'status' => $status,
            'dibuat_oleh' => $this->admin->id,
        ]);
        $p->anggota()->create(['user_id' => $this->teknisi->id, 'peran' => 'ketua']);

        return [$p, $p->item()->create(['jenis_alat' => 'Autoklaf', 'jumlah' => $jumlah, 'jumlah_selesai' => $selesai])];
    }

    private function jejak(string $aksi, int $id): ?AuditLog
    {
        return AuditLog::query()->where('entity_type', 'penugasan')->where('entity_id', $id)->where('action', $aksi)->latest('id')->first();
    }

    // ───────────────────────────── selesaikan ─────────────────────────────

    public function test_penugasan_tuntas_bisa_diselesaikan_admin_dan_super_admin_dengan_jejak_dan_kabar(): void
    {
        Notification::fake();

        foreach ([$this->admin, $this->superAdmin] as $pelaku) {
            [$p] = $this->penugasan();

            $this->actingAs($pelaku)->postJson("/api/penugasan/{$p->id}/selesaikan")
                ->assertOk()
                ->assertJsonPath('message', 'Penugasan diselesaikan.')
                ->assertJsonPath('data.status', 'selesai')
                ->assertJsonPath('data.persen_tuntas', 100);

            $segar = $p->fresh();
            $this->assertSame(Penugasan::STATUS_SELESAI, $segar->status);
            $this->assertNotNull($segar->diselesaikan_pada);

            $audit = $this->jejak(AuditLog::ACTION_DISELESAIKAN, $p->id);
            $this->assertNotNull($audit, 'ada baris audit `diselesaikan`');
            $this->assertSame($pelaku->id, $audit->changed_by);
            $this->assertSame('aktif', $audit->old_data['status']);
            $this->assertSame('selesai', $audit->new_data['status']);
            $this->assertSame(100, $audit->new_data['persen_tuntas']);
            $this->assertSame(1, AuditLog::query()->where('entity_type', 'penugasan')->where('entity_id', $p->id)->where('action', AuditLog::ACTION_DISELESAIKAN)->count());

            Notification::assertSentTo($this->teknisi, PenugasanSelesai::class);
        }
    }

    public function test_belum_tuntas_ditolak_422_penugasan_belum_tuntas_status_tetap_aktif(): void
    {
        Notification::fake();
        [$p] = $this->penugasan(selesai: 9, jumlah: 10);

        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/selesaikan")
            ->assertStatus(422)
            ->assertJsonPath('kode', 'penugasan_belum_tuntas')
            ->assertJsonPath('persen_tuntas', 90);

        $this->assertSame(Penugasan::STATUS_AKTIF, $p->fresh()->status);
        $this->assertNull($p->fresh()->diselesaikan_pada);
        $this->assertNull($this->jejak(AuditLog::ACTION_DISELESAIKAN, $p->id));
        Notification::assertNothingSent();
    }

    public function test_melebihi_rencana_tetap_boleh_ditutup(): void
    {
        [$p] = $this->penugasan(selesai: 12, jumlah: 10);

        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/selesaikan")->assertOk()->assertJsonPath('data.persen_tuntas', 100);
    }

    public function test_sudah_selesai_dan_dibatalkan_ditolak_dengan_kode_yang_jelas(): void
    {
        [$selesai] = $this->penugasan(status: Penugasan::STATUS_SELESAI);
        [$batal] = $this->penugasan(status: Penugasan::STATUS_DIBATALKAN);

        $this->actingAs($this->admin)->postJson("/api/penugasan/{$selesai->id}/selesaikan")
            ->assertStatus(422)->assertJsonPath('kode', 'penugasan_sudah_selesai')->assertJsonPath('status', 'selesai');

        $this->actingAs($this->admin)->postJson("/api/penugasan/{$batal->id}/selesaikan")
            ->assertStatus(422)->assertJsonPath('kode', 'penugasan_tidak_aktif')->assertJsonPath('status', 'dibatalkan');

        $this->assertNull($this->jejak(AuditLog::ACTION_DISELESAIKAN, $selesai->id));
        $this->assertSame(Penugasan::STATUS_DIBATALKAN, $batal->fresh()->status);
    }

    public function test_teknisi_dan_viewer_403_seragam_untuk_id_apa_pun(): void
    {
        [$p] = $this->penugasan();
        [$lain] = $this->penugasan(lab: $this->labLain);

        foreach ([$this->teknisi, $this->viewer] as $pelaku) {
            foreach ([$p->id, $lain->id, 99999999] as $id) {
                $this->actingAs($pelaku)->postJson("/api/penugasan/{$id}/selesaikan")->assertForbidden();
                $this->actingAs($pelaku)->postJson("/api/penugasan/{$id}/buka-lagi", ['alasan' => 'tidak berhak'])->assertForbidden();
            }
        }

        $this->assertSame(Penugasan::STATUS_AKTIF, $p->fresh()->status);
    }

    public function test_penugasan_lab_lain_dan_tak_ada_404_untuk_yang_berhak(): void
    {
        [$lain] = $this->penugasan(lab: $this->labLain);

        $this->actingAs($this->admin)->postJson("/api/penugasan/{$lain->id}/selesaikan")->assertNotFound();
        $this->actingAs($this->admin)->postJson('/api/penugasan/99999999/selesaikan')->assertNotFound();
        $this->actingAs($this->superAdmin)->postJson("/api/penugasan/{$lain->id}/buka-lagi", ['alasan' => 'lab lain'])->assertNotFound();
        $this->actingAs($this->superAdmin)->postJson('/api/penugasan/99999999/buka-lagi', ['alasan' => 'tidak ada'])->assertNotFound();

        $this->assertSame(Penugasan::STATUS_AKTIF, $lain->fresh()->status);
    }

    public function test_tamu_401(): void
    {
        [$p] = $this->penugasan();

        $this->postJson("/api/penugasan/{$p->id}/selesaikan")->assertUnauthorized();
        $this->postJson("/api/penugasan/{$p->id}/buka-lagi", ['alasan' => 'tamu'])->assertUnauthorized();
    }

    // ───────────────────────────── buka-lagi ─────────────────────────────

    public function test_super_admin_membuka_lagi_dengan_alasan_dan_jejak_lama_baru(): void
    {
        [$p, $item] = $this->penugasan();
        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/selesaikan")->assertOk();
        $selesaiPada = $p->fresh()->diselesaikan_pada;
        $this->assertNotNull($selesaiPada);

        $this->actingAs($this->superAdmin)->postJson("/api/penugasan/{$p->id}/buka-lagi", ['alasan' => 'Ada alat yang ternyata belum diperiksa.'])
            ->assertOk()
            ->assertJsonPath('message', 'Penugasan dibuka lagi.')
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.diselesaikan_pada', null);

        $segar = $p->fresh();
        $this->assertSame(Penugasan::STATUS_AKTIF, $segar->status);
        $this->assertNull($segar->diselesaikan_pada);
        // Progres teknisi tidak tersentuh.
        $this->assertSame(10, $item->fresh()->jumlah_selesai);

        $audit = $this->jejak(AuditLog::ACTION_DIBUKA_LAGI, $p->id);
        $this->assertNotNull($audit);
        $this->assertSame($this->superAdmin->id, $audit->changed_by);
        $this->assertSame('selesai', $audit->old_data['status']);
        $this->assertSame($selesaiPada->toIso8601String(), $audit->old_data['diselesaikan_pada']);
        $this->assertSame('aktif', $audit->new_data['status']);
        $this->assertSame('Ada alat yang ternyata belum diperiksa.', $audit->new_data['alasan']);
        $this->assertSame('Buka lagi penugasan: Ada alat yang ternyata belum diperiksa.', $audit->note);

        // Sesudah dibuka lagi, teknisi bisa melapor dan penugasan bisa ditutup lagi.
        $this->actingAs($this->teknisi)->patchJson("/api/penugasan/item/{$item->id}", ['jumlah_selesai' => 10])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/selesaikan")->assertOk();
        $this->assertSame(Penugasan::STATUS_SELESAI, $p->fresh()->status);
    }

    public function test_buka_lagi_mengabari_semua_anggota_dengan_alasan_dan_tidak_untuk_penolakan(): void
    {
        Notification::fake();
        [$p] = $this->penugasan(status: Penugasan::STATUS_SELESAI);
        $anggotaLain = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI]);
        $p->anggota()->create(['user_id' => $anggotaLain->id, 'peran' => 'anggota']);
        $bukanAnggota = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI]);

        // Penolakan (422 alasan kosong, 403 admin) tidak mengabari siapa pun.
        $this->actingAs($this->superAdmin)->postJson("/api/penugasan/{$p->id}/buka-lagi", [])->assertStatus(422);
        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/buka-lagi", ['alasan' => 'admin mencoba'])->assertForbidden();
        Notification::assertNothingSent();

        $this->actingAs($this->superAdmin)->postJson("/api/penugasan/{$p->id}/buka-lagi", ['alasan' => 'Ada alat yang belum diperiksa.'])->assertOk();

        foreach ([$this->teknisi, $anggotaLain] as $anggota) {
            Notification::assertSentTo($anggota, PenugasanDibukaLagi::class, function (PenugasanDibukaLagi $n) use ($anggota, $p): bool {
                $isi = $n->toDatabase($anggota);

                return ($isi['kategori'] ?? null) === 'penugasan_dibuka_lagi'
                    && ($isi['tautan'] ?? null) === ['tipe' => 'penugasan', 'id' => $p->id]
                    && str_contains((string) ($isi['body'] ?? ''), 'Ada alat yang belum diperiksa.')
                    && str_contains((string) ($isi['body'] ?? ''), $this->superAdmin->name);
            });
        }
        Notification::assertNotSentTo($bukanAnggota, PenugasanDibukaLagi::class);
        Notification::assertNotSentTo($this->admin, PenugasanDibukaLagi::class);

        // Panggilan kedua (sudah aktif) ditolak dan tidak mengabari lagi.
        $this->actingAs($this->superAdmin)->postJson("/api/penugasan/{$p->id}/buka-lagi", ['alasan' => 'sudah aktif kok'])->assertStatus(422);
        Notification::assertSentToTimes($this->teknisi, PenugasanDibukaLagi::class, 1);
    }

    public function test_admin_tidak_boleh_membuka_lagi_hanya_super_admin(): void
    {
        [$p] = $this->penugasan(status: Penugasan::STATUS_SELESAI);

        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/buka-lagi", ['alasan' => 'admin mencoba'])->assertForbidden();

        $this->assertSame(Penugasan::STATUS_SELESAI, $p->fresh()->status);
        $this->assertNull($this->jejak(AuditLog::ACTION_DIBUKA_LAGI, $p->id));
    }

    public function test_alasan_wajib_dan_dibatasi_422_sebelum_apa_pun_berubah(): void
    {
        [$p] = $this->penugasan(status: Penugasan::STATUS_SELESAI);

        foreach ([[], ['alasan' => ''], ['alasan' => 'abc'], ['alasan' => str_repeat('x', 401)]] as $badan) {
            $this->actingAs($this->superAdmin)->postJson("/api/penugasan/{$p->id}/buka-lagi", $badan)
                ->assertStatus(422)->assertJsonValidationErrors('alasan');
        }

        $this->assertSame(Penugasan::STATUS_SELESAI, $p->fresh()->status);

        // Batas atas persis 400 karakter muat di `audit_logs.note` (awalan + alasan).
        $this->actingAs($this->superAdmin)->postJson("/api/penugasan/{$p->id}/buka-lagi", ['alasan' => str_repeat('漢', 400)])->assertOk();
        $this->assertLessThanOrEqual(500, mb_strlen((string) $this->jejak(AuditLog::ACTION_DIBUKA_LAGI, $p->id)?->note));
    }

    public function test_buka_lagi_hanya_untuk_penugasan_selesai(): void
    {
        [$aktif] = $this->penugasan();
        [$batal] = $this->penugasan(status: Penugasan::STATUS_DIBATALKAN);

        $this->actingAs($this->superAdmin)->postJson("/api/penugasan/{$aktif->id}/buka-lagi", ['alasan' => 'masih aktif kok'])
            ->assertStatus(422)->assertJsonPath('kode', 'penugasan_tidak_selesai')->assertJsonPath('status', 'aktif');
        $this->actingAs($this->superAdmin)->postJson("/api/penugasan/{$batal->id}/buka-lagi", ['alasan' => 'sudah dibatalkan'])
            ->assertStatus(422)->assertJsonPath('kode', 'penugasan_tidak_selesai')->assertJsonPath('status', 'dibatalkan');

        $this->assertNull($this->jejak(AuditLog::ACTION_DIBUKA_LAGI, $aktif->id));
        $this->assertSame(Penugasan::STATUS_DIBATALKAN, $batal->fresh()->status);
    }

    // ─────────────────── laporProgres & batalkan tetap konsisten ───────────────────

    public function test_penugasan_selesai_menolak_laporan_progres_dengan_pesan_yang_kini_terjangkau(): void
    {
        [$p, $item] = $this->penugasan();
        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/selesaikan")->assertOk();

        $this->actingAs($this->teknisi)->patchJson("/api/penugasan/item/{$item->id}", ['jumlah_selesai' => 11])
            ->assertStatus(422);
        $this->assertSame(10, $item->fresh()->jumlah_selesai);

        // Yang selesai tidak bisa dibatalkan (kode lama tetap) -- harus dibuka lagi dulu.
        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/batalkan", ['alasan' => 'coba batalkan'])
            ->assertStatus(422)->assertJsonPath('kode', 'penugasan_sudah_selesai');
    }

    public function test_detail_dan_daftar_memuat_diselesaikan_pada_dan_filter_status_selesai_bekerja(): void
    {
        [$p] = $this->penugasan();
        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/selesaikan")->assertOk();

        $this->actingAs($this->admin)->getJson("/api/penugasan/{$p->id}")
            ->assertOk()->assertJsonPath('data.status', 'selesai')
            ->assertJson(fn ($j) => $j->where('data.diselesaikan_pada', fn ($v): bool => is_string($v) && $v !== '')->etc());

        $this->actingAs($this->admin)->getJson('/api/penugasan?status=selesai')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $p->id);
        $this->actingAs($this->admin)->getJson('/api/penugasan?status=aktif')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ─────────────────── izin (/me/permissions) ───────────────────

    public function test_matriks_izin_cocok_dengan_gerbang_rute(): void
    {
        $matriks = app(MatriksIzin::class);

        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $role) {
            $boleh = $matriks->bolehUntuk($role);
            $this->assertNotContains('penugasan.selesaikan', $boleh, $role);
            $this->assertNotContains('penugasan.buka-lagi', $boleh, $role);
        }

        $admin = $matriks->bolehUntuk(User::ROLE_ADMIN);
        $this->assertContains('penugasan.selesaikan', $admin);
        $this->assertNotContains('penugasan.buka-lagi', $admin, 'buka lagi = super admin saja');

        $super = $matriks->bolehUntuk(User::ROLE_SUPER_ADMIN);
        $this->assertContains('penugasan.selesaikan', $super);
        $this->assertContains('penugasan.buka-lagi', $super);

        // Dan lewat endpoint yang dibaca aplikasi.
        $this->assertContains(
            'penugasan.selesaikan',
            $this->actingAs($this->admin)->getJson('/api/me/permissions')->assertOk()->json('data.boleh'),
        );
    }
}
