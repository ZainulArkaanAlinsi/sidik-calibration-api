<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationCapability;
use App\Models\CalibrationMethod;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Formula;
use App\Models\Organization;
use App\Models\Penugasan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kelola kategori alat: buat, ubah nama, nonaktifkan/aktifkan, hapus yang tidak
 * dipakai (paket CRUD 18 Nov 2026, `docs/perintah-frontend-crud-lengkap.md` §2).
 *
 * Yang dijaga di sini: izin per peran (gerbang `role:admin`), isolasi lab (404
 * lintas lab), jejak audit, nama kembar, kategori nonaktif yang tidak ditawarkan
 * di form baru TAPI tetap terbaca alat lamanya, dan batas panjang dengan huruf
 * multibyte (pelajaran CI MySQL 18 Nov: SQLite tidak memeriksa panjang varchar).
 */
class KategoriKelolaTest extends TestCase
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

        $this->lab = Organization::factory()->create(['nama' => 'Lab Satu']);
        $this->labLain = Organization::factory()->create(['nama' => 'Lab Dua']);

        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->teknisi = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_TEKNISI]);
        $this->viewer = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_VIEWER]);
    }

    private function kategori(string $nama, ?Organization $lab = null, array $lain = []): EquipmentCategory
    {
        return EquipmentCategory::factory()->create([
            'organization_id' => ($lab ?? $this->lab)->id,
            'nama' => $nama,
            'kode' => Str::slug($nama),
            ...$lain,
        ]);
    }

    private function alat(EquipmentCategory $kategori): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $kategori->organization_id,
            'customer_id' => Customer::factory()->create(['organization_id' => $kategori->organization_id])->id,
            'equipment_category_id' => $kategori->id,
        ]);
    }

    private function jejak(string $aksi, int $entityId): ?AuditLog
    {
        return AuditLog::query()
            ->where('entity_type', 'equipment_categories')
            ->where('entity_id', $entityId)
            ->where('action', $aksi)
            ->latest('id')
            ->first();
    }

    // ───────────────────────────── buat ─────────────────────────────

    public function test_master_data_membuat_kategori_dengan_kode_dari_nama_dan_jejak_audit(): void
    {
        $respons = $this->actingAs($this->admin)->postJson('/api/categories', ['nama' => '  Tekanan   Vakum ']);

        $respons->assertCreated()
            ->assertJsonPath('message', 'Kategori dibuat.')
            ->assertJsonPath('data.kode', 'tekanan-vakum')
            ->assertJsonPath('data.nama', 'Tekanan Vakum')
            ->assertJsonPath('data.aktif', true)
            ->assertJsonPath('data.nonaktif_pada', null)
            ->assertJsonPath('data.jumlah_alat', 0)
            ->assertJsonPath('data.jumlah_kemampuan', 0);

        $baris = EquipmentCategory::where('kode', 'tekanan-vakum')->firstOrFail();
        $this->assertSame($this->lab->id, $baris->organization_id, 'organization_id harus dari pemanggil, bukan dari body');

        $audit = $this->jejak(AuditLog::ACTION_DIBIKIN, $baris->id);
        $this->assertNotNull($audit, 'pembuatan kategori harus tercatat');
        $this->assertSame($this->admin->id, $audit->changed_by);
    }

    public function test_super_admin_boleh_membuat_kategori(): void
    {
        $this->actingAs($this->superAdmin)->postJson('/api/categories', ['nama' => 'Gaya'])->assertCreated();
    }

    public function test_organization_id_dari_body_diabaikan(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/categories', ['nama' => 'Gaya', 'organization_id' => $this->labLain->id, 'kode' => 'dipaksa'])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'gaya');

        $this->assertDatabaseHas('equipment_categories', ['kode' => 'gaya', 'organization_id' => $this->lab->id]);
        $this->assertDatabaseMissing('equipment_categories', ['kode' => 'dipaksa']);
    }

    public function test_peran_selain_master_data_ditolak_dan_tidak_menulis_apa_pun(): void
    {
        $jumlah = EquipmentCategory::count();

        $this->actingAs($this->teknisi)->postJson('/api/categories', ['nama' => 'Gaya'])->assertForbidden();
        $this->actingAs($this->viewer)->postJson('/api/categories', ['nama' => 'Gaya'])->assertForbidden();

        $pelanggan = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_PELANGGAN]);
        Sanctum::actingAs($pelanggan, ['pelanggan']);
        $this->postJson('/api/categories', ['nama' => 'Gaya'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/categories', ['nama' => 'Gaya'])->assertUnauthorized();

        $this->assertSame($jumlah, EquipmentCategory::count());
    }

    public function test_semua_rute_tulis_kategori_menolak_teknisi_dan_viewer(): void
    {
        $k = $this->kategori('Massa');

        foreach ([$this->teknisi, $this->viewer] as $akun) {
            $this->actingAs($akun);
            $this->putJson('/api/categories/massa', ['nama' => 'Massa X'])->assertForbidden();
            $this->postJson('/api/categories/massa/nonaktifkan')->assertForbidden();
            $this->postJson('/api/categories/massa/aktifkan')->assertForbidden();
            $this->deleteJson('/api/categories/massa')->assertForbidden();
        }

        $this->assertNull($k->fresh()->nonaktif_pada);
        $this->assertSame('Massa', $k->fresh()->nama);
        $this->assertNull($k->fresh()->deleted_at);
    }

    public function test_nama_kembar_ditolak_tanpa_beda_huruf_besar_kecil_dan_spasi(): void
    {
        $ada = $this->kategori('Tekanan Vakum');

        $this->actingAs($this->admin)
            ->postJson('/api/categories', ['nama' => 'tekanan   VAKUM'])
            ->assertUnprocessable()
            ->assertJsonPath('kode', 'nama_kategori_kembar')
            ->assertJsonPath('errors.nama.0', 'Sudah ada kategori bernama itu.')
            ->assertJsonPath('kategori.kode', $ada->kode)
            ->assertJsonPath('kategori.aktif', true);

        $this->assertSame(1, EquipmentCategory::count());
    }

    public function test_nama_kembar_dengan_kategori_nonaktif_memberi_tahu_bahwa_dia_nonaktif(): void
    {
        $this->kategori('Gaya', null, ['nonaktif_pada' => now()]);

        $this->actingAs($this->admin)
            ->postJson('/api/categories', ['nama' => 'Gaya'])
            ->assertUnprocessable()
            ->assertJsonPath('kode', 'nama_kategori_kembar')
            ->assertJsonPath('kategori.aktif', false);
    }

    public function test_nama_yang_sama_boleh_ada_di_lab_lain(): void
    {
        $this->kategori('Gaya', $this->labLain);

        $this->actingAs($this->admin)->postJson('/api/categories', ['nama' => 'Gaya'])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'gaya');

        $this->assertSame(2, EquipmentCategory::where('kode', 'gaya')->count());
    }

    public function test_kode_yang_dipegang_kategori_terhapus_diberi_akhiran(): void
    {
        $lama = $this->kategori('Gaya');
        $lama->delete();

        $this->actingAs($this->admin)->postJson('/api/categories', ['nama' => 'Gaya'])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'gaya-2');
    }

    public function test_validasi_nama(): void
    {
        $this->actingAs($this->admin);

        $this->postJson('/api/categories', [])->assertUnprocessable()->assertJsonValidationErrors('nama');
        $this->postJson('/api/categories', ['nama' => '   '])->assertUnprocessable()->assertJsonValidationErrors('nama');
        $this->postJson('/api/categories', ['nama' => 'A'])->assertUnprocessable()->assertJsonValidationErrors('nama');
        $this->postJson('/api/categories', ['nama' => '!!! ???'])->assertUnprocessable()->assertJsonValidationErrors('nama');
        $this->postJson('/api/categories', ['nama' => ['bukan', 'string']])->assertUnprocessable()->assertJsonValidationErrors('nama');
        $this->postJson('/api/categories', ['nama' => str_repeat('a', 101)])->assertUnprocessable()->assertJsonValidationErrors('nama');

        $this->assertSame(0, EquipmentCategory::count());
    }

    public function test_batas_panjang_nama_dihitung_karakter_bukan_byte(): void
    {
        $this->actingAs($this->admin);

        // 100 huruf beraksen = 200 byte; batasnya 100 KARAKTER.
        $beraksen = str_repeat('é', 100);
        $this->postJson('/api/categories', ['nama' => $beraksen])->assertCreated();
        $this->assertSame($beraksen, EquipmentCategory::firstOrFail()->nama);
        $this->assertSame(100, mb_strlen(EquipmentCategory::firstOrFail()->nama));
        $this->postJson('/api/categories', ['nama' => $beraksen.'é'])->assertUnprocessable()->assertJsonValidationErrors('nama');

        // 3 byte dan 4 byte per karakter: 100 KARAKTER (298 dan 397 byte). Diawali
        // huruf Latin karena kode kategori diturunkan dari slug nama.
        foreach (['漢' => 3, '😀' => 4] as $huruf => $byte) {
            $nama = 'a'.str_repeat($huruf, 99);
            $this->assertSame(100, mb_strlen($nama));
            $this->assertSame(1 + 99 * $byte, strlen($nama));

            $this->postJson('/api/categories', ['nama' => $nama])->assertCreated();
            $this->assertSame($nama, EquipmentCategory::where('nama', $nama)->firstOrFail()->nama, 'nama utuh, tidak terpotong');

            $this->postJson('/api/categories', ['nama' => $nama.$huruf])->assertUnprocessable()->assertJsonValidationErrors('nama');
        }

        // Aksara non-Latin murni tidak menghasilkan kode -> ditolak dengan pesan yang jelas.
        $this->postJson('/api/categories', ['nama' => str_repeat('漢', 5)])
            ->assertUnprocessable()->assertJsonValidationErrors('nama');

        // Nama sepanjang batas yang slug-nya MEMBENGKAK tetap menghasilkan kode yang muat di kolom.
        $this->postJson('/api/categories', ['nama' => str_repeat('ß', 100)])->assertCreated();
        $this->assertLessThanOrEqual(110, mb_strlen(EquipmentCategory::where('nama', str_repeat('ß', 100))->firstOrFail()->kode));
    }

    // ───────────────────────────── ubah nama ─────────────────────────────

    public function test_ubah_nama_menjaga_kode_dan_mencatat_nama_lama_ke_baru(): void
    {
        $k = $this->kategori('Massa');

        $this->actingAs($this->admin)->putJson('/api/categories/massa', ['nama' => 'Massa dan Neraca'])
            ->assertOk()
            ->assertJsonPath('data.kode', 'massa')
            ->assertJsonPath('data.nama', 'Massa dan Neraca');

        $audit = $this->jejak(AuditLog::ACTION_DIUBAH, $k->id);
        $this->assertNotNull($audit);
        $this->assertSame('Massa', $audit->old_data['nama']);
        $this->assertSame('Massa dan Neraca', $audit->new_data['nama']);
        $this->assertSame($this->admin->id, $audit->changed_by);
    }

    public function test_ubah_nama_menjadi_milik_kategori_lain_ditolak_tapi_ganti_huruf_besar_sendiri_boleh(): void
    {
        $this->kategori('Gaya');
        $this->kategori('Massa');

        $this->actingAs($this->admin)->putJson('/api/categories/massa', ['nama' => 'GAYA'])
            ->assertUnprocessable()->assertJsonPath('kode', 'nama_kategori_kembar');

        $this->putJson('/api/categories/massa', ['nama' => 'MASSA'])->assertOk()->assertJsonPath('data.nama', 'MASSA');
    }

    public function test_ubah_nama_yang_sama_tidak_menulis_jejak_baru(): void
    {
        $k = $this->kategori('Massa');
        $sebelum = AuditLog::count();

        $this->actingAs($this->admin)->putJson('/api/categories/massa', ['nama' => 'Massa'])->assertOk();

        $this->assertSame($sebelum, AuditLog::count());
        $this->assertNull($this->jejak(AuditLog::ACTION_DIUBAH, $k->id));
    }

    public function test_kategori_nonaktif_tetap_bisa_diganti_namanya(): void
    {
        $this->kategori('Massa', null, ['nonaktif_pada' => now()]);

        $this->actingAs($this->admin)->putJson('/api/categories/massa', ['nama' => 'Massa Lama'])
            ->assertOk()->assertJsonPath('data.aktif', false);
    }

    // ───────────────────────────── nonaktif / aktif ─────────────────────────────

    public function test_nonaktifkan_kategori_yang_dipakai_alat_lama_tetap_menampilkan_kategorinya(): void
    {
        $k = $this->kategori('Massa');
        $alat = $this->alat($k);

        $this->actingAs($this->admin)->postJson('/api/categories/massa/nonaktifkan', ['alasan' => 'Tidak dipakai lagi'])
            ->assertOk()
            ->assertJsonPath('data.aktif', false)
            ->assertJsonPath('data.jumlah_alat', 1);

        $this->assertNotNull($k->fresh()->nonaktif_pada);

        // Alat lama tetap menampilkan kategorinya...
        $this->getJson("/api/equipments/{$alat->id}")->assertOk()->assertJsonPath('data.kategori', 'massa');
        // ...dan detail kategorinya masih terbaca (CMC-nya dibutuhkan alat lama).
        $this->getJson('/api/categories/massa')->assertOk()
            ->assertJsonPath('data.aktif', false)
            ->assertJsonPath('data.id', $k->id);
    }

    public function test_nonaktifkan_menulis_satu_jejak_dengan_alasan_dan_idempoten(): void
    {
        $k = $this->kategori('Massa');

        $this->actingAs($this->admin)->postJson('/api/categories/massa/nonaktifkan', ['alasan' => 'Digabung ke kategori lain'])->assertOk();
        $this->postJson('/api/categories/massa/nonaktifkan', ['alasan' => 'Ulang'])->assertOk()->assertJsonPath('data.aktif', false);

        $this->assertSame(1, AuditLog::where('entity_type', 'equipment_categories')
            ->where('entity_id', $k->id)->where('action', AuditLog::ACTION_DINONAKTIFKAN)->count(), 'dua ketukan = satu baris audit');

        $audit = $this->jejak(AuditLog::ACTION_DINONAKTIFKAN, $k->id);
        $this->assertSame('Nonaktifkan kategori: Digabung ke kategori lain', $audit->note);
        $this->assertSame('Digabung ke kategori lain', $audit->new_data['alasan']);
        $this->assertNull($audit->old_data['nonaktif_pada']);
        $this->assertNotNull($audit->new_data['nonaktif_pada']);
        $this->assertSame($this->admin->id, $audit->changed_by);
    }

    public function test_nonaktifkan_tanpa_alasan_boleh_dan_aktifkan_membalikkan(): void
    {
        $k = $this->kategori('Massa');

        $this->actingAs($this->admin)->postJson('/api/categories/massa/nonaktifkan')->assertOk();
        $this->assertSame('Nonaktifkan kategori', $this->jejak(AuditLog::ACTION_DINONAKTIFKAN, $k->id)->note);

        $this->postJson('/api/categories/massa/aktifkan')->assertOk()
            ->assertJsonPath('data.aktif', true)
            ->assertJsonPath('data.nonaktif_pada', null);
        $this->assertNull($k->fresh()->nonaktif_pada);
        $this->assertNotNull($this->jejak(AuditLog::ACTION_DIAKTIFKAN, $k->id));

        // Mengaktifkan yang sudah aktif: 200, tanpa baris audit kedua.
        $this->postJson('/api/categories/massa/aktifkan')->assertOk();
        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_DIAKTIFKAN)->count());
    }

    public function test_batas_alasan_dihitung_karakter_dan_note_audit_muat_di_kolomnya(): void
    {
        $k = $this->kategori('Massa');
        $this->actingAs($this->admin);

        // 401 karakter -> ditolak.
        $this->postJson('/api/categories/massa/nonaktifkan', ['alasan' => str_repeat('é', 401)])
            ->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->postJson('/api/categories/massa/nonaktifkan', ['alasan' => 'abcd'])
            ->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->assertNull($k->fresh()->nonaktif_pada);

        // 400 karakter persis, tiap jenis lebar byte: 2 byte, 3 byte, 4 byte.
        foreach (['é', '漢', '😀'] as $i => $huruf) {
            $alasan = str_repeat($huruf, 400);
            $this->postJson('/api/categories/massa/nonaktifkan', ['alasan' => $alasan])->assertOk();

            $audit = $this->jejak(AuditLog::ACTION_DINONAKTIFKAN, $k->id);
            $this->assertSame($alasan, $audit->new_data['alasan'], 'alasan LENGKAP ada di new_data');
            $this->assertLessThanOrEqual(500, mb_strlen($audit->note), 'note harus muat di audit_logs.note (500)');

            $this->postJson('/api/categories/massa/aktifkan')->assertOk();
        }
    }

    public function test_nonaktif_tidak_ditawarkan_di_dropdown_tapi_admin_bisa_memintanya(): void
    {
        $this->kategori('Massa');
        $this->kategori('Gaya', null, ['nonaktif_pada' => now()]);

        $kode = fn ($respons) => collect($respons->json('data'))->pluck('kode')->sort()->values()->all();

        // Semua peran: default hanya yang aktif.
        $this->assertSame(['massa'], $kode($this->actingAs($this->teknisi)->getJson('/api/categories')->assertOk()));
        $this->assertSame(['massa'], $kode($this->actingAs($this->viewer)->getJson('/api/categories')->assertOk()));
        $this->assertSame(['massa'], $kode($this->actingAs($this->admin)->getJson('/api/categories')->assertOk()));

        // Master Data & super admin: bisa meminta yang nonaktif juga.
        $this->assertSame(['gaya', 'massa'], $kode($this->actingAs($this->admin)->getJson('/api/categories?termasuk_nonaktif=1')->assertOk()));
        $this->assertSame(['gaya', 'massa'], $kode($this->actingAs($this->superAdmin)->getJson('/api/categories?termasuk_nonaktif=1')->assertOk()));

        // Peran lain: parameternya diabaikan -- bukan jalan pintas.
        $this->assertSame(['massa'], $kode($this->actingAs($this->teknisi)->getJson('/api/categories?termasuk_nonaktif=1')->assertOk()));
        $this->assertSame(['massa'], $kode($this->actingAs($this->viewer)->getJson('/api/categories?termasuk_nonaktif=1')->assertOk()));
    }

    public function test_alat_baru_ditolak_di_kategori_nonaktif_tapi_alat_lama_boleh_disunting(): void
    {
        $nonaktif = $this->kategori('Massa', null, ['nonaktif_pada' => now()]);
        $aktif = $this->kategori('Gaya');
        $alatLama = $this->alat($nonaktif);

        $this->actingAs($this->admin);

        // Alat baru ke kategori nonaktif: kategorinya ditolak.
        $this->postJson('/api/equipments', ['kategori' => 'massa', 'nama_alat' => 'Neraca'])
            ->assertUnprocessable()->assertJsonValidationErrors('kategori');

        // Kategori aktif tidak kena aturan ini.
        $this->postJson('/api/equipments', ['kategori' => 'gaya', 'nama_alat' => 'Neraca'])
            ->assertJsonMissingValidationErrors('kategori');

        // Alat lama mengirim ulang kategorinya apa adanya: tidak diblokir.
        $this->putJson("/api/equipments/{$alatLama->id}", ['kategori' => 'massa', 'nama_alat' => 'Neraca Baru'])
            ->assertJsonMissingValidationErrors('kategori');

        // Tapi tidak boleh PINDAH ke kategori nonaktif.
        $alatAktif = $this->alat($aktif);
        $this->putJson("/api/equipments/{$alatAktif->id}", ['kategori' => 'massa'])
            ->assertUnprocessable()->assertJsonValidationErrors('kategori');
    }

    public function test_kemampuan_baru_ditolak_di_kategori_nonaktif(): void
    {
        $this->kategori('Massa', null, ['nonaktif_pada' => now()]);

        $this->actingAs($this->teknisi)->postJson('/api/categories/massa/kemampuan', ['nama_alat' => 'Neraca Analitik'])
            ->assertUnprocessable()
            ->assertJsonPath('kode', 'kategori_nonaktif');

        $this->assertSame(0, CalibrationCapability::count());
    }

    public function test_penugasan_baru_tidak_boleh_memilih_kategori_nonaktif(): void
    {
        $nonaktif = $this->kategori('Massa', null, ['nonaktif_pada' => now()]);

        $this->actingAs($this->admin)->postJson('/api/penugasan', [
            'judul' => 'Uji',
            'teknisi' => [$this->teknisi->id],
            'item' => [['jenis_alat' => 'Neraca', 'jumlah' => 1, 'equipment_category_id' => $nonaktif->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('item.0.equipment_category_id');
    }

    // ───────────────────────────── hapus ─────────────────────────────

    public function test_hapus_kategori_yang_tidak_dipakai_adalah_soft_delete_dengan_jejak(): void
    {
        $k = $this->kategori('Massa');

        $this->actingAs($this->admin)->deleteJson('/api/categories/massa')
            ->assertOk()->assertJsonPath('message', 'Kategori dihapus.');

        $this->assertSoftDeleted('equipment_categories', ['id' => $k->id]);
        $this->assertNotNull($this->jejak(AuditLog::ACTION_DIHAPUS, $k->id));

        $this->getJson('/api/categories/massa')->assertNotFound();
        $this->assertNotContains('massa', collect($this->getJson('/api/categories?termasuk_nonaktif=1')->json('data'))->pluck('kode')->all());
        // Hapus ulang: sudah tidak ada.
        $this->deleteJson('/api/categories/massa')->assertNotFound();
    }

    public function test_kategori_yang_dipakai_alat_tidak_bisa_dihapus_tapi_boleh_dinonaktifkan(): void
    {
        $k = $this->kategori('Massa');
        $this->alat($k);
        $this->alat($k);

        $this->actingAs($this->admin)->deleteJson('/api/categories/massa')
            ->assertUnprocessable()
            ->assertJsonPath('kode', 'kategori_dipakai')
            ->assertJsonPath('pemakai.alat', 2);

        $this->assertNull($k->fresh()->deleted_at, 'tidak boleh terhapus');
        $this->assertNull($this->jejak(AuditLog::ACTION_DIHAPUS, $k->id));

        $this->postJson('/api/categories/massa/nonaktifkan')->assertOk();
    }

    public function test_alat_yang_sudah_dihapus_tetap_menahan_penghapusan_kategorinya(): void
    {
        $k = $this->kategori('Massa');
        $this->alat($k)->delete();

        $this->actingAs($this->admin)->deleteJson('/api/categories/massa')
            ->assertUnprocessable()->assertJsonPath('pemakai.alat', 1);
    }

    public function test_kemampuan_penugasan_dan_metode_juga_menahan_penghapusan(): void
    {
        $this->actingAs($this->admin);

        $a = $this->kategori('Massa');
        CalibrationCapability::factory()->create(['organization_id' => $this->lab->id, 'equipment_category_id' => $a->id]);
        $this->deleteJson('/api/categories/massa')->assertUnprocessable()->assertJsonPath('pemakai.kemampuan', 1);

        $b = $this->kategori('Gaya');
        $penugasan = Penugasan::create([
            'organization_id' => $this->lab->id, 'judul' => 'X', 'tipe' => 'personal', 'status' => 'aktif',
        ]);
        $penugasan->item()->create(['jenis_alat' => 'Neraca', 'jumlah' => 1, 'equipment_category_id' => $b->id]);
        $this->deleteJson('/api/categories/gaya')->assertUnprocessable()->assertJsonPath('pemakai.penugasan', 1);

        $c = $this->kategori('Volume');
        CalibrationMethod::factory()->create(['organization_id' => $this->lab->id, 'equipment_category_id' => $c->id]);
        $this->deleteJson('/api/categories/volume')->assertUnprocessable()->assertJsonPath('pemakai.metode', 1);
    }

    /**
     * Regresi CI MySQL 18 Nov 2026: `pemakai()` menghitung kolom `formulas`
     * yang tidak ada (`equipment_category_id`) dan SQLite diam-diam meloloskannya
     * (kondisi selalu salah -> 0), sehingga tidak ada test yang melihat 500.
     * Di sini baris `formulas` NYATA dibuat; hubungannya `besaran` = kode kategori
     * di organisasi yang sama.
     */
    public function test_rumus_dengan_besaran_sama_dengan_kode_kategori_menahan_penghapusan(): void
    {
        $this->actingAs($this->admin);

        $this->kategori('Massa');
        Formula::factory()->create(['organization_id' => $this->lab->id, 'besaran' => 'massa']);

        $respons = $this->deleteJson('/api/categories/massa')
            ->assertUnprocessable()
            ->assertJsonPath('kode', 'kategori_dipakai')
            ->assertJsonPath('pemakai.rumus', 1)
            ->assertJsonPath('pemakai.alat', 0);
        $this->assertStringContainsString('1 rumus', (string) $respons->json('message'));
        $this->assertNotNull(EquipmentCategory::where('kode', 'massa')->first(), 'kategori tidak boleh terhapus');

        // Rumus yang sudah dihapus lunak tetap menahan (alasan sama dengan alat:
        // riwayat masih menunjuknya).
        $this->kategori('Gaya');
        Formula::factory()->create(['organization_id' => $this->lab->id, 'besaran' => 'gaya'])->delete();
        $this->deleteJson('/api/categories/gaya')->assertUnprocessable()->assertJsonPath('pemakai.rumus', 1);
    }

    public function test_rumus_organisasi_atau_besaran_lain_tidak_menahan_penghapusan(): void
    {
        $k = $this->kategori('Volume');

        // Besaran lain di lab ini, besaran TANPA nilai, dan besaran yang sama tetapi
        // milik lab lain: tidak satu pun boleh menahan.
        Formula::factory()->create(['organization_id' => $this->lab->id, 'besaran' => 'ph']);
        Formula::factory()->create(['organization_id' => $this->lab->id, 'besaran' => null]);
        Formula::factory()->create(['organization_id' => $this->labLain->id, 'besaran' => 'volume']);

        $this->actingAs($this->admin)->deleteJson('/api/categories/volume')->assertOk();
        $this->assertSoftDeleted('equipment_categories', ['id' => $k->id]);
    }

    // ───────────────────────────── isolasi lab ─────────────────────────────

    public function test_kategori_lab_lain_dijawab_404_di_semua_rute_dan_tidak_berubah(): void
    {
        $milikLain = $this->kategori('Massa', $this->labLain);
        $jejakAwal = AuditLog::where('entity_type', 'equipment_categories')->where('entity_id', $milikLain->id)->count();
        $this->actingAs($this->admin);

        $this->putJson('/api/categories/massa', ['nama' => 'Dibajak'])->assertNotFound();
        $this->postJson('/api/categories/massa/nonaktifkan', ['alasan' => 'bajak'])->assertNotFound();
        $this->postJson('/api/categories/massa/aktifkan')->assertNotFound();
        $this->deleteJson('/api/categories/massa')->assertNotFound();

        $segar = $milikLain->fresh();
        $this->assertSame('Massa', $segar->nama);
        $this->assertNull($segar->nonaktif_pada);
        $this->assertNull($segar->deleted_at);
        $this->assertSame($jejakAwal, AuditLog::where('entity_type', 'equipment_categories')->where('entity_id', $milikLain->id)->count(), 'tidak ada jejak baru untuk kategori lab lain');
    }

    public function test_kode_yang_sama_di_dua_lab_hanya_menyentuh_lab_pemanggil(): void
    {
        $punyaKita = $this->kategori('Massa');
        $punyaLain = $this->kategori('Massa', $this->labLain);

        $this->actingAs($this->admin)->postJson('/api/categories/massa/nonaktifkan')->assertOk();

        $this->assertNotNull($punyaKita->fresh()->nonaktif_pada);
        $this->assertNull($punyaLain->fresh()->nonaktif_pada);
    }

    public function test_alat_lab_lain_tidak_menahan_penghapusan_kategori_kita(): void
    {
        $punyaKita = $this->kategori('Massa');
        $punyaLain = $this->kategori('Massa', $this->labLain);
        $this->alat($punyaLain);

        $this->actingAs($this->admin)->deleteJson('/api/categories/massa')->assertOk();
        $this->assertSoftDeleted('equipment_categories', ['id' => $punyaKita->id]);
        $this->assertNull($punyaLain->fresh()->deleted_at);
    }

    // ───────────────────────────── izin & throttle ─────────────────────────────

    public function test_kunci_izin_kategori_kelola_hanya_untuk_master_data_dan_super_admin(): void
    {
        $boleh = fn (User $u) => $this->actingAs($u)->getJson('/api/me/permissions')->json('data.boleh');

        $this->assertContains('kategori.kelola', $boleh($this->admin));
        $this->assertContains('kategori.kelola', $boleh($this->superAdmin));
        $this->assertNotContains('kategori.kelola', $boleh($this->teknisi));
        $this->assertNotContains('kategori.kelola', $boleh($this->viewer));
    }

    public function test_rute_kelola_kategori_ber_throttle_dan_batasnya_ditegakkan(): void
    {
        foreach (['POST api/categories', 'PUT api/categories/{kode}', 'POST api/categories/{kode}/nonaktifkan',
            'POST api/categories/{kode}/aktifkan', 'DELETE api/categories/{kode}'] as $kunci) {
            [$metode, $uri] = explode(' ', $kunci);
            $rute = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $uri && in_array($metode, $r->methods(), true));
            $this->assertNotNull($rute, "rute {$kunci} tidak ada");
            $this->assertContains('throttle:kategori-tulis', $rute->gatherMiddleware(), "{$kunci} tanpa throttle");
        }

        $this->actingAs($this->admin);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/categories', [])->assertUnprocessable();
        }
        $this->postJson('/api/categories', [])->assertStatus(429);
    }
}
