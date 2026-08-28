<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DokumenGenerikApiTest extends TestCase
{
    use RefreshDatabase;

    private function teknisi(): User
    {
        return User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'role' => 'teknisi',
            'status' => 'aktif',
        ]);
    }

    private function foto(): UploadedFile
    {
        return UploadedFile::fake()->image('lembar.jpg', 1200, 1600);
    }

    private function pasangJawaban(array $isi): void
    {
        config([
            'services.vision.driver' => 'anthropic',
            'services.vision.aktif' => true,
            'services.anthropic.api_key' => 'kunci-uji',
            'services.anthropic.model' => 'model-uji',
        ]);

        Http::fake(['*/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($isi)]],
            'usage' => ['input_tokens' => 1500, 'output_tokens' => 400],
        ])]);
    }

    /** Inti fitur: lembar yang belum punya profil TETAP menghasilkan form. */
    public function test_lembar_tanpa_profil_tetap_menghasilkan_skema_form(): void
    {
        $this->pasangJawaban([
            'document' => [
                'title' => 'Calibration Worksheet - Viscometer Rotasi',
                'equipment_name' => 'Viscometer Rotasi',
                'worksheet_code' => 'SIDIK-FM-CAL-0999',
                'revision' => 'Rev.2',
                'confidence' => 0.93,
            ],
            'sections' => [[
                'name' => 'Spindle Measurement',
                'fields' => [[
                    'label' => 'Spindle No', 'value' => 'S-62',
                    'confidence' => 0.95, 'source' => 'handwriting',
                ]],
                'tables' => [[
                    'headers' => ['RPM', 'Standard', 'Reading'],
                    'cells' => [
                        ['row' => 0, 'column' => 0, 'value' => '10', 'confidence' => 0.97, 'source' => 'static_document'],
                        ['row' => 0, 'column' => 2, 'value' => '998,4', 'confidence' => 0.91, 'source' => 'handwriting'],
                    ],
                ]],
            ]],
            'warnings' => [],
        ]);

        $r = $this->actingAs($this->teknisi())
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()]);

        $r->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.dokumen.equipment_name', 'Viscometer Rotasi')
            ->assertJsonPath('data.dokumen.worksheet_code', 'SIDIK-FM-CAL-0999')
            ->assertJsonPath('data.bagian.0.nama', 'Spindle Measurement')
            ->assertJsonPath('data.bagian.0.field.0.label', 'Spindle No');

        $tabel = $r->json('data.bagian.0.tabel.0');

        $this->assertCount(3, $tabel['kolom']);
        $this->assertSame('998,4', $tabel['baris'][0][2]['nilai']);
        // Sel (0,1) nggak dilaporkan model -> ADA tapi kosong & perlu review.
        $this->assertNull($tabel['baris'][0][1]['nilai']);
        $this->assertSame('REVIEW_REQUIRED', $tabel['baris'][0][1]['status']);
        $this->assertSame(1, $r->json('data.ringkasan.perlu_review'));
    }

    /**
     * Saklar privasinya SATU, dipakai bareng AI Vision. Kalau endpoint ini
     * punya saklar sendiri, lab yang sudah menutup pengiriman foto tetap
     * ngirim lewat sini tanpa sadar.
     */
    public function test_vision_aktif_false_menutup_endpoint_ini_juga(): void
    {
        $this->pasangJawaban(['document' => [], 'sections' => [], 'warnings' => []]);
        config(['services.vision.aktif' => false]);

        $r = $this->actingAs($this->teknisi())
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()]);

        $r->assertStatus(503)->assertJsonPath('status', 'dimatikan');

        // Yang paling penting: fotonya NGGAK PERNAH dikirim ke mana pun.
        Http::assertNothingSent();
    }

    public function test_tanpa_login_ditolak(): void
    {
        $this->postJson('/api/dokumen/baca', ['foto' => $this->foto()])
            ->assertUnauthorized();
    }

    public function test_foto_wajib_dan_harus_gambar(): void
    {
        $teknisi = $this->teknisi();

        $this->actingAs($teknisi)
            ->postJson('/api/dokumen/baca', [])
            ->assertStatus(422)->assertJsonValidationErrors('foto');

        $this->actingAs($teknisi)
            ->postJson('/api/dokumen/baca', [
                'foto' => UploadedFile::fake()->create('bukan-gambar.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422)->assertJsonValidationErrors('foto');
    }

    public function test_nama_alat_diterima_sebagai_string_bebas(): void
    {
        $this->pasangJawaban([
            'document' => ['equipment_name' => 'Alat Yang Belum Pernah Ada'],
            'sections' => [], 'warnings' => [],
        ]);

        // Nama alat apa pun diterima — nggak ada daftar tetap yang membatasi
        // kemampuan sistem.
        $this->actingAs($this->teknisi())
            ->postJson('/api/dokumen/baca', [
                'foto' => $this->foto(),
                'nama_alat' => 'Alat Yang Belum Pernah Ada',
            ])
            ->assertOk()
            ->assertJsonPath('data.dokumen.equipment_name', 'Alat Yang Belum Pernah Ada');
    }

    public function test_alat_pilihan_beda_dari_lembar_jadi_peringatan_bukan_penolakan(): void
    {
        $this->pasangJawaban([
            'document' => ['equipment_name' => 'Turbidimeter'],
            'sections' => [], 'warnings' => [],
        ]);

        $r = $this->actingAs($this->teknisi())
            ->postJson('/api/dokumen/baca', [
                'foto' => $this->foto(),
                'nama_alat' => 'pH Meter',
            ]);

        $r->assertOk();
        $this->assertNotEmpty(array_filter(
            $r->json('data.peringatan'),
            fn ($p) => str_contains($p, 'Turbidimeter'),
        ));
    }

    public function test_kunci_api_kosong_dibedakan_dari_gagal_baca(): void
    {
        config([
            'services.vision.driver' => 'anthropic',
            'services.vision.aktif' => true,
            'services.anthropic.api_key' => '',
        ]);

        $this->actingAs($this->teknisi())
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()])
            ->assertStatus(503)
            ->assertJsonPath('status', 'salah_setup');
    }

    public function test_layanan_sibuk_tidak_menyuruh_foto_ulang(): void
    {
        config([
            'services.vision.driver' => 'anthropic',
            'services.vision.aktif' => true,
            'services.anthropic.api_key' => 'kunci-uji',
            'services.anthropic.model' => 'model-uji',
        ]);

        Http::fake(['*/v1/messages' => Http::response(['error' => ['message' => 'overloaded']], 503)]);

        $r = $this->actingAs($this->teknisi())
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()]);

        $r->assertStatus(422);
        $this->assertStringContainsString('nggak perlu diulang', $r->json('pesan'));
    }

    public function test_tidak_ada_pengukuran_yang_lahir_di_jalur_ini(): void
    {
        $this->pasangJawaban([
            'document' => ['equipment_name' => 'X'],
            'sections' => [['name' => 'A', 'fields' => [
                ['label' => 'a', 'value' => '1', 'confidence' => 0.99],
            ], 'tables' => []]],
            'warnings' => [],
        ]);

        $this->actingAs($this->teknisi())
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()])
            ->assertOk();

        // PEMBACAANNYA memang disimpan (lihat DokumenBacaanSimpanTest) — yang
        // NGGAK lahir di sini itu pengukurannya. Menyimpan "kamera membaca ini"
        // bukan mengesahkan "angka ini benar": `raw_measurements` tetap lahir
        // dari `POST/PUT /calibrations` sesudah teknisi mengoreksi.
        $this->assertDatabaseCount('raw_measurements', 0);
        // Jalur bertemplate juga nggak ikut kesenggol.
        $this->assertDatabaseCount('worksheet_scans', 0);
    }
}
