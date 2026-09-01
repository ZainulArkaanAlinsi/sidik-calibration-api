<?php

namespace Tests\Feature;

use App\Filament\Resources\Certificates\Pages\ListCertificates;
use App\Jobs\GenerateCertificate;
use App\Models\Certificate;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aksi "Unduh PDF" & "Terbitkan ulang" di tabel Sertifikat panel admin.
 * Diuji lewat komponen Livewire-nya langsung, bukan cuma render HTTP,
 * biar nangkep salah kondisi visible()/action() di CertificatesTable.
 */
class CertificateTableActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /**
     * PDF palsu yang UKURANNYA masuk akal.
     *
     * `BerkasPdfSertifikat` menolak berkas di bawah 1 KB sebagai penulisan yang
     * TERPOTONG — `Storage::put()` menganggap tulisan kosong sebagai sukses dan
     * `exists()` sesudahnya balik `true`, jadi "ada" bukan berarti "utuh".
     * Fixture 17 byte bikin sertifikat ini dinilai rusak lalu dicoba dibangun
     * ulang, dan itu bukan yang diuji di berkas ini.
     */
    private function pdfPalsu(): string
    {
        return '%PDF-1.7 dummy pdf content'.str_repeat(' ', 2048);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_admin_bisa_unduh_pdf_sertifikat_yang_terbit(): void
    {
        $sertifikat = Certificate::factory()->create(['pdf_path' => 'certificates/abc.pdf']);
        Storage::disk('arsip')->put($sertifikat->pdf_path, $this->pdfPalsu());

        Livewire::actingAs($this->admin)
            ->test(ListCertificates::class)
            ->assertActionVisible(TestAction::make('download')->table($sertifikat))
            ->callAction(TestAction::make('download')->table($sertifikat))
            ->assertFileDownloaded('Sertifikat-'.str_replace('/', '-', $sertifikat->nomor).'.pdf');
    }

    public function test_tombol_unduh_disembunyikan_kalau_file_pdf_belum_ada(): void
    {
        $sertifikat = Certificate::factory()->menungguGenerate()->create();

        Livewire::actingAs($this->admin)
            ->test(ListCertificates::class)
            ->assertActionHidden(TestAction::make('download')->table($sertifikat));
    }

    public function test_admin_bisa_terbitkan_ulang_sertifikat_yang_gagal(): void
    {
        Queue::fake();

        $sertifikat = Certificate::factory()->gagal()->create();

        Livewire::actingAs($this->admin)
            ->test(ListCertificates::class)
            ->assertActionVisible(TestAction::make('retry')->table($sertifikat))
            ->callAction(TestAction::make('retry')->table($sertifikat))
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('certificates', [
            'id' => $sertifikat->id,
            'status' => Certificate::STATUS_MENUNGGU_GENERATE,
        ]);

        Queue::assertPushed(GenerateCertificate::class, fn (GenerateCertificate $job): bool => $job->calibrationSessionId === $sertifikat->calibration_session_id
            && $job->issuedBy === $this->admin->id);
    }

    public function test_tombol_terbitkan_ulang_disembunyikan_untuk_sertifikat_yang_sudah_terbit(): void
    {
        $sertifikat = Certificate::factory()->create(['pdf_path' => 'certificates/sudah-terbit.pdf']);
        Storage::disk('arsip')->put($sertifikat->pdf_path, $this->pdfPalsu());

        Livewire::actingAs($this->admin)
            ->test(ListCertificates::class)
            ->assertActionHidden(TestAction::make('retry')->table($sertifikat));
    }
}
