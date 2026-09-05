<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\DokumenBacaan;
use App\Models\DokumenBacaanNilai;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hasil baca satu lab nggak pernah kelihatan — atau kesentuh — dari lab lain.
 *
 * ## Kenapa berkas ini ada, padahal "kan sudah disaring"
 *
 * Proyek ini nggak punya global scope: tiap saringan `organization_id` ditulis
 * tangan. Konsekuensinya, query yang LUPA disaring tetap jalan, tetap balas
 * 200, dan tetap hijau di test biasa — karena database hari ini masih satu
 * organisasi, hasil yang bocor dan hasil yang benar isinya sama persis.
 *
 * Artinya nggak ada satu pun cara menemukan lubang ini dengan cara mencoba.
 * Yang bisa menangkapnya cuma test yang SENGAJA bikin dua organisasi. Tanpa
 * berkas ini, "sudah saya saring" cuma klaim.
 *
 * Yang dijaga di sini hari onboarding lab kedua.
 */
class DokumenBacaanTidakBocorAntarLabTest extends TestCase
{
    use RefreshDatabase;

    private function teknisi(Organization $org): User
    {
        return User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
            'status' => 'aktif',
        ]);
    }

    private function bacaanMilik(User $user): DokumenBacaan
    {
        $bacaan = DokumenBacaan::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'nama_alat' => 'Viscometer Rotasi',
            'kode_dokumen' => 'SIDIK-FM-CAL-0999',
            'status' => DokumenBacaan::STATUS_PERLU_REVIEW,
            'jumlah_field' => 1,
            'jumlah_sel' => 0,
            'perlu_review' => 1,
            'skema' => ['bagian' => [['kunci' => 'bagian-0', 'nama' => 'RAHASIA LAB LAIN']]],
        ]);

        DokumenBacaanNilai::create([
            'dokumen_bacaan_id' => $bacaan->id,
            'kunci' => 'bagian-0.field-0',
            'jenis' => DokumenBacaanNilai::JENIS_FIELD,
            'label' => 'Serial Number',
            'nilai_baca' => 'SN-RAHASIA',
            'status' => DokumenBacaanNilai::STATUS_PERLU_REVIEW,
        ]);

        return $bacaan;
    }

    public function test_buka_bacaan_lab_lain_dijawab_404_bukan_403(): void
    {
        $labA = Organization::factory()->create();
        $labB = Organization::factory()->create();

        $bacaanA = $this->bacaanMilik($this->teknisi($labA));

        // 404, BUKAN 403: 403 mengakui barisnya ada, dan itu sendiri sudah
        // membocorkan keberadaan data lab lain.
        $this->actingAs($this->teknisi($labB))
            ->getJson("/api/dokumen/bacaan/{$bacaanA->id}")
            ->assertNotFound();
    }

    public function test_koreksi_bacaan_lab_lain_ditolak_dan_datanya_utuh(): void
    {
        $labA = Organization::factory()->create();
        $labB = Organization::factory()->create();

        $bacaanA = $this->bacaanMilik($this->teknisi($labA));

        $this->actingAs($this->teknisi($labB))
            ->postJson("/api/dokumen/bacaan/{$bacaanA->id}/koreksi", [
                'koreksi' => [['kunci' => 'bagian-0.field-0', 'nilai_final' => 'DIUBAH']],
            ])
            ->assertNotFound();

        // Ditolak aja nggak cukup — nilainya harus benar-benar nggak berubah.
        $this->assertNull(
            DokumenBacaanNilai::where('kunci', 'bagian-0.field-0')->firstOrFail()->nilai_final,
        );
    }

    public function test_teknisi_lain_di_lab_yang_sama_dijawab_403_bukan_404(): void
    {
        $lab = Organization::factory()->create();

        $bacaan = $this->bacaanMilik($this->teknisi($lab));

        // Beda dari kasus lintas-lab: barisnya memang ada dan dia berhak tahu
        // itu, cuma bukan miliknya.
        $this->actingAs($this->teknisi($lab))
            ->getJson("/api/dokumen/bacaan/{$bacaan->id}")
            ->assertForbidden();
    }

    public function test_bacaan_baru_selalu_pakai_organisasi_user_bukan_dari_input(): void
    {
        $labA = Organization::factory()->create();
        $labB = Organization::factory()->create();

        config([
            'services.vision.driver' => 'anthropic',
            'services.vision.aktif' => true,
            'services.anthropic.api_key' => 'kunci-uji',
            'services.anthropic.model' => 'model-uji',
        ]);

        Http::fake(['*/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'document' => ['equipment_name' => 'X'],
                'sections' => [], 'warnings' => [],
            ])]],
        ])]);

        $teknisiA = $this->teknisi($labA);

        // `organization_id` di body itu parameter serangan, bukan data.
        $this->actingAs($teknisiA)
            ->postJson('/api/dokumen/baca', [
                'foto' => UploadedFile::fake()->image('lembar.jpg'),
                'organization_id' => $labB->id,
            ])
            ->assertOk();

        $this->assertSame($labA->id, DokumenBacaan::firstOrFail()->organization_id);
    }

    public function test_sesi_milik_lab_lain_tidak_bisa_ditautkan(): void
    {
        $labA = Organization::factory()->create();
        $labB = Organization::factory()->create();

        $teknisiB = $this->teknisi($labB);

        $sesiB = CalibrationSession::factory()->create([
            'organization_id' => $labB->id,
            'teknisi_id' => $teknisiB->id,
        ]);

        config([
            'services.vision.driver' => 'anthropic',
            'services.vision.aktif' => true,
            'services.anthropic.api_key' => 'kunci-uji',
            'services.anthropic.model' => 'model-uji',
        ]);

        Http::fake(['*/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"document":{},"sections":[],"warnings":[]}']],
        ])]);

        // Teknisi lab A nebak id sesi lab B.
        $this->actingAs($this->teknisi($labA))
            ->postJson('/api/dokumen/baca', [
                'foto' => UploadedFile::fake()->image('lembar.jpg'),
                'calibration_session_id' => $sesiB->id,
            ])
            ->assertNotFound();

        // Ditolak SEBELUM fotonya berangkat ke mana pun.
        Http::assertNothingSent();
        $this->assertDatabaseCount('dokumen_bacaan', 0);
    }

    public function test_daftar_nilai_dibaca_lewat_induk_yang_tersaring(): void
    {
        $labA = Organization::factory()->create();
        $labB = Organization::factory()->create();

        $this->bacaanMilik($this->teknisi($labA));
        $bacaanB = $this->bacaanMilik($teknisiB = $this->teknisi($labB));

        $r = $this->actingAs($teknisiB)
            ->getJson("/api/dokumen/bacaan/{$bacaanB->id}")
            ->assertOk();

        // Dua lab punya kunci yang SAMA PERSIS (`bagian-0.field-0`). Kalau
        // nilainya dikueri dari akar tabelnya, punya lab A bisa ikut kebawa
        // tanpa satu pun kolom yang kelihatan salah.
        $this->assertCount(1, $r->json('nilai'));
        $this->assertSame($bacaanB->id, DokumenBacaanNilai::where('kunci', 'bagian-0.field-0')
            ->where('dokumen_bacaan_id', $bacaanB->id)->firstOrFail()->dokumen_bacaan_id);
    }
}
