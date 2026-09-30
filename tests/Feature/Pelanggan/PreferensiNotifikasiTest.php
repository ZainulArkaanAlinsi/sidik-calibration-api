<?php

namespace Tests\Feature\Pelanggan;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\PermintaanKalibrasi;
use App\Models\PreferensiNotifikasiAnggota;
use App\Models\User;
use App\Notifications\Pelanggan\AlatAndaJatuhTempo;
use App\Services\Pelanggan\PengingatJatuhTempoPelanggan;
use App\Services\Pelanggan\PreferensiNotifikasi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Saklar notifikasi pelanggan (PL_Preferensi): endpoint-nya, kunci per
 * perusahaan, dan — yang paling penting — pengirimnya benar-benar MEMBACA
 * saklar itu. Saklar yang tersimpan tapi tidak dibaca tidak memunculkan error;
 * orang yang sudah mematikan pengingat tetap dapat pengingat.
 */
class PreferensiNotifikasiTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private User $pic;

    private Customer $perusahaan;

    private CustomerMember $anggotaPic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        $this->pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $this->anggotaPic = $this->pic->keanggotaan()->first();
        $this->perusahaan = $this->anggotaPic->customer;
    }

    private function panggil(User $user, string $method, string $uri, array $badan = [], array $header = [])
    {
        $this->permintaanBaru();

        return $this->withHeaders($this->bearer($user) + $header)->json($method, '/api/pelanggan/v1'.$uri, $badan);
    }

    private function alatJatuhTempoBesok(Customer $pelanggan, ?string $serial = null): Equipment
    {
        // Tepat H-1: salah satu anak tangga pengingat, jadi dikirim hari ini.
        return Equipment::factory()->create([
            'organization_id' => $pelanggan->organization_id,
            'customer_id' => $pelanggan->id,
            'serial_number' => $serial ?? strtoupper(fake()->unique()->bothify('PR-####')),
            'tanggal_jatuh_tempo' => now()->addDay(),
            'status' => Equipment::STATUS_AKTIF,
        ]);
    }

    // ------------------------------------------------------------------ endpoint

    public function test_bawaan_untuk_anggota_yang_belum_pernah_menyetel(): void
    {
        $this->panggil($this->pic, 'GET', '/preferensi-notifikasi')
            ->assertOk()
            ->assertExactJson(['data' => [
                'pengingat_jadwal' => true,
                'status_permintaan' => true,
                'pesan_lab' => true,
                'ringkasan_email_mingguan' => false,
            ]]);

        // Membaca tidak membuat baris.
        $this->assertDatabaseCount('preferensi_notifikasi_anggota', 0);
    }

    public function test_simpan_sebagian_tidak_mengubah_yang_tidak_dikirim(): void
    {
        $this->panggil($this->pic, 'PUT', '/preferensi-notifikasi', ['pengingat_jadwal' => false])
            ->assertOk()
            ->assertJsonPath('data.pengingat_jadwal', false)
            ->assertJsonPath('data.status_permintaan', true)
            ->assertJsonPath('data.pesan_lab', true)
            ->assertJsonPath('data.ringkasan_email_mingguan', false);

        $this->panggil($this->pic, 'PUT', '/preferensi-notifikasi', ['ringkasan_email_mingguan' => true])
            ->assertOk()
            ->assertJsonPath('data.pengingat_jadwal', false)
            ->assertJsonPath('data.ringkasan_email_mingguan', true);

        $this->panggil($this->pic, 'GET', '/preferensi-notifikasi')
            ->assertJsonPath('data.pengingat_jadwal', false);

        $this->assertDatabaseCount('preferensi_notifikasi_anggota', 1);
    }

    public function test_nilai_harus_boolean(): void
    {
        $this->panggil($this->pic, 'PUT', '/preferensi-notifikasi', ['pesan_lab' => 'mungkin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pesan_lab']);
    }

    public function test_kunci_asing_dan_anggota_dari_body_diabaikan(): void
    {
        $lain = $this->anggota(CustomerMember::PERAN_STAF)->keanggotaan()->first();

        $this->panggil($this->pic, 'PUT', '/preferensi-notifikasi', [
            'pesan_lab' => false,
            'customer_member_id' => $lain->id,
            'is_admin' => true,
        ])->assertOk();

        $this->assertDatabaseHas('preferensi_notifikasi_anggota', ['customer_member_id' => $this->anggotaPic->id, 'pesan_lab' => false]);
        $this->assertDatabaseMissing('preferensi_notifikasi_anggota', ['customer_member_id' => $lain->id]);
    }

    public function test_setelan_per_perusahaan_untuk_konsultan_yang_ikut_dua_perusahaan(): void
    {
        $perusahaanKedua = Customer::factory()->create([
            'organization_id' => $this->perusahaan->organization_id,
            'nama' => 'PT Pabrik Kedua',
        ]);
        CustomerMember::create([
            'organization_id' => $perusahaanKedua->organization_id,
            'customer_id' => $perusahaanKedua->id,
            'user_id' => $this->pic->id,
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $this->panggil($this->pic, 'PUT', '/preferensi-notifikasi', ['pengingat_jadwal' => false], ['X-Perusahaan-Id' => (string) $this->perusahaan->id])
            ->assertOk();

        $this->panggil($this->pic, 'GET', '/preferensi-notifikasi', [], ['X-Perusahaan-Id' => (string) $this->perusahaan->id])
            ->assertJsonPath('data.pengingat_jadwal', false);
        // Perusahaan kedua tidak ikut mati.
        $this->panggil($this->pic, 'GET', '/preferensi-notifikasi', [], ['X-Perusahaan-Id' => (string) $perusahaanKedua->id])
            ->assertJsonPath('data.pengingat_jadwal', true);
    }

    public function test_akun_yang_belum_diverifikasi_dan_tanpa_token_ditolak(): void
    {
        $this->panggil($this->pelanggan(User::STATUS_PENDING_VERIFIKASI), 'GET', '/preferensi-notifikasi')->assertForbidden();

        $this->permintaanBaru();
        $this->getJson('/api/pelanggan/v1/preferensi-notifikasi')->assertUnauthorized();
    }

    // ------------------------------------------------------------------ pengingat

    public function test_pengingat_jatuh_tempo_dikirim_bila_saklar_menyala(): void
    {
        Notification::fake();
        $this->alatJatuhTempoBesok($this->perusahaan);

        app(PengingatJatuhTempoPelanggan::class)->jalankan();

        Notification::assertSentTo($this->pic, AlatAndaJatuhTempo::class);
    }

    public function test_pengingat_jatuh_tempo_menghormati_saklar_yang_dimatikan(): void
    {
        Notification::fake();
        $this->alatJatuhTempoBesok($this->perusahaan);

        $this->panggil($this->pic, 'PUT', '/preferensi-notifikasi', ['pengingat_jadwal' => false])->assertOk();

        $this->permintaanBaru();
        app(PengingatJatuhTempoPelanggan::class)->jalankan();

        Notification::assertNotSentTo($this->pic, AlatAndaJatuhTempo::class);
    }

    public function test_saklar_dimatikan_satu_anggota_tidak_membungkam_rekannya(): void
    {
        Notification::fake();

        $rekan = $this->pelanggan();
        CustomerMember::create([
            'organization_id' => $rekan->organization_id,
            'customer_id' => $this->perusahaan->id,
            'user_id' => $rekan->id,
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);
        PreferensiNotifikasiAnggota::create(['customer_member_id' => $this->anggotaPic->id, 'pengingat_jadwal' => false]);

        $this->alatJatuhTempoBesok($this->perusahaan);
        app(PengingatJatuhTempoPelanggan::class)->jalankan();

        Notification::assertSentTo($rekan, AlatAndaJatuhTempo::class);
        Notification::assertNotSentTo($this->pic, AlatAndaJatuhTempo::class);
    }

    public function test_saklar_lain_tidak_mematikan_pengingat(): void
    {
        Notification::fake();

        PreferensiNotifikasiAnggota::create([
            'customer_member_id' => $this->anggotaPic->id,
            'pengingat_jadwal' => true,
            'status_permintaan' => false,
            'pesan_lab' => false,
        ]);

        $this->alatJatuhTempoBesok($this->perusahaan);
        app(PengingatJatuhTempoPelanggan::class)->jalankan();

        Notification::assertSentTo($this->pic, AlatAndaJatuhTempo::class);
    }

    public function test_alat_yang_sedang_diajukan_tidak_diingatkan(): void
    {
        Notification::fake();
        $alat = $this->alatJatuhTempoBesok($this->perusahaan);

        $p = PermintaanKalibrasi::create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => $this->perusahaan->id,
            'nomor' => 'PMT/'.now()->format('Y/m').'/0001',
            'status' => PermintaanKalibrasi::STATUS_BARU,
            'metode_pengantaran' => 'diantar_sendiri',
        ]);
        $p->items()->create(['equipment_id' => $alat->id]);

        app(PengingatJatuhTempoPelanggan::class)->jalankan();

        Notification::assertNotSentTo($this->pic, AlatAndaJatuhTempo::class);

        // Ajuan dibatalkan → alat kembali masuk radar.
        $p->update(['status' => PermintaanKalibrasi::STATUS_DIBATALKAN]);

        app(PengingatJatuhTempoPelanggan::class)->jalankan();

        Notification::assertSentTo($this->pic, AlatAndaJatuhTempo::class);
    }

    public function test_alat_dalam_order_yang_sedang_berjalan_tidak_diingatkan(): void
    {
        Notification::fake();
        $alat = $this->alatJatuhTempoBesok($this->perusahaan);

        $order = Order::factory()->create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => $this->perusahaan->id,
            'status' => Order::STATUS_DIPROSES,
        ]);
        $order->items()->create(['equipment_id' => $alat->id]);

        app(PengingatJatuhTempoPelanggan::class)->jalankan();
        Notification::assertNotSentTo($this->pic, AlatAndaJatuhTempo::class);

        $order->update(['status' => Order::STATUS_SELESAI]);

        app(PengingatJatuhTempoPelanggan::class)->jalankan();
        Notification::assertSentTo($this->pic, AlatAndaJatuhTempo::class);
    }

    public function test_kunci_tidak_dikenal_ditolak_service(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PreferensiNotifikasi::class)->penerima($this->perusahaan, 'id; drop table users');
    }
}
