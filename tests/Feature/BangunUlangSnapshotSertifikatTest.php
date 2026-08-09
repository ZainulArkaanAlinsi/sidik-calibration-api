<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `sertifikat:bangun-ulang` — nyusun ulang ISI sertifikat yang udah terbit,
 * tanpa nyentuh identitasnya.
 *
 * Perintah ini ada karena `snapshot` itu beku: disusun sekali waktu approve,
 * jadi bug format/olah data yang udah dibetulin tetap kelihatan salah di
 * sertifikat lama selamanya.
 *
 * Yang paling dijaga di sini bukan "snapshot-nya keupdate", tapi **nomor & QR
 * token-nya TETAP**. `GenerateCertificate` nggak bisa dipakai buat ini justru
 * karena `updateOrCreate`-nya bakal ngasih nomor baru + token baru — dan token
 * itu yang dicocokin halaman verifikasi, jadi ngegantinya matiin QR yang
 * mungkin udah kecetak di dokumen pelanggan.
 */
class BangunUlangSnapshotSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private function sertifikatTerbit(): Certificate
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        return Certificate::where('status', Certificate::STATUS_TERBIT)
            ->whereHas('session', fn ($q) => $q->where('nomor_sesi', '2405.13.A'))
            ->firstOrFail();
    }

    public function test_nomor_dan_qr_token_nggak_ikut_berubah(): void
    {
        $sebelum = $this->sertifikatTerbit();

        $nomor = $sebelum->nomor;
        $token = $sebelum->qr_token;
        $payload = $sebelum->qr_payload;
        $terbit = $sebelum->diterbitkan_pada;

        $this->artisan('sertifikat:bangun-ulang')->assertSuccessful();

        $sesudah = $sebelum->fresh();

        $this->assertSame($nomor, $sesudah->nomor, 'nomor sertifikat ikut berubah — QR & dokumen cetaknya jadi nggak nyambung');
        $this->assertSame($token, $sesudah->qr_token, 'qr_token diputer — QR yang udah kecetak langsung mati');
        $this->assertSame($payload, $sesudah->qr_payload);
        $this->assertEquals($terbit, $sesudah->diterbitkan_pada);
        $this->assertSame(Certificate::STATUS_TERBIT, $sesudah->status);
    }

    /**
     * Snapshot basi ditulis ulang ngikut kode yang sekarang. Ini inti gunanya:
     * kelembaban yang kesimpen dengan format lama (`%RH: 52%`) mesti berubah
     * jadi dua desimal tanpa nunggu sesinya diulang.
     */
    public function test_snapshot_lama_ditulis_ulang_ngikut_kode_sekarang(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $basi = $sertifikat->snapshot;
        $basi['header']['env_condition'] = 'T: 99,9°C ± 9,9°C — %RH: 99% ± 9%';
        $sertifikat->update(['snapshot' => $basi]);

        $this->artisan('sertifikat:bangun-ulang')->assertSuccessful();

        $this->assertSame(
            'T: 21,0°C ± 1,7°C — %RH: 51,95% ± 5,66%',
            $sertifikat->fresh()->snapshot['header']['env_condition'],
        );
    }

    public function test_dry_run_nggak_nulis_apa_apa(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $basi = $sertifikat->snapshot;
        $basi['header']['env_condition'] = 'T: 99,9°C ± 9,9°C — %RH: 99% ± 9%';
        $sertifikat->update(['snapshot' => $basi]);

        $this->artisan('sertifikat:bangun-ulang', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(
            'T: 99,9°C ± 9,9°C — %RH: 99% ± 9%',
            $sertifikat->fresh()->snapshot['header']['env_condition'],
        );
    }

    /** Sesi tertentu doang — yang lain jangan ikut kesenggol. */
    public function test_bisa_dibatesin_ke_sesi_tertentu(): void
    {
        $this->sertifikatTerbit();

        $lain = Certificate::where('status', Certificate::STATUS_TERBIT)
            ->whereHas('session', fn ($q) => $q->where('nomor_sesi', 'SES/2026/07/0001'))
            ->firstOrFail();

        $basi = $lain->snapshot;
        $basi['header']['env_condition'] = 'JANGAN DISENTUH';
        $lain->update(['snapshot' => $basi]);

        $this->artisan('sertifikat:bangun-ulang', ['sesi' => ['2405.13.A']])->assertSuccessful();

        $this->assertSame('JANGAN DISENTUH', $lain->fresh()->snapshot['header']['env_condition']);
    }

    /**
     * Perintah ini nyusun ulang dari data sesi APA ADANYA — dia bukan alat buat
     * ngubah hasil. Pembacaan yang salah ketik tetap salah sesudah dijalanin;
     * yang betulin itu alur revisi di aplikasi.
     */
    public function test_nggak_ngubah_angka_hasil_kalibrasi(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $sebelum = $sertifikat->snapshot['hasil'];

        $this->artisan('sertifikat:bangun-ulang')->assertSuccessful();

        $this->assertEquals($sebelum, $sertifikat->fresh()->snapshot['hasil']);
    }

    public function test_sertifikat_yang_belum_terbit_dilewati(): void
    {
        $this->sertifikatTerbit();

        $belum = CalibrationSession::whereDoesntHave('certificate')->first();

        $this->artisan('sertifikat:bangun-ulang')->assertSuccessful();

        if ($belum !== null) {
            $this->assertNull($belum->fresh()->certificate);
        }
    }
}
