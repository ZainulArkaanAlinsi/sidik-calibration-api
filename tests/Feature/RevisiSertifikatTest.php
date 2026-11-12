<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Jobs\ReviseCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\SinkronJadwalAlat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Revisi & pembatalan sertifikat — §38.5 `docs/permintaan-user-7.md`.
 *
 * Sertifikatnya diterbitkan lewat jalur SUNGGUHAN (`GenerateCertificate`), bukan
 * factory: revisi menyalin snapshot, dan snapshot factory yang kosong akan
 * meloloskan justru bagian yang paling perlu dijaga — bahwa angka pengukuran
 * ikut apa adanya.
 */
class RevisiSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('arsip');

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
    }

    private function sertifikatTerbit(?Equipment $alat = null, string $tanggal = '2026-07-20'): Certificate
    {
        // Nama pelanggan UNIK per lab — test yang menerbitkan dua sertifikat
        // memakai pelanggan yang sama, bukan membuat kembarannya.
        $pelanggan = Customer::query()->where('nama', 'PT Contoh Jaya')->first()
            ?? Customer::factory()->create(['nama' => 'PT Contoh Jaya']);

        $alat ??= Equipment::factory()->create([
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => EquipmentCategory::query()->firstOrCreate(
                ['organization_id' => $this->org->id, 'kode' => 'panjang'],
                ['nama' => 'Panjang'],
            )->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => Standard::factory()->create()->id,
            'tanggal_kalibrasi' => $tanggal,
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI, 'reviewed_by' => $this->admin->id]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    /** @param  array<string, mixed>  $perubahan */
    private function revisi(Certificate $c, array $perubahan = ['nomor_seri' => 'SN-BARU-01'], ?User $oleh = null)
    {
        return $this->actingAs($oleh ?? $this->admin)->postJson("/api/certificates/{$c->id}/revisi", [
            'perubahan' => $perubahan,
            'alasan' => 'Salah ketik nomor seri',
            'catatan_pelanggan' => 'Nomor seri disesuaikan dengan pelat nama.',
        ]);
    }

    // ---------------------------------------------------------------- revisi

    /** §38.5 butir 1 — cuma kunci daftar putih yang berubah; `hasil[]` identik. */
    public function test_revisi_menyalin_angka_dan_cuma_mengubah_kunci_daftar_putih(): void
    {
        $asal = $this->sertifikatTerbit();

        $respons = $this->revisi($asal, ['nomor_seri' => 'SN-BARU-01', 'pemilik' => 'PT Contoh Jaya Abadi'])
            ->assertStatus(202)
            ->assertJsonPath('data.revisi_ke', 1)
            ->assertJsonPath('data.revisi_dari.id', $asal->id);

        $revisi = Certificate::findOrFail($respons->json('data.id'));

        // QUEUE_CONNECTION=sync di phpunit.xml: render sudah jalan di dalam request.
        $this->assertSame(Certificate::STATUS_TERBIT, $revisi->status);
        $this->assertSame($asal->nomor.'-R1', $revisi->nomor);
        $this->assertNotSame($asal->qr_token, $revisi->qr_token);
        Storage::disk('arsip')->assertExists($revisi->pdf_path);

        $this->assertSame($asal->snapshot['hasil'], $revisi->snapshot['hasil']);
        $this->assertSame($asal->snapshot['standar_digunakan'] ?? null, $revisi->snapshot['standar_digunakan'] ?? null);
        $this->assertSame($asal->snapshot['meta']['keputusan'] ?? null, $revisi->snapshot['meta']['keputusan'] ?? null);

        $headerAsal = $asal->snapshot['header'];
        $headerBaru = $revisi->snapshot['header'];
        $this->assertSame('SN-BARU-01', $headerBaru['serial_number']);
        $this->assertSame('PT Contoh Jaya Abadi', $headerBaru['owner']);
        $this->assertSame($asal->nomor.'-R1', $headerBaru['certificate_number']);
        $this->assertSame("Revisi ke-1, menggantikan {$asal->nomor}", $headerBaru['catatan_revisi']);

        // Selain kunci itu, header tidak disentuh sama sekali.
        $dikecualikan = array_flip(['serial_number', 'owner', 'certificate_number', 'catatan_revisi']);
        $this->assertSame(array_diff_key($headerAsal, $dikecualikan), array_diff_key($headerBaru, $dikecualikan));

        // Yang lama tetap `terbit` — "digantikan" diturunkan dari revisinya.
        $this->assertSame(Certificate::STATUS_TERBIT, $asal->fresh()->status);
        $this->assertSame(Certificate::DOKUMEN_DIGANTIKAN, $asal->fresh()->statusDokumen());
    }

    /** §38.5 butir 2 — kunci di luar daftar putih 422, bukan diabaikan. */
    public function test_kunci_di_luar_daftar_putih_ditolak(): void
    {
        $asal = $this->sertifikatTerbit();

        $this->revisi($asal, ['nomor_seri' => 'SN-X', 'hasil' => 'apa pun', 'equipment_name' => 'Lain'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['perubahan.hasil', 'perubahan.equipment_name']);

        $this->assertSame(0, Certificate::whereNotNull('revision_of')->count());
    }

    public function test_tanpa_perubahan_nyata_ditolak(): void
    {
        $asal = $this->sertifikatTerbit();
        $seri = $asal->snapshot['header']['serial_number'];

        $this->revisi($asal, ['nomor_seri' => $seri])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['perubahan']);
    }

    public function test_berlaku_sampai_harus_sesudah_tanggal_kalibrasi(): void
    {
        $asal = $this->sertifikatTerbit();

        $this->revisi($asal, ['berlaku_sampai' => '2026-07-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['perubahan.berlaku_sampai']);
    }

    /** §38.5 butir 6 — yang sudah digantikan / dibatalkan tidak bisa direvisi. */
    public function test_revisi_dari_yang_sudah_digantikan_atau_dibatalkan_ditolak(): void
    {
        $asal = $this->sertifikatTerbit();
        $this->revisi($asal)->assertStatus(202);

        $this->revisi($asal, ['nomor_seri' => 'SN-LAIN'])->assertUnprocessable();

        $lain = $this->sertifikatTerbit();
        $this->actingAs($this->admin)->postJson("/api/certificates/{$lain->id}/batalkan", ['alasan' => 'Uji'])
            ->assertOk();

        $this->revisi($lain)->assertUnprocessable();
    }

    /** Revisi berantai: X → X′ → X″, nomor `-R2` dari nomor DASAR. */
    public function test_revisi_berantai_menomori_dari_nomor_dasar(): void
    {
        $asal = $this->sertifikatTerbit();

        $r1 = Certificate::findOrFail($this->revisi($asal)->json('data.id'));
        $r2 = Certificate::findOrFail($this->revisi($r1, ['merk' => 'Merk Baru'])->assertStatus(202)->json('data.id'));

        $this->assertSame($asal->nomor.'-R2', $r2->nomor);
        $this->assertSame(2, $r2->revisi_ke);
        $this->assertSame($r1->id, $r2->revision_of);
        $this->assertSame("Revisi ke-2, menggantikan {$r1->nomor}", $r2->snapshot['header']['catatan_revisi']);
    }

    /**
     * §38.5 butir 7. Super admin dulu ikut ditolak di sini; K-49-14 (15 Okt
     * 2026) membukanya — lihat test di bawah.
     */
    public function test_teknisi_dan_viewer_ditolak(): void
    {
        $asal = $this->sertifikatTerbit();

        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $role) {
            $orang = User::factory()->create(['role' => $role]);

            $this->revisi($asal, oleh: $orang)->assertForbidden();
            $this->actingAs($orang)->postJson("/api/certificates/{$asal->id}/batalkan", ['alasan' => 'x'])
                ->assertForbidden();
        }

        $this->assertSame(Certificate::STATUS_TERBIT, $asal->fresh()->status);
    }

    /** K-49-14: suntingan sesudah terbit lewat revisi bernomor, kini juga oleh super admin. */
    public function test_super_admin_boleh_merevisi_dan_membatalkan(): void
    {
        $asal = $this->sertifikatTerbit();
        $super = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_SUPER_ADMIN]);

        $revisi = Certificate::findOrFail($this->revisi($asal, oleh: $super)->assertStatus(202)->json('data.id'));
        $this->assertSame($asal->nomor.'-R1', $revisi->nomor);

        $this->actingAs($super)
            ->postJson("/api/certificates/{$revisi->id}/batalkan", ['alasan' => 'Alat ternyata bukan milik pelanggan ini.'])
            ->assertSuccessful();
    }

    public function test_lab_lain_dijawab_404(): void
    {
        $asal = $this->sertifikatTerbit();
        $labLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $labLain->id]);

        $this->revisi($asal, oleh: $adminLain)->assertNotFound();
    }

    /** §38.5 butir 3 — JEBAKAN PENOMORAN: sertifikat baru sesudah revisi bulan yang sama. */
    public function test_sertifikat_baru_sesudah_revisi_dapat_nomor_urut_yang_benar(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(3));

        $pertama = $this->sertifikatTerbit(tanggal: now()->toDateString());
        $this->revisi($pertama)->assertStatus(202);

        $kedua = $this->sertifikatTerbit(tanggal: now()->toDateString());

        $urutan = fn (Certificate $c): int => (int) substr((string) $c->nomor, -4);

        $this->assertSame($urutan($pertama) + 1, $urutan($kedua));
        $this->assertStringNotContainsString('-R', (string) $kedua->nomor);
        $this->assertSame(1, Certificate::where('nomor', $kedua->nomor)->count());
    }

    public function test_revisi_gagal_render_diterbitkan_ulang_lewat_retry_yang_sama(): void
    {
        $asal = $this->sertifikatTerbit();

        Queue::fake();
        $id = $this->revisi($asal)->assertStatus(202)->json('data.id');
        Certificate::whereKey($id)->update(['status' => Certificate::STATUS_GAGAL]);

        $this->actingAs($this->admin)->postJson("/api/certificates/{$id}/retry")->assertOk();

        Queue::assertPushed(ReviseCertificate::class, fn (ReviseCertificate $job) => $job->certificateId === $id);
        Queue::assertNotPushed(GenerateCertificate::class);
    }

    public function test_resource_membawa_status_dokumen_dan_data_cetak(): void
    {
        $asal = $this->sertifikatTerbit();

        $this->actingAs($this->admin)->getJson("/api/certificates/{$asal->id}")
            ->assertOk()
            ->assertJsonPath('data.status_dokumen', 'berlaku')
            ->assertJsonPath('data.bisa_direvisi', true)
            ->assertJsonPath('data.data_cetak.nomor_seri', $asal->snapshot['header']['serial_number'])
            ->assertJsonPath('data.dampak_pembatalan.jadwal_dikosongkan', true);

        $idRevisi = $this->revisi($asal)->json('data.id');

        $this->actingAs($this->admin)->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonFragment(['id' => $asal->id, 'status_dokumen' => 'digantikan'])
            ->assertJsonFragment(['id' => $idRevisi, 'status_dokumen' => 'berlaku']);
    }

    // ------------------------------------------------------------ pembatalan

    /** §38.5 butir 5a — satu-satunya sertifikat dibatalkan → jadwal alat kosong. */
    public function test_batal_satu_satunya_sertifikat_mengosongkan_jadwal_alat(): void
    {
        $asal = $this->sertifikatTerbit();
        $alat = $asal->session->equipment->fresh();
        $this->assertNotNull($alat->tanggal_jatuh_tempo);

        $this->actingAs($this->admin)->postJson("/api/certificates/{$asal->id}/batalkan", [
            'alasan' => 'Salah alat (internal)',
            'catatan_pelanggan' => 'Sertifikat diterbitkan untuk alat yang salah.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'dibatalkan')
            ->assertJsonPath('data.status_dokumen', 'dibatalkan')
            ->assertJsonPath('data.alasan_pembatalan', 'Salah alat (internal)')
            ->assertJsonPath('data.dibatalkan_oleh.id', $this->admin->id);

        $alat->refresh();
        $this->assertNull($alat->tanggal_jatuh_tempo);
        $this->assertNull($alat->tanggal_kalibrasi_terakhir);
    }

    /** §38.5 butir 5b — ada sertifikat sah sebelumnya → jadwal jatuh ke situ. */
    public function test_batal_jatuh_ke_sertifikat_sah_sebelumnya(): void
    {
        $lama = $this->sertifikatTerbit(tanggal: '2026-06-01');
        $alat = $lama->session->equipment;
        $baru = $this->sertifikatTerbit($alat, '2026-07-20');

        $this->assertSame($baru->berlaku_sampai->toDateString(), $alat->fresh()->tanggal_jatuh_tempo->toDateString());

        $this->actingAs($this->admin)->getJson("/api/certificates/{$baru->id}")
            ->assertJsonPath('data.dampak_pembatalan.jadwal_dikosongkan', false)
            ->assertJsonPath('data.dampak_pembatalan.jatuh_ke.id', $lama->id);

        $this->actingAs($this->admin)->postJson("/api/certificates/{$baru->id}/batalkan", ['alasan' => 'x'])->assertOk();

        $this->assertSame($lama->berlaku_sampai->toDateString(), $alat->fresh()->tanggal_jatuh_tempo->toDateString());
    }

    /** §38.5 butir 5c — sapuan rutin tetap TIDAK mengosongkan tanggal impor. */
    public function test_sapuan_rutin_tetap_diam_untuk_alat_tanpa_sertifikat(): void
    {
        $alat = Equipment::factory()->create(['tanggal_jatuh_tempo' => '2027-01-01', 'tanggal_kalibrasi_terakhir' => '2026-01-01']);

        app(SinkronJadwalAlat::class)->untuk($alat);

        $this->assertSame('2027-01-01', $alat->fresh()->tanggal_jatuh_tempo->toDateString());
    }

    /** K38-2 — membatalkan X′ tidak menghidupkan X. */
    public function test_membatalkan_revisi_tidak_menghidupkan_pendahulunya(): void
    {
        $asal = $this->sertifikatTerbit();
        $idRevisi = $this->revisi($asal)->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/certificates/{$idRevisi}/batalkan", ['alasan' => 'x'])->assertOk();

        $this->assertSame(Certificate::DOKUMEN_DIGANTIKAN, $asal->fresh()->statusDokumen());
        $this->assertNull(app(SinkronJadwalAlat::class)->sertifikatAktif($asal->session->equipment));
        $this->assertNull($asal->session->equipment->fresh()->tanggal_jatuh_tempo);
    }

    public function test_pembatalan_final_dan_arsipnya_tetap_bisa_diunduh_lab(): void
    {
        $asal = $this->sertifikatTerbit();
        $this->actingAs($this->admin)->postJson("/api/certificates/{$asal->id}/batalkan", ['alasan' => 'x'])->assertOk();

        $this->actingAs($this->admin)->postJson("/api/certificates/{$asal->id}/batalkan", ['alasan' => 'lagi'])
            ->assertUnprocessable();

        $this->actingAs($this->admin)->get("/api/certificates/{$asal->id}/download")->assertOk();
    }

    public function test_batalkan_wajib_alasan(): void
    {
        $asal = $this->sertifikatTerbit();

        $this->actingAs($this->admin)->postJson("/api/certificates/{$asal->id}/batalkan", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['alasan']);
    }

    // ------------------------------------------------------- halaman QR publik

    /** §38.5 butir 4 — QR lama: "digantikan oleh X′", tanpa alasan. */
    public function test_qr_sertifikat_lama_menunjuk_ke_revisinya_tanpa_alasan(): void
    {
        $asal = $this->sertifikatTerbit();
        $revisi = Certificate::findOrFail($this->revisi($asal)->json('data.id'));

        $this->get("/verify/{$asal->qr_token}")
            ->assertOk()
            ->assertSee('Sertifikat ini sudah direvisi')
            ->assertSee($revisi->nomor)
            ->assertSee(route('verify', $revisi->qr_token), false)
            ->assertDontSee('Salah ketik nomor seri');

        $this->getJson("/api/verify/{$asal->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.status_dokumen', 'digantikan')
            ->assertJsonPath('data.digantikan_oleh.nomor', $revisi->nomor);

        // Lembar lama tetap bisa diunduh sebagai riwayat.
        $this->get("/verify/{$asal->qr_token}/download")->assertOk();

        $this->get("/verify/{$revisi->qr_token}")
            ->assertOk()
            ->assertSee('Sertifikat terverifikasi')
            ->assertSee("Revisi ke-1, menggantikan {$asal->nomor}");
    }

    /**
     * Revisi yang DIBATALKAN bukan dokumen yang berlaku: tidak boleh ditunjuk
     * sebagai pengganti (sama dengan resource pelanggan, API #247) — tapi
     * pendahulunya TETAP `digantikan` (K38-2), jadi QR lama tidak boleh
     * berbalik tampil hijau "terverifikasi". Dua pertanyaan, dua jawaban.
     *
     * @return array{0: Certificate, 1: Certificate} [asal, revisi yang sudah dibatalkan]
     */
    private function asalDenganRevisiDibatalkan(): array
    {
        $asal = $this->sertifikatTerbit();
        $revisi = Certificate::findOrFail($this->revisi($asal)->json('data.id'));

        $this->actingAs($this->admin)->postJson("/api/certificates/{$revisi->id}/batalkan", ['alasan' => 'x'])->assertOk();

        return [$asal->fresh(), $revisi->fresh()];
    }

    /** Kontrol: sebelum dibatalkan, revisi TERBIT memang ditunjuk (jangan sampai fix ini mematikan pointer sama sekali). */
    public function test_revisi_terbit_tetap_ditunjuk_sebagai_pengganti_sah(): void
    {
        $asal = $this->sertifikatTerbit();
        $revisi = Certificate::findOrFail($this->revisi($asal)->json('data.id'));

        $this->assertSame($revisi->id, $asal->penggantiSah()?->id);
        $this->assertSame($revisi->id, $asal->revisiPenentuStatus()?->id);

        $this->getJson("/api/verify/{$asal->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.status_dokumen', 'digantikan')
            ->assertJsonPath('data.digantikan_oleh.nomor', $revisi->nomor);

        $this->actingAs($this->admin)->getJson("/api/certificates/{$asal->id}")
            ->assertOk()
            ->assertJsonPath('data.status_dokumen', 'digantikan')
            ->assertJsonPath('data.digantikan_oleh.id', $revisi->id)
            ->assertJsonPath('data.digantikan_oleh.status', Certificate::STATUS_TERBIT);
    }

    public function test_model_revisi_dibatalkan_bukan_pengganti_sah_tapi_tetap_menentukan_status(): void
    {
        [$asal, $revisi] = $this->asalDenganRevisiDibatalkan();

        $this->assertSame(Certificate::STATUS_DIBATALKAN, $revisi->status);
        $this->assertNull($asal->penggantiSah(), 'Revisi yang dibatalkan tidak boleh dihitung pengganti sah.');
        $this->assertSame($revisi->id, $asal->revisiPenentuStatus()?->id);
        $this->assertSame(Certificate::DOKUMEN_DIGANTIKAN, $asal->statusDokumen(), 'K38-2: pendahulu tetap digantikan.');
    }

    public function test_verifikasi_publik_json_revisi_dibatalkan_tidak_ditunjuk_tapi_tetap_digantikan(): void
    {
        [$asal, $revisi] = $this->asalDenganRevisiDibatalkan();

        $this->getJson("/api/verify/{$asal->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.status_dokumen', 'digantikan')
            ->assertJsonPath('data.digantikan_oleh', null);

        // Revisinya sendiri menjawab dibatalkan, tanpa alasan (D4).
        $this->getJson("/api/verify/{$revisi->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.status_dokumen', 'dibatalkan')
            ->assertJsonPath('data.digantikan_oleh', null);
    }

    public function test_verifikasi_publik_web_revisi_dibatalkan_tidak_menunjuk_dan_tidak_tampil_terverifikasi(): void
    {
        [$asal, $revisi] = $this->asalDenganRevisiDibatalkan();

        $this->get("/verify/{$asal->qr_token}")
            ->assertOk()
            ->assertSee('Sertifikat ini sudah direvisi')
            ->assertSee('dibatalkan')
            ->assertSee(route('verify.download', $asal->qr_token), false)
            // Tidak ada penunjuk ke revisi yang dibatalkan, dan tidak ada "yang berlaku".
            ->assertDontSee($revisi->nomor)
            ->assertDontSee(route('verify', $revisi->qr_token), false)
            ->assertDontSee('Yang berlaku')
            ->assertDontSee('Buka yang berlaku')
            // Lembar yang sudah digantikan tidak boleh berbalik jadi hijau.
            ->assertDontSee('Sertifikat terverifikasi')
            ->assertDontSee('Salah ketik nomor seri');
    }

    /** Sertifikat lama tanpa snapshot memakai kartu ringkas — jalur tampilan yang berbeda. */
    public function test_verifikasi_publik_kartu_ringkas_revisi_dibatalkan_tetap_digantikan_tanpa_penunjuk(): void
    {
        [$asal, $revisi] = $this->asalDenganRevisiDibatalkan();
        $asal->forceFill(['snapshot' => null])->save();

        $this->get("/verify/{$asal->qr_token}")
            ->assertOk()
            ->assertSee('Digantikan')
            ->assertSee('dibatalkan')
            ->assertDontSee($revisi->nomor)
            ->assertDontSee(route('verify', $revisi->qr_token), false)
            ->assertDontSee('Yang berlaku');
    }

    public function test_resource_lab_revisi_dibatalkan_tidak_ditunjuk_tapi_status_dokumen_tetap_digantikan(): void
    {
        [$asal, $revisi] = $this->asalDenganRevisiDibatalkan();

        $this->actingAs($this->admin)->getJson("/api/certificates/{$asal->id}")
            ->assertOk()
            ->assertJsonPath('data.status_dokumen', 'digantikan')
            ->assertJsonPath('data.digantikan_oleh', null)
            // Sudah punya revisi (walau batal): tidak boleh direvisi/dibatalkan lagi.
            ->assertJsonPath('data.bisa_direvisi', false)
            ->assertJsonPath('data.bisa_dibatalkan', false);

        $this->actingAs($this->admin)->getJson("/api/certificates/{$revisi->id}")
            ->assertOk()
            ->assertJsonPath('data.status_dokumen', 'dibatalkan')
            ->assertJsonPath('data.revisi_dari.id', $asal->id);

        // Jalur daftar memakai resource yang sama.
        $baris = collect($this->actingAs($this->admin)->getJson('/api/certificates')->assertOk()->json('data'))
            ->firstWhere('id', $asal->id);
        $this->assertNotNull($baris, 'Sertifikat asal harus ada di daftar.');
        $this->assertNull($baris['digantikan_oleh']);
        $this->assertSame('digantikan', $baris['status_dokumen']);
    }

    public function test_qr_sertifikat_batal_menyatakan_dibatalkan_tanpa_alasan_dan_unduh_410(): void
    {
        $asal = $this->sertifikatTerbit();
        $this->actingAs($this->admin)->postJson("/api/certificates/{$asal->id}/batalkan", [
            'alasan' => 'Rahasia internal lab',
            'catatan_pelanggan' => 'Catatan untuk pelanggan saja',
        ])->assertOk();

        $this->get("/verify/{$asal->qr_token}")
            ->assertOk()
            ->assertSee('Dibatalkan')
            ->assertSee($asal->nomor)
            ->assertDontSee('Rahasia internal lab')
            ->assertDontSee('Catatan untuk pelanggan saja')
            ->assertDontSee('Unduh PDF');

        $this->get("/verify/{$asal->qr_token}/download")->assertStatus(410);

        $this->getJson("/api/verify/{$asal->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.status_dokumen', 'dibatalkan')
            ->assertJsonMissingPath('data.alasan_pembatalan');
    }

    /**
     * Kartu ringkas TIDAK menampilkan nama pelanggan sama sekali (ISO/IEC
     * 17025 klausul 4.2); lembar lengkap tetap ada di balik tombolnya.
     * Rincian di `VerifikasiPublikMinimalTest`.
     */
    public function test_kartu_ringkas_tidak_menampilkan_nama_pemilik(): void
    {
        $asal = $this->sertifikatTerbit();

        $html = $this->get("/verify/{$asal->qr_token}")
            ->assertOk()
            ->assertSee('Tampilkan lembar lengkap')
            ->getContent();

        $ringkas = substr($html, 0, (int) strpos($html, 'class="lembar-lengkap"'));
        $this->assertNotSame('', $ringkas);
        $this->assertStringNotContainsString('PT Contoh Jaya', $ringkas);
        $this->assertStringNotContainsString('PT Con••', $ringkas);
        $this->assertStringNotContainsString('Disamarkan sebagian', $ringkas);
    }
}
