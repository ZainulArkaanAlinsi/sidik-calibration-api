<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Masa berlaku sertifikat bisa disetel admin (permintaan 26 Jul).
 *
 * Sebelumnya dipaku `now()->addYear()`. Itu salah dua hal: interval kalibrasi itu
 * keputusan teknis yang beda-beda per jenis alat & permintaan pelanggan, dan
 * `now()` itu tanggal TERBIT — bukan tanggal alat dikerjain.
 *
 * Tiga lapis, sengaja:
 * 1. admin ngirim `berlaku_sampai` waktu approve  → dipakai apa adanya
 * 2. nggak ngirim                                 → default organisasi (bulan)
 * 3. organisasi belum nyetel                      → 12 bulan
 */
class MasaBerlakuSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    private function sesiMenungguApproval(string $tanggalKalibrasi = '2026-07-20'): CalibrationSession
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => $tanggalKalibrasi,
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    /** Jalanin job-nya langsung (bukan lewat queue) biar sertifikatnya jadi. */
    private function terbitkan(CalibrationSession $sesi, ?string $berlakuSampai = null): string
    {
        Storage::fake('local');
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        (new GenerateCertificate($sesi->id, $this->admin->id, $berlakuSampai))->handle();

        return (string) $sesi->certificate()->firstOrFail()->berlaku_sampai?->toDateString();
    }

    // ------------------------------------------------------------------ default

    public function test_default_dua_belas_bulan_dari_tanggal_kalibrasi(): void
    {
        $sesi = $this->sesiMenungguApproval('2026-07-20');

        $this->assertSame('2027-07-20', $this->terbitkan($sesi));
    }

    public function test_default_organisasi_bisa_disetel_per_bulan(): void
    {
        $this->org->update(['settings' => [Organization::KEY_MASA_BERLAKU_BULAN => 6]]);

        $sesi = $this->sesiMenungguApproval('2026-07-20');

        $this->assertSame('2027-01-20', $this->terbitkan($sesi));
    }

    /**
     * Setelan ngawur jatuh ke 12 bulan, nggak bikin sertifikat aneh.
     *
     * `0` bikin sertifikat kadaluarsa di hari terbitnya; di atas 120 bulan hampir
     * pasti salah ketik. Dua-duanya lebih baik jadi default daripada dipakai.
     */
    public function test_setelan_ngawur_jatuh_ke_default(): void
    {
        foreach ([0, -3, 999, 'dua belas', null] as $ngawur) {
            $this->org->update(['settings' => [Organization::KEY_MASA_BERLAKU_BULAN => $ngawur]]);

            $this->assertSame(
                12,
                $this->org->fresh()->masaBerlakuBulan(),
                'Setelan `'.var_export($ngawur, true).'` harusnya jatuh ke 12 bulan.',
            );
        }
    }

    /**
     * Dihitung dari TANGGAL KALIBRASI, bukan tanggal terbit.
     *
     * Sertifikat bisa terbit beberapa hari sesudah alat dikerjain (contoh Tirta
     * Gracia: kalibrasi 26 Mei, terbit 30 Mei). Kalau dihitung dari tanggal
     * terbit, masa berlakunya diam-diam kepanjangan dan alat lewat jatuh tempo
     * tanpa ada yang sadar.
     */
    public function test_dihitung_dari_tanggal_kalibrasi_bukan_tanggal_terbit(): void
    {
        // Waktu dipatok, biar bedanya tanggal-kalibrasi vs tanggal-terbit pasti
        // ada dan test-nya nggak tergantung kapan dijalanin.
        $this->travelTo('2026-08-15');

        $sesi = $this->sesiMenungguApproval('2026-07-20');
        $hasil = $this->terbitkan($sesi);

        // Nempel ke tanggal kalibrasi (20 Jul), BUKAN ke tanggal terbit (15 Ags).
        $this->assertSame('2027-07-20', $hasil);
        $this->assertNotSame('2027-08-15', $hasil);
    }

    // --------------------------------------------------- pilihan admin & batas

    /**
     * Approve nerbitin langsung (bukan antrean) sejak 29 Juli 2026, jadi yang
     * diperiksa tanggal di SERTIFIKATNYA — bukan parameter job yang dikirim.
     * Itu juga yang sebenernya penting: yang salah tanggal bakal kecetak.
     */
    public function test_admin_bisa_nentuin_tanggal_sendiri_waktu_approve(): void
    {
        Storage::fake('local');
        $sesi = $this->sesiMenungguApproval('2026-07-20');

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['berlaku_sampai' => '2028-03-31'])
            ->assertOk()
            ->assertJsonPath('data.sertifikat.berlaku_sampai', '2028-03-31');
    }

    public function test_tanggal_pilihan_admin_beneran_kepakai_di_sertifikat(): void
    {
        $sesi = $this->sesiMenungguApproval('2026-07-20');

        $this->assertSame('2028-03-31', $this->terbitkan($sesi, '2028-03-31'));
    }

    public function test_tanpa_berlaku_sampai_tetap_jalan_pakai_default(): void
    {
        Storage::fake('local');
        $sesi = $this->sesiMenungguApproval('2026-07-20');

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk()
            // Default masa berlaku organisasi yang kepakai — yang penting ADA
            // tanggalnya, bukan null yang kecetak jadi "—" di sertifikat.
            ->assertJsonPath('data.sertifikat.status', 'terbit');

        $this->assertNotNull(
            Certificate::where('calibration_session_id', $sesi->id)->firstOrFail()->berlaku_sampai,
        );
    }

    /** Sertifikat yang berlakunya habis pas/sebelum alat dikerjain itu nggak masuk akal. */
    public function test_tanggal_sebelum_atau_sama_dengan_tanggal_kalibrasi_ditolak(): void
    {
        $sesi = $this->sesiMenungguApproval('2026-07-20');

        foreach (['2026-07-20', '2026-07-19', '2020-01-01'] as $ditolak) {
            $this->actingAs($this->admin)
                ->postJson("/api/calibrations/{$sesi->id}/approve", ['berlaku_sampai' => $ditolak])
                ->assertStatus(422)
                ->assertJsonValidationErrors('berlaku_sampai');
        }

        // Sesinya nggak boleh keburu disetujui gara-gara percobaan yang ditolak.
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
    }

    /** Nahan salah ketik tahun (2035 → 2350). */
    public function test_tanggal_lebih_dari_sepuluh_tahun_ditolak(): void
    {
        $sesi = $this->sesiMenungguApproval('2026-07-20');

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", [
                'berlaku_sampai' => now()->addYears(11)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('berlaku_sampai');
    }

    /** Teknisi tetap nggak bisa approve — apalagi nentuin masa berlaku. */
    public function test_teknisi_nggak_bisa_nentuin_masa_berlaku(): void
    {
        $sesi = $this->sesiMenungguApproval('2026-07-20');

        $this->actingAs($this->teknisi)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['berlaku_sampai' => '2030-01-01'])
            ->assertForbidden();
    }

    /** Tanggalnya keluar sebagai tanggal POLOS, bukan ISO yang mundur sehari. */
    public function test_berlaku_sampai_di_respons_tanggal_polos(): void
    {
        $sesi = $this->sesiMenungguApproval('2026-07-20');
        $this->terbitkan($sesi, '2028-03-31');

        $this->actingAs($this->admin)
            ->getJson("/api/certificates/{$sesi->certificate->id}")
            ->assertOk()
            ->assertJsonPath('data.berlaku_sampai', '2028-03-31');
    }
}
