<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Organization;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanSuntingan;
use App\Models\PaketAcuanVersi;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Support\JsonKanonik;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * Lapisan data Studio Data Acuan (03-SDD §2.1–2.3) dan stempel versinya di
 * `uncertainty_calculations`.
 *
 * Yang dijaga di sini semuanya jenis yang TIDAK menghasilkan error kalau
 * hilang: nomor versi kembar, riwayat suntingan yang bisa ditimpa, hasil
 * hitung yang ikut terhapus bersama versinya, dan isi kanonik yang disimpan
 * ulang dalam bentuk lain sehingga hash-nya berhenti cocok.
 */
class PaketAcuanModelTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
    }

    public function test_tabel_dan_kolom_rancangan_terbentuk(): void
    {
        $this->assertTrue(Schema::hasColumns('paket_acuan', [
            'id', 'organization_id', 'kode_profil', 'nama', 'skema_versi', 'created_at', 'updated_at', 'deleted_at',
        ]));

        $this->assertTrue(Schema::hasColumns('paket_acuan_versi', [
            'id', 'paket_acuan_id', 'organization_id', 'jenis', 'nomor_versi', 'status', 'isi', 'isi_sha256',
            'skema_versi', 'dasar_versi_id', 'berlaku_mulai', 'berlaku_sampai', 'alasan', 'rujukan',
            'dibuat_oleh', 'diajukan_oleh', 'disahkan_oleh', 'ditolak_oleh',
            'diajukan_pada', 'disahkan_pada', 'ditolak_pada', 'catatan_keputusan',
            'simulasi_terakhir_id', 'lock_versi', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('paket_acuan_suntingan', [
            'id', 'paket_acuan_versi_id', 'lembar', 'kunci_baris', 'kolom', 'nilai_lama', 'nilai_baru',
            'sumber', 'alasan', 'user_id', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('paket_acuan_suntingan', 'updated_at'));

        $this->assertTrue(Schema::hasColumn('uncertainty_calculations', 'paket_acuan_versi_id'));

        // `isi` wajib teks, bukan `json`: kolom JSON MySQL menormalkan angka
        // dan urutan kunci, jadi byte kanonik tidak kembali utuh. Di SQLite
        // keduanya `text`; di gate MySQL baris ini yang menangkap `json`.
        $this->assertContains(
            strtolower(Schema::getColumnType('paket_acuan_versi', 'isi')),
            ['text', 'longtext'],
        );

        $index = collect(Schema::getIndexes('paket_acuan_versi'))->pluck('name')->all();
        foreach (['pav_paket_jenis_nomor_unik', 'pav_paket_jenis_status_idx', 'pav_paket_jenis_mulai_idx'] as $nama) {
            $this->assertContains($nama, $index);
        }
        $this->assertContains('pa_org_profil_unik', collect(Schema::getIndexes('paket_acuan'))->pluck('name')->all());
        $this->assertContains('pas_versi_lembar_idx', collect(Schema::getIndexes('paket_acuan_suntingan'))->pluck('name')->all());
        $this->assertContains('uc_paket_acuan_versi_idx', collect(Schema::getIndexes('uncertainty_calculations'))->pluck('name')->all());
    }

    public function test_nomor_versi_unik_per_paket_dan_jenis(): void
    {
        $paket = $this->buatPaket();
        $this->buatVersi($paket, 1, PaketAcuanVersi::JENIS_DATA);

        // Nomor sama, JENIS beda → boleh: penomoran data dan bentuk terpisah.
        $this->buatVersi($paket, 1, PaketAcuanVersi::JENIS_BENTUK);

        // Nomor sama di PAKET lain → boleh.
        $this->buatVersi($this->buatPaket('dial-indicator'), 1, PaketAcuanVersi::JENIS_DATA);

        try {
            $this->buatVersi($paket, 1, PaketAcuanVersi::JENIS_DATA);
            $this->fail('Versi data nomor 1 kedua pada paket yang sama harus ditolak database.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, PaketAcuanVersi::query()
            ->where('paket_acuan_id', $paket->id)
            ->where('jenis', PaketAcuanVersi::JENIS_DATA)
            ->count());
    }

    public function test_suntingan_tidak_bisa_diubah(): void
    {
        $suntingan = $this->buatSuntingan($this->buatVersi($this->buatPaket(), 1));

        try {
            $suntingan->update(['nilai_baru' => '9.99999']);
            $this->fail('Suntingan harus append-only.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tidak boleh diubah', $e->getMessage());
        }

        $this->assertSame('100.00013', DB::table('paket_acuan_suntingan')->where('id', $suntingan->id)->value('nilai_baru'));
    }

    public function test_suntingan_tidak_bisa_dihapus(): void
    {
        $suntingan = $this->buatSuntingan($this->buatVersi($this->buatPaket(), 1));

        try {
            $suntingan->delete();
            $this->fail('Suntingan harus append-only.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tidak boleh dihapus', $e->getMessage());
        }

        $this->assertTrue(DB::table('paket_acuan_suntingan')->where('id', $suntingan->id)->exists());
    }

    public function test_menghapus_baris_versi_mengosongkan_stempel_tanpa_menghapus_hasil_hitung(): void
    {
        $versi = $this->buatVersi($this->buatPaket(), 1);
        $hasil = $this->buatHasilHitung($versi);

        $this->assertTrue($hasil->paketAcuanVersi->is($versi));
        $this->assertSame(1, $versi->uncertaintyCalculations()->count());

        // Lewat query builder, langsung ke database: yang diuji aksi FK-nya,
        // bukan event model.
        DB::table('paket_acuan_versi')->where('id', $versi->id)->delete();

        $baris = DB::table('uncertainty_calculations')->where('id', $hasil->id)->first();
        $this->assertNotNull($baris, 'Hasil hitung tidak boleh ikut terhapus bersama versinya.');
        $this->assertNull($baris->paket_acuan_versi_id);
    }

    public function test_paket_tidak_bisa_dihapus_permanen_selagi_punya_versi(): void
    {
        $paket = $this->buatPaket();
        $versi = $this->buatVersi($paket, 1);

        try {
            DB::table('paket_acuan')->where('id', $paket->id)->delete();
            $this->fail('Paket dengan versi harus ditahan restrictOnDelete.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue(DB::table('paket_acuan_versi')->where('id', $versi->id)->exists());
    }

    public function test_versi_yang_punya_suntingan_tidak_bisa_dihapus_permanen(): void
    {
        $versi = $this->buatVersi($this->buatPaket(), 1);
        $this->buatSuntingan($versi);

        try {
            DB::table('paket_acuan_versi')->where('id', $versi->id)->delete();
            $this->fail('Versi dengan riwayat suntingan harus ditahan restrictOnDelete.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, DB::table('paket_acuan_suntingan')->where('paket_acuan_versi_id', $versi->id)->count());
    }

    public function test_isi_disimpan_byte_apa_adanya_dan_bisa_diurai(): void
    {
        $kanonik = JsonKanonik::dariTeks(
            '{"skema":"micrometer@1","lembar":{"konstanta":{"delta_alpha_per_c":"1e-05","vi_type_b":200,"suhu":20.0,"f":1e-05}}}',
        );

        $versi = $this->buatVersi($this->buatPaket(), 1, PaketAcuanVersi::JENIS_DATA, $kanonik);
        $segar = PaketAcuanVersi::query()->findOrFail($versi->id);

        $this->assertSame($kanonik, $segar->isi);
        $this->assertSame($kanonik, DB::table('paket_acuan_versi')->where('id', $versi->id)->value('isi'));

        $terurai = $segar->isiTerurai();
        $this->assertSame('1e-05', $terurai['lembar']['konstanta']['delta_alpha_per_c']);
        $this->assertSame(200, $terurai['lembar']['konstanta']['vi_type_b']);
        $this->assertSame(20.0, $terurai['lembar']['konstanta']['suhu']);
        $this->assertSame(1e-05, $terurai['lembar']['konstanta']['f']);
    }

    public function test_relasi_paket_versi_suntingan(): void
    {
        $paket = $this->buatPaket();
        $v1 = $this->buatVersi($paket, 1);
        $v2 = $this->buatVersi($paket, 2, PaketAcuanVersi::JENIS_DATA, '{}', $v1->id);
        $suntingan = $this->buatSuntingan($v2);

        $this->assertSame([$v1->id, $v2->id], $paket->versi()->orderBy('nomor_versi')->pluck('id')->all());
        $this->assertTrue($v2->paketAcuan->is($paket));
        $this->assertTrue($v2->organization->is($this->org));
        $this->assertTrue($v2->dasarVersi->is($v1));
        $this->assertTrue($v2->suntingan->first()->is($suntingan));
        $this->assertTrue($suntingan->paketAcuanVersi->is($v2));
        $this->assertTrue($suntingan->user->is($this->admin));
        $this->assertTrue($paket->organization->is($this->org));
    }

    private function buatPaket(string $kode = 'micrometer'): PaketAcuan
    {
        return PaketAcuan::query()->create([
            'organization_id' => $this->org->id,
            'kode_profil' => $kode,
            'nama' => ucfirst($kode),
            'skema_versi' => 1,
        ]);
    }

    private function buatVersi(
        PaketAcuan $paket,
        int $nomor,
        string $jenis = PaketAcuanVersi::JENIS_DATA,
        string $isi = '{}',
        ?int $dasar = null,
    ): PaketAcuanVersi {
        return PaketAcuanVersi::query()->create([
            'paket_acuan_id' => $paket->id,
            'organization_id' => $paket->organization_id,
            'jenis' => $jenis,
            'nomor_versi' => $nomor,
            'status' => PaketAcuanVersi::STATUS_DRAF,
            'isi' => $isi,
            'skema_versi' => 1,
            'dasar_versi_id' => $dasar,
            'dibuat_oleh' => $this->admin->id,
        ]);
    }

    private function buatSuntingan(PaketAcuanVersi $versi): PaketAcuanSuntingan
    {
        return PaketAcuanSuntingan::query()->create([
            'paket_acuan_versi_id' => $versi->id,
            'lembar' => 'balok_ukur',
            'kunci_baris' => '100.0',
            'kolom' => 'nilai_terkoreksi_mm',
            'nilai_lama' => '100.00012',
            'nilai_baru' => '100.00013',
            'sumber' => PaketAcuanSuntingan::SUMBER_KETIK,
            'alasan' => 'Sertifikat balok ukur baru',
            'user_id' => $this->admin->id,
        ]);
    }

    private function buatHasilHitung(PaketAcuanVersi $versi): UncertaintyCalculation
    {
        $sesi = CalibrationSession::factory()->create(['organization_id' => $this->org->id]);

        return UncertaintyCalculation::query()->create([
            'calibration_session_id' => $sesi->id,
            'titik_ke' => 1,
            'titik_ukur' => 5.1,
            'rata_rata' => 5.1002,
            'error' => 0.0002,
            'koreksi' => -0.0002,
            'standar_deviasi' => 0.0001,
            'jumlah_pengulangan' => 5,
            'type_a' => 0.00005,
            'type_b_components' => [],
            'type_b' => 0.0004,
            'ketidakpastian_gabungan' => 0.0004,
            'faktor_cakupan_k' => 2,
            'derajat_kebebasan_efektif' => 50,
            'ketidakpastian_diperluas' => 0.0008,
            'toleransi' => 0.004,
            'keputusan' => 'PASS',
            'calculated_at' => now(),
            'paket_acuan_versi_id' => $versi->id,
        ]);
    }
}
