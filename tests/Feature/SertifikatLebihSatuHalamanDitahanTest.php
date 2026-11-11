<?php

namespace Tests\Feature;

use App\Exceptions\SertifikatLebihSatuHalamanException;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SertifikatGagal;
use App\Services\BerkasPdfSertifikat;
use App\Services\DataTampilanSertifikat;
use App\Services\SertifikatSatuHalaman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sertifikat yang tidak muat SATU halaman TIDAK diterbitkan diam-diam.
 *
 * Dulu `SertifikatSatuHalaman::paling()` hanya `Log::error` lalu mengembalikan
 * PDF dua halaman — yang terbit bertulis "Page : 1 of 1". Sekarang pengecualian
 * dilempar: sertifikat berstatus `gagal` (nomor, snapshot, dan sesi `disetujui`
 * tetap), admin dikabari dengan alasan yang terbaca, dan tombol "Terbitkan
 * ulang" berjalan normal setelah isinya diperbaiki.
 *
 * Sesi Autoklaf, bukan sesi factory polos: lihat `SinkronJadwalAlatTest` —
 * satu-satunya bentuk sesi sah tanpa titik hasil hitung.
 */
class SertifikatLebihSatuHalamanDitahanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('arsip');

        $this->org = Organization::factory()->create(['nama' => 'PT Contoh Satu']);
        $this->teknisi = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_TEKNISI]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN]);

        $this->alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => Customer::factory()->create([
                'organization_id' => $this->org->id,
                'nama' => 'PT Contoh Pelanggan 1',
            ])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $this->org->id])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);
    }

    /** Angka input dari master `Autoclave_CSV/INPUT_DATA.csv` baris 36-39 (sama dengan `SinkronJadwalAlatTest`). */
    private function sesiAutoklaf(): CalibrationSession
    {
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', [
                'equipment_id' => $this->alat->id,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'suhu_awal' => 24.4,
                'suhu_akhir' => 24.5,
                'kelembaban_awal' => 55,
                'kelembaban_akhir' => 56,
                'set_point' => 121.0,
                'suhu' => [
                    'disk' => [
                        [121.27, 121.26, 121.26, 121.26, 121.28],
                        [121.30, 121.26, 121.26, 121.25, 121.25],
                        [121.26, 121.26, 121.28, 121.35, 121.28],
                    ],
                    'indikator' => [121, 121, 121, 121, 121],
                    'suhu_ruang' => [25, 25, 25, 25, 25],
                ],
                'tekanan' => [
                    'uut_setting' => 0.112,
                    'satuan' => 'MPa',
                    'display' => 'Digital',
                    'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    private function setujui(CalibrationSession $sesi, string $nomor): void
    {
        $this->denganOrderNumber($sesi->id)->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $nomor]);
    }

    /** Renderer palsu yang selalu menolak karena meluap — pola `SinkronJadwalAlatTest`. */
    private function pakaiRendererMeluap(?string $isiPadat = null): void
    {
        $this->app->bind(SertifikatSatuHalaman::class, fn () => new class(app(DataTampilanSertifikat::class), $isiPadat) extends SertifikatSatuHalaman
        {
            public function __construct(DataTampilanSertifikat $data, private readonly ?string $padat)
            {
                parent::__construct($data);
            }

            public function isi(Certificate $sertifikat): string
            {
                throw new SertifikatLebihSatuHalamanException($sertifikat->getKey(), 2, 2, $this->padat);
            }
        });
    }

    private function jumlahHalaman(string $pdf): int
    {
        return preg_match_all('/\/Type\s*\/Page(?![a-z])/', $pdf);
    }

    public function test_sertifikat_meluap_ditahan_gagal_terang_dengan_nomor_dan_sesi_utuh(): void
    {
        $sesi = $this->sesiAutoklaf();
        $nomor = $this->nomorUji();

        $this->pakaiRendererMeluap();
        $this->setujui($sesi, $nomor);

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail();

        $this->assertSame(Certificate::STATUS_GAGAL, $sertifikat->status, 'Sertifikat >1 halaman tidak boleh terbit.');
        $this->assertSame($nomor, $sertifikat->nomor, 'Nomor sertifikat tidak boleh hilang.');
        $this->assertTrue(blank($sertifikat->pdf_path), 'Tidak boleh ada PDF yang tercatat.');
        $this->assertSame([], Storage::disk('arsip')->allFiles('certificates'), 'Tidak boleh ada PDF di arsip.');
        $this->assertNotEmpty($sertifikat->snapshot, 'Snapshot beku tetap tersimpan supaya bisa diterbitkan ulang.');
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi->fresh()->status, 'Sesi disetujui tidak boleh rusak.');

        $notif = $this->admin->notifications()->where('type', SertifikatGagal::class)->get();
        $this->assertNotEmpty($notif, 'Admin harus dikabari.');
        $this->assertStringContainsString('tidak muat 1 halaman', json_encode($notif->first()->data));
    }

    public function test_bisa_diterbitkan_ulang_setelah_diperbaiki_dengan_nomor_yang_sama(): void
    {
        $sesi = $this->sesiAutoklaf();
        $nomor = $this->nomorUji();

        $this->pakaiRendererMeluap();
        $this->setujui($sesi, $nomor);

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail();
        $this->assertSame(Certificate::STATUS_GAGAL, $sertifikat->status);

        // "Diperbaiki": renderer asli lagi.
        $this->app->bind(SertifikatSatuHalaman::class, fn () => new SertifikatSatuHalaman(app(DataTampilanSertifikat::class)));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/certificates/{$sertifikat->id}/retry")
            ->assertSuccessful();

        $sertifikat->refresh();

        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
        $this->assertSame($nomor, $sertifikat->nomor);
        $this->assertSame(1, Certificate::where('calibration_session_id', $sesi->id)->count(), 'Retry tidak boleh melahirkan baris/nomor kedua.');
        $this->assertSame(1, $this->jumlahHalaman(Storage::disk('arsip')->get($sertifikat->pdf_path)));
    }

    /** Kasus normal tidak berubah: sertifikat yang muat tetap terbit, satu halaman. */
    public function test_sertifikat_normal_tetap_terbit_satu_halaman(): void
    {
        $sesi = $this->sesiAutoklaf();
        $this->setujui($sesi, $this->nomorUji());

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail();

        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
        $this->assertSame(1, $this->jumlahHalaman(Storage::disk('arsip')->get($sertifikat->pdf_path)));
    }

    /**
     * Cetak ulang / bangun ulang berkas dari snapshot beku juga tidak menulis
     * PDF >1 halaman: berkas lama utuh, hasilnya `null`.
     */
    public function test_cetak_ulang_yang_meluap_tidak_menimpa_berkas_lama(): void
    {
        $sesi = $this->sesiAutoklaf();
        $this->setujui($sesi, $this->nomorUji());

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail();
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);

        $lama = Storage::disk('arsip')->get($sertifikat->pdf_path);

        $this->pakaiRendererMeluap();
        $berkas = app(BerkasPdfSertifikat::class);

        $this->assertNull($berkas->cetakUlang($sertifikat));
        $this->assertSame($lama, Storage::disk('arsip')->get($sertifikat->pdf_path), 'Berkas terbit lama tidak boleh berubah.');

        // Berkas raib + render ulang meluap, TANPA versi padat → tidak ada PDF yang ditulis.
        Storage::disk('arsip')->delete($sertifikat->pdf_path);
        $this->assertNull($berkas->pastikanAda($sertifikat));
        $this->assertFalse(Storage::disk('arsip')->exists($sertifikat->pdf_path));
    }

    /**
     * Sertifikat yang SUDAH terbit lalu berkasnya raib (disk arsip sementara):
     * pemegangnya tidak boleh 404 — versi terpadat dilayani (perilaku lama).
     * Cetak ulang PAKSA tetap menolak.
     */
    public function test_berkas_raib_sertifikat_terbit_tetap_dilayani_walau_render_ulang_meluap(): void
    {
        $sesi = $this->sesiAutoklaf();
        $this->setujui($sesi, $this->nomorUji());

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail();
        $lama = Storage::disk('arsip')->get($sertifikat->pdf_path);

        $this->pakaiRendererMeluap($lama);
        $berkas = app(BerkasPdfSertifikat::class);

        Storage::disk('arsip')->delete($sertifikat->pdf_path);
        $this->assertSame($sertifikat->pdf_path, $berkas->pastikanAda($sertifikat));
        $this->assertSame($lama, Storage::disk('arsip')->get($sertifikat->pdf_path));

        $this->assertNull($berkas->cetakUlang($sertifikat), 'Cetak ulang paksa tidak boleh menulis PDF yang meluap.');
        $this->assertSame($lama, Storage::disk('arsip')->get($sertifikat->pdf_path));
    }
}
