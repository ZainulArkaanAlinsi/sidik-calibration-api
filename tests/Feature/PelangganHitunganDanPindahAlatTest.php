<?php

namespace Tests\Feature;

use App\Exceptions\Pelanggan\AksiPelangganDitolak;
use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\UndanganPelanggan;
use App\Models\User;
use App\Services\MatriksIzin;
use App\Services\PemindahanAlatPelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Dua kebutuhan layar Pelanggan di aplikasi lab dari temuan data produksi
 * (18 Nov 2026): hitungan & penanda `tanpa_akun` di daftar/detail pelanggan, dan
 * `POST /customers/{c}/pindahkan-alat` (pratinjau + eksekusi).
 * Kontrak: `docs/perintah-frontend-super-admin.md` §3.6 dan §3.7.
 *
 * Yang dijaga dan kenapa:
 *
 * - **Hitungan = yang dilihat pelanggan**, dan tidak menambah query per baris.
 * - **Pratinjau tidak menulis apa pun**; eksekusi butuh konfirmasi + alasan.
 * - **Sertifikat tidak pernah disentuh**: nomor, snapshot, PDF utuh; yang ikut
 *   pindah hanya pemiliknya (lewat alat), dibuktikan dari sisi aplikasi pelanggan.
 * - **Isolasi**: PT/alat lab lain dijawab 404 SEBELUM validasi `alasan`/`konfirmasi`
 *   (422 lebih dulu dari 404 = bukti barisnya ada).
 * - **Jejak**: pelaku asli, peran, PT asal → tujuan, alasan.
 */
class PelangganHitunganDanPindahAlatTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private Organization $lab;

    private Customer $asal;

    private Customer $tujuan;

    private User $teknisi;

    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanJalurPelanggan();
        $this->lab = $this->organisasi();
        $this->asal = $this->perusahaan('PT Asal');
        $this->tujuan = $this->perusahaan('PT Tujuan');
        $this->teknisi = $this->adminLab(User::ROLE_TEKNISI);
    }

    private function alat(Customer $pt, array $tambahan = []): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $pt->organization_id,
            'customer_id' => $pt->id,
            ...$tambahan,
        ]);
    }

    private function sertifikat(Equipment $alat, array $tambahan = []): Certificate
    {
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $alat->organization_id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $this->teknisi->id,
        ]);

        return Certificate::factory()->create([
            'organization_id' => $alat->organization_id,
            'calibration_session_id' => $sesi->id,
            'nomor' => 'CAL/2026/11/'.str_pad((string) ++$this->nomor, 4, '0', STR_PAD_LEFT),
            ...$tambahan,
        ]);
    }

    private function jadiAnggota(Customer $pt, string $peran = CustomerMember::PERAN_STAF, ?User $akun = null, string $status = CustomerMember::STATUS_AKTIF): CustomerMember
    {
        return CustomerMember::create([
            'organization_id' => $pt->organization_id,
            'customer_id' => $pt->id,
            'user_id' => ($akun ?? $this->pelanggan())->id,
            'peran' => $peran,
            'status' => $status,
        ]);
    }

    private function pindah(User $pemanggil, Customer $asal, array $badan): TestResponse
    {
        return $this->actingAs($pemanggil)->postJson("/api/customers/{$asal->id}/pindahkan-alat", $badan);
    }

    // ===================================================== hitungan di daftar & detail

    public function test_daftar_dan_detail_membawa_hitungan_dan_penanda_tanpa_akun(): void
    {
        // PT asal: satu alat hidup (4 sertifikat berbagai status) + satu alat terhapus (1 terbit).
        $hidup = $this->alat($this->asal);
        $this->sertifikat($hidup);
        $this->sertifikat($hidup, ['status' => Certificate::STATUS_DIBATALKAN, 'dibatalkan_pada' => now()]);
        $this->sertifikat($hidup, ['status' => Certificate::STATUS_DITARIK, 'ditarik_pada' => now()]);
        $this->sertifikat($hidup, ['status' => Certificate::STATUS_GAGAL, 'diterbitkan_pada' => null]);
        $terhapus = $this->alat($this->asal);
        $this->sertifikat($terhapus);
        $terhapus->delete();

        $this->jadiAnggota($this->asal, CustomerMember::PERAN_PIC_UTAMA);
        $this->jadiAnggota($this->asal, CustomerMember::PERAN_STAF, null, CustomerMember::STATUS_NONAKTIF);

        UndanganPelanggan::factory()->create(['organization_id' => $this->lab->id, 'customer_id' => $this->asal->id]);
        UndanganPelanggan::factory()->kedaluwarsa()->create(['organization_id' => $this->lab->id, 'customer_id' => $this->asal->id]);
        UndanganPelanggan::factory()->sudahDipakai()->create(['organization_id' => $this->lab->id, 'customer_id' => $this->asal->id]);
        UndanganPelanggan::factory()->create(['organization_id' => $this->lab->id, 'customer_id' => $this->asal->id, 'dibatalkan_pada' => now()]);

        // PT tujuan: punya alat + sertifikat, TANPA satu pun anggota aktif.
        $this->sertifikat($this->alat($this->tujuan));
        $this->jadiAnggota($this->tujuan, CustomerMember::PERAN_STAF, null, CustomerMember::STATUS_NONAKTIF);

        $kosong = $this->perusahaan('PT Kosong');
        $hanyaAnggota = $this->perusahaan('PT Hanya Anggota');
        $this->jadiAnggota($hanyaAnggota);

        // Baris dengan nama_normal kosong (lahir lewat jalur tanpa model).
        DB::table('customers')->where('id', $kosong->id)->update(['nama_normal' => null]);

        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $peran) {
            $pemanggil = $this->adminLab($peran);
            $daftar = collect($this->actingAs($pemanggil)->getJson('/api/customers')->assertOk()->json('data'))->keyBy('id');

            $a = $daftar[$this->asal->id];
            $this->assertSame(1, $a['alat_count']);
            $this->assertSame(1, $a['jumlah_alat'], 'Kunci lama harus tetap ada dan sama.');
            $this->assertSame(3, $a['sertifikat_count']);
            $this->assertSame(1, $a['anggota_aktif_count']);
            $this->assertSame(1, $a['undangan_menunggu_count']);
            $this->assertFalse($a['tanpa_akun']);
            $this->assertFalse($a['nama_normal_kosong']);
            $this->assertSame('PT Asal', $a['nama']);

            $t = $daftar[$this->tujuan->id];
            $this->assertSame([1, 1, 0, 0], [$t['alat_count'], $t['sertifikat_count'], $t['anggota_aktif_count'], $t['undangan_menunggu_count']]);
            $this->assertTrue($t['tanpa_akun'], 'Punya alat & sertifikat tapi nol anggota aktif.');

            $k = $daftar[$kosong->id];
            $this->assertSame([0, 0, 0, 0], [$k['alat_count'], $k['sertifikat_count'], $k['anggota_aktif_count'], $k['undangan_menunggu_count']]);
            $this->assertFalse($k['tanpa_akun'], 'Tidak punya apa-apa untuk dilihat.');
            $this->assertTrue($k['nama_normal_kosong']);

            $this->assertFalse($daftar[$hanyaAnggota->id]['tanpa_akun']);

            // Detail = bentuk yang sama.
            $detail = $this->actingAs($pemanggil)->getJson("/api/customers/{$this->tujuan->id}")->assertOk()->json('data');
            $this->assertSame($t, $detail);
        }
    }

    public function test_sertifikat_count_sama_dengan_yang_dilihat_pelanggan(): void
    {
        $pic = $this->jadiAnggota($this->asal, CustomerMember::PERAN_PIC_UTAMA)->user;
        $alat = $this->alat($this->asal);
        $this->sertifikat($alat);
        $this->sertifikat($alat, ['status' => Certificate::STATUS_DIBATALKAN, 'dibatalkan_pada' => now()]);
        $this->sertifikat($alat, ['status' => Certificate::STATUS_DITARIK, 'ditarik_pada' => now()]);
        $this->sertifikat($alat, ['status' => Certificate::STATUS_MENUNGGU_GENERATE, 'diterbitkan_pada' => null]);
        $terhapus = $this->alat($this->asal);
        $this->sertifikat($terhapus);
        $terhapus->delete();

        $lab = $this->actingAs($this->adminLab())->getJson("/api/customers/{$this->asal->id}")->assertOk()->json('data.sertifikat_count');

        $this->permintaanBaru();
        $pelanggan = $this->withHeaders($this->bearer($pic))
            ->getJson('/api/pelanggan/v1/sertifikat?termasuk_digantikan=1')
            ->assertOk()
            ->json('meta.total');

        $this->assertSame(3, $lab);
        $this->assertSame($pelanggan, $lab);
    }

    public function test_hitungan_tidak_menambah_query_per_baris_dan_kunci_lama_tidak_berubah(): void
    {
        $admin = $this->adminLab();
        $this->sertifikat($this->alat($this->asal));

        $ukur = function () use ($admin): int {
            $this->permintaanBaru();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->getJson('/api/customers')->assertOk();
            $jumlah = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $jumlah;
        };

        $ukur(); // pemanasan
        $kecil = $ukur();

        foreach (range(1, 10) as $i) {
            $pt = $this->perusahaan("PT Banyak {$i}");
            $this->sertifikat($this->alat($pt));
            $this->jadiAnggota($pt);
        }

        $this->assertSame($kecil, $ukur(), 'Jumlah query tumbuh bersama jumlah PT: ada N+1.');

        $baris = $this->actingAs($admin)->getJson('/api/customers')->json('data.0');
        foreach (['id', 'nama', 'alamat', 'contact_person', 'telepon', 'email', 'jumlah_alat'] as $kunciLama) {
            $this->assertArrayHasKey($kunciLama, $baris);
        }
    }

    public function test_tambah_dan_ubah_pelanggan_tidak_membawa_hitungan_tapi_membawa_penanda_nama(): void
    {
        $admin = $this->adminLab();

        $baru = $this->actingAs($admin)->postJson('/api/customers', ['nama' => 'PT Baru Dibuat'])->assertCreated()->json('data');

        $this->assertArrayNotHasKey('sertifikat_count', $baru);
        $this->assertArrayNotHasKey('tanpa_akun', $baru);
        $this->assertFalse($baru['nama_normal_kosong']);

        // Menyimpan ulang memperbaiki baris yang nama_normal-nya kosong.
        DB::table('customers')->where('id', $baru['id'])->update(['nama_normal' => null]);
        $this->actingAs($admin)->getJson("/api/customers/{$baru['id']}")->assertOk()->assertJsonPath('data.nama_normal_kosong', true);
        $this->actingAs($admin)->putJson("/api/customers/{$baru['id']}", ['alamat' => 'Jl. Baru'])->assertOk();
        $this->actingAs($admin)->getJson("/api/customers/{$baru['id']}")->assertOk()->assertJsonPath('data.nama_normal_kosong', false);
    }

    public function test_hitungan_lab_lain_tidak_bocor(): void
    {
        $labLain = Organization::factory()->create(['nama' => 'Lab Lain']);
        $ptAsing = Customer::factory()->create(['organization_id' => $labLain->id, 'nama' => 'PT Asing']);
        $this->sertifikat($this->alat($ptAsing));

        $daftar = $this->actingAs($this->adminLab())->getJson('/api/customers')->assertOk()->json('data.*.nama');
        $this->assertNotContains('PT Asing', $daftar);

        $this->actingAs($this->adminLab())->getJson("/api/customers/{$ptAsing->id}")->assertNotFound();
    }

    // ===================================================== pindahkan-alat: pratinjau

    /** @return array{a1: Equipment, a2: Equipment, a3: Equipment, konsultan: User} */
    private function skenario(): array
    {
        $a1 = $this->alat($this->asal, ['serial_number' => 'SN-PINDAH-1']);
        $a2 = $this->alat($this->asal, ['serial_number' => 'SN-PINDAH-2']);
        $a3 = $this->alat($this->asal, ['serial_number' => 'SN-TETAP-3']);

        $this->sertifikat($a1);
        $this->sertifikat($a1, ['status' => Certificate::STATUS_DITARIK, 'ditarik_pada' => now()]);
        $this->sertifikat($a2, ['status' => Certificate::STATUS_DIBATALKAN, 'dibatalkan_pada' => now()]);
        $this->sertifikat($a3);

        $konsultan = $this->pelanggan();
        $this->jadiAnggota($this->asal, CustomerMember::PERAN_PIC_UTAMA, $this->pelanggan(User::STATUS_AKTIF, ['name' => 'Orang Asal']));
        $this->jadiAnggota($this->asal, CustomerMember::PERAN_STAF, $konsultan);
        $this->jadiAnggota($this->tujuan, CustomerMember::PERAN_PIC_UTAMA, $this->pelanggan(User::STATUS_AKTIF, ['name' => 'Orang Tujuan']));
        $this->jadiAnggota($this->tujuan, CustomerMember::PERAN_STAF, $konsultan);

        return compact('a1', 'a2', 'a3', 'konsultan');
    }

    public function test_pratinjau_menghitung_dampak_tanpa_menulis_apa_pun(): void
    {
        ['a1' => $a1, 'a2' => $a2, 'konsultan' => $konsultan] = $this->skenario();

        $order = Order::factory()->create(['organization_id' => $this->lab->id, 'customer_id' => $this->asal->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'equipment_id' => $a1->id]);

        $auditAlatSebelum = AuditLog::query()->where('entity_type', 'equipments')->count();

        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $peran) {
            $data = $this->pindah($this->adminLab($peran), $this->asal, [
                'alat_ids' => [$a2->id, $a1->id], 'ke_customer_id' => $this->tujuan->id, 'dry_run' => true,
            ])->assertOk()->json('data');

            $this->assertTrue($data['dry_run']);
            $this->assertSame(['id' => $this->asal->id, 'nama' => 'PT Asal', 'anggota_aktif_count' => 2], $data['dari']);
            $this->assertSame(['id' => $this->tujuan->id, 'nama' => 'PT Tujuan', 'anggota_aktif_count' => 2], $data['ke']);
            $this->assertSame(2, $data['jumlah_alat']);
            $this->assertSame([$a1->id, $a2->id], array_column($data['alat'], 'id'));
            $this->assertSame([2, 1], array_column($data['alat'], 'jumlah_sertifikat'));
            $this->assertSame(3, $data['sertifikat_terdampak']);
            $this->assertSame(2, $data['sertifikat_terlihat_pelanggan'], 'terbit + dibatalkan; yang ditarik tidak terlihat pelanggan');
            $this->assertSame(1, $data['paket_terkait_count']);

            // Konsultan ada di KEDUA PT: tidak mulai dan tidak berhenti melihat.
            $mulai = array_column($data['anggota_mulai_melihat'], 'id');
            $berhenti = array_column($data['anggota_berhenti_melihat'], 'id');
            $this->assertCount(1, $mulai);
            $this->assertCount(1, $berhenti);
            $this->assertNotContains($konsultan->id, $mulai);
            $this->assertNotContains($konsultan->id, $berhenti);
            $this->assertSame(['id', 'nama', 'email'], array_keys($data['anggota_mulai_melihat'][0]));

            $this->assertSame(
                ['paket_tidak_dipindah', 'arsip_tidak_dipindah', 'isi_sertifikat_tetap'],
                array_column($data['peringatan'], 'kode'),
            );
        }

        // TIDAK menulis apa pun: alat, audit, order.
        $this->assertSame($this->asal->id, $a1->fresh()->customer_id);
        $this->assertSame($this->asal->id, $a2->fresh()->customer_id);
        $this->assertSame($auditAlatSebelum, AuditLog::query()->where('entity_type', 'equipments')->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_ALAT_DIPINDAH_PT]);
        $this->assertSame($this->asal->id, $order->fresh()->customer_id);
    }

    // ===================================================== pindahkan-alat: eksekusi

    public function test_eksekusi_memindahkan_alat_sertifikat_tidak_disentuh_dan_jejaknya_lengkap(): void
    {
        ['a1' => $a1, 'a2' => $a2, 'a3' => $a3] = $this->skenario();
        $sertifikat = $this->sertifikat($a1, [
            'snapshot' => ['header' => ['owner_name' => 'PT Asal', 'serial_number' => 'SN-PINDAH-1']],
            'pdf_path' => 'arsip/sertifikat-asli.pdf',
        ]);
        $sebelum = (array) DB::table('certificates')->where('id', $sertifikat->id)->first();

        $pelaku = $this->adminLab(User::ROLE_SUPER_ADMIN);

        $this->pindah($pelaku, $this->asal, [
            'alat_ids' => [$a1->id, $a2->id], 'ke_customer_id' => $this->tujuan->id,
            'alasan' => '  PT salah ketik, pemilik sebenarnya PT Tujuan  ', 'konfirmasi' => true,
        ])
            ->assertOk()
            ->assertJsonPath('message', '2 alat dipindahkan ke PT Tujuan.')
            ->assertJsonPath('data.dry_run', false)
            ->assertJsonPath('data.jumlah_alat', 2)
            ->assertJsonPath('data.alat_ids', [$a1->id, $a2->id])
            ->assertJsonPath('data.sertifikat_terdampak', 4)
            ->assertJsonPath('data.dari.id', $this->asal->id)
            ->assertJsonPath('data.ke.id', $this->tujuan->id);

        $this->assertSame($this->tujuan->id, $a1->fresh()->customer_id);
        $this->assertSame($this->tujuan->id, $a2->fresh()->customer_id);
        $this->assertSame($this->asal->id, $a3->fresh()->customer_id, 'Alat yang tidak dipilih tidak boleh ikut.');

        // Sertifikat: SATU PUN kolom tidak berubah.
        $this->assertSame($sebelum, (array) DB::table('certificates')->where('id', $sertifikat->id)->first());

        // Jejak: baris `diubah` per alat (customer_id lama -> baru) + satu baris beralasan.
        foreach ([$a1, $a2] as $alat) {
            $diubah = AuditLog::query()
                ->where('entity_type', 'equipments')->where('entity_id', $alat->id)->where('action', AuditLog::ACTION_DIUBAH)
                ->latest('id')->firstOrFail();
            $this->assertSame($pelaku->id, (int) $diubah->changed_by);
            $this->assertSame($this->asal->id, (int) $diubah->old_data['customer_id']);
            $this->assertSame($this->tujuan->id, (int) $diubah->new_data['customer_id']);
        }

        $jejak = AuditLog::query()->where('action', AuditLog::ACTION_ALAT_DIPINDAH_PT)->sole();
        $this->assertSame($this->lab->id, $jejak->organization_id);
        $this->assertSame('customers', $jejak->entity_type);
        $this->assertSame($this->asal->id, (int) $jejak->entity_id);
        $this->assertSame($pelaku->id, (int) $jejak->changed_by);
        $this->assertSame($this->asal->id, $jejak->new_data['dari_customer_id']);
        $this->assertSame($this->tujuan->id, $jejak->new_data['ke_customer_id']);
        $this->assertSame([$a1->id, $a2->id], $jejak->new_data['alat_ids']);
        $this->assertSame(4, $jejak->new_data['sertifikat_terdampak']);
        $this->assertSame('super_admin', $jejak->new_data['peran_pelaku']);
        $this->assertSame('PT salah ketik, pemilik sebenarnya PT Tujuan', $jejak->new_data['alasan']);
        $this->assertStringContainsString('PT Asal', (string) $jejak->note);
        $this->assertStringContainsString('PT Tujuan', (string) $jejak->note);
    }

    public function test_sesudah_pindah_pelanggan_tujuan_melihat_dan_pelanggan_asal_tidak(): void
    {
        $a1 = $this->alat($this->asal, ['serial_number' => 'SN-LIHAT-1']);
        $sertifikat = $this->sertifikat($a1);
        $orangAsal = $this->jadiAnggota($this->asal, CustomerMember::PERAN_PIC_UTAMA)->user;
        $orangTujuan = $this->jadiAnggota($this->tujuan, CustomerMember::PERAN_PIC_UTAMA)->user;

        $lihat = function (User $orang) use ($a1, $sertifikat): array {
            $this->permintaanBaru();
            $alat = $this->withHeaders($this->bearer($orang))->getJson("/api/pelanggan/v1/alat/{$a1->id}")->status();
            $this->permintaanBaru();
            $sert = $this->withHeaders($this->bearer($orang))->getJson('/api/pelanggan/v1/sertifikat?termasuk_digantikan=1')->json('data.*.id');

            return [$alat, in_array($sertifikat->id, $sert, true)];
        };

        $this->assertSame([200, true], $lihat($orangAsal));
        $this->assertSame([404, false], $lihat($orangTujuan));

        $this->permintaanBaru();
        $this->pindah($this->adminLab(), $this->asal, [
            'alat_ids' => [$a1->id], 'ke_customer_id' => $this->tujuan->id, 'alasan' => 'Salah PT', 'konfirmasi' => true,
        ])->assertOk();

        $this->assertSame([404, false], $lihat($orangAsal));
        $this->assertSame([200, true], $lihat($orangTujuan));
    }

    public function test_eksekusi_butuh_alasan_dan_konfirmasi_dan_tidak_menulis_bila_kurang(): void
    {
        ['a1' => $a1] = $this->skenario();
        $admin = $this->adminLab();
        $dasar = ['alat_ids' => [$a1->id], 'ke_customer_id' => $this->tujuan->id];

        foreach ([[], ['alasan' => ''], ['alasan' => 'ab'], ['alasan' => str_repeat('x', 501)]] as $tambahan) {
            $this->pindah($admin, $this->asal, [...$dasar, 'konfirmasi' => true, ...$tambahan])
                ->assertStatus(422)->assertJsonValidationErrors('alasan');
        }

        foreach ([[], ['konfirmasi' => false], ['konfirmasi' => 'tidak']] as $tambahan) {
            $this->pindah($admin, $this->asal, [...$dasar, 'alasan' => 'Salah PT', ...$tambahan])
                ->assertStatus(422)->assertJsonPath('kode', 'konfirmasi_wajib');
        }

        $this->assertSame($this->asal->id, $a1->fresh()->customer_id);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_ALAT_DIPINDAH_PT]);
    }

    public function test_tujuan_sama_dan_alat_milik_pt_lain_ditolak_dengan_kode(): void
    {
        ['a1' => $a1] = $this->skenario();
        $lain = $this->perusahaan('PT Lain Lagi');
        $milikLain = $this->alat($lain);
        $admin = $this->adminLab();

        $this->pindah($admin, $this->asal, ['alat_ids' => [$a1->id], 'ke_customer_id' => $this->asal->id, 'dry_run' => true])
            ->assertStatus(422)->assertJsonPath('kode', 'pt_tujuan_sama');

        $this->pindah($admin, $this->asal, ['alat_ids' => [$a1->id, $milikLain->id], 'ke_customer_id' => $this->tujuan->id, 'dry_run' => true])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'alat_bukan_milik_pt_asal')
            ->assertJsonPath('data.alat_ids', [$milikLain->id]);

        $this->pindah($admin, $this->asal, ['alat_ids' => [$a1->id, $milikLain->id], 'ke_customer_id' => $this->tujuan->id, 'alasan' => 'Coba', 'konfirmasi' => true])
            ->assertStatus(422)->assertJsonPath('kode', 'alat_bukan_milik_pt_asal');

        $this->assertSame($this->asal->id, $a1->fresh()->customer_id);
        $this->assertSame($lain->id, $milikLain->fresh()->customer_id);
    }

    public function test_isian_rusak_dijawab_422_dan_pemindahan_tidak_jalan(): void
    {
        $admin = $this->adminLab();
        $a = $this->alat($this->asal);
        $tujuan = $this->tujuan->id;

        $rusak = [
            [],
            ['ke_customer_id' => $tujuan],
            ['alat_ids' => [$a->id]],
            ['alat_ids' => [], 'ke_customer_id' => $tujuan],
            ['alat_ids' => 'bukan-larik', 'ke_customer_id' => $tujuan],
            ['alat_ids' => ['abc'], 'ke_customer_id' => $tujuan],
            ['alat_ids' => [0], 'ke_customer_id' => $tujuan],
            ['alat_ids' => [$a->id, $a->id], 'ke_customer_id' => $tujuan],
            ['alat_ids' => range(1, 101), 'ke_customer_id' => $tujuan],
            ['alat_ids' => [$a->id], 'ke_customer_id' => 'x'],
            ['alat_ids' => [$a->id], 'ke_customer_id' => $tujuan, 'dry_run' => 'mungkin'],
        ];

        foreach ($rusak as $badan) {
            $this->pindah($this->adminLab(), $this->asal, $badan)->assertStatus(422);
        }

        $this->assertSame($this->asal->id, $a->fresh()->customer_id);
    }

    // ===================================================== pindahkan-alat: isolasi

    public function test_lab_lain_dijawab_404_sebelum_validasi_isi(): void
    {
        ['a1' => $a1] = $this->skenario();
        $labLain = Organization::factory()->create(['nama' => 'Lab Lain']);
        $ptAsing = Customer::factory()->create(['organization_id' => $labLain->id, 'nama' => 'PT Asing']);
        $ptAsing2 = Customer::factory()->create(['organization_id' => $labLain->id, 'nama' => 'PT Asing Dua']);
        $alatAsing = $this->alat($ptAsing);
        $admin = $this->adminLab(User::ROLE_SUPER_ADMIN);

        // Tanpa `alasan`/`konfirmasi`: kalau validasi mendahului penjaga, ini 422.
        // PT tujuan lab lain:
        $this->pindah($this->adminLab(User::ROLE_SUPER_ADMIN), $this->asal, ['alat_ids' => [$a1->id], 'ke_customer_id' => $ptAsing->id])->assertNotFound();
        $this->pindah($this->adminLab(User::ROLE_SUPER_ADMIN), $this->asal, ['alat_ids' => [$a1->id], 'ke_customer_id' => $ptAsing->id, 'dry_run' => true])->assertNotFound();
        // Alat lab lain:
        $this->pindah($this->adminLab(User::ROLE_SUPER_ADMIN), $this->asal, ['alat_ids' => [$alatAsing->id], 'ke_customer_id' => $this->tujuan->id])->assertNotFound();
        $this->pindah($this->adminLab(User::ROLE_SUPER_ADMIN), $this->asal, ['alat_ids' => [$a1->id, $alatAsing->id], 'ke_customer_id' => $this->tujuan->id, 'dry_run' => true])->assertNotFound();
        // PT asal lab lain:
        $this->pindah($this->adminLab(User::ROLE_SUPER_ADMIN), $ptAsing, ['alat_ids' => [$alatAsing->id], 'ke_customer_id' => $ptAsing2->id])->assertNotFound();
        $this->pindah($this->adminLab(User::ROLE_SUPER_ADMIN), $ptAsing, [])->assertNotFound();
        // Id yang tidak ada: jawaban yang SAMA.
        $this->pindah($this->adminLab(User::ROLE_SUPER_ADMIN), $this->asal, ['alat_ids' => [999999], 'ke_customer_id' => $this->tujuan->id])->assertNotFound();
        $this->pindah($this->adminLab(User::ROLE_SUPER_ADMIN), $this->asal, ['alat_ids' => [$a1->id], 'ke_customer_id' => 999999])->assertNotFound();
        $this->actingAs($admin)->postJson('/api/customers/999999/pindahkan-alat', [])->assertNotFound();

        // Alat terhapus lunak = tidak ada.
        $terhapus = $this->alat($this->asal);
        $terhapus->delete();
        $this->pindah($this->adminLab(User::ROLE_SUPER_ADMIN), $this->asal, ['alat_ids' => [$terhapus->id], 'ke_customer_id' => $this->tujuan->id])->assertNotFound();

        $this->assertSame($this->asal->id, $a1->fresh()->customer_id);
        $this->assertSame($ptAsing->id, $alatAsing->fresh()->customer_id);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_ALAT_DIPINDAH_PT]);
    }

    // ===================================================== pindahkan-alat: izin, race, throttle

    public function test_hanya_admin_dan_super_admin_boleh_memindahkan(): void
    {
        ['a1' => $a1] = $this->skenario();
        $badan = ['alat_ids' => [$a1->id], 'ke_customer_id' => $this->tujuan->id, 'alasan' => 'Coba', 'konfirmasi' => true];

        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $peran) {
            $this->pindah($this->adminLab($peran), $this->asal, $badan)->assertForbidden();
            $this->pindah($this->adminLab($peran), $this->asal, [...$badan, 'dry_run' => true])->assertForbidden();
        }

        $pic = $this->jadiAnggota($this->asal, CustomerMember::PERAN_PIC_UTAMA)->user;
        $this->permintaanBaru();
        $this->withHeaders($this->bearer($pic))->postJson("/api/customers/{$this->asal->id}/pindahkan-alat", $badan)->assertForbidden();

        $this->permintaanBaru();
        $this->postJson("/api/customers/{$this->asal->id}/pindahkan-alat", $badan)->assertUnauthorized();

        $this->assertSame($this->asal->id, $a1->fresh()->customer_id);

        $matriks = app(MatriksIzin::class);
        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $peran) {
            $this->assertContains('pelanggan.pindah-alat', $matriks->bolehUntuk($peran), $peran);
        }
        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $peran) {
            $this->assertNotContains('pelanggan.pindah-alat', $matriks->bolehUntuk($peran), $peran);
        }
    }

    public function test_alat_yang_berpindah_di_antara_pratinjau_dan_eksekusi_dijawab_409(): void
    {
        $a1 = $this->alat($this->asal);
        $lain = $this->perusahaan('PT Lain Lagi');

        // Admin lain memindahkan alat ini setelah pratinjau, sebelum eksekusi kita.
        DB::table('equipments')->where('id', $a1->id)->update(['customer_id' => $lain->id]);

        try {
            app(PemindahanAlatPelanggan::class)->jalankan($this->asal, $this->tujuan, [$a1->id], $this->adminLab(), 'Terlambat');
            $this->fail('Seharusnya melempar alat_sudah_pindah.');
        } catch (AksiPelangganDitolak $e) {
            $this->assertSame('alat_sudah_pindah', $e->kode);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame($lain->id, $a1->fresh()->customer_id, 'Tidak boleh menimpa keputusan admin lain.');
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditLog::ACTION_ALAT_DIPINDAH_PT]);
    }

    public function test_pindah_dibatasi_throttle_per_akun(): void
    {
        $admin = $this->adminLab();

        // 10/menit per akun; pratinjau ikut dihitung. Badan kosong = 422, tetap satu ketukan.
        for ($i = 0; $i < 10; $i++) {
            $this->pindah($admin, $this->asal, [])->assertStatus(422);
        }

        $this->pindah($admin, $this->asal, [])->assertStatus(429);
    }
}
