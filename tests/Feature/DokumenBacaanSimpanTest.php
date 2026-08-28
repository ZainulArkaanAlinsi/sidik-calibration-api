<?php

namespace Tests\Feature;

use App\Models\DokumenBacaan;
use App\Models\DokumenBacaanNilai;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Penyimpanan hasil baca generik: pembacaan tersimpan, koreksi tercatat
 * berpasangan, dan batas antar-lab tetap utuh.
 */
class DokumenBacaanSimpanTest extends TestCase
{
    use RefreshDatabase;

    private function teknisi(?Organization $org = null): User
    {
        return User::factory()->create([
            'organization_id' => ($org ?? Organization::factory()->create())->id,
            'role' => User::ROLE_TEKNISI,
            'status' => 'aktif',
        ]);
    }

    private function foto(): UploadedFile
    {
        return UploadedFile::fake()->image('lembar.jpg', 1200, 1600);
    }

    private function pasangJawaban(?array $isi = null): void
    {
        config([
            'services.vision.driver' => 'anthropic',
            'services.vision.aktif' => true,
            'services.anthropic.api_key' => 'kunci-uji',
            'services.anthropic.model' => 'model-uji',
        ]);

        Http::fake(['*/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($isi ?? $this->lembarViscometer())]],
            'usage' => ['input_tokens' => 1500, 'output_tokens' => 400],
        ])]);
    }

    /** @return array<string, mixed> */
    private function lembarViscometer(): array
    {
        return [
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
                    'headers' => ['RPM', 'Reading'],
                    'cells' => [
                        ['row' => 0, 'column' => 0, 'value' => '10',
                            'confidence' => 0.97, 'source' => 'static_document'],
                        ['row' => 0, 'column' => 1, 'value' => '998,4',
                            'confidence' => 0.42, 'source' => 'handwriting',
                            'bbox' => ['x' => 10, 'y' => 20, 'width' => 30, 'height' => 12]],
                    ],
                ]],
            ]],
            'warnings' => [],
        ];
    }

    public function test_pembacaan_dan_tiap_nilainya_tersimpan(): void
    {
        $this->pasangJawaban();
        $teknisi = $this->teknisi();

        $r = $this->actingAs($teknisi)
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()]);

        $r->assertOk();

        $bacaan = DokumenBacaan::firstOrFail();

        $this->assertSame($teknisi->organization_id, $bacaan->organization_id);
        $this->assertSame($teknisi->id, $bacaan->user_id);
        $this->assertSame('Viscometer Rotasi', $bacaan->nama_alat);
        $this->assertSame('SIDIK-FM-CAL-0999', $bacaan->kode_dokumen);
        $this->assertSame('model-uji', $bacaan->model);
        // Ada sel keyakinan rendah -> statusnya perlu_review, bukan ok.
        $this->assertSame(DokumenBacaan::STATUS_PERLU_REVIEW, $bacaan->status);
        $this->assertSame($r->json('id'), $bacaan->id);

        // 1 field + 2 sel.
        $this->assertSame(3, $bacaan->nilai()->count());

        $sel = $bacaan->nilai()->where('kunci', 'bagian-0.tabel-0.sel-0-1')->firstOrFail();

        $this->assertSame(DokumenBacaanNilai::JENIS_SEL, $sel->jenis);
        $this->assertSame('Reading', $sel->label);
        $this->assertSame(0, $sel->baris_ke);
        $this->assertSame(1, $sel->kolom_ke);
        $this->assertSame('handwriting', $sel->sumber);
        $this->assertSame(DokumenBacaanNilai::STATUS_PERLU_REVIEW, $sel->status);
        // Belum dikoreksi sama sekali -> null, BUKAN false.
        $this->assertNull($sel->cocok);
        // `kotak` harus jadi array beneran, bukan teks "Array" —
        // `insert()` massal nggak lewat cast Eloquent.
        $this->assertSame(10, (int) $sel->kotak['x']);
    }

    public function test_angka_disimpan_apa_adanya_termasuk_komanya(): void
    {
        $this->pasangJawaban();

        $this->actingAs($this->teknisi())
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()])
            ->assertOk();

        $sel = DokumenBacaanNilai::where('kunci', 'bagian-0.tabel-0.sel-0-1')->firstOrFail();

        $this->assertSame(
            '998,4',
            $sel->nilai_baca,
            'komanya harus utuh biar bisa dibandingin langsung sama kertasnya',
        );
    }

    public function test_teks_bukan_angka_tetap_tersimpan_utuh(): void
    {
        $this->pasangJawaban();

        $this->actingAs($this->teknisi())
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()])
            ->assertOk();

        // Kalau kolomnya desimal, `S-62` bakal jadi null atau 0.
        $this->assertSame(
            'S-62',
            DokumenBacaanNilai::where('kunci', 'bagian-0.field-0')->firstOrFail()->nilai_baca,
        );
    }

    public function test_koreksi_menyimpan_pasangannya_tanpa_menimpa_bacaan(): void
    {
        $this->pasangJawaban();
        $teknisi = $this->teknisi();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()])
            ->json('id');

        $this->actingAs($teknisi)
            ->postJson("/api/dokumen/bacaan/{$id}/koreksi", [
                'koreksi' => [
                    ['kunci' => 'bagian-0.tabel-0.sel-0-1', 'nilai_final' => '998,7'],
                    ['kunci' => 'bagian-0.field-0', 'nilai_final' => 'S-62'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('cocok', 1)
            ->assertJsonPath('meleset', 1);

        $sel = DokumenBacaanNilai::where('kunci', 'bagian-0.tabel-0.sel-0-1')->firstOrFail();

        // INI intinya: bacaan aslinya nggak ketimpa. Ketimpa, bukti bahwa
        // modelnya salah — dan salahnya jadi apa — hilang selamanya.
        $this->assertSame('998,4', $sel->nilai_baca);
        $this->assertSame('998,7', $sel->nilai_final);
        $this->assertFalse($sel->cocok);
        $this->assertSame($teknisi->id, $sel->dikoreksi_oleh);
        $this->assertNotNull($sel->dikoreksi_pada);
    }

    public function test_koma_versus_titik_tidak_dihitung_meleset(): void
    {
        $this->pasangJawaban();
        $teknisi = $this->teknisi();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()])
            ->json('id');

        // Teknisi ngetik ulang pakai titik — itu angka yang SAMA, bukan koreksi.
        $this->actingAs($teknisi)
            ->postJson("/api/dokumen/bacaan/{$id}/koreksi", [
                'koreksi' => [['kunci' => 'bagian-0.tabel-0.sel-0-1', 'nilai_final' => '998.4']],
            ])
            ->assertOk()
            ->assertJsonPath('cocok', 1)
            ->assertJsonPath('meleset', 0);
    }

    public function test_kunci_asing_dilaporkan_bukan_dibuang_diam_diam(): void
    {
        $this->pasangJawaban();
        $teknisi = $this->teknisi();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()])
            ->json('id');

        $this->actingAs($teknisi)
            ->postJson("/api/dokumen/bacaan/{$id}/koreksi", [
                'koreksi' => [['kunci' => 'bagian-9.tabel-9.sel-9-9', 'nilai_final' => '1']],
            ])
            ->assertOk()
            ->assertJsonPath('kunci_tidak_dikenal', ['bagian-9.tabel-9.sel-9-9']);
    }

    public function test_koreksi_teks_bukan_angka_diterima(): void
    {
        $this->pasangJawaban();
        $teknisi = $this->teknisi();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()])
            ->json('id');

        // Validasi `numeric` bakal nolak ini — padahal separuh isi lembar
        // generik memang bukan angka.
        $this->actingAs($teknisi)
            ->postJson("/api/dokumen/bacaan/{$id}/koreksi", [
                'koreksi' => [['kunci' => 'bagian-0.field-0', 'nilai_final' => 'Fluke 123']],
            ])
            ->assertOk();

        $this->assertSame(
            'Fluke 123',
            DokumenBacaanNilai::where('kunci', 'bagian-0.field-0')->firstOrFail()->nilai_final,
        );
    }

    public function test_buka_ulang_pakai_skema_tersimpan_tanpa_manggil_ai_lagi(): void
    {
        $this->pasangJawaban();
        $teknisi = $this->teknisi();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()])
            ->json('id');

        Http::fake();  // panggilan AI apa pun sesudah ini bakal kelihatan

        $this->actingAs($teknisi)
            ->getJson("/api/dokumen/bacaan/{$id}")
            ->assertOk()
            ->assertJsonPath('data.bagian.0.nama', 'Spindle Measurement');

        Http::assertNothingSent();
    }

    public function test_pembacaan_gagal_tidak_menyimpan_baris(): void
    {
        config([
            'services.vision.driver' => 'anthropic',
            'services.vision.aktif' => true,
            'services.anthropic.api_key' => 'kunci-uji',
            'services.anthropic.model' => 'model-uji',
        ]);

        Http::fake(['*/v1/messages' => Http::response(['error' => ['message' => 'overloaded']], 503)]);

        $this->actingAs($this->teknisi())
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()])
            ->assertStatus(422);

        // Yang gagal SEBELUM ada isinya nggak ninggalin baris — bedakan dari
        // yang kebaca tapi jelek, yang justru disimpan.
        $this->assertDatabaseCount('dokumen_bacaan', 0);
    }

    public function test_tidak_ada_raw_measurement_yang_lahir_di_sini(): void
    {
        $this->pasangJawaban();

        $this->actingAs($this->teknisi())
            ->postJson('/api/dokumen/baca', ['foto' => $this->foto()])
            ->assertOk();

        // Menyimpan PEMBACAAN bukan mengesahkan PENGUKURAN.
        $this->assertDatabaseCount('raw_measurements', 0);
    }
}
