<?php

namespace Tests\Feature\Pelanggan;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PermintaanKalibrasi;
use App\Models\User;
use App\Notifications\PermintaanKalibrasiBaru;
use App\Notifications\PesanPermintaanDariPelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Sisi PELANGGAN permintaan kalibrasi: ajukan, lihat, batalkan, berbincang.
 *
 * Yang paling dijaga, urut dari yang paling mahal kalau salah:
 *
 * 1. `customer_id` TIDAK PERNAH dari body — ajuan atas nama perusahaan lain lewat
 *    body harus tetap jatuh ke perusahaan si pemanggil.
 * 2. Permintaan perusahaan lain dijawab 404, bukan 403/422 (BR-02). Sapuan
 *    ber-ID lengkapnya di `IsolasiPerusahaanTest`; di sini cuma kasus utamanya.
 * 3. Notifikasi ke lab TIDAK boleh menggagalkan ajuan yang sudah tersimpan —
 *    pelanggan yang dijawab 500 menekan Ajukan lagi dan lahir ajuan kembar.
 */
class PermintaanKalibrasiPelangganTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private User $pic;

    private Customer $perusahaan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        $this->pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $this->perusahaan = $this->pic->keanggotaan()->first()->customer;
    }

    private function alat(?Customer $milik = null, array $tambahan = []): Equipment
    {
        $milik ??= $this->perusahaan;

        return Equipment::factory()->create([
            'organization_id' => $milik->organization_id,
            'customer_id' => $milik->id,
            ...$tambahan,
        ]);
    }

    /** @return array<string, mixed> */
    private function badanAjuan(array $tambahan = []): array
    {
        return [
            'metode_pengantaran' => 'diantar_sendiri',
            'catatan' => 'Tolong diprioritaskan.',
            'alat_id' => [$this->alat()->id],
            ...$tambahan,
        ];
    }

    private function kirim(User $user, string $method, string $uri, array $badan = [], array $header = [])
    {
        $this->permintaanBaru();

        return $this->withHeaders($this->bearer($user) + $header)->json($method, '/api/pelanggan/v1'.$uri, $badan);
    }

    private function adminAktif(): User
    {
        return User::factory()->admin()->create(['organization_id' => $this->organisasi()->id]);
    }

    private function ajuanSaya(string $status = PermintaanKalibrasi::STATUS_BARU): PermintaanKalibrasi
    {
        $p = PermintaanKalibrasi::create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => $this->perusahaan->id,
            'diajukan_oleh' => $this->pic->id,
            'nomor' => 'PMT/'.now()->format('Y/m').'/'.str_pad((string) (PermintaanKalibrasi::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => $status,
            'metode_pengantaran' => 'diantar_sendiri',
        ]);
        $p->items()->create(['equipment_id' => $this->alat()->id]);

        return $p;
    }

    // ------------------------------------------------------------------ ajukan

    public function test_ajukan_dengan_alat_terdaftar_lahir_berstatus_baru(): void
    {
        $alat = $this->alat();

        $respons = $this->kirim($this->pic, 'POST', '/permintaan', [
            'metode_pengantaran' => 'diambil_lab',
            'tanggal_diinginkan_dari' => now()->addDays(3)->toDateString(),
            'tanggal_diinginkan_sampai' => now()->addDays(7)->toDateString(),
            'catatan' => 'Hubungi Pak Budi dulu.',
            'alat_id' => [$alat->id],
        ])->assertCreated();

        $respons->assertJsonPath('data.status', 'baru')
            ->assertJsonPath('data.metode_pengantaran', 'diambil_lab')
            ->assertJsonPath('data.jumlah_alat', 1)
            ->assertJsonPath('data.alat.0.alat_id', $alat->id)
            ->assertJsonPath('data.alat.0.baru', false)
            ->assertJsonPath('data.dapat_dibatalkan', true);

        $this->assertMatchesRegularExpression(
            '#^PMT/'.now()->format('Y/m').'/0001$#',
            $respons->json('data.nomor'),
        );

        $this->assertDatabaseHas('permintaan_kalibrasi', [
            'customer_id' => $this->perusahaan->id,
            'organization_id' => $this->perusahaan->organization_id,
            'diajukan_oleh' => $this->pic->id,
            'status' => 'baru',
        ]);
    }

    public function test_customer_id_dari_body_diabaikan(): void
    {
        $lain = $this->anggota(CustomerMember::PERAN_PIC_UTAMA)->keanggotaan()->first()->customer;

        $this->kirim($this->pic, 'POST', '/permintaan', $this->badanAjuan([
            'customer_id' => $lain->id,
            'organization_id' => 999,
        ]))->assertCreated();

        $this->assertDatabaseHas('permintaan_kalibrasi', ['customer_id' => $this->perusahaan->id]);
        $this->assertDatabaseMissing('permintaan_kalibrasi', ['customer_id' => $lain->id]);
    }

    public function test_ajukan_dengan_alat_baru_tidak_membuat_baris_alat(): void
    {
        $sebelum = Equipment::count();

        $respons = $this->kirim($this->pic, 'POST', '/permintaan', [
            'metode_pengantaran' => 'diantar_sendiri',
            'alat_baru' => [[
                'nama_alat' => 'pH Meter',
                'merk' => 'Hanna',
                'model' => 'HI2211',
                'serial_number' => 'HI2211-0419',
                'rentang_min' => 0,
                'rentang_maks' => 14,
                'satuan' => 'pH',
                'resolusi' => 0.01,
                'lokasi' => 'Lab QC',
            ]],
        ])->assertCreated();

        // Alat baru baru lahir saat admin MENERIMA — ajuan yang ditolak tidak
        // boleh meninggalkan alat sampah yang ikut menyalakan alarm jatuh tempo.
        $this->assertSame($sebelum, Equipment::count());

        $respons->assertJsonPath('data.alat.0.baru', true)
            ->assertJsonPath('data.alat.0.alat_id', null)
            ->assertJsonPath('data.alat.0.nama', 'pH Meter')
            ->assertJsonPath('data.alat.0.serial', 'HI2211-0419');
    }

    public function test_ajukan_campuran_alat_terdaftar_dan_baru(): void
    {
        $this->kirim($this->pic, 'POST', '/permintaan', [
            'metode_pengantaran' => 'diantar_sendiri',
            'alat_id' => [$this->alat()->id],
            'alat_baru' => [['nama_alat' => 'Timbangan Ohaus']],
        ])->assertCreated()->assertJsonPath('data.jumlah_alat', 2);
    }

    public function test_staf_juga_boleh_mengajukan(): void
    {
        $staf = $this->pelanggan();
        CustomerMember::create([
            'organization_id' => $staf->organization_id,
            'customer_id' => $this->perusahaan->id,
            'user_id' => $staf->id,
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $this->kirim($staf, 'POST', '/permintaan', $this->badanAjuan())->assertCreated();
    }

    public function test_nomor_berurutan_per_bulan(): void
    {
        $a = $this->kirim($this->pic, 'POST', '/permintaan', $this->badanAjuan())->assertCreated()->json('data.nomor');
        $b = $this->kirim($this->pic, 'POST', '/permintaan', $this->badanAjuan())->assertCreated()->json('data.nomor');

        $this->assertStringEndsWith('/0001', $a);
        $this->assertStringEndsWith('/0002', $b);
    }

    public function test_ajuan_tanpa_alat_ditolak(): void
    {
        $this->kirim($this->pic, 'POST', '/permintaan', ['metode_pengantaran' => 'diantar_sendiri'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['alat_id']);

        $this->assertDatabaseCount('permintaan_kalibrasi', 0);
    }

    public function test_metode_pengantaran_wajib_dan_hanya_dua_nilai(): void
    {
        $this->kirim($this->pic, 'POST', '/permintaan', $this->badanAjuan(['metode_pengantaran' => 'teknisi_datang']))
            ->assertUnprocessable()->assertJsonValidationErrors(['metode_pengantaran']);

        $badan = $this->badanAjuan();
        unset($badan['metode_pengantaran']);

        $this->kirim($this->pic, 'POST', '/permintaan', $badan)
            ->assertUnprocessable()->assertJsonValidationErrors(['metode_pengantaran']);
    }

    public function test_alat_milik_perusahaan_lain_dijawab_sama_dengan_alat_yang_tidak_ada(): void
    {
        $lain = $this->anggota(CustomerMember::PERAN_PIC_UTAMA)->keanggotaan()->first()->customer;
        $alatLain = $this->alat($lain);

        // Pesan galatnya identik → tidak bisa dipakai menyisir ID alat pesaing.
        $punyaLain = $this->kirim($this->pic, 'POST', '/permintaan', ['metode_pengantaran' => 'diantar_sendiri', 'alat_id' => [$alatLain->id]])
            ->assertUnprocessable()->json('errors');
        $tidakAda = $this->kirim($this->pic, 'POST', '/permintaan', ['metode_pengantaran' => 'diantar_sendiri', 'alat_id' => [987654]])
            ->assertUnprocessable()->json('errors');

        $this->assertSame(['alat_id.0' => $punyaLain['alat_id.0']], ['alat_id.0' => $tidakAda['alat_id.0']]);
        $this->assertDatabaseCount('permintaan_kalibrasi', 0);
    }

    public function test_alat_nonaktif_tidak_bisa_diajukan(): void
    {
        $alat = $this->alat(null, ['status' => Equipment::STATUS_NONAKTIF]);

        $this->kirim($this->pic, 'POST', '/permintaan', ['metode_pengantaran' => 'diantar_sendiri', 'alat_id' => [$alat->id]])
            ->assertUnprocessable();
    }

    public function test_aturan_formulir_alat_baru(): void
    {
        // Rentang diisi → satuan wajib (02-SRS §11).
        $this->kirim($this->pic, 'POST', '/permintaan', [
            'metode_pengantaran' => 'diantar_sendiri',
            'alat_baru' => [['nama_alat' => 'pH Meter', 'rentang_min' => 0, 'rentang_maks' => 14]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['alat_baru.0.satuan']);

        // Nama alat wajib.
        $this->kirim($this->pic, 'POST', '/permintaan', [
            'metode_pengantaran' => 'diantar_sendiri',
            'alat_baru' => [['merk' => 'Hanna']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['alat_baru.0.nama_alat']);

        // Rentang maks tidak boleh di bawah min.
        $this->kirim($this->pic, 'POST', '/permintaan', [
            'metode_pengantaran' => 'diantar_sendiri',
            'alat_baru' => [['nama_alat' => 'X', 'rentang_min' => 10, 'rentang_maks' => 5, 'satuan' => 'pH']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['alat_baru.0.rentang_maks']);
    }

    public function test_serial_alat_baru_yang_sudah_ada_di_daftar_sendiri_ditolak(): void
    {
        $ada = $this->alat(null, ['serial_number' => 'SN-SUDAH-ADA']);

        $this->kirim($this->pic, 'POST', '/permintaan', [
            'metode_pengantaran' => 'diantar_sendiri',
            'alat_baru' => [['nama_alat' => 'Dobel', 'serial_number' => $ada->serial_number]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['alat_baru.0.serial_number']);
    }

    public function test_serial_alat_baru_yang_milik_perusahaan_lain_tidak_dibocorkan(): void
    {
        $lain = $this->anggota(CustomerMember::PERAN_PIC_UTAMA)->keanggotaan()->first()->customer;
        $alatLain = $this->alat($lain, ['serial_number' => 'SN-PUNYA-PESAING']);

        // Lolos dengan 201: bentrokannya diputuskan admin waktu menerima. Kalau
        // dijawab "sudah ada", pelanggan bisa memetakan alat pesaing.
        $this->kirim($this->pic, 'POST', '/permintaan', [
            'metode_pengantaran' => 'diantar_sendiri',
            'alat_baru' => [['nama_alat' => 'Sama', 'serial_number' => $alatLain->serial_number]],
        ])->assertCreated();
    }

    public function test_batas_jumlah_alat(): void
    {
        $banyak = array_map(fn (int $i): array => ['nama_alat' => "Alat {$i}"], range(1, 51));

        $this->kirim($this->pic, 'POST', '/permintaan', ['metode_pengantaran' => 'diantar_sendiri', 'alat_baru' => $banyak])
            ->assertUnprocessable();
    }

    public function test_tanggal_diinginkan_tidak_boleh_mundur(): void
    {
        $this->kirim($this->pic, 'POST', '/permintaan', $this->badanAjuan([
            'tanggal_diinginkan_dari' => now()->addDays(5)->toDateString(),
            'tanggal_diinginkan_sampai' => now()->addDays(2)->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['tanggal_diinginkan_sampai']);

        $this->kirim($this->pic, 'POST', '/permintaan', $this->badanAjuan([
            'tanggal_diinginkan_dari' => now()->subDays(2)->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['tanggal_diinginkan_dari']);
    }

    public function test_ajuan_mengabari_semua_admin_aktif_di_lab_itu_saja(): void
    {
        Notification::fake();

        $admin1 = $this->adminAktif();
        $admin2 = $this->adminAktif();
        $adminNonaktif = User::factory()->admin()->create([
            'organization_id' => $this->organisasi()->id,
            'status' => User::STATUS_NONAKTIF,
        ]);
        $teknisi = User::factory()->create([
            'organization_id' => $this->organisasi()->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ]);
        $adminLabLain = User::factory()->admin()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->kirim($this->pic, 'POST', '/permintaan', $this->badanAjuan())->assertCreated();

        Notification::assertSentTo([$admin1, $admin2], PermintaanKalibrasiBaru::class);
        Notification::assertNotSentTo([$adminNonaktif, $teknisi, $adminLabLain, $this->pic], PermintaanKalibrasiBaru::class);
    }

    public function test_notifikasi_yang_gagal_tidak_menggagalkan_ajuan(): void
    {
        $this->adminAktif();

        // Reverb mati melempar dari `Notification::send` SESUDAH data tersimpan.
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('Reverb mati'));

        $this->kirim($this->pic, 'POST', '/permintaan', $this->badanAjuan())->assertCreated();

        $this->assertDatabaseCount('permintaan_kalibrasi', 1);
    }

    // ------------------------------------------------------------------ baca

    public function test_daftar_memisahkan_aktif_dan_selesai_dan_hanya_milik_sendiri(): void
    {
        $this->ajuanSaya(PermintaanKalibrasi::STATUS_BARU);
        $this->ajuanSaya(PermintaanKalibrasi::STATUS_DITOLAK);
        $this->ajuanSaya(PermintaanKalibrasi::STATUS_DIBATALKAN);

        $lain = $this->anggota(CustomerMember::PERAN_PIC_UTAMA)->keanggotaan()->first()->customer;
        PermintaanKalibrasi::create([
            'organization_id' => $lain->organization_id,
            'customer_id' => $lain->id,
            'nomor' => 'PMT/2026/09/9999',
            'status' => 'baru',
            'metode_pengantaran' => 'diantar_sendiri',
        ]);

        $aktif = $this->kirim($this->pic, 'GET', '/permintaan')->assertOk();
        $aktif->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.jumlah.aktif', 1)
            ->assertJsonPath('meta.jumlah.selesai', 2);

        $this->kirim($this->pic, 'GET', '/permintaan?saring=selesai')->assertOk()->assertJsonCount(2, 'data');
        $this->kirim($this->pic, 'GET', '/permintaan?saring=semua')->assertOk()->assertJsonCount(3, 'data');

        $this->assertStringNotContainsString('9999', $this->kirim($this->pic, 'GET', '/permintaan?saring=semua')->getContent());
    }

    public function test_diterima_yang_paketnya_selesai_pindah_ke_riwayat(): void
    {
        $p = $this->ajuanSaya(PermintaanKalibrasi::STATUS_DITERIMA);
        $order = Order::factory()->create([
            'organization_id' => $p->organization_id,
            'customer_id' => $p->customer_id,
            'status' => 'diproses',
        ]);
        $p->update(['order_id' => $order->id]);

        $this->kirim($this->pic, 'GET', '/permintaan')->assertJsonCount(1, 'data');

        $order->update(['status' => 'selesai']);

        $this->kirim($this->pic, 'GET', '/permintaan')->assertJsonCount(0, 'data');
        $this->kirim($this->pic, 'GET', '/permintaan?saring=selesai')->assertJsonCount(1, 'data');
    }

    public function test_detail_milik_sendiri_dan_alasan_penolakan_hanya_saat_ditolak(): void
    {
        $baru = $this->ajuanSaya();
        $ditolak = $this->ajuanSaya(PermintaanKalibrasi::STATUS_DITOLAK);
        $ditolak->update(['alasan_penolakan' => 'Jenis alat di luar ruang lingkup akreditasi.']);

        $this->kirim($this->pic, 'GET', "/permintaan/{$baru->id}")
            ->assertOk()
            ->assertJsonPath('data.nomor', $baru->nomor)
            ->assertJsonMissingPath('data.alasan_penolakan');

        $this->kirim($this->pic, 'GET', "/permintaan/{$ditolak->id}")
            ->assertOk()
            ->assertJsonPath('data.alasan_penolakan', 'Jenis alat di luar ruang lingkup akreditasi.')
            ->assertJsonPath('data.dapat_dibatalkan', false);
    }

    public function test_detail_tidak_membawa_identitas_admin_atau_data_internal(): void
    {
        $admin = $this->adminAktif();
        $p = $this->ajuanSaya(PermintaanKalibrasi::STATUS_DITOLAK);
        $p->update(['alasan_penolakan' => 'Tidak sesuai.', 'diputuskan_oleh' => $admin->id]);

        $isi = $this->kirim($this->pic, 'GET', "/permintaan/{$p->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString($admin->name, $isi);
        $this->assertStringNotContainsString($admin->email, $isi);
        $this->assertStringNotContainsString('diputuskan_oleh', $isi);
        $this->assertStringNotContainsString('organization_id', $isi);
    }

    public function test_permintaan_perusahaan_lain_dijawab_404(): void
    {
        $lain = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $milikLain = PermintaanKalibrasi::create([
            'organization_id' => $lain->organization_id,
            'customer_id' => $lain->keanggotaan()->first()->customer_id,
            'nomor' => 'PMT/2026/09/0500',
            'status' => 'baru',
            'metode_pengantaran' => 'diantar_sendiri',
        ]);

        $this->kirim($this->pic, 'GET', "/permintaan/{$milikLain->id}")->assertNotFound();
        $this->kirim($this->pic, 'POST', "/permintaan/{$milikLain->id}/batal")->assertNotFound();
        $this->kirim($this->pic, 'GET', "/permintaan/{$milikLain->id}/pesan")->assertNotFound();
        $this->kirim($this->pic, 'POST', "/permintaan/{$milikLain->id}/pesan", ['isi' => 'halo'])->assertNotFound();

        $this->assertSame('baru', $milikLain->fresh()->status);
    }

    // ------------------------------------------------------------------ batal

    public function test_batal_selama_masih_baru(): void
    {
        $p = $this->ajuanSaya();

        $this->kirim($this->pic, 'POST', "/permintaan/{$p->id}/batal")
            ->assertOk()
            ->assertJsonPath('data.status', 'dibatalkan')
            ->assertJsonPath('data.dapat_dibatalkan', false);

        $this->assertNotNull($p->fresh()->dibatalkan_pada);
    }

    public function test_batal_dua_kali_ditolak(): void
    {
        $p = $this->ajuanSaya();

        $this->kirim($this->pic, 'POST', "/permintaan/{$p->id}/batal")->assertOk();
        $this->kirim($this->pic, 'POST', "/permintaan/{$p->id}/batal")->assertUnprocessable();
    }

    public function test_yang_sudah_diputuskan_lab_tidak_bisa_dibatalkan(): void
    {
        foreach ([PermintaanKalibrasi::STATUS_DITERIMA, PermintaanKalibrasi::STATUS_DITOLAK] as $status) {
            $p = $this->ajuanSaya($status);

            $this->kirim($this->pic, 'POST', "/permintaan/{$p->id}/batal")->assertUnprocessable();

            $this->assertSame($status, $p->fresh()->status);
        }
    }

    // ------------------------------------------------------------------ pesan

    public function test_pesan_dua_arah_dan_nama_admin_tidak_bocor(): void
    {
        $p = $this->ajuanSaya();
        $admin = $this->adminAktif();
        $p->pesan()->create(['pengirim_id' => $admin->id, 'sisi' => 'lab', 'isi' => 'Alat sudah kami terima.']);

        $this->kirim($this->pic, 'POST', "/permintaan/{$p->id}/pesan", ['isi' => 'Baik, terima kasih.'])
            ->assertCreated()
            ->assertJsonPath('data.sisi', 'pelanggan')
            ->assertJsonPath('data.dari_saya', true);

        $respons = $this->kirim($this->pic, 'GET', "/permintaan/{$p->id}/pesan")->assertOk();
        $respons->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.sisi', 'lab')
            ->assertJsonPath('data.0.dari_saya', false)
            ->assertJsonPath('data.1.isi', 'Baik, terima kasih.')
            ->assertJsonPath('meta.percakapan_terbuka', true);

        $this->assertStringStartsWith('Tim ', $respons->json('data.0.nama_pengirim'));
        $this->assertStringNotContainsString($admin->name, $respons->getContent());
        $this->assertStringNotContainsString($admin->email, $respons->getContent());
    }

    public function test_pesan_pelanggan_mengabari_admin_aktif(): void
    {
        Notification::fake();

        $p = $this->ajuanSaya();
        $admin = $this->adminAktif();

        $this->kirim($this->pic, 'POST', "/permintaan/{$p->id}/pesan", ['isi' => 'Bisa dijemput Jumat?'])->assertCreated();

        Notification::assertSentTo($admin, PesanPermintaanDariPelanggan::class);
    }

    public function test_isi_pesan_wajib_dan_dibatasi(): void
    {
        $p = $this->ajuanSaya();

        $this->kirim($this->pic, 'POST', "/permintaan/{$p->id}/pesan", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['isi']);
        $this->kirim($this->pic, 'POST', "/permintaan/{$p->id}/pesan", ['isi' => str_repeat('x', 2001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['isi']);
    }

    public function test_percakapan_ditutup_setelah_ditolak_atau_dibatalkan_tapi_masih_terbaca(): void
    {
        foreach ([PermintaanKalibrasi::STATUS_DITOLAK, PermintaanKalibrasi::STATUS_DIBATALKAN] as $status) {
            $p = $this->ajuanSaya($status);

            $this->kirim($this->pic, 'POST', "/permintaan/{$p->id}/pesan", ['isi' => 'Halo?'])->assertUnprocessable();
            $this->kirim($this->pic, 'GET', "/permintaan/{$p->id}/pesan")
                ->assertOk()->assertJsonPath('meta.percakapan_terbuka', false);
        }
    }

    public function test_percakapan_tetap_terbuka_setelah_diterima(): void
    {
        $p = $this->ajuanSaya(PermintaanKalibrasi::STATUS_DITERIMA);

        $this->kirim($this->pic, 'POST', "/permintaan/{$p->id}/pesan", ['isi' => 'Kapan selesai?'])->assertCreated();
    }

    public function test_tulis_dibatasi_throttle(): void
    {
        // Limiter terdaftar di AppServiceProvider: rute yang menyebut nama yang
        // tidak ada dianggap "tanpa batas" oleh Laravel, dan itu tidak berisik.
        $this->assertNotNull(RateLimiter::limiter('pelanggan-permintaan-tulis'));
        $this->assertNotNull(RateLimiter::limiter('pelanggan-preferensi'));
    }

    public function test_akun_yang_belum_diverifikasi_tidak_bisa_masuk_ke_permintaan(): void
    {
        $pending = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI);

        $this->kirim($pending, 'GET', '/permintaan')->assertForbidden();
        $this->kirim($pending, 'POST', '/permintaan', $this->badanAjuan())->assertForbidden();
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->permintaanBaru();
        $this->getJson('/api/pelanggan/v1/permintaan')->assertUnauthorized();
    }

    public function test_kategori_fixture_ada(): void
    {
        // Penjaga kecil: test lain bergantung pada `EquipmentCategory` yang dibuat
        // factory alat; kalau factory berubah, yang pertama tahu test ini.
        $this->alat();
        $this->assertGreaterThan(0, EquipmentCategory::count());
    }
}
