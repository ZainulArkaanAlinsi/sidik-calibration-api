<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penjaga `sertifikat:sapu-data-contoh`.
 *
 * Perintah ini menghapus PERMANEN - `CalibrationSession` dan `Certificate`
 * tidak memakai `SoftDeletes`. Jadi yang diuji di sini bukan "apakah dia bisa
 * menghapus", tapi tiga hal yang kalau salah tidak menghasilkan error dan tidak
 * bisa ditarik balik:
 *
 *  - dia tidak menyentuh sesi buatan aplikasi;
 *  - dia berhenti total begitu menemukan sesi yang tidak dikenali;
 *  - dia tidak menghapus apa pun sebelum cadangannya terbukti cocok.
 */
class SapuDataContohTest extends TestCase
{
    use RefreshDatabase;

    private string $dirCadangan;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        $this->dirCadangan = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sapu-contoh-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dirCadangan)) {
            foreach (glob($this->dirCadangan.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dirCadangan);
        }

        parent::tearDown();
    }

    /** Sesi bernomor master (bawaan seeder). */
    private function sesiContoh(string $nomor = '0133-CAL-324'): CalibrationSession
    {
        return CalibrationSession::factory()->create(['nomor_sesi' => $nomor]);
    }

    /** Sesi bernomor format aplikasi - pekerjaan teknisi sungguhan. */
    private function sesiAplikasi(string $nomor = 'KAL/2026/08/0001'): CalibrationSession
    {
        return CalibrationSession::factory()->create(['nomor_sesi' => $nomor]);
    }

    /**
     * Tanpa `--hapus`, perintah ini HANYA melapor.
     *
     * Ini bawaannya, dan sengaja: `docs/aturan-akses-database.md` butir 4
     * menuntut dry-run ditunjukkan lebih dulu, dan eksekusinya menunggu
     * instruksi terpisah - bukan menyambung otomatis dari dry-run.
     */
    public function test_tanpa_bendera_hapus_tidak_menghapus_apa_pun(): void
    {
        $contoh = $this->sesiContoh();

        $this->artisan('sertifikat:sapu-data-contoh')->assertSuccessful();

        $this->assertDatabaseHas('calibration_sessions', ['id' => $contoh->id]);
    }

    /** Sesi buatan aplikasi tidak boleh tersentuh, apa pun yang terjadi. */
    public function test_sesi_buatan_aplikasi_dipertahankan(): void
    {
        $contoh = $this->sesiContoh();
        $asli = $this->sesiAplikasi();

        $this->artisan('sertifikat:sapu-data-contoh', [
            '--hapus' => true,
            '--cadangan' => $this->dirCadangan,
            '--tanpa-berkas' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('calibration_sessions', ['id' => $contoh->id]);
        $this->assertDatabaseHas('calibration_sessions', ['id' => $asli->id]);
    }

    /**
     * Sesi yang tidak dikenali membuat perintah BERHENTI TOTAL.
     *
     * Bukan dilewati, bukan dihapus. Yang paling berbahaya di sini bukan gagal
     * menghapus, tapi menghapus sesuatu yang ternyata pekerjaan orang - dan
     * nomor yang tidak ada di seeder mana pun tidak bisa dipastikan asalnya.
     */
    public function test_sesi_tak_dikenal_menghentikan_seluruh_perintah(): void
    {
        $contoh = $this->sesiContoh();
        $asing = $this->sesiContoh('ENTAH-DARI-MANA-001');

        $this->artisan('sertifikat:sapu-data-contoh', [
            '--hapus' => true,
            '--cadangan' => $this->dirCadangan,
            '--tanpa-berkas' => true,
        ])->assertFailed();

        // Keduanya utuh - yang dikenal pun tidak ikut terhapus.
        $this->assertDatabaseHas('calibration_sessions', ['id' => $contoh->id]);
        $this->assertDatabaseHas('calibration_sessions', ['id' => $asing->id]);
    }

    /** `--hapus` tanpa `--cadangan` ditolak. */
    public function test_hapus_tanpa_cadangan_ditolak(): void
    {
        $contoh = $this->sesiContoh();

        $this->artisan('sertifikat:sapu-data-contoh', [
            '--hapus' => true,
            '--tanpa-berkas' => true,
        ])->assertFailed();

        $this->assertDatabaseHas('calibration_sessions', ['id' => $contoh->id]);
    }

    /**
     * Disk arsip `local` menolak jalan tanpa `--tanpa-berkas`.
     *
     * Kalau disk di mesin yang menjalankan BUKAN disk produksi, menyalin PDF
     * dari sini menghasilkan cadangan kosong yang terlihat berhasil - sementara
     * barisnya sudah terhapus permanen.
     */
    public function test_arsip_lokal_ditolak_tanpa_bendera_tanpa_berkas(): void
    {
        config(['filesystems.disks.arsip.driver' => 'local']);
        $contoh = $this->sesiContoh();

        $this->artisan('sertifikat:sapu-data-contoh', [
            '--hapus' => true,
            '--cadangan' => $this->dirCadangan,
        ])->assertFailed();

        $this->assertDatabaseHas('calibration_sessions', ['id' => $contoh->id]);
    }

    /** Sertifikat ikut terhapus, dan cadangannya terbentuk dengan isi yang cocok. */
    public function test_sertifikat_ikut_terhapus_dan_cadangan_terbentuk(): void
    {
        $contoh = $this->sesiContoh();
        $sertifikat = Certificate::factory()->create([
            'calibration_session_id' => $contoh->id,
            'nomor' => 'CAL/2026/09/0011',
        ]);

        $this->artisan('sertifikat:sapu-data-contoh', [
            '--hapus' => true,
            '--cadangan' => $this->dirCadangan,
            '--tanpa-berkas' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('certificates', ['id' => $sertifikat->id]);
        $this->assertDatabaseMissing('calibration_sessions', ['id' => $contoh->id]);

        $berkas = glob($this->dirCadangan.DIRECTORY_SEPARATOR.'cadangan-data-contoh-*.json') ?: [];
        $this->assertCount(1, $berkas, 'Berkas cadangan tidak terbentuk.');

        $isi = json_decode((string) file_get_contents($berkas[0]), true);
        $this->assertCount(1, $isi['calibration_sessions'], 'Sesi tidak ikut tercadangkan.');
        $this->assertCount(1, $isi['certificates'], 'Sertifikat tidak ikut tercadangkan.');
        $this->assertSame('CAL/2026/09/0011', $isi['certificates'][0]['nomor']);
    }

    /** Sesi berawalan DEMO diterima walau nomornya tidak ada di seeder. */
    public function test_sesi_berawalan_demo_diterima(): void
    {
        $demo = $this->sesiContoh('DEMO-SESUATU-YANG-BARU');

        $this->artisan('sertifikat:sapu-data-contoh', [
            '--hapus' => true,
            '--cadangan' => $this->dirCadangan,
            '--tanpa-berkas' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('calibration_sessions', ['id' => $demo->id]);
    }

    /** Database yang isinya cuma sesi aplikasi: tidak ada yang dihapus. */
    public function test_tanpa_data_contoh_perintah_diam(): void
    {
        $asli = $this->sesiAplikasi();

        $this->artisan('sertifikat:sapu-data-contoh', [
            '--hapus' => true,
            '--cadangan' => $this->dirCadangan,
            '--tanpa-berkas' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('calibration_sessions', ['id' => $asli->id]);
    }
}
