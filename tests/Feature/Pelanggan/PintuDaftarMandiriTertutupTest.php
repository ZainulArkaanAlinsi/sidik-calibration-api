<?php

namespace Tests\Feature\Pelanggan;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Pendaftaran mandiri BENAR-BENAR tertutup — di dua sisi, lab dan pelanggan.
 *
 * Kenapa ini butuh test sendiri padahal rutenya tinggal dihapus: rute bisa
 * lahir lagi dari mana saja. Berkas rute lain, paket pihak ketiga, `Route::any`
 * yang kelewat luas, atau orang berikutnya yang membaca `AuthPelangganController`
 * dan mengira `terimaUndangan()` kurang lengkap tanpa `daftar()`. Nol baris kode
 * yang perlu diingat siapa pun — yang menahan cuma test ini.
 *
 * Diuji lewat HTTP, bukan dengan membaca daftar rute, karena yang menentukan
 * pintunya kebuka atau nggak adalah apa yang dijawab server ke orang luar.
 *
 * Arah keduanya ikut diuji: undangan TETAP bisa ditukar. Tanpa itu, "aman" bisa
 * dicapai dengan menutup semua jalan masuk sekaligus — dan yang tersisa bukan
 * sistem yang aman, melainkan sistem yang tidak bisa dipakai siapa pun.
 */
class PintuDaftarMandiriTertutupTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
        Mail::fake();
    }

    /** @return array<int, array{0: string}> */
    public static function rutePelangganYangDicabut(): array
    {
        return [
            ['/api/pelanggan/v1/auth/daftar'],
            ['/api/pelanggan/v1/auth/verifikasi-email'],
            ['/api/pelanggan/v1/auth/kirim-ulang-otp'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rutePelangganYangDicabut')]
    public function test_rute_pendaftaran_pelanggan_dijawab_404(string $uri): void
    {
        $this->postJson($uri, [
            'nama' => 'Budi Pendaftar',
            'email' => 'budi@contoh.test',
            'sandi' => $this->sandiBenar,
            'otp' => '123456',
            'setuju_syarat' => true,
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'budi@contoh.test']);
    }

    /**
     * Tidak ada SATU PUN rute yang namanya masih menjanjikan pendaftaran.
     *
     * Pelengkap test 404 di atas: yang itu menjaga tiga URI yang kita tahu,
     * yang ini menjaring URI yang belum terpikirkan — termasuk rute baru yang
     * ditambahkan orang berikutnya dengan nama serupa.
     */
    public function test_tidak_ada_rute_bernama_daftar_atau_register(): void
    {
        $tertuduh = [];

        foreach (Route::getRoutes() as $rute) {
            $uri = $rute->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            // `pendaftaran` di sini berarti MEMBUAT akun dari luar. Rute yang
            // cuma MEMBACA daftar sesuatu (`/standards`, `/users`) tidak kena.
            if (preg_match('/(^|\/)(register|daftar)$/', $uri) === 1
                && in_array('POST', $rute->methods(), true)) {
                $tertuduh[] = implode('|', $rute->methods()).' '.$uri;
            }
        }

        $this->assertSame([], $tertuduh, sprintf(
            "Rute pendaftaran mandiri muncul lagi:\n  - %s\n\n".
            "Akun lahir dari undangan (AGENTS.md §Akun Lahir dari Undangan), bukan dari form publik.",
            implode("\n  - ", $tertuduh),
        ));
    }

    /** Sisi lab: form pendaftaran publik juga tidak ada. */
    public function test_register_internal_dijawab_404(): void
    {
        $this->postJson('/api/register', [
            'nama' => 'Calon Teknisi',
            'employee_id' => 'SDK-9911',
            'department' => 'Kalibrasi',
            'email' => 'calon@contoh.test',
            'password' => 'sandi-uji-yang-panjang',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'calon@contoh.test']);
    }

    /**
     * ARAH KEDUA — jalan masuk yang sah tetap terbuka.
     *
     * Admin lab membuat akun orang lab langsung, tanpa antrean persetujuan.
     * Ini jalur pengganti `POST /register`, dan kalau dia ikut mati, seluruh
     * pencabutan di atas berubah dari "lebih aman" jadi "tidak bisa dipakai".
     */
    public function test_admin_tetap_bisa_membuat_akun_orang_lab(): void
    {
        $akun = User::factory()->create([
            'organization_id' => $this->organisasi()->id,
            'employee_id' => 'SDK-7788',
            'email' => 'teknisi-baru@ptsidik.com',
            'password' => Hash::make($this->sandiBenar),
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ]);

        $this->assertSame(User::ROLE_TEKNISI, $akun->role);
        $this->assertSame(User::STATUS_AKTIF, $akun->status);

        // Dan akun itu beneran bisa masuk — bukan cuma ada barisnya.
        $this->postJson('/api/login', [
            'identifier' => 'SDK-7788',
            'password' => $this->sandiBenar,
        ])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }
}
