<?php

namespace Tests\Concerns;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use Database\Seeders\DatabaseSeeder;

/**
 * Terbitkan sesi contoh seeder lewat jalur produksi lalu render HTML sertifikat
 * dengan view asli — bahan `*SesiContohCocokMasterTest` (PK-4).
 *
 * Jalur yang dilewati sama dengan klik Setujui di aplikasi: `POST
 * /calibrations/{id}/approve` oleh admin pemeriksa yang BUKAN pengisi lembar
 * (K-30-03), Order Number terisi (K-49-14), nomor sertifikat diketik (K-50-04).
 * Yang dirender `DataTampilanSertifikat::untuk()` — fungsi yang sama dengan
 * jalur terbit PDF — ke `sertifikat.pdf` dengan `paksaPadat = false` (mode
 * normal; mode padat hanya mengecilkan huruf, tidak mengubah teks angka).
 *
 * Syarat pemakai: kelas `Tests\TestCase` + `RefreshDatabase`.
 */
trait MenerbitkanSesiContoh
{
    /** Seed penuh + admin yang login; panggil dari `setUp()` sesudah `parent::setUp()`. */
    protected function siapkanSesiContoh(): void
    {
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
    }

    /**
     * @param  (callable(CalibrationSession): void)|null  $sebelumApprove  melengkapi masukan sesi (mis. blok `spesifikasi_alat`) sebelum disetujui
     * @return array{0: Certificate, 1: string, 2: CalibrationSession}
     */
    protected function terbitkanDanRender(string $nomorSesi, ?callable $sebelumApprove = null): array
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();

        if ($sesi->certificate()->doesntExist()) {
            if ($sebelumApprove !== null) {
                $sebelumApprove($sesi);
            }

            $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)
                ->postJson("/api/calibrations/{$sesi->id}/approve", [
                    'nomor_sertifikat' => $this->nomorUji(),
                    // Sesi contoh membawa peringatan sah (mis. di luar akreditasi);
                    // yang diuji di sini angka cetaknya, bukan gerbang peringatan.
                    'abaikan_peringatan' => true,
                ])
                ->assertOk();
        }

        $sertifikat = $sesi->fresh()->certificate()->firstOrFail();
        $html = view('sertifikat.pdf', [
            ...app(DataTampilanSertifikat::class)->untuk($sertifikat),
            'paksaPadat' => false,
        ])->render();

        return [$sertifikat, $html, $sesi->fresh()];
    }
}
