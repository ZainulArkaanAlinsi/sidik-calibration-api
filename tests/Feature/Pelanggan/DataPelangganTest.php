<?php

namespace Tests\Feature\Pelanggan;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Notifications\Pelanggan\AlatAndaJatuhTempo;
use App\Services\Push\PengirimPush;
use App\Services\TahapPaket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Slice F — data perusahaan di aplikasi pelanggan: beranda, alat, sertifikat,
 * paket, notifikasi, dan pendaftaran perangkat push.
 *
 * Isolasi ID lintas perusahaan disapu `IsolasiPerusahaanTest` (tiap rute
 * ber-ID). Yang diuji di sini sisi yang sapuan itu tidak lihat: DAFTAR yang
 * tidak memuat milik orang lain, field internal yang tidak ikut keluar, dan
 * aturan tampil (sertifikat belum terbit, revisi, tahap pengesahan).
 */
class DataPelangganTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const V1 = '/api/pelanggan/v1';

    private User $pic;

    private Customer $milikSaya;

    private Customer $milikLain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        $this->pic = $this->anggota();
        $this->milikSaya = $this->pic->keanggotaan()->first()->customer;
        $this->milikLain = $this->perusahaan();
    }

    private function alat(Customer $pemilik, array $isi = []): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'customer_id' => $pemilik->id,
            'status' => Equipment::STATUS_AKTIF,
            ...$isi,
        ]);
    }

    private function sertifikat(Equipment $alat, array $isi = []): Certificate
    {
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $alat->organization_id,
            'equipment_id' => $alat->id,
        ]);

        return Certificate::factory()->create([
            'organization_id' => $alat->organization_id,
            'calibration_session_id' => $sesi->id,
            'snapshot' => ['header' => [
                'equipment_name' => $alat->nama_alat,
                'serial_number' => $alat->serial_number,
            ], 'meta' => ['keputusan' => 'PASS']],
            ...$isi,
        ]);
    }

    private function ambil(string $uri)
    {
        $this->permintaanBaru();

        return $this->withHeaders($this->bearer($this->pic))->getJson(self::V1.$uri);
    }

    // --- /alat ---------------------------------------------------------------

    public function test_daftar_alat_cuma_milik_perusahaan_sendiri(): void
    {
        $punyaSaya = $this->alat($this->milikSaya, ['nama_alat' => 'Jangka Sorong Saya']);
        $this->alat($this->milikLain, ['nama_alat' => 'Jangka Sorong Pesaing']);

        $respons = $this->ambil('/alat')->assertOk();

        $this->assertSame([$punyaSaya->id], array_column($respons->json('data'), 'id'));
        $this->assertStringNotContainsString('Pesaing', $respons->getContent());
    }

    public function test_status_kalibrasi_diturunkan_dari_jatuh_tempo(): void
    {
        $lewat = $this->alat($this->milikSaya, ['tanggal_jatuh_tempo' => now()->subDays(3)]);
        $segera = $this->alat($this->milikSaya, ['tanggal_jatuh_tempo' => now()->addDays(10)]);
        $aman = $this->alat($this->milikSaya, ['tanggal_jatuh_tempo' => now()->addDays(200)]);

        $data = collect($this->ambil('/alat')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('lewat_jatuh_tempo', $data[$lewat->id]['status_kalibrasi']);
        $this->assertSame(-3, $data[$lewat->id]['hari_ke_jatuh_tempo']);
        $this->assertSame('segera_jatuh_tempo', $data[$segera->id]['status_kalibrasi']);
        $this->assertSame('aman', $data[$aman->id]['status_kalibrasi']);

        // Paling mendesak di atas.
        $this->assertSame($lewat->id, $this->ambil('/alat')->json('data.0.id'));
        $this->assertSame([$lewat->id], array_column($this->ambil('/alat?saring=lewat')->json('data'), 'id'));
        $this->assertSame([$segera->id], array_column($this->ambil('/alat?saring=segera')->json('data'), 'id'));
    }

    public function test_alat_tidak_membuka_kolom_internal_lab(): void
    {
        $alat = $this->alat($this->milikSaya, ['catatan' => 'CATATAN-INTERNAL-LAB', 'toleransi' => 0.02]);

        $respons = $this->ambil("/alat/{$alat->id}")->assertOk();

        $this->assertStringNotContainsString('CATATAN-INTERNAL-LAB', $respons->getContent());
        $this->assertArrayNotHasKey('toleransi', $respons->json('data'));
        $this->assertArrayNotHasKey('nama_alat_kemampuan', $respons->json('data'));
    }

    public function test_detail_alat_memuat_riwayat_sertifikat_terbit_saja(): void
    {
        $alat = $this->alat($this->milikSaya);
        $terbit = $this->sertifikat($alat);
        $this->sertifikat($alat, [
            'status' => Certificate::STATUS_MENUNGGU_GENERATE,
            'diterbitkan_pada' => null,
            'berlaku_sampai' => null,
        ]);

        $respons = $this->ambil("/alat/{$alat->id}")->assertOk();

        $this->assertSame([$terbit->id], array_column($respons->json('data.riwayat_sertifikat'), 'id'));
        $this->assertSame($terbit->id, $respons->json('data.sertifikat_terakhir.id'));
    }

    // --- /sertifikat -----------------------------------------------------------

    public function test_daftar_sertifikat_menyembunyikan_yang_sudah_digantikan_revisi(): void
    {
        $alat = $this->alat($this->milikSaya);
        $lama = $this->sertifikat($alat);
        $baru = $this->sertifikat($alat, ['revision_of' => $lama->id]);
        $this->sertifikat($this->alat($this->milikLain));

        $this->assertSame([$baru->id], array_column($this->ambil('/sertifikat')->assertOk()->json('data'), 'id'));

        $semua = array_column($this->ambil('/sertifikat?termasuk_digantikan=1')->json('data'), 'id');
        sort($semua);
        $this->assertSame([$lama->id, $baru->id], $semua);

        $this->ambil("/sertifikat/{$lama->id}")
            ->assertOk()
            ->assertJsonPath('data.digantikan_oleh.id', $baru->id);

        $this->ambil("/sertifikat/{$baru->id}")
            ->assertOk()
            ->assertJsonPath('data.revisi_dari.id', $lama->id)
            ->assertJsonMissingPath('data.snapshot');
    }

    public function test_sertifikat_yang_belum_terbit_dijawab_404(): void
    {
        $belum = $this->sertifikat($this->alat($this->milikSaya), [
            'status' => Certificate::STATUS_MENUNGGU_GENERATE,
            'diterbitkan_pada' => null,
        ]);

        $this->ambil("/sertifikat/{$belum->id}")->assertNotFound();
        $this->assertSame([], $this->ambil('/sertifikat')->json('data'));
    }

    public function test_unduh_pdf_milik_sendiri_dan_tercatat_di_riwayat_audit(): void
    {
        Storage::fake('arsip');
        $sertifikat = $this->sertifikat($this->alat($this->milikSaya), ['pdf_path' => 'sertifikat/uji.pdf']);
        Storage::disk('arsip')->put('sertifikat/uji.pdf', '%PDF-1.4'.str_repeat('x', 5000)."\n%%EOF");

        $respons = $this->ambil("/sertifikat/{$sertifikat->id}/unduh")->assertOk();

        $this->assertStringStartsWith('%PDF', $respons->streamedContent());
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'certificates',
            'entity_id' => $sertifikat->id,
            'action' => AuditLog::ACTION_DIUNDUH_PELANGGAN,
            'changed_by' => $this->pic->id,
        ]);
    }

    public function test_unduh_pdf_perusahaan_lain_404_dan_tidak_dicatat(): void
    {
        Storage::fake('arsip');
        $lain = $this->sertifikat($this->alat($this->milikLain), ['pdf_path' => 'sertifikat/lain.pdf']);
        Storage::disk('arsip')->put('sertifikat/lain.pdf', '%PDF-1.4'.str_repeat('x', 5000)."\n%%EOF");

        $this->ambil("/sertifikat/{$lain->id}/unduh")->assertNotFound();
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_DIUNDUH_PELANGGAN]);
    }

    // --- /paket ------------------------------------------------------------

    public function test_paket_melipat_pengesahan_ke_pemeriksaan_dan_tidak_menyebut_teknisi(): void
    {
        $teknisi = User::factory()->create([
            'organization_id' => $this->milikSaya->organization_id,
            'role' => User::ROLE_TEKNISI,
            'name' => 'Teknisi Rahasia Sekali',
        ]);
        $order = Order::factory()->create([
            'organization_id' => $this->milikSaya->organization_id,
            'customer_id' => $this->milikSaya->id,
            'status' => Order::STATUS_DIPROSES,
        ]);
        $alat = $this->alat($this->milikSaya);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $teknisi->id,
        ]);
        CalibrationSession::factory()->create([
            'organization_id' => $alat->organization_id,
            'equipment_id' => $alat->id,
            'order_item_id' => $item->id,
            'teknisi_id' => $teknisi->id,
            'status' => CalibrationSession::STATUS_MENUNGGU_PENGESAHAN,
        ]);
        Order::factory()->create([
            'organization_id' => $this->milikLain->organization_id,
            'customer_id' => $this->milikLain->id,
        ]);

        $daftar = $this->ambil('/paket')->assertOk();
        $this->assertSame([$order->id], array_column($daftar->json('data'), 'id'));

        $detail = $this->ambil("/paket/{$order->id}")->assertOk();
        $detail->assertJsonPath('data.tahap', TahapPaket::MENUNGGU_PEMERIKSAAN)
            ->assertJsonPath('data.alat.0.tahap', TahapPaket::MENUNGGU_PEMERIKSAAN);

        $this->assertStringNotContainsString('Teknisi Rahasia', $detail->getContent());
        $this->assertStringNotContainsString(TahapPaket::MENUNGGU_PENGESAHAN, $detail->getContent());
    }

    // --- /beranda ----------------------------------------------------------

    public function test_beranda_menghitung_milik_sendiri_saja(): void
    {
        $this->alat($this->milikSaya, ['tanggal_jatuh_tempo' => now()->subDay()]);
        $this->alat($this->milikSaya, ['tanggal_jatuh_tempo' => now()->addDays(5)]);
        $this->alat($this->milikSaya, ['tanggal_jatuh_tempo' => now()->addYear()]);
        $this->alat($this->milikLain, ['tanggal_jatuh_tempo' => now()->subDay()]);

        $this->ambil('/beranda')
            ->assertOk()
            ->assertJsonPath('data.perusahaan.id', $this->milikSaya->id)
            ->assertJsonPath('data.ringkasan.jumlah_alat', 3)
            ->assertJsonPath('data.ringkasan.lewat_jatuh_tempo', 1)
            ->assertJsonPath('data.ringkasan.segera_jatuh_tempo', 1)
            ->assertJsonCount(2, 'data.perlu_perhatian');
    }

    public function test_token_aplikasi_internal_ditolak_di_rute_data_pelanggan(): void
    {
        $this->permintaanBaru();

        $this->withHeaders($this->bearer($this->pic, 'internal'))
            ->getJson(self::V1.'/beranda')
            ->assertForbidden();
    }

    // --- /notifikasi -------------------------------------------------------

    public function test_notifikasi_cuma_milik_akun_sendiri(): void
    {
        $orangLain = $this->anggota();
        $punyaSaya = $this->pic->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'uji',
            'data' => ['title' => 'Alat Anda jatuh tempo', 'kategori' => 'jatuh_tempo'],
        ]);
        $punyaLain = $orangLain->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'uji',
            'data' => ['title' => 'Milik orang lain'],
        ]);

        $this->ambil('/notifikasi')
            ->assertOk()
            ->assertJsonPath('meta.belum_dibaca', 1)
            ->assertJsonPath('data.0.id', $punyaSaya->id)
            ->assertJsonCount(1, 'data');

        $this->permintaanBaru();
        $this->withHeaders($this->bearer($this->pic))
            ->postJson(self::V1."/notifikasi/{$punyaLain->id}/dibaca")
            ->assertNotFound();

        $this->permintaanBaru();
        $this->withHeaders($this->bearer($this->pic))
            ->postJson(self::V1."/notifikasi/{$punyaSaya->id}/dibaca")
            ->assertOk()
            ->assertJsonPath('data.dibaca', true);

        $this->ambil('/notifikasi/jumlah')->assertJsonPath('data.belum_dibaca', 0);
    }

    // --- /perangkat + saringan aplikasi di SaluranPush ---------------------

    public function test_perangkat_pelanggan_terdaftar_sebagai_aplikasi_pelanggan(): void
    {
        $this->permintaanBaru();
        $this->withHeaders($this->bearer($this->pic))
            ->postJson(self::V1.'/perangkat', ['token' => 'tok-hp-pelanggan', 'platform' => 'android'])
            ->assertCreated();

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $this->pic->id,
            'token' => 'tok-hp-pelanggan',
            'aplikasi' => DeviceToken::APLIKASI_PELANGGAN,
        ]);

        $this->permintaanBaru();
        $this->withHeaders($this->bearer($this->pic))
            ->deleteJson(self::V1.'/perangkat', ['token' => 'tok-hp-pelanggan'])
            ->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'tok-hp-pelanggan']);
    }

    public function test_push_pelanggan_tidak_mendarat_di_token_aplikasi_internal(): void
    {
        $terkirim = [];
        $this->app->instance(PengirimPush::class, new class($terkirim) implements PengirimPush
        {
            public function __construct(private array &$terkirim) {}

            public function kirim(DeviceToken $perangkat, string $judul, string $isi, array $data = []): bool
            {
                $this->terkirim[] = $perangkat->token;

                return true;
            }

            public function tokenMati(): bool
            {
                return false;
            }
        });

        DeviceToken::catat($this->pic, 'tok-pelanggan', 'android', null, DeviceToken::APLIKASI_PELANGGAN);
        // Baris lama tanpa aplikasi eksplisit jatuh ke `internal` (default kolom).
        DeviceToken::catat($this->pic, 'tok-internal-nyasar', 'android');

        $alat = $this->alat($this->milikSaya, ['tanggal_jatuh_tempo' => now()->addDays(7)]);
        $this->pic->notify(AlatAndaJatuhTempo::dariAlat($this->milikSaya, collect([$alat])));

        $this->assertSame(['tok-pelanggan'], $terkirim);
    }
}
