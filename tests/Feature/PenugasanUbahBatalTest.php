<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Penugasan;
use App\Models\PenugasanItem;
use App\Models\User;
use App\Notifications\PenugasanBaru;
use App\Notifications\PenugasanDibatalkan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Edit (`PUT`) dan batal (`POST .../batalkan`) penugasan (paket CRUD 18 Nov
 * 2026, `docs/perintah-frontend-crud-lengkap.md` §4).
 *
 * Inti yang dijaga: hanya penugasan `aktif`; progres teknisi
 * (`jumlah_selesai`, catatan baris) tidak pernah tertimpa dan baris yang sudah
 * dikerjakan tidak bisa dibuang; batal = status saja, riwayat tetap; alasan
 * muat di `audit_logs.note`; lab lain 404 SEBELUM validasi.
 */
class PenugasanUbahBatalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private Organization $labLain;

    private User $admin;

    private User $superAdmin;

    private User $t1;

    private User $t2;

    private User $t3;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create(['nama' => 'Lab Satu']);
        $this->labLain = Organization::factory()->create(['nama' => 'Lab Dua']);

        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->t1 = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI, 'name' => 'Teknisi Satu']);
        $this->t2 = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI, 'name' => 'Teknisi Dua']);
        $this->t3 = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI, 'name' => 'Teknisi Tiga']);
        $this->viewer = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_VIEWER]);
    }

    /** @return array{0: Penugasan, 1: PenugasanItem} */
    private function penugasan(string $status = Penugasan::STATUS_AKTIF, ?Organization $lab = null, array $anggota = []): array
    {
        $lab ??= $this->lab;

        $p = Penugasan::create([
            'organization_id' => $lab->id,
            'judul' => 'Kalibrasi minggu ini',
            'tipe' => Penugasan::TIPE_PERSONAL,
            'status' => $status,
            'tanggal_target' => now()->addDays(7)->toDateString(),
            'catatan' => 'Catatan awal',
            'dibuat_oleh' => $this->admin->id,
        ]);

        foreach ($anggota === [] ? [$this->t1] : $anggota as $i => $u) {
            $p->anggota()->create(['user_id' => $u->id, 'peran' => $i === 0 ? 'ketua' : 'anggota']);
        }

        return [$p, $p->item()->create(['jenis_alat' => 'Autoklaf', 'jumlah' => 10])];
    }

    /** @param  array<string, mixed>  $ubah */
    private function body(Penugasan $p, PenugasanItem $item, array $ubah = []): array
    {
        return [
            'judul' => 'Kalibrasi minggu ini (revisi)',
            'teknisi' => [$this->t1->id],
            'item' => [['id' => $item->id, 'jenis_alat' => 'Autoklaf', 'jumlah' => 10]],
            ...$ubah,
        ];
    }

    private function jejak(string $aksi, int $penugasanId): ?AuditLog
    {
        return AuditLog::query()
            ->where('entity_type', 'penugasan')
            ->where('entity_id', $penugasanId)
            ->where('action', $aksi)
            ->latest('id')
            ->first();
    }

    // ───────────────────────────── edit ─────────────────────────────

    public function test_edit_mengubah_header_teknisi_dan_item_dengan_jejak_dan_kabar_ke_anggota_baru(): void
    {
        Notification::fake();
        [$p, $item] = $this->penugasan();
        $p->anggota()->where('user_id', $this->t1->id)->update(['dilihat_pada' => '2026-11-10 08:00:00']);
        $item->update(['jumlah_selesai' => 4, 'catatan' => 'catatan teknisi: 4 unit beres']);

        $this->actingAs($this->admin)->putJson("/api/penugasan/{$p->id}", [
            'judul' => 'Kalibrasi minggu ini (revisi)',
            'tanggal_target' => now()->addDays(14)->toDateString(),
            'catatan' => null,
            'teknisi' => [$this->t2->id, $this->t1->id],
            'item' => [
                ['id' => $item->id, 'jenis_alat' => 'Autoklaf', 'jumlah' => 12],
                ['jenis_alat' => 'Timbangan', 'jumlah' => 4],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Penugasan diubah.')
            ->assertJsonPath('data.judul', 'Kalibrasi minggu ini (revisi)')
            ->assertJsonPath('data.tipe', 'grup')
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.catatan', null)
            ->assertJsonPath('data.batal', null)
            ->assertJsonPath('data.teknisi.0.id', $this->t2->id)
            ->assertJsonPath('data.teknisi.0.peran', 'ketua')
            ->assertJsonPath('data.teknisi.1.id', $this->t1->id)
            ->assertJsonPath('data.teknisi.1.peran', 'anggota')
            ->assertJsonCount(2, 'data.item');

        $segar = $p->fresh();
        $this->assertSame('Kalibrasi minggu ini (revisi)', $segar->judul);
        $this->assertSame(Penugasan::TIPE_GRUP, $segar->tipe);

        // Progres teknisi TIDAK tersentuh; jumlah rencana berubah.
        $item->refresh();
        $this->assertSame(12, $item->jumlah);
        $this->assertSame(4, $item->jumlah_selesai);
        $this->assertSame('catatan teknisi: 4 unit beres', $item->catatan, 'catatan baris (tidak dikirim) harus tetap');

        // Anggota yang bertahan memegang `dilihat_pada`-nya.
        $this->assertNotNull($p->anggota()->where('user_id', $this->t1->id)->value('dilihat_pada'));

        // Jejak: header lama -> baru, dan daftar teknisi.
        $header = $this->jejak(AuditLog::ACTION_DIUBAH, $p->id);
        $this->assertNotNull($header);
        $this->assertSame($this->admin->id, $header->changed_by);

        $anggotaAudit = AuditLog::where('entity_type', 'penugasan')->where('entity_id', $p->id)
            ->where('note', 'Ubah penugasan: daftar teknisi')->first();
        $this->assertNotNull($anggotaAudit);
        $this->assertSame([$this->t1->id], $anggotaAudit->old_data['teknisi']);
        $this->assertSame([$this->t2->id, $this->t1->id], $anggotaAudit->new_data['teknisi']);

        // Hanya anggota BARU yang dikabari.
        Notification::assertSentTo($this->t2, PenugasanBaru::class);
        Notification::assertNotSentTo($this->t1, PenugasanBaru::class);
    }

    public function test_edit_yang_sama_berulang_tidak_menggandakan_apa_pun(): void
    {
        Notification::fake();
        [$p, $item] = $this->penugasan();
        $body = $this->body($p, $item);

        $this->actingAs($this->admin)->putJson("/api/penugasan/{$p->id}", $body)->assertOk();
        $sesudahPertama = AuditLog::count();
        $this->putJson("/api/penugasan/{$p->id}", $body)->assertOk();

        $this->assertSame($sesudahPertama, AuditLog::count(), 'PUT identik kedua tidak boleh menulis audit baru');
        $this->assertSame(1, $p->item()->count());
        $this->assertSame(1, $p->anggota()->count());
        Notification::assertNothingSent();
    }

    public function test_super_admin_boleh_mengedit(): void
    {
        [$p, $item] = $this->penugasan();

        $this->actingAs($this->superAdmin)->putJson("/api/penugasan/{$p->id}", $this->body($p, $item))->assertOk();
    }

    /** MUTAN gerbang peran. */
    public function test_teknisi_viewer_pelanggan_dan_tanpa_login_tidak_bisa_mengedit_atau_membatalkan(): void
    {
        [$p, $item] = $this->penugasan();

        foreach ([$this->t1, $this->viewer] as $akun) {
            $this->actingAs($akun)->putJson("/api/penugasan/{$p->id}", $this->body($p, $item))->assertForbidden();
            $this->postJson("/api/penugasan/{$p->id}/batalkan", ['alasan' => 'bukan hakmu'])->assertForbidden();
        }

        $pelanggan = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_PELANGGAN]);
        Sanctum::actingAs($pelanggan, ['pelanggan']);
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item))->assertForbidden();
        $this->postJson("/api/penugasan/{$p->id}/batalkan", ['alasan' => 'bukan hakmu'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item))->assertUnauthorized();
        $this->postJson("/api/penugasan/{$p->id}/batalkan", ['alasan' => 'tanpa login'])->assertUnauthorized();

        $segar = $p->fresh();
        $this->assertSame('Kalibrasi minggu ini', $segar->judul);
        $this->assertSame(Penugasan::STATUS_AKTIF, $segar->status);
    }

    /** MUTAN "hanya belum selesai": pemeriksaan status dicabut -> test ini merah. */
    public function test_penugasan_selesai_atau_dibatalkan_tidak_bisa_diedit(): void
    {
        foreach ([Penugasan::STATUS_SELESAI, Penugasan::STATUS_DIBATALKAN] as $status) {
            [$p, $item] = $this->penugasan($status);

            $this->actingAs($this->admin)->putJson("/api/penugasan/{$p->id}", $this->body($p, $item))
                ->assertUnprocessable()
                ->assertJsonPath('kode', 'penugasan_tidak_aktif')
                ->assertJsonPath('status', $status);

            $this->assertSame('Kalibrasi minggu ini', $p->fresh()->judul, "status {$status} ikut berubah");
        }
    }

    public function test_baris_yang_sudah_ada_progresnya_tidak_bisa_dibuang(): void
    {
        [$p, $item] = $this->penugasan();
        $item->update(['jumlah_selesai' => 3]);

        $this->actingAs($this->admin)->putJson("/api/penugasan/{$p->id}", [
            'judul' => 'Judul yang tidak boleh tersimpan',
            'teknisi' => [$this->t1->id],
            'item' => [['jenis_alat' => 'Baris pengganti', 'jumlah' => 1]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('kode', 'item_sudah_dikerjakan')
            ->assertJsonValidationErrors('item');

        // Seluruh transaksi batal: header, item, dan progres utuh.
        $this->assertSame('Kalibrasi minggu ini', $p->fresh()->judul);
        $this->assertSame(1, $p->item()->count());
        $this->assertSame(3, $item->fresh()->jumlah_selesai);
    }

    public function test_baris_tanpa_progres_boleh_dibuang_dan_isinya_tercatat_di_jejak(): void
    {
        [$p, $item] = $this->penugasan();
        $buang = $p->item()->create(['jenis_alat' => 'Oven', 'jumlah' => 2, 'catatan' => 'salah ketik']);

        $this->actingAs($this->admin)->putJson("/api/penugasan/{$p->id}", $this->body($p, $item))->assertOk();

        $this->assertDatabaseMissing('penugasan_item', ['id' => $buang->id]);
        $audit = AuditLog::where('entity_type', 'penugasan_item')->where('entity_id', $buang->id)
            ->where('action', AuditLog::ACTION_DIHAPUS)->get();
        $this->assertCount(1, $audit, 'satu baris `dihapus`, bukan dua');
        $this->assertSame('Oven', $audit[0]->old_data['jenis_alat']);
        $this->assertSame('salah ketik', $audit[0]->old_data['catatan']);
    }

    public function test_item_id_yang_bukan_milik_penugasan_ini_ditolak(): void
    {
        [$p, $item] = $this->penugasan();
        [, $itemLain] = $this->penugasan();
        [, $itemLabLain] = $this->penugasan(lab: $this->labLain, anggota: [
            User::factory()->create(['organization_id' => $this->labLain->id, 'role' => User::ROLE_TEKNISI]),
        ]);

        foreach ([$itemLain->id, $itemLabLain->id, 999999] as $idAsing) {
            $this->actingAs($this->admin)->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, [
                'item' => [['id' => $idAsing, 'jenis_alat' => 'Ngawur', 'jumlah' => 1]],
            ]))->assertUnprocessable()->assertJsonValidationErrors('item.0.id');
        }

        $this->assertSame('Autoklaf', $itemLain->fresh()->jenis_alat);
        $this->assertSame('Autoklaf', $itemLabLain->fresh()->jenis_alat);

        // Satu baris disebut dua kali.
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, [
            'item' => [['id' => $item->id, 'jenis_alat' => 'A', 'jumlah' => 1], ['id' => $item->id, 'jenis_alat' => 'B', 'jumlah' => 1]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('item');
    }

    public function test_tanggal_target_lama_lolos_walau_lewat_tapi_tanggal_baru_harus_hari_ini_atau_nanti(): void
    {
        [$p, $item] = $this->penugasan();
        $p->forceFill(['tanggal_target' => now()->subDays(3)->toDateString()])->save();
        $lama = $p->fresh()->tanggal_target->toDateString();

        $this->actingAs($this->admin);
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['tanggal_target' => $lama]))->assertOk();
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['tanggal_target' => now()->subDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('tanggal_target');
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['tanggal_target' => now()->toDateString()]))->assertOk();
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['tanggal_target' => null]))->assertOk()
            ->assertJsonPath('data.tanggal_target', null);
    }

    public function test_kategori_nonaktif_boleh_tetap_di_baris_lama_tapi_tidak_untuk_baris_baru(): void
    {
        $kategori = EquipmentCategory::factory()->create(['organization_id' => $this->lab->id, 'nonaktif_pada' => now()]);
        [$p, $item] = $this->penugasan();
        $item->update(['equipment_category_id' => $kategori->id]);

        $this->actingAs($this->admin);
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, [
            'item' => [['id' => $item->id, 'jenis_alat' => 'Autoklaf', 'jumlah' => 10, 'equipment_category_id' => $kategori->id]],
        ]))->assertOk();

        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, [
            'item' => [
                ['id' => $item->id, 'jenis_alat' => 'Autoklaf', 'jumlah' => 10, 'equipment_category_id' => $kategori->id],
                ['jenis_alat' => 'Baru', 'jumlah' => 1, 'equipment_category_id' => EquipmentCategory::factory()
                    ->create(['organization_id' => $this->lab->id, 'nonaktif_pada' => now()])->id],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors('item.1.equipment_category_id');
    }

    public function test_validasi_edit_dan_batas_panjang_multibyte(): void
    {
        [$p, $item] = $this->penugasan();
        $this->actingAs($this->admin);

        $this->putJson("/api/penugasan/{$p->id}", [])->assertUnprocessable()
            ->assertJsonValidationErrors(['judul', 'teknisi', 'item']);
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['teknisi' => []]))->assertUnprocessable()->assertJsonValidationErrors('teknisi');
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['item' => []]))->assertUnprocessable()->assertJsonValidationErrors('item');
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['teknisi' => [$this->t1->id, $this->t1->id]]))->assertUnprocessable();
        // Bukan teknisi (admin/viewer) dan teknisi lab lain ditolak.
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['teknisi' => [$this->viewer->id]]))->assertUnprocessable();
        $asing = User::factory()->create(['organization_id' => $this->labLain->id, 'role' => User::ROLE_TEKNISI]);
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['teknisi' => [$asing->id]]))->assertUnprocessable();

        // Batas persis, dalam KARAKTER (kolom penugasan.judul string(255), batas 150).
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['judul' => str_repeat('é', 150)]))->assertOk();
        $this->assertSame(150, mb_strlen($p->fresh()->judul));
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['judul' => str_repeat('é', 151)]))->assertUnprocessable()->assertJsonValidationErrors('judul');
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['catatan' => str_repeat('漢', 1000)]))->assertOk();
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['catatan' => str_repeat('漢', 1001)]))->assertUnprocessable()->assertJsonValidationErrors('catatan');
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['item' => [['id' => $item->id, 'jenis_alat' => str_repeat('é', 120), 'jumlah' => 999]]]))->assertOk();
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['item' => [['id' => $item->id, 'jenis_alat' => str_repeat('é', 121), 'jumlah' => 1]]]))->assertUnprocessable();
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['item' => [['id' => $item->id, 'jenis_alat' => 'A', 'jumlah' => 1000]]]))->assertUnprocessable();
        $this->putJson("/api/penugasan/{$p->id}", $this->body($p, $item, ['item' => [['id' => $item->id, 'jenis_alat' => 'A', 'jumlah' => 0]]]))->assertUnprocessable();
    }

    // ───────────────────────────── batal ─────────────────────────────

    public function test_batal_mengubah_status_saja_dan_riwayat_tetap(): void
    {
        Notification::fake();
        [$p, $item] = $this->penugasan(anggota: [$this->t1, $this->t2]);
        $item->update(['jumlah_selesai' => 5, 'catatan' => 'lima selesai']);

        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/batalkan", ['alasan' => 'Pelanggan membatalkan pesanan.'])
            ->assertOk()
            ->assertJsonPath('message', 'Penugasan dibatalkan.')
            ->assertJsonPath('data.status', 'dibatalkan')
            ->assertJsonPath('data.batal.alasan', 'Pelanggan membatalkan pesanan.')
            ->assertJsonPath('data.batal.oleh', $this->admin->name)
            ->assertJsonStructure(['data' => ['batal' => ['alasan', 'oleh', 'pada']]])
            ->assertJsonCount(2, 'data.teknisi')
            ->assertJsonPath('data.item.0.jumlah_selesai', 5);

        // Riwayat tetap utuh.
        $this->assertSoftDeleteAbsent($p);
        $this->assertSame(2, $p->anggota()->count());
        $this->assertSame(5, $item->fresh()->jumlah_selesai);
        $this->assertSame('lima selesai', $item->fresh()->catatan);
        $this->assertSame('Kalibrasi minggu ini', $p->fresh()->judul);

        $audit = $this->jejak(AuditLog::ACTION_DIBATALKAN, $p->id);
        $this->assertNotNull($audit);
        $this->assertSame($this->admin->id, $audit->changed_by);
        $this->assertSame('Batalkan penugasan: Pelanggan membatalkan pesanan.', $audit->note);
        $this->assertSame('aktif', $audit->old_data['status']);
        $this->assertSame('dibatalkan', $audit->new_data['status']);
        $this->assertSame('Pelanggan membatalkan pesanan.', $audit->new_data['alasan']);

        // Semua anggota dikabari.
        Notification::assertSentTo($this->t1, PenugasanDibatalkan::class);
        Notification::assertSentTo($this->t2, PenugasanDibatalkan::class);
        Notification::assertNotSentTo($this->t3, PenugasanDibatalkan::class);
    }

    private function assertSoftDeleteAbsent(Penugasan $p): void
    {
        $this->assertNull(Penugasan::withTrashed()->findOrFail($p->id)->deleted_at, 'batal TIDAK menghapus');
    }

    public function test_teknisi_tidak_bisa_lagi_melaporkan_progres_sesudah_batal(): void
    {
        [$p, $item] = $this->penugasan();
        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/batalkan", ['alasan' => 'Dibatalkan pelanggan'])->assertOk();

        $this->actingAs($this->t1)->patchJson("/api/penugasan/item/{$item->id}", ['jumlah_selesai' => 2])->assertUnprocessable();
        $this->assertSame(0, $item->fresh()->jumlah_selesai);
    }

    public function test_batal_dua_kali_idempoten_tanpa_audit_atau_kabar_kedua(): void
    {
        Notification::fake();
        [$p] = $this->penugasan();

        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/batalkan", ['alasan' => 'Alasan pertama'])->assertOk();
        $this->postJson("/api/penugasan/{$p->id}/batalkan", ['alasan' => 'Alasan kedua yang diabaikan'])->assertOk()
            ->assertJsonPath('message', 'Penugasan sudah dibatalkan.')
            ->assertJsonPath('data.batal.alasan', 'Alasan pertama');

        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_DIBATALKAN)->where('entity_id', $p->id)->count());
        Notification::assertSentToTimes($this->t1, PenugasanDibatalkan::class, 1);
    }

    public function test_batal_penugasan_selesai_ditolak(): void
    {
        [$p] = $this->penugasan(Penugasan::STATUS_SELESAI);

        $this->actingAs($this->admin)->postJson("/api/penugasan/{$p->id}/batalkan", ['alasan' => 'Sudah telanjur selesai'])
            ->assertUnprocessable()
            ->assertJsonPath('kode', 'penugasan_sudah_selesai')
            ->assertJsonPath('status', 'selesai');

        $this->assertSame(Penugasan::STATUS_SELESAI, $p->fresh()->status);
        $this->assertNull($this->jejak(AuditLog::ACTION_DIBATALKAN, $p->id));
    }

    public function test_alasan_batal_wajib_dan_batas_400_karakter_multibyte_muat_di_note(): void
    {
        $this->actingAs($this->admin);
        [$p] = $this->penugasan();

        $this->postJson("/api/penugasan/{$p->id}/batalkan", [])->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->postJson("/api/penugasan/{$p->id}/batalkan", ['alasan' => 'abcd'])->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->postJson("/api/penugasan/{$p->id}/batalkan", ['alasan' => str_repeat('é', 401)])->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->assertSame(Penugasan::STATUS_AKTIF, $p->fresh()->status);

        foreach (['é', '漢', '😀'] as $huruf) {
            [$q] = $this->penugasan();
            $alasan = str_repeat($huruf, 400);
            $this->postJson("/api/penugasan/{$q->id}/batalkan", ['alasan' => $alasan])->assertOk();

            $audit = $this->jejak(AuditLog::ACTION_DIBATALKAN, $q->id);
            $this->assertSame($alasan, $audit->new_data['alasan']);
            $this->assertLessThanOrEqual(500, mb_strlen($audit->note), 'note melewati audit_logs.note (500)');
        }
    }

    // ───────────────────────────── baca ─────────────────────────────

    public function test_daftar_dan_detail_menampilkan_batal_hanya_untuk_yang_dibatalkan(): void
    {
        [$aktif] = $this->penugasan();
        [$batal] = $this->penugasan();
        $this->actingAs($this->admin)->postJson("/api/penugasan/{$batal->id}/batalkan", ['alasan' => 'Tidak jadi dikerjakan'])->assertOk();

        $daftar = collect($this->getJson('/api/penugasan')->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($daftar[$aktif->id]['batal']);
        $this->assertSame('Tidak jadi dikerjakan', $daftar[$batal->id]['batal']['alasan']);

        $this->getJson("/api/penugasan/{$batal->id}")->assertOk()->assertJsonPath('data.batal.oleh', $this->admin->name);
        $this->getJson("/api/penugasan/{$aktif->id}")->assertOk()->assertJsonPath('data.batal', null);

        // Teknisi anggotanya membaca alasan yang sama.
        $this->actingAs($this->t1)->getJson("/api/penugasan/{$batal->id}")->assertOk()
            ->assertJsonPath('data.batal.alasan', 'Tidak jadi dikerjakan');
    }

    // ───────────────────────────── isolasi lab ─────────────────────────────

    public function test_lab_lain_dijawab_404_sebelum_validasi_dan_tidak_menulis_apa_pun(): void
    {
        $teknisiLain = User::factory()->create(['organization_id' => $this->labLain->id, 'role' => User::ROLE_TEKNISI]);
        [$milikLain, $itemLain] = $this->penugasan(lab: $this->labLain, anggota: [$teknisiLain]);

        $this->actingAs($this->admin);

        // Body SENGAJA tidak valid: 404 harus datang duluan, kalau tidak 422
        // membocorkan bahwa penugasan itu ada.
        $this->putJson("/api/penugasan/{$milikLain->id}", [])->assertNotFound();
        $this->putJson("/api/penugasan/{$milikLain->id}", [
            'judul' => 'Dibajak', 'teknisi' => [$teknisiLain->id], 'item' => [['id' => $itemLain->id, 'jenis_alat' => 'X', 'jumlah' => 1]],
        ])->assertNotFound();
        $this->postJson("/api/penugasan/{$milikLain->id}/batalkan", [])->assertNotFound();
        $this->postJson("/api/penugasan/{$milikLain->id}/batalkan", ['alasan' => 'Dibajak oleh lab lain'])->assertNotFound();

        $segar = $milikLain->fresh();
        $this->assertSame('Kalibrasi minggu ini', $segar->judul);
        $this->assertSame(Penugasan::STATUS_AKTIF, $segar->status);
        $this->assertSame('Autoklaf', $itemLain->fresh()->jenis_alat);
        $this->assertNull($this->jejak(AuditLog::ACTION_DIBATALKAN, $milikLain->id));
        // Percobaannya tercatat di riwayat lab PEMANGGIL.
        $this->assertGreaterThanOrEqual(1, AuditLog::where('action', AuditLog::ACTION_AKSES_LINTAS_LAB)
            ->where('organization_id', $this->lab->id)->count());
    }

    // ───────────────────────────── izin & throttle ─────────────────────────────

    public function test_kunci_izin_penugasan_ubah_dan_batalkan_per_peran(): void
    {
        $boleh = fn (User $u) => $this->actingAs($u)->getJson('/api/me/permissions')->json('data.boleh');

        foreach ([$this->admin, $this->superAdmin] as $akun) {
            $this->assertContains('penugasan.ubah', $boleh($akun));
            $this->assertContains('penugasan.batalkan', $boleh($akun));
        }
        foreach ([$this->t1, $this->viewer] as $akun) {
            $this->assertNotContains('penugasan.ubah', $boleh($akun));
            $this->assertNotContains('penugasan.batalkan', $boleh($akun));
        }
    }

    public function test_rute_edit_dan_batal_ber_throttle_penugasan_tulis(): void
    {
        foreach (['PUT api/penugasan/{penugasan}', 'POST api/penugasan/{penugasan}/batalkan'] as $kunci) {
            [$metode, $uri] = explode(' ', $kunci);
            $rute = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $uri && in_array($metode, $r->methods(), true));
            $this->assertNotNull($rute, "rute {$kunci} tidak ada");
            $this->assertContains('throttle:penugasan-tulis', $rute->gatherMiddleware(), "{$kunci} tanpa throttle");
        }
    }
}
