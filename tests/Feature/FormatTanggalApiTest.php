<?php

namespace Tests\Feature;

use App\Models\CalibrationMethod;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Format tanggal di SELURUH API — satu aturan, dijaga di satu tempat.
 *
 * Aturannya:
 * - kolom bercast `date` (nggak ada jamnya)  → tanggal polos `2024-05-26`
 * - kolom `datetime` asli (`created_at`, …)  → ISO 8601 `2024-05-26T09:10:00Z`
 *
 * Kenapa dijaga: `date` + `APP_TIMEZONE=Asia/Jakarta` yang diserialisasi jadi ISO
 * keluar sebagai `2024-05-25T17:00:00Z` — **mundur sehari**. Konsumen yang ngambil
 * 10 karakter pertama dapat tanggal salah, dan nilainya nggak bisa dipakai balik
 * jadi penyaring `dari`/`sampai`. Di sertifikat, tanggal terbit yang salah sehari
 * itu cacat dokumen terkendali.
 *
 * Test ini sengaja NYAPU banyak endpoint sekaligus, bukan satu-satu: yang gampang
 * kejadian itu satu resource baru lupa ikut aturan, dan itu cuma keliatan kalau
 * semuanya diperiksa dengan aturan yang sama.
 */
class FormatTanggalApiTest extends TestCase
{
    use RefreshDatabase;

    /** `2024-05-26` — 10 karakter, nggak ada `T` dan nggak ada `Z`. */
    private const POLOS = '/^\d{4}-\d{2}-\d{2}$/';

    /** `2024-05-26T09:10:00Z`. */
    private const ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create([
            'akreditasi_mulai' => '2024-01-15',
            'akreditasi_berakhir' => '2028-01-14',
        ]);

        $this->admin = User::factory()->admin()->create();
    }

    private function tanggalPolos(mixed $nilai, string $field): void
    {
        $this->assertIsString($nilai, "`{$field}` harus string tanggal, dapat: ".var_export($nilai, true));
        $this->assertMatchesRegularExpression(
            self::POLOS,
            $nilai,
            "`{$field}` harus tanggal polos (`2024-05-26`), bukan `{$nilai}`. "
                .'Kolomnya cast `date` — ISO di zona Jakarta mundur sehari.',
        );
    }

    private function sesi(): CalibrationSession
    {
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'satuan' => 'pH',
            'toleransi' => 0.05,
            'tanggal_kalibrasi_terakhir' => '2024-05-26',
            'tanggal_jatuh_tempo' => '2025-05-26',
        ]);

        return CalibrationSession::factory()->create([
            'teknisi_id' => $this->admin->id,
            'equipment_id' => $alat->id,
            'tanggal_kalibrasi' => '2024-05-26',
            'tanggal_terima' => '2024-05-26',
        ]);
    }

    // -------------------------------------------------------------- kolom date

    public function test_tanggal_sesi_kalibrasi_polos(): void
    {
        $sesi = $this->sesi();

        $data = $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->json('data');

        $this->tanggalPolos($data['tanggal_kalibrasi'], 'tanggal_kalibrasi');
        $this->tanggalPolos($data['tanggal_terima'], 'tanggal_terima');
    }

    public function test_tanggal_sertifikat_polos_di_endpoint_sertifikat_dan_embed_sesi(): void
    {
        $sesi = $this->sesi();
        $sertifikat = Certificate::factory()->create([
            'calibration_session_id' => $sesi->id,
            'status' => Certificate::STATUS_TERBIT,
            'diterbitkan_pada' => '2024-05-30',
            'berlaku_sampai' => '2025-05-29',
        ]);

        // Nilainya harus persis tanggal yang disimpan — bukan mundur sehari.
        $endpoint = $this->actingAs($this->admin)
            ->getJson("/api/certificates/{$sertifikat->id}")
            ->assertOk()
            ->assertJsonPath('data.diterbitkan_pada', '2024-05-30')
            ->assertJsonPath('data.berlaku_sampai', '2025-05-29')
            ->json('data');

        $this->tanggalPolos($endpoint['diterbitkan_pada'], 'certificates.diterbitkan_pada');

        // Objek yang di-embed di detail sesi harus SAMA — kalau dua jalur ini
        // beda format, satu sertifikat punya dua tanggal terbit.
        $embed = $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->json('data.sertifikat');

        $this->assertSame($endpoint['diterbitkan_pada'], $embed['diterbitkan_pada']);
        $this->assertSame($endpoint['berlaku_sampai'], $embed['berlaku_sampai']);
    }

    public function test_tanggal_alat_polos(): void
    {
        $this->sesi();

        $alat = $this->actingAs($this->admin)
            ->getJson('/api/equipments')
            ->assertOk()
            ->json('data.0');

        $this->tanggalPolos($alat['tanggal_kalibrasi_terakhir'], 'tanggal_kalibrasi_terakhir');
        $this->tanggalPolos($alat['tanggal_jatuh_tempo'], 'tanggal_jatuh_tempo');
    }

    public function test_tanggal_standar_polos(): void
    {
        Standard::factory()->create(['berlaku_sampai' => '2027-07-13']);

        $this->actingAs($this->admin)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonPath('data.0.berlaku_sampai', '2027-07-13');
    }

    public function test_tanggal_akreditasi_organisasi_polos(): void
    {
        $org = $this->actingAs($this->admin)
            ->getJson('/api/organization')
            ->assertOk()
            ->json('data');

        $this->tanggalPolos($org['akreditasi_mulai'], 'akreditasi_mulai');
        $this->tanggalPolos($org['akreditasi_berakhir'], 'akreditasi_berakhir');
    }

    public function test_tanggal_metode_kalibrasi_polos(): void
    {
        CalibrationMethod::factory()->create(['berlaku_mulai' => '2024-03-01']);

        $this->actingAs($this->admin)
            ->getJson('/api/calibration-methods')
            ->assertOk()
            ->assertJsonPath('data.0.berlaku_mulai', '2024-03-01');
    }

    /** Verifikasi QR kebuka tanpa login — orang luar yang scan juga lihat tanggal. */
    public function test_tanggal_di_verifikasi_qr_publik_polos(): void
    {
        $sesi = $this->sesi();
        $sertifikat = Certificate::factory()->create([
            'calibration_session_id' => $sesi->id,
            'status' => Certificate::STATUS_TERBIT,
            'diterbitkan_pada' => '2024-05-30',
            'berlaku_sampai' => '2025-05-29',
            'qr_token' => 'QRPOLOS123',
        ]);

        // Bentuknya DATAR (`data.diterbitkan_pada`), bukan bersarang di
        // `data.sertifikat` — beda dari embed di detail sesi.
        $this->getJson("/api/verify/{$sertifikat->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.diterbitkan_pada', '2024-05-30')
            ->assertJsonPath('data.berlaku_sampai', '2025-05-29')
            ->assertJsonPath('data.tanggal_kalibrasi', '2024-05-26');
    }

    public function test_tanggal_di_laporan_polos(): void
    {
        $this->sesi();

        $this->actingAs($this->admin)
            ->getJson('/api/laporan/kalibrasi')
            ->assertOk()
            ->assertJsonPath('data.0.tanggal_kalibrasi', '2024-05-26');
    }

    // ---------------------------------------------------------- kolom datetime

    /**
     * Kolom `datetime` asli TETAP ISO — jangan kena sapu bersih.
     *
     * `created_at` punya komponen jam yang beneran berarti ("dibuat 09:10"), jadi
     * motongnya jadi tanggal polos itu ngilangin informasi. Yang salah cuma kolom
     * `date`, bukan semua tanggal.
     */
    public function test_kolom_datetime_tetap_iso(): void
    {
        $notif = $this->actingAs($this->admin)
            ->getJson('/api/notifications')
            ->assertOk();

        // Notifikasi bisa kosong; yang dijaga di sini bentuknya kalau ada.
        if (filled($notif->json('data'))) {
            $this->assertMatchesRegularExpression(
                self::ISO,
                $notif->json('data.0.dibuat_pada'),
                '`notifications.dibuat_pada` itu datetime asli — harus tetap ISO.',
            );
        }

        $this->actingAs($this->admin)->getJson('/api/folders')->assertOk();
    }
}
