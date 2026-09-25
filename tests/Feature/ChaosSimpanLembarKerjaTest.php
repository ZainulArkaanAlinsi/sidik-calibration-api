<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Chaos review 25 Sep 2026 — simpan lembar kerja dari HP.
 *
 * Steady state yang dijaga: pembacaan yang sudah tercatat tidak hilang tanpa
 * jejak, kiriman ganda dari HP bersinyal buruk tidak pernah berujung 500, dan
 * angka yang sudah pernah disubmit tetap bisa ditelusuri sesudah direvisi
 * (ISO/IEC 17025 klausul 7.5.2, AGENTS.md §Peran butir 5).
 */
class ChaosSimpanLembarKerjaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Organization::factory()->create();

        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();

        // Alat TANPA profil, sama dengan `CalibrationTest`: yang diuji jalur
        // `measurements` datar. Alat berprofil blok (Sieve dkk) memang
        // mengirim `measurements: []` dengan datanya di `spesifikasi_alat`.
        $this->alat = Equipment::factory()->create([
            'nama_alat' => 'Mistar Baja Mitutoyo',
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang', 'nama' => 'Panjang'])->id,
            'satuan' => 'mm',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);

        $this->standar = Standard::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $ubah
     * @return array<string, mixed>
     */
    private function payload(array $ubah = []): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_ruang' => 23.5,
            'kelembaban' => 55.0,
            'measurements' => [
                ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]],
            ],
            ...$ubah,
        ];
    }

    /** @param  array<string, mixed>  $ubah */
    private function buatSesi(array $ubah = []): CalibrationSession
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload($ubah))
            ->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    /**
     * Gangguan F3-1: HP mengirim `measurements: []` untuk sesi yang sudah
     * punya pembacaan — tabel di memori HP kosong karena gagal dimuat atau
     * ter-reset, sementara mode "simpan header saja" (kunci dihilangkan) tidak
     * dipakai.
     *
     * Aturannya `sometimes|array|max:60`, jadi array kosong lolos, lalu
     * `isiUlangPengukuran()` menghapus semua baris tanpa syarat.
     */
    public function test_measurements_kosong_tidak_menghapus_pembacaan_yang_sudah_ada(): void
    {
        $sesi = $this->buatSesi(['status' => 'draft']);
        $this->assertSame(3, RawMeasurement::where('calibration_session_id', $sesi->id)->count());

        $respons = $this->actingAs($this->teknisi)->putJson(
            "/api/calibrations/{$sesi->id}",
            $this->payload(['status' => 'draft', 'measurements' => []]),
        );

        $this->assertSame(
            3,
            RawMeasurement::where('calibration_session_id', $sesi->id)->count(),
            "Pembacaan terhapus permanen oleh kiriman kosong (respons {$respons->status()}).",
        );
        $respons->assertStatus(422);
        $this->assertNotEmpty($respons->json('message'), 'Teknisi tidak diberi tahu kenapa simpannya ditolak.');
    }

    /**
     * Gangguan F3-1b: formulir yang gagal memuat draft lama, lalu disimpan.
     *
     * `_muatSesiLama()` di HP menelan galatnya (`catch (_) {}`), jadi layar
     * tetap kosong tanpa pesan. Tabel datar tetap mengirim barisnya — set
     * point bawaan ada, angkanya kosong semua. Susunannya TIDAK nol baris, jadi
     * penjaga F3-1 tidak menangkapnya; yang diuji di sini apakah angka lama
     * ditimpa baris kosong.
     */
    public function test_formulir_kosong_tidak_menimpa_angka_yang_sudah_ada(): void
    {
        $sesi = $this->buatSesi(['status' => 'draft']);

        $respons = $this->actingAs($this->teknisi)->putJson(
            "/api/calibrations/{$sesi->id}",
            $this->payload(['status' => 'draft', 'measurements' => [
                ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [null, null, null]],
            ]]),
        );

        $this->assertEqualsCanonicalizing(
            [50.01, 50.02, 50.03],
            RawMeasurement::where('calibration_session_id', $sesi->id)->whereNotNull('pembacaan')
                ->pluck('pembacaan')->map(fn ($v): float => (float) $v)->all(),
            "Angka yang sudah tercatat ditimpa kiriman tanpa angka (respons {$respons->status()}).",
        );
        // Titik tanpa satu angka pun tidak menjadi baris mentah, jadi yang
        // menahan kiriman ini penjaga susunan-nol-baris yang sama dengan F3-1 —
        // dipastikan, bukan diasumsikan.
        $respons->assertStatus(422)->assertJsonValidationErrors(['measurements']);
        $this->assertStringContainsString('nggak ada yang dihapus', (string) $respons->json('message'));
    }

    /** Kontrol F3-1: sesi BARU tanpa pembacaan tetap boleh disimpan kosong. */
    public function test_sesi_baru_tanpa_pembacaan_tetap_boleh_disimpan(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload(['status' => 'draft', 'measurements' => []]))
            ->assertCreated();
    }

    /**
     * Gangguan F3-2: teknisi merevisi sesi `perlu_revisi` — sesi yang SUDAH
     * pernah disubmit dan dibaca admin — dan angka lamanya ditimpa.
     *
     * Jejak koreksi dulu cuma menyala untuk admin yang menyunting sesi
     * `menunggu_approval`. Angka yang dilihat admin waktu mengembalikan
     * lembarnya lenyap tanpa bekas.
     */
    public function test_revisi_teknisi_sesudah_dikembalikan_menyimpan_angka_lama(): void
    {
        $sesi = $this->buatSesi();
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->status);

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/reject", ['catatan_revisi' => 'Pembacaan kedua janggal, cek ulang.'])
            ->assertOk();

        $this->actingAs($this->teknisi)->putJson("/api/calibrations/{$sesi->id}", $this->payload([
            'measurements' => [
                ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.04, 50.03]],
            ],
        ]))->assertOk();

        $jejak = AuditLog::where('entity_type', 'calibration_sessions')
            ->where('entity_id', $sesi->id)
            ->get()
            ->first(fn (AuditLog $a): bool => isset($a->old_data['pembacaan']));

        $this->assertNotNull($jejak, 'Angka yang sudah pernah disubmit hilang tanpa jejak sesudah direvisi.');
        $this->assertContains(50.01, array_column($jejak->old_data['pembacaan'], 'pembacaan'));
        $this->assertContains(50.04, array_column($jejak->new_data['pembacaan'], 'pembacaan'));
        $this->assertSame($this->teknisi->id, $jejak->changed_by);
        $this->assertStringContainsString('Pembacaan kedua janggal', (string) $jejak->note);
    }

    /** Kontrol F3-2: kiriman ulang yang angkanya SAMA tidak melahirkan jejak palsu. */
    public function test_revisi_tanpa_perubahan_angka_tidak_dijejak(): void
    {
        $sesi = $this->buatSesi();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/reject", ['catatan_revisi' => 'Nomor seri alat salah ketik.'])
            ->assertOk();

        $this->actingAs($this->teknisi)
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload())
            ->assertOk();

        $this->assertFalse(
            AuditLog::where('entity_type', 'calibration_sessions')
                ->where('entity_id', $sesi->id)
                ->get()
                ->contains(fn (AuditLog $a): bool => isset($a->old_data['pembacaan'])),
            'Jejak revisi lahir padahal tidak ada satu angka pun yang berubah.',
        );
    }

    /** Kontrol F3-2: draft yang belum pernah disubmit tidak dibanjiri jejak. */
    public function test_draft_yang_belum_pernah_disubmit_tidak_dijejak(): void
    {
        $sesi = $this->buatSesi(['status' => 'draft']);

        $this->actingAs($this->teknisi)->putJson("/api/calibrations/{$sesi->id}", $this->payload([
            'status' => 'draft',
            'measurements' => [
                ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.04, 50.03]],
            ],
        ]))->assertOk();

        $this->assertFalse(
            AuditLog::where('entity_type', 'calibration_sessions')
                ->where('entity_id', $sesi->id)
                ->get()
                ->contains(fn (AuditLog $a): bool => isset($a->old_data['pembacaan'])),
        );
    }

    /**
     * Gangguan F3-3: dua kiriman dengan `client_request_id` yang sama
     * berbarengan — HP kena timeout lalu mengirim ulang, sementara kiriman
     * pertama masih diproses.
     *
     * Kiriman pertama disimulasikan commit TEPAT sesudah kiriman kedua lolos
     * pemeriksaan `replay()`. Unique index-nya yang menyelamatkan data; yang
     * diuji jawabannya ke HP.
     */
    public function test_kiriman_ganda_berbarengan_dijawab_replay_bukan_500(): void
    {
        $idKiriman = (string) Str::uuid();
        $pemenang = null;

        DB::listen(function (QueryExecuted $q) use (&$pemenang, $idKiriman): void {
            if ($pemenang !== null || ! str_starts_with(strtolower($q->sql), 'select') || ! str_contains($q->sql, 'client_request_id')) {
                return;
            }

            $pemenang = false;
            $pemenang = CalibrationSession::factory()->create([
                'equipment_id' => $this->alat->id,
                'teknisi_id' => $this->teknisi->id,
                'client_request_id' => $idKiriman,
                'status' => CalibrationSession::STATUS_DRAFT,
            ]);
        });

        $respons = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload(['client_request_id' => $idKiriman]));

        $this->assertInstanceOf(CalibrationSession::class, $pemenang, 'Gangguannya tidak tersuntik — eksperimen tidak sah.');
        $this->assertSame(1, CalibrationSession::where('client_request_id', $idKiriman)->count());
        $this->assertNotSame(500, $respons->status(), 'HP dapat 500 untuk kiriman yang sebenarnya sudah tersimpan.');
        $respons->assertOk()->assertJsonPath('data.id', $pemenang->id);
    }
}
