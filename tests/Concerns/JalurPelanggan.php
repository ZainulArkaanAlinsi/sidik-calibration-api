<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Organization;
use App\Models\PengajuanAkunPelanggan;
use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Hash;

/**
 * Perkakas bersama buat test modul pelanggan.
 *
 * ## Kenapa `UncompromisedVerifier` dipalsukan di sini, bukan per test
 *
 * `Password::uncompromised()` (02-SRS §11) menembak API Have I Been Pwned lewat
 * HTTPS. Di CI tidak ada jaringan keluar, jadi satu test yang lupa
 * memalsukannya gagal dengan galat jaringan yang sama sekali tidak menyebut
 * sandi — dan orang berikutnya menghabiskan setengah jam mencari di tempat yang
 * salah.
 *
 * Yang dipalsukan kontraknya di container, BUKAN `Http::fake()`: verifier
 * bawaan Laravel memakai klien HTTP-nya sendiri, jadi `Http::fake()` tidak
 * menangkapnya.
 *
 * Perlu menguji sandi yang DITOLAK karena bocor? Panggil
 * [sandiSelaluDianggapBocor()] di test itu.
 */
trait JalurPelanggan
{
    protected string $sandiBenar = 'Kalibrasi#2026Sidik';

    /** Nomor urut supaya nama pelanggan tidak menabrak UNIQUE(organization_id, nama). */
    private int $nomorPerusahaan = 0;

    protected function siapkanJalurPelanggan(): void
    {
        config(['pelanggan.fitur' => true]);
        $this->sandiTidakPernahDianggapBocor();

        // Organisasi lab HARUS ada sebelum endpoint daftar dipanggil.
        // `AuthPelangganController::daftar()` menempelkan pendaftar ke
        // `Organization::min('id')` — sama seperti `register()` internal — dan
        // di produksi baris itu selalu ada karena diseed waktu pasang. Di test
        // dengan `RefreshDatabase` tabelnya kosong, jadi tanpa baris ini yang
        // muncul galat foreign key yang tidak menyebut sebabnya sama sekali.
        $this->organisasi();
    }

    protected function sandiTidakPernahDianggapBocor(): void
    {
        $this->app->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
        {
            public function verify($data): bool
            {
                return true;
            }
        });
    }

    protected function sandiSelaluDianggapBocor(): void
    {
        $this->app->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
        {
            public function verify($data): bool
            {
                return false;
            }
        });
    }

    protected function organisasi(): Organization
    {
        return Organization::query()->first() ?? Organization::factory()->create();
    }

    /** Akun pelanggan pada status apa pun, tanpa keanggotaan. */
    protected function pelanggan(string $status = User::STATUS_AKTIF, array $tambahan = []): User
    {
        return User::factory()->create([
            'organization_id' => $this->organisasi()->id,
            'role' => User::ROLE_PELANGGAN,
            'status' => $status,
            'employee_id' => null,
            'kode_teknisi' => null,
            'telepon' => '+628123456789',
            'jabatan' => 'QA Manager',
            'password' => Hash::make($this->sandiBenar),
            ...$tambahan,
        ]);
    }

    /** Akun pelanggan AKTIF yang sudah jadi anggota sebuah perusahaan. */
    protected function anggota(string $peran = CustomerMember::PERAN_PIC_UTAMA): User
    {
        $user = $this->pelanggan();

        $customer = Customer::factory()->create([
            'organization_id' => $user->organization_id,
            'nama' => 'PT Contoh Pelanggan '.(++$this->nomorPerusahaan),
        ]);

        CustomerMember::create([
            'organization_id' => $user->organization_id,
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'peran' => $peran,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        return $user->refresh();
    }

    protected function pengajuan(User $user, string $status = PengajuanAkunPelanggan::STATUS_MENUNGGU): PengajuanAkunPelanggan
    {
        return PengajuanAkunPelanggan::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'nama_perusahaan' => 'PT Klaim Pendaftar',
            'alamat_perusahaan' => 'Jl. Contoh No. 1, Bandung',
            'jabatan' => $user->jabatan,
            'status' => $status,
        ]);
    }

    /**
     * Buang guard yang sudah kadung memutuskan "ini siapa" di request sebelumnya.
     *
     * WAJIB dipanggil di antara dua request dalam SATU test kalau yang diuji
     * pencabutan token. Sebabnya `RequestGuard::user()` menyimpan hasilnya di
     * properti dan memulangkannya lagi tanpa memeriksa apa pun:
     *
     *     if (! is_null($this->user)) { return $this->user; }
     *
     * Di produksi itu tidak pernah jadi soal — tiap request HTTP dapat instance
     * aplikasi yang baru. Di test, satu instance melayani semua request dalam
     * satu method, jadi request SESUDAH `/auth/keluar` tetap dilayani sebagai
     * pemilik token yang sudah dihapus.
     *
     * Bentuk kegagalannya paling jahat: test "token dicabut" jadi HIJAU PALSU
     * dengan menulis `assertOk()` — karena memang 200 yang keluar. Dibuktikan
     * waktu menulis ini: sesudah `/auth/keluar`, `personal_access_tokens`
     * benar-benar kosong (0 baris) tapi request berikutnya tetap 200.
     */
    protected function lupakanSesiGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * Header Bearer buat token pelanggan yang baru diterbitkan.
     *
     * SENGAJA lewat token sungguhan, bukan `Sanctum::actingAs()`: yang diuji
     * modul ini justru ability & `expires_at` yang menempel di baris token, dan
     * `actingAs()` melewati dua-duanya.
     *
     * @return array<string, string>
     */
    protected function bearer(User $user, ?string $ability = null): array
    {
        $ability ??= $user->status === User::STATUS_AKTIF ? 'pelanggan' : 'pelanggan:menunggu';

        return ['Authorization' => 'Bearer '.$user->createToken('uji', [$ability])->plainTextToken];
    }
}
