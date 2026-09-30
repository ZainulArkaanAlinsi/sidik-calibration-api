<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman verifikasi QR — satu-satunya tampilan web di project ini.
 *
 * Yang discan itu orang LUAR pakai kamera HP biasa, jadi: nggak butuh login,
 * hasilnya halaman yang kebaca manusia, dan isinya nggak boleh bocor lebih dari
 * yang perlu buat mastiin sertifikatnya asli.
 */
class VerificationTest extends TestCase
{
    use RefreshDatabase;

    private function sertifikat(array $override = []): Certificate
    {
        Organization::factory()->create(['nama' => 'PT Sidik', 'no_akreditasi' => 'LK-285-IDN']);
        EquipmentCategory::factory()->create(['kode' => 'panjang']);
        $pelanggan = Customer::factory()->create(['nama' => 'PT Maju Jaya']);
        $alat = Equipment::factory()->create([
            'nama_alat' => 'Jangka Sorong Mitutoyo',
            'serial_number' => 'MT-500-196-30',
            'customer_id' => $pelanggan->id,
        ]);
        $teknisi = User::factory()->create();

        $sesi = CalibrationSession::create([
            'organization_id' => $alat->organization_id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $teknisi->id,
            'status' => CalibrationSession::STATUS_DISETUJUI,
            'keputusan' => 'PASS',
            'tanggal_kalibrasi' => now()->subMonth(),
        ]);

        return Certificate::create([
            'organization_id' => $alat->organization_id,
            'calibration_session_id' => $sesi->id,
            'nomor' => 'CAL/2026/07/0001',
            'qr_token' => 'DEMOQR123',
            'diterbitkan_pada' => now()->subMonth(),
            'berlaku_sampai' => now()->addMonths(11),
            'status' => Certificate::STATUS_TERBIT,
            ...$override,
        ]);
    }

    public function test_scan_qr_valid_nampilin_data_sertifikat_tanpa_login(): void
    {
        $this->sertifikat();

        $this->get('/verify/DEMOQR123')
            ->assertOk()
            ->assertSee('Sertifikat terverifikasi')
            ->assertSee('CAL/2026/07/0001')
            ->assertSee('Jangka Sorong Mitutoyo')
            ->assertSee('MT-500-196-30')
            ->assertSee('PT Maju Jaya')
            ->assertSee('PASS')
            ->assertSee('LK-285-IDN');
    }

    public function test_qr_ngawur_nampilin_halaman_tidak_ditemukan(): void
    {
        $this->sertifikat();

        $this->get('/verify/QR-KARANGAN')
            ->assertNotFound()
            ->assertSee('Sertifikat tidak ditemukan');
    }

    /** Sertifikat yang belum kelar digenerate belum boleh keliatan verified. */
    public function test_sertifikat_yang_belum_terbit_nggak_bisa_diverifikasi(): void
    {
        $this->sertifikat(['status' => Certificate::STATUS_MENUNGGU_GENERATE]);

        $this->get('/verify/DEMOQR123')->assertNotFound();
    }

    /**
     * Halaman ini publik, jadi isinya dibatesin. Data yang nggak perlu buat
     * mastiin keaslian sertifikat nggak boleh nongol.
     */
    public function test_halaman_publik_nggak_bocorin_data_internal(): void
    {
        $sertifikat = $this->sertifikat();
        $teknisi = $sertifikat->session->teknisi;

        $this->get('/verify/DEMOQR123')
            ->assertOk()
            ->assertDontSee($teknisi->email)
            ->assertDontSee($teknisi->employee_id)
            // Nama teknisi juga bukan urusan orang luar.
            ->assertDontSee($teknisi->name);
    }

    /**
     * Kartu ringkas (sertifikat lama tanpa snapshot) & halaman tidak-ketemu:
     * wajib `noindex` karena memuat nama pelanggan, dan wajib kebaca di HP.
     * Restyle artboard Web_Verifikasi tidak boleh menghilangkan dua meta itu
     * — layout-nya baru, jadi gampang terlewat.
     */
    public function test_kartu_dan_halaman_tidak_ketemu_noindex_dan_viewport(): void
    {
        $this->sertifikat();

        foreach (['/verify/DEMOQR123', '/verify/QR-KARANGAN'] as $url) {
            $this->get($url)
                ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
                ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', false);
        }
    }

    public function test_kartu_menurunkan_status_dari_masa_berlaku_tanpa_data_baru(): void
    {
        $this->sertifikat();

        $this->get('/verify/DEMOQR123')
            ->assertOk()
            ->assertSee('Berlaku')
            ->assertDontSee('Kedaluwarsa');

        Certificate::query()->update(['berlaku_sampai' => now()->subDay()]);

        $this->get('/verify/DEMOQR123')
            ->assertOk()
            ->assertSee('Kedaluwarsa')
            ->assertSee('Kadaluarsa');
    }

    /** Yang tampil TETAP delapan hal yang tampil sebelum restyle — tidak lebih. */
    public function test_kartu_tetap_memuat_data_yang_sama_dan_tidak_menambah(): void
    {
        $sertifikat = $this->sertifikat();

        $this->get('/verify/DEMOQR123')
            ->assertOk()
            ->assertSee('CAL/2026/07/0001')
            ->assertSee('Jangka Sorong Mitutoyo')
            ->assertSee('MT-500-196-30')
            ->assertSee('PT Maju Jaya')
            ->assertSee('PASS')
            ->assertDontSee($sertifikat->session->teknisi->name)
            ->assertDontSee($sertifikat->session->teknisi->email)
            ->assertDontSee('organization_id')
            ->assertDontSee((string) $sertifikat->qr_token.'/download');
    }

    public function test_versi_json_buat_mobile(): void
    {
        $this->sertifikat();

        $this->getJson('/api/verify/DEMOQR123')
            ->assertOk()
            ->assertJsonPath('data.nomor', 'CAL/2026/07/0001')
            ->assertJsonPath('data.keputusan', 'PASS')
            ->assertJsonPath('data.alat.serial_number', 'MT-500-196-30')
            ->assertJsonPath('data.diterbitkan_oleh.no_akreditasi', 'LK-285-IDN');

        $this->getJson('/api/verify/QR-KARANGAN')->assertNotFound();
    }

    public function test_beranda_nggak_nampilin_halaman_bawaan_laravel(): void
    {
        Organization::factory()->create(['nama' => 'PT Sidik']);

        $this->get('/')
            ->assertOk()
            ->assertSee('PT Sidik')
            // Halaman bawaan Laravel mamerin versi framework ke publik.
            ->assertDontSee('Laravel')
            ->assertDontSee(app()->version());
    }
}
