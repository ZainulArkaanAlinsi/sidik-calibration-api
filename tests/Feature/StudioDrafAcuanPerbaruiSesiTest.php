<?php

namespace Tests\Feature;

use App\Models\PaketAcuanVersi;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Services\Studio\PetaSel\PetaSel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SesiDataAcuan;
use Tests\TestCase;

/**
 * `PUT /api/studio/workbook/{workbook}/draf/{versi}` (A1, B4) di atas data seeder
 * penuh + versi 1 Micrometer hasil `acuan:impor-awal`: menyunting draf berkali-kali
 * TIDAK mengubah hasil hitung, stempel versi, maupun versi berlaku sesi mana pun --
 * sesi lama (berstempel v1) dan sesi baru sesudah PUT tetap dihitung v1.
 * Pasangan `StudioDrafAcuanBuatSesiTest` (POST). Perkakas: `SesiDataAcuan`.
 */
class StudioDrafAcuanPerbaruiSesiTest extends TestCase
{
    use RefreshDatabase;
    use SesiDataAcuan;

    public function test_menyunting_draf_tidak_mengubah_hasil_stempel_atau_versi_berlaku(): void
    {
        $this->siapkanAcuan();
        config(['studio.draf_acuan_aktif' => true, 'studio.peta_sel.direktori' => database_path('data/peta-sel')]);

        $organisasiId = (int) $this->teknisi->organization_id;
        $studio = User::query()->where('organization_id', $organisasiId)->where('role', User::ROLE_ADMIN)->firstOrFail();
        $peta = PetaSel::untuk('micrometer') ?? $this->fail('peta micrometer tidak ada');
        $wb = WorkbookMaster::create([
            'organization_id' => $organisasiId, 'kode_profil' => 'micrometer', 'nama' => 'Master_Olah_Data_Micrometer_25-50mm',
            'sumber' => WorkbookMaster::SUMBER_CSV, 'sha256' => hash('sha256', 'wb-sesi'), 'ukuran' => 1, 'jumlah_lembar' => 1, 'aktif' => true,
        ]);

        $idLama = $this->simpanSesi($this->payloadMicrometer());
        $stempelLama = $this->stempelSesi($idLama);
        $angkaLama = $this->angkaSesi($idLama);
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $stempelLama);
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$idLama]])->assertSuccessful();
        $ulangSebelum = $this->angkaSesi($idLama);

        $hitungan = UncertaintyCalculation::query()->count();
        $v1 = $this->v1->fresh()->getAttributes();
        $berlaku = app(PenentuVersiAcuan::class)->untukProfil($organisasiId, 'micrometer', now())?->id;
        $this->assertSame($this->v1->id, $berlaku);

        $this->app['auth']->forgetGuards();
        $this->sebagaiStudio($studio);
        $post = $this->postJson('/api/studio/workbook/'.$wb->id.'/draf', [
            'workbook_sha256' => $wb->sha256,
            'peta_sel_sha256' => $peta->sha256,
            'base_isi_sha256' => PenentuVersiAcuan::sha256($this->v1),
            'alasan' => 'Uji: draf tidak boleh menyentuh hasil hitung',
            'suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => '0.99']],
        ])->assertCreated();

        // Dua kali PUT berturut-turut (hash baru dari respons sebelumnya).
        $hash = $post->json('data.hash.isi');
        $versiId = $post->json('data.versi.id');

        foreach ([['DATABASE!T6', '0.97'], ['DATABASE!T7', '0.96']] as [$sel, $nilai]) {
            $put = $this->putJson('/api/studio/workbook/'.$wb->id.'/draf/'.$versiId, [
                'workbook_sha256' => $wb->sha256,
                'peta_sel_sha256' => $peta->sha256,
                'expected_isi_sha256' => $hash,
                'alasan' => 'Uji: suntingan lanjutan pada draf',
                'suntingan' => [['sel' => $sel, 'nilai' => $nilai]],
            ])->assertOk()->assertJsonPath('data.status', 'draf_diperbarui');
            $hash = $put->json('data.hash.isi');
        }

        // Tidak ada hasil hitung baru, versi 1 identik, versi berlaku tetap v1.
        $this->assertSame($hitungan, UncertaintyCalculation::query()->count());
        $this->assertSame($v1, $this->v1->fresh()->getAttributes());
        $this->assertSame($berlaku, app(PenentuVersiAcuan::class)->untukProfil($organisasiId, 'micrometer', now())?->id);
        $this->assertSame(PaketAcuanVersi::STATUS_DRAF, PaketAcuanVersi::query()->findOrFail($versiId)->status);

        // Sesi lama: stempel & angka (tersimpan DAN hitung ulang) tidak bergeser.
        $this->assertSame($stempelLama, $this->stempelSesi($idLama));
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$idLama]])->assertSuccessful();
        $this->assertSame($stempelLama, $this->stempelSesi($idLama));
        $this->assertEquals($ulangSebelum, $this->angkaSesi($idLama));

        // Sesi baru sesudah PUT: tetap v1, angka sama dengan sesi lama (payload sama).
        $this->app['auth']->forgetGuards();
        $idBaru = $this->simpanSesi($this->payloadMicrometer());
        $this->assertSame($stempelLama, $this->stempelSesi($idBaru));
        $this->assertEquals($angkaLama, $this->angkaSesi($idBaru));
    }
}
