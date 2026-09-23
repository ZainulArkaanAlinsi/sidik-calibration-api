<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin (Master Data) mengoreksi ANGKA yang sudah diisi teknisi.
 *
 * Kemampuannya sendiri sudah ada sejak `AdminEditSesiTeknisiTest`. Yang dijaga
 * berkas ini jejaknya:
 *
 *  - angka LAMA tidak boleh hilang — `isiUlangPengukuran()` menghapus baris
 *    mentah lalu menulisnya kembali, jadi tanpa potret sebelum-sesudah,
 *    pertanyaan "angka yang tercetak ini dulunya berapa" tidak punya jawaban;
 *  - koreksinya wajib beralasan, karena jejak yang cuma menyebut siapa & kapan
 *    tidak menjelaskan apa pun ke asesor.
 *
 * ISO/IEC 17025 klausul 7.5.2, dan AGENTS.md §Peran & pemisahan wewenang
 * butir 5.
 */
class KoreksiPembacaanAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CalibrationSession $sesi;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $kategori = EquipmentCategory::factory()->create(['organization_id' => $org->id]);

        $alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'equipment_category_id' => $kategori->id,
            'satuan' => 'mm',
            // Resolusi & toleransi bukan hiasan fixture: tanpa keduanya jalur
            // hitung generik pulang tanpa satu pun baris, dan test terakhir di
            // berkas ini tidak punya angka untuk diadu.
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);

        $standar = Standard::factory()->create(['organization_id' => $org->id]);

        $teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->admin = User::factory()->admin()->create(['organization_id' => $org->id]);

        $this->sesi = CalibrationSession::factory()->create([
            'organization_id' => $org->id,
            'equipment_id' => $alat->id,
            'standard_id' => $standar->id,
            'teknisi_id' => $teknisi->id,
            'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
        ]);

        // Pembacaan teknisi yang SUDAH tersimpan — ini yang nanti dikoreksi.
        foreach ([50.02, 50.01, 50.03] as $i => $nilai) {
            RawMeasurement::create([
                'calibration_session_id' => $this->sesi->id,
                'titik_ke' => 1,
                'pembacaan_ke' => $i + 1,
                'tahap' => 'sesudah_adjustment',
                'titik_ukur' => 50.0,
                'pembacaan' => $nilai,
                'satuan' => 'mm',
                'standard_id' => $standar->id,
                'input_source' => 'manual',
                'is_verified' => true,
            ]);
        }

        $this->sesi->refresh();
    }

    /** @param  array<string, mixed>  $ubah */
    private function payload(array $ubah = []): array
    {
        return [
            'equipment_id' => $this->sesi->equipment_id,
            'standard_id' => $this->sesi->standard_id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [
                ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]],
            ],
            ...$ubah,
        ];
    }

    public function test_koreksi_angka_tanpa_alasan_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/calibrations/{$this->sesi->id}", $this->payload([
                'measurements' => [
                    ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.05, 50.01, 50.03]],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('alasan_koreksi');

        $this->assertEqualsWithDelta(
            50.02,
            (float) $this->sesi->rawMeasurements()->where('pembacaan_ke', 1)->value('pembacaan'),
            1e-9,
            'angka tetap berubah walau permintaannya ditolak',
        );
    }

    /**
     * Menyimpan ulang TANPA mengubah angka tidak dimintai alasan. Kalau
     * dimintai, yang dilatih bukan ketelitian tapi kebiasaan mengetik "ok"
     * supaya lolos — dan jejak berisi "ok" lebih buruk daripada jejak kosong.
     */
    public function test_edit_header_tanpa_ubah_angka_tidak_perlu_alasan(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/calibrations/{$this->sesi->id}", $this->payload([
                'alat_serial_number' => 'SN-DIBETULIN',
            ]))
            ->assertOk();

        $this->assertSame('SN-DIBETULIN', $this->sesi->fresh()->alat_serial_number);
        $this->assertSame(0, AuditLog::where('note', 'like', 'Koreksi pembacaan%')->count());
    }

    public function test_angka_lama_dan_baru_tersimpan_berikut_alasannya(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/calibrations/{$this->sesi->id}", $this->payload([
                'measurements' => [
                    ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.05, 50.01, 50.03]],
                ],
                'alasan_koreksi' => 'Salah ketik digit kedua; angka benar dibaca ulang dari lembar kertas.',
            ]))
            ->assertOk();

        $log = AuditLog::where('note', 'like', 'Koreksi pembacaan%')->latest('id')->first();

        $this->assertNotNull($log, 'koreksi angka tidak meninggalkan jejak');
        $this->assertSame($this->admin->id, $log->changed_by);
        $this->assertStringContainsString('Salah ketik digit kedua', (string) $log->note);

        $lama = array_column((array) $log->old_data['pembacaan'], 'pembacaan');
        $baru = array_column((array) $log->new_data['pembacaan'], 'pembacaan');

        // Angka LAMA masih bisa dibaca sesudah barisnya ditimpa — itu inti
        // klausul 7.5.2.
        $this->assertEqualsWithDelta(50.02, (float) $lama[0], 1e-9);
        $this->assertEqualsWithDelta(50.05, (float) $baru[0], 1e-9);
        $this->assertEqualsWithDelta(50.01, (float) $lama[1], 1e-9, 'baris yang tidak dikoreksi ikut terpotret');

        // Dan angka yang berlaku sekarang memang yang baru.
        $this->assertEqualsWithDelta(
            50.05,
            (float) $this->sesi->rawMeasurements()->where('pembacaan_ke', 1)->value('pembacaan'),
            1e-9,
        );
    }

    /**
     * Hitungannya ikut berubah, bukan cuma angka mentahnya. Kalau
     * `uncertainty_calculations` masih memegang angka lama, yang tercetak di
     * sertifikat bukan angka yang barusan dikoreksi — tanpa satu pun error.
     */
    public function test_hitungan_ikut_diperbarui_sesudah_koreksi(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/calibrations/{$this->sesi->id}", $this->payload([
                'measurements' => [
                    ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.05, 50.05, 50.05]],
                ],
                'alasan_koreksi' => 'Tiga pembacaan tertukar dengan titik lain; diganti sesuai lembar kertas.',
            ]))
            ->assertOk();

        $this->assertEqualsWithDelta(
            50.05,
            (float) $this->sesi->fresh()->uncertaintyCalculations()->first()->rata_rata,
            1e-9,
        );
    }

    /** Teknisi tetap tidak bisa menyentuh sesi yang sudah dikirim, dengan atau tanpa alasan. */
    public function test_teknisi_tidak_bisa_mengoreksi_sesi_terkirim(): void
    {
        $teknisi = User::find($this->sesi->teknisi_id);

        $this->actingAs($teknisi)
            ->putJson("/api/calibrations/{$this->sesi->id}", $this->payload([
                'alasan_koreksi' => 'Mau membetulkan angka sendiri sesudah dikirim.',
                'measurements' => [
                    ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.09, 50.01, 50.03]],
                ],
            ]))
            ->assertStatus(422);
    }
}
