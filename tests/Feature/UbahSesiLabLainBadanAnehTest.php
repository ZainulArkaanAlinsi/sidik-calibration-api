<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * QA ronde 2-B, saran peninjau #283 butir (d): `authorize()` berjalan SESUDAH
 * `prepareForValidation()` (urutan Laravel).
 *
 * `CalibrationRequest::prepareForValidation()` bisa menolak badan yang terlalu
 * besar (422) atau, untuk bentuk yang tak terduga, melempar sebelum `authorize()`
 * sempat memberi 404. Akibatnya `PUT /calibrations/{sesi lab lain}` dengan badan
 * aneh menjawab 422/500, sementara ID yang tidak ada menjawab 404: selisih itu
 * membuka keberadaan ID sesi lab lain (kelas R2-5 yang sama, lewat jalan lain).
 *
 * Yang dijaga: untuk SEMUA badan di bawah, sesi lab lain dijawab 404 -- sama
 * dengan sesi yang tidak ada -- dan sesi milik sendiri tidak pernah 500.
 */
class UbahSesiLabLainBadanAnehTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private CalibrationSession $milikSendiri;

    private CalibrationSession $milikLabLain;

    protected function setUp(): void
    {
        parent::setUp();

        $lab = Organization::factory()->create();
        $labLain = Organization::factory()->create();

        $this->admin = User::factory()->admin()->create(['organization_id' => $lab->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $lab->id, 'role' => User::ROLE_TEKNISI]);

        $this->milikSendiri = CalibrationSession::factory()->create([
            'organization_id' => $lab->id,
            'equipment_id' => Equipment::factory()->create(['organization_id' => $lab->id])->id,
            'teknisi_id' => $this->teknisi->id,
        ]);
        $this->milikLabLain = CalibrationSession::factory()->create([
            'organization_id' => $labLain->id,
            'equipment_id' => Equipment::factory()->create(['organization_id' => $labLain->id])->id,
        ]);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badanAneh(): array
    {
        $titik = fn (int $n): array => array_fill(0, $n, ['titik_ukur' => 1, 'pembacaan' => [1, 2, 3]]);

        return [
            'kosong' => [[]],
            'terlalu banyak titik (61)' => [['measurements' => $titik(61)]],
            'terlalu banyak pembacaan per titik (11)' => [['measurements' => [['titik_ukur' => 1, 'pembacaan' => range(1, 11)]]]],
            'measurements bukan array' => [['measurements' => 'bukan array']],
            'measurements angka' => [['measurements' => 12345]],
            'measurements bersarang dalam' => [['measurements' => [[[[]]]]]],
            'baris measurements berupa teks' => [['measurements' => ['a', 'b']]],
            'pembacaan berupa teks' => [['measurements' => [['titik_ukur' => 1, 'pembacaan' => 'abc']]]],
            'pembacaan berisi array' => [['measurements' => [['titik_ukur' => 1, 'pembacaan' => [[1], [2]]]]]],
            'spesifikasi_alat teks' => [['spesifikasi_alat' => 'teks']],
            'spesifikasi_alat berisi titik_sesi teks' => [['spesifikasi_alat' => ['titik_sesi' => 'x']]],
            'titik_sesi teks' => [['titik_sesi' => 'x']],
            'titik_sesi.titik teks' => [['titik_sesi' => ['titik' => 'x']]],
            'titik_sesi.titik berisi teks' => [['titik_sesi' => ['titik' => ['a', 'b']]]],
            'nomor_order array' => [['nomor_order' => [['x']]]],
            'micrometer pra_evaluasi teks' => [['spesifikasi_alat' => ['micrometer' => ['pra_evaluasi' => 'x']]]],
            'micrometer pra_evaluasi bersarang' => [['spesifikasi_alat' => ['micrometer' => ['pra_evaluasi' => [[1], [2]]]]]],
            'hydrometer teks' => [['spesifikasi_alat' => ['hydrometer' => 'x']]],
            'hydrometer diameter_stem teks' => [['spesifikasi_alat' => ['hydrometer' => ['diameter_stem' => 'x']]]],
            'dial_indicator teks' => [['spesifikasi_alat' => ['dial_indicator' => 'x']]],
            'flowmeter teks' => [['spesifikasi_alat' => ['flowmeter' => 'x']]],
            'sieve teks' => [['spesifikasi_alat' => ['sieve' => 'x']]],
            'height_gauge teks' => [['spesifikasi_alat' => ['height_gauge' => 'x']]],
            'anak_timbangan teks' => [['spesifikasi_alat' => ['anak_timbangan' => 'x']]],
            'keterulangan teks' => [['spesifikasi_alat' => ['keterulangan' => 'x']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $badan
     */
    #[DataProvider('badanAneh')]
    public function test_sesi_lab_lain_dijawab_404_sama_dengan_sesi_tak_ada_apa_pun_badannya(array $badan): void
    {
        foreach ([$this->admin, $this->teknisi] as $pelaku) {
            $lain = $this->actingAs($pelaku)->putJson("/api/calibrations/{$this->milikLabLain->id}", $badan);
            $taAda = $this->actingAs($pelaku)->putJson('/api/calibrations/99999999', $badan);

            $this->assertSame(404, $taAda->getStatusCode(), "ID tak ada harus 404 ({$pelaku->role})");
            // STATUS-nya yang dijaga di sini. Teks `message` kedua 404 itu masih berbeda
            // (ID tak ada: "Data yang dicari nggak ketemu." dari render binding; lab lain:
            // `abort(404)` tanpa pesan) -- temuan terpisah di seluruh API, bukan perkara
            // urutan `authorize()` vs `prepareForValidation()`; dilaporkan, tidak diubah di sini.
            $this->assertSame(404, $lain->getStatusCode(), "sesi lab lain harus 404 seperti ID tak ada ({$pelaku->role}): {$lain->getContent()}");

            // Pintu autoclave berbagi pola yang sama.
            $this->assertSame(404, $this->actingAs($pelaku)->putJson("/api/calibrations/autoclave/{$this->milikLabLain->id}", $badan)->getStatusCode());
        }
    }

    /**
     * @param  array<string, mixed>  $badan
     */
    #[DataProvider('badanAneh')]
    public function test_sesi_sendiri_dengan_badan_aneh_tidak_pernah_500(array $badan): void
    {
        foreach ([$this->admin, $this->teknisi] as $pelaku) {
            $status = $this->actingAs($pelaku)->putJson("/api/calibrations/{$this->milikSendiri->id}", $badan)->getStatusCode();

            $this->assertNotSame(500, $status, "{$pelaku->role}: badan aneh ke sesi sendiri menjawab 500");
        }
    }
}
