<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\FolderFile;
use App\Models\Order;
use App\Models\Organization;
use App\Services\FolderOrganizer;
use App\Services\Pencarian\CariDokumenLab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DokumenArsipUji;
use Tests\TestCase;

/**
 * `CariDokumenLab`: satu pencarian untuk sertifikat, sesi, alat, dan berkas.
 * Diuji langsung ke layanannya (kueri nyata di DB), bukan lewat HTTP -- cakupan
 * HTTP tiap layar ada di tes layar masing-masing.
 */
class CariDokumenLabTest extends TestCase
{
    use DokumenArsipUji, RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
    }

    private function cari(): CariDokumenLab
    {
        return app(CariDokumenLab::class);
    }

    /** @return list<int> id sertifikat yang cocok, urut peringkat lalu id menurun */
    private function sertifikat(string $q, ?Organization $org = null, ?int $pelangganId = null): array
    {
        $org ??= $this->org;

        return $this->cari()
            ->sertifikat(Certificate::query()->where('certificates.organization_id', $org->id), $org->id, $q, pelangganId: $pelangganId)
            ->orderByDesc('certificates.id')
            ->get()->pluck('id')->all();
    }

    /** @return list<int> */
    private function sesi(string $q, ?Organization $org = null): array
    {
        $org ??= $this->org;

        return $this->cari()
            ->sesi(CalibrationSession::query()->where('calibration_sessions.organization_id', $org->id), $org->id, $q)
            ->orderByDesc('calibration_sessions.id')
            ->get()->pluck('id')->all();
    }

    /** @return list<int> */
    private function alat(string $q, ?Organization $org = null): array
    {
        $org ??= $this->org;

        return $this->cari()
            ->alat(Equipment::query()->where('equipments.organization_id', $org->id), $org->id, $q)
            ->orderByDesc('equipments.id')
            ->get()->pluck('id')->all();
    }

    public function test_nomor_sertifikat_dinormalisasi_slash_strip_spasi_dan_huruf(): void
    {
        $d = $this->bangunDokumen($this->org, ['nomor' => 'CAL/2026/10/0001']);
        $this->bangunDokumen($this->org, ['nomor' => 'CAL/2026/10/0002']);

        foreach (['CAL/2026/10/0001', 'cal-2026-10-0001', 'cal 2026 10 0001', 'CAL202610 0001', 'cal/2026-10 0001', '  CAL/2026/10/0001  '] as $diketik) {
            $this->assertSame([$d['sertifikat']->id], $this->sertifikat($diketik), "q = '{$diketik}'");
        }

        $this->assertSame([], $this->sertifikat('CAL/2026/10/0003'));
    }

    public function test_revisi_ikut_dan_nomor_dasar_didahulukan(): void
    {
        $dasar = $this->bangunDokumen($this->org, ['nomor' => 'CAL/2026/10/0001']);
        $revisi = $this->bangunDokumen($this->org, ['nomor' => 'CAL/2026/10/0001-R1']);
        $this->bangunDokumen($this->org, ['nomor' => 'CAL/2026/10/0100']);

        // Nomor dasar menemukan dasar (persis, peringkat 0) lalu revisinya (awalan).
        $this->assertSame(
            [$dasar['sertifikat']->id, $revisi['sertifikat']->id],
            $this->sertifikat('cal-2026-10-0001'),
        );
        // Mencari revisi hanya menemukan revisi itu.
        $this->assertSame([$revisi['sertifikat']->id], $this->sertifikat('CAL/2026/10/0001-R1'));
    }

    public function test_serial_persis_lalu_awalan_lalu_mengandung(): void
    {
        $mengandung = $this->bangunDokumen($this->org, ['serial' => 'AB123']);
        $persis = $this->bangunDokumen($this->org, ['serial' => '123']);
        $mengandung2 = $this->bangunDokumen($this->org, ['serial' => 'X123Y']);
        $awalan = $this->bangunDokumen($this->org, ['serial' => '1234']);

        // Dibuat dengan id naik; kalau peringkat tak bekerja, urutannya id menurun.
        $this->assertSame(
            [
                $persis['sertifikat']->id,
                $awalan['sertifikat']->id,
                $mengandung2['sertifikat']->id,
                $mengandung['sertifikat']->id,
            ],
            $this->sertifikat('123'),
        );
    }

    public function test_serial_persis_tidak_peka_huruf_besar_kecil(): void
    {
        $awalan = $this->bangunDokumen($this->org, ['serial' => 'ABC-10']);
        $persis = $this->bangunDokumen($this->org, ['serial' => 'abc-1']);

        // Tanpa pembandingan tak-peka-huruf, 'abc-1' bukan "persis" di SQLite
        // dan urutannya jatuh ke id menurun (awalan dulu).
        $this->assertSame([$persis['sertifikat']->id, $awalan['sertifikat']->id], $this->sertifikat('ABC-1'));
    }

    public function test_serial_yang_diketik_teknisi_di_lembar_ikut_dicari(): void
    {
        $d = $this->bangunDokumen($this->org, ['sesi' => ['alat_serial_number' => 'LEMBAR-77']]);

        $this->assertSame([$d['sertifikat']->id], $this->sertifikat('lembar-77'));
    }

    public function test_nomor_order_dari_order_dan_dari_sesi_dengan_normalisasi(): void
    {
        $dariOrder = $this->bangunDokumen($this->org, ['order' => 'ORD/2026/11/0042']);
        $dariSesi = $this->bangunDokumen($this->org, ['nomor_order' => 'PO-2026-77']);

        $this->assertSame([$dariOrder['sertifikat']->id], $this->sertifikat('ORD/2026/11/0042'));
        $this->assertSame([$dariOrder['sertifikat']->id], $this->sertifikat('ord-2026-11-0042'));
        $this->assertSame([$dariSesi['sertifikat']->id], $this->sertifikat('po 2026 77'));
    }

    public function test_nama_pt_nama_alat_dan_merek_ditemukan(): void
    {
        $d = $this->bangunDokumen($this->org, ['pt' => 'PT Sintetis Alfa', 'alat' => 'Busur Derajat Digital']);
        $this->bangunDokumen($this->org, ['pt' => 'PT Sintetis Beta']);

        $this->assertSame([$d['sertifikat']->id], $this->sertifikat('sintetis alfa'));
        $this->assertSame([$d['sertifikat']->id], $this->sertifikat('derajat DIGITAL'));
    }

    public function test_persen_dan_garis_bawah_diperlakukan_sebagai_huruf_biasa(): void
    {
        $garisBawah = $this->bangunDokumen($this->org, ['serial' => 'A_B']);
        $bukan = $this->bangunDokumen($this->org, ['serial' => 'AXB']);
        $persen = $this->bangunDokumen($this->org, ['serial' => '100%X']);

        $this->assertSame([$garisBawah['sertifikat']->id], $this->sertifikat('A_B'));
        $this->assertSame([$persen['sertifikat']->id], $this->sertifikat('100%'));
        // `%` saja bukan pola "cocokkan semuanya": tidak ada yang berisi `%`
        // selain satu serial itu.
        $this->assertSame([$persen['sertifikat']->id], $this->sertifikat('%'));
        $this->assertNotContains($bukan['sertifikat']->id, $this->sertifikat('A_B'));
    }

    public function test_kata_cari_yang_hanya_pemisah_tidak_mencocokkan_semuanya(): void
    {
        $this->bangunDokumen($this->org, ['nomor' => 'CAL/2026/10/0001', 'serial' => 'SERI1']);
        $this->bangunDokumen($this->org, ['nomor' => 'CAL/2026/10/0002', 'serial' => 'SERI2']);

        // '/' dan '-' ada di KEDUA nomor, tetapi hanya sebagai pemisah: tanpa
        // bentuk ternormalisasi, kolom nomor dilewati (kalau tidak, `LIKE '%%'`
        // mencocokkan semuanya), dan kolom lain tidak berisi karakter itu.
        $this->assertSame([], $this->sertifikat('/'));
        $this->assertSame([], $this->sertifikat(' - '));
    }

    public function test_tidak_menembus_organisasi_lain(): void
    {
        $labLain = Organization::factory()->create();
        $punyaSaya = $this->bangunDokumen($this->org, ['serial' => 'SAMA-1', 'nomor' => 'CAL/2026/10/0001', 'order' => 'ORD/SAMA/1']);
        $punyaLain = $this->bangunDokumen($labLain, ['serial' => 'SAMA-1', 'nomor' => 'CAL/2026/10/0001-X', 'order' => 'ORD/SAMA/9']);

        foreach (['SAMA-1', 'CAL/2026/10/0001', 'ORD/SAMA'] as $q) {
            $this->assertSame([$punyaSaya['sertifikat']->id], $this->sertifikat($q), "sertifikat q={$q}");
            $this->assertSame([$punyaLain['sertifikat']->id], $this->sertifikat($q, $labLain), "sertifikat lab lain q={$q}");
        }

        $this->assertSame([$punyaSaya['sesi']->id], $this->sesi('SAMA-1'));
        $this->assertSame([$punyaSaya['alat']->id], $this->alat('SAMA-1'));
    }

    public function test_alat_yang_dihapus_lunak_tetap_dicari_tetapi_order_yang_dihapus_tidak(): void
    {
        $d = $this->bangunDokumen($this->org, ['serial' => 'HAPUS-SERI-1', 'order' => 'ORD/HAPUS/1', 'hapus_alat' => true]);

        $this->assertSame([$d['sertifikat']->id], $this->sertifikat('HAPUS-SERI-1'));
        $this->assertSame([$d['sertifikat']->id], $this->sertifikat('ORD/HAPUS/1'));

        $d['order']->delete();
        $this->assertSame([], $this->sertifikat('ORD/HAPUS/1'), 'Order terhapus tidak lagi dicocokkan.');
    }

    public function test_pagar_pelanggan_mengabaikan_order_milik_pelanggan_lain_dan_nama_pt(): void
    {
        $a = $this->bangunDokumen($this->org, ['pt' => 'PT Pagar Satu']);
        $b = $this->bangunDokumen($this->org, ['pt' => 'PT Pagar Dua', 'order' => 'ORD/PAGAR/2']);
        // Data rusak: sesi A menunjuk item order milik PT B.
        $a['sesi']->update(['order_item_id' => $b['order']->items()->first()->id]);

        $this->assertContains($a['sertifikat']->id, $this->sertifikat('ORD/PAGAR/2'));
        $this->assertNotContains($a['sertifikat']->id, $this->sertifikat('ORD/PAGAR/2', pelangganId: $a['pt']->id));
        $this->assertContains($b['sertifikat']->id, $this->sertifikat('ORD/PAGAR/2', pelangganId: $b['pt']->id));
        // Nama PT sendiri tidak dicari untuk pelanggan.
        $this->assertSame([], $this->sertifikat('Pagar Satu', pelangganId: $a['pt']->id));
        $this->assertSame([$a['sertifikat']->id], $this->sertifikat('Pagar Satu'));
    }

    public function test_sesi_dicari_lewat_nomor_sertifikat_dan_revisi_tidak_menggandakan_baris(): void
    {
        $d = $this->bangunDokumen($this->org, ['nomor' => 'CAL/2026/10/0001']);
        // Revisi: sertifikat kedua pada SESI YANG SAMA.
        Certificate::factory()->create([
            'organization_id' => $this->org->id,
            'calibration_session_id' => $d['sesi']->id,
            'nomor' => 'CAL/2026/10/0001-R1',
            'revision_of' => $d['sertifikat']->id,
            'revisi_ke' => 1,
        ]);

        $this->assertSame([$d['sesi']->id], $this->sesi('cal-2026-10-0001'));
        $this->assertSame([$d['sesi']->id], $this->sesi('CAL/2026/10/0001-R1'));
        // Nomor sesi sendiri ikut dicari (yang dulu disaring di HP).
        $this->assertSame([$d['sesi']->id], $this->sesi((string) $d['sesi']->nomor_sesi));
    }

    public function test_alat_nomor_sertifikat_dan_order_hanya_persis_serial_boleh_sebagian(): void
    {
        $d = $this->bangunDokumen($this->org, ['serial' => 'ALAT-SERI-9', 'nomor' => 'CAL/2026/10/0001', 'order' => 'ORD/ALAT/5']);
        $lain = $this->bangunDokumen($this->org, ['serial' => 'LAIN-1', 'nomor' => 'CAL/2026/10/0002']);

        $this->assertSame([$d['alat']->id], $this->alat('cal-2026-10-0001'));
        $this->assertSame([$d['alat']->id], $this->alat('ORD/ALAT/5'));
        $this->assertSame([$d['alat']->id], $this->alat('seri-9'));
        // Potongan nomor tidak memunculkan alat (menghindari derau di pemilih alat).
        $this->assertSame([], $this->alat('2026'));
        $this->assertSame([], $this->alat('ORD/ALAT'));
        $this->assertNotContains($lain['alat']->id, $this->alat('cal-2026-10-0001'));
    }

    public function test_berkas_ikut_mencocokkan_nomor_sertifikat_serial_dan_order(): void
    {
        $d = $this->bangunDokumen($this->org, ['nomor' => 'CAL/2026/10/0001', 'serial' => 'BERKAS-SERI-1', 'order' => 'ORD/BERKAS/1']);
        $d['sertifikat']->load('session.equipment.customer');
        $berkas = app(FolderOrganizer::class)->tautkanSertifikat($d['sertifikat']);
        $unggahan = FolderFile::factory()->create([
            'organization_id' => $this->org->id,
            'folder_id' => $berkas->folder_id,
            'certificate_id' => null,
            'sumber' => FolderFile::SUMBER_UNGGAHAN,
            'nama' => 'surat-jalan-oktober.pdf',
        ]);

        $this->assertSame('Sertifikat-CAL-2026-10-0001.pdf', $berkas->nama);

        $cariBerkas = fn (string $q): array => $this->cari()
            ->berkas(FolderFile::query()->where('folder_files.organization_id', $this->org->id), $this->org->id, $q)
            ->orderByDesc('folder_files.id')->get()->pluck('id')->all();

        // Sebelumnya mengetik nomor dengan '/' tidak menemukan nama berkas ber-'-'.
        $this->assertSame([$berkas->id], $cariBerkas('CAL/2026/10/0001'));
        $this->assertSame([$berkas->id], $cariBerkas('cal 2026 10 0001'));
        $this->assertSame([$berkas->id], $cariBerkas('BERKAS-SERI-1'));
        $this->assertSame([$berkas->id], $cariBerkas('ORD/BERKAS/1'));
        // Pencarian nama berkas lama tetap jalan, termasuk berkas unggahan.
        $this->assertSame([$unggahan->id], $cariBerkas('surat-jalan'));
        $this->assertSame([$berkas->id], $cariBerkas('Sertifikat-CAL'));
    }
}
