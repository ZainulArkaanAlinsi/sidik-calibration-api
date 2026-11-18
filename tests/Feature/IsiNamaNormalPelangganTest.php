<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `php artisan pelanggan:isi-nama-normal` — mengisi `customers.nama_normal` yang
 * kosong (18 Nov 2026, temuan produksi: 29 baris kosong, penjaga PT kembar mati).
 *
 * Yang dijaga dan kenapa:
 *
 * - **Dry-run bawaan** — tanpa `--jalankan` tidak satu baris pun berubah, dan
 *   dry-run melaporkan angka yang SAMA dengan `--jalankan`.
 * - **Aturan satu-satunya** — nilai dari `Customer::normalkanNama()`, bukan salinan.
 * - **Hanya mengisi yang kosong** — baris yang sudah terisi tidak disentuh.
 * - **Keluaran hanya hitungan** — nama pelanggan tidak ikut tercetak.
 * - **Jejak** — tiap baris yang terisi tercatat `Diaudit` (tanpa pelaku = konsol).
 */
class IsiNamaNormalPelangganTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
    }

    /** PT yang `nama_normal`-nya dikosongkan lewat jalur tanpa model (seperti baris produksi). */
    private function ptKosong(string $nama, ?Organization $lab = null): Customer
    {
        $pt = Customer::factory()->create(['organization_id' => ($lab ?? $this->lab)->id, 'nama' => $nama]);
        DB::table('customers')->where('id', $pt->id)->update(['nama_normal' => null]);

        return $pt;
    }

    public function test_dry_run_adalah_bawaan_dan_tidak_menulis_apa_pun(): void
    {
        $a = $this->ptKosong('PT. Maju  Jaya');
        $b = $this->ptKosong('CV Sinar Baru');
        $audit = AuditLog::query()->where('entity_type', 'customers')->count();

        $this->artisan('pelanggan:isi-nama-normal')
            ->expectsOutputToContain('dry-run')
            ->expectsOutputToContain('nama_normal kosong: 2')
            ->expectsOutputToContain('bisa diisi: 2')
            ->assertSuccessful();

        $this->assertNull(DB::table('customers')->where('id', $a->id)->value('nama_normal'));
        $this->assertNull(DB::table('customers')->where('id', $b->id)->value('nama_normal'));
        $this->assertSame($audit, AuditLog::query()->where('entity_type', 'customers')->count());

        // `--dry-run` eksplisit sama dengan bawaan.
        $this->artisan('pelanggan:isi-nama-normal', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull(DB::table('customers')->where('id', $a->id)->value('nama_normal'));
    }

    public function test_jalankan_mengisi_dengan_aturan_model_dan_mencatat_jejak(): void
    {
        $a = $this->ptKosong('PT. Maju  Jaya');
        $b = $this->ptKosong('CV Sinar Baru');

        $this->artisan('pelanggan:isi-nama-normal', ['--jalankan' => true])
            ->expectsOutputToContain('JALANKAN')
            ->expectsOutputToContain('terisi: 2')
            ->assertSuccessful();

        // Nilainya persis keluaran fungsi yang dipakai model.
        $this->assertSame(Customer::normalkanNama('PT. Maju  Jaya'), DB::table('customers')->where('id', $a->id)->value('nama_normal'));
        $this->assertSame('pt maju jaya', DB::table('customers')->where('id', $a->id)->value('nama_normal'));
        $this->assertSame('cv sinar baru', DB::table('customers')->where('id', $b->id)->value('nama_normal'));

        // `nama` tidak berubah.
        $this->assertSame('PT. Maju  Jaya', DB::table('customers')->where('id', $a->id)->value('nama'));

        // Jejak `diubah`: nama_normal null -> nilai, tanpa pelaku (konsol).
        $jejak = AuditLog::query()
            ->where('entity_type', 'customers')->where('entity_id', $a->id)->where('action', AuditLog::ACTION_DIUBAH)
            ->latest('id')->firstOrFail();
        $this->assertNull($jejak->changed_by);
        $this->assertNull($jejak->old_data['nama_normal']);
        $this->assertSame('pt maju jaya', $jejak->new_data['nama_normal']);

        // Jalan ulang: tidak ada lagi yang kosong, tidak ada yang ditulis.
        $audit = AuditLog::query()->where('entity_type', 'customers')->count();
        $this->artisan('pelanggan:isi-nama-normal', ['--jalankan' => true])
            ->expectsOutputToContain('nama_normal kosong: 0')
            ->assertSuccessful();
        $this->assertSame($audit, AuditLog::query()->where('entity_type', 'customers')->count());
    }

    public function test_hanya_mengisi_yang_kosong_dan_baris_terisi_tidak_disentuh(): void
    {
        $kosong = $this->ptKosong('PT Kosong Satu');
        $terisi = Customer::factory()->create(['organization_id' => $this->lab->id, 'nama' => 'PT Sudah Terisi']);
        // Nilai yang SENGAJA berbeda dari hasil normalisasi sekarang: perintah ini tidak boleh menimpanya.
        DB::table('customers')->where('id', $terisi->id)->update(['nama_normal' => 'nilai-lama-yang-aneh']);
        $diperbarui = DB::table('customers')->where('id', $terisi->id)->value('updated_at');

        $this->artisan('pelanggan:isi-nama-normal', ['--jalankan' => true])
            ->expectsOutputToContain('nama_normal kosong: 1')
            ->assertSuccessful();

        $this->assertSame('pt kosong satu', DB::table('customers')->where('id', $kosong->id)->value('nama_normal'));
        $this->assertSame('nilai-lama-yang-aneh', DB::table('customers')->where('id', $terisi->id)->value('nama_normal'));
        $this->assertSame($diperbarui, DB::table('customers')->where('id', $terisi->id)->value('updated_at'));
    }

    public function test_baris_yang_sudah_dihapus_lunak_ikut_diisi_dan_nama_tanpa_huruf_tetap_kosong(): void
    {
        $terhapus = $this->ptKosong('PT Arsip Lama');
        $terhapus->delete();
        $tandaBaca = $this->ptKosong('--- / ---');

        $this->artisan('pelanggan:isi-nama-normal', ['--jalankan' => true])
            ->expectsOutputToContain('nama_normal kosong: 2')
            ->expectsOutputToContain('bisa diisi: 1')
            ->expectsOutputToContain('tetap kosong (nama tanpa huruf/angka): 1')
            ->assertSuccessful();

        $this->assertSame('pt arsip lama', DB::table('customers')->where('id', $terhapus->id)->value('nama_normal'));
        $this->assertNull(DB::table('customers')->where('id', $tandaBaca->id)->value('nama_normal'));
    }

    public function test_kembar_dihitung_sama_di_dry_run_dan_jalankan_dan_hanya_dalam_satu_lab(): void
    {
        $labLain = Organization::factory()->create();

        // Dua baris kosong yang akan jadi sama (satu lab) + satu yang kembar dengan baris terisi.
        $this->ptKosong('PT Kembar Raya');
        $this->ptKosong('PT. Kembar  Raya');
        Customer::factory()->create(['organization_id' => $this->lab->id, 'nama' => 'CV Sudah Ada']);
        $this->ptKosong('CV Sudah  Ada.');
        // Nama sama di LAB LAIN bukan kembar.
        $this->ptKosong('PT Kembar Raya', $labLain);

        $this->artisan('pelanggan:isi-nama-normal')
            ->expectsOutputToContain('kemungkinan kembar dengan PT lain setelah diisi: 3')
            ->assertSuccessful();

        $this->artisan('pelanggan:isi-nama-normal', ['--jalankan' => true])
            ->expectsOutputToContain('kemungkinan kembar dengan PT lain setelah diisi: 3')
            ->assertSuccessful();
    }

    public function test_keluaran_hanya_hitungan_tanpa_nama_pelanggan(): void
    {
        $this->ptKosong('PT Rahasia Nasabah Besar');

        $this->artisan('pelanggan:isi-nama-normal', ['--jalankan' => true])->assertSuccessful();

        // `Artisan::call()` + `output()` = keluaran SUNGGUHAN (PendingCommand memalsukannya).
        $this->ptKosong('PT Rahasia Kedua');
        Artisan::call('pelanggan:isi-nama-normal');
        $keluaran = Artisan::output();
        $this->assertStringContainsString('nama_normal kosong: 1', $keluaran);

        $this->assertStringNotContainsStringIgnoringCase('Rahasia', $keluaran);
        $this->assertStringNotContainsStringIgnoringCase('Nasabah', $keluaran);
    }

    public function test_dua_opsi_sekaligus_ditolak_dan_tidak_menulis(): void
    {
        $pt = $this->ptKosong('PT Dua Opsi');

        $this->artisan('pelanggan:isi-nama-normal', ['--jalankan' => true, '--dry-run' => true])->assertExitCode(2);

        $this->assertNull(DB::table('customers')->where('id', $pt->id)->value('nama_normal'));
    }
}
