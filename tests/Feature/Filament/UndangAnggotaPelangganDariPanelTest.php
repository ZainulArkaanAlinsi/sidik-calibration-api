<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Mail\Pelanggan\UndanganEmail;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Organization;
use App\Models\UndanganPelanggan;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tombol "Undang anggota" di panel admin — dan kodenya benar-benar bisa ditukar.
 *
 * ## Kenapa lewat panel, padahal endpoint-nya sudah ada
 *
 * `POST /api/customers/{customer}/undangan` memang sudah ada dan sudah dites
 * (`TerimaUndanganTest`). Tapi yang memegang pelanggan baru itu admin yang
 * duduk di depan /admin, bukan orang yang menyusun bearer token di Postman.
 * Selama tombolnya tidak ada, satu-satunya jalan menerbitkan undangan pertama
 * sebuah perusahaan adalah lewat alat pengembang — dan di lapangan itu sama
 * saja dengan tidak ada jalan.
 *
 * ## Yang dites sampai UJUNG, bukan sampai notifikasinya
 *
 * Aksi yang "berhasil" tapi menerbitkan kode yang ditolak waktu ditukar itu
 * kegagalan paling mahal di sini: gejalanya muncul di HP orang lain, berhari-
 * hari kemudian, dan yang kelihatan di panel cuma notifikasi hijau. Jadi test
 * pertama menempuh rantai penuh — tekan tombol, ambil kode dari email,
 * tukar lewat HTTP, lalu masuk.
 */
class UndangAnggotaPelangganDariPanelTest extends TestCase
{
    use RefreshDatabase;

    private Customer $perusahaan;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        Mail::fake();

        // Sakelar modulnya dinyalakan: undangannya terbit tanpa sakelar ini,
        // tapi yang ditukar pelanggan lewat `/terima-undangan` ikut 503.
        config(['pelanggan.fitur' => true]);

        $this->perusahaan = Customer::factory()->create();

        $this->actingAs(User::factory()->admin()->create());
    }

    private function tekanTombol(string $email, string $peran = CustomerMember::PERAN_PIC_UTAMA): void
    {
        Livewire::test(ListCustomers::class)
            ->callAction(
                TestAction::make('undangAnggota')->table($this->perusahaan),
                ['email' => $email, 'peran' => $peran],
            )
            ->assertHasNoActionErrors();
    }

    private function kodeDariEmail(string $email): string
    {
        $kode = null;

        Mail::assertSent(UndanganEmail::class, function (UndanganEmail $mail) use ($email, &$kode) {
            if (! $mail->hasTo($email)) {
                return false;
            }

            $kode = $mail->kode;

            return true;
        });

        $this->assertNotNull($kode, "Tidak ada email undangan ke {$email}.");

        return (string) $kode;
    }

    public function test_undangan_dari_panel_bisa_ditukar_jadi_akun_yang_bisa_masuk(): void
    {
        $this->tekanTombol('pic@pabrik.test');

        $balasan = $this->postJson('/api/pelanggan/v1/auth/terima-undangan', [
            'email' => 'pic@pabrik.test',
            'kode' => $this->kodeDariEmail('pic@pabrik.test'),
            'nama' => 'Budi Diundang',
            'sandi' => 'kunci-lemari-besi-91',
            'telepon' => '0812-3456-7890',
            'jabatan' => 'QA Staff',
            'setuju_syarat' => true,
        ]);

        $balasan->assertSuccessful();

        // Dan bukan cuma barisnya yang jadi — orangnya bisa MASUK dengan sandi
        // yang barusan dia pilih.
        $this->postJson('/api/pelanggan/v1/auth/masuk', [
            'email' => 'pic@pabrik.test',
            'sandi' => 'kunci-lemari-besi-91',
            'nama_perangkat' => 'uji',
        ])->assertSuccessful()->assertJsonPath('data.token', fn ($token) => filled($token));
    }

    /**
     * Peran yang dipilih di modal yang DIPAKAI, bukan dihitung ulang waktu
     * ditukar.
     *
     * PIC utama satu-satunya peran yang boleh mengundang anggota lain. Kalau
     * pilihan di modal diabaikan dan semua undangan jadi staf, perusahaannya
     * punya akun tapi tidak punya siapa pun yang bisa menambah orang — persis
     * kebuntuan yang tombol ini dibuat untuk memutusnya.
     */
    public function test_peran_yang_dipilih_admin_yang_menempel_di_undangan(): void
    {
        $this->tekanTombol('staf@pabrik.test', CustomerMember::PERAN_STAF);

        $this->assertSame(
            CustomerMember::PERAN_STAF,
            UndanganPelanggan::query()->where('email', 'staf@pabrik.test')->firstOrFail()->peran,
        );

        $this->tekanTombol('pic@pabrik.test', CustomerMember::PERAN_PIC_UTAMA);

        $this->assertSame(
            CustomerMember::PERAN_PIC_UTAMA,
            UndanganPelanggan::query()->where('email', 'pic@pabrik.test')->firstOrFail()->peran,
        );
    }

    /** Kodenya disimpan ter-hash — panel tidak jadi jalan pintas ke kode mentah. */
    public function test_kode_tidak_pernah_tersimpan_mentah(): void
    {
        $this->tekanTombol('pic@pabrik.test');

        $baris = UndanganPelanggan::query()->firstOrFail();

        $this->assertNotSame($this->kodeDariEmail('pic@pabrik.test'), $baris->kode_hash);
        $this->assertStringStartsWith('$2y$', (string) $baris->kode_hash);
    }

    /**
     * Batas `maks_anggota` ditegakkan dari panel juga.
     *
     * Aturan ini hidup di `Keanggotaan`, bukan di controller, justru supaya
     * pintu baru tidak bisa melewatinya tanpa ada yang sadar. Test ini yang
     * membuktikan pintu panel ikut lewat situ.
     */
    public function test_batas_anggota_ikut_menahan_undangan_dari_panel(): void
    {
        $this->perusahaan->forceFill(['maks_anggota' => 1])->save();

        CustomerMember::factory()->create([
            'customer_id' => $this->perusahaan->getKey(),
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $this->tekanTombol('orang-kedua@pabrik.test');

        $this->assertSame(
            0,
            UndanganPelanggan::query()->where('email', 'orang-kedua@pabrik.test')->count(),
            'undangan tetap terbit walau perusahaannya sudah penuh',
        );
        Mail::assertNotSent(UndanganEmail::class);
    }

    /**
     * Panel bisa MENCABUT akses anggota — dan tokennya ikut mati.
     *
     * `UserResource` menyaring baris ber-role `pelanggan` keluar dari layar
     * Pengguna (benar: formnya memaksa memilih role internal), tapi penyaringan
     * itu ikut mencabut satu-satunya jalan yang tersisa buat MENUTUP akses.
     * Kejadian yang harus bisa ditangani hari itu juga: HP PIC utama hilang,
     * atau orangnya keluar kerja.
     *
     * Yang diuji sampai ujung bukan kolom `status`-nya, tapi TOKENNYA: akun
     * yang "dicabut" tapi tokennya masih hidup itu akun yang masih bisa dipakai
     * dari HP yang hilang tadi.
     */
    public function test_panel_bisa_mencabut_akses_anggota_berikut_tokennya(): void
    {
        $orang = User::factory()->create([
            'email' => 'pic@pabrik.test',
            'role' => User::ROLE_PELANGGAN,
            'status' => User::STATUS_AKTIF,
        ]);

        $anggota = CustomerMember::factory()->create([
            'customer_id' => $this->perusahaan->getKey(),
            'user_id' => $orang->getKey(),
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $orang->createToken('hp-lama');

        $this->assertSame(1, $orang->tokens()->count());

        Livewire::test(ListCustomers::class)
            ->callAction(
                TestAction::make('cabutAnggota')->table($this->perusahaan),
                ['anggota' => $anggota->getKey()],
            )
            ->assertHasNoActionErrors();

        $this->assertSame(CustomerMember::STATUS_NONAKTIF, $anggota->fresh()->status);
        $this->assertSame(
            0,
            $orang->tokens()->count(),
            'aksesnya dicabut tapi tokennya masih hidup — HP yang hilang tetap bisa dipakai',
        );
    }

    /**
     * PIC utama TERAKHIR tidak bisa dicabut dari panel.
     *
     * Kalau boleh, perusahaannya berdiri tanpa satu pun orang yang bisa
     * mengundang anggota baru, dan satu-satunya jalan keluar menelepon lab.
     * Penjaganya hidup di `Keanggotaan`, bukan di aksi panel ini — yang diuji
     * di sini bahwa aksi panel beneran lewat situ, bukan menulis statusnya
     * sendiri.
     */
    public function test_pic_utama_terakhir_tidak_bisa_dicabut_dari_panel(): void
    {
        $orang = User::factory()->create(['role' => User::ROLE_PELANGGAN]);

        $anggota = CustomerMember::factory()->create([
            'customer_id' => $this->perusahaan->getKey(),
            'user_id' => $orang->getKey(),
            'peran' => CustomerMember::PERAN_PIC_UTAMA,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        Livewire::test(ListCustomers::class)
            ->callAction(
                TestAction::make('cabutAnggota')->table($this->perusahaan),
                ['anggota' => $anggota->getKey()],
            );

        $this->assertSame(
            CustomerMember::STATUS_AKTIF,
            $anggota->fresh()->status,
            'PIC utama terakhir kecabut — perusahaannya jadi nggak punya siapa pun yang bisa mengundang',
        );
    }

    /** Orang yang sudah jadi anggota aktif tidak diundang dua kali. */
    public function test_anggota_aktif_tidak_diundang_ulang(): void
    {
        $orang = User::factory()->create([
            'email' => 'sudah@pabrik.test',
            'role' => User::ROLE_PELANGGAN,
        ]);

        CustomerMember::factory()->create([
            'customer_id' => $this->perusahaan->getKey(),
            'user_id' => $orang->getKey(),
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $this->tekanTombol('sudah@pabrik.test');

        $this->assertSame(0, UndanganPelanggan::query()->count());
        Mail::assertNotSent(UndanganEmail::class);
    }
}
