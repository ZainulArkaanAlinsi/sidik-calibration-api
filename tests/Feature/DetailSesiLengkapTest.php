<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Objek embed di `GET /api/calibrations/{id}` digemukin
 * (docs/permintaan-endpoint.md §5c–5e).
 *
 * Kenapa: layar pencocokan sertifikat dipakai buat nyocokin isi sertifikat
 * SEBELUM di-approve — penting karena sesi yang udah `disetujui` nggak bisa
 * diubah lagi. Tapi objek embed-nya ringkas, jadi layar itu kepaksa nembak
 * `/certificates/{id}`, `/standards/{id}`, dan `/technicians` cuma buat ngisi
 * beberapa kolom kepala. Tiga request tambahan buat satu layar.
 */
class DetailSesiLengkapTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private User $admin;

    private Standard $standar;

    private CalibrationSession $sesi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        $this->teknisi = User::factory()->create([
            'name' => 'Dwi Rahayu',
            'employee_id' => 'SDK-2001',
            'department' => 'Kalibrasi',
            'kode_teknisi' => 'DR',
        ]);
        $this->admin = User::factory()->admin()->create(['name' => 'Alex Misramto']);

        $this->standar = Standard::factory()->create([
            'nama' => 'pH Buffer Solution 7',
            'merk' => 'Supelco',
            'model' => 'Merck',
            'serial_number' => 'HC46341939',
            'no_sertifikat' => 'HC46341939',
            'tertelusur_ke' => 'Merck KGaA',
        ]);

        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'satuan' => 'pH', 'toleransi' => 0.05,
        ]);

        $this->sesi = CalibrationSession::factory()->create([
            'teknisi_id' => $this->teknisi->id,
            'reviewed_by' => $this->admin->id,
            'equipment_id' => $alat->id,
            'standard_id' => $this->standar->id,
        ]);
    }

    private function detail(): TestResponse
    {
        return $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$this->sesi->id}")
            ->assertOk();
    }

    // ------------------------------------------------------------- 5c teknisi

    /**
     * Kolom "Technician ID" di lembar kerja isinya kode pendek (`DR`), BUKAN
     * `employee_id` (`SDK-2001`). Dokumen §5c nyontohin `"employee_id": "DR"` —
     * itu ketuker, jadi dua-duanya dikirim dengan nama yang bener.
     */
    public function test_teknisi_bawa_employee_id_dan_kode_teknisi(): void
    {
        $this->detail()
            ->assertJsonPath('data.teknisi.nama', 'Dwi Rahayu')
            ->assertJsonPath('data.teknisi.employee_id', 'SDK-2001')
            ->assertJsonPath('data.teknisi.kode_teknisi', 'DR')
            ->assertJsonPath('data.teknisi.department', 'Kalibrasi');
    }

    /** Kode belum diisi → jatuh ke inisial nama, bukan kolom kosong. */
    public function test_kode_teknisi_jatuh_ke_inisial_kalau_belum_diisi(): void
    {
        $this->teknisi->update(['kode_teknisi' => null]);

        $this->detail()->assertJsonPath('data.teknisi.kode_teknisi', 'DR');
    }

    /** "Checked by" di lembar kerja = admin yang approve/reject. */
    public function test_reviewer_ikut_buat_kolom_checked_by(): void
    {
        $this->detail()
            ->assertJsonPath('data.reviewer.id', $this->admin->id)
            ->assertJsonPath('data.reviewer.nama', 'Alex Misramto')
            ->assertJsonPath('data.reviewer.kode_teknisi', 'AM');
    }

    public function test_reviewer_null_kalau_sesi_belum_diperiksa(): void
    {
        $this->sesi->update(['reviewed_by' => null]);

        $this->detail()->assertJsonPath('data.reviewer', null);
    }

    // ------------------------------------------------------- 5d standar acuan

    /** Empat kolom tabel "Standard used": Name, Merk/Type, Serial, Traceable to. */
    public function test_standar_acuan_bawa_empat_kolom_tabel_sertifikat(): void
    {
        $this->detail()
            ->assertJsonPath('data.standar_acuan.nama', 'pH Buffer Solution 7')
            ->assertJsonPath('data.standar_acuan.merk_type', 'Supelco/Merck')
            ->assertJsonPath('data.standar_acuan.serial_number', 'HC46341939')
            ->assertJsonPath('data.standar_acuan.tertelusur_ke', 'Merck KGaA')
            // Bagian mentahnya ikut, biar layar bisa nampilin merk sendiri.
            ->assertJsonPath('data.standar_acuan.merk', 'Supelco')
            ->assertJsonPath('data.standar_acuan.model', 'Merck');
    }

    /** Yang kosong dilewat — jangan sampai ada "Supelco/" yang menggantung. */
    public function test_merk_type_nggak_ninggalin_garis_miring_menggantung(): void
    {
        $this->standar->update(['model' => null]);
        $this->detail()->assertJsonPath('data.standar_acuan.merk_type', 'Supelco');

        $this->standar->update(['merk' => null, 'model' => 'Merck']);
        $this->detail()->assertJsonPath('data.standar_acuan.merk_type', 'Merck');

        $this->standar->update(['merk' => null, 'model' => null]);
        $this->detail()->assertJsonPath('data.standar_acuan.merk_type', null);
    }

    /**
     * Standar per-titik bentuknya WAJIB sama sama yang di level sesi.
     *
     * Dua-duanya lewat `petakanStandar()` yang sama — pernah kejadian disalin
     * lalu cuma satu yang diperbarui, jadi buffer pH 4/7/10 (yang standarnya
     * per-titik) ketinggalan kolomnya.
     */
    public function test_standar_per_titik_bentuknya_sama_sama_level_sesi(): void
    {
        // Nggak ada factory buat model ini — dibikin manual dengan kolom wajibnya.
        $this->sesi->uncertaintyCalculations()->create([
            'standard_id' => $this->standar->id,
            'titik_ke' => 1,
            'titik_ukur' => 7.0,
            'rata_rata' => 7.03,
            'error' => 0.03,
            'koreksi' => -0.03,
            'jumlah_pengulangan' => 3,
            'type_a' => 0.00577350,
            'type_b' => 0.01055448,
            'ketidakpastian_gabungan' => 0.01055448,
            'faktor_cakupan_k' => 2,
            'ketidakpastian_diperluas' => 0.02110895,
            'keputusan' => 'PASS',
        ]);

        $res = $this->detail();

        $this->assertSame(
            $res->json('data.standar_acuan'),
            $res->json('data.titik.0.standar_acuan'),
        );
    }

    // ---------------------------------------------------------- 5e sertifikat

    public function test_sertifikat_bawa_tanggal_terbit_dan_qr(): void
    {
        Certificate::factory()->create([
            'calibration_session_id' => $this->sesi->id,
            'status' => Certificate::STATUS_TERBIT,
            'nomor' => '012-CAL-524',
            'diterbitkan_pada' => '2024-05-30',
            'berlaku_sampai' => '2025-05-29',
            'qr_token' => 'DEMOQR123',
            'qr_payload' => 'http://localhost/verify/DEMOQR123',
        ]);

        $this->detail()
            ->assertJsonPath('data.sertifikat.nomor', '012-CAL-524')
            // "Issuance Date" — beda dari tanggal_kalibrasi (kalibrasi 26 Mei,
            // sertifikat terbit 30 Mei).
            //
            // Tanggal POLOS, persis tanggal yang disimpan. Dulu keluar
            // `2024-05-29T17:00:00Z`: kolomnya cast `date` (tengah malam) dan
            // APP_TIMEZONE-nya Asia/Jakarta, jadi begitu diserialisasi ke UTC dia
            // mundur 7 jam — ke jam 17:00 HARI SEBELUMNYA. Konsumen yang ngambil
            // 10 karakter pertama dapat tanggal salah sehari, dan di sertifikat
            // itu cacat dokumen terkendali, bukan bug tampilan.
            //
            // Test ini yang ngunci supaya nggak balik ke ISO.
            ->assertJsonPath('data.sertifikat.diterbitkan_pada', '2024-05-30')
            ->assertJsonPath('data.sertifikat.berlaku_sampai', '2025-05-29')
            // `qr_payload` udah URL siap di-render — mobile nggak nyusun sendiri,
            // jadi domainnya nggak bisa salah.
            ->assertJsonPath('data.sertifikat.qr_token', 'DEMOQR123')
            ->assertJsonPath('data.sertifikat.qr_payload', 'http://localhost/verify/DEMOQR123');
    }

    public function test_sertifikat_null_kalau_belum_terbit(): void
    {
        $this->detail()->assertJsonPath('data.sertifikat', null);
    }

    // ------------------------------------------------------------- efisiensi

    /**
     * Daftar sesi nggak boleh nambah query per baris.
     *
     * `reviewer` relasi baru; kalau lupa dimasukin ke daftar eager-load
     * controller, tiap baris di daftar sesi jadi satu query tambahan — dan itu
     * baru kerasa di lab yang udah jalan setahun, bukan waktu dites 2 baris.
     */
    public function test_daftar_sesi_nggak_nambah_query_per_baris(): void
    {
        CalibrationSession::factory()->count(4)->create([
            'teknisi_id' => $this->teknisi->id,
            'reviewed_by' => $this->admin->id,
            'equipment_id' => $this->sesi->equipment_id,
            'standard_id' => $this->standar->id,
        ]);

        DB::enableQueryLog();
        $this->actingAs($this->teknisi)->getJson('/api/calibrations')->assertOk();
        $jumlah = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 5 sesi. Angkanya dibatesin longgar — yang dijaga di sini "nggak
        // tumbuh seiring jumlah baris", bukan angka persisnya.
        $this->assertLessThan(25, $jumlah, "Query-nya {$jumlah} — kemungkinan ada relasi yang belum di-eager-load.");
    }
}
