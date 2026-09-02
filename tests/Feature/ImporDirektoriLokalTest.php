<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DirektoriLokal;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `direktori:impor-lokal` — pintu masuk direktori rujukan.
 *
 * Dua janji yang dikunci di sini, dan dua-duanya gagal tanpa error kalau
 * dilanggar: impor ulang tidak boleh menggandakan baris, dan impor satu sumber
 * tidak boleh menyentuh sumber lain.
 */
class ImporDirektoriLokalTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $sampah = [];

    protected function tearDown(): void
    {
        foreach ($this->sampah as $p) {
            @unlink($p);
        }

        parent::tearDown();
    }

    private function csv(string $isi): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dir').'.csv';
        file_put_contents($path, $isi);
        $this->sampah[] = $path;

        return $path;
    }

    public function test_baris_masuk_dengan_nama_normal_dan_sumbernya(): void
    {
        $berkas = $this->csv("ref,nama,alamat,kota,provinsi\njbk-1,PT. Maju  Jaya,Jl. A,Kabupaten Bekasi,Jawa Barat\n");

        $this->artisan('direktori:impor-lokal', ['berkas' => $berkas, '--sumber' => 'jababeka'])
            ->assertSuccessful();

        $baris = DirektoriLokal::sole();

        $this->assertSame('PT. Maju Jaya', $baris->nama);
        $this->assertSame('pt maju jaya', $baris->nama_normal);
        $this->assertSame('jababeka', $baris->sumber);
        $this->assertSame('Kabupaten Bekasi', $baris->kota);
    }

    public function test_jalan_dua_kali_tidak_menggandakan_baris(): void
    {
        $berkas = $this->csv("ref,nama\njbk-1,PT Maju Jaya\njbk-2,PT Sinar Abadi\n");

        $this->artisan('direktori:impor-lokal', ['berkas' => $berkas, '--sumber' => 'jababeka'])->assertSuccessful();
        $this->assertSame(2, DirektoriLokal::count());

        $this->artisan('direktori:impor-lokal', ['berkas' => $berkas, '--sumber' => 'jababeka'])->assertSuccessful();
        $this->assertSame(2, DirektoriLokal::count());
    }

    public function test_impor_ulang_memperbarui_baris_yang_sama(): void
    {
        // Yang dibeli: memperbarui satu sumber cukup menjalankan ulang
        // perintahnya, tanpa mengosongkan tabel dulu.
        $this->artisan('direktori:impor-lokal', [
            'berkas' => $this->csv("ref,nama,alamat\njbk-1,PT Maju Jaya,Jl. Lama 1\n"),
            '--sumber' => 'jababeka',
        ])->assertSuccessful();

        $this->artisan('direktori:impor-lokal', [
            'berkas' => $this->csv("ref,nama,alamat\njbk-1,PT Maju Jaya,Jl. Baru 99\n"),
            '--sumber' => 'jababeka',
        ])->assertSuccessful();

        $this->assertSame(1, DirektoriLokal::count());
        $this->assertSame('Jl. Baru 99', DirektoriLokal::sole()->alamat);
    }

    public function test_ref_yang_sama_di_sumber_berbeda_hidup_berdampingan(): void
    {
        // Unique-nya `(sumber, ref)`, bukan `ref` sendirian. Dua direktori yang
        // kebetulan menomori barisnya dari 1 tidak boleh saling menimpa.
        $this->artisan('direktori:impor-lokal', [
            'berkas' => $this->csv("ref,nama\n1,PT Dari Jababeka\n"),
            '--sumber' => 'jababeka',
        ])->assertSuccessful();

        $this->artisan('direktori:impor-lokal', [
            'berkas' => $this->csv("ref,nama\n1,PT Dari Indonetwork\n"),
            '--sumber' => 'indonetwork',
        ])->assertSuccessful();

        $this->assertSame(2, DirektoriLokal::count());
    }

    public function test_uji_coba_tidak_menulis_apa_pun(): void
    {
        $this->artisan('direktori:impor-lokal', [
            'berkas' => $this->csv("ref,nama\njbk-1,PT Maju Jaya\n"),
            '--sumber' => 'jababeka',
            '--uji-coba' => true,
        ])->assertSuccessful();

        $this->assertSame(0, DirektoriLokal::count());
    }

    public function test_sumber_di_luar_daftar_ditolak(): void
    {
        $this->artisan('direktori:impor-lokal', [
            'berkas' => $this->csv("ref,nama\njbk-1,PT Maju Jaya\n"),
            '--sumber' => 'entah',
        ])->assertFailed();

        $this->assertSame(0, DirektoriLokal::count());
    }

    public function test_berkas_tanpa_kolom_wajib_ditolak_dengan_pesan(): void
    {
        $this->artisan('direktori:impor-lokal', [
            'berkas' => $this->csv("kode,perusahaan\nA1,PT Maju Jaya\n"),
            '--sumber' => 'jababeka',
        ])->assertFailed();

        $this->assertSame(0, DirektoriLokal::count());
    }

    public function test_baris_tanpa_nama_atau_ref_dilewat_bukan_menjatuhkan_impor(): void
    {
        // Berkas direktori selalu punya baris kosong di ujungnya.
        $this->artisan('direktori:impor-lokal', [
            'berkas' => $this->csv("ref,nama\njbk-1,PT Maju Jaya\n,PT Tanpa Ref\njbk-3,\n\n"),
            '--sumber' => 'jababeka',
        ])->assertSuccessful();

        $this->assertSame(1, DirektoriLokal::count());
    }

    public function test_impor_tidak_menyentuh_customers_sama_sekali(): void
    {
        // Batas yang paling menentukan: kalau bocor, sepuluh ribu baris tanpa
        // penanggung jawab ikut tersalin ke HP tiap teknisi.
        Organization::factory()->create();
        Customer::factory()->create(['nama' => 'PT Pelanggan Asli']);

        $this->artisan('direktori:impor-lokal', [
            'berkas' => $this->csv("ref,nama\njbk-1,PT Maju Jaya\njbk-2,PT Sinar Abadi\n"),
            '--sumber' => 'jababeka',
        ])->assertSuccessful();

        $this->assertSame(1, Customer::count());
        $this->assertSame('PT Pelanggan Asli', Customer::sole()->nama);
    }

    public function test_berkas_tidak_ada_gagal_dengan_pesan_bukan_exception(): void
    {
        $this->artisan('direktori:impor-lokal', [
            'berkas' => '/tmp/tidak-ada-berkas-ini.csv',
            '--sumber' => 'jababeka',
        ])->assertFailed();
    }
}
