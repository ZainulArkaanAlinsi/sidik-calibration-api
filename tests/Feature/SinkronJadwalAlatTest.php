<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\SertifikatSatuHalaman;
use App\Services\SinkronJadwalAlat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Jadwal alat ikut sertifikat aktifnya, dan cuma sertifikat aktifnya.
 *
 * ## Kenapa berkas ini ada
 *
 * `berlaku_sampai` dipilih admin waktu approve dan mendarat di `certificates`,
 * sementara pengingat pagi membaca `equipments.tanggal_jatuh_tempo` — kolom
 * yang cuma berubah lewat form manual dan impor Excel. Dua angka yang
 * seharusnya sama, diisi dari dua jalan yang tidak pernah bertemu, dan
 * selisihnya tidak pernah memunculkan error: dua-duanya kolom sah berisi
 * tanggal sah.
 *
 * Sesi AUTOKLAF, bukan sesi factory polos, buat yang lewat `approve`. Sesi
 * factory polos ditolak `CalibrationValidator` dengan `titik_kosong` (nol
 * `uncertainty_calculations`) dan tidak pernah sampai ke penerbitan sertifikat.
 * Autoklaf satu-satunya bentuk sesi yang sah tanpa titik hasil hitung — lihat
 * `AutoclaveCertificateTest`.
 */
class SinkronJadwalAlatTest extends TestCase
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
        $this->teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
        $this->admin = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_ADMIN,
        ]);

        $this->alat = $this->buatAlat($this->org, 'Autoclave', 'Autoklaf');
    }

    private function buatAlat(Organization $org, string $nama = 'Timbangan Contoh', ?string $kemampuan = null): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create([
                'organization_id' => $org->id,
                'nama' => 'PT Contoh Dua',
            ])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $org->id,
            ])->id,
            'nama_alat' => $nama,
            'nama_alat_kemampuan' => $kemampuan,
        ]);
    }

    /**
     * Angka input disalin dari master `Autoclave_CSV/INPUT_DATA.csv` baris
     * 36-39, sama persis dengan yang dipakai `AutoclaveCertificateTest`.
     *
     * @return array<string, mixed>
     */
    private function payloadAutoklaf(string $tanggalKalibrasi): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'tanggal_kalibrasi' => $tanggalKalibrasi,
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
        ];
    }

    private function sesiAutoklaf(string $tanggalKalibrasi): CalibrationSession
    {
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payloadAutoklaf($tanggalKalibrasi))
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    /** Sertifikat + sesinya buat alat tertentu, tanpa lewat jalur approve. */
    private function sertifikatUntuk(
        Equipment $alat,
        string $tanggalKalibrasi,
        string $berlakuSampai,
        string $diterbitkanPada,
        string $status = Certificate::STATUS_TERBIT,
        ?Certificate $revisiDari = null,
    ): Certificate {
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $alat->organization_id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $this->teknisi->id,
            'tanggal_kalibrasi' => $tanggalKalibrasi,
        ]);

        return Certificate::factory()->create([
            'organization_id' => $alat->organization_id,
            'calibration_session_id' => $sesi->id,
            'status' => $status,
            'diterbitkan_pada' => $diterbitkanPada,
            'berlaku_sampai' => $berlakuSampai,
            'revision_of' => $revisiDari?->id,
        ]);
    }

    // ---------------------------------------------------------------- T1 & T2
    // Lewat endpoint approve beneran, bukan lewat service langsung: yang diuji
    // di dua test ini justru SAMBUNGANNYA — kalau `GenerateCertificate` lupa
    // memanggil servicenya, test yang menembak service langsung tetap hijau.

    /** Masa berlaku pilihan admin mendarat di alat, bukan cuma di sertifikat. */
    public function test_approve_dengan_berlaku_sampai_kustom_ikut_ke_alat(): void
    {
        $tanggalKalibrasi = now()->subDay()->toDateString();
        $sesi = $this->sesiAutoklaf($tanggalKalibrasi);
        $pilihanAdmin = now()->addMonths(18)->toDateString();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['berlaku_sampai' => $pilihanAdmin])
            ->assertOk();

        $this->alat->refresh();

        $this->assertSame($pilihanAdmin, $this->alat->tanggal_jatuh_tempo?->toDateString());
        $this->assertSame($tanggalKalibrasi, $this->alat->tanggal_kalibrasi_terakhir?->toDateString());
    }

    /** Tanpa pilihan admin, yang dipakai default organisasi — dihitung dari TANGGAL KALIBRASI. */
    public function test_approve_tanpa_berlaku_sampai_pakai_default_masa_berlaku_organisasi(): void
    {
        $tanggalKalibrasi = now()->subDays(3)->toDateString();
        $sesi = $this->sesiAutoklaf($tanggalKalibrasi);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();

        // Dihitung dari tanggal kalibrasi, bukan tanggal terbit. Sertifikat bisa
        // terbit beberapa hari sesudah alat dikerjakan; kalau dihitung dari
        // tanggal terbit, masa berlakunya diam-diam kepanjangan.
        $harusnya = now()->subDays(3)
            ->addMonthsNoOverflow(Organization::DEFAULT_MASA_BERLAKU_BULAN)
            ->toDateString();

        $this->alat->refresh();

        $this->assertSame($harusnya, $this->alat->tanggal_jatuh_tempo?->toDateString());
        $this->assertSame($tanggalKalibrasi, $this->alat->tanggal_kalibrasi_terakhir?->toDateString());
    }

    // ------------------------------------------------------------------ T3–T10

    /** Revisi menang atas sertifikat yang direvisinya. */
    public function test_revisi_dengan_tanggal_baru_bikin_alat_ikut_revisi(): void
    {
        $asli = $this->sertifikatUntuk($this->alat, '2026-01-10', '2027-01-10', '2026-01-12');
        $this->sertifikatUntuk($this->alat, '2026-01-10', '2026-11-30', '2026-02-02', revisiDari: $asli);

        app(SinkronJadwalAlat::class)->untuk($this->alat);

        $this->alat->refresh();

        $this->assertSame('2026-11-30', $this->alat->tanggal_jatuh_tempo?->toDateString());
    }

    /**
     * Sertifikat yang sudah digantikan revisi terbit TIDAK boleh jadi sumber,
     * walau tanggal terbitnya lebih baru.
     *
     * Tanpa test ini, `test_revisi_...` di atas bisa hijau cuma karena revisinya
     * kebetulan terbit belakangan — bukan karena aturan "sudah digantikan"-nya
     * beneran jalan.
     */
    public function test_sertifikat_yang_sudah_digantikan_revisi_terbit_tidak_dipakai(): void
    {
        $asli = $this->sertifikatUntuk($this->alat, '2026-01-10', '2027-01-10', '2026-06-01');
        // Revisinya terbit LEBIH DULU dari yang direvisinya. Janggal, tapi
        // mungkin: `diterbitkan_pada` bisa diisi ulang lewat pembangunan ulang.
        $this->sertifikatUntuk($this->alat, '2026-01-10', '2026-11-30', '2026-02-02', revisiDari: $asli);

        app(SinkronJadwalAlat::class)->untuk($this->alat);

        $this->alat->refresh();

        $this->assertSame(
            '2026-11-30',
            $this->alat->tanggal_jatuh_tempo?->toDateString(),
            'Sertifikat yang sudah direvisi masih dipakai jadi sumber jadwal.'
        );
    }

    /** Revisi yang GAGAL dirender nggak boleh mematikan sertifikat asalnya. */
    public function test_revisi_yang_gagal_render_tidak_mematikan_sertifikat_asal(): void
    {
        $asli = $this->sertifikatUntuk($this->alat, '2026-01-10', '2027-01-10', '2026-01-12');
        $this->sertifikatUntuk(
            $this->alat, '2026-01-10', '2026-11-30', '2026-02-02',
            status: Certificate::STATUS_GAGAL, revisiDari: $asli,
        );

        app(SinkronJadwalAlat::class)->untuk($this->alat);

        $this->alat->refresh();

        $this->assertSame(
            '2027-01-10',
            $this->alat->tanggal_jatuh_tempo?->toDateString(),
            'Alat kehilangan jadwal gara-gara revisi yang dokumennya nggak pernah jadi.'
        );
    }

    /**
     * `diterbitkan_pada` bertipe `date`, bukan `datetime`.
     *
     * Dua sertifikat terbit di hari yang sama nilainya SERI, dan tanpa tie-break
     * urutannya ditentukan MySQL — alatnya bisa mengambil tanggal dari
     * sertifikat yang salah, bergantian tiap kali sapuannya dijalankan.
     */
    public function test_dua_sertifikat_terbit_tanggal_sama_dimenangkan_id_terbaru(): void
    {
        $this->sertifikatUntuk($this->alat, '2026-03-01', '2027-03-01', '2026-03-05');
        $terbaru = $this->sertifikatUntuk($this->alat, '2026-03-02', '2027-09-09', '2026-03-05');

        app(SinkronJadwalAlat::class)->untuk($this->alat);

        $this->alat->refresh();

        $this->assertSame('2027-09-09', $this->alat->tanggal_jatuh_tempo?->toDateString());
        $this->assertSame('2026-03-02', $this->alat->tanggal_kalibrasi_terakhir?->toDateString());
        $this->assertSame(
            $terbaru->id,
            app(SinkronJadwalAlat::class)->sertifikatAktif($this->alat)?->id,
            'Seri `diterbitkan_pada` diputus sembarangan, bukan oleh id terbaru.'
        );
    }

    /** Alat lama hasil impor Excel: tanggal tulisan tangan TIDAK ditimpa. */
    public function test_alat_impor_tanpa_sertifikat_tanggal_manualnya_tetap(): void
    {
        $alat = $this->buatAlat($this->org);
        $alat->update([
            'tanggal_kalibrasi_terakhir' => '2025-05-20',
            'tanggal_jatuh_tempo' => '2026-05-20',
        ]);

        app(SinkronJadwalAlat::class)->untuk($alat->refresh());

        $alat->refresh();

        $this->assertSame('2025-05-20', $alat->tanggal_kalibrasi_terakhir?->toDateString());
        $this->assertSame('2026-05-20', $alat->tanggal_jatuh_tempo?->toDateString());
    }

    /** Sertifikat terbit yang `berlaku_sampai`-nya kosong diperlakukan seperti nggak ada. */
    public function test_sertifikat_tanpa_berlaku_sampai_tidak_menimpa_jadwal_manual(): void
    {
        $alat = $this->buatAlat($this->org);
        $alat->update(['tanggal_jatuh_tempo' => '2026-05-20']);

        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $alat->organization_id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $this->teknisi->id,
            'tanggal_kalibrasi' => '2026-01-10',
        ]);
        Certificate::factory()->create([
            'organization_id' => $alat->organization_id,
            'calibration_session_id' => $sesi->id,
            'status' => Certificate::STATUS_TERBIT,
            'diterbitkan_pada' => '2026-01-12',
            'berlaku_sampai' => null,
        ]);

        app(SinkronJadwalAlat::class)->untuk($alat->refresh());

        $this->assertSame(
            '2026-05-20',
            $alat->refresh()->tanggal_jatuh_tempo?->toDateString(),
            'Jadwal alat ditimpa null — alatnya bakal raib dari pengingat jatuh tempo.'
        );
    }

    /** Sertifikat lab lain nggak boleh kebaca, walau `equipment_id`-nya kebetulan cocok. */
    public function test_sertifikat_organisasi_lain_tidak_dipakai(): void
    {
        $lain = Organization::factory()->create(['nama' => 'PT Contoh Tiga']);
        $alatLain = $this->buatAlat($lain);

        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $lain->id,
            'equipment_id' => $alatLain->id,
            'teknisi_id' => $this->teknisi->id,
            'tanggal_kalibrasi' => '2026-01-10',
        ]);

        // Sertifikat milik organisasi LAIN, tapi sesinya menunjuk alat itu.
        Certificate::factory()->create([
            'organization_id' => $this->org->id,
            'calibration_session_id' => $sesi->id,
            'status' => Certificate::STATUS_TERBIT,
            'diterbitkan_pada' => '2026-01-12',
            'berlaku_sampai' => '2027-01-12',
        ]);

        $this->assertNull(app(SinkronJadwalAlat::class)->sertifikatAktif($alatLain));
    }

    /**
     * Jalan kedua kalinya nggak nembak UPDATE sama sekali.
     *
     * Diadu ke query log, bukan ke `updated_at`: dua pemanggilan di detik yang
     * sama menghasilkan `updated_at` yang sama walau query-nya beneran jalan,
     * jadi test yang membandingkan timestamp bakal hijau tanpa membuktikan
     * apa-apa. Perintah sapuan menyentuh seluruh alat lab, dan sebagian besar
     * di antaranya sudah benar.
     */
    public function test_sinkron_ulang_tidak_menulis_kalau_nilainya_sudah_sama(): void
    {
        $this->sertifikatUntuk($this->alat, '2026-01-10', '2027-01-10', '2026-01-12');

        $sinkron = app(SinkronJadwalAlat::class);
        $sinkron->untuk($this->alat);
        $this->alat->refresh();

        DB::enableQueryLog();
        $sinkron->untuk($this->alat);
        $update = array_filter(
            DB::getQueryLog(),
            fn (array $q): bool => str_starts_with(strtolower(ltrim($q['query'])), 'update'),
        );
        DB::disableQueryLog();

        $this->assertSame([], array_values($update), 'Sinkron ulang masih nembak UPDATE walau nilainya sudah sama.');
    }

    // ------------------------------------------------------------------- T4

    /**
     * Sertifikat yang gagal dirender nggak boleh meninggalkan jejak di alat.
     *
     * Stub-nya diikat ke container, mengikuti pola `ApproveDuaKaliSatuSertifikatTest`.
     * `GenerateCertificate` mengambil `SertifikatSatuHalaman` lewat `app()` ke
     * variabel yang tidak bertipe, jadi stub polos cukup.
     */
    public function test_sertifikat_gagal_generate_tidak_mengubah_alat(): void
    {
        $tempoAwal = $this->alat->tanggal_jatuh_tempo?->toDateString();
        $kalibrasiAwal = $this->alat->tanggal_kalibrasi_terakhir?->toDateString();

        $sesi = $this->sesiAutoklaf(now()->subDay()->toDateString());

        $this->app->bind(SertifikatSatuHalaman::class, fn () => new class
        {
            public function isi(Certificate $sertifikat): string
            {
                throw new RuntimeException('Render PDF sengaja digagalkan buat test.');
            }
        });

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['berlaku_sampai' => now()->addMonths(18)->toDateString()]);

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail();
        $this->assertSame(Certificate::STATUS_GAGAL, $sertifikat->status);

        $this->alat->refresh();

        $this->assertSame($tempoAwal, $this->alat->tanggal_jatuh_tempo?->toDateString());
        $this->assertSame($kalibrasiAwal, $this->alat->tanggal_kalibrasi_terakhir?->toDateString());
    }

    /**
     * Sinkron yang meledak membatalkan status `terbit`, bukan menyisakan
     * sertifikat terbit dengan jadwal alat yang setengah jadi.
     */
    public function test_sinkron_yang_gagal_membatalkan_status_terbit(): void
    {
        $sesi = $this->sesiAutoklaf(now()->subDay()->toDateString());

        $this->app->bind(SinkronJadwalAlat::class, fn () => new class extends SinkronJadwalAlat
        {
            public function untuk(Equipment $alat): void
            {
                throw new RuntimeException('Sinkron sengaja digagalkan buat test.');
            }
        });

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve");

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail();

        $this->assertSame(
            Certificate::STATUS_GAGAL,
            $sertifikat->status,
            'Sertifikat tetap `terbit` padahal jadwal alatnya gagal ditulis.'
        );
    }
}
