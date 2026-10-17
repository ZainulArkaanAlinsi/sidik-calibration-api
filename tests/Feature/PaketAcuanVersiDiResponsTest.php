<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\CertificateSnapshotBuilder;
use App\Services\PerhitunganBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SesiDataAcuan;
use Tests\TestCase;

/**
 * `paket_acuan_versi` di lembar perhitungan, validasi, dan snapshot sertifikat
 * (Studio Data Acuan B4) — dibaca dari STEMPEL hasil tersimpan
 * (`uncertainty_calculations.paket_acuan_versi_id`), bukan dicari ulang dari
 * tanggal kalibrasi atau versi yang berlaku sekarang.
 *
 * Stempel seragam → `{nomor, sha256, paket}`; null atau tidak seragam →
 * `null` di perhitungan & validasi, dan kuncinya TIDAK ADA di snapshot
 * sertifikat (tinjau P1 S-1: kunci `null` baru membuat `sertifikat:bangun-ulang`
 * menulis ulang semua sertifikat lama). Field lama ketiganya tidak berubah,
 * `CertificateSnapshotBuilder::VERSI` tidak naik.
 */
class PaketAcuanVersiDiResponsTest extends TestCase
{
    use RefreshDatabase;
    use SesiDataAcuan;

    /** Penanda "kunci `paket_acuan_versi` tidak ada di snapshot" — beda dari `null`. */
    private const TIDAK_ADA = '(kunci tidak ada)';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanAcuan();

        $this->admin = User::query()
            ->where('organization_id', $this->teknisi->organization_id)
            ->where('role', User::ROLE_ADMIN)
            ->where('status', User::STATUS_AKTIF)
            ->firstOrFail();
    }

    public function test_stempel_seragam_terbaca_di_ketiga_tempat(): void
    {
        $id = $this->simpanSesi($this->payloadMicrometer());
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($id));

        $harapan = ['nomor' => 1, 'sha256' => $this->v1->isi_sha256, 'paket' => 'micrometer'];

        $this->assertSame($harapan, $this->tigaTempat($id)['perhitungan']);
        $this->assertSame($harapan, $this->tigaTempat($id)['validasi']);
        $this->assertSame($harapan, $this->tigaTempat($id)['snapshot']);
    }

    public function test_stempel_null_atau_tidak_seragam_null(): void
    {
        $id = $this->simpanSesi($this->payloadMicrometer());

        // Tidak seragam: satu baris kehilangan stempelnya.
        UncertaintyCalculation::query()
            ->where('calibration_session_id', $id)
            ->orderBy('titik_ke')
            ->limit(1)
            ->update(['paket_acuan_versi_id' => null]);
        $this->assertContains(null, $this->stempelSesi($id));
        $this->assertSame(['perhitungan' => null, 'validasi' => null, 'snapshot' => self::TIDAK_ADA], $this->tigaTempat($id));

        // Semua null — tidak ditebak dari versi yang berlaku pada tanggalnya.
        UncertaintyCalculation::query()->where('calibration_session_id', $id)->update(['paket_acuan_versi_id' => null]);
        $this->assertSame(['perhitungan' => null, 'validasi' => null, 'snapshot' => self::TIDAK_ADA], $this->tigaTempat($id));
    }

    public function test_sesi_sumber_json_tanpa_stempel_null(): void
    {
        config(['acuan.sumber' => 'json']);

        $id = $this->simpanSesi($this->payloadMicrometer());
        $this->assertSame([null, null, null], $this->stempelSesi($id));

        // Dibaca saat sumber sudah `db` pun tetap null: yang dilaporkan stempel.
        config(['acuan.sumber' => 'db']);
        $this->assertSame(['perhitungan' => null, 'validasi' => null, 'snapshot' => self::TIDAK_ADA], $this->tigaTempat($id));
    }

    /**
     * S-1: snapshot sertifikat TERBIT yang disusun builder lama (sebelum kunci
     * `paket_acuan_versi` ada) pada sesi tanpa stempel dinilai "nggak berubah"
     * oleh `sertifikat:bangun-ulang` — perbandingan `==` yang sama dengan yang
     * memutuskan tulis ulang. Pembanding sesi berstempel membuktikan
     * perbandingannya tidak hampa: kunci itu memang terdeteksi kalau ada.
     */
    public function test_bangun_ulang_tidak_menganggap_snapshot_lama_tanpa_stempel_berubah(): void
    {
        config(['acuan.sumber' => 'json']);
        $tanpaStempel = CalibrationSession::findOrFail($this->simpanSesi($this->payloadMicrometer()));
        $this->assertSame([null, null, null], $this->stempelSesi($tanpaStempel->id));

        config(['acuan.sumber' => 'db']);
        $berstempel = CalibrationSession::findOrFail($this->simpanSesi($this->payloadMicrometer()));
        $this->assertSame([$this->v1->id, $this->v1->id, $this->v1->id], $this->stempelSesi($berstempel->id));

        $lamaTanpa = $this->terbitDenganSnapshotBuilderLama($tanpaStempel);
        $lamaBerstempel = $this->terbitDenganSnapshotBuilderLama($berstempel);

        $this->artisan('sertifikat:bangun-ulang', ['sesi' => [$tanpaStempel->nomor_sesi], '--dry-run' => true])
            ->expectsOutputToContain('nggak berubah')
            ->doesntExpectOutputToContain('paket_acuan_versi')
            ->assertSuccessful();

        // Tanpa --dry-run pun tidak ada yang ditulis.
        $this->artisan('sertifikat:bangun-ulang', ['sesi' => [$tanpaStempel->nomor_sesi]])
            ->expectsOutputToContain('0 sertifikat dibangun ulang, 0 dilewati, 0 gagal.')
            ->assertSuccessful();
        $this->assertEquals($lamaTanpa->snapshot, $lamaTanpa->fresh()->snapshot);
        $this->assertSame($lamaTanpa->validasi, $lamaTanpa->fresh()->validasi);

        // Pembanding: sesi berstempel memang mendapat kuncinya.
        $this->artisan('sertifikat:bangun-ulang', ['sesi' => [$berstempel->nomor_sesi], '--dry-run' => true])
            ->expectsOutputToContain('berubah: paket_acuan_versi')
            ->assertSuccessful();
        $this->assertArrayNotHasKey('paket_acuan_versi', $lamaBerstempel->fresh()->snapshot, 'dry-run tidak menulis');
    }

    public function test_field_lama_tidak_berubah(): void
    {
        $id = $this->simpanSesi($this->payloadMicrometer());
        $sesi = CalibrationSession::findOrFail($id);

        $perhitungan = app(PerhitunganBuilder::class)->bangun($sesi);
        $this->assertSame(
            ['nomor_sesi', 'identitas_alat', 'identitas_customer', 'kondisi_lingkungan', 'hasil', 'paket_acuan_versi'],
            array_keys($perhitungan),
        );

        $validasi = $this->actingAs($this->admin)->getJson("/api/calibrations/{$id}/validasi")->assertOk()->json('data');
        $this->assertSame(['valid', 'boleh_terbit', 'temuan', 'ringkasan', 'paket_acuan_versi'], array_keys($validasi));

        $snapshot = $this->snapshot($sesi);
        $this->assertSame(1, CertificateSnapshotBuilder::VERSI);
        $this->assertSame(CertificateSnapshotBuilder::VERSI, $snapshot['versi']);
        $this->assertSame('paket_acuan_versi', array_key_last($snapshot), 'kunci tambahan, di ekor');
        foreach (['desimal', 'header', 'hasil', 'catatan', 'standar_digunakan', 'footer', 'meta'] as $kunci) {
            $this->assertArrayHasKey($kunci, $snapshot);
        }

        // Tanpa stempel: kunci lain persis sama, cuma `paket_acuan_versi` yang absen.
        UncertaintyCalculation::query()->where('calibration_session_id', $id)->update(['paket_acuan_versi_id' => null]);
        $tanpaStempel = $this->snapshot(CalibrationSession::findOrFail($id));
        $this->assertSame(array_slice(array_keys($snapshot), 0, -1), array_keys($tanpaStempel));
    }

    /** @return array{perhitungan: mixed, validasi: mixed, snapshot: mixed} */
    private function tigaTempat(int $id): array
    {
        return [
            'perhitungan' => $this->actingAs($this->admin)
                ->getJson("/api/calibrations/{$id}/perhitungan")
                ->assertOk()
                ->json('data.paket_acuan_versi'),
            'validasi' => $this->actingAs($this->admin)
                ->getJson("/api/calibrations/{$id}/validasi")
                ->assertOk()
                ->json('data.paket_acuan_versi'),
            'snapshot' => $this->snapshotStempel(CalibrationSession::findOrFail($id)),
        ];
    }

    /** `paket_acuan_versi` di snapshot, atau [TIDAK_ADA] bila kuncinya absen. */
    private function snapshotStempel(CalibrationSession $sesi): mixed
    {
        $snapshot = $this->snapshot($sesi);

        return array_key_exists('paket_acuan_versi', $snapshot) ? $snapshot['paket_acuan_versi'] : self::TIDAK_ADA;
    }

    /**
     * Sertifikat TERBIT yang snapshot-nya seperti disusun builder sebelum
     * Studio Data Acuan: keluaran builder sekarang TANPA `paket_acuan_versi`
     * (selain kunci itu, builder tidak berubah di branch ini).
     */
    private function terbitDenganSnapshotBuilderLama(CalibrationSession $sesi): Certificate
    {
        $sertifikat = Certificate::factory()->create([
            'organization_id' => $sesi->organization_id,
            'calibration_session_id' => $sesi->id,
        ]);

        $snapshot = app(CertificateSnapshotBuilder::class)->bangun($sesi, $sertifikat);
        unset($snapshot['paket_acuan_versi']);
        $sertifikat->update(['snapshot' => $snapshot]);

        return $sertifikat;
    }

    /** @return array<string, mixed> */
    private function snapshot(CalibrationSession $sesi): array
    {
        $sertifikat = Certificate::query()->where('calibration_session_id', $sesi->id)->first()
            ?? Certificate::factory()->create([
                'organization_id' => $sesi->organization_id,
                'calibration_session_id' => $sesi->id,
            ]);

        return app(CertificateSnapshotBuilder::class)->bangun($sesi, $sertifikat);
    }
}
