<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Organization;
use App\Models\UndanganPelanggan;
use App\Services\Pelanggan\KodeUndangan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `kode_hash` undangan (bcrypt dari kode berumur 7 hari yang menukar
 * keanggotaan perusahaan) tidak boleh tersalin ke `audit_logs`.
 *
 * `audit_logs` menyimpan nilai lama & baru tiap kolom yang berubah dan dibaca
 * jauh lebih banyak orang daripada tabel undangannya. Yang dijaga dua arah:
 *
 * - hash dan kode mentah TIDAK ADA di baris audit mana pun (dibikin, diubah);
 * - jejaknya sendiri tetap tercatat — undangan terbit/dibatalkan/dipakai tetap
 *   kelihatan di riwayat, dengan kolom lain utuh. Nama kolom `kode_hash` boleh
 *   muncul sebagai penanda `[disensor]`, isinya tidak.
 */
class UndanganPelangganAuditTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Customer $perusahaan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->perusahaan = Customer::factory()->create(['organization_id' => $this->org->id]);
    }

    /** @return Collection<int, AuditLog> */
    private function jejakUndangan()
    {
        return AuditLog::query()
            ->where('entity_type', 'undangan_pelanggan')
            ->orderBy('id')
            ->get();
    }

    private function semuaJejakSebagaiTeks(): string
    {
        return AuditLog::query()->get()->toJson();
    }

    public function test_undangan_dibikin_tercatat_tanpa_isi_kode_hash(): void
    {
        $undangan = UndanganPelanggan::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->perusahaan->id,
            'email' => 'anggota@contoh.test',
            'peran' => CustomerMember::PERAN_STAF,
        ]);
        $hash = (string) $undangan->kode_hash;

        $jejak = $this->jejakUndangan();

        $this->assertCount(1, $jejak, 'Undangan terbit harus tetap tercatat satu kali.');
        $log = $jejak->first();
        $this->assertSame(AuditLog::ACTION_DIBIKIN, $log->action);
        $this->assertSame($undangan->id, (int) $log->entity_id);
        $this->assertSame($this->org->id, (int) $log->organization_id);

        // Kolom lain utuh — jejaknya masih berguna buat asesor.
        $this->assertSame('anggota@contoh.test', $log->new_data['email']);
        $this->assertSame(CustomerMember::PERAN_STAF, $log->new_data['peran']);
        $this->assertSame($this->perusahaan->id, (int) $log->new_data['customer_id']);

        // Kolom rahasia: ada sebagai penanda, tanpa isi.
        $this->assertSame('[disensor]', $log->new_data['kode_hash']);
        $this->assertStringNotContainsString($hash, $this->semuaJejakSebagaiTeks());
        $this->assertStringNotContainsString('$2y$', $this->semuaJejakSebagaiTeks());
    }

    public function test_perubahan_undangan_tercatat_dan_kode_hash_baru_maupun_lama_disensor(): void
    {
        $undangan = UndanganPelanggan::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->perusahaan->id,
        ]);
        $hashLama = (string) $undangan->kode_hash;
        AuditLog::query()->delete();

        $hashBaru = Hash::make('KODEBARU');
        $undangan->forceFill(['kode_hash' => $hashBaru, 'dibatalkan_pada' => now()])->save();

        $log = $this->jejakUndangan()->last();

        $this->assertNotNull($log, 'Perubahan undangan harus tetap tercatat.');
        $this->assertSame(AuditLog::ACTION_DIUBAH, $log->action);
        $this->assertArrayHasKey('dibatalkan_pada', $log->new_data, 'Pembatalan harus kelihatan di riwayat.');
        $this->assertSame('[disensor]', $log->new_data['kode_hash']);
        $this->assertSame('[disensor]', $log->old_data['kode_hash']);

        $teks = $this->semuaJejakSebagaiTeks();
        $this->assertStringNotContainsString($hashLama, $teks);
        $this->assertStringNotContainsString($hashBaru, $teks);
    }

    public function test_perubahan_lain_tanpa_menyentuh_kode_hash_tetap_tercatat(): void
    {
        $undangan = UndanganPelanggan::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->perusahaan->id,
        ]);
        AuditLog::query()->delete();

        $undangan->update(['dibatalkan_pada' => now()]);

        $log = $this->jejakUndangan()->last();

        $this->assertNotNull($log);
        $this->assertSame(['dibatalkan_pada'], array_keys($log->new_data));
        $this->assertSame(['dibatalkan_pada'], array_keys($log->old_data));
    }

    /**
     * Jalur sungguhan: `KodeUndangan::terbitkan()` yang dipakai API & panel.
     * Baik hash maupun kode mentahnya tidak boleh tersisa di audit_logs.
     */
    public function test_jalur_terbitkan_sungguhan_tidak_membocorkan_hash_maupun_kode(): void
    {
        ['undangan' => $undangan, 'kode' => $kode] = app(KodeUndangan::class)->terbitkan(
            $this->perusahaan,
            'baru@contoh.test',
            CustomerMember::PERAN_STAF,
        );

        $jejak = $this->jejakUndangan();
        $this->assertCount(1, $jejak);
        $this->assertSame('baru@contoh.test', $jejak->first()->new_data['email']);

        $teks = $this->semuaJejakSebagaiTeks();
        $this->assertStringNotContainsString((string) $undangan->kode_hash, $teks);
        $this->assertStringNotContainsString($kode, $teks);
    }

    /**
     * Daftar rahasia bawaan trait (`password`, dst.) tidak boleh hilang karena
     * model ini menambah `kode_hash` — penambahan, bukan penggantian.
     */
    public function test_daftar_rahasia_menambah_bukan_mengganti_bawaan_trait(): void
    {
        $metode = new \ReflectionMethod(UndanganPelanggan::class, 'kolomRahasia');
        $daftar = $metode->invoke(new UndanganPelanggan);

        $this->assertContains('kode_hash', $daftar);
        $this->assertContains('password', $daftar);
        $this->assertContains('remember_token', $daftar);
    }
}
