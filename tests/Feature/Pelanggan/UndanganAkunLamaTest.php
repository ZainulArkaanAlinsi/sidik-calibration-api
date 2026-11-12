<?php

namespace Tests\Feature\Pelanggan;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Organization;
use App\Models\OtpPelanggan;
use App\Models\UndanganPelanggan;
use App\Models\User;
use App\Notifications\Pelanggan\UndanganDitukarDiAkunAnda;
use App\Services\Pelanggan\KodeOtp;
use App\Services\Pelanggan\KodeUndangan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Penukaran undangan di email yang SUDAH punya akun pelanggan (12 Nov 2026).
 *
 * Dulu `terima-undangan` untuk akun lama hanya mencocokkan kode lalu
 * menerbitkan token — tanpa sandi. Admin lab melihat kode polos undangannya
 * (`POST /api/customers/{c}/undangan`), jadi admin lab bisa mengundang email
 * pelanggan mana pun, menukar kodenya sendiri, dan memegang akun itu.
 * Regresi pertama di bawah adalah bukti pengambilalihan itu, dibalik jadi
 * asersi penolakan.
 *
 * Yang dijaga, per butir keputusan pemilik:
 * - (A) akun lama wajib sandi benar; salah = 422 `sandi_akun_lama_salah`,
 *       dibatasi satu ember dengan `masuk`, dan dicatat;
 * - (E) akun di organisasi lain ditolak;
 * - (F) status nonaktif / dianonimkan / pending_email ditolak seperti `masuk`;
 * - (D) penukaran sah dicatat audit dan pemilik akun dikabari;
 * - pihak TANPA kode sah tidak bisa membedakan "akun ada" dari "tidak ada",
 *   dan tidak bisa mengunci pemilik akun;
 * - akun BARU tidak berubah sama sekali.
 */
class UndanganAkunLamaTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const SANDI_TEBAKAN = 'SandiSembarang#2026Xy';

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
        Mail::fake();
    }

    /**
     * Admin lab mengundang `$email` ke `$perusahaan` dan membaca kode polos dari
     * balasan — persis yang bisa dilakukan penyerang di skenario temuan.
     *
     * @return array{customer: Customer, kode: string, admin: User}
     */
    private function undang(string $email, ?Customer $perusahaan = null): array
    {
        $perusahaan ??= $this->perusahaan();
        $admin = $this->adminLab();

        $respons = $this->withHeaders($this->bearerInternal($admin))
            ->postJson("/api/customers/{$perusahaan->id}/undangan", ['email' => $email, 'peran' => CustomerMember::PERAN_STAF])
            ->assertCreated();

        $this->permintaanBaru();

        $kode = (string) $respons->json('data.kode');
        $this->assertNotSame('', $kode);

        return ['customer' => $perusahaan, 'kode' => $kode, 'admin' => $admin];
    }

    /** @return array<string, mixed> */
    private function formulir(string $email, string $kode, string $sandi, array $ubah = []): array
    {
        return [
            'email' => $email,
            'kode' => $kode,
            'nama' => 'Orang Lab',
            'sandi' => $sandi,
            'telepon' => '0812-3456-7890',
            'setuju_syarat' => true,
            ...$ubah,
        ];
    }

    private function tukar(string $email, string $kode, string $sandi, array $ubah = [])
    {
        $this->permintaanBaru();

        return $this->postJson('/api/pelanggan/v1/auth/terima-undangan', $this->formulir($email, $kode, $sandi, $ubah));
    }

    /** Organisasi lab KEDUA + akun pelanggan di dalamnya. */
    private function pelangganDiLabLain(): User
    {
        $labLain = Organization::factory()->create();

        return $this->pelanggan(tambahan: ['organization_id' => $labLain->id]);
    }

    /** Tidak ada satu pun efek samping penukaran di akun ini. */
    private function assertTidakAdaEfekPenukaran(User $akun, Customer $perusahaan, ?int $tokenSebelum = null): void
    {
        $this->assertSame($tokenSebelum ?? 0, $akun->tokens()->count(), 'Token pelanggan tidak boleh terbit.');
        $this->assertDatabaseMissing('customer_members', ['customer_id' => $perusahaan->id, 'user_id' => $akun->id]);
        $this->assertNull(UndanganPelanggan::query()->where('customer_id', $perusahaan->id)->value('dipakai_pada'), 'Undangan tidak boleh terpakai.');
        $this->assertSame(0, AuditLog::query()->where('action', AuditLog::ACTION_UNDANGAN_DITUKAR)->count());
        $this->assertSame(0, $akun->notifications()->count(), 'Pemilik akun tidak dikabari penukaran yang tidak terjadi.');
    }

    // ------------------------------------------------ regresi temuan (admin lab)

    /** Dasar: `BuktiAmbilAlihTest` — sebelum perbaikan ini balasannya 201 + token milik korban. */
    public function test_regresi_admin_lab_tidak_bisa_mengambil_alih_akun_pelanggan_lama(): void
    {
        $korban = $this->anggota();      // PIC utama di perusahaannya sendiri
        $ptLain = $this->perusahaan();   // korban BUKAN anggota di sini

        ['kode' => $kode] = $this->undang((string) $korban->email, $ptLain);

        $balasan = $this->tukar((string) $korban->email, $kode, self::SANDI_TEBAKAN)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'sandi_akun_lama_salah');

        $this->assertArrayNotHasKey('data', $balasan->json(), 'Tidak boleh ada token/akun di balasan penolakan.');
        $this->assertStringNotContainsString('token', (string) $balasan->getContent());
        $this->assertTidakAdaEfekPenukaran($korban, $ptLain);
        $this->assertTrue(Hash::check($this->sandiBenar, (string) $korban->fresh()->password), 'Sandi korban tidak boleh berubah.');

        // Tanpa token dari penukaran itu, aplikasi tetap tertutup.
        $this->permintaanBaru();
        $this->getJson('/api/pelanggan/v1/saya')->assertUnauthorized();
    }

    // ------------------------------------------------ sukses (A) + (D)

    public function test_akun_lama_dengan_sandi_benar_dapat_201_audit_dan_notifikasi(): void
    {
        $konsultan = $this->anggota();
        $hashLama = (string) $konsultan->fresh()->password;
        $pertama = $konsultan->keanggotaan()->firstOrFail()->customer;

        ['customer' => $kedua, 'kode' => $kode, 'admin' => $admin] = $this->undang((string) $konsultan->email);

        $badan = $this->tukar((string) $konsultan->email, $kode, $this->sandiBenar, ['nama' => 'Nama Diabaikan'])
            ->assertCreated()
            ->assertJsonPath('data.akun_sudah_ada', true)
            ->assertJsonPath('data.user.email', $konsultan->email)
            ->json();

        $this->assertNotEmpty($badan['data']['token']);

        // Akun yang sama, tidak digandakan; sandi dan nama tidak ditimpa.
        $this->assertSame(1, User::query()->where('email', $konsultan->email)->count());
        $this->assertSame($hashLama, (string) $konsultan->fresh()->password);
        $this->assertSame($konsultan->name, $konsultan->fresh()->name);

        foreach ([$pertama->id, $kedua->id] as $customerId) {
            $this->assertDatabaseHas('customer_members', [
                'customer_id' => $customerId,
                'user_id' => $konsultan->id,
                'status' => CustomerMember::STATUS_AKTIF,
            ]);
        }
        $this->assertDatabaseHas('undangan_pelanggan', ['customer_id' => $kedua->id, 'dipakai_oleh' => $konsultan->id]);

        // Tokennya sungguhan.
        $this->permintaanBaru();
        $this->withHeaders(['Authorization' => 'Bearer '.$badan['data']['token']])
            ->getJson('/api/pelanggan/v1/saya')->assertOk()
            ->assertJsonPath('data.email', $konsultan->email);

        // (D) audit eksplisit.
        $baris = AuditLog::query()->where('action', AuditLog::ACTION_UNDANGAN_DITUKAR)->get();
        $this->assertCount(1, $baris);
        $audit = $baris->first();
        $this->assertSame('users', $audit->entity_type);
        $this->assertSame((int) $konsultan->id, (int) $audit->entity_id);
        $this->assertSame((int) $konsultan->organization_id, (int) $audit->organization_id);
        $this->assertSame((int) $konsultan->id, (int) $audit->changed_by);
        $this->assertSame(['jumlah_perusahaan_aktif' => 1], $audit->old_data);
        $this->assertSame(2, $audit->new_data['jumlah_perusahaan_aktif']);
        $this->assertSame((int) $kedua->id, (int) $audit->new_data['customer_id']);
        $this->assertSame((int) $admin->id, (int) $audit->new_data['diundang_oleh']);
        $this->assertSame(CustomerMember::PERAN_STAF, $audit->new_data['peran']);

        // Baris audit tidak boleh memuat sandi, kode, atau email.
        $json = json_encode([$audit->old_data, $audit->new_data, $audit->note], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($this->sandiBenar, $json);
        $this->assertStringNotContainsString($kode, $json);
        $this->assertStringNotContainsString((string) $konsultan->email, $json);

        // (D) pemilik akun dikabari, netral.
        $notif = $konsultan->notifications()->where('type', UndanganDitukarDiAkunAnda::class)->get();
        $this->assertCount(1, $notif);
        $data = $notif->first()->data;
        $this->assertSame('pelanggan.akun_ditautkan', $data['kategori']);
        $isi = $data['title'].' '.$data['body'];
        $this->assertStringNotContainsString((string) $kedua->nama, $isi, 'Isi notifikasi tampil di layar kunci: tanpa nama perusahaan.');
        $this->assertStringNotContainsString((string) $konsultan->email, $isi);

        // Penolakan tidak tercatat untuk penukaran yang sah.
        $this->assertSame(0, AuditLog::query()->where('action', AuditLog::ACTION_UNDANGAN_DITOLAK)->count());
    }

    // ------------------------------------------------ akun BARU tidak berubah

    public function test_akun_baru_tidak_berubah_tanpa_audit_penukaran_dan_tanpa_notifikasi(): void
    {
        ['customer' => $perusahaan, 'kode' => $kode] = $this->undang('budi@contoh.test');

        $this->tukar('budi@contoh.test', $kode, $this->sandiBenar, ['nama' => 'Budi Diundang'])
            ->assertCreated()
            ->assertJsonPath('data.akun_sudah_ada', false)
            ->assertJsonPath('data.user.status', User::STATUS_AKTIF)
            ->assertJsonPath('data.user.nama', 'Budi Diundang');

        $user = User::query()->where('email', 'budi@contoh.test')->firstOrFail();
        $this->assertTrue(Hash::check($this->sandiBenar, (string) $user->password), 'Sandi akun baru = sandi di formulir.');
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('customer_members', ['customer_id' => $perusahaan->id, 'user_id' => $user->id]);

        $this->assertSame(0, AuditLog::query()->whereIn('action', [AuditLog::ACTION_UNDANGAN_DITUKAR, AuditLog::ACTION_UNDANGAN_DITOLAK])->count());
        $this->assertSame(0, $user->notifications()->count());
    }

    /** Kunci gagal-masuk sebuah email TIDAK boleh menahan penukaran untuk akun yang belum ada. */
    public function test_kunci_masuk_tidak_menahan_penukaran_untuk_akun_baru(): void
    {
        ['kode' => $kode] = $this->undang('baru@contoh.test');

        for ($i = 0; $i < 5; $i++) {
            $this->permintaanBaru();
            $this->postJson('/api/pelanggan/v1/auth/masuk', ['email' => 'baru@contoh.test', 'sandi' => 'sandi-salah-banget'])
                ->assertStatus(401);
        }

        $this->tukar('baru@contoh.test', $kode, $this->sandiBenar)
            ->assertCreated()
            ->assertJsonPath('data.akun_sudah_ada', false);
    }

    // ------------------------------------------------ (A) sandi salah: dibatasi + dicatat

    public function test_sandi_salah_dibatasi_lima_kali_satu_ember_dengan_masuk_dan_dicatat(): void
    {
        $korban = $this->anggota();
        ['customer' => $perusahaan, 'kode' => $kode] = $this->undang((string) $korban->email);

        for ($i = 1; $i <= 5; $i++) {
            $this->tukar((string) $korban->email, $kode, self::SANDI_TEBAKAN."{$i}")
                ->assertStatus(422)
                ->assertJsonPath('kode', 'sandi_akun_lama_salah');
        }

        // Dicatat: satu baris per percobaan, tanpa pelaku, tanpa sandi/kode.
        $ditolak = AuditLog::query()->where('action', AuditLog::ACTION_UNDANGAN_DITOLAK)->orderBy('id')->get();
        $this->assertCount(5, $ditolak);
        $this->assertSame([1, 2, 3, 4, 5], $ditolak->map(fn (AuditLog $a) => $a->new_data['percobaan_ke'])->all());

        foreach ($ditolak as $a) {
            $this->assertSame('sandi_salah', $a->new_data['alasan']);
            $this->assertNull($a->changed_by, 'Pelaku penolakan tidak terbukti siapa pun.');
            $this->assertSame((int) $korban->id, (int) $a->entity_id);
            $json = json_encode([$a->new_data, $a->note], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('SandiSembarang', $json);
            $this->assertStringNotContainsString($kode, $json);
        }

        // Keenam: sandi BENAR pun ditolak selama terkunci — pesan sandi, bukan pesan ember kode.
        $terkunci = $this->tukar((string) $korban->email, $kode, $this->sandiBenar)
            ->assertStatus(429)
            ->assertJsonPath('kode', 'terlalu_sering');
        $this->assertStringContainsString('sandi', (string) $terkunci->json('message'));
        $this->assertTidakAdaEfekPenukaran($korban, $perusahaan);

        // Satu ember dengan `masuk`: login yang benar pun tertahan.
        $this->permintaanBaru();
        $this->postJson('/api/pelanggan/v1/auth/masuk', ['email' => $korban->email, 'sandi' => $this->sandiBenar])
            ->assertStatus(429)
            ->assertJsonPath('kode', 'terlalu_sering');

        // Sesudah kunci lewat, sandi benar diterima.
        $this->travel(16)->minutes();

        $this->tukar((string) $korban->email, $kode, $this->sandiBenar)
            ->assertCreated()
            ->assertJsonPath('data.akun_sudah_ada', true);
    }

    /** Kegagalan lewat `masuk` ikut menghabiskan jatah yang sama (tidak ada jatah ganda). */
    public function test_kegagalan_masuk_dan_penukaran_berbagi_satu_jatah(): void
    {
        $korban = $this->anggota();
        ['kode' => $kode] = $this->undang((string) $korban->email);

        for ($i = 0; $i < 3; $i++) {
            $this->permintaanBaru();
            $this->postJson('/api/pelanggan/v1/auth/masuk', ['email' => $korban->email, 'sandi' => 'salah-lewat-masuk'])
                ->assertStatus(401);
        }

        for ($i = 0; $i < 2; $i++) {
            $this->tukar((string) $korban->email, $kode, self::SANDI_TEBAKAN)->assertStatus(422)
                ->assertJsonPath('kode', 'sandi_akun_lama_salah');
        }

        // Lima gagal gabungan -> yang keenam terkunci walau sandinya benar.
        $this->tukar((string) $korban->email, $kode, $this->sandiBenar)
            ->assertStatus(429)
            ->assertJsonPath('kode', 'terlalu_sering');
    }

    public function test_penukaran_berhasil_mengosongkan_hitungan_gagal(): void
    {
        $korban = $this->anggota();
        ['kode' => $kode] = $this->undang((string) $korban->email);

        for ($i = 0; $i < 4; $i++) {
            $this->tukar((string) $korban->email, $kode, self::SANDI_TEBAKAN)->assertStatus(422);
        }

        $this->tukar((string) $korban->email, $kode, $this->sandiBenar)->assertCreated();

        // Hitungan kembali nol: empat salah lagi di `masuk` tidak mengunci.
        for ($i = 0; $i < 4; $i++) {
            $this->permintaanBaru();
            $this->postJson('/api/pelanggan/v1/auth/masuk', ['email' => $korban->email, 'sandi' => 'salah-lagi'])->assertStatus(401);
        }
        $this->permintaanBaru();
        $this->postJson('/api/pelanggan/v1/auth/masuk', ['email' => $korban->email, 'sandi' => $this->sandiBenar])->assertOk();
    }

    // ------------------------------------------------ (E) organisasi

    public function test_akun_di_organisasi_lain_ditolak_setelah_sandi_terbukti(): void
    {
        $orang = $this->pelangganDiLabLain();
        $perusahaan = $this->perusahaan();           // milik lab pertama
        ['kode' => $kode] = $this->undang((string) $orang->email, $perusahaan);

        $this->tukar((string) $orang->email, $kode, $this->sandiBenar)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'undangan_organisasi_berbeda');

        $this->assertTidakAdaEfekPenukaran($orang, $perusahaan);

        $audit = AuditLog::query()->where('action', AuditLog::ACTION_UNDANGAN_DITOLAK)->sole();
        $this->assertSame('organisasi_beda', $audit->new_data['alasan']);
        $this->assertSame((int) $perusahaan->organization_id, (int) $audit->organization_id);
    }

    /** Organisasi akun TIDAK boleh terbaca dari penebak sandi: sandi salah tetap pesan sandi. */
    public function test_sandi_salah_di_akun_organisasi_lain_tidak_membocorkan_organisasinya(): void
    {
        $orang = $this->pelangganDiLabLain();
        ['kode' => $kode] = $this->undang((string) $orang->email, $this->perusahaan());

        $this->tukar((string) $orang->email, $kode, self::SANDI_TEBAKAN)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'sandi_akun_lama_salah');
    }

    // ------------------------------------------------ (F) status

    /** @return array<string, array{0: string, 1: int, 2: array<string, mixed>}> */
    public static function statusDitolak(): array
    {
        return [
            'nonaktif' => ['akun_nonaktif', 403, ['status' => User::STATUS_NONAKTIF]],
            'dianonimkan' => ['akun_nonaktif', 403, ['dianonimkan_pada' => '2026-11-01 08:00:00']],
            'pending_email' => ['email_belum_diverifikasi', 403, ['status' => User::STATUS_PENDING_EMAIL]],
        ];
    }

    /**
     * @param  array<string, mixed>  $keadaan
     */
    #[DataProvider('statusDitolak')]
    public function test_status_akun_tidak_aktif_ditolak_seperti_masuk(string $kodeDiharapkan, int $http, array $keadaan): void
    {
        $orang = $this->pelanggan();
        $orang->forceFill($keadaan)->save();
        $perusahaan = $this->perusahaan();
        ['kode' => $kode] = $this->undang((string) $orang->email, $perusahaan);

        // `masuk` memberi kode & status yang sama untuk akun yang sama — dua pintu tidak boleh berbeda.
        $this->permintaanBaru();
        $this->postJson('/api/pelanggan/v1/auth/masuk', ['email' => $orang->email, 'sandi' => $this->sandiBenar])
            ->assertStatus($http)
            ->assertJsonPath('kode', $kodeDiharapkan);

        $this->tukar((string) $orang->email, $kode, $this->sandiBenar)
            ->assertStatus($http)
            ->assertJsonPath('kode', $kodeDiharapkan);

        $this->assertTidakAdaEfekPenukaran($orang, $perusahaan);

        $audit = AuditLog::query()->where('action', AuditLog::ACTION_UNDANGAN_DITOLAK)->sole();
        $this->assertSame($kodeDiharapkan, $audit->new_data['alasan']);
    }

    /** Status akun juga tidak terbaca tanpa sandi: sandi salah tetap pesan sandi. */
    public function test_sandi_salah_di_akun_nonaktif_tidak_membocorkan_statusnya(): void
    {
        $orang = $this->pelanggan(User::STATUS_NONAKTIF);
        ['kode' => $kode] = $this->undang((string) $orang->email);

        $this->tukar((string) $orang->email, $kode, self::SANDI_TEBAKAN)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'sandi_akun_lama_salah');
    }

    /** Akun internal: jawaban lama tidak berubah, dan tidak ada pemeriksaan sandi yang terbuka. */
    public function test_akun_internal_tetap_undangan_akun_internal_tanpa_menyentuh_ember_sandi(): void
    {
        $teknisi = $this->adminLab(User::ROLE_TEKNISI);
        ['kode' => $kode] = $this->undang((string) $teknisi->email);

        for ($i = 0; $i < 6; $i++) {
            $this->tukar((string) $teknisi->email, $kode, self::SANDI_TEBAKAN)
                ->assertStatus(422)
                ->assertJsonPath('kode', 'undangan_akun_internal');
        }

        $this->assertSame(0, AuditLog::query()->where('action', AuditLog::ACTION_UNDANGAN_DITOLAK)->count());
    }

    // ------------------------------------------------ pihak TANPA kode sah

    /**
     * Penebak tanpa kode sah: jawaban untuk email yang punya akun dan yang tidak
     * punya HARUS identik (isi dan status), apa pun sandi yang dikirim — dan
     * tidak menyentuh ember sandi maupun audit.
     */
    public function test_tanpa_kode_sah_akun_ada_dan_tidak_ada_tidak_bisa_dibedakan(): void
    {
        $punyaAkun = $this->anggota();
        $this->undang('undangan-lain@contoh.test');   // ada undangan hidup, tapi untuk email lain

        $bandingkan = function (string $skenario, string $kode, string $sandi) use ($punyaAkun): void {
            $ada = $this->tukar((string) $punyaAkun->email, $kode, $sandi)->assertStatus(422);
            $tidak = $this->tukar('tidak-ada@contoh.test', $kode, $sandi)->assertStatus(422);

            $this->assertSame($tidak->json(), $ada->json(), "Jawaban berbeda: {$skenario}");
            $this->assertSame('undangan_tidak_berlaku', $ada->json('kode'));
            $this->assertArrayNotHasKey('data', $ada->json());
        };

        $bandingkan('kode ngawur, sandi benar', 'ZZZZZZZZ', $this->sandiBenar);
        $bandingkan('kode ngawur, sandi salah', 'ZZZZZZZZ', self::SANDI_TEBAKAN);

        $this->assertSame(0, AuditLog::query()->whereIn('action', [AuditLog::ACTION_UNDANGAN_DITUKAR, AuditLog::ACTION_UNDANGAN_DITOLAK])->count());
        $this->assertSame(0, $punyaAkun->notifications()->count());
    }

    /** Orang tanpa kode tidak bisa mengunci login pemilik akun lewat pintu undangan. */
    public function test_kode_salah_tidak_menghitung_ke_ember_sandi_dan_tidak_mengunci_pemilik(): void
    {
        $korban = $this->anggota();

        // Throttle rute (10/15 menit per email) tetap berlaku; 8 percobaan masih di bawahnya.
        for ($i = 0; $i < 8; $i++) {
            $this->tukar((string) $korban->email, 'ZZZZZZZZ', self::SANDI_TEBAKAN)
                ->assertStatus(422)
                ->assertJsonPath('kode', 'undangan_tidak_berlaku');
        }

        $this->permintaanBaru();
        $this->postJson('/api/pelanggan/v1/auth/masuk', ['email' => $korban->email, 'sandi' => $this->sandiBenar])
            ->assertOk();

        $this->assertSame(0, AuditLog::query()->where('action', AuditLog::ACTION_UNDANGAN_DITOLAK)->count());
    }

    // ------------------------------------------------ klaim atomik (dua request bersamaan)

    /**
     * Sebuah `KodeUndangan` yang `tukar()`-nya selalu memulangkan FOTO undangan
     * yang diambil sebelum pemenang balapan mengklaimnya — persis yang dilihat
     * request kedua yang sampai di `tukar()` pada saat yang sama dengan request
     * pertama. Test HTTP biasa tidak bisa memalsukan jendela itu: begitu
     * request pertama selesai, `tukar()` asli sudah menolak.
     */
    private function pasangPembacaanBasi(UndanganPelanggan $foto): void
    {
        $this->app->instance(KodeUndangan::class, new class($foto) extends KodeUndangan
        {
            public function __construct(private readonly UndanganPelanggan $foto) {}

            public function tukar(string $email, string $kode): ?UndanganPelanggan
            {
                return $this->foto;
            }
        });
    }

    private function fotoUndangan(Customer $perusahaan): UndanganPelanggan
    {
        return UndanganPelanggan::query()->with('customer')->where('customer_id', $perusahaan->id)->firstOrFail();
    }

    /** Akun lama: klaim kedua kalah -> satu token, satu audit penukaran, satu keanggotaan, satu notifikasi. */
    public function test_klaim_kedua_pada_kode_yang_sama_ditolak_walau_lolos_pembacaan(): void
    {
        $korban = $this->anggota();
        ['customer' => $perusahaan, 'kode' => $kode] = $this->undang((string) $korban->email);
        $foto = $this->fotoUndangan($perusahaan);

        $this->tukar((string) $korban->email, $kode, $this->sandiBenar)->assertCreated();

        $this->pasangPembacaanBasi($foto);

        $kedua = $this->tukar((string) $korban->email, $kode, $this->sandiBenar)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'undangan_tidak_berlaku');

        $this->assertArrayNotHasKey('data', $kedua->json());
        $this->assertSame(1, $korban->tokens()->count(), 'Penukaran kedua tidak boleh menerbitkan token.');
        $this->assertSame(1, AuditLog::query()->where('action', AuditLog::ACTION_UNDANGAN_DITUKAR)->count());
        $this->assertSame(1, CustomerMember::query()->where('customer_id', $perusahaan->id)->where('user_id', $korban->id)->count());
        $this->assertSame(1, $korban->notifications()->where('type', UndanganDitukarDiAkunAnda::class)->count());
    }

    /** Akun baru: yang kalah balapan tidak meninggalkan akun yatim. */
    public function test_klaim_kalah_untuk_akun_baru_tidak_membuat_akun(): void
    {
        ['customer' => $perusahaan, 'kode' => $kode] = $this->undang('balapan@contoh.test');
        $foto = $this->fotoUndangan($perusahaan);

        // Pemenang balapan sudah mengklaim sebelum request ini sampai ke klaimnya.
        UndanganPelanggan::query()->whereKey($foto->id)->update(['dipakai_pada' => now()]);
        $this->pasangPembacaanBasi($foto);

        $this->tukar('balapan@contoh.test', $kode, $this->sandiBenar)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'undangan_tidak_berlaku');

        $this->assertDatabaseMissing('users', ['email' => 'balapan@contoh.test']);
        $this->assertDatabaseCount('customer_members', 0);
    }

    /** Sandi salah TIDAK boleh menghanguskan kode: pemiliknya masih bisa mencoba lagi. */
    public function test_sandi_salah_tidak_menghanguskan_kode(): void
    {
        $korban = $this->anggota();
        ['customer' => $perusahaan, 'kode' => $kode] = $this->undang((string) $korban->email);

        $this->tukar((string) $korban->email, $kode, self::SANDI_TEBAKAN)->assertStatus(422);

        $this->assertNull($this->fotoUndangan($perusahaan)->dipakai_pada);

        $this->tukar((string) $korban->email, $kode, $this->sandiBenar)->assertCreated();
    }

    // ------------------------------------------------ rollback total

    /**
     * `batas_anggota` dilempar SESUDAH sandi benar dan SESUDAH klaim: rollback
     * harus membatalkan semuanya — klaim (kode hidup lagi), audit penukaran,
     * keanggotaan, notifikasi — dan tidak ada token yang terbit.
     */
    public function test_batas_anggota_setelah_sandi_benar_membatalkan_semuanya(): void
    {
        $korban = $this->anggota();
        $perusahaan = $this->perusahaan();
        $perusahaan->forceFill(['maks_anggota' => 1])->save();

        // Undangan dibuat selagi masih muat (pembuatannya memeriksa batas)...
        ['kode' => $kode] = $this->undang((string) $korban->email, $perusahaan);

        // ...lalu kursinya diisi orang lain sebelum kodenya ditukar.
        $lain = $this->pelanggan();
        CustomerMember::create([
            'organization_id' => $perusahaan->organization_id,
            'customer_id' => $perusahaan->id,
            'user_id' => $lain->id,
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $this->tukar((string) $korban->email, $kode, $this->sandiBenar)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'batas_anggota');

        $this->assertTidakAdaEfekPenukaran($korban, $perusahaan);
        $this->assertSame(0, AuditLog::query()->where('action', AuditLog::ACTION_UNDANGAN_DITOLAK)->count());

        // Klaimnya ikut batal: begitu ada kursi kosong, kode yang sama berlaku lagi.
        CustomerMember::query()->where('user_id', $lain->id)->update(['status' => CustomerMember::STATUS_NONAKTIF]);

        $this->tukar((string) $korban->email, $kode, $this->sandiBenar)->assertCreated();
    }

    // ------------------------------------------------ atur ulang sandi mengosongkan ember

    public function test_atur_ulang_sandi_mengosongkan_ember_penukaran(): void
    {
        $korban = $this->anggota();
        ['kode' => $kode] = $this->undang((string) $korban->email);

        for ($i = 0; $i < 5; $i++) {
            $this->tukar((string) $korban->email, $kode, self::SANDI_TEBAKAN)->assertStatus(422);
        }

        $this->tukar((string) $korban->email, $kode, $this->sandiBenar)
            ->assertStatus(429)
            ->assertJsonPath('kode', 'terlalu_sering');

        $otp = app(KodeOtp::class)->terbitkan($korban, OtpPelanggan::TUJUAN_ATUR_ULANG_SANDI);
        $this->assertNotNull($otp);

        $sandiBaru = 'SandiHasilReset#2026';
        $this->permintaanBaru();
        $this->postJson('/api/pelanggan/v1/auth/atur-ulang-sandi', [
            'email' => $korban->email,
            'otp' => $otp,
            'sandi' => $sandiBaru,
        ])->assertOk();

        // Ember bersih: sandi baru langsung diterima, tanpa menunggu 15 menit.
        $this->tukar((string) $korban->email, $kode, $sandiBaru)
            ->assertCreated()
            ->assertJsonPath('data.akun_sudah_ada', true);
    }

    // ------------------------------------------------ kalimat 403 khusus undangan

    /** Jalur undangan tidak menyuruh minta "kode 6 digit" (kirim-ulang-otp sudah dicabut); `masuk` tidak berubah. */
    public function test_pending_email_di_jalur_undangan_diarahkan_ke_pt_sidik_dan_masuk_tidak_berubah(): void
    {
        $orang = $this->pelanggan(User::STATUS_PENDING_EMAIL);
        ['kode' => $kode] = $this->undang((string) $orang->email);

        $undangan = $this->tukar((string) $orang->email, $kode, $this->sandiBenar)
            ->assertStatus(403)
            ->assertJsonPath('kode', 'email_belum_diverifikasi');
        $this->assertStringNotContainsString('6 digit', (string) $undangan->json('message'));
        $this->assertStringContainsString('Hubungi PT Sidik', (string) $undangan->json('message'));

        $this->permintaanBaru();
        $masuk = $this->postJson('/api/pelanggan/v1/auth/masuk', ['email' => $orang->email, 'sandi' => $this->sandiBenar])
            ->assertStatus(403)
            ->assertJsonPath('kode', 'email_belum_diverifikasi');
        $this->assertStringContainsString('6 digit', (string) $masuk->json('message'));
    }

    /** Kode terpakai: tetap satu jawaban yang sama, walau akunnya ada. */
    public function test_kode_terpakai_tetap_undangan_tidak_berlaku_untuk_akun_lama(): void
    {
        $korban = $this->anggota();
        ['kode' => $kode] = $this->undang((string) $korban->email);

        $this->tukar((string) $korban->email, $kode, $this->sandiBenar)->assertCreated();

        $ulang = $this->tukar((string) $korban->email, $kode, self::SANDI_TEBAKAN)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'undangan_tidak_berlaku');

        $this->assertArrayNotHasKey('data', $ulang->json());
        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $korban->id)->count());
    }
}
