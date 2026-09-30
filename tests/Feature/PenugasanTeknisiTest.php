<?php

namespace Tests\Feature;

use App\Events\PerubahanDataOrganisasi;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Penugasan;
use App\Models\PenugasanTeknisi;
use App\Models\User;
use App\Notifications\PenugasanBaru;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Penugasan teknisi — personal & grup.
 *
 * Yang dijaga berkas ini, urut dari yang paling gampang salah:
 *
 * 1. Penugasan setengah jadi tidak pernah tersimpan (transaksi).
 * 2. Teknisi cuma melihat punyanya — disaring di SERVER, bukan dengan
 *    menyembunyikan tab di mobile.
 * 3. Teknisi lab lain tidak bisa ditugaskan.
 * 4. Progres yang dilaporkan meninggalkan jejak — angka yang tidak bisa dihitung
 *    ulang dari mana pun tanpa jejak berhenti bisa dipercaya.
 */
class PenugasanTeknisiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

    private User $teknisiA;

    private User $teknisiB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);
        $this->teknisiA = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
            'kode_teknisi' => 'AAA',
        ]);
        $this->teknisiB = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
            'kode_teknisi' => 'BBB',
        ]);
    }

    public function test_penugasan_personal_yang_pertama_jadi_ketua(): void
    {
        Notification::fake();

        $this->actingAs($this->superAdmin)
            ->postJson('/api/penugasan', $this->badan([$this->teknisiA->id]))
            ->assertCreated()
            ->assertJsonPath('data.tipe', Penugasan::TIPE_PERSONAL);

        $penugasan = Penugasan::firstOrFail();

        // Personal tetap punya satu baris anggota dengan peran ketua — jadi tidak
        // ada cabang "kalau personal, ambil dari kolom lain" di seluruh sistem.
        $this->assertSame(PenugasanTeknisi::PERAN_KETUA, $penugasan->anggota->first()->peran);

        Notification::assertSentTo($this->teknisiA, PenugasanBaru::class);
    }

    public function test_penugasan_grup_ketuanya_yang_pertama_dipilih(): void
    {
        Notification::fake();

        $this->actingAs($this->superAdmin)
            ->postJson('/api/penugasan', $this->badan([$this->teknisiB->id, $this->teknisiA->id]))
            ->assertCreated()
            ->assertJsonPath('data.tipe', Penugasan::TIPE_GRUP);

        $penugasan = Penugasan::with('anggota')->firstOrFail();

        $this->assertSame(
            $this->teknisiB->id,
            $penugasan->anggota->firstWhere('peran', PenugasanTeknisi::PERAN_KETUA)?->user_id,
            'Ketuanya bukan yang pertama di daftar. Urutan yang dikirim layar itu bermakna — '
            .'grup tanpa ketua yang jelas jadi pekerjaan yang semua orang anggap sedang '
            .'dikerjakan orang lain.',
        );

        Notification::assertSentTo($this->teknisiA, PenugasanBaru::class);
        Notification::assertSentTo($this->teknisiB, PenugasanBaru::class);
    }

    public function test_penugasan_tanpa_teknisi_atau_tanpa_baris_ditolak(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/penugasan', ['judul' => 'Kalibrasi minggu ini', 'item' => [
                ['jenis_alat' => 'Autoklaf', 'jumlah' => 10],
            ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('teknisi');

        $this->actingAs($this->superAdmin)
            ->postJson('/api/penugasan', ['judul' => 'Kalibrasi minggu ini', 'teknisi' => [$this->teknisiA->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item');

        // Tidak boleh ada yang tersimpan setengah.
        $this->assertSame(0, Penugasan::query()->count());
    }

    public function test_teknisi_lab_lain_tidak_bisa_ditugaskan(): void
    {
        $labLain = Organization::factory()->create();
        $teknisiLabLain = User::factory()->create([
            'organization_id' => $labLain->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ]);

        // Kebocoran dua arah kalau lolos: papan lab ini memuat nama orang lab
        // lain, dan orang itu menerima notifikasi dari lab yang bukan tempatnya
        // bekerja.
        $this->actingAs($this->superAdmin)
            ->postJson('/api/penugasan', $this->badan([$teknisiLabLain->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('teknisi.0');
    }

    public function test_teknisi_cuma_melihat_penugasan_miliknya(): void
    {
        Notification::fake();

        $this->actingAs($this->superAdmin)
            ->postJson('/api/penugasan', $this->badan([$this->teknisiA->id]))
            ->assertCreated();

        // Teknisi B bukan anggotanya.
        $this->actingAs($this->teknisiB)
            ->getJson('/api/penugasan')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->teknisiA)
            ->getJson('/api/penugasan')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Dan membuka punya orang lain lewat id langsung → 404, bukan 403.
        $penugasan = Penugasan::firstOrFail();

        $this->actingAs($this->teknisiB)
            ->getJson("/api/penugasan/{$penugasan->id}")
            ->assertNotFound();
    }

    public function test_progres_yang_dilaporkan_tercatat_di_jejak_audit(): void
    {
        Notification::fake();

        $this->actingAs($this->superAdmin)
            ->postJson('/api/penugasan', $this->badan([$this->teknisiA->id]))
            ->assertCreated();

        $item = Penugasan::with('item')->firstOrFail()->item->first();

        $this->actingAs($this->teknisiA)
            ->patchJson("/api/penugasan/item/{$item->id}", ['jumlah_selesai' => 6])
            ->assertOk()
            ->assertJsonPath('data.persen_tuntas', 60)
            ->assertJsonPath('siap_ditutup', false);

        // Angka ini dilaporkan orang dan tidak bisa dihitung ulang dari mana pun.
        // Tanpa jejak siapa-mengubah-jadi-berapa, dia berhenti bisa dipercaya
        // begitu ada yang menanyakannya.
        $this->assertTrue(
            AuditLog::query()
                ->where('entity_type', 'penugasan_item')
                ->where('entity_id', $item->id)
                ->where('changed_by', $this->teknisiA->id)
                ->exists(),
            'Laporan progres tidak meninggalkan jejak audit. Periksa override '
            .'`organisasiUntukAudit()` di `PenugasanItem` — tabel itu nggak punya '
            .'`organization_id`, dan tanpa override-nya `catatAudit()` diam-diam '
            .'tidak menulis apa pun.',
        );
    }

    /**
     * Anggota grup lain dan admin yang memantau melihat angka yang sama dari
     * perangkat mana pun mereka masuk — tanpa menunggu tarikan berkala.
     */
    public function test_buat_dan_lapor_progres_menyiarkan_sinyal_ke_perangkat_lain(): void
    {
        Notification::fake();
        Event::fake([PerubahanDataOrganisasi::class]);

        $this->actingAs($this->superAdmin)
            ->postJson('/api/penugasan', $this->badan([$this->teknisiA->id, $this->teknisiB->id]))
            ->assertCreated();

        $penugasan = Penugasan::with('item')->firstOrFail();

        $this->actingAs($this->teknisiA)
            ->patchJson("/api/penugasan/item/{$penugasan->item->first()->id}", ['jumlah_selesai' => 3])
            ->assertOk();

        foreach (['dibuat', 'diubah'] as $aksi) {
            Event::assertDispatched(
                PerubahanDataOrganisasi::class,
                fn (PerubahanDataOrganisasi $e): bool => $e->jenis === 'penugasan'
                    && $e->aksi === $aksi
                    && $e->id === $penugasan->id
                    && $e->organizationId === $this->org->id,
            );
        }
    }

    /**
     * `PenugasanBaru` ikut saluran `broadcast`. Reverb yang mati melempar
     * exception SESUDAH penugasan tersimpan — tanpa penjagaan, pembuatnya dapat
     * 500, menekan Kirim lagi, dan lahir penugasan kembar.
     */
    public function test_penugasan_tetap_tersimpan_sekali_walau_siaran_meledak(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'x',
            'secret' => 'x',
            'app_id' => 'x',
            'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http'],
            'client_options' => ['timeout' => 1],
        ]]);

        $this->actingAs($this->superAdmin)
            ->postJson('/api/penugasan', $this->badan([$this->teknisiA->id]))
            ->assertCreated();

        $this->assertSame(1, Penugasan::query()->count());
    }

    public function test_lapor_lebih_dari_rencana_diterima_dan_persennya_dipotong_100(): void
    {
        Notification::fake();

        $this->actingAs($this->superAdmin)
            ->postJson('/api/penugasan', $this->badan([$this->teknisiA->id]))
            ->assertCreated();

        $item = Penugasan::with('item')->firstOrFail()->item->first();

        // Paketnya ternyata berisi 12, bukan 10. Menolaknya memaksa teknisi
        // berhenti melaporkan apa adanya — dan angka yang disesuaikan supaya
        // lolos validasi lebih buruk daripada angka yang melebihi rencana.
        $this->actingAs($this->teknisiA)
            ->patchJson("/api/penugasan/item/{$item->id}", ['jumlah_selesai' => 12])
            ->assertOk()
            // 120% di layar terbaca seperti bug, dan yang menjelaskannya bukan layar.
            ->assertJsonPath('data.persen_tuntas', 100)
            ->assertJsonPath('siap_ditutup', true);
    }

    public function test_waktu_pertama_membuka_yang_disimpan_bukan_yang_terakhir(): void
    {
        Notification::fake();

        $this->actingAs($this->superAdmin)
            ->postJson('/api/penugasan', $this->badan([$this->teknisiA->id]))
            ->assertCreated();

        $penugasan = Penugasan::firstOrFail();

        $pertama = $this->actingAs($this->teknisiA)
            ->postJson("/api/penugasan/{$penugasan->id}/dilihat")
            ->assertOk()
            ->json('dilihat_pada');

        $this->travel(2)->hours();

        $kedua = $this->actingAs($this->teknisiA)
            ->postJson("/api/penugasan/{$penugasan->id}/dilihat")
            ->assertOk()
            ->json('dilihat_pada');

        // Yang ditanyakan super admin adalah "sejak kapan dia tahu". Yang terakhir
        // membuka menjawab pertanyaan yang tidak ada gunanya.
        $this->assertSame($pertama, $kedua);
    }

    public function test_teknisi_tidak_bisa_membuat_penugasan(): void
    {
        $this->actingAs($this->teknisiA)
            ->postJson('/api/penugasan', $this->badan([$this->teknisiB->id]))
            ->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<int>  $teknisi
     * @return array<string, mixed>
     */
    private function badan(array $teknisi): array
    {
        return [
            'judul' => 'Kalibrasi minggu ini',
            'tanggal_target' => now()->addDays(4)->toDateString(),
            'teknisi' => $teknisi,
            'item' => [
                ['jenis_alat' => 'Autoklaf', 'jumlah' => 10],
            ],
        ];
    }
}
