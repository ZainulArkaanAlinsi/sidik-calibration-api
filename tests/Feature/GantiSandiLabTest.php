<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * `POST /api/me/ganti-sandi` — akun LAB mengganti sandinya sendiri.
 *
 * Yang dijaga, dan kenapa tiap butir ada:
 *
 * - **Semua peran internal, termasuk `viewer` dan `super_admin`.** Mengganti
 *   sandi sendiri bukan "menulis data lab"; rute di grup `role:admin,teknisi,
 *   viewer` hanya mengizinkan super admin lewat `lolosTulisSuperAdmin()`, dan
 *   itu yang dibuktikan di sini (bukan diasumsikan dari komentar).
 * - **Token yang dipakai bertahan, token lain dicabut.** Dicek dengan token
 *   sungguhan (bukan `actingAs`), karena yang diuji baris `personal_access_tokens`.
 * - **Dua gerbang token**: token Sidik Studio dan token aplikasi Pelanggan
 *   ditolak `aplikasi:internal` SEBELUM controller dijalankan — sandi akun
 *   yang bersangkutan tidak boleh berubah.
 * - **Jejak audit tanpa sandi.** Satu baris `users`/`diubah`, `changed_by` =
 *   pemanggil; kolom `password` disensor, dan tidak ada sandi (polos maupun
 *   hash) di seluruh baris audit akun itu.
 * - **Role tidak tersentuh.**
 *
 * Memakai `JalurPelanggan` hanya untuk perkakasnya (sandi bocor dipalsukan,
 * token internal, akun pelanggan); tidak ada hubungannya dengan modul pelanggan.
 */
class GantiSandiLabTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const URL = '/api/me/ganti-sandi';

    private const SANDI_BARU = 'KataSandiBaru#2026Lab';

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
    }

    /** @return array<string, string> */
    private function badan(array $ubah = []): array
    {
        return [
            'sandi_lama' => $this->sandiBenar,
            'sandi' => self::SANDI_BARU,
            'sandi_confirmation' => self::SANDI_BARU,
            ...$ubah,
        ];
    }

    private function jumlahToken(User $user): int
    {
        return PersonalAccessToken::query()->where('tokenable_id', $user->getKey())->count();
    }

    // ------------------------------------------------------------- sukses

    /** @return array<string, array{0: string}> */
    public static function semuaPeranInternal(): array
    {
        return [
            'admin' => [User::ROLE_ADMIN],
            'teknisi' => [User::ROLE_TEKNISI],
            'viewer' => [User::ROLE_VIEWER],
            'super_admin' => [User::ROLE_SUPER_ADMIN],
        ];
    }

    #[DataProvider('semuaPeranInternal')]
    public function test_semua_peran_internal_bisa_ganti_sandi_sendiri_dan_token_lain_dicabut(string $role): void
    {
        $user = $this->adminLab($role);
        $statusAwal = $user->status;
        $organisasiAwal = $user->organization_id;

        $header = $this->bearerInternal($user);
        $user->createToken('hp-lain', ['internal']);
        $user->createToken('hp-ketiga', ['internal']);
        $this->assertSame(3, $this->jumlahToken($user), 'Premis testnya kosong: harus ada token lain untuk dicabut.');

        $this->postJson(self::URL, $this->badan(), $header)
            ->assertOk()
            ->assertExactJson([
                'message' => 'Sandi berhasil diganti.',
                'data' => ['sesi_dicabut' => 2],
            ]);

        $segar = $user->fresh();
        $this->assertTrue(Hash::check(self::SANDI_BARU, (string) $segar->password));
        $this->assertFalse(Hash::check($this->sandiBenar, (string) $segar->password));

        // Yang bertahan tepat SATU: token yang dipakai (nama `uji-internal`).
        $sisa = PersonalAccessToken::query()->where('tokenable_id', $user->getKey())->get();
        $this->assertCount(1, $sisa);
        $this->assertSame('uji-internal', $sisa->first()->name);

        // Mengganti sandi TIDAK menyentuh peran, status, maupun organisasi.
        $this->assertSame($role, $segar->role);
        $this->assertSame($statusAwal, $segar->status);
        $this->assertSame($organisasiAwal, $segar->organization_id);
    }

    /** Token yang dipakai tetap hidup: request berikutnya tidak 401. */
    public function test_token_yang_dipakai_tetap_berlaku_sesudah_ganti_sandi(): void
    {
        $user = $this->adminLab(User::ROLE_TEKNISI);
        $header = $this->bearerInternal($user);

        $this->postJson(self::URL, $this->badan(), $header)->assertOk();

        $this->permintaanBaru();
        $this->getJson('/api/me', $header)->assertOk()->assertJsonPath('data.id', $user->id);
    }

    /** Ujung ke ujung lewat pintu masuk yang sama dengan aplikasi: sandi baru jalan, lama mati. */
    public function test_login_memakai_sandi_baru_dan_menolak_sandi_lama(): void
    {
        $user = $this->adminLab(User::ROLE_TEKNISI);

        $this->postJson(self::URL, $this->badan(), $this->bearerInternal($user))->assertOk();

        $this->permintaanBaru();
        $this->postJson('/api/login', ['identifier' => $user->email, 'password' => self::SANDI_BARU])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $this->permintaanBaru();
        $this->postJson('/api/login', ['identifier' => $user->email, 'password' => $this->sandiBenar])
            ->assertStatus(401);
    }

    // ------------------------------------------------------------- gagal

    public function test_sandi_lama_salah_dijawab_422_dengan_kode_dan_tidak_mengubah_apa_pun(): void
    {
        $user = $this->adminLab(User::ROLE_ADMIN);
        $header = $this->bearerInternal($user);
        $user->createToken('hp-lain', ['internal']);
        $hashAwal = (string) $user->fresh()->password;

        $this->postJson(self::URL, $this->badan(['sandi_lama' => 'bukan-sandi-saya']), $header)
            ->assertStatus(422)
            ->assertExactJson([
                'kode' => 'sandi_lama_salah',
                'message' => 'Sandi lama tidak cocok.',
            ]);

        $this->assertSame($hashAwal, (string) $user->fresh()->password);
        $this->assertSame(2, $this->jumlahToken($user), 'Sandi lama salah tidak boleh mencabut sesi apa pun.');
        $this->assertSame(0, AuditLog::query()
            ->where('entity_type', 'users')
            ->where('entity_id', $user->id)
            ->where('action', AuditLog::ACTION_DIUBAH)
            ->count(), 'Pergantian yang ditolak tidak boleh meninggalkan baris audit.');
    }

    /** @return array<string, array{0: array<string, string|null>, 1: string}> */
    public static function badanTidakSah(): array
    {
        return [
            'sandi baru 9 karakter' => [['sandi' => 'Pendek#12', 'sandi_confirmation' => 'Pendek#12'], 'sandi'],
            'konfirmasi tidak cocok' => [['sandi_confirmation' => 'BedaSandi#2026Lab'], 'sandi'],
            'konfirmasi tidak dikirim' => [['sandi_confirmation' => null], 'sandi'],
            'sandi baru sama dengan lama' => [['sandi' => 'Kalibrasi#2026Sidik', 'sandi_confirmation' => 'Kalibrasi#2026Sidik'], 'sandi'],
            'sandi baru kosong' => [['sandi' => '', 'sandi_confirmation' => ''], 'sandi'],
            'sandi lama kosong' => [['sandi_lama' => ''], 'sandi_lama'],
        ];
    }

    /** @param  array<string, string|null>  $ubah */
    #[DataProvider('badanTidakSah')]
    public function test_sandi_baru_yang_tidak_memenuhi_aturan_ditolak_422(array $ubah, string $kolom): void
    {
        $user = $this->adminLab(User::ROLE_TEKNISI);
        $hashAwal = (string) $user->fresh()->password;

        $this->postJson(self::URL, $this->badan($ubah), $this->bearerInternal($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors($kolom);

        $this->assertSame($hashAwal, (string) $user->fresh()->password);
    }

    /** Sandi yang pernah bocor (k-anonymity ke HIBP, dipalsukan) ditolak. */
    public function test_sandi_baru_yang_pernah_bocor_ditolak(): void
    {
        $user = $this->adminLab(User::ROLE_TEKNISI);
        $hashAwal = (string) $user->fresh()->password;
        $this->sandiSelaluDianggapBocor();

        $this->postJson(self::URL, $this->badan(), $this->bearerInternal($user))
            ->assertStatus(422)
            ->assertJsonPath('errors.sandi.0', 'Sandi ini pernah muncul di kebocoran data. Pilih sandi lain.');

        $this->assertSame($hashAwal, (string) $user->fresh()->password);
    }

    public function test_pesan_validasi_berbahasa_indonesia(): void
    {
        $user = $this->adminLab(User::ROLE_TEKNISI);

        $this->postJson(self::URL, $this->badan(['sandi' => 'Pendek#12', 'sandi_confirmation' => 'Pendek#12']), $this->bearerInternal($user))
            ->assertStatus(422)
            ->assertJsonPath('errors.sandi.0', 'Sandi baru minimal 10 karakter.');
    }

    // ------------------------------------------------------- gerbang token

    public function test_tanpa_token_dijawab_401(): void
    {
        $this->postJson(self::URL, $this->badan())->assertUnauthorized();
    }

    public function test_token_studio_ditolak_dan_sandi_tidak_berubah(): void
    {
        $user = $this->adminLab(User::ROLE_ADMIN);
        $hashAwal = (string) $user->fresh()->password;
        $header = ['Authorization' => 'Bearer '.$user->createToken('studio', ['studio'])->plainTextToken];

        $this->postJson(self::URL, $this->badan(), $header)
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_aplikasi_ini');

        $this->assertSame($hashAwal, (string) $user->fresh()->password);
    }

    public function test_token_pelanggan_ditolak_dan_sandi_tidak_berubah(): void
    {
        $pelanggan = $this->anggota();
        $hashAwal = (string) $pelanggan->fresh()->password;

        $this->postJson(self::URL, $this->badan(), $this->bearer($pelanggan))
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_aplikasi_ini');

        $this->assertSame($hashAwal, (string) $pelanggan->fresh()->password);
    }

    // ------------------------------------------------------------- throttle

    /** Lima tebakan per menit per akun; yang keenam 429, bahkan dengan sandi lama yang benar. */
    public function test_throttle_sempit_per_akun(): void
    {
        $user = $this->adminLab(User::ROLE_TEKNISI);
        $header = $this->bearerInternal($user);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::URL, $this->badan(['sandi_lama' => 'tebakan-'.$i]), $header)
                ->assertStatus(422)
                ->assertJsonPath('kode', 'sandi_lama_salah');
        }

        $this->postJson(self::URL, $this->badan(), $header)->assertStatus(429);

        $this->assertTrue(Hash::check($this->sandiBenar, (string) $user->fresh()->password));
    }

    /** Jatah satu akun tidak menghabiskan jatah akun lain di belakang satu IP kantor. */
    public function test_jatah_dikunci_per_akun_bukan_per_ip(): void
    {
        $pertama = $this->adminLab(User::ROLE_TEKNISI);
        $kedua = $this->adminLab(User::ROLE_TEKNISI);
        $headerPertama = $this->bearerInternal($pertama);
        $headerKedua = $this->bearerInternal($kedua);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::URL, $this->badan(['sandi_lama' => 'tebakan-'.$i]), $headerPertama)->assertStatus(422);
        }
        $this->postJson(self::URL, $this->badan(), $headerPertama)->assertStatus(429);

        $this->permintaanBaru();
        $this->postJson(self::URL, $this->badan(), $headerKedua)->assertOk();
    }

    // ---------------------------------------------------------------- audit

    public function test_tercatat_di_audit_tanpa_nilai_sandi(): void
    {
        $user = $this->adminLab(User::ROLE_TEKNISI);
        $hashLama = (string) $user->fresh()->password;
        $sebelum = AuditLog::query()->count();

        $this->postJson(self::URL, $this->badan(), $this->bearerInternal($user))->assertOk();

        $baris = AuditLog::query()
            ->where('entity_type', 'users')
            ->where('entity_id', $user->id)
            ->where('action', AuditLog::ACTION_DIUBAH)
            ->get();

        $this->assertCount(1, $baris, 'Satu pergantian sandi = tepat satu baris audit.');
        $this->assertSame(1, AuditLog::query()->count() - $sebelum, 'Tidak ada baris audit lain yang ikut lahir.');

        $satu = $baris->first();
        $this->assertSame($user->id, $satu->changed_by, 'Pelakunya harus pemanggil sendiri.');
        $this->assertSame($user->organization_id, $satu->organization_id);
        $this->assertSame(['password' => '[disensor]'], $satu->old_data);
        $this->assertSame(['password' => '[disensor]'], $satu->new_data);

        // Tidak ada sandi — polos maupun hash — di baris audit mana pun.
        $semuaAudit = json_encode(AuditLog::query()->get()->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hashBaru = (string) $user->fresh()->password;
        foreach ([$this->sandiBenar, self::SANDI_BARU, $hashLama, $hashBaru] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, (string) $semuaAudit);
        }
    }

    public function test_balasan_tidak_memuat_sandi_atau_hash(): void
    {
        $user = $this->adminLab(User::ROLE_TEKNISI);

        $isi = $this->postJson(self::URL, $this->badan(), $this->bearerInternal($user))
            ->assertOk()
            ->getContent();

        foreach ([$this->sandiBenar, self::SANDI_BARU, (string) $user->fresh()->password] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, (string) $isi);
        }
    }
}
