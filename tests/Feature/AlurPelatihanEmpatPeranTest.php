<?php

namespace Tests\Feature;

use App\Mail\Pelanggan\UndanganEmail;
use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\PaketAcuanVersi;
use App\Models\Standard;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\Acuan\SumberAcuan;
use App\Support\VolumetricGlasswareMentah as M;
use Database\Seeders\CalibrationCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\JalurPelanggan;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * Alur pelatihan 4 peran, ujung ke ujung lewat HTTP NYATA.
 *
 * Tujuan berkas ini BUKAN menjaga satu aturan, melainkan meniru apa yang akan
 * dilakukan peserta pelatihan dari aplikasi — dan menemukan galat yang akan
 * mereka temui lebih dulu. Karena itu:
 *
 *  - Setiap peran masuk lewat `POST /api/login` (atau `/pelanggan/v1/auth/masuk`)
 *    dan memakai token yang DITERBITKAN endpoint itu. Tidak ada `actingAs()`.
 *  - Akun pelanggan lahir lewat alur undangan sungguhan (admin lab -> email ->
 *    `terima-undangan`), akun super admin lewat `php artisan akun:super-admin`.
 *  - Data sintetis semua (PT CONTOH, DEMO-0001); SQLite memori; disk `local` dan
 *    `arsip` dipalsukan oleh `TestCase`; antrean `sync`, jadi `GenerateCertificate`
 *    benar-benar jalan dan PDF-nya benar-benar dirender.
 *
 * Satu-satunya jalan pintas: `forgetGuards()` di antara dua request. Itu bukan
 * jalan pintas pengujian terhadap aplikasi, melainkan kompensasi untuk satu
 * instance aplikasi yang melayani banyak request di dalam satu test
 * (`RequestGuard` menyimpan user request pertama) -- lihat
 * `Tests\Concerns\JalurPelanggan::lupakanSesiGuard()`.
 *
 * Angka masukan Labu Ukur / Pipet Volume / Gelas Ukur disalin dari
 * `VolumetrikRev7AlurTest` (INPUT DATA workbook lab); yang dinilai di sini adalah
 * ALUR dan bentuk respons, bukan kebenaran angka (itu tugas test kalkulasi).
 */
class AlurPelatihanEmpatPeranTest extends TestCase
{
    use JalurPelanggan;
    use RefreshDatabase;
    use WorkbookUji;

    private const SANDI = 'password';

    private Organization $lab;

    private Organization $labLain;

    private EquipmentCategory $kategori;

    private Customer $pt;

    private Customer $ptLain;

    /** @var array<string, User> */
    private array $akun = [];

    /** @var array<string, string> */
    private array $token = [];

    /** @var array<string, Standard> */
    private array $standar = [];

    private string $sandiSuperAdmin = '';

    private int $urutAlat = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-08 10:00:00');
        $this->lab = Organization::factory()->create();
        $this->labLain = Organization::factory()->create();
        $this->siapkanJalurPelanggan();

        $this->kategori = EquipmentCategory::factory()->create([
            'organization_id' => $this->lab->id, 'kode' => 'volume', 'nama' => 'Volume',
        ]);

        foreach ([
            'Labu Ukur' => [[100, 0.026], [200, 0.044], [250, 0.044], [500, 0.077]],
            'Pipet Volume' => [[0.5, 0.002], [1, 0.003], [2, 0.0034]],
            'Gelas Ukur' => [[10, 0.067], [50, 0.17], [100, 0.34]],
        ] as $nama => $pita) {
            foreach ($pita as [$maks, $cmc]) {
                CalibrationCapability::forceCreate([
                    'sumber' => CalibrationCapability::SUMBER_AKREDITASI,
                    'organization_id' => $this->lab->id,
                    'equipment_category_id' => $this->kategori->id,
                    'nama_alat' => $nama, 'range_min' => null, 'range_max' => $maks,
                    'satuan' => 'mL', 'ketidakpastian_terbaik' => $cmc, 'satuan_ketidakpastian' => 'mL',
                    'faktor_cakupan' => 2, 'metode' => 'SIDIK-IK-CAL-0510_Rev.7',
                ]);
            }
        }

        $buat = fn (string $kunci, array $atribut): Standard => $this->standar[$kunci] = Standard::factory()->create([
            'organization_id' => $this->lab->id, 'berlaku_sampai' => '2027-01-19', ...$atribut,
        ]);
        $buat('mettler', ['nama' => 'Analytical Balance', 'merk' => 'Mettler Toledo', 'model' => 'XS204',
            'serial_number' => '1129063525', 'tertelusur_ke' => 'LK-305-IDN']);
        $buat('fujitsu', ['nama' => 'Electronic Balance Fujitsu', 'merk' => 'Fujitsu', 'model' => 'FSR-A',
            'serial_number' => 'SIDIK/134/2024', 'tertelusur_ke' => 'LK-305-IDN']);
        $buat('termometer', ['nama' => 'Termometer & Sensor Std.', 'merk' => 'Yokogawa', 'model' => 'CA 150 Handy Cal',
            'serial_number' => '23P1005', 'tertelusur_ke' => 'LK-285-IDN', 'berlaku_sampai' => '2027-08-12']);
        $buat('lutron', ['nama' => 'Thermobarometer Lutron', 'serial_number' => 'AM02225',
            'tertelusur_ke' => 'LK-172-IDN', 'berlaku_sampai' => '2027-06-26',
            'parameter_kondisi' => [
                'suhu' => ['indexed_value' => 20.1, 'correction' => 0.1, 'u95' => 1.2],
                'kelembaban' => ['indexed_value' => 48.5, 'correction' => -1.5, 'u95' => 3],
                'tekanan' => ['indexed_value' => 1001, 'correction' => 1, 'u95' => 2],
            ]]);

        $this->pt = Customer::factory()->create([
            'organization_id' => $this->lab->id, 'nama' => 'PT CONTOH VOLUMETRIK', 'alamat' => 'Jl. Contoh 1',
        ]);
        $this->ptLain = Customer::factory()->create([
            'organization_id' => $this->lab->id, 'nama' => 'PT SAINGAN SINTETIS', 'alamat' => 'Jl. Contoh 2',
        ]);

        // Akun orang lab (dibuat admin di panel di produksi; di sini lewat factory).
        foreach ([
            'admin' => User::ROLE_ADMIN, 'admin2' => User::ROLE_ADMIN,
            'teknisi' => User::ROLE_TEKNISI, 'teknisi2' => User::ROLE_TEKNISI,
            'viewer' => User::ROLE_VIEWER,
        ] as $nama => $peran) {
            $this->akun[$nama] = User::factory()->create([
                'organization_id' => $this->lab->id, 'role' => $peran, 'status' => User::STATUS_AKTIF,
                'email' => "{$nama}@lab.test", 'employee_id' => 'SDK-'.strtoupper($nama),
            ]);
        }
        $this->akun['studio'] = User::factory()->create([
            'organization_id' => $this->lab->id, 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_AKTIF,
            'email' => 'studio@lab.test', 'employee_id' => 'SDK-STUDIO',
        ]);

        // Super admin SATU-SATUNYA lewat perintah resmi; sandinya dicetak sekali.
        $kode = Artisan::call('akun:super-admin', [
            'email' => 'super@lab.test', '--nama' => 'Super Sintetis', '--id-pegawai' => 'SDK-SUPER',
            '--organisasi' => $this->lab->id, '--paksa' => true,
        ]);
        $keluaran = Artisan::output();
        $this->assertSame(0, $kode, $keluaran);
        $this->assertSame(1, preg_match('/cuma dicetak sekali:\s*\R\s+(\S+)/', $keluaran, $m), $keluaran);
        $this->sandiSuperAdmin = $m[1];
        $this->akun['super'] = User::query()->where('email', 'super@lab.test')->sole();
    }

    protected function tearDown(): void
    {
        $this->bersihkanFolderUji();
        parent::tearDown();
    }

    // ── Perkakas: semua lewat HTTP nyata ─────────────────────────────────────

    private function sandiDari(string $peran): string
    {
        return $peran === 'super' ? $this->sandiSuperAdmin : self::SANDI;
    }

    /** Persis yang dikirim layar login aplikasi lab. */
    private function login(string $peran, array $header = [], ?string $sandi = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->postJson('/api/login', [
            'identifier' => $this->akun[$peran]->email,
            'password' => $sandi ?? $this->sandiDari($peran),
        ], $header);
    }

    private function token(string $peran): string
    {
        return $this->token[$peran] ??= (string) $this->login($peran)
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'role', 'email']]])
            ->json('data.token');
    }

    /** Request terautentikasi dengan token hasil login `$peran`. */
    private function panggil(string $peran, string $metode, string $uri, array $data = [], array $header = []): TestResponse
    {
        return $this->panggilToken($this->token($peran), $metode, $uri, $data, $header);
    }

    private function panggilToken(string $token, string $metode, string $uri, array $data = [], array $header = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withToken($token)->json($metode, $uri, $data, $header);
    }

    private function tamu(string $metode, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->json($metode, $uri, $data);
    }

    // ── Fixture alat & payload (disalin dari VolumetrikRev7AlurTest) ─────────

    private function alat(string $kemampuan, float $kapasitas, ?float $resolusi = null, ?Customer $milik = null): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $this->lab->id,
            'customer_id' => ($milik ?? $this->pt)->id,
            'equipment_category_id' => $this->kategori->id,
            'nama_alat' => $kemampuan, 'nama_alat_kemampuan' => $kemampuan,
            'satuan' => 'ml', 'range_min' => 0, 'range_max' => $kapasitas, 'resolusi' => $resolusi,
            'serial_number' => 'VOL-'.(++$this->urutAlat).'-'.uniqid(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payloadLabu(Equipment $alat, array $timpa = []): array
    {
        return array_replace_recursive([
            'equipment_id' => $alat->id,
            'thermohygro_standard_id' => $this->standar['lutron']->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-10-06',
            'tanggal_terima' => '2026-09-26',
            'lokasi' => 'lab',
            'suhu_awal' => 20.9, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 51, 'kelembaban_akhir' => 50,
            'tekanan_awal' => 1001.2, 'tekanan_akhir' => 1001.5,
            'measurements' => [[
                'titik_ukur' => 200,
                M::PERAN_KOSONG => [0, 0, 0],
                M::PERAN_ISI => [199.026, 199.027, 199.031],
                M::PERAN_SUHU => [25.2, 25.3, 25.2],
            ]],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'kelas' => 'A', 'toleransi_ml' => 0.15, 'kapasitas_ml' => 200,
                'neraca' => 'Electronic Balance Fujitsu',
            ]],
        ], $timpa);
    }

    /** @return array<string, mixed> */
    private function payloadPipet(Equipment $alat): array
    {
        return $this->payloadLabu($alat, [
            'measurements' => [['titik_ukur' => 2, M::PERAN_ISI => [1.9816, 1.9827, 1.9815]]],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'toleransi_ml' => 0.01, 'kapasitas_ml' => 2, 'neraca' => 'Analytical Balance',
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    private function payloadGelas(Equipment $alat): array
    {
        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-10-06',
            'suhu_awal' => 20.4, 'suhu_akhir' => 20.5,
            'kelembaban_awal' => 64, 'kelembaban_akhir' => 62,
            'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
            'measurements' => [
                ['titik_ukur' => 10, M::PERAN_KOSONG => [60.234, 60.24, 60.243],
                    M::PERAN_ISI => [70.7791, 70.7965, 70.8854], M::PERAN_SUHU => [25.4, 25.3, 25.4]],
                ['titik_ukur' => 50, M::PERAN_KOSONG => [60.255, 60.258, 60.263],
                    M::PERAN_ISI => [110.944, 110.9023, 111.0863], M::PERAN_SUHU => [25.4, 25.3, 25.5]],
            ],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'kelas' => 'B', 'toleransi_ml' => 0.5, 'resolusi_ml' => 1, 'kapasitas_ml' => 100,
                'neraca' => 'Electronic Balance Precisa',
            ]],
        ];
    }

    /**
     * Teknisi: pratinjau lalu simpan (kirim). Pratinjau dan simpan harus
     * menjawab angka yang sama -- keduanya diadu di sini.
     *
     * @return array{0: int, 1: array<string, mixed>} id sesi, respons GET sesi
     */
    private function kirimSesi(string $peran, array $payload): array
    {
        $pratinjau = $this->panggil($peran, 'POST', '/api/calibrations/preview', $payload)
            ->assertOk()
            ->assertJsonStructure(['data' => ['keputusan', 'hasil', 'titik', 'lembar_perhitungan', 'kondisi_lingkungan', 'belum_dihitung']]);

        $simpan = $this->panggil($peran, 'POST', '/api/calibrations', $payload)
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'nomor_sesi', 'status', 'titik'], 'meta' => ['belum_dihitung']])
            ->assertJsonPath('data.status', CalibrationSession::STATUS_MENUNGGU_APPROVAL);
        $id = (int) $simpan->json('data.id');

        $baca = $this->panggil($peran, 'GET', "/api/calibrations/{$id}")->assertOk();

        // Satu fungsi hitung untuk pratinjau dan simpan: angka titik harus sama.
        $titikPratinjau = $pratinjau->json('data.titik');
        $titikTersimpan = $baca->json('data.titik');
        $this->assertNotEmpty($titikPratinjau, 'Pratinjau tidak menghasilkan titik: '.json_encode($pratinjau->json('data.belum_dihitung')));
        $this->assertCount(count($titikPratinjau), $titikTersimpan);

        foreach ($titikPratinjau as $i => $t) {
            foreach (['titik_ukur', 'rata_rata', 'koreksi', 'ketidakpastian_diperluas', 'faktor_cakupan_k', 'desimal'] as $kolom) {
                $this->assertEquals(
                    $t[$kolom] ?? null,
                    $titikTersimpan[$i][$kolom] ?? null,
                    "Pratinjau dan simpan beda di titik {$i} kolom {$kolom}.",
                );
            }
        }

        return [$id, $baca->json('data')];
    }

    /** Master Data mengisi Order Number lewat jalur admin (bukan DB). */
    private function isiOrder(string $peran, int $sesiId, string $nomor = 'ORD-DEMO-001'): void
    {
        $this->panggil($peran, 'PATCH', "/api/calibrations/{$sesiId}/admin", ['nomor_order' => $nomor])
            ->assertOk()
            ->assertJsonPath('data.nomor_order', $nomor);
    }

    private function setujui(string $peran, int $sesiId, ?string $nomor, array $tambahan = []): TestResponse
    {
        return $this->panggil($peran, 'POST', "/api/calibrations/{$sesiId}/approve", [
            ...($nomor === null ? [] : ['nomor_sertifikat' => $nomor]),
            'abaikan_peringatan' => true,
            ...$tambahan,
        ]);
    }

    /** Sesi Labu Ukur milik teknisi -> disetujui admin -> sertifikat DEMO-xxxx. */
    private function terbitkanLabu(string $nomor = 'DEMO-0001', ?Equipment $alat = null, string $teknisi = 'teknisi'): Certificate
    {
        [$id] = $this->kirimSesi($teknisi, $this->payloadLabu($alat ?? $this->alat('Labu Ukur', 200)));
        $this->isiOrder('admin', $id);
        $this->setujui('admin', $id, $nomor)->assertOk()->assertJsonPath('data.status', CalibrationSession::STATUS_DISETUJUI);

        return Certificate::query()->where('calibration_session_id', $id)->firstOrFail();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. Akun & login
    // ═════════════════════════════════════════════════════════════════════════

    public function test_01_login_semua_peran_lab_dan_bentuk_jsonnya(): void
    {
        foreach (['admin' => 'admin', 'teknisi' => 'teknisi', 'super' => 'super_admin', 'viewer' => 'viewer'] as $peran => $role) {
            $respons = $this->login($peran)
                ->assertOk()
                ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'nama', 'email', 'role']]])
                ->assertJsonPath('data.user.role', $role)
                ->assertJsonPath('data.user.email', $this->akun[$peran]->email);

            // Token aplikasi lab: ability `internal` saja.
            $this->assertSame(['internal'], PersonalAccessToken::findToken((string) $respons->json('data.token'))->abilities);

            $this->panggil($peran, 'GET', '/api/me')->assertOk()->assertJsonPath('data.role', $role);
            $this->panggil($peran, 'GET', '/api/me/permissions')->assertOk()->assertJsonStructure(['data']);
        }

        // Login lewat ID pegawai, seperti kolom "ID pegawai / email" di aplikasi.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->postJson('/api/login', ['identifier' => 'SDK-TEKNISI', 'password' => self::SANDI])
            ->assertOk()->assertJsonPath('data.user.email', 'teknisi@lab.test');
    }

    public function test_01b_login_gagal_bentuknya_seragam(): void
    {
        $this->login('admin', sandi: 'salah')->assertUnauthorized()->assertJsonStructure(['message']);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->postJson('/api/login', ['identifier' => 'tidak-ada@lab.test', 'password' => 'x'])
            ->assertUnauthorized()->assertJsonStructure(['message']);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->postJson('/api/login', [])->assertUnprocessable()->assertJsonValidationErrors(['identifier', 'password']);

        // Tanpa token: 401 JSON, bukan redirect ke halaman login.
        $this->tamu('GET', '/api/calibrations')->assertUnauthorized();
    }

    public function test_01e_keluar_mencabut_token_dan_akun_dinonaktifkan_langsung_mati(): void
    {
        $token = $this->token('teknisi');
        $this->panggilToken($token, 'GET', '/api/me')->assertOk();
        $this->panggilToken($token, 'POST', '/api/logout')->assertOk();
        $this->panggilToken($token, 'GET', '/api/me')->assertUnauthorized();

        // Akun dinonaktifkan admin SESUDAH login: token yang sudah beredar tidak boleh jalan terus.
        $tokenViewer = $this->token('viewer');
        $this->akun['viewer']->update(['status' => User::STATUS_NONAKTIF]);
        $this->panggilToken($tokenViewer, 'GET', '/api/calibrations')->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_01c_akun_nonaktif_dan_pending_ditolak_di_pintu_login(): void
    {
        $this->akun['teknisi2']->update(['status' => User::STATUS_NONAKTIF]);
        $this->login('teknisi2')->assertForbidden()->assertJsonStructure(['message']);

        $this->akun['teknisi2']->update(['status' => User::STATUS_PENDING]);
        $this->login('teknisi2')->assertForbidden()->assertJsonStructure(['message']);
    }

    public function test_01d_pelanggan_tidak_bisa_masuk_lewat_aplikasi_lab(): void
    {
        $pic = $this->pelangganLewatUndangan($this->pt, 'pic@pt-contoh.test', 'pic_utama');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->postJson('/api/login', ['identifier' => 'pic@pt-contoh.test', 'password' => $this->sandiBenar])
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_akun_internal');

        // Dan token pelanggan ditolak di rute lab.
        $this->panggilToken($pic, 'GET', '/api/calibrations')->assertForbidden();
        $this->panggilToken($pic, 'GET', '/api/me')->assertForbidden();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. Teknisi: pratinjau -> simpan -> kirim
    // ═════════════════════════════════════════════════════════════════════════

    public function test_02_teknisi_labu_ukur_pratinjau_lalu_simpan_dan_kirim(): void
    {
        $alat = $this->alat('Labu Ukur', 200);
        [$id, $sesi] = $this->kirimSesi('teknisi', $this->payloadLabu($alat));

        $this->assertSame($this->akun['teknisi']->id, $sesi['teknisi']['id'] ?? $sesi['teknisi_id'] ?? null);
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi['status']);
        $this->assertNotEmpty($sesi['nomor_sesi']);
        $this->assertCount(1, $sesi['titik']);
        $this->assertNotNull($sesi['titik'][0]['ketidakpastian_diperluas']);
        $this->assertSame('labu ukur', mb_strtolower((string) ($sesi['equipment']['nama_alat'] ?? '')));

        // Draft dulu, baru kirim: POST status=draft lalu PUT tanpa status.
        $draft = $this->panggil('teknisi', 'POST', '/api/calibrations', [...$this->payloadLabu($this->alat('Labu Ukur', 200)), 'status' => 'draft'])
            ->assertCreated()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_DRAFT);
        $draftId = (int) $draft->json('data.id');
        $this->panggil('teknisi', 'PUT', "/api/calibrations/{$draftId}", $this->payloadLabu(Equipment::findOrFail($draft->json('data.equipment.id'))))
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_MENUNGGU_APPROVAL);
    }

    public function test_02b_kirim_ulang_dengan_client_request_id_sama_tidak_membuat_sesi_ganda(): void
    {
        $payload = [...$this->payloadLabu($this->alat('Labu Ukur', 200)), 'client_request_id' => '7c1b4f5e-0a3d-4c0e-9b58-2f5b8e6b1a11'];

        $pertama = $this->panggil('teknisi', 'POST', '/api/calibrations', $payload)->assertCreated();
        $kedua = $this->panggil('teknisi', 'POST', '/api/calibrations', $payload);

        $this->assertContains($kedua->getStatusCode(), [200, 201], 'Kirim ulang karena sinyal putus harus tetap sukses.');
        $this->assertSame($pertama->json('data.id'), $kedua->json('data.id'));
        $this->assertSame(1, CalibrationSession::query()->where('client_request_id', $payload['client_request_id'])->count());
    }

    public function test_02c_teknisi_tidak_bisa_menyetel_order_number_dan_hanya_melihat_sesinya_sendiri(): void
    {
        [$milikTeknisi] = $this->kirimSesi('teknisi', [...$this->payloadLabu($this->alat('Labu Ukur', 200)), 'nomor_order' => 'ORD-CURANG']);
        [$milikTeknisi2] = $this->kirimSesi('teknisi2', $this->payloadLabu($this->alat('Labu Ukur', 200)));

        // Kiriman teknisi untuk Order Number dibuang server.
        $this->assertNull(CalibrationSession::findOrFail($milikTeknisi)->nomor_order);

        $daftar = collect($this->panggil('teknisi', 'GET', '/api/calibrations')->assertOk()->assertJsonStructure(['data'])->json('data'))->pluck('id')->all();
        $this->assertContains($milikTeknisi, $daftar);
        $this->assertNotContains($milikTeknisi2, $daftar, 'Teknisi melihat sesi teknisi lain.');

        // Sesi teknisi lain: tidak boleh dibuka/diubah.
        $this->assertContains($this->panggil('teknisi', 'GET', "/api/calibrations/{$milikTeknisi2}")->getStatusCode(), [403, 404]);
        $this->assertContains($this->panggil('teknisi', 'PUT', "/api/calibrations/{$milikTeknisi2}", $this->payloadLabu($this->alat('Labu Ukur', 200)))->getStatusCode(), [403, 404]);

        // Order Number lewat jalur admin: teknisi 403.
        $this->panggil('teknisi', 'PATCH', "/api/calibrations/{$milikTeknisi}/admin", ['nomor_order' => 'ORD-CURANG'])->assertForbidden();
    }

    public function test_02d_pipet_volume_dan_gelas_ukur_juga_lewat_pratinjau_dan_simpan(): void
    {
        [, $pipet] = $this->kirimSesi('teknisi', $this->payloadPipet($this->alat('Pipet Volume', 2)));
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $pipet['status']);
        $this->assertCount(1, $pipet['titik']);
        $this->assertSame(4, $pipet['titik'][0]['desimal']);

        [, $gelas] = $this->kirimSesi('teknisi', $this->payloadGelas($this->alat('Gelas Ukur', 100, 1)));
        $this->assertCount(2, $gelas['titik']);
    }

    /**
     * TITS (Temperature Indicator tanpa Sensor) lewat HTTP: hanya fixture
     * seeder TITS yang ada, jadi alat, kalibrator Yokogawa, dan angka titik
     * disusun ulang dari `TitsSeeder` (INPUT DATA master fungsi Measure).
     *
     * @return array<string, mixed> payload TITS (alat, kalibrator Yokogawa, dan CMC resmi dibuat di sini)
     */
    private function payloadTits(): array
    {
        // Baris CMC tipe sensor (Type N) berasal dari lampiran akreditasi; seeder
        // resminya menulis ke organisasi #1 = lab ini, lengkap dengan kategorinya.
        $this->seed(CalibrationCapabilitySeeder::class);
        $suhu = EquipmentCategory::query()->where('organization_id', $this->lab->id)->where('kode', 'suhu-dan-kelembapan')->firstOrFail();
        $yokogawa = Standard::factory()->create([
            'organization_id' => $this->lab->id, 'nama' => 'Temperature Calibrator Yokogawa CA 150 Handy Cal',
            'merk' => 'Yokogawa', 'model' => 'CA 150 Handy Cal', 'serial_number' => '23P1005', 'no_sertifikat' => '23P1005',
            'tertelusur_ke' => 'LK-241-IDN', 'berlaku_sampai' => '2027-08-12', 'ketidakpastian' => null,
            'satuan_ketidakpastian' => '°C', 'faktor_cakupan' => 2,
        ]);
        $alat = Equipment::factory()->create([
            'organization_id' => $this->lab->id, 'customer_id' => $this->pt->id, 'equipment_category_id' => $suhu->id,
            'nama_alat' => 'Temperature Calibrator', 'nama_alat_kemampuan' => 'Temperature Indicator tanpa Sensor',
            'merk' => 'Graphtech', 'model' => 'GL840', 'serial_number' => 'C305B1470', 'range_min' => 0, 'range_max' => 1000,
            'satuan' => '°C', 'resolusi' => 0.1, 'toleransi' => null,
        ]);

        $titik = [
            [-20, [-20.05, -20.11, -20.09, -20.49, -20.60, -20.42]],
            [50, [50.16, 50.00, 50.02, 49.55, 49.56, 49.52]],
            [100, [100.30, 100.15, 100.20, 99.90, 99.75, 99.65]],
        ];
        $payload = [
            'equipment_id' => $alat->id,
            'standard_id' => $yokogawa->id,
            'thermohygro_standard_id' => $this->standar['lutron']->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-10-06',
            'lokasi' => 'lab',
            'mode_kalibrasi' => 'measure',
            'tipe_sensor' => 'Type N',
            'suhu_awal' => 24.1, 'suhu_akhir' => 24.3,
            'kelembaban_awal' => 61, 'kelembaban_akhir' => 62,
            'measurements' => array_map(fn (array $t): array => [
                'titik_ukur' => $t[0], 'pembacaan' => $t[1], 'standard_id' => $yokogawa->id,
            ], $titik),
        ];

        return $payload;
    }

    public function test_02f_tits_pratinjau_simpan_dan_terbit(): void
    {
        [$id, $sesi] = $this->kirimSesi('teknisi', $this->payloadTits());
        $this->assertCount(3, $sesi['titik']);
        $this->assertSame('tits', $sesi['equipment']['profil'] ?? null);
        $this->assertNull($sesi['keputusan'] ?? null, 'TITS tidak divonis PASS/FAIL.');

        $this->isiOrder('admin', $id, 'ORD-TITS');
        $this->setujui('admin', $id, 'DEMO-0201')->assertOk();
        $sertifikat = Certificate::query()->where('calibration_session_id', $id)->sole();
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
        Storage::disk('arsip')->assertExists($sertifikat->pdf_path);
    }

    public function test_02e_validasi_kiriman_yang_salah_dijawab_422_berbentuk_laravel(): void
    {
        $this->panggil('teknisi', 'POST', '/api/calibrations', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors']);

        $this->panggil('teknisi', 'POST', '/api/calibrations/preview', ['equipment_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors']);

        // Alat milik lab lain tidak boleh dipakai.
        $asing = Equipment::factory()->create(['organization_id' => $this->labLain->id]);
        $this->panggil('teknisi', 'POST', '/api/calibrations', $this->payloadLabu($asing))->assertUnprocessable();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. Pemisahan wewenang & viewer
    // ═════════════════════════════════════════════════════════════════════════

    public function test_03_teknisi_menyetujui_sesinya_ditolak_di_gerbang_peran(): void
    {
        [$id] = $this->kirimSesi('teknisi', $this->payloadLabu($this->alat('Labu Ukur', 200)));
        $this->isiOrder('admin', $id);

        // CATATAN SKENARIO: brief pelatihan menyebut 422 pemisahan_wewenang untuk
        // teknisi, tetapi `approve` ada di grup `role:admin`, jadi teknisi dihentikan
        // lebih dulu oleh gerbang peran (403). 422 pemisahan_wewenang terjadi pada
        // admin/super admin yang mengisi lembarnya sendiri -- diuji di bawah.
        $this->setujui('teknisi', $id, 'DEMO-0001')->assertForbidden();
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, CalibrationSession::findOrFail($id)->status);
    }

    public function test_03b_pengisi_lembar_admin_atau_super_admin_tidak_bisa_menyetujui_sesinya_sendiri(): void
    {
        foreach (['admin2', 'super'] as $pengisi) {
            [$id] = $this->kirimSesi($pengisi, $this->payloadLabu($this->alat('Labu Ukur', 200)));
            $this->isiOrder($pengisi, $id);

            $this->setujui($pengisi, $id, 'DEMO-'.strtoupper($pengisi))
                ->assertUnprocessable()
                ->assertJsonPath('kode', 'pemisahan_wewenang')
                ->assertJsonStructure(['message', 'wewenang']);
            $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, CalibrationSession::findOrFail($id)->status);

            // Orang lain boleh.
            $pemeriksa = $pengisi === 'admin2' ? 'admin' : 'admin2';
            $this->setujui($pemeriksa, $id, 'DEMO-'.strtoupper($pengisi))->assertOk();
        }
    }

    public function test_03c_viewer_hanya_membaca(): void
    {
        [$id] = $this->kirimSesi('teknisi', $this->payloadLabu($alat = $this->alat('Labu Ukur', 200)));

        $this->panggil('viewer', 'GET', '/api/calibrations')->assertOk();
        $this->panggil('viewer', 'GET', "/api/calibrations/{$id}")->assertOk();
        $this->panggil('viewer', 'GET', '/api/equipments')->assertOk();

        $this->panggil('viewer', 'POST', '/api/calibrations', $this->payloadLabu($alat))->assertForbidden();
        $this->panggil('viewer', 'POST', '/api/calibrations/preview', $this->payloadLabu($alat))->assertForbidden();
        $this->panggil('viewer', 'PUT', "/api/calibrations/{$id}", $this->payloadLabu($alat))->assertForbidden();
        $this->panggil('viewer', 'PATCH', "/api/calibrations/{$id}/admin", ['nomor_order' => 'X'])->assertForbidden();
        $this->setujui('viewer', $id, 'DEMO-9999')->assertForbidden();
        $this->panggil('viewer', 'POST', '/api/equipments', ['nama_alat' => 'X'])->assertForbidden();
        $this->panggil('viewer', 'DELETE', "/api/equipments/{$alat->id}")->assertForbidden();
        $this->panggil('viewer', 'GET', '/api/users')->assertForbidden();

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, CalibrationSession::findOrFail($id)->status);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. Admin: Order Number, No. Sertifikat, penerbitan
    // ═════════════════════════════════════════════════════════════════════════

    public function test_04_admin_menyetujui_sertifikat_terbit_dengan_snapshot_dan_pdf(): void
    {
        [$id] = $this->kirimSesi('teknisi', $this->payloadLabu($this->alat('Labu Ukur', 200)));

        // Order Number wajib dulu.
        $this->setujui('admin', $id, 'DEMO-0001')
            ->assertUnprocessable()
            ->assertJsonPath('kode', CalibrationSession::KODE_NOMOR_ORDER_WAJIB)
            ->assertJsonStructure(['message', 'errors' => ['nomor_order']]);

        $this->isiOrder('admin', $id);

        // No. Sertifikat wajib, dan formatnya dijaga.
        $this->setujui('admin', $id, null)->assertUnprocessable()->assertJsonPath('kode', 'nomor_sertifikat_wajib')
            ->assertJsonStructure(['errors' => ['nomor_sertifikat']]);
        $this->setujui('admin', $id, 'DEMO-0001-R1')->assertUnprocessable()->assertJsonPath('kode', 'nomor_sertifikat_tidak_valid');
        $this->setujui('admin', $id, 'DEMO 0001 dengan spasi')->assertUnprocessable()->assertJsonPath('kode', 'nomor_sertifikat_tidak_valid');
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, CalibrationSession::findOrFail($id)->status, 'Penolakan nomor tidak boleh menghabiskan persetujuan.');

        // Valid.
        $respons = $this->setujui('admin', $id, 'DEMO-0001')
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_DISETUJUI)
            ->assertJsonStructure(['data' => ['id', 'status'], 'validasi' => ['valid', 'boleh_terbit']]);

        $sertifikat = Certificate::query()->where('calibration_session_id', $id)->sole();
        $this->assertSame('DEMO-0001', $sertifikat->nomor);
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status, 'Job GenerateCertificate (queue sync) tidak menerbitkan.');
        $this->assertNotNull($respons->json('data.certificate_id') ?? $sertifikat->id);
        $this->assertSame($this->akun['admin']->id, (int) $sertifikat->issued_by);

        // Snapshot beku.
        $snapshot = $sertifikat->snapshot;
        $this->assertIsArray($snapshot);
        $this->assertSame('DEMO-0001', $snapshot['header']['certificate_number']);
        $this->assertNotEmpty($snapshot['hasil']);
        $this->assertNotEmpty($snapshot['standar_digunakan']);

        // Mengubah master SESUDAH terbit tidak menggeser snapshot.
        $sertifikat->session->equipment->update(['nama_alat' => 'DIGANTI SESUDAH TERBIT']);
        $this->assertSame($snapshot, $sertifikat->fresh()->snapshot);

        // PDF terbentuk di disk arsip (fake) dan tidak kosong.
        $this->assertNotEmpty($sertifikat->pdf_path);
        Storage::disk('arsip')->assertExists($sertifikat->pdf_path);
        $isi = Storage::disk('arsip')->get($sertifikat->pdf_path);
        $this->assertStringStartsWith('%PDF', $isi);
        $this->assertGreaterThan(5000, strlen($isi), 'PDF terlalu kecil -- kemungkinan kosong.');

        // Dan bisa diunduh admin lewat API dengan isi yang sama.
        $unduh = $this->panggil('admin', 'GET', "/api/certificates/{$sertifikat->id}/download")->assertOk();
        $this->assertSame(strlen($isi), strlen($unduh->streamedContent()));
        $this->assertStringContainsString('application/pdf', (string) $unduh->headers->get('Content-Type'));

        // Detail & daftar sertifikat.
        $this->panggil('admin', 'GET', "/api/certificates/{$sertifikat->id}")->assertOk()
            ->assertJsonPath('data.nomor', 'DEMO-0001')
            ->assertJsonPath('data.status', Certificate::STATUS_TERBIT);
        $this->panggil('viewer', 'GET', '/api/certificates')->assertOk()->assertJsonFragment(['nomor' => 'DEMO-0001']);

        // Setujui dua kali: ditolak, sertifikat tidak ganda.
        $this->setujui('admin2', $id, 'DEMO-0002')->assertUnprocessable();
        $this->assertSame(1, Certificate::query()->where('calibration_session_id', $id)->count());
    }

    public function test_04b_nomor_sertifikat_duplikat_ditolak_walau_beda_sesi(): void
    {
        $this->terbitkanLabu('DEMO-0001');

        [$id2] = $this->kirimSesi('teknisi', $this->payloadLabu($this->alat('Labu Ukur', 200)));
        $this->isiOrder('admin', $id2, 'ORD-DEMO-002');

        $this->setujui('admin', $id2, 'DEMO-0001')
            ->assertUnprocessable()
            ->assertJsonPath('kode', 'nomor_sertifikat_dipakai')
            ->assertJsonStructure(['message', 'errors' => ['nomor_sertifikat'], 'sertifikat']);

        $this->setujui('admin', $id2, 'DEMO-0002')->assertOk();
    }

    public function test_04c_pipet_volume_dan_gelas_ukur_juga_bisa_terbit(): void
    {
        [$idPipet] = $this->kirimSesi('teknisi', $this->payloadPipet($this->alat('Pipet Volume', 2)));
        $this->isiOrder('admin', $idPipet, 'ORD-PIPET');
        $this->setujui('admin', $idPipet, 'DEMO-0101')->assertOk();
        $pipet = Certificate::query()->where('calibration_session_id', $idPipet)->sole();
        $this->assertSame(Certificate::STATUS_TERBIT, $pipet->status);
        Storage::disk('arsip')->assertExists($pipet->pdf_path);

        [$idGelas] = $this->kirimSesi('teknisi', $this->payloadGelas($this->alat('Gelas Ukur', 100, 1)));
        $this->isiOrder('admin', $idGelas, 'ORD-GELAS');
        $hasil = $this->setujui('admin', $idGelas, 'DEMO-0102');
        // Temuan peninjau akan melihat apa jawabannya: 200 atau 422 dengan alasan terbaca.
        $this->assertContains($hasil->getStatusCode(), [200], 'Gelas Ukur tidak bisa terbit: '.substr($hasil->getContent(), 0, 600));
        $gelas = Certificate::query()->where('calibration_session_id', $idGelas)->sole();
        $this->assertSame(Certificate::STATUS_TERBIT, $gelas->status);
        Storage::disk('arsip')->assertExists($gelas->pdf_path);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5. Verifikasi publik
    // ═════════════════════════════════════════════════════════════════════════

    public function test_05_verifikasi_publik_tanpa_login(): void
    {
        $sertifikat = $this->terbitkanLabu('DEMO-0001');

        $this->tamu('GET', "/api/verify/{$sertifikat->qr_token}")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'nomor', 'status', 'status_dokumen', 'digantikan_oleh', 'keputusan', 'diterbitkan_pada',
                'berlaku_sampai', 'kadaluarsa', 'alat' => ['nama_alat', 'serial_number'],
                'tanggal_kalibrasi', 'diterbitkan_oleh' => ['nama', 'no_akreditasi'],
            ]])
            ->assertJsonPath('data.nomor', 'DEMO-0001')
            ->assertJsonPath('data.status', Certificate::STATUS_TERBIT)
            ->assertJsonPath('data.status_dokumen', 'berlaku')
            // Nama pelanggan = data rahasia klien (ISO/IEC 17025 klausul 4.2):
            // tidak boleh ada di respons publik, di mana pun.
            ->assertJsonMissingPath('data.alat.pemilik');

        // Halaman web hasil scan QR.
        $this->app['auth']->forgetGuards();
        $halaman = $this->flushHeaders()->get("/verify/{$sertifikat->qr_token}")->assertOk()->assertSee('DEMO-0001');
        $html = $halaman->getContent();
        $ringkas = substr($html, 0, (int) strpos($html, 'class="lembar-lengkap"'));
        $this->assertNotSame('', $ringkas, 'Blok lembar lengkap tidak ditemukan.');
        $this->assertStringNotContainsString('PT CONTOH VOLUMETRIK', $ringkas, 'Kartu ringkas verifikasi publik memuat nama pelanggan.');
        $this->assertStringNotContainsString('Pemilik alat', $ringkas);

        $this->tamu('GET', '/api/verify/tokentakadayangsah')->assertNotFound()->assertJsonStructure(['message']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 6. Pelanggan: undangan, data perusahaan sendiri, isolasi
    // ═════════════════════════════════════════════════════════════════════════

    /** Alur nyata: admin lab mengundang -> kode di email -> pelanggan menukarnya. Pulangkan token. */
    private function pelangganLewatUndangan(Customer $pt, string $email, string $peran, ?string $lewat = null): string
    {
        Mail::fake();

        $kodeRespons = null;

        if ($lewat === null) {
            // Jalur admin lab: KODE dibaca dari balasan POST (data.kode), seperti
            // yang akan dilakukan aplikasi lab saat email tidak sampai.
            $buat = $this->panggil('admin', 'POST', "/api/customers/{$pt->id}/undangan", ['email' => $email, 'peran' => $peran])
                ->assertCreated()
                ->assertJsonStructure(['message', 'data' => ['id', 'email', 'peran', 'kedaluwarsa_pada', 'berlaku_sampai', 'kode', 'email_terkirim']])
                ->assertJsonPath('data.email', $email)
                ->assertJsonPath('data.peran', $peran);
            $kodeRespons = (string) $buat->json('data.kode');
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $kodeRespons);
            $this->assertSame($buat->json('data.kedaluwarsa_pada'), $buat->json('data.berlaku_sampai'));
            // Mailer uji = array: server jujur bahwa email tidak terbukti sampai.
            $this->assertFalse($buat->json('data.email_terkirim'));
        } else {
            // Jalur PIC: kode HANYA lewat email, tidak ada di balasan.
            $this->panggilToken($lewat, 'POST', '/api/pelanggan/v1/anggota/undangan', ['email' => $email, 'peran' => $peran])
                ->assertCreated()
                ->assertJsonMissingPath('data.kode');
        }

        $kodeEmail = Mail::sent(UndanganEmail::class, fn (UndanganEmail $m) => $m->hasTo($email))->first()?->kode;
        $this->assertNotNull($kodeEmail, "Email undangan ke {$email} tidak terkirim.");
        if ($kodeRespons !== null) {
            $this->assertSame($kodeEmail, $kodeRespons, 'Kode di balasan beda dari kode di email.');
        }
        $kode = $kodeRespons ?? $kodeEmail;

        $this->app['auth']->forgetGuards();
        $terima = $this->flushHeaders()->postJson('/api/pelanggan/v1/auth/terima-undangan', [
            'email' => $email, 'kode' => $kode, 'nama' => 'Pelanggan '.$peran, 'sandi' => $this->sandiBenar,
            'telepon' => '0812-3456-7890', 'jabatan' => 'QA', 'setuju_syarat' => true, 'nama_perangkat' => 'Pixel Uji',
        ])->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'kedaluwarsa_pada', 'kemampuan', 'user' => ['id', 'nama', 'email', 'status', 'keanggotaan']]])
            ->assertJsonPath('data.kemampuan', 'pelanggan')
            ->assertJsonPath('data.user.keanggotaan.0.peran', $peran)
            ->assertJsonPath('data.user.keanggotaan.0.customer_id', $pt->id);

        // Kode sekali pakai.
        $this->app['auth']->forgetGuards();
        $ulang = $this->flushHeaders()->postJson('/api/pelanggan/v1/auth/terima-undangan', [
            'email' => $email, 'kode' => $kode, 'nama' => 'Budi Lagi', 'sandi' => $this->sandiBenar,
            'telepon' => '0812-3456-7890', 'jabatan' => 'QA', 'setuju_syarat' => true,
        ])->assertUnprocessable();
        $this->assertSame('undangan_tidak_berlaku', $ulang->json('kode'), $ulang->getContent());

        // Login ulang lewat pintu masuk pelanggan memberi token yang juga sah.
        $this->app['auth']->forgetGuards();
        $masuk = $this->flushHeaders()->postJson('/api/pelanggan/v1/auth/masuk', [
            'email' => $email, 'sandi' => $this->sandiBenar, 'nama_perangkat' => 'Pixel Uji',
        ])->assertOk()->assertJsonPath('data.kemampuan', 'pelanggan');

        $this->assertNotSame($terima->json('data.token'), $masuk->json('data.token'));

        return (string) $masuk->json('data.token');
    }

    public function test_06_pelanggan_melihat_alat_dan_sertifikat_perusahaannya_dan_mengunduh_pdf(): void
    {
        $alat = $this->alat('Labu Ukur', 200);
        $sertifikat = $this->terbitkanLabu('DEMO-0001', $alat);
        $alatLain = $this->alat('Labu Ukur', 200, null, $this->ptLain);
        $sertifikatLain = $this->terbitkanLabu('DEMO-0002', $alatLain);

        $pic = $this->pelangganLewatUndangan($this->pt, 'pic@pt-contoh.test', 'pic_utama');

        $this->panggilToken($pic, 'GET', '/api/pelanggan/v1/saya')->assertOk()->assertJsonStructure(['data' => ['nama', 'email']]);
        $this->panggilToken($pic, 'GET', '/api/pelanggan/v1/beranda')->assertOk()->assertJsonStructure(['data']);

        $daftarAlat = $this->panggilToken($pic, 'GET', '/api/pelanggan/v1/alat')->assertOk()->assertJsonStructure(['data']);
        $idAlat = collect($daftarAlat->json('data'))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains($alat->id, $idAlat);
        $this->assertNotContains($alatLain->id, $idAlat, 'Alat perusahaan lain bocor ke daftar pelanggan.');
        $this->panggilToken($pic, 'GET', "/api/pelanggan/v1/alat/{$alat->id}")->assertOk();

        $daftar = $this->panggilToken($pic, 'GET', '/api/pelanggan/v1/sertifikat')
            ->assertOk()->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page', 'last_page']]);
        $nomor = collect($daftar->json('data'))->pluck('nomor')->all();
        $this->assertSame(['DEMO-0001'], $nomor, 'Daftar sertifikat pelanggan bocor atau kosong.');

        $this->panggilToken($pic, 'GET', "/api/pelanggan/v1/sertifikat/{$sertifikat->id}")->assertOk()->assertJsonPath('data.nomor', 'DEMO-0001');

        $this->app['auth']->forgetGuards();
        $unduh = $this->flushHeaders()->withToken($pic)->get("/api/pelanggan/v1/sertifikat/{$sertifikat->id}/unduh")->assertOk();
        $pdf = $unduh->streamedContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(strlen(Storage::disk('arsip')->get($sertifikat->pdf_path)), strlen($pdf));

        // Isolasi perusahaan: 404, BUKAN 403.
        $this->panggilToken($pic, 'GET', "/api/pelanggan/v1/sertifikat/{$sertifikatLain->id}")->assertNotFound();
        $this->panggilToken($pic, 'GET', "/api/pelanggan/v1/sertifikat/{$sertifikatLain->id}/unduh")->assertNotFound();
        $this->panggilToken($pic, 'GET', "/api/pelanggan/v1/alat/{$alatLain->id}")->assertNotFound();
        $this->panggilToken($pic, 'GET', '/api/pelanggan/v1/sertifikat/99999999')->assertNotFound();
    }

    public function test_06b_pelanggan_perusahaan_lain_dan_lab_lain_dijawab_404(): void
    {
        $sertifikat = $this->terbitkanLabu('DEMO-0001');
        $alat = $sertifikat->session->equipment;

        $picLain = $this->pelangganLewatUndangan($this->ptLain, 'pic@pt-saingan.test', 'pic_utama');

        foreach ([
            "/api/pelanggan/v1/sertifikat/{$sertifikat->id}",
            "/api/pelanggan/v1/sertifikat/{$sertifikat->id}/unduh",
            "/api/pelanggan/v1/alat/{$alat->id}",
        ] as $uri) {
            $this->panggilToken($picLain, 'GET', $uri)->assertNotFound();
        }

        $daftar = $this->panggilToken($picLain, 'GET', '/api/pelanggan/v1/sertifikat')->assertOk();
        $this->assertSame([], $daftar->json('data'));

        // Sertifikat lab lain sama sekali (organisasi berbeda) -> 404 juga.
        $alatAsing = Equipment::factory()->create([
            'organization_id' => $this->labLain->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->labLain->id])->id,
        ]);
        $sesiAsing = CalibrationSession::factory()->create([
            'organization_id' => $this->labLain->id, 'equipment_id' => $alatAsing->id,
            'status' => CalibrationSession::STATUS_DISETUJUI,
        ]);
        $sertifikatAsing = Certificate::factory()->create([
            'organization_id' => $this->labLain->id, 'calibration_session_id' => $sesiAsing->id,
        ]);
        $this->panggilToken($picLain, 'GET', "/api/pelanggan/v1/sertifikat/{$sertifikatAsing->id}")->assertNotFound();

        // customer_id tidak pernah dipercaya dari request.
        $this->panggilToken($picLain, 'GET', "/api/pelanggan/v1/sertifikat?customer_id={$this->pt->id}")->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_06c_staf_membaca_tetapi_tidak_mengelola_anggota_pic_bisa(): void
    {
        $sertifikat = $this->terbitkanLabu('DEMO-0001');
        $pic = $this->pelangganLewatUndangan($this->pt, 'pic@pt-contoh.test', 'pic_utama');

        // Staf diundang PIC (bukan admin lab) -- alur anggota.
        $staf = $this->pelangganLewatUndangan($this->pt, 'staf@pt-contoh.test', 'staf', lewat: $pic);

        $this->panggilToken($staf, 'GET', '/api/pelanggan/v1/sertifikat')->assertOk()->assertJsonFragment(['nomor' => 'DEMO-0001']);
        $this->panggilToken($staf, 'GET', "/api/pelanggan/v1/sertifikat/{$sertifikat->id}")->assertOk();
        $this->panggilToken($staf, 'GET', '/api/pelanggan/v1/anggota')->assertOk()->assertJsonStructure(['data']);

        $this->panggilToken($staf, 'POST', '/api/pelanggan/v1/anggota/undangan', ['email' => 'lain@pt-contoh.test', 'peran' => 'staf'])
            ->assertForbidden();
        $this->panggilToken($pic, 'POST', '/api/pelanggan/v1/anggota/undangan', ['email' => 'lain@pt-contoh.test', 'peran' => 'staf'])
            ->assertCreated();

        // Pelanggan tidak punya rute tulis data alat.
        $this->assertContains(
            $this->panggilToken($pic, 'PATCH', "/api/pelanggan/v1/alat/{$sertifikat->session->equipment_id}", ['nama_alat' => 'X'])->getStatusCode(),
            [404, 405],
        );

        // Token pelanggan ditolak di rute lab, dan token lab di rute pelanggan.
        $this->panggilToken($staf, 'GET', '/api/certificates')->assertForbidden();
        $this->panggil('admin', 'GET', '/api/pelanggan/v1/sertifikat')->assertForbidden();
        $this->panggil('super', 'GET', '/api/pelanggan/v1/sertifikat')->assertForbidden();
        $this->tamu('GET', '/api/pelanggan/v1/sertifikat')->assertUnauthorized();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 7. Super admin
    // ═════════════════════════════════════════════════════════════════════════

    public function test_07_super_admin_melakukan_aksi_teknisi_dan_admin_di_organisasinya(): void
    {
        // Aksi teknisi: buat sesi.
        [$milikSuper] = $this->kirimSesi('super', $this->payloadLabu($this->alat('Labu Ukur', 200)));
        // Aksi admin atas sesi teknisi: isi order, setujui, terbitkan.
        [$milikTeknisi] = $this->kirimSesi('teknisi', $this->payloadLabu($this->alat('Labu Ukur', 200)));
        $this->isiOrder('super', $milikTeknisi);
        $this->setujui('super', $milikTeknisi, 'DEMO-0001')->assertOk();

        $sertifikat = Certificate::query()->where('calibration_session_id', $milikTeknisi)->sole();
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
        // Dicatat sebagai super_admin, bukan menyamar sebagai orang lain.
        $this->assertSame($this->akun['super']->id, (int) $sertifikat->issued_by);

        // Sesi yang dia isi sendiri tetap tidak bisa dia setujui.
        $this->isiOrder('super', $milikSuper);
        $this->setujui('super', $milikSuper, 'DEMO-0002')->assertUnprocessable()->assertJsonPath('kode', 'pemisahan_wewenang');

        // Lintas organisasi lewat API: masih terkurung (404) -- keputusan di AGENTS.md.
        $sesiAsing = CalibrationSession::factory()->create(['organization_id' => $this->labLain->id]);
        $this->panggil('super', 'GET', "/api/calibrations/{$sesiAsing->id}")->assertNotFound();
        $this->setujui('super', $sesiAsing->id, 'DEMO-0003')->assertNotFound();

        // Panel boleh dimasuki (baca); teknisi tidak.
        $this->assertTrue($this->akun['super']->canAccessPanel(filament()->getPanel('admin')));
        $this->assertFalse($this->akun['teknisi']->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_07b_tarik_sertifikat_mengembalikan_ke_lembar_kerja_dan_nomor_tetap_terpakai(): void
    {
        $sertifikat = $this->terbitkanLabu('DEMO-0001');
        $sesiId = (int) $sertifikat->calibration_session_id;

        // Alasan + sandi wajib.
        $this->panggil('super', 'POST', "/api/certificates/{$sertifikat->id}/tarik", ['alasan' => 'pendek', 'password' => $this->sandiSuperAdmin])
            ->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->panggil('super', 'POST', "/api/certificates/{$sertifikat->id}/tarik", ['alasan' => 'Angka disk 1 salah salin dari logger.'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->panggil('super', 'POST', "/api/certificates/{$sertifikat->id}/tarik", ['alasan' => 'Angka disk 1 salah salin dari logger.', 'password' => 'salah-total'])
            ->assertUnprocessable()->assertJsonPath('kode', 'konfirmasi_sandi_salah');
        $this->panggil('teknisi', 'POST', "/api/certificates/{$sertifikat->id}/tarik", ['alasan' => 'Angka disk 1 salah salin dari logger.', 'password' => self::SANDI])
            ->assertForbidden();
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->fresh()->status);

        $this->panggil('super', 'POST', "/api/certificates/{$sertifikat->id}/tarik", ['alasan' => 'Angka disk 1 salah salin dari logger.', 'password' => $this->sandiSuperAdmin])
            ->assertOk()
            ->assertJsonPath('data.status', Certificate::STATUS_DITARIK)
            ->assertJsonPath('data.status_dokumen', 'ditarik');

        $tarik = $sertifikat->fresh();
        $this->assertSame($this->akun['super']->id, (int) $tarik->ditarik_oleh);
        $this->assertSame('Angka disk 1 salah salin dari logger.', $tarik->alasan_ditarik);
        $this->assertNotNull($tarik->pdf_path, 'PDF yang ditarik tidak boleh dihapus.');
        $this->assertSame('DEMO-0001', $tarik->nomor);

        // Sesi kembali ke lembar kerja (menunggu_approval).
        $this->panggil('admin', 'GET', "/api/calibrations/{$sesiId}")->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_MENUNGGU_APPROVAL);

        // Nomor tetap terpakai: sesi LAIN tidak boleh memakai DEMO-0001.
        [$lain] = $this->kirimSesi('teknisi', $this->payloadLabu($this->alat('Labu Ukur', 200)));
        $this->isiOrder('admin', $lain, 'ORD-DEMO-LAIN');
        $this->setujui('admin', $lain, 'DEMO-0001')->assertUnprocessable()->assertJsonPath('kode', 'nomor_sertifikat_dipakai');

        // QR lama jujur: ditarik.
        $this->tamu('GET', "/api/verify/{$sertifikat->qr_token}")->assertOk()
            ->assertJsonPath('data.status', Certificate::STATUS_DITARIK)
            ->assertJsonPath('data.status_dokumen', 'ditarik');

        // Pelanggan tidak lagi melihat sertifikat yang ditarik di daftarnya.
        $pic = $this->pelangganLewatUndangan($this->pt, 'pic@pt-contoh.test', 'pic_utama');
        $this->assertSame([], $this->panggilToken($pic, 'GET', '/api/pelanggan/v1/sertifikat')->assertOk()->json('data'));

        // Terbit ulang sebagai -R1 oleh admin lain (bukan pengisi), tanpa mengetik nomor.
        $this->setujui('admin2', $sesiId, null)->assertOk();
        $baru = Certificate::query()->where('revision_of', $sertifikat->id)->sole();
        $this->assertSame('DEMO-0001-R1', $baru->nomor);
        $this->assertSame(Certificate::STATUS_TERBIT, $baru->status);
        Storage::disk('arsip')->assertExists($baru->pdf_path);
        $this->assertSame(Certificate::STATUS_DITARIK, $sertifikat->fresh()->status);

        $this->tamu('GET', "/api/verify/{$sertifikat->qr_token}")->assertOk()
            ->assertJsonPath('data.digantikan_oleh.nomor', 'DEMO-0001-R1');

        // Pelanggan kini melihat -R1.
        $this->assertSame(['DEMO-0001-R1'], collect($this->panggilToken($pic, 'GET', '/api/pelanggan/v1/sertifikat')->json('data'))->pluck('nomor')->all());
    }

    public function test_07c_admin_biasa_juga_bisa_menarik_dan_teknisi_viewer_tidak(): void
    {
        $sertifikat = $this->terbitkanLabu('DEMO-0001');
        $badan = ['alasan' => 'Salah tulis nama pelanggan di kop.', 'password' => self::SANDI];

        $this->panggil('viewer', 'POST', "/api/certificates/{$sertifikat->id}/tarik", $badan)->assertForbidden();
        $this->panggil('teknisi', 'POST', "/api/certificates/{$sertifikat->id}/tarik", $badan)->assertForbidden();
        $this->panggil('admin2', 'POST', "/api/certificates/{$sertifikat->id}/tarik", $badan)->assertOk();
        // Tarik dua kali: ditolak jelas.
        $this->panggil('admin2', 'POST', "/api/certificates/{$sertifikat->id}/tarik", $badan)->assertUnprocessable();
    }

    /**
     * Keluaran `php artisan akun:super-admin` harus sesuai K-49-14 (15 Okt 2026):
     * super admin menulis di APLIKASI organisasinya sendiri, panel tetap baca
     * saja. Dulu teksnya masih "TIDAK: menulis apa pun ... tertutup sampai K4
     * dijawab" -- operator yang membacanya memberi tahu peserta pelatihan hal
     * yang salah.
     */
    public function test_07d_perintah_pembuat_super_admin_berbicara_sesuai_keputusan_k_49_14(): void
    {
        Artisan::call('akun:super-admin', [
            'email' => 'super2@lab.test', '--organisasi' => $this->lab->id, '--paksa' => true,
        ]);
        $keluaran = Artisan::output();

        foreach (['menulis apa pun', 'tertutup sampai K4', 'nol wewenang'] as $basi) {
            $this->assertStringNotContainsString($basi, $keluaran, "Pesan perintah akun:super-admin basi ({$basi}): bertentangan dengan K-49-14.");
        }
        $this->assertStringContainsString('K-49-14', $keluaran);
        $this->assertStringContainsString('panel /admin baca saja', $keluaran);
        $this->assertStringNotContainsString('nol wewenang', (string) Artisan::all()['akun:super-admin']->getDescription());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 8. Studio
    // ═════════════════════════════════════════════════════════════════════════

    private const STUDIO = ['X-Klien' => 'sidik-studio', 'X-Versi-Klien' => '1.0.0'];

    private function loginStudio(string $peran, ?string $sandi = null): TestResponse
    {
        return $this->login($peran, self::STUDIO, $sandi);
    }

    public function test_08_login_studio_hanya_untuk_studio_akun_dan_token_berability_studio(): void
    {
        config(['studio.akun' => 'studio@lab.test']);

        $respons = $this->loginStudio('studio')
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email', 'role']]]);
        $token = PersonalAccessToken::findToken((string) $respons->json('data.token'));
        $this->assertSame(['studio'], $token->abilities);

        // Akun lab lain, termasuk super admin dan admin lain: 403 tanpa token.
        foreach (['admin', 'teknisi', 'super', 'viewer'] as $peran) {
            $this->loginStudio($peran)
                ->assertForbidden()
                ->assertJsonPath('kode', 'bukan_akun_studio')
                ->assertJsonMissingPath('data.token');
            $this->assertSame(0, $this->akun[$peran]->tokens()->count());
        }

        // Sandi salah tetap 401 (tidak membocorkan siapa akun Studio).
        $this->loginStudio('studio', 'salah')->assertUnauthorized();

        // Klien Studio terlalu lama: 426.
        config(['studio.versi_minimum' => '2.0.0']);
        $this->login('studio', ['X-Klien' => 'sidik-studio', 'X-Versi-Klien' => '1.0.0'])->assertStatus(426)->assertJsonPath('kode', 'klien_kedaluwarsa');
    }

    public function test_08b_studio_membaca_workbook_data_acuan_dan_garis_waktu(): void
    {
        config(['studio.akun' => 'studio@lab.test']);
        Storage::fake('local');
        $this->siapkanFolderUji();
        $this->artisan('studio:impor-workbook', [
            '--csv' => true, '--akar' => $this->buatAkarCsv(),
            '--organisasi' => (string) $this->lab->id, '--oleh' => (string) $this->akun['admin']->email,
        ])->assertSuccessful();
        SumberAcuan::lupakanCache();
        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer', '--organisasi' => $this->lab->id])->assertSuccessful();

        $token = (string) $this->loginStudio('studio')->assertOk()->json('data.token');
        $studio = fn (string $uri) => $this->panggilToken($token, 'GET', $uri, [], self::STUDIO);

        $studio('/api/studio/me')->assertOk()->assertJsonPath('data.user.email', 'studio@lab.test')->assertJsonStructure(['data' => ['user', 'izin']])->assertJsonPath('data.izin.role', 'studio');

        $daftar = $studio('/api/studio/workbook')->assertOk()->assertJsonStructure(['data']);
        $wb = collect($daftar->json('data'))->firstWhere('nama', 'Master_Olah_Data_Micrometer_0-25mm');
        $this->assertNotNull($wb, 'Workbook hasil impor tidak muncul di daftar Studio.');
        $studio("/api/studio/workbook/{$wb['id']}")->assertOk()->assertJsonStructure(['data']);
        $studio("/api/studio/workbook/{$wb['id']}/riwayat")->assertOk()->assertJsonStructure(['data']);

        // Data acuan: paket, detail, berlaku, garis waktu versi, satu versi.
        $paket = $studio('/api/data-acuan/paket')->assertOk()->assertJsonStructure(['data' => [['kode', 'nama', 'diimpor']]]);
        $this->assertContains('micrometer', array_column($paket->json('data'), 'kode'));
        $studio('/api/data-acuan/paket/micrometer')->assertOk()->assertJsonPath('data.kode', 'micrometer');
        $studio('/api/data-acuan/paket/micrometer/berlaku?tanggal=2026-10-08')->assertOk()->assertJsonStructure(['data']);
        $garis = $studio('/api/data-acuan/paket/micrometer/versi')->assertOk()->assertJsonStructure(['data']);
        $this->assertNotEmpty($garis->json('data'), 'Garis waktu versi kosong padahal versi 1 sudah diimpor.');
        $versi = PaketAcuanVersi::query()->where('organization_id', $this->lab->id)->where('nomor_versi', 1)->sole();
        $studio("/api/data-acuan/versi/{$versi->id}")->assertOk()->assertJsonStructure(['data']);

        // Rute lab biasa: token Studio ditolak.
        foreach (['/api/me', '/api/calibrations', '/api/certificates', '/api/equipments'] as $uri) {
            $studio($uri)->assertForbidden();
        }
        // Rute TULIS data acuan juga tertutup untuk Studio.
        $this->panggilToken($token, 'POST', "/api/data-acuan/versi/{$versi->id}/ajukan", [], self::STUDIO)->assertForbidden();
    }

    public function test_08c_token_lab_ditolak_di_rute_studio_dan_data_acuan_terbuka_untuk_peran_lab(): void
    {
        config(['studio.akun' => 'studio@lab.test']);
        SumberAcuan::lupakanCache();
        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer', '--organisasi' => $this->lab->id])->assertSuccessful();

        // Token lab (admin, bahkan admin yang sama dengan STUDIO_AKUN) tidak membuka Studio.
        foreach (['admin', 'teknisi', 'super', 'viewer', 'studio'] as $peran) {
            $this->panggil($peran, 'GET', '/api/studio/workbook')->assertForbidden();
            $this->panggil($peran, 'GET', '/api/studio/me')->assertForbidden();
        }
        $this->tamu('GET', '/api/studio/workbook')->assertUnauthorized();

        // Layar Data Acuan terbuka untuk peran lab (baca).
        foreach (['admin', 'teknisi', 'viewer', 'super'] as $peran) {
            $this->panggil($peran, 'GET', '/api/data-acuan/paket')->assertOk()->assertJsonStructure(['data']);
            $this->panggil($peran, 'GET', '/api/data-acuan/paket/micrometer/versi')->assertOk();
        }

        // Pelanggan tidak.
        $pic = $this->pelangganLewatUndangan($this->pt, 'pic@pt-contoh.test', 'pic_utama');
        $this->panggilToken($pic, 'GET', '/api/data-acuan/paket')->assertForbidden();
        $this->panggilToken($pic, 'GET', '/api/studio/workbook')->assertForbidden();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 9. Rantai demo pemilik: undang -> isi -> setujui -> terbit -> notifikasi -> unduh
    // ═════════════════════════════════════════════════════════════════════════

    public function test_06d_rute_undangan_admin_hanya_untuk_admin_organisasinya_dan_kode_tidak_bocor(): void
    {
        $badan = ['email' => 'baru@pt-contoh.test', 'peran' => 'staf'];

        $this->panggil('teknisi', 'POST', "/api/customers/{$this->pt->id}/undangan", $badan)->assertForbidden();
        $this->panggil('viewer', 'POST', "/api/customers/{$this->pt->id}/undangan", $badan)->assertForbidden();

        $adminAsing = User::factory()->admin()->create(['organization_id' => $this->labLain->id, 'email' => 'admin@lab-lain.test']);
        $this->panggilToken($adminAsing->createToken('uji', ['internal'])->plainTextToken, 'POST', "/api/customers/{$this->pt->id}/undangan", $badan)->assertNotFound();

        // Super admin boleh (K-49-14) dan juga menerima kode.
        $this->panggil('super', 'POST', "/api/customers/{$this->pt->id}/undangan", $badan)
            ->assertCreated()->assertJsonStructure(['data' => ['kode', 'email_terkirim']]);

        // Penolakan 422 (anggota aktif sudah ada) tidak membawa kode.
        $pic = $this->pelangganLewatUndangan($this->pt, 'pic@pt-contoh.test', 'pic_utama');
        $tolak = $this->panggil('admin', 'POST', "/api/customers/{$this->pt->id}/undangan", ['email' => 'pic@pt-contoh.test', 'peran' => 'staf'])
            ->assertUnprocessable();
        $this->assertNull($tolak->json('data.kode'));
        $this->assertNull($tolak->json('kode'));

        // Token pelanggan tidak bisa memakai rute admin ini.
        $this->panggilToken($pic, 'POST', "/api/customers/{$this->pt->id}/undangan", $badan)->assertForbidden();
    }

    public function test_09_rantai_demo_pemilik_dari_undangan_sampai_unduh_pdf(): void
    {
        // (1) Admin mengundang lewat API; kode dari data.kode -> ditukar -> token pelanggan.
        $pic = $this->pelangganLewatUndangan($this->pt, 'pic@pt-contoh.test', 'pic_utama');
        $picLain = $this->pelangganLewatUndangan($this->ptLain, 'pic@pt-saingan.test', 'pic_utama');

        // (2) Teknisi mengisi empat jenis alat; admin kedua mengisi Order Number & menyetujui.
        $bahan = [
            'DEMO-0001' => $this->payloadLabu($this->alat('Labu Ukur', 200)),
            'DEMO-0002' => $this->payloadPipet($this->alat('Pipet Volume', 2)),
            'DEMO-0003' => $this->payloadGelas($this->alat('Gelas Ukur', 100, 1)),
            'DEMO-0004' => $this->payloadTits(),
        ];
        $terbit = [];
        $rekam = [];

        foreach ($bahan as $nomor => $payload) {
            [$id] = $this->kirimSesi('teknisi', $payload);
            $this->isiOrder('admin2', $id, 'ORD-'.$nomor);

            // (5) Penolakan: teknisi (pengisi) dihentikan gerbang peran (403); akhiran -R<n> dan
            // nomor ganda ditolak tanpa menghabiskan sesi.
            $this->setujui('teknisi', $id, $nomor)->assertForbidden();
            $this->setujui('admin2', $id, $nomor.'-R1')->assertUnprocessable()->assertJsonPath('kode', 'nomor_sertifikat_tidak_valid');
            if ($terbit !== []) {
                $this->setujui('admin2', $id, array_key_first($terbit))->assertUnprocessable()->assertJsonPath('kode', 'nomor_sertifikat_dipakai');
            }

            $this->setujui('admin2', $id, $nomor)->assertOk();
            $sertifikat = Certificate::query()->where('calibration_session_id', $id)->sole();
            $terbit[$nomor] = $sertifikat;

            // (2) Terbit, snapshot beku, PDF ada & tidak kosong.
            $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status, $nomor);
            $this->assertSame($nomor, $sertifikat->snapshot['header']['certificate_number'], $nomor);
            $this->assertNotEmpty($sertifikat->snapshot['hasil'], $nomor);
            $isi = Storage::disk('arsip')->get($sertifikat->pdf_path);
            $this->assertStringStartsWith('%PDF', $isi, $nomor);
            $this->assertGreaterThan(5000, strlen($isi), "{$nomor}: PDF terlalu kecil");

            // G0-a: tata letak membeku di snapshot. Bentuk "End of Certificate" dua macam:
            // grup garis-teks-garis (`akhir_sertifikat_garis`, 51 lembar) ATAU sel teks
            // (`akhir_sertifikat`, 13 profil termasuk Labu Ukur/Pipet/Gelas/TITS).
            $tataLetak = $sertifikat->snapshot['tata_letak'] ?? [];
            $rekam[$nomor] = [
                'garis' => $tataLetak['akhir_sertifikat_garis'] ?? null,
                'teks' => $tataLetak['akhir_sertifikat'] ?? null,
                'titik_dua' => $tataLetak['kepala_titik_dua'] ?? '(tak ada)',
            ];

            $this->assertTrue($rekam[$nomor]['titik_dua'] === true, "{$nomor}: kepala_titik_dua bukan true di snapshot");
            $akhir = $rekam[$nomor]['garis']['teks'] ?? $rekam[$nomor]['teks'];
            $this->assertStringContainsString('End of Certificate', (string) $akhir, "{$nomor}: penutup sertifikat tidak ada di snapshot");
        }

        // (3) Pelanggan yang diundang: notifikasi sertifikat terbit, daftar, unduh PDF.
        $notif = $this->panggilToken($pic, 'GET', '/api/pelanggan/v1/notifikasi')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'kategori', 'judul', 'isi', 'tautan' => ['tipe', 'id']]], 'meta' => ['total']])
            ->assertJsonPath('meta.total', 4);
        $this->assertSame(['pelanggan.sertifikat_terbit'], array_values(array_unique(array_column($notif->json('data'), 'kategori'))));
        $idDiNotif = array_map(fn (array $n): int => (int) $n['tautan']['id'], $notif->json('data'));
        sort($idDiNotif);
        $idTerbit = array_map(fn (Certificate $c): int => $c->id, array_values($terbit));
        sort($idTerbit);
        $this->assertSame($idTerbit, $idDiNotif, 'Tautan notifikasi tidak menunjuk keempat sertifikat.');
        foreach ($notif->json('data') as $n) {
            $this->assertSame('pelanggan_sertifikat', $n['tautan']['tipe']);
            $this->assertStringNotContainsString('PT CONTOH', $n['isi'].$n['judul'], 'Notifikasi membocorkan nama perusahaan.');
        }

        $nomorDaftar = collect($this->panggilToken($pic, 'GET', '/api/pelanggan/v1/sertifikat')->assertOk()->json('data'))->pluck('nomor')->sort()->values()->all();
        $this->assertSame(array_keys($terbit), $nomorDaftar);

        foreach ($terbit as $nomor => $sertifikat) {
            $this->panggilToken($pic, 'GET', "/api/pelanggan/v1/sertifikat/{$sertifikat->id}")->assertOk()->assertJsonPath('data.nomor', $nomor);

            $this->app['auth']->forgetGuards();
            $pdf = $this->flushHeaders()->withToken($pic)->get("/api/pelanggan/v1/sertifikat/{$sertifikat->id}/unduh")->assertOk()->streamedContent();
            $this->assertStringStartsWith('%PDF', $pdf, $nomor);
            $this->assertSame(strlen(Storage::disk('arsip')->get($sertifikat->pdf_path)), strlen($pdf), $nomor);

            // Pelanggan perusahaan lain: 404, bukan 403.
            $this->panggilToken($picLain, 'GET', "/api/pelanggan/v1/sertifikat/{$sertifikat->id}")->assertNotFound();
            $this->panggilToken($picLain, 'GET', "/api/pelanggan/v1/sertifikat/{$sertifikat->id}/unduh")->assertNotFound();
        }

        $this->panggilToken($picLain, 'GET', '/api/pelanggan/v1/notifikasi')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame([], $this->panggilToken($picLain, 'GET', '/api/pelanggan/v1/sertifikat')->json('data'));

        $this->tamu('GET', '/api/verify/'.$terbit['DEMO-0001']->qr_token)->assertOk()->assertJsonPath('data.nomor', 'DEMO-0001');
    }

    public function test_08d_studio_akun_kosong_menutup_semuanya(): void
    {
        config(['studio.akun' => '']);

        $this->loginStudio('studio')->assertForbidden()->assertJsonPath('kode', 'bukan_akun_studio');
        $this->assertSame(0, WorkbookMaster::query()->count());
    }
}
