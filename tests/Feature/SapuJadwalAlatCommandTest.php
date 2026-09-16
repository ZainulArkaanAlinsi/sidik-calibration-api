<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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
 * `alat:sinkron-jadwal` — sapuan sekali jalan buat alat yang sertifikatnya
 * terbit sebelum sambungan otomatis ada.
 *
 * Yang paling penting di sini `--dry-run`: keluarannya yang ditinjau admin lab
 * sebelum perintah ini dijalankan di data produksi. Kalau dry-run ternyata
 * menulis sesuatu, seluruh gunanya hilang — dan hilangnya diam-diam.
 *
 * Rujukan: `docs/pelanggan/06-Risk-Register.md` R-E04 — perintah ini yang jadi
 * langkah pemulihannya, dan urutan wajibnya dry-run → ditinjau → dijalankan.
 */
class SapuJadwalAlatCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['nama' => 'PT Contoh Satu']);
        $teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
        ]);

        $this->alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => Customer::factory()->create([
                'organization_id' => $this->org->id,
                'nama' => 'PT Contoh Dua',
            ])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $this->org->id,
            ])->id,
            'nama_alat' => 'Timbangan Contoh',
            'tanggal_kalibrasi_terakhir' => '2025-01-01',
            'tanggal_jatuh_tempo' => '2026-01-01',
        ]);

        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $this->org->id,
            'equipment_id' => $this->alat->id,
            'teknisi_id' => $teknisi->id,
            'tanggal_kalibrasi' => '2026-02-10',
        ]);

        Certificate::factory()->create([
            'organization_id' => $this->org->id,
            'calibration_session_id' => $sesi->id,
            'nomor' => 'CAL/2026/02/0042',
            'status' => Certificate::STATUS_TERBIT,
            'diterbitkan_pada' => '2026-02-12',
            'berlaku_sampai' => '2027-02-10',
        ]);
    }

    /**
     * Dry-run menampilkan yang bakal berubah, dan TIDAK menulis apa pun.
     *
     * Tabelnya diadu pakai `expectsTable`, BUKAN `expectsOutputToContain`:
     * keluaran `Command::table()` dirender Symfony langsung ke OutputInterface
     * dan nggak ikut tertangkap penangkap keluaran biasa. Dipakai
     * `expectsOutputToContain` buat isi tabel, assertion-nya hijau palsu —
     * lolos tanpa pernah melihat tabelnya.
     */
    public function test_dry_run_tidak_menulis_apa_pun(): void
    {
        $auditSebelum = AuditLog::count();

        $this->artisan('alat:sinkron-jadwal --dry-run')
            ->expectsTable(
                ['equipment_id', 'nama_alat', 'tgl_kalibrasi lama→baru', 'jatuh_tempo lama→baru', 'nomor sertifikat sumber'],
                [[$this->alat->id, 'Timbangan Contoh', '2025-01-01 → 2026-02-10', '2026-01-01 → 2027-02-10', 'CAL/2026/02/0042']],
            )
            ->expectsOutputToContain('1 alat AKAN diubah')
            ->assertSuccessful();

        $this->alat->refresh();

        $this->assertSame('2026-01-01', $this->alat->tanggal_jatuh_tempo?->toDateString());
        $this->assertSame('2025-01-01', $this->alat->tanggal_kalibrasi_terakhir?->toDateString());
        $this->assertSame($auditSebelum, AuditLog::count(), 'Dry-run meninggalkan baris audit.');
    }

    /** Tanpa flag: tabelnya ditampilkan dulu, baru menulis setelah dikonfirmasi. */
    public function test_menulis_setelah_konfirmasi_diterima(): void
    {
        $this->artisan('alat:sinkron-jadwal')
            ->expectsConfirmation('Tulis perubahan ke 1 alat?', 'yes')
            ->assertSuccessful();

        $this->alat->refresh();

        $this->assertSame('2027-02-10', $this->alat->tanggal_jatuh_tempo?->toDateString());
        $this->assertSame('2026-02-10', $this->alat->tanggal_kalibrasi_terakhir?->toDateString());
    }

    /** Konfirmasi ditolak = nggak ada yang ditulis. */
    public function test_konfirmasi_ditolak_tidak_menulis(): void
    {
        $this->artisan('alat:sinkron-jadwal')
            ->expectsConfirmation('Tulis perubahan ke 1 alat?', 'no')
            ->assertSuccessful();

        $this->alat->refresh();

        $this->assertSame('2026-01-01', $this->alat->tanggal_jatuh_tempo?->toDateString());
    }

    /** Alat yang tanggalnya sudah benar nggak muncul di tabel dan nggak minta konfirmasi. */
    public function test_tidak_ada_yang_perlu_diubah(): void
    {
        $this->alat->update([
            'tanggal_kalibrasi_terakhir' => '2026-02-10',
            'tanggal_jatuh_tempo' => '2027-02-10',
        ]);

        $this->artisan('alat:sinkron-jadwal --dry-run')
            ->expectsOutputToContain('nggak ada yang perlu diubah')
            ->assertSuccessful();
    }
}
