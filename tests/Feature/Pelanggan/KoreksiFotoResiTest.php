<?php

namespace Tests\Feature\Pelanggan;

use App\Jobs\ReviseCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\FotoPelanggan;
use App\Models\KoreksiPelanggan;
use App\Models\Organization;
use App\Models\PermintaanKalibrasi;
use App\Models\User;
use App\Notifications\KoreksiPelangganBaru;
use App\Notifications\Pelanggan\KabarKoreksiPermintaan;
use App\Notifications\ResiDiisi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * §42 — ubah alat, minta koreksi (alat & sertifikat), foto pelat nama, resi,
 * jadwal teknisi. Sapuan 404 lintas perusahaan untuk rute ber-ID ada di
 * `IsolasiPerusahaanTest`; di sini perilakunya.
 */
class KoreksiFotoResiTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private User $pic;

    private Customer $perusahaan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
        Storage::fake('arsip');

        $this->pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $this->perusahaan = $this->pic->keanggotaan()->first()->customer;
    }

    private function kirim(User $user, string $method, string $uri, array $badan = [])
    {
        $this->permintaanBaru();

        return $this->withHeaders($this->bearer($user))->json($method, '/api/pelanggan/v1'.$uri, $badan);
    }

    private function lab(User $user, string $method, string $uri, array $badan = [])
    {
        $this->permintaanBaru();

        return $this->withHeaders($this->bearerInternal($user))->json($method, '/api/'.ltrim($uri, '/'), $badan);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['organization_id' => $this->organisasi()->id]);
    }

    private function alat(array $tambahan = []): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => $this->perusahaan->id,
            ...$tambahan,
        ]);
    }

    /** Sertifikat terbit ber-snapshot minimal (cukup untuk data cetak). */
    private function sertifikat(Equipment $alat, array $tambahan = []): Certificate
    {
        return Certificate::factory()->create([
            'organization_id' => $alat->organization_id,
            'calibration_session_id' => CalibrationSession::factory()->create([
                'organization_id' => $alat->organization_id,
                'equipment_id' => $alat->id,
            ])->id,
            'pdf_path' => 'certificates/uji.pdf',
            'snapshot' => [
                'header' => [
                    'owner' => $this->perusahaan->nama,
                    'address' => 'Jl. Contoh 1',
                    'equipment_name' => $alat->nama_alat,
                    'manufacturer' => 'Merk Lama',
                    'model_type' => 'M-1',
                    'serial_number' => $alat->serial_number,
                    'calibration_location' => 'Lab',
                    'calibration_date' => '2026-09-01',
                ],
                'hasil' => [['titik_ke' => 1]],
                'meta' => ['keputusan' => 'PASS'],
                'footer' => ['penandatangan' => 'Penandatangan Uji'],
            ],
            ...$tambahan,
        ]);
    }

    private function permintaan(string $status, string $metode = PermintaanKalibrasi::METODE_DIANTAR_SENDIRI): PermintaanKalibrasi
    {
        return PermintaanKalibrasi::create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => $this->perusahaan->id,
            'diajukan_oleh' => $this->pic->id,
            'nomor' => 'PMT/2026/10/'.str_pad((string) (PermintaanKalibrasi::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => $status,
            'metode_pengantaran' => $metode,
        ]);
    }

    // ------------------------------------------------------------- ubah alat

    public function test_pelanggan_mengubah_lokasi_dan_catatannya_sendiri_tanpa_menyentuh_catatan_lab(): void
    {
        $alat = $this->alat(['catatan' => 'Catatan internal lab', 'lokasi' => 'Gudang']);

        $this->kirim($this->pic, 'PATCH', "/alat/{$alat->id}", ['lokasi' => 'Lantai 3', 'catatan' => 'Pindah sejak renovasi'])
            ->assertOk()
            ->assertJsonPath('data.lokasi', 'Lantai 3')
            ->assertJsonPath('data.catatan', 'Pindah sejak renovasi')
            ->assertDontSee('Catatan internal lab');

        $alat->refresh();
        $this->assertSame('Catatan internal lab', $alat->catatan);
        $this->assertSame('Pindah sejak renovasi', $alat->catatan_pelanggan);
    }

    public function test_identitas_bebas_diubah_sebelum_ada_sertifikat_lalu_terkunci_sesudahnya(): void
    {
        $alat = $this->alat();

        $this->kirim($this->pic, 'PATCH', "/alat/{$alat->id}", ['merk' => 'Merk Baru'])
            ->assertOk()
            ->assertJsonPath('data.terkunci', false);

        $this->sertifikat($alat);

        $this->kirim($this->pic, 'PATCH', "/alat/{$alat->id}", ['merk' => 'Merk Lain', 'lokasi' => 'X'])
            ->assertUnprocessable()
            ->assertJsonPath('kode', 'field_terkunci')
            ->assertJsonValidationErrors(['merk']);

        $this->assertSame('Merk Baru', $alat->fresh()->merk);

        $this->kirim($this->pic, 'GET', "/alat/{$alat->id}")
            ->assertOk()
            ->assertJsonPath('data.terkunci', true)
            ->assertJsonPath('data.field_terkunci.0', 'nama_alat');
    }

    // ------------------------------------------------------------ koreksi alat

    public function test_minta_koreksi_alat_lalu_lab_menerimanya(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $alat = $this->alat(['serial_number' => 'SN-SALAH']);

        $id = $this->kirim($this->pic, 'POST', "/alat/{$alat->id}/minta-koreksi", [
            'perubahan' => ['serial_number' => 'SN-BENAR', 'merk' => $alat->merk],
            'catatan' => 'Dua digit tertukar',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'menunggu')
            ->assertJsonCount(1, 'data.perubahan')
            ->assertJsonPath('data.perubahan.0.lama', 'SN-SALAH')
            ->assertJsonPath('data.perubahan.0.baru', 'SN-BENAR')
            ->json('data.id');

        Notification::assertSentTo($admin, KoreksiPelangganBaru::class);

        // Satu yang menunggu per alat.
        $this->kirim($this->pic, 'POST', "/alat/{$alat->id}/minta-koreksi", ['perubahan' => ['merk' => 'Z']])
            ->assertUnprocessable();

        $this->kirim($this->pic, 'GET', "/alat/{$alat->id}")->assertJsonPath('data.koreksi_menunggu.id', $id);

        $this->lab($admin, 'GET', 'koreksi-pelanggan')
            ->assertOk()
            ->assertJsonPath('meta.jumlah.menunggu', 1)
            ->assertJsonPath('data.0.diajukan_oleh.id', $this->pic->id);

        $this->lab($admin, 'POST', "koreksi-pelanggan/{$id}/terima", ['tanggapan' => 'Sudah kami betulkan.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.perubahan.0.diterapkan', 'SN-BENAR');

        $this->assertSame('SN-BENAR', $alat->fresh()->serial_number);
        Notification::assertSentTo($this->pic, KabarKoreksiPermintaan::class);

        // Pelanggan TIDAK pernah menerima nama peninjau.
        $this->kirim($this->pic, 'GET', "/koreksi/{$id}")
            ->assertOk()
            ->assertJsonPath('data.tanggapan', 'Sudah kami betulkan.')
            ->assertJsonMissingPath('data.ditinjau_oleh');
    }

    public function test_admin_boleh_membetulkan_nilai_sebelum_menerapkan_dan_bentrok_seri_ditolak(): void
    {
        $admin = $this->admin();
        $alat = $this->alat(['serial_number' => 'SN-1']);
        $this->alat(['serial_number' => 'SN-DIPAKAI']);

        $id = $this->kirim($this->pic, 'POST', "/alat/{$alat->id}/minta-koreksi", ['perubahan' => ['serial_number' => 'SN-DIPAKAI']])
            ->assertCreated()->json('data.id');

        $this->lab($admin, 'POST', "koreksi-pelanggan/{$id}/terima")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['perubahan.serial_number']);

        $this->lab($admin, 'POST', "koreksi-pelanggan/{$id}/terima", ['perubahan' => ['merk' => 'x']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['perubahan.merk']);

        $this->lab($admin, 'POST', "koreksi-pelanggan/{$id}/terima", ['perubahan' => ['serial_number' => 'SN-2']])
            ->assertOk();

        $this->assertSame('SN-2', $alat->fresh()->serial_number);
    }

    public function test_tolak_wajib_tanggapan_dan_yang_sudah_diputus_tidak_bisa_diputus_lagi(): void
    {
        $admin = $this->admin();
        $alat = $this->alat();
        $id = $this->kirim($this->pic, 'POST', "/alat/{$alat->id}/minta-koreksi", ['perubahan' => ['merk' => 'Baru']])->json('data.id');

        $this->lab($admin, 'POST', "koreksi-pelanggan/{$id}/tolak", [])->assertUnprocessable();
        $this->lab($admin, 'POST', "koreksi-pelanggan/{$id}/tolak", ['tanggapan' => 'Merk sudah sesuai pelat.'])->assertOk();
        $this->lab($admin, 'POST', "koreksi-pelanggan/{$id}/terima")->assertUnprocessable();
    }

    public function test_teknisi_403_super_admin_baca_saja_lab_lain_404(): void
    {
        $alat = $this->alat();
        $id = $this->kirim($this->pic, 'POST', "/alat/{$alat->id}/minta-koreksi", ['perubahan' => ['merk' => 'Baru']])->json('data.id');

        $teknisi = User::factory()->create(['organization_id' => $this->organisasi()->id, 'role' => User::ROLE_TEKNISI]);
        $this->lab($teknisi, 'GET', 'koreksi-pelanggan')->assertForbidden();

        $sa = User::factory()->create(['organization_id' => $this->organisasi()->id, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->lab($sa, 'GET', "koreksi-pelanggan/{$id}")->assertOk();
        $this->lab($sa, 'POST', "koreksi-pelanggan/{$id}/terima")->assertForbidden();

        $labLain = User::factory()->admin()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->lab($labLain, 'GET', "koreksi-pelanggan/{$id}")->assertNotFound();
        $this->lab($labLain, 'POST', "koreksi-pelanggan/{$id}/terima")->assertNotFound();
    }

    // ------------------------------------------------------ koreksi sertifikat

    public function test_koreksi_sertifikat_diterima_menerbitkan_revisi(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $alat = $this->alat();
        $sertifikat = $this->sertifikat($alat);

        $this->kirim($this->pic, 'GET', "/sertifikat/{$sertifikat->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'berlaku')
            ->assertJsonPath('data.bisa_minta_koreksi', true)
            ->assertJsonPath('data.data_cetak.merk', 'Merk Lama')
            ->assertJsonMissingPath('data.data_cetak.berlaku_sampai');

        $id = $this->kirim($this->pic, 'POST', "/sertifikat/{$sertifikat->id}/minta-koreksi", [
            'perubahan' => ['merk' => 'Merk Benar'],
        ])->assertCreated()->json('data.id');

        $this->kirim($this->pic, 'GET', "/sertifikat/{$sertifikat->id}")
            ->assertJsonPath('data.bisa_minta_koreksi', false)
            ->assertJsonPath('data.koreksi_menunggu.id', $id);

        $this->lab($admin, 'POST', "koreksi-pelanggan/{$id}/terima", ['tanggapan' => 'Merk dibetulkan.'])
            ->assertOk()
            ->assertJsonPath('data.revisi.nomor', $sertifikat->nomor.'-R1');

        $revisi = Certificate::where('revision_of', $sertifikat->id)->firstOrFail();
        $this->assertSame('Merk Benar', $revisi->snapshot['header']['manufacturer']);
        $this->assertSame('Merk dibetulkan.', $revisi->catatan_pelanggan);
        $this->assertSame('Koreksi dari pelanggan #'.$id, $revisi->alasan_revisi);
        Queue::assertPushed(ReviseCertificate::class);
    }

    public function test_sertifikat_batal_tampil_berstatus_dan_unduhnya_410(): void
    {
        $alat = $this->alat();
        $sertifikat = $this->sertifikat($alat, [
            'status' => Certificate::STATUS_DIBATALKAN,
            'dibatalkan_pada' => now(),
            'alasan_pembatalan' => 'Rahasia internal',
            'catatan_pelanggan' => 'Salah alat.',
        ]);

        $this->kirim($this->pic, 'GET', '/sertifikat')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'dibatalkan')
            ->assertJsonPath('data.0.bisa_diunduh', false)
            ->assertJsonPath('data.0.catatan_pelanggan', 'Salah alat.')
            ->assertDontSee('Rahasia internal');

        $this->kirim($this->pic, 'GET', "/sertifikat/{$sertifikat->id}/unduh")->assertStatus(410);

        $this->kirim($this->pic, 'POST', "/sertifikat/{$sertifikat->id}/minta-koreksi", ['perubahan' => ['merk' => 'x']])
            ->assertUnprocessable();
    }

    public function test_yang_digantikan_disembunyikan_bawaan_dan_menunjuk_ke_penggantinya(): void
    {
        $alat = $this->alat();
        $lama = $this->sertifikat($alat);
        $baru = $this->sertifikat($alat, [
            'revision_of' => $lama->id,
            'revisi_ke' => 1,
            'nomor' => $lama->nomor.'-R1',
            'catatan_pelanggan' => 'Nomor seri dibetulkan.',
        ]);

        $daftar = $this->kirim($this->pic, 'GET', '/sertifikat')->assertOk()->json('data');
        $this->assertSame([$baru->id], array_column($daftar, 'id'));

        $this->kirim($this->pic, 'GET', "/sertifikat/{$lama->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'digantikan')
            ->assertJsonPath('data.digantikan_oleh.id', $baru->id)
            ->assertJsonPath('data.catatan_pelanggan', 'Nomor seri dibetulkan.')
            ->assertJsonPath('data.bisa_diunduh', true);
    }

    // ------------------------------------------------------------------- foto

    public function test_foto_alat_maks_tiga_bisa_dilihat_dan_dihapus(): void
    {
        $alat = $this->alat();

        $ids = [];
        foreach (range(1, 3) as $i) {
            $ids[] = $this->kirim($this->pic, 'POST', "/alat/{$alat->id}/foto", [
                'foto' => UploadedFile::fake()->image("pelat{$i}.jpg", 800, 600),
            ])->assertCreated()->json('data.id');
        }

        $this->kirim($this->pic, 'POST', "/alat/{$alat->id}/foto", ['foto' => UploadedFile::fake()->image('ke4.jpg')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['foto']);

        $this->kirim($this->pic, 'POST', "/alat/{$alat->id}/foto", ['foto' => UploadedFile::fake()->create('bukan.pdf', 10, 'application/pdf')])
            ->assertUnprocessable();

        $foto = FotoPelanggan::findOrFail($ids[0]);
        Storage::disk('arsip')->assertExists($foto->path);

        $this->kirim($this->pic, 'GET', "/foto/{$foto->id}")->assertOk();
        $this->kirim($this->pic, 'GET', "/alat/{$alat->id}")->assertJsonCount(3, 'data.foto');

        $this->kirim($this->pic, 'DELETE', "/foto/{$foto->id}")->assertNoContent();
        Storage::disk('arsip')->assertMissing($foto->path);
    }

    public function test_foto_alat_baru_ikut_ke_alat_yang_lahir_saat_diterima_dan_terkunci_sesudahnya(): void
    {
        $p = $this->permintaan(PermintaanKalibrasi::STATUS_BARU);
        $item = $p->items()->create(['alat_baru' => ['nama_alat' => 'pH Meter', 'serial_number' => 'PH-UJI-1']]);

        $fotoId = $this->kirim($this->pic, 'POST', "/permintaan/{$p->id}/item/{$item->id}/foto", [
            'foto' => UploadedFile::fake()->image('pelat.jpg'),
        ])->assertCreated()->json('data.id');

        $this->kirim($this->pic, 'GET', "/permintaan/{$p->id}")->assertJsonPath('data.alat.0.foto.0.id', $fotoId);

        $admin = $this->admin();
        $kategori = EquipmentCategory::query()->firstOrCreate(
            ['organization_id' => $this->organisasi()->id, 'kode' => 'uji'],
            ['nama' => 'Uji'],
        );

        $this->lab($admin, 'GET', "permintaan-pelanggan/{$p->id}")->assertJsonPath('data.alat.0.foto.0.id', $fotoId);

        $this->lab($admin, 'POST', "permintaan-pelanggan/{$p->id}/terima", [
            'alat_baru' => [['item_id' => $item->id, 'equipment_category_id' => $kategori->id]],
        ])->assertOk();

        $alatBaru = Equipment::findOrFail($item->fresh()->equipment_id);
        $this->assertCount(1, $alatBaru->fotoPelat);

        // Sesudah diterima: foto item tidak bisa dihapus/ditambah dari permintaan.
        $this->kirim($this->pic, 'DELETE', "/foto/{$fotoId}")->assertUnprocessable();
        $this->kirim($this->pic, 'POST', "/permintaan/{$p->id}/item/{$item->id}/foto", [
            'foto' => UploadedFile::fake()->image('lagi.jpg'),
        ])->assertUnprocessable();
    }

    // ------------------------------------------------------- resi & jadwal

    public function test_resi_menggeser_tahap_dan_mengabari_lab(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $baru = $this->permintaan(PermintaanKalibrasi::STATUS_BARU);
        $diterima = $this->permintaan(PermintaanKalibrasi::STATUS_DITERIMA);

        $this->kirim($this->pic, 'POST', "/permintaan/{$baru->id}/resi", ['kurir' => 'JNE', 'nomor_resi' => 'X1'])
            ->assertUnprocessable();

        $daftar = $this->kirim($this->pic, 'GET', '/permintaan?saring=semua')->assertOk()->json('data');
        $this->assertSame($diterima->id, $daftar[0]['id'], 'Yang perlu tindakan pelanggan harus di paling atas.');
        $this->assertSame('menunggu_alat', $daftar[0]['tahap']);
        $this->assertTrue($daftar[0]['perlu_tindakan']);

        $this->kirim($this->pic, 'POST', "/permintaan/{$diterima->id}/resi", ['kurir' => 'JNE', 'nomor_resi' => 'JNE123'])
            ->assertOk()
            ->assertJsonPath('data.tahap', 'dalam_pengiriman')
            ->assertJsonPath('data.resi.nomor', 'JNE123')
            ->assertJsonPath('data.perlu_tindakan', false);

        Notification::assertSentTo($admin, ResiDiisi::class);

        $this->lab($admin, 'POST', "permintaan-pelanggan/{$diterima->id}/alat-tiba")
            ->assertOk()
            ->assertJsonPath('data.tahap', 'alat_di_lab');

        // Alat sudah tiba — resi tidak bisa diubah lagi.
        $this->kirim($this->pic, 'POST', "/permintaan/{$diterima->id}/resi", ['kurir' => 'JNE', 'nomor_resi' => 'LAIN'])
            ->assertUnprocessable();
    }

    public function test_jadwal_teknisi_cuma_untuk_diambil_lab_dan_dikabarkan(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $ambil = $this->permintaan(PermintaanKalibrasi::STATUS_DITERIMA, PermintaanKalibrasi::METODE_DIAMBIL_LAB);
        $antar = $this->permintaan(PermintaanKalibrasi::STATUS_DITERIMA);

        $this->kirim($this->pic, 'GET', "/permintaan/{$ambil->id}")->assertJsonPath('data.tahap', 'menunggu_jadwal');

        $this->lab($admin, 'POST', "permintaan-pelanggan/{$antar->id}/jadwal", ['jadwal_pada' => now()->addDay()->toIso8601String()])
            ->assertUnprocessable();

        $this->lab($admin, 'POST', "permintaan-pelanggan/{$ambil->id}/jadwal", [
            'jadwal_pada' => now()->addDays(2)->setTime(9, 0)->toIso8601String(),
            'lokasi' => 'Lab QC Lantai 2',
        ])->assertOk()->assertJsonPath('data.tahap', 'teknisi_dijadwalkan');

        $this->kirim($this->pic, 'GET', "/permintaan/{$ambil->id}")
            ->assertJsonPath('data.tahap', 'teknisi_dijadwalkan')
            ->assertJsonPath('data.jadwal.lokasi', 'Lab QC Lantai 2');

        Notification::assertSentTo($this->pic, KabarKoreksiPermintaan::class);
    }

    public function test_koreksi_perusahaan_lain_tidak_bocor_lewat_daftar(): void
    {
        $lain = Customer::factory()->create(['organization_id' => $this->perusahaan->organization_id]);
        KoreksiPelanggan::create([
            'organization_id' => $lain->organization_id,
            'customer_id' => $lain->id,
            'jenis' => KoreksiPelanggan::JENIS_ALAT,
            'perubahan' => [],
            'status' => KoreksiPelanggan::STATUS_MENUNGGU,
        ]);

        $this->kirim($this->pic, 'GET', '/koreksi')->assertOk()->assertJsonCount(0, 'data');
    }
}
