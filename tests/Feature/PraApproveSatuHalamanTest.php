<?php

namespace Tests\Feature;

use App\Exceptions\SertifikatLebihSatuHalamanException;
use App\Filament\Resources\CalibrationSessions\Pages\ListCalibrationSessions;
use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use App\Services\NomorSertifikat;
use App\Services\PraPeriksaSatuHalaman;
use App\Services\SertifikatSatuHalaman;
use App\Services\TarikSertifikat;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gerbang PRA-APPROVE satu halaman (PK-6, audit D3): sesi yang sertifikatnya
 * terbukti tidak muat satu halaman ditolak SEBELUM disetujui — tidak ada status
 * berubah, tidak ada nomor sertifikat terbuang, tidak ada job.
 *
 * Dompdf BENERAN di sini (bukan dipalsukan) — logika vonisnya sendiri diuji
 * terpisah di `Tests\Unit\PraPeriksaSatuHalamanTest`. Yang dijaga di sini hal
 * yang cuma kelihatan kalau seluruh jalur jalan:
 *
 *  - TEPAT DI BATAS: panjang alamat terbesar yang masih muat disetujui dan
 *    terbit; satu karakter lebih panjang ditolak. Batasnya dicari test ini
 *    sendiri (pencarian biner di renderer asli), bukan angka tempelan, jadi
 *    tetap benar kalau tata letak bergeser.
 *  - Sisi sebaliknya dibuktikan: tanpa gerbang, sesi di atas batas itu memang
 *    disetujui lalu sertifikatnya `gagal` — gerbang menutup lubang yang nyata.
 *  - Isi sertifikat sementara gerbang == isi sertifikat yang diterbitkan job
 *    (kecuali token QR acak), supaya vonisnya vonis atas lembar yang SAMA.
 *
 * Sesi Autoklaf, bukan sesi factory polos: lihat `SinkronJadwalAlatTest`.
 */
class PraApproveSatuHalamanTest extends TestCase
{
    use RefreshDatabase;

    private const KATA = 'Jl. Contoh Industri Raya Kav. 12 Kawasan Pergudangan Terpadu ';

    /** Batas kolom `calibration_sessions.pemilik_alamat` di `CalibrationRequest`. */
    private const ALAMAT_MAKS = 1000;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('arsip');
        $this->freezeTime();

        $this->org = Organization::factory()->create(['nama' => 'PT Contoh Satu']);
        $this->teknisi = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_TEKNISI]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN]);

        $this->alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => Customer::factory()->create([
                'organization_id' => $this->org->id,
                'nama' => 'PT Contoh Pelanggan 1',
            ])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $this->org->id])->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);
    }

    // ------------------------------------------------------------------ bahan

    /** Angka input dari master `Autoclave_CSV/INPUT_DATA.csv` baris 36-39 (sama dengan `SinkronJadwalAlatTest`). */
    private function sesiAutoklaf(): CalibrationSession
    {
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', [
                'equipment_id' => $this->alat->id,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'suhu_awal' => 24.4,
                'suhu_akhir' => 24.5,
                'kelembaban_awal' => 55,
                'kelembaban_akhir' => 56,
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
                    'uut_setting' => 0.112,
                    'satuan' => 'MPa',
                    'display' => 'Digital',
                    'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->denganOrderNumber($id);

        return CalibrationSession::findOrFail($id);
    }

    /** Alamat `$n` karakter (potongan deret kata yang sama: pemanjangan HANYA menambah teks di ujung). */
    private function setAlamat(CalibrationSession $sesi, int $n): void
    {
        DB::table('calibration_sessions')->where('id', $sesi->id)->update([
            'pemilik_alamat' => $n === 0 ? null : rtrim(substr(str_repeat(self::KATA, 40), 0, $n)),
        ]);
    }

    private function setujui(CalibrationSession $sesi, string $nomor, array $tambahan = [])
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $nomor, 'abaikan_peringatan' => true, ...$tambahan]);
    }

    private function tidakMuat(CalibrationSession $sesi, int $n, ?string $nomor = 'UJI/BATAS'): bool
    {
        $this->setAlamat($sesi, $n);

        return app(PraPeriksaSatuHalaman::class)->periksa($sesi->fresh(), $this->admin, $nomor) !== null;
    }

    /**
     * Panjang alamat TERBESAR yang masih muat satu halaman, dicari di renderer asli.
     * Monotonik: menambah teks di ujung tidak pernah memendekkan lembar.
     */
    private function batasAlamat(CalibrationSession $sesi): int
    {
        $this->assertFalse($this->tidakMuat($sesi, 0), 'Sesi polos harus muat — kalau tidak, fixture-nya yang rusak.');
        $this->assertTrue(
            $this->tidakMuat($sesi, self::ALAMAT_MAKS),
            'Alamat sepanjang batas validasi ('.self::ALAMAT_MAKS.' karakter) harus meluapkan Autoklaf; '
            .'kalau tidak, test ini tidak punya sisi "tidak muat" dan harus memakai isian lain.',
        );

        [$muat, $luap] = [0, self::ALAMAT_MAKS];

        while ($luap - $muat > 1) {
            $tengah = intdiv($muat + $luap, 2);

            if ($this->tidakMuat($sesi, $tengah)) {
                $luap = $tengah;
            } else {
                $muat = $tengah;
            }
        }

        return $muat;
    }

    private function jumlahHalaman(string $pdf): int
    {
        return preg_match_all('/\/Type\s*\/Page(?![a-z])/', $pdf);
    }

    /**
     * Renderer ASLI yang mencatat tiap vonis gerbang `[nomor sertifikat sementara, vonis]`
     * ke `$vonis` — untuk membuktikan gerbangnya benar-benar dipanggil, dengan nomor apa.
     */
    private function bindRendererPencatat(\ArrayObject $vonis): void
    {
        $this->app->bind(SertifikatSatuHalaman::class, fn () => new class(app(DataTampilanSertifikat::class), $vonis) extends SertifikatSatuHalaman
        {
            public function __construct(DataTampilanSertifikat $data, private \ArrayObject $vonis)
            {
                parent::__construct($data);
            }

            public function terbuktiTidakMuat(Certificate $sertifikat): ?SertifikatLebihSatuHalamanException
            {
                $hasil = parent::terbuktiTidakMuat($sertifikat);
                $this->vonis[] = [$sertifikat->nomor, $hasil];

                return $hasil;
            }
        });
    }

    // ------------------------------------------------------------------ gerbang

    /** Sesi yang muat: disetujui & terbit seperti biasa — dan gerbangnya BENAR-BENAR jalan. */
    public function test_sesi_yang_muat_disetujui_dan_terbit_dan_gerbang_jalan(): void
    {
        $sesi = $this->sesiAutoklaf();
        $nomor = $this->nomorUji();
        $vonis = new \ArrayObject;
        $this->bindRendererPencatat($vonis);

        $this->setujui($sesi, $nomor)->assertOk();

        $this->assertCount(1, $vonis, 'Gerbang pra-approve tidak jalan (atau jalan lebih dari sekali).');
        $this->assertSame($nomor, $vonis[0][0], 'Gerbang harus menilai lembar dengan nomor yang diketik admin.');
        $this->assertNull($vonis[0][1]);

        $sertifikat = Certificate::where('calibration_session_id', $sesi->id)->sole();
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
        $this->assertSame($nomor, $sertifikat->nomor);
        $this->assertSame(1, $this->jumlahHalaman(Storage::disk('arsip')->get($sertifikat->pdf_path)));
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi->fresh()->status);
    }

    /** Terbukti tidak muat: 422 dengan pesan jelas, dan TIDAK ada yang terbuang. */
    public function test_sesi_tidak_muat_ditolak_sebelum_status_nomor_dan_job(): void
    {
        $sesi = $this->sesiAutoklaf();
        $nomor = $this->nomorUji();
        $this->setAlamat($sesi, self::ALAMAT_MAKS);

        $respons = $this->setujui($sesi, $nomor);

        $respons->assertStatus(422)
            ->assertJsonPath('kode', PraPeriksaSatuHalaman::KODE)
            ->assertJsonPath('halaman', 2);
        $this->assertStringContainsString('TIDAK disetujui', (string) $respons->json('message'));
        $this->assertStringContainsString('nomor sertifikat belum terpakai', (string) $respons->json('message'));

        $segar = $sesi->fresh();
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $segar->status);
        $this->assertNull($segar->reviewed_by);
        $this->assertNull($segar->reviewed_at);
        $this->assertSame(0, Certificate::where('calibration_session_id', $sesi->id)->count(), 'Tidak boleh ada baris sertifikat (apalagi `gagal`).');
        $this->assertSame([], Storage::disk('arsip')->allFiles('certificates'));
        $this->assertFalse(
            AuditLog::where('entity_type', 'calibration_sessions')->where('entity_id', $sesi->id)->get()
                ->contains(fn (AuditLog $a): bool => ($a->new_data['status'] ?? null) === CalibrationSession::STATUS_DISETUJUI),
            'Tidak boleh ada jejak audit persetujuan untuk sesi yang ditolak gerbang.',
        );

        // Nomornya benar-benar belum terpakai: tersedia lagi, dan memang bisa dipakai sesudah isinya dipangkas.
        $this->assertNull(NomorSertifikat::periksa($this->org->id, $nomor));
        $this->setAlamat($sesi, 0);
        $this->setujui($sesi, $nomor)->assertOk();
        $this->assertSame($nomor, Certificate::where('calibration_session_id', $sesi->id)->value('nomor'));
    }

    /**
     * TEPAT DI BATAS, dua sisi, lawan job sungguhan: panjang terbesar yang muat
     * terbit satu halaman; satu karakter lagi ditolak gerbang — dan tanpa
     * gerbang, sesi yang sama memang berakhir `gagal` di job.
     */
    public function test_tepat_di_batas_gerbang_dan_job_sepakat(): void
    {
        $sesi = $this->sesiAutoklaf();
        $batas = $this->batasAlamat($sesi);
        $this->assertGreaterThan(0, $batas);
        $this->assertLessThan(self::ALAMAT_MAKS, $batas);

        // Sisi muat: batas persis.
        $this->setAlamat($sesi, $batas);
        $nomorMuat = $this->nomorUji();
        $this->setujui($sesi, $nomorMuat)->assertOk();
        $terbit = Certificate::where('calibration_session_id', $sesi->id)->sole();
        $this->assertSame(Certificate::STATUS_TERBIT, $terbit->status, "Alamat {$batas} karakter lolos gerbang tapi gagal di job.");
        $this->assertSame(1, $this->jumlahHalaman(Storage::disk('arsip')->get($terbit->pdf_path)));

        // Sisi luap: sesi kedua, alamat batas + 1. Ditolak gerbang.
        $sesi2 = $this->sesiAutoklaf();
        $this->setAlamat($sesi2, $batas + 1);
        $this->setujui($sesi2, $this->nomorUji())->assertStatus(422)->assertJsonPath('kode', PraPeriksaSatuHalaman::KODE);
        $this->assertSame(0, Certificate::where('calibration_session_id', $sesi2->id)->count());
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi2->fresh()->status);

        // Bukti bahwa gerbang menutup lubang yang NYATA: sakelarnya dimatikan, sesi yang
        // sama disetujui, dan JOB-lah yang menyerah `gagal` dengan nomor yang sudah terpakai.
        config(['sertifikat.pra_approve_satu_halaman' => false]);
        $nomorLuap = $this->nomorUji();
        // 503 = antrean `sync` di test meneruskan kegagalan job ke request (produksi: antrean
        // database, request-nya 200 dan sertifikatnya `gagal` belakangan). Yang dibuktikan di
        // bawah sama: sesi SUDAH disetujui, nomor SUDAH terpakai, PDF tidak ada.
        $this->setujui($sesi2, $nomorLuap)->assertStatus(503);
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi2->fresh()->status);
        $gagal = Certificate::where('calibration_session_id', $sesi2->id)->sole();
        $this->assertSame(Certificate::STATUS_GAGAL, $gagal->status, 'Alamat di atas batas harus gagal di job bila gerbang dimatikan.');
        $this->assertSame($nomorLuap, $gagal->nomor, 'Itulah nomor yang terbuang bila gerbang tidak ada.');
    }

    /** Isi sertifikat sementara gerbang == isi sertifikat yang diterbitkan job (kecuali token QR acak). */
    public function test_sertifikat_sementara_sama_dengan_yang_diterbitkan_job(): void
    {
        $sesi = $this->sesiAutoklaf();
        $nomor = $this->nomorUji();

        $sementara = app(PraPeriksaSatuHalaman::class)->sertifikatSementara($sesi, $this->admin, $nomor);

        $this->assertNull($sementara->getKey(), 'Sertifikat sementara tidak boleh tersimpan.');
        $this->assertSame(0, Certificate::count());

        $this->setujui($sesi, $nomor)->assertOk();
        $nyata = Certificate::where('calibration_session_id', $sesi->id)->sole();

        $banding = static function (array $snapshot): array {
            unset($snapshot['meta']['qr_token'], $snapshot['meta']['qr_payload']);

            return $snapshot;
        };

        $this->assertSame($banding($nyata->snapshot), $banding($sementara->snapshot));
        $this->assertSame(
            strlen((string) $nyata->qr_token),
            strlen((string) $sementara->qr_token),
            'Token QR sementara harus sepanjang token asli: QR-nya yang menentukan ukuran gambar.',
        );
        $this->assertSame(strlen((string) $nyata->qr_payload), strlen((string) $sementara->qr_payload));
    }

    /** Terbit ulang sesudah sertifikat ditarik: nomor `-R1` dan catatan revisi ikut dinilai. */
    public function test_sertifikat_sementara_untuk_terbit_ulang_sama_dengan_job(): void
    {
        $sesi = $this->sesiAutoklaf();
        $this->setujui($sesi, 'KAL/PRA/001')->assertOk();
        $lama = Certificate::where('calibration_session_id', $sesi->id)->sole();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/certificates/{$lama->id}/tarik", ['alasan' => 'Angka disk 1 salah salin dari logger.', 'password' => 'password'])
            ->assertOk();

        $dasar = TarikSertifikat::yangDitarik($sesi->fresh());
        $this->assertNotNull($dasar);

        $pemeriksa = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $sementara = app(PraPeriksaSatuHalaman::class)->sertifikatSementara($sesi->fresh(), $pemeriksa, null, $dasar);

        $this->actingAs($pemeriksa, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();
        $baru = Certificate::where('revision_of', $lama->id)->sole();

        $this->assertSame('KAL/PRA/001-R1', $baru->nomor);
        $this->assertSame($baru->nomor, $sementara->nomor);

        $banding = static function (array $snapshot): array {
            unset($snapshot['meta']['qr_token'], $snapshot['meta']['qr_payload']);

            return $snapshot;
        };

        $this->assertSame($banding($baru->snapshot), $banding($sementara->snapshot));
        $this->assertSame('Revisi ke-1, menggantikan KAL/PRA/001', $sementara->snapshot['header']['catatan_revisi']);
    }

    /**
     * Satu render gerbang pada satu waktu (jatah memori 512 MB dibagi dengan queue
     * worker): yang tidak kebagian kunci TIDAK merender dan approve-nya jalan; kunci
     * dilepas begitu vonis selesai — juga sesudah penolakan.
     */
    public function test_kunci_render_gerbang_dibagi_dan_selalu_dilepas(): void
    {
        $sesi = $this->sesiAutoklaf();
        $vonis = new \ArrayObject;
        $this->bindRendererPencatat($vonis);
        Log::spy();

        // Gerbang lain sedang merender: dilewati tanpa render, approve tetap jalan.
        $penahan = Cache::lock(PraPeriksaSatuHalaman::KUNCI_RENDER, 60);
        $this->assertTrue($penahan->get());
        $this->setujui($sesi, $this->nomorUji())->assertOk();
        $this->assertCount(0, $vonis, 'Gerbang yang tidak kebagian kunci tidak boleh merender.');
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $pesan): bool => str_contains($pesan, 'gerbang lain sedang merender'),
        )->once();
        $penahan->release();

        // Kunci lepas -> gerbang jalan, dan kunci dilepas lagi sesudah vonis (muat).
        $sesi2 = $this->sesiAutoklaf();
        $this->setujui($sesi2, $this->nomorUji())->assertOk();
        $this->assertCount(1, $vonis);
        $kunci = Cache::lock(PraPeriksaSatuHalaman::KUNCI_RENDER, 5);
        $this->assertTrue($kunci->get(), 'Kunci render gerbang tidak dilepas sesudah vonis muat.');
        $kunci->release();

        // ...dan sesudah PENOLAKAN juga.
        $sesi3 = $this->sesiAutoklaf();
        $this->setAlamat($sesi3, self::ALAMAT_MAKS);
        $this->setujui($sesi3, $this->nomorUji())->assertStatus(422);
        $kunci = Cache::lock(PraPeriksaSatuHalaman::KUNCI_RENDER, 5);
        $this->assertTrue($kunci->get(), 'Kunci render gerbang tidak dilepas sesudah penolakan.');
        $kunci->release();
    }

    /** Gerbang yang rusak sendiri TIDAK boleh menghentikan approve: jalur terbit yang memutuskan. */
    public function test_galat_di_gerbang_dilewatkan_dan_dicatat(): void
    {
        $sesi = $this->sesiAutoklaf();
        $nomor = $this->nomorUji();
        Log::spy();

        $this->app->bind(SertifikatSatuHalaman::class, fn () => new class(app(DataTampilanSertifikat::class)) extends SertifikatSatuHalaman
        {
            public function terbuktiTidakMuat(Certificate $sertifikat): ?SertifikatLebihSatuHalamanException
            {
                throw new \RuntimeException('dompdf kehabisan memori (palsu)');
            }
        });

        $this->setujui($sesi, $nomor)->assertOk();

        $this->assertSame(Certificate::STATUS_TERBIT, Certificate::where('calibration_session_id', $sesi->id)->value('status'));
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $pesan, array $konteks): bool => str_contains($pesan, 'Pra-periksa satu halaman dilewati')
                && ($konteks['calibration_session_id'] ?? null) === $sesi->id
                && $konteks['galat'] === \RuntimeException::class,
        )->once();
    }

    /** Pintu kedua: tombol Setujui di panel memakai gerbang yang sama. */
    public function test_panel_menolak_sesi_tidak_muat_dan_menerima_yang_muat(): void
    {
        $sesi = $this->sesiAutoklaf();
        $this->setAlamat($sesi, self::ALAMAT_MAKS);

        Livewire::actingAs($this->admin)
            ->test(ListCalibrationSessions::class)
            ->callAction(TestAction::make('approve')->table($sesi), ['nomor_sertifikat' => 'PANEL/PRA/001', 'abaikan_peringatan' => true]);

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        $this->assertSame(0, Certificate::where('calibration_session_id', $sesi->id)->count());
        $this->assertNull(NomorSertifikat::periksa($this->org->id, 'PANEL/PRA/001'), 'Nomor yang diketik tidak boleh terpakai.');

        $this->setAlamat($sesi, 0);

        Livewire::actingAs($this->admin)
            ->test(ListCalibrationSessions::class)
            ->callAction(TestAction::make('approve')->table($sesi), ['nomor_sertifikat' => 'PANEL/PRA/001', 'abaikan_peringatan' => true]);

        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi->fresh()->status);
        $this->assertSame('PANEL/PRA/001', Certificate::where('calibration_session_id', $sesi->id)->value('nomor'));
    }
}
