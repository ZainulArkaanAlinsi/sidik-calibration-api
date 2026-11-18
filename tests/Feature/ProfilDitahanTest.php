<?php

namespace Tests\Feature;

use App\Filament\Resources\CalibrationSessions\Pages\ListCalibrationSessions;
use App\Jobs\GenerateCertificate;
use App\Models\AuditLog;
use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SertifikatGagal;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\TimbanganProfile;
use App\Services\Calibration\VarianMasterTimbangan;
use App\Services\NomorSertifikat;
use App\Services\PenahananProfil;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification as NotifikasiPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penahanan PENERBITAN sertifikat per jenis alat sampai Technical Manager
 * menjawab (keputusan pemilik 18 Nov 2026): Timbangan kg, Proving Ring, Bath,
 * Picnometer (daftar di `config/sertifikat.php`), dan Tekanan x3 (sudah ditahan
 * lewat log metode, hook yang sama).
 *
 * Yang dijaga, dan kenapa tiap butir bisa diam kalau rusak:
 *
 *  - ketujuh jenis alat ditolak `422 profil_ditahan` di `approve()` API, tombol
 *    Setujui panel, dan job penerbitan; status sesi tidak berubah, nomor yang
 *    diketik belum terpakai, tidak ada job, dan ada jejak audit;
 *  - yang ditahan CUMA penerbitan: pengisian, pratinjau, dan hitung jalan terus;
 *  - jenis alat lain (dan varian gram/substitusi Timbangan) tidak terpengaruh;
 *  - melepas satu entri config melepas satu jenis alat saja;
 *  - tiap kode di config adalah kode profil yang TERDAFTAR. Salah ketik kode
 *    tidak menghasilkan galat apa pun — penahanannya saja yang tidak pernah
 *    menyala — jadi hanya test ini yang bisa menangkapnya.
 *
 * Daftarnya dibaca dari BERKAS config, bukan disalin ke sini: entri yang
 * dihapus/diubah diam-diam membuat test ini merah.
 */
class ProfilDitahanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    /** @var array<string, mixed> */
    private array $daftarAsli;

    protected function setUp(): void
    {
        parent::setUp();

        // Daftar NYATA dari berkas config — jangan diganti salinan.
        $this->daftarAsli = (require base_path('config/sertifikat.php'))['profil_ditahan'];
        config(['sertifikat.profil_ditahan' => $this->daftarAsli]);

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
    }

    /**
     * @param  array<string, mixed>  $alat
     * @param  array<string, mixed>  $sesi
     */
    private function sesiUntuk(string $kodeProfil, array $alat = [], array $sesi = []): CalibrationSession
    {
        $profil = app(CalibrationProfileRegistry::class)->untukKode($kodeProfil);
        $this->assertNotNull($profil, "Kode profil `{$kodeProfil}` tidak terdaftar di registry.");

        $equipment = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $this->org->id])->id,
            'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
            ...$alat,
        ]);

        return CalibrationSession::factory()->create([
            'organization_id' => $this->org->id,
            'equipment_id' => $equipment->id,
            'teknisi_id' => $this->teknisi->id,
            'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            ...$sesi,
        ]);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: string}> */
    public static function jenisAlatDiConfig(): array
    {
        return [
            'Timbangan kg (varian dipilih)' => ['timbangan', ['satuan' => 'kg', 'range_max' => 100], ['spesifikasi_alat' => ['varian_master' => 'kg']], 'TM-1'],
            'Timbangan kg (varian bawaan dari satuan kg, 100 kg)' => ['timbangan', ['satuan' => 'kg', 'range_max' => 100], [], 'TM-1'],
            'Proving Ring' => ['proving_ring', [], [], 'TM-4'],
            'Bath' => ['bath', [], [], 'TM-8'],
            'Picnometer' => ['picnometer', [], [], 'TM-7'],
        ];
    }

    private function setujui(CalibrationSession $sesi, ?User $oleh = null): TestResponse
    {
        return $this->actingAs($oleh ?? $this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", [
                'nomor_sertifikat' => 'UJI/TAHAN/0001',
                'abaikan_peringatan' => true,
            ]);
    }

    private function jumlahAuditDiubah(CalibrationSession $sesi): int
    {
        return AuditLog::query()
            ->where('entity_type', $sesi->getTable())
            ->where('entity_id', $sesi->id)
            ->where('action', AuditLog::ACTION_DIUBAH)
            ->count();
    }

    // ---- pintu 1: approve() API --------------------------------------------

    /**
     * @param  array<string, mixed>  $alat
     * @param  array<string, mixed>  $sesiAtribut
     */
    #[DataProvider('jenisAlatDiConfig')]
    public function test_approve_ditolak_422_status_tak_berubah_nomor_belum_terpakai_ada_jejak_dan_tanpa_job(
        string $kode,
        array $alat,
        array $sesiAtribut,
        string $rujukanTm,
    ): void {
        Queue::fake();
        $sesi = $this->sesiUntuk($kode, $alat, $sesiAtribut);
        $diubahSebelum = $this->jumlahAuditDiubah($sesi);

        $respons = $this->setujui($sesi)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'profil_ditahan')
            ->assertJsonPath('profil', $kode)
            ->assertJsonPath('rujukan_tm', $rujukanTm);

        $pesan = $respons->json('message');
        $this->assertStringStartsWith('Penerbitan sertifikat jenis alat ini ditahan menunggu keputusan Technical Manager: ', $pesan);
        $this->assertStringEndsWith('Lembar tetap bisa diisi dan dihitung.', $pesan);
        $this->assertStringContainsString($respons->json('alasan'), $pesan);
        $this->assertNotSame('', trim($respons->json('alasan')));

        // Status tidak berubah, tidak ada penyetuju, tidak ada sertifikat, tidak ada job.
        $segar = $sesi->fresh();
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $segar->status);
        $this->assertNull($segar->reviewed_by);
        $this->assertNull($segar->reviewed_at);
        $this->assertSame(0, Certificate::query()->count());
        $this->assertTrue(Queue::pushed(GenerateCertificate::class)->isEmpty());

        // Nomor yang diketik belum terpakai: masih bisa dipakai sesi lain.
        $this->assertNull(NomorSertifikat::periksa($this->org->id, 'UJI/TAHAN/0001'));

        // Jejak audit: satu baris, pelaku, pintu, alasan; bukan perubahan data.
        $jejak = AuditLog::query()->where('action', AuditLog::ACTION_PENERBITAN_DITAHAN)->sole();
        $this->assertSame($this->org->id, (int) $jejak->organization_id);
        $this->assertSame($sesi->getTable(), $jejak->entity_type);
        $this->assertSame($sesi->id, (int) $jejak->entity_id);
        $this->assertSame($this->admin->id, (int) $jejak->changed_by);
        $this->assertSame(PenahananProfil::PINTU_APPROVE_API, $jejak->new_data['pintu']);
        $this->assertSame($kode, $jejak->new_data['profil']);
        $this->assertSame($rujukanTm, $jejak->new_data['rujukan_tm']);
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $jejak->new_data['status_sesi']);
        $this->assertSame($respons->json('alasan'), $jejak->new_data['alasan']);
        $this->assertSame($pesan, $jejak->note);
        $this->assertSame($diubahSebelum, $this->jumlahAuditDiubah($sesi), 'Status tidak berubah, jadi tidak boleh ada baris `diubah`.');
    }

    public function test_penolakan_mendahului_pemeriksaan_lain_dan_tidak_bisa_dilewati(): void
    {
        Queue::fake();
        $sesi = $this->sesiUntuk('proving_ring');
        // Tanpa Order Number dan tanpa nomor sertifikat sekalipun: yang dijawab tetap penahanan.
        $sesi->forceFill(['nomor_order' => null])->save();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", [])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'profil_ditahan');

        // Tidak ada "setujui tetap": peringatan yang diabaikan tidak menembusnya.
        $this->setujui($sesi)->assertStatus(422)->assertJsonPath('kode', 'profil_ditahan');

        // Super admin pun tidak (satu pintu untuk semua peran).
        $super = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->setujui($sesi, $super)->assertStatus(422)->assertJsonPath('kode', 'profil_ditahan');

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        $this->assertTrue(Queue::pushed(GenerateCertificate::class)->isEmpty());
    }

    // ---- Timbangan: HANYA varian kg ----------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}> */
    public static function varianTimbangan(): array
    {
        return [
            'dipilih kg' => [['satuan' => 'kg', 'range_max' => 100], ['spesifikasi_alat' => ['varian_master' => 'kg']], true],
            'dipilih KG (huruf besar & spasi)' => [['satuan' => 'kg', 'range_max' => 100], ['spesifikasi_alat' => ['varian_master' => ' KG ']], true],
            'dipilih gram' => [['satuan' => 'g', 'range_max' => 54], ['spesifikasi_alat' => ['varian_master' => 'gram']], false],
            'dipilih substitusi' => [['satuan' => 'kg', 'range_max' => 2000], ['spesifikasi_alat' => ['varian_master' => 'substitusi']], false],
            'bawaan: satuan g' => [['satuan' => 'g', 'range_max' => 54], [], false],
            'bawaan: kapasitas di atas 200 kg' => [['satuan' => 'kg', 'range_max' => 2000], [], false],
            'bawaan: 200 kg tepat masih kg' => [['satuan' => 'kg', 'range_max' => 200], [], true],
            'bawaan: kapasitas tak diisi (tidak bisa ditentukan)' => [['satuan' => 'kg', 'range_max' => null], [], true],
            'dipilih gram tapi alat kapasitas besar: pilihan teknisi menang' => [['satuan' => 'kg', 'range_max' => 2000], ['spesifikasi_alat' => ['varian_master' => 'gram']], false],
            'dipilih kg walau satuan alat g: pilihan teknisi menang' => [['satuan' => 'g', 'range_max' => 54], ['spesifikasi_alat' => ['varian_master' => 'kg']], true],
        ];
    }

    /**
     * @param  array<string, mixed>  $alat
     * @param  array<string, mixed>  $sesiAtribut
     */
    #[DataProvider('varianTimbangan')]
    public function test_timbangan_hanya_varian_kg_yang_ditahan(array $alat, array $sesiAtribut, bool $ditahan): void
    {
        Queue::fake();
        $sesi = $this->sesiUntuk('timbangan', $alat, $sesiAtribut);

        $respons = $this->setujui($sesi)->assertStatus(422);

        if ($ditahan) {
            $respons->assertJsonPath('kode', 'profil_ditahan')->assertJsonPath('varian', 'kg');
            $this->assertNotNull(app(PenahananProfil::class)->periksa($sesi));
        } else {
            // Lolos gerbang ini; yang menolak berikutnya pemeriksaan lain (sesi tanpa hasil hitung).
            $this->assertNull($respons->json('kode'));
            $this->assertNull(app(PenahananProfil::class)->periksa($sesi));
            $this->assertSame(0, AuditLog::query()->where('action', AuditLog::ACTION_PENERBITAN_DITAHAN)->count());
        }

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
    }

    // ---- profil lain tidak terpengaruh -------------------------------------

    public function test_profil_lain_tidak_ditahan_dan_semua_kode_terdaftar_dibaca_hook_profilnya(): void
    {
        $registry = app(CalibrationProfileRegistry::class);
        $penahan = app(PenahananProfil::class);
        $ditahan = [];
        $bebas = [];

        foreach ($registry->semua() as $profil) {
            // Sesi tanpa baris di database: yang diuji hook profilnya, tidak lebih. Profil
            // yang menimpa hook (Tekanan, Piston) tetap membaca daftar config (`parent::`).
            $sesi = new CalibrationSession;
            $sesi->setRelation('equipment', new Equipment(['nama_alat_kemampuan' => $profil->namaAlatKemampuan(), 'satuan' => 'kg']));
            $sesi->setRelation('uncertaintyCalculations', collect());
            $sesi->setRelation('certificate', null);
            $vonis = $penahan->periksa($sesi, $profil);

            if ($vonis === null) {
                $bebas[] = $profil->kode();
            } else {
                $ditahan[] = $profil->kode();
            }
        }

        sort($ditahan);
        $diConfig = array_keys($this->daftarAsli);
        sort($diConfig);

        $this->assertSame($diConfig, $ditahan, 'Jenis alat yang ditahan harus persis daftar di config (profil yang menimpa hook wajib memanggil parent::).');
        $this->assertCount(48, $registry->semua());
        $this->assertCount(48 - count($diConfig), $bebas);

        // Saudara yang berbagi kelas/mesin dengan yang ditahan tidak ikut tertahan.
        foreach (['labu_ukur', 'pipet_volume', 'gelas_ukur', 'oven', 'inkubator', 'furnace', 'refrigerator', 'utm', 'load_cell', 'anak_timbangan', 'piston_pipette', 'ph_meter'] as $kode) {
            $this->assertContains($kode, $bebas, "{$kode} tidak boleh ikut tertahan.");
        }
    }

    /**
     * Penjaga untuk pola yang pernah menggigit di repo ini: profil yang MENIMPA hook
     * (Tekanan, Piston) dan lupa `parent::` membuat entri config untuk profil itu tidak
     * pernah menyala, tanpa galat apa pun. Dengan daftar yang memuat SEMUA kode, tiap profil
     * wajib menjawab "ditahan".
     */
    public function test_setiap_profil_menghormati_daftar_config_walau_menimpa_hook(): void
    {
        $registry = app(CalibrationProfileRegistry::class);
        config(['sertifikat.profil_ditahan' => collect($registry->semua())
            ->mapWithKeys(fn ($p) => [$p->kode() => ['tm' => 'TM-0', 'alasan' => 'uji semua kode']])
            ->all()]);

        foreach ($registry->semua() as $profil) {
            $sesi = new CalibrationSession;
            $sesi->setRelation('equipment', new Equipment(['nama_alat_kemampuan' => $profil->namaAlatKemampuan(), 'satuan' => 'kg']));
            $sesi->setRelation('uncertaintyCalculations', collect());

            $this->assertNotEmpty($profil->penahanTerbit($sesi), "{$profil->kode()} mengabaikan config/sertifikat.php (lupa memanggil parent::penahanTerbit()).");
        }
    }

    public function test_autoklaf_lolos_approve_penuh_lewat_gerbang_penahanan(): void
    {
        Queue::fake();
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $this->org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $this->org->id])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);

        $id = $this->actingAs($this->teknisi, 'sanctum')->postJson('/api/calibrations/autoclave', [
            'equipment_id' => $alat->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'suhu_awal' => 24.4, 'suhu_akhir' => 24.5, 'kelembaban_awal' => 55, 'kelembaban_akhir' => 56,
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
        ])->assertCreated()->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $sesi->forceFill(['nomor_order' => 'ORD/UJI/0001'])->save();

        $this->setujui($sesi)->assertOk();

        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi->fresh()->status);
        $this->assertFalse(Queue::pushed(GenerateCertificate::class)->isEmpty());
        $this->assertSame(0, AuditLog::query()->where('action', AuditLog::ACTION_PENERBITAN_DITAHAN)->count());
    }

    // ---- melepas entri = melepas satu jenis alat ---------------------------

    public function test_melepas_satu_entri_config_melepas_satu_jenis_alat_saja(): void
    {
        Queue::fake();
        $contoh = [
            'timbangan' => ['satuan' => 'kg', 'range_max' => 100],
            'proving_ring' => [],
            'bath' => [],
            'picnometer' => [],
        ];

        foreach ($contoh as $kode => $alat) {
            $sesi = $this->sesiUntuk($kode, $alat);
            $this->assertSame($kode, app(PenahananProfil::class)->periksa($sesi)['profil']);
        }

        foreach (array_keys($contoh) as $dilepas) {
            config(['sertifikat.profil_ditahan' => array_diff_key($this->daftarAsli, [$dilepas => true])]);

            foreach ($contoh as $kode => $alat) {
                $sesi = CalibrationSession::query()
                    ->whereHas('equipment', fn ($q) => $q->where('nama_alat_kemampuan', app(CalibrationProfileRegistry::class)->untukKode($kode)->namaAlatKemampuan()))
                    ->firstOrFail();
                $vonis = app(PenahananProfil::class)->periksa($sesi);

                if ($kode === $dilepas) {
                    $this->assertNull($vonis, "{$kode} harus lolos begitu entrinya dihapus.");
                    // Lolos gerbang ini; yang menolak berikutnya pemeriksaan lain (sesi tanpa hasil hitung).
                    $this->assertNull($this->setujui($sesi)->assertStatus(422)->json('kode'));
                } else {
                    $this->assertNotNull($vonis, "{$kode} harus tetap ditahan ketika {$dilepas} dilepas.");
                }
            }
        }

        $this->assertSame(0, Certificate::query()->count());
    }

    // ---- config yang rusak tidak boleh diam-diam melepas -------------------

    public function test_kode_di_config_terdaftar_dan_isinya_lengkap(): void
    {
        $registry = app(CalibrationProfileRegistry::class);

        $this->assertNotEmpty($this->daftarAsli);

        foreach ($this->daftarAsli as $kode => $entri) {
            $profil = $registry->untukKode((string) $kode);
            $this->assertNotNull($profil, "`{$kode}` di config/sertifikat.php bukan kode profil terdaftar — penahanannya tidak pernah menyala.");
            $this->assertSame($kode, $profil->kode());
            $this->assertNotSame('', trim((string) ($entri['alasan'] ?? '')), "{$kode}: alasan wajib diisi.");
            $this->assertMatchesRegularExpression('/^TM-\d+$/', (string) ($entri['tm'] ?? ''), "{$kode}: rujukan TM wajib (TM-n).");
        }

        // Timbangan: hanya varian master yang dikenal.
        foreach ($this->daftarAsli['timbangan']['varian'] as $varian) {
            $this->assertContains($varian, VarianMasterTimbangan::semua());
        }

        // Yang ditahan sesuai keputusan 18 Nov: KG saja, bukan seluruh Timbangan.
        $this->assertSame(['kg'], $this->daftarAsli['timbangan']['varian']);
        $this->assertSame(['bath', 'picnometer', 'proving_ring', 'timbangan'], collect(array_keys($this->daftarAsli))->sort()->values()->all());
    }

    public function test_entri_rusak_tetap_menahan_dan_config_bukan_array_tidak_meledak(): void
    {
        config(['sertifikat.profil_ditahan' => [
            'bath' => true,                                  // bukan array
            'picnometer' => ['alasan' => '', 'tm' => ''],   // alasan kosong
            'timbangan' => ['varian' => [], 'tm' => 'TM-1', 'alasan' => 'x'],  // varian kosong = semua varian
            'proving_ring' => ['varian' => 'kg', 'alasan' => 'y'],             // varian string
        ]]);

        $daftar = PenahananProfil::daftar();
        $this->assertNotSame('', $daftar['bath']['alasan']);
        $this->assertNotSame('', $daftar['picnometer']['alasan']);
        $this->assertNull($daftar['timbangan']['varian']);
        $this->assertSame(['kg'], $daftar['proving_ring']['varian']);

        // Timbangan gram ikut tertahan karena `varian` kosong = tanpa pembatasan.
        $this->assertNotEmpty(PenahananProfil::butirDariConfig('timbangan', 'gram'));
        // Varian yang TIDAK bisa ditentukan pada entri ber-varian: ditahan.
        $this->assertNotEmpty(PenahananProfil::butirDariConfig('proving_ring', null));
        $this->assertSame([], PenahananProfil::butirDariConfig('proving_ring', 'gram'));

        config(['sertifikat.profil_ditahan' => 'bukan array']);
        $this->assertSame([], PenahananProfil::daftar());
    }

    // ---- pintu 2: tombol Setujui panel -------------------------------------

    public function test_panel_menolak_dengan_pesan_yang_sama_status_tak_berubah_ada_jejak(): void
    {
        Queue::fake();
        $sesi = $this->sesiUntuk('bath');
        $pesan = app(PenahananProfil::class)->periksa($sesi)['message'];

        Livewire::actingAs($this->admin)
            ->test(ListCalibrationSessions::class)
            ->callAction(TestAction::make('approve')->table($sesi), ['nomor_sertifikat' => 'UJI/TAHAN/0002', 'abaikan_peringatan' => true])
            ->assertNotified(
                NotifikasiPanel::make()
                    ->title('Penerbitan sertifikat jenis alat ini ditahan.')
                    ->body($pesan)
                    ->danger()
                    ->persistent(),
            );

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        $this->assertNull($sesi->fresh()->reviewed_by);
        $this->assertTrue(Queue::pushed(GenerateCertificate::class)->isEmpty());
        $this->assertNull(NomorSertifikat::periksa($this->org->id, 'UJI/TAHAN/0002'));

        $jejak = AuditLog::query()->where('action', AuditLog::ACTION_PENERBITAN_DITAHAN)->sole();
        $this->assertSame(PenahananProfil::PINTU_APPROVE_PANEL, $jejak->new_data['pintu']);
        $this->assertSame($this->admin->id, (int) $jejak->changed_by);
        $this->assertSame('bath', $jejak->new_data['profil']);
    }

    // ---- pintu 3: job penerbitan -------------------------------------------

    public function test_job_menolak_sesi_yang_disetujui_sebelum_penahanan_dan_memberi_jalan_pulih(): void
    {
        Notification::fake();
        $sesi = $this->sesiUntuk('proving_ring', [], ['status' => CalibrationSession::STATUS_DISETUJUI]);

        (new GenerateCertificate($sesi->id, $this->admin->id, null, 'UJI/TAHAN/0003'))->handle();

        // Sesi tetap `disetujui` (tidak dimundurkan, tidak dihapus).
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi->fresh()->status);

        // Baris `gagal` supaya ada tombol pemulihan dan penyapu tidak mendorongnya
        // tiap sepuluh menit; nomor yang diketik tetap dipegang; tidak ada PDF.
        $sertifikat = $sesi->certificate()->sole();
        $this->assertSame(Certificate::STATUS_GAGAL, $sertifikat->status);
        $this->assertSame('UJI/TAHAN/0003', $sertifikat->nomor);
        $this->assertNull($sertifikat->pdf_path);
        $this->assertSame([], Storage::disk('arsip')->allFiles());

        $jejak = AuditLog::query()->where('action', AuditLog::ACTION_PENERBITAN_DITAHAN)->sole();
        $this->assertSame(PenahananProfil::PINTU_JOB_TERBIT, $jejak->new_data['pintu']);
        $this->assertSame($this->admin->id, (int) $jejak->changed_by);
        Notification::assertSentTo($this->admin, SertifikatGagal::class);

        // Retry (tombol "Terbitkan ulang" / penyapu) menghasilkan hal yang sama, bukan baris kedua.
        (new GenerateCertificate($sesi->id, $this->admin->id, null, 'UJI/TAHAN/0003'))->handle();
        $this->assertSame(1, $sesi->certificate()->count());
        $this->assertSame(Certificate::STATUS_GAGAL, $sesi->certificate()->sole()->status);

        // Dilepas: gerbang ini tidak lagi menahan job-nya.
        config(['sertifikat.profil_ditahan' => []]);
        $this->assertNull(app(PenahananProfil::class)->periksa($sesi->fresh()));
    }

    public function test_job_mengubah_baris_menunggu_generate_jadi_gagal_dengan_nomor_tetap(): void
    {
        Notification::fake();
        $sesi = $this->sesiUntuk('picnometer', [], ['status' => CalibrationSession::STATUS_DISETUJUI]);
        $baris = Certificate::factory()->menungguGenerate()->create([
            'organization_id' => $this->org->id,
            'calibration_session_id' => $sesi->id,
            'nomor' => 'UJI/TAHAN/0004',
        ]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        $baris->refresh();
        $this->assertSame(Certificate::STATUS_GAGAL, $baris->status);
        $this->assertSame('UJI/TAHAN/0004', $baris->nomor);
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi->fresh()->status);
        $this->assertSame(1, $sesi->certificate()->count());
        Notification::assertSentTo($this->admin, SertifikatGagal::class);
    }

    public function test_job_tidak_menyentuh_sertifikat_yang_sudah_terbit(): void
    {
        Notification::fake();
        $sesi = $this->sesiUntuk('proving_ring', [], ['status' => CalibrationSession::STATUS_DISETUJUI]);
        $terbit = Certificate::factory()->create([
            'organization_id' => $this->org->id,
            'calibration_session_id' => $sesi->id,
            'nomor' => 'UJI/TAHAN/0005',
            'pdf_path' => 'certificates/lama.pdf',
        ]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        $terbit->refresh();
        $this->assertSame(Certificate::STATUS_TERBIT, $terbit->status);
        $this->assertSame('certificates/lama.pdf', $terbit->pdf_path);
        $this->assertSame(0, AuditLog::query()->where('action', AuditLog::ACTION_PENERBITAN_DITAHAN)->count());
        Notification::assertNothingSent();

        // Dan klien melihatnya sebagai tidak ditahan: yang ditahan penerbitan BARU.
        $this->assertFalse(app(PenahananProfil::class)->ringkasan($sesi->fresh())['ditahan']);
    }

    // ---- tampilan ke klien (aditif) ----------------------------------------

    public function test_detail_sesi_memberi_tahu_klien_bahwa_penerbitan_ditahan(): void
    {
        $ditahan = $this->sesiUntuk('bath');
        $bebas = $this->sesiUntuk('oven');

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/calibrations/{$ditahan->id}")
            ->assertOk()
            ->assertJsonPath('data.penerbitan_ditahan.ditahan', true)
            ->assertJsonPath('data.penerbitan_ditahan.rujukan_tm', 'TM-8')
            ->assertJsonPath('data.penerbitan_ditahan.alasan', app(PenahananProfil::class)->periksa($ditahan)['alasan']);

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/calibrations/{$bebas->id}")
            ->assertOk()
            ->assertJsonPath('data.penerbitan_ditahan', ['ditahan' => false, 'alasan' => null, 'rujukan_tm' => null]);

        // Daftar ikut membawa kuncinya, tanpa query tambahan per baris yang berarti.
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/calibrations')
            ->assertOk()
            ->assertJsonFragment(['penerbitan_ditahan' => ['ditahan' => false, 'alasan' => null, 'rujukan_tm' => null]]);
    }

    public function test_kategori_memberi_tahu_klien_sebelum_lembar_dikirim(): void
    {
        $kategori = EquipmentCategory::factory()->create(['organization_id' => $this->org->id, 'kode' => 'massa-uji']);
        $profil = app(CalibrationProfileRegistry::class);

        foreach ([(new TimbanganProfile)->namaAlatKemampuan(), $profil->untukKode('proving_ring')->namaAlatKemampuan(), $profil->untukKode('oven')->namaAlatKemampuan()] as $nama) {
            $baris = CalibrationCapability::factory()->make(['equipment_category_id' => $kategori->id, 'nama_alat' => $nama]);
            $baris->organization_id = $this->org->id;
            $baris->save();
        }

        $kemampuan = collect(
            $this->actingAs($this->teknisi, 'sanctum')->getJson('/api/categories/massa-uji')->assertOk()->json('data.kemampuan'),
        )->keyBy('profil');

        $this->assertTrue($kemampuan['timbangan']['penerbitan_ditahan']['ditahan']);
        $this->assertSame(['kg'], $kemampuan['timbangan']['penerbitan_ditahan']['varian']);
        $this->assertSame('TM-1', $kemampuan['timbangan']['penerbitan_ditahan']['rujukan_tm']);
        $this->assertTrue($kemampuan['proving_ring']['penerbitan_ditahan']['ditahan']);
        $this->assertNull($kemampuan['proving_ring']['penerbitan_ditahan']['varian']);
        $this->assertSame(
            ['ditahan' => false, 'alasan' => null, 'rujukan_tm' => null, 'varian' => null],
            $kemampuan['oven']['penerbitan_ditahan'],
        );
    }

    // ---- audit: panjang kolom ----------------------------------------------

    public function test_jejak_audit_muat_di_kolom_walau_alasannya_panjang(): void
    {
        // Kolom `action` string(20) dan `note` string(500): MySQL menolak yang kepanjangan
        // (SQLite tidak), jadi panjangnya dijaga di sini.
        $this->assertLessThanOrEqual(20, mb_strlen(AuditLog::ACTION_PENERBITAN_DITAHAN));

        $panjang = str_repeat('Alasan yang sangat panjang tentang angka master. ', 40);
        config(['sertifikat.profil_ditahan' => ['bath' => ['tm' => 'TM-8', 'alasan' => $panjang]]]);
        $sesi = $this->sesiUntuk('bath');

        $this->setujui($sesi)->assertStatus(422)->assertJsonPath('kode', 'profil_ditahan');

        $jejak = AuditLog::query()->where('action', AuditLog::ACTION_PENERBITAN_DITAHAN)->sole();
        $this->assertLessThanOrEqual(AuditLog::NOTE_MAKS, mb_strlen((string) $jejak->note));
        $this->assertStringEndsWith('…', (string) $jejak->note);
        // Teks lengkap tidak hilang: ada di `new_data`.
        $this->assertStringContainsString(rtrim($panjang, ". \n"), $jejak->new_data['alasan']);
    }
}
