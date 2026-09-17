<?php

namespace Tests\Feature\Pelanggan;

use App\Mail\Pelanggan\UndanganEmail;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\DeviceToken;
use App\Models\UndanganPelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/** REQ-ANG-01/02/03 + tabel otorisasi 03-SDD §3.4. */
class AnggotaDanPeranTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
        Mail::fake();
    }

    /** @return array{pic: User, staf: User, perusahaan: Customer} */
    private function perusahaanBerisi(): array
    {
        $pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $perusahaan = $pic->keanggotaan()->first()->customer;

        $staf = $this->pelanggan();
        CustomerMember::create([
            'organization_id' => $perusahaan->organization_id,
            'customer_id' => $perusahaan->id,
            'user_id' => $staf->id,
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        return ['pic' => $pic, 'staf' => $staf, 'perusahaan' => $perusahaan];
    }

    // ------------------------------------------------------------- melihat

    /** REQ-ANG-03 — staf boleh MELIHAT daftar anggota. */
    public function test_REQ_ANG_03_staf_boleh_melihat_daftar(): void
    {
        ['staf' => $staf] = $this->perusahaanBerisi();

        $balasan = $this->withHeaders($this->bearer($staf))
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertOk()
            ->assertJsonPath('data.saya.peran', CustomerMember::PERAN_STAF)
            ->assertJsonStructure(['data' => ['anggota', 'undangan_menunggu', 'maks_anggota', 'saya']]);

        $this->assertCount(2, $balasan->json('data.anggota'));

        // `saya` dijawab server, bukan dihitung aplikasi dari id yang disimpan.
        $milikSaya = array_filter($balasan->json('data.anggota'), fn ($a) => $a['saya'] === true);
        $this->assertCount(1, $milikSaya);
    }

    /** Daftar anggota TIDAK membocorkan kosakata internal lab. */
    public function test_daftar_anggota_tidak_membocorkan_field_internal(): void
    {
        ['staf' => $staf] = $this->perusahaanBerisi();

        $balasan = $this->withHeaders($this->bearer($staf))
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertOk();

        foreach ($balasan->json('data.anggota') as $baris) {
            foreach (['role', 'employee_id', 'department', 'password'] as $bocor) {
                $this->assertArrayNotHasKey($bocor, $baris['orang'], "Field internal `{$bocor}` ikut keluar.");
            }
        }
    }

    /** Anggota perusahaan LAIN tidak pernah ikut terjaring. */
    public function test_daftar_disaring_ke_perusahaan_konteks(): void
    {
        ['staf' => $staf] = $this->perusahaanBerisi();
        $this->anggota(); // perusahaan lain, tidak ada hubungannya

        $balasan = $this->withHeaders($this->bearer($staf))
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertOk();

        $this->assertCount(2, $balasan->json('data.anggota'));
    }

    // ---------------------------------------------------------- mengundang

    /** REQ-ANG-01 — PIC utama mengundang, dan email beneran terkirim. */
    public function test_REQ_ANG_01_pic_utama_bisa_mengundang(): void
    {
        ['pic' => $pic, 'perusahaan' => $perusahaan] = $this->perusahaanBerisi();

        $this->withHeaders($this->bearer($pic))
            ->postJson('/api/pelanggan/v1/anggota/undangan', [
                'email' => 'orang-baru@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'orang-baru@contoh.test')
            ->assertJsonPath('data.peran', CustomerMember::PERAN_STAF);

        Mail::assertSent(UndanganEmail::class, fn (UndanganEmail $mail) => $mail->hasTo('orang-baru@contoh.test'));

        $this->assertDatabaseHas('undangan_pelanggan', [
            'customer_id' => $perusahaan->id,
            'email' => 'orang-baru@contoh.test',
            'dibuat_oleh' => $pic->id,
        ]);
    }

    /** PIC utama boleh mengangkat PIC utama lain — REQ-ANG-01 menyebut dua peran. */
    public function test_pic_utama_boleh_mengundang_pic_utama_lain(): void
    {
        ['pic' => $pic] = $this->perusahaanBerisi();

        $this->withHeaders($this->bearer($pic))
            ->postJson('/api/pelanggan/v1/anggota/undangan', [
                'email' => 'pic-kedua@contoh.test',
                'peran' => CustomerMember::PERAN_PIC_UTAMA,
            ])
            ->assertCreated()
            ->assertJsonPath('data.peran', CustomerMember::PERAN_PIC_UTAMA);
    }

    /** REQ-ANG-03 — staf TIDAK boleh mengundang. */
    public function test_REQ_ANG_03_staf_tidak_bisa_mengundang(): void
    {
        ['staf' => $staf] = $this->perusahaanBerisi();

        $this->withHeaders($this->bearer($staf))
            ->postJson('/api/pelanggan/v1/anggota/undangan', [
                'email' => 'orang-baru@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ])
            ->assertStatus(403)
            ->assertJsonPath('kode', 'bukan_pic_utama');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('undangan_pelanggan', 0);
    }

    /** Undangan yang masih menunggu ikut ditampilkan, supaya tidak diundang dua kali. */
    public function test_undangan_menunggu_ikut_di_daftar(): void
    {
        ['pic' => $pic] = $this->perusahaanBerisi();

        $this->withHeaders($this->bearer($pic))
            ->postJson('/api/pelanggan/v1/anggota/undangan', [
                'email' => 'orang-baru@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ])->assertCreated();

        $this->permintaanBaru();

        $this->withHeaders($this->bearer($pic))
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertOk()
            ->assertJsonPath('data.undangan_menunggu.0.email', 'orang-baru@contoh.test');
    }

    public function test_pic_utama_bisa_membatalkan_undangan(): void
    {
        ['pic' => $pic] = $this->perusahaanBerisi();

        $this->withHeaders($this->bearer($pic))
            ->postJson('/api/pelanggan/v1/anggota/undangan', [
                'email' => 'orang-baru@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ])->assertCreated();

        $undangan = UndanganPelanggan::query()->firstOrFail();
        $this->permintaanBaru();

        $this->withHeaders($this->bearer($pic))
            ->deleteJson("/api/pelanggan/v1/anggota/undangan/{$undangan->id}")
            ->assertOk();

        $this->assertNotNull($undangan->fresh()->dibatalkan_pada);
    }

    /** Undangan perusahaan LAIN → 404, bukan 403. */
    public function test_membatalkan_undangan_perusahaan_lain_dijawab_404(): void
    {
        ['pic' => $pic] = $this->perusahaanBerisi();

        $lain = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $perusahaanLain = $lain->keanggotaan()->first()->customer;
        $undanganLain = UndanganPelanggan::factory()->create([
            'organization_id' => $perusahaanLain->organization_id,
            'customer_id' => $perusahaanLain->id,
        ]);

        $this->permintaanBaru();

        $this->withHeaders($this->bearer($pic))
            ->deleteJson("/api/pelanggan/v1/anggota/undangan/{$undanganLain->id}")
            ->assertNotFound();

        $this->assertNull($undanganLain->fresh()->dibatalkan_pada);
    }

    /** REQ-ANG-01 — batas anggota juga ditegakkan di jalur PIC utama. */
    public function test_REQ_ANG_01_batas_anggota_menahan_undangan_dari_pic_utama(): void
    {
        ['pic' => $pic, 'perusahaan' => $perusahaan] = $this->perusahaanBerisi();
        $perusahaan->forceFill(['maks_anggota' => 2])->save();

        $this->withHeaders($this->bearer($pic))
            ->postJson('/api/pelanggan/v1/anggota/undangan', [
                'email' => 'orang-ketiga@contoh.test',
                'peran' => CustomerMember::PERAN_STAF,
            ])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'batas_anggota')
            ->assertJsonPath('data.maks_anggota', 2);

        Mail::assertNothingSent();
    }

    // --------------------------------------------------------- nonaktifkan

    /** REQ-ANG-02 + REQ-AUTH-09 — dinonaktifkan, seluruh sesinya dicabut. */
    public function test_REQ_ANG_02_nonaktifkan_mencabut_token_dan_perangkat(): void
    {
        ['pic' => $pic, 'staf' => $staf, 'perusahaan' => $perusahaan] = $this->perusahaanBerisi();

        $sesiStaf = $this->bearer($staf);
        DeviceToken::create([
            'user_id' => $staf->id,
            'token' => 'fcm-contoh-staf',
            'platform' => 'android',
            'aplikasi' => DeviceToken::APLIKASI_PELANGGAN,
        ]);

        $anggotaStaf = CustomerMember::query()->where('user_id', $staf->id)->firstOrFail();
        $this->permintaanBaru();

        $this->withHeaders($this->bearer($pic))
            ->postJson("/api/pelanggan/v1/anggota/{$anggotaStaf->id}/nonaktifkan")
            ->assertOk()
            ->assertJsonPath('data.status', CustomerMember::STATUS_NONAKTIF);

        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $staf->id)->count());
        $this->assertDatabaseMissing('device_tokens', ['user_id' => $staf->id]);

        $this->permintaanBaru();
        $this->withHeaders($sesiStaf)->getJson('/api/pelanggan/v1/anggota')->assertUnauthorized();
    }

    /**
     * Konsultan yang dilepas SATU perusahaan tidak ikut ter-logout dari yang lain.
     *
     * Kalau ikut, satu PIC utama bisa memutus akses orang ke perusahaan yang
     * sama sekali bukan urusannya.
     */
    public function test_anggota_di_perusahaan_lain_tidak_ikut_ter_logout(): void
    {
        ['pic' => $pic, 'staf' => $konsultan] = $this->perusahaanBerisi();

        $perusahaanLain = $this->perusahaan();
        CustomerMember::create([
            'organization_id' => $perusahaanLain->organization_id,
            'customer_id' => $perusahaanLain->id,
            'user_id' => $konsultan->id,
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $sesiKonsultan = $this->bearer($konsultan);

        $tokenSebelum = PersonalAccessToken::query()->where('tokenable_id', $konsultan->id)->count();
        $this->assertGreaterThan(0, $tokenSebelum, 'Premis testnya kosong: dia belum punya sesi buat dipertahankan.');

        $anggota = CustomerMember::query()
            ->where('user_id', $konsultan->id)
            ->where('customer_id', $pic->keanggotaan()->first()->customer_id)
            ->firstOrFail();

        $this->permintaanBaru();

        $this->withHeaders($this->bearer($pic))
            ->postJson("/api/pelanggan/v1/anggota/{$anggota->id}/nonaktifkan")
            ->assertOk();

        // Jumlahnya TIDAK berubah — bukan angka tetap yang gampang basi kalau
        // helper di atas suatu hari bikin token tambahan.
        $this->assertSame(
            $tokenSebelum,
            PersonalAccessToken::query()->where('tokenable_id', $konsultan->id)->count(),
            'Sesi konsultan ikut dicabut padahal dia masih anggota aktif di perusahaan lain.',
        );

        $this->permintaanBaru();

        $this->withHeaders($sesiKonsultan + ['X-Perusahaan-Id' => (string) $perusahaanLain->id])
            ->getJson('/api/pelanggan/v1/anggota')
            ->assertOk()
            ->assertJsonPath('data.saya.customer_id', $perusahaanLain->id);
    }

    /** REQ-ANG-02 — PIC utama terakhir tidak boleh menghilangkan dirinya. */
    public function test_REQ_ANG_02_pic_utama_terakhir_tidak_bisa_menonaktifkan_diri(): void
    {
        ['pic' => $pic] = $this->perusahaanBerisi();
        $anggotaPic = $pic->keanggotaan()->first();

        $this->withHeaders($this->bearer($pic))
            ->postJson("/api/pelanggan/v1/anggota/{$anggotaPic->id}/nonaktifkan")
            ->assertStatus(422)
            ->assertJsonPath('kode', 'pic_utama_terakhir');

        $this->assertSame(CustomerMember::STATUS_AKTIF, $anggotaPic->fresh()->status);
    }

    /** Begitu ada PIC utama kedua, yang pertama boleh keluar. */
    public function test_pic_utama_boleh_mundur_kalau_ada_pic_utama_lain(): void
    {
        ['pic' => $pic, 'staf' => $staf, 'perusahaan' => $perusahaan] = $this->perusahaanBerisi();

        CustomerMember::query()
            ->where('user_id', $staf->id)
            ->update(['peran' => CustomerMember::PERAN_PIC_UTAMA]);

        $anggotaPic = $pic->keanggotaan()->first();

        $this->withHeaders($this->bearer($pic))
            ->postJson("/api/pelanggan/v1/anggota/{$anggotaPic->id}/nonaktifkan")
            ->assertOk();

        $this->assertSame(CustomerMember::STATUS_NONAKTIF, $anggotaPic->fresh()->status);
    }

    /** REQ-ANG-03 — staf tidak bisa menonaktifkan siapa pun. */
    public function test_REQ_ANG_03_staf_tidak_bisa_menonaktifkan(): void
    {
        ['pic' => $pic, 'staf' => $staf] = $this->perusahaanBerisi();
        $anggotaPic = $pic->keanggotaan()->first();

        $this->withHeaders($this->bearer($staf))
            ->postJson("/api/pelanggan/v1/anggota/{$anggotaPic->id}/nonaktifkan")
            ->assertStatus(403)
            ->assertJsonPath('kode', 'bukan_pic_utama');

        $this->assertSame(CustomerMember::STATUS_AKTIF, $anggotaPic->fresh()->status);
    }

    /** Anggota perusahaan lain → 404, bukan 403. */
    public function test_menonaktifkan_anggota_perusahaan_lain_dijawab_404(): void
    {
        ['pic' => $pic] = $this->perusahaanBerisi();

        $korban = $this->anggota(CustomerMember::PERAN_STAF);
        $anggotaKorban = $korban->keanggotaan()->first();

        $this->permintaanBaru();

        $this->withHeaders($this->bearer($pic))
            ->postJson("/api/pelanggan/v1/anggota/{$anggotaKorban->id}/nonaktifkan")
            ->assertNotFound();

        $this->assertSame(CustomerMember::STATUS_AKTIF, $anggotaKorban->fresh()->status);
    }

    public function test_menonaktifkan_yang_sudah_nonaktif_ditolak(): void
    {
        ['pic' => $pic, 'staf' => $staf] = $this->perusahaanBerisi();
        $anggotaStaf = CustomerMember::query()->where('user_id', $staf->id)->firstOrFail();
        $anggotaStaf->forceFill(['status' => CustomerMember::STATUS_NONAKTIF])->save();

        $this->withHeaders($this->bearer($pic))
            ->postJson("/api/pelanggan/v1/anggota/{$anggotaStaf->id}/nonaktifkan")
            ->assertStatus(422)
            ->assertJsonPath('kode', 'sudah_nonaktif');
    }
}
