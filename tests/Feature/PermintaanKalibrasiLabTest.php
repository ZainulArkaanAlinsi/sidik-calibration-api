<?php

namespace Tests\Feature;

use App\Events\PerubahanDataOrganisasi;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PermintaanKalibrasi;
use App\Models\PreferensiNotifikasiAnggota;
use App\Models\User;
use App\Notifications\Pelanggan\PermintaanDiputuskan;
use App\Notifications\Pelanggan\PesanLabBaru;
use App\Services\MatriksIzin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Sisi LAB permintaan kalibrasi: antrean, terima (lahir order), tolak, balas.
 *
 * Yang dijaga:
 *
 * 1. Menerima melahirkan Order + OrderItem yang BENAR, memakai penomoran
 *    order yang sama dengan meja penerimaan, dan Equipment untuk alat baru —
 *    semuanya utuh atau tidak sama sekali.
 * 2. Menolak WAJIB beralasan, dan alasannya sampai ke pelanggan.
 * 3. Teknisi & viewer 403; super admin membaca tapi tidak menulis; lab lain 404
 *    SEBELUM validasi (422 untuk id lab lain sudah mengakui barisnya ada).
 */
class PermintaanKalibrasiLabTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private User $admin;

    private User $pic;

    private Customer $perusahaan;

    private EquipmentCategory $kategori;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        $this->admin = $this->adminLab();
        $this->pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $this->perusahaan = $this->pic->keanggotaan()->first()->customer;
        $this->kategori = EquipmentCategory::factory()->create(['organization_id' => $this->organisasi()->id]);
    }

    private function sebagai(User $user): void
    {
        // Token sungguhan ber-ability `internal` (bukan `Sanctum::actingAs`, yang
        // tanpa ability dan ditolak `aplikasi:internal`). `withHeaders` menempel
        // ke request berikutnya sampai `sebagai()` dipanggil lagi.
        $this->permintaanBaru();
        $this->withHeaders($this->bearerInternal($user));
    }

    private function ajuan(array $tambahan = [], bool $denganAlatBaru = false): PermintaanKalibrasi
    {
        $p = PermintaanKalibrasi::create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => $this->perusahaan->id,
            'diajukan_oleh' => $this->pic->id,
            'nomor' => 'PMT/'.now()->format('Y/m').'/'.str_pad((string) (PermintaanKalibrasi::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => PermintaanKalibrasi::STATUS_BARU,
            'metode_pengantaran' => 'diantar_sendiri',
            'catatan' => 'Mohon cepat.',
            ...$tambahan,
        ]);

        $p->items()->create(['equipment_id' => Equipment::factory()->create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => $this->perusahaan->id,
        ])->id]);

        if ($denganAlatBaru) {
            $p->items()->create(['alat_baru' => [
                'nama_alat' => 'pH Meter',
                'merk' => 'Hanna',
                'model' => 'HI2211',
                'serial_number' => 'HI2211-0419',
                'rentang_min' => 0,
                'rentang_maks' => 14,
                'satuan' => 'pH',
                'resolusi' => 0.01,
                'lokasi' => 'Lab QC',
                'catatan' => 'Elektroda baru.',
            ]]);
        }

        return $p->load('items');
    }

    /** @return array<string, mixed> */
    private function badanTerima(PermintaanKalibrasi $p, array $tambahan = []): array
    {
        $baru = $p->items->first(fn ($i) => $i->alatBaru());

        return [
            'alat_baru' => $baru ? [['item_id' => $baru->id, 'equipment_category_id' => $this->kategori->id]] : [],
            ...$tambahan,
        ];
    }

    private function anggotaTambahan(string $peran = CustomerMember::PERAN_STAF): User
    {
        $user = $this->pelanggan();
        CustomerMember::create([
            'organization_id' => $user->organization_id,
            'customer_id' => $this->perusahaan->id,
            'user_id' => $user->id,
            'peran' => $peran,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        return $user;
    }

    // ------------------------------------------------------------------ peran

    public function test_admin_membaca_teknisi_dan_viewer_403_super_admin_baca_saja(): void
    {
        $p = $this->ajuan();

        $this->sebagai($this->admin);
        $this->getJson('/api/permintaan-pelanggan')->assertOk();
        $this->getJson("/api/permintaan-pelanggan/{$p->id}")->assertOk();

        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $role) {
            $this->sebagai(User::factory()->create(['organization_id' => $this->organisasi()->id, 'role' => $role]));

            $this->getJson('/api/permintaan-pelanggan')->assertForbidden();
            $this->getJson("/api/permintaan-pelanggan/{$p->id}")->assertForbidden();
            $this->getJson("/api/permintaan-pelanggan/{$p->id}/pesan")->assertForbidden();
            $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima")->assertForbidden();
            $this->postJson("/api/permintaan-pelanggan/{$p->id}/tolak", ['alasan' => 'tidak sesuai'])->assertForbidden();
            $this->postJson("/api/permintaan-pelanggan/{$p->id}/pesan", ['isi' => 'halo'])->assertForbidden();
        }

        $superAdmin = User::factory()->create([
            'organization_id' => $this->organisasi()->id,
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
        $this->sebagai($superAdmin);

        $this->getJson('/api/permintaan-pelanggan')->assertOk();
        $this->getJson("/api/permintaan-pelanggan/{$p->id}")->assertOk();
        $this->getJson("/api/permintaan-pelanggan/{$p->id}/pesan")->assertOk();
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima")->assertForbidden();
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/tolak", ['alasan' => 'tidak sesuai'])->assertForbidden();
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/pesan", ['isi' => 'halo'])->assertForbidden();

        $this->assertSame('baru', $p->fresh()->status);
    }

    public function test_token_pelanggan_ditolak_di_rute_lab(): void
    {
        $this->permintaanBaru();

        $this->withHeaders($this->bearer($this->pic))
            ->getJson('/api/permintaan-pelanggan')
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------ baca

    public function test_antrean_bisa_disaring_dan_hanya_memuat_lab_sendiri(): void
    {
        $this->ajuan();
        $this->ajuan(['status' => PermintaanKalibrasi::STATUS_DITOLAK, 'alasan_penolakan' => 'x']);

        $labLain = Organization::factory()->create();
        $pelangganLain = Customer::factory()->create(['organization_id' => $labLain->id, 'nama' => 'PT Lab Lain']);
        PermintaanKalibrasi::create([
            'organization_id' => $labLain->id,
            'customer_id' => $pelangganLain->id,
            'nomor' => 'PMT/2026/09/7777',
            'status' => 'baru',
            'metode_pengantaran' => 'diantar_sendiri',
        ]);

        $this->sebagai($this->admin);

        $semua = $this->getJson('/api/permintaan-pelanggan')->assertOk();
        $semua->assertJsonCount(2, 'data')->assertJsonPath('meta.jumlah_baru', 1);
        $this->assertStringNotContainsString('7777', $semua->getContent());
        $this->assertStringNotContainsString('PT Lab Lain', $semua->getContent());

        $this->getJson('/api/permintaan-pelanggan?status=baru')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/permintaan-pelanggan?status=ditolak')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/permintaan-pelanggan?status=ngawur')->assertUnprocessable();
        $this->getJson('/api/permintaan-pelanggan?customer_id='.$this->perusahaan->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/permintaan-pelanggan?customer_id='.$pelangganLain->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/permintaan-pelanggan?search='.urlencode($this->perusahaan->nama))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_detail_memuat_pemohon_dan_petunjuk_alat_baru(): void
    {
        $p = $this->ajuan([], denganAlatBaru: true);

        $this->sebagai($this->admin);

        $respons = $this->getJson("/api/permintaan-pelanggan/{$p->id}")->assertOk();

        $respons->assertJsonPath('data.customer.nama', $this->perusahaan->nama)
            ->assertJsonPath('data.pemohon.email', $this->pic->email)
            ->assertJsonPath('data.jumlah_alat', 2)
            ->assertJsonPath('data.alat.0.perlu_kategori', false)
            ->assertJsonPath('data.alat.1.baru', true)
            ->assertJsonPath('data.alat.1.perlu_kategori', true)
            ->assertJsonPath('data.alat.1.perlu_nomor_seri', false)
            ->assertJsonPath('data.alat.1.serial_number', 'HI2211-0419');
    }

    public function test_lab_lain_dijawab_404_sebelum_validasi(): void
    {
        $labLain = Organization::factory()->create();
        $pelangganLain = Customer::factory()->create(['organization_id' => $labLain->id]);
        $milikLain = PermintaanKalibrasi::create([
            'organization_id' => $labLain->id,
            'customer_id' => $pelangganLain->id,
            'nomor' => 'PMT/2026/09/0801',
            'status' => 'baru',
            'metode_pengantaran' => 'diantar_sendiri',
        ]);

        $this->sebagai($this->admin);

        $this->getJson("/api/permintaan-pelanggan/{$milikLain->id}")->assertNotFound();
        $this->getJson("/api/permintaan-pelanggan/{$milikLain->id}/pesan")->assertNotFound();
        // Body KOSONG: kalau validasi jalan duluan ini 422, dan 422 mengakui barisnya ada.
        $this->postJson("/api/permintaan-pelanggan/{$milikLain->id}/terima")->assertNotFound();
        $this->postJson("/api/permintaan-pelanggan/{$milikLain->id}/tolak")->assertNotFound();
        $this->postJson("/api/permintaan-pelanggan/{$milikLain->id}/pesan")->assertNotFound();

        $this->assertSame('baru', $milikLain->fresh()->status);
    }

    // ------------------------------------------------------------------ terima

    public function test_terima_melahirkan_order_item_dan_alat_baru(): void
    {
        $p = $this->ajuan([], denganAlatBaru: true);
        $alatLama = $p->items->first()->equipment_id;
        $sebelumAlat = Equipment::count();

        $this->sebagai($this->admin);

        $respons = $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima", $this->badanTerima($p, [
            'tanggal_masuk' => '2026-10-02',
            'tanggal_janji_selesai' => '2026-10-20',
            'catatan' => 'Prioritas.',
        ]))->assertOk();

        $respons->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.diputuskan_oleh.id', $this->admin->id)
            ->assertJsonPath('data.order.status', 'baru');

        $p->refresh();
        $order = Order::findOrFail($p->order_id);

        // Order: milik lab & pelanggan yang sama, diterima admin yang memutuskan,
        // nomor dari penomoran order yang sama dengan meja penerimaan.
        $this->assertSame($this->perusahaan->organization_id, $order->organization_id);
        $this->assertSame($this->perusahaan->id, $order->customer_id);
        $this->assertSame($this->admin->id, $order->diterima_oleh);
        $this->assertSame(Order::STATUS_BARU, $order->status);
        $this->assertMatchesRegularExpression('#^ORD/'.now()->format('Y/m').'/0001$#', $order->nomor);
        $this->assertSame('2026-10-02', $order->tanggal_masuk->toDateString());
        $this->assertSame('2026-10-20', $order->tanggal_janji_selesai->toDateString());
        $this->assertStringContainsString($p->nomor, (string) $order->catatan);
        $this->assertStringContainsString('Mohon cepat.', (string) $order->catatan);
        $this->assertStringContainsString('Prioritas.', (string) $order->catatan);

        // Dua OrderItem: alat lama dan alat yang baru dibuat.
        $this->assertSame(2, $order->items()->count());
        $this->assertSame($sebelumAlat + 1, Equipment::count());

        $baru = Equipment::query()->where('serial_number', 'HI2211-0419')->firstOrFail();
        $this->assertSame($this->perusahaan->id, $baru->customer_id);
        $this->assertSame($this->perusahaan->organization_id, $baru->organization_id);
        $this->assertSame($this->kategori->id, $baru->equipment_category_id);
        $this->assertSame('pH Meter', $baru->nama_alat);
        $this->assertSame('Hanna', $baru->merk);
        $this->assertSame('HI2211', $baru->model);
        $this->assertSame('pH', $baru->satuan);
        $this->assertEquals(14.0, $baru->range_max);
        $this->assertEquals(0.01, $baru->resolusi);
        $this->assertSame(Equipment::STATUS_AKTIF, $baru->status);
        $this->assertStringContainsString($p->nomor, (string) $baru->catatan);

        $idAlatDiOrder = $order->items()->pluck('equipment_id')->sort()->values()->all();
        $this->assertSame(collect([$alatLama, $baru->id])->sort()->values()->all(), $idAlatDiOrder);

        // Tiap item ajuan tertaut ke baris paketnya.
        foreach ($p->items()->get() as $item) {
            $this->assertNotNull($item->equipment_id);
            $this->assertNotNull($item->order_item_id);
            $this->assertSame($item->equipment_id, $item->orderItem->equipment_id);
        }
    }

    public function test_penomoran_order_berlanjut_dari_order_meja_penerimaan(): void
    {
        Order::factory()->create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => $this->perusahaan->id,
            'nomor' => 'ORD/'.now()->format('Y/m').'/0007',
        ]);
        $p = $this->ajuan();

        $this->sebagai($this->admin);
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima")->assertOk();

        $this->assertSame('ORD/'.now()->format('Y/m').'/0008', Order::findOrFail($p->fresh()->order_id)->nomor);
    }

    public function test_terima_tanpa_kategori_untuk_alat_baru_ditolak_dan_tidak_menulis_apa_pun(): void
    {
        $p = $this->ajuan([], denganAlatBaru: true);
        $alat = Equipment::count();

        $this->sebagai($this->admin);

        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima")
            ->assertUnprocessable();

        $this->assertSame('baru', $p->fresh()->status);
        $this->assertSame(0, Order::count());
        $this->assertSame($alat, Equipment::count());
    }

    public function test_kategori_dari_lab_lain_ditolak(): void
    {
        $p = $this->ajuan([], denganAlatBaru: true);
        $kategoriLain = EquipmentCategory::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $item = $p->items->first(fn ($i) => $i->alatBaru());

        $this->sebagai($this->admin);

        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima", [
            'alat_baru' => [['item_id' => $item->id, 'equipment_category_id' => $kategoriLain->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['alat_baru.0.equipment_category_id']);

        $this->assertSame(0, Order::count());
    }

    public function test_item_dari_permintaan_lain_ditolak(): void
    {
        $p = $this->ajuan([], denganAlatBaru: true);
        $lain = $this->ajuan([], denganAlatBaru: true);
        $itemLain = $lain->items->first(fn ($i) => $i->alatBaru());

        $this->sebagai($this->admin);

        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima", [
            'alat_baru' => [['item_id' => $itemLain->id, 'equipment_category_id' => $this->kategori->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['alat_baru.0.item_id']);
    }

    public function test_alat_baru_tanpa_nomor_seri_diminta_dilengkapi_admin(): void
    {
        $p = $this->ajuan();
        $p->items()->create(['alat_baru' => ['nama_alat' => 'Timbangan tanpa pelat nama']]);
        $p->load('items');
        $item = $p->items->first(fn ($i) => $i->alatBaru());

        $this->sebagai($this->admin);

        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima", $this->badanTerima($p))
            ->assertUnprocessable();
        $this->assertSame(0, Order::count());

        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima", [
            'alat_baru' => [[
                'item_id' => $item->id,
                'equipment_category_id' => $this->kategori->id,
                'serial_number' => 'ADMIN-ISI-001',
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('equipments', ['serial_number' => 'ADMIN-ISI-001', 'customer_id' => $this->perusahaan->id]);
    }

    public function test_nomor_seri_yang_bentrok_di_lab_dijawab_422_bukan_500(): void
    {
        // Bentrok dengan alat PERUSAHAAN LAIN di lab yang sama — yang tidak
        // bisa dicegah waktu pelanggan mengajukan tanpa membocorkannya.
        Equipment::factory()->create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->perusahaan->organization_id])->id,
            'serial_number' => 'HI2211-0419',
        ]);
        $p = $this->ajuan([], denganAlatBaru: true);

        $this->sebagai($this->admin);

        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima", $this->badanTerima($p))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['alat_baru.'.$p->items->first(fn ($i) => $i->alatBaru())->id.'.serial_number']);

        $this->assertSame('baru', $p->fresh()->status);
        $this->assertSame(0, Order::count());
    }

    public function test_terima_kedua_kali_ditolak_dan_tidak_membuat_order_kembar(): void
    {
        $p = $this->ajuan();
        $adminLain = User::factory()->admin()->create(['organization_id' => $this->organisasi()->id]);

        $this->sebagai($this->admin);
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima")->assertOk();

        // Admin kedua yang terlambat — "siapa pun boleh memproses", tapi cuma sekali.
        $this->sebagai($adminLain);
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima")->assertUnprocessable();
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/tolak", ['alasan' => 'Terlambat menolak.'])->assertUnprocessable();

        $this->assertSame(1, Order::count());
        $this->assertSame('diterima', $p->fresh()->status);
    }

    public function test_yang_sudah_dibatalkan_pelanggan_tidak_bisa_diterima(): void
    {
        $p = $this->ajuan(['status' => PermintaanKalibrasi::STATUS_DIBATALKAN, 'dibatalkan_pada' => now()]);

        $this->sebagai($this->admin);

        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima")->assertUnprocessable();
        $this->assertSame(0, Order::count());
    }

    public function test_terima_mengabari_anggota_aktif_yang_saklarnya_menyala(): void
    {
        Notification::fake();

        $stafAktif = $this->anggotaTambahan();
        $stafMati = $this->anggotaTambahan();
        $stafNonaktifKeanggotaan = $this->anggotaTambahan();
        $perusahaanLain = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);

        PreferensiNotifikasiAnggota::create([
            'customer_member_id' => $stafMati->keanggotaan()->first()->id,
            'status_permintaan' => false,
        ]);
        $stafNonaktifKeanggotaan->keanggotaan()->first()->update(['status' => CustomerMember::STATUS_NONAKTIF]);

        $p = $this->ajuan();

        $this->sebagai($this->admin);
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima")->assertOk();

        Notification::assertSentTo([$this->pic, $stafAktif], PermintaanDiputuskan::class);
        Notification::assertNotSentTo([$stafMati, $stafNonaktifKeanggotaan, $perusahaanLain, $this->admin], PermintaanDiputuskan::class);
    }

    public function test_terima_menyiarkan_perubahan_ke_perangkat_lain(): void
    {
        Event::fake([PerubahanDataOrganisasi::class]);

        $p = $this->ajuan();

        $this->sebagai($this->admin);
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima")->assertOk();

        Event::assertDispatched(PerubahanDataOrganisasi::class, fn ($e) => $e->jenis === 'permintaan' && $e->aksi === 'diterima' && $e->id === $p->id);
        Event::assertDispatched(PerubahanDataOrganisasi::class, fn ($e) => $e->jenis === 'paket' && $e->aksi === 'dibuat');
    }

    public function test_notifikasi_gagal_tidak_menggagalkan_keputusan(): void
    {
        $p = $this->ajuan();

        Notification::shouldReceive('send')->andThrow(new \RuntimeException('Reverb mati'));

        $this->sebagai($this->admin);
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/terima")->assertOk();

        $this->assertSame('diterima', $p->fresh()->status);
        $this->assertSame(1, Order::count());
    }

    // ------------------------------------------------------------------ tolak

    public function test_tolak_wajib_beralasan(): void
    {
        $p = $this->ajuan();

        $this->sebagai($this->admin);

        $this->postJson("/api/permintaan-pelanggan/{$p->id}/tolak")
            ->assertUnprocessable()->assertJsonValidationErrors(['alasan']);
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/tolak", ['alasan' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors(['alasan']);
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/tolak", ['alasan' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['alasan']);

        $this->assertSame('baru', $p->fresh()->status);
    }

    public function test_tolak_menyimpan_alasan_yang_dibaca_pelanggan(): void
    {
        Notification::fake();

        $p = $this->ajuan();

        $this->sebagai($this->admin);
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/tolak", ['alasan' => 'Jenis alat di luar ruang lingkup akreditasi.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.alasan_penolakan', 'Jenis alat di luar ruang lingkup akreditasi.');

        $this->assertSame(0, Order::count());
        $this->assertSame($this->admin->id, $p->fresh()->diputuskan_oleh);

        Notification::assertSentTo($this->pic, PermintaanDiputuskan::class, function ($n) use ($p): bool {
            $data = $n->toDatabase($this->pic);

            return $data['kategori'] === 'pelanggan.permintaan_ditolak'
                && str_contains($data['body'], 'Jenis alat di luar ruang lingkup akreditasi.')
                && str_contains($data['body'], $p->nomor);
        });

        // Dan pelanggan membacanya lewat API-nya sendiri.
        $this->permintaanBaru();
        $this->withHeaders($this->bearer($this->pic))
            ->getJson("/api/pelanggan/v1/permintaan/{$p->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.alasan_penolakan', 'Jenis alat di luar ruang lingkup akreditasi.');
    }

    public function test_tolak_dihormati_saklar_status_permintaan(): void
    {
        Notification::fake();

        PreferensiNotifikasiAnggota::create([
            'customer_member_id' => $this->pic->keanggotaan()->first()->id,
            'status_permintaan' => false,
        ]);
        $p = $this->ajuan();

        $this->sebagai($this->admin);
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/tolak", ['alasan' => 'Tidak sesuai ruang lingkup.'])->assertOk();

        Notification::assertNotSentTo($this->pic, PermintaanDiputuskan::class);
        $this->assertSame('ditolak', $p->fresh()->status);
    }

    // ------------------------------------------------------------------ pesan

    public function test_balasan_lab_mengabari_anggota_dan_dihormati_saklar_pesan(): void
    {
        Notification::fake();

        $p = $this->ajuan();
        $stafMati = $this->anggotaTambahan();
        PreferensiNotifikasiAnggota::create([
            'customer_member_id' => $stafMati->keanggotaan()->first()->id,
            'pesan_lab' => false,
        ]);

        $this->sebagai($this->admin);
        $this->postJson("/api/permintaan-pelanggan/{$p->id}/pesan", ['isi' => 'Alat sudah kami terima semua ya.'])
            ->assertCreated()
            ->assertJsonPath('data.sisi', 'lab')
            ->assertJsonPath('data.pengirim.id', $this->admin->id);

        Notification::assertSentTo($this->pic, PesanLabBaru::class);
        Notification::assertNotSentTo($stafMati, PesanLabBaru::class);

        $this->getJson("/api/permintaan-pelanggan/{$p->id}/pesan")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.isi', 'Alat sudah kami terima semua ya.')
            ->assertJsonPath('meta.percakapan_terbuka', true);
    }

    public function test_pesan_lab_ke_permintaan_yang_ditolak_ditutup(): void
    {
        $p = $this->ajuan(['status' => PermintaanKalibrasi::STATUS_DITOLAK, 'alasan_penolakan' => 'x']);

        $this->sebagai($this->admin);

        $this->postJson("/api/permintaan-pelanggan/{$p->id}/pesan", ['isi' => 'Halo?'])->assertUnprocessable();
        $this->getJson("/api/permintaan-pelanggan/{$p->id}/pesan")->assertOk()->assertJsonPath('meta.percakapan_terbuka', false);
    }

    public function test_pesan_lab_wajib_berisi(): void
    {
        $p = $this->ajuan();

        $this->sebagai($this->admin);

        $this->postJson("/api/permintaan-pelanggan/{$p->id}/pesan", [])->assertUnprocessable()->assertJsonValidationErrors(['isi']);
    }

    // ------------------------------------------------------------------ gerbang

    public function test_limiter_lab_terdaftar(): void
    {
        foreach (['permintaan-putus', 'permintaan-pesan'] as $nama) {
            $this->assertNotNull(RateLimiter::limiter($nama), "Limiter {$nama} belum terdaftar.");
        }
    }

    public function test_matriks_izin_memuat_permintaan_untuk_admin_dan_baca_saja_untuk_super_admin(): void
    {
        $matriks = app(MatriksIzin::class);

        $admin = $matriks->bolehUntuk(User::ROLE_ADMIN);
        $this->assertContains('permintaan.lihat', $admin);
        $this->assertContains('permintaan.putuskan', $admin);
        $this->assertContains('permintaan.balas', $admin);

        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $role) {
            $boleh = $matriks->bolehUntuk($role);
            $this->assertNotContains('permintaan.lihat', $boleh);
            $this->assertNotContains('permintaan.putuskan', $boleh);
        }

        $superAdmin = $matriks->bolehUntuk(User::ROLE_SUPER_ADMIN);
        $this->assertContains('permintaan.lihat', $superAdmin);
        $this->assertNotContains('permintaan.putuskan', $superAdmin);
        $this->assertNotContains('permintaan.balas', $superAdmin);
    }
}
