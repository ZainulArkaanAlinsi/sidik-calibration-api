<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Organization;
use App\Models\OtpPelanggan;
use App\Models\PengajuanAkunPelanggan;
use App\Models\PersetujuanDokumen;
use App\Models\UndanganPelanggan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Relasi & penjagaan tingkat-database untuk tabel identitas pelanggan.
 */
class ModelPelangganTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Customer $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['nama' => 'PT Contoh Satu']);
        $this->pelanggan = Customer::factory()->create([
            'organization_id' => $this->org->id,
            'nama' => 'PT Contoh Dua',
        ]);
    }

    private function anggota(array $atribut = []): CustomerMember
    {
        return CustomerMember::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'customer_id' => $this->pelanggan->id,
            'user_id' => User::factory()->create(['organization_id' => $this->org->id])->id,
        ], $atribut));
    }

    public function test_customer_punya_daftar_anggota(): void
    {
        $this->anggota();
        $this->anggota(['peran' => CustomerMember::PERAN_PIC_UTAMA]);

        $this->assertCount(2, $this->pelanggan->refresh()->members);
    }

    public function test_user_punya_daftar_keanggotaan(): void
    {
        $anggota = $this->anggota();

        $this->assertCount(1, $anggota->user->refresh()->keanggotaan);
        $this->assertTrue($anggota->user->keanggotaan->first()->is($anggota));
    }

    public function test_customer_punya_pic_admin(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN]);
        $this->pelanggan->update(['pic_admin_id' => $admin->id]);

        $this->assertTrue($this->pelanggan->refresh()->picAdmin->is($admin));
    }

    /**
     * `refresh()` bukan hiasan: factory cuma menyetel atribut yang dia sebut,
     * jadi instance di memori nggak tahu nilai DEFAULT yang dipasang database.
     * Tanpa refresh, yang kebaca `null` — dan test ini gagal karena alasan yang
     * nggak ada hubungannya dengan migrasinya.
     */
    public function test_maks_anggota_default_lima_puluh(): void
    {
        $this->assertSame(50, (int) $this->pelanggan->refresh()->maks_anggota);
    }

    /**
     * Keanggotaan kembar ditolak DATABASE, bukan cuma kode.
     *
     * Dua baris untuk orang yang sama bikin `KonteksPerusahaan` memilih salah
     * satunya sembarangan — peran yang berlaku jadi bergantung pada urutan
     * baris, dan itu berubah-ubah tanpa ada yang menyentuh apa pun.
     */
    public function test_keanggotaan_kembar_ditolak(): void
    {
        $anggota = $this->anggota();

        $this->expectException(QueryException::class);

        CustomerMember::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->pelanggan->id,
            'user_id' => $anggota->user_id,
        ]);
    }

    public function test_scope_aktif_menyaring_yang_nonaktif(): void
    {
        $this->anggota();
        $this->anggota()->update([
            'status' => CustomerMember::STATUS_NONAKTIF,
            'dinonaktifkan_pada' => now(),
        ]);

        $this->assertSame(1, CustomerMember::query()->aktif()->count());
    }

    public function test_undangan_kedaluwarsa_dan_terpakai_ditolak(): void
    {
        $dasar = ['organization_id' => $this->org->id, 'customer_id' => $this->pelanggan->id];

        $this->assertTrue(UndanganPelanggan::factory()->create($dasar)->masihBisaDipakai());
        $this->assertFalse(UndanganPelanggan::factory()->kedaluwarsa()->create($dasar)->masihBisaDipakai());
        $this->assertFalse(UndanganPelanggan::factory()->sudahDipakai()->create($dasar)->masihBisaDipakai());
        $this->assertFalse(
            UndanganPelanggan::factory()->create($dasar + ['dibatalkan_pada' => now()])->masihBisaDipakai(),
            'Undangan yang sudah dibatalkan masih bisa ditukar.'
        );
    }

    public function test_otp_terkunci_dan_kedaluwarsa_tidak_berlaku(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $this->assertTrue(OtpPelanggan::factory()->create(['user_id' => $user->id])->masihBerlaku());
        $this->assertFalse(OtpPelanggan::factory()->kedaluwarsa()->create(['user_id' => $user->id])->masihBerlaku());

        $terkunci = OtpPelanggan::factory()->terkunci()->create(['user_id' => $user->id]);
        $this->assertTrue($terkunci->sedangDikunci());
        $this->assertFalse($terkunci->masihBerlaku());
    }

    /** `kode_hash` nggak boleh bisa diisi lewat mass-assignment. */
    public function test_kode_hash_tidak_mass_assignable(): void
    {
        foreach ([UndanganPelanggan::class, OtpPelanggan::class] as $kelas) {
            $this->assertNotContains(
                'kode_hash',
                (new $kelas)->getFillable(),
                $kelas.' membolehkan `kode_hash` diisi dari request. Kode undangan & OTP itu kredensial.'
            );
        }
    }

    public function test_pengajuan_dan_persetujuan_punya_relasi(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $pengajuan = PengajuanAkunPelanggan::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $user->id,
        ]);
        $this->assertTrue($pengajuan->pemohon->is($user));
        $this->assertSame(PengajuanAkunPelanggan::STATUS_MENUNGGU, $pengajuan->status);

        $setuju = PersetujuanDokumen::factory()->create(['user_id' => $user->id]);
        $this->assertTrue($setuju->user->is($user));
    }

    /**
     * Nilai `sumber` baru cukup di konstanta — kolomnya `string`, bukan ENUM,
     * jadi nol migrasi. SDD §4.1 menulisnya seolah ENUM; itu yang keliru.
     *
     * `sumber` sengaja TIDAK masuk `$fillable` Customer (keputusan lama yang
     * saya biarkan), jadi di sini diisi eksplisit — bukan lewat factory, yang
     * bakal membuangnya diam-diam.
     */
    public function test_sumber_pelanggan_terdaftar_di_konstanta(): void
    {
        $this->assertContains(Customer::SUMBER_PELANGGAN, Customer::SUMBER);

        $baru = Customer::factory()->create([
            'organization_id' => $this->org->id,
            'nama' => 'PT Contoh Tiga',
        ]);
        $baru->sumber = Customer::SUMBER_PELANGGAN;
        $baru->save();

        $this->assertSame(Customer::SUMBER_PELANGGAN, $baru->refresh()->sumber);
    }
}
