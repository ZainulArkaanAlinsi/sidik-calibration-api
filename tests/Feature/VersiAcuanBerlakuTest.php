<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\PaketAcuanVersi;
use App\Models\User;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Services\Acuan\SumberAcuan;
use App\Services\Calibration\CalibrationProfileRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-04 / BR-10 — versi data acuan yang berlaku pada sebuah tanggal
 * (`PenentuVersiAcuan::untukProfil`, `untukSesi`).
 *
 * Versi 1 lahir dari `acuan:impor-awal` untuk organisasi tanpa sesi, jadi
 * `berlaku_mulai`-nya 2024-01-01 (07 §3). Versi lain ditanam langsung dengan
 * tanggal yang ditentukan test.
 */
class VersiAcuanBerlakuTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisasi;

    private PaketAcuanVersi $v1;

    private PenentuVersiAcuan $penentu;

    protected function setUp(): void
    {
        parent::setUp();

        config(['acuan.sumber' => 'db']);
        SumberAcuan::lupakanCache();

        $this->organisasi = Organization::factory()->create();

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer', '--organisasi' => $this->organisasi->id])
            ->assertSuccessful();

        $this->v1 = PaketAcuanVersi::query()->sole();
        $this->assertSame('2024-01-01', $this->v1->berlaku_mulai->toDateString());

        $this->penentu = app(PenentuVersiAcuan::class);
    }

    /** Versi berikutnya paket micrometer organisasi ini (isi = v1). */
    private function versi(
        int $nomor,
        string $status,
        ?string $mulai,
        ?string $sampai = null,
        string $jenis = PaketAcuanVersi::JENIS_DATA,
        ?int $organisasiId = null,
    ): PaketAcuanVersi {
        return PaketAcuanVersi::create([
            'paket_acuan_id' => $this->v1->paket_acuan_id,
            'organization_id' => $organisasiId ?? $this->organisasi->id,
            'jenis' => $jenis,
            'nomor_versi' => $nomor,
            'status' => $status,
            'isi' => $this->v1->isi,
            'isi_sha256' => $this->v1->isi_sha256,
            'skema_versi' => 1,
            'dasar_versi_id' => $this->v1->id,
            'berlaku_mulai' => $mulai,
            'berlaku_sampai' => $sampai,
        ]);
    }

    private function berlaku(string $tanggal, ?int $organisasiId = null): ?int
    {
        return $this->penentu->untukProfil(
            $organisasiId ?? $this->organisasi->id,
            'micrometer',
            CarbonImmutable::parse($tanggal),
        )?->id;
    }

    private function sesi(string $namaAlat, ?string $tanggal, ?Organization $organisasi = null): CalibrationSession
    {
        $organisasi ??= $this->organisasi;

        return CalibrationSession::factory()->create([
            'organization_id' => $organisasi->id,
            'equipment_id' => Equipment::factory()->create([
                'organization_id' => $organisasi->id,
                'nama_alat_kemampuan' => $namaAlat,
            ])->id,
            'teknisi_id' => User::factory()->create(['organization_id' => $organisasi->id])->id,
            'status' => CalibrationSession::STATUS_DRAFT,
            'tanggal_kalibrasi' => $tanggal,
        ]);
    }

    public function test_tanggal_sebelum_v1_null(): void
    {
        $this->assertNull($this->berlaku('2023-12-31'), 'tanpa fallback ke versi aktif hari ini');
        $this->assertNull($this->penentu->untukSesi($this->sesi('Micrometer', '2023-12-31')));

        $this->assertSame($this->v1->id, $this->berlaku('2024-01-01'));
        $this->assertSame($this->v1->id, $this->penentu->untukSesi($this->sesi('Micrometer', '2025-05-02'))?->id);
    }

    public function test_batas_rentang_inklusif(): void
    {
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => '2024-06-30']);
        $v2 = $this->versi(2, PaketAcuanVersi::STATUS_AKTIF, '2024-07-01', '2024-12-31');

        $this->assertSame($this->v1->id, $this->berlaku('2024-01-01'), 'tepat di berlaku_mulai v1');
        $this->assertSame($this->v1->id, $this->berlaku('2024-06-30'), 'tepat di berlaku_sampai v1');
        $this->assertSame($v2->id, $this->berlaku('2024-07-01'), 'tepat di berlaku_mulai v2');
        $this->assertSame($v2->id, $this->berlaku('2024-12-31'), 'tepat di berlaku_sampai v2');
        $this->assertNull($this->berlaku('2025-01-01'), 'sesudah berlaku_sampai v2');

        // Jam di tanggal yang sama tidak menggeser hari.
        $this->assertSame($this->v1->id, $this->penentu->untukProfil(
            $this->organisasi->id, 'micrometer', CarbonImmutable::parse('2024-06-30 23:59:59'),
        )?->id);
    }

    public function test_v2_menutup_rentang_v1(): void
    {
        // Pengesahan v2 menutup v1 sehari sebelum v2 mulai (pola formula_versions).
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => '2025-02-28']);
        $v2 = $this->versi(2, PaketAcuanVersi::STATUS_AKTIF, '2025-03-01');

        $this->assertSame($this->v1->id, $this->berlaku('2025-02-28'));
        $this->assertSame($v2->id, $this->berlaku('2025-03-01'));
        $this->assertSame($v2->id, $this->berlaku('2030-01-01'), 'berlaku_sampai null = terbuka');

        // Sesi lama tetap v1 walau v2 sudah aktif hari ini (BR-10).
        $this->assertSame($this->v1->id, $this->penentu->untukSesi($this->sesi('Micrometer', '2024-09-09'))?->id);
        $this->assertSame($v2->id, $this->penentu->untukSesi($this->sesi('Micrometer', '2025-03-01'))?->id);

        // Rentang yang tumpang tindih (v1 belum ditutup): berlaku_mulai terakhir menang.
        $this->v1->update(['berlaku_sampai' => null]);
        $this->assertSame($v2->id, $this->berlaku('2025-03-01'));
        $this->assertSame($this->v1->id, $this->berlaku('2025-02-28'));
    }

    public function test_ditarik_terjadwal_draf_dan_status_lain_tidak_dipulangkan(): void
    {
        $nomor = 2;

        foreach ([
            PaketAcuanVersi::STATUS_DITARIK,
            PaketAcuanVersi::STATUS_TERJADWAL,
            PaketAcuanVersi::STATUS_DRAF,
            PaketAcuanVersi::STATUS_DIAJUKAN,
            PaketAcuanVersi::STATUS_DITOLAK,
            PaketAcuanVersi::STATUS_DIBUANG,
        ] as $status) {
            // Mulai SESUDAH v1 — kalau statusnya dihitung, dia yang menang.
            $this->versi($nomor++, $status, '2025-01-01');

            $this->assertSame($this->v1->id, $this->berlaku('2025-06-01'), "status {$status} tidak boleh dipulangkan");
        }

        // Versi `bentuk` (lapis 3) bukan data acuan.
        $this->versi(1, PaketAcuanVersi::STATUS_AKTIF, '2025-01-01', null, PaketAcuanVersi::JENIS_BENTUK);
        $this->assertSame($this->v1->id, $this->berlaku('2025-06-01'));

        // v1 sendiri ditarik → tidak ada versi berlaku sama sekali.
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_DITARIK]);
        $this->assertNull($this->berlaku('2025-06-01'));
    }

    public function test_organisasi_lain_terisolasi(): void
    {
        $lain = Organization::factory()->create();

        $this->assertNull($this->berlaku('2025-06-01', $lain->id), 'organisasi tanpa paket');
        $this->assertNull($this->penentu->untukSesi($this->sesi('Micrometer', '2025-06-01', $lain)));

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer', '--organisasi' => $lain->id])
            ->assertSuccessful();

        $milikLain = PaketAcuanVersi::query()->where('organization_id', $lain->id)->sole();

        $this->assertSame($milikLain->id, $this->berlaku('2025-06-01', $lain->id));
        $this->assertSame($this->v1->id, $this->berlaku('2025-06-01'));

        // Versi yang tersangkut di paket organisasi ini tapi bercap organisasi
        // lain tidak lolos hanya karena paketnya cocok.
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => '2024-12-31']);
        $this->versi(2, PaketAcuanVersi::STATUS_AKTIF, '2025-01-01', organisasiId: $lain->id);
        $this->assertNull($this->berlaku('2025-06-01'));
    }

    public function test_sumber_json_memulangkan_null(): void
    {
        $sesi = $this->sesi('Micrometer', '2025-05-02');
        $this->assertSame($this->v1->id, $this->penentu->untukSesi($sesi)?->id);

        config(['acuan.sumber' => 'json']);

        $this->assertNull($this->penentu->untukSesi($sesi));
        $this->assertNull($this->penentu->untukSesiTersimpan($sesi)['versi']);

        $jalan = $this->penentu->jalankan($sesi, fn (): string => 'dihitung');
        $this->assertSame(['hasil' => 'dihitung', 'versi' => null], $jalan);
    }

    public function test_profil_tanpa_skema_memulangkan_null(): void
    {
        $registry = app(CalibrationProfileRegistry::class);

        // pH Meter tidak punya skemaAcuan(). Dial Indicator punya (22 Okt 2026)
        // tapi paketnya belum diimpor di sini — dan walau memakai balok ukur
        // Micrometer, sesinya TIDAK boleh membuka lingkup micrometer.
        $this->assertNull($registry->untukKode('ph_meter')?->skemaAcuan(), 'ph_meter belum punya skemaAcuan()');

        foreach (['ph_meter', 'dial_indicator'] as $kode) {
            $profil = $registry->untukKode($kode);
            $this->assertNotNull($profil, $kode);

            $sesi = $this->sesi($profil->namaAlatKemampuan(), '2025-05-02');

            $this->assertNull($this->penentu->untukSesi($sesi), $kode);

            $jalan = $this->penentu->jalankan(
                $sesi,
                fn (): ?int => app(SumberAcuan::class)->versiDalamLingkup('micrometer')?->id,
            );
            $this->assertSame(['hasil' => null, 'versi' => null], $jalan, "{$kode}: tidak ada lingkup yang dibuka");
        }

        // Pembanding: sesi Micrometer membuka lingkup v1, dan versi yang
        // dipulangkan = versi lingkup.
        $jalan = $this->penentu->jalankan(
            $this->sesi('Micrometer', '2025-05-02'),
            fn (): ?int => app(SumberAcuan::class)->versiDalamLingkup('micrometer')?->id,
        );
        $this->assertSame($this->v1->id, $jalan['hasil']);
        $this->assertSame($this->v1->id, $jalan['versi']?->id);
        $this->assertNull(app(SumberAcuan::class)->versiDalamLingkup('micrometer'), 'lingkup dilepas sesudahnya');
    }

    public function test_tanggal_kosong_memakai_hari_ini(): void
    {
        $this->assertSame($this->v1->id, $this->penentu->untukSesi($this->sesi('Micrometer', null))?->id);
    }
}
