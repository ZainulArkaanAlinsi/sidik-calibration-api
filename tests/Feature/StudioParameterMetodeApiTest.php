<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ParameterMetodePenetapan;
use App\Models\ParameterMetodePenetapanJejak;
use App\Models\User;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Acuan\PenetapanParameterMetode;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use LogicException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * Studio Tahap 1 (25 Nov 2026): penetapan keputusan metode lewat API Studio.
 * Kontrak: `docs/perintah-frontend-parameter-metode-studio.md`.
 *
 * Yang dikunci:
 *  - Pintu: hanya akun Studio (`STUDIO_AKUN`) dengan token `studio`; akun lab lain,
 *    token lab (`internal`/`*`), dan sesi cookie ditolak 403; tanpa token 401.
 *  - Aturan D3 ditegakkan server: cap waktu server, `berlaku_mulai` tidak boleh
 *    surut dan tidak boleh turun menurut nomor, transisi status hanya yang sah.
 *  - Append-only: isi baris tak bisa diubah/dihapus; jejak tulis-sekali.
 *  - Baris `menunggu_tm` / `ditarik` TIDAK memengaruhi angka; hanya `disetujui`.
 */
class StudioParameterMetodeApiTest extends TestCase
{
    use RefreshDatabase;

    private const SANDI = 'sandi-akun-studio-1';

    private const STUDIO = ['X-Klien' => 'sidik-studio', 'X-Versi-Klien' => '1.0.0'];

    private const KUNCI = 'spectrophotometer.max_stdev_holmium';

    private const HARI_INI = '2026-12-01';

    private Organization $lab;

    private User $pemilik;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::HARI_INI.' 09:30:00');
        ParameterKeputusanMetode::lupakan();

        $this->lab = Organization::factory()->create();
        $this->pemilik = $this->akun(User::ROLE_SUPER_ADMIN, 'pemilik@sidik.test');
        config(['studio.akun' => 'pemilik@sidik.test']);

        $this->token = $this->pemilik->createToken('studio', ['studio'])->plainTextToken;
        $this->app['auth']->forgetGuards();
    }

    protected function tearDown(): void
    {
        // Pendengar `creating` buatan test (sebelumSimpanBarisPertama) tidak boleh bocor.
        app('events')->forget('eloquent.creating: '.ParameterMetodePenetapan::class);
        Carbon::setTestNow();
        ParameterKeputusanMetode::lupakan();
        parent::tearDown();
    }

    private function akun(string $role, string $email): User
    {
        return User::factory()->create([
            'organization_id' => $this->lab->id,
            'role' => $role,
            'status' => User::STATUS_AKTIF,
            'email' => $email,
            'password' => Hash::make(self::SANDI),
        ]);
    }

    /** Request sebagai akun Studio. Guard dibersihkan tiap panggilan (token dibaca ulang). */
    private function studio(string $metode, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->json($metode, $uri, $data, self::STUDIO);
    }

    /** @return array<string, string> */
    private function badanCatat(array $timpa = []): array
    {
        return array_merge([
            'nilai' => 'tanpa_titik_pertama',
            'berlaku_mulai' => self::HARI_INI,
            'catatan' => 'Usulan lab: titik pertama Holmium dibuang.',
        ], $timpa);
    }

    private function catat(array $timpa = [], string $kunci = self::KUNCI): int
    {
        return (int) $this->studio('POST', "/api/studio/parameter-metode/{$kunci}/penetapan", $this->badanCatat($timpa))
            ->assertCreated()
            ->json('data.id');
    }

    private function setujui(int $id, array $timpa = []): TestResponse
    {
        return $this->studio('POST', "/api/studio/parameter-metode/penetapan/{$id}/setujui", array_merge([
            'keputusan_oleh' => 'TM Spektro (Bu Rina)',
            'tanggal_keputusan' => self::HARI_INI,
        ], $timpa));
    }

    private function tarik(int $id, array $timpa = []): TestResponse
    {
        return $this->studio('POST', "/api/studio/parameter-metode/penetapan/{$id}/tarik", array_merge([
            'alasan' => 'Salah ketik tanggal berlaku mulai.',
        ], $timpa));
    }

    /** Pilihan yang berlaku untuk KUNCI pada tanggal tertentu, dibaca lewat jalur hitung (bukan endpoint). */
    private function berlaku(string $tanggal): string
    {
        ParameterKeputusanMetode::lupakan();

        return app(ParameterKeputusanMetode::class)->nilai(self::KUNCI, $tanggal);
    }

    // ---------------------------------------------------------------- pintu

    public function test_tanpa_token_401_untuk_semua_rute(): void
    {
        $this->getJson('/api/studio/parameter-metode', self::STUDIO)->assertUnauthorized();
        $this->postJson('/api/studio/parameter-metode/'.self::KUNCI.'/penetapan', $this->badanCatat(), self::STUDIO)->assertUnauthorized();
        $this->postJson('/api/studio/parameter-metode/penetapan/1/setujui', [], self::STUDIO)->assertUnauthorized();
        $this->postJson('/api/studio/parameter-metode/penetapan/1/tarik', [], self::STUDIO)->assertUnauthorized();
    }

    public function test_akun_lab_lain_dengan_token_studio_ditolak_403(): void
    {
        $lain = $this->akun(User::ROLE_ADMIN, 'admin.lain@sidik.test');
        $tokenLain = $lain->createToken('studio', ['studio'])->plainTextToken;

        foreach ([
            ['GET', '/api/studio/parameter-metode'],
            ['POST', '/api/studio/parameter-metode/'.self::KUNCI.'/penetapan'],
            ['POST', '/api/studio/parameter-metode/penetapan/1/setujui'],
            ['POST', '/api/studio/parameter-metode/penetapan/1/tarik'],
        ] as [$metode, $uri]) {
            $this->app['auth']->forgetGuards();
            $this->withToken($tokenLain)->json($metode, $uri, $this->badanCatat(), self::STUDIO)
                ->assertForbidden()
                ->assertJsonPath('kode', 'bukan_akun_studio');
        }

        $this->assertSame(0, ParameterMetodePenetapan::query()->count());
    }

    public function test_token_lab_ditolak_403_walau_emailnya_akun_studio(): void
    {
        foreach (['internal', '*'] as $kemampuan) {
            Sanctum::actingAs($this->pemilik, [$kemampuan]);

            $this->getJson('/api/studio/parameter-metode', self::STUDIO)
                ->assertForbidden()
                ->assertJsonPath('kode', 'bukan_token_studio');
            $this->postJson('/api/studio/parameter-metode/'.self::KUNCI.'/penetapan', $this->badanCatat(), self::STUDIO)
                ->assertForbidden();
            $this->postJson('/api/studio/parameter-metode/penetapan/1/setujui', [], self::STUDIO)->assertForbidden();
            $this->postJson('/api/studio/parameter-metode/penetapan/1/tarik', [], self::STUDIO)->assertForbidden();
        }

        $this->assertSame(0, ParameterMetodePenetapan::query()->count());
    }

    public function test_sesi_cookie_akun_studio_tanpa_token_studio_ditolak_403(): void
    {
        $this->actingAs($this->pemilik);

        $this->getJson('/api/studio/parameter-metode', self::STUDIO)->assertForbidden();
    }

    public function test_studio_akun_kosong_menutup_semua_rute(): void
    {
        config(['studio.akun' => '']);

        $this->studio('GET', '/api/studio/parameter-metode')
            ->assertForbidden()
            ->assertJsonPath('kode', 'studio_tertutup');
    }

    // ---------------------------------------------------------------- baca

    public function test_daftar_memuat_semua_parameter_registri_tanpa_penetapan(): void
    {
        $respons = $this->studio('GET', '/api/studio/parameter-metode')->assertOk();

        $data = collect($respons->json('data'));
        $this->assertCount(14, $data);

        $spektro = $data->firstWhere('kunci', self::KUNCI);
        $this->assertSame('semua_titik', $spektro['bawaan']);
        $this->assertSame('semua_titik', $spektro['aktif_sekarang']);
        $this->assertSame([], $spektro['penetapan']);
        $this->assertSame(['semua_titik', 'tanpa_titik_pertama'], array_column($spektro['pilihan'], 'kode'));
        $this->assertSame('hitung', $spektro['lingkup']);

        foreach (['kunci', 'alat', 'nama', 'keterangan', 'lingkup', 'butir_tm', 'dampak', 'pilihan', 'bawaan', 'aktif_sekarang', 'penetapan'] as $kolom) {
            $this->assertArrayHasKey($kolom, $spektro);
        }
    }

    public function test_daftar_menampilkan_semua_status_dan_jejak_tapi_hanya_disetujui_mengubah_aktif_sekarang(): void
    {
        $menunggu = $this->catat();

        $spektro = collect($this->studio('GET', '/api/studio/parameter-metode')->json('data'))->firstWhere('kunci', self::KUNCI);
        $this->assertCount(1, $spektro['penetapan']);
        $this->assertSame('menunggu_tm', $spektro['penetapan'][0]['status']);
        $this->assertSame($menunggu, $spektro['penetapan'][0]['id']);
        $this->assertSame('db', $spektro['penetapan'][0]['sumber']);
        $this->assertSame('semua_titik', $spektro['aktif_sekarang'], 'menunggu_tm belum memengaruhi angka');
        $this->assertCount(1, $spektro['penetapan'][0]['jejak']);
        $this->assertNull($spektro['penetapan'][0]['jejak'][0]['dari_status']);
        $this->assertSame('menunggu_tm', $spektro['penetapan'][0]['jejak'][0]['ke_status']);
        $this->assertSame('pemilik@sidik.test', $spektro['penetapan'][0]['jejak'][0]['oleh']);

        $this->setujui($menunggu)->assertOk();

        $spektro = collect($this->studio('GET', '/api/studio/parameter-metode')->json('data'))->firstWhere('kunci', self::KUNCI);
        $this->assertSame('disetujui', $spektro['penetapan'][0]['status']);
        $this->assertSame('tanpa_titik_pertama', $spektro['aktif_sekarang'], 'berlaku_mulai = hari ini, jadi sudah aktif');
        $this->assertCount(2, $spektro['penetapan'][0]['jejak']);
    }

    // ---------------------------------------------------------------- catat

    public function test_catat_201_status_menunggu_cap_waktu_server_dan_nomor_berurut(): void
    {
        $respons = $this->studio('POST', '/api/studio/parameter-metode/'.self::KUNCI.'/penetapan', $this->badanCatat([
            // Klien mencoba menitipkan cap waktu dan status sendiri: diabaikan.
            'dicatat_pada' => '2020-01-01T00:00:00+00:00',
            'status' => 'disetujui',
            'nomor' => 99,
        ]))->assertCreated();

        $respons->assertJsonPath('data.status', 'menunggu_tm')
            ->assertJsonPath('data.nomor', 1)
            ->assertJsonPath('data.nilai', 'tanpa_titik_pertama')
            ->assertJsonPath('data.berlaku_mulai', self::HARI_INI)
            ->assertJsonPath('data.keputusan_oleh', null)
            ->assertJsonPath('data.tanggal_keputusan', null)
            ->assertJsonPath('data.sumber', 'db');

        $baris = ParameterMetodePenetapan::query()->sole();
        $this->assertSame(self::HARI_INI.' 09:30:00', $baris->dicatat_pada->format('Y-m-d H:i:s'));
        $this->assertSame(self::HARI_INI, $baris->berlakuMulaiTeks());

        $kedua = $this->catat(['nilai' => 'semua_titik', 'berlaku_mulai' => '2026-12-15']);
        $this->assertSame(2, ParameterMetodePenetapan::query()->findOrFail($kedua)->nomor);
    }

    public function test_catat_tidak_memengaruhi_angka(): void
    {
        $this->catat();

        $this->assertSame('semua_titik', $this->berlaku(self::HARI_INI));
        $this->assertSame('semua_titik', $this->berlaku('2027-06-01'));
    }

    public function test_catat_kunci_tidak_dikenal_404(): void
    {
        $this->studio('POST', '/api/studio/parameter-metode/tidak.ada.kunci/penetapan', $this->badanCatat())->assertNotFound();
        $this->assertSame(0, ParameterMetodePenetapan::query()->count());
    }

    public function test_catat_nilai_bukan_pilihan_422_dengan_daftar_pilihan(): void
    {
        $this->studio('POST', '/api/studio/parameter-metode/'.self::KUNCI.'/penetapan', $this->badanCatat(['nilai' => 'ngawur']))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'nilai_bukan_pilihan')
            ->assertJsonPath('rincian.pilihan', ['semua_titik', 'tanpa_titik_pertama'])
            ->assertJsonStructure(['message', 'kode', 'errors' => ['nilai'], 'rincian']);

        $this->assertSame(0, ParameterMetodePenetapan::query()->count());
    }

    public function test_catat_berlaku_surut_422(): void
    {
        $this->studio('POST', '/api/studio/parameter-metode/'.self::KUNCI.'/penetapan', $this->badanCatat(['berlaku_mulai' => '2026-11-30']))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'berlaku_surut')
            ->assertJsonPath('rincian.tanggal_server', self::HARI_INI)
            ->assertJsonStructure(['errors' => ['berlaku_mulai']]);

        $this->assertSame(0, ParameterMetodePenetapan::query()->count());
    }

    public function test_catat_berlaku_mulai_tidak_boleh_turun_menurut_nomor(): void
    {
        $this->catat(['berlaku_mulai' => '2026-12-20']);

        $this->studio('POST', '/api/studio/parameter-metode/'.self::KUNCI.'/penetapan', $this->badanCatat(['nilai' => 'semua_titik', 'berlaku_mulai' => '2026-12-10']))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'berlaku_mulai_turun')
            ->assertJsonPath('rincian.nomor_sebelumnya', 1)
            ->assertJsonPath('rincian.berlaku_mulai_sebelumnya', '2026-12-20');

        // Sama persis boleh (tidak turun), dan parameter lain tidak ikut terkunci.
        $this->catat(['nilai' => 'semua_titik', 'berlaku_mulai' => '2026-12-20']);
        $this->catat(['nilai' => 'polinomial_suhu', 'berlaku_mulai' => self::HARI_INI], 'conductivity.nilai_acuan_larutan_1412');
        $this->assertSame(3, ParameterMetodePenetapan::query()->count());
    }

    public function test_penetapan_ditarik_tidak_menahan_penetapan_berikutnya(): void
    {
        $salahKetik = $this->catat(['berlaku_mulai' => '2030-01-01']);
        $this->tarik($salahKetik)->assertOk();

        // Tanggal yang benar lebih awal dari yang salah ketik: sah karena yang salah sudah ditarik.
        $kedua = $this->catat(['berlaku_mulai' => '2026-12-05']);
        $this->assertSame(2, ParameterMetodePenetapan::query()->findOrFail($kedua)->nomor);
    }

    public function test_catat_validasi_bentuk_422(): void
    {
        $url = '/api/studio/parameter-metode/'.self::KUNCI.'/penetapan';

        $this->studio('POST', $url, [])->assertStatus(422)->assertJsonValidationErrors(['nilai', 'berlaku_mulai']);
        $this->studio('POST', $url, $this->badanCatat(['berlaku_mulai' => '01-12-2026']))->assertStatus(422)->assertJsonValidationErrors(['berlaku_mulai']);
        $this->studio('POST', $url, $this->badanCatat(['berlaku_mulai' => '2026-02-30']))->assertStatus(422);
        $this->studio('POST', $url, $this->badanCatat(['catatan' => str_repeat('a', 2001)]))->assertStatus(422)->assertJsonValidationErrors(['catatan']);

        $this->assertSame(0, ParameterMetodePenetapan::query()->count());
    }

    public function test_catat_tanpa_catatan_boleh(): void
    {
        $this->studio('POST', '/api/studio/parameter-metode/'.self::KUNCI.'/penetapan', ['nilai' => 'tanpa_titik_pertama', 'berlaku_mulai' => self::HARI_INI])
            ->assertCreated()
            ->assertJsonPath('data.catatan', null);
    }

    // -------------------------------------------------------------- setujui

    public function test_setujui_200_mengisi_keputusan_dan_mencatat_jejak(): void
    {
        $id = $this->catat(['berlaku_mulai' => '2026-12-03']);

        $this->setujui($id)
            ->assertOk()
            ->assertJsonPath('data.status', 'disetujui')
            ->assertJsonPath('data.keputusan_oleh', 'TM Spektro (Bu Rina)')
            ->assertJsonPath('data.tanggal_keputusan', self::HARI_INI)
            ->assertJsonPath('data.jejak.1.dari_status', 'menunggu_tm')
            ->assertJsonPath('data.jejak.1.ke_status', 'disetujui')
            ->assertJsonPath('data.jejak.1.oleh', 'pemilik@sidik.test');

        $this->assertSame(2, ParameterMetodePenetapanJejak::query()->where('penetapan_id', $id)->count());
    }

    public function test_setujui_berlaku_mulai_masa_depan_sesi_lama_tetap_bawaan(): void
    {
        $id = $this->catat(['berlaku_mulai' => '2026-12-10']);
        $this->setujui($id)->assertOk();

        $this->assertSame('semua_titik', $this->berlaku('2026-12-09'), 'sesi sebelum berlaku_mulai tetap bawaan');
        $this->assertSame('semua_titik', $this->berlaku('2026-01-01'));
        $this->assertSame('tanpa_titik_pertama', $this->berlaku('2026-12-10'));
        $this->assertSame('tanpa_titik_pertama', $this->berlaku('2027-03-01'));
    }

    public function test_menunggu_tm_dan_ditarik_tidak_mengubah_angka_hanya_disetujui(): void
    {
        $menunggu = $this->catat();
        $this->assertSame('semua_titik', $this->berlaku('2027-01-01'), 'menunggu_tm');

        $this->setujui($menunggu)->assertOk();
        $this->assertSame('tanpa_titik_pertama', $this->berlaku('2027-01-01'), 'disetujui');

        $this->tarik($menunggu)->assertOk();
        $this->assertSame('semua_titik', $this->berlaku('2027-01-01'), 'ditarik kembali ke bawaan');

        $ditarikLangsung = $this->catat(['nilai' => 'tanpa_titik_pertama', 'berlaku_mulai' => '2026-12-02']);
        $this->tarik($ditarikLangsung)->assertOk();
        $this->assertSame('semua_titik', $this->berlaku('2027-01-01'), 'menunggu_tm yang langsung ditarik tidak pernah berlaku');
    }

    public function test_nomor_terbesar_menang_dan_penetapan_ditarik_jatuh_ke_sebelumnya(): void
    {
        $satu = $this->catat(['nilai' => 'tanpa_titik_pertama', 'berlaku_mulai' => '2026-12-01']);
        $dua = $this->catat(['nilai' => 'semua_titik', 'berlaku_mulai' => '2026-12-10']);
        $this->setujui($satu)->assertOk();
        $this->setujui($dua)->assertOk();

        $this->assertSame('tanpa_titik_pertama', $this->berlaku('2026-12-05'));
        $this->assertSame('semua_titik', $this->berlaku('2026-12-10'));

        $this->tarik($dua)->assertOk();
        $this->assertSame('tanpa_titik_pertama', $this->berlaku('2026-12-10'), 'nomor 2 ditarik, nomor 1 berlaku lagi');
    }

    public function test_setujui_dua_kali_422_status_tidak_sesuai(): void
    {
        $id = $this->catat();
        $this->setujui($id)->assertOk();

        $this->setujui($id)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'status_tidak_sesuai')
            ->assertJsonPath('rincian.status', 'disetujui');
    }

    public function test_setujui_penetapan_ditarik_422(): void
    {
        $id = $this->catat();
        $this->tarik($id)->assertOk();

        $this->setujui($id)->assertStatus(422)->assertJsonPath('kode', 'status_tidak_sesuai');
        $this->assertSame(ParameterMetodePenetapan::STATUS_DITARIK, ParameterMetodePenetapan::query()->findOrFail($id)->status);
    }

    public function test_setujui_yang_dibiarkan_menunggu_sampai_tanggalnya_lewat_ditolak_berlaku_surut(): void
    {
        $id = $this->catat(['berlaku_mulai' => '2026-12-03']);

        Carbon::setTestNow('2026-12-04 08:00:00');

        $this->setujui($id, ['tanggal_keputusan' => '2026-12-04'])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'berlaku_surut')
            ->assertJsonPath('rincian.berlaku_mulai', '2026-12-03')
            ->assertJsonPath('rincian.tanggal_server', '2026-12-04');

        $this->assertSame(ParameterMetodePenetapan::STATUS_MENUNGGU_TM, ParameterMetodePenetapan::query()->findOrFail($id)->status);
        $this->assertSame('semua_titik', $this->berlaku('2026-12-05'));
    }

    public function test_setujui_tanggal_keputusan_di_depan_422(): void
    {
        $id = $this->catat();

        $this->setujui($id, ['tanggal_keputusan' => '2026-12-02'])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'tanggal_keputusan_di_depan');
    }

    public function test_setujui_validasi_bentuk_dan_id_tak_dikenal(): void
    {
        $id = $this->catat();

        $this->studio('POST', "/api/studio/parameter-metode/penetapan/{$id}/setujui", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['keputusan_oleh', 'tanggal_keputusan']);
        $this->studio('POST', "/api/studio/parameter-metode/penetapan/{$id}/setujui", ['keputusan_oleh' => 'x', 'tanggal_keputusan' => self::HARI_INI])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['keputusan_oleh']);

        $this->setujui(987654)->assertNotFound();
        $this->studio('POST', '/api/studio/parameter-metode/penetapan/abc/setujui', [])->assertNotFound();
    }

    // ---------------------------------------------------------------- tarik

    public function test_tarik_dari_menunggu_tanpa_peringatan(): void
    {
        $id = $this->catat();

        $this->tarik($id)
            ->assertOk()
            ->assertJsonPath('data.status', 'ditarik')
            ->assertJsonPath('peringatan', null)
            ->assertJsonPath('data.jejak.1.dari_status', 'menunggu_tm')
            ->assertJsonPath('data.jejak.1.ke_status', 'ditarik')
            ->assertJsonPath('data.jejak.1.catatan', 'Salah ketik tanggal berlaku mulai.');
    }

    public function test_tarik_penetapan_disetujui_yang_sudah_berlaku_memberi_peringatan(): void
    {
        $id = $this->catat(['berlaku_mulai' => self::HARI_INI]);
        $this->setujui($id)->assertOk();

        $respons = $this->tarik($id)->assertOk()->assertJsonPath('data.status', 'ditarik');
        $this->assertStringContainsString('sudah berlaku sejak '.self::HARI_INI, (string) $respons->json('peringatan'));
        $this->assertStringContainsString('Hasil tersimpan tidak berubah', (string) $respons->json('peringatan'));
    }

    public function test_tarik_penetapan_disetujui_yang_belum_berlaku_tanpa_peringatan(): void
    {
        $id = $this->catat(['berlaku_mulai' => '2026-12-20']);
        $this->setujui($id)->assertOk();

        $this->tarik($id)->assertOk()->assertJsonPath('peringatan', null);
    }

    public function test_tarik_dua_kali_422_dan_alasan_terlalu_pendek_422_dan_id_tak_dikenal_404(): void
    {
        $id = $this->catat();

        $this->studio('POST', "/api/studio/parameter-metode/penetapan/{$id}/tarik", ['alasan' => 'pendek'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['alasan']);
        $this->studio('POST', "/api/studio/parameter-metode/penetapan/{$id}/tarik", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['alasan']);

        $this->tarik($id)->assertOk();
        $this->tarik($id)->assertStatus(422)->assertJsonPath('kode', 'status_tidak_sesuai');

        $this->tarik(987654)->assertNotFound();
        $this->assertSame(2, ParameterMetodePenetapanJejak::query()->where('penetapan_id', $id)->count());
    }

    public function test_id_penetapan_terlalu_panjang_404_bukan_500(): void
    {
        $panjang = str_repeat('9', 30);

        $this->studio('POST', "/api/studio/parameter-metode/penetapan/{$panjang}/setujui", [
            'keputusan_oleh' => 'TM Spektro (Bu Rina)',
            'tanggal_keputusan' => self::HARI_INI,
        ])->assertNotFound();
        $this->studio('POST', "/api/studio/parameter-metode/penetapan/{$panjang}/tarik", [
            'alasan' => 'Salah ketik tanggal berlaku mulai.',
        ])->assertNotFound();
    }

    // ------------------------------------------- cache dan pencatatan serentak

    public function test_persetujuan_dan_penarikan_langsung_terlihat_oleh_registri_yang_sudah_menyimpan_cache(): void
    {
        $registri = app(ParameterKeputusanMetode::class);
        $this->assertSame('semua_titik', $registri->nilai(self::KUNCI, '2026-12-05'), 'cache penetapan DB terisi (kosong)');

        $id = $this->catat(['berlaku_mulai' => '2026-12-03']);
        $this->assertSame($registri, app(ParameterKeputusanMetode::class), 'request tidak mengganti instance registri');
        $this->assertSame('semua_titik', $registri->nilai(self::KUNCI, '2026-12-05'), 'menunggu_tm tidak mengubah angka');

        // Tanpa lupakan(): hanya hook `saved` model yang boleh membuang cache.
        $this->setujui($id)->assertOk();
        $this->assertSame('tanpa_titik_pertama', $registri->nilai(self::KUNCI, '2026-12-05'), 'disetujui langsung terlihat');
        $this->assertSame('semua_titik', $registri->nilai(self::KUNCI, '2026-12-02'), 'sesi sebelum berlaku_mulai tetap bawaan');

        $this->tarik($id)->assertOk();
        $this->assertSame('semua_titik', $registri->nilai(self::KUNCI, '2026-12-05'), 'ditarik langsung kembali ke bawaan');
    }

    /** Jalankan `$aksi` sekali, tepat sebelum baris penetapan pertama disimpan (mensimulasikan penulis lain / kegagalan DB). */
    private function sebelumSimpanBarisPertama(Closure $aksi): void
    {
        $sudah = false;

        ParameterMetodePenetapan::creating(function () use (&$sudah, $aksi): void {
            if ($sudah) {
                return;
            }

            $sudah = true;
            $aksi();
        });
    }

    public function test_catat_dengan_nomor_bentrok_dijawab_409_konflik_nomor_lalu_bisa_diulang(): void
    {
        // Penulis lain menyelip dengan nomor yang sama tepat sebelum baris kita disimpan.
        $this->sebelumSimpanBarisPertama(function (): void {
            DB::table('parameter_metode_penetapan')->insert([
                'kunci' => self::KUNCI,
                'nomor' => 1,
                'nilai' => 'semua_titik',
                'berlaku_mulai' => self::HARI_INI,
                'dicatat_pada' => now(),
                'status' => ParameterMetodePenetapan::STATUS_MENUNGGU_TM,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->studio('POST', '/api/studio/parameter-metode/'.self::KUNCI.'/penetapan', $this->badanCatat())
            ->assertStatus(409)
            ->assertJsonPath('kode', 'konflik_nomor');

        $this->assertSame(0, ParameterMetodePenetapan::query()->count(), 'transaksi yang kalah tidak menyimpan apa pun');
        $this->assertSame(0, ParameterMetodePenetapanJejak::query()->count());

        $id = $this->catat();
        $this->assertSame(1, ParameterMetodePenetapan::query()->findOrFail($id)->nomor);
    }

    public function test_catat_yang_kalah_deadlock_database_dijawab_409_konflik_nomor(): void
    {
        // Parameter tanpa baris: dua pencatatan serentak bisa saling mengunci rentang kosong (MySQL 1213 / 40001).
        $this->sebelumSimpanBarisPertama(function (): void {
            throw new QueryException(
                'mysql',
                'insert into `parameter_metode_penetapan` (`kunci`, `nomor`) values (?, ?)',
                [self::KUNCI, 1],
                new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 40001),
            );
        });

        $this->studio('POST', '/api/studio/parameter-metode/'.self::KUNCI.'/penetapan', $this->badanCatat())
            ->assertStatus(409)
            ->assertJsonPath('kode', 'konflik_nomor');

        $this->assertSame(0, ParameterMetodePenetapan::query()->count());

        $this->catat();
        $this->assertSame(1, ParameterMetodePenetapan::query()->count());
    }

    public function test_catat_kegagalan_database_lain_tidak_disamarkan_jadi_409(): void
    {
        $this->sebelumSimpanBarisPertama(function (): void {
            throw new RuntimeException('disk penuh');
        });

        try {
            app(PenetapanParameterMetode::class)->catat($this->pemilik, self::KUNCI, 'tanpa_titik_pertama', self::HARI_INI);
            $this->fail('Kegagalan non-konkurensi harus naik apa adanya.');
        } catch (RuntimeException $e) {
            $this->assertSame('disk penuh', $e->getMessage());
        }

        $this->assertSame(0, ParameterMetodePenetapan::query()->count());
    }

    // ---------------------------------------------------------- append-only

    public function test_isi_baris_append_only_hanya_status_dan_keputusan_boleh_berubah(): void
    {
        $id = $this->catat();
        $baris = ParameterMetodePenetapan::query()->findOrFail($id);

        foreach (['kunci' => 'conductivity.nilai_acuan_larutan_1412', 'nomor' => 9, 'nilai' => 'semua_titik', 'berlaku_mulai' => '2027-01-01', 'catatan' => 'diubah'] as $kolom => $nilai) {
            $baru = ParameterMetodePenetapan::query()->findOrFail($id);
            $baru->{$kolom} = $nilai;

            try {
                $baru->save();
                $this->fail("Kolom {$kolom} seharusnya tidak bisa diubah.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $baris->refresh();
        $this->assertSame('tanpa_titik_pertama', $baris->nilai);
        $this->assertSame(1, $baris->nomor);
        $this->assertSame(self::HARI_INI, $baris->berlakuMulaiTeks());
    }

    public function test_baris_dan_jejak_tidak_bisa_dihapus_atau_diubah(): void
    {
        $id = $this->catat();
        $this->setujui($id)->assertOk();

        try {
            ParameterMetodePenetapan::query()->findOrFail($id)->delete();
            $this->fail('Baris penetapan seharusnya tidak bisa dihapus.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $jejak = ParameterMetodePenetapanJejak::query()->where('penetapan_id', $id)->firstOrFail();

        try {
            $jejak->update(['catatan' => 'ubah']);
            $this->fail('Jejak seharusnya tulis-sekali.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $jejak->delete();
            $this->fail('Jejak seharusnya tidak bisa dihapus.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, ParameterMetodePenetapan::query()->count());
        $this->assertSame(2, ParameterMetodePenetapanJejak::query()->count());
    }

    public function test_tidak_ada_rute_ubah_atau_hapus_penetapan(): void
    {
        $id = $this->catat();

        foreach (['PUT', 'PATCH', 'DELETE'] as $metode) {
            $this->studio($metode, "/api/studio/parameter-metode/penetapan/{$id}")->assertStatus(404);
            $this->studio($metode, '/api/studio/parameter-metode/'.self::KUNCI.'/penetapan')->assertStatus(405);
        }
    }
}
