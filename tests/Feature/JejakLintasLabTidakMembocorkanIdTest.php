<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R2-6 (QA ronde 2): jejak `akses_lintas_lab` tidak boleh menjadi oracle
 * keberadaan ID lab lain.
 *
 * `PenjagaOrganisasi` menulis satu baris `akses_lintas_lab` (entity_type +
 * entity_id) di riwayat lab PEMANGGIL setiap kali ia membuka ID yang ADA tetapi
 * milik lab lain (404). Baris itu hanya lahir untuk ID yang ada, sedangkan
 * `GET /audit-logs` — termasuk filter `entity_type`/`entity_id` dan ekspor CSV —
 * membacanya kembali ke pemanggil. Jadi "jumlah baris untuk ID 13" naik dari 0
 * ke 1 hanya bila ID 13 ada di lab lain: keberadaan + nama tabel ID itu bocor,
 * persis yang ingin dicegah jawaban 404.
 *
 * Perbaikan: baris itu tetap TERCATAT (pertanyaan "pernah ada yang mencoba?"
 * tetap terjawab), tetapi tidak dikembalikan oleh `/audit-logs` ke selain super
 * admin. Super admin memang berhak melihat semua data lab.
 */
class JejakLintasLabTidakMembocorkanIdTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private Organization $labLain;

    private User $admin;

    private User $superAdmin;

    private Customer $asing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create(['nama' => 'Lab Penanya']);
        $this->labLain = Organization::factory()->create(['nama' => 'Lab Lain']);

        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->lab->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->asing = Customer::factory()->create(['organization_id' => $this->labLain->id]);
    }

    private function jumlah(User $pemanggil, string $query): int
    {
        return (int) $this->actingAs($pemanggil)->getJson("/api/audit-logs?{$query}")->assertOk()->json('meta.total');
    }

    public function test_filter_entity_id_tidak_membedakan_id_yang_ada_di_lab_lain_dari_yang_tidak_ada(): void
    {
        $tidakAda = 99999999;

        $this->actingAs($this->admin)->getJson("/api/customers/{$this->asing->id}")->assertNotFound();
        $this->actingAs($this->admin)->getJson("/api/customers/{$tidakAda}")->assertNotFound();

        $this->assertSame(
            $this->jumlah($this->admin, "entity_type=customers&entity_id={$tidakAda}"),
            $this->jumlah($this->admin, "entity_type=customers&entity_id={$this->asing->id}"),
            'Jumlah baris riwayat untuk ID lab lain beda dari ID yang tidak ada: keberadaan ID bocor.',
        );
        $this->assertSame(0, $this->jumlah($this->admin, "entity_type=customers&entity_id={$this->asing->id}"));
    }

    public function test_daftar_tanpa_filter_dan_filter_aksi_tidak_memuat_baris_akses_lintas_lab_untuk_admin(): void
    {
        $sebelum = (int) $this->actingAs($this->admin)->getJson('/api/audit-logs')->assertOk()->json('meta.total');

        $this->actingAs($this->admin)->getJson("/api/customers/{$this->asing->id}")->assertNotFound();

        // Barisnya TETAP tercatat — jejaknya tidak boleh hilang.
        $this->assertSame(1, AuditLog::query()->where('action', AuditLog::ACTION_AKSES_LINTAS_LAB)->count());

        // Daftar polos: baris yang barusan lahir tidak ikut terkirim ke admin, jadi
        // "ada baris baru" tidak membedakan ID yang ada dari yang tidak ada.
        $semua = $this->actingAs($this->admin)->getJson('/api/audit-logs')->assertOk();
        $this->assertNotContains(AuditLog::ACTION_AKSES_LINTAS_LAB, array_column($semua->json('data'), 'aksi'));
        $this->assertSame($sebelum, (int) $semua->json('meta.total'), 'Jumlah baris riwayat naik setelah percobaan: ID lab lain bocor.');
    }

    public function test_ekspor_csv_tidak_memuat_baris_akses_lintas_lab_untuk_admin(): void
    {
        $this->actingAs($this->admin)->getJson("/api/customers/{$this->asing->id}")->assertNotFound();

        $csv = $this->actingAs($this->admin)->get('/api/audit-logs/export')->assertOk()->streamedContent();

        $this->assertStringNotContainsString(AuditLog::ACTION_AKSES_LINTAS_LAB, $csv);
        $this->assertStringNotContainsString('customers', $csv);
    }

    public function test_super_admin_tetap_melihat_jejaknya(): void
    {
        $this->actingAs($this->admin)->getJson("/api/customers/{$this->asing->id}")->assertNotFound();

        $baris = collect($this->actingAs($this->superAdmin)->getJson('/api/audit-logs')->assertOk()->json('data'))
            ->where('aksi', AuditLog::ACTION_AKSES_LINTAS_LAB);

        $this->assertCount(1, $baris);
        $this->assertSame($this->asing->id, $baris->first()['entitas']['id']);
        $this->assertSame(1, $this->jumlah($this->superAdmin, "entity_type=customers&entity_id={$this->asing->id}"));
    }

    public function test_baris_audit_lain_tetap_terbaca_admin(): void
    {
        $milik = Customer::factory()->create(['organization_id' => $this->lab->id]);
        $this->actingAs($this->admin)->putJson("/api/customers/{$milik->id}", ['nama' => 'Nama Baru Satu'])->assertOk();

        $this->assertGreaterThanOrEqual(1, $this->jumlah($this->admin, "entity_type=customers&entity_id={$milik->id}"));
    }
}
