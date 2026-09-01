<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\CertificateSnapshotBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Angka di LAYAR harus punya desimal yang sama dengan angka di SERTIFIKAT.
 *
 * ## Cacat yang ditutup berkas ini
 *
 * `CertificateSnapshotBuilder` memilih desimal per BARIS dulu
 * (`desimalSertifikatTitik()` / `desimalU95Titik()`), baru per alat, baru
 * aturan umum. `CalibrationResource::petakanTitik()` — yang mengisi layar
 * riwayat & approval — melewati hook per barisnya dan langsung ke per alat.
 *
 * Jadi dua jalur membaca data yang SAMA dan menulis angka yang BERBEDA. Terukur
 * di sesi master ter-seed, sebelum perbaikan:
 *
 * | Sesi | Titik | Sertifikat | Layar |
 * |---|---|---|---|
 * | Viscometer `2607.59.W` | 2 | 1 desimal | 2 desimal |
 * | Viscometer `2607.59.W` | 3 | 0 desimal | 2 desimal |
 * | Gas Detector `2602.03.A` | 1–3 | 0 · U95 1 | `null` · `null` |
 * | Gas Detector `2602.03.A` | 4 (O2) | 1 · U95 2 | `null` · `null` |
 *
 * `2709,8 cP` di sertifikat tampil `2709,80` di layar — satu angka penting yang
 * alat itu tidak punya. Untuk Gas Detector layar jatuh ke `equipments.resolusi`
 * yang cuma muat SATU angka buat empat gas, jadi baris oksigen tampil
 * `18 | 17 | 1` padahal yang diukur 17,9 %.
 *
 * Nol error, dan yang menemukannya paling mungkin teknisi yang sedang memeriksa
 * hasilnya sendiri sebelum minta approve — persis yang dijanjikan tidak akan
 * terjadi oleh catatan `desimal_u95` & `tanda_nol` di berkas itu.
 *
 * Disapu ke SELURUH sesi ter-seed, bukan cuma dua alat itu: cacatnya ada di
 * jalur bersama, jadi alat ke-25 yang memformat per baris kena hal yang sama
 * tanpa ada yang perlu ingat menulis test-nya.
 */
class DesimalLayarSamaDenganSertifikatTest extends TestCase
{
    use RefreshDatabase;

    public function test_desimal_layar_sama_dengan_sertifikat_di_semua_sesi(): void
    {
        $this->seed(DatabaseSeeder::class);

        $hasil = new ReflectionMethod(CertificateSnapshotBuilder::class, 'hasil');
        $pembangun = app(CertificateSnapshotBuilder::class);
        $admin = User::query()->where('role', User::ROLE_ADMIN)->firstOrFail();

        $diperiksa = 0;
        $beda = [];

        $sesiSemua = CalibrationSession::with(['equipment', 'uncertaintyCalculations', 'organization'])->get();

        foreach ($sesiSemua as $sesi) {
            if ($sesi->equipment === null || $sesi->uncertaintyCalculations->isEmpty()) {
                continue;
            }

            $baris = $hasil->invoke($pembangun, $sesi);

            // Lewat endpoint sungguhan, bukan helper internal: yang diuji angka
            // yang BENERAN sampai ke layar, termasuk cadangan tingkat-sesi.
            $api = $this->actingAs($admin)->getJson('/api/calibrations/'.$sesi->id);

            if ($api->getStatusCode() !== 200) {
                continue;
            }

            $desimalSesi = $api->json('data.desimal');
            $titikApi = $api->json('data.titik') ?? [];

            foreach ($baris as $i => $b) {
                if (! isset($titikApi[$i])) {
                    continue;
                }

                $diperiksa++;

                // `null` per titik BUKAN berarti "tanpa desimal" — mobile jatuh
                // ke `desimal` tingkat sesi, persis seperti yang ditulis catatan
                // di `petakanTitik()`. Yang dibandingkan angka EFEKTIFNYA.
                $efektif = $titikApi[$i]['desimal'] ?? $desimalSesi;

                if (($b['desimal'] ?? null) !== $efektif) {
                    $beda[] = sprintf(
                        '%s (%s) titik %s: desimal sertifikat=%s layar=%s',
                        $sesi->nomor_sesi, $sesi->equipment->nama_alat, $b['titik_ke'] ?? $i,
                        var_export($b['desimal'] ?? null, true), var_export($efektif, true),
                    );
                }

                // `desimal_u95` cadangannya `desimal` di kedua jalur, jadi yang
                // dibandingkan nilai mentahnya — null di dua-duanya berarti
                // dua-duanya jatuh ke tempat yang sama.
                if (($b['desimal_u95'] ?? null) !== ($titikApi[$i]['desimal_u95'] ?? null)) {
                    $beda[] = sprintf(
                        '%s (%s) titik %s: desimal_u95 sertifikat=%s layar=%s',
                        $sesi->nomor_sesi, $sesi->equipment->nama_alat, $b['titik_ke'] ?? $i,
                        var_export($b['desimal_u95'] ?? null, true),
                        var_export($titikApi[$i]['desimal_u95'] ?? null, true),
                    );
                }
            }
        }

        $this->assertGreaterThan(0, $diperiksa, 'Nggak ada satu pun titik yang keperiksa — seeder-nya berubah?');

        $this->assertSame(
            [], $beda,
            "Layar dan sertifikat nulis desimal yang beda buat data yang sama:\n  "
            .implode("\n  ", $beda)
            ."\n\n`CalibrationResource::petakanTitik()` harus memakai rantai yang sama dengan "
            .'`CertificateSnapshotBuilder`: hook per BARIS dulu, baru per alat, baru aturan umum.',
        );
    }
}
