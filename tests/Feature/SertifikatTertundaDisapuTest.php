<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Penjaga `sertifikat:sapu-tertunda`.
 *
 * Perintah itu ada karena penerbitan sertifikat jalan di antrean, dan antrean
 * punya satu kegagalan yang tidak berbunyi: worker mati → approve tetap 200 OK →
 * sertifikat berhenti di `menunggu_generate` selamanya, `failed_jobs` nol, nol
 * error di mana pun. 21 Sep 2026 ada 9 baris produksi dalam keadaan itu.
 *
 * Yang diuji di sini bukan cuma "sapuannya jalan", tapi tiga hal yang kalau
 * salah TIDAK menghasilkan error:
 *
 *  - dia tidak merebut job yang masih benar-benar merender;
 *  - dia tidak menyentuh sertifikat yang sudah selesai;
 *  - dia tidak mengubah satu pun keputusan yang sudah diambil manusia.
 */
class SertifikatTertundaDisapuTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();

        Queue::fake();
    }

    /** Sertifikat yang diam lewat ambang didorong ulang ke antrean. */
    public function test_sertifikat_tersangkut_didorong_ulang(): void
    {
        $sertifikat = Certificate::factory()->menungguGenerate()->create([
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('sertifikat:sapu-tertunda')->assertSuccessful();

        Queue::assertPushed(
            GenerateCertificate::class,
            fn (GenerateCertificate $job): bool => $job->calibrationSessionId === $sertifikat->calibration_session_id,
        );
    }

    /**
     * Yang baru saja dibuat TIDAK disentuh.
     *
     * Ini kasus yang paling mudah salah: ambang yang kependekan bikin penyapu
     * mendorong ulang job yang sebenarnya masih merender, dan dua job yang sama
     * jalan bersamaan adalah keadaan yang penjagaan nomor & token di
     * `GenerateCertificate::handle()` ada untuk mencegah akibat terburuknya.
     */
    public function test_sertifikat_yang_masih_segar_dibiarkan(): void
    {
        Certificate::factory()->menungguGenerate()->create([
            'updated_at' => now()->subMinutes(3),
        ]);

        $this->artisan('sertifikat:sapu-tertunda')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** Sertifikat yang sudah terbit bukan urusan penyapu. */
    public function test_sertifikat_terbit_dibiarkan(): void
    {
        Certificate::factory()->create([
            'status' => Certificate::STATUS_TERBIT,
            'pdf_path' => 'certificates/sudah-ada.pdf',
            'updated_at' => now()->subDay(),
        ]);

        $this->artisan('sertifikat:sapu-tertunda')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * `menunggu_generate` TAPI PDF-nya sudah ada bukan tersangkut.
     *
     * Kalau `pdf_path` sudah terisi, render-nya sudah selesai dan yang tertinggal
     * cuma stempel statusnya. Mendorong ulang cuma bikin pekerjaan ganda.
     */
    public function test_yang_pdfnya_sudah_ada_dibiarkan(): void
    {
        Certificate::factory()->menungguGenerate()->create([
            'pdf_path' => 'certificates/sudah-ada.pdf',
            'updated_at' => now()->subDay(),
        ]);

        $this->artisan('sertifikat:sapu-tertunda')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * Penerbit & masa berlaku DIWARISKAN dari barisnya — ini inti perintahnya.
     *
     * `updateOrCreate` di `GenerateCertificate::handle()` menulis ULANG kedua
     * kolom itu tiap job jalan. Mendorong ulang dengan nilai kosong berarti
     * catatan siapa yang menyetujui hilang, dan tanggal yang dipilih admin waktu
     * approve ditimpa default organisasi — di dokumen yang akan dicetak, tanpa
     * satu pun error. Sapuan ini pemulihan teknis, bukan penerbitan baru.
     */
    public function test_penerbit_dan_masa_berlaku_diwariskan(): void
    {
        $sertifikat = Certificate::factory()->menungguGenerate()->create([
            'issued_by' => $this->admin->id,
            'berlaku_sampai' => '2028-03-31',
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('sertifikat:sapu-tertunda')->assertSuccessful();

        Queue::assertPushed(
            GenerateCertificate::class,
            fn (GenerateCertificate $job): bool => $job->calibrationSessionId === $sertifikat->calibration_session_id
                && $job->issuedBy === $this->admin->id
                && $job->berlakuSampai === '2028-03-31',
        );
    }

    /**
     * Sertifikat yang SESINYA belum disetujui tidak disentuh.
     *
     * `GenerateCertificate::handle()` berhenti di baris pertamanya kalau sesi
     * bukan `disetujui`, dan berhenti TANPA SUARA - job-nya selesai "sukses"
     * tanpa mengerjakan apa pun. Tanpa saringan ini penyapu mendorongnya tiap
     * sepuluh menit selamanya sambil menulis peringatan tiap kali, dan
     * peringatan yang tidak berarti menenggelamkan yang berarti.
     *
     * Keadaannya nyata: 21 Sep 2026 ada 6 baris produksi seperti ini - sesinya
     * kembali ke `menunggu_approval` sementara baris sertifikatnya tertinggal
     * di `menunggu_generate`. Sistem menolak menerbitkannya, dan penolakan itu
     * BENAR: sertifikat tidak boleh terbit untuk sesi yang belum disetujui.
     */
    public function test_sesi_yang_belum_disetujui_tidak_didorong(): void
    {
        $sesi = CalibrationSession::factory()->create([
            'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
        ]);

        Certificate::factory()->menungguGenerate()->create([
            'calibration_session_id' => $sesi->id,
            'updated_at' => now()->subDay(),
        ]);

        $this->artisan('sertifikat:sapu-tertunda')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** `--kosongan` melaporkan tanpa mendorong apa pun. */
    public function test_kosongan_tidak_mendorong(): void
    {
        Certificate::factory()->menungguGenerate()->create([
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('sertifikat:sapu-tertunda', ['--kosongan' => true])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** `--batas` menahan jumlah yang didorong sekali jalan. */
    public function test_batas_dihormati(): void
    {
        Certificate::factory()->count(3)->menungguGenerate()->create([
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('sertifikat:sapu-tertunda', ['--batas' => 2])->assertSuccessful();

        Queue::assertPushed(GenerateCertificate::class, 2);
    }

    /**
     * Inilah kenapa penyapunya harus ada: `retry()` tidak bisa menjangkau mereka.
     *
     * Tombol "Terbitkan ulang" cuma menerima sertifikat berstatus `gagal`. Yang
     * tersangkut di `menunggu_generate` ditolak 422 — admin tidak punya jalan
     * pemulihan sama sekali tanpa masuk database manual. Kalau suatu saat ada
     * yang melonggarkan penjagaan ini, test ini yang memberi tahu bahwa alasan
     * keberadaan penyapu ikut berubah.
     */
    public function test_retry_tidak_bisa_menjangkau_yang_tersangkut(): void
    {
        $sertifikat = Certificate::factory()->menungguGenerate()->create([
            'updated_at' => now()->subHour(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/certificates/{$sertifikat->id}/retry")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }
}
