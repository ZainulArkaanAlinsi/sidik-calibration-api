<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\MatriksIzin;
use App\Support\FiturSaklar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Saklar fitur berbasis basis data untuk Super Admin (paket OPS-KODE butir 3).
 *
 * ## Yang dijaga, dan kenapa tidak ada yang berbunyi kalau dilanggar
 *
 *  - SIAPA: hanya `super_admin` yang bisa menetapkan; `admin` (Master Data) dan
 *    yang lain 403. Kunci `fitur_nonaktif` tidak bisa ditulis lewat
 *    `PUT /organization` (yang terbuka untuk admin) dan tidak terhapus olehnya.
 *  - APA: daftarnya tertutup. Pengisian, hitung, persetujuan, dan penerbitan
 *    TIDAK bisa dimatikan — dijaga lewat validasi DAN sapuan rute: rute inti
 *    tidak boleh punya `fitur:`.
 *  - DI MANA: rute fitur yang dimatikan menjawab 503 `fitur_dimatikan`, SESUDAH
 *    pemeriksaan peran (403 dulu) dan SEBELUM route model binding (tanpa
 *    oracle keberadaan id).
 *  - UNTUK SIAPA: satu organisasi. Lab lain tidak tersentuh.
 *  - JEJAK: tiap perubahan menulis audit dengan nilai lama/baru, pelaku, alasan.
 */
class SaklarFiturTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/organization/fitur';

    private const ALASAN = 'OCR salah baca terus sejak kemarin';

    private Organization $orgA;

    private Organization $orgB;

    private User $superA;

    private User $adminA;

    private User $teknisiA;

    private User $viewerA;

    private User $teknisiB;

    private User $adminB;

    private User $superB;

    /**
     * Rute yang dijaga tiap fitur — daftar TERTULIS, bukan dibaca dari router,
     * supaya rute yang diam-diam kehilangan atau ketambahan `fitur:` membuat
     * test merah.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const RUTE = [
        'pindai_lembar' => [
            ['GET', '/api/worksheet-templates'],
            ['GET', '/api/worksheet-templates/ph'],
            ['POST', '/api/worksheet-scans'],
            ['GET', '/api/worksheet-scans/999999'],
            ['GET', '/api/worksheet-scans/999999/sel/a1/crop'],
            ['POST', '/api/worksheet-scans/999999/koreksi'],
            // Dijaga DUA saklar: `pindai_lembar` (grup) dan `ocr_cloud_sel` (rute).
            ['POST', '/api/worksheet-scans/999999/ocr-awan'],
        ],
        // Urutan mengikuti urutan pendaftaran rute di routes/api.php (tes
        // pemeta membandingkan tanpa mengurutkan antar-kode).
        // Potongan sel angka saja; saklarnya terpisah dari `ocr_cloud` (foto halaman utuh).
        'ocr_cloud_sel' => [
            ['POST', '/api/worksheet-scans/999999/ocr-awan'],
        ],
        'ocr_cloud' => [
            ['POST', '/api/ocr/halaman'],
        ],
        'kirim_email_sertifikat' => [
            ['POST', '/api/certificates/999999/kirim-email'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->superA = $this->akun(User::ROLE_SUPER_ADMIN, $this->orgA);
        $this->adminA = $this->akun(User::ROLE_ADMIN, $this->orgA);
        $this->teknisiA = $this->akun(User::ROLE_TEKNISI, $this->orgA);
        $this->viewerA = $this->akun(User::ROLE_VIEWER, $this->orgA);
        $this->teknisiB = $this->akun(User::ROLE_TEKNISI, $this->orgB);
        $this->adminB = $this->akun(User::ROLE_ADMIN, $this->orgB);
        $this->superB = $this->akun(User::ROLE_SUPER_ADMIN, $this->orgB);
    }

    /**
     * `actingAs` dengan salinan SEGAR pengguna. Objek `User` yang dipakai ulang
     * antar-permintaan menyimpan relasi `organization` dari permintaan pertama;
     * di produksi tiap permintaan memuat penggunanya sendiri, jadi cache basi itu
     * cuma ada di tes dan membuat saklar tampak "tidak berlaku".
     */
    private function sebagai(User $pengguna): static
    {
        return $this->actingAs($pengguna->fresh());
    }

    private function akun(string $role, Organization $organisasi): User
    {
        return User::factory()->create(['role' => $role, 'organization_id' => $organisasi->id]);
    }

    /** @param  list<string>  $kode */
    private function tetapkan(array $kode, ?User $oleh = null, string $alasan = self::ALASAN)
    {
        return $this->sebagai($oleh ?? $this->superA)
            ->putJson(self::URL, ['fitur_nonaktif' => $kode, 'alasan' => $alasan]);
    }

    /**
     * Siapa yang BERHAK memanggil rute fitur itu (peran diperiksa sebelum
     * saklar): pindai = Master Data & teknisi; kirim surel = Master Data saja.
     *
     * @return list<User>
     */
    private function pemakai(string $kode, ?Organization $organisasi = null): array
    {
        $b = $organisasi !== null && $organisasi->is($this->orgB);

        return in_array($kode, ['pindai_lembar', 'ocr_cloud', 'ocr_cloud_sel'], true)
            ? ($b ? [$this->teknisiB, $this->adminB] : [$this->teknisiA, $this->adminA])
            : ($b ? [$this->adminB] : [$this->adminA]);
    }

    private function panggil(User $oleh, string $method, string $uri): int
    {
        return $this->sebagai($oleh)->json($method, $uri)->getStatusCode();
    }

    // ------------------------------------------------------------- siapa

    public function test_tamu_ditolak_401(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->putJson(self::URL, ['fitur_nonaktif' => [], 'alasan' => self::ALASAN])->assertUnauthorized();
    }

    public function test_hanya_super_admin_boleh(): void
    {
        foreach ([$this->adminA, $this->teknisiA, $this->viewerA] as $bukan) {
            $this->sebagai($bukan)->getJson(self::URL)->assertForbidden();
            $this->tetapkan(['pindai_lembar'], $bukan)->assertForbidden();
        }

        $this->sebagai($this->superA)->getJson(self::URL)->assertOk();
        $this->tetapkan(['pindai_lembar'])->assertOk();
    }

    /** Admin yang ditolak tidak meninggalkan jejak apa pun di data. */
    public function test_penolakan_tidak_mengubah_apa_pun(): void
    {
        $this->tetapkan(['pindai_lembar'], $this->adminA)->assertForbidden();

        $this->assertSame([], FiturSaklar::nonaktif($this->orgA->fresh()));
        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_FITUR_DIMATIKAN)->count());
    }

    public function test_izin_fitur_atur_hanya_untuk_super_admin_di_matriks(): void
    {
        $this->assertArrayHasKey('fitur.atur', MatriksIzin::PETA);

        $matriks = app(MatriksIzin::class);

        $this->assertContains('fitur.atur', $matriks->bolehUntuk(User::ROLE_SUPER_ADMIN));

        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER] as $role) {
            $this->assertNotContains('fitur.atur', $matriks->bolehUntuk($role), $role);
        }
    }

    // --------------------------------------------------------------- baca

    public function test_get_bawaan_semua_nyala(): void
    {
        $this->sebagai($this->superA)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.fitur_nonaktif', [])
            ->assertJsonPath('data.fitur.0.kode', 'pindai_lembar')
            ->assertJsonPath('data.fitur.0.aktif', true)
            ->assertJsonPath('data.fitur.1.kode', 'kirim_email_sertifikat')
            ->assertJsonPath('data.fitur.1.aktif', true)
            ->assertJsonStructure(['data' => ['fitur' => [['kode', 'label', 'deskripsi', 'aktif']], 'fitur_nonaktif']]);
    }

    // ------------------------------------------------------------- tulis

    public function test_mematikan_lalu_menyalakan_kembali(): void
    {
        $this->tetapkan(['pindai_lembar'])
            ->assertOk()
            ->assertJsonPath('perubahan.dimatikan', ['pindai_lembar'])
            ->assertJsonPath('perubahan.dinyalakan', [])
            ->assertJsonPath('data.fitur_nonaktif', ['pindai_lembar'])
            ->assertJsonPath('data.fitur.0.aktif', false)
            ->assertJsonPath('data.fitur.1.aktif', true);

        $this->assertSame(['pindai_lembar'], $this->orgA->fresh()->settings['fitur_nonaktif']);

        $this->tetapkan([])
            ->assertOk()
            ->assertJsonPath('perubahan.dimatikan', [])
            ->assertJsonPath('perubahan.dinyalakan', ['pindai_lembar'])
            ->assertJsonPath('data.fitur_nonaktif', []);

        // Semua nyala = kuncinya dibuang, bukan disimpan sebagai daftar kosong.
        $this->assertArrayNotHasKey('fitur_nonaktif', $this->orgA->fresh()->settings ?? []);
    }

    public function test_urutan_mengikuti_daftar_resmi_dan_menggantikan_seluruhnya(): void
    {
        $this->tetapkan(['kirim_email_sertifikat', 'pindai_lembar'])->assertOk()
            ->assertJsonPath('data.fitur_nonaktif', ['pindai_lembar', 'kirim_email_sertifikat']);

        $this->tetapkan(['kirim_email_sertifikat'])->assertOk()
            ->assertJsonPath('perubahan.dinyalakan', ['pindai_lembar'])
            ->assertJsonPath('data.fitur_nonaktif', ['kirim_email_sertifikat']);
    }

    public function test_pengaturan_lain_di_settings_tidak_tersentuh(): void
    {
        $this->orgA->update(['settings' => ['prefix_sertifikat' => 'LAB', 'reminder_hari_sebelum' => 14]]);

        $this->tetapkan(['pindai_lembar'])->assertOk();

        $settings = $this->orgA->fresh()->settings;
        $this->assertSame('LAB', $settings['prefix_sertifikat']);
        $this->assertSame(14, $settings['reminder_hari_sebelum']);
        $this->assertSame(['pindai_lembar'], $settings['fitur_nonaktif']);
    }

    // -------------------------------------------------------- validasi

    public function test_alasan_wajib_dan_dibatasi_5_sampai_400(): void
    {
        foreach ([null, '', '    ', 'abcd', str_repeat('x', 401)] as $alasan) {
            $this->sebagai($this->superA)
                ->putJson(self::URL, ['fitur_nonaktif' => ['pindai_lembar'], 'alasan' => $alasan])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('alasan');
        }

        $this->sebagai($this->superA)
            ->putJson(self::URL, ['fitur_nonaktif' => ['pindai_lembar']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('alasan');

        $this->assertSame([], FiturSaklar::nonaktif($this->orgA->fresh()), 'Alasan ditolak tapi fitur tetap berubah.');

        $this->tetapkan(['pindai_lembar'], null, 'abcde')->assertOk();
        $this->tetapkan([], null, str_repeat('x', 400))->assertOk();
    }

    public function test_kode_tidak_dikenal_ditolak_422(): void
    {
        $this->tetapkan(['pindai_lembar', 'fitur_ngawur'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fitur_nonaktif.1');

        $this->assertSame([], FiturSaklar::nonaktif($this->orgA->fresh()), 'Satu kode salah membatalkan seluruh perubahan.');
        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_FITUR_DIMATIKAN)->count());
    }

    /** Fitur inti dijawab 422 dengan alasan yang jelas, bukan "tidak dikenal". */
    public function test_fitur_inti_tidak_bisa_dimatikan(): void
    {
        foreach (['isi_lembar', 'hitung', 'approve', 'persetujuan', 'penerbitan', 'sertifikat'] as $inti) {
            $respons = $this->tetapkan([$inti])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('fitur_nonaktif.0');

            $this->assertStringContainsString('inti', $respons->json('errors')['fitur_nonaktif.0'][0], $inti);
        }

        $this->assertSame([], FiturSaklar::nonaktif($this->orgA->fresh()));
    }

    public function test_bentuk_isian_yang_salah_ditolak(): void
    {
        $this->sebagai($this->superA);

        $this->putJson(self::URL, ['alasan' => self::ALASAN])
            ->assertUnprocessable()->assertJsonValidationErrors('fitur_nonaktif');
        $this->putJson(self::URL, ['fitur_nonaktif' => 'pindai_lembar', 'alasan' => self::ALASAN])
            ->assertUnprocessable()->assertJsonValidationErrors('fitur_nonaktif');
        $this->putJson(self::URL, ['fitur_nonaktif' => [5], 'alasan' => self::ALASAN])
            ->assertUnprocessable()->assertJsonValidationErrors('fitur_nonaktif.0');
        $this->putJson(self::URL, ['fitur_nonaktif' => ['pindai_lembar', 'pindai_lembar'], 'alasan' => self::ALASAN])
            ->assertUnprocessable()->assertJsonValidationErrors('fitur_nonaktif.0');
    }

    // ------------------------------------------------------------- audit

    public function test_audit_mencatat_pelaku_alasan_dan_nilai_lama_baru(): void
    {
        $this->tetapkan(['pindai_lembar'])->assertOk();

        $baris = AuditLog::where('action', AuditLog::ACTION_FITUR_DIMATIKAN)->sole();

        $this->assertSame('organizations', $baris->entity_type);
        $this->assertSame($this->orgA->id, $baris->entity_id);
        $this->assertSame($this->orgA->id, $baris->organization_id);
        $this->assertSame($this->superA->id, $baris->changed_by);
        $this->assertSame(['fitur_nonaktif' => []], $baris->old_data);
        $this->assertSame(['pindai_lembar'], $baris->new_data['fitur_nonaktif']);
        $this->assertSame('pindai_lembar', $baris->new_data['fitur']);
        $this->assertSame(self::ALASAN, $baris->new_data['alasan']);
        $this->assertSame('super_admin', $baris->new_data['peran_pelaku']);
        $this->assertStringContainsString(self::ALASAN, $baris->note);

        // Dan perubahan datanya sendiri tercatat otomatis oleh Diaudit.
        $this->assertSame(1, AuditLog::where('entity_type', 'organizations')
            ->where('entity_id', $this->orgA->id)->where('action', AuditLog::ACTION_DIUBAH)->count());

        $this->tetapkan([], null, 'sudah diperbaiki dan diuji ulang')->assertOk();

        $nyala = AuditLog::where('action', AuditLog::ACTION_FITUR_DINYALAKAN)->sole();
        $this->assertSame(['fitur_nonaktif' => ['pindai_lembar']], $nyala->old_data);
        $this->assertSame([], $nyala->new_data['fitur_nonaktif']);
        $this->assertSame('sudah diperbaiki dan diuji ulang', $nyala->new_data['alasan']);
    }

    public function test_dua_fitur_sekaligus_dua_baris_audit(): void
    {
        $this->tetapkan(['pindai_lembar', 'kirim_email_sertifikat'])->assertOk();

        $this->assertEqualsCanonicalizing(
            ['pindai_lembar', 'kirim_email_sertifikat'],
            AuditLog::where('action', AuditLog::ACTION_FITUR_DIMATIKAN)->get()->pluck('new_data.fitur')->all(),
        );
    }

    /** PUT yang tidak mengubah apa-apa tidak menulis audit (dan tidak pura-pura mengubah). */
    public function test_tanpa_perubahan_tidak_ada_audit(): void
    {
        $this->tetapkan(['pindai_lembar'])->assertOk();
        $sebelum = AuditLog::count();

        $this->tetapkan(['pindai_lembar'])
            ->assertOk()
            ->assertJsonPath('message', 'Tidak ada perubahan.')
            ->assertJsonPath('perubahan.dimatikan', [])
            ->assertJsonPath('perubahan.dinyalakan', []);

        $this->assertSame($sebelum, AuditLog::count());
    }

    /**
     * `audit_logs.action` itu `string(20)` di MySQL dan SQLite tidak
     * menegakkannya — di situlah galat 22001 "Data too long" pernah lolos.
     */
    public function test_nama_aksi_audit_muat_di_kolom(): void
    {
        $this->assertLessThanOrEqual(20, strlen(AuditLog::ACTION_FITUR_DIMATIKAN));
        $this->assertLessThanOrEqual(20, strlen(AuditLog::ACTION_FITUR_DINYALAKAN));
    }

    // -------------------------------------------- 503 di rute yang terdampak

    public function test_rute_fitur_menjawab_503_fitur_dimatikan(): void
    {
        foreach (self::RUTE as $kode => $daftar) {
            $this->tetapkan([$kode])->assertOk();

            foreach ($this->pemakai($kode) as $pengguna) {
                foreach ($daftar as [$method, $uri]) {
                    $this->sebagai($pengguna)->json($method, $uri)
                        ->assertStatus(503)
                        ->assertJsonPath('kode', 'fitur_dimatikan')
                        ->assertJsonPath('fitur', $kode)
                        ->assertJsonStructure(['message']);
                }
            }

            $this->tetapkan([])->assertOk();
        }
    }

    /** Dinyalakan kembali = rute berfungsi lagi (apa pun statusnya, bukan 503). */
    public function test_rute_fitur_normal_saat_nyala(): void
    {
        foreach (self::RUTE as $kode => $daftar) {
            foreach ($this->pemakai($kode) as $pengguna) {
                foreach ($daftar as [$method, $uri]) {
                    $this->assertNotSame(503, $this->panggil($pengguna, $method, $uri), "{$pengguna->role} {$method} {$uri}");
                }
            }
        }
    }

    public function test_satu_fitur_mati_tidak_mematikan_yang_lain(): void
    {
        $this->tetapkan(['pindai_lembar'])->assertOk();

        foreach (self::RUTE['kirim_email_sertifikat'] as [$method, $uri]) {
            $this->assertNotSame(503, $this->panggil($this->adminA, $method, $uri));
        }

        $this->tetapkan(['kirim_email_sertifikat'])->assertOk();

        foreach (self::RUTE['pindai_lembar'] as [$method, $uri]) {
            $this->assertNotSame(503, $this->panggil($this->teknisiA, $method, $uri));
        }
    }

    /**
     * Peran diperiksa SEBELUM saklar: viewer tidak berhak memakai rute ini
     * apa pun keadaannya, jadi dia mendapat 403 — bukan 503 yang membocorkan
     * bahwa fitur itu sedang dimatikan. Tamu tetap 401.
     */
    public function test_peran_tanpa_hak_dapat_403_bukan_503(): void
    {
        $this->tetapkan(['pindai_lembar', 'kirim_email_sertifikat'])->assertOk();

        foreach (self::RUTE as $daftar) {
            foreach ($daftar as [$method, $uri]) {
                $this->assertSame(403, $this->panggil($this->viewerA, $method, $uri), "viewer {$method} {$uri}");
            }
        }

        // Teknisi tidak berhak mengirim surel sertifikat (Master Data saja): 403.
        $this->assertSame(403, $this->panggil($this->teknisiA, 'POST', '/api/certificates/999999/kirim-email'));

        $this->app['auth']->forgetGuards();
        $this->json('POST', '/api/worksheet-scans')->assertUnauthorized();
    }

    /**
     * Tanpa oracle: saat fitur mati, id yang tidak ada dan id yang ada sama-sama
     * 503 (saklar jalan sebelum route model binding). Saat nyala, id yang tidak
     * ada 404 seperti biasa.
     */
    public function test_503_datang_sebelum_binding_jadi_tidak_ada_oracle_id(): void
    {
        $this->assertSame(404, $this->panggil($this->teknisiA, 'GET', '/api/worksheet-scans/999999'));

        $this->tetapkan(['pindai_lembar'])->assertOk();

        $this->assertSame(503, $this->panggil($this->teknisiA, 'GET', '/api/worksheet-scans/999999'));
        $this->assertSame(503, $this->panggil($this->teknisiA, 'GET', '/api/worksheet-scans/1'));
    }

    // --------------------------------------------------------- /me/permissions

    public function test_me_permissions_memuat_fitur_nonaktif_untuk_semua_peran(): void
    {
        foreach ([$this->superA, $this->adminA, $this->teknisiA, $this->viewerA] as $pengguna) {
            $this->sebagai($pengguna)->getJson('/api/me/permissions')
                ->assertOk()
                ->assertJsonPath('data.fitur_nonaktif', [])
                ->assertJsonStructure(['data' => ['role', 'boleh', 'batasan', 'fitur_nonaktif']]);
        }

        $this->tetapkan(['kirim_email_sertifikat', 'pindai_lembar'])->assertOk();

        foreach ([$this->superA, $this->adminA, $this->teknisiA, $this->viewerA] as $pengguna) {
            $this->sebagai($pengguna)->getJson('/api/me/permissions')
                ->assertOk()
                ->assertJsonPath('data.fitur_nonaktif', ['pindai_lembar', 'kirim_email_sertifikat']);
        }
    }

    // ------------------------------------------------- isolasi organisasi

    public function test_saklar_satu_lab_tidak_menyentuh_lab_lain(): void
    {
        $this->tetapkan(['pindai_lembar', 'kirim_email_sertifikat'])->assertOk();

        foreach (self::RUTE as $kode => $daftar) {
            foreach ($this->pemakai($kode, $this->orgB) as $pengguna) {
                foreach ($daftar as [$method, $uri]) {
                    $this->assertNotSame(503, $this->panggil($pengguna, $method, $uri), "lab B {$pengguna->role} {$method} {$uri}");
                }
            }
        }

        $this->sebagai($this->teknisiB)->getJson('/api/me/permissions')->assertJsonPath('data.fitur_nonaktif', []);
        $this->sebagai($this->superB)->getJson(self::URL)->assertJsonPath('data.fitur_nonaktif', []);
        $this->assertArrayNotHasKey('fitur_nonaktif', $this->orgB->fresh()->settings ?? []);
    }

    /** Tidak ada jalan menyetel lab lain: `organization_id` di badan diabaikan. */
    public function test_organization_id_di_badan_diabaikan(): void
    {
        $this->sebagai($this->superA)
            ->putJson(self::URL, [
                'fitur_nonaktif' => ['pindai_lembar'],
                'alasan' => self::ALASAN,
                'organization_id' => $this->orgB->id,
            ])
            ->assertOk();

        $this->assertSame(['pindai_lembar'], FiturSaklar::nonaktif($this->orgA->fresh()));
        $this->assertSame([], FiturSaklar::nonaktif($this->orgB->fresh()));
        $this->assertSame(0, AuditLog::where('organization_id', $this->orgB->id)
            ->where('action', AuditLog::ACTION_FITUR_DIMATIKAN)->count());
    }

    // ------------------------------------------------- inti tak terpengaruh

    /**
     * Dua fitur mati sekaligus: pengisian, hitung, persetujuan, dan penerbitan
     * tetap TIDAK dijawab 503. Status lain (404/422/...) dianggap "lolos" —
     * yang diuji gerbang saklarnya, bukan isi permintaan.
     */
    public function test_inti_tetap_jalan_saat_semua_fitur_mati(): void
    {
        $this->tetapkan(['pindai_lembar', 'kirim_email_sertifikat'])->assertOk();

        $inti = [
            ['POST', '/api/calibrations'],
            ['PUT', '/api/calibrations/999999'],
            ['POST', '/api/calibrations/preview'],
            ['POST', '/api/calibrations/autoclave/preview'],
            ['POST', '/api/calibrations/autoclave'],
            ['POST', '/api/calibrations/999999/approve'],
            ['POST', '/api/calibrations/999999/measurements/verify'],
            ['POST', '/api/certificates/999999/retry'],
            ['GET', '/api/certificates/999999/download'],
            ['GET', '/api/certificates'],
            ['GET', '/api/certificates/999999/riwayat-email'],
            ['POST', '/api/certificates/999999/catat-whatsapp'],
        ];

        foreach ([$this->teknisiA, $this->adminA] as $pengguna) {
            foreach ($inti as [$method, $uri]) {
                $this->assertNotSame(503, $this->panggil($pengguna, $method, $uri), "{$pengguna->role} {$method} {$uri}");
            }
        }
    }

    /**
     * Sapuan rute: yang ber-`fitur:` PERSIS daftar tertulis, kodenya terdaftar,
     * dan tidak ada rute inti di antaranya. Rute inti yang kelak diberi `fitur:`
     * membuat test ini merah, bukan lolos diam-diam.
     */
    public function test_hanya_rute_opsional_yang_punya_saklar(): void
    {
        $dipasang = [];

        foreach (Route::getRoutes() as $rute) {
            foreach ($rute->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'fitur:')) {
                    $kode = substr($mw, 6);
                    $this->assertContains($kode, FiturSaklar::kode(), "{$rute->uri()} memakai kode fitur tak terdaftar: {$kode}");
                    foreach ($rute->methods() as $method) {
                        if ($method !== 'HEAD') {
                            $dipasang[$kode][] = $method.' /'.$rute->uri();
                        }
                    }
                }
            }
        }

        $diharapkan = [];
        foreach (self::RUTE as $kode => $daftar) {
            foreach ($daftar as [$method, $uri]) {
                // Uji memakai id/nama contoh; router menyimpan templatnya.
                $diharapkan[$kode][] = $method.' '.$this->templat($uri);
            }
        }

        foreach ([$diharapkan, $dipasang] as &$peta) {
            foreach ($peta as &$daftar) {
                sort($daftar);
            }
            ksort($peta);
        }
        unset($peta, $daftar);

        $this->assertSame($diharapkan, $dipasang);
    }

    /** Daftar resmi tidak memuat nama fitur inti. */
    public function test_daftar_resmi_tidak_memuat_fitur_inti(): void
    {
        $this->assertSame([], array_values(array_intersect(FiturSaklar::kode(), FiturSaklar::INTI)));
        $this->assertSame(['pindai_lembar', 'kirim_email_sertifikat', 'ocr_cloud', 'ocr_cloud_sel'], FiturSaklar::kode());
    }

    private function templat(string $uri): string
    {
        return match ($uri) {
            '/api/worksheet-templates/ph' => '/api/worksheet-templates/{kode}',
            '/api/worksheet-scans/999999' => '/api/worksheet-scans/{worksheetScan}',
            '/api/worksheet-scans/999999/sel/a1/crop' => '/api/worksheet-scans/{worksheetScan}/sel/{kunci}/crop',
            '/api/worksheet-scans/999999/koreksi' => '/api/worksheet-scans/{worksheetScan}/koreksi',
            '/api/worksheet-scans/999999/ocr-awan' => '/api/worksheet-scans/{worksheetScan}/ocr-awan',
            '/api/certificates/999999/kirim-email' => '/api/certificates/{certificate}/kirim-email',
            default => $uri,
        };
    }

    // ------------------------------------- PUT /organization tidak bisa lewat

    /**
     * Kunci `fitur_nonaktif` tidak ada di daftar kunci `PUT /organization`, jadi
     * dijawab 422 — untuk Master Data MAUPUN super admin. Itu satu-satunya hal
     * yang mencegah admin menyalakan/mematikan fitur lewat pintu yang terbuka
     * untuknya.
     */
    public function test_put_organization_menolak_kunci_saklar(): void
    {
        foreach ([$this->adminA, $this->superA] as $pengguna) {
            $this->sebagai($pengguna)
                ->putJson('/api/organization', ['settings' => ['fitur_nonaktif' => ['pindai_lembar']]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('settings');

            $this->sebagai($pengguna)
                ->putJson('/api/organization', ['settings' => ['fitur_nonaktif' => null]])
                ->assertUnprocessable();
        }

        $this->assertSame([], FiturSaklar::nonaktif($this->orgA->fresh()));
    }

    /** ...dan `PUT /organization` yang sah tidak menghapus saklar yang sudah dipasang. */
    public function test_put_organization_biasa_tidak_menghapus_saklar(): void
    {
        $this->tetapkan(['pindai_lembar'])->assertOk();

        $this->sebagai($this->adminA)
            ->putJson('/api/organization', ['settings' => ['prefix_sertifikat' => 'LAB']])
            ->assertOk();

        $this->assertSame(['pindai_lembar'], FiturSaklar::nonaktif($this->orgA->fresh()));
        $this->assertSame('LAB', $this->orgA->fresh()->settings['prefix_sertifikat']);

        // Dan tetap terbaca di rute yang dijaga.
        $this->assertSame(503, $this->panggil($this->teknisiA, 'GET', '/api/worksheet-templates'));
    }

    // ------------------------------------------------------- pembaca tangguh

    public function test_pembaca_mengabaikan_isi_yang_rusak(): void
    {
        $this->orgA->update(['settings' => ['fitur_nonaktif' => ['zzz', 5, null, 'pindai_lembar', 'pindai_lembar', ['x']]]]);
        $this->assertSame(['pindai_lembar'], FiturSaklar::nonaktif($this->orgA->fresh()));

        $this->orgA->update(['settings' => ['fitur_nonaktif' => 'pindai_lembar']]);
        $this->assertSame([], FiturSaklar::nonaktif($this->orgA->fresh()));

        $this->orgA->update(['settings' => null]);
        $this->assertSame([], FiturSaklar::nonaktif($this->orgA->fresh()));

        $this->assertSame([], FiturSaklar::nonaktif(null));
        $this->assertTrue(FiturSaklar::aktif(null, 'pindai_lembar'));
    }

    // ------------------------------------------------------------ throttle

    public function test_put_dibatasi_20_per_menit(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->tetapkan([])->assertOk();
        }

        $this->tetapkan([])->assertStatus(429);

        // Membaca tidak ikut dibatasi oleh ember tulis.
        $this->sebagai($this->superA)->getJson(self::URL)->assertOk();
    }
}
