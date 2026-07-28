<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Notifications\AkunBaruMenunggu;
use App\Notifications\SertifikatGagal;
use App\Notifications\StandarMauKadaluarsa;
use App\Services\CertificateSnapshotBuilder;
use App\Services\PengingatStandar;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tiga kejadian yang butuh tindakan admin tapi dulu nggak dikabarin ke siapa pun
 * (fase-2 §2).
 *
 * Permintaannya: *"kalo ada sesuatu dan yang dibutuhkan sama admin maka semuanya
 * dikirim ke bagian admin."* Tiga yang bolong:
 *
 * 1. akun baru daftar   → orangnya kejebak di layar "belum disetujui"
 * 2. sertifikat gagal   → pelanggan nungguin dokumen yang kelihatan udah selesai
 * 3. standar mau habis  → teknisi kerja di lapangan lalu mentok `422` waktu nyimpen
 *
 * Yang dijaga di sini bukan cuma "notifikasinya kekirim", tapi juga **nggak
 * kekirim ke yang salah** dan **nggak dikirim berulang sampai orang berhenti buka
 * loncengnya**.
 */
class NotifikasiKejadianAdminTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    // ------------------------------------------------ 1. akun baru mendaftar

    public function test_pendaftar_baru_ngabarin_admin(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'nama' => 'Budi Santoso',
            'employee_id' => 'SDK-7777',
            'department' => 'Kalibrasi',
            'email' => 'budi@sidik.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertCreated();

        Notification::assertSentTo($this->admin, AkunBaruMenunggu::class);
    }

    /** Isi & tautannya harus cukup buat admin langsung nindak, bukan cuma "ada yang daftar". */
    public function test_isi_notifikasi_pendaftar_nunjuk_ke_layar_approval(): void
    {
        $pendaftar = User::factory()->create([
            'name' => 'Budi Santoso',
            'employee_id' => 'SDK-7777',
            'department' => 'Kalibrasi',
            'status' => User::STATUS_PENDING,
        ]);

        $data = AkunBaruMenunggu::dariUser($pendaftar)->toDatabase($this->admin);

        $this->assertSame('akun.menunggu_persetujuan', $data['kategori']);
        $this->assertStringContainsString('Budi Santoso', $data['body']);
        $this->assertStringContainsString('SDK-7777', $data['body']);
        $this->assertSame('users', $data['tautan']['tipe']);
        $this->assertSame('pending', $data['tautan']['filter']);
        $this->assertSame($pendaftar->id, $data['tautan']['id']);
    }

    /**
     * Akun `pending` & `nonaktif` NGGAK dikabarin.
     *
     * Yang pending nggak bisa login, jadi notifikasinya cuma numpuk — dan yang
     * lebih penting, pendaftar nggak boleh dikabarin soal pendaftar lain.
     */
    public function test_admin_pending_dan_nonaktif_nggak_dikabarin(): void
    {
        Notification::fake();

        $pending = User::factory()->admin()->create(['status' => User::STATUS_PENDING]);
        $nonaktif = User::factory()->admin()->create(['status' => User::STATUS_NONAKTIF]);

        $this->postJson('/api/register', [
            'nama' => 'Citra',
            'employee_id' => 'SDK-7778',
            'department' => 'Kalibrasi',
            'email' => 'citra@sidik.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertCreated();

        Notification::assertSentTo($this->admin, AkunBaruMenunggu::class);
        Notification::assertNotSentTo($pending, AkunBaruMenunggu::class);
        Notification::assertNotSentTo($nonaktif, AkunBaruMenunggu::class);
    }

    /** Teknisi & viewer nggak dikabarin — ini kerjaan admin. */
    public function test_teknisi_dan_viewer_nggak_dikabarin(): void
    {
        Notification::fake();

        $teknisi = User::factory()->create();
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->postJson('/api/register', [
            'nama' => 'Dewi',
            'employee_id' => 'SDK-7779',
            'department' => 'Kalibrasi',
            'email' => 'dewi@sidik.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertCreated();

        Notification::assertNotSentTo($teknisi, AkunBaruMenunggu::class);
        Notification::assertNotSentTo($viewer, AkunBaruMenunggu::class);
    }

    // -------------------------------------------- 2. sertifikat gagal dibuat

    public function test_isi_notifikasi_sertifikat_gagal_nunjuk_ke_sertifikatnya(): void
    {
        $sertifikat = $this->sertifikat();

        $data = SertifikatGagal::dariSertifikat($sertifikat, 'Class "Imagick" not found')
            ->toDatabase($this->admin);

        $this->assertSame('sertifikat.gagal', $data['kategori']);
        $this->assertSame('danger', $data['iconColor']);
        $this->assertStringContainsString((string) $sertifikat->nomor, $data['title']);
        // Alasan teknisnya ikut: tanpa itu admin nggak bisa mutusin ini cukup
        // di-retry atau perlu diteruskan ke yang ngurus server.
        $this->assertStringContainsString('Imagick', $data['body']);
        $this->assertStringContainsString('terbitkan ulang', mb_strtolower($data['body']));
        $this->assertSame('certificates', $data['tautan']['tipe']);
        $this->assertSame($sertifikat->id, $data['tautan']['id']);
    }

    /** Pesan error yang kepanjangan dipotong — lonceng jadi nggak kebaca. */
    public function test_alasan_yang_kepanjangan_dipotong(): void
    {
        $data = SertifikatGagal::dariSertifikat($this->sertifikat(), str_repeat('A', 500))
            ->toDatabase($this->admin);

        $this->assertLessThan(500, mb_strlen($data['body']));
    }

    /**
     * Jalur gagalnya BENERAN nyambung, bukan cuma payload-nya bener.
     *
     * Snapshot builder dipaksa meledak biar `GenerateCertificate` masuk ke blok
     * `catch`-nya. Ini yang ngebuktiin notifikasinya kekirim dari kejadian asli —
     * test yang cuma manggil `dariSertifikat()` bakal tetap ijo walaupun
     * pemanggilnya di job kelupaan dipasang.
     */
    public function test_sertifikat_yang_gagal_beneran_ngabarin_admin(): void
    {
        Notification::fake();

        $sesi = $this->sesiDisetujui();

        $this->app->bind(CertificateSnapshotBuilder::class, fn () => new class extends CertificateSnapshotBuilder
        {
            public function bangun(CalibrationSession $sesi, Certificate $sertifikat): array
            {
                throw new \RuntimeException('Snapshot meledak (disengaja buat test).');
            }
        });

        try {
            (new GenerateCertificate($sesi->id, $this->admin->id))->handle();
            $this->fail('Job-nya harusnya nge-throw ulang errornya.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Snapshot meledak', $e->getMessage());
        }

        // Statusnya `gagal` (biar tombol retry muncul) DAN admin dikabarin.
        $this->assertSame(Certificate::STATUS_GAGAL, $sesi->certificate->fresh()->status);
        Notification::assertSentTo($this->admin, SertifikatGagal::class);
    }

    /** Teknisi nggak dikabarin soal kegagalan teknis — dia nggak bisa nindak apa-apa. */
    public function test_teknisi_nggak_dikabarin_sertifikat_gagal(): void
    {
        Notification::fake();

        $sesi = $this->sesiDisetujui();

        $this->app->bind(CertificateSnapshotBuilder::class, fn () => new class extends CertificateSnapshotBuilder
        {
            public function bangun(CalibrationSession $sesi, Certificate $sertifikat): array
            {
                throw new \RuntimeException('Meledak.');
            }
        });

        try {
            (new GenerateCertificate($sesi->id, $this->admin->id))->handle();
        } catch (\RuntimeException) {
            // Diabaikan; yang diuji siapa yang dikabarin.
        }

        Notification::assertNotSentTo($sesi->teknisi, SertifikatGagal::class);
    }

    // ------------------------------------------ 3. standar mendekati habis

    public function test_standar_mendekati_habis_ngabarin_admin(): void
    {
        Notification::fake();

        // Ambang default 30 hari → 10 hari lagi masuk jendela peringatan.
        $this->standar(10, 'pH Buffer 7');

        $hasil = app(PengingatStandar::class)->untukOrganisasi($this->org);

        Notification::assertSentTo($this->admin, StandarMauKadaluarsa::class);
        $this->assertSame(1, $hasil['mendekati']);
        $this->assertSame(0, $hasil['kadaluarsa']);
        $this->assertSame(1, $hasil['admin_dikabarin']);
    }

    public function test_standar_yang_masih_lama_nggak_ngabarin_siapa_pun(): void
    {
        Notification::fake();

        $this->standar(900);

        $this->assertNull(app(PengingatStandar::class)->untukOrganisasi($this->org));
        Notification::assertNothingSent();
    }

    /** Standar yang udah habis dibedain, dan akibatnya disebut eksplisit. */
    public function test_standar_kadaluarsa_pesannya_nyebut_sesi_bakal_ditolak(): void
    {
        $this->standar(-25, 'pH Buffer 4');

        $hasil = app(PengingatStandar::class)->untukOrganisasi($this->org);
        $this->assertSame(1, $hasil['kadaluarsa']);

        $data = $this->admin->fresh()->notifications()->first()->data;

        $this->assertStringContainsString('KADALUARSA', $data['title']);
        $this->assertStringContainsString('ditolak', $data['body']);
        $this->assertSame('danger', $data['iconColor']);
        $this->assertSame('expired', $data['tautan']['filter']);
    }

    /** Lab lain nggak boleh kena kabar soal standar kita. */
    public function test_admin_organisasi_lain_nggak_dikabarin(): void
    {
        Notification::fake();

        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);

        $this->standar(10);
        app(PengingatStandar::class)->untukOrganisasi($this->org);

        Notification::assertSentTo($this->admin, StandarMauKadaluarsa::class);
        Notification::assertNotSentTo($adminLain, StandarMauKadaluarsa::class);
    }

    // --------------------------------------------------------- anti-spam

    /**
     * INTI yang bikin ini kepakai: scheduler jalan tiap pagi, ambangnya 30 hari.
     * Tanpa penjaga, admin dapat baris yang persis sama 30 kali berturut-turut.
     */
    public function test_isi_yang_sama_nggak_diulang_tiap_hari(): void
    {
        $this->standar(10);
        $pengingat = app(PengingatStandar::class);

        $hari1 = $pengingat->untukOrganisasi($this->org);
        $this->assertSame(1, $hari1['admin_dikabarin']);

        // Besok & besoknya lagi: isinya sama → dilewat.
        $this->travel(1)->days();
        $hari2 = $pengingat->untukOrganisasi($this->org);
        $this->travel(1)->days();
        $hari3 = $pengingat->untukOrganisasi($this->org);

        $this->assertSame(0, $hari2['admin_dikabarin'], 'Hari ke-2 harusnya dilewat.');
        $this->assertSame(1, $hari2['admin_dilewat']);
        $this->assertSame(0, $hari3['admin_dikabarin'], 'Hari ke-3 harusnya dilewat.');

        $this->assertSame(1, $this->admin->fresh()->notifications()->count());
    }

    /** Sesudah masa tenang (7 hari), diingetin lagi — biar nggak kelupaan. */
    public function test_sesudah_masa_tenang_diingetin_lagi(): void
    {
        // 20 hari: masih di dalam jendela 30 hari SEBELUM dan SESUDAH lompat 8
        // hari, dan statusnya tetap `warning` — jadi yang diuji beneran masa
        // tenangnya, bukan status yang kebetulan berubah.
        $this->standar(20);
        $pengingat = app(PengingatStandar::class);

        $pengingat->untukOrganisasi($this->org);
        $this->travel(PengingatStandar::MASA_TENANG_HARI + 1)->days();
        $pengingat->untukOrganisasi($this->org);

        $this->assertSame(2, $this->admin->fresh()->notifications()->count());
    }

    /**
     * Isi BERUBAH → dikirim saat itu juga, nggak nunggu masa tenang.
     *
     * Standar baru masuk jendela peringatan itu kabar baru, dan itu justru saat
     * yang paling nggak boleh telat.
     */
    public function test_standar_baru_masuk_jendela_langsung_dikabarin(): void
    {
        $this->standar(10, 'pH Buffer 7');
        $pengingat = app(PengingatStandar::class);

        $pengingat->untukOrganisasi($this->org);
        $this->assertSame(1, $this->admin->fresh()->notifications()->count());

        // Besoknya, standar KEDUA masuk jendela → tanda tangannya beda.
        $this->travel(1)->days();
        $this->standar(15, 'pH Buffer 10');

        $hasil = $pengingat->untukOrganisasi($this->org);

        $this->assertSame(1, $hasil['admin_dikabarin'], 'Isi berubah harusnya langsung dikabarin.');
        $this->assertSame(2, $this->admin->fresh()->notifications()->count());
    }

    /** Admin baru tetap dapat kabar pertamanya walaupun admin lama udah dikabarin. */
    public function test_admin_baru_tetap_dapat_kabar_pertama(): void
    {
        $this->standar(10);
        $pengingat = app(PengingatStandar::class);

        $pengingat->untukOrganisasi($this->org);

        $adminBaru = User::factory()->admin()->create();
        $hasil = $pengingat->untukOrganisasi($this->org);

        $this->assertSame(1, $hasil['admin_dikabarin']);
        $this->assertSame(1, $adminBaru->fresh()->notifications()->count());
        // Yang lama tetap satu — nggak keulang.
        $this->assertSame(1, $this->admin->fresh()->notifications()->count());
    }

    /**
     * Tanda tangan yang dipakai penjaga harus SAMA dengan yang disimpen notifikasi.
     *
     * Dihitung di dua tempat (service buat mutusin kirim/nggak, notifikasi buat
     * disimpen). Kalau geser sendiri-sendiri, penjaganya nggak pernah nyocok dan
     * notifikasinya keulang tiap hari lagi — dan itu nggak keliatan sebagai error.
     */
    public function test_tanda_tangan_service_sama_dengan_yang_disimpen_notifikasi(): void
    {
        $this->standar(10);
        app(PengingatStandar::class)->untukOrganisasi($this->org);

        $data = $this->admin->fresh()->notifications()->first()->data;
        $rincian = $data['tautan']['standar'];

        $this->assertSame(
            app(PengingatStandar::class)->tandaTangan($rincian),
            $data['tanda_tangan'],
        );
    }

    /** Command-nya beneran jalan & kelihatan di scheduler. */
    public function test_command_terdaftar_dan_jalan(): void
    {
        $this->standar(10);

        $this->artisan('standar:cek-kadaluarsa')
            ->assertSuccessful();

        $this->assertSame(1, $this->admin->fresh()->notifications()->count());
    }

    public function test_command_terjadwal_harian(): void
    {
        $perintah = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command)
            ->filter(fn (?string $c): bool => $c !== null && str_contains($c, 'standar:cek-kadaluarsa'));

        $this->assertCount(1, $perintah, 'Command-nya harus terjadwal, bukan cuma bisa dipanggil manual.');
    }

    // ------------------------------------------------------------- pembantu

    /**
     * Standar yang sertifikatnya habis `$hariLagi` hari dari sekarang.
     *
     * Sengaja RELATIF, bukan tanggal mati: test yang nulis `2026-08-05` bakal
     * lolos hari ini dan diam-diam kehilangan artinya tahun depan — tanggal itu
     * berhenti "10 hari lagi" dan jadi masa lalu.
     */
    private function standar(int $hariLagi, string $nama = 'pH Buffer 7'): Standard
    {
        return Standard::factory()->create([
            'nama' => $nama,
            'berlaku_sampai' => now()->addDays($hariLagi)->toDateString(),
        ]);
    }

    private function sertifikat(): Certificate
    {
        return Certificate::factory()->create([
            'calibration_session_id' => $this->sesi()->id,
            'status' => Certificate::STATUS_GAGAL,
        ]);
    }

    private function sesi(): CalibrationSession
    {
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
            'satuan' => 'mm', 'toleransi' => 0.05,
        ]);

        return CalibrationSession::factory()->create([
            'teknisi_id' => User::factory()->create()->id,
            'equipment_id' => $alat->id,
        ]);
    }

    /** Sesi yang siap diterbitin — dipakai test jalur gagal. */
    private function sesiDisetujui(): CalibrationSession
    {
        Storage::fake('local');

        $sesi = $this->sesi();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        return $sesi->fresh();
    }
}
