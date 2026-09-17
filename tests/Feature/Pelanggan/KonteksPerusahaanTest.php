<?php

namespace Tests\Feature\Pelanggan;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/** REQ-ANG-04 & 03-SDD §3.3 — perusahaan aktif dari `X-Perusahaan-Id`. */
class KonteksPerusahaanTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
    }

    /** Jadikan satu user anggota beberapa perusahaan — kasus konsultan. */
    private function konsultan(int $jumlah = 2): array
    {
        $user = $this->anggota();
        $perusahaan = [$user->keanggotaan()->first()->customer];

        for ($i = 1; $i < $jumlah; $i++) {
            $lain = $this->perusahaan();
            CustomerMember::create([
                'organization_id' => $lain->organization_id,
                'customer_id' => $lain->id,
                'user_id' => $user->id,
                'peran' => CustomerMember::PERAN_STAF,
                'status' => CustomerMember::STATUS_AKTIF,
            ]);
            $perusahaan[] = $lain;
        }

        return ['user' => $user->refresh(), 'perusahaan' => $perusahaan];
    }

    /** Satu keanggotaan, header kosong → dipakai otomatis. */
    public function test_satu_keanggotaan_tanpa_header_dipakai_otomatis(): void
    {
        $user = $this->anggota();
        $perusahaan = $user->keanggotaan()->first()->customer;

        $this->withHeaders($this->bearer($user))
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertOk()
            ->assertJsonPath('data.saya.customer_id', $perusahaan->id)
            ->assertJsonPath('data.saya.peran', CustomerMember::PERAN_PIC_UTAMA);
    }

    /** REQ-ANG-04 — lebih dari satu, header kosong → 400 + daftar pilihannya. */
    public function test_REQ_ANG_04_banyak_keanggotaan_tanpa_header_minta_dipilih(): void
    {
        ['user' => $user, 'perusahaan' => $perusahaan] = $this->konsultan(3);

        $balasan = $this->withHeaders($this->bearer($user))
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertStatus(400)
            ->assertJsonPath('kode', 'perusahaan_belum_dipilih');

        $this->assertCount(3, $balasan->json('data.pilihan'));
        $this->assertEqualsCanonicalizing(
            array_map(fn (Customer $satu) => $satu->id, $perusahaan),
            array_column($balasan->json('data.pilihan'), 'customer_id'),
        );
    }

    /** Header yang sah memilih perusahaan yang benar, bukan yang pertama. */
    public function test_header_memilih_perusahaan_yang_diminta(): void
    {
        ['user' => $user, 'perusahaan' => $perusahaan] = $this->konsultan(2);

        foreach ($perusahaan as $satu) {
            $this->permintaanBaru();

            $this->withHeaders($this->bearer($user) + ['X-Perusahaan-Id' => (string) $satu->id])
                ->getJson('/api/pelanggan/v1/anggota')
                ->assertOk()
                ->assertJsonPath('data.saya.customer_id', $satu->id);
        }
    }

    /**
     * REQ-ANG-04 — perusahaan yang bukan miliknya dijawab 404, BUKAN 403.
     *
     * 403 sudah memberi tahu bahwa perusahaan dengan ID itu ada, dan itu cukup
     * buat menyisir daftar pelanggan PT Sidik dengan menembak ID satu per satu.
     */
    public function test_REQ_ANG_04_perusahaan_orang_lain_dijawab_404(): void
    {
        $user = $this->anggota();
        $milikOrangLain = $this->perusahaan('PT Bukan Punya Dia');

        $this->withHeaders($this->bearer($user) + ['X-Perusahaan-Id' => (string) $milikOrangLain->id])
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertNotFound();
    }

    /** Perusahaan lab LAIN pun 404, bukan bocor lewat celah organisasi. */
    public function test_perusahaan_lab_lain_dijawab_404(): void
    {
        $user = $this->anggota();
        $lain = Customer::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'nama' => 'PT Lab Sebelah',
        ]);

        $this->withHeaders($this->bearer($user) + ['X-Perusahaan-Id' => (string) $lain->id])
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertNotFound();
    }

    /** Keanggotaan NONAKTIF diperlakukan sama dengan tidak ada. */
    public function test_keanggotaan_nonaktif_dijawab_404(): void
    {
        ['user' => $user, 'perusahaan' => $perusahaan] = $this->konsultan(2);

        CustomerMember::query()
            ->where('user_id', $user->id)
            ->where('customer_id', $perusahaan[1]->id)
            ->update(['status' => CustomerMember::STATUS_NONAKTIF]);

        $this->withHeaders($this->bearer($user) + ['X-Perusahaan-Id' => (string) $perusahaan[1]->id])
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertNotFound();

        $this->permintaanBaru();

        // Yang masih aktif tetap jalan, dan sekarang tanpa header pun cukup.
        $this->withHeaders($this->bearer($user))
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertOk()
            ->assertJsonPath('data.saya.customer_id', $perusahaan[0]->id);
    }

    /**
     * Header ngawur tidak boleh diam-diam jadi ID yang sah.
     *
     * `(int) 'abc'` itu 0 dan `(int) '12abc'` itu 12 — dua-duanya bikin
     * pembandingan `(int)` di kedua sisi menerima masukan yang jelas rusak.
     */
    public function test_header_ngawur_dijawab_404_bukan_dibaca_sebagai_angka(): void
    {
        $user = $this->anggota();
        $perusahaan = $user->keanggotaan()->first()->customer;

        foreach (['abc', '', ' ', $perusahaan->id.'abc', '0', '-1'] as $ngawur) {
            $this->permintaanBaru();

            $balasan = $this->withHeaders($this->bearer($user) + ['X-Perusahaan-Id' => $ngawur])
                ->getJson('/api/pelanggan/v1/anggota');

            // String kosong & spasi diperlakukan "tidak dikirim" — dan dia punya
            // satu keanggotaan, jadi jalurnya lolos. Sisanya wajib 404.
            $harap = trim($ngawur) === '' ? 200 : 404;

            $balasan->assertStatus($harap, "Header `{$ngawur}` dijawab bukan {$harap}.");
        }
    }

    /** Akun yang seluruh keanggotaannya nonaktif tidak punya konteks sama sekali. */
    public function test_nol_keanggotaan_aktif_dijawab_404(): void
    {
        $user = $this->anggota();
        CustomerMember::query()->where('user_id', $user->id)->update(['status' => CustomerMember::STATUS_NONAKTIF]);

        $this->withHeaders($this->bearer($user))
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertNotFound();
    }

    /** Akun yang belum diverifikasi tidak pernah sampai ke gerbang konteks. */
    public function test_akun_menunggu_verifikasi_ditahan_gerbang_sebelumnya(): void
    {
        $user = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI);

        $this->withHeaders($this->bearer($user))
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertStatus(403)
            ->assertJsonPath('kode', 'akun_belum_diverifikasi');
    }
}
