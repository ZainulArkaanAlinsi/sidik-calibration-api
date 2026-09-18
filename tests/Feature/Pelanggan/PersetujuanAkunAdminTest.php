<?php

namespace Tests\Feature\Pelanggan;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Organization;
use App\Models\PengajuanAkunPelanggan;
use App\Models\User;
use App\Notifications\Pelanggan\AnggotaBaruBergabung;
use App\Notifications\Pelanggan\PengajuanDiputus;
use App\Services\Pelanggan\PersetujuanAkun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/** REQ-AUTH-04 & REQ-AUTH-05 — admin lab memutuskan pengajuan akun. */
class PersetujuanAkunAdminTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();
        Notification::fake();
    }

    private function pemohon(string $namaPerusahaan = 'PT Klaim Pendaftar'): PengajuanAkunPelanggan
    {
        $user = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI);

        return PengajuanAkunPelanggan::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'nama_perusahaan' => $namaPerusahaan,
            'alamat_perusahaan' => 'Jl. Contoh No. 1',
            'jabatan' => 'QA Manager',
            'status' => PengajuanAkunPelanggan::STATUS_MENUNGGU,
        ]);
    }

    // ------------------------------------------------------------- antrean

    /** Antrean hanya memuat pengajuan yang emailnya SUDAH diverifikasi. */
    public function test_antrean_menyembunyikan_pengajuan_yang_emailnya_belum_diverifikasi(): void
    {
        $siap = $this->pemohon('PT Sudah Verifikasi');

        $belum = $this->pelanggan(User::STATUS_PENDING_EMAIL);
        PengajuanAkunPelanggan::create([
            'organization_id' => $belum->organization_id,
            'user_id' => $belum->id,
            'nama_perusahaan' => 'PT Belum Verifikasi',
            'status' => PengajuanAkunPelanggan::STATUS_MENUNGGU,
        ]);

        $balasan = $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->getJson('/api/admin/pengajuan-akun')
            ->assertOk();

        $this->assertSame([$siap->id], array_column($balasan->json('data'), 'id'));
    }

    /**
     * Saran pelanggan mirip muncul, dan nama perusahaan yang diketik pendaftar
     * disajikan sebagai `klaim` — bukan sebagai nama pelanggan.
     */
    public function test_saran_pelanggan_mirip_muncul_dan_klaim_ditandai(): void
    {
        $this->perusahaan('PT Maju Jaya');
        $this->perusahaan('CV Maju Jaya');      // badan usaha beda → BUKAN saran
        $this->perusahaan('PT Sumber Rejeki');  // beda jauh → bukan saran

        $pengajuan = $this->pemohon('PT Maju Java');

        $balasan = $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->getJson('/api/admin/pengajuan-akun')
            ->assertOk()
            ->assertJsonPath('data.0.klaim.nama_perusahaan', 'PT Maju Java');

        $saran = array_column($balasan->json('data.0.saran_pelanggan'), 'nama');

        $this->assertSame(['PT Maju Jaya'], $saran, 'Saran ikut menampilkan badan usaha yang berbeda.');
        $this->assertSame($pengajuan->id, $balasan->json('data.0.id'));
    }

    /** Nama yang persis sama ditandai, supaya admin tidak membuat kembar. */
    public function test_saran_menandai_nama_yang_persis_sama(): void
    {
        $this->perusahaan('PT. Maju Jaya');
        $this->pemohon('PT Maju Jaya');

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->getJson('/api/admin/pengajuan-akun')
            ->assertOk()
            ->assertJsonPath('data.0.saran_pelanggan.0.sama_persis', true);
    }

    /** Pengajuan lab lain tidak pernah kelihatan. */
    public function test_antrean_disaring_per_organisasi(): void
    {
        $this->pemohon('PT Lab Ini');

        $lain = Organization::factory()->create();
        $userLain = User::factory()->create([
            'organization_id' => $lain->id,
            'role' => User::ROLE_PELANGGAN,
            'status' => User::STATUS_PENDING_VERIFIKASI,
        ]);
        PengajuanAkunPelanggan::create([
            'organization_id' => $lain->id,
            'user_id' => $userLain->id,
            'nama_perusahaan' => 'PT Lab Sebelah',
            'status' => PengajuanAkunPelanggan::STATUS_MENUNGGU,
        ]);

        $balasan = $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->getJson('/api/admin/pengajuan-akun')
            ->assertOk();

        $this->assertCount(1, $balasan->json('data'));
        $this->assertSame('PT Lab Ini', $balasan->json('data.0.klaim.nama_perusahaan'));
    }

    // ------------------------------------------------------------ setujui

    public function test_REQ_AUTH_04_anggota_pertama_jadi_pic_utama(): void
    {
        $pengajuan = $this->pemohon();
        $perusahaan = $this->perusahaan('PT Tujuan');

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/setujui", ['customer_id' => $perusahaan->id])
            ->assertOk()
            ->assertJsonPath('data.status', PengajuanAkunPelanggan::STATUS_DISETUJUI);

        $this->assertDatabaseHas('customer_members', [
            'customer_id' => $perusahaan->id,
            'user_id' => $pengajuan->user_id,
            'peran' => CustomerMember::PERAN_PIC_UTAMA,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $this->assertSame(User::STATUS_AKTIF, $pengajuan->pemohon->fresh()->status);
        Notification::assertSentTo($pengajuan->pemohon, PengajuanDiputus::class);
    }

    /** REQ-AUTH-04 kalimat 2 — anggota kedua jadi staf, dan PIC utama dikabari. */
    public function test_REQ_AUTH_04_anggota_kedua_jadi_staf_dan_pic_utama_dikabari(): void
    {
        $pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $perusahaan = Customer::query()->findOrFail($pic->keanggotaan()->first()->customer_id);

        $pengajuan = $this->pemohon();

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/setujui", ['customer_id' => $perusahaan->id])
            ->assertOk();

        $this->assertDatabaseHas('customer_members', [
            'customer_id' => $perusahaan->id,
            'user_id' => $pengajuan->user_id,
            'peran' => CustomerMember::PERAN_STAF,
        ]);

        Notification::assertSentTo($pic, AnggotaBaruBergabung::class);

        // PIC utama BARU (yaitu pemohonnya sendiri) tidak boleh dikabari soal
        // dirinya sendiri — dia sudah dapat `PengajuanDiputus`.
        Notification::assertNotSentTo($pengajuan->pemohon, AnggotaBaruBergabung::class);
    }

    public function test_setujui_dengan_pelanggan_baru_menandai_sumbernya(): void
    {
        $pengajuan = $this->pemohon();
        $admin = $this->adminLab();

        $this->withHeaders($this->bearerInternal($admin))
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/setujui", [
                'pelanggan_baru' => ['nama' => 'PT Baru Dibuat', 'alamat' => 'Jl. Baru No. 9'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('customers', [
            'nama' => 'PT Baru Dibuat',
            'sumber' => Customer::SUMBER_PELANGGAN,
            'dibuat_oleh_user_id' => $admin->id,
            'organization_id' => $admin->organization_id,
        ]);
    }

    /**
     * R-D02 — klaim tidak pernah otomatis jadi pelanggan.
     *
     * Admin WAJIB memilih salah satu, dan "dua-duanya" juga ditolak: kalau
     * lolos, yang menang jadi soal urutan baca di controller.
     */
    public function test_R_D02_setujui_tanpa_memilih_atau_memilih_dua_duanya_ditolak(): void
    {
        $pengajuan = $this->pemohon();
        $perusahaan = $this->perusahaan();
        $header = $this->bearerInternal($this->adminLab());

        $this->withHeaders($header)
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/setujui", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');

        $this->withHeaders($header)
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/setujui", [
                'customer_id' => $perusahaan->id,
                'pelanggan_baru' => ['nama' => 'PT Dua-duanya'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');

        $this->assertDatabaseCount('customer_members', 0);
        $this->assertDatabaseMissing('customers', ['nama' => 'PT Dua-duanya']);
    }

    /** Pelanggan milik lab lain tidak bisa ditautkan cuma dengan menebak ID. */
    public function test_customer_id_lab_lain_ditolak(): void
    {
        $pengajuan = $this->pemohon();
        $lain = Customer::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'nama' => 'PT Milik Lab Sebelah',
        ]);

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/setujui", ['customer_id' => $lain->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    /**
     * Dua admin menekan "setujui" bersamaan — yang kedua kena 409, BUKAN
     * membuat keanggotaan kedua.
     *
     * Dua panggilan HTTP berurutan memang bukan dua request yang benar-benar
     * bersamaan. Yang diadu di sini justru bagian yang bisa diuji secara
     * deterministik: transisinya menolak baris yang statusnya sudah bukan
     * `menunggu`, apa pun yang memenangkan perlombaan.
     */
    public function test_admin_kedua_kena_409_bukan_bikin_keanggotaan_kedua(): void
    {
        $pengajuan = $this->pemohon();
        $satu = $this->perusahaan('PT Pilihan Admin Satu');
        $dua = $this->perusahaan('PT Pilihan Admin Dua');

        $adminSatu = $this->adminLab();
        $adminDua = $this->adminLab();

        $this->withHeaders($this->bearerInternal($adminSatu))
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/setujui", ['customer_id' => $satu->id])
            ->assertOk();

        $this->withHeaders($this->bearerInternal($adminDua))
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/setujui", ['customer_id' => $dua->id])
            ->assertStatus(409)
            ->assertJsonPath('kode', 'sudah_diputus')
            ->assertJsonFragment(['message' => "Pengajuan ini sudah diputus {$adminSatu->name}."]);

        $this->assertSame(1, CustomerMember::query()->where('user_id', $pengajuan->user_id)->count());
        $this->assertDatabaseMissing('customer_members', ['customer_id' => $dua->id]);
    }

    /** Transisi yang sama juga menahan tolak-sesudah-setujui. */
    public function test_tolak_sesudah_disetujui_kena_409(): void
    {
        $pengajuan = $this->pemohon();
        $perusahaan = $this->perusahaan();

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/setujui", ['customer_id' => $perusahaan->id])
            ->assertOk();

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/tolak", ['alasan' => 'Berubah pikiran setelah dicek.'])
            ->assertStatus(409)
            ->assertJsonPath('kode', 'sudah_diputus');
    }

    /** REQ-ANG-01 — batas anggota ditegakkan di jalur persetujuan juga. */
    public function test_REQ_ANG_01_batas_anggota_menahan_persetujuan(): void
    {
        $perusahaan = $this->perusahaan('PT Sudah Penuh');
        $perusahaan->forceFill(['maks_anggota' => 1])->save();

        $penghuni = $this->pelanggan();
        CustomerMember::create([
            'organization_id' => $perusahaan->organization_id,
            'customer_id' => $perusahaan->id,
            'user_id' => $penghuni->id,
            'peran' => CustomerMember::PERAN_PIC_UTAMA,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $pengajuan = $this->pemohon();

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/setujui", ['customer_id' => $perusahaan->id])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'batas_anggota')
            ->assertJsonPath('data.maks_anggota', 1);

        // Transaksinya utuh: pengajuan TIDAK ikut pindah ke `disetujui`.
        $this->assertSame(PengajuanAkunPelanggan::STATUS_MENUNGGU, $pengajuan->fresh()->status);
        $this->assertSame(User::STATUS_PENDING_VERIFIKASI, $pengajuan->pemohon->fresh()->status);
    }

    // -------------------------------------------------------------- tolak

    public function test_REQ_AUTH_05_alasan_wajib_dan_sampai_ke_pemohon(): void
    {
        $pengajuan = $this->pemohon();
        $header = $this->bearerInternal($this->adminLab());

        $this->withHeaders($header)
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/tolak", ['alasan' => 'pendek'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('alasan');

        $this->withHeaders($header)
            ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/tolak", [
                'alasan' => 'Nama perusahaan tidak cocok dengan data pelanggan kami.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PengajuanAkunPelanggan::STATUS_DITOLAK);

        // Akun pemohon TETAP pending_verifikasi, bukan nonaktif — dia harus
        // masih bisa masuk buat membaca alasan penolakannya (REQ-AUTH-05).
        $this->assertSame(User::STATUS_PENDING_VERIFIKASI, $pengajuan->pemohon->fresh()->status);

        // Guard-nya masih memegang identitas ADMIN dari request di atas; tanpa
        // ini token pemohon diabaikan dan yang dijawab `aplikasi:pelanggan`
        // justru token internal si admin. Lihat docblock `lupakanSesiGuard()`.
        $this->lupakanSesiGuard();

        $this->withHeaders($this->bearer($pengajuan->pemohon->fresh()))
            ->getJson('/api/pelanggan/v1/saya')
            ->assertOk()
            ->assertJsonPath('data.pengajuan.alasan_tolak', 'Nama perusahaan tidak cocok dengan data pelanggan kami.');
    }

    // ------------------------------------------------------------ gerbang

    public function test_teknisi_dan_viewer_ditolak(): void
    {
        $pengajuan = $this->pemohon();

        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $role) {
            $header = $this->bearerInternal($this->adminLab($role));

            $this->withHeaders($header)->getJson('/api/admin/pengajuan-akun')->assertForbidden();
            $this->withHeaders($header)
                ->postJson("/api/admin/pengajuan-akun/{$pengajuan->id}/setujui", ['customer_id' => $this->perusahaan()->id])
                ->assertForbidden();
        }
    }

    /** Pengajuan lab lain → 404, bukan 403: 403 pun sudah memberi tahu dia ada. */
    public function test_pengajuan_lab_lain_dijawab_404(): void
    {
        $lain = Organization::factory()->create();
        $userLain = User::factory()->create([
            'organization_id' => $lain->id,
            'role' => User::ROLE_PELANGGAN,
            'status' => User::STATUS_PENDING_VERIFIKASI,
        ]);
        $pengajuanLain = PengajuanAkunPelanggan::create([
            'organization_id' => $lain->id,
            'user_id' => $userLain->id,
            'nama_perusahaan' => 'PT Lab Sebelah',
            'status' => PengajuanAkunPelanggan::STATUS_MENUNGGU,
        ]);

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->postJson("/api/admin/pengajuan-akun/{$pengajuanLain->id}/tolak", ['alasan' => 'Bukan urusan lab ini.'])
            ->assertNotFound();
    }

    /** Antrean tetap bisa diputus walau `FITUR_PELANGGAN` mati. */
    public function test_antrean_tetap_jalan_waktu_flag_pelanggan_mati(): void
    {
        $this->pemohon();
        config(['pelanggan.fitur' => false]);

        $this->withHeaders($this->bearerInternal($this->adminLab()))
            ->getJson('/api/admin/pengajuan-akun')
            ->assertOk();
    }

    /** Saran kemiripan memakai aturan yang sama dengan impor pelanggan. */
    public function test_saran_memakai_aturan_kemiripan_bersama(): void
    {
        $this->perusahaan('PT Maju Jaya');
        $pengajuan = $this->pemohon('CV Maju Jaya');

        $saran = app(PersetujuanAkun::class)->saranPelanggan($pengajuan);

        $this->assertCount(0, $saran, 'Badan usaha berbeda ikut disarankan — aturan kemiripannya tidak terpakai.');
    }
}
