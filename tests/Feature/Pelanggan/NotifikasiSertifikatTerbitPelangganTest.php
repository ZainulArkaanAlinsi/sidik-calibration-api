<?php

namespace Tests\Feature\Pelanggan;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Pelanggan\SertifikatAndaTerbit;
use App\Notifications\Pelanggan\SertifikatBerubah;
use App\Notifications\SertifikatTerbit;
use App\Services\CertificateSnapshotBuilder;
use App\Services\Pelanggan\KabarSertifikatTerbit;
use App\Services\Pelanggan\PreferensiNotifikasi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Sertifikat TERBIT -> anggota aktif perusahaan pemilik alat dikabari otomatis.
 *
 * Yang dijaga bukan cuma "terkirim", tapi tiga hal yang tidak menghasilkan
 * error kalau salah:
 *
 * - tidak bocor ke perusahaan lain (ISO/IEC 17025 klausul 4.2);
 * - tidak dobel kalau job diulang;
 * - kabar yang gagal tidak pernah menggagalkan penerbitan sertifikat.
 *
 * Jalurnya sungguhan: approve lewat API -> `GenerateCertificate` (antrean
 * `sync` di test) -> notifikasi tersimpan di tabel `notifications`.
 */
class NotifikasiSertifikatTerbitPelangganTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    private Customer $perusahaan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('arsip');

        $this->org = Organization::factory()->create();
        $this->siapkanJalurPelanggan();

        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_TEKNISI]);
        $this->perusahaan = Customer::factory()->create(['organization_id' => $this->org->id]);
    }

    public function test_anggota_aktif_perusahaan_pemilik_alat_dikabari_saat_sertifikat_terbit(): void
    {
        $pic = $this->anggotaDi($this->perusahaan, CustomerMember::PERAN_PIC_UTAMA);
        $staf = $this->anggotaDi($this->perusahaan, CustomerMember::PERAN_STAF);

        $sertifikat = $this->terbitkan('KAL/N/001');

        foreach ([$pic, $staf] as $user) {
            $baris = $user->notifications()->where('type', SertifikatAndaTerbit::class)->get();
            $this->assertCount(1, $baris);

            $data = $baris->first()->data;
            $this->assertSame('pelanggan.sertifikat_terbit', $data['kategori']);
            // Tautan = tipe yang sudah dipetakan aplikasi pelanggan ke layar detail sertifikat.
            $this->assertSame(['tipe' => 'pelanggan_sertifikat', 'id' => $sertifikat->id], $data['tautan']);
            $this->assertStringContainsString('KAL/N/001', $data['body']);
            $this->assertStringContainsString('Autoclave', $data['body']);
            $this->assertStringContainsString($sertifikat->diterbitkan_pada->format('d-m-Y'), $data['body']);
        }
    }

    public function test_isinya_netral_tanpa_data_sensitif_lain(): void
    {
        $user = $this->anggotaDi($this->perusahaan);
        $this->perusahaan->update(['nama' => 'PT Rahasia Sekali', 'alamat' => 'Jl. Pribadi 99']);

        $sertifikat = $this->terbitkan('KAL/N/002');
        $sertifikat->session->equipment->update(['serial_number' => 'SN-RAHASIA-777']);

        $data = $user->notifications()->where('type', SertifikatAndaTerbit::class)->sole()->data;
        $semua = json_encode($data);

        foreach (['PT Rahasia Sekali', 'Jl. Pribadi 99', 'SN-RAHASIA-777', $this->teknisi->name, $this->admin->name, $this->admin->email, 'PASS', 'FAIL'] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $semua, "Payload membawa `{$terlarang}`.");
        }

        // Payload push (layar kunci) memakai judul/isi yang sama; tidak ada kunci tambahan.
        $push = SertifikatAndaTerbit::dariSertifikat($sertifikat)->toPush($user);
        $this->assertSame(['kategori', 'tujuan', 'notification_id', 'android_channel_id', 'tautan_tipe', 'tautan_id'], array_keys($push['data']));
        $this->assertSame('pelanggan_sertifikat:'.$sertifikat->id, $push['data']['tujuan']);
    }

    public function test_anggota_nonaktif_dan_perusahaan_lain_tidak_dikabari(): void
    {
        $aktif = $this->anggotaDi($this->perusahaan);
        $nonaktif = $this->anggotaDi($this->perusahaan, CustomerMember::PERAN_STAF, CustomerMember::STATUS_NONAKTIF);
        $akunNonaktif = $this->anggotaDi($this->perusahaan, CustomerMember::PERAN_STAF, CustomerMember::STATUS_AKTIF, User::STATUS_NONAKTIF);

        $lain = Customer::factory()->create(['organization_id' => $this->org->id]);
        $anggotaLain = $this->anggotaDi($lain, CustomerMember::PERAN_PIC_UTAMA);

        // Orang yang anggota DUA perusahaan: hanya perusahaan pemilik alat yang mengabarinya
        // lewat sertifikat ini, dan perusahaan lain tidak ikut terbuka.
        $this->terbitkan('KAL/N/003');

        $this->assertSame(1, $aktif->notifications()->count());
        $this->assertSame(0, $nonaktif->notifications()->count(), 'Anggota nonaktif dikabari.');
        $this->assertSame(0, $akunNonaktif->notifications()->count(), 'Akun nonaktif dikabari.');
        $this->assertSame(0, $anggotaLain->notifications()->count(), 'Perusahaan lain dikabari.');
    }

    public function test_perusahaan_lain_di_organisasi_lain_tidak_pernah_dikabari(): void
    {
        $orgLain = Organization::factory()->create();
        $asing = Customer::factory()->create(['organization_id' => $orgLain->id]);
        $akunAsing = User::factory()->create([
            'organization_id' => $orgLain->id, 'role' => User::ROLE_PELANGGAN, 'status' => User::STATUS_AKTIF,
        ]);
        CustomerMember::create([
            'organization_id' => $orgLain->id, 'customer_id' => $asing->id, 'user_id' => $akunAsing->id,
            'peran' => CustomerMember::PERAN_PIC_UTAMA, 'status' => CustomerMember::STATUS_AKTIF,
        ]);
        $this->anggotaDi($this->perusahaan);

        $this->terbitkan('KAL/N/004');

        $this->assertSame(0, $akunAsing->notifications()->count());
    }

    public function test_saklar_status_permintaan_dihormati(): void
    {
        $mati = $this->anggotaDi($this->perusahaan);
        $nyala = $this->anggotaDi($this->perusahaan);

        app(PreferensiNotifikasi::class)->simpan(
            $mati->keanggotaan()->sole(),
            [PreferensiNotifikasi::STATUS_PERMINTAAN => false],
        );

        $this->terbitkan('KAL/N/005');

        $this->assertSame(0, $mati->notifications()->count(), 'Saklar dimatikan tapi tetap dikabari.');
        $this->assertSame(1, $nyala->notifications()->count());
    }

    public function test_teknisi_dan_admin_penerbit_tetap_dikabari_seperti_dulu(): void
    {
        $this->anggotaDi($this->perusahaan);

        $this->terbitkan('KAL/N/006');

        $this->assertSame(1, $this->teknisi->notifications()->where('type', SertifikatTerbit::class)->count());
        $this->assertSame(1, $this->admin->notifications()->where('type', SertifikatTerbit::class)->count());
        // Dan kedua notifikasi internal TIDAK bocor ke pelanggan.
        $this->assertSame(0, $this->teknisi->notifications()->where('type', SertifikatAndaTerbit::class)->count());
    }

    public function test_job_diulang_tidak_mengirim_ganda(): void
    {
        $user = $this->anggotaDi($this->perusahaan);

        $sertifikat = $this->terbitkan('KAL/N/007');
        $sesiId = $sertifikat->calibration_session_id;

        // Job yang sama jalan lagi (worker kedua / retry antrean).
        (new GenerateCertificate($sesiId, $this->admin->id))->handle();
        (new GenerateCertificate($sesiId, $this->admin->id))->handle();

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, $this->teknisi->notifications()->where('type', SertifikatTerbit::class)->count());

        // Lapis kedua: dipanggil langsung berkali-kali pun tidak menambah baris.
        $this->assertSame(0, app(KabarSertifikatTerbit::class)->kabari($sertifikat->fresh()));
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_retry_sertifikat_gagal_hanya_mengabari_sekali_dan_baru_saat_terbit(): void
    {
        $user = $this->anggotaDi($this->perusahaan);

        $this->app->bind(CertificateSnapshotBuilder::class, fn () => new class extends CertificateSnapshotBuilder
        {
            public function bangun(CalibrationSession $sesi, Certificate $sertifikat): array
            {
                throw new \RuntimeException('Snapshot meledak (disengaja).');
            }
        });

        $sesi = $this->sesiDisetujui();

        try {
            (new GenerateCertificate($sesi->id, $this->admin->id, null, 'KAL/N/008'))->handle();
            $this->fail('Harus melempar ulang.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(Certificate::STATUS_GAGAL, $sesi->certificate()->first()->status);
        $this->assertSame(0, $user->notifications()->count(), 'Sertifikat gagal tidak boleh dikabarkan sebagai terbit.');

        // Admin menekan "Terbitkan ulang": pembuat snapshot normal lagi.
        $this->app->forgetInstance(CertificateSnapshotBuilder::class);
        $this->app->bind(CertificateSnapshotBuilder::class, fn () => new CertificateSnapshotBuilder);

        (new GenerateCertificate($sesi->id, $this->admin->id, null, 'KAL/N/008'))->handle();
        (new GenerateCertificate($sesi->id, $this->admin->id, null, 'KAL/N/008'))->handle();

        $this->assertSame(Certificate::STATUS_TERBIT, $sesi->certificate()->first()->status);
        $this->assertSame(1, $user->notifications()->where('type', SertifikatAndaTerbit::class)->count());
    }

    public function test_hanya_status_terbit_yang_dikabarkan(): void
    {
        $user = $this->anggotaDi($this->perusahaan);
        $sertifikat = $this->terbitkan('KAL/N/009');

        foreach ([
            Certificate::STATUS_GAGAL,
            Certificate::STATUS_MENUNGGU_GENERATE,
            Certificate::STATUS_DITARIK,
            Certificate::STATUS_DIBATALKAN,
        ] as $status) {
            DB::table('notifications')->delete();
            DB::table('certificates')->where('id', $sertifikat->id)->update(['status' => $status]);

            $this->assertSame(0, app(KabarSertifikatTerbit::class)->kabari($sertifikat->fresh()), "Status {$status} dikabarkan.");
            $this->assertSame(0, $user->notifications()->count());
        }

        DB::table('certificates')->where('id', $sertifikat->id)->update(['status' => Certificate::STATUS_TERBIT]);
        $this->assertSame(1, app(KabarSertifikatTerbit::class)->kabari($sertifikat->fresh()));
    }

    public function test_kegagalan_notifikasi_tidak_menggagalkan_penerbitan_dan_log_tanpa_data_pelanggan(): void
    {
        $user = $this->anggotaDi($this->perusahaan);

        // Penerima gagal dipilih (mis. basis data tersendat) dengan pesan galat
        // yang SENGAJA memuat email — log tidak boleh membawanya.
        $this->app->bind(PreferensiNotifikasi::class, fn () => new class extends PreferensiNotifikasi
        {
            public function penerima(Customer $pelanggan, string $kunci): Collection
            {
                throw new \RuntimeException('SQLSTATE gagal untuk rahasia@pelanggan.test');
            }
        });
        Log::spy();

        $sertifikat = $this->terbitkan('KAL/N/010');

        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->fresh()->status);
        $this->assertNotNull($sertifikat->fresh()->pdf_path);
        $this->assertSame(0, $user->notifications()->count());
        // Kabar internal tidak ikut terhenti.
        $this->assertSame(1, $this->teknisi->notifications()->where('type', SertifikatTerbit::class)->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $pesan, array $konteks = []): bool {
                return str_contains($pesan, 'mengabari pelanggan gagal')
                    && ! str_contains(json_encode($konteks), 'rahasia@pelanggan.test')
                    && ($konteks['certificate_id'] ?? null) !== null;
            })
            ->once();
    }

    public function test_service_yang_meledak_total_tidak_menggagalkan_job(): void
    {
        $this->anggotaDi($this->perusahaan);

        $this->app->bind(KabarSertifikatTerbit::class, fn () => throw new \RuntimeException('container meledak'));

        $sertifikat = $this->terbitkan('KAL/N/011');

        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->fresh()->status);
    }

    public function test_satu_penerima_gagal_tidak_menghentikan_rekannya(): void
    {
        $pertama = $this->anggotaDi($this->perusahaan);
        $kedua = $this->anggotaDi($this->perusahaan);

        $sertifikat = $this->terbitkan('KAL/N/012');
        DB::table('notifications')->where('type', SertifikatAndaTerbit::class)->delete();

        // Akun pertama gagal dicek riwayatnya; yang kedua tetap harus menerima.
        $rusak = new class extends User
        {
            public function notifications()
            {
                throw new \RuntimeException('antrean menolak');
            }
        };
        $rusak->forceFill($pertama->getAttributes());
        $rusak->exists = true;

        $preferensi = new class($rusak, $kedua) extends PreferensiNotifikasi
        {
            public function __construct(private User $a, private User $b) {}

            public function penerima(Customer $pelanggan, string $kunci): Collection
            {
                return collect([$this->a, $this->b]);
            }
        };

        $this->assertSame(1, (new KabarSertifikatTerbit($preferensi))->kabari($sertifikat->fresh()));
        $this->assertSame(1, $kedua->notifications()->count());
    }

    public function test_revisi_dari_tarikan_memakai_kabar_direvisi_ke_semua_anggota_aktif_tanpa_ganda(): void
    {
        $user = $this->anggotaDi($this->perusahaan);
        $mati = $this->anggotaDi($this->perusahaan);
        app(PreferensiNotifikasi::class)->simpan($mati->keanggotaan()->sole(), [PreferensiNotifikasi::STATUS_PERMINTAAN => false]);

        $lama = $this->terbitkan('KAL/N/013');
        $sesi = $lama->session;

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/certificates/{$lama->id}/tarik", ['alasan' => 'Angka disk 1 salah salin dari logger.', 'password' => 'password'])
            ->assertOk();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        $baru = Certificate::where('revision_of', $lama->id)->sole();
        $this->assertSame(Certificate::STATUS_TERBIT, $baru->status);

        // Terbit pertama: 1 kabar "terbit". Revisi: 1 kabar "direvisi" — dan saklar TIDAK
        // menahannya (dokumen yang dipegang pelanggan berubah, ISO/IEC 17025 §7.8.8).
        $this->assertSame(1, $user->notifications()->where('type', SertifikatAndaTerbit::class)->count());
        $this->assertSame(1, $user->notifications()->where('type', SertifikatBerubah::class)->count());
        $this->assertSame(1, $mati->notifications()->where('type', SertifikatBerubah::class)->count());
        $this->assertSame(0, $mati->notifications()->where('type', SertifikatAndaTerbit::class)->count());

        $data = $user->notifications()->where('type', SertifikatBerubah::class)->sole()->data;
        $this->assertSame('pelanggan.sertifikat_direvisi', $data['kategori']);
        $this->assertSame(['tipe' => 'pelanggan_sertifikat', 'id' => $baru->id], $data['tautan']);

        // Diulang -> tetap satu.
        $this->assertSame(0, app(KabarSertifikatTerbit::class)->kabari($baru->fresh()));
        $this->assertSame(1, $user->notifications()->where('type', SertifikatBerubah::class)->count());
    }

    public function test_kotak_masuk_pelanggan_menampilkannya_lewat_endpoint_yang_sudah_ada(): void
    {
        $user = $this->anggotaDi($this->perusahaan);
        $lain = $this->anggotaDi(Customer::factory()->create(['organization_id' => $this->org->id]));

        $sertifikat = $this->terbitkan('KAL/N/014');

        $this->permintaanBaru();
        $this->withHeaders($this->bearer($user))
            ->getJson('/api/pelanggan/v1/notifikasi')
            ->assertOk()
            ->assertJsonPath('data.0.kategori', 'pelanggan.sertifikat_terbit')
            ->assertJsonPath('data.0.tautan.tipe', 'pelanggan_sertifikat')
            ->assertJsonPath('data.0.tautan.id', $sertifikat->id)
            ->assertJsonPath('meta.total', 1);

        $this->permintaanBaru();
        $this->withHeaders($this->bearer($lain))
            ->getJson('/api/pelanggan/v1/notifikasi')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    // ── bantuan ────────────────────────────────────────────────────────────

    private function anggotaDi(
        Customer $perusahaan,
        string $peran = CustomerMember::PERAN_STAF,
        string $statusAnggota = CustomerMember::STATUS_AKTIF,
        string $statusAkun = User::STATUS_AKTIF,
    ): User {
        $user = $this->pelanggan($statusAkun);

        CustomerMember::create([
            'organization_id' => $perusahaan->organization_id,
            'customer_id' => $perusahaan->id,
            'user_id' => $user->id,
            'peran' => $peran,
            'status' => $statusAnggota,
        ]);

        return $user->refresh();
    }

    /** Approve sampai sertifikat terbit (job `sync`). */
    private function terbitkan(string $nomor): Certificate
    {
        $sesi = $this->sesiAntre();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $nomor, 'abaikan_peringatan' => true])
            ->assertOk();

        return Certificate::where('calibration_session_id', $sesi->id)->sole()->load('session.equipment');
    }

    /** Sesi yang sudah `disetujui`, belum ada baris sertifikat. */
    private function sesiDisetujui(): CalibrationSession
    {
        $sesi = $this->sesiAntre();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        return $sesi->fresh();
    }

    /** Sesi autoklaf kiriman teknisi, alat milik `$this->perusahaan` (pola `NomorSertifikatDanTarikTest`). */
    private function sesiAntre(): CalibrationSession
    {
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->perusahaan->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $this->org->id])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);

        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', [
                'equipment_id' => $alat->id,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'suhu_awal' => 24.4, 'suhu_akhir' => 24.5,
                'kelembaban_awal' => 55, 'kelembaban_akhir' => 56,
                'set_point' => 121.0,
                'suhu' => [
                    'disk' => [
                        [121.27, 121.26, 121.26, 121.26, 121.28],
                        [121.30, 121.26, 121.26, 121.25, 121.25],
                        [121.26, 121.26, 121.28, 121.35, 121.28],
                    ],
                    'indikator' => [121, 121, 121, 121, 121],
                    'suhu_ruang' => [25, 25, 25, 25, 25],
                ],
                'tekanan' => [
                    'uut_setting' => 0.112, 'satuan' => 'MPa', 'display' => 'Digital',
                    'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->denganOrderNumber($id);

        return CalibrationSession::findOrFail($id);
    }
}
