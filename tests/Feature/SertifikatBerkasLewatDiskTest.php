<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Organization;
use App\Services\DataTampilanSertifikat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Penjaga: kop & logo sertifikat dibaca lewat ISI berkas, bukan lewat path
 * filesystem.
 *
 * ## Kenapa perlu
 *
 * Sampai 23 Agt 2026, `kopDataUri()` & `logoDataUri()` ngambil
 * `Storage::disk('public')->path()` lalu `file_get_contents()`. Itu jalan
 * sempurna selama disknya driver `local` — dan SELURUH suite jalan di disk
 * lokal, jadi nggak ada satu test pun yang bisa ngeliat masalahnya.
 *
 * Di S3/R2 dua pengandaian itu runtuh sekaligus:
 *
 *   1. `path()` nggak melempar error, dia balikin kunci bucket sebagai string.
 *      `file_get_contents()` atas string itu memicu warning yang diubah Laravel
 *      jadi ErrorException.
 *   2. `exists()` balikin true, jadi cabang bawaan `public/images` yang dulu
 *      ditulis pakai `elseif` nggak pernah kesentuh — jaring pengamannya ada
 *      tapi nggak pernah nangkap apa-apa.
 *
 * Gabungannya bukan "kop nggak kecetak". Exception-nya kelempar di tengah
 * `GenerateCertificate`, ketangkep `catch (\Throwable)` di situ, dan
 * sertifikatnya distempel `gagal` — buat tiap organisasi yang pernah ngunggah
 * kop atau logo.
 *
 * ## Kenapa pakai disk tiruan, bukan Storage::fake()
 *
 * `Storage::fake()` SELALU bikin disk driver lokal. Dipakai di sini, dia bakal
 * ikut menyembunyikan bug-nya persis seperti suite yang lama — `path()` tetap
 * bermakna, `file_get_contents()` tetap berhasil, test tetap hijau di kode yang
 * di produksi mati.
 *
 * Yang ditiru di sini justru SEMANTIK S3-nya: `exists()` yang bilang ya,
 * `path()` yang cuma balikin kunci, dan `get()` sebagai satu-satunya jalan yang
 * beneran ngasih isinya.
 */
class SertifikatBerkasLewatDiskTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Disk yang bersikap seperti S3/R2.
     *
     * @param  string|null  $isi  yang dibalikin `get()`; null = nggak kebaca
     */
    private function diskMiripS3(?string $isi): Filesystem
    {
        $disk = Mockery::mock(Filesystem::class);

        $disk->shouldReceive('exists')->andReturnTrue();

        // Inti tiruannya: di S3 path() balik kunci bucket, bukan berkas nyata.
        // Kode yang manggil ini bakal jatuh waktu isinya dibaca.
        $disk->shouldReceive('path')->andReturnUsing(fn (string $p): string => $p);

        $disk->shouldReceive('get')->andReturn($isi);

        return $disk;
    }

    private function sertifikatBerkop(): Certificate
    {
        $this->seed(DatabaseSeeder::class);

        $sertifikat = Certificate::with('organization')->firstOrFail();

        $sertifikat->organization->update([
            'logo_path' => 'logo-organisasi/1/logo.png',
            'settings' => [
                ...($sertifikat->organization->settings ?? []),
                Organization::KEY_KOP_PATH => 'kop-surat/1/kop.png',
            ],
        ]);

        return $sertifikat->fresh(['organization']);
    }

    /**
     * Yang paling menentukan: di disk yang nggak punya path filesystem,
     * sertifikatnya tetap kerender dan gambarnya tetap yang benar.
     */
    public function test_kop_dan_logo_dibaca_dari_isi_berkas_bukan_dari_path(): void
    {
        $sertifikat = $this->sertifikatBerkop();

        Storage::set('public', $this->diskMiripS3('GAMBAR-DARI-BUCKET'));

        $data = app(DataTampilanSertifikat::class)->untuk($sertifikat);

        $harapan = 'data:image/png;base64,'.base64_encode('GAMBAR-DARI-BUCKET');

        $this->assertSame($harapan, $data['logo'], 'Logo nggak diambil lewat get().');
        $this->assertSame($harapan, $data['kop'], 'Kop nggak diambil lewat get().');
    }

    /**
     * Sisi sebaliknya: berkas org yang nggak kebaca harus jatuh ke bawaan
     * `public/images`, bukan bikin penerbitan sertifikat berhenti.
     *
     * Ini yang dulu dimatikan `elseif` sesudah `exists()`.
     */
    public function test_berkas_org_yang_nggak_kebaca_jatuh_ke_bawaan(): void
    {
        $sertifikat = $this->sertifikatBerkop();

        Storage::set('public', $this->diskMiripS3(null));

        $data = app(DataTampilanSertifikat::class)->untuk($sertifikat);

        $bawaanLogo = base64_encode((string) file_get_contents(public_path('images/logo-sidik.png')));
        $bawaanKop = base64_encode((string) file_get_contents(public_path('images/kop-surat.png')));

        $this->assertSame('data:image/png;base64,'.$bawaanLogo, $data['logo']);
        $this->assertSame('data:image/png;base64,'.$bawaanKop, $data['kop']);
    }
}
