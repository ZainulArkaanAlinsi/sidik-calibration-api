<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaketAcuanVersi;
use App\Models\User;
use App\Services\Acuan\SumberAcuan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kunci baris data acuan boleh memuat garis miring: satuan Flowmeter `m3/h`,
 * `usg/min`; konversi Tekanan `kg/cm2`; pembagi resolusi `1/2`. Rute riwayat
 * sel menaruh kunci itu di PATH (`/sel/{lembar}/{kunci}/{kolom}/riwayat`).
 * Klien mengirim kunci ter-encode (`m3%2Fh`); server wajib menemukannya,
 * bukan 404 karena `/` memecah segmen rute.
 */
class DataAcuanRiwayatSelKunciGarisMiringTest extends TestCase
{
    use RefreshDatabase;

    private function v1(Organization $org, string $kode): PaketAcuanVersi
    {
        SumberAcuan::lupakanCache();
        $this->artisan('acuan:impor-awal', ['profil' => $kode, '--organisasi' => $org->id])->assertSuccessful();

        return PaketAcuanVersi::query()->where('organization_id', $org->id)->where('nomor_versi', 1)->latest('id')->firstOrFail();
    }

    public function test_kunci_bergaris_miring_di_path_riwayat_sel(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN]);
        $flow = $this->v1($org, 'flowmeter');
        $tekanan = $this->v1($org, 'tekanan');
        $isiFlow = $flow->isiTerurai();
        $isiTekanan = $tekanan->isiTerurai();

        foreach ([
            [$flow, 'satuan_flowrate', 'm3/h', 'faktor', $isiFlow['satuan']['flowrate']['m3/h']],
            [$flow, 'satuan_flowrate', 'usg/min', 'faktor', $isiFlow['satuan']['flowrate']['usg/min']],
            [$tekanan, 'druck07g_konversi', 'kg/cm2', 'faktor', 98.0665],
            [$tekanan, 'pembagi_resolusi', '1/10', 'pembagi', $isiTekanan['pembagi_resolusi']['1/10']],
        ] as [$versi, $lembar, $kunci, $kolom, $harap]) {
            $this->actingAs($admin)
                ->getJson("/api/data-acuan/versi/{$versi->id}/sel/{$lembar}/".rawurlencode($kunci)."/{$kolom}/riwayat")
                ->assertOk()
                ->assertJsonPath('data.kunci', $kunci)
                ->assertJsonPath('data.riwayat.0.ada', true)
                ->assertJsonPath('data.riwayat.0.nilai', $harap);
        }
    }
}
