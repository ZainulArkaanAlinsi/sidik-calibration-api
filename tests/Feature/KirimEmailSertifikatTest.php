<?php

namespace Tests\Feature;

use App\Mail\SertifikatKePelanggan;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\CertificateEmailLog;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Kirim sertifikat ke email pelanggan (fase-2 §3d).
 *
 * Dua alasan permintaannya naruh ini di backend, dan dua-duanya diuji di sini:
 *
 * 1. **Alamat pengirim harus domain lab**, bukan Gmail teknisi.
 * 2. **Pengirimannya tercatat buat audit** — siapa ngirim ke siapa, kapan. Termasuk
 *    yang GAGAL: "kami udah nyoba tapi alamatnya nolak" itu justru informasi yang
 *    dicari waktu pelanggan ngaku nggak nerima.
 *
 * Semua dites pakai `Mail::fake()` — jadi 100% bisa diuji TANPA kredensial SMTP.
 * Yang beneran nunggu SMTP cuma pengiriman sungguhan ke internet.
 */
class KirimEmailSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'nama' => 'PT SIDIK KALIBRASI',
            'email' => 'lab@pt-sidik.com',
            'no_akreditasi' => 'LK-285-IDN',
        ]);
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
    }

    /** Sertifikat terbit lengkap dengan PDF di disk. */
    private function sertifikatTerbit(): Certificate
    {
        Storage::fake('local');

        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create([
                'nama' => 'PT Tirta Gracia',
                'email' => 'pic@tirta.co.id',
            ])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
            'satuan' => 'pH', 'toleransi' => 0.05,
        ]);

        $sesi = CalibrationSession::factory()->create([
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => $alat->id,
            'tanggal_kalibrasi' => '2026-07-20',
        ]);

        $sertifikat = Certificate::factory()->create([
            'calibration_session_id' => $sesi->id,
            'status' => Certificate::STATUS_TERBIT,
            'nomor' => '012-CAL-524',
            'qr_token' => 'QRKIRIM123',
            'qr_payload' => 'http://localhost/verify/QRKIRIM123',
            'pdf_path' => 'certificates/QRKIRIM123.pdf',
        ]);

        Storage::disk('local')->put('certificates/QRKIRIM123.pdf', '%PDF-1.7 palsu buat test');

        return $sertifikat->fresh();
    }

    private function url(Certificate $c): string
    {
        return "/api/certificates/{$c->id}/kirim-email";
    }

    // --------------------------------------------------------- jalur normal

    public function test_admin_bisa_kirim_sertifikat(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertOk()
            ->assertJsonPath('data.status', 'terkirim')
            ->assertJsonPath('data.ke', ['pic@tirta.co.id']);

        Mail::assertSent(SertifikatKePelanggan::class, fn ($mail) => $mail->hasTo('pic@tirta.co.id'));
    }

    // ------------------------------------------------- mailer yang nggak ngirim

    /**
     * `MAIL_MAILER=log` nulis email ke `laravel.log` dan nggak pernah ngelempar
     * exception, jadi dulu responsnya bilang "Sertifikat (PDF) terkirim ke ..."
     * buat email yang nggak pernah keluar. Admin nungguin balasan pelanggan yang
     * nggak bakal datang, dan nggak ada apa pun di layar yang ngasih tahu kenapa.
     */
    public function test_mailer_log_diakui_belum_beneran_terkirim(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $sertifikat = $this->sertifikatTerbit();

        $respons = $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertOk();

        $this->assertStringContainsString('Belum beneran terkirim', $respons->json('peringatan'));
        $this->assertStringContainsString('MAIL_MAILER', $respons->json('peringatan'));

        // Pesan utamanya juga harus jujur: app cuma nampilin `message`, jadi
        // peringatan yang cuma nyempil di field lain sama aja nggak ada.
        $this->assertStringNotContainsString('terkirim ke pic@tirta.co.id', $respons->json('message'));
    }

    public function test_mailer_beneran_tetap_lapor_terkirim(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertOk()
            ->assertJsonPath('peringatan', null)
            ->assertJsonPath('data.status', 'terkirim');
    }

    public function test_cc_ikut_terkirim(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), [
                'ke' => ['pic@tirta.co.id'],
                'cc' => ['manajer@tirta.co.id'],
            ])
            ->assertOk();

        Mail::assertSent(
            SertifikatKePelanggan::class,
            fn ($mail) => $mail->hasTo('pic@tirta.co.id') && $mail->hasCc('manajer@tirta.co.id'),
        );
    }

    /**
     * PDF-nya DILAMPIRKAN, bukan dikirim sebagai tautan unduh.
     *
     * Tautan ke disk privat butuh login dan pelanggan nggak punya akun; tautan
     * publik berarti sertifikat bisa diakses siapa pun yang dapat URL-nya. Lampiran
     * itu jalan yang bener buat dokumen resmi.
     *
     * Diperiksa dari objek `Attachment` yang dibalikin `attachments()`, bukan lewat
     * `assertHasAttachmentFromStorageDisk` — helper itu ngebandingin bentuk internal
     * Laravel, sementara yang mau dijamin di sini keputusan KODE GW: diambil dari
     * disk privat, dan dinamain nomor sertifikatnya (bukan nama token di disk, yang
     * nggak berarti apa-apa buat penerima).
     */
    public function test_pdf_sertifikat_dilampirkan(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $lampiran = (new SertifikatKePelanggan($sertifikat))->attachments();

        $this->assertCount(1, $lampiran);
        $this->assertSame('Sertifikat-012-CAL-524.pdf', $lampiran[0]->as);
        $this->assertSame('application/pdf', $lampiran[0]->mime);
    }

    /** Sertifikat tanpa PDF nggak bawa lampiran — dan pemanggilnya nolak duluan. */
    public function test_tanpa_pdf_nggak_ada_lampiran(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $sertifikat->update(['pdf_path' => null]);

        $this->assertSame([], (new SertifikatKePelanggan($sertifikat->fresh()))->attachments());
    }

    /**
     * Email lab ditaruh di `Reply-To`, BUKAN `From`.
     *
     * `From` yang domainnya beda dari domain pengirim bikin SPF/DKIM gagal dan
     * email-nya masuk spam. Jadi `From` tetap `MAIL_FROM_ADDRESS` (dikonfigurasi ke
     * domain lab), dan balasan pelanggan diarahkan ke email lab lewat `Reply-To`.
     */
    public function test_email_lab_di_reply_to_bukan_from(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertOk();

        Mail::assertSent(SertifikatKePelanggan::class, function ($mail): bool {
            $envelope = $mail->envelope();

            $this->assertSame('lab@pt-sidik.com', $envelope->replyTo[0]->address);
            // `from` dibiarin NULL di envelope → Laravel jatuh ke MAIL_FROM_ADDRESS,
            // yang dikonfigurasi ke domain lab. Kalau di sini diisi
            // `organization.email`, SPF/DKIM-nya gagal dan email masuk spam.
            $this->assertNull($envelope->from);

            return true;
        });
    }

    public function test_subjeknya_nyebut_nomor_sertifikat(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertOk();

        Mail::assertSent(
            SertifikatKePelanggan::class,
            fn ($mail) => str_contains($mail->envelope()->subject, '012-CAL-524'),
        );
    }

    // ---------------------------------------------------- catatan audit

    /** Pengiriman kecatat: siapa, ke siapa, kapan. */
    public function test_pengiriman_kecatat_lengkap(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), [
                'ke' => ['pic@tirta.co.id'],
                'cc' => ['manajer@tirta.co.id'],
            ])
            ->assertOk();

        $log = CertificateEmailLog::latest('id')->first();

        $this->assertSame($sertifikat->id, $log->certificate_id);
        $this->assertSame(['pic@tirta.co.id'], $log->ke);
        $this->assertSame(['manajer@tirta.co.id'], $log->cc);
        $this->assertSame(CertificateEmailLog::STATUS_TERKIRIM, $log->status);
        $this->assertSame($this->admin->id, $log->dikirim_oleh);
        $this->assertNotNull($log->created_at);
    }

    /**
     * Percobaan yang GAGAL juga kecatat.
     *
     * "Kami udah nyoba kirim tapi alamatnya nolak" itu justru informasi yang dicari
     * waktu pelanggan ngaku nggak nerima. Kalau cuma yang sukses dicatat, riwayatnya
     * bohong lewat kelalaian.
     */
    public function test_pengiriman_gagal_tetap_kecatat_dan_balik_502(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        // Transport-nya dipaksa meledak.
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP connect() failed'));

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertStatus(502)
            ->assertJsonPath('data.status', 'gagal');

        $log = CertificateEmailLog::latest('id')->first();

        $this->assertSame(CertificateEmailLog::STATUS_GAGAL, $log->status);
        $this->assertStringContainsString('SMTP connect() failed', $log->error);
        $this->assertSame($this->admin->id, $log->dikirim_oleh, 'Siapa yang nyoba tetap kecatat.');
    }

    /** Catatan pengiriman nggak bisa diubah — kalau bisa, dia berhenti jadi bukti. */
    public function test_catatan_pengiriman_nggak_bisa_diubah(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();
        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])->assertOk();

        $log = CertificateEmailLog::latest('id')->first();

        $this->expectException(\RuntimeException::class);
        $log->update(['ke' => ['diganti@nakal.com']]);
    }

    public function test_riwayat_pengiriman_kebaca_terbaru_dulu(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();

        foreach (['satu@x.com', 'dua@x.com'] as $alamat) {
            $this->actingAs($this->admin)
                ->postJson($this->url($sertifikat), ['ke' => [$alamat]])->assertOk();
        }

        $res = $this->actingAs($this->admin)
            ->getJson("/api/certificates/{$sertifikat->id}/riwayat-email")
            ->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertSame(['dua@x.com'], $res->json('data.0.ke'), 'Terbaru dulu.');
        $this->assertSame($this->admin->name, $res->json('data.0.dikirim_oleh.nama'));
    }

    // -------------------------------------------------------- penolakan

    /**
     * Sertifikat yang belum terbit NGGAK bisa dikirim.
     *
     * Email sertifikat tanpa lampirannya lebih buruk daripada nggak ngirim:
     * pelanggan nganggep udah beres, padahal dokumennya nggak ada.
     */
    public function test_sertifikat_belum_terbit_ditolak(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();
        $sertifikat->update(['status' => Certificate::STATUS_GAGAL]);

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertSame(0, CertificateEmailLog::count(), 'Yang ditolak duluan nggak perlu dicatat.');
    }

    /**
     * Status terbit TAPI PDF-nya nggak ada → pesannya nyebut PDF, bukan status.
     *
     * Digabung jadi satu pesan bikin admin bingung: "harus terbit & punya PDF, yang
     * ini statusnya `terbit`" kedengeran kayak backend-nya ngaco.
     */
    public function test_pesan_penolakan_misahin_status_dan_pdf_hilang(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();
        $sertifikat->update(['pdf_path' => null]);

        $res = $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertStatus(422);

        $this->assertStringContainsString('belum punya berkas PDF', (string) $res->json('message'));
        $this->assertStringNotContainsString('statusnya', (string) $res->json('message'));
    }

    /** Baris bilang terbit tapi file PDF-nya raib. */
    public function test_pdf_yang_raib_ditolak(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();
        Storage::disk('local')->delete($sertifikat->pdf_path);

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_alamat_tujuan_wajib(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ke');
    }

    public function test_alamat_email_ngawur_ditolak(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['bukan-email']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ke.0');

        Mail::assertNothingSent();
    }

    public function test_alamat_dobel_digabung(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), [
                'ke' => ['pic@tirta.co.id', 'pic@tirta.co.id'],
            ])
            ->assertOk()
            ->assertJsonPath('data.ke', ['pic@tirta.co.id']);
    }

    public function test_terlalu_banyak_tujuan_ditolak(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();

        $banyak = array_map(fn (int $i): string => "orang{$i}@x.com", range(1, 11));

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => $banyak])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ke');
    }

    // ------------------------------------------------------------ akses

    /** Admin doang — ini ngirim dokumen resmi ke luar. */
    public function test_teknisi_dan_viewer_ditolak(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->teknisi)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]))
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_sertifikat_lab_lain_dapat_404(): void
    {
        Mail::fake();
        $sertifikat = $this->sertifikatTerbit();

        $orgLain = Organization::factory()->create();
        $sertifikat->update(['organization_id' => $orgLain->id]);

        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertNotFound();

        Mail::assertNothingSent();
    }

    public function test_riwayat_email_ditolak_buat_non_admin(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $this->actingAs($this->teknisi)
            ->getJson("/api/certificates/{$sertifikat->id}/riwayat-email")
            ->assertForbidden();
    }

    // ------------------------------------------------------ tanpa SMTP

    /**
     * Bisa dites 100% tanpa kredensial SMTP.
     *
     * `MAIL_MAILER` di test itu `array` (lihat `phpunit.xml`), dan di dev `log` —
     * dua-duanya nggak nyentuh internet. Yang beneran nunggu kredensial cuma
     * pengiriman sungguhan; kodenya nggak perlu diubah sama sekali begitu SMTP
     * dikasih.
     */
    public function test_jalan_tanpa_kredensial_smtp(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        // Tanpa `Mail::fake()` — pakai mailer `array` bawaan test.
        $this->actingAs($this->admin)
            ->postJson($this->url($sertifikat), ['ke' => ['pic@tirta.co.id']])
            ->assertOk()
            ->assertJsonPath('data.status', 'terkirim');

        $this->assertSame(
            CertificateEmailLog::STATUS_TERKIRIM,
            CertificateEmailLog::latest('id')->first()->status,
        );
    }

    /** Badan email-nya kerender tanpa error, dan nggak bawa angka hasil ukur. */
    public function test_badan_email_kerender_dan_nggak_bawa_angka_hasil_ukur(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $isi = (new SertifikatKePelanggan($sertifikat->load(['organization', 'session.equipment.customer'])))
            ->render();

        $this->assertStringContainsString('012-CAL-524', $isi);
        $this->assertStringContainsString('PT SIDIK KALIBRASI', $isi);
        $this->assertStringContainsString('QRKIRIM123', $isi, 'Tautan verifikasi ikut.');
        // Angka yang mengikat cuma ada di PDF — kalau badan email ikut nyebut
        // angka, dia bisa beda dari PDF sesudah sertifikat direvisi.
        $this->assertStringNotContainsString('U95', $isi);
        $this->assertStringNotContainsString('ketidakpastian', mb_strtolower($isi));
    }
}
