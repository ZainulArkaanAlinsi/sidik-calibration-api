<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\CalibrationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Satu sesi kalibrasi = satu sertifikat, walau tombol "Setujui" ditekan dua kali.
 *
 * ## Kenapa berkas ini ada
 *
 * Penjaga idempoten di `GenerateCertificate` cuma menahan sertifikat yang
 * **sudah** `terbit` — status yang baru tercapai sesudah transaksinya selesai
 * DAN PDF-nya berhasil ditulis (~1–2 detik, karena job-nya dijalankan langsung,
 * bukan lewat antrean). Selama jendela itu, permintaan kedua melihat "belum
 * terbit" dan masuk juga.
 *
 * Dua hal lain memperlebar jendelanya:
 *
 * - `$calibration->update(['status' => DISETUJUI])` tidak bersyarat pada status
 *   asal — bukan `UPDATE … WHERE status = 'menunggu_approval'`. Pemeriksaan
 *   statusnya dilakukan di awal `approve()`, jauh sebelum baris itu, dengan
 *   validasi + pemeriksaan OCR + `CalibrationValidator::periksa()` (yang
 *   menyentuh database) di antaranya.
 * - `updateOrCreate(['calibration_session_id' => …])` tidak memakai
 *   `lockForUpdate()`.
 *
 * Dan tidak ada jaring pengaman DB: migrasi `certificates` punya
 * `unique(['organization_id','nomor'])` dan `qr_token` unik, tapi **tidak**
 * punya unique pada `calibration_session_id`. Karena `nomorBerikutnya()` sudah
 * memakai `lockForUpdate()` yang menyerialkan penomoran, dua transaksi yang
 * bertabrakan tidak akan gagal constraint apa pun: keduanya commit dengan nomor
 * valid dan berurutan, `qr_token` beda, PDF beda.
 *
 * **Satu event kalibrasi dengan dua nomor sertifikat resmi yang sama-sama sah
 * di database.** Untuk lab terakreditasi itu temuan audit.
 *
 * ## Kenapa bukan unique index
 *
 * Kolom `revision_of` di tabel yang sama menyiratkan satu sesi suatu hari punya
 * lebih dari satu baris sertifikat — yang asli plus revisinya. Unique index
 * bakal menutup jalan itu untuk menyelesaikan balapan yang bisa diselesaikan
 * lock, yaitu menukar satu masalah dengan batasan arsitektur.
 */
class ApproveDuaKaliSatuSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CalibrationSession $sesi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('arsip');

        $org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $org->id]);
        $teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
        ]);

        // Sesi AUTOKLAF, bukan sesi factory polos.
        //
        // Yang diuji di sini balapan approve-nya, dan itu cuma bisa diuji lewat
        // sesi yang BENERAN lolos `CalibrationValidator`. Sesi factory polos
        // ditolak `titik_kosong` (nol `uncertainty_calculations`) dan tidak
        // pernah sampai ke baris yang jadi soal. Autoklaf satu-satunya bentuk
        // sesi yang sah tanpa titik hasil hitung — lihat
        // `AutoclaveCertificateTest`.
        $alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $org->id,
            ])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);

        $id = $this->actingAs($teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', [
                'equipment_id' => $alat->id,
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

        $this->sesi = CalibrationSession::findOrFail($id);
    }

    private function setujui(): TestResponse
    {
        return $this->actingAs($this->admin)->postJson(
            "/api/calibrations/{$this->sesi->id}/approve",
            ['abaikan_peringatan' => true],
        );
    }

    /**
     * INTI bug-nya, dari sisi yang dilihat admin.
     *
     * Klik kedua tidak boleh menerbitkan sertifikat kedua. Yang diadu jumlah
     * barisnya — bukan status responsnya — karena baris kedua itu yang jadi
     * temuan audit, apa pun yang tampil di layar.
     */
    public function test_approve_dua_kali_cuma_nerbitin_satu_sertifikat(): void
    {
        $this->setujui()->assertSuccessful();
        $this->setujui();

        $this->assertSame(
            1,
            Certificate::where('calibration_session_id', $this->sesi->id)->count(),
            'Satu sesi kalibrasi punya dua sertifikat resmi di database.'
        );
    }

    /**
     * Balapan yang sebenarnya, ditiru di titik yang benar.
     *
     * Dua klik BERURUTAN tidak pernah sampai ke baris yang jadi soal —
     * pemeriksaan status di awal `approve()` sudah memulangkan 422 (dan itu
     * penjagaan lama yang memang benar). Yang bocor cuma dua permintaan yang
     * BERBARENGAN: dua-duanya lolos pemeriksaan awal itu, lalu dua-duanya
     * sampai ke `update()`.
     *
     * Jendelanya ditiru dengan menggeser status TEPAT waktu
     * `CalibrationValidator::periksa()` jalan — itu memang salah satu langkah
     * yang duduk di antara pemeriksaan awal dan penulisan status, dan yang
     * paling lama karena dia menyentuh database.
     *
     * Tanpa transisi bersyarat, permintaan ini menimpa status yang sudah
     * `disetujui` dan memanggil `GenerateCertificate` untuk kedua kalinya.
     */
    public function test_permintaan_yang_kalah_balapan_dijawab_409(): void
    {
        $sesi = $this->sesi;

        $this->app->extend(CalibrationValidator::class, fn (CalibrationValidator $asli) => new class($asli, $sesi) extends CalibrationValidator
        {
            public function __construct(
                private readonly CalibrationValidator $asli,
                private readonly CalibrationSession $sesi,
            ) {}

            public function periksa(CalibrationSession $sesi): array
            {
                $hasil = $this->asli->periksa($sesi);

                // Permintaan "lain" menang di sini, selagi yang ini masih
                // memeriksa.
                CalibrationSession::whereKey($this->sesi->id)
                    ->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

                return $hasil;
            }
        });

        $this->setujui()
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $p): bool => str_contains($p, 'sudah disetujui'));

        $this->assertSame(
            0,
            Certificate::where('calibration_session_id', $this->sesi->id)->count(),
            'Sertifikat tetap dibikin walau permintaannya kalah balapan.'
        );
    }

    /**
     * Lapis kedua, diuji langsung di job-nya.
     *
     * `GenerateCertificate` dipanggil dari `approve()` hari ini, tapi dia job
     * yang bisa di-retry sendiri — dan retry yang mendarat waktu sertifikatnya
     * sudah ada tidak boleh bikin baris kedua. Sengaja menembus controller
     * supaya penjagaan di sini terbukti sendiri, bukan menumpang penjagaan
     * di atasnya.
     */
    public function test_job_dijalankan_dua_kali_tetap_satu_sertifikat(): void
    {
        $this->sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        app()->call([new GenerateCertificate($this->sesi->id, $this->admin->id, null), 'handle']);
        app()->call([new GenerateCertificate($this->sesi->id, $this->admin->id, null), 'handle']);

        $this->assertSame(
            1,
            Certificate::where('calibration_session_id', $this->sesi->id)->count(),
        );
    }

    /**
     * JANGAN kebablasan #1: approve yang PERTAMA tetap menerbitkan sertifikat
     * lengkap dengan nomornya.
     *
     * Kalau test ini merah, penjagaannya menelan jalur yang benar — dan
     * gejalanya persis sama dengan sertifikat yang tidak pernah terbit.
     */
    public function test_approve_pertama_tetap_nerbitin_sertifikat_utuh(): void
    {
        $this->setujui()->assertSuccessful();

        $sertifikat = Certificate::where('calibration_session_id', $this->sesi->id)->firstOrFail();

        $this->assertNotNull($sertifikat->nomor);
        $this->assertNotNull($sertifikat->qr_token);
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
        $this->assertSame(
            CalibrationSession::STATUS_DISETUJUI,
            $this->sesi->fresh()->status,
        );
    }

    /**
     * JANGAN kebablasan #2: sesi yang statusnya BUKAN `menunggu_approval` tetap
     * ditolak dengan pesan yang lama (422), bukan tertelan jadi 409.
     *
     * Dua kondisi ini beda artinya buat admin: "belum siap disetujui" lawan
     * "barusan disetujui orang lain".
     */
    public function test_sesi_draft_tetap_ditolak_422(): void
    {
        $this->sesi->update(['status' => CalibrationSession::STATUS_DRAFT]);

        $this->setujui()->assertStatus(422);
    }
}
