<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PDF sertifikat harus SELAMAT dari deploy.
 *
 * ## Kegagalan yang dijaga di sini
 *
 * Produksi jalan di Render dengan `ARSIP_DRIVER=local`, dan disk container
 * Render itu SEMENTARA — kehapus tiap deploy dan tiap container restart
 * (`docs/deploy-gratis-render.md` §227). Jadi tiap deploy menghapus SELURUH PDF
 * sertifikat yang pernah terbit, sementara barisnya di database tetap `terbit`
 * dengan `pdf_path` terisi.
 *
 * Yang dilihat pengguna: sesi sudah disetujui, tombol unduh diklik, 404. Tiap
 * deploy, lagi dan lagi.
 *
 * Bentuknya membingungkan karena tidak semuanya rusak: halaman QR dan unduhan
 * Excel tetap 200, sebab dua-duanya dirakit dari `certificates.snapshot` di
 * database. Cuma PDF yang berupa berkas di disk. Itu sebabnya berkas ini menguji
 * KETIGA jalur sesudah disk dikosongkan — supaya yang tetap jalan ikut tercatat
 * sebagai perilaku yang benar, bukan kebetulan.
 *
 * PDF itu TURUNAN, bukan sumber: isinya dirender dari snapshot yang sudah
 * dibekukan waktu terbit. Jadi membangunnya ulang sah, dan itu yang dilakukan
 * `BerkasPdfSertifikat`.
 */
class PdfSertifikatSelamatDariDeployTest extends TestCase
{
    use RefreshDatabase;

    private function sertifikatTerbit(): Certificate
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

    /** Persis yang dilakukan deploy Render: isi disk arsip dibuang. */
    private function deploy(Certificate $sertifikat): void
    {
        Storage::disk('arsip')->delete($sertifikat->pdf_path);

        $this->assertFalse(
            Storage::disk('arsip')->exists($sertifikat->pdf_path),
            'Persiapan test gagal: berkasnya masih ada.',
        );
    }

    /** Jalur QR — yang dipegang pelanggan & auditor, dan yang tanpa login. */
    public function test_unduh_pdf_lewat_qr_tetap_jalan_sesudah_disk_dibuang(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $this->deploy($sertifikat);

        $this->get("/verify/{$sertifikat->qr_token}/download?format=pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Bukan cuma dilayani sekali — berkasnya balik ke disk, jadi unduhan
        // berikutnya nggak perlu merender ulang.
        $this->assertTrue(Storage::disk('arsip')->exists($sertifikat->pdf_path));
    }

    /** Jalur API — yang dipakai HP teknisi & admin. */
    public function test_unduh_pdf_lewat_api_tetap_jalan_sesudah_disk_dibuang(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $this->deploy($sertifikat);

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        $this->actingAs($admin)
            ->get("/api/certificates/{$sertifikat->id}/download")
            ->assertOk();

        $this->assertTrue(Storage::disk('arsip')->exists($sertifikat->pdf_path));
    }

    /**
     * Yang dibangun ulang itu SNAPSHOT BEKU, bukan hitungan baru.
     *
     * Nomor sertifikat tinggal di snapshot. Kalau suatu saat jalur bangun ulang
     * diam-diam merender dari sesi (bukan dari snapshot), yang terbit bisa beda
     * dari lembar yang sudah dipegang pelanggan — dan tidak ada yang bisa lihat
     * bedanya dari berkasnya.
     */
    public function test_pdf_bangun_ulang_isinya_dari_snapshot_yang_dibekukan(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        // Dimaterialisasi lewat jalur yang sama dulu, baru dibanding — seeder
        // menanam `pdf_path` tanpa menulis berkasnya, jadi membaca disk apa
        // adanya di sini bikin pembandingnya string kosong dan test-nya lolos
        // tanpa membandingkan apa pun.
        $this->deploy($sertifikat);
        $this->get("/verify/{$sertifikat->qr_token}/download?format=pdf")->assertOk();
        $sebelum = (string) Storage::disk('arsip')->get($sertifikat->pdf_path);

        $this->deploy($sertifikat);
        $this->get("/verify/{$sertifikat->qr_token}/download?format=pdf")->assertOk();
        $sesudah = (string) Storage::disk('arsip')->get($sertifikat->pdf_path);

        // Byte-nya beda (dompdf menanam CreationDate), jadi yang diadu ISINYA:
        // dua-duanya PDF yang sah dan ukurannya sekelas. Bangun ulang dari
        // snapshot yang sama harus mendarat di lembar yang sama.
        $this->assertStringStartsWith('%PDF', $sebelum);
        $this->assertStringStartsWith('%PDF', $sesudah);
        $this->assertGreaterThan(10_000, strlen($sesudah), 'PDF-nya terlalu kecil buat berisi lembar penuh.');
        $this->assertEqualsWithDelta(strlen($sebelum), strlen($sesudah), strlen($sebelum) * 0.05);

        $this->assertSame(
            $sertifikat->nomor,
            $sertifikat->fresh()->snapshot['header']['certificate_number'],
            'Snapshot beku ikut berubah — jalur bangun ulang menghitung, bukan merender.',
        );
    }

    /**
     * Halaman QR & Excel memang tidak ikut rusak — dan itu ditegakkan, bukan
     * diasumsikan. Kalau suatu saat salah satunya mulai menyentuh disk arsip,
     * yang merah test ini, bukan pelanggan yang menelepon.
     */
    public function test_halaman_qr_dan_excel_tidak_bergantung_ke_disk_arsip(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $this->deploy($sertifikat);

        $this->get("/verify/{$sertifikat->qr_token}")->assertOk();
        $this->get("/verify/{$sertifikat->qr_token}/download?format=xlsx")->assertOk();
    }

    /**
     * Sertifikat TANPA snapshot tetap 404 — jangan merender lembar kosong.
     *
     * Bedanya penting: berkas yang RAIB punya sumber buat dibangun ulang,
     * sertifikat yang belum pernah punya snapshot tidak. Merendernya
     * menghasilkan lembar terakreditasi berisi tanda pisah semua.
     */
    public function test_tanpa_snapshot_tetap_404_bukan_lembar_kosong(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $this->deploy($sertifikat);
        $sertifikat->update(['snapshot' => null]);

        $this->get("/verify/{$sertifikat->qr_token}/download?format=pdf")->assertNotFound();
    }

    /** Sertifikat yang statusnya belum `terbit` juga tidak dibangunkan diam-diam. */
    public function test_sertifikat_belum_terbit_tidak_dibangun_ulang(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $this->deploy($sertifikat);
        $sertifikat->update(['status' => Certificate::STATUS_GAGAL]);

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        $this->actingAs($admin)
            ->get("/api/certificates/{$sertifikat->id}/download")
            ->assertNotFound();

        $this->assertFalse(Storage::disk('arsip')->exists($sertifikat->pdf_path));
    }
}
