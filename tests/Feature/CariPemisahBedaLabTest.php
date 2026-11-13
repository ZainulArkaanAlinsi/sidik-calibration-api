<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\FolderFile;
use App\Models\Organization;
use App\Models\User;
use App\Services\FolderOrganizer;
use App\Services\Pencarian\CariDokumenLab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DokumenArsipUji;
use Tests\TestCase;

/**
 * Pencarian terpadu lab (`CariDokumenLab`) tidak peduli pemisah: nomor SERI
 * kini dinormalisasi seperti nomor sertifikat dan nomor order, dan `.` serta
 * `_` ikut menjadi pemisah (13 Nov 2026).
 *
 * Yang sudah dijaga `CariDokumenLabTest` (nomor sertifikat dan order dengan `/`,
 * `-`, spasi; `%` harfiah; peringkat; isolasi lab) tidak diulang di sini.
 */
class CariPemisahBedaLabTest extends TestCase
{
    use DokumenArsipUji, RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
    }

    private function cari(): CariDokumenLab
    {
        return app(CariDokumenLab::class);
    }

    /** @return list<int> */
    private function sertifikat(string $q, ?Organization $org = null): array
    {
        $org ??= $this->org;

        return $this->cari()
            ->sertifikat(Certificate::query()->where('certificates.organization_id', $org->id), $org->id, $q)
            ->orderByDesc('certificates.id')
            ->get()->pluck('id')->all();
    }

    /** @return list<int> */
    private function sesi(string $q): array
    {
        return $this->cari()
            ->sesi(CalibrationSession::query()->where('calibration_sessions.organization_id', $this->org->id), $this->org->id, $q)
            ->orderByDesc('calibration_sessions.id')
            ->get()->pluck('id')->all();
    }

    /** @return list<int> */
    private function alat(string $q): array
    {
        return $this->cari()
            ->alat(Equipment::query()->where('equipments.organization_id', $this->org->id), $this->org->id, $q)
            ->orderByDesc('equipments.id')
            ->get()->pluck('id')->all();
    }

    public function test_nomor_seri_berpemisah_beda_ditemukan_di_sertifikat_sesi_dan_alat(): void
    {
        $d = $this->bangunDokumen($this->org, ['serial' => 'SN-123/4']);
        $this->bangunDokumen($this->org, ['serial' => 'SN-999/9']);

        foreach (['SN-123/4', 'sn 123 4', 'SN.123.4', 'sn_123_4', 'sn/123-4', '  SN - 123 / 4  '] as $diketik) {
            $this->assertSame([$d['sertifikat']->id], $this->sertifikat($diketik), "sertifikat q = '{$diketik}'");
            $this->assertSame([$d['sesi']->id], $this->sesi($diketik), "sesi q = '{$diketik}'");
            $this->assertSame([$d['alat']->id], $this->alat($diketik), "alat q = '{$diketik}'");
        }

        $this->assertSame([], $this->sertifikat('SN-123/5'));
    }

    public function test_serial_yang_diketik_teknisi_di_lembar_juga_dinormalisasi(): void
    {
        $d = $this->bangunDokumen($this->org, ['sesi' => ['alat_serial_number' => 'LEMBAR/77-B']]);

        $this->assertSame([$d['sertifikat']->id], $this->sertifikat('lembar 77 b'));
        $this->assertSame([$d['sesi']->id], $this->sesi('LEMBAR.77.B'));
    }

    public function test_serial_persis_tetap_didahulukan_walau_pemisah_diketik_lain(): void
    {
        $mengandung = $this->bangunDokumen($this->org, ['serial' => 'X123-4Y']);
        $persis = $this->bangunDokumen($this->org, ['serial' => '123-4']);
        $mengandung2 = $this->bangunDokumen($this->org, ['serial' => 'AB-123/4']);
        $awalan = $this->bangunDokumen($this->org, ['serial' => '123-45']);

        // Peringkat memutus lebih dulu; kalau tak bekerja urutannya id menurun.
        $this->assertSame(
            [
                $persis['sertifikat']->id,
                $awalan['sertifikat']->id,
                $mengandung2['sertifikat']->id,
                $mengandung['sertifikat']->id,
            ],
            $this->sertifikat('123 4'),
        );
    }

    public function test_peringkat_serial_alat_dan_sesi_ikut_pemisah_yang_diketik_lain(): void
    {
        $mengandung = $this->bangunDokumen($this->org, ['serial' => 'X123-4Y']);
        $persis = $this->bangunDokumen($this->org, ['serial' => '123-4']);
        $awalan = $this->bangunDokumen($this->org, ['serial' => '123-45']);

        // Tanpa peringkat urutannya id menurun: awalan, persis, mengandung.
        $this->assertSame(
            [$persis['alat']->id, $awalan['alat']->id, $mengandung['alat']->id],
            $this->alat('123 4'),
        );
        $this->assertSame(
            [$persis['sesi']->id, $awalan['sesi']->id, $mengandung['sesi']->id],
            $this->sesi('123 4'),
        );
    }

    public function test_berkas_dicari_dan_diperingkat_lewat_serial_berpemisah_beda(): void
    {
        $persis = $this->bangunDokumen($this->org, ['serial' => 'SERI-3']);
        $mengandung = $this->bangunDokumen($this->org, ['serial' => 'XSERI/3Y']);
        $this->bangunDokumen($this->org, ['serial' => 'LAIN-9']);

        $pengorganisasi = app(FolderOrganizer::class);
        foreach ([$persis, $mengandung] as $dokumen) {
            $dokumen['sertifikat']->load('session.equipment.customer');
        }
        $berkasPersis = $pengorganisasi->tautkanSertifikat($persis['sertifikat']);
        $berkasMengandung = $pengorganisasi->tautkanSertifikat($mengandung['sertifikat']);

        $hasil = $this->cari()
            ->berkas(FolderFile::query()->where('folder_files.organization_id', $this->org->id), $this->org->id, 'seri.3')
            ->orderByDesc('folder_files.id')
            ->get()->pluck('id')->all();

        // Persis dibuat lebih dulu: kalau peringkat tak bekerja, urutannya id menurun.
        $this->assertSame([$berkasPersis->id, $berkasMengandung->id], $hasil);
    }

    public function test_titik_dan_garis_bawah_adalah_pemisah_nomor_sertifikat_dan_order(): void
    {
        $d = $this->bangunDokumen($this->org, ['nomor' => 'CAL/2026/10/0001', 'order' => 'ORD/2026/11/0042']);
        $sesiOrder = $this->bangunDokumen($this->org, ['nomor_order' => 'PO-2026-77']);

        foreach (['CAL.2026.10.0001', 'cal_2026_10_0001', 'CAL-2026.10_0001'] as $diketik) {
            $this->assertSame([$d['sertifikat']->id], $this->sertifikat($diketik), "q = '{$diketik}'");
        }
        $this->assertSame([$d['sertifikat']->id], $this->sertifikat('ORD.2026.11.0042'));
        $this->assertSame([$sesiOrder['sertifikat']->id], $this->sertifikat('po_2026_77'));
    }

    public function test_data_yang_menyimpan_titik_atau_garis_bawah_ditemukan_dengan_pemisah_lain(): void
    {
        $d = $this->bangunDokumen($this->org, ['serial' => 'A_B.7', 'nomor' => 'CAL.2026.10.0003']);

        $this->assertSame([$d['sertifikat']->id], $this->sertifikat('a-b-7'));
        $this->assertSame([$d['sertifikat']->id], $this->sertifikat('CAL/2026/10/0003'));
        // `AXB7` tidak ikut: `_` yang diketik pemisah, bukan wildcard satu karakter.
        $this->bangunDokumen($this->org, ['serial' => 'AXB7']);
        $this->assertSame([$d['sertifikat']->id], $this->sertifikat('A_B7'));
    }

    public function test_kata_yang_hanya_titik_atau_garis_bawah_tidak_mencocokkan_semuanya(): void
    {
        $this->bangunDokumen($this->org, ['nomor' => 'CAL/2026/10/0001', 'serial' => 'SERI.1']);
        $this->bangunDokumen($this->org, ['nomor' => 'CAL/2026/10/0002', 'serial' => 'SERI_2']);

        foreach (['.', '_', ' . _ ', '..', '- . -'] as $diketik) {
            $this->assertSame([], $this->sertifikat($diketik), "q = '{$diketik}'");
            $this->assertSame([], $this->sesi($diketik), "q = '{$diketik}'");
        }
    }

    public function test_pemisah_longgar_tidak_menembus_lab_lain(): void
    {
        $labLain = Organization::factory()->create();
        $punyaSaya = $this->bangunDokumen($this->org, ['serial' => 'SAMA-1/A', 'nomor' => 'CAL/2026/10/0001']);
        $punyaLain = $this->bangunDokumen($labLain, ['serial' => 'SAMA-1/A', 'nomor' => 'CAL/2026/10/0001']);

        foreach (['sama 1 a', 'SAMA.1.A', 'cal-2026-10-0001'] as $q) {
            $this->assertSame([$punyaSaya['sertifikat']->id], $this->sertifikat($q), "lab saya q={$q}");
            $this->assertSame([$punyaLain['sertifikat']->id], $this->sertifikat($q, $labLain), "lab lain q={$q}");
        }
        $this->assertSame([$punyaSaya['alat']->id], $this->alat('sama 1 a'));
    }

    // --- lewat HTTP: dua rute lab yang memakai pencarian ini -------------------------

    public function test_telusuri_menemukan_serial_dan_nomor_berpemisah_beda(): void
    {
        $d = $this->bangunDokumen($this->org, ['serial' => 'SN-0204/B', 'nomor' => 'CAL/2026/11/0004']);
        $this->bangunDokumen($this->org, ['serial' => 'SN-0999/B', 'nomor' => 'CAL/2026/11/0005']);

        foreach (['sn 0204 b', 'SN.0204.B', 'cal.2026.11.0004'] as $q) {
            $res = $this->actingAs($this->admin)->getJson('/api/sertifikat/telusuri?q='.urlencode($q))->assertOk();

            $this->assertSame([$d['sertifikat']->id], array_column($res->json('data'), 'id'), "q = '{$q}'");
        }
    }

    public function test_pencarian_alat_menemukan_serial_berpemisah_beda(): void
    {
        $d = $this->bangunDokumen($this->org, ['serial' => 'ALAT-55/C']);
        $this->bangunDokumen($this->org, ['serial' => 'ALAT-66/C']);

        $res = $this->actingAs($this->admin)->getJson('/api/equipments?search='.urlencode('alat 55 c'))->assertOk();

        $this->assertSame([$d['alat']->id], array_column($res->json('data'), 'id'));
    }
}
