<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** K-50-08: `POST /equipments/{id}/jatuh-tempo` — jadwal langsung, tanggal tercetak lewat revisi. */
class UbahJatuhTempoAlatTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('arsip');

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
    }

    private function alat(): Equipment
    {
        return Equipment::factory()->create([
            'customer_id' => Customer::factory()->create(['nama' => 'PT Jatuh Tempo'])->id,
            'equipment_category_id' => EquipmentCategory::query()->firstOrCreate(
                ['organization_id' => $this->org->id, 'kode' => 'panjang'],
                ['nama' => 'Panjang'],
            )->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
            'tanggal_jatuh_tempo' => '2027-01-01',
        ]);
    }

    private function terbitkan(Equipment $alat): Certificate
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => Standard::factory()->create()->id,
            'tanggal_kalibrasi' => '2026-07-20',
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI, 'reviewed_by' => $this->admin->id]);
        (new GenerateCertificate($sesi->id, $this->admin->id, '2027-07-19'))->handle();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    public function test_tanpa_sertifikat_aktif_jadwal_langsung_berubah_dan_teraudit(): void
    {
        $alat = $this->alat();

        $this->actingAs($this->admin)
            ->postJson("/api/equipments/{$alat->id}/jatuh-tempo", ['tanggal_jatuh_tempo' => '2027-03-01', 'alasan' => 'Jadwal ulang disepakati'])
            ->assertOk()
            ->assertJsonPath('jalur', 'jadwal_alat');

        $this->assertSame('2027-03-01', $alat->fresh()->tanggal_jatuh_tempo->toDateString());

        $log = AuditLog::where('entity_type', $alat->getTable())->where('entity_id', $alat->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Jadwal ulang disepakati', (string) $log->note);
        $this->assertSame('2027-01-01', $log->old_data['tanggal_jatuh_tempo'] ?? null);
        $this->assertSame('2027-03-01', $log->new_data['tanggal_jatuh_tempo'] ?? null);
        $this->assertSame($this->admin->id, $log->changed_by);
    }

    public function test_tanggal_tercetak_lewat_revisi_dan_jadwal_alat_ikut(): void
    {
        $alat = $this->alat();
        $asal = $this->terbitkan($alat);
        $this->assertSame('2027-07-19', $asal->berlaku_sampai->toDateString());

        $res = $this->actingAs($this->admin)
            ->postJson("/api/equipments/{$alat->id}/jatuh-tempo", ['tanggal_jatuh_tempo' => '2027-12-31', 'alasan' => 'Perpanjang masa berlaku'])
            ->assertStatus(202)
            ->assertJsonPath('jalur', 'revisi_sertifikat')
            ->assertJsonPath('sertifikat.nomor', $asal->nomor.'-R1');

        $revisi = Certificate::findOrFail($res->json('sertifikat.id'));
        $this->assertSame($asal->id, $revisi->revision_of);
        $this->assertSame('2027-12-31', $revisi->berlaku_sampai->toDateString());
        // Antrean sync di test: revisinya sudah terbit dan jadwal alat disinkronkan.
        $this->assertSame('2027-12-31', $alat->fresh()->tanggal_jatuh_tempo->toDateString());
    }

    /**
     * Alasan boleh 2000 karakter tetapi `audit_logs.note` hanya `string(500)`:
     * alasan panjang + awalan pernah berpotensi `Data too long` di MySQL strict
     * (SQLite meloloskan). Diuji PERSIS di batas 2000 dengan huruf 2/3/4 byte,
     * dan `note` ditagih terhadap 500 karakter.
     */
    public function test_alasan_2000_karakter_multibyte_muat_di_note_dan_utuh_di_new_data(): void
    {
        $alat = $this->alat();
        $url = "/api/equipments/{$alat->id}/jatuh-tempo";

        foreach (['é' => '2027-03-01', '漢' => '2027-03-02', '😀' => '2027-03-03'] as $huruf => $tanggal) {
            $alasan = str_repeat($huruf, 2000);

            $this->actingAs($this->admin)
                ->postJson($url, ['tanggal_jatuh_tempo' => $tanggal, 'alasan' => $alasan])
                ->assertOk()
                ->assertJsonPath('jalur', 'jadwal_alat');

            $log = AuditLog::where('entity_type', $alat->getTable())->where('entity_id', $alat->id)->latest('id')->firstOrFail();
            $this->assertLessThanOrEqual(AuditLog::NOTE_MAKS, mb_strlen((string) $log->note), 'note melewati audit_logs.note');
            $this->assertStringStartsWith('Ubah jatuh tempo (jadwal alat): ', (string) $log->note);
            $this->assertSame($alasan, $log->new_data['alasan'], 'alasan LENGKAP harus utuh di new_data');
            $this->assertSame($tanggal, $log->new_data['tanggal_jatuh_tempo']);
        }

        $this->actingAs($this->admin)
            ->postJson($url, ['tanggal_jatuh_tempo' => '2027-03-04', 'alasan' => str_repeat('é', 2001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('alasan');
        $this->assertSame('2027-03-03', $alat->fresh()->tanggal_jatuh_tempo->toDateString());
    }

    public function test_hak_alasan_dan_organisasi(): void
    {
        $alat = $this->alat();
        $url = "/api/equipments/{$alat->id}/jatuh-tempo";

        $this->actingAs($this->teknisi)->postJson($url, ['tanggal_jatuh_tempo' => '2027-03-01', 'alasan' => 'tidak boleh'])->assertForbidden();
        $this->actingAs($this->admin)->postJson($url, ['tanggal_jatuh_tempo' => '2027-03-01'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson($url, ['tanggal_jatuh_tempo' => '2027-01-01', 'alasan' => 'sama saja'])->assertStatus(422);

        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);
        $this->actingAs($adminLain)->postJson($url, ['tanggal_jatuh_tempo' => '2027-03-01', 'alasan' => 'lab lain'])->assertNotFound();
    }
}
