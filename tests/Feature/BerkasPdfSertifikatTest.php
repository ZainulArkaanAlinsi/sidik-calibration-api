<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\BerkasPdfSertifikat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `BerkasPdfSertifikat` membangun ulang PDF yang raib dari disk arsip.
 *
 * Yang diuji di sini bukan jalur bahagianya — itu sudah dijaga
 * `PdfSertifikatSelamatDariDeployTest`. Yang diuji: apa yang terjadi waktu
 * penulisannya TIDAK utuh.
 */
class BerkasPdfSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private function sertifikat(): Certificate
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', '2405.13.A')->firstOrFail();
        $ada = $sesi->certificate()->first();

        if ($ada !== null && $ada->status === Certificate::STATUS_TERBIT) {
            return $ada;
        }

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    /**
     * Berkas NOL BYTE di disk tidak boleh dianggap beres.
     *
     * `Storage::put()` menganggap menulis string kosong sebagai SUKSES, dan
     * `exists()` sesudahnya balik `true`. Jadi satu penulisan yang terpotong —
     * disk penuh di tengah jalan, misalnya, yang sangat mungkin di container
     * yang disknya sudah sempit — mendarat sebagai berkas 0 byte yang
     * DIANGGAP SEHAT selamanya.
     *
     * Akibatnya lebih buruk daripada 404 yang digantikannya: 404 kelihatan dan
     * bisa ditelusuri, sementara PDF 0 byte sampai ke pelanggan sebagai unduhan
     * rusak yang disangka masalah browsernya sendiri. Dan karena `exists()`
     * satu-satunya gerbang, jalur bangun-ulangnya tidak pernah menyala lagi.
     */
    public function test_berkas_nol_byte_dibangun_ulang_bukan_dilayani(): void
    {
        $sertifikat = $this->sertifikat();

        Storage::disk('arsip')->put($sertifikat->pdf_path, '');

        $path = app(BerkasPdfSertifikat::class)->pastikanAda($sertifikat);

        $this->assertNotNull($path, 'Berkas 0 byte bikin sertifikatnya dianggap hilang total.');
        $this->assertGreaterThan(
            10_000,
            strlen((string) Storage::disk('arsip')->get($path)),
            'Berkas 0 byte dilayani apa adanya, bukan dibangun ulang.',
        );
    }

    /** Berkas yang isinya bukan PDF juga bukan berkas yang sehat. */
    public function test_berkas_terpotong_dibangun_ulang(): void
    {
        $sertifikat = $this->sertifikat();

        // Penulisan yang mati di tengah: kepala PDF-nya ada, sisanya tidak.
        Storage::disk('arsip')->put($sertifikat->pdf_path, '%PDF-1.7 keputus di sini');

        app(BerkasPdfSertifikat::class)->pastikanAda($sertifikat);

        $isi = (string) Storage::disk('arsip')->get($sertifikat->pdf_path);

        $this->assertStringStartsWith('%PDF', $isi);
        $this->assertGreaterThan(10_000, strlen($isi), 'Berkas terpotong dibiarkan.');
    }

    /**
     * Dua permintaan berbarengan cuma boleh merender SEKALI.
     *
     * Jalur unduh QR itu tanpa login, dan sesudah deploy menghapus disk,
     * seluruh permintaan yang masuk menemukan berkasnya raib sekaligus. Tanpa
     * kunci, tiap permintaan menjalankan dompdf-nya sendiri — dan di jatah
     * 512 MB bersama queue worker & scheduler, itu jalan tercepat menuju OOM.
     *
     * Diuji dengan menahan kuncinya lebih dulu: pemanggil kedua harus MENUNGGU
     * lalu memakai hasil yang pertama, bukan ikut merender.
     */
    public function test_render_berbarengan_dikunci(): void
    {
        $sertifikat = $this->sertifikat();
        Storage::disk('arsip')->delete($sertifikat->pdf_path);

        $kunci = Cache::lock('sertifikat-pdf:'.$sertifikat->id, 30);

        $this->assertTrue($kunci->get(), 'Kuncinya nggak bisa diambil di test.');

        try {
            $mulai = microtime(true);
            $path = app(BerkasPdfSertifikat::class)->pastikanAda($sertifikat);
            $lama = microtime(true) - $mulai;
        } finally {
            $kunci->release();
        }

        $this->assertNull(
            $path,
            'Pemanggil kedua tetap merender walau ada yang lagi memegang kuncinya.',
        );
        $this->assertGreaterThan(
            1.0,
            $lama,
            'Pemanggil kedua nggak menunggu sama sekali — berarti nggak ada kunci.',
        );
    }

    /** PDF yang sehat TIDAK dirender ulang — kuncinya tetap `exists`, bukan selalu bangun. */
    public function test_pdf_sehat_tidak_dirender_ulang(): void
    {
        $sertifikat = $this->sertifikat();

        $path = app(BerkasPdfSertifikat::class)->pastikanAda($sertifikat);
        $this->assertNotNull($path);

        $sebelum = Storage::disk('arsip')->lastModified($path);

        app(BerkasPdfSertifikat::class)->pastikanAda($sertifikat);

        $this->assertSame(
            $sebelum,
            Storage::disk('arsip')->lastModified($path),
            'Berkas yang sehat ikut ditulis ulang tiap unduhan.',
        );
    }
}
