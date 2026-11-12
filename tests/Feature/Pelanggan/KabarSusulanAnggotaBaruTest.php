<?php

namespace Tests\Feature\Pelanggan;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Pelanggan\SertifikatSudahTersedia;
use App\Services\Pelanggan\KabarSertifikatUntukAnggotaBaru;
use App\Services\Pelanggan\Keanggotaan;
use App\Services\Pelanggan\KodeUndangan;
use App\Services\Pelanggan\PreferensiNotifikasi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Anggota yang bergabung SESUDAH sertifikat perusahaannya terbit: tetap bisa
 * melihatnya, dan dikabari SATU kali (ringkas) saat bergabung.
 *
 * Yang dijaga bukan cuma "terkirim", tapi hal-hal yang tidak menghasilkan
 * error kalau salah:
 *
 * - tidak membanjiri (satu kabar, bukan satu per sertifikat) dan tidak dobel
 *   saat diundang/diaktifkan ulang;
 * - jumlahnya hanya sertifikat perusahaannya sendiri yang BERLAKU;
 * - saklar `status_permintaan` dan isolasi perusahaan/organisasi dihormati;
 * - gagal mengabari tidak pernah menggagalkan pendaftaran anggota.
 */
class KabarSusulanAnggotaBaruTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const V1 = '/api/pelanggan/v1';

    private Customer $perusahaan;

    private Customer $lain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        $this->perusahaan = $this->perusahaan();
        $this->lain = $this->perusahaan();
    }

    // ── jalur sungguhan: undangan ditukar ───────────────────────────────

    public function test_anggota_yang_masuk_lewat_undangan_melihat_sertifikat_lama_dan_dikabari_sekali(): void
    {
        $sertifikat = $this->sertifikat($this->perusahaan);
        $punyaLain = $this->sertifikat($this->lain);

        $user = $this->terimaUndangan('baru@contoh.test');

        $kabar = $user->notifications()->where('type', SertifikatSudahTersedia::class)->get();
        $this->assertCount(1, $kabar);

        $data = $kabar->first()->data;
        $this->assertSame('pelanggan.sertifikat_tersedia', $data['kategori']);
        $this->assertStringContainsString('1 sertifikat', $data['body']);
        $this->assertNull($data['tautan'], 'Susulan ringkas tidak menunjuk satu sertifikat.');

        // Netral: tidak ada nomor, nama perusahaan, atau nomor seri di layar kunci.
        $semua = json_encode($data);
        foreach ([$sertifikat->nomor, $this->perusahaan->nama, $this->lain->nama, $punyaLain->nomor] as $terlarang) {
            $this->assertStringNotContainsString((string) $terlarang, $semua, "Payload membawa `{$terlarang}`.");
        }

        // Sertifikat perusahaannya TERLIHAT walau terbit sebelum dia masuk;
        // milik perusahaan lain tidak (404, bukan 403).
        $this->permintaanBaru();
        $daftar = $this->withHeaders($this->bearer($user))->getJson(self::V1.'/sertifikat')->assertOk()->json('data');
        $this->assertSame([$sertifikat->id], array_column($daftar, 'id'));

        $this->permintaanBaru();
        $this->withHeaders($this->bearer($user))->getJson(self::V1."/sertifikat/{$sertifikat->id}")->assertOk();

        $this->permintaanBaru();
        $this->withHeaders($this->bearer($user))->getJson(self::V1."/sertifikat/{$punyaLain->id}")->assertNotFound();
    }

    public function test_kabar_masuk_ke_kotak_masuk_pelanggan_lewat_endpoint_yang_sudah_ada(): void
    {
        $this->sertifikat($this->perusahaan);
        $user = $this->terimaUndangan('kotak@contoh.test');

        $this->permintaanBaru();
        $this->withHeaders($this->bearer($user))
            ->getJson(self::V1.'/notifikasi')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.kategori', 'pelanggan.sertifikat_tersedia');
    }

    // ── isi & hitungan ──────────────────────────────────────────────────

    public function test_jumlah_hanya_sertifikat_perusahaan_sendiri_yang_berlaku(): void
    {
        $this->sertifikat($this->perusahaan);
        $this->sertifikat($this->perusahaan);

        // Tidak dihitung: perusahaan lain, belum terbit, gagal, dibatalkan, dan yang sudah digantikan.
        $this->sertifikat($this->lain);
        $this->sertifikat($this->perusahaan, ['status' => Certificate::STATUS_MENUNGGU_GENERATE]);
        $this->sertifikat($this->perusahaan, ['status' => Certificate::STATUS_GAGAL]);
        $this->sertifikat($this->perusahaan, ['status' => Certificate::STATUS_DIBATALKAN, 'dibatalkan_pada' => now()]);
        $lama = $this->sertifikat($this->perusahaan);
        $this->sertifikat($this->perusahaan, ['revision_of' => $lama->id]); // menggantikan $lama -> berlaku 1, $lama tidak

        $user = $this->anggotaBaru($this->perusahaan);

        // `daftarkan()` sudah memicu kabar susulan; di sini yang diperiksa isinya.
        $isi = $user->notifications()->where('type', SertifikatSudahTersedia::class)->sole()->data['body'];
        // 2 asli + 1 revisi yang berlaku = 3 (pendahulunya digantikan, sisanya bukan dokumen yang berlaku).
        $this->assertStringContainsString('3 sertifikat', $isi);
    }

    public function test_tanpa_sertifikat_berlaku_tidak_ada_kabar(): void
    {
        $this->sertifikat($this->lain);
        $this->sertifikat($this->perusahaan, ['status' => Certificate::STATUS_DIBATALKAN, 'dibatalkan_pada' => now()]);

        $user = $this->terimaUndangan('kosong@contoh.test');

        $this->assertSame(0, $user->notifications()->count());
    }

    // ── sekali saja ─────────────────────────────────────────────────────

    public function test_diaktifkan_ulang_atau_dipanggil_lagi_tidak_mengirim_dobel(): void
    {
        $this->sertifikat($this->perusahaan);
        $pic = $this->anggotaBaru($this->perusahaan, CustomerMember::PERAN_PIC_UTAMA);
        $user = $this->anggotaBaru($this->perusahaan);
        $keanggotaan = app(Keanggotaan::class);

        $this->assertSame(1, $user->notifications()->count());

        // Dinonaktifkan lalu diundang kembali: barisnya dihidupkan lagi, kabar tidak diulang.
        $keanggotaan->nonaktifkan($user->keanggotaan()->sole(), $pic);
        $keanggotaan->daftarkan($this->perusahaan, $user, CustomerMember::PERAN_STAF, $pic);

        // Dipanggil langsung berkali-kali pun tidak menambah baris.
        $this->assertSame(0, app(KabarSertifikatUntukAnggotaBaru::class)->kabari($user->keanggotaan()->sole()));
        $this->assertSame(1, $user->notifications()->where('type', SertifikatSudahTersedia::class)->count());
    }

    public function test_anggota_yang_sudah_aktif_dipanggil_daftarkan_lagi_tidak_dianggap_bergabung(): void
    {
        $user = $this->anggotaBaru($this->perusahaan, CustomerMember::PERAN_PIC_UTAMA);

        // Sertifikat baru terbit SESUDAH dia aktif: itu urusan `KabarSertifikatTerbit`, bukan susulan.
        $this->sertifikat($this->perusahaan);
        app(Keanggotaan::class)->daftarkan($this->perusahaan, $user, CustomerMember::PERAN_PIC_UTAMA);

        $this->assertSame(0, $user->notifications()->where('type', SertifikatSudahTersedia::class)->count());
    }

    // ── saklar & isolasi ────────────────────────────────────────────────

    public function test_saklar_status_permintaan_yang_dimatikan_dihormati_walau_diundang_kembali(): void
    {
        $this->sertifikat($this->perusahaan);
        $pic = $this->anggotaBaru($this->perusahaan, CustomerMember::PERAN_PIC_UTAMA);

        // Anggota lama yang dinonaktifkan, dan SEBELUMNYA sudah mematikan saklarnya.
        $user = $this->pelanggan();
        $anggota = CustomerMember::create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => $this->perusahaan->id,
            'user_id' => $user->id,
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_NONAKTIF,
        ]);
        app(PreferensiNotifikasi::class)->simpan($anggota, [PreferensiNotifikasi::STATUS_PERMINTAAN => false]);

        // Diundang kembali = bergabung lagi, tapi saklarnya tetap mati: tidak ada kabar.
        app(Keanggotaan::class)->daftarkan($this->perusahaan, $user, CustomerMember::PERAN_STAF, $pic);

        $this->assertSame(CustomerMember::STATUS_AKTIF, $anggota->fresh()->status);
        $this->assertSame(0, $user->notifications()->count(), 'Saklar dimatikan tapi tetap dikabari.');
    }

    public function test_perusahaan_beda_organisasi_dari_anggotanya_dilewati(): void
    {
        $orgLain = Organization::factory()->create();
        $asing = Customer::factory()->create(['organization_id' => $orgLain->id]);
        $this->sertifikat($asing);

        // Baris keanggotaan yang organisasinya TIDAK sama dengan perusahaannya (data rusak/impor salah).
        $user = $this->pelanggan();
        $anggota = CustomerMember::create([
            'organization_id' => $this->organisasi()->id,
            'customer_id' => $asing->id,
            'user_id' => $user->id,
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $this->assertSame(0, app(KabarSertifikatUntukAnggotaBaru::class)->kabari($anggota));
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_anggota_nonaktif_atau_akun_nonaktif_tidak_dikabari(): void
    {
        $this->sertifikat($this->perusahaan);

        $nonaktif = $this->pelanggan();
        $anggotaNonaktif = CustomerMember::create([
            'organization_id' => $this->perusahaan->organization_id, 'customer_id' => $this->perusahaan->id,
            'user_id' => $nonaktif->id, 'peran' => CustomerMember::PERAN_STAF, 'status' => CustomerMember::STATUS_NONAKTIF,
        ]);

        $akunMati = $this->pelanggan(User::STATUS_NONAKTIF);
        $anggotaAkunMati = CustomerMember::create([
            'organization_id' => $this->perusahaan->organization_id, 'customer_id' => $this->perusahaan->id,
            'user_id' => $akunMati->id, 'peran' => CustomerMember::PERAN_STAF, 'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $kabar = app(KabarSertifikatUntukAnggotaBaru::class);

        $this->assertSame(0, $kabar->kabari($anggotaNonaktif));
        $this->assertSame(0, $kabar->kabari($anggotaAkunMati));
        $this->assertSame(0, $nonaktif->notifications()->count() + $akunMati->notifications()->count());
    }

    // ── ketahanan ───────────────────────────────────────────────────────

    public function test_galat_saat_mengabari_tidak_menggagalkan_pendaftaran_anggota(): void
    {
        $this->sertifikat($this->perusahaan);

        $this->app->bind(PreferensiNotifikasi::class, fn () => new class extends PreferensiNotifikasi
        {
            public function penerima(Customer $pelanggan, string $kunci): Collection
            {
                throw new \RuntimeException('antrean menolak');
            }
        });

        $user = $this->pelanggan();
        $anggota = app(Keanggotaan::class)->daftarkan($this->perusahaan, $user, CustomerMember::PERAN_PIC_UTAMA);

        $this->assertSame(CustomerMember::STATUS_AKTIF, $anggota->fresh()->status);
        $this->assertSame(0, $user->notifications()->count());
    }

    // ── bantuan ─────────────────────────────────────────────────────────

    /** Akun baru lewat jalur SUNGGUHAN: undangan diterbitkan lalu ditukar di endpoint publik. */
    private function terimaUndangan(string $email): User
    {
        ['kode' => $kode] = app(KodeUndangan::class)->terbitkan(
            $this->perusahaan,
            $email,
            CustomerMember::PERAN_PIC_UTAMA,
        );

        $this->permintaanBaru();
        $this->postJson(self::V1.'/auth/terima-undangan', [
            'email' => $email,
            'kode' => $kode,
            'nama' => 'Anggota Susulan',
            'sandi' => $this->sandiBenar,
            'telepon' => '0812-3456-7890',
            'jabatan' => 'QA Staff',
            'setuju_syarat' => true,
        ])->assertCreated();

        return User::query()->where('email', $email)->firstOrFail();
    }

    /** Akun pelanggan aktif yang didaftarkan lewat `Keanggotaan` (pintu yang sama dengan produksi). */
    private function anggotaBaru(Customer $perusahaan, string $peran = CustomerMember::PERAN_STAF): User
    {
        $user = $this->pelanggan();
        app(Keanggotaan::class)->daftarkan($perusahaan, $user, $peran);

        return $user->refresh();
    }

    /** @param  array<string, mixed>  $isi */
    private function sertifikat(Customer $pemilik, array $isi = []): Certificate
    {
        $alat = Equipment::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'customer_id' => $pemilik->id,
            'status' => Equipment::STATUS_AKTIF,
        ]);

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
}
